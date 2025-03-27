
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

// Log plugin initialization
error_log('Ontario Obituaries: Plugin initialization beginning...');

// Include required files - using require_once instead of custom loading
require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries.php';
require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries-scraper.php';
require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries-admin.php';
require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries-display.php';
require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries-ajax.php';
require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries-debug.php';

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
        }
        
        // Initialize Debug handler
        if (class_exists('Ontario_Obituaries_Debug')) {
            $debug = new Ontario_Obituaries_Debug();
            $debug->init();
            error_log('Ontario Obituaries: Debug handler initialized');
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
            dbDelta($sql);
            error_log('Ontario Obituaries: Database table created');
            
            // Add default data
            ontario_obituaries_add_sample_data();
        } else {
            error_log('Ontario Obituaries: Table already exists, skipping creation');
        }
        
        // Schedule the daily scraper cron job
        if (!wp_next_scheduled('ontario_obituaries_daily_scrape')) {
            wp_schedule_event(time(), 'daily', 'ontario_obituaries_daily_scrape');
            error_log('Ontario Obituaries: Daily scrape scheduled');
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
        // Parse attributes
        $default_atts = array(
            'limit' => 10,
            'location' => '',
            'funeral_home' => '',
            'days' => 30,
        );
        $atts = shortcode_atts($default_atts, $atts, 'ontario_obituaries');
        
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

// Add Facebook settings to the admin menu (simplified from original implementation)
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
}
add_action('admin_init', 'ontario_obituaries_facebook_settings_init');

