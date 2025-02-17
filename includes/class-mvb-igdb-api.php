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
	public static function search_games($query, $limit = 10) {
		$access_token = self::get_access_token();
		if (is_wp_error($access_token)) {
			return $access_token;
		}

		$response = wp_remote_post(
			'https://api.igdb.com/v4/games',
			array(
				'headers' => array(
					'Client-ID' => get_option('mvb_igdb_client_id'),
					'Authorization' => 'Bearer ' . $access_token,
				),
				'body' => 'search "' . $query . '"; 
						  fields name,summary,first_release_date,cover.*,involved_companies.*,involved_companies.company.*,platforms.*; 
						  limit ' . $limit . ';',
			)
		);

		if (is_wp_error($response)) {
			return $response;
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);

		if (empty($body)) {
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
		error_log( '=== Starting get_game for ID: ' . $game_id . ' ===' );

		$query = 'fields name, cover.url, first_release_date, summary,
				 genres.name, rating, rating_count, involved_companies.*, 
				 involved_companies.company.*;
				 where id = ' . absint( $game_id ) . ';';

		error_log( 'IGDB Query: ' . $query );
		$result = self::request( 'games', $query );

		if ( is_wp_error( $result ) ) {
			error_log( 'Error getting game: ' . $result->get_error_message() );
			return $result;
		}

		error_log( 'Game API Response: ' . print_r( $result, true ) );

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

		$search = isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '';

		if ( empty( $search ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a search term', 'mvb' ) ) );
		}

		$results = self::search_games( $search, 5 );

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
	public static function process_companies($post_id, $game) {
		error_log('=== Processing companies for game ID: ' . $post_id . ' ===');
		
		if (empty($game['involved_companies'])) {
			error_log('No involved companies found');
			return true;
		}

		try {
			// First, let's clean up any existing numeric company terms
			$existing_terms = wp_get_object_terms($post_id, 'mvb_company');
			if (!is_wp_error($existing_terms)) {
				foreach ($existing_terms as $term) {
					if (is_numeric($term->name) || preg_match('/^\d+$/', $term->name)) {
						error_log('Deleting numeric company term: ' . $term->name);
						wp_remove_object_terms($post_id, $term->term_id, 'mvb_company');
						wp_delete_term($term->term_id, 'mvb_company');
					}
				}
			}

			foreach ($game['involved_companies'] as $involved_company) {
				error_log('Processing involved company: ' . print_r($involved_company, true));
				
				if (empty($involved_company['company'])) {
					error_log('No company data found in involved company');
					continue;
				}

				$company = $involved_company['company'];

				// Skip if company name is just a number or empty
				if (empty($company['name']) || 
					is_numeric($company['name']) || 
					preg_match('/^\d+$/', $company['name']) ||
					trim($company['name']) === ''
				) {
					error_log('Skipping invalid company name: ' . ($company['name'] ?? 'empty'));
					continue;
				}

				error_log('Adding/updating company: ' . print_r($company, true));
				
				$term_id = MVB_Taxonomies::add_or_update_company($company);

				if (is_wp_error($term_id)) {
					error_log('Error adding company: ' . $term_id->get_error_message());
					continue;
				}

				error_log('Successfully added/updated company with term ID: ' . $term_id);

				// Determine company role
				$roles = array();
				if (!empty($involved_company['developer'])) {
					$roles[] = 'developer';
				}
				if (!empty($involved_company['publisher'])) {
					$roles[] = 'publisher';
				}
				if (!empty($involved_company['supporting'])) {
					$roles[] = 'supporting';
				}
				if (!empty($involved_company['porting'])) {
					$roles[] = 'porting';
				}

				error_log('Company roles: ' . implode(', ', $roles));

				foreach ($roles as $role) {
					MVB_Taxonomies::link_company_to_game($post_id, $term_id, $role);
					error_log('Linked company ' . $term_id . ' to game ' . $post_id . ' with role: ' . $role);
				}
			}
			return true;
		} catch (Exception $e) {
			error_log('Error processing companies: ' . $e->getMessage());
			return new WP_Error('company_processing_error', $e->getMessage());
		}
	}

	/**
	 * Process platforms for a game
	 *
	 * @param int   $post_id Post ID.
	 * @param array $game IGDB game data.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function process_platforms($post_id, $game) {
		error_log('=== Processing platforms for game ID: ' . $post_id . ' ===');
		error_log('Game data: ' . print_r($game, true));
		
		if (empty($game['platforms'])) {
			error_log('No platforms found');
			return true;
		}

		try {
			// First, let's clean up any existing numeric platform terms
			$existing_terms = wp_get_object_terms($post_id, 'mvb_platform');
			if (!is_wp_error($existing_terms)) {
				foreach ($existing_terms as $term) {
					if (is_numeric($term->name) || preg_match('/^\d+$/', $term->name)) {
						error_log('Deleting numeric platform term: ' . $term->name);
						wp_remove_object_terms($post_id, $term->term_id, 'mvb_platform');
						wp_delete_term($term->term_id, 'mvb_platform');
					}
				}
			}

			foreach ($game['platforms'] as $platform) {
				error_log('Processing platform: ' . print_r($platform, true));

				// Skip if platform name is just a number or empty
				if (empty($platform['name']) || 
					is_numeric($platform['name']) || 
					preg_match('/^\d+$/', $platform['name']) ||
					trim($platform['name']) === ''
				) {
					error_log('Skipping invalid platform name: ' . ($platform['name'] ?? 'empty'));
					continue;
				}

				// Make sure we have a slug
				if (empty($platform['slug'])) {
					$platform['slug'] = sanitize_title($platform['name']);
				}

				error_log('Adding/updating platform: ' . print_r($platform, true));
				
				$term_id = MVB_Taxonomies::add_or_update_platform($platform);

				if (is_wp_error($term_id)) {
					error_log('Error adding platform: ' . $term_id->get_error_message());
					continue;
				}

				error_log('Successfully added/updated platform with term ID: ' . $term_id);

				// Link platform to game
				$link_result = MVB_Taxonomies::link_platform_to_game($post_id, $term_id);
				if (is_wp_error($link_result)) {
					error_log('Error linking platform: ' . $link_result->get_error_message());
				} else {
					error_log('Linked platform ' . $term_id . ' to game ' . $post_id);
				}
			}
			return true;
		} catch (Exception $e) {
			error_log('Error processing platforms: ' . $e->getMessage());
			return new WP_Error('platform_processing_error', $e->getMessage());
		}
	}

	/**
	 * Register game from IGDB
	 */
	public function register_game($igdb_id) {
		$game_data = $this->get_game_data($igdb_id);
		
		if (empty($game_data)) {
			return new WP_Error('no_game_data', __('No game data found', 'mvb'));
		}

		// ... existing game registration code ...

		// Process companies
		$this->process_companies($post_id, $game_data);

		// ... rest of the code ...
	}

	/**
	 * Create videogame post from IGDB data
	 *
	 * @param array $game_data Game data from IGDB.
	 * @return int|WP_Error Post ID on success, WP_Error on failure
	 */
	public static function create_videogame_post( $game_data ) {
		error_log( '=== Start creating videogame post ===' );

		if ( ! function_exists( 'update_field' ) ) {
			error_log( 'SCF is not active!' );
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

			error_log( 'Creating post with data: ' . print_r( $post_data, true ) );
			$post_id = wp_insert_post( $post_data, true );

			if ( is_wp_error( $post_id ) ) {
				error_log( 'Error creating post: ' . $post_id->get_error_message() );
				return $post_id;
			}

			error_log( 'Post created with ID: ' . $post_id );

			// Handle cover image
			if ( ! empty( $game_data['cover']['url'] ) ) {
				$cover_url = str_replace( 't_thumb', 't_cover_big', $game_data['cover']['url'] );
				error_log( 'Processing cover image: ' . $cover_url );

				try {
					$attachment_id = self::attach_remote_image( $cover_url, $post_id );

					if ( is_wp_error( $attachment_id ) ) {
						error_log( 'Error attaching image: ' . $attachment_id->get_error_message() );
					} else {
						error_log( 'Image attached with ID: ' . $attachment_id );
						$acf_result = update_field( 'videogame_cover', $attachment_id, $post_id );
						error_log( 'SCF cover update result: ' . var_export( $acf_result, true ) );
						set_post_thumbnail( $post_id, $attachment_id );
					}
				} catch ( Exception $e ) {
					error_log( 'Exception while handling image: ' . $e->getMessage() );
				}
			}

			// Set release date
			if ( ! empty( $game_data['first_release_date'] ) ) {
				try {
					$date       = date( 'Y-m-d', $game_data['first_release_date'] );
					$acf_result = update_field( 'videogame_release_date', $date, $post_id );
					error_log( 'Release date set to ' . $date . '. Result: ' . var_export( $acf_result, true ) );
				} catch ( Exception $e ) {
					error_log( 'Exception while setting release date: ' . $e->getMessage() );
				}
			}

			// Set default status
			try {
				$acf_result = update_field( 'videogame_status', 'backlog', $post_id );
				error_log( 'Status set to backlog. Result: ' . var_export( $acf_result, true ) );
			} catch ( Exception $e ) {
				error_log( 'Exception while setting status: ' . $e->getMessage() );
			}

			// Process companies
			$process_result = self::process_companies($post_id, $game_data);
			if (is_wp_error($process_result)) {
				error_log('Error processing companies: ' . $process_result->get_error_message());
			}

			// Process platforms
			$process_result = self::process_platforms($post_id, $game_data);
			if (is_wp_error($process_result)) {
				error_log('Error processing platforms: ' . $process_result->get_error_message());
			}

			error_log( '=== Finished creating videogame post ===' );
			return $post_id;

		} catch ( Exception $e ) {
			error_log( 'Fatal exception in create_videogame_post: ' . $e->getMessage() );
			return new WP_Error( 'creation_failed', $e->getMessage() );
		}
	}

	/**
	 * Handle AJAX add game request
	 */
	public static function handle_add_game_ajax() {
		error_log( '=== Starting add game AJAX handler ===' );

		try {
			check_ajax_referer( 'mvb_add_game', 'nonce' );

			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error( array( 'message' => __( 'Unauthorized access', 'mvb' ) ) );
			}

			$raw_data = isset( $_POST['game'] ) ? $_POST['game'] : '';
			error_log( 'Raw data type: ' . gettype( $raw_data ) );
			error_log( 'Raw data content: ' . $raw_data );  // Log the actual content

			// Try different JSON decode approaches
			$game_data = json_decode( $raw_data, true );
			if ( $game_data === null ) {
				error_log( 'First JSON decode failed. Error: ' . json_last_error_msg() );

				// Try with stripslashes
				$game_data = json_decode( stripslashes( $raw_data ), true );
				if ( $game_data === null ) {
					error_log( 'Second JSON decode failed. Error: ' . json_last_error_msg() );

					// Try with wp_unslash
					$game_data = json_decode( wp_unslash( $raw_data ), true );
					if ( $game_data === null ) {
						error_log( 'Third JSON decode failed. Error: ' . json_last_error_msg() );
					}
				}
			}

			if ( ! is_array( $game_data ) ) {
				error_log( 'Failed to get valid game data array' );
				wp_send_json_error(
					array(
						'message'     => __( 'Invalid game data', 'mvb' ),
						'raw_type'    => gettype( $raw_data ),
						'raw_content' => substr( $raw_data, 0, 1000 ), // First 1000 chars
						'json_error'  => json_last_error_msg(),
					)
				);
				return;
			}

			error_log( 'Successfully decoded game data: ' . print_r( $game_data, true ) );
			$result = self::create_videogame_post( $game_data );

			if ( is_wp_error( $result ) ) {
				error_log( 'Error creating post: ' . $result->get_error_message() );
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			error_log( 'Successfully created post with ID: ' . $result );
			wp_send_json_success(
				array(
					'message' => __( 'Game added successfully!', 'mvb' ),
					'post_id' => $result,
				)
			);
		} catch ( Exception $e ) {
			error_log( 'Exception in add game handler: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}
}
