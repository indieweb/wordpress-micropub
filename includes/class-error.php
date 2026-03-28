<?php
/**
 * Micropub Error Class.
 *
 * @package Micropub
 */

namespace Micropub;

/**
 * Micropub Error Class.
 *
 * Extends WP_REST_Response to provide standardized Micropub error responses.
 */
class Error extends \WP_REST_Response {

	/**
	 * Constructor.
	 *
	 * @param string $error             Error code.
	 * @param string $error_description Error description.
	 * @param int    $code              HTTP status code.
	 * @param mixed  $debug             Debug data.
	 */
	public function __construct( $error, $error_description, $code = 200, $debug = null ) {
		$data = array(
			'error'             => $error,
			'error_description' => $error_description,
			'data'              => $debug,
		);
		$data = array_filter( $data );
		parent::__construct( $data, $code );
		if ( \WP_DEBUG && ! \defined( 'DIR_TESTDATA' ) ) {
			\error_log( $this->to_log() ); // phpcs:ignore
		}
	}

	/**
	 * Set debug data.
	 *
	 * @param array $a Debug data to add.
	 */
	public function set_debug( $a ) {
		$data = $this->get_data();
		$this->set_data( array_merge( $data, $a ) );
	}

	/**
	 * Convert to WP_Error.
	 *
	 * @return \WP_Error
	 */
	public function to_wp_error() {
		$data   = $this->get_data();
		$status = $this->get_status();
		return new \WP_Error(
			$data['error'],
			$data['error_description'],
			array(
				'status' => $status,
				'data'   => mp_get( $data, 'data' ),
			)
		);
	}

	/**
	 * Format error for logging.
	 *
	 * @return string
	 */
	public function to_log() {
		$data   = $this->get_data();
		$status = $this->get_status();
		$debug  = mp_get( $data, 'debug', array() );
		return \sprintf( 'Micropub Error: %1$s %2$s - %3$s', $status, $data['error'], $data['error_description'], \wp_json_encode( $debug ) );
	}
}
