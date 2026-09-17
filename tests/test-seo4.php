<?php
/**
 * SEO-4 behavior coverage.
 *
 * @package Lookit_SEO_Copilot
 */

class Test_Lookit_SEO_Copilot_SEO4 extends WP_UnitTestCase {

	private int $admin_id;

	public function set_up(): void {
		parent::set_up();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
		delete_option( BSM_Reports::OPTION );
		delete_option( BSM_Reports::STATE_OPTION );
	}

	public function tear_down(): void {
		remove_all_filters( 'bsm_health_redirect_target' );
		remove_role( 'seo_task_limited' );
		delete_option( 'page_on_front' );
		delete_option( 'page_for_posts' );
		delete_option( BSM_Reports::OPTION );
		delete_option( BSM_Reports::STATE_OPTION );
		parent::tear_down();
	}

	private function post( string $slug, string $status = 'publish' ): WP_Post {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => $status,
				'post_title'   => '2026 The Complete Guide to Better Search Results',
				'post_name'    => $slug,
				'post_content' => '<p>Useful content.</p>',
			)
		);
		return get_post( $post_id );
	}

	private function url_check( WP_Post $post ): array {
		$audit = BSM_Health::audit_post( $post );
		foreach ( $audit['groups']['Titles & meta'] as $check ) {
			if ( 'URL length' === $check['label'] ) {
				return $check;
			}
		}
		return array();
	}

	private function finish_scan(): array {
		$result = BSM_Reports::scan_batch( 'start', 0 );
		while ( empty( $result['complete'] ) ) {
			$result = BSM_Reports::scan_batch( $result['phase'], $result['offset'], $result['scan_id'] );
		}
		return $result['snapshot'];
	}

	public function test_slug_suggestions_are_normalized_bounded_and_review_only(): void {
		$post = $this->post( str_repeat( 'long-', 15 ) . 'address' );
		$old  = $post->post_name;
		$list = BSM_Health::slug_candidates(
			$post,
			'Better Search Results',
			array( 'Unsafe <b>Candidate</b>', str_repeat( 'too-long-', 20 ) )
		);

		$this->assertSame( 'better-search-results', $list[0] );
		$this->assertContains( 'unsafe-candidate', $list );
		$this->assertLessThanOrEqual( 5, count( $list ) );
		$this->assertSame( $old, get_post( $post->ID )->post_name );
		foreach ( $list as $slug ) {
			$this->assertSame( sanitize_title( $slug ), $slug );
			$this->assertLessThanOrEqual( 60, strlen( $slug ) );
		}
	}

	public function test_slug_eligibility_excludes_special_and_unpublished_pages(): void {
		$front = $this->post( 'front' );
		$posts = $this->post( 'news' );
		$draft = $this->post( 'draft', 'draft' );
		update_option( 'page_on_front', $front->ID );
		update_option( 'page_for_posts', $posts->ID );

		$this->assertFalse( BSM_Health::slug_eligible( $front ) );
		$this->assertFalse( BSM_Health::slug_eligible( $posts ) );
		$this->assertFalse( BSM_Health::slug_eligible( $draft ) );
		$this->assertSame( array(), BSM_Health::slug_candidates( $draft, 'phrase', array() ) );
		$this->assertWPError( BSM_Health::apply_slug( $draft->ID, 'replacement' ) );
	}

	public function test_slug_apply_uses_wordpress_collision_and_old_slug_flows(): void {
		$this->post( 'preferred-slug' );
		$post   = $this->post( 'old-address' );
		$result = BSM_Health::apply_slug( $post->ID, 'Preferred Slug' );

		$this->assertNotWPError( $result );
		$this->assertSame( 'preferred-slug-2', $result['slug'] );
		$this->assertSame( 'preferred-slug-2', get_post( $post->ID )->post_name );
		$this->assertContains( 'old-address', get_post_meta( $post->ID, '_wp_old_slug', false ) );
		$this->assertSame( 'WordPress', $result['host'] );
	}

	public function test_slug_apply_requires_general_and_object_capabilities(): void {
		$post   = $this->post( 'protected-address' );
		$reader = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $reader );

		$result = BSM_Health::apply_slug( $post->ID, 'changed-address' );
		$this->assertWPError( $result );
		$this->assertSame( 'protected-address', get_post( $post->ID )->post_name );
	}

	public function test_slug_apply_returns_wordpress_update_errors_without_partial_success(): void {
		$post = $this->post( 'empty-post-address' );
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_title'   => '',
				'post_content' => '',
				'post_excerpt' => '',
			),
			array( 'ID' => $post->ID )
		);
		clean_post_cache( $post->ID );

		$result = BSM_Health::apply_slug( $post->ID, 'new-empty-address' );
		$this->assertWPError( $result );
		$this->assertSame( 'empty_content', $result->get_error_code() );
		$this->assertSame( 'empty-post-address', get_post( $post->ID )->post_name );
	}

	public function test_redirect_adapters_keep_order_fall_back_and_flatten_targets(): void {
		$calls  = array();
		$first  = static function ( string $from, string $to ) use ( &$calls ): bool {
			$calls[] = array( 'yoast', $from, $to );
			return false;
		};
		$second = static function ( string $from, string $to ) use ( &$calls ): bool {
			$calls[] = array( 'redirection', $from, $to );
			return true;
		};
		add_filter(
			'bsm_health_redirect_target',
			static function (): string {
				return '/final-target/';
			}
		);
		$host = BSM_Health::create_redirect(
			'/old/',
			'/intermediate/',
			array(
				array(
					'label'  => 'Yoast SEO Premium',
					'create' => $first,
				),
				array(
					'label'  => 'Redirection',
					'create' => $second,
				),
			)
		);

		$this->assertSame( 'Redirection', $host );
		$this->assertSame( array( 'yoast', 'redirection' ), array_column( $calls, 0 ) );
		$this->assertSame( '/final-target', $calls[0][2] );
		$this->assertStringNotContainsString( '.htaccess', file_get_contents( dirname( __DIR__ ) . '/includes/class-bsm-health.php' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source assertion.
	}

	public function test_production_resolvers_support_real_yoast_and_redirection_api_shapes(): void {
		$yoast_redirect = new class() {
			public function get_target(): string {
				return 'http://example.org/final-target/';
			}
		};
		$yoast_manager  = new class($yoast_redirect) {
			public string $origin = '';
			private object $redirect;

			public function __construct( object $redirect ) {
				$this->redirect = $redirect;
			}

			public function get_redirect( string $origin ): object {
				$this->origin = $origin;
				return $this->redirect;
			}
		};
		$this->assertSame( '/final-target', BSM_Health::resolve_yoast_redirect( '/old-path/', $yoast_manager ) );
		$this->assertSame( 'old-path', $yoast_manager->origin );

		$fallback_manager = new class() {
			public function get_redirects(): array {
				return array(
					new class() {
						public function get_origin(): string {
							return '/other/';
						}
						public function get_target(): string {
							return '/wrong/';
						}
					},
					new class() {
						public function get_origin(): string {
							return 'old-path';
						}
						public function get_target(): string {
							return '/fallback-target/';
						}
					},
				);
			}
		};
		$this->assertSame( '/fallback-target', BSM_Health::resolve_yoast_redirect( '/old-path/', $fallback_manager ) );

		$lookup_calls = array();
		$lookup       = static function ( string $url ) use ( &$lookup_calls ): array {
			$lookup_calls[] = array( $url, func_num_args() );
			return array(
				new class() {
					public function get_action_data(): array {
						return array();
					}
				},
				new class() {
					public function get_action_data(): array {
						return array( 'url' => 'http://example.org/redirection-target/' );
					}
				},
			);
		};
		$this->assertSame( '/redirection-target', BSM_Health::resolve_redirection_redirect( '/old-path/', $lookup ) );
		$this->assertSame( array( '/old-path', 1 ), $lookup_calls[0] );
		$this->assertSame( '/same-site', BSM_Health::normalize_redirect_path( 'http://example.org/same-site/' ) );
		$this->assertSame( 'https://external.example/path/', BSM_Health::normalize_redirect_path( 'https://external.example/path/' ) );
	}

	public function test_redirect_chain_traversal_is_bounded_and_stops_loops(): void {
		$resolved = 0;
		$created  = '';
		$adapter  = array(
			'label'   => 'Resolver',
			'resolve' => static function () use ( &$resolved ): string {
				++$resolved;
				return '/step-' . $resolved . '/';
			},
			'create'  => static function ( string $from, string $to ) use ( &$created ): bool {
				unset( $from );
				$created = $to;
				return true;
			},
		);
		BSM_Health::create_redirect( '/old/', '/start/', array( $adapter ) );
		$this->assertSame( 5, $resolved );
		$this->assertSame( '/step-5', $created );

		$looped  = 0;
		$adapter = array(
			'label'   => 'Loop resolver',
			'resolve' => static function ( string $target ) use ( &$looped ): string {
				++$looped;
				return '/middle' === $target ? '/final/' : '/middle/';
			},
			'create'  => static function (): bool {
				return true;
			},
		);
		BSM_Health::create_redirect( '/old/', '/middle/', array( $adapter ) );
		$this->assertSame( 2, $looped );
	}

	/**
	 * @dataProvider url_threshold_provider
	 */
	public function test_url_length_thresholds( int $length, string $status ): void {
		$post = $this->post( str_repeat( 'a', $length ) );
		$this->assertSame( $status, $this->url_check( $post )['status'] );
	}

	public function url_threshold_provider(): array {
		return array(
			'40 passes' => array( 40, 'good' ),
			'41 warns'  => array( 41, 'warn' ),
			'70 warns'  => array( 70, 'warn' ),
			'71 fails'  => array( 71, 'fail' ),
		);
	}

	public function test_report_url_distribution_longest_order_simulator_points_and_staleness(): void {
		$this->post( str_repeat( 'a', 40 ) );
		$this->post( str_repeat( 'b', 41 ) );
		$this->post( str_repeat( 'c', 70 ) );
		$this->post( str_repeat( 'd', 71 ) );
		$snapshot = $this->finish_scan();

		$this->assertSame( array( 0, 1, 2, 1 ), $snapshot['urls']['bands'] );
		$this->assertSame( array( 71, 70, 41 ), array_column( $snapshot['urls']['long'], 'len' ) );
		$url_fix = wp_list_filter( $snapshot['checks'], array( 'label' => 'URL length' ) );
		$this->assertCount( 1, $url_fix );
		$this->assertGreaterThan( 0, reset( $url_fix )['points'] );
		$this->assertTrue(
			BSM_Reports::snapshot_is_stale(
				array(
					'schema'  => 1,
					'version' => '3.47.2',
				)
			)
		);
	}

	public function test_task_state_is_per_user_and_filters_deleted_items(): void {
		$post = $this->post( str_repeat( 'task-', 12 ) );
		$this->finish_scan();
		$slug = BSM_Reports::check_slug( 'URL length' );

		$this->assertSame( array( $slug ), BSM_Tasks::set_checks( array( $slug, 'unknown' ) ) );
		$this->assertNotWPError( BSM_Tasks::toggle( $slug, $post->ID, true ) );
		$this->assertSame( 1, BSM_Tasks::done_counts()[ $slug ] );

		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $other );
		$this->assertSame( array(), BSM_Tasks::checks() );
		$this->assertSame( array(), BSM_Tasks::done() );

		wp_set_current_user( $this->admin_id );
		wp_delete_post( $post->ID, true );
		$this->assertSame( array(), BSM_Tasks::done() );
		$this->assertWPError( BSM_Tasks::toggle( $slug, $post->ID, true ) );
		$this->assertSame( array(), BSM_Tasks::reset() );
	}

	public function test_task_items_filter_posts_the_requester_cannot_edit(): void {
		$post = $this->post( str_repeat( 'restricted-', 8 ) );
		$this->finish_scan();
		$slug = BSM_Reports::check_slug( 'URL length' );
		add_role(
			'seo_task_limited',
			'SEO Task Limited',
			array(
				'read'           => true,
				'edit_posts'     => true,
				'manage_options' => true,
			)
		);
		$user_id = self::factory()->user->create( array( 'role' => 'seo_task_limited' ) );
		wp_set_current_user( $user_id );

		BSM_Tasks::set_checks( array( $slug ) );
		$items = BSM_Tasks::items( $slug );
		$this->assertNotWPError( $items );
		$this->assertSame( array(), $items['items'] );
		$this->assertWPError( BSM_Tasks::toggle( $slug, $post->ID, true ) );
	}

	public function test_task_progress_uses_all_affected_items_not_the_capped_list(): void {
		update_option(
			BSM_Reports::OPTION,
			array(
				'checks' => array(
					'URL length' => array(
						'fail' => 5000,
						'warn' => 0,
						'good' => 0,
					),
				),
			),
			false
		);
		$slug = BSM_Reports::check_slug( 'URL length' );
		BSM_Tasks::set_checks( array( $slug ) );
		$progress = BSM_Tasks::progress(
			array(
				array(
					'slug'     => $slug,
					'affected' => 5000,
					'listed'   => 400,
					'points'   => 10,
				),
			),
			array( $slug => 400 )
		);

		$this->assertSame( 5000, $progress['total'] );
		$this->assertSame( 400, $progress['done'] );
		$this->assertSame( 8, $progress['percent'] );
		$this->assertSame( 0.8, $progress['points'] );
		$this->assertSame( 8, $progress['groups'][ $slug ]['percent'] );
	}

	public function test_task_changes_preserve_hidden_check_progress(): void {
		$first  = $this->post( 'first-task' );
		$second = $this->post( 'second-task' );
		update_option(
			BSM_Reports::OPTION,
			array(
				'checks' => array(
					'First check'  => array( 'fail' => 1 ),
					'Second check' => array( 'fail' => 1 ),
				),
				'items'  => array(
					'First check'  => array(
						'f' => array( $first->ID ),
						'w' => array(),
					),
					'Second check' => array(
						'f' => array( $second->ID ),
						'w' => array(),
					),
				),
			),
			false
		);
		$first_slug  = BSM_Reports::check_slug( 'First check' );
		$second_slug = BSM_Reports::check_slug( 'Second check' );
		BSM_Tasks::set_checks( array( $first_slug, $second_slug ) );
		update_user_meta( $this->admin_id, BSM_Tasks::META_DONE, array( $second_slug => array( $second->ID ) ) );

		BSM_Tasks::set_checks( array( $first_slug ) );
		$this->assertNotWPError( BSM_Tasks::toggle( $first_slug, $first->ID, true ) );
		$stored = get_user_meta( $this->admin_id, BSM_Tasks::META_DONE, true );
		$this->assertSame( array( $second->ID ), $stored[ $second_slug ] );

		BSM_Tasks::reset( $first_slug );
		$stored = get_user_meta( $this->admin_id, BSM_Tasks::META_DONE, true );
		$this->assertSame( array( $second->ID ), $stored[ $second_slug ] );
	}

	public function test_pre_task_snapshots_fail_closed(): void {
		$payload = BSM_Reports::payload(
			array(
				'schema'     => 2,
				'version'    => '3.49.0',
				'generated'  => 1,
				'total'      => 1,
				'score_sum'  => 50,
				'issues_sum' => 1,
				'checks'     => array(),
				'types'      => array(),
				'buckets'    => array( 0, 1, 0, 0 ),
				'worst'      => array(),
			)
		);
		$this->assertFalse( $payload['tasks_ready'] );
		$runtime = file_get_contents( dirname( __DIR__ ) . '/assets/reports.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source assertion.
		$this->assertStringContainsString( 'This report predates Task Manager', $runtime );
	}

	public function test_report_item_lists_are_capped_per_status(): void {
		for ( $index = 0; $index < BSM_Reports::ITEM_CAP + 2; ++$index ) {
			$this->post( str_repeat( 'f', 71 ) . '-' . $index );
			$this->post( str_repeat( 'w', 41 ) . '-' . $index );
		}
		$this->finish_scan();
		$items = BSM_Reports::check_items( BSM_Reports::check_slug( 'URL length' ) );

		$this->assertCount( BSM_Reports::ITEM_CAP, $items['fail'] );
		$this->assertCount( BSM_Reports::ITEM_CAP, $items['warn'] );
	}

	public function test_routes_assets_version_and_scope_are_exact(): void {
		$this->assertSame( '3.50.1', BSM_VERSION );
		$this->assertTrue( has_action( 'wp_ajax_bsm_health_apply_slug' ) );
		$this->assertTrue( has_action( 'wp_ajax_bsm_task_items' ) );
		$runtime = file_get_contents( dirname( __DIR__ ) . '/assets/reports.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source assertion.
		$this->assertStringContainsString( 'Task manager', $runtime );
		$this->assertStringNotContainsString( 'Trends', $runtime );
		$this->assertStringNotContainsString( 'Target content', $runtime );
	}
}
