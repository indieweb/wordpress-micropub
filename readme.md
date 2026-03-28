# Micropub

- Contributors: indieweb, snarfed, dshanske, pfefferle
- Tags: micropub, publish, indieweb, microformats
- Requires at least: 4.9.9
- Tested up to: 7.0
- Stable tag: 2.5.0
- Requires PHP: 7.2
- License: CC0
- License URI: http://creativecommons.org/publicdomain/zero/1.0/

Allows you to publish to your site using Micropub clients.

## Description

Micropub is an open API standard that is used to create posts on your site using third-party clients. Web apps and native apps (e.g. iPhone, Android) can use Micropub to post short notes, photos, events or other posts to your own site, similar to a Twitter client posting to Twitter.com. Requires the IndieAuth plugin for authentication.

Once you've installed and activated the plugin, try a client such as [Quill](http://quill.p3k.io/) to create a new post on your site. It walks you through the steps and helps you troubleshoot if you run into any problems. A list of known Micropub clients are available [here](https://indieweb.org/Micropub/Clients).

Supports the full [Micropub spec](https://micropub.spec.indieweb.org/).

As this allows the creation of posts without entering the WordPress admin, it is not subject to any Gutenberg compatibility concerns per se. Posts created will not have Gutenberg blocks as they were not created with Gutenberg, but otherwise there should be no issues at this time.

Available in the WordPress plugin directory at [wordpress.org/plugins/micropub](https://wordpress.org/plugins/micropub/).

## Frequently Asked Questions

### What is Micropub?

[Micropub](https://www.w3.org/TR/micropub/) is a W3C Recommendation that defines an open API standard for creating, updating, and deleting posts on your own website.

### I am experiencing issues in logging in with IndieAuth.

There are a series of troubleshooting steps in the IndieAuth plugin for this. The most common problem involves the token not being passed due the configuration of your hosting provider.

### Why do I need the IndieAuth plugin?

For reasons of security it is recommended that you only use this plugin on sites that implement HTTPS. Authentication is not built into this plugin. The IndieAuth plugin provides the authentication layer.

### What Micropub extensions are supported?

Supports Stable Extensions:

* [Post Status](https://indieweb.org/Micropub-extensions#Post_Status) - Either `published` or `draft`
* [Visibility](https://indieweb.org/Micropub-extensions#Visibility) - Either `public` or `private`
* [Query for Category/Tag List](https://indieweb.org/Micropub-extensions#Query_for_Category.2FTag_List)
* [Slug](https://indieweb.org/Micropub-extensions#Slug) - Custom slug
* [Query for Post List](https://indieweb.org/Micropub-extensions#Query_for_Post_List)

Supports Proposed Extensions:

* [Limit Parameter for Query](https://github.com/indieweb/micropub-extensions/issues/35)
* [Offset Parameter for Query](https://github.com/indieweb/micropub-extensions/issues/36)
* [Filter Parameter for Query](https://github.com/indieweb/micropub-extensions/issues/34)
* [Location Visibility](https://github.com/indieweb/micropub-extensions/issues/16)
* [Query for Supported Queries](https://github.com/indieweb/micropub-extensions/issues/7)
* [Query for Supported Properties](https://github.com/indieweb/micropub-extensions/issues/8)
* [Discovery of Media Endpoint using Link Rel](https://github.com/indieweb/micropub-extensions/issues/15)
* [Extended GEO URIs](https://github.com/indieweb/micropub-extensions/issues/32)
* [Deleting uploaded media](https://github.com/indieweb/micropub-extensions/issues/30)
* [Querying for media](https://github.com/indieweb/micropub-extensions/issues/14)
* [Filtering media by mime-type](https://github.com/indieweb/micropub-extensions/issues/45)
* [Return Visibility in q=config](https://github.com/indieweb/micropub-extensions/issues/8#issuecomment-536301952)

### What configuration options are available?

These configuration options can be enabled by adding them to your wp-config.php:

* `define('MICROPUB_NAMESPACE', 'micropub/1.0')` - Change the namespace for the micropub endpoint
* `define('MICROPUB_DRAFT_MODE', 1)` - Override default post status and set to draft for debugging

## Changelog

Project and support maintained on GitHub at [indieweb/wordpress-micropub](https://github.com/indieweb/wordpress-micropub).

### 2.5.0

* Add namespaces and PSR-4 autoloader
* Refactor REST API to use WP_REST_Controller pattern
* Fix q=source to return valid MF2 JSON for all properties
* Fix REST response headers causing PHP warning
* Replace admin notice with Site Health test for HTTPS check
* Modernize build setup and workflows
* Add PHPDoc documentation for all hooks and filters
* Tested up to WordPress 7.0

### 2.4.0

* Remove sole setting as no longer needed (see upgrade notice)
* Remove settings page as no more settings
* Bump minimum PHP version to PHP 7.2
* Require IndieAuth plugin as a dependency
* Switch to dynamic from static rendering on posts

### 2.3.3

* Stop including visible text in reply contexts since they go inside e-content
* Fix undeclared variables

### 2.3.2

* Update readme
* Fix client name bug

### 2.3.1

* Made one little mistake

### 2.3.0

* Sanitize media endpoint queries
* Add mime_type filter for media queries
* Update media endpoint query response
* Set client application taxonomy id if present
* Add display functions for showing the client or returning the client data
* Normalize JSON inputs to ensure no errors
* Add support for Visibility config return
* Sets `_edit_last` property when a post is updated

### 2.2.5

* Update readme links
* Add filter to allow custom database insert
* Latitude and longitude properties are now converted into a location property
* Introduce new function to simplify returning a properly set datetime with timezone
* Media Endpoint now supports a delete action
* New query unit test revealed bug in new q=source&url= query previously introduced
* Update media response to include published, updated, created, and mime_type

### 2.2.4

* Add published date to return from q=source on media endpoint

### 2.2.3

* Deduplicated endpoint test code from endpoint and media endpoint classes
* Removed error suppression revealing several notices that had been hidden
* Abstract request for scope and response into functions
* Switch check in permissions to whether a user was logged in
* Published, updated, name, and summary properties are no longer stored in post meta
* Store the timezone offset for the post in meta

### 2.2.2

* Fixed and updated testing environment
* Fixed failing tests as a result of update to testing environment
* Change return response code based on spec update from 401 to 403

### 2.2.1

* Change category query parameter from search to filter
* Fix permissions for Media Endpoint to match Endpoint
* For source query on both media and micropub endpoint support offset parameter

### 2.2.0

* Deprecate MICROPUB_LOCAL_AUTH, MICROPUB_AUTHENTICATION_ENDPOINT and MICROPUB_TOKEN_ENDPOINT constants
* Remove IndieAuth Client code, will now require the IndieAuth plugin

### 2.1.0

* Fix bug where timezone meta key was always set to website timezone
* Fix issue where title and caption were not being set for images
* Remove post scope
* Add support for draft scope
* Improve permission handling
* Fix issue with date rendering in events
* Return URL in response to creating a post
* Introduce new filters for post type and taxonomy input

### 2.0.0

* Rewrite using REST API
* Add Media Endpoint
* Improve error handling
* Ensure compliance with Micropub spec

## Installation

Follow the normal instructions for [installing WordPress plugins](https://codex.wordpress.org/Managing_Plugins#Installing_Plugins).

### Automatic Plugin Installation

1. Go to Plugins > Add New
2. Search for "micropub"
3. Click Install Now, then Activate

### Manual Plugin Installation

1. Download the plugin from [GitHub](https://github.com/indieweb/wordpress-micropub/releases) or [WordPress.org](https://wordpress.org/plugins/micropub/)
2. Upload to `wp-content/plugins/`
3. Activate the plugin

## Filters and Hooks

### Filters

`before_micropub( $input )`
Called before handling a Micropub request. Returns `$input`, possibly modified.

`micropub_post_content( $post_content, $input )`
Called during the handling of a Micropub request. Returns `$post_content`, possibly modified.

`micropub_post_type( $post_type = 'post', $input )`
Called during the creation of a Micropub post. Defaults to post but allows setting a custom post type.

`micropub_tax_input( $tax_input, $input )`
Called during the creation of a Micropub post. Allows setting custom taxonomy.

`micropub_syndicate-to( $synd_urls, $user_id, $input )`
Called to generate the list of `syndicate-to` targets.

`micropub_query( $resp, $input )`
Allows you to replace a query response with your own customized version.

`micropub_suggest_title( $mf2 )`
Allows a suggested title to be generated for the post slug.

`pre_insert_micropub_post( $args )`
Filters the arguments sent to wp_insert_post just prior to insertion.

### Hooks

`after_micropub( $input, $wp_args = null )`
Called after handling a Micropub request. Not called if the request fails.

`micropub_syndication( $ID, $syndicate_to )`
Called only if there are syndication targets for the post.

## Upgrade Notice

### 2.4.0

The following option and setting was removed from the plugin as the IndieAuth plugin now allows the creation of a draft token. The MICROPUB_DRAFT_MODE config override remains in place.

Static rendering for newer posts has been replaced by dynamic render.

### 2.2.0

The Micropub plugin will no longer function without the IndieAuth plugin installed.

### 2.0.0

This version changes the Micropub endpoint URL as it now uses the REST API. You may have to update any third-parties that have cached this info.
