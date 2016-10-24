=== Plugin Name ===
Contributors: snarfed, dshanske
Tags: micropub
Requires at least: 4.4
Tested up to: 4.6.1
Stable tag: trunk
License: CC0
License URI: http://creativecommons.org/publicdomain/zero/1.0/
Donate link: -

A Micropub server plugin.

== Description ==

A [Micropub](http://micropub.net/) server plugin. From [micropub.net](http://micropub.net/):

> Micropub is an open API standard that is used to create posts on one's own domain using third-party clients. Web apps and native apps (e.g. iPhone, Android) can use Micropub to post short notes, photos, events or other posts to your own site, similar to a Twitter client posting to Twitter.com.

Once you've installed and activated the plugin, try using
[Quill](http://quill.p3k.io/) to create a new post on your site. It walks you
through the steps and helps you troubleshoot if you run into any problems. After
that, try other clients like [OwnYourGram](http://ownyourgram.com/), [OwnYourCheckin](https://ownyourcheckin.wirres.net/),
[MobilePub](http://indiewebcamp.com/MobilePub), and
[Teacup](https://teacup.p3k.io/).

Supports the [full W3C Micropub CR spec](https://www.w3.org/TR/micropub/) as of
2016-10-18, except for the optional media endpoint. Media may be uploaded
directly to the wordpress-micropub endpoint as multipart/form-data, or
sideloaded from URLs.

== WordPress details ==

=== Filters and hooks ===

Adds one filter: `before_micropub( $input )`

Called before handling a Micropub request. Returns $input, possibly modified.

...and one hook: `after_micropub( $input, $wp_args = null)`

Called after handling a Micropub request. Not called if the request fails
(ie doesn't return HTTP 2xx).

Arguments:

`$input`: associative array, the Micropub request in
  [JSON format](http://micropub.net/draft/index.html#json-syntax). If the
  request was form-encoded or a multipart file upload, it's converted to JSON
  format.

`$wp_args`: optional associative array. For creates and updates, this is the
8  arguments passed to wp_insert_post or wp_update_post. For deletes and
  undeletes, args['ID'] contains the post id to be (un)deleted. Null for queries.

=== Other ===

Stores [microformats2](http://microformats.org/wiki/microformats2) properties in
[post metadata](http://codex.wordpress.org/Function_Reference/post_meta_Function_Examples)
with keys prefixed by `mf2_`.
[Details here.](https://indiewebcamp.com/WordPress_Data#Microformats_data)
All values are arrays; use `unserialize()` to deserialize them.

Does *not* support multithreading. (PHP doesn't really either, so it generally
won't matter, but just for the record.)


== Authentication and authorization ==

Supports the full OAuth2/IndieAuth authentication and authorization flow.
Defaults to IndieAuth. Custom auth and token endpoints can be used by overriding
the `MICROPUB_AUTHENTICATION_ENDPOINT` and `MICROPUB_TOKEN_ENDPOINT` endpoints.
If the token's `me` value matches a WordPress user's URL, that user will be
used. Otherwise, the token must match the site's URL, and no user will be used.

Alternatively, you can set `MICROPUB_LOCAL_AUTH` to 1 to use WordPress's
internal user login instead of tokens.

Finally, for ease of development, if the WordPress site is running on
`localhost`, it logs a warning if the access token is missing or invalid and
still allows the request.


== Installation ==

Install from the WordPress plugin directory or put `micropub.php` in your plugin directory. No setup needed.


== Configuration Options ==

These configuration options can be enabled by adding them to your wp-config.php

* `define('MICROPUB_LOCAL_AUTH', '1')` - Bypasses Micropub authentication in
favor of WordPress authentication
* `define('MICROPUB_AUTHENTICATION_ENDPOINT', 'https://indieauth.com/auth')` -
Define a custom authentication endpoint
* `define('MICROPUB_TOKEN_ENDPOINT', 'https://tokens.indieauth.com/token')` -
Define a custom token endpoint
* `define('MICROPUB_DRAFT_MODE', '1')` - set all micropub posts to draft mode

== Frequently Asked Questions ==

None yet.

== Upgrade Notice ==

None yet.

== Screenshots ==

None.

== Development ==

The canonical repo is http://github.com/snarfed/wordpress-micropub . Feedback
and pull requests are welcome!

To add a new release to the WordPress plugin directory, run `push.sh`.

To set up your local environment to run the unit tests:

1. Install [PHPUnit](https://github.com/sebastianbergmann/phpunit#installation),
   e.g. `brew install homebrew/php/wp-cli phpunit` with Homebrew on Mac OS X.
1. Install and start MySQL. (You may already have it.)
1. Run `./bin/install-wp-tests.sh wordpress_micropub_test root '' localhost` to
   download WordPress and
   [its unit test library](https://develop.svn.wordpress.org/trunk/tests/phpunit/),
   into `/tmp` and `./temp` by default, and create a MySQL db to test against.
   [Background here](http://wp-cli.org/docs/plugin-unit-tests/). Feel free to
   use a MySQL user other than `root`. You can set the `WP_CORE_DIR` and
   `WP_TESTS_DIR` environment variables to change where WordPress and its test
   library are installed. For example, I put them both in the repo dir.
1. Open `wordpress-tests-lib/wp-tests-config.php` and add a slash to the end of
   the ABSPATH value. No clue why it leaves off the slash; it doesn't work
   without it.
1. Run `phpunit` in the repo root dir. If you set `WP_CORE_DIR` and
   `WP_TESTS_DIR` above, you'll need to set them for this too. You should see
   output like this:

    ```
    Installing...
    ...
    1 / 1 (100%)
    Time: 703 ms, Memory: 33.75Mb
    OK (1 test, 3 assertions)
    ```

To set up PHPCodesniffer to test changes for adherance to WordPress Coding Standards

1. install [PHPCS](https://github.com/squizlabs/PHP_CodeSniffer).
1. install and connect [WordPress-Coding-Standards](https://github.com/WordPress-Coding-Standards/WordPress-Coding-Standards)
1. Run in command line or install a plugin for your favorite editor.
1. To list coding standard issues in a file, run phpcs --standard=phpcs.ruleset.xml micropub.php
1. If you want to try to automatically fix issues, run phpcbf with the same arguments as phpcs.


== Changelog ==

= 1.0 =
Substantial update. Supports
[full W3C Micropub spec](https://www.w3.org/TR/micropub/), except for optional
media endpoint.

* Post content will not be automatically marked up if theme supports
  microformats2 or
  [Post Kinds plugin](https://wordpress.org/plugins/indieweb-post-kinds/) is
  enabled.
* Add PHP Codesniffer File

= 0.4 =
* Store all properties in post meta except those in a blacklist
* Support setting authentication and token endpoint in wp-config
  (set MICROPUB_AUTHENTICATION_ENDPOINT and MICROPUB_TOKEN_ENDPOINT)
* Support setting all micropub posts to draft in wp-config for testing by
  setting MICROPUB_DRAFT_MODE in wp-config.
* Support using local auth to authenticate as opposed to Indieauth
  as by setting MICROPUB_LOCAL_AUTH in wp-config
* Set content to summary if no content provided
* Support querying for syndicate-to and future query options.

= 0.3 =
* Use the specific WordPress user whose URL matches the access token, if
  possible.
* Set `post_date_gmt` as well as `post_date`.

= 0.2 =
* Support more Micropub properties: photo, like-of, repost-of, in-reply-to,
  rsvp, location, category, h=event
* Check but don't require access tokens on localhost.
* Better error handling.

= 0.1 =
Initial release.
