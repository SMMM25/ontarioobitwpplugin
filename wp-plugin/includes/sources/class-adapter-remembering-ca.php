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

        // Remembering.ca uses search/browse pages with pagination
        // Typical URL patterns:
        //   /obituaries/obituaries/search
        //   /obituaries/browse?page=2
        for ( $p = 2; $p <= $max_pages; $p++ ) {
            $urls[] = add_query_arg( 'page', $p, $source['base_url'] );
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

        // If still no full date_text, try the Published line for at least a published date
        if ( empty( $card['date_text'] ) && preg_match( '/Published\s+online\s+.*?(\b(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s+\d{4})\b/i', $full_text, $pm ) ) {
            $card['published_date'] = $pm[1];
        }

        // Image
        $img = $xpath->query( './/img', $node )->item( 0 );
        if ( $img ) {
            $src = $this->node_attr( $img, 'data-src' ) ?: $this->node_attr( $img, 'src' );
            if ( $src && ! preg_match( '/(placeholder|default|generic|no-?photo|avatar|logo|spacer)/i', $src ) ) {
                $card['image_url'] = $this->resolve_url( $src, $source['base_url'] );
            }
        }

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

        // v3.6.0 FIX: Use year_death from the dates div when full date unavailable.
        // This stores a best-effort date (January 1 of the death year) rather than
        // today's date, which was the previous broken behaviour.
        if ( empty( $date_of_death ) && ! empty( $card['year_death'] ) ) {
            $date_of_death = $card['year_death'] . '-01-01';
        }
        if ( empty( $date_of_birth ) && ! empty( $card['year_birth'] ) ) {
            $date_of_birth = $card['year_birth'] . '-01-01';
        }

        // v3.6.0: Fall back to published_date as an approximate death date
        // (obituaries are typically published within days of death).
        if ( empty( $date_of_death ) && ! empty( $card['published_date'] ) ) {
            $date_of_death = $this->normalize_date( $card['published_date'] );
        }

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
