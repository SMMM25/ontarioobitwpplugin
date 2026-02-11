<?php
/**
 * SEO Template: Individual Obituary Page (content-only partial)
 *
 * v3.10.2 PR #24b: Converted to content-only partial. This template no longer
 * calls get_header() / get_footer(). It is included by wrapper.php which
 * provides the full HTML shell and correct Elementor header/footer.
 *
 * v3.13.3: Visible dates removed from header; "Date published" added to footer.
 * v3.14.0: Complete UI redesign to match Monaco Monuments brand.
 * v3.14.1: Full Monaco Monuments themed detail page with dates, image, CTA.
 * v3.15.0: Forced enrichment sweep populates images; year_death extraction fix.
 * v3.15.1: Better images (300x400 print-only CDN); full description extraction; SPA skip.
 *
 * Variables provided via query var 'ontario_obituaries_seo_data' (extracted by wrapper):
 *   $obituary     — object the obituary record
 *   $city_slug    — string URL slug for the city
 *   $should_index — bool whether this page should be indexed
 *
 * @package Ontario_Obituaries
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$city_name = ! empty( $obituary->city_normalized ) ? $obituary->city_normalized : $obituary->location;

// Format dates for display
$birth_display = '';
$death_display = '';
$date_range_display = '';

if ( ! empty( $obituary->date_of_birth ) && '0000-00-00' !== $obituary->date_of_birth ) {
    $birth_display = date_i18n( 'F j, Y', strtotime( $obituary->date_of_birth ) );
}
if ( ! empty( $obituary->date_of_death ) && '0000-00-00' !== $obituary->date_of_death ) {
    $death_display = date_i18n( 'F j, Y', strtotime( $obituary->date_of_death ) );
}

if ( $birth_display && $death_display ) {
    $date_range_display = $birth_display . ' &ndash; ' . $death_display;
} elseif ( $death_display ) {
    $date_range_display = $death_display;
}
?>

<div class="ontario-obituary-individual" itemscope itemtype="https://schema.org/Person">

    <!-- Breadcrumbs -->
    <nav class="ontario-obituaries-breadcrumbs">
        <a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'ontario-obituaries' ); ?></a> <span class="ontario-breadcrumb-sep">&rsaquo;</span>
        <a href="<?php echo esc_url( home_url( '/obituaries/ontario/' ) ); ?>"><?php esc_html_e( 'Ontario Obituaries', 'ontario-obituaries' ); ?></a> <span class="ontario-breadcrumb-sep">&rsaquo;</span>
        <?php if ( ! empty( $city_name ) ) : ?>
            <a href="<?php echo esc_url( home_url( '/obituaries/ontario/' . sanitize_title( $city_name ) . '/' ) ); ?>">
                <?php echo esc_html( $city_name ); ?>
            </a> <span class="ontario-breadcrumb-sep">&rsaquo;</span>
        <?php endif; ?>
        <span class="ontario-breadcrumb-current"><?php echo esc_html( $obituary->name ); ?></span>
    </nav>

    <article class="ontario-obituary-page">
        <!-- Hero section: image + name + dates -->
        <div class="ontario-obituary-hero">
            <?php if ( ! empty( $obituary->image_url ) ) : ?>
            <div class="ontario-obituary-hero-image">
                <img src="<?php echo esc_url( $obituary->image_url ); ?>"
                     alt="<?php echo esc_attr( sprintf( __( 'Photo of %s', 'ontario-obituaries' ), $obituary->name ) ); ?>"
                     loading="lazy">
            </div>
            <?php endif; ?>

            <div class="ontario-obituary-hero-info">
                <h1 itemprop="name"><?php echo esc_html( $obituary->name ); ?></h1>

                <?php if ( $date_range_display ) : ?>
                <div class="ontario-obituary-dates">
                    <?php echo $date_range_display; // Contains HTML entities, already escaped ?>
                </div>
                <?php endif; ?>

                <?php if ( ! empty( $obituary->age ) && intval( $obituary->age ) > 0 ) : ?>
                <div class="ontario-obituary-age">
                    <?php printf( esc_html__( 'Age %d', 'ontario-obituaries' ), intval( $obituary->age ) ); ?>
                </div>
                <?php endif; ?>

                <div class="ontario-obituary-meta-details">
                    <?php if ( ! empty( $obituary->location ) ) : ?>
                        <div class="ontario-obituary-location-tag" itemprop="deathPlace" itemscope itemtype="https://schema.org/Place">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                            <span itemprop="name"><?php echo esc_html( $obituary->location ); ?></span>, Ontario
                        </div>
                    <?php endif; ?>

                    <?php if ( ! empty( $obituary->funeral_home ) ) : ?>
                        <div class="ontario-obituary-funeral-tag">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M3 10h18M5 6l7-3 7 3M4 10v11M20 10v11M8 14v4M12 14v4M16 14v4"></path></svg>
                            <?php echo esc_html( $obituary->funeral_home ); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Schema.org meta (hidden) -->
        <?php if ( ! empty( $obituary->date_of_birth ) && '0000-00-00' !== $obituary->date_of_birth ) : ?>
            <meta itemprop="birthDate" content="<?php echo esc_attr( $obituary->date_of_birth ); ?>">
        <?php endif; ?>
        <meta itemprop="deathDate" content="<?php echo esc_attr( $obituary->date_of_death ); ?>">

        <!-- Obituary content -->
        <?php if ( ! empty( $obituary->description ) ) : ?>
        <div class="ontario-obituary-body">
            <?php echo wpautop( esc_html( $obituary->description ) ); ?>
        </div>
        <?php else : ?>
        <div class="ontario-obituary-body ontario-obituary-body-minimal">
            <p><?php printf(
                esc_html__( 'We have recorded the passing of %s of %s, Ontario.', 'ontario-obituaries' ),
                esc_html( $obituary->name ),
                esc_html( ! empty( $city_name ) ? $city_name : 'Ontario' )
            ); ?></p>
            <p><?php esc_html_e( 'A full obituary was not available at the time of publication. If you are a family member or representative and would like to add details, please contact us.', 'ontario-obituaries' ); ?></p>
        </div>
        <?php endif; ?>

        <!-- Footer / provenance -->
        <footer class="ontario-obituary-footer">
            <?php if ( ! empty( $obituary->source_url ) ) : ?>
                <!-- v3.10.0 FIX: Source attribution is text-only (no clickable external link).
                     Per PLATFORM_OVERSIGHT_HUB Rule 6 #9: no external obituary links.
                     source_url retained in DB for provenance/audit. -->
                <p class="ontario-obituary-provenance">
                    <?php esc_html_e( 'Source record retained internally for provenance.', 'ontario-obituaries' ); ?>
                </p>
            <?php endif; ?>

            <?php // v3.13.3: "Date published" shown here (moved from header per owner request). ?>
            <?php if ( ! empty( $obituary->created_at ) ) : ?>
                <p class="ontario-obituary-published-date">
                    <?php printf(
                        esc_html__( 'Date published: %s', 'ontario-obituaries' ),
                        esc_html( date_i18n( get_option( 'date_format' ), strtotime( $obituary->created_at ) ) )
                    ); ?>
                </p>
            <?php endif; ?>

            <!-- Back to city hub -->
            <?php if ( ! empty( $city_name ) ) : ?>
                <a href="<?php echo esc_url( home_url( '/obituaries/ontario/' . sanitize_title( $city_name ) . '/' ) ); ?>" class="ontario-obituary-back-link">
                    &larr; <?php printf( esc_html__( 'Back to %s Obituaries', 'ontario-obituaries' ), esc_html( $city_name ) ); ?>
                </a>
            <?php endif; ?>
        </footer>
    </article>

    <!-- Monument Services CTA -->
    <aside class="ontario-obituaries-cta-block">
        <div class="ontario-obituaries-cta-inner">
            <h3><?php esc_html_e( 'Personalized Monuments', 'ontario-obituaries' ); ?></h3>
            <p class="ontario-cta-subtitle"><?php esc_html_e( 'Monaco Monuments, Newmarket', 'ontario-obituaries' ); ?></p>
            <p class="ontario-cta-desc"><?php esc_html_e( 'Each monument we create is unique, a one-of-a-kind celebration of life meant to be cherished for eternity. Serving families in York Region, Newmarket, Aurora, Richmond Hill, and throughout Southern Ontario.', 'ontario-obituaries' ); ?></p>
            <p class="ontario-cta-address">
                <?php esc_html_e( '109 Harry Walker Pkwy S, Newmarket, ON L3Y 7B3', 'ontario-obituaries' ); ?> &bull; <?php esc_html_e( '(905) 898-6262', 'ontario-obituaries' ); ?>
            </p>
            <div class="ontario-cta-buttons">
                <a href="https://monacomonuments.ca/contact/" class="ontario-cta-btn-primary">
                    <?php esc_html_e( 'Contact Monaco Monuments', 'ontario-obituaries' ); ?>
                </a>
                <a href="https://monacomonuments.ca/catalog/" class="ontario-cta-btn-secondary">
                    <?php esc_html_e( 'Browse Our Catalog', 'ontario-obituaries' ); ?> &rarr;
                </a>
            </div>
        </div>
    </aside>

    <!-- Removal notice -->
    <div class="ontario-obituary-removal-notice">
        <p><?php esc_html_e( 'This information was compiled from publicly available sources.', 'ontario-obituaries' ); ?>
        <a href="https://monacomonuments.ca/contact/"><?php esc_html_e( 'Request removal or correction', 'ontario-obituaries' ); ?></a>.</p>
    </div>

</div>

<?php
// v3.10.2 PR #24b: get_footer() removed — wrapper.php handles footer rendering.
