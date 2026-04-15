<?php
/**
 * IGDB API Handler
 *
 * @package MVB
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * MVB_IGDB_API class
 */
class MVB_IGDB_API {

	/**
	 * Get access token
	 *
	 * @return string|WP_Error Access token or WP_Error on failure
	 */
	public static function get_access_token() {
		$token   = get_option( 'mvb_igdb_access_token' );
		$expires = get_option( 'mvb_igdb_token_expires' );

		// If token exists and is not expired, return it
		if ( $token && $expires && time() < $expires ) {
			return $token;
		}

		// Token doesn't exist or is expired, get a new one
		return self::refresh_access_token();
	}

	/**
	 * Refresh access token
	 *
	 * @return string|WP_Error New access token or WP_Error on failure
	 */
	public static function refresh_access_token() {
		$client_id     = get_option( 'mvb_igdb_client_id' );
		$client_secret = get_option( 'mvb_igdb_client_secret' );

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			return new WP_Error(
				'missing_credentials',
				__( 'IGDB API credentials are not configured.', 'mvb' )
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
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! isset( $body['access_token'] ) ) {
			return new WP_Error(
				'token_error',
				isset( $body['message'] ) ? $body['message'] : __( 'Failed to obtain access token', 'mvb' )
			);
		}

		// Save the new token and expiration
		update_option( 'mvb_igdb_access_token', $body['access_token'] );
		update_option( 'mvb_igdb_token_expires', time() + $body['expires_in'] );

		return $body['access_token'];
	}

	/**
	 * Make an API request to IGDB
	 *
	 * @param string $endpoint The API endpoint.
	 * @param string $query The API query.
	 * @return array|WP_Error Response data or WP_Error on failure
	 */
	public static function request( $endpoint, $query ) {
		$token = self::get_access_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$client_id = get_option( 'mvb_igdb_client_id' );

		$response = wp_remote_post(
			'https://api.igdb.com/v4/' . $endpoint,
			array(
				'headers' => array(
					'Client-ID'     => $client_id,
					'Authorization' => 'Bearer ' . $token,
				),
				'body'    => $query,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! $body ) {
			return new WP_Error(
				'api_error',
				__( 'Invalid response from IGDB API', 'mvb' )
			);
		}

		return $body;
	}

	/**
	 * Search for games
	 *
	 * @param string $query Search query.
	 * @param int    $limit Number of results to return.
	 * @return array|WP_Error Array of games on success, WP_Error on failure.
	 */
	public static function search_games( $query, $limit = 10 ) {
		$access_token = self::get_access_token();
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		// Escape characters that would break out of the IGDB APICalypse search literal.
		$safe_query = str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), (string) $query );
		$safe_limit = max( 1, min( 50, (int) $limit ) );

		$response = wp_remote_post(
			'https://api.igdb.com/v4/games',
			array(
				'headers' => array(
					'Client-ID'     => get_option( 'mvb_igdb_client_id' ),
					'Authorization' => 'Bearer ' . $access_token,
				),
				'body'    => 'search "' . $safe_query . '";
						  fields name,summary,first_release_date,cover.*,involved_companies.*,involved_companies.company.*,platforms.*;
						  limit ' . $safe_limit . ';',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body ) ) {
			return array();
		}

		return $body;
	}

	/**
	 * Get game by ID
	 *
	 * @param int $game_id Game ID.
	 * @return array|WP_Error Game data or WP_Error on failure
	 */
	public static function get_game( $game_id ) {
		$query = 'fields name, cover.url, first_release_date, summary,
				 genres.name, rating, rating_count, involved_companies.*, 
				 involved_companies.company.*;
				 where id = ' . absint( $game_id ) . ';';

		$result = self::request( 'games', $query );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return ! empty( $result[0] ) ? $result[0] : new WP_Error(
			'not_found',
			__( 'Game not found', 'mvb' )
		);
	}

