<?php

class MicropubTest extends WP_UnitTestCase {

    /**
     * HTTP status code returned for the last request
     * @var string
     */
    protected static $status = 0;

    /**
     * Saved error reporting level
     * @var int
     */
    protected $_error_level = 0;

    public static function record_status($header) {
        $matches = array();
        self::assertEquals(1, preg_match('/^HTTP\/1.1 ([0-9]+) .*/', $header, $matches));
        self::$status = $matches[1];
        return $header;
    }

    public static function setUpBeforeClass() {
        parent::setUpBeforeClass();
        global $wp_query;
        $wp_query->query_vars['micropub'] = 'endpoint';
        $MICROPUB_LOCAL_AUTH = 1;

        add_filter('status_header', array('MicropubTest', 'record_status'));
    }

    public function setUp() {
        parent::setUp();
        $_POST = array();
        $_GET = array();
        unset($GLOBALS['post']);
        add_filter('wp_die_handler', array($this, 'get_wp_die_handler'), 1, 1);

        // Suppress "Cannot modify header information - headers already sent by"
        $this->_error_level = error_reporting();
        error_reporting($this->_error_level & ~E_WARNING);
    }

    public function tearDown() {
        error_reporting($this->_error_level);
        parent::tearDown();
    }

    public function get_wp_die_handler() {
        return array($this, 'wp_die_handler');
    }

    public function wp_die_handler($message) {
        throw new WPDieException($message);
    }

    /**
     * Helper that runs Micropub::parse_query. Based on
     * WP_Ajax_UnitTestCase::_handleAjax.
     */
    function parse_query() {
        global $wp_query;

        // Buffer output
        ini_set('implicit_flush', false);
        ob_start();
        try {
            do_action('parse_query', $wp_query);
        }
        catch (WPDieException $e) {
            // expected
            $body = ob_get_clean();
            return $body ? $body : $e->getMessage();
        }

        $this->fail('WPDieException not thrown!');
    }

    function test_missing_access_token() {
        $_GET['q'] = 'syndicate-to';
        $resp = $this->parse_query();
        $this->assertEquals(401, self::$status);
        $this->assertEquals('missing access token', trim($resp));
    }
}

