<?php
/**
 * Lookit Bulk SEO Manager — SEO Health tab.
 *
 * On-page audit engine. Runs entirely inside WordPress with no external
 * calls: it reads Yoast meta and parses post content to score each post.
 *
 * Platform-dependent checks (Core Web Vitals via PageSpeed, full-site crawl,
 * Google Search Console, AI content suggestions) are rendered as designed but
 * in a "pending — connect platform" state. Those run through the metered
 * platform (n8n → AWS), never in the plugin — see the handoff notes for Vadim.
 *
 * @package Lookit_Bulk_SEO_Manager
 */

defined( 'ABSPATH' ) || exit;

class BSM_Health {

	/** Word-count thresholds. */
	const WORDS_THIN = 300;
	const WORDS_OK   = 600;

	/** SEO title length (characters). */
	const TITLE_OK  = 60;
	const TITLE_MAX = 70;

	// Focus tab: how many candidate posts to audit per pick. Bounds the cost on
	// large sites — see focus_pick() and the Vadim note about caching a full-site
	// ranking for exactness at scale.
	const FOCUS_SCAN_CAP = 40;

	// ─────────────────────────────────────────────────────────────────────────
	// Entry point
	// ─────────────────────────────────────────────────────────────────────────

	public static function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'bulk-keyphrase-manager' ) );
		}

		// Read-only routing — no state change, so no nonce required.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$audit_id  = absint( $_GET['audit_post'] ?? 0 );
		$htype_get = isset( $_GET['htype'] ) ? sanitize_key( wp_unslash( $_GET['htype'] ) ) : '';
		$paged     = max( 1, absint( $_GET['hpaged'] ?? 1 ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$all_types = bsm_get_post_types();

		// Sticky filter: remember the last chosen post type per user so the choice
		// survives leaving and returning to the tab. An explicit ?htype in the URL
		// sets it; otherwise fall back to the saved preference.
		$uid = get_current_user_id();
		if ( '' !== $htype_get ) {
			$htype = $htype_get;
			update_user_meta( $uid, 'bsm_health_htype', $htype );
		} else {
			$htype = (string) get_user_meta( $uid, 'bsm_health_htype', true );
			if ( '' === $htype ) {
				$htype = 'all';
			}
		}
		if ( 'all' !== $htype && ! isset( $all_types[ $htype ] ) ) {
			$htype = 'all';
		}

		echo '<div class="wrap" id="bsm-wrap">';
		bsm_topbar();
		bsm_render_tabs( 'health' );

		if ( ! class_exists( 'WPSEO_Meta' ) && ! defined( 'WPSEO_VERSION' ) ) {
			echo '<div class="notice notice-warning"><p><strong>' .
				esc_html__( 'Yoast SEO not active.', 'bulk-keyphrase-manager' ) . '</strong> ' .
				esc_html__( 'Keyphrase and meta-description checks read Yoast fields, so they will be blank until Yoast is active.', 'bulk-keyphrase-manager' ) .
				'</p></div>';
		}

		if ( $audit_id ) {
			self::render_detail( $audit_id );
		} else {
			self::render_pages( $all_types, $htype, $paged );
		}

		echo '</div>';
	}

	private static function base_url( array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => 'lookit-bulk-seo',
					'tab'  => 'health',
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Focus tab — surface one page to work on next (reuses the audit engine)
	// ─────────────────────────────────────────────────────────────────────────

	public static function render_focus(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'bulk-keyphrase-manager' ) );
		}

		// The focused post and strategy are settled in route_focus() (admin_init)
		// and arrive as URL params, so here we only read and render.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only render; state changes happen in route_focus() / admin_post_skips().
		$strat = sanitize_key( wp_unslash( $_GET['fstrat'] ?? '' ) );
		$ff    = absint( $_GET['ff'] ?? 0 );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $strat, array( 'work', 'random' ), true ) ) {
			$strat = (string) get_user_meta( get_current_user_id(), 'bsm_focus_strat', true );
			if ( ! in_array( $strat, array( 'work', 'random' ), true ) ) {
				$strat = 'work';
			}
		}

		$scope_name = self::focus_scope_name();

		echo '<div class="wrap" id="bsm-wrap">';
		bsm_topbar();
		bsm_render_tabs( 'focus' );

		if ( ! class_exists( 'WPSEO_Meta' ) && ! defined( 'WPSEO_VERSION' ) ) {
			echo '<div class="notice notice-warning"><p><strong>' .
				esc_html__( 'Yoast SEO not active.', 'bulk-keyphrase-manager' ) . '</strong> ' .
				esc_html__( 'Focus scores pages from Yoast fields, so picks will be rough until Yoast is active.', 'bulk-keyphrase-manager' ) .
				'</p></div>';
		}

		// ── Top controls: strategy toggle + Skip, all in one row. ──
		$work_url = self::base_url(
			array(
				'tab'     => 'focus',
				'fstrat'  => 'work',
				'refocus' => 1,
			)
		);
		$rand_url = self::base_url(
			array(
				'tab'     => 'focus',
				'fstrat'  => 'random',
				'refocus' => 1,
			)
		);

		echo '<div class="bsm-f-controls">';
		echo '<div class="bsm-f-strat">';
		printf(
			'<a class="%s" href="%s">%s</a>',
			'work' === $strat ? 'is-on' : '',
			esc_url( $work_url ),
			esc_html__( 'Needs the most work', 'bulk-keyphrase-manager' )
		);
		printf(
			'<a class="%s" href="%s">%s</a>',
			'random' === $strat ? 'is-on' : '',
			esc_url( $rand_url ),
			esc_html__( 'Surprise me', 'bulk-keyphrase-manager' )
		);
		echo '</div>';

		if ( $ff ) {
			$skip_url = wp_nonce_url(
				self::base_url(
					array(
						'tab'    => 'focus',
						'fstrat' => $strat,
						'ffskip' => $ff,
					)
				),
				self::focus_skip_action( $ff )
			);
			echo '<a class="bsm-f-skip" href="' . esc_url( $skip_url ) . '">' . esc_html__( 'Skip → show another', 'bulk-keyphrase-manager' ) . '</a>';
		}
		echo '</div>';

		/* translators: %s: post type scope name, e.g. "Pages" or "all post types". */
		echo '<p class="bsm-f-scope">' . esc_html( sprintf( __( 'Scanning: %s', 'bulk-keyphrase-manager' ), $scope_name ) ) . '</p>';

		// Full SEO Health detail for the focused post, rendered inside this tab.
		if ( $ff && self::focus_eligible( $ff ) ) {
			self::render_detail( $ff, 'focus' );
		} else {
			echo '<div class="bsm-f-empty">' . esc_html__(
				'Nothing to focus on right now. Either there are no published items for this scope, or you have skipped them all — clear skips under Settings → Focus to bring them back.',
				'bulk-keyphrase-manager'
			) . '</div>';
		}

		echo '</div>';
	}

	// ── Focus helpers ─────────────────────────────────────────────────────────

	/** Post types Focus scans, following the SEO Health sticky post-type filter. */
	private static function focus_types(): array {
		$all   = bsm_get_post_types();
		$htype = (string) get_user_meta( get_current_user_id(), 'bsm_health_htype', true );
		if ( '' === $htype || ( 'all' !== $htype && ! isset( $all[ $htype ] ) ) ) {
			$htype = 'all';
		}
		return 'all' === $htype ? array_keys( $all ) : array( $htype );
	}

	private static function focus_scope_name(): string {
		$all   = bsm_get_post_types();
		$htype = (string) get_user_meta( get_current_user_id(), 'bsm_health_htype', true );
		if ( 'all' !== $htype && isset( $all[ $htype ] ) ) {
			return $all[ $htype ]->labels->name;
		}
		return __( 'all post types', 'bulk-keyphrase-manager' );
	}

	/** Skipped post IDs (site-wide list, managed under Settings → Focus). */
	public static function focus_skips(): array {
		$v = get_option( 'bsm_focus_skips', array() );
		return is_array( $v ) ? array_values( array_unique( array_map( 'absint', $v ) ) ) : array();
	}

	public static function focus_skip_action( int $id ): string {
		return 'bsm_focus_skip_' . $id;
	}

	public static function focus_add_skip( int $id ): bool {
		if ( $id <= 0 || ! get_post( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			return false;
		}
		$skips = self::focus_skips();
		if ( ! in_array( $id, $skips, true ) ) {
			$skips[] = $id;
			update_option( 'bsm_focus_skips', $skips, false );
		}
		return true;
	}

	private static function focus_eligible( int $id ): bool {
		if ( in_array( $id, self::focus_skips(), true ) ) {
			return false;
		}
		$p = get_post( $id );
		return $p && 'publish' === $p->post_status && current_user_can( 'edit_post', $id );
	}

	/**
	 * Pre-render routing for the Focus tab (runs on admin_init, before output):
	 * process a skip, then make sure a valid focused post is pinned in the URL
	 * so reloads (including the inline editor's re-audit) stay on the same page.
	 */
	public static function route_focus(): void {
		if ( ! is_admin() ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing; the skip branch verifies its own nonce.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'lookit-bulk-seo' !== $page || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		// Only act when Focus is genuinely the tab being rendered. This used to
		// fire on a bare ?page=lookit-bulk-seo too, back when Focus was the
		// landing tab — which silently redirected every default page load to
		// Focus regardless of what the router thought the default was.
		if ( 'focus' !== bsm_resolve_tab() ) {
			return;
		}

		$strat = isset( $_GET['fstrat'] ) ? sanitize_key( wp_unslash( $_GET['fstrat'] ) ) : '';
		if ( ! in_array( $strat, array( 'work', 'random' ), true ) ) {
			$strat = (string) get_user_meta( get_current_user_id(), 'bsm_focus_strat', true );
			if ( ! in_array( $strat, array( 'work', 'random' ), true ) ) {
				$strat = 'work';
			}
		}
		update_user_meta( get_current_user_id(), 'bsm_focus_strat', $strat );

		$ff      = absint( $_GET['ff'] ?? 0 );
		$ffskip  = absint( $_GET['ffskip'] ?? 0 );
		$refocus = ! empty( $_GET['refocus'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Skip is a state change — verify the nonce, record it, then re-pick.
		if ( $ffskip ) {
			check_admin_referer( self::focus_skip_action( $ffskip ) );
			if ( ! self::focus_add_skip( $ffskip ) ) {
				wp_die( esc_html__( 'Permission denied.', 'bulk-keyphrase-manager' ) );
			}
			wp_safe_redirect(
				self::base_url(
					array(
						'tab'    => 'focus',
						'fstrat' => $strat,
					)
				)
			);
			exit;
		}

		// A still-eligible focused post and no forced re-pick → render it as-is.
		if ( ! $refocus && $ff && self::focus_eligible( $ff ) ) {
			return;
		}

		// Otherwise pick a fresh post and pin it in the URL.
		$post = self::focus_pick( self::focus_types(), $strat, self::focus_skips() );
		if ( $post ) {
			wp_safe_redirect(
				self::base_url(
					array(
						'tab'    => 'focus',
						'fstrat' => $strat,
						'ff'     => $post->ID,
					)
				)
			);
			exit;
		}
		// No candidate — fall through; render_focus() shows the empty state.
	}

	/** Settings → Focus: clear all skips, or clear a selected subset. */
	public static function admin_post_skips(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'bulk-keyphrase-manager' ) );
		}
		check_admin_referer( 'bsm_focus_skips' );

		$mode = isset( $_POST['bsm_focus_mode'] ) ? sanitize_key( wp_unslash( $_POST['bsm_focus_mode'] ) ) : '';
		if ( 'all' === $mode ) {
			update_option( 'bsm_focus_skips', array(), false );
		} else {
			$ids = isset( $_POST['skip_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['skip_ids'] ) ) : array();
			if ( $ids ) {
				$remaining = array_values( array_diff( self::focus_skips(), $ids ) );
				update_option( 'bsm_focus_skips', $remaining, false );
			}
		}

		$redirect = add_query_arg(
			array(
				'page'     => 'lookit-bulk-seo',
				'tab'      => 'settings',
				'pane'     => 'focus',
				'focusmsg' => 'cleared',
			),
			admin_url( 'admin.php' )
		) . '#focus';
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Choose one post to surface.
	 *
	 * 'random' — a random published post of the scoped type(s).
	 * 'work'   — samples a bounded candidate pool (posts missing a keyphrase or
	 *            meta description first, since those score lowest), audits up to
	 *            FOCUS_SCAN_CAP of them, and picks from the worst handful with a
	 *            little randomness so "Skip" feels fresh.
	 */
	/** Drop excluded IDs from a result set in PHP, avoiding post__not_in. */
	private static function focus_strip( array $posts, array $exclude ): array {
		$skip = array_flip( array_map( 'absint', $exclude ) );
		return array_values(
			array_filter(
				$posts,
				static function ( $p ) use ( $skip ) {
					return ! isset( $skip[ (int) $p->ID ] ) && current_user_can( 'edit_post', (int) $p->ID );
				}
			)
		);
	}

	private static function focus_pick( array $types, string $strat, array $exclude_ids = array() ): ?WP_Post {
		$not_in = array_values( array_unique( array_map( 'absint', $exclude_ids ) ) );

		if ( 'random' === $strat ) {
			// Over-fetch by the size of the skip list and drop the skips in PHP.
			// post__not_in makes MySQL do the exclusion and degrades badly on
			// large sites; the skip list is small, so this is the cheaper shape.
			$q    = new WP_Query(
				array(
					'post_type'           => $types,
					'post_status'         => 'publish',
					'posts_per_page'      => min( self::FOCUS_SCAN_CAP, count( $not_in ) + 1 ),
					'orderby'             => 'rand',
					'ignore_sticky_posts' => true,
					'no_found_rows'       => true,
				)
			);
			$pool = self::focus_strip( $q->posts, $not_in );
			return $pool[0] ?? null;
		}

		// 'work' — prioritise likely-weak posts (no keyphrase or no meta desc).
		$weak       = new WP_Query(
			array(
				'post_type'           => $types,
				'post_status'         => 'publish',
				'posts_per_page'      => self::FOCUS_SCAN_CAP,
				'orderby'             => 'rand',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded candidate pool.
				'meta_query'          => array(
					'relation' => 'OR',
					array(
						'key'     => BSM_META_KW,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => BSM_META_KW,
						'value'   => '',
						'compare' => '=',
					),
					array(
						'key'     => BSM_META_DESC,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => BSM_META_DESC,
						'value'   => '',
						'compare' => '=',
					),
				),
			)
		);
		$candidates = self::focus_strip( $weak->posts, $not_in );

		// If few weak posts, widen with a random sample so we still have range.
		if ( count( $candidates ) < 8 ) {
			$fill       = new WP_Query(
				array(
					'post_type'           => $types,
					'post_status'         => 'publish',
					'posts_per_page'      => self::FOCUS_SCAN_CAP,
					'orderby'             => 'rand',
					'ignore_sticky_posts' => true,
					'no_found_rows'       => true,
				)
			);
			$seen       = array_merge( $not_in, wp_list_pluck( $candidates, 'ID' ) );
			$candidates = array_merge( $candidates, self::focus_strip( $fill->posts, $seen ) );
		}

		if ( ! $candidates ) {
			return null;
		}

		$dupe_ids = bsm_duplicate_keyphrase_post_ids( $types );

		// Rank by "need score" (higher = needs more work).
		$ranked = array();
		foreach ( $candidates as $p ) {
			$a        = self::audit_post( $p, $dupe_ids );
			$content  = (string) $p->post_content;
			$plain    = trim( wp_strip_all_tags( strip_shortcodes( $content ) ) );
			$words    = '' === $plain ? 0 : count( preg_split( '/\s+/u', $plain ) );
			$meta     = trim( (string) get_post_meta( $p->ID, BSM_META_DESC, true ) ) !== '';
			$imgs     = self::images( $content );
			$ranked[] = array(
				'post' => $p,
				'need' => self::focus_need_score( (int) $a['score'], count( $a['issues'] ), $meta, $words, $imgs ),
			);
		}
		usort(
			$ranked,
			static function ( $x, $y ) {
				return $y['need'] <=> $x['need'];
			}
		);

		// Pick from the worst ~40% (at least 3) with a little randomness.
		$slice_len = max( 3, (int) ceil( count( $ranked ) * 0.4 ) );
		$slice     = array_slice( $ranked, 0, $slice_len );
		$pick      = 1 === count( $slice ) ? $slice[0] : $slice[ wp_rand( 0, count( $slice ) - 1 ) ];

		return $pick['post'];
	}

	private static function focus_need_score( int $score, int $issues, bool $meta, int $words, array $imgs ): float {
		$n = ( 100 - $score ) * 1.5 + $issues * 4;
		if ( ! $meta ) {
			$n += 12; }
		if ( $words < self::WORDS_THIN ) {
			$n += 6; }
		if ( $imgs['total'] > 0 && $imgs['with_alt'] < $imgs['total'] ) {
			$n += 5; }
		return $n;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Audit engine (on-page, no external calls)
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Audit a single post. Returns groups of checks plus a 0–100 score.
	 *
	 * @return array{score:int,groups:array,issues:array}
	 */
	public static function audit_post( WP_Post $post, array $dupe_ids = array() ): array {
		$content = (string) $post->post_content;
		$plain   = trim( wp_strip_all_tags( strip_shortcodes( $content ) ) );
		$words   = '' === $plain ? 0 : count( preg_split( '/\s+/u', $plain ) );

		$keyphrase = trim( (string) get_post_meta( $post->ID, BSM_META_KW, true ) );
		$metadesc  = trim( (string) get_post_meta( $post->ID, BSM_META_DESC, true ) );
		$seo_title = trim( (string) get_post_meta( $post->ID, BSM_META_TITLE, true ) );
		if ( '' === $seo_title ) {
			$seo_title = get_the_title( $post );
		}
		// Strip Yoast template tokens (%%sitename%% etc.) for a fair length read.
		$seo_title_clean = trim( preg_replace( '/%%[^%]+%%/', '', $seo_title ) );

		$kp        = mb_strtolower( $keyphrase );
		$plain_lc  = mb_strtolower( $plain );
		$headings  = self::extract_headings( $content );
		$first_par = self::first_paragraph( $content );
		$images    = self::images( $content );
		$int_list  = self::internal_links( $content );
		$int_links = count( $int_list );

		$groups = array();

		/* ---- Keyphrase optimization ---- */
		$kg = array();
		if ( '' === $keyphrase ) {
			$kg[] = self::chk( 'fail', 'Focus keyphrase set', 'No focus keyphrase — nothing to optimize against.' );
		} else {
			$kg[]    = self::chk( 'good', 'Focus keyphrase set', 'Keyphrase: “' . $keyphrase . '”.' );
			$kg[]    = false !== strpos( mb_strtolower( $seo_title ), $kp )
				? self::chk( 'good', 'Keyphrase in SEO title', 'Present in the title.' )
				: self::chk( 'fail', 'Keyphrase in SEO title', 'Add the keyphrase to the SEO title.' );
			$kg[]    = ( '' !== $metadesc && false !== strpos( mb_strtolower( $metadesc ), $kp ) )
				? self::chk( 'good', 'Keyphrase in meta description', 'Present in the meta description.' )
				: self::chk( 'fail', 'Keyphrase in meta description', 'Add the keyphrase to the meta description.' );
			$kg[]    = false !== strpos( $post->post_name, sanitize_title( $keyphrase ) )
				? self::chk( 'good', 'Keyphrase in URL slug', 'The slug reflects the keyphrase.' )
				: self::chk( 'warn', 'Keyphrase in URL slug', 'Consider working the keyphrase into the slug.' );
			$kg[]    = false !== strpos( mb_strtolower( $first_par ), $kp )
				? self::chk( 'good', 'Keyphrase in first paragraph', 'Appears early in the copy.' )
				: self::chk( 'warn', 'Keyphrase in first paragraph', 'Move the keyphrase into the opening paragraph.' );
			$sub_hit = false;
			foreach ( $headings as $h ) {
				if ( $h['level'] >= 2 && false !== strpos( mb_strtolower( $h['text'] ), $kp ) ) {
					$sub_hit = true;
					break; }
			}
			$kg[]     = $sub_hit
				? self::chk( 'good', 'Keyphrase in a subheading', 'Used in at least one subheading.' )
				: self::chk( 'warn', 'Keyphrase in a subheading', 'None of the subheadings use the keyphrase.' );
			$kp_words = max( 1, count( preg_split( '/\s+/u', trim( $kp ) ) ) );
			$density  = $words > 0 ? ( substr_count( $plain_lc, $kp ) * $kp_words / $words ) * 100 : 0;
			if ( $density >= 0.5 && $density <= 2.5 ) {
				$kg[] = self::chk( 'good', 'Keyphrase density', sprintf( '%.1f%% — in a natural range.', $density ) );
			} elseif ( $density > 0 ) {
				$kg[] = self::chk( 'warn', 'Keyphrase density', sprintf( '%.1f%% — aim for roughly 0.5–2.5%%.', $density ) );
			} else {
				$kg[] = self::chk( 'fail', 'Keyphrase density', 'Keyphrase does not appear in the body copy.' );
			}
		}
		$groups['Keyphrase optimization'] = $kg;

		/* ---- Titles & meta ---- */
		$tg  = array();
		$len = mb_strlen( $seo_title_clean );
		if ( $len <= self::TITLE_OK ) {
			$tg[] = self::chk( 'good', 'SEO title length', $len . ' characters — fits in results.' );
		} elseif ( $len <= self::TITLE_MAX ) {
			$tg[] = self::chk( 'warn', 'SEO title length', $len . ' characters — may truncate on mobile.' );
		} else {
			$tg[] = self::chk( 'fail', 'SEO title length', $len . ' characters — will truncate in results.' );
		}
		$dlen = mb_strlen( $metadesc );
		if ( '' === $metadesc ) {
			$tg[] = self::chk( 'fail', 'Meta description', 'Missing — Google will write its own snippet.' );
		} elseif ( $dlen >= BSM_DESC_LO && $dlen <= BSM_DESC_HI ) {
			$tg[] = self::chk( 'good', 'Meta description', $dlen . ' characters — good length.' );
		} else {
			$tg[] = self::chk( 'warn', 'Meta description', $dlen . ' characters — aim for ' . BSM_DESC_LO . '–' . BSM_DESC_HI . '.' );
		}
		$is_dupe                 = in_array( $post->ID, $dupe_ids, true );
		$tg[]                    = $is_dupe
			? self::chk( 'warn', 'Overlapping keyphrase', 'Another page targets this same keyphrase.' )
			: self::chk( 'good', 'Overlapping keyphrase', 'This keyphrase is unique across the site.' );
		$groups['Titles & meta'] = $tg;

		/* ---- Content quality ---- */
		$cg = array();
		if ( $words >= self::WORDS_OK ) {
			$cg[] = self::chk( 'good', 'Word count', $words . ' words.' );
		} elseif ( $words >= self::WORDS_THIN ) {
			$cg[] = self::chk( 'warn', 'Word count', $words . ' words — a little light for competitive terms.' );
		} else {
			$cg[] = self::chk( 'fail', 'Word count', $words . ' words — thin content.' );
		}
		$h1 = 0;
		foreach ( $headings as $h ) {
			if ( 1 === $h['level'] ) {
				++$h1; }
		}
		if ( $h1 > 1 ) {
			$cg[] = self::chk( 'warn', 'H1 headings', $h1 . ' H1s in the content — usually there should be one.' );
		} else {
			$cg[] = self::chk( 'good', 'H1 headings', 'One H1 (theme supplies it if none is in the content).' );
		}
		$cg[]                      = self::hierarchy_check( $headings );
		$groups['Content quality'] = $cg;

		/* ---- Media ---- */
		$mg = array();
		if ( 0 === $images['total'] ) {
			$mg[] = self::chk( 'good', 'Image alt text', 'No inline images to caption.' );
		} elseif ( $images['with_alt'] === $images['total'] ) {
			$check          = self::chk( 'good', 'Image alt text', 'All ' . $images['total'] . ' images have alt text.' );
			$check['items'] = $images['items']; // allow View images (review / regenerate) even when all pass
			$mg[]           = $check;
		} else {
			$status         = 0 === $images['with_alt'] ? 'fail' : 'warn';
			$detail         = $images['with_alt'] . ' of ' . $images['total'] . ' images have alt text.';
			$check          = self::chk( $status, 'Image alt text', $detail, 'media' );
			$check['files'] = $images['missing']; // searchable filenames for the fallback copy buttons
			$check['items'] = $images['items'];   // resolvable images for the inline alt panel
			$mg[]           = $check;
		}
		$groups['Media'] = $mg;

		/* ---- Links ---- */
		$lg                  = array();
		$link_check          = $int_links > 0
			? self::chk( 'good', 'Internal links out', $int_links . ' internal link(s) from this page.' )
			: self::chk( 'fail', 'Internal links out', 'No internal links — link to related pages.' );
		$link_check['links'] = $int_list;
		$lg[]                = $link_check;
		$groups['Links']     = $lg;

		// ---- Score + flat issue list ----
		$sum    = 0;
		$n      = 0;
		$issues = array();
		foreach ( $groups as $group => $checks ) {
			foreach ( $checks as $c ) {
				$sum += ( 'good' === $c['status'] ) ? 1 : ( ( 'warn' === $c['status'] ) ? 0.5 : 0 );
				++$n;
				if ( 'good' !== $c['status'] ) {
					$issues[] = $c['label'];
				}
			}
		}
		$score = $n > 0 ? (int) round( $sum / $n * 100 ) : 0;

		return array(
			'score'  => $score,
			'groups' => $groups,
			'issues' => $issues,
		);
	}

	private static function chk( string $status, string $label, string $detail, string $bridge = '' ): array {
		return array(
			'status' => $status,
			'label'  => $label,
			'detail' => $detail,
			'bridge' => $bridge,
		);
	}

	private static function first_paragraph( string $content ): string {
		if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $content, $m ) ) {
			return wp_strip_all_tags( $m[1] );
		}
		$plain = wp_strip_all_tags( strip_shortcodes( $content ) );
		return mb_substr( $plain, 0, 200 );
	}

	private static function extract_headings( string $content ): array {
		$out = array();
		if ( preg_match_all( '/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $content, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $h ) {
				$out[] = array(
					'level' => (int) $h[1],
					'text'  => trim( wp_strip_all_tags( $h[2] ) ),
				);
			}
		}
		return $out;
	}

	private static function hierarchy_check( array $headings ): array {
		$prev = 0;
		foreach ( $headings as $h ) {
			if ( $prev > 0 && $h['level'] > $prev + 1 ) {
				return self::chk( 'warn', 'Heading hierarchy', 'Levels skip (e.g. H2 → H' . $h['level'] . ') — tidy the structure.' );
			}
			$prev = $h['level'];
		}
		return self::chk( 'good', 'Heading hierarchy', 'Headings nest in order.' );
	}

	private static function images( string $content ): array {
		$total    = 0;
		$with_alt = 0;
		$missing  = array();
		$items    = array();
		if ( preg_match_all( '/<img\b[^>]*>/i', $content, $m ) ) {
			foreach ( $m[0] as $img ) {
				++$total;

				$src = '';
				if ( preg_match( '/\bsrc\s*=\s*("|\')(.*?)\1/i', $img, $s ) ) {
					$src = $s[2];
				}
				$inline_alt = '';
				if ( preg_match( '/\balt\s*=\s*("|\')(.*?)\1/i', $img, $a ) ) {
					$inline_alt = trim( $a[2] );
				}

				// Resolve to an attachment once; the media-library alt (what Lookit
				// Media Master writes to) counts as alt even when the tag has none.
				$id      = self::attachment_id_from_img( $img, $src );
				$lib_alt = $id ? trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) : '';

				$cur_alt = '' !== $inline_alt ? $inline_alt : $lib_alt;
				$has_alt = '' !== $cur_alt;
				if ( $has_alt ) {
					++$with_alt;
				}

				$fname = '' !== $src ? self::filename_for_search( $src ) : '';
				if ( ! $has_alt && '' !== $fname ) {
					$missing[] = $fname; // searchable filenames for the fallback copy buttons
				}

				// Actionable items = images resolvable to an attachment, so the vision
				// call can read the file and alt can be written back. Keyed by ID to
				// dedupe an image used more than once on the page. Includes captioned
				// images too, so the panel can display/regenerate them.
				if ( $id ) {
					$thumb        = wp_get_attachment_image_url( $id, 'medium' );
					$items[ $id ] = array(
						'id'       => $id,
						'thumb'    => $thumb ? $thumb : '',
						'filename' => '' !== $fname ? $fname : ( 'attachment-' . $id ),
						'alt'      => $cur_alt,
						'has_alt'  => $has_alt,
					);
				}
			}
		}
		return array(
			'total'    => $total,
			'with_alt' => $with_alt,
			'missing'  => array_values( array_unique( $missing ) ),
			'items'    => array_values( $items ),
		);
	}

	/**
	 * Inline image-alt panel for the Media check: a "View images" toggle that
	 * reveals each resolvable image with a thumbnail, its current alt, and
	 * Generate / edit / Save controls. Only rendered when the vision endpoint
	 * is configured (see render_detail()).
	 *
	 * @param array $items List of [id, thumb, filename, alt, has_alt].
	 */
	private static function render_alt_panel( array $items ): void {
		$count   = count( $items );
		$missing = 0;
		foreach ( $items as $it ) {
			if ( empty( $it['has_alt'] ) ) {
				++$missing;
			}
		}

		echo '<button type="button" class="bsm-h-altview" aria-expanded="false"><span class="caret">▸</span> ' .
			esc_html(
				sprintf(
				/* translators: %d: number of images on the page. */
					_n( 'View image (%d)', 'View images (%d)', $count, 'bulk-keyphrase-manager' ),
					$count
				)
			) . '</button>';

		echo '<div class="bsm-h-altpanel" hidden>';
		echo '<p class="bsm-h-altlede">Images on this page. Generate alt text with Nova Lite vision, edit if needed, then save — it writes to the media library.</p>';
		echo '<div class="bsm-h-altlist">';

		foreach ( $items as $it ) {
			$has = ! empty( $it['has_alt'] );
			echo '<div class="bsm-h-altrow" data-id="' . (int) $it['id'] . '">';

			echo '<div class="bsm-h-altthumb">';
			if ( ! empty( $it['thumb'] ) ) {
				echo '<img src="' . esc_url( $it['thumb'] ) . '" alt="" loading="lazy">';
			} else {
				echo '<span class="bsm-h-altph">🖼</span>';
			}
			echo '<span class="bsm-h-altbadge ' . ( $has ? 'is-ok' : 'is-miss' ) . '">' . esc_html( $has ? 'alt set' : 'no alt' ) . '</span>';
			echo '</div>';

			echo '<div class="bsm-h-altbody">';
			echo '<div class="bsm-h-altfn">' . esc_html( $it['filename'] ) . '</div>';
			echo '<div class="bsm-h-altctrls">';
			echo '<textarea class="bsm-h-altfield' . ( $has ? '' : ' is-empty' ) . '" rows="2" placeholder="' .
				esc_attr( $has ? '' : 'No alt text — click Generate' ) . '">' . esc_textarea( (string) $it['alt'] ) . '</textarea>';
			echo '<div class="bsm-h-altbtns">';
			echo '<button type="button" class="button button-primary bsm-h-altgen">' . esc_html( $has ? '✦ Regenerate' : '✦ Generate' ) . '</button>';
			echo '<button type="button" class="button bsm-h-altsave"' . ( $has ? '' : ' disabled' ) . '>Save</button>';
			echo '</div>'; // .bsm-h-altbtns
			echo '</div>'; // .bsm-h-altctrls
			echo '<div class="bsm-h-altmsg" hidden></div>';
			echo '</div>'; // .bsm-h-altbody

			echo '</div>'; // .bsm-h-altrow
		}

		echo '</div>'; // .bsm-h-altlist
		echo '<div class="bsm-h-altfoot"><button type="button" class="button button-primary bsm-h-altgenall"' .
			( 0 === $missing ? ' disabled' : '' ) . '>✦ Generate all missing (' . (int) $missing . ')</button>' .
			'<span class="bsm-h-altfootmsg"></span></div>';
		echo '</div>'; // .bsm-h-altpanel
	}

	/** Resolve an inline <img> to its attachment ID (0 if not found). */
	private static function attachment_id_from_img( string $img_tag, string $src ): int {
		// 1) wp-image-{ID} class — WordPress editors add this; most reliable.
		if ( preg_match( '/wp-image-(\d+)/', $img_tag, $m ) ) {
			return (int) $m[1];
		}
		// 2) URL → ID (handles resized -WxH by stripping to the base URL).
		if ( '' !== $src ) {
			$url  = (string) preg_replace( '/[?#].*$/', '', trim( $src ) );
			$base = (string) preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $url );
			$id   = attachment_url_to_postid( $base );
			if ( ! $id && $base !== $url ) {
				$id = attachment_url_to_postid( $url );
			}
			if ( $id ) {
				return (int) $id;
			}
		}
		// 3) Filename lookup against the media library (robust to scoped/CDN URLs).
		$file = self::filename_for_search( $src );
		if ( '' !== $file ) {
			$found = get_posts(
				array(
					'post_type'     => 'attachment',
					'post_status'   => 'inherit',
					'numberposts'   => 1,
					'fields'        => 'ids',
					'no_found_rows' => true,
					// Lookup by stored filename; unavoidable meta query, cached per request.
					'meta_query'    => array(
						array(
							'key'     => '_wp_attached_file',
							'value'   => $file,
							'compare' => 'LIKE',
						),
					), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				)
			);
			if ( ! empty( $found ) ) {
				return (int) $found[0];
			}
		}
		return 0;
	}

	/** Media-library alt text for an <img> ('' if none / unresolved). Cached per request. */
	private static function library_alt( string $img_tag, string $src ): string {
		static $cache = array();
		$key          = md5( $img_tag );
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}
		$id            = self::attachment_id_from_img( $img_tag, $src );
		$alt           = $id ? trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) : '';
		$cache[ $key ] = $alt;
		return $alt;
	}

	/** Reduce an image URL to the core filename Media Master's search will match. */
	private static function filename_for_search( string $src ): string {
		$src  = preg_replace( '/[?#].*$/', '', trim( $src ) ); // drop query/hash
		$base = wp_basename( (string) $src );
		$base = preg_replace( '/\.[a-z0-9]+$/i', '', $base );  // drop extension
		$base = preg_replace( '/-\d+x\d+$/', '', $base );       // drop -WIDTHxHEIGHT
		$base = preg_replace( '/-scaled$/', '', $base );        // drop WP -scaled suffix
		return (string) $base;
	}

	private static function internal_link_count( string $content ): int {
		return count( self::internal_links( $content ) );
	}

	/** Internal links on the page as [ ['href'=>..,'text'=>..], ... ]. */
	private static function internal_links( string $content ): array {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$out  = array();
		if ( preg_match_all( '/<a\b[^>]*?href\s*=\s*("|\')(.*?)\1[^>]*>(.*?)<\/a>/is', $content, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $a ) {
				$href = trim( $a[2] );
				if ( '' === $href || '#' === $href[0] ) {
					continue; }
				$h = wp_parse_url( $href, PHP_URL_HOST );
				if ( null === $h || $host === $h ) {
					$text  = trim( wp_strip_all_tags( $a[3] ) );
					$out[] = array(
						'href' => $href,
						'text' => '' !== $text ? $text : $href,
					);
				}
			}
		}
		return $out;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Pages table
	// ─────────────────────────────────────────────────────────────────────────

	private static function render_pages( array $all_types, string $htype, int $paged ): void {
		$types    = 'all' === $htype ? array_keys( $all_types ) : array( $htype );
		$dupe_ids = bsm_duplicate_keyphrase_post_ids( $types );

		$q = new WP_Query(
			array(
				'post_type'      => $types,
				'post_status'    => 'publish',
				'posts_per_page' => 25,
				'paged'          => $paged,
				'orderby'        => 'type title',
				'order'          => 'ASC',
			)
		);

		// Type filter.
		echo '<form class="bsm-h-filters" method="get">';
		echo '<input type="hidden" name="page" value="lookit-bulk-seo"><input type="hidden" name="tab" value="health">';
		echo '<select name="htype" data-bsm-autosubmit><option value="all">All post types</option>';
		foreach ( $all_types as $slug => $obj ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $slug ), selected( $htype, $slug, false ), esc_html( $obj->labels->name ) );
		}
		echo '</select>';
		echo '<span class="cnt">' . esc_html( (string) $q->found_posts ) . ' published items</span>';
		$xlsx_url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'bsm_health_export',
					'format' => 'xlsx',
					'htype'  => $htype,
				),
				admin_url( 'admin-post.php' )
			),
			'bsm_health_export'
		);
		$csv_url  = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'bsm_health_export',
					'format' => 'csv',
					'htype'  => $htype,
				),
				admin_url( 'admin-post.php' )
			),
			'bsm_health_export'
		);
		echo '<a class="button button-primary bsm-h-export" href="' . esc_url( $xlsx_url ) . '">⬇ Export Excel</a>';
		echo '<a class="button bsm-h-export bsm-h-export-csv" href="' . esc_url( $csv_url ) . '">CSV</a>';
		echo '<a class="button bsm-h-export bsm-h-refresh" href="' . esc_url(
			self::base_url(
				array(
					'htype'  => $htype,
					'hpaged' => $paged,
				)
			)
		) . '">↻ Refresh</a>';
		echo '</form>';

		echo '<table class="bsm-h-tbl"><thead><tr>';
		foreach ( array( 'Title', 'Type', 'Score', 'Words', 'Meta', 'Title len', 'Alt', 'Links out', 'Issues', '' ) as $th ) {
			echo '<th>' . esc_html( $th ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $q->posts as $p ) {
			$a        = self::audit_post( $p, $dupe_ids );
			$content  = (string) $p->post_content;
			$plain    = trim( wp_strip_all_tags( strip_shortcodes( $content ) ) );
			$words    = '' === $plain ? 0 : count( preg_split( '/\s+/u', $plain ) );
			$meta     = get_post_meta( $p->ID, BSM_META_DESC, true );
			$title    = get_post_meta( $p->ID, BSM_META_TITLE, true ) ? get_post_meta( $p->ID, BSM_META_TITLE, true ) : get_the_title( $p );
			$tlen     = mb_strlen( trim( preg_replace( '/%%[^%]+%%/', '', (string) $title ) ) );
			$imgs     = self::images( $content );
			$links    = self::internal_link_count( $content );
			$type_obj = $all_types[ $p->post_type ] ?? null;
			$dot      = $a['score'] >= 80 ? 'good' : ( $a['score'] >= 50 ? 'warn' : 'fail' );
			$icount   = count( $a['issues'] );
			$ibadge   = 0 === $icount ? 'z' : ( $icount <= 2 ? 'm' : '' );
			$detail   = self::base_url( array( 'audit_post' => $p->ID ) );

			echo '<tr data-href="' . esc_url( $detail ) . '">';
			echo '<td><b>' . esc_html( get_the_title( $p ) ) . '</b></td>';
			echo '<td><span class="bsm-h-type">' . esc_html( $type_obj ? $type_obj->labels->singular_name : $p->post_type ) . '</span></td>';
			echo '<td><span class="bsm-h-dot ' . esc_attr( $dot ) . '"></span> ' . esc_html( (string) $a['score'] ) . '</td>';
			echo '<td class="' . ( $words < self::WORDS_THIN ? 'fail' : ( $words < self::WORDS_OK ? 'warn' : 'good' ) ) . '">' . esc_html( (string) $words ) . '</td>';
			echo '<td class="' . ( $meta ? 'good' : 'fail' ) . '">' . ( $meta ? 'OK' : 'Missing' ) . '</td>';
			echo '<td class="' . ( $tlen <= self::TITLE_OK ? 'good' : ( $tlen <= self::TITLE_MAX ? 'warn' : 'fail' ) ) . '">' . esc_html( (string) $tlen ) . '</td>';
			echo '<td class="' . ( 0 === $imgs['total'] || $imgs['total'] === $imgs['with_alt'] ? 'good' : ( 0 === $imgs['with_alt'] ? 'fail' : 'warn' ) ) . '">' . esc_html( $imgs['with_alt'] . '/' . $imgs['total'] ) . '</td>';
			echo '<td class="' . ( $links > 0 ? 'good' : 'fail' ) . '">' . esc_html( (string) $links ) . '</td>';
			echo '<td><span class="bsm-h-ib ' . esc_attr( $ibadge ) . '">' . esc_html( (string) $icount ) . '</span></td>';
			echo '<td><a class="bsm-h-view" href="' . esc_url( $detail ) . '">View →</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		// Pagination — compact windowed pager (first / last / ±2 around current).
		$tp = (int) $q->max_num_pages;
		if ( $tp > 1 ) {
			$cur    = min( $paged, $tp );
			$window = 2;
			$show   = array( 1, $tp );
			for ( $i = $cur - $window; $i <= $cur + $window; $i++ ) {
				if ( $i >= 1 && $i <= $tp ) {
					$show[] = $i; }
			}
			$show = array_values( array_unique( $show ) );
			sort( $show );

			echo '<div class="bsm-h-pager">';
			if ( $cur > 1 ) {
				printf(
					'<a href="%s">‹ Prev</a>',
					esc_url(
						self::base_url(
							array(
								'htype'  => $htype,
								'hpaged' => $cur - 1,
							)
						)
					)
				);
			}
			$prev = 0;
			foreach ( $show as $i ) {
				if ( $prev && $i > $prev + 1 ) {
					echo '<span class="bsm-h-gap">…</span>'; }
				printf(
					'<a class="%s" href="%s">%s</a>',
					esc_attr( $i === $cur ? 'is-active' : '' ),
					esc_url(
						self::base_url(
							array(
								'htype'  => $htype,
								'hpaged' => $i,
							)
						)
					),
					esc_html( (string) $i )
				);
				$prev = $i;
			}
			if ( $cur < $tp ) {
				printf(
					'<a href="%s">Next ›</a>',
					esc_url(
						self::base_url(
							array(
								'htype'  => $htype,
								'hpaged' => $cur + 1,
							)
						)
					)
				);
			}
			echo '<span class="bsm-h-pageinfo">Page ' . esc_html( (string) $cur ) . ' of ' . esc_html( (string) $tp ) . '</span>';
			echo '</div>';
		}

		echo '<p class="bsm-h-note">Reuses the Bulk Editor’s enumerate-every-post-type logic; each row is audited live. Click a row for the full page audit.</p>';
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Export (CSV / XLSX) — admin-post.php?action=bsm_health_export
	// ─────────────────────────────────────────────────────────────────────────

	public static function export(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'bulk-keyphrase-manager' ) );
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'bsm_health_export' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'bulk-keyphrase-manager' ) );
		}

		$all_types = bsm_get_post_types();
		$htype     = isset( $_GET['htype'] ) ? sanitize_key( wp_unslash( $_GET['htype'] ) ) : 'all';
		if ( 'all' !== $htype && ! isset( $all_types[ $htype ] ) ) {
			$htype = 'all';
		}
		$format   = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : 'csv';
		$types    = 'all' === $htype ? array_keys( $all_types ) : array( $htype );
		$dupe_ids = bsm_duplicate_keyphrase_post_ids( $types );

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- large CSV/XLSX exports may exceed the default limit
		}
		$rows = self::gather_export_rows( $types, $dupe_ids, $all_types );

		if ( 'xlsx' === $format && class_exists( 'ZipArchive' ) ) {
			self::stream_xlsx( $rows );
		} else {
			self::stream_csv( $rows );
		}
		exit;
	}

	private static function export_headers(): array {
		return array(
			'Title',
			'Type',
			'URL',
			'Score',
			'Words',
			'Meta description',
			'Title length',
			'Images with alt',
			'Internal links out',
			'Issue count',
			'Issues',
			'Focus keyphrase',
		);
	}

	/** Build every audited row (batched to keep memory flat on large sites). */
	private static function gather_export_rows( array $types, array $dupe_ids, array $all_types ): array {
		$rows  = array();
		$paged = 1;
		$max   = 1;
		do {
			$q = new WP_Query(
				array(
					'post_type'      => $types,
					'post_status'    => 'publish',
					'posts_per_page' => 300, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded export batch.
					'paged'          => $paged,
					'orderby'        => 'type title',
					'order'          => 'ASC',
					'no_found_rows'  => $paged > 1,
				)
			);
			if ( 1 === $paged ) {
				$max = (int) $q->max_num_pages;
			}
			foreach ( $q->posts as $p ) {
				$a        = self::audit_post( $p, $dupe_ids );
				$content  = (string) $p->post_content;
				$plain    = trim( wp_strip_all_tags( strip_shortcodes( $content ) ) );
				$words    = '' === $plain ? 0 : count( preg_split( '/\s+/u', $plain ) );
				$meta     = get_post_meta( $p->ID, BSM_META_DESC, true );
				$title    = get_post_meta( $p->ID, BSM_META_TITLE, true ) ? get_post_meta( $p->ID, BSM_META_TITLE, true ) : get_the_title( $p );
				$tlen     = mb_strlen( trim( preg_replace( '/%%[^%]+%%/', '', (string) $title ) ) );
				$imgs     = self::images( $content );
				$type_obj = $all_types[ $p->post_type ] ?? null;

				$rows[] = array(
					get_the_title( $p ),
					$type_obj ? $type_obj->labels->singular_name : $p->post_type,
					get_permalink( $p ),
					(int) $a['score'],
					(int) $words,
					$meta ? 'Yes' : 'Missing',
					(int) $tlen,
					$imgs['with_alt'] . '/' . $imgs['total'],
					self::internal_link_count( $content ),
					(int) count( $a['issues'] ),
					implode( '; ', $a['issues'] ),
					(string) get_post_meta( $p->ID, BSM_META_KW, true ),
				);
			}
			wp_reset_postdata();
			++$paged;
		} while ( $paged <= $max );

		return $rows;
	}

	private static function stream_csv( array $rows ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="seo-health-' . gmdate( 'Y-m-d' ) . '.csv"' );

		// Streaming download; fputcsv handles CSV quoting/escaping.
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM so Excel reads accents correctly.
		fputcsv( $out, self::export_headers() );
		foreach ( $rows as $r ) {
			fputcsv( $out, $r );
		}
		fclose( $out );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	// ── XLSX writer (native OOXML via ZipArchive — no third-party library) ──────

	private static function col_letter( int $c ): string {
		return chr( 65 + $c ); // A–L; the export has 12 columns.
	}

	private static function xesc( string $s ): string {
		$s = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s ); // strip XML-illegal control chars
		return str_replace( array( '&', '<', '>', '"' ), array( '&amp;', '&lt;', '&gt;', '&quot;' ), (string) $s );
	}

	private static function stream_xlsx( array $rows ): void {
		$tmp = self::build_xlsx( $rows );
		if ( null === $tmp ) {
			self::stream_csv( $rows ); // fall back if the archive can't be created
			return;
		}
		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="seo-health-' . gmdate( 'Y-m-d' ) . '.xlsx"' );
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_filesize, WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		header( 'Content-Length: ' . filesize( $tmp ) );
		readfile( $tmp );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_filesize, WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		wp_delete_file( $tmp );
	}

	/** Assemble a styled .xlsx into a temp file and return its path (or null on failure). */
	private static function build_xlsx( array $rows ): ?string {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return null;
		}
		$headers  = self::export_headers();
		$ncols    = count( $headers );
		$last_row = count( $rows ) + 1;
		$last_col = self::col_letter( $ncols - 1 );

		// Which columns are numeric; score (3) and issue count (9) get RAG colour fills.
		$numeric = array(
			3 => 1,
			4 => 1,
			6 => 1,
			8 => 1,
			9 => 1,
		);

		$body = '<row r="1">';
		for ( $c = 0; $c < $ncols; $c++ ) {
			$body .= '<c r="' . self::col_letter( $c ) . '1" s="1" t="inlineStr"><is><t xml:space="preserve">' . self::xesc( $headers[ $c ] ) . '</t></is></c>';
		}
		$body .= '</row>';

		$r = 2;
		foreach ( $rows as $row ) {
			$body .= '<row r="' . $r . '">';
			for ( $c = 0; $c < $ncols; $c++ ) {
				$ref = self::col_letter( $c ) . $r;
				$val = $row[ $c ] ?? '';
				if ( isset( $numeric[ $c ] ) ) {
					$n = (int) $val;
					$s = '';
					if ( 3 === $c ) {
						$s = $n >= 80 ? ' s="2"' : ( $n >= 50 ? ' s="3"' : ' s="4"' );
					} elseif ( 9 === $c ) {
						$s = 0 === $n ? ' s="2"' : ( $n <= 2 ? ' s="3"' : ' s="4"' );
					}
					$body .= '<c r="' . $ref . '"' . $s . '><v>' . $n . '</v></c>';
				} else {
					$body .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . self::xesc( (string) $val ) . '</t></is></c>';
				}
			}
			$body .= '</row>';
			++$r;
		}

		$cols = '<col min="1" max="1" width="34" customWidth="1"/><col min="2" max="2" width="16" customWidth="1"/>'
			. '<col min="3" max="3" width="42" customWidth="1"/><col min="4" max="4" width="8" customWidth="1"/>'
			. '<col min="5" max="5" width="8" customWidth="1"/><col min="6" max="6" width="16" customWidth="1"/>'
			. '<col min="7" max="7" width="12" customWidth="1"/><col min="8" max="8" width="14" customWidth="1"/>'
			. '<col min="9" max="9" width="16" customWidth="1"/><col min="10" max="10" width="11" customWidth="1"/>'
			. '<col min="11" max="11" width="60" customWidth="1"/><col min="12" max="12" width="22" customWidth="1"/>';

		$sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft"/></sheetView></sheetViews>'
			. '<sheetFormatPr defaultRowHeight="15"/>'
			. '<cols>' . $cols . '</cols>'
			. '<sheetData>' . $body . '</sheetData>'
			. '<autoFilter ref="A1:' . $last_col . $last_row . '"/>'
			. '</worksheet>';

		$content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. '</Types>';

		$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>';

		$workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets><sheet name="SEO Health" sheetId="1" r:id="rId1"/></sheets></workbook>';

		$wb_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
			. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			. '</Relationships>';

		$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<fonts count="3">'
			. '<font><sz val="11"/><name val="Calibri"/></font>'
			. '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
			. '<font><b/><sz val="11"/><name val="Calibri"/></font>'
			. '</fonts>'
			. '<fills count="6">'
			. '<fill><patternFill patternType="none"/></fill>'
			. '<fill><patternFill patternType="gray125"/></fill>'
			. '<fill><patternFill patternType="solid"><fgColor rgb="FF0D1117"/></patternFill></fill>'
			. '<fill><patternFill patternType="solid"><fgColor rgb="FFD6EFD6"/></patternFill></fill>'
			. '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF1C2"/></patternFill></fill>'
			. '<fill><patternFill patternType="solid"><fgColor rgb="FFF8D2D2"/></patternFill></fill>'
			. '</fills>'
			. '<borders count="1"><border/></borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="5">'
			. '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
			. '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
			. '<xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
			. '<xf numFmtId="0" fontId="2" fillId="4" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
			. '<xf numFmtId="0" fontId="2" fillId="5" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
			. '</cellXfs>'
			. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
			. '</styleSheet>';

		$tmp = wp_tempnam( 'seo-health-xlsx' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
			return null;
		}
		$zip->addFromString( '[Content_Types].xml', $content_types );
		$zip->addFromString( '_rels/.rels', $rels );
		$zip->addFromString( 'xl/workbook.xml', $workbook );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', $wb_rels );
		$zip->addFromString( 'xl/styles.xml', $styles );
		$zip->addFromString( 'xl/worksheets/sheet1.xml', $sheet );
		$zip->close();

		return $tmp;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Single-page detail
	// ─────────────────────────────────────────────────────────────────────────

	private static function render_detail( int $post_id, string $context = 'health' ): void {
		$post = get_post( $post_id );
		if ( ! $post || 'trash' === $post->post_status || ! current_user_can( 'edit_post', $post_id ) ) {
			echo '<p class="bsm-h-note">That item could not be found.</p>';
			return;
		}
		$dupe_ids  = bsm_duplicate_keyphrase_post_ids( array_keys( bsm_get_post_types() ) );
		$a         = self::audit_post( $post, $dupe_ids );
		$keyphrase = get_post_meta( $post->ID, BSM_META_KW, true );
		$dcolor    = $a['score'] >= 80 ? '#46b450' : ( $a['score'] >= 50 ? '#ffb900' : '#dc3232' );

		if ( 'focus' !== $context ) {
			printf(
				'<a class="bsm-h-back" href="%s">← Back to Pages &amp; Posts</a>',
				esc_url( self::base_url() )
			);
		}

		echo '<div class="bsm-h-dhead"><div class="l">';
		echo '<h2>' . esc_html( get_the_title( $post ) ) . '</h2>';
		echo '<div class="u">' . esc_html( $post->post_type ) . ' · ' . esc_html( wp_make_link_relative( get_permalink( $post ) ) ) . '</div>';
		if ( $keyphrase ) {
			echo '<div class="kw">Focus keyphrase: <b>' . esc_html( $keyphrase ) . '</b></div>';
		} else {
			echo '<div class="kw">No focus keyphrase set</div>';
		}
		$edit_link = get_edit_post_link( $post->ID, 'raw' );
		$view_link = get_permalink( $post );
		echo '<div class="bsm-h-dactions">';
		if ( $edit_link ) {
			echo '<a class="button button-primary" href="' . esc_url( $edit_link ) . '" target="_blank" rel="noopener">Edit page ↗</a>';
		}
		if ( $view_link ) {
			echo '<a class="button" href="' . esc_url( $view_link ) . '" target="_blank" rel="noopener">View live ↗</a>';
		}
		if ( 'focus' === $context ) {
			$strat = (string) get_user_meta( get_current_user_id(), 'bsm_focus_strat', true );
			if ( ! in_array( $strat, array( 'work', 'random' ), true ) ) {
				$strat = 'work';
			}
			$refresh_url = self::base_url(
				array(
					'tab'    => 'focus',
					'fstrat' => $strat,
					'ff'     => $post->ID,
				)
			);
		} else {
			$refresh_url = self::base_url( array( 'audit_post' => $post->ID ) );
		}
		echo '<a class="button" href="' . esc_url( $refresh_url ) . '">↻ Refresh page SEO</a>';
		echo '</div>';
		echo '</div><div class="r"><b style="color:' . esc_attr( $dcolor ) . '">' . esc_html( (string) $a['score'] ) . '</b><span>Page score</span></div></div>';

		// Editable Yoast fields — collapsible; each field shows the current value,
		// an input for the new one, and a per-field AI fill. Saved through the same
		// writer the Bulk Editor uses.
		$cur_title = (string) get_post_meta( $post->ID, BSM_META_TITLE, true );
		$cur_desc  = (string) get_post_meta( $post->ID, BSM_META_DESC, true );
		$kp_str    = (string) $keyphrase;
		// Always-open panel, matching the check groups below. It used to be a
		// <details>; the summary was too easy to hit by accident and collapse.
		echo '<div class="bsm-h-grp bsm-h-edit"><div class="gh"><span class="gn">Edit SEO fields</span><span class="gc">writes to Yoast · re-audits on save</span></div>';
		echo '<div class="bsm-h-edit-body" data-post="' . (int) $post->ID . '">';

		echo '<div class="bsm-h-field"><div class="lbl">Focus keyphrase</div>';
		echo '<div class="cur">Current: <span>' . ( '' !== $kp_str ? esc_html( $kp_str ) : '— none —' ) . '</span></div>';
		echo '<div class="row"><input type="text" class="bsm-h-f-kw" value="' . esc_attr( $kp_str ) . '"><button type="button" class="button bsm-h-fill" data-kind="keyphrase" data-target="bsm-h-f-kw">✦ Fill with AI</button></div></div>';

		// Related keyphrases — shown as removable chips so the editor can see
		// what's already on the page while adding suggestions from below.
		// Yoast stores these as a JSON array of { keyword, score } objects.
		$rel_cur = array();
		$rel_raw = get_post_meta( $post->ID, '_yoast_wpseo_focuskeywords', true );
		if ( is_string( $rel_raw ) && '' !== $rel_raw ) {
			$rel_dec = json_decode( $rel_raw, true );
			if ( is_array( $rel_dec ) ) {
				foreach ( $rel_dec as $obj ) {
					$kw = is_array( $obj ) ? ( $obj['keyword'] ?? '' ) : (string) $obj;
					if ( '' !== trim( (string) $kw ) ) {
						$rel_cur[] = (string) $kw;
					}
				}
			}
		}
		echo '<div class="bsm-h-field"><div class="lbl">Related keyphrases</div>';
		echo '<div class="cur">Current: <span>' . ( $rel_cur ? esc_html( (string) count( $rel_cur ) ) . ' saved' : '— none —' ) . '</span></div>';
		echo '<div class="bsm-h-rel-list">';
		foreach ( $rel_cur as $kw ) {
			echo '<span class="bsm-h-rel-chip" data-kw="' . esc_attr( $kw ) . '">' . esc_html( $kw ) .
				'<button type="button" class="bsm-h-rel-x" aria-label="Remove ' . esc_attr( $kw ) . '">×</button></span>';
		}
		echo '<span class="bsm-h-rel-none"' . ( $rel_cur ? ' hidden' : '' ) . '>— none —</span>';
		echo '</div>';
		echo '<div class="bsm-h-rel-hint">Removals and additions are written when you press Save to Yoast.</div>';
		echo '</div>';

		echo '<div class="bsm-h-field"><div class="lbl">SEO title</div>';
		echo '<div class="cur">Current: <span>' . ( '' !== $cur_title ? esc_html( $cur_title ) : '— none —' ) . '</span></div>';
		echo '<div class="row"><input type="text" class="bsm-h-f-title" value="' . esc_attr( $cur_title ) . '"><button type="button" class="button bsm-h-fill" data-kind="title" data-target="bsm-h-f-title">✦ Fill with AI</button></div></div>';

		echo '<div class="bsm-h-field"><div class="lbl">Meta description</div>';
		echo '<div class="cur">Current: <span>' . ( '' !== $cur_desc ? esc_html( $cur_desc ) : '— none —' ) . '</span></div>';
		echo '<div class="row"><textarea class="bsm-h-f-desc" rows="2">' . esc_textarea( $cur_desc ) . '</textarea><button type="button" class="button bsm-h-fill" data-kind="metadesc" data-target="bsm-h-f-desc">✦ Fill with AI</button></div></div>';

		echo '<div class="bsm-h-edit-actions"><button type="button" class="button button-primary bsm-h-save">Save to Yoast</button><span class="bsm-h-save-msg"></span></div>';
		echo '</div></div>';

		// AI suggestions sit directly under the edit fields: the two feed each
		// other, and burying them below every check group meant editors scrolled
		// past them.
		self::render_ai_suggestions( $post );

		// Inline image-alt panel is available only when the vision endpoint is set;
		// otherwise the Media check falls back to the copy-filenames + Media Master bridge.
		$vision_on = '' !== trim( (string) get_option( 'bsm_vision_webhook_url', '' ) );

		foreach ( $a['groups'] as $group => $checks ) {
			$counts = array(
				'good' => 0,
				'warn' => 0,
				'fail' => 0,
			);
			foreach ( $checks as $c ) {
				++$counts[ $c['status'] ]; }
			echo '<div class="bsm-h-grp"><div class="gh"><span class="gn">' . esc_html( $group ) . '</span>';
			echo '<span class="gc">' . (int) $counts['good'] . ' pass · ' . (int) $counts['warn'] . ' warn · ' . (int) $counts['fail'] . ' fail</span></div>';
			foreach ( $checks as $c ) {
				$icon      = 'good' === $c['status'] ? '✓' : ( 'warn' === $c['status'] ? '!' : '✕' );
				$alt_panel = $vision_on && ! empty( $c['items'] ) && 'Image alt text' === $c['label'];
				$ico_cls   = 'ico ' . $c['status'] . ( $alt_panel ? ' bsm-h-alt-ico' : '' );
				$det_cls   = 'cd' . ( $alt_panel ? ' bsm-h-alt-detail' : '' );
				echo '<div class="chk"' . ( $alt_panel ? ' data-bsm-alt="1"' : '' ) . '><div class="' . esc_attr( $ico_cls ) . '">' . esc_html( $icon ) . '</div><div>';
				echo '<div class="ct">' . esc_html( $c['label'] ) . '</div>';
				echo '<div class="' . esc_attr( $det_cls ) . '">' . esc_html( $c['detail'] ) . '</div>';
				if ( ! $alt_panel && ! empty( $c['files'] ) ) {
					echo '<div class="bsm-h-files">';
					$total_f = count( $c['files'] );
					$idx     = 1;
					foreach ( $c['files'] as $fname ) {
						echo '<div class="bsm-h-file"><code>' . esc_html( $fname ) . '</code>';
						printf(
							'<button type="button" class="button button-small bsm-h-copy" data-file="%s">Copy file name %d/%d</button>',
							esc_attr( $fname ),
							(int) $idx,
							(int) $total_f
						);
						echo '</div>';
						++$idx;
					}
					echo '</div>';
				}
				if ( $alt_panel ) {
					self::render_alt_panel( $c['items'] );
				}
				if ( ! $alt_panel && ! empty( $c['bridge'] ) && 'media' === $c['bridge'] ) {
					// Deep-link into Lookit Media Master's Alt Text Manager, but only
					// if that plugin is installed (its admin page is registered).
					$mm_url = menu_page_url( 'lookit-media-master', false );
					if ( $mm_url ) {
						$mm_url = add_query_arg( 'mm_tab', 'alt', $mm_url ); // open the Alt Text Manager tab (Media Master reads this)
						echo '<div class="bsm-h-bridge"><a href="' . esc_url( $mm_url ) . '" target="_blank" rel="noopener">→ Fix in <b>Lookit Media Master</b> — AI alt text &amp; WebP ↗</a> <span class="bsm-h-hint">copy a file name above, then paste it into the search there</span></div>';
					} else {
						echo '<div class="bsm-h-bridge">→ <b>Lookit Media Master</b> can batch-fix these (AI alt text &amp; WebP).</div>';
					}
				}
				if ( ! empty( $c['links'] ) ) {
					echo '<details class="bsm-h-links"><summary>Show ' . count( $c['links'] ) . ' link' . ( count( $c['links'] ) === 1 ? '' : 's' ) . '</summary><ul>';
					foreach ( $c['links'] as $lnk ) {
						echo '<li><a href="' . esc_url( $lnk['href'] ) . '" target="_blank" rel="noopener">' . esc_html( $lnk['text'] ) . '</a><span class="u">' . esc_html( $lnk['href'] ) . '</span></li>';
					}
					echo '</ul></details>';
				}
				echo '</div></div>';
			}
			echo '</div>';
		}
	}

	private static function render_ai_suggestions( WP_Post $post ): void {
			// AI suggestions — all three tasks are served by the existing platform
			// endpoint (metadesc was already live; subheadings + outline added to the
			// same n8n workflow). Thin client: the plugin only POSTs context.
			$has_ai = '' !== trim( (string) get_option( 'bsm_ai_webhook_url', '' ) );
			echo '<div class="bsm-h-grp"><div class="gh"><span class="gn">AI suggestions</span><span class="gc">' .
				esc_html( $has_ai ? 'Nova Lite · via platform' : 'Not connected' ) . '</span></div>';

		if ( $has_ai ) {
			$pid  = (int) $post->ID;
			$rows = array(
				array( 'keyphrase', 'Focus keyphrase', 'Suggest focus keyphrases for this page. Generate again for a different set.' ),
				array( 'related', 'Related keyphrases', 'Suggest related keyphrases to save alongside the focus keyphrase.' ),
				array( 'metadesc', 'Meta description', 'Write a meta description for this page.' ),
				array( 'subheadings', 'H2 subheadings', 'Suggest keyphrase-aware H2s to structure the page.' ),
				array( 'outline', 'Content-expansion outline', 'Suggest sections to add — useful for thin pages.' ),
			);
			foreach ( $rows as $row ) {
				list( $kind, $label, $desc ) = $row;
				echo '<div class="chk"><div class="ico ai">✦</div><div class="bsm-h-suggest-wrap">';
				echo '<div class="ct">' . esc_html( $label ) . '</div>';
				echo '<div class="cd">' . esc_html( $desc ) . '</div>';
				echo '<button type="button" class="button button-primary bsm-h-suggest" data-post="' . esc_attr( (string) $pid ) . '" data-kind="' . esc_attr( $kind ) . '">Generate</button>';
				echo '<div class="bsm-h-suggest-out" hidden></div>';
				echo '</div></div>';
			}
			// Generate page content — includes a target word-count control.
			echo '<div class="chk"><div class="ico ai">✦</div><div class="bsm-h-suggest-wrap">';
			echo '<div class="ct">Generate page content</div>';
			echo '<div class="cd">Draft original body copy for this page at a target length.</div>';
			echo '<div class="bsm-h-content-ctrl">Target words <input type="number" class="bsm-h-words" value="600" min="100" max="2000" step="50"> ';
			echo '<button type="button" class="button button-primary bsm-h-suggest" data-post="' . esc_attr( (string) $pid ) . '" data-kind="content">Generate</button></div>';
			echo '<div class="bsm-h-suggest-out" hidden></div>';
			echo '</div></div>';
		} else {
			echo '<div class="chk"><div class="ico pend">···</div><div>' .
				'<div class="ct">AI engine not connected</div>' .
				'<div class="cd">Add your platform endpoint under <a href="' . esc_url( self::base_url_settings() ) . '">Settings → AI engine</a> to enable AI suggestions here.</div>' .
				'</div></div>';
		}
			echo '</div>';
	}

	private static function base_url_settings(): string {
		return add_query_arg(
			array(
				'page' => 'lookit-bulk-seo',
				'tab'  => 'settings',
			),
			admin_url( 'admin.php' )
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// AJAX — generate a meta-description suggestion (reuses bsm_ai_call_webhook)
	// ─────────────────────────────────────────────────────────────────────────

	public static function ajax_suggest(): void {
		check_ajax_referer( 'bsm_health_suggest', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$kind    = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : 'metadesc';
		$words   = absint( $_POST['words'] ?? 0 );
		$attempt = absint( $_POST['attempt'] ?? 0 );
		$exclude = sanitize_text_field( wp_unslash( $_POST['exclude'] ?? '' ) );
		$result  = self::suggest_for_post( $post_id, $kind, $words, $attempt, $exclude );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( $result );
	}

	public static function suggest_for_post( int $post_id, string $kind, int $words = 0, int $attempt = 0, string $exclude = '' ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'Invalid post or permission denied.' );
		}
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}
		if ( ! function_exists( 'bsm_ai_call_webhook' ) ) {
			return new WP_Error( 'unavailable', 'AI engine unavailable.' );
		}

		$rel_n     = max( 1, min( 5, (int) get_option( 'asy_kp_count', 3 ) ) );
		$map       = array(
			'keyphrase'   => array(
				'task'  => 'keyphrase',
				'count' => 5,
			),
			'related'     => array(
				'task'  => 'keyphrase',
				'count' => $rel_n + 1,
			),
			'title'       => array(
				'task'  => 'title',
				'count' => 1,
			),
			'metadesc'    => array(
				'task'  => 'metadesc',
				'count' => 1,
			),
			'subheadings' => array(
				'task'  => 'subheadings',
				'count' => 4,
			),
			'outline'     => array(
				'task'  => 'outline',
				'count' => 5,
			),
			'content'     => array(
				'task'  => 'content',
				'count' => 1,
			),
		);
		$cfg       = $map[ $kind ] ?? $map['metadesc'];
		$keyphrase = (string) get_post_meta( $post->ID, BSM_META_KW, true );
		$word_goal = 'content' === $cfg['task'] ? max( 100, min( 2000, $words ? $words : 600 ) ) : 0;

		// Re-generate variation (3.39.0). The client sends which attempt this is
		// and everything it has already been shown, so pressing Generate again
		// returns a different set. Attempt 1 is a plain first-look request.
		$extra = array();
		if ( $attempt > 1 ) {
			$extra = array(
				'variation' => $attempt,
				'seed'      => wp_generate_password( 10, false, false ),
				'previous'  => array(
					'keyphrase' => in_array( $kind, array( 'keyphrase', 'related' ), true ) ? $keyphrase : '',
					'related'   => in_array( $kind, array( 'keyphrase', 'related' ), true ) ? $exclude : '',
					'metadesc'  => 'metadesc' === $kind ? ( $exclude ? $exclude : (string) get_post_meta( $post->ID, BSM_META_DESC, true ) ) : '',
					'title'     => 'title' === $kind ? ( $exclude ? $exclude : (string) get_post_meta( $post->ID, BSM_META_TITLE, true ) ) : '',
				),
			);
		}

		$result = bsm_ai_call_webhook( $cfg['task'], $post, $cfg['count'], $keyphrase, $word_goal, $extra );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( is_array( $result ) ) {
			$list = array_values( $result );
			// "Related" reuses the keyphrase task: item 0 is the primary, which
			// belongs in the focus field, not the related list.
			if ( 'related' === $kind && count( $list ) > 1 ) {
				$list = array_slice( $list, 1 );
			}
			return array(
				'kind' => $kind,
				'list' => $list,
			);
		}
		return array(
			'kind' => $kind,
			'text' => (string) $result,
		);
	}

	/**
	 * Save a chosen set of related keyphrases straight to Yoast. There is no
	 * related-keyphrase input in "Edit SEO fields", so this writes through the
	 * plugin's canonical writer instead of round-tripping through the form.
	 */
	public static function ajax_apply_related(): void {
		check_ajax_referer( 'bsm_health_save', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( 'Invalid post or permission denied.' );
		}
		if ( ! class_exists( 'ASY_Keyphrase_Engine' ) ) {
			wp_send_json_error( 'Keyphrase engine unavailable.' );
		}

		$raw  = isset( $_POST['related'] )
			? (array) map_deep( wp_unslash( $_POST['related'] ), 'sanitize_text_field' )
			: array();
		$list = array();
		foreach ( $raw as $item ) {
			if ( '' !== trim( (string) $item ) ) {
				$list[] = $item;
			}
		}
		if ( empty( $list ) ) {
			wp_send_json_error( 'Nothing selected.' );
		}

		// Merge with what's already saved rather than replacing it — the edit
		// panel above shows the existing chips, so applying a suggestion must
		// add to that list, not wipe it.
		$existing = array();
		$rel_raw  = get_post_meta( $post_id, '_yoast_wpseo_focuskeywords', true );
		if ( is_string( $rel_raw ) && '' !== $rel_raw ) {
			$rel_dec = json_decode( $rel_raw, true );
			if ( is_array( $rel_dec ) ) {
				foreach ( $rel_dec as $obj ) {
					$kw = is_array( $obj ) ? ( $obj['keyword'] ?? '' ) : (string) $obj;
					if ( '' !== trim( (string) $kw ) ) {
						$existing[] = (string) $kw;
					}
				}
			}
		}
		$merged = self::merge_related( $existing, $list );

		delete_post_meta( $post_id, '_yoast_wpseo_focuskeywords' );
		ASY_Keyphrase_Engine::save_related_keyphrases( $post_id, $merged );
		update_post_meta( $post_id, '_asy_or_keyphrases', implode( ', ', $merged ) );
		update_post_meta( $post_id, '_asy_or_status', 'done' );
		delete_post_meta( $post_id, '_asy_or_error' );

		wp_send_json_success( 'Related keyphrases saved.' );
	}

	public static function merge_related( array $existing, array $added ): array {
		$merged = array();
		foreach ( array_merge( $existing, $added ) as $keyword ) {
			$keyword = trim( preg_replace( '/\s+/', ' ', (string) $keyword ) );
			$norm    = strtolower( $keyword );
			if ( '' !== $norm && ! isset( $merged[ $norm ] ) ) {
				$merged[ $norm ] = $keyword;
			}
		}
		return array_values( $merged );
	}

	/** Save edited Yoast fields via the plugin's canonical writer, then the client re-audits. */
	public static function ajax_save(): void {
		check_ajax_referer( 'bsm_health_save', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( 'Invalid post or permission denied.' );
		}
		if ( ! function_exists( 'bsm_update_fields' ) ) {
			wp_send_json_error( 'Save unavailable.' );
		}
		$keyphrase = sanitize_text_field( wp_unslash( $_POST['keyphrase'] ?? '' ) );
		$metadesc  = sanitize_textarea_field( wp_unslash( $_POST['metadesc'] ?? '' ) );
		$title     = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );

		bsm_update_fields( $post_id, $keyphrase, $metadesc, $title );

		// Related keyphrases travel with the same save. The client always sends
		// related_set, so an empty list means "the editor removed them all" and
		// must clear the meta rather than be treated as nothing to do.
		if ( ! empty( $_POST['related_set'] ) && class_exists( 'ASY_Keyphrase_Engine' ) ) {
			$raw  = isset( $_POST['related'] )
				? (array) map_deep( wp_unslash( $_POST['related'] ), 'sanitize_text_field' )
				: array();
			$list = array();
			foreach ( $raw as $item ) {
				if ( '' !== trim( (string) $item ) ) {
					$list[] = $item;
				}
			}
			if ( empty( $list ) ) {
				delete_post_meta( $post_id, '_yoast_wpseo_focuskeywords' );
				delete_post_meta( $post_id, '_asy_or_keyphrases' );
			} else {
				delete_post_meta( $post_id, '_yoast_wpseo_focuskeywords' );
				ASY_Keyphrase_Engine::save_related_keyphrases( $post_id, $list, $keyphrase );
				update_post_meta( $post_id, '_asy_or_keyphrases', implode( ', ', $list ) );
			}
		}

		wp_send_json_success( 'Saved.' );
	}

	/**
	 * Generate alt text for one attachment via the vision webhook. Returns the
	 * text only — it does NOT persist, so the editor can review/edit before Save.
	 * Payload shape matches Lookit Media Master, so the same published Bedrock
	 * Vision workflow serves both plugins.
	 */
	public static function can_edit_image( int $id ): bool {
		return $id > 0 &&
			'attachment' === get_post_type( $id ) &&
			current_user_can( 'upload_files' ) &&
			current_user_can( 'edit_post', $id ) &&
			wp_attachment_is_image( $id );
	}

	public static function save_image_alt( int $id, string $alt ): bool {
		if ( ! self::can_edit_image( $id ) ) {
			return false;
		}
		update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		return true;
	}

	public static function ajax_alt_generate(): void {
		check_ajax_referer( 'bsm_health_alt', 'nonce' );
		$id     = absint( $_POST['id'] ?? 0 );
		$result = self::generate_image_alt( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success(
			array(
				'id'  => $id,
				'alt' => $result,
			)
		);
	}

	public static function generate_image_alt( int $id ) {
		if ( ! self::can_edit_image( $id ) ) {
			return new WP_Error( 'invalid_image', 'Not an image.' );
		}
		if ( ! function_exists( 'bsm_vision_call' ) ) {
			return new WP_Error( 'unavailable', 'Vision engine unavailable.' );
		}

		$file = get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) ) {
			return new WP_Error( 'missing_file', 'Image file not found on disk.' );
		}
		// Prefer the medium thumbnail for large originals — smaller payload + cost.
		if ( filesize( $file ) > 4 * 1024 * 1024 ) {
			$upload = wp_upload_dir();
			$turl   = wp_get_attachment_image_url( $id, 'medium' );
			if ( $turl ) {
				$rel = str_replace( $upload['baseurl'], $upload['basedir'], $turl );
				if ( file_exists( $rel ) ) {
					$file = $rel;
				}
			}
		}
		if ( filesize( $file ) > 10 * 1024 * 1024 ) {
			return new WP_Error( 'file_too_large', 'Image too large (>10 MB even after thumbnail fallback).' );
		}

		$detected = function_exists( 'mime_content_type' ) ? mime_content_type( $file ) : false;
		$mime     = $detected ? $detected : get_post_mime_type( $id );
		$allowed  = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
		if ( ! in_array( $mime, $allowed, true ) ) {
			return new WP_Error( 'invalid_mime', 'Unsupported image type: ' . $mime );
		}

		// Local file read + base64 for the vision payload; not remote I/O.
		$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local upload for base64 encoding.
		if ( false === $raw || '' === $raw ) {
			return new WP_Error( 'read_failed', 'Could not read image file.' );
		}
		$data_uri = 'data:' . $mime . ';base64,' . base64_encode( $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding for the image payload, not obfuscation.

		$prompt = get_option(
			'bsm_alt_prompt',
			'Write a concise, descriptive alt text for this image. Be specific about what is shown. Keep it under 125 characters. Do not start with "Image of" or "Photo of". Return only the alt text, nothing else.'
		);

		$result = bsm_vision_call( $data_uri, $mime, $prompt );
		if ( empty( $result['ok'] ) ) {
			return new WP_Error( 'generation_failed', $result['error'] ?? 'Generation failed.' );
		}
		return sanitize_text_field( $result['alt'] );
	}

	/** Persist generated/edited alt text to the media library. */
	public static function ajax_alt_save(): void {
		check_ajax_referer( 'bsm_health_alt', 'nonce' );
		$id = absint( $_POST['id'] ?? 0 );
		if ( ! self::can_edit_image( $id ) ) {
			wp_send_json_error( 'Not an image.' );
		}
		$alt = sanitize_text_field( wp_unslash( $_POST['alt'] ?? '' ) );
		self::save_image_alt( $id, $alt );
		wp_send_json_success(
			array(
				'id'  => $id,
				'alt' => $alt,
			)
		);
	}
}
