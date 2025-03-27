
<?php
/**
 * Debug functionality for Ontario Obituaries
 */
class Ontario_Obituaries_Debug {
    /**
     * Initialize the debug functionality
     */
    public function init() {
        // Add debug tab to admin menu
        add_action('admin_menu', array($this, 'add_debug_menu'));
        
        // Register AJAX handler for testing the scraper
        add_action('wp_ajax_ontario_obituaries_test_scraper', array($this, 'ajax_test_scraper'));
        
        // Add admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    /**
     * Add debug menu item
     */
    public function add_debug_menu() {
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
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our debug page
        if ($hook != 'obituaries_page_ontario-obituaries-debug') {
            return;
        }
        
        wp_enqueue_style('ontario-obituaries-admin-css');
        
        wp_enqueue_script(
            'ontario-obituaries-debug-js',
            ONTARIO_OBITUARIES_PLUGIN_URL . 'assets/js/ontario-obituaries-debug.js',
            array('jquery'),
            ONTARIO_OBITUARIES_VERSION,
            true
        );
    }
    
    /**
     * Render the debug page
     */
    public function render_debug_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Ontario Obituaries Debug Tools', 'ontario-obituaries'); ?></h1>
            
            <div class="ontario-admin-card">
                <h2><?php _e('Scraper Diagnostics', 'ontario-obituaries'); ?></h2>
                <p><?php _e('Test connections to obituary sources and diagnose issues with the scraper.', 'ontario-obituaries'); ?></p>
                
                <button id="ontario-test-scraper" class="button button-primary">
                    <?php _e('Test Scraper Sources', 'ontario-obituaries'); ?>
                </button>
                
                <div id="ontario-debug-results" class="ontario-debug-results" style="display: none;">
                    <div class="ontario-debug-section">
                        <h3><?php _e('Connection Results', 'ontario-obituaries'); ?></h3>
                        
                        <div class="ontario-debug-connections">
                            <div class="ontario-debug-connection-group">
                                <h4><?php _e('Successful Connections', 'ontario-obituaries'); ?></h4>
                                <div id="ontario-success-connections" class="ontario-connection-list"></div>
                            </div>
                            
                            <div class="ontario-debug-connection-group">
                                <h4><?php _e('Failed Connections', 'ontario-obituaries'); ?></h4>
                                <div id="ontario-failed-connections" class="ontario-connection-list"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="ontario-debug-section">
                        <h3><?php _e('Debug Log', 'ontario-obituaries'); ?></h3>
                        <div id="ontario-debug-log-entries" class="ontario-debug-log"></div>
                    </div>
                    
                    <div class="ontario-debug-section">
                        <h3><?php _e('Troubleshooting', 'ontario-obituaries'); ?></h3>
                        <ul class="ontario-troubleshooting-tips">
                            <li><?php _e('If connections are failing, check that your website has permissions to make outbound HTTP requests.', 'ontario-obituaries'); ?></li>
                            <li><?php _e('Ensure the funeral home websites are accessible and not blocking web scrapers.', 'ontario-obituaries'); ?></li>
                            <li><?php _e('Check that the website structure hasn\'t changed, which could break the scraper selectors.', 'ontario-obituaries'); ?></li>
                            <li><?php _e('Try temporarily disabling other plugins that might be interfering with outbound requests.', 'ontario-obituaries'); ?></li>
                            <li><?php _e('Contact your hosting provider if outbound connections are being blocked.', 'ontario-obituaries'); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
            .ontario-admin-card {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                padding: 20px;
                margin-top: 20px;
                box-shadow: 0 1px 1px rgba(0,0,0,0.04);
            }
            
            .ontario-debug-section {
                margin-top: 25px;
            }
            
            .ontario-debug-connections {
                display: flex;
                flex-wrap: wrap;
                gap: 20px;
            }
            
            .ontario-debug-connection-group {
                flex: 1;
                min-width: 250px;
            }
            
            .ontario-connection-list {
                margin-top: 10px;
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
            }
            
            .ontario-connection-badge {
                display: inline-flex;
                align-items: center;
                padding: 4px 10px;
                border-radius: 20px;
                font-size: 13px;
            }
            
            .ontario-connection-badge.success {
                background-color: #edfaef;
                color: #1e8a3e;
                border: 1px solid #a9e0b6;
            }
            
            .ontario-connection-badge.error {
                background-color: #fcefef;
                color: #b71c1c;
                border: 1px solid #f1c0c0;
            }
            
            .ontario-connection-badge .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
                margin-right: 4px;
            }
            
