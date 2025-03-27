<?php
/**
 * Scraper class for Ontario Obituaries
 */
class Ontario_Obituaries_Scraper {
    /**
     * Default scraper configuration
     */
    private $default_config = array(
        'regions' => array('Toronto', 'Ottawa', 'Hamilton', 'London', 'Windsor'),
        'max_age' => 7,
        'retry_attempts' => 3,
        'timeout' => 30,
        'user_agent' => 'Ontario Obituaries Plugin/1.0 (WordPress)',
        'adaptive_mode' => true
    );
    
    /**
     * Structure change detection flags
     */
    private $structure_change_alerts = array();
    
    /**
     * Scrape all configured regions with enhanced error handling
     */
    public function scrape() {
        // Get settings
        $settings = get_option('ontario_obituaries_settings', $this->default_config);
        
        // Get regions
        $regions = isset($settings['regions']) ? $settings['regions'] : $this->default_config['regions'];
        
        // Set additional scraper parameters
        $max_age = isset($settings['max_age']) ? intval($settings['max_age']) : $this->default_config['max_age'];
        $retry_attempts = isset($settings['retry_attempts']) ? intval($settings['retry_attempts']) : $this->default_config['retry_attempts'];
        $timeout = isset($settings['timeout']) ? intval($settings['timeout']) : $this->default_config['timeout'];
        $adaptive_mode = isset($settings['adaptive_mode']) ? (bool)$settings['adaptive_mode'] : $this->default_config['adaptive_mode'];
        
        // Log start of scraping with enhanced configuration
        error_log(sprintf(
            'Starting enhanced Ontario obituaries scrape for regions: %s (max_age: %d, retry_attempts: %d, adaptive_mode: %s)',
            implode(', ', $regions),
            $max_age,
            $retry_attempts,
            $adaptive_mode ? 'enabled' : 'disabled'
        ));
        
        $new_count = 0;
        $error_regions = array();
        $all_obituaries = array();
        
        // Scrape each region with enhanced error handling
        foreach ($regions as $region) {
            $start_time = microtime(true);
            $result = $this->scrape_region($region, array(
                'max_age' => $max_age,
                'retry_attempts' => $retry_attempts,
                'timeout' => $timeout,
                'adaptive_mode' => $adaptive_mode
            ));
            $elapsed_time = microtime(true) - $start_time;
            
            if ($result['success']) {
                $all_obituaries = array_merge($all_obituaries, $result['obituaries']);
                error_log(sprintf(
                    'Successfully scraped %d obituaries from %s (time: %.2f seconds)',
                    count($result['obituaries']),
                    $region,
                    $elapsed_time
                ));
            } else {
                $error_regions[$region] = $result['message'];
                error_log(sprintf(
                    'Failed to scrape %s: %s (time: %.2f seconds)',
                    $region,
                    $result['message'],
                    $elapsed_time
                ));
            }
            
            // Add a small delay between regions to avoid overwhelming the server
            if ($region !== end($regions)) {
                sleep(1);
            }
        }
        
        // Deduplicate obituaries before saving
        $unique_obituaries = $this->deduplicate_obituaries($all_obituaries);
        $deduplication_count = count($all_obituaries) - count($unique_obituaries);
        
        if ($deduplication_count > 0) {
            error_log(sprintf('Deduplication removed %d duplicate entries', $deduplication_count));
        }
        
        // Save the obituaries to the database
        if (!empty($unique_obituaries)) {
            $saved_count = $this->save_obituaries($unique_obituaries);
            $new_count += $saved_count;
            
            error_log(sprintf(
                'Saved %d new obituaries to the database (from %d total found, %d duplicates removed)',
                $saved_count,
                count($all_obituaries),
                $deduplication_count
            ));
        }
        
        // Check for structure changes
        if (!empty($this->structure_change_alerts)) {
            error_log('STRUCTURE CHANGE ALERTS: ' . implode('; ', $this->structure_change_alerts));
            
            // Save the structure change alerts for admin notification
            update_option('ontario_obituaries_structure_alerts', $this->structure_change_alerts);
        }
        
        // Log completion with statistics
        error_log(sprintf(
            'Ontario obituaries scrape completed. Found %d obituaries, saved %d new entries. Errors in %d of %d regions.',
            count($all_obituaries),
            $new_count,
            count($error_regions),
            count($regions)
        ));
        
        return array(
            'success' => $new_count > 0 || count($all_obituaries) > 0,
            'count' => $new_count,
            'total_found' => count($all_obituaries),
            'errors' => $error_regions
        );
    }
    
