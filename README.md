# AV-PvH Broken Link Checker

WordPress plugin that checks posts, pages, comments and custom fields for broken links. Based on the local engine of [Broken Link Checker](https://wordpress.org/plugins/broken-link-checker/) 2.4.15.1 (GPL-2.0-or-later), without the WPMU DEV cloud service, with every global name prefixed `avpvh_brkn_`.

Additions:

- **Host rules** (`includes/class-host-rules.php`, Settings > Advanced): per host and its subdomains a user agent, a minimum interval between requests, HTTP codes that count as working, or skip.
- **Exclusion list patterns** (`includes/class-url-pattern.php`): plain text, `*` wildcards, `/regex/flags`.
- **URL find and replace** (`includes/class-url-replacer.php`) for the bulk action.
- **Local lookup of members-only links** (`includes/class-local-links.php`), see below.

Requires PHP 8.4 and WordPress 7.1.

## Links that only members can open

The checker requests every link as an anonymous visitor. A link to this site that needs a login (a private post, an AJAX endpoint without a `nopriv` handler, a file behind single sign-on) then gets 400, 401, 403 or 404, although it works for the members who see it.

For such a link (same host as the home or site URL, with or without `www.`) the plugin looks the target up in WordPress, in this order:

1. the filter `avpvh_brkn_local_link_status` (below);
2. `admin-ajax.php?action=X`: OK when `wp_ajax_X` or `wp_ajax_nopriv_X` is registered. This only shows that the endpoint exists, not that the item in the query string does. Plugins that register their AJAX handlers only on admin requests aren't seen here, so the HTTP result stays;
3. a file under `wp-content` (also `uploads/private/`): OK when it exists, broken when it doesn't;
4. a post, page or other post type (`url_to_postid()`), whatever its status except trash.

Only a positive find changes the result. The link then shows **Verified locally**, and its details say what was found. A link to this site that WordPress doesn't know stays broken.

### For plugins with their own URLs

A plugin that serves members-only URLs, such as a photo proxy, knows best whether the item a URL names exists. It can tell the checker:

```php
add_filter( 'avpvh_brkn_local_link_status', function ( $status, $url, $parts ) {
	$args = $parts['query_args'] ?? array();
	if ( null !== $status || ( $args['action'] ?? '' ) !== 'my_photo' ) {
		return $status; // Not ours: leave it to another filter or the built-in lookups.
	}

	$file = my_photo_file( (int) ( $args['id'] ?? 0 ) ); // The same lookup the endpoint uses.

	return array(
		'ok'   => (bool) $file,
		'note' => $file ? 'photo exists: ' . basename( $file ) : 'photo not found',
	);
}, 10, 3 );
```

Return `true`/`false`, or `array( 'ok' => bool, 'note' => string )`, or `null` to pass. `$parts` is `wp_parse_url()` of the URL plus `query_args`. The filter runs only for links to this site that an anonymous request couldn't open, during the cron check (so `is_admin()` is false). Use the same function as the endpoint, so the two can't drift apart. Without this plugin active the filter does nothing, so the hook needs no `class_exists()` check.

## Tests

```sh
php tests/test-rules-and-patterns.php
```

PHPCS, PHPStan and Plugin Check run in CI (`.github/workflows/CI.yml`, with the shared checks from `grmt/wp-plugin-ci`).
