<?php

class WP_Micropub_Error extends WP_REST_Response {

	public function __construct( $error, $error_description, $code = 200, $debug = null ) {
		$this->set_status( $code );
		$data = array(
			'error'             => $error,
			'error_description' => $error_description,
			'data'              => $debug,
		);
		$data = array_filter( $data );
		$this->set_data( $data );
		if ( WP_DEBUG && ! defined( 'DIR_TESTDATA' ) ) {
			error_log( $this->to_log() ); // phpcs:ignore
		}
	}

	public function set_debug( $a ) {
		$data = $this->get_data();
		$this->set_data( array_merge( $data, $a ) );
	}

	public function to_wp_error() {
		$data   = $this->get_data();
		$status = $this->get_status();
		return new WP_Error(
			$data['error'],
			$data['error_description'],
			array(
				'status' => $status,
				'data'   => mp_get( $data, 'data' ),
			)
		);
	}

	public function to_log() {
		$data   = $this->get_data();
		$status = $this->get_status();
		$debug  = mp_get( $data, 'debug', array() );
		return sprintf( 'Micropub Error: %1$s %2$s - %3$s', $status, $data['error'], $data['error_description'], wp_json_encode( $debug ) );
	}
}