    /**
     * Scrape a specific region with enhanced reliability
     * 
     * @param string $region The region to scrape
     * @param array $options Scraping options
     * @return array Result with success/failure and details
     */
    private function scrape_region($region, $options = array()) {
        // Merge options with defaults
        $options = array_merge(array(
            'max_age' => 7,
            'retry_attempts' => 3,
            'timeout' => 30,
            'adaptive_mode' => true
        ), $options);
        
        // Get the URL for the region
        $url = $this->get_region_url($region);
        
        if (!$url) {
            return array(
                'success' => false,
                'message' => 'No URL configured for this region',
                'obituaries' => array()
            );
        }
        
        // Try to connect with retry logic
        $response = null;
        $success = false;
        $attempt = 0;
        $error_message = '';
        
        while (!$success && $attempt < $options['retry_attempts']) {
            $attempt++;
            
            // Calculate backoff time if this is a retry
            $backoff_time = $attempt > 1 ? pow(2, $attempt - 1) * 1 : 0; // Exponential backoff in seconds
            
            if ($backoff_time > 0) {
                error_log(sprintf('Region %s: Retrying connection (attempt %d of %d) after %d second backoff',
                    $region, $attempt, $options['retry_attempts'], $backoff_time));
                sleep($backoff_time);
            }
            
            // Try to connect
            $response = wp_remote_get($url, array(
                'timeout' => $options['timeout'],
                'user-agent' => $this->default_config['user_agent'],
                'headers' => array(
                    'Referer' => home_url(),
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                )
            ));
            
            // Check for errors
            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                error_log(sprintf('Region %s: Connection attempt %d failed: %s',
                    $region, $attempt, $error_message));
                continue; // Try again
            }
            
            // Check response code
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code != 200) {
                $error_message = sprintf('Server returned status code %d', $response_code);
                error_log(sprintf('Region %s: Connection attempt %d failed: %s',
                    $region, $attempt, $error_message));
                
                // Special handling for common error codes
                if ($response_code == 429) {
                    // Rate limiting - use longer backoff
                    sleep(min(30, $backoff_time * 2));
                } elseif ($response_code >= 500) {
                    // Server error - retry with standard backoff
                    continue;
                } elseif ($response_code == 403) {
                    // Forbidden - might be blocking scrapers, try with different user agent
                    $this->default_config['user_agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';
                    continue;
                } elseif ($response_code == 404) {
                    // Page not found - URL might have changed
                    break; // Don't retry 404 errors
                }
                
                continue; // Try again for other status codes
            }
            
            // Get the body
            $body = wp_remote_retrieve_body($response);
            if (empty($body)) {
                $error_message = 'Empty response from server';
                error_log(sprintf('Region %s: Connection attempt %d failed: %s',
                    $region, $attempt, $error_message));
                continue; // Try again
            }
            
            // If we got here, the connection was successful
            $success = true;
        }
        
        // If all attempts failed, return error
        if (!$success) {
            return array(
                'success' => false,
                'message' => sprintf('Failed to connect after %d attempts: %s', $options['retry_attempts'], $error_message),
                'obituaries' => array()
            );
        }
        
        // Parse the obituaries from the HTML
        $body = wp_remote_retrieve_body($response);
        
        // Track structure detection results if adaptive mode is enabled
        $structure_detection = null;
        if ($options['adaptive_mode']) {
            // Get the selectors for this region
            $selectors = $this->get_region_selectors($region);
            
            // Attempt structure detection
            $structure_detection = $this->detect_page_structure($body, $region);
            
            // If we detected a different structure, use it instead
            if ($structure_detection['detected_changes']) {
                $this->structure_change_alerts[] = sprintf(
                    'Region %s: %s',
                    $region, 
                    $structure_detection['message']
                );
                
                error_log(sprintf('STRUCTURE CHANGE DETECTED for %s: %s',
                    $region, $structure_detection['message']));
                
                // Use the adaptive selectors if found
                if (!empty($structure_detection['new_selectors'])) {
                    $selectors = $structure_detection['new_selectors'];
                    error_log('Using adaptive selectors: ' . json_encode($selectors));
                }
            }
        }
        
        // Parse obituaries using the appropriate selectors
        $obituaries = $this->parse_obituaries($body, $region, $url);
        
        if (empty($obituaries)) {
            // No obituaries found, but connection was successful
            return array(
                'success' => true,
                'message' => 'Connection successful but no obituaries found',
                'obituaries' => array(),
                'structure_detection' => $structure_detection
            );
        }
        
        // Filter obituaries by max age
        $filtered_obituaries = $this->filter_obituaries_by_age($obituaries, $options['max_age']);
        
