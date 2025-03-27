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

// Enable WP_DEBUG if not already enabled
if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', true);
}

// Enable debug logging
if (!defined('WP_DEBUG_LOG')) {
    define('WP_DEBUG_LOG', true);
}

// Define plugin constants
define('ONTARIO_OBITUARIES_VERSION', '1.0.0');
define('ONTARIO_OBITUARIES_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ONTARIO_OBITUARIES_PLUGIN_URL', plugin_dir_url(__FILE__));

// Log plugin initialization start
error_log('Ontario Obituaries: Plugin initialization beginning...');

// Include required files
$required_files = array(
    'includes/class-ontario-obituaries.php',
    'includes/class-ontario-obituaries-scraper.php',
    'includes/class-ontario-obituaries-admin.php',
    'includes/class-ontario-obituaries-display.php',
    'includes/class-ontario-obituaries-ajax.php',
    'includes/class-ontario-obituaries-debug.php'
);

$missing_files = array();
foreach ($required_files as $file) {
    $file_path = ONTARIO_OBITUARIES_PLUGIN_DIR . $file;
    if (file_exists($file_path)) {
        require_once $file_path;
        error_log('Ontario Obituaries: Successfully loaded ' . $file);
    } else {
        error_log('Ontario Obituaries: File not found - ' . $file_path);
        $missing_files[] = $file;
    }
}

// Stop initialization if files are missing
if (!empty($missing_files)) {
    add_action('admin_notices', function() use ($missing_files) {
        $message = sprintf(
            __('Ontario Obituaries Error: The following required files are missing: %s', 'ontario-obituaries'),
            '<ul><li>' . implode('</li><li>', $missing_files) . '</li></ul>'
        );
        echo '<div class="error"><p>' . $message . '</p></div>';
    });
    return;
}

// Initialize the plugin
function ontario_obituaries_init() {
    try {
        error_log('Ontario Obituaries: Starting plugin initialization');
        
        // Initialize the main plugin class
        if (class_exists('Ontario_Obituaries')) {
            $plugin = new Ontario_Obituaries();
            $plugin->run();
            error_log('Ontario Obituaries: Main plugin class initialized');
        } else {
            throw new Exception('Ontario_Obituaries class not found');
        }
        
        // Initialize AJAX handler
        if (class_exists('Ontario_Obituaries_Ajax')) {
            $ajax = new Ontario_Obituaries_Ajax();
            $ajax->init();
            error_log('Ontario Obituaries: AJAX handler initialized');
        } else {
            error_log('Ontario Obituaries: Ontario_Obituaries_Ajax class not found');
        }
        
        // Initialize Debug handler
        if (class_exists('Ontario_Obituaries_Debug')) {
            $debug = new Ontario_Obituaries_Debug();
            $debug->init();
            error_log('Ontario Obituaries: Debug handler initialized');
        } else {
            error_log('Ontario Obituaries: Ontario_Obituaries_Debug class not found');
        }
        
        // Register shortcode
        add_shortcode('ontario_obituaries', 'ontario_obituaries_shortcode');
        error_log('Ontario Obituaries: Shortcode registered');
        
        error_log('Ontario Obituaries: Plugin initialization completed');
    } catch (Exception $e) {
        error_log('Ontario Obituaries: Error during plugin initialization: ' . $e->getMessage());
        add_action('admin_notices', function() use ($e) {
            echo '<div class="error"><p>' . esc_html__('Ontario Obituaries Error: ', 'ontario-obituaries') . esc_html($e->getMessage()) . '</p></div>';
        });
    }
}

// Run initialization on plugins_loaded to ensure WordPress is fully loaded
add_action('plugins_loaded', 'ontario_obituaries_init');

// Register activation hook
register_activation_hook(__FILE__, 'ontario_obituaries_activate');

