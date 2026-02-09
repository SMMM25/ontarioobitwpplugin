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

        // v3.10.2 PR #24b: Intercept template loading for SEO pages.
        // Uses a custom wrapper template (Pattern 2: full HTML shell) instead
        // of the theme's header.php / footer.php, which render incorrect
        // Litho demo navigation instead of the Monaco Monuments header.
        add_filter( 'template_include', array( $this, 'maybe_use_seo_wrapper_template' ), 99 );

        // Prevent WordPress from resolving the 'obituaries' page for our SEO URLs.
        // WP tries to resolve /obituaries/ontario/ as child of the 'obituaries' page,
        // which overrides our rewrite rules. This filter intercepts that resolution.
        add_filter( 'pre_handle_404', array( $this, 'prevent_false_404' ), 10, 2 );
        add_action( 'parse_request', array( $this, 'intercept_obituary_seo_urls' ), 1 );

        // Inject Schema.org JSON-LD
        add_action( 'wp_head', array( $this, 'output_schema_markup' ) );

        // Custom <title> tags
        add_filter( 'document_title_parts', array( $this, 'custom_page_title' ) );

        // Meta description
        add_action( 'wp_head', array( $this, 'output_meta_description' ) );

        // Canonical URLs
        add_action( 'wp_head', array( $this, 'output_canonical_url' ) );

        // OpenGraph meta tags for social sharing
        add_action( 'wp_head', array( $this, 'output_opengraph_tags' ) );

        // v3.10.2 PR #24b: Noindex output for individual pages.
        // Controlled deterministically via query var set in handle_seo_pages().
        add_action( 'wp_head', array( $this, 'output_noindex_tag' ) );

        // Sitemap integration — custom provider for obituary SEO URLs
        add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'exclude_suppressed_from_sitemap' ), 10, 2 );
        add_filter( 'wp_sitemaps_add_provider', array( $this, 'register_sitemap_provider' ), 10, 2 );

        // Handle verification tokens
        add_action( 'template_redirect', array( $this, 'handle_verification' ) );

        // v3.10.0: Fix Litho theme layout on SEO pages.
        // Swaps 'blog' → 'page' body class so Litho uses page template.
        // Also hides the Litho page-title section (our templates have their own <h1>).
        add_filter( 'body_class', array( $this, 'fix_litho_body_class' ) );
        add_action( 'wp_head', array( $this, 'inject_litho_layout_fix_css' ), 1 );

        // v3.10.2 PR #24b: Prevent WordPress canonical redirect on SEO virtual pages.
        // WP's redirect_canonical() can rewrite /obituaries/ontario/... back to the
        // /obituaries/ page (or add trailing slashes / remove query strings), which
        // breaks our virtual pages. Disabling it on SEO routes is standard practice
        // for virtual page plugins.
        add_filter( 'redirect_canonical', array( $this, 'disable_canonical_redirect_on_seo_pages' ), 10, 2 );
    }

    /**
     * Intercept requests that match our SEO URL patterns.
     *
     * WordPress's page resolution (`pagename` query var) matches /obituaries/ontario/
     * as a child page of the 'obituaries' page, which prevents our rewrite rules
     * from firing. This hook detects the pattern in the raw request and forces
     * the correct query vars.
     *
     * @param WP $wp WordPress request object.
     */
    public function intercept_obituary_seo_urls( $wp ) {
        $request = trim( $wp->request, '/' );

        // Match: obituaries-sitemap.xml
        if ( preg_match( '#^obituaries-sitemap\.xml$#', $request ) ) {
            $wp->query_vars['ontario_obituaries_sitemap'] = '1';
            // Clear the pagename if WP set it
            unset( $wp->query_vars['pagename'] );
            unset( $wp->query_vars['page'] );
            return;
        }

        // Match: obituaries/ontario/{city}/{slug}-{id}/
        if ( preg_match( '#^obituaries/ontario/([a-z0-9-]+)/([a-z0-9-]+)-(\d+)/?$#', $request, $m ) ) {
            $wp->query_vars['ontario_obituaries_hub']  = 'ontario';
            $wp->query_vars['ontario_obituaries_city'] = $m[1];
            $wp->query_vars['ontario_obituaries_id']   = $m[3];
            unset( $wp->query_vars['pagename'] );
            unset( $wp->query_vars['page'] );
            return;
        }

        // Match: obituaries/ontario/{city}/
        if ( preg_match( '#^obituaries/ontario/([a-z0-9-]+)/?$#', $request, $m ) ) {
            $wp->query_vars['ontario_obituaries_hub']  = 'ontario';
            $wp->query_vars['ontario_obituaries_city'] = $m[1];
            unset( $wp->query_vars['pagename'] );
            unset( $wp->query_vars['page'] );
            return;
        }

        // Match: obituaries/ontario/
        if ( preg_match( '#^obituaries/ontario/?$#', $request ) ) {
            $wp->query_vars['ontario_obituaries_hub'] = 'ontario';
            unset( $wp->query_vars['pagename'] );
            unset( $wp->query_vars['page'] );
            return;
        }
    }

    /**
     * Prevent false 404s for our SEO pages.
     *
     * When WordPress resolves the request as a child page that doesn't exist,
     * it returns 404. This filter checks if our query vars are set and prevents it.
     *
     * @param bool     $preempt Whether to preempt the 404 handling.
     * @param WP_Query $query   The WP_Query instance.
     * @return bool
     */
    public function prevent_false_404( $preempt, $query ) {
        if ( ! empty( $query->query_vars['ontario_obituaries_hub'] ) ||
             ! empty( $query->query_vars['ontario_obituaries_sitemap'] ) ) {
            $query->is_404 = false;
            return true; // Prevent default 404 handling
        }
        return $preempt;
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
            // v3.10.2 PR #24b: Query vars for wrapper template rendering.
            $vars[] = 'ontario_obituaries_seo_mode';
            $vars[] = 'ontario_obituaries_seo_data';
            $vars[] = 'ontario_obituaries_header_template_ids';
            $vars[] = 'ontario_obituaries_footer_template_id';
            $vars[] = 'ontario_obituaries_noindex';
            return $vars;
        } );
    }

    /**
     * Handle SEO page rendering.
     */
    public function handle_seo_pages() {
        // Sitemap XML endpoint — outputs XML and exits (non-HTML).
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

        // Enqueue the plugin CSS on SEO pages so styles are consistent
        $css_file = ONTARIO_OBITUARIES_PLUGIN_DIR . 'assets/css/ontario-obituaries.css';
        $css_ver  = file_exists( $css_file ) ? filemtime( $css_file ) : ONTARIO_OBITUARIES_VERSION;
        wp_enqueue_style(
            'ontario-obituaries-css',
            ONTARIO_OBITUARIES_PLUGIN_URL . 'assets/css/ontario-obituaries.css',
            array(),
            $css_ver
        );

        // Verification tokens are handled by handle_verification() on template_redirect;
        // if a token is present on a hub URL, skip rendering and let that handler run.
        if ( isset( $_GET['ontario_obituaries_verify'] ) ) {
            return;
        }

        // LiteSpeed-specific cache control:
        // Hub and city pages get a moderate TTL (10 min); individual pages
        // get a longer TTL (1 hour) since their content rarely changes.
        // Tag-based purge headers let us selectively invalidate after scrape/dedup.
        if ( ! headers_sent() ) {
            if ( ! empty( $id ) ) {
                header( 'X-LiteSpeed-Cache-Control: public,max-age=3600' );
                header( 'X-LiteSpeed-Tag: ontario_obits,ontario_obits_individual,ontario_obits_id_' . intval( $id ) );
            } elseif ( ! empty( $city ) ) {
                header( 'X-LiteSpeed-Cache-Control: public,max-age=600' );
                header( 'X-LiteSpeed-Tag: ontario_obits,ontario_obits_city,ontario_obits_city_' . sanitize_title( $city ) );
            } else {
                header( 'X-LiteSpeed-Cache-Control: public,max-age=600' );
                header( 'X-LiteSpeed-Tag: ontario_obits,ontario_obits_hub' );
            }
        }

        // v3.10.2 PR #24b: Pass Elementor template IDs via query vars so the
        // wrapper template can render correct header/footer without instantiating
        // this class. IDs are overridable via constants or filters.
        set_query_var( 'ontario_obituaries_header_template_ids', $this->get_header_template_ids() );
        set_query_var( 'ontario_obituaries_footer_template_id', $this->get_footer_template_id() );

        // v3.10.2 PR #24b: Dispatch to render methods which now set query vars
        // instead of including templates directly. WordPress's template_include
        // filter (maybe_use_seo_wrapper_template) takes over rendering.
        if ( ! empty( $id ) ) {
            $this->render_individual_page( intval( $id ), $city );
        } elseif ( ! empty( $city ) ) {
            $this->render_city_hub( $city );
        } else {
            $this->render_ontario_hub();
        }
        // v3.10.2 PR #24b: No exit — let WordPress continue to template_include.
        // The wrapper template (templates/seo/wrapper.php) handles HTML output.
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

        // v3.10.2 PR #24b: Store data in query vars for wrapper template.
        set_query_var( 'ontario_obituaries_seo_mode', 'hub-ontario' );
        set_query_var( 'ontario_obituaries_seo_data', array(
            'city_stats' => $city_stats,
            'recent'     => $recent,
        ) );
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

        // v3.10.2 PR #24b: Store data in query vars for wrapper template.
        set_query_var( 'ontario_obituaries_seo_mode', 'hub-city' );
        set_query_var( 'ontario_obituaries_seo_data', array(
            'city_slug'   => $city_slug,
            'city_name'   => $city_name,
            'obituaries'  => $obituaries,
            'total'       => intval( $total ),
            'total_pages' => $total_pages,
            'page'        => $page,
            'per_page'    => $per_page,
        ) );
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
            // v3.10.2 PR #24b: Signal 404 to wrapper template.
            set_query_var( 'ontario_obituaries_seo_mode', '404' );
            return;
        }

        // noindex by default (can be overridden via enrichment flag)
        $should_index = ! empty( $obituary->description ) && strlen( $obituary->description ) > 100;

        // v3.10.2 PR #24b: Noindex controlled deterministically via query var.
        // The output_noindex_tag() method reads this during wp_head().
        if ( ! $should_index ) {
            set_query_var( 'ontario_obituaries_noindex', true );
        }

        // v3.10.2 PR #24b: Store data in query vars for wrapper template.
        set_query_var( 'ontario_obituaries_seo_mode', 'individual' );
        set_query_var( 'ontario_obituaries_seo_data', array(
            'obituary'     => $obituary,
            'city_slug'    => $city_slug,
            'should_index' => $should_index,
        ) );
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
        if ( ! empty( $obit->image_url ) ) {
            $schema['image'] = $obit->image_url;
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
        if ( ! empty( $obit->description ) ) {
            $schema['description'] = wp_trim_words( $obit->description, 50, '...' );
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

        // v3.3.0: Include the person's name in breadcrumbs for individual pages
        if ( ! empty( $id ) ) {
            global $wpdb;
            $table = $wpdb->prefix . 'ontario_obituaries';
            $obit  = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM `{$table}` WHERE id = %d", intval( $id ) ) );
            if ( $obit ) {
                $items[] = array(
                    'name' => $obit->name,
                    'url'  => '', // current page — no link
                );
            }
        }

        $schema = array(
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => array(),
        );

        foreach ( $items as $i => $item ) {
            $entry = array(
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => $item['name'],
            );
            // v3.3.0: Omit 'item' for the last breadcrumb (current page)
            if ( ! empty( $item['url'] ) ) {
                $entry['item'] = $item['url'];
            }
            $schema['itemListElement'][] = $entry;
        }

        echo "\n<script type=\"application/ld+json\">\n" . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . "\n</script>\n";
    }

    /**
     * Custom page titles.
     */
    public function custom_page_title( $title_parts ) {
        $hub  = get_query_var( 'ontario_obituaries_hub' );
        $city = get_query_var( 'ontario_obituaries_city' );
        $id   = get_query_var( 'ontario_obituaries_id' );

        if ( empty( $hub ) ) {
            return $title_parts;
        }

        // Individual obituary page — use the person's name
        if ( ! empty( $id ) ) {
            global $wpdb;
            $table = $wpdb->prefix . 'ontario_obituaries';
            $obit  = $wpdb->get_row( $wpdb->prepare( "SELECT name, city_normalized, location FROM `{$table}` WHERE id = %d", intval( $id ) ) );
            if ( $obit ) {
                $city_name = ! empty( $obit->city_normalized ) ? $obit->city_normalized : $obit->location;
                $title_parts['title'] = sprintf( '%s — %s, Ontario | Monaco Monuments', $obit->name, $city_name );
            } else {
                $title_parts['title'] = 'Obituary — Ontario | Monaco Monuments';
            }
        } elseif ( ! empty( $city ) ) {
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
        $id   = get_query_var( 'ontario_obituaries_id' );

        if ( empty( $hub ) ) {
            return;
        }

        // Individual obituary — person-specific description
        if ( ! empty( $id ) ) {
            global $wpdb;
            $table = $wpdb->prefix . 'ontario_obituaries';
            $obit  = $wpdb->get_row( $wpdb->prepare(
                "SELECT name, description, city_normalized, location, date_of_death FROM `{$table}` WHERE id = %d",
                intval( $id )
            ) );
            if ( $obit ) {
                $city_name = ! empty( $obit->city_normalized ) ? $obit->city_normalized : $obit->location;
                $snippet   = ! empty( $obit->description ) ? wp_trim_words( $obit->description, 25, '...' ) : '';
                $desc = sprintf(
                    'Obituary for %s of %s, Ontario (%s). %s Monaco Monuments — Newmarket, serving York Region.',
                    $obit->name,
                    $city_name,
                    date_i18n( 'F j, Y', strtotime( $obit->date_of_death ) ),
                    $snippet
                );
            } else {
                $desc = 'Obituary notice from Ontario, Canada. Monaco Monuments — Newmarket, serving York Region & Southern Ontario.';
            }
        } elseif ( ! empty( $city ) ) {
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
                    esc_attr( gmdate( 'Y-m-d', strtotime( $city->latest ) ) )
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
                $city_raw  = ! empty( $obit->city_normalized ) ? $obit->city_normalized : $obit->location;
                $city_slug = sanitize_title( $city_raw );

                // Skip obituaries with no city — produces malformed /ontario//name/ URLs
                if ( empty( $city_slug ) ) {
                    continue;
                }

                $name_slug = sanitize_title( $obit->name ) . '-' . $obit->id;
                printf(
                    "<url><loc>%s</loc><lastmod>%s</lastmod><changefreq>weekly</changefreq><priority>0.5</priority></url>\n",
                    esc_url( home_url( '/obituaries/ontario/' . $city_slug . '/' . $name_slug . '/' ) ),
                    esc_attr( gmdate( 'Y-m-d', strtotime( $obit->date_of_death ) ) )
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
     * Output OpenGraph meta tags for social sharing.
     *
     * v3.3.0: Ensures shared links on Facebook/Twitter show proper
     * preview cards with the correct title, description, and image.
     */
    public function output_opengraph_tags() {
        $hub  = get_query_var( 'ontario_obituaries_hub' );
        $city = get_query_var( 'ontario_obituaries_city' );
        $id   = get_query_var( 'ontario_obituaries_id' );

        if ( empty( $hub ) ) {
            return;
        }

        $og = array(
            'og:site_name' => 'Monaco Monuments',
            'og:locale'    => 'en_CA',
        );

        if ( ! empty( $id ) ) {
            global $wpdb;
            $table = $wpdb->prefix . 'ontario_obituaries';
            $obit  = $wpdb->get_row( $wpdb->prepare(
                "SELECT name, description, city_normalized, location, image_url, date_of_death FROM `{$table}` WHERE id = %d",
                intval( $id )
            ) );
            if ( $obit ) {
                $city_name       = ! empty( $obit->city_normalized ) ? $obit->city_normalized : $obit->location;
                $og['og:type']   = 'article';
                $og['og:title']  = sprintf( '%s — %s, Ontario | Monaco Monuments', $obit->name, $city_name );
                $og['og:description'] = ! empty( $obit->description )
                    ? wp_trim_words( $obit->description, 30, '...' )
                    : sprintf( 'Obituary for %s of %s, Ontario.', $obit->name, $city_name );
                if ( ! empty( $obit->image_url ) ) {
                    $og['og:image'] = $obit->image_url;
                }
                $city_slug = sanitize_title( $city_name );
                if ( empty( $city_slug ) ) {
                    $city_slug = 'ontario';
                }
                $name_slug = sanitize_title( $obit->name ) . '-' . intval( $id );
                $og['og:url'] = home_url( '/obituaries/ontario/' . $city_slug . '/' . $name_slug . '/' );
            }
        } elseif ( ! empty( $city ) ) {
            $city_name              = ucwords( str_replace( '-', ' ', $city ) );
            $og['og:type']          = 'website';
            $og['og:title']         = sprintf( '%s Obituaries — Ontario | Monaco Monuments', $city_name );
            $og['og:description']   = sprintf( 'Recent obituary notices from %s, Ontario.', $city_name );
            $og['og:url']           = home_url( '/obituaries/ontario/' . sanitize_title( $city ) . '/' );
        } else {
            $og['og:type']        = 'website';
            $og['og:title']       = 'Ontario Obituaries — Recent Notices | Monaco Monuments';
            $og['og:description'] = 'Browse recent Ontario obituary notices from York Region, Newmarket, Toronto & across Ontario.';
            $og['og:url']         = home_url( '/obituaries/ontario/' );
        }

        foreach ( $og as $property => $content ) {
            if ( ! empty( $content ) ) {
                printf( '<meta property="%s" content="%s">' . "\n", esc_attr( $property ), esc_attr( $content ) );
            }
        }
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
     * Check if the current request is an Ontario Obituaries SEO page.
     *
     * Condition 4 hardening: ontario_obituaries_hub is a registered query var,
     * so WordPress will accept it from querystrings on ANY URL (e.g.
     * ?ontario_obituaries_hub=ontario appended to /contact/). To prevent the
     * Litho fix from firing on unintended pages, we verify both:
     *   (a) the query var is set, AND
     *   (b) the request URI actually matches our SEO route pattern.
     *
     * @return bool True if this is a genuine obituary SEO page request.
     */
    private function is_obituary_seo_request() {
        $hub = get_query_var( 'ontario_obituaries_hub' );
        if ( empty( $hub ) ) {
            return false;
        }

        // Belt-and-suspenders: also verify the request URI matches our pattern.
        // This prevents querystring injection on unrelated pages.
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
        $path        = trim( wp_parse_url( $request_uri, PHP_URL_PATH ), '/' );

        return (bool) preg_match( '#^obituaries/ontario(/|$)#', $path );
    }

    /**
     * v3.10.0: Fix Litho theme body class for SEO pages.
     *
     * The Litho theme checks for the 'blog' body class and renders a
     * blog-specific layout (title bar with "Blog" heading + subtitle).
     * Our SEO pages are virtual (no real WP post), so WordPress defaults
     * to the blog context. Swapping 'blog' → 'page' tells Litho to use
     * its standard page layout — matching the Monaco Monuments design
     * seen on /ontario-obituaries/ and other polished pages.
     *
     * @param array $classes Body CSS classes.
     * @return array Modified classes.
     */
    public function fix_litho_body_class( $classes ) {
        if ( ! $this->is_obituary_seo_request() ) {
            return $classes;
        }

        // Remove 'blog' and add 'page' so Litho renders page layout.
        $classes = array_diff( $classes, array( 'blog', 'archive', 'paged' ) );
        if ( ! in_array( 'page', $classes, true ) ) {
            $classes[] = 'page';
        }

        // Add a custom class for targeted CSS.
        $classes[] = 'ontario-obituaries-seo-page';

        return $classes;
    }

    /**
     * v3.10.0: Inject CSS to hide Litho's page-title section on SEO pages.
     *
     * The Litho theme renders a page-title area (with heading "Blog" and
     * subtitle "Short tagline goes here") on blog-context pages. Even after
     * swapping the body class, some Litho configurations still output this
     * bar. This CSS hides it specifically on our SEO pages so users see
     * our plugin's own <h1> and breadcrumbs instead of the Litho blog bar.
     */
    public function inject_litho_layout_fix_css() {
        if ( ! $this->is_obituary_seo_request() ) {
            return;
        }

        echo "\n<style id=\"ontario-obituaries-litho-fix\">\n";
        echo "/* v3.10.0: Hide Litho blog page-title bar on Ontario Obituaries SEO pages.\n";
        echo "   The Litho theme wraps its page-title bar in a <section> with class\n";
        echo "   'litho-main-title-wrappper' (triple 'p' — typo in the theme).\n";
        echo "   We hide the outer wrapper and all known inner selectors. */\n";
        echo ".ontario-obituaries-seo-page .litho-main-title-wrappper,\n";
        echo ".ontario-obituaries-seo-page .litho-main-title-wrap,\n";
        echo ".ontario-obituaries-seo-page .page-title-section,\n";
        echo ".ontario-obituaries-seo-page .litho-page-title-wrap {\n";
        echo "    display: none !important;\n";
        echo "}\n";
        echo "</style>\n";
    }

    /**
     * v3.10.2 PR #24b: Intercept WordPress template loading for SEO pages.
     *
     * Returns the path to our custom wrapper template (Pattern 2: full HTML shell)
     * which renders the correct Monaco Monuments Elementor header/footer by ID,
     * instead of relying on the theme's get_header() / get_footer() which
     * produce the wrong Litho demo navigation on virtual pages.
     *
     * Safety gate: requires BOTH is_obituary_seo_request() AND
     * ontario_obituaries_seo_mode to be set (prevents activation on
     * sitemap/verification paths).
     *
     * @param string $template The path of the template to include.
     * @return string Modified or original template path.
     */
    public function maybe_use_seo_wrapper_template( $template ) {
        if ( ! $this->is_obituary_seo_request() ) {
            return $template;
        }

        // Only intercept if handle_seo_pages() set the mode (not sitemap/verification).
        $mode = get_query_var( 'ontario_obituaries_seo_mode', '' );
        if ( empty( $mode ) ) {
            return $template;
        }

        $wrapper = ONTARIO_OBITUARIES_PLUGIN_DIR . 'templates/seo/wrapper.php';
        return file_exists( $wrapper ) ? $wrapper : $template;
    }

    /**
     * v3.10.2 PR #24b: Output noindex meta tag for individual obituary pages.
     *
     * Controlled deterministically via query var set in render_individual_page().
     * Replaces the anonymous closure that was previously in individual.php.
     */
    public function output_noindex_tag() {
        if ( get_query_var( 'ontario_obituaries_noindex' ) ) {
            echo '<meta name="robots" content="noindex, follow">' . "\n";
        }
    }

    /**
     * v3.10.2 PR #24b: Get Elementor header template IDs (Monaco Monuments).
     *
     * Default IDs correspond to the Monaco Monuments production site:
     *   - 13039: Mini-header (top bar with phone/hours)
     *   - 15223: Main header (navigation menu + logo)
     *
     * Override via:
     *   - Constant: define( 'ONTARIO_OBITUARIES_HEADER_IDS', array( 13039, 15223 ) );
     *   - Filter:   add_filter( 'ontario_obituaries_header_template_ids', $callback );
     *
     * @return array Elementor post IDs for header templates.
     */
    private function get_header_template_ids() {
        $ids = defined( 'ONTARIO_OBITUARIES_HEADER_IDS' )
            ? ONTARIO_OBITUARIES_HEADER_IDS
            : array( 13039, 15223 );
        return apply_filters( 'ontario_obituaries_header_template_ids', $ids );
    }

    /**
     * v3.10.2 PR #24b: Get Elementor footer template ID (Monaco Monuments).
     *
     * Default ID corresponds to the Monaco Monuments production footer (35674).
     *
     * Override via:
     *   - Constant: define( 'ONTARIO_OBITUARIES_FOOTER_ID', 35674 );
     *   - Filter:   add_filter( 'ontario_obituaries_footer_template_id', $callback );
     *
     * @return int Elementor post ID for footer template.
     */
    private function get_footer_template_id() {
        $id = defined( 'ONTARIO_OBITUARIES_FOOTER_ID' )
            ? ONTARIO_OBITUARIES_FOOTER_ID
            : 35674;
        return apply_filters( 'ontario_obituaries_footer_template_id', $id );
    }

    /**
     * v3.10.2 PR #24b: Prevent canonical redirect on SEO virtual pages.
     *
     * WordPress's redirect_canonical() can rewrite /obituaries/ontario/...
     * back to the /obituaries/ page or apply trailing-slash / query-string
     * normalization that breaks our virtual page routing. This is a standard
     * requirement for any plugin that serves virtual pages via rewrite rules.
     *
     * @param string $redirect_url  The redirect target URL.
     * @param string $requested_url The originally requested URL.
     * @return string|false False to cancel redirect, or the redirect URL.
     */
    public function disable_canonical_redirect_on_seo_pages( $redirect_url, $requested_url ) {
        if ( $this->is_obituary_seo_request() ) {
            return false;
        }
        return $redirect_url;
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
