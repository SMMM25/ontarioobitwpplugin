
<?php
/**
 * The main plugin class
 */
class Ontario_Obituaries {
    /**
     * Initialize the plugin
     */
    public function __construct() {
        // Load text domain for translations
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        
        // Add hook for the daily scrape
        add_action('ontario_obituaries_daily_scrape', array($this, 'run_daily_scrape'));
        
        // Add scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Add hook for auto-sharing to Facebook
        add_action('ontario_obituaries_new_obituary', array($this, 'auto_share_to_facebook'), 10, 1);
    }
    
    /**
     * Run the plugin
     */
    public function run() {
        // Initialize admin
        $admin = new Ontario_Obituaries_Admin();
        $admin->init();
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
        $scraper = new Ontario_Obituaries_Scraper();
        $scraper->scrape();
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
            // This is a simplified version - in a real implementation, 
            // you would use Facebook's Graph API to post to the group
            
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
            // This is just a placeholder to demonstrate the concept
            $this->post_to_facebook_group($fb_settings['group_id'], $post_data);
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
        // Get Facebook settings
        $fb_settings = get_option('ontario_obituaries_facebook_settings', array());
        
        // This is a simplified implementation - in a real scenario,
        // you would need to handle Facebook authentication properly
        
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
    }
}
