<?php
/**
 * SEO-6 behavior coverage.
 *
 * @package Lookit_SEO_Copilot
 */

class Test_Lookit_SEO_Copilot_SEO6 extends WP_UnitTestCase {

	private int $admin_id;

	public function set_up(): void {
		parent::set_up();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
		$this->clear_links();
	}

	public function tear_down(): void {
		remove_all_filters( 'wp_insert_post_empty_content' );
		remove_all_filters( 'wp_insert_post_data' );
		remove_role( 'links_manager' );
		$this->clear_links();
		parent::tear_down();
	}

	private function clear_links(): void {
		foreach ( array( BSM_Links::OPTION, BSM_Links::IGNORED, BSM_Links::STATE_OPTION, BSM_Links::LEGACY_ACC, BSM_Links::LEGACY_MAP ) as $option ) {
			delete_option( $option );
		}
	}

	private function target( string $keyphrase, string $title = 'Destination page' ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => 'page',
				'post_title'  => $title,
			)
		);
		update_post_meta( $post_id, BSM_META_KW, $keyphrase );
		return $post_id;
	}

	private function finish_scan( bool $titles = false ): array {
		$result = BSM_Links::start_scan( $titles, true );
		$this->assertNotWPError( $result );
		$guard = 0;
		while ( empty( $result['complete'] ) && ++$guard < 100 ) {
			$result = BSM_Links::scan_batch( $result['phase'], $result['offset'], $result['scan_id'] );
			$this->assertNotWPError( $result );
		}
		$this->assertTrue( $result['complete'] );
		return $result['snapshot'];
	}

	public function test_dormant_flag_registers_no_links_surface_or_secrets(): void {
		$this->assertFalse( BSM_LINKS_UI );
		$this->assertFalse( has_action( 'wp_ajax_bsm_links_scan' ) );
		$this->assertFalse( has_action( 'wp_ajax_bsm_links_insert' ) );
		$this->assertFalse( has_action( 'wp_ajax_bsm_links_ignore' ) );
		$this->assertArrayNotHasKey( 'links', BSM_Reports::views() );

		$_GET = array(
			'page' => 'lookit-bulk-seo',
			'tab'  => 'reports',
			'view' => 'links',
		);
		set_current_screen( 'dashboard' );
		bsm_enqueue_assets( 'toplevel_page_lookit-bulk-seo' );
		$data = wp_scripts()->get_data( 'bsm-reports', 'data' );
		$this->assertStringNotContainsString( 'links_nonce', (string) $data );
		$this->assertStringNotContainsString( 'link_nonce', (string) $data );
		$this->assertFileDoesNotExist( dirname( __DIR__ ) . '/includes/class-asy-openrouter.php' );
		$this->assertFileDoesNotExist( dirname( __DIR__ ) . '/includes/class-bsm-export.php' );
		$this->assertFalse( has_action( 'wp_ajax_asy_save_api_key' ) );
		$main     = file_get_contents( dirname( __DIR__ ) . '/bulk-keyphrase-manager.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source assertion.
		$settings = file_get_contents( dirname( __DIR__ ) . '/includes/class-asy-settings.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source assertion.
		$this->assertStringNotContainsString( 'OpenRouter', $main . $settings );
	}

	public function test_scan_is_admin_bound_resumable_and_non_autoloaded(): void {
		$this->target( 'unique destination phrase' );
		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'This names the unique destination phrase once.',
			)
		);
		$started = BSM_Links::start_scan();
		$resumed = BSM_Links::start_scan();
		$this->assertSame( $started, $resumed );

		$other_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $other_admin );
		$this->assertWPError( BSM_Links::start_scan() );
		$this->assertSame( 'scan_busy', BSM_Links::start_scan()->get_error_code() );
		$this->assertWPError( BSM_Links::scan_batch( $started['phase'], $started['offset'], $started['scan_id'] ) );

		wp_set_current_user( $this->admin_id );
		$result = BSM_Links::scan_batch( $started['phase'], $started['offset'], $started['scan_id'] );
		$this->assertNotWPError( $result );
		global $wpdb;
		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", BSM_Links::STATE_OPTION )
		);
		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	public function test_stale_and_mismatched_continuations_are_rejected(): void {
		$started = BSM_Links::start_scan();
		$this->assertSame(
			'stale_scan',
			BSM_Links::scan_batch( $started['phase'], $started['offset'] + 1, $started['scan_id'] )->get_error_code()
		);
		$state            = get_option( BSM_Links::STATE_OPTION );
		$state['updated'] = time() - BSM_Links::STATE_TTL - 1;
		update_option( BSM_Links::STATE_OPTION, $state, false );
		$this->assertSame(
			'stale_scan',
			BSM_Links::scan_batch( $started['phase'], $started['offset'], $started['scan_id'] )->get_error_code()
		);
		$this->assertFalse( get_option( BSM_Links::STATE_OPTION ) );
	}

	public function test_scan_uses_supported_published_editable_posts_only(): void {
		$target = $this->target( 'supported destination phrase' );
		$source = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'The supported destination phrase appears here.',
			)
		);
		self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_content' => 'The supported destination phrase appears in a draft.',
			)
		);
		$attachment = self::factory()->post->create(
			array(
				'post_status'  => 'inherit',
				'post_type'    => 'attachment',
				'post_content' => 'The supported destination phrase appears in media.',
			)
		);
		$snapshot   = $this->finish_scan();
		$pairs      = array_map(
			static fn( array $opportunity ): string => $opportunity['src'] . ':' . $opportunity['dst'],
			$snapshot['opps']
		);
		$this->assertContains( $source . ':' . $target, $pairs );
		$this->assertStringNotContainsString( (string) $attachment, implode( ',', $pairs ) );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$this->assertSame( 'forbidden', BSM_Links::start_scan()->get_error_code() );
		$this->assertSame( array(), BSM_Links::snapshot() );
	}

	public function test_opportunities_exclude_self_existing_heading_ambiguous_and_duplicate_mentions(): void {
		$existing_target = $this->target( 'already linked phrase', 'Existing target' );
		$this->target( 'heading only phrase', 'Heading target' );
		$this->target( 'repeated mention phrase', 'Repeated target' );
		$this->target( 'ambiguous target phrase', 'Ambiguous target A' );
		$this->target( 'ambiguous target phrase', 'Ambiguous target B' );
		$self = $this->target( 'self reference phrase', 'Self target' );
		wp_update_post(
			array(
				'ID'           => $self,
				'post_content' => 'A self reference phrase cannot link to itself.',
			)
		);
		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => sprintf(
					'<a href="%1$s">already linked phrase</a><h2>heading only phrase</h2> repeated mention phrase and repeated mention phrase. ambiguous target phrase.',
					esc_url( get_permalink( $existing_target ) )
				),
			)
		);
		$snapshot = $this->finish_scan();
		$phrases  = array_column( $snapshot['opps'], 'phrase' );
		$this->assertNotContains( 'already linked phrase', $phrases );
		$this->assertNotContains( 'heading only phrase', $phrases );
		$this->assertNotContains( 'repeated mention phrase', $phrases );
		$this->assertNotContains( 'ambiguous target phrase', $phrases );
		$this->assertNotContains( 'self reference phrase', $phrases );
	}

	public function test_longer_overlapping_phrase_wins_deterministically(): void {
		$this->target( 'specific destination phrase', 'Specific target' );
		$this->target( 'destination phrase', 'Broad target' );
		$source = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'The specific destination phrase appears once.',
			)
		);
		$first  = $this->finish_scan();
		$second = $this->finish_scan();
		$filter = static function ( array $snapshot ) use ( $source ): array {
			return array_values(
				array_filter(
					$snapshot['opps'],
					static fn( array $opportunity ): bool => $source === $opportunity['src']
				)
			);
		};
		$this->assertSame( 'specific destination phrase', $filter( $first )[0]['phrase'] );
		$this->assertSame(
			array_column( $filter( $first ), 'dst' ),
			array_column( $filter( $second ), 'dst' )
		);
	}

	public function test_classic_insertion_revalidates_updates_and_reads_back(): void {
		$target = $this->target( 'classic destination phrase' );
		$source = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Use the classic destination phrase in this sentence.',
			)
		);
		$this->finish_scan();
		$before = count( wp_get_post_revisions( $source ) );
		$result = BSM_Links::insert_link( $source, $target, 'classic destination phrase' );
		$this->assertNotWPError( $result );
		$this->assertSame( 'content', $result['where'] );
		$this->assertStringContainsString( '<a href="' . get_permalink( $target ) . '">classic destination phrase</a>', get_post( $source )->post_content );
		$this->assertGreaterThan( $before, count( wp_get_post_revisions( $source ) ) );
	}

	public function test_classic_insertion_preserves_markup_backslashes_and_unicode_offsets(): void {
		$target   = $this->target( 'café destination phrase' );
		$source   = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => wp_slash( 'Keep C:\\docs and <iframe src="https://example.org/embed"></iframe> before café destination phrase.' ),
			)
		);
		$original = get_post( $source )->post_content;
		$this->finish_scan();

		$result = BSM_Links::insert_link( $source, $target, 'café destination phrase' );
		$stored = get_post( $source )->post_content;

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'C:\\docs', $stored );
		$this->assertStringContainsString( '<iframe src="https://example.org/embed"></iframe>', $stored );
		$this->assertStringContainsString( '<a href="' . get_permalink( $target ) . '">café destination phrase</a>', $stored );
		$this->assertSame( 1, substr_count( $stored, '<a ' ) );
		$this->assertNotSame( $original, $stored );
	}

	public function test_insertion_requires_site_and_object_capabilities_for_both_posts(): void {
		add_role(
			'links_manager',
			'Links Manager',
			array(
				'read'                 => true,
				'manage_options'       => true,
				'edit_posts'           => true,
				'edit_published_posts' => true,
				'publish_posts'        => true,
				'edit_others_posts'    => false,
			)
		);
		$manager = self::factory()->user->create( array( 'role' => 'links_manager' ) );
		$target  = $this->target( 'capability destination phrase' );
		$source  = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_author'  => $manager,
				'post_content' => 'Use the capability destination phrase here.',
			)
		);
		$this->finish_scan();
		wp_set_current_user( $manager );

		$this->assertTrue( current_user_can( 'edit_post', $source ) );
		$this->assertFalse( current_user_can( 'edit_post', $target ) );
		$result = BSM_Links::insert_link( $source, $target, 'capability destination phrase' );
		$this->assertSame( 'forbidden', $result->get_error_code() );
		$this->assertStringNotContainsString( '<a ', get_post( $source )->post_content );
		remove_role( 'links_manager' );
	}

	public function test_classic_insertion_rejects_stale_content_and_update_failure(): void {
		$target = $this->target( 'stale destination phrase' );
		$source = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Use the stale destination phrase here.',
			)
		);
		$this->finish_scan();
		wp_update_post(
			array(
				'ID'           => $source,
				'post_content' => 'The page changed.',
			)
		);
		$this->assertSame( 'stale_content', BSM_Links::insert_link( $source, $target, 'stale destination phrase' )->get_error_code() );

		wp_update_post(
			array(
				'ID'           => $source,
				'post_content' => 'Use the stale destination phrase here.',
			)
		);
		$this->finish_scan();
		add_filter( 'wp_insert_post_empty_content', '__return_true' );
		$result = BSM_Links::insert_link( $source, $target, 'stale destination phrase' );
		$this->assertWPError( $result );
		$this->assertStringNotContainsString( '<a ', get_post( $source )->post_content );
	}

	public function test_classic_read_back_mismatch_rolls_back_without_false_success(): void {
		$target   = $this->target( 'read back destination phrase' );
		$original = 'Use the read back destination phrase here.';
		$source   = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => $original,
			)
		);
		$this->finish_scan();
		add_filter(
			'wp_insert_post_data',
			static function ( array $data ): array {
				if ( false !== strpos( $data['post_content'], '<a ' ) ) {
					$data['post_content'] = 'Unexpected stored content.';
				}
				return $data;
			}
		);

		$result = BSM_Links::insert_link( $source, $target, 'read back destination phrase' );
		$this->assertSame( 'read_back_failed', $result->get_error_code() );
		$this->assertSame( $original, get_post( $source )->post_content );
	}

	public function test_elementor_nested_insertion_preserves_siblings_and_post_content(): void {
		$target = $this->target( 'elementor destination phrase' );
		$source = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Disposable Elementor mirror.',
			)
		);
		$tree   = array(
			array(
				'id'       => 'section',
				'elements' => array(
					array(
						'id'       => 'column',
						'elements' => array(
							array(
								'id'         => 'editable',
								'widgetType' => 'text-editor',
								'settings'   => array(
									'editor' => 'Use the elementor destination phrase here.',
									'color'  => '#fff',
								),
							),
							array(
								'id'         => 'sibling',
								'widgetType' => 'text-editor',
								'settings'   => array( 'editor' => 'Keep this sibling unchanged.' ),
							),
							array(
								'id'         => 'button',
								'widgetType' => 'button',
								'settings'   => array( 'text' => 'elementor destination phrase' ),
							),
						),
					),
				),
			),
		);
		update_post_meta( $source, '_elementor_edit_mode', 'builder' );
		update_post_meta( $source, '_elementor_data', wp_slash( wp_json_encode( $tree ) ) );
		$this->finish_scan();

		$result = BSM_Links::insert_link( $source, $target, 'elementor destination phrase' );
		$this->assertNotWPError( $result );
		$stored = json_decode( get_post_meta( $source, '_elementor_data', true ), true );
		$this->assertStringContainsString( '<a href="' . get_permalink( $target ) . '">elementor destination phrase</a>', $stored[0]['elements'][0]['elements'][0]['settings']['editor'] );
		$this->assertSame( $tree[0]['elements'][0]['elements'][1], $stored[0]['elements'][0]['elements'][1] );
		$this->assertSame( $tree[0]['elements'][0]['elements'][2], $stored[0]['elements'][0]['elements'][2] );
		$this->assertSame( '#fff', $stored[0]['elements'][0]['elements'][0]['settings']['color'] );
		$this->assertSame( 'Disposable Elementor mirror.', get_post( $source )->post_content );
	}

	public function test_elementor_rejects_malformed_unsupported_heading_and_oversized_data_without_fallback(): void {
		$this->target( 'elementor boundary phrase' );
		$malformed = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'elementor boundary phrase in the mirror',
			)
		);
		update_post_meta( $malformed, '_elementor_edit_mode', 'builder' );
		update_post_meta( $malformed, '_elementor_data', '{broken' );

		$heading = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'elementor boundary phrase in another mirror',
			)
		);
		update_post_meta( $heading, '_elementor_edit_mode', 'builder' );
		update_post_meta(
			$heading,
			'_elementor_data',
			wp_slash(
				wp_json_encode(
					array(
						array(
							'widgetType' => 'heading',
							'settings'   => array( 'text' => 'elementor boundary phrase' ),
						),
					)
				)
			)
		);

		$oversized = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'elementor boundary phrase in a third mirror',
			)
		);
		update_post_meta( $oversized, '_elementor_edit_mode', 'builder' );
		update_post_meta( $oversized, '_elementor_data', str_repeat( 'x', BSM_Links::EL_MAX_RAW + 1 ) );

		$snapshot = $this->finish_scan();
		$sources  = array_column( $snapshot['opps'], 'src' );
		$this->assertNotContains( $malformed, $sources );
		$this->assertNotContains( $heading, $sources );
		$this->assertNotContains( $oversized, $sources );
		$this->assertSame( 'elementor boundary phrase in the mirror', get_post( $malformed )->post_content );
	}

	public function test_elementor_depth_and_node_limits_are_enforced(): void {
		$this->target( 'elementor limits phrase' );
		$deep_tree = array(
			array(
				'widgetType' => 'text-editor',
				'settings'   => array( 'editor' => 'elementor limits phrase' ),
			),
		);
		for ( $depth = 0; $depth <= BSM_Links::EL_MAX_DEPTH; ++$depth ) {
			$deep_tree = array( array( 'elements' => $deep_tree ) );
		}
		$deep = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'elementor limits phrase in the mirror',
			)
		);
		update_post_meta( $deep, '_elementor_edit_mode', 'builder' );
		update_post_meta( $deep, '_elementor_data', wp_slash( wp_json_encode( $deep_tree ) ) );

		$wide_tree = array_fill(
			0,
			BSM_Links::EL_MAX_NODES + 1,
			array(
				'widgetType' => 'text-editor',
				'settings'   => array( 'editor' => 'unrelated text' ),
			)
		);
		$wide      = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'elementor limits phrase in another mirror',
			)
		);
		update_post_meta( $wide, '_elementor_edit_mode', 'builder' );
		update_post_meta( $wide, '_elementor_data', wp_slash( wp_json_encode( $wide_tree ) ) );

		$snapshot = $this->finish_scan();
		$sources  = array_column( $snapshot['opps'], 'src' );
		$this->assertNotContains( $deep, $sources );
		$this->assertNotContains( $wide, $sources );
		$this->assertStringNotContainsString( '<a ', get_post( $deep )->post_content );
		$this->assertStringNotContainsString( '<a ', get_post( $wide )->post_content );
	}

	public function test_snapshot_and_opportunity_caps_are_enforced(): void {
		$this->target( 'bounded destination phrase' );
		for ( $index = 0; $index < BSM_Links::PER_SOURCE + 5; ++$index ) {
			self::factory()->post->create(
				array(
					'post_status'  => 'publish',
					'post_content' => 'A bounded destination phrase appears once.',
				)
			);
		}
		$snapshot = $this->finish_scan();
		$this->assertLessThanOrEqual( BSM_Links::OPP_CAP, count( $snapshot['opps'] ) );
		$this->assertLessThanOrEqual( BSM_Links::LIST_CAP, count( $snapshot['orphans'] ) );
		global $wpdb;
		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", BSM_Links::OPTION )
		);
		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	public function test_per_source_opportunity_limit_is_enforced(): void {
		$phrases = array();
		for ( $index = 0; $index < BSM_Links::PER_SOURCE + 3; ++$index ) {
			$phrase    = 'bounded target phrase ' . self::number_word( $index );
			$phrases[] = $phrase;
			$this->target( $phrase, 'Target ' . $index );
		}
		$source               = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => implode( '. ', $phrases ) . '.',
			)
		);
		$snapshot             = $this->finish_scan();
		$source_opportunities = array_filter(
			$snapshot['opps'],
			static fn( array $opportunity ): bool => $source === $opportunity['src']
		);

		$this->assertCount( BSM_Links::PER_SOURCE, $source_opportunities );
	}

	private static function number_word( int $number ): string {
		return array( 'zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven' )[ $number ];
	}
}
