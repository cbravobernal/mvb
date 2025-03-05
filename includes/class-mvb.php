<?php
/**
 * Main MVB class
 *
 * @package MVB
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class MVB
 */
class MVB {

	/**
	 * Initialize the plugin
	 */
	public static function init() {
		// Initialize admin.
		MVB_Admin::init();
		// Initialize IGDB API.
		MVB_IGDB_API::init();
		// Initialize taxonomies.
		MVB_Taxonomies::init();

		// Load text domain.
		add_action( 'plugins_loaded', array( __CLASS__, 'load_textdomain' ) );

		// Add quick edit functionality.
		add_action( 'quick_edit_custom_box', array( __CLASS__, 'add_quick_edit_field' ), 10, 2 );
		add_action( 'save_post', array( __CLASS__, 'save_quick_edit_field' ), 10, 2 );
		add_action( 'admin_footer', array( __CLASS__, 'quick_edit_javascript' ) );

		// Add column for status.
		add_filter( 'manage_videogame_posts_columns', array( __CLASS__, 'add_videogame_status_column' ) );
		add_action( 'manage_videogame_posts_custom_column', array( __CLASS__, 'display_videogame_status_column' ), 10, 2 );

		// Add sorting functionality.
		add_filter( 'manage_edit-videogame_sortable_columns', array( __CLASS__, 'make_columns_sortable' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'handle_custom_sorting' ) );

		// Add admin notices.
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );

