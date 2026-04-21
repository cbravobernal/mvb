<?php
/**
 * Add Game admin page for MVB
 *
 * @package MVB
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * MVB_Admin_Add_Game class
 */
class MVB_Admin_Add_Game {

	/**
	 * Render add game page
	 */
	public static function render_page() {
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
							'<a href="' . esc_url( admin_url( 'edit.php?post_type=videogame&page=mvb-igdb-settings' ) ) . '">' .
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
}
