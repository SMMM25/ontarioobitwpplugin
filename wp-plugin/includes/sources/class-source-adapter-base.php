<?php
/**
 * Base Source Adapter
 *
 * Shared logic for all source adapters: HTTP fetching, HTML parsing helpers,
 * date normalization, provenance hash generation, city normalization.
 *
 * @package Ontario_Obituaries
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class Ontario_Obituaries_Source_Adapter_Base implements Ontario_Obituaries_Source_Adapter {

    /** @var int HTTP timeout in seconds. */
    protected $timeout = 30;

    /** @var int Maximum retries per request. */
    protected $max_retries = 2;

    /** @var string User-Agent string. */
    protected $user_agent = 'Mozilla/5.0 (compatible; OntarioObituariesBot/3.0; +https://monacomonuments.ca)';

    /* ─────────────────────── HTTP HELPERS ──────────────────────────── */

    /**
     * Perform a GET request with retries and back-off.
     *
     * @param string $url     URL to fetch.
     * @param array  $headers Optional headers.
     * @return string|WP_Error Response body or WP_Error.
     */
    protected function http_get( $url, $headers = array() ) {
        $attempt    = 0;
        $last_error = null;

        $default_headers = array(
            'User-Agent' => $this->user_agent,
            'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        );
        $headers = array_merge( $default_headers, $headers );

        while ( $attempt < $this->max_retries ) {
            $attempt++;

            $response = wp_remote_get( $url, array(
                'timeout'    => $this->timeout,
                'headers'    => $headers,
                'user-agent' => $this->user_agent,
                'sslverify'  => true,
            ) );

            if ( is_wp_error( $response ) ) {
                $last_error = $response;
                ontario_obituaries_log(
                    sprintf( 'HTTP GET failed (attempt %d/%d) %s: %s', $attempt, $this->max_retries, $url, $response->get_error_message() ),
                    'warning'
                );
                if ( $attempt < $this->max_retries ) {
                    usleep( pow( 2, $attempt ) * 500000 ); // Exponential back-off
                }
                continue;
            }

            $code = wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body( $response );

            if ( 200 !== $code ) {
                $last_error = new WP_Error( 'http_error', sprintf( 'HTTP %d for %s', $code, $url ) );
                ontario_obituaries_log( sprintf( 'HTTP %d (attempt %d/%d) %s', $code, $attempt, $this->max_retries, $url ), 'warning' );
                if ( $attempt < $this->max_retries ) {
                    usleep( pow( 2, $attempt ) * 500000 );
                }
                continue;
            }

            return $body;
        }

        return $last_error ? $last_error : new WP_Error( 'http_error', 'Request failed after retries' );
    }

    /* ─────────────────────── HTML PARSING HELPERS ──────────────────── */

    /**
     * Create a DOMDocument from HTML, suppressing warnings for malformed markup.
     *
     * @param string $html Raw HTML.
     * @return DOMDocument
     */
    protected function parse_html( $html ) {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors( true );
        $doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        return $doc;
    }

    /**
     * Run an XPath query on a DOMDocument.
     *
     * @param DOMDocument $doc   The document.
     * @param string      $query XPath expression.
     * @return DOMNodeList
     */
    protected function xpath_query( $doc, $query ) {
        $xpath = new DOMXPath( $doc );
        return $xpath->query( $query );
    }

    /**
     * Extract text content from a node, trimmed and cleaned.
     *
     * @param DOMNode|null $node The DOM node.
     * @return string
     */
    protected function node_text( $node ) {
        if ( ! $node ) {
            return '';
        }
        return trim( preg_replace( '/\s+/', ' ', $node->textContent ) );
    }

    /**
     * Extract an attribute from a node.
     *
     * @param DOMNode|null $node The DOM node.
     * @param string       $attr Attribute name.
     * @return string
     */
    protected function node_attr( $node, $attr ) {
        if ( ! $node || ! $node->hasAttribute( $attr ) ) {
            return '';
        }
        return trim( $node->getAttribute( $attr ) );
    }

    /**
     * Resolve a relative URL against a base.
     *
     * @param string $relative The relative or absolute URL.
     * @param string $base     The base URL.
     * @return string Absolute URL.
     */
    protected function resolve_url( $relative, $base ) {
        if ( empty( $relative ) ) {
            return '';
        }
        // Already absolute
        if ( preg_match( '#^https?://#i', $relative ) ) {
            return $relative;
        }
        $parsed = wp_parse_url( $base );
        $scheme = isset( $parsed['scheme'] ) ? $parsed['scheme'] : 'https';
        $host   = isset( $parsed['host'] )   ? $parsed['host']   : '';

        if ( 0 === strpos( $relative, '//' ) ) {
            return $scheme . ':' . $relative;
        }
        if ( 0 === strpos( $relative, '/' ) ) {
            return $scheme . '://' . $host . $relative;
        }
        $path = isset( $parsed['path'] ) ? $parsed['path'] : '/';
        $dir  = rtrim( dirname( $path ), '/' );
        return $scheme . '://' . $host . $dir . '/' . $relative;
    }

    /* ─────────────────────── DATE NORMALIZATION ───────────────────── */

    /**
     * Normalize a date string to Y-m-d format.
     *
     * Handles many common obituary date patterns:
     *   "January 15, 2026", "Jan 15, 2026", "15/01/2026", "2026-01-15", etc.
     *
     * @param string $date_string Raw date.
     * @return string Y-m-d or empty string.
     */
    protected function normalize_date( $date_string ) {
        if ( empty( $date_string ) ) {
            return '';
        }

        $date_string = trim( $date_string );

        // Already Y-m-d
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_string ) ) {
            return $date_string;
        }

        // v3.6.0 FIX: Reject bare 4-digit years ("1926", "2025").
        // strtotime("1926") returns today's date which corrupts death dates.
        // A bare year is NOT a valid date — return empty so the caller can
        // decide what to do (e.g. store as birth-year-only).
        if ( preg_match( '/^\d{4}$/', $date_string ) ) {
            return '';
        }

        // Common formats
        $formats = array(
            'F j, Y',      // January 15, 2026
            'F jS, Y',     // January 15th, 2026
            'M j, Y',      // Jan 15, 2026
            'M jS, Y',     // Jan 15th, 2026
            'j F Y',        // 15 January 2026
            'j M Y',        // 15 Jan 2026
            'Y/m/d',
            'm/d/Y',
            'd/m/Y',
            'd-m-Y',
            'm-d-Y',
            'Y-m-d',
            'd-M-Y',       // 15-Jan-2026
        );

        // Strip ordinal suffixes
        $cleaned = preg_replace( '/(\d+)(st|nd|rd|th)/i', '$1', $date_string );

        foreach ( $formats as $format ) {
            $date = DateTime::createFromFormat( $format, $cleaned );
            if ( false !== $date ) {
                return $date->format( 'Y-m-d' );
            }
        }

        // Last resort: strtotime — but guard against it returning today for
        // ambiguous strings like month-names-only or partial dates.
        $ts = strtotime( $cleaned );
        if ( false !== $ts && $ts > 0 ) {
            $result = gmdate( 'Y-m-d', $ts );
            // Reject if strtotime returned today's date for a short/ambiguous input.
            $today = gmdate( 'Y-m-d' );
            if ( $result === $today && strlen( $cleaned ) < 10 ) {
                return ''; // Ambiguous short input resolved to today — reject
            }
            return $result;
        }

        return '';
    }

    /**
     * Calculate age from birth and death dates.
     *
     * @param string $birth Y-m-d.
     * @param string $death Y-m-d.
     * @return int
     */
    protected function calculate_age( $birth, $death ) {
        if ( empty( $birth ) || empty( $death ) ) {
            return 0;
        }
        try {
            $b = new DateTime( $birth );
            $d = new DateTime( $death );
            return $b->diff( $d )->y;
        } catch ( Exception $e ) {
            return 0;
        }
    }

    /**
     * Extract age from descriptive text (e.g. "aged 85", "age 92", "at the age of 78").
     *
     * @param string $text Obituary text.
     * @return int Age or 0.
     */
    protected function extract_age_from_text( $text ) {
        if ( preg_match( '/\bage[d]?\s+(\d{1,3})\b/i', $text, $m ) ) {
            return intval( $m[1] );
        }
        if ( preg_match( '/\bat\s+the\s+age\s+of\s+(\d{1,3})\b/i', $text, $m ) ) {
            return intval( $m[1] );
        }
        return 0;
    }

    /* ─────────────────────── PROVENANCE & DEDUPE ──────────────────── */

    /**
     * Generate a stable provenance hash for deduplication.
     *
     * sha1( normalized_name + dod + funeral_home + city )
     *
     * @param array $record Normalized record.
     * @return string 40-char hex hash.
     */
    protected function generate_provenance_hash( $record ) {
        $parts = array(
            $this->normalize_name_for_hash( isset( $record['name'] ) ? $record['name'] : '' ),
            isset( $record['date_of_death'] ) ? $record['date_of_death'] : '',
            $this->normalize_for_hash( isset( $record['funeral_home'] ) ? $record['funeral_home'] : '' ),
            $this->normalize_for_hash( isset( $record['city_normalized'] ) ? $record['city_normalized'] : '' ),
        );
        return sha1( implode( '|', $parts ) );
    }

    /**
     * Normalize a name for hashing: lowercase, collapse whitespace, strip common suffixes.
     *
     * @param string $name Raw name.
     * @return string
     */
    private function normalize_name_for_hash( $name ) {
        $name = strtolower( trim( $name ) );
        $name = preg_replace( '/\s+/', ' ', $name );
        // Remove common name suffixes/prefixes that vary between sources
        $name = preg_replace( '/\b(jr|sr|ii|iii|iv|v|dr|mr|mrs|ms|miss|prof)\b\.?/i', '', $name );
        return trim( $name );
    }

    /**
     * Generic normalization for hash: lowercase, trim, collapse spaces.
     *
     * @param string $value Input.
     * @return string
     */
    private function normalize_for_hash( $value ) {
        return trim( strtolower( preg_replace( '/\s+/', ' ', $value ) ) );
    }

    /* ─────────────────────── CITY NORMALIZATION ───────────────────── */

    /**
     * Normalize city names for Ontario.
     *
     * Maps common variations to a canonical form:
     *   "North York" → "Toronto", "Scarborough" → "Toronto", etc.
     *
     * @param string $city     Raw city/location string.
     * @param string $province Province (default 'ON').
     * @return string Normalized city.
     */
    protected function normalize_city( $city, $province = 'ON' ) {
        if ( empty( $city ) ) {
            return '';
        }

        $city = trim( $city );

        // Remove province/postal suffixes
        $city = preg_replace( '/,?\s*(ON|Ontario|Canada)\s*$/i', '', $city );
        $city = preg_replace( '/,?\s*[A-Z]\d[A-Z]\s*\d[A-Z]\d\s*$/i', '', $city );
        $city = trim( $city, ', ' );

        // Known mergers / neighborhoods → parent city
        $toronto_areas = array(
            'north york', 'scarborough', 'etobicoke', 'east york',
            'york', 'willowdale', 'don mills', 'agincourt',
            'thornhill', // Often listed as Toronto-area
        );

        $lower = strtolower( $city );
        foreach ( $toronto_areas as $area ) {
            if ( $lower === $area ) {
                return 'Toronto';
            }
        }

        // Title-case the result
        return ucwords( strtolower( $city ) );
    }

    /* ─────────────────────── DOMAIN EXTRACTION ───────────────────── */

    /**
     * Extract the domain from a URL.
     *
     * @param string $url Full URL.
     * @return string Domain (e.g. "roadhouseandrose.com").
     */
    protected function extract_domain( $url ) {
        $parsed = wp_parse_url( $url );
        $host   = isset( $parsed['host'] ) ? $parsed['host'] : '';
        // Strip www.
        return preg_replace( '/^www\./i', '', $host );
    }

    /* ─────────────────────── DEFAULT IMPLEMENTATIONS ──────────────── */

    /**
     * Default test_connection: try to fetch the base URL.
     *
     * @param array $source Source registry row.
     * @return array
     */
    public function test_connection( $source ) {
        $url    = isset( $source['base_url'] ) ? $source['base_url'] : '';
        $result = $this->http_get( $url );

        if ( is_wp_error( $result ) ) {
            return array(
                'success' => false,
                'message' => 'Connection failed: ' . $result->get_error_message(),
            );
        }

        return array(
            'success' => true,
            'message' => 'Connection successful. Received ' . strlen( $result ) . ' bytes.',
        );
    }

    /**
     * Default fetch_detail: returns null (adapter can override for richer data).
     *
     * @param string $detail_url Detail page URL.
     * @param array  $card       Card data.
     * @param array  $source     Source row.
     * @return array|null
     */
    public function fetch_detail( $detail_url, $card, $source ) {
        return null;
    }
}
