<?php
/*
 Plugin Name: Micropub
 Plugin URI: https://github.com/snarfed/wordpress-micropub
 Description: <a href="https://indiewebcamp.com/micropub">Micropub</a> server.
 Protocol spec: <a href="https://www.w3.org/TR/micropub/">w3.org/TR/micropub</a>
 Author: Ryan Barrett
 Author URI: https://snarfed.org/
 Version: 0.5
*/

/*
 * New filters:
 *
 * before_micropub: Called before handling a create, update, or delete Micropub
 *   request. Not called for queries or if there's an error.
 *
 *   Arguments: $args, assoc array of arguments passed to wp_insert_post or
 *     wp_update_post. For deletes and undeletes, args['ID'] contains the post
 *     id to be (un)deleted.
 *
 * after_micropub: Same as before_micropub, but called after the request is
 *   handled.
 */

// Example command line for testing:
// curl -i -H 'Authorization: Bearer ...' -F h=entry -F name=foo -F content=bar \
//   -F photo=@gallery/snarfed.gif 'http://localhost/w/?micropub=endpoint'
//
// To generate an access token for testing:
// 1. Open this in a browser, filling in SITE:
//   https://indieauth.com/auth?me=SITE&scope=post&client_id=https://wordpress.org/plugins/micropub/&redirect_uri=https%3A%2F%2Findieauth.com%2Fsuccess
// 2. Log in.
// 3. Extract the code param from the URL.
// 4. Run this command line, filling in CODE and SITE (which logged into IndieAuth):
//   curl -i -d 'code=CODE&me=SITE&client_id=indieauth&redirect_uri=https://indieauth.com/success' 'https://tokens.indieauth.com/token'
// 5. Extract the access_token parameter from the response body.

// For debugging purposes this will bypass Micropub authentication
// in favor of WordPress authentication
// Using this to test querying(q=) parameters quickly
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

if ( ! class_exists( 'Micropub' ) ) :

add_action( 'init', array( 'Micropub', 'init' ) );

/**
 * Micropub Plugin Class
 */
class Micropub {
	// associative array
	public static $request_headers;

    // associative array, read from JSON or form-encoded input. populated by
    // load_input().
	protected static $input;

