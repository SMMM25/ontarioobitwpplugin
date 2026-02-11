<?php
/**
 * Template for displaying a single obituary in the modal or detail view.
 *
 * Rendered server-side by Ontario_Obituaries_Ajax::get_obituary_detail()
 * and injected into the modal via AJAX. Also used if the AJAX endpoint is
 * called directly (backward compatibility).
 *
 * v3.10.1: Footer removed (View Full Obituary, Print, Share, Obituary Assistant).
 *          Modal is now a clean read-only view: name, dates, location, photo, description.
 *          The AJAX endpoint and this template are kept for backward compatibility;
 *          the Quick View button that triggered them has been removed from obituaries.php.
 *
 * v3.14.1: Redesigned with Monaco Monuments branding to match the main listing.
 *
 * @var object $obituary The obituary object
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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

<div class="ontario-obituary-detail">
    <div class="ontario-obituary-detail-header">
        <h2 class="ontario-obituary-detail-name"><?php echo esc_html( $obituary->name ); ?></h2>

        <?php if ( $date_range_display ) : ?>
        <div class="ontario-obituary-detail-dates">
            <?php echo $date_range_display; ?>
        </div>
        <?php endif; ?>

        <?php if ( ! empty( $obituary->age ) && intval( $obituary->age ) > 0 ) : ?>
        <div class="ontario-obituary-detail-age">
            <?php printf( esc_html__( 'Age %d', 'ontario-obituaries' ), intval( $obituary->age ) ); ?>
        </div>
        <?php endif; ?>

        <div class="ontario-obituary-detail-meta">
            <?php if ( ! empty( $obituary->location ) || ! empty( $obituary->funeral_home ) ) : ?>
            <div class="ontario-obituary-detail-location">
                <?php if ( ! empty( $obituary->location ) ) : ?>
                <span class="ontario-obituary-location-tag">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                    <?php echo esc_html( $obituary->location ); ?>
                </span>
                <?php endif; ?>

                <?php if ( ! empty( $obituary->funeral_home ) ) : ?>
                <span class="ontario-obituary-funeral-home">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M3 10h18M5 6l7-3 7 3M4 10v11M20 10v11M8 14v4M12 14v4M16 14v4"></path></svg>
                    <?php echo esc_html( $obituary->funeral_home ); ?>
                </span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="ontario-obituary-detail-content">
        <?php if ( ! empty( $obituary->image_url ) ) : ?>
            <div class="ontario-obituary-detail-image">
                <img src="<?php echo esc_url( $obituary->image_url ); ?>"
                     alt="<?php echo esc_attr( sprintf( __( 'Photo of %s', 'ontario-obituaries' ), $obituary->name ) ); ?>"
                     loading="lazy">
            </div>
        <?php endif; ?>

        <div class="ontario-obituary-detail-description">
            <?php echo wpautop( wp_kses_post( $obituary->description ) ); ?>
        </div>
    </div>
</div>
