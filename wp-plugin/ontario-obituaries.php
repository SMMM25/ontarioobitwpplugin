
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
require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries-debug.php';

// Initialize the plugin
function ontario_obituaries_init() {
    $plugin = new Ontario_Obituaries();
    $plugin->run();
    
    // Initialize AJAX handler
    $ajax = new Ontario_Obituaries_Ajax();
    $ajax->init();
    
    // Initialize Debug handler
    $debug = new Ontario_Obituaries_Debug();
    $debug->init();
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
    
    // Default Facebook settings
    add_option('ontario_obituaries_facebook_settings', array(
        'app_id' => '',
        'enabled' => false,
        'group_id' => '',
        'auto_share' => false
    ));
    
    // Perform initial scrape of previous month's obituaries
    ontario_obituaries_initial_scrape();
}

// Function to run initial scrape on activation
function ontario_obituaries_initial_scrape() {
    // Log that we're starting the initial scrape
    error_log('Starting initial obituary scrape for previous month on plugin activation');
    
    // Check if we have any obituaries already
    global $wpdb;
    $table_name = $wpdb->prefix . 'ontario_obituaries';
    $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    
    // Only run if we have no obituaries
    if ($count == 0) {
        // Create scraper instance
        $scraper = new Ontario_Obituaries_Scraper();
        
        // Run the previous month scrape
        $result = $scraper->scrape_previous_month();
        
        if ($result['success']) {
            error_log('Initial obituary scrape successful: ' . $result['message']);
            
            // Add transient to show admin notice
            set_transient('ontario_obituaries_initial_scrape_notice', $result, 60 * 60 * 24); // 1 day
        } else {
            error_log('Initial obituary scrape failed: ' . $result['message']);
            
            // If scrape failed, add test data instead
            $test_result = $scraper->add_test_data();
            error_log('Added test data instead: ' . $test_result['message']);
        }
    } else {
        error_log('Skipping initial obituary scrape - database already contains ' . $count . ' obituaries');
    }
}

// Display admin notice after initial scrape
function ontario_obituaries_admin_notices() {
    $notice = get_transient('ontario_obituaries_initial_scrape_notice');
    
    if ($notice) {
        $class = $notice['success'] ? 'notice-success' : 'notice-warning';
        $message = $notice['message'];
        
        echo "<div class='notice $class is-dismissible'><p><strong>Ontario Obituaries:</strong> $message</p></div>";
        
        // Remove the transient
        delete_transient('ontario_obituaries_initial_scrape_notice');
    }
}
add_action('admin_notices', 'ontario_obituaries_admin_notices');

// Register deactivation hook
register_deactivation_hook(__FILE__, 'ontario_obituaries_deactivate');
function ontario_obituaries_deactivate() {
    // Clear the scheduled cron job
    wp_clear_scheduled_hook('ontario_obituaries_daily_scrape');
}

// Register shortcode for displaying obituaries
add_shortcode('ontario_obituaries', 'ontario_obituaries_shortcode');
function ontario_obituaries_shortcode($atts) {
    $display = new Ontario_Obituaries_Display();
    return $display->render_obituaries($atts);
}

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
