<?php
/**
 * SEO Template: Individual Obituary Page
 *
 * Variables provided by Ontario_Obituaries_SEO::render_individual_page():
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

get_header();

$city_name = ! empty( $obituary->city_normalized ) ? $obituary->city_normalized : $obituary->location;
?>

<?php if ( ! $should_index ) : ?>
<meta name="robots" content="noindex, follow">
<?php endif; ?>

<div class="ontario-obituary-individual" style="max-width: 800px; margin: 0 auto; padding: 20px;" itemscope itemtype="https://schema.org/Person">

    <!-- Breadcrumbs -->
    <nav class="ontario-obituaries-breadcrumbs" style="margin-bottom: 20px; font-size: 0.9em; color: #666;">
        <a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'ontario-obituaries' ); ?></a> &rsaquo;
        <a href="<?php echo esc_url( home_url( '/obituaries/ontario/' ) ); ?>"><?php esc_html_e( 'Ontario Obituaries', 'ontario-obituaries' ); ?></a> &rsaquo;
        <?php if ( ! empty( $city_name ) ) : ?>
            <a href="<?php echo esc_url( home_url( '/obituaries/ontario/' . sanitize_title( $city_name ) . '/' ) ); ?>">
                <?php echo esc_html( $city_name ); ?>
            </a> &rsaquo;
        <?php endif; ?>
        <span><?php echo esc_html( $obituary->name ); ?></span>
    </nav>

    <article class="ontario-obituary-page">
        <header style="margin-bottom: 30px;">
            <h1 itemprop="name" style="margin-bottom: 10px;"><?php echo esc_html( $obituary->name ); ?></h1>

            <div class="ontario-obituary-dates" style="color: #555; font-size: 1.1em; margin-bottom: 10px;">
                <?php if ( ! empty( $obituary->date_of_birth ) && '0000-00-00' !== $obituary->date_of_birth ) : ?>
                    <span itemprop="birthDate" content="<?php echo esc_attr( $obituary->date_of_birth ); ?>">
                        <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $obituary->date_of_birth ) ) ); ?>
                    </span> &ndash;
                <?php endif; ?>
                <span itemprop="deathDate" content="<?php echo esc_attr( $obituary->date_of_death ); ?>">
                    <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $obituary->date_of_death ) ) ); ?>
                </span>
                <?php if ( ! empty( $obituary->age ) && $obituary->age > 0 ) : ?>
                    <span>(<?php echo esc_html( $obituary->age ); ?> <?php esc_html_e( 'years', 'ontario-obituaries' ); ?>)</span>
                <?php endif; ?>
            </div>

            <?php if ( ! empty( $obituary->location ) ) : ?>
                <div style="color: #666; margin-bottom: 5px;" itemprop="deathPlace" itemscope itemtype="https://schema.org/Place">
                    <span itemprop="name"><?php echo esc_html( $obituary->location ); ?></span>, Ontario
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $obituary->funeral_home ) ) : ?>
                <div style="color: #888; font-size: 0.95em;">
                    <?php echo esc_html( $obituary->funeral_home ); ?>
                </div>
            <?php endif; ?>
        </header>

        <?php if ( ! empty( $obituary->description ) ) : ?>
        <div class="ontario-obituary-content" style="line-height: 1.8; margin-bottom: 30px;">
            <?php echo wpautop( esc_html( $obituary->description ) ); ?>
        </div>
        <?php endif; ?>

        <footer style="border-top: 1px solid #e0e0e0; padding-top: 20px; margin-top: 30px;">
            <?php if ( ! empty( $obituary->source_url ) ) : ?>
                <p>
                    <a href="<?php echo esc_url( $obituary->source_url ); ?>" target="_blank" rel="noopener noreferrer" style="color: #3498db;">
                        <?php esc_html_e( 'View Original Obituary', 'ontario-obituaries' ); ?> &rarr;
                    </a>
                </p>
            <?php endif; ?>

            <!-- Back to city hub -->
            <?php if ( ! empty( $city_name ) ) : ?>
                <p>
                    <a href="<?php echo esc_url( home_url( '/obituaries/ontario/' . sanitize_title( $city_name ) . '/' ) ); ?>" style="color: #2c3e50;">
                        &larr; <?php printf( esc_html__( 'Back to %s Obituaries', 'ontario-obituaries' ), esc_html( $city_name ) ); ?>
                    </a>
                </p>
            <?php endif; ?>
        </footer>
    </article>

    <!-- Monument Services CTA -->
    <aside class="ontario-obituaries-cta" style="margin-top: 40px; padding: 25px; background: #f0f4f8; border-radius: 8px;">
        <h3><?php esc_html_e( 'Personalized Monuments — Monaco Monuments, Newmarket', 'ontario-obituaries' ); ?></h3>
        <p><?php esc_html_e( 'Each monument we create is unique, a one-of-a-kind celebration of life meant to be cherished for eternity. Serving families in York Region, Newmarket, Aurora, Richmond Hill, and throughout Southern Ontario.', 'ontario-obituaries' ); ?></p>
        <p style="color: #666; font-size: 0.9em;">
            <?php esc_html_e( '109 Harry Walker Pkwy S, Newmarket, ON L3Y 7B3 | (905) 898-6262', 'ontario-obituaries' ); ?>
        </p>
        <a href="https://monacomonuments.ca/contact/" style="display: inline-block; padding: 10px 20px; background: #2c3e50; color: #fff; text-decoration: none; border-radius: 4px;">
            <?php esc_html_e( 'Contact Monaco Monuments', 'ontario-obituaries' ); ?>
        </a>
        <a href="https://monacomonuments.ca/catalog/" style="margin-left: 15px; color: #2c3e50;">
            <?php esc_html_e( 'Browse Our Catalog', 'ontario-obituaries' ); ?> &rarr;
        </a>
    </aside>

    <!-- Removal notice -->
    <div style="margin-top: 20px; font-size: 0.85em; color: #999;">
        <p><?php esc_html_e( 'This information was compiled from publicly available sources.', 'ontario-obituaries' ); ?>
        <a href="https://monacomonuments.ca/contact/" style="color: #999; text-decoration: underline;"><?php esc_html_e( 'Request removal or correction', 'ontario-obituaries' ); ?></a>.</p>
    </div>

</div>

<?php
get_footer();
