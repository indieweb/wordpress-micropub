<?php

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

if ( ! function_exists( 'mp_get' ) ) {
	function mp_get( $array, $key, $default = array(), $index = false ) {
		$return = $default;
		if ( is_array( $array ) && isset( $array[ $key ] ) ) {
			$return = $array[ $key ];
		}
		if ( $index && wp_is_numeric_array( $return ) && ! empty( $return ) ) {
			$return = $return[0];
		}
		return $return;
	}
}

if ( ! function_exists( 'mp_filter' ) ) {
	// Searches for partial matches in an array of strings
	function mp_filter( $array, $filter ) {
		return array_values(
			array_filter(
				$array,
				function( $value ) use ( $filter ) {
					return ( false !== stripos( $value, $filter ) );
				}
			)
		);
	}
}

if ( ! function_exists( 'micropub_get_response' ) ) {
	function micropub_get_response() {
		return apply_filters( 'indieauth_response', null );
	}
}

if ( ! function_exists( 'micropub_get_scopes' ) ) {
	function micropub_get_scopes() {
		return apply_filters( 'indieauth_scopes', null );
	}
}

if ( ! function_exists( 'micropub_get_post_datetime' ) ) {
	function micropub_get_post_datetime( $post = null, $field = 'date', $timezone = null ) {
		$post = get_post( $post );
		$datetime = get_post_datetime( $post, $field, 'gmt' );
		if ( is_null( $timezone ) ) {
			$timezone = get_post_meta( $post->ID, 'geo_timezone', true );
		}
		if ( $timezone ) {
			$timezone = new DateTimeZone( $timezone );
			return $datetime->setTimezone( $timezone );
		}
		return $datetime;
	}
}
