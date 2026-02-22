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
     * v4.6.5: Skip detail page fetches to stay within server timeouts.
     *
     * When true, process_source() will NOT fetch individual obituary detail
     * pages. Listing-page data (name, dates, description snippet, published
     * date, image) is sufficient for initial ingest — records are created
     * with status='pending' and the AI rewriter enriches them later.
     *
     * This is the fix for the "22-minute rescan → 0 results" bug:
     *   - 7 sources × 5 pages × 25 cards = 875 detail page fetches
     *   - Each fetch ~1-3s + 2s rate limit = 375+ seconds per source
     *   - LiteSpeed kills PHP at 120s → every source AJAX request dies
     *   - JS has 180s timeout → 7 × 3 min = 21 min of waiting, 0 results
     *
     * With skip_detail_fetch = true:
     *   - 7 sources × 5 listing pages × 2s = ~70s total
     *   - Each source finishes in ~10-20s (well within timeout)
     *
     * @var bool
     */
    private $skip_detail_fetch = false;

    /**
     * Constructor.
     *
     * @param array $options {
     *     Optional. Configuration options.
     *     @type bool $skip_detail_fetch Skip detail page fetches (default false).
     * }
     */
    public function __construct( $options = array() ) {
        $settings      = function_exists( 'ontario_obituaries_get_settings' ) ? ontario_obituaries_get_settings() : array();
        $this->max_age = isset( $settings['max_age'] ) ? intval( $settings['max_age'] ) : 7;

        if ( ! empty( $options['skip_detail_fetch'] ) ) {
            $this->skip_detail_fetch = true;
        }
    }

    /**
     * Run the full collection pipeline.
     *
     * @param string $region Optional — filter sources by region.
     * @param string $city   Optional — filter sources by city.
     * @return array Results { obituaries_found, obituaries_added, sources_processed, errors, per_source }
     */
    /**
     * v4.6.2: Collect from a single source by ID.
     *
     * Used by the source-by-source AJAX rescan to stay within server timeouts.
     *
     * @param int $source_id Source registry ID.
     * @return array Same format as collect().
     */
    public function collect_source( $source_id ) {
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

        $table_name = $wpdb->prefix . 'ontario_obituaries';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
            $this->results['errors']['database'] = 'Obituaries table does not exist.';
            $this->results['completed_at'] = current_time( 'mysql', true );
            return $this->results;
        }

        // Load the specific source.
        $source_table = Ontario_Obituaries_Source_Registry::table_name();
        $source = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$source_table}` WHERE id = %d AND enabled = 1",
            $source_id
        ), ARRAY_A );

        if ( empty( $source ) ) {
            $this->results['errors']['source'] = sprintf( 'Source ID %d not found or disabled.', $source_id );
            $this->results['completed_at'] = current_time( 'mysql', true );
            return $this->results;
        }

        // v6.1.0 BUG FIX: Check banned sources for single-source collection too.
        // Previously, filter_banned_sources() was only called in collect(), so a
        // banned source could still be scraped via collect_source() (AJAX per-source).
        $filtered = $this->filter_banned_sources( array( $source ) );
        if ( empty( $filtered ) ) {
            $this->results['errors']['source'] = sprintf( 'Source ID %d is banned.', $source_id );
            $this->results['completed_at'] = current_time( 'mysql', true );
            return $this->results;
        }

        $this->process_source( $source, $table_name );
        $this->results['completed_at'] = current_time( 'mysql', true );
        return $this->results;
    }

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

        // v6.1.0: Filter out banned sources before processing.
        $sources = $this->filter_banned_sources( $sources );

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
            // v3.12.2: Per-source diagnostics for Reset & Rescan UI.
            'http_status'   => '',
            'error_message' => '',
            'final_url'     => '',
            'duration_ms'   => 0,
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

            /**
             * Filter the discovered listing URLs before fetching begins.
             *
             * Allows callers to cap or modify the URL list — e.g., the
             * synchronous first-page scrape in on_plugin_update() limits
             * each source to 1 URL to avoid timeouts.
             *
             * @since 3.10.0
             *
             * @param string[] $listing_urls Discovered listing page URLs.
             * @param array    $source       Source registry row (id, domain, adapter_type, etc.).
             */
            $listing_urls = apply_filters( 'ontario_obituaries_discovered_urls', $listing_urls, $source );

            foreach ( $listing_urls as $listing_url ) {
                $source_result['pages']++;

                // Rate limiting between pages
                if ( $source_result['pages'] > 1 && $request_interval > 0 ) {
                    usleep( (int) ( $request_interval * 1000000 ) );
                }

                // 4. Fetch listing
                $html = $adapter->fetch_listing( $listing_url, $source );

                // v3.12.2: Capture HTTP diagnostics from the adapter.
                // Always overwrite so the last page's diagnostics are retained.
                if ( method_exists( $adapter, 'get_last_request_diagnostics' ) ) {
                    $diag = $adapter->get_last_request_diagnostics();
                    if ( ! empty( $diag ) ) {
                        $source_result['http_status']   = isset( $diag['http_status'] )   ? $diag['http_status']   : '';
                        $source_result['final_url']     = isset( $diag['final_url'] )     ? $diag['final_url']     : '';
                        $source_result['duration_ms']   = isset( $diag['duration_ms'] )   ? intval( $diag['duration_ms'] ) : 0;
                        // Only overwrite error_message when there IS an error.
                        if ( ! empty( $diag['error_message'] ) ) {
                            $source_result['error_message'] = $diag['error_message'];
                        }
                    }
                }

                if ( is_wp_error( $html ) ) {
                    $source_result['errors'][] = $html->get_error_message();
                    continue;
                }

                // 5. Extract cards
                $cards = $adapter->extract_obit_cards( $html, $source );
                $source_result['found'] += count( $cards );

                // v3.8.0: Log when a page was fetched successfully but yielded 0 cards.
                if ( empty( $cards ) && ! empty( $html ) && strlen( $html ) > 500 ) {
                    ontario_obituaries_log(
                        sprintf(
                            'Source %s page %s: fetched %d bytes but extracted 0 cards — selectors may be stale.',
                            $domain,
                            $listing_url,
                            strlen( $html )
                        ),
                        'warning'
                    );
                }

                foreach ( $cards as $card ) {
                    // 5b. Optional detail fetch
                    // v4.6.5: Skip detail fetches during interactive rescans.
                    // Detail pages are ~328KB each; 125 cards × 3s = 375s per source,
                    // which exceeds LiteSpeed's 120s timeout. Listing data is sufficient
                    // for initial ingest (name, dates, description snippet, published date).
                    if ( ! $this->skip_detail_fetch && ! empty( $card['detail_url'] ) ) {
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
                    // v5.1.0 FIX: Also reject '0000-00-00' which PHP's empty() treats
                    // as non-empty (it's a non-empty string). normalize_date() can
                    // return this placeholder in edge cases, and MySQL accepts it as a
                    // valid DATE, but it is semantically empty — no real death date.
                    if ( empty( $record['name'] ) || empty( $record['date_of_death'] ) || '0000-00-00' === $record['date_of_death'] ) {
                        continue;
                    }

                    // v5.1.1 FIX: Reject "poison date" records at ingest.
                    // These produce rows that block the AI rewriter queue and display
                    // implausible data on the frontend.
                    //
                    // Rules:
                    //   1. DOD before 2000-01-01 — Ontario obituary data starts ~2020+;
                    //      pre-2000 dates are extraction artefacts.
                    //   2. DOD == DOB — impossible unless stillbirth (not our coverage).
                    //   3. DOD <= DOB when both are non-empty and non-zero — inverted
                    //      range means at least one date is wrong.
                    $dod = $record['date_of_death'];
                    if ( $dod < '2000-01-01' ) {
                        ontario_obituaries_log(
                            sprintf( 'Collector: Rejected "%s" — DOD %s is before 2000.', $record['name'], $dod ),
                            'warning'
                        );
                        continue;
                    }
                    $dob = isset( $record['date_of_birth'] ) ? $record['date_of_birth'] : '';
                    if ( ! empty( $dob ) && '0000-00-00' !== $dob && $dod <= $dob ) {
                        ontario_obituaries_log(
                            sprintf( 'Collector: Rejected "%s" — DOD %s <= DOB %s.', $record['name'], $dod, $dob ),
                            'warning'
                        );
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
     * Deduplication strategy (layered):
     *   1. Cross-source fuzzy dedup: same name + death date = skip
     *      (catches the same person scraped from different sources)
     *   2. INSERT IGNORE on unique key (name, date_of_death, funeral_home)
     *      (catches exact duplicates from the same source)
     *
     * When a cross-source duplicate IS found, we enrich the existing record
     * if the new source has a longer description (better content wins).
     *
     * @param array  $record     Normalized obituary data.
     * @param string $table_name Full table name.
     * @return bool Whether a new row was inserted.
     */
    private function insert_obituary( $record, $table_name ) {
        global $wpdb;

        // ── Cross-source dedup: same name + same death date ───────────────
        // Normalise the name for fuzzy matching (strip titles, extra spaces).
        $clean_name = $this->normalize_name_for_dedup( $record['name'] );

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, description, source_url, funeral_home FROM `{$table_name}`
             WHERE date_of_death = %s
               AND suppressed_at IS NULL
             ORDER BY id ASC",
            $record['date_of_death']
        ) );

        // Check all records with the same death date for a name match
        if ( $existing ) {
            $candidates = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, name, description, source_url, funeral_home, city_normalized, location, image_url, date_of_birth, age FROM `{$table_name}`
                 WHERE date_of_death = %s
                   AND suppressed_at IS NULL",
                $record['date_of_death']
            ) );

            foreach ( $candidates as $candidate ) {
                $existing_clean = $this->normalize_name_for_dedup( $candidate->name );

                // Match if names are identical after normalization, or one contains the other
                if ( $existing_clean === $clean_name
                     || ( strlen( $clean_name ) > 5 && strlen( $existing_clean ) > 5
                          && ( false !== strpos( $existing_clean, $clean_name )
                               || false !== strpos( $clean_name, $existing_clean ) ) )
                ) {
                    // Duplicate found — enrich if new source has better content
                    $new_desc_len = isset( $record['description'] ) ? strlen( $record['description'] ) : 0;
                    $old_desc_len = strlen( $candidate->description );

                    // v3.7.0: Always enrich empty fields from the new source,
                    // even when the existing record has a longer description.
                    $update_data = array();

                    if ( $new_desc_len > $old_desc_len ) {
                        $update_data['description'] = $record['description'];
                    }

                    // Fill empty funeral_home from duplicate
                    if ( empty( $candidate->funeral_home ) && ! empty( $record['funeral_home'] ) ) {
                        $update_data['funeral_home'] = $record['funeral_home'];
                    }

                    // v3.7.0: Fill empty city_normalized from duplicate
                    if ( empty( $candidate->city_normalized ) && ! empty( $record['city_normalized'] ) ) {
                        $update_data['city_normalized'] = $record['city_normalized'];
                    }

                    // v3.7.0: Fill empty location from duplicate
                    if ( empty( $candidate->location ) && ! empty( $record['location'] ) ) {
                        $update_data['location'] = $record['location'];
                    }

                    // v3.15.1: Update image_url if existing is empty OR if new URL
                    // is a better-quality CDN image (300×400 print-only vs 200×200 thumb).
                    if ( ! empty( $record['image_url'] ) ) {
                        if ( empty( $candidate->image_url ) || strlen( $candidate->image_url ) < 10 ) {
                            $update_data['image_url'] = $record['image_url'];
                        }
                    }

                    // v3.14.1: Fill empty date_of_birth from duplicate
                    if ( ( empty( $candidate->date_of_birth ) || '0000-00-00' === $candidate->date_of_birth ) && ! empty( $record['date_of_birth'] ) ) {
                        $update_data['date_of_birth'] = $record['date_of_birth'];
                    }

                    // v3.14.1: Update age if existing is 0 and new has a value
                    if ( ( empty( $candidate->age ) || 0 === intval( $candidate->age ) ) && ! empty( $record['age'] ) && intval( $record['age'] ) > 0 ) {
                        $update_data['age'] = intval( $record['age'] );
                    }

                    if ( ! empty( $update_data ) ) {
                        $upd_result = $wpdb->update( $table_name, $update_data, array( 'id' => $candidate->id ) );
                        if ( function_exists( 'oo_db_check' ) ) {
                            oo_db_check( 'SCRAPE', $upd_result, 'DB_UPDATE_FAIL', 'Failed to update duplicate obituary', array(
                                'obit_id' => $candidate->id,
                                'source'  => isset( $record['source_domain'] ) ? $record['source_domain'] : '',
                            ) );
                        }
                    }

                    return false; // Not a new insert — it's a cross-source duplicate
                }
            }
        }

        // ── Standard INSERT IGNORE (catches exact dupes from same source) ──
        // v4.6.0: All new records start as 'pending'. They become 'published'
        // only after AI rewrite + validation passes.
        $sql = $wpdb->prepare(
            "INSERT IGNORE INTO `{$table_name}`
                (name, date_of_birth, date_of_death, age, funeral_home, location,
                 image_url, description, source_url, source_domain, source_type,
                 city_normalized, provenance_hash, status)
             VALUES (%s, %s, %s, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, 'pending')",
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

        $result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

        // v6.0.0: Structured DB error check via oo_db_check().
        // INSERT IGNORE returns 0 rows for duplicates (not an error), so we only
        // flag strict false (actual DB failure, e.g., table missing, connection lost).
        if ( function_exists( 'oo_db_check' ) ) {
            oo_db_check( 'SCRAPE', $result, 'DB_INSERT_FAIL', 'Failed to insert obituary record', array(
                'name'   => isset( $record['name'] ) ? $record['name'] : '',
                'source' => isset( $record['source_domain'] ) ? $record['source_domain'] : '',
            ) );
        }

        $was_inserted = $wpdb->rows_affected > 0;

        // v5.2.0: Localize the image immediately for new inserts.
        // This runs synchronously (~1-2s) but only for genuinely new records.
        // Existing records (INSERT IGNORE no-ops) are skipped.
        // GAP-FIX #5: Wrapped in try/catch(\Throwable) so an image download
        // failure (TypeError, OOM, network fatal) never breaks the scrape loop.
        // The migration cron will pick up any missed images within 5 minutes.
        if ( $was_inserted && ! empty( $record['image_url'] ) && class_exists( 'Ontario_Obituaries_Image_Localizer' ) ) {
            $new_id = $wpdb->insert_id;
            if ( $new_id > 0 ) {
                try {
                    Ontario_Obituaries_Image_Localizer::localize_on_insert( $new_id, $record['image_url'] );
                } catch ( \Throwable $e ) {
                    // Image localization is non-critical — log and continue.
                    ontario_obituaries_log(
                        sprintf( 'Image localize_on_insert failed for #%d: %s', $new_id, $e->getMessage() ),
                        'warning'
                    );
                }
            }
        }

        return $was_inserted;
    }

    /**
     * Normalize a name for fuzzy dedup comparison.
     *
     * Strips common prefixes/suffixes, collapses whitespace,
     * lowercases, and removes non-alpha characters.
     *
     * @param string $name Raw name.
     * @return string Normalized name.
     */
    private function normalize_name_for_dedup( $name ) {
        $name = strtolower( trim( $name ) );

        // Remove common titles / suffixes
        $name = preg_replace( '/\b(mr|mrs|ms|dr|jr|sr|ii|iii|obituary)\b\.?/i', '', $name );

        // Remove parenthetical nicknames: "John (Jack) Smith" → "John Smith"
        $name = preg_replace( '/\([^)]*\)/', '', $name );

        // Remove non-alpha (keep spaces)
        $name = preg_replace( '/[^a-z\s]/', '', $name );

        // Collapse whitespace
        $name = preg_replace( '/\s+/', ' ', trim( $name ) );

        return $name;
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

    /**
     * v6.1.0: Filter out banned sources before collection.
     *
     * Reads the 'ontario_obituaries_banned_sources' option (array of domain
     * patterns). Sources whose 'domain' matches any banned pattern are skipped.
     * Supports exact match and wildcard prefix (e.g., '*.legacy.com').
     *
     * @param array $sources Array of source rows from the registry.
     * @return array Filtered sources with banned entries removed.
     */
    private function filter_banned_sources( $sources ) {
        $banned = get_option( 'ontario_obituaries_banned_sources', array() );
        if ( empty( $banned ) || ! is_array( $banned ) ) {
            return $sources;
        }

        $filtered = array();
        foreach ( $sources as $source ) {
            $domain = isset( $source['domain'] ) ? strtolower( $source['domain'] ) : '';
            $is_banned = false;

            foreach ( $banned as $pattern ) {
                $pattern = strtolower( trim( $pattern ) );
                if ( empty( $pattern ) ) {
                    continue;
                }
                // Exact match.
                if ( $domain === $pattern ) {
                    $is_banned = true;
                    break;
                }
                // Wildcard prefix: *.example.com matches sub.example.com.
                if ( 0 === strpos( $pattern, '*.' ) ) {
                    $suffix = substr( $pattern, 1 ); // '.example.com'
                    if ( substr( $domain, -strlen( $suffix ) ) === $suffix ) {
                        $is_banned = true;
                        break;
                    }
                }
                // Contains match for partial domain patterns (e.g., 'legacy.com' matches 'legacy.com/ca/...')
                if ( false !== strpos( $domain, $pattern ) ) {
                    $is_banned = true;
                    break;
                }
            }

            if ( $is_banned ) {
                ontario_obituaries_log(
                    sprintf( 'Source Manager: Skipping banned source "%s" (matched ban list).', $domain ),
                    'info'
                );
                $this->results['sources_skipped']++;
                continue;
            }

            $filtered[] = $source;
        }

        return $filtered;
    }
}