function ontario_obituaries_activate() {
    try {
        error_log('Ontario Obituaries: Running plugin activation');
        
        // Create database tables
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'ontario_obituaries';
        
        error_log('Ontario Obituaries: Creating database table ' . $table_name);
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        if (!$table_exists) {
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
            $result = dbDelta($sql);
            error_log('Ontario Obituaries: Database creation result: ' . print_r($result, true));
            
            // Add default data
            ontario_obituaries_add_sample_data();
        } else {
            error_log('Ontario Obituaries: Table already exists, skipping creation');
        }
        
        // Schedule the daily scraper cron job
        if (!wp_next_scheduled('ontario_obituaries_daily_scrape')) {
            $scheduled = wp_schedule_event(time(), 'daily', 'ontario_obituaries_daily_scrape');
            error_log('Ontario Obituaries: Scheduling daily scrape result: ' . ($scheduled ? 'success' : 'failed'));
        }
        
        // Default Facebook settings
        if (!get_option('ontario_obituaries_facebook_settings')) {
            add_option('ontario_obituaries_facebook_settings', array(
                'app_id' => '',
                'enabled' => false,
                'group_id' => '',
                'auto_share' => false
            ));
            error_log('Ontario Obituaries: Added default Facebook settings');
        }
        
        error_log('Ontario Obituaries: Activation completed successfully');
        
        // Store success message to show admin notice
        set_transient('ontario_obituaries_activation_notice', array(
            'success' => true,
            'message' => 'Ontario Obituaries plugin activated successfully!'
        ), 60 * 5); // Display for 5 minutes
        
    } catch (Exception $e) {
        error_log('Ontario Obituaries: Error during activation: ' . $e->getMessage());
        // Store activation error for display
        set_transient('ontario_obituaries_activation_notice', array(
            'success' => false,
            'message' => 'Error activating plugin: ' . $e->getMessage()
        ), 60 * 60); // Display for 1 hour
    }
}

// Function to add sample data if table is empty
function ontario_obituaries_add_sample_data() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ontario_obituaries';
    
    // Check if table is empty
    $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    
    if ($count == 0) {
        error_log('Ontario Obituaries: Adding sample data');
        
        // Sample data
        $sample_data = array(
            array(
                'name' => 'John Smith',
                'age' => 78,
                'date_of_birth' => '1945-06-15',
                'date_of_death' => date('Y-m-d', strtotime('-5 days')),
                'funeral_home' => 'Smith & Sons Funeral Home',
                'location' => 'Toronto',
                'description' => 'John Smith passed away peacefully surrounded by his family. He was a beloved husband, father, and grandfather.',
                'source_url' => 'https://example.com/obituary1'
            ),
            array(
                'name' => 'Mary Johnson',
                'age' => 92,
                'date_of_birth' => '1932-03-22',
                'date_of_death' => date('Y-m-d', strtotime('-3 days')),
                'funeral_home' => 'Johnson Funeral Services',
                'location' => 'Ottawa',
                'description' => 'Mary Johnson, loving mother and grandmother, passed away in her sleep. She will be deeply missed by all who knew her.',
                'source_url' => 'https://example.com/obituary2'
            ),
            array(
                'name' => 'Robert Williams',
                'age' => 65,
                'date_of_birth' => '1958-11-10',
                'date_of_death' => date('Y-m-d', strtotime('-7 days')),
                'funeral_home' => 'Williams Memorial',
                'location' => 'Hamilton',
                'description' => 'Robert Williams, dedicated teacher and community volunteer, left us too soon. His impact on the community will be remembered.',
                'source_url' => 'https://example.com/obituary3'
            )
        );
        
        foreach ($sample_data as $data) {
            $wpdb->insert(
                $table_name,
                $data
            );
        }
        
        error_log('Ontario Obituaries: Added ' . count($sample_data) . ' sample obituaries');
    }
}

// Register shortcode for displaying obituaries
function ontario_obituaries_shortcode($atts) {
    try {
        error_log('Ontario Obituaries: Shortcode called with attributes: ' . print_r($atts, true));
        
        if (class_exists('Ontario_Obituaries_Display')) {
            $display = new Ontario_Obituaries_Display();
            $content = $display->render_obituaries($atts);
            
            // If content is empty, add a message
            if (empty(trim($content))) {
                error_log('Ontario Obituaries: Shortcode returned empty content');
                return '<div class="ontario-obituaries-error">No obituaries found or there was an error displaying them. Please check the plugin settings.</div>';
            }
            
            return $content;
        } else {
            error_log('Ontario Obituaries: Ontario_Obituaries_Display class not found');
            return '<div class="ontario-obituaries-error">Error: Display class not found.</div>';
        }
    } catch (Exception $e) {
        error_log('Ontario Obituaries: Error in shortcode: ' . $e->getMessage());
        return '<div class="ontario-obituaries-error">Error: ' . esc_html($e->getMessage()) . '</div>';
    }
}