	/**
	 * Initialize the plugin.
	 */
	public static function init() {
		$cls = get_called_class();

		// register endpoint
		// (I originally used add_rewrite_endpoint() to serve on /micropub instead
		// of ?micropub=endpoint, but that had problems. details in
		// https://github.com/snarfed/wordpress-micropub/commit/d3bdc433ee019d3968be6c195b0384cba5ffe36b#commitcomment-9690066 )
		add_filter( 'query_vars', array( $cls, 'query_var' ) );
		add_action( 'parse_query', array( $cls, 'parse_query' ) );

		// endpoint discovery
		add_action( 'wp_head', array( $cls, 'html_header' ), 99 );
		add_action( 'send_headers', array( $cls, 'http_header' ) );
		add_filter( 'host_meta', array( $cls, 'jrd_links' ) );
		add_filter( 'webfinger_data', array( $cls, 'jrd_links' ) );

		add_filter( 'before_micropub', array( $cls, 'store_mf2' ) );
		add_filter( 'before_micropub', array( $cls, 'generate_post_content' ) );
		add_filter( 'before_micropub', array( $cls, 'store_geodata' ) );
		// Sideload any provided photos.
		add_action( 'after_micropub', array( $cls, 'default_file_handler' ) );
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
	 */
	public static function parse_query( $wp ) {
		if ( ! array_key_exists( 'micropub', $wp->query_vars ) ) {
			return;
		}

		static::load_input();
		if ( WP_DEBUG ) {
			error_log( 'Micropub Data: ' . serialize( $_GET ) . ' ' .
					   serialize( static::$input ) );
		}

		// For debug purposes be able to bypass Micropub auth with WordPress auth
		if ( MICROPUB_LOCAL_AUTH ) {
			if ( ! is_user_logged_in() ) {
				auth_redirect();
			}
			$user_id = get_current_user_id();
		} else {
			$user_id = static::authorize();
		}

		if ( $_SERVER['REQUEST_METHOD'] == 'GET' && isset( $_GET['q'] ) ) {
			static::query_handler( $user_id );
		} elseif ( $_SERVER['REQUEST_METHOD'] == 'POST' ) {
			self::post_handler( $user_id );
		} else {
			static::error( 400, 'Unknown Micropub request' );
		}
	}

	/**
	 * Validate the access token at the token endpoint.
	 *
	 * If the token is valid, returns the user id to use as the post's author, or
	 * NULL if the token only matched the site URL and no specific user.
	 */
	private static function authorize() {
		// find the access token
		$auth = $this->get_header( 'authorization' );
		$token = $_POST['access_token'];
		if ( ! $auth_header && ! $token) {
			static::handle_authorize_error( 401, 'missing access token' );
		}

		$resp = wp_remote_get(
			MICROPUB_TOKEN_ENDPOINT, array( 'headers' => array(
				'Content-Type' => 'application/x-www-form-urlencoded',
				'Authorization' => $auth ?: 'Bearer ' . $token,
			) ) );
		$code = wp_remote_retrieve_response_code( $resp );
		$body = wp_remote_retrieve_body( $resp );
		parse_str( $body, $params );
		if ( $code / 100 != 2 ) {
			static::handle_authorize_error(
				$code, 'invalid access token: ' . $body );
		} elseif ( ! isset( $params['scope'] ) ||
				   ! in_array( 'post', explode( ' ', $params['scope'] ) ) ) {
			static::handle_authorize_error(
				403, 'access token is missing post scope; got `' . $params['scope'] . '`' );
		}

		parse_str( $body, $resp );
		$me = untrailingslashit( $resp['me'] );

		// look for a user with the same url as the token's `me` value. search both
		// with and without trailing slash.
		foreach ( array_merge( get_users( array( 'search' => $me ) ),
							   get_users( array( 'search' => $me . '/' ) ) )
				  as $user ) {
			if ( untrailingslashit( $user->user_url ) == $me ) {
				return $user->ID;
			}
		}

		// no user with that url. if the token is for this site itself, allow it and
		// post as the default user
		$home = untrailingslashit( home_url() );
		if ( $home != $me ) {
			static::handle_authorize_error(
				401, 'access token URL ' . $me . " doesn't match site " . $home . ' or any user' );
		}

		return NULL;
	}

	/**
	 * Parse the micropub request and render the document
	 *
	 * @param int $user_id User ID for Authorized User.
	 *
	 * @uses apply_filter() Calls 'before_micropub' on the default request
	 * @uses do_action() Calls 'after_micropub' for additional postprocessing
	 */
	public static function post_handler( $user_id ) {
		$status = 200;
		$action = static::$input['action'];
		$url = static::$input['url'];

		// create
		if ( ! $url || $action == 'create' ) {
			if ( ! user_can( $user_id, 'publish_posts' ) ) {
				static::error( 403, 'user id ' . $user_id . ' cannot publish posts' );
			}
			$args = static::create( $user_id );
			$status = 201;
			static::header( 'Location', get_permalink( $args['ID'] ) );

		// update
		} elseif ( $action == 'update' || ! $action ) {
			if ( $user_id && ! user_can( $user_id, 'edit_posts' ) ) {
				static::error( 403, 'user id ' . $user_id . ' cannot edit posts' );
			}
			$args = static::update();

		// delete
		} elseif ( $action == 'delete' ) {
			if ( $user_id && ! user_can( $user_id, 'delete_posts' ) ) {
				static::error( 403, 'user id ' . $user_id . ' cannot delete posts' );
			}
			$post = get_post( url_to_postid( $url ) );
			if ( ! $post ) {
				static::error( 400, $url . ' not found' );
			}
			static::check_error( wp_trash_post( $post->ID ) );

		// undelete
		} elseif ( $action == 'undelete' ) {
			if ( $user_id && ! user_can( $user_id, 'publish_posts' ) ) {
				static::error( 403, 'user id ' . $user_id . ' cannot undelete posts' );
			}
			$found = false;
			// url_to_postid() doesn't support posts in trash, so look for
			// it ourselves, manually.
			// here's another, more complicated way that customizes WP_Query:
			// https://gist.github.com/peterwilsoncc/bb40e52cae7faa0e6efc
			foreach ( get_posts( array( 'post_status' => 'trash' ) ) as $post ) {
				if ( get_permalink ( $post ) == $url ) {
					wp_publish_post( $post->ID );
					$found = true;
				}
			}
			if ( ! $found ) {
				static::error( 400, 'deleted post ' . $url . ' not found' );
			}

		// unknown action
		} else {
			static::error( 400, 'unknown action ' . $action );
		}

		do_action( 'after_micropub', $args['ID'] );
		static::respond( $status );
	}

	/**
	 * Handle queries to the micropub endpoint
	 *
	 * @param int $user_id Authenticated User
	 */
	private static function query_handler( $user_id ) {
		switch( $_GET['q'] ) {
			case 'config':
			case 'syndicate-to':
			case 'mp-syndicate-to':
				// return empty syndication target with filter
				$syndicate_tos = apply_filters( 'micropub_syndicate-to', array(), $user_id );
				static::respond( 200, array( 'syndicate-to' => $syndicate_tos ) );
				break;
			case 'source':
				$post_id = url_to_postid( $_GET['url'] );
				if ( $post_id ) {
					static::respond( 200, static::get_mf2( $post_id ) );
				} else {
					static::error( 400, 'not found: ' . $_GET['url'] );
				}
				break;
			default:
				static::error( 400, 'unknown query ' . $_GET['q'] );
		}
	}

	/*
	 * Handle a create request.
	 */
	private static function create( $user_id ) {
		$args = apply_filters( 'before_micropub', static::mp_to_wp( static::$input ) );
		if ( $user_id ) {
			$args['post_author'] = $user_id;
		}
		$args['post_status'] = MICROPUB_DRAFT_MODE ? 'draft' : 'publish';
		kses_remove_filters();  // prevent sanitizing HTML tags in post_content
		$args['ID'] = static::check_error( wp_insert_post( $args, true ) );
		kses_init_filters();
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
		$args = get_post( $post_id, ARRAY_A );
		if ( ! $args ) {
			static::error( 400, static::$input['url'] . ' not found' );
		}

		if ( static::$input['add'] &&
			 array_keys( static::$input['add'] ) != array( 'category' ) ) {
			static::error( 400, 'can only add to category; other properties not supported' );
		}

		// add
		$add = static::$input['add'];
		if ( $add ) {
			$add_args = static::mp_to_wp( array( 'properties' => $add ) );
			if ( $add_args['tags_input'] ) {
				// i tried wp_add_post_tags here, but it didn't work
				$args['tags_input'] = array_merge($args['tags_input'] ?: array(),
												  $add_args['tags_input']);
			}
			if ( $add_args['post_category'] ) {
				// i tried wp_set_post_categories here, but it didn't work
				$args['post_category'] = array_merge($args['post_category'] ?: array(),
													 $add_args['post_category']);
			}
		}

		// replace
		$replace = static::$input['replace'];
		if ( $replace ) {
			foreach ( static::mp_to_wp( array( 'properties' => $replace ) )
					  as $name => $val ) {
				$args[ $name ] = $val;
			}
		}

		// TODO: support removing individual values. (ie when $input['delete']
		// is an associative array mapping field names to values to remove.)
		$delete = static::$input['delete'];
		if ( $delete ) {
			foreach ( static::mp_to_wp( array( 'properties' => $delete ) )
					  as $_ => $name ) {
				$args[ $name ] = NULL;
			}
		}

		// tell wordpress to preserve published date explicitly, otherwise
		// wp_update_post sets it to the current time
		$args['edit_date'] = true;

		$args = apply_filters( 'before_micropub', $args );
		kses_remove_filters();
		static::check_error( wp_update_post( $args, true ) );
		kses_init_filters();
		return $args;
	}

	private static function handle_authorize_error( $code, $msg ) {
		$home = untrailingslashit( home_url() );
		if ( $home == 'http://localhost' ) {
			error_log( 'WARNING: ' . $code . ' ' . $msg .
					   ". Allowing only because this is localhost.\n" );
			return;
		}
		static::respond( $code, $msg );
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
		$args = array();

		foreach ( array( 'slug' => 'post_name',
						 'name' => 'post_title',
						 'summary'  => 'post_excerpt',
		          ) as $mf2 => $wp ) {
			if ( $props[ $mf2 ] ) {
				$args[ $wp ] = $props[ $mf2 ][0];
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
			$args['post_date'] = iso8601_to_datetime( $props['published'][0] );
			$args['post_date_gmt'] = get_gmt_from_date( $args['post_date'][0] );
		}

		// Map micropub categories to WordPress categories if they exist, otherwise
		// to WordPress tags.
		if ( $props['category'] ) {
			$args['post_category'] = array();
			$args['tags_input'] = array();
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
		} elseif ( isset( $props['summary'] ) ) {
			$args['post_content'] = $props['summary'];
		}

		return $args;
	}

	/**
	 * Generates and returns a post_content string suitable for wp_insert_post()
	 * and friends.
	 */
	public static function generate_post_content( $args ) {
		if ( $args['post_content'] &&
			 ( current_theme_supports( 'microformats2' ) ||
			   // Post Kinds: https://wordpress.org/plugins/indieweb-post-kinds/
			   taxonomy_exists( 'kind' ) ) ) {
			return $args;
		}

		$mf2 = static::$input;
		$props = static::$input['replace'] ?: $mf2['properties'];

		$verbs = array(
			'like-of' => 'Likes',
			'repost-of' => 'Reposted',
			'in-reply-to' => 'In reply to',
		);
		$lines = array();

		// interactions
		foreach ( array_keys( $verbs ) as $prop ) {
			$val = $props[ $prop ][0];
			if ( $val ) {
				$lines[] = '<p>' . $verbs[ $prop ] .
						   ' <a class="u-' . $prop . '" href="' .
						   $val . '">' . $val . '</a>.</p>';
			}
		}

		if ( isset( $props['rsvp'] ) ) {
			$lines[] = '<p>RSVPs <data class="p-rsvp" value="' . $props['rsvp'][0] .
				'">' . $props['rsvp'][0] . '</data>.</p>';
		}

		// event
		if ( $mf2['type'] == array( 'h-event' ) ) {
			$lines[] = static::generate_event();
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
		if ( isset( $_FILES['photo'] ) || isset( $_FILES['video'] ) ||
			 isset( $_FILES['audio'] ) ) {
			$lines[] = "\n[gallery size=full columns=1]";
		}

		$args['post_content'] = implode( "\n", $lines );
		return $args;
	}

	/**
	 * Generates and returns a string h-event.
	 */
	private static function generate_event() {
		$mf2 = static::$input;
		$props = static::$input['replace'] ?: $mf2['properties'];
		$lines[] = '<div class="h-event">';

		if ( isset( $props['name'] ) ) {
			$lines[] = '<h1 class="p-name">' . $props['name'][0] . '</h1>';
		}

		$lines[] = '<p>';
		$times = array();
		foreach ( array( 'start', 'end' ) as $cls ) {
			if ( isset( $props[ $cls ][0] ) ) {
				$datetime = iso8601_to_datetime( $props[ $cls ][0] );
				$times[] = '<time class="dt-' . $cls . '" datetime="' .
						 $props[ $cls ][0] . '">' . $datetime . '</time>';
			}
		}
		$lines[] = implode( "\nto\n", $times );

		if ( isset( $props['location'] ) && substr( $props['location'][0], 0, 4 ) != 'geo:' ) {
			$lines[] = 'at <a class="p-location" href="' . $props['location'][0] . '">' .
				$props['location'][0] . '</a>';
		}

		end( $lines );
		$lines[key( $lines )] .= '.';
		$lines[] = '</p>';

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
			if ( isset( $_FILES[ $field ] ) ) {
				require_once( ABSPATH . 'wp-admin/includes/image.php' );
				require_once( ABSPATH . 'wp-admin/includes/file.php' );
				require_once( ABSPATH . 'wp-admin/includes/media.php' );
				static::check_error( media_handle_upload(
					$field, $post_id, array(),
					array( 'action' => 'allow_file_outside_uploads_dir' ) ) );
			}
		}
	}

	/**
	 * Stores geodata in WordPress format
	 *
	 * Uses $input, so load_input() must be called before this.
	 */
	public static function store_geodata( $args ) {
		if ( ! isset( $args['meta_input'] ) ) {
			$args['meta_input'] = array();
		}
		$location = static::$input['properties']['location'][0];
		if ( $location && substr( $location, 0, 4 ) == 'geo:' ) {
			// Geo URI format:
			// http://en.wikipedia.org/wiki/Geo_URI#Example
			// https://indiewebcamp.com/micropub##location
			//
			// e.g. geo:37.786971,-122.399677;u=35
			$geo = explode( ':', substr( urldecode( $location ), 4 ) );
			$geo = explode( ';', $geo[0] );
			$coords = explode( ',', $geo[0] );
			$args['meta_input']['geo_latitude'] = trim( $coords[0] );
			$args['meta_input']['geo_longitude'] = trim( $coords[1] );
		}
		return $args;
	}

	/**
	 * Store properties as post metadata. Details:
	 * https://indiewebcamp.com/WordPress_Data#Microformats_data
	 *
	 * Uses $input, so load_input() must be called before this.
	 */
	public static function store_mf2( $args ) {
		if ( ! isset( $args['meta_input'] ) ) {
			$args['meta_input'] = array();
		}

		$type = static::$input['type'];
		if ( $type ) {
			$args['meta_input'][ 'mf2_type' ] = $type;
		}

		foreach ( static::$input['replace'] ?: static::$input['properties']
				  as $key => $val ) {
			$args['meta_input'][ 'mf2_' . $key ] = $val;
		}
		// TODO: add, delete
		return $args;
	}

	/**
	 * Returns the mf2 properties for a post.
	 */
	public static function get_mf2( $post_id ) {
		$mf2 = array();

		foreach ( get_post_meta($post_id) as $field => $val ) {
			$val = unserialize( $val[0] );
			if ( $field == 'mf2_type' ) {
				$mf2['type'] = $val;
			} elseif ( substr( $field, 0, 4 ) == 'mf2_' ) {
				$mf2['properties'][ substr( $field, 4 ) ] = $val;
			}
		}

		return $mf2;
	}

	public static function error( $code, $message ) {
		static::respond( $code, array(
			'error' => ($code == 403) ? 'forbidden' :
					   ($code == 401) ? 'insufficient_scope' :
					   'invalid_request',
			'error_description' => $message,
		) );
	}

	public static function respond( $code, $resp = NULL ) {
		status_header( $code );
		static::header( 'Content-Type',
						'application/json; charset=' . get_option( 'blog_charset' ) );
		exit( $resp ? json_encode( $resp ) : '' );
	}

	public static function header( $header, $value ) {
		header( $header . ': ' . $value );
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
	public static function html_header() {
		echo '<link rel="micropub" href="' . site_url( '?micropub=endpoint' ) . '">';
		echo '<link rel="authorization_endpoint" href="' . MICROPUB_AUTHENTICATION_ENDPOINT . '">';
		echo '<link rel="token_endpoint" href="' . MICROPUB_TOKEN_ENDPOINT . '">';
	}

	/**
	 * The micropub autodicovery http-header
	 */
	public static function http_header() {
		static::header( 'Link', '<' . site_url( '?micropub=endpoint' ) . '>; rel="micropub"', false );
		static::header( 'Link', '<' . MICROPUB_AUTHENTICATION_ENDPOINT . '>; rel="authorization_endpoint"', false );
		static::header( 'Link', '<' . MICROPUB_TOKEN_ENDPOINT . '>; rel="token_endpoint"', false );
	}

	/**
	 * Generates webfinger/host-meta links
	 */
	public static function jrd_links( $array ) {
		$array['links'][] = array( 'rel' => 'micropub', 'href' => site_url( '?micropub=endpoint' ) );
		$array['links'][] = array( 'rel' => 'authorization_endpoint', 'href' => MICROPUB_AUTHENTICATION_ENDPOINT );
		$array['links'][] = array( 'rel' => 'token_endpoint', 'href' => MICROPUB_TOKEN_ENDPOINT );
	}

	protected static function load_input() {
		$content_type = explode( ';', static::get_header( 'Content-Type' ) )[0];
		if ( $content_type  == 'application/json' ) {
			static::$input = json_decode( static::read_input(), true );
		} elseif ( ! $content_type ||
				   $content_type  == 'application/x-www-form-urlencoded' ) {
			static::$input = array( 'properties' => array() );
			foreach ( $_POST as $key => $val ) {
				if ( $key == 'action' || $key == 'url' ) {
					static::$input[ $key ] = $val;
				} elseif ( $key == 'h' ) {
					static::$input['type'] = array( 'h-' . $val );
				} else {
					static::$input['properties'][ $key ] =
						is_array( $val ) ? $val : array( $val );
				}
			}
		} else {
			static::error( 400, 'unsupported content type ' . $content_type );
		}
	}

	protected static function read_input() {
			return file_get_contents( 'php://input' );
	}

	protected static function get_header( $name ) {
		if ( ! static::$request_headers ) {
			static::$request_headers = getallheaders();
		}
		return static::$request_headers[ strtolower( $name ) ];
	}
}


// blatantly stolen from https://github.com/idno/Known/blob/master/Idno/Pages/File/View.php#L25
if ( ! function_exists( 'getallheaders' ) ) {
	function getallheaders()
	{
		$headers = '';
		foreach ( $_SERVER as $name => $value ) {
			if ( substr( $name, 0, 5 ) == 'HTTP_' ) {
				$headers[str_replace( ' ', '-', ucwords( strtolower( str_replace( '_', ' ', substr( $name, 5 ) ) ) ) )] = $value;
			}
		}
		return $headers;
	}
}

endif;
?>
