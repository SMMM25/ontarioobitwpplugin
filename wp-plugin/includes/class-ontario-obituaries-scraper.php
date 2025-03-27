
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
     * Debug mode
     */
    private $debug = true;
    
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
                    'list' => '.obituary-listing-item',
                    'name' => '.obituary-listing-item__name',
                    'dates' => '.obituary-listing-item__date',
                    'description' => '.obituary-listing-item__description',
                    'image' => '.obituary-listing-item__image img',
                    'link' => '.obituary-listing-item__link'
                ),
                'date_format' => 'F j, Y',
                'backup_selectors' => array(
                    'list' => '.obituary-list .obituary-item, .obituaries-list .obituary, .obits-list .obituary-item',
                    'name' => '.obituary-name, h3.name, .title, .name a',
                    'dates' => '.obituary-dates, .date-info, .date-range, .date',
                    'description' => '.obituary-text, .description, .summary, .content',
                    'image' => '.obituary-image img, .photo img, img.photo',
                    'link' => 'a.details, a.more, a.view-more, a.name'
                )
            );
            
            $this->sources['jerrett'] = array(
                'name' => 'Jerrett Funeral Homes',
                'url' => 'https://www.jerrettfuneralhomes.com/obituaries/',
                'region' => 'Toronto',
                'selectors' => array(
                    'list' => '.obits-item',
                    'name' => '.obits-name',
                    'dates' => '.obits-date',
                    'description' => '.obits-excerpt',
                    'image' => '.obits-image img',
                    'link' => '.obits-name a'
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
                    'list' => '.view-content .views-row',
                    'name' => 'h3.field-content',
                    'dates' => '.views-field-field-date',
                    'description' => '.views-field-body',
                    'image' => '.views-field-field-image img',
                    'link' => 'h3.field-content a'
                ),
                'date_format' => 'F j, Y',
                'backup_selectors' => array(
                    'list' => '.obituaries .obituary, .obits .obit, article',
                    'name' => '.name, h3.heading, .title, h2',
                    'dates' => '.dates, .date-range, .date, time',
                    'description' => '.excerpt, .text, .description, .summary',
                    'image' => '.image img, .photo img, .portrait img',
                    'link' => 'a.details, a.more, a.view, h2 a'
                )
            );
            
            $this->sources['kellyfh'] = array(
                'name' => 'Kelly Funeral Home',
                'url' => 'https://www.kellyfh.ca/recent-obituaries/',
                'region' => 'Ottawa',
                'selectors' => array(
                    'list' => '.obituary',
                    'name' => '.obituary h3',
                    'dates' => '.obit-info p',
                    'description' => '.obituary-text',
                    'image' => '.obituary-image img',
                    'link' => '.obituary a'
                ),
                'date_format' => 'F j, Y',
                'backup_selectors' => array(
                    'list' => '.obits .obit, .obituaries .obituary, .content article',
                    'name' => '.name, .title, .heading, h2, h3',
                    'dates' => '.dates, .date-info, .lifespan, time, .date',
                    'description' => '.description, .summary, .content, .text',
                    'image' => '.image img, .photo img, figure img',
                    'link' => 'a.more, a.details, a.view, h3 a'
                )
            );
        }
        
        // Hamilton sources
        if (in_array('Hamilton', $regions)) {
            $this->sources['smith_funeral'] = array(
                'name' => 'Smith Funeral Home',
                'url' => 'https://www.smithsfh.com/obits',
                'region' => 'Hamilton',
                'selectors' => array(
                    'list' => '.obits',
                    'name' => '.obits-name',
                    'dates' => '.obits-date',
                    'description' => '.obits-excerpt',
                    'image' => '.obits-image img',
                    'link' => '.obits-name a'
                ),
                'date_format' => 'F j, Y',
                'backup_selectors' => array(
                    'list' => '.obituaries .obituary, .listings .listing, article',
                    'name' => '.name, h3.title, h2, .heading',
                    'dates' => '.date, .dates, .lifespan, time',
                    'description' => '.description, .text, .summary, .content',
                    'image' => '.image img, .photo img, figure img',
                    'link' => 'a.details, a.more, a.read-more, h2 a'
                )
            );
            
            $this->sources['bay_garden'] = array(
                'name' => 'Bay Gardens Funeral Home',
                'url' => 'https://www.baygardens.ca/listings.php',
                'region' => 'Hamilton',
                'selectors' => array(
                    'list' => '.listing',
                    'name' => '.listing-name',
                    'dates' => '.listing-date',
                    'description' => '.listing-text',
                    'image' => '.listing-photo img',
                    'link' => '.listing a'
                ),
                'date_format' => 'F j, Y',
                'backup_selectors' => array(
                    'list' => '.memorials .memorial, .obits .obituary, article',
                    'name' => '.name, .title, h3.heading, h2',
                    'dates' => '.dates, .date-info, .date-range, .date',
                    'description' => '.text, .excerpt, .content, p',
                    'image' => '.image img, .photo img, figure img',
                    'link' => 'a.details, a.more, a.view, h2 a'
                )
            );
        }
        
        // London sources
        if (in_array('London', $regions)) {
            $this->sources['westview'] = array(
                'name' => 'Westview Funeral Chapel',
                'url' => 'https://westviewfuneralchapel.com/current-services/',
                'region' => 'London, ON',
                'selectors' => array(
                    'list' => 'article',
                    'name' => 'h2.entry-title',
                    'dates' => '.entry-meta .posted-on',
                    'description' => '.entry-content',
                    'image' => '.post-thumbnail img',
                    'link' => 'h2.entry-title a'
                ),
                'date_format' => 'F j, Y',
                'backup_selectors' => array(
                    'list' => '.services .service, .obits .obituary, .post',
                    'name' => '.title, .name, h3.heading, h2, .entry-title',
                    'dates' => '.date, .dates, .service-date, .posted-on',
                    'description' => '.excerpt, .description, .text, .content',
                    'image' => '.image img, .photo img, figure img',
                    'link' => 'a.more, a.details, a.view, h2 a'
                )
            );
            
            $this->sources['harrisfuneral'] = array(
                'name' => 'Harris Funeral Home',
                'url' => 'https://www.harrisfuneral.ca/obituaries',
                'region' => 'London, ON',
                'selectors' => array(
                    'list' => '.obituary-item',
                    'name' => '.obituary-title',
                    'dates' => '.obituary-date',
                    'description' => '.obituary-excerpt',
                    'image' => '.obituary-image img',
                    'link' => '.obituary-item a'
                ),
                'date_format' => 'F j, Y',
                'backup_selectors' => array(
                    'list' => '.obituaries .obituary, .obits .obit, article',
                    'name' => '.name, .title, h3.heading, h2',
                    'dates' => '.date, .dates, .lifespan, time',
                    'description' => '.description, .text, .summary, p',
                    'image' => '.image img, .photo img, figure img',
                    'link' => 'a.details, a.more, a.view, h2 a'
                )
            );
        }
        
        // Windsor sources
        if (in_array('Windsor', $regions)) {
            $this->sources['windsorchapel'] = array(
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
                    'list' => '.memorials .memorial, .obituaries .obituary, article',
                    'name' => '.name, .title, h3.heading, h2',
                    'dates' => '.dates, .date, .date-range, time',
                    'description' => '.excerpt, .description, .summary, .content',
                    'image' => '.image img, .photo img, figure img',
                    'link' => 'a.details, a.more, a.view, h2 a'
                )
            );
            
            $this->sources['familiesfirst'] = array(
                'name' => 'Families First',
                'url' => 'https://familiesfirst.ca/obits',
                'region' => 'Windsor',
                'selectors' => array(
                    'list' => '.obit-listing',
                    'name' => '.obit-name',
                    'dates' => '.obit-date',
                    'description' => '.obit-description',
                    'image' => '.obit-image img',
                    'link' => '.obit-name a'
                ),
                'date_format' => 'F j, Y',
                'backup_selectors' => array(
                    'list' => '.obituaries .obituary, .listings .listing, article',
                    'name' => '.name, h3.title, .heading, h2',
                    'dates' => '.dates, .date, .lifespan, time',
                    'description' => '.description, .content, .text, p',
                    'image' => '.image img, .photo img, figure img',
                    'link' => 'a.more, a.details, a.view, h2 a'
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
        $this->log("Starting Ontario Obituaries scrape");
        
        if (!isset($this->settings['enabled']) || !$this->settings['enabled']) {
            $this->log("Scraper is disabled in settings");
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
            'errors' => array(),
            'sources' => array()
        );
        
        // Make sure we have the WordPress HTTP API
        if (!function_exists('wp_remote_get')) {
            include_once(ABSPATH . WPINC . '/http.php');
        }
        
        // Process each source
        foreach ($this->sources as $source_id => $source) {
            $this->log("Processing source: {$source['name']} ({$source_id})");
            
            try {
                $source_results = $this->scrape_source($source_id, $source);
                
                // Update statistics
                $results['sources_scraped']++;
                $results['obituaries_found'] += $source_results['found'];
                $results['obituaries_added'] += $source_results['added'];
                $results['sources'][$source_id] = $source_results;
                
                // Add any errors
                if (!empty($source_results['errors'])) {
                    $results['errors'][$source_id] = $source_results['errors'];
                }
            } catch (Exception $e) {
                $error_message = $e->getMessage();
                $this->log("Error processing source {$source_id}: {$error_message}");
                
                $results['errors'][$source_id] = array(
                    'message' => $error_message,
                    'code' => $e->getCode()
                );
            }
        }
        
        // Send email notification if enabled and obituaries were added
        if ($results['obituaries_added'] > 0 && isset($this->settings['notify_admin']) && $this->settings['notify_admin']) {
            $this->log("Sending notification email for {$results['obituaries_added']} new obituaries");
            $this->send_notification_email($results);
        }
        
        $this->log("Scrape completed: Found {$results['obituaries_found']} obituaries, added {$results['obituaries_added']}");
        
        // Save the last scrape results
        update_option('ontario_obituaries_last_scrape', array(
            'timestamp' => time(),
            'results' => $results
        ));
        
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
        $this->log("Scraping source: {$source['name']} from URL: {$source['url']}");
        
        $results = array(
            'found' => 0,
            'added' => 0,
            'errors' => array(),
            'debug' => array()
        );
        
        try {
            // Make the HTTP request with a custom user agent
            $response = wp_remote_get($source['url'], array(
                'timeout' => 30,
                'user-agent' => 'Mozilla/5.0 (compatible; Ontario Obituaries Bot/1.0; +https://monacomonuments.ca)',
                'sslverify' => false // Sometimes needed for certain sites
            ));
            
            // Check for errors
            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                $this->log("HTTP request failed for {$source_id}: {$error_message}");
                throw new Exception('HTTP request failed: ' . $error_message);
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code !== 200) {
                $this->log("HTTP request returned non-200 status for {$source_id}: {$response_code}");
                throw new Exception('HTTP request failed with code: ' . $response_code);
            }
            
            $html = wp_remote_retrieve_body($response);
            if (empty($html)) {
                $this->log("Empty response from server for {$source_id}");
                throw new Exception('Empty response from server');
            }
            
            $results['debug']['html_length'] = strlen($html);
            $this->log("Received HTML response ({$results['debug']['html_length']} bytes) for {$source_id}");
            
            // Load the HTML into DOMDocument
            $dom = new DOMDocument();
            @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
            
            // Create a DOMXPath object
            $xpath = new DOMXPath($dom);
            
            // Log which selectors we're trying
            $this->log("Trying primary selector for {$source_id}: {$source['selectors']['list']}");
            
            // Try to find the list of obituaries using primary selectors
            $selector = $source['selectors']['list'];
            $css_selector = $this->css_to_xpath($selector);
            $obituary_nodes = $xpath->query($css_selector);
            
            $results['debug']['primary_selector'] = $selector;
            $results['debug']['primary_selector_results'] = $obituary_nodes->length;
            
            // If primary selector fails, try backup selectors
            if ($obituary_nodes->length === 0 && isset($source['backup_selectors']['list'])) {
                $this->log("Primary selector failed for {$source_id}. Trying backup selectors.");
                $backup_selectors = explode(', ', $source['backup_selectors']['list']);
                
                foreach ($backup_selectors as $backup_selector) {
                    $this->log("Trying backup selector: {$backup_selector}");
                    $css_selector = $this->css_to_xpath($backup_selector);
                    $obituary_nodes = $xpath->query($css_selector);
                    
                    $results['debug']['backup_selector_tried'] = $backup_selector;
                    $results['debug']['backup_selector_results'] = $obituary_nodes->length;
                    
                    if ($obituary_nodes->length > 0) {
                        $this->log("Found {$obituary_nodes->length} obituaries with backup selector: {$backup_selector}");
                        // Update the source with the working selector for future scrapes
                        $this->sources[$source_id]['selectors']['list'] = $backup_selector;
                        break;
                    }
                }
            }
            
            // Process found obituaries
            if ($obituary_nodes->length > 0) {
                $this->log("Found {$obituary_nodes->length} obituary nodes for {$source_id}");
                $results['found'] = $obituary_nodes->length;
                
                foreach ($obituary_nodes as $index => $node) {
                    try {
                        $this->log("Processing obituary #{$index} for {$source_id}");
                        
                        // Extract data using selectors
                        $obituary_data = $this->extract_obituary_data($node, $xpath, $source);
                        
                        // Add debug info
                        $results['debug']['obituaries'][] = array(
                            'raw_data' => $obituary_data,
                            'index' => $index
                        );
                        
                        // Validate and filter data
                        if ($this->validate_obituary($obituary_data)) {
                            $this->log("Obituary #{$index} is valid: {$obituary_data['name']}");
                            
                            // Check if this obituary already exists in the database
                            if (!$this->obituary_exists($obituary_data)) {
                                $this->log("Obituary is new, saving: {$obituary_data['name']}");
                                
                                // Save to database
                                $added = $this->save_obituary($obituary_data);
                                if ($added) {
                                    $results['added']++;
                                    $this->log("Successfully saved obituary: {$obituary_data['name']}");
                                } else {
                                    $this->log("Failed to save obituary: {$obituary_data['name']}");
                                }
                            } else {
                                $this->log("Obituary already exists: {$obituary_data['name']}");
                            }
                        } else {
                            $this->log("Obituary #{$index} failed validation");
                        }
                    } catch (Exception $e) {
                        $this->log("Error processing individual obituary: " . $e->getMessage());
                        $results['errors'][] = "Error processing obituary #{$index}: " . $e->getMessage();
                    }
                }
            } else {
                $this->log("No obituaries found on page for {$source_id}");
                $results['errors'][] = 'No obituaries found on page';
                
                // Save a sample of the HTML for debugging
                if ($this->debug) {
                    $sample_length = min(strlen($html), 1000);
                    $html_sample = substr($html, 0, $sample_length);
                    $results['debug']['html_sample'] = $html_sample;
                    $this->log("HTML sample for {$source_id}: " . $html_sample);
                }
            }
        } catch (Exception $e) {
            $this->log("Exception scraping {$source_id}: " . $e->getMessage());
            $results['errors'][] = $e->getMessage();
            
            // Attempt to recover from common errors
            if (strpos($e->getMessage(), 'HTTP request failed') !== false) {
                $this->log("Attempting recovery for {$source_id}");
                // Try with different timeout
                try {
                    $response = wp_remote_get($source['url'], array(
                        'timeout' => 60,
                        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                        'sslverify' => false
                    ));
                    
                    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                        $this->log("Recovery successful for {$source_id} with extended timeout");
                        $results['errors'][] = 'Recovered with extended timeout';
                        // Process the response here...
                        // (simplified for this example)
                    }
                } catch (Exception $recovery_e) {
                    $this->log("Recovery attempt failed for {$source_id}: " . $recovery_e->getMessage());
                    $results['errors'][] = 'Recovery attempt failed: ' . $recovery_e->getMessage();
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Convert CSS selector to XPath
     *
     * @param string $css_selector The CSS selector
     * @return string The XPath equivalent
     */
    private function css_to_xpath($css_selector) {
        // This is a very simplified conversion
        if (strpos($css_selector, ' ') !== false) {
            // For space-separated selectors like "div p", use descendant axis
            $parts = explode(' ', $css_selector);
            return '//' . implode('/descendant::', $parts);
        } else {
            // For simple selectors like "div.class"
            return '//' . $css_selector;
        }
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
        
        // Log the name extraction
        $this->log("Extracted name: {$data['name']}");
        
        // Extract dates
        $dates_text = $extract_with_selectors(
            $source['selectors']['dates'],
            isset($source['backup_selectors']['dates']) ? $source['backup_selectors']['dates'] : ''
        );
        
        // Log the dates extraction
        $this->log("Extracted dates text: {$dates_text}");
        
        // Parse dates from text
        if (!empty($dates_text)) {
            $parsed_dates = $this->parse_dates($dates_text);
            $data['date_of_birth'] = $parsed_dates['birth'];
            $data['date_of_death'] = $parsed_dates['death'];
            
            // If no death date was parsed, use current date as fallback
            if (empty($data['date_of_death'])) {
                $data['date_of_death'] = date('Y-m-d');
                $this->log("No death date found, using current date as fallback");
            }
            
            // Calculate age if we have both dates
            if ($data['date_of_birth'] && $data['date_of_death']) {
                $birth_date = new DateTime($data['date_of_birth']);
                $death_date = new DateTime($data['date_of_death']);
                $interval = $birth_date->diff($death_date);
                $data['age'] = $interval->y;
                $this->log("Calculated age: {$data['age']}");
            }
        } else {
            // If no dates were found, set death date to current date
            $data['date_of_death'] = date('Y-m-d');
            $this->log("No dates found, using current date for death date");
        }
        
        // Extract description
        $data['description'] = $extract_with_selectors(
            $source['selectors']['description'],
            isset($source['backup_selectors']['description']) ? $source['backup_selectors']['description'] : ''
        );
        
        // Log the description extraction
        $description_length = strlen($data['description']);
        $this->log("Extracted description (length: {$description_length})");
        
        // If description is empty, create a basic one
        if (empty($data['description'])) {
            $data['description'] = "Obituary for {$data['name']} from {$source['name']} in {$source['region']}.";
            $this->log("Created fallback description");
        }
        
        // Extract image URL
        $image_selector = $source['selectors']['image'];
        $backup_image_selector = isset($source['backup_selectors']['image']) ? $source['backup_selectors']['image'] : '';
        
        $image_nodes = $xpath->query('.//' . str_replace(' ', '/descendant::', $image_selector), $node);
        if ($image_nodes->length > 0) {
            $data['image_url'] = $image_nodes->item(0)->getAttribute('src');
            $this->log("Found image URL: {$data['image_url']}");
        } elseif (!empty($backup_image_selector)) {
            $backup_selectors = explode(', ', $backup_image_selector);
            foreach ($backup_selectors as $backup_selector) {
                $image_nodes = $xpath->query('.//' . str_replace(' ', '/descendant::', $backup_selector), $node);
                if ($image_nodes->length > 0) {
                    $data['image_url'] = $image_nodes->item(0)->getAttribute('src');
                    $this->log("Found image URL with backup selector: {$data['image_url']}");
                    break;
                }
            }
        }
        
        // Make sure image URL is absolute
        if (!empty($data['image_url']) && strpos($data['image_url'], 'http') !== 0) {
            $parsed_url = parse_url($source['url']);
            $base_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];
            
            if (strpos($data['image_url'], '//') === 0) {
                // Handle protocol-relative URLs
                $data['image_url'] = 'https:' . $data['image_url'];
            } elseif (strpos($data['image_url'], '/') === 0) {
                $data['image_url'] = $base_url . $data['image_url'];
            } else {
                $data['image_url'] = $base_url . '/' . $data['image_url'];
            }
            $this->log("Converted image URL to absolute: {$data['image_url']}");
        }
        
        // Extract source URL (link to full obituary)
        $link_selector = $source['selectors']['link'];
        $backup_link_selector = isset($source['backup_selectors']['link']) ? $source['backup_selectors']['link'] : '';
        
        $link_nodes = $xpath->query('.//' . str_replace(' ', '/descendant::', $link_selector), $node);
        if ($link_nodes->length > 0) {
            $data['source_url'] = $link_nodes->item(0)->getAttribute('href');
            $this->log("Found source URL: {$data['source_url']}");
        } elseif (!empty($backup_link_selector)) {
            $backup_selectors = explode(', ', $backup_link_selector);
            foreach ($backup_selectors as $backup_selector) {
                $link_nodes = $xpath->query('.//' . str_replace(' ', '/descendant::', $backup_selector), $node);
                if ($link_nodes->length > 0) {
                    $data['source_url'] = $link_nodes->item(0)->getAttribute('href');
                    $this->log("Found source URL with backup selector: {$data['source_url']}");
                    break;
                }
            }
        }
        
        // Make sure source URL is absolute
        if (!empty($data['source_url']) && strpos($data['source_url'], 'http') !== 0) {
            $parsed_url = parse_url($source['url']);
            $base_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];
            
            if (strpos($data['source_url'], '//') === 0) {
                // Handle protocol-relative URLs
                $data['source_url'] = 'https:' . $data['source_url'];
            } elseif (strpos($data['source_url'], '/') === 0) {
                $data['source_url'] = $base_url . $data['source_url'];
            } else {
                $data['source_url'] = $base_url . '/' . $data['source_url'];
            }
            $this->log("Converted source URL to absolute: {$data['source_url']}");
        }
        
        // If source URL is still empty, use the funeral home website
        if (empty($data['source_url'])) {
            $data['source_url'] = $source['url'];
            $this->log("Using default source URL: {$data['source_url']}");
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
        $css_selector = $this->css_to_xpath($selector);
        $relative_selector = '.' . $css_selector;
        
        // Try with relative selector first
        $child_nodes = $xpath->query($relative_selector, $node);
        
        if ($child_nodes->length === 0) {
            // If that fails, try with descendant axis
            $descendant_selector = './/' . str_replace(' ', '/descendant::', $selector);
            $child_nodes = $xpath->query($descendant_selector, $node);
        }
        
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
        
        $this->log("Parsing dates from: '{$dates_text}'");
        
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
                
                $this->log("Matched pattern. Birth text: '$birth_text', Death text: '$death_text'");
                
                // Try to parse the dates using different formats
                foreach ($date_formats as $format) {
                    // Try to parse birth date
                    $birth_date = DateTime::createFromFormat($format, $birth_text);
                    if ($birth_date) {
                        $result['birth'] = $birth_date->format('Y-m-d');
                        $this->log("Parsed birth date: {$result['birth']} using format: $format");
                        break;
                    }
                }
                
                foreach ($date_formats as $format) {
                    // Try to parse death date
                    $death_date = DateTime::createFromFormat($format, $death_text);
                    if ($death_date) {
                        $result['death'] = $death_date->format('Y-m-d');
                        $this->log("Parsed death date: {$result['death']} using format: $format");
                        break;
                    }
                }
                
                // If we only matched years, create full dates
                if ($result['birth'] === null && strlen($birth_text) === 4 && is_numeric($birth_text)) {
                    $result['birth'] = $birth_text . '-01-01';
                    $this->log("Using year only for birth date: {$result['birth']}");
                }
                
                if ($result['death'] === null && strlen($death_text) === 4 && is_numeric($death_text)) {
                    $result['death'] = $death_text . '-01-01';
                    $this->log("Using year only for death date: {$result['death']}");
                }
                
                // If we found both dates, stop processing
                if ($result['birth'] !== null && $result['death'] !== null) {
                    break;
                }
            }
        }
        
        // If we couldn't find both dates, try to find just the death date
        if ($result['death'] === null) {
            $this->log("Trying to find just the death date");
            
            // Look for phrases indicating a death date
            $death_patterns = array(
                '/passed\s+away\s+on\s+([^\.]+)/i',
                '/died\s+on\s+([^\.]+)/i',
                '/deceased\s+on\s+([^\.]+)/i'
            );
            
            foreach ($death_patterns as $pattern) {
                if (preg_match($pattern, $dates_text, $matches)) {
                    $death_text = trim($matches[1]);
                    $this->log("Matched death pattern. Death text: '$death_text'");
                    
                    foreach ($date_formats as $format) {
                        $death_date = DateTime::createFromFormat($format, $death_text);
                        if ($death_date) {
                            $result['death'] = $death_date->format('Y-m-d');
                            $this->log("Parsed death date: {$result['death']} using format: $format");
                            break 2;
                        }
                    }
                }
            }
        }
        
        // Try to find a single date - assume it's the death date
        if ($result['death'] === null) {
            $this->log("Trying to find any date as death date");
            foreach ($date_formats as $format) {
                $date = DateTime::createFromFormat($format, trim($dates_text));
                if ($date) {
                    $result['death'] = $date->format('Y-m-d');
                    $this->log("Found date: {$result['death']} assuming it's death date");
                    break;
                }
            }
        }
        
        // If we still don't have a death date, use today's date as fallback
        if ($result['death'] === null) {
            $result['death'] = date('Y-m-d');
            $this->log("No death date found, using today's date: {$result['death']}");
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
            $this->log("Validation failed: Empty name");
            return false;
        }
        
        // Death date is required
        if (empty($data['date_of_death'])) {
            $this->log("Validation failed: Empty death date");
            return false;
        }
        
        // Check against filtered keywords
        if (!empty($this->settings['filter_keywords'])) {
            $keywords = explode(',', $this->settings['filter_keywords']);
            foreach ($keywords as $keyword) {
                $keyword = trim($keyword);
                if (!empty($keyword) && stripos($data['name'] . ' ' . $data['description'], $keyword) !== false) {
                    // Keyword found, filter this obituary
                    $this->log("Validation failed: Filtered keyword found: $keyword");
                    return false;
                }
            }
        }
        
        // Validate death date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['date_of_death'])) {
            $this->log("Validation failed: Invalid death date format: {$data['date_of_death']}");
            return false;
        }
        
        // Validate birth date format if present
        if (!empty($data['date_of_birth']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['date_of_birth'])) {
            $this->log("Validation failed: Invalid birth date format: {$data['date_of_birth']}");
            return false;
        }
        
        // If we have both dates, make sure birth date is before death date
        if (!empty($data['date_of_birth']) && !empty($data['date_of_death'])) {
            $birth = new DateTime($data['date_of_birth']);
            $death = new DateTime($data['date_of_death']);
            
            if ($birth > $death) {
                $this->log("Validation failed: Birth date is after death date");
                return false;
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
        
        // Sanitize data
        $sanitized_data = array(
            'name' => sanitize_text_field($data['name']),
            'date_of_birth' => $data['date_of_birth'] ? sanitize_text_field($data['date_of_birth']) : null,
            'date_of_death' => sanitize_text_field($data['date_of_death']),
            'age' => $data['age'] ? intval($data['age']) : null,
            'funeral_home' => sanitize_text_field($data['funeral_home']),
            'location' => sanitize_text_field($data['location']),
            'image_url' => esc_url_raw($data['image_url']),
            'description' => wp_kses_post($data['description']),
            'source_url' => esc_url_raw($data['source_url']),
            'created_at' => current_time('mysql')
        );
        
        // Save to database
        $result = $wpdb->insert(
            $table_name,
            $sanitized_data,
            array(
                '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s'
            )
        );
        
        if ($result === false) {
            $this->log("Database error: " . $wpdb->last_error);
            return false;
        }
        
        return true;
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
    
    /**
     * Log a message (to the error log or a custom log)
     * 
     * @param string $message The message to log
     */
    private function log($message) {
        if ($this->debug) {
            error_log('Ontario Obituaries: ' . $message);
        }
    }
    
    /**
     * Run the scraper now (manually triggered)
     * 
     * @return array Scraping results
     */
    public function run_now() {
        $this->log("Manual scrape triggered");
        return $this->scrape();
    }
}

