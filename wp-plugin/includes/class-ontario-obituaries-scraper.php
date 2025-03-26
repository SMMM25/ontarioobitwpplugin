
<?php
/**
 * Ontario Obituaries Scraper
 * 
 * Handles the scraping of obituary data from various sources
 */
class Ontario_Obituaries_Scraper {
    /**
     * Sources to scrape
     * 
     * @var array
     */
    private $sources = array(
        'Toronto' => array(
            'url' => 'https://mountpleasantgroup.permavita.com/site/MountPleasantCemetery.html',
            'selector' => '.obituary-listing'
        ),
        'Ottawa' => array(
            'url' => 'https://www.hpmcgarry.ca/obituaries/',
            'selector' => '.obits-list .obit'
        ),
        'Hamilton' => array(
            'url' => 'https://www.dodsworth-brown.com/memorials',
            'selector' => '.memorial-list .item'
        )
        // Add more sources as needed
    );
    
    /**
     * Scrape obituaries from all sources
     */
    public function scrape() {
        $log = array(
            'started' => current_time('mysql'),
            'sources_scraped' => 0,
            'obituaries_found' => 0,
            'obituaries_added' => 0,
            'errors' => array()
        );
        
        foreach ($this->sources as $location => $source) {
            try {
                $results = $this->scrape_source($location, $source);
                $log['sources_scraped']++;
                $log['obituaries_found'] += count($results);
                
                foreach ($results as $obituary) {
                    if ($this->save_obituary($obituary)) {
                        $log['obituaries_added']++;
                    }
                }
            } catch (Exception $e) {
                $log['errors'][] = "Error scraping {$location}: " . $e->getMessage();
            }
        }
        
        $log['completed'] = current_time('mysql');
        $log['duration'] = strtotime($log['completed']) - strtotime($log['started']);
        
        // Save log to options
        update_option('ontario_obituaries_last_scrape', $log);
        
        // Return log for potential immediate use
        return $log;
    }
    
    /**
     * Scrape a specific source
     * 
     * @param string $location The location name
     * @param array $source The source configuration
     * @return array Scraped obituaries
     */
    private function scrape_source($location, $source) {
        $obituaries = array();
        
        // Request the page
        $response = wp_remote_get($source['url'], array(
            'timeout' => 60,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        ));
        
        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        
        // Create a new DOMDocument
        $dom = new DOMDocument();
        
        // Suppress warnings for malformed HTML
        @$dom->loadHTML($body);
        $xpath = new DOMXPath($dom);
        
        // Use the selector to find obituaries
        $items = $xpath->query('//' . $source['selector']);
        
        if ($items) {
            foreach ($items as $item) {
                $obituary = $this->parse_obituary($item, $location, $source);
                if ($obituary) {
                    $obituaries[] = $obituary;
                }
            }
        }
        
        return $obituaries;
    }
    
    /**
     * Parse an obituary from a DOM element
     * 
     * @param DOMElement $item The DOM element
     * @param string $location The location name
     * @param array $source The source configuration
     * @return array|false The parsed obituary or false on failure
     */
    private function parse_obituary($item, $location, $source) {
        // Implementation will vary based on the HTML structure of each source
        // This is a simplified generic version
        
        $xpath = new DOMXPath($item->ownerDocument);
        
        try {
            // Try to extract name, dates, etc.
            $name = $this->extract_text($xpath, './/h3', $item);
            if (empty($name)) {
                return false;
            }
            
            $dates = $this->extract_text($xpath, './/span.dates', $item);
            $description = $this->extract_text($xpath, './/div.description', $item);
            $image_url = $this->extract_attribute($xpath, './/img', 'src', $item);
            $source_url = $source['url'] . $this->extract_attribute($xpath, './/a', 'href', $item);
            
            // Parse dates
            $date_parts = array();
            if (preg_match('/(\d{4}-\d{2}-\d{2}).*?(\d{4}-\d{2}-\d{2})/', $dates, $date_parts)) {
                $date_of_birth = $date_parts[1];
                $date_of_death = $date_parts[2];
            } else {
                $date_of_birth = null;
                $date_of_death = date('Y-m-d'); // Default to today
            }
            
            // Calculate age if both dates are available
            $age = null;
            if ($date_of_birth && $date_of_death) {
                $dob = new DateTime($date_of_birth);
                $dod = new DateTime($date_of_death);
                $age = $dob->diff($dod)->y;
            }
            
            return array(
                'name' => $name,
                'age' => $age,
                'date_of_birth' => $date_of_birth,
                'date_of_death' => $date_of_death,
                'funeral_home' => $source['name'] ?? $location . ' Funeral Home',
                'location' => $location,
                'image_url' => $image_url,
                'description' => $description,
                'source_url' => $source_url
            );
        } catch (Exception $e) {
            // Log error and return false
            error_log('Error parsing obituary: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Extract text from an element
     * 
     * @param DOMXPath $xpath The XPath
     * @param string $selector The selector
     * @param DOMElement $context The context element
     * @return string The extracted text
     */
    private function extract_text($xpath, $selector, $context) {
        $nodes = $xpath->query($selector, $context);
        if ($nodes && $nodes->length > 0) {
            return trim($nodes->item(0)->textContent);
        }
        return '';
    }
    
    /**
     * Extract an attribute from an element
     * 
     * @param DOMXPath $xpath The XPath
     * @param string $selector The selector
     * @param string $attribute The attribute name
     * @param DOMElement $context The context element
     * @return string The extracted attribute value
     */
    private function extract_attribute($xpath, $selector, $attribute, $context) {
        $nodes = $xpath->query($selector, $context);
        if ($nodes && $nodes->length > 0) {
            return $nodes->item(0)->getAttribute($attribute);
        }
        return '';
    }
    
    /**
     * Save an obituary to the database
     * 
     * @param array $obituary The obituary data
     * @return bool Whether the save was successful
     */
    private function save_obituary($obituary) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ontario_obituaries';
        
        // Check if this obituary already exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE name = %s AND date_of_death = %s AND funeral_home = %s",
            $obituary['name'],
            $obituary['date_of_death'],
            $obituary['funeral_home']
        ));
        
        if ($exists) {
            return false; // Already exists
        }
        
        // Insert the new obituary
        $result = $wpdb->insert(
            $table_name,
            array(
                'name' => $obituary['name'],
                'age' => $obituary['age'],
                'date_of_birth' => $obituary['date_of_birth'],
                'date_of_death' => $obituary['date_of_death'],
                'funeral_home' => $obituary['funeral_home'],
                'location' => $obituary['location'],
                'image_url' => $obituary['image_url'],
                'description' => $obituary['description'],
                'source_url' => $obituary['source_url']
            ),
            array(
                '%s', // name
                '%d', // age
                '%s', // date_of_birth
                '%s', // date_of_death
                '%s', // funeral_home
                '%s', // location
                '%s', // image_url
                '%s', // description
                '%s'  // source_url
            )
        );
        
        return $result !== false;
    }
}
