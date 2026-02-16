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

global $wpdb;

// ── 1. Drop helper tables (NOT the main obituaries table) ────────────
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

// ── 2. Delete ALL plugin options ─────────────────────────────────────
// BUG-H2 FIX (v5.0.5): Comprehensive list — every option written by the
// plugin.  Grouped by category for auditability.
//
// INVENTORY METHOD: grep -rn "update_option\|add_option\|get_option"
//   across all plugin PHP files, extracting 'ontario_obituaries_*' keys.
//   Last audited: 2026-02-16 (v5.0.5).
$option_keys = array(
    // ── Core settings & state ────────────────────────────────────────
    'ontario_obituaries_settings',
    // 'ontario_obituaries_db_version',       // PRESERVED — prevents migration re-run on reinstall
    'ontario_obituaries_deployed_version',     // BUG-H2 FIX: was missing
    'ontario_obituaries_last_collection',
    'ontario_obituaries_last_scrape_errors',
    'ontario_obituaries_region_urls',
    'ontario_obituaries_page_id',
    'ontario_obituaries_menu_added',
    'ontario_obituaries_menu_v2',

    // ── Legacy options (may not exist on newer installs) ─────────────
    'ontario_obituaries_enable_facebook',
    'ontario_obituaries_fb_app_id',
    'ontario_obituaries_last_scrape',
    'ontario_obituaries_dedup_v1',

    // ── Sensitive API keys & credentials ─────────────────────────────
    // BUG-H2 FIX (v5.0.5): These were NOT deleted before, leaving API
    // keys exposed in wp_options after plugin removal.
    'ontario_obituaries_groq_api_key',         // Groq LLM API key
    'ontario_obituaries_google_ads_credentials', // Google Ads OAuth tokens
    'ontario_obituaries_indexnow_key',         // IndexNow API key

    // ── Feature data ─────────────────────────────────────────────────
    // BUG-H2 FIX (v5.0.5): was missing
    'ontario_obituaries_chatbot_settings',     // Chatbot configuration
    'ontario_obituaries_leads',                // Lead capture data (array of leads)

    // ── Reset/rescan session state ───────────────────────────────────
    // BUG-H2 FIX (v5.0.5): was missing
    'ontario_obituaries_reset_session',
    'ontario_obituaries_reset_progress',
    'ontario_obituaries_reset_last_result',
    'ontario_obituaries_rescan_only_last_result',

    // ── BUG-C4 dedup lock & throttle log ─────────────────────────────
    // Added in v5.0.4 (BUG-C4 fix).  autoload=no, zero runtime cost,
    // but should still be cleaned up.
    'ontario_obituaries_dedup_lock',
    'ontario_obituaries_dedup_throttle_log',
    'ontario_obituaries_cron_secret',           // QC-R6: Optional /cron auth secret
);

foreach ( $option_keys as $key ) {
    delete_option( $key );
}

// Also delete versioned dedup flags (e.g., ontario_obituaries_dedup_3.1.0)
// Uses a LIKE query because version numbers are dynamic.
$wpdb->query(
    "DELETE FROM `{$wpdb->options}` WHERE option_name LIKE 'ontario\_obituaries\_dedup\_%'"
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// ── 3. Delete ALL plugin transients ──────────────────────────────────
// BUG-H2 FIX (v5.0.5): Added rewriter-related transients that were missing.
//
// INVENTORY METHOD: grep -rn "set_transient\|delete_transient" across all
//   plugin PHP files.  Last audited: 2026-02-16 (v5.0.5).
$transient_keys = array(
    // ── Core transients ──────────────────────────────────────────────
    'ontario_obituaries_activation_notice',
    'ontario_obituaries_table_exists',
    'ontario_obituaries_locations_cache',
    'ontario_obituaries_funeral_homes_cache',

    // ── Cron & rewriter transients ───────────────────────────────────
    // BUG-H2 FIX (v5.0.5): was missing
    'ontario_obituaries_last_cron_spawn',
    'ontario_obituaries_rewriter_running',     // Rewriter lock transient
    'ontario_obituaries_rewriter_scheduling',  // Rewriter scheduling guard
    'ontario_obituaries_rewriter_throttle',    // Rewriter throttle transient
    'ontario_obituaries_cron_rest_limiter',    // QC-R5: REST /cron rate limiter
);

foreach ( $transient_keys as $key ) {
    delete_transient( $key );
}

// ── 4. Clear ALL scheduled cron hooks ────────────────────────────────
// BUG-H7 FIX (v5.0.5): Previously only 2 of 8 hooks were cleared.
// Orphan cron events from uncleared hooks fire WordPress errors and
// can trigger PHP fatal errors if the plugin files are gone.
//
// INVENTORY METHOD: grep -rn "wp_schedule_event\|wp_schedule_single_event"
//   across all plugin PHP files.  Last audited: 2026-02-16 (v5.0.5).
$cron_hooks = array(
    // ── Collection ───────────────────────────────────────────────────
    'ontario_obituaries_collection_event',     // Recurring collection (hourly/daily/etc.)
    'ontario_obituaries_initial_collection',   // One-shot initial collection after activation

    // ── AI processing ────────────────────────────────────────────────
    // BUG-H7 FIX (v5.0.5): was missing
    'ontario_obituaries_ai_rewrite_batch',     // AI rewriter batch processing
    'ontario_obituaries_gofundme_batch',       // GoFundMe link matching

    // ── Auditing & analytics ─────────────────────────────────────────
    // BUG-H7 FIX (v5.0.5): was missing
    'ontario_obituaries_authenticity_audit',   // AI authenticity checker
    'ontario_obituaries_google_ads_analysis',  // Google Ads daily analysis

    // ── Dedup (BUG-C4, v5.0.4) ──────────────────────────────────────
    // BUG-H7 FIX (v5.0.5): was missing
    'ontario_obituaries_dedup_daily',          // Daily recurring dedup
    'ontario_obituaries_dedup_once',           // One-shot post-scrape dedup
);

foreach ( $cron_hooks as $hook ) {
    wp_clear_scheduled_hook( $hook );
}

// ── 5. Flush rewrite rules ───────────────────────────────────────────
// P1-6 FIX: SEO class registers /obituaries/ontario/ routes that must be
// removed from the rewrite rules table.
flush_rewrite_rules();
