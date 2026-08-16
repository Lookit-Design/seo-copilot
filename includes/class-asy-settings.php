<?php
/**
 * Settings Page – Auto SEO for Yoast
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASY_Settings {

	public static function init() {
		$instance = new self();
		add_action( 'admin_menu', array( $instance, 'register_menu' ) );
		add_action( 'admin_init', array( $instance, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $instance, 'enqueue_assets' ) );
		add_action( 'wp_ajax_asy_save_templates', array( $instance, 'ajax_save_templates' ) );
		add_action( 'wp_ajax_asy_save_api_key', array( $instance, 'ajax_save_api_key' ) );
	}

	// ── Menu ──────────────────────────────────────────────────────────────────

	public function register_menu() {
		$parent = function_exists( 'wpseo_init' ) ? 'wpseo_dashboard' : 'options-general.php';
		add_submenu_page(
			$parent,
			__( 'Auto SEO Templates', 'lookit-seo-copilot' ),
			__( 'Auto SEO Templates', 'lookit-seo-copilot' ),
			'manage_options',
			'lookit-seo-copilot',
			array( $this, 'render_page' )
		);
	}

	// ── Settings API ──────────────────────────────────────────────────────────

	public function register_settings() {
		register_setting(
			'asy_settings_group',
			ASY_OPTION_KEY,
			array(
				'sanitize_callback' => array( $this, 'sanitize_templates' ),
			)
		);
	}

	/**
	 * Normalise a template string for comparison only.
	 *
	 * The template lists are stored with sanitize_textarea_field(), but the copy
	 * saved against a post type here goes through sanitize_text_field(), which
	 * collapses runs of whitespace and strips %xx sequences. A strict comparison
	 * between the two therefore fails for any template containing a newline,
	 * a double space, a tab or a percent sequence — and the dropdown falls
	 * through to the "(custom)" option, which looks like the save was lost.
	 */
	private static function norm_tpl( $value ) {
		$value = (string) $value;
		while ( preg_match( '/%[a-f0-9]{2}/i', $value, $m ) ) {
			$value = str_replace( $m[0], '', $value );
		}
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', $value ) );
	}

	public function sanitize_templates( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}
		$clean = array();
		foreach ( $input as $post_type => $data ) {
			$pt = sanitize_key( $post_type );

			$kp_allowed  = array( 'ai', 'title', 'title_short', 'slug', 'slug_short', 'topword' );
			$rel_allowed = array( 'off', 'datamuse', 'ai' );
			$kp_src      = isset( $data['keyphrase_source'] ) ? sanitize_key( $data['keyphrase_source'] ) : 'title';
			$rel_src     = isset( $data['related_source'] ) ? sanitize_key( $data['related_source'] ) : 'off';
			if ( ! in_array( $kp_src, $kp_allowed, true ) ) {
				$kp_src = 'title'; }
			if ( ! in_array( $rel_src, $rel_allowed, true ) ) {
				$rel_src = 'off'; }

			$clean[ $pt ] = array(
				'enabled'          => ! empty( $data['enabled'] ),
				'keyphrase_source' => $kp_src,
				'related_source'   => $rel_src,
				'template'         => isset( $data['template'] ) ? sanitize_text_field( $data['template'] ) : '',
				'title_source'     => isset( $data['title_source'] ) ? sanitize_text_field( $data['title_source'] ) : '',
			);
		}
		return $clean;
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	public function enqueue_assets( $hook ) {
		// Settings page: full JS + CSS
		if ( strpos( $hook, 'lookit-seo-copilot' ) !== false ) {
			wp_enqueue_style( 'lookit-bsm-admin', ASY_PLUGIN_URL . 'assets/admin.css', array(), ASY_VERSION );
			wp_enqueue_script( 'lookit-bsm-admin', ASY_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), ASY_VERSION, true );
			wp_localize_script(
				'lookit-bsm-admin',
				'ASY',
				array(
					'ajax_url'    => admin_url( 'admin-ajax.php' ),
					'nonce'       => wp_create_nonce( 'asy_nonce' ),
					'saved'       => __( 'Settings saved!', 'lookit-seo-copilot' ),
					'key_saved'   => __( 'API key saved!', 'lookit-seo-copilot' ),
					'error'       => __( 'Save failed. Please try again.', 'lookit-seo-copilot' ),
					'has_api_key' => ! empty( get_option( 'asy_openrouter_api_key', '' ) ),
				)
			);
			return;
		}
		// Post edit screens: CSS only (for the lock metabox styles)
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			wp_enqueue_style( 'lookit-bsm-admin', ASY_PLUGIN_URL . 'assets/admin.css', array(), ASY_VERSION );
		}
	}

	// ── AJAX: save templates ──────────────────────────────────────────────────

	public function ajax_save_templates() {
		check_ajax_referer( 'asy_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' ); }

		// Sanitized by sanitize_templates() below, which walks every key/value.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = isset( $_POST['templates'] ) && is_array( $_POST['templates'] ) ? wp_unslash( $_POST['templates'] ) : array();

		// An empty payload is never a real "clear everything" instruction — it
		// means the request was blocked, stripped or truncated in transit.
		// Bail instead of overwriting saved settings with nothing.
		if ( empty( $raw ) ) {
			wp_send_json_error( __( 'No settings arrived at the server — nothing was saved.', 'lookit-seo-copilot' ) );
		}

		// Truncation guard. PHP silently discards POST fields past
		// max_input_vars, which on a site with many post types looks exactly
		// like "the save didn't stick". The browser tells us how many rows it
		// sent; if fewer arrived, refuse the write and say why.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$expected = isset( $_POST['row_count'] ) ? absint( $_POST['row_count'] ) : 0;
		if ( $expected > 0 && count( $raw ) < $expected ) {
			wp_send_json_error(
				sprintf(
				/* translators: 1: rows received, 2: rows sent */
					__( 'Only %1$d of %2$d rows reached the server — PHP dropped part of the request (max_input_vars). Nothing was saved.', 'lookit-seo-copilot' ),
					count( $raw ),
					$expected
				)
			);
		}

		// Merge over what is stored so post types that were not on screen (or
		// are registered by a plugin that had not loaded) keep their settings.
		$existing = get_option( ASY_OPTION_KEY, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array(); }
		$clean = $this->sanitize_templates( $raw );

		update_option( ASY_OPTION_KEY, array_merge( $existing, $clean ) );

		// Also save keyphrase count
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		if ( isset( $_POST['kp_count'] ) ) {
			update_option( 'asy_kp_count', absint( $_POST['kp_count'] ) );
		}

		// Read back through a busted cache and hand the stored rows to the
		// browser, so the UI can prove what actually landed in the database
		// rather than assuming a 200 means the write took.
		wp_cache_delete( ASY_OPTION_KEY, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		$stored = get_option( ASY_OPTION_KEY, array() );

		wp_send_json_success(
			array(
				'rows'     => count( $clean ),
				'received' => array_keys( $raw ),
				'stored'   => is_array( $stored ) ? $stored : array(),
			)
		);
	}

	// ── AJAX: save API key separately (keeps it out of POST logs) ────────────

	public function ajax_save_api_key() {
		check_ajax_referer( 'asy_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' ); }

		$key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		if ( empty( $key ) ) {
			wp_send_json_error( 'Empty key.' ); }

		update_option( 'asy_openrouter_api_key', $key );
		wp_send_json_success();
	}

	// ── Render ────────────────────────────────────────────────────────────────

	public function render_page( $embedded = false ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$saved      = get_option( ASY_OPTION_KEY, array() );
		$post_types = $this->get_public_post_types();
		$kp_count   = (int) get_option( 'asy_kp_count', 3 );

		$placeholders = array(
			'{title}'     => __( 'Page title', 'lookit-seo-copilot' ),
			'{site}'      => __( 'Site name', 'lookit-seo-copilot' ),
			'{keyphrase}' => __( 'Post title used as keyphrase', 'lookit-seo-copilot' ),
			'{excerpt}'   => __( 'First 25 words of content', 'lookit-seo-copilot' ),
			'{category}'  => __( 'First category / term', 'lookit-seo-copilot' ),
			'{type}'      => __( 'Post type label', 'lookit-seo-copilot' ),
		);
		?>
		<div class="<?php echo $embedded ? 'asy-wrap asy-embedded' : 'wrap asy-wrap'; ?>">

			<div class="asy-header">
				<?php if ( $embedded ) : ?>
					<h2 style="font-family:'DM Sans',system-ui,sans-serif;font-size:calc(18px * var(--bsm-fs, 1));margin:0 0 6px;"><?php esc_html_e( 'Auto SEO Manager', 'lookit-seo-copilot' ); ?></h2>
				<?php else : ?>
					<h1><?php esc_html_e( 'Auto SEO Templates', 'lookit-seo-copilot' ); ?></h1>
				<?php endif; ?>
				<p class="asy-subtitle">
					<?php esc_html_e( 'Auto-fill Yoast keyphrase, meta description, related keyphrases, and SEO title when a post is published. Add templates in Settings → Description Templates.', 'lookit-seo-copilot' ); ?>
				</p>
			</div>

			<div id="asy-notice" class="asy-notice" style="display:none;"></div>

			<!-- ── Test & Reprocess moved to Settings → Test & reprocess (v3.27.0) ── -->

			<!-- ── Post types table ── -->
			<?php
			/* Real table markup (v3.29.0). Uses the Bulk Editor's proven
					.bsm-table styles, which ship in the inline stylesheet and so
					cannot be blocked by a stylesheet handle collision or served
					stale from a CDN. Table cells cannot wrap onto a second line. */
			?>
			<div class="bsm-auto-tablewrap">
			<table class="bsm-table widefat bsm-auto-table">
				<thead>
					<tr>
						<th class="bsm-auto-th-toggle"><?php esc_html_e( 'Enable', 'lookit-seo-copilot' ); ?></th>
						<th><?php esc_html_e( 'Post Type', 'lookit-seo-copilot' ); ?></th>
						<th><?php esc_html_e( 'Auto Keyphrase', 'lookit-seo-copilot' ); ?></th>
						<th><?php esc_html_e( 'Related Keyphrases', 'lookit-seo-copilot' ); ?></th>
						<th><?php esc_html_e( 'Meta Description Template', 'lookit-seo-copilot' ); ?></th>
						<th><?php esc_html_e( 'SEO Title', 'lookit-seo-copilot' ); ?></th>
					</tr>
				</thead>

				<tbody id="asy-rows">
					<?php
					foreach ( $post_types as $pt_slug => $pt_label ) :
						$row            = isset( $saved[ $pt_slug ] ) ? $saved[ $pt_slug ] : array();
						$enabled        = ! empty( $row['enabled'] );
						$tpl            = isset( $row['template'] ) ? $row['template'] : '';
						$set_kp         = isset( $row['set_keyphrase'] ) ? (bool) $row['set_keyphrase'] : true;
						$slug_kp        = ! empty( $row['slug_keyphrase'] );
						$title_short_kp = ! empty( $row['title_short_keyphrase'] );
						$slug_short_kp  = ! empty( $row['slug_short_keyphrase'] );
						$top_word_kp    = ! empty( $row['top_word_keyphrase'] );
						$ai_kp          = ! empty( $row['ai_keyphrases'] );
						$ai_gen         = ! empty( $row['ai_generate'] );

						// Per-field source model (dropdowns), with legacy fallback.
						$kp_source = isset( $row['keyphrase_source'] ) ? $row['keyphrase_source'] : '';
						if ( '' === $kp_source ) {
							if ( $top_word_kp ) {
								$kp_source = 'topword'; } elseif ( $title_short_kp ) {
								$kp_source = 'title_short'; } elseif ( $slug_short_kp ) {
									$kp_source = 'slug_short'; } elseif ( $slug_kp ) {
									$kp_source = 'slug'; } else {
															$kp_source = 'title'; }
						}
						$rel_source = isset( $row['related_source'] ) ? $row['related_source'] : ( $ai_kp ? 'datamuse' : 'off' );
						if ( $ai_gen && ! isset( $row['keyphrase_source'] ) ) {
							$kp_source  = 'ai';
							$rel_source = 'ai';
							$tpl        = 'ai'; }
						?>
					<tr class="asy-row<?php echo $enabled ? ' asy-row--active' : ''; ?>" data-pt="<?php echo esc_attr( $pt_slug ); ?>">

						<td class="asy-col-toggle">
							<label class="asy-toggle">
								<input type="checkbox" class="asy-enable-cb"
										name="templates[<?php echo esc_attr( $pt_slug ); ?>][enabled]"
										value="1" <?php checked( $enabled ); ?>>
								<span class="asy-toggle-slider"></span>
							</label>
						</td>

						<td class="asy-col-pt">
							<span class="asy-pt-label"><?php echo esc_html( $pt_label ); ?></span>
							<span class="asy-pt-slug"><?php echo esc_html( $pt_slug ); ?></span>
						</td>

						<td class="asy-col-kp">
							<select class="asy-kp-source asy-select" name="templates[<?php echo esc_attr( $pt_slug ); ?>][keyphrase_source]">
								<optgroup label="── AI (Amazon Bedrock) ──">
									<option value="ai" <?php selected( $kp_source, 'ai' ); ?>>&#10022; AI — Nova Lite via platform</option>
								</optgroup>
								<optgroup label="── From the title ──">
									<option value="title" <?php selected( $kp_source, 'title' ); ?>>Full title</option>
									<option value="title_short" <?php selected( $kp_source, 'title_short' ); ?>>Title — first 3 words</option>
								</optgroup>
								<optgroup label="── From the slug ──">
									<option value="slug" <?php selected( $kp_source, 'slug' ); ?>>Slug</option>
									<option value="slug_short" <?php selected( $kp_source, 'slug_short' ); ?>>Slug — first 3 words</option>
								</optgroup>
								<optgroup label="── From content ──">
									<option value="topword" <?php selected( $kp_source, 'topword' ); ?>>Top content word</option>
								</optgroup>
							</select>
						</td>

						<td class="asy-col-ai">
							<select class="asy-rel-source asy-select" name="templates[<?php echo esc_attr( $pt_slug ); ?>][related_source]">
								<option value="off" <?php selected( $rel_source, 'off' ); ?>>Off</option>
								<option value="datamuse" <?php selected( $rel_source, 'datamuse' ); ?>>Datamuse — free</option>
								<option value="ai" <?php selected( $rel_source, 'ai' ); ?>>&#10022; AI — Nova Lite</option>
							</select>
						</td>

						<td class="asy-col-tpl">
							<?php
							$asy_desc_tpls = function_exists( 'bsm_get_templates' ) ? bsm_get_templates() : array();
							$tpl_matched   = ( 'ai' === $tpl );
							?>
							<select class="asy-tpl-input asy-select"
									name="templates[<?php echo esc_attr( $pt_slug ); ?>][template]">
								<option value=""><?php esc_html_e( '— No meta description —', 'lookit-seo-copilot' ); ?></option>
								<optgroup label="── AI (Amazon Bedrock) ──">
									<option value="ai" <?php selected( $tpl, 'ai' ); ?>>&#10022; AI — Nova Lite via platform</option>
								</optgroup>
								<?php if ( ! empty( $asy_desc_tpls ) ) : ?>
								<optgroup label="── Your templates ──">
									<?php
									foreach ( $asy_desc_tpls as $dt ) :
										$dt_str = isset( $dt['template'] ) ? $dt['template'] : '';
										$sel    = ( self::norm_tpl( $tpl ) === self::norm_tpl( $dt_str ) );
										if ( $sel ) {
											$tpl_matched = true; }
										?>
										<option value="<?php echo esc_attr( $dt_str ); ?>" <?php selected( $sel ); ?>>
											<?php echo esc_html( isset( $dt['label'] ) && '' !== $dt['label'] ? $dt['label'] : $dt_str ); ?>
										</option>
									<?php endforeach; ?>
								</optgroup>
								<?php endif; ?>
								<?php if ( '' !== (string) $tpl && ! $tpl_matched ) : ?>
									<option value="<?php echo esc_attr( $tpl ); ?>" selected>
										<?php echo esc_html( $tpl ); ?> <?php esc_html_e( '(custom)', 'lookit-seo-copilot' ); ?>
									</option>
								<?php endif; ?>
							</select>
						</td>

						<?php
						$title_src      = isset( $row['title_source'] ) ? $row['title_source'] : '';
						$asy_title_tpls = function_exists( 'bsm_get_title_templates' ) ? bsm_get_title_templates() : array();
						$title_matched  = ( 'ai' === $title_src );
						?>
						<td class="asy-col-title">
							<select class="asy-title-source asy-select" name="templates[<?php echo esc_attr( $pt_slug ); ?>][title_source]">
								<option value=""><?php esc_html_e( '— No SEO title —', 'lookit-seo-copilot' ); ?></option>
								<optgroup label="── AI (Amazon Bedrock) ──">
									<option value="ai" <?php selected( $title_src, 'ai' ); ?>>&#10022; AI — Nova Lite via platform</option>
								</optgroup>
								<?php if ( ! empty( $asy_title_tpls ) ) : ?>
								<optgroup label="── Your templates ──">
									<?php
									foreach ( $asy_title_tpls as $tt ) :
										$tt_str = isset( $tt['template'] ) ? $tt['template'] : '';
										$tsel   = ( self::norm_tpl( $title_src ) === self::norm_tpl( $tt_str ) );
										if ( $tsel ) {
											$title_matched = true; }
										?>
										<option value="<?php echo esc_attr( $tt_str ); ?>" <?php selected( $tsel ); ?>>
											<?php echo esc_html( isset( $tt['label'] ) && '' !== $tt['label'] ? $tt['label'] : $tt_str ); ?>
										</option>
									<?php endforeach; ?>
								</optgroup>
								<?php endif; ?>
								<?php if ( '' !== (string) $title_src && ! $title_matched ) : ?>
									<option value="<?php echo esc_attr( $title_src ); ?>" selected><?php echo esc_html( $title_src ); ?> <?php esc_html_e( '(custom)', 'lookit-seo-copilot' ); ?></option>
								<?php endif; ?>
							</select>
						</td>

					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div><?php /* /bsm-auto-tablewrap */ ?>

			<p class="bsm-auto-save">
				<button type="button" id="asy-save-btn" class="button button-primary">
					<?php esc_html_e( 'Save Settings', 'lookit-seo-copilot' ); ?>
				</button>
				<span id="asy-save-state" class="asy-save-state" data-rows="<?php echo esc_attr( (string) count( $post_types ) ); ?>">
					<?php esc_html_e( 'All changes saved', 'lookit-seo-copilot' ); ?>
				</span>
			</p>

		</div>
		<?php
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function get_public_post_types() {
		$types  = get_post_types( array( 'public' => true ), 'objects' );
		$result = array();
		foreach ( $types as $slug => $obj ) {
			$result[ $slug ] = $obj->labels->singular_name ?: $slug;
		}
		return $result;
	}
}
