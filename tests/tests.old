<?php

/** Unit tests for the Micropub class.
 * TODO: Remove this file once new testing is complete
 */

function write_temp_file( $contents, $extension = '' ) {
	$filename = tempnam( sys_get_temp_dir(), 'micropub_test' );
	if ( $extension ) {
		$filename .= '.' . $extension;
	}
	$file = fopen( $filename, 'w' );
	fwrite( $file, $contents );
	fclose( $file );
	return $filename;
}


class Recorder extends Micropub_Endpoint {
	public static $status;
	public static $response;
	public static $input;
	public static $micropub_auth_response;
	public static $response_headers = array();
	public static $download_url_filenames;
	public static $downloaded_urls;
	public static $scopes;

	public static function init() {
		remove_filter( 'query_vars', array( 'Micropub_Endpoint', 'query_var' ) );
		remove_action( 'parse_query', array( 'Micropub_Endpoint', 'parse_query' ) );
		parent::init();
	}

	public static function respond( $status, $response ) {
		self::$status = $status;
		self::$response = $response;
		throw new WPDieException('from respond');
	}

	public static function header( $header, $value ) {
		self::$response_headers[ $header ] = $value;
	}

	protected static function read_input() {
		return json_encode( static::$input );
	}

	protected static function download_url( $url ) {
		self::$downloaded_urls[] = $url;
		return array_pop( self::$download_url_filenames );
	}
}
Recorder::init();


class MicropubTest extends WP_UnitTestCase {

	/**
	 * HTTP status code returned for the last request
	 * @var string
	 */
	protected static $status = 0;

	/**
	 * Arguments captured from the before_micropub and after_micropub filters.
	 * @vars array
	 */
	protected static $before_micropub_input;
	protected static $after_micropub_input;
	protected static $after_micropub_args;

	// POST args
	protected static $post = array(
		'h' => 'entry',
		'content' => 'my<br>content',
		'mp-slug' => 'my_slug',
		'name' => 'my name',
		'summary' => 'my summary',
		'category' => array( 'tag1', 'tag4' ),
		'published' => '2016-01-01T04:01:23-08:00',
		'location' => 'geo:42.361,-71.092;u=25000',
	);

	// JSON mf2 input
	protected static $mf2 = array(
		'type' => array( 'h-entry' ),
		'properties' => array(
			'content' => array( 'my<br>content' ),
			'mp-slug' => array( 'my_slug' ),
			'name' => array( 'my name' ),
			'summary' => array( 'my summary' ),
			'category' => array( 'tag1', 'tag4' ),
			'published' => array( '2016-01-01T04:01:23-08:00' ),
			'location' => array( 'geo:42.361,-71.092;u=25000' ),
		),
	);

	// Micropub Auth Response, based on https://tokens.indieauth.com/
	protected static $micropub_auth_response = array(
		'me'    =>  'http://tacos.com', // taken from WordPress' tests/user.php
		'client_id' =>  'https://example.com',
		'scope' =>  'create update',
		'issued_at' =>   1399155608,
		'nonce' => 501884823,
	);

	// Scope defaulting to legacy params
	protected static $scopes = array( 'post' );

	protected static $geo = array(
		'type' => array('h-geo'),
		'properties' => array(
			'latitude' => array('42.361'),
			'longitude' => array('-71.092'),
			'altitude' => array('25000'),
		),
	);

	// WordPress wp_insert_post/wp_update_post $args
	protected static $wp_args = array(
		'post_name' => 'my_slug',
		'post_title' => 'my name',
		'post_content' => 'my<br>content',
		'tags_input' => array( 'tag1', 'tag4' ),
		'post_date' => '2016-01-01 12:01:23',
		'location' => 'geo:42.361,-71.092;u=25000',
		'guid' => 'http://localhost/1/2/my_slug',
	);

	public static function setUpBeforeClass() {
		WP_UnitTestCase::setUpBeforeClass();
		add_filter( 'before_micropub', array(MicropubTest, before_micropub_recorder ) );
		add_action( 'after_micropub', array(MicropubTest, after_micropub_recorder ), 10, 2 );
	}

	public function setUp() {
		parent::setUp();
		self::$status = 0;
		$_POST = array();
		$_GET = array();
		$_FILES = array();
		Recorder::$scopes = static::$scopes;
		Recorder::$request_headers = array();
		Recorder::$input = NULL;
		Recorder::$downloaded_urls = array();
		static::$before_micropub_input =
            static::$after_micropub_input = static::$after_micropub_args = null;
		unset( $GLOBALS['post'] );

		update_option( 'permalink_structure', '/%year%/%monthnum%/%day%/%postname%', 'yes' );
		global $wp_query;
		$wp_query->query_vars['micropub'] = 'endpoint';

		$this->userid = self::factory()->user->create( array( 'role' => 'editor' ) );
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
		if ( is_array( $expected ) ) {
			$this->assertEquals( $expected, Recorder::$response, $encoded );
		} elseif ( is_string( $expected ) ) {
			$this->assertContains( $expected, Recorder::$response['error_description'], $encoded );
		} else {
			$this->assertSame( NULL, $expected );
		}

		$this->assertFalse( is_null( static::$before_micropub_input ) );
		if ( (int) ( $status / 100 ) == 2 ) {
			$this->assertFalse( is_null( static::$after_micropub_input ) );
		} else {
			$this->assertNull( static::$after_micropub_input );
			$this->assertNull( static::$after_micropub_args );
		}
	}

	function check_create() {
		$this->check( 201 );
		$posts = wp_get_recent_posts( NULL, OBJECT );
		$this->assertEquals( 1, count( $posts ) );
		$post = $posts[0];
		$this->assertEquals( get_permalink( $post ),
							 Recorder::$response_headers['Location'] );
		return $post;
	}

