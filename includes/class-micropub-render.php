<?php


/*  Generate Post Content and Save it for Pre 2.4.0 versions
 * add_filter( 'micropub_post_content', array( 'Micropub_Render', 'generate_post_content' ), 1, 2 );
 */

add_filter( 'the_content', array( 'Micropub_Render', 'render_content' ), 1 );

/**
 * Micropub Render Class
 */
class Micropub_Render {
	/**
	 * Dynamically Renders Microformats 2
	 *
	 */
	public static function render_content( $content ) {
		// If this is not a micropub post return without any further work.
		if ( ! is_micropub_post() ) {
			return $content;
		}

		if ( self::should_dynamic_render() ) {
			$input = Micropub_Base::get_mf2( get_the_ID() );
			return self::generate_post_content( $content, $input );
		}

		return $content;
	}

	public static function should_dynamic_render( $post = null ) {
		$post = get_post();
		if ( class_exists( 'Post_Kinds_Plugin' ) ) {
			$should = false;
		} else {
			$version = get_post_meta( $post->ID, 'micropub_version', true );
			if ( ! $version ) {
				$should = false;
			} elseif ( get_post_meta( $post->ID, 'mf2_content', true ) ) {
				$should = false;
			} else {
				$should = true;
			}
		}
		return apply_filters( 'micropub_dynamic_render', $should, $post );
	}

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
			'follow-of'   => 'Follows',
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
					if ( 'in-reply-to' === $prop && ! isset( $props['rsvp'] ) ) {
						// Special case replies. Don't include any visible text,
						// since it goes inside e-content, which webmention
						// recipients use as the reply text.
						$lines[] = sprintf(
							'<a class="u-%1s" href="%2s"></a>',
							$prop,
							$val['url']
						);
					} else {
						$lines[] = sprintf(
							'<p>%1s <a class="u-%2s" href="%3s">%4s</a>.</p>',
							$verbs[ $prop ],
							$prop,
							$val['url'],
							$val['name']
						);
					}
				}
			}
		}

		$checkin = isset( $props['checkin'] );
		if ( $checkin ) {
			$checkin = wp_is_numeric_array( $props['checkin'] ) ? $props['checkin'][0] : $props['checkin'];
			$name    = $checkin['properties']['name'][0];
			$urls    = $checkin['properties']['url'];
			$lines[] = '<p>Checked into <a class="h-card p-location" href="' .
				( isset( $urls[1] ) ? $urls[1] : $urls[0] ) . '">' . $name . '</a>.</p>';
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
		$props   = mp_get( $input, 'replace', mp_get( $input, 'properties' ) );
		$lines[] = '<div class="h-event">';

		if ( isset( $props['name'] ) ) {
			$lines[] = '<h1 class="p-name">' . $props['name'][0] . '</h1>';
		}

		$lines[] = '<p>';
		$times   = array();
		foreach ( array( 'start', 'end' ) as $cls ) {
			if ( isset( $props[ $cls ][0] ) ) {
				$datetime = new DateTimeImmutable( $props[ $cls ][0] );
				$times[]  = '<time class="dt-' . $cls . '" datetime="' .
					$datetime->format( DATE_W3C ) . '">' . $datetime->format( DATE_W3C ) . '</time>';
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