// Register deactivation hook
register_deactivation_hook(__FILE__, 'ontario_obituaries_deactivate');
function ontario_obituaries_deactivate() {
    // Clear the scheduled cron job
    wp_clear_scheduled_hook('ontario_obituaries_daily_scrape');
    error_log('Ontario Obituaries: Plugin deactivated, cron job cleared');
}

// Display admin notices
function ontario_obituaries_admin_notices() {
    $notice = get_transient('ontario_obituaries_activation_notice');
    
    if ($notice) {
        $class = $notice['success'] ? 'notice-success' : 'notice-error';
        $message = $notice['message'];
        
        echo "<div class='notice $class is-dismissible'><p>" . esc_html($message) . "</p></div>";
        
        // Remove the transient
        delete_transient('ontario_obituaries_activation_notice');
    }
}
add_action('admin_notices', 'ontario_obituaries_admin_notices');

// Add Facebook settings to the admin menu
function ontario_obituaries_facebook_settings_init() {
    add_submenu_page(
        'ontario-obituaries',
        __('Facebook Integration', 'ontario-obituaries'),
        __('Facebook Integration', 'ontario-obituaries'),
        'manage_options',
        'ontario-obituaries-facebook',
        'ontario_obituaries_facebook_settings_page'
    );
    
    // Register settings
    register_setting('ontario_obituaries_facebook', 'ontario_obituaries_facebook_settings');
    
    // Add settings section
    add_settings_section(
        'ontario_obituaries_facebook_section',
        __('Facebook Integration Settings', 'ontario-obituaries'),
        'ontario_obituaries_facebook_section_callback',
        'ontario-obituaries-facebook'
    );
    
    // Add fields
    add_settings_field(
        'facebook_app_id',
        __('Facebook App ID', 'ontario-obituaries'),
        'ontario_obituaries_facebook_app_id_callback',
        'ontario-obituaries-facebook',
        'ontario_obituaries_facebook_section'
    );
    
    add_settings_field(
        'facebook_enabled',
        __('Enable Facebook Integration', 'ontario-obituaries'),
        'ontario_obituaries_facebook_enabled_callback',
        'ontario-obituaries-facebook',
        'ontario_obituaries_facebook_section'
    );
    
    add_settings_field(
        'facebook_group_id',
        __('Facebook Group ID', 'ontario-obituaries'),
        'ontario_obituaries_facebook_group_id_callback',
        'ontario-obituaries-facebook',
        'ontario_obituaries_facebook_section'
    );
    
    add_settings_field(
        'facebook_auto_share',
        __('Auto-Share to Facebook Group', 'ontario-obituaries'),
        'ontario_obituaries_facebook_auto_share_callback',
        'ontario-obituaries-facebook',
        'ontario_obituaries_facebook_section'
    );
}
add_action('admin_init', 'ontario_obituaries_facebook_settings_init');

// Settings section callback
function ontario_obituaries_facebook_section_callback() {
    echo '<p>' . __('Configure Facebook integration to share obituaries to your Facebook group.', 'ontario-obituaries') . '</p>';
}

// Facebook App ID field callback
function ontario_obituaries_facebook_app_id_callback() {
    $options = get_option('ontario_obituaries_facebook_settings');
    ?>
    <input type="text" id="facebook_app_id" name="ontario_obituaries_facebook_settings[app_id]" value="<?php echo esc_attr($options['app_id'] ?? ''); ?>" class="regular-text" />
    <p class="description"><?php _e('Enter your Facebook App ID. <a href="https://developers.facebook.com/apps/" target="_blank">Create one here</a> if you don\'t have it.', 'ontario-obituaries'); ?></p>
    <?php
}

// Facebook enabled field callback
function ontario_obituaries_facebook_enabled_callback() {
    $options = get_option('ontario_obituaries_facebook_settings');
    ?>
    <input type="checkbox" id="facebook_enabled" name="ontario_obituaries_facebook_settings[enabled]" <?php checked(isset($options['enabled']) ? $options['enabled'] : false); ?> />
    <p class="description"><?php _e('Enable Facebook sharing functionality', 'ontario-obituaries'); ?></p>
    <?php
}

