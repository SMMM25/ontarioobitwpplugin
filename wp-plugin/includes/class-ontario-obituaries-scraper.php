<?php
/**
 * Ontario Obituaries Scraper
 * 
 * Handles scraping obituaries from various sources
 */
class Ontario_Obituaries_Scraper {
    /**
     * Scrape obituaries from all configured sources
     * 
     * @return array Scraping log
     */
    public function scrape() {
        $settings = get_option('ontario_obituaries_settings', array());
        $enabled = isset($settings['enabled']) ? $settings['enabled'] : true;
        
        // If the scraper is disabled, return an empty log
        if (!$enabled) {
            return array(
                'sources_scraped' => 0,
                'obituaries_found' => 0,
                'obituaries_added' => 0,
                'errors' => array(),
            );
        }
        
        $regions = isset($settings['regions']) ? $settings['regions'] : array('Toronto', 'Ottawa', 'Hamilton');
        $max_age = isset($settings['max_age']) ? intval($settings['max_age']) : 7;
        $filter_keywords = isset($settings['filter_keywords']) ? $settings['filter_keywords'] : '';
        $filter_keywords = array_map('trim', explode(',', $filter_keywords));
        $filter_keywords = array_filter($filter_keywords);
        
        $log = array(
            'sources_scraped' => 0,
            'obituaries_found' => 0,
            'obituaries_added' => 0,
            'errors' => array(),
        );
        
        $obituaries_added = 0;
        
        // Get the current date
        $today = new DateTime();
        
        // Loop through each region
        foreach ($regions as $region) {
            // Get sources for this region
            $sources = $this->get_region_sources($region);
            
            // Loop through each source
            foreach ($sources as $source) {
                $log['sources_scraped']++;
                
                try {
                    // Scrape obituaries from the source
                    $obituaries = $this->scrape_source($source);
                    $log['obituaries_found'] += count($obituaries);
                    
                    // Loop through each obituary
                    foreach ($obituaries as $obituary) {
                        // Validate obituary data
                        if (empty($obituary['name']) || empty($obituary['date_of_death']) || empty($obituary['location']) || empty($obituary['funeral_home']) || empty($obituary['description'])) {
                            $log['errors'][] = sprintf(
                                __('Skipping obituary from %s due to missing data', 'ontario-obituaries'),
                                $source['name']
                            );
                            continue;
                        }
                        
                        // Filter by keywords
                        if (!empty($filter_keywords)) {
                            $match = false;
                            foreach ($filter_keywords as $keyword) {
                                if (stripos($obituary['name'], $keyword) !== false || stripos($obituary['description'], $keyword) !== false) {
                                    $match = true;
                                    break;
                                }
                            }
                            
                            if ($match) {
                                $log['errors'][] = sprintf(
                                    __('Skipping obituary from %s due to keyword filter', 'ontario-obituaries'),
                                    $source['name']
                                );
                                continue;
                            }
                        }
                        
                        // Check if the obituary already exists
                        global $wpdb;
                        $table_name = $wpdb->prefix . 'ontario_obituaries';
                        
                        $existing_obituary = $wpdb->get_row(
                            $wpdb->prepare(
                                "SELECT id FROM $table_name WHERE name = %s AND date_of_death = %s",
                                $obituary['name'],
                                $obituary['date_of_death']
                            )
                        );
                        
                        if ($existing_obituary) {
                            $log['errors'][] = sprintf(
                                __('Skipping obituary from %s because it already exists', 'ontario-obituaries'),
                                $source['name']
                            );
                            continue;
                        }
                        
                        // Insert the obituary into the database
                        $wpdb->insert(
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
                                'source_url' => $source['url'],
                            ),
                            array(
                                '%s',
                                '%d',
                                '%s',
                                '%s',
                                '%s',
                                '%s',
                                '%s',
                                '%s',
                                '%s',
                            )
                        );
                        
                        $obituary_id = $wpdb->insert_id;
                        
                        if ($obituary_id) {
                            $obituaries_added++;
                            
                            // Auto-publish the obituary as a post
                            $auto_publish = isset($settings['auto_publish']) ? $settings['auto_publish'] : true;
                            
                            if ($auto_publish) {
                                $post_id = wp_insert_post(array(
                                    'post_title' => $obituary['name'],
                                    'post_content' => $obituary['description'],
                                    'post_status' => 'publish',
                                    'post_date' => $obituary['date_of_death'],
                                    'meta_input' => array(
                                        'ontario_obituaries_id' => $obituary_id,
                                        'ontario_obituaries_funeral_home' => $obituary['funeral_home'],
                                        'ontario_obituaries_location' => $obituary['location'],
                                        'ontario_obituaries_source_url' => $source['url'],
                                    ),
                                ));
                                
                                if (is_wp_error($post_id)) {
                                    $log['errors'][] = sprintf(
                                        __('Error creating post for obituary %s: %s', 'ontario-obituaries'),
                                        $obituary['name'],
                                        $post_id->get_error_message()
                                    );
                                }
                            }
                            
                            // Trigger action for new obituary
                            $obituary_data = $wpdb->get_row(
                                $wpdb->prepare(
                                    "SELECT * FROM $table_name WHERE id = %d",
                                    $obituary_id
                                )
                            );
                            
                            do_action('ontario_obituaries_new_obituary', $obituary_data);
                        } else {
                            $log['errors'][] = sprintf(
                                __('Error inserting obituary %s into the database', 'ontario-obituaries'),
                                $obituary['name']
                            );
                        }
                    }
                } catch (Exception $e) {
                    $log['errors'][] = sprintf(
                        __('Error scraping %s: %s', 'ontario-obituaries'),
                        $source['name'],
                        $e->getMessage()
                    );
                }
            }
        }
        
