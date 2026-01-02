<?php
/**
 * Micropub Class
 *
 * @package Micropub
 */

namespace Micropub;

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
	 * Whether the class has been initialized.
	 *
	 * @var boolean
	 */
	private $initialized = false;

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
	 * Initialize the plugin.
	 */
	public function init() {
		if ( $this->initialized ) {
			return;
		}

		$this->register_hooks();

		$this->initialized = true;
	}

	/**
	 * Get the plugin version.
	 *
	 * @return string
	 */
	public function get_version() {
		return \get_file_data( MICROPUB_PLUGIN_FILE, array( 'Version' => 'Version' ) )['Version'];
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		// Initialize Micropub Endpoint.
		Endpoint::init();

		// Initialize Micropub Media Endpoint.
		Media::init();

		// Initialize Micropub Render.
		\add_filter( 'the_content', array( Render::class, 'render_content' ), 1 );

		// Admin notices.
		\add_action( 'admin_notices', array( $this, 'ssl_notice' ) );
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
