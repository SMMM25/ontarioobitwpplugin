<?php
/**
 * Debug class for Ontario Obituaries
 */
class Ontario_Obituaries_Debug {
    /**
     * Initialize the debug class
     */
    public function init() {
        // Add admin page for debug tools
        add_action('admin_menu', array($this, 'add_debug_page'));
        
        // Add AJAX handlers
        add_action('wp_ajax_ontario_obituaries_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_ontario_obituaries_save_region_url', array($this, 'ajax_save_region_url'));
        add_action('wp_ajax_ontario_obituaries_add_test_data', array($this, 'ajax_add_test_data'));
        add_action('wp_ajax_ontario_obituaries_check_database', array($this, 'ajax_check_database'));
        add_action('wp_ajax_ontario_obituaries_run_scrape', array($this, 'ajax_run_scrape'));
    }
    
    /**
     * Add debug page to admin menu
     */
    public function add_debug_page() {
        add_submenu_page(
            'ontario-obituaries',
            __('Debug Tools', 'ontario-obituaries'),
            __('Debug Tools', 'ontario-obituaries'),
            'manage_options',
            'ontario-obituaries-debug',
            array($this, 'render_debug_page')
        );
    }
    
    /**
     * Render the debug page
     */
    public function render_debug_page() {
        // Get settings
        $settings = get_option('ontario_obituaries_settings', array(
            'regions' => array('Toronto', 'Ottawa', 'Hamilton', 'London', 'Windsor')
        ));
        
        // Get regions
        $regions = isset($settings['regions']) ? $settings['regions'] : array('Toronto', 'Ottawa', 'Hamilton', 'London', 'Windsor');
        
        ?>
        <div class="wrap ontario-obituaries-debug">
            <h1><?php _e('Ontario Obituaries Debug Tools', 'ontario-obituaries'); ?></h1>
            
            <div class="card">
                <h2><?php _e('Database Tools', 'ontario-obituaries'); ?></h2>
                <p><?php _e('Tools to check and manage the database.', 'ontario-obituaries'); ?></p>
                
                <div class="ontario-debug-actions" style="margin-bottom: 20px;">
                    <button id="ontario-check-database" class="button button-secondary">
                        <?php _e('Check Database', 'ontario-obituaries'); ?>
                    </button>
                    
                    <button id="ontario-add-test-data" class="button button-secondary">
                        <?php _e('Add Test Data', 'ontario-obituaries'); ?>
                    </button>
                    
                    <button id="ontario-run-scrape" class="button button-primary">
                        <?php _e('Run Manual Scrape', 'ontario-obituaries'); ?>
                    </button>
                </div>
            </div>
            
            <div class="card">
                <h2><?php _e('Test Scraper Connections', 'ontario-obituaries'); ?></h2>
                <p><?php _e('Test the connection to each region\'s obituary sources.', 'ontario-obituaries'); ?></p>
                
                <div class="ontario-debug-actions">
                    <button id="ontario-test-all-regions" class="button button-primary">
                        <?php _e('Test All Regions', 'ontario-obituaries'); ?>
                    </button>
                </div>
                
                <div class="ontario-debug-regions">
                    <h3><?php _e('Configure Regions', 'ontario-obituaries'); ?></h3>
                    <table class="widefat fixed" cellspacing="0">
                        <thead>
                            <tr>
                                <th><?php _e('Region', 'ontario-obituaries'); ?></th>
                                <th><?php _e('Source URL', 'ontario-obituaries'); ?></th>
                                <th><?php _e('Actions', 'ontario-obituaries'); ?></th>
                                <th><?php _e('Status', 'ontario-obituaries'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $scraper = new Ontario_Obituaries_Scraper();
                            foreach ($regions as $region) :
                                $url = $scraper->get_region_url($region);
                            ?>
                                <tr>
                                    <td><?php echo esc_html($region); ?></td>
                                    <td>
                                        <input type="text" class="widefat region-url" 
                                               data-region="<?php echo esc_attr($region); ?>" 
                                               value="<?php echo esc_attr($url); ?>">
                                    </td>
                                    <td>
                                        <button class="button test-region" data-region="<?php echo esc_attr($region); ?>">
                                            <?php _e('Test Connection', 'ontario-obituaries'); ?>
                                        </button>
                                    </td>
                                    <td>
                                        <span class="region-status" data-region="<?php echo esc_attr($region); ?>">
                                            <?php _e('Not tested', 'ontario-obituaries'); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="ontario-debug-logs">
                    <h3><?php _e('Debug Logs', 'ontario-obituaries'); ?></h3>
                    <div id="ontario-debug-log" class="ontario-debug-log">
                        <div class="log-item log-info">[<?php echo date('H:i:s'); ?>] <?php _e('Debug interface loaded. Test connections to see logs.', 'ontario-obituaries'); ?></div>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <h2><?php _e('Troubleshooting Tips', 'ontario-obituaries'); ?></h2>
                <ul>
                    <li><?php _e('Check that your website has proper permissions to connect to external sites', 'ontario-obituaries'); ?></li>
                    <li><?php _e('Ensure your web host allows outbound connections to the funeral home websites', 'ontario-obituaries'); ?></li>
                    <li><?php _e('Verify the scraper selectors in the plugin settings match the current structure of the funeral home websites', 'ontario-obituaries'); ?></li>
                    <li><?php _e('Try increasing the "maxAge" setting to find older obituaries', 'ontario-obituaries'); ?></li>
                    <li><?php _e('Check the WordPress debug log for PHP errors', 'ontario-obituaries'); ?></li>
                    <li><?php _e('If the debug tool is not working, try temporarily disabling other plugins that might conflict with AJAX requests', 'ontario-obituaries'); ?></li>
                    <li><?php _e('If no obituaries are showing, try adding test data using the "Add Test Data" button above', 'ontario-obituaries'); ?></li>
                </ul>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX handler for testing connection to a region
     */
    public function ajax_test_connection() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ontario-obituaries-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'ontario-obituaries')));
        }
        
        // Check if we have a region
        if (!isset($_POST['region']) || empty($_POST['region'])) {
            wp_send_json_error(array('message' => __('No region specified', 'ontario-obituaries')));
        }
        
        $region = sanitize_text_field($_POST['region']);
        
        // Check if we have a URL
        if (!isset($_POST['url']) || empty($_POST['url'])) {
            wp_send_json_error(array('message' => __('No URL specified', 'ontario-obituaries')));
        }
        
        $url = esc_url_raw($_POST['url']);
        
        // Update URL for this region
        $this->save_region_url($region, $url);
        
        // Test the connection
        try {
            $scraper = new Ontario_Obituaries_Scraper();
            $result = $scraper->test_connection($region);
            
            if ($result['success']) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result);
            }
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * AJAX handler for saving region URL
     */
    public function ajax_save_region_url() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ontario-obituaries-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'ontario-obituaries')));
        }
        
        // Check if we have a region
        if (!isset($_POST['region']) || empty($_POST['region'])) {
            wp_send_json_error(array('message' => __('No region specified', 'ontario-obituaries')));
        }
        
        $region = sanitize_text_field($_POST['region']);
        
        // Check if we have a URL
        if (!isset($_POST['url']) || empty($_POST['url'])) {
            wp_send_json_error(array('message' => __('No URL specified', 'ontario-obituaries')));
        }
        
        $url = esc_url_raw($_POST['url']);
        
        // Save the URL
        $result = $this->save_region_url($region, $url);
        
        if ($result) {
            wp_send_json_success(array('message' => __('URL saved successfully', 'ontario-obituaries')));
        } else {
            wp_send_json_error(array('message' => __('Failed to save URL', 'ontario-obituaries')));
        }
    }
    
    /**
     * AJAX handler for adding test data
     */
    public function ajax_add_test_data() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ontario-obituaries-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'ontario-obituaries')));
        }
        
        // Add test data
        $scraper = new Ontario_Obituaries_Scraper();
        $result = $scraper->add_test_data();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX handler for checking database
     */
    public function ajax_check_database() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ontario-obituaries-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'ontario-obituaries')));
        }
        
        // Check database
        $scraper = new Ontario_Obituaries_Scraper();
        $result = $scraper->check_database();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX handler for running manual scrape
     */
    public function ajax_run_scrape() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ontario-obituaries-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'ontario-obituaries')));
        }
        
        // Run scrape
        $scraper = new Ontario_Obituaries_Scraper();
        $count = $scraper->scrape();
        
        wp_send_json_success(array(
            'message' => sprintf(__('Scrape completed. Found %d new obituaries.', 'ontario-obituaries'), $count)
        ));
    }
    
    /**
     * Save a region URL
     * 
     * @param string $region Region name
     * @param string $url URL to save
     * @return bool Success or failure
     */
    private function save_region_url($region, $url) {
        if (empty($region) || empty($url)) {
            return false;
        }
        
        // Get existing URLs
        $region_urls = get_option('ontario_obituaries_region_urls', array());
        
        // Update the URL for this region
        $region_urls[$region] = $url;
        
        // Save the updated URLs
        return update_option('ontario_obituaries_region_urls', $region_urls);
    }
}
