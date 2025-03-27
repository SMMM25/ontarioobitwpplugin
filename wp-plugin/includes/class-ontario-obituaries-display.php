
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
        
        try {
            // Include the template
            if (file_exists(ONTARIO_OBITUARIES_PLUGIN_DIR . 'templates/obituaries.php')) {
                // Get the obituaries data first
                $args = array(
                    'limit' => intval($atts['limit']),
                    'location' => $atts['location'],
                    'funeral_home' => $atts['funeral_home'],
                    'days' => intval($atts['days']),
                );
                
                // Get search and pagination values from request
                $search = isset($_GET['ontario_obituaries_search']) ? sanitize_text_field($_GET['ontario_obituaries_search']) : '';
                $page = isset($_GET['ontario_obituaries_page']) ? max(1, intval($_GET['ontario_obituaries_page'])) : 1;
                
                // Add to args
                $args['search'] = $search;
                $args['offset'] = (intval($atts['limit']) * ($page - 1));
                
                // Get obituaries
                $obituaries = $this->get_obituaries($args);
                
                // Get total count for pagination
                $total = $this->count_obituaries(array(
                    'location' => $atts['location'],
                    'funeral_home' => $atts['funeral_home'],
                    'days' => intval($atts['days']),
                    'search' => $search
                ));
                
                // Calculate pagination
                $total_pages = ceil($total / intval($atts['limit']));
                
                // Get locations for filter
                $locations = $this->get_locations();
                
                // Get funeral homes for filter
                $funeral_homes = $this->get_funeral_homes();
                
                // Include the template with data available
                include ONTARIO_OBITUARIES_PLUGIN_DIR . 'templates/obituaries.php';
            } else {
                echo '<p>' . __('Error: Obituary template not found.', 'ontario-obituaries') . '</p>';
            }
        } catch (Exception $e) {
            echo '<p>' . __('Error rendering obituaries:', 'ontario-obituaries') . ' ' . esc_html($e->getMessage()) . '</p>';
        }
        
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
        
        try {
            // Check if table exists
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
            if (!$table_exists) {
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
            $where_clauses = array('1=1');
            $values = array();
            
            // Location filter
            if (!empty($args['location'])) {
                $where_clauses[] = 'location = %s';
                $values[] = $args['location'];
            }
            
            // Funeral home filter
            if (!empty($args['funeral_home'])) {
                $where_clauses[] = 'funeral_home = %s';
                $values[] = $args['funeral_home'];
            }
            
            // Days filter
            if (!empty($args['days']) && is_numeric($args['days'])) {
                $where_clauses[] = 'date_of_death >= DATE_SUB(CURDATE(), INTERVAL %d DAY)';
                $values[] = intval($args['days']);
            }
            
            // Search filter
            if (!empty($args['search'])) {
                $where_clauses[] = '(name LIKE %s OR description LIKE %s)';
                $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
                $values[] = $search_term;
                $values[] = $search_term;
            }
            
            // Join where clauses
            $where = implode(' AND ', $where_clauses);
            
            // Orderby - sanitize to prevent SQL injection
            $allowed_orderby = array('date_of_death', 'name', 'location', 'funeral_home');
            $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'date_of_death';
            
            // Order - sanitize to prevent SQL injection
            $order = $args['order'] === 'ASC' ? 'ASC' : 'DESC';
            
            // Prepare the query with limit and offset
            $limit = intval($args['limit']);
            $offset = intval($args['offset']);
            
            $query = "SELECT * FROM $table_name WHERE $where ORDER BY $orderby $order";
            
            // Add limit and offset
            if ($limit > 0) {
                $query .= " LIMIT %d";
                $values[] = $limit;
                
                if ($offset > 0) {
                    $query .= " OFFSET %d";
                    $values[] = $offset;
                }
            }
            
            // Get the obituaries
            $prepared_query = $wpdb->prepare($query, $values);
            $results = $wpdb->get_results($prepared_query);
            
            return $results ?: array();
        } catch (Exception $e) {
            // Log the error but don't expose it
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
                return null;
            }
            
            // Validate ID
            $id = intval($id);
            if ($id <= 0) {
                return null;
            }
            
            // Get the obituary
            $result = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE id = %d",
                $id
            ));
            
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
                return array();
            }
            
            $locations = $wpdb->get_col(
                "SELECT DISTINCT location FROM $table_name WHERE location != '' ORDER BY location"
            );
            
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
                return array();
            }
            
            $funeral_homes = $wpdb->get_col(
                "SELECT DISTINCT funeral_home FROM $table_name WHERE funeral_home != '' ORDER BY funeral_home"
            );
            
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
            $where_clauses = array('1=1');
            $values = array();
            
            // Location filter
            if (!empty($args['location'])) {
                $where_clauses[] = 'location = %s';
                $values[] = $args['location'];
            }
            
            // Funeral home filter
            if (!empty($args['funeral_home'])) {
                $where_clauses[] = 'funeral_home = %s';
                $values[] = $args['funeral_home'];
            }
            
            // Days filter
            if (!empty($args['days']) && is_numeric($args['days'])) {
                $where_clauses[] = 'date_of_death >= DATE_SUB(CURDATE(), INTERVAL %d DAY)';
                $values[] = intval($args['days']);
            }
            
            // Search filter
            if (!empty($args['search'])) {
                $where_clauses[] = '(name LIKE %s OR description LIKE %s)';
                $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
                $values[] = $search_term;
                $values[] = $search_term;
            }
            
            // Join where clauses
            $where = implode(' AND ', $where_clauses);
            
            // Prepare the query
            $query = "SELECT COUNT(*) FROM $table_name WHERE $where";
            
            // Get the count
            $prepared_query = $wpdb->prepare($query, $values);
            $count = $wpdb->get_var($prepared_query);
            
            return intval($count);
        } catch (Exception $e) {
            error_log('Ontario Obituaries: Error counting obituaries: ' . $e->getMessage());
            return 0;
        }
    }
}