       function check_create_content_html() {
               $post = $this->check_create();
               $this->assertEquals( 'HTML content test', $post->post_title );
               // check that HTML in content isn't sanitized
               $this->assertEquals( "<div class=\"e-content\">\n<h1>HTML content!</h1><p>coolio.</p>\n</div>", $post->post_content );
       }


	function query_source( $post_id ) {
		$_GET = array(
			'q' => 'source',
			'url' => 'http://example.org/?p=' . $post_id,
		);
		$this->parse_query( 'GET' );
		return Recorder::$response;
	}

	protected static function insert_post() {
		return wp_insert_post( static::$wp_args );
	}

	public static function before_micropub_recorder( $input ) {
		static::$before_micropub_input = $input;
		return $input;
	}

	public static function after_micropub_recorder( $input, $args ) {
		static::$after_micropub_input = $input;
		static::$after_micropub_args = $args;
	}

	function test_bad_query() {
		$_GET['q'] = 'not_real';
		$this->check( 400, array( 'error' => 'invalid_request',
								  'error_description' => 'unknown query not_real' ) );
	}

	function test_query_syndicate_to_empty() {
		$_GET['q'] = 'syndicate-to';
		$this->check( 200, array( 'syndicate-to' => array() ) );
	}

	function test_query_syndicate_to() {
		function syndicate_to() {
			return array( 'abc', 'xyz' );
		}
		add_filter( 'micropub_syndicate-to', 'syndicate_to' );

		$_GET['q'] = 'syndicate-to';
		$expected = array( 'syndicate-to' => array( 'abc', 'xyz' ) );
		$this->check( 200, $expected );

		$this->assertEquals( $_GET, static::$before_micropub_input );
		$this->assertEquals( $_GET, static::$after_micropub_input );
		$this->assertNull( static::$after_micropub_args );
	}


	function test_custom_query() {
		function custom_query( $return, $input ) {
			if ( 'abc' === $input['q'] ) {
				return array( 'abc' => array( '123' ) );
			}
			return $return;
		}
		add_filter( 'micropub_query', 'custom_query', 10, 2 );
		$_GET['q'] = 'abc';
		$expected = array( 'abc' => array( '123' ) );
		$this->check( 200, $expected );

		$this->assertEquals( $_GET, static::$before_micropub_input );
		$this->assertEquals( $_GET, static::$after_micropub_input );
		$this->assertNull( static::$after_micropub_args );

		// Check to ensure default options are still working
		$_GET['q'] = 'config';
		$expected = array( 'syndicate-to' => array(), 'media-endpoint' => 'http://example.org/wp-json/micropub/1.0/media' );
		$this->check( 200, $expected );

		$this->assertEquals( $_GET, static::$before_micropub_input );
		$this->assertEquals( $_GET, static::$after_micropub_input );
		$this->assertNull( static::$after_micropub_args );
	}


	function test_query_source() {
		$_POST = self::$post;
		$post = $this->check_create();

		$_GET = array(
			'q' => 'source',
			'url' => 'http://example.org/?p=' . $post->ID,
		);
		$this->check( 200, self::$mf2 );
	}

	function test_query_category() {
		$_POST = self::$post;
		$post = $this->check_create();
		$_GET = array(
			'q' => 'category'
		);
		wp_set_post_tags( $post->ID, 'Tag' );
		wp_create_categories( array( 'Category' ), $post->ID );
		$this->check( 200, array( 'Tag', 'Category' ) );
	}


	function test_query_source_with_properties() {
		$_POST = self::$post;
		$post = $this->check_create();

		$_GET = array(
			'q' => 'source',
			'url' => 'http://example.org/?p=' . $post->ID,
			'properties' => array( 'content', 'category' ),
		);
		$this->check( 200, 	array(
			'properties' => array(
				'content' => array( 'my<br>content' ),
				'category' => array( 'tag1', 'tag4' ),
			),
		) );
	}

	function test_query_source_not_found() {
		$_GET = array(
			'q' => 'source',
			'url' => 'http:/localhost/doesnt/exist',
		);

		$this->check( 400, array(
			'error' => 'invalid_request',
			'error_description' => 'not found: http:/localhost/doesnt/exist',
		) );

		$this->assertEquals( $_GET, static::$before_micropub_input );
		$this->assertNull( static::$after_micropub_input );
		$this->assertNull( static::$after_micropub_args );
	}

	function test_create_basic_post() {
		Recorder::$request_headers = array( 'Content-type' => 'application/x-www-form-urlencoded' );
		$_POST = self::$post;
		Recorder::$micropub_auth_response = static::$micropub_auth_response;
		self::check_create_basic();
	}

	function test_create_post_without_create_scope() {
		Recorder::$micropub_auth_response = static::$micropub_auth_response;
		Recorder::$scopes = array( 'update' );
                $_POST = self::$post;
                $this->check( 403, array( 'error' => 'insufficient_scope',
                        'error_description' => 'scope insufficient to create posts' ) );
	}

	function test_create_post_with_create_scope() {
		Recorder::$request_headers = array( 'Content-type' => 'application/x-www-form-urlencoded' );
		$_POST = self::$post;
		Recorder::$micropub_auth_response = static::$micropub_auth_response;
		Recorder::$scopes = array( 'create' );
		self::check_create_basic();
	}

	function test_create_basic_json() {
		Recorder::$scopes = static::$scopes;
		Recorder::$request_headers = array( 'content-type' => 'application/json; charset=utf-8' );
		Recorder::$input = static::$mf2;
		Recorder::$micropub_auth_response = static::$micropub_auth_response;
		self::check_create_basic();
	}

