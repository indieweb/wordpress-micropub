<?php
/*
 Plugin Name: Micropub
 Plugin URI: https://github.com/snarfed/wordpress-micropub
 Description: <a href="https://indiewebcamp.com/micropub">Micropub</a> server.
 Author: Ryan Barrett
 Author URI: https://snarfed.org/
 Version: 0.5
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

		// Store MF2. Run at higher priority so the MF2 is available to other
		// functions that run on this filter.
		add_filter( 'before_micropub', array( $cls, 'store_mf2' ), 8 );
		// Postprocess
		add_filter( 'before_micropub', array( $cls, 'generate_post_content' ) );
		// Store Geodata
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
		if ( WP_DEBUG ) {
			error_log( 'Micropub Data: ' . serialize( $_GET ) . ' ' . serialize( $_POST ) );
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
		if ( isset( $_GET['q'] ) ) {
			static::query_handler( $user_id );
		} else {
			self::form_handler( $user_id );
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
		$headers = getallheaders();
		foreach ($headers as $k => $v) {
			$lowheaders[strtolower($k)] = $v;
		}
		if ( isset( $lowheaders['authorization'] ) ) {
			$auth_header = $lowheaders['authorization'];
		} elseif ( isset( $_POST['access_token'] ) ) {
			$auth_header = 'Bearer ' . $_POST['access_token'];
		} else {
			static::handle_authorize_error( 401, 'missing access token' );
		}

		$resp = wp_remote_get(
			MICROPUB_TOKEN_ENDPOINT, array( 'headers' => array(
				'Content-type' => 'application/x-www-form-urlencoded',
				'Authorization' => $auth_header,
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
	public static function form_handler( $user_id ) {
		$status = 200;
		header( 'Content-Type: text/plain; charset=' . get_option( 'blog_charset' ) );
		$edit_url = isset( $_POST['edit-of'] ) ? $_POST['edit-of']
				  : isset( $_POST['url'] ) ? $_POST['url']
				  : NULL;
		// validate micropub request params
		if ( ! isset( $_POST['h'] ) && ! $edit_url ) {
			static::respond( 400, 'Empty Micropub request. Either an "h", "edit-of", "url" or "q" property is required, e.g. h=entry or url=http://example.com/post/100 or q=syndicate-to' );
		}
		// support both action= and operation= parameter names
		if ( ! isset( $_POST['action'] ) ) {
			$_POST['action'] = isset( $_POST['operation'] ) ? $_POST['operation']
							 : isset( $_POST['url'] ) ? 'edit' : 'create';
		}
		$args = apply_filters( 'before_micropub', static::generate_args() );
		if ( $user_id ) {
			$args['post_author'] = $user_id;
		}

		if ( ! $edit_url || $_POST['action'] == 'create' ) {
			if ( $user_id && ! user_can( $user_id, 'publish_posts' ) ) {
				static::respond( 403, 'user id ' . $user_id . ' cannot publish posts' );
			}
			$args['post_status'] = MICROPUB_DRAFT_MODE ? 'draft' : 'publish';
			kses_remove_filters();  // prevent sanitizing HTML tags in post_content
			$args['ID'] = static::check_error( wp_insert_post( $args, true ) );
			kses_init_filters();
			$status = 201;
			header( 'Location: ' . get_permalink( $args['ID'] ) );

		} else {
			if ( $args['ID'] == 0 || ! get_post( $args['ID'] ) ) {
				static::respond( 400, $edit_url . ' not found' );
			}

			if ( $_POST['action'] == 'edit' || ! isset( $_POST['action'] ) ) {
				if ( $user_id && ! user_can( $user_id, 'edit_posts' ) ) {
					static::respond( 403, 'user id ' . $user_id . ' cannot edit posts' );
				}
				kses_remove_filters();  // prevent sanitizing HTML tags in post_content
				static::check_error( wp_update_post( $args, true ) );
				kses_init_filters();

			} elseif ( $_POST['action'] == 'delete' ) {
				if ( $user_id && ! user_can( $user_id, 'delete_posts' ) ) {
					static::respond( 403, 'user id ' . $user_id . ' cannot delete posts' );
				}
				static::check_error( wp_trash_post( $args['ID'] ) );
				// TODO: figure out how to make url_to_postid() support posts in trash
				// here's one way:
				// https://gist.github.com/peterwilsoncc/bb40e52cae7faa0e6efc
				// } elseif ( $action == 'undelete' ) {
				//   static::check_error( wp_update_post( array(
				//     'ID'           => $args['ID'],
				//     'post_status'  => 'publish',
				//   ) ) );
			} else {
				static::respond( 400, 'unknown action ' . $_POST['action'] );
			}
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
		header( 'Content-Type: text/plain; charset=' . get_option( 'blog_charset' ) );
		switch( $_GET['q'] ) {
			case 'syndicate-to':
			// Fallback
			case 'mp-syndicate-to':
				// return empty syndication target with filter
				$syndication = apply_filters( 'micropub_syndicate-to', array(), $user_id );
				if ( ! empty( $syndication ) ) {
					$syndication = 'syndicate-to[]=' . implode( '&syndicate-to[]=', $syndication );
				} else {
					$syndication = '';
				}
				header( 'Content-type: application/x-www-form-urlencoded' );
				static::respond( 200, $syndication );
			default:
				static::respond( 400, 'unknown query ' . $_GET['q'] );
		}
	}

	private static function handle_authorize_error( $code, $msg ) {
		$home = untrailingslashit( home_url() );
		if ( $home == 'http://localhost' ) {
				echo 'WARNING: ' . $code . ' ' . $msg .
					 ". Allowing only because this is localhost.\n";
				return;
		}
		static::respond( $code, $msg );
	}

	/**
	 * Generate args for WordPress wp_insert_post() and friends.
	 */
	private static function generate_args() {
		// these can be passed through untouched
		$mp_to_wp = array(
			'slug'     => 'post_name',
			'name'     => 'post_title',
			'summary'  => 'post_excerpt',
		);

		$args = array();
		foreach ( $_POST as $param => $value ) {
			if ( isset( $mp_to_wp[ $param ] ) ) {
				$args[ $mp_to_wp[ $param ] ] = $value;
			}
		}
		// these are transformed or looked up
		if ( isset( $_POST['edit-of'] ) ) {
			$args['ID'] = url_to_postid( $_POST['edit-of'] );
		}
		if ( isset( $_POST['url'] ) ) {
			$args['ID'] = url_to_postid( $_POST['url'] );
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

		if ( isset( $_POST['published'] ) ) {
			$args['post_date'] = iso8601_to_datetime( $_POST['published'] );
			$args['post_date_gmt'] = get_gmt_from_date( $args['post_date'] );
		}

		// Map micropub categories to WordPress categories if they exist, otherwise
		// to WordPress tags.
		if ( isset( $_POST['category'] ) ) {
			if ( empty( $_POST['category'] ) ) {
				$_POST['category'] = array();
			}
			foreach ( $_POST['category'] as $mp_cat ) {
				$wp_cat = get_category_by_slug( $mp_cat );
				if ( $wp_cat ) {
					$args['post_category'][] = $wp_cat->term_id;
				} else {
					$args['tags_input'][] = $mp_cat;
				}
			}
		}
		if ( isset( $_POST['content']['html'] ) ) {
			$args['post_content'] = $_POST['content']['html'];
		} else if ( isset( $_POST['content'] ) ) {
			$args['post_content'] = $_POST['content'];
		}
		elseif ( isset( $_POST['summary'] ) ) {
			$args['post_content'] = $_POST['summary'];
		}
		return $args;
	}

	/**
	 * Generates and returns a post_content string suitable for wp_insert_post()
	 * and friends.
	 */
	public static function generate_post_content( $args ) {
		// If the theme declares it supports microformats2, pass the content through
		if ( current_theme_supports( 'microformats2' ) ) {
			return $args;
		}
		// Disable if the Post Kinds' plugin's Taxonomy is enabled, since it handles
		// its own markup. https://wordpress.org/plugins/indieweb-post-kinds/
		if ( taxonomy_exists( 'kind' ) ) {
			return $args;
		}
		$lines = array();
		$verbs = array(
			'like' => 'Likes',
			'repost' => 'Reposted',
			'in-reply-to' => 'In reply to',
		);

		// interactions
		foreach ( array( 'like', 'repost', 'in-reply-to' ) as $cls ) {
			$val = isset( $_POST[ $cls ] ) ? $_POST[ $cls ]
				 : ( isset( $_POST[ $cls . '-of' ] ) ? $_POST[ $cls . '-of' ]
				 : NULL );
			if ( $val ) {
				$lines[] = '<p>' . $verbs[ $cls ] .
					' <a class="u-' . $cls . '-of" href="' . $val . '">' . $val . '</a>.</p>';
			}
		}

		if ( isset( $_POST['rsvp'] ) ) {
			$lines[] = '<p>RSVPs <data class="p-rsvp" value="' . $_POST['rsvp'] .
				'">' . $_POST['rsvp'] . '</data>.</p>';
		}

		// content, event
		if ( isset( $_POST['content'] ) ) {
			$lines[] = '<div class="e-content">';
			if (isset($_POST['content']['html'])) {
				$lines[] = $_POST['content']['html'];
			} else {
				$lines[] = $_POST['content'];
			}
			if ( isset( $_POST['h'] ) && $_POST['h'] == 'event' ) {
				$lines[] = static::generate_event();
			}
			$lines[] = '</div>';
		}

		// TODO: generate my own markup so i can include u-photo
		if ( isset( $_FILES['photo'] ) ) {
			$lines[] = "\n[gallery size=full columns=1]";
		}

		$args['post_content'] = implode( "\n", $lines );
		return $args;
	}

	/**
	 * Generates and returns a string h-event.
	 */
	private static function generate_event() {
		$lines[] = '<div class="h-event">';

		if ( isset( $_POST['name'] ) ) {
			$lines[] = '<h1 class="p-name">' . $_POST['name'] . '</h1>';
		}

		$lines[] = '<p>';
		$times = array();
		foreach ( array( 'start', 'end' ) as $cls ) {
			if ( isset( $_POST[ $cls ] ) ) {
				$datetime = iso8601_to_datetime( $_POST[ $cls ] );
				$times[] = '<time class="dt-' . $cls . '" datetime="' .
						 $_POST[ $cls ] . '">' . $datetime . '</time>';
			}
		}
		$lines[] = implode( "\nto\n", $times );

		if ( isset( $_POST['location'] ) && substr( $_POST['location'], 0, 4 ) != 'geo:' ) {
			$lines[] = 'at <a class="p-location" href="' . $_POST['location'] . '">' .
				$_POST['location'] . '</a>';
		}

		end( $lines );
		$lines[key( $lines )] .= '.';
		$lines[] = '</p>';

		if ( isset( $_POST['summary'] ) ) {
			$lines[] = '<p class="p-summary">' . urldecode( $_POST['summary'] ) . '</p>';
		}

		if ( isset( $_POST['description'] ) ) {
			$lines[] = '<p class="p-description">' . urldecode( $_POST['description'] ) . '</p>';
		}

		$lines[] = '</div>';
		return implode( "\n", $lines );
	}

	/**
	 * Handles Photo Upload.
	 *
	 */
	public static function default_file_handler( $post_id ) {
		if ( isset( $_FILES['photo'] ) ) {
			require_once( ABSPATH . 'wp-admin/includes/image.php' );
			require_once( ABSPATH . 'wp-admin/includes/file.php' );
			require_once( ABSPATH . 'wp-admin/includes/media.php' );
			static::check_error( media_handle_upload( 'photo', $post_id ) );
		}
	}

	/**
	 * Stores geodata in WordPress format
	 */
	public static function store_geodata( $args ) {
		if ( ! isset( $args['meta_input'] ) ) {
			$args['meta_input'] = array();
		}
		if ( isset( $_POST['location'] ) && substr( $_POST['location'], 0, 4 ) == 'geo:' ) {
			// Geo URI format:
			// http://en.wikipedia.org/wiki/Geo_URI#Example
			// https://indiewebcamp.com/micropub##location
			$geo = str_replace( 'geo:', '', urldecode( $_POST['location'] ) );
			$geo = explode( ':', $geo );
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
	 */
	public static function store_mf2( $args ) {
		if ( ! isset( $args['meta_input'] ) ) {
			$args['meta_input'] = array();
		}

		// Do not store access_token or other optional parameters
		$blacklist = array( 'access_token' );
		foreach ( $_POST as $key => $value ) {
			if ( ! is_array( $value ) ) {
				$value = array( $value );
			}
			if ( ! in_array( $key, $blacklist ) ) {
				$key = 'mf2_' . $key;
				foreach ( $value as $val ) {
					if ( ! empty( $val ) ) {
						$args['meta_input'][ $key ] = $val;
					}
				}
			}
		}
	 return $args;
	}

	public static function respond( $code, $message = '' ) {
		status_header( $code );
		exit( $message );
	}

	private static function check_error( $result ) {
		if ( ! $result ) {
			static::respond( 500, 'Unknown WordPress error: ' . $result );
		} elseif ( is_wp_error( $result ) ) {
			static::respond( 500, $result->get_error_message() );
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
		header( 'Link: <' . site_url( '?micropub=endpoint' ) . '>; rel="micropub"', false );
		header( 'Link: <' . MICROPUB_AUTHENTICATION_ENDPOINT . '>; rel="authorization_endpoint"', false );
		header( 'Link: <' . MICROPUB_TOKEN_ENDPOINT . '>; rel="token_endpoint"', false );
	}

	/**
	 * Generates webfinger/host-meta links
	 */
	public static function jrd_links( $array ) {
		$array['links'][] = array( 'rel' => 'micropub', 'href' => site_url( '?micropub=endpoint' ) );
		$array['links'][] = array( 'rel' => 'authorization_endpoint', 'href' => MICROPUB_AUTHENTICATION_ENDPOINT );
		$array['links'][] = array( 'rel' => 'token_endpoint', 'href' => MICROPUB_TOKEN_ENDPOINT );
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
