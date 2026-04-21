<?php
/**
 * Database migration handler for MVB.
 *
 * Runs versioned, idempotent migrations when the plugin loads and the stored
 * schema version is below the current target.
 *
 * @package MVB
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class MVB_Migration
 */
class MVB_Migration {

	/**
	 * Option key that stores the current schema version.
	 */
	const SCHEMA_VERSION_OPTION = 'mvb_schema_version';

	/**
	 * Target schema version for this release.
	 */
	const CURRENT_VERSION = 2;

	/**
	 * Transient / option key to store migration results for admin notices.
	 */
	const NOTICE_OPTION = 'mvb_migration_notice';

	/**
	 * Register hooks.
	 */
	public static function init() {
		// Run migrations only in admin context with an admin user. This avoids
		// running wp_insert_post during unauthenticated frontend requests where
		// capability checks fail and would mark games as migrated without
		// creating their library entries.
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate' ), 20 );
		add_action( 'admin_notices', array( __CLASS__, 'render_migration_notice' ) );
		add_action( 'wp_ajax_mvb_dismiss_migration_notice', array( __CLASS__, 'dismiss_migration_notice' ) );
	}

	/**
	 * Run pending migrations if the stored schema version is below the target.
	 *
	 * Gated on `manage_options` so orphan/reassignment writes always run as an
	 * admin. Non-admin admin-area requests (e.g. players viewing My Library)
	 * never trigger the migration.
	 */
	public static function maybe_migrate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$stored = (int) get_option( self::SCHEMA_VERSION_OPTION, 0 );

		if ( $stored >= self::CURRENT_VERSION ) {
			return;
		}

		if ( $stored < 2 ) {
			self::migrate_to_v2();
		}

