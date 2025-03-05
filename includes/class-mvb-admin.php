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
		add_action( 'wp_ajax_mvb_migrate_statuses', array( __CLASS__, 'handle_migration_ajax' ) );

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
		// Add settings page under Settings menu
		add_options_page(
			__( 'MVB Settings', 'mvb' ),
			__( 'MVB', 'mvb' ),
			'manage_options',
			'mvb-settings',
			array( __CLASS__, 'render_settings_page' )
		);

		// Add "Add with API" page under Videogames menu
		add_submenu_page(
			'edit.php?post_type=videogame',  // Parent slug
			__( 'Add with IGDB', 'mvb' ), // Page title
			__( 'Add with IGDB', 'mvb' ), // Menu title
			'edit_posts',                     // Capability
			'mvb-add-game',               // Menu slug
			array( __CLASS__, 'render_add_game_page' ) // Callback function
		);

		// Add migration page
		add_submenu_page(
			'edit.php?post_type=videogame',  // Parent slug
			__( 'Migrate Game Status', 'mvb' ), // Page title
			__( 'Migrate Statuses', 'mvb' ), // Menu title
			'manage_options',                // Capability
			'mvb-migrate-statuses',          // Menu slug
			array( __CLASS__, 'render_migration_page' ) // Callback function
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
		if ( 'settings_page_mvb-settings' === $hook || 'videogame_page_mvb-add-game' === $hook ) {
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
	 * Remove months dropdown for videogames post type
	 *
	 * @param array        $months   Array of months.
	 * @param WP_Post_Type $post_type Post type object.
	 * @return array Empty array for videogames, original array for other post types
	 */
	public static function remove_months_dropdown( $months, $post_type ) {
		if ( 'videogame' === $post_type ) {
			return array();
		}
		return $months;
	}

	/**
	 * Render migration page
	 */
	public static function render_migration_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Enqueue the script for AJAX migration
		wp_enqueue_script(
			'mvb-migration',
			MVB_PLUGIN_URL . 'assets/js/migration.js',
			array( 'jquery' ),
			MVB_VERSION,
			true
		);

		// Pass data to the script
		wp_localize_script(
			'mvb-migration',
			'MVBMigration',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'mvb_migrate_statuses' ),
				'i18n'    => array(
					'processing' => __( 'Processing... Please wait.', 'mvb' ),
					'complete'   => __( 'Migration completed successfully!', 'mvb' ),
					'error'      => __( 'An error occurred during migration.', 'mvb' ),
				),
			)
		);

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Migrate Game Status', 'mvb' ); ?></h1>
			
			<div class="card">
				<h2><?php esc_html_e( 'Status Migration Tool', 'mvb' ); ?></h2>
				<p><?php esc_html_e( 'This tool will migrate your existing Game Status from post meta to the new taxonomy system.', 'mvb' ); ?></p>
				<p><?php esc_html_e( 'This is useful if you have games that were created before the taxonomy system was implemented.', 'mvb' ); ?></p>
				
				<div id="mvb-migration-progress" style="display:none;">
					<div class="mvb-progress-bar">
						<div class="mvb-progress-bar-inner" style="width: 0%;"></div>
					</div>
					<p class="mvb-progress-status"></p>
				</div>
				
				<div id="mvb-migration-result" style="display:none;"></div>
				
				<p>
					<button id="mvb-start-migration" class="button button-primary">
						<?php esc_html_e( 'Start Migration', 'mvb' ); ?>
					</button>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle the AJAX request for migrating Game Status
	 */
	public static function handle_migration_ajax() {
		// Verify nonce
		check_ajax_referer( 'mvb_migrate_statuses', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'mvb' ),
				)
			);
			return;
		}

		// Get batch parameters
		$batch_size = isset( $_POST['batch_size'] ) ? intval( $_POST['batch_size'] ) : 5;
		$offset     = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;

		// Limit batch size to prevent timeouts
		$batch_size = min( $batch_size, 10 );

		// Set a longer time limit for this request
		@set_time_limit( 300 ); // 5 minutes

		// Increase memory limit if possible
		@ini_set( 'memory_limit', '256M' );

		// Disable output buffering
		if ( ob_get_level() ) {
			ob_end_clean();
		}

		// Log start of migration batch
		error_log( sprintf( 'MVB Migration: Starting batch processing. Offset: %d, Batch size: %d', $offset, $batch_size ) );

		try {
			// Process the batch
			$result = MVB::migrate_game_statuses( $batch_size, $offset );

			// Log memory usage
			error_log(
				sprintf(
					'MVB Migration: Processed batch %d-%d. Memory usage: %.2f MB',
					$offset,
					$offset + $batch_size - 1,
					$result['memory_usage']
				)
			);

			// Return the result
			wp_send_json_success( $result );
		} catch ( Exception $e ) {
			error_log( 'MVB Migration Error: ' . $e->getMessage() );
			wp_send_json_error(
				array(
					'message' => sprintf(
						__( 'An error occurred during migration: %s', 'mvb' ),
						$e->getMessage()
					),
					'offset'  => $offset,
				)
			);
		} catch ( Error $e ) {
			// Catch PHP 7+ errors
			error_log( 'MVB Migration Fatal Error: ' . $e->getMessage() );
			wp_send_json_error(
				array(
					'message' => sprintf(
						__( 'A fatal error occurred during migration: %s', 'mvb' ),
						$e->getMessage()
					),
					'offset'  => $offset,
				)
			);
		}
	}
}
