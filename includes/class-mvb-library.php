<?php
/**
 * Library CPT and meta registration for MVB.
 *
 * Registers the `mvb_library_entry` custom post type and all associated post
 * meta fields. Also provides helper methods for querying a user's library.
 *
 * @package MVB
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class MVB_Library
 */
class MVB_Library {

	/**
	 * Valid values for the `library_status` meta field.
	 *
	 * @var array<int,string>
	 */
	const VALID_STATUSES = array(
		'backlog',
		'playing',
		'completed',
		'dropped',
		'wishlist',
	);

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_library_entry_cpt' ), 10 );
		add_action( 'init', array( __CLASS__, 'register_library_meta' ), 20 );
	}

	/**
	 * Register the `mvb_library_entry` custom post type.
	 */
	public static function register_library_entry_cpt() {
		$labels = array(
			'name'               => __( 'Library Entries', 'mvb' ),
			'singular_name'      => __( 'Library Entry', 'mvb' ),
			'menu_name'          => __( 'Library', 'mvb' ),
			'all_items'          => __( 'All Library Entries', 'mvb' ),
			'edit_item'          => __( 'Edit Library Entry', 'mvb' ),
			'view_item'          => __( 'View Library Entry', 'mvb' ),
			'add_new_item'       => __( 'Add New Library Entry', 'mvb' ),
			'add_new'            => __( 'Add New Library Entry', 'mvb' ),
			'new_item'           => __( 'New Library Entry', 'mvb' ),
			'search_items'       => __( 'Search Library Entries', 'mvb' ),
			'not_found'          => __( 'No library entries found', 'mvb' ),
			'not_found_in_trash' => __( 'No library entries found in Trash', 'mvb' ),
		);

		$args = array(
			'labels'          => $labels,
			'hierarchical'    => false,
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => false,
			'show_in_rest'    => false,
			'supports'        => array( 'title', 'author', 'custom-fields' ),
			'capability_type' => array( 'mvb_library_entry', 'mvb_library_entries' ),
			'map_meta_cap'    => true,
		);

		register_post_type( 'mvb_library_entry', $args );
	}

	/**
	 * Register post meta fields for `mvb_library_entry`.
	 */
	public static function register_library_meta() {
		$auth = array( __CLASS__, 'meta_auth_callback' );

		// Videogame reference.
		register_post_meta(
			'mvb_library_entry',
			'library_videogame_id',
			array(
				'type'              => 'integer',
				'description'       => __( 'Post ID of the referenced videogame in the catalog.', 'mvb' ),
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $auth,
			)
		);

		// Completion date (Ymd string).
		register_post_meta(
			'mvb_library_entry',
			'library_completion_date',
			array(
				'type'              => 'string',
				'description'       => __( 'Date the game was completed (Ymd format, e.g. 20240315).', 'mvb' ),
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_ymd_date' ),
				'auth_callback'     => $auth,
			)
		);

		// Device reference.
		register_post_meta(
			'mvb_library_entry',
			'library_device_id',
			array(
				'type'              => 'integer',
				'description'       => __( 'Post ID of the device used to play this game.', 'mvb' ),
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $auth,
			)
		);

		// Rating (1-10; 0 = unset).
		register_post_meta(
			'mvb_library_entry',
			'library_rating',
			array(
				'type'              => 'integer',
				'description'       => __( 'User rating for this game, 1 to 10. 0 means unset.', 'mvb' ),
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_rating' ),
				'auth_callback'     => $auth,
			)
		);

		// Status enum.
		register_post_meta(
			'mvb_library_entry',
			'library_status',
			array(
				'type'              => 'string',
				'description'       => __( 'Library status: backlog, playing, completed, dropped, or wishlist.', 'mvb' ),
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_status' ),
				'auth_callback'     => $auth,
			)
		);

		// Notes (allows basic HTML).
		register_post_meta(
			'mvb_library_entry',
			'library_notes',
			array(
				'type'              => 'string',
				'description'       => __( 'Free-form notes about this game entry.', 'mvb' ),
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'wp_kses_post',
				'auth_callback'     => $auth,
			)
		);
	}

	/**
	 * Auth callback for all library entry meta fields.
	 *
	 * Allows editing when the current user can edit the post.
	 *
	 * @param bool   $allowed   Whether the user is allowed.
	 * @param string $meta_key  Meta key being modified.
	 * @param int    $object_id Object ID (post ID).
	 * @return bool
	 */
	public static function meta_auth_callback( $allowed, $meta_key, $object_id ) {
		unset( $allowed, $meta_key );
		return current_user_can( 'edit_post', (int) $object_id );
	}

	/**
	 * Sanitize a date value to Ymd format.
	 *
	 * Returns an empty string if the value cannot be parsed.
	 *
	 * @param string $value Raw date string.
	 * @return string Sanitized Ymd string or empty string.
	 */
	public static function sanitize_ymd_date( $value ) {
		$value = sanitize_text_field( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		// Accept Ymd directly (e.g. 20240315).
		if ( preg_match( '/^[0-9]{8}$/', $value ) ) {
			return $value;
		}

		// Accept Y-m-d and convert.
		if ( preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $value, $m ) ) {
			return $m[1] . $m[2] . $m[3];
		}

		return '';
	}

	/**
	 * Sanitize a rating value (1-10; 0 = unset).
	 *
	 * @param mixed $value Raw rating value.
	 * @return int Clamped integer 0–10.
	 */
	public static function sanitize_rating( $value ) {
		$int = absint( $value );
		if ( $int > 10 ) {
			return 10;
		}
		return $int;
	}

	/**
	 * Sanitize a status value against the allowed enum.
	 *
	 * Returns 'backlog' as a safe default for unrecognised values.
	 *
	 * @param string $value Raw status string.
	 * @return string Valid status string.
	 */
	public static function sanitize_status( $value ) {
		$value = sanitize_text_field( (string) $value );
		if ( in_array( $value, self::VALID_STATUSES, true ) ) {
			return $value;
		}
		return 'backlog';
	}

	/**
	 * Get all library entries for a given user.
	 *
	 * @param int   $user_id WordPress user ID.
	 * @param array $args    Optional WP_Query argument overrides.
	 * @return WP_Post[] Array of WP_Post objects.
	 */
	public static function get_user_library( $user_id, $args = array() ) {
		$user_id = absint( $user_id );
		if ( 0 === $user_id ) {
			return array();
		}

		$defaults = array(
			'post_type'      => 'mvb_library_entry',
			'post_status'    => 'publish',
			'author'         => $user_id,
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		$query_args = wp_parse_args( $args, $defaults );
		// Force author scope — cannot be overridden via $args for security.
		$query_args['author'] = $user_id;

		$query = new WP_Query( $query_args );

		return $query->posts ? $query->posts : array();
	}

	/**
	 * Get the videogame post referenced by a library entry.
	 *
	 * @param int $entry_id Post ID of the `mvb_library_entry`.
	 * @return WP_Post|null The referenced videogame post or null if not found.
	 */
	public static function get_entry_videogame( $entry_id ) {
		$entry_id     = absint( $entry_id );
		$videogame_id = absint( get_post_meta( $entry_id, 'library_videogame_id', true ) );

		if ( 0 === $videogame_id ) {
			return null;
		}

		$post = get_post( $videogame_id );
		if ( ! $post instanceof WP_Post || 'videogame' !== $post->post_type ) {
			return null;
		}

		return $post;
	}

	/**
	 * Check whether a user already has a library entry for a given videogame.
	 *
	 * @param int $user_id      WordPress user ID.
	 * @param int $videogame_id Post ID of the videogame.
	 * @return bool True if an entry exists.
	 */
	public static function user_has_game( $user_id, $videogame_id ) {
		$user_id      = absint( $user_id );
		$videogame_id = absint( $videogame_id );

		if ( 0 === $user_id || 0 === $videogame_id ) {
			return false;
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'mvb_library_entry',
				'post_status'    => 'publish',
				'author'         => $user_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => 'library_videogame_id',
						'value'   => $videogame_id,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		return $query->found_posts > 0;
	}
}
