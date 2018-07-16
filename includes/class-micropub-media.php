<?php

add_action( 'plugins_loaded', array( 'Micropub_Media', 'init' ) );

/**
 * Micropub Media Class
 */
class Micropub_Media {

	protected static $scopes                 = array();
	protected static $micropub_auth_response = array();

	/**
	 * Initialize the plugin.
	 */
	public static function init() {
		$cls = get_called_class();

		// register endpoint
		add_action( 'rest_api_init', array( $cls, 'register_route' ) );

	}

	public static function register_route() {
		$cls = get_called_class();
		register_rest_route(
			'micropub/1.0', '/media', array(
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $cls, 'upload_handler' ),
				),
			)
		);
	}

	// Based on WP_REST_Attachments_Controller function of the same name
	// TODO: Hook main endpoint functionality into and extend to use this class

	public static function upload_from_file( $files, $name ) {

		// Pass off to WP to handle the actual upload.
		$overrides = array(
			'test_form' => false,
		);

		// Bypasses is_uploaded_file() when running unit tests.
		if ( defined( 'DIR_TESTDATA' ) && DIR_TESTDATA ) {
			$overrides['action'] = 'wp_handle_mock_upload';
		}

		/** Include admin functions to get access to wp_handle_upload() */
		require_once ABSPATH . 'wp-admin/includes/admin.php';

		$file = wp_handle_upload( $files[ $name ], $overrides );

		if ( isset( $file['error'] ) ) {
			return new WP_Micropub_Error( 'invalid_request', $file['error'], 500 );
		}

		return $file;
	}

	/**
	* Check scope
	*
	* @param array $scope
	*
	* @return boolean
	**/
	protected static function check_scopes( $scopes ) {
	}


	/**
	 * Checks if a given request has access to create an attachment.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Micropub_Error|true Boolean true if the attachment may be created, or a WP_Micropub_Error if not.
	 */
	protected static function permissions_check( $request ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Micropub_Error( 'forbidden', 'User is not permitted to upload files', 403 );
		}
		$intersect = array_intersect( array( 'create', 'media' ), static::$scopes );
		if ( empty( $intersect ) ) {
			return new WP_Micropub_Error( 'insufficient_scope', 'Token Does Not Meet Requirements for Upload', 401 );
		}

		return true;
	}

	// Handles requests to the Media Endpoint
	public static function upload_handler( $request ) {
		static::$scopes                 = apply_filters( 'indieauth_scopes', static::$scopes );
		static::$micropub_auth_response = apply_filters( 'indieauth_response', static::$micropub_auth_response );

		$permission = static::permissions_check( $request );
		if ( is_micropub_error( $permission ) ) {
			return $permission;
		}

		// Get the file via $_FILES
		$files   = $request->get_file_params();
		$headers = $request->get_headers();
		if ( empty( $files ) ) {
			return new WP_Micropub_Error( 'invalid_request', 'No Files Attached', 400 );
		} else {
			$file = self::upload_from_file( $files, 'file' );
		}

		if ( is_micropub_error( $file ) ) {
			return $file;
		}

		$args = array(
			'post_mime_type' => $file['type'],
			'guid'           => $file['url'],
			'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $file['file'] ) ),
		);

		$id = wp_insert_attachment( $args, $file['file'], 0, true );

		if ( is_wp_error( $id ) ) {
			if ( 'db_update_error' === $id->get_error_code() ) {
				return new WP_Micropub_Error( 'invalid_request', 'Database Error On Upload', 500 );
			} else {
				return new WP_Micropub_Error( 'invalid_request', $id->get_error_message(), 400 );
			}
		}

		$attachment = get_post( $id );

		// Include admin functions to get access to wp_generate_attachment_metadata().
		require_once ABSPATH . 'wp-admin/includes/admin.php';

		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file['file'] ) );
		$url         = wp_get_attachment_url( $id );
		$data        = wp_get_attachment_metadata( $id );
		$data['url'] = $url;
		$data['id']  = $id;
		$response    = new WP_REST_Response(
			$data,
			201,
			array(
				'Location' => $url,
			)
		);
		return $response;
	}

}
