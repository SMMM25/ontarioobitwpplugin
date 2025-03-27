<?php
/**
 * The main plugin class
 */
class Ontario_Obituaries {
    /**
     * Display class instance
     */
    private $display;
    
    /**
     * Initialize the plugin
     */
    public function __construct() {
        // Initialize display class
        $this->display = new Ontario_Obituaries_Display();
        
        // Register our admin menu
        add_action('admin_menu', array($this, 'register_admin_menu'));
        
        // Register our shortcode
        add_shortcode('ontario_obituaries', array($this, 'render_shortcode'));
        
        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        
        // Register REST API endpoints for Obituary Assistant integration
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
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
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('ontario_obituaries_options', 'ontario_obituaries_settings', array(
            'sanitize_callback' => array($this, 'sanitize_settings')
        ));
    }
    
    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        
        // Boolean settings
        $boolean_settings = array('enabled', 'auto_publish', 'notify_admin');
        foreach ($boolean_settings as $setting) {
            $sanitized[$setting] = isset($input[$setting]) ? true : false;
        }
        
        // Text settings
        $text_settings = array('time', 'filter_keywords');
        foreach ($text_settings as $setting) {
            $sanitized[$setting] = isset($input[$setting]) ? sanitize_text_field($input[$setting]) : '';
        }
        
        // Numeric settings
        $numeric_settings = array('max_age');
        foreach ($numeric_settings as $setting) {
            $sanitized[$setting] = isset($input[$setting]) ? intval($input[$setting]) : 0;
        }
        
        // Select settings
        $sanitized['frequency'] = isset($input['frequency']) && in_array($input['frequency'], array('hourly', 'daily', 'twicedaily', 'weekly')) 
            ? $input['frequency'] 
            : 'daily';
        
        // Array settings
        $sanitized['regions'] = isset($input['regions']) && is_array($input['regions']) 
            ? array_map('sanitize_text_field', $input['regions'])
            : array('Toronto', 'Ottawa', 'Hamilton');
        
