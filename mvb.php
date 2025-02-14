<?php
/**
 * Plugin Name: My Videogames Backlog
 * Requires Plugins: secure-custom-fields
 * Plugin URI: https://github.com/cbravobernal/mvb
 * Description: A secure custom fields plugin with block bindings for WordPress
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Carlos Bravo
 * Author URI: https://github.com/cbravobernal
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mvb
 * Domain Path: /languages
 *
 * @package MVB
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Define plugin constants
define( 'MVB_VERSION', '1.0.0' );
define( 'MVB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MVB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Include required files
require_once MVB_PLUGIN_DIR . 'includes/class-mvb-admin.php';
require_once MVB_PLUGIN_DIR . 'includes/class-mvb-igdb-api.php';

/**
 * Class MVB
 */
class MVB {

	/**
	 * Initialize the plugin
	 */
	public static function init() {
		// Initialize admin
		MVB_Admin::init();
		// Initialize IGDB API
		MVB_IGDB_API::init();

		// Load text domain
		add_action( 'plugins_loaded', array( __CLASS__, 'load_textdomain' ) );

		wp_register_script_module(
			'@mvb/main',
			plugins_url( 'assets/js/main.js', __FILE__ ),
			array(
				'@wordpress/interactivity',
			),
			MVB_VERSION
		);

		add_action(
			'admin_enqueue_scripts',
			function ( ) {
				if ( isset( $_GET['page'] ) && $_GET['page'] === 'mvb-settings' ) {
					wp_enqueue_script_module( '@mvb/main' );
				}
			}
		);

		// Add quick edit functionality
		add_action('quick_edit_custom_box', array(__CLASS__, 'add_quick_edit_field'), 10, 2);
		add_action('save_post', array(__CLASS__, 'save_quick_edit_field'), 10, 2);
		add_action('admin_footer', array(__CLASS__, 'quick_edit_javascript'));
		
		// Add column for status
		add_filter('manage_videogame_posts_columns', array(__CLASS__, 'add_videogame_status_column'));
		add_action('manage_videogame_posts_custom_column', array(__CLASS__, 'display_videogame_status_column'), 10, 2);
	}

	/**
	 * Load plugin text domain
	 */
	public static function load_textdomain() {
		load_plugin_textdomain(
			'mvb',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages/'
		);
	}

	/**
	 * Activate the plugin
	 */
	public static function activate() {
		// Create tasks on plugin activation
	}

	/**
	 * Deactivate the plugin
	 */
	public static function deactivate() {
		// Cleanup tasks if needed
	}

	/**
	 * Add videogame status column
	 */
	public static function add_videogame_status_column($columns) {
		$columns['videogame_status'] = __('Status', 'mvb');
		return $columns;
	}

	/**
	 * Display videogame status in the column
	 */
	public static function display_videogame_status_column($column, $post_id) {
		if ($column === 'videogame_status') {
			$status = get_post_meta($post_id, 'videogame_status', true);
			echo esc_html($status);
		}
	}

	/**
	 * Add quick edit field
	 */
	public static function add_quick_edit_field($column_name, $post_type) {
		if ($post_type !== 'videogame' || $column_name !== 'videogame_status') {
			return;
		}
		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-col">
				<label class="inline-edit-group">
					<span class="title"><?php esc_html_e('Status', 'mvb'); ?></span>
					<select name="videogame_status">
						<option value=""><?php esc_html_e('Select Status', 'mvb'); ?></option>
						<option value="played"><?php esc_html_e('Played', 'mvb'); ?></option>
						<option value="playing"><?php esc_html_e('Playing', 'mvb'); ?></option>
						<option value="backlog"><?php esc_html_e('Backlog', 'mvb'); ?></option>
						<option value="wishlist"><?php esc_html_e('Wishlist', 'mvb'); ?></option>
					</select>
				</label>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Save quick edit field
	 */
	public static function save_quick_edit_field($post_id, $post) {
		// Skip autosave
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return $post_id;
		}

		// Check post type
		if ($post->post_type !== 'videogame') {
			return $post_id;
		}

		// Verify nonce and permissions
		if (!current_user_can('edit_post', $post_id)) {
			return $post_id;
		}

		// Save status if set
		if (isset($_POST['videogame_status'])) {
			update_post_meta(
				$post_id,
				'videogame_status',
				sanitize_text_field($_POST['videogame_status'])
			);
		}
	}

	/**
	 * Add JavaScript for quick edit
	 */
	public static function quick_edit_javascript() {
		$screen = get_current_screen();
		if ($screen->post_type !== 'videogame') {
			return;
		}
		?>
		<script type="text/javascript">
		jQuery(function($) {
			var $wp_inline_edit = inlineEditPost.edit;
			inlineEditPost.edit = function(id) {
				$wp_inline_edit.apply(this, arguments);
				var post_id = 0;
				if (typeof(id) == 'object') {
					post_id = parseInt(this.getId(id));
				}
				if (post_id > 0) {
					var status = $('#post-' + post_id).find('.column-videogame_status').text();
					$('select[name="videogame_status"]').val(status);
				}
			};
		});
		</script>
		<?php
	}
}

// Initialize the plugin
MVB::init();
