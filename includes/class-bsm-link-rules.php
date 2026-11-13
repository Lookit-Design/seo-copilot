<?php
/**
 * Link rules: destinations plus the wording that should point at them.
 *
 * A rule is made by hand (pick a page or an external URL, type phrases) or
 * from a suggestion. Suggestions are read from the last link scan, which keeps
 * the anchor text of every internal link it reads, so the site's own wording
 * is offered first. Phrases carry where they came from, and only harvested
 * ones are ticked by default. Nothing here scans or writes page content.
 *
 * @package Lookit_SEO_Copilot
 */

defined( 'ABSPATH' ) || exit;

class BSM_Link_Rules {

	const OPTION    = 'bsm_links_rules';
	const DISMISSED = 'bsm_links_dismissed';
	const PLACED    = 'bsm_links_placed';

	/** Phrases per rule and rules overall. Workload limits, not technical ones. */
	const PHRASE_CAP = 12;
	const RULE_CAP   = 60;
	/** Newest insertions kept in the ledger. */
	const PLACED_CAP = 200;

	private static function store_option( string $name, array $value ): void {
		if ( false === get_option( $name, false ) ) {
			add_option( $name, $value, '', false );
			return;
		}
		update_option( $name, $value, false );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Rules
	// ─────────────────────────────────────────────────────────────────────────

	/** All rules, valid shape guaranteed. */
	public static function rules(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$out = array();
		foreach ( $stored as $rule ) {
			// A rule points at either a post on this site (dst) or an external
			// address (url). External rules keep dst at 0.
			if ( ! is_array( $rule ) || empty( $rule['id'] ) || ( empty( $rule['dst'] ) && empty( $rule['url'] ) ) || empty( $rule['phrases'] ) ) {
				continue;
			}
			$out[] = array(
				'id'      => sanitize_key( (string) $rule['id'] ),
				'dst'     => (int) ( $rule['dst'] ?? 0 ),
				'url'     => (string) ( $rule['url'] ?? '' ),
				'label'   => (string) ( $rule['label'] ?? '' ),
				'phrases' => array_values( array_filter( array_map( 'strval', (array) $rule['phrases'] ) ) ),
				'max'     => max( 0, (int) ( $rule['max'] ?? 2 ) ), // 0 = no limit.
				'on'      => ! empty( $rule['on'] ),
			);
		}
		return $out;
	}

	/** Is this an external-URL rule rather than a page on this site? */
	public static function is_external( array $rule ): bool {
		return empty( $rule['dst'] ) && ! empty( $rule['url'] );
	}

	/** Display name and address for a rule, whichever kind it is. */
	public static function target( array $rule ): array {
		if ( self::is_external( $rule ) ) {
			$label = '' !== (string) ( $rule['label'] ?? '' ) ? (string) $rule['label'] : (string) wp_parse_url( $rule['url'], PHP_URL_HOST );
			return array( $label, (string) $rule['url'] );
		}
		return array(
			html_entity_decode( wp_strip_all_tags( (string) get_the_title( $rule['dst'] ) ), ENT_QUOTES, 'UTF-8' ),
			(string) get_permalink( $rule['dst'] ),
		);
	}

	/** One rule by ID, or null. */
	public static function find( string $id ): ?array {
		foreach ( self::rules() as $rule ) {
			if ( $rule['id'] === $id ) {
				return $rule;
			}
		}
		return null;
	}

	/** Only the rules the scan should act on. */
	public static function active(): array {
		return array_values(
			array_filter(
				self::rules(),
				static function ( array $rule ): bool {
					if ( empty( $rule['on'] ) ) {
						return false;
					}
					return $rule['dst'] ? 'publish' === get_post_status( $rule['dst'] ) : '' !== $rule['url'];
				}
			)
		);
	}

	/**
	 * A phrase is usable if it is long enough to mean something and short
	 * enough to be anchor text. One word is allowed only when it is a
	 * substantial one: single common words are how internal linking ruins a site.
	 */
	public static function clean_phrase( string $phrase ): string {
		$phrase = html_entity_decode( wp_strip_all_tags( $phrase ), ENT_QUOTES, 'UTF-8' );
		$phrase = mb_strtolower( trim( (string) preg_replace( '/\s+/u', ' ', $phrase ) ) );
		$phrase = (string) preg_replace( '/^[\s\x{0000}.,;:!?"\'()\[\]–—-]+|[\s\x{0000}.,;:!?"\'()\[\]–—-]+$/u', '', $phrase );
		if ( mb_strlen( $phrase ) < 4 || mb_strlen( $phrase ) > 60 ) {
			return '';
		}
		if ( ! substr_count( $phrase, ' ' ) && mb_strlen( $phrase ) < 8 ) {
			return '';
		}
		return in_array( $phrase, self::stop_anchors(), true ) ? '' : $phrase;
	}

	/** Anchor text that says nothing about its destination. */
	public static function stop_anchors(): array {
		$stop = array(
			'here',
			'click here',
			'this page',
			'this',
			'read more',
			'more',
			'learn more',
			'find out more',
			'see more',
			'continue reading',
			'link',
			'this link',
			'more info',
			'more information',
			'view',
			'view more',
			'download',
			'read',
			'see here',
			'this article',
			'the page',
			'page',
			'website',
			'site',
			'home',
			'homepage',
		);
		/** Filter: phrases never harvested as anchor text. */
		return (array) apply_filters( 'bsm_link_stop_anchors', $stop );
	}

	/** Generic phrasings for common page types, matched against slug and title. */
	public static function starter(): array {
		$seed = array(
			'contact'   => array( 'contact us', 'get in touch', 'reach out to us', 'talk to us' ),
			'about'     => array( 'about us', 'our story', 'meet the team' ),
			'team'      => array( 'our team', 'meet the team' ),
			'pricing'   => array( 'our pricing', 'see pricing', 'plans and pricing' ),
			'service'   => array( 'our services', 'what we do' ),
			'blog'      => array( 'our blog', 'latest articles' ),
			'news'      => array( 'latest news' ),
			'career'    => array( 'join our team', 'work with us', 'open roles' ),
			'job'       => array( 'join our team', 'open roles' ),
			'support'   => array( 'get help', 'help centre', 'help center' ),
			'faq'       => array( 'frequently asked questions', 'common questions' ),
			'book'      => array( 'book a call', 'book a consultation' ),
			'schedule'  => array( 'schedule a call' ),
			'consult'   => array( 'free consultation', 'book a consultation' ),
			'quote'     => array( 'get a quote', 'request a quote' ),
			'audit'     => array( 'free audit', 'request an audit' ),
			'case'      => array( 'case studies', 'our work' ),
			'work'      => array( 'our work', 'portfolio' ),
			'portfolio' => array( 'our work', 'portfolio' ),
			'privacy'   => array( 'privacy policy' ),
			'terms'     => array( 'terms of service', 'terms and conditions' ),
			'donate'    => array( 'donate now', 'make a donation' ),
			'event'     => array( 'upcoming events', 'our events' ),
			'resource'  => array( 'our resources', 'resource library' ),
		);
		/** Filter: token => phrases, matched against destination slug and title. */
		return (array) apply_filters( 'bsm_link_starter_phrases', $seed );
	}

	private static function seed_for( string $slug, string $title ): array {
		$haystack = mb_strtolower( $slug . ' ' . $title );
		$out      = array();
		foreach ( self::starter() as $token => $phrases ) {
			if ( false !== strpos( $haystack, (string) $token ) ) {
				$out = array_merge( $out, (array) $phrases );
			}
		}
		return array_values( array_unique( $out ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Map entries handed to the scan
	// ─────────────────────────────────────────────────────────────────────────

	/** Rule phrases as extra destinations for the scan's phrase map. */
	public static function map_entries(): array {
		$out  = array();
		$seen = array();
		foreach ( self::active() as $rule ) {
			list( $title, $url ) = self::target( $rule );
			if ( '' === $url ) {
				continue;
			}
			foreach ( $rule['phrases'] as $phrase ) {
				$phrase = self::clean_phrase( $phrase );
				if ( '' === $phrase || isset( $seen[ $rule['id'] . '|' . $phrase ] ) ) {
					continue;
				}
				$seen[ $rule['id'] . '|' . $phrase ] = true;
				$out[]                               = array(
					'id'    => (int) $rule['dst'],
					'kp'    => $phrase,
					'lower' => $phrase,
					'title' => $title,
					'url'   => $url,
					'src'   => 'rule',
					'rule'  => $rule['id'],
					'max'   => (int) $rule['max'],
					'ext'   => self::is_external( $rule ) ? $rule['id'] : '',
				);
			}
		}
		return $out;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Suggestions
	// ─────────────────────────────────────────────────────────────────────────

	/** Post ID => menu label, across every registered menu. Read once. */
	public static function menu_labels(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$cache = array();
		foreach ( (array) wp_get_nav_menus() as $menu ) {
			foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
				if ( isset( $item->type, $item->object_id ) && 'post_type' === $item->type && $item->object_id ) {
					$cache[ (int) $item->object_id ] = html_entity_decode( wp_strip_all_tags( (string) $item->title ), ENT_QUOTES, 'UTF-8' );
				}
			}
		}
		return $cache;
	}

	/** Wording on the destination itself: menu label, keyphrase, SEO title, title, H1/H2. */
	private static function page_words( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}
		$out  = array();
		$menu = self::menu_labels();
		if ( ! empty( $menu[ $post_id ] ) ) {
			$out[] = array( self::clean_phrase( $menu[ $post_id ] ), __( 'Menu label', 'bulk-keyphrase-manager' ) );
		}
		$keyphrase = trim( (string) get_post_meta( $post_id, BSM_META_KW, true ) );
		if ( '' !== $keyphrase ) {
			$out[] = array( self::clean_phrase( $keyphrase ), __( 'Focus keyphrase', 'bulk-keyphrase-manager' ) );
		}
		$seo_title = trim( (string) get_post_meta( $post_id, BSM_META_TITLE, true ) );
		if ( '' !== $seo_title && false === strpos( $seo_title, '%%' ) ) {
			$out[] = array( self::clean_phrase( $seo_title ), __( 'SEO title', 'bulk-keyphrase-manager' ) );
		}
		$out[] = array( self::clean_phrase( (string) get_the_title( $post ) ), __( 'Page title', 'bulk-keyphrase-manager' ) );

		$html = BSM_Links::is_elementor( $post_id ) ? BSM_Links::el_html( $post_id ) : (string) $post->post_content;
		if ( preg_match_all( '#<h([12])[^>]*>(.*?)</h\1>#is', $html, $headings ) ) {
			foreach ( array_slice( $headings[2], 0, 3 ) as $index => $heading ) {
				$out[] = array(
					self::clean_phrase( (string) $heading ),
					/* translators: %s: heading level, e.g. H1. */
					sprintf( __( '%s on the destination page', 'bulk-keyphrase-manager' ), 'H' . (string) ( $headings[1][ $index ] ?? '2' ) ),
				);
			}
		}
		return array_values(
			array_filter(
				$out,
				static function ( array $pair ): bool {
					return '' !== $pair[0];
				}
			)
		);
	}

	/**
	 * Destinations worth a rule, from the newest snapshot only. Scored on
	 * mentions and evidence over incoming links, lifted for menu pages and for
	 * anything that is not a dated post.
	 */
	public static function suggestions( int $limit = 12 ): array {
		$snapshot = BSM_Links::snapshot();
		if ( empty( $snapshot['cands'] ) || ! is_array( $snapshot['cands'] ) ) {
			return array();
		}
		$have = array();
		foreach ( self::rules() as $rule ) {
			$have[ (int) $rule['dst'] ] = true;
		}
		$dismissed = array_map( 'intval', (array) get_option( self::DISMISSED, array() ) );
		$menu      = self::menu_labels();
		$out       = array();

		foreach ( $snapshot['cands'] as $candidate ) {
			$post_id = (int) ( $candidate['id'] ?? 0 );
			$post    = $post_id ? get_post( $post_id ) : null;
			if ( ! $post || isset( $have[ $post_id ] ) || in_array( $post_id, $dismissed, true ) || 'publish' !== $post->post_status ) {
				continue;
			}
			$phrases = array();
			$seen    = array();

			// 1. Harvested: the site's own vocabulary, ticked by default.
			foreach ( (array) ( $candidate['anchors'] ?? array() ) as $phrase => $pages ) {
				$phrase = self::clean_phrase( (string) $phrase );
				if ( '' === $phrase || isset( $seen[ $phrase ] ) ) {
					continue;
				}
				$seen[ $phrase ] = true;
				$phrases[]       = array(
					'p'   => $phrase,
					'src' => 'harv',
					/* translators: %d: number of pages already using this wording as link text. */
					'ev'  => sprintf( _n( 'Used as link text on %d page', 'Used as link text on %d pages', (int) $pages, 'bulk-keyphrase-manager' ), (int) $pages ),
					'on'  => 1,
				);
			}
			// 2. The destination's own wording, the only source an orphan has.
			foreach ( self::page_words( $post_id ) as $pair ) {
				if ( isset( $seen[ $pair[0] ] ) || count( $phrases ) >= self::PHRASE_CAP ) {
					continue;
				}
				$seen[ $pair[0] ] = true;
				$phrases[]        = array(
					'p'   => $pair[0],
					'src' => 'page',
					'ev'  => $pair[1],
					'on'  => 0,
				);
			}
			// 3. Starter list.
			foreach ( self::seed_for( (string) $post->post_name, (string) ( $candidate['title'] ?? '' ) ) as $phrase ) {
				$phrase = self::clean_phrase( $phrase );
				if ( '' === $phrase || isset( $seen[ $phrase ] ) || count( $phrases ) >= self::PHRASE_CAP ) {
					continue;
				}
				$seen[ $phrase ] = true;
				$phrases[]       = array(
					'p'   => $phrase,
					'src' => 'seed',
					'ev'  => __( 'Starter list', 'bulk-keyphrase-manager' ),
					'on'  => 0,
				);
			}
			if ( empty( $phrases ) ) {
				continue;
			}

			$incoming = (int) ( $candidate['in'] ?? 0 );
			$mentions = (int) ( $candidate['total'] ?? 0 );
			$score    = ( $mentions + count( $phrases ) ) / ( $incoming + 1 );
			if ( ! empty( $menu[ $post_id ] ) ) {
				$score *= 1.4;
			}
			if ( 'post' !== ( $candidate['type'] ?? 'post' ) ) {
				$score *= 1.2;
			}
			$tags = array();
			if ( ! empty( $menu[ $post_id ] ) ) {
				$tags[] = array( 'menu', __( 'In main menu', 'bulk-keyphrase-manager' ) );
			}
			if ( 0 === $incoming ) {
				$tags[] = array( 'hot', __( 'Orphan page', 'bulk-keyphrase-manager' ) );
			}
			if ( $mentions ) {
				/* translators: %d: number of pages naming this destination in plain text. */
				$tags[] = array( '', sprintf( _n( 'Named on %d page', 'Named on %d pages', $mentions, 'bulk-keyphrase-manager' ), $mentions ) );
			}
			$out[] = array(
				'id'      => $post_id,
				'title'   => (string) ( $candidate['title'] ?? '' ),
				'url'     => (string) ( $candidate['url'] ?? '' ),
				'in'      => $incoming,
				'raw'     => $score,
				'tags'    => $tags,
				'phrases' => $phrases,
			);
		}

		usort(
			$out,
			static function ( array $left, array $right ): int {
				return $right['raw'] <=> $left['raw'];
			}
		);
		$out = array_slice( $out, 0, $limit );
		// Normalise to 0-100 so the column means something at a glance.
		$top = ! empty( $out ) ? max( 0.01, (float) $out[0]['raw'] ) : 1.0;
		foreach ( $out as $index => $suggestion ) {
			$out[ $index ]['score'] = (int) round( min( 100, $suggestion['raw'] / $top * 100 ) );
			unset( $out[ $index ]['raw'] );
		}
		return $out;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Placed-link ledger
	// ─────────────────────────────────────────────────────────────────────────

	public static function placed(): array {
		$placed = get_option( self::PLACED, array() );
		return is_array( $placed ) ? $placed : array();
	}

	public static function record_placed( int $source_id, int $target_id, string $phrase, string $where, string $to = '' ): void {
		$rows = self::placed();
		array_unshift(
			$rows,
			array(
				'src'    => $source_id,
				'dst'    => $target_id,
				'phrase' => $phrase,
				'from'   => html_entity_decode( wp_strip_all_tags( (string) get_the_title( $source_id ) ), ENT_QUOTES, 'UTF-8' ),
				'to'     => '' !== $to ? $to : html_entity_decode( wp_strip_all_tags( (string) get_the_title( $target_id ) ), ENT_QUOTES, 'UTF-8' ),
				'edit'   => (string) get_edit_post_link( $source_id, 'raw' ),
				'where'  => $where,
				'time'   => time(),
			)
		);
		self::store_option( self::PLACED, array_slice( $rows, 0, self::PLACED_CAP ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Payload + endpoints
	// ─────────────────────────────────────────────────────────────────────────

	/** Everything the Links view needs about rules, in one shape. */
	public static function payload(): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array();
		}
		$rules = array();
		foreach ( self::rules() as $rule ) {
			list( $title, $url ) = self::target( $rule );
			$rules[]             = array_merge(
				$rule,
				array(
					'title' => $title,
					'url'   => $url,
					'ext'   => self::is_external( $rule ),
				)
			);
		}
		$placed = array();
		foreach ( array_slice( self::placed(), 0, 60 ) as $row ) {
			$row['date'] = date_i18n( get_option( 'date_format' ), (int) ( $row['time'] ?? 0 ) );
			$placed[]    = $row;
		}
		return array(
			'rules'   => $rules,
			'suggest' => self::suggestions(),
			'placed'  => $placed,
		);
	}

	private static function deny(): void {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'bulk-keyphrase-manager' ) ), 403 );
	}

	private static function fail( string $message ): void {
		wp_send_json_error( array( 'message' => $message ), 400 );
	}

	/** Published destinations for the "add a rule by hand" picker. */
	public static function ajax_targets(): void {
		check_ajax_referer( 'bsm_rule_save', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			self::deny();
		}
		$search = isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '';
		$query  = new WP_Query(
			array(
				'post_type'              => BSM_Links::types(),
				'post_status'            => 'publish',
				'posts_per_page'         => 20,
				's'                      => $search,
				'orderby'                => '' !== $search ? 'relevance' : 'title',
				'order'                  => '' !== $search ? 'DESC' : 'ASC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
		$items  = array();
		foreach ( $query->posts as $post ) {
			$items[] = array(
				'id'    => (int) $post->ID,
				'title' => html_entity_decode( wp_strip_all_tags( (string) get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ),
				'url'   => (string) get_permalink( $post ),
			);
		}
		wp_reset_postdata();
		wp_send_json_success( array( 'items' => $items ) );
	}

	/** Create or replace one rule. */
	public static function ajax_save(): void {
		check_ajax_referer( 'bsm_rule_save', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			self::deny();
		}
		$id    = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$dst   = absint( $_POST['dst'] ?? 0 );
		$max   = min( 50, absint( $_POST['max'] ?? 2 ) );
		$url   = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ), array( 'http', 'https' ) ) : '';
		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$raw   = isset( $_POST['phrases'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['phrases'] ) ) : array();

		if ( ! $dst && '' !== $url ) {
			// An address on this site is a page rule, so incoming-link counts and
			// the self-link check keep working.
			$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			if ( '' !== $host && 0 === strcasecmp( (string) wp_parse_url( $url, PHP_URL_HOST ), $host ) ) {
				$dst = (int) url_to_postid( $url );
				if ( ! $dst ) {
					self::fail( __( 'That address is on this site but does not resolve to a published page. Pick the page instead.', 'bulk-keyphrase-manager' ) );
				}
				$url = '';
			}
		}
		if ( $dst ) {
			$url   = '';
			$label = '';
			if ( 'publish' !== get_post_status( $dst ) || ! in_array( get_post_type( $dst ), BSM_Links::types(), true ) ) {
				self::fail( __( 'Choose a published page to link to.', 'bulk-keyphrase-manager' ) );
			}
		} elseif ( '' === BSM_Links::href_url( $url ) ) {
			self::fail( __( 'Choose a published page, or enter a full web address starting with https://.', 'bulk-keyphrase-manager' ) );
		}

		$phrases = array();
		foreach ( $raw as $phrase ) {
			$clean = self::clean_phrase( (string) $phrase );
			if ( '' !== $clean && ! in_array( $clean, $phrases, true ) ) {
				$phrases[] = $clean;
			}
			if ( count( $phrases ) >= self::PHRASE_CAP ) {
				break;
			}
		}
		if ( empty( $phrases ) ) {
			self::fail( __( 'Add at least one phrase. Phrases need four or more characters, and a single word must be at least eight.', 'bulk-keyphrase-manager' ) );
		}

		$rules = self::rules();
		// One destination, one rule: two rules for the same page would race for
		// the same wording and the result would depend on array order.
		foreach ( $rules as $rule ) {
			$same = $dst
				? (int) $rule['dst'] === $dst
				: ( '' !== $rule['url'] && BSM_Links::norm_ext( $rule['url'] ) === BSM_Links::norm_ext( $url ) );
			if ( $same && $rule['id'] !== $id ) {
				self::fail( __( 'There is already a rule for that destination. Edit it instead of adding a second one.', 'bulk-keyphrase-manager' ) );
			}
		}

		$found = false;
		foreach ( $rules as $index => $rule ) {
			if ( '' !== $id && $rule['id'] === $id ) {
				$rules[ $index ] = array_merge(
					$rule,
					array(
						'dst'     => $dst,
						'url'     => $url,
						'label'   => $label,
						'phrases' => $phrases,
						'max'     => $max,
					)
				);
				$found           = true;
				break;
			}
		}
		if ( ! $found ) {
			if ( count( $rules ) >= self::RULE_CAP ) {
				self::fail( __( 'That is as many rules as one site can usefully run.', 'bulk-keyphrase-manager' ) );
			}
			$rules[] = array(
				'id'      => 'r' . strtolower( wp_generate_password( 12, false, false ) ),
				'dst'     => $dst,
				'url'     => $url,
				'label'   => $label,
				'phrases' => $phrases,
				'max'     => $max,
				'on'      => true,
			);
		}
		self::store_option( self::OPTION, array_values( $rules ) );
		wp_send_json_success( self::payload() );
	}

	/** Delete a rule, pause it, dismiss a suggestion, or re-read after a scan. */
	public static function ajax_update(): void {
		check_ajax_referer( 'bsm_rule_update', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			self::deny();
		}
		$action = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$id     = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';

		if ( 'dismiss' === $action ) {
			$dst = absint( $_POST['dst'] ?? 0 );
			if ( $dst ) {
				$dismissed = array_map( 'intval', (array) get_option( self::DISMISSED, array() ) );
				if ( ! in_array( $dst, $dismissed, true ) ) {
					$dismissed[] = $dst;
				}
				self::store_option( self::DISMISSED, array_slice( $dismissed, -300 ) );
			}
			wp_send_json_success( self::payload() );
		}
		if ( 'refresh' === $action ) {
			wp_send_json_success( self::payload() );
		}
		if ( 'restore' === $action ) {
			self::store_option( self::DISMISSED, array() );
			wp_send_json_success( self::payload() );
		}

		$rules = self::rules();
		foreach ( $rules as $index => $rule ) {
			if ( $rule['id'] !== $id ) {
				continue;
			}
			if ( 'delete' === $action ) {
				unset( $rules[ $index ] );
			} elseif ( 'toggle' === $action ) {
				$rules[ $index ]['on'] = empty( $rule['on'] );
			} else {
				self::fail( __( 'Unknown rule action.', 'bulk-keyphrase-manager' ) );
			}
			self::store_option( self::OPTION, array_values( $rules ) );
			wp_send_json_success( self::payload() );
		}
		self::fail( __( 'That rule no longer exists.', 'bulk-keyphrase-manager' ) );
	}
}
