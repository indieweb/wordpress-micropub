<?php

add_action( 'plugins_loaded', array( 'Micropub_Media', 'init' ) );

/**
 * Micropub Media Class
 */
class Micropub_Media extends Micropub_Base {
	/**
	 * Initialize the plugin.
	 */
	public static function init() {
		// register endpoint
		add_action( 'rest_api_init', array( static::class, 'register_route' ) );

		// endpoint discovery
		add_action( 'wp_head', array( static::class, 'html_header' ), 99 );
		add_action( 'send_headers', array( static::class, 'http_header' ) );
		add_filter( 'host_meta', array( static::class, 'jrd_links' ) );
		add_filter( 'webfinger_user_data', array( static::class, 'jrd_links' ) );
	}

	public static function get_rel() {
		return 'micropub_media';
	}

	public static function get_route( $slash = false ) {
		$return = static::get_namespace() . '/media';
		return $slash ? '/' . $return : $return;
	}

	public static function register_route() {
		register_rest_route(
			MICROPUB_NAMESPACE,
			'/media',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( static::class, 'post_handler' ),
					'permission_callback' => array( static::class, 'check_create_permissions' ),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( static::class, 'query_handler' ),
					'permission_callback' => array( static::class, 'check_query_permissions' ),
				),
			)
		);
	}

	public static function check_create_permissions( $request ) {
		$auth = self::load_auth();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			$error = new WP_Micropub_Error( 'insufficient_scope', 'You do not have permission to create or upload media', 403 );
			return $error->to_wp_error();
		}
		return true;
	}

	// Based on WP_REST_Attachments_Controller function of the same name
	// TODO: Hook main endpoint functionality into and extend to use this class

	public static function upload_from_file( $files, $name = null, $headers = array() ) {

		if ( empty( $files ) ) {
			return new WP_Micropub_Error( 'invalid_request', 'No data supplied', 400 );
		}

		// Pass off to WP to handle the actual upload.
		$overrides = array(
			'test_form' => false,
		);

		// Verify hash, if given.
		if ( ! empty( $headers['content_md5'] ) ) {
			$content_md5 = array_shift( $headers['content_md5'] );
			$expected    = trim( $content_md5 );
			$actual      = md5_file( $files['file']['tmp_name'] );

			if ( $expected !== $actual ) {
				return new WP_Micropub_Error( 'invalid_request', 'Content hash did not match expected.', 412 );
			}
		}

		// Bypasses is_uploaded_file() when running unit tests.
		if ( defined( 'DIR_TESTDATA' ) && DIR_TESTDATA ) {
			$overrides['action'] = 'wp_handle_mock_upload';
		}

		/** Include admin functions to get access to wp_handle_upload() */
		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( $name && isset( $files[ $name ] ) && is_array( $files[ $name ] ) ) {
			$files = $files[ $name ];
		}

		foreach ( $files as $key => $value ) {
			if ( is_array( $value ) ) {
				$files[ $key ] = array_shift( $value );
			}
		}

		$file = wp_handle_upload( $files, $overrides );

		if ( isset( $file['error'] ) ) {
			error_log( wp_json_encode( $file['error'] ) );
			return new WP_Micropub_Error( 'invalid_request', $file['error'], 500, $files );
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
			'name'     => basename( wp_parse_url( $url, PHP_URL_PATH ) ),
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

	protected static function insert_attachment( $file, $post_id = 0, $title = null ) {
		$args = array(
			'post_mime_type' => $file['type'],
			'guid'           => $file['url'],
			'post_parent'    => $post_id,
			'meta_input'     => array(
				'_micropub_upload' => 1,
			),
		);

		// Include image functions to get access to wp_read_image_metadata
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Use image exif/iptc data for title and caption defaults if possible.
		// This is copied from the REST API Attachment upload controller code
		// FIXME: It probably should work for audio and video as well but as Core does not do that it is fine for now
		$image_meta = wp_read_image_metadata( $file['file'] );

		if ( $image_meta ) {
			if ( trim( $image_meta['title'] ) && ! is_numeric( sanitize_title( $image_meta['title'] ) ) ) {
				$args['post_title'] = $image_meta['title'];
			}
			if ( trim( $image_meta['caption'] ) ) {
				$args['post_excerpt'] = $image_meta['caption'];
			}
		}
		if ( empty( $args['post_title'] ) ) {
			$args['post_title'] = preg_replace( '/\.[^.]+$/', '', wp_basename( $file['file'] ) );
		}

		$id = wp_insert_attachment( $args, $file['file'], 0, true );

		if ( is_wp_error( $id ) ) {
			if ( 'db_update_error' === $id->get_error_code() ) {
				return new WP_Micropub_Error( 'invalid_request', 'Database Error On Upload', 500 );
			} else {
				return new WP_Micropub_Error( 'invalid_request', $id->get_error_message(), 400 );
			}
		}

		// Set Client Application Taxonomy if available.
		if ( $id && array_key_exists( 'client_uid', static::$micropub_auth_response ) ) {
			wp_set_object_terms( $id, array( static::$micropub_auth_response['client_uid'] ), 'indieauth_client' );
		}

		// Include admin functions to get access to wp_generate_attachment_metadata(). These functions are included here
		// as these functions are not normally loaded externally as is the practice in similar areas of WordPress.
		require_once ABSPATH . 'wp-admin/includes/admin.php';

		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file['file'] ) );

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
		$published = micropub_get_post_datetime( $attachment_id );
		$metadata  = wp_get_attachment_metadata( $attachment_id );

		$data = array(
			'url'       => wp_get_attachment_image_url( $attachment_id, 'full' ),
			'published' => $published->format( DATE_W3C ),
			'mime_type' => get_post_mime_type( $attachment_id ),
		);

		if ( array_key_exists( 'width', $metadata ) ) {
			$data['width'] = $metadata['width'];
		}

		if ( array_key_exists( 'height', $metadata ) ) {
			$data['height'] = $metadata['height'];
		}

		$created = null;
		// Created is added by the Simple Location plugin and includes the full timezone if it can find it.
		if ( array_key_exists( 'created', $metadata ) ) {
			$created = new DateTime( $metadata['created'] );
			/** created_timestamp is the default created timestamp in all WordPress installations. It has no timezone offset so it is often output incorrectly.
			 * See https://core.trac.wordpress.org/ticket/49413
			 **/
		} elseif ( array_key_exists( 'created_timestamp', $metadata ) && 0 !== $metadata['created_timestamp'] ) {
			$created = new DateTime();
			$created->setTimestamp( $metadata['created_timestamp'] );
			$created->setTimezone( wp_timezone() );
		}
		if ( $created ) {
			$data['created'] = $created->format( DATE_W3C );
		}

		// Only video or audio would have album art. Video uses the term poster, audio has no term, but using for both in the interest of simplicity.
		if ( has_post_thumbnail( $attachment_id ) ) {
			$data['poster'] = wp_get_attachment_url( get_post_thumbnail_id( $attachment_id ) );
		}

		if ( wp_attachment_is( 'image', $attachment_id ) ) {
			// Return the thumbnail size present as a default.
			$data['thumbnail'] = wp_get_attachment_image_url( $attachment_id );
		}

		return array_filter( $data );
	}

	// Handles requests to the Media Endpoint
	public static function post_handler( $request ) {
		$params = $request->get_params();
		if ( array_key_exists( 'action', $params ) ) {
			return self::action_handler( $params );
		}

		return self::upload_handler( $request );
	}

	public static function action_handler( $params ) {
		switch ( $params['action'] ) {
			case 'delete':
				if ( ! array_key_exists( 'url', $params ) ) {
					return new WP_Micropub_Error( 'invalid_request', 'Missing Parameter: url', 400 );
				}
				$url           = esc_url_raw( $params['url'] );
				$attachment_id = attachment_url_to_postid( $url );
				if ( $attachment_id ) {
					if ( ! current_user_can( 'delete_post', $attachment_id ) ) {
						$error = new WP_Micropub_Error( 'insufficient_scope', 'You do not have permission to delete media', 403 );
						return $error->to_wp_error();
					}
					$response = wp_delete_attachment( $attachment_id, true );
					if ( $response ) {
						return new WP_REST_Response(
							$response,
							200
						);
					}
				}
				return new WP_Micropub_Error( 'invalid_request', 'Unable to Delete File', 400 );
			default:
				return new WP_Micropub_Error( 'invalid_request', 'No Action Handler for This Action', 400 );
		}
	}

	public static function upload_handler( $request ) {
		// Get the file via $_FILES
		$files   = $request->get_file_params();
		$headers = $request->get_headers();
		if ( empty( $files ) ) {
			return new WP_Micropub_Error( 'invalid_request', 'No Files Attached', 400 );
		} else {
			$file = self::upload_from_file( $files, 'file', $headers );
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
		$params = $request->get_query_params();
		if ( array_key_exists( 'q', $params ) ) {
			switch ( sanitize_key( $params['q'] ) ) {
				case 'config':
					return new WP_REST_Response(
						array(
							'q'          => array(
								'last',
								'source',
							),
							'properties' => array(
								'url',
								'limit',
								'offset',
								'mime_type',
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
							'post_parent'    => 0,
							'order'          => 'DESC',
							'date_query'     => array(
								'after' => '1 hour ago',
							),
						)
					);
					if ( is_array( $attachments ) ) {
						foreach ( $attachments as $attachment ) {
							$datetime = micropub_get_post_datetime( $attachment );
							if ( wp_attachment_is( 'image', $attachment ) ) {
								return self::return_media_data( $attachment );
							}
						}
					}
					return array();
				case 'source':
					if ( array_key_exists( 'url', $params ) ) {
						$attachment_id = attachment_url_to_postid( esc_url( $params['url'] ) );
						if ( ! $attachment_id ) {
							return new WP_Micropub_Error( 'invalid_request', sprintf( 'not found: %1$s', $params['url'] ), 400 );
						}
						$resp = self::return_media_data( $attachment_id );
					} else {
						$numberposts = (int) mp_get( $params, 'limit', 10 );
						$args        = array(
							'posts_per_page' => $numberposts,
							'post_type'      => 'attachment',
							'post_parent'    => 0,
							'fields'         => 'ids',
							'order'          => 'DESC',
						);
						if ( array_key_exists( 'offset', $params ) ) {
							$args['offset'] = (int) mp_get( $params, 'offset' );
						}

						if ( array_key_exists( 'mime_type', $params ) ) {
							$args['post_mime_type'] = sanitize_mime_type( $params['mime_type'] );
						}
						$attachments = get_posts( $args );
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
