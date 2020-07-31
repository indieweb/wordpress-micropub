<?php

/** Unit tests for the Micropub Global Functions.
 */

class MicropubFunctionsTest extends WP_UnitTestCase {
	function test_mp_filter() {
		$input = array(
			'webmention',
			'jsonfeed',
			'micropub',
			'author',
			'foo',
			'bar',
			'indieweb',
	   		'indieweb-goals',
			'indienews'
		);
		$return = mp_filter( $input, 'indie' );
		$this->assertEquals( 
			$return,
			array(
				'indieweb',
				'indieweb-goals',
				'indienews'
			)
		);
	}

}
