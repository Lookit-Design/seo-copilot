<?php
/**
 * @package Lookit_SEO_Copilot
 */

class Test_Lookit_SEO_Copilot_Settings extends WP_UnitTestCase {

	const SECRET = 'sk-openrouter-test-key';

	public function tear_down() {
		delete_option( 'asy_openrouter_api_key' );
		parent::tear_down();
	}

	public function test_store_blank_keeps_existing_key() {
		$settings = new ASY_Settings();
		$settings->store_openrouter_api_key( self::SECRET );

		$this->assertSame( self::SECRET, $settings->store_openrouter_api_key( '' ) );
		$this->assertSame( self::SECRET, get_option( 'asy_openrouter_api_key' ) );
	}

	public function test_store_saves_trimmed_key_without_autoload() {
		$settings = new ASY_Settings();
		$settings->store_openrouter_api_key( '  ' . self::SECRET . '  ' );

		$this->assertSame( self::SECRET, get_option( 'asy_openrouter_api_key' ) );
		$this->assertArrayNotHasKey( 'asy_openrouter_api_key', wp_load_alloptions() );
	}

	public function test_maybe_disable_autoload_removes_key_from_autoload() {
		delete_option( 'asy_openrouter_api_key' );
		add_option( 'asy_openrouter_api_key', self::SECRET, '', 'yes' );

		$this->assertArrayHasKey( 'asy_openrouter_api_key', wp_load_alloptions() );

		$settings = new ASY_Settings();
		$settings->maybe_disable_autoload();

		$this->assertArrayNotHasKey( 'asy_openrouter_api_key', wp_load_alloptions() );
		$this->assertSame( self::SECRET, get_option( 'asy_openrouter_api_key' ) );
	}
}
