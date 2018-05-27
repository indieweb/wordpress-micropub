<?php
/**
 * Plugin Name: Micropub
 * Plugin URI: https://github.com/snarfed/wordpress-micropub
 * Description: <a href="https://indiewebcamp.com/micropub">Micropub</a> server.
 * Protocol spec: <a href="https://www.w3.org/TR/micropub/">w3.org/TR/micropub</a>
 * Author: Ryan Barrett
 * Author URI: https://snarfed.org/
 * Text Domain: micropub
 * Version: 1.4.3
 */

/* See README for supported filters and actions.
 * Example command lines for testing:
 * Form-encoded:
 * curl -i -H 'Authorization: Bearer ...' -F h=entry -F name=foo -F content=bar \
 *   -F photo=@gallery/snarfed.gif 'http://localhost/w/?micropub=endpoint'
 * JSON:
 * curl -v -d @body.json -H 'Content-Type: application/json' 'http://localhost/w/?micropub=endpoint'
 *
 * To generate an access token for testing:
 * 1. Open this in a browser, filling in SITE:
 *   https://indieauth.com/auth?me=SITE&scope=post&client_id=https://wordpress.org/plugins/micropub/&redirect_uri=https%3A%2F%2Findieauth.com%2Fsuccess
 * 2. Log in.
 * 3. Extract the code param from the URL.
 * 4. Run this command line, filling in CODE and SITE (which logged into IndieAuth):
 *   curl -i -d 'code=CODE&me=SITE&client_id=indieauth&redirect_uri=https://indieauth.com/success' 'https://tokens.indieauth.com/token'
 * 5. Extract the access_token parameter from the response body.
 */

/* For debugging purposes this will bypass Micropub authentication
 * in favor of WordPress authentication
 * Using this to test querying(q=) parameters quickly
 */
if ( ! defined( 'MICROPUB_LOCAL_AUTH' ) ) {
	define( 'MICROPUB_LOCAL_AUTH', '0' );
}

// Allows for a custom Authentication and Token Endpoint
if ( ! defined( 'MICROPUB_AUTHENTICATION_ENDPOINT' ) ) {
	define( 'MICROPUB_AUTHENTICATION_ENDPOINT', 'https://indieauth.com/auth' );
}
if ( ! defined( 'MICROPUB_TOKEN_ENDPOINT' ) ) {
	define( 'MICROPUB_TOKEN_ENDPOINT', 'https://tokens.indieauth.com/token' );
}

// For debugging purposes this will set all Micropub posts to Draft
if ( ! defined( 'MICROPUB_DRAFT_MODE' ) ) {
	define( 'MICROPUB_DRAFT_MODE', '0' );
}

add_action( 'plugins_loaded', array( 'Micropub_Plugin', 'init' ) );

/**
 * Micropub Plugin Class
 */
class Micropub_Plugin {
	// associative array
	public static $request_headers;

	// associative array, read from JSON or form-encoded input. populated by load_input().
	protected static $input;

	// associative array, populated by authorize().
	protected static $micropub_auth_response;

	// Array of Scopes
	protected static $scopes;

	/**
	 * Initialize the plugin.
	 */
	public static function init() {
		$cls = get_called_class();

		add_action( 'admin_init', array( $cls, 'admin_init' ) );

		// register endpoint
		// (I originally used add_rewrite_endpoint() to serve on /micropub instead
		// of ?micropub=endpoint, but that had problems. details in
		// https://github.com/snarfed/wordpress-micropub/commit/d3bdc433ee019d3968be6c195b0384cba5ffe36b#commitcomment-9690066 )
		add_filter( 'query_vars', array( $cls, 'query_var' ) );
		add_action( 'parse_query', array( $cls, 'parse_query' ) );

		// endpoint discovery
		add_action( 'wp_head', array( $cls, 'micropub_html_header' ), 99 );
		add_action( 'send_headers', array( $cls, 'micropub_http_header' ) );
		add_filter( 'host_meta', array( $cls, 'micropub_jrd_links' ) );
		add_filter( 'webfinger_user_data', array( $cls, 'micropub_jrd_links' ) );

		// Disable adding headers if local auth is set
		if ( MICROPUB_LOCAL_AUTH || ! class_exists( 'IndieAuth_Plugin' ) ) {
			add_action( 'wp_head', array( $cls, 'indieauth_html_header' ), 99 );
			add_action( 'send_headers', array( $cls, 'indieauth_http_header' ) );
			add_filter( 'host_meta', array( $cls, 'indieauth_jrd_links' ) );
			add_filter( 'webfinger_user_data', array( $cls, 'indieauth_jrd_links' ) );
		}
		// Post Content Filter
		add_filter( 'micropub_post_content', array( $cls, 'generate_post_content' ), 1, 2 );

		// Register Setting
		register_setting(
			'writing', // Option Group Writing
			'micropub_default_post_status', // Setting Name
			array(
				'type'              => 'string',
				'description'       => 'Default Post Status for Micropub Server',
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => false,
				'default'           => static::default_post_status(),
			)
		);
	}

