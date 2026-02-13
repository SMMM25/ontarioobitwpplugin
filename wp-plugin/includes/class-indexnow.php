<?php
/**
 * IndexNow Integration
 *
 * Submits new and updated obituary URLs to search engines (Bing, Yandex, Naver)
 * via the IndexNow protocol for instant indexing.
 *
 * Architecture:
 *   - API key stored in a text file at the site root (required by IndexNow spec).
 *   - Key also stored in wp_options for reference.
 *   - URLs are batched and submitted after each collection cycle.
 *   - Supports up to 10,000 URLs per submission.
 *
 * @package Ontario_Obituaries
 * @since   4.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ontario_Obituaries_IndexNow {

    /** @var string IndexNow API endpoint. */
    private $api_url = 'https://api.indexnow.org/indexnow';

    /** @var string Site host (e.g., monacomonuments.ca). */
    private $host;

    /** @var string IndexNow API key. */
    private $key;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->host = wp_parse_url( home_url(), PHP_URL_HOST );
        $this->key  = get_option( 'ontario_obituaries_indexnow_key', '' );

        // Auto-generate key if not set.
        if ( empty( $this->key ) ) {
            $this->key = wp_generate_password( 32, false );
            update_option( 'ontario_obituaries_indexnow_key', $this->key );
        }
    }

    /**
     * Check if IndexNow is ready (key file must exist at site root).
     *
     * @return bool
     */
    public function is_ready() {
        return ! empty( $this->key );
    }

    /**
     * Get the API key for verification file creation.
     *
     * @return string The IndexNow API key.
     */
    public function get_key() {
        return $this->key;
    }

    /**
     * Submit a batch of obituary URLs to IndexNow.
     *
     * Called after each collection cycle with the IDs of newly added obituaries.
     *
     * @param array $obituary_ids Array of obituary IDs to submit.
     * @return array { submitted: int, status: string, response_code: int }
     */
    public function submit_urls( $obituary_ids ) {
        if ( empty( $obituary_ids ) || ! $this->is_ready() ) {
            return array( 'submitted' => 0, 'status' => 'skipped', 'response_code' => 0 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        $urls = array();

        foreach ( $obituary_ids as $id ) {
            $obit = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, name, city_normalized, location FROM `{$table}` WHERE id = %d",
                intval( $id )
            ) );

            if ( ! $obit ) {
                continue;
            }

            $city_slug = sanitize_title(
                ! empty( $obit->city_normalized ) ? $obit->city_normalized : $obit->location
            );
            if ( empty( $city_slug ) ) {
                $city_slug = 'ontario';
            }

            $name_slug = sanitize_title( $obit->name );
            $url       = home_url( sprintf( '/obituaries/ontario/%s/%s-%d/', $city_slug, $name_slug, $obit->id ) );
            $urls[]    = $url;
        }

        if ( empty( $urls ) ) {
            return array( 'submitted' => 0, 'status' => 'no_valid_urls', 'response_code' => 0 );
        }

        // Also submit hub pages that may have changed.
        $urls[] = home_url( '/obituaries/ontario/' );

        // Submit via IndexNow batch API.
        $body = wp_json_encode( array(
            'host'    => $this->host,
            'key'     => $this->key,
            'keyLocation' => home_url( '/' . $this->key . '.txt' ),
            'urlList' => array_slice( $urls, 0, 10000 ), // API limit
        ) );

        $response = wp_remote_post( $this->api_url, array(
            'timeout' => 15,
            'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
            'body'    => $body,
        ) );

        $code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );

        $status = 'error';
        if ( 200 === $code || 202 === $code ) {
            $status = 'accepted';
        } elseif ( 429 === $code ) {
            $status = 'rate_limited';
        }

        ontario_obituaries_log(
            sprintf(
                'IndexNow: Submitted %d URLs — HTTP %d (%s).',
                count( $urls ),
                $code,
                $status
            ),
            $status === 'error' ? 'warning' : 'info'
        );

        return array(
            'submitted'     => count( $urls ),
            'status'        => $status,
            'response_code' => $code,
        );
    }

    /**
     * Handle the IndexNow key verification request.
     *
     * IndexNow requires the key to be accessible at /{key}.txt on the site root.
     * This hooks into template_redirect to serve it without a physical file.
     */
    public function serve_verification_file() {
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

        if ( $request_uri === '/' . $this->key . '.txt' ) {
            header( 'Content-Type: text/plain; charset=utf-8' );
            echo esc_html( $this->key );
            exit;
        }
    }
}
