
<?php
/**
 * Ontario Obituaries Display
 * 
 * Handles the frontend display of obituaries
 */
class Ontario_Obituaries_Display {
    /**
     * Render obituaries
     * 
     * @param array $atts Shortcode attributes
     * @return string The rendered HTML
     */
    public function render_obituaries($atts) {
        // Debug log
        error_log('Ontario Obituaries: Starting to render obituaries with attributes: ' . print_r($atts, true));
        
        // Parse attributes
        $atts = shortcode_atts(array(
            'limit' => 20,
            'location' => '',
            'funeral_home' => '',
            'days' => 0,
        ), $atts, 'ontario_obituaries');
        
        // Start output buffering
        ob_start();
        
        try {
            // Include the template
            if (file_exists(ONTARIO_OBITUARIES_PLUGIN_DIR . 'templates/obituaries.php')) {
                include_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'templates/obituaries.php';
                error_log('Ontario Obituaries: Template included successfully');
            } else {
                error_log('Ontario Obituaries: Template file not found at: ' . ONTARIO_OBITUARIES_PLUGIN_DIR . 'templates/obituaries.php');
                echo '<p>Error: Obituary template not found.</p>';
            }
        } catch (Exception $e) {
            error_log('Ontario Obituaries: Error including template: ' . $e->getMessage());
            echo '<p>Error rendering obituaries: ' . esc_html($e->getMessage()) . '</p>';
        }
        
        // Return the buffered content
        $content = ob_get_clean();
        error_log('Ontario Obituaries: Rendering complete, content length: ' . strlen($content));
        return $content;
    }
    
    /**
     * Get obituaries
     * 
     * @param array $args Query arguments
     * @return array The obituaries
     */
    public function get_obituaries($args = array()) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ontario_obituaries';
        
        // Debug log
        error_log('Ontario Obituaries: Getting obituaries with args: ' . print_r($args, true));
        
