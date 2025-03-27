
<?php
/**
 * The main plugin class - simplified version for testing activation
 */
class Ontario_Obituaries {
    /**
     * Initialize the plugin
     */
    public function __construct() {
        // Basic initialization
        error_log('Ontario Obituaries: Basic class loaded');
    }
    
    /**
     * Run the plugin
     */
    public function run() {
        error_log('Ontario Obituaries: Basic run method called');
        return true;
    }
}
