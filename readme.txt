=== Plugin Name ===
Contributors: snarfed
Tags: micropub
Requires at least: 3.0.1
Tested up to: 4.1
Stable tag: trunk
License: CC0
License URI: http://creativecommons.org/publicdomain/zero/1.0/
Donate link: -

A Micropub server plugin.

== Description ==

A [Micropub](http://micropub.net/) server plugin. From [micropub.net](http://micropub.net/):

> Micropub is an open API standard that is used to create posts on one's own domain using third-party clients. Web apps and native apps (e.g. iPhone, Android) can use Micropub to post short notes, photos, events or other posts to your own site, similar to a Twitter client posting to Twitter.com.

Supports create, update, and delete, but not undelete. Supports these
Micropub properties:

* `content`
* `name`
* `summary`
* `slug`
* `url`
* `published`
* `photo`

Adds one WordPress filter, `before_micropub($wp_args)`, and one hook,
`after_micropub($post_id)`.

Delegates token handling to [tokens.indieauth.com](https://tokens.indieauth.com/).

Development happens at http://github.com/snarfed/wordpress-micropub . Feedback
and pull requests are welcome!

== Installation ==

Install from the WordPress plugin directory or put `micropub.php` in your plugin directory. No setup needed.

== Frequently Asked Questions ==

None yet.

== Upgrade Notice ==

None yet.

== Screenshots ==

TODO

== Changelog ==

= 0.1 =
Initial release.

== Development ==

The canonical repo is http://github.com/snarfed/wordpress-micropub . Feedback
and pull requests are welcome!
