<?php
/**
 * Plugin Name: Micropub
 * Plugin URI: https://github.com/indieweb/wordpress-micropub
 * Description: <a href="https://indiewebcamp.com/micropub">Micropub</a> server.
 * Protocol spec: <a href="https://micropub.spec.indieweb.org/">Micropub Living Standard</a>
 * Author: IndieWeb WordPress Outreach Club
 * Requires at least: 4.9.9
 * Requires PHP: 7.2
 * Requires Plugins: indieauth
 * Author URI: https://indieweb.org/WordPress_Outreach_Club
 * Text Domain: micropub
 * License: CC0
 * License URI: http://creativecommons.org/publicdomain/zero/1.0/
 * Version: 2.5.0
 *
 * @package Micropub
 */

namespace Micropub;

\define( 'MICROPUB_PLUGIN_VERSION', '2.5.0' );

\defined( 'MICROPUB_NAMESPACE' ) || \define( 'MICROPUB_NAMESPACE', 'micropub/1.0' );

// For debugging purposes this will set all Micropub posts to Draft.
\defined( 'MICROPUB_DRAFT_MODE' ) || \define( 'MICROPUB_DRAFT_MODE', '0' );

\define( 'MICROPUB_PLUGIN_DIR', \plugin_dir_path( __FILE__ ) );
\define( 'MICROPUB_PLUGIN_FILE', __FILE__ );

// Load the autoloader.
require_once MICROPUB_PLUGIN_DIR . 'includes/class-autoloader.php';

// Register the autoloader.
Autoloader::register_path( __NAMESPACE__, MICROPUB_PLUGIN_DIR . 'includes' );

// Global Functions.
require_once MICROPUB_PLUGIN_DIR . 'includes/functions.php';

// Compatibility Functions with Newer WordPress Versions.
require_once MICROPUB_PLUGIN_DIR . 'includes/compat-functions.php';

if ( \class_exists( 'IndieAuth_Plugin' ) ) {
	\add_action( 'plugins_loaded', array( Micropub::get_instance(), 'init' ) );
} else {
	\add_action( 'admin_notices', __NAMESPACE__ . '\indieauth_not_installed_notice' );
}

/**
 * Display IndieAuth not installed notice.
 */
function indieauth_not_installed_notice() {
	?>
	<div class="notice notice-error">
		<p><?php \esc_html_e( 'To use Micropub, you must have IndieAuth support. Please install the IndieAuth plugin.', 'micropub' ); ?></p>
	</div>
	<?php
}

/**
 * Get the plugin version.
 *
 * @return string
 */
function get_plugin_version() {
	return Micropub::get_instance()->get_version();
}