// Facebook Group ID field callback
function ontario_obituaries_facebook_group_id_callback() {
    $options = get_option('ontario_obituaries_facebook_settings');
    ?>
    <input type="text" id="facebook_group_id" name="ontario_obituaries_facebook_settings[group_id]" value="<?php echo esc_attr($options['group_id'] ?? ''); ?>" class="regular-text" />
    <p class="description"><?php _e('Enter your Facebook Group ID where obituaries will be shared.', 'ontario-obituaries'); ?></p>
    <?php
}

// Facebook auto-share field callback
function ontario_obituaries_facebook_auto_share_callback() {
    $options = get_option('ontario_obituaries_facebook_settings');
    ?>
    <input type="checkbox" id="facebook_auto_share" name="ontario_obituaries_facebook_settings[auto_share]" <?php checked(isset($options['auto_share']) ? $options['auto_share'] : false); ?> />
    <p class="description"><?php _e('Automatically share new obituaries to the Facebook group', 'ontario-obituaries'); ?></p>
    <?php
}

// Settings page callback
function ontario_obituaries_facebook_settings_page() {
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        return;
    }
    
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form action="options.php" method="post">
            <?php
            settings_fields('ontario_obituaries_facebook');
            do_settings_sections('ontario-obituaries-facebook');
            submit_button(__('Save Settings', 'ontario-obituaries'));
            ?>
        </form>
        
        <div class="ontario-obituaries-facebook-help">
            <h2><?php _e('How to Set Up Facebook Integration', 'ontario-obituaries'); ?></h2>
            <ol>
                <li><?php _e('Create a Facebook Developer account and register a new app at <a href="https://developers.facebook.com/apps/" target="_blank">developers.facebook.com</a>', 'ontario-obituaries'); ?></li>
                <li><?php _e('Get your App ID and add it to the settings above.', 'ontario-obituaries'); ?></li>
                <li><?php _e('Create a Facebook Group for your obituaries if you don\'t already have one.', 'ontario-obituaries'); ?></li>
                <li><?php _e('Find your Group ID (it\'s in the URL when you visit your group).', 'ontario-obituaries'); ?></li>
                <li><?php _e('Enable the Facebook integration in the settings above.', 'ontario-obituaries'); ?></li>
                <li><?php _e('Optionally, enable auto-sharing to automatically post new obituaries to your group.', 'ontario-obituaries'); ?></li>
            </ol>
        </div>
    </div>
    <?php
}

// Add admin script for debug page
function ontario_obituaries_admin_scripts($hook) {
    // Only load on our plugin's debug page
    if ($hook !== 'ontario-obituaries_page_ontario-obituaries-debug') {
        return;
    }
    
    wp_enqueue_script(
        'ontario-obituaries-debug-js',
        ONTARIO_OBITUARIES_PLUGIN_URL . 'assets/js/ontario-obituaries-debug.js',
        array('jquery'),
        ONTARIO_OBITUARIES_VERSION,
        true
    );
    
    // Add some basic styles for the debug page
    wp_add_inline_style('wp-admin', '
        .ontario-obituaries-debug .card {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            margin-bottom: 20px;
            padding: 20px;
        }
        
        .ontario-debug-actions {
            margin-bottom: 20px;
        }
        
        .ontario-debug-regions {
            margin-bottom: 20px;
        }
        
        .ontario-debug-log {
            background: #f6f7f7;
            border: 1px solid #ddd;
            border-radius: 3px;
            height: 200px;
            overflow-y: auto;
            padding: 10px;
        }
        
        .log-item {
            font-family: monospace;
            margin-bottom: 5px;
        }
        
        .log-info {
            color: #007cba;
        }
        
        .log-success {
            color: #46b450;
        }
        
        .log-error {
            color: #dc3232;
        }
        
        .region-status {
            font-weight: bold;
        }
        
        .region-status.success {
            color: #46b450;
        }
        
        .region-status.error {
            color: #dc3232;
        }
    ');
    
    // Pass data to JavaScript
    wp_localize_script(
        'ontario-obituaries-debug-js',
        'ontarioObituariesData',
        array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ontario-obituaries-nonce')
        )
    );
}
add_action('admin_enqueue_scripts', 'ontario_obituaries_admin_scripts');

