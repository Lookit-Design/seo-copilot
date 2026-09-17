<?php
/**
 * @package Lookit_SEO_Copilot
 */

class Test_Lookit_SEO_Copilot_Uninstall extends WP_UnitTestCase {

	public function test_uninstall_deletes_plugin_options() {
		update_option( 'asy_openrouter_api_key', 'lookit-test-value' );
		update_option( 'bsm_report_snapshot', array( 'total' => 2 ) );
		update_option( 'bsm_report_scan_state', array( 'offset' => 1 ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'bulk-keyphrase-manager/bulk-keyphrase-manager.php' );
		}
		require dirname( __DIR__ ) . '/uninstall.php';

		$this->assertFalse( get_option( 'asy_openrouter_api_key' ) );
		$this->assertFalse( get_option( 'bsm_report_snapshot' ) );
		$this->assertFalse( get_option( 'bsm_report_scan_state' ) );
	}
}
