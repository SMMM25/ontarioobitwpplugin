
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
}
