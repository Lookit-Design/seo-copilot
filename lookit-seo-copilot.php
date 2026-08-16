<?php
/**
 * Plugin Name:  Lookit SEO Copilot
 * Plugin URI:   https://lookitai.com
 * Description:  Manage Yoast SEO Focus Keyphrases and Meta Descriptions for all post types from one screen — plus an Auto SEO Manager that auto-fills Yoast fields on publish (content extraction + Datamuse, no AI key needed).
 * Version:      3.34.1
 * Author:       Lookit Design
 * Author URI:   https://lookitai.com
 * License:      GPL-2.0+
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Text Domain:  lookit-seo-copilot
 */

defined( 'ABSPATH' ) || exit;

define( 'BSM_VERSION', '3.34.1' );
define( 'BSM_NONCE', 'bsm_save_nonce' );
define( 'BSM_AJAX_NONCE', 'bsm_ajax_nonce' );
define( 'BSM_META_KW', '_yoast_wpseo_focuskw' );
define( 'BSM_META_DESC', '_yoast_wpseo_metadesc' );
define( 'BSM_META_TITLE', '_yoast_wpseo_title' );
define( 'BSM_PER_PAGE', 25 );
define( 'BSM_DESC_LO', 107 );
define( 'BSM_DESC_HI', 141 );

add_action( 'admin_menu', 'bsm_register_menu' );
add_action( 'admin_init', 'bsm_handle_save' );
add_action( 'admin_enqueue_scripts', 'bsm_enqueue_assets' );
add_action( 'wp_ajax_bsm_save_single', 'bsm_ajax_save_single' );
add_action( 'wp_ajax_bsm_save_all', 'bsm_ajax_save_all' );
add_action( 'wp_ajax_bsm_get_meta_fields', 'bsm_ajax_get_meta_fields' );
add_action( 'wp_ajax_bsm_get_desc_data', 'bsm_ajax_get_desc_data' );
add_action( 'wp_ajax_bsm_fill_keyphrases', 'bsm_ajax_fill_keyphrases' );
add_action( 'wp_ajax_bsm_ai_fill', 'bsm_ajax_ai_fill' );
add_action( 'wp_ajax_bsm_save_ai_webhook', 'bsm_ajax_save_ai_webhook' );
add_action( 'wp_ajax_bsm_save_kp_count', 'bsm_ajax_save_kp_count' );
add_action( 'admin_post_bsm_save_all', 'bsm_handle_save' );
add_action( 'admin_post_bsm_health_export', array( 'BSM_Health', 'export' ) );
add_action( 'wp_ajax_bsm_health_suggest', array( 'BSM_Health', 'ajax_suggest' ) );
add_action( 'wp_ajax_bsm_health_save', array( 'BSM_Health', 'ajax_save' ) );

// ─── Auto SEO Manager engine (merged from "Auto SEO for Yoast" v1.2.5) ───────
// Brings the on-publish auto-fill engine in as a tab. All Auto SEO option keys,
// meta keys, class names, and AJAX action names are preserved unchanged so an
// existing "Auto SEO for Yoast" install carries its settings straight over.

define( 'ASY_VERSION', '3.15.3' );
define( 'ASY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ASY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ASY_OPTION_KEY', 'asy_post_type_templates' );

require_once ASY_PLUGIN_DIR . 'includes/class-asy-keyphrase-engine.php';
require_once ASY_PLUGIN_DIR . 'includes/class-asy-openrouter.php'; // kept for back-compat
require_once ASY_PLUGIN_DIR . 'includes/class-asy-settings.php';
require_once ASY_PLUGIN_DIR . 'includes/class-asy-processor.php';
require_once ASY_PLUGIN_DIR . 'includes/class-bsm-health.php';

// The processor (publish hooks, reprocess AJAX, lock metabox) runs as-is.
add_action( 'plugins_loaded', array( 'ASY_Processor', 'init' ) );

// ASY_Settings normally registers its own admin menu. We don't want that — the
// Auto SEO UI lives inside the Bulk SEO Manager tab — so we register only the
// pieces we need (settings + AJAX save) and skip its menu/enqueue.
add_action(
	'plugins_loaded',
	function () {
		$asy = new ASY_Settings();
		add_action( 'admin_init', array( $asy, 'register_settings' ) );
		add_action( 'wp_ajax_asy_save_templates', array( $asy, 'ajax_save_templates' ) );
		add_action( 'wp_ajax_asy_save_api_key', array( $asy, 'ajax_save_api_key' ) );
		$GLOBALS['bsm_asy_settings'] = $asy;
	}
);

// Register the lock meta for Gutenberg REST access (from the Auto SEO loader).
add_action(
	'init',
	function () {
		foreach ( get_post_types( array( 'public' => true ) ) as $pt ) {
			register_post_meta(
				$pt,
				'_asy_seo_locked',
				array(
					'show_in_rest'  => true,
					'single'        => true,
					'type'          => 'string',
					'auth_callback' => function () {
						return current_user_can( 'edit_posts' ); },
				)
			);
		}
	}
);

// ─── Post types ──────────────────────────────────────────────────────────────

function bsm_get_post_types(): array {
	$exclude = array(
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
	);
	$types   = get_post_types(
		array(
			'public'  => true,
			'show_ui' => true,
		),
		'objects'
	);
	foreach ( $exclude as $slug ) {
		unset( $types[ $slug ] );
	}
	return $types;
}

// ─── Menu ────────────────────────────────────────────────────────────────────

function bsm_register_menu() {
	add_menu_page( 'Lookit SEO Copilot', 'SEO Copilot', 'edit_posts', 'lookit-bulk-seo', 'bsm_render_page', 'dashicons-chart-line', 58 );
	add_submenu_page( 'lookit-bulk-seo', 'Lookit SEO Copilot', 'SEO Manager', 'edit_posts', 'lookit-bulk-seo', 'bsm_render_page' );
	add_submenu_page( 'lookit-bulk-seo', 'SEO Settings', 'SEO Settings', 'manage_options', 'lookit-bulk-seo-settings', 'bsm_render_settings' );
}

// ─── Settings registration ────────────────────────────────────────────────────

add_action(
	'admin_init',
	function () {
		register_setting(
			'bsm_settings_group',
			'bsm_custom_templates',
			array(
				'sanitize_callback' => 'bsm_sanitize_templates',
				'default'           => array(),
			)
		);
		register_setting(
			'bsm_settings_group',
			'bsm_keyphrase_templates',
			array(
				'sanitize_callback' => 'bsm_sanitize_templates',
				'default'           => array(),
			)
		);
		register_setting(
			'bsm_settings_group',
			'bsm_title_templates',
			array(
				'sanitize_callback' => 'bsm_sanitize_templates',
				'default'           => array(),
			)
		);
	}
);

// Sanitize the templates array — each item has a label and a template string
function bsm_sanitize_templates( $input ): array {
	if ( ! is_array( $input ) ) {
		return array();
	}
	$out = array();
	foreach ( $input as $item ) {
		$label    = sanitize_text_field( $item['label'] ?? '' );
		$template = sanitize_textarea_field( $item['template'] ?? '' );
		if ( '' !== $label && '' !== $template ) {
			$out[] = array(
				'label'    => $label,
				'template' => $template,
			);
		}
	}
	return $out;
}

function bsm_get_templates(): array {
	return (array) get_option( 'bsm_custom_templates', array() );
}

function bsm_get_keyphrase_templates(): array {
	return (array) get_option( 'bsm_keyphrase_templates', array() );
}

/**
 * Text size preference for the plugin's admin screens.
 *
 * Stored per user, not per site — readability is a personal preference, and one
 * admin bumping the size shouldn't change it for everyone else.
 * Returns a multiplier applied to every font-size via the --bsm-fs CSS variable.
 */
function bsm_text_sizes(): array {
	return array(
		'default' => array(
			'label' => 'Default',
			'scale' => '1',
			'note'  => 'Matches WordPress admin',
		),
		'large'   => array(
			'label' => 'Large',
			'scale' => '1.1',
			'note'  => '10% larger',
		),
		'xlarge'  => array(
			'label' => 'Larger',
			'scale' => '1.2',
			'note'  => '20% larger',
		),
		'xxlarge' => array(
			'label' => 'Largest',
			'scale' => '1.35',
			'note'  => '35% larger',
		),
	);
}

function bsm_get_text_size(): string {
	$key   = (string) get_user_meta( get_current_user_id(), 'bsm_text_size', true );
	$sizes = bsm_text_sizes();
	return isset( $sizes[ $key ] ) ? $key : 'default';
}

function bsm_get_text_scale(): string {
	$sizes = bsm_text_sizes();
	return $sizes[ bsm_get_text_size() ]['scale'];
}

add_action( 'wp_ajax_bsm_save_text_size', 'bsm_ajax_save_text_size' );
function bsm_ajax_save_text_size(): void {
	check_ajax_referer( BSM_AJAX_NONCE, 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( 'Permission denied.' );
	}

	$key   = isset( $_POST['size'] ) ? sanitize_key( wp_unslash( $_POST['size'] ) ) : 'default';
	$sizes = bsm_text_sizes();
	if ( ! isset( $sizes[ $key ] ) ) {
		wp_send_json_error( 'Unknown size.' );
	}

	update_user_meta( get_current_user_id(), 'bsm_text_size', $key );
	wp_send_json_success( array( 'scale' => $sizes[ $key ]['scale'] ) );
}


function bsm_get_title_templates(): array {
	return (array) get_option( 'bsm_title_templates', array() );
}


// ─── Settings preview: resolve a template against a real post ────────────────
// Mirrors ASY_Processor::resolve_template() using its public static helpers, so
// the Settings preview shows exactly what Auto SEO Manager would write.

function bsm_resolve_template( string $template, WP_Post $post ): string {
	if ( '' === trim( $template ) ) {
		return '';
	}

	$type_obj = get_post_type_object( $post->post_type );
	$parent   = $post->post_parent ? get_the_title( $post->post_parent ) : '';

	$map = array(
		'{title_short}' => ASY_Processor::first_words( $post->post_title, 4 ),
		'{title}'       => $post->post_title,
		'{site}'        => get_bloginfo( 'name' ),
		'{sep}'         => '–',
		'{keyphrase}'   => (string) get_post_meta( $post->ID, BSM_META_KW, true ) ? (string) get_post_meta( $post->ID, BSM_META_KW, true ) : $post->post_title,
		'{excerpt}'     => ASY_Processor::get_excerpt( $post ),
		'{category}'    => ASY_Processor::get_primary_term( $post ),
		'{type}'        => $type_obj ? $type_obj->labels->singular_name : $post->post_type,
		'{slug}'        => ASY_Processor::slug_to_keyphrase( $post->post_name ),
		'{parent}'      => $parent,
	);

	// Custom meta placeholders: anything left over is looked up as post meta.
	$out = str_replace( array_keys( $map ), array_values( $map ), $template );
	$out = preg_replace_callback(
		'/\{([a-zA-Z0-9_\-]+)\}/',
		function ( $m ) use ( $post ) {
			$val = get_post_meta( $post->ID, $m[1], true );
			return is_scalar( $val ) ? (string) $val : '';
		},
		$out
	);

	return trim( preg_replace( '/\s+/', ' ', $out ) );
}

/**
 * Resolve the three template kinds against one post for the Settings preview.
 * Read-only — never writes to Yoast.
 */
add_action( 'wp_ajax_bsm_preview_templates', 'bsm_ajax_preview_templates' );
function bsm_ajax_preview_templates(): void {
	check_ajax_referer( BSM_AJAX_NONCE, 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Permission denied.' );
	}

	$post_id = absint( $_POST['post_id'] ?? 0 );
	$post    = $post_id ? get_post( $post_id ) : null;
	if ( ! $post ) {
		wp_send_json_error( 'Post not found.' );
	}

	// Remember the chosen sample per user, not site-wide.
	update_user_meta( get_current_user_id(), 'bsm_preview_post_id', $post->ID );

	$title_tpl = isset( $_POST['title_tpl'] ) ? sanitize_text_field( wp_unslash( $_POST['title_tpl'] ) ) : '';
	$desc_tpl  = isset( $_POST['desc_tpl'] ) ? sanitize_text_field( wp_unslash( $_POST['desc_tpl'] ) ) : '';
	$kp_tpl    = isset( $_POST['kp_tpl'] ) ? sanitize_text_field( wp_unslash( $_POST['kp_tpl'] ) ) : '';

	$type_obj  = get_post_type_object( $post->post_type );
	$permalink = get_permalink( $post );

	wp_send_json_success(
		array(
			'post'  => array(
				'id'    => $post->ID,
				'title' => $post->post_title,
				'type'  => $type_obj ? $type_obj->labels->singular_name : $post->post_type,
				'slug'  => $post->post_name,
				'url'   => $permalink ? preg_replace( '#^https?://#', '', untrailingslashit( $permalink ) ) : '',
				'edit'  => get_edit_post_link( $post->ID, 'raw' ),
			),
			'title' => $title_tpl ? bsm_resolve_template( $title_tpl, $post ) : '',
			'desc'  => $desc_tpl ? bsm_resolve_template( $desc_tpl, $post ) : '',
			'kp'    => $kp_tpl ? bsm_resolve_template( $kp_tpl, $post ) : '',
			// What Yoast holds right now, shown when no template is selected.
			'live'  => array(
				'title' => (string) get_post_meta( $post->ID, BSM_META_TITLE, true ),
				'desc'  => (string) get_post_meta( $post->ID, BSM_META_DESC, true ),
				'kp'    => (string) get_post_meta( $post->ID, BSM_META_KW, true ),
			),
		)
	);
}

/**
 * Post picker for the Settings preview. Title/ID search across public types.
 */
add_action( 'wp_ajax_bsm_search_posts', 'bsm_ajax_search_posts' );
function bsm_ajax_search_posts(): void {
	check_ajax_referer( BSM_AJAX_NONCE, 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Permission denied.' );
	}

	$term = isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '';
	$type = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';

	$types = $type && 'all' !== $type
		? array( $type )
		: array_keys( get_post_types( array( 'public' => true ), 'names' ) );

	$args = array(
		'post_type'              => $types,
		'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
		'posts_per_page'         => 20,
		'orderby'                => 'modified',
		'order'                  => 'DESC',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	);

	// A bare number is treated as an ID lookup first.
	if ( '' !== $term && ctype_digit( $term ) && get_post( (int) $term ) ) {
		$args['p'] = (int) $term;
	} elseif ( '' !== $term ) {
		$args['s'] = $term;
	}

	$out = array();
	foreach ( ( new WP_Query( $args ) )->posts as $p ) {
		$obj   = get_post_type_object( $p->post_type );
		$out[] = array(
			'id'    => $p->ID,
			'title' => '' !== $p->post_title ? $p->post_title : '(no title)',
			'type'  => $obj ? $obj->labels->singular_name : $p->post_type,
			'slug'  => $p->post_name,
		);
	}

	wp_send_json_success( array( 'posts' => $out ) );
}

/**
 * Public post types for the picker's filter row.
 */
function bsm_picker_types(): array {
	$out = array();
	foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $slug => $obj ) {
		if ( 'attachment' === $slug ) {
			continue;
		}
		$out[ $slug ] = $obj->labels->name ? $obj->labels->name : $slug;
	}
	return $out;
}


// ─── AJAX: discover meta fields registered on this site ──────────────────────

function bsm_ajax_get_meta_fields(): void {
	check_ajax_referer( BSM_AJAX_NONCE, 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Permission denied.' );
	}

	$fields = array();

	// ── 1. JetEngine meta fields ──
	try {
		if ( function_exists( 'jet_engine' ) && isset( jet_engine()->meta_boxes ) ) {
			$meta_boxes = jet_engine()->meta_boxes->get_items_for_register();
			foreach ( (array) $meta_boxes as $box ) {
				$box_name = $box['args']['name'] ?? ( $box['title'] ?? 'JetEngine' );
				foreach ( (array) ( $box['meta_fields'] ?? array() ) as $field ) {
					$name  = $field['name'] ?? '';
					$title = $field['title'] ?? $name;
					$type  = $field['type'] ?? 'text';
					if ( $name ) {
						$fields[] = array(
							'key'         => $name,
							'label'       => ( $title ? $title : $name ) . ' (' . $box_name . ')',
							'source'      => 'JetEngine',
							'type'        => $type,
							'placeholder' => '{meta:' . $name . '}',
						);
					}
				}
			}
		}
	} catch ( \Throwable $e ) {
		unset( $e );
	}

	// ── 2. ACF fields ──
	try {
		if ( function_exists( 'acf_get_field_groups' ) ) {
			$groups = acf_get_field_groups();
			foreach ( (array) $groups as $group ) {
				$gname   = $group['title'] ?? 'ACF';
				$gfields = function_exists( 'acf_get_fields' ) ? acf_get_fields( $group['key'] ?? '' ) : array();
				foreach ( (array) $gfields as $field ) {
					$name  = $field['name'] ?? '';
					$label = $field['label'] ?? $name;
					$type  = $field['type'] ?? 'text';
					if ( $name ) {
						$fields[] = array(
							'key'         => $name,
							'label'       => ( $label ? $label : $name ) . ' (' . $gname . ')',
							'source'      => 'ACF',
							'type'        => $type,
							'placeholder' => '{meta:' . $name . '}',
						);
					}
				}
			}
		}
	} catch ( \Throwable $e ) {
		unset( $e );
	}

	// ── 3. Meta Box / RWMB ──
	try {
		if ( function_exists( 'rwmb_get_registry' ) ) {
			$registry = rwmb_get_registry( 'field' );
			if ( $registry ) {
				foreach ( $registry->get_by_object_type( 'post' ) as $box_id => $box_fields ) {
					foreach ( (array) $box_fields as $field ) {
						$name  = $field['id'] ?? '';
						$label = $field['name'] ?? $name;
						$type  = $field['type'] ?? 'text';
						if ( $name ) {
							$fields[] = array(
								'key'         => $name,
								'label'       => ( $label ? $label : $name ) . ' (Meta Box)',
								'source'      => 'MetaBox',
								'type'        => $type,
								'placeholder' => '{meta:' . $name . '}',
							);
						}
					}
				}
			}
		}
	} catch ( \Throwable $e ) {
		unset( $e );
	}

	// ── 4. WordPress core + Yoast placeholders (always shown) ──
	$core = array(
		array(
			'key'         => '_title',
			'label'       => 'Post Title',
			'source'      => 'WordPress',
			'type'        => 'text',
			'placeholder' => '{title}',
		),
		array(
			'key'         => '_excerpt',
			'label'       => 'Post Excerpt / Content',
			'source'      => 'WordPress',
			'type'        => 'textarea',
			'placeholder' => '{excerpt}',
		),
		array(
			'key'         => '_type',
			'label'       => 'Post Type Label',
			'source'      => 'WordPress',
			'type'        => 'text',
			'placeholder' => '{type}',
		),
		array(
			'key'         => '_site',
			'label'       => 'Site Name',
			'source'      => 'WordPress',
			'type'        => 'text',
			'placeholder' => '{site}',
		),
		array(
			'key'         => '_category',
			'label'       => 'First Category / Term',
			'source'      => 'WordPress',
			'type'        => 'text',
			'placeholder' => '{category}',
		),
		array(
			'key'         => '_keyphrase',
			'label'       => 'Yoast Focus Keyphrase',
			'source'      => 'Yoast',
			'type'        => 'text',
			'placeholder' => '{keyphrase}',
		),
	);

	// ── 5. Raw postmeta scan — public keys actually used on this site ──
	// Excludes keys starting with _ (private/internal) using PHP string check
	// to avoid SQL escaping issues with LIKE patterns.
	try {
		global $wpdb;
		$all_keys = wp_cache_get( 'bsm_distinct_meta_keys', 'lookit-seo-copilot' );
		if ( false === $all_keys ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- distinct meta_key list for the admin field browser; result cached below.
			$all_keys = $wpdb->get_col(
				"SELECT DISTINCT meta_key FROM {$wpdb->postmeta} ORDER BY meta_key LIMIT 500"
			);
			wp_cache_set( 'bsm_distinct_meta_keys', $all_keys, 'lookit-seo-copilot', 5 * MINUTE_IN_SECONDS );
		}

		$skip_prefixes = array( '_', 'seopress', 'rankmath', 'rank_math', 'wp_', 'elementor', '_elementor' );
		$skip_contains = array( 'transient', 'session', 'nonce', 'token', 'password', 'hash' );
		$already_keys  = array_column( $fields, 'key' );

		foreach ( (array) $all_keys as $mk ) {
			if ( in_array( $mk, $already_keys, true ) ) {
				continue;
			}

			// Skip private/internal keys
			$skip = false;
			foreach ( $skip_prefixes as $prefix ) {
				if ( 0 === strpos( $mk, $prefix ) ) {
					$skip = true;
					break; }
			}
			if ( ! $skip ) {
				foreach ( $skip_contains as $needle ) {
					if ( false !== strpos( strtolower( $mk ), $needle ) ) {
						$skip = true;
						break; }
				}
			}
			if ( $skip ) {
				continue;
			}

			$fields[]       = array(
				'key'         => $mk,
				'label'       => $mk,
				'source'      => 'Post Meta',
				'type'        => 'meta',
				'placeholder' => '{meta:' . $mk . '}',
			);
			$already_keys[] = $mk;
		}
	} catch ( \Throwable $e ) {
		unset( $e );
	}

	wp_send_json_success(
		array(
			'core'   => $core,
			'fields' => $fields,
		)
	);
}

