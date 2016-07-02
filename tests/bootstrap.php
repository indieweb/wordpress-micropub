<?php

error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_WARNING);

define( 'MICROPUB_LOCAL_AUTH', true );
define( 'WP_DEBUG', false );

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

function _manually_load_plugin() {
	require dirname( dirname( __FILE__ ) ) . '/micropub.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';
