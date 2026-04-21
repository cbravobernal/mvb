<?php
/**
 * User registration flow tweaks for MVB.
 *
 * Activates native WordPress registration, sets the default role to
 * `mvb_player`, and redirects players to their My Library page after login.
 *
 * @package MVB
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class MVB_Registration
 */
class MVB_Registration {

	/**
	 * Register hooks.
	 */
	public static function init() {
		// Opt-in: the admin can disable MVB's registration override by returning
		// false from the `mvb_enable_registration` filter. Default: enabled.
		if ( apply_filters( 'mvb_enable_registration', true ) ) {
			add_filter( 'option_users_can_register', array( __CLASS__, 'enable_registration' ) );
			add_filter( 'pre_option_default_role', array( __CLASS__, 'set_default_role' ) );
		}

		// Redirect players to My Library after login.
		add_filter( 'login_redirect', array( __CLASS__, 'handle_login_redirect' ), 10, 3 );

		// Add registration link on MVB admin pages for admin reference.
		add_action( 'admin_footer', array( __CLASS__, 'render_registration_link' ) );
	}

	/**
	 * Filter `option_users_can_register` to enable native WP registration.
	 *
	 * This does not write to the database; it only overrides the option value
	 * in memory so that the registration link and form are available.
	 *
	 * Administrators can disable this behavior by returning `false` from the
	 * `mvb_enable_registration` filter (evaluated once at plugin init). Useful
	 * when the parent site has intentionally disabled registration elsewhere.
	 *
	 * @return int Always returns 1.
	 */
	public static function enable_registration() {
		return 1;
	}

	/**
	 * Filter `pre_option_default_role` to set `mvb_player` as the default role
	 * for new user registrations.
	 *
	 * @return string Role slug.
	 */
	public static function set_default_role() {
		return 'mvb_player';
	}

	/**
	 * Redirect `mvb_player` users to the My Library admin page after login.
	 *
	 * Administrators are not redirected (they go to the default dashboard).
	 *
	 * @param string           $redirect_to           The destination URL.
	 * @param string           $requested_redirect_to The originally requested redirect URL.
	 * @param WP_User|WP_Error $user                  The logged-in user or WP_Error on failure.
	 * @return string The (possibly modified) redirect URL.
	 */
	public static function handle_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		if ( ! $user instanceof WP_User ) {
			return $redirect_to;
		}

		// Admins go to the default dashboard.
		if ( $user->has_cap( 'manage_options' ) ) {
			return $redirect_to;
		}

		// Players with the mvb_player role go to My Library.
		if ( in_array( 'mvb_player', (array) $user->roles, true ) ) {
			return admin_url( 'admin.php?page=mvb-my-library' );
		}

		return $redirect_to;
	}

	/**
	 * Render an informational registration link in the admin footer on MVB pages.
	 *
	 * Only visible to users who can manage options (admins). Provides a quick
	 * link to the WP registration URL for sharing with players.
	 */
	public static function render_registration_link() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// Only show on MVB admin pages.
		$mvb_pages = array(
			'toplevel_page_mvb-my-library',
			'videogame_page_mvb-add-game',
			'videogame_page_mvb-stats',
			'videogame_page_mvb-recommendations',
			'videogame_page_mvb-data-health',
		);

		if ( ! in_array( $screen->id, $mvb_pages, true ) ) {
			return;
		}

		$register_url = wp_registration_url();
		?>
		<div class="mvb-registration-link" style="padding: 10px 20px; color: #666; font-size: 12px;">
			<?php
			printf(
				/* translators: %s: WordPress registration page URL */
				esc_html__( 'Share player registration link: %s', 'mvb' ),
				'<a href="' . esc_url( $register_url ) . '" target="_blank">' . esc_html( $register_url ) . '</a>'
			);
			?>
		</div>
		<?php
	}
}
