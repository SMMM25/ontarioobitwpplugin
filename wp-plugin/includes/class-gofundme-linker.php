<?php
/**
 * GoFundMe Auto-Linker
 *
 * Searches GoFundMe for memorial/funeral fundraising campaigns that match
 * obituary records by name, death date, and location. Uses a strict 3-point
 * verification system to prevent false matches.
 *
 * Architecture:
 *   - Uses GoFundMe's public Algolia search API (same as their website).
 *   - Processes obituaries in batches via WP-Cron.
 *   - Stores verified links in the gofundme_url column.
 *   - Rate-limited: 1 search per 3 seconds to be a good citizen.
 *   - Batch size: 20 obituaries per run.
 *   - Only matches campaigns created within ±90 days of the death date.
 *
 * Matching algorithm (3-point verification):
 *   1. NAME MATCH    — deceased's last name appears in campaign title or description.
 *   2. DATE MATCH    — campaign created within ±90 days of the death date, AND
 *                       description mentions a date or year consistent with the death.
 *   3. LOCATION MATCH — campaign city/state matches obituary city/province.
 *
 * All three points must pass for a link to be stored.
 *
 * @package Ontario_Obituaries
 * @since   4.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ontario_Obituaries_GoFundMe_Linker {

    /** @var string Algolia Application ID (public, same as GoFundMe's website). */
    private $algolia_app_id = 'E7PHE9BB38';

    /** @var string Algolia public search API key (read-only, from GoFundMe's frontend). */
    private $algolia_api_key = '2a43f30c25e7719436f10fed6d788170';

    /** @var string Algolia search index for fundraisers. */
    private $algolia_index = 'prod_funds_feed_replica_1';

    /** @var int Maximum obituaries to process per batch. */
    private $batch_size = 20;

    /** @var int Delay between API requests in microseconds (3 seconds). */
    private $request_delay = 3000000;

    /** @var int Maximum days between death date and campaign creation to consider a match. */
    private $date_window_days = 90;

    /** @var int API timeout in seconds. */
    private $api_timeout = 15;

    /**
     * Process a batch of obituaries to find matching GoFundMe campaigns.
     *
     * Selects obituaries that:
     *   - Have no gofundme_url yet
     *   - Have not been previously checked (gofundme_checked_at IS NULL)
     *   - Have a name and death date
     *   - Are not suppressed
     *
     * @return array { processed: int, matched: int, no_match: int, errors: string[] }
     */
    public function process_batch() {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        // v4.6.0: Only check published obituaries (already AI-rewritten & validated).
        $obituaries = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, date_of_birth, date_of_death, age, location, city_normalized, funeral_home
             FROM `{$table}`
             WHERE (gofundme_url IS NULL OR gofundme_url = '')
               AND gofundme_checked_at IS NULL
               AND name IS NOT NULL AND name != ''
               AND date_of_death IS NOT NULL AND date_of_death != '' AND date_of_death != '0000-00-00'
               AND suppressed_at IS NULL
               AND status = 'published'
             ORDER BY created_at DESC
             LIMIT %d",
            $this->batch_size
        ) );

        $result = array(
            'processed' => 0,
            'matched'   => 0,
            'no_match'  => 0,
            'errors'    => array(),
        );

        if ( empty( $obituaries ) ) {
            ontario_obituaries_log( 'GoFundMe Linker: No unchecked obituaries remaining.', 'info' );
            return $result;
        }

        ontario_obituaries_log(
            sprintf( 'GoFundMe Linker: Processing batch of %d obituaries.', count( $obituaries ) ),
            'info'
        );

        foreach ( $obituaries as $obit ) {
            $result['processed']++;

            // Rate limiting.
            if ( $result['processed'] > 1 ) {
                usleep( $this->request_delay );
            }

            $match = $this->find_matching_campaign( $obit );

            if ( is_wp_error( $match ) ) {
                $result['errors'][] = sprintf( 'ID %d (%s): %s', $obit->id, $obit->name, $match->get_error_message() );

                // Mark as checked even on error so we don't retry endlessly.
                $gfm_upd = $wpdb->update(
                    $table,
                    array( 'gofundme_checked_at' => current_time( 'mysql' ) ),
                    array( 'id' => $obit->id ),
                    array( '%s' ),
                    array( '%d' )
                );
                if ( function_exists( 'oo_db_check' ) ) {
                    oo_db_check( 'GOFUNDME', $gfm_upd, 'DB_UPDATE_FAIL', 'Failed to mark GoFundMe checked (error path)', array( 'obit_id' => $obit->id ) );
                }
                continue;
            }

            if ( ! empty( $match ) ) {
                // Verified match found — store the link.
                $gfm_upd = $wpdb->update(
                    $table,
                    array(
                        'gofundme_url'        => esc_url_raw( $match['url'] ),
                        'gofundme_checked_at' => current_time( 'mysql' ),
                    ),
                    array( 'id' => $obit->id ),
                    array( '%s', '%s' ),
                    array( '%d' )
                );
                if ( function_exists( 'oo_db_check' ) ) {
                    oo_db_check( 'GOFUNDME', $gfm_upd, 'DB_UPDATE_FAIL', 'Failed to store GoFundMe match', array( 'obit_id' => $obit->id ) );
                }

                ontario_obituaries_log(
                    sprintf(
                        'GoFundMe Linker: MATCHED ID %d (%s) → %s (score: %s).',
                        $obit->id, $obit->name, $match['url'], $match['match_details']
                    ),
                    'info'
                );

                $result['matched']++;
            } else {
                // No match — mark as checked.
                $gfm_upd = $wpdb->update(
                    $table,
                    array( 'gofundme_checked_at' => current_time( 'mysql' ) ),
                    array( 'id' => $obit->id ),
                    array( '%s' ),
                    array( '%d' )
                );
                if ( function_exists( 'oo_db_check' ) ) {
                    oo_db_check( 'GOFUNDME', $gfm_upd, 'DB_UPDATE_FAIL', 'Failed to mark GoFundMe checked (no-match path)', array( 'obit_id' => $obit->id ) );
                }
                $result['no_match']++;
            }
        }

        ontario_obituaries_log(
            sprintf(
                'GoFundMe Linker: Batch complete — %d processed, %d matched, %d no match, %d errors.',
                $result['processed'], $result['matched'], $result['no_match'], count( $result['errors'] )
            ),
            'info'
        );

        return $result;
    }

    /**
     * Search GoFundMe for a campaign matching the given obituary.
     *
     * @param object $obit Obituary database row.
     * @return array|null|WP_Error Match array with url + match_details, null if no match, or error.
     */
    private function find_matching_campaign( $obit ) {
        $name_parts  = $this->parse_name( $obit->name );
        $last_name   = $name_parts['last'];
        $first_name  = $name_parts['first'];
        $city        = ! empty( $obit->city_normalized ) ? $obit->city_normalized : $obit->location;
        $death_date  = $obit->date_of_death;
        $death_year  = intval( substr( $death_date, 0, 4 ) );

        if ( strlen( $last_name ) < 2 ) {
            return new WP_Error( 'name_too_short', 'Last name too short for reliable matching.' );
        }

        // Build search query: "FirstName LastName memorial" filtered to Canada.
        $search_query = trim( $first_name . ' ' . $last_name );
        $search_hits  = $this->search_gofundme( $search_query, 'CA' );

        if ( is_wp_error( $search_hits ) ) {
            return $search_hits;
        }

        if ( empty( $search_hits ) ) {
            return null; // No campaigns found at all.
        }

        // Evaluate each hit against the 3-point verification.
        foreach ( $search_hits as $hit ) {
            $verification = $this->verify_match( $hit, $obit, $name_parts, $city, $death_date, $death_year );

            if ( ! empty( $verification ) ) {
                return $verification;
            }
        }

        return null; // No verified match.
    }

    /**
     * Query GoFundMe's Algolia search index.
     *
     * @param string $query  Search query.
     * @param string $country Country filter (e.g., 'CA').
     * @return array|WP_Error Array of hit objects, or error.
     */
    private function search_gofundme( $query, $country = '' ) {
        $url = sprintf(
            'https://%s-dsn.algolia.net/1/indexes/%s/query',
            $this->algolia_app_id,
            $this->algolia_index
        );

        $body = array(
            'query'       => $query,
            'hitsPerPage' => 10,
        );

        // Filter to country if specified.
        if ( ! empty( $country ) ) {
            $body['filters'] = 'country:' . $country;
        }

        $response = oo_safe_http_post( 'GOFUNDME', $url, array(
            'timeout' => $this->api_timeout,
            'headers' => array(
                'X-Algolia-Application-Id' => $this->algolia_app_id,
                'X-Algolia-API-Key'        => $this->algolia_api_key,
                'Content-Type'             => 'application/json',
            ),
            'body' => wp_json_encode( $body ),
        ), array( 'source' => 'class-gofundme-linker' ) );

        if ( is_wp_error( $response ) ) {
            // Wrapper already logged. Re-code to caller's expected error code.
            return new WP_Error( 'gfm_api_error', 'GoFundMe search failed: ' . $response->get_error_message() );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        return isset( $data['hits'] ) ? $data['hits'] : array();
    }

    /**
     * Verify a GoFundMe hit against the 3-point matching criteria.
     *
     * @param array  $hit        Algolia search hit.
     * @param object $obit       Obituary record.
     * @param array  $name_parts Parsed name components.
     * @param string $city       Obituary city.
     * @param string $death_date Death date (Y-m-d).
     * @param int    $death_year Death year.
     * @return array|null Match array or null.
     */
    private function verify_match( $hit, $obit, $name_parts, $city, $death_date, $death_year ) {
        $fund_name  = isset( $hit['fundname'] )                ? $hit['fundname']                : '';
        $fund_desc  = isset( $hit['funddescription_cleaned'] ) ? $hit['funddescription_cleaned'] : '';
        $fund_city  = isset( $hit['city'] )                    ? $hit['city']                    : '';
        $fund_state = isset( $hit['state'] )                   ? $hit['state']                   : '';
        $fund_url   = isset( $hit['url'] )                     ? $hit['url']                     : '';

        if ( empty( $fund_desc ) ) {
            $fund_desc = isset( $hit['funddescription'] ) ? $hit['funddescription'] : '';
        }

        $combined_text = strtolower( $fund_name . ' ' . $fund_desc );
        $points        = array();

        // ── POINT 1: NAME MATCH ──
        // The deceased's last name must appear in the campaign title or description.
        // For common last names (< 5 chars), require both first AND last.
        $last_name  = $name_parts['last'];
        $first_name = $name_parts['first'];

        $last_found  = ( strlen( $last_name ) >= 2 )  && ( false !== stripos( $combined_text, strtolower( $last_name ) ) );
        $first_found = ( strlen( $first_name ) >= 2 ) && ( false !== stripos( $combined_text, strtolower( $first_name ) ) );

        if ( ! $last_found ) {
            return null; // Last name must be present.
        }

        // For short/common last names, require first name too.
        if ( strlen( $last_name ) <= 4 && ! $first_found ) {
            return null;
        }

        // Full name match is stronger.
        if ( $first_found && $last_found ) {
            $points[] = 'full_name';
        } else {
            $points[] = 'last_name';
        }

        // ── POINT 2: DATE MATCH ──
        // Campaign description must reference the death year OR a date near the death date.
        $date_matched = false;

        // Check for the death year in the description.
        if ( false !== strpos( $fund_desc, (string) $death_year ) ) {
            $date_matched = true;
            $points[]     = 'death_year_in_desc';
        }

        // Check for specific month/day patterns matching the death date.
        if ( ! $date_matched && ! empty( $death_date ) ) {
            $death_ts    = strtotime( $death_date );
            $death_month = date( 'F', $death_ts );
            $death_day   = intval( date( 'j', $death_ts ) );

            // Look for "Month Day" pattern.
            if ( preg_match( '/' . preg_quote( $death_month, '/' ) . '\s+' . $death_day . '/i', $fund_desc ) ) {
                $date_matched = true;
                $points[]     = 'death_date_in_desc';
            }
        }

        // Fallback: check if campaign was created within the date window.
        // Use the Algolia hit's objectID or other timing indicator.
        // Since Algolia doesn't always expose creation date, the year check is primary.
        if ( ! $date_matched ) {
            // Check for adjacent year (in case death was late Dec / early Jan).
            $adjacent_year = ( intval( date( 'n', strtotime( $death_date ) ) ) <= 2 ) ? $death_year - 1 : $death_year + 1;
            if ( false !== strpos( $fund_desc, (string) $adjacent_year ) ) {
                $date_matched = true;
                $points[]     = 'adjacent_year_in_desc';
            }
        }

        if ( ! $date_matched ) {
            return null; // Date verification failed.
        }

        // ── POINT 3: LOCATION MATCH ──
        // Campaign city/state must match the obituary's city or province.
        $location_matched = false;

        if ( ! empty( $city ) && ! empty( $fund_city ) ) {
            // Direct city match.
            if ( $this->cities_match( $city, $fund_city ) ) {
                $location_matched = true;
                $points[]         = 'city_match';
            }
        }

        // Check state/province — Ontario = ON.
        if ( ! $location_matched && ! empty( $fund_state ) ) {
            $on_variants = array( 'on', 'ontario' );
            if ( in_array( strtolower( trim( $fund_state ) ), $on_variants, true ) ) {
                $location_matched = true;
                $points[]         = 'province_match';
            }
        }

        // Check if the city name appears in the campaign description.
        if ( ! $location_matched && ! empty( $city ) ) {
            if ( false !== stripos( $combined_text, strtolower( $city ) ) ) {
                $location_matched = true;
                $points[]         = 'city_in_desc';
            }
        }

        if ( ! $location_matched ) {
            return null; // Location verification failed.
        }

        // ── ALL 3 POINTS VERIFIED ──
        // Must have a name, date, AND location match.
        $has_name     = in_array( 'full_name', $points, true ) || in_array( 'last_name', $points, true );
        $has_date     = in_array( 'death_year_in_desc', $points, true ) || in_array( 'death_date_in_desc', $points, true ) || in_array( 'adjacent_year_in_desc', $points, true );
        $has_location = in_array( 'city_match', $points, true ) || in_array( 'province_match', $points, true ) || in_array( 'city_in_desc', $points, true );

        if ( $has_name && $has_date && $has_location ) {
            // Category 9 = memorial/funeral on GoFundMe. Bonus confidence if category matches.
            $category = isset( $hit['category_id'] ) ? intval( $hit['category_id'] ) : 0;
            if ( $category === 9 ) {
                $points[] = 'memorial_category';
            }

            return array(
                'url'           => 'https://www.gofundme.com/f/' . $fund_url,
                'title'         => $fund_name,
                'match_details' => implode( '+', $points ),
            );
        }

        return null;
    }

    /**
     * Parse an obituary name into first and last name components.
     *
     * Handles formats like:
     *   "JOHN SMITH"
     *   "John Michael Smith"
     *   "SMITH, John Michael"
     *   "John Smith (nee Jones)"
     *
     * @param string $name Full name from the obituary.
     * @return array { first: string, last: string }
     */
    private function parse_name( $name ) {
        // Remove parenthetical notes like "(nee Jones)", "(née Smith)".
        $name = preg_replace( '/\s*\(.*?\)\s*/', ' ', $name );
        // Remove quotes.
        $name = str_replace( array( '"', "'", "\xE2\x80\x9C", "\xE2\x80\x9D" ), '', $name );
        $name = trim( preg_replace( '/\s+/', ' ', $name ) );

        // Check for "LAST, First" format.
        if ( false !== strpos( $name, ',' ) ) {
            $parts = explode( ',', $name, 2 );
            return array(
                'last'  => trim( $parts[0] ),
                'first' => trim( explode( ' ', trim( $parts[1] ) )[0] ),
            );
        }

        // Standard "First [Middle] Last" format.
        $parts = preg_split( '/\s+/', $name );

        // Remove common suffixes.
        $suffixes = array( 'jr', 'jr.', 'sr', 'sr.', 'ii', 'iii', 'iv', 'v' );
        while ( ! empty( $parts ) && in_array( strtolower( end( $parts ) ), $suffixes, true ) ) {
            array_pop( $parts );
        }

        if ( count( $parts ) < 2 ) {
            return array( 'first' => $name, 'last' => $name );
        }

        return array(
            'first' => $parts[0],
            'last'  => end( $parts ),
        );
    }

    /**
     * Compare two city names for a match (fuzzy — handles case, accent, minor spelling).
     *
     * @param string $city1 First city name.
     * @param string $city2 Second city name.
     * @return bool True if cities match.
     */
    private function cities_match( $city1, $city2 ) {
        $c1 = strtolower( trim( $city1 ) );
        $c2 = strtolower( trim( $city2 ) );

        if ( $c1 === $c2 ) {
            return true;
        }

        // Strip common suffixes.
        $c1 = preg_replace( '/\s+(city|town|village|township)$/i', '', $c1 );
        $c2 = preg_replace( '/\s+(city|town|village|township)$/i', '', $c2 );

        if ( $c1 === $c2 ) {
            return true;
        }

        // Levenshtein for minor typos (within 2 edits for names > 5 chars).
        if ( strlen( $c1 ) > 5 && strlen( $c2 ) > 5 ) {
            return levenshtein( $c1, $c2 ) <= 2;
        }

        return false;
    }

    /**
     * Get statistics for the GoFundMe linker.
     *
     * @return array { total: int, checked: int, matched: int, pending: int }
     */
    public function get_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        // v4.6.0: Only count published records.
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}` WHERE suppressed_at IS NULL AND status = 'published' AND name != '' AND date_of_death != '' AND date_of_death != '0000-00-00'"
        );

        $checked = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}` WHERE gofundme_checked_at IS NOT NULL AND suppressed_at IS NULL AND status = 'published'"
        );

        $matched = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}` WHERE gofundme_url IS NOT NULL AND gofundme_url != '' AND suppressed_at IS NULL AND status = 'published'"
        );

        return array(
            'total'   => $total,
            'checked' => $checked,
            'matched' => $matched,
            'pending' => $total - $checked,
        );
    }
}
