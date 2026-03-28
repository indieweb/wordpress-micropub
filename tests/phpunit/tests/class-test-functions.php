<?php

/** Unit tests for the Micropub Global Functions.
 */

class MicropubFunctionsTest extends WP_UnitTestCase {
	function test_mp_filter() {
		$input  = array(
			'webmention',
			'jsonfeed',
			'micropub',
			'author',
			'foo',
			'bar',
			'indieweb',
			'indieweb-goals',
			'indienews',
		);
		$return = mp_filter( $input, 'indie' );
		$this->assertEquals(
			$return,
			array(
				'indieweb',
				'indieweb-goals',
				'indienews',
			)
		);
	}

	function test_micropub_get_mf2_skips_empty_string_properties() {
		$post_id = $this->factory->post->create();

		// Add an empty string property (simulates unset property).
		update_post_meta( $post_id, 'mf2_location', '' );
		// Add a valid property.
		update_post_meta( $post_id, 'mf2_category', array( 'test' ) );

		$mf2 = micropub_get_mf2( $post_id );

		// Empty string property should be skipped.
		$this->assertArrayNotHasKey( 'location', $mf2['properties'] );
		// Valid property should exist.
		$this->assertArrayHasKey( 'category', $mf2['properties'] );
	}

	function test_micropub_get_mf2_wraps_non_array_values() {
		$post_id = $this->factory->post->create();

		// Add a non-array property value (string).
		update_post_meta( $post_id, 'mf2_syndication', 'https://twitter.com/example/123' );

		$mf2 = micropub_get_mf2( $post_id );

		// Property value should be wrapped in an array.
		$this->assertIsArray( $mf2['properties']['syndication'] );
		$this->assertEquals( array( 'https://twitter.com/example/123' ), $mf2['properties']['syndication'] );
	}

	function test_micropub_get_mf2_preserves_array_values() {
		$post_id = $this->factory->post->create();

		// Add an array property value.
		$categories = array( 'tech', 'indieweb' );
		update_post_meta( $post_id, 'mf2_category', $categories );

		$mf2 = micropub_get_mf2( $post_id );

		// Property value should remain as array.
		$this->assertIsArray( $mf2['properties']['category'] );
		$this->assertEquals( $categories, $mf2['properties']['category'] );
	}
}