// ─── Settings page ───────────────────────────────────────────────────────────

// ─── Auto SEO Manager tab ─────────────────────────────────────────────────────

function bsm_render_auto_section(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Permission denied.' );
	}
	$asy = $GLOBALS['bsm_asy_settings'] ?? new ASY_Settings();
	?>
	<div class="wrap" id="bsm-wrap">
		<?php bsm_topbar(); ?>
		<?php bsm_render_tabs( 'auto' ); ?>

		<?php if ( ! defined( 'WPSEO_VERSION' ) ) : ?>
			<div class="notice notice-warning"><p><strong>Yoast SEO not active.</strong> Auto SEO writes to Yoast fields, so nothing will be filled until Yoast is activated.</p></div>
		<?php endif; ?>

		<?php $asy->render_page( true ); ?>
	</div>
	<?php
}

// ─── Settings page ─────────────────────────────────────────────────────────────

function bsm_render_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Permission denied.' );
	}
	$templates       = bsm_get_templates();
	$kw_templates    = bsm_get_keyphrase_templates();
	$title_templates = bsm_get_title_templates();
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by WP core redirect.
	$saved      = isset( $_GET['settings-updated'] );
	$ajax_nonce = wp_create_nonce( BSM_AJAX_NONCE );
	?>
	<div class="wrap" id="bsm-wrap">
		<?php bsm_topbar(); ?>
		<?php bsm_render_tabs( 'settings' ); ?>

		<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
		<?php endif; ?>

		<h2 class="bsm-set-h1">Settings</h2>
		<p class="bsm-set-sub">
			Connect the AI platform and build the templates that fill Yoast fields. Templates appear in the matching
			dropdowns on the Bulk Editor, and in Auto SEO Manager.
		</p>

		<?php
		$bsm_ai_webhook = get_option( 'bsm_ai_webhook_url', '' );
		$bsm_kp_count   = max( 1, min( 5, (int) get_option( 'asy_kp_count', 3 ) ) );

		// Sample post for the preview rail — per user, so one admin's choice
		// doesn't change what another admin sees.
		$bsm_sample_id = (int) get_user_meta( get_current_user_id(), 'bsm_preview_post_id', true );
		if ( ! $bsm_sample_id || ! get_post( $bsm_sample_id ) ) {
			$bsm_recent    = get_posts(
				array(
					'numberposts' => 1,
					'post_status' => 'publish',
					'orderby'     => 'modified',
				)
			);
			$bsm_sample_id = $bsm_recent ? (int) $bsm_recent[0]->ID : 0;
		}
		$bsm_sample = $bsm_sample_id ? get_post( $bsm_sample_id ) : null;

		$bsm_panes  = array(
			'engine'   => array( 'Setup', 'AI engine' ),
			'defaults' => array( 'Setup', 'Defaults' ),
			'textsize' => array( 'Setup', 'Text size' ),
			'title'    => array( 'Templates', 'SEO titles' ),
			'desc'     => array( 'Templates', 'Descriptions' ),
			'kp'       => array( 'Templates', 'Keyphrases' ),
			'test'     => array( 'Tools', 'Test &amp; reprocess' ),
			'fields'   => array( 'Tools', 'Custom fields' ),
			'help'     => array( 'Tools', 'How it works' ),
		);
		$bsm_counts = array(
			'title' => count( $title_templates ),
			'desc'  => count( $templates ),
			'kp'    => count( $kw_templates ),
		);
		?>

		<form method="post" action="options.php" id="bsm-settings-form">
			<?php settings_fields( 'bsm_settings_group' ); ?>

			<div class="bsm-set-shell" id="bsm-set-shell">

				<?php /* ── Nav rail ── */ ?>
				<nav class="bsm-set-rail" id="bsm-set-rail" aria-label="<?php esc_attr_e( 'Settings sections', 'lookit-seo-copilot' ); ?>">
					<?php
					$bsm_last_group = ''; foreach ( $bsm_panes as $bsm_key => $bsm_meta ) :
						[ $bsm_group, $bsm_label ] = $bsm_meta;
						if ( $bsm_group !== $bsm_last_group ) {
							echo '<div class="bsm-set-group">' . esc_html( $bsm_group ) . '</div>';
							$bsm_last_group = $bsm_group;
						}
						?>
						<button type="button" class="bsm-set-navbtn<?php echo 'engine' === $bsm_key ? ' is-active' : ''; ?>"
								data-pane="<?php echo esc_attr( $bsm_key ); ?>">
							<span><?php echo wp_kses_post( $bsm_label ); ?></span>
							<?php if ( isset( $bsm_counts[ $bsm_key ] ) ) : ?>
								<span class="bsm-set-count" data-count-for="<?php echo esc_attr( $bsm_key ); ?>"><?php echo (int) $bsm_counts[ $bsm_key ]; ?></span>
							<?php endif; ?>
						</button>
					<?php endforeach; ?>
				</nav>

				<?php /* ── Panes ── */ ?>
				<div class="bsm-set-main">

					<?php /* ── AI engine ── */ ?>
					<section class="bsm-set-pane is-active" data-pane="engine">
						<h3>AI engine</h3>
						<p class="bsm-set-lede">
							Requests are relayed to Amazon Bedrock through the platform. No API key is stored in WordPress.
						</p>
						<label class="bsm-set-label" for="bsm-ai-webhook">Endpoint URL</label>
						<div class="bsm-set-inline">
							<input type="url" id="bsm-ai-webhook" value="<?php echo esc_url( $bsm_ai_webhook ); ?>"
									placeholder="Paste your platform endpoint URL"
									class="bsm-set-mono">
							<span class="bsm-set-pill"><span class="bsm-set-dot"></span>No API key required</span>
						</div>
						<p class="bsm-set-hint">
							Used by the <strong>AI — Nova Lite</strong> fill options in the Bulk Editor, the
							<strong>Use AI</strong> button under Test &amp; reprocess, and any AI option in Auto SEO Manager.
						</p>
						<p class="bsm-set-actions">
							<button type="button" class="button button-primary" id="bsm-ai-webhook-save">Save endpoint</button>
							<span id="bsm-ai-webhook-status" class="bsm-set-status"></span>
						</p>
					</section>

					<?php /* ── Defaults ── */ ?>
					<section class="bsm-set-pane" data-pane="defaults">
						<h3>Defaults</h3>
						<p class="bsm-set-lede">Applied whenever the Bulk Editor or Auto SEO Manager generates fields.</p>
						<label class="bsm-set-label" for="bsm-kp-count">Related keyphrases to generate</label>
						<select id="bsm-kp-count" class="bsm-set-narrow">
							<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
								<option value="<?php echo esc_attr( $i ); ?>" <?php selected( $bsm_kp_count, $i ); ?>><?php echo esc_html( $i ); ?></option>
							<?php endfor; ?>
						</select>
						<p class="bsm-set-hint">
							How many related keyphrases to add to Yoast on each generate — used by the Bulk Editor
							Related dropdown and the Auto SEO Manager.
						</p>
						<p class="bsm-set-actions">
							<button type="button" class="button button-primary" id="bsm-kp-count-save">Save defaults</button>
							<span id="bsm-kp-count-status" class="bsm-set-status"></span>
						</p>
					</section>

					<?php
					/* ── Template panes: one shared palette per pane, then the rows ── */
					$bsm_tpl_panes = array(
						'title' => array(
							'h'     => 'SEO title templates',
							'lede'  => 'Compose SEO titles from post data. Editors pick these by name in the Bulk Editor, and Auto SEO Manager can apply one automatically on publish.',
							'list'  => 'bsm-title-templates-list',
							'add'   => 'bsm-add-title-template',
							'items' => $title_templates,
							'opt'   => 'bsm_title_templates',
							'eg'    => '{title} {sep} {site}',
							'chips' => array(
								array( '{title}', 'Page title' ),
								array( '{title_short}', 'First 4 words of title' ),
								array( '{sep}', 'Separator (–)' ),
								array( '{site}', 'Site / brand name' ),
								array( '{category}', 'First category / term' ),
								array( '{type}', 'Post type label' ),
								array( '{parent}', 'Parent page title' ),
								array( '{slug}', 'URL slug as words' ),
							),
						),
						'desc'  => array(
							'h'     => 'Description templates',
							'lede'  => 'Compose meta descriptions from post data. These also populate the Meta Description Template dropdown in Auto SEO Manager.',
							'list'  => 'bsm-templates-list',
							'add'   => 'bsm-add-template',
							'items' => $templates,
							'opt'   => 'bsm_custom_templates',
							'eg'    => '{title} — Discover more at {site}.',
							'chips' => array(
								array( '{excerpt}', 'First 25 words of content' ),
								array( '{title}', 'Page title' ),
								array( '{keyphrase}', 'Yoast focus keyphrase' ),
								array( '{category}', 'First category / term' ),
								array( '{type}', 'Post type label' ),
								array( '{site}', 'Site name' ),
								array( '{sep}', 'Separator (–)' ),
							),
						),
						'kp'    => array(
							'h'     => 'Keyphrase templates',
							'lede'  => 'Compose short focus keyphrases from post data. Two or three words works best — Yoast scores long keyphrases poorly.',
							'list'  => 'bsm-kw-templates-list',
							'add'   => 'bsm-add-kw-template',
							'items' => $kw_templates,
							'opt'   => 'bsm_keyphrase_templates',
							'eg'    => '{parent} {title_short}',
							'chips' => array(
								array( '{title_short}', 'First 4 words of title' ),
								array( '{category}', 'First category / term' ),
								array( '{slug}', 'URL slug as words' ),
								array( '{parent}', 'Parent page title' ),
								array( '{type}', 'Post type label' ),
								array( '{title}', 'Page title' ),
								array( '{site}', 'Site / brand name' ),
							),
						),
					);

					foreach ( $bsm_tpl_panes as $bsm_key => $bsm_p ) :
						?>
						<section class="bsm-set-pane" data-pane="<?php echo esc_attr( $bsm_key ); ?>">
							<h3><?php echo esc_html( $bsm_p['h'] ); ?></h3>
							<p class="bsm-set-lede"><?php echo esc_html( $bsm_p['lede'] ); ?></p>

							<?php /* One shared palette — inserts into whichever template string was last focused. */ ?>
							<div class="bsm-set-palette">
								<div class="bsm-set-palette-head">
									<span class="bsm-set-eyebrow">Insert placeholder</span>
									<span class="bsm-set-palette-target" data-target-for="<?php echo esc_attr( $bsm_key ); ?>"></span>
								</div>
								<div class="bsm-set-chips">
									<?php foreach ( $bsm_p['chips'] as [ $bsm_ph, $bsm_desc ] ) : ?>
										<span class="bsm-ph-chip bsm-ph-builtin"
												data-ph="<?php echo esc_attr( $bsm_ph ); ?>"
												title="<?php echo esc_attr( $bsm_desc ); ?>">
											<code><?php echo esc_html( $bsm_ph ); ?></code>
											<span class="bsm-ph-desc"><?php echo esc_html( $bsm_desc ); ?></span>
										</span>
									<?php endforeach; ?>
								</div>
							</div>

							<div id="<?php echo esc_attr( $bsm_p['list'] ); ?>" class="bsm-set-tpl-list">
								<?php if ( empty( $bsm_p['items'] ) ) : ?>
									<p class="bsm-no-templates">No templates yet — click "Add template" to create your first one.</p>
								<?php else : ?>
									<?php foreach ( $bsm_p['items'] as $bsm_i => $bsm_tpl ) : ?>
										<?php bsm_template_row( $bsm_i, $bsm_tpl['label'], $bsm_tpl['template'], $bsm_p['opt'], $bsm_p['eg'] ); ?>
									<?php endforeach; ?>
								<?php endif; ?>
							</div>

							<p class="bsm-set-actions">
								<button type="button" class="button" id="<?php echo esc_attr( $bsm_p['add'] ); ?>">+ Add template</button>
							</p>
						</section>
					<?php endforeach; ?>

					<?php /* ── Text size (3.28.0) ── */ ?>
					<section class="bsm-set-pane" data-pane="textsize">
						<h3>Text size</h3>
						<p class="bsm-set-lede">
							Scales the text on the Bulk Editor, Auto SEO Manager and Settings screens. This is saved
							against your WordPress account, so it won't change what anyone else on the site sees.
						</p>

						<div class="bsm-set-sizes" role="radiogroup" aria-label="<?php esc_attr_e( 'Text size', 'lookit-seo-copilot' ); ?>">
							<?php
							$bsm_current_size = bsm_get_text_size();
							foreach ( bsm_text_sizes() as $bsm_sk => $bsm_sv ) :
								?>
								<button type="button"
										class="bsm-set-size<?php echo $bsm_sk === $bsm_current_size ? ' is-active' : ''; ?>"
										role="radio"
										aria-checked="<?php echo $bsm_sk === $bsm_current_size ? 'true' : 'false'; ?>"
										data-size="<?php echo esc_attr( $bsm_sk ); ?>"
										data-scale="<?php echo esc_attr( $bsm_sv['scale'] ); ?>">
									<span class="bsm-set-size-demo" style="font-size:calc(<?php echo esc_attr( $bsm_sv['scale'] ); ?> * 15px);">Aa</span>
									<span class="bsm-set-size-label"><?php echo esc_html( $bsm_sv['label'] ); ?></span>
									<span class="bsm-set-size-note"><?php echo esc_html( $bsm_sv['note'] ); ?></span>
								</button>
							<?php endforeach; ?>
						</div>

						<p class="bsm-set-hint" id="bsm-size-status">
							Changes apply straight away — no need to save.
						</p>
					</section>

					<?php /* ── Test & reprocess (moved here from Auto SEO Manager, v3.27.0) ── */ ?>
					<section class="bsm-set-pane" data-pane="test">
						<h3>Test &amp; reprocess</h3>
						<p class="bsm-set-lede">
							Run the Auto SEO engine against one existing post right now — useful for posts published
							before Auto SEO was enabled, or to refresh fields after editing content.
						</p>
						<label class="bsm-set-label" for="asy_reprocess_id">Post ID</label>
						<div class="bsm-set-inline">
							<input type="number" id="asy_reprocess_id" class="asy-or-input asy-or-input--sm bsm-set-narrow"
									placeholder="e.g. 27037" min="1">
							<button type="button" id="asy-reprocess-btn" class="button button-primary">Generate keyphrases</button>
							<button type="button" id="asy-ai-btn" class="button bsm-set-ai"
									title="<?php esc_attr_e( 'Generate focus keyphrase, related keyphrases, and meta description with Amazon Bedrock, and save them to Yoast', 'lookit-seo-copilot' ); ?>">
								&#10022; Use AI
							</button>
						</div>
						<p class="bsm-set-hint">
							<strong>Generate keyphrases</strong> uses Datamuse and the built-in sources — free, no endpoint needed.
							<strong>Use AI</strong> generates the focus keyphrase, related keyphrases and meta description with
							Amazon Bedrock and saves all three to Yoast. Both overwrite what Yoast currently holds for that post.
						</p>
						<div id="asy-reprocess-result" class="asy-reprocess-result" style="display:none;"></div>
					</section>

					<?php /* ── Custom fields ── */ ?>
					<section class="bsm-set-pane" data-pane="fields">
						<h3>Custom fields</h3>
						<p class="bsm-set-lede">
							Scans JetEngine, ACF, Meta Box, and your post meta to find available fields. Click any field
							to copy its placeholder, then paste it into a template.
						</p>
						<p class="bsm-set-actions">
							<button type="button" class="button" id="bsm-load-fields">Load fields</button>
						</p>
						<input type="search" id="bsm-field-search" placeholder="Search fields…" style="display:none;">
						<div id="bsm-field-browser">
							<p class="bsm-set-hint"><em>Click "Load fields" to scan your site.</em></p>
						</div>
						<div id="bsm-copy-toast">&#10003; Copied to clipboard!</div>
					</section>

					<?php /* ── How it works ── */ ?>
					<section class="bsm-set-pane" data-pane="help">
						<h3>How it works</h3>
						<ul class="bsm-set-help">
							<li><?php esc_html_e( 'Bulk Editor fills the Yoast focus keyphrase, meta description, and related keyphrases for the posts you choose; Auto SEO Manager does the same automatically when a post is published.', 'lookit-seo-copilot' ); ?></li>
							<li><?php esc_html_e( 'Keyphrase source: "Full title", "Slug", their first-3-words variants, "Top content word" (strongest phrase in the content), or AI — Nova Lite via the platform.', 'lookit-seo-copilot' ); ?></li>
							<li><?php esc_html_e( 'Related keyphrases: Off, Datamuse (free, no key), or AI — Nova Lite. Meta description: a saved template, AI — Nova Lite, or none.', 'lookit-seo-copilot' ); ?></li>
							<li><?php esc_html_e( 'Any AI option is generated with Amazon Bedrock through the platform (set the endpoint under AI engine). If the platform is unreachable, Auto SEO falls back to the post title so publishing never breaks.', 'lookit-seo-copilot' ); ?></li>
							<li><?php esc_html_e( 'Auto SEO overwrites the fields on each publish. Requires Yoast SEO (free or premium).', 'lookit-seo-copilot' ); ?></li>
						</ul>
					</section>

				</div>

				<?php /* ── Preview rail (template panes only) ── */ ?>
				<aside class="bsm-set-prev" id="bsm-set-prev" aria-live="polite">
					<div class="bsm-set-prev-head">
						<span class="bsm-set-eyebrow">Preview</span>
						<button type="button" class="bsm-set-link" id="bsm-change-sample">Change sample post</button>
					</div>

					<div class="bsm-set-sample">
						<p class="bsm-set-sample-t" id="bsm-sample-title">
							<?php echo $bsm_sample ? esc_html( $bsm_sample->post_title ) : esc_html__( 'No posts found', 'lookit-seo-copilot' ); ?>
						</p>
						<p class="bsm-set-sample-m">
							<span class="bsm-set-tag" id="bsm-sample-type">
							<?php
								echo $bsm_sample ? esc_html( get_post_type_object( $bsm_sample->post_type )->labels->singular_name ) : '—';
							?>
							</span>
							<span id="bsm-sample-slug">/<?php echo $bsm_sample ? esc_html( $bsm_sample->post_name ) : ''; ?></span>
							&middot; ID <span id="bsm-sample-id"><?php echo (int) $bsm_sample_id; ?></span>
						</p>
					</div>

					<div class="bsm-set-serp">
						<div class="bsm-set-serp-url" id="bsm-serp-url">
						<?php
							echo $bsm_sample ? esc_html( preg_replace( '#^https?://#', '', (string) untrailingslashit( get_permalink( $bsm_sample ) ) ) ) : '';
						?>
						</div>
						<div class="bsm-set-serp-title" id="bsm-serp-title">—</div>
						<div class="bsm-set-serp-desc" id="bsm-serp-desc">—</div>
					</div>
					<p class="bsm-set-hint">The highlighted line is the template you're editing.</p>

					<div id="bsm-set-meter"></div>

					<div class="bsm-set-kpbox">
						<span class="bsm-set-eyebrow">Focus keyphrase</span>
						<p id="bsm-prev-kp">—</p>
					</div>
				</aside>

			</div>

			<div class="bsm-set-savebar">
				<span id="bsm-set-dirty">All changes saved</span>
				<?php submit_button( 'Save templates', 'primary', 'submit', false ); ?>
			</div>
		</form>

		<?php /* ── Post picker ── */ ?>
		<div class="bsm-set-scrim" id="bsm-set-scrim" hidden>
			<div class="bsm-set-modal" role="dialog" aria-modal="true" aria-labelledby="bsm-picker-h">
				<div class="bsm-set-modal-head">
					<h3 id="bsm-picker-h">Choose a sample post</h3>
					<input type="search" id="bsm-picker-q" placeholder="Search by title, or paste an ID…" autocomplete="off">
					<div class="bsm-set-filters" id="bsm-picker-filters">
						<button type="button" class="bsm-set-filt is-active" data-type="all">All</button>
						<?php foreach ( bsm_picker_types() as $bsm_pt => $bsm_pt_label ) : ?>
							<button type="button" class="bsm-set-filt" data-type="<?php echo esc_attr( $bsm_pt ); ?>"><?php echo esc_html( $bsm_pt_label ); ?></button>
						<?php endforeach; ?>
					</div>
				</div>
				<div class="bsm-set-results" id="bsm-picker-results"></div>
				<div class="bsm-set-modal-foot">
					<span>Previewing does not change the post.</span>
					<button type="button" class="button" id="bsm-picker-close">Cancel</button>
				</div>
			</div>
		</div>
		<?php /* Template rows added via JS buildTemplateRow() */ ?>
	</div>


	<script>
	(function(){
		var NONCE   = <?php echo wp_json_encode( $ajax_nonce ); ?>;
		var AJAXURL = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

		// ── AI engine: save webhook endpoint ──
		(function(){
			var saveBtn = document.getElementById('bsm-ai-webhook-save');
			if (!saveBtn) return;
			saveBtn.addEventListener('click', function(){
				var input = document.getElementById('bsm-ai-webhook');
				var st    = document.getElementById('bsm-ai-webhook-status');
				var fd = new FormData();
				fd.append('action','bsm_save_ai_webhook');
				fd.append('nonce', NONCE);
				fd.append('url', input ? input.value : '');
				if (st){ st.style.display='inline'; st.style.color='#1a8fd1'; st.textContent='Saving…'; }
				fetch(AJAXURL,{method:'POST',body:fd,credentials:'same-origin'})
					.then(function(r){ return r.json(); })
					.then(function(resp){
						if (!st) return;
						if (resp && resp.success){ st.style.color='#1da462'; st.textContent='✓ Saved'; }
						else { st.style.color='#d63638'; st.textContent='⚠ '+((resp&&resp.data)?resp.data:'Save failed'); }
						setTimeout(function(){ st.style.display='none'; }, 2500);
					})
					.catch(function(){ if(st){ st.style.color='#d63638'; st.textContent='⚠ Network error'; } });
			});
		})();

		// ── Related keyphrase count: save ──
		(function(){
			var b = document.getElementById('bsm-kp-count-save');
			if (!b) return;
			b.addEventListener('click', function(){
				var sel = document.getElementById('bsm-kp-count');
				var st  = document.getElementById('bsm-kp-count-status');
				var fd = new FormData();
				fd.append('action','bsm_save_kp_count');
				fd.append('nonce', NONCE);
				fd.append('count', sel ? sel.value : '3');
				if (st){ st.style.display='inline'; st.style.color='#1a8fd1'; st.textContent='Saving…'; }
				fetch(AJAXURL,{method:'POST',body:fd,credentials:'same-origin'})
					.then(function(r){ return r.json(); })
					.then(function(resp){
						if (!st) return;
						if (resp && resp.success){ st.style.color='#1da462'; st.textContent='✓ Saved'; }
						else { st.style.color='#d63638'; st.textContent='⚠ '+((resp&&resp.data)?resp.data:'Save failed'); }
						setTimeout(function(){ st.style.display='none'; }, 2500);
					})
					.catch(function(){ if(st){ st.style.color='#d63638'; st.textContent='⚠ Network error'; } });
			});
		})();

		var activeTemplateInput = null;
		document.addEventListener('focusin', function(e){
			if ( e.target && e.target.matches('input[name*="[template]"]') ) {
				activeTemplateInput = e.target;
			}
		});

		// ── Copy placeholder to clipboard AND inject into focused template input ──
		function copyPlaceholder(ph) {
			navigator.clipboard?.writeText(ph).catch(function(){
				// Fallback for older browsers
				var ta = document.createElement('textarea');
				ta.value = ph; document.body.appendChild(ta); ta.select();
				document.execCommand('copy'); document.body.removeChild(ta);
			});
			// If a template input is focused, insert at cursor position
			if (activeTemplateInput) {
				var start = activeTemplateInput.selectionStart;
				var end   = activeTemplateInput.selectionEnd;
				var val   = activeTemplateInput.value;
				activeTemplateInput.value = val.slice(0,start) + ph + val.slice(end);
				activeTemplateInput.selectionStart = activeTemplateInput.selectionEnd = start + ph.length;
				activeTemplateInput.dispatchEvent(new Event('input'));
			}
			// Toast
			var toast = document.getElementById('bsm-copy-toast');
			if (toast) {
				toast.style.display = 'block';
				setTimeout(function(){ toast.style.display = 'none'; }, 1800);
			}
		}

		// ── Built-in placeholder chips ──
		document.querySelectorAll('.bsm-ph-chip').forEach(function(chip){
			chip.addEventListener('click', function(){
				copyPlaceholder(chip.getAttribute('data-ph'));
				chip.classList.add('copied');
				setTimeout(function(){ chip.classList.remove('copied'); }, 1200);
			});
		});

		// ── Load meta fields from server ──
		document.getElementById('bsm-load-fields')?.addEventListener('click', function(){
			var browser = document.getElementById('bsm-field-browser');
			var search  = document.getElementById('bsm-field-search');
			browser.innerHTML = '<p style="font-size:calc(12px * var(--bsm-fs, 1));color:#1a8fd1;">⏳ Scanning meta fields…</p>';

			var fd = new FormData();
			fd.append('action', 'bsm_get_meta_fields');
			fd.append('nonce',  NONCE);

			fetch(AJAXURL, {method:'POST', body:fd})
				.then(function(r){ return r.json(); })
				.then(function(resp){
					if (!resp.success) {
						browser.innerHTML = '<p style="color:#d63638;font-size:calc(12px * var(--bsm-fs, 1));">⚠ ' + resp.data + '</p>';
						return;
					}

					var data   = resp.data;
					var core   = data.core   || [];
					var fields = data.fields || [];
					search.style.display = 'block';

					// Group fields by source
					var groups = {};
					core.forEach(function(f)   { (groups['WordPress & Yoast'] = groups['WordPress & Yoast'] || []).push(f); });
					fields.forEach(function(f) { var s = f.source || 'Post Meta'; (groups[s] = groups[s] || []).push(f); });

					renderGroups(groups);

					// Live search within the panel
					search.addEventListener('input', function(){
						var term = search.value.toLowerCase();
						document.querySelectorAll('.bsm-field-chip').forEach(function(chip){
							var txt = chip.textContent.toLowerCase();
							chip.style.display = txt.includes(term) ? '' : 'none';
						});
						document.querySelectorAll('.bsm-field-group').forEach(function(grp){
							var visible = Array.from(grp.querySelectorAll('.bsm-field-chip')).some(function(c){ return c.style.display !== 'none'; });
							grp.style.display = visible ? '' : 'none';
						});
					});
				})
				.catch(function(){
					browser.innerHTML = '<p style="color:#d63638;font-size:calc(12px * var(--bsm-fs, 1));">⚠ Request failed</p>';
				});
		});

		function renderGroups(groups) {
			var browser = document.getElementById('bsm-field-browser');
			browser.innerHTML = '';
			Object.keys(groups).forEach(function(src){
				var wrap  = document.createElement('div');
				wrap.className = 'bsm-field-group';
				var label = document.createElement('div');
				label.className   = 'bsm-field-group-label';
				label.textContent = src + ' (' + groups[src].length + ')';
				wrap.appendChild(label);
				groups[src].forEach(function(f){
					var chip = document.createElement('div');
					chip.className = 'bsm-field-chip';
					chip.innerHTML =
						'<span class="bsm-field-chip-ph">' + escHtml(f.placeholder) + '</span>' +
						'<span class="bsm-field-chip-label">' + escHtml(f.label) + '</span>';
					chip.addEventListener('click', function(){
						copyPlaceholder(f.placeholder);
						chip.classList.add('copied');
						setTimeout(function(){ chip.classList.remove('copied'); }, 1200);
					});
					wrap.appendChild(chip);
				});
				browser.appendChild(wrap);
			});
		}

		function escHtml(s) {
			return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
		}

		// ── Reusable template-list controller (drives both Keyphrase and Description lists) ──
		function initTemplateList(cfg) {
			var list   = document.getElementById(cfg.listId);
			var addBtn = document.getElementById(cfg.addBtnId);
			if (!list || !addBtn) return;
			var option = cfg.optionName;
			var idx    = list.querySelectorAll('.bsm-tpl-row').length;

			// Build a new template row entirely in JS — no <template> element needed
			function buildTemplateRow(i, labelVal, templateVal) {
				var row = document.createElement('div');
				row.className = 'bsm-tpl-row';
				row.style.cssText = 'display:grid;grid-template-columns:24px 1fr auto;gap:10px;align-items:start;padding:12px 0;border-bottom:1px solid #f0f0f0;cursor:default;';

				var col0 = document.createElement('div');
				col0.className = 'bsm-drag-handle';
				col0.draggable = true;
				col0.title = 'Drag to reorder';
				col0.style.cssText = 'padding-top:18px;display:flex;flex-direction:column;align-items:center;gap:2px;cursor:grab;';
				col0.innerHTML = '<span style="font-size:calc(16px * var(--bsm-fs, 1));color:#c0c8d4;line-height:1;">&#10783;</span>' +
					'<button type="button" class="bsm-move-up" title="Move up" style="background:none;border:none;cursor:pointer;padding:1px;color:#8b96a8;font-size:calc(11px * var(--bsm-fs, 1));line-height:1;">&#9650;</button>' +
					'<button type="button" class="bsm-move-down" title="Move down" style="background:none;border:none;cursor:pointer;padding:1px;color:#8b96a8;font-size:calc(11px * var(--bsm-fs, 1));line-height:1;">&#9660;</button>';

				var col1 = document.createElement('div');
				var lbl1 = document.createElement('label');
				lbl1.style.cssText = 'font-size:calc(12px * var(--bsm-fs, 1));color:#646970;display:block;margin-bottom:4px;';
				lbl1.textContent = 'Template name';
				var inp1 = document.createElement('input');
				inp1.type        = 'text';
				inp1.name        = option + '[' + i + '][label]';
				inp1.value       = labelVal || '';
				inp1.placeholder = 'e.g. Default, Event, Blog post';
				inp1.style.cssText = 'width:100%;font-size:calc(13px * var(--bsm-fs, 1));padding:6px 9px;';
				col1.appendChild(lbl1);
				col1.appendChild(inp1);

				var col2 = document.createElement('div');
				var lbl2 = document.createElement('label');
				lbl2.style.cssText = 'font-size:calc(12px * var(--bsm-fs, 1));color:#646970;display:block;margin-bottom:4px;';
				lbl2.textContent = 'Template string';
				var inp2 = document.createElement('input');
				inp2.type        = 'text';
				inp2.name        = option + '[' + i + '][template]';
				inp2.value       = templateVal || '';
				inp2.placeholder = cfg.phExample;
				inp2.style.cssText = 'width:100%;font-size:calc(13px * var(--bsm-fs, 1));padding:6px 9px;font-family:ui-monospace,Menlo,Consolas,monospace;';
				col2.appendChild(lbl2);
				col2.appendChild(inp2);

				var col3 = document.createElement('div');
				col3.style.paddingTop = '18px';
				var removeBtn = document.createElement('button');
				removeBtn.type      = 'button';
				removeBtn.className = 'button bsm-remove-tpl';
				removeBtn.style.cssText = 'color:#d63638;border-color:#d63638;';
				removeBtn.textContent = 'Remove';
				col3.appendChild(removeBtn);

				var colMid = document.createElement('div');
				colMid.style.cssText = 'display:flex;flex-direction:column;gap:8px;min-width:0;';
				colMid.appendChild(col1);
				colMid.appendChild(col2);

				row.appendChild(col0);
				row.appendChild(colMid);
				row.appendChild(col3);
				return row;
			}

			// Reindex names sequentially so order is preserved on save
			function reindex() {
				list.querySelectorAll('.bsm-tpl-row').forEach(function(row, i) {
					var lbl = row.querySelector('input[name*="[label]"]');
					var tpl = row.querySelector('input[name*="[template]"]');
					if (lbl) lbl.name = option + '[' + i + '][label]';
					if (tpl) tpl.name = option + '[' + i + '][template]';
				});
			}
			document.getElementById('bsm-settings-form')?.addEventListener('submit', reindex);

			addBtn.addEventListener('click', function(){
				var noMsg = list.querySelector('.bsm-no-templates');
				if (noMsg) noMsg.remove();
				var newRow = buildTemplateRow(idx++, '', '');
				list.appendChild(newRow);
				newRow.querySelector('input[name*="[label]"]').focus();
			});

			list.addEventListener('click', function(e){
				if (e.target.classList.contains('bsm-remove-tpl')) {
					e.target.closest('.bsm-tpl-row').remove();
					if (!list.querySelector('.bsm-tpl-row')) {
						list.innerHTML = '<p class="bsm-no-templates" style="color:#999;font-size:calc(13px * var(--bsm-fs, 1));font-style:italic;">No templates yet — click "Add template" to create your first one.</p>';
					}
				}
				if (e.target.classList.contains('bsm-move-up')) {
					var row = e.target.closest('.bsm-tpl-row');
					var prev = row.previousElementSibling;
					if (prev && prev.classList.contains('bsm-tpl-row')) {
						list.insertBefore(row, prev);
						row.style.background = '#f0f7ff';
						setTimeout(function(){ row.style.background = ''; }, 400);
					}
				}
				if (e.target.classList.contains('bsm-move-down')) {
					var row = e.target.closest('.bsm-tpl-row');
					var next = row.nextElementSibling;
					if (next && next.classList.contains('bsm-tpl-row')) {
						list.insertBefore(next, row);
						row.style.background = '#f0f7ff';
						setTimeout(function(){ row.style.background = ''; }, 400);
					}
				}
			});

			// ── Drag-and-drop reordering (scoped to this list, grab by handle) ──
			var dragSrc = null;
			list.addEventListener('dragstart', function(e) {
				var handle = e.target.closest('.bsm-drag-handle');
				if (!handle) { e.preventDefault(); return; }
				dragSrc = handle.closest('.bsm-tpl-row');
				if (!dragSrc) { e.preventDefault(); return; }
				dragSrc.style.opacity = '0.5';
				e.dataTransfer.effectAllowed = 'move';
				e.dataTransfer.setData('text/plain', '');
			});
			list.addEventListener('dragend', function() {
				if (dragSrc) dragSrc.style.opacity = '';
				list.querySelectorAll('.bsm-tpl-row').forEach(function(r){ r.classList.remove('bsm-drag-over'); });
				dragSrc = null;
			});
			list.addEventListener('dragover', function(e) {
				e.preventDefault();
				e.dataTransfer.dropEffect = 'move';
				var row = e.target.closest('.bsm-tpl-row');
				list.querySelectorAll('.bsm-tpl-row').forEach(function(r){ r.classList.remove('bsm-drag-over'); });
				if (row && row !== dragSrc) row.classList.add('bsm-drag-over');
			});
			list.addEventListener('drop', function(e) {
				e.preventDefault();
				var target = e.target.closest('.bsm-tpl-row');
				if (!target || target === dragSrc || !dragSrc) return;
				var rect = target.getBoundingClientRect();
				var mid  = rect.top + rect.height / 2;
				if (e.clientY < mid) {
					list.insertBefore(dragSrc, target);
				} else {
					list.insertBefore(dragSrc, target.nextElementSibling);
				}
				list.querySelectorAll('.bsm-tpl-row').forEach(function(r){ r.classList.remove('bsm-drag-over'); });
			});
		}

		initTemplateList({ listId:'bsm-kw-templates-list', addBtnId:'bsm-add-kw-template', optionName:'bsm_keyphrase_templates', phExample:'{parent} {title_short}' });
		initTemplateList({ listId:'bsm-title-templates-list', addBtnId:'bsm-add-title-template', optionName:'bsm_title_templates', phExample:'{title} {sep} {site}' });
		initTemplateList({ listId:'bsm-templates-list',    addBtnId:'bsm-add-template',    optionName:'bsm_custom_templates',    phExample:'{title} — Discover more at {site}.' });

	})();
	</script>
	<?php
}

