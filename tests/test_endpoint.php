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

	// Scope defaulting to legacy
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

	public static function auth_response( $response ) {
		return static::$micropub_auth_response;
	}

	public static function empty_auth_response( $response ) {
		return array();
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

	public function setUp() {
		parent::setUp();
		static::$scopes = array( 'post' );
	}


	public function test_register_routes() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( MICROPUB_NAMESPACE . '/endpoint', $routes );
		$this->assertCount( 2, $routes[ MICROPUB_NAMESPACE . '/endpoint' ] );
	}

	public function test_no_auth_response() {
		wp_set_current_user( static::$author_id );
		$response = static::$micropub_auth_response;
		static::$micropub_auth_response = array();
		add_filter( 'indieauth_response', array( get_called_class(), 'empty_auth_response' ), 99 );
		$auth = Micropub_Endpoint::load_auth();
		$this->assertEquals( 'unauthorized', $auth->get_error_code() );
		remove_filter( 'indieauth_response', array( get_called_class(), 'empty_auth_response' ), 99 );
	}

	public function test_auth_response() {
		wp_set_current_user( static::$author_id );
		$auth = Micropub_Endpoint::load_auth();
		$this->assertTrue( $auth );
	}


	public function dispatch( $request, $user_id ) {
		add_filter( 'indieauth_scopes', array( get_called_class(), 'scopes' ), 12 );
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

	public function insert_post() {
		return wp_insert_post( static::$wp_args );
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

	public function check( $response, $status, $expected = null ) {
		$this->assertEquals( $status, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
		$encoded = $response->get_data();
		if ( is_array( $expected ) ) {
			$this->assertEquals( $expected, $encoded, 'Array Equals: ' . wp_json_encode( $encoded ) );
		} elseif ( is_string( $expected ) ) {
			$this->assertContains( $expected, $encoded['error_description'], 'String Contains: ' . $encoded['error_description'] );
		} else {
			$this->assertSame( null, $expected, 'Same:  ' );
		}
		return $response;
	}

	public function check_create( $request, $user_id = null ) {
		if ( ! $user_id ) {
			$user_id = static::$author_id;
		}
		$response = $this->dispatch( $request, $user_id );
		$response = $this->check( $response, 201, null );
		$posts    = wp_get_recent_posts( null, OBJECT );
		$this->assertEquals( 1, count( $posts ) );
		$post    = $posts[0];
		$headers = $response->get_headers();
		$this->assertEquals( get_permalink( $post ), $headers['Location'] );
		return $post;
	}

	public function check_create_basic( $request, $input = null ) {
		if ( ! $input ) {
			$input = static::$mf2;
		}
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
		$this->assertEquals( $input, $this->query_source( $post->ID ) );
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
		self::check( $response, 401, 'scope insufficient to create posts' );
	}

	public function test_create_post_subscriber_id() {
		static::$scopes = array( 'create' );
		$response       = $this->dispatch( self::create_form_request( static::$post ), static::$subscriber_id );
		self::check( $response, 403, sprintf( 'user id %1$s cannot create posts', static::$subscriber_id ) );
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
		add_filter( 'micropub_syndicate-to', array( $this, 'syndications' ), 10, 2 );
		$input                                  = static::$mf2;
		$input['properties']['mp-syndicate-to'] = array( 'twitter' );
		$response                               = $this->dispatch( self::create_json_request( $input ), static::$author_id );
		self::check( $response, 201 );
	}

	public function test_create_with_unsupported_syndicate_to() {
		add_filter( 'micropub_syndicate-to', array( $this, 'syndications' ), 10, 2 );
		$input                                  = static::$mf2;
		$input['properties']['mp-syndicate-to'] = array( 'twitter', facebook );
		$response                               = $this->dispatch( self::create_json_request( $input ), static::$author_id );
		self::check( $response, 400, 'Unknown mp-syndicate-to targets: facebook' );
	}

	function syndicate_trigger( $id, $syns ) {
		add_post_meta( $id, 'testing', $syns );
	}

	public function test_create_syn_hook() {
		add_filter( 'micropub_syndicate-to', array( $this, 'syndications' ), 10, 2 );
		add_action( 'micropub_syndication', array( $this, 'syndicate_trigger' ), 10, 2 );
		$input                                  = static::$mf2;
		$input['properties']['mp-syndicate-to'] = array( 'twitter' );
		$post                                   = self::check_create( self::create_json_request( $input ) );
		$this->assertEquals( array( 'twitter' ), get_post_meta( $post->ID, 'testing', true ) );
	}

	public function test_create_nested_mf2_object() {
		$input = array(
			'type'       => array( 'h-entry' ),
			'properties' => array(
				'summary'  => array( 'Weighed 70.64 kg' ),
				'x-weight' => array(
					'type'       => array( 'h-measure' ),
					'properties' => array(
						'num'  => array( '70.64' ),
						'unit' => array( 'kg' ),
					),
				),
			),
		);
		$post  = self::check_create( self::create_json_request( $input ) );
		$mf2   = $this->query_source( $post->ID );
		$this->assertEquals( $input, $mf2 );
	}

	public function test_create_location_url_ignore() {
		$input                           = static::$mf2;
		$input['properties']['location'] = array( 'http://a/venue' );
		$post                            = self::check_create( self::create_json_request( $input ) );
		$this->assertEquals( '', get_post_meta( $post->ID, 'geo_latitude', true ) );
		$this->assertEquals( '', get_post_meta( $post->ID, 'geo_longitude', true ) );
		$this->assertEquals( '', get_post_meta( $post->ID, 'geo_address', true ) );
	}
	public function test_create_location_h_geo() {
		$input                           = static::$mf2;
		$input['properties']['location'] = array( static::$geo );
		$post                            = self::check_create( self::create_json_request( $input ) );
		$this->assertEquals( '42.361', get_post_meta( $post->ID, 'geo_latitude', true ) );
		$this->assertEquals( '-71.092', get_post_meta( $post->ID, 'geo_longitude', true ) );
		$this->assertEquals( '25000', get_post_meta( $post->ID, 'geo_altitude', true ) );
		$this->assertEquals( '', get_post_meta( $post->ID, 'geo_address', true ) );
	}

	public function test_create_location_h_adr() {
		$input                           = static::$mf2;
		$input['properties']['location'] = array(
			array(
				'type'       => array( 'h-adr' ),
				'properties' => array(
					'geo' => array( static::$geo ),
				),
			),
		);
		$post                            = self::check_create( self::create_json_request( $input ) );
		$this->assertEquals( '42.361', get_post_meta( $post->ID, 'geo_latitude', true ) );
		$this->assertEquals( '-71.092', get_post_meta( $post->ID, 'geo_longitude', true ) );
		$this->assertEquals( '', get_post_meta( $post->ID, 'geo_address', true ) );
	}
	public function test_create_location_geo_with_altitude() {
		$input                           = static::$mf2;
		$input['properties']['location'] = array( 'geo:42.361,-71.092,1500;u=25000' );
		$post                            = self::check_create( self::create_json_request( $input ) );
		$this->assertEquals( '42.361', get_post_meta( $post->ID, 'geo_latitude', true ) );
		$this->assertEquals( '-71.092', get_post_meta( $post->ID, 'geo_longitude', true ) );
		$this->assertEquals( '1500', get_post_meta( $post->ID, 'geo_altitude', true ) );
	}
	public function test_create_location_plain_text() {
		$input                           = static::$mf2;
		$input['properties']['location'] = array( 'foo bar baz' );
		$post                            = self::check_create( self::create_json_request( $input ) );
		$this->assertEquals( 'foo bar baz', get_post_meta( $post->ID, 'geo_address', true ) );
		$this->assertEquals( '', get_post_meta( $post->ID, 'geo_latitude', true ) );
		$this->assertEquals( '', get_post_meta( $post->ID, 'geo_longitude', true ) );
	}

	public function test_create_location_visibility_private() {
		$input                                      = static::$mf2;
		$input['properties']['location-visibility'] = array( 'private' );
		$post                                       = self::check_create( self::create_json_request( $input ) );
		$this->assertEquals( 0, get_post_meta( $post->ID, 'geo_public', true ) );
	}

	public function test_create_location_visibility_unsupported() {
		$input                                      = static::$mf2;
		$input['properties']['location-visibility'] = array( 'bleh' );
				$response                           = $this->dispatch( self::create_json_request( $input ), static::$author_id );

		$this->check( $response, 400, 'unsupported location visibility' );
	}
	public function test_create_location_visibility_none() {
		$input = static::$mf2;
		$post  = self::check_create( self::create_json_request( $input ) );
		$this->assertEquals( '', get_post_meta( $post->ID, 'geo_public', true ) );
	}

	public function test_update_replaceadddelete() {
		$POST    = self::$post;
		$post_id = $this->check_create( self::create_form_request( $POST ) )->ID;
		$this->assertEquals( '2016-01-01 12:01:23', get_post( $post_id )->post_date );
		$input    = array(
			'action'  => 'update',
			'url'     => 'http://example.org/?p=' . $post_id,
			'replace' => array( 'content' => array( 'new<br>content' ) ),
			'add'     => array(
				'category'    => array( 'add tag' ),
				'syndication' => array( 'http://synd/1', 'http://synd/2' ),
			),
			'delete'  => array( 'location', 'summary' ),
		);
		$response = $this->dispatch( self::create_json_request( $input ), static::$author_id );
		$this->check( $response, 200 );
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
		$this->assertEquals(
			array(
				'type'       => array( 'h-entry' ),
				'properties' => array(
					'content'     => array( 'new<br>content' ),
					'mp-slug'     => array( 'my_slug' ),
					'name'        => array( 'my name' ),
					'category'    => array( 'tag1', 'tag4', 'add tag' ),
					'syndication' => array( 'http://synd/1', 'http://synd/2' ),
					'published'   => array( '2016-01-01T04:01:23-08:00' ),
				),
			),
			$this->query_source( $post->ID )
		);
	}

	public function test_update_add_without_content() {
		$POST     = array( 'content' => 'my<br>content' );
		$post_id  = $this->check_create( self::create_form_request( $POST ) )->ID;
		$input    = array(
			'action' => 'update',
			'url'    => 'http://example.org/?p=' . $post_id,
			'add'    => array( 'category' => array( 'foo', 'bar' ) ),
		);
		$response = $this->dispatch( self::create_json_request( $input ), static::$author_id );
		$this->check( $response, 200 );
		// added
		$post = get_post( $post_id );
		$tags = wp_get_post_tags( $post_id );
		$this->assertEquals( 2, count( $tags ) );
		$this->assertEquals( 'foo', $tags[1]->name );
		$this->assertEquals( 'bar', $tags[0]->name );
		$this->assertEquals(
			array(
				'properties' => array(
					'content'  => array( 'my<br>content' ),
					'category' => array( 'foo', 'bar' ),
				),
			),
			$this->query_source( $post->ID )
		);
	}


	public function test_update_delete_prop() {
		$POST     = array( 'content' => 'my<br>content' );
		$post_id  = $this->check_create( self::create_form_request( $POST ) )->ID;
		$input    = array(
			'action' => 'update',
			'url'    => 'http://example.org/?p=' . $post_id,
			'delete'    => array( 'location' ),
		);
		$response = $this->dispatch( self::create_json_request( $input ), static::$author_id );
		$this->check( $response, 200 );
		// added
		$post = get_post( $post_id );
		$meta = get_post_meta( $post->ID );
		$this->assertNull( $meta['geo_latitude'] );
		$this->assertNull( $meta['geo_longitude'] );
	}


	public function test_add_property_not_category() {
		$post_id  = self::insert_post();
		$input    = array(
			'action' => 'update',
			'url'    => 'http://example.org/?p=' . $post_id,
			'add'    => array( 'content' => array( 'foo' ) ),
		);
		$response = $this->dispatch( self::create_json_request( $input ), static::$author_id );
		$this->check( $response, 400, 'can only add to category and syndication' );
	}

	public function test_update_post_not_found() {
		$input    = array(
			'action'  => 'update',
			'url'     => 'http://example.org/?p=999',
			'replace' => array( 'content' => array( 'unused' ) ),
		);
		$response = $this->dispatch( self::create_json_request( $input ), static::$author_id );
		$this->check( $response, 400, 'http://example.org/?p=999 not found' );
	}

	public function test_update_post_no_scope() {
		static::$scopes = array( 'create' );
		$input    = array(
			'action'  => 'update',
			'url'     => 'http://example.org/?p=999',
			'replace' => array( 'content' => array( 'unused' ) ),
		);
		$response = $this->dispatch( self::create_json_request( $input ), static::$author_id );
		$this->check( $response, 401, 'scope insufficient to update posts' );
	}

	public function test_update_post_no_permission() {
		$input    = array(
			'action'  => 'update',
			'url'     => 'http://example.org/?p=999',
			'replace' => array( 'content' => array( 'unused' ) ),
		);
		$response = $this->dispatch( self::create_json_request( $input ), static::$subscriber_id );
		$this->check( $response, 403, sprintf( 'user id %1$s cannot update posts', static::$subscriber_id ) );
	}



	function test_update_delete_value() {
		$POST     = self::$post;
		$post_id  = $this->check_create( self::create_form_request( $POST ) )->ID;
		$input    = array(
			'action' => 'update',
			'url'    => 'http://example.org/?p=' . $post_id,
			'delete' => array(
				'category' => array(
					'tag1',  // exists
					'tag9',  // doesn't exist
				),
			),
		);
		$response = $this->dispatch( self::create_json_request( $input ), static::$author_id );
		$this->check( $response, 200 );
		$post = get_post( $post_id );
		$tags = wp_get_post_tags( $post->ID );
		$this->assertEquals( 1, count( $tags ) );
		$this->assertEquals( 'tag4', $tags[0]->name );
		$this->assertEquals(
			array( 'tag4' ),
			array_values( $this->query_source( $post->ID )['properties']['category'] )
		);
	}
	function test_update_delete_category() {
		$post_id = self::insert_post();
		$this->assertEquals( 2, count( wp_get_post_tags( $post_id ) ) );
		$input    = array(
			'action' => 'update',
			'url'    => 'http://example.org/?p=' . $post_id,
			'delete' => array( 'category' ),
		);
		$response = $this->dispatch( self::create_json_request( $input ), static::$author_id );
		$this->check( $response, 200 );
		$this->assertEquals( 0, count( wp_get_post_tags( $post_id ) ) );
	}
	function test_update_delete_bad_property() {
		$post_id  = self::insert_post();
		$input    = array(
			'action' => 'update',
			'url'    => 'http://example.org/?p=' . $post_id,
			'delete' => array( 'content' => array( 'to delete ' ) ),
		);
		$response = $this->dispatch( self::create_json_request( $input ), static::$author_id );
		$this->check( $response, 400, 'can only delete individual values from category and syndication' );
	}

	public function test_update_replace_not_array() {
		$post_id  = self::insert_post();
		$input    = array(
			'action'  => 'update',
			'url'     => 'http://example.org/?p=' . $post_id,
			'replace' => 'foo',
		);
		$response = $this->dispatch( self::create_json_request( $input ), static::$author_id );
		$this->check( $response, 400, 'replace must be an object' );
	}
	public function test_update_add_not_array() {
		$post_id  = self::insert_post();
		$input    = array(
			'action' => 'update',
			'url'    => 'http://example.org/?p=' . $post_id,
			'add'    => 'foo',
		);
		$response = $this->dispatch( self::create_json_request( $input ), static::$author_id );
		$this->check( $response, 400, 'add must be an object' );
	}
	public function test_update_delete_not_array() {
		$post_id  = self::insert_post();
		$input    = array(
			'action' => 'update',
			'url'    => 'http://example.org/?p=' . $post_id,
			'delete' => 'foo',
		);
		$response = $this->dispatch( self::create_json_request( $input ), static::$author_id );
		$this->check( $response, 400, 'delete must be an array' );
	}
	public function test_delete() {
		$post_id  = self::insert_post();
		$POST     = array(
			'action' => 'delete',
			'url'    => 'http://example.org/?p=' . $post_id,
		);
		$response = $this->dispatch( self::create_form_request( $POST ), static::$author_id );
		$this->check( $response, 200 );
		$post = get_post( $post_id );
		$this->assertEquals( 'trash', $post->post_status );
	}

	public function test_delete_no_permission() {
		$post_id = self::insert_post();
		$POST = array(
			'action' => 'delete',
			'url'    => 'http://example.org/?p=' . $post_id,
		);
		$response = $this->dispatch( self::create_form_request( $POST ), static::$subscriber_id );
		$this->check(
			$response, 403, array(
				'error' => 'forbidden',
				'error_description' => sprintf( 'user id %1$s cannot delete posts', static::$subscriber_id )
			)
		);
	}

	public function test_delete_no_scope() {
		static::$scopes = array( 'create' );
		$post_id = self::insert_post();
		$POST = array(
			'action' => 'delete',
			'url'    => 'http://example.org/?p=' . $post_id,
		);
		$response = $this->dispatch( self::create_form_request( $POST ), static::$author_id );
		$this->check(
			$response, 401, array(
				'error' => 'insufficient_scope',
				'error_description' => sprintf( 'scope insufficient to delete posts', static::$subscriber_id )
			)
		);
	}


	public function test_delete_post_not_found() {
		$POST     = array(
			'action' => 'delete',
			'url'    => 'http://example.org/?p=999',
		);
		$response = $this->dispatch( self::create_form_request( $POST ), static::$author_id );
		$this->check(
			$response, 400, array(
				'error'             => 'invalid_request',
				'error_description' => 'http://example.org/?p=999 not found',
			)
		);
	}

	public function test_undelete() {
		$post_id = self::insert_post();
		$post    = get_post( $post_id );
		$url     = get_the_guid( $post );
		$slug    = $post->post_name;
		wp_trash_post( $post_id );
		$this->assertEquals( 'trash', get_post( $post_id )->post_status );
		$POST     = array(
			'action' => 'undelete',
			'url'    => $url,
		);
		$response = $this->dispatch( self::create_form_request( $POST ), static::$author_id );
		$this->check( $response, 200 );
		$post = get_post( $post_id );
		$this->assertEquals( 'publish', $post->post_status );
		$this->assertEquals( $slug, $post->post_name );
		$this->assertEquals( $url, get_the_guid( $post_id ) );
	}
	public function test_undelete_post_not_found() {
		$POST     = array(
			'action' => 'undelete',
			'url'    => 'http://example.org/?p=999',
		);
		$response = $this->dispatch( self::create_form_request( $POST ), static::$author_id );
		$this->check(
			$response, 400, array(
				'error'             => 'invalid_request',
				'error_description' => 'deleted post http://example.org/?p=999 not found',
			)
		);
	}

	public function test_unknown_action() {
		$post_id  = self::insert_post();
		$POST     = array(
			'action' => 'foo',
			'url'    => 'http://example.org/?p=' . $post_id,
		);
		$response = $this->dispatch( self::create_form_request( $POST ), static::$author_id );
		$this->check( $response, 400, 'Unknown Action' );
	}

	// https://github.com/snarfed/wordpress-micropub/issues/57#issuecomment-302965336
	// https://dougbeal.com/2017/05/21/285/
	public function test_unicode_content() {
		$input = array(
			'type'       => array( 'h-entry' ),
			'properties' => array(
				'content' => array( 'Charles ☕ Foo covers 😻 #dougbeal.com' ),
			),
		);
		$post  = self::check_create( self::create_json_request( $input ) );
		$mf2   = $this->query_source( $post->ID );
		$this->assertEquals( $input, $mf2 );
	}
	public function test_create_with_no_timezone() {
		$input                            = static::$mf2;
		$input['properties']['published'] = array( '2016-01-01T12:01:23Z' );
		self::check_create_basic( self::create_json_request( $input ), $input );
	}

	public function test_create_draft_status() {
		  $input = array(
			  'type'       => array( 'h-entry' ),
			  'properties' => array(
				  'post-status' => array( 'draft' ),
				  'content'     => array( 'This is a test' ),
			  ),
		  );
		$post    = self::check_create( self::create_json_request( $input ) );
		$this->assertEquals( 'draft', $post->post_status );
	}

	public function test_create_publish_status() {
		  $input = array(
			  'type'       => array( 'h-entry' ),
			  'properties' => array(
				  'post-status' => array( 'published' ),
				  'content'     => array( 'This is a test' ),
			  ),
		  );
		$post    = self::check_create( self::create_json_request( $input ) );
		$this->assertEquals( 'publish', $post->post_status );
	}
	function test_create_private_status() {
		  $input = array(
			  'type'       => array( 'h-entry' ),
			  'properties' => array(
				  'visibility' => array( 'private' ),
				  'content'    => array( 'This is a test' ),
			  ),
		  );
		$post    = self::check_create( self::create_json_request( $input ) );
		$this->assertEquals( 'private', $post->post_status );
	}
	function test_create_custom_visibility() {
		  $input  = array(
			  'type'       => array( 'h-entry' ),
			  'properties' => array(
				  'visibility' => array( 'limited' ),
				  'content'    => array( 'This is a test' ),
			  ),
		  );
		$response = $this->dispatch( self::create_json_request( $input ), static::$author_id );
		$this->check(
			$response, 400, array(
				'error'             => 'invalid_request',
				'error_description' => 'Invalid Post Status',
			)
		);
	}
	function test_create_custom_status() {
		  $input  = array(
			  'type'       => array( 'h-entry' ),
			  'properties' => array(
				  'post-status' => array( 'fakestatus' ),
				  'content'     => array( 'This is a test' ),
			  ),
		  );
		$response = $this->dispatch( self::create_json_request( $input ), static::$author_id );
		$this->check(
			$response, 400, array(
				'error'             => 'invalid_request',
				'error_description' => 'Invalid Post Status',
			)
		);
	}
	function test_create_empty_default_status() {
		add_option( 'micropub_default_post_status', '' );
		  $input = array(
			  'type'       => array( 'h-entry' ),
			  'properties' => array(
				  'content' => array( 'This is a test' ),
			  ),
		  );
		$post    = self::check_create( self::create_json_request( $input ) );
		$this->assertEquals( 'publish', $post->post_status );
	}
	function test_create_publish_default_status() {
		add_option( 'micropub_default_post_status', 'publish' );
		  $input = array(
			  'type'       => array( 'h-entry' ),
			  'properties' => array(
				  'content' => array( 'This is a test' ),
			  ),
		  );
		$post    = self::check_create( self::create_json_request( $input ) );
		$this->assertEquals( 'publish', $post->post_status );
	}


}
