
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

<style>
.ontario-obituary-detail {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
}

.ontario-obituary-detail-header {
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #eee;
}

.ontario-obituary-detail-name {
    margin: 0 0 10px 0;
    font-size: 24px;
    font-weight: 600;
    color: #333;
}

.ontario-obituary-detail-meta {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 10px;
}

.ontario-obituary-detail-dates {
    font-size: 16px;
    color: #666;
}

.ontario-obituary-detail-location {
    display: flex;
    align-items: center;
    gap: 10px;
}

.ontario-obituary-location-tag {
    background-color: #f3f3f3;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 14px;
    color: #666;
}

.ontario-obituary-funeral-home {
    font-size: 14px;
    color: #888;
}

.ontario-obituary-detail-content {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

@media (min-width: 768px) {
    .ontario-obituary-detail-content {
        flex-direction: row;
    }
}

.ontario-obituary-detail-image {
    flex: 0 0 35%;
    max-width: 300px;
    margin: 0 auto;
}

@media (min-width: 768px) {
    .ontario-obituary-detail-image {
        margin: 0;
    }
}

.ontario-obituary-detail-image img {
    width: 100%;
    height: auto;
    border-radius: 8px;
    object-fit: cover;
}

.ontario-obituary-detail-description {
    flex: 1;
    font-size: 15px;
    line-height: 1.6;
    color: #444;
}

.ontario-obituary-detail-footer {
    margin-top: 30px;
    text-align: center;
}

.ontario-obituary-detail-source-link {
    display: inline-block;
    padding: 10px 20px;
    background-color: #f3f3f3;
    color: #333;
    text-decoration: none;
    border-radius: 4px;
    font-size: 14px;
    transition: background-color 0.2s;
}

.ontario-obituary-detail-source-link:hover {
    background-color: #e6e6e6;
    text-decoration: none;
}
</style>
