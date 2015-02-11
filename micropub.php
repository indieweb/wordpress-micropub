<?php
/*
 Plugin Name: Micropub
 Plugin URI: https://github.com/snarfed/wordpress-micropub
 Description: <a href="https://indiewebcamp.com/micropub">Micropub</a> server.
 Author: Ryan Barrett
 Author URI: https://snarfed.org/
 Version: 0.1

TODO
- /micropub instead of ?micropub=endpoint
- support Authorization HTTP header (how to get a request header from WP?)
- edit, delete, undelete

TO USE:
url_to_postid, wp_update_post, wp_trash_post, apply_filters, do_action
*/

// check if class already exists
if (!class_exists('Micropub')) :

// initialize plugin
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
    // TODO: add_rewrite_endpoint('micropub', EP_ROOT);
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
    // check if it is a micropub request or not
    if (!array_key_exists('micropub', $wp->query_vars)) {
      return;
    }

    $content = file_get_contents('php://input');
    parse_str($content);
    global $token;

    header('Content-Type: text/plain; charset=' . get_option('blog_charset'));

    // verify token
    if ($access_token != $token) {
      status_header(401);
      echo 'Invalid access token';
      exit;
    } elseif (!isset($h) && !isset($url)) {
      status_header(400);
      echo 'requires either h= (for create) or url= (for update, delete, etc)';
      exit;
    }

    if (!isset($url) || $operation == 'create') {
      $post_id = wp_insert_post(array(
        'post_title'    => $name,
        'post_content'  => $content,
        'post_status'   => 'publish',
      ));
      status_header(201);
      header('Location: ' . get_permalink($post_id));
    } elseif ($operation == 'edit' || !isset($operation)) {
        // TODO
    } elseif ($operation == 'delete') {
        // TODO
    } elseif ($operation == 'undelete') {
        // TODO
    }

    // be sure to add an exit; to the end of your request handler
    do_action('micropub_request', $source, $target, $contents);

    exit;
  }

  /**
   * The micropub autodicovery meta tags
   */
  public static function html_header() {
    echo '<link rel="micropub" href="'.site_url("?micropub=endpoint").'" />'."\n";
  }

  /**
   * The micropub autodicovery http-header
   */
  public static function http_header() {
    header('Link: <'.site_url("?micropub=endpoint").'>; rel="micropub"', false);
  }

  /**
   * Generates webfinger/host-meta links
   */
  public static function jrd_links($array) {
    $array["links"][] = array("rel" => "micropub",
                              "href" => site_url("?micropub=endpoint"));
    return $array;
  }
}

// end check if class already exists
endif;
