<?php
/**
 * Dormant internal-link scanning and insertion engine.
 *
 * @package Lookit_SEO_Copilot
 */

defined( 'ABSPATH' ) || exit;

class BSM_Links {

	const OPTION        = 'bsm_links_snapshot';
	const IGNORED       = 'bsm_links_ignored';
	const STATE_OPTION  = 'bsm_links_scan_state';
	const LEGACY_ACC    = 'bsm_links_acc_state';
	const LEGACY_MAP    = 'bsm_links_map_state';
	const SCHEMA        = 1;
	const BATCH         = 25;
	const MAP_BATCH     = 100;
	const STATE_TTL     = 900;
	const SOURCE_CAP    = 2000;
	const MAP_CAP       = 1000;
	const OPP_CAP       = 250;
	const PER_SOURCE    = 3;
	const LIST_CAP      = 25;
	const IGNORE_CAP    = 500;
	const MAX_HTML      = 1000000;
	const EL_MAX_RAW    = 2000000;
	const EL_MAX_DEPTH  = 30;
	const EL_MAX_NODES  = 5000;
	const EL_MAX_STRING = 500000;

	private static function store_option( string $name, array $value ): bool {
		if ( isset( wp_load_alloptions()[ $name ] ) ) {
			delete_option( $name );
			return add_option( $name, $value, '', false );
		}
		if ( false === get_option( $name, false ) ) {
			return add_option( $name, $value, '', false );
		}
		return update_option( $name, $value, false );
	}

	public static function types(): array {
		$available = function_exists( 'bsm_get_post_types' ) ? array_keys( bsm_get_post_types() ) : array( 'post', 'page' );
		$types     = array();
		foreach ( $available as $type ) {
			$type_object = get_post_type_object( $type );
			if ( $type_object && $type_object->public && $type_object->show_ui && is_post_type_viewable( $type ) ) {
				$types[] = sanitize_key( $type );
			}
		}
		sort( $types, SORT_STRING );
		return array_values( array_unique( $types ) );
	}

