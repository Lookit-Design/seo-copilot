<?php
/**
 * Processor – Auto SEO for Yoast v1.2.0
 *
 * On publish: synchronously sets focus keyphrase, meta description, and
 * related keyphrases via ASY_Keyphrase_Engine (content extraction + Datamuse).
 * No external AI calls means no timeouts and no API costs.
 *
 * The reprocess AJAX action runs synchronously too — it's fast enough.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASY_Processor {

	private static $processed_ids = array();

	public static function init() {
		$instance = new self();
		add_action( 'wp_after_insert_post', array( $instance, 'on_after_insert' ), 999, 4 );
		add_action( 'init', array( $instance, 'register_rest_hooks' ), 99 );
		add_action( 'elementor/editor/after_save', array( $instance, 'on_elementor_save' ), 999, 2 );
		add_action( 'wp_ajax_asy_reprocess_post', array( $instance, 'ajax_reprocess_post' ) );
		add_action( 'wp_ajax_asy_poll_keyphrases', array( $instance, 'ajax_poll_keyphrases' ) );

		// Lock metabox
		add_action( 'add_meta_boxes', array( $instance, 'register_lock_metabox' ) );
		add_action( 'save_post', array( $instance, 'save_lock_metabox' ), 10, 2 );
		add_action( 'rest_after_insert_post', array( $instance, 'save_lock_from_rest' ), 10, 2 );
	}

	// ── Publish hooks ─────────────────────────────────────────────────────────

	public function register_rest_hooks() {
		$post_types = get_post_types(
			array(
				'public'       => true,
				'show_in_rest' => true,
			)
		);
		foreach ( $post_types as $pt ) {
			add_action( "rest_after_insert_{$pt}", array( $this, 'on_rest_publish' ), 999, 2 );
		}
	}

	public function on_after_insert( $post_id, $post, $update, $post_before ) {
		if ( 'publish' !== $post->post_status ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		$this->process( $post );
	}

	public function on_rest_publish( $post, $request ) {
		if ( 'publish' !== $post->post_status ) {
			return;
		}
		$this->process( $post );
	}

	public function on_elementor_save( $post_id, $data ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}
		$this->process( $post );
	}

	// ── Core process ──────────────────────────────────────────────────────────

	public function process( $post ) {
		if ( is_int( $post ) ) {
			$post = get_post( $post );
		}
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}
		if ( in_array( $post->ID, self::$processed_ids, true ) ) {
			return;
		}
		self::$processed_ids[] = $post->ID;

		if ( ! defined( 'WPSEO_VERSION' ) ) {
			return;
		}

		// Respect the per-post lock — never overwrite hand-crafted SEO
		if ( get_post_meta( $post->ID, '_asy_seo_locked', true ) ) {
			$this->log( "Post {$post->ID} is locked — skipping Auto SEO." );
			return;
		}

		$templates = get_option( ASY_OPTION_KEY, array() );
		$pt        = $post->post_type;

		if ( empty( $templates[ $pt ]['enabled'] ) ) {
			return;
		}

		$row = $templates[ $pt ];

		// Resolve per-field sources (dropdown model), with fallback to the
		// legacy checkbox keys so older saved settings keep working.
		$kp_source = isset( $row['keyphrase_source'] ) ? $row['keyphrase_source'] : '';
		if ( '' === $kp_source ) {
			if ( ! empty( $row['top_word_keyphrase'] ) ) {
				$kp_source = 'topword'; } elseif ( ! empty( $row['title_short_keyphrase'] ) ) {
				$kp_source = 'title_short'; } elseif ( ! empty( $row['slug_short_keyphrase'] ) ) {
					$kp_source = 'slug_short'; } elseif ( ! empty( $row['slug_keyphrase'] ) ) {
					$kp_source = 'slug'; } elseif ( ! empty( $row['set_keyphrase'] ) ) {
							$kp_source = 'title'; }
		}
		$rel_source = isset( $row['related_source'] )
			? $row['related_source']
			: ( ! empty( $row['ai_keyphrases'] ) ? 'datamuse' : 'off' );
		$desc_tpl   = isset( $row['template'] ) ? $row['template'] : '';

		// Legacy row-level "Use AI (Bedrock)" → AI for all three fields.
		if ( ! empty( $row['ai_generate'] ) && ! isset( $row['keyphrase_source'] ) ) {
			$kp_source  = 'ai';
			$rel_source = 'ai';
			$desc_tpl   = 'ai';
		}

		$related_n  = max( 1, min( 5, (int) get_option( 'asy_kp_count', 3 ) ) );
		$ai_kp_list = null; // cache AI keyphrase array so related can reuse it
		$primary    = '';

		// 1. Focus keyphrase
		if ( 'ai' === $kp_source && function_exists( 'bsm_ai_call_webhook' ) ) {
			$kp = bsm_ai_call_webhook( 'keyphrase', $post, $related_n + 1 );
			if ( ! is_wp_error( $kp ) && ! empty( $kp ) ) {
				$ai_kp_list = $kp;
				$primary    = $kp[0];
				update_post_meta( $post->ID, '_asy_ai_status', 'done' );
			} else {
				$primary = $post->post_title; // safe fallback so publish never breaks
				update_post_meta( $post->ID, '_asy_ai_status', 'error' );
				$this->log( "AI keyphrase failed for post {$post->ID}: " . ( is_wp_error( $kp ) ? $kp->get_error_message() : 'empty' ) . ' — used title.' );
			}
		} elseif ( 'topword' === $kp_source ) {
			$primary = self::get_top_content_word( $post );
		} elseif ( 'title_short' === $kp_source ) {
			$primary = self::first_words( $post->post_title, 3 );
		} elseif ( 'slug_short' === $kp_source ) {
			$primary = self::first_words( self::slug_to_keyphrase( $post->post_name ), 3 );
		} elseif ( 'slug' === $kp_source ) {
			$primary = self::slug_to_keyphrase( $post->post_name );
		} elseif ( 'title' === $kp_source ) {
			$primary = $post->post_title;
		}
		if ( '' !== $primary ) {
			update_post_meta( $post->ID, '_yoast_wpseo_focuskw', sanitize_text_field( $primary ) );
			$this->log( "Keyphrase ({$kp_source}) set for post {$post->ID}: {$primary}" );
		}

		// 2. Related keyphrases
		if ( 'datamuse' === $rel_source ) {
			$this->generate_keyphrases( $post );
		} elseif ( 'ai' === $rel_source && function_exists( 'bsm_ai_call_webhook' ) ) {
			$list = ( null !== $ai_kp_list ) ? $ai_kp_list : bsm_ai_call_webhook( 'keyphrase', $post, $related_n + 1 );
			if ( ! is_wp_error( $list ) && is_array( $list ) && count( $list ) > 1 ) {
				$related = array_slice( $list, 1, $related_n );
				ASY_Keyphrase_Engine::save_related_keyphrases( $post->ID, $related, $primary );
				update_post_meta( $post->ID, '_asy_or_keyphrases', implode( ', ', $related ) );
				update_post_meta( $post->ID, '_asy_or_status', 'done' );
			}
		}

		// 3. Meta description
		if ( 'ai' === $desc_tpl && function_exists( 'bsm_ai_call_webhook' ) ) {
			$desc = bsm_ai_call_webhook( 'metadesc', $post, 1, $primary ); // already length-capped
			if ( ! is_wp_error( $desc ) && '' !== $desc ) {
				update_post_meta( $post->ID, '_yoast_wpseo_metadesc', sanitize_text_field( $desc ) );
			}
		} elseif ( '' !== $desc_tpl ) {
			$description = $this->fit_meta_description( $this->resolve_template( $desc_tpl, $post ), $post );
			update_post_meta( $post->ID, '_yoast_wpseo_metadesc', sanitize_text_field( $description ) );
			$this->log( "Meta description set for post {$post->ID}: {$description}" );
		}

		// 4. SEO title
		$title_src = isset( $row['title_source'] ) ? $row['title_source'] : '';
		if ( 'ai' === $title_src && function_exists( 'bsm_ai_call_webhook' ) ) {
			$seo_title = bsm_ai_call_webhook( 'title', $post, 1, $primary );
			if ( ! is_wp_error( $seo_title ) && '' !== $seo_title ) {
				update_post_meta( $post->ID, '_yoast_wpseo_title', sanitize_text_field( $seo_title ) );
				$this->log( "SEO title (AI) set for post {$post->ID}: {$seo_title}" );
			}
		} elseif ( '' !== $title_src ) {
			$seo_title = trim( preg_replace( '/\s+/', ' ', $this->resolve_template( $title_src, $post ) ) );
			if ( '' !== $seo_title ) {
				update_post_meta( $post->ID, '_yoast_wpseo_title', sanitize_text_field( $seo_title ) );
				$this->log( "SEO title (template) set for post {$post->ID}: {$seo_title}" );
			}
		}

		update_post_meta( $post->ID, '_asy_processed', current_time( 'mysql' ) );
		update_post_meta( $post->ID, '_asy_version', ASY_VERSION );
	}

	// ── Keyphrase generation ──────────────────────────────────────────────────

	private function generate_keyphrases( WP_Post $post ) {
		$count  = (int) get_option( 'asy_kp_count', 3 );
		$result = ASY_Keyphrase_Engine::get_related_keyphrases( $post, $count );

		if ( is_wp_error( $result ) ) {
			$this->log( "Keyphrase error for post {$post->ID}: " . $result->get_error_message() );
			update_post_meta( $post->ID, '_asy_or_error', $result->get_error_message() );
			update_post_meta( $post->ID, '_asy_or_status', 'error' );
			return;
		}

		ASY_Keyphrase_Engine::save_related_keyphrases( $post->ID, $result );
		update_post_meta( $post->ID, '_asy_or_keyphrases', implode( ', ', $result ) );
		update_post_meta( $post->ID, '_asy_or_status', 'done' );
		delete_post_meta( $post->ID, '_asy_or_error' );
		$this->log( "Keyphrases set for post {$post->ID}: " . implode( ', ', $result ) );
	}

	// ── Admin AJAX: reprocess a single post ──────────────────────────────────

	public function ajax_reprocess_post() {
		check_ajax_referer( 'asy_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( 'Invalid post ID.' );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			wp_send_json_error( "Post {$post_id} not found." );
		}

		if ( ! defined( 'WPSEO_VERSION' ) ) {
			wp_send_json_error( 'Yoast SEO is not active.' );
		}

		$count  = (int) get_option( 'asy_kp_count', 3 );
		$result = ASY_Keyphrase_Engine::get_related_keyphrases( $post, $count );

		if ( is_wp_error( $result ) ) {
			update_post_meta( $post_id, '_asy_or_error', $result->get_error_message() );
			update_post_meta( $post_id, '_asy_or_status', 'error' );
			wp_send_json_error( $result->get_error_message() );
		}

		ASY_Keyphrase_Engine::save_related_keyphrases( $post_id, $result );
		update_post_meta( $post_id, '_asy_or_keyphrases', implode( ', ', $result ) );
		update_post_meta( $post_id, '_asy_or_status', 'done' );
		delete_post_meta( $post_id, '_asy_or_error' );

		wp_send_json_success(
			array(
				'keyphrases' => $result,
				'message'    => 'Keyphrases saved: ' . implode( ', ', $result ),
			)
		);
	}

	// ── Admin AJAX: poll (kept for JS compat, now instant) ────────────────────

	public function ajax_poll_keyphrases() {
		check_ajax_referer( 'asy_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( 'Invalid post ID.' );
		}

		wp_send_json_success(
			array(
				'status'     => get_post_meta( $post_id, '_asy_or_status', true ) ?: 'unknown',
				'keyphrases' => get_post_meta( $post_id, '_asy_or_keyphrases', true ) ?: '',
				'error'      => get_post_meta( $post_id, '_asy_or_error', true ) ?: '',
			)
		);
	}

	// ── Lock metabox ──────────────────────────────────────────────────────────

	/**
	 * Register the "Auto SEO Lock" metabox on all public post types.
	 */
	public function register_lock_metabox() {
		$post_types = get_post_types( array( 'public' => true ) );
		foreach ( $post_types as $pt ) {
			add_meta_box(
				'asy_seo_lock',
				__( 'Auto SEO', 'lookit-seo-copilot' ),
				array( $this, 'render_lock_metabox' ),
				$pt,
				'side',
				'low'
			);
		}
	}

	/**
	 * Render the lock metabox HTML.
	 */
	public function render_lock_metabox( WP_Post $post ) {
		$locked = (bool) get_post_meta( $post->ID, '_asy_seo_locked', true );
		wp_nonce_field( 'asy_lock_nonce', 'asy_lock_nonce_field' );
		?>
		<div class="asy-lock-wrap">
			<label class="asy-lock-label">
				<input type="checkbox"
						name="asy_seo_locked"
						value="1"
						<?php checked( $locked ); ?>>
				<span><?php esc_html_e( 'Lock SEO fields', 'lookit-seo-copilot' ); ?></span>
			</label>
			<p class="asy-lock-hint">
				<?php esc_html_e( 'When locked, Auto SEO will not overwrite the focus keyphrase, meta description, or related keyphrases on publish.', 'lookit-seo-copilot' ); ?>
			</p>
			<?php if ( $locked ) : ?>
				<p class="asy-lock-status asy-lock-status--on">
					🔒 <?php esc_html_e( 'SEO fields are protected', 'lookit-seo-copilot' ); ?>
				</p>
			<?php else : ?>
				<p class="asy-lock-status asy-lock-status--off">
					🔓 <?php esc_html_e( 'Auto SEO is active', 'lookit-seo-copilot' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save the lock value from Classic Editor / quick edit.
	 */
	public function save_lock_metabox( $post_id, WP_Post $post ) {
		// Verify nonce
		if ( ! isset( $_POST['asy_lock_nonce_field'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['asy_lock_nonce_field'] ) ), 'asy_lock_nonce' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$locked = ! empty( $_POST['asy_seo_locked'] );
		update_post_meta( $post_id, '_asy_seo_locked', $locked ? '1' : '' );
	}

	/**
	 * Save the lock value from Gutenberg REST saves.
	 * Gutenberg passes meta via the REST API when the post is saved.
	 */
	public function save_lock_from_rest( $post, $request ) {
		$params = $request->get_params();
		if ( ! isset( $params['meta']['_asy_seo_locked'] ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		$locked = ! empty( $params['meta']['_asy_seo_locked'] );
		update_post_meta( $post->ID, '_asy_seo_locked', $locked ? '1' : '' );
	}

	// ── Template resolution ───────────────────────────────────────────────────

	/**
	 * Convert a URL slug into a readable keyphrase.
	 * "introducing-lookit-sucuri-purge" → "Introducing Lookit Sucuri Purge"
	 */
	/**
	 * Best-match keyphrase: the title-derived phrase best supported by the
	 * post's own intro copy. Scores every title bigram/word by how many
	 * content zones it appears in (excerpt, first sentence, body) plus body
	 * frequency, preferring specific 2-word phrases. A term echoed across the
	 * title AND the opening content is the strongest local focus-keyphrase
	 * signal. Falls back to top content word, then cleaned title.
	 */
	public static function get_best_match_keyphrase( WP_Post $post ) {
		$stop = ASY_Keyphrase_Engine::get_stop_words();

		$normalize = static function ( $text ) {
			$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
			$text = strtolower( $text );
			$text = preg_replace( "/[^a-z0-9\s'-]/", ' ', $text );
			return preg_replace( '/\s+/', ' ', trim( $text ) );
		};

		$title   = $normalize( $post->post_title );
		$excerpt = $normalize( self::get_excerpt( $post ) );
		$content = $normalize( ASY_Keyphrase_Engine::get_content_text( $post ) );

		if ( '' === $title ) {
			return '';
		}

		// First sentence of the content (or a short lead-in fallback).
		preg_match( '/^[^.!?]*[.!?]/', $content, $m );
		$first = ! empty( $m[0] ) ? trim( $m[0] ) : mb_substr( $content, 0, 120 );

		// Meaningful title tokens (drop stop words / very short).
		$tokens = array_values(
			array_filter(
				preg_split( '/\s+/', $title ),
				static function ( $w ) use ( $stop ) {
					return mb_strlen( $w ) >= 3 && ! isset( $stop[ $w ] );
				}
			)
		);
		if ( empty( $tokens ) ) {
			return self::get_clean_title_keyphrase( $post );
		}

		// Candidates: title bigrams first (more specific), then single words.
		$candidates = array();
		$count      = count( $tokens );
		for ( $i = 0; $i < $count - 1; $i++ ) {
			$candidates[] = $tokens[ $i ] . ' ' . $tokens[ $i + 1 ];
		}
		foreach ( $tokens as $t ) {
			$candidates[] = $t;
		}
		$candidates = array_values( array_unique( $candidates ) );

		$best       = '';
		$best_score = -1;
		foreach ( $candidates as $c ) {
			$zones = 0;
			$score = 0;
			if ( false !== strpos( $excerpt, $c ) ) {
				$score += 3;
				++$zones;
			}
			if ( false !== strpos( $first, $c ) ) {
				$score += 3;
				++$zones;
			}
			$freq = substr_count( $content, $c );
			if ( $freq > 0 ) {
				$score += min( $freq, 5 );
				++$zones;
			}
			if ( 0 === $zones ) {
				continue; // title-only term with no content support — skip.
			}
			if ( false !== strpos( $c, ' ' ) ) {
				$score *= 1.6; // prefer 2-word phrases.
			}
			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = $c;
			}
		}

		if ( '' === $best ) {
			$top = self::get_top_content_word( $post );
			return $top ? $top : self::get_clean_title_keyphrase( $post );
		}
		return ucwords( $best );
	}

	/**
	 * Cleaned title: drop any subtitle after a separator (: | – — -),
	 * strip leading list markers/numbers and stop words, and cap at 4 words.
	 * Turns "Antisemitism: A Very Short Introduction" into "Antisemitism".
	 */
	public static function get_clean_title_keyphrase( WP_Post $post ) {
		$stop  = ASY_Keyphrase_Engine::get_stop_words();
		$title = html_entity_decode( wp_strip_all_tags( (string) $post->post_title ), ENT_QUOTES, 'UTF-8' );

		// Keep only the part before the first separator.
		$parts = preg_split( '/\s*[:\|]\s*|\s+[–—-]\s+/u', $title );
		$title = isset( $parts[0] ) ? $parts[0] : $title;

		// Remove a leading list marker / number ("1 ", "1. ", "1) ", "1- ").
		$title = preg_replace( '/^\s*\d+[\.\)\-]?\s*/', '', $title );

		$words = preg_split( '/\s+/', trim( $title ) );
		$keep  = array();
		foreach ( (array) $words as $w ) {
			$clean = trim( $w, " \t\r\n,.;:!?\"'()[]" );
			if ( '' === $clean ) {
				continue;
			}
			if ( isset( $stop[ strtolower( $clean ) ] ) ) {
				continue;
			}
			$keep[] = $clean;
			if ( count( $keep ) >= 4 ) {
				break;
			}
		}
		if ( empty( $keep ) ) {
			return '' !== trim( $title ) ? trim( $title ) : $post->post_title;
		}
		return implode( ' ', $keep );
	}

	/**
	 * Single strongest word from the title. Scores each meaningful title
	 * word by how often it recurs in the body (topical relevance), with a
	 * small length tiebreak. Returns one word, not the whole title.
	 */
	public static function get_title_best_word( WP_Post $post ) {
		$stop = ASY_Keyphrase_Engine::get_stop_words();
		$norm = static function ( $t ) {
			$t = strtolower( html_entity_decode( wp_strip_all_tags( (string) $t ), ENT_QUOTES, 'UTF-8' ) );
			$t = preg_replace( "/[^a-z0-9\s'-]/", ' ', $t );
			return preg_replace( '/\s+/', ' ', trim( $t ) );
		};

		$title   = $norm( $post->post_title );
		$content = $norm( ASY_Keyphrase_Engine::get_content_text( $post ) );

		$words = array_values(
			array_filter(
				explode( ' ', $title ),
				static function ( $w ) use ( $stop ) {
					return mb_strlen( $w ) >= 3 && ! isset( $stop[ $w ] );
				}
			)
		);

		if ( empty( $words ) ) {
			$raw = preg_split( '/\s+/', trim( wp_strip_all_tags( (string) $post->post_title ) ) );
			return ! empty( $raw[0] ) ? $raw[0] : $post->post_title;
		}

		$best       = $words[0];
		$best_score = -1;
		foreach ( $words as $w ) {
			$freq  = '' !== $content ? substr_count( $content, $w ) : 0;
			$score = ( $freq * 2 ) + ( mb_strlen( $w ) / 10 );
			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = $w;
			}
		}
		return ucfirst( $best );
	}

	/**
	 * Words that appear in BOTH the title and the slug — two independent
	 * editorial signals, so their overlap is a high-confidence keyphrase.
	 * Falls back to the best single title word when there's no overlap.
	 */
	public static function get_title_slug_overlap( WP_Post $post ) {
		$stop = ASY_Keyphrase_Engine::get_stop_words();
		$norm = static function ( $t ) {
			$t = strtolower( html_entity_decode( wp_strip_all_tags( (string) $t ), ENT_QUOTES, 'UTF-8' ) );
			$t = str_replace( array( '-', '_' ), ' ', $t );
			$t = preg_replace( "/[^a-z0-9\s']/", ' ', $t );
			return preg_replace( '/\s+/', ' ', trim( $t ) );
		};

		$title_words = array_values(
			array_filter(
				explode( ' ', $norm( $post->post_title ) ),
				static function ( $w ) use ( $stop ) {
					return strlen( $w ) >= 3 && ! isset( $stop[ $w ] );
				}
			)
		);
		$slug_words  = array_flip( explode( ' ', $norm( $post->post_name ) ) );

		$overlap = array();
		foreach ( $title_words as $w ) {
			if ( isset( $slug_words[ $w ] ) ) {
				$overlap[] = $w;
			}
		}

		if ( empty( $overlap ) ) {
			return self::get_title_best_word( $post );
		}
		$overlap = array_slice( array_values( array_unique( $overlap ) ), 0, 4 );
		return ucwords( implode( ' ', $overlap ) );
	}

	public static function slug_to_keyphrase( $slug ) {
		if ( empty( $slug ) ) {
			return '';
		}
		$phrase = str_replace( array( '-', '_' ), ' ', $slug );
		$phrase = preg_replace( '/\s+/', ' ', trim( $phrase ) );
		return ucwords( $phrase );
	}

	/**
	 * Return the first N words of a string (whitespace-collapsed, trimmed).
	 * Used by the "Title short" / "Slug short" keyphrase strategies.
	 *
	 * @param string $text Source text.
	 * @param int    $n    Number of leading words to keep.
	 * @return string
	 */
	public static function first_words( $text, $n = 3 ) {
		$text = preg_replace( '/\s+/', ' ', trim( (string) $text ) );
		if ( $text === '' ) {
			return '';
		}
		$words = preg_split( '/\s+/', $text );
		return implode( ' ', array_slice( $words, 0, max( 1, (int) $n ) ) );
	}

	/**
	 * Find the top-scoring bigram (2-word phrase) from post content.
	 *
	 * Strategy:
	 *   1. Count individual word frequencies (stop words excluded)
	 *   2. Score every bigram as the SUM of its two word frequencies
	 *      — this naturally surfaces pairs where both words are important
	 *   3. Apply a ×1.5 bonus if the bigram appears in the post title
	 *   4. Return the highest-scoring bigram, title-cased
	 *
	 * Falls back to the top single word if fewer than 2 meaningful words exist.
	 *
	 * @return string  e.g. "Web Security", or empty string if nothing found.
	 */
	public static function get_top_content_word( WP_Post $post ) {
		$text = ASY_Keyphrase_Engine::get_content_text( $post );

		if ( empty( $text ) ) {
			$text = sanitize_text_field( $post->post_title );
		}

		$text = strtolower( wp_strip_all_tags( $text ) );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/[^a-z0-9\s\'\-]/', ' ', $text );
		$text = preg_replace( '/\s+/', ' ', trim( $text ) );

		$title_lower = strtolower( sanitize_text_field( $post->post_title ) );
		$stop_words  = ASY_Keyphrase_Engine::get_stop_words();

		// Build clean word list
		$raw_words = preg_split( '/\s+/', $text );
		$words     = array();
		foreach ( $raw_words as $w ) {
			$w = trim( $w, "'-" );
			if ( mb_strlen( $w ) < 3 ) {
				continue;
			}
			if ( isset( $stop_words[ $w ] ) ) {
				continue;
			}
			$words[] = $w;
		}

		if ( empty( $words ) ) {
			return '';
		}

		// Count word frequencies
		$freq = array();
		foreach ( $words as $w ) {
			$freq[ $w ] = ( $freq[ $w ] ?? 0 ) + 1;
		}

		// Need at least 2 words to form a bigram
		if ( count( $words ) < 2 ) {
			arsort( $freq );
			return ucfirst( key( $freq ) );
		}

		// Score every bigram
		$bigrams = array();
		$n       = count( $words );
		for ( $i = 0; $i < $n - 1; $i++ ) {
			$bigram = $words[ $i ] . ' ' . $words[ $i + 1 ];
			$score  = ( $freq[ $words[ $i ] ] ?? 1 ) + ( $freq[ $words[ $i + 1 ] ] ?? 1 );

			// Bonus if the bigram appears in the title
			if ( strpos( $title_lower, $bigram ) !== false ) {
				$score *= 1.5;
			}

			// Accumulate — same bigram may appear multiple times
			$bigrams[ $bigram ] = ( $bigrams[ $bigram ] ?? 0 ) + $score;
		}

		arsort( $bigrams );
		$top_bigram = key( $bigrams );

		return ucwords( $top_bigram );
	}

	private function resolve_template( $template, WP_Post $post ) {
		$parent       = $post->post_parent ? get_the_title( $post->post_parent ) : '';
		$replacements = array(
			'{title_short}' => self::first_words( $post->post_title, 4 ),
			'{title}'       => $post->post_title,
			'{site}'        => get_bloginfo( 'name' ),
			'{sep}'         => '–',
			'{keyphrase}'   => $post->post_title,
			'{excerpt}'     => self::get_excerpt( $post ),
			'{category}'    => self::get_primary_term( $post ),
			'{type}'        => $this->get_post_type_label( $post->post_type ),
			'{slug}'        => self::slug_to_keyphrase( $post->post_name ),
			'{parent}'      => $parent,
		);
		return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
	}

	public static function get_excerpt( WP_Post $post ) {
		if ( ! empty( $post->post_excerpt ) ) {
			return wp_trim_words( $post->post_excerpt, 25, '' );
		}
		return wp_trim_words( wp_strip_all_tags( $post->post_content ), 25, '' );
	}

	public static function get_primary_term( WP_Post $post ) {
		$taxonomies = get_object_taxonomies( $post->post_type );
		if ( empty( $taxonomies ) ) {
			return '';
		}
		$primary_tax     = in_array( 'category', $taxonomies, true ) ? 'category' : $taxonomies[0];
		$primary_term_id = get_post_meta( $post->ID, '_yoast_wpseo_primary_' . $primary_tax, true );
		if ( $primary_term_id ) {
			$term = get_term( (int) $primary_term_id, $primary_tax );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term->name;
			}
		}
		$terms = get_the_terms( $post->ID, $primary_tax );
		if ( $terms && ! is_wp_error( $terms ) ) {
			return $terms[0]->name;
		}
		return '';
	}

	private function get_post_type_label( $post_type ) {
		$obj = get_post_type_object( $post_type );
		return $obj ? $obj->labels->singular_name : $post_type;
	}

	private function truncate( $string, $max ) {
		if ( mb_strlen( $string ) <= $max ) {
			return $string;
		}
		return mb_substr( $string, 0, $max - 1 ) . '…';
	}

	/**
	 * Fit a meta description into the Yoast "green" sweet spot.
	 *
	 * Target window defaults to 107–141 chars (the Bulk SEO Manager green zone).
	 * - Over the max: trimmed to <= max on a word boundary (no mid-word cut).
	 * - Under the min: extended using the post's plain-text content
	 *   (Elementor-aware), without duplicating text already present.
	 * If the post genuinely lacks enough content to reach the min, the best
	 * available text is returned as-is.
	 */
	private function fit_meta_description( $text, WP_Post $post ) {
		$min = defined( 'BSM_DESC_LO' ) ? BSM_DESC_LO : 107;
		$max = defined( 'BSM_DESC_HI' ) ? BSM_DESC_HI : 141;

		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );

		// Extend if under the minimum.
		if ( mb_strlen( $text ) < $min ) {
			$filler = '';
			if ( class_exists( 'ASY_Keyphrase_Engine' ) ) {
				$filler = wp_strip_all_tags( ASY_Keyphrase_Engine::get_content_text( $post ) );
			}
			if ( '' === $filler ) {
				$filler = wp_strip_all_tags( $post->post_content );
			}
			$filler = trim( preg_replace( '/\s+/', ' ', html_entity_decode( $filler, ENT_QUOTES, 'UTF-8' ) ) );

			if ( '' !== $filler ) {
				if ( '' === $text ) {
					$text = $filler;
				} elseif ( 0 === stripos( $filler, $text ) ) {
					$text = $filler; // filler is a longer superset
				} elseif ( false === stripos( $text, $filler ) ) {
					$text = trim( rtrim( $text, ' .-—|' ) . ' ' . $filler );
				}
				$text = trim( preg_replace( '/\s+/', ' ', $text ) );
			}
		}

		// Trim to <= max on a word boundary.
		if ( mb_strlen( $text ) > $max ) {
			$cut = mb_substr( $text, 0, $max );
			$sp  = mb_strrpos( $cut, ' ' );
			if ( false !== $sp && $sp > $max * 0.6 ) {
				$cut = mb_substr( $cut, 0, $sp );
			}
			$text = rtrim( $cut, ' ,;:-—|' );
		}

		return $text;
	}

	private function log( $message ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[Lookit Bulk SEO Manager] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- guarded by WP_DEBUG_LOG, dev diagnostics only.
		}
	}
}
