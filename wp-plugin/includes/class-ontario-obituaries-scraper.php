<?php
/**
 * Ontario Obituaries Scraper
 *
 * Handles scraping obituary data from various sources.
 *
 * FIX LOG:
 *  P0-2  : check_for_duplicates() now queries `name` column (not first_name/last_name)
 *  P0-3  : Removed data_quality from INSERT (column does not exist in schema)
 *  P1-1  : Settings now read from centralized ontario_obituaries_get_settings()
 *  P1-5  : Rate limiting closure rewritten as a proper method; hook is scoped and
 *           removed after each collect() run to avoid polluting other HTTP calls
 *  P0-13 : get_scraper_for_region() gracefully returns null when region file missing
 *  P1-9  : INSERT IGNORE replaces select-then-insert for exact dedup; fuzzy dedup
 *           is now gated by the 'fuzzy_dedupe' setting (off by default)
 *  P2-12 : Uses ontario_obituaries_log() wrapper instead of direct error_log()
 *  P2-13 : Stores per-region scrape errors in option for Debug page display
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ontario_Obituaries_Scraper {

    /** @var array Regions to scrape */
    private $regions;

    /** @var int Maximum age of obituaries to scrape (days) */
    private $max_age;

    /** @var int Retry attempts for failed requests */
    private $retry_attempts;

    /** @var int HTTP timeout (seconds) */
    private $timeout;

    /** @var bool Whether adaptive scraping is enabled */
    private $adaptive_mode;

    /** @var bool Whether fuzzy deduplication is enabled */
    private $fuzzy_dedupe;

    /** @var int Static request counter for rate limiting */
    private static $request_count = 0;

    /** @var float Last request timestamp for rate limiting */
    private static $last_request_time = 0.0;

    /**
     * Constructor.
     * P1-1 FIX: Uses ontario_obituaries_get_settings() as single source of truth.
     */
    public function __construct() {
        $settings = function_exists( 'ontario_obituaries_get_settings' )
            ? ontario_obituaries_get_settings()
            : array();

        $this->regions        = ! empty( $settings['regions'] )        ? array_map( 'strtolower', (array) $settings['regions'] ) : array( 'toronto', 'ottawa', 'hamilton', 'london', 'windsor' );
        $this->max_age        = ! empty( $settings['max_age'] )        ? intval( $settings['max_age'] )        : 7;
        $this->retry_attempts = ! empty( $settings['retry_attempts'] ) ? intval( $settings['retry_attempts'] ) : 3;
        $this->timeout        = ! empty( $settings['timeout'] )        ? intval( $settings['timeout'] )        : 30;
        $this->adaptive_mode  = isset( $settings['adaptive_mode'] )    ? (bool) $settings['adaptive_mode']     : true;
        $this->fuzzy_dedupe   = ! empty( $settings['fuzzy_dedupe'] );
    }

    /* ───────────────────────────── PUBLIC API ──────────────────────────── */

    /**
     * Run the scraper now (alias for collect).
     *
     * @return array Results of the scraping operation.
     */
    public function run_now() {
        return $this->collect();
    }

    /**
     * Collect obituary data from all configured regions.
     *
     * P1-9 FIX: Uses INSERT IGNORE for exact dedup (relying on the unique key).
     *           Fuzzy dedup only runs when the fuzzy_dedupe setting is enabled.
     *
     * @return array Results array.
     */
    public function collect() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ontario_obituaries';

        $results = array(
            'obituaries_found' => 0,
            'obituaries_added' => 0,
            'errors'           => array(),
            'regions'          => array(),
            'data_quality'     => array(
                'complete' => 0,
                'partial'  => 0,
                'invalid'  => 0,
            ),
        );

        // P1-5 FIX: Enable rate limiting scoped to this run only
        $this->enable_rate_limiting();

        // Check table existence
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
            $results['errors']['database'] = 'Obituaries table does not exist';
            $this->disable_rate_limiting();
            return $results;
        }

        foreach ( $this->regions as $region ) {
            $results['regions'][ $region ] = array(
                'found'    => 0,
                'added'    => 0,
                'complete' => 0,
                'partial'  => 0,
            );

            try {
                $obituaries = $this->get_obituaries_for_region_with_retry( $region );

                $results['regions'][ $region ]['found'] = count( $obituaries );
                $results['obituaries_found']           += count( $obituaries );

                foreach ( $obituaries as $obituary ) {
                    $obituary     = $this->validate_and_sanitize_obituary( $obituary );
                    $completeness = $this->check_data_completeness( $obituary );

                    if ( 'invalid' === $completeness ) {
                        $results['data_quality']['invalid']++;
                        continue;
                    }

                    if ( 'partial' === $completeness ) {
                        $results['data_quality']['partial']++;
                        $results['regions'][ $region ]['partial']++;
                    } else {
                        $results['data_quality']['complete']++;
                        $results['regions'][ $region ]['complete']++;
                    }

                    // P1-9 FIX: Fuzzy dedup (optional, runs BEFORE insert to prevent insertion)
                    if ( $this->fuzzy_dedupe && $this->check_for_duplicates( $obituary, $table_name ) ) {
                        ontario_obituaries_log( 'Fuzzy duplicate skipped: ' . $obituary['name'], 'info' );
                        continue;
                    }

                    // P1-9 FIX: INSERT IGNORE — relies on UNIQUE KEY (name, date_of_death, funeral_home)
                    // P0-3 FIX: No data_quality column
                    $sql = $wpdb->prepare(
                        "INSERT IGNORE INTO `{$table_name}`
                            (name, date_of_birth, date_of_death, age, funeral_home, location, image_url, description, source_url)
                         VALUES (%s, %s, %s, %d, %s, %s, %s, %s, %s)",
                        $obituary['name'],
                        $obituary['date_of_birth'],
                        $obituary['date_of_death'],
                        $obituary['age'],
                        $obituary['funeral_home'],
                        $obituary['location'],
                        $obituary['image_url'],
                        $obituary['description'],
                        $obituary['source_url']
                    );

                    $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

                    if ( $wpdb->rows_affected > 0 ) {
                        $results['regions'][ $region ]['added']++;
                        $results['obituaries_added']++;
                    }
                    // rows_affected === 0 means duplicate was silently ignored
                }
            } catch ( Exception $e ) {
                $results['errors'][ $region ] = $e->getMessage();
                ontario_obituaries_log( 'Error scraping ' . $region . ': ' . $e->getMessage(), 'error' );
            }
        }

        // P1-5 FIX: Remove rate-limiting hook after scraping completes
        $this->disable_rate_limiting();

        // P2-13 FIX: Store per-region errors for Debug page display
        if ( ! empty( $results['errors'] ) ) {
            update_option( 'ontario_obituaries_last_scrape_errors', $results['errors'] );
        } else {
            delete_option( 'ontario_obituaries_last_scrape_errors' );
        }

        return $results;
    }

    /**
     * Run scraper and return count of new records.
     *
     * @return int Number of new records added.
     */
    public function scrape() {
        $results = $this->collect();
        return isset( $results['obituaries_added'] ) ? intval( $results['obituaries_added'] ) : 0;
    }

    /* ──────────────────────── RATE LIMITING (P1-5 FIX) ─────────────────── */

    private function enable_rate_limiting() {
        add_action( 'http_api_curl', array( $this, 'apply_rate_limiting' ), 10, 1 );
    }

    private function disable_rate_limiting() {
        remove_action( 'http_api_curl', array( $this, 'apply_rate_limiting' ), 10 );
    }

    /**
     * cURL handle callback.
     *
     * @param resource $handle cURL handle.
     * @return resource
     */
    public function apply_rate_limiting( $handle ) {
        $current_time    = microtime( true );
        $time_since_last = $current_time - self::$last_request_time;

        self::$request_count++;
        $base_delay = min( 1 + ( self::$request_count * 0.1 ), 3 );

        if ( $time_since_last < $base_delay ) {
            $jitter = mt_rand( -200, 500 ) / 1000;
            usleep( (int) ( ( $base_delay + $jitter ) * 1000000 ) );
        }

        if ( self::$request_count > 20 ) {
            self::$request_count = 0;
            if ( mt_rand( 1, 10 ) > 8 ) {
                usleep( mt_rand( 3000000, 6000000 ) );
            }
        }

        self::$last_request_time = microtime( true );

        if ( $this->timeout > 0 ) {
            curl_setopt( $handle, CURLOPT_TIMEOUT, $this->timeout );
            curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, min( $this->timeout, 10 ) );
            curl_setopt( $handle, CURLOPT_TCP_KEEPALIVE, 1 );
            curl_setopt( $handle, CURLOPT_TCP_KEEPIDLE, 60 );
            curl_setopt( $handle, CURLOPT_TCP_KEEPINTVL, 60 );
            curl_setopt( $handle, CURLOPT_ENCODING, '' );
        }

        return $handle;
    }

    /* ──────────────────────── REGION FETCHING ──────────────────────────── */

    /**
     * Fetch obituaries for a region with retry + exponential back-off.
     *
     * @param string $region Region slug.
     * @return array
     * @throws Exception On total failure.
     */
    private function get_obituaries_for_region_with_retry( $region ) {
        $attempt      = 0;
        $max_attempts = $this->retry_attempts;
        $obituaries   = array();
        $last_error   = null;

        while ( $attempt < $max_attempts ) {
            try {
                $attempt++;
                $scraper = $this->get_scraper_for_region( $region );

                if ( ! $scraper ) {
                    throw new Exception( "Scraper for region '{$region}' not found or not installed." );
                }

                if ( $this->adaptive_mode && method_exists( $scraper, 'set_adaptive_mode' ) ) {
                    $scraper->set_adaptive_mode( true );
                }

                $obituaries = $scraper->get_obituaries();
                break;

            } catch ( Exception $e ) {
                $last_error = $e;
                ontario_obituaries_log(
                    sprintf( 'Error scraping %s (attempt %d/%d): %s', $region, $attempt, $max_attempts, $e->getMessage() ),
                    'warning'
                );

                if ( $attempt < $max_attempts ) {
                    $backoff = pow( 2, $attempt ) * 500;
                    $jitter  = mt_rand( -100, 200 );
                    usleep( ( $backoff + $jitter ) * 1000 );
                }
            }
        }

        if ( empty( $obituaries ) && $last_error ) {
            throw new Exception( "Failed after {$max_attempts} attempts: " . $last_error->getMessage() );
        }

        return $obituaries;
    }

    /**
     * Load and return the scraper class for a specific region.
     *
     * @param string $region Region slug (lowercase).
     * @return object|null
     */
    private function get_scraper_for_region( $region ) {
        $class_name = 'Ontario_Obituaries_Scraper_' . ucfirst( $region );

        if ( ! class_exists( $class_name ) ) {
            $file_path = ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/scrapers/class-ontario-obituaries-scraper-' . $region . '.php';

            if ( file_exists( $file_path ) ) {
                require_once $file_path;
            } else {
                ontario_obituaries_log( 'Scraper file not found for region: ' . $region . ' (' . $file_path . ')', 'warning' );
                return null;
            }
        }

        if ( class_exists( $class_name ) ) {
            return new $class_name( $this->max_age, $this->retry_attempts );
        }

        return null;
    }

    /* ────────────────────── VALIDATION & SANITIZATION ──────────────────── */

    /**
     * Validate and sanitize a single obituary array.
     *
     * @param array $obituary Raw data.
     * @return array Cleaned data.
     */
    private function validate_and_sanitize_obituary( $obituary ) {
        $obituary['name']          = isset( $obituary['name'] )          ? sanitize_text_field( $obituary['name'] )          : '';
        $obituary['date_of_death'] = isset( $obituary['date_of_death'] ) ? sanitize_text_field( $obituary['date_of_death'] ) : '';
        // P0-3 FIX: Normalize empty funeral_home/location to '' (never NULL)
        $obituary['funeral_home']  = isset( $obituary['funeral_home'] ) && '' !== $obituary['funeral_home']
            ? sanitize_text_field( $obituary['funeral_home'] )
            : '';
        $obituary['location']      = isset( $obituary['location'] ) && '' !== $obituary['location']
            ? sanitize_text_field( $obituary['location'] )
            : '';
        $obituary['date_of_birth'] = isset( $obituary['date_of_birth'] ) ? sanitize_text_field( $obituary['date_of_birth'] ) : '';
        $obituary['age']           = isset( $obituary['age'] ) && is_numeric( $obituary['age'] ) ? intval( $obituary['age'] ) : 0;
        $obituary['image_url']     = isset( $obituary['image_url'] )  ? esc_url_raw( $obituary['image_url'] )  : '';
        $obituary['source_url']    = isset( $obituary['source_url'] ) ? esc_url_raw( $obituary['source_url'] ) : '';
        $obituary['description']   = isset( $obituary['description'] ) ? wp_kses_post( $obituary['description'] ) : '';

        // Normalize dates to Y-m-d
        $obituary['date_of_death'] = $this->validate_and_normalize_date( $obituary['date_of_death'] );
        $obituary['date_of_birth'] = $this->validate_and_normalize_date( $obituary['date_of_birth'] );

        // Compute age from dates when missing
        if ( empty( $obituary['age'] ) && ! empty( $obituary['date_of_birth'] ) && ! empty( $obituary['date_of_death'] ) ) {
            $obituary['age'] = $this->calculate_age_from_dates( $obituary['date_of_birth'], $obituary['date_of_death'] );
        }

        return $obituary;
    }

    /**
     * Normalize date string to Y-m-d.
     *
     * @param string $date_string Input date.
     * @return string Normalized date or empty string.
     */
    private function validate_and_normalize_date( $date_string ) {
        if ( empty( $date_string ) ) {
            return '';
        }

        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_string ) ) {
            return $date_string;
        }

        $formats = array(
            'm/d/Y', 'd/m/Y', 'Y/m/d',
            'M j, Y', 'F j, Y', 'j F Y',
            'd-m-Y', 'm-d-Y', 'Y-m-d',
        );

        foreach ( $formats as $format ) {
            $date = DateTime::createFromFormat( $format, $date_string );
            if ( false !== $date ) {
                return $date->format( 'Y-m-d' );
            }
        }

        return '';
    }

    /**
     * Calculate age from birth and death dates.
     *
     * @param string $birth_date Y-m-d.
     * @param string $death_date Y-m-d.
     * @return int Age in years or 0.
     */
    private function calculate_age_from_dates( $birth_date, $death_date ) {
        try {
            $birth    = new DateTime( $birth_date );
            $death    = new DateTime( $death_date );
            $interval = $birth->diff( $death );
            return $interval->y;
        } catch ( Exception $e ) {
            return 0;
        }
    }

    /**
     * Evaluate data completeness.
     *
     * @param array $obituary Sanitized data.
     * @return string 'complete', 'partial', or 'invalid'.
     */
    private function check_data_completeness( $obituary ) {
        if ( empty( $obituary['name'] ) || empty( $obituary['date_of_death'] ) ) {
            return 'invalid';
        }

        $required = array( 'name', 'date_of_death', 'funeral_home', 'location', 'description' );
        $complete = true;

        foreach ( $required as $field ) {
            if ( empty( $obituary[ $field ] ) ) {
                $complete = false;
                break;
            }
        }

        if ( $complete ) {
            if ( strlen( $obituary['description'] ) < 50 ) {
                $complete = false;
            }
            if ( empty( $obituary['age'] ) && empty( $obituary['date_of_birth'] ) ) {
                $complete = false;
            }
        }

        return $complete ? 'complete' : 'partial';
    }

    /* ────────────────────────── DEDUPLICATION ──────────────────────────── */

    /**
     * Fuzzy duplicate detection.
     *
     * P0-2 FIX: Queries the `name` column instead of non-existent first_name/last_name.
     * P1-9 FIX: Now gated by the fuzzy_dedupe setting (off by default).
     *
     * @param array  $obituary   Sanitized obituary.
     * @param string $table_name Full table name.
     * @return bool Whether a likely duplicate exists.
     */
    private function check_for_duplicates( $obituary, $table_name ) {
        global $wpdb;

        if ( empty( $obituary['name'] ) || empty( $obituary['date_of_death'] ) ) {
            return false;
        }

        $name_parts = explode( ' ', trim( $obituary['name'] ) );
        if ( count( $name_parts ) < 2 ) {
            return false;
        }

        $last_name     = end( $name_parts );
        $first_initial = substr( $name_parts[0], 0, 1 );

        $death_date       = $obituary['date_of_death'];
        $date_range_start = gmdate( 'Y-m-d', strtotime( $death_date . ' -3 days' ) );
        $date_range_end   = gmdate( 'Y-m-d', strtotime( $death_date . ' +3 days' ) );

        $duplicate_count = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table_name}`
             WHERE name LIKE %s
               AND name LIKE %s
               AND date_of_death BETWEEN %s AND %s",
            '%' . $wpdb->esc_like( $last_name ),
            $wpdb->esc_like( $first_initial ) . '%',
            $date_range_start,
            $date_range_end
        ) );

        return intval( $duplicate_count ) > 0;
    }

    /* ─────────────────────── HISTORICAL SCRAPING ──────────────────────── */

    /**
     * Collect obituaries for a date range.
     *
     * @param string $start_date Y-m-d.
     * @param string $end_date   Y-m-d.
     * @return array Results.
     */
    public function get_historical_obituaries( $start_date, $end_date ) {
        if ( ! $this->validate_date( $start_date ) || ! $this->validate_date( $end_date ) ) {
            return array(
                'success' => false,
                'message' => 'Invalid date format. Use YYYY-MM-DD.',
            );
        }

        $results = array(
            'success'          => true,
            'obituaries_found' => 0,
            'obituaries_added' => 0,
            'errors'           => array(),
            'regions'          => array(),
        );

        $this->enable_rate_limiting();

        foreach ( $this->regions as $region ) {
            $results['regions'][ $region ] = array( 'found' => 0, 'added' => 0 );

            try {
                $scraper = $this->get_scraper_for_region( $region );
                if ( ! $scraper ) {
                    $results['errors'][ $region ] = 'Scraper not found';
                    continue;
                }

                if ( ! method_exists( $scraper, 'get_historical_obituaries' ) ) {
                    $results['errors'][ $region ] = 'Historical data not supported';
                    continue;
                }

                set_time_limit( max( 120, $this->timeout * 2 ) );
                $obituaries = $scraper->get_historical_obituaries( $start_date, $end_date );
                $this->process_obituaries( $obituaries, $region, $results );

            } catch ( Exception $e ) {
                $results['errors'][ $region ] = $e->getMessage();
                ontario_obituaries_log( 'Historical error for ' . $region . ': ' . $e->getMessage(), 'error' );
            }
        }

        $this->disable_rate_limiting();
        return $results;
    }

    /**
     * Process and insert obituaries (used by historical scraper).
     * P1-9 FIX: Uses INSERT IGNORE.
     *
     * @param array  $obituaries Raw obituaries.
     * @param string $region     Region slug.
     * @param array  &$results   Results accumulator.
     */
    private function process_obituaries( $obituaries, $region, &$results ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ontario_obituaries';

        $results['regions'][ $region ]['found'] = count( $obituaries );
        $results['obituaries_found']           += count( $obituaries );

        foreach ( $obituaries as $obituary ) {
            $sql = $wpdb->prepare(
                "INSERT IGNORE INTO `{$table_name}`
                    (name, date_of_birth, date_of_death, age, funeral_home, location, image_url, description, source_url)
                 VALUES (%s, %s, %s, %d, %s, %s, %s, %s, %s)",
                $obituary['name'],
                $obituary['date_of_birth'],
                $obituary['date_of_death'],
                $obituary['age'],
                $obituary['funeral_home'],
                $obituary['location'],
                $obituary['image_url'],
                $obituary['description'],
                $obituary['source_url']
            );

            $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

            if ( $wpdb->rows_affected > 0 ) {
                $results['regions'][ $region ]['added']++;
                $results['obituaries_added']++;
            }
        }
    }

    /* ──────────────────────────── HELPERS ──────────────────────────────── */

    /**
     * Validate a Y-m-d date string.
     *
     * @param string $date Date string.
     * @return bool
     */
    private function validate_date( $date ) {
        $d = DateTime::createFromFormat( 'Y-m-d', $date );
        return $d && $d->format( 'Y-m-d' ) === $date;
    }

    /**
     * Get previous month's date range.
     *
     * @return array With start_date and end_date.
     */
    public function get_previous_month_dates() {
        return array(
            'start_date' => gmdate( 'Y-m-d', strtotime( 'first day of last month' ) ),
            'end_date'   => gmdate( 'Y-m-d', strtotime( 'last day of last month' ) ),
        );
    }

    /**
     * Fetch obituaries from the previous month.
     *
     * @return array Results.
     */
    public function get_previous_month_obituaries() {
        $dates = $this->get_previous_month_dates();
        return $this->get_historical_obituaries( $dates['start_date'], $dates['end_date'] );
    }

    /**
     * Get saved URL for a region.
     *
     * @param string $region Region name.
     * @return string URL or empty.
     */
    public function get_region_url( $region ) {
        $urls = get_option( 'ontario_obituaries_region_urls', array() );
        return isset( $urls[ $region ] ) ? $urls[ $region ] : '';
    }

    /**
     * Test connection to a region source.
     *
     * @param string $region Region slug.
     * @return array With success bool and message.
     */
    public function test_connection( $region ) {
        try {
            $scraper = $this->get_scraper_for_region( $region );

            if ( ! $scraper ) {
                return array(
                    'success' => false,
                    'message' => 'Scraper not found for ' . $region,
                );
            }

            if ( $this->adaptive_mode && method_exists( $scraper, 'set_adaptive_mode' ) ) {
                $scraper->set_adaptive_mode( true );
            }

            $scraper->test_connection();

            return array(
                'success' => true,
                'message' => 'Connection successful to ' . $region . '. Source is accessible.',
            );
        } catch ( Exception $e ) {
            return array(
                'success' => false,
                'message' => 'Connection failed to ' . $region . ': ' . $e->getMessage(),
            );
        }
    }

    /**
     * Add test / sample data to the database.
     *
     * @return array With success bool and message.
     */
    public function add_test_data() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ontario_obituaries';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
            return array( 'success' => false, 'message' => 'Obituaries table does not exist' );
        }

        $test_data = array(
            array(
                'name'          => 'John Smith',
                'date_of_birth' => '1945-03-15',
                'date_of_death' => gmdate( 'Y-m-d', strtotime( '-5 days' ) ),
                'age'           => 78,
                'funeral_home'  => 'Toronto Memorial Services',
                'location'      => 'Toronto',
                'description'   => 'John Smith passed away peacefully surrounded by family. He was a beloved husband, father and grandfather.',
                'source_url'    => 'https://example.com/obituaries/john-smith',
                'image_url'     => 'https://randomuser.me/api/portraits/men/45.jpg',
            ),
            array(
                'name'          => 'Mary Johnson',
                'date_of_birth' => '1938-09-22',
                'date_of_death' => gmdate( 'Y-m-d', strtotime( '-3 days' ) ),
                'age'           => 85,
                'funeral_home'  => 'Ottawa Memorial Chapel',
                'location'      => 'Ottawa',
                'description'   => 'Mary Johnson, aged 85, passed away on ' . gmdate( 'F j, Y', strtotime( '-3 days' ) ) . '. She is survived by her three children and seven grandchildren.',
                'source_url'    => 'https://example.com/obituaries/mary-johnson',
                'image_url'     => 'https://randomuser.me/api/portraits/women/67.jpg',
            ),
        );

        $added = 0;
        foreach ( $test_data as $obituary ) {
            // Use INSERT IGNORE (consistent with collect())
            $sql = $wpdb->prepare(
                "INSERT IGNORE INTO `{$table_name}`
                    (name, date_of_birth, date_of_death, age, funeral_home, location, image_url, description, source_url)
                 VALUES (%s, %s, %s, %d, %s, %s, %s, %s, %s)",
                $obituary['name'],
                $obituary['date_of_birth'],
                $obituary['date_of_death'],
                $obituary['age'],
                $obituary['funeral_home'],
                $obituary['location'],
                $obituary['image_url'],
                $obituary['description'],
                $obituary['source_url']
            );

            $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
            if ( $wpdb->rows_affected > 0 ) {
                $added++;
            }
        }

        return array(
            'success' => true,
            'message' => sprintf( 'Added %d test obituaries to the database', $added ),
        );
    }

    /**
     * Check database connection and table structure.
     *
     * @return array With success bool and message.
     */
    public function check_database() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ontario_obituaries';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
            return array( 'success' => false, 'message' => 'Obituaries table does not exist' );
        }

        $columns      = $wpdb->get_results( "DESCRIBE `{$table_name}`" );
        $column_names = array_map( function ( $col ) { return $col->Field; }, $columns );
        $required     = array( 'name', 'date_of_death', 'funeral_home', 'location' );
        $missing      = array_diff( $required, $column_names );

        if ( ! empty( $missing ) ) {
            return array(
                'success' => false,
                'message' => 'Table is missing required columns: ' . implode( ', ', $missing ),
            );
        }

        $count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table_name}`" );

        return array(
            'success' => true,
            'message' => sprintf( 'Database connection successful. Table exists with %d records.', $count ),
        );
    }
}
