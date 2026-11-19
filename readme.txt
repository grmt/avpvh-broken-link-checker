=== AV-PvH Broken Link Checker ===
Contributors: grmt
Tags: links, broken links, link checker
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Checks posts, pages, comments and custom fields for broken links and missing images, with per-host request rules.

== Description ==

Based on the local engine of Broken Link Checker 2.4.15.1 by WPMU DEV and Janis Elsts, without the WPMU DEV cloud service.

* Per-host rules (Settings > Advanced): user agent, minimum time between requests, HTTP codes that count as working, or skip the host.
* A desktop browser user agent by default, as many sites refuse link checkers.
* Exclusion list with plain text, `*` wildcards and `/regular expressions/`.
* Bulk find and replace in link URLs.

== Changelog ==

= 1.0.0 =
* First release, based on Broken Link Checker 2.4.15.1.