	function check_create_basic() {
		$post = $this->check_create();

		$this->assertEquals( 'publish', $post->post_status );
		$this->assertEquals( 'post', $post->post_type );
		$this->assertFalse( has_post_format( $post ) );
		$this->assertEquals( $this->userid, $post->post_author );
		// check that HTML in content is sanitized
		$this->assertEquals( "<div class=\"e-content\">\nmy&lt;br&gt;content\n</div>", $post->post_content );
		$this->assertEquals( 'my_slug', $post->post_name );
		$this->assertEquals( 'my name', $post->post_title );
		$this->assertEquals( 'my summary', $post->post_excerpt );
		$this->assertEquals( '2016-01-01 12:01:23', $post->post_date );

		// Check that post_date_gmt is set. It is the same here as post_date, since the WordPress test library is set to GMT.
		$this->assertEquals( '2016-01-01 12:01:23', $post->post_date_gmt );

		$this->assertEquals( '42.361', get_post_meta( $post->ID, 'geo_latitude', true ) );
		$this->assertEquals( '-71.092', get_post_meta( $post->ID, 'geo_longitude', true ) );
		$this->assertEquals( '', get_post_meta( $post->ID, 'geo_address', true ) );

		$this->assertEquals( static::$mf2, static::$before_micropub_input );
		$this->assertEquals( static::$mf2, static::$after_micropub_input );
		$this->assertEquals( 'my name', static::$after_micropub_args['post_title'] );
		$this->assertGreaterThan( 0, static::$after_micropub_args['ID'] );

		$this->assertEquals( static::$mf2, $this->query_source( $post->ID ) );

		$this->assertEquals( static::$micropub_auth_response, get_post_meta ( $post->ID, 'micropub_auth_response', true ) ) ;

		return $post;
	}

	public static function syndications( $synd_urls, $user_id ) {
		return array( 
			array(
			       	'name' => 'Instagram',
				'uid' => 'instagram'),
			array(
				'name' => 'Twitter',
				'uid' => 'twitter' )
		);
	}

	function test_create_with_supported_syndicate_to() {
		add_filter( 'micropub_syndicate-to', array( $this, 'syndications' ) );

		Recorder::$request_headers = array( 'content-type' => 'application/json; charset=utf-8' );
		Recorder::$input = static::$mf2;
		Recorder::$input['properties']['mp-syndicate-to'] = array( 'twitter' );
		Recorder::$micropub_auth_response = static::$micropub_auth_response;

		self::check_create();
	}

	function test_create_with_unsupported_syndicate_to() {
		add_filter( 'micropub_syndicate-to', array( $this, 'syndications' ) );

		Recorder::$request_headers = array( 'content-type' => 'application/json; charset=utf-8' );
		Recorder::$input = static::$mf2;
		Recorder::$input['properties']['mp-syndicate-to'] = array( 'twitter', 'facebook' );
		Recorder::$micropub_auth_response = static::$micropub_auth_response;

		$this->check( 400, 'Unknown mp-syndicate-to targets: facebook' );
	}

	function syndicate_trigger( $id, $syns ) {
		add_post_meta( $id, 'testing', $syns );
	}

	function test_create_syn_hook() {
		add_filter( 'micropub_syndicate-to', array( $this, 'syndications' ) );
		add_action( 'micropub_syndication', array( $this, 'syndicate_trigger' ), 10, 2 );
		Recorder::$request_headers = array( 'content-type' => 'application/json; charset=utf-8' );
		Recorder::$input = static::$mf2;
		Recorder::$input['properties']['mp-syndicate-to'] = array( 'twitter' );
		Recorder::$micropub_auth_response = static::$micropub_auth_response;
		$post = self::check_create();
		$this->assertEquals( array( 'twitter' ), get_post_meta( $post->ID, 'testing', true ) );		
	}


	function test_create_content_html_post() {
		$_POST = array(
			'h' => 'entry',
			'content' => array( 'html' => '<h1>HTML content!</h1><p>coolio.</p>' ),
			'name' => 'HTML content test',
		);
		self::check_create_content_html();
	}

	function test_create_content_html_json() {
		Recorder::$request_headers = array( 'content-type' => 'application/json' );
		Recorder::$input = array(
			'type' => array( 'h-entry' ),
			'properties' => array(
				'content' => array( array( 'html' => '<h1>HTML content!</h1><p>coolio.</p>' ) ),
				'name' => array( 'HTML content test' ),
			) );
		self::check_create_content_html();
	}

	function test_create_doesnt_store_access_token() {
		Recorder::$request_headers = array( 'Content-type' => 'application/x-www-form-urlencoded' );
		$_POST = self::$post;
		$_POST['access_token'] = 'super secret';
		$post = self::check_create_basic();

		$mf2 = $this->query_source( $post->ID );
		$this->assertFalse( isset($mf2['properties']['access_token'] ) );
	}

	function test_create_nested_mf2_object() {
		Recorder::$request_headers = array( 'content-type' => 'application/json' );
		$input = Recorder::$input = array(
			'type' => array( 'h-entry' ),
			'properties' => array(
				'summary' => array( 'Weighed 70.64 kg' ),
				'x-weight' => array(
					'type' => array( 'h-measure' ),
					'properties' => array(
						'num' => array( '70.64' ),
						'unit' => array( 'kg' ),
					),
				),
			),
		);
		$post = self::check_create();

		$mf2 = $this->query_source( $post->ID );
		$this->assertEquals( $input, $mf2);
	}

