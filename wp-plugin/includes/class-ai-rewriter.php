<?php
/**
 * AI Rewriter
 *
 * Rewrites scraped obituary descriptions into unique, professional prose
 * using a free LLM API (Groq / Llama). Preserves all facts — names, dates,
 * locations, funeral homes — while creating original content.
 *
 * Architecture (v5.0.0 — Groq Structured Extraction):
 *   - Uses Groq's OpenAI-compatible chat completion API.
 *   - API key stored in wp_options (ontario_obituaries_groq_api_key).
 *   - v5.0.0: SINGLE-CALL structured extraction + rewrite.
 *     Groq reads the FULL original obituary text and returns a JSON object:
 *       { date_of_death, date_of_birth, age, location, funeral_home, rewritten_text }
 *     The extracted fields OVERWRITE the regex-scraped DB columns when they
 *     pass sanity checks. This eliminates regex extraction errors as the
 *     root cause of wrong dates, ages, and locations on the frontend.
 *   - v4.6.0: Processes obituaries with status='pending', rewrites them,
 *     validates the rewrite preserves all facts, then sets status='published'.
 *     Obituaries are NOT visible on the site until they pass this pipeline.
 *   - Called after each collection cycle and on a separate cron hook.
 *   - Rate-limited: 12s delay everywhere (~5 req/min, within Groq's 6,000 TPM free tier).
 *   - Batch size: 1 by default everywhere. Callers can override for CLI loops.
 *
 * @package Ontario_Obituaries
 * @since   4.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ontario_Obituaries_AI_Rewriter {

    /** @var string Groq API endpoint (OpenAI-compatible). */
    private $api_url = 'https://api.groq.com/openai/v1/chat/completions';

    /** @var string Primary model. Llama 3.1 8B for speed + lower token usage on free tier. */
    private $model = 'llama-3.1-8b-instant';

    /** @var string Fallback model if primary is rate-limited or permission-blocked. */
    private $fallback_model = 'llama-3.3-70b-versatile';

    /** @var array Additional fallback models to try on 403 permission errors. */
    private $fallback_models = array(
        'llama-3.3-70b-versatile',
        'meta-llama/llama-4-scout-17b-16e-instruct',
    );

    /** @var int Maximum obituaries to process per batch run. */
    private $batch_size = 1;

    /**
     * Delay between API requests in microseconds.
     * 12 seconds = 12,000,000 µs — max ~5 requests per minute.
     *
     * WHY 12s? Groq free tier limit is 6,000 TOKENS per minute (TPM),
     * NOT just 30 requests per minute. Each obituary rewrite uses
     * ~1,000-1,200 total tokens (prompt + response). At 12s intervals:
     *   5 requests/min × 1,200 tokens = 6,000 TPM (the exact limit).
     * At 6s intervals we'd hit 10 req/min × 1,200 = 12,000 TPM → instant 429.
     */
    private $request_delay = 12000000;

    /** @var int API timeout in seconds. */
    private $api_timeout = 45;

    /** @var string|null Cached API key. */
    private $api_key = null;

    /** @var int Maximum retries for rate-limited requests. */
    private $max_rate_limit_retries = 2;

    /** @var bool Whether any rate-limiting was encountered during this batch. */
    private $was_rate_limited = false;

    /** @var int Number of consecutive rate-limit hits in this batch. */
    private $consecutive_rate_limits = 0;

    /**
     * v6.0.3 QC FIX: Maximum validation failures per obituary before quarantine.
     * After this many consecutive validate_rewrite() hard-rejects for the SAME
     * record, the record is quarantined (status remains 'pending', but a
     * transient flag prevents re-selection for 1 hour). This prevents a single
     * poison record from deadlocking the autonomous queue.
     */
    private $max_validation_failures = 3;

    /**
     * v6.1.1 FIX: Maximum quarantine cycles before permanent failure.
     * After this many 1-hour quarantine cycles (each triggered by
     * $max_validation_failures consecutive rejects), the record is
     * permanently marked status='failed' and removed from the queue.
     * This prevents poison records from blocking the queue indefinitely.
     */
    private $max_quarantine_cycles = 3;

    /** @var bool Whether we're running in CLI mode (standalone cron). */
    private $is_cli = false;

    /**
     * Constructor.
     *
     * @param int $batch_size Optional batch size override.
     */
    public function __construct( $batch_size = 0 ) {
        $this->api_key = get_option( 'ontario_obituaries_groq_api_key', '' );

        // Detect CLI mode — set by cron-rewriter.php via ONTARIO_CLI_REWRITER constant.
        $this->is_cli = defined( 'ONTARIO_CLI_REWRITER' ) && ONTARIO_CLI_REWRITER;

        if ( $batch_size > 0 ) {
            $this->batch_size = (int) $batch_size;
        }
        // Note: default batch_size is 1 everywhere. Callers can override.

        if ( $this->is_cli ) {
            // CLI mode: longer API timeout is fine (no AJAX timeout to worry about).
            $this->api_timeout = 60;
        }
    }

    /**
     * Check if the AI rewriter is configured and ready.
     *
     * @return bool True if API key is set.
     */
    public function is_configured() {
        return ! empty( $this->api_key );
    }

    /**
     * v4.6.7: Validate the Groq API key by making a minimal test request.
     *
     * Uses the cheapest possible request (max_tokens=1) to verify:
     *   - The API key is valid (not 401)
     *   - The model is accessible (not 403)
     *   - The account has quota (not 429)
     *
     * @return true|WP_Error True if valid, WP_Error with details if not.
     */
    public function validate_api_key() {
        if ( ! $this->is_configured() ) {
            return new WP_Error( 'not_configured', 'No Groq API key is set.' );
        }

        $test_messages = array(
            array( 'role' => 'user', 'content' => 'Say "OK".' ),
        );

        $body = wp_json_encode( array(
            'model'      => $this->model,
            'messages'   => $test_messages,
            'max_tokens' => 1,
        ) );

        $response = oo_safe_http_post( 'REWRITE', $this->api_url, array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ),
            'body' => $body,
        ), array( 'source' => 'class-ai-rewriter', 'purpose' => 'validate_api_key' ) );

        // Wrapper returns WP_Error for transport failures AND HTTP ≥ 400.
        // Extract status code to preserve existing 401/403/429 business logic.
        if ( is_wp_error( $response ) ) {
            $code      = oo_http_error_status( $response );
            $resp_body = oo_http_error_body( $response );
            $decoded   = json_decode( $resp_body, true );
            $error_msg = ! empty( $decoded['error']['message'] ) ? $decoded['error']['message'] : '';

            if ( 0 === $code ) {
                // Transport-level failure (DNS, timeout, SSL).
                return new WP_Error( 'connection_error', 'Cannot reach Groq API: ' . $response->get_error_message() );
            }

            if ( 401 === $code ) {
                return new WP_Error( 'api_key_invalid', 'API key is invalid, expired, or revoked. ' . $error_msg );
            }

            if ( 403 === $code ) {
                // Model may be blocked — try fallback models.
                $working_model = null;
                foreach ( $this->fallback_models as $fallback ) {
                    $fb_body = wp_json_encode( array(
                        'model'      => $fallback,
                        'messages'   => $test_messages,
                        'max_tokens' => 1,
                    ) );
                    $fb_resp = oo_safe_http_post( 'REWRITE', $this->api_url, array(
                        'timeout' => 15,
                        'headers' => array(
                            'Content-Type'  => 'application/json',
                            'Authorization' => 'Bearer ' . $this->api_key,
                        ),
                        'body' => $fb_body,
                    ), array( 'source' => 'class-ai-rewriter', 'purpose' => 'validate_fallback', 'fallback_model' => $fallback ) );
                    if ( ! is_wp_error( $fb_resp ) ) {
                        $working_model = $fallback;
                        break;
                    }
                }

                if ( $working_model ) {
                    return new WP_Error(
                        'model_blocked_but_fallback_ok',
                        sprintf(
                            'Primary model "%s" is blocked (403), but fallback model "%s" works. The rewriter will automatically use the fallback. %s',
                            $this->model,
                            $working_model,
                            $error_msg
                        )
                    );
                }

                return new WP_Error( 'model_permission_blocked', 'All models returned 403. Check permissions at https://console.groq.com/settings/limits. ' . $error_msg );
            }

            if ( 429 === $code ) {
                return new WP_Error( 'rate_limited', 'API key is valid but rate-limited. Try again in a few minutes.' );
            }

            return new WP_Error( 'unknown_error', sprintf( 'Groq API returned HTTP %d. %s', $code, $error_msg ) );
        }

        // 2xx — key is valid.
        return true;
    }

    /**
     * Process a batch of unrewritten obituaries.
     *
     * Fetches obituaries that have a description but no ai_description,
     * rewrites each via the LLM, and stores the result.
     *
     * @return array { processed: int, succeeded: int, failed: int, errors: string[] }
     */
    public function process_batch() {
        if ( ! $this->is_configured() ) {
            return array(
                'processed' => 0,
                'succeeded' => 0,
                'failed'    => 0,
                'errors'    => array( 'Groq API key not configured.' ),
            );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        // v4.6.4 FIX: Defensive check — ensure 'ai_description' column exists.
        // If the v4.6.0 migration failed or the column was never created,
        // the SELECT below would produce a MySQL error and return NULL.
        $col_check = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'ai_description'",
            $table
        ) );
        if ( intval( $col_check ) < 1 ) {
            // Column missing — create it now.
            $alter_result = $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN ai_description text DEFAULT NULL AFTER description" );
            // v5.3.3: Phase 2c — structured DB check for schema migration.
            if ( function_exists( 'oo_db_check' ) ) {
                oo_db_check( 'REWRITE', $alter_result, 'DB_SCHEMA_FAIL', 'Failed to add ai_description column', array( 'table' => $table ) );
            }
            ontario_obituaries_log( 'AI Rewriter: Created missing ai_description column.', 'warning' );
        }

        // v4.6.0: Get obituaries with status='pending' that need AI rewrite.
        // Only pending records are processed — they become 'published' after
        // successful rewrite + validation.
        // v6.0.3 QC FIX: Exclude quarantined records (ai_description LIKE '__quarantined_%').
        // Quarantined records had repeated validation failures; they are re-eligible
        // after 1 hour when a separate cron or the next audit clears the marker.
        $obituaries = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, date_of_birth, date_of_death, age, funeral_home,
                    location, description, city_normalized
             FROM `{$table}`
             WHERE status = 'pending'
               AND description IS NOT NULL
               AND description != ''
               AND (ai_description IS NULL OR ai_description = '' OR ai_description LIKE '__quarantined_%%')
               AND suppressed_at IS NULL
             ORDER BY created_at DESC
             LIMIT %d",
            $this->batch_size
        ) );

        // v6.0.3 QC FIX: Filter out records that are still within quarantine window.
        // v6.1.1 FIX: Track quarantine cycle count; after max_quarantine_cycles,
        // permanently mark as 'failed' to prevent infinite retry loops.
        if ( ! empty( $obituaries ) ) {
            $filtered = array();
            foreach ( $obituaries as $obit ) {
                // Check if quarantined and still within the 1-hour window.
                if ( ! empty( $obit->ai_description ) && 0 === strpos( $obit->ai_description, '__quarantined_' ) ) {
                    // v6.1.1: Parse format __quarantined_{timestamp}_{cycle_count}
                    $parts      = explode( '_', str_replace( '__quarantined_', '', $obit->ai_description ) );
                    $q_ts       = isset( $parts[0] ) ? (int) $parts[0] : 0;
                    $q_cycles   = isset( $parts[1] ) ? (int) $parts[1] : 1;

                    if ( $q_ts > 0 && ( time() - $q_ts ) < 3600 ) {
                        continue; // Still quarantined — skip.
                    }

                    // v6.1.1: If max quarantine cycles reached, permanently fail.
                    if ( $q_cycles >= $this->max_quarantine_cycles ) {
                        $wpdb->update(
                            $table,
                            array(
                                'status'         => 'failed',
                                'ai_description' => '__failed_max_quarantine_cycles_' . $q_cycles,
                                'audit_status'   => 'admin_review',
                            ),
                            array( 'id' => $obit->id ),
                            array( '%s', '%s', '%s' ),
                            array( '%d' )
                        );
                        ontario_obituaries_log(
                            sprintf(
                                'AI Rewriter: PERMANENTLY FAILED ID %d (%s) after %d quarantine cycles. Requires manual review.',
                                $obit->id, $obit->name, $q_cycles
                            ),
                            'error'
                        );
                        continue; // Remove from this batch.
                    }

                    // Quarantine expired but cycles remain — clear marker and allow re-processing.
                    $clear_result = $wpdb->query( $wpdb->prepare(
                        "UPDATE `{$table}` SET ai_description = NULL WHERE id = %d",
                        $obit->id
                    ) );
                    if ( function_exists( 'oo_db_check' ) ) {
                        oo_db_check( 'REWRITE', $clear_result, 'DB_QUARANTINE_CLEAR_FAIL', 'Failed to clear expired quarantine marker', array( 'obit_id' => $obit->id ) );
                    }
                }
                $filtered[] = $obit;
            }
            $obituaries = $filtered;
        }

        $result = array(
            'processed' => 0,
            'succeeded' => 0,
            'failed'    => 0,
            'errors'    => array(),
        );

        if ( empty( $obituaries ) ) {
            ontario_obituaries_log( 'AI Rewriter: No unrewritten obituaries found.', 'info' );
            return $result;
        }

        ontario_obituaries_log(
            sprintf( 'AI Rewriter: Processing batch of %d obituaries.', count( $obituaries ) ),
            'info'
        );

        // v4.6.5: Track rate-limit state for this batch.
        $this->was_rate_limited      = false;
        $this->consecutive_rate_limits = 0;

        foreach ( $obituaries as $obituary ) {
            $result['processed']++;

            // v4.6.5: Adaptive rate limiting — increase delay after rate-limit hits.
            if ( $result['processed'] > 1 ) {
                $delay = $this->request_delay;
                if ( $this->consecutive_rate_limits > 0 ) {
                    // Exponential backoff: double the base delay after each consecutive rate-limit.
                    // CLI: 12s, 24s, 48s.  AJAX: 30s, 60s.
                    $delay = $this->request_delay * pow( 2, $this->consecutive_rate_limits );
                    $delay = min( $delay, 60000000 ); // Cap at 60 seconds.
                    ontario_obituaries_log(
                        sprintf( 'AI Rewriter: Rate-limit backoff — waiting %ds before next request.', $delay / 1000000 ),
                        'info'
                    );
                }
                usleep( $delay );
            }

            $rewritten = $this->rewrite_obituary( $obituary );

            if ( is_wp_error( $rewritten ) ) {
                $result['failed']++;
                $result['errors'][] = sprintf(
                    'ID %d (%s): %s',
                    $obituary->id,
                    $obituary->name,
                    $rewritten->get_error_message()
                );

                // v4.6.7: On auth errors (401/403), break immediately — no point retrying
                // when the API key is invalid or all models are permission-blocked.
                if ( in_array( $rewritten->get_error_code(), array( 'api_key_invalid', 'model_permission_blocked' ), true ) ) {
                    ontario_obituaries_log(
                        sprintf( 'AI Rewriter: Auth error (%s) — aborting batch. Fix the API key or model permissions before retrying.', $rewritten->get_error_code() ),
                        'error'
                    );
                    $result['auth_error'] = true;
                    break;
                }

                // v4.6.5 FIX: On rate-limit, DON'T break the loop. Instead, track
                // consecutive rate-limits and let the backoff delay handle pacing.
                // Only break if we've hit 3+ consecutive rate-limits with no successes
                // in between — that means the quota is fully exhausted for this window.
                if ( 'rate_limited' === $rewritten->get_error_code() ) {
                    $this->was_rate_limited = true;
                    $this->consecutive_rate_limits++;

                    // v5.0.2: Use Groq's retry-after header if available.
                    $retry_secs = $rewritten->get_error_data();
                    if ( ! empty( $retry_secs ) && is_numeric( $retry_secs ) && $retry_secs > 0 ) {
                        ontario_obituaries_log(
                            sprintf( 'AI Rewriter: Groq says wait %ds. Sleeping...', $retry_secs ),
                            'info'
                        );
                        sleep( (int) $retry_secs + 1 ); // +1s safety margin.
                    }

                    if ( $this->consecutive_rate_limits >= 3 ) {
                        ontario_obituaries_log(
                            'AI Rewriter: 3 consecutive rate-limits — TPM quota exhausted. Pausing batch.',
                            'warning'
                        );
                        break;
                    }

                    ontario_obituaries_log(
                        sprintf(
                            'AI Rewriter: Rate limited (consecutive: %d/%d) — will retry after delay.',
                            $this->consecutive_rate_limits,
                            3
                        ),
                        'info'
                    );
                }

                continue;
            }

            // Success — reset consecutive rate-limit counter.
            $this->consecutive_rate_limits = 0;

            // v5.0.0: $rewritten is now an array with extracted fields + rewritten_text.
            $rewrite_text = $rewritten['rewritten_text'];

            // Validate the rewritten prose.
            $validation = $this->validate_rewrite( $rewrite_text, $obituary, $rewritten );

            if ( is_wp_error( $validation ) ) {
                $result['failed']++;
                $result['errors'][] = sprintf(
                    'ID %d (%s): Validation failed — %s',
                    $obituary->id,
                    $obituary->name,
                    $validation->get_error_message()
                );

                // v6.0.3 QC FIX: Quarantine after repeated validation failures.
                // Track per-record failure count in a short-lived transient.
                // After max_validation_failures consecutive rejects, quarantine
                // the record for 1 hour so it doesn't block the queue.
                $fail_key   = 'oo_rewrite_fail_' . $obituary->id;
                $fail_count = (int) get_transient( $fail_key );
                $fail_count++;
                set_transient( $fail_key, $fail_count, 3600 ); // 1-hour TTL.

                if ( $fail_count >= $this->max_validation_failures ) {
                    // Quarantine: set a transient that process_batch's SELECT
                    // won't see (we skip quarantined IDs in the query below),
                    // AND mark the record in the DB so the SELECT can exclude it.
                    // v6.1.1: Track cycle count in marker: __quarantined_{ts}_{cycles}
                    $prev_cycles = 0;
                    if ( ! empty( $obituary->ai_description ) && 0 === strpos( $obituary->ai_description, '__quarantined_' ) ) {
                        $prev_parts  = explode( '_', str_replace( '__quarantined_', '', $obituary->ai_description ) );
                        $prev_cycles = isset( $prev_parts[1] ) ? (int) $prev_parts[1] : 0;
                    }
                    $new_cycles = $prev_cycles + 1;
                    $quarantine_result = $wpdb->query( $wpdb->prepare(
                        "UPDATE `{$table}` SET ai_description = %s WHERE id = %d",
                        '__quarantined_' . time() . '_' . $new_cycles,
                        $obituary->id
                    ) );
                    if ( function_exists( 'oo_db_check' ) ) {
                        oo_db_check( 'REWRITE', $quarantine_result, 'DB_QUARANTINE_SET_FAIL', 'Failed to set quarantine marker', array( 'obit_id' => $obituary->id, 'name' => $obituary->name ) );
                    }
                    ontario_obituaries_log(
                        sprintf(
                            'AI Rewriter: QUARANTINED ID %d (%s) after %d consecutive validation failures (cycle %d/%d). Will retry in 1 hour.',
                            $obituary->id, $obituary->name, $fail_count, $new_cycles, $this->max_quarantine_cycles
                        ),
                        'warning'
                    );
                    delete_transient( $fail_key ); // Reset counter.
                }

                continue;
            }

            // Success — clear any failure counter for this record.
            delete_transient( 'oo_rewrite_fail_' . $obituary->id );

            // v6.0.4: Apply tone sanitizer BEFORE save. Removes banned slang,
            // AI cliches, and age-of-0 phrases that slip through the prompt.
            if ( function_exists( 'oo_tone_sanitize' ) ) {
                $rewrite_text = oo_tone_sanitize( $rewrite_text );
            }

            // v6.0.4 PIPELINE GATING: Rewriter no longer publishes directly.
            // Flow: pending → (rewrite) → pending + needs_audit → (audit pass) → published.
            // The authenticity checker is the ONLY component that sets status='published'.
            $sanitized_text = sanitize_textarea_field( $rewrite_text );
            $ai_hash = ! empty( $sanitized_text ) ? hash( 'sha256', $sanitized_text ) : null;

            $update_data = array(
                'ai_description'      => $sanitized_text,
                'ai_description_hash' => $ai_hash,
                'status'              => 'pending',         // v6.0.4: stays pending until audit pass.
                'audit_status'        => 'needs_audit',     // v6.0.4: flag for authenticity checker.
                'last_audited_hash'   => null,              // v6.0.4: force re-audit on new rewrite.
            );

            // Apply Groq-extracted fields — only overwrite when Groq returned a value
            // AND the value passed sanity checks in parse_structured_response().
            // NEVER overwrite a populated field with an empty value.
            if ( ! empty( $rewritten['date_of_death'] ) ) {
                $update_data['date_of_death'] = $rewritten['date_of_death'];
            }
            if ( ! empty( $rewritten['date_of_birth'] ) ) {
                $update_data['date_of_birth'] = $rewritten['date_of_birth'];
            }
            if ( ! empty( $rewritten['age'] ) && intval( $rewritten['age'] ) >= 1 && intval( $rewritten['age'] ) <= 120 ) {
                $update_data['age'] = intval( $rewritten['age'] );
            }
            // v6.0.2: If the existing DB age is 0 (bad scrape) and Groq didn't fix it,
            // actively set it to NULL to prevent "age 0" from displaying.
            if ( isset( $obituary->age ) && intval( $obituary->age ) === 0 && ! isset( $update_data['age'] ) ) {
                $update_data['age'] = null;
                ontario_obituaries_log(
                    sprintf( 'AI Rewriter: Clearing age=0 for ID %d (%s) — bad scrape data.', $obituary->id, $obituary->name ),
                    'info'
                );
            }
            if ( ! empty( $rewritten['location'] ) ) {
                $update_data['location'] = sanitize_text_field( $rewritten['location'] );
                // Only update city_normalized if it was previously empty.
                // The scraper's normalize_city() handles Toronto-area mergers
                // (North York → Toronto, etc.) at ingest time. We don't want
                // to overwrite that with the raw Groq value.
                if ( empty( $obituary->city_normalized ) ) {
                    $update_data['city_normalized'] = sanitize_text_field( $rewritten['location'] );
                }
            }
            if ( ! empty( $rewritten['funeral_home'] ) ) {
                $update_data['funeral_home'] = sanitize_text_field( $rewritten['funeral_home'] );
            }

            // v5.0.0: Single atomic DB update — prose + corrected fields + status.
            // Build explicit format array so WordPress uses correct types
            // (especially %d for age, %s for everything else).
            // v6.0.2: Handle NULL age — remove from $update_data and set via raw SQL.
            // v6.0.4: Handle NULL last_audited_hash — remove from $update_data and set via raw SQL.
            $null_age = false;
            if ( array_key_exists( 'age', $update_data ) && null === $update_data['age'] ) {
                $null_age = true;
                unset( $update_data['age'] );
            }
            $null_last_audited_hash = false;
            if ( array_key_exists( 'last_audited_hash', $update_data ) && null === $update_data['last_audited_hash'] ) {
                $null_last_audited_hash = true;
                unset( $update_data['last_audited_hash'] );
            }
            $update_formats = array();
            foreach ( $update_data as $col => $val ) {
                $update_formats[] = ( 'age' === $col ) ? '%d' : '%s';
            }

            $upd_result = $wpdb->update(
                $table,
                $update_data,
                array( 'id' => $obituary->id ),
                $update_formats,
                array( '%d' )
            );

            // v6.0.4: Set last_audited_hash to NULL via raw query (wpdb::update cannot set NULL).
            if ( $null_last_audited_hash ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE `{$table}` SET last_audited_hash = NULL WHERE id = %d",
                    $obituary->id
                ) );
            }

            // v6.0.2: Set age to NULL via raw query (wpdb::update cannot set NULL).
            // v6.0.3 QC FIX: Log failures from raw NULL query via oo_db_check.
            if ( $null_age ) {
                $null_result = $wpdb->query( $wpdb->prepare(
                    "UPDATE `{$table}` SET age = NULL WHERE id = %d",
                    $obituary->id
                ) );
                if ( false === $null_result ) {
                    ontario_obituaries_log(
                        sprintf(
                            'AI Rewriter: Failed to SET age=NULL for ID %d — %s',
                            $obituary->id,
                            $wpdb->last_error
                        ),
                        'error'
                    );
                }
                if ( function_exists( 'oo_db_check' ) ) {
                    oo_db_check( 'REWRITE', $null_result, 'DB_NULL_AGE_FAIL', 'Failed to set age=NULL', array(
                        'obit_id' => $obituary->id,
                        'name'    => $obituary->name,
                    ) );
                }
            }

            // v5.3.3: Phase 2c — check rewrite update succeeded.
            if ( function_exists( 'oo_db_check' ) ) {
                if ( ! oo_db_check( 'REWRITE', $upd_result, 'DB_REWRITE_SAVE_FAIL', 'Failed to save rewritten obituary', array(
                    'obit_id' => $obituary->id,
                    'name'    => $obituary->name,
                ) ) ) {
                    $result['failed']++;
                    $result['errors'][] = sprintf( 'ID %d (%s): DB update failed during rewrite save', $obituary->id, $obituary->name );
                    continue;
                }
            }

            $corrections_msg = ! empty( $rewritten['corrections'] )
                ? ' Corrections: ' . implode( '; ', $rewritten['corrections'] )
                : '';

            // v6.0.4: Rewriter no longer publishes — it marks as needs_audit.
            ontario_obituaries_log(
                sprintf( 'AI Rewriter: Rewrote obituary ID %d (%s) — awaiting audit.%s', $obituary->id, $obituary->name, $corrections_msg ),
                'info'
            );

            $result['succeeded']++;
        }

        ontario_obituaries_log(
            sprintf(
                'AI Rewriter: Batch complete — %d processed, %d succeeded, %d failed.%s',
                $result['processed'],
                $result['succeeded'],
                $result['failed'],
                $this->was_rate_limited ? ' (rate-limited)' : ''
            ),
            'info'
        );

        // v4.6.5: Include rate-limit status so the AJAX handler can tell JS to slow down.
        $result['rate_limited'] = $this->was_rate_limited;

        return $result;
    }

    /**
     * Rewrite a single obituary via the Groq LLM API.
     *
     * v5.0.0: Returns a parsed associative array with extracted fields + rewritten text,
     * or a WP_Error on failure. The caller (process_batch) uses the extracted fields
     * to correct DB columns.
     *
     * @param object $obituary Database row with name, dates, description, etc.
     * @return array|WP_Error Parsed JSON array with keys: date_of_death, date_of_birth,
     *                        age, location, funeral_home, rewritten_text. Or WP_Error.
     */
    public function rewrite_obituary( $obituary ) {
        // BUG-M1 FIX (v5.0.6): Check shared rate limiter before calling Groq.
        // QC-R11-1: Estimated tokens for rewriter = 1100.
        $rewriter_estimate = 1100;
        $has_reservation   = false;

        if ( class_exists( 'Ontario_Obituaries_Groq_Rate_Limiter' ) ) {
            $limiter = Ontario_Obituaries_Groq_Rate_Limiter::get_instance();
            if ( ! $limiter->may_proceed( $rewriter_estimate, 'rewriter' ) ) {
                // QC-R10-8 FIX: WP_Error data must be an array for REST/JSON compatibility.
                return new WP_Error(
                    'rate_limited',
                    'Shared Groq rate limiter: TPM budget exhausted. Deferring.',
                    array( 'seconds_until_reset' => $limiter->seconds_until_reset() )
                );
            }
            $has_reservation = true;
        }

        $prompt = $this->build_prompt( $obituary );

        $response = $this->call_api( $prompt );

        // v4.6.7: Handle 403 model permission blocked — try each fallback model.
        if ( is_wp_error( $response ) && 'model_permission_blocked' === $response->get_error_code() ) {
            foreach ( $this->fallback_models as $fallback ) {
                ontario_obituaries_log(
                    sprintf( 'AI Rewriter: Model permission blocked, trying fallback "%s".', $fallback ),
                    'info'
                );
                usleep( 2000000 );
                $response = $this->call_api( $prompt, $fallback );
                if ( ! is_wp_error( $response ) || 'model_permission_blocked' !== $response->get_error_code() ) {
                    break;
                }
            }
        }

        // On 401 (invalid key), don't retry — release reservation.
        if ( is_wp_error( $response ) && 'api_key_invalid' === $response->get_error_code() ) {
            // QC-R11-1: Release reservation on failure — no tokens consumed.
            if ( $has_reservation ) {
                $limiter->release_reservation( $rewriter_estimate, 'rewriter' );
            }
            return $response;
        }

        if ( is_wp_error( $response ) && 'rate_limited' === $response->get_error_code() ) {
            $retry_after = $response->get_error_data();
            if ( ! empty( $retry_after ) && is_numeric( $retry_after ) ) {
                ontario_obituaries_log(
                    sprintf( 'AI Rewriter: Rate-limited. Groq says retry after %ds.', $retry_after ),
                    'info'
                );
            } else {
                ontario_obituaries_log( 'AI Rewriter: Rate-limited (TPM quota exhausted). Will retry after backoff.', 'info' );
            }
            // QC-R11-1: Release reservation on failure — no tokens consumed.
            if ( $has_reservation ) {
                $limiter->release_reservation( $rewriter_estimate, 'rewriter' );
            }
            return $response;
        }

        if ( is_wp_error( $response ) ) {
            // QC-R11-1: Release reservation on any other error.
            if ( $has_reservation ) {
                $limiter->release_reservation( $rewriter_estimate, 'rewriter' );
            }
            return $response;
        }

        // v5.0.0: Parse the structured JSON response.
        return $this->parse_structured_response( $response, $obituary );
    }

    /**
     * Parse and validate the structured JSON response from Groq.
     *
     * v5.0.0: Extracts the JSON fields, runs sanity checks on each,
     * and falls back to original DB values when Groq's extraction is
     * empty or implausible.
     *
     * @param string $raw_json  Raw JSON string from Groq.
     * @param object $obituary  Original DB row (fallback values).
     * @return array|WP_Error   Validated array or error.
     */
    private function parse_structured_response( $raw_json, $obituary ) {
        // Strip markdown code fences if the model wrapped the JSON.
        $cleaned = trim( $raw_json );
        $cleaned = preg_replace( '/^```(?:json)?\s*/i', '', $cleaned );
        $cleaned = preg_replace( '/\s*```$/', '', $cleaned );

        $data = json_decode( $cleaned, true );

        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
            ontario_obituaries_log(
                sprintf( 'AI Rewriter: JSON parse failed for ID %d — %s. Raw: %s', $obituary->id, json_last_error_msg(), substr( $raw_json, 0, 200 ) ),
                'error'
            );
            return new WP_Error( 'json_parse_failed', 'Groq returned invalid JSON: ' . json_last_error_msg() );
        }

        // Require rewritten_text at minimum.
        if ( empty( $data['rewritten_text'] ) ) {
            return new WP_Error( 'missing_rewritten_text', 'Groq JSON missing rewritten_text field.' );
        }

        // ── Validate and sanitize each extracted field ──
        $result = array(
            'rewritten_text' => trim( $data['rewritten_text'] ),
            'date_of_death'  => '',
            'date_of_birth'  => '',
            'age'            => null,
            'location'       => '',
            'funeral_home'   => '',
            'corrections'    => array(), // Track what we corrected for logging.
        );

        // Date of death — must be valid YYYY-MM-DD, not in the future, not before 2000.
        // v5.1.1: Tightened from 1900 to 2000 — Ontario obituary data starts ~2020+;
        // pre-2000 dates from Groq extraction are artefacts, not real death dates.
        if ( ! empty( $data['date_of_death'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $data['date_of_death'] ) ) {
            $dod = $data['date_of_death'];
            $dod_ts = strtotime( $dod );
            if ( $dod_ts && $dod <= date( 'Y-m-d' ) && $dod >= '2000-01-01' ) {
                $result['date_of_death'] = $dod;
                // Log correction if different from regex value.
                if ( ! empty( $obituary->date_of_death ) && $obituary->date_of_death !== $dod ) {
                    $result['corrections'][] = sprintf( 'date_of_death: %s → %s', $obituary->date_of_death, $dod );
                }
            }
        }

        // Date of birth — must be valid YYYY-MM-DD, before date of death, after 1880.
        if ( ! empty( $data['date_of_birth'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $data['date_of_birth'] ) ) {
            $dob = $data['date_of_birth'];
            $dob_ts = strtotime( $dob );
            $effective_dod = ! empty( $result['date_of_death'] ) ? $result['date_of_death'] : date( 'Y-m-d' );
            if ( $dob_ts && $dob < $effective_dod && $dob >= '1880-01-01' ) {
                $result['date_of_birth'] = $dob;
                if ( ! empty( $obituary->date_of_birth ) && $obituary->date_of_birth !== '0000-00-00' && $obituary->date_of_birth !== $dob ) {
                    $result['corrections'][] = sprintf( 'date_of_birth: %s → %s', $obituary->date_of_birth, $dob );
                }
            }
        }

        // Age — must be 1-120 (v6.0.2: tightened from 0-130; age 0 is always a data error,
        // age >120 is implausible for modern Ontario obituaries).
        if ( isset( $data['age'] ) && is_numeric( $data['age'] ) ) {
            $age = intval( $data['age'] );
            if ( $age >= 1 && $age <= 120 ) {
                $result['age'] = $age;
                if ( ! empty( $obituary->age ) && intval( $obituary->age ) > 0 && intval( $obituary->age ) !== $age ) {
                    $result['corrections'][] = sprintf( 'age: %d → %d', intval( $obituary->age ), $age );
                }
            } else {
                // v6.0.2: Log implausible age — do NOT store it.
                ontario_obituaries_log(
                    sprintf( 'AI Rewriter: Rejected implausible age %d for ID %d (%s). Age must be 1-120.', $age, $obituary->id, $obituary->name ),
                    'warning'
                );
            }
        }

        // Location — non-empty, reasonable length, no code/garbage.
        if ( ! empty( $data['location'] ) && is_string( $data['location'] ) ) {
            $loc = trim( $data['location'] );
            if ( strlen( $loc ) >= 2 && strlen( $loc ) <= 60 && ! preg_match( '/[{}<>\[\]]/', $loc ) ) {
                $result['location'] = $loc;
                if ( ! empty( $obituary->location ) && strtolower( $obituary->location ) !== strtolower( $loc ) ) {
                    $result['corrections'][] = sprintf( 'location: "%s" → "%s"', $obituary->location, $loc );
                }
            }
        }

        // Funeral home — non-empty, reasonable length.
        if ( ! empty( $data['funeral_home'] ) && is_string( $data['funeral_home'] ) ) {
            $fh = trim( $data['funeral_home'] );
            if ( strlen( $fh ) >= 3 && strlen( $fh ) <= 150 ) {
                $result['funeral_home'] = $fh;
            }
        }

        // v6.0.3 QC FIX: Strengthened age cross-validation.
        // Only compute when BOTH dates are valid (not poison dates like 0000-00-00).
        // Uses DateTime::diff() for correct leap-year/birthday-edge handling.
        // Prefers explicit Groq-extracted age when it passes sanity checks;
        // only overrides with computed age when there's a significant mismatch.
        $dob_valid = ! empty( $result['date_of_birth'] )
            && '0000-00-00' !== $result['date_of_birth']
            && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $result['date_of_birth'] );
        $dod_valid = ! empty( $result['date_of_death'] )
            && '0000-00-00' !== $result['date_of_death']
            && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $result['date_of_death'] );

        if ( $dob_valid && $dod_valid ) {
            try {
                $b = new DateTime( $result['date_of_birth'] );
                $d = new DateTime( $result['date_of_death'] );

                // v6.0.3: Guard — DOB must be before DOD (poison-date rule).
                if ( $b >= $d ) {
                    ontario_obituaries_log(
                        sprintf(
                            'AI Rewriter: Poison date detected for ID %d — DOB %s >= DOD %s. Clearing DOB.',
                            $obituary->id, $result['date_of_birth'], $result['date_of_death']
                        ),
                        'warning'
                    );
                    $result['date_of_birth'] = '';
                    // Don't compute age from invalid dates.
                } else {
                    $diff         = $d->diff( $b );
                    $computed_age = $diff->y; // DateTime::diff handles leap years correctly.

                    // v6.0.3: If Groq returned an age, prefer it unless significantly wrong.
                    if ( ! empty( $result['age'] ) ) {
                        // Allow ±1 year tolerance (birthday edge cases).
                        if ( abs( $computed_age - $result['age'] ) > 1 ) {
                            $groq_age = $result['age']; // Capture before overwrite.
                            ontario_obituaries_log(
                                sprintf(
                                    'AI Rewriter: Age cross-validation mismatch for ID %d — Groq says %d, computed %d from dates %s to %s. Using computed.',
                                    $obituary->id, $groq_age, $computed_age, $result['date_of_birth'], $result['date_of_death']
                                ),
                                'warning'
                            );
                            $result['age'] = $computed_age;
                            $result['corrections'][] = sprintf( 'age: Groq %d → computed %d', $groq_age, $computed_age );
                        }
                        // If within ±1, keep Groq's age (prefer explicit over computed).
                    } else {
                        // Groq didn't return a valid age — compute from dates.
                        if ( $computed_age >= 1 && $computed_age <= 120 ) {
                            $result['age'] = $computed_age;
                            $result['corrections'][] = sprintf( 'age: (computed from dates) → %d', $computed_age );
                            ontario_obituaries_log(
                                sprintf( 'AI Rewriter: Computed age %d from dates for ID %d (%s).', $computed_age, $obituary->id, $obituary->name ),
                                'info'
                            );
                        }
                    }
                }

                // Final sanity check — computed age must also be plausible.
                if ( ! empty( $result['age'] ) && ( $result['age'] < 1 || $result['age'] > 120 ) ) {
                    ontario_obituaries_log(
                        sprintf( 'AI Rewriter: Computed age %d is implausible for ID %d — clearing.', $result['age'], $obituary->id ),
                        'warning'
                    );
                    $result['age'] = null;
                }
            } catch ( Exception $e ) {
                // Date parsing failed — keep Groq's age, log the error.
                ontario_obituaries_log(
                    sprintf( 'AI Rewriter: DateTime exception for ID %d — %s', $obituary->id, $e->getMessage() ),
                    'warning'
                );
            }
        }

        // Log corrections.
        if ( ! empty( $result['corrections'] ) ) {
            ontario_obituaries_log(
                sprintf(
                    'AI Rewriter: ID %d (%s) — Groq corrected: %s',
                    $obituary->id,
                    $obituary->name,
                    implode( '; ', $result['corrections'] )
                ),
                'info'
            );
        }

        return $result;
    }

    /**
     * Build the system + user prompt for structured JSON extraction + rewrite.
     *
     * v5.0.0: Complete redesign. The LLM now performs TWO jobs in ONE call:
     *   1. EXTRACT correct structured data from the original text (dates, age, location).
     *   2. REWRITE the obituary into professional prose.
     *
     * The response is a JSON object with both the extracted fields and the rewritten text.
     * This eliminates regex errors because the LLM reads and understands the full text.
     *
     * @param object $obituary Database row.
     * @return array Messages array for the chat completion API.
     */
    private function build_prompt( $obituary ) {
        // v6.0.2: Enhanced prompt — stronger data accuracy rules to prevent age 0,
        // wrong dates, and fabricated details. Added explicit "NEVER output age 0"
        // and cross-validation instructions.
        // v6.0.4: Professional tone — banned slang, AI cliche list, facts-only rule.
        $system = <<<'SYSTEM'
You extract structured data from obituaries and rewrite them as dignified prose for an Ontario funeral directory.

Return ONLY a JSON object with these keys:
{
  "date_of_death": "YYYY-MM-DD or empty",
  "date_of_birth": "YYYY-MM-DD or empty",
  "age": integer or null,
  "location": "City or empty",
  "funeral_home": "Name or empty",
  "rewritten_text": "Rewritten obituary"
}

EXTRACTION RULES (strict):
- Read the ORIGINAL TEXT carefully. SUGGESTIONS may be wrong — trust the original text if they conflict.
- For "in his/her Nth year", age = N-1.
- NEVER return age 0 or age null when the text states an age. Age must be between 1 and 120.
- If no age is stated in the text, return null for age — do NOT guess or compute.
- If dates of birth AND death are both present, verify the age matches (death_year - birth_year ± 1).
- Only extract location/funeral_home if explicitly stated in the original text.
- Dates must be real calendar dates. Do not confuse funeral/visitation dates with death dates.

REWRITING RULES:
- Preserve every fact exactly: full legal name, all dates, locations, funeral home, age, survivors, predeceased.
- Include death date and age explicitly in the prose (e.g., "passed away on February 14, 2025, at the age of 78").
- NEVER write "at the age of 0" — if age is unknown, omit the age phrase entirely.
- NEVER invent details not in the original (no family members, hobbies, traits, cause of death).
- A year in parentheses after a name (e.g. "wife of Frank (2020)") means that person DIED — write "predeceased by".
- 2-4 paragraphs, third person, plain prose. No headings or formatting.
- If original is sparse, keep rewrite brief. No padding.
- No phrases like "according to the obituary". No condolences.

TONE (strict — professional memorial):
- Write in a respectful, dignified, matter-of-fact memorial tone.
- BANNED terms (never use unless they appear VERBATIM in the original text):
  "fur boys", "fur babies", "fur baby", "doggo", "pupper", "good boy",
  "kiddo", "kiddos", "hubby", "wifey", "bestie", "bestie forever",
  "journey", "shined bright", "indelible mark", "tapestry of life",
  "forever etched", "treasured memories", "cherished moments", "left a void",
  "beacon of light", "heart of gold", "touched the lives", "celebrated life".
- For pets: use "dogs", "cats", "pets" — never slang.
- No sentimental filler. State facts plainly. Let the facts speak.
- Do NOT add commentary, praise, or editorial observations not in the original.

Return ONLY the JSON object.
SYSTEM;

        // Build suggestions block — these are regex-extracted values that may be wrong.
        // Groq uses them as hints but trusts the original text when they conflict.
        $suggestions = array();
        $suggestions[] = 'Name: ' . $obituary->name;

        if ( ! empty( $obituary->date_of_death ) && '0000-00-00' !== $obituary->date_of_death ) {
            $suggestions[] = 'Date of Death (regex-extracted, may be wrong): ' . $obituary->date_of_death;
        }

        if ( ! empty( $obituary->date_of_birth ) && '0000-00-00' !== $obituary->date_of_birth ) {
            $suggestions[] = 'Date of Birth (regex-extracted, may be wrong): ' . $obituary->date_of_birth;
        }

        if ( ! empty( $obituary->age ) && intval( $obituary->age ) > 0 ) {
            $suggestions[] = 'Age (regex-extracted, may be wrong): ' . intval( $obituary->age );
        }

        if ( ! empty( $obituary->location ) ) {
            $suggestions[] = 'Location (regex-extracted, may be wrong): ' . $obituary->location;
        }

        if ( ! empty( $obituary->funeral_home ) ) {
            $suggestions[] = 'Funeral Home: ' . $obituary->funeral_home;
        }

        $user_message = "SUGGESTIONS (may contain errors — verify against the original text):\n"
            . implode( "\n", $suggestions )
            . "\n\nORIGINAL OBITUARY TEXT:\n" . $obituary->description;

        return array(
            array( 'role' => 'system', 'content' => $system ),
            array( 'role' => 'user',   'content' => $user_message ),
        );
    }

    /**
     * Call the Groq chat completion API with JSON response format.
     *
     * v5.0.0: temperature 0.1 for deterministic structured extraction.
     * response_format json_object enforces valid JSON output.
     * max_tokens 1500 to accommodate JSON wrapper + rewritten text.
     *
     * @param array  $messages Chat messages.
     * @param string $model    Optional model override.
     * @return string|WP_Error The raw JSON string, or error.
     */
    private function call_api( $messages, $model = '' ) {
        if ( empty( $model ) ) {
            $model = $this->model;
        }

        $body = wp_json_encode( array(
            'model'           => $model,
            'messages'        => $messages,
            'temperature'     => 0.1,
            'max_tokens'      => 1500,
            'top_p'           => 0.95,
            'response_format' => array( 'type' => 'json_object' ),
        ) );

        $response = oo_safe_http_post( 'REWRITE', $this->api_url, array(
            'timeout' => $this->api_timeout,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ),
            'body' => $body,
        ), array( 'source' => 'class-ai-rewriter', 'purpose' => 'call_api', 'model' => $model ) );

        // Wrapper returns WP_Error for transport failures AND HTTP ≥ 400.
        if ( is_wp_error( $response ) ) {
            $http_status = oo_http_error_status( $response );

            // Handle rate limiting — extract retry-after header for smarter backoff.
            if ( 429 === $http_status ) {
                $retry_after = oo_http_error_header( $response, 'retry-after' );
                $retry_secs  = is_numeric( $retry_after ) ? intval( $retry_after ) : 0;
                return new WP_Error( 'rate_limited', 'Groq API rate limit exceeded (likely TPM).', $retry_secs );
            }

            // v4.6.7: Handle 401 Unauthorized — API key is invalid, expired, or revoked.
            if ( 401 === $http_status ) {
                $error_detail = '';
                $decoded = json_decode( oo_http_error_body( $response ), true );
                if ( ! empty( $decoded['error']['message'] ) ) {
                    $error_detail = $decoded['error']['message'];
                }
                // Business-event log (wrapper already logged HTTP 401).
                ontario_obituaries_log(
                    sprintf( 'AI Rewriter: Groq API key is INVALID (HTTP 401). Go to Settings → Ontario Obituaries → AI Rewrite Engine and enter a valid key from https://console.groq.com/keys' ),
                    'error'
                );
                return new WP_Error(
                    'api_key_invalid',
                    sprintf(
                        'Groq API key is invalid or expired (HTTP 401). %s. Please generate a new key at https://console.groq.com/keys and update it in Settings → Ontario Obituaries.',
                        $error_detail
                    )
                );
            }

            // v4.6.7: Handle 403 Forbidden — model permission blocked or firewall issue.
            if ( 403 === $http_status ) {
                $error_detail = '';
                $error_code   = '';
                $decoded = json_decode( oo_http_error_body( $response ), true );
                if ( ! empty( $decoded['error']['message'] ) ) {
                    $error_detail = $decoded['error']['message'];
                }
                if ( ! empty( $decoded['error']['code'] ) ) {
                    $error_code = $decoded['error']['code'];
                }
                // Business-event log (wrapper already logged HTTP 403).
                ontario_obituaries_log(
                    sprintf( 'AI Rewriter: Groq API returned HTTP 403 for model "%s". Code: %s. Detail: %s', $model, $error_code, $error_detail ),
                    'error'
                );
                return new WP_Error(
                    'model_permission_blocked',
                    sprintf(
                        'Groq API returned 403 (Permission Denied) for model "%s". %s. Check your model permissions at https://console.groq.com/settings/limits or try a different model.',
                        $model,
                        $error_detail
                    )
                );
            }

            // Generic failure (transport or other HTTP error).
            return new WP_Error( 'api_error', 'HTTP request failed: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        $data = json_decode( $body, true );

        if ( empty( $data['choices'][0]['message']['content'] ) ) {
            return new WP_Error( 'empty_response', 'Groq API returned empty content.' );
        }

        // BUG-M1 FIX (v5.0.6): Record actual token usage in shared rate limiter.
        // QC-R12-2 FIX: Pass the original estimate to record_usage() so it computes
        // delta = actual - estimated. Without this, the legacy $estimated=0 path
        // would add actual tokens ON TOP of the already-reserved estimate.
        // QC-R12 FIX: If usage data is missing, the reservation stands (overcount,
        // safe direction). We still record_usage(estimated, consumer, estimated) to
        // signal "no adjustment needed" and prevent token pool from going negative.
        if ( class_exists( 'Ontario_Obituaries_Groq_Rate_Limiter' ) ) {
            $actual = ! empty( $data['usage']['total_tokens'] )
                ? (int) $data['usage']['total_tokens']
                : 1100; // Fallback: assume estimate was correct — reservation stands.
            Ontario_Obituaries_Groq_Rate_Limiter::get_instance()->record_usage(
                $actual,
                'rewriter',
                1100  // Must match the estimate in rewrite_obituary()'s may_proceed() call.
            );
        }

        return trim( $data['choices'][0]['message']['content'] );
    }

    /**
     * Validate the rewritten obituary text.
     *
     * v5.0.0: Validation now checks the rewritten prose against BOTH the original
     * DB values AND the Groq-extracted values. If Groq extracted a corrected date,
     * we validate against Groq's date (not the potentially-wrong regex date).
     *
     * @param string $rewritten       The rewritten prose text.
     * @param object $obituary        The original database row.
     * @param array  $extracted_data  The full parsed Groq response (with extracted fields).
     * @return true|WP_Error True if valid.
     */
    private function validate_rewrite( $rewritten, $obituary, $extracted_data = array() ) {
        $lower = strtolower( $rewritten );

        // ── 1. Length check ──
        $len = strlen( $rewritten );
        if ( $len < 50 ) {
            return new WP_Error( 'too_short', sprintf( 'Rewrite too short (%d chars).', $len ) );
        }
        if ( $len > 5000 ) {
            return new WP_Error( 'too_long', sprintf( 'Rewrite too long (%d chars).', $len ) );
        }

        // ── 1b. Implausible data check (v6.0.2) ──
        // Hard-reject rewrites that contain obviously wrong data in the prose.
        // These patterns indicate the LLM reproduced bad source data verbatim.
        $implausible_patterns = array(
            '/\bage(?:d?)?\s+(?:of\s+)?0\b/i',           // "age 0", "age of 0", "aged 0"
            '/\bat\s+the\s+age\s+of\s+0\b/i',            // "at the age of 0"
            '/\bin\s+(?:his|her|their)\s+(?:1st|first)\s+year\b/i',  // "in his 1st year" (implies age 0)
            '/\bage(?:d?)?\s+(?:of\s+)?(?:1[3-9]\d{2}|20\d{2})\b/i', // "age 1900", "age 2024" (year confused with age)
        );
        foreach ( $implausible_patterns as $pattern ) {
            if ( preg_match( $pattern, $rewritten ) ) {
                ontario_obituaries_log(
                    sprintf(
                        'AI Rewriter: HARD REJECT — rewrite for ID %d (%s) contains implausible data: "%s". Setting to re-queue.',
                        $obituary->id,
                        $obituary->name,
                        $pattern
                    ),
                    'error'
                );
                return new WP_Error( 'implausible_data', sprintf(
                    'Rewrite contains implausible data (pattern: %s). Will retry.',
                    $pattern
                ) );
            }
        }

        // ── 2. Name check (last name required, first name preferred) ──
        // v5.3.1 FIX: Strip parenthesized nicknames/aliases BEFORE splitting.
        // Names like "Patricia Gillian Ansaldo (Gillian)" caused the validator
        // to treat "(Gillian)" as the last name — the parens never appear in the
        // rewrite, so the check always fails. This blocked the entire queue
        // (same deadlock pattern as date/age/location, demoted in v5.1.1-5.1.2).
        $clean_name = preg_replace( '/\s*\([^)]*\)\s*/', ' ', trim( $obituary->name ) );
        $clean_name = trim( preg_replace( '/\s+/', ' ', $clean_name ) );
        $name_parts = preg_split( '/\s+/', $clean_name );
        $last_name  = end( $name_parts );
        $last_name  = preg_replace( '/^(Jr|Sr|III|II|IV)\.?$/i', '', $last_name );
        $last_name  = trim( $last_name );

        // v5.3.1 FIX: Demote name_missing from hard-fail to warning. Same deadlock
        // pattern as date_missing (v5.1.2) and location_missing (v5.1.1): With
        // batch_size=1 and ORDER BY created_at DESC, a single record that
        // consistently fails validation blocks the entire rewrite queue forever.
        // Groq may use a nickname, middle name, or omit the first name entirely.
        // The DB row retains the original name regardless of the rewrite text.
        if ( strlen( $last_name ) >= 3 && false === stripos( $rewritten, $last_name ) ) {
            $first_name = reset( $name_parts );
            $first_name = preg_replace( '/^(Mr|Mrs|Ms|Dr|Rev)\.?$/i', '', $first_name );
            $first_name = trim( $first_name );

            if ( strlen( $first_name ) >= 3 && false === stripos( $rewritten, $first_name ) ) {
                // Also check the original parenthesized parts — the nickname may
                // appear in the rewrite even though the legal name doesn't.
                $nickname_found = false;
                if ( preg_match_all( '/\(([^)]+)\)/', $obituary->name, $nick_matches ) ) {
                    foreach ( $nick_matches[1] as $nick ) {
                        if ( strlen( $nick ) >= 3 && false !== stripos( $rewritten, $nick ) ) {
                            $nickname_found = true;
                            break;
                        }
                    }
                }

                if ( ! $nickname_found ) {
                    ontario_obituaries_log(
                        sprintf( 'AI Rewriter: Validation warning — rewrite does not mention "%s" or "%s" (ID %d). Continuing.', $last_name, $first_name, $obituary->id ),
                        'warning'
                    );
                }
            }
        }

        // ── 3. Death date check — use Groq-extracted date if available ──
        // v5.1.2 FIX: Demoted from hard-fail to warning. Same deadlock pattern as
        // location_missing (v5.1.1): Groq may paraphrase dates ("early February" vs
        // "February 4"), use a different format, or omit the day. With batch_size=1,
        // a single record that consistently fails blocks the entire queue.
        // The structured date_of_death field is preserved in the DB row regardless.
        $check_dod = ! empty( $extracted_data['date_of_death'] ) ? $extracted_data['date_of_death'] : '';
        if ( empty( $check_dod ) && ! empty( $obituary->date_of_death ) && '0000-00-00' !== $obituary->date_of_death ) {
            $check_dod = $obituary->date_of_death;
        }
        if ( ! empty( $check_dod ) ) {
            $dod_parts = explode( '-', $check_dod );
            if ( count( $dod_parts ) === 3 ) {
                $year = $dod_parts[0];
                $day  = ltrim( $dod_parts[2], '0' );
                if ( false === strpos( $rewritten, $year ) ) {
                    ontario_obituaries_log(
                        sprintf( 'AI Rewriter: Validation warning — rewrite does not mention death year %s (ID %d). Continuing.', $year, $obituary->id ),
                        'warning'
                    );
                }
                if ( false === strpos( $rewritten, $day ) ) {
                    ontario_obituaries_log(
                        sprintf( 'AI Rewriter: Validation warning — rewrite does not mention death day %s (ID %d). Continuing.', $day, $obituary->id ),
                        'warning'
                    );
                }
            }
        }

        // ── 4. Age check — use Groq-extracted age if available ──
        // v5.1.2 FIX: Demoted from hard-fail to warning. Proven blocker on ID 1311
        // ("Rewrite does not mention age 71"). Groq may express age differently
        // ("seventy-one", "in her 72nd year", compute a different age from DOB/DOD),
        // or the DB age may itself be wrong (see v5.1.0 extract_age_from_text fix).
        // The structured age field is preserved in the DB row regardless.
        $check_age = ! empty( $extracted_data['age'] ) ? intval( $extracted_data['age'] ) : 0;
        if ( 0 === $check_age && ! empty( $obituary->age ) ) {
            $check_age = intval( $obituary->age );
        }
        if ( $check_age > 0 ) {
            $age_str = strval( $check_age );
            if ( false === strpos( $rewritten, $age_str ) ) {
                ontario_obituaries_log(
                    sprintf( 'AI Rewriter: Validation warning — rewrite does not mention age %s (ID %d). Continuing.', $age_str, $obituary->id ),
                    'warning'
                );
            }
        }

        // ── 5. Location check — use Groq-extracted location if available ──
        $check_loc = ! empty( $extracted_data['location'] ) ? $extracted_data['location'] : '';
        if ( empty( $check_loc ) ) {
            $check_loc = ! empty( $obituary->city_normalized ) ? $obituary->city_normalized : '';
            if ( empty( $check_loc ) && ! empty( $obituary->location ) ) {
                $check_loc = $obituary->location;
            }
        }
        if ( ! empty( $check_loc ) && strlen( $check_loc ) >= 3 ) {
            $city_check = preg_split( '/[,\-]/', $check_loc );
            $city_check = trim( $city_check[0] );
            if ( strlen( $city_check ) >= 3 && false === stripos( $rewritten, $city_check ) ) {
                // v5.1.1 FIX (production hotfix upstream): Demoted from hard-fail to warning.
                //
                // Root cause: Groq extracts a location from text (e.g. "Grand River Hospital",
                // "Port Colborne") even when the DB location column is empty. The validator then
                // requires the rewritten prose to mention this extracted location, but the model
                // may paraphrase or omit it. With batch_size=1 and ORDER BY created_at DESC,
                // a single record that consistently fails this check blocks the entire queue
                // indefinitely ("0 succeeded / 1 failed" deadlock).
                //
                // Acceptable risk: a rewrite may omit a location mention. This is less harmful
                // than publishing nothing (the original description is still available as fallback,
                // and the structured location field is preserved in the DB row regardless).
                //
                // TODO (v5.2.0): Improve by injecting location into build_prompt() system message
                // ("You MUST mention {location} in the rewrite") so the model is more likely to
                // include it, then re-enable hard validation.
                ontario_obituaries_log(
                    sprintf(
                        'AI Rewriter: Validation warning — rewrite did not mention location "%s" (ID %d). Continuing.',
                        $city_check,
                        $obituary->id
                    ),
                    'warning'
                );
            }
        }

        // ── 6. LLM artifact check ──
        $artifacts = array(
            'as an ai', 'i cannot', 'i\'m sorry', 'language model',
            'here is', 'here\'s the rewritten', 'certainly!', 'sure!',
            'of course!', 'i\'d be happy', 'i am an', 'note:', 'disclaimer:',
        );
        foreach ( $artifacts as $artifact ) {
            if ( false !== strpos( $lower, $artifact ) ) {
                return new WP_Error( 'llm_artifact', sprintf( 'Rewrite contains LLM artifact: "%s".', $artifact ) );
            }
        }

        return true;
    }

    /**
     * Get the number of obituaries still awaiting AI rewrite.
     *
     * v6.0.4: Only counts records that have NO ai_description yet (or quarantined).
     * Records that have been rewritten but await audit (audit_status='needs_audit')
     * are NOT counted here — they're done from the rewriter's perspective.
     *
     * @return int Count of unrewritten obituaries.
     */
    public function get_pending_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        // v4.6.4 FIX: Query must EXACTLY match the process_batch() WHERE clause.
        // v6.0.3 QC FIX: Include quarantined records in the count (they are pending
        // but temporarily blocked). This gives accurate UI counts.
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE status = 'pending'
               AND description IS NOT NULL
               AND description != ''
               AND (ai_description IS NULL OR ai_description = '' OR ai_description LIKE '__quarantined_%')
               AND suppressed_at IS NULL"
        );
    }

    /**
     * v6.0.4: Get count of obituaries awaiting authenticity audit.
     *
     * These are records that have been rewritten (ai_description set) but
     * not yet audited (audit_status = 'needs_audit').
     *
     * @return int Count of records awaiting audit.
     */
    public function get_needs_audit_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE audit_status = 'needs_audit'
               AND ai_description IS NOT NULL
               AND ai_description != ''
               AND suppressed_at IS NULL"
        );
    }

    /**
     * Get rewrite statistics.
     *
     * v4.6.0: Now tracks pipeline status (pending → published).
     * v6.0.4: Added needs_audit count for pipeline visibility.
     *
     * @return array { total: int, rewritten: int, pending: int, published: int, needs_audit: int, pct: float }
     */
    public function get_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE description IS NOT NULL AND description != '' AND suppressed_at IS NULL"
        );

        $rewritten = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE ai_description IS NOT NULL AND ai_description != '' AND suppressed_at IS NULL"
        );

        $published = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE status = 'published' AND suppressed_at IS NULL"
        );

        $pending_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE status = 'pending' AND suppressed_at IS NULL"
        );

        // v6.0.4: Count records awaiting audit (rewritten but not yet published).
        $needs_audit = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE audit_status = 'needs_audit'
               AND ai_description IS NOT NULL AND ai_description != ''
               AND suppressed_at IS NULL"
        );

        $pct = $total > 0 ? round( ( $published / $total ) * 100, 1 ) : 0;

        return array(
            'total'            => $total,
            'rewritten'        => $rewritten,
            'published'        => $published,
            'pending'          => $pending_count,
            'needs_audit'      => $needs_audit,
            'pending_rewrite'  => max( 0, $total - $rewritten ),
            'pct'              => $pct,
            'percent_complete' => $pct,
        );
    }
}
