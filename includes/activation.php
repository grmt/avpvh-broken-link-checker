<?php
/**
 * Plugin activation script
 *
 * @package Avpvh_Link_Checker
 */

$migrated = avpvh_lnkc_maybe_migrate_configuration();

if ( get_option( 'avpvh_lnkc_activation_enabled' ) ) {
	return;
}

$avpvh_lnkc_config_manager = avpvh_lnkc_get_configuration();
if ( ! $migrated ) {
	// Load existing options from the database if migration did not occur.
	$avpvh_lnkc_config_manager->load_options();
	// Ensure default options are saved for any managers that have not loaded options yet.
	$avpvh_lnkc_config_manager->save_defaults();
}

global $blclog, $wpdb;
$queryCnt = $wpdb->num_queries;

require_once AVPVH_LNKC_DIRECTORY . '/includes/utility-class.php';

// Completing the installation/upgrade is required for the plugin to work, so make sure.
// the script doesn't get aborted by (for example) the browser timing out.
// set_time_limit( 300 );  //5 minutes should be plenty, anything more would probably indicate an infinite loop or a deadlock.
if ( Avpvh_Lnkc_Utility::is_host_wp_engine() || Avpvh_Lnkc_Utility::is_host_flywheel() ) {
	set_time_limit( 60 );
} else {
	set_time_limit( 300 );
}

ignore_user_abort( true );

//Log installation progress to a DB option
$blclog = new Avpvh_Lnkc_Cached_Option_Logger( 'avpvh_lnkc_installation_log' );
register_shutdown_function( array( &$blclog, 'save' ) ); //Make sure the log is saved even if the plugin crashes

$blclog->clear();
$blclog->info( sprintf( 'Plugin activated at %s.', date_i18n( 'Y-m-d H:i:s' ) ) );
$activation_start = microtime( true );

//Reset the "installation_complete" flag
$avpvh_lnkc_config_manager->options['installation_complete']        = false;
$avpvh_lnkc_config_manager->options['installation_flag_cleared_on'] = date( 'c' ) . ' (' . microtime( true ) . ')'; //phpcs:ignore
//Note the time of the first installation (not very accurate, but still useful)
if ( empty( $avpvh_lnkc_config_manager->options['first_installation_timestamp'] ) ) {
	$avpvh_lnkc_config_manager->options['first_installation_timestamp'] = time();

	// Assume this is a new install if there's no first installation timestamp
	// We don't need to upgrade the database in this case. Only configuration setup.

	//Turn off load limiting if it's not available on this server.
	$blclog->info( 'Updating server load limit settings...' );
	$load = Avpvh_Lnkc_Utility::get_server_load();
	if ( empty( $load ) ) {
		// Disable load limit if we can't retrieve the current load average
		$avpvh_lnkc_config_manager->options['enable_load_limit'] = false;
		$blclog->info( 'Disable load limit. Cannot retrieve current load average.' );
	} elseif ( $avpvh_lnkc_config_manager->options['enable_load_limit'] && ! isset( $avpvh_lnkc_config_manager->options['server_load_limit'] ) ) {
		$fifteen_minutes    = floatval( end( $load ) );
		$default_load_limit = round( max( min( $fifteen_minutes * 2, $fifteen_minutes + 2 ), 4 ) );
		// Set the server load limit to the max possible limit based on current load
		$avpvh_lnkc_config_manager->options['server_load_limit'] = $default_load_limit;

		$blclog->info(
			sprintf(
				'Set server load limit to %.2f. Current load average is %.2f',
				$default_load_limit,
				$fifteen_minutes
			)
		);
	}

	$blclog->info( 'Completing installation...' );
	$avpvh_lnkc_config_manager->options['installation_complete']    = true;
	$avpvh_lnkc_config_manager->options['installation_flag_set_on'] = date( 'c' ) . ' (' . microtime( true ) . ')'; //phpcs:ignore
	if ( $avpvh_lnkc_config_manager->save_options() ) {
		$blclog->info( 'Configuration saved.' );
	} else {
		$blclog->error( 'Error saving plugin configuration!' );
	};

	$blclog->save();

	update_option( 'avpvh_lnkc_activation_enabled', true );
	return;
}

$avpvh_lnkc_config_manager->save_options();
$blclog->info( 'Installation/update begins.' );

