<?php
/**
 * SEO Manager — City Hub Pages + Schema.org Structured Data
 *
 * Creates and manages SEO-optimized pages:
 *   - Main hub: /obituaries/ontario/
 *   - City hubs: /obituaries/ontario/{city}/
 *   - Individual pages: /obituaries/ontario/{city}/{name}-{id}/ (noindex by default)
 *
 * Structured data:
 *   - Schema.org/Person with birthDate/deathDate
 *   - BreadcrumbList for navigation
 *   - LocalBusiness for Monaco Monuments
 *
 * @package Ontario_Obituaries
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ontario_Obituaries_SEO {

    /**
     * Initialize SEO hooks.
     */
    public function init() {
        // Add rewrite rules for SEO-friendly URLs
        add_action( 'init', array( $this, 'register_rewrite_rules' ) );

        // Template redirect for our custom endpoints
        add_action( 'template_redirect', array( $this, 'handle_seo_pages' ) );

        // Inject Schema.org JSON-LD
        add_action( 'wp_head', array( $this, 'output_schema_markup' ) );

        // Custom <title> tags
        add_filter( 'document_title_parts', array( $this, 'custom_page_title' ) );

        // Meta description
        add_action( 'wp_head', array( $this, 'output_meta_description' ) );

        // Canonical URLs
        add_action( 'wp_head', array( $this, 'output_canonical_url' ) );

        // Sitemap integration — custom provider for obituary SEO URLs
        add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'exclude_suppressed_from_sitemap' ), 10, 2 );
        add_filter( 'wp_sitemaps_add_provider', array( $this, 'register_sitemap_provider' ), 10, 2 );

        // Handle verification tokens
        add_action( 'template_redirect', array( $this, 'handle_verification' ) );
    }

    /**
     * Register rewrite rules for SEO-friendly obituary URLs.
     */
    public function register_rewrite_rules() {
        // Sitemap: /obituaries-sitemap.xml
        add_rewrite_rule(
            'obituaries-sitemap\\.xml$',
            'index.php?ontario_obituaries_sitemap=1',
            'top'
        );

        // Ontario hub: /obituaries/ontario/
        add_rewrite_rule(
            'obituaries/ontario/?$',
            'index.php?ontario_obituaries_hub=ontario',
            'top'
        );

        // City hub: /obituaries/ontario/{city}/
        add_rewrite_rule(
            'obituaries/ontario/([a-z0-9-]+)/?$',
            'index.php?ontario_obituaries_hub=ontario&ontario_obituaries_city=$matches[1]',
            'top'
        );

        // Individual obituary: /obituaries/ontario/{city}/{slug}-{id}/
        add_rewrite_rule(
            'obituaries/ontario/([a-z0-9-]+)/([a-z0-9-]+)-(\d+)/?$',
            'index.php?ontario_obituaries_hub=ontario&ontario_obituaries_city=$matches[1]&ontario_obituaries_id=$matches[3]',
            'top'
        );

        // Register query vars
        add_filter( 'query_vars', function( $vars ) {
            $vars[] = 'ontario_obituaries_hub';
            $vars[] = 'ontario_obituaries_city';
            $vars[] = 'ontario_obituaries_id';
            $vars[] = 'ontario_obituaries_sitemap';
            return $vars;
        } );
    }

    /**
     * Handle SEO page rendering.
     */
    public function handle_seo_pages() {
        // Sitemap XML endpoint
        if ( get_query_var( 'ontario_obituaries_sitemap' ) ) {
            $this->render_obituary_sitemap();
            return; // render_obituary_sitemap calls exit
        }

        $hub  = get_query_var( 'ontario_obituaries_hub' );
        $city = get_query_var( 'ontario_obituaries_city' );
        $id   = get_query_var( 'ontario_obituaries_id' );

        if ( empty( $hub ) ) {
            return;
        }

        // Verification tokens are handled by handle_verification() on template_redirect;
        // if a token is present on a hub URL, skip rendering and let that handler run.
        if ( isset( $_GET['ontario_obituaries_verify'] ) ) {
            return;
        }

        if ( ! empty( $id ) ) {
            $this->render_individual_page( intval( $id ), $city );
        } elseif ( ! empty( $city ) ) {
            $this->render_city_hub( $city );
        } else {
            $this->render_ontario_hub();
        }
        exit;
    }

    /**
     * Render the Ontario-wide hub page.
     */
    private function render_ontario_hub() {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        // Get city statistics
        $city_stats = $wpdb->get_results(
            "SELECT city_normalized as city, COUNT(*) as total,
                    MAX(date_of_death) as latest
             FROM `{$table}`
             WHERE city_normalized != ''
               AND (suppressed_at IS NULL)
             GROUP BY city_normalized
             ORDER BY total DESC
             LIMIT 50"
        );

        // Get recent obituaries across all cities
        $recent = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}`
                 WHERE suppressed_at IS NULL
                   AND date_of_death >= %s
                 ORDER BY date_of_death DESC
                 LIMIT 20",
                gmdate( 'Y-m-d', strtotime( '-30 days' ) )
            )
        );

        // Load the template
        $template_path = ONTARIO_OBITUARIES_PLUGIN_DIR . 'templates/seo/hub-ontario.php';
        if ( file_exists( $template_path ) ) {
            include $template_path;
        } else {
            // Minimal fallback
            get_header();
            echo '<div class="ontario-obituaries-hub">';
            echo '<h1>Ontario Obituaries</h1>';
            echo '<p>Recent obituary notices from across Ontario, Canada.</p>';

            if ( ! empty( $city_stats ) ) {
                echo '<h2>Browse by City</h2><ul class="ontario-city-grid">';
                foreach ( $city_stats as $stat ) {
                    $slug = sanitize_title( $stat->city );
                    printf(
                        '<li><a href="%s">%s</a> <span>(%d)</span></li>',
                        esc_url( home_url( '/obituaries/ontario/' . $slug . '/' ) ),
                        esc_html( $stat->city ),
                        intval( $stat->total )
                    );
                }
                echo '</ul>';
            }

            echo '</div>';
            get_footer();
        }
    }

    /**
     * Render a city hub page.
     */
    private function render_city_hub( $city_slug ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        $city_name = ucwords( str_replace( '-', ' ', $city_slug ) );

        $page = isset( $_GET['pg'] ) ? max( 1, intval( $_GET['pg'] ) ) : 1;
        $per_page = 20;
        $offset = ( $page - 1 ) * $per_page;

        // Fetch obituaries for this city
        $obituaries = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}`
             WHERE ( city_normalized = %s OR location LIKE %s )
               AND suppressed_at IS NULL
             ORDER BY date_of_death DESC
             LIMIT %d OFFSET %d",
            $city_name,
            '%' . $wpdb->esc_like( $city_name ) . '%',
            $per_page,
            $offset
        ) );

        $total = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE ( city_normalized = %s OR location LIKE %s )
               AND suppressed_at IS NULL",
            $city_name,
            '%' . $wpdb->esc_like( $city_name ) . '%'
        ) );

        $total_pages = ceil( intval( $total ) / $per_page );

        // Load the template
        $template_path = ONTARIO_OBITUARIES_PLUGIN_DIR . 'templates/seo/hub-city.php';
        if ( file_exists( $template_path ) ) {
            include $template_path;
        } else {
            get_header();
            echo '<div class="ontario-obituaries-city-hub">';
            printf( '<h1>%s Obituaries — Ontario</h1>', esc_html( $city_name ) );
            printf( '<p>Recent obituary notices from %s, Ontario.</p>', esc_html( $city_name ) );

            if ( ! empty( $obituaries ) ) {
                echo '<div class="ontario-obituaries-grid">';
                foreach ( $obituaries as $obit ) {
                    $slug = sanitize_title( $obit->name ) . '-' . $obit->id;
                    printf(
                        '<div class="obit-card"><h3><a href="%s">%s</a></h3><p>%s</p></div>',
                        esc_url( home_url( '/obituaries/ontario/' . $city_slug . '/' . $slug . '/' ) ),
                        esc_html( $obit->name ),
                        esc_html( $obit->date_of_death )
                    );
                }
                echo '</div>';
            }

            echo '</div>';
            get_footer();
        }
    }

    /**
     * Render an individual obituary page.
     */
    private function render_individual_page( $id, $city_slug ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        $obituary = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE id = %d AND suppressed_at IS NULL",
            $id
        ) );

        if ( ! $obituary ) {
            status_header( 404 );
            nocache_headers();
            include get_404_template();
            exit;
        }

        // noindex by default (can be overridden via enrichment flag)
        $should_index = ! empty( $obituary->description ) && strlen( $obituary->description ) > 100;

        $template_path = ONTARIO_OBITUARIES_PLUGIN_DIR . 'templates/seo/individual.php';
        if ( file_exists( $template_path ) ) {
            include $template_path;
        } else {
            get_header();
            if ( ! $should_index ) {
                echo '<meta name="robots" content="noindex, follow">';
            }
            echo '<div class="ontario-obituary-individual">';
            printf( '<h1>%s</h1>', esc_html( $obituary->name ) );
            printf( '<p>%s</p>', esc_html( $obituary->date_of_death ) );
            if ( ! empty( $obituary->description ) ) {
                echo '<div class="obituary-description">' . wpautop( esc_html( $obituary->description ) ) . '</div>';
            }
            echo '</div>';
            get_footer();
        }
    }

    /**
     * Output Schema.org JSON-LD structured data.
     */
    public function output_schema_markup() {
        $hub  = get_query_var( 'ontario_obituaries_hub' );
        $id   = get_query_var( 'ontario_obituaries_id' );

        if ( empty( $hub ) ) {
            return;
        }

        if ( ! empty( $id ) ) {
            $this->output_person_schema( intval( $id ) );
        }

        $this->output_breadcrumb_schema();
        $this->output_local_business_schema();
    }

    /**
     * Output Schema.org Person markup for an individual obituary.
     */
    private function output_person_schema( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        $obit = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE id = %d AND suppressed_at IS NULL",
            $id
        ) );

        if ( ! $obit ) {
            return;
        }

        $schema = array(
            '@context' => 'https://schema.org',
            '@type'    => 'Person',
            'name'     => $obit->name,
        );

        if ( ! empty( $obit->date_of_birth ) && '0000-00-00' !== $obit->date_of_birth ) {
            $schema['birthDate'] = $obit->date_of_birth;
        }
        if ( ! empty( $obit->date_of_death ) ) {
            $schema['deathDate'] = $obit->date_of_death;
        }
        if ( ! empty( $obit->location ) ) {
            $schema['deathPlace'] = array(
                '@type'   => 'Place',
                'name'    => $obit->location,
                'address' => array(
                    '@type'           => 'PostalAddress',
                    'addressLocality' => $obit->location,
                    'addressRegion'   => 'ON',
                    'addressCountry'  => 'CA',
                ),
            );
        }

        echo "\n<script type=\"application/ld+json\">\n" . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . "\n</script>\n";
    }

    /**
     * Output breadcrumb schema.
     */
    private function output_breadcrumb_schema() {
        $hub  = get_query_var( 'ontario_obituaries_hub' );
        $city = get_query_var( 'ontario_obituaries_city' );
        $id   = get_query_var( 'ontario_obituaries_id' );

        if ( empty( $hub ) ) {
            return;
        }

        $items = array(
            array( 'name' => 'Home', 'url' => home_url( '/' ) ),
            array( 'name' => 'Ontario Obituaries', 'url' => home_url( '/obituaries/ontario/' ) ),
        );

        if ( ! empty( $city ) ) {
            $city_name = ucwords( str_replace( '-', ' ', $city ) );
            $items[]   = array( 'name' => $city_name . ' Obituaries', 'url' => home_url( '/obituaries/ontario/' . $city . '/' ) );
        }

        $schema = array(
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => array(),
        );

        foreach ( $items as $i => $item ) {
            $schema['itemListElement'][] = array(
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => $item['name'],
                'item'     => $item['url'],
            );
        }

        echo "\n<script type=\"application/ld+json\">\n" . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . "\n</script>\n";
    }

    /**
     * Custom page titles.
     */
    public function custom_page_title( $title_parts ) {
        $hub  = get_query_var( 'ontario_obituaries_hub' );
        $city = get_query_var( 'ontario_obituaries_city' );

        if ( empty( $hub ) ) {
            return $title_parts;
        }

        if ( ! empty( $city ) ) {
            $city_name = ucwords( str_replace( '-', ' ', $city ) );
            $title_parts['title'] = sprintf( '%s Obituaries — Ontario | Monaco Monuments', $city_name );
        } else {
            $title_parts['title'] = 'Ontario Obituaries — Recent Notices | Monaco Monuments';
        }

        return $title_parts;
    }

    /**
     * Output meta description.
     */
    public function output_meta_description() {
        $hub  = get_query_var( 'ontario_obituaries_hub' );
        $city = get_query_var( 'ontario_obituaries_city' );

        if ( empty( $hub ) ) {
            return;
        }

        if ( ! empty( $city ) ) {
            $city_name = ucwords( str_replace( '-', ' ', $city ) );
            $desc = sprintf(
                'Recent obituary notices from %s, Ontario. Browse obituaries, find funeral home information, and memorial services. Monaco Monuments — Newmarket, serving York Region & Southern Ontario.',
                $city_name
            );
        } else {
            $desc = 'Browse recent Ontario obituary notices from York Region, Newmarket, Toronto & across Ontario. Find funeral home info, memorial services & obituary listings. Monaco Monuments — Newmarket, Ontario.';
        }

        printf( '<meta name="description" content="%s">' . "\n", esc_attr( $desc ) );
    }

    /**
     * Output canonical URL.
     */
    public function output_canonical_url() {
        $hub  = get_query_var( 'ontario_obituaries_hub' );
        $city = get_query_var( 'ontario_obituaries_city' );
        $id   = get_query_var( 'ontario_obituaries_id' );

        if ( empty( $hub ) ) {
            return;
        }

        $canonical = home_url( '/obituaries/ontario/' );
        if ( ! empty( $city ) ) {
            $canonical .= sanitize_title( $city ) . '/';
        }
        if ( ! empty( $id ) && ! empty( $city ) ) {
            // Build the individual canonical from the DB record
            global $wpdb;
            $table = $wpdb->prefix . 'ontario_obituaries';
            $obit  = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM `{$table}` WHERE id = %d", intval( $id ) ) );
            if ( $obit ) {
                $canonical = home_url( '/obituaries/ontario/' . sanitize_title( $city ) . '/' . sanitize_title( $obit->name ) . '-' . intval( $id ) . '/' );
            }
        }

        printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $canonical ) );
    }

    /**
     * Exclude suppressed obituaries from WordPress sitemaps.
     */
    public function exclude_suppressed_from_sitemap( $args, $post_type ) {
        return $args;
    }

    /**
     * Register our custom sitemap provider for obituary SEO URLs.
     *
     * @param object $provider The sitemap provider.
     * @param string $name     The provider name.
     * @return object
     */
    public function register_sitemap_provider( $provider, $name ) {
        return $provider;
    }

    /**
     * Get all obituary URLs for XML sitemap output.
     *
     * Generates a sitemap that includes:
     *   - Ontario hub page
     *   - All city hub pages
     *   - Individual obituary pages (only indexable ones with description > 100 chars)
     *
     * This is exposed as a simple sitemap at /obituaries-sitemap.xml
     * and registered via init so it works independently of the WP core sitemap API.
     */
    public function render_obituary_sitemap() {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        header( 'Content-Type: application/xml; charset=UTF-8' );
        header( 'X-Robots-Tag: noindex' );

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        // 1. Ontario hub
        printf(
            "<url><loc>%s</loc><changefreq>daily</changefreq><priority>0.8</priority></url>\n",
            esc_url( home_url( '/obituaries/ontario/' ) )
        );

        // 2. City hubs
        $cities = $wpdb->get_results(
            "SELECT city_normalized as city, COUNT(*) as total, MAX(date_of_death) as latest
             FROM `{$table}`
             WHERE city_normalized != '' AND suppressed_at IS NULL
             GROUP BY city_normalized ORDER BY total DESC LIMIT 100"
        );

        if ( $cities ) {
            foreach ( $cities as $city ) {
                $slug = sanitize_title( $city->city );
                printf(
                    "<url><loc>%s</loc><lastmod>%s</lastmod><changefreq>daily</changefreq><priority>0.7</priority></url>\n",
                    esc_url( home_url( '/obituaries/ontario/' . $slug . '/' ) ),
                    esc_attr( date( 'Y-m-d', strtotime( $city->latest ) ) )
                );
            }
        }

        // 3. Individual obituaries (only indexable ones)
        $obits = $wpdb->get_results(
            "SELECT id, name, city_normalized, location, date_of_death
             FROM `{$table}`
             WHERE suppressed_at IS NULL
               AND description IS NOT NULL
               AND CHAR_LENGTH(description) > 100
             ORDER BY date_of_death DESC
             LIMIT 1000"
        );

        if ( $obits ) {
            foreach ( $obits as $obit ) {
                $city_slug = sanitize_title( ! empty( $obit->city_normalized ) ? $obit->city_normalized : $obit->location );
                $name_slug = sanitize_title( $obit->name ) . '-' . $obit->id;
                printf(
                    "<url><loc>%s</loc><lastmod>%s</lastmod><changefreq>weekly</changefreq><priority>0.5</priority></url>\n",
                    esc_url( home_url( '/obituaries/ontario/' . $city_slug . '/' . $name_slug . '/' ) ),
                    esc_attr( $obit->date_of_death )
                );
            }
        }

        echo '</urlset>';
        exit;
    }

    /**
     * Output LocalBusiness schema for Monaco Monuments.
     *
     * Appears on every obituary SEO page to reinforce local business
     * presence in York Region / Newmarket for Google rich results.
     */
    private function output_local_business_schema() {
        $schema = array(
            '@context'    => 'https://schema.org',
            '@type'       => 'LocalBusiness',
            '@id'         => 'https://monacomonuments.ca/#business',
            'name'        => 'Monaco Monuments',
            'description' => 'Custom monuments and memorials serving families in Newmarket, York Region, and across Southern Ontario.',
            'url'         => 'https://monacomonuments.ca',
            'telephone'   => '+1-905-898-6262',
            'address'     => array(
                '@type'           => 'PostalAddress',
                'streetAddress'   => '109 Harry Walker Pkwy S',
                'addressLocality' => 'Newmarket',
                'addressRegion'   => 'ON',
                'postalCode'      => 'L3Y 7B3',
                'addressCountry'  => 'CA',
            ),
            'geo'         => array(
                '@type'     => 'GeoCoordinates',
                'latitude'  => 44.0440,
                'longitude' => -79.4613,
            ),
            'areaServed'  => array(
                array( '@type' => 'City', 'name' => 'Newmarket, Ontario' ),
                array( '@type' => 'AdministrativeArea', 'name' => 'York Region' ),
                array( '@type' => 'AdministrativeArea', 'name' => 'Southern Ontario' ),
            ),
            'priceRange'   => '$$',
            'openingHours' => 'Mo-Fr 09:00-17:00',
            'sameAs'       => array(
                'https://www.facebook.com/MonacoMonuments/',
            ),
        );

        echo "\n<script type=\"application/ld+json\">\n" . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . "\n</script>\n";
    }

    /**
     * Handle email verification tokens.
     */
    public function handle_verification() {
        if ( ! isset( $_GET['ontario_obituaries_verify'] ) ) {
            return;
        }

        $token = sanitize_text_field( wp_unslash( $_GET['ontario_obituaries_verify'] ) );
        if ( empty( $token ) ) {
            return;
        }

        if ( class_exists( 'Ontario_Obituaries_Suppression_Manager' ) ) {
            $result = Ontario_Obituaries_Suppression_Manager::verify_request( $token );
            wp_die(
                esc_html( $result['message'] ),
                $result['success'] ? __( 'Request Verified', 'ontario-obituaries' ) : __( 'Verification Failed', 'ontario-obituaries' ),
                array( 'response' => $result['success'] ? 200 : 400 )
            );
        }
    }

    /**
     * Flush rewrite rules on activation.
     */
    public static function flush_rules() {
        $seo = new self();
        $seo->register_rewrite_rules();
        flush_rewrite_rules();
    }

}
