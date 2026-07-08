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
	 * Option name for storing microformats2 theme support detection.
	 *
	 * @var string
	 */
	const MICROFORMATS2_SUPPORT_OPTION = 'micropub_theme_supports_mf2';

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

		// Clear microformats2 support cache when theme changes.
		\add_action( 'switch_theme', array( $this, 'clear_microformats2_support_cache' ) );
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

	/**
	 * Clear the cached microformats2 support detection.
	 */
	public function clear_microformats2_support_cache() {
		\delete_option( self::MICROFORMATS2_SUPPORT_OPTION );
	}

	/**
	 * Check if the current theme supports microformats2.
	 *
	 * This checks explicit theme support first, then falls back to
	 * cached detection results. If no cached result exists and we're
	 * on a singular page, it will detect and cache the result.
	 *
	 * Detection is only scheduled on singular frontend views. In other
	 * contexts (admin, REST API requests) this returns the cached result,
	 * or false if no detection has run yet — so content is wrapped by
	 * default until a frontend view populates the cache.
	 *
	 * @return bool True if theme supports microformats2.
	 */
	public static function theme_supports_microformats2() {
		// First check explicit theme support declaration.
		if ( \current_theme_supports( 'microformats2' ) ) {
			return true;
		}

		// Check cached detection result.
		$cached = \get_option( self::MICROFORMATS2_SUPPORT_OPTION );

		if ( false !== $cached ) {
			return 'yes' === $cached;
		}

		// If we're on a singular frontend page, detect and cache the result.
		if ( ! \is_admin() && \is_singular() ) {
			// Hook into shutdown to check the rendered output, but avoid adding multiple times.
			if ( ! \has_action( 'shutdown', array( self::class, 'detect_microformats2_support' ) ) ) {
				\add_action( 'shutdown', array( self::class, 'detect_microformats2_support' ), 0 );
			}
		}

		// Return false for now, will be cached after first detection.
		return false;
	}

	/**
	 * Detect microformats2 support by checking the rendered page output.
	 *
	 * This runs at shutdown to capture the full page HTML and check
	 * if e-content class exists in the template markup.
	 *
	 * The detection result is stored in a persistent option that survives
	 * across requests. This cached value is reused by theme_supports_microformats2()
	 * on subsequent requests and is only cleared when the theme is switched.
	 */
	public static function detect_microformats2_support() {
		$output = \ob_get_contents();

		if ( empty( $output ) ) {
			return;
		}

		// Remove code and pre blocks to avoid false positives from user content.
		$filtered = preg_replace( '/<(code|pre)[^>]*>.*?<\/\1>/is', '', $output );

		// If the regex fails, abort detection to avoid incorrect caching.
		if ( null === $filtered ) {
			return;
		}

		// Check if e-content exists as a class attribute value.
		// Matches class="...e-content...", class='...e-content...' or class=e-content (unquoted).
		$has_support = preg_match( '/\bclass\s*=\s*(?:"[^"]*\be-content\b[^"]*"|\'[^\']*\be-content\b[^\']*\'|[^\s>]*\be-content\b[^\s>]*)/i', $filtered );

		\update_option( self::MICROFORMATS2_SUPPORT_OPTION, $has_support ? 'yes' : 'no', false );
	}
}
