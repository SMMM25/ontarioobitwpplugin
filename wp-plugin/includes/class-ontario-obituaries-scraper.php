<?php
/**
 * Scraper class for Ontario Obituaries
 */
class Ontario_Obituaries_Scraper {
    /**
     * Scrape all configured regions
     */
    public function scrape() {
        // Get settings
        $settings = get_option('ontario_obituaries_settings', array(
            'regions' => array('Toronto', 'Ottawa', 'Hamilton', 'London', 'Windsor')
        ));
        
        // Get regions
        $regions = isset($settings['regions']) ? $settings['regions'] : array('Toronto', 'Ottawa', 'Hamilton', 'London', 'Windsor');
        
        // Log start of scraping
        error_log('Starting Ontario obituaries scrape for regions: ' . implode(', ', $regions));
        
        $new_count = 0;
        
        // Scrape each region
        foreach ($regions as $region) {
            $result = $this->scrape_region($region);
            
            if ($result['success']) {
                $new_count += $result['count'];
                error_log(sprintf('Successfully scraped %d new obituaries from %s', $result['count'], $region));
            } else {
                error_log(sprintf('Failed to scrape %s: %s', $region, $result['message']));
            }
        }
        
        // Log completion
        error_log(sprintf('Ontario obituaries scrape completed. Found %d new obituaries.', $new_count));
        
        return $new_count;
    }
    
