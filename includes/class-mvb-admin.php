<?php
/**
 * Admin settings page for MVB
 *
 * @package MVB
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Include WordPress core files
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once ABSPATH . 'wp-includes/pluggable.php';
require_once ABSPATH . 'wp-includes/functions.php';

/**
 * MVB_Admin class
 */
class MVB_Admin {

	/**
	 * Initialize the admin functionality
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_scripts' ) );

		// Register AJAX handlers
		add_action( 'wp_ajax_mvb_sync_companies', array( __CLASS__, 'handle_sync_companies_ajax' ) );
		add_action( 'wp_ajax_mvb_sync_platforms', array( __CLASS__, 'handle_sync_platforms_ajax' ) );
		add_action( 'wp_ajax_mvb_test_igdb_connection', array( __CLASS__, 'test_igdb_connection' ) );

		// Add filters to videogame list
		add_action( 'restrict_manage_posts', array( __CLASS__, 'add_completion_year_filter' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'add_platform_filter' ) );
		add_filter( 'parse_query', array( __CLASS__, 'handle_completion_year_filter' ) );
		add_filter( 'parse_query', array( __CLASS__, 'handle_platform_filter' ) );

		// Remove default date filter for videogames
		add_filter( 'months_dropdown_results', array( __CLASS__, 'remove_months_dropdown' ), 10, 2 );
	}

	/**
	 * Add admin menu items
	 */
	public static function add_admin_menu() {
		// Add "Add Game" page
		add_submenu_page(
			'edit.php?post_type=videogame',  // Parent slug
			__( 'Add Game', 'mvb' ),         // Page title
			__( 'Add Game', 'mvb' ),         // Menu title
			'edit_posts',                     // Capability
			'mvb-add-game',               // Menu slug
			array( __CLASS__, 'render_add_game_page' ) // Callback function
		);

		add_submenu_page(
			'edit.php?post_type=videogame',
			__( 'Update Covers', 'mvb' ),
			__( 'Update Covers', 'mvb' ),
			'manage_options',
			'mvb-update-covers',
			array( __CLASS__, 'render_update_covers_page' )
		);

		add_submenu_page(
			'edit.php?post_type=videogame',
			__( 'Stats', 'mvb' ),
			__( 'Stats', 'mvb' ),
			'edit_posts',
			'mvb-stats',
			array( __CLASS__, 'render_stats_page' )
		);
	}

	/**
	 * Render add game page
	 */
	public static function render_add_game_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Add Videogame from IGDB', 'mvb' ); ?></h1>

			<?php
			$client_id     = get_option( 'mvb_igdb_client_id' );
			$client_secret = get_option( 'mvb_igdb_client_secret' );

