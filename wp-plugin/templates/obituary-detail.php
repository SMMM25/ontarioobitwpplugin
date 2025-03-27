
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
                <?php if (!empty($obituary->date_of_birth)): ?>
                    <span><?php echo esc_html($obituary->date_of_birth); ?> - </span>
                <?php endif; ?>
                <span><?php echo esc_html($obituary->date_of_death); ?></span>
                <?php if (!empty($obituary->age)): ?>
                    <span>(<?php echo esc_html($obituary->age); ?> <?php _e('years', 'ontario-obituaries'); ?>)</span>
                <?php endif; ?>
            </div>
            
            <div class="ontario-obituary-detail-location">
                <span class="ontario-obituary-location-tag"><?php echo esc_html($obituary->location); ?></span>
                <span class="ontario-obituary-funeral-home"><?php echo esc_html($obituary->funeral_home); ?></span>
            </div>
        </div>
    </div>
    
    <div class="ontario-obituary-detail-content">
        <?php if (!empty($obituary->image_url)): ?>
            <div class="ontario-obituary-detail-image">
                <img src="<?php echo esc_url($obituary->image_url); ?>" alt="<?php echo esc_attr(sprintf(__('Photo of %s', 'ontario-obituaries'), $obituary->name)); ?>">
            </div>
        <?php endif; ?>
        
        <div class="ontario-obituary-detail-description">
            <?php echo wpautop(esc_html($obituary->description)); ?>
        </div>
    </div>
    
    <div class="ontario-obituary-detail-footer">
        <?php if (!empty($obituary->source_url)): ?>
            <a href="<?php echo esc_url($obituary->source_url); ?>" target="_blank" rel="noopener noreferrer" class="ontario-obituary-detail-source-link">
                <?php _e('View Original Obituary', 'ontario-obituaries'); ?>
            </a>
        <?php endif; ?>
    </div>
</div>
