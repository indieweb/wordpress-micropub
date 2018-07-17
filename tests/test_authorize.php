<?php

class Micropub_Authorize_Test extends WP_UnitTestCase {
	

	protected static $author_id;
	protected static $secondauthor_id;
	protected static $scopes;

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
	}

}
