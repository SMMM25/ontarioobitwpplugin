
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
        // Parse attributes
        $atts = shortcode_atts(array(
            'limit' => 20,
            'location' => '',
            'funeral_home' => '',
            'days' => 0,
        ), $atts, 'ontario_obituaries');
        
        // Start output buffering
        ob_start();
        
        // Include the template
        include_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'templates/obituaries.php';
        
        // Return the buffered content
        return ob_get_clean();
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
        
        // Prepare the query
        $query = "SELECT * FROM $table_name WHERE $where ORDER BY $orderby $order LIMIT %d OFFSET %d";
        $values[] = intval($args['limit']);
        $values[] = intval($args['offset']);
        
        // Get the obituaries
        return $wpdb->get_results($wpdb->prepare($query, $values));
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
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $id
        ));
    }
    
    /**
     * Get unique locations
     * 
     * @return array The unique locations
     */
    public function get_locations() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ontario_obituaries';
        
        return $wpdb->get_col(
            "SELECT DISTINCT location FROM $table_name ORDER BY location"
        );
    }
    
    /**
     * Get unique funeral homes
     * 
     * @return array The unique funeral homes
     */
    public function get_funeral_homes() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ontario_obituaries';
        
        return $wpdb->get_col(
            "SELECT DISTINCT funeral_home FROM $table_name ORDER BY funeral_home"
        );
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
        return $wpdb->get_var($wpdb->prepare($query, $values));
    }
}
