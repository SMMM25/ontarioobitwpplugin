<?php
/**
 * Remembering.ca / Postmedia Obituary Network Adapter
 *
 * Handles obituaries from the Remembering.ca platform, which powers
 * obituary sections for Postmedia newspapers across Canada:
 *   - obituaries.yorkregion.com
 *   - nationalpost.remembering.ca
 *   - lfpress.remembering.ca
 *   etc.
 *
 * Remembering.ca is a paid obituary placement platform where families
 * or funeral homes submit and pay for obituary notices through newspapers.
 * We extract only publicly available listing-level facts.
 *
 * @package Ontario_Obituaries
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ontario_Obituaries_Adapter_Remembering_Ca extends Ontario_Obituaries_Source_Adapter_Base {

    public function get_type() {
        return 'remembering_ca';
    }

    public function get_label() {
        return 'Remembering.ca / Postmedia Newspapers';
    }

    public function discover_listing_urls( $source, $max_age = 7 ) {
        $urls      = array( $source['base_url'] );
        $max_pages = isset( $source['max_pages_per_run'] ) ? intval( $source['max_pages_per_run'] ) : 5;

        // v3.9.0 FIX: The Remembering.ca / Postmedia platform uses ?p= for pagination.
        // The previous value ?page= was silently ignored, returning page-1 data every time.
        // Verified on obituaries.yorkregion.com and www.remembering.ca — ?p= is correct.
        // This adapter is only used for sources with adapter_type 'remembering_ca'.
        for ( $p = 2; $p <= $max_pages; $p++ ) {
            $urls[] = add_query_arg( 'p', $p, $source['base_url'] );
        }

        return $urls;
    }

    public function fetch_listing( $url, $source ) {
        return $this->http_get( $url, array(
            'Accept-Language' => 'en-CA,en;q=0.9',
        ) );
    }

    public function extract_obit_cards( $html, $source ) {
        $cards = array();
        $doc   = $this->parse_html( $html );
        $xpath = new DOMXPath( $doc );

        // Remembering.ca listing patterns
        // v3.6.0: Added selectors for yorkregion.com's actual HTML structure
        // (Bishop/Postmedia template uses .ap_ad_wrap > a > .listview-summary)
        $selectors = array(
            '//div[contains(@class,"ap_ad_wrap")]',
            '//div[contains(@class,"listview-summary")]/..',
            '//div[contains(@class,"obituary-card")]',
            '//div[contains(@class,"obit-listing")]//article',
            '//div[contains(@class,"listing")]//div[contains(@class,"card")]',
            '//ul[contains(@class,"obituaries")]//li',
            '//div[contains(@class,"search-results")]//div[contains(@class,"result")]',
            // Fallback: anchor-based
            '//a[contains(@href,"/obituary/") or contains(@href,"/obituaries/")]/..',
        );

        $nodes = null;
        foreach ( $selectors as $sel ) {
            $nodes = $xpath->query( $sel );
            if ( $nodes && $nodes->length > 0 ) {
                break;
            }
        }

        if ( ! $nodes || 0 === $nodes->length ) {
            // v3.8.0: Diagnostic — log when we get a page but extract 0 cards.
            // This means the HTML structure changed and our selectors are stale.
            $html_len = strlen( $html );
            $snippet  = substr( wp_strip_all_tags( $html ), 0, 300 );
            ontario_obituaries_log(
                sprintf(
                    'Remembering.ca adapter: 0 cards extracted from %s (HTML size: %d bytes). Snippet: %s',
                    isset( $source['base_url'] ) ? $source['base_url'] : 'unknown',
                    $html_len,
                    $snippet
                ),
                'warning'
            );
            return $cards;
        }

        foreach ( $nodes as $node ) {
            $card = $this->extract_card( $xpath, $node, $source );
            if ( ! empty( $card['name'] ) ) {
                $cards[] = $card;
            }
        }

        return $cards;
    }

    /**
     * Extract data from a single card.
     */
    private function extract_card( $xpath, $node, $source ) {
        $card = array();

        // Name
        $name_queries = array(
            './/h2/a', './/h3/a', './/h4/a',
            './/h2', './/h3', './/h4',
            './/*[contains(@class,"name")]',
            './/strong/a', './/strong',
        );
        foreach ( $name_queries as $q ) {
            $n = $xpath->query( $q, $node )->item( 0 );
            if ( $n && ! empty( trim( $n->textContent ) ) ) {
                $card['name'] = $this->node_text( $n );
                if ( 'a' === $n->nodeName ) {
                    $card['detail_url'] = $this->resolve_url( $this->node_attr( $n, 'href' ), $source['base_url'] );
                }
                break;
            }
        }

        // Detail URL fallback
        if ( empty( $card['detail_url'] ) ) {
            $link = $xpath->query( './/a', $node )->item( 0 );
            if ( $link ) {
                $card['detail_url'] = $this->resolve_url( $this->node_attr( $link, 'href' ), $source['base_url'] );
            }
        }

        // Dates — v3.6.0: Remembering.ca / yorkregion.com often shows only
        // years in the dates div ("1926", "1961 - 2026").  We capture those
        // as year_text and later attempt to extract full dates from the
        // content or Published line.
        $date_queries = array(
            './/*[contains(@class,"date")]',
            './/time',
            './/span[contains(@class,"dates")]',
        );
        foreach ( $date_queries as $q ) {
            $d = $xpath->query( $q, $node )->item( 0 );
            if ( $d ) {
                $dt = $this->node_attr( $d, 'datetime' );
                $card['date_text'] = ! empty( $dt ) ? $dt : $this->node_text( $d );
                break;
            }
        }

        // Full text date extraction
        if ( empty( $card['date_text'] ) ) {
            $text = $this->node_text( $node );
            if ( preg_match( '/(\w+\s+\d{1,2},?\s+\d{4})\s*[-\x{2013}\x{2014}]\s*(\w+\s+\d{1,2},?\s+\d{4})/u', $text, $m ) ) {
                $card['date_text'] = $m[1] . ' - ' . $m[2];
            }
        }

        // v3.6.0 FIX: Extract full dates from "content" paragraph and
        // "Published online" line when the structured dates div only has years.
        $full_text = $this->node_text( $node );

        // Look for "YEAR - YEAR" in dates div → store as year_birth / year_death
        if ( ! empty( $card['date_text'] ) && preg_match( '/^(\d{4})\s*[-\x{2013}\x{2014}~]\s*(\d{4})$/u', trim( $card['date_text'] ), $ym ) ) {
            $card['year_birth'] = $ym[1];
            $card['year_death'] = $ym[2];
            $card['date_text']  = ''; // Clear so normalize() doesn't try to parse it
        } elseif ( ! empty( $card['date_text'] ) && preg_match( '/^\d{4}$/', trim( $card['date_text'] ) ) ) {
            // Single year — might be birth year
            $card['year_birth'] = trim( $card['date_text'] );
            $card['date_text']  = '';
        }

        // Try to extract full "Month Day, Year - Month Day, Year" from content paragraph
        if ( empty( $card['date_text'] ) && preg_match( '/(\b(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s+\d{4})\s*[-\x{2013}\x{2014}~]\s*(\b(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s+\d{4})/u', $full_text, $dm ) ) {
            $card['date_text'] = $dm[1] . ' - ' . $dm[2];
        }

        // v3.12.0 FIX (Goal A): Extract death date from common obituary phrases
        // ALWAYS — not just when date_text is empty. The textual death date
        // (e.g., "passed away on January 6, 2026") is more reliable than the
        // structured dates div or published_date, which may reflect the posting
        // date rather than the actual date of death.
        //
        // Previous behavior (v3.10.2): only ran when date_text was empty,
        // so records with a date_text/published_date would never get the
        // more accurate death date extracted from the obituary body.
        //
        // Example: Paul Andrew Smith — yorkregion.com shows "Published
        // online January 10, 2026" but the obit body says "passed away on
        // January 6, 2026". The correct date_of_death is 2026-01-06.
        $month_pattern = '(?:January|February|March|April|May|June|July|August|September|October|November|December)';
        $death_phrases = array(
            // "passed away/peacefully/suddenly [words] Month Day, Year"
            '/(?:passed\s+away|passed\s+peacefully|passed\s+suddenly|peacefully\s+passed)(?:\s+\w+){0,3}?\s+(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/iu',
            // "entered into rest [on] Month Day, Year"
            '/entered\s+into\s+rest(?:\s+on)?\s+(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/iu',
            // "died [peacefully|suddenly] [on] Month Day, Year"
            '/died(?:\s+\w+){0,2}?\s+(?:on\s+)?(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/iu',
            // "went to be with the Lord on Month Day, Year" / "called home on ..."
            '/(?:went\s+to\s+be\s+with|called\s+home)(?:\s+\w+){0,4}?\s+(?:on\s+)?(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/iu',
        );
        foreach ( $death_phrases as $pattern ) {
            if ( preg_match( $pattern, $full_text, $death_m ) ) {
                $card['death_date_from_text'] = trim( $death_m[1] );
                break;
            }
        }

        // If no extracted death date from text, try the Published line as last resort.
        // v3.12.0: Removed the `empty( $card['date_text'] )` gate so published_date
        // is always captured as a fallback (normalize() only uses it if
        // death_date_from_text and dates['death'] are both empty).
        if ( empty( $card['death_date_from_text'] ) && preg_match( '/Published\s+online\s+.*?(\b(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s+\d{4})\b/i', $full_text, $pm ) ) {
            $card['published_date'] = $pm[1];
        }

        // v3.9.0: Intentionally do NOT extract image_url for Remembering.ca / Postmedia listings.
        // These are paid obituary images owned by families/newspapers; hotlinking creates
        // rights and bandwidth risks. The normalize() method already sets image_url to ''.

        // Location
        $loc_node = $xpath->query( './/*[contains(@class,"location") or contains(@class,"city")]', $node )->item( 0 );
        if ( $loc_node ) {
            $card['location'] = $this->node_text( $loc_node );
        }

        // Funeral home
        $fh_node = $xpath->query( './/*[contains(@class,"funeral") or contains(@class,"provider")]', $node )->item( 0 );
        if ( $fh_node ) {
            $card['funeral_home'] = $this->node_text( $fh_node );
        }

        // Description
        $desc_node = $xpath->query( './/p | .//*[contains(@class,"excerpt")]', $node )->item( 0 );
        if ( $desc_node && strlen( trim( $desc_node->textContent ) ) > 15 ) {
            $card['description'] = $this->node_text( $desc_node );
        }

        return $card;
    }

    /**
     * No detail page fetch — facts only from listings.
     */
    public function fetch_detail( $detail_url, $card, $source ) {
        return null;
    }

    public function normalize( $card, $source ) {
        $dates = $this->parse_date_range( isset( $card['date_text'] ) ? $card['date_text'] : '' );

        $name          = isset( $card['name'] ) ? sanitize_text_field( $card['name'] ) : '';
        $date_of_death = ! empty( $dates['death'] ) ? $dates['death'] : '';
        $date_of_birth = ! empty( $dates['birth'] ) ? $dates['birth'] : '';
        $funeral_home  = isset( $card['funeral_home'] ) ? sanitize_text_field( $card['funeral_home'] ) : '';
        $location      = isset( $card['location'] ) ? $card['location'] : '';
        $description   = isset( $card['description'] ) ? wp_strip_all_tags( $card['description'] ) : '';

        // v3.12.0 FIX (Goal A): Death-date priority — prefer the textual death
        // date extracted from the obituary body over ALL other sources.
        //
        // Priority order (highest → lowest):
        //   1. death_date_from_text  — explicit phrase like "passed away on Jan 6, 2026"
        //   2. dates['death']        — from structured date range ("Jan 1, 1940 - Jan 10, 2026")
        //   3. published_date        — "Published online January 10, 2026" (fallback)
        //
        // Previous behavior (v3.10.2): death_date_from_text was only used when
        // dates['death'] was empty. This caused yorkregion.com records like
        // Paul Andrew Smith to store 2026-01-10 (published date) instead of
        // 2026-01-06 (actual death date from obituary text).
        if ( ! empty( $card['death_date_from_text'] ) ) {
            $text_death = $this->normalize_date( $card['death_date_from_text'] );
            if ( ! empty( $text_death ) ) {
                $date_of_death = $text_death;
            }
        }

        // Fall back to published_date as an approximate death date
        // (obituaries are typically published within days of death).
        if ( empty( $date_of_death ) && ! empty( $card['published_date'] ) ) {
            $date_of_death = $this->normalize_date( $card['published_date'] );
        }

        // v3.10.2: REMOVED Jan 1 fabrication for year-only dates.
        // Previously (v3.6.0): $date_of_death = $card['year_death'] . '-01-01';
        // If no trustworthy death date exists after all extraction attempts,
        // date_of_death remains empty. The collector's validation gate
        // (class-source-collector.php line 224) will skip ingest:
        //   if ( empty( $record['date_of_death'] ) ) { continue; }
        // This is correct: we do not ingest records we cannot date reliably.
        //
        // v3.10.2: Do NOT fabricate birth dates either. date_of_birth is
        // DATE DEFAULT NULL, so empty is safe — no fabrication needed.

        // Truncate to facts-only length
        if ( strlen( $description ) > 200 ) {
            $description = substr( $description, 0, 197 ) . '...';
        }

        $age = 0;
        if ( ! empty( $card['age'] ) ) {
            $age = intval( $card['age'] );
        } elseif ( ! empty( $description ) ) {
            $age = $this->extract_age_from_text( $description );
        }
        if ( 0 === $age && ! empty( $date_of_birth ) && ! empty( $date_of_death ) ) {
            $age = $this->calculate_age( $date_of_birth, $date_of_death );
        }
        // v3.10.2: Compute age from year_birth/year_death when full dates
        // are not available (no longer relies on fabricated -01-01 dates).
        if ( 0 === $age && ! empty( $card['year_birth'] ) && ! empty( $card['year_death'] ) ) {
            $year_age = intval( $card['year_death'] ) - intval( $card['year_birth'] );
            if ( $year_age > 0 && $year_age <= 130 ) {
                $age = $year_age;
            }
        }

        // v3.7.0: Fall back to the source registry's city when the card has no location.
        // Many yorkregion.com cards don't include a location in the HTML.
        if ( empty( $location ) && ! empty( $source['city'] ) ) {
            $location = $source['city'];
        }

        $city_normalized = $this->normalize_city( $location );

        $record = array(
            'name'             => $name,
            'date_of_birth'    => $date_of_birth,
            'date_of_death'    => $date_of_death,
            'age'              => $age,
            'funeral_home'     => $funeral_home,
            'location'         => sanitize_text_field( $location ),
            'image_url'        => '', // Do NOT hotlink newspaper images
            'description'      => $description,
            'source_url'       => isset( $card['detail_url'] ) ? esc_url_raw( $card['detail_url'] ) : '',
            'source_domain'    => $this->extract_domain( $source['base_url'] ),
            'source_type'      => $this->get_type(),
            'city_normalized'  => $city_normalized,
            'provenance_hash'  => '',
        );

        $record['provenance_hash'] = $this->generate_provenance_hash( $record );
        return $record;
    }

    private function parse_date_range( $text ) {
        $result = array( 'birth' => '', 'death' => '' );
        if ( empty( $text ) ) {
            return $result;
        }

        $separators = array( ' - ', ' – ', ' — ', ' to ', '~' );
        foreach ( $separators as $sep ) {
            if ( false !== strpos( $text, $sep ) ) {
                $parts = explode( $sep, $text, 2 );
                $result['birth'] = $this->normalize_date( trim( $parts[0] ) );
                $result['death'] = $this->normalize_date( trim( $parts[1] ) );
                return $result;
            }
        }

        $result['death'] = $this->normalize_date( $text );
        return $result;
    }
}