		// Check for updates.
		add_action( 'admin_init', array( __CLASS__, 'check_for_updates' ) );
	}

	/**
	 * Load plugin text domain
	 */
	public static function load_textdomain() {
		load_plugin_textdomain(
			'mvb',
			false,
			dirname( plugin_basename( plugin_dir_path( __DIR__ ) . 'mvb.php' ) ) . '/languages/'
		);
	}

	/**
	 * Activate the plugin
	 */
	public static function activate() {
		// Register taxonomies first so they exist when we add terms.
		MVB_Taxonomies::register_game_status_taxonomy();

		// Create default statuses.
		self::create_default_game_statuses();

		// Flush rewrite rules.
		flush_rewrite_rules();
	}

	/**
	 * Deactivate the plugin
	 */
	public static function deactivate() {
		// Cleanup tasks if needed.
	}

	/**
	 * Make columns sortable
	 *
	 * @param array $columns Array of sortable columns.
	 * @return array Modified array of sortable columns
	 */
	public static function make_columns_sortable( $columns ) {
		$columns['videogame_status']          = 'videogame_status';
		$columns['videogame_completion_date'] = 'videogame_completion_date';
		return $columns;
	}

	/**
	 * Handle custom sorting
	 *
	 * @param WP_Query $query The query object.
	 */
	public static function handle_custom_sorting( $query ) {
		if ( ! is_admin() ) {
			return;
		}

		$orderby = $query->get( 'orderby' );

		if ( 'videogame_status' === $orderby ) {
			$query->set( 'meta_key', 'videogame_status' );
			$query->set( 'orderby', 'meta_value' );

			// Define custom status order
			$meta_query = array(
				'relation' => 'OR',
				array(
					'key'     => 'videogame_status',
					'value'   => 'playing',
					'compare' => '=',
					'order'   => 1,
				),
				array(
					'key'     => 'videogame_status',
					'value'   => 'finished',
					'compare' => '=',
					'order'   => 2,
				),
				array(
					'key'     => 'videogame_status',
					'value'   => 'backlog',
					'compare' => '=',
					'order'   => 3,
				),
				array(
					'key'     => 'videogame_status',
					'value'   => 'wishlist',
					'compare' => '=',
					'order'   => 4,
				),
			);
			$query->set( 'meta_query', $meta_query );
		}
	}

	/**
	 * Display game status column
	 *
	 * @param string $column Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function display_videogame_status_column( $column, $post_id ) {
		if ( 'videogame_status' === $column ) {
			$status        = get_post_meta( $post_id, 'videogame_status', true );
			$status_labels = array(
				'finished' => __( 'Finished', 'mvb' ),
				'playing'  => __( 'Playing', 'mvb' ),
				'backlog'  => __( 'Backlog', 'mvb' ),
				'wishlist' => __( 'Wishlist', 'mvb' ),
			);
			// Add data attribute with raw value.
			printf(
				'<span class="videogame-status" data-status="%s">%s</span>',
				esc_attr( $status ),
				esc_html( $status_labels[ $status ] ?? $status )
			);
		} elseif ( 'videogame_completion_date' === $column ) {
			$completion_date = get_post_meta( $post_id, 'videogame_completion_date', true );
			printf(
				'<span class="videogame-completion-date" data-date="%s">%s</span>',
				esc_attr( $completion_date ),
				esc_html( $completion_date )
			);
		}
	}

	/**
	 * Get contrast color (black or white) based on background color
	 *
	 * @param string $hex_color Hex color code.
	 * @return string Black or white hex color
	 */
	private static function get_contrast_color( $hex_color ) {
		// Remove # if present
		$hex_color = ltrim( $hex_color, '#' );

		// Convert to RGB
		$r = hexdec( substr( $hex_color, 0, 2 ) );
		$g = hexdec( substr( $hex_color, 2, 2 ) );
		$b = hexdec( substr( $hex_color, 4, 2 ) );

		// Calculate luminance
		$luminance = ( 0.299 * $r + 0.587 * $g + 0.114 * $b ) / 255;

		// Return black or white based on luminance
		return $luminance > 0.5 ? '#000000' : '#ffffff';
	}

	/**
	 * Create default game statuses
	 */
	public static function create_default_game_statuses() {
		$default_statuses = array(
			'playing'  => array(
				'name'        => __( 'Playing', 'mvb' ),
				'description' => __( 'Games currently being played', 'mvb' ),
				'color'       => '#0288d1',
				'order'       => 1,
			),
			'finished' => array(
				'name'        => __( 'Finished', 'mvb' ),
				'description' => __( 'Games that have been completed', 'mvb' ),
				'color'       => '#2e7d32',
				'order'       => 2,
			),
			'backlog'  => array(
				'name'        => __( 'Backlog', 'mvb' ),
				'description' => __( 'Games owned but not yet played', 'mvb' ),
				'color'       => '#ef6c00',
				'order'       => 3,
			),
			'wishlist' => array(
				'name'        => __( 'Wishlist', 'mvb' ),
				'description' => __( 'Games to purchase in the future', 'mvb' ),
				'color'       => '#7b1fa2',
				'order'       => 4,
			),
		);

		foreach ( $default_statuses as $slug => $status ) {
			if ( ! term_exists( $slug, 'mvb_game_status' ) ) {
				$term = wp_insert_term(
					$status['name'],
					'mvb_game_status',
					array(
						'slug'        => $slug,
						'description' => $status['description'],
					)
				);

				if ( ! is_wp_error( $term ) ) {
					update_term_meta( $term['term_id'], 'status_color', $status['color'] );
					update_term_meta( $term['term_id'], 'status_order', $status['order'] );
				}
			}
		}
	}

	/**
	 * Add quick edit field
	 *
	 * @param string $column_name The column name.
	 * @param string $post_type   The post type.
	 */
	public static function add_quick_edit_field( $column_name, $post_type ) {
		if ( 'videogame' !== $post_type || 'taxonomy-mvb_game_status' !== $column_name ) {
			return;
		}
		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-col">
				<label class="inline-edit-group">
					<span class="title"><?php esc_html_e( 'Game Status', 'mvb' ); ?></span>
					<select name="tax_input[mvb_game_status][]">
						<option value=""><?php esc_html_e( 'Select Status', 'mvb' ); ?></option>
						<?php
						$terms = get_terms(
							array(
								'taxonomy'   => 'mvb_game_status',
								'hide_empty' => false,
								'orderby'    => 'meta_value_num',
								'meta_key'   => 'status_order',
							)
						);

						foreach ( $terms as $term ) {
							printf(
								'<option value="%s">%s</option>',
								esc_attr( $term->term_id ),
								esc_html( $term->name )
							);
						}
						?>
					</select>
				</label>
				<label class="inline-edit-group">
					<span class="title"><?php esc_html_e( 'Completion Date', 'mvb' ); ?></span>
					<input type="date" name="videogame_completion_date" />
				</label>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Add JavaScript for quick edit
	 */
	public static function quick_edit_javascript() {
		$screen = get_current_screen();

		// Only load on videogame post type
		if ( $screen->post_type !== 'videogame' ) {
			return;
		}

		// Only load on the edit.php page (list view), not on post edit screens or other pages
		if ( $screen->base !== 'edit' ) {
			return;
		}

		// Don't load on the migration page
		if ( isset( $_GET['page'] ) && $_GET['page'] === 'mvb-migrate-statuses' ) {
			return;
		}

		// Don't load on post edit action
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'edit' ) {
			return;
		}
		?>
		<script type="text/javascript">
		jQuery(function($) {
			// Check if inlineEditPost is defined
			if (typeof inlineEditPost === 'undefined') {
				console.error('inlineEditPost is not defined. Quick edit functionality may not work properly.');
				return;
			}
			
			// Store the original edit function
			var wp_inline_edit = inlineEditPost.edit;
			
			// Override the edit function
			inlineEditPost.edit = function(id) {
				// Call the original edit function
				wp_inline_edit.apply(this, arguments);
				
				var post_id = 0;
				if (typeof(id) == 'object') {
					post_id = parseInt(this.getId(id));
				}
				
				if (post_id > 0) {
					var $row = $('#post-' + post_id);
					var $editRow = $('#edit-' + post_id);
					
					// Get the status term ID
					var termId = $row.find('.column-taxonomy-mvb_game_status .mvb-status-badge').data('term-id');
					var completionDate = $row.find('.column-videogame_completion_date').text().trim();
					
					// Set values in the edit form
					if (termId) {
						$editRow.find('select[name="tax_input[mvb_game_status][]"]').val(termId);
					}
					$editRow.find('input[name="videogame_completion_date"]').val(completionDate);
				}
			};
		});
		</script>
		<?php
	}

	/**
	 * Migrate game statuses from post meta to taxonomy
	 *
	 * @param int $batch_size Number of games to process in a batch.
	 * @param int $offset Starting offset for batch processing.
	 * @return array Migration statistics and processing info.
	 */
	public static function migrate_game_statuses( $batch_size = 5, $offset = 0 ) {
		// Initialize stats array
		$stats = array(
			'total'          => 0,
			'processed'      => 0,
			'migrated'       => 0,
			'skipped'        => 0,
			'errors'         => 0,
			'complete'       => false,
			'next_offset'    => 0,
			'error_messages' => array(),
		);

		// Log memory usage at start
		$initial_memory = memory_get_usage( true ) / 1024 / 1024;
		error_log( sprintf( 'MVB Migration: Starting batch. Initial memory: %.2f MB', $initial_memory ) );

		try {
			// Get total count first (for progress tracking)
			$total_query    = new WP_Query(
				array(
					'post_type'      => 'videogame',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => false,
				)
			);
			$stats['total'] = $total_query->found_posts;
			wp_reset_postdata();

			// Get batch of games to process
			$games = get_posts(
				array(
					'post_type'      => 'videogame',
					'posts_per_page' => $batch_size,
					'offset'         => $offset,
					'post_status'    => 'any',
				)
			);

			$stats['processed']   = count( $games );
			$stats['next_offset'] = $offset + $stats['processed'];

			// Check if this is the last batch
			$stats['complete'] = ( $stats['next_offset'] >= $stats['total'] );

			// Process each game
			foreach ( $games as $game ) {
				try {
					$status = get_post_meta( $game->ID, 'videogame_status', true );

					if ( empty( $status ) ) {
						++$stats['skipped'];
						continue;
					}

					// Check if term exists
					$term = get_term_by( 'slug', $status, 'mvb_game_status' );

					if ( $term && ! is_wp_error( $term ) ) {
						// Term exists, set it for the game
						$result = wp_set_object_terms( $game->ID, $term->term_id, 'mvb_game_status' );

						if ( ! is_wp_error( $result ) ) {
							++$stats['migrated'];
						} else {
							++$stats['errors'];
							$stats['error_messages'][] = sprintf(
								'Error setting term for game #%d (%s): %s',
								$game->ID,
								$game->post_title,
								$result->get_error_message()
							);
						}
					} else {
						// Term doesn't exist, create it
						if ( ! term_exists( $status, 'mvb_game_status' ) ) {
							$new_term = wp_insert_term(
								ucfirst( $status ), // Capitalize the first letter
								'mvb_game_status',
								array(
									'slug'        => $status,
									'description' => sprintf( __( 'Custom status: %s', 'mvb' ), $status ),
								)
							);

							if ( ! is_wp_error( $new_term ) ) {
								// Set a default color
								update_term_meta( $new_term['term_id'], 'status_color', '#666666' );

								// Now assign the term
								$result = wp_set_object_terms( $game->ID, $new_term['term_id'], 'mvb_game_status' );
								if ( ! is_wp_error( $result ) ) {
									++$stats['migrated'];
								} else {
									++$stats['errors'];
									$stats['error_messages'][] = sprintf(
										'Error setting new term for game #%d (%s): %s',
										$game->ID,
										$game->post_title,
										$result->get_error_message()
									);
								}
							} else {
								++$stats['errors'];
								$stats['error_messages'][] = sprintf(
									'Error creating term for status "%s": %s',
									$status,
									$new_term->get_error_message()
								);
							}
						} else {
							// Term exists but couldn't be retrieved by get_term_by
							// Try to get it again by term_exists
							$existing_term = term_exists( $status, 'mvb_game_status' );
							if ( is_array( $existing_term ) ) {
								$result = wp_set_object_terms( $game->ID, $existing_term['term_id'], 'mvb_game_status' );
								if ( ! is_wp_error( $result ) ) {
									++$stats['migrated'];
								} else {
									++$stats['errors'];
									$stats['error_messages'][] = sprintf(
										'Error setting existing term for game #%d (%s): %s',
										$game->ID,
										$game->post_title,
										$result->get_error_message()
									);
								}
							} else {
								++$stats['skipped'];
								$stats['error_messages'][] = sprintf(
									'Term exists but could not be retrieved for status "%s" on game #%d (%s)',
									$status,
									$game->ID,
									$game->post_title
								);
							}
						}
					}

					// Free memory
					wp_cache_delete( $game->ID, 'posts' );
					wp_cache_delete( $game->ID, 'post_meta' );

				} catch ( Exception $e ) {
					++$stats['errors'];
					$stats['error_messages'][] = sprintf(
						'Exception processing game #%d (%s): %s',
						$game->ID,
						$game->post_title,
						$e->getMessage()
					);

					// Log the error
					error_log( sprintf( 'MVB Migration Error (Game #%d): %s', $game->ID, $e->getMessage() ) );
				}
			}
		} catch ( Exception $e ) {
			// Catch any exceptions in the main process
			++$stats['errors'];
			$stats['error_messages'][] = 'Fatal error: ' . $e->getMessage();
			error_log( 'MVB Migration Fatal Error: ' . $e->getMessage() );
		}

		// Add memory usage tracking
		$stats['memory_usage'] = memory_get_usage( true ) / 1024 / 1024; // in MB
		$memory_increase       = $stats['memory_usage'] - $initial_memory;
		error_log(
			sprintf(
				'MVB Migration: Ending batch. Final memory: %.2f MB, Increase: %.2f MB',
				$stats['memory_usage'],
				$memory_increase
			)
		);

		return $stats;
	}

	/**
	 * Add videogame status column
	 *
	 * @param array $columns The columns array.
	 * @return array Modified columns array.
	 */
	public static function add_videogame_status_column( $columns ) {
		// Remove date column.
		unset( $columns['date'] );

		// Add our custom columns.
		$columns['videogame_status']          = __( 'Status', 'mvb' );
		$columns['videogame_completion_date'] = __( 'Completion Date', 'mvb' );
		return $columns;
	}

	/**
	 * Save quick edit field
	 *
	 * @param int     $post_id The post ID.
	 * @param WP_Post $post    The post object.
	 * @return int The post ID.
	 */
	public static function save_quick_edit_field( $post_id, $post ) {
		// Skip autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $post_id;
		}

		// Check post type.
		if ( 'videogame' !== $post->post_type ) {
			return $post_id;
		}

		// Verify nonce and permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return $post_id;
		}

		// Save status if set.
		if ( isset( $_POST['videogame_status'] ) ) {
			update_post_meta(
				$post_id,
				'videogame_status',
				sanitize_text_field( wp_unslash( $_POST['videogame_status'] ) )
			);
		}

		// Save completion date if set.
		if ( isset( $_POST['videogame_completion_date'] ) ) {
			update_post_meta(
				$post_id,
				'videogame_completion_date',
				sanitize_text_field( wp_unslash( $_POST['videogame_completion_date'] ) )
			);
		}

		return $post_id;
	}

	/**
	 * Show admin notices
	 */
	public static function admin_notices() {
		// Check if migration is needed.
		$migration_needed = get_option( 'mvb_migration_needed', false );

		if ( $migration_needed && current_user_can( 'manage_options' ) ) {
			?>
			<div class="notice notice-info is-dismissible">
				<p>
					<?php esc_html_e( 'My Videogames Backlog plugin has been updated with a new status system. Please migrate your existing game statuses.', 'mvb' ); ?>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=videogame&page=mvb-migrate-statuses' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Migrate Now', 'mvb' ); ?>
					</a>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Check for plugin updates
	 */
	public static function check_for_updates() {
		$current_version = get_option( 'mvb_version', '0.0.0' );

		if ( version_compare( $current_version, MVB_VERSION, '<' ) ) {
			// Plugin was updated.
			update_option( 'mvb_version', MVB_VERSION );

			// If updating from a version before 1.1.0 (when taxonomy was introduced).
			if ( version_compare( $current_version, '1.1.0', '<' ) ) {
				update_option( 'mvb_migration_needed', true );
			}
		}
	}
}
