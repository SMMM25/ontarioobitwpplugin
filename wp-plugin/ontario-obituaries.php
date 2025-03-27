
<?php
/**
 * Plugin Name: Ontario Obituaries
 * Description: Scrapes and displays obituaries from Ontario Canada - Compatible with Obituary Assistant
 * Version: 1.0.2
 * Author: Monaco Monuments
 * Author URI: https://monacomonuments.ca
 * Text Domain: ontario-obituaries
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ONTARIO_OBITUARIES_VERSION', '1.0.2');
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
    
    // Add the scraper class
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries-scraper.php';

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
    
    // Schedule the cron events for scraping
    if (!wp_next_scheduled('ontario_obituaries_scrape_event')) {
        // Schedule the event to run twice daily
        wp_schedule_event(time(), 'twicedaily', 'ontario_obituaries_scrape_event');
    }
    
    // Run an initial scrape to populate data immediately
    if (class_exists('Ontario_Obituaries_Scraper')) {
        $scraper = new Ontario_Obituaries_Scraper();
        $scraper->run_now();
    }
    
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
    
    // Unschedule the cron event
    $timestamp = wp_next_scheduled('ontario_obituaries_scrape_event');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'ontario_obituaries_scrape_event');
    }
    
    // Flush rewrite rules
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'ontario_obituaries_deactivate');

/**
 * Scheduled task hook for scraping obituaries
 */
function ontario_obituaries_scheduled_scrape() {
    // Load the scraper class if not already loaded
    if (!class_exists('Ontario_Obituaries_Scraper')) {
        require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries-scraper.php';
    }
    
    // Initialize and run the scraper
    $scraper = new Ontario_Obituaries_Scraper();
    $results = $scraper->scrape();
    
    // Log the results
    error_log('Ontario Obituaries Scheduled Scrape: ' . print_r($results, true));
    
    // Update the last scrape timestamp and data
    update_option('ontario_obituaries_last_scrape', array(
        'timestamp' => current_time('timestamp'),
        'completed' => current_time('mysql'),
        'results' => $results
    ));
}
add_action('ontario_obituaries_scrape_event', 'ontario_obituaries_scheduled_scrape');

/**
 * Admin notices hook
 */
function ontario_obituaries_admin_notices() {
    // Activation notice
    if (get_transient('ontario_obituaries_activation_notice')) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e('Ontario Obituaries plugin has been activated successfully!', 'ontario-obituaries'); ?></p>
            <p><?php _e('An initial scrape has been triggered to populate obituaries data.', 'ontario-obituaries'); ?></p>
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
    
    // Check for failed scrapes
    $last_scrape = get_option('ontario_obituaries_last_scrape');
    if ($last_scrape && isset($last_scrape['results']) && !empty($last_scrape['results']['errors'])) {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p><?php _e('Ontario Obituaries encountered some issues during the last scrape. Check the logs for details.', 'ontario-obituaries'); ?></p>
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

/**
 * Add a new menu item in the WP Admin
 */
function ontario_obituaries_add_admin_menu() {
    add_management_page(
        __('Run Ontario Obituaries Scraper', 'ontario-obituaries'),
        __('Run Obituaries Scraper', 'ontario-obituaries'),
        'manage_options',
        'run-ontario-scraper',
        'ontario_obituaries_render_scraper_page'
    );
}
add_action('admin_menu', 'ontario_obituaries_add_admin_menu');

/**
 * Render the manual scrape page
 */
function ontario_obituaries_render_scraper_page() {
    ?>
    <div class="wrap">
        <h1><?php _e('Run Ontario Obituaries Scraper', 'ontario-obituaries'); ?></h1>
        <p><?php _e('Click the button below to manually run the obituaries scraper right now.', 'ontario-obituaries'); ?></p>
        
        <form method="post" action="">
            <?php wp_nonce_field('run_ontario_scraper', 'ontario_scraper_nonce'); ?>
            <input type="submit" name="run_scraper" class="button button-primary" value="<?php _e('Run Scraper Now', 'ontario-obituaries'); ?>">
        </form>
        
        <?php
        // Handle the form submission
        if (isset($_POST['run_scraper']) && check_admin_referer('run_ontario_scraper', 'ontario_scraper_nonce')) {
            // Load the scraper class if not already loaded
            if (!class_exists('Ontario_Obituaries_Scraper')) {
                require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries-scraper.php';
            }
            
            // Initialize and run the scraper
            $scraper = new Ontario_Obituaries_Scraper();
            $results = $scraper->run_now();
            
            // Display the results
            echo '<h2>' . __('Scrape Results', 'ontario-obituaries') . '</h2>';
            echo '<div class="notice notice-success inline"><p>' . sprintf(__('Scrape completed. Found %d obituaries, added %d new entries.', 'ontario-obituaries'), $results['obituaries_found'], $results['obituaries_added']) . '</p></div>';
            
            // Display any errors
            if (!empty($results['errors'])) {
                echo '<h3>' . __('Errors', 'ontario-obituaries') . '</h3>';
                echo '<div class="notice notice-warning inline"><ul>';
                foreach ($results['errors'] as $source_id => $errors) {
                    $error_messages = is_array($errors) ? implode(', ', $errors) : $errors;
                    echo '<li><strong>' . esc_html($source_id) . ':</strong> ' . esc_html($error_messages) . '</li>';
                }
                echo '</ul></div>';
            }
            
            // Display scraping details for each source
            echo '<h3>' . __('Sources Details', 'ontario-obituaries') . '</h3>';
            echo '<table class="widefat striped">';
            echo '<thead><tr><th>' . __('Source', 'ontario-obituaries') . '</th><th>' . __('Found', 'ontario-obituaries') . '</th><th>' . __('Added', 'ontario-obituaries') . '</th></tr></thead>';
            echo '<tbody>';
            
            if (!empty($results['sources'])) {
                foreach ($results['sources'] as $source_id => $source_results) {
                    echo '<tr>';
                    echo '<td>' . esc_html($source_id) . '</td>';
                    echo '<td>' . intval($source_results['found']) . '</td>';
                    echo '<td>' . intval($source_results['added']) . '</td>';
                    echo '</tr>';
                }
            } else {
                echo '<tr><td colspan="3">' . __('No source details available', 'ontario-obituaries') . '</td></tr>';
            }
            
            echo '</tbody></table>';
        }
        ?>
    </div>
    <?php
}

