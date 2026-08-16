<?php
/**
 * @package Lookit_SEO_Copilot
 */

class Test_Lookit_SEO_Copilot_Plugin extends WP_UnitTestCase {

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

		$registered = get_registered_meta_keys( 'post', 'post' );
		$this->assertArrayHasKey( '_asy_seo_locked', $registered );
		$callback = $registered['_asy_seo_locked']['auth_callback'];

		wp_set_current_user( $author );
		$this->assertTrue( $callback( false, '_asy_seo_locked', $own ) );
		$this->assertFalse( $callback( false, '_asy_seo_locked', $other ) );
	}
}
