<?php
/**
 * Ontario Obituaries AJAX Handler
 *
 * Handles AJAX requests for the plugin.
 *
 * FIX LOG:
 *  P0-1  : Action name matched to JS (ontario_obituaries_get_detail)
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

        // Integration with Obituary Assistant (P1-11 FIX: tightened auth)
        if ( function_exists( 'ontario_obituaries_check_dependency' ) && ontario_obituaries_check_dependency() ) {
            add_action( 'wp_ajax_obituary_assistant_get_ontario', array( $this, 'get_obituaries_for_assistant' ) );
            // Removed nopriv – assistant endpoint is admin-only now
        }
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

        if ( false === $result ) {
            wp_send_json_error( array( 'message' => __( 'Failed to delete obituary.', 'ontario-obituaries' ) ) );
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
