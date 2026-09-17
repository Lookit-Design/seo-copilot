<?php
/**
 * SEO-3 behavior coverage.
 *
 * @package Lookit_SEO_Copilot
 */

class Test_Lookit_SEO_Copilot_SEO3 extends WP_UnitTestCase {

	private int $admin_id;

	public function set_up(): void {
		parent::set_up();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
		delete_option( BSM_Reports::OPTION );
		delete_option( BSM_Reports::STATE_OPTION );
	}

	public function tear_down(): void {
		unregister_post_type( 'report_book' );
		delete_option( BSM_Reports::OPTION );
		delete_option( BSM_Reports::STATE_OPTION );
		parent::tear_down();
	}

	private function create_post( array $args = array(), array $meta = array() ): int {
		$post_id = self::factory()->post->create(
			array_merge(
				array(
					'post_status'  => 'publish',
					'post_title'   => 'Controlled report item',
					'post_content' => '<p>Short controlled content.</p>',
				),
				$args
			)
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
		return $post_id;
	}

	private function raw_score( WP_Post $post, array $duplicate_ids = array() ): float {
		$audit  = BSM_Health::audit_post( $post, $duplicate_ids );
		$earned = 0.0;
		$count  = 0;
		foreach ( $audit['groups'] as $group ) {
			foreach ( $group as $check ) {
				$earned += 'good' === $check['status'] ? 1 : ( 'warn' === $check['status'] ? 0.5 : 0 );
				++$count;
			}
		}
		return 100 * $earned / $count;
	}

	private function finish_scan() {
		$result = BSM_Reports::scan_batch( 'start', 0 );
		$guard  = 0;
		while ( ! is_wp_error( $result ) && empty( $result['complete'] ) && 20 > $guard ) {
			$result = BSM_Reports::scan_batch( $result['phase'], $result['offset'], $result['scan_id'] );
			++$guard;
		}
		$this->assertLessThan( 20, $guard );
		$this->assertNotWPError( $result );
		$this->assertTrue( $result['complete'] );
		return $result;
	}

	public function test_aggregate_totals_score_bands_and_point_projection_reconcile(): void {
		$weak_id   = $this->create_post( array( 'post_title' => 'Weak item' ) );
		$strong_id = $this->create_post(
			array(
				'post_title'   => 'Target phrase guide',
				'post_name'    => 'target-phrase-guide',
				'post_content' => '<h2>Target phrase details</h2><p>Target phrase ' . str_repeat( 'useful words ', 320 ) . '</p><a href="/">Home</a>',
			),
			array(
				BSM_META_KW    => 'target phrase',
				BSM_META_DESC  => 'Target phrase ' . str_repeat( 'description ', 10 ),
				BSM_META_TITLE => 'Target phrase guide',
			)
		);

		$result           = $this->finish_scan();
		$snapshot         = BSM_Reports::snapshot();
		$scores           = array( $this->raw_score( get_post( $weak_id ) ), $this->raw_score( get_post( $strong_id ) ) );
		$expected         = array_sum( $scores ) / count( $scores );
		$expected_buckets = array( 0, 0, 0, 0 );
		foreach ( $scores as $score ) {
			$bucket = 50 > $score ? 0 : ( 70 > $score ? 1 : ( 90 > $score ? 2 : 3 ) );
			++$expected_buckets[ $bucket ];
		}
		$payload = $result['snapshot'];

		$this->assertSame( 2, $snapshot['total'] );
		$this->assertEqualsWithDelta( $expected * 2, $snapshot['score_sum'], 0.0001 );
		$this->assertSame( $expected_buckets, $payload['buckets'] );
		$this->assertSame( round( $expected, 1 ), $payload['score'] );
		$this->assertEqualsWithDelta( 100 - $payload['score'], array_sum( array_column( $payload['checks'], 'points' ) ), 0.0001 );

		$points = array_column( $payload['checks'], 'points' );
		$sorted = $points;
		rsort( $sorted );
		$this->assertSame( $sorted, $points );
		$this->assertSame(
			min( 100, round( $payload['score'] + array_sum( array_slice( $points, 0, 3 ) ), 1 ) ),
			$payload['top_three']
		);
	}

	public function test_scan_is_bounded_and_aggregates_multiple_batches(): void {
		for ( $index = 0; $index < BSM_Reports::BATCH + 3; ++$index ) {
			$this->create_post( array( 'post_title' => 'Batch item ' . $index ) );
		}

		$first = BSM_Reports::scan_batch( 'start', 0 );
		$this->assertFalse( $first['complete'] );
		$this->assertSame( BSM_Reports::BATCH, $first['offset'] );
		$this->assertSame( 2 * ( BSM_Reports::BATCH + 3 ), $first['total'] );

		$result = $first;
		$calls  = 1;
		while ( empty( $result['complete'] ) ) {
			$result = BSM_Reports::scan_batch( $result['phase'], $result['offset'], $result['scan_id'] );
			++$calls;
		}
		$this->assertSame( 4, $calls );
		$this->assertSame( BSM_Reports::BATCH + 3, $result['snapshot']['total'] );
	}

	public function test_projection_rounding_distributes_the_exact_gap_without_negative_points(): void {
		$aggregate = array(
			'schema'     => BSM_Reports::SCHEMA,
			'version'    => BSM_VERSION,
			'generated'  => 1,
			'total'      => 3,
			'score_sum'  => 200,
			'issues_sum' => 10,
			'buckets'    => array( 0, 3, 0, 0 ),
			'types'      => array(),
			'worst'      => array(),
			'checks'     => array(),
		);
		for ( $index = 0; $index < 10; ++$index ) {
			$aggregate['checks'][ 'Check ' . $index ] = array(
				'fail' => 1,
				'warn' => 0,
				'good' => 2,
				'gain' => 10,
			);
		}

		$payload = BSM_Reports::payload( $aggregate );
		$points  = array_column( $payload['checks'], 'points' );
		$this->assertEqualsWithDelta( 100 - $payload['score'], array_sum( $points ), 0.0001 );
		$this->assertGreaterThanOrEqual( 0, min( $points ) );
	}

	public function test_empty_site_completes_and_persists_a_zero_item_snapshot(): void {
		$result = $this->finish_scan();

		$this->assertSame( 0, $result['snapshot']['total'] );
		$this->assertSame( 0.0, $result['snapshot']['score'] );
		$this->assertNotEmpty( BSM_Reports::snapshot()['generated'] );
	}

	public function test_stale_or_interrupted_batches_cannot_replace_last_snapshot(): void {
		$previous = array(
			'schema'    => BSM_Reports::SCHEMA,
			'version'   => BSM_VERSION,
			'generated' => 123,
			'total'     => 0,
		);
		add_option( BSM_Reports::OPTION, $previous, '', false );
		$this->create_post();
		$first            = BSM_Reports::scan_batch( 'start', 0 );
		$state            = get_option( BSM_Reports::STATE_OPTION );
		$state['updated'] = time() - BSM_Reports::STATE_TTL - 1;
		update_option( BSM_Reports::STATE_OPTION, $state, false );

		$expired = BSM_Reports::scan_batch( $first['phase'], $first['offset'], $first['scan_id'] );
		$this->assertWPError( $expired );
		$this->assertSame( 'stale_scan', $expired->get_error_code() );
		$this->assertSame( $previous, BSM_Reports::snapshot() );
		$this->assertFalse( get_option( BSM_Reports::STATE_OPTION ) );

		$fresh = BSM_Reports::scan_batch( 'start', 0 );
		$wrong = BSM_Reports::scan_batch( $fresh['phase'], $fresh['offset'] + 1, $fresh['scan_id'] );
		$this->assertWPError( $wrong );
		$this->assertSame( $fresh['offset'], get_option( BSM_Reports::STATE_OPTION )['offset'] );
	}

	public function test_private_items_and_non_administrators_cannot_read_or_mutate_reports(): void {
		$this->create_post( array( 'post_title' => 'Public item' ) );
		$this->create_post(
			array(
				'post_status' => 'private',
				'post_title'  => 'Private secret',
			)
		);
		$this->finish_scan();
		$this->assertSame( 1, BSM_Reports::snapshot()['total'] );
		$stored = get_option( BSM_Reports::OPTION );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$this->assertSame( array(), BSM_Reports::snapshot() );
		$denied = BSM_Reports::scan_batch( 'start', 0 );
		$this->assertWPError( $denied );
		$this->assertSame( 'forbidden', $denied->get_error_code() );
		$this->assertSame( $stored, get_option( BSM_Reports::OPTION ) );
		$this->assertFalse( get_option( BSM_Reports::STATE_OPTION ) );
	}

	public function test_supported_custom_public_post_types_are_scanned(): void {
		register_post_type(
			'report_book',
			array(
				'public'   => true,
				'show_ui'  => true,
				'label'    => 'Report Books',
				'labels'   => array( 'singular_name' => 'Report Book' ),
				'supports' => array( 'title', 'editor' ),
			)
		);
		$this->create_post(
			array(
				'post_type'  => 'report_book',
				'post_title' => 'Custom report item',
			)
		);

		$payload = $this->finish_scan()['snapshot'];
		$this->assertSame( 1, $payload['total'] );
		$this->assertSame( 'Report Book', $payload['types'][0]['label'] );
	}

	public function test_snapshot_and_scan_state_options_disable_autoload(): void {
		$this->create_post();
		$first = BSM_Reports::scan_batch( 'start', 0 );
		$this->assertFalse( $first['complete'] );

		global $wpdb;
		$state_autoload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
				BSM_Reports::STATE_OPTION
			)
		);
		$this->assertContains( $state_autoload, array( 'no', 'off', 'auto-off' ) );

