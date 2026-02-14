<?php
/**
 * Tribute Archive / Tribute Technology Adapter
 *
 * Handles funeral home websites built on the Tribute Technology platform
 * (formerly known as TributeArchive). These sites serve hundreds of
 * Canadian funeral homes with a consistent obituary listing structure.
 *
 * Pattern:
 *   /tributes/recent → paginated listing with JS-rendered or HTML cards
 *   Each card links to /tributes/[slug]
 *
 * @package Ontario_Obituaries
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ontario_Obituaries_Adapter_Tribute_Archive extends Ontario_Obituaries_Source_Adapter_Base {

    public function get_type() {
        return 'tribute_archive';
    }

    public function get_label() {
        return 'Tribute Technology / Tribute Archive';
    }

    public function discover_listing_urls( $source, $max_age = 7 ) {
        $urls      = array( $source['base_url'] );
        $max_pages = isset( $source['max_pages_per_run'] ) ? intval( $source['max_pages_per_run'] ) : 5;

        // Tribute Tech sites typically paginate via ?page=N or /page/N
        $config = isset( $source['config_parsed'] ) ? $source['config_parsed'] : array();
        $pagination_style = isset( $config['pagination_style'] ) ? $config['pagination_style'] : 'query';

        for ( $p = 2; $p <= $max_pages; $p++ ) {
            if ( 'path' === $pagination_style ) {
                $urls[] = rtrim( $source['base_url'], '/' ) . '/page/' . $p;
            } else {
                $urls[] = add_query_arg( 'page', $p, $source['base_url'] );
            }
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

        // Tribute Technology uses various wrapper patterns
        $selectors = array(
            '//div[contains(@class,"tribute-listing")]//div[contains(@class,"tribute")]',
            '//div[contains(@class,"obituary-listing")]//div[contains(@class,"obituary")]',
            '//ul[contains(@class,"tributes")]//li',
            '//div[contains(@class,"obit-list")]//article',
            '//div[contains(@class,"recent-tributes")]//div[contains(@class,"tribute-item")]',
            // Fallback: any container with tribute/obituary links
            '//div[contains(@class,"content")]//div[.//a[contains(@href,"tribute") or contains(@href,"obituar")]]',
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
     * Extract data from a single card node.
     */
    private function extract_card( $xpath, $node, $source ) {
        $card = array();

        // Name
        $name_queries = array(
            './/h2/a', './/h3/a', './/h4/a',
            './/h2', './/h3', './/h4',
            './/*[contains(@class,"tribute-name")]',
            './/*[contains(@class,"name")]//a',
            './/a[contains(@class,"tribute")]',
        );
        foreach ( $name_queries as $q ) {
            $n = $xpath->query( $q, $node )->item( 0 );
            if ( $n && ! empty( trim( $n->textContent ) ) ) {
                $card['name'] = $this->node_text( $n );
                if ( 'a' === $n->nodeName || $n->getElementsByTagName( 'a' )->length > 0 ) {
                    $link = ( 'a' === $n->nodeName ) ? $n : $n->getElementsByTagName( 'a' )->item( 0 );
                    $card['detail_url'] = $this->resolve_url( $this->node_attr( $link, 'href' ), $source['base_url'] );
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

        // Dates
        $date_queries = array(
            './/*[contains(@class,"date")]',
            './/*[contains(@class,"tribute-dates")]',
            './/time',
            './/span[contains(text(),"-") or contains(text(),"–")]',
        );
        foreach ( $date_queries as $q ) {
            $d = $xpath->query( $q, $node )->item( 0 );
            if ( $d ) {
                $card['date_text'] = $this->node_text( $d );
                break;
            }
        }

        // Date from full text fallback
        if ( empty( $card['date_text'] ) ) {
            $full_text = $this->node_text( $node );
            if ( preg_match( '/(\w+\s+\d{1,2},?\s+\d{4})\s*[-–—]\s*(\w+\s+\d{1,2},?\s+\d{4})/', $full_text, $m ) ) {
                $card['date_text'] = $m[1] . ' - ' . $m[2];
            }
        }

        // Image
        $img = $xpath->query( './/img', $node )->item( 0 );
        if ( $img ) {
            $src = $this->node_attr( $img, 'data-src' ) ?: $this->node_attr( $img, 'src' );
            if ( $src && ! preg_match( '/(placeholder|default|generic|no-?photo|avatar|logo|spacer)/i', $src ) ) {
                $card['image_url'] = $this->resolve_url( $src, $source['base_url'] );
            }
        }

        // Description
        $desc_queries = array(
            './/*[contains(@class,"excerpt")]',
            './/*[contains(@class,"summary")]',
            './/*[contains(@class,"tribute-text")]',
            './/p',
        );
        foreach ( $desc_queries as $q ) {
            $d = $xpath->query( $q, $node )->item( 0 );
            if ( $d && strlen( trim( $d->textContent ) ) > 20 ) {
                $card['description'] = $this->node_text( $d );
                break;
            }
        }

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

        $doc      = $this->parse_html( $html );
        $xpath    = new DOMXPath( $doc );
        $enriched = array();

        // Better description
        $desc_selectors = array(
            '//div[contains(@class,"tribute-text")]',
            '//div[contains(@class,"obituary-text")]',
            '//div[contains(@class,"tribute-content")]',
            '//div[contains(@class,"entry-content")]',
        );

        foreach ( $desc_selectors as $sel ) {
            $n = $xpath->query( $sel )->item( 0 );
            if ( $n && strlen( trim( $n->textContent ) ) > 50 ) {
                $full_text = $this->node_text( $n );
                // v5.0.0: Preserve the FULL obituary text for the AI rewriter.
                // Previously used create_factual_summary() which reduced the text
                // to a single sentence, losing all detail. The Groq rewriter needs
                // the complete text to extract accurate dates, ages, and locations.
                if ( strlen( $full_text ) > 2000 ) {
                    $full_text = substr( $full_text, 0, 1997 ) . '...';
                }
                $enriched['description'] = $full_text;
                break;
            }
        }

        // Better image
        $img_selectors = array(
            '//div[contains(@class,"tribute")]//img[contains(@class,"portrait") or contains(@class,"photo")]',
            '//div[contains(@class,"obituary")]//img',
            '//img[contains(@class,"tribute-image")]',
        );

        foreach ( $img_selectors as $sel ) {
            $img = $xpath->query( $sel )->item( 0 );
            if ( $img ) {
                $src = $this->node_attr( $img, 'src' );
                if ( $src && ! preg_match( '/(placeholder|default|generic|no-?photo|avatar|logo)/i', $src ) ) {
                    $enriched['image_url'] = $this->resolve_url( $src, $source['base_url'] );
                    break;
                }
            }
        }

        return ! empty( $enriched ) ? $enriched : null;
    }

    /**
     * Create a facts-only summary — no copied prose.
     */
    private function create_factual_summary( $full_text, $card ) {
        $facts = array();

        if ( preg_match( '/(?:of|in|from)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*),?\s*(?:Ontario|ON)/i', $full_text, $m ) ) {
            $facts[] = 'of ' . trim( $m[1] ) . ', Ontario';
        }
        if ( preg_match( '/passed\s+away\s+(peacefully|suddenly|unexpectedly)/i', $full_text, $m ) ) {
            $facts[] = 'passed away ' . strtolower( $m[1] );
        }
        $age = $this->extract_age_from_text( $full_text );
        if ( $age > 0 ) {
            $facts[] = 'aged ' . $age;
        }

        $name = isset( $card['name'] ) ? $card['name'] : '';
        if ( ! empty( $facts ) ) {
            return $name . ', ' . implode( ', ', $facts ) . '.';
        }

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
