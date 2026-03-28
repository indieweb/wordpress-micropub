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
		Site_Health::init();
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
		Render::init();
	}

	/**
	 * Get the plugin version.
	 *
	 * @return string
	 */
	public function get_version() {
		return MICROPUB_PLUGIN_VERSION;
	}
}
