<?php

/** Unit tests for the Micropub Rendering class.
 */

class MicropubRenderTest extends WP_UnitTestCase {

	function test_create_checkin_autogenerates_checkin_text_with_content() {
		$input = array(
			'type' => array( 'h-entry' ),
			'properties' => array(
				'content' => array( 'something' ),
				'checkin' => array( array(
					'properties' => array(
						'name' => array( 'Place' ),
						'url' => array( 'http://place' ),
					),
				) ),
			),
		);
		$post_content = Micropub_Render::generate_post_content( 'something', $input );

		$this->assertEquals( "<p>Checked into <a class=\"h-card p-location\" href=\"http://place\">Place</a>.</p>\n" .
			"<div class=\"e-content\">\nsomething\n</div>",
			$post_content );
	}

	function test_check_wrap_content() {
		$content = '<h1>HTML content!</h1><p>coolio.</p>';
		$input = array(
			'properties' => array(
				'content' => array( $content )
			) );
		$post_content = Micropub_Render::generate_post_content( $content, $input );
		$this->assertEquals( "<div class=\"e-content\">\n<h1>HTML content!</h1><p>coolio.</p>\n</div>", $post_content );
	}

	function test_check_no_content_passed() {
		$content = '<h1>HTML content!</h1><p>coolio.</p>';
		$input = array(
			'properties' => array(
				'content' => array( $content )
			) );
		$post_content = Micropub_Render::generate_post_content( '', $input );
		$this->assertEquals( "", $post_content );
	}


	function create_interaction( $property ) {
		$input = array(
			'properties' => array(
				$property => array( 'http://target' ),
			) );
		return $input;
	}

	function test_create_reply() {
		$input = $this->create_interaction( 'in-reply-to' ); 
		$post_content = Micropub_Render::generate_post_content( '', $input );
		$this->assertEquals( '<p>In reply to <a class="u-in-reply-to" href="http://target">http://target</a>.</p>', $post_content );

	}

	function test_create_like() {
		$input = $this->create_interaction( 'like-of' );
		$post_content = Micropub_Render::generate_post_content( '', $input );
		$this->assertEquals( '<p>Likes <a class="u-like-of" href="http://target">http://target</a>.</p>', $post_content );
	}

	function test_create_repost() {
		$input = $this->create_interaction( 'repost-of' );
		$post_content = Micropub_Render::generate_post_content( '', $input );
		$this->assertEquals( '<p>Reposted <a class="u-repost-of" href="http://target">http://target</a>.</p>', $post_content );
	}

	function test_create_event() {
		$input = array(
			'type' => array( 'h-event' ),
			'properties' => array(
				'name' => array( 'My Event' ),
				'start' => array( '2013-06-30 12:00:00' ),
				'end' => array( '2013-06-31 18:00:00' ),
				'location' => array( 'http://a/place' ),
				'description' => array( 'some stuff' ),
			) );
		$post_content = Micropub_Render::generate_post_content( '', $input );
		$this->assertEquals( <<<EOF
<div class="h-event">
<h1 class="p-name">My Event</h1>
<p>
<time class="dt-start" datetime="2013-06-30 12:00:00">2013-06-30 12:00:00</time>
to
<time class="dt-end" datetime="2013-06-31 18:00:00">2013-06-31 18:00:00</time>
at <a class="p-location" href="http://a/place">http://a/place</a>.
</p>
<p class="p-description">some stuff</p>
</div>
EOF
, $post_content );
	}



	function test_create_rsvp() {
		$input = array(
			'type' => array( 'h-entry' ),
			'properties' => array(
				'rsvp' => array( 'maybe' ),
				'in-reply-to' => array( 'http://target' ),
			) );
		$post_content = Micropub_Render::generate_post_content( '', $input );
		$this->assertEquals( <<<EOF
<p>In reply to <a class="u-in-reply-to" href="http://target">http://target</a>.</p>
<p>RSVPs <data class="p-rsvp" value="maybe">maybe</data>.</p>
EOF
, $post_content );

	}

	function test_create_bookmark() {
		$input = array(
			'type' => array( 'h-entry' ),
			'properties' => array(
				'bookmark-of' => array( 'http://target' ),
			) );
		$post_content = Micropub_Render::generate_post_content( '', $input );
		$this->assertEquals( <<<EOF
<p>Bookmarked <a class="u-bookmark-of" href="http://target">http://target</a>.</p>
EOF
, $post_content );
	}

	// While the specification allows for nested properties, currently the Post Kinds
	// plugin hooks into the Micropub plugin to enhance a URL in a like, bookmark, etc.
	// by parsing the URL and trying to find the page title among other things and adds
	// it into the input properties.
	// https://github.com/dshanske/indieweb-post-kinds/blob/master/readme.md
	function test_create_nested_bookmark() {
		$input = array(
			'type' => array( 'h-entry' ),
			'properties' => array(
				'bookmark-of' => array(
					'name' => 'Target',
					'url' => 'http://target'
				)
			) );
		$post_content = Micropub_Render::generate_post_content( '', $input );
		$this->assertEquals( <<<EOF
<p>Bookmarked <a class="u-bookmark-of" href="http://target">Target</a>.</p>
EOF
, $post_content );
	}

	function test_create_multiple_bookmark_urls() {
		$input = array(
			'type' => array( 'h-entry' ),
			'properties' => array(
				'bookmark-of' => array(
					'http://target',
					'http://tarjet'
				)
			) );
		$post_content = Micropub_Render::generate_post_content( '', $input );
		$this->assertEquals( <<<EOF
<p>Bookmarked <a class="u-bookmark-of" href="http://target">http://target</a>.</p>
EOF
, $post_content );
	}

	function test_merges_auto_generated_content() {
		$input = array(
			'type' => array( 'h-entry' ),
			'properties' => array( 
				'content' => array( 'foo bar' ),
				'in-reply-to' => array( 'http://target' )
			)
		);
		$post_content = Micropub_Render::generate_post_content( 'foo bar', $input );
		$this->assertEquals( <<<EOF
<p>In reply to <a class="u-in-reply-to" href="http://target">http://target</a>.</p>
<div class="e-content">
foo bar
</div>
EOF
, $post_content );
	}
}
