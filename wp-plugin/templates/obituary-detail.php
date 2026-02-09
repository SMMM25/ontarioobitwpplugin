
<?php
/**
 * Template for displaying a single obituary in the modal
 * 
 * @var object $obituary The obituary object
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="ontario-obituary-detail">
    <div class="ontario-obituary-detail-header">
        <h2 class="ontario-obituary-detail-name"><?php echo esc_html($obituary->name); ?></h2>
        
        <div class="ontario-obituary-detail-meta">
            <div class="ontario-obituary-detail-dates">
                <?php if (!empty($obituary->date_of_birth) && $obituary->date_of_birth !== '0000-00-00'): ?>
                    <span><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($obituary->date_of_birth))); ?> - </span>
                <?php endif; ?>
                <span><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($obituary->date_of_death))); ?></span>
                <?php if (!empty($obituary->age)): ?>
                    <span>(<?php echo esc_html($obituary->age); ?> <?php esc_html_e('years', 'ontario-obituaries'); ?>)</span>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($obituary->location) || !empty($obituary->funeral_home)): ?>
            <div class="ontario-obituary-detail-location">
                <?php if (!empty($obituary->location)): ?>
                <span class="ontario-obituary-location-tag"><?php echo esc_html($obituary->location); ?></span>
                <?php endif; ?>
                
                <?php if (!empty($obituary->funeral_home)): ?>
                <span class="ontario-obituary-funeral-home"><?php echo esc_html($obituary->funeral_home); ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="ontario-obituary-detail-content">
        <?php if (!empty($obituary->image_url)): ?>
            <div class="ontario-obituary-detail-image">
                <img src="<?php echo esc_url($obituary->image_url); ?>" alt="<?php echo esc_attr(sprintf(__('Photo of %s', 'ontario-obituaries'), $obituary->name)); ?>">
            </div>
        <?php endif; ?>
        
        <div class="ontario-obituary-detail-description">
            <?php echo wpautop(wp_kses_post($obituary->description)); ?>
        </div>
    </div>
    
    <?php
    // Build internal SEO page URL (keeps traffic on-site)
    $detail_city_slug = sanitize_title(
        ! empty( $obituary->city_normalized ) ? $obituary->city_normalized
        : ( ! empty( $obituary->location ) ? $obituary->location : 'ontario' )
    );
    $detail_name_slug = sanitize_title( $obituary->name ) . '-' . $obituary->id;
    $detail_internal_url = home_url( '/obituaries/ontario/' . $detail_city_slug . '/' . $detail_name_slug . '/' );
    ?>
    <div class="ontario-obituary-detail-footer">
        <div class="ontario-obituary-detail-actions">
            <!-- Link to full SEO page (keeps traffic on-site) -->
            <a href="<?php echo esc_url( $detail_internal_url ); ?>" class="ontario-obituary-detail-full-page-link">
                <?php esc_html_e( 'View Full Obituary', 'ontario-obituaries' ); ?>
            </a>

            <?php if ( ! empty( $obituary->source_url ) ) : ?>
                <span style="font-size: 0.8em; color: #999; margin-left: 10px;">
                    <?php esc_html_e( 'Source:', 'ontario-obituaries' ); ?>
                    <a href="<?php echo esc_url( $obituary->source_url ); ?>" target="_blank" rel="noopener noreferrer nofollow" style="color: #999;">
                        <?php echo esc_html( wp_parse_url( $obituary->source_url, PHP_URL_HOST ) ); ?>
                    </a>
                </span>
            <?php endif; ?>

            <!-- Print button -->
            <button class="ontario-obituary-print-button" onclick="window.print();">
                <span class="dashicons dashicons-printer"></span> <?php esc_html_e( 'Print', 'ontario-obituaries' ); ?>
            </button>
        </div>

        <!-- Integration with Obituary Assistant if available -->
        <?php if ( function_exists( 'ontario_obituaries_check_dependency' ) && ontario_obituaries_check_dependency() ) : ?>
        <div class="ontario-obituary-assistant-actions" style="margin-top: 10px;">
            <a href="<?php echo esc_url( add_query_arg( array( 'source' => 'ontario', 'obituary_id' => $obituary->id ), site_url( '/obituary-assistant/' ) ) ); ?>" class="button ontario-obituary-assistant-button">
                <?php esc_html_e( 'Open in Obituary Assistant', 'ontario-obituaries' ); ?>
            </a>
        </div>
        <?php endif; ?>

        <!-- Social sharing links -->
        <div class="ontario-obituary-social-sharing" style="margin-top: 10px;">
            <?php if ( get_option( 'ontario_obituaries_enable_facebook', '1' ) ) : ?>
            <a href="#" class="ontario-obituary-share-facebook" data-id="<?php echo esc_attr( $obituary->id ); ?>" data-name="<?php echo esc_attr( $obituary->name ); ?>" data-url="<?php echo esc_url( $detail_internal_url ); ?>">
                <span class="dashicons dashicons-facebook"></span> <?php esc_html_e( 'Share on Facebook', 'ontario-obituaries' ); ?>
            </a>
            <?php endif; ?>

            <a href="mailto:?subject=<?php echo esc_attr( sprintf( __( 'Obituary for %s', 'ontario-obituaries' ), $obituary->name ) ); ?>&body=<?php echo esc_attr( sprintf( __( 'I thought you might want to see this obituary for %s: %s', 'ontario-obituaries' ), $obituary->name, esc_url( $detail_internal_url ) ) ); ?>" class="ontario-obituary-share-email">
                <span class="dashicons dashicons-email"></span> <?php esc_html_e( 'Share via Email', 'ontario-obituaries' ); ?>
            </a>
        </div>
    </div>
</div>