	public static function snapshot(): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array();
		}
		$snapshot = get_option( self::OPTION, array() );
		return is_array( $snapshot ) ? $snapshot : array();
	}

	public static function ignored(): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array();
		}
		$ignored = get_option( self::IGNORED, array() );
		return is_array( $ignored ) ? array_slice( array_values( $ignored ), -self::IGNORE_CAP ) : array();
	}

	private static function blank_aggregate(): array {
		return array(
			'scanned'  => 0,
			'opps'     => array(),
			'incoming' => array(),
			'pages'    => array(),
			'broken'   => array(),
			'no_out'   => 0,
		);
	}

	private static function new_state( bool $titles ): array {
		return array(
			'schema'    => self::SCHEMA,
			'scan_id'   => wp_generate_uuid4(),
			'user_id'   => get_current_user_id(),
			'started'   => time(),
			'updated'   => time(),
			'phase'     => 'map',
			'offset'    => 0,
			'total'     => null,
			'types'     => self::types(),
			'titles'    => $titles,
			'map'       => array(),
			'aggregate' => self::blank_aggregate(),
		);
	}

	private static function current_state() {
		$state = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $state ) || empty( $state['scan_id'] ) ) {
			return new WP_Error( 'stale_scan', __( 'The link scan no longer exists. Start a new scan.', 'bulk-keyphrase-manager' ) );
		}
		if (
			self::SCHEMA !== (int) ( $state['schema'] ?? 0 ) ||
			time() - (int) ( $state['updated'] ?? 0 ) > self::STATE_TTL
		) {
			delete_option( self::STATE_OPTION );
			return new WP_Error( 'stale_scan', __( 'The interrupted link scan expired. Start a new scan.', 'bulk-keyphrase-manager' ) );
		}
		return $state;
	}

	public static function start_scan( bool $titles = false, bool $restart = false ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'bulk-keyphrase-manager' ) );
		}
		$current = self::current_state();
		if ( ! is_wp_error( $current ) ) {
			if ( get_current_user_id() !== (int) $current['user_id'] ) {
				return new WP_Error( 'scan_busy', __( 'Another administrator is already running a link scan.', 'bulk-keyphrase-manager' ) );
			}
			if ( ! $restart ) {
				return self::progress( $current );
			}
		}

		$state = self::new_state( $titles );
		if ( empty( $state['types'] ) || ! self::store_option( self::STATE_OPTION, $state ) ) {
			return new WP_Error( 'state_write_failed', __( 'The link scan could not be started.', 'bulk-keyphrase-manager' ) );
		}
		return self::progress( $state );
	}

	private static function progress( array $state ): array {
		return array(
			'complete' => false,
			'phase'    => (string) $state['phase'],
			'offset'   => (int) $state['offset'],
			'total'    => null === $state['total'] ? 0 : (int) $state['total'],
			'scan_id'  => (string) $state['scan_id'],
		);
	}

	private static function validate_continuation( string $phase, int $offset, string $scan_id ) {
		$state = self::current_state();
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		if (
			get_current_user_id() !== (int) $state['user_id'] ||
			! hash_equals( (string) $state['scan_id'], $scan_id ) ||
			$phase !== (string) $state['phase'] ||
			$offset !== (int) $state['offset']
		) {
			return new WP_Error( 'stale_scan', __( 'This link scan request is stale.', 'bulk-keyphrase-manager' ) );
		}
		return $state;
	}

	private static function query( array $state, int $limit ): WP_Query {
		$args = array(
			'post_type'              => $state['types'],
			'post_status'            => 'publish',
			'posts_per_page'         => $limit,
			'offset'                 => (int) $state['offset'],
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'perm'                   => 'editable',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => null !== $state['total'],
			'update_post_term_cache' => false,
		);
		return new WP_Query( $args );
	}

	public static function scan_batch( string $phase, int $offset, string $scan_id ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'bulk-keyphrase-manager' ) );
		}
		$phase = sanitize_key( $phase );
		if ( ! in_array( $phase, array( 'map', 'scan' ), true ) ) {
			return new WP_Error( 'invalid_phase', __( 'Invalid link scan phase.', 'bulk-keyphrase-manager' ) );
		}
		$state = self::validate_continuation( $phase, max( 0, $offset ), sanitize_text_field( $scan_id ) );
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		$query = self::query( $state, 'map' === $phase ? self::MAP_BATCH : self::BATCH );
		if ( null === $state['total'] ) {
			$state['total'] = min( self::SOURCE_CAP, (int) $query->found_posts );
		}
		if ( 'map' === $phase ) {
			self::fold_map( $state, $query->posts );
		} else {
			self::fold_sources( $state, $query->posts );
		}
		$read            = count( $query->posts );
		$state['offset'] = min( (int) $state['total'], (int) $state['offset'] + $read );
		$complete        = $state['offset'] >= (int) $state['total'] || 0 === $read;

		if ( $complete && 'map' === $phase ) {
			$state['map']       = self::normalise_map( $state['map'] );
			$state['phase']     = 'scan';
			$state['offset']    = 0;
			$state['total']     = null;
			$state['aggregate'] = self::blank_aggregate();
		} elseif ( $complete ) {
			return self::finish_scan( $state );
		}

		$state['updated'] = time();
		if ( ! self::store_option( self::STATE_OPTION, $state ) ) {
			return new WP_Error( 'state_write_failed', __( 'Link scan progress could not be saved.', 'bulk-keyphrase-manager' ) );
		}
		return self::progress( $state );
	}

	private static function fold_map( array &$state, array $posts ): void {
		foreach ( $posts as $post ) {
			if ( count( $state['map'] ) >= self::SOURCE_CAP || ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$title     = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );
			$keyphrase = trim( (string) get_post_meta( $post->ID, BSM_META_KW, true ) );
			if ( '' === $keyphrase && ! empty( $state['titles'] ) && mb_strlen( $title ) <= 60 ) {
				$keyphrase = $title;
			}
			$keyphrase = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $keyphrase ) ) );
			if ( mb_strlen( $keyphrase ) < 8 || substr_count( $keyphrase, ' ' ) < 1 ) {
				continue;
			}
			$url = self::safe_url( get_permalink( $post ) );
			if ( '' === $url ) {
				continue;
			}
			$state['map'][] = array(
				'id'    => (int) $post->ID,
				'kp'    => $keyphrase,
				'lower' => mb_strtolower( $keyphrase ),
				'title' => $title,
				'url'   => $url,
			);
		}
		wp_reset_postdata();
	}

	private static function normalise_map( array $map ): array {
		$counts = array_count_values( array_column( $map, 'lower' ) );
		$map    = array_values(
			array_filter(
				$map,
				static function ( array $target ) use ( $counts ): bool {
					return 1 === (int) ( $counts[ $target['lower'] ] ?? 0 );
				}
			)
		);
		usort(
			$map,
			static function ( array $left, array $right ): int {
				$length = mb_strlen( $right['kp'] ) <=> mb_strlen( $left['kp'] );
				return 0 !== $length ? $length : ( $left['id'] <=> $right['id'] );
			}
		);
		return array_slice( $map, 0, self::MAP_CAP );
	}

	private static function fold_sources( array &$state, array $posts ): void {
		$ignored = self::ignored();
		foreach ( $posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			++$state['aggregate']['scanned'];
			self::fold_source( $state['aggregate'], $post, $state['map'], $ignored );
		}
		wp_reset_postdata();
	}

	private static function fold_source( array &$aggregate, WP_Post $post, array $map, array $ignored ): void {
		$source = self::source_document( $post->ID );
		if ( is_wp_error( $source ) || '' === trim( $source['html'] ) ) {
			return;
		}
		$aggregate['pages'][ $post->ID ] = array(
			'title' => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ),
			'url'   => self::safe_url( get_permalink( $post ) ),
			'edit'  => (string) get_edit_post_link( $post->ID, 'raw' ),
			'type'  => (string) $post->post_type,
		);
		$linked                          = self::existing_targets( $source['html'], $aggregate, $post );
		if ( empty( $linked['count'] ) ) {
			++$aggregate['no_out'];
		}

		$candidates = array();
		foreach ( $map as $target ) {
			if (
				(int) $target['id'] === (int) $post->ID ||
				isset( $linked['ids'][ $target['id'] ] ) ||
				in_array( $post->ID . ':' . $target['id'], $ignored, true )
			) {
				continue;
			}
			$matches = self::document_matches( $source, $target['kp'] );
			if ( 1 !== count( $matches ) ) {
				continue;
			}
			$match        = $matches[0];
			$candidates[] = array(
				'target' => $target,
				'match'  => $match,
			);
		}

		$accepted = array();
		foreach ( $candidates as $candidate ) {
			$key     = $candidate['match']['node'];
			$start   = $candidate['match']['start'];
			$end     = $start + mb_strlen( $candidate['match']['phrase'] );
			$overlap = false;
			foreach ( $accepted as $range ) {
				if ( $range['node'] === $key && $start < $range['end'] && $end > $range['start'] ) {
					$overlap = true;
					break;
				}
			}
			if ( $overlap ) {
				continue;
			}
			$accepted[] = array(
				'node'  => $key,
				'start' => $start,
				'end'   => $end,
			);
			if ( count( $accepted ) > self::PER_SOURCE || count( $aggregate['opps'] ) >= self::OPP_CAP ) {
				break;
			}
			$target              = $candidate['target'];
			$match               = $candidate['match'];
			$aggregate['opps'][] = array(
				'src'         => (int) $post->ID,
				'dst'         => (int) $target['id'],
				'phrase'      => $match['phrase'],
				'from'        => $aggregate['pages'][ $post->ID ]['title'],
				'to'          => $target['title'],
				'to_url'      => $target['url'],
				'where'       => $source['where'],
				'node'        => $match['node'],
				'fingerprint' => $source['fingerprint'],
			);
		}
	}

	private static function existing_targets( string $html, array &$aggregate, WP_Post $post ): array {
		$result = array(
			'ids'   => array(),
			'count' => 0,
		);
		if ( ! preg_match_all( '#<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>#i', $html, $matches ) ) {
			return $result;
		}
		foreach ( $matches[1] as $href ) {
			$url = self::normalise_internal_url( $href );
			if ( '' === $url ) {
				continue;
			}
			++$result['count'];
			$target_id = (int) url_to_postid( $url );
			if ( $target_id ) {
				$result['ids'][ $target_id ]         = true;
				$aggregate['incoming'][ $target_id ] = 1 + (int) ( $aggregate['incoming'][ $target_id ] ?? 0 );
			} elseif ( count( $aggregate['broken'] ) < self::LIST_CAP ) {
				$aggregate['broken'][] = array(
					'from' => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ),
					'edit' => (string) get_edit_post_link( $post->ID, 'raw' ),
					'href' => $url,
				);
			}
		}
		return $result;
	}

	private static function normalise_internal_url( string $href ): string {
		$href = trim( html_entity_decode( $href, ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $href || '#' === $href[0] ) {
			return '';
		}
		if ( '/' === $href[0] ) {
			$href = home_url( $href );
		}
		$url = self::safe_url( $href );
		if ( '' === $url ) {
			return '';
		}
		$home_host = mb_strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$url_host  = mb_strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return '' !== $home_host && $home_host === $url_host ? $url : '';
	}

	private static function safe_url( $url ): string {
		$url    = esc_url_raw( (string) $url, array( 'http', 'https' ) );
		$scheme = mb_strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		return in_array( $scheme, array( 'http', 'https' ), true ) && wp_http_validate_url( $url ) ? $url : '';
	}

	private static function finish_scan( array $state ) {
		$aggregate = $state['aggregate'];
		$incoming  = $aggregate['incoming'];
		$orphans   = array();
		foreach ( $aggregate['pages'] as $post_id => $page ) {
			if ( empty( $incoming[ $post_id ] ) ) {
				$orphans[] = array( 'id' => (int) $post_id ) + $page;
			}
		}
		foreach ( $aggregate['opps'] as &$opportunity ) {
			$opportunity['in'] = (int) ( $incoming[ $opportunity['dst'] ] ?? 0 );
		}
		unset( $opportunity );
		usort(
			$aggregate['opps'],
			static function ( array $left, array $right ): int {
				$incoming_order = $left['in'] <=> $right['in'];
				if ( 0 !== $incoming_order ) {
					return $incoming_order;
				}
				$source_order = $left['src'] <=> $right['src'];
				return 0 !== $source_order ? $source_order : ( $left['dst'] <=> $right['dst'] );
			}
		);
		$snapshot = array(
			'schema'    => self::SCHEMA,
			'version'   => BSM_VERSION,
			'generated' => time(),
			'scanned'   => (int) $aggregate['scanned'],
			'dest_n'    => count( $state['map'] ),
			'titles'    => ! empty( $state['titles'] ),
			'opps'      => array_slice( array_values( $aggregate['opps'] ), 0, self::OPP_CAP ),
			'orphans'   => array_slice( array_values( $orphans ), 0, self::LIST_CAP ),
			'orphan_n'  => count( $orphans ),
			'broken'    => array_slice( array_values( $aggregate['broken'] ), 0, self::LIST_CAP ),
			'no_out'    => (int) $aggregate['no_out'],
		);
		self::store_option( self::OPTION, $snapshot );
		wp_cache_delete( self::OPTION, 'options' );
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) || $stored !== $snapshot ) {
			return new WP_Error( 'snapshot_read_failed', __( 'The saved link scan could not be verified.', 'bulk-keyphrase-manager' ) );
		}
		delete_option( self::STATE_OPTION );
		return array(
			'complete' => true,
			'snapshot' => $snapshot,
			'done'     => (int) $aggregate['scanned'],
			'total'    => (int) $aggregate['scanned'],
		);
	}

	private static function source_document( int $post_id ) {
		if ( self::is_elementor( $post_id ) ) {
			$tree = self::elementor_tree( $post_id );
			if ( is_wp_error( $tree ) ) {
				return $tree;
			}
			$nodes = array();
			$count = 0;
			if ( ! self::collect_elementor_nodes( $tree, $nodes, 0, $count ) ) {
				return new WP_Error( 'elementor_limit', __( 'Elementor content exceeds safe traversal limits.', 'bulk-keyphrase-manager' ) );
			}
			return array(
				'where'       => 'elementor',
				'html'        => implode( "\n", array_column( $nodes, 'html' ) ),
				'nodes'       => $nodes,
				'fingerprint' => hash( 'sha256', wp_json_encode( $tree ) ),
			);
		}
		$post = get_post( $post_id );
		$html = $post ? (string) $post->post_content : '';
		if ( strlen( $html ) > self::MAX_HTML ) {
			return new WP_Error( 'content_limit', __( 'Content exceeds the safe link-scanning limit.', 'bulk-keyphrase-manager' ) );
		}
		return array(
			'where'       => 'content',
			'html'        => $html,
			'nodes'       => array(
				array(
					'path' => 'post_content',
					'html' => $html,
				),
			),
			'fingerprint' => hash( 'sha256', $html ),
		);
	}

	private static function document_matches( array $source, string $phrase ): array {
		$matches = array();
		foreach ( $source['nodes'] as $node ) {
			foreach ( self::linkable_matches( $node['html'], $phrase ) as $match ) {
				$match['node'] = $node['path'];
				$matches[]     = $match;
			}
		}
		return $matches;
	}

	private static function linkable_matches( string $content, string $phrase ): array {
		$parts = preg_split( '/(<[^>]+>)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return array();
		}
		$matches   = array();
		$in_anchor = false;
		$skip      = 0;
		$position  = 0;
		$quoted    = preg_quote( $phrase, '/' );
		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}
			if ( '<' === $part[0] ) {
				if ( preg_match( '#^<a[\s>]#i', $part ) ) {
					$in_anchor = true;
				} elseif ( preg_match( '#^</a>#i', $part ) ) {
					$in_anchor = false;
				} elseif ( preg_match( '#^<(h[1-6]|script|style)[\s>]#i', $part ) ) {
					++$skip;
				} elseif ( preg_match( '#^</(h[1-6]|script|style)>#i', $part ) ) {
					$skip = max( 0, $skip - 1 );
				}
				continue;
			}
			if ( ! $in_anchor && 0 === $skip && preg_match_all( '/(?<![\pL\pN])' . $quoted . '(?![\pL\pN])/iu', $part, $found, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $found[0] as $found_match ) {
					$prefix    = substr( $part, 0, $found_match[1] );
					$matches[] = array(
						'phrase' => $found_match[0],
						'start'  => $position + mb_strlen( $prefix ),
					);
				}
			}
			$position += mb_strlen( $part );
		}
		return $matches;
	}

	public static function link_first( string $content, string $phrase, string $url ): ?string {
		if ( '' === self::safe_url( $url ) || 1 !== count( self::linkable_matches( $content, $phrase ) ) ) {
			return null;
		}
		$parts     = preg_split( '/(<[^>]+>)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
		$in_anchor = false;
		$skip      = 0;
		foreach ( $parts as $index => $part ) {
			if ( '' === $part ) {
				continue;
			}
			if ( '<' === $part[0] ) {
				if ( preg_match( '#^<a[\s>]#i', $part ) ) {
					$in_anchor = true;
				} elseif ( preg_match( '#^</a>#i', $part ) ) {
					$in_anchor = false;
				} elseif ( preg_match( '#^<(h[1-6]|script|style)[\s>]#i', $part ) ) {
					++$skip;
				} elseif ( preg_match( '#^</(h[1-6]|script|style)>#i', $part ) ) {
					$skip = max( 0, $skip - 1 );
				}
				continue;
			}
			if ( $in_anchor || 0 !== $skip || ! preg_match( '/(?<![\pL\pN])' . preg_quote( $phrase, '/' ) . '(?![\pL\pN])/iu', $part, $match, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			$byte_offset     = $match[0][1];
			$written         = $match[0][0];
			$parts[ $index ] = substr( $part, 0, $byte_offset ) . '<a href="' . esc_url( $url ) . '">' . $written . '</a>' . substr( $part, $byte_offset + strlen( $written ) );
			return implode( '', $parts );
		}
		return null;
	}

	private static function opportunity( int $source_id, int $target_id, string $phrase ) {
		foreach ( (array) ( self::snapshot()['opps'] ?? array() ) as $opportunity ) {
			if (
				(int) ( $opportunity['src'] ?? 0 ) === $source_id &&
				(int) ( $opportunity['dst'] ?? 0 ) === $target_id &&
				hash_equals( (string) ( $opportunity['phrase'] ?? '' ), $phrase )
			) {
				return $opportunity;
			}
		}
		return new WP_Error( 'stale_opportunity', __( 'That link opportunity is no longer available.', 'bulk-keyphrase-manager' ) );
	}

	public static function insert_link( int $source_id, int $target_id, string $phrase ) {
		if (
			! current_user_can( 'manage_options' ) ||
			! current_user_can( 'edit_post', $source_id ) ||
			! current_user_can( 'edit_post', $target_id )
		) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'bulk-keyphrase-manager' ) );
		}
		if ( $source_id === $target_id || '' === trim( $phrase ) ) {
			return new WP_Error( 'invalid_opportunity', __( 'Invalid link opportunity.', 'bulk-keyphrase-manager' ) );
		}
		$source = get_post( $source_id );
		$target = get_post( $target_id );
		if (
			! $source || ! $target ||
			'publish' !== $source->post_status || 'publish' !== $target->post_status ||
			! in_array( $source->post_type, self::types(), true ) ||
			! in_array( $target->post_type, self::types(), true )
		) {
			return new WP_Error( 'invalid_posts', __( 'The source or destination is no longer supported and published.', 'bulk-keyphrase-manager' ) );
		}
		$url         = self::safe_url( get_permalink( $target ) );
		$opportunity = self::opportunity( $source_id, $target_id, $phrase );
		$document    = self::source_document( $source_id );
		if ( '' === $url || is_wp_error( $opportunity ) || is_wp_error( $document ) ) {
			return '' === $url ? new WP_Error( 'unsafe_url', __( 'The destination URL is not safe.', 'bulk-keyphrase-manager' ) ) : ( is_wp_error( $opportunity ) ? $opportunity : $document );
		}
		if ( ! hash_equals( (string) $opportunity['fingerprint'], (string) $document['fingerprint'] ) ) {
			return new WP_Error( 'stale_content', __( 'The source content changed after the scan. Scan again.', 'bulk-keyphrase-manager' ) );
		}
		if ( 'elementor' === $document['where'] ) {
			return self::insert_elementor( $source_id, $phrase, $url, (string) $opportunity['node'] );
		}
		return self::insert_classic( $source, $phrase, $url );
	}

	private static function insert_classic( WP_Post $source, string $phrase, string $url ) {
		$original = (string) $source->post_content;
		$updated  = self::link_first( $original, $phrase, $url );
		if ( null === $updated ) {
			return new WP_Error( 'stale_content', __( 'The wording is no longer uniquely linkable.', 'bulk-keyphrase-manager' ) );
		}
		wp_save_post_revision( $source->ID );
		$expected = current_user_can( 'unfiltered_html' ) ? $updated : wp_kses_post( $updated );
		$result   = wp_update_post(
			array(
				'ID'           => $source->ID,
				'post_content' => wp_slash( $updated ),
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		clean_post_cache( $source->ID );
		$stored = get_post( $source->ID );
		if ( ! $stored || $expected !== $stored->post_content ) {
			wp_update_post(
				array(
					'ID'           => $source->ID,
					'post_content' => wp_slash( $original ),
				)
			);
			return new WP_Error( 'read_back_failed', __( 'The link update could not be verified and was rolled back.', 'bulk-keyphrase-manager' ) );
		}
		return array(
			'src'   => $source->ID,
			'where' => 'content',
		);
	}

	public static function is_elementor( int $post_id ): bool {
		return 'builder' === (string) get_post_meta( $post_id, '_elementor_edit_mode', true ) || metadata_exists( 'post', $post_id, '_elementor_data' );
	}

	private static function elementor_tree( int $post_id ) {
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( is_string( $raw ) && strlen( $raw ) > self::EL_MAX_RAW ) {
			return new WP_Error( 'elementor_size', __( 'Elementor data exceeds the safe size limit.', 'bulk-keyphrase-manager' ) );
		}
		$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		return is_array( $data ) && ! empty( $data )
			? $data
			: new WP_Error( 'elementor_malformed', __( 'Elementor data is missing or malformed.', 'bulk-keyphrase-manager' ) );
	}

	private static function elementor_keys( string $widget_type ): array {
		return 'text-editor' === $widget_type ? array( 'editor' ) : array();
	}

	private static function collect_elementor_nodes( array $elements, array &$nodes, int $depth, int &$count, string $prefix = '' ): bool {
		if ( $depth > self::EL_MAX_DEPTH ) {
			return false;
		}
		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) || ++$count > self::EL_MAX_NODES ) {
				return false;
			}
			$path        = '' === $prefix ? (string) $index : $prefix . '.elements.' . $index;
			$widget_type = (string) ( $element['widgetType'] ?? '' );
			if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
				foreach ( self::elementor_keys( $widget_type ) as $key ) {
					$value = $element['settings'][ $key ] ?? null;
					if ( is_string( $value ) && '' !== trim( $value ) && strlen( $value ) <= self::EL_MAX_STRING ) {
						$nodes[] = array(
							'path' => $path . '.settings.' . $key,
							'html' => $value,
						);
					}
				}
			}
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) && ! self::collect_elementor_nodes( $element['elements'], $nodes, $depth + 1, $count, $path ) ) {
				return false;
			}
		}
		return true;
	}

	private static function &elementor_path( array &$tree, string $path, bool &$found ) {
		$parts = explode( '.', $path );
		$value = &$tree;
		$found = true;
		foreach ( $parts as $part ) {
			if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) {
				$found = false;
				break;
			}
			$value = &$value[ $part ];
		}
		return $value;
	}

	private static function insert_elementor( int $post_id, string $phrase, string $url, string $path ) {
		$tree = self::elementor_tree( $post_id );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}
		$original = wp_json_encode( $tree );
		$found    = false;
		$value    = &self::elementor_path( $tree, $path, $found );
		if ( ! $found || ! is_string( $value ) ) {
			return new WP_Error( 'stale_node', __( 'The Elementor text node moved after the scan.', 'bulk-keyphrase-manager' ) );
		}
		$updated = self::link_first( $value, $phrase, $url );
		if ( null === $updated ) {
			return new WP_Error( 'stale_content', __( 'The Elementor wording is no longer uniquely linkable.', 'bulk-keyphrase-manager' ) );
		}
		$value = $updated;
		$json  = wp_json_encode( $tree );
		if ( ! is_string( $original ) || ! is_string( $json ) ) {
			return new WP_Error( 'elementor_encode', __( 'Elementor data could not be encoded.', 'bulk-keyphrase-manager' ) );
		}

		wp_save_post_revision( $post_id );
		$result = update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );
		if ( false === $result ) {
			return new WP_Error( 'elementor_write', __( 'Elementor content could not be updated.', 'bulk-keyphrase-manager' ) );
		}
		wp_cache_delete( $post_id, 'post_meta' );
		$stored = get_post_meta( $post_id, '_elementor_data', true );
		$stored = is_string( $stored ) ? json_decode( $stored, true ) : $stored;
		if ( ! is_array( $stored ) || wp_json_encode( $stored ) !== $json ) {
			update_post_meta( $post_id, '_elementor_data', wp_slash( $original ) );
			return new WP_Error( 'read_back_failed', __( 'The Elementor update could not be verified and was rolled back.', 'bulk-keyphrase-manager' ) );
		}
		delete_post_meta( $post_id, '_elementor_element_cache' );
		clean_post_cache( $post_id );
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
		}
		return array(
			'src'   => $post_id,
			'where' => 'elementor',
		);
	}
}
