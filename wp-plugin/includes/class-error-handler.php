<?php
/**
 * Error Handling Foundation — Phase 1
 *
 * Provides three lightweight wrappers that standardize error detection and
 * logging across the plugin WITHOUT adding a database table.
 *
 * Every log entry includes: subsystem + error_code + context (obit_id, run_id)
 * so that any failure can be traced to its exact cause in the PHP error log.
 *
 * Wrappers:
 *   oo_safe_call( $subsystem, $callable, $fallback )  — catches Throwable
 *   oo_safe_http( $subsystem, $url, $args )            — wp_safe_remote_get + checks
 *   oo_db_check( $subsystem, $result, $code, $ctx )    — validates $wpdb return values
 *
 * Logging:
 *   oo_log( $level, $subsystem, $code, $message, $context )
 *     → routes through ontario_obituaries_log() for backward compatibility
 *     → always writes structured prefix for grep-ability
 *
 * Health counters (wp_options, no DB table):
 *   oo_health_increment( $code )           — bumps a counter in a transient
 *   oo_health_get_counts()                 — returns [ code => count ] for last 24h
 *   oo_health_record_critical( $code,$msg) — stores last critical in wp_options
 *   oo_health_record_success( $subsystem ) — records last success timestamp per subsystem
 *
 * Log deduplication:
 *   oo_log() suppresses repeated identical subsystem+code pairs for 5 minutes.
 *   The health counter still increments (so counts are accurate), but only the
 *   first occurrence writes to error_log (prevents log spam during outages).
 *
 * Run IDs:
 *   oo_run_id()  — generates/returns a unique ID for the current cron tick / request
 *
 * @package  OntarioObituaries
 * @since    6.0.0
 *
 * PHASE 1 SCOPE:
 *   - This file is loaded via require_once in ontario-obituaries.php.
 *   - Zero changes to existing data paths in the happy path.
 *   - Existing ontario_obituaries_log() is NOT replaced; oo_log() calls it.
 *   - No new DB table. Counters use transients + a single wp_option.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ═══════════════════════════════════════════════════════════════════
 *  RUN ID — unique per request / cron tick
 * ═══════════════════════════════════════════════════════════════════ */

/**
 * Generate or return the current run ID.
 *
 * A run ID is a short unique string that ties together every log entry
 * produced during a single cron tick, AJAX call, or page request.
 * Format: "r-<8-hex>" e.g. "r-a3f1c9b2"
 *
 * @since 6.0.0
 * @return string
 */
function oo_run_id() {
    static $id = null;
    if ( null === $id ) {
        $id = 'r-' . substr( md5( uniqid( wp_rand(), true ) ), 0, 8 );
    }
    return $id;
}

/* ═══════════════════════════════════════════════════════════════════
 *  STRUCTURED LOGGING
 * ═══════════════════════════════════════════════════════════════════ */

/**
 * Structured log entry.
 *
 * Produces a grep-friendly line in the PHP error log:
 *   [Ontario Obituaries][ERROR][SCRAPE][SCRAPE_HTTP_FAIL][r-a3f1c9b2] Message {context}
 *
 * Also calls the existing ontario_obituaries_log() for backward compat
 * (which respects the debug_logging setting for non-error levels).
 *
 * @since 6.0.0
 *
 * @param string $level      One of: debug, info, warning, error, critical.
 * @param string $subsystem  Short subsystem name: SCRAPE, REWRITE, IMAGE, CRON,
 *                           DEDUP, GOFUNDME, AUDIT, DB, HTTP, AJAX, TEMPLATE, SYSTEM.
 * @param string $code       Unique error code (e.g., 'SCRAPE_HTTP_FAIL').
 * @param string $message    Human-readable description.
 * @param array  $context    Optional metadata:
 *                             'obit_id'   => int
 *                             'source'    => string (class/adapter name)
 *                             'run_id'    => string (auto-filled if absent)
 *                             'url'       => string
 *                             'wp_error'  => WP_Error (auto-extracted)
 *                             'exception' => Throwable (auto-extracted)
 *                             'data'      => mixed (truncated to 500 chars)
 * @return void
 */
