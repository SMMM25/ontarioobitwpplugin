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
     * Whether to use adaptive mode
     * 
     * @var bool
     */
    private $adaptive_mode;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Get settings or use defaults
        $this->regions = get_option('ontario_obituaries_regions', array('toronto', 'ottawa', 'hamilton', 'london', 'windsor'));
        $this->max_age = get_option('ontario_obituaries_max_age', 7);
        $this->retry_attempts = get_option('ontario_obituaries_retry_attempts', 3);
        $this->timeout = get_option('ontario_obituaries_timeout', 30);
        $this->adaptive_mode = get_option('ontario_obituaries_adaptive_mode', true);
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
            'regions' => array(),
            'data_quality' => array(
                'complete' => 0,
                'partial' => 0,
                'invalid' => 0
            )
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
                'added' => 0,
                'complete' => 0,
                'partial' => 0
            );
            
            try {
                // Get obituaries for this region with retry logic
                $obituaries = $this->get_obituaries_for_region_with_retry($region);
                
                // Update found count
                $results['regions'][$region]['found'] = count($obituaries);
                $results['obituaries_found'] += count($obituaries);
                
                // Process obituaries
                foreach ($obituaries as $obituary) {
                    // Validate and sanitize the data
                    $obituary = $this->validate_and_sanitize_obituary($obituary);
                    
                    // Check data completeness
                    $completeness = $this->check_data_completeness($obituary);
                    if ($completeness === 'invalid') {
                        $results['data_quality']['invalid']++;
                        continue;
                    } elseif ($completeness === 'partial') {
                        $results['data_quality']['partial']++;
                        $results['regions'][$region]['partial']++;
                    } else {
                        $results['data_quality']['complete']++;
                        $results['regions'][$region]['complete']++;
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
                    
                    // Check for duplicates with fuzzy matching
                    $duplicate = $this->check_for_duplicates($obituary, $table_name);
                    if ($duplicate) {
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
                            'source_url' => $obituary['source_url'],
                            'data_quality' => $completeness === 'complete' ? 'high' : 'medium'
                        ),
                        array(
                            '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s'
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
     * Enhanced rate limiting for scraper requests with adaptive delays
     */
    private function enable_rate_limiting() {
        // Add a random delay between requests to different sources
        add_action('http_api_curl', function($handle) {
            static $last_request_time = 0;
            static $request_count = 0;
            
            // Calculate time since last request
            $current_time = microtime(true);
            $time_since_last = $current_time - $last_request_time;
            
            // Increase delay as request count increases to prevent detection
            $request_count++;
            $base_delay = min(1 + ($request_count * 0.1), 3); // Scale up to max 3 seconds
            
            // If last request was too recent, add a delay
            if ($time_since_last < $base_delay) {
                // More variable delay with natural distribution
                $jitter = mt_rand(-200, 500) / 1000; // -0.2 to +0.5 seconds
                $delay = $base_delay + $jitter;
                usleep($delay * 1000000); // Convert to microseconds
            }
            
            // Reset counter periodically to prevent excessive delays
            if ($request_count > 20) {
                $request_count = 0;
                // Take a longer break after many requests
                if (mt_rand(1, 10) > 8) { // 20% chance
                    usleep(mt_rand(3000000, 6000000)); // 3-6 second pause
                }
            }
            
            // Update last request time
            $last_request_time = microtime(true);
            
            // Set timeout and optimize connection settings
            if ($this->timeout > 0) {
                curl_setopt($handle, CURLOPT_TIMEOUT, $this->timeout);
                curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, min($this->timeout, 10));
                
                // Set keepalive and optimize connection
                curl_setopt($handle, CURLOPT_TCP_KEEPALIVE, 1);
                curl_setopt($handle, CURLOPT_TCP_KEEPIDLE, 60);
                curl_setopt($handle, CURLOPT_TCP_KEEPINTVL, 60);
                
                // Set proper encoding handling
                curl_setopt($handle, CURLOPT_ENCODING, '');
            }
            
            return $handle;
        });
    }
    
    /**
     * Get obituaries for a region with automatic retry
     * 
     * @param string $region The region to scrape
     * @return array The obituaries
     */
    private function get_obituaries_for_region_with_retry($region) {
        $attempt = 0;
        $max_attempts = $this->retry_attempts;
        $obituaries = array();
        $last_error = null;
        
        while ($attempt < $max_attempts) {
            try {
                $attempt++;
                
                // Get the scraper for this region
                $scraper = $this->get_scraper_for_region($region);
                
                // If no scraper, return empty array
                if (!$scraper) {
                    throw new Exception("Scraper for region '$region' not found");
                }
                
                // Pass adaptive mode setting to the scraper if supported
                if ($this->adaptive_mode && method_exists($scraper, 'set_adaptive_mode')) {
                    $scraper->set_adaptive_mode(true);
                }
                
                // Get the obituaries
                $obituaries = $scraper->get_obituaries();
                
                // If successful, break the loop
                break;
                
            } catch (Exception $e) {
                $last_error = $e;
                
                // Log the error
                error_log(sprintf('Ontario Obituaries: Error scraping %s (attempt %d/%d): %s', 
                    $region, $attempt, $max_attempts, $e->getMessage()));
                
                // If we have more attempts, wait with exponential backoff
                if ($attempt < $max_attempts) {
                    $backoff = pow(2, $attempt) * 500; // 1s, 2s, 4s, 8s...
                    $jitter = mt_rand(-100, 200) / 1000; // -0.1 to +0.2 seconds
                    usleep(($backoff + $jitter) * 1000);
                }
            }
        }
        
        // If all attempts failed, throw the last error
        if (empty($obituaries) && $last_error) {
            throw new Exception("Failed after $max_attempts attempts: " . $last_error->getMessage());
        }
        
        return $obituaries;
    }
    
    /**
     * Validate and sanitize obituary data
     * 
     * @param array $obituary The obituary data to validate
     * @return array The validated and sanitized data
     */
    private function validate_and_sanitize_obituary($obituary) {
        // Required fields with defaults
        $obituary['name'] = isset($obituary['name']) ? sanitize_text_field($obituary['name']) : '';
        $obituary['date_of_death'] = isset($obituary['date_of_death']) ? sanitize_text_field($obituary['date_of_death']) : '';
        $obituary['funeral_home'] = isset($obituary['funeral_home']) ? sanitize_text_field($obituary['funeral_home']) : '';
        $obituary['location'] = isset($obituary['location']) ? sanitize_text_field($obituary['location']) : '';
        
        // Optional fields with defaults
        $obituary['date_of_birth'] = isset($obituary['date_of_birth']) ? sanitize_text_field($obituary['date_of_birth']) : '';
        $obituary['age'] = isset($obituary['age']) && is_numeric($obituary['age']) ? intval($obituary['age']) : 0;
        $obituary['image_url'] = isset($obituary['image_url']) ? esc_url_raw($obituary['image_url']) : '';
        $obituary['source_url'] = isset($obituary['source_url']) ? esc_url_raw($obituary['source_url']) : '';
        
        // Sanitize description with more HTML tags allowed
        $obituary['description'] = isset($obituary['description']) ? wp_kses_post($obituary['description']) : '';
        
        // Validate and normalize dates
        $obituary['date_of_death'] = $this->validate_and_normalize_date($obituary['date_of_death']);
        $obituary['date_of_birth'] = $this->validate_and_normalize_date($obituary['date_of_birth']);
        
        // Validate age
        if (empty($obituary['age']) && !empty($obituary['date_of_birth']) && !empty($obituary['date_of_death'])) {
            $obituary['age'] = $this->calculate_age_from_dates($obituary['date_of_birth'], $obituary['date_of_death']);
        }
        
        return $obituary;
    }
    
    /**
     * Validate and normalize a date string to Y-m-d format
     * 
     * @param string $date_string The date string to validate
     * @return string The normalized date or empty string if invalid
     */
    private function validate_and_normalize_date($date_string) {
        if (empty($date_string)) {
            return '';
        }
        
        // Check if already in Y-m-d format
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_string)) {
            return $date_string;
        }
        
        // Try multiple date formats
        $date_formats = array(
            'm/d/Y', 'd/m/Y', 'Y/m/d',
            'M j, Y', 'F j, Y', 'j F Y',
            'd-m-Y', 'm-d-Y', 'Y-m-d'
        );
        
        foreach ($date_formats as $format) {
            $date = DateTime::createFromFormat($format, $date_string);
            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }
        
        return '';
    }
    
    /**
     * Calculate age from birth and death dates
     * 
     * @param string $birth_date Birth date in Y-m-d format
     * @param string $death_date Death date in Y-m-d format
     * @return int The calculated age or 0 if invalid
     */
    private function calculate_age_from_dates($birth_date, $death_date) {
        if (empty($birth_date) || empty($death_date)) {
            return 0;
        }
        
        try {
            $birth = new DateTime($birth_date);
            $death = new DateTime($death_date);
            $interval = $birth->diff($death);
            return $interval->y;
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * Check data completeness
     * 
     * @param array $obituary The obituary data
     * @return string 'complete', 'partial', or 'invalid'
     */
    private function check_data_completeness($obituary) {
        // Check for required fields
        if (empty($obituary['name']) || empty($obituary['date_of_death'])) {
            return 'invalid';
        }
        
        // Check for complete data
        $complete_fields = array('name', 'date_of_death', 'funeral_home', 'location', 'description');
        $complete = true;
        
        foreach ($complete_fields as $field) {
            if (empty($obituary[$field])) {
                $complete = false;
                break;
            }
        }
        
        // Additional validation for high-quality data
        if ($complete) {
            // Check if description is substantial
            if (strlen($obituary['description']) < 50) {
                $complete = false;
            }
            
            // Check if age or birth date is available
            if (empty($obituary['age']) && empty($obituary['date_of_birth'])) {
                $complete = false;
            }
        }
        
        return $complete ? 'complete' : 'partial';
    }
    
    /**
     * Check for duplicates with fuzzy matching
     * 
     * @param array $obituary The obituary to check
     * @param string $table_name The database table name
     * @return bool Whether a duplicate was found
     */
    private function check_for_duplicates($obituary, $table_name) {
        global $wpdb;
        
        // Skip if required fields are missing
        if (empty($obituary['name']) || empty($obituary['date_of_death'])) {
            return false;
        }
        
        // Extract first and last name
        $name_parts = explode(' ', trim($obituary['name']));
        if (count($name_parts) < 2) {
            return false;
        }
        
        $first_name = $name_parts[0];
        $last_name = end($name_parts);
        
        // Fuzzy match by last name and first initial within the same date range
        $death_date = $obituary['date_of_death'];
        $date_range_start = date('Y-m-d', strtotime($death_date . ' -3 days'));
        $date_range_end = date('Y-m-d', strtotime($death_date . ' +3 days'));
        
        $first_initial = substr($first_name, 0, 1);
        
        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE 
            last_name LIKE %s AND 
            first_name LIKE %s AND 
            date_of_death BETWEEN %s AND %s",
            $last_name . '%',
            $first_initial . '%',
            $date_range_start,
            $date_range_end
        );
        
        $duplicate_count = $wpdb->get_var($sql);
        
        return $duplicate_count > 0;
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
    
    /**
     * Get region URL
     * 
     * @param string $region The region
     * @return string The URL
     */
    public function get_region_url($region) {
        $urls = get_option('ontario_obituaries_region_urls', array());
        return isset($urls[$region]) ? $urls[$region] : '';
    }
    
    /**
     * Test connection to a region
     * 
     * @param string $region The region to test
     * @return array Results of the test
     */
    public function test_connection($region) {
        try {
            // Get the scraper for this region
            $scraper = $this->get_scraper_for_region($region);
            
            // If no scraper, return error
            if (!$scraper) {
                return array(
                    'success' => false,
                    'message' => 'Scraper not found for ' . $region
                );
            }
            
            // Set adaptive mode if supported
            if ($this->adaptive_mode && method_exists($scraper, 'set_adaptive_mode')) {
                $scraper->set_adaptive_mode(true);
            }
            
            // Test the connection
            $connection_result = $scraper->test_connection();
            
            return array(
                'success' => true,
                'message' => 'Connection successful to ' . $region . '. Source is accessible.'
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Connection failed to ' . $region . ': ' . $e->getMessage()
            );
        }
    }
    
    /**
     * Add test data to the database
     * 
     * @return array Results of adding test data
     */
    public function add_test_data() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ontario_obituaries';
        
        // Check if the table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            return array(
                'success' => false,
                'message' => 'Obituaries table does not exist'
            );
        }
        
        // Sample test data
        $test_data = array(
            array(
                'name' => 'John Smith',
                'date_of_birth' => '1945-03-15',
                'date_of_death' => date('Y-m-d', strtotime('-5 days')),
                'age' => 78,
                'funeral_home' => 'Toronto Memorial Services',
                'location' => 'Toronto',
                'description' => 'John Smith passed away peacefully surrounded by family. He was a beloved husband, father and grandfather.',
                'source_url' => 'https://example.com/obituaries/john-smith',
                'image_url' => 'https://randomuser.me/api/portraits/men/45.jpg',
                'data_quality' => 'high'
            ),
            array(
                'name' => 'Mary Johnson',
                'date_of_birth' => '1938-09-22',
                'date_of_death' => date('Y-m-d', strtotime('-3 days')),
                'age' => 85,
                'funeral_home' => 'Ottawa Memorial Chapel',
                'location' => 'Ottawa',
                'description' => 'Mary Johnson, aged 85, passed away on ' . date('F j, Y', strtotime('-3 days')) . '. She is survived by her three children and seven grandchildren.',
                'source_url' => 'https://example.com/obituaries/mary-johnson',
                'image_url' => 'https://randomuser.me/api/portraits/women/67.jpg',
                'data_quality' => 'high'
            ),
        );
        
        $added = 0;
        
        // Add test data
        foreach ($test_data as $obituary) {
            // Check if already exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE name = %s AND date_of_death = %s",
                $obituary['name'],
                $obituary['date_of_death']
            ));
            
            if ($exists) {
                continue;
            }
            
            // Insert test obituary
            $wpdb->insert($table_name, $obituary);
            $added++;
        }
        
        return array(
            'success' => true,
            'message' => sprintf('Added %d test obituaries to the database', $added)
        );
    }
    
    /**
     * Check database connection and structure
     * 
     * @return array Results of the database check
     */
    public function check_database() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ontario_obituaries';
        
        // Check if the table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            return array(
                'success' => false,
                'message' => 'Obituaries table does not exist'
            );
        }
        
        // Get table structure
        $columns = $wpdb->get_results("DESCRIBE $table_name");
        
        // Check for essential columns
        $required_columns = array('name', 'date_of_death', 'funeral_home', 'location');
        $missing_columns = array();
        
        $column_names = array_map(function($col) {
            return $col->Field;
        }, $columns);
        
        foreach ($required_columns as $required) {
            if (!in_array($required, $column_names)) {
                $missing_columns[] = $required;
            }
        }
        
        if (!empty($missing_columns)) {
            return array(
                'success' => false,
                'message' => 'Table is missing required columns: ' . implode(', ', $missing_columns)
            );
        }
        
        // Check record count
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        
        return array(
            'success' => true,
            'message' => sprintf('Database connection successful. Table exists with %d records.', $count)
        );
    }
    
    /**
     * Run scraper directly and return count of new records
     * 
     * @return int Number of new records added
     */
    public function scrape() {
        $results = $this->collect();
        return isset($results['obituaries_added']) ? intval($results['obituaries_added']) : 0;
    }
}
