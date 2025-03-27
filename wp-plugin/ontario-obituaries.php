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

// Activation function - keep this extremely simple to avoid activation failures
function ontario_obituaries_activate() {
    // Create a log that we can check
    error_log('Ontario Obituaries: Plugin activation started');
    
    // Create database tables
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->prefix . 'ontario_obituaries';
    
    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    
    if (!$table_exists) {
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            date_of_death date NOT NULL,
            funeral_home varchar(255) NOT NULL,
            location varchar(255) NOT NULL,
            description longtext NOT NULL,
            source_url text NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        error_log('Ontario Obituaries: Database table created');
    }
    
    // Set a transient to show an admin notice
    set_transient('ontario_obituaries_activation_notice', true, 60 * 5);
    
    error_log('Ontario Obituaries: Plugin activation completed');
}
register_activation_hook(__FILE__, 'ontario_obituaries_activate');

// Simple deactivation hook
function ontario_obituaries_deactivate() {
    error_log('Ontario Obituaries: Plugin deactivated');
}
register_deactivation_hook(__FILE__, 'ontario_obituaries_deactivate');

// Hook for admin notices
function ontario_obituaries_admin_notices() {
    if (get_transient('ontario_obituaries_activation_notice')) {
        echo '<div class="notice notice-success is-dismissible"><p>Ontario Obituaries plugin has been activated successfully!</p></div>';
        delete_transient('ontario_obituaries_activation_notice');
    }
}
add_action('admin_notices', 'ontario_obituaries_admin_notices');

// Basic shortcode
function ontario_obituaries_shortcode($atts) {
    return '<div class="ontario-obituaries">Ontario Obituaries will be displayed here.</div>';
}
add_shortcode('ontario_obituaries', 'ontario_obituaries_shortcode');

// Add a basic admin menu
function ontario_obituaries_admin_menu() {
    add_menu_page(
        'Ontario Obituaries',
        'Ontario Obituaries',
        'manage_options',
        'ontario-obituaries',
        'ontario_obituaries_admin_page',
        'dashicons-list-view',
        30
    );
}
add_action('admin_menu', 'ontario_obituaries_admin_menu');

// Simple admin page
function ontario_obituaries_admin_page() {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <p>Welcome to Ontario Obituaries plugin. This is a basic version to ensure plugin activation works correctly.</p>
    </div>
    <?php
}

// Include class files only after admin_init to ensure WordPress is fully loaded
function ontario_obituaries_load_files() {
    // We'll add more files later when the basic plugin is confirmed to work
}
add_action('admin_init', 'ontario_obituaries_load_files');