	function test_create_location_url_ignore() {
		Recorder::$request_headers = array( 'content-type' => 'application/json; charset=utf-8' );
		Recorder::$input = static::$mf2;
		Recorder::$input['properties']['location'] = array( 'http://a/venue' );
		$post = self::check_create();

		$this->assertEquals( '', get_post_meta( $post->ID, 'geo_latitude', true ) );
		$this->assertEquals( '', get_post_meta( $post->ID, 'geo_longitude', true ) );
		$this->assertEquals( '', get_post_meta( $post->ID, 'geo_address', true ) );
	}

	function test_create_location_h_geo() {
		Recorder::$request_headers = array( 'content-type' => 'application/json; charset=utf-8' );
		Recorder::$input = static::$mf2;
		Recorder::$input['properties']['location'] = array( static::$geo );
		$post = self::check_create();

		$this->assertEquals( '42.361', get_post_meta( $post->ID, 'geo_latitude', true ) );
		$this->assertEquals( '-71.092', get_post_meta( $post->ID, 'geo_longitude', true ) );
		$this->assertEquals( '25000', get_post_meta( $post->ID, 'geo_altitude', true ) );
		$this->assertEquals( '', get_post_meta( $post->ID, 'geo_address', true ) );
	}

	function test_create_location_h_adr() {
		Recorder::$request_headers = array( 'content-type' => 'application/json; charset=utf-8' );
		Recorder::$input = static::$mf2;
		Recorder::$input['properties']['location'] = array(
			array(
				'type' => array('h-adr'),
				'properties' => array(
					'geo' => array( static::$geo ),
				),
			),
		);
		$post = self::check_create();

		$this->assertEquals( '42.361', get_post_meta( $post->ID, 'geo_latitude', true ) );
		$this->assertEquals( '-71.092', get_post_meta( $post->ID, 'geo_longitude', true ) );
		$this->assertEquals( '', get_post_meta( $post->ID, 'geo_address', true ) );
	}

	function test_create_location_geo_with_altitude() {
		Recorder::$request_headers = array( 'content-type' => 'application/json; charset=utf-8' );
		Recorder::$input = static::$mf2;
		Recorder::$input['properties']['location'] = array( 'geo:42.361,-71.092,1500;u=25000' );
		$post = self::check_create();

		$this->assertEquals( '42.361', get_post_meta( $post->ID, 'geo_latitude', true ) );
		$this->assertEquals( '-71.092', get_post_meta( $post->ID, 'geo_longitude', true ) );
		$this->assertEquals( '1500', get_post_meta( $post->ID, 'geo_altitude', true ) );
	}

	function test_create_location_plain_text() {
		Recorder::$request_headers = array( 'content-type' => 'application/json; charset=utf-8' );
		Recorder::$input = static::$mf2;
		Recorder::$input['properties']['location'] = array( 'foo bar baz' );
		$post = self::check_create();
		$this->assertEquals( 'foo bar baz', get_post_meta( $post->ID, 'geo_address', true ) );
		$this->assertEquals( '', get_post_meta( $post->ID, 'geo_latitude', true ) );
		$this->assertEquals( '', get_post_meta( $post->ID, 'geo_longitude', true ) );
	}

	function test_create_location_visibility_private() {
		Recorder::$request_headers = array( 'content-type' => 'application/json; charset=utf-8' );
		Recorder::$input = static::$mf2;
		Recorder::$input['properties']['location-visibility'] = array( 'private' );
		$post = self::check_create();
		$this->assertEquals( 0, get_post_meta( $post->ID, 'geo_public', true ) );

	}

	function test_create_location_visibility_public() {
		Recorder::$request_headers = array( 'content-type' => 'application/json; charset=utf-8' );
		Recorder::$input = static::$mf2;
		Recorder::$input['properties']['location-visibility'] = array( 'public' );
		$post = self::check_create();
		$this->assertEquals( 1, get_post_meta( $post->ID, 'geo_public', true ) );

	}

	function test_create_location_visibility_unsupported() {
		Recorder::$request_headers = array( 'content-type' => 'application/json; charset=utf-8' );
		Recorder::$input = static::$mf2;
		Recorder::$input['properties']['location-visibility'] = array( 'bleh' );
		$this->check( 400, 'unsupported location visibility bleh' )	;
	}


	function test_create_location_visibility_none() {
		Recorder::$request_headers = array( 'content-type' => 'application/json; charset=utf-8' );
		Recorder::$input = static::$mf2;
		$post = self::check_create();
		$this->assertEquals( '', get_post_meta( $post->ID, 'geo_public', true ) );

	}


	// checkin isn't a standard mf2 property yet, but OwnYourSwarm uses it.
	// https://ownyourswarm.p3k.io/docs#checkins
	function test_create_checkin() {
		Recorder::$request_headers = array( 'content-type' => 'application/json; charset=utf-8' );
		Recorder::$input = array(
			'type' => array( 'h-entry' ),
			'properties' => array(
				'checkin' => array( array(
					'type' => array( 'h-card' ),
					'properties' => array(
						'name' => array( 'A Place' ),
						'url' => array(
							'https:/foursquare.com/a/place',
							'http:/a/place',
							'https:/twitter.com/aplace',
						),
						'latitude' => array( '42.361' ),
						'longitude' => array( '-71.092' ),
						'street-address' => array( '1 Micro Pub' ),
						'locality' => array( 'Portland' ),
						'region' => array( 'Oregon' ),
						'country-name' => array( 'US' ),
						'postal-code' => array( '97214' ),
						'tel' => array( '(123) 456-7890' ),
					),
				) ),
			),
		);
		$post = self::check_create();

		$this->assertEquals( 'A Place, 1 Micro Pub, Portland, Oregon, 97214, US',
							 get_post_meta( $post->ID, 'geo_address', true ) );
		$this->assertEquals( '42.361', get_post_meta( $post->ID, 'geo_latitude', true ) );
		$this->assertEquals( '-71.092', get_post_meta( $post->ID, 'geo_longitude', true ) );
		$this->assertEquals( '<p>Checked into <a class="h-card p-location" href="http:/a/place">A Place</a>.</p>', $post->post_content );
	}

