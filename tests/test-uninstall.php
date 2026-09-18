<?php
/**
 * @package Lookit_SEO_Copilot
 */

class Test_Lookit_SEO_Copilot_Uninstall extends WP_UnitTestCase {

	public function test_uninstall_deletes_plugin_options() {
		update_option( 'asy_openrouter_api_key', 'lookit-test-value' );
		update_option( 'bsm_report_snapshot', array( 'total' => 2 ) );
		update_option( 'bsm_report_scan_state', array( 'offset' => 1 ) );
		update_option( 'bsm_report_history', array( array( 'score' => 50 ) ) );
		update_option( 'bsm_links_snapshot', array( 'scanned' => 2 ) );
		update_option( 'bsm_links_ignored', array( '1:2' ) );
		update_option( 'bsm_links_scan_state', array( 'offset' => 1 ) );
		update_option( 'bsm_links_acc_state', array( 'offset' => 1 ) );
		update_option( 'bsm_links_map_state', array( 'offset' => 1 ) );
		update_option( 'bsm_ai_webhook_token', 'text-token' );
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'bsm_task_checks', array( 'url-length' ) );
		update_user_meta( $user_id, 'bsm_task_done', array( 'url-length' => array( 1 ) ) );
		update_user_meta( $user_id, 'bsm_last_view', array( 'tab' => 'reports' ) );
		update_user_meta( $user_id, 'bsm_health_htype', 'page' );
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_asy_autofill', '1' );
		update_post_meta( $post_id, '_asy_autofill_run', '2' );
		update_post_meta( $post_id, '_asy_seo_locked', '1' );
		update_post_meta( $post_id, '_asy_ai_status', 'done' );
		update_post_meta( $post_id, '_asy_or_keyphrases', 'related phrase' );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'bulk-keyphrase-manager/bulk-keyphrase-manager.php' );
		}
		require dirname( __DIR__ ) . '/uninstall.php';

		$this->assertFalse( get_option( 'asy_openrouter_api_key' ) );
		$this->assertFalse( get_option( 'bsm_report_snapshot' ) );
		$this->assertFalse( get_option( 'bsm_report_scan_state' ) );
		$this->assertFalse( get_option( 'bsm_report_history' ) );
		$this->assertFalse( get_option( 'bsm_links_snapshot' ) );
		$this->assertFalse( get_option( 'bsm_links_ignored' ) );
		$this->assertFalse( get_option( 'bsm_links_scan_state' ) );
		$this->assertFalse( get_option( 'bsm_links_acc_state' ) );
		$this->assertFalse( get_option( 'bsm_links_map_state' ) );
		$this->assertFalse( get_option( 'bsm_ai_webhook_token' ) );
		$this->assertSame( '', get_user_meta( $user_id, 'bsm_task_checks', true ) );
		$this->assertSame( '', get_user_meta( $user_id, 'bsm_task_done', true ) );
		$this->assertSame( '', get_user_meta( $user_id, 'bsm_last_view', true ) );
		$this->assertSame( '', get_user_meta( $user_id, 'bsm_health_htype', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_asy_autofill', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_asy_autofill_run', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_asy_seo_locked', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_asy_ai_status', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_asy_or_keyphrases', true ) );
	}
}