//Load the base classes and utilities
require_once AVPVH_LNKC_DIRECTORY . '/includes/links.php';
require_once AVPVH_LNKC_DIRECTORY . '/includes/link-query.php';
require_once AVPVH_LNKC_DIRECTORY . '/includes/instances.php';
//require_once AVPVH_LNKC_DIRECTORY . '/includes/utility-class.php';

//Load the module subsystem
require_once AVPVH_LNKC_DIRECTORY . '/includes/modules.php';
$moduleManager = Avpvh_Lnkc_Module_Manager::getInstance();

//If upgrading, activate/deactivate custom field and comment containers based on old ver. settings
if ( isset( $avpvh_lnkc_config_manager->options['check_comment_links'] ) ) {
	if ( ! $avpvh_lnkc_config_manager->options['check_comment_links'] ) {
		$moduleManager->deactivate( 'comment' );
	}
	unset( $avpvh_lnkc_config_manager->options['check_comment_links'] );
}
if ( empty( $avpvh_lnkc_config_manager->options['custom_fields'] ) ) {
	$moduleManager->deactivate( 'custom_field' );
}
if ( empty( $avpvh_lnkc_config_manager->options['acf_fields'] ) ) {
	$moduleManager->deactivate( 'acf_field' );
}

//Prepare the database.
$blclog->info( 'Upgrading the database...' );
$upgrade_start = microtime( true );
require_once AVPVH_LNKC_DIRECTORY . '/includes/admin/db-upgrade.php';
Avpvh_Lnkc_Database_Upgrader::upgrade_database();
$blclog->info( sprintf( '--- Total: %.3f seconds', microtime( true ) - $upgrade_start ) );

//Remove invalid DB entries
$blclog->info( 'Cleaning up the database...' );
$cleanup_start = microtime( true );
avpvh_lnkc_cleanup_database();
$blclog->info( sprintf( '--- Total: %.3f seconds', microtime( true ) - $cleanup_start ) );

//Notify modules that the plugin has been activated. This will cause container
//modules to create and update synch. records for all new/modified posts and other items.
$blclog->info( 'Notifying modules...' );
$notification_start = microtime( true );
$moduleManager->plugin_activated();
avpvh_lnkc_got_unsynched_items();
$blclog->info( sprintf( '--- Total: %.3f seconds', microtime( true ) - $notification_start ) );

//Turn off load limiting if it's not available on this server.
$blclog->info( 'Updating server load limit settings...' );
$load = Avpvh_Lnkc_Utility::get_server_load();
if ( empty( $load ) ) {
	$avpvh_lnkc_config_manager->options['enable_load_limit'] = false;
	$blclog->info( 'Disable load limit. Cannot retrieve current load average.' );
} elseif ( $avpvh_lnkc_config_manager->options['enable_load_limit'] && ! isset( $avpvh_lnkc_config_manager->options['server_load_limit'] ) ) {
	$fifteen_minutes                                  = floatval( end( $load ) );
	$default_load_limit                               = round( max( min( $fifteen_minutes * 2, $fifteen_minutes + 2 ), 4 ) );
	$avpvh_lnkc_config_manager->options['server_load_limit'] = $default_load_limit;

	$blclog->info(
		sprintf(
			'Set server load limit to %.2f. Current load average is %.2f',
			$default_load_limit,
			$fifteen_minutes
		)
	);
}

//And optimize my DB tables, too (for good measure)
$blclog->info( 'Optimizing the database...' );
$optimize_start = microtime( true );
Avpvh_Lnkc_Utility::optimize_database();
$blclog->info( sprintf( '--- Total: %.3f seconds', microtime( true ) - $optimize_start ) );

$blclog->info( 'Completing installation...' );
$avpvh_lnkc_config_manager->options['installation_complete']    = true;
$avpvh_lnkc_config_manager->options['installation_flag_set_on'] = date( 'c' ) . ' (' . microtime( true ) . ')'; //phpcs:ignore
if ( $avpvh_lnkc_config_manager->save_options() ) {
	$blclog->info( 'Configuration saved.' );
} else {
	$blclog->error( 'Error saving plugin configuration!' );
};

$blclog->info(
	sprintf(
		'Installation/update completed at %s with %d queries executed.',
		date_i18n( 'Y-m-d H:i:s' ),
		$wpdb->num_queries - $queryCnt
	)
);
$blclog->info( sprintf( 'Total time: %.3f seconds', microtime( true ) - $activation_start ) );
$blclog->save();

// for multisite support.
update_option( 'avpvh_lnkc_activation_enabled', true );
