
<?php
/**
 * The main plugin class
 */
class Ontario_Obituaries {
    /**
     * Initialize the plugin
     */
    public function __construct() {
        // Load text domain for translations
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        
        // Add hook for the daily scrape
        add_action('ontario_obituaries_daily_scrape', array($this, 'run_daily_scrape'));
        
        // Add scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Register dedicated obituaries page
        add_action('init', array($this, 'register_obituaries_page'));
        
        // Filter the content of the obituaries page
        add_filter('the_content', array($this, 'obituaries_page_content'));
    }
    
    /**
     * Run the plugin
     */
    public function run() {
        // Initialize admin
        $admin = new Ontario_Obituaries_Admin();
        $admin->init();
    }
    
    /**
     * Load plugin text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain('ontario-obituaries', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    /**
     * Run the daily scrape
     */
    public function run_daily_scrape() {
        $scraper = new Ontario_Obituaries_Scraper();
        $scraper->scrape();
    }
    
    /**
     * Register the dedicated obituaries page
     */
    public function register_obituaries_page() {
        // Get settings
        $settings = get_option('ontario_obituaries_settings', array());
        $page_title = isset($settings['page_title']) ? $settings['page_title'] : 'Ontario Obituaries';
        
        // Check if the obituaries page already exists
        $obituaries_page = get_page_by_path('ontario-obituaries');
        
        // If the page doesn't exist, create it
        if (!$obituaries_page) {
            $page_id = wp_insert_post(array(
                'post_title'     => $page_title,
                'post_name'      => 'ontario-obituaries',
                'post_status'    => 'publish',
                'post_type'      => 'page',
                'comment_status' => 'closed'
            ));
            
            // Save the page ID in options for future reference
            update_option('ontario_obituaries_page_id', $page_id);
        } else {
            // Update the page ID in options
            update_option('ontario_obituaries_page_id', $obituaries_page->ID);
        }
    }
    
    /**
     * Filter the content of the obituaries page
     */
    public function obituaries_page_content($content) {
        // Get the current page ID
        $page_id = get_the_ID();
        
        // Get the obituaries page ID
        $obituaries_page_id = get_option('ontario_obituaries_page_id');
        
        // If this is the obituaries page, replace the content with our template
        if ($page_id == $obituaries_page_id) {
            $display = new Ontario_Obituaries_Display();
            
            // Default attributes
            $atts = array(
                'limit' => 20,
                'location' => '',
                'funeral_home' => '',
                'days' => 0,
            );
            
            // Start output buffering
            ob_start();
            
            // Include the template
            include_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'templates/obituaries.php';
            
            // Get the content
            $content = ob_get_clean();
        }
        
        return $content;
    }
    
    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts() {
        // Get the current page ID
        $page_id = get_the_ID();
        
        // Get the obituaries page ID
        $obituaries_page_id = get_option('ontario_obituaries_page_id');
        
        // Only load on the obituaries page
        if ($page_id == $obituaries_page_id) {
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
            
            // Pass data to JavaScript
            wp_localize_script(
                'ontario-obituaries-js',
                'ontarioObituariesData',
                array(
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('ontario-obituaries-nonce')
                )
            );
        }
    }
}
