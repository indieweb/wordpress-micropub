=== Micropub ===
Contributors: indieweb, snarfed, dshanske
Tags: micropub, publish, indieweb, microformats
Requires at least: 4.7
Requires PHP: 5.3
Tested up to: 5.1
Stable tag: 2.0.8
License: CC0
License URI: http://creativecommons.org/publicdomain/zero/1.0/
Donate link: -

A [Micropub](http://micropub.net/) server plugin. Available in the WordPress plugin directory at [wordpress.org/plugins/micropub](https://wordpress.org/plugins/micropub/).

== Description ==

[![Travis CI](https://travis-ci.org/indieweb/wordpress-micropub.svg?branch=master)](https://travis-ci.org/indieweb/wordpress-micropub)

> Micropub is an open API standard that is used to create posts on one's own domain using third-party clients. Web apps and native apps (e.g. iPhone, Android) can use Micropub to post short notes, photos, events or other posts to your own site, similar to a Twitter client posting to Twitter.com. 

Once you've installed and activated the plugin, try using [Quill](http://quill.p3k.io/) to create a new post on your site. It walks you through the steps and helps you troubleshoot if you run into any problems. A list of known Micropub clients are available [here](https://indieweb.org/Micropub/Clients)

Supports the [full W3C Micropub CR spec](https://www.w3.org/TR/micropub/) as of version 2.0.0.

As this allows the creation of posts without entering the WordPress admin, it is not subject to any Gutenberg compatibility concerns per se. Posts created will not have Gutenberg blocks
as they were not created with Gutenberg, but otherwise there should be no issues at this time.

== License ==

This project is placed in the public domain. You may also use it under the [CC0 license](http://creativecommons.org/publicdomain/zero/1.0/).

== Scope ==

Supports the following [scope](https://indieweb.org/scope) parameters requested by Micropub clients.
* post (legacy) - Grants all user delegated access
* create - Allows the client to create posts on behalf of the user
* update - Allows the client to update posts on behalf of the user
* delete - Allows the client to delete posts on behalf of the user
* undelete - Allows the client to undelete posts on behalf of the user
* media  - Supports media for the media endpoint, but create or update also give media upload permissions

== WordPress details ==

= Filters and hooks =
Adds six filters:

`before_micropub( $input )`

Called before handling a Micropub request. Returns `$input`, possibly modified.

`micropub_post_content( $post_content, $input )` 

Called during the handling of a Micropub request. The content generation function is attached to this filter by default. Returns `$post_content`, possibly modified.

`micropub_syndicate-to( $synd_urls, $user_id )`

Called to generate the list of `syndicate-to` targets to return in response to a query. Returns `$synd_urls`, an array, possibly modified. This filter is empty by default

`micropub_query( $resp, $input )`

Allows you to replace a query response with your own customized version to add additional information

`indieauth_scopes( $scopes )`

This returns scopes from a plugin implementing IndieAuth or from the internal IndieAuth code. This filter is empty by default.

`indieauth_response( $response )`

This returns the token auth response from a plugin implementing IndieAuth or from the internal IndieAuth code. This filter is empty by default.

...and two hooks:

`after_micropub( $input, $wp_args = null)`

Called after handling a Micropub request. Not called if the request fails (ie doesn't return HTTP 2xx).

`micropub_syndication( $ID, $syndicate_to )`


Called only if there are syndication targets $syndicate_to for post $ID. $syndicate_to will be an array of UIDs that are verified as one or more of the UIDs added using the `micropub_syndicate-to` filter.

Arguments:

* `$input`: associative array, the Micropub request in [JSON format](http://micropub.net/draft/index.html#json-syntax). If the request was form-encoded or a multipart file upload, it's converted to JSON format.
* `$wp_args`: optional associative array. For creates and updates, this is the arguments passed to `wp_insert_post` or `wp_update_post`. For deletes and undeletes, `args['ID']` contains the post id to be (un)deleted. Null for queries.

= Other =

Stores [microformats2](http://microformats.org/wiki/microformats2) properties in [post metadata](http://codex.wordpress.org/Function_Reference/post_meta_Function_Examples) with keys prefixed by `mf2_`. [Details here.](https://indiewebcamp.com/WordPress_Data#Microformats_data) All values are arrays; use `unserialize()` to deserialize them.

Does *not* support multithreading. PHP doesn't really either, so it generally won't matter, but just for the record.

Supports Experimental Extensions to Micropub:
* [Post Status](https://indieweb.org/Micropub-extensions#Post_Status) - Either `published` or `draft`
* [Visibility](https://indieweb.org/Micropub-extensions#Visibility) - Either `public` or `private`.
* [Location Visiblity](https://indieweb.org/Micropub-extensions#Location_Visibility) - Either `public`, `private`, or `protected`
* [Query for Post List](https://github.com/indieweb/micropub-extensions/issues/4) - Supports query for the last x number of posts. 
* [Query for Support Queries](https://github.com/indieweb/micropub-extensions/issues/7) - Returns a list of query parameters the endpoint supports
* [Query for Supported Properties](https://github.com/indieweb/micropub-extensions/issues/8) - Returns a list of which supported experimental properties the endpoint supports so the client can choose to hide unsupported ones.


If an experimental property is not set to one of these options, the plugin will return HTTP 400 with body:

    {
      "error": "invalid_request",
    }

WordPress has a [whitelist of file extensions that it allows in uploads](https://codex.wordpress.org/Uploading_Files#About_Uploading_Files_on_Dashboard). If you upload a file in a Micropub extension that doesn't have an allowed extension, the plugin will return HTTP 400 with body:

    {
      "error": "invalid_request",
      "error_description": "Sorry, this file is not permitted for security reasons."
    }


== Authentication and authorization ==

For reasons of security it is recommended that you only use this plugin on sites that implement HTTPS.

Supports the full OAuth2/IndieAuth authentication and authorization flow. Defaults to IndieAuth.com. Custom auth and token endpoints can be used by overriding the `MICROPUB_AUTHENTICATION_ENDPOINT`
and `MICROPUB_TOKEN_ENDPOINT` endpoints or by setting the options `indieauth_authorization_endpoint` and `indieauth_token_endpoint`.

If you want to use your own site as an IndieAuth endpoint, you can activate the IndieAuth plugin which is recommended but not required. You can disable the authentication in favor of an alternative plugin.
This can be done by removing the loading of the auth flow or setting MICROPUB_LOCAL_AUTH to 1.

`remove_action( 'plugins_loaded', 'load_micropub_auth', 20 );` 

If the token's `me` value matches a WordPress user's or author post URL, that user will be used. If there is only one site author that will be matched otherwise.

== Installation ==

Install from the WordPress plugin directory. No setup needed.


== Configuration Options ==

These configuration options can be enabled by adding them to your wp-config.php

* `define('MICROPUB_AUTHENTICATION_ENDPOINT', 'https://indieauth.com/auth')` - Define a custom authentication endpoint. Can be overridden in the settings interface
* `define('MICROPUB_TOKEN_ENDPOINT', 'https://tokens.indieauth.com/token')` - Define a custom token endpoint. Can be overridden in the settings interface.
* `define('MICROPUB_NAMESPACE', 'micropub/1.0' )` - By default the namespace for micropub is micropub/1.0. This would allow you to change this for your endpoint
* `define('MICROPUB_DISABLE_NAG', 1 ) - Disable notices for insecure sites
* `define('MICROPUB_LOCAL_AUTH', 1 ) - Disable built in AUTH in favor of your own plugin. Recommend plugin developers use the filter `disable_micropub_auth` for this.

These configuration options can be enabled by setting them in the WordPress options table.
* `indieauth_authorization_endpoint` - if set will override MICROPUB_AUTHENTICATION_ENDPOINT for setting a custom endpoint
* `indieauth_token_endpoint` - if set will override MICROPUB_TOKEN_ENDPOINT for setting a custom endpoint
* `micropub_default_post_status` - if set, Micropub posts will be set to this status by default( publish, draft, or private ). Can also be set on the settings page.

== Frequently Asked Questions ==

If your Micropub client includes an `Authorization` HTTP request header but you still get an HTTP 401 response with body `missing access token`, your server may be stripping the `Authorization` header. If you're on Apache, [try adding this line to your `.htaccess` file](https://github.com/indieweb/wordpress-micropub/issues/56#issuecomment-299202820):

    SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1

If that doesn't work, [try this line](https://github.com/georgestephanis/application-passwords/wiki/Basic-Authorization-Header----Missing):

    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

If that doesn't work either, you may need to ask your hosting provider to whitelist the `Authorization` header for your account. If they refuse, you can [pass it through Apache with an alternate name](https://github.com/indieweb/wordpress-micropub/issues/56#issuecomment-299569822). The plugin searches for the header in REDIRECT_HTTP_AUTHORIZATION, as some FastCGI implementations store the header in this location.

If you are getting an `Unauthorized` error despite passing a valid access token then your WordPress installation may not be able to match your user account with the provided URL. The easiest way to 
resolve is to add the URL you are using as the URL in your user profile. 

== Upgrade Notice ==

= Version 2.0.0 =

This version changes the Micropub endpoint URL as it now uses the REST API. You may have to update any third-parties that have cached this info.

== Screenshots ==

None.

== Development ==

The canonical repo is http://github.com/indieweb/wordpress-micropub . Feedback and pull requests are welcome!

To add a new release to the WordPress plugin directory, run `push.sh`.

To set up your local environment to run the unit tests and set up PHPCodesniffer to test adherence to [WordPress Coding Standards](https://make.wordpress.org/core/handbook/coding-standards/php/) and [PHP 5.3 Compatibility](https://github.com/wimg/PHPCompatibility):

1. Install [Composer](https://getcomposer.org). Composer is only used for development and is not required to run the plugin.
1. Run `composer install` which will install PHP Codesniffer, PHPUnit, the standards required, and all dependencies.

To configure PHPUnit

1. Install and start MySQL. (You may already have it.)
1. Run `./bin/install-wp-tests.sh wordpress_micropub_test root '' localhost` to download WordPress and [its unit test library](https://develop.svn.wordpress.org/trunk/tests/phpunit/), into `/tmp` and `./temp` by default, and create a MySQL db to test against. [Background here](http://wp-cli.org/docs/plugin-unit-tests/). Feel free to use a MySQL user other than `root`. You can set the `WP_CORE_DIR` and `WP_TESTS_DIR` environment variables to change where WordPress and its test library are installed. For example, I put them both in the repo dir.
1. Open `wordpress-tests-lib/wp-tests-config.php` and add a slash to the end of the ABSPATH value. No clue why it leaves off the slash; it doesn't work without it.
1. Run `phpunit` in the repo root dir. If you set `WP_CORE_DIR` and `WP_TESTS_DIR` above, you'll need to set them for this too. You should see output like this:

    ```
    Installing...
    ...
    1 / 1 (100%)
    Time: 703 ms, Memory: 33.75Mb
    OK (1 test, 3 assertions)
    ```

To set up PHPCodesniffer to test adherence to [WordPress Coding Standards](https://make.wordpress.org/core/handbook/coding-standards/php/) and [PHP 5.3 Compatibility](https://github.com/wimg/PHPCompatibility):

1. To list coding standard issues in a file, run `phpcs --standard=phpcs.xml`
1. If you want to try to automatically fix issues, run `phpcbf` with the same arguments as `phpcs`.

To automatically convert the readme.txt file to readme.md, you may, if you have installed composer as noted in the previous section, enter `composer update-readme` to have the .txt file converted
into markdown and saved to readme.md.

== Changelog ==

= 2.0.8 (2019-03-08) =
* Parse geo URI into h-geo or h-card object

= 2.0.7 (2019-02-18) =
* Update geo storage to fix accuracy storage as well as allow for name parameter and future parameters to be passed. Indigenous for Android now supports passing this

= 2.0.6 (2018-12-30) =
* Adjust query filter to allow for new properties to be added by query
* Add Gutenberg information into README

= 2.0.5 (2018-11-23) =
* Move syndication trigger to after micropub hook in order to ensure final version is rendered before sending syndication
* Add settings UI for alternate authorization endpoint and token endpoint which will be hidden if Indieauth plugin is enabled

= 2.0.4 (2018-11-17) =
* Issues raised on prior release.
* Removed generating debug messages when the data is empty

= 2.0.3 (2018-11-17) =
* Fix issue where the after_micropub action could not see form encoded files by adding them as properties on upload
* Fix issue in previous release where did not account for a null request sent by wpcli
* Add search parameter to category
* Wrap category query in categories key to be consistent with other query parameters
* If a URL is not provided to the query source parameter it will return the last 10 posts or more/less with an optional parameter
* Micropub query filter now called after default parameters are added rather than before so it can modify the defaults rather than replacing them.
* Micropub config query now returns a list of supported mp parameters and supported q query parameters
* Micropub media endpoint config query now returns an empty array indicating that it has no configuration parameters yet

= 2.0.2 (2018-11-12) =
* Fix issue with built-in auth and update compatibility testing
* Add experimental endpoint discovery option(https://indieweb.org/micropub_media_endpoint#Discovery_via_link_rel)

= 2.0.1 (2018-11-04) =
* Move authorization code later in load to resolve conflict

= 2.0.0 (2018-10-22) =
* Split plugin into files by functionality
* Change authorization to integrate with WordPress mechanisms for login
* Reject where the URL cannot be matched with a user account
* Rewrite using REST API
* Use `indieauth_scopes` and `indieauth_response` originally added for IndieAuth integration to be used by built in auth as well
* Improve handling of access tokens in headers to cover additional cases
* Add Media Endpoint
* Improve error handling
* Ensure compliance with Micropub spec
* Update composer dependencies and include PHPUnit as a development dependency
* Add nag notice for http domains and the option to diable with a setting
* Load auth later in init sequence to avoid conflict

= 1.4.3 (2018-05-27) =
* Change scopes to filter
* Get token response when IndieAuth plugin is installed

= 1.4.2 (2018-04-19) =
* Enforce scopes

= 1.4.1 (2018-04-15) =
* Version bump due some individuals not getting template file

= 1.4 (2018-04-08) =
* Separate functions that generate headers into micropub and IndieAuth
* Add support for an option now used by the IndieAuth plugin to set alternate token and authorization endpoints
* MICROPUB_LOCAL_AUTH configuration option adjusted to reflect that this disables the plugin built in authentication. This can hand it back to WordPress or allow another plugin to take over
* MICROPUB_LOCAL_AUTH now disables adding auth headers to the page.
* Fix post status issue by checking for valid defaults
* Add configuration option under writing settings to set default post status
* Add `micropub_syndication` hook that only fires on a request to syndicate to make it easier for third-party plugins to hook in

= 1.3 (2017-12-31) =
* Saves [access token response](https://tokens.indieauth.com/) in a post meta field `micropub_auth_response`.
* Bug fix for `post_date_gmt`
* Store timezone from published in arguments passed to micropub filter
* Correctly handle published times that are in a different timezone than the site.
* Set minimum version to PHP 5.3
* Adhere to WordPress Coding Standards
* Add `micropub_query` filter
* Support Nested Properties in Content Generation 
* Deprecate `MICROPUB_DRAFT_MODE` configuration option in favor of setting option
* Remove post content generation override in case of microformats2 capable theme or Post Kinds plugin installed
* Introduce `micropub_post_content` filter to which post content generation is attached so that a theme or plugin can modify/remove the post generation as needed

= 1.2 (2017-06-25) =
* Support [OwnYourSwarm](https://ownyourswarm.p3k.io/)'s [custom `checkin` microformats2 property](https://ownyourswarm.p3k.io/docs#checkins), including auto-generating content if necessary.
* Support `u-bookmark-of`.

= 1.1 (2017-03-30) =
* Support [`h-adr`](http://microformats.org/wiki/h-adr), [`h-geo`](http://microformats.org/wiki/h-geo), and plain text values for [`p-location`](http://microformats.org/wiki/h-event#p-location).
* Bug fix for create/update with `content[html]`.

= 1.0.1 =
* Remove accidental dependence on PHP 5.3 (#46).

= 1.0 =
Substantial update. Supports [full W3C Micropub spec](https://www.w3.org/TR/micropub/), except for optional
media endpoint.

* Change `mf2_*` post meta format from multiple separate values to single array value that can be deserialized with `unserialize`.
* Change the `before_micropub` filter's signature from `( $wp_args )` to `( $input )` (microformats2 associative array).
* Change the `after_micropub` hook's signature changed from `( $post_id )` to `( $input, $wp_args )` (microformats2 associative array, WordPress post args).
* Post content will not be automatically marked up if theme supports microformats2 or [Post Kinds plugin](https://wordpress.org/plugins/indieweb-post-kinds/) is enabled.
* Add PHP Codesniffer File.

= 0.4 =
* Store all properties in post meta except those in a blacklist.
* Support setting authentication and token endpoint in wp-config by setting `MICROPUB_AUTHENTICATION_ENDPOINT` and `MICROPUB_TOKEN_ENDPOINT`.
* Support setting all micropub posts to draft in wp-config for testing by setting `MICROPUB_DRAFT_MODE` in wp-config.
* Support using local auth to authenticate as opposed to IndieAuth as by setting `MICROPUB_LOCAL_AUTH` in wp-config.
* Set content to summary if no content provided.
* Support querying for syndicate-to and future query options.

= 0.3 =
* Use the specific WordPress user whose URL matches the access token, if possible.
* Set `post_date_gmt` as well as `post_date`.

= 0.2 =
* Support more Micropub properties: `photo`, `like-of`, `repost-of`, `in-reply-to`, `rsvp`, `location`, `category`, `h=event`.
* Check but don't require access tokens on localhost.
* Better error handling.

= 0.1 =
Initial release.
