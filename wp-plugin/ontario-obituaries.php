<?php
/**
 * Plugin Name: Ontario Obituaries
 * Description: Displays obituaries from Ontario Canada - Compatible with Obituary Assistant
 * Version: 1.0.3
 * Author: Monaco Monuments
 * Author URI: https://monacomonuments.ca
 * Text Domain: ontario-obituaries
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ONTARIO_OBITUARIES_VERSION', '1.0.3');
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
    
    // Add the data processor class
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
    
    // Schedule the cron events for collection
    if (!wp_next_scheduled('ontario_obituaries_collection_event')) {
        // Schedule the event to run twice daily
        wp_schedule_event(time(), 'twicedaily', 'ontario_obituaries_collection_event');
    }
    
    // Run an initial collection to populate data immediately
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
    $timestamp = wp_next_scheduled('ontario_obituaries_collection_event');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'ontario_obituaries_collection_event');
    }
    
    // Flush rewrite rules
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'ontario_obituaries_deactivate');

/**
 * Scheduled task hook for collecting obituaries
 */
function ontario_obituaries_scheduled_collection() {
    // Load the collection class if not already loaded
    if (!class_exists('Ontario_Obituaries_Scraper')) {
        require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries-scraper.php';
    }
    
    // Initialize and run the collection
    $scraper = new Ontario_Obituaries_Scraper();
    $results = $scraper->collect();
    
    // Log the results
    error_log('Ontario Obituaries Scheduled Collection: ' . print_r($results, true));
    
    // Update the last collection timestamp and data
    update_option('ontario_obituaries_last_collection', array(
        'timestamp' => current_time('timestamp'),
        'completed' => current_time('mysql'),
        'results' => $results
    ));
}
add_action('ontario_obituaries_collection_event', 'ontario_obituaries_scheduled_collection');

/**
 * Admin notices hook
 */
function ontario_obituaries_admin_notices() {
    // Activation notice
    if (get_transient('ontario_obituaries_activation_notice')) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e('Ontario Obituaries plugin has been activated successfully!', 'ontario-obituaries'); ?></p>
            <p><?php _e('An initial collection has been triggered to populate obituaries data.', 'ontario-obituaries'); ?></p>
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
    
    // Check for failed collections
    $last_collection = get_option('ontario_obituaries_last_collection');
    if ($last_collection && isset($last_collection['results']) && !empty($last_collection['results']['errors'])) {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p><?php _e('Ontario Obituaries encountered some issues during the last collection. Check the logs for details.', 'ontario-obituaries'); ?></p>
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
        __('Run Ontario Obituaries Collection', 'ontario-obituaries'),
        __('Run Obituaries Collection', 'ontario-obituaries'),
        'manage_options',
        'run-ontario-collection',
        'ontario_obituaries_render_collection_page'
    );
}
add_action('admin_menu', 'ontario_obituaries_add_admin_menu');

/**
 * Render the manual collection page
 */