            .ontario-debug-log {
                background: #f6f7f7;
                border: 1px solid #dcdcde;
                padding: 10px;
                max-height: 300px;
                overflow-y: auto;
                font-family: monospace;
                font-size: 12px;
                line-height: 1.5;
            }
            
            .ontario-log-entry {
                margin-bottom: 4px;
                word-break: break-word;
            }
            
            .ontario-log-timestamp {
                color: #646970;
            }
            
            .ontario-log-message.error {
                color: #b71c1c;
            }
            
            .ontario-log-message.success {
                color: #1e8a3e;
            }
            
            .ontario-troubleshooting-tips {
                padding-left: 20px;
            }
            
            .ontario-troubleshooting-tips li {
                margin-bottom: 8px;
            }
            
            .ontario-spinner {
                display: inline-block;
                width: 16px;
                height: 16px;
                border: 2px solid rgba(0,0,0,0.1);
                border-top-color: #fff;
                border-radius: 50%;
                animation: ontario-spinner 1s linear infinite;
                margin-right: 8px;
            }
            
            @keyframes ontario-spinner {
                to { transform: rotate(360deg); }
            }
        </style>
        <?php
    }
    
    /**
     * AJAX handler for testing the scraper
     */
    public function ajax_test_scraper() {
        // Check nonce
        check_ajax_referer('ontario_obituaries_admin_nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'ontario-obituaries')));
        }
        
        // Initialize log
        $log = array();
        $successful = array();
        $failed = array();
        
        // Get settings
        $settings = get_option('ontario_obituaries_settings', array(
            'regions' => array('Toronto', 'Ottawa', 'Hamilton', 'London', 'Windsor')
        ));
        
        // Get regions
        $regions = isset($settings['regions']) ? $settings['regions'] : array('Toronto', 'Ottawa', 'Hamilton', 'London', 'Windsor');
        
        // Add initial log entry
        $log[] = array(
            'timestamp' => current_time('H:i:s'),
            'type' => 'info',
            'message' => __('Starting test of obituary sources...', 'ontario-obituaries')
        );
        
        // Test each region
        foreach ($regions as $region) {
            // Log test start
            $log[] = array(
                'timestamp' => current_time('H:i:s'),
                'type' => 'info',
                'message' => sprintf(__('Testing connection to %s obituary sources...', 'ontario-obituaries'), $region)
            );
            
            // Create a scraper instance
            $scraper = new Ontario_Obituaries_Scraper();
            
            // Test the connection
            $result = $scraper->test_connection($region);
            
            if ($result['success']) {
                // Connection successful
                $successful[] = $region;
                $log[] = array(
                    'timestamp' => current_time('H:i:s'),
                    'type' => 'success',
                    'message' => sprintf(__('Successfully connected to %s obituary sources.', 'ontario-obituaries'), $region)
                );
                
                // Add details if available
                if (!empty($result['message'])) {
                    $log[] = array(
                        'timestamp' => current_time('H:i:s'),
                        'type' => 'info',
                        'message' => $result['message']
                    );
                }
            } else {
                // Connection failed
                $failed[] = $region;
                $log[] = array(
                    'timestamp' => current_time('H:i:s'),
                    'type' => 'error',
                    'message' => sprintf(__('Failed to connect to %s obituary sources: %s', 'ontario-obituaries'), $region, $result['message'])
                );
            }
        }
        
        // Add summary log entry
        if (count($successful) > 0 && count($failed) == 0) {
            $log[] = array(
                'timestamp' => current_time('H:i:s'),
                'type' => 'success',
                'message' => __('All connections successful! The scraper should work properly.', 'ontario-obituaries')
            );
        } elseif (count($successful) > 0 && count($failed) > 0) {
            $log[] = array(
                'timestamp' => current_time('H:i:s'),
                'type' => 'info',
                'message' => sprintf(__('Testing completed with %d successful and %d failed connections.', 'ontario-obituaries'), count($successful), count($failed))
            );
        } else {
            $log[] = array(
                'timestamp' => current_time('H:i:s'),
                'type' => 'error',
                'message' => __('All connections failed. Check your network and source configurations.', 'ontario-obituaries')
            );
        }
        
        // Send the results
        wp_send_json_success(array(
            'successful' => $successful,
            'failed' => $failed,
            'log' => $log
        ));
    }
}
