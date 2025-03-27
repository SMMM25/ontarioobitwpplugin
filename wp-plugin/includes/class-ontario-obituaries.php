
<?php
/**
 * The main plugin class
 */
class Ontario_Obituaries {
    /**
     * Initialize the plugin
     */
    public function __construct() {
        // Register our admin menu
        add_action('admin_menu', array($this, 'register_admin_menu'));
        
        // Register our shortcode
        add_shortcode('ontario_obituaries', array($this, 'render_shortcode'));
        
        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
    }
    
    /**
     * Register the admin menu
     */
    public function register_admin_menu() {
        add_menu_page(
            'Ontario Obituaries',
            'Ontario Obituaries',
            'manage_options',
            'ontario-obituaries',
            array($this, 'admin_page'),
            'dashicons-list-view',
            30
        );
        
        // Add settings subpage
        add_submenu_page(
            'ontario-obituaries',
            'Settings',
            'Settings',
            'manage_options',
            'ontario-obituaries-settings',
            array($this, 'settings_page')
        );
    }
    
    /**
     * Display the admin page
     */
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>Ontario Obituaries</h1>
            
            <div class="card">
                <h2>Recent Obituaries</h2>
                <p>Here are the most recently added obituaries:</p>
                
                <?php 
                $obituaries = $this->get_recent_obituaries();
                
                if (!empty($obituaries)): ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Date of Death</th>
                                <th>Location</th>
                                <th>Funeral Home</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($obituaries as $obituary): ?>
                                <tr>
                                    <td><?php echo esc_html($obituary->name); ?></td>
                                    <td><?php echo esc_html($obituary->date_of_death); ?></td>
                                    <td><?php echo esc_html($obituary->location); ?></td>
                                    <td><?php echo esc_html($obituary->funeral_home); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No obituaries found. Add some test data by clicking the button below.</p>
                <?php endif; ?>
                
