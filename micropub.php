<?php
/**
 * Plugin Name: Micropub
 * Plugin URI: https://github.com/snarfed/wordpress-micropub
 * Description: <a href="https://indiewebcamp.com/micropub">Micropub</a> server.
 * Author: Ryan Barrett
 * Author URI: https://snarfed.org/
 * Version: 0.4
 *
 * @package Micropub
 *
 * Example command line for testing:
 * curl -i -H 'Authorization: Bearer ...' -F h=entry -F name=foo -F content=bar \
 * -F photo=@gallery/snarfed.gif 'http://localhost/w/?micropub=endpoint'
 *
 * To generate an access token for testing:
 * 1. Open this in a browser, filling in SITE:
 * https://indieauth.com/auth?me=SITE&scope=post&client_id=indieauth&redirect_uri=https%3A%2F%2Findieauth.com%2Fsuccess
 * 2. Extract the code param from the URL.
 * 3. Run this command line, filling in CODE and SITE (which logged into IndieAuth):
 * curl -i -d 'code=CODE&me=SITE&client_id=indieauth&redirect_uri=https://indieauth.com/success' 'https://tokens.indieauth.com/token'
 * 4. Extract the access_token parameter from the response body.
 */

// For debugging purposes this will bypass Micropub authentication.
if ( ! defined( 'MICROPUB_LOCAL_AUTH' ) ) {
	define( 'MICROPUB_LOCAL_AUTH', false ); }

// Allows for a custom Authentication and Token Endpoint.
if ( ! defined( 'MICROPUB_AUTHENTICATION_ENDPOINT' ) ) {
	define( 'MICROPUB_AUTHENTICATION_ENDPOINT', 'https://indieauth.com/auth' ); }
if ( ! defined( 'MICROPUB_TOKEN_ENDPOINT' ) ) {
	define( 'MICROPUB_TOKEN_ENDPOINT', 'https://tokens.indieauth.com/token' ); }

