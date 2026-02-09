<?php
/**
 * Dignity Memorial / SCI Adapter
 *
 * Handles the Dignity Memorial network (dignitymemorial.com) — the
 * largest funeral services provider in North America (Service Corporation
 * International). Operates hundreds of funeral homes across Ontario.
 *
 * Pattern:
 *   /obituaries/{city}-{province} → paginated listing
 *   /obituaries/{slug}/{id} → individual obituary
 *
 * @package Ontario_Obituaries
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ontario_Obituaries_Adapter_Dignity_Memorial extends Ontario_Obituaries_Source_Adapter_Base {

    public function get_type() {
        return 'dignity_memorial';
    }

    public function get_label() {
        return 'Dignity Memorial / SCI Network';
    }

    public function discover_listing_urls( $source, $max_age = 7 ) {
        $urls      = array( $source['base_url'] );
        $max_pages = isset( $source['max_pages_per_run'] ) ? intval( $source['max_pages_per_run'] ) : 5;

        // Dignity Memorial paginates via ?page=N
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

        // Dignity Memorial card patterns
        $selectors = array(
            '//div[contains(@class,"obit-card")]',
            '//div[contains(@class,"obituary-card")]',
            '//article[contains(@class,"obit")]',
            '//div[contains(@class,"search-results")]//div[contains(@class,"result")]',
            '//ul[contains(@class,"obituaries")]//li',
            // Fallback: links to individual obituaries
            '//a[contains(@href,"/obituaries/") and not(contains(@href,"/search"))]/..',
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

    private function extract_card( $xpath, $node, $source ) {
        $card = array();

        // Name
        $name_queries = array(
            './/h2/a', './/h3/a', './/h4/a',
            './/h2', './/h3', './/h4',
            './/*[contains(@class,"name")]',
            './/a[contains(@class,"obit")]',
        );
        foreach ( $name_queries as $q ) {
            $n = $xpath->query( $q, $node )->item( 0 );
            if ( $n && ! empty( trim( $n->textContent ) ) ) {
                $card['name'] = $this->node_text( $n );
                if ( 'a' === $n->nodeName ) {
                    $card['detail_url'] = $this->resolve_url( $this->node_attr( $n, 'href' ), 'https://www.dignitymemorial.com' );
                }
                break;
            }
        }

        if ( empty( $card['detail_url'] ) ) {
            $link = $xpath->query( './/a[contains(@href,"/obituaries/")]', $node )->item( 0 );
            if ( $link ) {
                $card['detail_url'] = $this->resolve_url( $this->node_attr( $link, 'href' ), 'https://www.dignitymemorial.com' );
            }
        }

        // Dates
        $date_queries = array(
            './/*[contains(@class,"date")]',
            './/*[contains(@class,"life-span")]',
            './/time',
        );
        foreach ( $date_queries as $q ) {
            $d = $xpath->query( $q, $node )->item( 0 );
            if ( $d ) {
                $card['date_text'] = $this->node_text( $d );
                break;
            }
        }

        if ( empty( $card['date_text'] ) ) {
            $text = $this->node_text( $node );
            if ( preg_match( '/(\w+\s+\d{1,2},?\s+\d{4})\s*[-–—]\s*(\w+\s+\d{1,2},?\s+\d{4})/', $text, $m ) ) {
                $card['date_text'] = $m[1] . ' - ' . $m[2];
            }
        }

        // Image
        $img = $xpath->query( './/img', $node )->item( 0 );
        if ( $img ) {
            $src = $this->node_attr( $img, 'data-src' ) ?: $this->node_attr( $img, 'src' );
            if ( $src && ! preg_match( '/(placeholder|default|generic|no-?photo|avatar|logo|spacer)/i', $src ) ) {
                $card['image_url'] = $this->resolve_url( $src, 'https://www.dignitymemorial.com' );
            }
        }

        // Location
        $loc_node = $xpath->query( './/*[contains(@class,"location") or contains(@class,"city")]', $node )->item( 0 );
        if ( $loc_node ) {
            $card['location'] = $this->node_text( $loc_node );
        } elseif ( ! empty( $source['city'] ) ) {
            $card['location'] = $source['city'];
        }

        // Funeral home
        $fh_node = $xpath->query( './/*[contains(@class,"funeral") or contains(@class,"provider") or contains(@class,"location-name")]', $node )->item( 0 );
        if ( $fh_node ) {
            $card['funeral_home'] = $this->node_text( $fh_node );
        }

        // Description
        $desc_node = $xpath->query( './/p | .//*[contains(@class,"excerpt") or contains(@class,"summary")]', $node )->item( 0 );
        if ( $desc_node && strlen( trim( $desc_node->textContent ) ) > 15 ) {
            $card['description'] = $this->node_text( $desc_node );
        }

        return $card;
    }

    /**
     * No detail page fetch — rights-aware, facts only.
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
        $location      = isset( $card['location'] ) ? $card['location'] : ( isset( $source['city'] ) ? $source['city'] : '' );
        $description   = isset( $card['description'] ) ? wp_strip_all_tags( $card['description'] ) : '';

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
            'image_url'        => '', // Do NOT hotlink Dignity Memorial images
            'description'      => $description,
            'source_url'       => isset( $card['detail_url'] ) ? esc_url_raw( $card['detail_url'] ) : '',
            'source_domain'    => 'dignitymemorial.com',
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