                <p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ontario-obituaries&action=add_test')); ?>" class="button button-primary">Add Test Data</a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ontario-obituaries&action=scrape')); ?>" class="button">Run Manual Scrape</a>
                </p>
            </div>
            
            <div class="card">
                <h2>Using the Shortcode</h2>
                <p>Use the shortcode <code>[ontario_obituaries]</code> to display obituaries on any page or post.</p>
                <p>Optional parameters:</p>
                <ul>
                    <li><code>limit</code> - Number of obituaries to display (default: 10)</li>
                    <li><code>location</code> - Filter by location (default: all locations)</li>
                    <li><code>funeral_home</code> - Filter by funeral home (default: all funeral homes)</li>
                    <li><code>days</code> - Only show obituaries from the last X days (default: 30)</li>
                </ul>
                <p>Example: <code>[ontario_obituaries limit="12" location="Toronto" days="60"]</code></p>
            </div>
        </div>
        <?php
        
        // Handle actions
        if (isset($_GET['action'])) {
            switch ($_GET['action']) {
                case 'add_test':
                    $this->add_test_data();
                    break;
                case 'scrape':
                    $this->run_scrape();
                    break;
            }
        }
    }
    
    /**
     * Display the settings page
     */
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Ontario Obituaries Settings</h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('ontario_obituaries_options');
                do_settings_sections('ontario_obituaries_settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Render the shortcode
     */
    public function render_shortcode($atts) {
        // Set default attributes
        $atts = shortcode_atts(array(
            'limit' => 10,
            'location' => '',
            'funeral_home' => '',
            'days' => 30,
        ), $atts);
        
        // Get obituaries
        $obituaries = $this->get_obituaries($atts);
        
        // Start output buffering
        ob_start();
        
        // Include the template
        include(ONTARIO_OBITUARIES_PLUGIN_DIR . 'templates/obituaries.php');
        
        // Return the buffered content
        return ob_get_clean();
    }
    
    /**
     * Get obituaries from the database
     */
    public function get_obituaries($args = array()) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ontario_obituaries';
        
        // Default arguments
        $defaults = array(
            'limit' => 10,
            'offset' => 0,
            'location' => '',
            'funeral_home' => '',
            'days' => 30,
            'search' => '',
        );
        
        // Parse incoming args
        $args = wp_parse_args($args, $defaults);
        
        // Start building query
        $sql = "SELECT * FROM $table_name WHERE 1=1";
        
        // Add where clauses
        if (!empty($args['location'])) {
            $sql .= $wpdb->prepare(" AND location = %s", $args['location']);
        }
        
        if (!empty($args['funeral_home'])) {
            $sql .= $wpdb->prepare(" AND funeral_home = %s", $args['funeral_home']);
        }
        
        if (!empty($args['days'])) {
            $date = date('Y-m-d', strtotime('-' . intval($args['days']) . ' days'));
            $sql .= $wpdb->prepare(" AND date_of_death >= %s", $date);
        }
        
        if (!empty($args['search'])) {
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $sql .= $wpdb->prepare(" AND (name LIKE %s OR description LIKE %s)", $search, $search);
        }
        
        // Add order and limit
        $sql .= " ORDER BY date_of_death DESC";
        
        if (!empty($args['limit'])) {
            $sql .= $wpdb->prepare(" LIMIT %d", intval($args['limit']));
            
            if (!empty($args['offset'])) {
                $sql .= $wpdb->prepare(" OFFSET %d", intval($args['offset']));
            }
        }
        
        // Get results
        $results = $wpdb->get_results($sql);
        
        return $results;
    }
    
    /**
     * Get recent obituaries for admin dashboard
     */
    public function get_recent_obituaries($limit = 10) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ontario_obituaries';
        
        $sql = $wpdb->prepare("SELECT * FROM $table_name ORDER BY date_of_death DESC LIMIT %d", $limit);
        $results = $wpdb->get_results($sql);
        
        return $results;
    }
    
    /**
     * Count obituaries in the database
     */
    public function count_obituaries($args = array()) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ontario_obituaries';
        
        // Default arguments
        $defaults = array(
            'location' => '',
            'funeral_home' => '',
            'days' => 30,
            'search' => '',
        );
        
        // Parse incoming args
        $args = wp_parse_args($args, $defaults);
        
        // Start building query
        $sql = "SELECT COUNT(*) FROM $table_name WHERE 1=1";
        
        // Add where clauses
        if (!empty($args['location'])) {
            $sql .= $wpdb->prepare(" AND location = %s", $args['location']);
        }
        
        if (!empty($args['funeral_home'])) {
            $sql .= $wpdb->prepare(" AND funeral_home = %s", $args['funeral_home']);
        }
        
        if (!empty($args['days'])) {
            $date = date('Y-m-d', strtotime('-' . intval($args['days']) . ' days'));
            $sql .= $wpdb->prepare(" AND date_of_death >= %s", $date);
        }
        
        if (!empty($args['search'])) {
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $sql .= $wpdb->prepare(" AND (name LIKE %s OR description LIKE %s)", $search, $search);
        }
        
        // Get count
        $count = $wpdb->get_var($sql);
        
        return $count;
    }
    
    /**
     * Get locations from the database
     */
    public function get_locations() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ontario_obituaries';
        
        $sql = "SELECT DISTINCT location FROM $table_name ORDER BY location ASC";
        $results = $wpdb->get_col($sql);
        
        return $results;
    }
    
    /**
     * Add test data to the database
     */
    private function add_test_data() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ontario_obituaries';
        
        // Sample test data
        $test_data = array(
            array(
                'name' => 'John Test Smith',
                'date_of_death' => date('Y-m-d', strtotime('-2 days')),
                'funeral_home' => 'Toronto Funeral Services',
                'location' => 'Toronto',
                'image_url' => 'https://via.placeholder.com/150',
                'description' => 'This is a test obituary for demonstration purposes. John enjoyed gardening, fishing, and spending time with his family.',
                'source_url' => home_url()
            ),
            array(
                'name' => 'Mary Test Johnson',
                'date_of_death' => date('Y-m-d', strtotime('-3 days')),
                'funeral_home' => 'Ottawa Funeral Home',
                'location' => 'Ottawa',
                'image_url' => 'https://via.placeholder.com/150',
                'description' => 'This is another test obituary. Mary was a dedicated teacher for over 30 years and loved by all her students.',
                'source_url' => home_url()
            ),
            array(
                'name' => 'Robert Test Williams',
                'date_of_death' => date('Y-m-d', strtotime('-1 day')),
                'funeral_home' => 'Hamilton Funeral Services',
                'location' => 'Hamilton',
                'image_url' => 'https://via.placeholder.com/150',
                'description' => 'Robert was a respected community leader and devoted family man. This is a test obituary for debugging purposes.',
                'source_url' => home_url()
            )
        );
        
        $count = 0;
        
        // Insert test data
        foreach ($test_data as $obituary) {
            $wpdb->insert(
                $table_name,
                array(
                    'name' => $obituary['name'],
                    'date_of_death' => $obituary['date_of_death'],
                    'funeral_home' => $obituary['funeral_home'],
                    'location' => $obituary['location'],
                    'image_url' => $obituary['image_url'],
                    'description' => $obituary['description'],
                    'source_url' => $obituary['source_url'],
                    'created_at' => current_time('mysql')
                )
            );
            
            if ($wpdb->insert_id) {
                $count++;
            }
        }
        
        // Add admin notice
        add_action('admin_notices', function() use ($count) {
            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf('Added %d test obituaries successfully.', $count) . '</p></div>';
        });
        
        // Redirect back to admin page
        wp_redirect(admin_url('admin.php?page=ontario-obituaries'));
        exit;
    }
    
    /**
     * Run a manual scrape
     */
    private function run_scrape() {
        // For now, just add more test data with different dates
        global $wpdb;
        $table_name = $wpdb->prefix . 'ontario_obituaries';
        
        // Sample test data with older dates
        $test_data = array(
            array(
                'name' => 'Emily Test Wilson',
                'date_of_death' => date('Y-m-d', strtotime('-8 days')),
                'funeral_home' => 'Windsor Memorial',
                'location' => 'Windsor',
                'image_url' => 'https://via.placeholder.com/150',
                'description' => 'Emily was known for her kindness and generosity. This is a test obituary added by manual scrape.',
                'source_url' => home_url()
            ),
            array(
                'name' => 'David Test Brown',
                'date_of_death' => date('Y-m-d', strtotime('-12 days')),
                'funeral_home' => 'London Funeral Home',
                'location' => 'London',
                'image_url' => 'https://via.placeholder.com/150',
                'description' => 'David was an accomplished musician and loving father. This is a test obituary added by manual scrape.',
                'source_url' => home_url()
            )
        );
        
        $count = 0;
        
        // Insert test data
        foreach ($test_data as $obituary) {
            $wpdb->insert(
                $table_name,
                array(
                    'name' => $obituary['name'],
                    'date_of_death' => $obituary['date_of_death'],
                    'funeral_home' => $obituary['funeral_home'],
                    'location' => $obituary['location'],
                    'image_url' => $obituary['image_url'],
                    'description' => $obituary['description'],
                    'source_url' => $obituary['source_url'],
                    'created_at' => current_time('mysql')
                )
            );
            
            if ($wpdb->insert_id) {
                $count++;
            }
        }
        
        // Add admin notice
        add_action('admin_notices', function() use ($count) {
            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf('Added %d obituaries from manual scrape.', $count) . '</p></div>';
        });
        
        // Redirect back to admin page
        wp_redirect(admin_url('admin.php?page=ontario-obituaries'));
        exit;
    }
    
    /**
     * Enqueue scripts and styles for the frontend
     */
    public function enqueue_scripts() {
        // Only enqueue on pages with our shortcode
        global $post;
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'ontario_obituaries')) {
            wp_enqueue_style('dashicons');
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
            
            // Add ajax url
            wp_localize_script('ontario-obituaries-js', 'ontario_obituaries_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ontario_obituaries_nonce')
            ));
        }
    }
    
    /**
     * Enqueue scripts and styles for the admin
     */
    public function admin_enqueue_scripts($hook) {
        // Only enqueue on our admin pages
        if (strpos($hook, 'ontario-obituaries') !== false) {
            wp_enqueue_style(
                'ontario-obituaries-admin-css',
                ONTARIO_OBITUARIES_PLUGIN_URL . 'assets/css/ontario-obituaries-admin.css',
                array(),
                ONTARIO_OBITUARIES_VERSION
            );
            
            wp_enqueue_script(
                'ontario-obituaries-admin-js',
                ONTARIO_OBITUARIES_PLUGIN_URL . 'assets/js/ontario-obituaries-admin.js',
                array('jquery'),
                ONTARIO_OBITUARIES_VERSION,
                true
            );
        }
    }
}
