
<?php
/**
 * Ontario Obituaries Debug
 * 
 * Adds debugging tools to the admin interface
 */
class Ontario_Obituaries_Debug {
    /**
     * Initialize debug tools
     */
    public function init() {
        // Add debug submenu page
        add_action('admin_menu', array($this, 'add_debug_menu'));
        
        // Add AJAX handler for testing scraper sources
        add_action('wp_ajax_ontario_obituaries_test_scraper', array($this, 'handle_test_scraper'));
    }
    
    /**
     * Add debug menu
     */
    public function add_debug_menu() {
        add_submenu_page(
            'ontario-obituaries',
            __('Scraper Diagnostics', 'ontario-obituaries'),
            __('Diagnostics', 'ontario-obituaries'),
            'manage_options',
            'ontario-obituaries-debug',
            array($this, 'render_debug_page')
        );
    }
    
    /**
     * Render debug page
     */
    public function render_debug_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="ontario-debug-container">
                <div class="ontario-debug-card">
                    <h2><?php _e('Scraper Diagnostics', 'ontario-obituaries'); ?></h2>
                    <p><?php _e('Test the connection to each obituary source to identify issues.', 'ontario-obituaries'); ?></p>
                    
                    <div class="ontario-debug-actions">
                        <button id="ontario-test-scraper" class="button button-primary">
                            <?php _e('Test Scraper Sources', 'ontario-obituaries'); ?>
                        </button>
                    </div>
                    
                    <div id="ontario-debug-results" class="ontario-debug-results" style="display: none;">
                        <h3><?php _e('Test Results', 'ontario-obituaries'); ?></h3>
                        
                        <div class="ontario-debug-status">
                            <div class="ontario-debug-connections">
                                <h4><?php _e('Successful Connections', 'ontario-obituaries'); ?></h4>
                                <div id="ontario-success-connections" class="ontario-connection-badges"></div>
                            </div>
                            
                            <div class="ontario-debug-connections">
                                <h4><?php _e('Failed Connections', 'ontario-obituaries'); ?></h4>
                                <div id="ontario-failed-connections" class="ontario-connection-badges"></div>
                            </div>
                        </div>
                        
                        <div class="ontario-debug-log">
                            <h4><?php _e('Scraper Log', 'ontario-obituaries'); ?></h4>
                            <div id="ontario-debug-log-entries" class="ontario-debug-log-container"></div>
                        </div>
                    </div>
                    
                    <div class="ontario-debug-tips">
                        <h3><?php _e('Troubleshooting Tips', 'ontario-obituaries'); ?></h3>
                        <ul>
                            <li><?php _e('Check that your website has proper permissions to connect to external sites', 'ontario-obituaries'); ?></li>
                            <li><?php _e('Ensure your web host allows outbound connections to the funeral home websites', 'ontario-obituaries'); ?></li>
                            <li><?php _e('Verify the scraper selectors match the current structure of the funeral home websites', 'ontario-obituaries'); ?></li>
                            <li><?php _e('Try increasing the "max_age" setting to find older obituaries', 'ontario-obituaries'); ?></li>
                            <li><?php _e('Check the WordPress debug log for PHP errors', 'ontario-obituaries'); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        
        <?php
        // Load the debug JavaScript
        wp_enqueue_script(
            'ontario-obituaries-debug-js',
            ONTARIO_OBITUARIES_PLUGIN_URL . 'assets/js/ontario-obituaries-debug.js',
            array('jquery'),
            ONTARIO_OBITUARIES_VERSION,
            true
        );
        