	function test_create_with_photo() {
		$this->_test_create_with_upload('photo', 'image', 'jpg');
	}

	function test_create_with_video() {
		$this->_test_create_with_upload('video', 'video', 'mp4');
	}

	function test_create_with_audio() {
		$this->_test_create_with_upload('audio', 'audio', 'mp3');
	}

	function _test_create_with_upload( $mf2_prop, $wp_type, $extension ) {
		$filename = write_temp_file( 'fake file contents' );

		$_FILES = array( $mf2_prop => array(
			'name' => 'micropub_test.' . $extension,
			'tmp_name' => $filename,
			'size' => 19,
		) );
		$_POST['action'] = 'allow_file_outside_uploads_dir';
		Recorder::$request_headers = array(
			'content-type' => 'multipart/form-data; boundary=asdf' );

		$post = $this->check_create();
		$att = $this->check_upload( $post, $wp_type );
		$this->assertEquals(
			array(
				'properties' => array(
					$mf2_prop => array( wp_get_attachment_url( $att->ID ) ),
				) ),
			$this->query_source( $post->ID ) );
	}

	function test_create_with_upload_multiple_photos() {
		$filenames = array(
			write_temp_file( 'fake file contents' ),
			write_temp_file( 'fake file contents 2' ),
		);

		$_FILES = array( 'photo' => array(
			'name' => array( '1.jpg', '2.png' ),
			'tmp_name' => $filenames,
			'size' => array( 19, 21 ),
		) );
		$_POST['action'] = 'allow_file_outside_uploads_dir';
		Recorder::$request_headers = array(
			'content-type' => 'multipart/form-data; boundary=asdf' );

		$post = $this->check_create();
		$atts = $this->check_upload( $post, 'image', 2 );

		$att_ids = array();
		foreach ( $atts as $_ => $att ) {
			$att_urls[] = wp_get_attachment_url( $att->ID );
		}
		$this->assertEquals(
			array(
				'properties' => array(
					'photo' => $att_urls,
				) ),
			$this->query_source( $post->ID ) );
	}

	function test_create_with_upload_error() {
		$_FILES = array( 'photo' => array(
			'name' => NULL,
			'tmp_name' => NULL,
			'error' => UPLOAD_ERR_PARTIAL,
		) );
		$_POST['action'] = 'allow_file_outside_uploads_dir';
		Recorder::$request_headers = array(
			'content-type' => 'multipart/form-data; boundary=asdf' );

		$post = $this->check(400, 'The uploaded file was only partially uploaded.');
	}

	function test_create_with_photo_url() {
		$this->_test_create_with_upload_url('photo', 'image', 'jpg');
	}

	function test_create_with_video_url() {
		$this->_test_create_with_upload_url('video', 'video', 'mp4');
	}

	function test_create_with_audio_url() {
		$this->_test_create_with_upload_url('audio', 'audio', 'mp3');
	}

	function _test_create_with_upload_url( $mf2_prop, $wp_type, $extension ) {
		Recorder::$download_url_filenames = array(
			write_temp_file( 'fake file contents', $extension ) );
		Recorder::$request_headers = array( 'content-type' => 'application/json' );
		$url = 'http://elsewhere/file.' . $extension;

		$mf2 = Recorder::$input = array(
			'properties' => array(
				$mf2_prop => array( $url ),
			) );
		$post = $this->check_create();
		$att = $this->check_upload( $post, $wp_type );
		$this->assertEquals( array( $url ), Recorder::$downloaded_urls );
		$this->assertEquals( $mf2, $this->query_source( $post->ID ) );
	}

	function test_create_with_photo_url_alt() {
		$this->_test_create_with_upload_url_alt('photo', 'image', 'jpg');
	}

	function test_create_with_video_url_alt() {
		$this->_test_create_with_upload_url_alt('video', 'video', 'mp4');
	}

	function test_create_with_audio_url_alt() {
		$this->_test_create_with_upload_url_alt('audio', 'audio', 'mp3');
	}

	function _test_create_with_upload_url_alt( $mf2_prop, $wp_type, $extension ) {
		Recorder::$download_url_filenames = array(
			write_temp_file( 'fake file contents', $extension ) );
		Recorder::$request_headers = array( 'content-type' => 'application/json' );
		$url = 'http://elsewhere/file.' . $extension;

		$mf2 = Recorder::$input = array(
			'properties' => array(
				$mf2_prop => array( array(
					'value' => $url,
					'alt' => 'my alt text',
				) ),
			) );

		$post = $this->check_create();
		$this->check_upload( $post, $wp_type );
		$this->assertEquals( array( $url ), Recorder::$downloaded_urls );
		$this->assertEquals( $mf2, $this->query_source( $post->ID ) );
	}

	function check_upload( $post, $wp_type, $num = 1 ) {
		$this->assertEquals( get_permalink( $post ),
							 Recorder::$response_headers['Location'] );
		$this->assertEquals( '[gallery size=full columns=1]', $post->post_content );

		$media = get_attached_media( $wp_type, $post->ID );
		$this->assertEquals( $num, count( $media ) );

		foreach ( $media as $_ => $att ) {
			$this->assertEquals( 'attachment', $att->post_type);
		}
		return $num == 1 ? reset( $media ) : $media;
	}