function oo_log( $level, $subsystem, $code, $message, $context = array() ) {
    // Auto-fill run_id.
    if ( empty( $context['run_id'] ) ) {
        $context['run_id'] = oo_run_id();
    }

    // ── QC Tweak 2: Deduplicate repeated errors ──────────────────────
    // Suppress writing the same subsystem+code to error_log for 5 minutes.
    // Health counter ALWAYS increments (so counts stay accurate).
    // Only the first occurrence and a periodic reminder hit the log file.
    //
    // QC Note: The dedupe key includes an optional discriminator (source_domain
    // or hook) so that simultaneous failures from different sources/URLs are
    // NOT collapsed into a single suppression window.  This preserves useful
    // diagnostic variety while still suppressing true duplicate spam.
    $is_actionable = in_array( $level, array( 'warning', 'error', 'critical' ), true );
    $is_duplicate  = false;

    if ( $is_actionable ) {
        // Always bump health counter — even for suppressed duplicates.
        oo_health_increment( $code );

        // Build a dedupe key that distinguishes different failure sources.
        // Priority: source_domain > hook > url hostname > subsystem+code only.
        $discriminator = '';
        if ( ! empty( $context['source'] ) ) {
            $discriminator = (string) $context['source'];          // e.g., 'remembering.ca'
        } elseif ( ! empty( $context['source_domain'] ) ) {
            $discriminator = (string) $context['source_domain'];
        } elseif ( ! empty( $context['hook'] ) ) {
            $discriminator = (string) $context['hook'];            // e.g., 'collection_event'
        } elseif ( ! empty( $context['url'] ) ) {
            $discriminator = (string) wp_parse_url( $context['url'], PHP_URL_HOST );
        }
        $dedupe_key = 'oo_dedupe_' . md5( $subsystem . $code . $discriminator );

        $last_seen  = get_transient( $dedupe_key );
        if ( false !== $last_seen ) {
            // Same subsystem+code+source was logged within the last 5 min → suppress.
            $is_duplicate = true;
        } else {
            // First occurrence in this window → allow log line, set 5-min cooldown.
            set_transient( $dedupe_key, time(), 5 * MINUTE_IN_SECONDS );
        }
    }

    // Extract WP_Error details.
    if ( isset( $context['wp_error'] ) && is_wp_error( $context['wp_error'] ) ) {
        $context['wp_error_code']    = $context['wp_error']->get_error_code();
        $context['wp_error_message'] = $context['wp_error']->get_error_message();
        unset( $context['wp_error'] );
    }

    // Extract Throwable details.
    if ( isset( $context['exception'] ) && $context['exception'] instanceof \Throwable ) {
        $context['exception_class']   = get_class( $context['exception'] );
        $context['exception_message'] = $context['exception']->getMessage();
        $context['exception_file']    = $context['exception']->getFile();
        $context['exception_line']    = $context['exception']->getLine();
        unset( $context['exception'] );
    }

    // Truncate 'data' if present.
    if ( isset( $context['data'] ) ) {
        $encoded = is_string( $context['data'] ) ? $context['data'] : wp_json_encode( $context['data'] );
        if ( strlen( $encoded ) > 500 ) {
            $context['data'] = substr( $encoded, 0, 497 ) . '...';
        } else {
            $context['data'] = $encoded;
        }
    }

    // ── QC Tweak 2: Skip log line if duplicate (counter already bumped above) ──
    if ( $is_duplicate ) {
        // Record critical even if duplicate (never miss a critical).
        if ( 'critical' === $level ) {
            oo_health_record_critical( $code, $message, $context );
        }
        return;
    }

    // Build structured log line.
    $context_str = '';
    if ( ! empty( $context ) ) {
        // Build compact key=value pairs for the log line.
        $parts = array();
        foreach ( $context as $k => $v ) {
            if ( is_null( $v ) ) {
                continue;
            }
            $parts[] = $k . '=' . ( is_scalar( $v ) ? $v : wp_json_encode( $v ) );
        }
        $context_str = ' {' . implode( ', ', $parts ) . '}';
    }

    $structured = sprintf(
        '[%s][%s][%s] %s%s',
        strtoupper( $level ),
        strtoupper( $subsystem ),
        $code,
        $message,
        $context_str
    );

    // Route through existing ontario_obituaries_log() for backward compat.
    // That function checks debug_logging setting and writes to error_log.
    if ( function_exists( 'ontario_obituaries_log' ) ) {
        // Map our levels to what the existing function expects.
        $legacy_level = $level;
        if ( 'critical' === $level ) {
            $legacy_level = 'error'; // Existing function only knows info/warning/error.
        }
        ontario_obituaries_log( $structured, $legacy_level );
    } else {
        // Fallback if called before main plugin loads.
        error_log( '[Ontario Obituaries]' . $structured );
    }

    // Record critical in wp_options for dashboard visibility.
    if ( 'critical' === $level ) {
        oo_health_record_critical( $code, $message, $context );
    }
}

