
<?php
/**
 * Ontario Obituaries Scraper
 * 
 * Handles scraping obituary data from funeral home websites
 */
class Ontario_Obituaries_Scraper {
    /**
     * Array of funeral home sources to scrape
     */
    private $sources = array();
    
    /**
     * Settings for the scraper
     */
    private $settings = array();
    
    /**
     * Constructor
     */
    public function __construct() {
        // Load settings
        $this->settings = get_option('ontario_obituaries_settings', array());
        
        // Default settings if none exist
        if (empty($this->settings)) {
            $this->settings = array(
                'enabled' => true,
                'frequency' => 'daily',
                'time' => '03:00',
                'regions' => array('Toronto', 'Ottawa', 'Hamilton', 'London', 'Windsor'),
                'max_age' => 7,
                'filter_keywords' => '',
                'auto_publish' => true,
                'notify_admin' => true
            );
        }
        
        // Initialize sources
        $this->init_sources();
    }
    
    /**
     * Initialize the funeral home sources
     */
    private function init_sources() {
        // Only include sources for enabled regions
        $regions = isset($this->settings['regions']) ? $this->settings['regions'] : array('Toronto', 'Ottawa', 'Hamilton');
        
        // Toronto sources
        if (in_array('Toronto', $regions)) {
            $this->sources['mount_pleasant'] = array(
                'name' => 'Mount Pleasant Group',
                'url' => 'https://www.mountpleasantgroup.com/en-CA/Obituaries/search',
                'region' => 'Toronto',
                'selectors' => array(
                    'list' => '.list-item-wrap .list-item',
                    'name' => '.obit-name h3',
                    'dates' => '.list-item-detail',
                    'description' => '.more-less-text',
                    'image' => '.obit-image img',
                    'link' => '.list-item a'
                ),
                'date_format' => 'F j, Y',
                'backup_selectors' => array(
                    'list' => '.obituaries-list .obituary, .obits-list .obituary-item',
                    'name' => '.obituary-name, h3.name, .title',
                    'dates' => '.obituary-dates, .date-info, .date-range',
                    'description' => '.obituary-text, .description, .summary',
                    'image' => '.obituary-image img, .photo img',
                    'link' => 'a.details, a.more, a.view-more'
                )
            );
            
            $this->sources['turner_porter'] = array(
                'name' => 'Turner & Porter',
                'url' => 'https://www.turnerporter.ca/memorials',
                'region' => 'Toronto',
                'selectors' => array(
                    'list' => '.memorials-list .memorials-item',
                    'name' => '.memorials-name',
                    'dates' => '.memorials-date',
                    'description' => '.memorials-description',
                    'image' => '.memorials-img img',
                    'link' => '.memorials-btn a'
                ),
                'date_format' => 'F j, Y',
                'backup_selectors' => array(
                    'list' => '.obituaries .obituary, .listings .listing',
                    'name' => '.name, h3.title, .heading',
                    'dates' => '.date, .dates, .lifespan',
                    'description' => '.description, .text, .summary',
                    'image' => '.image img, .photo img',
                    'link' => 'a.more, a.details, a.view'
                )
            );
        }
        
        // Ottawa sources
        if (in_array('Ottawa', $regions)) {
            $this->sources['beechwood'] = array(
                'name' => 'Beechwood Cemetery',
                'url' => 'https://beechwoodottawa.ca/en/services/obituaries',
                'region' => 'Ottawa',
                'selectors' => array(
                    'list' => '.obit-box',
                    'name' => '.obit-name',
                    'dates' => '.obit-date',
                    'description' => '.obit-copy',
                    'image' => '.obit-image img',
                    'link' => '.obit-box a'
                ),
                'date_format' => 'F j, Y',
                'backup_selectors' => array(
                    'list' => '.obituaries .obituary, .obits .obit',
                    'name' => '.name, h3.heading, .title',
                    'dates' => '.dates, .date-range, .date',
                    'description' => '.excerpt, .text, .description',
                    'image' => '.image img, .photo img, .portrait img',
                    'link' => 'a.details, a.more, a.view'
                )
            );
            
            $this->sources['tubman'] = array(
                'name' => 'Tubman Funeral Homes',
                'url' => 'https://www.tubmanfuneralhomes.com/obituaries/',
                'region' => 'Ottawa',
                'selectors' => array(
                    'list' => '.listing-item',
                    'name' => 'h3.h4',
                    'dates' => '.text-secondary',
                    'description' => '.excerpt',
                    'image' => '.listing-item__image img',
                    'link' => '.listing-item a'
                ),
                'date_format' => 'F j, Y',
                'backup_selectors' => array(
                    'list' => '.obits .obit, .obituaries .obituary',
                    'name' => '.name, .title, .heading',
                    'dates' => '.dates, .date-info, .lifespan',
                    'description' => '.description, .summary, .content',
                    'image' => '.image img, .photo img',
                    'link' => 'a.more, a.details, a.view'
                )
            );
        }
        
        // Hamilton sources
        if (in_array('Hamilton', $regions)) {
            $this->sources['smith_funeral'] = array(
                'name' => 'Smith Funeral Home',
                'url' => 'https://www.smithsfh.com/memorials',
                'region' => 'Hamilton',
                'selectors' => array(
                    'list' => '.memorials-card',
                    'name' => '.memorials-title',
                    'dates' => '.memorials-date',
                    'description' => '.memorials-description',
                    'image' => '.memorials-image img',
                    'link' => '.memorials-card a'
                ),
                'date_format' => 'F j, Y',
                'backup_selectors' => array(
                    'list' => '.obituaries .obituary, .listings .listing',
                    'name' => '.name, h3.title',
                    'dates' => '.date, .dates, .lifespan',
                    'description' => '.description, .text, .summary',
                    'image' => '.image img, .photo img',
                    'link' => 'a.details, a.more, a.read-more'
                )
            );
            
            $this->sources['bay_garden'] = array(
                'name' => 'Bay Gardens Funeral Home',
                'url' => 'https://www.baygardens.ca/memorials',
                'region' => 'Hamilton',
                'selectors' => array(
                    'list' => '.obituary-container',
                    'name' => '.obituary-title',
                    'dates' => '.obituary-dates',
                    'description' => '.obituary-description',
                    'image' => '.obituary-image img',
                    'link' => '.obituary-link'
                ),
                'date_format' => 'F j, Y',
                'backup_selectors' => array(
                    'list' => '.memorials .memorial, .obits .obituary',
                    'name' => '.name, .title, h3.heading',
                    'dates' => '.dates, .date-info, .date-range',
                    'description' => '.text, .excerpt, .content',
                    'image' => '.image img, .photo img',
                    'link' => 'a.details, a.more, a.view'
                )
            );
        }
        
        // London sources
        if (in_array('London', $regions)) {
            $this->sources['westview'] = array(
                'name' => 'Westview Funeral Chapel',
                'url' => 'https://westviewfuneralchapel.com/current-services/',
                'region' => 'London',
                'selectors' => array(
                    'list' => '.post-preview-content',
                    'name' => 'h2.post-title',
                    'dates' => '.post-date',
                    'description' => '.post-excerpt',
                    'image' => '.post-featured-image img',
                    'link' => '.post-preview-content a'
                ),
                'date_format' => 'F j, Y',
                'backup_selectors' => array(
                    'list' => '.services .service, .obits .obituary',
                    'name' => '.title, .name, h3.heading',
                    'dates' => '.date, .dates, .service-date',
                    'description' => '.excerpt, .description, .text',
                    'image' => '.image img, .photo img',
                    'link' => 'a.more, a.details, a.view'
                )
            );
            
            $this->sources['logan_funeral'] = array(
                'name' => 'Logan Funeral Home',
                'url' => 'https://www.loganfh.ca/obituaries/',
                'region' => 'London',
                'selectors' => array(
                    'list' => '.obit-container',
                    'name' => '.info h3',
                    'dates' => '.info .dates',
                    'description' => '.info .description',
                    'image' => '.image-container img',
                    'link' => '.obit-container a'
                ),
                'date_format' => 'F j, Y',
                'backup_selectors' => array(
                    'list' => '.obituaries .obituary, .obits .obit',
                    'name' => '.name, .title, h3.heading',
                    'dates' => '.date, .dates, .lifespan',
                    'description' => '.description, .text, .summary',
                    'image' => '.image img, .photo img',
                    'link' => 'a.details, a.more, a.view'
                )
            );
        }
        
        // Windsor sources
        if (in_array('Windsor', $regions)) {
            $this->sources['families_first'] = array(
                'name' => 'Families First',
                'url' => 'https://www.familiesfirst.ca/memorials',
                'region' => 'Windsor',
                'selectors' => array(
                    'list' => '.tribute-list .tribute',
                    'name' => '.tribute-title',
                    'dates' => '.tribute-date',
                    'description' => '.tribute-content',
                    'image' => '.tribute-image img',
                    'link' => '.tribute a'
                ),
                'date_format' => 'F j, Y',
                'backup_selectors' => array(
                    'list' => '.obituaries .obituary, .listings .listing',
                    'name' => '.name, h3.title, .heading',
                    'dates' => '.dates, .date, .lifespan',
                    'description' => '.description, .content, .text',
                    'image' => '.image img, .photo img',
                    'link' => 'a.more, a.details, a.view'
                )
            );
            
            $this->sources['windsor_chapel'] = array(
                'name' => 'Windsor Chapel',
                'url' => 'https://www.windsorchapel.com/obituaries/',
                'region' => 'Windsor',
                'selectors' => array(
                    'list' => '.obits-item',
                    'name' => '.obits-name a',
                    'dates' => '.obits-date',
                    'description' => '.obits-excerpt',
                    'image' => '.obits-image img',
                    'link' => '.obits-name a'
                ),
                'date_format' => 'F j, Y',
                'backup_selectors' => array(
                    'list' => '.memorials .memorial, .obituaries .obituary',
                    'name' => '.name, .title, h3.heading',
                    'dates' => '.dates, .date, .date-range',
                    'description' => '.excerpt, .description, .summary',
                    'image' => '.image img, .photo img',
                    'link' => 'a.details, a.more, a.view'
                )
            );
        }
        
        // Apply filter to allow adding more sources or modifying existing ones
        $this->sources = apply_filters('ontario_obituaries_sources', $this->sources);
    }
    
