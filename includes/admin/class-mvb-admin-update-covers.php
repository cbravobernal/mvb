<?php
/**
 * Update Covers admin page for MVB
 *
 * @package MVB
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * MVB_Admin_Update_Covers class
 */
class MVB_Admin_Update_Covers {

	/**
	 * Render update covers page
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$client_id     = get_option( 'mvb_igdb_client_id' );
		$client_secret = get_option( 'mvb_igdb_client_secret' );

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Update Game Covers', 'mvb' ); ?></h1>
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
			</div>
			<?php
			return;
		}

		// Handle form submission
		if ( isset( $_POST['mvb_update_covers'] ) && check_admin_referer( 'mvb_update_covers' ) ) {
			$results = MVB_IGDB_API::update_all_game_covers();
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Update Game Covers', 'mvb' ); ?></h1>
				<div class="notice notice-info">
					<p>
						<?php
						printf(
							/* translators: 1: total games, 2: processed games, 3: updated games */
							esc_html__( 'Processed %1$d games, updated %2$d covers successfully.', 'mvb' ),
							$results['processed'],
							$results['updated']
						);
						?>
					</p>
					<?php if ( ! empty( $results['errors'] ) ) : ?>
						<div class="mvb-errors">
							<h3><?php esc_html_e( 'Errors:', 'mvb' ); ?></h3>
							<ul>
								<?php foreach ( $results['errors'] as $error ) : ?>
									<li><?php echo esc_html( $error ); ?></li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>
				</div>
			</div>
			<?php
			return;
		}

		// Display the form
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Update Game Covers', 'mvb' ); ?></h1>
			<p><?php esc_html_e( 'This will update all game covers using the IGDB API. This process may take some time depending on the number of games.', 'mvb' ); ?></p>
			<form method="post" action="">
				<?php wp_nonce_field( 'mvb_update_covers' ); ?>
				<p>
					<input type="submit"
						name="mvb_update_covers"
						class="button button-primary"
						value="<?php esc_attr_e( 'Update All Covers', 'mvb' ); ?>"
					>
				</p>
			</form>
		</div>
		<?php
	}
}
