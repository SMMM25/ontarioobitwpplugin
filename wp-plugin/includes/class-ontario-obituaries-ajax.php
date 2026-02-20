<?php
/**
 * Ontario Obituaries AJAX Handler
 *
 * Handles AJAX requests for the plugin.
 *
 * FIX LOG:
 *  P0-1  : Action name matched to JS (ontario_obituaries_get_detail)
 *  P0-SUP: Added removal request AJAX handler with nonce + honeypot + rate limiting
 *  P1-11 : Obituary Assistant endpoint now requires nonce + manage_options cap
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ontario_Obituaries_Ajax {

    /**
     * Initialize AJAX handlers.
     */
    public function init() {
        // Frontend detail view (logged-in + logged-out)
        add_action( 'wp_ajax_ontario_obituaries_get_detail',        array( $this, 'get_obituary_detail' ) );
        add_action( 'wp_ajax_nopriv_ontario_obituaries_get_detail', array( $this, 'get_obituary_detail' ) );

        // Admin deletion
        add_action( 'wp_ajax_ontario_obituaries_delete', array( $this, 'delete_obituary' ) );

        // Public removal request (logged-in + logged-out) — P0-SUP FIX
        add_action( 'wp_ajax_ontario_obituaries_removal_request',        array( $this, 'handle_removal_request' ) );
        add_action( 'wp_ajax_nopriv_ontario_obituaries_removal_request', array( $this, 'handle_removal_request' ) );

        // Integration with Obituary Assistant (P1-11 FIX: tightened auth)
        if ( function_exists( 'ontario_obituaries_check_dependency' ) && ontario_obituaries_check_dependency() ) {
            add_action( 'wp_ajax_obituary_assistant_get_ontario', array( $this, 'get_obituaries_for_assistant' ) );
            // Removed nopriv – assistant endpoint is admin-only now
        }

        // v4.2.0: Soft lead capture (logged-in + logged-out)
        add_action( 'wp_ajax_ontario_obituaries_lead_capture',        array( $this, 'handle_lead_capture' ) );
        add_action( 'wp_ajax_nopriv_ontario_obituaries_lead_capture', array( $this, 'handle_lead_capture' ) );
    }

    /**
     * v4.2.0: Handle lead capture form submissions.
     *
     * Stores email + context in wp_options (simple approach — no extra table).
     * Data: email, city, obituary_id, timestamp, IP (hashed).
     */
    public function handle_lead_capture() {
        check_ajax_referer( 'ontario_obituaries_lead', 'ontario_lead_nonce' );

        $email       = isset( $_POST['lead_email'] ) ? sanitize_email( wp_unslash( $_POST['lead_email'] ) ) : '';
        $obituary_id = isset( $_POST['obituary_id'] ) ? intval( $_POST['obituary_id'] ) : 0;
        $city        = isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '';

        if ( empty( $email ) || ! is_email( $email ) ) {
            wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'ontario-obituaries' ) ) );
        }

        // Store leads in a simple option (array of leads).
        $leads = get_option( 'ontario_obituaries_leads', array() );

        // Prevent duplicate submissions.
        foreach ( $leads as $lead ) {
            if ( $lead['email'] === $email ) {
                wp_send_json_success( array( 'message' => __( 'You are already subscribed. Thank you!', 'ontario-obituaries' ) ) );
            }
        }

        $leads[] = array(
            'email'       => $email,
            'city'        => $city,
            'obituary_id' => $obituary_id,
            'timestamp'   => current_time( 'mysql' ),
            'ip_hash'     => wp_hash( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' ),
        );

        update_option( 'ontario_obituaries_leads', $leads );

        ontario_obituaries_log(
            sprintf( 'Lead captured: %s (city: %s, obituary: %d).', $email, $city, $obituary_id ),
            'info'
        );

        wp_send_json_success( array( 'message' => __( 'Thank you for subscribing!', 'ontario-obituaries' ) ) );
    }

    /**
     * Get obituary detail (modal content).
     */
    public function get_obituary_detail() {
        check_ajax_referer( 'ontario-obituaries-nonce', 'nonce' );

        // Accept both GET and POST
        $id = isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : 0;
        if ( $id <= 0 ) {
            wp_send_json_error( array( 'message' => __( 'Invalid obituary ID.', 'ontario-obituaries' ) ) );
        }

        $display  = new Ontario_Obituaries_Display();
        $obituary = $display->get_obituary( $id );

        if ( ! $obituary ) {
            wp_send_json_error( array( 'message' => __( 'Obituary not found.', 'ontario-obituaries' ) ) );
        }

        ob_start();
        include ONTARIO_OBITUARIES_PLUGIN_DIR . 'templates/obituary-detail.php';
        $html = ob_get_clean();

        wp_send_json_success( array(
            'html'     => $html,
            'obituary' => $obituary,
        ) );
    }

    /**
     * Delete obituary (admin only).
     */
    public function delete_obituary() {
        check_ajax_referer( 'ontario-obituaries-admin-nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'ontario-obituaries' ) ) );
        }

        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        if ( $id <= 0 ) {
            wp_send_json_error( array( 'message' => __( 'Invalid obituary ID.', 'ontario-obituaries' ) ) );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'ontario_obituaries';
        $result     = $wpdb->delete( $table_name, array( 'id' => $id ), array( '%d' ) );

        // v5.3.4: Phase 2d — structured DB check for delete (QC fix: gate audit on $result > 0).
        if ( function_exists( 'oo_db_check' ) ) {
            oo_db_check( 'AJAX', $result, 'DB_DELETE_FAIL', 'AJAX delete obituary failed', array( 'obit_id' => $id, 'user_id' => get_current_user_id() ) );
        }

        if ( false === $result ) {
            wp_send_json_error( array( 'message' => __( 'Failed to delete obituary.', 'ontario-obituaries' ) ) );
        }

        // v5.3.4: Phase 2d — audit log only when a row was actually removed.
        if ( $result > 0 && function_exists( 'oo_log' ) ) {
            oo_log( 'info', 'AJAX', 'OBIT_DELETED', 'Obituary deleted via AJAX', array( 'obit_id' => $id, 'user_id' => get_current_user_id() ) );
        }

        // Clear caches after deletion
        delete_transient( 'ontario_obituaries_locations_cache' );
        delete_transient( 'ontario_obituaries_funeral_homes_cache' );

        wp_send_json_success( array(
            'message' => __( 'Obituary deleted successfully.', 'ontario-obituaries' ),
            'id'      => $id,
        ) );
    }

    /**
     * Handle a public removal request (P0-SUP FIX).
     *
     * Security layers:
     *   1. Nonce verification (ontario-obituaries-nonce)
     *   2. Honeypot field check (must be empty)
     *   3. Rate limiting via Suppression_Manager::is_rate_limited()
     *   4. Deduplication via Suppression_Manager (internal)
     *   5. Obituary is NOT suppressed immediately — suppress-after-verify
     */
    public function handle_removal_request() {
        // 1. Nonce check.
        check_ajax_referer( 'ontario-obituaries-nonce', 'nonce' );

        // 2. Honeypot: the "website" field must be empty (bots fill it).
        if ( ! empty( $_POST['website'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid submission.', 'ontario-obituaries' ) ) );
        }

        // 3. Rate limiting.
        if ( class_exists( 'Ontario_Obituaries_Suppression_Manager' ) && Ontario_Obituaries_Suppression_Manager::is_rate_limited() ) {
            wp_send_json_error( array( 'message' => __( 'Too many requests. Please try again later.', 'ontario-obituaries' ) ) );
        }

        // 4. Collect and sanitize inputs.
        $obituary_id  = isset( $_POST['obituary_id'] )  ? absint( $_POST['obituary_id'] ) : 0;
        $name         = isset( $_POST['name'] )         ? sanitize_text_field( wp_unslash( $_POST['name'] ) )         : '';
        $email        = isset( $_POST['email'] )        ? sanitize_email( wp_unslash( $_POST['email'] ) )             : '';
        $relationship = isset( $_POST['relationship'] ) ? sanitize_text_field( wp_unslash( $_POST['relationship'] ) ) : '';
        $notes        = isset( $_POST['notes'] )        ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) )    : '';

        // 5. Delegate to the Suppression Manager (handles validation, dedupe, email).
        if ( ! class_exists( 'Ontario_Obituaries_Suppression_Manager' ) ) {
            wp_send_json_error( array( 'message' => __( 'Suppression system is not available.', 'ontario-obituaries' ) ) );
        }

        $result = Ontario_Obituaries_Suppression_Manager::submit_removal_request(
            $obituary_id,
            $name,
            $email,
            $relationship,
            $notes
        );

        if ( $result['success'] ) {
            wp_send_json_success( array( 'message' => $result['message'] ) );
        } else {
            wp_send_json_error( array( 'message' => $result['message'] ) );
        }
    }

    /**
     * Get obituaries for Obituary Assistant.
     *
     * P1-11 FIX: Now requires admin nonce + manage_options capability
     *            instead of relying on a weak GET parameter.
     */
    public function get_obituaries_for_assistant() {
        // P0-7 / P1-11 FIX: proper auth
        check_ajax_referer( 'ontario-obituaries-admin-nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ontario-obituaries' ) ) );
        }

        $display    = new Ontario_Obituaries_Display();
        $limit      = isset( $_GET['limit'] ) ? min( 100, max( 1, intval( $_GET['limit'] ) ) ) : 50;
        $obituaries = $display->get_obituaries( array( 'limit' => $limit ) );

        $formatted = array();
        foreach ( $obituaries as $obituary ) {
            $formatted[] = array(
                'id'            => $obituary->id,
                'name'          => $obituary->name,
                'date_of_birth' => $obituary->date_of_birth,
                'date_of_death' => $obituary->date_of_death,
                'age'           => $obituary->age,
                'location'      => $obituary->location,
                'funeral_home'  => $obituary->funeral_home,
                'image'         => $obituary->image_url,
                'description'   => $obituary->description,
                'source'        => $obituary->source_url,
                'source_name'   => 'Ontario Obituaries',
            );
        }

        wp_send_json_success( $formatted );
    }
}
