<?php

add_action( 'plugins_loaded', array( 'Micropub_Endpoint', 'init' ) );

/**
 * Micropub Endpoint Class
 */
class Micropub_Endpoint extends Micropub_Base {
	// associative array
	public static $request_headers;

	// associative array, read from JSON or form-encoded input. populated by load_input().
	protected static $input;

	// file array populated by load_input
	protected static $files;

	// associative array, populated by authorize().
	protected static $micropub_auth_response = array();

	// Array of Scopes
	protected static $scopes = array();

	/**
	 * Initialize the plugin.
	 */
	public static function init() {
		// endpoint discovery
		add_action( 'wp_head', array( static::class, 'html_header' ), 99 );
		add_action( 'send_headers', array( static::class, 'http_header' ) );
		add_filter( 'host_meta', array( static::class, 'jrd_links' ) );
		add_filter( 'webfinger_user_data', array( static::class, 'jrd_links' ) );

		// register endpoint
		add_action( 'rest_api_init', array( static::class, 'register_route' ) );

		add_filter( 'rest_request_after_callbacks', array( static::class, 'return_micropub_error' ), 10, 3 );
	}

	public static function get( $a, $key, $d = array() ) {
		if ( is_array( $a ) ) {
			return isset( $a[ $key ] ) ? $a[ $key ] : $d;
		}
		return $d;
	}