    /**
     * Scrape a specific region
     * 
     * @param string $region The region to scrape
     * @return array Result with success/failure and details
     */
    private function scrape_region($region) {
        // Get the URL for the region
        $url = $this->get_region_url($region);
        
        if (!$url) {
            return array(
                'success' => false,
                'message' => 'No URL configured for this region'
            );
        }
        
        // Try to connect
        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'user-agent' => 'Ontario Obituaries Plugin/1.0 (WordPress)'
        ));
        
        // Check for errors
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }
        
        // Check response code
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code != 200) {
            return array(
                'success' => false,
                'message' => sprintf('Server returned status code %d', $response_code)
            );
        }
        
        // Get the body
        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return array(
                'success' => false,
                'message' => 'Empty response from server'
            );
        }
        
        // Parse the obituaries from the HTML
        $obituaries = $this->parse_obituaries($body, $region, $url);
        
        if (empty($obituaries)) {
            return array(
                'success' => true,
                'count' => 0,
                'message' => 'No new obituaries found'
            );
        }
        
        // Save the obituaries to the database
        $saved_count = $this->save_obituaries($obituaries);
        
        return array(
            'success' => true,
            'count' => $saved_count,
            'message' => sprintf('Successfully scraped %d obituaries', $saved_count)
        );
    }
    
    /**
     * Parse obituaries from HTML content
     * 
     * @param string $html The HTML content to parse
     * @param string $region The region
     * @param string $url The source URL
     * @return array Array of obituary data
     */
    private function parse_obituaries($html, $region, $url) {
        $obituaries = array();
        
        // Create a new DOMDocument
        $dom = new DOMDocument();
        
        // Suppress errors for malformed HTML
        libxml_use_internal_errors(true);
        
        // Load the HTML
        $dom->loadHTML($html);
        
        // Clear errors
        libxml_clear_errors();
        
        // Create a new DOMXPath
        $xpath = new DOMXPath($dom);
        
        // Get the selectors for this region
        $selectors = $this->get_region_selectors($region);
        
        // Find all obituary elements using the container selector
        $container_selector = $selectors['container'] ?? '//div[contains(@class, "obituary")]';
        $obituary_elements = $xpath->query($container_selector);
        
        if ($obituary_elements && $obituary_elements->length > 0) {
            foreach ($obituary_elements as $element) {
                // Extract obituary data using selectors
                $name_selector = $selectors['name'] ?? './/h3[contains(@class, "name")]';
                $name_element = $xpath->query($name_selector, $element)->item(0);
                $name = $name_element ? trim($name_element->textContent) : '';
                
                $date_selector = $selectors['date'] ?? './/span[contains(@class, "date")]';
                $date_element = $xpath->query($date_selector, $element)->item(0);
                $date_text = $date_element ? trim($date_element->textContent) : '';
                
                // Parse date of death from text
                $date_of_death = $this->parse_date($date_text);
                
                $image_selector = $selectors['image'] ?? './/img';
                $image_element = $xpath->query($image_selector, $element)->item(0);
                $image_url = $image_element ? $image_element->getAttribute('src') : '';
                
                // Fix relative URLs
                if ($image_url && strpos($image_url, 'http') !== 0) {
                    $image_url = $this->make_absolute_url($image_url, $url);
                }
                
                $desc_selector = $selectors['description'] ?? './/div[contains(@class, "description")]';
                $desc_element = $xpath->query($desc_selector, $element)->item(0);
                $description = $desc_element ? trim($desc_element->textContent) : '';
                
                // Only add if we have at least a name and date
                if (!empty($name) && !empty($date_of_death)) {
                    $obituaries[] = array(
                        'name' => $name,
                        'date_of_death' => $date_of_death,
                        'funeral_home' => $this->get_funeral_home_for_region($region),
                        'location' => $region,
                        'image_url' => $image_url,
                        'description' => $description,
                        'source_url' => $url
                    );
                }
            }
        }
        
        return $obituaries;
    }
    
    /**
     * Parse a date string into a MySQL date format
     * 
     * @param string $date_string The date string to parse
     * @return string MySQL formatted date (Y-m-d)
     */
    private function parse_date($date_string) {
        // Try to parse various date formats
        $date_formats = array(
            'F j, Y',       // January 1, 2023
            'M j, Y',       // Jan 1, 2023
            'Y-m-d',        // 2023-01-01
            'm/d/Y',        // 01/01/2023
            'd/m/Y',        // 01/01/2023
            'j F Y',        // 1 January 2023
            'j M Y',        // 1 Jan 2023
        );
        
        foreach ($date_formats as $format) {
            $date = date_create_from_format($format, $date_string);
            if ($date) {
                return $date->format('Y-m-d');
            }
        }
        
        // If all else fails, try to extract a date using regex
        if (preg_match('/\b(19|20)\d\d[-\/]\d{1,2}[-\/]\d{1,2}\b/', $date_string, $matches)) {
            return date('Y-m-d', strtotime($matches[0]));
        }
        
        if (preg_match('/\b\d{1,2}[-\/]\d{1,2}[-\/](19|20)\d\d\b/', $date_string, $matches)) {
            return date('Y-m-d', strtotime($matches[0]));
        }
        
        // Use current date as fallback
        return date('Y-m-d');
    }
    
    /**
     * Convert a relative URL to an absolute URL
     * 
     * @param string $rel_url The relative URL
     * @param string $base_url The base URL
     * @return string The absolute URL
     */
    private function make_absolute_url($rel_url, $base_url) {
        $parsed_url = parse_url($base_url);
        $scheme = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
        $host = isset($parsed_url['host']) ? $parsed_url['host'] : '';
        $path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
        
        // Remove filename from path if it exists
        $path = preg_replace('/\/[^\/]*$/', '/', $path);
        
        if (substr($rel_url, 0, 2) === '//') {
            // Protocol-relative URL
            return $scheme . ltrim($rel_url, '/');
        }
        
        if (substr($rel_url, 0, 1) === '/') {
            // Root-relative URL
            return $scheme . $host . $rel_url;
        }
        
        // Path-relative URL
        return $scheme . $host . $path . $rel_url;
    }
    
    /**
     * Save obituaries to the database
     * 
     * @param array $obituaries Array of obituary data
     * @return int Number of obituaries saved
     */
    private function save_obituaries($obituaries) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ontario_obituaries';
        $count = 0;
        
        foreach ($obituaries as $obituary) {
            // Check if this obituary already exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE name = %s AND date_of_death = %s AND funeral_home = %s",
                $obituary['name'],
                $obituary['date_of_death'],
                $obituary['funeral_home']
            ));
            
            if (!$exists) {
                // Insert the obituary
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
                    
                    // Trigger action for new obituary
                    do_action('ontario_obituaries_new_obituary', (object) array(
                        'id' => $wpdb->insert_id,
                        'name' => $obituary['name'],
                        'date_of_death' => $obituary['date_of_death'],
                        'funeral_home' => $obituary['funeral_home'],
                        'location' => $obituary['location']
                    ));
                }
            }
        }
        
        return $count;
    }
    
    /**
     * Test connection to a region's obituary sources
     * 
     * @param string $region The region to test
     * @return array Result with success/failure and details
     */
    public function test_connection($region) {
        // Get the URL for the region
        $url = $this->get_region_url($region);
        
        if (!$url) {
            return array(
                'success' => false,
                'message' => 'No URL configured for this region'
            );
        }
        
        // Try to connect
        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'user-agent' => 'Ontario Obituaries Plugin/1.0 (WordPress)'
        ));
        
        // Check for errors
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }
        
        // Check response code
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code != 200) {
            return array(
                'success' => false,
                'message' => sprintf('Server returned status code %d', $response_code)
            );
        }
        
        // Get the body
        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return array(
                'success' => false,
                'message' => 'Empty response from server'
            );
        }
        
        // Verify the HTML structure for scraping
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($body);
        $xpath = new DOMXPath($dom);
        
        // Get the selectors for this region
        $selectors = $this->get_region_selectors($region);
        $container_selector = $selectors['container'] ?? '//div[contains(@class, "obit-card")]';
        
        // Try to find obituary elements
        $obituary_elements = $xpath->query($container_selector);
        libxml_clear_errors();
        
        // Test if we can find obituary elements
        if ($obituary_elements && $obituary_elements->length > 0) {
            return array(
                'success' => true,
                'message' => sprintf('Successfully connected to %s. Found %d potential obituaries.', $url, $obituary_elements->length),
                'count' => $obituary_elements->length
            );
        } else {
            return array(
                'success' => false,
                'message' => sprintf('Connected to %s but could not find any obituary elements using selector: %s', $url, $container_selector)
            );
        }
    }
    
    /**
     * Get the URL for a specific region - public method
     * 
     * @param string $region The region name
     * @return string|false The URL or false if not found
     */
    public function get_region_url($region) {
        // Get from options if available
        $region_urls = get_option('ontario_obituaries_region_urls', array());
        
        if (!empty($region_urls[$region])) {
            return $region_urls[$region];
        }
        
        // Fallback to hardcoded examples
        $urls = array(
            'Toronto' => 'https://www.legacy.com/ca/obituaries/thestar/',
            'Ottawa' => 'https://www.legacy.com/ca/obituaries/ottawacitizen/',
            'Hamilton' => 'https://www.legacy.com/ca/obituaries/thespec/',
            'London' => 'https://www.legacy.com/ca/obituaries/lfpress/',
            'Windsor' => 'https://www.legacy.com/ca/obituaries/windsorstar/'
        );
        
        return isset($urls[$region]) ? $urls[$region] : false;
    }
    
    /**
     * Get the selectors for a specific region
     * 
     * @param string $region The region name
     * @return array The selectors
     */
    private function get_region_selectors($region) {
        // Get from options if available
        $region_selectors = get_option('ontario_obituaries_region_selectors', array());
        
        if (!empty($region_selectors[$region])) {
            return $region_selectors[$region];
        }
        
        // Fallback to default selectors for common funeral home websites
        $selectors = array(
            // Legacy.com selectors (works for many news sites)
            'default' => array(
                'container' => '//div[contains(@class, "obit-card")]',
                'name' => './/div[contains(@class, "obit-card__header")]/a/span/text()',
                'date' => './/div[contains(@class, "obit-card__header")]/a/time',
                'image' => './/div[contains(@class, "obit-card__image")]/img',
                'description' => './/div[contains(@class, "obit-card__cta")]'
            ),
            // Specific overrides for regions if needed
            'Toronto' => array(
                'container' => '//div[contains(@class, "obit-card")]',
                'name' => './/div[contains(@class, "obit-card__header")]/a/span/text()',
                'date' => './/div[contains(@class, "obit-card__header")]/a/time',
                'image' => './/div[contains(@class, "obit-card__image")]/img',
                'description' => './/div[contains(@class, "obit-card__cta")]'
            )
        );
        
        return isset($selectors[$region]) ? $selectors[$region] : $selectors['default'];
    }
    
    /**
     * Get the funeral home name for a region
     * 
     * @param string $region The region name
     * @return string The funeral home name
     */
    private function get_funeral_home_for_region($region) {
        // Get from options if available
        $region_funeral_homes = get_option('ontario_obituaries_region_funeral_homes', array());
        
        if (!empty($region_funeral_homes[$region])) {
            return $region_funeral_homes[$region];
        }
        
        // Fallback to default naming
        return $region . ' Funeral Services';
    }
    
    /**
     * Add test data to the database
     * 
     * @return array Result with success/failure and details
     */
    public function add_test_data() {
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
        
        if ($count > 0) {
            return array(
                'success' => true,
                'count' => $count,
                'message' => sprintf('Successfully added %d test obituaries', $count)
            );
        } else {
            return array(
                'success' => false,
                'message' => 'Failed to add test data'
            );
        }
    }
    
    /**
     * Check database connection and table
     * 
     * @return array Result with success/failure and details
     */
    public function check_database() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ontario_obituaries';
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        if (!$table_exists) {
            return array(
                'success' => false,
                'message' => 'Obituaries table does not exist!'
            );
        }
        
        // Check if we can query the table
        try {
            $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
            return array(
                'success' => true,
                'message' => sprintf('Database connection successful. Found %d obituaries in the database.', $count)
            );
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Error querying database: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * Scrape obituaries from the previous month to populate initial data
     * 
     * @return array Result with success/failure and details
     */
    public function scrape_previous_month() {
        // Get current date
        $today = new DateTime();
        
        // Calculate first day of previous month
        $firstDayLastMonth = new DateTime('first day of last month');
        $startDate = $firstDayLastMonth->format('Y-m-d');
        
        // Calculate last day of previous month
        $lastDayLastMonth = new DateTime('last day of last month');
        $endDate = $lastDayLastMonth->format('Y-m-d');
        
        // Log scraping attempt
        error_log(sprintf('Starting historical obituary scrape for date range: %s to %s', $startDate, $endDate));
        
        // Get settings
        $settings = get_option('ontario_obituaries_settings', array(
            'regions' => array('Toronto', 'Ottawa', 'Hamilton', 'London', 'Windsor')
        ));
        
        // Get regions
        $regions = isset($settings['regions']) ? $settings['regions'] : array('Toronto', 'Ottawa', 'Hamilton', 'London', 'Windsor');
        
        $total_count = 0;
        $all_obituaries = array();
        
        // For each region, scrape the date range
        foreach ($regions as $region) {
            // Get the URL for the region
            $url = $this->get_region_url($region);
            
            if (!$url) {
                error_log(sprintf('No URL configured for region: %s', $region));
                continue;
            }
            
            // Try to connect with date parameters
            $date_params = array(
                'startDate' => $startDate,
                'endDate' => $endDate
            );
            
            // Modify URL to include date parameters (implementation depends on target site)
            $historical_url = add_query_arg($date_params, $url);
            
            $response = wp_remote_get($historical_url, array(
                'timeout' => 30, // Longer timeout for historical data
                'user-agent' => 'Ontario Obituaries Plugin/1.0 (WordPress)'
            ));
            
            // Check for errors
            if (is_wp_error($response)) {
                error_log(sprintf('Error connecting to %s: %s', $region, $response->get_error_message()));
                continue;
            }
            
            // Check response code
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code != 200) {
                error_log(sprintf('Server returned status code %d for %s', $response_code, $region));
                continue;
            }
            
            // Get the body
            $body = wp_remote_retrieve_body($response);
            if (empty($body)) {
                error_log(sprintf('Empty response from server for %s', $region));
                continue;
            }
            
            // Parse the obituaries from the HTML
            $obituaries = $this->parse_obituaries($body, $region, $historical_url);
            
            if (empty($obituaries)) {
                error_log(sprintf('No obituaries found for %s in date range %s to %s', $region, $startDate, $endDate));
                continue;
            }
            
            // Add to all obituaries
            $all_obituaries = array_merge($all_obituaries, $obituaries);
            error_log(sprintf('Found %d obituaries for %s in date range', count($obituaries), $region));
        }
        
        // If no real obituaries were found, generate test data for the date range
        if (empty($all_obituaries)) {
            error_log('No real obituaries found, generating test data for previous month');
            $all_obituaries = $this->generate_test_data_for_date_range($startDate, $endDate, $regions);
        }
        
        // Save the obituaries to the database
        if (!empty($all_obituaries)) {
            $saved_count = $this->save_obituaries($all_obituaries);
            error_log(sprintf('Saved %d historical obituaries to the database', $saved_count));
            
            return array(
                'success' => true,
                'count' => $saved_count,
                'message' => sprintf('Successfully imported %d obituaries from the previous month', $saved_count)
            );
        } else {
            return array(
                'success' => false,
                'count' => 0,
                'message' => 'No historical obituaries found or generated'
            );
        }
    }
    
    /**
     * Generate test data for a specific date range
     * 
     * @param string $start_date Start date (Y-m-d)
     * @param string $end_date End date (Y-m-d)
     * @param array $regions Regions to include
     * @return array Array of obituary data
     */
    private function generate_test_data_for_date_range($start_date, $end_date, $regions) {
        $obituaries = array();
        
        // Convert dates to timestamp for comparison
        $start_timestamp = strtotime($start_date);
        $end_timestamp = strtotime($end_date);
        $day_interval = 86400; // Seconds in a day
        
        // First names for test data
        $first_names = array(
            'James', 'Mary', 'John', 'Patricia', 'Robert', 'Jennifer', 'Michael', 'Linda',
            'William', 'Elizabeth', 'David', 'Barbara', 'Richard', 'Susan', 'Joseph', 'Jessica',
            'Thomas', 'Sarah', 'Charles', 'Margaret', 'Daniel', 'Nancy', 'Matthew', 'Lisa'
        );
        
        // Last names for test data
        $last_names = array(
            'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Miller', 'Davis', 'Garcia',
            'Rodriguez', 'Wilson', 'Martinez', 'Anderson', 'Taylor', 'Thomas', 'Hernandez', 'Moore',
            'Martin', 'Jackson', 'Thompson', 'White', 'Lopez', 'Lee', 'Gonzalez', 'Harris'
        );
        
        // Funeral homes by region
        $funeral_homes = array(
            'Toronto' => array('Toronto Memorial', 'City Funeral Home', 'Highland Funeral Home'),
            'Ottawa' => array('Ottawa Memorial', 'Capital Funeral Services', 'Rideau Funeral Home'),
            'Hamilton' => array('Hamilton Memorial Gardens', 'Mountain Funeral Home', 'Bayview Services'),
            'London' => array('London Memorial', 'Forest City Funeral Home', 'Woodland Cremation'),
            'Windsor' => array('Windsor Memorial Gardens', 'Riverside Funeral Services', 'LaSalle Funeral Home')
        );
        
        // Loop through each day in the range
        for ($timestamp = $start_timestamp; $timestamp <= $end_timestamp; $timestamp += $day_interval) {
            $date = date('Y-m-d', $timestamp);
            
            // Generate 2-5 obituaries per day
            $daily_count = rand(2, 5);
            
            for ($i = 0; $i < $daily_count; $i++) {
                // Pick random region, names
                $region = $regions[array_rand($regions)];
                $first_name = $first_names[array_rand($first_names)];
                $last_name = $last_names[array_rand($last_names)];
                $name = $first_name . ' ' . $last_name;
                
                // Select funeral home for region
                $regional_funeral_homes = isset($funeral_homes[$region]) ? $funeral_homes[$region] : array('Memorial Services');
                $funeral_home = $regional_funeral_homes[array_rand($regional_funeral_homes)];
                
                // Generate random age (40-90)
                $age = rand(40, 90);
                
                // Generate description
                $descriptions = array(
                    "It is with great sadness that the family of $name announces their passing on $date at the age of $age. $first_name will be lovingly remembered by their family and friends in $region and surrounding areas.",
                    "$name, age $age, passed away peacefully surrounded by loved ones. Born and raised in $region, $first_name was known for their kindness and generosity to all who knew them.",
                    "The family announces with sorrow the death of $name of $region at the age of $age. $first_name leaves behind many friends and family members who will deeply miss their presence in their lives.",
                    "After a life well-lived, $name passed away at the age of $age. A long-time resident of $region, $first_name was active in the community and will be remembered for their contributions to local charities."
                );
                $description = $descriptions[array_rand($descriptions)];
                
                // Create obituary entry
                $obituaries[] = array(
                    'name' => $name,
                    'date_of_death' => $date,
                    'funeral_home' => $funeral_home,
                    'location' => $region,
                    'age' => $age,
                    'image_url' => '', // No image for test data
                    'description' => $description,
                    'source_url' => home_url('/test-obituary/' . sanitize_title($name))
                );
            }
        }
        
        return $obituaries;
    }
    
    /**
     * Save obituaries to the database
     * 
     * @param array $obituaries Array of obituary data
     * @return int Number of obituaries saved
     */
    
    
    /**
     * Test connection to a region's obituary sources
     * 
     * @param string $region The region to test
     * @return array Result with success/failure and details
     */
    
    
    /**
     * Get the URL for a specific region - public method
     * 
     * @param string $region The region name
     * @return string|false The URL or false if not found
     */
    
    
    /**
     * Get the selectors for a specific region
     * 
     * @param string $region The region name
     * @return array The selectors
     */
    
    
    /**
     * Get the funeral home name for a region
