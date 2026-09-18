<?php
/**
 * SEO-5 behavior coverage.
 *
 * @package Lookit_SEO_Copilot
 */

class Test_Lookit_SEO_Copilot_SEO5 extends WP_UnitTestCase {

	private int $admin_id;

	public function set_up(): void {
		parent::set_up();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
		delete_option( BSM_Reports::OPTION );
		delete_option( BSM_Reports::STATE_OPTION );
		delete_option( BSM_Reports::HISTORY );
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		unregister_post_type( 'product' );
		delete_option( BSM_Reports::OPTION );
		delete_option( BSM_Reports::STATE_OPTION );
		delete_option( BSM_Reports::HISTORY );
		parent::tear_down();
	}

	/**
	 * @dataProvider keyphrase_provider
	 */
	public function test_keyphrase_normalization( string $raw, string $expected ): void {
		$this->assertSame( $expected, bsm_clean_keyphrase( $raw ) );
	}

	public function keyphrase_provider(): array {
		return array(
			'periods and initialism' => array( 'U.S. policy.', 'US policy' ),
			'long initialism'        => array( 'F.B.I. report', 'FBI report' ),
			'slashes and colons'     => array( 'SEO/SEM: guide', 'SEO SEM guide' ),
			'ampersand'              => array( 'Sales & Marketing', 'Sales Marketing' ),
			'dashes'                 => array( 'North – South — East', 'North South East' ),
			'quotes and brackets'    => array( '"Guide" [Updated] (2026)', 'Guide Updated 2026' ),
			'curly single quotes'    => array( 'Guide ‘SEO’ tips', 'Guide SEO tips' ),
			'apostrophe and hyphen'  => array( "women's e-commerce", "women's e-commerce" ),
			'accents'                => array( 'Café Sephardí', 'Café Sephardí' ),
			'whitespace'             => array( "  search\t\n guide  ", 'search guide' ),
			'empty'                  => array( '', '' ),
			'punctuation only'       => array( '/:&[]', '' ),
		);
	}

	public function test_manual_and_related_persistence_normalize_without_touching_other_meta(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $post_id, '_unrelated', 'keep' );

		bsm_update_fields( $post_id, 'U.S./SEO & Guide', 'Description', 'Title' );
		ASY_Keyphrase_Engine::save_related_keyphrases(
			$post_id,
			array( 'F.B.I. report', 'women\'s e-commerce', 'U.S./SEO & Guide' )
		);

