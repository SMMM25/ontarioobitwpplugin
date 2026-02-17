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

    /**
     * v5.1.0: Death-context keywords regex fragment (shared by all adapters).
     *
     * Used by extract_age_from_text() and subclass detail-page extractors to
     * ensure "at the age of N" / "aged N" patterns only match inside sentences
     * that contain a death-related keyword.
     *
     * @var string Regex alternation (no delimiters, case-insensitive use).
     */
    const DEATH_KEYWORDS_REGEX = 'passed\s+away|passed|died|peacefully|suddenly|unexpectedly|passing|departed|entered\s+into\s+rest';

    /**
     * v3.12.2: Last HTTP request diagnostics.
     *
     * Populated by http_get() on every call. Allows the Source Collector
     * to read diagnostics (HTTP status, error message, duration, final URL)
     * without changing the http_get() return signature.
     *
     * @var array { http_status: int|string, error_message: string, final_url: string, duration_ms: int }
     */
    protected $last_diagnostics = array();

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

        // v3.12.2: Track diagnostics for the caller (Source Collector / Reset & Rescan).
        $start_time    = microtime( true );
        $diag_status   = 0;
        $diag_error    = '';
        $diag_url      = $url;

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
                $last_error  = $response;
                $diag_status = 'error';
                $diag_error  = $response->get_error_message();
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

            // v3.12.2: Capture effective URL from redirect headers when available.
            $effective_url = wp_remote_retrieve_header( $response, 'location' );
            if ( ! empty( $effective_url ) ) {
                $diag_url = $effective_url;
            }

            if ( 200 !== $code ) {
                $last_error  = new WP_Error( 'http_error', sprintf( 'HTTP %d for %s', $code, $url ) );
                $diag_status = $code;
                $diag_error  = sprintf( 'HTTP %d', $code );
                ontario_obituaries_log( sprintf( 'HTTP %d (attempt %d/%d) %s', $code, $attempt, $this->max_retries, $url ), 'warning' );
                if ( $attempt < $this->max_retries ) {
                    usleep( pow( 2, $attempt ) * 500000 );
                }
                continue;
            }

            // Success
            $diag_status = $code;
            $diag_error  = '';

            $this->last_diagnostics = array(
                'http_status'   => $diag_status,
                'error_message' => $diag_error,
                'final_url'     => $diag_url,
                'duration_ms'   => (int) round( ( microtime( true ) - $start_time ) * 1000 ),
            );

            return $body;
        }

        // All retries exhausted — store failure diagnostics.
        // Detect timeout from WP_Error code.
        if ( $last_error && is_wp_error( $last_error ) && 'http_request_failed' === $last_error->get_error_code() ) {
            $err_msg = $last_error->get_error_message();
            if ( false !== stripos( $err_msg, 'timed out' ) || false !== stripos( $err_msg, 'timeout' ) ) {
                $diag_status = 'timeout';
            }
        }

        $this->last_diagnostics = array(
            'http_status'   => $diag_status,
            'error_message' => $diag_error,
            'final_url'     => $diag_url,
            'duration_ms'   => (int) round( ( microtime( true ) - $start_time ) * 1000 ),
        );

        return $last_error ? $last_error : new WP_Error( 'http_error', 'Request failed after retries' );
    }

    /**
     * v3.12.2: Retrieve diagnostics from the last http_get() call.
     *
     * @return array { http_status: int|string, error_message: string, final_url: string, duration_ms: int }
     */
    public function get_last_request_diagnostics() {
        return $this->last_diagnostics;
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

        // v4.2.5 FIX: Collapse internal whitespace (newlines, double-spaces) BEFORE
        // any parsing. Source descriptions often contain line-breaks mid-date like
        // "May 7,\n\n2024" which causes strtotime() to ignore the year portion.
        $date_string = preg_replace( '/\s+/', ' ', $date_string );

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
     * v5.1.0 FIX (ID-454): Restrict "at the age of N" and "aged N" patterns to
     * sentences containing a death-related keyword. Previously, these patterns
     * matched anywhere in the text, causing biographical age mentions (e.g.,
     * "admitted to Teacher's College at the age of 16") to be mis-classified
     * as the death age. The "Nth year" patterns are inherently death-context
     * (obituaries only use "in his/her Nth year" for the final age) so they
     * remain full-text.
     *
     * @param string $text Obituary text.
     * @return int Age or 0.
     */
    protected function extract_age_from_text( $text ) {
        // v5.1.0: Normalize text before sentence splitting to handle HTML remnants,
        // collapsed whitespace, and abbreviation-period false splits.
        // strip_tags handles <p>, <br>, <div> boundaries; we replace them with ". "
        // first so tag boundaries become sentence breaks.
        $text = preg_replace( '/<\s*(?:br|p|div)[^>]*>/i', '. ', $text );
        $text = wp_strip_all_tags( $text );
        $text = preg_replace( '/\s+/', ' ', $text );  // collapse whitespace
        $text = trim( $text );

        // ── Pass 1: "in his/her Nth year" — always death-context; full-text scan ──
        // v5.0.0: "in his/her Nth year" means age N-1 (they completed N-1 years).
        // This pattern MUST come first because "aged 93" might also appear in text
        // that says "in her 93rd year" — the Nth year form is more precise.
        if ( preg_match( '/(?:in|into|lived\s+into)\s+(?:his|her|their)\s+(\d+)(?:st|nd|rd|th)\s+year/i', $text, $m ) ) {
            return intval( $m[1] ) - 1;
        }
        if ( preg_match( '/(?:his|her|their)\s+(\d+)(?:st|nd|rd|th)\s+year/i', $text, $m ) ) {
            return intval( $m[1] ) - 1;
        }

        // ── Pass 2: sentence-scoped "at the age of N" / "aged N" ──
        // v5.1.0: Only match when the same sentence contains a death keyword.
        // Split on sentence-ending punctuation. The text normalization above
        // ensures HTML tags don't break sentence boundaries.
        $sentences = preg_split( '/(?<=[.!?;])\s+/', $text );
        foreach ( $sentences as $sentence ) {
            if ( ! preg_match( '/' . self::DEATH_KEYWORDS_REGEX . '/i', $sentence ) ) {
                continue;
            }
            if ( preg_match( '/\bat\s+the\s+age\s+of\s+(\d{1,3})\b/i', $sentence, $m ) ) {
                return intval( $m[1] );
            }
            if ( preg_match( '/\bage[d]?\s+(\d{1,3})\b/i', $sentence, $m ) ) {
                return intval( $m[1] );
            }
        }

        // No death-context age found; return 0 so the caller can fall back
        // to calculate_age( DOB, DOD ) when both dates are available.
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

        // v4.2.2: Reject values that are clearly not city names.
        // Garbled/encoded strings (contain non-printable, base64 artifacts, JS fragments).
        if ( preg_match( '/[{}\[\]<>]|__next|mself|q2l0|push1/', $city ) ) {
            return '';
        }

        // v4.2.2: Reject biographical text stored as city.
        if ( preg_match( '/\b(was born|settled in|passed away|survived by|predeceased)\b/i', $city ) ) {
            return '';
        }

        // v4.2.2: If the value contains a street address indicator, extract only the city.
        // Pattern: "123 Street Name, City" or "Street Name, City, ON"
        $street_keywords = array( 'Street', 'Avenue', 'Drive', 'Road', 'Parkway', 'Boulevard', 'Crescent', 'Lane', 'Court', 'Place', 'Way', 'Circle', 'Trail', ' St ', ' Ave ', ' Dr ', ' Rd ', ' Blvd ' );
        $is_address = false;
        foreach ( $street_keywords as $kw ) {
            if ( stripos( $city, $kw ) !== false ) {
                $is_address = true;
                break;
            }
        }

        if ( $is_address ) {
            // Try to extract city after the last comma.
            $parts = explode( ',', $city );
            if ( count( $parts ) > 1 ) {
                $candidate = trim( end( $parts ) );
                // Remove province suffixes from the extracted part.
                $candidate = preg_replace( '/\s*(ON|Ontario|Canada)\s*$/i', '', $candidate );
                $candidate = trim( $candidate );
                if ( strlen( $candidate ) >= 3 && strlen( $candidate ) <= 30 && ! preg_match( '/\d/', $candidate ) ) {
                    $city = $candidate;
                } else {
                    return ''; // Cannot reliably extract city from address.
                }
            } else {
                return ''; // Address with no comma — can't extract city reliably.
            }
        }

        // Remove province/postal suffixes
        $city = preg_replace( '/,?\s*(ON|Ontario|Canada)\s*$/i', '', $city );
        $city = preg_replace( '/,?\s*[A-Z]\d[A-Z]\s*\d[A-Z]\d\s*$/i', '', $city );
        $city = trim( $city, ', ' );

        // v4.2.2: Reject if still too long (real city names are rarely > 30 chars).
        if ( strlen( $city ) > 40 ) {
            return '';
        }

        // v4.2.2: Reject if the value contains digits (house numbers leaked through).
        if ( preg_match( '/\d/', $city ) ) {
            return '';
        }

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

        // v4.2.2: Known truncation fixes (source data arrives pre-truncated).
        $truncation_fixes = array(
            'hamilt'   => 'Hamilton',
            'burlingt' => 'Burlington',
            'sutt'     => 'Sutton',
            'lond'     => 'London',
            'milt'     => 'Milton',
            'tivert'   => 'Tiverton',
            'harrist'  => 'Harriston',
            'kingst'   => 'Kingston',
            'leamingt' => 'Leamington',
            'allist'   => 'Alliston',
            'kitchner' => 'Kitchener',
            'stoiuffville' => 'Stouffville',
            'catharines'   => 'St. Catharines',
            'beet'     => 'Beeton',
        );

        if ( isset( $truncation_fixes[ $lower ] ) ) {
            return $truncation_fixes[ $lower ];
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
