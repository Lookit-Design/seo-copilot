<?php
/**
 * Website Report and Impact Simulator.
 *
 * @package Lookit_SEO_Copilot
 */

defined( 'ABSPATH' ) || exit;

class BSM_Reports {

	const OPTION       = 'bsm_report_snapshot';
	const STATE_OPTION = 'bsm_report_scan_state';
	const HISTORY      = 'bsm_report_history';
	const SCHEMA       = 4;
	const BATCH        = 50;
	const STATE_TTL    = 900;
	const ITEM_CAP     = 400;
	const HISTORY_MAX  = 50;
	const SCORE_CAP    = 3000;

	public static function check_slug( string $label ): string {
		$slug = sanitize_title( $label );
		return '' !== $slug ? $slug : md5( $label );
	}

	public static function snapshot(): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array();
		}
		$snapshot = get_option( self::OPTION, array() );
		return is_array( $snapshot ) ? $snapshot : array();
	}

	public static function snapshot_is_stale( array $snapshot ): bool {
		return ! empty( $snapshot ) && (
			self::SCHEMA !== (int) ( $snapshot['schema'] ?? 0 ) ||
			BSM_VERSION !== (string) ( $snapshot['version'] ?? '' )
		);
	}

	public static function check_items( string $slug ): array {
		$snapshot = self::snapshot();
		foreach ( (array) ( $snapshot['items'] ?? array() ) as $label => $lists ) {
			if ( self::check_slug( (string) $label ) !== sanitize_key( $slug ) ) {
				continue;
			}
			return array(
				'label' => (string) $label,
				'fail'  => array_slice( array_values( array_unique( array_map( 'absint', (array) ( $lists['f'] ?? array() ) ) ) ), 0, self::ITEM_CAP ),
				'warn'  => array_slice( array_values( array_unique( array_map( 'absint', (array) ( $lists['w'] ?? array() ) ) ) ), 0, self::ITEM_CAP ),
			);
		}
		return array();
	}

	private static function store_option( string $name, array $value ): void {
		if ( false === get_option( $name, false ) ) {
			add_option( $name, $value, '', false );
			return;
		}
		update_option( $name, $value, false );
	}

	public static function views(): array {
		return array(
			'report'    => 'Website Report',
			'simulator' => 'Impact Simulator',
			'tasks'     => 'Task Manager',
			'trends'    => 'Trends',
			'focus'     => 'Focus',
		);
	}

	public static function nav( string $view, array $snapshot = array(), bool $run = true ): void {
		$items = current_user_can( 'manage_options' )
			? self::views()
			: array( 'focus' => 'Focus' );
		?>
		<div class="bsm-rep-nav">
			<?php foreach ( $items as $key => $label ) : ?>
				<?php
				$url = add_query_arg(
					array(
						'page' => 'lookit-bulk-seo',
						'tab'  => 'reports',
						'view' => $key,
					),
					admin_url( 'admin.php' )
				);
				?>
				<a href="<?php echo esc_url( $url ); ?>" class="bsm-rep-navlink<?php echo $view === $key ? ' -active' : ''; ?>">
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
			<?php if ( $run && current_user_can( 'manage_options' ) ) : ?>
				<div class="bsm-rep-navright">
					<span class="bsm-rep-stamp" id="bsm-rep-stamp">
						<?php
						if ( self::snapshot_is_stale( $snapshot ) ) {
							esc_html_e( 'Audit is out of date', 'bulk-keyphrase-manager' );
						} elseif ( ! empty( $snapshot['generated'] ) ) {
							printf(
								/* translators: %s: Human-readable elapsed time. */
								esc_html__( 'Last run %s ago', 'bulk-keyphrase-manager' ),
								esc_html( human_time_diff( (int) $snapshot['generated'] ) )
							);
						} else {
							esc_html_e( 'No audit run yet', 'bulk-keyphrase-manager' );
						}
						?>
					</span>
					<button type="button" class="button button-primary" id="bsm-rep-run">
						<?php echo empty( $snapshot ) ? esc_html__( 'Run audit', 'bulk-keyphrase-manager' ) : esc_html__( 'Re-run audit', 'bulk-keyphrase-manager' ); ?>
					</button>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'bulk-keyphrase-manager' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selection.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'report';
		if ( ! in_array( $view, array( 'report', 'simulator', 'tasks', 'trends' ), true ) ) {
			$view = 'report';
		}

		$snapshot = self::snapshot();
		?>
		<div class="wrap" id="bsm-wrap">
			<?php bsm_topbar(); ?>
			<?php bsm_render_tabs( 'reports' ); ?>
			<div class="bsm-rep-wrap">
				<?php self::nav( $view, $snapshot ); ?>
				<div class="bsm-rep-progress" id="bsm-rep-progress" hidden>
					<div class="bsm-rep-track"><div class="bsm-rep-fill" id="bsm-rep-fill"></div></div>
					<span id="bsm-rep-count"><?php esc_html_e( 'Starting…', 'bulk-keyphrase-manager' ); ?></span>
				</div>
				<?php if ( empty( $snapshot ) ) : ?>
					<div class="bsm-rep-empty" id="bsm-rep-empty">
						<h2><?php esc_html_e( 'Build a report from your site', 'bulk-keyphrase-manager' ); ?></h2>
						<p><?php esc_html_e( 'The audit scans published content in small batches and stores one site-wide aggregate.', 'bulk-keyphrase-manager' ); ?></p>
					</div>
				<?php endif; ?>
				<div id="bsm-rep-report" class="bsm-rep-view<?php echo 'report' === $view ? '' : ' bsm-rep-hidden'; ?>"></div>
				<div id="bsm-rep-sim" class="bsm-rep-view<?php echo 'simulator' === $view ? '' : ' bsm-rep-hidden'; ?>"></div>
				<div id="bsm-rep-tasks" class="bsm-rep-view<?php echo 'tasks' === $view ? '' : ' bsm-rep-hidden'; ?>"></div>
				<div id="bsm-rep-trends" class="bsm-rep-view<?php echo 'trends' === $view ? '' : ' bsm-rep-hidden'; ?>"></div>
			</div>
		</div>
		<?php
	}

	public static function types(): array {
		$available = function_exists( 'bsm_get_post_types' ) ? array_keys( bsm_get_post_types() ) : array( 'post', 'page' );
		$types     = array();
		foreach ( $available as $type ) {
			$type = sanitize_key( $type );
			if ( '' !== $type && post_type_exists( $type ) && is_post_type_viewable( $type ) ) {
				$types[] = $type;
			}
		}
		return array_values( array_unique( $types ) );
	}

	public static function blank_aggregate(): array {
		return array(
			'total'      => 0,
			'score_sum'  => 0.0,
			'issues_sum' => 0,
			'checks'     => array(),
			'items'      => array(),
			'types'      => array(),
			'slices'     => array(),
			'buckets'    => array( 0, 0, 0, 0 ),
			'worst'      => array(),
			'url_n'      => 0,
			'url_sum'    => 0,
			'url_bands'  => array( 0, 0, 0, 0 ),
			'url_long'   => array(),
			'scores'     => array(),
		);
	}

	private static function new_state(): array {
		return array(
			'scan_id'    => wp_generate_uuid4(),
			'user_id'    => get_current_user_id(),
			'started'    => time(),
			'updated'    => time(),
			'phase'      => 'keyphrases',
			'offset'     => 0,
			'total'      => null,
			'types'      => self::types(),
			'keyphrases' => array(),
			'aggregate'  => self::blank_aggregate(),
		);
	}

	private static function query( array $state, bool $ids_only ): WP_Query {
		$args = array(
			'post_type'              => $state['types'],
			'post_status'            => 'publish',
			'posts_per_page'         => self::BATCH,
			'offset'                 => (int) $state['offset'],
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'perm'                   => 'editable',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => null !== $state['total'],
			'update_post_term_cache' => false,
		);
		if ( $ids_only ) {
			$args['fields'] = 'ids';
		}
		return new WP_Query( $args );
	}

	private static function validate_state( string $phase, int $offset, string $scan_id ) {
		$state = get_option( self::STATE_OPTION, array() );
		if (
			! is_array( $state ) ||
			empty( $state['scan_id'] ) ||
			time() - (int) ( $state['updated'] ?? 0 ) > self::STATE_TTL
		) {
			delete_option( self::STATE_OPTION );
			return new WP_Error( 'stale_scan', __( 'The interrupted audit expired. Start a new audit.', 'bulk-keyphrase-manager' ) );
		}
		if (
			! hash_equals( (string) $state['scan_id'], $scan_id ) ||
			get_current_user_id() !== (int) $state['user_id'] ||
			$phase !== (string) $state['phase'] ||
			$offset !== (int) $state['offset']
		) {
			return new WP_Error( 'stale_scan', __( 'This audit request is stale. Start a new audit.', 'bulk-keyphrase-manager' ) );
		}
		return $state;
	}

	public static function scan_batch( string $phase, int $offset, string $scan_id = '' ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'bulk-keyphrase-manager' ) );
		}

		$phase  = sanitize_key( $phase );
		$offset = max( 0, $offset );
		if ( 'start' === $phase ) {
			if ( 0 !== $offset || '' !== $scan_id ) {
				return new WP_Error( 'invalid_request', __( 'Invalid audit start request.', 'bulk-keyphrase-manager' ) );
			}
			$state = self::new_state();
			self::store_option( self::STATE_OPTION, $state );
		} elseif ( in_array( $phase, array( 'keyphrases', 'audit' ), true ) ) {
			$state = self::validate_state( $phase, $offset, sanitize_text_field( $scan_id ) );
			if ( is_wp_error( $state ) ) {
				return $state;
			}
		} else {
			return new WP_Error( 'invalid_phase', __( 'Invalid audit phase.', 'bulk-keyphrase-manager' ) );
		}

		$ids_only = 'keyphrases' === $state['phase'];
		$query    = self::query( $state, $ids_only );
		if ( null === $state['total'] ) {
			$state['total'] = (int) $query->found_posts;
		}

		if ( $ids_only ) {
			foreach ( $query->posts as $post_id ) {
				if ( ! current_user_can( 'edit_post', (int) $post_id ) ) {
					continue;
				}
				$keyphrase = mb_strtolower( trim( (string) get_post_meta( (int) $post_id, BSM_META_KW, true ) ) );
				if ( '' !== $keyphrase ) {
					$state['keyphrases'][ $keyphrase ] = 1 + (int) ( $state['keyphrases'][ $keyphrase ] ?? 0 );
				}
			}
		} else {
			foreach ( $query->posts as $post ) {
				if ( ! current_user_can( 'edit_post', $post->ID ) ) {
					continue;
				}
				self::fold_post( $state['aggregate'], $post, $state['keyphrases'] );
			}
			wp_reset_postdata();
		}

		$read            = count( $query->posts );
		$state['offset'] = (int) $state['offset'] + $read;
		$phase_complete  = $state['offset'] >= (int) $state['total'] || self::BATCH > $read;

		if ( $phase_complete && 'keyphrases' === $state['phase'] ) {
			$state['phase']  = 'audit';
			$state['offset'] = 0;
		} elseif ( $phase_complete ) {
			$snapshot = array_merge(
				$state['aggregate'],
				array(
					'schema'    => self::SCHEMA,
					'version'   => BSM_VERSION,
					'generated' => time(),
				)
			);
			self::record_run( $snapshot );
			unset( $snapshot['scores'] );
			self::store_option( self::OPTION, $snapshot );
			delete_option( self::STATE_OPTION );
			return array(
				'complete' => true,
				'done'     => 2 * (int) $state['total'],
				'total'    => 2 * (int) $state['total'],
				'snapshot' => self::payload( $snapshot ),
				'history'  => self::history_payload(),
			);
		}

		$state['updated'] = time();
		self::store_option( self::STATE_OPTION, $state );
		$done = 'audit' === $state['phase'] ? (int) $state['total'] + (int) $state['offset'] : (int) $state['offset'];
		return array(
			'complete' => false,
			'done'     => $done,
			'total'    => 2 * (int) $state['total'],
			'phase'    => $state['phase'],
			'offset'   => (int) $state['offset'],
			'scan_id'  => (string) $state['scan_id'],
			'snapshot' => null,
		);
	}

	private static function fold_post( array &$aggregate, WP_Post $post, array $keyphrases ): void {
		$keyphrase = mb_strtolower( trim( (string) get_post_meta( $post->ID, BSM_META_KW, true ) ) );
		$duplicate = '' !== $keyphrase && 1 < (int) ( $keyphrases[ $keyphrase ] ?? 0 );
		$audit     = BSM_Health::audit_post( $post, $duplicate ? array( $post->ID ) : array() );
		$checks    = array();
		$earned    = 0.0;
		foreach ( $audit['groups'] as $group ) {
			foreach ( $group as $check ) {
				$checks[] = $check;
				$earned  += self::status_value( $check['status'] );
			}
		}
		if ( empty( $checks ) ) {
			return;
		}

		$score = 100 * $earned / count( $checks );
		++$aggregate['total'];
		$aggregate['score_sum']  += $score;
		$aggregate['issues_sum'] += count( $audit['issues'] );
		if ( count( $aggregate['scores'] ) < self::SCORE_CAP ) {
			$aggregate['scores'][ $post->ID ] = round( $score, 1 );
		}

		$type = $post->post_type;
		if ( ! isset( $aggregate['types'][ $type ] ) ) {
			$type_object                 = get_post_type_object( $type );
			$aggregate['types'][ $type ] = array(
				'label' => $type_object ? $type_object->labels->singular_name : $type,
				'n'     => 0,
				'sum'   => 0.0,
			);
		}
		++$aggregate['types'][ $type ]['n'];
		$aggregate['types'][ $type ]['sum'] += $score;
		if ( ! isset( $aggregate['slices'][ $type ] ) ) {
			$aggregate['slices'][ $type ] = array(
				'label'      => $aggregate['types'][ $type ]['label'],
				'core'       => in_array( $type, array( 'page', 'post' ), true ),
				'total'      => 0,
				'score_sum'  => 0.0,
				'issues_sum' => 0,
				'checks'     => array(),
				'buckets'    => array( 0, 0, 0, 0 ),
				'worst'      => array(),
				'url_n'      => 0,
				'url_sum'    => 0,
				'url_bands'  => array( 0, 0, 0, 0 ),
				'url_long'   => array(),
			);
		}
		$slice = &$aggregate['slices'][ $type ];
		++$slice['total'];
		$slice['score_sum']  += $score;
		$slice['issues_sum'] += count( $audit['issues'] );

		$bucket = 50 > $score ? 0 : ( 70 > $score ? 1 : ( 90 > $score ? 2 : 3 ) );
		++$aggregate['buckets'][ $bucket ];
		++$slice['buckets'][ $bucket ];

		foreach ( $checks as $check ) {
			$label = $check['label'];
			if ( ! isset( $aggregate['checks'][ $label ] ) ) {
				$aggregate['checks'][ $label ] = array(
					'fail' => 0,
					'warn' => 0,
					'good' => 0,
					'gain' => 0.0,
				);
			}
			++$aggregate['checks'][ $label ][ $check['status'] ];
			$aggregate['checks'][ $label ]['gain'] += 100 * ( 1 - self::status_value( $check['status'] ) ) / count( $checks );
			if ( ! isset( $slice['checks'][ $label ] ) ) {
				$slice['checks'][ $label ] = array(
					'fail' => 0,
					'warn' => 0,
					'good' => 0,
					'gain' => 0.0,
				);
			}
			++$slice['checks'][ $label ][ $check['status'] ];
			$slice['checks'][ $label ]['gain'] += 100 * ( 1 - self::status_value( $check['status'] ) ) / count( $checks );
			if ( 'good' !== $check['status'] ) {
				if ( ! isset( $aggregate['items'][ $label ] ) ) {
					$aggregate['items'][ $label ] = array(
						'f' => array(),
						'w' => array(),
					);
				}
				$item_bucket = 'fail' === $check['status'] ? 'f' : 'w';
				if ( count( $aggregate['items'][ $label ][ $item_bucket ] ) < self::ITEM_CAP ) {
					$aggregate['items'][ $label ][ $item_bucket ][] = (int) $post->ID;
				}
			}
		}

		$slug = (string) $post->post_name;
		if ( '' !== $slug ) {
			$slug_length = strlen( $slug );
			++$aggregate['url_n'];
			$aggregate['url_sum'] += $slug_length;
			++$slice['url_n'];
			$slice['url_sum'] += $slug_length;
			$url_bucket        = $slug_length <= 30 ? 0 : ( $slug_length <= BSM_Health::URL_OK ? 1 : ( $slug_length <= BSM_Health::URL_MAX ? 2 : 3 ) );
			++$aggregate['url_bands'][ $url_bucket ];
			++$slice['url_bands'][ $url_bucket ];
			if ( $slug_length > BSM_Health::URL_OK ) {
				$url_row                 = array(
					'title' => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ),
					'slug'  => $slug,
					'len'   => $slug_length,
					'words' => count( array_filter( explode( '-', $slug ) ) ),
					'edit'  => (string) add_query_arg(
						array(
							'page'       => 'lookit-bulk-seo',
							'tab'        => 'health',
							'audit_post' => $post->ID,
						),
						admin_url( 'admin.php' )
					),
					'type'  => $aggregate['types'][ $type ]['label'],
				);
				$aggregate['url_long'][] = $url_row;
				$slice['url_long'][]     = $url_row;
				usort(
					$aggregate['url_long'],
					static function ( array $left, array $right ): int {
						$length = $right['len'] <=> $left['len'];
						return 0 !== $length ? $length : strcmp( $left['slug'], $right['slug'] );
					}
				);
				$aggregate['url_long'] = array_slice( $aggregate['url_long'], 0, 12 );
				usort(
					$slice['url_long'],
					static function ( array $left, array $right ): int {
						$length = $right['len'] <=> $left['len'];
						return 0 !== $length ? $length : strcmp( $left['slug'], $right['slug'] );
					}
				);
				$slice['url_long'] = array_slice( $slice['url_long'], 0, 12 );
			}
		}

		$worst_row            = array(
			'title'  => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ),
			'edit'   => (string) get_edit_post_link( $post->ID, 'raw' ),
			'score'  => round( $score, 1 ),
			'issues' => count( $audit['issues'] ),
			'type'   => $aggregate['types'][ $type ]['label'],
		);
		$aggregate['worst'][] = $worst_row;
		$slice['worst'][]     = $worst_row;
		usort(
			$aggregate['worst'],
			static function ( array $left, array $right ): int {
				return $left['score'] <=> $right['score'];
			}
		);
		$aggregate['worst'] = array_slice( $aggregate['worst'], 0, 12 );
		usort(
			$slice['worst'],
			static function ( array $left, array $right ): int {
				return $left['score'] <=> $right['score'];
			}
		);
		$slice['worst'] = array_slice( $slice['worst'], 0, 12 );
		unset( $slice );
	}

	private static function status_value( string $status ): float {
		return 'good' === $status ? 1.0 : ( 'warn' === $status ? 0.5 : 0.0 );
	}

	public static function payload( array $aggregate ): array {
		$total = (int) ( $aggregate['total'] ?? 0 );
		$score = $total ? round( (float) $aggregate['score_sum'] / $total, 1 ) : 0.0;

		$checks = array();
		foreach ( (array) ( $aggregate['checks'] ?? array() ) as $label => $check ) {
			$affected = (int) $check['fail'] + (int) $check['warn'];
			if ( 0 === $affected ) {
				continue;
			}
			$checks[] = array(
				'label'    => $label,
				'slug'     => self::check_slug( (string) $label ),
				'fail'     => (int) $check['fail'],
				'warn'     => (int) $check['warn'],
				'good'     => (int) $check['good'],
				'affected' => $affected,
				'listed'   => count( (array) ( $aggregate['items'][ $label ]['f'] ?? array() ) ) + count( (array) ( $aggregate['items'][ $label ]['w'] ?? array() ) ),
				'raw'      => (float) $check['gain'] / max( 1, $total ),
			);
		}
		usort(
			$checks,
			static function ( array $left, array $right ): int {
				return $right['raw'] <=> $left['raw'];
			}
		);

		$target_tenths = $checks ? (int) round( ( 100 - $score ) * 10 ) : 0;
		$given_tenths  = 0;
		foreach ( $checks as $index => &$check ) {
			$raw_tenths         = max( 0, $check['raw'] * 10 );
			$check['tenths']    = (int) floor( $raw_tenths + 0.0000001 );
			$check['remainder'] = $raw_tenths - $check['tenths'];
			$check['index']     = $index;
			$given_tenths      += $check['tenths'];
		}
		unset( $check );
		$remainders = $checks;
		usort(
			$remainders,
			static function ( array $left, array $right ): int {
				return $right['remainder'] <=> $left['remainder'];
			}
		);
		for ( $index = 0; $index < $target_tenths - $given_tenths; ++$index ) {
			++$checks[ $remainders[ $index % max( 1, count( $remainders ) ) ]['index'] ]['tenths'];
		}
		foreach ( $checks as &$check ) {
			$check['points'] = $check['tenths'] / 10;
			unset( $check['raw'], $check['tenths'], $check['remainder'], $check['index'] );
		}
		unset( $check );
		usort(
			$checks,
			static function ( array $left, array $right ): int {
				$points = $right['points'] <=> $left['points'];
				return 0 !== $points ? $points : strcmp( $left['label'], $right['label'] );
			}
		);

		$top_three_points = array_sum( array_column( array_slice( $checks, 0, 3 ), 'points' ) );
		$types            = array();
		foreach ( (array) ( $aggregate['types'] ?? array() ) as $type ) {
			$types[] = array(
				'label' => $type['label'],
				'n'     => (int) $type['n'],
				'avg'   => round( (float) $type['sum'] / max( 1, (int) $type['n'] ), 1 ),
			);
		}
		usort(
			$types,
			static function ( array $left, array $right ): int {
				return $right['n'] <=> $left['n'];
			}
		);

		$slices = array();
		foreach ( (array) ( $aggregate['slices'] ?? array() ) as $key => $slice ) {
			$slice_total = (int) ( $slice['total'] ?? 0 );
			if ( 0 === $slice_total ) {
				continue;
			}
			$slice_checks = array();
			foreach ( (array) ( $slice['checks'] ?? array() ) as $label => $check ) {
				$slice_checks[] = array(
					'label' => (string) $label,
					'slug'  => self::check_slug( (string) $label ),
					'fail'  => (int) $check['fail'],
					'warn'  => (int) $check['warn'],
					'good'  => (int) $check['good'],
					'gain'  => round( (float) $check['gain'], 2 ),
				);
			}
			$slices[] = array(
				'key'       => sanitize_key( $key ),
				'label'     => (string) $slice['label'],
				'core'      => ! empty( $slice['core'] ),
				'n'         => $slice_total,
				'sum'       => round( (float) $slice['score_sum'], 2 ),
				'issues'    => (int) $slice['issues_sum'],
				'buckets'   => array_map( 'intval', (array) $slice['buckets'] ),
				'checks'    => $slice_checks,
				'url_n'     => (int) $slice['url_n'],
				'url_sum'   => (int) $slice['url_sum'],
				'url_bands' => array_map( 'intval', (array) $slice['url_bands'] ),
				'url_long'  => array_values( (array) $slice['url_long'] ),
				'worst'     => array_values( (array) $slice['worst'] ),
			);
		}
		usort(
			$slices,
			static function ( array $left, array $right ): int {
				return $right['n'] <=> $left['n'];
			}
		);

		$generated = (int) ( $aggregate['generated'] ?? 0 );
		return array(
			'generated'   => $generated,
			'stale'       => self::snapshot_is_stale( $aggregate ),
			'tasks_ready' => self::SCHEMA === (int) ( $aggregate['schema'] ?? 0 ) && isset( $aggregate['items'] ) && is_array( $aggregate['items'] ),
			'date'        => $generated ? date_i18n( get_option( 'date_format' ), $generated ) : '',
			'site'        => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
			'host'        => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'total'       => $total,
			'score'       => $score,
			'top_three'   => min( 100, round( $score + $top_three_points, 1 ) ),
			'issues'      => $total ? round( (int) $aggregate['issues_sum'] / $total, 1 ) : 0.0,
			'buckets'     => array_map( 'intval', (array) ( $aggregate['buckets'] ?? array( 0, 0, 0, 0 ) ) ),
			'checks'      => $checks,
			'types'       => $types,
			'slices'      => $slices,
			'worst'       => array_values( (array) ( $aggregate['worst'] ?? array() ) ),
			'urls'        => array(
				'n'     => (int) ( $aggregate['url_n'] ?? 0 ),
				'avg'   => ! empty( $aggregate['url_n'] ) ? (int) round( $aggregate['url_sum'] / $aggregate['url_n'] ) : 0,
				'bands' => array_map( 'intval', (array) ( $aggregate['url_bands'] ?? array( 0, 0, 0, 0 ) ) ),
				'ok'    => BSM_Health::URL_OK,
				'max'   => BSM_Health::URL_MAX,
				'long'  => array_values( (array) ( $aggregate['url_long'] ?? array() ) ),
			),
		);
	}

	public static function history(): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array();
		}
		$history = get_option( self::HISTORY, array() );
		return is_array( $history ) ? array_slice( array_values( $history ), -self::HISTORY_MAX ) : array();
	}

	private static function record_run( array $aggregate ): void {
		$rows   = self::history();
		$prior  = ! empty( $rows ) ? end( $rows ) : null;
		$scores = array_slice( array_map( 'floatval', (array) ( $aggregate['scores'] ?? array() ) ), 0, self::SCORE_CAP, true );
		$checks = array();
		foreach ( (array) ( $aggregate['checks'] ?? array() ) as $label => $check ) {
			$checks[ (string) $label ] = array(
				'f' => (int) $check['fail'],
				'w' => (int) $check['warn'],
			);
		}

		$regressed = array();
		if ( is_array( $prior ) && ! empty( $prior['scores'] ) ) {
			foreach ( $scores as $post_id => $score ) {
				if ( ! isset( $prior['scores'][ $post_id ] ) || (float) $prior['scores'][ $post_id ] <= $score ) {
					continue;
				}
				$regressed[] = array(
					'id'  => absint( $post_id ),
					'was' => (float) $prior['scores'][ $post_id ],
					'now' => $score,
				);
			}
			usort(
				$regressed,
				static function ( array $left, array $right ): int {
					return ( $left['now'] - $left['was'] ) <=> ( $right['now'] - $right['was'] );
				}
			);
			$regressed = array_slice( $regressed, 0, 15 );
		}

		$total  = max( 1, (int) ( $aggregate['total'] ?? 0 ) );
		$core_n = 0;
		$core   = 0.0;
		foreach ( (array) ( $aggregate['slices'] ?? array() ) as $slice ) {
			if ( ! empty( $slice['core'] ) ) {
				$core_n += (int) $slice['total'];
				$core   += (float) $slice['score_sum'];
			}
		}
		$rows[] = array(
			'schema'    => self::SCHEMA,
			'ts'        => time(),
			'total'     => (int) ( $aggregate['total'] ?? 0 ),
			'score'     => round( (float) ( $aggregate['score_sum'] ?? 0 ) / $total, 1 ),
			'core'      => $core_n ? round( $core / $core_n, 1 ) : 0,
			'issues'    => round( (float) ( $aggregate['issues_sum'] ?? 0 ) / $total, 1 ),
			'buckets'   => array_map( 'intval', (array) ( $aggregate['buckets'] ?? array( 0, 0, 0, 0 ) ) ),
			'checks'    => $checks,
			'regressed' => $regressed,
			'scores'    => $scores,
		);
		$rows   = array_slice( $rows, -self::HISTORY_MAX );
		$latest = count( $rows ) - 1;
		foreach ( $rows as $index => &$row ) {
			if ( $index < $latest - 1 ) {
				unset( $row['scores'] );
			}
		}
		unset( $row );
		self::store_option( self::HISTORY, array_values( $rows ) );
	}

	public static function history_payload(): array {
		$rows = self::history();
		$rows = array_values(
			array_filter(
				$rows,
				static function ( $row ): bool {
					return is_array( $row ) &&
						self::SCHEMA === (int) ( $row['schema'] ?? 0 ) &&
						isset( $row['ts'], $row['total'], $row['score'], $row['core'], $row['issues'], $row['buckets'] );
				}
			)
		);
		if ( empty( $rows ) ) {
			return array();
		}
		$runs = array();
		foreach ( $rows as $row ) {
			$runs[] = array(
				'date'    => date_i18n( 'j M', (int) $row['ts'] ),
				'ts'      => (int) $row['ts'],
				'total'   => (int) $row['total'],
				'score'   => (float) $row['score'],
				'core'    => (float) $row['core'],
				'issues'  => (float) $row['issues'],
				'buckets' => array_map( 'intval', (array) $row['buckets'] ),
			);
		}
		if ( empty( $runs ) ) {
			return array();
		}

		$last      = end( $rows );
		$previous  = 1 < count( $rows ) ? $rows[ count( $rows ) - 2 ] : null;
		$labels    = array_unique( array_merge( array_keys( (array) ( $last['checks'] ?? array() ) ), array_keys( (array) ( $previous['checks'] ?? array() ) ) ) );
		$movement  = array();
		$regressed = array();
		foreach ( $labels as $label ) {
			$now = (int) ( $last['checks'][ $label ]['f'] ?? 0 ) + (int) ( $last['checks'][ $label ]['w'] ?? 0 );
			$was = (int) ( $previous['checks'][ $label ]['f'] ?? 0 ) + (int) ( $previous['checks'][ $label ]['w'] ?? 0 );
			if ( $previous && $now !== $was ) {
				$movement[] = array(
					'label' => (string) $label,
					'was'   => $was,
					'now'   => $now,
				);
			}
		}
		foreach ( array_slice( (array) ( $last['regressed'] ?? array() ), 0, 15 ) as $row ) {
			$post = get_post( absint( $row['id'] ?? 0 ) );
			if ( ! $post || 'publish' !== $post->post_status || ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$regressed[] = array(
				'title' => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ),
				'edit'  => (string) add_query_arg(
					array(
						'page'       => 'lookit-bulk-seo',
						'tab'        => 'health',
						'audit_post' => $post->ID,
					),
					admin_url( 'admin.php' )
				),
				'when'  => (string) get_post_modified_time( 'j M Y', false, $post ),
				'was'   => (float) $row['was'],
				'now'   => (float) $row['now'],
			);
		}
		return array(
			'runs'      => $runs,
			'moved'     => array_slice( $movement, 0, 12 ),
			'regressed' => $regressed,
			'prev'      => $previous && self::SCHEMA === (int) ( $previous['schema'] ?? 0 )
				? array(
					'date'    => date_i18n( 'j M', (int) $previous['ts'] ),
					'total'   => (int) $previous['total'],
					'score'   => (float) $previous['score'],
					'core'    => (float) $previous['core'],
					'issues'  => (float) $previous['issues'],
					'buckets' => array_map( 'intval', (array) $previous['buckets'] ),
				)
				: null,
		);
	}

	public static function ajax_scan(): void {
		check_ajax_referer( 'bsm_report_scan', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'bulk-keyphrase-manager' ) ), 403 );
		}

		$phase   = isset( $_POST['phase'] ) ? sanitize_key( wp_unslash( $_POST['phase'] ) ) : '';
		$offset  = min( 100000000, absint( $_POST['offset'] ?? 0 ) );
		$scan_id = isset( $_POST['scan_id'] ) ? sanitize_text_field( wp_unslash( $_POST['scan_id'] ) ) : '';
		$result  = self::scan_batch( $phase, $offset, $scan_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
					'restart' => 'stale_scan' === $result->get_error_code(),
				),
				'forbidden' === $result->get_error_code() ? 403 : 400
			);
		}
		wp_send_json_success( $result );
	}
}