/* ─── Convenience shortcuts ─── */

function oo_log_debug( $subsystem, $code, $msg, $ctx = array() ) {
    oo_log( 'debug', $subsystem, $code, $msg, $ctx );
}
function oo_log_info( $subsystem, $code, $msg, $ctx = array() ) {
    oo_log( 'info', $subsystem, $code, $msg, $ctx );
}
function oo_log_warning( $subsystem, $code, $msg, $ctx = array() ) {
    oo_log( 'warning', $subsystem, $code, $msg, $ctx );
}
function oo_log_error( $subsystem, $code, $msg, $ctx = array() ) {
    oo_log( 'error', $subsystem, $code, $msg, $ctx );
}
function oo_log_critical( $subsystem, $code, $msg, $ctx = array() ) {
    oo_log( 'critical', $subsystem, $code, $msg, $ctx );
}

/* ═══════════════════════════════════════════════════════════════════
 *  WRAPPER 1: oo_safe_call()
 *
 *  Catches Throwable (Exception + Error) around any callable.
 *  Always returns $fallback on failure; never crashes the caller.
 * ═══════════════════════════════════════════════════════════════════ */

/**
 * Execute a callable safely, catching all Throwable.
 *
 * Usage:
 *   $result = oo_safe_call( 'CRON', 'CRON_COLLECTION_CRASH', function() {
 *       return $collector->collect();
 *   }, array(), array( 'hook' => 'collection_event' ) );
 *
 * @since 6.0.0
 *
 * @param string   $subsystem Subsystem name for logging.
 * @param string   $code      Error code if exception occurs.
 * @param callable $callable  The function to execute.
 * @param mixed    $fallback  Value to return on failure (default null).
 * @param array    $context   Additional context for the log entry.
 * @return mixed              Return value of $callable, or $fallback on failure.
 */
function oo_safe_call( $subsystem, $code, $callable, $fallback = null, $context = array() ) {
    try {
        return call_user_func( $callable );
    } catch ( \Exception $e ) {
        $context['exception'] = $e;
        oo_log( 'error', $subsystem, $code, $e->getMessage(), $context );
        return $fallback;
    } catch ( \Error $e ) {
        $context['exception_class']   = get_class( $e );
        $context['exception_message'] = $e->getMessage();
        $context['exception_file']    = $e->getFile();
        $context['exception_line']    = $e->getLine();
        oo_log( 'critical', $subsystem, $code, 'Fatal: ' . $e->getMessage(), $context );
        return $fallback;
    }
}

/* ═══════════════════════════════════════════════════════════════════
 *  WRAPPER 2: oo_safe_http()
 *
 *  Wraps wp_safe_remote_get/head/post with:
 *    - is_wp_error check
 *    - HTTP status check (>= 400)
 *    - Structured logging on failure
 *    - Returns WP_Error on failure, response array on success
 *
 *  Does NOT change behavior on success (zero impact on happy path).
 * ═══════════════════════════════════════════════════════════════════ */