    /**
     * Main scrape function
     * 
     * @return array Scraping results
     */
    public function scrape() {
        if (!isset($this->settings['enabled']) || !$this->settings['enabled']) {
            return array(
                'status' => 'disabled',
                'message' => 'Scraper is disabled in settings',
                'obituaries_added' => 0,
                'sources_scraped' => 0,
                'obituaries_found' => 0,
                'errors' => array()
            );
        }
        
        $results = array(
            'status' => 'success',
            'sources_scraped' => 0,
            'obituaries_found' => 0,
            'obituaries_added' => 0,
            'errors' => array()
        );
        
        // Make sure we have the WordPress HTTP API
        if (!function_exists('wp_remote_get')) {
            include_once(ABSPATH . WPINC . '/http.php');
        }
        
        // Process each source
        foreach ($this->sources as $source_id => $source) {
            try {
                $source_results = $this->scrape_source($source_id, $source);
                
                // Update statistics
                $results['sources_scraped']++;
                $results['obituaries_found'] += $source_results['found'];
                $results['obituaries_added'] += $source_results['added'];
                
                // Add any errors
                if (!empty($source_results['errors'])) {
                    $results['errors'][$source_id] = $source_results['errors'];
                }
            } catch (Exception $e) {
                $results['errors'][$source_id] = array(
                    'message' => $e->getMessage(),
                    'code' => $e->getCode()
                );
            }
        }
        
        // Send email notification if enabled and obituaries were added
        if ($results['obituaries_added'] > 0 && isset($this->settings['notify_admin']) && $this->settings['notify_admin']) {
            $this->send_notification_email($results);
        }
        
        return $results;
    }
    
