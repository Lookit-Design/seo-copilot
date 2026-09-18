<?php
/**
 * @package Lookit_SEO_Copilot
 */

class Test_Lookit_SEO_Copilot_Settings extends WP_UnitTestCase {

	const SECRET = 'lookit-text-webhook-test-token';

	public function tear_down() {
		delete_option( 'bsm_ai_webhook_token' );
		parent::tear_down();
	}

	public function test_text_webhook_header_uses_saved_bearer_token() {
		add_option( 'bsm_ai_webhook_token', self::SECRET, '', false );

		$this->assertSame( 'Bearer ' . self::SECRET, bsm_ai_webhook_headers()['Authorization'] );
	}

	public function test_text_webhook_token_is_not_autoloaded() {
		add_option( 'bsm_ai_webhook_token', self::SECRET, '', false );
		$this->assertArrayNotHasKey( 'bsm_ai_webhook_token', wp_load_alloptions() );
	}

	public function test_secret_migration_disables_autoload() {
		add_option( 'bsm_ai_webhook_token', self::SECRET, '', 'yes' );

		bsm_disable_secret_autoload();
		$this->assertArrayNotHasKey( 'bsm_ai_webhook_token', wp_load_alloptions() );
		$this->assertSame( self::SECRET, get_option( 'bsm_ai_webhook_token' ) );
	}

	public function test_blank_text_webhook_token_preserves_saved_secret() {
		add_option( 'bsm_ai_webhook_token', self::SECRET, '', false );

		$url = bsm_store_ai_settings( 'https://platform.example.test/text', '' );

		$this->assertSame( 'https://platform.example.test/text', $url );
		$this->assertSame( self::SECRET, get_option( 'bsm_ai_webhook_token' ) );
		$this->assertArrayNotHasKey( 'bsm_ai_webhook_token', wp_load_alloptions() );
	}

	public function test_text_generation_sends_bearer_header_without_exposing_token_in_payload() {
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Webhook test',
			)
		);
		update_option( 'bsm_ai_webhook_url', 'https://platform.example.test/text' );
		add_option( 'bsm_ai_webhook_token', self::SECRET, '', false );
		$request = array();
		add_filter(
			'pre_http_request',
			static function ( $response, $args ) use ( &$request ) {
				$request = $args;
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'text' => 'Generated description.' ) ),
					'response' => array( 'code' => 200 ),
					'cookies'  => array(),
				);
			},
			10,
			2
		);

		$result = bsm_ai_call_webhook( 'metadesc', get_post( $post_id ) );
		$this->assertSame( 'Generated description.', $result );
		$this->assertSame( 'Bearer ' . self::SECRET, $request['headers']['Authorization'] );
		$this->assertStringNotContainsString( self::SECRET, $request['body'] );
		remove_all_filters( 'pre_http_request' );
		delete_option( 'bsm_ai_webhook_url' );
	}
}
