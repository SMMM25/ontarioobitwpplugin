
<?php
/**
 * Ontario Obituaries Scraper
 * 
 * Handles scraping obituary data from various sources
 */
class Ontario_Obituaries_Scraper {
    /**
     * Regions to scrape
     * 
     * @var array
     */
    private $regions;
    
    /**
     * Maximum age of obituaries to scrape (in days)
     * 
     * @var int
     */
    private $max_age;
    
    /**
     * Number of retry attempts for failed requests
     * 
     * @var int
     */
    private $retry_attempts;
    
    /**
     * Request timeout in seconds
     * 
     * @var int
     */
    private $timeout;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Get settings or use defaults
        $this->regions = get_option('ontario_obituaries_regions', array('toronto', 'ottawa', 'hamilton'));
        $this->max_age = get_option('ontario_obituaries_max_age', 7);
        $this->retry_attempts = get_option('ontario_obituaries_retry_attempts', 3);
        $this->timeout = get_option('ontario_obituaries_timeout', 30);
    }
    
    /**
     * Run the scraper now
     * 
     * @return array Results of the scraping operation
     */
    public function run_now() {
        return $this->collect();
    }
    
    /**
     * Collect obituary data
     * 
     * @return array Results of the collection
     */
    public function collect() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ontario_obituaries';
        
        // Initialize results
        $results = array(
            'obituaries_found' => 0,
            'obituaries_added' => 0,
            'errors' => array(),
            'regions' => array()
        );
        
        // Add rate limiting to prevent overloading sources
        $this->enable_rate_limiting();
        
        // Check if the table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        if (!$table_exists) {
            $results['errors']['database'] = 'Obituaries table does not exist';
            return $results;
        }
        
        // Loop through regions
        foreach ($this->regions as $region) {
            // Initialize region results
            $results['regions'][$region] = array(
                'found' => 0,
                'added' => 0
            );
            
            try {
                // Get obituaries for this region
                $obituaries = $this->get_obituaries_for_region($region);
                
                // Update found count
                $results['regions'][$region]['found'] = count($obituaries);
                $results['obituaries_found'] += count($obituaries);
                
                // Process obituaries
                foreach ($obituaries as $obituary) {
                    // Validate required fields
                    if (empty($obituary['name']) || empty($obituary['date_of_death'])) {
                        continue;
                    }
                    
                    // Check if obituary already exists
                    $exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM $table_name WHERE name = %s AND date_of_death = %s AND funeral_home = %s",
                        $obituary['name'],
                        $obituary['date_of_death'],
                        $obituary['funeral_home']
                    ));
                    
                    // Skip if already exists
                    if ($exists) {
                        continue;
                    }
                    
                    // Insert obituary with error handling
                    $insert_result = $wpdb->insert(
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
                            'source_url' => $obituary['source_url']
                        ),
                        array(
                            '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s'
                        )
                    );
                    
                    if ($insert_result === false) {
                        error_log('Ontario Obituaries: Database insert error: ' . $wpdb->last_error);
                        continue;
                    }
                    
                    // Update added count
                    $results['regions'][$region]['added']++;
                    $results['obituaries_added']++;
                }
            } catch (Exception $e) {
                // Log error
                $results['errors'][$region] = $e->getMessage();
                error_log('Ontario Obituaries: Error scraping ' . $region . ': ' . $e->getMessage());
            }
        }
        
        return $results;
    }
    
    /**
     * Enable rate limiting for scraper requests
     */
    private function enable_rate_limiting() {
        // Add a random delay between requests to different sources
        add_action('http_api_curl', function($handle) {
            static $last_request_time = 0;
            
            // Calculate time since last request
            $current_time = microtime(true);
            $time_since_last = $current_time - $last_request_time;
            
            // If last request was too recent, add a delay
            // Minimum delay is 1 second, max is 3 seconds
            if ($time_since_last < 1) {
                $delay = mt_rand(1000, 3000) / 1000; // Convert ms to seconds
                usleep($delay * 1000000); // Convert to microseconds
            }
            
            // Update last request time
            $last_request_time = microtime(true);
            
            // Set timeout
            if ($this->timeout > 0) {
                curl_setopt($handle, CURLOPT_TIMEOUT, $this->timeout);
                curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, $this->timeout);
            }
            
            return $handle;
        });
    }
    
    /**
     * Get obituaries for a specific region
     * 
     * @param string $region The region to scrape
     * @return array The obituaries
     */
    private function get_obituaries_for_region($region) {
        // Initialize obituaries array
        $obituaries = array();
        
        // Get the scraper for this region
        $scraper = $this->get_scraper_for_region($region);
        
        // If no scraper, return empty array
        if (!$scraper) {
            return $obituaries;
        }
        
        // Get the obituaries
        $obituaries = $scraper->get_obituaries();
        
        return $obituaries;
    }
    
    /**
     * Get scraper for a specific region
     * 
     * @param string $region The region to get scraper for
     * @return object|null The scraper object or null if not found
     */
    private function get_scraper_for_region($region) {
        // Get the scraper class name
        $class_name = 'Ontario_Obituaries_Scraper_' . ucfirst($region);
        
        // Check if class exists
        if (!class_exists($class_name)) {
            // Try to load the class
            $file_path = ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/scrapers/class-ontario-obituaries-scraper-' . $region . '.php';
            
            if (file_exists($file_path)) {
                require_once $file_path;
            } else {
                return null;
            }
        }
        
        // Create and return the scraper
        if (class_exists($class_name)) {
            return new $class_name($this->max_age, $this->retry_attempts);
        }
        
        return null;
    }
    
    /**
     * Get historical obituaries for a specific date range
     * 
     * @param string $start_date Start date (YYYY-MM-DD)
     * @param string $end_date End date (YYYY-MM-DD)
     * @return array Results of the historical collection
     */
    public function get_historical_obituaries($start_date, $end_date) {
        // Validate dates
        if (!$this->validate_date($start_date) || !$this->validate_date($end_date)) {
            return array(
                'success' => false,
                'message' => 'Invalid date format. Use YYYY-MM-DD.'
            );
        }
        
        // Initialize results
        $results = array(
            'success' => true,
            'obituaries_found' => 0,
            'obituaries_added' => 0,
            'errors' => array(),
            'regions' => array()
        );
        
        // Add rate limiting to prevent overloading sources
        $this->enable_rate_limiting();
        
        // Loop through regions
        foreach ($this->regions as $region) {
            // Initialize region results
            $results['regions'][$region] = array(
                'found' => 0,
                'added' => 0
            );
            
            try {
                // Get the scraper for this region
                $scraper = $this->get_scraper_for_region($region);
                
                // If no scraper, skip
                if (!$scraper) {
                    $results['errors'][$region] = 'Scraper not found';
                    continue;
                }
                
                // Check if scraper supports historical data
                if (!method_exists($scraper, 'get_historical_obituaries')) {
                    $results['errors'][$region] = 'Historical data not supported';
                    continue;
                }
                
                // Get historical obituaries with timeout handling
                $timeout_occurred = false;
                set_time_limit(max(120, $this->timeout * 2)); // Double the timeout for historical data
                
                // Register shutdown function to detect timeouts
                register_shutdown_function(function() use (&$timeout_occurred, $region) {
                    $error = error_get_last();
                    if ($error && str_contains($error['message'], 'Maximum execution time')) {
                        $timeout_occurred = true;
                        error_log("Ontario Obituaries: Timeout occurred while processing historical data for $region");
                    }
                });
                
                // Get historical obituaries
                $obituaries = $scraper->get_historical_obituaries($start_date, $end_date);
                
                if ($timeout_occurred) {
                    $results['errors'][$region] = 'Timeout occurred during historical data retrieval';
                    continue;
                }
                
                // Process obituaries
                $this->process_obituaries($obituaries, $region, $results);
            } catch (Exception $e) {
                // Log error
                $results['errors'][$region] = $e->getMessage();
                error_log('Ontario Obituaries: Error scraping historical data for ' . $region . ': ' . $e->getMessage());
            }
        }
        
        return $results;
    }
    
    /**
     * Process obituaries and add to database
     * 
     * @param array $obituaries The obituaries to process
     * @param string $region The region the obituaries are from
     * @param array &$results The results array to update
     */
    private function process_obituaries($obituaries, $region, &$results) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ontario_obituaries';
        
        // Update found count
        $results['regions'][$region]['found'] = count($obituaries);
        $results['obituaries_found'] += count($obituaries);
        
        // Process obituaries
        foreach ($obituaries as $obituary) {
            // Check if obituary already exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE name = %s AND date_of_death = %s AND funeral_home = %s",
                $obituary['name'],
                $obituary['date_of_death'],
                $obituary['funeral_home']
            ));
            
            // Skip if already exists
            if ($exists) {
                continue;
            }
            
            // Insert obituary
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
                    'source_url' => $obituary['source_url']
                ),
                array(
                    '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s'
                )
            );
            
            // Update added count
            $results['regions'][$region]['added']++;
            $results['obituaries_added']++;
        }
    }
    
    /**
     * Validate date format
     * 
     * @param string $date The date to validate
     * @return bool Whether the date is valid
     */
    private function validate_date($date) {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
    
    /**
     * Get previous month's date range
     * 
     * @return array The start and end dates
     */
    public function get_previous_month_dates() {
        $start_date = date('Y-m-d', strtotime('first day of last month'));
        $end_date = date('Y-m-d', strtotime('last day of last month'));
        
        return array(
            'start_date' => $start_date,
            'end_date' => $end_date
        );
    }
    
    /**
     * Get obituaries from the previous month
     * 
     * @return array Results of the collection
     */
    public function get_previous_month_obituaries() {
        // Get previous month's date range
        $dates = $this->get_previous_month_dates();
        
        // Get historical obituaries
        return $this->get_historical_obituaries($dates['start_date'], $dates['end_date']);
    }
}
