# wordpress-micropub [![Circle CI](https://circleci.com/gh/snarfed/wordpress-micropub.svg?style=svg)](https://circleci.com/gh/snarfed/wordpress-micropub)

A [Micropub](http://micropub.net/) server plugin for [WordPress](https://wordpress.org/). Available in the WordPress plugin directory at [wordpress.org/plugins/micropub](https://wordpress.org/plugins/micropub/).

From [micropub.net](http://micropub.net/):

> Micropub is an open API standard that is used to create posts on one's own domain using third-party clients. Web apps and native apps (e.g. iPhone, Android) can use Micropub to post short notes, photos, events or other posts to your own site, similar to a Twitter client posting to Twitter.com.

Once you've installed and activated the plugin, try using
[Quill](http://quill.p3k.io/) to create a new post on your site. It walks you
through the steps and helps you troubleshoot if you run into any problems. After
that, try other clients like [OwnYourGram](http://ownyourgram.com/), [OwnYourCheckin](https://ownyourcheckin.wirres.net/),
[MobilePub](http://indiewebcamp.com/MobilePub), and
[Teacup](https://teacup.p3k.io/).

Supports create, update, and delete, but not undelete. Supports these
Micropub properties:

* `category` maps to WordPress category if it exists, otherwise to WordPress tag
* `content`
* `description`
* `end`
* `h`=entry and `h`=event
* `in-reply-to`
* `like-of`/`like`
* `location` is stored in [WordPress standard geodata format](http://codex.wordpress.org/Geodata)
* `name`
* `photo`
* `published`
* `repost-of`/`repost`
* `rsvp`
* `slug`
* `start`
* `summary`
* `url`

Adds one WordPress filter, `before_micropub($wp_args)`, and one hook,
`after_micropub($post_id)`.

Delegates token handling to
[tokens.indieauth.com](https://tokens.indieauth.com/). If the token's `me` value
matches a WordPress user's URL, that user will be used. Otherwise, the token
must match the site's URL, and no user will be used.

For ease of development, if the WordPress site is running on `localhost`, it
logs a warning if the access token is missing or invalid and still allows the
request.

Stores [microformats2](http://microformats.org/wiki/microformats2) properties in
[post metadata](http://codex.wordpress.org/Function_Reference/post_meta_Function_Examples)
with keys prefixed by `mf2_`.
[Details here.](https://indiewebcamp.com/WordPress_Data#Microformats_data)

This project is placed in the public domain. You may also use it under the
[CC0 license](http://creativecommons.org/publicdomain/zero/1.0/).

### Development

To add a new release to the WordPress plugin directory, run `push.sh`.

To set up your local environment to run the unit tests:

1. Install [PHPUnit](https://github.com/sebastianbergmann/phpunit#installation),
   e.g. `brew install wp-cli phpunit` with Homebrew on Mac OS X.
1. Install and start MySQL. (You may already have it.)
1. Run `bin/install-wp-tests.sh wordpress_micropub_test root '' localhost` to
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
