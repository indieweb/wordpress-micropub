<?php
/**
 * Micropub Class
 *
 * @package Micropub
 */

namespace Micropub;

use Micropub\Rest\Endpoint_Controller;
use Micropub\Rest\Media_Controller;

/**
 * Micropub Class
 *
 * Main plugin class that initializes all components.
 *
 * @package Micropub
 */
class Micropub {
	/**
	 * Instance of the class.
	 *
	 * @var Micropub
	 */
	private static $instance;

	/**
	 * Text domain.
	 *
	 * @var string
	 */
	const TEXT_DOMAIN = 'micropub';

	/**
	 * Get the instance of the class.
	 *
	 * @return Micropub
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Do not allow multiple instances of the class.
	 */
	private function __construct() {
		// Do nothing.
	}

	/**
	 * Initialize the plugin by registering hooks.
	 */
	public function init() {
		\add_action( 'rest_api_init', array( $this, 'rest_init' ) );
		\add_action( 'init', array( $this, 'plugin_init' ) );
		\add_action( 'admin_notices', array( $this, 'ssl_notice' ) );
	}

	/**
	 * Initialize REST routes.
	 */
	public function rest_init() {
		( new Endpoint_Controller() )->register_routes();
		( new Media_Controller() )->register_routes();
	}

	/**
	 * Initialize plugin components.
	 */
	public function plugin_init() {
		// Initialize Discovery.
		Discovery::init();

		// Initialize Micropub Render.
		\add_filter( 'the_content', array( Render::class, 'render_content' ), 1 );
	}

	/**
	 * Get the plugin version.
	 *
	 * @return string
	 */
	public function get_version() {
		return MICROPUB_PLUGIN_VERSION;
	}

	/**
	 * Display SSL warning notice.
	 */
	public function ssl_notice() {
		if ( \is_ssl() || MICROPUB_DISABLE_NAG ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p><?php \esc_html_e( 'For security reasons you should use Micropub only on an HTTPS domain.', 'micropub' ); ?></p>
		</div>
		<?php
	}
}
