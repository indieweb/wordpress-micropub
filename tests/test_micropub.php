<?php

/** Unit tests for the Micropub class.
 *
 * TODO:
 * token validation
 * categories/tags
 * post type rendering - reply, like, repost, event, rsvp
 */

class Recorder extends Micropub {
	public static $status;
	public static $response;

	public static function init() {
		remove_filter( 'query_vars', array( 'Micropub', 'query_var' ) );
		remove_action( 'parse_query', array( 'Micropub', 'parse_query' ) );
		remove_all_filters('before_micropub');
		remove_all_filters('after_micropub');
		parent::init();
	}

	public static function respond( $status, $response ) {
		self::$status = $status;
		self::$response = $response;
		throw new WPDieException('from respond');
	}
}
Recorder::init();

class MicropubTest extends WP_UnitTestCase {

	/**
	 * HTTP status code returned for the last request
	 * @var string
	 */
	protected static $status = 0;

	public function setUp() {
		parent::setUp();
		self::$status = 0;
		$_POST = array();
		$_GET = array();
		$_FILES = array();
		unset( $GLOBALS['post'] );

		global $wp_query;
		$wp_query->query_vars['micropub'] = 'endpoint';

		$this->userid = self::factory()->user->create( array( 'role' => 'editor' ));
		wp_set_current_user( $this->userid );
	}

	/**
	 * Helper that runs Micropub::parse_query. Based on
	 * WP_Ajax_UnitTestCase::_handleAjax.
	 */
	function parse_query( $method = 'POST' ) {
		global $wp_query;
		$_SERVER['REQUEST_METHOD'] = $method;
		try {
			do_action( 'parse_query', $wp_query );
		}
		catch ( WPDieException $e ) {
			return;
		}
		$this->fail( 'WPDieException not thrown!' );
	}

	/**
	 * Run parse_query and check the result.
	 *
	 * If $response is an array, it's compared to the JSON response verbatim. If
	 * it's a string, it's checked against the 'error_description' response
	 * field as a substring.
	 */
	function check( $status, $expected = NULL ) {
		$this->parse_query( $_GET ? 'GET' : 'POST' );
		$encoded = json_encode( Recorder::$response, true );

		$this->assertEquals( $status, Recorder::$status, $encoded );
		if ( is_array( $expected )) {
			$this->assertEquals( $expected, Recorder::$response, $encoded );
		} elseif ( is_string( $expected ) ) {
			$this->assertContains( $expected, Recorder::$response['error_description'], $encoded );
		} else {
			$this->assertSame( NULL, $expected );
		}
	}

	// Post properties that match insert_post below
	protected static $properties = array(
		'h' => 'entry',
		'content' => 'my<br>content',
		'slug' => 'my_slug',
		'name' => 'my name',
		'summary' => 'my summary',
		'category' => 'my tag',
		'published' => '2016-01-01T12:01:23Z',
		'location' => 'geo:42.361,-71.092;u=25000',
	);

	// Creates a WordPress post with data that matches $properties above
	protected static function insert_post() {
		return wp_insert_post( array(
			'post_name' => 'my_slug',
			'post_title' => 'my name',
			'post_content' => 'my<br>content',
			'category' => 'my tag',
			'post_date' => '2016-01-01 12:01:23',
			'location' => 'geo:42.361,-71.092;u=25000',
		));
	}

	function test_bad_query() {
		$_GET['q'] = 'not_real';
		$this->check( 400, array( 'error' => 'invalid_request',
								  'error_description' => 'unknown query not_real' ));
	}

	function test_query_syndicate_to_empty() {
		$_GET['q'] = 'syndicate-to';
		$this->check( 200, array( 'syndicate-to' => array() ));
	}

	function test_query_syndicate_to() {
		function syndicate_to() {
			return array( 'abc', 'xyz' );
		}
		add_filter( 'micropub_syndicate-to', 'syndicate_to' );

		$_GET['q'] = 'syndicate-to';
		$this->check( 200, array( 'syndicate-to' => array( 'abc', 'xyz' )));
	}

	function test_query_post() {
		$_POST = self::$properties;
		$this->check( 201 );

		$posts = wp_get_recent_posts( NULL, OBJECT );
		$this->assertEquals( 1, count( $posts ));

		$_GET = array(
			'q' => 'source',
			'url' => 'http://example.org/?p=' . $posts[0]->ID,
		);
		$this->check( 200, array('properties' => self::$properties ));
	}

	function test_query_post_not_found() {
		$_GET = array(
			'q' => 'source',
			'url' => 'http:/localhost/doesnt/exist',
		);

		$this->check( 400, array(
			'error' => 'invalid_request',
			'error_description' => 'not found: http:/localhost/doesnt/exist',
		));
	}