        // Delete obituaries older than max_age
        $date_limit = $today->modify("-{$max_age} days")->format('Y-m-d');
        
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $table_name WHERE date_of_death < %s",
                $date_limit
            )
        );
        
        // Update the last scrape time
        update_option('ontario_obituaries_last_scrape', array(
            'completed' => date('Y-m-d H:i:s'),
            'sources_scraped' => $log['sources_scraped'],
            'obituaries_found' => $log['obituaries_found'],
            'obituaries_added' => $obituaries_added,
            'errors' => $log['errors'],
        ));
        
        // Send email to admin if new obituaries were found
        $notify_admin = isset($settings['notify_admin']) ? $settings['notify_admin'] : true;
        
        if ($notify_admin && $obituaries_added > 0) {
            $admin_email = get_option('admin_email');
            $subject = __('New Obituaries Found', 'ontario-obituaries');
            $message = sprintf(
                __('The Ontario Obituaries scraper found %d new obituaries.', 'ontario-obituaries'),
                $obituaries_added
            );
            
            wp_mail($admin_email, $subject, $message);
        }
        
        $log['obituaries_added'] = $obituaries_added;
        
        return $log;
    }
    
    /**
     * Test scraping for a specific region
     * 
     * @param string $region The region to test
     * @return array Result with success status, count and message
     */
    public function test_region($region) {
        try {
            // Initialize result
            $result = array(
                'success' => false,
                'count' => 0,
                'message' => '',
            );
            
            // Get sources for this region
            $sources = $this->get_region_sources($region);
            
            if (empty($sources)) {
                return array(
                    'success' => false,
                    'count' => 0,
                    'message' => __('No sources configured for this region', 'ontario-obituaries'),
                );
            }
            
            // Try to connect to one source as a test
            $test_source = $sources[0];
            $obituaries = $this->scrape_source($test_source);
            
            // If we get here, the connection was successful
            $result['success'] = true;
            $result['count'] = count($obituaries);
            
            return $result;
        } catch (Exception $e) {
            return array(
                'success' => false,
                'count' => 0,
                'message' => $e->getMessage(),
            );
        }
    }
    
    /**
     * Get sources for a specific region
     * 
     * @param string $region The region name
     * @return array Sources for the region
     */
    private function get_region_sources($region) {
        // This is a simplified version for demonstration
        // In a real implementation, you would have a database of sources for each region
        
        $sources = array();
        
        switch ($region) {
            case 'Toronto':
                $sources[] = array(
                    'name' => 'Toronto Funeral Home',
                    'url' => 'https://www.mountpleasantgroup.com/en-CA/Obituaries.aspx',
                    'selector' => '.obit-title a',
					'name_selector' => '.obit-title a',
					'date_selector' => '.obit-date',
					'location_selector' => '.obit-city',
					'description_selector' => '.obit-text',
                );
                break;
                
            case 'Ottawa':
                $sources[] = array(
                    'name' => 'Ottawa Citizen',
                    'url' => 'https://ottawacitizen.remembering.ca/search?dateRange=last30Days&sort=date&order=0',
                    'selector' => '.obit-title a',
					'name_selector' => '.obit-title a',
					'date_selector' => '.obit-date',
					'location_selector' => '.obit-city',
					'description_selector' => '.obit-text',
                );
                break;
                
            case 'Hamilton':
                $sources[] = array(
                    'name' => 'Hamilton Spectator',
                    'url' => 'https://www.arbormemorial.ca/en/dbacliffefu/obituaries',
                    'selector' => '.obit-title a',
					'name_selector' => '.obit-title a',
					'date_selector' => '.obit-date',
					'location_selector' => '.obit-city',
					'description_selector' => '.obit-text',
                );
                break;
                
            case 'London':
                $sources[] = array(
                    'name' => 'London Free Press',
                    'url' => 'https://lfpress.remembering.ca/search?dateRange=last30Days&sort=date&order=0',
                    'selector' => '.obit-title a',
					'name_selector' => '.obit-title a',
					'date_selector' => '.obit-date',
					'location_selector' => '.obit-city',
					'description_selector' => '.obit-text',
                );
                break;
                
            case 'Windsor':
                $sources[] = array(
                    'name' => 'Windsor Star',
                    'url' => 'https://windsorstar.remembering.ca/search?dateRange=last30Days&sort=date&order=0',
                    'selector' => '.obit-title a',
					'name_selector' => '.obit-title a',
					'date_selector' => '.obit-date',
					'location_selector' => '.obit-city',
					'description_selector' => '.obit-text',
                );
                break;
                
            default:
                // No sources for this region
                break;
        }
        
        return $sources;
    }
    
    /**
     * Scrape obituaries from a source
     * 
     * @param array $source The source configuration
     * @return array Scraped obituaries
     */
    private function scrape_source($source) {
        // This is a simplified version for demonstration
        // In a real implementation, you would use a library like Simple HTML DOM Parser
        
        // Try to fetch the page - this will validate if we can connect
        $response = wp_remote_get($source['url'], array(
            'timeout' => 15,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        ));
        
        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }
        
        if (wp_remote_retrieve_response_code($response) != 200) {
            throw new Exception(sprintf(
                __('Failed to connect to %s (HTTP code: %d)', 'ontario-obituaries'),
                $source['name'],
                wp_remote_retrieve_response_code($response)
            ));
        }
        
		$html = wp_remote_retrieve_body( $response );
		$dom = new DOMDocument();
		@$dom->loadHTML($html);
		$xpath = new DOMXPath($dom);
	
		$obituaries = array();
	
		$elements = $xpath->query($source['selector']);
	
		foreach ($elements as $element) {
			$name = $xpath->query($source['name_selector'], $element)->item(0)->textContent;
			$date_of_death = $xpath->query($source['date_selector'], $element)->item(0)->textContent;
			$location = $xpath->query($source['location_selector'], $element)->item(0)->textContent;
			$description = $xpath->query($source['description_selector'], $element)->item(0)->textContent;
	
			$obituaries[] = array(
				'name' => $name,
				'date_of_death' => $date_of_death,
				'location' => $location,
				'description' => $description,
				'funeral_home' => $source['name'],
			);
		}
	
		return $obituaries;
    }
}
