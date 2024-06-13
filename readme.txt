=== Micropub ===
Contributors: indieweb, snarfed, dshanske
Tags: micropub, publish, indieweb, microformats
Requires at least: 4.9.9
Tested up to: 6.5.2
Stable tag: 2.4.0
Requires PHP: 7.2
License: CC0
License URI: http://creativecommons.org/publicdomain/zero/1.0/

Allows you to publish to your site using [Micropub](http://micropub.net/) clients.

== Description ==

Micropub is an open API standard that is used to create posts on your site using third-party clients. Web apps and native apps (e.g. iPhone, Android) can use Micropub to post short notes, photos, events or other posts to your own site, similar to a Twitter client posting to Twitter.com. Requires the IndieAuth plugin for authentication.

Once you've installed and activated the plugin, try a client such as [Quill](http://quill.p3k.io/) to create a new post on your site. It walks you through the steps and helps you troubleshoot if you run into any problems. A list of known Micropub clients are available [here](https://indieweb.org/Micropub/Clients)

Supports the full [Micropub spec](https://micropub.spec.indieweb.org/)

As this allows the creation of posts without entering the WordPress admin, it is not subject to any Gutenberg compatibility concerns per se. Posts created will not have Gutenberg blocks as they were not created with Gutenberg, but otherwise there should be no issues at this time.

Available in the WordPress plugin directory at [wordpress.org/plugins/micropub](https://wordpress.org/plugins/micropub/).

== License ==

This project is placed in the public domain. You may also use it under the [CC0 license](http://creativecommons.org/publicdomain/zero/1.0/).

== WordPress details ==

= Filters and hooks =
Adds ten filters:

`before_micropub( $input )`

Called before handling a Micropub request. Returns `$input`, possibly modified.

`micropub_post_content( $post_content, $input )`

Called during the handling of a Micropub request. The content generation function is attached to this filter by default. Returns `$post_content`, possibly modified.

`micropub_post_type( $post_type = 'post', $input )`

Called during the creation of a Micropub post. This defaults to post, but allows for setting Micropub posts to a custom post type.

`micropub_tax_input( $tax_input, $input )`

Called during the creation of a Micropub post. This defaults to nothing but allows for a Micropub post to set a custom taxonomy.

`micropub_syndicate-to( $synd_urls, $user_id, $input )`

Called to generate the list of `syndicate-to` targets to return in response to a query. Returns `$synd_urls`, an array, possibly modified. This filter is empty by default

`micropub_query( $resp, $input )`

Allows you to replace a query response with your own customized version to add additional information

`micropub_suggest_title( $mf2 )`

Allows a suggested title to be generated. This can be used either to generate the post slug or for individuals who want to use it to set a WordPress title

`indieauth_scopes( $scopes )`

This returns scopes from a plugin implementing IndieAuth. This filter is empty by default.

`indieauth_response( $response )`

This returns the token auth response from a plugin implementing IndieAuth. This filter is empty by default.

`pre_insert_micropub_post( $args )`

This filters the arguments sent to wp_insert_post just prior to its insertion. If the ID key is set, then this will short-circuit the insertion to allow for custom database coding.

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

Supports Stable Extensions to Micropub:

* [Post Status](https://indieweb.org/Micropub-extensions#Post_Status) - Either `published` or `draft`
* [Visibility](https://indieweb.org/Micropub-extensions#Visibility) - Either `public` or `private`.
* [Query for Category/Tag List](https://indieweb.org/Micropub-extensions#Query_for_Category.2FTag_List) - Supports querying for categories and tags.
* [Slug](https://indieweb.org/Micropub-extensions#Slug) - Custom slug.
* [Query for Post List](https://indieweb.org/Micropub-extensions#Query_for_Post_List) - Supports query for the last x number of posts.

Supports Proposed Extensions to Micropub:

* [Limit Parameter for Query](https://github.com/indieweb/micropub-extensions/issues/35) - Supports adding limit to any query designed to return a list of options to limit it to that number.
* [Offset Parameter for Query](https://github.com/indieweb/micropub-extensions/issues/36) - Supports adding offset to any query. Must be used with limit.
* [Filter Parameter for Query](https://github.com/indieweb/micropub-extensions/issues/34) - Supported for the Category/Tag List query.
* [Location Visiblity](https://github.com/indieweb/micropub-extensions/issues/16) - Either `public`, `private`, or `protected`
* [Query for Supported Queries](https://github.com/indieweb/micropub-extensions/issues/7) - Returns a list of query parameters the endpoint supports
* [Query for Supported Properties](https://github.com/indieweb/micropub-extensions/issues/8) - Returns a list of which supported experimental properties the endpoint supports so the client can choose to hide unsupported ones.
* [Discovery of Media Endpoint using Link Rel](https://github.com/indieweb/micropub-extensions/issues/15) - Adds a link header for the media endpoint
* [Supports extended GEO URIs](https://github.com/indieweb/micropub-extensions/issues/32) - Supports adding arbitrary parameters to the GEO URI. Micropub converts this into an mf2 object. Supported as built into the Indigenous client.
* [Supports deleting uploaded media](https://github.com/indieweb/micropub-extensions/issues/30) - Supports action=delete&url=url on the media endpoint to delete files.
* [Supports querying for media on the media endpoint](https://github.com/indieweb/micropub-extensions/issues/14) and [optional URL parameter for same]((https://github.com/indieweb/micropub-extensions/issues/37))
* [Supports filtering media queries by mime-type](https://github.com/indieweb/micropub-extensions/issues/45)
* [Return Visibility in q=config](https://github.com/indieweb/micropub-extensions/issues/8#issuecomment-536301952)

Deprecated Extensions still Supported:

* [Last Media Uploaded](https://github.com/indieweb/micropub-extensions/issues/10) - Supports querying for the last image uploaded ...set to within the last hour. This was superseded by supporting `q=source&limit=1` on the media endpoint.

Extensions Supported by Other Plugins:

* [Query for Location](https://github.com/indieweb/micropub-extensions/issues/6) - Suported by Simple Location if installed.

If an experimental property is not set to one of the noted options, the plugin will return HTTP 400 with body:

    {
      "error": "invalid_request",
    }

WordPress has a [whitelist of file extensions that it allows in uploads](https://codex.wordpress.org/Uploading_Files#About_Uploading_Files_on_Dashboard). If you upload a file in a Micropub extension that doesn't have an allowed extension, the plugin will return HTTP 400 with body:

    {
      "error": "invalid_request",
      "error_description": "Sorry, this file is not permitted for security reasons."
    }


== Authentication and authorization ==

For reasons of security it is recommended that you only use this plugin on sites that implement HTTPS. Authentication is not built into this plugin.

In order to use this, the IndieAuth plugin is required. Other plugins may be written in future as alternatives and will be noted if they exist.

== Installation ==

Install the IndieAuth plugin from the WordPress plugin directory, then install this plugin. No setup needed.

== Configuration Options ==

These configuration options can be enabled by adding them to your wp-config.php

* `define('MICROPUB_NAMESPACE', 'micropub/1.0' )` - By default the namespace for micropub is micropub/1.0. This would allow you to change this for your endpoint
* `define('MICROPUB_DISABLE_NAG', 1 )` - Disable notices for insecure sites
* `define('MICROPUB_DRAFT_MODE', 1 )` - Override default post status and set to draft for debugging purposes.

== Frequently Asked Questions ==

= I am experiencing issues in logging in with IndieAuth. =

There are a series of troubleshooting steps in the IndieAuth plugin for this. The most common problem involves the token not being passed due the configuration of your hosting provider.

== Upgrade Notice ==


= Version 2.4.0 =

The following option and setting was removed from the plugin as the IndieAuth plugin now allows the creation of a draft token to satisfy this need. Being as this was the only setting
the entire settings page was removed.

* `micropub_default_post_status` - if set, Micropub posts will be set to this status by default( publish, draft, or private ).

The older MICROPUB_DRAFT_MODE config override remains in place for now.

Static rendering for newer posts has been replaced by dynamic render. This means that if you disable this plugin, it will not render the microformats on newer posts. This is using the same code used for static 
rendering but future enhancements are planned.

= Version 2.2.3 =
The Micropub plugin will no longer store published, updated, summary, or name options. These will be derived from the WordPress post properties they are mapped to and returned on query.

= Version 2.2.0 =

The Micropub plugin will no longer function without the IndieAuth plugin installed.

= Version 2.0.0 =

This version changes the Micropub endpoint URL as it now uses the REST API. You may have to update any third-parties that have cached this info.

== Screenshots ==

None.

== Development ==

The canonical repo is http://github.com/indieweb/wordpress-micropub . Feedback and pull requests are welcome!

To add a new release to the WordPress plugin directory, tag it with the version number and push the tag. It will automatically deploy.

To set up your local environment to run the unit tests and set up PHPCodesniffer to test adherence to [WordPress Coding Standards](https://make.wordpress.org/core/handbook/coding-standards/php/) and [PHP Compatibility](https://github.com/wimg/PHPCompatibility):

1. Install [Composer](https://getcomposer.org). Composer is only used for development and is not required to run the plugin.
1. Run `composer install` which will install PHP Codesniffer, PHPUnit, the standards required, and all dependencies.

To configure PHPUnit

1. Install and start MySQL. (You may already have it.)
1. Run `./bin/install-wp-tests.sh wordpress_micropub_test root '' localhost` to download WordPress and [its unit test library](https://develop.svn.wordpress.org/trunk/tests/phpunit/), into your systems tmp directory by default, and create a MySQL db to test against. [Background here](http://wp-cli.org/docs/plugin-unit-tests/). Feel free to use a MySQL user other than `root`. You can set the `WP_CORE_DIR` and `WP_TESTS_DIR` environment variables to change where WordPress and its test library are installed. For example, I put them both in the repo dir.
1. Open `wordpress-tests-lib/wp-tests-config.php` and add a slash to the end of the ABSPATH value. No clue why it leaves off the slash; it doesn't work without it.
1. Run `phpunit` in the repo root dir. If you set `WP_CORE_DIR` and `WP_TESTS_DIR` above, you'll need to set them for this too. You should see output like this:


    Installing...
    ...
    1 / 1 (100%)
    Time: 703 ms, Memory: 33.75Mb
    OK (1 test, 3 assertions)

To set up PHPCodesniffer to test adherence to [WordPress Coding Standards](https://make.wordpress.org/core/handbook/coding-standards/php/) and [PHP 5.6 Compatibility](https://github.com/wimg/PHPCompatibility):

1. To list coding standard issues in a file, run `composer phpcs`
1. If you want to try to automatically fix issues, run `composer phpcbf``.

To automatically convert the readme.txt file to readme.md, you may, if you have installed composer as noted in the previous section, enter `composer update-readme` to have the .txt file converted
into markdown and saved to readme.md.

== Changelog ==

= 2.4.0 (2024-06-13) =
* Remove sole setting as no longer needed(see upgrade notice)
* Remove settings page as no more settings.
* Bump minimum PHP version to PHP7.2
* Require IndieAuth plugin as a dependency
* Switch to dynamic from static rendering on posts...markup will no longer be placed inside the content block but dynamically added.

= 2.3.3 (2023-03-10) =
* Stop including visible text in reply contexts since they go inside since they go inside e-content, which webmention recipients use as the reply text.
* Fix undeclared variables

= 2.3.2 (2022-06-22 ) =
* Update readme
* Fix client name bug

= 2.3.1 (2021-12-25 ) =
* Made one little mistake.

= 2.3.0 (2021-12-25 ) =
* Sanitize media endpoint queries
* Add mime_type filter for media queries
* Update media endpoint query response
* Set client application taxonomy id if present
* Add display functions for showing the client or returning the client data which will work with or without the client application taxonomy added in Indieauth
* Normalize JSON inputs to ensure no errors
* Add support for Visibility config return https://github.com/indieweb/micropub-extensions/issues/8#issuecomment-536301952
* Sets `_edit_last` property when a post is updated.

= 2.2.5 (2021-09-22 ) =
* Update readme links
* Add filter to allow custom database insert.
* Latitude and longitude properties are now converted into a location property.
* Introduce new function to simplify returning a properly set datetime with timezone
* Media Endpoint now supports a delete action.
* New query unit test revealed bug in new q=source&url= query previously introduced.
* Update media response to now just include published, updated, created, and mime_type for now.

= 2.2.4 (2021-05-06 ) =
* Add published date to return from q=source on media endpoint

= 2.2.3 (2020-09-09 ) =
* Deduplicated endpoint test code from endpoint and media endpoint classes.
* Removed error suppression revealing several notices that had been hidden. Fixed warning notices.
* Abstract request for scope and response into functions to avoid calling the actual filter as this may be deprecated in future.
* Switch check in permissions to whether a user was logged in.
* Published, updated, name, and summary properties are no longer stored in post meta. When queried, they will be pulled from the equivalent WordPress properties. Content should be as well, however as content in the post includes rendered microformats we need to store the pure version. Might address this in a future version.
* As timezone is not stored in the WordPress timestamp, store the timezone offset for the post in meta instead.
* Sideload and set featured images if featured property is set.

= 2.2.2 (2020-08-23 ) =
* Fixed and updated testing environment
* Fixed failing tests as a result of update to testing environment
* Change return response code based on spec update from 401 to 403

= 2.2.1 (2020-07-31 ) =
* Change category query parameter from search to filter per decision at Micropub Popup Session
* Fix permissions for Media Endpoint to match Endpoint
* For source query on both media and micropub endpoint support offset parameter

= 2.2.0 (2020-07-25 ) =
* Deprecate MICROPUB_LOCAL_AUTH, MICROPUB_AUTHENTICATION_ENDPOINT and MICROPUB_TOKEN_ENDPOINT constants.
* Remove IndieAuth Client code, will now require the IndieAuth or other plugin that does not yet exist.

= 2.1.0 (2020-02-06 ) =
* Fix bug where timezone meta key was always set to website timezone instead of provided one
* Fix issue where title and caption were not being set for images by adopting code from WordPress core
* Remove post scope
* Add support for draft scope
* Improve permission handling by ensuring someone cannot edit another users posts unless they have that capability
* Fix issue with date rendering in events
* return URL in response to creating a post
* introduce two new filters to filter the post type and the taxonomy input for posts

= 2.0.11 (2019-05-25) =
* Fix issues with empty variables
* Update last media query to limit itself to last hour
* Undelete is now part of delete scope as there is no undelete scope
* Address issue where properties in upload are single property arrays

= 2.0.10 (2019-04-13) =
* Fix issue with media not being attached to post

= 2.0.9 (2019-03-25) =
* Add filter `micropub_suggest_title` and related function to generate slugs
* Map updated property to WordPress modified property
* Add meta key to micropub uploaded media so it can be queried
* Add last and source queries for media endpoint
* Set up return function for media that returns attachment metadata for now

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
* Saves access token response in a post meta field `micropub_auth_response`.
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
