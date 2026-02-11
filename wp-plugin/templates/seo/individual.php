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
?>

<div class="ontario-obituary-individual" style="max-width: 800px; margin: 0 auto; padding: 40px 20px;" itemscope itemtype="https://schema.org/Person">

    <!-- Breadcrumbs -->
    <nav class="ontario-obituaries-breadcrumbs" style="margin-bottom: 28px; font-size: 13px; color: #888;">
        <a href="<?php echo esc_url( home_url( '/' ) ); ?>" style="color: #2c3e50; text-decoration: none;"><?php esc_html_e( 'Home', 'ontario-obituaries' ); ?></a> &rsaquo;
        <a href="<?php echo esc_url( home_url( '/obituaries/ontario/' ) ); ?>" style="color: #2c3e50; text-decoration: none;"><?php esc_html_e( 'Ontario Obituaries', 'ontario-obituaries' ); ?></a> &rsaquo;
        <?php if ( ! empty( $city_name ) ) : ?>
            <a href="<?php echo esc_url( home_url( '/obituaries/ontario/' . sanitize_title( $city_name ) . '/' ) ); ?>" style="color: #2c3e50; text-decoration: none;">
                <?php echo esc_html( $city_name ); ?>
            </a> &rsaquo;
        <?php endif; ?>
        <span style="color: #aaa;"><?php echo esc_html( $obituary->name ); ?></span>
    </nav>

    <article class="ontario-obituary-page">
        <header style="margin-bottom: 32px;">
            <h1 itemprop="name" style="font-family: 'Cardo', Georgia, serif; font-size: 36px; font-weight: 700; color: #232323; margin-bottom: 12px; line-height: 1.2; letter-spacing: -0.02em;"><?php echo esc_html( $obituary->name ); ?></h1>

            <?php if ( ! empty( $obituary->location ) ) : ?>
                <div style="font-size: 14px; color: #555; margin-bottom: 6px;" itemprop="deathPlace" itemscope itemtype="https://schema.org/Place">
                    <span itemprop="name"><?php echo esc_html( $obituary->location ); ?></span>, Ontario
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $obituary->funeral_home ) ) : ?>
                <div style="color: #888; font-size: 13px; margin-bottom: 16px;">
                    <?php echo esc_html( $obituary->funeral_home ); ?>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $obituary->image_url ) ) : ?>
            <div class="ontario-obituary-image" style="margin-bottom: 28px;">
                <img src="<?php echo esc_url( $obituary->image_url ); ?>"
                     alt="<?php echo esc_attr( sprintf( __( 'Photo of %s', 'ontario-obituaries' ), $obituary->name ) ); ?>"
                     style="max-width: 320px; height: auto; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.06);"
                     loading="lazy">
            </div>
            <?php endif; ?>

            <?php
            // v3.13.3: Visible dates removed from header per owner request.
            // Schema.org markup kept as hidden meta for SEO (Google rich results).
            ?>
            <?php if ( ! empty( $obituary->date_of_birth ) && '0000-00-00' !== $obituary->date_of_birth ) : ?>
                <meta itemprop="birthDate" content="<?php echo esc_attr( $obituary->date_of_birth ); ?>">
            <?php endif; ?>
            <meta itemprop="deathDate" content="<?php echo esc_attr( $obituary->date_of_death ); ?>">
        </header>

        <?php if ( ! empty( $obituary->description ) ) : ?>
        <div class="ontario-obituary-content" style="font-size: 16px; line-height: 1.8; color: #555; margin-bottom: 40px;">
            <?php echo wpautop( esc_html( $obituary->description ) ); ?>
        </div>
        <?php endif; ?>

        <footer style="border-top: 1px solid #e0e0e0; padding-top: 24px; margin-top: 40px;">
            <?php if ( ! empty( $obituary->source_url ) ) : ?>
                <!-- v3.10.0 FIX: Source attribution is text-only (no clickable external link).
                     Per PLATFORM_OVERSIGHT_HUB Rule 6 #9: no external obituary links.
                     source_url retained in DB for provenance/audit. -->
                <p style="font-size: 12px; color: #aaa; margin-bottom: 4px;">
                    <?php esc_html_e( 'Source record retained internally for provenance.', 'ontario-obituaries' ); ?>
                </p>
            <?php endif; ?>

            <?php // v3.13.3: "Date published" shown here (moved from header per owner request). ?>
            <?php if ( ! empty( $obituary->created_at ) ) : ?>
                <p style="font-size: 12px; color: #aaa;">
                    <?php printf(
                        esc_html__( 'Date published: %s', 'ontario-obituaries' ),
                        esc_html( date_i18n( get_option( 'date_format' ), strtotime( $obituary->created_at ) ) )
                    ); ?>
                </p>
            <?php endif; ?>

            <!-- Back to city hub -->
            <?php if ( ! empty( $city_name ) ) : ?>
                <p style="margin-top: 20px;">
                    <a href="<?php echo esc_url( home_url( '/obituaries/ontario/' . sanitize_title( $city_name ) . '/' ) ); ?>" style="color: #2c3e50; text-decoration: none; font-weight: 500; font-size: 14px; letter-spacing: 0.02em;">
                        &larr; <?php printf( esc_html__( 'Back to %s Obituaries', 'ontario-obituaries' ), esc_html( $city_name ) ); ?>
                    </a>
                </p>
            <?php endif; ?>
        </footer>
    </article>

    <!-- Monument Services CTA -->
    <aside class="ontario-obituaries-cta" style="margin-top: 48px; padding: 32px; background: linear-gradient(135deg, #f0f4f8 0%, #e8eef5 100%); border-radius: 12px; border-left: 4px solid #2c3e50;">
        <h3 style="font-family: 'Cardo', Georgia, serif; font-size: 24px; margin-top: 0; margin-bottom: 12px; color: #232323;"><?php esc_html_e( 'Personalized Monuments — Monaco Monuments, Newmarket', 'ontario-obituaries' ); ?></h3>
        <p style="color: #555; line-height: 1.7; margin-bottom: 16px;"><?php esc_html_e( 'Each monument we create is unique, a one-of-a-kind celebration of life meant to be cherished for eternity. Serving families in York Region, Newmarket, Aurora, Richmond Hill, and throughout Southern Ontario.', 'ontario-obituaries' ); ?></p>
        <p style="color: #888; font-size: 13px; margin-bottom: 20px;">
            <?php esc_html_e( '109 Harry Walker Pkwy S, Newmarket, ON L3Y 7B3 | (905) 898-6262', 'ontario-obituaries' ); ?>
        </p>
        <div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center;">
            <a href="https://monacomonuments.ca/contact/" style="display: inline-block; padding: 12px 28px; background: #2c3e50; color: #fff; text-decoration: none; border-radius: 4px; font-size: 13px; font-weight: 500; letter-spacing: 0.04em; text-transform: uppercase;">
                <?php esc_html_e( 'Contact Monaco Monuments', 'ontario-obituaries' ); ?>
            </a>
            <a href="https://monacomonuments.ca/catalog/" style="color: #2c3e50; text-decoration: none; font-weight: 500; font-size: 14px;">
                <?php esc_html_e( 'Browse Our Catalog', 'ontario-obituaries' ); ?> &rarr;
            </a>
        </div>
    </aside>

    <!-- Removal notice -->
    <div style="margin-top: 24px; font-size: 12px; color: #aaa;">
        <p><?php esc_html_e( 'This information was compiled from publicly available sources.', 'ontario-obituaries' ); ?>
        <a href="https://monacomonuments.ca/contact/" style="color: #aaa; text-decoration: underline;"><?php esc_html_e( 'Request removal or correction', 'ontario-obituaries' ); ?></a>.</p>
    </div>

</div>

<?php
// v3.10.2 PR #24b: get_footer() removed — wrapper.php handles footer rendering.
