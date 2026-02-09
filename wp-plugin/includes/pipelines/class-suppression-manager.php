<?php
/**
 * Suppression / Opt-Out Manager
 *
 * Handles the removal/opt-out workflow for obituary listings:
 *   - Suppress-after-verify for public requests (P0 security fix)
 *   - Instant suppression for admin/legal actions only
 *   - Audit log of all suppression actions
 *   - Admin review queue
 *   - Do-not-republish fingerprints (provenance_hash blocklist)
 *   - Email verification with 48-hour TTL
 *   - Rate limiting (per-IP and per-obituary)
 *   - Deduplication of repeated requests
 *
 * Tables:
 *   {prefix}_ontario_obituaries_suppressions — audit log + blocklist
 *
 * Security model:
 *   Public requests (family_request, funeral_home_request):
 *     → Record is created with pending status (no immediate suppression)
 *     → Verification email sent; obituary is suppressed only after token verification
 *     → Callers MUST enforce nonce + honeypot + rate limiting (see AJAX handler)
 *
 *   Admin/legal actions (admin_action, legal_notice, privacy):
 *     → Immediate suppression; auto-verified if user is logged in
 *
 * @package Ontario_Obituaries
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ontario_Obituaries_Suppression_Manager {

    /** @var string Table name (without prefix). */
    const TABLE_NAME = 'ontario_obituaries_suppressions';

    /** @var int Verification token TTL in seconds (48 hours). */
    const TOKEN_TTL = 172800;

    /** @var int Max public removal requests per IP per hour. */
    const RATE_LIMIT_PER_IP = 5;

    /** @var int Max pending requests per obituary (prevents spam). */
    const MAX_PENDING_PER_OBITUARY = 2;

    /** @var array Allowed suppression reasons (must match ENUM in schema). */
    private static $valid_reasons = array(
        'family_request',
        'funeral_home_request',
        'admin_action',
        'legal_notice',
        'privacy',
    );

    /** @var array Reasons that trigger immediate suppression (admin/legal only). */
    private static $instant_suppress_reasons = array(
        'admin_action',
        'legal_notice',
        'privacy',
    );

    /**
     * Create the suppressions table.
     */
    public static function create_table() {
        global $wpdb;
        $table_name      = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            obituary_id bigint(20) UNSIGNED DEFAULT NULL,
            provenance_hash varchar(40) NOT NULL DEFAULT '',
            name varchar(200) NOT NULL DEFAULT '',
            date_of_death date DEFAULT NULL,
            reason enum('family_request','funeral_home_request','admin_action','legal_notice','privacy') NOT NULL DEFAULT 'family_request',
            requester_name varchar(200) NOT NULL DEFAULT '',
            requester_email varchar(200) NOT NULL DEFAULT '',
            requester_relationship varchar(100) NOT NULL DEFAULT '',
            verification_token varchar(64) DEFAULT NULL,
            token_created_at datetime DEFAULT NULL,
            verified_at datetime DEFAULT NULL,
            suppressed_at datetime DEFAULT NULL,
            reviewed_by bigint(20) UNSIGNED DEFAULT NULL,
            reviewed_at datetime DEFAULT NULL,
            requester_ip varchar(45) NOT NULL DEFAULT '',
            notes text DEFAULT NULL,
            do_not_republish tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_obituary_id (obituary_id),
            KEY idx_provenance_hash (provenance_hash),
            KEY idx_suppressed_at (suppressed_at),
            KEY idx_verification_token (verification_token),
            KEY idx_requester_ip (requester_ip),
            KEY idx_created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Get the full table name.
     *
     * @return string
     */
    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /* ────────────────────────── RATE LIMITING ────────────────────────── */

    /**
     * Check whether the current IP has exceeded the per-hour rate limit.
     *
     * Uses a WordPress transient keyed on the anonymised IP. Each submission
     * increments the counter; after RATE_LIMIT_PER_IP hits within one hour
     * further submissions are rejected.
     *
     * @return bool True if the rate limit has been exceeded.
     */
    public static function is_rate_limited() {
        $ip  = self::get_client_ip();
        $key = 'ontario_obit_rl_' . md5( $ip );

        $count = (int) get_transient( $key );
        return $count >= self::RATE_LIMIT_PER_IP;
    }

    /**
     * Record a removal-request hit against the current IP's rate-limit counter.
     */
    private static function record_rate_limit_hit() {
        $ip  = self::get_client_ip();
        $key = 'ontario_obit_rl_' . md5( $ip );

        $count = (int) get_transient( $key );
        set_transient( $key, $count + 1, HOUR_IN_SECONDS );
    }

    /**
     * Get the client IP address (best effort, behind proxies).
     *
     * @return string
     */
    private static function get_client_ip() {
        // Trust REMOTE_ADDR in most WP environments. If behind a known proxy,
        // the host should set X-Forwarded-For correctly.
        if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        }
        return '0.0.0.0';
    }

    /* ────────────────────────── DEDUPLICATION ────────────────────────── */

    /**
     * Check whether there are already pending (unverified, unsuppressed) requests
     * for the same obituary.
     *
     * @param int $obituary_id Obituary ID.
     * @return bool True if the max pending threshold has been reached.
     */
    private static function has_pending_duplicate( $obituary_id ) {
        global $wpdb;
        $table = self::table_name();

        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE obituary_id = %d
               AND verified_at IS NULL
               AND suppressed_at IS NULL
               AND reason IN ('family_request','funeral_home_request')",
            $obituary_id
        ) );

        return $count >= self::MAX_PENDING_PER_OBITUARY;
    }

    /* ──────────────── ADMIN / LEGAL: INSTANT SUPPRESSION ────────────── */

    /**
     * Suppress an obituary immediately (admin/legal actions only).
     *
     * Sets suppressed_at on the main obituary table and creates an
     * audit record in the suppressions table. For admin_action, the
     * record is auto-verified and auto-reviewed.
     *
     * @param int    $obituary_id Obituary ID.
     * @param string $reason      One of the $valid_reasons values.
     * @param array  $requester   Optional { name, email, relationship }.
     * @param string $notes       Optional admin notes.
     * @return int|false Suppression record ID, or false on failure.
     */
    public static function suppress( $obituary_id, $reason = 'admin_action', $requester = array(), $notes = '' ) {
        global $wpdb;

        // Validate reason against ENUM whitelist.
        if ( ! in_array( $reason, self::$valid_reasons, true ) ) {
            $reason = 'admin_action';
        }

        $main_table = $wpdb->prefix . 'ontario_obituaries';
        $supp_table = self::table_name();

        // Fetch the obituary for provenance data.
        $obituary = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$main_table}` WHERE id = %d",
            $obituary_id
        ) );

        if ( ! $obituary ) {
            return false;
        }

        $now = current_time( 'mysql', true );

        // 1. Mark suppressed on the main table.
        $wpdb->update(
            $main_table,
            array(
                'suppressed_at'     => $now,
                'suppressed_reason' => $reason,
            ),
            array( 'id' => $obituary_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        // 2. Build audit record.
        $data = array(
            'obituary_id'            => $obituary_id,
            'provenance_hash'        => isset( $obituary->provenance_hash ) ? $obituary->provenance_hash : '',
            'name'                   => $obituary->name,
            'date_of_death'          => $obituary->date_of_death,
            'reason'                 => $reason,
            'requester_name'         => isset( $requester['name'] ) ? sanitize_text_field( $requester['name'] ) : '',
            'requester_email'        => isset( $requester['email'] ) ? sanitize_email( $requester['email'] ) : '',
            'requester_relationship' => isset( $requester['relationship'] ) ? sanitize_text_field( $requester['relationship'] ) : '',
            'suppressed_at'          => $now,
            'notes'                  => sanitize_textarea_field( $notes ),
            'do_not_republish'       => 1,
            'created_at'             => $now,
        );

        // Matching format array — one entry per key, in exact insertion order.
        $formats = array(
            '%d', // obituary_id
            '%s', // provenance_hash
            '%s', // name
            '%s', // date_of_death
            '%s', // reason
            '%s', // requester_name
            '%s', // requester_email
            '%s', // requester_relationship
            '%s', // suppressed_at
            '%s', // notes
            '%d', // do_not_republish
            '%s', // created_at
        );

        // Auto-verify and auto-review admin/legal actions.
        if ( in_array( $reason, self::$instant_suppress_reasons, true ) && is_user_logged_in() ) {
            $data['verified_at'] = $now;
            $data['reviewed_by'] = get_current_user_id();
            $data['reviewed_at'] = $now;

            $formats[] = '%s'; // verified_at
            $formats[] = '%d'; // reviewed_by
            $formats[] = '%s'; // reviewed_at
        }

        $wpdb->insert( $supp_table, $data, $formats );
        $suppression_id = $wpdb->insert_id;

        if ( $suppression_id ) {
            ontario_obituaries_log(
                sprintf( 'Obituary %d suppressed (reason: %s, by user: %d)', $obituary_id, $reason, get_current_user_id() ),
                'info'
            );
        }

        return $suppression_id ? intval( $suppression_id ) : false;
    }

    /* ─────── PUBLIC REQUESTS: SUPPRESS-AFTER-VERIFY (P0 FIX) ────────── */

    /**
     * Submit a family / funeral-home removal request.
     *
     * KEY CHANGE (P0 FIX): The obituary is NOT suppressed immediately.
     * A pending record is created in the suppressions table with a
     * verification token. Suppression only happens when the token is
     * verified via verify_request().
     *
     * Callers MUST enforce:
     *   - Nonce verification (check_ajax_referer)
     *   - Honeypot field validation
     *   - Rate-limit check (is_rate_limited)
     *
     * @param int    $obituary_id  Obituary ID.
     * @param string $name         Requester name.
     * @param string $email        Requester email.
     * @param string $relationship Relationship to deceased.
     * @param string $notes        Additional details.
     * @return array { success: bool, message: string }
     */
    public static function submit_removal_request( $obituary_id, $name, $email, $relationship = '', $notes = '' ) {
        // ── Input validation ──────────────────────────────────────────
        $obituary_id = absint( $obituary_id );
        if ( 0 === $obituary_id ) {
            return array( 'success' => false, 'message' => __( 'Invalid obituary ID.', 'ontario-obituaries' ) );
        }
        if ( ! is_email( $email ) ) {
            return array( 'success' => false, 'message' => __( 'A valid email address is required.', 'ontario-obituaries' ) );
        }
        $name = trim( $name );
        if ( empty( $name ) ) {
            return array( 'success' => false, 'message' => __( 'Your name is required.', 'ontario-obituaries' ) );
        }

        // ── Rate limiting ─────────────────────────────────────────────
        if ( self::is_rate_limited() ) {
            return array( 'success' => false, 'message' => __( 'Too many requests. Please try again later.', 'ontario-obituaries' ) );
        }

        // ── Deduplication ─────────────────────────────────────────────
        if ( self::has_pending_duplicate( $obituary_id ) ) {
            return array(
                'success' => false,
                'message' => __( 'A removal request for this obituary is already pending verification. Please check your email.', 'ontario-obituaries' ),
            );
        }

        global $wpdb;
        $main_table = $wpdb->prefix . 'ontario_obituaries';

        // ── Verify the obituary exists and is not already suppressed ──
        $obituary = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$main_table}` WHERE id = %d",
            $obituary_id
        ) );

        if ( ! $obituary ) {
            return array( 'success' => false, 'message' => __( 'Obituary not found.', 'ontario-obituaries' ) );
        }

        if ( ! empty( $obituary->suppressed_at ) ) {
            return array( 'success' => false, 'message' => __( 'This obituary has already been removed from display.', 'ontario-obituaries' ) );
        }

        // ── Create pending suppression record (NOT suppressed yet) ────
        $now   = current_time( 'mysql', true );
        $token = wp_generate_password( 32, false );

        $supp_table = self::table_name();

        $data = array(
            'obituary_id'            => $obituary_id,
            'provenance_hash'        => isset( $obituary->provenance_hash ) ? $obituary->provenance_hash : '',
            'name'                   => $obituary->name,
            'date_of_death'          => $obituary->date_of_death,
            'reason'                 => 'family_request',
            'requester_name'         => sanitize_text_field( $name ),
            'requester_email'        => sanitize_email( $email ),
            'requester_relationship' => sanitize_text_field( $relationship ),
            'verification_token'     => $token,
            'token_created_at'       => $now,
            'requester_ip'           => self::get_client_ip(),
            'notes'                  => sanitize_textarea_field( $notes ),
            'do_not_republish'       => 0,   // Not blocked yet — set to 1 on verify.
            'created_at'             => $now,
            // suppressed_at is intentionally NULL — suppress-after-verify.
        );

        $formats = array(
            '%d', // obituary_id
            '%s', // provenance_hash
            '%s', // name
            '%s', // date_of_death
            '%s', // reason
            '%s', // requester_name
            '%s', // requester_email
            '%s', // requester_relationship
            '%s', // verification_token
            '%s', // token_created_at
            '%s', // requester_ip
            '%s', // notes
            '%d', // do_not_republish
            '%s', // created_at
        );

        $wpdb->insert( $supp_table, $data, $formats );

        if ( ! $wpdb->insert_id ) {
            ontario_obituaries_log(
                sprintf( 'Failed to create suppression request for obituary %d: %s', $obituary_id, $wpdb->last_error ),
                'error'
            );
            return array( 'success' => false, 'message' => __( 'An error occurred. Please try again.', 'ontario-obituaries' ) );
        }

        // Record the rate-limit hit after successful insert.
        self::record_rate_limit_hit();

        // ── Send verification email ───────────────────────────────────
        self::send_verification_email( $email, $name, $obituary->name, $token );

        // ── Notify admin ──────────────────────────────────────────────
        self::notify_admin_of_request( $obituary, $name, $email, $relationship, $notes );

        ontario_obituaries_log(
            sprintf( 'Removal request submitted for obituary %d by %s (pending verification)', $obituary_id, $email ),
            'info'
        );

        return array(
            'success' => true,
            'message' => __( 'Your removal request has been received. Please check your email to verify the request. Once verified, the obituary will be removed from public display.', 'ontario-obituaries' ),
        );
    }

    /* ───────────────── VERIFICATION (P0 FIX: now suppresses) ────────── */

    /**
     * Verify a removal request via token.
     *
     * P0 FIX: On successful verification this method now:
     *   1. Sets verified_at on the suppression record
     *   2. Sets suppressed_at (actually hides the obituary)
     *   3. Sets do_not_republish = 1
     *   4. Marks the main obituary table as suppressed
     *   5. Notifies the admin
     *
     * @param string $token The verification token from the email link.
     * @return array { success: bool, message: string }
     */
    public static function verify_request( $token ) {
        global $wpdb;

        $token = sanitize_text_field( $token );
        if ( empty( $token ) ) {
            return array( 'success' => false, 'message' => __( 'Invalid verification token.', 'ontario-obituaries' ) );
        }

        $table = self::table_name();

        $record = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE verification_token = %s AND verified_at IS NULL",
            $token
        ) );

        if ( ! $record ) {
            return array( 'success' => false, 'message' => __( 'Invalid or already-used verification token.', 'ontario-obituaries' ) );
        }

        // ── Token TTL check ───────────────────────────────────────────
        // Use token_created_at if available (new schema); fall back to created_at.
        $created_col = ! empty( $record->token_created_at ) ? $record->token_created_at : $record->created_at;
        $token_age   = time() - strtotime( get_date_from_gmt( $created_col, 'U' ) );

        if ( $token_age > self::TOKEN_TTL ) {
            return array(
                'success' => false,
                'message' => __( 'This verification link has expired. Please submit a new removal request.', 'ontario-obituaries' ),
            );
        }

        $now        = current_time( 'mysql', true );
        $main_table = $wpdb->prefix . 'ontario_obituaries';

        // 1. Mark verified + suppressed on the suppression record.
        $wpdb->update(
            $table,
            array(
                'verified_at'     => $now,
                'suppressed_at'   => $now,
                'do_not_republish' => 1,
            ),
            array( 'id' => $record->id ),
            array( '%s', '%s', '%d' ),
            array( '%d' )
        );

        // 2. Mark suppressed on the main obituary table.
        if ( ! empty( $record->obituary_id ) ) {
            $wpdb->update(
                $main_table,
                array(
                    'suppressed_at'     => $now,
                    'suppressed_reason' => $record->reason,
                ),
                array( 'id' => $record->obituary_id ),
                array( '%s', '%s' ),
                array( '%d' )
            );
        }

        // 3. Invalidate the token (prevent reuse).
        $wpdb->update(
            $table,
            array( 'verification_token' => null ),
            array( 'id' => $record->id ),
            array( '%s' ),
            array( '%d' )
        );

        ontario_obituaries_log(
            sprintf( 'Removal request verified and obituary %d suppressed (token: %s...)', $record->obituary_id, substr( $token, 0, 8 ) ),
            'info'
        );

        return array(
            'success' => true,
            'message' => __( 'Your removal request has been verified. The obituary has been removed from public display. Thank you.', 'ontario-obituaries' ),
        );
    }

    /* ────────────────────────── BLOCKLIST ────────────────────────────── */

    /**
     * Check if a provenance hash is on the do-not-republish list.
     *
     * @param string $hash Provenance hash (sha1, 40 chars).
     * @return bool
     */
    public static function is_blocked( $hash ) {
        if ( empty( $hash ) ) {
            return false;
        }

        global $wpdb;
        $table = self::table_name();

        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE provenance_hash = %s AND do_not_republish = 1",
            $hash
        ) );

        return $count > 0;
    }

    /* ────────────────────────── ADMIN REVIEW ─────────────────────────── */

    /**
     * Get pending (unreviewed) suppression requests.
     *
     * Excludes auto-reviewed admin actions so the queue only shows
     * family/funeral-home requests that need human attention.
     *
     * @return array Array of associative arrays.
     */
    public static function get_pending_requests() {
        global $wpdb;
        $table = self::table_name();

        return $wpdb->get_results(
            "SELECT * FROM `{$table}`
             WHERE reviewed_at IS NULL
               AND reason NOT IN ('admin_action')
             ORDER BY created_at DESC",
            ARRAY_A
        );
    }

    /**
     * Mark a suppression request as reviewed by an admin.
     *
     * @param int $suppression_id Suppression record ID.
     * @param int $admin_id       Admin user ID (0 = current user).
     * @return int|false Number of rows updated, or false on error.
     */
    public static function mark_reviewed( $suppression_id, $admin_id = 0 ) {
        global $wpdb;
        $table = self::table_name();

        return $wpdb->update(
            $table,
            array(
                'reviewed_by' => $admin_id ? absint( $admin_id ) : get_current_user_id(),
                'reviewed_at' => current_time( 'mysql', true ),
            ),
            array( 'id' => absint( $suppression_id ) ),
            array( '%d', '%s' ),
            array( '%d' )
        );
    }

    /* ────────────────────────── UNSUPPRESS ───────────────────────────── */

    /**
     * Unsuppress an obituary (admin action).
     *
     * Clears suppressed_at/suppressed_reason on the main table and
     * sets do_not_republish = 0 on all matching suppression records.
     *
     * @param int    $obituary_id Obituary ID.
     * @param string $notes       Optional admin notes.
     */
    public static function unsuppress( $obituary_id, $notes = '' ) {
        global $wpdb;
        $main_table = $wpdb->prefix . 'ontario_obituaries';

        // Use raw query for NULL assignment — $wpdb->update() can't reliably SET col = NULL.
        $wpdb->query( $wpdb->prepare(
            "UPDATE `{$main_table}` SET suppressed_at = NULL, suppressed_reason = NULL WHERE id = %d",
            $obituary_id
        ) );

        // Clear do-not-republish on all suppression records for this obituary.
        $supp_table = self::table_name();
        $wpdb->update(
            $supp_table,
            array(
                'do_not_republish' => 0,
                'notes'            => $notes ? sanitize_textarea_field( $notes ) : 'Unsuppressed by admin',
            ),
            array( 'obituary_id' => absint( $obituary_id ) ),
            array( '%d', '%s' ),
            array( '%d' )
        );

        ontario_obituaries_log(
            sprintf( 'Obituary %d unsuppressed by admin (user: %d)', $obituary_id, get_current_user_id() ),
            'info'
        );
    }

    /* ───────────────────────── EMAIL HELPERS ─────────────────────────── */

    /**
     * Send verification email to the requester.
     *
     * @param string $email          Requester email.
     * @param string $requester_name Requester name.
     * @param string $obit_name      Obituary name (deceased).
     * @param string $token          Verification token.
     */
    private static function send_verification_email( $email, $requester_name, $obit_name, $token ) {
        $verify_url = add_query_arg(
            array( 'ontario_obituaries_verify' => $token ),
            home_url()
        );

        $subject = sprintf(
            /* translators: %s: deceased name */
            __( 'Verify your removal request for %s — Ontario Obituaries', 'ontario-obituaries' ),
            $obit_name
        );

        $message = sprintf(
            __(
                "Dear %1\$s,\n\n"
                . "We received your request to remove the obituary listing for %2\$s.\n\n"
                . "To complete the removal, please verify your request by clicking the link below:\n"
                . "%3\$s\n\n"
                . "This link expires in 48 hours. Once verified, the listing will be removed from public display.\n\n"
                . "If you did not submit this request, please disregard this email — no action will be taken.\n\n"
                . "Sincerely,\n"
                . "Monaco Monuments\n"
                . "https://monacomonuments.ca",
                'ontario-obituaries'
            ),
            sanitize_text_field( $requester_name ),
            sanitize_text_field( $obit_name ),
            esc_url_raw( $verify_url )
        );

        wp_mail( sanitize_email( $email ), $subject, $message );
    }

    /**
     * Notify the site admin of a new removal request.
     *
     * @param object $obituary     The obituary row object.
     * @param string $name         Requester name.
     * @param string $email        Requester email.
     * @param string $relationship Requester relationship.
     * @param string $notes        Additional notes.
     */
    private static function notify_admin_of_request( $obituary, $name, $email, $relationship, $notes ) {
        $admin_email = get_option( 'admin_email' );

        $subject = sprintf(
            /* translators: %s: deceased name */
            __( '[Ontario Obituaries] Removal Request: %s', 'ontario-obituaries' ),
            $obituary->name
        );

        $message = sprintf(
            "A removal request has been submitted.\n\n"
            . "Obituary: %s (ID: %d)\n"
            . "Date of Death: %s\n"
            . "Requester: %s\n"
            . "Email: %s\n"
            . "Relationship: %s\n"
            . "Notes: %s\n\n"
            . "Status: PENDING VERIFICATION (obituary is still visible until the requester verifies via email).\n\n"
            . "Review in WordPress Admin: %s",
            $obituary->name,
            $obituary->id,
            $obituary->date_of_death,
            $name,
            $email,
            $relationship,
            $notes,
            admin_url( 'admin.php?page=ontario-obituaries-suppressions' )
        );

        wp_mail( $admin_email, $subject, $message );
    }
}
