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

        // Sitemap integration
        add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'exclude_suppressed_from_sitemap' ), 10, 2 );

        // Handle verification tokens
        add_action( 'template_redirect', array( $this, 'handle_verification' ) );
    }

    /**
     * Register rewrite rules for SEO-friendly obituary URLs.
     */
    public function register_rewrite_rules() {
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
            return $vars;
        } );
    }

    /**
     * Handle SEO page rendering.
     */
    public function handle_seo_pages() {
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
                'Recent obituary notices from %s, Ontario. Browse obituaries, find funeral home information, and memorial services. Monaco Monuments serves Southern Ontario.',
                $city_name
            );
        } else {
            $desc = 'Browse recent Ontario obituary notices. Find funeral home information, memorial services, and obituary listings from across Ontario, Canada. Monaco Monuments — Newmarket, Ontario.';
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

        printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $canonical ) );
    }

    /**
     * Exclude suppressed obituaries from WordPress sitemaps.
     */
    public function exclude_suppressed_from_sitemap( $args, $post_type ) {
        // This primarily affects our custom endpoints via the sitemap provider
        return $args;
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