			if ( empty( $client_id ) || empty( $client_secret ) ) {
				?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %s: settings page URL */
							esc_html__( 'Please configure your IGDB API credentials in the %s.', 'mvb' ),
							'<a href="' . esc_url( admin_url( 'options-general.php?page=mvb-settings' ) ) . '">' .
							esc_html__( 'settings page', 'mvb' ) .
							'</a>'
						);
						?>
					</p>
				</div>
				<?php
			} else {
				?>
				<!-- Test Connection Section -->
				<div class="mvb-test-connection" data-wp-interactive="mvb">
					<button type="button" class="button button-secondary" id="mvb-test-connection" data-wp-on--click="actions.setSearch">
						<?php esc_html_e( 'Test API Connection', 'mvb' ); ?>
					</button>
					<div id="mvb-connection-result"></div>
				</div>

				<!-- Game Search Section -->
				<div class="mvb-search-section">
					<div class="mvb-search-form">
						<div class="mvb-search-input">
							<input type="text" 
								id="mvb-game-search" 
								placeholder="<?php esc_attr_e( 'Search for a game...', 'mvb' ); ?>"
							>
							<button type="button" id="mvb-search-button" class="button">
								<?php esc_html_e( 'Search', 'mvb' ); ?>
							</button>
						</div>
					</div>
					<div id="mvb-search-results" class="mvb-search-results"></div>
				</div>
				<?php
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render update covers page
	 */
	public static function render_update_covers_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$client_id     = get_option( 'mvb_igdb_client_id' );
		$client_secret = get_option( 'mvb_igdb_client_secret' );

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Update Game Covers', 'mvb' ); ?></h1>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %s: settings page URL */
							esc_html__( 'Please configure your IGDB API credentials in the %s.', 'mvb' ),
							'<a href="' . esc_url( admin_url( 'options-general.php?page=mvb-settings' ) ) . '">' .
							esc_html__( 'settings page', 'mvb' ) .
							'</a>'
						);
						?>
					</p>
				</div>
			</div>
			<?php
			return;
		}

		// Handle form submission
		if ( isset( $_POST['mvb_update_covers'] ) && check_admin_referer( 'mvb_update_covers' ) ) {
			$results = MVB_IGDB_API::update_all_game_covers();
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Update Game Covers', 'mvb' ); ?></h1>
				<div class="notice notice-info">
					<p>
						<?php
						printf(
							/* translators: 1: total games, 2: processed games, 3: updated games */
							esc_html__( 'Processed %1$d games, updated %2$d covers successfully.', 'mvb' ),
							$results['processed'],
							$results['updated']
						);
						?>
					</p>
					<?php if ( ! empty( $results['errors'] ) ) : ?>
						<div class="mvb-errors">
							<h3><?php esc_html_e( 'Errors:', 'mvb' ); ?></h3>
							<ul>
								<?php foreach ( $results['errors'] as $error ) : ?>
									<li><?php echo esc_html( $error ); ?></li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>
				</div>
			</div>
			<?php
			return;
		}

		// Display the form
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Update Game Covers', 'mvb' ); ?></h1>
			<p><?php esc_html_e( 'This will update all game covers using the IGDB API. This process may take some time depending on the number of games.', 'mvb' ); ?></p>
			<form method="post" action="">
				<?php wp_nonce_field( 'mvb_update_covers' ); ?>
				<p>
					<input type="submit" 
						name="mvb_update_covers" 
						class="button button-primary" 
						value="<?php esc_attr_e( 'Update All Covers', 'mvb' ); ?>"
					>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * @param string $hook The current admin page.
	 */
	public static function enqueue_admin_scripts( $hook ) {
		$screen = get_current_screen();

		// Only load on videogame post type list screen
		if ( $screen && 'edit.php' === $hook && 'videogame' === $screen->post_type ) {
			wp_enqueue_script( 'inline-edit-post' );
		}

		// Load on settings and add game pages
		if ( 'settings_page_mvb-settings' === $hook ||
			'videogame_page_mvb-add-game' === $hook ||
			'videogame_page_mvb-stats' === $hook
		) {
			wp_enqueue_style(
				'mvb-admin',
				MVB_PLUGIN_URL . 'assets/css/mvb.css',
				array(),
				MVB_VERSION
			);

			wp_enqueue_script(
				'mvb-admin',
				MVB_PLUGIN_URL . 'assets/js/admin.js',
				array( 'jquery', 'inline-edit-post' ),
				MVB_VERSION,
				true
			);

			$ajax_nonce = wp_create_nonce( 'mvb_ajax_nonce' );

			wp_localize_script(
				'mvb-admin',
				'MVBAdmin',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => $ajax_nonce,
					'i18n'    => array(
						'syncing'       => __( 'Syncing...', 'mvb' ),
						'syncStarted'   => __( 'Starting sync...', 'mvb' ),
						'syncErrors'    => __( 'Sync completed with errors:', 'mvb' ),
						'syncError'     => __( 'Error during sync. Please try again.', 'mvb' ),
						'syncCompanies' => __( 'Sync Companies', 'mvb' ),
						'syncPlatforms' => __( 'Sync Platforms', 'mvb' ),
					),
				)
			);

			// Only load search functionality on the add game page
			if ( 'videogame_page_mvb-add-game' === $hook ) {
				wp_enqueue_script(
					'mvb-search',
					MVB_PLUGIN_URL . 'assets/js/search.js',
					array( 'jquery' ),
					MVB_VERSION,
					true
				);

				wp_localize_script(
					'mvb-search',
					'MVBSearch',
					array(
						'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
						'searchNonce' => wp_create_nonce( 'mvb_search' ),
						'addNonce'    => wp_create_nonce( 'mvb_add_game' ),
					)
				);
			}
		}
	}

	/**
	 * Render stats page.
	 */
	public static function render_stats_page() {
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
		$rows = array_values( array_filter( $rows, static function ( $row ) {
			return isset( $row->year ) && isset( $row->total );
		} ) );

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No completion trend data available yet.', 'mvb' ) . '</p>';
			return;
		}

		$chart_width  = 560;
		$chart_height = 220;
		$padding      = 28;
		$plot_width   = $chart_width - ( $padding * 2 );
		$plot_height  = $chart_height - ( $padding * 2 );
		$max_total    = max( 1, max( array_map( static function ( $row ) {
			return (int) $row->total;
		}, $rows ) ) );

		$points = array();
		$count  = count( $rows );
		foreach ( $rows as $index => $row ) {
			$x = $padding + ( $count > 1 ? ( $index / ( $count - 1 ) ) * $plot_width : $plot_width / 2 );
			$y = $chart_height - $padding - ( ( (int) $row->total / $max_total ) * $plot_height );
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

		$max_count = max( 1, max( array_map( static function ( $item ) {
			return isset( $item->count ) ? (int) $item->count : 0;
		}, $items ) ) );
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
		if ( has_term( 'finished', 'mvb_game_status', $post_id ) ) {
			return true;
		}

		$meta_status = get_post_meta( $post_id, 'videogame_status', true );
		if ( is_string( $meta_status ) && 'finished' === strtolower( trim( $meta_status ) ) ) {
			return true;
		}

		$finished_term = get_term_by( 'slug', 'finished', 'mvb_game_status' );
		if ( ! $finished_term || is_wp_error( $finished_term ) ) {
			return false;
		}

		return (int) $meta_status > 0 && (int) $meta_status === (int) $finished_term->term_id;
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

	/**
	 * Register settings
	 */
	public static function register_settings() {
		register_setting(
			'mvb_settings',
			'mvb_igdb_client_id',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			'mvb_settings',
			'mvb_igdb_client_secret',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		add_settings_section(
			'mvb_igdb_section',
			__( 'IGDB API Settings', 'mvb' ),
			array( __CLASS__, 'render_section_description' ),
			'mvb-settings'
		);

		add_settings_field(
			'mvb_igdb_client_id',
			__( 'Client ID', 'mvb' ),
			array( __CLASS__, 'render_client_id_field' ),
			'mvb-settings',
			'mvb_igdb_section'
		);

		add_settings_field(
			'mvb_igdb_client_secret',
			__( 'Client Secret', 'mvb' ),
			array( __CLASS__, 'render_client_secret_field' ),
			'mvb-settings',
			'mvb_igdb_section'
		);
	}

	/**
	 * Test IGDB API connection
	 */
	public static function test_igdb_connection() {
		// Use the same nonce key as created in enqueue_admin_scripts
		check_ajax_referer( 'mvb_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access', 'mvb' ) ) );
		}

		$client_id     = get_option( 'mvb_igdb_client_id' );
		$client_secret = get_option( 'mvb_igdb_client_secret' );

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please save your Client ID and Client Secret first.', 'mvb' ),
				)
			);
		}

		$response = wp_remote_post(
			'https://id.twitch.tv/oauth2/token',
			array(
				'body' => array(
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'grant_type'    => 'client_credentials',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				array(
					'message' => $response->get_error_message(),
				)
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['access_token'] ) ) {
			update_option( 'mvb_igdb_access_token', $body['access_token'] );
			update_option( 'mvb_igdb_token_expires', time() + $body['expires_in'] );
			wp_send_json_success(
				array(
					'message' => __( 'Connection successful! Access token has been saved.', 'mvb' ),
				)
			);
		} else {
			wp_send_json_error(
				array(
					'message' => isset( $body['message'] ) ? $body['message'] : __( 'Unknown error occurred', 'mvb' ),
				)
			);
		}
	}

	/**
	 * Render section description
	 */
	public static function render_section_description() {
		?>
		<p>
			<?php
			printf(
				/* translators: %s: Twitch Developer Console URL */
				esc_html__( 'Enter your IGDB API credentials. You can get these from the %s.', 'mvb' ),
				'<a href="https://dev.twitch.tv/console" target="_blank">' . esc_html__( 'Twitch Developer Console', 'mvb' ) . '</a>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render client ID field
	 */
	public static function render_client_id_field() {
		$client_id = get_option( 'mvb_igdb_client_id' );
		?>
		<input type="text"
			name="mvb_igdb_client_id"
			id="mvb_igdb_client_id"
			value="<?php echo esc_attr( $client_id ); ?>"
			class="regular-text"
		/>
		<?php
	}

	/**
	 * Render client secret field
	 */
	public static function render_client_secret_field() {
		$client_secret = get_option( 'mvb_igdb_client_secret' );
		?>
		<input type="password"
			name="mvb_igdb_client_secret"
			id="mvb_igdb_client_secret"
			value="<?php echo esc_attr( $client_secret ); ?>"
			class="regular-text"
		/>
		<?php
	}

	/**
	 * Render settings page
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'mvb_settings' );
				do_settings_sections( 'mvb-settings' );
				submit_button();
				?>
			</form>

			<!-- Test Connection Section -->
			<div class="mvb-test-connection" data-wp-interactive="mvb">
				<h2><?php esc_html_e( 'Test API Connection', 'mvb' ); ?></h2>
				<button type="button" class="button button-secondary" id="mvb-test-connection" data-wp-on--click="actions.setSearch">
					<?php esc_html_e( 'Test Connection', 'mvb' ); ?>
				</button>
				<div id="mvb-connection-result" style="margin-top: 10px;"></div>
			</div>

			<!-- Sync Companies Section -->
			<div class="mvb-sync-section">
				<h2><?php esc_html_e( 'Sync Companies', 'mvb' ); ?></h2>
				<p><?php esc_html_e( 'Update company information for existing videogames.', 'mvb' ); ?></p>
				<button type="button" class="button button-secondary" id="mvb-sync-companies">
					<?php esc_html_e( 'Sync Companies', 'mvb' ); ?>
				</button>
				<div id="mvb-sync-result" style="margin-top: 10px;"></div>
			</div>

			<!-- Sync Platforms Section -->
			<div class="mvb-sync-section">
				<h2><?php esc_html_e( 'Sync Platforms', 'mvb' ); ?></h2>
				<p><?php esc_html_e( 'Update platform information for existing videogames.', 'mvb' ); ?></p>
				<button type="button" class="button button-secondary" id="mvb-sync-platforms">
					<?php esc_html_e( 'Sync Platforms', 'mvb' ); ?>
				</button>
				<div id="mvb-platforms-result" style="margin-top: 10px;"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle platform sync AJAX request
	 */
	public static function handle_sync_platforms_ajax() {
		try {
			// Verify nonce with the correct nonce key
			if ( ! check_ajax_referer( 'mvb_ajax_nonce', 'nonce', false ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Security check failed. Please refresh the page and try again.', 'mvb' ),
					)
				);
				return;
			}

			// Make sure we're in the admin
			if ( ! is_admin() ) {
				wp_send_json_error(
					array(
						'message' => __( 'This action can only be performed in the admin area.', 'mvb' ),
					)
				);
				return;
			}

			// Verify user capabilities
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'You do not have permission to perform this action.', 'mvb' ),
					)
				);
				return;
			}

			error_log( 'MVB: Starting platform sync process' );

			$processed = 0;
			$updated   = 0;
			$errors    = array();

			// Get all videogames
			$games = get_posts(
				array(
					'post_type'      => 'videogame',
					'posts_per_page' => -1,
					'post_status'    => 'publish',
				)
			);

			error_log( 'MVB: Found ' . count( $games ) . ' games to process' );

			if ( empty( $games ) ) {
				wp_send_json_success(
					array(
						'message' => __( 'No videogames found to sync.', 'mvb' ),
						'details' => array(
							'processed' => 0,
							'updated'   => 0,
							'errors'    => array(),
						),
					)
				);
				return;
			}

			foreach ( $games as $game ) {
				error_log( 'MVB: Processing game: ' . $game->post_title );
				++$processed;

				try {
					// Search for the game in IGDB by name
					error_log( 'MVB: Searching IGDB for: ' . $game->post_title );
					$search_results = MVB_IGDB_API::search_games( $game->post_title, 1 );

					if ( is_wp_error( $search_results ) ) {
						error_log( 'MVB: Search error for ' . $game->post_title . ': ' . $search_results->get_error_message() );
						$errors[] = sprintf(
							__( 'Failed to search for "%1$s": %2$s', 'mvb' ),
							$game->post_title,
							$search_results->get_error_message()
						);
						continue;
					}

					if ( empty( $search_results ) ) {
						error_log( 'MVB: No results found for: ' . $game->post_title );
						$errors[] = sprintf(
							__( 'No IGDB match found for "%s"', 'mvb' ),
							$game->post_title
						);
						continue;
					}

					// Get the first (best) match
					$game_data = $search_results[0];
					error_log( 'MVB: Found match for ' . $game->post_title . ': ' . print_r( $game_data, true ) );

					if ( empty( $game_data ) ) {
						error_log( 'MVB: Invalid data for: ' . $game->post_title );
						$errors[] = sprintf(
							__( 'Invalid data received for "%s"', 'mvb' ),
							$game->post_title
						);
						continue;
					}

					// Process platforms
					error_log( 'MVB: Processing platforms for: ' . $game->post_title );
					$process_result = MVB_IGDB_API::process_platforms( $game->ID, $game_data );
					if ( is_wp_error( $process_result ) ) {
						error_log( 'MVB: Error processing platforms: ' . $process_result->get_error_message() );
						$errors[] = sprintf(
							__( 'Error processing platforms for "%1$s": %2$s', 'mvb' ),
							$game->post_title,
							$process_result->get_error_message()
						);
						continue;
					}
					++$updated;
					error_log( 'MVB: Successfully processed: ' . $game->post_title );
				} catch ( Exception $e ) {
					error_log( 'MVB: Error processing ' . $game->post_title . ': ' . $e->getMessage() );
					$errors[] = sprintf(
						__( 'Error processing platforms for "%1$s": %2$s', 'mvb' ),
						$game->post_title,
						$e->getMessage()
					);
				}
			}

			error_log( 'MVB: Sync completed. Processed: ' . $processed . ', Updated: ' . $updated . ', Errors: ' . count( $errors ) );

			wp_send_json_success(
				array(
					'message' => sprintf(
						__( 'Sync completed. Processed: %1$d, Updated: %2$d, Errors: %3$d', 'mvb' ),
						$processed,
						$updated,
						count( $errors )
					),
					'details' => array(
						'processed' => $processed,
						'updated'   => $updated,
						'errors'    => $errors,
					),
				)
			);

		} catch ( Exception $e ) {
			error_log( 'MVB: Fatal error in sync process: ' . $e->getMessage() );
			error_log( 'MVB: Stack trace: ' . $e->getTraceAsString() );
			wp_send_json_error(
				array(
					'message' => sprintf(
						__( 'A fatal error occurred: %s', 'mvb' ),
						$e->getMessage()
					),
				)
			);
		}
	}

	/**
	 * Handle company sync AJAX request
	 */
	public static function handle_sync_companies_ajax() {
		try {
			// Verify nonce with the correct nonce key
			if ( ! check_ajax_referer( 'mvb_ajax_nonce', 'nonce', false ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Security check failed. Please refresh the page and try again.', 'mvb' ),
					)
				);
				return;
			}

			// Make sure we're in the admin
			if ( ! is_admin() ) {
				wp_send_json_error(
					array(
						'message' => __( 'This action can only be performed in the admin area.', 'mvb' ),
					)
				);
				return;
			}

			// Verify user capabilities
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'You do not have permission to perform this action.', 'mvb' ),
					)
				);
				return;
			}

			error_log( 'MVB: Starting company sync process' );

			$processed = 0;
			$updated   = 0;
			$errors    = array();

			// Get all videogames
			$games = get_posts(
				array(
					'post_type'      => 'videogame',
					'posts_per_page' => -1,
					'post_status'    => 'publish',
				)
			);

			error_log( 'MVB: Found ' . count( $games ) . ' games to process' );

			if ( empty( $games ) ) {
				wp_send_json_success(
					array(
						'message' => __( 'No videogames found to sync.', 'mvb' ),
						'details' => array(
							'processed' => 0,
							'updated'   => 0,
							'errors'    => array(),
						),
					)
				);
				return;
			}

			foreach ( $games as $game ) {
				error_log( 'MVB: Processing game: ' . $game->post_title );
				++$processed;

				try {
					// Search for the game in IGDB by name
					error_log( 'MVB: Searching IGDB for: ' . $game->post_title );
					$search_results = MVB_IGDB_API::search_games( $game->post_title, 1 );

					if ( is_wp_error( $search_results ) ) {
						error_log( 'MVB: Search error for ' . $game->post_title . ': ' . $search_results->get_error_message() );
						$errors[] = sprintf(
							__( 'Failed to search for "%1$s": %2$s', 'mvb' ),
							$game->post_title,
							$search_results->get_error_message()
						);
						continue;
					}

					if ( empty( $search_results ) ) {
						error_log( 'MVB: No results found for: ' . $game->post_title );
						$errors[] = sprintf(
							__( 'No IGDB match found for "%s"', 'mvb' ),
							$game->post_title
						);
						continue;
					}

					// Get the first (best) match
					$game_data = $search_results[0];
					error_log( 'MVB: Found match for ' . $game->post_title . ': ' . print_r( $game_data, true ) );

					if ( empty( $game_data ) ) {
						error_log( 'MVB: Invalid data for: ' . $game->post_title );
						$errors[] = sprintf(
							__( 'Invalid data received for "%s"', 'mvb' ),
							$game->post_title
						);
						continue;
					}

					// Store the IGDB ID for future reference
					error_log( 'MVB: Updating IGDB ID for: ' . $game->post_title );
					update_post_meta( $game->ID, 'igdb_id', $game_data['id'] );

					// Process the companies
					error_log( 'MVB: Processing companies for: ' . $game->post_title );
					$process_result = MVB_IGDB_API::process_companies( $game->ID, $game_data );
					if ( is_wp_error( $process_result ) ) {
						error_log( 'MVB: Error processing companies: ' . $process_result->get_error_message() );
						$errors[] = sprintf(
							__( 'Error processing companies for "%1$s": %2$s', 'mvb' ),
							$game->post_title,
							$process_result->get_error_message()
						);
						continue;
					}
					++$updated;
					error_log( 'MVB: Successfully processed: ' . $game->post_title );
				} catch ( Exception $e ) {
					error_log( 'MVB: Error processing ' . $game->post_title . ': ' . $e->getMessage() );
					$errors[] = sprintf(
						__( 'Error processing companies for "%1$s": %2$s', 'mvb' ),
						$game->post_title,
						$e->getMessage()
					);
				}
			}

			error_log( 'MVB: Sync completed. Processed: ' . $processed . ', Updated: ' . $updated . ', Errors: ' . count( $errors ) );

			wp_send_json_success(
				array(
					'message' => sprintf(
						__( 'Sync completed. Processed: %1$d, Updated: %2$d, Errors: %3$d', 'mvb' ),
						$processed,
						$updated,
						count( $errors )
					),
					'details' => array(
						'processed' => $processed,
						'updated'   => $updated,
						'errors'    => $errors,
					),
				)
			);

		} catch ( Exception $e ) {
			error_log( 'MVB: Fatal error in sync process: ' . $e->getMessage() );
			error_log( 'MVB: Stack trace: ' . $e->getTraceAsString() );
			wp_send_json_error(
				array(
					'message' => sprintf(
						__( 'A fatal error occurred: %s', 'mvb' ),
						$e->getMessage()
					),
				)
			);
		}
	}

	/**
	 * Add completion year filter dropdown
	 *
	 * @param string $post_type Current post type.
	 */
	public static function add_completion_year_filter( $post_type ) {
		if ( 'videogame' !== $post_type ) {
			return;
		}

		$current_year = isset( $_GET['completion_year'] ) ? $_GET['completion_year'] : '';

		// Get all completion years from the database
		global $wpdb;
		$years = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT SUBSTRING(meta_value, 1, 4) as year
				FROM {$wpdb->postmeta}
				WHERE meta_key = %s
				AND meta_value IS NOT NULL
				AND meta_value != ''
				ORDER BY year DESC",
				'videogame_completion_date'
			)
		);

		error_log( 'MVB: Found completion years: ' . print_r( $years, true ) );

		if ( empty( $years ) ) {
			return;
		}

		?>
		<select name="completion_year">
			<option value=""><?php esc_html_e( 'All Completion Years', 'mvb' ); ?></option>
			<?php
			foreach ( $years as $year ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $year ),
					selected( $year, $current_year, false ),
					esc_html( $year )
				);
			}
			?>
		</select>
		<?php
	}

	/**
	 * Handle completion year filter
	 *
	 * @param WP_Query $query Current query.
	 */
	public static function handle_completion_year_filter( $query ) {
		global $pagenow;

		if ( ! is_admin() ||
			'edit.php' !== $pagenow ||
			! $query->is_main_query() ||
			empty( $_GET['completion_year'] ) ||
			'videogame' !== $query->get( 'post_type' )
		) {
			return;
		}

		$year = sanitize_text_field( $_GET['completion_year'] );
		error_log( 'MVB: Filtering by year: ' . $year );

		// Use LIKE to match the year prefix
		$query->set(
			'meta_query',
			array(
				array(
					'key'     => 'videogame_completion_date',
					'value'   => $year . '%',
					'compare' => 'LIKE',
				),
			)
		);

		error_log( 'MVB: Meta query: ' . print_r( $query->get( 'meta_query' ), true ) );
	}

	/**
	 * Add platform filter dropdown
	 *
	 * @param string $post_type Current post type.
	 */
	public static function add_platform_filter( $post_type ) {
		if ( 'videogame' !== $post_type ) {
			return;
		}

		$current_platform = isset( $_GET['platform'] ) ? $_GET['platform'] : '';

		// Get all platforms
		$platforms = get_terms(
			array(
				'taxonomy'   => 'mvb_platform',
				'hide_empty' => true,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( empty( $platforms ) ) {
			return;
		}

		?>
		<select name="platform">
			<option value=""><?php esc_html_e( 'All Platforms', 'mvb' ); ?></option>
			<?php
			foreach ( $platforms as $platform ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $platform->slug ),
					selected( $platform->slug, $current_platform, false ),
					esc_html( $platform->name )
				);
			}
			?>
		</select>
		<?php
	}

	/**
	 * Handle platform filter
	 *
	 * @param WP_Query $query Current query.
	 */
	public static function handle_platform_filter( $query ) {
		global $pagenow;

		if ( ! is_admin() ||
			'edit.php' !== $pagenow ||
			! $query->is_main_query() ||
			empty( $_GET['platform'] ) ||
			'videogame' !== $query->get( 'post_type' )
		) {
			return;
		}

		$platform = sanitize_text_field( $_GET['platform'] );
		error_log( 'MVB: Filtering by platform: ' . $platform );

		// Add tax query for platform
		$tax_query   = $query->get( 'tax_query' ) ?: array();
		$tax_query[] = array(
			'taxonomy' => 'mvb_platform',
			'field'    => 'slug',
			'terms'    => $platform,
		);
		$query->set( 'tax_query', $tax_query );

		error_log( 'MVB: Tax query: ' . print_r( $query->get( 'tax_query' ), true ) );
	}

	/**
	 * Remove months dropdown from admin filters.
	 *
	 * @param array  $months   Array of month objects.
	 * @param string $post_type Post type.
	 * @return array Modified months array.
	 */
	public static function remove_months_dropdown( $months, $post_type ) {
		if ( 'videogame' === $post_type ) {
			return array();
		}
		return $months;
	}
}
