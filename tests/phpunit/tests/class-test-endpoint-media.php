<?php
/* Endpoint Media Tests */

class Micropub_Endpoint_Media_Test extends Micropub_UnitTestCase {

	protected static $wp_args = array(
		'post_title'   => 'a post with a photo',
		'post_content' => 'content',
		'post_status'  => 'publish',
	);

	public function insert_post() {
		return wp_insert_post( static::$wp_args );
	}

	public function set_up() {
		parent::set_up();
		add_filter( 'pre_http_request', array( $this, 'serve_test_image' ), 10, 2 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'serve_test_image' ), 10 );
		$this->remove_added_uploads();
		parent::tear_down();
	}

	/**
	 * A source URL for a photo.
	 *
	 * It sits on the site's own host so that wp_http_validate_url() does not have
	 * to resolve a hostname, which would make the test depend on DNS. Nothing is
	 * fetched either way, serve_test_image() answers the request.
	 */
	protected function source_url( $name ) {
		return home_url( '/remote/' . $name );
	}

	protected function post_url( $post_id ) {
		return home_url( '/?p=' . $post_id );
	}

	protected function uploaded_photos( $post_id ) {
		$photos = get_post_meta( $post_id, 'mf2_photo', true );
		return is_array( $photos ) ? $photos : array();
	}

	protected function assertIsLocalUpload( $url ) {
		$uploads = wp_upload_dir();
		$this->assertStringStartsWith( $uploads['baseurl'], $url, 'Not a local attachment URL: ' . $url );
	}

	/**
	 * store_mf2() writes the source URL as it arrived, so without the sideload the
	 * property keeps pointing at the origin.
	 */
	public function test_create_sideloads_a_photo_and_stores_the_local_url() {
		$source  = $this->source_url( 'canola.jpg' );
		$request = self::create_json_request(
			array(
				'type'       => array( 'h-entry' ),
				'properties' => array(
					'content' => array( 'a photo post' ),
					'photo'   => array( $source ),
				),
			)
		);

		$response = $this->dispatch( $request, static::$author_id );
		$this->assertEquals( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$posts   = get_posts( array( 'post_type' => 'post' ) );
		$post_id = $posts[0]->ID;

		$photos = $this->uploaded_photos( $post_id );
		$this->assertCount( 1, $photos );
		$this->assertNotEquals( $source, $photos[0], 'The remote source URL was left in place' );
		$this->assertIsLocalUpload( $photos[0] );

		$attachments = get_attached_media( 'image', $post_id );
		$this->assertCount( 1, $attachments );
	}

	/**
	 * alt is optional in the object form of a media value.
	 */
	public function test_create_with_a_photo_object_without_alt_does_not_raise_a_warning() {
		$request = self::create_json_request(
			array(
				'type'       => array( 'h-entry' ),
				'properties' => array(
					'content' => array( 'a photo post' ),
					'photo'   => array(
						array( 'value' => $this->source_url( 'canola.jpg' ) ),
					),
				),
			)
		);

		$response = null;
		$errors   = $this->record_php_errors(
			function () use ( $request, &$response ) {
				$response = $this->dispatch( $request, static::$author_id );
			}
		);

		$this->assertSame( array(), $errors, 'PHP diagnostics: ' . wp_json_encode( $errors ) );
		$this->assertEquals( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$posts  = get_posts( array( 'post_type' => 'post' ) );
		$photos = $this->uploaded_photos( $posts[0]->ID );
		$this->assertCount( 1, $photos );
		$this->assertIsLocalUpload( $photos[0] );
	}

	public function test_create_with_a_photo_object_with_alt_sideloads_it() {
		$request = self::create_json_request(
			array(
				'type'       => array( 'h-entry' ),
				'properties' => array(
					'content' => array( 'a photo post' ),
					'photo'   => array(
						array(
							'value' => $this->source_url( 'canola.jpg' ),
							'alt'   => 'a field of canola',
						),
					),
				),
			)
		);

		$response = $this->dispatch( $request, static::$author_id );
		$this->assertEquals( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$posts  = get_posts( array( 'post_type' => 'post' ) );
		$photos = $this->uploaded_photos( $posts[0]->ID );
		$this->assertCount( 1, $photos );
		$this->assertIsLocalUpload( $photos[0] );
	}

	/**
	 * How OwnYourSwarm attaches a check-in photo: the post is created first, the
	 * photo follows once Foursquare has finished uploading it.
	 */
	public function test_update_add_sideloads_the_photo_and_keeps_the_existing_one() {
		$post_id = self::insert_post();
		update_post_meta( $post_id, 'mf2_photo', array( 'http://localhost/already-here.jpg' ) );

		$request = self::create_json_request(
			array(
				'action' => 'update',
				'url'    => $this->post_url( $post_id ),
				'add'    => array( 'photo' => array( $this->source_url( 'added.jpg' ) ) ),
			)
		);

		$response = $this->dispatch( $request, static::$author_id );
		$this->assertEquals( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$photos = $this->uploaded_photos( $post_id );
		$this->assertCount( 2, $photos, wp_json_encode( $photos ) );
		$this->assertEquals( 'http://localhost/already-here.jpg', $photos[0] );
		$this->assertIsLocalUpload( $photos[1] );

		$this->assertCount( 1, get_attached_media( 'image', $post_id ) );
	}

	public function test_update_replace_sideloads_the_photo() {
		$post_id = self::insert_post();
		update_post_meta( $post_id, 'mf2_photo', array( 'http://localhost/replaced.jpg' ) );

		$request = self::create_json_request(
			array(
				'action'  => 'update',
				'url'     => $this->post_url( $post_id ),
				'replace' => array( 'photo' => array( $this->source_url( 'new.jpg' ) ) ),
			)
		);

		$response = $this->dispatch( $request, static::$author_id );
		$this->assertEquals( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$photos = $this->uploaded_photos( $post_id );
		$this->assertCount( 1, $photos, wp_json_encode( $photos ) );
		$this->assertIsLocalUpload( $photos[0] );

		$this->assertCount( 1, get_attached_media( 'image', $post_id ) );
	}

	public function test_update_add_still_rejects_an_unsupported_property() {
		$post_id = self::insert_post();

		$request = self::create_json_request(
			array(
				'action' => 'update',
				'url'    => $this->post_url( $post_id ),
				'add'    => array( 'content' => array( 'nope' ) ),
			)
		);

		$response = $this->dispatch( $request, static::$author_id );
		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'invalid_request', $response->get_data()['error'] );
	}
}