	function test_create() {
		$_POST = self::$properties;
		$this->check( 201 );

		$posts = wp_get_recent_posts( NULL, OBJECT );
		$this->assertEquals( 1, count( $posts ));
		$post = $posts[0];
		$this->assertEquals( 'publish', $post->post_status );
		$this->assertEquals( $this->userid, $post->post_author );
		// check that HTML in content is sanitized
		$this->assertEquals( "<div class=\"e-content\">\nmy&lt;br&gt;content\n</div>", $post->post_content );
		$this->assertEquals( 'my_slug', $post->post_name );
		$this->assertEquals( 'my name', $post->post_title );
		$this->assertEquals( 'my summary', $post->post_excerpt );
		$this->assertEquals( '2016-01-01 12:01:23', $post->post_date );
		$this->assertEquals( '42.361', get_post_meta( $post->ID, 'geo_latitude', true ), 'Latitude Does Not Match' );
		$this->assertEquals( '-71.092', get_post_meta( $post->ID, 'geo_longitude', true ), 'Longitude Does Not Match' );
		$this->assertEquals( 'my summary', get_post_meta( $post->ID, 'mf2_summary', true ));
	}

	function test_create_content_html()
	{
		$_POST = array(
			'h' => 'entry',
			'content' => array('html' => '<h1>HTML content!</h1><p>coolio.</p>'),
			'name' => 'HTML content test'
		);
		$this->check( 201 );

		$posts = wp_get_recent_posts( NULL, OBJECT );
		$this->assertEquals( 1, count( $posts ));
		$post = $posts[0];
		$this->assertEquals( 'HTML content test', $post->post_title );
		// check that HTML in content isn't sanitized
		$this->assertEquals( "<div class=\"e-content\">\n<h1>HTML content!</h1><p>coolio.</p>\n</div>", $post->post_content );
	}

	function test_create_with_photo()
	{
		$filename = tempnam( sys_get_temp_dir(), 'micropub_test' );
		$file = fopen( $filename, 'w' );
		fwrite( $file, 'fake image contents' );
		fclose( $file );

		$_FILES = array( 'photo' => array(
			'name' => 'micropub_test.jpg',
			'tmp_name' => $filename,
			'size' => 19,
		));
		$_POST['action'] = 'allow_file_outside_uploads_dir';
		$this->check( 201 );

		$posts = wp_get_recent_posts( NULL, OBJECT );
		$this->assertEquals( 1, count( $posts ));
		$post = $posts[0];
		$this->assertEquals( "\n[gallery size=full columns=1]", $post->post_content );

		$media = get_attached_media( 'image', $post->ID );
		$this->assertEquals( 1, count( $media ));
		$this->assertEquals( 'attachment', current( $media )->post_type);
	}

	function test_create_like()
	{
		// shouldn't require name or content
		$_POST = array('like-of' => 'http://target');
		$this->check( 201 );

		$posts = wp_get_recent_posts( NULL, OBJECT );
		$this->assertEquals( 1, count( $posts ));
		$post = $posts[0];
		$this->assertEquals( '', $post->post_title );
		$this->assertEquals( '<p>Likes <a class="u-like-of" href="http://target">http://target</a>.</p>', $post->post_content );
	}

	function test_create_user_cannot_publish_posts() {
		get_user_by( 'ID', $this->userid )->remove_role( 'editor' );
		$_POST = array( 'h' => 'entry', 'content' => 'x' );
		$this->check( 403, 'cannot publish posts' );
	}

	function test_edit() {
		$post_id = self::insert_post();
		$this->assertEquals( '2016-01-01 12:01:23', get_post($post_id)->post_date );

		$_POST = array( 'url' => '/?p=' . $post_id, 'content' => 'new<br>content' );
		$this->check( 200 );

		$post = get_post( $post_id );
		$this->assertEquals( "<div class=\"e-content\">\nnew&lt;br&gt;content\n</div>",
							 $post->post_content );

		// check that published date is preserved
		// https://github.com/snarfed/wordpress-micropub/issues/16
		$this->assertEquals( '2016-01-01 12:01:23', $post->post_date );
	}

	function test_edit_post_not_found() {
		$_POST = array( 'url' => '/?p=999', 'content' => 'unused' );
		$this->check( 400, '/?p=999 not found' );
	}

	function test_edit_user_cannot_edit_posts() {
		$post_id = self::insert_post();
		get_user_by( 'ID', $this->userid )->remove_role( 'editor' );
		$_POST = array( 'url' => '/?p=' . $post_id, 'content' => 'x' );
		$this->check( 403, 'cannot edit posts' );
	}

	function test_delete() {
		$post_id = self::insert_post();

		$_POST = array( 'action' => 'delete', 'url' => '/?p=' . $post_id );
		$this->check( 200 );

		$post = get_post( $post_id );
		$this->assertEquals( 'trash', $post->post_status );
	}

	function test_delete_post_not_found() {
		$_POST = array( 'action' => 'delete', 'url' => '/?p=999' );
		$this->check( 400, array( 'error' => 'invalid_request',
								  'error_description' => '/?p=999 not found' ));
	}

	function test_delete_user_cannot_delete_posts() {
		$post_id = self::insert_post();
		get_user_by( 'ID', $this->userid )->remove_role( 'editor' );
		$_POST = array( 'action' => 'delete', 'url' => '/?p=' . $post_id );
		$this->check( 403, 'cannot delete posts' );
	}

	function test_unknown_action() {
		$post_id = self::insert_post();
		$_POST = array( 'action' => 'foo', 'url' => '/?p=' . $post_id );
		$this->check( 400, 'unknown action' );
	}
}
