<?php
/**
 * SEO Template: City Hub Page
 *
 * Variables provided by Ontario_Obituaries_SEO::render_city_hub():
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
 *
 * v3.10.2: Updated date display to use ontario_obituaries_format_date()
 *          for smart year-only handling.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header();
?>

<div class="ontario-obituaries-city-hub" style="max-width: 1200px; margin: 0 auto; padding: 20px;">

    <!-- Breadcrumbs -->
    <nav class="ontario-obituaries-breadcrumbs" style="margin-bottom: 20px; font-size: 0.9em; color: #666;">
        <a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'ontario-obituaries' ); ?></a> &rsaquo;
        <a href="<?php echo esc_url( home_url( '/obituaries/ontario/' ) ); ?>"><?php esc_html_e( 'Ontario Obituaries', 'ontario-obituaries' ); ?></a> &rsaquo;
        <span><?php echo esc_html( $city_name ); ?></span>
    </nav>

    <h1><?php printf( esc_html__( '%s Obituaries — Ontario', 'ontario-obituaries' ), esc_html( $city_name ) ); ?></h1>

    <div class="ontario-obituaries-city-intro" style="margin-bottom: 30px;">
        <p><?php printf(
            esc_html__( 'Recent obituary notices from %1$s, Ontario, Canada. We collect publicly available factual information from local funeral homes and news sources to help families find memorial service details. Currently showing %2$d obituary notices from %1$s.', 'ontario-obituaries' ),
            esc_html( $city_name ),
            intval( $total )
        ); ?></p>
    </div>

    <?php if ( empty( $obituaries ) ) : ?>
        <div class="ontario-obituaries-empty" style="padding: 40px; text-align: center; background: #f8f9fa; border-radius: 8px;">
            <p><?php printf( esc_html__( 'No obituary notices found for %s at this time. Check back soon.', 'ontario-obituaries' ), esc_html( $city_name ) ); ?></p>
        </div>
    <?php else : ?>

        <div class="ontario-obituaries-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; margin-bottom: 30px;">
            <?php foreach ( $obituaries as $obit ) :
                $name_slug = sanitize_title( $obit->name ) . '-' . $obit->id;
                $obit_url  = home_url( '/obituaries/ontario/' . $city_slug . '/' . $name_slug . '/' );
            ?>
                <article class="ontario-obituary-card" style="background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px;" itemscope itemtype="https://schema.org/Person">
                    <h3 style="margin-top: 0;">
                        <a href="<?php echo esc_url( $obit_url ); ?>" itemprop="name" style="text-decoration: none; color: #2c3e50;">
                            <?php echo esc_html( $obit->name ); ?>
                        </a>
                    </h3>

                    <div style="color: #666; margin: 8px 0;">
                        <?php
                        // v3.10.2: Smart date formatting
                        $dob = ! empty( $obit->date_of_birth ) ? $obit->date_of_birth : '';
                        $dod = ! empty( $obit->date_of_death ) ? $obit->date_of_death : '';
                        $dob_display = ontario_obituaries_format_date( $dob, $dod );
                        $dod_display = ontario_obituaries_format_date( $dod, $dob );
                        ?>
                        <?php if ( ! empty( $dob_display ) ) : ?>
                            <span itemprop="birthDate" content="<?php echo esc_attr( $obit->date_of_birth ); ?>">
                                <?php echo esc_html( $dob_display ); ?>
                            </span> &ndash;
                        <?php endif; ?>
                        <span itemprop="deathDate" content="<?php echo esc_attr( $obit->date_of_death ); ?>">
                            <?php echo esc_html( $dod_display ); ?>
                        </span>
                        <?php if ( ! empty( $obit->age ) && $obit->age > 0 ) : ?>
                            <span>(<?php echo esc_html( $obit->age ); ?> <?php esc_html_e( 'years', 'ontario-obituaries' ); ?>)</span>
                        <?php endif; ?>
                    </div>

                    <?php if ( ! empty( $obit->funeral_home ) ) : ?>
                        <p style="color: #888; font-size: 0.9em; margin: 5px 0;"><?php echo esc_html( $obit->funeral_home ); ?></p>
                    <?php endif; ?>

                    <?php if ( ! empty( $obit->description ) ) : ?>
                        <p style="font-size: 0.95em; color: #444;">
                            <?php echo esc_html( wp_trim_words( $obit->description, 25, '...' ) ); ?>
                        </p>
                    <?php endif; ?>

                    <div style="margin-top: 10px; font-size: 0.85em;">
                        <a href="<?php echo esc_url( $obit_url ); ?>" style="color: #3498db;">
                            <?php esc_html_e( 'Read More', 'ontario-obituaries' ); ?> &rarr;
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if ( $total_pages > 1 ) : ?>
        <nav class="ontario-obituaries-pagination" style="text-align: center; margin: 30px 0;">
            <?php for ( $p = 1; $p <= min( $total_pages, 20 ); $p++ ) :
                $url = add_query_arg( 'pg', $p, home_url( '/obituaries/ontario/' . $city_slug . '/' ) );
            ?>
                <?php if ( $p === $page ) : ?>
                    <strong style="margin: 0 5px; padding: 5px 12px; background: #2c3e50; color: #fff; border-radius: 4px;"><?php echo intval( $p ); ?></strong>
                <?php else : ?>
                    <a href="<?php echo esc_url( $url ); ?>" style="margin: 0 5px; padding: 5px 12px; text-decoration: none; border: 1px solid #ddd; border-radius: 4px; color: #2c3e50;"><?php echo intval( $p ); ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </nav>
        <?php endif; ?>

    <?php endif; ?>

    <!-- Local Services Section — Internal Link to Monaco Monuments -->
    <section class="ontario-obituaries-local-services" style="margin-top: 40px; padding: 25px; background: #f0f4f8; border-radius: 8px;">
        <h2><?php printf( esc_html__( 'Monument Services near %s', 'ontario-obituaries' ), esc_html( $city_name ) ); ?></h2>
        <p><?php printf(
            esc_html__( 'Monaco Monuments, located in Newmarket, Ontario, serves families in %s, across York Region, and throughout Southern Ontario. Each monument we create is a unique, one-of-a-kind celebration of life meant to be cherished for eternity.', 'ontario-obituaries' ),
            esc_html( $city_name )
        ); ?></p>
        <p style="color: #666; font-size: 0.9em;">
            <?php esc_html_e( '109 Harry Walker Pkwy S, Newmarket, ON L3Y 7B3 | (905) 898-6262', 'ontario-obituaries' ); ?>
        </p>
        <p>
            <a href="https://monacomonuments.ca/contact/" style="display: inline-block; padding: 10px 20px; background: #2c3e50; color: #fff; text-decoration: none; border-radius: 4px;">
                <?php esc_html_e( 'Get a Free Consultation', 'ontario-obituaries' ); ?>
            </a>
            <a href="https://monacomonuments.ca/catalog/" style="margin-left: 15px; color: #2c3e50;">
                <?php esc_html_e( 'View Our Catalog', 'ontario-obituaries' ); ?> &rarr;
            </a>
            <a href="tel:+19058986262" style="margin-left: 15px; color: #2c3e50;">
                <?php esc_html_e( 'Call Us', 'ontario-obituaries' ); ?>
            </a>
        </p>
    </section>

    <!-- Removal / Correction Notice -->
    <div class="ontario-obituaries-disclaimer" style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e0e0e0; font-size: 0.85em; color: #888;">
        <p><?php esc_html_e( 'All obituary information is obtained from publicly available sources. If you wish to have a listing removed or corrected, please contact us.', 'ontario-obituaries' ); ?>
        <a href="https://monacomonuments.ca/contact/" style="color: #3498db;"><?php esc_html_e( 'Request a removal', 'ontario-obituaries' ); ?></a>.</p>
    </div>

</div>

<?php
get_footer();