	function test_create_with_multiple_uploads() {
		Recorder::$request_headers = array( 'content-type' => 'application/json' );
		Recorder::$download_url_filenames = array(
			write_temp_file( 'fake file contents', 'gif' ),
			write_temp_file( 'fake file contents', 'png' ),
			write_temp_file( 'fake file contents', 'mov' ),
			write_temp_file( 'fake file contents', 'wav' ),
			write_temp_file( 'fake file contents', 'ogg' ),
			write_temp_file( 'fake file contents', 'mp3' ),
		);
		$url = 'http://elsewhere/file.' . $extension;

		$mf2 = Recorder::$input = array(
			'properties' => array(
				'photo' => array( array(
					'value' => 'http://photo/1.gif',
					'alt' => 'gif alt text',
				), array(
					'value' => 'http://photo/2.png',
					'alt' => 'png alt text',
				) ),
				'video' => array( 'http://video/3.mov' ),
				'audio' => array(
					'http://audio/4.wav',
					'http://audio/5.ogg',
					'http://audio/6.mp3',
				),
			) );

		$post = $this->check_create();
		$this->assertEquals( get_permalink( $post ),
							 Recorder::$response_headers['Location'] );
		$this->assertEquals( '[gallery size=full columns=1]', $post->post_content );
		$this->assertEquals( array(
			'http://photo/1.gif',
			'http://photo/2.png',
			'http://video/3.mov',
			'http://audio/4.wav',
			'http://audio/5.ogg',
			'http://audio/6.mp3',
		), Recorder::$downloaded_urls );
		$this->assertEquals( $mf2, $this->query_source( $post->ID ) );

		$media = get_attached_media( 'image', $post->ID );
		$this->assertEquals( 2, count( $media ) );
		// media array keys are post ids, so can't just do [0]
		$this->assertEquals( 'gif alt text', current( $media )->post_title);
		$this->assertEquals( 'png alt text', next( $media )->post_title);

		$media = get_attached_media( 'video', $post->ID );
		$this->assertEquals( '3.mov', current( $media )->post_title);

		$media = get_attached_media( 'audio', $post->ID );
		$this->assertEquals( '4.wav', current( $media )->post_title);
		$this->assertEquals( '5.ogg', next( $media )->post_title);
		$this->assertEquals( '6.mp3', next( $media )->post_title);
	}

	function test_create_user_cannot_publish_posts() {
		get_user_by( 'ID', $this->userid )->remove_role( 'editor' );
		$_POST = array( 'h' => 'entry', 'content' => 'x' );
		$this->check( 403, 'cannot publish posts' );
	}

	function test_update() {
		$_POST = self::$post;
		$post_id = $this->check_create()->ID;

		$this->assertEquals( '2016-01-01 12:01:23', get_post( $post_id )->post_date );

		Recorder::$request_headers = array( 'content-type' => 'application/json' );
		Recorder::$input = array(
			'action' => 'update',
			'url' => 'http://example.org/?p=' . $post_id,
			'replace' => array( 'content' => array( 'new<br>content' ) ),
			'add' => array(
				'category' => array( 'add tag' ),
				'syndication' => array( 'http://synd/1', 'http://synd/2' ),
			),
			'delete' => array( 'location', 'summary' ),
		);
		$this->check( 200 );

		$post = get_post( $post_id );

		// updated
		$expected_content = <<<EOF
<div class="e-content">
new&lt;br&gt;content
</div>
EOF;
		$this->assertEquals( $expected_content, $post->post_content );

		// added
		$tags = wp_get_post_tags( $post->ID );
		$this->assertEquals( 3, count( $tags ) );
		$this->assertEquals( 'add tag', $tags[0]->name );
		$this->assertEquals( 'tag1', $tags[1]->name );
		$this->assertEquals( 'tag4', $tags[2]->name );

		// deleted
		$this->assertEquals( '', $post->post_excerpt );
		$meta = get_post_meta( $post->ID );
		$this->assertNull( $meta['geo_latitude'] );
		$this->assertNull( $meta['geo_longitude'] );

		// check that published date is preserved
		// https://github.com/snarfed/wordpress-micropub/issues/16
		$this->assertEquals( '2016-01-01 12:01:23', $post->post_date );

		$this->assertEquals( Recorder::$input, static::$before_micropub_input );
		$this->assertEquals( Recorder::$input, static::$after_micropub_input );
		$this->assertEquals( $expected_content, static::$after_micropub_args['post_content'] );
		$this->assertGreaterThan( 0, static::$after_micropub_args['ID'] );

		$this->assertEquals( array(
			'type' => array( 'h-entry' ),
			'properties' => array(
				'content' => array( 'new<br>content' ),
				'mp-slug' => array( 'my_slug' ),
				'name' => array( 'my name' ),
				'category' => array( 'tag1', 'tag4', 'add tag' ),
				'syndication' => array( 'http://synd/1', 'http://synd/2' ),
				'published' => array( '2016-01-01T04:01:23-08:00' ),
			) ),
			$this->query_source( $post->ID ) );
	}

	function test_update_add_without_content() {
		$_POST = array( 'content' => 'my<br>content' );
		$post_id = $this->check_create()->ID;

		Recorder::$request_headers = array( 'content-type' => 'application/json' );
		Recorder::$input = array(
			'action' => 'update',
			'url' => 'http://example.org/?p=' . $post_id,
			'add' => array( 'category' => array( 'foo', 'bar' ) ),
		);
		$this->check( 200 );

		// added
		$post = get_post( $post_id );
		$tags = wp_get_post_tags( $post_id );
		$this->assertEquals( 2, count( $tags ) );
		$this->assertEquals( 'foo', $tags[1]->name );
		$this->assertEquals( 'bar', $tags[0]->name );

		$this->assertEquals( array(
			'properties' => array(
				'content' => array( 'my<br>content' ),
				'category' => array( 'foo', 'bar' ),
			) ),
			$this->query_source( $post->ID ) );
	}

