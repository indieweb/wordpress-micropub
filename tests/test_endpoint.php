<?php
/* Endpoint Tests */

class Micropub_Endpoint_Test extends WP_UnitTestCase {


	protected static $author_id;
	protected static $subscriber_id;

	// POST args
	protected static $post = array(
		'h'         => 'entry',
		'content'   => 'my<br>content',
		'mp-slug'   => 'my_slug',
		'name'      => 'my name',
		'summary'   => 'my summary',
		'category'  => array( 'tag1', 'tag4' ),
		'published' => '2016-01-01T04:01:23-08:00',
		'location'  => 'geo:42.361,-71.092;u=25000',
	);
		// JSON mf2 input
	protected static $mf2 = array(
		'type'       => array( 'h-entry' ),
		'properties' => array(
			'content'   => array( 'my<br>content' ),
			'mp-slug'   => array( 'my_slug' ),
			'name'      => array( 'my name' ),
			'summary'   => array( 'my summary' ),
			'category'  => array( 'tag1', 'tag4' ),
			'published' => array( '2016-01-01T04:01:23-08:00' ),
			'location'  => array( 'geo:42.361,-71.092;u=25000' ),
		),
	);
		// Micropub Auth Response, based on https://tokens.indieauth.com/
	protected static $micropub_auth_response = array(
		'me'        => 'http://tacos.com', // taken from WordPress' tests/user.php
		'client_id' => 'https://example.com',
		'scope'     => 'create update',
		'issued_at' => 1399155608,
		'nonce'     => 501884823,
	);

	// Scope defaulting to legacy params
	protected static $scopes = array( 'post' );

	protected static $geo = array(
		'type'       => array( 'h-geo' ),
		'properties' => array(
			'latitude'  => array( '42.361' ),
			'longitude' => array( '-71.092' ),
			'altitude'  => array( '25000' ),
		),
	);
			// WordPress wp_insert_post/wp_update_post $args
	protected static $wp_args = array(
		'post_name'    => 'my_slug',
		'post_title'   => 'my name',
		'post_content' => 'my<br>content',
		'tags_input'   => array( 'tag1', 'tag4' ),
		'post_date'    => '2016-01-01 12:01:23',
		'location'     => 'geo:42.361,-71.092;u=25000',
		'guid'         => 'http://localhost/1/2/my_slug',
	);


	public static function scopes( $scope ) {
		return static::$scopes;
	}

	public static function wpSetUpBeforeClass( $factory ) {
		self::$author_id     = $factory->user->create(
			array(
				'role' => 'author',
			)
		);
		self::$subscriber_id = $factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);

	}
	public static function wpTearDownAfterClass() {
		self::delete_user( self::$author_id );
		remove_filter( 'indieauth_scopes', array( get_called_class(), 'scopes' ) );
	}


	public function test_register_routes() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( MICROPUB_NAMESPACE . '/endpoint', $routes );
		$this->assertCount( 2, $routes[ MICROPUB_NAMESPACE . '/endpoint' ] );
	}

	public function dispatch( $request, $user_id ) {
		add_filter( 'indieauth_scopes', array( get_called_class(), 'scopes' ) );
		wp_set_current_user( $user_id );
		return rest_get_server()->dispatch( $request );
	}

	public function create_form_request( $POST ) {
		$request = new WP_REST_Request( 'POST', MICROPUB_NAMESPACE . '/endpoint' );
		$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
		$request->set_body_params( $POST );
		return $request;
	}

	public function create_json_request( $input ) {
		$request = new WP_REST_Request( 'POST', MICROPUB_NAMESPACE . '/endpoint' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $input ) );
		return $request;
	}

	public function query_request( $GET ) {
		$request = new WP_REST_Request( 'GET', MICROPUB_NAMESPACE . '/endpoint' );
		$request->set_query_params( $GET );
		return $request;
	}

	public function query_source( $post_id ) {
		$GET      = array(
			'q'   => 'source',
			'url' => 'http://example.org/?p=' . $post_id,
		);
		$request  = self::query_request( $GET );
		$response = Micropub_Endpoint::query_handler( $request );
		return $response->get_data();
	}

	public function check( $response, $status, $expected ) {
		$encoded = $response->get_data();
		$this->assertEquals( $status, $response->get_status(), 'Status: ' . $response->get_status );

		if ( is_array( $expected ) ) {
			$this->assertEquals( $expected, $encoded, 'Array Equals: ' . wp_json_encode( $encoded ) );
		} elseif ( is_string( $expected ) ) {
			$this->assertContains( $expected, $encoded['error_description'], 'String Contains: ' . $encoded['error_description'] );
		} else {
			$this->assertSame( null, $expected, 'Same:  ' );
		}
		return $response;
	}

	public function check_create( $request ) {
		$response = $this->dispatch( $request, static::$author_id );
		$response = $this->check( $response, 201 );
		$posts    = wp_get_recent_posts( null, OBJECT );
		$this->assertEquals( 1, count( $posts ) );
		$post    = $posts[0];
		$headers = $response->get_headers();
		$this->assertEquals( get_permalink( $post ), $headers['Location'] );
		return $post;
	}

	public function check_create_basic( $request ) {
		$post = $this->check_create( $request );
		$this->assertEquals( 'publish', $post->post_status );
		$this->assertEquals( 'post', $post->post_type );
		$this->assertFalse( has_post_format( $post ) );
		$this->assertEquals( static::$author_id, $post->post_author, 'Post Author' );
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
		$this->assertEquals( static::$mf2, $this->query_source( $post->ID ) );
		return $post;
	}

	public function test_create_basic_post() {
		self::check_create_basic( self::create_form_request( static::$post ) );
	}

	public function test_create_basic_json() {
		self::check_create_basic( self::create_json_request( static::$mf2 ) );
	}

	public function test_create_post_without_create_scope() {
		static::$scopes = array( 'update' );
		$response       = $this->dispatch( self::create_form_request( static::$post ), static::$author_id );
		self::check( $response, 403 );
		// Set Back to Default
		static::$scopes = array( 'post' );
	}

	public function test_form_to_json_encode() {
		$output = Micropub_Endpoint::form_to_json( static::$post );
		$this->assertEquals( $output, static::$mf2 );
	}

	public static function syndications( $synd_urls, $user_id ) {
		return array(
			array(
				'name' => 'Instagram',
				'uid'  => 'instagram',
			),
			array(
				'name' => 'Twitter',
				'uid'  => 'twitter',
			),
		);
	}

	public function test_create_with_supported_syndicate_to() {
		add_filter( 'micropub_syndicate-to', array( $this, 'syndications' ) );
		$input                                  = static::$mf2;
		$input['properties']['mp-syndicate-to'] = array( 'twitter' );
		$response                               = $this->dispatch( self::create_json_request( $input ), static::$author_id );
		self::check( $response, 201 );
	}

	public function test_create_with_unsupported_syndicate_to() {
		add_filter( 'micropub_syndicate-to', array( $this, 'syndications' ) );
		$input                                  = static::$mf2;
		$input['properties']['mp-syndicate-to'] = array( 'twitter', facebook );
		$response                               = $this->dispatch( self::create_json_request( $input ), static::$author_id );
		self::check( $response, 400 );
	}

}
