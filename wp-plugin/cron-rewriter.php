<?php
/**
 * Standalone Cron Rewriter — runs via server cron, NOT WordPress AJAX.
 *
 * Usage (cPanel Cron Job — every 5 minutes):
 *   /usr/local/bin/php /home/monaylnf/html/wp-content/plugins/ontario-obituaries/cron-rewriter.php >/dev/null 2>&1
 *
 * This script:
 *   1. Bootstraps WordPress (no browser, no AJAX timeout, no visitor needed)
 *   2. Processes 1 obituary at a time with 6-second delays
 *   3. Loops for up to 4 minutes, processing multiple batches
 *   4. Manages rate limits (pause + retry + exponential backoff)
 *   5. File-based locking — only one instance runs at a time
 *   6. Logs everything to WP debug log + CLI stdout
 *
 * Performance (Groq free tier):
 *   - 1 obituary at a time with 12s delay between requests
 *   - 4-minute runtime ≈ ~18 obituaries per cron run
 *   - Cron every 5 min = ~200 obituaries per hour
 *   - 47 pending ≈ ~15 minutes to clear
 *
 * Why not use WP-Cron or the AJAX button?
 *   - WP-Cron requires site visitors to trigger — unreliable with low traffic.
 *   - AJAX button has 90-second browser timeout — too short for large batches.
 *   - This script runs server-side with a 5-minute execution window.
 *
 * @package Ontario_Obituaries
 * @since   5.0.0
 */

// ── SECURITY: Block ALL web access. CLI only. ──────────────────────────────
// QC-R3/R4 FIX: Primary check is PHP_SAPI.  STDIN check removed because some
// legitimate CLI runners (e.g., proc_open, exec, some Docker entrypoints) may
// not define STDIN.  The SAPI check alone is definitive: 'cli' is only set
// when PHP is invoked from the command line or via the CLI binary.  CGI/FPM/
// Apache module SAPIs are always non-'cli' and will be blocked.
if ( PHP_SAPI !== 'cli' ) {
    http_response_code( 403 );
    die( 'CLI only.' );
}

// QC-R5/R6 FIX: Include-safety guard.  If another file ever require_once's this
// script (e.g., test harness), the constant gate prevents re-execution.
// Note on semantics: the guard only prevents the main processing loop and
// WordPress bootstrap from running twice.  Side-effect-free setup (constants,
// function defs) above this point is harmless on re-entry.  The lock file +
// shutdown function below are the authoritative single-instance mechanism.
if ( defined( 'ONTARIO_CLI_REWRITER_LOADED' ) ) {
    return; // Already loaded — skip main body.
}
define( 'ONTARIO_CLI_REWRITER_LOADED', true );

// ── Tell WordPress this is a cron/CLI context BEFORE loading ───────────────
define( 'DOING_CRON', true );
define( 'ONTARIO_CLI_REWRITER', true ); // Flag for class-ai-rewriter to use CLI-optimized settings.

// ── CLI-safe resource limits ───────────────────────────────────────────────
@set_time_limit( 300 );         // 5 minutes hard limit.
@ini_set( 'memory_limit', '256M' );

// ── Find and load WordPress ────────────────────────────────────────────────
$wp_load = dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) . '/wp-load.php';
if ( ! file_exists( $wp_load ) ) {
    fwrite( STDERR, "FATAL: wp-load.php not found at: {$wp_load}\n" );
    exit( 1 );
}

// Suppress any HTML output from WordPress loading.
ob_start();
require_once $wp_load;
ob_end_clean();

// ── Helper: timestamped log + flush ────────────────────────────────────────
function cron_log( $msg ) {
    $ts = gmdate( 'Y-m-d H:i:s' );
    $line = "[{$ts}] {$msg}\n";
    fwrite( STDOUT, $line );
    // Also write to WP debug log if available.
    if ( function_exists( 'ontario_obituaries_log' ) ) {
        ontario_obituaries_log( 'CLI-Rewriter: ' . $msg, 'info' );
    }
}

// ── FILE-BASED LOCK (no DB dependency) ─────────────────────────────────────
$lock_file = sys_get_temp_dir() . '/ontario_rewriter.lock';

if ( file_exists( $lock_file ) ) {
    $lock_age = time() - (int) filemtime( $lock_file );
    if ( $lock_age < 270 ) {
        // Another instance started less than 4.5 minutes ago — still running.
        cron_log( "SKIP: Another instance running ({$lock_age}s ago). Exiting." );
        exit( 0 );
    }
    // Stale lock (process crashed). Override.
    cron_log( "WARNING: Stale lock ({$lock_age}s old). Overriding." );
}

// Write lock with current PID so we can detect stale locks.
file_put_contents( $lock_file, getmypid() . "\n" . time() );

// Always clean up the lock on exit (even on fatal errors).
register_shutdown_function( function() use ( $lock_file ) {
    @unlink( $lock_file );
} );

// ── Load the rewriter class ────────────────────────────────────────────────
if ( ! class_exists( 'Ontario_Obituaries_AI_Rewriter' ) ) {
    $rewriter_file = __DIR__ . '/includes/class-ai-rewriter.php';
    if ( ! file_exists( $rewriter_file ) ) {
        cron_log( 'FATAL: includes/class-ai-rewriter.php not found.' );
        exit( 1 );
    }
    require_once $rewriter_file;
}

// Process 1 obituary at a time — simple, predictable, no rate-limit surprises.
$rewriter = new Ontario_Obituaries_AI_Rewriter( 1 );