/**
 * Safe HTTP GET request with automatic error logging.
 *
 * Usage:
 *   $response = oo_safe_http_get( 'SCRAPE', $url, array( 'timeout' => 15 ), array( 'source' => 'legacy-com' ) );
 *   if ( is_wp_error( $response ) ) { return; } // already logged
 *   $body = wp_remote_retrieve_body( $response );
 *
 * @since 6.0.0
 *
 * @param string $subsystem  Subsystem name for logging.
 * @param string $url        The URL to fetch.
 * @param array  $args       Arguments for wp_safe_remote_get().
 * @param array  $context    Additional context for log entry.
 * @return array|WP_Error    Response array on success, WP_Error on failure.
 */
function oo_safe_http_get( $subsystem, $url, $args = array(), $context = array() ) {
    // Enforce safe defaults.
    $args = wp_parse_args( $args, array(
        'timeout'     => 15,
        'redirection' => 3,
        'sslverify'   => apply_filters( 'ontario_obituaries_sslverify', true ),
        'user-agent'  => 'Ontario-Obituaries/' . ONTARIO_OBITUARIES_VERSION . ' (WordPress/' . get_bloginfo( 'version' ) . ')',
    ) );

    $context['url'] = $url;

    $response = wp_safe_remote_get( esc_url_raw( $url, array( 'http', 'https' ) ), $args );

    // ── QC Tweak 4: Classify WP_Error failures consistently ─────────
    if ( is_wp_error( $response ) ) {
        $context['wp_error'] = $response;
        $wp_code = $response->get_error_code();
        $error_code = oo_classify_http_error( $subsystem, $wp_code, 'GET' );
        $context['wp_error_type'] = $wp_code; // e.g., 'http_request_failed', 'http_request_not_executed'
        oo_log( 'error', $subsystem, $error_code, 'GET failed: ' . $response->get_error_message(), $context );
        return $response;
    }

    $status = wp_remote_retrieve_response_code( $response );
    if ( $status >= 400 ) {
        $context['http_status'] = $status;
        $bucket   = ( $status >= 500 ) ? '5XX' : '4XX';
        $severity = ( $status >= 500 ) ? 'error' : 'warning';
        oo_log( $severity, $subsystem, $subsystem . '_HTTP_' . $bucket, "GET returned HTTP $status", $context );
        return new WP_Error( 'http_' . $status, "HTTP $status from $url", array( 'status' => $status ) );
    }

    return $response;
}

/**
 * Safe HTTP HEAD request with automatic error logging.
 *
 * Same pattern as oo_safe_http_get but uses HEAD method.
 *
 * @since 6.0.0
 *
 * @param string $subsystem  Subsystem name for logging.
 * @param string $url        The URL to check.
 * @param array  $args       Arguments for wp_safe_remote_head().
 * @param array  $context    Additional context for log entry.
 * @return array|WP_Error    Response array on success, WP_Error on failure.
 */
function oo_safe_http_head( $subsystem, $url, $args = array(), $context = array() ) {
    $args = wp_parse_args( $args, array(
        'timeout'     => 10,
        'redirection' => 3,
        'sslverify'   => apply_filters( 'ontario_obituaries_sslverify', true ),
        'user-agent'  => 'Ontario-Obituaries/' . ONTARIO_OBITUARIES_VERSION . ' (WordPress/' . get_bloginfo( 'version' ) . ')',
    ) );

    $context['url'] = $url;

    $response = wp_safe_remote_head( esc_url_raw( $url, array( 'http', 'https' ) ), $args );

    // ── QC Tweak 4: Classify WP_Error failures consistently ─────────
    if ( is_wp_error( $response ) ) {
        $context['wp_error'] = $response;
        $wp_code = $response->get_error_code();
        $error_code = oo_classify_http_error( $subsystem, $wp_code, 'HEAD' );
        $context['wp_error_type'] = $wp_code;
        oo_log( 'warning', $subsystem, $error_code, 'HEAD failed: ' . $response->get_error_message(), $context );
        return $response;
    }

    $status = wp_remote_retrieve_response_code( $response );
    if ( $status >= 400 ) {
        $context['http_status'] = $status;
        $bucket = ( $status >= 500 ) ? '5XX' : '4XX';
        oo_log( 'warning', $subsystem, $subsystem . '_HTTP_' . $bucket, "HEAD returned HTTP $status", $context );
        return new WP_Error( 'http_' . $status, "HTTP $status from $url", array( 'status' => $status ) );
    }

    return $response;
}