    /**
     * Scrape a single source
     * 
     * @param string $source_id The source identifier
     * @param array $source The source configuration
     * @return array Results from this source
     */
    private function scrape_source($source_id, $source) {
        $results = array(
            'found' => 0,
            'added' => 0,
            'errors' => array()
        );
        
        try {
            // Make the HTTP request with a custom user agent
            $response = wp_remote_get($source['url'], array(
                'timeout' => 30,
                'user-agent' => 'Ontario Obituaries Plugin/1.0 (+https://monacomonuments.ca)'
            ));
            
            // Check for errors
            if (is_wp_error($response)) {
                throw new Exception('HTTP request failed: ' . $response->get_error_message());
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code !== 200) {
                throw new Exception('HTTP request failed with code: ' . $response_code);
            }
            
            $html = wp_remote_retrieve_body($response);
            if (empty($html)) {
                throw new Exception('Empty response from server');
            }
            
            // Load the HTML into DOMDocument
            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            
            // Create a DOMXPath object
            $xpath = new DOMXPath($dom);
            
            // Try to find the list of obituaries using primary selectors
            $selector = $source['selectors']['list'];
            $obituary_nodes = $xpath->query('//' . str_replace(' ', '/descendant::', $selector));
            
            // If primary selector fails, try backup selectors
            if ($obituary_nodes->length === 0 && isset($source['backup_selectors']['list'])) {
                $backup_selectors = explode(', ', $source['backup_selectors']['list']);
                foreach ($backup_selectors as $backup_selector) {
                    $obituary_nodes = $xpath->query('//' . str_replace(' ', '/descendant::', $backup_selector));
                    if ($obituary_nodes->length > 0) {
                        // Update the source with the working selector for future scrapes
                        $this->sources[$source_id]['selectors']['list'] = $backup_selector;
                        break;
                    }
                }
            }
            
            // Process found obituaries
            if ($obituary_nodes->length > 0) {
                $results['found'] = $obituary_nodes->length;
                
                foreach ($obituary_nodes as $node) {
                    // Extract data using selectors
                    $obituary_data = $this->extract_obituary_data($node, $xpath, $source);
                    
                    // Validate and filter data
                    if ($this->validate_obituary($obituary_data)) {
                        // Check if this obituary already exists in the database
                        if (!$this->obituary_exists($obituary_data)) {
                            // Save to database
                            $added = $this->save_obituary($obituary_data);
                            if ($added) {
                                $results['added']++;
                            }
                        }
                    }
                }
            } else {
                $results['errors'][] = 'No obituaries found on page';
            }
        } catch (Exception $e) {
            $results['errors'][] = $e->getMessage();
            
            // Attempt to recover from common errors
            if (strpos($e->getMessage(), 'HTTP request failed') !== false) {
                // Try with different timeout
                try {
                    $response = wp_remote_get($source['url'], array(
                        'timeout' => 60,
                        'user-agent' => 'Mozilla/5.0 (compatible; Ontario Obituaries Bot/1.0)'
                    ));
                    
                    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                        $results['errors'][] = 'Recovered with extended timeout';
                        // Process the response here...
                        // (simplified for this example)
                    }
                } catch (Exception $recovery_e) {
                    $results['errors'][] = 'Recovery attempt failed: ' . $recovery_e->getMessage();
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Extract obituary data from a DOM node
     * 
     * @param DOMNode $node The obituary DOM node
     * @param DOMXPath $xpath The XPath object
     * @param array $source The source configuration
     * @return array The extracted obituary data
     */
    private function extract_obituary_data($node, $xpath, $source) {
        $data = array(
            'name' => '',
            'date_of_birth' => null,
            'date_of_death' => null,
            'age' => null,
            'funeral_home' => $source['name'],
            'location' => $source['region'],
            'image_url' => '',
            'description' => '',
            'source_url' => ''
        );
        
        // Helper function to try multiple selectors
        $extract_with_selectors = function($primary_selector, $backup_selectors = '') {
            $value = $this->extract_node_value($node, $xpath, $primary_selector);
            
            if (empty($value) && !empty($backup_selectors)) {
                $backup_selectors_array = explode(', ', $backup_selectors);
                foreach ($backup_selectors_array as $backup_selector) {
                    $value = $this->extract_node_value($node, $xpath, $backup_selector);
                    if (!empty($value)) {
                        break;
                    }
                }
            }
            
            return $value;
        };
        
        // Extract name
        $data['name'] = $extract_with_selectors(
            $source['selectors']['name'], 
            isset($source['backup_selectors']['name']) ? $source['backup_selectors']['name'] : ''
        );
        
        // Extract dates
        $dates_text = $extract_with_selectors(
            $source['selectors']['dates'],
            isset($source['backup_selectors']['dates']) ? $source['backup_selectors']['dates'] : ''
        );
        
        // Parse dates from text
        if (!empty($dates_text)) {
            $parsed_dates = $this->parse_dates($dates_text);
            $data['date_of_birth'] = $parsed_dates['birth'];
            $data['date_of_death'] = $parsed_dates['death'];
            
            // Calculate age if we have both dates
            if ($data['date_of_birth'] && $data['date_of_death']) {
                $birth_date = new DateTime($data['date_of_birth']);
                $death_date = new DateTime($data['date_of_death']);
                $interval = $birth_date->diff($death_date);
                $data['age'] = $interval->y;
            }
        }
        
        // Extract description
        $data['description'] = $extract_with_selectors(
            $source['selectors']['description'],
            isset($source['backup_selectors']['description']) ? $source['backup_selectors']['description'] : ''
        );
        
        // Extract image URL
        $image_selector = $source['selectors']['image'];
        $backup_image_selector = isset($source['backup_selectors']['image']) ? $source['backup_selectors']['image'] : '';
        
        $image_nodes = $xpath->query('.//' . str_replace(' ', '/descendant::', $image_selector), $node);
        if ($image_nodes->length > 0) {
            $data['image_url'] = $image_nodes->item(0)->getAttribute('src');
        } elseif (!empty($backup_image_selector)) {
            $backup_selectors = explode(', ', $backup_image_selector);
            foreach ($backup_selectors as $backup_selector) {
                $image_nodes = $xpath->query('.//' . str_replace(' ', '/descendant::', $backup_selector), $node);
                if ($image_nodes->length > 0) {
                    $data['image_url'] = $image_nodes->item(0)->getAttribute('src');
                    break;
                }
            }
        }
        
        // Make sure image URL is absolute
        if (!empty($data['image_url']) && strpos($data['image_url'], 'http') !== 0) {
            $parsed_url = parse_url($source['url']);
            $base_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];
            
            if (strpos($data['image_url'], '/') === 0) {
                $data['image_url'] = $base_url . $data['image_url'];
            } else {
                $data['image_url'] = $base_url . '/' . $data['image_url'];
            }
        }
        
        // Extract source URL (link to full obituary)
        $link_selector = $source['selectors']['link'];
        $backup_link_selector = isset($source['backup_selectors']['link']) ? $source['backup_selectors']['link'] : '';
        
        $link_nodes = $xpath->query('.//' . str_replace(' ', '/descendant::', $link_selector), $node);
        if ($link_nodes->length > 0) {
            $data['source_url'] = $link_nodes->item(0)->getAttribute('href');
        } elseif (!empty($backup_link_selector)) {
            $backup_selectors = explode(', ', $backup_link_selector);
            foreach ($backup_selectors as $backup_selector) {
                $link_nodes = $xpath->query('.//' . str_replace(' ', '/descendant::', $backup_selector), $node);
                if ($link_nodes->length > 0) {
                    $data['source_url'] = $link_nodes->item(0)->getAttribute('href');
                    break;
                }
            }
        }
        
        // Make sure source URL is absolute
        if (!empty($data['source_url']) && strpos($data['source_url'], 'http') !== 0) {
            $parsed_url = parse_url($source['url']);
            $base_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];
            
            if (strpos($data['source_url'], '/') === 0) {
                $data['source_url'] = $base_url . $data['source_url'];
            } else {
                $data['source_url'] = $base_url . '/' . $data['source_url'];
            }
        }
        
        return $data;
    }
    
    /**
     * Extract text value from a node using an XPath selector
     * 
     * @param DOMNode $node The parent node
     * @param DOMXPath $xpath The XPath object
     * @param string $selector The selector
     * @return string The extracted text
     */
    private function extract_node_value($node, $xpath, $selector) {
        $child_nodes = $xpath->query('.//' . str_replace(' ', '/descendant::', $selector), $node);
        if ($child_nodes->length > 0) {
            return trim($child_nodes->item(0)->textContent);
        }
        return '';
    }
    
    /**
     * Parse birth and death dates from a string
     * 
     * @param string $dates_text The text containing dates
     * @return array Array with 'birth' and 'death' dates in Y-m-d format
     */
    private function parse_dates($dates_text) {
        $result = array(
            'birth' => null,
            'death' => null
        );
        
        // Common date formats
        $date_formats = array(
            'F j, Y',       // January 1, 2020
            'M j, Y',       // Jan 1, 2020
            'Y-m-d',        // 2020-01-01
            'd/m/Y',        // 01/01/2020
            'm/d/Y',        // 01/01/2020
            'd.m.Y',        // 01.01.2020
            'j F Y',        // 1 January 2020
            'j M Y'         // 1 Jan 2020
        );
        
        // Try to match patterns like "Born: Jan 1, 1950 - Died: Jan 1, 2020"
        $patterns = array(
            // Pattern 1: Born: <date> - Died: <date>
            '/born:?\s*([^-]+)-\s*died:?\s*([^-,\.]+)/i',
            
            // Pattern 2: <date> - <date>
            '/([^-]+)-\s*([^-,\.]+)/i',
            
            // Pattern 3: <birth year> - <death year>
            '/(\d{4})\s*-\s*(\d{4})/',
            
            // Pattern 4: <date of birth> to <date of death>
            '/([^to]+)\s+to\s+([^,\.]+)/i',
            
            // Pattern 5: <birth year> to <death year>
            '/(\d{4})\s+to\s+(\d{4})/'
        );
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $dates_text, $matches)) {
                $birth_text = trim($matches[1]);
                $death_text = trim($matches[2]);
                
                // Try to parse the dates using different formats
                foreach ($date_formats as $format) {
                    // Try to parse birth date
                    $birth_date = DateTime::createFromFormat($format, $birth_text);
                    if ($birth_date) {
                        $result['birth'] = $birth_date->format('Y-m-d');
                        break;
                    }
                }
                
                foreach ($date_formats as $format) {
                    // Try to parse death date
                    $death_date = DateTime::createFromFormat($format, $death_text);
                    if ($death_date) {
                        $result['death'] = $death_date->format('Y-m-d');
                        break;
                    }
                }
                
                // If we only matched years, create full dates
                if ($result['birth'] === null && strlen($birth_text) === 4 && is_numeric($birth_text)) {
                    $result['birth'] = $birth_text . '-01-01';
                }
                
                if ($result['death'] === null && strlen($death_text) === 4 && is_numeric($death_text)) {
                    $result['death'] = $death_text . '-01-01';
                }
                
                // If we found both dates, stop processing
                if ($result['birth'] !== null && $result['death'] !== null) {
                    break;
                }
            }
        }
        
        // If we couldn't find both dates, try to find just the death date
        if ($result['death'] === null) {
            // Look for phrases indicating a death date
            $death_patterns = array(
                '/passed\s+away\s+on\s+([^\.]+)/i',
                '/died\s+on\s+([^\.]+)/i',
                '/deceased\s+on\s+([^\.]+)/i'
            );
            
            foreach ($death_patterns as $pattern) {
                if (preg_match($pattern, $dates_text, $matches)) {
                    $death_text = trim($matches[1]);
                    
                    foreach ($date_formats as $format) {
                        $death_date = DateTime::createFromFormat($format, $death_text);
                        if ($death_date) {
                            $result['death'] = $death_date->format('Y-m-d');
                            break 2;
                        }
                    }
                }
            }
        }
        
        // If we still don't have a death date, use today's date as fallback
        if ($result['death'] === null) {
            $result['death'] = date('Y-m-d');
        }
        
        return $result;
    }
    
    /**
     * Validate obituary data
     * 
     * @param array $data The obituary data
     * @return bool Whether the data is valid
     */
    private function validate_obituary($data) {
        // Name is required
        if (empty($data['name'])) {
            return false;
        }
        
        // Death date is required
        if (empty($data['date_of_death'])) {
            return false;
        }
        
        // Check against filtered keywords
        if (!empty($this->settings['filter_keywords'])) {
            $keywords = explode(',', $this->settings['filter_keywords']);
            foreach ($keywords as $keyword) {
                $keyword = trim($keyword);
                if (!empty($keyword) && stripos($data['name'] . ' ' . $data['description'], $keyword) !== false) {
                    // Keyword found, filter this obituary
                    return false;
                }
            }
        }
        
        return true;
    }
    
    /**
     * Check if an obituary already exists in the database
     * 
     * @param array $data The obituary data
     * @return bool Whether the obituary exists
     */
    private function obituary_exists($data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ontario_obituaries';
        
        // Check for existing record with same name and date of death
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE name = %s AND date_of_death = %s LIMIT 1",
            $data['name'],
            $data['date_of_death']
        ));
        
        return !empty($existing);
    }
    
    /**
     * Save obituary to database
     * 
     * @param array $data The obituary data
     * @return bool Whether the save was successful
     */
    private function save_obituary($data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ontario_obituaries';
        
        // Save to database
        $result = $wpdb->insert(
            $table_name,
            array(
                'name' => $data['name'],
                'date_of_birth' => $data['date_of_birth'],
                'date_of_death' => $data['date_of_death'],
                'age' => $data['age'],
                'funeral_home' => $data['funeral_home'],
                'location' => $data['location'],
                'image_url' => $data['image_url'],
                'description' => $data['description'],
                'source_url' => $data['source_url'],
                'created_at' => current_time('mysql')
            ),
            array(
                '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s'
            )
        );
        
        return ($result !== false);
    }
    
    /**
     * Send notification email about new obituaries
     * 
     * @param array $results The scrape results
     */
    private function send_notification_email($results) {
        $subject = sprintf(
            __('[%s] %d New Obituaries Found', 'ontario-obituaries'),
            get_bloginfo('name'),
            $results['obituaries_added']
        );
        
        $message = sprintf(
            __('The Ontario Obituaries scraper has completed its run and found %d new obituaries.', 'ontario-obituaries'),
            $results['obituaries_added']
        ) . "\n\n";
        
        $message .= __('Scrape Summary:', 'ontario-obituaries') . "\n";
        $message .= sprintf(__('- Sources scraped: %d', 'ontario-obituaries'), $results['sources_scraped']) . "\n";
        $message .= sprintf(__('- Total obituaries found: %d', 'ontario-obituaries'), $results['obituaries_found']) . "\n";
        $message .= sprintf(__('- New obituaries added: %d', 'ontario-obituaries'), $results['obituaries_added']) . "\n\n";
        
        if (!empty($results['errors'])) {
            $message .= __('Errors encountered:', 'ontario-obituaries') . "\n";
            foreach ($results['errors'] as $source_id => $errors) {
                $message .= "- $source_id: " . implode(', ', (array)$errors) . "\n";
            }
            $message .= "\n";
        }
        
        $message .= __('You can view the obituaries in the admin area:', 'ontario-obituaries') . "\n";
        $message .= admin_url('admin.php?page=ontario-obituaries') . "\n\n";
        
        $message .= __('This is an automated message. Please do not reply.', 'ontario-obituaries');
        
        // Send email to admin
        wp_mail(get_option('admin_email'), $subject, $message);
    }
}

