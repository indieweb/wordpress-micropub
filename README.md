# wordpress-micropub

A [Micropub](http://micropub.net/) server plugin for [WordPress](https://wordpress.org/).

From [micropub.net](http://micropub.net/):

> Micropub is an open API standard that is used to create posts on one's own domain using third-party clients. Web apps and native apps (e.g. iPhone, Android) can use Micropub to post short notes, photos, events or other posts to your own site, similar to a Twitter client posting to Twitter.com.

Once you've installed and activated the plugin, try using
[Quill](http://quill.p3k.io/) to create a new post on your site. It walks you
through the steps and helps you troubleshoot if you run into any problems. After
that, try other clients like [OwnYourGram](http://ownyourgram.com/),
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
[tokens.indieauth.com](https://tokens.indieauth.com/). For ease of development,
if the WordPress site is running on `localhost`, it logs a warning if the access
token is missing or invalid and still allows the request.

Stores [microformats2](http://microformats.org/wiki/microformats2) properties in
[post metadata](http://codex.wordpress.org/Function_Reference/post_meta_Function_Examples)
with keys prefixed by `mf2_`.
[Details here.](https://indiewebcamp.com/WordPress_Data#Microformats_data)

This project is placed in the public domain. You may also use it under the
[CC0 license](http://creativecommons.org/publicdomain/zero/1.0/).