	function test_add_property_not_category() {
		$post_id = self::insert_post();
		Recorder::$request_headers = array( 'content-type' => 'application/json' );
		Recorder::$input = array(
			'action' => 'update',
			'url' => 'http://example.org/?p=' . $post_id,
			'add' => array( 'content' => array( 'foo' ) ),
		);
		$this->check( 400, 'can only add to category and syndication' );
	}

	function test_update_post_not_found() {
		Recorder::$request_headers = array( 'content-type' => 'application/json' );
		Recorder::$input = array(
			'action' => 'update',
			'url' => 'http://example.org/?p=999',
			'replace' => array( 'content' => array( 'unused' ) ),
	    );
		$this->check( 400, 'http://example.org/?p=999 not found' );
	}

	function test_update_user_cannot_edit_posts() {
		$post_id = self::insert_post();
		get_user_by( 'ID', $this->userid )->remove_role( 'editor' );

		Recorder::$request_headers = array( 'content-type' => 'application/json' );
		Recorder::$input = array(
			'action' => 'update',
			'url' => 'http://example.org/?p=' . $post_id,
			'replace' => array( 'content' => array( 'unused' ) ),
		);
		$this->check( 403, 'cannot edit posts' );
	}


	function test_update_delete_value() {
		$_POST = self::$post;
		$post_id = $this->check_create()->ID;

		Recorder::$request_headers = array( 'content-type' => 'application/json' );
		Recorder::$input = array(
			'action' => 'update',
			'url' => 'http://example.org/?p=' . $post_id,
			'delete' => array(
			    'category' => array(
					'tag1',  // exists
					'tag9',  // doesn't exist
				),
			),
		);
		$this->check( 200 );

		$post = get_post( $post_id );

		$tags = wp_get_post_tags( $post->ID );
		$this->assertEquals( 1, count( $tags ) );
		$this->assertEquals( 'tag4', $tags[0]->name );

		$this->assertEquals( array( 'tag4' ),
			array_values( $this->query_source( $post->ID )['properties']['category'] ));
	}

	function test_update_delete_category() {
		$post_id = self::insert_post();
		$this->assertEquals( 2, count( wp_get_post_tags( $post_id ) ) );

		Recorder::$request_headers = array( 'content-type' => 'application/json' );
		Recorder::$input = array(
			'action' => 'update',
			'url' => 'http://example.org/?p=' . $post_id,
			'delete' => array( 'category' ),
		);
		$this->check( 200 );
		$this->assertEquals( 0, count( wp_get_post_tags( $post_id ) ) );
	}

	function test_update_delete_bad_property() {
		$post_id = self::insert_post();
		Recorder::$request_headers = array( 'content-type' => 'application/json' );
		Recorder::$input = array(
			'action' => 'update',
			'url' => 'http://example.org/?p=' . $post_id,
			'delete' => array( 'content' => array( 'to delete ' ) ),
		);
		$this->check( 400, 'can only delete individual values from category and syndication' );
	}

	function test_update_replace_not_array() {
		$post_id = self::insert_post();
		Recorder::$request_headers = array( 'content-type' => 'application/json' );
		Recorder::$input = array(
			'action' => 'update',
			'url' => 'http://example.org/?p=' . $post_id,
			'replace' => 'foo',
	    );
		$this->check( 400, 'replace must be an object' );
	}

	function test_update_add_not_array() {
		$post_id = self::insert_post();
		Recorder::$request_headers = array( 'content-type' => 'application/json' );
		Recorder::$input = array(
			'action' => 'update',
			'url' => 'http://example.org/?p=' . $post_id,
			'add' => 'foo',
	    );
		$this->check( 400, 'add must be an object' );
	}

	function test_update_delete_not_array() {
		$post_id = self::insert_post();
		Recorder::$request_headers = array( 'content-type' => 'application/json' );
		Recorder::$input = array(
			'action' => 'update',
			'url' => 'http://example.org/?p=' . $post_id,
			'delete' => 'foo',
	    );
		$this->check( 400, 'delete must be an array' );
	}

	function test_delete() {
		$post_id = self::insert_post();

		$_POST = array( 'action' => 'delete', 'url' => 'http://example.org/?p=' . $post_id );
		$this->check( 200 );

		$post = get_post( $post_id );
		$this->assertEquals( 'trash', $post->post_status );
		$this->assertEquals( $_POST, static::$before_micropub_input );
		$this->assertEquals( $_POST, static::$after_micropub_input );
		$this->assertEquals( $post_id, static::$after_micropub_args['ID'] );
	}

	function test_delete_post_not_found() {
		$_POST = array( 'action' => 'delete', 'url' => 'http://example.org/?p=999' );
		$this->check( 400, array(
			'error' => 'invalid_request',
			'error_description' => 'http://example.org/?p=999 not found',
		) );
	}

	function test_delete_user_cannot_delete_posts() {
		$post_id = self::insert_post();
		get_user_by( 'ID', $this->userid )->remove_role( 'editor' );
		$_POST = array(
			'action' => 'delete',
			'url' => 'http://example.org/?p=' . $post_id,
		);
		$this->check( 403, 'cannot delete posts' );
	}

	function test_undelete() {
		$post_id = self::insert_post();
		$post = get_post( $post_id );
		$url = get_the_guid( $post );
		$slug = $post->post_name;

		wp_trash_post( $post_id );
		$this->assertEquals( 'trash', get_post( $post_id )->post_status );

		$_POST = array(
			'action' => 'undelete',
			'url' => $url,
		);
		$this->check( 200 );
		$post = get_post( $post_id );
		$this->assertEquals( 'publish', $post->post_status );
		$this->assertEquals( $slug, $post->post_name );
		$this->assertEquals( $url, get_the_guid( $post_id ) );

		$this->assertEquals( $_POST, static::$before_micropub_input );
		$this->assertEquals( $_POST, static::$after_micropub_input );
		$this->assertEquals( $post_id, static::$after_micropub_args['ID'] );
	}

