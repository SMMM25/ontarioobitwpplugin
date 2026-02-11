<?php
/**
 * Template for displaying a single obituary in the modal.
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
 * @var object $obituary The obituary object
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="ontario-obituary-detail">
    <div class="ontario-obituary-detail-header">
        <h2 class="ontario-obituary-detail-name"><?php echo esc_html( $obituary->name ); ?></h2>

        <div class="ontario-obituary-detail-meta">
            <?php
            // v3.13.3: Dates removed from detail header per owner request.
            // They are shown as "Date published" in the provenance footer on the SEO page.
            ?>

            <?php if ( ! empty( $obituary->location ) || ! empty( $obituary->funeral_home ) ) : ?>
            <div class="ontario-obituary-detail-location">
                <?php if ( ! empty( $obituary->location ) ) : ?>
                <span class="ontario-obituary-location-tag"><?php echo esc_html( $obituary->location ); ?></span>
                <?php endif; ?>

                <?php if ( ! empty( $obituary->funeral_home ) ) : ?>
                <span class="ontario-obituary-funeral-home"><?php echo esc_html( $obituary->funeral_home ); ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="ontario-obituary-detail-content">
        <?php if ( ! empty( $obituary->image_url ) ) : ?>
            <div class="ontario-obituary-detail-image">
                <img src="<?php echo esc_url( $obituary->image_url ); ?>" alt="<?php echo esc_attr( sprintf( __( 'Photo of %s', 'ontario-obituaries' ), $obituary->name ) ); ?>">
            </div>
        <?php endif; ?>

        <div class="ontario-obituary-detail-description">
            <?php echo wpautop( wp_kses_post( $obituary->description ) ); ?>
        </div>
    </div>
</div>