/**
 * Safe HTTP POST request with automatic error logging.
 *
 * @since 6.0.0
 *
 * @param string $subsystem  Subsystem name for logging.
 * @param string $url        The URL to POST to.
 * @param array  $args       Arguments for wp_safe_remote_post().
 * @param array  $context    Additional context for log entry.
 * @return array|WP_Error    Response array on success, WP_Error on failure.
 */
function oo_safe_http_post( $subsystem, $url, $args = array(), $context = array() ) {
    $args = wp_parse_args( $args, array(
        'timeout'     => 30,
        'redirection' => 3,
        'sslverify'   => apply_filters( 'ontario_obituaries_sslverify', true ),
        'user-agent'  => 'Ontario-Obituaries/' . ONTARIO_OBITUARIES_VERSION . ' (WordPress/' . get_bloginfo( 'version' ) . ')',
    ) );

    $context['url'] = $url;

    $response = wp_safe_remote_post( esc_url_raw( $url, array( 'http', 'https' ) ), $args );

    // ── QC Tweak 4: Classify WP_Error failures consistently ─────────
    if ( is_wp_error( $response ) ) {
        $context['wp_error'] = $response;
        $wp_code = $response->get_error_code();
        $error_code = oo_classify_http_error( $subsystem, $wp_code, 'POST' );
        $context['wp_error_type'] = $wp_code;
        oo_log( 'error', $subsystem, $error_code, 'POST failed: ' . $response->get_error_message(), $context );
        return $response;
    }

    $status = wp_remote_retrieve_response_code( $response );
    if ( $status >= 400 ) {
        $context['http_status'] = $status;
        $bucket   = ( $status >= 500 ) ? '5XX' : '4XX';
        $severity = ( $status >= 500 ) ? 'error' : 'warning';
        oo_log( $severity, $subsystem, $subsystem . '_HTTP_' . $bucket, "POST returned HTTP $status", $context );
        return new WP_Error( 'http_' . $status, "HTTP $status from $url", array( 'status' => $status ) );
    }

    return $response;
}

/* ═══════════════════════════════════════════════════════════════════
 *  HTTP ERROR CLASSIFIER (QC Tweak 4)
 *
 *  Maps WP_Error codes from wp_remote_* into consistent, greppable
 *  error codes. Distinguishes timeout, DNS, SSL, and connection failures.
 * ═══════════════════════════════════════════════════════════════════ */

/**
 * Classify a WP_Error code into a consistent plugin error code.
 *
 * WordPress HTTP API error codes include:
 *   'http_request_failed'         → generic (often timeout or cURL error)
 *   'http_request_not_executed'   → request blocked by filter/pre-check
 *
 * cURL error strings (embedded in error messages) are also detected:
 *   'Could not resolve host'      → DNS failure
 *   'Connection timed out'        → Timeout
 *   'SSL certificate problem'     → SSL failure
 *   'Connection refused'          → Port/firewall
 *
 * @since 6.0.0
 *
 * @param string $subsystem Plugin subsystem (e.g., 'SCRAPE').
 * @param string $wp_code   WP_Error code (e.g., 'http_request_failed').
 * @param string $method    HTTP method (GET/HEAD/POST) for the code prefix.
 * @return string            Classified error code (e.g., 'SCRAPE_HTTP_TIMEOUT').
 */
function oo_classify_http_error( $subsystem, $wp_code, $method = 'GET' ) {
    // Known WP_Error codes → specific classification.
    $map = array(
        'http_request_not_executed' => 'HTTP_BLOCKED',
    );
    if ( isset( $map[ $wp_code ] ) ) {
        return $subsystem . '_' . $map[ $wp_code ];
    }

    // For 'http_request_failed' (the catch-all), inspect the error message.
    // We can't get the message here directly, but the caller passes it via context.
    // So we classify based on the wp_code alone → generic HTTP_WP_ERROR.
    // The caller's log message will contain the specific cURL error string,
    // and wp_error_type in context provides the raw code for filtering.
    return $subsystem . '_HTTP_WP_ERROR';
}

