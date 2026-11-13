<?php
/**
 * Internal-link scanning and insertion engine (Reports → Links).
 *
 * Link rules (BSM_Link_Rules) feed extra destinations into the scan map,
 * including external URLs. Rule matches have their own budget so automatic
 * keyphrase matches can never crowd them out.
 *
 * @package Lookit_SEO_Copilot
 */

defined( 'ABSPATH' ) || exit;

class BSM_Links {

	const OPTION       = 'bsm_links_snapshot';
	const IGNORED      = 'bsm_links_ignored';
	const STATE_OPTION = 'bsm_links_scan_state';
	const LEGACY_ACC   = 'bsm_links_acc_state';
	const LEGACY_MAP   = 'bsm_links_map_state';
	const SCHEMA       = 1;
	const BATCH        = 25;
	const MAP_BATCH    = 100;
	const STATE_TTL    = 900;
	const SOURCE_CAP   = 2000;
	const MAP_CAP      = 1000;
	const OPP_CAP      = 250;
	const RULE_CAP     = 500;
	const PER_SOURCE   = 3;
	const ANCHOR_CAP   = 10;
	const CAND_CAP     = 40;
	const INCOMING_CAP = 3000;
	const SNIP_PAD     = 70;

	/** URL => post ID, for one scan batch only. */
	private static array $resolved = array();
	const LIST_CAP                 = 25;
	const IGNORE_CAP               = 500;
	const MAX_HTML                 = 1000000;
	const EL_MAX_RAW               = 2000000;
	const EL_MAX_DEPTH             = 30;
	const EL_MAX_NODES             = 5000;
	const EL_MAX_STRING            = 500000;

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
			'n_rule'   => 0,
			'n_auto'   => 0,
			// Destination ID => phrase => distinct source pages using it as link text.
			'anchors'  => array(),
			// Destination ID => pages naming it in linkable plain text.
			'mentions' => array(),
		);
	}

	/** Compare external hosts case-insensitively while preserving path and query case. */
	public static function norm_ext( string $url ): string {
		$url = trim( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) );
		return (string) preg_replace_callback(
			'~^(?:https?:)?//([^/\?#]+)~i',
			static function ( array $parts ): string {
				return (string) preg_replace( '/^www\./i', '', strtolower( $parts[1] ) );
			},
			rtrim( $url, '/' )
		);
	}

	/** Ignore-list key for an opportunity: external rules key on the rule ID. */
	public static function opp_key( array $opportunity ): string {
		$ext = (string) ( $opportunity['ext'] ?? '' );
		return (int) ( $opportunity['src'] ?? 0 ) . ':' . ( '' !== $ext ? $ext : (int) ( $opportunity['dst'] ?? 0 ) );
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
			$state['map']       = self::with_rules( self::normalise_map( $state['map'] ) );
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

	/**
	 * Add active link-rule phrases to the normalised keyphrase map. Rules join
	 * after the keyphrase dedupe and cap, so neither can drop them, then the
	 * whole map is re-sorted longest phrase first for the overlap check.
	 */
	private static function with_rules( array $map ): array {
		if ( ! class_exists( 'BSM_Link_Rules' ) ) {
			return $map;
		}
		$rules = BSM_Link_Rules::map_entries();
		if ( empty( $rules ) ) {
			return $map;
		}
		$map = array_merge( $rules, $map );
		usort(
			$map,
			static function ( array $left, array $right ): int {
				$length = mb_strlen( $right['kp'] ) <=> mb_strlen( $left['kp'] );
				if ( 0 !== $length ) {
					return $length;
				}
				$rule = ( 'rule' === ( $right['src'] ?? '' ) ) <=> ( 'rule' === ( $left['src'] ?? '' ) );
				return 0 !== $rule ? $rule : ( $left['id'] <=> $right['id'] );
			}
		);
		return $map;
	}

	private static function fold_sources( array &$state, array $posts ): void {
		self::$resolved = array();
		$ignored        = self::ignored();
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
		if ( is_wp_error( $source ) ) {
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
		$mentioned  = array();
		foreach ( $map as $target ) {
			$ext     = (string) ( $target['ext'] ?? '' );
			$is_rule = 'rule' === ( $target['src'] ?? '' );
			if ( '' === $ext && (int) $target['id'] === (int) $post->ID ) {
				continue;
			}
			$matches = self::document_matches( $source, $target['kp'] );
			if ( empty( $matches ) ) {
				continue;
			}
			// Counted whether or not a row is offered: a page that already links
			// to the destination still proves the wording is used.
			if ( '' === $ext && ! isset( $mentioned[ $target['id'] ] ) ) {
				$mentioned[ $target['id'] ]             = true;
				$aggregate['mentions'][ $target['id'] ] = 1 + (int) ( $aggregate['mentions'][ $target['id'] ] ?? 0 );
			}
			$already = '' === $ext
				? isset( $linked['ids'][ $target['id'] ] )
				: isset( $linked['ext'][ self::norm_ext( (string) $target['url'] ) ] );
			if ( $already || in_array( $post->ID . ':' . ( '' !== $ext ? $ext : (int) $target['id'] ), $ignored, true ) ) {
				continue;
			}
			// An automatic keyphrase match must be unique on the page so the
			// insert is unambiguous. A rule is an explicit instruction, so it may
			// match more than once and links the first linkable occurrence; the
			// content fingerprint still pins the insert to what was scanned.
			if ( ! $is_rule && 1 !== count( $matches ) ) {
				continue;
			}
			$candidates[] = array(
				'target' => $target,
				'match'  => $matches[0],
				'first'  => count( $matches ) > 1,
			);
		}

		$accepted = array();
		$found    = 0;
		$per_dst  = array();
		foreach ( $candidates as $candidate ) {
			$target  = $candidate['target'];
			$ext     = (string) ( $target['ext'] ?? '' );
			$is_rule = 'rule' === ( $target['src'] ?? '' );
			$dkey    = '' !== $ext ? 'x:' . $ext : (string) $target['id'];
			if ( $is_rule ) {
				$cap = (int) ( $target['max'] ?? 2 );
				if ( $aggregate['n_rule'] >= self::RULE_CAP || ( $cap > 0 && ( $per_dst[ $dkey ] ?? 0 ) >= $cap ) ) {
					continue;
				}
			} elseif ( $found >= self::PER_SOURCE || $aggregate['n_auto'] >= self::OPP_CAP ) {
				continue;
			}
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
			$accepted[]          = array(
				'node'  => $key,
				'start' => $start,
				'end'   => $end,
			);
			$match               = $candidate['match'];
			$snippet             = self::snippet( $source, $match );
			$aggregate['opps'][] = array(
				'src'         => (int) $post->ID,
				'dst'         => '' !== $ext ? 0 : (int) $target['id'],
				'phrase'      => $match['phrase'],
				'snippet'     => $snippet['text'],
				'lead'        => $snippet['lead'],
				'from'        => $aggregate['pages'][ $post->ID ]['title'],
				'from_ed'     => $aggregate['pages'][ $post->ID ]['edit'],
				'to'          => $target['title'],
				'to_url'      => $target['url'],
				'where'       => $source['where'],
				'node'        => $match['node'],
				'fingerprint' => $source['fingerprint'],
				'via'         => $is_rule ? 'rule' : 'auto',
				'rule'        => (string) ( $target['rule'] ?? '' ),
				'ext'         => $ext,
				'first'       => ! empty( $candidate['first'] ),
			);
			$per_dst[ $dkey ]    = 1 + (int) ( $per_dst[ $dkey ] ?? 0 );
			if ( $is_rule ) {
				++$aggregate['n_rule'];
			} else {
				++$aggregate['n_auto'];
				++$found;
			}
		}
	}

	/** Plain-text context around a match, for the review table. */
	private static function snippet( array $source, array $occurrence ): array {
		$html = '';
		foreach ( $source['nodes'] as $node ) {
			if ( $node['path'] === $occurrence['node'] ) {
				$html = (string) $node['html'];
				break;
			}
		}
		$plain = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) ) );
		$pos   = mb_stripos( $plain, $occurrence['phrase'] );
		if ( false === $pos ) {
			return array(
				'text' => $occurrence['phrase'],
				'lead' => false,
			);
		}
		$start = max( 0, $pos - self::SNIP_PAD );
		return array(
			'text' => trim( mb_substr( $plain, $start, mb_strlen( $occurrence['phrase'] ) + 2 * self::SNIP_PAD ) ),
			'lead' => $start > 0,
		);
	}

	private static function existing_targets( string $html, array &$aggregate, WP_Post $post ): array {
		$result = array(
			'ids'   => array(),
			'ext'   => array(),
			'count' => 0,
		);
		self::harvest_anchors( $html, $aggregate );
		if ( ! preg_match_all( '#<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>#i', $html, $matches ) ) {
			return $result;
		}
		foreach ( $matches[1] as $href ) {
			$url = self::normalise_internal_url( $href );
			if ( '' === $url ) {
				// Remember external addresses so an external rule is not offered
				// on a page that already links there.
				$external = trim( html_entity_decode( $href, ENT_QUOTES, 'UTF-8' ) );
				if ( 0 === strpos( $external, '//' ) ) {
					$external = 'https:' . $external;
				}
				$external = self::href_url( $external );
				if ( '' !== $external ) {
					$result['ext'][ self::norm_ext( $external ) ] = true;
				}
				continue;
			}
			++$result['count'];
			$target_id = self::resolve( $url );
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

	/**
	 * Keep the anchor text of internal links, once per source page, so rule
	 * suggestions use the site's own wording. Runs as its own pass: pairing an
	 * anchor with its closing tag would drop pages with unclosed anchors from
	 * the link count, so the counting walk keeps its opening-tag-only match.
	 */
	private static function harvest_anchors( string $html, array &$aggregate ): void {
		if ( ! class_exists( 'BSM_Link_Rules' ) || ! preg_match_all( '#<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $html, $hits, PREG_SET_ORDER ) ) {
			return;
		}
		$seen = array();
		foreach ( $hits as $hit ) {
			$url = self::normalise_internal_url( (string) $hit[1] );
			if ( '' === $url ) {
				continue;
			}
			$target_id = self::resolve( $url );
			$text      = $target_id ? BSM_Link_Rules::clean_phrase( (string) $hit[2] ) : '';
			if ( '' === $text || isset( $seen[ $target_id . '|' . $text ] ) ) {
				continue;
			}
			$seen[ $target_id . '|' . $text ] = true;
			$list                             = $aggregate['anchors'][ $target_id ] ?? array();
			if ( isset( $list[ $text ] ) || count( $list ) < self::ANCHOR_CAP ) {
				$list[ $text ] = 1 + (int) ( $list[ $text ] ?? 0 );
			}
			$aggregate['anchors'][ $target_id ] = $list;
		}
	}

	/** Resolve an internal URL to a post ID, once per URL per request. */
	private static function resolve( string $url ): int {
		if ( ! isset( self::$resolved[ $url ] ) ) {
			if ( count( self::$resolved ) > 5000 ) {
				self::$resolved = array();
			}
			self::$resolved[ $url ] = (int) url_to_postid( $url );
		}
		return self::$resolved[ $url ];
	}

	private static function normalise_internal_url( string $href ): string {
		$href = trim( html_entity_decode( $href, ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $href || '#' === $href[0] ) {
			return '';
		}
		if ( 0 === strpos( $href, '//' ) ) {
			$href = (string) wp_parse_url( home_url(), PHP_URL_SCHEME ) . ':' . $href;
		} elseif ( '/' === $href[0] ) {
			$href = home_url( $href );
		}
		// Host first: safe_url() resolves DNS for any host other than this
		// site's, so checking it before the host compare cost a lookup per
		// external link on every scan.
		$home_host = mb_strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$href_host = mb_strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
		if ( '' === $home_host || $home_host !== $href_host ) {
			return '';
		}
		$url      = self::safe_url( $href );
		$url_host = mb_strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return '' !== $url && $home_host === $url_host ? $url : '';
	}

	/**
	 * An address safe to write into an href: http(s) with a host, escaped.
	 * Unlike safe_url() it never resolves DNS, since nothing is fetched; used
	 * for external rule addresses and for the anchor written by link_first().
	 */
	public static function href_url( $url ): string {
		$url    = esc_url_raw( (string) $url, array( 'http', 'https' ) );
		$scheme = mb_strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$host   = (string) wp_parse_url( $url, PHP_URL_HOST );
		return in_array( $scheme, array( 'http', 'https' ), true ) && '' !== $host ? $url : '';
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
		// Rule matches first (somebody asked for those by name), then the
		// automatic ones, fewest incoming links first.
		usort(
			$aggregate['opps'],
			static function ( array $left, array $right ): int {
				$rule_order = ( 'rule' === ( $right['via'] ?? '' ) ) <=> ( 'rule' === ( $left['via'] ?? '' ) );
				if ( 0 !== $rule_order ) {
					return $rule_order;
				}
				$incoming_order = $left['in'] <=> $right['in'];
				if ( 0 !== $incoming_order ) {
					return $incoming_order;
				}
				$source_order = $left['src'] <=> $right['src'];
				return 0 !== $source_order ? $source_order : ( $left['dst'] <=> $right['dst'] );
			}
		);
		$top_in = $incoming;
		arsort( $top_in );
		$top = array();
		foreach ( array_slice( $top_in, 0, 5, true ) as $post_id => $count ) {
			$top[] = array(
				'title' => (string) ( $aggregate['pages'][ $post_id ]['title'] ?? html_entity_decode( wp_strip_all_tags( get_the_title( (int) $post_id ) ), ENT_QUOTES, 'UTF-8' ) ),
				'n'     => (int) $count,
			);
		}
		$snapshot = array(
			'schema'    => self::SCHEMA,
			'version'   => BSM_VERSION,
			'generated' => time(),
			'scanned'   => (int) $aggregate['scanned'],
			'dest_n'    => count( $state['map'] ),
			'titles'    => ! empty( $state['titles'] ),
			'opps'      => array_slice( array_values( $aggregate['opps'] ), 0, self::OPP_CAP + self::RULE_CAP ),
			'orphans'   => array_slice( array_values( $orphans ), 0, self::LIST_CAP ),
			'orphan_n'  => count( $orphans ),
			'broken'    => array_slice( array_values( $aggregate['broken'] ), 0, self::LIST_CAP ),
			'no_out'    => (int) $aggregate['no_out'],
			'top'       => $top,
			// Full incoming count per page, so SEO Health can say what links to a
			// page without a site-wide query of its own.
			'incoming'  => array_map( 'intval', array_slice( $incoming, 0, self::INCOMING_CAP, true ) ),
			'cands'     => self::candidates( $aggregate ),
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

	/**
	 * Destinations the site names often and links to rarely, with the evidence
	 * (mention count and harvested anchor text) attached. Feeds the rule
	 * suggestions; trimmed hard because the snapshot is one option row.
	 */
	private static function candidates( array $aggregate ): array {
		$incoming = (array) $aggregate['incoming'];
		$mentions = (array) $aggregate['mentions'];
		$anchors  = (array) $aggregate['anchors'];
		$out      = array();
		foreach ( array_unique( array_merge( array_keys( $mentions ), array_keys( $anchors ) ) ) as $post_id ) {
			$post_id = (int) $post_id;
			$page    = $aggregate['pages'][ $post_id ] ?? null;
			$in      = (int) ( $incoming[ $post_id ] ?? 0 );
			$total   = (int) ( $mentions[ $post_id ] ?? 0 );
			$words   = (array) ( $anchors[ $post_id ] ?? array() );
			// Missing from the scan, nothing to go on, or already well linked.
			if ( ! $page || ( ! $total && ! $words ) || $in > 20 ) {
				continue;
			}
			$out[] = array(
				'id'      => $post_id,
				'title'   => (string) $page['title'],
				'url'     => (string) $page['url'],
				'type'    => (string) $page['type'],
				'in'      => $in,
				'total'   => $total,
				'anchors' => array_map( 'intval', $words ),
				'rank'    => ( $total + count( $words ) ) / ( $in + 1 ),
			);
		}
		usort(
			$out,
			static function ( array $left, array $right ): int {
				$rank = $right['rank'] <=> $left['rank'];
				return 0 !== $rank ? $rank : ( $left['id'] <=> $right['id'] );
			}
		);
		$out = array_slice( $out, 0, self::CAND_CAP );
		foreach ( $out as &$candidate ) {
			unset( $candidate['rank'] );
		}
		unset( $candidate );
		return $out;
	}

	/** The stored snapshot shaped for the Links view: ignored rows dropped, date added. */
	public static function payload(): array {
		$snapshot = self::snapshot();
		if ( empty( $snapshot ) ) {
			return array();
		}
		$ignored = self::ignored();
		$opps    = array();
		foreach ( (array) ( $snapshot['opps'] ?? array() ) as $opportunity ) {
			if ( in_array( self::opp_key( $opportunity ), $ignored, true ) ) {
				continue;
			}
			// The fingerprint and node path stay server-side; the insert looks
			// the row up again from the stored snapshot.
			unset( $opportunity['fingerprint'], $opportunity['node'] );
			$opportunity['inline'] = true;
			$opps[]                = $opportunity;
		}
		$snapshot['opps'] = $opps;
		$snapshot['date'] = ! empty( $snapshot['generated'] ) ? date_i18n( get_option( 'date_format' ), (int) $snapshot['generated'] ) : '';
		unset( $snapshot['incoming'], $snapshot['cands'] );
		return $snapshot;
	}

	/** Incoming internal-link counts from the last scan, or null if there is none. */
	public static function incoming(): ?array {
		$snapshot = self::snapshot();
		return isset( $snapshot['incoming'] ) && is_array( $snapshot['incoming'] ) ? $snapshot['incoming'] : null;
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
		$parts = preg_split( '/(<!--.*?-->|<(?:"[^"]*"|\'[^\']*\'|[^\'">])*>)/s', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
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
				} elseif ( preg_match( '#^<(h[1-6]|script|style|textarea|pre|code|template|noscript)[\s>]#i', $part ) ) {
					++$skip;
				} elseif ( preg_match( '#^</(h[1-6]|script|style|textarea|pre|code|template|noscript)>#i', $part ) ) {
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

	/**
	 * Wrap the linkable occurrence of $phrase in an anchor. By default the
	 * phrase must occur exactly once; $first_of_many accepts several and links
	 * the first (rule rows, whose content is pinned by the scan fingerprint).
	 */
	public static function link_first( string $content, string $phrase, string $url, bool $first_of_many = false ): ?string {
		$count = count( self::linkable_matches( $content, $phrase ) );
		if ( '' === self::href_url( $url ) || 0 === $count || ( ! $first_of_many && 1 !== $count ) ) {
			return null;
		}
		$parts     = preg_split( '/(<!--.*?-->|<(?:"[^"]*"|\'[^\']*\'|[^\'">])*>)/s', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
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
				} elseif ( preg_match( '#^<(h[1-6]|script|style|textarea|pre|code|template|noscript)[\s>]#i', $part ) ) {
					++$skip;
				} elseif ( preg_match( '#^</(h[1-6]|script|style|textarea|pre|code|template|noscript)>#i', $part ) ) {
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

	private static function opportunity( int $source_id, int $target_id, string $phrase, string $ext = '' ) {
		foreach ( (array) ( self::snapshot()['opps'] ?? array() ) as $opportunity ) {
			if (
				(int) ( $opportunity['src'] ?? 0 ) === $source_id &&
				(int) ( $opportunity['dst'] ?? 0 ) === $target_id &&
				(string) ( $opportunity['ext'] ?? '' ) === $ext &&
				hash_equals( (string) ( $opportunity['phrase'] ?? '' ), $phrase )
			) {
				return $opportunity;
			}
		}
		return new WP_Error( 'stale_opportunity', __( 'That link opportunity is no longer available.', 'bulk-keyphrase-manager' ) );
	}

	/**
	 * Insert one reviewed link. $ext names an external link rule; its address
	 * is read from the saved rule, never from the request.
	 */
	public static function insert_link( int $source_id, int $target_id, string $phrase, string $ext = '' ) {
		$ext = sanitize_key( $ext );
		if (
			! current_user_can( 'manage_options' ) ||
			! current_user_can( 'edit_post', $source_id ) ||
			( '' === $ext && ! current_user_can( 'edit_post', $target_id ) )
		) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'bulk-keyphrase-manager' ) );
		}
		if ( ( '' === $ext ? $source_id === $target_id : 0 !== $target_id ) || '' === trim( $phrase ) ) {
			return new WP_Error( 'invalid_opportunity', __( 'Invalid link opportunity.', 'bulk-keyphrase-manager' ) );
		}
		$source = get_post( $source_id );
		$target = '' === $ext ? get_post( $target_id ) : null;
		if (
			! $source || 'publish' !== $source->post_status ||
			! in_array( $source->post_type, self::types(), true ) ||
			( '' === $ext && ( ! $target || 'publish' !== $target->post_status || ! in_array( $target->post_type, self::types(), true ) ) )
		) {
			return new WP_Error( 'invalid_posts', __( 'The source or destination is no longer supported and published.', 'bulk-keyphrase-manager' ) );
		}
		$label = '';
		if ( '' !== $ext ) {
			$rule = class_exists( 'BSM_Link_Rules' ) ? BSM_Link_Rules::find( $ext ) : null;
			if ( ! $rule || ! BSM_Link_Rules::is_external( $rule ) ) {
				return new WP_Error( 'stale_rule', __( 'That link rule no longer exists.', 'bulk-keyphrase-manager' ) );
			}
			list( $label, $url ) = BSM_Link_Rules::target( $rule );
			$url                 = self::href_url( $url );
		} else {
			$url = self::safe_url( get_permalink( $target ) );
		}
		if ( in_array( $source_id . ':' . ( '' !== $ext ? $ext : $target_id ), self::ignored(), true ) ) {
			return new WP_Error( 'ignored_opportunity', __( 'This link opportunity was ignored.', 'bulk-keyphrase-manager' ) );
		}
		$opportunity = self::opportunity( $source_id, $target_id, $phrase, $ext );
		$document    = self::source_document( $source_id );
		if ( '' === $url || is_wp_error( $opportunity ) || is_wp_error( $document ) ) {
			return '' === $url ? new WP_Error( 'unsafe_url', __( 'The destination URL is not safe.', 'bulk-keyphrase-manager' ) ) : ( is_wp_error( $opportunity ) ? $opportunity : $document );
		}
		if ( 'rule' === ( $opportunity['via'] ?? '' ) ) {
			$current_rule = BSM_Link_Rules::find( (string) $opportunity['rule'] );
			if (
				! $current_rule || empty( $current_rule['on'] ) ||
				(int) $current_rule['dst'] !== $target_id ||
				! in_array( BSM_Link_Rules::clean_phrase( $phrase ), $current_rule['phrases'], true ) ||
				$url !== (string) $opportunity['to_url']
			) {
				return new WP_Error( 'stale_rule', __( 'The link rule changed after the scan. Scan again.', 'bulk-keyphrase-manager' ) );
			}
		}
		if ( ! hash_equals( (string) $opportunity['fingerprint'], (string) $document['fingerprint'] ) ) {
			return new WP_Error( 'stale_content', __( 'The source content changed after the scan. Scan again.', 'bulk-keyphrase-manager' ) );
		}
		$first  = ! empty( $opportunity['first'] );
		$result = 'elementor' === $document['where']
			? self::insert_elementor( $source_id, $phrase, $url, (string) $opportunity['node'], $first )
			: self::insert_classic( $source, $phrase, $url, $first );
		if ( ! is_wp_error( $result ) && class_exists( 'BSM_Link_Rules' ) ) {
			BSM_Link_Rules::record_placed( $source_id, $target_id, $phrase, (string) $result['where'], $label );
		}
		return $result;
	}

	private static function insert_classic( WP_Post $source, string $phrase, string $url, bool $first = false ) {
		$original = (string) $source->post_content;
		$updated  = self::link_first( $original, $phrase, $url, $first );
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

	private static function insert_elementor( int $post_id, string $phrase, string $url, string $path, bool $first = false ) {
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
		$updated = self::link_first( $value, $phrase, $url, $first );
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

	/** Hide one opportunity from future views and scans. */
	public static function ignore( int $source_id, int $target_id, string $ext = '' ) {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $source_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'bulk-keyphrase-manager' ) );
		}
		$ext = sanitize_key( $ext );
		if ( ! $source_id || ( '' === $ext && ! $target_id ) ) {
			return new WP_Error( 'invalid_opportunity', __( 'Invalid link opportunity.', 'bulk-keyphrase-manager' ) );
		}
		$key  = self::opp_key(
			array(
				'src' => $source_id,
				'dst' => $target_id,
				'ext' => $ext,
			)
		);
		$list = self::ignored();
		if ( ! in_array( $key, $list, true ) ) {
			$list[] = $key;
		}
		self::store_option( self::IGNORED, array_slice( $list, -self::IGNORE_CAP ) );
		return true;
	}

	/**
	 * Editable text plus headings from an Elementor page, as HTML. Used to read
	 * a destination's own wording for rule suggestions; never written back.
	 */
	public static function el_html( int $post_id ): string {
		if ( ! self::is_elementor( $post_id ) ) {
			return '';
		}
		$tree = self::elementor_tree( $post_id );
		if ( is_wp_error( $tree ) ) {
			return '';
		}
		$parts = array();
		$count = 0;
		self::collect_elementor_text( $tree, $parts, 0, $count );
		return implode( "\n", $parts );
	}

	private static function collect_elementor_text( array $elements, array &$parts, int $depth, int &$count ): void {
		if ( $depth > self::EL_MAX_DEPTH ) {
			return;
		}
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) || ++$count > self::EL_MAX_NODES ) {
				return;
			}
			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
			$type     = (string) ( $element['widgetType'] ?? '' );
			if ( 'heading' === $type && is_string( $settings['title'] ?? null ) ) {
				$tag     = in_array( $settings['header_size'] ?? 'h2', array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ? ( $settings['header_size'] ?? 'h2' ) : 'h2';
				$parts[] = '<' . $tag . '>' . $settings['title'] . '</' . $tag . '>';
			} elseif ( 'text-editor' === $type && is_string( $settings['editor'] ?? null ) && strlen( $settings['editor'] ) <= self::EL_MAX_STRING ) {
				$parts[] = $settings['editor'];
			}
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::collect_elementor_text( $element['elements'], $parts, $depth + 1, $count );
			}
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// AJAX (registered only when BSM_LINKS_UI is on)
	// ─────────────────────────────────────────────────────────────────────────

	private static function send_error( WP_Error $error ): void {
		wp_send_json_error(
			array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'restart' => 'stale_scan' === $error->get_error_code(),
			),
			'forbidden' === $error->get_error_code() ? 403 : 400
		);
	}

	public static function ajax_scan(): void {
		check_ajax_referer( 'bsm_links_scan', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			self::send_error( new WP_Error( 'forbidden', __( 'Permission denied.', 'bulk-keyphrase-manager' ) ) );
		}
		$phase   = isset( $_POST['phase'] ) ? sanitize_key( wp_unslash( $_POST['phase'] ) ) : '';
		$offset  = min( 100000000, absint( $_POST['offset'] ?? 0 ) );
		$scan_id = isset( $_POST['scan_id'] ) ? sanitize_text_field( wp_unslash( $_POST['scan_id'] ) ) : '';
		$titles  = ! empty( $_POST['titles'] );
		$result  = 'start' === $phase
			? self::start_scan( $titles, true )
			: self::scan_batch( $phase, $offset, $scan_id );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}
		if ( ! empty( $result['complete'] ) ) {
			$result['snapshot'] = self::payload();
		}
		wp_send_json_success( $result );
	}

	public static function ajax_insert(): void {
		check_ajax_referer( 'bsm_links_insert', 'nonce' );
		$result = self::insert_link(
			absint( $_POST['src'] ?? 0 ),
			absint( $_POST['dst'] ?? 0 ),
			isset( $_POST['phrase'] ) ? sanitize_text_field( wp_unslash( $_POST['phrase'] ) ) : '',
			isset( $_POST['ext'] ) ? sanitize_key( wp_unslash( $_POST['ext'] ) ) : ''
		);
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}
		wp_send_json_success( $result );
	}

	public static function ajax_ignore(): void {
		check_ajax_referer( 'bsm_links_insert', 'nonce' );
		$result = self::ignore(
			absint( $_POST['src'] ?? 0 ),
			absint( $_POST['dst'] ?? 0 ),
			isset( $_POST['ext'] ) ? sanitize_key( wp_unslash( $_POST['ext'] ) ) : ''
		);
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}
		wp_send_json_success();
	}
}
