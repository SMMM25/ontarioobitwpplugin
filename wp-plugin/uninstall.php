<?php
/**
 * Uninstall script for Ontario Obituaries
 *
 * Fired when the plugin is deleted from WP Admin > Plugins.
 * Cleans up all database tables, options, transients, and cron events
 * created by the plugin.
 *
 * P2-1  FIX: Added proper cleanup on plugin deletion.
 * P1-6  FIX: Restored flush_rewrite_rules() — SEO class registers
 *            /obituaries/ontario/ rewrite rules that must be cleaned up.
 *
 * @package OntarioObituaries
 */

// Exit if not called by WordPress
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// 1. Drop all custom tables
$tables = array(
    $wpdb->prefix . 'ontario_obituaries',
    $wpdb->prefix . 'ontario_obituaries_sources',
    $wpdb->prefix . 'ontario_obituaries_suppressions',
);

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

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
    'ontario_obituaries_page_id',
    'ontario_obituaries_menu_added',
    'ontario_obituaries_menu_v2',
    'ontario_obituaries_dedup_v1',
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
wp_clear_scheduled_hook( 'ontario_obituaries_initial_collection' );

// 5. P1-6 FIX: Flush rewrite rules — SEO class registers /obituaries/ontario/ routes
flush_rewrite_rules();
