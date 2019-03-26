<?php
/**
 * Plugin Name: Micropub
 * Plugin URI: https://github.com/snarfed/wordpress-micropub
 * Description: <a href="https://indiewebcamp.com/micropub">Micropub</a> server.
 * Protocol spec: <a href="https://www.w3.org/TR/micropub/">w3.org/TR/micropub</a>
 * Author: Ryan Barrett
 * Author URI: https://snarfed.org/
 * Text Domain: micropub
 * Version: 2.0.9
 */

/* See README for supported filters and actions.
 * Example command lines for testing:
 * Form-encoded:
 * curl -i -H 'Authorization: Bearer ...' -F h=entry -F name=foo -F content=bar \
 *   -F photo=@gallery/snarfed.gif 'http://localhost/w/?micropub=endpoint'
 * JSON:
 * curl -v -d @body.json -H 'Content-Type: application/json' 'http://localhost/w/?micropub=endpoint'
 *
 * To generate an access token for testing:
 * 1. Open this in a browser, filling in SITE:
 *   https://indieauth.com/auth?me=SITE&scope=post&client_id=https://wordpress.org/plugins/micropub/&redirect_uri=https%3A%2F%2Findieauth.com%2Fsuccess
 * 2. Log in.
 * 3. Extract the code param from the URL.
 * 4. Run this command line, filling in CODE and SITE (which logged into IndieAuth):
 *   curl -i -d 'code=CODE&me=SITE&client_id=indieauth&redirect_uri=https://indieauth.com/success' 'https://tokens.indieauth.com/token'
 * 5. Extract the access_token parameter from the response body.
 */

if ( ! defined( 'MICROPUB_NAMESPACE' ) ) {
	define( 'MICROPUB_NAMESPACE', 'micropub/1.0' );
}

if ( ! defined( 'MICROPUB_DISABLE_NAG' ) ) {
	define( 'MICROPUB_DISABLE_NAG', 0 );
}

if ( ! defined( 'MICROPUB_LOCAL_AUTH' ) ) {
	define( 'MICROPUB_LOCAL_AUTH', 0 );
}

// Global Functions
require_once plugin_dir_path( __FILE__ ) . 'includes/functions.php';

// Admin Menu Functions
require_once plugin_dir_path( __FILE__ ) . 'includes/class-micropub-admin.php';

function load_micropub_auth() {
	// Always disable local auth when the IndieAuth Plugin is installed
	if ( class_exists( 'IndieAuth_Plugin' ) ) {
		return;
	}
	// If this configuration option is set to 0 then load this file
	if ( 0 === MICROPUB_LOCAL_AUTH ) {
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-micropub-authorize.php';

	}
}

// Load auth at the plugins loaded stage in order to ensure it occurs after the IndieAuth plugin is loaded
add_action( 'plugins_loaded', 'load_micropub_auth', 20 );

// Error Handling Class
require_once plugin_dir_path( __FILE__ ) . 'includes/class-micropub-error.php';

// Media Endpoint and Handling Functions
require_once plugin_dir_path( __FILE__ ) . 'includes/class-micropub-media.php';

// Server Functions
require_once plugin_dir_path( __FILE__ ) . 'includes/class-micropub-endpoint.php';

// Render Functions
require_once plugin_dir_path( __FILE__ ) . 'includes/class-micropub-render.php';

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

