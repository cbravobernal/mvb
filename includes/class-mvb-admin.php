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

		// Register AJAX handlers.
		add_action( 'wp_ajax_mvb_sync_companies', array( __CLASS__, 'handle_sync_companies_ajax' ) );
		add_action( 'wp_ajax_mvb_sync_platforms', array( __CLASS__, 'handle_sync_platforms_ajax' ) );
		add_action( 'wp_ajax_mvb_test_igdb_connection', array( __CLASS__, 'test_igdb_connection' ) );

		// Library AJAX handlers.
		add_action( 'wp_ajax_mvb_add_library_entry', array( __CLASS__, 'handle_add_library_entry' ) );
		add_action( 'wp_ajax_mvb_search_catalog', array( __CLASS__, 'handle_search_catalog' ) );
		// Delete uses admin-post.php (standard GET action with nonce).
		add_action( 'admin_post_mvb_delete_library_entry', array( __CLASS__, 'handle_delete_library_entry' ) );

		// Add filters to videogame list.
		add_action( 'restrict_manage_posts', array( __CLASS__, 'add_completion_year_filter' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'add_platform_filter' ) );
		add_filter( 'parse_query', array( __CLASS__, 'handle_completion_year_filter' ) );
		add_filter( 'parse_query', array( __CLASS__, 'handle_platform_filter' ) );

		// Remove default date filter for videogames.
		add_filter( 'months_dropdown_results', array( __CLASS__, 'remove_months_dropdown' ), 10, 2 );

		// Scope devices list to current user when not admin.
		add_action( 'pre_get_posts', array( __CLASS__, 'scope_devices_to_author' ) );
	}

	/**
	 * Add admin menu items
	 */
	public static function add_admin_menu() {
		// Add "My Library" top-level page — visible to every logged-in user with `read`.
		add_menu_page(
			__( 'My Library', 'mvb' ),
			__( 'My Library', 'mvb' ),
			'read',
			'mvb-my-library',
			array( __CLASS__, 'render_my_library_page' ),
			'dashicons-book-alt',
			6
		);

		add_submenu_page(
			'edit.php?post_type=videogame',
			__( 'MVB Tools', 'mvb' ),
			__( 'Tools', 'mvb' ),
			'edit_posts',
			'mvb-tools',
			array( __CLASS__, 'render_tools_page' )
		);

		// Hide the default "Add New Videogame" submenu — creation flows through IGDB.
		remove_submenu_page( 'edit.php?post_type=videogame', 'post-new.php?post_type=videogame' );
	}

	/**
	 * Tab registry for the MVB Tools page.
	 *
	 * @return array<string,array{label:string,cap:string,cb:callable}>
	 */
	private static function get_tools_tabs() {
		return array(
			'add-game'        => array(
				'label' => __( 'Add Game', 'mvb' ),
				'cap'   => 'edit_posts',
				'cb'    => array( 'MVB_Admin_Add_Game', 'render_page' ),
			),
			'stats'           => array(
				'label' => __( 'Stats', 'mvb' ),
				'cap'   => 'edit_posts',
				'cb'    => array( 'MVB_Admin_Stats', 'render_page' ),
			),
			'recommendations' => array(
				'label' => __( 'Recommendations', 'mvb' ),
				'cap'   => 'edit_posts',
				'cb'    => array( 'MVB_Recommendations', 'render_page' ),
			),
			'update-covers'   => array(
				'label' => __( 'Update Covers', 'mvb' ),
				'cap'   => 'manage_options',
				'cb'    => array( 'MVB_Admin_Update_Covers', 'render_page' ),
			),
			'data-health'     => array(
				'label' => __( 'Data Health', 'mvb' ),
				'cap'   => 'manage_options',
				'cb'    => array( 'MVB_Data_Health', 'render_page' ),
			),
		);
	}

	/**
	 * Resolve the active tab slug, falling back to the first tab the user can access.
	 *
	 * @return string|null
	 */
	private static function resolve_active_tab() {
		$tabs      = self::get_tools_tabs();
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		if ( isset( $tabs[ $requested ] ) && current_user_can( $tabs[ $requested ]['cap'] ) ) {
			return $requested;
		}

		foreach ( $tabs as $slug => $tab ) {
			if ( current_user_can( $tab['cap'] ) ) {
				return $slug;
			}
		}

		return null;
	}

	/**
	 * Render the unified MVB Tools page with tab navigation.
	 */
	public static function render_tools_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$tabs   = self::get_tools_tabs();
		$active = self::resolve_active_tab();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'MVB Tools', 'mvb' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<?php
				foreach ( $tabs as $slug => $tab ) {
					if ( ! current_user_can( $tab['cap'] ) ) {
						continue;
					}
					$url   = add_query_arg(
						array(
							'post_type' => 'videogame',
							'page'      => 'mvb-tools',
							'tab'       => $slug,
						),
						admin_url( 'edit.php' )
					);
					$class = 'nav-tab' . ( $slug === $active ? ' nav-tab-active' : '' );
					printf(
						'<a href="%s" class="%s">%s</a>',
						esc_url( $url ),
						esc_attr( $class ),
						esc_html( $tab['label'] )
					);
				}
				?>
			</nav>
		</div>
		<?php
		if ( null !== $active ) {
			call_user_func( $tabs[ $active ]['cb'] );
		}
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

		// Load on tools page, legacy settings hook, and My Library page.
		if ( 'settings_page_mvb-settings' === $hook
			|| 'videogame_page_mvb-tools' === $hook
			|| 'toplevel_page_mvb-my-library' === $hook
		) {
			$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'add-game';
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

			// Only load search functionality on the Add Game tab.
			if ( 'videogame_page_mvb-tools' === $hook && 'add-game' === $active_tab ) {
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
				++$processed;

				try {
					// Search for the game in IGDB by name
					$search_results = MVB_IGDB_API::search_games( $game->post_title, 1 );

					if ( is_wp_error( $search_results ) ) {
						$errors[] = sprintf(
							__( 'Failed to search for "%1$s": %2$s', 'mvb' ),
							$game->post_title,
							$search_results->get_error_message()
						);
						continue;
					}

					if ( empty( $search_results ) ) {
						$errors[] = sprintf(
							__( 'No IGDB match found for "%s"', 'mvb' ),
							$game->post_title
						);
						continue;
					}

					// Get the first (best) match
					$game_data = $search_results[0];
					if ( empty( $game_data ) ) {
						$errors[] = sprintf(
							__( 'Invalid data received for "%s"', 'mvb' ),
							$game->post_title
						);
						continue;
					}

					// Process platforms
					$process_result = MVB_IGDB_API::process_platforms( $game->ID, $game_data );
					if ( is_wp_error( $process_result ) ) {
						$errors[] = sprintf(
							__( 'Error processing platforms for "%1$s": %2$s', 'mvb' ),
							$game->post_title,
							$process_result->get_error_message()
						);
						continue;
					}
					++$updated;
				} catch ( Exception $e ) {
					$errors[] = sprintf(
						__( 'Error processing platforms for "%1$s": %2$s', 'mvb' ),
						$game->post_title,
						$e->getMessage()
					);
				}
			}

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
				++$processed;

				try {
					// Search for the game in IGDB by name
					$search_results = MVB_IGDB_API::search_games( $game->post_title, 1 );

					if ( is_wp_error( $search_results ) ) {
						$errors[] = sprintf(
							__( 'Failed to search for "%1$s": %2$s', 'mvb' ),
							$game->post_title,
							$search_results->get_error_message()
						);
						continue;
					}

					if ( empty( $search_results ) ) {
						$errors[] = sprintf(
							__( 'No IGDB match found for "%s"', 'mvb' ),
							$game->post_title
						);
						continue;
					}

					// Get the first (best) match
					$game_data = $search_results[0];
					if ( empty( $game_data ) ) {
						$errors[] = sprintf(
							__( 'Invalid data received for "%s"', 'mvb' ),
							$game->post_title
						);
						continue;
					}

					// Store the IGDB ID for future reference
					update_post_meta( $game->ID, 'igdb_id', $game_data['id'] );

					// Process the companies
					$process_result = MVB_IGDB_API::process_companies( $game->ID, $game_data );
					if ( is_wp_error( $process_result ) ) {
						$errors[] = sprintf(
							__( 'Error processing companies for "%1$s": %2$s', 'mvb' ),
							$game->post_title,
							$process_result->get_error_message()
						);
						continue;
					}
					++$updated;
				} catch ( Exception $e ) {
					$errors[] = sprintf(
						__( 'Error processing companies for "%1$s": %2$s', 'mvb' ),
						$game->post_title,
						$e->getMessage()
					);
				}
			}

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

		$current_year = isset( $_GET['completion_year'] ) ? sanitize_text_field( wp_unslash( $_GET['completion_year'] ) ) : '';

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

		$year = sanitize_text_field( wp_unslash( $_GET['completion_year'] ) );
		// Only accept a four-digit year to keep the LIKE value safe.
		if ( ! preg_match( '/^[0-9]{4}$/', $year ) ) {
			return;
		}
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

		$current_platform = isset( $_GET['platform'] ) ? sanitize_text_field( wp_unslash( $_GET['platform'] ) ) : '';

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

		$platform    = sanitize_text_field( wp_unslash( $_GET['platform'] ) );
		$tax_query   = $query->get( 'tax_query' ) ?: array();
		$tax_query[] = array(
			'taxonomy' => 'mvb_platform',
			'field'    => 'slug',
			'terms'    => $platform,
		);
		$query->set( 'tax_query', $tax_query );
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

	/**
	 * Scope the devices list to the current user when they cannot manage_options.
	 *
	 * @param WP_Query $query The current query.
	 */
	public static function scope_devices_to_author( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		if ( 'mvb_device' !== $post_type ) {
			return;
		}

		$query->set( 'author', get_current_user_id() );
	}

	/**
	 * Render the My Library admin page.
	 */
	public static function render_my_library_page() {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mvb' ) );
		}

		$user_id = get_current_user_id();
		$entries = MVB_Library::get_user_library( $user_id );

		// Fetch devices available to this user (own + admin-owned).
		$admin_id       = MVB_Migration::get_admin_user_id();
		$device_authors = array( $user_id );
		if ( $admin_id !== $user_id ) {
			$device_authors[] = $admin_id;
		}

		$devices = get_posts(
			array(
				'post_type'      => 'mvb_device',
				'post_status'    => 'publish',
				'author__in'     => $device_authors,
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'My Library', 'mvb' ); ?></h1>

			<?php if ( ! empty( $entries ) ) : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Game', 'mvb' ); ?></th>
							<th><?php esc_html_e( 'Status', 'mvb' ); ?></th>
							<th><?php esc_html_e( 'Completion Date', 'mvb' ); ?></th>
							<th><?php esc_html_e( 'Device', 'mvb' ); ?></th>
							<th><?php esc_html_e( 'Rating', 'mvb' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'mvb' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $entry ) : ?>
							<?php
							$status          = get_post_meta( $entry->ID, 'library_status', true );
							$completion_date = get_post_meta( $entry->ID, 'library_completion_date', true );
							$device_id       = absint( get_post_meta( $entry->ID, 'library_device_id', true ) );
							$rating          = absint( get_post_meta( $entry->ID, 'library_rating', true ) );
							$device_title    = $device_id > 0 ? get_the_title( $device_id ) : '';

							// Format date for display.
							$display_date = '';
							if ( '' !== $completion_date && 8 === strlen( $completion_date ) ) {
								$display_date = substr( $completion_date, 0, 4 ) . '-' . substr( $completion_date, 4, 2 ) . '-' . substr( $completion_date, 6, 2 );
							}

							$delete_url = wp_nonce_url(
								admin_url( 'admin-post.php?action=mvb_delete_library_entry&entry_id=' . $entry->ID ),
								'mvb_delete_entry_' . $entry->ID
							);
							?>
							<tr>
								<td><?php echo esc_html( $entry->post_title ); ?></td>
								<td><?php echo esc_html( $status ); ?></td>
								<td><?php echo esc_html( $display_date ); ?></td>
								<td><?php echo esc_html( $device_title ); ?></td>
								<td>
									<?php
									if ( $rating > 0 ) {
										/* translators: %d: rating number (1-10) */
										echo esc_html( sprintf( __( '%d/10', 'mvb' ), $rating ) );
									} else {
										echo '&mdash;';
									}
									?>
								</td>
								<td>
									<a href="<?php echo esc_url( $delete_url ); ?>"
										onclick="return confirm('<?php esc_attr_e( 'Delete this entry?', 'mvb' ); ?>');"
										class="submitdelete">
										<?php esc_html_e( 'Delete', 'mvb' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'Your library is empty. Add a game below!', 'mvb' ); ?></p>
			<?php endif; ?>

			<hr />

			<h2><?php esc_html_e( 'Add Game to My Library', 'mvb' ); ?></h2>

			<form id="mvb-add-library-form" method="post">
				<?php wp_nonce_field( 'mvb_add_library_entry', 'mvb_library_nonce' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="mvb-library-search"><?php esc_html_e( 'Search Game', 'mvb' ); ?></label>
						</th>
						<td>
							<input type="text"
								id="mvb-library-search"
								class="regular-text"
								placeholder="<?php esc_attr_e( 'Type to search the catalog...', 'mvb' ); ?>"
								autocomplete="off"
							/>
							<input type="hidden" id="mvb-library-videogame-id" name="videogame_id" value="" />
							<div id="mvb-library-search-results" class="mvb-search-results" style="position:relative;"></div>
							<div id="mvb-library-selected-game" style="margin-top:6px;"></div>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="mvb-library-status"><?php esc_html_e( 'Status', 'mvb' ); ?></label>
						</th>
						<td>
							<select id="mvb-library-status" name="library_status">
								<option value="backlog"><?php esc_html_e( 'Backlog', 'mvb' ); ?></option>
								<option value="playing"><?php esc_html_e( 'Playing', 'mvb' ); ?></option>
								<option value="completed"><?php esc_html_e( 'Completed', 'mvb' ); ?></option>
								<option value="dropped"><?php esc_html_e( 'Dropped', 'mvb' ); ?></option>
								<option value="wishlist"><?php esc_html_e( 'Wishlist', 'mvb' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="mvb-library-completion-date"><?php esc_html_e( 'Completion Date', 'mvb' ); ?></label>
						</th>
						<td>
							<input type="date"
								id="mvb-library-completion-date"
								name="library_completion_date"
								class="regular-text"
							/>
						</td>
					</tr>
					<?php if ( ! empty( $devices ) ) : ?>
						<tr>
							<th scope="row">
								<label for="mvb-library-device"><?php esc_html_e( 'Device', 'mvb' ); ?></label>
							</th>
							<td>
								<select id="mvb-library-device" name="library_device_id">
									<option value="0"><?php esc_html_e( '— None —', 'mvb' ); ?></option>
									<?php foreach ( $devices as $device ) : ?>
										<option value="<?php echo esc_attr( (string) $device->ID ); ?>">
											<?php echo esc_html( $device->post_title ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row">
							<label for="mvb-library-rating"><?php esc_html_e( 'Rating', 'mvb' ); ?></label>
						</th>
						<td>
							<select id="mvb-library-rating" name="library_rating">
								<option value="0"><?php esc_html_e( '— Unrated —', 'mvb' ); ?></option>
								<?php for ( $i = 1; $i <= 10; $i++ ) : ?>
									<option value="<?php echo esc_attr( (string) $i ); ?>">
										<?php echo esc_html( (string) $i ); ?>/10
									</option>
								<?php endfor; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="mvb-library-notes"><?php esc_html_e( 'Notes', 'mvb' ); ?></label>
						</th>
						<td>
							<textarea id="mvb-library-notes" name="library_notes" rows="4" class="large-text"></textarea>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Add to Library', 'mvb' ); ?>
					</button>
				</p>
			</form>

			<div id="mvb-library-message" style="display:none;"></div>
		</div>

		<script>
		(function() {
			'use strict';

			var searchInput  = document.getElementById('mvb-library-search');
			var searchResults = document.getElementById('mvb-library-search-results');
			var selectedGame = document.getElementById('mvb-library-selected-game');
			var videogameId  = document.getElementById('mvb-library-videogame-id');
			var searchTimer  = null;

			if (searchInput) {
				searchInput.addEventListener('input', function() {
					clearTimeout(searchTimer);
					var term = this.value;
					if (term.length < 2) {
						searchResults.innerHTML = '';
						return;
					}
					searchTimer = setTimeout(function() {
						var xhr = new XMLHttpRequest();
						xhr.open('GET', ajaxurl + '?action=mvb_search_catalog&nonce=<?php echo esc_js( wp_create_nonce( 'mvb_search_catalog' ) ); ?>&term=' + encodeURIComponent(term));
						xhr.onload = function() {
							if (200 === xhr.status) {
								var res = JSON.parse(xhr.responseText);
								if (res.success && res.data.length > 0) {
									var html = '<ul style="list-style:none;margin:0;padding:0;border:1px solid #ddd;background:#fff;max-height:200px;overflow:auto;">';
									res.data.forEach(function(game) {
										html += '<li style="padding:6px 10px;cursor:pointer;display:flex;align-items:center;gap:8px;" data-id="' + game.id + '" data-title="' + game.title.replace(/"/g, '&quot;') + '">';
										if (game.thumb) {
											html += '<img src="' + game.thumb + '" width="32" height="32" style="object-fit:cover;" />';
										}
										html += '<span>' + game.title + '</span></li>';
									});
									html += '</ul>';
									searchResults.innerHTML = html;

									searchResults.querySelectorAll('li').forEach(function(li) {
										li.addEventListener('click', function() {
											videogameId.value = this.getAttribute('data-id');
											searchInput.value  = this.getAttribute('data-title');
											selectedGame.innerHTML = '<strong><?php echo esc_js( __( 'Selected:', 'mvb' ) ); ?></strong> ' + this.getAttribute('data-title');
											searchResults.innerHTML = '';
										});
									});
								} else {
									searchResults.innerHTML = '<p style="padding:8px;"><?php echo esc_js( __( 'No games found.', 'mvb' ) ); ?></p>';
								}
							}
						};
						xhr.send();
					}, 300);
				});
			}

			var form    = document.getElementById('mvb-add-library-form');
			var message = document.getElementById('mvb-library-message');

			if (form) {
				form.addEventListener('submit', function(e) {
					e.preventDefault();

					if (!videogameId.value) {
						if (message) {
							message.style.display = 'block';
							message.className = 'notice notice-error';
							message.innerHTML = '<p><?php echo esc_js( __( 'Please select a game from the search results.', 'mvb' ) ); ?></p>';
						}
						return;
					}

					var formData = new FormData(form);
					formData.append('action', 'mvb_add_library_entry');

					var xhr = new XMLHttpRequest();
					xhr.open('POST', ajaxurl);
					xhr.onload = function() {
						if (200 === xhr.status) {
							var res = JSON.parse(xhr.responseText);
							if (message) {
								message.style.display = 'block';
								if (res.success) {
									message.className = 'notice notice-success';
									message.innerHTML = '<p>' + res.data.message + '</p>';
									form.reset();
									videogameId.value = '';
									selectedGame.innerHTML = '';
									setTimeout(function() { location.reload(); }, 1500);
								} else {
									message.className = 'notice notice-error';
									message.innerHTML = '<p>' + res.data.message + '</p>';
								}
							}
						}
					};
					xhr.send(formData);
				});
			}
		}());
		</script>
		<?php
	}

	/**
	 * AJAX handler — add a library entry for the current user.
	 */
	public static function handle_add_library_entry() {
		check_ajax_referer( 'mvb_add_library_entry', 'mvb_library_nonce' );

		if ( ! current_user_can( 'publish_mvb_library_entries' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to add library entries.', 'mvb' ) ) );
		}

		$videogame_id = absint( isset( $_POST['videogame_id'] ) ? wp_unslash( $_POST['videogame_id'] ) : 0 );
		if ( 0 === $videogame_id ) {
			wp_send_json_error( array( 'message' => __( 'No game selected.', 'mvb' ) ) );
		}

		$videogame = get_post( $videogame_id );
		if ( ! $videogame instanceof WP_Post || 'videogame' !== $videogame->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Invalid game selected.', 'mvb' ) ) );
		}

		$user_id = get_current_user_id();

		if ( MVB_Library::user_has_game( $user_id, $videogame_id ) ) {
			wp_send_json_error( array( 'message' => __( 'This game is already in your library.', 'mvb' ) ) );
		}

		$status          = MVB_Library::sanitize_status( isset( $_POST['library_status'] ) ? sanitize_text_field( wp_unslash( $_POST['library_status'] ) ) : 'backlog' );
		$completion_date = MVB_Library::sanitize_ymd_date( isset( $_POST['library_completion_date'] ) ? sanitize_text_field( wp_unslash( $_POST['library_completion_date'] ) ) : '' );
		$device_id       = absint( isset( $_POST['library_device_id'] ) ? wp_unslash( $_POST['library_device_id'] ) : 0 );
		$rating          = MVB_Library::sanitize_rating( isset( $_POST['library_rating'] ) ? sanitize_text_field( wp_unslash( $_POST['library_rating'] ) ) : 0 );
		$notes           = wp_kses_post( isset( $_POST['library_notes'] ) ? wp_unslash( $_POST['library_notes'] ) : '' );

		$entry_id = wp_insert_post(
			array(
				'post_type'   => 'mvb_library_entry',
				'post_status' => 'publish',
				'post_author' => $user_id,
				'post_title'  => $videogame->post_title,
			),
			true
		);

		if ( is_wp_error( $entry_id ) ) {
			wp_send_json_error( array( 'message' => $entry_id->get_error_message() ) );
		}

		update_post_meta( $entry_id, 'library_videogame_id', $videogame_id );
		update_post_meta( $entry_id, 'library_status', $status );

		if ( '' !== $completion_date ) {
			update_post_meta( $entry_id, 'library_completion_date', $completion_date );
		}

		if ( $device_id > 0 ) {
			update_post_meta( $entry_id, 'library_device_id', $device_id );
		}

		if ( $rating > 0 ) {
			update_post_meta( $entry_id, 'library_rating', $rating );
		}

		if ( '' !== $notes ) {
			update_post_meta( $entry_id, 'library_notes', $notes );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: game title */
					__( '"%s" has been added to your library.', 'mvb' ),
					$videogame->post_title
				),
			)
		);
	}

	/**
	 * AJAX handler — search the videogame catalog by title.
	 */
	public static function handle_search_catalog() {
		check_ajax_referer( 'mvb_search_catalog', 'nonce' );

		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'mvb' ) ) );
		}

		$term = sanitize_text_field( isset( $_GET['term'] ) ? wp_unslash( $_GET['term'] ) : '' );
		if ( strlen( $term ) < 2 ) {
			wp_send_json_success( array() );
		}

		$games = get_posts(
			array(
				'post_type'      => 'videogame',
				'post_status'    => 'publish',
				's'              => $term,
				'posts_per_page' => 10,
				'orderby'        => 'relevance',
			)
		);

		$results = array();
		foreach ( $games as $game ) {
			$cover_id = get_post_meta( $game->ID, 'videogame_cover', true );
			$thumb    = '';
			if ( $cover_id ) {
				$img   = wp_get_attachment_image_src( (int) $cover_id, 'thumbnail' );
				$thumb = is_array( $img ) ? $img[0] : '';
			}

			$results[] = array(
				'id'    => $game->ID,
				'title' => $game->post_title,
				'thumb' => $thumb,
			);
		}

		wp_send_json_success( $results );
	}

	/**
	 * AJAX handler — delete a library entry owned by the current user.
	 */
	public static function handle_delete_library_entry() {
		$entry_id = absint( isset( $_GET['entry_id'] ) ? wp_unslash( $_GET['entry_id'] ) : 0 );
		if ( 0 === $entry_id ) {
			wp_die( esc_html__( 'Invalid entry.', 'mvb' ) );
		}

		check_admin_referer( 'mvb_delete_entry_' . $entry_id );

		if ( ! current_user_can( 'delete_post', $entry_id ) ) {
			wp_die( esc_html__( 'You do not have permission to delete this entry.', 'mvb' ) );
		}

		$post = get_post( $entry_id );
		if ( ! $post instanceof WP_Post || 'mvb_library_entry' !== $post->post_type ) {
			wp_die( esc_html__( 'Entry not found.', 'mvb' ) );
		}

		wp_trash_post( $entry_id );

		wp_safe_redirect( admin_url( 'admin.php?page=mvb-my-library&deleted=1' ) );
		exit;
	}
}
