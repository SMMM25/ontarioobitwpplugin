
<?php
/**
 * Template for displaying obituaries
 * 
 * @var array $atts Shortcode attributes
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Get settings
$settings = get_option('ontario_obituaries_settings', array());

// Get display object
$display = new Ontario_Obituaries_Display();

// Get query args from request
$search = isset($_GET['ontario_obituaries_search']) ? sanitize_text_field($_GET['ontario_obituaries_search']) : '';
$location = isset($_GET['ontario_obituaries_location']) ? sanitize_text_field($_GET['ontario_obituaries_location']) : $atts['location'];
$page = isset($_GET['ontario_obituaries_page']) ? max(1, intval($_GET['ontario_obituaries_page'])) : 1;

// Set up the query
$args = array(
    'limit' => intval($atts['limit']),
    'offset' => (intval($atts['limit']) * ($page - 1)),
    'location' => $location,
    'funeral_home' => $atts['funeral_home'],
    'days' => intval($atts['days']),
    'search' => $search
);

// Get obituaries
$obituaries = $display->get_obituaries($args);

// Get total count
$total = $display->count_obituaries(array(
    'location' => $location,
    'funeral_home' => $atts['funeral_home'],
    'days' => intval($atts['days']),
    'search' => $search
));

// Calculate pagination
$total_pages = ceil($total / intval($atts['limit']));

// Get locations for filter
$locations = $display->get_locations();

// Add plugin CSS
wp_enqueue_style('ontario-obituaries-css');
wp_enqueue_script('ontario-obituaries-js');
?>

<div class="ontario-obituaries-container">
    <div class="ontario-obituaries-filters">
        <form method="get" class="ontario-obituaries-search-form">
            <?php
            // Preserve existing query params
            foreach ($_GET as $key => $value) {
                if (!in_array($key, array('ontario_obituaries_search', 'ontario_obituaries_location', 'ontario_obituaries_page'))) {
                    echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
                }
            }
            ?>
            
            <div class="ontario-obituaries-search">
                <input type="text" name="ontario_obituaries_search" placeholder="<?php esc_attr_e('Search obituaries...', 'ontario-obituaries'); ?>" value="<?php echo esc_attr($search); ?>">
                <button type="submit" class="ontario-obituaries-search-button">
                    <span class="dashicons dashicons-search"></span>
                </button>
            </div>
            
            <?php if (!empty($locations)): ?>
                <div class="ontario-obituaries-location-filter">
                    <select name="ontario_obituaries_location">
                        <option value=""><?php _e('All Locations', 'ontario-obituaries'); ?></option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?php echo esc_attr($loc); ?>" <?php selected($location, $loc); ?>><?php echo esc_html($loc); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="ontario-obituaries-filter-button">
                        <?php _e('Filter', 'ontario-obituaries'); ?>
                    </button>
                </div>
            <?php endif; ?>
            
            <?php if ($search || $location): ?>
                <div class="ontario-obituaries-clear-filters">
                    <a href="<?php echo esc_url(remove_query_arg(array('ontario_obituaries_search', 'ontario_obituaries_location', 'ontario_obituaries_page'))); ?>">
                        <?php _e('Clear Filters', 'ontario-obituaries'); ?>
                    </a>
                </div>
            <?php endif; ?>
        </form>
        
        <div class="ontario-obituaries-count">
            <?php printf(_n('%d obituary found', '%d obituaries found', $total, 'ontario-obituaries'), $total); ?>
        </div>
    </div>
    
    <?php if (empty($obituaries)): ?>
        <div class="ontario-obituaries-empty">
            <p><?php _e('No obituaries found.', 'ontario-obituaries'); ?></p>
            <?php if ($search || $location): ?>
                <p><?php _e('Try adjusting your search or filters.', 'ontario-obituaries'); ?></p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="ontario-obituaries-grid">
            <?php foreach ($obituaries as $index => $obituary): ?>
                <div class="ontario-obituaries-card" data-index="<?php echo esc_attr($index); ?>">
                    <?php if (!empty($obituary->image_url)): ?>
                        <div class="ontario-obituaries-card-image">
                            <img src="<?php echo esc_url($obituary->image_url); ?>" alt="<?php echo esc_attr(sprintf(__('Photo of %s', 'ontario-obituaries'), $obituary->name)); ?>">
                        </div>
                    <?php endif; ?>
                    
                    <div class="ontario-obituaries-card-content">
                        <div class="ontario-obituaries-card-meta">
                            <span class="ontario-obituaries-location"><?php echo esc_html($obituary->location); ?></span>
                            <span class="ontario-obituaries-funeral-home"><?php echo esc_html($obituary->funeral_home); ?></span>
                        </div>
                        
                        <h3 class="ontario-obituaries-name"><?php echo esc_html($obituary->name); ?></h3>
                        
                        <div class="ontario-obituaries-dates">
                            <?php if (!empty($obituary->date_of_birth)): ?>
                                <span><?php echo esc_html($obituary->date_of_birth); ?> - </span>
                            <?php endif; ?>
                            <span><?php echo esc_html($obituary->date_of_death); ?></span>
                            <?php if (!empty($obituary->age)): ?>
                                <span>(<?php echo esc_html($obituary->age); ?> <?php _e('years', 'ontario-obituaries'); ?>)</span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="ontario-obituaries-description">
                            <p><?php echo wp_trim_words(esc_html($obituary->description), 30, '...'); ?></p>
                        </div>
                        
                        <div class="ontario-obituaries-actions">
                            <button class="ontario-obituaries-read-more" data-id="<?php echo esc_attr($obituary->id); ?>">
                                <?php _e('Read More', 'ontario-obituaries'); ?>
                            </button>
                            
                            <?php if (!empty($obituary->source_url)): ?>
                                <a href="<?php echo esc_url($obituary->source_url); ?>" target="_blank" rel="noopener noreferrer" class="ontario-obituaries-source-link">
                                    <?php _e('View Original', 'ontario-obituaries'); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <?php if ($total_pages > 1): ?>
            <div class="ontario-obituaries-pagination">
                <?php
                $pagination_links = paginate_links(array(
                    'base' => add_query_arg('ontario_obituaries_page', '%#%'),
                    'format' => '',
                    'prev_text' => __('&laquo; Previous', 'ontario-obituaries'),
                    'next_text' => __('Next &raquo;', 'ontario-obituaries'),
                    'total' => $total_pages,
                    'current' => $page,
                    'type' => 'array'
                ));
                
                if (!empty($pagination_links)) {
                    echo '<div class="ontario-obituaries-pagination-links">';
                    foreach ($pagination_links as $link) {
                        echo $link;
                    }
                    echo '</div>';
                }
                ?>
            </div>
        <?php endif; ?>
        
        <div id="ontario-obituaries-modal" class="ontario-obituaries-modal">
            <div class="ontario-obituaries-modal-content">
                <span class="ontario-obituaries-modal-close">&times;</span>
                <div id="ontario-obituaries-modal-body" class="ontario-obituaries-modal-body">
                    <!-- Modal content will be loaded here via AJAX -->
                    <div class="ontario-obituaries-modal-loading">
                        <div class="ontario-obituaries-spinner"></div>
                        <p><?php _e('Loading...', 'ontario-obituaries'); ?></p>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