// Update localized script data to include Facebook info
function ontario_obituaries_enqueue_scripts() {
    global $post;
    
    // Only load on pages/posts with our shortcode
    if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'ontario_obituaries')) {
        wp_enqueue_style(
            'ontario-obituaries-css',
            ONTARIO_OBITUARIES_PLUGIN_URL . 'assets/css/ontario-obituaries.css',
            array(),
            ONTARIO_OBITUARIES_VERSION
        );
        
        wp_enqueue_script(
            'ontario-obituaries-js',
            ONTARIO_OBITUARIES_PLUGIN_URL . 'assets/js/ontario-obituaries.js',
            array('jquery'),
            ONTARIO_OBITUARIES_VERSION,
            true
        );
        
        // Get Facebook settings
        $fb_settings = get_option('ontario_obituaries_facebook_settings', array());
        
        // Pass data to JavaScript
        wp_localize_script(
            'ontario-obituaries-js',
            'ontario_obituaries_data',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ontario-obituaries-nonce'),
                'fb_app_id' => $fb_settings['app_id'] ?? '',
                'fb_enabled' => isset($fb_settings['enabled']) ? (bool) $fb_settings['enabled'] : false,
                'site_name' => get_bloginfo('name')
            )
        );
    }
}
add_action('wp_enqueue_scripts', 'ontario_obituaries_enqueue_scripts');

// Add CSS for the Facebook share button
function ontario_obituaries_add_facebook_css() {
    // Add this to your existing CSS file or create a new one
    $custom_css = "
        .ontario-obituaries-share-fb {
            display: inline-flex;
            align-items: center;
            background-color: #1877f2;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            margin-left: 8px;
        }
        
        .ontario-obituaries-share-fb:hover {
            background-color: #166fe5;
        }
        
        .ontario-obituaries-fb-icon {
            display: inline-block;
            width: 16px;
            height: 16px;
            margin-right: 5px;
            background-image: url('data:image/svg+xml;charset=utf-8,%3Csvg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\" fill=\"%23fff\"%3E%3Cpath d=\"M9.198 21.5h4v-8.01h3.604l.396-3.98h-4V7.5a1 1 0 0 1 1-1h3v-4h-3a5 5 0 0 0-5 5v2.01h-2l-.396 3.98h2.396v8.01Z\"%3E%3C/path%3E%3C/svg%3E');
            background-size: contain;
            background-repeat: no-repeat;
        }
        
        .ontario-obituaries-share-success {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background-color: #4caf50;
            color: white;
            padding: 10px 15px;
            border-radius: 4px;
            z-index: 9999;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
    ";
    
    wp_add_inline_style('ontario-obituaries-css', $custom_css);
}
add_action('wp_enqueue_scripts', 'ontario_obituaries_add_facebook_css', 20);

// Add a visual debug option to the frontend for administrators
function ontario_obituaries_add_debug_to_frontend() {
    if (current_user_can('manage_options') && !is_admin()) {
        // Check if the debug parameter is in the URL
        $debug = isset($_GET['ontario_debug']) ? $_GET['ontario_debug'] : '';
        
        if ($debug === 'true') {
            // Output debug information
            echo '<div style="position: fixed; bottom: 0; left: 0; right: 0; background: #f8f9fa; border-top: 3px solid #007cba; padding: 15px; z-index: 9999; font-family: monospace; font-size: 12px;">';
            echo '<h4 style="margin: 0 0 10px;">Ontario Obituaries Debug</h4>';
            
            // Check if table exists
            global $wpdb;
            $table_name = $wpdb->prefix . 'ontario_obituaries';
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
            
            echo '<p>Database Table: ' . ($table_exists ? 'Exists' : 'Missing') . '</p>';
            
            if ($table_exists) {
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
                echo '<p>Records in database: ' . $count . '</p>';
                
                if ($count > 0) {
                    echo '<p>Latest records:</p>';
                    $latest = $wpdb->get_results("SELECT id, name, date_of_death, location FROM $table_name ORDER BY id DESC LIMIT 3");
                    
                    echo '<ul>';
                    foreach ($latest as $record) {
                        echo '<li>' . esc_html($record->name) . ' - ' . esc_html($record->date_of_death) . ' - ' . esc_html($record->location) . '</li>';
                    }
                    echo '</ul>';
                }
            }
            
            echo '<p>Shortcode: [ontario_obituaries]</p>';
            echo '<p>Plugin Version: ' . ONTARIO_OBITUARIES_VERSION . '</p>';
            echo '</div>';
        }
    }
}
add_action('wp_footer', 'ontario_obituaries_add_debug_to_frontend');
