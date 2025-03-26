
<?php
/**
 * Ontario Obituaries AJAX Handler
 * 
 * Handles AJAX requests for the plugin
 */
class Ontario_Obituaries_Ajax {
    /**
     * Initialize AJAX handlers
     */
    public function init() {
        // Add AJAX actions
        add_action('wp_ajax_ontario_obituaries_get_detail', array($this, 'get_obituary_detail'));
        add_action('wp_ajax_nopriv_ontario_obituaries_get_detail', array($this, 'get_obituary_detail'));
        
        // Admin AJAX actions
        add_action('wp_ajax_ontario_obituaries_update_page', array($this, 'update_page_settings'));
    }
    
    /**
     * Get obituary detail
     */
    public function get_obituary_detail() {
        // Check nonce
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'ontario-obituaries-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'ontario-obituaries')));
        }
        
        // Check obituary ID
        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            wp_send_json_error(array('message' => __('Invalid obituary ID.', 'ontario-obituaries')));
        }
        
        $id = intval($_GET['id']);
        
        // Get the obituary
        $display = new Ontario_Obituaries_Display();
        $obituary = $display->get_obituary($id);
        
        if (!$obituary) {
            wp_send_json_error(array('message' => __('Obituary not found.', 'ontario-obituaries')));
        }
        
        // Start output buffering
        ob_start();
        
        // Include the template
        include ONTARIO_OBITUARIES_PLUGIN_DIR . 'templates/obituary-detail.php';
        
        // Get the buffered content
        $html = ob_get_clean();
        
        // Send the response
        wp_send_json_success(array(
            'html' => $html
        ));
    }
    
    /**
     * Update page settings
     */
    public function update_page_settings() {
        // Check if user has permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to do this.', 'ontario-obituaries')));
        }
        
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ontario-obituaries-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'ontario-obituaries')));
        }
        
        // Get page title
        $page_title = isset($_POST['page_title']) ? sanitize_text_field($_POST['page_title']) : 'Ontario Obituaries';
        
        // Get the settings
        $settings = get_option('ontario_obituaries_settings', array());
        
        // Update the settings
        $settings['page_title'] = $page_title;
        update_option('ontario_obituaries_settings', $settings);
        
        // Get the page ID
        $page_id = get_option('ontario_obituaries_page_id');
        
        // Update the page
        if ($page_id) {
            wp_update_post(array(
                'ID'         => $page_id,
                'post_title' => $page_title
            ));
        }
        
        // Send the response
        wp_send_json_success(array(
            'message' => __('Page settings updated successfully.', 'ontario-obituaries')
        ));
    }
}
