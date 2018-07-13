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

	/**
	 * Initialize the plugin.
	 */
	public static function init() {
		$cls = get_called_class();

		add_action( 'wp_head', array( $cls, 'html_header' ), 99 );
		add_action( 'send_headers', array( $cls, 'http_header' ) );
		add_filter( 'host_meta', array( $cls, 'jrd_links' ) );
		add_filter( 'webfinger_user_data', array( $cls, 'jrd_links' ) );
		// The WordPress IndieAuth plugin uses priority 30
		add_filter( 'determine_current_user', array( $cls, 'determine_current_user' ), 31 );

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
			printf( '<link rel="authorization_endpoint" href="%s" />' . PHP_EOL, get_option( 'indieauth_authorization_endpoint', MICROPUB_AUTHENTICATION_ENDPOINT ) );
			printf( '<link rel="token_endpoint" href="%s" />' . PHP_EOL, get_option( 'indieauth_token_endpoint', MICROPUB_TOKEN_ENDPOINT ) );
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

	public static function get( $array, $key, $default = array() ) {
		if ( is_array( $array ) ) {
			return isset( $array[ $key ] ) ? $array[ $key ] : $default;
		}
		return $default;
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

		// Since this runs on the built-in determine_current user filter only try to authenticate if this is the Micropub endpoint
		if ( ! empty( get_query_var( 'micropub' ) ) ) {
			return $user_id;
		}

		// find the access token
		$auth  = static::get_authorization_header();
		$token = self::get( $_POST, 'access_token' );
		if ( ! $auth && ! $token ) {
			static::authorize_error( 401, 'missing access token' );
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
			return $resp;
		}

		$code           = wp_remote_retrieve_response_code( $resp );
		$body           = wp_remote_retrieve_body( $resp );
		$params         = json_decode( $body, true );
		static::$scopes = explode( ' ', $params['scope'] );

		if ( (int) ( $code / 100 ) !== 2 ) {
			return static::authorize_error(
				$code, 'invalid access token: ' . $body
			);
		} elseif ( empty( static::$scopes ) ) {
			return static::authorize_error(
				401, 'access token is missing scope'
			);
		}

		$me                             = untrailingslashit( $params['me'] );
		static::$micropub_auth_response = $params;

		// look for a user with the same url as the token's `me` value.
		$user = static::user_url( $me );

		// IndieAuth Plugin uses priority 9		
		add_filter( 'indieauth_scopes', array( $cls, 'indieauth_scopes' ), 11 );
		add_filter( 'indieauth_response', array( $cls, 'indieauth_response' ), 11 );

		if ( $user ) {
			return $user;
		}

		return $user_id;
	}

	private static function authorize_error( $code, $msg ) {
		return new WP_Error(
			'forbidden',
			$msg,
			array(
				'status' => $code
			)
		);
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
}
