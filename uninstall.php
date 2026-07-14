<?php
// When set to true, this erases the options and the information column (not default)
// When set to false, this keeps everything so an accidental deletion never wipes collected data
$dd_full_uninstall = false;

// Only ever run in the proper uninstall context never via a direct request
if ( ! defined( 'YOURLS_UNINSTALL_PLUGIN' ) && ! defined( 'YOURLS_ABSPATH' ) ) {
    die();
}

// Deactivation mode preserves everything and bails out
if ( ! $dd_full_uninstall ) {
    return;
}

// Remove the plugin stored options
$dd_options = array(
    'dd_ipinfo_token',
    'dd_api_signature',
    'dd_info_column_created',
);

foreach ( $dd_options as $dd_option ) {
    if ( function_exists( 'yourls_delete_option' ) ) {
        yourls_delete_option( $dd_option );
    }
}

// Drop the information column this plugin added to the log table which erases all collected device data
if ( function_exists( 'yourls_get_db' ) && defined( 'YOURLS_DB_TABLE_LOG' ) ) {
    $dd_table = YOURLS_DB_TABLE_LOG;
    $dd_db    = yourls_get_db();

    try {
        $dd_has_column = $dd_db->fetchObjects( "SHOW COLUMNS FROM `$dd_table` LIKE 'information'" );
        if ( ! empty( $dd_has_column ) ) {
            $dd_db->query( "ALTER TABLE `$dd_table` DROP COLUMN `information`" );
        }
    } catch ( Exception $e ) {
        // Nothing else to do the plugin files are about to be removed regardless
    }
}