	function test_undelete_post_not_found() {
		$_POST = array(
			'action' => 'undelete',
			'url' => 'http://example.org/?p=999',
		);
		$this->check( 400, array(
			'error' => 'invalid_request',
			'error_description' => 'deleted post http://example.org/?p=999 not found',
		) );
	}

	function test_undelete_user_cannot_undelete_posts() {
		$post_id = self::insert_post();
		get_user_by( 'ID', $this->userid )->remove_role( 'editor' );
		$_POST = array(
			'action' => 'undelete',
			'url' => 'http://example.org/?p=' . $post_id,
		);
		$this->check( 403, 'cannot undelete posts' );
	}

	function test_unknown_action() {
		$post_id = self::insert_post();
		$_POST = array(
			'action' => 'foo',
			'url' => 'http://example.org/?p=' . $post_id,
		);
		$this->check( 400, 'unknown action' );
	}

	function test_bad_content_type() {
		Recorder::$request_headers = array( 'content-type' => 'not/supported' );
		$_POST = array( 'content' => 'foo' );

		// can't use check() because it checks the before_micropub filter, which
		// isn't called on bad content type.
		$this->parse_query();
		$this->assertEquals( 400, Recorder::$status );
		$this->assertContains( 'unsupported content type not/supported',
							   Recorder::$response['error_description'] );
	}

	// https://github.com/snarfed/wordpress-micropub/issues/57#issuecomment-302965336
	// https://dougbeal.com/2017/05/21/285/
	function test_unicode_content() {
		Recorder::$request_headers = array( 'content-type' => 'application/json' );
		$input = Recorder::$input = array(
			'type' => array( 'h-entry' ),
			'properties' => array(
				'content' => array( 'Charles ☕ Foo covers 😻 #dougbeal.com' ),
			),
		);
		$post = self::check_create();
		$mf2 = $this->query_source( $post->ID );
		$this->assertEquals( $input, $mf2);
	}

	function test_create_with_no_timezone() {
		Recorder::$request_headers = array( 'content-type' => 'application/json; charset=utf-8' );
		static::$mf2['properties']['published'] = array( '2016-01-01T12:01:23Z' );
		Recorder::$input = static::$mf2;
		Recorder::$micropub_auth_response = static::$micropub_auth_response;
		self::check_create_basic();
	}

	function test_create_draft_status() {
		Recorder::$request_headers = array( 'content-type' => 'application/json; charset=utf-8' );
  		$input = Recorder::$input = array(
			'type' => array( 'h-entry' ),
			'properties' => array(
				'post-status' => array( 'draft' ),
				'content' => array( 'This is a test' )
			)
		);
		$post = self::check_create();
		$this->assertEquals( 'draft', $post->post_status );
	}

	function test_create_publish_status() {
		Recorder::$request_headers = array( 'content-type' => 'application/json; charset=utf-8' );
  		$input = Recorder::$input = array(
			'type' => array( 'h-entry' ),
			'properties' => array(
				'post-status' => array( 'published' ),
				'content' => array( 'This is a test' )
			)
		);
		$post = self::check_create();
		$this->assertEquals( 'publish', $post->post_status );
	}


	function test_create_private_status() {
		Recorder::$request_headers = array( 'content-type' => 'application/json; charset=utf-8' );
  		$input = Recorder::$input = array(
			'type' => array( 'h-entry' ),
			'properties' => array(
				'visibility' => array( 'private' ),
				'content' => array( 'This is a test' )
			)
		);
		$post = self::check_create();
		$this->assertEquals( 'private', $post->post_status );
	}

	function test_create_custom_visibility() {
		Recorder::$request_headers = array( 'content-type' => 'application/json; charset=utf-8' );
  		$input = Recorder::$input = array(
			'type' => array( 'h-entry' ),
			'properties' => array(
				'visibility' => array( 'limited' ),
				'content' => array( 'This is a test' )
			)
		);
		$this->check( 400, array( 'error' => 'invalid_request',
			'error_description' => 'Invalid Post Status' ) );
	}

	function test_create_custom_status() {
		Recorder::$request_headers = array( 'content-type' => 'application/json; charset=utf-8' );
  		$input = Recorder::$input = array(
			'type' => array( 'h-entry' ),
			'properties' => array(
				'post-status' => array( 'fakestatus' ),
				'content' => array( 'This is a test' )
			)
		);
		$this->check( 400, array( 'error' => 'invalid_request',
			'error_description' => 'Invalid Post Status' ) );
	}

	function test_create_empty_default_status() {
		add_option( 'micropub_default_post_status', '' );
		Recorder::$request_headers = array( 'content-type' => 'application/json; charset=utf-8' );
  		$input = Recorder::$input = array(
			'type' => array( 'h-entry' ),
			'properties' => array(
				'content' => array( 'This is a test' )
			)
		);
		$post = self::check_create();
		$this->assertEquals( 'publish', $post->post_status );
	}

	function test_create_publish_default_status() {
		add_option( 'micropub_default_post_status', 'publish' );
		Recorder::$request_headers = array( 'content-type' => 'application/json; charset=utf-8' );
  		$input = Recorder::$input = array(
			'type' => array( 'h-entry' ),
			'properties' => array(
				'content' => array( 'This is a test' )
			)
		);
		$post = self::check_create();
		$this->assertEquals( 'publish', $post->post_status );
	}


}