// ── Pre-flight checks ──────────────────────────────────────────────────────
if ( ! $rewriter->is_configured() ) {
    cron_log( 'ABORT: Groq API key not configured. Set it in Settings > Ontario Obituaries.' );
    exit( 0 );
}

$pending = $rewriter->get_pending_count();
if ( $pending < 1 ) {
    cron_log( 'Nothing to do: 0 obituaries pending.' );
    exit( 0 );
}

cron_log( "START: {$pending} obituaries pending." );

// ── MAIN PROCESSING LOOP ──────────────────────────────────────────────────
$start_time           = time();
$max_runtime          = 240;  // 4 minutes — 60s buffer before next 5-min cron.
$total_ok             = 0;
$total_fail           = 0;
$total_processed      = 0;
$batch_num            = 0;
$consecutive_failures = 0;
$max_consecutive_fail = 3;    // Stop after 3 batches with zero successes.

while ( true ) {
    $elapsed = time() - $start_time;

    // ── Time guard: stop if <60s remaining (next batch needs ~80s) ──
    if ( $elapsed >= $max_runtime || ( $max_runtime - $elapsed ) < 60 ) {
        cron_log( sprintf( 'TIME: %ds elapsed, stopping to avoid overlap.', $elapsed ) );
        break;
    }

    // ── Check for remaining work ──
    $remaining = $rewriter->get_pending_count();
    if ( $remaining < 1 ) {
        cron_log( 'DONE: All obituaries processed!' );
        break;
    }

    $batch_num++;

    // ── Process ONE obituary ──
    $result = $rewriter->process_batch();

    $batch_ok   = isset( $result['succeeded'] ) ? (int) $result['succeeded'] : 0;
    $batch_fail = isset( $result['failed'] )    ? (int) $result['failed']    : 0;

    $total_ok        += $batch_ok;
    $total_fail      += $batch_fail;
    $total_processed += $batch_ok + $batch_fail;
    $elapsed          = time() - $start_time;
    $remaining_now    = $rewriter->get_pending_count();

    cron_log( sprintf(
        '#%d: %s (total: %d published, %d failed, %d remaining) [%ds]',
        $batch_num, $batch_ok > 0 ? 'PUBLISHED' : 'FAILED',
        $total_ok, $total_fail, $remaining_now, $elapsed
    ) );

    // ── Auth error — stop immediately ──
    if ( ! empty( $result['auth_error'] ) ) {
        cron_log( 'FATAL: API key invalid or all models blocked. Fix before next run.' );
        break;
    }

    // ── Track consecutive failures ──
    if ( $batch_ok === 0 ) {
        $consecutive_failures++;
        if ( $consecutive_failures >= $max_consecutive_fail ) {
            cron_log( sprintf(
                'ABORT: %d consecutive failures. Groq may be down or rate-limiting. Will retry next cron run.',
                $consecutive_failures
            ) );
            break;
        }
        sleep( 10 ); // Brief backoff after failure.
    } else {
        $consecutive_failures = 0;
        sleep( 12 ); // 12s pause = ~5 req/min, within 6,000 TPM.
    }
}

// ── Post-processing: cache purge + IndexNow ────────────────────────────────
if ( $total_ok > 0 ) {
    // Purge LiteSpeed cache.
    if ( function_exists( 'ontario_obituaries_purge_litespeed' ) ) {
        ontario_obituaries_purge_litespeed( 'ontario_obits' );
        cron_log( 'LiteSpeed cache purged.' );
    }

    // Submit newly published obituaries to IndexNow.
    if ( class_exists( 'Ontario_Obituaries_IndexNow' ) ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';
        $recent_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM `{$table}`
             WHERE status = 'published'
               AND ai_description IS NOT NULL AND ai_description != ''
               AND created_at >= %s
               AND suppressed_at IS NULL
             ORDER BY id DESC
             LIMIT 100",
            gmdate( 'Y-m-d H:i:s', strtotime( '-10 minutes' ) )
        ) );
        if ( ! empty( $recent_ids ) ) {
            $indexnow = new Ontario_Obituaries_IndexNow();
            $indexnow->submit_urls( $recent_ids );
            cron_log( sprintf( 'IndexNow: submitted %d URLs.', count( $recent_ids ) ) );
        }
    }
}

// ── Summary ────────────────────────────────────────────────────────────────
// BUG-H1 FIX (v5.0.5): Corrected rate calculation.
// Previous formula: $rate * 60 / max($runtime, 1) * 3600 / 60
//   expanded to: total_ok * 216000 / runtime² — nonsense (squared denominator).
// Correct formula: total_ok / runtime * 3600 = obituaries per hour.
$runtime  = time() - $start_time;
$per_hour = $runtime > 0 ? round( $total_ok / $runtime * 3600 ) : 0;
cron_log( sprintf(
    'FINISHED: %d published, %d failed, %ds runtime (~%d/hour). %d remaining.',
    $total_ok,
    $total_fail,
    $runtime,
    $per_hour,
    $rewriter->get_pending_count()
) );

// QC-R5/R6 FIX: return instead of exit(0).
// PHP manual (php.net/return): "If return is called from within the main script
// file, then script execution ends."  This is NOT a fatal — it is explicitly
// documented behaviour since PHP 4.  When executed directly via CLI (the normal
// cPanel cron path), return at file scope ends the process with exit code 0.
// When include()'d, it returns control to the caller without killing the process.
return;
