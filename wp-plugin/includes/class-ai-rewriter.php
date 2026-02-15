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
 *   - Rate-limited: CLI mode uses 6s delay (~10/min); AJAX mode uses 15s delay (~4/min).
 *   - Batch size: CLI mode uses 10; AJAX mode uses 3 (fits within 90s timeout).
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
    private $batch_size = 3;

    /**
     * Delay between API requests in microseconds.
     * - CLI mode (cron-rewriter.php):  6 seconds  = 6,000,000  — no browser timeout.
     * - AJAX/web mode:                15 seconds = 15,000,000 — safe for 90s timeout.
     */
    private $request_delay = 15000000;

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
        } elseif ( $this->is_cli ) {
            // CLI has no browser timeout — use larger batches.
            $this->batch_size = 10;
        }

        if ( $this->is_cli ) {
            // CLI mode: 6-second delay (10 req/min, well within Groq's 30 req/min free tier).
            $this->request_delay = 6000000;
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

        $response = wp_remote_post( $this->api_url, array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ),
            'body' => $body,
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'connection_error', 'Cannot reach Groq API: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $resp_body = wp_remote_retrieve_body( $response );
        $decoded = json_decode( $resp_body, true );
        $error_msg = ! empty( $decoded['error']['message'] ) ? $decoded['error']['message'] : '';

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
                $fb_resp = wp_remote_post( $this->api_url, array(
                    'timeout' => 15,
                    'headers' => array(
                        'Content-Type'  => 'application/json',
                        'Authorization' => 'Bearer ' . $this->api_key,
                    ),
                    'body' => $fb_body,
                ) );
                if ( ! is_wp_error( $fb_resp ) ) {
                    $fb_code = wp_remote_retrieve_response_code( $fb_resp );
                    if ( $fb_code >= 200 && $fb_code < 300 ) {
                        $working_model = $fallback;
                        break;
                    }
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

        if ( $code >= 200 && $code < 300 ) {
            return true;
        }

        return new WP_Error( 'unknown_error', sprintf( 'Groq API returned HTTP %d. %s', $code, $error_msg ) );
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
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN ai_description text DEFAULT NULL AFTER description" );
            ontario_obituaries_log( 'AI Rewriter: Created missing ai_description column.', 'warning' );
        }

        // v4.6.0: Get obituaries with status='pending' that need AI rewrite.
        // Only pending records are processed — they become 'published' after
        // successful rewrite + validation.
        $obituaries = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, date_of_birth, date_of_death, age, funeral_home,
                    location, description, city_normalized
             FROM `{$table}`
             WHERE status = 'pending'
               AND description IS NOT NULL
               AND description != ''
               AND (ai_description IS NULL OR ai_description = '')
               AND suppressed_at IS NULL
             ORDER BY created_at DESC
             LIMIT %d",
            $this->batch_size
        ) );

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

                    if ( $this->consecutive_rate_limits >= 3 ) {
                        ontario_obituaries_log(
                            'AI Rewriter: 3 consecutive rate-limits — pausing batch. Will resume on next AJAX call.',
                            'warning'
                        );
                        break;
                    }

                    ontario_obituaries_log(
                        sprintf(
                            'AI Rewriter: Rate limited (consecutive: %d/%d) — will retry with backoff.',
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
                continue;
            }

            // v5.0.0: Build the DB update — rewritten text + corrected structured fields.
            $update_data = array(
                'ai_description' => sanitize_textarea_field( $rewrite_text ),
                'status'         => 'published',
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
            if ( ! empty( $rewritten['age'] ) && intval( $rewritten['age'] ) > 0 ) {
                $update_data['age'] = intval( $rewritten['age'] );
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
            $update_formats = array();
            foreach ( $update_data as $col => $val ) {
                $update_formats[] = ( 'age' === $col ) ? '%d' : '%s';
            }

            $wpdb->update(
                $table,
                $update_data,
                array( 'id' => $obituary->id ),
                $update_formats,
                array( '%d' )
            );

            $corrections_msg = ! empty( $rewritten['corrections'] )
                ? ' Corrections: ' . implode( '; ', $rewritten['corrections'] )
                : '';

            ontario_obituaries_log(
                sprintf( 'AI Rewriter: Published obituary ID %d (%s).%s', $obituary->id, $obituary->name, $corrections_msg ),
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

        // On 401 (invalid key), don't retry.
        if ( is_wp_error( $response ) && 'api_key_invalid' === $response->get_error_code() ) {
            return $response;
        }

        if ( is_wp_error( $response ) && 'rate_limited' === $response->get_error_code() ) {
            ontario_obituaries_log( 'AI Rewriter: Primary model rate-limited, trying fallback.', 'info' );
            usleep( 3000000 );
            $response = $this->call_api( $prompt, $this->fallback_model );

            if ( is_wp_error( $response ) && 'rate_limited' === $response->get_error_code() ) {
                ontario_obituaries_log( 'AI Rewriter: Fallback also rate-limited, waiting 15s and retrying.', 'info' );
                usleep( 15000000 );
                $response = $this->call_api( $prompt );
            }
        }

        if ( is_wp_error( $response ) ) {
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

        // Date of death — must be valid YYYY-MM-DD, not in the future, not before 1900.
        if ( ! empty( $data['date_of_death'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $data['date_of_death'] ) ) {
            $dod = $data['date_of_death'];
            $dod_ts = strtotime( $dod );
            if ( $dod_ts && $dod <= date( 'Y-m-d' ) && $dod >= '1900-01-01' ) {
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

        // Age — must be 0-130.
        if ( isset( $data['age'] ) && is_numeric( $data['age'] ) ) {
            $age = intval( $data['age'] );
            if ( $age > 0 && $age <= 130 ) {
                $result['age'] = $age;
                if ( ! empty( $obituary->age ) && intval( $obituary->age ) > 0 && intval( $obituary->age ) !== $age ) {
                    $result['corrections'][] = sprintf( 'age: %d → %d', intval( $obituary->age ), $age );
                }
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

        // Cross-validate: if we have both birth and death dates, compute expected age.
        if ( ! empty( $result['date_of_birth'] ) && ! empty( $result['date_of_death'] ) && ! empty( $result['age'] ) ) {
            try {
                $b = new DateTime( $result['date_of_birth'] );
                $d = new DateTime( $result['date_of_death'] );
                $computed_age = $d->diff( $b )->y;
                // Allow ±1 year tolerance (birthday edge cases).
                if ( abs( $computed_age - $result['age'] ) > 1 ) {
                    ontario_obituaries_log(
                        sprintf(
                            'AI Rewriter: Age cross-validation mismatch for ID %d — Groq says %d, computed %d from dates %s to %s. Using computed.',
                            $obituary->id, $result['age'], $computed_age, $result['date_of_birth'], $result['date_of_death']
                        ),
                        'warning'
                    );
                    $result['age'] = $computed_age;
                }
            } catch ( Exception $e ) {
                // Date parsing failed — keep Groq's age.
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
        $system = <<<'SYSTEM'
You are a professional obituary data extraction and rewriting system for a funeral home directory in Ontario, Canada.

You have TWO jobs:
1. EXTRACT structured facts from the original obituary text.
2. REWRITE the obituary into dignified, original prose.

Return a JSON object with EXACTLY these keys:
{
  "date_of_death": "YYYY-MM-DD or empty string if not found",
  "date_of_birth": "YYYY-MM-DD or empty string if not found",
  "age": integer or null if not found,
  "location": "City name or empty string if not found",
  "funeral_home": "Funeral home name or empty string if not found",
  "rewritten_text": "The rewritten obituary prose"
}

EXTRACTION RULES:
- Read the ORIGINAL OBITUARY TEXT carefully. Extract facts from THAT text, not from the SUGGESTIONS.
- The SUGGESTIONS block contains regex-extracted values that MAY BE WRONG. Use them only as hints.
  If the original text contradicts a suggestion, trust the original text.
- date_of_death: Look for phrases like "passed away on", "died on", "on [date]", date ranges, etc.
  Convert to YYYY-MM-DD. If only a year is found, return empty string.
- date_of_birth: Look for "born on", birth date ranges "(Month Day, Year - Month Day, Year)".
  Convert to YYYY-MM-DD. If only a year is found, return empty string.
- age: Look for "aged X", "age X", "at the age of X", "in his/her Nth year" (which means age N-1).
  Return an integer.
- location: The city/town where the person lived or died. NOT the funeral home's city.
  Only extract if explicitly stated in the text. Do NOT guess or default to any city.
- funeral_home: The funeral home handling arrangements. Only if explicitly mentioned.

REWRITING RULES:
1. PRESERVE every fact EXACTLY: full name, ALL dates, ALL locations, funeral home, age.
2. You MUST include the death date and age explicitly in your rewritten text.
3. Include the city/location ONLY if it appears in the original text. Do NOT invent one.
4. NEVER invent, assume, or fabricate ANY detail not in the original text:
   - No invented family members, occupations, hobbies, or personality traits.
   - If the original is sparse, keep the rewrite equally brief.
5. PREDECEASED FAMILY: A year in parentheses after a name (e.g., "wife of Frank (2020)")
   means that person DIED in that year. Write "predeceased by" — NEVER "survived by".
6. Write 2-4 short paragraphs. Third person. Plain prose only.
7. No headings, bullet points, or formatting.
8. No phrases like "according to the obituary" or "the original notice stated".
9. No condolence messages or calls-to-action.
10. If the original is very short, write a single brief sentence. Do NOT pad with invented details.

Return ONLY the JSON object. No markdown, no code fences, no commentary.
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

        $response = wp_remote_post( $this->api_url, array(
            'timeout' => $this->api_timeout,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ),
            'body' => $body,
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'api_error', 'HTTP request failed: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        // Handle rate limiting.
        if ( 429 === $code ) {
            return new WP_Error( 'rate_limited', 'Groq API rate limit exceeded.' );
        }

        // v4.6.7: Handle 401 Unauthorized — API key is invalid, expired, or revoked.
        if ( 401 === $code ) {
            $error_detail = '';
            $decoded = json_decode( $body, true );
            if ( ! empty( $decoded['error']['message'] ) ) {
                $error_detail = $decoded['error']['message'];
            }
            ontario_obituaries_log(
                sprintf( 'AI Rewriter: Groq API key is INVALID (HTTP 401). Detail: %s. Go to Settings → Ontario Obituaries → AI Rewrite Engine and enter a valid key from https://console.groq.com/keys', $error_detail ),
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
        // Groq returns 403 with type "permissions_error" when a model is blocked at
        // the organization or project level.
        if ( 403 === $code ) {
            $error_detail = '';
            $error_code   = '';
            $decoded = json_decode( $body, true );
            if ( ! empty( $decoded['error']['message'] ) ) {
                $error_detail = $decoded['error']['message'];
            }
            if ( ! empty( $decoded['error']['code'] ) ) {
                $error_code = $decoded['error']['code'];
            }
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

        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error(
                'api_error',
                sprintf( 'Groq API returned HTTP %d: %s', $code, wp_trim_words( $body, 30, '...' ) )
            );
        }

        $data = json_decode( $body, true );

        if ( empty( $data['choices'][0]['message']['content'] ) ) {
            return new WP_Error( 'empty_response', 'Groq API returned empty content.' );
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

        // ── 2. Name check (last name required, first name preferred) ──
        $name_parts = preg_split( '/\s+/', trim( $obituary->name ) );
        $last_name  = end( $name_parts );
        $last_name  = preg_replace( '/^(Jr|Sr|III|II|IV)\.?$/i', '', $last_name );
        $last_name  = trim( $last_name );

        if ( strlen( $last_name ) >= 3 && false === stripos( $rewritten, $last_name ) ) {
            $first_name = reset( $name_parts );
            $first_name = preg_replace( '/^(Mr|Mrs|Ms|Dr|Rev)\.?$/i', '', $first_name );
            $first_name = trim( $first_name );

            if ( strlen( $first_name ) >= 3 && false === stripos( $rewritten, $first_name ) ) {
                return new WP_Error(
                    'name_missing',
                    sprintf( 'Rewrite does not mention "%s" or "%s".', $last_name, $first_name )
                );
            }
        }

        // ── 3. Death date check — use Groq-extracted date if available ──
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
                    return new WP_Error( 'death_year_missing', sprintf( 'Rewrite does not mention death year %s.', $year ) );
                }
                if ( false === strpos( $rewritten, $day ) ) {
                    return new WP_Error( 'death_day_missing', sprintf( 'Rewrite does not mention death day %s.', $day ) );
                }
            }
        }

        // ── 4. Age check — use Groq-extracted age if available ──
        $check_age = ! empty( $extracted_data['age'] ) ? intval( $extracted_data['age'] ) : 0;
        if ( 0 === $check_age && ! empty( $obituary->age ) ) {
            $check_age = intval( $obituary->age );
        }
        if ( $check_age > 0 ) {
            $age_str = strval( $check_age );
            if ( false === strpos( $rewritten, $age_str ) ) {
                return new WP_Error( 'age_missing', sprintf( 'Rewrite does not mention age %s.', $age_str ) );
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
                return new WP_Error( 'location_missing', sprintf( 'Rewrite does not mention location "%s".', $city_check ) );
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
     * @return int Count of unrewritten obituaries.
     */
    public function get_pending_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        // v4.6.4 FIX: Query must EXACTLY match the process_batch() WHERE clause.
        // Previously this counted all status='pending' records, but process_batch()
        // also filters on (ai_description IS NULL OR ai_description = ''). Mismatch
        // caused the UI to show "X pending" while the batch processed 0 records.
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE status = 'pending'
               AND description IS NOT NULL
               AND description != ''
               AND (ai_description IS NULL OR ai_description = '')
               AND suppressed_at IS NULL"
        );
    }

    /**
     * Get rewrite statistics.
     *
     * v4.6.0: Now tracks pipeline status (pending → published).
     *
     * @return array { total: int, rewritten: int, pending: int, published: int, pct: float }
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

        $pct = $total > 0 ? round( ( $published / $total ) * 100, 1 ) : 0;

        return array(
            'total'            => $total,
            'rewritten'        => $rewritten,
            'published'        => $published,
            'pending'          => $pending_count,
            'pending_rewrite'  => $total - $rewritten,
            'pct'              => $pct,
            'percent_complete' => $pct,
        );
    }
}
