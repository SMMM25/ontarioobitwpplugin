
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
        
        // Add Admin AJAX actions
        add_action('wp_ajax_ontario_obituaries_delete', array($this, 'delete_obituary'));
        
        // Integration with Obituary Assistant
        if (function_exists('ontario_obituaries_check_dependency') && ontario_obituaries_check_dependency()) {
            add_action('wp_ajax_obituary_assistant_get_ontario', array($this, 'get_obituaries_for_assistant'));
            add_action('wp_ajax_nopriv_obituary_assistant_get_ontario', array($this, 'get_obituaries_for_assistant'));
        }
    }
    
    /**
     * Get obituary detail
     */
    public function get_obituary_detail() {
        // Check nonce
        check_ajax_referer('ontario-obituaries-nonce', 'nonce');
        
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
            'html' => $html,
            'obituary' => $obituary
        ));
    }
    
    /**
     * Delete obituary
     */
    public function delete_obituary() {
        // Check nonce
        check_ajax_referer('ontario-obituaries-admin-nonce', 'nonce');
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to do this.', 'ontario-obituaries')));
        }
        
        // Check obituary ID
        if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
            wp_send_json_error(array('message' => __('Invalid obituary ID.', 'ontario-obituaries')));
        }
        
        $id = intval($_POST['id']);
        
        // Delete the obituary
        global $wpdb;
        $table_name = $wpdb->prefix . 'ontario_obituaries';
        $result = $wpdb->delete($table_name, array('id' => $id), array('%d'));
        
        if ($result === false) {
            wp_send_json_error(array('message' => __('Failed to delete obituary.', 'ontario-obituaries')));
        }
        
        // Send success response
        wp_send_json_success(array(
            'message' => __('Obituary deleted successfully.', 'ontario-obituaries'),
            'id' => $id
        ));
    }
    
    /**
     * Get obituaries for Obituary Assistant
     */
    public function get_obituaries_for_assistant() {
        // Check if the request is from Obituary Assistant
        if (!isset($_GET['source']) || $_GET['source'] !== 'obituary_assistant') {
            wp_send_json_error(array('message' => __('Invalid request.', 'ontario-obituaries')));
        }
        
        // Get the obituaries
        $display = new Ontario_Obituaries_Display();
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
        $obituaries = $display->get_obituaries(array('limit' => $limit));
        
        // Format the data for Obituary Assistant
        $formatted_data = array();
        foreach ($obituaries as $obituary) {
            $formatted_data[] = array(
                'id' => $obituary->id,
                'name' => $obituary->name,
                'date_of_birth' => $obituary->date_of_birth,
                'date_of_death' => $obituary->date_of_death,
                'age' => $obituary->age,
                'location' => $obituary->location,
                'funeral_home' => $obituary->funeral_home,
                'image' => $obituary->image_url,
                'description' => $obituary->description,
                'source' => $obituary->source_url,
                'source_name' => 'Ontario Obituaries'
            );
        }
        
        // Send the response
        wp_send_json_success($formatted_data);
    }
}
