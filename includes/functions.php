<?php
/**
 * Micropub utility functions.
 *
 * @package Micropub
 */

/**
 * Check if an array is associative.
 *
 * @param mixed $assoc Value to check.
 * @return bool True if associative array.
 */
function is_assoc_array( $assoc ) {
	return is_array( $assoc ) && array_values( $assoc ) !== $assoc;
}


if ( ! function_exists( 'getallheaders' ) ) {
	/**
	 * Get all HTTP headers.
	 *
	 * Polyfill for getallheaders() function.
	 *
	 * @see https://github.com/idno/Known/blob/master/Idno/Pages/File/View.php#L25
	 * @return array HTTP headers.
	 */
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
	/**
	 * Get a value from an array by key.
	 *
	 * @param array  $data  The data array.
	 * @param string $key   The key to get.
	 * @param mixed  $def   Default value if key not found.
	 * @param bool   $index Whether to return only first element.
	 * @return mixed The value or default.
	 */
	function mp_get( $data, $key, $def = array(), $index = false ) {
		$return = $def;
		if ( is_array( $data ) && isset( $data[ $key ] ) ) {
			$return = $data[ $key ];
		}
		if ( $index && wp_is_numeric_array( $return ) && ! empty( $return ) ) {
			$return = $return[0];
		}
		return $return;
	}
}

if ( ! function_exists( 'mp_filter' ) ) {
	/**
	 * Searches for partial matches in an array of strings.
	 *
	 * @param array  $a      The array to filter.
	 * @param string $filter The filter string to match.
	 * @return array Filtered array values.
	 */
	function mp_filter( $a, $filter ) {
		return array_values(
			array_filter(
				$a,
				function ( $value ) use ( $filter ) {
					return ( false !== stripos( $value, $filter ) );
				}
			)
		);
	}
}

if ( ! function_exists( 'micropub_get_response' ) ) {
	/**
	 * Get the Micropub authentication response.
	 *
	 * @return mixed|null The IndieAuth response or null.
	 */
	function micropub_get_response() {
		return apply_filters( 'indieauth_response', null );
	}
}

if ( ! function_exists( 'is_micropub_post' ) ) {
	/**
	 * Check if a post was created via Micropub.
	 *
	 * @param WP_Post|int|null $post Post object or ID.
	 * @return bool True if created via Micropub.
	 */
	function is_micropub_post( $post = null ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return false;
		}
		$response = get_post_meta( $post->ID, 'micropub_version', true );
		if ( $response ) {
			return true;
		}
		$response = get_post_meta( $post->ID, 'micropub_auth_response', true );
		if ( ! $response ) {
			return false;
		}
		return true;
	}
}

if ( ! function_exists( 'micropub_get_client_info' ) ) {
	/**
	 * Get the Micropub client info for a post.
	 *
	 * @param WP_Post|int|null $post Post object or ID.
	 * @return array|string|false Client info array, empty string, or false.
	 */
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
	/**
	 * Display the Micropub client info for a post.
	 *
	 * @param WP_Post|int|null $post Post object or ID.
	 * @param array|null       $args Display arguments.
	 */
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
			$text = esc_html( $client['name'] );
		} else {
			$text = 'Unknown Client';
		}

		if ( array_key_exists( 'id', $client ) ) {
			printf( '<%1$s class="%2$s"><a href="%3$s">%4$s</a></%1$s>', esc_attr( $args['container'] ), esc_attr( $args['class'] ), esc_url( get_term_link( $client['id'] ), 'indieauth_client' ), wp_kses_post( $text ) );
		} else {
			printf( '<%1$s class="%1$s">%2$s</%1$s>', esc_attr( $args['container'] ), esc_attr( $args['class'] ), wp_kses_post( $text ) );
		}
	}
}

if ( ! function_exists( 'micropub_get_scopes' ) ) {
	/**
	 * Get the current IndieAuth scopes.
	 *
	 * @return mixed|null The scopes or null.
	 */
	function micropub_get_scopes() {
		return apply_filters( 'indieauth_scopes', null );
	}
}

if ( ! function_exists( 'micropub_get_post_datetime' ) ) {
	/**
	 * Get the post datetime with proper timezone handling.
	 *
	 * @param WP_Post|int|null $post     Post object or ID.
	 * @param string           $field    Date field ('date' or 'modified').
	 * @param string|null      $timezone Timezone string or null for default.
	 * @return DateTimeImmutable|false DateTime object or false.
	 */
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

if ( ! function_exists( 'get_micropub_error' ) ) {
	/**
	 * Get a Micropub error from an object or response.
	 *
	 * @param mixed $obj Object or array to check for error.
	 * @return \Micropub\Error|false Error object or false.
	 */
	function get_micropub_error( $obj ) {
		if ( is_array( $obj ) ) {
			// When checking the result of wp_remote_post.
			if ( isset( $obj['body'] ) ) {
				$body = json_decode( $obj['body'], true );
				if ( isset( $body['error'] ) ) {
					return new \Micropub\Error(
						$body['error'],
						isset( $body['error_description'] ) ? $body['error_description'] : null,
						$obj['response']['code']
					);
				}
			}
		} elseif ( is_object( $obj ) && 'Micropub\Error' === get_class( $obj ) ) {
			$data = $obj->get_data();
			if ( isset( $data['error'] ) ) {
				return $obj;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'is_micropub_error' ) ) {
	/**
	 * Check if an object is a Micropub error.
	 *
	 * @param mixed $obj Object to check.
	 * @return bool True if Micropub error.
	 */
	function is_micropub_error( $obj ) {
		return ( $obj instanceof \Micropub\Error );
	}
}

if ( ! function_exists( 'micropub_wp_error' ) ) {
	/**
	 * Converts WP_Error into Micropub Error.
	 *
	 * @param WP_Error $error The WP_Error object.
	 * @return \Micropub\Error|null The Micropub error or null.
	 */
	function micropub_wp_error( $error ) {
		if ( is_wp_error( $error ) ) {
			$data   = $error->get_error_data();
			$status = isset( $data['status'] ) ? $data['status'] : 200;
			if ( is_array( $data ) ) {
				unset( $data['status'] );
			}
			return new \Micropub\Error( $error->get_error_code(), $error->get_error_message(), $status, $data );
		}
		return null;
	}
}

if ( ! function_exists( 'micropub_get_mf2' ) ) {
	/**
	 * Get MF2 properties for a post.
	 *
	 * @param int|null $post_id Post ID.
	 * @return array MF2 properties.
	 */
	function micropub_get_mf2( $post_id = null ) {
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

		// Time Information.
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
