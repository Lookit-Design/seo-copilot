<?php
/**
 * Uninstall routine for Lookit SEO Copilot.
 *
 * @package Lookit_SEO_Copilot
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$asy_uninstall_options = array(
	'asy_openrouter_api_key',
	'asy_post_type_templates',
	'asy_kp_count',
	'bsm_custom_templates',
	'bsm_keyphrase_templates',
	'bsm_title_templates',
	'bsm_ai_webhook_url',
	'bsm_ai_webhook_token',
	'bsm_vision_webhook_url',
	'bsm_vision_token',
	'bsm_alt_prompt',
	'bsm_focus_skips',
	'bsm_landing_tab',
	'bsm_report_snapshot',
	'bsm_report_scan_state',
	'bsm_report_history',
	'bsm_links_snapshot',
	'bsm_links_ignored',
	'bsm_links_scan_state',
	'bsm_links_acc_state',
	'bsm_links_map_state',
);

function asy_uninstall_site_data( array $options ): void {
	foreach ( $options as $option ) {
		delete_option( $option );
	}
	delete_transient( 'bsm_links_acc' );
	delete_transient( 'bsm_links_map' );
	foreach ( array( 'bsm_task_checks', 'bsm_task_done', 'bsm_last_tab', 'bsm_last_view', 'bsm_text_size', 'bsm_preview_post_id', 'bsm_health_htype', 'bsm_focus_strat' ) as $meta_key ) {
		delete_metadata( 'user', 0, $meta_key, '', true );
	}
	foreach ( array( '_asy_seo_locked', '_asy_autofill', '_asy_autofill_run', '_asy_ai_status', '_asy_processed', '_asy_version', '_asy_or_keyphrases', '_asy_or_status', '_asy_or_error' ) as $meta_key ) {
		delete_post_meta_by_key( $meta_key );
	}
	wp_cache_delete( 'bsm_distinct_meta_keys', 'bulk-keyphrase-manager' );
}

if ( is_multisite() ) {
	$asy_uninstall_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $asy_uninstall_site_ids as $asy_uninstall_site_id ) {
		switch_to_blog( $asy_uninstall_site_id );
		asy_uninstall_site_data( $asy_uninstall_options );
		restore_current_blog();
	}
} else {
	asy_uninstall_site_data( $asy_uninstall_options );
}