	/**
	 * Register AJAX handlers and scripts
	 */
	public static function init() {
		add_action( 'wp_ajax_mvb_search_games', array( __CLASS__, 'handle_search_ajax' ) );
		add_action( 'wp_ajax_mvb_add_game', array( __CLASS__, 'handle_add_game_ajax' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_igdb_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_igdb_settings' ) );
	}

	/**
	 * Add IGDB settings page.
	 */
	public static function add_igdb_settings_page() {
		// Global settings for admins
		if ( current_user_can( 'manage_options' ) ) {
			add_submenu_page(
				'edit.php?post_type=videogame',
				__( 'IGDB Settings', 'mvb' ),
				__( 'IGDB Settings', 'mvb' ),
				'manage_options',
				'mvb-igdb-settings',
				array( __CLASS__, 'render_igdb_settings_page' )
			);
		}

		// User-specific settings for gamers
		if ( current_user_can( 'mvb_manage_igdb_settings' ) ) {
			add_submenu_page(
				'edit.php?post_type=videogame',
				__( 'Your IGDB Settings', 'mvb' ),
				__( 'Your IGDB Settings', 'mvb' ),
				'mvb_manage_igdb_settings',
				'mvb-user-igdb-settings',
				array( __CLASS__, 'render_user_igdb_settings_page' )
			);
		}
	}

	/**
	 * Register IGDB settings.
	 */
	public static function register_igdb_settings() {
		register_setting( 'mvb_igdb_settings', 'mvb_igdb_client_id' );
		register_setting( 'mvb_igdb_settings', 'mvb_igdb_client_secret' );
	}

	/**
	 * Render IGDB settings page.
	 */
	public static function render_igdb_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Save settings if form was submitted
		if ( isset( $_POST['submit'] ) && check_admin_referer( 'mvb_igdb_settings' ) ) {
			$client_id     = isset( $_POST['mvb_igdb_client_id'] ) ?
				sanitize_text_field( wp_unslash( $_POST['mvb_igdb_client_id'] ) ) : '';
			$client_secret = isset( $_POST['mvb_igdb_client_secret'] ) ?
				sanitize_text_field( wp_unslash( $_POST['mvb_igdb_client_secret'] ) ) : '';

			update_option( 'mvb_igdb_client_id', $client_id );
			update_option( 'mvb_igdb_client_secret', $client_secret );

			// Clear access token to force refresh with new credentials
			delete_option( 'mvb_igdb_access_token' );
			delete_option( 'mvb_igdb_token_expires' );

			echo '<div class="notice notice-success"><p>' .
				esc_html__( 'Settings saved successfully.', 'mvb' ) . '</p></div>';
		}

		// Get current values
		$client_id     = get_option( 'mvb_igdb_client_id', '' );
		$client_secret = get_option( 'mvb_igdb_client_secret', '' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="">
				<?php wp_nonce_field( 'mvb_igdb_settings' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="mvb_igdb_client_id">
								<?php esc_html_e( 'Client ID', 'mvb' ); ?>
							</label>
						</th>
						<td>
							<input type="text" 
									id="mvb_igdb_client_id" 
									name="mvb_igdb_client_id" 
									value="<?php echo esc_attr( $client_id ); ?>" 
									class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="mvb_igdb_client_secret">
								<?php esc_html_e( 'Client Secret', 'mvb' ); ?>
							</label>
						</th>
						<td>
							<input type="password" 
									id="mvb_igdb_client_secret" 
									name="mvb_igdb_client_secret" 
									value="<?php echo esc_attr( $client_secret ); ?>" 
									class="regular-text" />
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render user-specific IGDB settings page.
	 */
	public static function render_user_igdb_settings_page() {
		if ( ! current_user_can( 'mvb_manage_igdb_settings' ) ) {
			return;
		}

		$user_id = get_current_user_id();

		// Save settings if form was submitted
		if ( isset( $_POST['submit'] ) && check_admin_referer( 'mvb_user_igdb_settings' ) ) {
			$client_id     = isset( $_POST['mvb_igdb_client_id'] ) ?
				sanitize_text_field( wp_unslash( $_POST['mvb_igdb_client_id'] ) ) : '';
			$client_secret = isset( $_POST['mvb_igdb_client_secret'] ) ?
				sanitize_text_field( wp_unslash( $_POST['mvb_igdb_client_secret'] ) ) : '';

			update_user_meta( $user_id, 'mvb_igdb_client_id', $client_id );
			update_user_meta( $user_id, 'mvb_igdb_client_secret', $client_secret );

			echo '<div class="notice notice-success"><p>' .
				esc_html__( 'Your IGDB settings have been saved.', 'mvb' ) . '</p></div>';
		}

		// Get current values
		$client_id     = get_user_meta( $user_id, 'mvb_igdb_client_id', true );
		$client_secret = get_user_meta( $user_id, 'mvb_igdb_client_secret', true );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p><?php esc_html_e( 'Enter your IGDB API credentials to manage your video game collection.', 'mvb' ); ?></p>
			<p>
				<?php
				printf(
					/* translators: %s: URL to IGDB API documentation */
					wp_kses(
						__( 'You can get your API credentials from the <a href="%s" target="_blank">IGDB API portal</a>.', 'mvb' ),
						array(
							'a' => array(
								'href'   => array(),
								'target' => array(),
							),
						)
					),
					'https://api-docs.igdb.com/#getting-started'
				);
				?>
			</p>
			<form method="post" action="">
				<?php wp_nonce_field( 'mvb_user_igdb_settings' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="mvb_igdb_client_id">
								<?php esc_html_e( 'Client ID', 'mvb' ); ?>
							</label>
						</th>
						<td>
							<input type="text" 
									id="mvb_igdb_client_id" 
									name="mvb_igdb_client_id" 
									value="<?php echo esc_attr( $client_id ); ?>" 
									class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="mvb_igdb_client_secret">
								<?php esc_html_e( 'Client Secret', 'mvb' ); ?>
							</label>
						</th>
						<td>
							<input type="password" 
									id="mvb_igdb_client_secret" 
									name="mvb_igdb_client_secret" 
									value="<?php echo esc_attr( $client_secret ); ?>" 
									class="regular-text" />
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Your Settings', 'mvb' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Check if game already exists
	 *
	 * @param string $game_name Game name to check.
	 * @return bool|int Post ID if exists, false otherwise
	 */
	private static function game_exists( $game_name ) {
		$args = array(
			'post_type'      => 'videogame',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'title'          => $game_name,
			'exact'          => true,
		);

		$query = new WP_Query( $args );

		if ( $query->have_posts() ) {
			return $query->posts[0]->ID;
		}

		return false;
	}

	/**
	 * Handle AJAX search request
	 */
	public static function handle_search_ajax() {
		check_ajax_referer( 'mvb_search', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access', 'mvb' ) ), 403 );
		}

		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

		if ( empty( $search ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a search term', 'mvb' ) ) );
		}

		$results = self::search_games( $search, 15 );

		if ( is_wp_error( $results ) ) {
			wp_send_json_error( array( 'message' => $results->get_error_message() ) );
		}

		// Check each game if it exists in the database
		foreach ( $results as &$game ) {
			$existing_id         = self::game_exists( $game['name'] );
			$game['exists']      = $existing_id ? true : false;
			$game['existing_id'] = $existing_id;

			// Format release date if exists
			if ( ! empty( $game['first_release_date'] ) ) {
				$game['release_date'] = date( 'M j, Y', $game['first_release_date'] );
			}
		}

		wp_send_json_success( array( 'games' => $results ) );
	}

	/**
	 * Download and attach image from URL
	 *
	 * @param string $url Image URL.
	 * @param int    $post_id Post ID to attach the image to.
	 * @return int|WP_Error Attachment ID if successful, WP_Error otherwise
	 */
	private static function attach_remote_image( $url, $post_id ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Add https: if the URL starts with //
		if ( strpos( $url, '//' ) === 0 ) {
			$url = 'https:' . $url;
		}

		// Download file to temp dir
		$temp_file = download_url( $url );

		if ( is_wp_error( $temp_file ) ) {
			return $temp_file;
		}

		$file_array = array(
			'name'     => basename( $url ),
			'tmp_name' => $temp_file,
		);

		// Check file type
		$file_type = wp_check_filetype( $file_array['name'], null );
		if ( empty( $file_type['type'] ) ) {
			@unlink( $temp_file );
			return new WP_Error( 'invalid_file_type', __( 'Invalid file type', 'mvb' ) );
		}

		// Upload the file
		$attachment_id = media_handle_sideload( $file_array, $post_id );

		// Clean up
		@unlink( $temp_file );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		return $attachment_id;
	}

	/**
	 * Process involved companies for a game
	 *
	 * @param int   $post_id Post ID.
	 * @param array $game IGDB game data.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function process_companies( $post_id, $game ) {
		if ( empty( $game['involved_companies'] ) ) {
			return true;
		}

		try {
			// First, let's clean up any existing numeric company terms
			$existing_terms = wp_get_object_terms( $post_id, 'mvb_company' );
			if ( ! is_wp_error( $existing_terms ) ) {
				foreach ( $existing_terms as $term ) {
					if ( is_numeric( $term->name ) || preg_match( '/^\d+$/', $term->name ) ) {
						wp_remove_object_terms( $post_id, $term->term_id, 'mvb_company' );
						wp_delete_term( $term->term_id, 'mvb_company' );
					}
				}
			}

			foreach ( $game['involved_companies'] as $involved_company ) {
				if ( empty( $involved_company['company'] ) ) {
					continue;
				}

				$company = $involved_company['company'];

				// Skip if company name is just a number or empty
				if ( empty( $company['name'] ) ||
					is_numeric( $company['name'] ) ||
					preg_match( '/^\d+$/', $company['name'] ) ||
					trim( $company['name'] ) === ''
				) {
					continue;
				}

				$term_id = MVB_Taxonomies::add_or_update_company( $company );

				if ( is_wp_error( $term_id ) ) {
					continue;
				}

				$roles = array();
				if ( ! empty( $involved_company['developer'] ) ) {
					$roles[] = 'developer';
				}
				if ( ! empty( $involved_company['publisher'] ) ) {
					$roles[] = 'publisher';
				}
				if ( ! empty( $involved_company['supporting'] ) ) {
					$roles[] = 'supporting';
				}
				if ( ! empty( $involved_company['porting'] ) ) {
					$roles[] = 'porting';
				}

				foreach ( $roles as $role ) {
					MVB_Taxonomies::link_company_to_game( $post_id, $term_id, $role );
				}
			}
			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'company_processing_error', $e->getMessage() );
		}
	}

	/**
	 * Process platforms for a game
	 *
	 * @param int   $post_id Post ID.
	 * @param array $game IGDB game data.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function process_platforms( $post_id, $game ) {
		if ( empty( $game['platforms'] ) ) {
			return true;
		}

		try {
			// First, let's clean up any existing numeric platform terms
			$existing_terms = wp_get_object_terms( $post_id, 'mvb_platform' );
			if ( ! is_wp_error( $existing_terms ) ) {
				foreach ( $existing_terms as $term ) {
					if ( is_numeric( $term->name ) || preg_match( '/^\d+$/', $term->name ) ) {
						wp_remove_object_terms( $post_id, $term->term_id, 'mvb_platform' );
						wp_delete_term( $term->term_id, 'mvb_platform' );
					}
				}
			}

			foreach ( $game['platforms'] as $platform ) {
				if ( empty( $platform['name'] ) ||
					is_numeric( $platform['name'] ) ||
					preg_match( '/^\d+$/', $platform['name'] ) ||
					trim( $platform['name'] ) === ''
				) {
					continue;
				}

				// Make sure we have a slug
				if ( empty( $platform['slug'] ) ) {
					$platform['slug'] = sanitize_title( $platform['name'] );
				}

				$term_id = MVB_Taxonomies::add_or_update_platform( $platform );

				if ( is_wp_error( $term_id ) ) {
					continue;
				}

				MVB_Taxonomies::link_platform_to_game( $post_id, $term_id );
			}
			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'platform_processing_error', $e->getMessage() );
		}
	}

	/**
	 * Register game from IGDB
	 */
	public function register_game( $igdb_id ) {
		$game_data = $this->get_game_data( $igdb_id );

		if ( empty( $game_data ) ) {
			return new WP_Error( 'no_game_data', __( 'No game data found', 'mvb' ) );
		}

		// ... existing game registration code ...

		// Process companies
		$this->process_companies( $post_id, $game_data );

		// ... rest of the code ...
	}

	/**
	 * Create videogame post from IGDB data
	 *
	 * @param array $game_data Game data from IGDB.
	 * @return int|WP_Error Post ID on success, WP_Error on failure
	 */
	public static function create_videogame_post( $game_data ) {
		if ( ! function_exists( 'update_field' ) ) {
			return new WP_Error( 'acf_missing', 'Secure Custom Fields plugin is not active' );
		}

		try {
			$post_data = array(
				'post_title'   => sanitize_text_field( $game_data['name'] ),
				'post_type'    => 'videogame',
				'post_status'  => 'publish',
				'post_content' => isset( $game_data['summary'] ) ?
					wp_kses_post( $game_data['summary'] ) : '',
			);

			$post_id = wp_insert_post( $post_data, true );

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			if ( ! empty( $game_data['cover']['url'] ) ) {
				$cover_url = str_replace( 't_thumb', 't_cover_big', $game_data['cover']['url'] );
				try {
					$attachment_id = self::attach_remote_image( $cover_url, $post_id );
					if ( ! is_wp_error( $attachment_id ) ) {
						update_field( 'videogame_cover', $attachment_id, $post_id );
						set_post_thumbnail( $post_id, $attachment_id );
					}
				} catch ( Exception $e ) {
					// Ignore image failure so the rest of the post is still created.
				}
			}

			// Set release date
			if ( ! empty( $game_data['first_release_date'] ) ) {
				try {
					$date = gmdate( 'Ymd', $game_data['first_release_date'] );
					update_field( 'videogame_release_date', $date, $post_id );
				} catch ( Exception $e ) {
					// Ignore release date failure.
				}
			}

			// Set default status
			try {
				if ( class_exists( 'MVB_Data_Health' ) ) {
					MVB_Data_Health::set_status_taxonomy( $post_id, 'backlog' );
				} else {
					wp_set_object_terms( $post_id, 'backlog', 'mvb_game_status', false );
				}
			} catch ( Exception $e ) {
				// Ignore status failure.
			}

			// Process companies and platforms; individual failures are handled inside.
			self::process_companies( $post_id, $game_data );
			self::process_platforms( $post_id, $game_data );

			return $post_id;

		} catch ( Exception $e ) {
			return new WP_Error( 'creation_failed', $e->getMessage() );
		}
	}

	/**
	 * Handle AJAX add game request
	 */
	public static function handle_add_game_ajax() {
		try {
			check_ajax_referer( 'mvb_add_game', 'nonce' );

			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error( array( 'message' => __( 'Unauthorized access', 'mvb' ) ) );
			}

			$raw_data  = isset( $_POST['game'] ) ? wp_unslash( $_POST['game'] ) : '';
			$game_data = is_string( $raw_data ) ? json_decode( $raw_data, true ) : null;

			if ( ! is_array( $game_data ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Invalid game data', 'mvb' ),
					)
				);
				return;
			}

			$result = self::create_videogame_post( $game_data );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			wp_send_json_success(
				array(
					'message' => __( 'Game added successfully!', 'mvb' ),
					'post_id' => $result,
				)
			);
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Get IGDB credentials for the current context.
	 *
	 * @return array Array containing client_id and client_secret.
	 */
	public static function get_igdb_credentials() {
		if ( current_user_can( 'manage_options' ) ) {
			// Admin users use global settings
			return array(
				'client_id'     => get_option( 'mvb_igdb_client_id', '' ),
				'client_secret' => get_option( 'mvb_igdb_client_secret', '' ),
			);
		} else {
			// Regular users use their own settings
			$user_id = get_current_user_id();
			return array(
				'client_id'     => get_user_meta( $user_id, 'mvb_igdb_client_id', true ),
				'client_secret' => get_user_meta( $user_id, 'mvb_igdb_client_secret', true ),
			);
		}
	}

	/**
	 * Update game cover from IGDB data
	 *
	 * @param int   $post_id Post ID.
	 * @param array $game_data IGDB game data.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function update_game_cover( $post_id, $game_data ) {
		if ( empty( $game_data['cover']['url'] ) ) {
			return new WP_Error(
				'no_cover',
				__( 'No cover image available for this game', 'mvb' )
			);
		}

		$cover_url = str_replace( 't_thumb', 't_cover_big', $game_data['cover']['url'] );
		try {
			$attachment_id = self::attach_remote_image( $cover_url, $post_id );

			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			$acf_result = update_field( 'videogame_cover', $attachment_id, $post_id );
			set_post_thumbnail( $post_id, $attachment_id );

			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'image_error', $e->getMessage() );
		}
	}

	/**
	 * Update all game covers
	 *
	 * @return array Array with results of the operation
	 */
	public static function update_all_game_covers() {
		$args = array(
			'post_type'      => 'videogame',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		);

		$games   = get_posts( $args );
		$results = array(
			'total'     => count( $games ),
			'processed' => 0,
			'updated'   => 0,
			'errors'    => array(),
		);

		foreach ( $games as $game ) {
			++$results['processed'];

			try {
				// Search for the game in IGDB by name.
				$search_results = self::search_games( $game->post_title, 1 );

				if ( is_wp_error( $search_results ) ) {
					$results['errors'][] = sprintf(
						__( 'Failed to search for "%1$s": %2$s', 'mvb' ),
						$game->post_title,
						$search_results->get_error_message()
					);
					continue;
				}

				if ( empty( $search_results ) ) {
					$results['errors'][] = sprintf(
						__( 'No IGDB match found for "%s"', 'mvb' ),
						$game->post_title
					);
					continue;
				}

				// Get the first (best) match.
				$game_data = $search_results[0];
				if ( empty( $game_data ) ) {
					$results['errors'][] = sprintf(
						__( 'Invalid data received for "%s"', 'mvb' ),
						$game->post_title
					);
					continue;
				}

				// Update the cover.
				$update_result = self::update_game_cover( $game->ID, $game_data );
				if ( is_wp_error( $update_result ) ) {
					$results['errors'][] = sprintf(
						__( 'Error updating cover for "%1$s": %2$s', 'mvb' ),
						$game->post_title,
						$update_result->get_error_message()
					);
					continue;
				}

				++$results['updated'];
			} catch ( Exception $e ) {
				$results['errors'][] = sprintf(
					__( 'Exception while processing "%1$s": %2$s', 'mvb' ),
					$game->post_title,
					$e->getMessage()
				);
			}
		}

		return $results;
	}
}
