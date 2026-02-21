<?php
/**
 * AI Authenticity Checker v2
 *
 * v6.1.0: Structured issues format (type, severity, detail) for audit responses.
 * Updated banned terms list (kiddo, hubby, wifey, bestie). Restored pending==0
 * idle gate. Improved issue logging for structured format.
 *
 * v6.0.6: Blocker fixes — parse_audit_response() no longer defaults to PASS
 * on JSON parse failure (returns flagged/admin_review instead). Idle gate now
 * includes a 10-minute buffer before scheduled scrape/collection events and
 * respects collection cooldown transient. Rate limiter pre-check in
 * check_idle_gates() replaced with non-reserving peek_budget(). apply_corrections()
 * uses %d for age field. New setting authenticity_auto_correct_enabled (default OFF)
 * gates auto-corrections.
 *
 * v6.0.5: (Reverted in v6.1.0) Continuous publishing — idle gate was relaxed
 * from "pending == 0" to "rewriter not actively running + rate limiter OK".
 * v6.1.0 restored the strict "pending == 0" requirement per spec.
 *
 * v6.0.4: Complete redesign. The checker is now the ONLY component that
 * can publish obituaries. Flow:
 *   SCRAPE → REWRITE (pending + needs_audit) → AUDIT → PUBLISH (or requeue)
 *
 * Key changes from v1 (4.3.0):
 *   - Batch size 1 (not 10): processes one at a time, deterministically.
 *   - Idle gate: runs when rewriter is not actively running (v6.1.0: restored
 *     strict pending == 0 requirement — overrides v6.0.5 relaxation).
 *   - Cross-reference: compares ORIGINAL text vs AI rewrite, not just fields.
 *   - Publish gating: PASS → status='published'; FLAGGED → requeue for rewrite.
 *   - Hash-based no-churn: skips re-audit when ai_description_hash == last_audited_hash.
 *   - Requeue limit: max 2 requeues per obituary, then admin_review hold.
 *   - Error codes: DB_AUDIT_REQUEUE_FAIL, DB_AUDIT_FLAG_FAIL, AUDIT_REQUEUE_LIMIT.
 *
 * Architecture:
 *   - Uses Groq's OpenAI-compatible chat completion API (same key as AI Rewriter).
 *   - Selects obituaries with audit_status='needs_audit' deterministically (newest first).
 *   - LLM compares original description + AI rewrite for fact fidelity.
 *   - Rate-limited via shared Ontario_Obituaries_Groq_Rate_Limiter (800 tokens/call).
 *
 * @package Ontario_Obituaries
 * @since   4.3.0
 * @since   6.0.4  Complete v2 redesign — publish gating, cross-reference, requeue logic.
 * @since   6.0.5  Continuous publishing — idle gate relaxed for rebuild scenarios.
 * @since   6.0.6  Blocker fixes — parse failure safety, scrape buffer, rate limiter peek, auto-correct gate.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ontario_Obituaries_Authenticity_Checker {

    /** @var string Groq API endpoint. */
    private $api_url = 'https://api.groq.com/openai/v1/chat/completions';

    /** @var string Primary model — 70B for better factual comparison. */
    private $model = 'llama-3.3-70b-versatile';

    /** @var int v6.0.4: Process ONE obituary at a time. */
    private $batch_size = 1;

    /** @var int Delay between requests in microseconds (12 seconds — share Groq budget). */
    private $request_delay = 12000000;

    /** @var int API timeout. */
    private $api_timeout = 45;

    /** @var string|null API key (shared with AI Rewriter). */
    private $api_key = null;

    /** @var int v6.0.4: Maximum requeue attempts before admin_review hold. */
    private $max_requeues = 2;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->api_key = get_option( 'ontario_obituaries_groq_api_key', '' );
    }

    /**
     * Check if the checker is configured.
     *
     * @return bool
     */
    public function is_configured() {
        return ! empty( $this->api_key );
    }

    /**
     * v6.1.0: Check if the checker should run (idle gate).
     *
     * The checker runs ONLY when the system is fully idle:
     *   1. API key is configured.
     *   2. Pending rewrites == 0 (hard requirement — restored in v6.1.0).
     *   3. No active rewriter lock (rewriter is not mid-batch).
     *   4. No active scraper/collector lock (collection cooldown transient).
     *   5. No scrape/collection imminent (10-minute buffer via wp_next_scheduled).
     *   6. Groq rate limiter allows 800 tokens (non-reserving peek).
     *
     * v6.1.0 CHANGE: Restored "pending rewrites == 0" as a hard gate.
     * The v6.0.5 relaxation (continuous publishing) is reverted because
     * the spec requires strict idle-only operation: audit must not compete
     * with rewrites for Groq budget or DB writes.
     *
     * @return true|WP_Error True if idle and ready, WP_Error with reason if not.
     */
    public function check_idle_gates() {
        if ( ! $this->is_configured() ) {
            return new WP_Error( 'not_configured', 'Groq API key not configured.' );
        }

        // Gate 1 (v6.1.0 RESTORED): Pending rewrites must be 0.
        // Audit must not run while the rewrite queue has work to do.
        // This is a hard requirement from the pipeline spec.
        if ( class_exists( 'Ontario_Obituaries_AI_Rewriter' ) ) {
            $rewriter = new Ontario_Obituaries_AI_Rewriter();
            $pending  = $rewriter->get_pending_count();
            if ( $pending > 0 ) {
                return new WP_Error(
                    'pending_rewrites',
                    sprintf( 'AUDIT_IDLE_SKIP_PENDING: %d pending rewrites — audit deferred until rewrite queue is empty.', $pending )
                );
            }
        }

        // Gate 2: No active rewriter lock (rewriter is not mid-API-call).
        if ( get_transient( 'ontario_obituaries_rewriter_running' ) ) {
            return new WP_Error( 'rewriter_locked', 'Rewriter is currently running — deferring audit.' );
        }

        // Gate 3: No active scraper/collector lock.
        if ( get_transient( 'ontario_obituaries_collection_cooldown' ) ) {
            return new WP_Error( 'scrape_active', 'Collection cooldown active — scraping recently ran, deferring audit.' );
        }

        // Gate 4: Not within 10 minutes of next scheduled scrape/collect event.
        $scrape_buffer_seconds = 600; // 10 minutes.
        $scrape_hooks = array(
            'ontario_obituaries_collection_event',
            'ontario_obituaries_initial_collection',
        );
        foreach ( $scrape_hooks as $hook ) {
            $next_scheduled = wp_next_scheduled( $hook );
            if ( $next_scheduled && ( $next_scheduled - time() ) <= $scrape_buffer_seconds && ( $next_scheduled - time() ) > 0 ) {
                return new WP_Error(
                    'scrape_imminent',
                    sprintf(
                        'Scrape/collection event "%s" scheduled in %d seconds (within %d-second buffer) — deferring audit.',
                        $hook,
                        $next_scheduled - time(),
                        $scrape_buffer_seconds
                    )
                );
            }
        }

        // Gate 5: Rate limiter check — non-reserving peek.
        if ( class_exists( 'Ontario_Obituaries_Groq_Rate_Limiter' ) ) {
            $limiter = Ontario_Obituaries_Groq_Rate_Limiter::get_instance();
            if ( method_exists( $limiter, 'peek_budget' ) ) {
                if ( ! $limiter->peek_budget( 800, 'authenticity' ) ) {
                    return new WP_Error( 'rate_limited', 'Groq TPM budget exhausted — deferring.' );
                }
            } else {
                $reserved = $limiter->may_proceed( 800, 'authenticity' );
                if ( ! $reserved ) {
                    return new WP_Error( 'rate_limited', 'Groq TPM budget exhausted — deferring.' );
                }
                $limiter->release_reservation( 800, 'authenticity' );
            }
        }

        return true;
    }

    /**
     * v6.0.4: Run audit batch — processes ONE obituary at a time.
     *
     * Deterministic selection order:
     *   1. Newest needs_audit (just rewritten, never audited).
     *   2. Changed hash (rewritten again, hash differs from last audit).
     *   3. Oldest audited (re-check, for legacy 4-hour schedule).
     *
     * @return array { audited: int, passed: int, flagged: int, published: int, requeued: int, errors: string[] }
     */
    public function run_audit_batch() {
        if ( ! $this->is_configured() ) {
            return array(
                'audited'   => 0,
                'passed'    => 0,
                'flagged'   => 0,
                'published' => 0,
                'requeued'  => 0,
                'errors'    => array( 'Groq API key not configured.' ),
            );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        // v6.0.4: Deterministic selection — newest needs_audit first.
        // Also picks up records where ai_description_hash != last_audited_hash (re-rewritten).
        $obituaries = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, date_of_birth, date_of_death, age, funeral_home,
                    location, city_normalized, description, ai_description, source_url,
                    ai_description_hash, last_audited_hash, audit_status, audit_requeue_count
             FROM `{$table}`
             WHERE suppressed_at IS NULL
               AND ai_description IS NOT NULL AND ai_description != ''
               AND description IS NOT NULL AND description != ''
               AND (
                   audit_status = 'needs_audit'
                   OR (
                       audit_status IN ('pass', 'flagged')
                       AND ai_description_hash IS NOT NULL
                       AND last_audited_hash IS NOT NULL
                       AND ai_description_hash != last_audited_hash
                   )
               )
             ORDER BY
               CASE WHEN audit_status = 'needs_audit' THEN 0 ELSE 1 END ASC,
               id DESC
             LIMIT %d",
            $this->batch_size
        ) );

        // Fallback for legacy 4-hour schedule: if no needs_audit, pick oldest published for re-check.
        if ( empty( $obituaries ) ) {
            $obituaries = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, name, date_of_birth, date_of_death, age, funeral_home,
                        location, city_normalized, description, ai_description, source_url,
                        ai_description_hash, last_audited_hash, audit_status, audit_requeue_count
                 FROM `{$table}`
                 WHERE suppressed_at IS NULL
                   AND status = 'published'
                   AND ai_description IS NOT NULL AND ai_description != ''
                   AND description IS NOT NULL AND description != ''
                   AND (last_audit_at IS NULL OR last_audit_at < DATE_SUB(NOW(), INTERVAL 7 DAY))
                   AND audit_status NOT IN ('admin_review', 'needs_audit')
                 ORDER BY last_audit_at ASC, id ASC
                 LIMIT %d",
                $this->batch_size
            ) );
        }

        $result = array(
            'audited'   => 0,
            'passed'    => 0,
            'flagged'   => 0,
            'published' => 0,
            'requeued'  => 0,
            'errors'    => array(),
        );

        if ( empty( $obituaries ) ) {
            ontario_obituaries_log( 'Authenticity Checker v2: No obituaries need audit.', 'info' );
            return $result;
        }

        foreach ( $obituaries as $obit ) {
            $result['audited']++;

            if ( $result['audited'] > 1 ) {
                usleep( $this->request_delay );
            }

            // v6.0.4: Hash-based no-churn — skip if hash matches and already audited.
            if ( ! empty( $obit->ai_description_hash )
                 && ! empty( $obit->last_audited_hash )
                 && $obit->ai_description_hash === $obit->last_audited_hash
                 && 'pass' === $obit->audit_status ) {
                ontario_obituaries_log(
                    sprintf( 'Authenticity Checker v2: ID %d hash unchanged — skipping re-audit.', $obit->id ),
                    'info'
                );
                $result['passed']++;
                continue;
            }

            $audit = $this->audit_obituary( $obit );

            if ( is_wp_error( $audit ) ) {
                $result['errors'][] = sprintf(
                    'ID %d (%s): %s',
                    $obit->id, $obit->name, $audit->get_error_message()
                );

                if ( 'rate_limited' === $audit->get_error_code() ) {
                    ontario_obituaries_log( 'Authenticity Checker v2: Rate limited — stopping batch.', 'warning' );
                    break;
                }
                continue;
            }

            // v6.0.4: Process audit result with publish gating.
            $this->process_audit_result( $obit, $audit, $table, $result );
        }

        ontario_obituaries_log(
            sprintf(
                'Authenticity Checker v2: Batch complete — %d audited, %d passed (%d published), %d flagged (%d requeued), %d errors.',
                $result['audited'], $result['passed'], $result['published'],
                $result['flagged'], $result['requeued'], count( $result['errors'] )
            ),
            'info'
        );

        return $result;
    }

    /**
     * v6.0.4: Process a single audit result — publish, requeue, or hold.
     *
     * @param object $obit   Obituary DB row.
     * @param array  $audit  Parsed audit response.
     * @param string $table  Table name.
     * @param array  &$result Batch result counters (passed by reference).
     */
    private function process_audit_result( $obit, $audit, $table, &$result ) {
        global $wpdb;

        $current_hash = ! empty( $obit->ai_description_hash )
            ? $obit->ai_description_hash
            : ( ! empty( $obit->ai_description ) ? hash( 'sha256', $obit->ai_description ) : null );

        if ( 'pass' === $audit['status'] ) {
            // ── PUBLISH: audit passed — set status='published'. ──
            $update_data = array(
                'status'            => 'published',
                'audit_status'      => 'pass',
                'audit_flags'       => null,
                'last_audit_at'     => current_time( 'mysql', true ),
                'last_audited_hash' => $current_hash,
            );

            // Apply high-confidence field corrections if any.
            if ( ! empty( $audit['corrections'] ) ) {
                foreach ( $audit['corrections'] as $field => $value ) {
                    $update_data[ $field ] = $value;
                }
            }

            // Handle NULL audit_flags — wpdb::update cannot set NULL.
            $has_null_flags = true;
            unset( $update_data['audit_flags'] );

            $formats = array();
            foreach ( $update_data as $col => $val ) {
                $formats[] = ( 'age' === $col ) ? '%d' : '%s';
            }

            $upd = $wpdb->update( $table, $update_data, array( 'id' => $obit->id ), $formats, array( '%d' ) );

            if ( function_exists( 'oo_db_check' ) ) {
                oo_db_check( 'AUDIT', $upd, 'DB_AUDIT_PUBLISH_FAIL', 'Failed to publish after audit pass', array( 'obit_id' => $obit->id ) );
            }

            // Set audit_flags to NULL via raw query.
            if ( $has_null_flags ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE `{$table}` SET audit_flags = NULL WHERE id = %d",
                    $obit->id
                ) );
            }

            $result['passed']++;
            $result['published']++;

            ontario_obituaries_log(
                sprintf( 'Authenticity Checker v2: PUBLISHED ID %d (%s) — audit passed.', $obit->id, $obit->name ),
                'info'
            );

        } else {
            // ── FLAGGED: audit found issues. ──
            $result['flagged']++;

            $recommendation = isset( $audit['recommendation'] ) ? $audit['recommendation'] : 'requeue';
            $requeue_count  = isset( $obit->audit_requeue_count ) ? intval( $obit->audit_requeue_count ) : 0;

            // v6.1.0: Format structured issues for log display.
            $issue_summaries = array();
            foreach ( $audit['issues'] as $iss ) {
                if ( is_array( $iss ) && isset( $iss['detail'] ) ) {
                    $issue_summaries[] = sprintf( '[%s/%s] %s', isset( $iss['type'] ) ? $iss['type'] : '?', isset( $iss['severity'] ) ? $iss['severity'] : '?', $iss['detail'] );
                } elseif ( is_string( $iss ) ) {
                    $issue_summaries[] = $iss;
                }
            }

            ontario_obituaries_log(
                sprintf(
                    'Authenticity Checker v2: FLAGGED ID %d (%s) — %s (requeue count: %d/%d)',
                    $obit->id, $obit->name,
                    implode( '; ', $issue_summaries ),
                    $requeue_count, $this->max_requeues
                ),
                'warning'
            );

            if ( 'requeue' === $recommendation && $requeue_count < $this->max_requeues ) {
                // ── REQUEUE for rewrite: clear AI text, set pending, increment counter. ──
                $new_count = $requeue_count + 1;

                // v6.0.5 BUG FIX: Removed ai_description_hash from update data
                // (NULL via raw query below). Fixed format: audit_requeue_count
                // must use %d, not %s.
                $upd = $wpdb->update(
                    $table,
                    array(
                        'status'               => 'pending',
                        'ai_description'       => '',
                        'audit_status'         => 'flagged',
                        'audit_flags'          => wp_json_encode( $audit['issues'] ),
                        'last_audit_at'        => current_time( 'mysql', true ),
                        'last_audited_hash'    => $current_hash,
                        'audit_requeue_count'  => $new_count,
                        'rewrite_requested_at' => current_time( 'mysql', true ),
                        'rewrite_request_reason' => 'audit_flagged: ' . implode( '; ', array_map( function( $i ) {
                            return is_array( $i ) && isset( $i['detail'] ) ? $i['detail'] : ( is_string( $i ) ? $i : '' );
                        }, array_slice( $audit['issues'], 0, 2 ) ) ),
                    ),
                    array( 'id' => $obit->id ),
                    array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ),
                    array( '%d' )
                );

                // Handle NULL ai_description_hash via raw query.
                $wpdb->query( $wpdb->prepare(
                    "UPDATE `{$table}` SET ai_description_hash = NULL WHERE id = %d",
                    $obit->id
                ) );

                if ( function_exists( 'oo_db_check' ) ) {
                    oo_db_check( 'AUDIT', $upd, 'DB_AUDIT_REQUEUE_FAIL', 'Failed to requeue flagged obituary', array( 'obit_id' => $obit->id ) );
                }

                $result['requeued']++;

                ontario_obituaries_log(
                    sprintf(
                        'Authenticity Checker v2: REQUEUED ID %d (%s) for rewrite (attempt %d/%d).',
                        $obit->id, $obit->name, $new_count, $this->max_requeues
                    ),
                    'info'
                );

                // Apply high-confidence field corrections even on requeue.
                if ( ! empty( $audit['corrections'] ) ) {
                    $this->apply_corrections( $obit->id, $audit['corrections'], $table );
                }

            } else {
                // ── ADMIN REVIEW HOLD: max requeues exceeded or recommendation is admin_review. ──
                $hold_status = ( $requeue_count >= $this->max_requeues ) ? 'admin_review' : $recommendation;

                $upd = $wpdb->update(
                    $table,
                    array(
                        'audit_status'      => $hold_status,
                        'audit_flags'       => wp_json_encode( $audit['issues'] ),
                        'last_audit_at'     => current_time( 'mysql', true ),
                        'last_audited_hash' => $current_hash,
                    ),
                    array( 'id' => $obit->id ),
                    array( '%s', '%s', '%s', '%s' ),
                    array( '%d' )
                );

                if ( function_exists( 'oo_db_check' ) ) {
                    oo_db_check( 'AUDIT', $upd, 'DB_AUDIT_FLAG_FAIL', 'Failed to flag obituary for admin review', array( 'obit_id' => $obit->id ) );
                }

                ontario_obituaries_log(
                    sprintf(
                        'Authenticity Checker v2: HELD ID %d (%s) for admin review — %d requeues exhausted.',
                        $obit->id, $obit->name, $requeue_count
                    ),
                    'warning'
                );

                // Apply high-confidence field corrections.
                if ( ! empty( $audit['corrections'] ) ) {
                    $this->apply_corrections( $obit->id, $audit['corrections'], $table );
                }
            }
        }
    }

    /**
     * Audit a single obituary using the LLM.
     *
     * v6.0.4: Cross-references original text with AI rewrite.
     *
     * @param object $obit Obituary database row.
     * @return array|WP_Error { status: 'pass'|'flagged', issues: string[], corrections: array, recommendation: string }
     */
    private function audit_obituary( $obit ) {
        // Check shared Groq rate limiter before API call.
        $auth_estimate   = 800;
        $has_reservation = false;
        $auth_limiter    = null;

        if ( class_exists( 'Ontario_Obituaries_Groq_Rate_Limiter' ) ) {
            $auth_limiter = Ontario_Obituaries_Groq_Rate_Limiter::get_instance();
            if ( ! $auth_limiter->may_proceed( $auth_estimate, 'authenticity' ) ) {
                return new WP_Error(
                    'rate_limited',
                    'Shared Groq rate limiter: TPM budget exhausted. Deferring.',
                    array( 'seconds_until_reset' => $auth_limiter->seconds_until_reset() )
                );
            }
            $has_reservation = true;
        }

        $prompt = $this->build_audit_prompt( $obit );

        $response = $this->call_api( $prompt );

        if ( is_wp_error( $response ) ) {
            if ( $has_reservation && $auth_limiter ) {
                $auth_limiter->release_reservation( $auth_estimate, 'authenticity' );
            }
            return $response;
        }

        return $this->parse_audit_response( $response, $obit );
    }

    /**
     * v6.0.4: Build the cross-reference audit prompt.
     *
     * Compares ORIGINAL text against AI REWRITE + structured fields.
     * The LLM acts as a verifier, not a rewriter.
     *
     * @param object $obit Obituary record.
     * @return array Chat messages.
     */
    private function build_audit_prompt( $obit ) {
        $system = <<<'SYSTEM'
You are a strict fact-checking auditor for an obituary database. You compare the ORIGINAL obituary text against the AI-REWRITTEN version to ensure no facts were lost, fabricated, or distorted.

CHECK THESE POINTS (in order of severity):

1. FACT PRESERVATION: Every name, date, age, location, funeral home, survivor, and predeceased person in the ORIGINAL must appear in the REWRITE. Missing facts = FLAGGED.

2. NO FABRICATION: The REWRITE must NOT contain names, dates, relationships, hobbies, traits, or details NOT present in the ORIGINAL. Invented content = FLAGGED.

3. FIELD ACCURACY: The structured fields (date_of_death, date_of_birth, age, location, funeral_home) must match what the ORIGINAL text states. Mismatches = FLAGGED.

4. AGE/DATE CONSISTENCY: If age AND birth/death dates are all present, verify age = death_year - birth_year (±1). Age 0 or age >120 = FLAGGED.

5. TONE: The REWRITE should be dignified, professional, matter-of-fact. No slang ("doggo", "fur babies", "pupper", "kiddo", "hubby", "wifey", "bestie"), no AI cliches ("indelible mark", "tapestry of life", "beacon of light", "heart of gold"), no "age of 0". Tone violations = FLAGGED.

6. CONTENT QUALITY: The REWRITE must be actual obituary prose (not placeholder, lorem ipsum, or truncated text).

Respond ONLY with valid JSON:
{
  "status": "pass" or "flagged",
  "issues": [
    { "type": "missing_fact|fabrication|field_mismatch|tone|age_error|quality", "severity": "critical|warning|info", "detail": "description" }
  ],
  "missing_facts": ["facts from ORIGINAL missing in REWRITE"],
  "fabricated_facts": ["facts in REWRITE not in ORIGINAL"],
  "field_corrections": { "field_name": "correct_value" },
  "confidence": 0.0 to 1.0,
  "recommendation": "pass" or "requeue" or "admin_review"
}

ISSUE TYPES (use exactly these strings):
- "missing_fact": A name, date, age, location, or other fact from the ORIGINAL is absent in the REWRITE.
- "fabrication": The REWRITE contains details not present in the ORIGINAL.
- "field_mismatch": A structured field (date, age, location, funeral_home) does not match the ORIGINAL.
- "tone": Banned slang, AI cliche, or undignified language detected.
- "age_error": Age is 0, >120, or inconsistent with birth/death dates.
- "quality": Placeholder text, truncation, or other content quality problem.

SEVERITY LEVELS:
- "critical": Hard failure — must be requeued or sent to admin review. (fabrication, missing key facts)
- "warning": Likely needs requeue but could be acceptable. (tone issues, minor missing facts)
- "info": Noted but not grounds for flagging alone. (stylistic preferences)

RULES:
- "pass" means the REWRITE faithfully represents the ORIGINAL with no issues.
- "requeue" means the REWRITE has fixable issues (missing facts, tone problems) — it should be rewritten.
- "admin_review" means the issues are serious or ambiguous — a human should decide.
- Only include "field_corrections" for structured fields you are >0.9 confident are wrong.
- Do NOT flag missing birth dates or ages when the ORIGINAL also lacks them.
- Be strict about fabrication — any invented detail is a hard flag.
SYSTEM;

        $record = array();
        $record[] = '=== STRUCTURED FIELDS ===';
        $record[] = 'Name: ' . $obit->name;
        $record[] = 'Date of Birth: ' . ( ! empty( $obit->date_of_birth ) && '0000-00-00' !== $obit->date_of_birth ? $obit->date_of_birth : 'Not provided' );
        $record[] = 'Date of Death: ' . ( ! empty( $obit->date_of_death ) && '0000-00-00' !== $obit->date_of_death ? $obit->date_of_death : 'Not provided' );
        $record[] = 'Listed Age: ' . ( ! empty( $obit->age ) && intval( $obit->age ) > 0 ? intval( $obit->age ) : 'Not provided' );
        $record[] = 'Location: ' . ( ! empty( $obit->location ) ? $obit->location : 'Not provided' );
        $record[] = 'City (normalized): ' . ( ! empty( $obit->city_normalized ) ? $obit->city_normalized : 'Not provided' );
        $record[] = 'Funeral Home: ' . ( ! empty( $obit->funeral_home ) ? $obit->funeral_home : 'Not provided' );

        $record[] = '';
        $record[] = '=== ORIGINAL OBITUARY TEXT (from source) ===';
        $record[] = substr( $obit->description, 0, 3000 );

        $record[] = '';
        $record[] = '=== AI-REWRITTEN VERSION (to verify) ===';
        $record[] = ! empty( $obit->ai_description ) ? substr( $obit->ai_description, 0, 3000 ) : '[NO AI REWRITE AVAILABLE]';

        if ( ! empty( $obit->source_url ) ) {
            $record[] = '';
            $record[] = 'Source URL: ' . $obit->source_url;
        }

        $user_message = implode( "\n", $record );

        return array(
            array( 'role' => 'system', 'content' => $system ),
            array( 'role' => 'user',   'content' => $user_message ),
        );
    }

    /**
     * v6.0.6: Parse the LLM's audit response with v2 fields.
     *
     * v6.0.6 BLOCKER 1 FIX: On JSON parse failure, previously defaulted to
     * 'pass' — allowing unaudited publishing. Now returns 'flagged' with
     * recommendation 'admin_review', ensuring the record is held for human
     * review rather than silently published without a valid audit.
     *
     * @param string $response Raw LLM response text.
     * @param object $obit     Original obituary record.
     * @return array { status, issues, missing_facts, fabricated_facts, corrections, confidence, recommendation }
     */
    private function parse_audit_response( $response, $obit ) {
        $json = $response;

        // Handle markdown code blocks.
        if ( preg_match( '/```(?:json)?\s*([\s\S]*?)```/', $response, $m ) ) {
            $json = trim( $m[1] );
        }

        $data = json_decode( $json, true );

        if ( empty( $data ) || ! isset( $data['status'] ) ) {
            ontario_obituaries_log(
                sprintf( 'Authenticity Checker v2: Failed to parse LLM response for ID %d. Raw: %s', $obit->id, substr( $response, 0, 200 ) ),
                'warning'
            );
            // v6.0.6 BLOCKER 1 FIX: On parse failure, return flagged + admin_review.
            // Previously defaulted to 'pass' which allowed unaudited publishing.
            // Now the record is held for admin review — fail-safe, not fail-open.
            return array(
                'status'           => 'flagged',
                'issues'           => array( array(
                    'type'     => 'quality',
                    'severity' => 'critical',
                    'detail'   => 'LLM audit response could not be parsed — held for admin review.',
                ) ),
                'missing_facts'    => array(),
                'fabricated_facts' => array(),
                'corrections'      => array(),
                'confidence'       => 0,
                'recommendation'   => 'admin_review',
            );
        }

        // Sanitize the response.
        $status           = ( 'flagged' === $data['status'] ) ? 'flagged' : 'pass';
        $issues           = isset( $data['issues'] ) && is_array( $data['issues'] ) ? $data['issues'] : array();
        $missing_facts    = isset( $data['missing_facts'] ) && is_array( $data['missing_facts'] ) ? $data['missing_facts'] : array();
        $fabricated_facts = isset( $data['fabricated_facts'] ) && is_array( $data['fabricated_facts'] ) ? $data['fabricated_facts'] : array();
        $corrections      = isset( $data['field_corrections'] ) && is_array( $data['field_corrections'] ) ? $data['field_corrections'] : array();
        $confidence       = isset( $data['confidence'] ) ? floatval( $data['confidence'] ) : 0;
        $recommendation   = isset( $data['recommendation'] ) ? sanitize_text_field( $data['recommendation'] ) : '';

        // v6.1.0: Normalize issues to structured format (type, severity, detail).
        // Handles both new structured array-of-objects and legacy string arrays.
        $structured_issues = array();
        foreach ( $issues as $issue ) {
            if ( is_array( $issue ) && isset( $issue['type'] ) && isset( $issue['detail'] ) ) {
                // Already structured — validate and keep.
                $allowed_types      = array( 'missing_fact', 'fabrication', 'field_mismatch', 'tone', 'age_error', 'quality' );
                $allowed_severities = array( 'critical', 'warning', 'info' );
                $structured_issues[] = array(
                    'type'     => in_array( $issue['type'], $allowed_types, true ) ? $issue['type'] : 'quality',
                    'severity' => isset( $issue['severity'] ) && in_array( $issue['severity'], $allowed_severities, true ) ? $issue['severity'] : 'warning',
                    'detail'   => sanitize_text_field( substr( $issue['detail'], 0, 500 ) ),
                );
            } elseif ( is_string( $issue ) ) {
                // Legacy string — classify based on keywords.
                $type     = 'quality';
                $severity = 'warning';
                $lower    = strtolower( $issue );
                if ( false !== strpos( $lower, 'missing' ) || false !== strpos( $lower, 'absent' ) ) {
                    $type = 'missing_fact';
                } elseif ( false !== strpos( $lower, 'fabricat' ) || false !== strpos( $lower, 'invent' ) || false !== strpos( $lower, 'not in original' ) ) {
                    $type     = 'fabrication';
                    $severity = 'critical';
                } elseif ( false !== strpos( $lower, 'mismatch' ) || false !== strpos( $lower, 'incorrect' ) ) {
                    $type = 'field_mismatch';
                } elseif ( false !== strpos( $lower, 'tone' ) || false !== strpos( $lower, 'slang' ) || false !== strpos( $lower, 'cliche' ) ) {
                    $type = 'tone';
                } elseif ( false !== strpos( $lower, 'age' ) ) {
                    $type = 'age_error';
                }
                $structured_issues[] = array(
                    'type'     => $type,
                    'severity' => $severity,
                    'detail'   => sanitize_text_field( substr( $issue, 0, 500 ) ),
                );
            }
        }
        $issues = $structured_issues;

        // Validate recommendation.
        if ( ! in_array( $recommendation, array( 'pass', 'requeue', 'admin_review' ), true ) ) {
            $recommendation = ( 'pass' === $status ) ? 'pass' : 'requeue';
        }

        // Only apply corrections with high confidence.
        if ( $confidence < 0.9 ) {
            $corrections = array();
        }

        // Whitelist of fields that can be auto-corrected.
        $allowed_fields = array( 'date_of_death', 'date_of_birth', 'age', 'city_normalized' );
        $corrections    = array_intersect_key( $corrections, array_flip( $allowed_fields ) );

        // Validate correction values.
        foreach ( $corrections as $field => $value ) {
            if ( 'date_of_death' === $field || 'date_of_birth' === $field ) {
                if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
                    unset( $corrections[ $field ] );
                }
            }
            if ( 'age' === $field ) {
                $age_val = intval( $value );
                if ( $age_val < 1 || $age_val > 120 ) {
                    unset( $corrections[ $field ] );
                }
            }
        }

        // Merge all issue sources for audit_flags storage.
        // v6.1.0: Structured issues — append missing_facts and fabricated_facts as typed entries.
        $all_issues = $issues;
        if ( ! empty( $missing_facts ) ) {
            $all_issues[] = array(
                'type'     => 'missing_fact',
                'severity' => 'warning',
                'detail'   => 'Missing facts: ' . sanitize_text_field( implode( ', ', array_slice( $missing_facts, 0, 3 ) ) ),
            );
        }
        if ( ! empty( $fabricated_facts ) ) {
            $all_issues[] = array(
                'type'     => 'fabrication',
                'severity' => 'critical',
                'detail'   => 'Fabricated: ' . sanitize_text_field( implode( ', ', array_slice( $fabricated_facts, 0, 3 ) ) ),
            );
        }

        return array(
            'status'           => $status,
            'issues'           => array_slice( $all_issues, 0, 10 ),
            'missing_facts'    => array_map( 'sanitize_text_field', array_slice( $missing_facts, 0, 5 ) ),
            'fabricated_facts' => array_map( 'sanitize_text_field', array_slice( $fabricated_facts, 0, 5 ) ),
            'corrections'      => $corrections,
            'confidence'       => $confidence,
            'recommendation'   => $recommendation,
        );
    }

    /**
     * Apply auto-corrections from a high-confidence audit.
     *
     * v6.0.6 BLOCKER 4 FIX:
     *   1. Uses %d format for the 'age' field (was %s — wrong type for INT column).
     *   2. Gated behind 'authenticity_auto_correct_enabled' setting (default OFF).
     *      When disabled, corrections are logged but not applied.
     *
     * @param int    $obit_id     Obituary ID.
     * @param array  $corrections Field => value corrections.
     * @param string $table       Table name.
     */
    private function apply_corrections( $obit_id, $corrections, $table ) {
        if ( empty( $corrections ) ) {
            return;
        }

        // v6.0.6 BLOCKER 4: Check the auto-correct setting (default OFF).
        $settings = function_exists( 'ontario_obituaries_get_settings' )
            ? ontario_obituaries_get_settings()
            : get_option( 'ontario_obituaries_settings', array() );
        if ( empty( $settings['authenticity_auto_correct_enabled'] ) ) {
            ontario_obituaries_log(
                sprintf(
                    'Authenticity Checker v2: Auto-correct DISABLED — skipping %d correction(s) for ID %d. Enable via Settings > authenticity_auto_correct_enabled.',
                    count( $corrections ), $obit_id
                ),
                'info'
            );
            return;
        }

        global $wpdb;

        // v6.0.5: Defence-in-depth whitelist — parse_audit_response() already
        // filters, but validate here too in case of future code paths.
        $allowed_fields = array( 'date_of_death', 'date_of_birth', 'age', 'city_normalized' );

        foreach ( $corrections as $field => $value ) {
            if ( ! in_array( $field, $allowed_fields, true ) ) {
                continue; // Skip unknown fields — prevents SQL injection.
            }

            $old_value = $wpdb->get_var( $wpdb->prepare(
                "SELECT `{$field}` FROM `{$table}` WHERE id = %d",
                $obit_id
            ) );

            if ( (string) $old_value !== (string) $value ) {
                // v6.0.6 BLOCKER 4 FIX: Use %d for age field (INT column), %s for others.
                $field_format = ( 'age' === $field ) ? '%d' : '%s';

                $corr_upd = $wpdb->update(
                    $table,
                    array( $field => $value ),
                    array( 'id' => $obit_id ),
                    array( $field_format ),
                    array( '%d' )
                );
                if ( function_exists( 'oo_db_check' ) ) {
                    oo_db_check( 'AUDIT', $corr_upd, 'DB_CORRECTION_FAIL', 'Failed to apply auto-correction', array(
                        'obit_id' => $obit_id,
                        'field'   => $field,
                    ) );
                }

                ontario_obituaries_log(
                    sprintf(
                        'Authenticity Checker v2: AUTO-CORRECTED ID %d — %s: "%s" → "%s".',
                        $obit_id, $field, $old_value, $value
                    ),
                    'info'
                );
            }
        }
    }

    /**
     * Call the Groq API.
     *
     * @param array  $messages Chat messages.
     * @param string $model    Optional model override.
     * @return string|WP_Error Generated text or error.
     */
    private function call_api( $messages, $model = '' ) {
        if ( empty( $model ) ) {
            $model = $this->model;
        }

        $body = wp_json_encode( array(
            'model'           => $model,
            'messages'        => $messages,
            'temperature'     => 0.1, // v6.0.4: Lower temp for strict factual checking.
            'max_tokens'      => 800, // v6.0.4: Increased for v2 response format.
            'top_p'           => 0.9,
            'response_format' => array( 'type' => 'json_object' ),
        ) );

        $response = oo_safe_http_post( 'AUDIT', $this->api_url, array(
            'timeout' => $this->api_timeout,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ),
            'body' => $body,
        ), array( 'source' => 'class-ai-authenticity-checker', 'model' => $model ) );

        if ( is_wp_error( $response ) ) {
            $http_status = oo_http_error_status( $response );
            if ( 429 === $http_status ) {
                return new WP_Error( 'rate_limited', 'Groq API rate limit exceeded.' );
            }
            if ( 401 === $http_status ) {
                return new WP_Error( 'api_key_invalid', 'Groq API key is invalid or expired.' );
            }
            return new WP_Error( 'api_error', 'HTTP request failed: ' . $response->get_error_message() );
        }

        $body_text = wp_remote_retrieve_body( $response );
        $data      = json_decode( $body_text, true );

        if ( empty( $data['choices'][0]['message']['content'] ) ) {
            return new WP_Error( 'empty_response', 'Groq API returned empty content.' );
        }

        // Record actual token usage in shared rate limiter.
        if ( class_exists( 'Ontario_Obituaries_Groq_Rate_Limiter' ) ) {
            $actual = ! empty( $data['usage']['total_tokens'] )
                ? (int) $data['usage']['total_tokens']
                : 800;
            Ontario_Obituaries_Groq_Rate_Limiter::get_instance()->record_usage(
                $actual,
                'authenticity',
                800
            );
        }

        return trim( $data['choices'][0]['message']['content'] );
    }

    /**
     * v6.0.4: Get count of obituaries needing audit.
     *
     * @return int
     */
    public function get_needs_audit_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE audit_status = 'needs_audit'
               AND ai_description IS NOT NULL AND ai_description != ''
               AND suppressed_at IS NULL"
        );
    }

    /**
     * Get audit statistics.
     *
     * v6.0.4: Updated to include pipeline-aware counts.
     *
     * @return array
     */
    public function get_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        $total_published = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}` WHERE suppressed_at IS NULL AND status = 'published'"
        );

        $needs_audit = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}` WHERE audit_status = 'needs_audit' AND suppressed_at IS NULL"
        );

        $audited = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}` WHERE last_audit_at IS NOT NULL AND suppressed_at IS NULL"
        );

        $passed = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}` WHERE audit_status = 'pass' AND suppressed_at IS NULL"
        );

        $flagged = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}` WHERE audit_status = 'flagged' AND suppressed_at IS NULL"
        );

        $admin_review = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}` WHERE audit_status = 'admin_review' AND suppressed_at IS NULL"
        );

        return array(
            'total_published' => $total_published,
            'needs_audit'     => $needs_audit,
            'audited'         => $audited,
            'passed'          => $passed,
            'flagged'         => $flagged,
            'admin_review'    => $admin_review,
            'never_audited'   => max( 0, $total_published - $audited ),
        );
    }

    /**
     * Get recently flagged obituaries for admin review.
     *
     * @param int $limit Number of records to return.
     * @return array Array of flagged obituary objects.
     */
    public function get_flagged( $limit = 20 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, date_of_death, location, audit_status, audit_flags,
                    last_audit_at, audit_requeue_count
             FROM `{$table}`
             WHERE audit_status IN ('flagged', 'admin_review')
               AND suppressed_at IS NULL
             ORDER BY last_audit_at DESC
             LIMIT %d",
            $limit
        ) );
    }
}
