<?php
/**
 * FrontRunner / Book of Memories Adapter
 *
 * Handles funeral home websites built on the FrontRunner Professional platform.
 * These sites use a consistent HTML structure with obituary listing pages
 * that follow predictable patterns.
 *
 * Covers: Roadhouse & Rose, Forrest & Taylor, Thompson Funeral Home,
 *         and hundreds of other Ontario funeral homes using FrontRunner.
 *
 * Pattern:
 *   /obituaries → paginated listing with cards
 *   Each card links to /obituaries/[slug] or /book-of-memories/[id]
 *
 * @package Ontario_Obituaries
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ontario_Obituaries_Adapter_FrontRunner extends Ontario_Obituaries_Source_Adapter_Base {

    public function get_type() {
        return 'frontrunner';
    }

    public function get_label() {
        return 'FrontRunner / Book of Memories';
    }

    public function discover_listing_urls( $source, $max_age = 7 ) {
        $urls      = array( $source['base_url'] );
        $max_pages = isset( $source['max_pages_per_run'] ) ? intval( $source['max_pages_per_run'] ) : 3;

        // FrontRunner typically paginates with /page/N or ?page=N
        for ( $p = 2; $p <= $max_pages; $p++ ) {
            $base = rtrim( $source['base_url'], '/' );
            $urls[] = $base . '/page/' . $p;
        }

        return $urls;
    }

    public function fetch_listing( $url, $source ) {
        return $this->http_get( $url );
    }

    public function extract_obit_cards( $html, $source ) {
        $cards = array();
        $doc   = $this->parse_html( $html );
        $xpath = new DOMXPath( $doc );

        // FrontRunner patterns: obituary cards in various container classes
        $selectors = array(
            '//div[contains(@class,"obituary-item")]',
            '//div[contains(@class,"obit-listing")]',
            '//article[contains(@class,"obituary")]',
            '//div[contains(@class,"tribute-item")]',
            '//li[contains(@class,"obituary")]',
            // Fallback: any link that looks like an obituary detail
            '//div[contains(@class,"entry")]//a[contains(@href,"obituar")]/..',
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
            $card = $this->extract_card_from_node( $xpath, $node, $source );
            if ( ! empty( $card['name'] ) ) {
                $cards[] = $card;
            }
        }

        return $cards;
    }

    /**
     * Extract data from a single obituary card node.
     *
     * @param DOMXPath $xpath  XPath context.
     * @param DOMNode  $node   The card node.
     * @param array    $source Source row.
     * @return array Card data.
     */
    private function extract_card_from_node( $xpath, $node, $source ) {
        $card = array();

        // Name — usually in h2, h3, h4, or a strong/span with name class
        $name_queries = array(
            './/h2/a', './/h3/a', './/h4/a',
            './/h2', './/h3', './/h4',
            './/*[contains(@class,"name")]',
            './/a[contains(@class,"obit")]',
            './/strong',
        );
        foreach ( $name_queries as $q ) {
            $n = $xpath->query( $q, $node )->item( 0 );
            if ( $n && ! empty( trim( $n->textContent ) ) ) {
                $card['name'] = $this->node_text( $n );
                // Also grab the link
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

        // Dates — look for date-like text
        $date_queries = array(
            './/*[contains(@class,"date")]',
            './/time',
            './/span[contains(@class,"born")] | .//span[contains(@class,"died")]',
        );
        foreach ( $date_queries as $q ) {
            $d = $xpath->query( $q, $node )->item( 0 );
            if ( $d ) {
                $card['date_text'] = $this->node_text( $d );
                break;
            }
        }

        // If no structured date, try to extract from the full card text
        if ( empty( $card['date_text'] ) ) {
            $full_text = $this->node_text( $node );
            // Look for date-range patterns
            if ( preg_match( '/(\w+\s+\d{1,2},?\s+\d{4})\s*[-–—]\s*(\w+\s+\d{1,2},?\s+\d{4})/', $full_text, $m ) ) {
                $card['date_text'] = $m[1] . ' - ' . $m[2];
            } elseif ( preg_match( '/(\d{4})\s*[-–—]\s*(\d{4})/', $full_text, $m ) ) {
                $card['date_text'] = $m[0];
            }
        }

        // Image
        $img = $xpath->query( './/img', $node )->item( 0 );
        if ( $img ) {
            $src = $this->node_attr( $img, 'src' );
            // Skip placeholder/generic images
            if ( ! preg_match( '/(placeholder|default|generic|no-?photo|avatar)/i', $src ) ) {
                $card['image_url'] = $this->resolve_url( $src, $source['base_url'] );
            }
        }

        // Description excerpt
        $desc_queries = array(
            './/*[contains(@class,"excerpt")]',
            './/*[contains(@class,"summary")]',
            './/*[contains(@class,"description")]',
            './/p',
        );
        foreach ( $desc_queries as $q ) {
            $d = $xpath->query( $q, $node )->item( 0 );
            if ( $d && strlen( trim( $d->textContent ) ) > 20 ) {
                $card['description'] = $this->node_text( $d );
                break;
            }
        }

        // Funeral home — from source name (FrontRunner sites are typically one funeral home)
        $card['funeral_home'] = isset( $source['name'] ) ? $source['name'] : '';
        $card['location']     = isset( $source['city'] ) ? $source['city'] : '';

        return $card;
    }

    public function fetch_detail( $detail_url, $card, $source ) {
        if ( empty( $detail_url ) ) {
            return null;
        }

        $html = $this->http_get( $detail_url );
        if ( is_wp_error( $html ) ) {
            return null;
        }

        $doc   = $this->parse_html( $html );
        $xpath = new DOMXPath( $doc );
        $enriched = array();

        // Try to get a better description from the detail page
        $desc_selectors = array(
            '//div[contains(@class,"obituary-text")]',
            '//div[contains(@class,"obit-content")]',
            '//div[contains(@class,"tribute-text")]',
            '//div[contains(@class,"entry-content")]',
            '//article//div[contains(@class,"content")]',
        );

        foreach ( $desc_selectors as $sel ) {
            $n = $xpath->query( $sel )->item( 0 );
            if ( $n && strlen( trim( $n->textContent ) ) > 50 ) {
                // We only store a facts-based summary, NOT the full text
                $full_text = $this->node_text( $n );
                $enriched['description'] = $this->create_factual_summary( $full_text, $card );
                break;
            }
        }

        // Try to get a better image from detail page
        $img_selectors = array(
            '//div[contains(@class,"obituary")]//img',
            '//div[contains(@class,"tribute")]//img',
            '//img[contains(@class,"portrait")]',
            '//img[contains(@class,"photo")]',
        );

        foreach ( $img_selectors as $sel ) {
            $img = $xpath->query( $sel )->item( 0 );
            if ( $img ) {
                $src = $this->node_attr( $img, 'src' );
                if ( ! preg_match( '/(placeholder|default|generic|no-?photo|avatar|logo)/i', $src ) ) {
                    $enriched['image_url'] = $this->resolve_url( $src, $source['base_url'] );
                    break;
                }
            }
        }

        // Extract structured date info if available
        $date_sel = '//meta[@property="article:published_time"] | //time[@datetime]';
        $date_node = $xpath->query( $date_sel )->item( 0 );
        if ( $date_node ) {
            $dt = $this->node_attr( $date_node, 'content' ) ?: $this->node_attr( $date_node, 'datetime' );
            if ( $dt ) {
                $enriched['published_date'] = $dt;
            }
        }

        return ! empty( $enriched ) ? $enriched : null;
    }

    /**
     * Create a factual summary from obituary text.
     *
     * Extracts key facts (survived by, location, service details) without
     * reproducing the original copyrighted prose.
     *
     * @param string $full_text Full obituary text.
     * @param array  $card      Card data.
     * @return string Factual summary (max ~200 chars).
     */
    private function create_factual_summary( $full_text, $card ) {
        $facts = array();

        // Extract location mentions
        if ( preg_match( '/(?:of|in|from)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*),?\s*(?:Ontario|ON)/i', $full_text, $m ) ) {
            $facts[] = 'of ' . trim( $m[1] ) . ', Ontario';
        }

        // Extract "passed away" context
        if ( preg_match( '/passed\s+away\s+(peacefully|suddenly|unexpectedly)/i', $full_text, $m ) ) {
            $facts[] = 'passed away ' . strtolower( $m[1] );
        }

        // Extract age
        $age = $this->extract_age_from_text( $full_text );
        if ( $age > 0 ) {
            $facts[] = 'aged ' . $age;
        }

        // Combine into a factual summary
        $name = isset( $card['name'] ) ? $card['name'] : '';
        if ( ! empty( $facts ) ) {
            return $name . ', ' . implode( ', ', $facts ) . '.';
        }

        // Fallback: first sentence, truncated
        $first_sentence = strtok( $full_text, '.' );
        if ( strlen( $first_sentence ) > 200 ) {
            $first_sentence = substr( $first_sentence, 0, 197 ) . '...';
        }
        return $first_sentence . '.';
    }

    public function normalize( $card, $source ) {
        $dates = $this->parse_date_range( isset( $card['date_text'] ) ? $card['date_text'] : '' );

        $name          = isset( $card['name'] ) ? sanitize_text_field( $card['name'] ) : '';
        $date_of_death = ! empty( $dates['death'] ) ? $dates['death'] : '';
        $date_of_birth = ! empty( $dates['birth'] ) ? $dates['birth'] : '';
        $funeral_home  = isset( $card['funeral_home'] ) ? sanitize_text_field( $card['funeral_home'] ) : '';
        $location      = isset( $card['location'] ) ? $card['location'] : ( isset( $source['city'] ) ? $source['city'] : '' );
        $description   = isset( $card['description'] ) ? wp_strip_all_tags( $card['description'] ) : '';

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
            'image_url'        => isset( $card['image_url'] ) ? esc_url_raw( $card['image_url'] ) : '',
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

    /**
     * Parse date range from text.
     *
     * @param string $text Date text.
     * @return array { birth, death } Y-m-d.
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
