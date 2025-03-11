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

		// Add status filter dropdown.
		add_action( 'restrict_manage_posts', array( __CLASS__, 'add_game_status_filter' ) );
		add_filter( 'parse_query', array( __CLASS__, 'filter_games_by_status' ) );

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

		// Create gamer role.
		self::create_gamer_role();

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

		if ( 'videogame_completion_date' === $orderby ) {
			$query->set( 'meta_key', 'videogame_completion_date' );
			$query->set( 'orderby', 'meta_value' );
		}
	}

	/**
	 * Display game status column
	 *
	 * @param string $column Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function display_videogame_status_column( $column, $post_id ) {
		if ( 'videogame_completion_date' === $column ) {
			$completion_date = get_post_meta( $post_id, 'videogame_completion_date', true );
			echo esc_html( $completion_date );
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

		// Return black for bright colors, white for dark colors
		return $luminance > 0.5 ? '#000000' : '#ffffff';
	}

	/**
	 * Create default Game Status
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

		// Only load on videogame post type.
		if ( 'videogame' !== $screen->post_type ) {
			return;
		}

		// Only load on the edit.php page (list view), not on post edit screens or other pages.
		if ( 'edit' !== $screen->base ) {
			return;
		}

		// Don't load on the migration page.
		if ( isset( $_GET['page'] ) && 'mvb-migrate-statuses' === $_GET['page'] ) {
			return;
		}

		// Don't load on post edit action.
		if ( isset( $_GET['action'] ) && 'edit' === $_GET['action'] ) {
			return;
		}
		?>
		<script type="text/javascript">
		jQuery(function($) {
			// Check if inlineEditPost is defined.
			if (typeof inlineEditPost === 'undefined') {
				console.error('inlineEditPost is not defined. Quick edit functionality may not work properly.');
				return;
			}
			
			// Store the original edit function.
			var wp_inline_edit = inlineEditPost.edit;
			
			// Override the edit function.
			inlineEditPost.edit = function(id) {
				// Call the original edit function.
				wp_inline_edit.apply(this, arguments);
				
				var post_id = 0;
				if (typeof(id) == 'object') {
					post_id = parseInt(this.getId(id));
				}
				
				if (post_id > 0) {
					var $row = $('#post-' + post_id);
					var $editRow = $('#edit-' + post_id);
					
					// Get the completion date.
					var completionDate = $row.find('.column-videogame_completion_date').text().trim();
					
					// Set value in the edit form.
					$editRow.find('input[name="videogame_completion_date"]').val(completionDate);
				}
			};
		});
		</script>
		<?php
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

		// Add our custom column.
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
	 * Check for plugin updates.
	 */
	public static function check_for_updates() {
		$current_version = get_option( 'mvb_version', '0.0.0' );

		if ( version_compare( $current_version, MVB_VERSION, '<' ) ) {
			// Plugin was updated.
			update_option( 'mvb_version', MVB_VERSION );
		}
	}

	/**
	 * Add status filter dropdown.
	 */
	public static function add_game_status_filter() {
		global $pagenow, $typenow;

		// Only add on the videogame post type list screen.
		if ( 'edit.php' !== $pagenow || 'videogame' !== $typenow ) {
			return;
		}

		// Get all game status terms.
		$statuses = get_terms(
			array(
				'taxonomy'   => 'mvb_game_status',
				'hide_empty' => false,
			)
		);

		if ( empty( $statuses ) || is_wp_error( $statuses ) ) {
			return;
		}

		$selected_status = isset( $_GET['mvb_game_status'] ) ? sanitize_text_field( wp_unslash( $_GET['mvb_game_status'] ) ) : '';
		?>
		<select name="mvb_game_status" class="mvb-status-filter">
			<option value=""><?php esc_html_e( 'All statuses', 'mvb' ); ?></option>
			<?php foreach ( $statuses as $status ) : ?>
				<option value="<?php echo esc_attr( $status->slug ); ?>" <?php selected( $selected_status, $status->slug ); ?>>
					<?php echo esc_html( $status->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Filter games by status.
	 *
	 * @param WP_Query $query The query object.
	 */
	public static function filter_games_by_status( $query ) {
		global $pagenow, $typenow;

		// Only filter on the videogame post type list screen.
		if ( ! is_admin() || 'edit.php' !== $pagenow || 'videogame' !== $typenow ) {
			return;
		}

		// Check if our filter is set.
		if ( ! isset( $_GET['mvb_game_status'] ) || empty( $_GET['mvb_game_status'] ) ) {
			return;
		}

		// Apply the filter.
		$status = sanitize_text_field( wp_unslash( $_GET['mvb_game_status'] ) );

		$tax_query = array(
			array(
				'taxonomy' => 'mvb_game_status',
				'field'    => 'slug',
				'terms'    => $status,
			),
		);

		$query->set( 'tax_query', $tax_query );
	}

	/**
	 * Create the Gamer role.
	 */
	private static function create_gamer_role() {
		// Get or create the gamer role.
		$gamer = get_role( 'mvb_gamer' );
		if ( null === $gamer ) {
			$gamer = add_role(
				'mvb_gamer',
				__( 'Gamer', 'mvb' ),
				array(
					'read' => true,
					'upload_files' => true, // Required for uploading game covers and media files!
					'edit_mvb_game' => true,
					'read_mvb_game' => true,
					'delete_mvb_game' => true,
					'edit_mvb_games' => true,
					'publish_mvb_games' => true,
					'mvb_manage_igdb_settings' => true, // Allow gamers to manage their own IGDB settings!
				)
			);
		} else {
			// Update existing role capabilities.
			$gamer->add_cap( 'read' );
			$gamer->add_cap( 'upload_files' );
			$gamer->add_cap( 'edit_mvb_game' );
			$gamer->add_cap( 'read_mvb_game' );
			$gamer->add_cap( 'delete_mvb_game' );
			$gamer->add_cap( 'edit_mvb_games' );
			$gamer->add_cap( 'publish_mvb_games' );
			$gamer->add_cap( 'mvb_manage_igdb_settings' );
		}
	}
}