		$this->assertSame( 'US SEO Guide', get_post_meta( $post_id, BSM_META_KW, true ) );
		$related = json_decode( get_post_meta( $post_id, '_yoast_wpseo_focuskeywords', true ), true );
		$this->assertSame( array( 'FBI report', "women's e-commerce" ), array_column( $related, 'keyword' ) );
		$this->assertSame( 'keep', get_post_meta( $post_id, '_unrelated', true ) );
	}

	public function test_title_slug_and_content_sources_normalize_before_persistence(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'U.S. Search & Growth',
				'post_name'    => 'f-b-i-search',
				'post_content' => str_repeat( 'Café growth strategy. ', 20 ),
			)
		);
		$post    = get_post( $post_id );

		$this->assertSame( 'F B I Search', bsm_clean_keyphrase( ASY_Processor::slug_to_keyphrase( $post->post_name ) ) );
		$this->assertSame( 'US Search Growth', bsm_clean_keyphrase( $post->post_title ) );
		$this->assertStringNotContainsString( '.', bsm_clean_keyphrase( ASY_Processor::get_top_content_word( $post ) ) );
	}

	public function test_target_payload_is_bounded_keeps_context_and_does_not_mutate_post(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Target page',
				'post_excerpt' => '',
				'post_content' => str_repeat( 'Existing page context. ', 30 ),
			)
		);
		$before  = get_post( $post_id )->post_content;
		$payload = array();
		update_option( 'bsm_ai_webhook_url', 'https://example.org/hook' );
		add_filter(
			'pre_http_request',
			static function ( $response, $args ) use ( &$payload ) {
				$payload = json_decode( $args['body'], true );
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'text' => 'Review this generated section.' ) ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			2
		);

		$result = BSM_Health::suggest_for_post( $post_id, 'content', 0, 1, '', str_repeat( 'Section ', 80 ) );

		$this->assertNotWPError( $result );
		$this->assertSame( 'content', $result['kind'] );
		$this->assertSame( 250, $payload['words'] );
		$this->assertLessThanOrEqual( 300, mb_strlen( $payload['target'] ) );
		$this->assertStringContainsString( 'Existing page context', $payload['excerpt'] );
		$this->assertSame( $before, get_post( $post_id )->post_content );
	}

	public function test_ai_and_datamuse_results_are_normalized(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Search strategy',
				'post_content' => str_repeat( 'content planning content planning editorial calendar. ', 8 ),
			)
		);
		$post    = get_post( $post_id );
		update_option( 'bsm_ai_webhook_url', 'https://example.org/hook' );
		add_filter(
			'pre_http_request',
			static function ( $response, $args, $url ) {
				$body = false !== strpos( $url, 'datamuse.com' )
					? wp_json_encode(
						array(
							array(
								'word'  => 'F.B.I./research',
								'score' => 100,
							),
						)
					)
					: wp_json_encode( array( 'text' => '["U.S. SEO", "women\'s e-commerce"]' ) );
				return array(
					'headers'  => array(),
					'body'     => $body,
					'response' => array( 'code' => 200 ),
					'cookies'  => array(),
				);
			},
			10,
			3
		);

		$this->assertSame( array( 'US SEO', "women's e-commerce" ), bsm_ai_call_webhook( 'keyphrase', $post, 2 ) );
		$related = ASY_Keyphrase_Engine::get_related_keyphrases( $post, 5 );
		$this->assertNotWPError( $related );
		foreach ( $related as $keyphrase ) {
			$this->assertSame( bsm_clean_keyphrase( $keyphrase ), $keyphrase );
		}
	}

	public function test_empty_target_retains_whole_page_defaults(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Existing content.',
			)
		);
		$payload = array();
		update_option( 'bsm_ai_webhook_url', 'https://example.org/hook' );
		add_filter(
			'pre_http_request',
			static function ( $response, $args ) use ( &$payload ) {
				$payload = json_decode( $args['body'], true );
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'text' => 'Draft.' ) ),
					'response' => array( 'code' => 200 ),
					'cookies'  => array(),
				);
			},
			10,
			2
		);

		BSM_Health::suggest_for_post( $post_id, 'content' );
		$this->assertSame( 600, $payload['words'] );
		$this->assertArrayNotHasKey( 'target', $payload );
	}

	public function test_completed_scans_store_slices_and_bounded_history_without_autoload(): void {
		register_post_type(
			'product',
			array(
				'public'          => true,
				'show_ui'         => true,
				'map_meta_cap'    => true,
				'capability_type' => 'post',
			)
		);
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => 'post',
				'post_name'   => 'short',
			)
		);
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => 'product',
				'post_name'   => str_repeat( 'long-', 15 ),
			)
		);

		$snapshot = $this->finish_scan();
		$this->assertEqualsCanonicalizing( array( 'post', 'product' ), array_column( $snapshot['slices'], 'key' ) );
		$this->assertCount( 1, BSM_Reports::history_payload()['runs'] );

		global $wpdb;
		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", BSM_Reports::HISTORY )
		);
		$this->assertContains( $autoload, array( 'no', 'off' ) );

		update_option(
			BSM_Reports::HISTORY,
			array_fill(
				0,
				50,
				array(
					'schema' => 0,
					'ts'     => 1,
				)
			),
			false
		);
		$this->finish_scan();
		$this->assertCount( 50, get_option( BSM_Reports::HISTORY ) );
	}

	public function test_history_comparison_reports_movement_and_only_accessible_regressions(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Changing page',
				'post_content' => str_repeat( 'Strong useful content with structure. ', 40 ),
			)
		);
		update_post_meta( $post_id, BSM_META_KW, 'Strong useful content' );
		update_post_meta( $post_id, BSM_META_DESC, str_repeat( 'a', 120 ) );
		$this->finish_scan();

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '',
			)
		);
		$this->finish_scan();
		$history = BSM_Reports::history_payload();

		$this->assertCount( 2, $history['runs'] );
		$this->assertNotEmpty( $history['moved'] );
		$this->assertSame( 'Changing page', $history['regressed'][0]['title'] );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'private',
			)
		);
		$this->assertSame( array(), BSM_Reports::history_payload()['regressed'] );
	}

	public function test_admin_only_report_data_and_focus_object_authorization_remain_separate(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$editor  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->finish_scan();
		wp_set_current_user( $editor );

		$_GET = array(
			'tab'  => 'reports',
			'view' => 'focus',
		);
		$this->assertTrue( bsm_is_focus_view() );
		$this->assertSame( array(), BSM_Reports::snapshot() );
		$this->assertSame( array(), BSM_Reports::history_payload() );
		$this->assertTrue( current_user_can( 'edit_post', $post_id ) );
	}

	public function test_editor_focus_view_is_remembered_without_one_time_parameters(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		set_current_screen( 'dashboard' );
		$_GET = array(
			'page'     => 'lookit-bulk-seo',
			'tab'      => 'reports',
			'view'     => 'focus',
			'fstrat'   => 'random',
			'ff'       => '42',
			'ffskip'   => '41',
			'_wpnonce' => 'discard',
		);

		bsm_remember_tab();
		$view = get_user_meta( $editor, 'bsm_last_view', true );

		$this->assertSame( 'reports', bsm_resolve_tab() );
		$this->assertSame(
			array(
				'tab'    => 'reports',
				'view'   => 'focus',
				'fstrat' => 'random',
				'ff'     => '42',
			),
			$view
		);
		$this->assertArrayNotHasKey( 'ffskip', $view );
		$this->assertArrayNotHasKey( '_wpnonce', $view );
		$_GET = array();
	}

	public function test_runtime_has_exact_view_order_and_no_links_surface(): void {
		$main = file_get_contents( dirname( __DIR__ ) . '/bulk-keyphrase-manager.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source assertion.
		$this->assertSame(
			array( 'Website Report', 'Impact Simulator', 'Task Manager', 'Trends', 'Focus' ),
			array_values( BSM_Reports::views() )
		);
		$this->assertFileExists( dirname( __DIR__ ) . '/includes/class-bsm-links.php' );
		$this->assertFalse( BSM_LINKS_UI );
		$this->assertStringNotContainsString( "add_action( 'wp_ajax_bsm_links_", $main );
		$this->assertSame( array( 'view', 'fstrat', 'ff' ), bsm_view_params( 'reports' ) );
		$health_js = file_get_contents( dirname( __DIR__ ) . '/assets/health.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source assertion.
		$this->assertStringContainsString( "words.dataset.bsmUserSet = '1'", $health_js );
		$this->assertStringContainsString( "return target ? '250' : '600'", $health_js );
	}

	private function finish_scan(): array {
		$result = BSM_Reports::scan_batch( 'start', 0 );
		while ( empty( $result['complete'] ) ) {
			$result = BSM_Reports::scan_batch( $result['phase'], $result['offset'], $result['scan_id'] );
		}
		return $result['snapshot'];
	}
}
