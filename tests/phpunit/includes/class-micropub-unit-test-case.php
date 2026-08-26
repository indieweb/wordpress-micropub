<?php
/* Endpoint Tests */

class Micropub_UnitTestCase extends WP_UnitTestCase {


	protected static $route = '/' . MICROPUB_NAMESPACE . '/endpoint';

	protected static $author_id;
	protected static $subscriber_id;
	protected static $scopes;

	// Micropub Auth Response, based on https://tokens.indieauth.com/
	protected static $micropub_auth_response = array(
		'me'        => 'http://tacos.com', // taken from WordPress' tests/user.php
		'client_id' => 'https://example.com',
		'scope'     => 'create update delete',
		'issued_at' => 1399155608,
		'nonce'     => 501884823,
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
		global $wp_rest_server;
		$wp_rest_server = null;
	}

	public function set_up() {
		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		parent::set_up();
	}

	/**
	 * Serves download_url() from the local test data instead of the network.
	 *
	 * download_url() asks for a streamed response and passes the file to write to,
	 * so the bytes go in place here rather than into the returned body. Hook it on
	 * pre_http_request to sideload without touching the network.
	 */
	public function serve_test_image( $preempt, $args ) {
		if ( empty( $args['filename'] ) ) {
			return $preempt;
		}

		copy( DIR_MEDIATESTDATA . '/canola.jpg', $args['filename'] );

		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => $args['filename'],
		);
	}

	/**
	 * Runs a callback with a handler that records every PHP diagnostic it raises.
	 *
	 * @param callable $callback The code under test.
	 * @return string[] The messages raised, empty when the callback stayed quiet.
	 */
	protected function record_php_errors( $callback ) {
		$errors = array();

		set_error_handler(
			function ( $errno, $errstr ) use ( &$errors ) {
				$errors[] = $errstr;
				return true;
			}
		);

		try {
			$callback();
		} finally {
			restore_error_handler();
		}

		return $errors;
	}

	public function dispatch( $request, $user_id ) {
		add_filter( 'indieauth_scopes', array( get_called_class(), 'scopes' ), 12 );
		add_filter( 'indieauth_response', array( get_called_class(), 'auth_response' ), 12 );
		wp_set_current_user( $user_id );
		return rest_get_server()->dispatch( $request );
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

	public function insert_post() {
		return wp_insert_post( static::$wp_args );
	}

	public function query_request( $GET ) {
		$request = new WP_REST_Request( 'GET', static::$route );
		$request->set_query_params( $GET );
		return $request;
	}

	public function query_source( $post_id ) {
		$GET      = array(
			'q'   => 'source',
			'url' => home_url( '/?p=' . $post_id ),
		);
		$request  = self::query_request( $GET );
		$response = $this->dispatch( $request, static::$author_id );
		return $response->get_data();
	}
}