		update_option( self::SCHEMA_VERSION_OPTION, self::CURRENT_VERSION );
	}

	/**
	 * Migration to schema version 2.
	 *
	 * For every `videogame` post not already migrated:
	 * - If post_author is non-admin: create a `mvb_library_entry` for that author,
	 *   copying completion_date and device meta, then reassign post_author to admin.
	 * - If post_author is 0 (orphan): reassign to admin, skip entry creation.
	 * - Mark the videogame as migrated via `_mvb_migrated_v2` meta.
	 */
	public static function migrate_to_v2() {
		$admin_id = self::get_admin_user_id();

		$videogames = get_posts(
			array(
				'post_type'      => 'videogame',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'     => '_mvb_migrated_v2',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$entries_created = 0;
		$reassigned      = 0;
		$errors          = array();

		foreach ( $videogames as $game ) {
			$original_author = (int) $game->post_author;

			// Reassign orphan (post_author == 0) to admin; no library entry.
			// Mark as migrated ONLY after the reassignment succeeds so a failure
			// can be retried on the next admin page load without losing data.
			if ( 0 === $original_author ) {
				$result = wp_update_post(
					array(
						'ID'          => $game->ID,
						'post_author' => $admin_id,
					),
					true
				);
				if ( is_wp_error( $result ) ) {
					$errors[] = sprintf(
						/* translators: 1: post ID, 2: error message */
						__( 'Could not reassign orphan videogame #%1$d: %2$s', 'mvb' ),
						$game->ID,
						$result->get_error_message()
					);
				} else {
					update_post_meta( $game->ID, '_mvb_migrated_v2', 1 );
					++$reassigned;
				}
				continue;
			}

			// If post_author is already admin, no library entry needed —
			// just mark as migrated so we skip on future runs.
			if ( $original_author === $admin_id ) {
				update_post_meta( $game->ID, '_mvb_migrated_v2', 1 );
				continue;
			}

			// Create library entry for the original author.
			$completion_date = get_post_meta( $game->ID, 'videogame_completion_date', true );

			// Device link: real meta is `videogame_devices` (array of mvb_device IDs).
			// Falls back to singular `videogame_device` for forward compatibility
			// if a future schema ever stores a single device.
			$device_id    = 0;
			$devices_meta = get_post_meta( $game->ID, 'videogame_devices', true );
			if ( is_array( $devices_meta ) && ! empty( $devices_meta ) ) {
				$device_id = absint( reset( $devices_meta ) );
			} elseif ( '' !== $devices_meta && is_numeric( $devices_meta ) ) {
				$device_id = absint( $devices_meta );
			} else {
				$device_id = absint( get_post_meta( $game->ID, 'videogame_device', true ) );
			}

			$status = ( '' !== $completion_date ) ? 'completed' : 'backlog';

			$entry_id = wp_insert_post(
				array(
					'post_type'   => 'mvb_library_entry',
					'post_status' => 'publish',
					'post_author' => $original_author,
					'post_title'  => $game->post_title,
				),
				true
			);

			if ( is_wp_error( $entry_id ) ) {
				$errors[] = sprintf(
					/* translators: 1: post title, 2: error message */
					__( 'Could not create library entry for "%1$s": %2$s', 'mvb' ),
					$game->post_title,
					$entry_id->get_error_message()
				);
				// Do NOT mark as migrated and do NOT reassign — leave the game
				// intact so the next admin page load retries the migration.
				continue;
			}

			update_post_meta( $entry_id, 'library_videogame_id', $game->ID );
			update_post_meta( $entry_id, 'library_status', $status );

			if ( '' !== $completion_date ) {
				// Normalise to Ymd.
				$normalised = MVB_Library::sanitize_ymd_date( $completion_date );
				if ( '' !== $normalised ) {
					update_post_meta( $entry_id, 'library_completion_date', $normalised );
				}
			}

			if ( $device_id > 0 ) {
				update_post_meta( $entry_id, 'library_device_id', $device_id );
			}

			++$entries_created;

			// Reassign videogame to admin now that the library entry is safe.
			$result = wp_update_post(
				array(
					'ID'          => $game->ID,
					'post_author' => $admin_id,
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				$errors[] = sprintf(
					/* translators: 1: post title, 2: error message */
					__( 'Could not reassign videogame "%1$s" to admin: %2$s', 'mvb' ),
					$game->post_title,
					$result->get_error_message()
				);
				// Library entry exists; leave the videogame owned by the player.
				// Admin can re-run later or reassign manually.
				continue;
			}

			++$reassigned;

			// All work complete for this game — mark as migrated so future runs skip it.
			update_post_meta( $game->ID, '_mvb_migrated_v2', 1 );
		}

		// Store the notice data so it can be shown to admins on next page load.
		$notice = array(
			'entries_created' => $entries_created,
			'reassigned'      => $reassigned,
			'errors'          => $errors,
		);
		update_option( self::NOTICE_OPTION, $notice );
	}

	/**
	 * Render the post-migration admin notice (once).
	 */
	public static function render_migration_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice = get_option( self::NOTICE_OPTION );
		if ( ! is_array( $notice ) || ! isset( $notice['entries_created'] ) ) {
			return;
		}

		$type = empty( $notice['errors'] ) ? 'notice-success' : 'notice-warning';
		?>
		<div class="notice <?php echo esc_attr( $type ); ?> is-dismissible" id="mvb-migration-notice">
			<p>
				<strong><?php esc_html_e( 'MVB Library Migration (v2)', 'mvb' ); ?></strong>
			</p>
			<p>
				<?php
				printf(
					/* translators: 1: number of library entries created, 2: number of videogames reassigned */
					esc_html__( 'Migration complete. Library entries created: %1$d. Videogames reassigned to admin: %2$d.', 'mvb' ),
					(int) $notice['entries_created'],
					(int) $notice['reassigned']
				);
				?>
			</p>
			<?php if ( ! empty( $notice['errors'] ) ) : ?>
				<p><strong><?php esc_html_e( 'Errors encountered:', 'mvb' ); ?></strong></p>
				<ul>
					<?php foreach ( $notice['errors'] as $error ) : ?>
						<li><?php echo esc_html( $error ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<script>
		(function() {
			var notice = document.getElementById('mvb-migration-notice');
			if (notice) {
				notice.addEventListener('click', function(e) {
					if (e.target.classList.contains('notice-dismiss')) {
						var xhr = new XMLHttpRequest();
						xhr.open('POST', ajaxurl);
						xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
						xhr.send('action=mvb_dismiss_migration_notice&nonce=' + encodeURIComponent('<?php echo esc_js( wp_create_nonce( 'mvb_dismiss_migration' ) ); ?>'));
					}
				});
			}
		}());
		</script>
		<?php
	}

	/**
	 * AJAX handler to dismiss the migration notice.
	 */
	public static function dismiss_migration_notice() {
		check_ajax_referer( 'mvb_dismiss_migration', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die();
		}

		delete_option( self::NOTICE_OPTION );
		wp_die();
	}

	/**
	 * Get the ID of the primary admin user.
	 *
	 * Returns the first user with `manage_options`. Falls back to user ID 1.
	 *
	 * @return int Admin user ID.
	 */
	public static function get_admin_user_id() {
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);

		if ( ! empty( $admins ) ) {
			return (int) reset( $admins );
		}

		return 1;
	}
}