        // Add inline styles
        $this->add_debug_styles();
    }
    
    /**
     * Add debug styles
     */
    private function add_debug_styles() {
        ?>
        <style>
            .ontario-debug-card {
                background: #fff;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
                padding: 20px;
                margin-bottom: 20px;
                border-radius: 3px;
            }
            
            .ontario-debug-actions {
                margin-bottom: 20px;
            }
            
            .ontario-debug-results {
                margin-top: 20px;
                padding-top: 20px;
                border-top: 1px solid #eee;
            }
            
            .ontario-debug-status {
                display: flex;
                flex-wrap: wrap;
                gap: 20px;
                margin-bottom: 20px;
            }
            
            .ontario-debug-connections {
                flex: 1;
                min-width: 250px;
            }
            
            .ontario-connection-badges {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                margin-top: 10px;
            }
            
            .ontario-connection-badge {
                display: inline-flex;
                align-items: center;
                padding: 5px 10px;
                border-radius: 15px;
                font-size: 12px;
                font-weight: 500;
            }
            
            .ontario-connection-badge.success {
                background: #edfaef;
                border: 1px solid #c3e6cb;
                color: #155724;
            }
            
            .ontario-connection-badge.error {
                background: #f8d7da;
                border: 1px solid #f5c6cb;
                color: #721c24;
            }
            
            .ontario-debug-log-container {
                height: 250px;
                overflow-y: auto;
                background: #f5f5f5;
                padding: 10px;
                border: 1px solid #ddd;
                border-radius: 3px;
                font-family: monospace;
                font-size: 12px;
                line-height: 1.5;
            }
            
            .ontario-log-entry {
                margin-bottom: 5px;
            }
            
            .ontario-log-timestamp {
                color: #6c757d;
                margin-right: 5px;
            }
            
            .ontario-log-message.error {
                color: #dc3545;
                font-weight: bold;
            }
            
            .ontario-log-message.success {
                color: #28a745;
                font-weight: bold;
            }
            
            .ontario-debug-tips {
                margin-top: 20px;
                padding: 15px;
                background: #fff8e5;
                border-left: 4px solid #ffb900;
            }
            
            .ontario-debug-tips ul {
                margin-left: 20px;
            }
            
            .ontario-spinner {
                display: inline-block;
                width: 20px;
                height: 20px;
                margin-right: 10px;
                border: 2px solid rgba(0, 0, 0, 0.1);
                border-left-color: #0073aa;
                border-radius: 50%;
                animation: ontario-spin 1s linear infinite;
            }
            
            @keyframes ontario-spin {
                to { transform: rotate(360deg); }
            }
        </style>
        <?php
    }
    
    /**
     * Handle test scraper AJAX request
     */
    public function handle_test_scraper() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ontario-obituaries-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'ontario-obituaries')));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to do this.', 'ontario-obituaries')));
        }
        
        // Get settings
        $settings = get_option('ontario_obituaries_settings', array());
        $regions = isset($settings['regions']) ? $settings['regions'] : array('Toronto', 'Ottawa', 'Hamilton');
        
        // Test results
        $log = array();
        $successful = array();
        $failed = array();
        
        // Initialize scraper
        $scraper = new Ontario_Obituaries_Scraper();
        
        // Test each region separately
        foreach ($regions as $region) {
            $log[] = array(
                'timestamp' => current_time('H:i:s'),
                'type' => 'info',
                'message' => sprintf(__('Testing connection to %s sources...', 'ontario-obituaries'), $region)
            );
            
            // Try to scrape this region
            $result = $scraper->test_region($region);
            
            if ($result['success']) {
                $successful[] = $region;
                $log[] = array(
                    'timestamp' => current_time('H:i:s'),
                    'type' => 'success',
                    'message' => sprintf(
                        __('Successfully connected to %s source (found %d obituaries)', 'ontario-obituaries'),
                        $region,
                        $result['count']
                    )
                );
                
                // If no obituaries found despite successful connection
                if ($result['count'] == 0) {
                    $log[] = array(
                        'timestamp' => current_time('H:i:s'),
                        'type' => 'info',
                        'message' => sprintf(
                            __('No obituaries found from %s within the time period. Try increasing maxAge.', 'ontario-obituaries'),
                            $region
                        )
                    );
                }
            } else {
                $failed[] = $region;
                $log[] = array(
                    'timestamp' => current_time('H:i:s'),
                    'type' => 'error',
                    'message' => sprintf(__('Failed to scrape %s: %s', 'ontario-obituaries'), $region, $result['message'])
                );
            }
            
            // Small delay between requests
            usleep(500000); // 0.5 second
        }
        
        // Log completion
        $success_count = count($successful);
        $error_count = count($failed);
        
        if ($success_count && !$error_count) {
            $log[] = array(
                'timestamp' => current_time('H:i:s'),
                'type' => 'success',
                'message' => __('All sources connected successfully. Check WordPress database for obituaries.', 'ontario-obituaries')
            );
        } else if ($success_count && $error_count) {
            $log[] = array(
                'timestamp' => current_time('H:i:s'),
                'type' => 'info',
                'message' => sprintf(
                    __('Test completed with %d successful and %d failed connections.', 'ontario-obituaries'),
                    $success_count,
                    $error_count
                )
            );
        } else {
            $log[] = array(
                'timestamp' => current_time('H:i:s'),
                'type' => 'error',
                'message' => __('No sources could be connected. Check your network and source configurations.', 'ontario-obituaries')
            );
        }
        
        // Send response
        wp_send_json_success(array(
            'log' => $log,
            'successful' => $successful,
            'failed' => $failed
        ));
    }
}
