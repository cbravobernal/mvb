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
		add_action( 'wp_ajax_mvb_test_igdb_connection', array( __CLASS__, 'test_igdb_connection' ) );
		add_action( 'wp_ajax_mvb_sync_platforms', array( __CLASS__, 'handle_sync_platforms_ajax' ) );
		add_action( 'wp_ajax_mvb_sync_companies', array( __CLASS__, 'handle_sync_companies_ajax' ) );
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
		// Check if we're on either the settings page or the add game page
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

			wp_localize_script(
				'mvb-admin',
				'MVBAdmin',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'mvb_test_connection' ),
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
		</div>
		<?php
	}

	/**
	 * Handle platform sync AJAX request
	 */
	public static function handle_sync_platforms_ajax() {
		check_ajax_referer( 'mvb_sync_platforms', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access', 'mvb' ) ) );
		}

		$result = MVB_IGDB_API::sync_platforms();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				)
			);
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					__( 'Platforms synced successfully! Created: %1$d, Updated: %2$d, Errors: %3$d', 'mvb' ),
					$result['created'],
					$result['updated'],
					count( $result['errors'] )
				),
				'details' => $result,
			)
		);
	}

	/**
	 * Handle company sync AJAX request
	 */
	public static function handle_sync_companies_ajax() {
		check_ajax_referer( 'mvb_sync_companies', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access', 'mvb' ) ) );
		}

		$result = MVB_IGDB_API::sync_companies();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				)
			);
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					__( 'Companies synced successfully! Created: %1$d, Updated: %2$d, Errors: %3$d', 'mvb' ),
					$result['created'],
					$result['updated'],
					count( $result['errors'] )
				),
				'details' => $result,
			)
		);
	}
}
