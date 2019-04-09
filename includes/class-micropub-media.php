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

				// endpoint discovery
				add_action( 'wp_head', array( $cls, 'micropub_media_html_header' ), 99 );
				add_action( 'send_headers', array( $cls, 'micropub_media_http_header' ) );
				add_filter( 'host_meta', array( $cls, 'micropub_media_jrd_links' ) );
				add_filter( 'webfinger_user_data', array( $cls, 'micropub_media_jrd_links' ) );

	}

	public static function get_micropub_media_endpoint() {
			return rest_url( MICROPUB_NAMESPACE . '/media' );
	}

		/**
		 * The micropub autodicovery meta tags
		 */
	public static function micropub_media_html_header() {
			// phpcs:ignore
			printf( '<link rel="micropub_media" href="%s" />' . PHP_EOL, static::get_micropub_media_endpoint() );
	}

		/**
		 * The micropub autodicovery http-header
		 */
	public static function micropub_media_http_header() {
			Micropub_Endpoint::header( 'Link', '<' . static::get_micropub_media_endpoint() . '>; rel="micropub_media"' );
	}

		/**
		 * Generates webfinger/host-meta links
		 */
	public static function micropub_media_jrd_links( $array ) {
			$array['links'][] = array(
				'rel'  => 'micropub_media',
				'href' => static::get_micropub_media_endpoint(),
			);
			return $array;
	}


	public static function register_route() {
		$cls = get_called_class();
		register_rest_route(
			MICROPUB_NAMESPACE,
			'/media',
			array(
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $cls, 'upload_handler' ),
				),
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $cls, 'query_handler' ),
				),
			)
		);
	}

	// Based on WP_REST_Attachments_Controller function of the same name
	// TODO: Hook main endpoint functionality into and extend to use this class

	public static function upload_from_file( $files, $name = null ) {

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

		if ( $name && isset( $files[ $name ] ) && is_array( $files[ $name ] ) ) {
			$files = $files[ $name ];
		}

		$file = wp_handle_upload( $files, $overrides );

		if ( isset( $file['error'] ) ) {
			return new WP_Micropub_Error( 'invalid_request', $file['error'], 500 );
		}

		return $file;
	}

	// Takes an array of files and converts it for use with wp_handle_upload
	public static function file_array( $files ) {
		if ( ! is_array( $files['name'] ) ) {
			return $files;
		}
		$count    = count( $files['name'] );
		$newfiles = array();
		for ( $i = 0; $i < $count; ++$i ) {
			$newfiles[] = array(
				'name'     => $files['name'][ $i ],
				'tmp_name' => $files['tmp_name'][ $i ],
				'size'     => $files['size'][ $i ],
			);
		}
		return $newfiles;
	}

	public static function upload_from_url( $url ) {
		if ( ! wp_http_validate_url( $url ) ) {
			return new WP_Micropub_Error( 'invalid_request', 'Invalid Media URL', 400 );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = download_url( $url );
		if ( is_wp_error( $tmp ) ) {
			return new WP_Micropub_Error( 'invalid_request', $tmp->get_message(), 400 );
		}
		$file_array = array(
			'name'     => basename( $url ),
			'tmp_name' => $tmp,
			'error'    => 0,
			'size'     => filesize( $tmp ),
		);
		$overrides  = array(
			/*
			 * Tells WordPress to not look for the POST form fields that would
			 * normally be present, default is true, we downloaded the file from
			 * a remote server, so there will be no form fields.
			 */
				'test_form' => false,

			// Setting this to false lets WordPress allow empty files, not recommended.
			'test_size'     => true,

			// A properly uploaded file will pass this test. There should be no reason to override this one.
			'test_upload'   => true,
		);
		// Move the temporary file into the uploads directory.
		$file = wp_handle_sideload( $file_array, $overrides );
		if ( isset( $file['error'] ) ) {
			return new WP_Micropub_Error( 'invalid_request', $file['error'], 500 );
		}
		return $file;
	}

	/**
	 * Checks if a given request has access to create an attachment.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_Micropub_Error|true Boolean true if the attachment may be created, or a WP_Micropub_Error if not.
	 */
	protected static function permissions_check( $request ) {
		static::$scopes                 = apply_filters( 'indieauth_scopes', static::$scopes );
		static::$micropub_auth_response = apply_filters( 'indieauth_response', static::$micropub_auth_response );
		if ( 'POST' === $request->get_method() ) {
			if ( ! current_user_can( 'upload_files' ) ) {
				return new WP_Micropub_Error( 'forbidden', 'User is not permitted to upload files', 403 );
			}
		}
		$intersect = array_intersect( array( 'create', 'media' ), static::$scopes );
		if ( empty( $intersect ) ) {
			return new WP_Micropub_Error( 'insufficient_scope', 'You do not have permission to create or upload media', 401 );
		}

		return true;
	}

	protected static function insert_attachment( $file, $post_id = 0, $title = null ) {
		if ( ! $title ) {
			$title = preg_replace( '/\.[^.]+$/', '', basename( $file['file'] ) );
		}
		$args = array(
			'post_mime_type' => $file['type'],
			'guid'           => $file['url'],
			'post_title'     => $title,
			'post_parent'    => $post_id,
		);

		$id = wp_insert_attachment( $args, $file['file'], 0, true );

		if ( is_wp_error( $id ) ) {
			if ( 'db_update_error' === $id->get_error_code() ) {
				return new WP_Micropub_Error( 'invalid_request', 'Database Error On Upload', 500 );
			} else {
				return new WP_Micropub_Error( 'invalid_request', $id->get_error_message(), 400 );
			}
		}

		// Include admin functions to get access to wp_generate_attachment_metadata(). These functions are included here
		// as these functions are not normally loaded externally as is the practice in similar areas of WordPress.
		require_once ABSPATH . 'wp-admin/includes/admin.php';

		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file['file'] ) );

		// Add metadata tag to micropub uploaded so they can be queried
		update_post_meta( $id, '_micropub_upload', 1 );

		return $id;
	}

	public static function attach_media( $attachment_id, $post_id ) {
		$post = array(
			'ID'          => $attachment_id,
			'post_parent' => $post_id,
		);
		return wp_update_post( $post, true );
	}

	/*
	 * Returns information about an attachment
	 */
	private static function return_media_data( $attachment_id ) {
		$data        = wp_get_attachment_metadata( $attachment_id );
		$data['url'] = wp_get_attachment_image_url( $attachment_id, 'full' );
		return $data;
	}

	// Handles requests to the Media Endpoint
	public static function upload_handler( $request ) {

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
		$title = $request->get_param( 'name' );
		$id    = self::insert_attachment( $file, 0, $title );

		$url  = wp_get_attachment_url( $id );
		$data = self::return_media_data( $id );
		add_post_meta( $id, 'micropub_auth_response', static::$micropub_auth_response );
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

	// Responds to queries to the media endpoint
	public static function query_handler( $request ) {
		$permission = static::permissions_check( $request );
		$params     = $request->get_query_params();
		if ( array_key_exists( 'q', $params ) ) {
			switch ( $params['q'] ) {
				case 'config':
					return new WP_REST_Response(
						array(
							'q' => array(
								'last',
								'source',
							),
						),
						200
					);
				case 'last':
					$attachments = get_posts(
						array(
							'post_type'      => 'attachment',
							'fields'         => 'ids',
							'posts_per_page' => 10,
							'order'          => 'DESC',
						)
					);
					if ( is_array( $attachments ) ) {
						foreach ( $attachments as $attachment ) {
							if ( wp_attachment_is( 'image', $attachment ) ) {
								return array(
									'url' => wp_get_attachment_image_url( $attachment, 'full' ),
								);
							}
						}
					}
					return array();
				case 'source':
					if ( array_key_exists( 'url', $params ) ) {
						$attachment_id = url_to_postid( $params['url'] );
						if ( ! $attachment_id ) {
							return new WP_Micropub_Error( 'invalid_request', sprintf( 'not found: %1$s', $params['url'] ), 400 );
						}
						$resp = self::return_media_data( $attachment_id );
					} else {
						$numberposts = mp_get( $params, 'limit', 10 );
						$attachments = get_posts(
							array(
								'posts_per_page' => $numberposts,
								'post_type'      => 'attachment',
								'fields'         => 'ids',
								'order'          => 'DESC',
							)
						);
						$resp        = array();
						foreach ( $attachments as $attachment ) {
							$resp[] = self::return_media_data( $attachment );
						}
						$resp = array( 'items' => $resp );
					}
					return $resp;
			}
		}

		if ( is_micropub_error( $permission ) ) {
			return $permission;
		}
		return new WP_Micropub_Error( 'invalid_request', 'unknown query', 400, $request->get_query_params() );
	}

	public static function media_sideload_url( $url, $post_id = 0, $title = null ) {
		// Check to see if URL is already in the media library
		$id = attachment_url_to_postid( $url );
		if ( $id ) {
			// Attach media to post
			wp_update_post(
				array(
					'ID'          => $id,
					'post_parent' => $post_id,
				)
			);
			return $id;
		}

		$file = self::upload_from_url( $url );
		if ( is_micropub_error( $file ) ) {
			return $file;
		}

		return self::insert_attachment( $file, $post_id, $title );
	}

	public static function media_handle_upload( $file, $post_id = 0 ) {
		$file = self::upload_from_file( $file );
		if ( is_micropub_error( $file ) ) {
			return $file;
		}

		return self::insert_attachment( $file, $post_id );
	}




}
