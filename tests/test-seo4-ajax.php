<?php
/**
 * SEO-4 AJAX boundary coverage.
 *
 * @package Lookit_SEO_Copilot
 */

/**
 * @group ajax
 */
class Test_Lookit_SEO_Copilot_SEO4_Ajax extends WP_Ajax_UnitTestCase {

	public function test_slug_handler_rejects_missing_nonce_before_mutation(): void {
		$post_id  = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_name'   => 'ajax-old',
			)
		);
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$_POST    = array( // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Intentional missing-nonce request fixture.
			'post_id' => $post_id,
			'slug'    => 'ajax-new',
		);
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Intentional missing-nonce request fixture.
		wp_set_current_user( $admin_id );

		try {
			$this->_handleAjax( 'bsm_health_apply_slug' );
		} catch ( WPAjaxDieStopException $error ) {
			unset( $error );
		}
		$this->assertSame( 'ajax-old', get_post( $post_id )->post_name );
	}

	/**
	 * @dataProvider task_action_provider
	 */
	public function test_task_handlers_reject_missing_nonces( string $action ): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$_POST    = array( 'checks' => 'url-length' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Intentional missing-nonce request fixture.
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Intentional missing-nonce request fixture.

		try {
			$this->_handleAjax( $action );
		} catch ( WPAjaxDieStopException $error ) {
			unset( $error );
		}
		$this->assertSame( '', get_user_meta( $admin_id, BSM_Tasks::META_CHECKS, true ) );
		$this->assertSame( '', get_user_meta( $admin_id, BSM_Tasks::META_DONE, true ) );
	}

	public function task_action_provider(): array {
		return array(
			array( 'bsm_task_set' ),
			array( 'bsm_task_items' ),
			array( 'bsm_task_toggle' ),
			array( 'bsm_task_reset' ),
		);
	}
}
