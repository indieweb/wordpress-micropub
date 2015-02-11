# wordpress-micropub

And so it begins...

This project is placed in the public domain. You may also use it under the
[CC0 license](http://creativecommons.org/publicdomain/zero/1.0/).

Micropub spec: http://indiewebcamp.com/micropub

Current plan: start with an existing API plugin, hopefully steal its token
handling outright, and tweak the rest as little as possible, ideally just the
API surface area, and reuse the WP interaction as much as possible.

Candidates:
* https://github.com/WP-API/WP-API
* https://wordpress.org/plugins/json-api/developers/ (last updated 6/2013)
* https://wordpress.org/plugins/json-rest-api/
* http://thermal-api.com/

Little post and example of setting up an API endpoint in general:
http://coderrr.com/create-an-api-endpoint-in-wordpress/