	public static function register_route() {
		register_rest_route(
			static::get_namespace(),
			'/endpoint',
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

		$action     = $request->get_param( 'action' );
		$action     = $action ? $action : 'create';
		$permission = self::check_action( $action );

		if ( is_micropub_error( $permission ) ) {
			return $permission->to_wp_error();
		}

		return $permission;
	}


	/**
	 * Parse the micropub request and render the document
	 *
	 * @param WP_REST_Request $request WordPress request
	 *
	 * @uses apply_filter() Calls 'before_micropub' on the default request
	 */
	protected static function load_input( $request ) {
		$content_type = $request->get_content_type();
		$content_type = mp_get( $content_type, 'value', 'application/x-www-form-urlencoded' );

		if ( 'GET' === $request->get_method() ) {
			static::$input = $request->get_query_params();
		} elseif ( 'application/json' === $content_type ) {
			static::$input = self::normalize_json( $request->get_json_params() );
		} elseif ( ! $content_type ||
			'application/x-www-form-urlencoded' === $content_type ||
			'multipart/form-data' === $content_type ) {
				static::$input = self::form_to_json( $request->get_body_params() );
				static::$files = $request->get_file_params();
		} else {
			return new WP_Micropub_Error( 'invalid_request', 'Unsupported Content Type: ' . $content_type, 400 );
		}
		if ( empty( static::$input ) ) {
			return new WP_Micropub_Error( 'invalid_request', 'No input provided', 400 );
		}
		if ( WP_DEBUG ) {
			if ( ! empty( static::$files ) ) {
				static::log_error( array_keys( static::$files ), 'Micropub File Parameters' );
			}
			static::log_error( static::$input, 'Micropub Input' );
		}

		if ( isset( static::$input['properties'] ) ) {
			$properties = static::$input['properties'];
			if ( isset( $properties['location'] ) ) {
				static::$input['properties']['location'] = self::parse_geo_uri( $properties['location'][0] );
			} elseif ( isset( $properties['latitude'] ) && isset( $properties['longitude'] ) ) {
					// Convert latitude and longitude properties to an h-geo with altitude if present.
					static::$input['properties']['location'] = array(
						'type'       => array( 'h-geo' ),
						'properties' => array(
							'latitude'  => $properties['latitude'],
							'longitude' => $properties['longitude'],
						),
					);
					if ( isset( $properties['altitude'] ) ) {
						static::$input['properties']['location']['properties']['altitude'] = $properties['altitude'];
						unset( static::$input['properties']['altitude'] );
					}
					unset( static::$input['properties']['latitude'] );
					unset( static::$input['properties']['longitude'] );
			}

			if ( isset( $properties['checkin'] ) ) {
				static::$input['properties']['checkin'] = self::parse_geo_uri( $properties['checkin'][0] );
			}
		}

		static::$input = apply_filters( 'before_micropub', static::$input );
	}

	/**
	 * Check action and match to scope
	 *
	 * @param string $action
	 *
	 * @return boolean|WP_Micropub_Error
	**/
	protected static function check_action( $action ) {
		switch ( $action ) {
			case 'delete':
			case 'undelete':
				$return = current_user_can( 'delete_posts' );
				break;
			case 'update':
				$return = current_user_can( 'edit_published_posts' );
				break;
			case 'create':
				$return = current_user_can( 'edit_posts' );
				break;
			default:
				return new WP_Micropub_Error( 'invalid_request', 'Unknown Action', 400 );
		}
		if ( $return ) {
			return true;
		}
		return new WP_Micropub_Error( 'insufficient_scope', sprintf( 'insufficient to %1$s posts', $action ), 403, static::$scopes );
	}


	/**
	 * Parse the micropub request and render the document
	 *
	 * @param WP_REST_Request $request.
	 */
	public static function post_handler( $request ) {
		$user_id  = get_current_user_id();
		$response = new WP_REST_Response();
		$load     = static::load_input( $request );
		if ( is_micropub_error( $load ) ) {
			return $load;
		}

		$action = mp_get( static::$input, 'action', 'create' );
		$url    = mp_get( static::$input, 'url' );

		// check that we support all requested syndication targets
		$synd_supported = self::get_syndicate_targets( $user_id );
		$uids           = array();
		foreach ( $synd_supported as $syn ) {
			$uids[] = mp_get( $syn, 'uid' );
		}

		$properties     = mp_get( static::$input, 'properties' );
		$synd_requested = mp_get( $properties, 'mp-syndicate-to' );
		$unknown        = array_diff( $synd_requested, $uids );

		if ( $unknown ) {
			return new WP_Micropub_Error( 'invalid_request', sprintf( 'Unknown mp-syndicate-to targets: %1$s', implode( ', ', $unknown ) ), 400 );
		}
		// For all actions other than creation a url is required
		if ( ! $url && 'create' !== $action ) {
			return new WP_Micropub_Error( 'invalid_request', sprintf( 'URL is Required for %1$s action', $action ), 400 );
		}
		switch ( $action ) {
			case 'create':
				$args = static::create( $user_id );
				if ( ! is_micropub_error( $args ) ) {
					$response->set_status( 201 );
					$response->header( 'Location', get_permalink( $args['ID'] ) );
				}
				break;
			case 'update':
				$args = static::update( static::$input );
				break;
			case 'delete':
				$post_id = url_to_postid( $url );
				$args    = get_post( $post_id, ARRAY_A );
				if ( ! $args ) {
					return new WP_Micropub_Error( 'invalid_request', sprintf( '%1$s not found', $url ), 400 );
				}
				static::check_error( wp_trash_post( $args['ID'] ) );
				break;
			case 'undelete':
				$found = false;
				// url_to_postid() doesn't support posts in trash, so look for
				// it ourselves, manually.
				// here's another, more complicated way that customizes WP_Query:
				// https://gist.github.com/peterwilsoncc/bb40e52cae7faa0e6efc
				foreach ( get_posts(
					array(
						'post_status' => 'trash',
						'fields'      => 'ids',
					)
				) as $post_id ) {
					if ( get_the_guid( $post_id ) === $url ) {
						wp_untrash_post( $post_id );
						wp_publish_post( $post_id );
						$found = true;
						$args  = array( 'ID' => $post_id );
					}
				}
				if ( ! $found ) {
					return new WP_Micropub_Error( 'invalid_request', sprintf( 'deleted post %1$s not found', $url ), 400 );
				}
				break;
			default:
				return new WP_Micropub_Error( 'invalid_request', sprintf( 'unknown action %1$s', $action ), 400 );
		}
		if ( is_micropub_error( $args ) ) {
			return $args;
		}
		do_action( 'after_micropub', static::$input, $args );

		if ( ! empty( $synd_requested ) ) {
			do_action( 'micropub_syndication', $args['ID'], $synd_requested );
		}

		$response->set_data( $args );
		return $response;
	}

	private static function get_syndicate_targets( $user_id, $input = null ) {
		return apply_filters( 'micropub_syndicate-to', array(), $user_id, $input );
	}

	/**
	 * Handle queries to the micropub endpoint
	 *
	 * @param WP_REST_Request $request
	 */
	public static function query_handler( $request ) {
		$user_id = get_current_user_id();
		static::load_input( $request );

		switch ( static::$input['q'] ) {
			case 'config':
				$resp = array(
					'syndicate-to'   => static::get_syndicate_targets( $user_id, static::$input ),
					'media-endpoint' => rest_url( static::get_namespace() . '/media' ),
					// Support returning visibility properties in q=config https://github.com/indieweb/micropub-extensions/issues/8#issuecomment-536301952
					'visibility'     => array( 'public', 'private' ),
					'mp'             => array(
						'slug',
						'syndicate-to',
					), // List of supported mp parameters
					'q'              => array(
						'config',
						'syndicate-to',
						'category',
						'source',
					), // List of supported query parameters https://github.com/indieweb/micropub-extensions/issues/7
					'properties'     => array(
						'location-visibility',
					), // List of support properties https://github.com/indieweb/micropub-extensions/issues/8
				);
				break;
			case 'syndicate-to':
				// return syndication targets with filter
				$resp = array( 'syndicate-to' => static::get_syndicate_targets( $user_id, static::$input ) );
				break;
			case 'category':
				// https://github.com/indieweb/micropub-extensions/issues/5
				$resp = array_merge(
					get_tags( array( 'fields' => 'names' ) ),
					get_terms(
						array(
							'taxonomy' => 'category',
							'fields'   => 'names',
						)
					)
				);
				if ( array_key_exists( 'filter', static::$input ) ) {
					$filter = static::$input['filter'];
					$resp   = mp_filter( $resp, $filter );
				}
				$resp = array( 'categories' => $resp );
				break;
			case 'source':
				if ( array_key_exists( 'url', static::$input ) ) {
					$post_id = url_to_postid( static::$input['url'] );
					if ( ! $post_id ) {
						return new WP_Micropub_Error( 'invalid_request', sprintf( 'not found: %1$s', static::$input['url'] ), 400 );
					}
					$resp = self::query( $post_id );
				} else {
					$args = array(
						'posts_per_page' => mp_get( static::$input, 'limit', 10 ),
						'fields'         => 'ids',
					);
					if ( array_key_exists( 'offset', static::$input ) ) {
						$args['offset'] = mp_get( static::$input, 'offset' );
					}

					if ( array_key_exists( 'visibility', static::$input ) ) {
						$visibilitylist = array( array( 'private' ), array( 'public' ) );
						if ( ! in_array( static::$input['visibility'], $visibilitylist, true ) ) {
							// Returning null will cause the server to return a 400 error
							return null;
						}
						if ( array( 'private' ) === static::$input['visibility'] ) {
							if ( user_can( $user_id, 'read_private_posts' ) ) {
								$args['post-status'] = 'private';
							}
						}
					} elseif ( array_key_exists( 'post-status', static::$input ) ) {
						//  According to the proposed specification these are the only two properties supported.
						// https://indieweb.org/Micropub-extensions#Post_Status
						// For now these are the only two we will support even though WordPress defaults to 8 and allows custom
						// But makes it easy to change

						// Map published to the WordPress property publish.
						if ( 'published' === mp_get( static::$input, 'post-status' ) ) {
							$args['post-status'] = 'publish';
						} elseif ( 'draft' === mp_get( static::$input, 'post-status' ) ) {
							$args['post-status'] = 'draft';
						}
					}
					$posts = get_posts( $args );
					$resp  = array();
					foreach ( $posts as $post ) {
						$resp[] = self::query( $post );
					}
					$resp = array( 'items' => $resp );
				}

				break;
			default:
				$resp = new WP_Micropub_Error( 'invalid_request', 'unknown query', 400, static::$input );
		}
		$resp = apply_filters( 'micropub_query', $resp, static::$input );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		do_action( 'after_micropub', static::$input, null );
		return new WP_REST_Response( $resp, 200 );
	}

	/* Query a format.
	 *
	 * @param int $post_id Post ID
	 *
	 * @return array MF2 Formatted Array
	 */
	public static function query( $post_id ) {
		$resp = static::get_mf2( $post_id );

		$props = mp_get( static::$input, 'properties' );

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

	/*
	 * Insert Post
	 *
	 */
	private static function insert_post( &$args ) {

		/**
		 * This filters arguments before inserting into the Post Table.
		 * If $args['ID'] is set, this will short circuit insertion to allow for custom database insertion.
		 */
		$args = apply_filters( 'pre_insert_micropub_post', $args );
		if ( array_key_exists( 'ID', $args ) ) {
			return;
		}
		kses_remove_filters();  // prevent sanitizing HTML tags in post_content
		$args['ID'] = static::check_error( wp_insert_post( $args, true ) );

		// Set Client Application Taxonomy if available.
		if ( $args['ID'] && array_key_exists( 'client_uid', static::$micropub_auth_response ) ) {
			wp_set_object_terms( $args['ID'], array( static::$micropub_auth_response['client_uid'] ), 'indieauth_client' );
		}

		$args['post_url'] = get_permalink( $args['ID'] );
		kses_init_filters();
	}

	/*
	 * Handle a create request.
	 */
	private static function create( $user_id ) {
		$args = static::mp_to_wp( static::$input );

		// Allow Filtering of Post Type
		$args['post_type'] = apply_filters( 'micropub_post_type', 'post', static::$input );

		// Allow filtering of Tax Input
		$args['tax_input'] = apply_filters( 'micropub_tax_input', null, static::$input );

		$args = static::store_micropub_auth_response( $args );

		$post_content = mp_get( $args, 'post_content', '' );
		$post_content = apply_filters( 'micropub_post_content', $post_content, static::$input );
		if ( $post_content ) {
			$args['post_content'] = $post_content;
		}

		$args = static::store_mf2( $args );
		$args = static::store_geodata( $args );
		if ( is_micropub_error( $args ) ) {
			return $args;
		}

		if ( $user_id ) {
			$args['post_author'] = $user_id;
		}

		// If the current user cannot publish posts then post status is always draft
		if ( ! user_can( $user_id, 'publish_posts' ) && user_can( $user_id, 'edit_posts' ) ) {
			$args['post_status'] = 'draft';
		} else {
			$args['post_status'] = static::post_status( static::$input );
		}
		if ( ! $args['post_status'] ) {
			return new WP_Micropub_Error( 'invalid_request', 'Invalid Post Status', 400 );
		}
		if ( WP_DEBUG ) {
			static::log_error( $args, 'wp_insert_post with args' );
		}

		static::insert_post( $args );

		static::default_file_handler( $args['ID'] );
		return $args;
	}

	/*
	 * Update Post
	 *
	 */
	private static function update_post( &$args ) {
		kses_remove_filters();  // prevent sanitizing HTML tags in post_content
		$args['ID']       = static::check_error( wp_update_post( $args, true ) );
		$args['post_url'] = get_permalink( $args['ID'] );
		kses_init_filters();
	}

	/*
	 * Handle an update request.
	 *
	 * This really needs a db transaction! But we can't assume the underlying
	 * MySQL db is InnoDB and supports transactions. :(
	 */
	private static function update( $input ) {
		$post_id = url_to_postid( $input['url'] );
		$args    = get_post( $post_id, ARRAY_A );
		if ( ! $args ) {
			return new WP_Micropub_Error( 'invalid_request', sprintf( '%1$s not found', $input['url'] ), 400 );
		}

		// add
		$add = mp_get( $input, 'add', false );
		if ( $add ) {
			if ( ! is_array( $add ) ) {
				return new WP_Micropub_Error( 'invalid_request', 'add must be an object', 400 );
			}
			if ( array_diff( array_keys( $add ), array( 'category', 'syndication' ) ) ) {
				return new WP_Micropub_Error( 'invalid_request', 'can only add to category and syndication; other properties not supported', 400 );
			}
			$add_args = static::mp_to_wp( array( 'properties' => $add ) );
			if ( $add_args['tags_input'] ) {
				// i tried wp_add_post_tags here, but it didn't work
				$args['tags_input'] = array_merge(
					$args['tags_input'] ? $args['tags_input'] : array(),
					$add_args['tags_input']
				);
			}
			if ( $add_args['post_category'] ) {
				// i tried wp_set_post_categories here, but it didn't work
				$args['post_category'] = array_merge(
					$args['post_category'] ? $args['post_category'] : array(),
					$add_args['post_category']
				);
			}
		}
		// Delete was moved to before replace in versions greater than 1.4.3 due to the fact that all items should be removed before replacement
		// delete
		$delete = mp_get( $input, 'delete', false );
		if ( $delete ) {
			if ( is_assoc_array( $delete ) ) {
				if ( array_diff( array_keys( $delete ), array( 'category', 'syndication' ) ) ) {
					return new WP_Micropub_Error( 'invalid_request', 'can only delete individual values from category and syndication; other properties not supported', 400 );
				}
				$delete_args = static::mp_to_wp( array( 'properties' => $delete ) );
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
			} elseif ( wp_is_numeric_array( $delete ) ) {
				$delete = array_flip( $delete );
				if ( array_key_exists( 'category', $delete ) ) {
					wp_delete_object_term_relationships( $post_id, array( 'post_tag', 'category' ) );
					unset( $args['tags_input'] );
					unset( $args['post_category'] );
				}
				$delete = static::mp_to_wp( array( 'properties' => $delete ) );
				if ( ! empty( $delete ) && is_assoc_array( $delete ) ) {
					foreach ( $delete as $name => $_ ) {
						$args[ $name ] = null;
					}
				}
			} else {
				return new WP_Micropub_Error( 'invalid_request', 'delete must be an array or object', 400 );
			}
		}

		// replace
		$replace = mp_get( $input, 'replace', false );
		if ( $replace ) {
			if ( ! is_array( $replace ) ) {
				return new WP_Micropub_Error( 'invalid_request', 'replace must be an object', 400 );
			}
			foreach ( static::mp_to_wp( array( 'properties' => $replace ) )
				as $name => $val ) {
				$args[ $name ] = $val;
			}
		}

		// tell WordPress to preserve published date explicitly, otherwise
		// wp_update_post sets it to the current time
		$args['edit_date'] = true;

		/* Filter Post Content
		 * Post Content is initially generated from content properties in the mp_to_wp function however this function is called
		 * multiple times for replace and delete
		*/
		$post_content = mp_get( $args, 'post_content', '' );
		$post_content = apply_filters( 'micropub_post_content', $post_content, static::$input );
		if ( $post_content ) {
			$args['post_content'] = $post_content;
		}

		// Store metadata from Microformats Properties
		$args = static::store_mf2( $args );
		$args = static::store_geodata( $args );

		if ( 0 !== get_current_user_id() ) {
			if ( ! array_key_exists( 'meta_input', $args ) ) {
				$args['meta_input'] = array();
			}

			$args['meta_input']['_edit_last'] = get_current_user_id();
		}

		if ( WP_DEBUG ) {
			static::log_error( $args, 'wp_update_post with args' );
		}

		static::update_post( $args );

		static::default_file_handler( $post_id );
		return $args;
	}

	private static function default_post_status() {
		return MICROPUB_DRAFT_MODE ? 'draft' : 'publish';
	}

	private static function post_status( $mf2 ) {
		$props = $mf2['properties'];
		// If both are not set immediately return
		if ( ! isset( $props['post-status'] ) && ! isset( $props['visibility'] ) ) {
			return self::default_post_status();
		}
		if ( isset( $props['visibility'] ) ) {
			$visibilitylist = array( array( 'private' ), array( 'public' ) );
			if ( ! in_array( $props['visibility'], $visibilitylist, true ) ) {
				// Returning null will cause the server to return a 400 error
				return null;
			}
			if ( array( 'private' ) === $props['visibility'] ) {
				return 'private';
			}
		}
		if ( isset( $props['post-status'] ) ) {
			//  According to the proposed specification these are the only two properties supported.
			// https://indieweb.org/Micropub-extensions#Post_Status
			// For now these are the only two we will support even though WordPress defaults to 8 and allows custom
			// But makes it easy to change
			$statuslist = array( array( 'published' ), array( 'draft' ) );
			if ( ! in_array( $props['post-status'], $statuslist, true ) ) {
				// Returning null will cause the server to return a 400 error
				return null;
			}
			// Map published to the WordPress property publish.
			if ( array( 'published' ) === $props['post-status'] ) {
				return 'publish';
			}
			return 'draft';
		}

		// If visibility is public and no post-status is specified,
		// return the default post status value.
		return self::default_post_status();
	}

	/**
	 * Generates a suggestion for a title based on mf2 properties.
	 * This can be used to generate a post slug
	 * $mf2 MF2 Properties
	 *
	 */
	private static function suggest_post_title( $mf2 ) {
		$props = mp_get( $mf2, 'properties' );
		if ( isset( $props['name'] ) ) {
			return $props['name'];
		}
		return apply_filters( 'micropub_suggest_title', '', $props );
	}

	/**
	 * Converts Micropub create, update, or delete request to args for WordPress
	 * wp_insert_post() or wp_update_post().
	 *
	 * For updates, reads the existing post and starts with its data:
	 *  'replace' properties are replaced
	 *  'add' properties are added. the new value in $args will contain both the
	 *    existing and new values.
	 *  'delete' properties are set to NULL
	 *
	 * Uses $input, so load_input() must be called before this.
	 */
	private static function mp_to_wp( $mf2 ) {
		$props = mp_get( $mf2, 'properties' );
		$args  = array();

		foreach ( array(
			'mp-slug' => 'post_name',
			'name'    => 'post_title',
			'summary' => 'post_excerpt',
		) as $mf => $wp ) {
			if ( isset( $props[ $mf ] ) ) {
				$args[ $wp ] = static::get( $props[ $mf ], 0 );
			}
		}

		// perform these functions only for creates
		if ( ! isset( $args['ID'] ) && ! isset( $args['post_name'] ) ) {
			$slug = static::suggest_post_title( $mf2 );
			if ( ! empty( $slug ) ) {
				$args['post_name'] = $slug;
			}
		}
		if ( isset( $args['post_name'] ) ) {
			if ( is_array( $args['post_name'] ) ) {
				$args['post_name'] = array_key_first( $args['post_name'] );
			}
			$args['post_name'] = sanitize_title( $args['post_name'] );
		}

		if ( isset( $props['published'] ) ) {
			$date = new DateTime( $props['published'][0] );
			// If for whatever reason the date cannot be parsed do not include one which defaults to now
			if ( $date ) {
				$wptz = wp_timezone();
				$tz   = $date->getTimezone();
				$date->setTimeZone( $wptz );
				// Pass this argument to the filter for use
				$args['timezone']  = $tz->getName();
				$args['post_date'] = $date->format( 'Y-m-d H:i:s' );
				$date->setTimeZone( new DateTimeZone( 'GMT' ) );
				$args['post_date_gmt'] = $date->format( 'Y-m-d H:i:s' );
			}
		}

		if ( isset( $props['updated'] ) ) {
			$date = new DateTime( $props['updated'][0] );
			// If for whatever reason the date cannot be parsed do not include one which defaults to now
			if ( $date ) {
				$wptz = wp_timezone();
				$date->setTimeZone( $wptz );
				$tz = $date->getTimezone();
				// Pass this argument to the filter for use
				$args['timezone']      = $tz->getName();
				$args['post_modified'] = $date->format( 'Y-m-d H:i:s' );
				$date->setTimeZone( new DateTimeZone( 'GMT' ) );
				$args['post_modified_gmt'] = $date->format( 'Y-m-d H:i:s' );
			}
		}

		// Map micropub categories to WordPress categories if they exist, otherwise
		// to WordPress tags.
		if ( isset( $props['category'] ) && is_array( $props['category'] ) ) {
			$args['post_category'] = array();
			$args['tags_input']    = array();
			foreach ( $props['category'] as $mp_cat ) {
				$wp_cat = get_category_by_slug( $mp_cat );
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
							htmlspecialchars( $content['value'] );
			} elseif ( $content ) {
				$args['post_content'] = htmlspecialchars( $content );
			}
		}
		return $args;
	}

	/**
	 * Handles Photo Upload.
	 *
	 */
	public static function default_file_handler( $post_id ) {
		foreach ( array( 'photo', 'video', 'audio', 'featured' ) as $field ) {
			$props   = mp_get( static::$input, 'properties' );
			$att_ids = array();

			if ( isset( static::$files[ $field ] ) || isset( $props[ $field ] ) ) {
				if ( isset( static::$files[ $field ] ) ) {
					$files = static::$files[ $field ];
					if ( is_array( $files['name'] ) ) {
						$files = Micropub_Media::file_array( $files );
						foreach ( $files as $file ) {
							$att_ids[] = static::check_error(
								Micropub_Media::media_handle_upload( $file, $post_id )
							);
						}
					} else {
						$att_ids[] = static::check_error(
							Micropub_Media::media_handle_upload( $files, $post_id )
						);
					}
				} elseif ( isset( $props[ $field ] ) ) {
					foreach ( $props[ $field ] as $val ) {
						$url       = is_array( $val ) ? $val['value'] : $val;
						$desc      = is_array( $val ) ? $val['alt'] : null;
						$att_ids[] = static::check_error(
							Micropub_Media::media_sideload_url(
								$url,
								$post_id,
								$desc
							)
						);
					}
				}

				$att_urls = array();
				foreach ( $att_ids as $id ) {
					if ( is_micropub_error( $id ) ) {
						return $id;
					}
					// There should only be one of these.
					if ( 'featured' === $field ) {
						set_post_thumbnail( $post_id, $id );
					}
					$att_urls[] = wp_get_attachment_url( $id );
				}
				// Add to the input so will be visible to the after_micropub action
				if ( ! isset( static::$input['properties'][ $field ] ) ) {
					static::$input['properties'][ $field ] = $att_urls;
				} else {
					static::$input['properties'][ $field ] = array_merge( static::$input['properties'][ $field ], $att_urls );
				}
				add_post_meta( $post_id, 'mf2_' . $field, $att_urls, true );
			}
		}
	}

	/**
	 * Stores geodata in WordPress format.
	 *
	 * Reads from the location and checkin properties. checkin isn't an official
	 * mf2 property yet, but OwnYourSwarm sends it:
	 * https://ownyourswarm.p3k.io/docs#checkins
	 *
	 * WordPress geo data is stored in post meta: geo_address (free text),
	 * geo_latitude, geo_longitude, and geo_public:
	 * https://codex.wordpress.org/Geodata
	 * It is noted that should the HTML5 style geolocation properties of altitude, accuracy, speed, and heading are
	 * used they would use the same geo prefix. Simple Location stores these when available using accuracy to estimate
	 * map zoom when displayed.
	 */
	public static function store_geodata( $args ) {
		$properties = static::get( static::$input, 'properties' );
		$location   = static::get( $properties, 'location', static::get( $properties, 'checkin' ) );
		$location   = static::get( $location, 0, $location );

		// Location-visibility is an experimental property https://indieweb.org/Micropub-extensions#Location_Visibility
		// It attempts to mimic the geo_public property
		$visibility = static::get( $properties, 'location-visibility', null );
		if ( $visibility ) {
			$visibility = array_pop( $visibility );
			if ( ! isset( $args['meta_input'] ) ) {
				$args['meta_input'] = array();
			}
			switch ( $visibility ) {
				// Currently supported by https://github.com/dshanske/simple-location as part of the Geodata store noted in codex link above
				// Public indicates coordinates, map, and textual description displayed
				case 'public':
					$args['meta_input']['geo_public'] = 1;
					break;
				// Private indicates no display
				case 'private':
					$args['meta_input']['geo_public'] = 0;
					break;
				// Protected which is not in the original geodata spec is used by Simple Location to indicate textual description only
				case 'protected':
					$args['meta_input']['geo_public'] = 2;
					break;
				default:
					return new WP_Micropub_Error( 'invalid_request', sprintf( 'unsupported location visibility %1$s', $visibility ), 400 );

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
						mp_get( $props, 'name', array(), true ),
						mp_get( $props, 'street-address', array(), true ),
						mp_get( $props, 'locality', array(), true ),
						mp_get( $props, 'region', array(), true ),
						mp_get( $props, 'postal-code', array(), true ),
						mp_get( $props, 'country-name', array(), true ),
					);
					$parts = array_filter( $parts );
					if ( ! empty( $parts ) ) {
						$args['meta_input']['geo_address'] = implode(
							', ',
							array_filter(
								$parts,
								function ( $v ) {
									return $v;
								}
							)
						);
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
	 * Parse a GEO URI into an mf2 object for storage
	 */
	public static function parse_geo_uri( $uri ) {
		if ( ! is_string( $uri ) ) {
			return $uri;
		}
		// Ensure this is a geo uri
		if ( 'geo:' !== substr( $uri, 0, 4 ) ) {
			return $uri;
		}
		$properties = array();
		// Geo URI format:
		// http://en.wikipedia.org/wiki/Geo_URI#Example
		// https://indieweb.org/Micropub#h-entry
		//
		// e.g. geo:37.786971,-122.399677;u=35
		$geo                     = str_replace( 'geo:', '', urldecode( $uri ) );
		$geo                     = explode( ';', $geo );
		$coords                  = explode( ',', $geo[0] );
		$properties['latitude']  = array( trim( $coords[0] ) );
		$properties['longitude'] = array( trim( $coords[1] ) );
		// Geo URI optionally allows for altitude to be stored as a third csv
		if ( isset( $coords[2] ) ) {
			$properties['altitude'] = array( trim( $coords[2] ) );
		}
		// Store additional parameters
		array_shift( $geo ); // Remove coordinates to check for other parameters
		foreach ( $geo as $g ) {
			$g = explode( '=', $g );
			if ( 'u' === $g[0] ) {
				$g[0] = 'accuracy';
			}
			$properties[ $g[0] ] = array( $g[1] );
		}
		// If geo URI is overloaded h-card... e.g. geo:37.786971,-122.399677;u=35;h=card;name=Home;url=https://example.com
		if ( array_key_exists( 'h', $properties ) ) {
			$type = array( 'h-' . $properties['h'][0] );
			unset( $properties['h'] );
		} else {
			$diff = array_diff(
				array_keys( $properties ),
				array( 'longitude', 'latitude', 'altitude', 'accuracy' )
			);
			// If empty that means this is a geo
			if ( empty( $diff ) ) {
				$type = array( 'h-geo' );
			} else {
				$type = array( 'h-card' );
			}
		}

		return array(
			'type'       => $type,
			'properties' => array_filter( $properties ),
		);
	}

	/**
	 * Store the return of the authorization endpoint as post metadata.
	 */
	public static function store_micropub_auth_response( $args ) {
		$micropub_auth_response = static::$micropub_auth_response;
		if ( $micropub_auth_response || ( is_assoc_array( $micropub_auth_response ) ) ) {
			$args['meta_input']                                = mp_get( $args, 'meta_input' );
			$args['meta_input']['micropub_auth_response']      = wp_array_slice_assoc( $micropub_auth_response, array( 'client_id', 'client_name', 'client_icon', 'uuid' ) );
			$args['meta_input']['micropub_version']['version'] = micropub_get_plugin_version();
		}
		return $args;
	}

	/**
	 * Store properties as post metadata. Details:
	 * https://indiewebcamp.com/WordPress_Data#Microformats_data
	 *
	 * Uses $input, so load_input() must be called before this.
	 *
	 * If the request is a create, this populates $args['meta_input']. If the
	 * request is an update, it changes the post meta values in the db directly.
	 */
	public static function store_mf2( $args ) {
		// Properties that map to WordPress properties.
		$excludes = array( 'name', 'published', 'updated', 'summary', 'content', 'visibility' );
		$props    = mp_get( static::$input, 'properties', false );
		if ( ! isset( $args['ID'] ) && $props ) {
			$args['meta_input'] = mp_get( $args, 'meta_input' );
			$type               = mp_get( static::$input, 'type' );
			if ( $type ) {
				$args['meta_input']['mf2_type'] = $type;
			}
			if ( isset( $args['timezone'] ) ) {
				$args['meta_input']['geo_timezone'] = $args['timezone'];
			}
			foreach ( $props as $key => $val ) {
				// mp- entries are commands not properties and are therefore not stored.
				if ( 'mp-' !== substr( $key, 0, 3 ) && ! in_array( $key, $excludes, true ) ) {
					$args['meta_input'][ 'mf2_' . $key ] = $val;
				}
			}
			return $args;
		}

		$replace = static::get( static::$input, 'replace', null );
		if ( $replace ) {
			foreach ( $replace as $prop => $val ) {
				update_post_meta( $args['ID'], 'mf2_' . $prop, $val );
			}
		}

		$meta = get_post_meta( $args['ID'] );
		$add  = static::get( static::$input, 'add', null );
		if ( $add ) {
			foreach ( $add as $prop => $val ) {
				$key = 'mf2_' . $prop;
				if ( array_key_exists( $key, $meta ) ) {
					$cur = $meta[ $key ][0] ? unserialize( $meta[ $key ][0] ) : array();
					update_post_meta( $args['ID'], $key, array_merge( $cur, $val ) );
				} else {
					update_post_meta( $args['ID'], $key, $val );
				}
			}
		}

		$delete = static::get( static::$input, 'delete', null );
		if ( $delete ) {
			if ( is_assoc_array( $delete ) ) {
				foreach ( $delete as $prop => $to_delete ) {
					$key = 'mf2_' . $prop;
					if ( isset( $meta[ $key ] ) ) {
						$existing = unserialize( $meta[ $key ][0] );
						update_post_meta(
							$args['ID'],
							$key,
							array_diff( $existing, $to_delete )
						);
					}
				}
			} else {
				foreach ( $delete as $_ => $prop ) {
					delete_post_meta( $args['ID'], 'mf2_' . $prop );
					if ( 'location' === $prop ) {
						delete_post_meta( $args['ID'], 'geo_latitude' );
						delete_post_meta( $args['ID'], 'geo_longitude' );
					}
				}
			}
		}

		return $args;
	}

	/* Takes form encoded input and converts to json encoded input */
	public static function form_to_json( $data ) {
		$input = array();
		foreach ( $data as $key => $val ) {
			if ( 'action' === $key || 'url' === $key ) {
				$input[ $key ] = $val;
			} elseif ( 'h' === $key ) {
				$input['type'] = array( 'h-' . $val );
			} elseif ( 'access_token' === $key ) {
				continue;
			} else {
				$input['properties']         = mp_get( $input, 'properties' );
				$input['properties'][ $key ] =
				( is_array( $val ) && wp_is_numeric_array( $val ) )
				? $val : array( $val );
			}
		}
		return $input;
	}

	/* Ensures JSON is compliant with the Micropub JSON Syntax */
	public static function normalize_json( $data ) {
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
