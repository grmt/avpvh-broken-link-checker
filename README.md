# AV-PvH Link Checker

WordPress plugin that checks posts, pages, comments and custom fields for broken links. Based on the local engine of [Broken Link Checker](https://wordpress.org/plugins/broken-link-checker/) 2.4.15.1 (GPL-2.0-or-later), without the WPMU DEV cloud service, with every global name prefixed `avpvh_lnkc_`.

Additions:

- **Host rules** (`includes/class-host-rules.php`, Settings > Advanced): per host and its subdomains a user agent, a minimum interval between requests, HTTP codes that count as working, or skip.
- **Exclusion list patterns** (`includes/class-url-pattern.php`): plain text, `*` wildcards, `/regex/flags`.
- **URL find and replace** (`includes/class-url-replacer.php`) for the bulk action.

Requires PHP 8.4 and WordPress 7.1.

## Tests

```sh
php tests/test-rules-and-patterns.php
```

PHPCS, PHPStan and Plugin Check run in CI (`.github/workflows/CI.yml`, with the shared checks from `grmt/wp-plugin-ci`).