// For debugging purposes this will set all Micropub posts to Draft.
if ( ! defined( 'MICROPUB_DRAFT_MODE' ) ) {
	define( 'MICROPUB_DRAFT_MODE', false ); }

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
			/*
			 * register endpoint
			 * (I originally used add_rewrite_endpoint() to serve on /micropub instead
			 * of ?micropub=endpoint, but that had problems. details in
			 * https://github.com/snarfed/wordpress-micropub/commit/d3bdc433ee019d3968be6c195b0384cba5ffe36b#commitcomment-9690066 )
			 */
			add_filter( 'query_vars', array( 'Micropub', 'query_var' ) );
			add_action( 'parse_query', array( 'Micropub', 'parse_query' ) );

			// endpoint discovery
			add_action( 'wp_head', array( 'Micropub', 'html_header' ), 99 );
			add_action( 'send_headers', array( 'Micropub', 'http_header' ) );
			add_filter( 'host_meta', array( 'Micropub', 'jrd_links' ) );
			add_filter( 'webfinger_data', array( 'Micropub', 'jrd_links' ) );
		}

		/**
		 * Adds some query vars
		 *
		 * @param array $vars
		 * @return array
		 */
		public static function query_var($vars) {
			$vars[] = 'micropub';
			return $vars;
		}

		/**
		 * Parse the micropub request and render the document
		 *
		 * @param WP $wp WordPress request context
		 *
		 * @uses apply_filter() Calls 'before_micropub' on the default request
		 * @uses do_action() Calls 'after_micropub' for additional postprocessing
		 */
		public static function parse_query($wp) {
			if ( ! array_key_exists( 'micropub', $wp->query_vars ) ) {
				return;
			}
			if ( WP_DEBUG ) {
				error_log( 'Micropub Data: ' . serialize( $_POST ) );
			}
			header( 'Content-Type: text/plain; charset=' . get_option( 'blog_charset' ) );
			// Bypass Micropub auth with WordPress auth.
			if ( MICROPUB_LOCAL_AUTH ) {
				if ( ! is_user_logged_in() ) {
					auth_redirect();
				}
				$user_id = wp_get_current_user();
			} else {
				$user_id = Micropub::authorize();
			}
			// TODO: future development note to add JSON support.
			// Validate micropub request params.
			if ( ! isset( $_POST['h'] ) && ! isset( $_POST['url'] ) && ! isset( $_POST['mp-action'] ) && ! isset( $_POST['action'] ) && ! isset( $_GET['q'] ) ) {
				Micropub::error( 400, 'Empty Micropub request. Either an "h", "mp-action", "url" or "q" property is required, e.g. h=entry or url=http://example.com/post/100 or q=syndicate-to' );
			}
			if ( isset( $_GET['q'] ) ) {
				Micropub::return_query( $user_id );
				exit;
			}
			// support both mp-action and operation parameter names
			if ( ! isset( $_POST['mp-action'] ) ) {
				$_POST['mp-action'] = isset( $_POST['operation'] ) ? $_POST['operation']
				: isset( $_POST['url'] ) ? 'edit' : 'create';
			}

			$args = apply_filters( 'before_micropub', Micropub::generate_args() );
			if ( $user_id ) {
				$args['post_author'] = $user_id;
			}

			if ( ! isset( $_POST['mp-action'] ) || ! isset( $_POST['url'] ) ) {
				if ( $user_id && ! user_can( $user_id, 'publish_posts' ) ) {
					Micropub::error( 403, 'user id ' . $user_id . ' cannot publish posts' );
				}
				$args['post_status'] = MICROPUB_DRAFT_MODE ? 'draft' : 'publish';
				kses_remove_filters();  // prevent sanitizing HTML tags in post_content
				$args['ID'] = Micropub::check_error( wp_insert_post( $args ) );
				kses_init_filters();
				Micropub::postprocess( $args['ID'] );
				status_header( 201 );
				header( 'Location: ' . get_permalink( $args['ID'] ) );

			} else {
				if ( 0 === $args['ID'] ) {
					Micropub::error( 400, $_POST['mp-action'] ?: $_POST['url'] . ' not found' );
				}

				if ( 'edit' === $_POST['mp-action'] || ! isset( $_POST['mp-action'] ) ) {
					if ( $user_id && ! user_can( $user_id, 'edit_posts' ) ) {
						Micropub::error( 403, 'user id ' . $user_id . ' cannot edit posts' );
					}
					kses_remove_filters();  // prevent sanitizing HTML tags in post_content
					Micropub::check_error( wp_update_post( $args ) );
					kses_init_filters();
					Micropub::postprocess( $args['ID'] );
					status_header( 200 );

				} elseif ( 'delete' === $_POST['mp-action'] ) {
					if ( $user_id && ! user_can( $user_id, 'delete_posts' ) ) {
						Micropub::error( 403, 'user id ' . $user_id . ' cannot delete posts' );
					}
					Micropub::check_error( wp_trash_post( $args['ID'] ) );
					status_header( 200 );
					// TODO: figure out how to make url_to_postid() support posts in trash
					// here's one way:
					// https://gist.github.com/peterwilsoncc/bb40e52cae7faa0e6efc
					// } elseif ('undelete' === $action ) {
					// Micropub::check_error(wp_update_post(array(
					// 'ID'           => $args['ID'],
					// 'post_status'  => 'publish',
					// )));
					// status_header(200);
				} else {
					Micropub::error( 400, 'unknown action ' . $_POST['action'] );
				}
			}
			do_action( 'after_micropub', $args['ID'] );
			exit;
		}

		/**
		 * Use tokens.indieauth.com to validate the access token.
		 *
		 * If the token is valid, returns the user id to use as the post's author, or
		 * NULL if the token only matched the site URL and no specific user.
		 */
		private static function authorize() {
			// find the access token
			$headers = getallheaders();
			if ( isset( $headers['Authorization'] ) ) {
				$auth_header = $headers['Authorization'];
			} elseif ( isset( $_POST['access_token'] ) ) {
				$auth_header = 'Bearer ' . $_POST['access_token'];
			} else {
				return Micropub::handle_authorize_error( 401, 'missing access token' );
			}

			// verify it with tokens.indieauth.com
			$resp = wp_remote_get('https://tokens.indieauth.com/token',
				array(
				'headers' => array(
							'Content-type' => 'application/x-www-form-urlencoded',
							'Authorization' => $auth_header,
				),
				));
				$code = wp_remote_retrieve_response_code( $resp );
				$body = wp_remote_retrieve_body( $resp );
				parse_str( $body, $params );
				if ( 2 !== $code / 100 ) {
					return Micropub::handle_authorize_error(
					$code, 'invalid access token: ' . $body);
				} else if ( ! isset( $params['scope'] ) ||
				! in_array( 'post', explode( ' ', $params['scope'] ) ) ) {
					return Micropub::handle_authorize_error(
					403, 'access token is missing post scope; got `' . $params['scope'] . '`');
				}

				parse_str( $body, $resp );
				$me = untrailingslashit( $resp['me'] );

				// look for a user with the same url as the token's `me` value. search both
				// with and without trailing slash.
				foreach ( array_merge(get_users( array( 'search' => $me ) ),
				get_users( array( 'search' => $me . '/' ) ))
						 as $user ) {
					if ( untrailingslashit( $user->user_url ) === $me ) {
						return $user->ID;
					}
				}

				// no user with that url. if the token is for this site itself, allow it and
				// post as the default user
				$home = untrailingslashit( home_url() );
				if ( $home !== $me ) {
					return Micropub::handle_authorize_error(
					401, 'access token URL ' . $me . " doesn't match site " . $home . ' or any user');
				}

				return null;
		}

		private static function return_query($user_id) {
			header( 'Content-type: application/x-www-form-urlencoded' );
			switch ( $_GET['q'] ) {
				case 'syndicate-to':
					// Fallback
				case 'mp-syndicate-to':
					status_header( 200 );
					// return empty syndication target with filter
					$syndication = apply_filters( 'micropub_syndicate-to', array(), $user_id );
					if ( ! empty( $syndication ) ) {
						echo 'syndicate-to[]=' . implode( '&syndicate-to[]=', $syndication );
					}
				break;
				default:
					Micropub::error( 400, 'unknown query ' . $_GET['q'] );
			}
		}

		private static function handle_authorize_error($code, $msg) {
			$home = untrailingslashit( home_url() );
			if ( 'http://localhost' === $home ) {
				echo 'WARNING: ' . $code . ' ' . $msg .
				  ". Allowing only because this is localhost.\n";
				return;
			}
			Micropub::error( $code, $msg );
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
			// If the theme declares it supports microformats2 do not mark up content.
			if ( current_theme_supports( 'microformats2' ) ) {
				if ( isset( $_POST['content'] ) ) {
					$args['post_content'] = $_POST['content'];
				} else if ( isset( $_POST['summary'] ) ) {
					$args['post_content'] = $_POST['summary'];
				}
			} // Else markup the content before passing it through.
			else {
				$args['post_content'] = Micropub::generate_post_content();
			}
			return $args;
		}

		/**
		 * Generates and returns a post_content string suitable for wp_insert_post()
		 * and friends.
		 */
		private static function generate_post_content() {
			$verbs = array(
			'like' => 'Likes',
				   'repost' => 'Reposted',
				   'in-reply-to' => 'In reply to',
			);

			// interactions.
			foreach ( array( 'like', 'repost', 'in-reply-to' ) as $cls ) {
				$val = isset( $_POST[ $cls ] ) ? $_POST[ $cls ]
				 : (isset( $_POST[ $cls . '-of' ] ) ? $_POST[ $cls . '-of' ]
				 : null);
				if ( $val ) {
					$lines[] = '<p>' . $verbs[ $cls ] .
					' <a class="u-' . $cls . '-of" href="' . $val . '">' . $val . '</a>.</p>';
				}
			}

			if ( isset( $_POST['rsvp'] ) ) {
				$lines[] = '<p>RSVPs <data class="p-rsvp" value="' . $_POST['rsvp'] .
				'">' . $_POST['rsvp'] . '</data>.</p>';
			}

			// content, event.
			if ( isset( $_POST['content'] ) ) {
				$lines[] = '<div class="e-content">';
				$lines[] = $_POST['content'];
				if ( isset( $_POST['h'] ) && 'event' === $_POST['h'] ) {
					$lines[] = Micropub::generate_event();
				}
				$lines[] = '</div>';
			}

			// TODO: generate my own markup so i can include u-photo.
			if ( isset( $_FILES['photo'] ) ) {
				$lines[] = "\n[gallery size=full columns=1]";
			}

			return implode( "\n", $lines );
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
					$times[] = '<time class="dt-' . $cls . '" datetime="' . $_POST[ $cls ] .
					'">' . $datetime . '</time>';
				}
			}
			$lines[] = implode( "\nto\n", $times );

			if ( isset( $_POST['location'] ) && 'geo:' !== substr( $_POST['location'], 0, 4 ) ) {
				$lines[] = 'at <a class="p-location" href="' . $_POST['location'] . '">' .
				$_POST['location'] . '</a>';
			}

			end( $lines );
			$lines[ key( $lines ) ] .= '.';
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
		 * Postprocesses a post that has been created or updated. Useful for changes
		 * that require the post id, e.g. uploading media and adding post metadata.
		 */
		private static function postprocess($post_id) {
			if ( isset( $_FILES['photo'] ) ) {
				require_once( ABSPATH . 'wp-admin/includes/image.php' );
				require_once( ABSPATH . 'wp-admin/includes/file.php' );
				require_once( ABSPATH . 'wp-admin/includes/media.php' );
				Micropub::check_error( media_handle_upload( 'photo', $post_id ) );
			}

			if ( isset( $_POST['location'] ) && 'geo:' === substr( $_POST['location'], 0, 4 ) ) {
				// Geo URI format:
				// http://en.wikipedia.org/wiki/Geo_URI#Example
				// https://indiewebcamp.com/micropub##location
				$geo = str_replace( 'geo:', '', urldecode( $_POST['location'] ) );
				$geo = explode( ':', $geo );
				$geo = explode( ';', $geo[0] );
				$coords = explode( ',', $geo[0] );
				update_post_meta( $post_id, 'geo_latitude', trim( $coords[0] ) );
				update_post_meta( $post_id, 'geo_longitude', trim( $coords[1] ) );
			}

			Micropub::store_mf2( $post_id );
		}

		/**
		 * Store properties as post metadata. Details:
		 * https://indiewebcamp.com/WordPress_Data#Microformats_data
		 */
		private static function store_mf2($post_id) {
			// Do not store reserved parameters
			$blacklist = array( 'access_token', 'mp-action', 'action', 'url', 'cite' );
			foreach ( $_POST as $key => $value ) {
				if ( ! is_array( $value ) ) {
					$value = array( $value );
				}
				if ( ! in_array( $key, $blacklist ) ) {
					$key = 'mf2_' . $key;
					delete_post_meta( $post_id, $key );  // clear old value(s)
					foreach ( $value as $val ) {
						if ( ! empty( $val ) ) {
							add_post_meta( $post_id, $key, $val );
						}
					}
				}
			}

		}

		private static function error($code, $msg) {
			status_header( $code );
			echo $msg . "\r\n";
			exit;
		}

		private static function check_error($result) {
			if ( ! $result ) {
				Micropub::error( 500, 'Unknown WordPress error: ' . $result );
			} else if ( is_wp_error( $result ) ) {
				Micropub::error( 500, $result->get_error_message() );
			}
			return $result;
		}

		/**
		 * The micropub autodicovery meta tags
		 */
		public static function html_header() {
			echo '<link rel="micropub" href="' . site_url( '?micropub=endpoint' ) . '">';
			echo '<link rel="authorization_endpoint" href="' . esc_url( MICROPUB_AUTHENTICATION_ENDPOINT ) . '">';
			echo '<link rel="token_endpoint" href="' . esc_url( MICROPUB_TOKEN_ENDPOINT ) . '">';
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
		public static function jrd_links($array) {
			$array['links'][] = array(
			'rel' => 'micropub',
							  'href' => site_url( '?micropub=endpoint' ),
			);
			$array['links'][] = array(
			'rel' => 'authorization_endpoint',
							  'href' => MICROPUB_AUTHENTICATION_ENDPOINT,
			);
			$array['links'][] = array(
			'rel' => 'token_endpoint',
							  'href' => MICROPUB_TOKEN_ENDPOINT,
			);
		}
	}

	// blatantly stolen from https://github.com/idno/Known/blob/master/Idno/Pages/File/View.php#L25
	if ( ! function_exists( 'getallheaders' ) ) {
		function getallheaders() {

			$headers = '';
			foreach ( $_SERVER as $name => $value ) {
				if ( 'HTTP_' === substr( $name, 0, 5 ) ) {
					$headers[ str_replace( ' ', '-', ucwords( strtolower( str_replace( '_', ' ', substr( $name, 5 ) ) ) ) ) ] = $value;
				}
			}
			return $headers;
		}
	}

endif;
?>
