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

/**
 * Micropub Plugin Class
 */
class Micropub {
  /**
   * Initialize the plugin.
   */
  public static function init() {
    // register endpoint
    // (I originally used add_rewrite_endpoint() to serve on /micropub instead
    // of ?micropub=endpoint, but that had problems. details in
    // https://github.com/snarfed/wordpress-micropub/commit/d3bdc433ee019d3968be6c195b0384cba5ffe36b#commitcomment-9690066 )
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
    header('Content-Type: text/plain; charset=' . get_option('blog_charset'));

    Micropub::authorize($q);

    // validate micropub request params
    if (!isset($q['h']) && !isset($q['url'])) {
      status_header(400);
      echo 'requires either h= (for create) or url= (for update, delete, etc)';
      exit;
    }

    // support both action= and operation= parameter names
    if (!isset($q['action']) && isset($q['operation'])) {
      $q['action'] = $q['operation'];
    }

    $args = apply_filters('before_micropub', Micropub::map_params($q), $q);
    if (!isset($q['url']) || $q['action'] == 'create') {
      $args['post_status'] = 'publish';
      $result = Micropub::check_error(wp_insert_post($args));
      $args['ID'] = $result;
      status_header(201);
      header('Location: ' . get_permalink($result));

    } else {
      if ($args['ID'] == 0) {
        status_header(400);
        echo $q['url'] . ' not found';
        exit;
      }

      if ($q['action'] == 'edit' || !isset($q['action'])) {
        Micropub::check_error(wp_update_post($args));
        status_header(204);
      } elseif ($q['action'] == 'delete') {
        Micropub::check_error(wp_trash_post($args['ID']));
        status_header(204);
      // TODO: figure out how to make url_to_postid() support posts in trash
      // here's one way:
      // https://gist.github.com/peterwilsoncc/bb40e52cae7faa0e6efc
      // } elseif ($action == 'undelete') {
      //   Micropub::check_error(wp_update_post(array(
      //     'ID'           => $args['ID'],
      //     'post_status'  => 'publish',
      //   )));
      //   status_header(204);
      } else {
        status_header(400);
        echo 'unknown action ' . $q['action'];
        exit;
      }
    }
    do_action('after_micropub', $q, $args['ID']);
    exit;
  }

  /**
   * Use tokens.indieauth.com to validate the access token.
   */
  private static function authorize($q) {
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
      $auth_header = $headers['Authorization'];
    } elseif (isset($q['access_token'])) {
      $auth_header = 'Bearer ' . $q['access_token'];
    } else {
      status_header(401);
      echo 'Missing access_token';
      exit;
    }

    $resp = wp_remote_get('https://tokens.indieauth.com/token',
                          array('headers' => array(
                            'Content-type' => 'application/x-www-form-urlencoded',
                            'Authorization' => $auth_header)));
    $code = wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);
    if ($code / 100 != 2) {
      status_header($code);
      echo 'Invalid access_token: ' . $body;
      exit;
    }

    parse_str($body, $resp);
    $home = untrailingslashit(home_url());
    if ($home != 'http://localhost' &&
        $home != untrailingslashit($resp['me'])) {
      status_header(401);
      echo 'access_token domain ' . $resp['me'] . " doesn't match " . $url;
      exit;
    } else if (!isset($resp['scope']) ||
               !in_array('post', explode(' ', $resp['scope']))) {
      status_header(403);
      echo "access token is missing post scope; got " . $resp['scope'];
      exit;
    }
  }

  /**
   * Map Micropub parameters to WordPress wp_insert_post() args.
   */
  private static function map_params($q) {
    // these can be passed through untouched
    $mp_to_wp = array(
      'slug'     => 'post_name',
      'name'     => 'post_title',
      'content'  => 'post_content',
      'summary'  => 'post_excerpt'
    );

    $args = array();
    foreach ($q as $param => $value) {
      if (isset($mp_to_wp[$param])) {
        $args[$mp_to_wp[$param]] = $value;
      }
    }

    // these are transformed or looked up
    if (isset($q['url'])) {
      $args['ID'] = url_to_postid($q['url']);
    }

    if (isset($q['published'])) {
      $args['post_date'] = iso8601_to_datetime($q['published']);
    }

    return $args;
  }

  private static function check_error($result) {
    if (!$result) {
      status_header(500);
      echo 'Unknown WordPress error';
      exit;
    } else if (is_wp_error($result)) {
      status_header(500);
      echo $result->get_error_message();
      exit;
    }
    return $result;
  }

  /**
   * The micropub autodicovery meta tags
   */
  public static function html_header() {
?>
<link rel="micropub" href="<?php echo site_url('?micropub=endpoint') ?>">
<link rel="authorization_endpoint" href="https://indieauth.com/auth">
<link rel="token_endpoint" href="https://tokens.indieauth.com/token">
<?php
  }

  /**
   * The micropub autodicovery http-header
   */
  public static function http_header() {
    header('Link: <' . site_url('?micropub=endpoint') . '>; rel="micropub"', false);
    header('Link: <https://indieauth.com/auth>; rel="authorization_endpoint"', false);
    header('Link: <https://tokens.indieauth.com/token>; rel="token_endpoint"', false);
  }

  /**
   * Generates webfinger/host-meta links
   */
  public static function jrd_links($array) {
    $array['links'][] = array('rel' => 'micropub',
                              'href' => site_url('?micropub=endpoint'));
    $array['links'][] = array('rel' => 'authorization_endpoint',
                              'href' => 'https://indieauth.com/auth');
    $array['links'][] = array('rel' => 'token_endpoint',
                              'href' => 'https://tokens.indieauth.com/token');
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
?>
