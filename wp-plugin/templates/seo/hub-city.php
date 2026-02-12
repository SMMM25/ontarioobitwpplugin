<?php
/**
 * SEO Template: City Hub Page (content-only partial)
 *
 * v3.10.2 PR #24b: Converted to content-only partial. This template no longer
 * calls get_header() / get_footer(). It is included by wrapper.php which
 * provides the full HTML shell and correct Elementor header/footer.
 *
 * v3.13.3: Dates removed from listing cards per owner request.
 * v3.14.0: Complete UI redesign to match Monaco Monuments brand.
 *
 * Variables provided via query var 'ontario_obituaries_seo_data' (extracted by wrapper):
 *   $city_slug    — string URL slug
 *   $city_name    — string display name
 *   $obituaries   — array of obituary objects
 *   $total        — int total count
 *   $total_pages  — int total pages
 *   $page         — int current page
 *   $per_page     — int records per page
 *
 * @package Ontario_Obituaries
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="ontario-obituaries-city-hub" style="max-width: 1200px; margin: 0 auto; padding: 40px 20px; font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; color: #232323;">

    <!-- Breadcrumbs -->
    <nav class="ontario-obituaries-breadcrumbs" style="margin-bottom: 28px; font-size: 13px; color: #888;">
        <a href="<?php echo esc_url( home_url( '/' ) ); ?>" style="color: #2c3e50; text-decoration: none;"><?php esc_html_e( 'Home', 'ontario-obituaries' ); ?></a> &rsaquo;
        <a href="<?php echo esc_url( home_url( '/obituaries/ontario/' ) ); ?>" style="color: #2c3e50; text-decoration: none;"><?php esc_html_e( 'Ontario Obituaries', 'ontario-obituaries' ); ?></a> &rsaquo;
        <span style="color: #aaa;"><?php echo esc_html( $city_name ); ?></span>
    </nav>

    <h1 style="font-family: 'Cardo', Georgia, serif; font-size: 36px; font-weight: 700; color: #232323; margin-bottom: 12px; letter-spacing: -0.02em;"><?php printf( esc_html__( '%s Obituaries — Ontario', 'ontario-obituaries' ), esc_html( $city_name ) ); ?></h1>

    <div class="ontario-obituaries-city-intro" style="margin-bottom: 36px; max-width: 720px;">
        <p style="color: #555; line-height: 1.7; font-size: 15px;"><?php printf(
            esc_html__( 'Recent obituary notices from %1$s, Ontario, Canada. We collect publicly available factual information from local funeral homes and news sources to help families find memorial service details. Currently showing %2$d obituary notices from %1$s.', 'ontario-obituaries' ),
            esc_html( $city_name ),
            intval( $total )
        ); ?></p>
    </div>

    <?php if ( empty( $obituaries ) ) : ?>
        <div class="ontario-obituaries-empty" style="padding: 60px; text-align: center; background: #f7f7f7; border-radius: 12px;">
            <p style="color: #888; font-size: 16px;"><?php printf( esc_html__( 'No obituary notices found for %s at this time. Check back soon.', 'ontario-obituaries' ), esc_html( $city_name ) ); ?></p>
        </div>
    <?php else : ?>

        <div class="ontario-obituaries-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 24px; margin-bottom: 36px;">
            <?php foreach ( $obituaries as $obit ) :
                $name_slug = sanitize_title( $obit->name ) . '-' . $obit->id;
                $obit_url  = home_url( '/obituaries/ontario/' . $city_slug . '/' . $name_slug . '/' );
            ?>
                <article class="ontario-obituary-card" style="background: #fff; border: 1px solid #f0f0f0; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,0.06); transition: all 0.25s ease; display: flex; flex-direction: column;" itemscope itemtype="https://schema.org/Person">
                    <?php if ( ! empty( $obit->image_url ) ) : ?>
                    <div style="height: 220px; overflow: hidden; background: #f7f7f7; display: flex; align-items: center; justify-content: center;">
                        <img src="<?php echo esc_url( $obit->image_url ); ?>"
                             alt="<?php echo esc_attr( sprintf( __( 'Photo of %s', 'ontario-obituaries' ), $obit->name ) ); ?>"
                             loading="lazy"
                             style="width: 100%; height: 100%; object-fit: contain; object-position: center; padding: 12px;">
                    </div>
                    <?php endif; ?>

                    <div style="padding: 24px; flex: 1; display: flex; flex-direction: column;">
                    <h3 style="margin-top: 0; margin-bottom: 10px; font-family: 'Cardo', Georgia, serif; font-size: 20px; line-height: 1.3;">
                        <a href="<?php echo esc_url( $obit_url ); ?>" itemprop="name" style="text-decoration: none; color: #232323;">
                            <?php echo esc_html( $obit->name ); ?>
                        </a>
                    </h3>

                    <?php
                    // v3.13.3: Visible dates removed from listing cards per owner request.
                    // Schema.org markup kept as hidden meta for SEO (Google rich results).
                    ?>
                    <?php if ( ! empty( $obit->date_of_birth ) && '0000-00-00' !== $obit->date_of_birth ) : ?>
                        <meta itemprop="birthDate" content="<?php echo esc_attr( $obit->date_of_birth ); ?>">
                    <?php endif; ?>
                    <meta itemprop="deathDate" content="<?php echo esc_attr( $obit->date_of_death ); ?>">

                    <?php if ( ! empty( $obit->funeral_home ) ) : ?>
                        <p style="color: #888; font-size: 12px; margin: 0 0 12px 0; letter-spacing: 0.02em;"><?php echo esc_html( $obit->funeral_home ); ?></p>
                    <?php endif; ?>

                    <?php if ( ! empty( $obit->description ) ) : ?>
                        <p style="font-size: 14px; color: #555; line-height: 1.6; margin-bottom: 16px;">
                            <?php echo esc_html( wp_trim_words( $obit->description, 25, '...' ) ); ?>
                        </p>
                    <?php endif; ?>

                    <div style="margin-top: auto; padding-top: 12px; border-top: 1px solid #f0f0f0;">
                        <a href="<?php echo esc_url( $obit_url ); ?>" style="font-size: 13px; color: #2c3e50; text-decoration: none; font-weight: 500; letter-spacing: 0.04em; text-transform: uppercase;">
                            <?php esc_html_e( 'Read More', 'ontario-obituaries' ); ?> &rarr;
                        </a>
                    </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if ( $total_pages > 1 ) : ?>
        <nav class="ontario-obituaries-pagination" style="text-align: center; margin: 36px 0;">
            <?php for ( $p = 1; $p <= min( $total_pages, 20 ); $p++ ) :
                $url = add_query_arg( 'pg', $p, home_url( '/obituaries/ontario/' . $city_slug . '/' ) );
            ?>
                <?php if ( $p === $page ) : ?>
                    <strong style="margin: 0 3px; padding: 8px 14px; background: #2c3e50; color: #fff; border-radius: 4px; font-size: 14px;"><?php echo intval( $p ); ?></strong>
                <?php else : ?>
                    <a href="<?php echo esc_url( $url ); ?>" style="margin: 0 3px; padding: 8px 14px; text-decoration: none; border: 1px solid #e0e0e0; border-radius: 4px; color: #2c3e50; font-size: 14px;"><?php echo intval( $p ); ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </nav>
        <?php endif; ?>

    <?php endif; ?>

    <!-- Local Services Section — Internal Link to Monaco Monuments -->
    <section class="ontario-obituaries-local-services" style="margin-top: 48px; padding: 32px; background: linear-gradient(135deg, #f0f4f8 0%, #e8eef5 100%); border-radius: 12px; border-left: 4px solid #2c3e50;">
        <h2 style="font-family: 'Cardo', Georgia, serif; font-size: 26px; margin-top: 0; margin-bottom: 12px; color: #232323;"><?php printf( esc_html__( 'Monument Services near %s', 'ontario-obituaries' ), esc_html( $city_name ) ); ?></h2>
        <p style="color: #555; line-height: 1.7; margin-bottom: 16px;"><?php printf(
            esc_html__( 'Monaco Monuments, located in Newmarket, Ontario, serves families in %s, across York Region, and throughout Southern Ontario. Each monument we create is a unique, one-of-a-kind celebration of life meant to be cherished for eternity.', 'ontario-obituaries' ),
            esc_html( $city_name )
        ); ?></p>
        <p style="color: #888; font-size: 13px; margin-bottom: 20px;">
            <?php esc_html_e( '1190 Twinney Dr. Unit #8, Newmarket, ON | (905) 392-0778', 'ontario-obituaries' ); ?>
        </p>
        <div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center;">
            <a href="https://monacomonuments.ca/contact/" style="display: inline-block; padding: 12px 28px; background: #2c3e50; color: #fff; text-decoration: none; border-radius: 4px; font-size: 13px; font-weight: 500; letter-spacing: 0.04em; text-transform: uppercase;">
                <?php esc_html_e( 'Get a Free Consultation', 'ontario-obituaries' ); ?>
            </a>
            <a href="https://monacomonuments.ca/catalog/" style="color: #2c3e50; text-decoration: none; font-weight: 500; font-size: 14px;">
                <?php esc_html_e( 'View Our Catalog', 'ontario-obituaries' ); ?> &rarr;
            </a>
            <a href="tel:+19053920778" style="color: #2c3e50; text-decoration: none; font-weight: 500; font-size: 14px;">
                <?php esc_html_e( 'Call Us', 'ontario-obituaries' ); ?>
            </a>
        </div>
    </section>

    <!-- Removal / Correction Notice -->
    <div class="ontario-obituaries-disclaimer" style="margin-top: 32px; padding-top: 20px; border-top: 1px solid #e0e0e0; font-size: 12px; color: #aaa;">
        <p><?php esc_html_e( 'All obituary information is obtained from publicly available sources. If you wish to have a listing removed or corrected, please contact us.', 'ontario-obituaries' ); ?>
        <a href="https://monacomonuments.ca/contact/" style="color: #aaa; text-decoration: underline;"><?php esc_html_e( 'Request a removal', 'ontario-obituaries' ); ?></a>.</p>
    </div>

</div>

<?php
// v3.10.2 PR #24b: get_footer() removed — wrapper.php handles footer rendering.
