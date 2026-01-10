<?php
/**
 * Micropub Trait.
 *
 * @package Micropub
 */

namespace Micropub\Rest;

use Micropub\Error;

/**
 * Micropub Trait.
 *
 * Provides common functionality for Micropub REST controllers.
 */
trait Micropub {
	/**
	 * Auth response data.
	 *
	 * @var array
	 */
	protected $micropub_auth_response = array();

	/**
	 * Array of OAuth scopes.
	 *
	 * @var array
	 */
	protected $scopes = array();

	/**
	 * Load authentication data.
	 *
	 * @return true|\WP_Error
	 */
	protected function load_auth() {
		if ( ! \is_user_logged_in() ) {
			return new \WP_Error( 'forbidden', 'Unauthorized', array( 'status' => 403 ) );
		}

		$this->micropub_auth_response = \micropub_get_response();
		$this->scopes                 = \micropub_get_scopes();

		// If there is no auth response this is cookie authentication which should be rejected.
		if ( empty( $this->micropub_auth_response ) ) {
			return new \WP_Error( 'unauthorized', 'Cookie Authentication is not permitted', array( 'status' => 401 ) );
		}

		return true;
	}

	/**
	 * Check query permissions.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return true|\WP_Error
	 */
	public function check_query_permissions( $request ) {
		$auth = $this->load_auth();
		if ( \is_wp_error( $auth ) ) {
			return $auth;
		}

		$query = $request->get_param( 'q' );
		if ( ! $query ) {
			return new \WP_Error( 'invalid_request', 'Missing Query Parameter', array( 'status' => 400 ) );
		}

		return true;
	}

	/**
	 * Check for errors and convert to Micropub errors.
	 *
	 * @param mixed $result Result to check.
	 * @return mixed
	 */
	protected function check_error( $result ) {
		if ( ! $result ) {
			return new Error( 'invalid_request', $result, 400 );
		} elseif ( \is_wp_error( $result ) ) {
			return \micropub_wp_error( $result );
		}
		return $result;
	}

	/**
	 * Convert WP_Error responses to Micropub errors.
	 *
	 * @param mixed            $response Response object.
	 * @param mixed            $handler  Handler.
	 * @param \WP_REST_Request $request  Request object.
	 * @return mixed
	 */
	public function return_micropub_error( $response, $handler, $request ) {
		$route = '/' . $this->namespace . '/' . $this->rest_base;
		if ( $route !== $request->get_route() ) {
			return $response;
		}
		if ( \is_wp_error( $response ) ) {
			return \micropub_wp_error( $response );
		}
		return $response;
	}

	/**
	 * Log an error message.
	 *
	 * @param mixed  $message Error message.
	 * @param string $name    Log prefix.
	 * @return bool
	 */
	protected function log_error( $message, $name = 'Micropub' ) {
		if ( empty( $message ) || \defined( 'DIR_TESTDATA' ) ) {
			return false;
		}
		if ( is_array( $message ) || is_object( $message ) ) {
			$message = \wp_json_encode( $message );
		}

		return \error_log( sprintf( '%1$s: %2$s', $name, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
