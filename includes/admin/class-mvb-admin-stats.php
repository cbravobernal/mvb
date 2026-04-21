<?php
/**
 * Stats admin page for MVB
 *
 * @package MVB
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * MVB_Admin_Stats class
 */
class MVB_Admin_Stats {

	/**
	 * Render stats page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$total_games    = wp_count_posts( 'videogame' )->publish ?? 0;
		$current_year   = (string) current_time( 'Y' );
		$completed_year = self::get_completed_games_count_for_year( $current_year );

		$status_terms = get_terms(
			array(
				'taxonomy'   => 'mvb_game_status',
				'hide_empty' => false,
			)
		);

		$platform_terms = get_terms(
			array(
				'taxonomy'   => 'mvb_platform',
				'hide_empty' => true,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => 10,
			)
		);

		$completion_by_year = self::get_completion_totals_by_year();
		$recent_completed   = self::get_recent_completed_games( 5 );

		?>
		<div class="wrap mvb-stats">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<div class="mvb-stats-grid">
				<div class="mvb-stat-card">
					<div class="mvb-stat-value"><?php echo esc_html( $total_games ); ?></div>
					<div class="mvb-stat-label"><?php esc_html_e( 'Total Games', 'mvb' ); ?></div>
				</div>
				<div class="mvb-stat-card">
					<div class="mvb-stat-value"><?php echo esc_html( $completed_year ); ?></div>
					<div class="mvb-stat-label">
						<?php
						printf(
							/* translators: %s: year */
							esc_html__( 'Completed in %s', 'mvb' ),
							esc_html( $current_year )
						);
						?>
					</div>
				</div>
			</div>

			<div class="mvb-stats-sections">
				<div class="mvb-stats-section">
					<h2><?php esc_html_e( 'Completion Trend', 'mvb' ); ?></h2>
					<?php self::render_completion_trend_chart( $completion_by_year ); ?>
				</div>

				<div class="mvb-stats-section">
					<h2><?php esc_html_e( 'Status Distribution', 'mvb' ); ?></h2>
					<?php self::render_count_bars( $status_terms ); ?>
				</div>
			</div>

			<div class="mvb-stats-sections">
				<div class="mvb-stats-section">
					<h2><?php esc_html_e( 'By Status', 'mvb' ); ?></h2>
					<?php if ( ! empty( $status_terms ) && ! is_wp_error( $status_terms ) ) : ?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Status', 'mvb' ); ?></th>
									<th><?php esc_html_e( 'Count', 'mvb' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $status_terms as $status ) : ?>
									<tr>
										<td><?php echo esc_html( $status->name ); ?></td>
										<td><?php echo esc_html( $status->count ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p><?php esc_html_e( 'No status data available yet.', 'mvb' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="mvb-stats-section">
					<h2><?php esc_html_e( 'Top Platforms', 'mvb' ); ?></h2>
					<?php self::render_count_bars( $platform_terms ); ?>
					<?php if ( ! empty( $platform_terms ) && ! is_wp_error( $platform_terms ) ) : ?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Platform', 'mvb' ); ?></th>
									<th><?php esc_html_e( 'Count', 'mvb' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $platform_terms as $platform ) : ?>
									<tr>
										<td><?php echo esc_html( $platform->name ); ?></td>
										<td><?php echo esc_html( $platform->count ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p><?php esc_html_e( 'No platform data available yet.', 'mvb' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<div class="mvb-stats-sections">
				<div class="mvb-stats-section">
					<h2><?php esc_html_e( 'Completions by Year', 'mvb' ); ?></h2>
					<?php if ( ! empty( $completion_by_year ) ) : ?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Year', 'mvb' ); ?></th>
									<th><?php esc_html_e( 'Completed', 'mvb' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $completion_by_year as $row ) : ?>
									<tr>
										<td><?php echo esc_html( $row->year ); ?></td>
										<td><?php echo esc_html( $row->total ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p><?php esc_html_e( 'No completion dates recorded yet.', 'mvb' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="mvb-stats-section">
					<h2><?php esc_html_e( 'Recently Completed', 'mvb' ); ?></h2>
					<?php if ( ! empty( $recent_completed ) ) : ?>
						<ul class="mvb-recent-list">
							<?php foreach ( $recent_completed as $game ) : ?>
								<?php
								$completion_value = get_post_meta( $game->ID, 'videogame_completion_date', true );
								$completion_ts    = self::completion_value_to_timestamp( $completion_value );
								?>
								<li>
									<a href="<?php echo esc_url( get_edit_post_link( $game->ID ) ); ?>">
										<?php echo esc_html( $game->post_title ); ?>
									</a>
									<?php if ( $completion_ts > 0 ) : ?>
										<span class="mvb-recent-date">
											<?php echo esc_html( wp_date( 'M Y', $completion_ts ) ); ?>
										</span>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p><?php esc_html_e( 'No completed games yet.', 'mvb' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render completion trend as an inline SVG line chart.
	 *
	 * @param array<int, object> $rows Completion rows.
	 */
	private static function render_completion_trend_chart( $rows ) {
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No completion trend data available yet.', 'mvb' ) . '</p>';
			return;
		}

		$rows = array_reverse( $rows );
		$rows = array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return isset( $row->year ) && isset( $row->total );
				}
			)
		);

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No completion trend data available yet.', 'mvb' ) . '</p>';
			return;
		}

		$chart_width  = 560;
		$chart_height = 220;
		$padding      = 28;
		$plot_width   = $chart_width - ( $padding * 2 );
		$plot_height  = $chart_height - ( $padding * 2 );
		$max_total    = max(
			1,
			max(
				array_map(
					static function ( $row ) {
						return (int) $row->total;
					},
					$rows
				)
			)
		);

		$points = array();
		$count  = count( $rows );
		foreach ( $rows as $index => $row ) {
			$x        = $padding + ( $count > 1 ? ( $index / ( $count - 1 ) ) * $plot_width : $plot_width / 2 );
			$y        = $chart_height - $padding - ( ( (int) $row->total / $max_total ) * $plot_height );
			$points[] = array(
				'x'     => round( $x, 2 ),
				'y'     => round( $y, 2 ),
				'year'  => (string) $row->year,
				'total' => (int) $row->total,
			);
		}

		$line_points = implode(
			' ',
			array_map(
				static function ( $point ) {
					return $point['x'] . ',' . $point['y'];
				},
				$points
			)
		);

		$last_point  = $points[ count( $points ) - 1 ];
		$area_points = $padding . ',' . ( $chart_height - $padding ) . ' ' .
			$line_points . ' ' .
			$last_point['x'] . ',' . ( $chart_height - $padding );

		?>
		<div class="mvb-chart-wrap">
			<svg class="mvb-line-chart" viewBox="0 0 <?php echo esc_attr( $chart_width ); ?> <?php echo esc_attr( $chart_height ); ?>" role="img" aria-label="<?php esc_attr_e( 'Games completed by year', 'mvb' ); ?>">
				<line x1="<?php echo esc_attr( $padding ); ?>" y1="<?php echo esc_attr( $chart_height - $padding ); ?>" x2="<?php echo esc_attr( $chart_width - $padding ); ?>" y2="<?php echo esc_attr( $chart_height - $padding ); ?>" class="mvb-axis-line"></line>
				<polygon points="<?php echo esc_attr( $area_points ); ?>" class="mvb-area"></polygon>
				<polyline points="<?php echo esc_attr( $line_points ); ?>" class="mvb-line"></polyline>
				<?php foreach ( $points as $point ) : ?>
					<circle cx="<?php echo esc_attr( $point['x'] ); ?>" cy="<?php echo esc_attr( $point['y'] ); ?>" r="4" class="mvb-point">
						<title><?php echo esc_html( $point['year'] . ': ' . $point['total'] ); ?></title>
					</circle>
				<?php endforeach; ?>
			</svg>
			<div class="mvb-line-legend">
				<?php foreach ( $points as $point ) : ?>
					<span><?php echo esc_html( $point['year'] . ' (' . $point['total'] . ')' ); ?></span>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render horizontal bars for term counts.
	 *
	 * @param array<int, object>|WP_Error $items Term list.
	 */
	private static function render_count_bars( $items ) {
		if ( empty( $items ) || is_wp_error( $items ) ) {
			echo '<p>' . esc_html__( 'No data available yet.', 'mvb' ) . '</p>';
			return;
		}

		$max_count = max(
			1,
			max(
				array_map(
					static function ( $item ) {
						return isset( $item->count ) ? (int) $item->count : 0;
					},
					$items
				)
			)
		);
		?>
		<ul class="mvb-bar-list">
			<?php foreach ( $items as $item ) : ?>
				<?php
				$count   = isset( $item->count ) ? (int) $item->count : 0;
				$label   = isset( $item->name ) ? (string) $item->name : '';
				$percent = ( $count / $max_count ) * 100;
				?>
				<li class="mvb-bar-item">
					<div class="mvb-bar-meta">
						<span><?php echo esc_html( $label ); ?></span>
						<span><?php echo esc_html( $count ); ?></span>
					</div>
					<div class="mvb-bar-track">
						<div class="mvb-bar-fill" style="width: <?php echo esc_attr( round( $percent, 2 ) ); ?>%;"></div>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Count finished games completed in a given year.
	 *
	 * @param string $year Four-digit year.
	 * @return int
	 */
	private static function get_completed_games_count_for_year( $year ) {
		$year = preg_replace( '/[^0-9]/', '', (string) $year );
		$year = substr( $year, 0, 4 );

		if ( 4 !== strlen( $year ) ) {
			return 0;
		}

		$rows = self::get_completion_totals_by_year();
		foreach ( $rows as $row ) {
			if ( isset( $row->year ) && $year === (string) $row->year ) {
				return isset( $row->total ) ? (int) $row->total : 0;
			}
		}

		return 0;
	}

	/**
	 * Get finished-game completion counts grouped by year.
	 *
	 * @return array<int, object>
	 */
	private static function get_completion_totals_by_year() {
		$candidates = get_posts(
			array(
				'post_type'      => 'videogame',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => 'videogame_completion_date',
						'value'   => '',
						'compare' => '!=',
					),
				),
			)
		);

		$totals = array();
		foreach ( $candidates as $post_id ) {
			if ( ! self::is_post_finished( (int) $post_id ) ) {
				continue;
			}

			$completion_value = get_post_meta( (int) $post_id, 'videogame_completion_date', true );
			$year             = self::extract_year_from_completion_value( $completion_value );
			if ( '' === $year ) {
				continue;
			}

			if ( ! isset( $totals[ $year ] ) ) {
				$totals[ $year ] = 0;
			}
			++$totals[ $year ];
		}

		krsort( $totals, SORT_NUMERIC );

		$rows = array();
		foreach ( $totals as $year => $total ) {
			$rows[] = (object) array(
				'year'  => (string) $year,
				'total' => (int) $total,
			);
		}

		return $rows;
	}

	/**
	 * Get most recently completed finished games.
	 *
	 * @param int $limit Number of posts to retrieve.
	 * @return array<int, WP_Post>
	 */
	private static function get_recent_completed_games( $limit = 5 ) {
		$candidates = get_posts(
			array(
				'post_type'      => 'videogame',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'     => 'videogame_completion_date',
						'value'   => '',
						'compare' => '!=',
					),
				),
			)
		);

		$limit          = absint( $limit );
		$finished_posts = array();
		foreach ( $candidates as $post ) {
			if ( ! self::is_post_finished( $post->ID ) ) {
				continue;
			}

			$completion_value = get_post_meta( $post->ID, 'videogame_completion_date', true );
			$timestamp        = self::completion_value_to_timestamp( $completion_value );
			if ( $timestamp <= 0 ) {
				continue;
			}

			$finished_posts[] = array(
				'post'      => $post,
				'timestamp' => $timestamp,
			);
		}

		usort(
			$finished_posts,
			static function ( $a, $b ) {
				return $b['timestamp'] <=> $a['timestamp'];
			}
		);

		$finished_posts = array_slice( $finished_posts, 0, $limit );

		return array_map(
			static function ( $entry ) {
				return $entry['post'];
			},
			$finished_posts
		);
	}

	/**
	 * Determine whether a videogame is finished.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function is_post_finished( $post_id ) {
		if ( class_exists( 'MVB_Data_Health' ) ) {
			return 'finished' === MVB_Data_Health::get_status_slug( $post_id );
		}

		return has_term( 'finished', 'mvb_game_status', $post_id );
	}

	/**
	 * Extract year from completion date values stored in mixed formats.
	 *
	 * @param mixed $value Completion date value.
	 * @return string
	 */
	private static function extract_year_from_completion_value( $value ) {
		$timestamp = self::completion_value_to_timestamp( $value );
		if ( $timestamp > 0 ) {
			return gmdate( 'Y', $timestamp );
		}

		return '';
	}

	/**
	 * Convert mixed completion date formats to timestamp.
	 *
	 * @param mixed $value Completion date value.
	 * @return int
	 */
	private static function completion_value_to_timestamp( $value ) {
		if ( class_exists( 'MVB_Data_Health' ) ) {
			return MVB_Data_Health::completion_value_to_timestamp( $value );
		}

		if ( ! is_scalar( $value ) ) {
			return 0;
		}

		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}

		if ( preg_match( '/^[0-9]{8}$/', $value ) ) {
			$dt = DateTime::createFromFormat( 'Ymd', $value, wp_timezone() );
			return $dt ? (int) $dt->getTimestamp() : 0;
		}

		if ( preg_match( '/^[0-9]{10}$/', $value ) ) {
			return (int) $value;
		}

		if ( preg_match( '/^[0-9]{2}\/[0-9]{2}\/[0-9]{4}$/', $value ) ) {
			$dt = DateTime::createFromFormat( 'd/m/Y', $value, wp_timezone() );
			return $dt ? (int) $dt->getTimestamp() : 0;
		}

		if ( preg_match( '/^[0-9]{4}\/[0-9]{2}\/[0-9]{2}$/', $value ) ) {
			$dt = DateTime::createFromFormat( 'Y/m/d', $value, wp_timezone() );
			return $dt ? (int) $dt->getTimestamp() : 0;
		}

		$timestamp = strtotime( $value );
		return false === $timestamp ? 0 : (int) $timestamp;
	}
}
