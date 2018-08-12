<?php
/**
 * Plugin Name: Micropub
 * Plugin URI: https://github.com/snarfed/wordpress-micropub
 * Description: <a href="https://indiewebcamp.com/micropub">Micropub</a> server.
 * Protocol spec: <a href="https://www.w3.org/TR/micropub/">w3.org/TR/micropub</a>
 * Author: Ryan Barrett
 * Author URI: https://snarfed.org/
 * Text Domain: micropub
 * Version: 1.4.3
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

if ( ! defined( 'MICROPUB_LOCAL_AUTH' ) ) {
	define( 'MICROPUB_LOCAL_AUTH', '0' );
}

if ( ! defined( 'MICROPUB_NAMESPACE' ) ) {
	define( 'MICROPUB_NAMESPACE', 'micropub/1.0' );
}

// Global Functions
require_once plugin_dir_path( __FILE__ ) . 'includes/functions.php';

// Admin Menu Functions
require_once plugin_dir_path( __FILE__ ) . 'includes/class-micropub-admin.php';

if ( MICROPUB_LOCAL_AUTH || ! class_exists( 'IndieAuth_Plugin' ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-micropub-authorize.php';
}

// Error Handling Class
require_once plugin_dir_path( __FILE__ ) . 'includes/class-micropub-error.php';

// Media Endpoint and Handling Functions
require_once plugin_dir_path( __FILE__ ) . 'includes/class-micropub-media.php';

// Server Functions
require_once plugin_dir_path( __FILE__ ) . 'includes/class-micropub-endpoint.php';

// Render Functions
require_once plugin_dir_path( __FILE__ ) . 'includes/class-micropub-render.php';