// Renders one editable template row (used both in PHP loop and as JS clone source)
function bsm_template_row( $i, string $label, string $template, string $option = 'bsm_custom_templates', string $ph_example = '{title} — Discover more at {site}.' ): void {

	?>
	<div class="bsm-tpl-row"
		style="display:grid;grid-template-columns:24px 1fr auto;gap:10px;align-items:start;padding:12px 0;border-bottom:1px solid #f0f0f0;cursor:default;">
		<div class="bsm-drag-handle" draggable="true" title="Drag to reorder"
			style="padding-top:18px;display:flex;flex-direction:column;align-items:center;gap:2px;cursor:grab;">
			<span style="font-size:calc(16px * var(--bsm-fs, 1));color:#c0c8d4;line-height:1;">&#10783;</span>
			<button type="button" class="bsm-move-up"   title="Move up"   style="background:none;border:none;cursor:pointer;padding:1px;color:#8b96a8;font-size:calc(11px * var(--bsm-fs, 1));line-height:1;">&#9650;</button>
			<button type="button" class="bsm-move-down" title="Move down" style="background:none;border:none;cursor:pointer;padding:1px;color:#8b96a8;font-size:calc(11px * var(--bsm-fs, 1));line-height:1;">&#9660;</button>
		</div>
		<div style="display:flex;flex-direction:column;gap:8px;min-width:0;">
			<div>
				<label style="font-size:calc(12px * var(--bsm-fs, 1));color:#646970;display:block;margin-bottom:4px;">Template name</label>
				<input type="text"
						name="<?php echo esc_attr( $option ); ?>[<?php echo esc_attr( $i ); ?>][label]"
						value="<?php echo esc_attr( $label ); ?>"
						placeholder="e.g. Default, Event, Blog post"
						style="width:100%;font-size:calc(13px * var(--bsm-fs, 1));padding:6px 9px;">
			</div>
			<div>
				<label style="font-size:calc(12px * var(--bsm-fs, 1));color:#646970;display:block;margin-bottom:4px;">Template string</label>
				<input type="text"
						name="<?php echo esc_attr( $option ); ?>[<?php echo esc_attr( $i ); ?>][template]"
						value="<?php echo esc_attr( $template ); ?>"
						placeholder="<?php echo esc_attr( $ph_example ); ?>"
						style="width:100%;font-size:calc(13px * var(--bsm-fs, 1));padding:6px 9px;font-family:ui-monospace,Menlo,Consolas,monospace;">
			</div>
		</div>
		<div style="padding-top:18px;">
			<button type="button" class="button bsm-remove-tpl" style="color:#d63638;border-color:#d63638;">Remove</button>
		</div>
	</div>
	<?php
}

