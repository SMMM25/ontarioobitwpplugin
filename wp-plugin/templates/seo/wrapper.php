<?php
/**
 * SEO Wrapper Template -- Pattern 2: Custom HTML Shell
 *
 * v3.10.2 PR #24b: Renders a complete HTML document for SEO virtual pages
 * (/obituaries/ontario/...) with the correct Monaco Monuments Elementor
 * header and footer templates, bypassing the Litho theme's get_header() /
 * get_footer() which produce incorrect demo navigation.
 *
 * v3.12.1 FIX: Triple-logo / header duplication fix.
 *
 * Root cause: Three separate header systems were all active on SEO pages:
 *   1. Elementor template 13039 (mini-header) -- rendered by this wrapper
 *   2. Elementor template 15223 (main header) -- rendered by this wrapper
 *   3. Elementor Pro Theme Builder auto-injecting site-wide header via
 *      get_header/get_footer actions or elementor/theme/do_location
 *
 * Fix (Strategy A -- wrapper renders ONE header, suppress all others):
 *   - Render only ONE Elementor header template (prefer ID 15223 = main
 *     header; fall back to 13039 = mini-header; then any remaining ID).
 *     Previously the loop rendered ALL $header_ids.
 *   - Suppress Elementor Pro Theme Builder auto-injection via two methods:
 *     (A) Filter elementor/theme/get_location_templates to empty array
 *     (B) Remove Theme_Support/Locations_Manager get_header/get_footer hooks
 *   - Emergency toggle: filter 'ontario_obituaries_seo_wrapper_render_elementor_header'
 *     (default true). Return false to disable wrapper header output entirely.
 *   - Same toggle for footer: 'ontario_obituaries_seo_wrapper_render_elementor_footer'
 *
 * This template:
 *   - Outputs a valid HTML5 document (DOCTYPE, html, head, body)
 *   - Calls wp_head() for all enqueued styles, scripts, and meta (Schema.org,
 *     OG tags, canonical URLs, noindex, Elementor CSS, Google Fonts, etc.)
 *   - Renders ONE Elementor header template by ID (preferred: main header)
 *   - Includes the content-only partial (hub-ontario, hub-city, or individual)
 *   - Renders ONE Elementor footer template by ID
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

// --- v3.12.1 FIX: Suppress Elementor Pro Theme Builder auto-inject -----------
//
// Root cause of triple-logo:
//   Logo 1: Elementor template 13039 (mini-header bar) rendered by this wrapper
//   Logo 2: Elementor template 15223 (main nav header) rendered by this wrapper
//   Logo 3: Elementor Pro Theme Builder auto-injecting its site-wide header
//           condition via get_header action or wp_body_open hook
//
// This suppression block eliminates Logo 3. The header rendering block below
// (also updated in v3.12.1) eliminates the Logo 1+2 duplication by rendering
// only ONE preferred header template (15223) instead of all of them.
//
// Two suppression methods for maximum compatibility:
//   Method A: Filter location template lists to empty arrays (Elementor Pro
//             public API -- prevents it from finding any templates to render).
//   Method B: Remove get_header/get_footer callbacks from Elementor Pro classes
//             (guarded $wp_filter surgery -- catches action-based injection).
//
// Note: Method C (elementor/theme/do_location filter) was considered but removed
// because the return-value contract varies across Elementor Pro versions and
// could leave behind side effects. Methods A+B combined with single-header
// rendering are sufficient to eliminate all three logo sources.
//
// Safety:
//   - Only runs when $use_elementor is true (Elementor is present).
//   - Only affects this template file (SEO virtual pages).
//   - Normal pages use standard get_header()/get_footer() and are unaffected.
//   - If Elementor Pro is absent, $use_elementor may still be true (free
//     Elementor), but Methods A/B simply have no effect (no hooks to remove).
if ( $use_elementor ) {
    // Method A: Tell Elementor Pro that header/footer locations have no templates.
    // Priority 999 ensures this runs after any Elementor Pro filters that
    // populate the template list.
    add_filter( 'elementor/theme/get_location_templates/header', '__return_empty_array', 999 );
    add_filter( 'elementor/theme/get_location_templates/footer', '__return_empty_array', 999 );

    // Method B: Remove Elementor Pro Theme Support / Locations_Manager callbacks
    // from 'get_header' and 'get_footer' WP actions. These are the hooks that
    // auto-replace the theme header/footer with Theme Builder templates.
    //
    // Uses remove_action() with the exact callable rather than unsetting
    // $wp_filter internals directly, which preserves WP_Hook bookkeeping
    // and avoids potential PHP notices from stale iterator state.
    //
    // Guards:
    //   - $wp_filter[$hook_tag] must be a WP_Hook object with a 'callbacks' property.
    //   - Each callback must be an array with an object at index [0].
    //   - Only Elementor Pro classes containing 'Theme_Support', 'Locations_Manager',
    //     or 'Theme_Builder' in their class name are removed.
    //   - Callables are collected first, then removed in a second pass to avoid
    //     mutating the array while iterating over it.
    global $wp_filter;
    foreach ( array( 'get_header', 'get_footer' ) as $hook_tag ) {
        if ( ! isset( $wp_filter[ $hook_tag ] )
            || ! is_object( $wp_filter[ $hook_tag ] )
            || ! isset( $wp_filter[ $hook_tag ]->callbacks )
            || ! is_array( $wp_filter[ $hook_tag ]->callbacks )
        ) {
            continue;
        }

        // Pass 1: collect callables to remove (avoids mutating during iteration).
        $to_remove = array();
        foreach ( $wp_filter[ $hook_tag ]->callbacks as $priority => $hooks ) {
            if ( ! is_array( $hooks ) ) {
                continue;
            }
            foreach ( $hooks as $hook_key => $hook_data ) {
                if ( ! isset( $hook_data['function'] )
                    || ! is_array( $hook_data['function'] )
                    || ! isset( $hook_data['function'][0] )
                    || ! is_object( $hook_data['function'][0] )
                ) {
                    continue;
                }
                $class_name = get_class( $hook_data['function'][0] );
                if ( false !== strpos( $class_name, 'Theme_Support' )
                    || false !== strpos( $class_name, 'Locations_Manager' )
                    || false !== strpos( $class_name, 'Theme_Builder' )
                ) {
                    $to_remove[] = array(
                        'callable' => $hook_data['function'],
                        'priority' => $priority,
                    );
                }
            }
        }

        // Pass 2: remove via WordPress API (safe, maintains WP_Hook state).
        foreach ( $to_remove as $entry ) {
            remove_action( $hook_tag, $entry['callable'], $entry['priority'] );
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
// --- Header: ONE Elementor template or minimal fallback ----------------------
//
// v3.12.1 FIX: Render exactly ONE header template, not all of them.
//
// Previously this loop rendered every ID in $header_ids (default: 13039 +
// 15223), producing two logo/header blocks. The site design uses:
//   - 13039: Mini-header (top bar with phone number / hours)
//   - 15223: Main header (site logo + navigation menu)
//
// Strategy: Try the PREFERRED header ID (15223) first. If it renders, done.
// If not, fall back to the secondary ID (13039), then any remaining IDs in
// order. If none render, show minimal fallback nav.
//
// This is explicit rather than relying on array order, so reordering the
// $header_ids array via filter won't accidentally change which header renders.
//
// The preferred ID is filterable:
//   add_filter( 'ontario_obituaries_seo_preferred_header_id', function() { return 13039; } );
//
// Emergency toggle: return false from the filter below to completely disable
// Elementor header rendering in this wrapper (fallback nav will show instead).
// Example: add_filter( 'ontario_obituaries_seo_wrapper_render_elementor_header', '__return_false' );
$header_rendered = false;

$render_elementor_header = apply_filters( 'ontario_obituaries_seo_wrapper_render_elementor_header', true );

if ( $render_elementor_header && $use_elementor && ! empty( $header_ids ) && is_array( $header_ids ) ) {
    // Preferred header: main navigation header (15223). Filterable.
    $preferred_header_id = intval( apply_filters( 'ontario_obituaries_seo_preferred_header_id', 15223 ) );

    // Build ordered try-list: preferred first, then remaining IDs as fallbacks.
    $try_ids = array();
    if ( $preferred_header_id > 0 && in_array( $preferred_header_id, array_map( 'intval', $header_ids ), true ) ) {
        $try_ids[] = $preferred_header_id;
    }
    foreach ( $header_ids as $hid ) {
        $hid = intval( $hid );
        if ( $hid > 0 && $hid !== $preferred_header_id ) {
            $try_ids[] = $hid;
        }
    }

    // Render the first ID that returns content, then stop.
    foreach ( $try_ids as $hid ) {
        $header_html = \Elementor\Plugin::instance()->frontend->get_builder_content_for_display( $hid );
        if ( ! empty( $header_html ) ) {
            echo $header_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Elementor rendered content
            $header_rendered = true;
            break; // v3.12.1: Only render ONE header template.
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
// --- Footer: ONE Elementor template or minimal fallback ----------------------
//
// Emergency toggle: return false to disable Elementor footer rendering.
// Example: add_filter( 'ontario_obituaries_seo_wrapper_render_elementor_footer', '__return_false' );
$footer_rendered = false;
$footer_id       = intval( $footer_id );

$render_elementor_footer = apply_filters( 'ontario_obituaries_seo_wrapper_render_elementor_footer', true );

if ( $render_elementor_footer && $use_elementor && $footer_id > 0 ) {
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
