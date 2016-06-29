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

    public function setUp() {
        parent::setUp();
        self::$status = 0;
        $_POST = array();
        $_GET = array();
        unset($GLOBALS['post']);

        global $wp_query;
        $wp_query->query_vars['micropub'] = 'endpoint';

        add_filter('status_header', array('MicropubTest', 'record_status'));

        wp_set_current_user(self::factory()->user->create(array('role' => 'editor')));

        // Suppress "Cannot modify header information - headers already sent by"
        $this->_error_level = error_reporting();
        error_reporting($this->_error_level & ~E_WARNING);
    }

    public function tearDown() {
        error_reporting($this->_error_level);
        parent::tearDown();
    }

    public function wp_die_handler($message, $title, $args) {
        self::$status = $args['response'];
        throw new WPDieException($message ? $message : '');
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
            return trim($body ? $body : $e->getMessage());
        }

        $this->fail('WPDieException not thrown!');
    }

    function test_empty_request() {
        $resp = $this->parse_query();
        $this->assertEquals(400, self::$status);
        $this->assertContains('Empty Micropub request', $resp);
    }
}
