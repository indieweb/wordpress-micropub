<?php
/**
 * Micropub Endpoint Controller.
 *
 * @package Micropub
 */

namespace Micropub\Rest;

use Micropub\Error;

/**
 * Micropub Endpoint Controller.
 *
 * Handles Micropub protocol requests (create, update, delete, query).
 */
class Endpoint_Controller extends \WP_REST_Controller {
	use Micropub;

	/**
	 * Properties whose values are media that has to be sideloaded.
	 *
	 * @var string[]
	 */
	const MEDIA_PROPERTIES = array( 'photo', 'video', 'audio', 'featured' );

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
	protected $rest_base = 'endpoint';

	/**
	 * Input data from JSON or form-encoded input.
	 *
	 * @var array
	 */
	protected $input;

	/**
	 * File array populated by load_input.
	 *
	 * @var array
	 */
	protected $files;

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

		// Add error handling filter.
		\add_filter( 'rest_request_after_callbacks', array( $this, 'return_micropub_error' ), 10, 3 );
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

		$action     = $request->get_param( 'action' );
		$action     = $action ? $action : 'create';
		$permission = $this->check_action( $action );

		if ( \is_micropub_error( $permission ) ) {
			return $permission->to_wp_error();
		}

		return $permission;
	}

	/**
	 * Handle POST requests (create, update, delete).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|Error
	 */
	public function create_item( $request ) {
		$user_id  = \get_current_user_id();
		$response = new \WP_REST_Response();
		$load     = $this->load_input( $request );

		if ( \is_micropub_error( $load ) ) {
			return $load;
		}

		$action = \mp_get( $this->input, 'action', 'create' );
		$url    = \mp_get( $this->input, 'url' );

		// Check that we support all requested syndication targets.
		$synd_supported = $this->get_syndicate_targets( $user_id );
		$uids           = array();
		foreach ( $synd_supported as $syn ) {
			$uids[] = \mp_get( $syn, 'uid' );
		}

		$properties     = \mp_get( $this->input, 'properties' );
		$synd_requested = \mp_get( $properties, 'mp-syndicate-to' );
		$unknown        = array_diff( $synd_requested, $uids );

		if ( $unknown ) {
			return new Error( 'invalid_request', sprintf( 'Unknown mp-syndicate-to targets: %1$s', implode( ', ', $unknown ) ), 400 );
		}

		// For all actions other than creation a url is required.
		if ( ! $url && 'create' !== $action ) {
			return new Error( 'invalid_request', sprintf( 'URL is Required for %1$s action', $action ), 400 );
		}

		switch ( $action ) {
			case 'create':
				$args = $this->handle_create( $user_id );
				if ( ! \is_micropub_error( $args ) ) {
					$response->set_status( 201 );
					$response->header( 'Location', \get_permalink( $args['ID'] ) );
				}
				break;
			case 'update':
				$args = $this->handle_update( $this->input );
				break;
			case 'delete':
				$post_id = \url_to_postid( $url );
				$args    = \get_post( $post_id, ARRAY_A );
				if ( ! $args ) {
					return new Error( 'invalid_request', sprintf( '%1$s not found', $url ), 400 );
				}
				$this->check_error( \wp_trash_post( $args['ID'] ) );
				break;
			case 'undelete':
				$found = false;
				foreach ( \get_posts(
					array(
						'post_status' => 'trash',
						'fields'      => 'ids',
					)
				) as $post_id ) {
					if ( \get_the_guid( $post_id ) === $url ) {
						\wp_untrash_post( $post_id );
						\wp_publish_post( $post_id );
						$found = true;
						$args  = array( 'ID' => $post_id );
					}
				}
				if ( ! $found ) {
					return new Error( 'invalid_request', sprintf( 'deleted post %1$s not found', $url ), 400 );
				}
				break;
			default:
				return new Error( 'invalid_request', sprintf( 'unknown action %1$s', $action ), 400 );
		}

		if ( \is_micropub_error( $args ) ) {
			return $args;
		}

		/**
		 * Fires after a Micropub request has been processed.
		 *
		 * @param array $input   The Micropub request input.
		 * @param array $wp_args The WordPress post arguments.
		 */
		\do_action( 'after_micropub', $this->input, $args );

		if ( ! empty( $synd_requested ) ) {
			/**
			 * Fires when syndication targets are requested for a post.
			 *
			 * @param int   $post_id      The post ID.
			 * @param array $syndicate_to Array of syndication target UIDs.
			 */
			\do_action( 'micropub_syndication', $args['ID'], $synd_requested );
		}

		$response->set_data( $args );
		return $response;
	}

	/**
	 * Handle GET requests (queries).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|Error
	 */
	public function get_items( $request ) {
		$user_id = \get_current_user_id();
		$this->load_input( $request );

		switch ( $this->input['q'] ) {
			case 'config':
				$resp = array(
					'syndicate-to'   => $this->get_syndicate_targets( $user_id, $this->input ),
					'media-endpoint' => \rest_url( $this->namespace . '/media' ),
					'visibility'     => array( 'public', 'private' ),
					'mp'             => array( 'slug', 'syndicate-to' ),
					'q'              => array( 'config', 'syndicate-to', 'category', 'source' ),
					'properties'     => array( 'location-visibility' ),
				);
				break;
			case 'syndicate-to':
				$resp = array( 'syndicate-to' => $this->get_syndicate_targets( $user_id, $this->input ) );
				break;
			case 'category':
				$resp = array_merge(
					\get_tags( array( 'fields' => 'names' ) ),
					\get_terms(
						array(
							'taxonomy' => 'category',
							'fields'   => 'names',
						)
					)
				);
				if ( array_key_exists( 'filter', $this->input ) ) {
					$resp = \mp_filter( $resp, $this->input['filter'] );
				}
				$resp = array( 'categories' => $resp );
				break;
			case 'source':
				$resp = $this->handle_source_query();
				break;
			default:
				$resp = new Error( 'invalid_request', 'unknown query', 400, $this->input );
		}

		/**
		 * Filters the Micropub query response.
		 *
		 * @param array|Error $resp  The query response.
		 * @param array       $input The Micropub request input.
		 */
		$resp = \apply_filters( 'micropub_query', $resp, $this->input );

		if ( \is_wp_error( $resp ) ) {
			return $resp;
		}

		/** This action is documented above. */
		\do_action( 'after_micropub', $this->input, null );

		return new \WP_REST_Response( $resp, 200 );
	}

	/**
	 * Handle source query.
	 *
	 * @return array|Error
	 */
	protected function handle_source_query() {
		$user_id = \get_current_user_id();

		if ( array_key_exists( 'url', $this->input ) ) {
			$post_id = \url_to_postid( $this->input['url'] );
			if ( ! $post_id ) {
				return new Error( 'invalid_request', sprintf( 'not found: %1$s', $this->input['url'] ), 400 );
			}
			return $this->query( $post_id );
		}

		$args = array(
			'posts_per_page' => \mp_get( $this->input, 'limit', 10 ),
			'fields'         => 'ids',
		);

		if ( array_key_exists( 'offset', $this->input ) ) {
			$args['offset'] = \mp_get( $this->input, 'offset' );
		}

		if ( array_key_exists( 'visibility', $this->input ) ) {
			$visibilitylist = array( array( 'private' ), array( 'public' ) );
			if ( ! in_array( $this->input['visibility'], $visibilitylist, true ) ) {
				return null;
			}
			if ( array( 'private' ) === $this->input['visibility'] ) {
				if ( \user_can( $user_id, 'read_private_posts' ) ) {
					$args['post-status'] = 'private';
				}
			}
		} elseif ( array_key_exists( 'post-status', $this->input ) ) {
			if ( 'published' === \mp_get( $this->input, 'post-status' ) ) {
				$args['post-status'] = 'publish';
			} elseif ( 'draft' === \mp_get( $this->input, 'post-status' ) ) {
				$args['post-status'] = 'draft';
			}
		}

		$posts = \get_posts( $args );
		$resp  = array();
		foreach ( $posts as $post ) {
			$resp[] = $this->query( $post );
		}

		return array( 'items' => $resp );
	}

	/**
	 * Parse the micropub request and load input.
	 *
	 * @param \WP_REST_Request $request WordPress request.
	 * @return void|Error
	 */
	protected function load_input( $request ) {
		$content_type = $request->get_content_type();
		$content_type = \mp_get( $content_type, 'value', 'application/x-www-form-urlencoded' );

		if ( 'GET' === $request->get_method() ) {
			$this->input = $request->get_query_params();
		} elseif ( 'application/json' === $content_type ) {
			$this->input = $this->normalize_json( $request->get_json_params() );
		} elseif ( ! $content_type ||
			'application/x-www-form-urlencoded' === $content_type ||
			'multipart/form-data' === $content_type ) {
				$this->input = $this->form_to_json( $request->get_body_params() );
				$this->files = $request->get_file_params();
		} else {
			return new Error( 'invalid_request', 'Unsupported Content Type: ' . $content_type, 400 );
		}

		if ( empty( $this->input ) ) {
			return new Error( 'invalid_request', 'No input provided', 400 );
		}

		if ( WP_DEBUG ) {
			if ( ! empty( $this->files ) ) {
				$this->log_error( array_keys( $this->files ), 'Micropub File Parameters' );
			}
			$this->log_error( $this->input, 'Micropub Input' );
		}

		if ( isset( $this->input['properties'] ) ) {
			$properties = $this->input['properties'];
			if ( isset( $properties['location'] ) ) {
				$this->input['properties']['location'] = $this->parse_geo_uri( $properties['location'][0] );
			} elseif ( isset( $properties['latitude'] ) && isset( $properties['longitude'] ) ) {
				$this->input['properties']['location'] = array(
					'type'       => array( 'h-geo' ),
					'properties' => array(
						'latitude'  => $properties['latitude'],
						'longitude' => $properties['longitude'],
					),
				);
				if ( isset( $properties['altitude'] ) ) {
					$this->input['properties']['location']['properties']['altitude'] = $properties['altitude'];
					unset( $this->input['properties']['altitude'] );
				}
				unset( $this->input['properties']['latitude'] );
				unset( $this->input['properties']['longitude'] );
			}

			if ( isset( $properties['checkin'] ) ) {
				$this->input['properties']['checkin'] = $this->parse_geo_uri( $properties['checkin'][0] );
			}
		}

		/**
		 * Filters the Micropub request before processing.
		 *
		 * @param array $input The Micropub request input.
		 */
		$this->input = \apply_filters( 'before_micropub', $this->input );
	}

	/**
	 * Check action and match to scope.
	 *
	 * @param string $action Action to check.
	 * @return boolean|Error
	 */
	protected function check_action( $action ) {
		switch ( $action ) {
			case 'delete':
			case 'undelete':
				$return = \current_user_can( 'delete_posts' );
				break;
			case 'update':
				$return = \current_user_can( 'edit_published_posts' );
				break;
			case 'create':
				$return = \current_user_can( 'edit_posts' );
				break;
			default:
				return new Error( 'invalid_request', 'Unknown Action', 400 );
		}

		if ( $return ) {
			return true;
		}

		return new Error( 'insufficient_scope', sprintf( 'insufficient to %1$s posts', $action ), 403, $this->scopes );
	}

	/**
	 * Get syndication targets.
	 *
	 * @param int   $user_id User ID.
	 * @param array $input   Input data.
	 * @return array
	 */
	protected function get_syndicate_targets( $user_id, $input = null ) {
		/**
		 * Filters the list of syndication targets.
		 *
		 * @param array $synd_urls Array of syndication target URLs. Empty by default.
		 * @param int   $user_id   The user ID.
		 * @param array $input     The Micropub request input.
		 */
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		return \apply_filters( 'micropub_syndicate-to', array(), $user_id, $input );
	}

	/**
	 * Query a post for its MF2 data.
	 *
	 * @param int $post_id Post ID.
	 * @return array MF2 formatted array.
	 */
	public function query( $post_id ) {
		$resp  = \micropub_get_mf2( $post_id );
		$props = \mp_get( $this->input, 'properties' );

		if ( $props ) {
			if ( ! is_array( $props ) ) {
				$props = array( $props );
			}
			$resp = array(
				'properties' => array_intersect_key(
					$resp['properties'],
					array_flip( $props )
				),
			);
		}

		return $resp;
	}

	/**
	 * Handle a create request.
	 *
	 * @param int $user_id User ID.
	 * @return array|Error
	 */
	protected function handle_create( $user_id ) {
		$args = $this->mp_to_wp( $this->input );

		/**
		 * Filters the post type for a Micropub post.
		 *
		 * @param string $post_type The post type. Default 'post'.
		 * @param array  $input     The Micropub request input.
		 */
		$args['post_type'] = \apply_filters( 'micropub_post_type', 'post', $this->input );

		/**
		 * Filters the taxonomy input for a Micropub post.
		 *
		 * @param array|null $tax_input Taxonomy terms to set.
		 * @param array      $input     The Micropub request input.
		 */
		$args['tax_input'] = \apply_filters( 'micropub_tax_input', null, $this->input );

		$args = $this->store_micropub_auth_response( $args );

		$post_content = \mp_get( $args, 'post_content', '' );

		/**
		 * Filters the post content for a Micropub post.
		 *
		 * @param string $post_content The post content.
		 * @param array  $input        The Micropub request input.
		 */
		$post_content = \apply_filters( 'micropub_post_content', $post_content, $this->input );

		if ( $post_content ) {
			$args['post_content'] = $post_content;
		}

		$args = $this->store_mf2( $args );
		$args = $this->store_geodata( $args );

		if ( \is_micropub_error( $args ) ) {
			return $args;
		}

		if ( $user_id ) {
			$args['post_author'] = $user_id;
		}

		if ( ! \user_can( $user_id, 'publish_posts' ) && \user_can( $user_id, 'edit_posts' ) ) {
			$args['post_status'] = 'draft';
		} else {
			$args['post_status'] = $this->post_status( $this->input );
		}

		if ( ! $args['post_status'] ) {
			return new Error( 'invalid_request', 'Invalid Post Status', 400 );
		}

		if ( WP_DEBUG ) {
			$this->log_error( $args, 'wp_insert_post with args' );
		}

		$this->insert_post( $args );

		if ( \is_micropub_error( $args['ID'] ) ) {
			return $args['ID'];
		}

		$this->default_file_handler( $args['ID'] );

		return $args;
	}

	/**
	 * Handle an update request.
	 *
	 * @param array $input Input data.
	 * @return array|Error
	 */
	protected function handle_update( $input ) {
		$post_id = \url_to_postid( $input['url'] );
		$args    = \get_post( $post_id, ARRAY_A );

		if ( ! $args ) {
			return new Error( 'invalid_request', sprintf( '%1$s not found', $input['url'] ), 400 );
		}

		// Add.
		$add = \mp_get( $input, 'add', false );
		if ( $add ) {
			if ( ! is_array( $add ) ) {
				return new Error( 'invalid_request', 'add must be an object', 400 );
			}
			$addable = array_merge( array( 'category', 'syndication' ), self::MEDIA_PROPERTIES );
			if ( array_diff( array_keys( $add ), $addable ) ) {
				return new Error( 'invalid_request', sprintf( 'can only add to %1$s; other properties not supported', implode( ', ', $addable ) ), 400 );
			}
			$add_args = $this->mp_to_wp( array( 'properties' => $add ) );
			if ( \mp_get( $add_args, 'tags_input' ) ) {
				$args['tags_input'] = array_merge(
					\mp_get( $args, 'tags_input' ),
					$add_args['tags_input']
				);
			}
			if ( \mp_get( $add_args, 'post_category' ) ) {
				$args['post_category'] = array_merge(
					\mp_get( $args, 'post_category' ),
					$add_args['post_category']
				);
			}
		}

		// Delete.
		$delete = \mp_get( $input, 'delete', false );
		if ( $delete ) {
			if ( \is_assoc_array( $delete ) ) {
				if ( array_diff( array_keys( $delete ), array( 'category', 'syndication' ) ) ) {
					return new Error( 'invalid_request', 'can only delete individual values from category and syndication; other properties not supported', 400 );
				}
				$delete_args = $this->mp_to_wp( array( 'properties' => $delete ) );
				if ( $delete_args['tags_input'] ) {
					$args['tags_input'] = array_diff(
						$args['tags_input'] ? $args['tags_input'] : array(),
						$delete_args['tags_input']
					);
				}
				if ( $delete_args['post_category'] ) {
					$args['post_category'] = array_diff(
						$args['post_category'] ? $args['post_category'] : array(),
						$delete_args['post_category']
					);
				}
			} elseif ( \wp_is_numeric_array( $delete ) ) {
				$delete = array_flip( $delete );
				if ( array_key_exists( 'category', $delete ) ) {
					\wp_delete_object_term_relationships( $post_id, array( 'post_tag', 'category' ) );
					unset( $args['tags_input'] );
					unset( $args['post_category'] );
				}
				$delete = $this->mp_to_wp( array( 'properties' => $delete ) );
				if ( ! empty( $delete ) && \is_assoc_array( $delete ) ) {
					foreach ( $delete as $name => $_ ) {
						$args[ $name ] = null;
					}
				}
			} else {
				return new Error( 'invalid_request', 'delete must be an array or object', 400 );
			}
		}

		// Replace.
		$replace = \mp_get( $input, 'replace', false );
		if ( $replace ) {
			if ( ! is_array( $replace ) ) {
				return new Error( 'invalid_request', 'replace must be an object', 400 );
			}
			foreach ( $this->mp_to_wp( array( 'properties' => $replace ) ) as $name => $val ) {
				$args[ $name ] = $val;
			}
		}

		$args['edit_date'] = true;

		$post_content = \mp_get( $args, 'post_content', '' );

		/** This filter is documented above. */
		$post_content = \apply_filters( 'micropub_post_content', $post_content, $this->input );

		if ( $post_content ) {
			$args['post_content'] = $post_content;
		}

		$args = $this->store_mf2( $args );
		$args = $this->store_geodata( $args );

		if ( 0 !== \get_current_user_id() ) {
			if ( ! array_key_exists( 'meta_input', $args ) ) {
				$args['meta_input'] = array();
			}
			$args['meta_input']['_edit_last'] = \get_current_user_id();
		}

		if ( WP_DEBUG ) {
			$this->log_error( $args, 'wp_update_post with args' );
		}

		$this->update_post( $args );

		if ( \is_micropub_error( $args['ID'] ) ) {
			return $args['ID'];
		}

		$this->default_file_handler( $post_id );

		return $args;
	}

	/**
	 * Insert a new post.
	 *
	 * @param array $args Post arguments.
	 */
	protected function insert_post( &$args ) {
		/**
		 * Filters the arguments for wp_insert_post before insertion.
		 *
		 * @param array $args Arguments to be passed to wp_insert_post.
		 */
		$args = \apply_filters( 'pre_insert_micropub_post', $args );

		if ( array_key_exists( 'ID', $args ) ) {
			return;
		}

		\kses_remove_filters();
		$args['ID'] = $this->check_error( \wp_insert_post( $args, true ) );

		if ( $args['ID'] && array_key_exists( 'client_uid', $this->micropub_auth_response ) ) {
			\wp_set_object_terms( $args['ID'], array( $this->micropub_auth_response['client_uid'] ), 'indieauth_client' );
		}

		$args['post_url'] = \get_permalink( $args['ID'] );
		\kses_init_filters();
	}

	/**
	 * Update an existing post.
	 *
	 * @param array $args Post arguments.
	 */
	protected function update_post( &$args ) {
		\kses_remove_filters();
		$args['ID']       = $this->check_error( \wp_update_post( $args, true ) );
		$args['post_url'] = \get_permalink( $args['ID'] );
		\kses_init_filters();
	}

	/**
	 * Get default post status.
	 *
	 * @return string
	 */
	protected function default_post_status() {
		return MICROPUB_DRAFT_MODE ? 'draft' : 'publish';
	}

	/**
	 * Determine post status from mf2 data.
	 *
	 * @param array $mf2 MF2 data.
	 * @return string|null
	 */
	protected function post_status( $mf2 ) {
		$props = $mf2['properties'];

		if ( ! isset( $props['post-status'] ) && ! isset( $props['visibility'] ) ) {
			return $this->default_post_status();
		}

		if ( isset( $props['visibility'] ) ) {
			$visibilitylist = array( array( 'private' ), array( 'public' ) );
			if ( ! in_array( $props['visibility'], $visibilitylist, true ) ) {
				return null;
			}
			if ( array( 'private' ) === $props['visibility'] ) {
				return 'private';
			}
		}

		if ( isset( $props['post-status'] ) ) {
			$statuslist = array( array( 'published' ), array( 'draft' ) );
			if ( ! in_array( $props['post-status'], $statuslist, true ) ) {
				return null;
			}
			if ( array( 'published' ) === $props['post-status'] ) {
				return 'publish';
			}
			return 'draft';
		}

		return $this->default_post_status();
	}

	/**
	 * Generates a suggestion for a title based on mf2 properties.
	 *
	 * @param array $mf2 MF2 Properties.
	 * @return array|string
	 */
	protected function suggest_post_title( $mf2 ) {
		$props = \mp_get( $mf2, 'properties' );
		if ( isset( $props['name'] ) ) {
			return $props['name'];
		}

		/**
		 * Filters the suggested title for a Micropub post.
		 *
		 * @param string $title      The suggested title.
		 * @param array  $properties The MF2 properties.
		 */
		return \apply_filters( 'micropub_suggest_title', '', $props );
	}

	/**
	 * Converts Micropub request to WordPress post args.
	 *
	 * @param array $mf2 MF2 data.
	 * @return array
	 */
	protected function mp_to_wp( $mf2 ) {
		$props = \mp_get( $mf2, 'properties' );
		$args  = array();

		foreach ( array(
			'mp-slug' => 'post_name',
			'name'    => 'post_title',
			'summary' => 'post_excerpt',
		) as $mf => $wp ) {
			if ( isset( $props[ $mf ] ) ) {
				$args[ $wp ] = isset( $props[ $mf ][0] ) ? $props[ $mf ][0] : $props[ $mf ];
			}
		}

		if ( ! isset( $args['ID'] ) && ! isset( $args['post_name'] ) ) {
			$slug = $this->suggest_post_title( $mf2 );
			if ( ! empty( $slug ) ) {
				$args['post_name'] = $slug;
			}
		}

		if ( isset( $args['post_name'] ) ) {
			if ( is_array( $args['post_name'] ) ) {
				$args['post_name'] = array_key_first( $args['post_name'] );
			}
			$args['post_name'] = \sanitize_title( $args['post_name'] );
		}

		if ( isset( $props['published'] ) ) {
			$date = new \DateTime( $props['published'][0] );
			if ( $date ) {
				$wptz = \wp_timezone();
				$tz   = $date->getTimezone();
				$date->setTimeZone( $wptz );
				$args['timezone']  = $tz->getName();
				$args['post_date'] = $date->format( 'Y-m-d H:i:s' );
				$date->setTimeZone( new \DateTimeZone( 'GMT' ) );
				$args['post_date_gmt'] = $date->format( 'Y-m-d H:i:s' );
			}
		}

		if ( isset( $props['updated'] ) ) {
			$date = new \DateTime( $props['updated'][0] );
			if ( $date ) {
				$wptz = \wp_timezone();
				$date->setTimeZone( $wptz );
				$tz                    = $date->getTimezone();
				$args['timezone']      = $tz->getName();
				$args['post_modified'] = $date->format( 'Y-m-d H:i:s' );
				$date->setTimeZone( new \DateTimeZone( 'GMT' ) );
				$args['post_modified_gmt'] = $date->format( 'Y-m-d H:i:s' );
			}
		}

		if ( isset( $props['category'] ) && is_array( $props['category'] ) ) {
			$args['post_category'] = array();
			$args['tags_input']    = array();
			foreach ( $props['category'] as $mp_cat ) {
				$wp_cat = \get_category_by_slug( $mp_cat );
				if ( $wp_cat ) {
					$args['post_category'][] = $wp_cat->term_id;
				} else {
					$args['tags_input'][] = $mp_cat;
				}
			}
		}

		if ( isset( $props['content'] ) ) {
			$content = $props['content'][0];
			if ( is_array( $content ) ) {
				$args['post_content'] = $content['html'] ? $content['html'] :
							\htmlspecialchars( $content['value'] );
			} elseif ( $content ) {
				$args['post_content'] = \htmlspecialchars( $content );
			}
		}

		return $args;
	}

	/**
	 * Collects the media values of a property from the request.
	 *
	 * A create request carries media under `properties`, an update request under
	 * `add` or `replace`, so all three have to be considered.
	 *
	 * @param string $field The property name.
	 * @return array The values found, in request order.
	 */
	public function media_values( $field ) {
		$values = array();

		foreach ( array( 'properties', 'add', 'replace' ) as $key ) {
			$group = \mp_get( $this->input, $key );
			if ( is_array( $group ) && isset( $group[ $field ] ) ) {
				$values = array_merge( $values, (array) $group[ $field ] );
			}
		}

		return $values;
	}

	/**
	 * Handles file uploads.
	 *
	 * @param int $post_id Post ID.
	 */
	public function default_file_handler( $post_id ) {
		$media_controller = new Media_Controller();

		foreach ( self::MEDIA_PROPERTIES as $field ) {
			$values  = $this->media_values( $field );
			$att_ids = array();

			// Only the values that were actually sideloaded may be swapped out of the
			// metadata further down. An uploaded file part takes precedence over them,
			// in which case they are left alone rather than dropped.
			$sideloaded = array();

			if ( isset( $this->files[ $field ] ) || ! empty( $values ) ) {
				if ( isset( $this->files[ $field ] ) ) {
					$files = $this->files[ $field ];
					if ( is_array( $files['name'] ) ) {
						$files = $media_controller->file_array( $files );
						foreach ( $files as $file ) {
							$att_ids[] = $this->check_error(
								$media_controller->media_handle_upload( $file, $post_id )
							);
						}
					} else {
						$att_ids[] = $this->check_error(
							$media_controller->media_handle_upload( $files, $post_id )
						);
					}
				} else {
					foreach ( $values as $val ) {
						// alt is optional in the object form of a media value, value is not.
						$url       = is_array( $val ) ? $val['value'] : $val;
						$desc      = is_array( $val ) ? \mp_get( $val, 'alt', null ) : null;
						$att_ids[] = $this->check_error(
							$media_controller->media_sideload_url( $url, $post_id, $desc )
						);

						$sideloaded[] = $val;
					}
				}

				$att_urls = array();
				foreach ( $att_ids as $id ) {
					if ( \is_micropub_error( $id ) ) {
						return $id;
					}
					if ( 'featured' === $field ) {
						\set_post_thumbnail( $post_id, $id );
					}
					$att_urls[] = \wp_get_attachment_url( $id );
				}

				if ( ! isset( $this->input['properties'][ $field ] ) ) {
					$this->input['properties'][ $field ] = $att_urls;
				} else {
					$this->input['properties'][ $field ] = array_merge( $this->input['properties'][ $field ], $att_urls );
				}
				$this->store_media_meta( $post_id, $field, $sideloaded, $att_urls );
			}
		}
	}

	/**
	 * Stores the local URLs of sideloaded media as post metadata.
	 *
	 * `store_mf2()` has already written the values as they arrived, which for
	 * sideloaded media means the remote source URLs. Swap those out for the local
	 * ones rather than appending, so the property does not end up listing every
	 * attachment twice.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $field    The property name.
	 * @param array  $sources  The source values that were sideloaded.
	 * @param array  $att_urls The local URLs of the resulting attachments.
	 */
	public function store_media_meta( $post_id, $field, $sources, $att_urls ) {
		$key      = 'mf2_' . $field;
		$existing = \get_post_meta( $post_id, $key, true );
		$existing = is_array( $existing ) ? $existing : array();

		$urls = array();
		foreach ( $sources as $source ) {
			$urls[] = is_array( $source ) && isset( $source['value'] ) ? $source['value'] : $source;
		}

		$keep = array();
		foreach ( $existing as $value ) {
			$url = is_array( $value ) && isset( $value['value'] ) ? $value['value'] : $value;
			if ( ! in_array( $url, $urls, true ) ) {
				$keep[] = $value;
			}
		}

		\update_post_meta( $post_id, $key, array_merge( $keep, $att_urls ) );
	}

	/**
	 * Stores geodata in WordPress format.
	 *
	 * @param array $args Post arguments.
	 * @return array|Error
	 */
	public function store_geodata( $args ) {
		$properties = isset( $this->input['properties'] ) ? $this->input['properties'] : array();
		$location   = isset( $properties['location'] ) ? $properties['location'] : ( isset( $properties['checkin'] ) ? $properties['checkin'] : null );
		$location   = is_array( $location ) && isset( $location[0] ) ? $location[0] : $location;

		$visibility = isset( $properties['location-visibility'] ) ? $properties['location-visibility'] : null;
		if ( $visibility ) {
			$visibility = array_pop( $visibility );
			if ( ! isset( $args['meta_input'] ) ) {
				$args['meta_input'] = array();
			}
			switch ( $visibility ) {
				case 'public':
					$args['meta_input']['geo_public'] = 1;
					break;
				case 'private':
					$args['meta_input']['geo_public'] = 0;
					break;
				case 'protected':
					$args['meta_input']['geo_public'] = 2;
					break;
				default:
					return new Error( 'invalid_request', sprintf( 'unsupported location visibility %1$s', $visibility ), 400 );
			}
		}

		if ( $location ) {
			if ( ! isset( $args['meta_input'] ) ) {
				$args['meta_input'] = array();
			}
			if ( is_array( $location ) ) {
				$props = $location['properties'];
				if ( isset( $props['geo'] ) ) {
					if ( array_key_exists( 'label', $props ) ) {
						$args['meta_input']['geo_address'] = $props['label'][0];
					}
					$props = $props['geo'][0]['properties'];
				} else {
					$parts = array(
						\mp_get( $props, 'name', array(), true ),
						\mp_get( $props, 'street-address', array(), true ),
						\mp_get( $props, 'locality', array(), true ),
						\mp_get( $props, 'region', array(), true ),
						\mp_get( $props, 'postal-code', array(), true ),
						\mp_get( $props, 'country-name', array(), true ),
					);
					$parts = array_filter( $parts );
					if ( ! empty( $parts ) ) {
						$args['meta_input']['geo_address'] = implode( ', ', array_filter( $parts ) );
					}
				}
				foreach ( array( 'latitude', 'longitude', 'altitude', 'accuracy' ) as $property ) {
					if ( array_key_exists( $property, $props ) ) {
						$args['meta_input'][ 'geo_' . $property ] = $props[ $property ][0];
					}
				}
			} elseif ( 'http' !== substr( $location, 0, 4 ) ) {
				$args['meta_input']['geo_address'] = $location;
			}
		}

		return $args;
	}

	/**
	 * Parse a GEO URI into an mf2 object.
	 *
	 * @param string|array $uri GEO URI.
	 * @return array|string
	 */
	public function parse_geo_uri( $uri ) {
		if ( ! is_string( $uri ) ) {
			return $uri;
		}
		if ( 'geo:' !== substr( $uri, 0, 4 ) ) {
			return $uri;
		}

		$properties              = array();
		$geo                     = str_replace( 'geo:', '', urldecode( $uri ) );
		$geo                     = explode( ';', $geo );
		$coords                  = explode( ',', $geo[0] );
		$properties['latitude']  = array( trim( $coords[0] ) );
		$properties['longitude'] = array( trim( $coords[1] ) );

		if ( isset( $coords[2] ) ) {
			$properties['altitude'] = array( trim( $coords[2] ) );
		}

		array_shift( $geo );
		foreach ( $geo as $g ) {
			$g = explode( '=', $g );
			if ( 'u' === $g[0] ) {
				$g[0] = 'accuracy';
			}
			$properties[ $g[0] ] = array( $g[1] );
		}

		if ( array_key_exists( 'h', $properties ) ) {
			$type = array( 'h-' . $properties['h'][0] );
			unset( $properties['h'] );
		} else {
			$diff = array_diff(
				array_keys( $properties ),
				array( 'longitude', 'latitude', 'altitude', 'accuracy' )
			);
			$type = empty( $diff ) ? array( 'h-geo' ) : array( 'h-card' );
		}

		return array(
			'type'       => $type,
			'properties' => array_filter( $properties ),
		);
	}

	/**
	 * Store the authorization endpoint response as post metadata.
	 *
	 * @param array $args Post arguments.
	 * @return array
	 */
	public function store_micropub_auth_response( $args ) {
		if ( $this->micropub_auth_response || ( \is_assoc_array( $this->micropub_auth_response ) ) ) {
			$args['meta_input']                                = \mp_get( $args, 'meta_input' );
			$args['meta_input']['micropub_auth_response']      = \wp_array_slice_assoc( $this->micropub_auth_response, array( 'client_id', 'client_name', 'client_icon', 'uuid' ) );
			$args['meta_input']['micropub_version']['version'] = \Micropub\get_plugin_version();
		}
		return $args;
	}

	/**
	 * Store properties as post metadata.
	 *
	 * @param array $args Post arguments.
	 * @return array
	 */
	public function store_mf2( $args ) {
		$excludes = array( 'name', 'published', 'updated', 'summary', 'content', 'visibility' );
		$props    = \mp_get( $this->input, 'properties', false );

		if ( ! isset( $args['ID'] ) && $props ) {
			$args['meta_input'] = \mp_get( $args, 'meta_input' );
			$type               = \mp_get( $this->input, 'type' );
			if ( $type ) {
				$args['meta_input']['mf2_type'] = $type;
			}
			if ( isset( $args['timezone'] ) ) {
				$args['meta_input']['geo_timezone'] = $args['timezone'];
			}
			foreach ( $props as $key => $val ) {
				if ( 'mp-' !== substr( $key, 0, 3 ) && ! in_array( $key, $excludes, true ) ) {
					$args['meta_input'][ 'mf2_' . $key ] = $val;
				}
			}
			return $args;
		}

		$replace = isset( $this->input['replace'] ) ? $this->input['replace'] : null;
		if ( $replace ) {
			foreach ( $replace as $prop => $val ) {
				\update_post_meta( $args['ID'], 'mf2_' . $prop, $val );
			}
		}

		$meta = \get_post_meta( $args['ID'] );
		$add  = isset( $this->input['add'] ) ? $this->input['add'] : null;
		if ( $add ) {
			foreach ( $add as $prop => $val ) {
				$key = 'mf2_' . $prop;
				if ( array_key_exists( $key, $meta ) ) {
					$cur = $meta[ $key ][0] ? \maybe_unserialize( $meta[ $key ][0] ) : array();
					\update_post_meta( $args['ID'], $key, array_merge( $cur, $val ) );
				} else {
					\update_post_meta( $args['ID'], $key, $val );
				}
			}
		}

		$delete = isset( $this->input['delete'] ) ? $this->input['delete'] : null;
		if ( $delete ) {
			if ( \is_assoc_array( $delete ) ) {
				foreach ( $delete as $prop => $to_delete ) {
					$key = 'mf2_' . $prop;
					if ( isset( $meta[ $key ] ) ) {
						$existing = \maybe_unserialize( $meta[ $key ][0] );
						\update_post_meta( $args['ID'], $key, array_diff( $existing, $to_delete ) );
					}
				}
			} else {
				foreach ( $delete as $_ => $prop ) {
					\delete_post_meta( $args['ID'], 'mf2_' . $prop );
					if ( 'location' === $prop ) {
						\delete_post_meta( $args['ID'], 'geo_latitude' );
						\delete_post_meta( $args['ID'], 'geo_longitude' );
					}
				}
			}
		}

		return $args;
	}

	/**
	 * Takes form encoded input and converts to json encoded input.
	 *
	 * @param array $data Form data.
	 * @return array
	 */
	public function form_to_json( $data ) {
		$input = array();
		foreach ( $data as $key => $val ) {
			if ( 'action' === $key || 'url' === $key ) {
				$input[ $key ] = $val;
			} elseif ( 'h' === $key ) {
				$input['type'] = array( 'h-' . $val );
			} elseif ( 'access_token' === $key ) {
				continue;
			} else {
				$input['properties']         = \mp_get( $input, 'properties' );
				$input['properties'][ $key ] = ( is_array( $val ) && \wp_is_numeric_array( $val ) ) ? $val : array( $val );
			}
		}
		return $input;
	}

	/**
	 * Ensures JSON is compliant with the Micropub JSON Syntax.
	 *
	 * @param array $data JSON data.
	 * @return array
	 */
	public function normalize_json( $data ) {
		if ( ! array_key_exists( 'properties', $data ) ) {
			return $data;
		}
		foreach ( $data['properties'] as $key => $value ) {
			if ( ! is_array( $value ) ) {
				$data['properties'][ $key ] = array( $value );
			}
		}
		return $data;
	}
}
