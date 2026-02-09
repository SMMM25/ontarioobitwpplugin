<?php
/**
 * Legacy.com Adapter
 *
 * Handles the Legacy.com obituary aggregator — the world's largest obituary
 * platform, covering 95%+ of North American obituaries through partnerships
 * with newspapers and funeral homes.
 *
 * Legacy.com organizes obituaries by newspaper partner:
 *   /ca/obituaries/yorkregion/today
 *   /ca/obituaries/thespec/today
 *   /ca/obituaries/ottawacitizen/today
 *
 * Important: We only extract factual data (name, dates, city, funeral home).
 * Full obituary text is NOT copied — rights-aware publishing.
 *
 * @package Ontario_Obituaries
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ontario_Obituaries_Adapter_Legacy_Com extends Ontario_Obituaries_Source_Adapter_Base {

    public function get_type() {
        return 'legacy_com';
    }

    public function get_label() {
        return 'Legacy.com (Newspaper Obituary Network)';
    }

    public function discover_listing_urls( $source, $max_age = 7 ) {
        $urls     = array();
        $base_url = rtrim( $source['base_url'], '/' );
        $config   = isset( $source['config_parsed'] ) ? $source['config_parsed'] : array();

        // Legacy.com uses date-based listing pages
        // /ca/obituaries/{publication}/today
        // /ca/obituaries/{publication}/browse?date=YYYY-MM-DD
        $max_days = min( $max_age, 7 ); // Don't go back more than 7 days on aggregators

        // Today's page first
        $urls[] = $base_url;

        // Then browse by date for recent days
        if ( strpos( $base_url, '/today' ) !== false ) {
            $browse_base = str_replace( '/today', '/browse', $base_url );
            for ( $d = 1; $d <= $max_days; $d++ ) {
                $date   = gmdate( 'Y-m-d', strtotime( "-{$d} days" ) );
                $urls[] = add_query_arg( 'date', $date, $browse_base );
            }
        }

        $max_pages = isset( $source['max_pages_per_run'] ) ? intval( $source['max_pages_per_run'] ) : 5;
        return array_slice( $urls, 0, $max_pages );
    }

    public function fetch_listing( $url, $source ) {
        // Legacy.com may serve different content based on headers
        return $this->http_get( $url, array(
            'Accept-Language' => 'en-CA,en;q=0.9',
        ) );
    }

    public function extract_obit_cards( $html, $source ) {
        $cards = array();
        $doc   = $this->parse_html( $html );
        $xpath = new DOMXPath( $doc );

        // Legacy.com card selectors — their HTML structure
        $selectors = array(
            '//div[contains(@class,"obit-card")]',
            '//div[contains(@class,"PersonCard")]',
            '//div[contains(@class,"Obituary")]//div[contains(@class,"Card")]',
            '//a[contains(@class,"obit-link")]/parent::*',
            '//div[contains(@data-testid,"obituary")]',
            // Fallback
            '//div[contains(@class,"results")]//div[contains(@class,"item")]',
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
     * Extract from a single Legacy.com card node.
     */
    private function extract_card( $xpath, $node, $source ) {
        $card = array();

        // Name — Legacy uses strong name links
        $name_queries = array(
            './/h3/a', './/h2/a',
            './/a[contains(@class,"obit-name")]',
            './/a[contains(@class,"name")]',
            './/*[contains(@class,"PersonName")]',
            './/strong/a',
            './/h3', './/h2',
        );
        foreach ( $name_queries as $q ) {
            $n = $xpath->query( $q, $node )->item( 0 );
            if ( $n && ! empty( trim( $n->textContent ) ) ) {
                $card['name'] = $this->node_text( $n );
                if ( 'a' === $n->nodeName ) {
                    $card['detail_url'] = $this->resolve_url( $this->node_attr( $n, 'href' ), 'https://www.legacy.com' );
                }
                break;
            }
        }

        // Detail URL fallback
        if ( empty( $card['detail_url'] ) ) {
            $link = $xpath->query( './/a[contains(@href,"/obituar")]', $node )->item( 0 );
            if ( $link ) {
                $card['detail_url'] = $this->resolve_url( $this->node_attr( $link, 'href' ), 'https://www.legacy.com' );
            }
        }

        // Dates — Legacy typically shows "Birth Date – Death Date" or just death date
        $date_queries = array(
            './/*[contains(@class,"date")]',
            './/*[contains(@class,"Date")]',
            './/time',
        );
        foreach ( $date_queries as $q ) {
            $d = $xpath->query( $q, $node )->item( 0 );
            if ( $d ) {
                $card['date_text'] = $this->node_text( $d );
                break;
            }
        }

        // Extract date from text
        if ( empty( $card['date_text'] ) ) {
            $text = $this->node_text( $node );
            if ( preg_match( '/(\w+\s+\d{1,2},?\s+\d{4})\s*[-–—]\s*(\w+\s+\d{1,2},?\s+\d{4})/', $text, $m ) ) {
                $card['date_text'] = $m[1] . ' - ' . $m[2];
            } elseif ( preg_match( '/(\d{4})\s*[-–—]\s*(\d{4})/', $text, $m ) ) {
                $card['date_text'] = $m[0];
            }
        }

        // Image
        $img = $xpath->query( './/img', $node )->item( 0 );
        if ( $img ) {
            $src = $this->node_attr( $img, 'data-src' ) ?: $this->node_attr( $img, 'src' );
            if ( $src && ! preg_match( '/(placeholder|default|generic|no-?photo|avatar|logo|spacer|1x1)/i', $src ) ) {
                $card['image_url'] = $this->resolve_url( $src, 'https://www.legacy.com' );
            }
        }

        // Location — Legacy often includes city/state
        $loc_queries = array(
            './/*[contains(@class,"location")]',
            './/*[contains(@class,"Location")]',
            './/*[contains(@class,"city")]',
        );
        foreach ( $loc_queries as $q ) {
            $l = $xpath->query( $q, $node )->item( 0 );
            if ( $l ) {
                $card['location'] = $this->node_text( $l );
                break;
            }
        }

        // Funeral home name from Legacy card
        $fh_queries = array(
            './/*[contains(@class,"funeral")]',
            './/*[contains(@class,"Funeral")]',
            './/*[contains(@class,"provider")]',
        );
        foreach ( $fh_queries as $q ) {
            $f = $xpath->query( $q, $node )->item( 0 );
            if ( $f ) {
                $card['funeral_home'] = $this->node_text( $f );
                break;
            }
        }

        // Brief description / excerpt
        $desc_queries = array(
            './/*[contains(@class,"excerpt")]',
            './/*[contains(@class,"snippet")]',
            './/*[contains(@class,"summary")]',
            './/p',
        );
        foreach ( $desc_queries as $q ) {
            $d = $xpath->query( $q, $node )->item( 0 );
            if ( $d && strlen( trim( $d->textContent ) ) > 15 ) {
                $card['description'] = $this->node_text( $d );
                break;
            }
        }

        return $card;
    }

    /**
     * We do NOT fetch detail pages from Legacy.com.
     * Extracting full obituary text would be copyright infringement.
     * We only use listing-level facts.
     */
    public function fetch_detail( $detail_url, $card, $source ) {
        // Intentionally returns null — facts only from listing page
        return null;
    }

    public function normalize( $card, $source ) {
        $dates = $this->parse_date_range( isset( $card['date_text'] ) ? $card['date_text'] : '' );

        $name          = isset( $card['name'] ) ? sanitize_text_field( $card['name'] ) : '';
        $date_of_death = ! empty( $dates['death'] ) ? $dates['death'] : '';
        $date_of_birth = ! empty( $dates['birth'] ) ? $dates['birth'] : '';
        $funeral_home  = isset( $card['funeral_home'] ) ? sanitize_text_field( $card['funeral_home'] ) : '';
        $location      = isset( $card['location'] ) ? $card['location'] : ( isset( $source['city'] ) ? $source['city'] : '' );
        $description   = isset( $card['description'] ) ? wp_strip_all_tags( $card['description'] ) : '';

        // Limit description to a factual snippet only
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

        $city_normalized = $this->normalize_city( $location );

        $record = array(
            'name'             => $name,
            'date_of_birth'    => $date_of_birth,
            'date_of_death'    => $date_of_death,
            'age'              => $age,
            'funeral_home'     => $funeral_home,
            'location'         => sanitize_text_field( $location ),
            'image_url'        => '', // Do NOT hotlink Legacy.com images
            'description'      => $description,
            'source_url'       => isset( $card['detail_url'] ) ? esc_url_raw( $card['detail_url'] ) : '',
            'source_domain'    => 'legacy.com',
            'source_type'      => $this->get_type(),
            'city_normalized'  => $city_normalized,
            'provenance_hash'  => '',
        );

        $record['provenance_hash'] = $this->generate_provenance_hash( $record );
        return $record;
    }

    /**
     * Parse date range text.
     */
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
