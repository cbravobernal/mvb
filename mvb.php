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
}

// Initialize the plugin
MVB::init();
