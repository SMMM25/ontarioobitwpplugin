<?php
/**
 * Source Adapter Interface
 *
 * Defines the contract every source adapter must implement.
 * Each adapter handles a specific platform pattern (Tribute Archive, FrontRunner,
 * WordPress obituary pages, newspaper networks, generic HTML).
 *
 * Architecture:
 *   Source Registry → SourceAdapter::discover → fetch → extract → normalize → DB
 *
 * @package Ontario_Obituaries
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface Ontario_Obituaries_Source_Adapter {

    /**
     * Return a unique machine slug for this adapter type.
     * Example: 'tribute_archive', 'frontrunner', 'wp_obituary', 'newspaper_network'
     *
     * @return string
     */
    public function get_type();

    /**
     * Return a human-readable label.
     *
     * @return string
     */
    public function get_label();

    /**
     * Discover listing page URLs for a given source entry.
     *
     * Receives a source registry row (domain, base_url, config JSON).
     * Returns an array of URLs to fetch (e.g. paginated listing pages for "recent obituaries").
     *
     * @param array $source Source registry row.
     * @param int   $max_age Maximum obituary age in days.
     * @return string[] Array of listing page URLs to fetch.
     */
    public function discover_listing_urls( $source, $max_age = 7 );

    /**
     * Fetch a single listing page and return raw HTML.
     *
     * @param string $url     The listing page URL.
     * @param array  $source  Source registry row (for auth/headers if needed).
     * @return string|WP_Error Raw HTML or error.
     */
    public function fetch_listing( $url, $source );

    /**
     * Extract obituary "cards" from a listing page HTML.
     *
     * Each card is a minimal associative array:
     *   - name (string)
     *   - detail_url (string, optional — for a follow-up fetch)
     *   - date_of_death (string, optional)
     *   - ... any other fields extractable from the listing
     *
     * @param string $html   Raw HTML of the listing page.
     * @param array  $source Source registry row.
     * @return array[] Array of raw card arrays.
     */
    public function extract_obit_cards( $html, $source );

    /**
     * (Optional) Fetch the detail page for richer data.
     *
     * Return null if the adapter can extract everything from the listing page.
     *
     * @param string $detail_url URL of the detail page.
     * @param array  $card       Card data from extract_obit_cards().
     * @param array  $source     Source registry row.
     * @return array|null Enriched card array or null on failure.
     */
    public function fetch_detail( $detail_url, $card, $source );

    /**
     * Normalize a raw card into the canonical DB schema.
     *
     * Must return an array with these keys:
     *   name, date_of_birth, date_of_death, age, funeral_home, location,
     *   image_url, description, source_url, source_domain, source_type,
     *   city_normalized, provenance_hash
     *
     * @param array $card   Raw or enriched card.
     * @param array $source Source registry row.
     * @return array Normalized obituary record.
     */
    public function normalize( $card, $source );

    /**
     * Test connectivity to a source.
     *
     * @param array $source Source registry row.
     * @return array { success: bool, message: string }
     */
    public function test_connection( $source );
}
