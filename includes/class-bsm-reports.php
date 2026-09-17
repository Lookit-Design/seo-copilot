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
	const SCHEMA       = 1;
	const BATCH        = 50;
	const STATE_TTL    = 900;

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

	private static function store_option( string $name, array $value ): void {
		if ( false === get_option( $name, false ) ) {
			add_option( $name, $value, '', false );
			return;
		}
		update_option( $name, $value, false );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'bulk-keyphrase-manager' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selection.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'report';
		if ( ! in_array( $view, array( 'report', 'simulator' ), true ) ) {
			$view = 'report';
		}

		$snapshot = self::snapshot();
		?>
		<div class="wrap" id="bsm-wrap">
			<?php bsm_topbar(); ?>
			<?php bsm_render_tabs( 'reports' ); ?>
			<div class="bsm-rep-wrap">
				<div class="bsm-rep-nav">
					<?php
					foreach ( array(
						'report'    => 'Website Report',
						'simulator' => 'Impact Simulator',
					) as $key => $label ) {
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
						<?php
					}
					?>
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
				</div>
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
			'types'      => array(),
			'buckets'    => array( 0, 0, 0, 0 ),
			'worst'      => array(),
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
			self::store_option( self::OPTION, $snapshot );
			delete_option( self::STATE_OPTION );
			return array(
				'complete' => true,
				'done'     => 2 * (int) $state['total'],
				'total'    => 2 * (int) $state['total'],
				'snapshot' => self::payload( $snapshot ),
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

		$bucket = 50 > $score ? 0 : ( 70 > $score ? 1 : ( 90 > $score ? 2 : 3 ) );
		++$aggregate['buckets'][ $bucket ];

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
		}

		$aggregate['worst'][] = array(
			'title'  => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ),
			'edit'   => (string) get_edit_post_link( $post->ID, 'raw' ),
			'score'  => round( $score, 1 ),
			'issues' => count( $audit['issues'] ),
			'type'   => $aggregate['types'][ $type ]['label'],
		);
		usort(
			$aggregate['worst'],
			static function ( array $left, array $right ): int {
				return $left['score'] <=> $right['score'];
			}
		);
		$aggregate['worst'] = array_slice( $aggregate['worst'], 0, 12 );
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
				'fail'     => (int) $check['fail'],
				'warn'     => (int) $check['warn'],
				'good'     => (int) $check['good'],
				'affected' => $affected,
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

		$generated = (int) ( $aggregate['generated'] ?? 0 );
		return array(
			'generated' => $generated,
			'stale'     => self::snapshot_is_stale( $aggregate ),
			'date'      => $generated ? date_i18n( get_option( 'date_format' ), $generated ) : '',
			'site'      => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
			'host'      => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'total'     => $total,
			'score'     => $score,
			'top_three' => min( 100, round( $score + $top_three_points, 1 ) ),
			'issues'    => $total ? round( (int) $aggregate['issues_sum'] / $total, 1 ) : 0.0,
			'buckets'   => array_map( 'intval', (array) ( $aggregate['buckets'] ?? array( 0, 0, 0, 0 ) ) ),
			'checks'    => $checks,
			'types'     => $types,
			'worst'     => array_values( (array) ( $aggregate['worst'] ?? array() ) ),
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
