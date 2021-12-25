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

if ( ! function_exists( 'micropub_get_client_info' ) ) {
	function micropub_get_client_info( $post = null ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return false;
		}
		$response = get_post_meta( $post->ID, 'micropub_auth_response', true );
		if ( empty( $response ) ) {
			return '';
		}
		if ( class_exists( 'IndieAuth_Client_Taxonomy' ) ) {
			if ( array_key_exists( 'client_uid', $response ) ) {
				return IndieAuth_Client_Taxonomy::get_client( $response['client_uid'] );
			}
			if ( array_key_exists( 'client_id', $response ) ) {
				$return = IndieAuth_Client_Taxonomy::get_client( $response['client_id'] );
				if ( ! is_wp_error( $return ) ) {
					return $return;
				}
			}
		}

		return array_filter(
			array(
				'client_id' => $response['client_id'],
				'name'      => mp_get( $response, 'client_name', null ),
				'icon'      => mp_get( $response, 'client_icon', null ),
			)
		);
	}
}

if ( ! function_exists( 'micropub_client_info' ) ) {
	function micropub_client_info( $post = null, $args = null ) {
		$client   = micropub_get_client_info( $post );
		$defaults = array(
			'size'      => 15,
			'class'     => 'micropub-client',
			'container' => 'div',
		);

		$args = wp_parse_args( $args, $defaults );
		if ( is_wp_error( $client ) || empty( $client ) ) {
			return '';
		}
		if ( array_key_exists( 'icon', $client ) ) {
			$props = array(
				'src'    => $client['icon'],
				'height' => $args['size'],
				'width'  => $args['size'],
				'title'  => $client['name'],
			);

			$text = '<img';
			foreach ( $props as $key => $value ) {
				$text .= ' ' . esc_attr( $key ) . '="' . esc_attr( $value ) . '"';
			}
			$text .= ' />';
		} elseif ( array_key_exists( 'name', $client ) ) {
			$text = sanitize_text( $name );
		} else {
			$text = 'Unknown Client';
		}

		if ( array_key_exists( 'id', $client ) ) {
			printf( '<%1$s class="%2$s"><a href="%3$s">%4$s</a></%1$s>', esc_attr( $args['container'] ), esc_attr( $args['class'] ), esc_url( get_term_link( $client['id'] ), 'indieauth_client' ), wp_kses_post( $text ) );
		} else {
			printf( '<%1$s class="%1$s">%2$S</%1$s>', esc_attr( $args['container'] ), esc_attr( $args['class'] ), wp_kses_post( $text ) );
		}

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
		if ( ! $post ) {
			return false;
		}

		$time = ( 'modified' === $field ) ? $post->post_modified_gmt : $post->post_date_gmt;
		if ( empty( $time ) || '0000-00-00 00:00:00' === $time ) {
			return false;
		}

		$datetime = date_create_immutable_from_format( 'Y-m-d H:i:s', $time, new DateTimeZone( 'UTC' ) );

		if ( is_null( $timezone ) ) {
			$timezone = get_post_meta( $post->ID, 'geo_timezone', true );
		}

		if ( $timezone ) {
			$timezone = new DateTimeZone( $timezone );
		} else {
			$timezone = wp_timezone();
		}
		return $datetime->setTimezone( $timezone );
	}
}
