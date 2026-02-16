<?php
/**
 * Uninstall script for Ontario Obituaries
 *
 * Fired when the plugin is deleted from WP Admin > Plugins.
 * Cleans up all database tables, options, transients, and cron events
 * created by the plugin.
 *
 * BUG-H2 FIX (v5.0.5): Complete option cleanup — adds ALL plugin options
 *   including sensitive API keys (Groq, Google Ads, IndexNow), chatbot
 *   settings, lead data, reset/rescan session state, and BUG-C4 lock options.
 *   Previously only 11 of 22+ options were deleted, leaving API keys exposed.
 *
 * BUG-H7 FIX (v5.0.5): Complete cron cleanup — clears ALL 8 scheduled hooks.
 *   Previously only 2 of 8 hooks were cleared, leaving orphan cron events.
 *
 * QC-R12 FIX (v5.0.12): Multisite uninstall hardening:
 *   - switch_to_blog() return value is checked — if it fails (returns false
 *     on some WP versions), we skip the site and log a warning.
 *   - try/finally around switch_to_blog()/restore_current_blog() prevents
 *     blog-context leaks if ontario_obituaries_uninstall_site() throws.
 *   - Per-site elapsed-time guard (replaces set_time_limit which may be
 *     disabled by safe_mode, open_basedir, or hosting restrictions and
 *     generates E_WARNING when blocked). Each site checks elapsed time
 *     BEFORE and AFTER cleanup — if a single site exceeds the per-site
 *     budget, the loop breaks immediately.
 *   - Timeout guard breaks IMMEDIATELY when budget exceeded.
 *   - Skipped-site ID list capped to 500 entries to prevent unbounded logging
 *     on very large networks (10k+ sites).
 *   - get_sites() uses explicit 'number' param — never 'number' => 0.
 *   - Batch pagination with 100 sites per batch.
 *   - Network-level cleanup via delete_site_option()/delete_site_transient().
 *   - Partial completion is safe: all operations are idempotent.
 *
 * P2-1  FIX: Added proper cleanup on plugin deletion.
 * P1-6  FIX: Restored flush_rewrite_rules() — SEO class registers
 *            /obituaries/ontario/ rewrite rules that must be cleaned up.
 *
 * @package OntarioObituaries
 */

// Exit if not called by WordPress
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Clean up all plugin data for a single site.
 *
 * This function handles all per-site cleanup: tables, options,
 * transients, cron hooks, and rewrite rules.
 *
 * Each operation is idempotent: calling this on an already-cleaned site
 * is a no-op (delete_option/delete_transient on missing keys returns false).
 *
 * @since 5.0.8
 * @since 5.0.9  Added idempotency note for partial-completion safety.
 * @since 5.0.10 Last audited: 2026-02-16 (v5.0.11 QC-R11).
 */
