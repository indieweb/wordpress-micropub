<?php

class Micropub_Authorize_Test extends WP_UnitTestCase {
	

	protected static $author_id;
	protected static $secondauthor_id;
	protected static $scopes;


	protected static $headers = array(
			'server' => 'nginx/1.9.15',
			'date' => 'Mon, 16 May 2016 01:21:08 GMT',
			'content-type' => 'application/json',
			'authorization' => 'Bearer abcdef',
	);

	protected static $token = array(
			"me" => "http://tacos.com",
  			"client_id" => "https://app.example.com/",
			"scope" => "create update delete"
	);

	public static function scopes( $scope ) {
		return static::$scopes;
	}

	public static function wpSetUpBeforeClass( $factory ) {
		self::$author_id      = $factory->user->create(
			array(
				'role' => 'author',
				'user_url' => 'http://tacos.com'
			)
		);

	}
	public static function wpTearDownAfterClass() {
		self::delete_user( self::$author_id );
		self::delete_user( self::$secondauthor_id );
		remove_filter( 'indieauth_scopes', array( 'Micropub_Authorize', 'scopes' ) );
	}
	public function setUp() {
		// parent::setUp();
	}

	public function test_author_url() {
		$url = get_author_posts_url( self::$author_id );
		$user_id = Micropub_Authorize::url_to_user( $url );
		$this->assertEquals( self::$author_id, $user_id, $url );
	}

	public function test_user_url() {
		$user = get_userdata( self::$author_id );
		$user_id = Micropub_Authorize::url_to_user( $user->user_url );
		$this->assertEquals( self::$author_id, $user_id );
	}

	public function test_home_url() {
		wp_update_user( array( 'ID' => self::$author_id, 'user_url' => home_url() ) );
		$user_id = Micropub_Authorize::url_to_user( home_url() );
		$this->assertEquals( self::$author_id, $user_id );
		wp_update_user( array( 'ID' => self::$author_id, 'user_url' => 'http://tacos.com' ) );
	}

	public function test_determine_current_user() {
		$_SERVER['REQUEST_URI'] = MICROPUB_NAMESPACE;
		$user_id = Micropub_Authorize::determine_current_user( 0 );
		$this->assertEquals( 0, $user_id );
		$error = Micropub_Authorize::get_error();
		$this->assertNotNull( $error );
		$data = $error->get_data();
		$this->assertEquals( $error->get_status(), 401 );
		$this->assertEquals( $data['error'], 'unauthorized' );
		$this->assertEquals( $data['error_description'], 'missing access token' );
	}

	public function response( $code, $response ) {
		$response = array(
			'code' => $code,
			'response' => $response,
		);
		return $response;
	}

	public function verify_token() {
		$response = $this->response( 200, 'OK' );
		return array(
			'headers' => static::$headers, 
			'response' => $response,
			'body' => wp_json_encode( static::$token )
		);
	}

	public function test_determine_current_user_with_token() {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer testtoken';
		add_filter( 'pre_http_request', array( $this, 'verify_token' ) );
		$user_id = Micropub_Authorize::determine_current_user( 0 );
		$this->assertEquals( static::$author_id, $user_id );
		remove_filter( 'pre_http_request', array( $this, 'verify_token' ) );
	}

	public function failed_token() {
		$response = $this->response( 401, 'Unauthorized' );
		return array(
			'headers' => static::$headers, 
			'response' => $response,
			'body' => wp_json_encode( 
				array(
					'error' => 'invalid_token',
					'error_description' => 'Invalid access token'
				)
			)
		);
	}

	public function test_determine_current_user_with_bad_token() {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer testtoken';
		add_filter( 'pre_http_request', array( $this, 'failed_token' ) );
		$user_id = Micropub_Authorize::determine_current_user( 0 );
		$error = Micropub_Authorize::get_error();
		$this->assertEquals( 0, $user_id );
		$this->assertNotNull( $error );
		$data = $error->get_data();
		$this->assertEquals( $error->get_status(), 403 );
		$this->assertEquals( $data['error'], 'invalid_request' );
		$this->assertEquals( $data['error_description'], 'invalid access token' );
		remove_filter( 'pre_http_request', array( $this, 'verify_token' ) );
	}

}
