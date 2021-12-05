<?php

class Micropub_Endpoint_Post_Status_Test extends Micropub_UnitTestCase {
	/**
	 * An instance of the Micropub_Endpoint class.
	 *
	 * @var \Micropub_Endpoint
	 */
	private $endpoint;

	/**
	 * An instance of the private post_status method from
	 * the Micropub_Endpoint class.
	 */
	private $method;

	public function setUp() {
		$this->endpoint = new Micropub_Endpoint();

		// Perform magic to access the private method for testing.
		$ref = new ReflectionClass( 'Micropub_Endpoint' );
		$this->method = $ref->getMethod( 'post_status' );
		$this->method->setAccessible( true );
	}

	/**
	 * Provide possible conditions with which to test the Micropub_Endpoint's
	 * post_status method.
	 *
	 * @return array A list of conditions.
	 */
	public function data_get_conditions() {
		return array(
			array(
				'publish',
				array( 'properties' => array() ),
				'The default post status of publish should be used if post-status and visibility are not provided.'
			),
			array(
				'publish',
				array( 'properties' => array( 'post-status' => array( 'published' ) ) )
			),
			array(
				'draft',
				array( 'properties' => array( 'post-status' => array( 'draft' ) ) )
			),
			array(
				null,
				array( 'properties' => array( 'post-status' => array( 'invalid' ) ) )
			),
			array(
				'private',
				array( 'properties' => array( 'visibility' => array( 'private' ) ) )
			),
			array(
				'publish',
				array( 'properties' => array( 'visibility' => array( 'public' ) ) ),
				'Public visibility with no specific post-status property should return the default post status.'
			),
			array(
				null,
				array( 'properties' => array( 'visibility' => array( 'invalid' ) ) ),
				'A null value should be returned when visibility is invalid.'
			),
			array(
				null,
				array( 'properties' => array( 'visibility' => array( 'invalid' ), 'post-status' => array( 'published' ) ) ),
				'A null value should be returned when visibility is invalid, even if post-status is valid.'
			),
			array(
				'publish',
				array( 'properties' => array( 'visibility' => array( 'public' ), 'post-status' => array( 'published' ) ) )
			),
			array(
				'draft',
				array( 'properties' => array( 'visibility' => array( 'public' ), 'post-status' => array( 'draft' ) ) )
			),
			array(
				'private',
				array( 'properties' => array( 'visibility' => array( 'private' ), 'post-status' => array( 'publish' ) ) )
			),
		);
	}

	/**
	 * If neither post_status or visibility are assigned, the default
	 * post status should be used.
	 *
	 * @dataProvider data_get_conditions
	 *
	 * @param string|null A post status string if valid, null if not.
	 * @param array       A list of arguments to pass to post_status().
	 * @param string      A specific error message, if available.
	 */
	public function test_post_status_default( $expected, $args, $error = '' ) {
		$result = $this->method->invokeArgs(
			$this->endpoint,
			array( $args )
		);

		$this->assertEquals( $expected, $result, $error );
	}
}
