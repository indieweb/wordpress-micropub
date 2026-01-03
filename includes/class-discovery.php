<?php
/**
 * Discovery Class.
 *
 * @package Micropub
 */

namespace Micropub;

/**
 * Discovery Class.
 *
 * Handles endpoint discovery via HTML headers, HTTP headers, and WebFinger.
 */
class Discovery {
	/**
	 * Initialize discovery hooks.
	 */
	public static function init() {
		\add_action( 'wp_head', array( self::class, 'html_header' ), 99 );
		\add_action( 'send_headers', array( self::class, 'http_header' ) );
		\add_filter( 'host_meta', array( self::class, 'jrd_links' ) );
		\add_filter( 'webfinger_user_data', array( self::class, 'jrd_links' ) );
	}

	/**
	 * Get the Micropub endpoint URL.
	 *
	 * @return string
	 */
	public static function get_micropub_endpoint() {
		return \rest_url( MICROPUB_NAMESPACE . '/endpoint' );
	}

	/**
	 * Get the Micropub media endpoint URL.
	 *
	 * @return string
	 */
	public static function get_media_endpoint() {
		return \rest_url( MICROPUB_NAMESPACE . '/media' );
	}

	/**
	 * Output the autodiscovery HTML headers.
	 */
	public static function html_header() {
		printf(
			'<link rel="micropub" href="%s" />' . PHP_EOL,
			\esc_url( self::get_micropub_endpoint() )
		);
		printf(
			'<link rel="micropub_media" href="%s" />' . PHP_EOL,
			\esc_url( self::get_media_endpoint() )
		);
	}

	/**
	 * Output the autodiscovery HTTP headers.
	 */
	public static function http_header() {
		\header(
			sprintf(
				'Link: <%s>; rel="micropub"',
				self::get_micropub_endpoint()
			),
			false
		);
		\header(
			sprintf(
				'Link: <%s>; rel="micropub_media"',
				self::get_media_endpoint()
			),
			false
		);
	}

	/**
	 * Add JRD links for discovery.
	 *
	 * @param array $links Existing links.
	 * @return array Modified links.
	 */
	public static function jrd_links( $links ) {
		$links['links'][] = array(
			'rel'  => 'micropub',
			'href' => self::get_micropub_endpoint(),
		);
		$links['links'][] = array(
			'rel'  => 'micropub_media',
			'href' => self::get_media_endpoint(),
		);
		return $links;
	}
}
