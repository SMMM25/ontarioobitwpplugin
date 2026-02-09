<?php
/**
 * SEO Template: Ontario Hub Page
 *
 * Variables provided by Ontario_Obituaries_SEO::render_ontario_hub():
 *   $city_stats  — array of objects { city, total, latest }
 *   $recent      — array of obituary objects
 *
 * @package Ontario_Obituaries
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header();
?>

<div class="ontario-obituaries-seo-hub" style="max-width: 1200px; margin: 0 auto; padding: 20px;">

    <h1><?php esc_html_e( 'Ontario Obituaries', 'ontario-obituaries' ); ?></h1>

    <div class="ontario-obituaries-hub-intro" style="margin-bottom: 30px;">
        <p><?php esc_html_e( 'Browse recent obituary notices from communities across Ontario, Canada. Our obituary directory provides factual information to help you find funeral services, visitation details, and memorial information.', 'ontario-obituaries' ); ?></p>
        <p><?php printf(
            esc_html__( 'This service is provided by %s, located in Newmarket, Ontario — serving York Region and Southern Ontario families with personalized monuments and memorials.', 'ontario-obituaries' ),
            '<a href="https://monacomonuments.ca">Monaco Monuments</a>'
        ); ?></p>
    </div>

    <!-- York Region Spotlight — drives local traffic -->
    <section class="ontario-obituaries-york-region" style="margin-bottom: 40px; padding: 25px; background: linear-gradient(135deg, #f0f4f8, #e8eef5); border-radius: 8px; border-left: 4px solid #2c3e50;">
        <h2><?php esc_html_e( 'York Region & Newmarket Obituaries', 'ontario-obituaries' ); ?></h2>
        <p><?php esc_html_e( 'Monaco Monuments is proud to serve the York Region community from our Newmarket location. Browse obituary notices from Newmarket, Aurora, Richmond Hill, Markham, Vaughan, and surrounding areas.', 'ontario-obituaries' ); ?></p>
        <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 15px;">
            <?php
            $york_cities = array( 'Newmarket', 'Aurora', 'Richmond Hill', 'Markham', 'Vaughan', 'Stouffville', 'King City', 'Georgina', 'East Gwillimbury', 'Bradford' );
            foreach ( $york_cities as $yr_city ) :
                $yr_slug = sanitize_title( $yr_city );
            ?>
                <a href="<?php echo esc_url( home_url( '/obituaries/ontario/' . $yr_slug . '/' ) ); ?>"
                   style="display: inline-block; padding: 8px 16px; background: #fff; border: 1px solid #d0d5dd; border-radius: 20px; text-decoration: none; color: #2c3e50; font-size: 0.9em;">
                    <?php echo esc_html( $yr_city ); ?>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ( ! empty( $city_stats ) ) : ?>
    <section class="ontario-obituaries-city-directory" style="margin-bottom: 40px;">
        <h2><?php esc_html_e( 'Browse Obituaries by City', 'ontario-obituaries' ); ?></h2>
        <div class="ontario-city-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px;">
            <?php foreach ( $city_stats as $stat ) :
                $slug = sanitize_title( $stat->city );
            ?>
                <a href="<?php echo esc_url( home_url( '/obituaries/ontario/' . $slug . '/' ) ); ?>"
                   class="ontario-city-card" style="display: block; padding: 15px; background: #f8f9fa; border-radius: 8px; text-decoration: none; color: inherit;">
                    <strong><?php echo esc_html( $stat->city ); ?></strong>
                    <span style="display: block; color: #666; font-size: 0.9em;">
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
        <h2><?php esc_html_e( 'Recent Obituary Notices', 'ontario-obituaries' ); ?></h2>
        <div class="ontario-obituaries-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px;">
            <?php foreach ( $recent as $obit ) :
                $city_slug = sanitize_title( ! empty( $obit->city_normalized ) ? $obit->city_normalized : $obit->location );
                $name_slug = sanitize_title( $obit->name ) . '-' . $obit->id;
            ?>
                <div class="ontario-obituary-card" style="background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px;">
                    <h3 style="margin-top: 0;">
                        <a href="<?php echo esc_url( home_url( '/obituaries/ontario/' . $city_slug . '/' . $name_slug . '/' ) ); ?>"
                           style="text-decoration: none; color: #2c3e50;">
                            <?php echo esc_html( $obit->name ); ?>
                        </a>
                    </h3>
                    <p style="color: #666; margin: 5px 0;">
                        <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $obit->date_of_death ) ) ); ?>
                        <?php if ( ! empty( $obit->location ) ) : ?>
                            &mdash; <?php echo esc_html( $obit->location ); ?>
                        <?php endif; ?>
                    </p>
                    <?php if ( ! empty( $obit->funeral_home ) ) : ?>
                        <p style="color: #888; font-size: 0.9em;"><?php echo esc_html( $obit->funeral_home ); ?></p>
                    <?php endif; ?>
                    <a href="<?php echo esc_url( home_url( '/obituaries/ontario/' . $city_slug . '/' . $name_slug . '/' ) ); ?>" style="font-size: 0.85em; color: #3498db;">
                        <?php esc_html_e( 'Read More', 'ontario-obituaries' ); ?> &rarr;
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="ontario-obituaries-services" style="margin-top: 40px; padding: 30px; background: #f0f4f8; border-radius: 8px;">
        <h2><?php esc_html_e( 'Monaco Monuments — Personalized Memorials in Newmarket', 'ontario-obituaries' ); ?></h2>
        <p><?php esc_html_e( 'Located at 109 Harry Walker Pkwy S, Newmarket, Ontario, Monaco Monuments creates unique, one-of-a-kind monuments and memorials meant to be cherished for eternity. We serve families throughout York Region and Southern Ontario.', 'ontario-obituaries' ); ?></p>
        <p style="color: #555; font-size: 0.95em;">
            <?php esc_html_e( 'Serving: Newmarket, Aurora, Richmond Hill, Markham, Vaughan, King City, Stouffville, Barrie, and all of Southern Ontario.', 'ontario-obituaries' ); ?>
        </p>
        <p>
            <a href="https://monacomonuments.ca/catalog/" class="button" style="display: inline-block; padding: 10px 20px; background: #2c3e50; color: #fff; text-decoration: none; border-radius: 4px;">
                <?php esc_html_e( 'Browse Our Catalog', 'ontario-obituaries' ); ?>
            </a>
            <a href="https://monacomonuments.ca/contact/" style="margin-left: 15px; color: #2c3e50;">
                <?php esc_html_e( 'Contact Us', 'ontario-obituaries' ); ?> &rarr;
            </a>
            <a href="tel:+19058986262" style="margin-left: 15px; color: #2c3e50;">
                <?php esc_html_e( '(905) 898-6262', 'ontario-obituaries' ); ?>
            </a>
        </p>
    </section>

</div>

<?php
get_footer();