// ─── AJAX: get description source data for a batch of post IDs ───────────────
// Returns title, excerpt (first sentence of content), keyphrase, category for each post

/**
 * Compute a Focus Keyphrase for each post from a chosen source, and return
 * an { id => keyphrase } map for staging into the New Keyphrase column.
 *
 * Sources reuse the Auto SEO engine — no external calls, no AI, no API key:
 *   title   -> post title verbatim
 *   slug    -> ASY_Processor::slug_to_keyphrase()
 *   topword -> ASY_Processor::get_top_content_word()  (top-scored bigram)
 *
 * This handler does NOT write to Yoast — the row Save (bsm_save_single) does.
 */
function bsm_ajax_fill_keyphrases() {
	check_ajax_referer( BSM_AJAX_NONCE, 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( 'Permission denied.' );
	}

	$ids = array_filter(
		array_map(
			'absint',
			explode( ',', sanitize_text_field( wp_unslash( $_POST['ids'] ?? '' ) ) )
		)
	);

	$source = sanitize_key( wp_unslash( $_POST['source'] ?? 'title' ) );
	if ( ! in_array( $source, array( 'title', 'slug', 'topword', 'match', 'cleantitle', 'category', 'titleword', 'titleslug' ), true ) ) {
		$source = 'title';
	}

	if ( empty( $ids ) ) {
		wp_send_json_error( 'No IDs.' );
	}

	$results = array();
	foreach ( $ids as $id ) {
		$post = get_post( $id );
		if ( ! $post ) {
			continue;
		}

		switch ( $source ) {
			case 'slug':
				$keyphrase = ASY_Processor::slug_to_keyphrase( $post->post_name );
				break;
			case 'topword':
				$keyphrase = ASY_Processor::get_top_content_word( $post );
				break;
			case 'match':
				$keyphrase = ASY_Processor::get_best_match_keyphrase( $post );
				break;
			case 'cleantitle':
				$keyphrase = ASY_Processor::get_clean_title_keyphrase( $post );
				break;
			case 'titleword':
				$keyphrase = ASY_Processor::get_title_best_word( $post );
				break;
			case 'titleslug':
				$keyphrase = ASY_Processor::get_title_slug_overlap( $post );
				break;
			case 'category':
				$keyphrase = ASY_Processor::get_primary_term( $post );
				break;
			default:
				$keyphrase = $post->post_title;
		}

		$results[ $id ] = sanitize_text_field( $keyphrase );
	}

	wp_send_json_success( $results );
}

/**
 * Save the AI platform webhook URL (option: bsm_ai_webhook_url).
 * This is a platform endpoint, NOT a credential — the plugin never holds
 * AWS keys. Auth/quota/metering live on the platform (n8n → Bedrock).
 */
function bsm_ajax_save_ai_webhook() {
	check_ajax_referer( BSM_AJAX_NONCE, 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Permission denied.' );
	}
	$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
	update_option( 'bsm_ai_webhook_url', $url );
	wp_send_json_success( array( 'url' => $url ) );
}

/**
 * Save the related-keyphrase count (shared by the Bulk Editor and Auto SEO).
 * Stored as asy_kp_count.
 */
function bsm_ajax_save_kp_count() {
	check_ajax_referer( BSM_AJAX_NONCE, 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Permission denied.' );
	}
	$count = max( 1, min( 5, absint( $_POST['count'] ?? 3 ) ) );
	update_option( 'asy_kp_count', $count );
	wp_send_json_success( array( 'count' => $count ) );
}

/**
 * AI fill — thin client. Posts each page's context to the platform webhook,
 * which calls AWS Bedrock (Nova Lite) and returns text. Mirrors the Lookit
 * Media Master pattern. No provider keys in WordPress.
 *
 * Request:  ids (csv), task ('keyphrase'|'metadesc'), count, kw[<id>] (optional)
 * Response: { results: { <id>: {primary,related}|{text} }, errors: {<id>:msg} }
 *
 * NOTE for Vadim (pre-production): the n8n webhook currently has no auth —
 * add a shared secret / Header Auth before exposing this for real. Also add
 * the == External Services == disclosure in readme.txt for this endpoint.
 */
function bsm_ajax_ai_fill() {
	check_ajax_referer( BSM_AJAX_NONCE, 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( 'Permission denied.' );
	}

	$webhook = trim( (string) get_option( 'bsm_ai_webhook_url', '' ) );
	if ( empty( $webhook ) ) {
		wp_send_json_error( 'AI engine not configured — set the webhook URL in the AI engine box above.' );
	}

	$task = sanitize_key( wp_unslash( $_POST['task'] ?? 'keyphrase' ) );
	if ( ! in_array( $task, array( 'keyphrase', 'metadesc', 'title' ), true ) ) {
		$task = 'keyphrase';
	}
	$count = max( 1, min( 6, absint( $_POST['count'] ?? 3 ) ) );

	$ids = array_filter(
		array_map(
			'absint',
			explode( ',', sanitize_text_field( wp_unslash( $_POST['ids'] ?? '' ) ) )
		)
	);
	if ( empty( $ids ) ) {
		wp_send_json_error( 'No IDs.' );
	}

	// Current keyphrases from the row inputs (so metadesc can include them).
	$row_kw = array();
	if ( isset( $_POST['kw'] ) && is_array( $_POST['kw'] ) ) {
		$kw_raw = array_map( 'sanitize_text_field', wp_unslash( $_POST['kw'] ) );
		foreach ( $kw_raw as $k => $v ) {
			$row_kw[ absint( $k ) ] = $v;
		}
	}

	$site    = array(
		'url'  => home_url(),
		'name' => get_bloginfo( 'name' ),
	);
	$results = array();
	$errors  = array();

	foreach ( $ids as $id ) {
		$post = get_post( $id );
		if ( ! $post ) {
			continue;
		}

		$type_obj   = get_post_type_object( $post->post_type );
		$type_label = $type_obj ? $type_obj->label : $post->post_type;

		$stripped = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $post->post_content ) ) );
		$excerpt  = $post->post_excerpt
			? wp_strip_all_tags( $post->post_excerpt )
			: wp_trim_words( $stripped, 40, '' );

		// First category / taxonomy term
		$category = '';
		$terms    = get_the_terms( $id, 'category' );
		if ( ! $terms || is_wp_error( $terms ) ) {
			foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
				$tt = get_the_terms( $id, $tax );
				if ( $tt && ! is_wp_error( $tt ) ) {
					$terms = $tt;
					break; }
			}
		}
		if ( $terms && ! is_wp_error( $terms ) ) {
			$category = $terms[0]->name;
		}

		$payload = array(
			'task'      => $task,
			'title'     => $post->post_title,
			'excerpt'   => $excerpt,
			'category'  => $category,
			'type'      => $type_label,
			'keyphrase' => isset( $row_kw[ $id ] ) ? $row_kw[ $id ] : '',
			'count'     => $count,
			'site'      => $site,
		);

		$resp = wp_remote_post(
			$webhook,
			array(
				'timeout' => 45,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $resp ) ) {
			$errors[ $id ] = $resp->get_error_message();
			continue;
		}
		$code = wp_remote_retrieve_response_code( $resp );
		$raw  = wp_remote_retrieve_body( $resp );
		if ( 200 !== $code ) {
			$errors[ $id ] = "HTTP {$code}";
			continue;
		}

		// n8n responds with {"text":"..."} — for keyphrase task the text is a
		// JSON array; for metadesc it's a plain string.
		$outer = json_decode( $raw, true );
		$text  = ( is_array( $outer ) && isset( $outer['text'] ) ) ? trim( $outer['text'] ) : trim( $raw );

		if ( 'keyphrase' === $task ) {
			$t   = trim( preg_replace( '/```(?:json)?/i', '', $text ) );
			$t   = trim( str_replace( '```', '', $t ) );
			$arr = json_decode( $t, true );
			if ( ! is_array( $arr ) && preg_match( '/\[.*\]/s', $t, $m ) ) {
				$arr = json_decode( $m[0], true );
			}
			if ( is_array( $arr ) && ! empty( $arr ) ) {
				$arr            = array_values( array_map( 'sanitize_text_field', array_filter( $arr ) ) );
				$results[ $id ] = array( 'primary' => $arr[0] );
			} else {
				// Model returned a bare phrase, not an array — use as-is.
				$results[ $id ] = array( 'primary' => sanitize_text_field( $text ) );
			}
		} else {
			$results[ $id ] = array( 'text' => sanitize_text_field( $text ) );
		}
	}

	if ( empty( $results ) ) {
		wp_send_json_error( $errors ? reset( $errors ) : 'No results returned.' );
	}
	wp_send_json_success(
		array(
			'results' => $results,
			'errors'  => $errors,
		)
	);
}

/**
 * Shared helper: call the platform webhook for one post + task.
 * Returns array of keyphrases (task 'keyphrase'), string (task 'metadesc'),
 * or WP_Error. No AWS keys touched — the platform holds them.
 */
function bsm_ai_call_webhook( $task, WP_Post $post, $count = 3, $keyphrase = '', $words = 0 ) {
	$webhook = trim( (string) get_option( 'bsm_ai_webhook_url', '' ) );
	if ( empty( $webhook ) ) {
		return new WP_Error( 'no_webhook', 'AI engine not configured — set the webhook URL in Settings.' );
	}

	$type_obj   = get_post_type_object( $post->post_type );
	$type_label = $type_obj ? $type_obj->label : $post->post_type;
	$stripped   = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $post->post_content ) ) );
	$excerpt    = $post->post_excerpt
		? wp_strip_all_tags( $post->post_excerpt )
		: wp_trim_words( $stripped, 40, '' );

	$category = '';
	$terms    = get_the_terms( $post->ID, 'category' );
	if ( ! $terms || is_wp_error( $terms ) ) {
		foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
			$tt = get_the_terms( $post->ID, $tax );
			if ( $tt && ! is_wp_error( $tt ) ) {
				$terms = $tt;
				break; }
		}
	}
	if ( $terms && ! is_wp_error( $terms ) ) {
		$category = $terms[0]->name;
	}

	$payload = array(
		'task'      => $task,
		'title'     => $post->post_title,
		'excerpt'   => $excerpt,
		'category'  => $category,
		'type'      => $type_label,
		'keyphrase' => $keyphrase,
		'count'     => max( 1, min( 6, (int) $count ) ),
		'words'     => max( 0, (int) $words ),
		'site'      => array(
			'url'  => home_url(),
			'name' => get_bloginfo( 'name' ),
		),
	);

	$resp = wp_remote_post(
		$webhook,
		array(
			'timeout' => 45,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $payload ),
		)
	);
	if ( is_wp_error( $resp ) ) {
		return $resp;
	}
	$code = (int) wp_remote_retrieve_response_code( $resp );
	if ( 200 !== $code ) {
		return new WP_Error( 'http', "Webhook HTTP {$code}" );
	}
	$raw   = wp_remote_retrieve_body( $resp );
	$outer = json_decode( $raw, true );
	$text  = ( is_array( $outer ) && isset( $outer['text'] ) ) ? trim( $outer['text'] ) : trim( $raw );

	if ( in_array( $task, array( 'keyphrase', 'subheadings', 'outline' ), true ) ) {
		$t   = trim( str_replace( '```', '', preg_replace( '/```(?:json)?/i', '', $text ) ) );
		$arr = json_decode( $t, true );
		if ( ! is_array( $arr ) && preg_match( '/\[.*\]/s', $t, $m ) ) {
			$arr = json_decode( $m[0], true );
		}
		if ( is_array( $arr ) && ! empty( $arr ) ) {
			return array_values( array_map( 'sanitize_text_field', array_filter( $arr ) ) );
		}
		return array( sanitize_text_field( $text ) );
	}
	// Full page content — return as-is (paragraph text), no length cap.
	if ( 'content' === $task ) {
		return sanitize_textarea_field( $text );
	}
	// metadesc: cap to the ideal max (BSM_DESC_HI) at a word boundary — matches
	// the truncation the other Bulk Editor fill options apply, so AI output can't
	// overshoot the character limit.
	$desc = sanitize_text_field( $text );
	if ( mb_strlen( $desc ) > BSM_DESC_HI ) {
		$cut = mb_substr( $desc, 0, BSM_DESC_HI );
		$sp  = mb_strrpos( $cut, ' ' );
		if ( false !== $sp && $sp > BSM_DESC_HI * 0.6 ) {
			$cut = mb_substr( $cut, 0, $sp );
		}
		$desc = rtrim( $cut, ' ,;:-—|' );
	}
	return $desc;
}

/**
 * Auto SEO Manager — "Use AI": generate focus keyphrase + related keyphrases
 * + meta description for one post via Bedrock, and write them to Yoast.
 * Parallels the Datamuse "Generate Keyphrases" (asy_reprocess_post) flow.
 */
function bsm_ajax_asy_ai_generate() {
	check_ajax_referer( 'asy_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Permission denied.' );
	}

	$post_id = absint( $_POST['post_id'] ?? 0 );
	$post    = $post_id ? get_post( $post_id ) : null;
	if ( ! $post ) {
		wp_send_json_error( 'Post not found.' );
	}

	// One primary + N related, where N is the Auto SEO "Keyphrases to generate" setting.
	$related_n = max( 1, min( 5, (int) get_option( 'asy_kp_count', 3 ) ) );

	$kp = bsm_ai_call_webhook( 'keyphrase', $post, $related_n + 1 );
	if ( is_wp_error( $kp ) ) {
		wp_send_json_error( $kp->get_error_message() );
	}
	$primary = $kp[0];
	$related = array_slice( $kp, 1, $related_n );

	$desc = bsm_ai_call_webhook( 'metadesc', $post, 1, $primary );
	if ( is_wp_error( $desc ) ) {
		wp_send_json_error( $desc->get_error_message() );
	}

	// Write to Yoast (same meta keys the rest of the plugin uses).
	update_post_meta( $post_id, '_yoast_wpseo_focuskw', sanitize_text_field( $primary ) );
	if ( ! empty( $related ) && class_exists( 'ASY_Keyphrase_Engine' ) ) {
		ASY_Keyphrase_Engine::save_related_keyphrases( $post_id, $related, $primary );
	}
	update_post_meta( $post_id, '_yoast_wpseo_metadesc', sanitize_text_field( $desc ) );
	update_post_meta( $post_id, '_asy_ai_status', 'done' );
	update_post_meta( $post_id, '_asy_processed', current_time( 'mysql' ) );

	wp_send_json_success(
		array(
			'primary'  => $primary,
			'related'  => $related,
			'metadesc' => $desc,
		)
	);
}
add_action( 'wp_ajax_asy_ai_generate', 'bsm_ajax_asy_ai_generate' );

/**
 * Bulk Editor — generate related keyphrases for the given posts and save them
 * to Yoast. Source is 'datamuse' (free) or 'ai' (Bedrock via platform). Count
 * comes from the Auto SEO "Keyphrases to generate" setting (asy_kp_count).
 */
function bsm_ajax_related_fill() {
	check_ajax_referer( BSM_AJAX_NONCE, 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( 'Permission denied.' );
	}
	$source = sanitize_key( wp_unslash( $_POST['source'] ?? '' ) );
	if ( ! in_array( $source, array( 'datamuse', 'ai' ), true ) ) {
		wp_send_json_error( 'Invalid source.' );
	}
	$ids = array_filter(
		array_map(
			'absint',
			explode( ',', sanitize_text_field( wp_unslash( $_POST['ids'] ?? '' ) ) )
		)
	);
	if ( empty( $ids ) ) {
		wp_send_json_error( 'No IDs.' );
	}

	$count = max( 1, min( 5, (int) get_option( 'asy_kp_count', 3 ) ) );

	// Current (possibly staged) keyphrase per row, so related terms never repeat it.
	$row_kw = array();
	if ( isset( $_POST['kw'] ) && is_array( $_POST['kw'] ) ) {
		$kw_raw = array_map( 'sanitize_text_field', wp_unslash( $_POST['kw'] ) );
		foreach ( $kw_raw as $k => $v ) {
			$row_kw[ absint( $k ) ] = $v;
		}
	}

	$results = array();
	$errors  = array();

	foreach ( $ids as $id ) {
		$post = get_post( $id );
		if ( ! $post ) {
			continue;
		}
		$related = array();

		if ( 'datamuse' === $source ) {
			if ( class_exists( 'ASY_Keyphrase_Engine' ) ) {
				$r = ASY_Keyphrase_Engine::get_related_keyphrases( $post, $count );
				if ( is_wp_error( $r ) ) {
					$errors[ $id ] = $r->get_error_message();
					continue; }
				$related = is_array( $r ) ? $r : array();
			}
		} else { // ai
			$list = bsm_ai_call_webhook( 'keyphrase', $post, $count + 1 );
			if ( is_wp_error( $list ) ) {
				$errors[ $id ] = $list->get_error_message();
				continue; }
			$related = array_slice( (array) $list, 1, $count );
		}

		if ( ! empty( $related ) && class_exists( 'ASY_Keyphrase_Engine' ) ) {
			$exclude = isset( $row_kw[ $id ] ) ? $row_kw[ $id ] : '';
			ASY_Keyphrase_Engine::save_related_keyphrases( $id, $related, $exclude );
			update_post_meta( $id, '_asy_or_keyphrases', implode( ', ', $related ) );
			// Report back only what was actually kept (focus/dupes removed).
			$saved   = get_post_meta( $id, '_yoast_wpseo_focuskeywords', true );
			$decoded = json_decode( (string) $saved, true );
			if ( is_array( $decoded ) ) {
				$results[ $id ] = array_values(
					array_filter(
						array_map(
							function ( $o ) {
								return isset( $o['keyword'] ) ? $o['keyword'] : '';
							},
							$decoded
						)
					)
				);
			} else {
				$results[ $id ] = array_values( $related );
			}
		}
	}

	if ( empty( $results ) ) {
		wp_send_json_error( $errors ? reset( $errors ) : 'No related keyphrases generated.' );
	}
	wp_send_json_success(
		array(
			'results' => $results,
			'errors'  => $errors,
		)
	);
}
add_action( 'wp_ajax_bsm_related_fill', 'bsm_ajax_related_fill' );