/* ═══════════════════════════════════════════════════════════════════
 *  WRAPPER 3: oo_db_check()
 *
 *  Validates a $wpdb method return value and logs on failure.
 *  Does NOT wrap the call itself — just checks the result.
 *  Returns true/false so callers can branch on failure.
 * ═══════════════════════════════════════════════════════════════════ */

/**
 * Check a $wpdb operation result and log failures.
 *
 * Usage:
 *   $result = $wpdb->insert( $table, $data );
 *   if ( ! oo_db_check( 'SCRAPE', $result, 'DB_INSERT_FAIL', 'Failed to insert obituary', array( 'obit_id' => $id ) ) ) {
 *       return false; // already logged
 *   }
 *
 * For $wpdb->insert/update/delete/replace:
 *   Returns false (int 0 is also falsy for delete/update of 0 rows which is not an error).
 *   We check for `false === $result` specifically.
 *
 * For $wpdb->query():
 *   Returns false on error, int on success (including 0 for no rows affected).
 *
 * For $wpdb->get_var/get_row/get_results:
 *   Returns null on no result, which is often valid. Only $wpdb->last_error tells us.
 *
 * @since 6.0.0
 *
 * @param string $subsystem  Subsystem name for logging.
 * @param mixed  $result     Return value from $wpdb method.
 * @param string $code       Error code (e.g., 'DB_INSERT_FAIL').
 * @param string $message    Human-readable description.
 * @param array  $context    Additional context.
 * @return bool              True if operation succeeded, false if it failed.
 */
function oo_db_check( $subsystem, $result, $code, $message = '', $context = array() ) {
    global $wpdb;

    // $wpdb->insert/update/delete/replace return false on failure.
    // $wpdb->query returns false on error.
    // We check for strict false, not just falsy (0 rows affected is valid).
    if ( false === $result ) {
        $context['wpdb_last_error'] = $wpdb->last_error;

        // ── QC Tweak 1: Redact SQL by default ────────────────────────
        // $wpdb->last_query can contain PII, API keys, or large strings.
        // Only log the full query when OO_DEBUG_LOG_SQL is explicitly true.
        // Otherwise log a safe summary: query type + table name hint.
        if ( defined( 'OO_DEBUG_LOG_SQL' ) && OO_DEBUG_LOG_SQL ) {
            $context['wpdb_last_query'] = substr( $wpdb->last_query, 0, 300 );
        } else {
            $context['wpdb_query_hint'] = oo_redact_query( $wpdb->last_query );
        }

        oo_log( 'error', $subsystem, $code, $message ?: 'Database operation failed', $context );
        return false;
    }

    // Also check if $wpdb->last_error was set (some operations return non-false but still error).
    if ( ! empty( $wpdb->last_error ) ) {
        $context['wpdb_last_error'] = $wpdb->last_error;

        if ( defined( 'OO_DEBUG_LOG_SQL' ) && OO_DEBUG_LOG_SQL ) {
            $context['wpdb_last_query'] = substr( $wpdb->last_query, 0, 300 );
        } else {
            $context['wpdb_query_hint'] = oo_redact_query( $wpdb->last_query );
        }

        oo_log( 'warning', $subsystem, $code . '_PARTIAL', ( $message ?: 'DB operation' ) . ' (with warning)', $context );
        // Return true because the operation technically completed.
        return true;
    }

    return true;
}

/**
 * Redact a SQL query to a safe summary.
 *
 * Extracts the query type (SELECT/INSERT/UPDATE/DELETE) and table name,
 * strips everything else. Never logs VALUES, WHERE conditions, or data.
 *
 * Example: "INSERT IGNORE INTO `wp_ontario_obituaries` (name, ...) VALUES (...)" → "INSERT INTO wp_ontario_obituaries"
 *
 * @since 6.0.0
 * @param string $query Raw SQL query.
 * @return string       Redacted summary (e.g., "INSERT INTO wp_ontario_obituaries").
 */
