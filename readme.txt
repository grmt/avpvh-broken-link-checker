=== AV-PvH Broken Link Checker ===
Contributors: grmt
Tags: links, broken links, link checker
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Checks posts, pages, comments and custom fields for broken links and missing images, with per-host request rules.

== Description ==

Based on the local engine of Broken Link Checker 2.4.15.1 by WPMU DEV and Janis Elsts, without the WPMU DEV cloud service.

* Per-host rules (Settings > Advanced): user agent, minimum time between requests, HTTP codes that count as working, or skip the host.
* A desktop browser user agent by default, as many sites refuse link checkers.
* Exclusion list with plain text, `*` wildcards and `/regular expressions/`.
* Bulk find and replace in link URLs.
* Links to this site that only members may open (private posts, members-only AJAX endpoints, files behind single sign-on) are looked up in WordPress instead of being reported as broken. Plugins can decide for their own URLs with the `avpvh_brkn_local_link_status` filter.

== Changelog ==

= 1.2.0 =
* Hardening of the link checker's requests: certificates are verified (a link with a certificate problem shows as a warning), responses are size-limited, and the checked address is used for the connection.
* Wayback Machine suggestions are fetched as plain JSON, never for links to this site.
* The local lookup only looks in uploads and skips code, logs and backups.
* Safer handling in the admin screens (bulk edit, notices, link actions, error messages) and in the log file setup.
* Removed the RapidShare module (the service closed in 2015); MediaFire and YouTube checks use HTTPS and the safe HTTP API.

= 1.1.1 =
* No database transaction around link checks any more. It kept the checked links locked for minutes, so other writes to the links table failed with "Lock wait timeout".

= 1.1.0 =
* Look up links to this site in WordPress when an anonymous request is refused (400, 401, 403 or 404); new filter `avpvh_brkn_local_link_status`.

= 1.0.0 =
* First release, based on Broken Link Checker 2.4.15.1.
