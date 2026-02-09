<?php
/**
 * Ontario Obituaries Admin
 *
 * Handles the manual scrape AJAX endpoint only.
 *
 * P0-1 FIX: This class NO LONGER registers settings or enqueues scripts.
 *           All settings registration, sanitization, and script enqueueing
 *           are handled by Ontario_Obituaries (the main class) to avoid
 *           duplicate register_setting() calls and group mismatches.
 *
 * ARCH-01 FIX: This class does NOT register its own admin menu.
 *              Menu registration is handled exclusively by
 *              Ontario_Obituaries::register_admin_menu().
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ontario_Obituaries_Admin {

    /**
     * Initialize admin hooks.
     *
     * P0-1 FIX: Removed register_settings() and enqueue_admin_scripts()
     *           hooks — those are now handled solely by the main class.
     */
    public function init() {
        // AJAX handler for manual scrape
        add_action( 'wp_ajax_ontario_obituaries_manual_scrape', array( $this, 'handle_manual_scrape' ) );
    }

    /* ────────────────────── AJAX: MANUAL SCRAPE ───────────────────────── */

    /**
     * Handle manual scrape AJAX request.
     */
    public function handle_manual_scrape() {
        check_ajax_referer( 'ontario-obituaries-admin-nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'ontario-obituaries' ) ) );
        }

        $scraper = new Ontario_Obituaries_Scraper();
        $results = $scraper->collect();

        // Persist last collection
        update_option( 'ontario_obituaries_last_collection', array(
            'timestamp' => current_time( 'mysql' ),
            'completed' => current_time( 'mysql' ),
            'results'   => $results,
        ) );

        // Clear caches
        delete_transient( 'ontario_obituaries_locations_cache' );
        delete_transient( 'ontario_obituaries_funeral_homes_cache' );

        wp_send_json_success( array(
            'message' => sprintf(
                __( 'Scraping completed. Found %d obituaries, added %d new.', 'ontario-obituaries' ),
                intval( $results['obituaries_found'] ),
                intval( $results['obituaries_added'] )
            ),
            'results' => $results,
        ) );
    }
}