function oo_redact_query( $query ) {
    $query = trim( $query );
    // Match: verb [IGNORE|INTO|FROM|SET] `table_name`
    if ( preg_match( '/^(SELECT|INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP)\b/i', $query, $verb ) ) {
        $type = strtoupper( $verb[1] );
        // Try to extract table name (with or without backticks).
        if ( preg_match( '/(?:FROM|INTO|UPDATE|TABLE)\s+`?([a-zA-Z0-9_]+)`?/i', $query, $table ) ) {
            return $type . ' ' . $table[1];
        }
        return $type . ' (table unknown)';
    }
    return 'QUERY (redacted)';
}

/* ═══════════════════════════════════════════════════════════════════
 *  HEALTH COUNTERS (transient-based, no DB table)
 *
 *  Stores a rolling 24h window of error code counts in a transient.
 *  A single wp_option records the last critical event for dashboard display.
 *  This is lightweight enough for shared hosting.
 * ═══════════════════════════════════════════════════════════════════ */

/**
 * Increment an error code counter.
 *
 * Stored in a transient with 24h TTL. Structure:
 *   { 'DB_INSERT_FAIL' => 3, 'SCRAPE_HTTP_FAIL' => 12, ... }
 *
 * @since 6.0.0
 * @param string $code Error code.
 */
function oo_health_increment( $code ) {
    $key    = 'oo_error_counts_24h';
    $counts = get_transient( $key );
    if ( ! is_array( $counts ) ) {
        $counts = array();
    }

    if ( isset( $counts[ $code ] ) ) {
        $counts[ $code ]++;
    } else {
        $counts[ $code ] = 1;
    }

    // Cap individual codes to prevent runaway counting.
    if ( $counts[ $code ] > 9999 ) {
        $counts[ $code ] = 9999;
    }

    // Cap total unique codes to prevent unbounded growth.
    if ( count( $counts ) > 100 ) {
        // Keep only the top 100 by count.
        arsort( $counts );
        $counts = array_slice( $counts, 0, 100, true );
    }

    set_transient( $key, $counts, DAY_IN_SECONDS );
}

/**
 * Get the current 24h error counts.
 *
 * @since 6.0.0
 * @return array [ 'code' => count, ... ] or empty array.
 */
function oo_health_get_counts() {
    $counts = get_transient( 'oo_error_counts_24h' );
    return is_array( $counts ) ? $counts : array();
}

/**
 * Get summary totals by severity level.
 *
 * @since 6.0.0
 * @return array [ 'warnings' => int, 'errors' => int, 'critical' => int, 'total' => int ]
 */
function oo_health_get_summary() {
    $counts   = oo_health_get_counts();
    $total    = array_sum( $counts );
    $last     = get_option( 'oo_last_critical', array() );

    // ── QC Tweak 5: Include last_success and last_ran timestamps ─────
    $successes = get_option( 'oo_last_success', array() );
    $ran       = get_option( 'oo_last_ran', array() );

    return array(
        'total_issues_24h'     => $total,
        'unique_codes'         => count( $counts ),
        'top_codes'            => array_slice( $counts, 0, 5, true ), // Top 5 by count.
        'last_critical'        => $last,
        'last_ran'             => is_array( $ran ) ? $ran : array(),       // e.g., { 'REWRITE' => '2026-02-20 14:32:00' }
        'last_success'         => is_array( $successes ) ? $successes : array(), // e.g., { 'SCRAPE' => '2026-02-20 14:32:00' }
        'pipeline_healthy'     => oo_is_pipeline_healthy( $successes ),
    );
}

/**
 * Record that a cron handler ran to clean completion (even with 0 work).
 *
 * "Ran" means the handler executed without crashing — it may have found nothing
 * to process (no pending, rate-limited, feature disabled), but the code path
 * completed. This prevents the health dashboard from reading "dead" when the
 * pipeline is actually running but idle.
 *
 * Usage:
 *   oo_health_record_ran( 'REWRITE' );  // end of ai_rewrite_batch(), even if 0 published
 *   oo_health_record_ran( 'DEDUP' );    // end of maybe_cleanup_duplicates()
 *
 * @since 6.0.0
 * @param string $subsystem Subsystem name (SCRAPE, REWRITE, IMAGE, DEDUP, etc.).
 */
