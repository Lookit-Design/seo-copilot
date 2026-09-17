<?php
/**
 * @package Lookit_SEO_Copilot
 */

class Test_Lookit_SEO_Copilot_Plugin extends WP_UnitTestCase {

	public function test_plugin_headers_preserve_display_and_technical_identity() {
		$headers = get_file_data(
			dirname( __DIR__ ) . '/bulk-keyphrase-manager.php',
			array(
				'name'        => 'Plugin Name',
				'text_domain' => 'Text Domain',
			)
		);

		$this->assertSame( 'Lookit SEO Copilot', $headers['name'] );
		$this->assertSame( 'bulk-keyphrase-manager', $headers['text_domain'] );
	}

	public function test_only_canonical_main_file_exists() {
		$this->assertFileExists( dirname( __DIR__ ) . '/bulk-keyphrase-manager.php' );
		$this->assertFileDoesNotExist( dirname( __DIR__ ) . '/lookit-seo-copilot.php' );
	}

	public function test_canonical_plugin_basename() {
		$main_file = WP_PLUGIN_DIR . '/bulk-keyphrase-manager/bulk-keyphrase-manager.php';

		$this->assertSame( 'bulk-keyphrase-manager/bulk-keyphrase-manager.php', plugin_basename( $main_file ) );
	}

	public function test_plugin_defines_version() {
		$this->assertTrue( defined( 'BSM_VERSION' ) );
	}

	public function test_settings_class_is_available() {
		$this->assertTrue( class_exists( 'ASY_Settings' ) );
	}

	public function test_sanitize_templates_returns_array() {
		$result = bsm_sanitize_templates( 'not-an-array' );
		$this->assertSame( array(), $result );
	}

	public function test_lock_meta_auth_requires_edit_post() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$own    = self::factory()->post->create( array( 'post_author' => $author ) );
		$other  = self::factory()->post->create( array( 'post_author' => $editor ) );

		wp_set_current_user( $author );
		$this->assertTrue( bsm_lock_meta_auth( false, '_asy_seo_locked', $own ) );
		$this->assertFalse( bsm_lock_meta_auth( false, '_asy_seo_locked', $other ) );
	}
}
