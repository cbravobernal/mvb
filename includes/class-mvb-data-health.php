<?php
/**
 * Data normalization and health checks.
 *
 * @package MVB
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class MVB_Data_Health
 */
class MVB_Data_Health {

	/**
	 * Data health action nonce.
	 */
	const ACTION_NONCE = 'mvb_data_health_action';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_post_mvb_data_health_repair', array( __CLASS__, 'handle_repair_action' ) );
		add_action( 'save_post_videogame', array( __CLASS__, 'normalize_on_save' ), 30, 3 );
	}

	/**
	 * Render data health page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$report = self::get_health_report();
		$result = self::get_repair_result_from_request();
		?>
		<div class="wrap mvb-data-health">
			<h1><?php esc_html_e( 'Data Health', 'mvb' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Checks data consistency for status, completion dates, and HLTB metadata.', 'mvb' ); ?>
			</p>

			<?php if ( ! empty( $result ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: mode, 2: processed, 3: normalized dates, 4: synced status, 5: removed legacy meta. */
								__( 'Repair "%1$s" completed. Processed: %2$d, Dates normalized: %3$d, Status synced: %4$d, Legacy status removed: %5$d.', 'mvb' ),
								$result['mode'],
								$result['processed'],
								$result['dates'],
								$result['status'],
								$result['legacy']
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<div class="mvb-stats-grid">
				<div class="mvb-stat-card">
					<div class="mvb-stat-value"><?php echo esc_html( $report['total_games'] ); ?></div>
					<div class="mvb-stat-label"><?php esc_html_e( 'Total Games', 'mvb' ); ?></div>
				</div>
				<div class="mvb-stat-card">
					<div class="mvb-stat-value"><?php echo esc_html( $report['missing_status'] ); ?></div>
					<div class="mvb-stat-label"><?php esc_html_e( 'Missing Status', 'mvb' ); ?></div>
				</div>
				<div class="mvb-stat-card">
					<div class="mvb-stat-value"><?php echo esc_html( $report['legacy_status_only'] ); ?></div>
					<div class="mvb-stat-label"><?php esc_html_e( 'Legacy Status Only', 'mvb' ); ?></div>
				</div>
				<div class="mvb-stat-card">
					<div class="mvb-stat-value"><?php echo esc_html( $report['status_mismatch'] ); ?></div>
					<div class="mvb-stat-label"><?php esc_html_e( 'Status Mismatch', 'mvb' ); ?></div>
				</div>
				<div class="mvb-stat-card">
					<div class="mvb-stat-value"><?php echo esc_html( $report['missing_completion_date'] ); ?></div>
					<div class="mvb-stat-label"><?php esc_html_e( 'Missing Completion Date', 'mvb' ); ?></div>
				</div>
				<div class="mvb-stat-card">
					<div class="mvb-stat-value"><?php echo esc_html( $report['invalid_completion_date'] ); ?></div>
					<div class="mvb-stat-label"><?php esc_html_e( 'Invalid Completion Date', 'mvb' ); ?></div>
				</div>
				<div class="mvb-stat-card">
					<div class="mvb-stat-value"><?php echo esc_html( $report['non_canonical_completion_date'] ); ?></div>
					<div class="mvb-stat-label"><?php esc_html_e( 'Non-Canonical Date Format', 'mvb' ); ?></div>
				</div>
				<div class="mvb-stat-card">
					<div class="mvb-stat-value"><?php echo esc_html( $report['missing_hltb'] ); ?></div>
					<div class="mvb-stat-label"><?php esc_html_e( 'Missing HLTB Length', 'mvb' ); ?></div>
				</div>
			</div>

			<div class="mvb-stats-sections">
				<div class="mvb-stats-section">
					<h2><?php esc_html_e( 'Repair Actions', 'mvb' ); ?></h2>
					<p><?php esc_html_e( 'Use these tools to normalize stored values and remove legacy status drift.', 'mvb' ); ?></p>
					<div class="mvb-data-health-actions">
						<?php self::render_repair_form( 'recalculate', __( 'Recalculate All', 'mvb' ) ); ?>
						<?php self::render_repair_form( 'normalize_dates', __( 'Normalize Dates', 'mvb' ) ); ?>
						<?php self::render_repair_form( 'sync_status', __( 'Sync Status', 'mvb' ) ); ?>
					</div>
				</div>

				<div class="mvb-stats-section">
					<h2><?php esc_html_e( 'What Gets Fixed', 'mvb' ); ?></h2>
					<ul class="mvb-recent-list">
						<li><?php esc_html_e( 'Moves legacy `videogame_status` values into taxonomy status.', 'mvb' ); ?></li>
						<li><?php esc_html_e( 'Removes `videogame_status` meta after taxonomy sync.', 'mvb' ); ?></li>
						<li><?php esc_html_e( 'Normalizes completion dates into `Ymd` format.', 'mvb' ); ?></li>
						<li><?php esc_html_e( 'Keeps unparseable date values untouched so you can inspect manually.', 'mvb' ); ?></li>
					</ul>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle repair action submissions.
	 */
	public static function handle_repair_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'mvb' ) );
		}

		check_admin_referer( self::ACTION_NONCE, 'mvb_data_health_nonce' );

		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		if ( ! in_array( $mode, array( 'recalculate', 'normalize_dates', 'sync_status' ), true ) ) {
			$mode = 'recalculate';
		}

		$result = self::run_repair( $mode );
		$url    = add_query_arg(
			array(
				'post_type'         => 'videogame',
				'page'              => 'mvb-data-health',
				'mvb_health_done'   => '1',
				'mvb_health_mode'   => $mode,
				'mvb_health_total'  => $result['processed'],
				'mvb_health_dates'  => $result['dates'],
				'mvb_health_status' => $result['status'],
				'mvb_health_legacy' => $result['legacy'],
			),
			admin_url( 'edit.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Normalize data on post save.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post object.
	 * @param bool    $update Whether this is an existing post update.
	 */
	public static function normalize_on_save( $post_id, $post, $update ) {
		unset( $update );

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		self::normalize_completion_date_for_post( $post_id );
		self::sync_status_for_post( $post_id, true );
	}

	/**
	 * Normalize and save completion date for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True if updated.
	 */
	public static function normalize_completion_date_for_post( $post_id ) {
		$raw_date = get_post_meta( $post_id, 'videogame_completion_date', true );
		if ( '' === $raw_date || null === $raw_date ) {
			return false;
		}

		$normalized = self::normalize_completion_date( $raw_date );
		if ( '' === $normalized || $normalized === $raw_date ) {
			return false;
		}

		update_post_meta( $post_id, 'videogame_completion_date', $normalized );
		return true;
	}

	/**
	 * Normalize completion date to canonical Ymd.
	 *
	 * @param mixed $value Raw date value.
	 * @return string
	 */
	public static function normalize_completion_date( $value ) {
		$timestamp = self::completion_value_to_timestamp( $value );
		if ( $timestamp <= 0 ) {
			return '';
		}

		return wp_date( 'Ymd', $timestamp );
	}

	/**
	 * Convert mixed completion date values to timestamp.
	 *
	 * @param mixed $value Raw date value.
	 * @return int
	 */
	public static function completion_value_to_timestamp( $value ) {
		if ( ! is_scalar( $value ) ) {
			return 0;
		}

		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}

		if ( preg_match( '/^[0-9]{8}$/', $value ) ) {
			$dt = DateTime::createFromFormat( 'Ymd', $value, wp_timezone() );
			return $dt ? (int) $dt->getTimestamp() : 0;
		}

		if ( preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $value ) ) {
			$dt = DateTime::createFromFormat( 'Y-m-d', $value, wp_timezone() );
			return $dt ? (int) $dt->getTimestamp() : 0;
		}

		if ( preg_match( '/^[0-9]{4}\\/[0-9]{2}\\/[0-9]{2}$/', $value ) ) {
			$dt = DateTime::createFromFormat( 'Y/m/d', $value, wp_timezone() );
			return $dt ? (int) $dt->getTimestamp() : 0;
		}

		if ( preg_match( '/^[0-9]{2}\\/[0-9]{2}\\/[0-9]{4}$/', $value ) ) {
			$dt = DateTime::createFromFormat( 'd/m/Y', $value, wp_timezone() );
			return $dt ? (int) $dt->getTimestamp() : 0;
		}

		if ( preg_match( '/^[0-9]{10}$/', $value ) ) {
			return (int) $value;
		}

		$timestamp = strtotime( $value );
		return false === $timestamp ? 0 : (int) $timestamp;
	}

	/**
	 * Get effective status slug for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_status_slug( $post_id ) {
		$tax_status = self::get_taxonomy_status_slug( $post_id );
		if ( '' !== $tax_status ) {
			return $tax_status;
		}

		return self::get_legacy_status_slug( $post_id );
	}

	/**
	 * Set status using taxonomy only.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $status_slug Status slug.
	 * @return bool
	 */
	public static function set_status_taxonomy( $post_id, $status_slug ) {
		$status_slug = sanitize_title( $status_slug );
		if ( '' === $status_slug || ! term_exists( $status_slug, 'mvb_game_status' ) ) {
			return false;
		}

		$result = wp_set_object_terms( $post_id, $status_slug, 'mvb_game_status', false );
		return ! is_wp_error( $result );
	}

	/**
	 * Sync status taxonomy from effective status and optionally remove legacy meta.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $remove_legacy_meta Whether to remove legacy meta key.
	 * @return array<string, bool>
	 */
	public static function sync_status_for_post( $post_id, $remove_legacy_meta = true ) {
		$tax_status    = self::get_taxonomy_status_slug( $post_id );
		$legacy_status = self::get_legacy_status_slug( $post_id );
		$target_status = '' !== $tax_status ? $tax_status : $legacy_status;

		$status_synced = false;
		if ( '' !== $target_status && $tax_status !== $target_status ) {
			$status_synced = self::set_status_taxonomy( $post_id, $target_status );
		}

		$legacy_removed = false;
		$can_remove_legacy = $remove_legacy_meta && ( '' !== $tax_status || ( '' !== $target_status && $status_synced ) );
		if ( $can_remove_legacy ) {
			$legacy_raw = get_post_meta( $post_id, 'videogame_status', true );
			if ( '' !== $legacy_raw && null !== $legacy_raw ) {
				delete_post_meta( $post_id, 'videogame_status' );
				$legacy_removed = true;
			}
		}

		return array(
			'status_synced'  => $status_synced,
			'legacy_removed' => $legacy_removed,
		);
	}

	/**
	 * Get taxonomy status slug for post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function get_taxonomy_status_slug( $post_id ) {
		$terms = wp_get_post_terms( $post_id, 'mvb_game_status', array( 'fields' => 'slugs' ) );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '';
		}

		$priority = array( 'playing', 'finished', 'backlog', 'wishlist' );
		foreach ( $priority as $slug ) {
			if ( in_array( $slug, $terms, true ) ) {
				return $slug;
			}
		}

		return sanitize_title( (string) $terms[0] );
	}

	/**
	 * Get legacy status slug from post meta.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function get_legacy_status_slug( $post_id ) {
		$meta_value = get_post_meta( $post_id, 'videogame_status', true );
		if ( ! is_scalar( $meta_value ) ) {
			return '';
		}

		$meta_value = trim( (string) $meta_value );
		if ( '' === $meta_value ) {
			return '';
		}

		if ( ctype_digit( $meta_value ) ) {
			$term = get_term_by( 'id', (int) $meta_value, 'mvb_game_status' );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term->slug;
			}
			return '';
		}

		$slug = sanitize_title( $meta_value );
		if ( term_exists( $slug, 'mvb_game_status' ) ) {
			return $slug;
		}

		return '';
	}

	/**
	 * Get current health report values.
	 *
	 * @return array<string, int>
	 */
	private static function get_health_report() {
		$post_ids = get_posts(
			array(
				'post_type'      => 'videogame',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$report = array(
			'total_games'                   => 0,
			'missing_status'                => 0,
			'legacy_status_only'            => 0,
			'status_mismatch'               => 0,
			'missing_completion_date'       => 0,
			'invalid_completion_date'       => 0,
			'non_canonical_completion_date' => 0,
			'missing_hltb'                  => 0,
		);

		foreach ( $post_ids as $post_id ) {
			++$report['total_games'];

			$tax_status    = self::get_taxonomy_status_slug( $post_id );
			$legacy_status = self::get_legacy_status_slug( $post_id );
			$effective     = '' !== $tax_status ? $tax_status : $legacy_status;

			if ( '' === $tax_status && '' === $legacy_status ) {
				++$report['missing_status'];
			} elseif ( '' === $tax_status && '' !== $legacy_status ) {
				++$report['legacy_status_only'];
			} elseif ( '' !== $tax_status && '' !== $legacy_status && $tax_status !== $legacy_status ) {
				++$report['status_mismatch'];
			}

			$raw_date = get_post_meta( $post_id, 'videogame_completion_date', true );
			if ( ( '' === $raw_date || null === $raw_date ) && 'finished' === $effective ) {
				++$report['missing_completion_date'];
			} elseif ( '' !== $raw_date && null !== $raw_date ) {
				$normalized = self::normalize_completion_date( $raw_date );
				if ( '' === $normalized ) {
					++$report['invalid_completion_date'];
				} elseif ( (string) $raw_date !== $normalized ) {
					++$report['non_canonical_completion_date'];
				}
			}

			$hltb_value = get_post_meta( $post_id, 'hltb_main_story', true );
			if ( ! is_numeric( $hltb_value ) || (float) $hltb_value <= 0 ) {
				++$report['missing_hltb'];
			}
		}

		return $report;
	}

	/**
	 * Render a single repair form.
	 *
	 * @param string $mode Repair mode.
	 * @param string $label Button label.
	 */
	private static function render_repair_form( $mode, $label ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mvb_data_health_repair" />
			<input type="hidden" name="mode" value="<?php echo esc_attr( $mode ); ?>" />
			<?php wp_nonce_field( self::ACTION_NONCE, 'mvb_data_health_nonce' ); ?>
			<button type="submit" class="button button-secondary"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Execute repair mode over all videogames.
	 *
	 * @param string $mode Repair mode.
	 * @return array<string, int>
	 */
	private static function run_repair( $mode ) {
		$post_ids = get_posts(
			array(
				'post_type'      => 'videogame',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$results = array(
			'processed' => 0,
			'dates'     => 0,
			'status'    => 0,
			'legacy'    => 0,
		);

		$run_dates  = in_array( $mode, array( 'recalculate', 'normalize_dates' ), true );
		$run_status = in_array( $mode, array( 'recalculate', 'sync_status' ), true );

		foreach ( $post_ids as $post_id ) {
			++$results['processed'];

			if ( $run_dates && self::normalize_completion_date_for_post( $post_id ) ) {
				++$results['dates'];
			}

			if ( $run_status ) {
				$sync = self::sync_status_for_post( $post_id, true );
				if ( $sync['status_synced'] ) {
					++$results['status'];
				}
				if ( $sync['legacy_removed'] ) {
					++$results['legacy'];
				}
			}
		}

		return $results;
	}

	/**
	 * Read repair result summary from URL query.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_repair_result_from_request() {
		if ( ! isset( $_GET['mvb_health_done'] ) ) {
			return array();
		}

		return array(
			'mode'      => isset( $_GET['mvb_health_mode'] ) ? sanitize_key( wp_unslash( $_GET['mvb_health_mode'] ) ) : 'recalculate',
			'processed' => isset( $_GET['mvb_health_total'] ) ? absint( $_GET['mvb_health_total'] ) : 0,
			'dates'     => isset( $_GET['mvb_health_dates'] ) ? absint( $_GET['mvb_health_dates'] ) : 0,
			'status'    => isset( $_GET['mvb_health_status'] ) ? absint( $_GET['mvb_health_status'] ) : 0,
			'legacy'    => isset( $_GET['mvb_health_legacy'] ) ? absint( $_GET['mvb_health_legacy'] ) : 0,
		);
	}
}
