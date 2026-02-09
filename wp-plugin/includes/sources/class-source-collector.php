<?php
/**
 * Source Collector
 *
 * Orchestrates the daily collection pipeline:
 *   1. Load active sources from registry
 *   2. For each source, get the adapter
 *   3. Discover listing URLs (incremental — today/recent only)
 *   4. Fetch & extract obituary cards
 *   5. Optionally fetch detail pages
 *   6. Normalize → dedupe → insert
 *   7. Record success/failure per source
 *
 * Respects per-source rate limits, page budgets, and circuit breakers.
 *
 * @package Ontario_Obituaries
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ontario_Obituaries_Source_Collector {

    /** @var int Global max age of obituaries in days. */
    private $max_age;

    /** @var array Collection results accumulator. */
    private $results;

    /**
     * Constructor.
     */
    public function __construct() {
        $settings      = function_exists( 'ontario_obituaries_get_settings' ) ? ontario_obituaries_get_settings() : array();
        $this->max_age = isset( $settings['max_age'] ) ? intval( $settings['max_age'] ) : 7;
    }

    /**
     * Run the full collection pipeline.
     *
     * @param string $region Optional — filter sources by region.
     * @param string $city   Optional — filter sources by city.
     * @return array Results { obituaries_found, obituaries_added, sources_processed, errors, per_source }
     */
    public function collect( $region = '', $city = '' ) {
        global $wpdb;

        $this->results = array(
            'obituaries_found'  => 0,
            'obituaries_added'  => 0,
            'sources_processed' => 0,
            'sources_skipped'   => 0,
            'errors'            => array(),
            'per_source'        => array(),
            'started_at'        => current_time( 'mysql', true ),
            'completed_at'      => '',
        );

        // 1. Load sources
        if ( ! empty( $region ) || ! empty( $city ) ) {
            $sources = Ontario_Obituaries_Source_Registry::get_sources_by_location( $region, $city );
        } else {
            $sources = Ontario_Obituaries_Source_Registry::get_active_sources();
        }

        if ( empty( $sources ) ) {
            $this->results['errors']['registry'] = 'No active sources found in the registry.';
            $this->results['completed_at'] = current_time( 'mysql', true );
            return $this->results;
        }

        ontario_obituaries_log( sprintf( 'Collection starting: %d active sources', count( $sources ) ), 'info' );

        $table_name = $wpdb->prefix . 'ontario_obituaries';

        // Verify main table exists
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
            $this->results['errors']['database'] = 'Obituaries table does not exist.';
            $this->results['completed_at'] = current_time( 'mysql', true );
            return $this->results;
        }

        // 2. Process each source
        foreach ( $sources as $source ) {
            $this->process_source( $source, $table_name );
        }

        $this->results['completed_at'] = current_time( 'mysql', true );

        // Persist errors for Debug page
        if ( ! empty( $this->results['errors'] ) ) {
            update_option( 'ontario_obituaries_last_scrape_errors', $this->results['errors'] );
        } else {
            delete_option( 'ontario_obituaries_last_scrape_errors' );
        }

        ontario_obituaries_log(
            sprintf(
                'Collection complete: %d found, %d added, %d sources processed, %d skipped',
                $this->results['obituaries_found'],
                $this->results['obituaries_added'],
                $this->results['sources_processed'],
                $this->results['sources_skipped']
            ),
            'info'
        );

        return $this->results;
    }

    /**
     * Process a single source through the full pipeline.
     *
     * @param array  $source     Source registry row.
     * @param string $table_name Main obituaries table name.
     */
    private function process_source( $source, $table_name ) {
        global $wpdb;

        $source_id   = intval( $source['id'] );
        $domain      = $source['domain'];
        $adapter_type = $source['adapter_type'];

        $source_result = array(
            'domain' => $domain,
            'name'   => $source['name'],
            'found'  => 0,
            'added'  => 0,
            'errors' => array(),
            'pages'  => 0,
        );

        // Get adapter
        $adapter = Ontario_Obituaries_Source_Registry::get_adapter( $adapter_type );
        if ( ! $adapter ) {
            $msg = sprintf( 'No adapter registered for type "%s" (source: %s)', $adapter_type, $domain );
            $source_result['errors'][] = $msg;
            $this->results['errors'][ $domain ] = $msg;
            $this->results['sources_skipped']++;
            Ontario_Obituaries_Source_Registry::record_failure( $source_id, $msg );
            $this->results['per_source'][ $domain ] = $source_result;
            return;
        }

        // Parse config JSON
        $source['config_parsed'] = ! empty( $source['config'] ) ? json_decode( $source['config'], true ) : array();
        if ( ! is_array( $source['config_parsed'] ) ) {
            $source['config_parsed'] = array();
        }

        $max_pages        = intval( $source['max_pages_per_run'] );
        $request_interval = floatval( $source['min_request_interval'] );

        try {
            // 3. Discover listing URLs
            $listing_urls = $adapter->discover_listing_urls( $source, $this->max_age );
            $listing_urls = array_slice( $listing_urls, 0, $max_pages ); // Enforce page budget

            foreach ( $listing_urls as $listing_url ) {
                $source_result['pages']++;

                // Rate limiting between pages
                if ( $source_result['pages'] > 1 && $request_interval > 0 ) {
                    usleep( (int) ( $request_interval * 1000000 ) );
                }

                // 4. Fetch listing
                $html = $adapter->fetch_listing( $listing_url, $source );
                if ( is_wp_error( $html ) ) {
                    $source_result['errors'][] = $html->get_error_message();
                    continue;
                }

                // 5. Extract cards
                $cards = $adapter->extract_obit_cards( $html, $source );
                $source_result['found'] += count( $cards );

                foreach ( $cards as $card ) {
                    // 5b. Optional detail fetch
                    if ( ! empty( $card['detail_url'] ) ) {
                        // Rate limit between detail fetches
                        if ( $request_interval > 0 ) {
                            usleep( (int) ( $request_interval * 1000000 ) );
                        }
                        $enriched = $adapter->fetch_detail( $card['detail_url'], $card, $source );
                        if ( $enriched ) {
                            $card = array_merge( $card, $enriched );
                        }
                    }

                    // 6. Normalize
                    $record = $adapter->normalize( $card, $source );

                    // Validate minimum fields
                    if ( empty( $record['name'] ) || empty( $record['date_of_death'] ) ) {
                        continue;
                    }

                    // Check suppression (do-not-republish)
                    if ( $this->is_suppressed( $record ) ) {
                        continue;
                    }

                    // 7. INSERT IGNORE (dedupe via unique key + provenance hash)
                    $inserted = $this->insert_obituary( $record, $table_name );
                    if ( $inserted ) {
                        $source_result['added']++;
                    }
                }
            }

            // Record success
            $this->results['obituaries_found'] += $source_result['found'];
            $this->results['obituaries_added'] += $source_result['added'];
            $this->results['sources_processed']++;

            Ontario_Obituaries_Source_Registry::record_success( $source_id, $source_result['added'] );

            ontario_obituaries_log(
                sprintf( 'Source %s: %d found, %d added, %d pages', $domain, $source_result['found'], $source_result['added'], $source_result['pages'] ),
                'info'
            );

        } catch ( Exception $e ) {
            $msg = $e->getMessage();
            $source_result['errors'][] = $msg;
            $this->results['errors'][ $domain ] = $msg;
            $this->results['sources_processed']++;

            Ontario_Obituaries_Source_Registry::record_failure( $source_id, $msg );

            ontario_obituaries_log( sprintf( 'Source %s FAILED: %s', $domain, $msg ), 'error' );
        }

        $this->results['per_source'][ $domain ] = $source_result;
    }

    /**
     * Insert a normalized obituary record.
     *
     * Uses INSERT IGNORE for exact dedup on the unique key.
     * Also stores the new provenance columns.
     *
     * @param array  $record     Normalized obituary data.
     * @param string $table_name Full table name.
     * @return bool Whether a new row was inserted.
     */
    private function insert_obituary( $record, $table_name ) {
        global $wpdb;

        $sql = $wpdb->prepare(
            "INSERT IGNORE INTO `{$table_name}`
                (name, date_of_birth, date_of_death, age, funeral_home, location,
                 image_url, description, source_url, source_domain, source_type,
                 city_normalized, provenance_hash)
             VALUES (%s, %s, %s, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s)",
            $record['name'],
            $record['date_of_birth'],
            $record['date_of_death'],
            intval( $record['age'] ),
            $record['funeral_home'],
            $record['location'],
            $record['image_url'],
            $record['description'],
            $record['source_url'],
            isset( $record['source_domain'] ) ? $record['source_domain'] : '',
            isset( $record['source_type'] )   ? $record['source_type']   : '',
            isset( $record['city_normalized'] ) ? $record['city_normalized'] : '',
            isset( $record['provenance_hash'] ) ? $record['provenance_hash'] : ''
        );

        $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

        return $wpdb->rows_affected > 0;
    }

    /**
     * Check if an obituary is suppressed (do-not-republish).
     *
     * @param array $record Normalized record.
     * @return bool
     */
    private function is_suppressed( $record ) {
        if ( empty( $record['provenance_hash'] ) ) {
            return false;
        }

        // P1-4 FIX: Check both the main table AND the suppression manager's
        // do-not-republish blocklist (survives record deletion/retention).
        if ( class_exists( 'Ontario_Obituaries_Suppression_Manager' ) ) {
            if ( Ontario_Obituaries_Suppression_Manager::is_blocked( $record['provenance_hash'] ) ) {
                return true;
            }
        }

        // Also check for suppressed records in the main table
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        $suppressed = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE provenance_hash = %s
               AND suppressed_at IS NOT NULL",
            $record['provenance_hash']
        ) );

        return intval( $suppressed ) > 0;
    }

    /**
     * Run collection as a WP-Cron task (backwards-compatible with the old scraper).
     *
     * @return array Results.
     */
    public function run_now() {
        return $this->collect();
    }

    /**
     * Backwards-compatible scrape method.
     *
     * @return int Number of new records.
     */
    public function scrape() {
        $results = $this->collect();
        return isset( $results['obituaries_added'] ) ? intval( $results['obituaries_added'] ) : 0;
    }
}
