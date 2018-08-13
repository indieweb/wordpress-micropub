<?php

/* For debugging purposes this will bypass Micropub authentication
 * in favor of WordPress authentication
 * Using this to test querying(q=) parameters quickly
 */
// Allows for a custom Authentication and Token Endpoint
if ( ! defined( 'MICROPUB_AUTHENTICATION_ENDPOINT' ) ) {
	define( 'MICROPUB_AUTHENTICATION_ENDPOINT', 'https://indieauth.com/auth' );
}
if ( ! defined( 'MICROPUB_TOKEN_ENDPOINT' ) ) {
	define( 'MICROPUB_TOKEN_ENDPOINT', 'https://tokens.indieauth.com/token' );
}

add_action( 'plugins_loaded', array( 'Micropub_Authorize', 'init' ) );

/**
 * Micropub IndieAuth Authorization Class
 */
class Micropub_Authorize {

	// associative array, populated by determine_current_user.
	protected static $micropub_auth_response = array();

	// Array of Scopes
	protected static $scopes = array();

	// This allows more detailed error messages to be passed as otherwise the normal message is not detailed enough for debugging
	protected static $error = null;

	/**
	 * Initialize the plugin.
	 */
	public static function init() {
		$cls = get_called_class();

		add_action( 'wp_head', array( $cls, 'html_header' ), 99 );
		add_action( 'send_headers', array( $cls, 'http_header' ) );
		add_filter( 'host_meta', array( $cls, 'jrd_links' ) );
		add_filter( 'webfinger_user_data', array( $cls, 'jrd_links' ) );
		add_filter( 'rest_index', array( $cls, 'rest_index' ) );

		// The WordPress IndieAuth plugin uses priority 30
		add_filter( 'determine_current_user', array( $cls, 'determine_current_user' ), 31 );
		add_filter( 'rest_authentication_errors', array( $cls, 'rest_authentication_errors' ), 10 );
		add_filter( 'rest_post_dispatch', array( $cls, 'return_micropub_error' ), 10, 3 );

	}

	public static function return_micropub_error( $result, $server, $request ) {
		if ( '/micropub/1.0/endpoint' !== $request->get_route() ) {
			return $result;
		}
		if ( is_micropub_error( static::$error ) ) {
			return static::$error;
		}

		return $result;
	}

	public static function rest_index( $response ) {
		$data                                = $response->get_data();
		$data['authentication']['indieauth'] = array(
			'endpoints' => array(
				'authorization' => get_option( 'indieauth_authorization_endpoint', MICROPUB_AUTHENTICATION_ENDPOINT ),
				'token'         => get_option( 'indieauth_token_endpoint', MICROPUB_TOKEN_ENDPOINT ),
			),
		);
		$response->set_data( $data );
		return $response;
	}

	public static function rest_authentication_errors( $error = null ) {
		if ( $error ) {
			return $error;
		}
		if ( is_wp_error( static::$error ) ) {
			return static::$error;
		}
		if ( is_micropub_error( static::$error ) ) {
			return static::$error->to_wp_error();
		}
		return null;

	}

	public static function indieauth_scopes( $scopes ) {
		return static::$scopes;
	}
	public static function indieauth_response( $response ) {
		return static::$micropub_auth_response;
	}

	public static function header( $header, $value ) {
			header( $header . ': ' . $value, false );
	}

	public static function http_header() {
			static::header( 'Link', '<' . get_option( 'indieauth_authorization_endpoint', MICROPUB_AUTHENTICATION_ENDPOINT ) . '>; rel="authorization_endpoint"' );
			static::header( 'Link', '<' . get_option( 'indieauth_token_endpoint', MICROPUB_TOKEN_ENDPOINT ) . '>; rel="token_endpoint"' );
	}
	public static function html_header() {
			printf( '<link rel="authorization_endpoint" href="%s" />' . PHP_EOL, esc_url( get_option( 'indieauth_authorization_endpoint', MICROPUB_AUTHENTICATION_ENDPOINT ) ) ); // phpcs:ignore
			printf( '<link rel="token_endpoint" href="%s" />' . PHP_EOL, esc_url ( get_option( 'indieauth_token_endpoint', MICROPUB_TOKEN_ENDPOINT ) ) ); // phpcs:ignore
	}

