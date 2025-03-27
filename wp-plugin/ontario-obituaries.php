
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

// Include the main plugin class
require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries.php';

// Activation function - extremely simplified
function ontario_obituaries_activate() {
    // Just log that activation was attempted
    error_log('Ontario Obituaries: Plugin activation started');
    
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

// Initialize the plugin
function ontario_obituaries_init() {
    $plugin = new Ontario_Obituaries();
}
add_action('plugins_loaded', 'ontario_obituaries_init');