        return $sanitized;
    }
    
    /**
     * Display the admin page
     */
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Ontario Obituaries', 'ontario-obituaries'); ?></h1>
            
            <div class="card">
                <h2><?php _e('Recent Obituaries', 'ontario-obituaries'); ?></h2>
                <p><?php _e('Here are the most recently added obituaries:', 'ontario-obituaries'); ?></p>
                
                <?php 
                $obituaries = $this->get_recent_obituaries();
                
                if (!empty($obituaries)): ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('Name', 'ontario-obituaries'); ?></th>
                                <th><?php _e('Date of Death', 'ontario-obituaries'); ?></th>
                                <th><?php _e('Location', 'ontario-obituaries'); ?></th>
                                <th><?php _e('Funeral Home', 'ontario-obituaries'); ?></th>
                                <th><?php _e('Actions', 'ontario-obituaries'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($obituaries as $obituary): ?>
                                <tr>
                                    <td><?php echo esc_html($obituary->name); ?></td>
                                    <td><?php echo esc_html($obituary->date_of_death); ?></td>
                                    <td><?php echo esc_html($obituary->location); ?></td>
                                    <td><?php echo esc_html($obituary->funeral_home); ?></td>
                                    <td>
                                        <a href="#" class="ontario-obituaries-view" data-id="<?php echo esc_attr($obituary->id); ?>"><?php _e('View', 'ontario-obituaries'); ?></a> | 
                                        <a href="#" class="ontario-obituaries-delete" data-id="<?php echo esc_attr($obituary->id); ?>" data-nonce="<?php echo wp_create_nonce('ontario-obituaries-admin-nonce'); ?>"><?php _e('Delete', 'ontario-obituaries'); ?></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p><?php _e('No obituaries found. Add some test data by clicking the button below.', 'ontario-obituaries'); ?></p>
                <?php endif; ?>
                
                <p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ontario-obituaries&action=add_test')); ?>" class="button button-primary"><?php _e('Add Test Data', 'ontario-obituaries'); ?></a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ontario-obituaries&action=scrape')); ?>" class="button"><?php _e('Run Manual Scrape', 'ontario-obituaries'); ?></a>
                </p>
            </div>
            
            <div class="card">
                <h2><?php _e('Using the Shortcode', 'ontario-obituaries'); ?></h2>
                <p><?php _e('Use the shortcode <code>[ontario_obituaries]</code> to display obituaries on any page or post.', 'ontario-obituaries'); ?></p>
                <p><?php _e('Optional parameters:', 'ontario-obituaries'); ?></p>
                <ul>
                    <li><code>limit</code> - <?php _e('Number of obituaries to display (default: 20)', 'ontario-obituaries'); ?></li>
                    <li><code>location</code> - <?php _e('Filter by location (default: all locations)', 'ontario-obituaries'); ?></li>
                    <li><code>funeral_home</code> - <?php _e('Filter by funeral home (default: all funeral homes)', 'ontario-obituaries'); ?></li>
                    <li><code>days</code> - <?php _e('Only show obituaries from the last X days (default: 0, show all)', 'ontario-obituaries'); ?></li>
                </ul>
                <p><?php _e('Example:', 'ontario-obituaries'); ?> <code>[ontario_obituaries limit="12" location="Toronto" days="60"]</code></p>
            </div>
            
            <?php if (function_exists('ontario_obituaries_check_dependency') && ontario_obituaries_check_dependency()): ?>
            <div class="card">
                <h2><?php _e('Obituary Assistant Integration', 'ontario-obituaries'); ?></h2>
                <p><?php _e('This plugin is integrated with Obituary Assistant for enhanced functionality.', 'ontario-obituaries'); ?></p>
                <p><?php _e('Obituary Assistant can access Ontario Obituaries data through its API.', 'ontario-obituaries'); ?></p>
            </div>
            <?php endif; ?>
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
        // Get settings
        $settings = get_option('ontario_obituaries_settings', array(
            'enabled' => true,
            'frequency' => 'daily',
            'time' => '03:00',
            'regions' => array('Toronto', 'Ottawa', 'Hamilton'),
            'max_age' => 7,
            'filter_keywords' => '',
            'auto_publish' => true,
            'notify_admin' => true
        ));
        
        ?>
        <div class="wrap">
            <h1><?php _e('Ontario Obituaries Settings', 'ontario-obituaries'); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('ontario_obituaries_options');
                ?>
                
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php _e('Enable Scraper', 'ontario-obituaries'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="ontario_obituaries_settings[enabled]" value="1" <?php checked(isset($settings['enabled']) ? $settings['enabled'] : true); ?> />
                                <?php _e('Automatically collect obituaries from Ontario', 'ontario-obituaries'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php _e('Scrape Frequency', 'ontario-obituaries'); ?></th>
                        <td>
                            <select name="ontario_obituaries_settings[frequency]">
                                <option value="hourly" <?php selected(isset($settings['frequency']) ? $settings['frequency'] : 'daily', 'hourly'); ?>><?php _e('Hourly', 'ontario-obituaries'); ?></option>
                                <option value="twicedaily" <?php selected(isset($settings['frequency']) ? $settings['frequency'] : 'daily', 'twicedaily'); ?>><?php _e('Twice Daily', 'ontario-obituaries'); ?></option>
                                <option value="daily" <?php selected(isset($settings['frequency']) ? $settings['frequency'] : 'daily', 'daily'); ?>><?php _e('Daily', 'ontario-obituaries'); ?></option>
                                <option value="weekly" <?php selected(isset($settings['frequency']) ? $settings['frequency'] : 'daily', 'weekly'); ?>><?php _e('Weekly', 'ontario-obituaries'); ?></option>
                            </select>
                            <p class="description"><?php _e('How often to check for new obituaries', 'ontario-obituaries'); ?></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php _e('Regions to Scrape', 'ontario-obituaries'); ?></th>
                        <td>
                            <?php $regions = array('Toronto', 'Ottawa', 'Hamilton', 'London', 'Windsor', 'Kitchener', 'Sudbury', 'Thunder Bay'); ?>
                            <?php foreach ($regions as $region): ?>
                                <label style="display: block; margin-bottom: 8px;">
                                    <input type="checkbox" name="ontario_obituaries_settings[regions][]" value="<?php echo esc_attr($region); ?>" <?php checked(in_array($region, isset($settings['regions']) ? $settings['regions'] : array())); ?> />
                                    <?php echo esc_html($region); ?>
                                </label>
                            <?php endforeach; ?>
                            <p class="description"><?php _e('Select which regions to collect obituaries from', 'ontario-obituaries'); ?></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php _e('Auto-Publish', 'ontario-obituaries'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="ontario_obituaries_settings[auto_publish]" value="1" <?php checked(isset($settings['auto_publish']) ? $settings['auto_publish'] : true); ?> />
                                <?php _e('Automatically publish new obituaries without review', 'ontario-obituaries'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php _e('Email Notifications', 'ontario-obituaries'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="ontario_obituaries_settings[notify_admin]" value="1" <?php checked(isset($settings['notify_admin']) ? $settings['notify_admin'] : true); ?> />
                                <?php _e('Send email when new obituaries are found', 'ontario-obituaries'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php _e('Retention Period (days)', 'ontario-obituaries'); ?></th>
                        <td>
                            <input type="number" name="ontario_obituaries_settings[max_age]" value="<?php echo esc_attr(isset($settings['max_age']) ? $settings['max_age'] : 7); ?>" min="1" max="365" />
                            <p class="description"><?php _e('How many days to keep obituaries before archiving', 'ontario-obituaries'); ?></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php _e('Filtering Keywords', 'ontario-obituaries'); ?></th>
                        <td>
                            <input type="text" class="regular-text" name="ontario_obituaries_settings[filter_keywords]" value="<?php echo esc_attr(isset($settings['filter_keywords']) ? $settings['filter_keywords'] : ''); ?>" placeholder="<?php _e('Enter comma-separated terms', 'ontario-obituaries'); ?>" />
                            <p class="description"><?php _e('Optional: Filter obituaries containing these terms', 'ontario-obituaries'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(__('Save Settings', 'ontario-obituaries')); ?>
            </form>
            
            <hr>
            
            <h2><?php _e('Manual Control', 'ontario-obituaries'); ?></h2>
            <p><?php _e('Click the button below to manually run the scraper:', 'ontario-obituaries'); ?></p>
            
            <form method="post" action="">
                <?php wp_nonce_field('ontario_obituaries_manual_scrape', 'ontario_obituaries_nonce'); ?>
                <p>
                    <input type="submit" name="ontario_obituaries_scrape" class="button button-primary" value="<?php _e('Run Scraper Now', 'ontario-obituaries'); ?>">
                </p>
            </form>
            
            <?php
            // Handle manual scrape
            if (isset($_POST['ontario_obituaries_scrape']) && check_admin_referer('ontario_obituaries_manual_scrape', 'ontario_obituaries_nonce')) {
                $scraper = new Ontario_Obituaries_Scraper();
                $results = $scraper->scrape();
                
                echo '<div class="notice notice-success is-dismissible"><p>';
                printf(
                    __('Scraper completed. Checked %d sources, found %d obituaries, added %d new obituaries.', 'ontario-obituaries'),
                    $results['sources_scraped'],
                    $results['obituaries_found'],
                    $results['obituaries_added']
                );
                echo '</p></div>';
                
                if (!empty($results['errors'])) {
                    echo '<div class="notice notice-warning is-dismissible"><p>';
                    _e('The following errors were encountered:', 'ontario-obituaries');
                    echo '</p><ul>';
                    
                    foreach ($results['errors'] as $source_id => $errors) {
                        foreach ((array)$errors as $error) {
                            echo '<li><strong>' . esc_html($source_id) . '</strong>: ' . esc_html($error) . '</li>';
                        }
                    }
                    
                    echo '</ul></div>';
                }
            }
            ?>
            
            <hr>
            
            <h2><?php _e('Last Scrape Information', 'ontario-obituaries'); ?></h2>
            <?php
            $last_scrape = get_option('ontario_obituaries_last_scrape');
            
            if ($last_scrape) {
                echo '<p><strong>' . __('Last Run:', 'ontario-obituaries') . '</strong> ' . esc_html($last_scrape['completed']) . '</p>';
                
                if (isset($last_scrape['results'])) {
                    $results = $last_scrape['results'];
                    echo '<p><strong>' . __('Results:', 'ontario-obituaries') . '</strong> ';
                    printf(
                        __('Checked %d sources, found %d obituaries, added %d new obituaries.', 'ontario-obituaries'),
                        $results['sources_scraped'],
                        $results['obituaries_found'],
                        $results['obituaries_added']
                    );
                    echo '</p>';
                    
                    if (!empty($results['errors'])) {
                        echo '<p><strong>' . __('Errors:', 'ontario-obituaries') . '</strong></p>';
                        echo '<ul>';
                        
                        foreach ($results['errors'] as $source_id => $errors) {
                            foreach ((array)$errors as $error) {
                                echo '<li><strong>' . esc_html($source_id) . '</strong>: ' . esc_html($error) . '</li>';
                            }
                        }
                        
                        echo '</ul>';
                    }
                }
            } else {
                echo '<p>' . __('No scrapes have been run yet.', 'ontario-obituaries') . '</p>';
            }
            ?>
        </div>
        <?php
    }
    
    /**
     * Render the shortcode
     */
    public function render_shortcode($atts) {
        // Set default attributes
        $atts = shortcode_atts(array(
            'limit' => get_option('ontario_obituaries_items_per_page', 20),
            'location' => get_option('ontario_obituaries_default_location', ''),
            'funeral_home' => '',
            'days' => 0,
        ), $atts, 'ontario_obituaries');
        
        // Render obituaries
        return $this->display->render_obituaries($atts);
    }
    
    /**
     * Get recent obituaries for admin dashboard
     */
    public function get_recent_obituaries($limit = 10) {
        return $this->display->get_obituaries(array(
            'limit' => $limit, 
            'orderby' => 'date_of_death', 
            'order' => 'DESC'
        ));
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
                'date_of_birth' => date('Y-m-d', strtotime('-80 years')),
                'date_of_death' => date('Y-m-d', strtotime('-2 days')),
                'age' => 80,
                'funeral_home' => 'Toronto Funeral Services',
                'location' => 'Toronto',
                'image_url' => 'https://via.placeholder.com/150',
                'description' => 'This is a test obituary for demonstration purposes. John enjoyed gardening, fishing, and spending time with his family.',
                'source_url' => home_url()
            ),
            array(
                'name' => 'Mary Test Johnson',
                'date_of_birth' => date('Y-m-d', strtotime('-75 years')),
                'date_of_death' => date('Y-m-d', strtotime('-3 days')),
                'age' => 75,
                'funeral_home' => 'Ottawa Funeral Home',
                'location' => 'Ottawa',
                'image_url' => 'https://via.placeholder.com/150',
                'description' => 'This is another test obituary. Mary was a dedicated teacher for over 30 years and loved by all her students.',
                'source_url' => home_url()
            ),
            array(
                'name' => 'Robert Test Williams',
                'date_of_birth' => date('Y-m-d', strtotime('-90 years')),
                'date_of_death' => date('Y-m-d', strtotime('-1 day')),
                'age' => 90,
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
                    'date_of_birth' => $obituary['date_of_birth'],
                    'date_of_death' => $obituary['date_of_death'],
                    'age' => $obituary['age'],
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
            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(__('Added %d test obituaries successfully.', 'ontario-obituaries'), $count) . '</p></div>';
        });
        
        // Redirect back to admin page
        wp_redirect(admin_url('admin.php?page=ontario-obituaries'));
        exit;
    }
    
    /**
     * Run a manual scrape
     */
    private function run_scrape() {
        // Load the scraper class
        if (!class_exists('Ontario_Obituaries_Scraper')) {
            require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries-scraper.php';
        }
        
        // Run the scraper
        $scraper = new Ontario_Obituaries_Scraper();
        $results = $scraper->scrape();
        
        // Add admin notice
        add_action('admin_notices', function() use ($results) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            printf(
                __('Scraper completed. Checked %d sources, found %d obituaries, added %d new obituaries.', 'ontario-obituaries'),
                $results['sources_scraped'],
                $results['obituaries_found'],
                $results['obituaries_added']
            );
            echo '</p></div>';
            
            if (!empty($results['errors'])) {
                echo '<div class="notice notice-warning is-dismissible"><p>';
                _e('The following errors were encountered:', 'ontario-obituaries');
                echo '</p><ul>';
                
                foreach ($results['errors'] as $source_id => $errors) {
                    foreach ((array)$errors as $error) {
                        echo '<li><strong>' . esc_html($source_id) . '</strong>: ' . esc_html($error) . '</li>';
                    }
                }
                
                echo '</ul></div>';
            }
        });
        
        // Redirect back to admin page
        wp_redirect(admin_url('admin.php?page=ontario-obituaries'));
        exit;
    }
    
    /**
     * Register REST API routes for integration
     */
    public function register_rest_routes() {
        register_rest_route('ontario-obituaries/v1', '/obituaries', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_obituaries_api'),
            'permission_callback' => '__return_true'
        ));
        
        register_rest_route('ontario-obituaries/v1', '/obituary/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_obituary_api'),
            'permission_callback' => '__return_true',
            'args' => array(
                'id' => array(
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                )
            )
        ));
    }
    
    /**
     * API endpoint to get obituaries
     */
    public function get_obituaries_api($request) {
        $params = $request->get_params();
        
        $args = array(
            'limit' => isset($params['limit']) ? intval($params['limit']) : 20,
            'offset' => isset($params['offset']) ? intval($params['offset']) : 0,
            'location' => isset($params['location']) ? sanitize_text_field($params['location']) : '',
            'funeral_home' => isset($params['funeral_home']) ? sanitize_text_field($params['funeral_home']) : '',
            'days' => isset($params['days']) ? intval($params['days']) : 0,
            'search' => isset($params['search']) ? sanitize_text_field($params['search']) : '',
            'orderby' => isset($params['orderby']) ? sanitize_text_field($params['orderby']) : 'date_of_death',
            'order' => isset($params['order']) ? sanitize_text_field($params['order']) : 'DESC'
        );
        
        $obituaries = $this->display->get_obituaries($args);
        $total = $this->display->count_obituaries($args);
        
        return array(
            'total' => $total,
            'obituaries' => $obituaries
        );
    }
    
    /**
     * API endpoint to get a single obituary
     */
    public function get_obituary_api($request) {
        $id = $request->get_param('id');
        $obituary = $this->display->get_obituary($id);
        
        if (!$obituary) {
            return new WP_Error('not_found', __('Obituary not found', 'ontario-obituaries'), array('status' => 404));
        }
        
        return $obituary;
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
                'nonce' => wp_create_nonce('ontario-obituaries-nonce'),
                'enable_facebook' => get_option('ontario_obituaries_enable_facebook', '1')
            ));
            
            // Add Facebook scripts if enabled
            if (get_option('ontario_obituaries_enable_facebook', '1')) {
                wp_enqueue_script(
                    'ontario-obituaries-facebook',
                    ONTARIO_OBITUARIES_PLUGIN_URL . 'assets/js/ontario-obituaries-facebook.js',
                    array('jquery', 'ontario-obituaries-js'),
                    ONTARIO_OBITUARIES_VERSION,
                    true
                );
            }
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
            
            // Add ajax url
            wp_localize_script('ontario-obituaries-admin-js', 'ontario_obituaries_admin', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ontario-obituaries-admin-nonce'),
                'confirm_delete' => __('Are you sure you want to delete this obituary?', 'ontario-obituaries')
            ));
        }
    }
}