        return array(
            'success' => true,
            'message' => sprintf('Successfully scraped %d obituaries (%d within max age)',
                count($obituaries), count($filtered_obituaries)),
            'obituaries' => $filtered_obituaries,
            'structure_detection' => $structure_detection
        );
    }
    
    /**
     * Parse obituaries from HTML content with improved error handling
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
        
        // Get errors for diagnosis
        $errors = libxml_get_errors();
        libxml_clear_errors();
        
        // Log serious parsing errors but continue
        if (count($errors) > 10) {
            error_log(sprintf('Region %s: HTML parsing had %d errors - page structure might have changed',
                $region, count($errors)));
        }
        
        // Create a new DOMXPath
        $xpath = new DOMXPath($dom);
        
        // Get the selectors for this region
        $selectors = $this->get_region_selectors($region);
        
        // Try different container selectors if adaptive mode is enabled
        $container_selectors = array($selectors['container']);
        
        if (!empty($selectors['fallback_containers'])) {
            $container_selectors = array_merge($container_selectors, $selectors['fallback_containers']);
        }
        
        // Try each container selector
        $obituary_elements = null;
        $used_selector = '';
        
        foreach ($container_selectors as $container_selector) {
            $obituary_elements = $xpath->query($container_selector);
            
            if ($obituary_elements && $obituary_elements->length > 0) {
                $used_selector = $container_selector;
                break;
            }
        }
        
        // If no elements were found with any selector, return empty array
        if (!$obituary_elements || $obituary_elements->length === 0) {
            error_log(sprintf('Region %s: No obituary elements found with any container selector',
                $region));
            return array();
        }
        
        // Log which selector worked
        error_log(sprintf('Region %s: Found %d obituary elements using selector: %s',
            $region, $obituary_elements->length, $used_selector));
        
        // Process each obituary element
        foreach ($obituary_elements as $element) {
            try {
                // Extract obituary data using selectors
                $name_selector = $selectors['name'];
                $name_element = $xpath->query($name_selector, $element)->item(0);
                $name = $name_element ? trim($name_element->textContent) : '';
                
                $date_selector = $selectors['date'];
                $date_element = $xpath->query($date_selector, $element)->item(0);
                $date_text = $date_element ? trim($date_element->textContent) : '';
                
                // Parse date of death from text
                $date_of_death = $this->parse_date($date_text);
                
                $image_selector = $selectors['image'];
                $image_element = $xpath->query($image_selector, $element)->item(0);
                $image_url = $image_element ? $image_element->getAttribute('src') : '';
                
                // Fix relative URLs
                if ($image_url && strpos($image_url, 'http') !== 0) {
                    $image_url = $this->make_absolute_url($image_url, $url);
                }
                
                $desc_selector = $selectors['description'];
                $desc_element = $xpath->query($desc_selector, $element)->item(0);
                $description = $desc_element ? trim($desc_element->textContent) : '';
                
                // Try to extract age if available
                $age = null;
                if (!empty($date_text)) {
                    // Look for age in format "Age XX" or "XX years old"
                    if (preg_match('/Age[^\d]*(\d+)|(\d+)[^\d]*years old/i', $date_text, $matches)) {
                        $age = !empty($matches[1]) ? intval($matches[1]) : intval($matches[2]);
                    } elseif (preg_match('/(\d+)[^\d]*years/i', $description, $matches)) {
                        $age = intval($matches[1]);
                    }
                }
                
                // Only add if we have at least a name and date
                if (!empty($name) && !empty($date_of_death)) {
                    $obituaries[] = array(
                        'name' => $name,
                        'date_of_death' => $date_of_death,
                        'funeral_home' => $this->get_funeral_home_for_region($region),
                        'location' => $region,
                        'age' => $age,
                        'image_url' => $image_url,
                        'description' => $description,
                        'source_url' => $url
                    );
                }
            } catch (Exception $e) {
                // Log the error but continue processing other elements
                error_log(sprintf('Error processing obituary element: %s', $e->getMessage()));
                continue;
            }
        }
        
        return $obituaries;
    }
    
    /**
     * Detect changes in page structure and adapt selectors
     * 
     * @param string $html The HTML content to analyze
     * @param string $region The region name
     * @return array Detection results
     */
    private function detect_page_structure($html, $region) {
        // Create a new DOMDocument
        $dom = new DOMDocument();
        
        // Suppress errors for malformed HTML
        libxml_use_internal_errors(true);
        
        // Load the HTML
        $dom->loadHTML($html);
        libxml_clear_errors();
        
        // Create a new DOMXPath
        $xpath = new DOMXPath($dom);
        
        // Get the current selectors
        $current_selectors = $this->get_region_selectors($region);
        
        // Try the current container selector
        $current_elements = $xpath->query($current_selectors['container']);
        
        $result = array(
            'detected_changes' => false,
            'message' => '',
            'new_selectors' => array()
        );
        
        // If current selector works well, no change needed
        if ($current_elements && $current_elements->length > 3) {
            return $result;
        }
        
        // Try to detect common obituary containers
        $potential_selectors = array(
            // Common container patterns for obituaries
            '//div[contains(@class, "obit")]',
            '//div[contains(@class, "obituary")]',
            '//article[contains(@class, "death")]',
            '//article[contains(@class, "obituary")]',
            '//div[contains(@class, "death-notice")]',
            '//div[@class="card" or contains(@class, "card-body")]',
            '//div[contains(@class, "listing-item")]',
            '//div[contains(@class, "search-result")]',
            // Legacy.com specific
            '//div[contains(@class, "obit-card")]',
            '//div[contains(@class, "EntryContainer")]',
            '//li[contains(@class, "EntryListItem")]'
        );
        
        $best_selector = '';
        $max_count = 0;
        
        // Try each potential selector and see which finds the most elements
        foreach ($potential_selectors as $selector) {
            $elements = $xpath->query($selector);
            
            if ($elements && $elements->length > $max_count) {
                $max_count = $elements->length;
                $best_selector = $selector;
            }
        }
        
        // If we found a better selector with at least 3 elements
        if ($max_count >= 3 && $best_selector !== $current_selectors['container']) {
            // We've detected a structure change!
            $result['detected_changes'] = true;
            $result['message'] = sprintf(
                'Structure change detected! Old selector "%s" found %d elements, new selector "%s" found %d elements',
                $current_selectors['container'],
                $current_elements ? $current_elements->length : 0,
                $best_selector,
                $max_count
            );
            
            // Now try to detect appropriate child selectors
            $sample_element = $xpath->query($best_selector)->item(0);
            if ($sample_element) {
                $new_selectors = $this->analyze_element_structure($sample_element, $xpath);
                
                // Combine with the new container selector
                $result['new_selectors'] = array_merge(
                    array('container' => $best_selector),
                    $new_selectors
                );
                
                // Add the old selector as a fallback
                $result['new_selectors']['fallback_containers'] = array($current_selectors['container']);
                
                // Store the new selectors for this region
                $this->update_region_selectors($region, $result['new_selectors']);
            }
        }
        
        return $result;
    }
    
    /**
     * Analyze an element to determine appropriate selectors for child elements
     * 
     * @param DOMElement $element The element to analyze
     * @param DOMXPath $xpath The XPath object for queries
     * @return array Detected selectors
     */
    private function analyze_element_structure($element, $xpath) {
        $selectors = array();
        
        // Common patterns for obituary name elements
        $name_patterns = array(
            './/h1', './/h2', './/h3', './/h4',
            './/div[contains(@class, "name")]',
            './/div[contains(@class, "title")]',
            './/div[contains(@class, "heading")]',
            './/a[contains(@class, "name")]',
            './/span[contains(@class, "name")]',
            './/div[contains(@class, "obit-name")]',
            './/a[not(contains(@href, "javascript"))]'
        );
        
        // Try to find the name element
        foreach ($name_patterns as $pattern) {
            $name_elements = $xpath->query($pattern, $element);
            if ($name_elements && $name_elements->length > 0) {
                $selectors['name'] = $pattern;
                break;
            }
        }
        
        // Common patterns for date elements
        $date_patterns = array(
            './/time',
            './/div[contains(@class, "date")]',
            './/span[contains(@class, "date")]',
            './/div[contains(text(), "died") or contains(text(), "passed")]',
            './/span[contains(text(), "died") or contains(text(), "passed")]',
            './/p[contains(text(), "died") or contains(text(), "passed")]'
        );
        
        // Try to find the date element
        foreach ($date_patterns as $pattern) {
            $date_elements = $xpath->query($pattern, $element);
            if ($date_elements && $date_elements->length > 0) {
                $selectors['date'] = $pattern;
                break;
            }
        }
        
        // Common patterns for image elements
        $image_patterns = array(
            './/img',
            './/div[contains(@class, "image")]//img',
            './/div[contains(@class, "photo")]//img',
            './/div[contains(@class, "thumbnail")]//img'
        );
        
        // Try to find the image element
        foreach ($image_patterns as $pattern) {
            $image_elements = $xpath->query($pattern, $element);
            if ($image_elements && $image_elements->length > 0) {
                $selectors['image'] = $pattern;
                break;
            }
        }
        
        // Common patterns for description elements
        $desc_patterns = array(
            './/div[contains(@class, "desc")]',
            './/div[contains(@class, "content")]',
            './/div[contains(@class, "text")]',
            './/p',
            './/div[contains(@class, "summary")]',
            './/div[contains(@class, "body")]'
        );
        
        // Try to find the description element
        foreach ($desc_patterns as $pattern) {
            $desc_elements = $xpath->query($pattern, $element);
            if ($desc_elements && $desc_elements->length > 0) {
                $selectors['description'] = $pattern;
                break;
            }
        }
        
        // Fill in any missing selectors with defaults
        if (empty($selectors['name'])) {
            $selectors['name'] = './/h3';
        }
        
        if (empty($selectors['date'])) {
            $selectors['date'] = './/span[contains(@class, "date")]';
        }
        
        if (empty($selectors['image'])) {
            $selectors['image'] = './/img';
        }
        
        if (empty($selectors['description'])) {
            $selectors['description'] = './/p';
        }
        
        return $selectors;
    }
    
    /**
     * Update the selectors for a region
     * 
     * @param string $region The region name
     * @param array $selectors The new selectors
     */
    private function update_region_selectors($region, $selectors) {
        // Get current selectors from options
        $region_selectors = get_option('ontario_obituaries_region_selectors', array());
        
        // Update this region's selectors
        $region_selectors[$region] = $selectors;
        
        // Save back to options
        update_option('ontario_obituaries_region_selectors', $region_selectors);
        
        // Log the update
        error_log(sprintf('Updated selectors for region %s: %s',
            $region, json_encode($selectors)));
    }
    
    /**
     * Parse a date string into a MySQL date format with enhanced recognition
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
            'F d, Y',       // January 01, 2023
            'M d, Y',       // Jan 01, 2023
            'd-m-Y',        // 01-01-2023
            'd.m.Y',        // 01.01.2023
        );
        
        foreach ($date_formats as $format) {
            $date = date_create_from_format($format, $date_string);
            if ($date) {
                return $date->format('Y-m-d');
            }
        }
        
        // Try to extract a date using various regex patterns
        $patterns = array(
            // ISO format: 2023-01-01
            '/\b(19|20)\d\d[-\/]\d{1,2}[-\/]\d{1,2}\b/',
            
            // MM/DD/YYYY or DD/MM/YYYY
            '/\b\d{1,2}[-\/\.]\d{1,2}[-\/\.](19|20)\d\d\b/',
            
            // Common English format with month name: January 1, 2023
            '/\b(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+\d{1,2}(?:st|nd|rd|th)?,?\s+(19|20)\d\d\b/i',
            
            // Date ranges - extract end date: January 1, 1950 - March 15, 2023
            '/\s+-\s+(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+\d{1,2}(?:st|nd|rd|th)?,?\s+(19|20)\d\d\b/i',
            
            // Patterns for "died on" or "passed away on" followed by a date
            '/(?:died|passed away)(?:\s+on)?\s+(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+\d{1,2}(?:st|nd|rd|th)?,?\s+(19|20)\d\d\b/i',
        );
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $date_string, $matches)) {
                $extracted_date = trim($matches[0]);
                
                // For "died on" pattern, remove the prefix
                $extracted_date = preg_replace('/(?:died|passed away)(?:\s+on)?\s+/i', '', $extracted_date);
                
                // For range pattern with dash, remove everything before the dash
                if (strpos($extracted_date, ' - ') !== false) {
                    $extracted_date = trim(substr($extracted_date, strpos($extracted_date, '-') + 1));
                }
                
                // Try to convert to timestamp
                $timestamp = strtotime($extracted_date);
                if ($timestamp) {
                    return date('Y-m-d', $timestamp);
                }
            }
        }
        
        // If still no date found, look for just year and month
        if (preg_match('/\b(19|20)\d\d\b/', $date_string, $year_matches) && 
            preg_match('/\b(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\b/i', $date_string, $month_matches)) {
            
            $year = $year_matches[0];
            $month = $month_matches[0];
            
            // Construct a date string with the 1st of the month
            $constructed_date = "$month 1, $year";
            $timestamp = strtotime($constructed_date);
            
            if ($timestamp) {
                return date('Y-m-d', $timestamp);
            }
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
     * Filter obituaries by max age
     * 
     * @param array $obituaries Array of obituary data
     * @param int $max_age Maximum age in days
