<?php
/**
 * Ontario Obituaries Admin
 * 
 * Handles the admin interface
 */
class Ontario_Obituaries_Admin {
    /**
     * Initialize admin
     */
    public function init() {
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Add admin AJAX handlers
        add_action('wp_ajax_ontario_obituaries_manual_scrape', array($this, 'handle_manual_scrape'));
        
        // Add admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        // Only enqueue on our plugin pages
        if (strpos($hook, 'ontario-obituaries') === false) {
            return;
        }
        
        wp_enqueue_script(
            'ontario-obituaries-admin-js',
            ONTARIO_OBITUARIES_PLUGIN_URL . 'assets/js/ontario-obituaries-admin.js',
            array('jquery'),
            ONTARIO_OBITUARIES_VERSION,
            true
        );
        
        wp_localize_script('ontario-obituaries-admin-js', 'ontario_obituaries_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ontario-obituaries-admin-nonce'),
            'confirm_delete' => __('Are you sure you want to delete this obituary? This action cannot be undone.', 'ontario-obituaries'),
            'deleting' => __('Deleting...', 'ontario-obituaries')
        ));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Ontario Obituaries', 'ontario-obituaries'),
            __('Obituaries', 'ontario-obituaries'),
            'manage_options',
            'ontario-obituaries',
            array($this, 'render_settings_page'),
            'dashicons-book-alt',
            30
        );
        
        add_submenu_page(
            'ontario-obituaries',
            __('Settings', 'ontario-obituaries'),
            __('Settings', 'ontario-obituaries'),
            'manage_options',
            'ontario-obituaries',
            array($this, 'render_settings_page')
        );
        
        add_submenu_page(
            'ontario-obituaries',
            __('Obituaries List', 'ontario-obituaries'),
            __('Obituaries List', 'ontario-obituaries'),
            'manage_options',
            'ontario-obituaries-list',
            array($this, 'render_obituaries_list')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('ontario_obituaries_settings', 'ontario_obituaries_settings');
        
        add_settings_section(
            'ontario_obituaries_general',
            __('General Settings', 'ontario-obituaries'),
            array($this, 'render_general_section'),
            'ontario_obituaries_settings'
        );
        
        add_settings_field(
            'enabled',
            __('Enable Scraper', 'ontario-obituaries'),
            array($this, 'render_enabled_field'),
            'ontario_obituaries_settings',
            'ontario_obituaries_general'
        );
        
        add_settings_field(
            'frequency',
            __('Scrape Frequency', 'ontario-obituaries'),
            array($this, 'render_frequency_field'),
            'ontario_obituaries_settings',
            'ontario_obituaries_general'
        );
        
        add_settings_field(
            'time',
            __('Scrape Time', 'ontario-obituaries'),
            array($this, 'render_time_field'),
            'ontario_obituaries_settings',
            'ontario_obituaries_general'
        );
        
        add_settings_field(
            'regions',
            __('Ontario Regions', 'ontario-obituaries'),
            array($this, 'render_regions_field'),
            'ontario_obituaries_settings',
            'ontario_obituaries_general'
        );
        
        add_settings_field(
            'max_age',
            __('Retention Period (days)', 'ontario-obituaries'),
            array($this, 'render_max_age_field'),
            'ontario_obituaries_settings',
            'ontario_obituaries_general'
        );
        
        add_settings_field(
            'filter_keywords',
            __('Filtering Keywords', 'ontario-obituaries'),
            array($this, 'render_filter_keywords_field'),
            'ontario_obituaries_settings',
            'ontario_obituaries_general'
        );
        
        add_settings_section(
            'ontario_obituaries_display',
            __('Display Settings', 'ontario-obituaries'),
            array($this, 'render_display_section'),
            'ontario_obituaries_settings'
        );
        
        add_settings_field(
            'auto_publish',
            __('Auto-Publish', 'ontario-obituaries'),
            array($this, 'render_auto_publish_field'),
            'ontario_obituaries_settings',
            'ontario_obituaries_display'
        );
        
        add_settings_field(
            'notify_admin',
            __('Email Notifications', 'ontario-obituaries'),
            array($this, 'render_notify_admin_field'),
            'ontario_obituaries_settings',
            'ontario_obituaries_display'
        );
    }
    
    /**
     * Render general section
     */
    public function render_general_section() {
        echo '<p>' . __('Configure how the obituary scraper works on your site', 'ontario-obituaries') . '</p>';
    }
    
    /**
     * Render display section
     */
    public function render_display_section() {
        echo '<p>' . __('Control how obituaries are shown on your website', 'ontario-obituaries') . '</p>';
    }
    
    /**
     * Get settings
     */
    private function get_settings() {
        $defaults = array(
            'enabled' => true,
            'frequency' => 'daily',
            'time' => '03:00',
            'regions' => array('Toronto', 'Ottawa', 'Hamilton'),
            'max_age' => 7,
            'filter_keywords' => '',
            'auto_publish' => true,
            'notify_admin' => true
        );
        
        $settings = get_option('ontario_obituaries_settings', array());
        return wp_parse_args($settings, $defaults);
    }
    
    /**
     * Render enabled field
     */
    public function render_enabled_field() {
        $settings = $this->get_settings();
        ?>
        <label for="ontario_obituaries_settings[enabled]">
            <input type="checkbox" id="ontario_obituaries_settings[enabled]" name="ontario_obituaries_settings[enabled]" value="1" <?php checked(1, $settings['enabled']); ?> />
            <?php _e('Automatically collect obituaries from Ontario', 'ontario-obituaries'); ?>
        </label>
        <?php
    }
    
    /**
     * Render frequency field
     */
    public function render_frequency_field() {
        $settings = $this->get_settings();
        ?>
        <select id="ontario_obituaries_settings[frequency]" name="ontario_obituaries_settings[frequency]">
            <option value="hourly" <?php selected('hourly', $settings['frequency']); ?>><?php _e('Hourly', 'ontario-obituaries'); ?></option>
            <option value="daily" <?php selected('daily', $settings['frequency']); ?>><?php _e('Daily', 'ontario-obituaries'); ?></option>
            <option value="weekly" <?php selected('weekly', $settings['frequency']); ?>><?php _e('Weekly', 'ontario-obituaries'); ?></option>
        </select>
        <p class="description"><?php _e('How often to check for new obituaries', 'ontario-obituaries'); ?></p>
        <?php
    }
    
    /**
     * Render time field
     */
    public function render_time_field() {
        $settings = $this->get_settings();
        ?>
        <input type="time" id="ontario_obituaries_settings[time]" name="ontario_obituaries_settings[time]" value="<?php echo esc_attr($settings['time']); ?>" />
        <p class="description"><?php _e('When to run the scraper (24h format)', 'ontario-obituaries'); ?></p>
        <?php
    }
    
    /**
     * Render regions field
     */
    public function render_regions_field() {
        $settings = $this->get_settings();
        $regions = array('Toronto', 'Ottawa', 'Hamilton', 'London', 'Windsor', 'Kitchener', 'Sudbury', 'Thunder Bay');
        
        foreach ($regions as $region) {
            $checked = in_array($region, $settings['regions']) ? 'checked' : '';
            ?>
            <label style="display: inline-block; margin-right: 15px; margin-bottom: 10px;">
                <input type="checkbox" name="ontario_obituaries_settings[regions][]" value="<?php echo esc_attr($region); ?>" <?php echo $checked; ?> />
                <?php echo esc_html($region); ?>
            </label>
            <?php
        }
        
        echo '<p class="description">' . __('Select the regions to collect obituaries from', 'ontario-obituaries') . '</p>';
    }
    
    /**
     * Render max age field
     */
    public function render_max_age_field() {
        $settings = $this->get_settings();
        ?>
        <input type="number" id="ontario_obituaries_settings[max_age]" name="ontario_obituaries_settings[max_age]" value="<?php echo esc_attr($settings['max_age']); ?>" min="1" max="365" />
        <p class="description"><?php _e('How many days to keep obituaries before archiving', 'ontario-obituaries'); ?></p>
        <?php
    }
    
    /**
     * Render filter keywords field
     */
    public function render_filter_keywords_field() {
        $settings = $this->get_settings();
        ?>
        <input type="text" id="ontario_obituaries_settings[filter_keywords]" name="ontario_obituaries_settings[filter_keywords]" value="<?php echo esc_attr($settings['filter_keywords']); ?>" class="regular-text" placeholder="<?php _e('Enter comma-separated terms', 'ontario-obituaries'); ?>" />
        <p class="description"><?php _e('Optional: Filter obituaries containing these terms', 'ontario-obituaries'); ?></p>
        <?php
    }
    
    /**
     * Render auto publish field
     */
    public function render_auto_publish_field() {
        $settings = $this->get_settings();
        ?>
        <label for="ontario_obituaries_settings[auto_publish]">
            <input type="checkbox" id="ontario_obituaries_settings[auto_publish]" name="ontario_obituaries_settings[auto_publish]" value="1" <?php checked(1, $settings['auto_publish']); ?> />
            <?php _e('Automatically publish new obituaries without review', 'ontario-obituaries'); ?>
        </label>
        <?php
    }
    
    /**
     * Render notify admin field
     */
    public function render_notify_admin_field() {
        $settings = $this->get_settings();
        ?>
        <label for="ontario_obituaries_settings[notify_admin]">
            <input type="checkbox" id="ontario_obituaries_settings[notify_admin]" name="ontario_obituaries_settings[notify_admin]" value="1" <?php checked(1, $settings['notify_admin']); ?> />
            <?php _e('Send email when new obituaries are found', 'ontario-obituaries'); ?>
        </label>
        <?php
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Check if a manual scrape was requested
        if (isset($_POST['ontario_obituaries_manual_scrape']) && check_admin_referer('ontario_obituaries_manual_scrape')) {
            $scraper = new Ontario_Obituaries_Scraper();
            $log = $scraper->scrape();
            
            if (!empty($log['errors'])) {
                $this->show_admin_notice('error', __('Scraping completed with errors. Please check the logs.', 'ontario-obituaries'));
            } else {
                $this->show_admin_notice('success', sprintf(
                    __('Scraping completed successfully. Found %d new obituaries.', 'ontario-obituaries'),
                    $log['obituaries_added']
                ));
            }
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form action="options.php" method="post">
                <?php
                settings_fields('ontario_obituaries_settings');
                do_settings_sections('ontario_obituaries_settings');
                submit_button(__('Save Settings', 'ontario-obituaries'));
                ?>
            </form>
            
            <hr>
            
            <h2><?php _e('Manual Controls', 'ontario-obituaries'); ?></h2>
            
            <form method="post" action="">
                <?php wp_nonce_field('ontario_obituaries_manual_scrape'); ?>
                <p><?php _e('Manually trigger the obituary scraper', 'ontario-obituaries'); ?></p>
                <input type="submit" name="ontario_obituaries_manual_scrape" class="button button-secondary" value="<?php _e('Scrape Now', 'ontario-obituaries'); ?>">
            </form>
            
            <hr>
            
            <h2><?php _e('Status Information', 'ontario-obituaries'); ?></h2>
            
            <?php $last_scrape = get_option('ontario_obituaries_last_scrape'); ?>
            
            <?php if ($last_scrape): ?>
                <div class="ontario-obituaries-status">
                    <p>
                        <strong><?php _e('Last Scraped:', 'ontario-obituaries'); ?></strong>
                        <?php echo esc_html($last_scrape['completed']); ?>
                    </p>
                    <p>
                        <strong><?php _e('Results:', 'ontario-obituaries'); ?></strong>
                        <?php printf(
                            __('%d sources scraped, %d obituaries found, %d new obituaries added', 'ontario-obituaries'),
                            $last_scrape['sources_scraped'],
                            $last_scrape['obituaries_found'],
                            $last_scrape['obituaries_added']
                        ); ?>
                    </p>
                    
                    <?php if (!empty($last_scrape['errors'])): ?>
                        <div class="ontario-obituaries-errors">
                            <h3><?php _e('Errors:', 'ontario-obituaries'); ?></h3>
                            <ul>
                                <?php foreach ($last_scrape['errors'] as $error): ?>
                                    <li><?php echo esc_html($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p><?php _e('No scraping has been performed yet.', 'ontario-obituaries'); ?></p>
            <?php endif; ?>
            
            <hr>
            
            <h2><?php _e('Shortcode Usage', 'ontario-obituaries'); ?></h2>
            <p><?php _e('Use the following shortcode to display obituaries on any page or post:', 'ontario-obituaries'); ?></p>
            <code>[ontario_obituaries]</code>
            
            <p><?php _e('You can also specify parameters:', 'ontario-obituaries'); ?></p>
            <code>[ontario_obituaries limit="10" location="Toronto"]</code>
        </div>
        <?php
    }
    
    /**
     * Render obituaries list
     */
    public function render_obituaries_list() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Get obituaries
        global $wpdb;
        $table_name = $wpdb->prefix . 'ontario_obituaries';
        
        // Handle pagination
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $limit = 20;
        $offset = ($page - 1) * $limit;
        
        // Handle filtering
        $where = '1=1';
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        if ($search) {
            $where .= $wpdb->prepare(" AND (name LIKE %s OR description LIKE %s)", "%{$search}%", "%{$search}%");
        }
        
        $location_filter = isset($_GET['location']) ? sanitize_text_field($_GET['location']) : '';
        if ($location_filter) {
            $where .= $wpdb->prepare(" AND location = %s", $location_filter);
        }
        
        // Get total count
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE $where");
        
        // Get obituaries
        $obituaries = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE $where ORDER BY date_of_death DESC LIMIT %d OFFSET %d",
                $limit,
                $offset
            )
        );
        
        // Get locations for filter
        $locations = $wpdb->get_col("SELECT DISTINCT location FROM $table_name ORDER BY location");
        
        ?>
        <div class="wrap">
            <h1><?php _e('Obituaries List', 'ontario-obituaries'); ?></h1>
            
            <form method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr($_GET['page']); ?>">
                
                <div class="tablenav top">
                    <div class="alignleft actions">
                        <select name="location">
                            <option value=""><?php _e('All Locations', 'ontario-obituaries'); ?></option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?php echo esc_attr($location); ?>" <?php selected($location_filter, $location); ?>>
                                    <?php echo esc_html($location); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="submit" class="button" value="<?php _e('Filter', 'ontario-obituaries'); ?>">
                    </div>
                    
                    <div class="alignright">
                        <p class="search-box">
                            <label class="screen-reader-text" for="ontario-obituaries-search"><?php _e('Search Obituaries:', 'ontario-obituaries'); ?></label>
                            <input type="search" id="ontario-obituaries-search" name="s" value="<?php echo esc_attr($search); ?>">
                            <input type="submit" class="button" value="<?php _e('Search Obituaries', 'ontario-obituaries'); ?>">
                        </p>
                    </div>
                    
                    <div class="tablenav-pages">
                        <?php
                        $total_pages = ceil($total / $limit);
                        
                        if ($total_pages > 1) {
                            $page_links = paginate_links(array(
                                'base' => add_query_arg('paged', '%#%'),
                                'format' => '',
                                'prev_text' => __('&laquo;'),
                                'next_text' => __('&raquo;'),
                                'total' => $total_pages,
                                'current' => $page
                            ));
                            
                            echo '<span class="pagination-links">' . $page_links . '</span>';
                        }
                        ?>
                    </div>
                </div>
            </form>
            
            <table class="wp-list-table widefat fixed striped obituaries-list">
                <thead>
                    <tr>
                        <th scope="col" class="column-primary"><?php _e('Name', 'ontario-obituaries'); ?></th>
                        <th scope="col"><?php _e('Date of Death', 'ontario-obituaries'); ?></th>
                        <th scope="col"><?php _e('Location', 'ontario-obituaries'); ?></th>
                        <th scope="col"><?php _e('Funeral Home', 'ontario-obituaries'); ?></th>
                        <th scope="col"><?php _e('Actions', 'ontario-obituaries'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($obituaries)): ?>
                        <tr>
                            <td colspan="5"><?php _e('No obituaries found.', 'ontario-obituaries'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($obituaries as $obituary): ?>
                            <tr id="obituary-<?php echo esc_attr($obituary->id); ?>">
                                <td class="column-primary">
                                    <?php echo esc_html($obituary->name); ?>
                                    <button type="button" class="toggle-row"><span class="screen-reader-text"><?php _e('Show more details', 'ontario-obituaries'); ?></span></button>
                                </td>
                                <td data-colname="<?php _e('Date of Death', 'ontario-obituaries'); ?>">
                                    <?php echo esc_html($obituary->date_of_death); ?>
                                </td>
                                <td data-colname="<?php _e('Location', 'ontario-obituaries'); ?>">
                                    <?php echo esc_html($obituary->location); ?>
                                </td>
                                <td data-colname="<?php _e('Funeral Home', 'ontario-obituaries'); ?>">
                                    <?php echo esc_html($obituary->funeral_home); ?>
                                </td>
                                <td data-colname="<?php _e('Actions', 'ontario-obituaries'); ?>">
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=ontario-obituaries-view&id=' . $obituary->id)); ?>" class="button button-small">
                                        <?php _e('View', 'ontario-obituaries'); ?>
                                    </a>
                                    <button type="button" class="button button-small delete-obituary" data-id="<?php echo esc_attr($obituary->id); ?>">
                                        <?php _e('Delete', 'ontario-obituaries'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <div id="ontario-obituaries-delete-result" class="notice hidden">
                <p></p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Show admin notice
     * 
     * @param string $type The notice type
     * @param string $message The notice message
     */
    private function show_admin_notice($type, $message) {
        add_action('admin_notices', function() use ($type, $message) {
            ?>
            <div class="notice notice-<?php echo esc_attr($type); ?> is-dismissible">
                <p><?php echo esc_html($message); ?></p>
            </div>
            <?php
        });
    }
    
    /**
     * Handle manual scrape AJAX
     */
    public function handle_manual_scrape() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ontario-obituaries-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'ontario-obituaries')));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to do this.', 'ontario-obituaries')));
        }
        
        // Run scraper
        $scraper = new Ontario_Obituaries_Scraper();
        $log = $scraper->scrape();
        
        // Send response
        wp_send_json_success(array(
            'message' => sprintf(
                __('Scraping completed. Found %d new obituaries.', 'ontario-obituaries'),
                $log['obituaries_added']
            ),
            'log' => $log
        ));
    }
}
