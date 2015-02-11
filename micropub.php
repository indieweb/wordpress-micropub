<?php
/*
 Plugin Name: Micropub
 Plugin URI: https://github.com/snarfed/wordpress-micropub
 Description: <a href="https://indiewebcamp.com/micropub">Micropub</a> server.
 Author: Ryan Barrett
 Author URI: https://snarfed.org/
 Version: 0.1
*/

if (!class_exists('Micropub')) :

add_action('init', array('Micropub', 'init'));

$token = 'soopersekret';

/**
 * Micropub Plugin Class
 */
class Micropub {
  /**
   * Initialize the plugin.
   */
  public static function init() {
    // register endpoint
    add_rewrite_endpoint('micropub', EP_ALL);
    add_filter('query_vars', array('Micropub', 'query_var'));
    add_action('parse_query', array('Micropub', 'parse_query'));

    // endpoint discovery
    add_action('wp_head', array('Micropub', 'html_header'), 99);
    add_action('send_headers', array('Micropub', 'http_header'));
    add_filter('host_meta', array('Micropub', 'jrd_links'));
    add_filter('webfinger_data', array('Micropub', 'jrd_links'));
  }

  /**
   * Adds some query vars
   *
   * @param array $vars
   * @return array
   */
  public static function query_var($vars) {
    $vars[] = 'micropub';
    return $vars;
  }

  /**
   * Parse the micropub request and render the document
   *
   * @param WP $wp WordPress request context
   *
   * @uses do_action() Calls 'micropub_request' on the default request
   */
  public static function parse_query($wp) {
    if (!array_key_exists('micropub', $wp->query_vars)) {
      return;
    }

    $input = file_get_contents('php://input');
    parse_str($input, $q);
    global $token;

    header('Content-Type: text/plain; charset=' . get_option('blog_charset'));

    // verify token
    if ($q['access_token'] != $token &&
        getallheaders()['Authorization'] == 'Bearer ' . $token) {
      status_header(401);
      echo 'Invalid access token';
      exit;
    } elseif (!isset($q['h']) && !isset($q['url'])) {
      status_header(400);
      echo 'requires either h= (for create) or url= (for update, delete, etc)';
      exit;
    }

    // support both action= and operation= parameter names
    if (!isset($q['action']) && isset($q['operation'])) {
      $q['action'] = $q['operation'];
    }

    if (!isset($q['url']) || $q['action'] == 'create') {
      $post_id = wp_insert_post(array(
        'post_name'     => $q['slug'] || '',
        'post_title'    => $q['name'] || '',
        'post_content'  => $q['content'] || '',
        'post_excerpt'  => $q['summary'] || '',
        'post_status'   => 'publish',
      ));
      status_header(201);
      header('Location: ' . get_permalink($post_id));

    } else {
      $post_id = url_to_postid($q['url']);
      if ($post_id == 0) {
        status_header(404);
        echo $q['url'] . 'not found';
        exit;
      }

      if ($q['action'] == 'edit' || !isset($q['action'])) {
        wp_update_post(array(
          'ID'            => $post_id,
          'post_title'    => $q['name'],
          'post_content'  => $q['content'],
        ));
        status_header(204);
      } elseif ($q['action'] == 'delete') {
        wp_trash_post($post_id);
        status_header(204);
      // TODO: figure out how to make url_to_postid() support posts in trash
      // } elseif ($action == 'undelete') {
      //   wp_update_post(array(
      //     'ID'           => $post_id,
      //     'post_status'  => 'publish',
      //   ));
      //   status_header(204);
      } else {
        status_header(400);
        echo 'unknown action ' . $q['action'];
        exit;
      }
    }

    // be sure to add an exit; to the end of your request handler
    do_action('micropub_request', $source, $target, $contents);

    exit;
  }

  /**
   * The micropub autodicovery meta tags
   */
  public static function html_header() {
    echo '<link rel="micropub" href="'.site_url("micropub").'" />'."\n";
  }

  /**
   * The micropub autodicovery http-header
   */
  public static function http_header() {
    header('Link: <'.site_url("micropub").'>; rel="micropub"', false);
  }

  /**
   * Generates webfinger/host-meta links
   */
  public static function jrd_links($array) {
    $array["links"][] = array("rel" => "micropub", "href" => site_url("micropub"));
    return $array;
  }
}

// blatantly stolen from https://github.com/idno/Known/blob/master/Idno/Pages/File/View.php#L25
if (!function_exists('getallheaders')) {
  function getallheaders()
  {
    $headers = '';
    foreach ($_SERVER as $name => $value) {
      if (substr($name, 0, 5) == 'HTTP_') {
        $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
      }
    }
    return $headers;
  }
}

endif;
