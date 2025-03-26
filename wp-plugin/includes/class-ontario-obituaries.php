
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
     * Enqueue scripts and styles
     */
    public function enqueue_scripts() {
        // Only load on pages where the shortcode is used
        global $post;
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'ontario_obituaries')) {
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
