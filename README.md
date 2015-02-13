# wordpress-micropub

A [Micropub](http://micropub.net/) server plugin for [WordPress](https://wordpress.org/).

From [micropub.net](http://micropub.net/):

> Micropub is an open API standard that is used to create posts on one's own domain using third-party clients. Web apps and native apps (e.g. iPhone, Android) can use Micropub to post short notes, photos, events or other posts to your own site, similar to a Twitter client posting to Twitter.com."

Supports create, update, and delete, but not undelete. Supports these
Micropub properties:

* `content`
* `name`
* `summary`
* `slug`
* `url`
* `published`

Delegates token handling to [tokens.indieauth.com](https://tokens.indieauth.com/).

Adds one WordPress filter, `before_micropub($wp_args, $micropub_params)`, and
one hook, `after_micropub($micropub_params, $post_id)`.

This project is placed in the public domain. You may also use it under the
[CC0 license](http://creativecommons.org/publicdomain/zero/1.0/).
