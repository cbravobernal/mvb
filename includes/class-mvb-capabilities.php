<?php
/**
 * Capability management for the videogame post type.
 *
 * Switches the SCF-registered `videogame` CPT to custom capability types
 * (`mvb_game` / `mvb_games`) via the `register_post_type_args` filter so
 * the existing `mvb_gamer` role caps stop being dead code, then keeps
 * the administrator and gamer roles in sync with the expected cap set.
 *
 * @package MVB
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class MVB_Capabilities
 */
class MVB_Capabilities {

	/**
	 * Option name that tracks the role cap version currently applied.
	 */
	const ROLE_VERSION_OPTION = 'mvb_roles_version';

	/**
	 * Bumping this forces sync_roles to re-apply the cap map.
	 */
	const ROLE_VERSION = '3';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_filter( 'register_post_type_args', array( __CLASS__, 'filter_post_type_args' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'sync_roles' ) );
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_library_entry_caps' ), 10, 4 );
	}

	/**
	 * Swap the CPT to custom capability types.
	 *
	 * @param array  $args      Post type args.
	 * @param string $post_type Post type slug.
	 * @return array
	 */
	public static function filter_post_type_args( $args, $post_type ) {
		if ( 'videogame' !== $post_type ) {
			return $args;
		}

		$args['capability_type'] = array( 'mvb_game', 'mvb_games' );
		$args['map_meta_cap']    = true;

		return $args;
	}

	/**
	 * All primitive caps admin should have.
	 *
	 * @return array<int,string>
	 */
	public static function admin_caps() {
		return array(
			// Videogame catalog caps.
			'edit_mvb_game',
			'read_mvb_game',
			'delete_mvb_game',
			'edit_mvb_games',
			'edit_others_mvb_games',
			'publish_mvb_games',
			'read_private_mvb_games',
			'delete_mvb_games',
			'delete_private_mvb_games',
			'delete_published_mvb_games',
			'delete_others_mvb_games',
			'edit_private_mvb_games',
			'edit_published_mvb_games',
			'create_mvb_games',
			'mvb_manage_igdb_settings',
			// Library entry caps (full — admin can edit all users' entries).
			'edit_mvb_library_entry',
			'read_mvb_library_entry',
			'delete_mvb_library_entry',
			'edit_mvb_library_entries',
			'edit_others_mvb_library_entries',
			'publish_mvb_library_entries',
			'read_private_mvb_library_entries',
			'delete_mvb_library_entries',
			'delete_private_mvb_library_entries',
			'delete_published_mvb_library_entries',
			'delete_others_mvb_library_entries',
			'edit_private_mvb_library_entries',
			'edit_published_mvb_library_entries',
			'edit_own_mvb_library_entries',
			'delete_own_mvb_library_entries',
			'create_mvb_library_entries',
		);
	}

	/**
	 * Gamer role cap set (author-level scoped to videogames).
	 *
	 * @return array<int,string>
	 */
	public static function gamer_caps() {
		return array(
			'read',
			'upload_files',
			'edit_mvb_game',
			'read_mvb_game',
			'delete_mvb_game',
			'edit_mvb_games',
			'publish_mvb_games',
			'edit_published_mvb_games',
			'delete_mvb_games',
			'delete_published_mvb_games',
			'mvb_manage_igdb_settings',
		);
	}

	/**
	 * MVB Player role cap set — library entries and devices only, no catalog access.
	 *
	 * @return array<int,string>
	 */
	public static function player_caps() {
		return array(
			'read',
			// Library entries.
			'edit_mvb_library_entries',
			'edit_own_mvb_library_entries',
			'edit_published_mvb_library_entries',
			'publish_mvb_library_entries',
			'delete_mvb_library_entries',
			'delete_own_mvb_library_entries',
			'delete_published_mvb_library_entries',
			// Devices.
			'edit_mvb_devices',
			'edit_own_mvb_devices',
			'edit_published_mvb_devices',
			'publish_mvb_devices',
			'delete_mvb_devices',
			'delete_own_mvb_devices',
			'delete_published_mvb_devices',
		);
	}

	/**
	 * Register the `mvb_player` role.
	 *
	 * Called during plugin activation. Uses add_role so it only runs once;
	 * subsequent calls are handled by sync_roles which calls add_cap.
	 */
	public static function register_mvb_player_role() {
		if ( null !== get_role( 'mvb_player' ) ) {
			return;
		}

		add_role(
			'mvb_player',
			__( 'MVB Player', 'mvb' ),
			array_fill_keys( self::player_caps(), true )
		);
	}

	/**
	 * Ensure roles have the right caps. Runs once per ROLE_VERSION bump.
	 */
	public static function sync_roles() {
		$applied = get_option( self::ROLE_VERSION_OPTION );
		if ( self::ROLE_VERSION === $applied ) {
			return;
		}

		$admin = get_role( 'administrator' );
		if ( $admin instanceof WP_Role ) {
			foreach ( self::admin_caps() as $cap ) {
				$admin->add_cap( $cap );
			}
		}

		$gamer = get_role( 'mvb_gamer' );
		if ( null === $gamer ) {
			$gamer = add_role(
				'mvb_gamer',
				__( 'Gamer', 'mvb' ),
				array_fill_keys( self::gamer_caps(), true )
			);
		}

		if ( $gamer instanceof WP_Role ) {
			foreach ( self::gamer_caps() as $cap ) {
				$gamer->add_cap( $cap );
			}
		}

		// Ensure mvb_player role exists and has the right caps.
		self::register_mvb_player_role();

		$player = get_role( 'mvb_player' );
		if ( $player instanceof WP_Role ) {
			foreach ( self::player_caps() as $cap ) {
				$player->add_cap( $cap );
			}
		}

		update_option( self::ROLE_VERSION_OPTION, self::ROLE_VERSION );
	}

	/**
	 * Map meta caps for `mvb_library_entry` to enforce own-vs-others rules.
	 *
	 * When WordPress resolves `edit_post` or `delete_post` for a library entry,
	 * this filter maps the meta cap to the appropriate primitive depending on
	 * whether the current user is the post author.
	 *
	 * @param array  $caps    Primitive capabilities required.
	 * @param string $cap     Meta capability being checked.
	 * @param int    $user_id ID of the user performing the check.
	 * @param array  $args    Additional context; $args[0] is the post ID.
	 * @return array Modified primitives array.
	 */
	public static function map_library_entry_caps( $caps, $cap, $user_id, $args ) {
		$object_caps = array( 'edit_post', 'delete_post', 'read_post' );
		if ( ! in_array( $cap, $object_caps, true ) || empty( $args[0] ) ) {
			return $caps;
		}

		$post = get_post( $args[0] );
		if ( ! $post instanceof WP_Post || 'mvb_library_entry' !== $post->post_type ) {
			return $caps;
		}

		$is_owner = ( (int) $post->post_author === (int) $user_id );

		if ( 'edit_post' === $cap ) {
			return $is_owner
				? array( 'edit_own_mvb_library_entries' )
				: array( 'edit_others_mvb_library_entries' );
		}

		if ( 'delete_post' === $cap ) {
			return $is_owner
				? array( 'delete_own_mvb_library_entries' )
				: array( 'delete_others_mvb_library_entries' );
		}

		// read_post — players can read their own entries.
		return $is_owner
			? array( 'read' )
			: array( 'read_private_mvb_library_entries' );
	}

	/**
	 * Deactivation cleanup — drop the custom role so it doesn't linger.
	 */
	public static function remove_gamer_role() {
		if ( get_role( 'mvb_gamer' ) ) {
			remove_role( 'mvb_gamer' );
		}
	}
}
