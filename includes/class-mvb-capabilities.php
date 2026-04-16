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
	const ROLE_VERSION = '2';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_filter( 'register_post_type_args', array( __CLASS__, 'filter_post_type_args' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'sync_roles' ) );
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

		update_option( self::ROLE_VERSION_OPTION, self::ROLE_VERSION );
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