	public static function admin_init() {
		$cls = get_called_class();
		add_settings_field(
			'micropub_writing_settings',
			__( 'Default Status for Micropub Posts', 'micropub' ),
			array( $cls, 'writing_settings' ),
			'writing',
			'default'
		);
	}

	public static function writing_settings() {
		load_template( plugin_dir_path( __FILE__ ) . 'templates/micropub-writing-settings.php' );
	}

	public static function get( $array, $key, $default = array() ) {
		if ( is_array( $array ) ) {
			return isset( $array[ $key ] ) ? $array[ $key ] : $default;
		}
		return $default;
	}

	/**
	 * Adds some query vars
	 *
	 * @param array $vars
	 * @return array
	 */
	public static function query_var( $vars ) {
		$vars[] = 'micropub';
		return $vars;
	}

	/**
	 * Parse the micropub request and render the document
	 *
	 * @param WP $wp WordPress request context
	 *
	 * @uses apply_filter() Calls 'before_micropub' on the default request
	 */
	public static function parse_query( $wp ) {
		if ( ! array_key_exists( 'micropub', $wp->query_vars ) ) {
			return;
		}

		static::load_input();
		if ( WP_DEBUG ) {
			error_log(
				'Micropub Data: ' . wp_json_encode( $_GET ) . ' ' .
					wp_json_encode( static::$input )
			);
		}
		static::$input = apply_filters( 'before_micropub', static::$input );

		// Be able to bypass Micropub auth with other auth
		if ( MICROPUB_LOCAL_AUTH || class_exists( 'IndieAuth_Plugin' ) ) {
			$user_id = get_current_user_id();

			// The WordPress IndieAuth plugin uses filters for this
			static::$scopes = apply_filters( 'indieauth_scopes', static::$scopes );
			static::$micropub_auth_response = apply_filters( 'indieauth_response',  static::$micropub_auth_response );
			
			if ( ! $user_id ) {
				static::handle_authorize_error( 401, 'Unauthorized' );
			}
		} else {
			$user_id = static::authorize();
		}

		if ( 'GET' === $_SERVER['REQUEST_METHOD'] && isset( static::$input['q'] ) ) {
			static::query_handler( $user_id );
		} elseif ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			self::post_handler( $user_id );
		} else {
			static::error( 400, 'Unknown Micropub request' );
		}
	}

	/**
	 * Validate the access token at the token endpoint.
	 *
	 * https://indieauth.spec.indieweb.org/#access-token-verification
	 * If the token is valid, returns the user id to use as the post's author, or
	 * NULL if the token only matched the site URL and no specific user.
	 */
	private static function authorize() {
		// find the access token
		$auth  = static::get_header( 'authorization' );
		$token = self::get( $_POST, 'access_token' );
		if ( ! $auth && ! $token ) {
			static::handle_authorize_error( 401, 'missing access token' );
		}

		$resp = wp_remote_get(
			get_option( 'indieauth_token_endpoint', MICROPUB_TOKEN_ENDPOINT ), array(
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => $auth ?: 'Bearer ' . $token,
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			static::handle_authorize_error( 502, "couldn't validate token: " . implode( ' , ', $resp->get_error_messages() ) );
		}

		$code           = wp_remote_retrieve_response_code( $resp );
		$body           = wp_remote_retrieve_body( $resp );
		$params         = json_decode( $body, true );
		static::$scopes = explode( ' ', $params['scope'] );

		if ( (int) ( $code / 100 ) !== 2 ) {
			static::handle_authorize_error(
				$code, 'invalid access token: ' . $body
			);
		} elseif ( empty( static::$scopes ) ) {
			static::handle_authorize_error(
				401, 'access token is missing scope'
			);
		}

		$me = untrailingslashit( $params['me'] );

		static::$micropub_auth_response = $params;

		// look for a user with the same url as the token's `me` value.
		$user = static::user_url( $me );
		if ( $user ) {
			return $user;
		}

		// no user with that url. if the token is for this site itself, allow it and
		// post as the default user
		$home = untrailingslashit( home_url() );
		if ( $home !== $me ) {
			static::handle_authorize_error(
				401, 'access token URL ' . $me . " doesn't match site " . $home . ' or any user'
			);
		}

		return null;
	}

	/**
	 * Check scope
	 *
	 * @param string $scope
	 *
	 * @return boolean
	**/
	protected static function check_scope( $scope ) {
		if ( in_array( 'post', static::$scopes, true ) ) {
			return true;
		}
		return in_array( $scope, static::$scopes, true );
	}

	/**
	 * Try to match a user with a URL with or without trailing slash.
	 *
	 * @param string $me URL to match
	 *
	 * @return null|int Return user ID if matched or null
	**/
	public static function user_url( $me ) {
		if ( ! isset( $me ) ) {
			return null;
		}
		$search = array(
			'search'         => $me,
			'search_columns' => array( 'url' ),
		);
		$users  = get_users( $search );

		$search['search'] = $me . '/';
		$users            = array_merge( $users, get_users( $search ) );
		foreach ( $users as $user ) {
			if ( untrailingslashit( $user->user_url ) === $me ) {
				return $user->ID;
			}
		}
		return null;
	}


	/**
	 * Parse the micropub request and render the document
	 *
	 * @param int $user_id User ID for Authorized User.
	 */
	public static function post_handler( $user_id ) {
		$status = 200;
		$action = self::get( static::$input, 'action', 'create' );
		if ( ! self::check_scope( $action ) ) {
			static::error( 403, sprintf( 'scope insufficient to %1$s posts', $action ) );
		}
		$url = self::get( static::$input, 'url' );

		// check that we support all requested syndication targets
		$synd_supported = apply_filters( 'micropub_syndicate-to', array(), $user_id );
		$uids           = array();
		foreach ( $synd_supported as $syn ) {
			$uids[] = self::get( $syn, 'uid' );
		}
		$properties     = self::get( static::$input, 'properties' );
		$synd_requested = self::get( $properties, 'mp-syndicate-to' );
		$unknown        = array_diff( $synd_requested, $uids );

		if ( $unknown ) {
			static::error( 400, 'Unknown mp-syndicate-to targets: ' . implode( ', ', $unknown ) );

		} elseif ( ! $url || 'create' === $action ) { // create
			if ( $user_id && ! user_can( $user_id, 'publish_posts' ) ) {
				static::error( 403, 'user id ' . $user_id . ' cannot publish posts' );
			}
			$args   = static::create( $user_id );
			$status = 201;
			static::header( 'Location', get_permalink( $args['ID'] ) );

		} elseif ( 'update' === $action || ! $action ) { // update
			if ( $user_id && ! user_can( $user_id, 'edit_posts' ) ) {
				static::error( 403, 'user id ' . $user_id . ' cannot edit posts' );
			}
			$args = static::update();

		} elseif ( 'delete' === $action ) { // delete
			if ( $user_id && ! user_can( $user_id, 'delete_posts' ) ) {
				static::error( 403, 'user id ' . $user_id . ' cannot delete posts' );
			}
			$args = get_post( url_to_postid( $url ), ARRAY_A );
			if ( ! $args ) {
				static::error( 400, $url . ' not found' );
			}
			static::check_error( wp_trash_post( $args['ID'] ) );

		} elseif ( 'undelete' === $action ) { // undelete
			if ( $user_id && ! user_can( $user_id, 'publish_posts' ) ) {
				static::error( 403, 'user id ' . $user_id . ' cannot undelete posts' );
			}
			$found = false;
			// url_to_postid() doesn't support posts in trash, so look for
			// it ourselves, manually.
			// here's another, more complicated way that customizes WP_Query:
			// https://gist.github.com/peterwilsoncc/bb40e52cae7faa0e6efc
			foreach ( get_posts( array( 'post_status' => 'trash' ) ) as $post ) {
				if ( get_the_guid( $post ) === $url ) {
					wp_untrash_post( $post->ID );
					wp_publish_post( $post->ID );
					$found = true;
					$args  = array( 'ID' => $post->ID );
				}
			}
			if ( ! $found ) {
				static::error( 400, 'deleted post ' . $url . ' not found' );
			}

			// unknown action
		} else {
			static::error( 400, 'unknown action ' . $action );
		}
		if ( ! empty( $synd_requested ) ) {
			do_action( 'micropub_syndication', $args['ID'], $synd_requested );
		}
		do_action( 'after_micropub', static::$input, $args );
		static::respond( $status, null, $args );
	}

	/**
	 * Handle queries to the micropub endpoint
	 *
	 * @param int $user_id Authenticated User
	 */
	private static function query_handler( $user_id ) {
		$resp = apply_filters( 'micropub_query', null, static::$input );
		if ( ! $resp ) {
			switch ( static::$input['q'] ) {
				case 'config':
				case 'syndicate-to':
					// return empty syndication target with filter
					$syndicate_tos = apply_filters( 'micropub_syndicate-to', array(), $user_id );
					$resp          = array( 'syndicate-to' => $syndicate_tos );
					break;
				case 'category':
					$resp = array_merge(
						get_tags( array( 'fields' => 'names' ) ),
						get_terms(
							array(
								'taxonomy' => 'category',
								'fields'   => 'names',
							)
						)
					);
					break;
				case 'source':
					$post_id = url_to_postid( static::$input['url'] );
					if ( ! $post_id ) {
						static::error( 400, 'not found: ' . static::$input['url'] );
					}
					$resp  = static::get_mf2( $post_id );
					$props = static::$input['properties'];
					if ( $props ) {
						if ( ! is_array( $props ) ) {
							$props = array( $props );
						}
						$resp = array(
							'properties' => array_intersect_key(
								$resp['properties'], array_flip( $props )
							),
						);
					}
					break;
				default:
					static::error( 400, 'unknown query ' . static::$input['q'] );
			}
		}

		do_action( 'after_micropub', static::$input, null );
		static::respond( 200, $resp );
	}

	/*
	 * Handle a create request.
	 */
	private static function create( $user_id ) {
		$args = static::mp_to_wp( static::$input );
		$args = static::store_micropub_auth_response( $args );

		$post_content = self::get( $args, 'post_content', '' );
		$post_content = apply_filters( 'micropub_post_content', $post_content, static::$input );
		if ( $post_content ) {
			$args['post_content'] = $post_content;
		}

		$args = static::store_mf2( $args );
		$args = static::store_geodata( $args );

		if ( $user_id ) {
			$args['post_author'] = $user_id;
		}
		$args['post_status'] = static::post_status( static::$input );
		if ( ! $args['post_status'] ) {
			static::error( 400, 'Invalid Post Status' );
		}
		if ( WP_DEBUG ) {
			error_log( 'wp_insert_post with args: ' . wp_json_encode( $args ) );
		}

		kses_remove_filters();  // prevent sanitizing HTML tags in post_content
		$args['ID'] = static::check_error( wp_insert_post( $args, true ) );
		kses_init_filters();

		static::default_file_handler( $args['ID'] );
		return $args;
	}

	/*
	 * Handle an update request.
	 *
	 * This really needs a db transaction! But we can't assume the underlying
	 * MySQL db is InnoDB and supports transactions. :(
	 */
	private static function update() {
		$post_id = url_to_postid( static::$input['url'] );
		$args    = get_post( $post_id, ARRAY_A );
		if ( ! $args ) {
			static::error( 400, static::$input['url'] . ' not found' );
		}

		// add
		$add = static::$input['add'];
		if ( $add ) {
			if ( ! is_array( $add ) ) {
				static::error( 400, 'add must be an object' );
			}
			if ( array_diff( array_keys( $add ), array( 'category', 'syndication' ) ) ) {
				static::error( 400, 'can only add to category and syndication; other properties not supported' );
			}
			$add_args = static::mp_to_wp( array( 'properties' => $add ) );
			if ( $add_args['tags_input'] ) {
				// i tried wp_add_post_tags here, but it didn't work
				$args['tags_input'] = array_merge(
					$args['tags_input'] ?: array(),
					$add_args['tags_input']
				);
			}
			if ( $add_args['post_category'] ) {
				// i tried wp_set_post_categories here, but it didn't work
				$args['post_category'] = array_merge(
					$args['post_category'] ?: array(),
					$add_args['post_category']
				);
			}
		}

		// replace
		$replace = static::$input['replace'];
		if ( $replace ) {
			if ( ! is_array( $replace ) ) {
				static::error( 400, 'replace must be an object' );
			}
			foreach ( static::mp_to_wp( array( 'properties' => $replace ) )
					as $name => $val ) {
				$args[ $name ] = $val;
			}
		}

		// delete
		$delete = static::$input['delete'];
		if ( $delete ) {
			if ( is_assoc_array( $delete ) ) {
				if ( array_diff( array_keys( $delete ), array( 'category', 'syndication' ) ) ) {
					static::error( 400, 'can only delete individual values from category and syndication; other properties not supported' );
				}
				$delete_args = static::mp_to_wp( array( 'properties' => $delete ) );
				if ( $delete_args['tags_input'] ) {
					$args['tags_input'] = array_diff(
						$args['tags_input'] ?: array(),
						$delete_args['tags_input']
					);
				}
				if ( $delete_args['post_category'] ) {
					$args['post_category'] = array_diff(
						$args['post_category'] ?: array(),
						$delete_args['post_category']
					);
				}
			} elseif ( is_array( $delete ) ) {
				foreach ( static::mp_to_wp( array( 'properties' => array_flip( $delete ) ) )
					  as $name => $_ ) {
					$args[ $name ] = null;
				}
				if ( in_array( 'category', $delete, true ) ) {
					wp_set_post_tags( $post_id, '', false );
					wp_set_post_categories( $post_id, '' );
				}
			} else {
				static::error( 400, 'delete must be an array or object' );
			}
		}

		// tell WordPress to preserve published date explicitly, otherwise
		// wp_update_post sets it to the current time
		$args['edit_date'] = true;

		// Generate Post Content
		$post_content = self::get( $args, 'post_content', '' );
		$post_content = apply_filters( 'micropub_post_content', $post_content, static::$input );
		if ( $post_content ) {
			$args['post_content'] = $post_content;
		}

		// Store metadata from Microformats Properties
		$args = static::store_mf2( $args );
		$args = static::store_geodata( $args );

		if ( WP_DEBUG ) {
			error_log( 'wp_update_post with args: ' . wp_json_encode( $args ) );
		}

		kses_remove_filters();
		static::check_error( wp_update_post( $args, true ) );
		kses_init_filters();

		static::default_file_handler( $post_id );
		return $args;
	}

	private static function handle_authorize_error( $code, $msg ) {
		$home = untrailingslashit( home_url() );
		if ( 'http://localhost' === $home ) {
			error_log(
				'WARNING: ' . $code . ' ' . $msg .
				". Allowing only because this is localhost.\n"
			);
			return;
		}
		static::respond( $code, $msg );
	}

	private static function default_post_status() {
		return MICROPUB_DRAFT_MODE ? 'draft' : 'publish';
	}

	private static function post_status( $mf2 ) {
		$props = $mf2['properties'];
		// If both are not set immediately return
		if ( ! isset( $props['post-status'] ) && ! isset( $props['visibility'] ) ) {
			$status = get_option( 'micropub_default_post_status', static::default_post_status() );
			if ( ! in_array( $status, array( 'publish', 'draft', 'private' ), true ) ) {
				return static::default_post_status();
			}
			return $status;
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
		// Execution will never reach here
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
		$props = $mf2['properties'];
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
		if ( ! isset( $args['ID'] ) ) {
			if ( isset( $args['post_title'] ) && ! isset( $args['post_name'] ) ) {
				$args['post_name'] = $args['post_title'];
			}
		}
		if ( isset( $args['post_name'] ) ) {
			$args['post_name'] = sanitize_title( $args['post_name'] );
		}

		if ( isset( $props['published'] ) ) {
			$date = new DateTime( $props['published'][0] );
			// If for whatever reason the date cannot be parsed do not include one which defaults to now
			if ( $date ) {
				$tz_string = get_option( 'timezone_string' );
				if ( empty( $tz_string ) ) {
					$tz_string = 'UTC';
				}
				$date->setTimeZone( new DateTimeZone( $tz_string ) );
				$tz = $date->getTimezone();
				// Pass this argument to the filter for use
				$args['timezone']  = $tz->getName();
				$args['post_date'] = $date->format( 'Y-m-d H:i:s' );
				$date->setTimeZone( new DateTimeZone( 'GMT' ) );
				$args['post_date_gmt'] = $date->format( 'Y-m-d H:i:s' );
			}
		}

		// Map micropub categories to WordPress categories if they exist, otherwise
		// to WordPress tags.
		if ( isset( $props['category'] ) ) {
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

		$content = $props['content'][0];
		if ( is_array( $content ) ) {
			$args['post_content'] = $content['html'] ?:
								htmlspecialchars( $content['value'] );
		} elseif ( $content ) {
			$args['post_content'] = htmlspecialchars( $content );
		} elseif ( $props['summary'] ) {
			$args['post_content'] = $props['summary'][0];
		}

		return $args;
	}

	/**
	 * Generates and returns a post_content string suitable for wp_insert_post()
	 * and friends.
	 */
	public static function generate_post_content( $post_content, $input ) {
		$props = $input['replace'] ?: $input['properties'];
		$lines = array();

		$verbs = array(
			'like-of'     => 'Likes',
			'repost-of'   => 'Reposted',
			'in-reply-to' => 'In reply to',
			'bookmark-of' => 'Bookmarked',
		);

		// interactions
		foreach ( array_keys( $verbs ) as $prop ) {
			if ( wp_is_numeric_array( $props[ $prop ] ) ) {
				$val = $props[ $prop ][0];
			} else {
				$val = $props[ $prop ];
			}
			if ( $val ) {
				// Supports nested properties by turning single value properties into nested
				// https://micropub.net/draft/#nested-microformats-objects
				if ( is_string( $val ) ) {
					$val = array(
						'url' => $val,
					);
				}
				if ( ! isset( $val['name'] ) && isset( $val['url'] ) ) {
					$val['name'] = $val['url'];
				}
				if ( isset( $val['url'] ) ) {
					$lines[] = sprintf(
						'<p>%1s <a class="u-%2s" href="%3s">%4s</a>.</p>',
						$verbs[ $prop ],
						$prop, $val['url'],
						$val['name']
					);
				}
			}
		}

		$checkin = isset( $props['checkin'] ) ? $props['checkin'][0] : null;
		if ( $checkin ) {
			$name    = $checkin['properties']['name'][0];
			$urls    = $checkin['properties']['url'];
			$lines[] = '<p>Checked into <a class="h-card p-location" href="' .
				( $urls[1] ?: $urls[0] ) . '">' . $name . '</a>.</p>';
		}

		if ( isset( $props['rsvp'] ) ) {
			$lines[] = '<p>RSVPs <data class="p-rsvp" value="' . $props['rsvp'][0] .
			  '">' . $props['rsvp'][0] . '</data>.</p>';
		}

		// event
		if ( array( 'h-event' ) === static::$input['type'] ) {
			$lines[] = static::generate_event( static::$input );
		}

		// content
		$content = $props['content'][0];
		if ( $content ) {
			$lines[] = '<div class="e-content">';
			if ( is_array( $content ) ) {
				$lines[] = $content['html'] ?: htmlspecialchars( $content['value'] );
			} else {
				$lines[] = htmlspecialchars( $content );
			}
			$lines[] = '</div>';
		}

		// TODO: generate my own markup so i can include u-photo
		foreach ( array( 'photo', 'video', 'audio' ) as $field ) {
			if ( isset( $_FILES[ $field ] ) || isset( $props[ $field ] ) ) {
				$lines[] = '[gallery size=full columns=1]';
				break;
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Generates and returns a string h-event.
	 */
	private static function generate_event( $input ) {
		$props   = $input['replace'] ?: $input['properties'];
		$lines[] = '<div class="h-event">';

		if ( isset( $props['name'] ) ) {
			$lines[] = '<h1 class="p-name">' . $props['name'][0] . '</h1>';
		}

		$lines[] = '<p>';
		$times   = array();
		foreach ( array( 'start', 'end' ) as $cls ) {
			if ( isset( $props[ $cls ][0] ) ) {
				$datetime = iso8601_to_datetime( $props[ $cls ][0] );
				$times[]  = '<time class="dt-' . $cls . '" datetime="' .
					$props[ $cls ][0] . '">' . $datetime . '</time>';
			}
		}
		$lines[] = implode( "\nto\n", $times );

		if ( isset( $props['location'] ) && 'geo:' !== substr( $props['location'][0], 0, 4 ) ) {
			$lines[] = 'at <a class="p-location" href="' . $props['location'][0] . '">' .
			$props['location'][0] . '</a>';
		}

		end( $lines );
		$lines[ key( $lines ) ] .= '.';
		$lines[]                 = '</p>';

		if ( isset( $props['summary'] ) ) {
			$lines[] = '<p class="p-summary">' . urldecode( $props['summary'][0] ) . '</p>';
		}

		if ( isset( $props['description'] ) ) {
			$lines[] = '<p class="p-description">' . urldecode( $props['description'][0] ) . '</p>';
		}

		$lines[] = '</div>';
		return implode( "\n", $lines );
	}

	/**
	 * Handles Photo Upload.
	 *
	 */
	public static function default_file_handler( $post_id ) {
		foreach ( array( 'photo', 'video', 'audio' ) as $field ) {
			$props   = static::$input['properties'];
			$att_ids = array();

			if ( isset( $_FILES[ $field ] ) || isset( $props[ $field ] ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/media.php';

				if ( isset( $_FILES[ $field ] ) ) {
					$overrides = array(
						'action'    => 'allow_file_outside_uploads_dir',
						'test_form' => false,
					);

					$files = $_FILES[ $field ];
					if ( is_array( $files['name'] ) ) {
						$count = count( $files['name'] );
						for ( $i = 0; $i < $count; ++$i ) {
							$_FILES = array(
								$field => array(
									'name'     => $files['name'][ $i ],
									'tmp_name' => $files['tmp_name'][ $i ],
									// 'type' => $files['type'][ $i ],
									// 'error' => $files['error'][ $i ],
									'size'     => $files['size'][ $i ],
								),
							);
							$att_ids[] = static::check_error(
								media_handle_upload(
									$field, $post_id, array(), $overrides
								)
							);
						}
					} else {
						$att_ids[] = static::check_error(
							media_handle_upload(
								$field, $post_id, array(), $overrides
							)
						);
					}
				} elseif ( isset( $props[ $field ] ) ) {
					foreach ( $props[ $field ] as $val ) {
						$url       = is_array( $val ) ? $val['value'] : $val;
						$filename  = static::check_error( static::download_url( $url ) );
						$file      = array(
							'name'     => basename( $url ),
							'tmp_name' => $filename,
							'size'     => filesize( $filename ),
						);
						$desc      = is_array( $val ) ? $val['alt'] : $file['name'];
						$att_ids[] = static::check_error(
							media_handle_sideload(
								$file, $post_id, $desc
							)
						);
					}
				}

				$att_urls = array();
				foreach ( $att_ids as $id ) {
					$att_urls[] = wp_get_attachment_url( $id );
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
		$location   = static::get( $location, 0, null );
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
					static::error( 400, 'unsupported location visibility ' . $visibility );

			}
		}
		if ( $location ) {
			if ( ! isset( $args['meta_input'] ) ) {
				$args['meta_input'] = array();
			}
			if ( is_array( $location ) ) {
				$props = $location['properties'];
				if ( isset( $props['geo'] ) ) {
					$args['meta_input']['geo_address'] = $props['label'][0];
					$props                             = $props['geo'][0]['properties'];
				} else {
					$parts                             = array(
						$props['name'][0],
						$props['street-address'][0],
						$props['locality'][0],
						$props['region'][0],
						$props['postal-code'][0],
						$props['country-name'][0],
					);
					$args['meta_input']['geo_address'] = implode(
						', ', array_filter(
							$parts, function( $v ) {
								return $v;
							}
						)
					);
				}
				$args['meta_input']['geo_latitude']  = $props['latitude'][0];
				$args['meta_input']['geo_longitude'] = $props['longitude'][0];
				$args['meta_input']['geo_altitude']  = $props['altitude'][0];
			} elseif ( 'geo:' === substr( $location, 0, 4 ) ) {
				// Geo URI format:
				// http://en.wikipedia.org/wiki/Geo_URI#Example
				// https://indieweb.org/Micropub#h-entry
				//
				// e.g. geo:37.786971,-122.399677;u=35
				$geo = explode( ':', substr( urldecode( $location ), 4 ) );
				$geo = explode( ';', $geo[0] );
				// Store the accuracy/uncertainty
				$args['meta_input']['geo_accuracy']  = substr( $geo[1], 2 );
				$coords                              = explode( ',', $geo[0] );
				$args['meta_input']['geo_latitude']  = trim( $coords[0] );
				$args['meta_input']['geo_longitude'] = trim( $coords[1] );
				// Geo URI optionally allows for altitude to be stored as a third csv
				if ( isset( $coords[2] ) ) {
					$args['meta_input']['geo_altitude'] = trim( $coords[2] );
				}
			} elseif ( 'http' !== substr( $location, 0, 4 ) ) {
				$args['meta_input']['geo_address'] = $location;
			}
		}
		return $args;
	}

	/**
	 * Store the return of the authorization endpoint as post metadata. Details:
	 * https://tokens.indieauth.com/
	 */
	public static function store_micropub_auth_response( $args ) {
		$micropub_auth_response = static::$micropub_auth_response;
		if ( $micropub_auth_response || ( is_assoc_array( $micropub_auth_response ) ) ) {
			$args['meta_input']                           = self::get( $args, 'meta_input' );
			$args['meta_input']['micropub_auth_response'] = $micropub_auth_response;
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
		$props = static::$input['properties'];
		if ( ! isset( $args['ID'] ) && $props ) {
			$args['meta_input'] = self::get( $args, 'meta_input' );
			$type               = static::$input['type'];
			if ( $type ) {
				$args['meta_input']['mf2_type'] = $type;
			}
			foreach ( $props as $key => $val ) {
				$args['meta_input'][ 'mf2_' . $key ] = $val;
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
				$cur = $meta[ $key ][0] ? unserialize( $meta[ $key ][0] ) : array();
				update_post_meta( $args['ID'], $key, array_merge( $cur, $val ) );
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
							$args['ID'], $key,
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

	/**
	 * Returns the mf2 properties for a post.
	 */
	public static function get_mf2( $post_id ) {
		$mf2 = array();

		foreach ( get_post_meta( $post_id ) as $field => $val ) {
			$val = unserialize( $val[0] );
			if ( 'mf2_type' === $field ) {
				$mf2['type'] = $val;
			} elseif ( 'mf2_' === substr( $field, 0, 4 ) ) {
				$mf2['properties'][ substr( $field, 4 ) ] = $val;
			}
		}

		return $mf2;
	}

	public static function error( $code, $message ) {
		static::respond(
			$code, array(
				'error'             => ( 403 === $code ) ? 'forbidden' :
							   ( 401 === $code ) ? 'insufficient_scope' :
							   'invalid_request',
				'error_description' => $message,
			)
		);
	}

	private static function check_error( $result ) {
		if ( ! $result ) {
			static::error( 400, $result );
		} elseif ( is_wp_error( $result ) ) {
			static::error( 400, $result->get_error_message() );
		}
		return $result;
	}

	/**
	 * The micropub autodicovery meta tags
	 */
	public static function micropub_html_header() {
		printf( '<link rel="micropub" href="%s" />' . PHP_EOL, site_url( '?micropub=endpoint' ) );
	}

	public static function indieauth_html_header() {
		printf( '<link rel="authorization_endpoint" href="%s" />' . PHP_EOL, get_option( 'indieauth_authorization_endpoint', MICROPUB_AUTHENTICATION_ENDPOINT ) );
		printf( '<link rel="token_endpoint" href="%s" />' . PHP_EOL, get_option( 'indieauth_token_endpoint', MICROPUB_TOKEN_ENDPOINT ) );
	}

	/**
	 * The micropub autodicovery http-header
	 */
	public static function micropub_http_header() {
		static::header( 'Link', '<' . site_url( '?micropub=endpoint' ) . '>; rel="micropub"' );
	}

	public static function indieauth_http_header() {
		static::header( 'Link', '<' . get_option( 'indieauth_authorization_endpoint', MICROPUB_AUTHENTICATION_ENDPOINT ) . '>; rel="authorization_endpoint"' );
		static::header( 'Link', '<' . get_option( 'indieauth_token_endpoint', MICROPUB_TOKEN_ENDPOINT ) . '>; rel="token_endpoint"' );
	}

	/**
	 * Generates webfinger/host-meta links
	 */
	public static function micropub_jrd_links( $array ) {
		$array['links'][] = array(
			'rel'  => 'micropub',
			'href' => site_url( '?micropub=endpoint' ),
		);
		return $array;
	}

	public static function indieauth_jrd_links( $array ) {
		$array['links'][] = array(
			'rel'  => 'authorization_endpoint',
			'href' => get_option( 'indieauth_authorization_endpoint', MICROPUB_AUTHENTICATION_ENDPOINT ),
		);
		$array['links'][] = array(
			'rel'  => 'token_endpoint',
			'href' => get_option( 'indieauth_token_endpoint', MICROPUB_TOKEN_ENDPOINT ),
		);

		return $array;
	}

	protected static function load_input() {
		$content_type = explode( ';', static::get_header( 'Content-Type' ) );
		$content_type = $content_type[0];
		if ( 'GET' === $_SERVER['REQUEST_METHOD'] ) {
			static::$input = $_GET;
		} elseif ( 'application/json' === $content_type ) {
			static::$input = json_decode( static::read_input(), true );
		} elseif ( ! $content_type ||
			   'application/x-www-form-urlencoded' === $content_type ||
			   'multipart/form-data' === $content_type ) {
			static::$input = array();
			foreach ( $_POST as $key => $val ) {
				if ( 'action' === $key || 'url' === $key ) {
					static::$input[ $key ] = $val;
				} elseif ( 'h' === $key ) {
					static::$input['type'] = array( 'h-' . $val );
				} elseif ( 'access_token' === $key ) {
					continue;
				} else {
					static::$input['properties']         = self::get( static::$input, 'properties' );
					static::$input['properties'][ $key ] =
					( is_array( $val ) && ! is_assoc_array( $val ) )
					? $val : array( $val );
				}
			}
		} else {
			static::error( 400, 'unsupported content type ' . $content_type );
		}
	}

	/** Wrappers for WordPress/PHP functions so we can mock them for unit tests.
	 **/
	protected static function read_input() {
		return file_get_contents( 'php://input' );
	}

	protected static function respond( $code, $resp = null, $args = null ) {
		status_header( $code );
		static::header( 'Content-Type', 'application/json' );
		exit( $resp ? wp_json_encode( $resp ) : '' );
	}

	public static function header( $header, $value ) {
		header( $header . ': ' . $value, false );
	}

	protected static function get_header( $name ) {
		if ( ! static::$request_headers ) {
			$headers                 = getallheaders();
			static::$request_headers = array();
			foreach ( $headers as $key => $value ) {
				static::$request_headers[ strtolower( $key ) ] = $value;
			}
		}
		return static::$request_headers[ strtolower( $name ) ];
	}

	protected static function download_url( $url ) {
		return static::check_error( download_url( $url ) );
	}
}


function is_assoc_array( $array ) {
	return is_array( $array ) && array_values( $array ) !== $array;
}


	// blatantly stolen from https://github.com/idno/Known/blob/master/Idno/Pages/File/View.php#L25
if ( ! function_exists( 'getallheaders' ) ) {
	function getallheaders() {
		$headers = array();
		foreach ( $_SERVER as $name => $value ) {
			if ( 'HTTP_' === substr( $name, 0, 5 ) ) {
				$headers[ str_replace( ' ', '-', strtolower( str_replace( '_', ' ', substr( $name, 5 ) ) ) ) ] = $value;
			} elseif ( 'CONTENT_TYPE' === $name ) {
				$headers['content-type'] = $value;
			} elseif ( 'CONTENT_LENGTH' === $name ) {
				$headers['content-length'] = $value;
			}
		}
		return $headers;
	}
}
