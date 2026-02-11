<?php
/**
 * Template for displaying obituaries (shortcode output).
 *
 * Variables are provided by Ontario_Obituaries_Display::render_obituaries():
 *   $obituaries   - array of obituary objects
 *   $total        - int total count
 *   $total_pages  - int total pages
 *   $locations    - array of unique locations
 *   $location     - string current location filter value
 *   $search       - string current search value
 *   $page         - int current page number
 *   $atts         - array shortcode attributes (limit, location, funeral_home, days)
 *
 * FIX LOG:
 *  P1-2  : Removed error_log() and HTML debug comments from production output
 *  P1-6  : Hidden field loop now sanitizes array values and skips arrays
 *  P1-10 : Template no longer creates its own Display instance or re-queries
 *  P2-4  : Images use loading="lazy" for performance
 *  v3.10.1: Removed Quick View, Share, flag buttons per owner request.
 *           Read More link preserved for SEO internal linking.
 *           Per-card "Request removal" text link preserved (populates removal form).
 *           Modal markup removed (no Quick View trigger exists).
 *  v3.13.3: Dates removed from listing cards per owner request.
 *  v3.14.0: Complete UI redesign to match Monaco Monuments theme.
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// All data is provided by render_obituaries() – no re-queries needed (P1-10 FIX).
$site_url = get_site_url();
?>

<!-- Main container -->
<div class="ontario-obituaries-container">
    <div class="ontario-obituaries-filters">
        <form method="get" class="ontario-obituaries-search-form">
            <?php
            // P1-6 FIX: Preserve existing GET params safely – skip arrays and plugin-controlled params
            $skip_keys = array( 'ontario_obituaries_search', 'ontario_obituaries_location', 'ontario_obituaries_page' );
            foreach ( $_GET as $key => $value ) {
                if ( in_array( $key, $skip_keys, true ) ) {
                    continue;
                }
                if ( is_array( $value ) ) {
                    continue; // skip array-valued params to avoid PHP warnings
                }
                echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
            }
            ?>

            <div class="ontario-obituaries-search">
                <input type="text"
                       name="ontario_obituaries_search"
                       placeholder="<?php esc_attr_e( 'Search obituaries...', 'ontario-obituaries' ); ?>"
                       value="<?php echo esc_attr( $search ); ?>">
                <button type="submit" class="ontario-obituaries-search-button" aria-label="<?php esc_attr_e( 'Search', 'ontario-obituaries' ); ?>">
                    <span class="dashicons dashicons-search"></span>
                </button>
            </div>

            <?php if ( ! empty( $locations ) ) : ?>
                <div class="ontario-obituaries-location-filter">
                    <label for="ontario-obituaries-location-select" class="screen-reader-text">
                        <?php esc_html_e( 'Filter by location', 'ontario-obituaries' ); ?>
                    </label>
                    <select id="ontario-obituaries-location-select" name="ontario_obituaries_location">
                        <option value=""><?php esc_html_e( 'All Locations', 'ontario-obituaries' ); ?></option>
                        <?php foreach ( $locations as $loc ) : ?>
                            <option value="<?php echo esc_attr( $loc ); ?>" <?php selected( $location, $loc ); ?>><?php echo esc_html( $loc ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="ontario-obituaries-filter-button">
                        <?php esc_html_e( 'Filter', 'ontario-obituaries' ); ?>
                    </button>
                </div>
            <?php endif; ?>

            <?php if ( $search || $location ) : ?>
                <div class="ontario-obituaries-clear-filters">
                    <a href="<?php echo esc_url( remove_query_arg( array( 'ontario_obituaries_search', 'ontario_obituaries_location', 'ontario_obituaries_page' ) ) ); ?>">
                        <?php esc_html_e( 'Clear Filters', 'ontario-obituaries' ); ?>
                    </a>
                </div>
            <?php endif; ?>
        </form>

        <div class="ontario-obituaries-count">
            <?php
            printf(
                /* translators: %d: number of obituaries */
                _n( '%d obituary found', '%d obituaries found', $total, 'ontario-obituaries' ),
                $total
            );
            ?>
        </div>
    </div>

    <?php if ( empty( $obituaries ) ) : ?>
        <div class="ontario-obituaries-empty">
            <p><?php esc_html_e( 'No obituaries found.', 'ontario-obituaries' ); ?></p>
            <?php if ( $search || $location ) : ?>
                <p><?php esc_html_e( 'Try adjusting your search or filters.', 'ontario-obituaries' ); ?></p>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <div class="ontario-obituaries-grid">
            <?php foreach ( $obituaries as $index => $obituary ) : ?>
                <div class="ontario-obituaries-card" data-index="<?php echo esc_attr( $index ); ?>">
                    <?php if ( ! empty( $obituary->image_url ) ) : ?>
                        <div class="ontario-obituaries-card-image">
                            <!-- P2-4 FIX: lazy loading -->
                            <img src="<?php echo esc_url( $obituary->image_url ); ?>"
                                 alt="<?php echo esc_attr( sprintf( __( 'Photo of %s', 'ontario-obituaries' ), $obituary->name ) ); ?>"
                                 loading="lazy">
                        </div>
                    <?php endif; ?>

                    <div class="ontario-obituaries-card-content">
                        <div class="ontario-obituaries-card-meta">
                            <?php if ( ! empty( $obituary->location ) ) : ?>
                                <span class="ontario-obituaries-location"><?php echo esc_html( $obituary->location ); ?></span>
                            <?php endif; ?>
                            <?php if ( ! empty( $obituary->funeral_home ) ) : ?>
                                <span class="ontario-obituaries-funeral-home"><?php echo esc_html( $obituary->funeral_home ); ?></span>
                            <?php endif; ?>
                        </div>

                        <h3 class="ontario-obituaries-name"><?php echo esc_html( $obituary->name ); ?></h3>

                        <?php
                        // v3.13.3: Dates removed from listing cards per owner request.
                        // Dates are still available on the individual obituary detail page
                        // (next to the provenance line) for reference.
                        ?>

                        <?php if ( ! empty( $obituary->description ) ) : ?>
                        <div class="ontario-obituaries-description">
                            <p><?php echo esc_html( wp_trim_words( $obituary->description, 30, '...' ) ); ?></p>
                        </div>
                        <?php endif; ?>

                        <div class="ontario-obituaries-actions">
                            <?php
                            // Build the SEO-friendly internal obituary URL
                            $obit_city_raw = ! empty( $obituary->city_normalized )
                                ? $obituary->city_normalized
                                : ( ! empty( $obituary->location ) ? $obituary->location : 'ontario' );
                            $obit_city_slug = sanitize_title( $obit_city_raw );
                            $obit_name_slug = sanitize_title( $obituary->name ) . '-' . $obituary->id;
                            $obit_internal_url = home_url( '/obituaries/ontario/' . $obit_city_slug . '/' . $obit_name_slug . '/' );
                            ?>
                            <a href="<?php echo esc_url( $obit_internal_url ); ?>"
                               class="ontario-obituaries-read-more-link">
                                <?php esc_html_e( 'Read More', 'ontario-obituaries' ); ?>
                            </a>

                            <?php // v3.10.1: Quick View and Share buttons removed. Read More kept for SEO. ?>

                            <a href="#" class="ontario-obituaries-remove-link"
                               data-id="<?php echo esc_attr( $obituary->id ); ?>"
                               data-name="<?php echo esc_attr( $obituary->name ); ?>"
                               title="<?php esc_attr_e( 'Request removal', 'ontario-obituaries' ); ?>">
                                <?php esc_html_e( 'Request removal', 'ontario-obituaries' ); ?>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ( $total_pages > 1 ) : ?>
            <nav class="ontario-obituaries-pagination" aria-label="<?php esc_attr_e( 'Obituary pages', 'ontario-obituaries' ); ?>">
                <?php
                $pagination_links = paginate_links( array(
                    'base'      => add_query_arg( 'ontario_obituaries_page', '%#%' ),
                    'format'    => '',
                    'prev_text' => __( '&laquo; Previous', 'ontario-obituaries' ),
                    'next_text' => __( 'Next &raquo;', 'ontario-obituaries' ),
                    'total'     => $total_pages,
                    'current'   => $page,
                    'type'      => 'array',
                ) );

                if ( ! empty( $pagination_links ) ) {
                    echo '<div class="ontario-obituaries-pagination-links">';
                    foreach ( $pagination_links as $link ) {
                        echo $link; // already escaped by paginate_links
                    }
                    echo '</div>';
                }
                ?>
            </nav>
        <?php endif; ?>

        <!-- v3.10.1: Modal markup removed — Quick View button no longer exists.
             The AJAX endpoint and obituary-detail.php are kept server-side for
             backward compatibility but are never triggered from this template. -->
    <?php endif; ?>

    <!-- Removal Request Form (v3.0.0 — P0-SUP FIX: suppress-after-verify) -->
    <div id="ontario-obituaries-removal-form" class="ontario-obituaries-removal-form" style="display:none;">
        <h3><?php esc_html_e( 'Request Obituary Removal', 'ontario-obituaries' ); ?></h3>
        <p><?php esc_html_e( 'If you are a family member or funeral home representative and wish to have a listing removed, please fill out the form below. A verification email will be sent to you; once confirmed, the listing will be removed from public view.', 'ontario-obituaries' ); ?></p>
        <form id="ontario-obituaries-removal-submit">
            <input type="hidden" id="removal-obituary-id" value="">
            <?php // Honeypot field — must remain empty. Bots that auto-fill it are rejected. ?>
            <div style="position:absolute;left:-9999px;" aria-hidden="true">
                <label for="removal-website">Website</label>
                <input type="text" id="removal-website" name="website" tabindex="-1" autocomplete="off" value="">
            </div>
            <p>
                <label for="removal-name"><?php esc_html_e( 'Your Name', 'ontario-obituaries' ); ?> <span class="required">*</span></label>
                <input type="text" id="removal-name" required>
            </p>
            <p>
                <label for="removal-email"><?php esc_html_e( 'Your Email', 'ontario-obituaries' ); ?> <span class="required">*</span></label>
                <input type="email" id="removal-email" required>
            </p>
            <p>
                <label for="removal-relationship"><?php esc_html_e( 'Relationship to Deceased', 'ontario-obituaries' ); ?></label>
                <select id="removal-relationship">
                    <option value=""><?php esc_html_e( 'Select...', 'ontario-obituaries' ); ?></option>
                    <option value="family"><?php esc_html_e( 'Family Member', 'ontario-obituaries' ); ?></option>
                    <option value="funeral_home"><?php esc_html_e( 'Funeral Home Representative', 'ontario-obituaries' ); ?></option>
                    <option value="executor"><?php esc_html_e( 'Estate Executor', 'ontario-obituaries' ); ?></option>
                    <option value="other"><?php esc_html_e( 'Other', 'ontario-obituaries' ); ?></option>
                </select>
            </p>
            <p>
                <label for="removal-notes"><?php esc_html_e( 'Additional Details (optional)', 'ontario-obituaries' ); ?></label>
                <textarea id="removal-notes" rows="3"></textarea>
            </p>
            <button type="submit" class="ontario-obituaries-removal-btn"><?php esc_html_e( 'Submit Removal Request', 'ontario-obituaries' ); ?></button>
            <button type="button" class="ontario-obituaries-removal-cancel"><?php esc_html_e( 'Cancel', 'ontario-obituaries' ); ?></button>
        </form>
    </div>

    <!-- Disclaimer -->
    <div class="ontario-obituaries-disclaimer">
        <hr class="ontario-obituaries-disclaimer-divider">
        <p class="ontario-obituaries-disclaimer-text">
            <?php esc_html_e( 'Disclaimer: All obituary information posted has been obtained via publicly available data. Facts such as names, dates, and locations are not copyrightable. Original memorial content is created by Monaco Monuments.', 'ontario-obituaries' ); ?>
            <a href="#" class="ontario-obituaries-request-removal" style="text-decoration:underline;"><?php esc_html_e( 'Request a removal or correction', 'ontario-obituaries' ); ?></a>.
        </p>
    </div>
</div>
