<?php
/**
 * Ontario Obituaries — Reset & Rescan Tool (Admin-only)
 *
 * Provides a safe, gated workflow for admins to:
 *   1. Export a JSON backup of all obituaries.
 *   2. Purge the obituaries table in batches (200 rows per request).
 *   3. Run the Source Collector once (first-page cap) and display results.
 *
 * Safety gates:
 *   - Requires manage_options capability.
 *   - Nonce verification on every destructive action.
 *   - Three confirmation gates before purge (checkbox, typed phrase, backup confirmation).
 *   - Concurrent reset lock (option-based session, survives refresh/back).
 *   - Does NOT touch suppression/blocklist tables, WP posts/pages, cron, SEO, or adapters.
 *
 * Option keys used:
 *   - ontario_obituaries_reset_session    — Active session lock.
 *   - ontario_obituaries_reset_progress   — Purge/rescan progress state.
 *   - ontario_obituaries_reset_last_result — Last completed reset + rescan results.
 *
 * @package Ontario_Obituaries
 * @since   3.11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ontario_Obituaries_Reset_Rescan {

    /** @var int Rows to delete per AJAX batch. */
    const BATCH_SIZE = 200;

    /**
     * Register hooks.
     */
    public function init() {
        // AJAX endpoints (admin-only)
        add_action( 'wp_ajax_ontario_obituaries_reset_export',    array( $this, 'ajax_export' ) );
        add_action( 'wp_ajax_ontario_obituaries_reset_start',     array( $this, 'ajax_start' ) );
        add_action( 'wp_ajax_ontario_obituaries_reset_purge',     array( $this, 'ajax_purge_batch' ) );
        add_action( 'wp_ajax_ontario_obituaries_reset_rescan',    array( $this, 'ajax_rescan' ) );
        add_action( 'wp_ajax_ontario_obituaries_reset_cancel',    array( $this, 'ajax_cancel' ) );
    }

    /* ─────────────────────── PERMISSIONS ───────────────────────── */

    /**
     * Verify the current user has permission and the nonce is valid.
     *
     * Sends a JSON error and dies if validation fails.
     *
     * @param string $nonce_action The expected nonce action name.
     */
    private function verify_request( $nonce_action = 'ontario_obituaries_reset_rescan' ) {
        check_ajax_referer( $nonce_action, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized. Admin access required.', 'ontario-obituaries' ) ), 403 );
        }
    }

    /* ─────────────────────── ADMIN PAGE ────────────────────────── */

    /**
     * Render the Reset & Rescan admin page.
     */
    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'ontario-obituaries' ) );
        }

        global $wpdb;
        $table      = $wpdb->prefix . 'ontario_obituaries';
        $total_rows = 0;
        $table_ok   = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
        if ( $table_ok ) {
            $total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
        }

        $session     = get_option( 'ontario_obituaries_reset_session', array() );
        $progress    = get_option( 'ontario_obituaries_reset_progress', array() );
        $last_result = get_option( 'ontario_obituaries_reset_last_result', array() );
        $has_lock    = ! empty( $session['session_id'] );
        ?>
        <div class="wrap" id="ontario-reset-rescan-wrap">
            <h1><?php esc_html_e( 'Ontario Obituaries — Reset & Rescan', 'ontario-obituaries' ); ?></h1>

            <!-- Last result banner -->
            <?php if ( ! empty( $last_result['phase'] ) && 'complete' === $last_result['phase'] ) : ?>
            <div class="notice notice-info is-dismissible">
                <p><strong><?php esc_html_e( 'Last Reset & Rescan Result', 'ontario-obituaries' ); ?></strong></p>
                <p><?php printf(
                    esc_html__( 'Completed: %s | Rows purged: %s | Rescan — Found: %s, Added: %s, Skipped: %s, Errors: %s', 'ontario-obituaries' ),
                    esc_html( isset( $last_result['completed_at'] ) ? $last_result['completed_at'] : '—' ),
                    esc_html( isset( $last_result['deleted_rows_count'] ) ? number_format( $last_result['deleted_rows_count'] ) : '0' ),
                    esc_html( isset( $last_result['rescan_result']['found'] ) ? $last_result['rescan_result']['found'] : '0' ),
                    esc_html( isset( $last_result['rescan_result']['added'] ) ? $last_result['rescan_result']['added'] : '0' ),
                    esc_html( isset( $last_result['rescan_result']['skipped'] ) ? $last_result['rescan_result']['skipped'] : '0' ),
                    esc_html( isset( $last_result['rescan_result']['errors'] ) ? $last_result['rescan_result']['errors'] : '0' )
                ); ?></p>
                <?php if ( ! empty( $last_result['rescan_result']['per_source'] ) ) : ?>
                    <details><summary><?php esc_html_e( 'Per-source breakdown', 'ontario-obituaries' ); ?></summary>
                    <table class="widefat striped" style="max-width:700px;margin-top:8px;">
                        <thead><tr><th><?php esc_html_e( 'Source', 'ontario-obituaries' ); ?></th><th><?php esc_html_e( 'Found', 'ontario-obituaries' ); ?></th><th><?php esc_html_e( 'Added', 'ontario-obituaries' ); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ( $last_result['rescan_result']['per_source'] as $domain => $ps ) : ?>
                            <tr>
                                <td><?php echo esc_html( $domain ); ?></td>
                                <td><?php echo intval( $ps['found'] ); ?></td>
                                <td><?php echo intval( $ps['added'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </details>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Lock warning -->
            <?php if ( $has_lock ) : ?>
            <div class="notice notice-warning" id="reset-lock-notice">
                <p><strong><?php esc_html_e( 'A reset session is already in progress.', 'ontario-obituaries' ); ?></strong></p>
                <p><?php printf(
                    esc_html__( 'Started by user #%d at %s. Phase: %s.', 'ontario-obituaries' ),
                    intval( $session['started_by_user_id'] ),
                    esc_html( $session['started_at'] ),
                    esc_html( isset( $progress['phase'] ) ? $progress['phase'] : 'unknown' )
                ); ?></p>
                <p>
                    <button type="button" class="button" id="btn-cancel-reset"><?php esc_html_e( 'Cancel & Unlock', 'ontario-obituaries' ); ?></button>
                    <button type="button" class="button button-primary" id="btn-resume-reset"><?php esc_html_e( 'Resume', 'ontario-obituaries' ); ?></button>
                </p>
            </div>
            <?php endif; ?>

            <!-- Main panel (hidden while lock is active) -->
            <div id="reset-main-panel" <?php echo $has_lock ? 'style="display:none;"' : ''; ?>>
                <div class="card" style="max-width:800px;">
                    <h2 style="color:#d63638;">⚠️ <?php esc_html_e( 'Danger Zone — Full Reset & Rescan', 'ontario-obituaries' ); ?></h2>
                    <p><?php printf(
                        esc_html__( 'This tool will DELETE all %s obituary rows from the database, then immediately re-scrape the first page of each active source.', 'ontario-obituaries' ),
                        '<strong>' . number_format( $total_rows ) . '</strong>'
                    ); ?></p>
                    <p><?php esc_html_e( 'Suppression lists, WordPress pages, cron schedules, and plugin settings are NOT affected.', 'ontario-obituaries' ); ?></p>

                    <hr>

                    <!-- Step 1: Export -->
                    <h3><?php esc_html_e( 'Step 1 — Export Backup', 'ontario-obituaries' ); ?></h3>
                    <p><?php esc_html_e( 'Download a JSON backup of all current obituary records before proceeding.', 'ontario-obituaries' ); ?></p>
                    <p>
                        <button type="button" class="button button-primary" id="btn-export-json"
                            <?php echo ( ! $table_ok || 0 === $total_rows ) ? 'disabled' : ''; ?>>
                            <?php esc_html_e( 'Download JSON Backup', 'ontario-obituaries' ); ?>
                        </button>
                        <span id="export-status" style="margin-left:10px;"></span>
                    </p>

                    <hr>

                    <!-- Step 2: Confirmation gates -->
                    <h3><?php esc_html_e( 'Step 2 — Confirm Deletion', 'ontario-obituaries' ); ?></h3>

                    <p><label>
                        <input type="checkbox" id="gate-understand" />
                        <?php esc_html_e( 'I understand this deletes all obituaries.', 'ontario-obituaries' ); ?>
                    </label></p>

                    <p><label>
                        <input type="checkbox" id="gate-backup" />
                        <?php esc_html_e( 'I confirm I have a fresh database backup (UpdraftPlus/host) taken within the last 30 minutes.', 'ontario-obituaries' ); ?>
                    </label></p>

                    <p>
                        <label for="gate-phrase"><?php esc_html_e( 'Type exactly:', 'ontario-obituaries' ); ?> <code>DELETE ALL OBITUARIES</code></label><br>
                        <input type="text" id="gate-phrase" autocomplete="off" spellcheck="false" style="width:350px;margin-top:4px;" placeholder="DELETE ALL OBITUARIES" />
                    </p>

                    <hr>

                    <!-- Step 3: Start -->
                    <h3><?php esc_html_e( 'Step 3 — Execute Reset & Rescan', 'ontario-obituaries' ); ?></h3>
                    <p>
                        <button type="button" class="button button-primary button-hero" id="btn-start-reset" disabled>
                            <?php esc_html_e( 'Purge All Obituaries & Rescan Now', 'ontario-obituaries' ); ?>
                        </button>
                    </p>
                </div>
            </div>

            <!-- Progress panel (hidden until started) -->
            <div id="reset-progress-panel" style="display:none;max-width:800px;">
                <div class="card">
                    <h2><?php esc_html_e( 'Reset & Rescan In Progress', 'ontario-obituaries' ); ?></h2>
                    <div id="progress-phase" style="font-size:16px;font-weight:600;margin-bottom:12px;"></div>
                    <div style="background:#f0f0f0;border-radius:4px;height:24px;position:relative;overflow:hidden;margin-bottom:12px;">
                        <div id="progress-bar" style="background:#2271b1;height:100%;width:0%;transition:width 0.3s;border-radius:4px;"></div>
                    </div>
                    <div id="progress-detail" style="color:#666;"></div>
                    <div id="progress-log" style="margin-top:16px;max-height:300px;overflow-y:auto;font-family:monospace;font-size:12px;background:#f9f9f9;padding:10px;border:1px solid #ddd;border-radius:4px;"></div>
                </div>
            </div>

            <!-- Results panel (hidden until complete) -->
            <div id="reset-results-panel" style="display:none;max-width:800px;">
                <div class="card">
                    <h2 style="color:#00a32a;">✅ <?php esc_html_e( 'Reset & Rescan Complete', 'ontario-obituaries' ); ?></h2>
                    <div id="results-summary"></div>
                    <div id="results-per-source" style="margin-top:12px;"></div>
                    <div id="results-next-steps" style="margin-top:16px;padding:12px;background:#f0f6fc;border-left:4px solid #2271b1;border-radius:4px;"></div>
                    <p style="margin-top:16px;">
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ontario-obituaries' ) ); ?>" class="button button-primary"><?php esc_html_e( 'View Obituaries', 'ontario-obituaries' ); ?></a>
                        <a href="<?php echo esc_url( home_url( '/obituaries/ontario/' ) ); ?>" class="button" target="_blank"><?php esc_html_e( 'View Frontend', 'ontario-obituaries' ); ?></a>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    /* ─────────────────────── AJAX: EXPORT ──────────────────────── */

    /**
     * AJAX: Stream a JSON export of all obituary rows.
     *
     * Returns a JSON array of all rows from {prefix}ontario_obituaries.
     * Does NOT delete anything.
     */
    public function ajax_export() {
        $this->verify_request();

        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            wp_send_json_error( array( 'message' => __( 'Obituaries table not found.', 'ontario-obituaries' ) ) );
        }

        $rows = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY id ASC", ARRAY_A );

        // Stream as downloadable JSON
        if ( ! headers_sent() ) {
            header( 'Content-Type: application/json; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename="ontario-obituaries-backup-' . gmdate( 'Y-m-d-His' ) . '.json"' );
            header( 'Cache-Control: no-store' );
        }

        echo wp_json_encode( array(
            'exported_at' => current_time( 'mysql' ),
            'total_rows'  => count( $rows ),
            'plugin_version' => defined( 'ONTARIO_OBITUARIES_VERSION' ) ? ONTARIO_OBITUARIES_VERSION : 'unknown',
            'rows'        => $rows,
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

        wp_die();
    }

    /* ─────────────────────── AJAX: START ───────────────────────── */

    /**
     * AJAX: Start a reset session (acquires the lock).
     *
     * Validates all three confirmation gates:
     *   - gate_understand checkbox
     *   - gate_phrase === 'DELETE ALL OBITUARIES'
     *   - gate_backup checkbox
     *
     * Creates a session lock to prevent concurrent resets.
     */
    public function ajax_start() {
        $this->verify_request();

        // Validate gates
        $understand = isset( $_POST['gate_understand'] ) && '1' === $_POST['gate_understand'];
        $backup     = isset( $_POST['gate_backup'] )     && '1' === $_POST['gate_backup'];
        $phrase     = isset( $_POST['gate_phrase'] )      ? sanitize_text_field( wp_unslash( $_POST['gate_phrase'] ) ) : '';

        if ( ! $understand ) {
            wp_send_json_error( array( 'message' => __( 'You must check the understanding checkbox.', 'ontario-obituaries' ) ) );
        }
        if ( ! $backup ) {
            wp_send_json_error( array( 'message' => __( 'You must confirm you have a fresh database backup.', 'ontario-obituaries' ) ) );
        }
        if ( 'DELETE ALL OBITUARIES' !== $phrase ) {
            wp_send_json_error( array( 'message' => __( 'Confirmation phrase does not match. Type exactly: DELETE ALL OBITUARIES', 'ontario-obituaries' ) ) );
        }

        // Check for existing lock
        $existing = get_option( 'ontario_obituaries_reset_session', array() );
        if ( ! empty( $existing['session_id'] ) ) {
            wp_send_json_error( array(
                'message' => sprintf(
                    __( 'A reset is already in progress (started by user #%d at %s). Cancel it first.', 'ontario-obituaries' ),
                    intval( $existing['started_by_user_id'] ),
                    esc_html( $existing['started_at'] )
                ),
            ) );
        }

        // Count rows
        global $wpdb;
        $table      = $wpdb->prefix . 'ontario_obituaries';
        $total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

        // Create session
        $session_id = wp_generate_uuid4();
        $session    = array(
            'session_id'         => $session_id,
            'started_at'         => current_time( 'mysql' ),
            'started_by_user_id' => get_current_user_id(),
            'total_rows_at_start' => $total_rows,
        );

        $progress = array(
            'session_id'         => $session_id,
            'phase'              => 'purge',
            'deleted_rows_count' => 0,
            'total_rows_at_start' => $total_rows,
            'last_error'         => '',
        );

        update_option( 'ontario_obituaries_reset_session', $session );
        update_option( 'ontario_obituaries_reset_progress', $progress );

        ontario_obituaries_log(
            sprintf( 'Reset & Rescan started by user #%d. %d rows to purge.', get_current_user_id(), $total_rows ),
            'info'
        );

        wp_send_json_success( array(
            'session_id'  => $session_id,
            'total_rows'  => $total_rows,
            'batch_size'  => self::BATCH_SIZE,
            'message'     => sprintf( __( 'Reset session started. %s rows to purge.', 'ontario-obituaries' ), number_format( $total_rows ) ),
        ) );
    }

    /* ─────────────────────── AJAX: PURGE BATCH ────────────────── */

    /**
     * AJAX: Delete the next batch of obituary rows.
     *
     * Selects the next N IDs ordered by id ASC, deletes them, and returns
     * the count of remaining rows.
     */
    public function ajax_purge_batch() {
        $this->verify_request();

        $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
        $session    = get_option( 'ontario_obituaries_reset_session', array() );

        if ( empty( $session['session_id'] ) || $session['session_id'] !== $session_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid or expired reset session.', 'ontario-obituaries' ) ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        // Select next batch of IDs
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM `{$table}` ORDER BY id ASC LIMIT %d",
            self::BATCH_SIZE
        ) );

        $deleted_this_batch = 0;
        if ( ! empty( $ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $deleted_this_batch = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM `{$table}` WHERE id IN ({$placeholders})",
                    $ids
                )
            );
            if ( false === $deleted_this_batch ) {
                $deleted_this_batch = 0;
            }
        }

        $remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

        // Update progress
        $progress = get_option( 'ontario_obituaries_reset_progress', array() );
        $progress['deleted_rows_count'] = isset( $progress['deleted_rows_count'] )
            ? intval( $progress['deleted_rows_count'] ) + $deleted_this_batch
            : $deleted_this_batch;
        $progress['phase'] = ( 0 === $remaining ) ? 'rescan' : 'purge';
        update_option( 'ontario_obituaries_reset_progress', $progress );

        // Clear caches after purge completes
        if ( 0 === $remaining ) {
            delete_transient( 'ontario_obituaries_locations_cache' );
            delete_transient( 'ontario_obituaries_funeral_homes_cache' );
        }

        wp_send_json_success( array(
            'deleted_this_batch' => $deleted_this_batch,
            'total_deleted'      => $progress['deleted_rows_count'],
            'remaining'          => $remaining,
            'phase'              => $progress['phase'],
            'done'               => ( 0 === $remaining ),
        ) );
    }

    /* ─────────────────────── AJAX: RESCAN ─────────────────────── */

    /**
     * AJAX: Run the Source Collector once (first-page cap) and return results.
     *
     * Uses the same Source Collector as the cron hook, but caps each source
     * to 1 listing URL via the `ontario_obituaries_discovered_urls` filter.
     */
    public function ajax_rescan() {
        $this->verify_request();

        $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
        $session    = get_option( 'ontario_obituaries_reset_session', array() );

        if ( empty( $session['session_id'] ) || $session['session_id'] !== $session_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid or expired reset session.', 'ontario-obituaries' ) ) );
        }

        // Update phase
        $progress = get_option( 'ontario_obituaries_reset_progress', array() );
        $progress['phase'] = 'rescan';
        update_option( 'ontario_obituaries_reset_progress', $progress );

        // Source Collector MUST be available (loaded in admin/cron/AJAX context)
        if ( ! class_exists( 'Ontario_Obituaries_Source_Collector' ) ) {
            $progress['phase']      = 'error';
            $progress['last_error'] = 'Source Collector class not available.';
            update_option( 'ontario_obituaries_reset_progress', $progress );
            wp_send_json_error( array( 'message' => __( 'Source Collector is not available. Cannot rescan.', 'ontario-obituaries' ) ) );
        }

        // Cap each source to 1 listing URL (first-page safety)
        $cap_pages = function( $urls ) {
            return array_slice( (array) $urls, 0, 1 );
        };
        add_filter( 'ontario_obituaries_discovered_urls', $cap_pages, 10, 1 );

        $rescan_error = null;
        $results      = array();
        try {
            $collector = new Ontario_Obituaries_Source_Collector();
            $results   = $collector->collect();
        } catch ( Exception $e ) {
            $rescan_error = $e->getMessage();
        } finally {
            remove_filter( 'ontario_obituaries_discovered_urls', $cap_pages );
        }

        // Persist last collection (same format as cron/manual scrape)
        update_option( 'ontario_obituaries_last_collection', array(
            'timestamp' => current_time( 'mysql' ),
            'completed' => current_time( 'mysql' ),
            'results'   => $results,
        ) );

        // Clear caches
        delete_transient( 'ontario_obituaries_locations_cache' );
        delete_transient( 'ontario_obituaries_funeral_homes_cache' );

        // Purge LiteSpeed
        if ( function_exists( 'ontario_obituaries_purge_litespeed' ) ) {
            ontario_obituaries_purge_litespeed( 'ontario_obits' );
        }

        // Build structured result
        $found   = isset( $results['obituaries_found'] )  ? intval( $results['obituaries_found'] )  : 0;
        $added   = isset( $results['obituaries_added'] )  ? intval( $results['obituaries_added'] )  : 0;
        $sources = isset( $results['sources_processed'] )  ? intval( $results['sources_processed'] )  : 0;
        $skipped = isset( $results['sources_skipped'] )    ? intval( $results['sources_skipped'] )    : 0;
        $errors  = ! empty( $results['errors'] )           ? count( $results['errors'] )              : 0;

        $per_source = array();
        if ( ! empty( $results['per_source'] ) ) {
            foreach ( $results['per_source'] as $domain => $ps ) {
                $per_source[ $domain ] = array(
                    'found'  => isset( $ps['found'] )  ? intval( $ps['found'] )  : 0,
                    'added'  => isset( $ps['added'] )  ? intval( $ps['added'] )  : 0,
                    'errors' => ! empty( $ps['errors'] ) ? $ps['errors']          : array(),
                );
            }
        }

        // Finalize progress + session
        $progress['phase'] = 'complete';
        if ( $rescan_error ) {
            $progress['phase']      = 'error';
            $progress['last_error'] = $rescan_error;
        }
        update_option( 'ontario_obituaries_reset_progress', $progress );

        // Store last result
        $last_result = array(
            'phase'              => $progress['phase'],
            'completed_at'       => current_time( 'mysql' ),
            'deleted_rows_count' => isset( $progress['deleted_rows_count'] ) ? intval( $progress['deleted_rows_count'] ) : 0,
            'rescan_result'      => array(
                'found'      => $found,
                'added'      => $added,
                'skipped'    => $skipped,
                'errors'     => $errors,
                'per_source' => $per_source,
            ),
        );
        update_option( 'ontario_obituaries_reset_last_result', $last_result );

        // Release lock
        delete_option( 'ontario_obituaries_reset_session' );

        ontario_obituaries_log(
            sprintf(
                'Reset & Rescan complete. Purged %d rows. Rescan: %d found, %d added, %d sources, %d errors.',
                intval( $progress['deleted_rows_count'] ),
                $found, $added, $sources, $errors
            ),
            'info'
        );

        if ( $rescan_error ) {
            wp_send_json_error( array(
                'message'     => sprintf( __( 'Rescan encountered an error: %s', 'ontario-obituaries' ), $rescan_error ),
                'last_result' => $last_result,
            ) );
        }

        wp_send_json_success( array(
            'message'     => sprintf(
                __( 'Rescan complete. Found %d obituaries, added %d new.', 'ontario-obituaries' ),
                $found, $added
            ),
            'found'       => $found,
            'added'       => $added,
            'skipped'     => $skipped,
            'errors'      => $errors,
            'per_source'  => $per_source,
            'last_result' => $last_result,
        ) );
    }

    /* ─────────────────────── AJAX: CANCEL ─────────────────────── */

    /**
     * AJAX: Cancel an in-progress reset session (release the lock).
     */
    public function ajax_cancel() {
        $this->verify_request();

        $session = get_option( 'ontario_obituaries_reset_session', array() );
        if ( empty( $session['session_id'] ) ) {
            wp_send_json_success( array( 'message' => __( 'No active session to cancel.', 'ontario-obituaries' ) ) );
        }

        delete_option( 'ontario_obituaries_reset_session' );

        // Keep progress for reference but mark as cancelled
        $progress = get_option( 'ontario_obituaries_reset_progress', array() );
        $progress['phase'] = 'cancelled';
        update_option( 'ontario_obituaries_reset_progress', $progress );

        ontario_obituaries_log(
            sprintf( 'Reset & Rescan cancelled by user #%d.', get_current_user_id() ),
            'info'
        );

        wp_send_json_success( array( 'message' => __( 'Reset session cancelled and lock released.', 'ontario-obituaries' ) ) );
    }
}
