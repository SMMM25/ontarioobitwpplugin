
<?php
/**
 * Plugin Name: Ontario Obituaries
 * Description: Scrapes and displays obituaries from Ontario Canada
 * Version: 1.0.0
 * Author: Monaco Monuments
 * Author URI: https://monacomonuments.ca
 * Text Domain: ontario-obituaries
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ONTARIO_OBITUARIES_VERSION', '1.0.0');
define('ONTARIO_OBITUARIES_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ONTARIO_OBITUARIES_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries.php';
require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries-scraper.php';
require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries-admin.php';
require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries-display.php';
require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries-ajax.php';

// Initialize the plugin
function ontario_obituaries_init() {
    $plugin = new Ontario_Obituaries();
    $plugin->run();
    
    // Initialize AJAX handler
    $ajax = new Ontario_Obituaries_Ajax();
    $ajax->init();
}
ontario_obituaries_init();

// Register activation hook
register_activation_hook(__FILE__, 'ontario_obituaries_activate');
function ontario_obituaries_activate() {
    // Create database tables
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->prefix . 'ontario_obituaries';
    
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        age int(3),
        date_of_birth date,
        date_of_death date NOT NULL,
        funeral_home varchar(255) NOT NULL,
        location varchar(255) NOT NULL,
        image_url text,
        description longtext NOT NULL,
        source_url text NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Schedule the daily scraper cron job
    if (!wp_next_scheduled('ontario_obituaries_daily_scrape')) {
        wp_schedule_event(time(), 'daily', 'ontario_obituaries_daily_scrape');
    }
    
    // Create the obituaries page
    $plugin = new Ontario_Obituaries();
    $plugin->register_obituaries_page();
}

// Register deactivation hook
register_deactivation_hook(__FILE__, 'ontario_obituaries_deactivate');
function ontario_obituaries_deactivate() {
    // Clear the scheduled cron job
    wp_clear_scheduled_hook('ontario_obituaries_daily_scrape');
}