		$this->finish_scan();
		$snapshot_autoload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
				BSM_Reports::OPTION
			)
		);
		$this->assertContains( $snapshot_autoload, array( 'no', 'off', 'auto-off' ) );
	}

	public function test_print_data_decodes_ampersands_and_snapshot_staleness_is_explicit(): void {
		$this->create_post( array( 'post_title' => 'Research &amp; Development' ) );
		$payload = $this->finish_scan()['snapshot'];
		$this->assertSame( 'Research & Development', $payload['worst'][0]['title'] );

		$stale = BSM_Reports::payload(
			array(
				'schema'     => 0,
				'version'    => 'old',
				'generated'  => 1,
				'total'      => 0,
				'score_sum'  => 0,
				'issues_sum' => 0,
			)
		);
		$this->assertTrue( $stale['stale'] );
	}

	public function test_reports_assets_include_print_and_hidden_progress_rules(): void {
		$css = file_get_contents( dirname( __DIR__ ) . '/assets/reports.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$js  = file_get_contents( dirname( __DIR__ ) . '/assets/reports.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.

		$this->assertStringContainsString( 'print-color-adjust:exact', $css );
		$this->assertStringContainsString( '#adminmenumain', $css );
		$this->assertStringContainsString( 'page-break-inside:avoid', $css );
		$this->assertStringContainsString( 'Prepared with Lookit SEO Copilot', $css );
		$this->assertStringContainsString( '.bsm-rep-progress[hidden]{display:none!important}', $css );
		$this->assertStringContainsString( 'progressEl.hidden = false', $js );
		$this->assertStringContainsString( 'progressEl.hidden = true', $js );
		$this->assertStringContainsString( 'Items touched', $js );
		$this->assertStringContainsString( 'Rough effort', $js );
	}

	public function test_reports_route_and_assets_are_admin_only_and_versioned(): void {
		$_GET['tab'] = 'reports';
		$this->assertSame( 'reports', bsm_resolve_tab() );
		$this->assertSame( array( 'view' ), bsm_view_params( 'reports' ) );
		bsm_enqueue_assets( 'toplevel_page_lookit-bulk-seo' );
		$this->assertSame( BSM_VERSION, wp_styles()->registered['bsm-reports']->ver );
		$this->assertSame( BSM_VERSION, wp_scripts()->registered['bsm-reports']->ver );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 'health', bsm_resolve_tab() );
		unset( $_GET['tab'] );
	}

	public function test_reports_do_not_expose_features_after_seo4(): void {
		$files  = file_get_contents( dirname( __DIR__ ) . '/assets/reports.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$files .= file_get_contents( dirname( __DIR__ ) . '/includes/class-bsm-reports.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$this->assertStringNotContainsString( 'Trends', $files );
		$this->assertStringNotContainsString( 'Content type filtering', $files );
		$this->assertStringNotContainsString( 'Links report', $files );
	}
}
