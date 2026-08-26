<?php
/**
 * Micropub Media Controller.
 *
 * @package Micropub
 */

namespace Micropub\Rest;

use Micropub\Error;

/**
 * Micropub Media Controller.
 *
 * Handles Micropub media endpoint for file/image uploads.
 */
class Media_Controller extends \WP_REST_Controller {
	use Micropub;

	/**
	 * The namespace of this controller's route.
	 *
	 * @var string
	 */
	protected $namespace = MICROPUB_NAMESPACE;

	/**
	 * The base of this controller's route.
	 *
	 * @var string
	 */
	protected $rest_base = 'media';

	/**
	 * Register the routes for the controller.
	 */
	public function register_routes() {
		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'check_query_permissions' ),
				),
			)
		);
	}

	/**
	 * Check create permissions.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return true|\WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		$auth = $this->load_auth();
		if ( \is_wp_error( $auth ) ) {
			return $auth;
		}

		if ( ! \current_user_can( 'upload_files' ) ) {
			$error = new Error( 'insufficient_scope', 'You do not have permission to create or upload media', 403 );
			return $error->to_wp_error();
		}

		return true;
	}

	/**
	 * Handle POST requests.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|Error
	 */
	public function create_item( $request ) {
		$params = $request->get_params();

		if ( array_key_exists( 'action', $params ) ) {
			return $this->handle_action( $params );
		}

		return $this->handle_upload( $request );
	}

	/**
	 * Handle GET requests (queries).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|Error|array
	 */
	public function get_items( $request ) {
		$params = $request->get_query_params();

		if ( array_key_exists( 'q', $params ) ) {
			switch ( \sanitize_key( $params['q'] ) ) {
				case 'config':
					return new \WP_REST_Response(
						array(
							'q'          => array( 'last', 'source' ),
							'properties' => array( 'url', 'limit', 'offset', 'mime_type' ),
						),
						200
					);

				case 'last':
					$attachments = \get_posts(
						array(
							'post_type'      => 'attachment',
							'fields'         => 'ids',
							'posts_per_page' => 10,
							'post_parent'    => 0,
							'order'          => 'DESC',
							'date_query'     => array( 'after' => '1 hour ago' ),
						)
					);

					if ( is_array( $attachments ) ) {
						foreach ( $attachments as $attachment ) {
							if ( \wp_attachment_is( 'image', $attachment ) ) {
								return $this->return_media_data( $attachment );
							}
						}
					}
					return array();

				case 'source':
					return $this->handle_source_query( $params );
			}
		}

		return new Error( 'invalid_request', 'unknown query', 400, $request->get_query_params() );
	}

	/**
	 * Handle source query.
	 *
	 * @param array $params Query parameters.
	 * @return array|Error
	 */
	protected function handle_source_query( $params ) {
		if ( array_key_exists( 'url', $params ) ) {
			$attachment_id = \attachment_url_to_postid( \esc_url( $params['url'] ) );
			if ( ! $attachment_id ) {
				return new Error( 'invalid_request', sprintf( 'not found: %1$s', $params['url'] ), 400 );
			}
			return $this->return_media_data( $attachment_id );
		}

		$numberposts = (int) \mp_get( $params, 'limit', 10 );
		$args        = array(
			'posts_per_page' => $numberposts,
			'post_type'      => 'attachment',
			'post_parent'    => 0,
			'fields'         => 'ids',
			'order'          => 'DESC',
		);

		if ( array_key_exists( 'offset', $params ) ) {
			$args['offset'] = (int) \mp_get( $params, 'offset' );
		}

		if ( array_key_exists( 'mime_type', $params ) ) {
			$args['post_mime_type'] = \sanitize_mime_type( $params['mime_type'] );
		}

		$attachments = \get_posts( $args );
		$resp        = array();

		foreach ( $attachments as $attachment ) {
			$resp[] = $this->return_media_data( $attachment );
		}

		return array( 'items' => $resp );
	}

	/**
	 * Handle action requests.
	 *
	 * @param array $params Request parameters.
	 * @return \WP_REST_Response|Error|\WP_Error
	 */
	protected function handle_action( $params ) {
		switch ( $params['action'] ) {
			case 'delete':
				if ( ! array_key_exists( 'url', $params ) ) {
					return new Error( 'invalid_request', 'Missing Parameter: url', 400 );
				}

				$url           = \esc_url_raw( $params['url'] );
				$attachment_id = \attachment_url_to_postid( $url );

				if ( $attachment_id ) {
					if ( ! \current_user_can( 'delete_post', $attachment_id ) ) {
						$error = new Error( 'insufficient_scope', 'You do not have permission to delete media', 403 );
						return $error->to_wp_error();
					}

					$response = \wp_delete_attachment( $attachment_id, true );
					if ( $response ) {
						return new \WP_REST_Response( $response, 200 );
					}
				}

				return new Error( 'invalid_request', 'Unable to Delete File', 400 );

			default:
				return new Error( 'invalid_request', 'No Action Handler for This Action', 400 );
		}
	}

	/**
	 * Handle upload requests.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|Error
	 */
	protected function handle_upload( $request ) {
		$files   = $request->get_file_params();
		$headers = $request->get_headers();

		if ( empty( $files ) ) {
			return new Error( 'invalid_request', 'No Files Attached', 400 );
		}

		$file = $this->upload_from_file( $files, 'file', $headers );

		if ( \is_micropub_error( $file ) ) {
			return $file;
		}

		$title = $request->get_param( 'name' );
		$id    = $this->insert_attachment( $file, 0, $title );

		$url  = \wp_get_attachment_url( $id );
		$data = $this->return_media_data( $id );

		\add_post_meta( $id, 'micropub_auth_response', $this->micropub_auth_response );

		$data['url'] = $url;
		$data['id']  = $id;

		return new \WP_REST_Response(
			$data,
			201,
			array( 'Location' => $url )
		);
	}

	/**
	 * Upload from file.
	 *
	 * @param array  $files   Files array.
	 * @param string $name    File name key.
	 * @param array  $headers Request headers.
	 * @return array|Error
	 */
	public function upload_from_file( $files, $name = null, $headers = array() ) {
		if ( empty( $files ) ) {
			return new Error( 'invalid_request', 'No data supplied', 400 );
		}

		$overrides = array( 'test_form' => false );

		// Verify hash, if given.
		if ( ! empty( $headers['content_md5'] ) ) {
			$content_md5 = array_shift( $headers['content_md5'] );
			$expected    = trim( $content_md5 );
			$actual      = md5_file( $files['file']['tmp_name'] );

			if ( $expected !== $actual ) {
				return new Error( 'invalid_request', 'Content hash did not match expected.', 412 );
			}
		}

		// Bypasses is_uploaded_file() when running unit tests.
		if ( \defined( 'DIR_TESTDATA' ) && DIR_TESTDATA ) {
			$overrides['action'] = 'wp_handle_mock_upload';
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( $name && isset( $files[ $name ] ) && is_array( $files[ $name ] ) ) {
			$files = $files[ $name ];
		}

		foreach ( $files as $key => $value ) {
			if ( is_array( $value ) ) {
				$files[ $key ] = array_shift( $value );
			}
		}

		$file = \wp_handle_upload( $files, $overrides );

		if ( isset( $file['error'] ) ) {
			return new Error( 'invalid_request', $file['error'], 500, $files );
		}

		return $file;
	}

	/**
	 * Takes an array of files and converts it for use with wp_handle_upload.
	 *
	 * @param array $files Files array.
	 * @return array
	 */
	public function file_array( $files ) {
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

	/**
	 * Upload from URL.
	 *
	 * @param string $url URL to upload from.
	 * @return array|Error
	 */
	public function upload_from_url( $url ) {
		if ( ! \wp_http_validate_url( $url ) ) {
			return new Error( 'invalid_request', 'Invalid Media URL', 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$tmp = \download_url( $url );
		if ( \is_wp_error( $tmp ) ) {
			return new Error( 'invalid_request', $tmp->get_message(), 400 );
		}

		$file_array = array(
			'name'     => basename( \wp_parse_url( $url, PHP_URL_PATH ) ),
			'tmp_name' => $tmp,
			'error'    => 0,
			'size'     => filesize( $tmp ),
		);

		$overrides = array(
			'test_form'   => false,
			'test_size'   => true,
			'test_upload' => true,
		);

		$file = \wp_handle_sideload( $file_array, $overrides );

		if ( isset( $file['error'] ) ) {
			return new Error( 'invalid_request', $file['error'], 500 );
		}

		return $file;
	}

	/**
	 * Insert attachment.
	 *
	 * @param array  $file    File data.
	 * @param int    $post_id Post ID.
	 * @param string $title   Attachment title.
	 * @param string $alt     Alternative text describing the media.
	 * @return int|Error
	 */
	protected function insert_attachment( $file, $post_id = 0, $title = null, $alt = null ) {
		$args = array(
			'post_mime_type' => $file['type'],
			'guid'           => $file['url'],
			'post_parent'    => $post_id,
			'meta_input'     => array( '_micropub_upload' => 1 ),
		);

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$image_meta = \wp_read_image_metadata( $file['file'] );

		if ( $image_meta ) {
			if ( trim( $image_meta['title'] ) && ! is_numeric( \sanitize_title( $image_meta['title'] ) ) ) {
				$args['post_title'] = $image_meta['title'];
			}
			if ( trim( $image_meta['caption'] ) ) {
				$args['post_excerpt'] = $image_meta['caption'];
			}
		}

		if ( $title ) {
			$args['post_title'] = $title;
		}

		if ( empty( $args['post_title'] ) ) {
			$args['post_title'] = preg_replace( '/\.[^.]+$/', '', \wp_basename( $file['file'] ) );
		}

		$id = \wp_insert_attachment( $args, $file['file'], 0, true );

		if ( \is_wp_error( $id ) ) {
			if ( 'db_update_error' === $id->get_error_code() ) {
				return new Error( 'invalid_request', 'Database Error On Upload', 500 );
			} else {
				return new Error( 'invalid_request', $id->get_error_message(), 400 );
			}
		}

		if ( $alt ) {
			\update_post_meta( $id, '_wp_attachment_image_alt', \wp_slash( \wp_strip_all_tags( $alt ) ) );
		}

		// Set Client Application Taxonomy if available.
		if ( $id && array_key_exists( 'client_uid', $this->micropub_auth_response ) ) {
			\wp_set_object_terms( $id, array( $this->micropub_auth_response['client_uid'] ), 'indieauth_client' );
		}

		require_once ABSPATH . 'wp-admin/includes/admin.php';

		\wp_update_attachment_metadata( $id, \wp_generate_attachment_metadata( $id, $file['file'] ) );

		return $id;
	}

	/**
	 * Returns information about an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	protected function return_media_data( $attachment_id ) {
		$published = \micropub_get_post_datetime( $attachment_id );
		$metadata  = \wp_get_attachment_metadata( $attachment_id );

		$data = array(
			'url'       => \wp_get_attachment_image_url( $attachment_id, 'full' ),
			'published' => $published->format( DATE_W3C ),
			'mime_type' => \get_post_mime_type( $attachment_id ),
		);

		if ( array_key_exists( 'width', $metadata ) ) {
			$data['width'] = $metadata['width'];
		}

		if ( array_key_exists( 'height', $metadata ) ) {
			$data['height'] = $metadata['height'];
		}

		$created = null;
		if ( array_key_exists( 'created', $metadata ) ) {
			$created = new \DateTime( $metadata['created'] );
		} elseif ( array_key_exists( 'created_timestamp', $metadata ) && 0 !== $metadata['created_timestamp'] ) {
			$created = new \DateTime();
			$created->setTimestamp( $metadata['created_timestamp'] );
			$created->setTimezone( \wp_timezone() );
		}

		if ( $created ) {
			$data['created'] = $created->format( DATE_W3C );
		}

		if ( \has_post_thumbnail( $attachment_id ) ) {
			$data['poster'] = \wp_get_attachment_url( \get_post_thumbnail_id( $attachment_id ) );
		}

		if ( \wp_attachment_is( 'image', $attachment_id ) ) {
			$data['thumbnail'] = \wp_get_attachment_image_url( $attachment_id );
		}

		return array_filter( $data );
	}

	/**
	 * Attach media to a post.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $post_id       Post ID.
	 * @return int|\WP_Error
	 */
	public function attach_media( $attachment_id, $post_id ) {
		return \wp_update_post(
			array(
				'ID'          => $attachment_id,
				'post_parent' => $post_id,
			),
			true
		);
	}

	/**
	 * Sideload media from URL.
	 *
	 * @param string $url     URL to sideload from.
	 * @param int    $post_id Post ID to attach to.
	 * @param string $alt     Alternative text describing the media.
	 * @return int|Error
	 */
	public function media_sideload_url( $url, $post_id = 0, $alt = null ) {
		$id = \attachment_url_to_postid( $url );
		if ( $id ) {
			\wp_update_post(
				array(
					'ID'          => $id,
					'post_parent' => $post_id,
				)
			);
			return $id;
		}

		$file = $this->upload_from_url( $url );
		if ( \is_micropub_error( $file ) ) {
			return $file;
		}

		return $this->insert_attachment( $file, $post_id, null, $alt );
	}

	/**
	 * Handle media upload.
	 *
	 * @param array $file    File data.
	 * @param int   $post_id Post ID to attach to.
	 * @return int|Error
	 */
	public function media_handle_upload( $file, $post_id = 0 ) {
		$file = $this->upload_from_file( $file );
		if ( \is_micropub_error( $file ) ) {
			return $file;
		}

		return $this->insert_attachment( $file, $post_id );
	}
}
