<?php
/**
 * Generic HTML Adapter
 *
 * Fallback adapter for funeral home websites without a recognized platform.
 * Uses configurable CSS selectors (stored in source config JSON) to extract
 * obituary cards from arbitrary HTML pages.
 *
 * Config keys (in source.config JSON):
 *   listing_selector    — CSS-like XPath for each obituary card on the listing page
 *   name_selector       — XPath relative to card for the deceased's name
 *   date_selector       — XPath for date text (attempts to extract both birth and death)
 *   link_selector       — XPath for the detail link
 *   image_selector      — XPath for the portrait image
 *   description_selector — XPath for the summary text
 *   funeral_home_name   — Static override (many sites are a single funeral home)
 *   pagination_selector — XPath for next-page link
 *
 * @package Ontario_Obituaries
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ontario_Obituaries_Adapter_Generic_HTML extends Ontario_Obituaries_Source_Adapter_Base {

    public function get_type() {
        return 'generic_html';
    }

    public function get_label() {
        return 'Generic HTML (configurable selectors)';
    }

    public function discover_listing_urls( $source, $max_age = 7 ) {
        $urls = array( $source['base_url'] );
        $config = isset( $source['config_parsed'] ) ? $source['config_parsed'] : array();
        $max_pages = isset( $source['max_pages_per_run'] ) ? intval( $source['max_pages_per_run'] ) : 3;

        // If the base URL supports pagination via query param
        if ( ! empty( $config['pagination_param'] ) ) {
            for ( $p = 2; $p <= $max_pages; $p++ ) {
                $urls[] = add_query_arg( $config['pagination_param'], $p, $source['base_url'] );
            }
        }

        return $urls;
    }

    public function fetch_listing( $url, $source ) {
        return $this->http_get( $url );
    }

    public function extract_obit_cards( $html, $source ) {
        $config = isset( $source['config_parsed'] ) ? $source['config_parsed'] : array();
        $cards  = array();

        $doc  = $this->parse_html( $html );
        $xpath = new DOMXPath( $doc );

        // Default selectors — work for many simple obituary pages
        $listing_sel     = isset( $config['listing_selector'] )     ? $config['listing_selector']     : '//div[contains(@class,"obituary")] | //article[contains(@class,"obit")]';
        $name_sel        = isset( $config['name_selector'] )        ? $config['name_selector']        : './/h2 | .//h3 | .//h4 | .//*[contains(@class,"name")]';
        $date_sel        = isset( $config['date_selector'] )        ? $config['date_selector']        : './/*[contains(@class,"date")] | .//time | .//span[contains(@class,"date")]';
        $link_sel        = isset( $config['link_selector'] )        ? $config['link_selector']        : './/a[contains(@href,"obituar") or contains(@href,"memorial") or contains(@href,"tribute")]';
        $image_sel       = isset( $config['image_selector'] )       ? $config['image_selector']       : './/img';
        $desc_sel        = isset( $config['description_selector'] ) ? $config['description_selector'] : './/p | .//*[contains(@class,"excerpt")] | .//*[contains(@class,"summary")]';

        $nodes = $xpath->query( $listing_sel );

        if ( false === $nodes || 0 === $nodes->length ) {
            return $cards;
        }

        foreach ( $nodes as $node ) {
            $card = array();

            // Name
            $name_node = $xpath->query( $name_sel, $node )->item( 0 );
            $card['name'] = $this->node_text( $name_node );

            // Date text
            $date_node = $xpath->query( $date_sel, $node )->item( 0 );
            $card['date_text'] = $this->node_text( $date_node );

            // Detail link
            $link_node = $xpath->query( $link_sel, $node )->item( 0 );
            $href = $link_node ? $this->node_attr( $link_node, 'href' ) : '';
            $card['detail_url'] = $this->resolve_url( $href, $source['base_url'] );

            // Image
            $img_node = $xpath->query( $image_sel, $node )->item( 0 );
            $card['image_url'] = $img_node ? $this->resolve_url( $this->node_attr( $img_node, 'src' ), $source['base_url'] ) : '';

            // Description
            $desc_node = $xpath->query( $desc_sel, $node )->item( 0 );
            $card['description'] = $this->node_text( $desc_node );

            // Static funeral home from config
            $card['funeral_home'] = isset( $config['funeral_home_name'] ) ? $config['funeral_home_name'] : '';

            if ( ! empty( $card['name'] ) ) {
                $cards[] = $card;
            }
        }

        return $cards;
    }

    public function normalize( $card, $source ) {
        $config = isset( $source['config_parsed'] ) ? $source['config_parsed'] : array();

        // Parse dates from the date_text
        $dates = $this->parse_date_range( isset( $card['date_text'] ) ? $card['date_text'] : '' );

        $name          = isset( $card['name'] ) ? sanitize_text_field( $card['name'] ) : '';
        // v3.17.0 FIX: Strip trailing 'Obituary' / 'Obit' suffix from names
        $name = preg_replace( '/\s+Obituary$/i', '', $name );
        $name = preg_replace( '/\s+Obit\.?$/i', '', $name );
        $date_of_death = ! empty( $dates['death'] ) ? $dates['death'] : '';
        $date_of_birth = ! empty( $dates['birth'] ) ? $dates['birth'] : '';
        $funeral_home  = ! empty( $card['funeral_home'] ) ? sanitize_text_field( $card['funeral_home'] ) : ( isset( $source['name'] ) ? $source['name'] : '' );
        $location      = ! empty( $card['location'] ) ? $card['location'] : ( isset( $source['city'] ) ? $source['city'] : '' );
        $description   = isset( $card['description'] ) ? wp_strip_all_tags( $card['description'] ) : '';

        // Try to extract age from text
        $age = 0;
        if ( ! empty( $card['age'] ) ) {
            $age = intval( $card['age'] );
        } elseif ( ! empty( $description ) ) {
            $age = $this->extract_age_from_text( $description );
        }

        // Calculate age from dates if still missing
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
     * Parse a date range string like "March 15, 1945 - January 20, 2026"
     * or "1945 - 2026" or just "January 20, 2026".
     *
     * @param string $text Date text.
     * @return array { birth: string, death: string } Y-m-d dates.
     */
    private function parse_date_range( $text ) {
        $result = array( 'birth' => '', 'death' => '' );

        if ( empty( $text ) ) {
            return $result;
        }

        // Try splitting on common separators
        $separators = array( ' - ', ' – ', ' — ', ' to ', '~' );
        foreach ( $separators as $sep ) {
            if ( false !== strpos( $text, $sep ) ) {
                $parts = explode( $sep, $text, 2 );
                $result['birth'] = $this->normalize_date( trim( $parts[0] ) );
                $result['death'] = $this->normalize_date( trim( $parts[1] ) );
                return $result;
            }
        }

        // Single date — assume death date
        $result['death'] = $this->normalize_date( $text );
        return $result;
    }
}