// Settings page callback (simplified)
function ontario_obituaries_facebook_settings_page() {
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        return;
    }
    
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <p><?php _e('Configure Facebook integration settings for the Ontario Obituaries plugin.', 'ontario-obituaries'); ?></p>
        
        <form action="options.php" method="post">
            <?php
            settings_fields('ontario_obituaries_facebook');
            
            // Get current settings
            $options = get_option('ontario_obituaries_facebook_settings', array(
                'app_id' => '',
                'enabled' => false,
                'group_id' => '',
                'auto_share' => false
            ));
            ?>
            
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php _e('Facebook App ID', 'ontario-obituaries'); ?></th>
                    <td>
                        <input type="text" name="ontario_obituaries_facebook_settings[app_id]" value="<?php echo esc_attr($options['app_id'] ?? ''); ?>" class="regular-text" />
                        <p class="description"><?php _e('Enter your Facebook App ID', 'ontario-obituaries'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Enable Integration', 'ontario-obituaries'); ?></th>
                    <td>
                        <input type="checkbox" name="ontario_obituaries_facebook_settings[enabled]" value="1" <?php checked(isset($options['enabled']) ? $options['enabled'] : false); ?> />
                        <p class="description"><?php _e('Enable Facebook sharing functionality', 'ontario-obituaries'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Facebook Group ID', 'ontario-obituaries'); ?></th>
                    <td>
                        <input type="text" name="ontario_obituaries_facebook_settings[group_id]" value="<?php echo esc_attr($options['group_id'] ?? ''); ?>" class="regular-text" />
                        <p class="description"><?php _e('Enter your Facebook Group ID', 'ontario-obituaries'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Auto-Share', 'ontario-obituaries'); ?></th>
                    <td>
                        <input type="checkbox" name="ontario_obituaries_facebook_settings[auto_share]" value="1" <?php checked(isset($options['auto_share']) ? $options['auto_share'] : false); ?> />
                        <p class="description"><?php _e('Automatically share new obituaries to Facebook', 'ontario-obituaries'); ?></p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button(__('Save Settings', 'ontario-obituaries')); ?>
        </form>
    </div>
    <?php
}

// Modify the Ontario_Obituaries class to fix any potential issues
<lov-write file_path="wp-plugin/includes/class-ontario-obituaries.php">
<?php
/**
 * The main plugin class
 */
class Ontario_Obituaries {
    /**
     * Initialize the plugin
     */
    public function __construct() {
        // Log plugin initialization
        error_log('Ontario Obituaries: Plugin initialization started');
        
        // Load text domain for translations
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        
        // Add hook for the daily scrape
        add_action('ontario_obituaries_daily_scrape', array($this, 'run_daily_scrape'));
        
        // Add scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Add hook for auto-sharing to Facebook
        add_action('ontario_obituaries_new_obituary', array($this, 'auto_share_to_facebook'), 10, 1);
        
        // Log successful initialization
        error_log('Ontario Obituaries: Plugin initialization completed');
    }
    
    /**
     * Run the plugin
     */
    public function run() {
        try {
            // Log plugin run
            error_log('Ontario Obituaries: Plugin run started');
            
            // Initialize admin if class exists
            if (class_exists('Ontario_Obituaries_Admin')) {
                $admin = new Ontario_Obituaries_Admin();
                if (method_exists($admin, 'init')) {
                    $admin->init();
                    error_log('Ontario Obituaries: Admin initialized');
                } else {
                    error_log('Ontario Obituaries: Admin init method not found');
                }
            } else {
                error_log('Ontario Obituaries: Admin class not found');
            }
            
            // Log successful run
            error_log('Ontario Obituaries: Plugin run completed successfully');
        } catch (Exception $e) {
            // Log any exceptions
            error_log('Ontario Obituaries: Error during plugin run: ' . $e->getMessage());
        }
    }
    
    /**
     * Load plugin text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain('ontario-obituaries', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    /**
     * Run the daily scrape
     */
    public function run_daily_scrape() {
        if (class_exists('Ontario_Obituaries_Scraper')) {
            $scraper = new Ontario_Obituaries_Scraper();
            if (method_exists($scraper, 'scrape')) {
                $scraper->scrape();
            } else {
                error_log('Ontario Obituaries: Scraper method not found');
            }
        } else {
            error_log('Ontario Obituaries: Scraper class not found');
        }
    }
    
    /**
     * Enqueue scripts and styles - only on pages with our shortcode
     */
    public function enqueue_scripts() {
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
            
            // Pass data to JavaScript
            wp_localize_script(
                'ontario-obituaries-js',
                'ontarioObituariesData',
                array(
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('ontario-obituaries-nonce')
                )
            );
        }
    }
    
    /**
     * Auto-share new obituary to Facebook group if enabled
     * 
     * @param object $obituary The obituary object
     */
    public function auto_share_to_facebook($obituary) {
        try {
            // Get Facebook settings
            $fb_settings = get_option('ontario_obituaries_facebook_settings', array());
            
            // Check if auto-share and Facebook integration are enabled
            if (
                isset($fb_settings['enabled']) && 
                $fb_settings['enabled'] && 
                isset($fb_settings['auto_share']) && 
                $fb_settings['auto_share'] && 
                !empty($fb_settings['app_id']) && 
                !empty($fb_settings['group_id'])
            ) {
                // Log the auto-share attempt
                error_log('Auto-sharing obituary to Facebook: ' . $obituary->name);
                
                // Prepare the post data
                $post_data = array(
                    'message' => sprintf(
                        __('In Memory of %s (%s - %s)', 'ontario-obituaries'),
                        $obituary->name,
                        !empty($obituary->date_of_birth) ? $obituary->date_of_birth : __('unknown', 'ontario-obituaries'),
                        $obituary->date_of_death
                    ),
                    'link' => add_query_arg(array('obituary_id' => $obituary->id), site_url()),
                );
                
                // In a real implementation, you would use wp_remote_post() to send data to Facebook Graph API
                $this->post_to_facebook_group($fb_settings['group_id'], $post_data);
            }
        } catch (Exception $e) {
            // Log any exceptions during Facebook sharing
            error_log('Ontario Obituaries: Error during Facebook sharing: ' . $e->getMessage());
        }
    }
    
    /**
     * Post to Facebook Group using Graph API
     * 
     * @param string $group_id The Facebook Group ID
     * @param array $post_data The data to post
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    private function post_to_facebook_group($group_id, $post_data) {
        try {
            // Get Facebook settings
            $fb_settings = get_option('ontario_obituaries_facebook_settings', array());
            
            // For demonstration purposes only - this would need to be expanded in a real implementation
            $url = "https://graph.facebook.com/v18.0/{$group_id}/feed";
            
            // Prepare the request arguments
            $args = array(
                'method' => 'POST',
                'timeout' => 45,
                'redirection' => 5,
                'httpversion' => '1.0',
                'blocking' => true,
                'headers' => array(),
                'body' => array(
                    'access_token' => $fb_settings['access_token'] ?? '', // You would need a valid access token
                    'message' => $post_data['message'],
                    'link' => $post_data['link'],
                ),
                'cookies' => array()
            );
            
            // In a real implementation, you would send this request to Facebook
            // $response = wp_remote_post($url, $args);
            
            // For now, just log that we would have posted
            error_log('Would post to Facebook Group: ' . $group_id);
            error_log('Post data: ' . print_r($post_data, true));
            
            return true;
        } catch (Exception $e) {
            // Log any exceptions during Facebook posting
            error_log('Ontario Obituaries: Error during Facebook posting: ' . $e->getMessage());
            return false;
        }
    }
}
