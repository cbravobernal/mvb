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
	 * Enqueue admin scripts
	 *
	 * @param string $hook The current admin page.
	 */
	public static function enqueue_admin_scripts( $hook ) {
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
				array( 'jquery' ),
				MVB_VERSION,
				true
			);

			// Create a single nonce for all AJAX operations
			$ajax_nonce = wp_create_nonce('mvb_ajax_nonce');

			wp_localize_script(
				'mvb-admin',
				'MVBAdmin',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce' => $ajax_nonce,
					'i18n'    => array(
						'syncing' => __('Syncing...', 'mvb'),
						'syncStarted' => __('Starting sync...', 'mvb'),
						'syncErrors' => __('Sync completed with errors:', 'mvb'),
						'syncError' => __('Error during sync. Please try again.', 'mvb'),
						'syncCompanies' => __('Sync Companies', 'mvb'),
						'syncPlatforms' => __('Sync Platforms', 'mvb'),
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
		check_ajax_referer( 'mvb_test_connection', 'nonce' );

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
				<h2><?php esc_html_e('Sync Platforms', 'mvb'); ?></h2>
				<p><?php esc_html_e('Update platform information for existing videogames.', 'mvb'); ?></p>
				<button type="button" class="button button-secondary" id="mvb-sync-platforms">
					<?php esc_html_e('Sync Platforms', 'mvb'); ?>
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
			if (!check_ajax_referer('mvb_ajax_nonce', 'nonce', false)) {
				wp_send_json_error(array(
					'message' => __('Security check failed. Please refresh the page and try again.', 'mvb')
				));
				return;
			}

			// Make sure we're in the admin
			if (!is_admin()) {
				wp_send_json_error(array(
					'message' => __('This action can only be performed in the admin area.', 'mvb')
				));
				return;
			}

			// Verify user capabilities
			if (!current_user_can('manage_options')) {
				wp_send_json_error(array(
					'message' => __('You do not have permission to perform this action.', 'mvb')
				));
				return;
			}

			error_log('MVB: Starting platform sync process');

			$processed = 0;
			$updated = 0;
			$errors = array();

			// Get all videogames
			$games = get_posts(array(
				'post_type' => 'videogame',
				'posts_per_page' => -1,
				'post_status' => 'publish',
			));

			error_log('MVB: Found ' . count($games) . ' games to process');

			if (empty($games)) {
				wp_send_json_success(array(
					'message' => __('No videogames found to sync.', 'mvb'),
					'details' => array(
						'processed' => 0,
						'updated' => 0,
						'errors' => array()
					)
				));
				return;
			}

			foreach ($games as $game) {
				error_log('MVB: Processing game: ' . $game->post_title);
				$processed++;
				
				try {
					// Search for the game in IGDB by name
					error_log('MVB: Searching IGDB for: ' . $game->post_title);
					$search_results = MVB_IGDB_API::search_games($game->post_title, 1);
					
					if (is_wp_error($search_results)) {
						error_log('MVB: Search error for ' . $game->post_title . ': ' . $search_results->get_error_message());
						$errors[] = sprintf(
							__('Failed to search for "%s": %s', 'mvb'),
							$game->post_title,
							$search_results->get_error_message()
						);
						continue;
					}

					if (empty($search_results)) {
						error_log('MVB: No results found for: ' . $game->post_title);
						$errors[] = sprintf(
							__('No IGDB match found for "%s"', 'mvb'),
							$game->post_title
						);
						continue;
					}

					// Get the first (best) match
					$game_data = $search_results[0];
					error_log('MVB: Found match for ' . $game->post_title . ': ' . print_r($game_data, true));
					
					if (empty($game_data)) {
						error_log('MVB: Invalid data for: ' . $game->post_title);
						$errors[] = sprintf(
							__('Invalid data received for "%s"', 'mvb'),
							$game->post_title
						);
						continue;
					}

					// Process platforms
					error_log('MVB: Processing platforms for: ' . $game->post_title);
					$process_result = MVB_IGDB_API::process_platforms($game->ID, $game_data);
					if (is_wp_error($process_result)) {
						error_log('MVB: Error processing platforms: ' . $process_result->get_error_message());
						$errors[] = sprintf(
							__('Error processing platforms for "%s": %s', 'mvb'),
							$game->post_title,
							$process_result->get_error_message()
						);
						continue;
					}
					$updated++;
					error_log('MVB: Successfully processed: ' . $game->post_title);
				} catch (Exception $e) {
					error_log('MVB: Error processing ' . $game->post_title . ': ' . $e->getMessage());
					$errors[] = sprintf(
						__('Error processing platforms for "%s": %s', 'mvb'),
						$game->post_title,
						$e->getMessage()
					);
				}
			}

			error_log('MVB: Sync completed. Processed: ' . $processed . ', Updated: ' . $updated . ', Errors: ' . count($errors));

			wp_send_json_success(array(
				'message' => sprintf(
					__('Sync completed. Processed: %1$d, Updated: %2$d, Errors: %3$d', 'mvb'),
					$processed,
					$updated,
					count($errors)
				),
				'details' => array(
					'processed' => $processed,
					'updated' => $updated,
					'errors' => $errors
				)
			));

		} catch (Exception $e) {
			error_log('MVB: Fatal error in sync process: ' . $e->getMessage());
			error_log('MVB: Stack trace: ' . $e->getTraceAsString());
			wp_send_json_error(array(
				'message' => sprintf(
					__('A fatal error occurred: %s', 'mvb'),
					$e->getMessage()
				)
			));
		}
	}

	/**
	 * Handle company sync AJAX request
	 */
	public static function handle_sync_companies_ajax() {
		try {
			// Verify nonce with the correct nonce key
			if (!check_ajax_referer('mvb_ajax_nonce', 'nonce', false)) {
				wp_send_json_error(array(
					'message' => __('Security check failed. Please refresh the page and try again.', 'mvb')
				));
				return;
			}

			// Make sure we're in the admin
			if (!is_admin()) {
				wp_send_json_error(array(
					'message' => __('This action can only be performed in the admin area.', 'mvb')
				));
				return;
			}

			// Verify user capabilities
			if (!current_user_can('manage_options')) {
				wp_send_json_error(array(
					'message' => __('You do not have permission to perform this action.', 'mvb')
				));
				return;
			}

			error_log('MVB: Starting company sync process');

			$processed = 0;
			$updated = 0;
			$errors = array();

			// Get all videogames
			$games = get_posts(array(
				'post_type' => 'videogame',
				'posts_per_page' => -1,
				'post_status' => 'publish',
			));

			error_log('MVB: Found ' . count($games) . ' games to process');

			if (empty($games)) {
				wp_send_json_success(array(
					'message' => __('No videogames found to sync.', 'mvb'),
					'details' => array(
						'processed' => 0,
						'updated' => 0,
						'errors' => array()
					)
				));
				return;
			}

			foreach ($games as $game) {
				error_log('MVB: Processing game: ' . $game->post_title);
				$processed++;
				
				try {
					// Search for the game in IGDB by name
					error_log('MVB: Searching IGDB for: ' . $game->post_title);
					$search_results = MVB_IGDB_API::search_games($game->post_title, 1);
					
					if (is_wp_error($search_results)) {
						error_log('MVB: Search error for ' . $game->post_title . ': ' . $search_results->get_error_message());
						$errors[] = sprintf(
							__('Failed to search for "%s": %s', 'mvb'),
							$game->post_title,
							$search_results->get_error_message()
						);
						continue;
					}

					if (empty($search_results)) {
						error_log('MVB: No results found for: ' . $game->post_title);
						$errors[] = sprintf(
							__('No IGDB match found for "%s"', 'mvb'),
							$game->post_title
						);
						continue;
					}

					// Get the first (best) match
					$game_data = $search_results[0];
					error_log('MVB: Found match for ' . $game->post_title . ': ' . print_r($game_data, true));
					
					if (empty($game_data)) {
						error_log('MVB: Invalid data for: ' . $game->post_title);
						$errors[] = sprintf(
							__('Invalid data received for "%s"', 'mvb'),
							$game->post_title
						);
						continue;
					}

					// Store the IGDB ID for future reference
					error_log('MVB: Updating IGDB ID for: ' . $game->post_title);
					update_post_meta($game->ID, 'igdb_id', $game_data['id']);
					
					// Process the companies
					error_log('MVB: Processing companies for: ' . $game->post_title);
					$process_result = MVB_IGDB_API::process_companies($game->ID, $game_data);
					if (is_wp_error($process_result)) {
						error_log('MVB: Error processing companies: ' . $process_result->get_error_message());
						$errors[] = sprintf(
							__('Error processing companies for "%s": %s', 'mvb'),
							$game->post_title,
							$process_result->get_error_message()
						);
						continue;
					}
					$updated++;
					error_log('MVB: Successfully processed: ' . $game->post_title);
				} catch (Exception $e) {
					error_log('MVB: Error processing ' . $game->post_title . ': ' . $e->getMessage());
					$errors[] = sprintf(
						__('Error processing companies for "%s": %s', 'mvb'),
						$game->post_title,
						$e->getMessage()
					);
				}
			}

			error_log('MVB: Sync completed. Processed: ' . $processed . ', Updated: ' . $updated . ', Errors: ' . count($errors));

			wp_send_json_success(array(
				'message' => sprintf(
					__('Sync completed. Processed: %1$d, Updated: %2$d, Errors: %3$d', 'mvb'),
					$processed,
					$updated,
					count($errors)
				),
				'details' => array(
					'processed' => $processed,
					'updated' => $updated,
					'errors' => $errors
				)
			));

		} catch (Exception $e) {
			error_log('MVB: Fatal error in sync process: ' . $e->getMessage());
			error_log('MVB: Stack trace: ' . $e->getTraceAsString());
			wp_send_json_error(array(
				'message' => sprintf(
					__('A fatal error occurred: %s', 'mvb'),
					$e->getMessage()
				)
			));
		}
	}
}
