<?php
/**
 * Plugin Name: Monaco Monuments — Site Hardening & SEO
 * Description: Security headers, SEO meta, schema markup, and WordPress hardening for monacomonuments.ca
 * Version: 1.0.0
 * Author: Monaco Monuments
 *
 * INSTALLATION:
 *   Upload this file to: wp-content/mu-plugins/monaco-site-hardening.php
 *   Must-use plugins auto-activate — no enable step needed.
 *
 * WHAT THIS FILE DOES:
 *   1. Security headers (HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy)
 *   2. Hides WordPress version from HTML and RSS
 *   3. Disables XML-RPC (brute-force protection)
 *   4. Restricts REST API user enumeration
 *   5. Fixes the homepage <title> tag
 *   6. Adds meta descriptions to key pages
 *   7. Adds OpenGraph + Twitter Card tags for social sharing
 *   8. Adds LocalBusiness schema markup (JSON-LD)
 *   9. Redirects /ontario-obituaries/ to /obituaries/ (duplicate content fix)
 *  10. Drafts theme demo content on first run
 *
 * @package MonacoMonuments
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─────────────────────────────────────────────
// 1. SECURITY HEADERS
// ─────────────────────────────────────────────
function monaco_send_security_headers() {
    if ( headers_sent() ) {
        return;
    }
    header( 'X-Content-Type-Options: nosniff' );
    header( 'X-Frame-Options: SAMEORIGIN' );
    header( 'Referrer-Policy: strict-origin-when-cross-origin' );
    header( 'X-XSS-Protection: 1; mode=block' );
    // HSTS — only on HTTPS (which monacomonuments.ca uses)
    if ( is_ssl() ) {
        header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains' );
    }
}
add_action( 'send_headers', 'monaco_send_security_headers' );

// ─────────────────────────────────────────────
// 2. HIDE WORDPRESS VERSION
// ─────────────────────────────────────────────
remove_action( 'wp_head', 'wp_generator' );
add_filter( 'the_generator', '__return_empty_string' );

// ─────────────────────────────────────────────
// 3. DISABLE XML-RPC
// ─────────────────────────────────────────────
// NOTE: If you ever need the WordPress mobile app or Jetpack, comment out this line.
add_filter( 'xmlrpc_enabled', '__return_false' );

// Remove the XML-RPC link from <head>
remove_action( 'wp_head', 'rsd_link' );
remove_action( 'wp_head', 'wlwmanifest_link' );

// ─────────────────────────────────────────────
// 4. RESTRICT REST API USER ENUMERATION
// ─────────────────────────────────────────────
function monaco_restrict_rest_users( $endpoints ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        unset( $endpoints['/wp/v2/users'] );
        unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
    }
    return $endpoints;
}
add_filter( 'rest_endpoints', 'monaco_restrict_rest_users' );

// Block ?author=N enumeration too
function monaco_block_author_enum() {
    if ( ! is_admin() && isset( $_GET['author'] ) && ! current_user_can( 'manage_options' ) ) {
        wp_safe_redirect( home_url(), 302 );
        exit;
    }
}
add_action( 'template_redirect', 'monaco_block_author_enum' );

// ─────────────────────────────────────────────
// 5. FIX HOMEPAGE TITLE TAG
// ─────────────────────────────────────────────
function monaco_custom_page_titles( $title_parts ) {
    if ( is_front_page() || is_home() ) {
        $title_parts['title'] = 'Monaco Monuments';
        $title_parts['tagline'] = 'Custom Headstones, Monuments & Memorials in Ontario';
    }
    // Clean up default tagline for other pages
    if ( isset( $title_parts['site'] ) && 'monacomonuments.ca' === $title_parts['site'] ) {
        $title_parts['site'] = 'Monaco Monuments';
    }
    return $title_parts;
}
add_filter( 'document_title_parts', 'monaco_custom_page_titles', 20 );

// Ensure the title tag separator is a dash
add_filter( 'document_title_separator', function() { return '—'; } );

// ─────────────────────────────────────────────
// 6. META DESCRIPTIONS FOR KEY PAGES
// ─────────────────────────────────────────────
function monaco_get_meta_description() {
    $descriptions = array(
        'front'       => 'Monaco Monuments crafts custom headstones, monuments, and memorials across Ontario. Serving families with compassion and quality craftsmanship since our founding.',
        'obituaries'  => 'Browse recent Ontario obituaries. Monaco Monuments provides compassionate memorial services and custom headstones for families across the province.',
        'contact'     => 'Contact Monaco Monuments for custom headstones, monuments, and memorial services in Ontario. Request a free consultation today.',
        'about'       => 'Learn about Monaco Monuments — Ontario\'s trusted source for custom headstones, monuments, and memorial craftsmanship.',
        'gallery'     => 'View our gallery of custom headstones, monuments, and memorials crafted by Monaco Monuments for Ontario families.',
        'shop'        => 'Shop Monaco Monuments for headstones, memorial products, and monument accessories. Quality craftsmanship delivered across Ontario.',
        'catalog'     => 'Browse the Monaco Monuments product catalog. Custom headstones, granite monuments, bronze plaques, and memorial accessories.',
        'blog'        => 'Read articles about monument care, memorial traditions, grief support, and headstone design from Monaco Monuments.',
        'privacy'     => 'Privacy policy for Monaco Monuments (monacomonuments.ca). Learn how we handle your personal information.',
    );

    if ( is_front_page() || is_home() ) {
        return $descriptions['front'];
    }

    if ( is_page() ) {
        $slug = get_post_field( 'post_name', get_the_ID() );
        $map = array(
            'obituaries'     => 'obituaries',
            'contact'        => 'contact',
            'about'          => 'about',
            'about-us'       => 'about',
            'gallery'        => 'gallery',
            'shop'           => 'shop',
            'catalog'        => 'catalog',
            'blog'           => 'blog',
            'privacy-policy' => 'privacy',
        );
        if ( isset( $map[ $slug ] ) ) {
            return $descriptions[ $map[ $slug ] ];
        }
    }

    return '';
}

function monaco_output_meta_description() {
    // Don't output if an SEO plugin is active (Yoast, RankMath, etc.)
    if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) ) {
        return;
    }
    // Don't output on obituary SEO pages (handled by Ontario_Obituaries_SEO)
    if ( get_query_var( 'ontario_obituaries_hub' ) ) {
        return;
    }

    $desc = monaco_get_meta_description();
    if ( ! empty( $desc ) ) {
        echo '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
    }
}
add_action( 'wp_head', 'monaco_output_meta_description', 1 );

// ─────────────────────────────────────────────
// 7. OPENGRAPH + TWITTER CARD TAGS
// ─────────────────────────────────────────────
function monaco_output_opengraph_tags() {
    // Don't output if an SEO plugin handles this
    if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) ) {
        return;
    }
    // Don't output on obituary SEO pages (handled by Ontario_Obituaries_SEO)
    if ( get_query_var( 'ontario_obituaries_hub' ) ) {
        return;
    }
    // Skip 404 pages (no meaningful URL or content to share)
    if ( is_404() ) {
        return;
    }

    $site_name   = 'Monaco Monuments';
    $title       = wp_get_document_title();
    $description = monaco_get_meta_description();
    $url         = is_front_page() ? home_url( '/' ) : get_permalink();

    // Try to get a featured image or fallback to logo
    $image = '';
    if ( is_singular() && has_post_thumbnail() ) {
        $image = get_the_post_thumbnail_url( null, 'large' );
    }
    // Fallback: use the site's custom logo if set
    if ( empty( $image ) ) {
        $custom_logo_id = get_theme_mod( 'custom_logo' );
        if ( $custom_logo_id ) {
            $image = wp_get_attachment_image_url( $custom_logo_id, 'full' );
        }
    }

    // OpenGraph
    echo '<meta property="og:site_name" content="' . esc_attr( $site_name ) . '" />' . "\n";
    echo '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
    echo '<meta property="og:url" content="' . esc_url( $url ) . '" />' . "\n";
    echo '<meta property="og:type" content="website" />' . "\n";
    if ( ! empty( $description ) ) {
        echo '<meta property="og:description" content="' . esc_attr( $description ) . '" />' . "\n";
    }
    if ( ! empty( $image ) ) {
        echo '<meta property="og:image" content="' . esc_url( $image ) . '" />' . "\n";
    }
    echo '<meta property="og:locale" content="en_CA" />' . "\n";

    // Twitter Card
    echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
    echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '" />' . "\n";
    if ( ! empty( $description ) ) {
        echo '<meta name="twitter:description" content="' . esc_attr( $description ) . '" />' . "\n";
    }
    if ( ! empty( $image ) ) {
        echo '<meta name="twitter:image" content="' . esc_url( $image ) . '" />' . "\n";
    }
}
add_action( 'wp_head', 'monaco_output_opengraph_tags', 2 );

// ─────────────────────────────────────────────
// 8. LOCALBUSINESS SCHEMA MARKUP (JSON-LD)
// ─────────────────────────────────────────────
function monaco_output_localbusiness_schema() {
    // Only on the homepage
    if ( ! is_front_page() && ! is_home() ) {
        return;
    }
    // Don't output if an SEO plugin handles schema
    if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) ) {
        return;
    }

    $schema = array(
        '@context'    => 'https://schema.org',
        '@type'       => 'LocalBusiness',
        'name'        => 'Monaco Monuments',
        'description' => 'Custom headstones, monuments, and memorials crafted with compassion for Ontario families.',
        'url'         => home_url( '/' ),
        'telephone'   => '+1-905-392-0778',
        'address'     => array(
            '@type'           => 'PostalAddress',
            'streetAddress'   => '1190 Twinney Dr. Unit #8',
            'addressLocality' => 'Newmarket',
            'addressRegion'   => 'ON',
            'addressCountry'  => 'CA',
            // 'postalCode'   => 'L3Y 9E3', // Uncomment when confirmed
        ),
        'geo' => array(
            '@type'     => 'GeoCoordinates',
            'latitude'  => '44.063462',
            'longitude' => '-79.427102',
        ),
        'areaServed'  => array(
            '@type' => 'State',
            'name'  => 'Ontario',
        ),
        'priceRange'  => '$$',
        // Uncomment and add your social media URLs:
        // 'sameAs' => array(
        //     'https://www.facebook.com/monacomonuments',
        //     'https://www.instagram.com/monacomonuments',
        // ),
    );

    // Try to get logo
    $custom_logo_id = get_theme_mod( 'custom_logo' );
    if ( $custom_logo_id ) {
        $logo_url = wp_get_attachment_image_url( $custom_logo_id, 'full' );
        if ( $logo_url ) {
            $schema['logo'] = $logo_url;
            $schema['image'] = $logo_url;
        }
    }

    // Remove empty values
    $schema = array_filter( $schema, function( $v ) {
        return ! empty( $v ) && $v !== array();
    });

    echo '<script type="application/ld+json">' . "\n";
    echo wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
    echo "\n" . '</script>' . "\n";
}
add_action( 'wp_head', 'monaco_output_localbusiness_schema', 3 );

// ─────────────────────────────────────────────
// 9. REDIRECT /obituaries/ → /ontario-obituaries/
//    The /obituaries/ page is a blank Elementor placeholder.
//    The real shortcode listing lives at /ontario-obituaries/.
//    Also redirect the old /ontario-obituaries/ slug kept for
//    backwards compatibility via the SEO hub (/obituaries/ontario/).
// ─────────────────────────────────────────────
function monaco_redirect_duplicate_obituaries() {
    // /obituaries/ Elementor page → real listing page
    if ( is_page( 'obituaries' ) && ! get_query_var( 'ontario_obituaries_hub' ) && ! get_query_var( 'ontario_obituaries_seo' ) ) {
        wp_safe_redirect( home_url( '/ontario-obituaries/' ), 301 );
        exit;
    }
}
add_action( 'template_redirect', 'monaco_redirect_duplicate_obituaries' );

// ─────────────────────────────────────────────
// 10. AUTO-DRAFT THEME DEMO CONTENT (runs once)
// ─────────────────────────────────────────────
function monaco_cleanup_demo_content() {
    // Only run once
    if ( get_option( 'monaco_demo_cleanup_done' ) ) {
        return;
    }
    // Only run in admin context to avoid frontend slowdown
    if ( ! is_admin() ) {
        return;
    }

    // Litho theme demo pages to draft
    $demo_page_slugs = array(
        'home-hotel-resort', 'home-gym-fitness', 'home-corporate',
        'home-digital-agency', 'home-creative-agency', 'home-freelancer',
        'home-creative-portfolio', 'home-event-conference',
        'who-we-are', 'contact-simple', 'contact-classic', 'contact-modern',
        'maintenance', 'coming-soon', 'coming-soon-v2', 'subscribe',
        'google-map', 'contact-form', 'icon-with-text', 'custom-icon-with-text',
        'counters', 'countdown', 'fancy-text-box', 'heading', 'drop-caps',
        'columns', 'highlights', 'dark-header', 'header-with-top-bar',
        'header-with-push', 'center-navigation', 'center-logo',
        'hamburger-menu', 'hamburger-menu-modern', 'hamburger-menu-half',
        'disable-fixed', 'mobile-menu-modern',
        'footer-style-01', 'footer-style-02', 'footer-style-03',
        'footer-style-04', 'footer-style-05', 'footer-style-06',
        'footer-style-07', 'footer-style-08', 'footer-style-09',
        'footer-style-10', 'footer-style-11', 'footer-style-12',
        'page-title-center-alignment', 'page-title-gallery-background',
        'portfolio-switch-image-four-column',
    );

    // Demo blog post slugs
    $demo_post_slugs = array(
        'hello-world',
        'blog-post-layout-01', 'blog-post-layout-02', 'blog-post-layout-03',
        'blog-post-layout-04', 'blog-post-layout-05',
        'blog-standard-post', 'blog-gallery-post', 'blog-slider-post',
        'blog-html5-video-post', 'blog-youtube-video-post',
        'blog-vimeo-video-post', 'blog-audio-post', 'blog-blockquote-post',
        'blog-full-width-post',
        'reason-and-judgment-are-the-qualities-of-a-leader',
        'computers-are-to-design-as-microwaves-cooking',
        'opportunities-dont-happen-you-create-them',
        'never-give-in-except-to-convictions-good-sense',
        'an-economists-guess-is-liable-good-as-anybody',
        'all-progress-takes-place-outside-the-comfort-zone',
        'creativity-is-nothing-but-a-mind-set-free',
    );

    $drafted = 0;

    // Draft demo pages
    foreach ( $demo_page_slugs as $slug ) {
        $page = get_page_by_path( $slug );
        if ( $page && 'publish' === $page->post_status ) {
            wp_update_post( array(
                'ID'          => $page->ID,
                'post_status' => 'draft',
            ) );
            $drafted++;
        }
    }

    // Draft demo posts
    foreach ( $demo_post_slugs as $slug ) {
        $posts = get_posts( array(
            'name'        => $slug,
            'post_type'   => 'post',
            'post_status' => 'publish',
            'numberposts' => 1,
        ) );
        if ( ! empty( $posts ) ) {
            wp_update_post( array(
                'ID'          => $posts[0]->ID,
                'post_status' => 'draft',
            ) );
            $drafted++;
        }
    }

    // Draft demo WooCommerce products (Litho theme dummy data)
    $demo_product_slugs = array(
        'simple-product', 'variable-product', 'cashmere-sweater',
        'cotton-polo-shirt', 'plain-multicolored', 'external-product',
        'cotton-dark-shirt', 'sale-product', 'grouped-product',
        'out-of-stock-product', 'winter-long-top', 'summer-short-sleeve',
    );

    foreach ( $demo_product_slugs as $slug ) {
        $products = get_posts( array(
            'name'        => $slug,
            'post_type'   => 'product',
            'post_status' => 'publish',
            'numberposts' => 1,
        ) );
        if ( ! empty( $products ) ) {
            wp_update_post( array(
                'ID'          => $products[0]->ID,
                'post_status' => 'draft',
            ) );
            $drafted++;
        }
    }

    // Mark as done so it only runs once
    update_option( 'monaco_demo_cleanup_done', true );

    if ( $drafted > 0 ) {
        error_log( sprintf( '[Monaco Hardening] Drafted %d demo pages/posts/products.', $drafted ) );
    }
}
add_action( 'admin_init', 'monaco_cleanup_demo_content' );

// ─────────────────────────────────────────────
// 11. EXCLUDE DEMO/INTERNAL PAGES FROM SITEMAP
// ─────────────────────────────────────────────
function monaco_exclude_pages_from_sitemap( $args, $post_type ) {
    // Exclude the duplicate obituaries page and any remaining demo pages
    if ( 'page' === $post_type ) {
        $exclude_slugs = array( 'ontario-obituaries' );
        $exclude_ids = array();
        foreach ( $exclude_slugs as $slug ) {
            $page = get_page_by_path( $slug );
            if ( $page ) {
                $exclude_ids[] = $page->ID;
            }
        }
        if ( ! empty( $exclude_ids ) ) {
            $args['post__not_in'] = isset( $args['post__not_in'] )
                ? array_merge( $args['post__not_in'], $exclude_ids )
                : $exclude_ids;
        }
    }
    return $args;
}
add_filter( 'wp_sitemaps_posts_query_args', 'monaco_exclude_pages_from_sitemap', 10, 2 );

// ─────────────────────────────────────────────
// 12. DISABLE AUTHOR ARCHIVES (prevent user enumeration via URLs)
// ─────────────────────────────────────────────
function monaco_disable_author_archives() {
    if ( is_author() ) {
        wp_safe_redirect( home_url(), 301 );
        exit;
    }
}
add_action( 'template_redirect', 'monaco_disable_author_archives' );

// Exclude author pages from sitemap
function monaco_remove_users_from_sitemap( $provider, $name ) {
    if ( 'users' === $name ) {
        return false;
    }
    return $provider;
}
add_filter( 'wp_sitemaps_add_provider', 'monaco_remove_users_from_sitemap', 10, 2 );