function ontario_obituaries_uninstall_site() {
    global $wpdb;

    // ── 1. Drop helper tables (NOT the main obituaries table) ─────────
    // v4.6.0 FIX: Do NOT drop the obituary data table on uninstall.
    // The table contains hundreds/thousands of scraped + AI-rewritten obituaries
    // that take hours to rebuild. Dropping it on a simple delete-and-reinstall
    // cycle causes catastrophic data loss.
    //
    // Only drop helper tables (sources registry & suppressions) which are
    // lightweight and auto-rebuilt on activation.
    //
    // To fully remove ALL data, manually run:
    //   DROP TABLE wp_ontario_obituaries;
    //   DROP TABLE wp_ontario_obituaries_sources;
    //   DROP TABLE wp_ontario_obituaries_suppressions;
    $tables = array(
        // $wpdb->prefix . 'ontario_obituaries',  // PRESERVED — contains obituary data
        $wpdb->prefix . 'ontario_obituaries_sources',
        $wpdb->prefix . 'ontario_obituaries_suppressions',
    );

    foreach ( $tables as $table ) {
        $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    // ── 2. Delete ALL plugin options ──────────────────────────────────
    // BUG-H2 FIX (v5.0.5): Comprehensive list — every option written by the
    // plugin.  Grouped by category for auditability.
    //
    // INVENTORY METHOD: grep -rn "update_option\|add_option\|get_option"
    //   across all plugin PHP files, extracting 'ontario_obituaries_*' keys.
    //   Last audited: 2026-02-16 (v5.0.10).
    $option_keys = array(
        // ── Core settings & state ─────────────────────────────────────
        'ontario_obituaries_settings',
        // 'ontario_obituaries_db_version',       // PRESERVED — prevents migration re-run on reinstall
        'ontario_obituaries_deployed_version',     // BUG-H2 FIX: was missing
        'ontario_obituaries_last_collection',
        'ontario_obituaries_last_scrape_errors',
        'ontario_obituaries_region_urls',
        'ontario_obituaries_page_id',
        'ontario_obituaries_menu_added',
        'ontario_obituaries_menu_v2',

        // ── Legacy options (may not exist on newer installs) ──────────
        'ontario_obituaries_enable_facebook',
        'ontario_obituaries_fb_app_id',
        'ontario_obituaries_last_scrape',
        'ontario_obituaries_dedup_v1',

        // ── Sensitive API keys & credentials ──────────────────────────
        // BUG-H2 FIX (v5.0.5): These were NOT deleted before, leaving API
        // keys exposed in wp_options after plugin removal.
        'ontario_obituaries_groq_api_key',         // Groq LLM API key
        'ontario_obituaries_google_ads_credentials', // Google Ads OAuth tokens
        'ontario_obituaries_indexnow_key',         // IndexNow API key

        // ── Feature data ──────────────────────────────────────────────
        // BUG-H2 FIX (v5.0.5): was missing
        'ontario_obituaries_chatbot_settings',     // Chatbot configuration
        'ontario_obituaries_chatbot_conversations', // Chatbot conversation log
        'ontario_obituaries_leads',                // Lead capture data (array of leads)

        // ── Reset/rescan session state ────────────────────────────────
        // BUG-H2 FIX (v5.0.5): was missing
        'ontario_obituaries_reset_session',
        'ontario_obituaries_reset_progress',
        'ontario_obituaries_reset_last_result',
        'ontario_obituaries_rescan_only_last_result',

        // ── BUG-C4 dedup lock & throttle log ──────────────────────────
        // Added in v5.0.4 (BUG-C4 fix).  autoload=no, zero runtime cost,
        // but should still be cleaned up.
        'ontario_obituaries_dedup_lock',
        'ontario_obituaries_dedup_throttle_log',
        'ontario_obituaries_cron_secret',           // QC-R6: Optional /cron auth secret

        // ── Rate limiter (v5.0.10) ────────────────────────────────────
        'ontario_obituaries_groq_rate_window',      // v5.0.7+: Atomic CAS rate-limit window
        'ontario_obituaries_groq_tpm_budget',       // v5.0.7+: Admin-configurable TPM budget
    );

    foreach ( $option_keys as $key ) {
        delete_option( $key );
    }

    // Also delete versioned dedup flags (e.g., ontario_obituaries_dedup_3.1.0)
    // Uses a LIKE query because version numbers are dynamic.
    $wpdb->query(
        "DELETE FROM `{$wpdb->options}` WHERE option_name LIKE 'ontario\_obituaries\_dedup\_%'"
    ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

    // ── 3. Delete ALL plugin transients ───────────────────────────────
    // BUG-H2 FIX (v5.0.5): Added rewriter-related transients that were missing.
    //
    // INVENTORY METHOD: grep -rn "set_transient\|delete_transient" across all
    //   plugin PHP files.  Last audited: 2026-02-16 (v5.0.10).
    $transient_keys = array(
        // ── Core transients ───────────────────────────────────────────
        'ontario_obituaries_activation_notice',
        'ontario_obituaries_table_exists',
        'ontario_obituaries_locations_cache',
        'ontario_obituaries_funeral_homes_cache',

        // ── Cron & rewriter transients ────────────────────────────────
        // BUG-H2 FIX (v5.0.5): was missing
        'ontario_obituaries_last_cron_spawn',
        'ontario_obituaries_rewriter_running',     // Rewriter lock transient
        'ontario_obituaries_rewriter_scheduling',  // Rewriter scheduling guard
        'ontario_obituaries_rewriter_throttle',    // Rewriter throttle transient
        'ontario_obituaries_cron_rest_limiter',    // QC-R5: REST /cron rate limiter
        'ontario_obituaries_groq_tpm_window',      // BUG-M1 FIX (v5.0.6): Legacy transient (pre-v5.0.7)
        'ontario_obituaries_collection_cooldown',  // QC-R9 (v5.0.9): Collection burst guard
    );

    foreach ( $transient_keys as $key ) {
        delete_transient( $key );
    }

    // ── 4. Clear ALL scheduled cron hooks ─────────────────────────────
    // BUG-H7 FIX (v5.0.5): Previously only 2 of 8 hooks were cleared.
    // Orphan cron events from uncleared hooks fire WordPress errors and
    // can trigger PHP fatal errors if the plugin files are gone.
    //
    // INVENTORY METHOD: grep -rn "wp_schedule_event\|wp_schedule_single_event"
    //   across all plugin PHP files.  Last audited: 2026-02-16 (v5.0.10).
    $cron_hooks = array(
        // ── Collection ────────────────────────────────────────────────
        'ontario_obituaries_collection_event',     // Recurring collection (hourly/daily/etc.)
        'ontario_obituaries_initial_collection',   // One-shot initial collection after activation

        // ── AI processing ─────────────────────────────────────────────
        // BUG-H7 FIX (v5.0.5): was missing
        'ontario_obituaries_ai_rewrite_batch',     // AI rewriter batch processing
        'ontario_obituaries_gofundme_batch',       // GoFundMe link matching

        // ── Auditing & analytics ──────────────────────────────────────
        // BUG-H7 FIX (v5.0.5): was missing
        'ontario_obituaries_authenticity_audit',   // AI authenticity checker
        'ontario_obituaries_google_ads_analysis',  // Google Ads daily analysis

        // ── Dedup (BUG-C4, v5.0.4) ───────────────────────────────────
        // BUG-H7 FIX (v5.0.5): was missing
        'ontario_obituaries_dedup_daily',          // Daily recurring dedup
        'ontario_obituaries_dedup_once',           // One-shot post-scrape dedup
    );

    foreach ( $cron_hooks as $hook ) {
        wp_clear_scheduled_hook( $hook );
    }

    // ── 5. Flush rewrite rules ────────────────────────────────────────
    // P1-6 FIX: SEO class registers /obituaries/ontario/ routes that must be
    // removed from the rewrite rules table.
    flush_rewrite_rules();
}

/**
 * Clean up network-level (site-wide) keys on multisite.
 *
 * QC-R10 FIX (v5.0.10): Handles network-level site_options and site_transients.
 * Currently the plugin does not store network-wide options, but this function
 * future-proofs cleanup. On single-site installs, this is a no-op (the
 * functions exist but operate on the same table as single-site options).
 *
 * @since 5.0.9
 * @since 5.0.10 Updated docblock for QC-R11.
 */
function ontario_obituaries_uninstall_network() {
    // Network-level site options (stored in wp_sitemeta on multisite).
    // The plugin currently stores no site_options, but if future versions do,
    // add them here. delete_site_option() is safe to call even if the key
    // doesn't exist (returns false, no side effects).
    $network_options = array(
        // Future: 'ontario_obituaries_network_setting',
    );
    foreach ( $network_options as $key ) {
        delete_site_option( $key );
    }

    // Network-level site transients (stored in wp_sitemeta on multisite).
    $network_transients = array(
        // Future: 'ontario_obituaries_network_cache',
    );
    foreach ( $network_transients as $key ) {
        delete_site_transient( $key );
    }
}

// ── MULTISITE-AWARE UNINSTALL ─────────────────────────────────────────────
// QC-R12 FIX (v5.0.12): On multisite, delete_option() only clears the current
// site's options. We must iterate all sites and clean each one.
//
// Hardening vs QC-R11:
//   1. CONTEXT SAFETY: try/finally around switch_to_blog()/restore_current_blog().
//      If ontario_obituaries_uninstall_site() throws, restore_current_blog() runs.
//   2. SWITCH RETURN CHECK: switch_to_blog() return value is checked. If it fails,
//      we skip the site and log instead of running cleanup in the wrong context.
//   3. PER-SITE ELAPSED-TIME CHECK: Replaces set_time_limit() which may be disabled
//      by safe_mode, open_basedir, or hosting restrictions (generates E_WARNING).
//      Instead, we measure wall-clock time per site. If a single site exceeds the
//      per-site budget ($per_site_limit seconds), we log and break.
//   4. TIMEOUT BREAK: When the time budget is exceeded, the loop BREAKS immediately.
//   5. SAFE get_sites(): Uses 'number' => $batch_size (never 0). Explicit pagination.
//   6. SKIP-LIST CAP: Skipped site IDs capped at 500 to prevent unbounded memory
//      usage and oversized log lines on very large networks (10k+ sites).
//   7. NETWORK-LEVEL CLEANUP: delete_site_option()/delete_site_transient().
//   8. PARTIAL COMPLETION: Idempotent by design — re-running is safe.
//
if ( is_multisite() ) {
    $uninstall_start  = time();
    $max_exec = (int) ini_get( 'max_execution_time' );
    if ( $max_exec <= 0 ) {
        $max_exec = 300; // Unlimited → assume 300s (safe default).
    }
    $time_budget      = min( 120, max( 30, (int) floor( $max_exec * 0.5 ) ) );
    $per_site_limit   = min( 15, max( 5, (int) floor( $time_budget * 0.15 ) ) ); // QC-R11: Per-site cap.
    $sites_cleaned    = 0;
    $sites_skipped    = array();
    $max_skip_ids     = 500; // QC-R11: Cap skip-list to prevent unbounded growth.
    $batch_size       = 100;
    $offset           = 0;
    $timed_out        = false;

    do {
        // QC-R11-5: Explicit 'number' param. Never 'number' => 0.
        $site_ids = get_sites( array(
            'fields' => 'ids',
            'number' => $batch_size,
            'offset' => $offset,
        ) );

        if ( ! is_array( $site_ids ) || empty( $site_ids ) ) {
            break;
        }

        foreach ( $site_ids as $site_id ) {
            // QC-R11-4: Timeout guard — check BEFORE processing.
            $elapsed = time() - $uninstall_start;
            if ( $elapsed >= $time_budget ) {
                if ( count( $sites_skipped ) < $max_skip_ids ) {
                    $sites_skipped[] = (int) $site_id;
                }
                $timed_out = true;
                break;
            }

            $site_id = (int) $site_id;

            // QC-R11-2: Check switch_to_blog() return value.
            // On some WP versions, switch_to_blog() can return false if the
            // blog doesn't exist. Running cleanup in the wrong context would
            // corrupt the main site's data.
            $switched = switch_to_blog( $site_id );
            if ( false === $switched ) {
                error_log( sprintf(
                    '[Ontario Obituaries] Uninstall: switch_to_blog(%d) returned false — skipping.',
                    $site_id
                ) );
                if ( count( $sites_skipped ) < $max_skip_ids ) {
                    $sites_skipped[] = $site_id;
                }
                continue;
            }

            try {
                // QC-R12-3: Per-site elapsed-time check — replaces set_time_limit()
                // which is disabled on many shared hosts (safe_mode, disable_functions)
                // and generates E_WARNING when called in that context.
                // Instead, we measure wall-clock time after cleanup and break if
                // the per-site budget was exceeded.
                $site_start = time();
                ontario_obituaries_uninstall_site();
                $sites_cleaned++;

                // If this single site took longer than the per-site limit,
                // break to prevent further overrun.
                $site_elapsed = time() - $site_start;
                if ( $site_elapsed > $per_site_limit ) {
                    error_log( sprintf(
                        '[Ontario Obituaries] Uninstall: site %d took %ds (limit %ds) — breaking to prevent overrun.',
                        $site_id, $site_elapsed, $per_site_limit
                    ) );
                    $timed_out = true;
                }
            } catch ( \Throwable $e ) {
                error_log( sprintf(
                    '[Ontario Obituaries] Uninstall: error cleaning site %d: %s',
                    $site_id,
                    $e->getMessage()
                ) );
            } finally {
                // ALWAYS restore blog context, even on exception/error.
                restore_current_blog();
            }

            // QC-R12: Break if the per-site time check set $timed_out.
            if ( $timed_out ) {
                break;
            }
        }

        $offset += $batch_size;

        // QC-R11-4: If timed out, collect remaining site IDs for logging.
        if ( $timed_out ) {
            // Collect remaining sites from current batch.
            $remaining_in_batch = array();
            $found_skipped      = false;
            foreach ( $site_ids as $sid ) {
                if ( $found_skipped ) {
                    if ( count( $sites_skipped ) + count( $remaining_in_batch ) < $max_skip_ids ) {
                        $remaining_in_batch[] = (int) $sid;
                    }
                } elseif ( ! empty( $sites_skipped ) && (int) $sid === end( $sites_skipped ) ) {
                    $found_skipped = true;
                }
            }
            // Get remaining batches count (lightweight: IDs only).
            $more_sites = get_sites( array(
                'fields' => 'ids',
                'number' => $max_skip_ids,
                'offset' => $offset,
            ) );
            if ( is_array( $more_sites ) && ! empty( $more_sites ) ) {
                $space_left = $max_skip_ids - count( $sites_skipped ) - count( $remaining_in_batch );
                if ( $space_left > 0 ) {
                    $remaining_in_batch = array_merge(
                        $remaining_in_batch,
                        array_map( 'intval', array_slice( $more_sites, 0, $space_left ) )
                    );
                }
            }
            if ( ! empty( $remaining_in_batch ) ) {
                $sites_skipped = array_merge( $sites_skipped, $remaining_in_batch );
            }
            break;
        }

    } while ( count( $site_ids ) === $batch_size );

    // Network-level cleanup (wp_sitemeta).
    ontario_obituaries_uninstall_network();

    // Log summary if sites were skipped due to timeout.
    if ( ! empty( $sites_skipped ) ) {
        $sites_skipped = array_unique( array_slice( $sites_skipped, 0, $max_skip_ids ) );
        error_log( sprintf(
            '[Ontario Obituaries] Uninstall: cleaned %d sites in %ds. '
            . 'TIMEOUT: %d sites skipped (IDs: %s). '
            . 'Re-delete the plugin to clean remaining sites.',
            $sites_cleaned,
            time() - $uninstall_start,
            count( $sites_skipped ),
            implode( ', ', array_slice( $sites_skipped, 0, 20 ) )
            . ( count( $sites_skipped ) > 20 ? '...' : '' )
        ) );
    }
} else {
    // Single-site: run cleanup once.
    ontario_obituaries_uninstall_site();
}
