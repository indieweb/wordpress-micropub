<?php


if ( ! function_exists( 'current_datetime' ) ) {
	/**
	 * Retrieves the current time as an object with the timezone from settings.
	 *
	 * @since 5.3.0 - Backported to Micropub
	 *
	 * @return DateTimeImmutable Date and time object.
	 */
	function current_datetime() {
		return new DateTimeImmutable( 'now', wp_timezone() );
	}
}

if ( ! function_exists( 'get_post_datetime' ) ) {
	/**
	 * Retrieve post published or modified time as a `DateTime` object instance.
	 *
	 * The object will be set to the timezone from WordPress settings.
	 *
	 * @since 5.3.0 - backported to Micropub
	 *
	 * @param int|WP_Post $post  Optional. WP_Post object or ID. Default is global `$post` object.
	 * @param string      $field Optional. Post field to use. Accepts 'date' or 'modified'.
	 * @return DateTimeImmutable|false Time object on success, false on failure.
	 */
	function get_post_datetime( $post = null, $field = 'date' ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return false;
		}
		$time = ( 'modified' === $field ) ? $post->post_modified : $post->post_date;
		if ( empty( $time ) || '0000-00-00 00:00:00' === $time ) {
			return false;
		}
		return date_create_from_format( 'Y-m-d H:i:s', $time, wp_timezone() );
	}
}

if ( ! function_exists( 'wp_timezone_string' ) ) {
	/**
	 * Retrieves the timezone from site settings as a string.
	 *
	 * Uses the `timezone_string` option to get a proper timezone if available,
	 * otherwise falls back to an offset.
	 *
	 * @since 5.3.0 - backported into Micropub
	 *
	* @return string PHP timezone string or a ±HH:MM offset.
	*/
	function wp_timezone_string() {
		$timezone_string = get_option( 'timezone_string' );
		if ( $timezone_string ) {
			return $timezone_string;
		}
		$offset    = (float) get_option( 'gmt_offset' );
		$hours     = (int) $offset;
		$minutes   = ( $offset - $hours );
		$sign      = ( $offset < 0 ) ? '-' : '+';
		$abs_hour  = abs( $hours );
		$abs_mins  = abs( $minutes * 60 );
		$tz_offset = sprintf( '%s%02d:%02d', $sign, $abs_hour, $abs_mins );
		return $tz_offset;
	}
}

if ( ! function_exists( 'wp_timezone' ) ) {
	/**
	 * Retrieves the timezone from site settings as a `DateTimeZone` object.
	 *
	 * Timezone can be based on a PHP timezone string or a ±HH:MM offset.
	 *
	 * @since 5.3.0 - backported into Simple Location
	 *
	 * @return DateTimeZone Timezone object.
	*/
	function wp_timezone() {
		return new DateTimeZone( wp_timezone_string() );
	}
}

// Polyfill for pre-PHP 7.3.
if ( ! function_exists( 'array_key_first' ) ) {
	function array_key_first( array $arr ) {
		foreach ( $arr as $key => $unused ) {
			return $key;
		}
		return null;
	}
}

// Polyfill for pre-PHP 7.3.
if ( ! function_exists( 'array_key_last' ) ) {
	function array_key_last( $a ) {
		if ( ! is_array( $a ) || empty( $a ) ) {
			return null;
		}
		return array_keys( $a )[ count( $a ) - 1 ];
	}
}
