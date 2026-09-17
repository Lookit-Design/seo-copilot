<?php
/**
 * Per-user work lists built from report checks.
 *
 * @package Lookit_SEO_Copilot
 */

defined( 'ABSPATH' ) || exit;

class BSM_Tasks {

	const META_CHECKS = 'bsm_task_checks';
	const META_DONE   = 'bsm_task_done';
	const PAGE        = 25;

	private static function can_use(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function known_slugs(): array {
		$slugs = array();
		foreach ( (array) ( BSM_Reports::snapshot()['checks'] ?? array() ) as $label => $check ) {
			unset( $check );
			$slugs[] = BSM_Reports::check_slug( (string) $label );
		}
		return array_values( array_unique( $slugs ) );
	}

	public static function checks(): array {
		if ( ! self::can_use() ) {
			return array();
		}
		$saved = get_user_meta( get_current_user_id(), self::META_CHECKS, true );
		$saved = is_array( $saved ) ? array_map( 'sanitize_key', $saved ) : array();
		return array_values( array_intersect( array_unique( $saved ), self::known_slugs() ) );
	}

	private static function item_is_valid( string $slug, int $post_id ): bool {
		if ( 1 > $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return false;
		}
		$items = BSM_Reports::check_items( $slug );
		return in_array( $post_id, array_merge( $items['fail'] ?? array(), $items['warn'] ?? array() ), true );
	}

	public static function done(): array {
		if ( ! self::can_use() ) {
			return array();
		}
		$done = array();
		foreach ( self::raw_done() as $slug => $ids ) {
			if ( ! in_array( $slug, self::checks(), true ) ) {
				continue;
			}
			foreach ( array_unique( array_map( 'absint', (array) $ids ) ) as $post_id ) {
				if ( self::item_is_valid( $slug, $post_id ) ) {
					$done[ $slug ][] = $post_id;
				}
			}
		}
		return $done;
	}

	private static function raw_done(): array {
		$stored = get_user_meta( get_current_user_id(), self::META_DONE, true );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$done = array();
		foreach ( $stored as $slug => $ids ) {
			$done[ sanitize_key( (string) $slug ) ] = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
		}
		return $done;
	}

	public static function done_counts(): array {
		$counts = array();
		foreach ( self::done() as $slug => $ids ) {
			$counts[ $slug ] = count( $ids );
		}
		return $counts;
	}

	public static function state(): array {
		$done = self::done_counts();
		return array(
			'checks'   => self::checks(),
			'done'     => (object) $done,
			'progress' => self::progress( BSM_Reports::payload( BSM_Reports::snapshot() )['checks'] ?? array(), $done ),
		);
	}

	public static function progress( array $checks, array $done_counts ): array {
		$total  = 0;
		$done   = 0;
		$points = 0.0;
		$groups = array();
		foreach ( $checks as $check ) {
			$slug = sanitize_key( (string) ( $check['slug'] ?? '' ) );
			if ( '' === $slug || ! in_array( $slug, self::checks(), true ) ) {
				continue;
			}
			$affected        = max( 0, (int) ( $check['affected'] ?? 0 ) );
			$complete        = min( $affected, max( 0, (int) ( $done_counts[ $slug ] ?? 0 ) ) );
			$total          += $affected;
			$done           += $complete;
			$points         += $affected ? (float) ( $check['points'] ?? 0 ) * $complete / $affected : 0;
			$groups[ $slug ] = array(
				'affected' => $affected,
				'done'     => $complete,
				'percent'  => $affected ? (int) round( 100 * $complete / $affected ) : 0,
			);
		}
		return array(
			'total'   => $total,
			'done'    => $done,
			'percent' => $total ? (int) round( 100 * $done / $total ) : 0,
			'points'  => round( $points, 1 ),
			'groups'  => $groups,
		);
	}

	public static function set_checks( array $checks ) {
		if ( ! self::can_use() ) {
			return new WP_Error( 'forbidden', 'Permission denied.' );
		}
		$checks = array_values( array_intersect( array_unique( array_map( 'sanitize_key', $checks ) ), self::known_slugs() ) );
		update_user_meta( get_current_user_id(), self::META_CHECKS, $checks );
		return $checks;
	}

	public static function items( string $slug, int $offset = 0 ) {
		if ( ! self::can_use() || ! in_array( $slug, self::checks(), true ) ) {
			return new WP_Error( 'forbidden', 'Unknown task or permission denied.' );
		}
		$stored = BSM_Reports::check_items( $slug );
		if ( empty( $stored ) ) {
			return new WP_Error( 'missing', 'That check is not in the latest report.' );
		}
		$rows = array();
		foreach ( array( 'fail', 'warn' ) as $status ) {
			foreach ( $stored[ $status ] as $post_id ) {
				if ( self::item_is_valid( $slug, $post_id ) ) {
					$rows[] = array( $post_id, $status );
				}
			}
		}
		$total = count( $rows );
		$done  = self::done();
		$mine  = $done[ $slug ] ?? array();
		$items = array();
		foreach ( array_slice( $rows, max( 0, $offset ), self::PAGE ) as $row ) {
			list( $post_id, $status ) = $row;
			$post                     = get_post( $post_id );
			$type                     = get_post_type_object( $post->post_type );
			$items[]                  = array(
				'id'     => $post_id,
				'title'  => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ),
				'type'   => $type ? $type->labels->singular_name : $post->post_type,
				'status' => $status,
				'url'    => add_query_arg(
					array(
						'page'       => 'lookit-bulk-seo',
						'tab'        => 'health',
						'audit_post' => $post_id,
					),
					admin_url( 'admin.php' )
				) . '#bsm-h-chk-' . $slug,
				'done'   => in_array( $post_id, $mine, true ),
			);
		}
		return array(
			'check' => $slug,
			'label' => $stored['label'],
			'items' => $items,
			'next'  => max( 0, $offset ) + self::PAGE,
			'more'  => max( 0, $offset ) + self::PAGE < $total,
			'total' => $total,
		);
	}

