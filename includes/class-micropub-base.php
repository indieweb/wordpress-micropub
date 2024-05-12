<?php

/**
 * Micropub Baseb Endpoint Class
 */
abstract class Micropub_Base {
	// associative array.
	protected static $micropub_auth_response = array();

	// Array of Scopes
	protected static $scopes = array();

	public static function get_namespace() {
		return defined( MICROPUB_NAMESPACE ) ? MICROPUB_NAMESPACE : 'micropub/1.0';
	}

	public static function get_rel() {
		return 'micropub';
	}

	public static function get_route( $slash = false ) {
		$return = static::get_namespace() . '/endpoint';
		return $slash ? '/' . $return : $return;
	}

	public static function get_endpoint() {
		return rest_url( static::get_route() );
	}

	/**
	 * The autodicovery meta tags
	 */
	public static function html_header() {
		// phpcs:ignore
		printf( '<link rel="%1$s" href="%2$s" />' . PHP_EOL, static::get_rel(), static::get_endpoint() );
	}

	public static function header( $header, $value ) {
		header( $header . ': ' . $value, false );
	}

	/**
	 * The autodicovery http-header
	 */
	public static function http_header() {
		static::header( 'Link', sprintf( '<%1$s>; rel="%2$s"', static::get_endpoint(), static::get_rel() ) );
	}

	/**
	 * Generates webfinger/host-meta links
	 */
	public static function jrd_links( $links ) {
		$links['links'][] = array(
			'rel'  => static::get_rel(),
			'href' => static::get_endpoint(),
		);
		return $links;
	}


	public static function return_micropub_error( $response, $handler, $request ) {
		if ( static::get_route() !== $request->get_route() ) {
			return $response;
		}
		if ( is_wp_error( $response ) ) {
			return micropub_wp_error( $response );
		}
		return $response;
	}

	public static function log_error( $message, $name = 'Micropub' ) {
		if ( empty( $message ) || defined( 'DIR_TESTDATA' ) ) {
			return false;
		}
		if ( is_array( $message ) || is_object( $message ) ) {
			$message = wp_json_encode( $message );
		}

		return error_log( sprintf( '%1$s: %2$s', $name, $message ) ); // phpcs:ignore
	}

	public static function get( $a, $key, $args = array() ) {
		if ( is_array( $a ) ) {
			return isset( $a[ $key ] ) ? $a[ $key ] : $args;
		}
		return $args;
	}

	public static function load_auth() {
		// Check if logged in
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'forbidden', 'Unauthorized', array( 'status' => 403 ) );
		}

		static::$micropub_auth_response = micropub_get_response();
		static::$scopes                 = micropub_get_scopes();

		// If there is no auth response this is cookie authentication which should be rejected
		// https://www.w3.org/TR/micropub/#authentication-and-authorization - Requests must be authenticated by token
		if ( empty( static::$micropub_auth_response ) ) {
			return new WP_Error( 'unauthorized', 'Cookie Authentication is not permitted', array( 'status' => 401 ) );
		}
		return true;
	}

	public static function check_query_permissions( $request ) {
		$auth = self::load_auth();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$query = $request->get_param( 'q' );
		if ( ! $query ) {
			return new WP_Error( 'invalid_request', 'Missing Query Parameter', array( 'status' => 400 ) );
		}

		return true;
	}

	protected static function check_error( $result ) {
		if ( ! $result ) {
			return new WP_Micropub_Error( 'invalid_request', $result, 400 );
		} elseif ( is_wp_error( $result ) ) {
			return micropub_wp_error( $result );
		}
		return $result;
	}

	/**
	 * Returns the mf2 properties for a post.
	 */
	public static function get_mf2( $post_id = null ) {
		$mf2  = array();
		$post = get_post( $post_id );

		foreach ( get_post_meta( $post_id ) as $field => $val ) {
			$val = maybe_unserialize( $val[0] );
			if ( 'mf2_type' === $field ) {
				$mf2['type'] = $val;
			} elseif ( 'mf2_' === substr( $field, 0, 4 ) ) {
				$mf2['properties'][ substr( $field, 4 ) ] = $val;
			}
		}

		// Time Information
		$published                      = micropub_get_post_datetime( $post );
		$updated                        = micropub_get_post_datetime( $post, 'modified' );
		$mf2['properties']['published'] = array( $published->format( DATE_W3C ) );
		if ( $published->getTimestamp() !== $updated->getTimestamp() ) {
			$mf2['properties']['updated'] = array( $updated->format( DATE_W3C ) );
		}

		if ( ! empty( $post->post_title ) ) {
			$mf2['properties']['name'] = array( $post->post_title );
		}

		if ( ! empty( $post->post_excerpt ) ) {
			$mf2['properties']['summary'] = array( htmlspecialchars_decode( $post->post_excerpt ) );
		}
		if ( ! array_key_exists( 'content', $mf2['properties'] ) && ! empty( $post->post_content ) ) {
			$mf2['properties']['content'] = array( htmlspecialchars_decode( $post->post_content ) );
		}

		return $mf2;
	}
}
