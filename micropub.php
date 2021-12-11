<?php
/**
 * Plugin Name: Micropub
 * Plugin URI: https://github.com/indieweb/wordpress-micropub
 * Description: <a href="https://indiewebcamp.com/micropub">Micropub</a> server.
 * Protocol spec: <a href="https://micropub.spec.indieweb.org/">Micropub Living Standard</a>
 * Author: IndieWeb WordPress Outreach Club
 * Requires at least: 4.9.9
 * Requires PHP: 5.6
 * Author URI: https://indieweb.org/WordPress_Outreach_Club
 * Text Domain: micropub
 * License: CC0
 * License URI: http://creativecommons.org/publicdomain/zero/1.0/
 * Version: 2.3.0
 */

/* See README for supported filters and actions.
 * Example command lines for testing:
 * Form-encoded:
 * curl -i -H 'Authorization: Bearer ...' -F h=entry -F name=foo -F content=bar \
 *   -F photo=@gallery/snarfed.gif 'http://localhost/wp-json/micropub/1.0/endpoint'
 * JSON:
 * curl -v -d @body.json -H 'Content-Type: application/json' 'http://localhost/w/?micropub=endpoint'
 *
 */

if ( ! defined( 'MICROPUB_NAMESPACE' ) ) {
	define( 'MICROPUB_NAMESPACE', 'micropub/1.0' );
}

if ( ! defined( 'MICROPUB_DISABLE_NAG' ) ) {
	define( 'MICROPUB_DISABLE_NAG', 0 );
}


if ( class_exists( 'IndieAuth_Plugin' ) ) {

	// Global Functions
	require_once plugin_dir_path( __FILE__ ) . 'includes/functions.php';

	// Compatibility Functions with Newer WordPress Versions
	require_once plugin_dir_path( __FILE__ ) . 'includes/compat-functions.php';

	// Admin Menu Functions
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-micropub-admin.php';

	// Error Handling Class
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-micropub-error.php';

	// Endpoint Base Class.
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-micropub-base.php';

	// Media Endpoint and Handling Functions
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-micropub-media.php';

	// Server Functions
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-micropub-endpoint.php';

	// Render Functions
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-micropub-render.php';

} else {

	add_action( 'admin_notices', 'micropub_indieauth_not_installed_notice' );
}
function micropub_not_ssl_notice() {
	if ( is_ssl() || MICROPUB_DISABLE_NAG ) {
		return;
	}
	?>
	<div class="notice notice-warning">
		<p>For security reasons you should use Micropub only on an HTTPS domain.</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'micropub_not_ssl_notice' );


function micropub_indieauth_not_installed_notice() {
	?>
	<div class="notice notice-error">
		<p>To use Micropub, you must have IndieAuth support. Please install the IndieAuth plugin.</p>
	</div>
	<?php
}