function bsm_ajax_get_desc_data() {
	check_ajax_referer( BSM_AJAX_NONCE, 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( 'Permission denied.' );
	}

	$ids = array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['ids'] ?? '' ) ) ) ) );
	if ( empty( $ids ) ) {
		wp_send_json_error( 'No IDs.' );
	}

	$site_name = get_bloginfo( 'name' );
	$results   = array();

	foreach ( $ids as $id ) {
		$post = get_post( $id );
		if ( ! $post ) {
			continue;
		}

		$type_obj   = get_post_type_object( $post->post_type );
		$type_label = $type_obj ? $type_obj->label : ucfirst( $post->post_type );

		// First sentence / ~156 chars of content
		$stripped = wp_strip_all_tags( $post->post_content );
		$stripped = preg_replace( '/\s+/', ' ', trim( $stripped ) );
		// Try to grab first sentence
		preg_match( '/^[^.!?]*[.!?]/', $stripped, $sentence_match );
		$first_sentence = isset( $sentence_match[0] ) ? trim( $sentence_match[0] ) : '';
		// Fallback: first 156 chars
		if ( mb_strlen( $first_sentence ) < 20 ) {
			$first_sentence = mb_strlen( $stripped ) > 160
				? mb_substr( $stripped, 0, 156 ) . '…'
				: $stripped;
		}

		// Excerpt (WP excerpt field, or auto-generated)
		$excerpt = $post->post_excerpt
			? wp_strip_all_tags( $post->post_excerpt )
			: wp_trim_words( $stripped, 25, '…' );

		// First category or taxonomy term
		$category = '';
		$terms    = get_the_terms( $id, 'category' );
		if ( ! $terms || is_wp_error( $terms ) ) {
			// Try first registered taxonomy for this post type
			$taxs = get_object_taxonomies( $post->post_type );
			foreach ( $taxs as $tax ) {
				$tt = get_the_terms( $id, $tax );
				if ( $tt && ! is_wp_error( $tt ) ) {
					$terms = $tt;
					break; }
			}
		}
		if ( $terms && ! is_wp_error( $terms ) ) {
			$category = $terms[0]->name;
		}

		// Collect all post meta for {meta:fieldname} placeholder resolution
		$all_meta  = get_post_meta( $id );
		$flat_meta = array();
		foreach ( (array) $all_meta as $mk => $mv ) {
			// Only include scalar, human-readable meta values
			$val = is_array( $mv ) ? $mv[0] : $mv;
			if ( is_string( $val ) && strlen( $val ) < 300 && '_' !== substr( $mk, 0, 1 ) ) {
				$flat_meta[ $mk ] = $val;
			}
		}

		// Parent page title (blank if none) and slug rendered as words — used by keyphrase templates
		$parent_title = $post->post_parent ? get_the_title( $post->post_parent ) : '';
		$slug_words   = ucwords( str_replace( '-', ' ', $post->post_name ) );
		// First 4 words of the title — a short, Yoast-friendly focus phrase
		$title_short = implode( ' ', array_slice( preg_split( '/\s+/', trim( $post->post_title ) ), 0, 4 ) );

		$results[ $id ] = array(
			'title'          => $post->post_title,
			'title_short'    => $title_short,
			'parent'         => $parent_title,
			'slug'           => $slug_words,
			'site'           => $site_name,
			'keyphrase'      => (string) get_post_meta( $id, BSM_META_KW, true ),
			'type'           => $type_label,
			'excerpt'        => $excerpt,
			'first_sentence' => $first_sentence,
			'category'       => $category,
			'content'        => mb_substr( $stripped, 0, 300 ),
			'meta'           => $flat_meta,
		);
	}

	wp_send_json_success( $results );
}

// ─── AJAX: save single row ────────────────────────────────────────────────────

function bsm_ajax_save_single() {
	check_ajax_referer( BSM_AJAX_NONCE, 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( 'Permission denied.' );
	}

	$post_id   = absint( $_POST['post_id'] ?? 0 );
	$keyphrase = sanitize_text_field( wp_unslash( $_POST['keyphrase'] ?? '' ) );
	$metadesc  = sanitize_textarea_field( wp_unslash( $_POST['metadesc'] ?? '' ) );

	if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( 'Invalid post or permission denied.' );
	}

	bsm_update_fields( $post_id, $keyphrase, $metadesc );
	wp_send_json_success( 'Saved.' );
}

/**
 * AJAX save-all — persists every staged row without a page reload.
 * Payload: keyphrases[<id>], metadescs[<id>]. Mirrors bsm_handle_save but
 * returns JSON instead of redirecting.
 */
function bsm_ajax_save_all() {
	check_ajax_referer( BSM_AJAX_NONCE, 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( 'Permission denied.' );
	}
	$kw = ( isset( $_POST['keyphrases'] ) && is_array( $_POST['keyphrases'] ) )
		? array_map( 'sanitize_text_field', wp_unslash( $_POST['keyphrases'] ) ) : array();
	$md = ( isset( $_POST['metadescs'] ) && is_array( $_POST['metadescs'] ) )
		? array_map( 'sanitize_textarea_field', wp_unslash( $_POST['metadescs'] ) ) : array();
	$ti = ( isset( $_POST['titles'] ) && is_array( $_POST['titles'] ) )
		? array_map( 'sanitize_text_field', wp_unslash( $_POST['titles'] ) ) : array();

	$ids   = array_unique( array_merge( array_keys( $kw ), array_keys( $md ), array_keys( $ti ) ) );
	$saved = 0;
	foreach ( $ids as $id ) {
		$pid = absint( $id );
		if ( ! $pid || ! current_user_can( 'edit_post', $pid ) ) {
			continue;
		}
		bsm_update_fields(
			$pid,
			isset( $kw[ $id ] ) ? $kw[ $id ] : '',
			isset( $md[ $id ] ) ? $md[ $id ] : '',
			isset( $ti[ $id ] ) ? $ti[ $id ] : ''
		);
		++$saved;
	}
	wp_send_json_success( array( 'saved' => $saved ) );
}

// ─── Bulk save handler ────────────────────────────────────────────────────────

