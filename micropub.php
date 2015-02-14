<?php
/*
 Plugin Name: Micropub
 Plugin URI: https://github.com/snarfed/wordpress-micropub
 Description: <a href="https://indiewebcamp.com/micropub">Micropub</a> server.
 Author: Ryan Barrett
 Author URI: https://snarfed.org/
 Version: 0.1
*/

// Example command line for testing:
// curl -i -H 'Authorization: Bearer ...' -F h=entry -F name=foo -F content=bar \
//   -F photo=@gallery/snarfed.gif 'http://localhost/w/?micropub=endpoint'

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
    header('Content-Type: text/plain; charset=' . get_option('blog_charset'));

    Micropub::authorize();

    // validate micropub request params
    if (!isset($_POST['h']) && !isset($_POST['url'])) {
      status_header(400);
      echo 'requires either h= (for create) or url= (for update, delete, etc)';
      exit;
    }

    // support both action= and operation= parameter names
    if (!isset($_POST['action'])) {
      $_POST['action'] = isset($_POST['operation']) ? $_POST['operation']
        : isset($_POST['url']) ? 'edit' : 'create';
    }

    $args = apply_filters('before_micropub', Micropub::generate_args());

    if (!isset($_POST['url']) || $_POST['action'] == 'create') {
      $args['post_status'] = 'publish';
      kses_remove_filters();  // prevent sanitizing HTML tags in post_content
      $args['ID'] = Micropub::check_error(wp_insert_post($args));
      kses_init_filters();
      Micropub::postprocess($args['ID']);
      status_header(201);
      header('Location: ' . get_permalink($args['ID']));

    } else {
      if ($args['ID'] == 0) {
        status_header(400);
        echo $_POST['url'] . ' not found';
        exit;
      }

      if ($_POST['action'] == 'edit' || !isset($_POST['action'])) {
        kses_remove_filters();  // prevent sanitizing HTML tags in post_content
        Micropub::check_error(wp_update_post($args));
        kses_init_filters();
        Micropub::postprocess($args['ID']);
        status_header(200);
      } elseif ($_POST['action'] == 'delete') {
        Micropub::check_error(wp_trash_post($args['ID']));
        status_header(200);
      // TODO: figure out how to make url_to_postid() support posts in trash
      // here's one way:
      // https://gist.github.com/peterwilsoncc/bb40e52cae7faa0e6efc
      // } elseif ($action == 'undelete') {
      //   Micropub::check_error(wp_update_post(array(
      //     'ID'           => $args['ID'],
      //     'post_status'  => 'publish',
      //   )));
      //   status_header(200);
      } else {
        status_header(400);
        echo 'unknown action ' . $_POST['action'];
        exit;
      }
    }
    do_action('after_micropub', $args['ID']);
    exit;
  }

  /**
   * Use tokens.indieauth.com to validate the access token.
   */
  private static function authorize() {
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
      $auth_header = $headers['Authorization'];
    } elseif (isset($_POST['access_token'])) {
      $auth_header = 'Bearer ' . $_POST['access_token'];
    } else {
      return Micropub::handle_authorize_error(401, 'missing access token');
    }

    $resp = wp_remote_get('https://tokens.indieauth.com/token',
                          array('headers' => array(
                            'Content-type' => 'application/x-www-form-urlencoded',
                            'Authorization' => $auth_header)));
    $code = wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);
    if ($code / 100 != 2) {
      return Micropub::handle_authorize_error(
        $code, 'invalid access token: ' . $body);
    }

    parse_str($body, $resp);
    $home = untrailingslashit(home_url());
    $me = untrailingslashit($resp['me']);
    if ($home != $me) {
      return Micropub::handle_authorize_error(
        401, 'access token URL ' . $me . " doesn't match " . $home);
    } else if (!isset($resp['scope']) ||
               !in_array('post', explode(' ', $resp['scope']))) {
      return Micropub::handle_authorize_error(
        403, 'access token is missing post scope; got ' . $resp['scope']);
    }
  }

  private static function handle_authorize_error($code, $msg) {
    $home = untrailingslashit(home_url());
    if ($home == 'http://localhost') {
        echo 'WARNING: ' . $code . ' ' . $msg .
          ". Allowing only because this is localhost.\n";
        return;
    }
    status_header($code);
    echo $msg;
    exit;
  }

  /**
   * Generate args for WordPress wp_insert_post() and friends.
   */
  private static function generate_args() {
    // these can be passed through untouched
    $mp_to_wp = array(
      'slug'     => 'post_name',
      'name'     => 'post_title',
      'summary'  => 'post_excerpt'
    );

    $args = array();
    foreach ($_POST as $param => $value) {
      if (isset($mp_to_wp[$param])) {
        $args[$mp_to_wp[$param]] = $value;
      }
    }

    // these are transformed or looked up
    if (isset($_POST['url'])) {
      $args['ID'] = url_to_postid($_POST['url']);
    }

    if (isset($_POST['published'])) {
      $args['post_date'] = iso8601_to_datetime($_POST['published']);
    }

    // Map micropub categories to WordPress categories if they exist, otherwise
    // to WordPress tags.
    if (isset($_POST['category'])) {
      foreach ($_POST['category'] as $mp_cat) {
        $wp_cat = get_category_by_slug($mp_cat);
        if ($wp_cat) {
          $args['post_category'][] = $wp_cat->term_id;
        } else {
          $args['tags_input'][] = $mp_cat;
        }
      }
    }

    $args['post_content'] = Micropub::generate_post_content();
    return $args;
  }

  /**
   * Generates and returns a post_content string suitable for wp_insert_post()
   * and friends.
   */
  private static function generate_post_content() {
    // interactions
    foreach (array('like', 'repost', 'in-reply-to') as $cls) {
      $val = isset($_POST[$cls]) ? $_POST[$cls]
             : (isset($_POST[$cls . '-of']) ? $_POST[$cls . '-of']
             : NULL);
      if ($val) {
        if ($cls != 'in-reply-to') {
          $cls .= 's';
        }
        $lines[] = '<p>' . ucfirst(str_replace('-', ' ', $cls)) .
          ' <a class="u-' . $cls . ' href="' . $val . '">' . $val . '</a>.</p>';
      }
    }

    if (isset($_POST['rsvp'])) {
      $lines[] = '<p>RSVPs <data class="p-rsvp" value="' . $_POST['rsvp'] .
        '">' . $_POST['rsvp'] . '</data>.</p>';
    }

    // content, event
    if (isset($_POST['content'])) {
      $lines[] = '<div class="e-content">';
      $lines[] = $_POST['content'];
      if (isset($_POST['h']) && $_POST['h'] == 'event') {
        $lines[] = Micropub::generate_event();
      }
      $lines[] = '</div>';
    }

    // TODO: generate my own markup so i can include u-photo
    if (isset($_FILES['photo'])) {
      $lines[] = "\n[gallery size=full columns=1]";
    }

    return implode("\n", $lines);
  }

  /**
   * Generates and returns a string h-event.
   */
  private static function generate_event() {
    $lines[] = '<div class="h-event">';

    if (isset($_POST['name'])) {
      $lines[] = '<h1 class="p-name">' . $_POST['name'] . '</h1>';
    }

    $lines[] = '<p>';
    $times = array();
    foreach (array('start', 'end') as $cls) {
      if (isset($_POST[$cls])) {
        $datetime = iso8601_to_datetime($_POST[$cls]);
        $times[] = '<time class="dt-' . $cls . '" datetime="' . $_POST[$cls] .
          '">' . $datetime . '</time>';
      }
    }
    $lines[] = implode("\nto\n", $times);

    if (isset($_POST['location']) && substr($_POST['location'], 0, 4) != 'geo:') {
      $lines[] = 'at <a class="p-location" href="' . $_POST['location'] . '">' .
        $_POST['location'] . '</a>';
    }

    end($lines);
    $lines[key($lines)] .= '.';
    $lines[] = '</p>';

    if (isset($_POST['summary'])) {
      $lines[] = '<p class="p-summary">' . urldecode($_POST['summary']) . '</p>';
    }
  
    if (isset($_POST['description'])) {
      $lines[] = '<p class="p-description">' . urldecode($_POST['description']) . '</p>';
    }

    $lines[] = '</div>';
    return implode("\n", $lines);
  }

  /**
   * Postprocesses a post that has been created or updated. Useful for changes
   * that require the post id, e.g. uploading media and adding post metadata.
   */
  private static function postprocess($post_id) {
    if (isset($_FILES['photo'])) {
      require_once(ABSPATH . 'wp-admin/includes/image.php');
      require_once(ABSPATH . 'wp-admin/includes/file.php');
      require_once(ABSPATH . 'wp-admin/includes/media.php');
      Micropub::check_error(media_handle_upload('photo', $post_id));
    }

    if (isset($_POST['location']) && substr($_POST['location'], 0, 4) == 'geo:') {
      // Geo URI format:
      // http://en.wikipedia.org/wiki/Geo_URI#Example
      // https://indiewebcamp.com/micropub##location
      $geo = str_replace('geo:', '', urldecode($_POST['location']));
      $geo = explode(';', explode(':', $geo)[0])[0];
      $coords = explode(',', $geo);
      update_post_meta($post_id, 'geo_latitude', trim($coords[0]));
      update_post_meta($post_id, 'geo_longitude', trim($coords[1]));
    }
}

  private static function check_error($result) {
    if (!$result) {
      status_header(500);
      echo 'Unknown WordPress error: ' . $result;
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
