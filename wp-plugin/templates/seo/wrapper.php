<?php
/**
 * SEO Wrapper Template -- Pattern 2: Custom HTML Shell
 *
 * v3.10.2 PR #24b: Renders a complete HTML document for SEO virtual pages
 * (/obituaries/ontario/...) with the correct Monaco Monuments Elementor
 * header and footer templates, bypassing the Litho theme's get_header() /
 * get_footer() which produce incorrect demo navigation.
 *
 * v3.12.0 FIX (Goal B): Added Elementor Pro Theme Builder suppression to
 * prevent duplicate header/footer rendering on SEO pages. When Elementor Pro
 * Theme Builder has site-wide header/footer conditions, it auto-injects them
 * via get_header/get_footer actions. Since this template renders headers
 * manually by ID, the auto-injection caused triple logos. The fix removes
 * the Theme Builder's auto-injection hooks before rendering.
 *
 * This template:
 *   - Outputs a valid HTML5 document (DOCTYPE, html, head, body)
 *   - Calls wp_head() for all enqueued styles, scripts, and meta (Schema.org,
 *     OG tags, canonical URLs, noindex, Elementor CSS, Google Fonts, etc.)
 *   - Renders Elementor Section Builder templates by ID for header/footer
 *   - Includes the content-only partial (hub-ontario, hub-city, or individual)
 *   - Calls wp_footer() for deferred scripts and Elementor frontend JS
 *   - Falls back gracefully if Elementor is unavailable (minimal nav/footer)
 *
 * This template reads ALL data from query vars set by handle_seo_pages().
 * It does NOT instantiate Ontario_Obituaries_SEO or any plugin class.
 *
 * Query vars consumed:
 *   - ontario_obituaries_seo_mode:          'hub-ontario'|'hub-city'|'individual'|'404'
 *   - ontario_obituaries_seo_data:          array of template variables
 *   - ontario_obituaries_header_template_ids: array of Elementor post IDs
 *   - ontario_obituaries_footer_template_id:  int Elementor post ID
 *
 * @package Ontario_Obituaries
 * @since   3.10.2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// --- Read query vars ---------------------------------------------------------
$seo_mode    = get_query_var( 'ontario_obituaries_seo_mode', '' );
$seo_data    = get_query_var( 'ontario_obituaries_seo_data', array() );
$header_ids  = get_query_var( 'ontario_obituaries_header_template_ids', array() );
$footer_id   = get_query_var( 'ontario_obituaries_footer_template_id', 0 );

// --- 404 handling ------------------------------------------------------------
if ( '404' === $seo_mode ) {
    include get_404_template();
    return;
}

// --- Resolve content partial -------------------------------------------------
$partials = array(
    'hub-ontario' => 'hub-ontario.php',
    'hub-city'    => 'hub-city.php',
    'individual'  => 'individual.php',
);

if ( ! isset( $partials[ $seo_mode ] ) ) {
    include get_404_template();
    return;
}

$content_template = ONTARIO_OBITUARIES_PLUGIN_DIR . 'templates/seo/' . $partials[ $seo_mode ];

// --- Extract data vars for partial templates ---------------------------------
// Safe initialization with defaults for ALL variables the partials reference.
// This prevents PHP undefined-variable notices if data is missing or incomplete.
// hub-ontario: $city_stats, $recent
// hub-city:    $city_slug, $city_name, $obituaries, $total, $total_pages, $page, $per_page
// individual:  $obituary, $city_slug, $should_index
$seo_data = is_array( $seo_data ) ? $seo_data : array();

$city_stats   = isset( $seo_data['city_stats'] )   ? $seo_data['city_stats']           : array();
$recent       = isset( $seo_data['recent'] )        ? $seo_data['recent']               : array();
$city_slug    = isset( $seo_data['city_slug'] )     ? $seo_data['city_slug']            : '';
$city_name    = isset( $seo_data['city_name'] )     ? $seo_data['city_name']            : '';
$obituaries   = isset( $seo_data['obituaries'] )    ? $seo_data['obituaries']           : array();
$total        = isset( $seo_data['total'] )         ? intval( $seo_data['total'] )      : 0;
$total_pages  = isset( $seo_data['total_pages'] )   ? intval( $seo_data['total_pages'] ): 0;
$page         = isset( $seo_data['page'] )          ? intval( $seo_data['page'] )       : 1;
$per_page     = isset( $seo_data['per_page'] )      ? intval( $seo_data['per_page'] )   : 20;
$obituary     = isset( $seo_data['obituary'] )      ? $seo_data['obituary']             : null;
$should_index = ! empty( $seo_data['should_index'] );

// --- Elementor availability check --------------------------------------------
// Verify full call chain: Plugin class exists -> instance() callable ->
// frontend property exists -> get_builder_content_for_display() method exists.
// If any link is missing, fallback to minimal nav/footer.
$use_elementor = false;
if ( class_exists( '\Elementor\Plugin' )
    && method_exists( '\Elementor\Plugin', 'instance' )
    && is_callable( array( '\Elementor\Plugin', 'instance' ) )
) {
    $elementor_instance = \Elementor\Plugin::instance();
    if ( isset( $elementor_instance->frontend )
        && is_object( $elementor_instance->frontend )
        && method_exists( $elementor_instance->frontend, 'get_builder_content_for_display' )
    ) {
        $use_elementor = true;
    }
}

// --- v3.12.0 FIX (Goal B): Suppress Elementor Pro Theme Builder auto-inject --
//
// Problem: When Elementor Pro Theme Builder has a site-wide header/footer
// display condition, it hooks into 'get_header' and 'get_footer' WP actions
// to replace the theme's header/footer with its own templates. Since this
// wrapper.php already renders headers/footers manually by explicit template
// ID (via get_builder_content_for_display), the auto-injection causes
// duplicate (triple) logo/header rendering on SEO virtual pages.
//
// Solution: Remove Elementor Pro's theme location hooks for header/footer
// before we output any HTML. This is safe because:
//   1. We render headers/footers manually below (by known template IDs).
//   2. This template is only used for SEO virtual pages (/obituaries/ontario/...).
//   3. Normal pages (including /ontario-obituaries/) are unaffected -- they
//      use the theme's standard get_header()/get_footer().
//   4. The hooks are only removed in this template's scope; other requests
//      are completely unaffected.
//
// Two suppression methods are used for maximum compatibility:
//   Method A: Empty the Theme Builder's location template list for header/footer.
//   Method B: Remove 'get_header'/'get_footer' callbacks from Theme_Support
//             and Locations_Manager classes (Elementor Pro internals).
if ( $use_elementor ) {
    // Method A: Tell Elementor Pro that header/footer locations have no templates.
    // Elementor Pro checks these filters before auto-rendering location content.
    add_filter( 'elementor/theme/get_location_templates/header', '__return_empty_array' );
    add_filter( 'elementor/theme/get_location_templates/footer', '__return_empty_array' );

    // Method B: Remove Elementor Pro Theme Support overrides if registered.
    // ElementorPro\Modules\ThemeBuilder\Classes\Theme_Support hooks the
    // 'get_header' and 'get_footer' WP actions to replace theme header/footer
    // with Theme Builder templates. Remove those specific callbacks.
    global $wp_filter;
    foreach ( array( 'get_header', 'get_footer' ) as $hook_tag ) {
        if ( empty( $wp_filter[ $hook_tag ] ) ) {
            continue;
        }
        foreach ( $wp_filter[ $hook_tag ]->callbacks as $priority => $hooks ) {
            foreach ( $hooks as $hook_key => $hook_data ) {
                if ( ! is_array( $hook_data['function'] ) || ! is_object( $hook_data['function'][0] ) ) {
                    continue;
                }
                $class_name = get_class( $hook_data['function'][0] );
                // Match Elementor Pro's Theme_Support and Locations_Manager classes.
                // These are the only classes that auto-inject header/footer templates.
                if ( false !== strpos( $class_name, 'Theme_Support' )
                    || false !== strpos( $class_name, 'Locations_Manager' )
                ) {
                    unset( $wp_filter[ $hook_tag ]->callbacks[ $priority ][ $hook_key ] );
                }
            }
        }
    }
}

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php
if ( function_exists( 'wp_body_open' ) ) {
    wp_body_open();
}
?>

<?php
// --- Header: Elementor templates or minimal fallback -------------------------
$header_rendered = false;

if ( $use_elementor && ! empty( $header_ids ) && is_array( $header_ids ) ) {
    foreach ( $header_ids as $hid ) {
        $hid = intval( $hid );
        if ( $hid <= 0 ) {
            continue;
        }
        $header_html = \Elementor\Plugin::instance()->frontend->get_builder_content_for_display( $hid );
        if ( ! empty( $header_html ) ) {
            echo $header_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Elementor rendered content
            $header_rendered = true;
        }
    }
}

if ( ! $header_rendered ) {
    // Minimal fallback navigation -- no theme header/footer (avoids Litho demo menu).
    echo '<nav class="ontario-obituaries-fallback-header" style="background:#2c3e50;color:#fff;padding:15px 20px;font-size:0.95em;">';
    echo '<a href="' . esc_url( home_url( '/' ) ) . '" style="color:#fff;text-decoration:none;font-weight:bold;">Monaco Monuments</a>';
    echo ' &nbsp;|&nbsp; ';
    echo '<a href="' . esc_url( home_url( '/ontario-obituaries/' ) ) . '" style="color:#ecf0f1;text-decoration:none;">Ontario Obituaries</a>';
    echo '</nav>';
}
?>

<?php
// --- Content partial ---------------------------------------------------------
if ( file_exists( $content_template ) ) {
    include $content_template;
} else {
    echo '<div style="max-width:1200px;margin:0 auto;padding:40px 20px;">';
    echo '<h1>Page Not Found</h1>';
    echo '<p>The requested obituary page could not be loaded. <a href="' . esc_url( home_url( '/obituaries/ontario/' ) ) . '">Browse Ontario Obituaries</a>.</p>';
    echo '</div>';
}
?>

<?php
// --- Footer: Elementor template or minimal fallback --------------------------
$footer_rendered = false;
$footer_id       = intval( $footer_id );

if ( $use_elementor && $footer_id > 0 ) {
    $footer_html = \Elementor\Plugin::instance()->frontend->get_builder_content_for_display( $footer_id );
    if ( ! empty( $footer_html ) ) {
        echo $footer_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Elementor rendered content
        $footer_rendered = true;
    }
}

if ( ! $footer_rendered ) {
    echo '<footer class="ontario-obituaries-fallback-footer" style="background:#2c3e50;color:#ecf0f1;padding:20px;text-align:center;font-size:0.85em;margin-top:40px;">';
    echo '<p>&copy; ' . esc_html( gmdate( 'Y' ) ) . ' <a href="https://monacomonuments.ca" style="color:#ecf0f1;">Monaco Monuments</a> &mdash; 109 Harry Walker Pkwy S, Newmarket, ON L3Y 7B3 &mdash; (905) 898-6262</p>';
    echo '</footer>';
}
?>

<?php wp_footer(); ?>
</body>
</html>
