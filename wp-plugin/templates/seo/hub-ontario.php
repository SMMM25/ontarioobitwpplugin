<?php
/**
 * SEO Template: Ontario Hub Page (content-only partial)
 *
 * v3.10.2 PR #24b: Converted to content-only partial. This template no longer
 * calls get_header() / get_footer(). It is included by wrapper.php which
 * provides the full HTML shell and correct Elementor header/footer.
 *
 * v3.13.3: Dates removed from listing cards per owner request.
 * v3.14.0: Complete UI redesign to match Monaco Monuments brand.
 *
 * Variables provided via query var 'ontario_obituaries_seo_data' (extracted by wrapper):
 *   $city_stats  — array of objects { city, total, latest }
 *   $recent      — array of obituary objects
 *
 * @package Ontario_Obituaries
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="ontario-obituaries-seo-hub" style="max-width: 1200px; margin: 0 auto; padding: 40px 20px; font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; color: #232323;">

    <h1 style="font-family: 'Cardo', Georgia, serif; font-size: 36px; font-weight: 700; color: #232323; margin-bottom: 12px; letter-spacing: -0.02em;"><?php esc_html_e( 'Ontario Obituaries', 'ontario-obituaries' ); ?></h1>

    <div class="ontario-obituaries-hub-intro" style="margin-bottom: 36px; max-width: 720px;">
        <p style="color: #555; line-height: 1.7; font-size: 15px; margin-bottom: 12px;"><?php esc_html_e( 'Browse recent obituary notices from communities across Ontario, Canada. Our obituary directory provides factual information to help you find funeral services, visitation details, and memorial information.', 'ontario-obituaries' ); ?></p>
        <p style="color: #555; line-height: 1.7; font-size: 15px;"><?php printf(
            esc_html__( 'This service is provided by %s, located in Newmarket, Ontario — serving York Region and Southern Ontario families with personalized monuments and memorials.', 'ontario-obituaries' ),
            '<a href="https://monacomonuments.ca" style="color: #2c3e50; text-decoration: none; font-weight: 500;">Monaco Monuments</a>'
        ); ?></p>
    </div>

    <!-- York Region Spotlight — drives local traffic -->
    <section class="ontario-obituaries-york-region" style="margin-bottom: 48px; padding: 32px; background: linear-gradient(135deg, #f0f4f8 0%, #e8eef5 100%); border-radius: 12px; border-left: 4px solid #2c3e50;">
        <h2 style="font-family: 'Cardo', Georgia, serif; font-size: 26px; margin-top: 0; margin-bottom: 12px; color: #232323;"><?php esc_html_e( 'York Region & Newmarket Obituaries', 'ontario-obituaries' ); ?></h2>
        <p style="color: #555; line-height: 1.7; margin-bottom: 20px;"><?php esc_html_e( 'Monaco Monuments is proud to serve the York Region community from our Newmarket location. Browse obituary notices from Newmarket, Aurora, Richmond Hill, Markham, Vaughan, and surrounding areas.', 'ontario-obituaries' ); ?></p>
        <div style="display: flex; flex-wrap: wrap; gap: 10px;">
            <?php
            $york_cities = array( 'Newmarket', 'Aurora', 'Richmond Hill', 'Markham', 'Vaughan', 'Stouffville', 'King City', 'Georgina', 'East Gwillimbury', 'Bradford' );
            foreach ( $york_cities as $yr_city ) :
                $yr_slug = sanitize_title( $yr_city );
            ?>
                <a href="<?php echo esc_url( home_url( '/obituaries/ontario/' . $yr_slug . '/' ) ); ?>"
                   style="display: inline-block; padding: 8px 18px; background: #fff; border: 1px solid #d0d5dd; border-radius: 24px; text-decoration: none; color: #2c3e50; font-size: 13px; font-weight: 500; transition: all 0.25s ease; letter-spacing: 0.01em;">
                    <?php echo esc_html( $yr_city ); ?>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ( ! empty( $city_stats ) ) : ?>
    <section class="ontario-obituaries-city-directory" style="margin-bottom: 48px;">
        <h2 style="font-family: 'Cardo', Georgia, serif; font-size: 26px; margin-bottom: 20px; color: #232323;"><?php esc_html_e( 'Browse Obituaries by City', 'ontario-obituaries' ); ?></h2>
        <div class="ontario-city-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px;">
            <?php foreach ( $city_stats as $stat ) :
                $slug = sanitize_title( $stat->city );
            ?>
                <a href="<?php echo esc_url( home_url( '/obituaries/ontario/' . $slug . '/' ) ); ?>"
                   class="ontario-city-card" style="display: block; padding: 16px; background: #f7f7f7; border-radius: 8px; text-decoration: none; color: inherit; border: 1px solid #f0f0f0; transition: all 0.25s ease;">
                    <strong style="color: #232323; font-size: 15px;"><?php echo esc_html( $stat->city ); ?></strong>
                    <span style="display: block; color: #888; font-size: 13px; margin-top: 4px;">
                        <?php printf(
                            /* translators: %d: number of notices */
                            esc_html( _n( '%d notice', '%d notices', intval( $stat->total ), 'ontario-obituaries' ) ),
                            intval( $stat->total )
                        ); ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if ( ! empty( $recent ) ) : ?>
    <section class="ontario-obituaries-recent">
        <h2 style="font-family: 'Cardo', Georgia, serif; font-size: 26px; margin-bottom: 20px; color: #232323;"><?php esc_html_e( 'Recent Obituary Notices', 'ontario-obituaries' ); ?></h2>
        <div class="ontario-obituaries-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 24px;">
            <?php foreach ( $recent as $obit ) :
                $city_raw  = ! empty( $obit->city_normalized ) ? $obit->city_normalized : $obit->location;
                $city_slug = ! empty( $city_raw ) ? sanitize_title( $city_raw ) : 'ontario';
                $name_slug = sanitize_title( $obit->name ) . '-' . $obit->id;
            ?>
                <div class="ontario-obituary-card" style="background: #fff; border: 1px solid #f0f0f0; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,0.06); transition: all 0.25s ease; display: flex; flex-direction: column;">
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
                        <a href="<?php echo esc_url( home_url( '/obituaries/ontario/' . $city_slug . '/' . $name_slug . '/' ) ); ?>"
                           style="text-decoration: none; color: #232323;">
                            <?php echo esc_html( $obit->name ); ?>
                        </a>
                    </h3>
                    <?php // v3.13.3: Date removed from listing cards per owner request. ?>
                    <?php if ( ! empty( $obit->location ) ) : ?>
                    <p style="color: #888; margin: 0 0 8px 0; font-size: 12px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.08em;">
                        <?php echo esc_html( $obit->location ); ?>
                    </p>
                    <?php endif; ?>
                    <?php if ( ! empty( $obit->funeral_home ) ) : ?>
                        <p style="color: #aaa; font-size: 12px; margin: 0 0 12px 0;"><?php echo esc_html( $obit->funeral_home ); ?></p>
                    <?php endif; ?>
                    <div style="margin-top: auto; padding-top: 12px; border-top: 1px solid #f0f0f0;">
                        <a href="<?php echo esc_url( home_url( '/obituaries/ontario/' . $city_slug . '/' . $name_slug . '/' ) ); ?>" style="font-size: 13px; color: #2c3e50; text-decoration: none; font-weight: 500; letter-spacing: 0.04em; text-transform: uppercase;">
                            <?php esc_html_e( 'Read More', 'ontario-obituaries' ); ?> &rarr;
                        </a>
                    </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="ontario-obituaries-services" style="margin-top: 48px; padding: 32px; background: linear-gradient(135deg, #f0f4f8 0%, #e8eef5 100%); border-radius: 12px; border-left: 4px solid #2c3e50;">
        <h2 style="font-family: 'Cardo', Georgia, serif; font-size: 26px; margin-top: 0; margin-bottom: 12px; color: #232323;"><?php esc_html_e( 'Monaco Monuments — Personalized Memorials in Newmarket', 'ontario-obituaries' ); ?></h2>
        <p style="color: #555; line-height: 1.7; margin-bottom: 12px;"><?php esc_html_e( 'Located at 109 Harry Walker Pkwy S, Newmarket, Ontario, Monaco Monuments creates unique, one-of-a-kind monuments and memorials meant to be cherished for eternity. We serve families throughout York Region and Southern Ontario.', 'ontario-obituaries' ); ?></p>
        <p style="color: #888; font-size: 13px; margin-bottom: 20px;">
            <?php esc_html_e( 'Serving: Newmarket, Aurora, Richmond Hill, Markham, Vaughan, King City, Stouffville, Barrie, and all of Southern Ontario.', 'ontario-obituaries' ); ?>
        </p>
        <div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center;">
            <a href="https://monacomonuments.ca/catalog/" style="display: inline-block; padding: 12px 28px; background: #2c3e50; color: #fff; text-decoration: none; border-radius: 4px; font-size: 13px; font-weight: 500; letter-spacing: 0.04em; text-transform: uppercase;">
                <?php esc_html_e( 'Browse Our Catalog', 'ontario-obituaries' ); ?>
            </a>
            <a href="https://monacomonuments.ca/contact/" style="color: #2c3e50; text-decoration: none; font-weight: 500; font-size: 14px;">
                <?php esc_html_e( 'Contact Us', 'ontario-obituaries' ); ?> &rarr;
            </a>
            <a href="tel:+19058986262" style="color: #2c3e50; text-decoration: none; font-weight: 500; font-size: 14px;">
                <?php esc_html_e( '(905) 898-6262', 'ontario-obituaries' ); ?>
            </a>
        </div>
    </section>

</div>

<?php
// v3.10.2 PR #24b: get_footer() removed — wrapper.php handles footer rendering.