function oo_health_record_ran( $subsystem ) {
    $ran = get_option( 'oo_last_ran', array() );
    if ( ! is_array( $ran ) ) {
        $ran = array();
    }
    $ran[ strtoupper( $subsystem ) ] = current_time( 'mysql', true );
    update_option( 'oo_last_ran', $ran, false ); // false = don't autoload.
}

/**
 * Record a successful operation for a subsystem (made progress).
 *
 * Called when a cron handler actually did useful work (published obituaries,
 * linked GoFundMe, etc.). Distinguished from oo_health_record_ran() which
 * fires even on idle runs.
 *
 * Usage:
 *   oo_health_record_success( 'SCRAPE' );   // after collection found new records
 *   oo_health_record_success( 'REWRITE' );  // after rewrite published > 0
 *   oo_health_record_success( 'IMAGE' );    // after image batch processed > 0
 *
 * @since 6.0.0
 * @param string $subsystem Subsystem name (SCRAPE, REWRITE, IMAGE, DEDUP, etc.).
 */
function oo_health_record_success( $subsystem ) {
    $successes = get_option( 'oo_last_success', array() );
    if ( ! is_array( $successes ) ) {
        $successes = array();
    }
    $successes[ strtoupper( $subsystem ) ] = current_time( 'mysql', true );
    update_option( 'oo_last_success', $successes, false ); // false = don't autoload.
}

/**
 * Check if the pipeline is healthy based on last success timestamps.
 *
 * "Healthy" means collection ran within the last 2 hours AND rewrite ran within 2 hours.
 * Returns false if either is missing or stale, indicating a stuck pipeline.
 *
 * @since 6.0.0
 * @param array $successes Last success timestamps by subsystem.
 * @return bool            True if pipeline appears healthy.
 */
function oo_is_pipeline_healthy( $successes ) {
    if ( empty( $successes ) ) {
        return false;
    }
    $threshold = 2 * HOUR_IN_SECONDS;
    $now       = time();

    foreach ( array( 'SCRAPE', 'REWRITE' ) as $required ) {
        if ( empty( $successes[ $required ] ) ) {
            return false; // Never ran.
        }
        $last_time = strtotime( $successes[ $required ] );
        if ( false === $last_time || ( $now - $last_time ) > $threshold ) {
            return false; // Stale.
        }
    }
    return true;
}

/**
 * Record the most recent critical error in wp_options.
 *
 * Only stores one entry (the latest). Minimal DB footprint.
 *
 * @since 6.0.0
 * @param string $code    Error code.
 * @param string $message Description.
 * @param array  $context Additional context.
 */
function oo_health_record_critical( $code, $message, $context = array() ) {
    update_option( 'oo_last_critical', array(
        'code'    => $code,
        'message' => substr( $message, 0, 200 ),
        'time'    => current_time( 'mysql', true ),
        'run_id'  => isset( $context['run_id'] ) ? $context['run_id'] : oo_run_id(),
    ), false ); // false = don't autoload.
}

/**
 * Clean up all health data. Called from uninstall.php.
 *
 * @since 6.0.0
 */
function oo_health_cleanup() {
    delete_transient( 'oo_error_counts_24h' );
    delete_option( 'oo_last_critical' );
    delete_option( 'oo_last_success' );
    delete_option( 'oo_last_ran' );

    // Clean up any dedupe transients (pattern: oo_dedupe_<md5>).
    // These are short-lived (5 min TTL) and will expire on their own,
    // but we clean them explicitly on uninstall for completeness.
    global $wpdb;
    $wpdb->query( "DELETE FROM `{$wpdb->prefix}options` WHERE option_name LIKE '_transient_oo_dedupe_%' OR option_name LIKE '_transient_timeout_oo_dedupe_%'" );
}
