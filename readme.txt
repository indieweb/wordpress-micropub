=== Plugin Name ===
Contributors: snarfed, dshanske
Tags: micropub
Requires at least: 3.0.1
Tested up to: 4.5
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

Adds the following filters:
* `before_micropub($wp_args)`
* `micropub_syndicate-to', array(), $user_id)`

And the hook:
* `after_micropub($post_id)`

Delegates token handling to
[tokens.indieauth.com](https://tokens.indieauth.com/) by default. For ease of
development, if the WordPress site is running on `localhost`, it logs a warning
if the access token is missing or invalid and still allows the request. 
There is also a wp-config option to use WordPress authentication.

Stores [microformats2](http://microformats.org/wiki/microformats2) properties in
[post metadata](http://codex.wordpress.org/Function_Reference/post_meta_Function_Examples)
with keys prefixed by `mf2_`.
[Details here.](https://indiewebcamp.com/WordPress_Data#Microformats_data)

Development happens at http://github.com/snarfed/wordpress-micropub . Feedback
and pull requests are welcome!

== Installation ==

Install from the WordPress plugin directory or put `micropub.php` in your plugin directory. No setup needed.

== Configuration Options ==

These configuration options can be enabled by adding them to your wp-config.php

* `define('MICROPUB_LOCAL_AUTH', '1')` - Bypasses Micropub authentication in 
favor of WordPress authentication for testing purposes
* `define('MICROPUB_AUTHENTICATION_ENDPOINT'`, 'https://indieauth.com/auth') 
Define a custom authentication endpoint
* `define('MICROPUB_TOKEN_ENDPOINT', 'https://tokens.indieauth.com/token')` - 
Define a custom token endpoint
* `define('MICROPUB_DRAFT_MODE', '1')` - set all micropub posts to draft mode

== Frequently Asked Questions ==

None yet.

== Upgrade Notice ==

None yet.

== Screenshots ==

TODO

== Changelog ==

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

== Development ==

The canonical repo is http://github.com/snarfed/wordpress-micropub . Feedback
and pull requests are welcome!
