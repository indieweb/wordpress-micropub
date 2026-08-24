<?php
/* Media Endpoint and Upload Tests inspired by REST API Attachment Endpoint */

class Micropub_Media_Test extends Micropub_UnitTestCase {

	protected static $route = '/' . MICROPUB_NAMESPACE . '/media';

	protected $test_file;
	protected $test_file2;

	public function set_up() {
		$orig_file       = DIR_MEDIATESTDATA . '/canola.jpg';
		$this->test_file = '/tmp/canola.jpg';
			copy( $orig_file, $this->test_file );
			$orig_file2       = DIR_MEDIATESTDATA . '/codeispoetry.png';
			$this->test_file2 = '/tmp/codeispoetry.png';
			copy( $orig_file2, $this->test_file2 );
		parent::set_up();
	}

	public function test_register_routes() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( static::$route, $routes );
		$this->assertCount( 2, $routes[ static::$route ] );
	}

	public function upload_request() {
		$request = new WP_REST_Request( 'POST', static::$route );
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

	public function query_request( $GET ) {
		$request = new WP_REST_Request( 'GET', static::$route );
		$request->set_query_params( $GET );
		return $request;
	}

	public function create_form_request( $POST ) {
		$request = new WP_REST_Request( 'POST', static::$route );
		$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
		$request->set_body_params( $POST );
		return $request;
	}

	public function create_json_request( $input ) {
		$request = new WP_REST_Request( 'POST', static::$route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $input ) );
		return $request;
	}

	public function test_media_handle_upload() {
		$file_array = array(
			'file'     => file_get_contents( $this->test_file ),
			'name'     => 'canola.jpg',
			'size'     => filesize( $this->test_file ),
			'tmp_name' => $this->test_file,
		);
		$controller = new \Micropub\Rest\Media_Controller();
		$id         = $controller->media_handle_upload( $file_array );
		$this->assertIsInt( $id );
		$this->assertGreaterThanorEqual( 1, $id );
	}

	public function test_upload_file() {
		$response = $this->dispatch( self::upload_request(), self::$author_id );
		$data     = $response->get_data();
		$this->assertEquals( 201, $response->get_status(), wp_json_encode( $data ) );
		// Test that a valid URL is returned in the JSON Body
		$this->assertNotEquals( 0, attachment_url_to_postid( $data['url'] ), sprintf( '%1$s is not an attachment', $data['url'] ) );
		// Test that a valid URL is returned in the Location Header
		$headers       = $response->get_headers();
		$attachment_id = attachment_url_to_postid( $headers['Location'] );
		$this->assertNotEquals( 0, $attachment_id, sprintf( '%1$s is not an attachment', $headers['Location'] ) );
		$this->assertEquals( 'image/jpeg', get_post_mime_type( $attachment_id ) );
	}

	public function test_delete_file() {
		$response = $this->dispatch( self::upload_request(), self::$author_id );
		$data     = $response->get_data();
		$this->assertEquals( 201, $response->get_status(), wp_json_encode( $data ) );
		$attachment_id = attachment_url_to_postid( $data['url'] );
		$post          = get_post( $attachment_id );
		$this->assertNotNull( $post );
		$input    = array(
			'action' => 'delete',
			'url'    => $data['url'],
		);
		$response = $this->dispatch( self::create_form_request( $input ), self::$author_id );
		$this->assertEquals( 200, $response->get_status(), wp_json_encode( $data ) );
		$post = get_post( $attachment_id );
		$this->assertNull( $post );
	}

	public function test_delete_file_without_scope() {
		$response = $this->dispatch( self::upload_request(), self::$author_id );
		$data     = $response->get_data();
		$this->assertEquals( 201, $response->get_status(), wp_json_encode( $data ) );
		$attachment_id = attachment_url_to_postid( $data['url'] );
		$post          = get_post( $attachment_id );
		$this->assertNotNull( $post );
		$input    = array(
			'action' => 'delete',
			'url'    => $data['url'],
		);
		$response = $this->dispatch( self::create_form_request( $input ), self::$subscriber_id );
		$this->assertEquals( 403, $response->get_status(), wp_json_encode( $data ) );
		$post = get_post( $attachment_id );
		$this->assertEquals( $attachment_id, $post->ID );
	}

	public function test_unsupported_action() {
		$input    = array(
			'action' => 'create',
		);
		$response = $this->dispatch( self::create_form_request( $input ), self::$author_id );
		$this->assertEquals( 400, $response->get_status(), wp_json_encode( $response ) );
	}

	public function test_empty_upload() {
		$request  = new WP_REST_Request( 'POST', static::$route );
		$response = $this->dispatch( $request, self::$author_id );
		$data     = $response->get_data();
		$this->assertEquals( 400, $response->get_status(), wp_json_encode( $data ) );
	}

	public function test_upload_file_without_scope() {
		$response = $this->dispatch( self::upload_request(), self::$subscriber_id );
		$data     = $response->get_data();
		$this->assertEquals( 403, $response->get_status(), wp_json_encode( $data ) );
	}

	public function test_query_source_url() {
		$response = $this->dispatch( self::upload_request(), self::$author_id );
		$data     = $response->get_data();
		$url      = $data['url'];
		$this->assertEquals( 201, $response->get_status(), wp_json_encode( $data ) );
		$get      = array(
			'q'   => 'source',
			'url' => $url,
		);
		$response = $this->dispatch( self::query_request( $get ), self::$author_id );
		$data     = $response->get_data();
		$this->assertEquals( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->assertEquals( $url, $data['url'] );
		$this->assertEquals( 'image/jpeg', $data['mime_type'] );
		$this->assertArrayHasKey( 'published', $data );
		$this->assertArrayHasKey( 'height', $data );
		$this->assertArrayHasKey( 'width', $data );
	}

	/**
	 * A source URL on the site's own host, so that wp_http_validate_url() does not
	 * have to resolve a hostname. Nothing is fetched either way.
	 */
	protected function sideload( $alt = null ) {
		add_filter( 'pre_http_request', array( $this, 'serve_test_image' ), 10, 2 );

		$controller = new \Micropub\Rest\Media_Controller();
		$id         = $controller->media_sideload_url( home_url( '/remote/canola.jpg' ), 0, $alt );

		remove_filter( 'pre_http_request', array( $this, 'serve_test_image' ), 10 );

		$this->assertIsInt( $id, 'Sideload failed: ' . wp_json_encode( $id ) );

		return $id;
	}

	/**
	 * The alt of a media value used to be passed down as far as insert_attachment()
	 * and then dropped, so the description a client sent was lost.
	 */
	public function test_sideload_stores_the_alt_text() {
		$id = $this->sideload( 'a field of canola' );

		$this->assertEquals( 'a field of canola', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
	}

	public function test_sideload_without_alt_stores_no_alt_text() {
		$id = $this->sideload();

		$this->assertEquals( '', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
	}

	public function test_sideload_strips_tags_from_the_alt_text() {
		$id = $this->sideload( 'a <strong>field</strong> of canola' );

		$this->assertEquals( 'a field of canola', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * The alt has to survive the whole way from the request, not just the last call.
	 */
	public function test_alt_from_a_create_request_reaches_the_attachment() {
		add_filter( 'pre_http_request', array( $this, 'serve_test_image' ), 10, 2 );

		$request = new WP_REST_Request( 'POST', '/' . MICROPUB_NAMESPACE . '/endpoint' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'type'       => array( 'h-entry' ),
					'properties' => array(
						'content' => array( 'a photo post' ),
						'photo'   => array(
							array(
								'value' => home_url( '/remote/canola.jpg' ),
								'alt'   => 'a field of canola',
							),
						),
					),
				)
			)
		);

		$response = $this->dispatch( $request, static::$author_id );

		remove_filter( 'pre_http_request', array( $this, 'serve_test_image' ), 10 );

		$this->assertEquals( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$posts       = get_posts( array( 'post_type' => 'post' ) );
		$attachments = get_attached_media( 'image', $posts[0]->ID );
		$this->assertCount( 1, $attachments );

		$attachment_id = array_values( $attachments )[0]->ID;
		$this->assertEquals( 'a field of canola', get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * The media endpoint reads a name parameter, which insert_attachment() used to
	 * accept and ignore.
	 */
	public function test_upload_uses_the_name_as_the_attachment_title() {
		$request = self::upload_request();
		$request->set_param( 'name', 'a field of canola' );

		$response = $this->dispatch( $request, self::$author_id );
		$this->assertEquals( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$attachment_id = attachment_url_to_postid( $response->get_data()['url'] );
		$this->assertEquals( 'a field of canola', get_post( $attachment_id )->post_title );
	}

	public function test_upload_without_a_name_falls_back_to_the_filename() {
		$response = $this->dispatch( self::upload_request(), self::$author_id );
		$this->assertEquals( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );

		// wp_handle_sideload() makes the filename unique, so earlier uploads in the
		// same run leave the title as canola-1 and so on.
		$attachment_id = attachment_url_to_postid( $response->get_data()['url'] );
		$this->assertStringStartsWith( 'canola', get_post( $attachment_id )->post_title );
	}
}