	public static function toggle( string $slug, int $post_id, bool $is_done ) {
		if ( ! self::can_use() || ! in_array( $slug, self::checks(), true ) || ! self::item_is_valid( $slug, $post_id ) ) {
			return new WP_Error( 'forbidden', 'Unknown task or permission denied.' );
		}
		$done = self::raw_done();
		$list = $done[ $slug ] ?? array();
		if ( $is_done ) {
			$list[] = $post_id;
			$list   = array_values( array_unique( $list ) );
		} else {
			$list = array_values( array_diff( $list, array( $post_id ) ) );
		}
		if ( empty( $list ) ) {
			unset( $done[ $slug ] );
		} else {
			$done[ $slug ] = $list;
		}
		update_user_meta( get_current_user_id(), self::META_DONE, $done );
		return self::done_counts();
	}

	public static function reset( string $slug = '' ) {
		if ( ! self::can_use() ) {
			return new WP_Error( 'forbidden', 'Permission denied.' );
		}
		if ( '' === $slug ) {
			delete_user_meta( get_current_user_id(), self::META_DONE );
		} else {
			$done = self::raw_done();
			unset( $done[ sanitize_key( $slug ) ] );
			update_user_meta( get_current_user_id(), self::META_DONE, $done );
		}
		return self::done_counts();
	}

	public static function ajax_set(): void {
		check_ajax_referer( 'bsm_task_set', 'nonce' );
		$raw    = isset( $_POST['checks'] ) ? sanitize_text_field( wp_unslash( $_POST['checks'] ) ) : '';
		$result = self::set_checks( explode( ',', $raw ) );
		self::respond( $result, 'checks' );
	}

	public static function ajax_items(): void {
		check_ajax_referer( 'bsm_task_items', 'nonce' );
		$slug   = isset( $_POST['check'] ) ? sanitize_key( wp_unslash( $_POST['check'] ) ) : '';
		$offset = min( 100000, absint( $_POST['offset'] ?? 0 ) );
		$result = self::items( $slug, $offset );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message(), 403 );
		}
		wp_send_json_success( $result );
	}

	public static function ajax_toggle(): void {
		check_ajax_referer( 'bsm_task_toggle', 'nonce' );
		$slug   = isset( $_POST['check'] ) ? sanitize_key( wp_unslash( $_POST['check'] ) ) : '';
		$post   = absint( $_POST['post'] ?? 0 );
		$done   = isset( $_POST['done'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['done'] ) );
		$result = self::toggle( $slug, $post, $done );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message(), 403 );
		}
		wp_send_json_success( self::state() );
	}

	public static function ajax_reset(): void {
		check_ajax_referer( 'bsm_task_reset', 'nonce' );
		$slug   = isset( $_POST['check'] ) ? sanitize_key( wp_unslash( $_POST['check'] ) ) : '';
		$result = self::reset( $slug );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message(), 403 );
		}
		wp_send_json_success( self::state() );
	}

	private static function respond( $result, string $key ): void {
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message(), 403 );
		}
		wp_send_json_success( array( $key => 'done' === $key ? (object) $result : $result ) );
	}
}
