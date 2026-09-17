<?php
/**
 * SEO-2 behavior coverage.
 *
 * @package Lookit_SEO_Copilot
 */

class Test_Lookit_SEO_Copilot_SEO2 extends WP_UnitTestCase {

	private function reset_processor(): void {
		$property = new ReflectionProperty( ASY_Processor::class, 'processed_ids' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( null, array() );
	}

	public function test_auto_seo_defaults_off_and_saved_rows_remain_explicit(): void {
		$settings = new ASY_Settings();
		$result   = $settings->sanitize_templates(
			array(
				'post' => array(
					'enabled'          => '1',
					'keyphrase_source' => 'title',
				),
				'page' => array(),
			)
		);

		$this->assertTrue( $result['post']['enabled'] );
		$this->assertSame( 'title', $result['post']['keyphrase_source'] );
		$this->assertFalse( $result['page']['enabled'] );
		$this->assertSame( 'off', $result['page']['keyphrase_source'] );
	}

	public function test_ai_title_cap_uses_word_boundary(): void {
		$title = 'A practical guide to creating excellent search titles for every important page';
		$cap   = bsm_cap_seo_title( $title );

		$this->assertLessThanOrEqual( 55, mb_strlen( $cap ) );
		$this->assertSame( 'A practical guide to creating excellent search titles', $cap );
	}

	public function test_per_post_autofill_runs_repeatedly_and_lock_wins(): void {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			define( 'WPSEO_VERSION', 'test' );
		}
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Original title',
				'post_content' => 'Useful content for a generated description and title.',
			)
		);
		update_post_meta( $post_id, '_asy_autofill', '1' );
		update_option( 'bsm_ai_webhook_url', 'https://platform.example.test/text' );

		$filter = static function ( $preempt, $args ) {
			$payload = json_decode( $args['body'], true );
			$run     = isset( $payload['variation'] ) ? (int) $payload['variation'] : 0;
			$text    = 'metadesc' === $payload['task'] ? "Description run {$run}" : wp_json_encode( array( "Phrase run {$run}", "Related run {$run}" ) );
			if ( 'title' === $payload['task'] ) {
				$text = "SEO title run {$run}";
			}
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'text' => $text ) ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $filter, 10, 2 );

		$processor = new ASY_Processor();
		$this->reset_processor();
		$processor->process( get_post( $post_id ) );
		$this->assertSame( '1', (string) get_post_meta( $post_id, '_asy_autofill_run', true ) );
		$this->assertSame( 'Phrase run 1', get_post_meta( $post_id, BSM_META_KW, true ) );

		$this->reset_processor();
		$processor->process( get_post( $post_id ) );
		$this->assertSame( '2', (string) get_post_meta( $post_id, '_asy_autofill_run', true ) );
		$this->assertSame( 'Phrase run 2', get_post_meta( $post_id, BSM_META_KW, true ) );

		update_post_meta( $post_id, '_asy_seo_locked', '1' );
		$this->reset_processor();
		$processor->process( get_post( $post_id ) );
		$this->assertSame( '2', (string) get_post_meta( $post_id, '_asy_autofill_run', true ) );
		$this->assertSame( 'Phrase run 2', get_post_meta( $post_id, BSM_META_KW, true ) );
		remove_filter( 'pre_http_request', $filter );
	}

	public function test_processor_allows_system_flow_but_rejects_unauthorized_user(): void {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			define( 'WPSEO_VERSION', 'test' );
		}
		$owner   = self::factory()->user->create( array( 'role' => 'editor' ) );
		$foreign = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id = self::factory()->post->create(
			array(
				'post_author' => $owner,
				'post_status' => 'publish',
				'post_title'  => 'Scheduled system title',
			)
		);
		update_option(
			ASY_OPTION_KEY,
			array(
				'post' => array(
					'enabled'          => true,
					'keyphrase_source' => 'title',
				),
			)
		);
		$processor = new ASY_Processor();

		wp_set_current_user( 0 );
		$this->reset_processor();
		$processor->process( get_post( $post_id ) );
		$this->assertSame( 'Scheduled system title', get_post_meta( $post_id, BSM_META_KW, true ) );

		delete_post_meta( $post_id, BSM_META_KW );
		wp_set_current_user( $foreign );
		$this->reset_processor();
		$processor->process( get_post( $post_id ) );
		$this->assertSame( '', get_post_meta( $post_id, BSM_META_KW, true ) );
	}

	public function test_bulk_field_only_update_leaves_other_fields_unchanged(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, BSM_META_KW, 'Existing phrase' );
		update_post_meta( $post_id, BSM_META_DESC, 'Existing description' );
		update_post_meta( $post_id, BSM_META_TITLE, 'Existing title' );

		bsm_update_fields( $post_id, '', '', 'Replacement title' );

		$this->assertSame( 'Existing phrase', get_post_meta( $post_id, BSM_META_KW, true ) );
		$this->assertSame( 'Existing description', get_post_meta( $post_id, BSM_META_DESC, true ) );
		$this->assertSame( 'Replacement title', get_post_meta( $post_id, BSM_META_TITLE, true ) );
	}

	public function test_bulk_editor_reports_all_four_field_mode_counts(): void {
		$complete = self::factory()->post->create();
		$missing  = self::factory()->post->create();
		update_post_meta( $complete, BSM_META_KW, 'Phrase' );
		update_post_meta( $complete, BSM_META_DESC, 'Description' );
		update_post_meta( $complete, BSM_META_TITLE, 'Title' );
		update_post_meta( $complete, '_yoast_wpseo_focuskeywords', 'related' );

		$counts = bsm_bulk_mode_counts( array( get_post( $complete ), get_post( $missing ) ) );

		$this->assertSame(
			array(
				'kw'    => 1,
				'desc'  => 1,
				'rel'   => 1,
				'title' => 1,
			),
			$counts
		);
	}

	public function test_related_keyphrase_chips_merge_case_insensitively_and_allow_removal(): void {
		$merged = BSM_Health::merge_related(
			array( 'Existing phrase', 'Remove me' ),
			array( ' existing   phrase ', 'New phrase' )
		);
		$this->assertSame( array( 'Existing phrase', 'Remove me', 'New phrase' ), $merged );

		$after_removal = BSM_Health::merge_related( array( 'Existing phrase' ), array( 'New phrase' ) );
		$this->assertSame( array( 'Existing phrase', 'New phrase' ), $after_removal );
	}

	public function test_view_restoration_allowlist_excludes_notices_and_nonces(): void {
		$this->assertSame( array( 'audit_post', 'htype', 'hpaged' ), bsm_view_params( 'health' ) );
		$this->assertContains( 'kw_status', bsm_view_params( 'bulk' ) );
		$this->assertNotContains( '_wpnonce', bsm_view_params( 'bulk' ) );
		$this->assertNotContains( 'saved', bsm_view_params( 'bulk' ) );
		$this->assertNotContains( 'focusmsg', bsm_view_params( 'settings' ) );
	}

	public function test_remember_tab_persists_safe_view_only(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		set_current_screen( 'dashboard' );
		$_GET = array(
			'page'      => 'lookit-bulk-seo',
			'tab'       => 'bulk',
			'bkm_type'  => 'page',
			'kw_status' => 'empty',
			's'         => 'search phrase',
			'saved'     => '9',
			'_wpnonce'  => 'forged',
		);

		bsm_remember_tab();
		$view = get_user_meta( $admin, 'bsm_last_view', true );
		$_GET = array();

		$this->assertSame( 'bulk', $view['tab'] );
		$this->assertSame( 'page', $view['bkm_type'] );
		$this->assertSame( 'search phrase', $view['s'] );
		$this->assertArrayNotHasKey( 'saved', $view );
		$this->assertArrayNotHasKey( '_wpnonce', $view );
	}

	public function test_focus_eligibility_respects_skips_and_object_capability(): void {
		$author  = self::factory()->user->create( array( 'role' => 'author' ) );
		$other   = self::factory()->user->create( array( 'role' => 'editor' ) );
		$own     = self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_status' => 'publish',
			)
		);
		$foreign = self::factory()->post->create(
			array(
				'post_author' => $other,
				'post_status' => 'publish',
			)
		);
		wp_set_current_user( $author );
		$method = new ReflectionMethod( BSM_Health::class, 'focus_eligible' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$this->assertTrue( $method->invoke( null, $own ) );
		$this->assertFalse( $method->invoke( null, $foreign ) );
		update_option( 'bsm_focus_skips', array( $own ), false );
		$this->assertFalse( $method->invoke( null, $own ) );
	}

	public function test_focus_work_and_random_strategies_only_return_eligible_posts(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Short content',
			)
		);
		$method  = new ReflectionMethod( BSM_Health::class, 'focus_pick' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$random = $method->invoke( null, array( 'post' ), 'random', array() );
		$work   = $method->invoke( null, array( 'post' ), 'work', array() );
		$this->assertInstanceOf( WP_Post::class, $random );
		$this->assertInstanceOf( WP_Post::class, $work );

		$excluded = $method->invoke( null, array( 'post' ), 'random', array( $post_id ) );
		$this->assertNull( $excluded );
	}

	public function test_focus_skip_nonce_and_mutation_are_bound_to_editable_post(): void {
		$author  = self::factory()->user->create( array( 'role' => 'author' ) );
		$editor  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$own     = self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_status' => 'publish',
			)
		);
		$foreign = self::factory()->post->create(
			array(
				'post_author' => $editor,
				'post_status' => 'publish',
			)
		);
		wp_set_current_user( $author );

		$own_action     = BSM_Health::focus_skip_action( $own );
		$foreign_action = BSM_Health::focus_skip_action( $foreign );
		$own_nonce      = wp_create_nonce( $own_action );
		$this->assertSame( 'bsm_focus_skip_' . $own, $own_action );
		$this->assertFalse( wp_verify_nonce( $own_nonce, $foreign_action ) );
		$this->assertFalse( BSM_Health::focus_add_skip( $foreign ) );
		$this->assertSame( array(), BSM_Health::focus_skips() );
		$this->assertTrue( BSM_Health::focus_add_skip( $own ) );
		$this->assertSame( array( $own ), BSM_Health::focus_skips() );
	}

	public function test_suggestion_rejects_foreign_post_before_http_request(): void {
		$author  = self::factory()->user->create( array( 'role' => 'author' ) );
		$editor  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$foreign = self::factory()->post->create( array( 'post_author' => $editor ) );
		$own     = self::factory()->post->create( array( 'post_author' => $author ) );
		wp_set_current_user( $author );
		update_option( 'bsm_ai_webhook_url', 'https://platform.example.test/text' );
		$calls  = 0;
		$filter = static function () use ( &$calls ) {
			++$calls;
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'text' => 'Allowed description' ) ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $filter );

		$denied = BSM_Health::suggest_for_post( $foreign, 'metadesc' );
		$this->assertWPError( $denied );
		$this->assertSame( 0, $calls );

		$allowed = BSM_Health::suggest_for_post( $own, 'metadesc' );
		remove_filter( 'pre_http_request', $filter );
		$this->assertSame(
			array(
				'kind' => 'metadesc',
				'text' => 'Allowed description',
			),
			$allowed
		);
		$this->assertSame( 1, $calls );
	}

	public function test_keyphrase_suggestions_repeat_without_writing_to_yoast(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$post_id = self::factory()->post->create();
		update_option( 'bsm_ai_webhook_url', 'https://platform.example.test/text' );
		$payloads = array();
		$filter   = static function ( $preempt, $args ) use ( &$payloads ) {
			$payloads[] = json_decode( $args['body'], true );
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'text' => wp_json_encode( array( 'Fresh phrase' ) ) ) ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $filter, 10, 2 );

		$result = bsm_ai_call_webhook(
			'keyphrase',
			get_post( $post_id ),
			1,
			'',
			0,
			array(
				'variation' => 2,
				'previous'  => array( 'suggestions' => array( 'Earlier phrase' ) ),
			)
		);
		remove_filter( 'pre_http_request', $filter );

		$this->assertSame( array( 'Fresh phrase' ), $result );
		$this->assertSame( '', get_post_meta( $post_id, BSM_META_KW, true ) );
		$this->assertSame( 2, $payloads[0]['variation'] );
		$this->assertContains( 'Earlier phrase', $payloads[0]['previous']['suggestions'] );
	}

	public function test_vision_secret_is_not_autoloaded_and_blank_is_preserved(): void {
		add_option( 'bsm_vision_token', 'existing-secret', '', 'yes' );
		bsm_disable_secret_autoload();
		$this->assertArrayNotHasKey( 'bsm_vision_token', wp_load_alloptions( true ) );

		bsm_store_vision_settings( 'javascript:alert(1)', '', 'Describe the image.' );
		$this->assertSame( '', get_option( 'bsm_vision_webhook_url' ) );
		$this->assertSame( 'existing-secret', get_option( 'bsm_vision_token' ) );
		$this->assertSame( 'Describe the image.', get_option( 'bsm_alt_prompt' ) );
	}

	public function test_vision_rejects_missing_endpoint_invalid_payload_and_malformed_response(): void {
		delete_option( 'bsm_vision_webhook_url' );
		$missing = bsm_vision_call( 'data:image/png;base64,AA==', 'image/png', 'Describe.' );
		$this->assertFalse( $missing['ok'] );

		update_option( 'bsm_vision_webhook_url', 'https://platform.example.test/vision' );
		$invalid = bsm_vision_call( 'data:text/plain;base64,AA==', 'text/plain', 'Describe.' );
		$this->assertFalse( $invalid['ok'] );

		$filter = static function () {
			return array(
				'headers'  => array(),
				'body'     => '{bad json',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $filter );
		$malformed = bsm_vision_call( 'data:image/png;base64,AA==', 'image/png', 'Describe.' );
		remove_filter( 'pre_http_request', $filter );
		$this->assertFalse( $malformed['ok'] );

		$success_filter = static function () {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'text' => 'A descriptive alt.' ) ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $success_filter );
		$success = bsm_vision_call( 'data:image/png;base64,AA==', 'image/png', 'Describe.' );
		remove_filter( 'pre_http_request', $success_filter );
		$this->assertTrue( $success['ok'] );
		$this->assertSame( 'A descriptive alt.', $success['alt'] );
	}

	public function test_attachment_alt_actions_require_upload_and_object_permissions(): void {
		$owner = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user  = self::factory()->user->create( array( 'role' => 'author' ) );
		$id    = wp_insert_attachment(
			array(
				'post_author'    => $owner,
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
				'post_title'     => 'Test image',
			),
			DIR_TESTDATA . '/images/canola.jpg'
		);
		wp_set_current_user( $user );
		$this->assertFalse( BSM_Health::can_edit_image( $id ) );
		$this->assertFalse( BSM_Health::save_image_alt( $id, 'Unauthorized alt' ) );
		$this->assertSame( '', get_post_meta( $id, '_wp_attachment_image_alt', true ) );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->assertTrue( BSM_Health::can_edit_image( $id ) );
		update_option( 'bsm_vision_webhook_url', 'https://platform.example.test/vision' );
		$filter = static function () {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'text' => 'Generated but unsaved alt' ) ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $filter );
		$generated = BSM_Health::generate_image_alt( $id );
		remove_filter( 'pre_http_request', $filter );
		$this->assertSame( 'Generated but unsaved alt', $generated );
		$this->assertSame( '', get_post_meta( $id, '_wp_attachment_image_alt', true ) );

		$this->assertTrue( BSM_Health::save_image_alt( $id, 'Authorized alt' ) );
		$this->assertSame( 'Authorized alt', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
	}

	public function test_assets_use_plugin_version_and_metabox_script_needs_endpoint(): void {
		delete_option( 'bsm_ai_webhook_url' );
		bsm_enqueue_assets( 'post.php' );
		$this->assertSame( BSM_VERSION, wp_styles()->registered['lookit-bsm-admin']->ver );
		$this->assertFalse( wp_script_is( 'lookit-bsm-metabox', 'enqueued' ) );

		update_option( 'bsm_ai_webhook_url', 'https://platform.example.test/text' );
		bsm_enqueue_assets( 'post.php' );
		$this->assertTrue( wp_script_is( 'lookit-bsm-metabox', 'enqueued' ) );
		$this->assertSame( BSM_VERSION, wp_scripts()->registered['lookit-bsm-metabox']->ver );
	}

	public function test_health_client_exposes_reviewable_alt_and_related_controls(): void {
		$script = file_get_contents( dirname( __DIR__ ) . '/assets/health.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local test fixture.
		$this->assertStringContainsString( 'bsm_health_alt_generate', $script );
		$this->assertStringContainsString( 'bsm_health_alt_save', $script );
		$this->assertStringContainsString( 'bsm-h-altgenall', $script );
		$this->assertStringContainsString( 'bsm_health_apply_related', $script );

		$single_start = strpos( $script, '// Per-row Generate fills' );
		$single_end   = strpos( $script, '// Per-row Save', $single_start );
		$bulk_start   = strpos( $script, '// Generate all missing' );
		$this->assertStringNotContainsString( 'bsmAltSave( row )', substr( $script, $single_start, $single_end - $single_start ) );
		$this->assertStringContainsString( 'bsmAltSave( row )', substr( $script, $bulk_start ) );
	}
}
