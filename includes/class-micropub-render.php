<?php

// Post Content Filter
add_filter( 'micropub_post_content', array( 'Micropub_Render', 'generate_post_content' ), 1, 2 );


/**
 * Micropub Render Class
 */
class Micropub_Render {

	/**
	 * Generates and returns a post_content string suitable for wp_insert_post()
	 * and friends.
	 */
	public static function generate_post_content( $post_content, $input ) {
		$props = mp_get( $input, 'properties' );
		$lines = array();

		$verbs = array(
			'like-of'     => 'Likes',
			'repost-of'   => 'Reposted',
			'in-reply-to' => 'In reply to',
			'bookmark-of' => 'Bookmarked',
		);

		// interactions
		foreach ( array_keys( $verbs ) as $prop ) {
			if ( ! isset( $props[ $prop ] ) ) {
				continue;
			}

			if ( wp_is_numeric_array( $props[ $prop ] ) ) {
				$val = $props[ $prop ][0];
			} else {
				$val = $props[ $prop ];
			}
			if ( $val ) {
				// Supports nested properties by turning single value properties into nested
				// https://micropub.net/draft/#nested-microformats-objects
				if ( is_string( $val ) ) {
					$val = array(
						'url' => $val,
					);
				}
				if ( ! isset( $val['name'] ) && isset( $val['url'] ) ) {
					$val['name'] = $val['url'];
				}
				if ( isset( $val['url'] ) ) {
					$lines[] = sprintf(
						'<p>%1s <a class="u-%2s" href="%3s">%4s</a>.</p>',
						$verbs[ $prop ],
						$prop, $val['url'],
						$val['name']
					);
				}
			}
		}

		$checkin = isset( $props['checkin'] ) ? $props['checkin'][0] : null;
		if ( $checkin ) {
			$name    = $checkin['properties']['name'][0];
			$urls    = $checkin['properties']['url'];
			$lines[] = '<p>Checked into <a class="h-card p-location" href="' .
				( $urls[1] ?: $urls[0] ) . '">' . $name . '</a>.</p>';
		}

		if ( isset( $props['rsvp'] ) ) {
			$lines[] = '<p>RSVPs <data class="p-rsvp" value="' . $props['rsvp'][0] .
			  '">' . $props['rsvp'][0] . '</data>.</p>';
		}

		// event
		if ( array( 'h-event' ) === mp_get( $input, 'type' ) ) {
			$lines[] = static::generate_event( $input );
		}

		// If there is no content use the summary property as content
		if ( empty( $post_content ) && isset( $props['summary'] ) ) {
			$post_content = $props['summary'][0];
		}

		if ( ! empty( $post_content ) ) {
			$lines[] = '<div class="e-content">';
			$lines[] = $post_content;
			$lines[] = '</div>';
		}

		// TODO: generate my own markup so i can include u-photo
		foreach ( array( 'photo', 'video', 'audio' ) as $field ) {
			if ( isset( $_FILES[ $field ] ) || isset( $props[ $field ] ) ) {
				$lines[] = '[gallery size=full columns=1]';
				break;
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Generates and returns a string h-event.
	 */
	private static function generate_event( $input ) {
		$props   = $input['replace'] ?: $input['properties'];
		$lines[] = '<div class="h-event">';

		if ( isset( $props['name'] ) ) {
			$lines[] = '<h1 class="p-name">' . $props['name'][0] . '</h1>';
		}

		$lines[] = '<p>';
		$times   = array();
		foreach ( array( 'start', 'end' ) as $cls ) {
			if ( isset( $props[ $cls ][0] ) ) {
				$datetime = iso8601_to_datetime( $props[ $cls ][0] );
				$times[]  = '<time class="dt-' . $cls . '" datetime="' .
					$props[ $cls ][0] . '">' . $datetime . '</time>';
			}
		}
		$lines[] = implode( "\nto\n", $times );

		if ( isset( $props['location'] ) && 'geo:' !== substr( $props['location'][0], 0, 4 ) ) {
			$lines[] = 'at <a class="p-location" href="' . $props['location'][0] . '">' .
			$props['location'][0] . '</a>';
		}

		end( $lines );
		$lines[ key( $lines ) ] .= '.';
		$lines[]                 = '</p>';

		if ( isset( $props['summary'] ) ) {
			$lines[] = '<p class="p-summary">' . urldecode( $props['summary'][0] ) . '</p>';
		}

		if ( isset( $props['description'] ) ) {
			$lines[] = '<p class="p-description">' . urldecode( $props['description'][0] ) . '</p>';
		}

		$lines[] = '</div>';
		return implode( "\n", $lines );
	}

}
