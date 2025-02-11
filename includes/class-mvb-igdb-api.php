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
	 * Search games
	 *
	 * @param string $search Search term.
	 * @param int    $limit Result limit.
	 * @return array|WP_Error Search results or WP_Error on failure
	 */
	public static function search_games( $search, $limit = 10 ) {
		$query = 'search "' . esc_sql( $search ) . '";
				 fields name, cover.url, first_release_date, 
				 platforms.*, platforms.slug, platforms.name,
				 summary;
				 limit ' . absint( $limit ) . ';';

		return self::request( 'games', $query );
	}

	/**
	 * Get game by ID
	 *
	 * @param int $game_id Game ID.
	 * @return array|WP_Error Game data or WP_Error on failure
	 */
	public static function get_game( $game_id ) {
		$query = 'fields name, cover.url, first_release_date, summary, 
				 genres.name, platforms.name, rating, rating_count;
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
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_scripts' ) );
	}

	/**
	 * Enqueue admin scripts and styles
	 *
	 * @param string $hook The current admin page.
	 */
	public static function enqueue_admin_scripts( $hook ) {
		if ( 'settings_page_mvb-settings' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'mvb-admin',
			MVB_PLUGIN_URL . 'assets/css/mvb.css',
			array(),
			MVB_VERSION
		);

		wp_enqueue_script(
			'mvb-search',
			MVB_PLUGIN_URL . 'assets/js/search.js',
			array( 'jquery' ),
			MVB_VERSION,
			true
		);

		wp_localize_script(
			'mvb-search',
			'mvbSearch',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'mvb_search' ),
			)
		);
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
	 * Create videogame post from IGDB data
	 *
	 * @param array $game_data Game data from IGDB.
	 * @return int|WP_Error Post ID on success, WP_Error on failure
	 */
	public static function create_videogame_post( $game_data ) {
		// Create post with content
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

		// Handle cover image
		if ( ! empty( $game_data['cover']['url'] ) ) {
			$cover_url = str_replace( 't_thumb', 't_cover_big', $game_data['cover']['url'] );

			// Download and attach the image
			$attachment_id = self::attach_remote_image( $cover_url, $post_id );

			if ( ! is_wp_error( $attachment_id ) ) {
				// Update ACF field with attachment ID
				update_field( 'videogame_cover', $attachment_id, $post_id );

				// Optionally set as featured image
				set_post_thumbnail( $post_id, $attachment_id );
			}
		}

		// Set release date
		if ( ! empty( $game_data['first_release_date'] ) ) {
			update_field( 'videogame_release_date', date( 'Y-m-d', $game_data['first_release_date'] ), $post_id );
		}

		// Set default status
		update_field( 'videogame_status', 'backlog', $post_id );

		// Map IGDB platforms to our platform slugs
		$platform_mapping = array(
			'pc-windows'   => 'win',
			'android'      => 'android',
			'mac'          => 'mac',
			'xbox360'      => 'xbox360',
			'linux'        => 'linux',
			'ps4'          => 'ps4',
			'ps5'          => 'ps5',
			'ps3'          => 'ps3',
			'ps2'          => 'ps2',
			'ps1'          => 'ps',
			'xboxone'      => 'xone',
			'ps-vita'      => 'psvita',
			'switch'       => 'switch',
			'wii'          => 'wii',
			'wii-u'        => 'wiiu',
			'nintendo-3ds' => 'n3ds',
			'nintendo-ds'  => 'nds',
			'gba'          => 'gba',
			'gb'           => 'gb',
		);

		// Set platforms
		if ( ! empty( $game_data['platforms'] ) ) {
			$platforms = array();
			foreach ( $game_data['platforms'] as $platform ) {
				if ( isset( $platform['slug'] ) && isset( $platform_mapping[ $platform['slug'] ] ) ) {
					$platforms[] = $platform_mapping[ $platform['slug'] ];
				}
			}

			if ( ! empty( $platforms ) ) {
				// Update the field with an array of values
				update_field( 'release_platform', $platforms, $post_id );

				// For debugging
				error_log( 'Platforms found: ' . print_r( $platforms, true ) );
				error_log( 'Original IGDB platforms: ' . print_r( $game_data['platforms'], true ) );
			}
		}

		return $post_id;
	}

	/**
	 * Handle AJAX add game request
	 */
	public static function handle_add_game_ajax() {
		check_ajax_referer( 'mvb_search', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized access', 'mvb' ) ) );
		}

		$game_data = isset( $_POST['game'] ) ? json_decode( stripslashes( $_POST['game'] ), true ) : null;

		// Debug incoming data
		error_log( 'Incoming game data: ' . print_r( $game_data, true ) );

		if ( empty( $game_data ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid game data', 'mvb' ) ) );
		}

		$result = self::create_videogame_post( $game_data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Debug the final post
		$platforms = get_field( 'release_platform', $result );
		error_log( 'Final platforms saved: ' . print_r( $platforms, true ) );

		wp_send_json_success(
			array(
				'message' => __( 'Game added successfully!', 'mvb' ),
				'post_id' => $result,
			)
		);
	}
}
