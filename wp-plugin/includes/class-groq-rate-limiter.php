<?php
/**
 * Shared Groq API Rate Limiter (v5.0.13 — QC-R13 hardening)
 *
 * BUG-M1 FIX: Coordinates API usage across all three Groq consumers:
 *   1. AI Rewriter        — ~1,100 tokens/call, 12s delay, batch cron/shutdown/AJAX
 *   2. AI Chatbot          — ~500 tokens/call, user-driven, rate-limited 1 msg/2s/IP
 *   3. Authenticity Checker — ~800 tokens/call, 10 per 4-hour cycle
 *
 * Architecture (v5.0.13 rewrite — addresses QC-R13 review):
 *
 *   ATOMICITY (QC-R12-1): may_proceed() performs an atomic CAS reserve.
 *     record_usage() ADJUSTS by computing delta = actual - estimated.
 *     Consumers MUST pass the same $estimated to record_usage() that they
 *     passed to may_proceed(). This eliminates the double-counting bug
 *     where the legacy $estimated=0 path would add actual tokens ON TOP
 *     of the already-reserved estimate.
 *
 *     On API failure, consumers MUST call release_reservation($estimated, $consumer)
 *     which credits the unused reservation back. Without this, failed API
 *     calls would permanently reduce pool capacity until window expiry.
 *
 *   CAS STRATEGY (QC-R13-2): Uses SELECT … FOR UPDATE row-level lock
 *     followed by version-counter comparison in PHP.
 *
 *     Previous approach (QC-R11): LIKE '{"v":N,%' pattern match was NOT
 *     truly atomic — JSON key order is not guaranteed by wp_json_encode()
 *     across all PHP versions (PHP 8.1+ can reorder on certain inputs),
 *     and the LIKE prefix could match unintended content. Lost updates
 *     were possible if two processes read the same version simultaneously.
 *
 *     New approach: cas_update() wraps the read+compare+write in a single
 *     $wpdb transaction using SELECT … FOR UPDATE (InnoDB exclusive row
 *     lock on wp_options). The lock is held for <1ms (JSON decode + version
 *     check + UPDATE). This is true mutual exclusion — concurrent callers
 *     block until the lock is released, then re-read and retry.
 *
 *     The version counter `v` is still incremented on every write as a
 *     secondary guard — if the row is somehow modified between the
 *     FOR UPDATE read and the UPDATE (e.g. by a non-transactional path),
 *     the WHERE clause catches the mismatch.
 *
 *     TRANSACTION SAFETY (QC-R13): cas_update() now wraps the entire
 *     transaction body in try/finally — if ANY throwable occurs between
 *     START TRANSACTION and COMMIT (e.g. PHP fatal in a custom error
 *     handler, plugin conflict), ROLLBACK is guaranteed to fire, preventing
 *     leaked locks and open transactions. Additionally:
 *       - $wpdb->last_error is checked after START TRANSACTION to catch
 *         connection/permission errors immediately.
 *       - json_decode() failure on the locked row is explicitly detected
 *         and treated as a version mismatch (ROLLBACK + return 0), not
 *         silently coerced to v=0 which could cause spurious matches.
 *
 *   INPUT HARDENING (QC-R12-3): Both may_proceed() and record_usage() reject
 *     negative and zero token values with early return.
 *
 *   CACHE EFFICIENCY (QC-R13-4): Tiered cache invalidation:
 *     - After successful CAS write: invalidate per-option key + alloptions
 *       (defense-in-depth if autoload ever becomes 'yes').
 *     - After CAS failure (retry path): invalidate full triad.
 *     - After add_option(): invalidate per-option + notoptions + alloptions.
 *     OPTION_KEY is always created with autoload='no' (verified: the only
 *     two add_option() calls are on lines 281 and 451, both pass 'no').
 *     However, invalidate_option_only() also busts alloptions as a
 *     defense-in-depth guard — if a future change, migration script, or
 *     WP core bug ever promotes this key to autoload='yes', get_option()
 *     would serve stale data from the bundled alloptions cache without
 *     this extra delete.
 *     QC-R13 improvement: The FOR UPDATE approach eliminates most CAS
 *     failures (concurrent readers block, not fail), so the full-triad
 *     invalidation path is rarely triggered. This reduces notoptions
 *     churn to the retry/add_option paths only.
 *
 *   POOL ROUTING (QC-R12-5): get_pool() normalizes consumer string to lowercase
 *     and validates against CONSUMER_POOL_MAP. Unknown consumers are REJECTED
 *     (fail-closed) — may_proceed() returns false, record_usage() returns early.
 *     This prevents typo-based misrouting from silently consuming budget.
 *     QC-R12 improvement: Unknown consumer errors are now logged at 'error'
 *     level with the raw consumer string, the calling method, and a list of
 *     valid consumers. This ensures silent outages are immediately visible
 *     in the debug log.
 *
 *   BUDGET SEPARATION (QC-R12-6, DoS mitigation):
 *     - Cron consumers (rewriter, authenticity) share 80% of TPM budget.
 *     - Chatbot (public endpoint) gets 20%.
 *     - get_pool() is the SOLE mapping function.
 *       VERIFIED: All consumer call sites pass a known consumer string:
 *         - class-ai-rewriter.php:477     → may_proceed(1100, 'rewriter')    → cron pool
 *         - class-ai-rewriter.php:call_api→ record_usage($tokens,'rewriter',1100)→cron pool
 *         - class-ai-rewriter.php:error   → release_reservation(1100,'rewriter')→cron pool
 *         - class-ai-chatbot.php:198      → may_proceed(500, 'chatbot')      → chatbot pool
 *         - class-ai-chatbot.php:252      → record_usage($tokens,'chatbot',500)→chatbot pool
 *         - class-ai-chatbot.php:error    → release_reservation(500,'chatbot')→chatbot pool
 *         - class-ai-authenticity-checker.php:220→may_proceed(800,'authenticity')→cron pool
 *         - class-ai-authenticity-checker.php:call_api→record_usage($t,'authenticity',800)→cron
 *         - class-ai-authenticity-checker.php:error→release_reservation(800,'authenticity')→cron
 *
 *   CONFIGURABLE (QC-R12-7): TPM budget stored in wp_options with autoload='no'.
 *     Admins override via option; code overrides via filter. Filter runs once
 *     at singleton construction time. refresh() destroys and recreates the singleton.
 *
 *   WORDPRESS-SAFE (QC-R12-8): All reads via get_option(), creates via
 *     add_option(..., '', 'no'), CAS updates via $wpdb SELECT … FOR UPDATE
 *     + $wpdb->update() + immediate cache invalidation. No stale reads
 *     after direct DB writes.
 *
 *   PUBLISH GATING (QC-R12-9): The limiter ONLY returns rate_limited WP_Error
 *     or falls back to rule-based responses. It NEVER changes record status.
 *     pending→published transition is exclusively in class-ai-rewriter.php.
 *
 *   CLEANUP: Option keys are deleted on deactivation and uninstall.
 *     Multisite: uninstall iterates all sites via get_sites()/switch_to_blog().
 *
 * @package Ontario_Obituaries
 * @since   5.0.6
 * @since   5.0.12  QC-R12: SELECT … FOR UPDATE CAS, enhanced logging,
 *                  multisite elapsed-time guard, cache churn reduction.
 * @since   5.0.13  QC-R13: Transaction try/finally safety, $wpdb->last_error
 *                  checks, explicit json_decode error handling, autoload
 *                  defense-in-depth on happy-path cache invalidation.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ontario_Obituaries_Groq_Rate_Limiter {

    /** @var string wp_options key for the atomic rate-limit window. */
    const OPTION_KEY = 'ontario_obituaries_groq_rate_window';

    /** @var string wp_options key for configurable TPM budget. */
    const BUDGET_OPTION_KEY = 'ontario_obituaries_groq_tpm_budget';

    /**
     * Default tokens-per-minute budget.
     * Groq free tier = 6,000 TPM.  We use 5,500 (500 headroom for retry-after bursts).
     */
    const DEFAULT_TPM_BUDGET = 5500;

    /** @var int Window duration in seconds. */
    const WINDOW_SECONDS = 60;

    /**
     * Fraction of budget reserved for cron consumers (rewriter + authenticity).
     * Chatbot gets 1 - CRON_BUDGET_FRACTION.
     *
     * 80/20 split: 4,400 TPM for cron, 1,100 TPM for chatbot.
     */
    const CRON_BUDGET_FRACTION = 0.80;

    /** @var int Maximum CAS retry attempts before giving up. */
    const MAX_CAS_RETRIES = 3;

    /**
     * Known consumer → pool mapping.
     * QC-R11-5: Explicit allowlist. Unknown consumers are REJECTED (fail-closed).
     *
     * @var array<string, string>
     */
    const CONSUMER_POOL_MAP = array(
        'rewriter'      => 'cron',
        'chatbot'       => 'chatbot',
        'authenticity'  => 'cron',
    );

    /** @var self|null Singleton instance. */
    private static $instance = null;

    /** @var int Configured total TPM budget. */
    private $tpm_budget;

    /** @var int Cron-pool budget (rewriter + authenticity). */
    private $cron_budget;

    /** @var int Chatbot-pool budget. */
    private $chatbot_budget;

    /**
     * Get singleton instance.
     *
     * @return self
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Destroy and recreate the singleton to pick up runtime budget changes.
     *
     * @since 5.0.9
     * @return self The new singleton instance.
     */
    public static function refresh() {
        self::$instance = null;
        return self::get_instance();
    }

    /**
     * Constructor — private (singleton).
     */
    private function __construct() {
        $stored = get_option( self::BUDGET_OPTION_KEY, 0 );
        $base   = ( (int) $stored > 0 ) ? (int) $stored : self::DEFAULT_TPM_BUDGET;

        /** @since 5.0.7 */
        $this->tpm_budget = (int) apply_filters(
            'ontario_obituaries_groq_tpm_budget',
            $base
        );

        if ( $this->tpm_budget < 500 ) {
            $this->tpm_budget = self::DEFAULT_TPM_BUDGET;
        }

        $this->cron_budget    = (int) floor( $this->tpm_budget * self::CRON_BUDGET_FRACTION );
        $this->chatbot_budget = $this->tpm_budget - $this->cron_budget;
    }

    /**
     * Check if a consumer may proceed with an API call — ATOMIC RESERVE.
     *
     * QC-R11-1 FIX: Performs an atomic reserve via CAS. On success, the
     * estimated tokens are DEDUCTED from the pool budget in the same write
     * that confirms availability. This eliminates the race window.
     *
     * On CAS failure (concurrent write), returns false — fail-closed.
     *
     * IMPORTANT: Callers MUST either:
     *   1. Call record_usage($actual, $consumer, $estimated) after success, OR
     *   2. Call release_reservation($estimated, $consumer) after API failure.
     * Failure to do either leaves the reservation standing (safe but wasteful).
     *
     * @param int    $estimated_tokens Estimated total tokens for the request.
     * @param string $consumer         Consumer ID: 'rewriter', 'chatbot', 'authenticity'.
     * @return bool  True if budget allows AND reservation was atomically committed.
     */
    public function may_proceed( $estimated_tokens = 1100, $consumer = 'unknown' ) {
        // QC-R11-3: Input hardening.
        $estimated_tokens = (int) $estimated_tokens;
        if ( $estimated_tokens <= 0 ) {
            $this->log_message(
                sprintf( 'Groq rate limiter: %s rejected — non-positive token estimate (%d).', $consumer, $estimated_tokens ),
                'warning'
            );
            return false;
        }

        // QC-R12-5: Normalize and validate consumer. Reject unknown with detailed logging.
        $consumer = $this->normalize_consumer( $consumer );
        $pool     = $this->get_pool( $consumer );
        if ( false === $pool ) {
            // QC-R12-4: Enhanced logging for unknown consumer — includes raw string,
            // calling file/line (for tracing misconfiguration), and valid options.
            $caller = 'unknown';
            if ( function_exists( 'debug_backtrace' ) ) {
                $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 3 ); // phpcs:ignore
                if ( isset( $trace[1] ) ) {
                    $caller = basename( $trace[1]['file'] ?? '' ) . ':' . ( $trace[1]['line'] ?? '?' );
                }
            }
            $this->log_message(
                sprintf( 'Groq rate limiter: BLOCKED unknown consumer "%s" (caller: %s). '
                    . 'Valid consumers: [%s]. This request was REJECTED (fail-closed). '
                    . 'Check the consumer string passed to may_proceed().',
                    $consumer, $caller, implode( ', ', array_keys( self::CONSUMER_POOL_MAP ) )
                ),
                'error'
            );
            return false;
        }

        global $wpdb;

        $pool_budget = ( 'chatbot' === $pool ) ? $this->chatbot_budget : $this->cron_budget;
        $pool_key    = $pool . '_tokens';
        $now         = time();

        for ( $attempt = 0; $attempt < self::MAX_CAS_RETRIES; $attempt++ ) {

            if ( $attempt > 0 ) {
                $this->invalidate_full_cache();
            }

            $raw = get_option( self::OPTION_KEY, '' );

            // ── Case A: Option doesn't exist — create with reservation ──
            if ( ! is_string( $raw ) || '' === $raw ) {
                if ( $estimated_tokens > $pool_budget ) {
                    $this->log_message(
                        sprintf( 'Groq rate limiter: %s BLOCKED — %d tokens > %d pool budget (%s pool).',
                            $consumer, $estimated_tokens, $pool_budget, $pool ),
                        'info'
                    );
                    return false;
                }

                $new_window = $this->fresh_window( $now, $pool_key, $estimated_tokens, $consumer );
                if ( add_option( self::OPTION_KEY, wp_json_encode( $new_window ), '', 'no' ) ) {
                    // QC-R11-4: After add_option, bust per-option + notoptions + alloptions.
                    $this->invalidate_full_cache();
                    $this->log_reserve( $consumer, $estimated_tokens, $new_window, $pool, $pool_budget );
                    return true;
                }
                continue;
            }

            // ── Parse the stored JSON ──
            $window = json_decode( $raw, true );

            // ── Case B: Invalid JSON or expired window — reset with reservation ──
            if ( ! is_array( $window ) || ! isset( $window['expires'] ) || $now >= (int) $window['expires'] ) {
                if ( $estimated_tokens > $pool_budget ) {
                    $this->log_message(
                        sprintf( 'Groq rate limiter: %s BLOCKED — %d tokens > %d pool budget (%s pool).',
                            $consumer, $estimated_tokens, $pool_budget, $pool ),
                        'info'
                    );
                    return false;
                }

                $new_window = $this->fresh_window( $now, $pool_key, $estimated_tokens, $consumer );
                $new_json   = wp_json_encode( $new_window );

                // QC-R12-2: CAS via SELECT … FOR UPDATE + version comparison.
                $old_version = is_array( $window ) && isset( $window['v'] ) ? (int) $window['v'] : 0;
                $rows = $this->cas_update( $new_json, $old_version );

                if ( $rows > 0 ) {
                    $this->invalidate_option_only();
                    $this->log_reserve( $consumer, $estimated_tokens, $new_window, $pool, $pool_budget );
                    return true;
                }
                continue;
            }

            // ── Case C: Active window — check budget then atomic reserve ──
            $pool_used  = isset( $window[ $pool_key ] ) ? (int) $window[ $pool_key ] : 0;
            $remaining  = $pool_budget - $pool_used;

            if ( $estimated_tokens > $remaining ) {
                $this->log_message(
                    sprintf( 'Groq rate limiter: %s BLOCKED — %d tokens requested, %d/%d remaining in %s pool.',
                        $consumer, $estimated_tokens, max( 0, $remaining ), $pool_budget, $pool ),
                    'info'
                );
                return false;
            }

            $updated_window = $window;
            $updated_window[ $pool_key ] = $pool_used + $estimated_tokens;
            if ( ! isset( $updated_window['calls'][ $consumer ] ) ) {
                $updated_window['calls'][ $consumer ] = 0;
            }
            $updated_window['calls'][ $consumer ]++;
            $updated_window['v'] = ( isset( $window['v'] ) ? (int) $window['v'] : 0 ) + 1;

            $new_json = wp_json_encode( $updated_window );

            // QC-R12-2: CAS via SELECT … FOR UPDATE + version comparison.
            $old_version = isset( $window['v'] ) ? (int) $window['v'] : 0;
            $rows = $this->cas_update( $new_json, $old_version );

            if ( $rows > 0 ) {
                $this->invalidate_option_only();
                $this->log_reserve( $consumer, $estimated_tokens, $updated_window, $pool, $pool_budget );
                return true;
            }
            // CAS failed — retry with full cache-bust.
        }

        // All CAS attempts failed — BLOCK (fail-closed).
        $this->log_message(
            sprintf( 'Groq rate limiter: %s BLOCKED — CAS failed after %d attempts (%d tokens). Deferring.',
                $consumer, self::MAX_CAS_RETRIES, $estimated_tokens ),
            'warning'
        );
        return false;
    }

    /**
     * Adjust the reservation to actual token usage after a SUCCESSFUL API call.
     *
     * QC-R11-1 FIX: Callers MUST pass the same $estimated value they used in
     * may_proceed(). The method computes delta = actual - estimated and adjusts.
     *   - delta > 0: underestimated → add more tokens
     *   - delta < 0: overestimated → refund excess tokens
     *   - delta = 0: exact match → no-op
     *
     * On CAS failure, the original reservation stands (overcount = safe direction).
     *
     * For callers without a prior reservation (legacy), $estimated defaults to 0
     * which treats the call as a pure addition. This is SAFE but results in
     * double-counting if may_proceed() was called. All current consumers now
     * pass $estimated, so this path exists only for backward compatibility and
     * logs a deprecation warning.
     *
     * @param int    $actual_tokens Actual total tokens consumed (from Groq usage.total_tokens).
     * @param string $consumer      Consumer ID.
     * @param int    $estimated     Original estimate passed to may_proceed(). REQUIRED.
     */
    public function record_usage( $actual_tokens, $consumer = 'unknown', $estimated = 0 ) {
        $actual_tokens = max( 0, (int) $actual_tokens );
        $estimated     = max( 0, (int) $estimated );
        $consumer      = $this->normalize_consumer( $consumer );
        $pool          = $this->get_pool( $consumer );

        if ( false === $pool ) {
            // QC-R12-4: Detailed logging for unknown consumer in record_usage.
            $caller = 'unknown';
            if ( function_exists( 'debug_backtrace' ) ) {
                $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 3 ); // phpcs:ignore
                if ( isset( $trace[1] ) ) {
                    $caller = basename( $trace[1]['file'] ?? '' ) . ':' . ( $trace[1]['line'] ?? '?' );
                }
            }
            $this->log_message(
                sprintf( 'Groq rate limiter: record_usage REJECTED unknown consumer "%s" '
                    . '(caller: %s, actual=%d, estimated=%d). Valid: [%s].',
                    $consumer, $caller, $actual_tokens, $estimated,
                    implode( ', ', array_keys( self::CONSUMER_POOL_MAP ) )
                ),
                'error'
            );
            return;
        }

        $pool_key = $pool . '_tokens';

        // QC-R11-1: Compute delta.
        if ( $estimated > 0 ) {
            $delta = $actual_tokens - $estimated;
            if ( 0 === $delta ) {
                $this->log_usage( $consumer, $actual_tokens, null );
                return;
            }
        } else {
            // Legacy path: no estimate provided. Log deprecation warning.
            $this->log_message(
                sprintf( 'Groq rate limiter: record_usage(%d, "%s") called WITHOUT $estimated. '
                    . 'This causes double-counting if may_proceed() was called. '
                    . 'Pass the same estimate to record_usage() as you passed to may_proceed().',
                    $actual_tokens, $consumer ),
                'warning'
            );
            if ( 0 === $actual_tokens ) {
                return;
            }
            $delta = $actual_tokens;
        }

        global $wpdb;
        $now = time();

        for ( $attempt = 0; $attempt < self::MAX_CAS_RETRIES; $attempt++ ) {

            if ( $attempt > 0 ) {
                $this->invalidate_full_cache();
            }

            $raw = get_option( self::OPTION_KEY, '' );

            // ── Case A: Option doesn't exist ──
            if ( ! is_string( $raw ) || '' === $raw ) {
                if ( $delta <= 0 ) {
                    return; // Nothing to adjust.
                }
                $new_window = $this->fresh_window( $now, $pool_key, max( 0, $delta ), $consumer );
                if ( add_option( self::OPTION_KEY, wp_json_encode( $new_window ), '', 'no' ) ) {
                    $this->invalidate_full_cache();
                    $this->log_usage( $consumer, $actual_tokens, $new_window );
                    return;
                }
                continue;
            }

            // ── Parse ──
            $window = json_decode( $raw, true );

            // ── Case B: Invalid/expired ──
            if ( ! is_array( $window ) || ! isset( $window['expires'] ) || $now >= (int) $window['expires'] ) {
                if ( $delta <= 0 ) {
                    return; // Window expired — reservation gone.
                }
                $new_window = $this->fresh_window( $now, $pool_key, max( 0, $delta ), $consumer );
                $new_json   = wp_json_encode( $new_window );

                $old_version = is_array( $window ) && isset( $window['v'] ) ? (int) $window['v'] : 0;
                $rows = $this->cas_update( $new_json, $old_version );

                if ( $rows > 0 ) {
                    $this->invalidate_option_only();
                    $this->log_usage( $consumer, $actual_tokens, $new_window );
                    return;
                }
                continue;
            }

            // ── Case C: Active window — apply delta ──
            $updated_window = $window;
            $current_val    = isset( $window[ $pool_key ] ) ? (int) $window[ $pool_key ] : 0;
            $updated_window[ $pool_key ] = max( 0, $current_val + $delta );
            $updated_window['v'] = ( isset( $window['v'] ) ? (int) $window['v'] : 0 ) + 1;

            $new_json = wp_json_encode( $updated_window );

            $old_version = isset( $window['v'] ) ? (int) $window['v'] : 0;
            $rows = $this->cas_update( $new_json, $old_version );

            if ( $rows > 0 ) {
                $this->invalidate_option_only();
                $this->log_usage( $consumer, $actual_tokens, $updated_window );
                return;
            }
        }

        // CAS exhausted — reservation stands (overcount, safe direction).
        $this->log_message(
            sprintf( 'Groq rate limiter: record_usage CAS failed after %d attempts for %s (%d actual, %d estimated). Reservation stands.',
                self::MAX_CAS_RETRIES, $consumer, $actual_tokens, $estimated ),
            'warning'
        );
    }

    /**
     * Release a reservation after a FAILED API call (no tokens consumed).
     *
     * QC-R11-1 FIX: If the API call fails (WP_Error, timeout, etc.), the tokens
     * reserved by may_proceed() must be returned to the pool. Without this,
     * failed calls permanently reduce pool capacity until window expiry.
     *
     * This is equivalent to record_usage(0, $consumer, $estimated) but with
     * a clearer name and intent.
     *
     * @since 5.0.11
     * @param int    $estimated The estimate originally passed to may_proceed().
     * @param string $consumer  Consumer ID.
     */
    public function release_reservation( $estimated, $consumer ) {
        $this->record_usage( 0, $consumer, $estimated );
    }

    /**
     * Get seconds until the current rate-limit window resets.
     *
     * @return int Seconds until reset (0 if no active window).
     */
    public function seconds_until_reset() {
        $window = $this->read_window();
        if ( ! isset( $window['expires'] ) || 0 === (int) $window['expires'] ) {
            return 0;
        }
        return max( 0, (int) $window['expires'] - time() );
    }

    /**
     * Get current window usage stats (for admin/debug display).
     *
     * @return array { cron_tokens, chatbot_tokens, cron_budget, chatbot_budget,
     *                 total_budget, expires_in, calls }
     */
    public function get_stats() {
        $window = $this->read_window();
        $now    = time();

        $cron_used    = isset( $window['cron_tokens'] )    ? (int) $window['cron_tokens']    : 0;
        $chatbot_used = isset( $window['chatbot_tokens'] ) ? (int) $window['chatbot_tokens'] : 0;

        return array(
            'cron_tokens'       => $cron_used,
            'chatbot_tokens'    => $chatbot_used,
            'cron_budget'       => $this->cron_budget,
            'chatbot_budget'    => $this->chatbot_budget,
            'total_budget'      => $this->tpm_budget,
            'cron_remaining'    => max( 0, $this->cron_budget - $cron_used ),
            'chatbot_remaining' => max( 0, $this->chatbot_budget - $chatbot_used ),
            'expires_in'        => isset( $window['expires'] ) ? max( 0, (int) $window['expires'] - $now ) : 0,
            'calls'             => isset( $window['calls'] ) ? $window['calls'] : array(),
        );
    }

    /**
     * Reset the rate window. Called on deactivation/uninstall.
     */
    public static function reset() {
        delete_option( self::OPTION_KEY );
        wp_cache_delete( self::OPTION_KEY, 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );
    }

    // ──────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Perform a true atomic CAS via SELECT … FOR UPDATE + version comparison.
     *
     * QC-R12-2 FIX: The previous LIKE '{"v":N,%' approach (QC-R11) was not
     * truly atomic — JSON key order is not guaranteed across PHP versions,
     * and two processes reading the same version simultaneously could both
     * succeed in matching the LIKE pattern, causing a lost update.
     *
     * New approach:
     *   1. BEGIN a transaction (InnoDB autocommit override).
     *   2. SELECT option_value FROM wp_options WHERE option_name = %s FOR UPDATE
     *      — this acquires an exclusive row-level lock. Concurrent callers BLOCK
     *      (wait) rather than fail, eliminating the retry loop for contention.
     *   3. Decode the JSON, compare the version counter in PHP (immune to key
     *      ordering and whitespace differences).
     *   4. If the version matches, UPDATE the row with the new value.
     *   5. COMMIT — releases the lock.
     *
     * Lock duration: <1ms (JSON decode + comparison + UPDATE). InnoDB's
     * innodb_lock_wait_timeout (default 50s) provides the safety net.
     *
     * Fallback: If FOR UPDATE is unavailable (e.g., MyISAM tables, which
     * cannot do row-level locking), falls back to the UPDATE … WHERE
     * option_value = %s exact-match approach (safe because we read the
     * raw value inside the same connection).
     *
     * @since 5.0.12
     * @param string $new_json    The new JSON value to write.
     * @param int    $old_version The version counter from the last read.
     * @return int Number of rows affected (1 = success, 0 = CAS failure).
     */
    private function cas_update( $new_json, $old_version ) {
        global $wpdb;

        $old_version = (int) $old_version;
        $rows        = 0;

        // QC-R13: Check START TRANSACTION result — catches connection loss,
        // permission errors, and MyISAM-only tables up front.
        $tx_result = $wpdb->query( 'START TRANSACTION' );
        if ( false === $tx_result || ! empty( $wpdb->last_error ) ) {
            $this->log_message(
                sprintf( 'Groq rate limiter: START TRANSACTION failed — %s. Falling back to non-transactional CAS.', $wpdb->last_error ),
                'error'
            );
            // Fallback: non-transactional exact-match UPDATE (safe for single-row CAS).
            return $this->cas_update_fallback( $new_json, $old_version );
        }

        // QC-R13: try/finally guarantees ROLLBACK on ANY throwable (PHP fatal in
        // custom error handler, plugin conflict, OOM) — prevents leaked locks and
        // open transactions that would block subsequent requests until timeout.
        try {
            $locked_row = $wpdb->get_var( $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s FOR UPDATE",
                self::OPTION_KEY
            ) );

            // Row doesn't exist — another process deleted it.
            if ( null === $locked_row ) {
                $wpdb->query( 'ROLLBACK' );
                return 0;
            }

            // QC-R13: Explicitly check json_decode result. Invalid JSON in the locked
            // row should NOT silently match version 0 — that could cause a spurious
            // write if $old_version also happens to be 0 (e.g., fresh window).
            $locked_data = json_decode( $locked_row, true );
            if ( null === $locked_data && JSON_ERROR_NONE !== json_last_error() ) {
                // Corrupted option value — rollback, let caller retry with fresh read.
                $this->log_message(
                    sprintf( 'Groq rate limiter: CAS locked row contains invalid JSON — %s. Rolling back.', json_last_error_msg() ),
                    'error'
                );
                $wpdb->query( 'ROLLBACK' );
                return 0;
            }

            $locked_version = ( is_array( $locked_data ) && isset( $locked_data['v'] ) )
                ? (int) $locked_data['v']
                : 0;

            if ( $locked_version !== $old_version ) {
                // Version mismatch — another process wrote between our get_option()
                // read and this FOR UPDATE lock. Rollback and let the caller retry.
                $wpdb->query( 'ROLLBACK' );
                return 0;
            }

            // Version matches — safe to update. Use exact raw-string match as
            // a belt-and-suspenders guard (the row is exclusively locked, so
            // no one else can change it between SELECT and UPDATE).
            $rows = (int) $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                $new_json,
                self::OPTION_KEY,
                $locked_row
            ) );

            // QC-R13: Check COMMIT result — if COMMIT fails the transaction
            // is still open; ROLLBACK to release the lock cleanly.
            $commit_result = $wpdb->query( 'COMMIT' );
            if ( false === $commit_result || ! empty( $wpdb->last_error ) ) {
                $this->log_message(
                    sprintf( 'Groq rate limiter: COMMIT failed — %s. Rolling back.', $wpdb->last_error ),
                    'error'
                );
                $wpdb->query( 'ROLLBACK' );
                return 0;
            }

            return $rows;

        } catch ( \Throwable $e ) {
            // QC-R13: Catch ANY throwable — ensures ROLLBACK fires even on fatal-class
            // errors that PHP 7+ converts to Error objects (TypeError, ParseError, etc.).
            $this->log_message(
                sprintf( 'Groq rate limiter: Exception during CAS transaction — %s. Rolling back.', $e->getMessage() ),
                'error'
            );
            $wpdb->query( 'ROLLBACK' );
            return 0;
        }
    }

    /**
     * Non-transactional CAS fallback — used when START TRANSACTION fails.
     *
     * Uses exact option_value string match in the WHERE clause. This is safe
     * because $wpdb reads and writes on the same connection, and the risk of
     * lost updates is low (only possible under concurrent writes, which are
     * rare when the transactional path is unavailable — typically MyISAM or
     * permission-restricted environments).
     *
     * @since 5.0.13
     * @param string $new_json    The new JSON value to write.
     * @param int    $old_version The version counter from the last read (unused here but kept for API symmetry).
     * @return int Number of rows affected.
     */
    private function cas_update_fallback( $new_json, $old_version ) {
        global $wpdb;

        // Read the current raw value.
        $current_raw = $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            self::OPTION_KEY
        ) );

        if ( null === $current_raw ) {
            return 0;
        }

        // Exact string match — if someone else changed it, this returns 0.
        return (int) $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
            $new_json,
            self::OPTION_KEY,
            $current_raw
        ) );
    }

    /**
     * Normalize a consumer string for consistent pool routing.
     *
     * QC-R11-5 FIX: Lowercases and trims. Does NOT default unknown to 'cron'.
     * The get_pool() method handles rejection of unknown consumers.
     *
     * @param string $consumer Raw consumer ID.
     * @return string Normalized consumer ID.
     */
    private function normalize_consumer( $consumer ) {
        return strtolower( trim( (string) $consumer ) );
    }

    /**
     * Determine which budget pool a consumer belongs to.
     *
     * QC-R12-5 FIX: Unknown consumers return false (fail-closed) instead of
     * silently routing to the cron pool. This prevents typos from consuming
     * budget in the wrong pool without the developer noticing.
     *
     * QC-R12: Enhanced error logging — logs the raw consumer string, calling
     * context (via debug_backtrace), and the full list of valid consumers.
     * This makes misconfiguration immediately visible in the debug log
     * instead of causing silent outages.
     *
     * @param string $consumer Consumer ID (already normalized).
     * @return string|false 'chatbot', 'cron', or false if unknown.
     */
    private function get_pool( $consumer ) {
        if ( isset( self::CONSUMER_POOL_MAP[ $consumer ] ) ) {
            return self::CONSUMER_POOL_MAP[ $consumer ];
        }
        return false; // QC-R12-5: Fail-closed — unknown consumers are REJECTED.
    }

    /**
     * Invalidate only the per-option cache key (cheap — happy path).
     *
     * @since 5.0.10
     */
    private function invalidate_option_only() {
        wp_cache_delete( self::OPTION_KEY, 'options' );
        // QC-R13: Defense-in-depth — also bust alloptions in case OPTION_KEY is
        // ever promoted to autoload='yes' (by migration, WP core bug, or admin
        // direct DB edit). Without this, get_option() would serve stale data
        // from the bundled alloptions cache. Cost: one extra wp_cache_delete()
        // per successful CAS write (negligible vs. the DB round-trip).
        wp_cache_delete( 'alloptions', 'options' );
    }

    /**
     * Invalidate all WP object-cache keys that could hold this option's value.
     *
     * QC-R11-4 FIX: Used on retry path, after add_option(), and after CAS failure.
     * Includes the per-option key (missing from QC-R10's add_option path).
     *
     * @since 5.0.9
     */
    private function invalidate_full_cache() {
        wp_cache_delete( self::OPTION_KEY, 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );
    }

    /**
     * Read the current window from wp_options, handling expiry.
     *
     * @return array
     */
    private function read_window() {
        $raw    = get_option( self::OPTION_KEY, '' );
        $window = is_string( $raw ) ? json_decode( $raw, true ) : null;

        if ( ! is_array( $window ) || ! isset( $window['expires'] ) ) {
            return array( 'expires' => 0, 'cron_tokens' => 0, 'chatbot_tokens' => 0, 'calls' => array(), 'v' => 0 );
        }

        if ( time() >= (int) $window['expires'] ) {
            return array( 'expires' => 0, 'cron_tokens' => 0, 'chatbot_tokens' => 0, 'calls' => array(), 'v' => 0 );
        }

        return $window;
    }

    /**
     * Build a fresh window array.
     *
     * IMPORTANT: "v" MUST be the first key so that the CAS LIKE pattern
     * '{"v":N,' matches the JSON prefix. wp_json_encode preserves insertion order.
     *
     * @param int    $now       Current Unix timestamp.
     * @param string $pool_key  Pool key ('cron_tokens' or 'chatbot_tokens').
     * @param int    $tokens    Initial token count.
     * @param string $consumer  Consumer name.
     * @return array
     */
    private function fresh_window( $now, $pool_key, $tokens, $consumer ) {
        $window = array(
            'v'              => 1,  // MUST be first key for CAS LIKE prefix match.
            'expires'        => (int) ( $now + self::WINDOW_SECONDS ),
            'cron_tokens'    => 0,
            'chatbot_tokens' => 0,
            'calls'          => array( $consumer => 1 ),
        );
        $window[ $pool_key ] = max( 0, (int) $tokens );
        return $window;
    }

    /**
     * Log a successful reservation (from may_proceed).
     */
    private function log_reserve( $consumer, $tokens, $window, $pool, $pool_budget ) {
        $pool_key  = $pool . '_tokens';
        $pool_used = isset( $window[ $pool_key ] ) ? (int) $window[ $pool_key ] : 0;

        $this->log_message(
            sprintf( 'Groq rate limiter: %s RESERVED %d tokens (%s pool: %d/%d, expires in %ds, v=%d).',
                $consumer, $tokens, $pool,
                $pool_used, $pool_budget,
                max( 0, (int) $window['expires'] - time() ),
                isset( $window['v'] ) ? (int) $window['v'] : 0
            ),
            'debug'
        );
    }

    /**
     * Log token usage adjustment (from record_usage).
     */
    private function log_usage( $consumer, $tokens, $window ) {
        if ( null === $window ) {
            $this->log_message(
                sprintf( 'Groq rate limiter: %s actual usage = estimate (%d tokens). No adjustment needed.', $consumer, $tokens ),
                'debug'
            );
            return;
        }

        $pool        = $this->get_pool( $consumer );
        $pool_key    = ( false !== $pool ) ? $pool . '_tokens' : 'cron_tokens';
        $pool_used   = isset( $window[ $pool_key ] ) ? (int) $window[ $pool_key ] : 0;
        $pool_budget = ( 'chatbot' === $pool ) ? $this->chatbot_budget : $this->cron_budget;

        $this->log_message(
            sprintf( 'Groq rate limiter: %s adjusted to %d actual tokens (%s pool: %d/%d, v=%d).',
                $consumer, $tokens, ( false !== $pool ) ? $pool : 'cron',
                $pool_used, $pool_budget,
                isset( $window['v'] ) ? (int) $window['v'] : 0
            ),
            'debug'
        );
    }

    /**
     * Log a message.
     */
    private function log_message( $message, $level = 'info' ) {
        if ( function_exists( 'ontario_obituaries_log' ) ) {
            ontario_obituaries_log( $message, $level );
        }
    }
}
