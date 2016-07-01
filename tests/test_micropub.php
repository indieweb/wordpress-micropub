<?php

/** Unit tests for the Micropub class.
 *
 * TODO:
 * token validation
 * categories/tags
 * post type rendering - reply, like, repost, event, rsvp
 * storing location in geo_* postmeta
 * storing mf2 in postmeta
 * photo upload
 */
class MicropubTest extends WP_UnitTestCase {

    /**
     * HTTP status code returned for the last request
     * @var string
     */
    protected static $status = 0;

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

        $this->userid = self::factory()->user->create(array('role' => 'editor'));
        wp_set_current_user($this->userid);
    }

    public function wp_die_handler($message, $title, $args) {
        if (isset($args['response'])) {
            self::$status = $args['response'];
        }
        throw new WPDieException($message ?: '');
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
            return trim($body ?: $e->getMessage());
        }

        $this->fail('WPDieException not thrown!');
    }

    function test_empty_request() {
        $resp = $this->parse_query();
        $this->assertEquals(400, self::$status);
        $this->assertContains('Empty Micropub request', $resp);
    }

    function test_q_syndicate_to_empty() {
        $_GET['q'] = 'syndicate-to';
        $resp = $this->parse_query();
        $this->assertEquals(200, self::$status);
        $this->assertEquals('', $resp);
    }

    function test_q_syndicate_to() {
        function syndicate_to() {
            return array('abc', 'xyz');
        }
        add_filter('micropub_syndicate-to', 'syndicate_to');

        $_GET['q'] = 'syndicate-to';
        $resp = $this->parse_query();
        $this->assertEquals(200, self::$status);
        $this->assertEquals('syndicate-to[]=abc&syndicate-to[]=xyz', $resp);
    }

    function test_create() {
        $_POST = array(
            'h' => 'entry',
            'content' => 'my<br>content',
            'slug' => 'my_slug',
            'name' => 'my name',
            'summary' => 'my summary',
            'category' => 'my tag',
            'published' => '2016-01-01T12:01:23Z',
        );
        $resp = $this->parse_query();
        $this->assertEquals(201, self::$status);

        $posts = wp_get_recent_posts(NULL, OBJECT);
        $post = $posts[0];
        $this->assertEquals('publish', $post->post_status);
        $this->assertEquals($this->userid, $post->post_author);
        // check that HTML in content isn't sanitized
        $this->assertEquals("<div class=\"e-content\">\nmy<br>content\n</div>",
                            $post->post_content);
        $this->assertEquals('my_slug', $post->post_name);
        $this->assertEquals('my name', $post->post_title);
        $this->assertEquals('my summary', $post->post_excerpt);
        $this->assertEquals('2016-01-01 12:01:23', $post->post_date);
    }

    function test_create_user_cannot_publish_posts() {
        get_user_by('ID', $this->userid)->remove_role('editor');
        $_POST = array('h' => 'entry', 'content' => 'x');
        $resp = $this->parse_query();
        $this->assertEquals(403, self::$status);
        $this->assertContains('cannot publish posts', $resp);
    }

    function test_edit() {
        $post_id = wp_insert_post(array('post_content' => 'xyz'));

        $_POST = array('url' => '/?p=' . $post_id, 'content' => 'new<br>content');
        $resp = $this->parse_query();
        $this->assertEquals(200, self::$status);

        $post = get_post($post_id);
        $this->assertEquals("<div class=\"e-content\">\nnew<br>content\n</div>",
                            $post->post_content);
    }

    function test_edit_post_not_found() {
        $_POST = array('url' => '/?p=999', 'content' => 'unused');
        $resp = $this->parse_query();
        $this->assertEquals(400, self::$status);
        $this->assertContains('not found', $resp);
    }

    function test_edit_user_cannot_edit_posts() {
        $post_id = wp_insert_post(array('post_content' => 'xyz'));
        get_user_by('ID', $this->userid)->remove_role('editor');
        $_POST = array('url' => '/?p=' . $post_id, 'content' => 'x');
        $resp = $this->parse_query();
        $this->assertEquals(403, self::$status);
        $this->assertContains('cannot edit posts', $resp);
    }

    function test_delete() {
        $post_id = wp_insert_post(array('post_content' => 'xyz'));

        $_POST = array('action' => 'delete', 'url' => '/?p=' . $post_id);
        $resp = $this->parse_query();
        $this->assertEquals(200, self::$status);

        $post = get_post($post_id);
        $this->assertEquals('trash', $post->post_status);
    }

    function test_delete_post_not_found() {
        $_POST = array('action' => 'delete', 'url' => '/?p=999');
        $resp = $this->parse_query();
        $this->assertEquals(400, self::$status);
        $this->assertContains('not found', $resp);
    }

    function test_delete_user_cannot_delete_posts() {
        $post_id = wp_insert_post(array('post_content' => 'xyz'));
        get_user_by('ID', $this->userid)->remove_role('editor');
        $_POST = array('action' => 'delete', 'url' => '/?p=' . $post_id);
        $resp = $this->parse_query();
        $this->assertEquals(403, self::$status);
        $this->assertContains('cannot delete posts', $resp);
    }

    function test_unknown_action() {
        $post_id = wp_insert_post(array('post_content' => 'xyz'));
        $_POST = array('action' => 'foo', 'url' => '/?p=' . $post_id);
        $resp = $this->parse_query();
        $this->assertEquals(400, self::$status);
        $this->assertContains('unknown action', $resp);
    }
}
