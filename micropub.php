<?php
/**
 * Plugin Name: Micropub
 * Plugin URI: https://github.com/snarfed/wordpress-micropub
 * Description: <a href="https://indiewebcamp.com/micropub">Micropub</a> server.
 * Protocol spec: <a href="https://www.w3.org/TR/micropub/">w3.org/TR/micropub</a>
 * Author: IndieWeb WordPress Outreach Club
 * Author URI: https://indieweb.org/WordPress_Outreach_Club
 * Text Domain: micropub
 * Version: 2.2.1
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