function ontario_obituaries_render_collection_page() {
    ?>
    <div class="wrap">
        <h1><?php _e('Run Ontario Obituaries Collection', 'ontario-obituaries'); ?></h1>
        <p><?php _e('Click the button below to manually run the obituaries collection right now.', 'ontario-obituaries'); ?></p>
        
        <form method="post" action="">
            <?php wp_nonce_field('run_ontario_collection', 'ontario_collection_nonce'); ?>
            <input type="submit" name="run_collection" class="button button-primary" value="<?php _e('Run Collection Now', 'ontario-obituaries'); ?>">
        </form>
        
        <?php
        // Handle the form submission
        if (isset($_POST['run_collection']) && check_admin_referer('run_ontario_collection', 'ontario_collection_nonce')) {
            // Load the collection class if not already loaded
            if (!class_exists('Ontario_Obituaries_Scraper')) {
                require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries-scraper.php';
            }
            
            // Initialize and run the collection
            $scraper = new Ontario_Obituaries_Scraper();
            $results = $scraper->run_now();
            
            // Display the results
            echo '<h2>' . __('Collection Results', 'ontario-obituaries') . '</h2>';
            echo '<div class="notice notice-success inline"><p>' . sprintf(__('Collection completed. Found %d obituaries, added %d new entries.', 'ontario-obituaries'), $results['obituaries_found'], $results['obituaries_added']) . '</p></div>';
            
            // Display any errors
            if (!empty($results['errors'])) {
                echo '<h3>' . __('Errors', 'ontario-obituaries') . '</h3>';
                echo '<div class="notice notice-warning inline"><ul>';
                foreach ($results['errors'] as $region_id => $errors) {
                    $error_messages = is_array($errors) ? implode(', ', $errors) : $errors;
                    echo '<li><strong>' . esc_html($region_id) . ':</strong> ' . esc_html($error_messages) . '</li>';
                }
                echo '</ul></div>';
            }
            
            // Display collection details for each region
            echo '<h3>' . __('Region Details', 'ontario-obituaries') . '</h3>';
            echo '<table class="widefat striped">';
            echo '<thead><tr><th>' . __('Region', 'ontario-obituaries') . '</th><th>' . __('Found', 'ontario-obituaries') . '</th><th>' . __('Added', 'ontario-obituaries') . '</th></tr></thead>';
            echo '<tbody>';
            
            if (!empty($results['regions'])) {
                foreach ($results['regions'] as $region_id => $region_results) {
                    echo '<tr>';
                    echo '<td>' . esc_html($region_id) . '</td>';
                    echo '<td>' . intval($region_results['found']) . '</td>';
                    echo '<td>' . intval($region_results['added']) . '</td>';
                    echo '</tr>';
                }
            } else {
                echo '<tr><td colspan="3">' . __('No region details available', 'ontario-obituaries') . '</td></tr>';
            }
            
            echo '</tbody></table>';
        }
        ?>
    </div>
    <?php
}

/**
 * Add a new dashboard widget for obituary statistics
 */
function ontario_obituaries_add_dashboard_widget() {
    wp_add_dashboard_widget(
        'ontario_obituaries_stats', 
        __('Ontario Obituaries Statistics', 'ontario-obituaries'),
        'ontario_obituaries_render_dashboard_widget'
    );
}
add_action('wp_dashboard_setup', 'ontario_obituaries_add_dashboard_widget');

/**
 * Render the dashboard widget
 */
function ontario_obituaries_render_dashboard_widget() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ontario_obituaries';
    
    // Get total count
    $total_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    
    // Get count from the last 7 days
    $last_week_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE date_of_death >= %s",
        date('Y-m-d', strtotime('-7 days'))
    ));
    
    // Get counts by source
    $source_counts = $wpdb->get_results(
        "SELECT funeral_home, COUNT(*) as count FROM $table_name GROUP BY funeral_home ORDER BY count DESC LIMIT 10"
    );
    
    // Get the last collection result
    $last_collection = get_option('ontario_obituaries_last_collection');
    
    echo '<div class="ontario-obituaries-widget">';
    echo '<p>' . sprintf(__('Total Obituaries: <strong>%d</strong>', 'ontario-obituaries'), $total_count) . '</p>';
    echo '<p>' . sprintf(__('Added in Last 7 Days: <strong>%d</strong>', 'ontario-obituaries'), $last_week_count) . '</p>';
    
    if (!empty($source_counts)) {
        echo '<h4>' . __('Top Sources:', 'ontario-obituaries') . '</h4>';
        echo '<ul>';
        foreach ($source_counts as $source) {
            echo '<li>' . esc_html($source->funeral_home) . ': ' . esc_html($source->count) . '</li>';
        }
        echo '</ul>';
    }
    
    if (!empty($last_collection)) {
        $time_ago = human_time_diff(strtotime($last_collection['timestamp']), current_time('timestamp'));
        echo '<p>' . sprintf(__('Last Collection: <strong>%s ago</strong>', 'ontario-obituaries'), $time_ago) . '</p>';
        
        if (isset($last_collection['results'])) {
            $results = $last_collection['results'];
            echo '<p>' . sprintf(
                __('Last Run: %d found, %d added', 'ontario-obituaries'),
                $results['obituaries_found'],
                $results['obituaries_added']
            ) . '</p>';
        }
    }
    
    echo '<p><a href="' . admin_url('tools.php?page=run-ontario-collection') . '">' . __('Run Collection Now', 'ontario-obituaries') . '</a></p>';
    echo '</div>';
}
