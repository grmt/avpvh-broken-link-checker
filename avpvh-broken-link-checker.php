<?php
/**
 * Plugin Name: AV-PvH Broken Link Checker
 * Plugin URI:  https://github.com/grmt/avpvh-broken-link-checker
 * Description: Checks posts, pages, comments and custom fields for broken links and missing images, with per-host request rules, scheduled reports and bulk link actions.
 * Version:     1.1.1
 * Author:      grmt
 * Author URI:  https://github.com/grmt/avpvh-broken-link-checker
 * Text Domain: avpvh-broken-link-checker
 * Domain Path: /languages
 * Requires at least: 7.1
 * Requires PHP: 8.4
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:  https://github.com/grmt/avpvh-broken-link-checker
 *
 * Based on the local engine of Broken Link Checker 2.4.15.1 by WPMU DEV and
 * Janis Elsts (GPL-2.0-or-later).
 *
 * @package Avpvh_Broken_Link_Checker
 */

defined( 'ABSPATH' ) || exit;

define( 'AVPVH_BRKN_DIRECTORY', __DIR__ );
define( 'AVPVH_BRKN_PLUGIN_FILE', __FILE__ );

add_action(
	'plugins_loaded',
	static function () {
		require __DIR__ . '/init.php';
	},
	11
);
