<?php
/* Media Endpoint and Upload Tests inspired by REST API Attachment Endpoint */

class Micropub_Media_Test extends WP_UnitTestCase {
	

	protected static $author_id;
	protected static $subscriber_id;
	protected static $scopes;

	public static function scopes( $scope ) {
		return static::$scopes;
	}

	public static function wpSetUpBeforeClass( $factory ) {
		self::$author_id      = $factory->user->create(
			array(
				'role' => 'author',
			)
		);
		self::$subscriber_id      = $factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);

	}
	public static function wpTearDownAfterClass() {
		self::delete_user( self::$author_id );
		remove_filter( 'indieauth_scopes', array( get_called_class(), 'scopes' ), 12 );
	}
	public function setUp() {
		// parent::setUp();
		$orig_file       = DIR_TESTDATA . '/canola.jpg';
		$this->test_file = '/tmp/canola.jpg';
		copy( $orig_file, $this->test_file );
		$orig_file2       = DIR_TESTDATA . '/codeispoetry.png';
		$this->test_file2 = '/tmp/codeispoetry.png';
		copy( $orig_file2, $this->test_file2 );
	}

	public function test_register_routes() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( MICROPUB_NAMESPACE . '/media', $routes );
		$this->assertCount( 2, $routes[ MICROPUB_NAMESPACE . '/media'] );
	}

	public function upload_request() {
		$request = new WP_REST_Request( 'POST', MICROPUB_NAMESPACE . '/media' );
		$request->set_header( 'Content-Type', 'image/jpeg' );
		$request->set_file_params( 
			array(
				'file' => array(
					'file'     => file_get_contents( $this->test_file ),
					'name'     => 'canola.jpg',
					'size'     => filesize( $this->test_file ),
					'tmp_name' => $this->test_file,
				),
			)
		);
		return $request;
	}

	public function test_media_handle_upload() {
		$file_array = array(
			'file' => file_get_contents( $this->test_file ),
			'name' => 'canola.jpg',
			'size' => filesize( $this->test_file ),
			'tmp_name' => $this->test_file
		);
		$id = Micropub_Media::media_handle_upload( $file_array );
		$this->assertInternalType( "int", $id );
	}

	public function test_upload_file_with_scope() {
		static::$scopes = array( 'create' );
		add_filter( 'indieauth_scopes', array( get_called_class(), 'scopes' ), 12 );
		wp_set_current_user( self::$author_id );
		$response = rest_get_server()->dispatch( self::upload_request() );
		$data     = $response->get_data();
		$this->assertEquals( 201, $response->get_status(), wp_json_encode( $data ) );
		// Test that a valid URL is returned in the JSON Body
		$this->assertNotEquals( 0, attachment_url_to_postid( $data['url'] ), sprintf( '%1$s is not an attachment', $data['url'] ) );
		// Test that a valid URL is returned in the Location Header
		$headers = $response->get_headers();
		$attachment_id = attachment_url_to_postid( $headers['Location'] );
		$this->assertNotEquals( 0, $attachment_id, sprintf( '%1$s is not an attachment', $headers['Location'] ) );
		$this->assertEquals( 'image/jpeg', get_post_mime_type( $attachment_id ) );
	}

	public function test_empty_upload() {
		static::$scopes = array( 'create' );
		add_filter( 'indieauth_scopes', array( get_called_class(), 'scopes' ), 12 );
		wp_set_current_user( self::$author_id );
		$request = new WP_REST_Request( 'POST', MICROPUB_NAMESPACE . '/media' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();
		$this->assertEquals( 400, $response->get_status(), wp_json_encode( $data ) );
	}

	public function test_upload_file_without_scope() {
		static::$scopes = array();
		add_filter( 'indieauth_scopes', array( get_called_class(), 'scopes' ), 12 );
		wp_set_current_user( self::$author_id );
		$response = rest_get_server()->dispatch( self::upload_request() );
		$data     = $response->get_data();
		$this->assertEquals( 401, $response->get_status(), wp_json_encode( $data ) );
	}

	public function test_upload_file_with_scope_but_insufficient_permissions() {
		static::$scopes = array( 'create' );
		add_filter( 'indieauth_scopes', array( get_called_class(), 'scopes' ), 12 );
		wp_set_current_user( self::$subscriber_id );
		$response = rest_get_server()->dispatch( self::upload_request() );
		$data     = $response->get_data();
		$this->assertEquals( 403, $response->get_status(), wp_json_encode( $data ) );
	}


}