	public static function jrd_links( $array ) {
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

	/**
	 * Get the authorization header
	 *
	 * On certain systems and configurations, the Authorization header will be
	 * stripped out by the server or PHP. Typically this is then used to
	 * generate `PHP_AUTH_USER`/`PHP_AUTH_PASS` but not passed on. We use
	 * `getallheaders` here to try and grab it out instead.
	 *
	 * @return string|null Authorization header if set, null otherwise
	 */
	public static function get_authorization_header() {
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] );
		}
		// When Apache speaks via FastCGI with PHP, then the authorization header is often available as REDIRECT_HTTP_AUTHORIZATION.
		if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		}
		$headers = getallheaders();
		// Check for the authorization header case-insensitively
		foreach ( $headers as $key => $value ) {
			if ( strtolower( $key ) === 'authorization' ) {
				return $value;
			}
		}
		return null;
	}

	/**
	 * Validate the access token at the token endpoint.
	 *
	 * https://indieauth.spec.indieweb.org/#access-token-verification
	 */
	public static function determine_current_user( $user_id ) {
		$cls = get_called_class();
		// Do not try to find a user if one has already been found
		if ( ! empty( $user_id ) ) {
			return $user_id;
		}

		// find the access token
		$auth  = static::get_authorization_header();
		$token = mp_get( $_POST, 'access_token' ); // phpcs:ignore
		if ( ! $auth && ! $token ) {
			static::$error = new WP_Micropub_Error( 'unauthorized', 'missing access token', 401 );
			return $user_id;
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
			static::$error = $resp;
			return $user_id;
		}

		$code           = wp_remote_retrieve_response_code( $resp );
		$body           = wp_remote_retrieve_body( $resp );
		$params         = json_decode( $body, true );
		static::$scopes = explode( ' ', $params['scope'] );

		if ( (int) ( $code / 100 ) !== 2 ) {
			static::$error = new WP_Micropub_Error( 'invalid_request', 'invalid access token', 403 );
			return $user_id;
		} elseif ( empty( static::$scopes ) ) {
			static::$error = new WP_Micropub_Error( 'insufficient_scope', 'access token is missing scope', 401 );
			return $user_id;
		}

		$me                             = untrailingslashit( $params['me'] );
		static::$micropub_auth_response = $params;

		// look for a user with the same url as the token's `me` value.
		$user_id = static::url_to_user( $me );

		// IndieAuth Plugin uses priority 9
		// TODO: These filters are added here to ensure that they are loaded after the scope is set
		// However future changes will change the order so this is no longer needed
		add_filter( 'indieauth_scopes', array( $cls, 'indieauth_scopes' ), 11 );
		add_filter( 'indieauth_response', array( $cls, 'indieauth_response' ), 11 );

		if ( $user_id ) {
			return $user_id;
		}
		// Nothing was found return 0 to indicate no privileges given
		return 0;
	}

	/**
	 * Try to match a user with a URL with or without trailing slash.
	 *
	 * @param string $me URL to match
	 *
	 * @return int Return user ID if matched or 0
	**/
	public static function url_to_user( $me ) {
		if ( empty( $me ) ) {
			return 0;
		}
		if ( ( 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) ) && ( wp_parse_url( home_url(), PHP_URL_HOST ) === wp_parse_url( $me, PHP_URL_HOST ) ) ) {
			$me = set_url_scheme( $me, 'https' );
		}
		$me = trailingslashit( $me );
		// Try to save the expense of a search query if the URL is the site URL
		if ( home_url( '/' ) === $me ) {
			// Use the Indieweb settings to set the default author
			if ( class_exists( 'Indieweb_Plugin' ) && ( get_option( 'iw_single_author' ) || ! is_multi_author() ) ) {
				return get_option( 'iw_default_author' );
			}
			$users = get_users(
				array(
					'who'    => 'authors',
					'fields' => 'ID',
				)
			);
			if ( 1 === count( $users ) ) {
				return $users[0];
			}
		}
		// Check if this is a author post URL
		$user_id = static::url_to_author( $me );
		if ( 0 !== $user_id ) {
			return $user_id;
		}

		$search = array(
			'search'         => $me,
			'search_columns' => array( 'user_url' ),
		);
		$users  = get_users( $search );

		$search['search'] = untrailingslashit( $me );
		$users            = array_merge( $users, get_users( $search ) );
		foreach ( $users as $user ) {
			if ( untrailingslashit( $user->user_url ) === untrailingslashit( $me ) ) {
				return $user->ID;
			}
		}
		return 0;
	}

	/**
	 * Examine a url and try to determine the author ID it represents.
	 *
	 *
	 * @param string $url Permalink to check.
	 *
	 * @return $user_id, or 0 on failure.
	 */
	private static function url_to_author( $url ) {
		global $wp_rewrite;
		// check if url hase the same host
		if ( wp_parse_url( site_url(), PHP_URL_HOST ) !== wp_parse_url( $url, PHP_URL_HOST ) ) {
			return 0;
		}
		// first, check to see if there is a 'author=N' to match against
		if ( preg_match( '/[?&]author=(\d+)/i', $url, $values ) ) {
			$id = absint( $values[1] );
			if ( $id ) {
				return $id;
			}
		}
		// check to see if we are using rewrite rules
		$rewrite = $wp_rewrite->wp_rewrite_rules();
		// not using rewrite rules, and 'author=N' method failed, so we're out of options
		if ( empty( $rewrite ) ) {
			return 0;
		}
		// generate rewrite rule for the author url
		$author_rewrite = $wp_rewrite->get_author_permastruct();
		$author_regexp  = str_replace( '%author%', '', $author_rewrite );
		// match the rewrite rule with the passed url
		if ( preg_match( '/https?:\/\/(.+)' . preg_quote( $author_regexp, '/' ) . '([^\/]+)/i', $url, $match ) ) {
			$user = get_user_by( 'slug', $match[2] );
			if ( $user ) {
				return $user->ID;
			}
		}
		return 0;
	}


}
