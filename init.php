<?php

// To prevent conflicts, only one version of the plugin can be activated at any given time.
if ( defined( 'AVPVH_LNKC_ACTIVE' ) ) {
	trigger_error(
		'Another version of Broken Link Checker is already active. Please deactivate it before activating this one.',
		E_USER_ERROR
	);
} else {

	define( 'AVPVH_LNKC_ACTIVE', true );

	// Fail fast if the WP version is unsupported. The $wp_version variable may be obfuscated by other
	// plugins, so use function detection to determine the version. get_post_stati was introduced in WP 3.0.0
	if ( ! function_exists( 'get_post_stati' ) ) {
		trigger_error(
			'This version of Broken Link Checker requires WordPress 3.0 or later!',
			E_USER_ERROR
		);
	}

	/***********************************************
					Debugging stuff
	*/
	// define('AVPVH_LNKC_DEBUG', true);

	/***********************************************
					Constants
	*/

	/**
	* For performance, some internal APIs used for retrieving multiple links, instances or containers
	* can take an optional "$purpose" argument. Those APIs will try to use this argument to pre-load
	* any DB data required for the specified purpose ahead of time.

	* For example, if you're loading a bunch of link containers for the purposes of parsing them and
	* thus set $purpose to AVPVH_LNKC_FOR_PARSING, the relevant container managers will (if applicable) precache
	* the parse-able fields in each returned container object. Still, setting $purpose to any particular
	* value does not *guarantee* any data will be preloaded - it's only a suggestion that it should.

	* The currently supported values for the $purpose argument are :
	*/
	define( 'AVPVH_LNKC_FOR_EDITING', 'edit' );
	define( 'AVPVH_LNKC_FOR_PARSING', 'parse' );
	define( 'AVPVH_LNKC_FOR_DISPLAY', 'display' );
	define( 'AVPVH_LNKC_DATABASE_VERSION', 18 );

	/***********************************************
					Configuration
	*/
	// Create and register configuration managers.
if ( ! function_exists( 'avpvh_lnkc_sanitize_custom_css' ) ) {
	/**
	 * Reduce a stored custom CSS option to something that cannot leave its style element.
	 *
	 * The option is only writable by a user with unfiltered_html, so this is not the
	 * boundary that stops an attack - it is the guard for a value stored before that
	 * gate existed, or written by some other route.
	 *
	 * @param string $css The stored CSS.
	 * @return string
	 */
	function avpvh_lnkc_sanitize_custom_css( $css ) {
		$css = (string) $css;

		// A style element ends at the first "</style", however it is spelled.
		$css = preg_replace( '#<\s*/\s*style#i', '', $css );

		// Legitimate CSS carries no tags, so anything tag-shaped goes.
		return trim( wp_strip_all_tags( $css ) );
	}
}

	require AVPVH_LNKC_DIRECTORY . '/config.php';

	/***********************************************
					Logging
	*/

	avpvh_lnkc_init_configuration();
	$avpvh_lnkc_config_manager = avpvh_lnkc_get_configuration();

	include AVPVH_LNKC_DIRECTORY . '/includes/logger.php';

	global $blclog;

	/*
	 * A path stored by an earlier version may still point outside the plugin's log
	 * directory, so it is reduced here as well and not only when the setting is saved.
	 */
	$avpvh_lnkc_log_file = $avpvh_lnkc_config_manager->get( 'logging_enabled', false )
		? avpvh_lnkc_sanitize_log_path( $avpvh_lnkc_config_manager->get( 'log_file' ) )
		: '';

	if ( '' !== $avpvh_lnkc_log_file && avpvh_lnkc_log_path_is_safe( $avpvh_lnkc_log_file ) && is_writable( $avpvh_lnkc_log_file ) ) {
		$blclog = new Avpvh_Lnkc_File_Logger( $avpvh_lnkc_log_file );
	} else {
		$blclog = new Avpvh_Lnkc_Dummy_Logger();
	}

	/*
	if ( defined('AVPVH_LNKC_DEBUG') && constant('AVPVH_LNKC_DEBUG') ){
		//Load FirePHP for debug logging
		if ( !class_exists('FB') && file_exists(AVPVH_LNKC_DIRECTORY . '/FirePHPCore/fb.php4') ) {
			require_once AVPVH_LNKC_DIRECTORY . '/FirePHPCore/fb.php4';
		}
		//FB::setEnabled(false);
	}
	//to comment out all calls : (^[^\/]*)(FB::)  ->  $1\/\/$2
	//to uncomment : \/\/(\s*FB::)  ->   $1
	//*/

	/***********************************************
					Global functions
	 ************************************************/

	/**
	 * Notify the link checker that there are unsynched items
	 * that might contain links (e.g. a new or edited post).
	 *
	 * @return void
	 */
	function avpvh_lnkc_got_unsynched_items() {
		$conf = avpvh_lnkc_get_configuration();

		if ( ! $conf->options['need_resynch'] ) {
			$conf->options['need_resynch'] = true;
			$conf->save_options();
		}
	}

	/**
	 * (Re)create synchronization records for all containers and mark them all as unparsed.
	 *
	 * @param bool $forced If true, the plugin will recreate all synch. records from scratch.
	 * @return void
	 */
	function avpvh_lnkc_resynch( $forced = false ) {
		global $wpdb, $blclog;

		if ( $forced ) {
			$blclog->info( '... Forced resynchronization initiated' );

			// Drop all synchronization records
			$wpdb->query( "TRUNCATE {$wpdb->prefix}avpvh_lnkc_synch" );
		} else {
			$blclog->info( '... Resynchronization initiated' );
		}

		// Remove invalid DB entries
		avpvh_lnkc_cleanup_database();

		// (Re)create and update synch. records for all container types.
		$blclog->info( '... (Re)creating container records' );
		Avpvh_Lnkc_Container_Helper::resynch( $forced );

		$blclog->info( '... Setting resync. flags' );
		avpvh_lnkc_got_unsynched_items();

		// All done.
		$blclog->info( 'Database resynchronization complete.' );
	}

	/**
	 * Delete synch. records, instances and links that refer to missing or invalid items.
	 *
	 * @return void
	 */
	function avpvh_lnkc_cleanup_database() {
		global $blclog;

		// Delete synch. records for container types that don't exist
		$blclog->info( '... Deleting invalid container records' );
		Avpvh_Lnkc_Container_Helper::cleanup_containers();

		// Delete invalid instances
		$blclog->info( '... Deleting invalid link instances' );
		avpvh_lnkc_cleanup_instances();

		// Delete orphaned links
		$blclog->info( '... Deleting orphaned links' );
		avpvh_lnkc_cleanup_links();
	}

	/***********************************************
					Utility hooks
	 ************************************************/

	/**
	 * Adds the following cron schedules:
	 * - 10min: every 10 minutes.
	 * - weekly: once per week.
	 * - bimonthly : twice per month.
	 *
	 * @param array $schedules Existing Cron schedules.
	 * @return array
	 */
	function avpvh_lnkc_cron_schedules( $schedules ) {

		if ( ! isset( $schedules['10min'] ) ) {
			$schedules['10min'] = array(
				'interval' => 600,
				'display'  => __( 'Every 10 minutes' ),
			);
		}

		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => 604800, // 7 days
				'display'  => __( 'Once Weekly' ),
			);
		}
		if ( ! isset( $schedules['bimonthly'] ) ) {
			$schedules['bimonthly'] = array(
				'interval' => 15 * 24 * 2600, // 15 days
				'display'  => __( 'Twice a Month' ),
			);
		}

		return $schedules;
	}
	add_filter( 'cron_schedules', 'avpvh_lnkc_cron_schedules' );

	/***********************************************
					Main functionality
	*/

	require AVPVH_LNKC_DIRECTORY . '/includes/activation.php';

	// Load the plugin if installed successfully
	if ( $avpvh_lnkc_config_manager->options['installation_complete'] ) {
		function avpvh_lnkc_init() {
			global $avpvh_lnkc_module_manager, $ws_link_checker;
			$avpvh_lnkc_config_manager = avpvh_lnkc_get_configuration();

			static $init_done = false;
			if ( $init_done ) {
				return;
			}
			$init_done = true;

			// Ensure the database is up to date
			if ( AVPVH_LNKC_DATABASE_VERSION !== $avpvh_lnkc_config_manager->options['current_db_version'] ) {
				require_once AVPVH_LNKC_DIRECTORY . '/includes/admin/db-upgrade.php';

				// Throttle upgrade attempts (on both single-site and multisite) so that a burst of
				// concurrent requests can't all try to run the schema diff/ALTER queries at once.
				// Without this, every request that sees a stale current_db_version re-runs the
				// upgrade, and concurrent identical ALTER TABLE statements pile up waiting on the
				// same metadata lock.
				$last_upgrade_time = intval( get_site_option( 'avpvh_lnkc_last_db_upgrade', 0 ) );
				$last_upgrade_diff = time() - $last_upgrade_time;

				if ( apply_filters( 'avpvh_lnkc_db_upgrade_cooldown_sec', 30 ) <= $last_upgrade_diff ) {
					update_site_option( 'avpvh_lnkc_last_db_upgrade', time() );

					// Also updates the DB ver. in options['current_db_version'].
					Avpvh_Lnkc_Database_Upgrader::upgrade_database();
				}
			}

			// Load the base classes and utilities
			require_once AVPVH_LNKC_DIRECTORY . '/includes/links.php';
			require_once AVPVH_LNKC_DIRECTORY . '/includes/link-query.php';
			require_once AVPVH_LNKC_DIRECTORY . '/includes/instances.php';
			require_once AVPVH_LNKC_DIRECTORY . '/includes/utility-class.php';

			// Load the module subsystem
			require_once AVPVH_LNKC_DIRECTORY . '/includes/modules.php';

			// Load the modules that want to be executed in all contexts
			if ( is_object( $avpvh_lnkc_module_manager ) && method_exists( $avpvh_lnkc_module_manager, 'load_modules' ) ) {
				$avpvh_lnkc_module_manager->load_modules();
			}

			if ( is_admin() || defined( 'DOING_CRON' ) ) {

				// It's an admin-side or Cron request. Load the core.
				require_once AVPVH_LNKC_DIRECTORY . '/core/core.php';
				$ws_link_checker = new Avpvh_Lnkc_Link_Checker( AVPVH_LNKC_PLUGIN_FILE, $avpvh_lnkc_config_manager );

			} else {

				// This is user-side request, so we don't need to load the core.
				// We might need to inject the CSS for removed links, though.
				if ( $avpvh_lnkc_config_manager->options['mark_removed_links'] && ! empty( $avpvh_lnkc_config_manager->options['removed_link_css'] ) ) {
					function avpvh_lnkc_print_removed_link_css() {
						$avpvh_lnkc_config_manager = avpvh_lnkc_get_configuration();
						echo '<style>', avpvh_lnkc_sanitize_custom_css( $avpvh_lnkc_config_manager->options['removed_link_css'] ), '</style>';
					}
					add_action( 'wp_head', 'avpvh_lnkc_print_removed_link_css' );
				}
			}
		}
		add_action( 'init', 'avpvh_lnkc_init', 2000 );
	} else {
		// Display installation errors (if any) on the Dashboard.
		function avpvh_lnkc_print_installation_errors() {
			global $wpdb;
			$avpvh_lnkc_config_manager = avpvh_lnkc_get_configuration();

			if ( $avpvh_lnkc_config_manager->options['installation_complete'] ) {
				return;
			}

			$messages = array(
				'<strong>' . __( 'Broken Link Checker installation failed. Try deactivating and then reactivating the plugin.', 'avpvh-link-checker' ) . '</strong>',
			);

			$missing_options = $avpvh_lnkc_config_manager->get_missing_options();
			if ( empty( $missing_options ) ) {
				$logger   = new Avpvh_Lnkc_Cached_Option_Logger( 'avpvh_lnkc_installation_log' );
				$messages = array_merge(
					$messages,
					array(
						'installation_complete = ' . ( isset( $avpvh_lnkc_config_manager->options['installation_complete'] ) ? intval( $avpvh_lnkc_config_manager->options['installation_complete'] ) : 'no value' ),
						'installation_flag_cleared_on = ' . $avpvh_lnkc_config_manager->options['installation_flag_cleared_on'],
						'installation_flag_set_on = ' . $avpvh_lnkc_config_manager->options['installation_flag_set_on'],
						'',
						'<em>Installation log follows :</em>',
					),
					$logger->get_messages()
				);
			} else {
				foreach ( $missing_options as $option_name ) {
					$messages[] = sprintf(
						'<strong>Failed to load plugin settings from the "%s" option.</strong>',
						$option_name
					);
					$messages[] = '';

					$serialized_config = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT `option_value` FROM `$wpdb->options` WHERE `option_name` = %s",
							$option_name
						)
					);

					if ( null === $serialized_config ) {
						$messages[] = "Option doesn't exist in the {$wpdb->options} table.";
					} else {
						$messages[] = "Option exists in the {$wpdb->options} table and has the following value:";
						$messages[] = '';
						$messages[] = '<textarea cols="120" rows="20">' . htmlentities( $serialized_config ) . '</textarea>';
					}
				}
			}

			echo '<div class="error"><p>', implode( "<br>\n", $messages ), '</p></div>';
		}
		add_action( 'admin_notices', 'avpvh_lnkc_print_installation_errors' );
	}
}
