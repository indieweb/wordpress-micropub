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

class MicropubMicroformats2DetectionTest extends WP_UnitTestCase {
	function tear_down() {
		// Clean up after each test.
		remove_theme_support( 'microformats2' );
		delete_option( \Micropub\Micropub::MICROFORMATS2_SUPPORT_OPTION );
		parent::tear_down();
	}

	function test_theme_supports_microformats2_with_explicit_support() {
		add_theme_support( 'microformats2' );

		$this->assertTrue( \Micropub\Micropub::theme_supports_microformats2() );
	}

	function test_theme_supports_microformats2_with_cached_yes() {
		update_option( \Micropub\Micropub::MICROFORMATS2_SUPPORT_OPTION, 'yes' );

		$this->assertTrue( \Micropub\Micropub::theme_supports_microformats2() );
	}

	function test_theme_supports_microformats2_with_cached_no() {
		update_option( \Micropub\Micropub::MICROFORMATS2_SUPPORT_OPTION, 'no' );

		$this->assertFalse( \Micropub\Micropub::theme_supports_microformats2() );
	}

	function test_theme_supports_microformats2_returns_false_when_not_cached() {
		// No theme support and no cached value.
		$this->assertFalse( \Micropub\Micropub::theme_supports_microformats2() );
	}

	function test_clear_microformats2_support_cache() {
		update_option( \Micropub\Micropub::MICROFORMATS2_SUPPORT_OPTION, 'yes' );

		$micropub = \Micropub\Micropub::get_instance();
		$micropub->clear_microformats2_support_cache();

		$this->assertFalse( get_option( \Micropub\Micropub::MICROFORMATS2_SUPPORT_OPTION ) );
	}

	function test_detect_microformats2_support_finds_econtent_in_class() {
		// Start output buffering to simulate page output.
		ob_start();
		echo '<html><body><div class="entry-content e-content">Test</div></body></html>';

		\Micropub\Micropub::detect_microformats2_support();

		ob_end_clean();

		$this->assertEquals( 'yes', get_option( \Micropub\Micropub::MICROFORMATS2_SUPPORT_OPTION ) );
	}

	function test_detect_microformats2_support_ignores_econtent_in_code_blocks() {
		// Start output buffering to simulate page output.
		ob_start();
		echo '<html><body><code>class="e-content"</code></body></html>';

		\Micropub\Micropub::detect_microformats2_support();

		ob_end_clean();

		$this->assertEquals( 'no', get_option( \Micropub\Micropub::MICROFORMATS2_SUPPORT_OPTION ) );
	}

	function test_detect_microformats2_support_ignores_econtent_in_pre_blocks() {
		// Start output buffering to simulate page output.
		ob_start();
		echo '<html><body><pre>class="e-content"</pre></body></html>';

		\Micropub\Micropub::detect_microformats2_support();

		ob_end_clean();

		$this->assertEquals( 'no', get_option( \Micropub\Micropub::MICROFORMATS2_SUPPORT_OPTION ) );
	}

	function test_detect_microformats2_support_ignores_econtent_as_plain_text() {
		// Start output buffering to simulate page output.
		ob_start();
		echo '<html><body><p>The e-content class is used in microformats2.</p></body></html>';

		\Micropub\Micropub::detect_microformats2_support();

		ob_end_clean();

		$this->assertEquals( 'no', get_option( \Micropub\Micropub::MICROFORMATS2_SUPPORT_OPTION ) );
	}
}
