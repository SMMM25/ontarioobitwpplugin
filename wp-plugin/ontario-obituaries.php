
<?php
/**
 * Plugin Name: Ontario Obituaries
 * Description: Scrapes and displays obituaries from Ontario Canada - Compatible with Obituary Assistant
 * Version: 1.0.1
 * Author: Monaco Monuments
 * Author URI: https://monacomonuments.ca
 * Text Domain: ontario-obituaries
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ONTARIO_OBITUARIES_VERSION', '1.0.1');
define('ONTARIO_OBITUARIES_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ONTARIO_OBITUARIES_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Check if Obituary Assistant plugin is active
 * 
 * @return bool Whether Obituary Assistant is active
 */
function ontario_obituaries_check_dependency() {
    include_once(ABSPATH . 'wp-admin/includes/plugin.php');
    return is_plugin_active('obituary-assistant/obituary-assistant.php');
}

/**
 * Include required files
 */
function ontario_obituaries_includes() {
    // Core plugin class
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries.php';
    
    // Display class for frontend rendering
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries-display.php';
    
    // AJAX handler
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries-ajax.php';

    // Initialize AJAX handler
    $ajax_handler = new Ontario_Obituaries_Ajax();
    $ajax_handler->init();
}

/**
 * Activation function
 */
function ontario_obituaries_activate() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->prefix . 'ontario_obituaries';
    
    // SQL to create the obituaries table - optimized schema
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        name varchar(100) NOT NULL,
        date_of_birth date DEFAULT NULL,
        date_of_death date NOT NULL,
        age int(3) DEFAULT NULL,
        funeral_home varchar(100) DEFAULT NULL,
        location varchar(100) DEFAULT NULL,
        image_url varchar(255) DEFAULT NULL,
        description text DEFAULT NULL,
        source_url varchar(255) DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_date_death (date_of_death),
        KEY idx_location (location),
        KEY idx_funeral_home (funeral_home)
    ) $charset_collate;";
    
    // Include WordPress database upgrade functions
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Set a transient to show an admin notice
    set_transient('ontario_obituaries_activation_notice', true, 60 * 5);
    
    // Force-flush rewrite rules
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'ontario_obituaries_activate');

/**
 * Deactivation hook
 */
function ontario_obituaries_deactivate() {
    // Clean up transients
    delete_transient('ontario_obituaries_activation_notice');
    
    // Flush rewrite rules
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'ontario_obituaries_deactivate');

/**
 * Admin notices hook
 */
function ontario_obituaries_admin_notices() {
    // Activation notice
    if (get_transient('ontario_obituaries_activation_notice')) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e('Ontario Obituaries plugin has been activated successfully!', 'ontario-obituaries'); ?></p>
        </div>
        <?php
        delete_transient('ontario_obituaries_activation_notice');
    }
    
    // Dependency notice
    if (!ontario_obituaries_check_dependency()) {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p><?php _e('Ontario Obituaries plugin works best with Obituary Assistant plugin. Please install and activate it for full functionality.', 'ontario-obituaries'); ?></p>
        </div>
        <?php
    }
}
add_action('admin_notices', 'ontario_obituaries_admin_notices');

/**
 * Initialize the plugin
 */
function ontario_obituaries_init() {
    // Include required files
    ontario_obituaries_includes();
    
    // Initialize the main plugin class
    $plugin = new Ontario_Obituaries();
}
add_action('plugins_loaded', 'ontario_obituaries_init');

/**
 * Add integration with Obituary Assistant
 */
function ontario_obituaries_integration() {
    if (ontario_obituaries_check_dependency()) {
        // Add integration filters and actions here
        add_filter('obituary_assistant_sources', 'ontario_obituaries_register_source');
    }
}
add_action('plugins_loaded', 'ontario_obituaries_integration', 20);

/**
 * Register as a source for Obituary Assistant
 */
function ontario_obituaries_register_source($sources) {
    $sources['ontario'] = array(
        'name' => 'Ontario Obituaries',
        'description' => 'Obituaries from Ontario, Canada',
        'callback' => 'ontario_obituaries_get_data_for_assistant'
    );
    
    return $sources;
}

/**
 * Provide data to Obituary Assistant
 */
function ontario_obituaries_get_data_for_assistant() {
    $display = new Ontario_Obituaries_Display();
    return $display->get_obituaries(array('limit' => 50));
}
