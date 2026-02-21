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
     * v6.1.0: Added AJAX handlers for source manager + admin controls.
     */
    public function init() {
        // AJAX handler for manual scrape
        add_action( 'wp_ajax_ontario_obituaries_manual_scrape', array( $this, 'handle_manual_scrape' ) );

        // v6.1.0: Source URL Manager AJAX handlers.
        add_action( 'wp_ajax_ontario_obituaries_get_banned_sources', array( $this, 'ajax_get_banned_sources' ) );
        add_action( 'wp_ajax_ontario_obituaries_ban_source', array( $this, 'ajax_ban_source' ) );
        add_action( 'wp_ajax_ontario_obituaries_unban_source', array( $this, 'ajax_unban_source' ) );
        add_action( 'wp_ajax_ontario_obituaries_enable_source', array( $this, 'ajax_enable_source' ) );
        add_action( 'wp_ajax_ontario_obituaries_disable_source', array( $this, 'ajax_disable_source' ) );

        // v6.1.0: Admin controls — audit burst, per-obituary actions.
        add_action( 'wp_ajax_ontario_obituaries_audit_burst', array( $this, 'ajax_audit_burst' ) );
        add_action( 'wp_ajax_ontario_obituaries_request_rewrite', array( $this, 'ajax_request_rewrite' ) );
        add_action( 'wp_ajax_ontario_obituaries_suppress_obituary', array( $this, 'ajax_suppress_obituary' ) );
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

        // v3.10.0 FIX: Use Source_Collector (adapter-based pipeline) instead of
        // the legacy Scraper. The legacy scraper doesn't use the source adapter
        // architecture and cannot scrape yorkregion.com or any v3.0+ sources.
        if ( class_exists( 'Ontario_Obituaries_Source_Collector' ) ) {
            $collector = new Ontario_Obituaries_Source_Collector();
            $results   = $collector->collect();
        } else {
            $scraper = new Ontario_Obituaries_Scraper();
            $results = $scraper->collect();
        }

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

    /* ─────────────── SOURCE URL MANAGER (v6.1.0) ──────────────────── */

    /**
     * v6.1.0: Get the banned sources list.
     */
    public function ajax_get_banned_sources() {
        check_ajax_referer( 'ontario-obituaries-admin-nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
        }

        $banned = get_option( 'ontario_obituaries_banned_sources', array() );
        if ( ! is_array( $banned ) ) {
            $banned = array();
        }

        // Also return all sources for the manager UI.
        $sources = array();
        if ( class_exists( 'Ontario_Obituaries_Source_Registry' ) ) {
            global $wpdb;
            $table = Ontario_Obituaries_Source_Registry::table_name();
            $sources = $wpdb->get_results( "SELECT id, domain, name, enabled, last_success, consecutive_failures FROM `{$table}` ORDER BY domain ASC", ARRAY_A );
        }

        wp_send_json_success( array(
            'banned_sources' => array_values( $banned ),
            'sources'        => $sources,
        ) );
    }

    /**
     * v6.1.0: Ban a source domain pattern.
     */
    public function ajax_ban_source() {
        check_ajax_referer( 'ontario-obituaries-admin-nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
        }

        $pattern = isset( $_POST['domain_pattern'] ) ? sanitize_text_field( wp_unslash( $_POST['domain_pattern'] ) ) : '';
        if ( empty( $pattern ) ) {
            wp_send_json_error( array( 'message' => 'Domain pattern is required.' ) );
        }

        $banned = get_option( 'ontario_obituaries_banned_sources', array() );
        if ( ! is_array( $banned ) ) {
            $banned = array();
        }

        $pattern = strtolower( trim( $pattern ) );
        if ( ! in_array( $pattern, $banned, true ) ) {
            $banned[] = $pattern;
            update_option( 'ontario_obituaries_banned_sources', $banned, false );
        }

        // Also disable matching sources in the registry.
        if ( class_exists( 'Ontario_Obituaries_Source_Registry' ) ) {
            global $wpdb;
            $table = Ontario_Obituaries_Source_Registry::table_name();
            $all_sources = $wpdb->get_results( "SELECT id, domain FROM `{$table}` WHERE enabled = 1", ARRAY_A );
            foreach ( $all_sources as $src ) {
                $d = strtolower( $src['domain'] );
                if ( $d === $pattern || false !== strpos( $d, $pattern ) ) {
                    $wpdb->update( $table, array( 'enabled' => 0 ), array( 'id' => $src['id'] ), array( '%d' ), array( '%d' ) );
                }
            }
        }

        ontario_obituaries_log( sprintf( 'Source Manager: Banned pattern "%s".', $pattern ), 'info' );

        wp_send_json_success( array(
            'message'        => sprintf( 'Source pattern "%s" banned.', $pattern ),
            'banned_sources' => array_values( $banned ),
        ) );
    }

    /**
     * v6.1.0: Unban a source domain pattern.
     */
    public function ajax_unban_source() {
        check_ajax_referer( 'ontario-obituaries-admin-nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
        }

        $pattern = isset( $_POST['domain_pattern'] ) ? sanitize_text_field( wp_unslash( $_POST['domain_pattern'] ) ) : '';
        if ( empty( $pattern ) ) {
            wp_send_json_error( array( 'message' => 'Domain pattern is required.' ) );
        }

        $banned = get_option( 'ontario_obituaries_banned_sources', array() );
        if ( ! is_array( $banned ) ) {
            $banned = array();
        }

        $pattern = strtolower( trim( $pattern ) );
        $banned  = array_values( array_diff( $banned, array( $pattern ) ) );
        update_option( 'ontario_obituaries_banned_sources', $banned, false );

        ontario_obituaries_log( sprintf( 'Source Manager: Unbanned pattern "%s".', $pattern ), 'info' );

        wp_send_json_success( array(
            'message'        => sprintf( 'Source pattern "%s" unbanned.', $pattern ),
            'banned_sources' => array_values( $banned ),
        ) );
    }

    /**
     * v6.1.0: Enable a source in the registry.
     */
    public function ajax_enable_source() {
        check_ajax_referer( 'ontario-obituaries-admin-nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
        }

        $source_id = isset( $_POST['source_id'] ) ? intval( $_POST['source_id'] ) : 0;
        if ( $source_id < 1 ) {
            wp_send_json_error( array( 'message' => 'Invalid source ID.' ) );
        }

        if ( ! class_exists( 'Ontario_Obituaries_Source_Registry' ) ) {
            wp_send_json_error( array( 'message' => 'Source registry not loaded.' ) );
        }

        global $wpdb;
        $table = Ontario_Obituaries_Source_Registry::table_name();
        $wpdb->update( $table, array( 'enabled' => 1 ), array( 'id' => $source_id ), array( '%d' ), array( '%d' ) );

        wp_send_json_success( array( 'message' => sprintf( 'Source #%d enabled.', $source_id ) ) );
    }

    /**
     * v6.1.0: Disable a source in the registry.
     */
    public function ajax_disable_source() {
        check_ajax_referer( 'ontario-obituaries-admin-nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
        }

        $source_id = isset( $_POST['source_id'] ) ? intval( $_POST['source_id'] ) : 0;
        if ( $source_id < 1 ) {
            wp_send_json_error( array( 'message' => 'Invalid source ID.' ) );
        }

        if ( ! class_exists( 'Ontario_Obituaries_Source_Registry' ) ) {
            wp_send_json_error( array( 'message' => 'Source registry not loaded.' ) );
        }

        global $wpdb;
        $table = Ontario_Obituaries_Source_Registry::table_name();
        $wpdb->update( $table, array( 'enabled' => 0 ), array( 'id' => $source_id ), array( '%d' ), array( '%d' ) );

        wp_send_json_success( array( 'message' => sprintf( 'Source #%d disabled.', $source_id ) ) );
    }

    /* ─────────────── ADMIN CONTROLS (v6.1.0) ──────────────────────── */

    /**
     * v6.1.0: Run an audit burst — processes multiple records in one request.
     *
     * Admin-triggered, not cron. Processes up to $count records sequentially
     * with 2-second delays between each. Respects rate limiter.
     */
    public function ajax_audit_burst() {
        check_ajax_referer( 'ontario-obituaries-admin-nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
        }

        $count = isset( $_POST['count'] ) ? min( 20, max( 1, intval( $_POST['count'] ) ) ) : 5;

        if ( ! class_exists( 'Ontario_Obituaries_Authenticity_Checker' ) ) {
            wp_send_json_error( array( 'message' => 'Authenticity checker class not loaded.' ) );
        }

        $checker = new Ontario_Obituaries_Authenticity_Checker();
        if ( ! $checker->is_configured() ) {
            wp_send_json_error( array( 'message' => 'Groq API key not configured.' ) );
        }

        $total_result = array(
            'audited'   => 0,
            'passed'    => 0,
            'flagged'   => 0,
            'published' => 0,
            'requeued'  => 0,
            'errors'    => array(),
        );

        for ( $i = 0; $i < $count; $i++ ) {
            if ( $i > 0 ) {
                sleep( 2 ); // Brief pause between audits.
            }

            $batch = $checker->run_audit_batch();

            $total_result['audited']   += $batch['audited'];
            $total_result['passed']    += $batch['passed'];
            $total_result['flagged']   += $batch['flagged'];
            $total_result['published'] += $batch['published'];
            $total_result['requeued']  += $batch['requeued'];
            $total_result['errors']     = array_merge( $total_result['errors'], $batch['errors'] );

            // Stop if no more records to audit.
            if ( $batch['audited'] === 0 ) {
                break;
            }

            // Stop if rate limited.
            if ( ! empty( $batch['errors'] ) ) {
                foreach ( $batch['errors'] as $err ) {
                    if ( false !== strpos( strtolower( $err ), 'rate' ) ) {
                        break 2;
                    }
                }
            }
        }

        wp_send_json_success( array(
            'message' => sprintf(
                'Audit burst complete: %d audited, %d published, %d flagged, %d requeued.',
                $total_result['audited'],
                $total_result['published'],
                $total_result['flagged'],
                $total_result['requeued']
            ),
            'results' => $total_result,
        ) );
    }

    /**
     * v6.1.0: Request a rewrite for a specific obituary.
     *
     * Clears AI description and sets status back to pending + needs_audit.
     */
    public function ajax_request_rewrite() {
        check_ajax_referer( 'ontario-obituaries-admin-nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
        }

        $obit_id = isset( $_POST['obit_id'] ) ? intval( $_POST['obit_id'] ) : 0;
        if ( $obit_id < 1 ) {
            wp_send_json_error( array( 'message' => 'Invalid obituary ID.' ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        // v6.1.0 BUG FIX: Only include non-NULL fields in wpdb->update().
        // NULL columns are set via raw SQL below — avoids writing empty strings first.
        $upd = $wpdb->update(
            $table,
            array(
                'status'                 => 'pending',
                'ai_description'         => '',
                'audit_requeue_count'    => 0,
                'rewrite_requested_at'   => current_time( 'mysql', true ),
                'rewrite_request_reason' => 'admin_manual_rewrite',
            ),
            array( 'id' => $obit_id ),
            array( '%s', '%s', '%d', '%s', '%s' ),
            array( '%d' )
        );

        // Handle NULLs via raw SQL (wpdb::update can't set NULL).
        $wpdb->query( $wpdb->prepare(
            "UPDATE `{$table}` SET ai_description_hash = NULL, audit_status = NULL, audit_flags = NULL, last_audit_at = NULL, last_audited_hash = NULL WHERE id = %d",
            $obit_id
        ) );

        if ( false === $upd ) {
            wp_send_json_error( array( 'message' => 'Database update failed.' ) );
        }

        ontario_obituaries_log( sprintf( 'Admin: Requested rewrite for obituary ID %d.', $obit_id ), 'info' );

        wp_send_json_success( array( 'message' => sprintf( 'Rewrite requested for obituary #%d.', $obit_id ) ) );
    }

    /**
     * v6.1.0: Suppress (unpublish) a specific obituary.
     */
    public function ajax_suppress_obituary() {
        check_ajax_referer( 'ontario-obituaries-admin-nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
        }

        $obit_id = isset( $_POST['obit_id'] ) ? intval( $_POST['obit_id'] ) : 0;
        $reason  = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : 'admin_suppressed';

        if ( $obit_id < 1 ) {
            wp_send_json_error( array( 'message' => 'Invalid obituary ID.' ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        $upd = $wpdb->update(
            $table,
            array(
                'suppressed_at'     => current_time( 'mysql', true ),
                'suppressed_reason' => $reason,
            ),
            array( 'id' => $obit_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        if ( false === $upd ) {
            wp_send_json_error( array( 'message' => 'Database update failed.' ) );
        }

        ontario_obituaries_log( sprintf( 'Admin: Suppressed obituary ID %d (reason: %s).', $obit_id, $reason ), 'info' );

        wp_send_json_success( array( 'message' => sprintf( 'Obituary #%d suppressed.', $obit_id ) ) );
    }
}