function bsm_handle_save() {
	// Lightweight guard before the nonce check below; only compares a routing value, changes nothing.
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( ! isset( $_POST['bsm_action'] ) || 'save_all' !== sanitize_key( wp_unslash( $_POST['bsm_action'] ) ) ) {
		return;
	}
	if ( ! check_admin_referer( BSM_NONCE ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( 'Permission denied.' );
	}

	$keyphrases = isset( $_POST['bkm_keyphrases'] )
		? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['bkm_keyphrases'] ) )
		: array();
	$metadescs  = isset( $_POST['bkm_metadescs'] )
		? array_map( 'sanitize_textarea_field', (array) wp_unslash( $_POST['bkm_metadescs'] ) )
		: array();
	$titles     = isset( $_POST['bkm_titles'] )
		? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['bkm_titles'] ) )
		: array();
	$raw_ids    = sanitize_text_field( wp_unslash( $_POST['bkm_all_ids'] ?? '' ) );
	$page_ids   = array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) );

	if ( empty( $page_ids ) ) {
		$page_ids = array_unique(
			array_merge(
				array_map( 'absint', array_keys( $keyphrases ) ),
				array_map( 'absint', array_keys( $metadescs ) )
			)
		);
	}

	$saved  = 0;
	$errors = 0;
	foreach ( $page_ids as $pid ) {
		$pid = absint( $pid );
		$kw  = sanitize_text_field( wp_unslash( $keyphrases[ $pid ] ?? '' ) );
		$md  = sanitize_textarea_field( wp_unslash( $metadescs[ $pid ] ?? '' ) );
		$ti  = sanitize_text_field( wp_unslash( $titles[ $pid ] ?? '' ) );
		if ( $pid && current_user_can( 'edit_post', $pid ) ) {
			bsm_update_fields( $pid, $kw, $md, $ti );
			++$saved;
		} else {
			++$errors; }
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'        => 'lookit-bulk-seo',
				'saved'       => $saved,
				'errors'      => $errors,
				'bkm_type'    => sanitize_key( $_POST['bkm_filter_type'] ?? 'all' ),
				'kw_status'   => sanitize_key( $_POST['bkm_filter_status'] ?? 'all' ),
				'desc_status' => sanitize_key( $_POST['bkm_filter_desc'] ?? 'all' ),
				'paged'       => absint( $_POST['bkm_paged'] ?? 1 ),
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

// ─── Write meta + sync Yoast indexables ──────────────────────────────────────

function bsm_update_fields( int $post_id, string $keyphrase, string $metadesc, string $title = '' ): void {
	if ( '' !== $keyphrase ) {
		update_post_meta( $post_id, BSM_META_KW, $keyphrase );
	}
	if ( '' !== $metadesc ) {
		update_post_meta( $post_id, BSM_META_DESC, $metadesc );
	}
	if ( '' !== $title ) {
		update_post_meta( $post_id, BSM_META_TITLE, $title );
	}
	if ( ( '' !== $keyphrase || '' !== $metadesc || '' !== $title ) && class_exists( '\Yoast\WP\SEO\Models\Indexable' ) ) {
		try {
			$repo = YoastSEO()->classes->get( \Yoast\WP\SEO\Repositories\Indexable_Repository::class );
			if ( $repo ) {
				$idx = $repo->find_by_id_and_type( $post_id, 'post', false );
				if ( $idx ) {
					if ( '' !== $keyphrase ) {
						$idx->primary_focus_keyword = $keyphrase;
					}
					if ( '' !== $metadesc ) {
						$idx->description = $metadesc;
					}
					if ( '' !== $title ) {
						$idx->title = $title;
					}
					$idx->save();
				}
			}
		} catch ( \Exception $e ) {
			unset( $e );
		}
	}
}

// ─── Topbar ──────────────────────────────────────────────────────────────────

function bsm_topbar(): void {
	$jet = defined( 'JET_ENGINE_VERSION' ) || class_exists( 'Jet_Engine' );
	?>
	<div class="bsm-topbar">
		<div class="bsm-topbar-logo">L</div>
		<div>
			<span class="bsm-topbar-title">Lookit SEO Copilot</span>
			<span class="bsm-topbar-sub">by <a href="https://lookitai.com" target="_blank" style="color:#1a8fd1;text-decoration:none;">Lookit Design</a></span>
		</div>
		<span class="bsm-badge bsm-badge-yoast">Yoast SEO</span>
		<?php
		if ( $jet ) :
			?>
			<span class="bsm-badge bsm-badge-jet">JetEngine</span><?php endif; ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=lookit-bulk-seo-settings' ) ); ?>"
			style="margin-left:auto;font-size:calc(12px * var(--bsm-fs, 1));color:#8b96a8;text-decoration:none;">⚙ Settings</a>
		<span style="font-size:calc(11px * var(--bsm-fs, 1));color:#4e5a6e;">v<?php echo esc_html( BSM_VERSION ); ?></span>
	</div>
	<?php
}

// ─── Tab navigation (shared across Bulk Editor / Auto SEO / Settings) ────────

function bsm_render_tabs( string $active ): void {
	$base = admin_url( 'admin.php?page=lookit-bulk-seo' );
	$tabs = array( 'bulk' => 'Bulk Editor' );
	if ( current_user_can( 'manage_options' ) ) {
		$tabs['auto'] = 'Auto SEO Manager';
	}
	$tabs['health'] = 'SEO Health';
	if ( current_user_can( 'manage_options' ) ) {
		$tabs['settings'] = 'Settings';
	}
	?>
	<div class="bsm-tabs">
		<?php
		foreach ( $tabs as $slug => $label ) :
			$url = 'bulk' === $slug ? $base : add_query_arg( 'tab', $slug, $base );
			?>
			<a href="<?php echo esc_url( $url ); ?>"
				class="bsm-tab<?php echo $active === $slug ? ' bsm-tab-active' : ''; ?>">
				<?php echo esc_html( $label ); ?>
			</a>
		<?php endforeach; ?>
	</div>
	<?php
}

// ─── Assets ──────────────────────────────────────────────────────────────────

function bsm_enqueue_assets( $hook ): void {
	// Auto SEO lock metabox lives on the post editor — load its CSS there.
	if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		wp_enqueue_style( 'lookit-bsm-admin', ASY_PLUGIN_URL . 'assets/admin.css', array(), ASY_VERSION );
		return;
	}

	if ( false === strpos( (string) $hook, 'lookit-bulk-seo' ) ) {
		return;
	}
	wp_enqueue_style( 'google-dm-sans', 'https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&display=swap', array(), BSM_VERSION );

	// Settings tab only: sidebar layout, preview rail and post picker (3.27.0).
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch, no state change.
	$bsm_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
	if ( 'settings' === $bsm_tab || false !== strpos( (string) $hook, 'lookit-bulk-seo-settings' ) ) {
		// Delivered inline rather than as a separate file request. On installs
		// behind an aggressive CDN or WAF, a stale assets/settings.css was being
		// served and the Settings page rendered at the old, larger type scale.
		// Inline CSS travels with the page and cannot go stale. Falls back to the
		// enqueued file if the asset can't be read for any reason.
		$bsm_set_css_path = ASY_PLUGIN_DIR . 'assets/settings.css';
		$bsm_set_css      = is_readable( $bsm_set_css_path ) ? file_get_contents( $bsm_set_css_path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin asset, inlined deliberately.
		if ( '' !== $bsm_set_css ) {
			wp_add_inline_style( 'wp-admin', $bsm_set_css );
		} else {
			wp_enqueue_style( 'bsm-settings', ASY_PLUGIN_URL . 'assets/settings.css', array(), BSM_VERSION );
		}

		wp_enqueue_script( 'bsm-settings', ASY_PLUGIN_URL . 'assets/settings.js', array(), BSM_VERSION, true );
		wp_localize_script(
			'bsm-settings',
			'BSM_SET',
			array(
				'ajax_url'  => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( BSM_AJAX_NONCE ),
				'sample_id' => (int) get_user_meta( get_current_user_id(), 'bsm_preview_post_id', true ),
			)
		);
	}

	// SEO Health tab: dedicated stylesheet + behaviours (enqueued, no inline).
	if ( 'health' === $bsm_tab ) {
		wp_enqueue_style( 'bsm-health', ASY_PLUGIN_URL . 'assets/health.css', array(), BSM_VERSION );
		wp_enqueue_script( 'bsm-health', ASY_PLUGIN_URL . 'assets/health.js', array(), BSM_VERSION, true );
		wp_localize_script(
			'bsm-health',
			'BSM_HEALTH',
			array(
				'ajax_url'   => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'bsm_health_suggest' ),
				'save_nonce' => wp_create_nonce( 'bsm_health_save' ),
			)
		);
	}

	$css = '
        #bsm-wrap * { box-sizing:border-box; }
        #bsm-wrap { font-family:"DM Sans",system-ui,sans-serif; max-width:none; color:#1c2333; }
        .bsm-topbar { display:flex;align-items:center;gap:12px;background:#0d1117;border-radius:10px;padding:14px 20px;margin-bottom:20px; }
        .bsm-topbar-logo { width:32px;height:32px;border-radius:8px;background:#1a8fd1;display:flex;align-items:center;justify-content:center;font-size:calc(16px * var(--bsm-fs, 1));font-weight:700;color:#fff;flex-shrink:0; }
        .bsm-topbar-title { font-size:calc(17px * var(--bsm-fs, 1));font-weight:600;color:#e8edf4; }
        .bsm-topbar-sub   { font-size:calc(12px * var(--bsm-fs, 1));color:#8b96a8;margin-left:4px; }
        .bsm-badge { font-size:calc(11px * var(--bsm-fs, 1));font-weight:600;padding:2px 8px;border-radius:4px;letter-spacing:.03em; }
        .bsm-badge-yoast { background:#a4286a;color:#fff; }
        .bsm-badge-jet   { background:#2271b1;color:#fff; }
        .bsm-filters { display:flex;gap:10px;flex-wrap:wrap;align-items:center; }
        .bsm-filters select,
        .bsm-filters input[type="search"],
        .bsm-filters .button { height:36px;box-sizing:border-box;font-size:calc(13px * var(--bsm-fs, 1)); }
        .bsm-filters select { flex:0 0 auto;min-width:160px;padding:0 30px 0 10px; }
        .bsm-filters input[type="search"] { min-width:160px;padding:0 10px; }
        .bsm-filters .button { line-height:34px;padding-top:0;padding-bottom:0; }
        /* ── Control panel (top of table): filters + fill controls + Save All ── */
        .bsm-fill-bar { display:flex;flex-direction:column;gap:12px;margin-bottom:12px;background:#f0f7ff;border:1px solid #b3d4f0;border-radius:8px;padding:14px 16px; }
        .bsm-panel-top { display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;padding-bottom:12px;border-bottom:1px solid #cfe3f7; }
        .bsm-panel-actions { display:flex;align-items:center;gap:12px;flex-shrink:0;margin-left:auto; }
        .bsm-switcher { display:flex;align-items:center;gap:16px;flex-wrap:wrap; }
        .bsm-fill-tabs { display:inline-flex;border:1px solid #b3d4f0;border-radius:6px;overflow:hidden;background:#fff;flex-shrink:0;height:40px; }
        .bsm-tab { display:inline-flex;align-items:center;height:100%;box-sizing:border-box;border:none;border-right:1px solid #cfe3f7;background:transparent;padding:0 22px;font-size:calc(14px * var(--bsm-fs, 1));line-height:1;cursor:pointer;color:#1c2333; }
        .bsm-tab:last-child { border-right:none; }
        .bsm-tab:hover { background:#eaf2fb; }
        .bsm-tab.-active { background:#2271b1;color:#fff;font-weight:600; }
        .bsm-target { display:flex;align-items:center;gap:12px;flex-wrap:wrap;flex:1 1 auto;min-width:0; }
        .bsm-target[hidden] { display:none; }
        .bsm-target select { height:40px;box-sizing:border-box;min-width:300px;flex:1 1 300px;max-width:520px;font-size:calc(13px * var(--bsm-fs, 1));padding:0 30px 0 10px;border-radius:5px;border:1px solid #b3d4f0;background:#fff; }
        .bsm-switcher .bsm-btn { height:40px;box-sizing:border-box; }
        .bsm-switcher .button { height:40px;box-sizing:border-box;line-height:38px;padding-top:0;padding-bottom:0;flex-shrink:0; }
        .bsm-fill-footer { display:flex;align-items:center;justify-content:flex-end;gap:12px;border-top:1px solid #cfe3f7;padding-top:12px; }
        .bsm-btn { font-size:calc(12px * var(--bsm-fs, 1));padding:6px 14px;border-radius:5px;cursor:pointer;font-weight:500;white-space:nowrap;display:inline-flex;align-items:center;justify-content:center;gap:5px;border:none; }
        .bsm-btn-green { background:#1da462;color:#fff; }
        .bsm-btn-green:hover { background:#178a52; }
        .bsm-btn-selected { background:#1470a8;color:#fff; }
        .bsm-btn-selected:hover { background:#115a86; }
        /* ── Table ── */
        #bsm-wrap table.bsm-table { border-collapse:collapse;width:100%; }
        #bsm-wrap table.bsm-table th { background:#0d1117;color:#8b96a8;font-size:calc(11px * var(--bsm-fs, 1));text-transform:uppercase;letter-spacing:.06em;font-weight:600;padding:10px;border:1px solid #222d40;white-space:nowrap; }
        #bsm-wrap table.bsm-table td { padding:10px;border:1px solid #dcdcde;font-size:calc(13px * var(--bsm-fs, 1));vertical-align:top; }
        #bsm-wrap table.bsm-table tr:hover td { background:#f9fafc; }
        .bsm-type-pill { display:inline-block;font-size:calc(11px * var(--bsm-fs, 1));padding:2px 7px;border-radius:3px;background:#f0f0f1;color:#50575e;border:1px solid #dcdcde;white-space:nowrap; }
        .bsm-status-pill { display:inline-block;margin-left:6px;font-size:calc(10px * var(--bsm-fs, 1));font-weight:600;text-transform:uppercase;letter-spacing:.04em;padding:1px 6px;border-radius:3px;vertical-align:middle;white-space:nowrap; }
        .bsm-status-draft   { background:#fff4e0;color:#b26a00;border:1px solid #f0c88a; }
        .bsm-status-pending { background:#fff4e0;color:#b26a00;border:1px solid #f0c88a; }
        .bsm-status-future  { background:#e7f0ff;color:#1470a8;border:1px solid #b3d4f0; }
        .bsm-status-private { background:#f0f0f1;color:#50575e;border:1px solid #dcdcde; }
        .bsm-kw-input  { width:100%;font-size:calc(13px * var(--bsm-fs, 1)); }
        .bsm-desc-ta   { width:100%;font-size:calc(12px * var(--bsm-fs, 1));resize:vertical; }
        .bsm-current   { font-size:calc(12px * var(--bsm-fs, 1));color:#444;line-height:1.4; }
        .bsm-cc        { font-size:calc(11px * var(--bsm-fs, 1));margin-top:3px; }
        .bsm-cc-ok     { color:#1da462; }
        .bsm-cc-warn   { color:#d63638; }
        .bsm-cc-info   { color:#888; }
        /* ── Buttons ── */
        .bsm-btn-save  { background:transparent;border:1px solid #a4286a !important;color:#a4286a;padding:5px 10px;border-radius:4px;cursor:pointer;font-size:calc(12px * var(--bsm-fs, 1));font-weight:500;white-space:nowrap; }
        .bsm-btn-save:hover { background:#a4286a;color:#fff; }
        .bsm-btn-save.saved { background:#1da462;border-color:#1da462 !important;color:#fff; }
        .bsm-fill-row  { margin-top:5px;font-size:calc(11px * var(--bsm-fs, 1));padding:4px 10px;border:1px solid #1da462;background:#fff;color:#1da462;border-radius:4px;cursor:pointer;font-weight:500;white-space:nowrap; }
        .bsm-fill-row:hover { background:#1da462;color:#fff; }
        /* ── Related keyphrase pills (surfaced under the keyphrase input on AI fill) ── */
        .bsm-related-pills  { margin-top:6px;display:flex;flex-wrap:wrap;gap:4px;align-items:center; }
        .bsm-related-label  { font-size:calc(10px * var(--bsm-fs, 1));font-weight:600;color:#028673;text-transform:uppercase;letter-spacing:.04em;width:100%; }
        .bsm-related-pill   { font-size:calc(11px * var(--bsm-fs, 1));background:rgba(2,134,115,.08);border:1px solid rgba(2,134,115,.35);color:#028673;border-radius:20px;padding:2px 8px;white-space:nowrap; }
        /* Bottom-align the per-row Fill buttons so they line up across the row */
        #bsm-wrap table.bsm-table td.bsm-fill-cell { height:1px; }

        /* ── Auto SEO Manager table (v3.29.0) ──
           Delivered inline, like the Bulk Editor table, so a stylesheet handle
           collision or a stale cached admin.css cannot break the layout. The
           display rules are defensive: an older admin.css on some installs
           still declares .asy-row{display:grid}, which would otherwise turn
           these table rows back into a wrapping grid. */
        #bsm-wrap .bsm-auto-tablewrap { overflow-x:auto; margin-bottom:14px; }
        #bsm-wrap table.bsm-auto-table { min-width:900px; table-layout:fixed; }
        #bsm-wrap table.bsm-auto-table thead tr,
        #bsm-wrap table.bsm-auto-table tbody tr.asy-row { display:table-row !important; }
        #bsm-wrap table.bsm-auto-table td,
        #bsm-wrap table.bsm-auto-table th { display:table-cell !important; vertical-align:middle; }
        #bsm-wrap table.bsm-auto-table th.bsm-auto-th-toggle { width:74px; }
        #bsm-wrap table.bsm-auto-table col.bsm-auto-c-pt { width:150px; }
        #bsm-wrap table.bsm-auto-table td.asy-col-pt { width:150px; }
        #bsm-wrap table.bsm-auto-table select,
        #bsm-wrap table.bsm-auto-table .asy-select {
            width:100%; box-sizing:border-box; max-width:none;
            font-size:calc(13px * var(--bsm-fs, 1)); padding:6px 10px;
            border:1px solid #c3c4c7; border-radius:4px; background:#fff; color:#1d2327;
        }
        #bsm-wrap table.bsm-auto-table tr.asy-row--active td { background:#fafeff; }
        #bsm-wrap .bsm-auto-save { margin:0 0 8px; }
        /* Post type name/slug stack inside its cell. */
        #bsm-wrap table.bsm-auto-table .asy-pt-label { display:block; font-weight:500; }
        #bsm-wrap table.bsm-auto-table .asy-pt-slug  { display:block; font-family:ui-monospace,Menlo,monospace;
            font-size:calc(11px * var(--bsm-fs, 1)); color:#8c8f94; }
        .bsm-cell-fill { display:flex;flex-direction:column;height:100%;align-items:flex-start; }
        .bsm-cell-fill .bsm-new-kw,
        .bsm-cell-fill .bsm-new-title,
        .bsm-cell-fill .bsm-new-desc { width:100%; }
        .bsm-cell-fill .bsm-fill-row { margin-top:auto; }
        .bsm-bottom-bar { display:flex;align-items:center;justify-content:space-between;margin-top:16px;flex-wrap:wrap;gap:10px; }
        .bsm-cpt-count  { font-size:calc(12px * var(--bsm-fs, 1));color:#646970;margin-bottom:12px; }
        /* ── Preview pill ── */
        .bsm-preview-pill { font-size:calc(11px * var(--bsm-fs, 1));background:#fff8e1;border:1px solid #f0c040;color:#7a5c00;border-radius:4px;padding:3px 7px;margin-top:4px;display:none;line-height:1.4; }
        /* ── Drag reorder ── */
        .bsm-tpl-row.bsm-drag-over { outline:2px dashed #1a8fd1;outline-offset:-2px;background:#eef6ff; }
        .bsm-drag-handle:hover span { color:#1a8fd1 !important; }
        .bsm-move-up:hover,.bsm-move-down:hover { color:#1a8fd1 !important; }
        /* ── Tab nav ── */
        .bsm-tabs { display:flex;gap:2px;margin-bottom:22px;border-bottom:2px solid #e2e6ee; }
        .bsm-tab { padding:9px 18px;font-size:calc(13px * var(--bsm-fs, 1));font-weight:600;color:#646970;text-decoration:none;border:1px solid transparent;border-bottom:none;border-radius:8px 8px 0 0;margin-bottom:-2px;transition:color .12s,background .12s; }
        .bsm-tab:hover { color:#1a8fd1;background:#f4f8fc; }
        .bsm-tab-active { color:#0d1117;background:#fff;border-color:#e2e6ee;border-bottom:2px solid #fff; }
        /* Auto SEO section sits inside the Bulk wrap — drop its own page chrome */
        #bsm-wrap .asy-wrap.asy-embedded { max-width:none;margin:0; }
        #bsm-wrap .asy-wrap.asy-embedded .asy-header { margin-top:0; }
    ';
	wp_add_inline_style( 'wp-admin', $css );

	// Text size preference — applies to Bulk Editor, Auto SEO Manager and Settings.
	wp_add_inline_style( 'wp-admin', ':root{--bsm-fs:' . esc_attr( bsm_get_text_scale() ) . ';}' );

	// On the Auto SEO Manager tab, load that engine's admin assets.
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch, no state change.
	$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'bulk';
	if ( 'auto' === $tab && current_user_can( 'manage_options' ) ) {
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
	}
}

// ─── Main page ───────────────────────────────────────────────────────────────

/**
 * Return post IDs whose Yoast focus keyphrase is shared by 2+ posts
 * (case-insensitive, trimmed), scoped to the given post types. Used by the
 * "Duplicated keyphrases" filter so collisions can be found and fixed.
 *
 * @param array $post_types Post type slugs to scan.
 * @return int[] Post IDs with a duplicated keyphrase.
 */
function bsm_duplicate_keyphrase_post_ids( array $post_types ) {
	global $wpdb;
	if ( empty( $post_types ) ) {
		return array();
	}
	$type_set = array_flip( $post_types );

	// Direct query: cross-post duplicate detection isn't expressible via meta_query.
	// Opt-in admin screen; post types are filtered in PHP below to avoid a dynamic IN clause.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT pm.post_id AS id, pm.meta_value AS kw, p.post_type AS ptype
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE pm.meta_key = %s
           AND pm.meta_value <> ''
           AND p.post_status NOT IN ('trash', 'auto-draft')",
			BSM_META_KW
		)
	);

	$by_kw = array();
	foreach ( (array) $rows as $r ) {
		if ( ! isset( $type_set[ $r->ptype ] ) ) {
			continue;
		}
		$key             = mb_strtolower( trim( (string) $r->kw ) );
		$by_kw[ $key ][] = (int) $r->id;
	}

	$ids = array();
	foreach ( $by_kw as $group ) {
		if ( count( $group ) > 1 ) {
			$ids = array_merge( $ids, $group );
		}
	}
	return array_values( array_unique( array_map( 'absint', $ids ) ) );
}

function bsm_render_page(): void {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( 'Permission denied.' );
	}

	// Route to the requested tab.
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab routing, no state change.
	$tab = sanitize_key( $_GET['tab'] ?? 'bulk' );
	if ( 'auto' === $tab && current_user_can( 'manage_options' ) ) {
		bsm_render_auto_section();
		return; }
	if ( 'health' === $tab ) {
		BSM_Health::render();
		return; }
	if ( 'settings' === $tab && current_user_can( 'manage_options' ) ) {
		bsm_render_settings();
		return; }

	$all_types = bsm_get_post_types();
	// These $_GET reads only drive display filtering/pagination of an admin list — they perform
	// no state changes, so a nonce is not required here. Each value is sanitized on read.
    // phpcs:disable WordPress.Security.NonceVerification.Recommended
	$ftype   = sanitize_key( $_GET['bkm_type'] ?? 'all' );
	$fstatus = sanitize_key( $_GET['kw_status'] ?? 'all' );
	$fdesc   = sanitize_key( $_GET['desc_status'] ?? 'all' );
	// Publish state filter. Defaults to live (published) posts; drafts etc. are opt-in.
	$fpost        = sanitize_key( $_GET['post_status'] ?? 'publish' );
	$bsm_statuses = array( 'publish', 'draft', 'pending', 'future', 'private', 'any' );
	if ( ! in_array( $fpost, $bsm_statuses, true ) ) {
		$fpost = 'publish';
	}
	$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
	$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
	$saved  = absint( $_GET['saved'] ?? 0 );
	$errors = absint( $_GET['errors'] ?? 0 );
    // phpcs:enable WordPress.Security.NonceVerification.Recommended
	$templates       = bsm_get_templates();
	$kw_templates    = bsm_get_keyphrase_templates();
	$title_templates = bsm_get_title_templates();

	if ( 'all' !== $ftype && ! isset( $all_types[ $ftype ] ) ) {
		$ftype = 'all';
	}

	$qargs = array(
		'post_type'      => 'all' === $ftype ? array_keys( $all_types ) : array( $ftype ),
		'post_status'    => $fpost,
		'posts_per_page' => BSM_PER_PAGE,
		'paged'          => $paged,
		'orderby'        => 'type title',
		'order'          => 'ASC',
	);
	if ( $search ) {
		$qargs['s'] = $search;
	}
	// Filtering an admin list by whether Yoast keyphrase/meta-description values exist requires
	// meta_query. This is an opt-in admin screen, not a front-end query, so the cost is acceptable.
    // phpcs:disable WordPress.DB.SlowDBQuery
	if ( 'set' === $fstatus ) {
		$qargs['meta_query'] = array(
			array(
				'key'     => BSM_META_KW,
				'value'   => '',
				'compare' => '!=',
			),
		);
	} elseif ( 'empty' === $fstatus ) {
		$qargs['meta_query'] = array(
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
		);
	} elseif ( 'duplicate' === $fstatus ) {
		$dup_ids           = bsm_duplicate_keyphrase_post_ids(
			'all' === $ftype ? array_keys( $all_types ) : array( $ftype )
		);
		$qargs['post__in'] = ! empty( $dup_ids ) ? $dup_ids : array( 0 );
		// Group duplicates together so matching keyphrases sit next to each other.
		$qargs['meta_key'] = BSM_META_KW;
		$qargs['orderby']  = 'meta_value title';
	}
	if ( 'set' === $fdesc ) {
		$desc_clause = array(
			array(
				'key'     => BSM_META_DESC,
				'value'   => '',
				'compare' => '!=',
			),
		);
	} elseif ( 'empty' === $fdesc ) {
		$desc_clause = array(
			'relation' => 'OR',
			array(
				'key'     => BSM_META_DESC,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => BSM_META_DESC,
				'value'   => '',
				'compare' => '=',
			),
		);
	} else {
		$desc_clause = array();
	}
	if ( ! empty( $desc_clause ) ) {
		if ( ! empty( $qargs['meta_query'] ) ) {
			$qargs['meta_query'] = array(
				'relation' => 'AND',
				$qargs['meta_query'],
				$desc_clause,
			);
		} else {
			$qargs['meta_query'] = $desc_clause;
		}
	}
    // phpcs:enable WordPress.DB.SlowDBQuery

	$q           = new WP_Query( $qargs );
	$posts       = $q->posts;
	$total       = $q->found_posts;
	$total_pages = $q->max_num_pages;
	$page_ids    = array_map( fn( $p ) => $p->ID, $posts );
	$ajax_nonce  = wp_create_nonce( BSM_AJAX_NONCE );

	$jet_active = defined( 'JET_ENGINE_VERSION' ) || class_exists( 'Jet_Engine' );
	$jet_count  = 0;
	if ( $jet_active ) {
		foreach ( array_keys( $all_types ) as $s ) {
			if ( ! in_array( $s, array( 'post', 'page' ), true ) ) {
				++$jet_count;
			}
		}
	}
	?>
	<div class="wrap" id="bsm-wrap">
		<?php bsm_topbar(); ?>
		<?php bsm_render_tabs( 'bulk' ); ?>

		<?php
		if ( $saved > 0 ) :
			?>
			<div class="notice notice-success is-dismissible"><p>
			<?php
			/* translators: %d: number of items saved. */
			printf( esc_html( _n( '%d item saved.', '%d items saved.', $saved, 'lookit-seo-copilot' ) ), (int) $saved );
			?>
			</p></div><?php endif; ?>
		<?php
		if ( $errors > 0 ) :
			?>
			<div class="notice notice-warning is-dismissible"><p>
			<?php
			/* translators: %d: number of items that could not be updated. */
			printf( esc_html__( '%d item(s) could not be updated.', 'lookit-seo-copilot' ), (int) $errors );
			?>
			</p></div><?php endif; ?>
		<?php if ( ! class_exists( 'WPSEO_Meta' ) && ! defined( 'WPSEO_VERSION' ) ) : ?>
			<div class="notice notice-warning"><p><strong>Yoast SEO not active.</strong> Values will be saved and picked up once Yoast is activated.</p></div>
		<?php endif; ?>

		<p class="bsm-cpt-count">
			<?php
			/* translators: 1: number of post types, 2: comma-separated list of post type labels. */
			printf( esc_html__( '%1$d post types: %2$s', 'lookit-seo-copilot' ), count( $all_types ), esc_html( implode( ', ', array_map( fn( $t ) => $t->label, $all_types ) ) ) );
			if ( $jet_active && $jet_count ) {
				/* translators: %d: number of post types provided via JetEngine. */
				printf( ' — <strong>%d</strong> via JetEngine.', (int) $jet_count );
			}
			?>
		</p>

		<?php /* ── Control panel: filters + fill controls + Save All ── */ ?>
		<div class="bsm-fill-bar">

			<div class="bsm-panel-top">
			<?php /* Filters (now inside the panel) */ ?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="lookit-bulk-seo">
				<div class="bsm-filters">
					<select name="bkm_type">
						<option value="all" <?php selected( $ftype, 'all' ); ?>>All post types</option>
						<?php foreach ( $all_types as $slug => $obj ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $ftype, $slug ); ?>><?php echo esc_html( $obj->label ); ?></option>
						<?php endforeach; ?>
					</select>
					<select name="post_status">
						<option value="publish" <?php selected( $fpost, 'publish' ); ?>>Published (live)</option>
						<option value="draft"   <?php selected( $fpost, 'draft' ); ?>>Drafts</option>
						<option value="pending" <?php selected( $fpost, 'pending' ); ?>>Pending review</option>
						<option value="future"  <?php selected( $fpost, 'future' ); ?>>Scheduled</option>
						<option value="private" <?php selected( $fpost, 'private' ); ?>>Private</option>
						<option value="any"     <?php selected( $fpost, 'any' ); ?>>All statuses</option>
					</select>
					<select name="kw_status">
						<option value="all"   <?php selected( $fstatus, 'all' ); ?>>Any keyphrase status</option>
						<option value="set"   <?php selected( $fstatus, 'set' ); ?>>Keyphrase set</option>
						<option value="empty" <?php selected( $fstatus, 'empty' ); ?>>No keyphrase</option>
						<option value="duplicate" <?php selected( $fstatus, 'duplicate' ); ?>>Duplicated keyphrases</option>
					</select>
					<select name="desc_status">
						<option value="all"   <?php selected( $fdesc, 'all' ); ?>>Any description status</option>
						<option value="set"   <?php selected( $fdesc, 'set' ); ?>>Meta description set</option>
						<option value="empty" <?php selected( $fdesc, 'empty' ); ?>>No meta description</option>
					</select>
					<input type="search" name="s" id="bsm-search" placeholder="Search titles…" value="<?php echo esc_attr( $search ); ?>">
					<?php submit_button( 'Filter', 'secondary', '', false ); ?>
					<?php if ( $search || 'all' !== $ftype || 'all' !== $fstatus || 'all' !== $fdesc || 'publish' !== $fpost ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=lookit-bulk-seo' ) ); ?>" class="button">Reset</a>
					<?php endif; ?>
				</div>
			</form>
			</div><?php /* /bsm-panel-top */ ?>

			<div class="bsm-switcher">
			<div class="bsm-fill-tabs" role="tablist">
				<button type="button" class="bsm-tab" data-target="kw">Keyphrases</button>
				<button type="button" class="bsm-tab" data-target="desc">Descriptions</button>
				<button type="button" class="bsm-tab" data-target="rel">Related keyphrases</button>
				<button type="button" class="bsm-tab" data-target="title">SEO Titles</button>
			</div>

			<div class="bsm-target" data-target="kw">
			<select class="bsm-kw-source" id="bsm-kw-source">
				<optgroup label="── AI (Amazon Bedrock) ──">
					<option value="ai" selected>✦ AI — Nova Lite via platform</option>
				</optgroup>
				<optgroup label="── Built-in ──">
					<option value="match">Best match — title + intro + content</option>
					<option value="titleslug">Title &#8745; slug overlap</option>
					<option value="title">Use title (verbatim)</option>
					<option value="slug">Use slug</option>
				</optgroup>
				<?php if ( ! empty( $kw_templates ) ) : ?>
				<optgroup label="── Your templates ──">
					<?php foreach ( $kw_templates as $i => $tpl ) : ?>
						<option value="template_<?php echo esc_attr( $i ); ?>"
								data-template="<?php echo esc_attr( $tpl['template'] ); ?>">
							<?php echo esc_html( $tpl['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</optgroup>
				<?php else : ?>
				<optgroup label="── Your templates ──">
					<option disabled>No templates yet — add them in Settings</option>
				</optgroup>
				<?php endif; ?>
			</select>

			<button type="button" class="bsm-btn bsm-btn-green" id="bsm-btn-fill-kw">✦ Preview &amp; Fill All</button>
			<button type="button" class="bsm-btn bsm-btn-selected" id="bsm-btn-fill-kw-selected"
					style="opacity:0.5;" title="Tick the checkboxes on the rows you want to fill first">✦ Preview &amp; Fill Selected</button>
			</div>

			<div class="bsm-target" data-target="desc" hidden>
			<select class="bsm-desc-source" id="bsm-desc-source">
				<optgroup label="── AI (Amazon Bedrock) ──">
					<option value="ai" selected>✦ AI — Nova Lite via platform</option>
				</optgroup>
				<optgroup label="── Built-in ──">
					<option value="first_sentence">First sentence of content</option>
					<option value="excerpt">Page excerpt / first 25 words</option>
				</optgroup>
				<?php if ( ! empty( $templates ) ) : ?>
				<optgroup label="── Your templates ──">
					<?php foreach ( $templates as $i => $tpl ) : ?>
						<option value="template_<?php echo esc_attr( $i ); ?>"
								data-template="<?php echo esc_attr( $tpl['template'] ); ?>">
							<?php echo esc_html( $tpl['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</optgroup>
				<?php else : ?>
				<optgroup label="── Your templates ──">
					<option disabled>No templates yet — add them in Settings</option>
				</optgroup>
				<?php endif; ?>
			</select>

			<button type="button" class="bsm-btn bsm-btn-green" id="bsm-btn-fill-all">✦ Preview &amp; Fill All</button>
			<button type="button" class="bsm-btn bsm-btn-selected" id="bsm-btn-fill-selected"
					style="opacity:0.5;" title="Tick the checkboxes on the rows you want to fill first">✦ Preview &amp; Fill Selected</button>
			</div>

			<div class="bsm-target" data-target="rel" hidden>
			<select id="bsm-rel-source-bulk">
				<option value="off">Off</option>
				<option value="datamuse" selected>Datamuse — free</option>
				<option value="ai">✦ AI — Nova Lite</option>
			</select>
			<button type="button" class="bsm-btn bsm-btn-green" id="bsm-btn-rel-all">✦ Generate All</button>
			<button type="button" class="bsm-btn bsm-btn-selected" id="bsm-btn-rel-selected"
					style="opacity:0.5;" title="Tick the checkboxes on the rows you want first">✦ Generate Selected</button>
			</div>

			<div class="bsm-target" data-target="title" hidden>
			<select id="bsm-title-source-bulk">
				<optgroup label="── AI (Amazon Bedrock) ──">
					<option value="ai" selected>✦ AI — Nova Lite via platform</option>
				</optgroup>
				<optgroup label="── Built-in ──">
					<option value="tpl_title_site" data-template="{title} {sep} {site}">Title + site name</option>
					<option value="tpl_title" data-template="{title}">Title only</option>
				</optgroup>
				<?php if ( ! empty( $title_templates ) ) : ?>
				<optgroup label="── Your templates ──">
					<?php foreach ( $title_templates as $i => $tpl ) : ?>
						<option value="template_<?php echo esc_attr( $i ); ?>"
								data-template="<?php echo esc_attr( $tpl['template'] ); ?>">
							<?php echo esc_html( $tpl['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</optgroup>
				<?php else : ?>
				<optgroup label="── Your templates ──">
					<option disabled>No templates yet — add them in Settings</option>
				</optgroup>
				<?php endif; ?>
			</select>
			<button type="button" class="bsm-btn bsm-btn-green" id="bsm-btn-fill-title-all">✦ Preview &amp; Fill All</button>
			<button type="button" class="bsm-btn bsm-btn-selected" id="bsm-btn-fill-title-selected"
					style="opacity:0.5;" title="Tick the checkboxes on the rows you want first">✦ Preview &amp; Fill Selected</button>
			</div>
			<button type="button" class="button button-primary bsm-save-all">Save All Changes</button>
			<span id="bsm-fill-status" style="font-size:calc(12px * var(--bsm-fs, 1));color:#1da462;display:none;"></span>
			</div><?php /* /bsm-switcher */ ?>
		</div>

		<?php /* ── Main form ── */ ?>
		<form method="post" id="bsm-bulk-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( BSM_NONCE ); ?>
			<input type="hidden" name="action"            value="bsm_save_all">
			<input type="hidden" name="bsm_action"        value="save_all">
			<input type="hidden" name="bkm_filter_type"   value="<?php echo esc_attr( $ftype ); ?>">
			<input type="hidden" name="bkm_filter_status" value="<?php echo esc_attr( $fstatus ); ?>">
			<input type="hidden" name="bkm_filter_desc"   value="<?php echo esc_attr( $fdesc ); ?>">
			<input type="hidden" name="bkm_paged"         value="<?php echo esc_attr( $paged ); ?>">
			<input type="hidden" name="bkm_all_ids" id="bsm-all-ids"
					value="<?php echo esc_attr( implode( ',', $page_ids ) ); ?>">

			<?php if ( empty( $posts ) ) : ?>
				<p>No content found matching your filters.</p>
			<?php else : ?>

			<table class="bsm-table widefat">
				<thead><tr>
					<th style="width:30px;"><input type="checkbox" id="bsm-check-all"></th>
					<th>Title</th>
					<th style="width:100px;">Type</th>
					<th style="width:60px;">KW</th>
					<th style="width:140px;">Current Keyphrase</th>
					<th style="width:170px;">New Keyphrase</th>
					<th style="width:180px;">Current Meta Description</th>
					<th style="width:250px;">New Meta Description</th>
					<th style="width:180px;">Current SEO Title</th>
					<th style="width:220px;">New SEO Title</th>
					<th style="width:96px;">Fill Row</th>
				</tr></thead>
				<tbody>
				<?php
				foreach ( $posts as $post ) :
					$cur_kw    = (string) get_post_meta( $post->ID, BSM_META_KW, true );
					$cur_desc  = (string) get_post_meta( $post->ID, BSM_META_DESC, true );
					$cur_title = (string) get_post_meta( $post->ID, BSM_META_TITLE, true );
					$has_title = '' !== $cur_title;
					// Yoast titles are templates (e.g. %%title%% %%sep%% %%sitename%%); render them for display.
					$title_display = $has_title
						? ( function_exists( 'wpseo_replace_vars' ) ? wpseo_replace_vars( $cur_title, $post ) : $cur_title )
						: '';
					$has_kw        = '' !== $cur_kw;
					$has_desc      = '' !== $cur_desc;
					$dlen          = mb_strlen( $cur_desc );
					$edit_url      = get_edit_post_link( $post->ID );
					$tlabel        = $all_types[ $post->post_type ]->label ?? ucfirst( $post->post_type );
					$dcls          = ! $has_desc ? 'bsm-cc-info' : ( $dlen >= BSM_DESC_LO && $dlen <= BSM_DESC_HI ? 'bsm-cc-ok' : 'bsm-cc-warn' );
					$dtxt          = ! $has_desc ? 'Not set' : ( $dlen >= BSM_DESC_LO && $dlen <= BSM_DESC_HI ? $dlen . ' chars ✓' : $dlen . ' chars (ideal ' . BSM_DESC_LO . '–' . BSM_DESC_HI . ')' );
					?>
					<tr data-post-id="<?php echo esc_attr( $post->ID ); ?>"
						data-title="<?php echo esc_attr( $post->post_title ); ?>">
						<td><input type="checkbox" class="bsm-row-check" value="<?php echo esc_attr( $post->ID ); ?>"></td>
						<td>
							<a href="<?php echo esc_url( $edit_url ); ?>" target="_blank" style="font-weight:500;">
								<?php echo esc_html( $post->post_title ? $post->post_title : '(no title)' ); ?>
							</a>
							<?php
							if ( 'publish' !== $post->post_status ) :
								$st_obj = get_post_status_object( $post->post_status );
								$st_lbl = $st_obj ? $st_obj->label : ucfirst( $post->post_status );
								?>
								<span class="bsm-status-pill bsm-status-<?php echo esc_attr( $post->post_status ); ?>"><?php echo esc_html( $st_lbl ); ?></span>
							<?php endif; ?>
						</td>
						<td><span class="bsm-type-pill"><?php echo esc_html( $tlabel ); ?></span></td>
						<td><span style="font-size:calc(11px * var(--bsm-fs, 1));color:<?php echo $has_kw ? '#1da462' : '#999'; ?>;">
							<?php echo $has_kw ? '● Set' : '○ Empty'; ?>
						</span></td>
						<td><span class="bsm-current"><?php echo $has_kw ? esc_html( $cur_kw ) : '<em style="color:#999;">none</em>'; ?></span></td>
						<td class="bsm-fill-cell">
							<div class="bsm-cell-fill">
								<input type="text"
										class="bsm-kw-input bsm-new-kw"
										name="bkm_keyphrases[<?php echo esc_attr( $post->ID ); ?>]"
										value=""
										placeholder="<?php echo $has_kw ? esc_attr( $cur_kw ) : 'Enter keyphrase…'; ?>">
							</div>
						</td>
						<td>
							<?php if ( $has_desc ) : ?>
								<span class="bsm-current" style="font-size:calc(11px * var(--bsm-fs, 1));"><?php echo esc_html( $cur_desc ); ?></span>
								<div class="bsm-cc <?php echo esc_attr( $dcls ); ?>"><?php echo esc_html( $dtxt ); ?></div>
							<?php else : ?>
								<em style="color:#999;font-size:calc(12px * var(--bsm-fs, 1));">none</em>
								<div class="bsm-cc bsm-cc-info">Not set</div>
							<?php endif; ?>
						</td>
						<td class="bsm-fill-cell">
							<div class="bsm-cell-fill">
								<textarea class="bsm-desc-ta bsm-new-desc"
											name="bkm_metadescs[<?php echo esc_attr( $post->ID ); ?>]"
											rows="3"
											data-post-id="<?php echo esc_attr( $post->ID ); ?>"
											placeholder="<?php echo $has_desc ? esc_attr( $cur_desc ) : 'Enter meta description…'; ?>"></textarea>
								<div class="bsm-cc bsm-cc-info" id="bsm-cc-<?php echo esc_attr( $post->ID ); ?>">0 chars (ideal <?php echo esc_html( BSM_DESC_LO . '–' . BSM_DESC_HI ); ?>)</div>
							</div>
						</td>
						<td><span class="bsm-current"><?php echo $has_title ? esc_html( $title_display ) : '<em style="color:#999;">default</em>'; ?></span></td>
						<td class="bsm-fill-cell">
							<div class="bsm-cell-fill">
								<input type="text"
										class="bsm-kw-input bsm-new-title"
										name="bkm_titles[<?php echo esc_attr( $post->ID ); ?>]"
										value=""
										placeholder="<?php echo $has_title ? esc_attr( $title_display ) : 'Enter SEO title…'; ?>">
							</div>
						</td>
						<td style="text-align:center;vertical-align:middle;">
							<button type="button"
									class="bsm-btn bsm-btn-green bsm-fill-row-both"
									data-post-id="<?php echo esc_attr( $post->ID ); ?>"
									title="Fill this row's keyphrase and meta description using the sources selected above">✦ Fill row</button>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<div class="bsm-bottom-bar">
				<span style="font-size:calc(12px * var(--bsm-fs, 1));color:#666;">
					<?php
					/* translators: 1: first item number, 2: last item number, 3: total items. */
					printf( esc_html__( 'Showing %1$d–%2$d of %3$d', 'lookit-seo-copilot' ), (int) ( ( ( $paged - 1 ) * BSM_PER_PAGE ) + 1 ), (int) min( $paged * BSM_PER_PAGE, $total ), (int) $total );
					?>
				</span>
				<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $paged,
								'total'     => $total_pages,
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							)
						)
					);
					?>
					<button type="button" class="button button-primary bsm-save-all">Save all changes on this page</button>
				</div>
			</div>

			<?php endif; ?>
		</form>
	</div>

	<script>
	(function(){
		var NONCE   = <?php echo wp_json_encode( $ajax_nonce ); ?>;
		var AJAXURL = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
		var LO = <?php echo (int) BSM_DESC_LO; ?>, HI = <?php echo (int) BSM_DESC_HI; ?>;

		// ── Check-all ──
		document.getElementById('bsm-check-all')?.addEventListener('change', function() {
			document.querySelectorAll('.bsm-row-check').forEach(function(cb){ cb.checked = this.checked; }, this);
		});

		// ── Live char counter ──
		// ── AI fill via platform webhook (Bedrock Nova Lite) — chunked in 5s ──
		function bsmAiFill(rows, task) {
			if (!rows.length) return;
			var status = document.getElementById('bsm-fill-status');
			if (status){ status.style.display='inline'; status.style.color='#1a8fd1'; status.textContent='✦ Generating with Bedrock…'; }
			var CHUNK = 5, idx = 0, filled = 0, hadError = '';

			(function next(){
				var slice = rows.slice(idx, idx + CHUNK);
				if (!slice.length){
					if (status){
						if (filled){ status.style.color='#1da462'; status.textContent='✦ Filled '+filled+' '+(task==='keyphrase'?'keyphrase':task==='title'?'SEO title':'description')+(filled===1?'':'s')+' with Bedrock — review and Save.'; }
						else { status.style.color='#d63638'; status.textContent='⚠ '+(hadError||'No results'); }
					}
					return;
				}
				var ids = slice.map(function(r){ return r.getAttribute('data-post-id'); }).filter(Boolean);
				var fd = new FormData();
				fd.append('action','bsm_ai_fill');
				fd.append('nonce', NONCE);
				fd.append('ids', ids.join(','));
				fd.append('task', task);
				slice.forEach(function(row){
					var pid = row.getAttribute('data-post-id');
					var kwEl = row.querySelector('.bsm-new-kw');
					var kw = (kwEl && kwEl.value) ? kwEl.value : '';
					if (kw) fd.append('kw['+pid+']', kw);
				});
				fetch(AJAXURL,{method:'POST',body:fd,credentials:'same-origin'})
					.then(function(r){ return r.json(); })
					.then(function(resp){
						if (resp && resp.success){
							var res = resp.data.results || {};
							slice.forEach(function(row){
								var pid = row.getAttribute('data-post-id');
								var d = res[pid];
								if (!d) return;
								if (task === 'keyphrase'){
									var inp = row.querySelector('.bsm-new-kw');
									if (inp){ inp.value = d.primary || ''; bsmKwFlash(inp); filled++; }
								} else if (task === 'title'){
									var ti = row.querySelector('.bsm-new-title');
									if (ti){ ti.value = d.text || d.primary || ''; bsmKwFlash(ti); filled++; }
								} else {
									var ta = row.querySelector('.bsm-new-desc');
									if (ta){ ta.value = fitDesc(d.text || '', {}); updateCC(ta); ta.style.background='#e8f7ee'; setTimeout(function(){ ta.style.background=''; },800); filled++; }
								}
							});
							if (resp.data.errors){ for (var k in resp.data.errors){ hadError = resp.data.errors[k]; } }
						} else {
							hadError = (resp && resp.data) ? resp.data : 'AI request failed';
						}
						idx += CHUNK; next();
					})
					.catch(function(){ hadError = 'Network error'; idx += CHUNK; next(); });
			})();
		}

		function updateCC(ta) {
			var pid = ta.getAttribute('data-post-id');
			var el  = document.getElementById('bsm-cc-' + pid);
			if (!el) return;
			var len = ta.value.length;
			el.className = 'bsm-cc ';
			if      (len === 0)             { el.className += 'bsm-cc-info'; el.textContent = '0 chars (ideal ' + LO + '–' + HI + ')'; }
			else if (len >= LO && len <= HI){ el.className += 'bsm-cc-ok';  el.textContent = len + ' chars ✓'; }
			else                            { el.className += 'bsm-cc-warn'; el.textContent = len + ' chars (ideal ' + LO + '–' + HI + ')'; }
		}
		document.querySelectorAll('.bsm-new-desc').forEach(function(ta){
			ta.addEventListener('input', function(){ updateCC(ta); });
		});

		// ── Fit a generated description into the LO–HI sweet spot ──
		// Trims to <= HI on a word boundary; if under LO, extends using the
		// post's excerpt/content (without duplicating text already present).
		function fitDesc(text, d) {
			text = (text || '').replace(/\s+/g, ' ').trim();

			if (text.length < LO) {
				var pool = [d.excerpt, d.content, d.first_sentence];
				for (var i = 0; i < pool.length && text.length < LO; i++) {
					var extra = (pool[i] || '').replace(/\s+/g, ' ').replace(/…+$/, '').trim();
					if (!extra) continue;
					if (!text) {
						text = extra;
					} else if (extra.toLowerCase().indexOf(text.toLowerCase()) === 0) {
						text = extra; // extra is a longer superset of what we have
					} else if (text.toLowerCase().indexOf(extra.toLowerCase()) !== -1) {
						continue;     // extra already contained — skip
					} else {
						text = (text.replace(/[\s.\-—|]+$/, '') + ' ' + extra).trim();
					}
				}
			}

			if (text.length > HI) {
				var cut = text.slice(0, HI);
				var sp  = cut.lastIndexOf(' ');
				if (sp > HI * 0.6) cut = cut.slice(0, sp);
				text = cut.replace(/[\s,;:\-—|]+$/, '');
			}
			return text;
		}

		// ── Live search ──
		document.getElementById('bsm-search')?.addEventListener('input', function(){
			var t = this.value.toLowerCase();
			document.querySelectorAll('.bsm-table tbody tr').forEach(function(r){
				var a = r.querySelector('a');
				r.style.display = (a && a.textContent.toLowerCase().includes(t)) ? '' : 'none';
			});
		});

		// ── Fill keyphrases from source (title | slug | topword) ──
		function bsmKwFlash(inp) {
			inp.style.background = '#e8f7ee';
			setTimeout(function(){ inp.style.background = ''; }, 800);
		}

		function fillKeyphrases(rows) {
			if (!rows.length) return;
			var source = document.getElementById('bsm-kw-source').value;
			var status = document.getElementById('bsm-fill-status');

			// AI (Bedrock via platform) — posts each row's context to the webhook.
			if (source === 'ai') { bsmAiFill(rows, 'keyphrase'); return; }

			// Title needs no server round-trip — read the row's data-title.
			if (source === 'title') {
				rows.forEach(function(row){
					var inp = row.querySelector('.bsm-new-kw');
					if (inp) { inp.value = row.getAttribute('data-title') || ''; bsmKwFlash(inp); }
				});
				if (status) {
					status.style.display = 'inline';
					status.style.color   = '#1da462';
					status.textContent   = '✓ Filled ' + rows.length + ' keyphrase(s) from title — review and Save.';
				}
				return;
			}

			// Custom keyphrase template: fetch source data, resolve placeholders client-side.
			if (source.startsWith('template_')) {
				var kwSel = document.getElementById('bsm-kw-source');
				var tplStr = kwSel.options[kwSel.selectedIndex].getAttribute('data-template') || '';
				var kwIds  = rows.map(function(r){ return r.getAttribute('data-post-id'); }).filter(Boolean);
				if (status) {
					status.style.display = 'inline';
					status.style.color   = '#1a8fd1';
					status.textContent   = 'Loading…';
				}
				var kfd = new FormData();
				kfd.append('action', 'bsm_get_desc_data');
				kfd.append('nonce',  NONCE);
				kfd.append('ids',    kwIds.join(','));
				fetch(AJAXURL, {method:'POST', body:kfd, credentials:'same-origin'})
					.then(function(r){ return r.json(); })
					.then(function(resp){
						if (!resp || !resp.success) {
							if (status) { status.style.color = '#d63638'; status.textContent = '⚠ ' + (resp && resp.data ? resp.data : 'Request failed'); }
							return;
						}
						var kfilled = 0;
						rows.forEach(function(row){
							var pid = row.getAttribute('data-post-id');
							var d   = resp.data[pid];
							var inp = row.querySelector('.bsm-new-kw');
							if (!d || !inp) return;
							var val = tplStr
								.replace(/{title_short}/g, d.title_short || d.title)
								.replace(/{title}/g,       d.title)
								.replace(/{parent}/g,      d.parent   || '')
								.replace(/{slug}/g,        d.slug     || '')
								.replace(/{category}/g,    d.category || '')
								.replace(/{type}/g,        d.type     || '')
								.replace(/{site}/g,        d.site     || '');
							val = val.replace(/{meta:([^}]+)}/g, function(m, key){
								return (d.meta && d.meta[key]) ? d.meta[key] : '';
							});
							// Keyphrases are short — collapse whitespace and trim stray separators.
							val = val.replace(/\s+/g, ' ').replace(/^[\s\-–—|]+|[\s\-–—|]+$/g, '').trim();
							inp.value = val;
							bsmKwFlash(inp);
							kfilled++;
						});
						if (status) {
							status.style.color = '#1da462';
							status.textContent = '✓ Filled ' + kfilled + ' keyphrase(s) — review and Save.';
						}
					})
					.catch(function(){
						if (status) { status.style.color = '#d63638'; status.textContent = '⚠ Network error'; }
					});
				return;
			}

			// Slug / Top content word: fetch server-side, chunked (topword reads each body).
			if (status) {
				status.style.display = 'inline';
				status.style.color   = '#1a8fd1';
				status.textContent   = 'Loading…';
			}
			var CHUNK = 10, i = 0, filled = 0;

			(function next() {
				var slice = rows.slice(i, i + CHUNK);
				if (!slice.length) {
					if (status) {
						status.style.color   = '#1da462';
						status.textContent   = '✓ Filled ' + filled + ' keyphrase(s) — review and Save.';
					}
					return;
				}
				var ids = slice.map(function(r){ return r.getAttribute('data-post-id'); }).filter(Boolean);

				var fd = new FormData();
				fd.append('action', 'bsm_fill_keyphrases');
				fd.append('nonce',  NONCE);
				fd.append('ids',    ids.join(','));
				fd.append('source', source);

				fetch(AJAXURL, {method:'POST', body:fd, credentials:'same-origin'})
					.then(function(r){ return r.json(); })
					.then(function(resp){
						if (resp && resp.success) {
							slice.forEach(function(row){
								var pid = row.getAttribute('data-post-id');
								var inp = row.querySelector('.bsm-new-kw');
								if (inp && resp.data[pid] !== undefined) {
									inp.value = resp.data[pid];
									bsmKwFlash(inp);
									filled++;
								}
							});
						} else if (status) {
							status.style.color = '#d63638';
							status.textContent = '⚠ ' + (resp && resp.data ? resp.data : 'Request failed');
						}
						i += CHUNK;
						next();
					})
					.catch(function(){
						if (status) { status.style.color = '#d63638'; status.textContent = '⚠ Network error'; }
					});
			})();
		}

		// All visible rows
		document.getElementById('bsm-btn-fill-kw')?.addEventListener('click', function(){
			var rows = Array.from(document.querySelectorAll('.bsm-table tbody tr'))
							.filter(function(r){ return r.style.display !== 'none'; });
			fillKeyphrases(rows);
		});

		// Selected (checked) rows only
		document.getElementById('bsm-btn-fill-kw-selected')?.addEventListener('click', function(){
			var rows = Array.from(document.querySelectorAll('.bsm-table tbody tr'))
							.filter(function(r){
								if (r.style.display === 'none') return false;
								var cb = r.querySelector('.bsm-row-check');
								return cb && cb.checked;
							});
			if (!rows.length) {
				alert('Tick some row checkboxes first, then click this button.');
				return;
			}
			fillKeyphrases(rows);
		});

		// ── Fill descriptions — shared function used by both Fill All and Fill Selected ──
		function fillRows(rows) {
			var sourceEl    = document.getElementById('bsm-desc-source');
			var source      = sourceEl.value;
			var status      = document.getElementById('bsm-fill-status');

			// AI (Bedrock via platform) — generates the finished description.
			if (source === 'ai') { bsmAiFill(rows, 'metadesc'); return; }

			var ids         = rows.map(function(r){ return r.getAttribute('data-post-id'); }).filter(Boolean);
			if (!ids.length) {
				status.style.display = 'inline';
				status.style.color   = '#d63638';
				status.textContent   = '⚠ No rows selected — tick some checkboxes first';
				return;
			}

			var templateStr = '';
			if (source.startsWith('template_')) {
				var opt = sourceEl.options[sourceEl.selectedIndex];
				templateStr = opt.getAttribute('data-template') || '';
			}

			status.style.display = 'inline';
			status.style.color   = '#1a8fd1';
			status.textContent   = 'Loading…';

			var fd = new FormData();
			fd.append('action', 'bsm_get_desc_data');
			fd.append('nonce',  NONCE);
			fd.append('ids',    ids.join(','));

			fetch(AJAXURL, {method:'POST', body:fd})
				.then(function(r){ return r.json(); })
				.then(function(resp){
					if (!resp.success) {
						status.style.color = '#d63638';
						status.textContent = '⚠ ' + resp.data;
						return;
					}
					var data   = resp.data;
					var filled = 0;

					rows.forEach(function(row){
						var pid = row.getAttribute('data-post-id');
						var d   = data[pid];
						if (!d) return;
						var ta  = row.querySelector('.bsm-new-desc');
						if (!ta) return;

						var text = '';
						if (source === 'first_sentence') {
							text = d.first_sentence;
						} else if (source === 'excerpt') {
							text = d.excerpt;
						} else if (source.startsWith('template_')) {
							text = templateStr
								.replace(/{title}/g,     d.title)
								.replace(/{site}/g,      d.site)
								.replace(/{keyphrase}/g, d.keyphrase || d.title)
								.replace(/{type}/g,      d.type)
								.replace(/{excerpt}/g,   d.excerpt)
								.replace(/{category}/g,  d.category || d.type);
							// Replace {meta:fieldname} placeholders with actual post meta values.
							// Unresolved fields collapse to empty (never leave the raw token in output).
							text = text.replace(/{meta:([^}]+)}/g, function(match, key) {
								return (d.meta && d.meta[key]) ? d.meta[key] : '';
							});
						}

						text = fitDesc(text, d);
						ta.value = text;
						updateCC(ta);
						ta.style.background = '#e8f7ee';
						setTimeout(function(){ ta.style.background = ''; }, 800);
						filled++;
					});

					status.style.color = '#1da462';
					status.textContent = '✓ ' + filled + ' description' + (filled === 1 ? '' : 's') + ' filled — review and save when ready';
				})
				.catch(function(){
					status.style.color = '#d63638';
					status.textContent = '⚠ Request failed';
				});
		}

		// ── Fill All — every visible row ──
		document.getElementById('bsm-btn-fill-all')?.addEventListener('click', function(){
			var rows = Array.from(document.querySelectorAll('.bsm-table tbody tr'))
							.filter(function(r){ return r.style.display !== 'none'; });
			fillRows(rows);
		});

		// ── Fill Selected — only checked rows ──
		document.getElementById('bsm-btn-fill-selected')?.addEventListener('click', function(){
			var rows = Array.from(document.querySelectorAll('.bsm-table tbody tr'))
							.filter(function(r){
								if (r.style.display === 'none') return false;
								var cb = r.querySelector('.bsm-row-check');
								return cb && cb.checked;
							});
			fillRows(rows);
		});

		// ── Related keyphrases — generate + save to Yoast (Datamuse or AI) ──
		function renderRelatedPills(row, related) {
			var inp  = row.querySelector('.bsm-new-kw');
			var cell = inp ? inp.closest('.bsm-cell-fill') : null;
			if (!cell) return;
			var box = cell.querySelector('.bsm-related-pills');
			if (!related || !related.length){ if (box) box.remove(); return; }
			if (!box){ box = document.createElement('div'); box.className='bsm-related-pills'; (inp||cell).insertAdjacentElement('afterend', box); }
			box.innerHTML = '<span class="bsm-related-label">Related saved to Yoast:</span> ' +
				related.map(function(k){ return '<span class="bsm-related-pill">'+String(k).replace(/</g,'&lt;')+'</span>'; }).join(' ');
		}

		function bsmRelFill(rows) {
			var source = document.getElementById('bsm-rel-source-bulk').value;
			var status = document.getElementById('bsm-fill-status');
			function say(color,msg){ if(status){ status.style.display='inline'; status.style.color=color; status.textContent=msg; } }
			if (source === 'off') { say('#646970','Related keyphrases is set to Off.'); return; }
			if (!rows.length) { say('#d63638','⚠ No rows — tick some checkboxes first'); return; }
			say('#1a8fd1', source==='ai' ? '✦ Generating related with Bedrock…' : 'Generating related via Datamuse…');

			var CHUNK = 5, idx = 0, done = 0, hadError = '';
			(function next(){
				var slice = rows.slice(idx, idx+CHUNK);
				if (!slice.length){
					if (done) { say('#1da462','Saved related keyphrases to Yoast for '+done+' post'+(done===1?'':'s')+'.'); }
					else { say('#d63638','⚠ '+(hadError||'No related keyphrases generated')); }
					return;
				}
				var ids = slice.map(function(r){ return r.getAttribute('data-post-id'); }).filter(Boolean);
				var fd = new FormData();
				fd.append('action','bsm_related_fill');
				fd.append('nonce', NONCE);
				fd.append('ids', ids.join(','));
				fd.append('source', source);
				slice.forEach(function(row){
					var pid = row.getAttribute('data-post-id');
					var kwEl = row.querySelector('.bsm-new-kw');
					var kw = (kwEl && kwEl.value) ? kwEl.value : '';
					if (kw) fd.append('kw[' + pid + ']', kw);
				});
				fetch(AJAXURL,{method:'POST',body:fd,credentials:'same-origin'})
					.then(function(r){ return r.json(); })
					.then(function(resp){
						if (resp && resp.success){
							var res = resp.data.results || {};
							slice.forEach(function(row){
								var rel = res[row.getAttribute('data-post-id')];
								if (rel && rel.length){ renderRelatedPills(row, rel); done++; }
							});
							if (resp.data.errors){ for (var k in resp.data.errors){ hadError = resp.data.errors[k]; } }
						} else { hadError = (resp && resp.data) ? resp.data : 'Request failed'; }
						idx += CHUNK; next();
					})
					.catch(function(){ hadError='Network error'; idx += CHUNK; next(); });
			})();
		}

		document.getElementById('bsm-btn-rel-all')?.addEventListener('click', function(){
			var rows = Array.from(document.querySelectorAll('.bsm-table tbody tr')).filter(function(r){ return r.style.display !== 'none'; });
			bsmRelFill(rows);
		});
		document.getElementById('bsm-btn-rel-selected')?.addEventListener('click', function(){
			var rows = Array.from(document.querySelectorAll('.bsm-table tbody tr')).filter(function(r){
				if (r.style.display === 'none') return false;
				var cb = r.querySelector('.bsm-row-check'); return cb && cb.checked;
			});
			bsmRelFill(rows);
		});

		// ── Fill SEO titles — AI (Bedrock) or a title template ──
		function fillTitles(rows) {
			if (!rows.length) return;
			var sel    = document.getElementById('bsm-title-source-bulk');
			if (!sel) return;
			var source = sel.value;
			var status = document.getElementById('bsm-fill-status');

			if (source === 'ai') { bsmAiFill(rows, 'title'); return; }

			var tplStr = sel.options[sel.selectedIndex].getAttribute('data-template') || '';
			if (!tplStr) return;
			var ids = rows.map(function(r){ return r.getAttribute('data-post-id'); }).filter(Boolean);
			if (status){ status.style.display='inline'; status.style.color='#1a8fd1'; status.textContent='Loading…'; }
			var fd = new FormData();
			fd.append('action','bsm_get_desc_data');
			fd.append('nonce', NONCE);
			fd.append('ids', ids.join(','));
			fetch(AJAXURL, {method:'POST', body:fd, credentials:'same-origin'})
				.then(function(r){ return r.json(); })
				.then(function(resp){
					if (!resp || !resp.success){ if(status){ status.style.color='#d63638'; status.textContent='⚠ '+((resp&&resp.data)?resp.data:'Request failed'); } return; }
					var filled = 0;
					rows.forEach(function(row){
						var d  = resp.data[row.getAttribute('data-post-id')];
						var ti = row.querySelector('.bsm-new-title');
						if (!d || !ti) return;
						var val = tplStr
							.replace(/{title_short}/g, d.title_short || d.title)
							.replace(/{title}/g,       d.title)
							.replace(/{sep}/g,         '–')
							.replace(/{site}/g,        d.site     || '')
							.replace(/{category}/g,    d.category || '')
							.replace(/{type}/g,        d.type     || '')
							.replace(/{parent}/g,      d.parent   || '')
							.replace(/{slug}/g,        d.slug     || '');
						val = val.replace(/{meta:([^}]+)}/g, function(m, key){ return (d.meta && d.meta[key]) ? d.meta[key] : ''; });
						val = val.replace(/\s+/g, ' ').replace(/^[\s\-–—|]+|[\s\-–—|]+$/g, '').trim();
						ti.value = val; bsmKwFlash(ti); filled++;
					});
					if (status){ status.style.color='#1da462'; status.textContent='✓ Filled '+filled+' SEO title'+(filled===1?'':'s')+' — review and Save.'; }
				})
				.catch(function(){ if(status){ status.style.color='#d63638'; status.textContent='⚠ Network error'; } });
		}
		document.getElementById('bsm-btn-fill-title-all')?.addEventListener('click', function(){
			var rows = Array.from(document.querySelectorAll('.bsm-table tbody tr')).filter(function(r){ return r.style.display !== 'none'; });
			fillTitles(rows);
		});
		document.getElementById('bsm-btn-fill-title-selected')?.addEventListener('click', function(){
			var rows = Array.from(document.querySelectorAll('.bsm-table tbody tr')).filter(function(r){
				if (r.style.display === 'none') return false;
				var cb = r.querySelector('.bsm-row-check'); return cb && cb.checked;
			});
			fillTitles(rows);
		});

		// ── Fill target tabs (segmented switcher) — show one target's controls at a time ──
		(function(){
			var tabs    = document.querySelectorAll('.bsm-fill-tabs .bsm-tab');
			var targets = document.querySelectorAll('.bsm-target');
			if (!tabs.length) return;
			function setTarget(t){
				tabs.forEach(function(b){ b.classList.toggle('-active', b.getAttribute('data-target') === t); });
				targets.forEach(function(d){ d.hidden = (d.getAttribute('data-target') !== t); });
				try { sessionStorage.setItem('bsmFillTab', t); } catch(e){}
			}
			tabs.forEach(function(b){ b.addEventListener('click', function(){ setTarget(b.getAttribute('data-target')); }); });
			var saved = 'kw';
			try { saved = sessionStorage.getItem('bsmFillTab') || 'kw'; } catch(e){}
			if (!document.querySelector('.bsm-fill-tabs .bsm-tab[data-target="' + saved + '"]')) { saved = 'kw'; }
			setTarget(saved);

			// Remember each source dropdown's selection across tab switches and refresh.
			[
				['bsm-kw-source',         'bsmKwSource'],
				['bsm-desc-source',       'bsmDescSource'],
				['bsm-rel-source-bulk',   'bsmRelSource'],
				['bsm-title-source-bulk', 'bsmTitleSource']
			].forEach(function(pair){
				var el = document.getElementById(pair[0]);
				if (!el) return;
				try {
					var v = sessionStorage.getItem(pair[1]);
					if (v !== null && el.querySelector('option[value="' + (window.CSS && CSS.escape ? CSS.escape(v) : v) + '"]')) { el.value = v; }
				} catch(e){}
				el.addEventListener('change', function(){ try { sessionStorage.setItem(pair[1], el.value); } catch(e){} });
			});
		})();

		// ── Dim/brighten Selected buttons based on whether anything is checked ──
		function updateSelectedBtns() {
			var anyChecked = Array.from(document.querySelectorAll('.bsm-row-check')).some(function(cb){ return cb.checked; });
			var fillBtn = document.getElementById('bsm-btn-fill-selected');
			var kwBtn   = document.getElementById('bsm-btn-fill-kw-selected');
			var relBtn  = document.getElementById('bsm-btn-rel-selected');
			if (relBtn) {
				relBtn.style.opacity = anyChecked ? '1' : '0.5';
				relBtn.title = anyChecked ? '' : 'Tick the checkboxes on the rows you want first';
			}
			var titleBtn = document.getElementById('bsm-btn-fill-title-selected');
			if (titleBtn) {
				titleBtn.style.opacity = anyChecked ? '1' : '0.5';
				titleBtn.title = anyChecked ? '' : 'Tick the checkboxes on the rows you want first';
			}
			if (fillBtn) {
				fillBtn.style.opacity = anyChecked ? '1' : '0.5';
				fillBtn.title = anyChecked ? '' : 'Tick the checkboxes on the rows you want to fill first';
			}
			if (kwBtn) {
				kwBtn.style.opacity = anyChecked ? '1' : '0.5';
				kwBtn.title = anyChecked ? '' : 'Tick the checkboxes on the rows you want to fill first';
			}
		}
		// Alias for backward compat
		var updateFillSelectedBtn = updateSelectedBtns;
		document.querySelectorAll('.bsm-row-check').forEach(function(cb){
			cb.addEventListener('change', updateSelectedBtns);
		});
		document.getElementById('bsm-check-all')?.addEventListener('change', function(){
			setTimeout(updateSelectedBtns, 0);
		});
		updateSelectedBtns();

		// ── AJAX: save single row ──
		function saveSingle(postId) {
			var row  = document.querySelector('tr[data-post-id="' + postId + '"]');
			var kw   = row?.querySelector('.bsm-new-kw')?.value   || '';
			var desc = row?.querySelector('.bsm-new-desc')?.value || '';
			var btn  = row?.querySelector('.bsm-save-single');
			if (btn) { btn.disabled = true; btn.textContent = '…'; }

			var fd = new FormData();
			fd.append('action',    'bsm_save_single');
			fd.append('nonce',     NONCE);
			fd.append('post_id',   postId);
			fd.append('keyphrase', kw);
			fd.append('metadesc',  desc);

			fetch(AJAXURL, {method:'POST', body:fd})
				.then(function(r){ return r.json(); })
				.then(function(data){
					if (!btn) return;
					btn.disabled    = false;
					btn.textContent = data.success ? '✓ Saved' : '✗ Error';
					btn.className   = data.success ? 'bsm-btn-save saved' : 'bsm-btn-save';
					setTimeout(function(){ btn.textContent = 'Save'; btn.className = 'bsm-btn-save bsm-save-single'; }, 2000);
				})
				.catch(function(){ if(btn){ btn.disabled=false; btn.textContent='Save'; } });
		}

		document.querySelectorAll('.bsm-save-single').forEach(function(btn){
			btn.addEventListener('click', function(){ saveSingle(btn.getAttribute('data-post-id')); });
		});

		// ── Fill row — fill BOTH keyphrase and description for one row ──
		document.querySelector('.bsm-table')?.addEventListener('click', function(e){
			var bothBtn = e.target.closest('.bsm-fill-row-both');
			if (bothBtn) {
				var row = bothBtn.closest('tr');
				if (row) { fillKeyphrases([row]); fillRows([row]); fillTitles([row]); }
			}
		});

		// ── AJAX save-all — persist every staged row without a page reload ──
		function saveAll() {
			var status = document.getElementById('bsm-fill-status');
			var btns   = document.querySelectorAll('.bsm-save-all');
			var fd = new FormData();
			fd.append('action', 'bsm_save_all');
			fd.append('nonce', NONCE);
			document.querySelectorAll('.bsm-table tbody tr[data-post-id]').forEach(function(row){
				var id = row.getAttribute('data-post-id');
				var kw = row.querySelector('.bsm-new-kw');
				var ta = row.querySelector('.bsm-new-desc');
				var ti = row.querySelector('.bsm-new-title');
				if (kw && kw.value.trim()) { fd.append('keyphrases[' + id + ']', kw.value); }
				if (ta && ta.value.trim()) { fd.append('metadescs[' + id + ']', ta.value); }
				if (ti && ti.value.trim()) { fd.append('titles[' + id + ']', ti.value); }
			});
			btns.forEach(function(b){ b.disabled = true; });
			if (status){ status.style.display='inline'; status.style.color='#1a8fd1'; status.textContent='Saving…'; }
			fetch(AJAXURL, {method:'POST', body:fd, credentials:'same-origin'})
				.then(function(r){ return r.json(); })
				.then(function(resp){
					btns.forEach(function(b){ b.disabled = false; });
					if (resp && resp.success){
						var n = (resp.data && resp.data.saved != null) ? resp.data.saved : 0;
						if (status){ status.style.color='#1da462'; status.textContent='✓ Saved ' + n + ' row' + (n===1?'':'s') + '.'; }
						btns.forEach(function(b){ var t=b.textContent; b.textContent='✓ Saved'; setTimeout(function(){ b.textContent=t; }, 2000); });
					} else {
						if (status){ status.style.color='#d63638'; status.textContent='⚠ ' + ((resp&&resp.data)?resp.data:'Save failed'); }
					}
				})
				.catch(function(){ btns.forEach(function(b){ b.disabled=false; }); if(status){ status.style.color='#d63638'; status.textContent='⚠ Network error'; } });
		}
		document.querySelectorAll('.bsm-save-all').forEach(function(b){
			b.addEventListener('click', function(e){ e.preventDefault(); saveAll(); });
		});
		document.getElementById('bsm-bulk-form')?.addEventListener('submit', function(e){ e.preventDefault(); saveAll(); });

	})();
	</script>
	<?php
}
