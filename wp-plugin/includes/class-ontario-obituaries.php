
<?php
/**
 * The main plugin class - extremely simplified version
 */
class Ontario_Obituaries {
    /**
     * Initialize the plugin with minimal functionality
     */
    public function __construct() {
        // Register our admin menu
        add_action('admin_menu', array($this, 'register_admin_menu'));
        
        // Register our shortcode
        add_shortcode('ontario_obituaries', array($this, 'render_shortcode'));
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
    }
    
    /**
     * Display the admin page
     */
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>Ontario Obituaries</h1>
            <p>Welcome to the Ontario Obituaries plugin. This is a minimal version to ensure plugin activation works correctly.</p>
        </div>
        <?php
    }
    
    /**
     * Render the shortcode
     */
    public function render_shortcode($atts) {
        return '<div class="ontario-obituaries">Ontario Obituaries will be displayed here.</div>';
    }
}
