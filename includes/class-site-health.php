<?php
/**
 * Site Health Class.
 *
 * @package Micropub
 */

namespace Micropub;

/**
 * Site Health Class.
 *
 * Adds Micropub-specific tests to the WordPress Site Health screen.
 */
class Site_Health {
	/**
	 * Initialize Site Health hooks.
	 */
	public static function init() {
		\add_filter( 'site_status_tests', array( self::class, 'add_tests' ) );
	}

	/**
	 * Register Micropub Site Health tests.
	 *
	 * @param array $tests The existing Site Health tests.
	 * @return array
	 */
	public static function add_tests( $tests ) {
		$tests['direct']['micropub_ssl'] = array(
			'label' => \__( 'Micropub HTTPS', 'micropub' ),
			'test'  => array( self::class, 'test_ssl' ),
		);

		return $tests;
	}

	/**
	 * Site Health test for SSL/HTTPS.
	 *
	 * @return array
	 */
	public static function test_ssl() {
		$result = array(
			'label'       => \__( 'Micropub is running on HTTPS', 'micropub' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => \__( 'Micropub', 'micropub' ),
				'color' => 'blue',
			),
			'description' => \sprintf(
				'<p>%s</p>',
				\__( 'Micropub is using HTTPS, which is required for secure token-based authentication.', 'micropub' )
			),
			'test'        => 'micropub_ssl',
		);

		if ( ! \is_ssl() ) {
			$result['status']      = 'recommended';
			$result['label']       = \__( 'Micropub is not running on HTTPS', 'micropub' );
			$result['description'] = \sprintf(
				'<p>%s</p>',
				\__( 'For security reasons you should use Micropub only on an HTTPS domain.', 'micropub' )
			);
			$result['actions']     = \sprintf(
				'<p><a href="%s">%s</a></p>',
				\esc_url( \admin_url( 'options-general.php' ) ),
				\__( 'Update your site address', 'micropub' )
			);
		}

		return $result;
	}
}
