<?php
/**
 * Uninstall script for Ontario Obituaries
 *
 * Fired when the plugin is deleted from WP Admin > Plugins.
 * Cleans up all database tables, options, transients, and cron events
 * created by the plugin.
 *
 * P2-1  FIX: Added proper cleanup on plugin deletion.
 * FIX-E    : Removed flush_rewrite_rules() – this plugin does not register
 *            any custom rewrite rules, so flushing is unnecessary and slow.
 *
 * @package OntarioObituaries
 */

// Exit if not called by WordPress
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// 1. Drop the custom table
$table_name = $wpdb->prefix . 'ontario_obituaries';
$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// 2. Delete all plugin options
$option_keys = array(
    'ontario_obituaries_settings',
    'ontario_obituaries_db_version',
    'ontario_obituaries_last_collection',
    'ontario_obituaries_last_scrape',
    'ontario_obituaries_last_scrape_errors',
    'ontario_obituaries_region_urls',
    'ontario_obituaries_enable_facebook',
    'ontario_obituaries_fb_app_id',
);

foreach ( $option_keys as $key ) {
    delete_option( $key );
}

// 3. Delete all transients
$transient_keys = array(
    'ontario_obituaries_activation_notice',
    'ontario_obituaries_table_exists',
    'ontario_obituaries_locations_cache',
    'ontario_obituaries_funeral_homes_cache',
);

foreach ( $transient_keys as $key ) {
    delete_transient( $key );
}

// 4. Unschedule ALL cron event instances (P0-1 / P1-1 QC FIX)
wp_clear_scheduled_hook( 'ontario_obituaries_collection_event' );