        try {
            // Check if table exists
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
            if (!$table_exists) {
                error_log("Ontario Obituaries: Table $table_name does not exist");
                return array(); // Return empty array if table doesn't exist
            }
            
            // Default arguments
            $defaults = array(
                'limit' => 20,
                'offset' => 0,
                'location' => '',
                'funeral_home' => '',
                'days' => 0,
                'search' => '',
                'orderby' => 'date_of_death',
                'order' => 'DESC'
            );
            
            // Parse arguments
            $args = wp_parse_args($args, $defaults);
            
            // Build query
            $where = '1=1';
            $values = array();
            
            // Location filter
            if (!empty($args['location'])) {
                $where .= ' AND location = %s';
                $values[] = $args['location'];
            }
            
            // Funeral home filter
            if (!empty($args['funeral_home'])) {
                $where .= ' AND funeral_home = %s';
                $values[] = $args['funeral_home'];
            }
            
            // Days filter
            if (!empty($args['days']) && is_numeric($args['days'])) {
                $where .= ' AND date_of_death >= DATE_SUB(CURDATE(), INTERVAL %d DAY)';
                $values[] = intval($args['days']);
            }
            
            // Search filter
            if (!empty($args['search'])) {
                $where .= ' AND (name LIKE %s OR description LIKE %s)';
                $values[] = '%' . $wpdb->esc_like($args['search']) . '%';
                $values[] = '%' . $wpdb->esc_like($args['search']) . '%';
            }
            
            // Orderby
            $allowed_orderby = array('date_of_death', 'name', 'location', 'funeral_home');
            $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'date_of_death';
            
            // Order
            $order = $args['order'] === 'ASC' ? 'ASC' : 'DESC';
            
            // Prepare the query with limit and offset
            $query = "SELECT * FROM $table_name WHERE $where ORDER BY $orderby $order LIMIT %d OFFSET %d";
            $values[] = intval($args['limit']);
            $values[] = intval($args['offset']);
            
            // Get the obituaries
            $prepared_query = $wpdb->prepare($query, $values);
            error_log('Ontario Obituaries: Executing query: ' . $prepared_query);
            
            $results = $wpdb->get_results($prepared_query);
            
            if ($wpdb->last_error) {
                error_log('Ontario Obituaries: Database error: ' . $wpdb->last_error);
            }
            
            error_log('Ontario Obituaries: Found ' . count($results) . ' obituaries');
            return $results ?: array();
        } catch (Exception $e) {
            error_log('Ontario Obituaries: Error getting obituaries: ' . $e->getMessage());
            return array();
        }
    }
    
    /**
     * Get obituary by ID
     * 
     * @param int $id The obituary ID
     * @return object|null The obituary object or null if not found
     */
    public function get_obituary($id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ontario_obituaries';
        
        try {
            // Check if table exists
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
            if (!$table_exists) {
                error_log("Ontario Obituaries: Table $table_name does not exist");
                return null;
            }
            
            $result = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE id = %d",
                $id
            ));
            
            if ($wpdb->last_error) {
                error_log('Ontario Obituaries: Database error: ' . $wpdb->last_error);
            }
            
            return $result;
        } catch (Exception $e) {
            error_log('Ontario Obituaries: Error getting obituary: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get unique locations
     * 
     * @return array The unique locations
     */
    public function get_locations() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ontario_obituaries';
        
        try {
            // Check if table exists
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
            if (!$table_exists) {
                error_log("Ontario Obituaries: Table $table_name does not exist");
                return array();
            }
            
            $locations = $wpdb->get_col(
                "SELECT DISTINCT location FROM $table_name ORDER BY location"
            );
            
            if ($wpdb->last_error) {
                error_log('Ontario Obituaries: Database error: ' . $wpdb->last_error);
            }
            
            return $locations ?: array();
        } catch (Exception $e) {
            error_log('Ontario Obituaries: Error getting locations: ' . $e->getMessage());
            return array();
        }
    }
    
    /**
     * Get unique funeral homes
     * 
     * @return array The unique funeral homes
     */
    public function get_funeral_homes() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ontario_obituaries';
        
        try {
            // Check if table exists
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
            if (!$table_exists) {
                error_log("Ontario Obituaries: Table $table_name does not exist");
                return array();
            }
            
            $funeral_homes = $wpdb->get_col(
                "SELECT DISTINCT funeral_home FROM $table_name ORDER BY funeral_home"
            );
            
            if ($wpdb->last_error) {
                error_log('Ontario Obituaries: Database error: ' . $wpdb->last_error);
            }
            
            return $funeral_homes ?: array();
        } catch (Exception $e) {
            error_log('Ontario Obituaries: Error getting funeral homes: ' . $e->getMessage());
            return array();
        }
    }
    
    /**
     * Count obituaries
     * 
     * @param array $args Query arguments
     * @return int The number of obituaries
     */
    public function count_obituaries($args = array()) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ontario_obituaries';
        
        try {
            // Check if table exists
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
            if (!$table_exists) {
                error_log("Ontario Obituaries: Table $table_name does not exist");
                return 0;
            }
            
            // Default arguments
            $defaults = array(
                'location' => '',
                'funeral_home' => '',
                'days' => 0,
                'search' => ''
            );
            
            // Parse arguments
            $args = wp_parse_args($args, $defaults);
            
            // Build query
            $where = '1=1';
            $values = array();
            
            // Location filter
            if (!empty($args['location'])) {
                $where .= ' AND location = %s';
                $values[] = $args['location'];
            }
            
            // Funeral home filter
            if (!empty($args['funeral_home'])) {
                $where .= ' AND funeral_home = %s';
                $values[] = $args['funeral_home'];
            }
            
            // Days filter
            if (!empty($args['days']) && is_numeric($args['days'])) {
                $where .= ' AND date_of_death >= DATE_SUB(CURDATE(), INTERVAL %d DAY)';
                $values[] = intval($args['days']);
            }
            
            // Search filter
            if (!empty($args['search'])) {
                $where .= ' AND (name LIKE %s OR description LIKE %s)';
                $values[] = '%' . $wpdb->esc_like($args['search']) . '%';
                $values[] = '%' . $wpdb->esc_like($args['search']) . '%';
            }
            
            // Prepare the query
            $query = "SELECT COUNT(*) FROM $table_name WHERE $where";
            
            // Get the count
            $prepared_query = $wpdb->prepare($query, $values);
            $count = $wpdb->get_var($prepared_query);
            
            if ($wpdb->last_error) {
                error_log('Ontario Obituaries: Database error: ' . $wpdb->last_error);
                return 0;
            }
            
            return (int)$count;
        } catch (Exception $e) {
            error_log('Ontario Obituaries: Error counting obituaries: ' . $e->getMessage());
            return 0;
        }
    }
}
