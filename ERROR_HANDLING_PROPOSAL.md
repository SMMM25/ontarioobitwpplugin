# Ontario Obituaries v6.0 — Full Error Handling System

## Proposal for QC Review

**Date:** 2026-02-20
**Scope:** 38 PHP files, 22,893 lines of code
**Version target:** v6.0.0 (major — touches every subsystem)

---

## 1. Current State Audit

### 1.1 Quantitative Assessment

| Metric | Current | Target |
|--------|---------|--------|
| PHP files | 38 | 38 + 2 new |
| Files with zero error handling | **15 / 38 (39%)** | 0 / 40 |
| Database operations | 177 | 177 (all checked) |
| Unchecked DB operations | **~120 (68%)** | 0 |
| HTTP remote calls | 21 | 21 (all checked) |
| HTTP calls with `is_wp_error` | **2 (10%)** | 21 (100%) |
| AJAX handlers | 29 | 29 (all nonce-verified) |
| AJAX handlers with nonce checks | **~3 (10%)** | 29 (100%) |
| try/catch blocks | 23 (in 13 files) | ~80+ (in all files) |
| `ontario_obituaries_log()` calls | 169 | 169 + ~200 new |
| Raw `error_log()` calls | 9 | 0 (all routed through logger) |
| Dedicated error handler class | **0** | 1 |
| Admin health dashboard | **0** | 1 |

### 1.2 Files with ZERO Error Handling

These files have no try/catch, no WP_Error checks, and no structured error responses:

```
cron-rewriter.php                              (cron entry point)
includes/class-ontario-obituaries-admin.php    (admin settings page)
includes/class-ontario-obituaries-ajax.php     (ALL AJAX handlers)
includes/class-ontario-obituaries-seo.php      (SEO metadata + sitemaps)
includes/pipelines/class-suppression-manager.php (suppression pipeline)
includes/sources/class-adapter-dignity-memorial.php (scraper adapter)
includes/sources/class-adapter-generic-html.php     (scraper adapter)
includes/sources/class-adapter-legacy-com.php       (scraper adapter)
mu-plugins/monaco-site-hardening.php           (security hardening)
templates/obituaries.php                       (frontend grid)
templates/obituary-detail.php                  (frontend detail)
templates/seo/hub-city.php                     (SEO template)
templates/seo/hub-ontario.php                  (SEO template)
templates/seo/wrapper.php                      (SEO template)
```

### 1.3 Critical Gaps Identified

**GAP-1: AJAX handlers have no nonce verification**
29 AJAX endpoints registered, only ~3 verify nonces. Any logged-in user (or CSRF attack) can trigger scrapes, deletes, resets, test data injection, and chatbot email sends.

**GAP-2: Database operations are unchecked**
~120 of 177 `$wpdb->` calls do not check return values. A failed INSERT/UPDATE silently returns `false`; the caller continues assuming success. This is how "ghost" records appear.

**GAP-3: HTTP calls lack error handling**
Only 2 of 21 remote HTTP calls check `is_wp_error()`. A DNS failure or timeout during scraping silently produces `null` values that propagate into the database.

**GAP-4: No centralized error tracking**
Errors are written to PHP's `error_log` (server log file). There is:
- No admin-visible error dashboard
- No error counters or rate detection
- No alerting (email/webhook on repeated failures)
- No structured error codes
- No error context (which obituary, which source, which cron tick)

**GAP-5: Cron failures are invisible**
If any cron job (collection, rewriting, image migration, dedup, GoFundMe, authenticity) fails, the only evidence is a line buried in the server log. The admin panel shows no indication.

**GAP-6: Template errors crash the page**
A database error in `templates/obituaries.php` or `obituary-detail.php` produces a fatal PHP error visible to site visitors instead of a graceful fallback.

---

## 2. Proposed Architecture

### 2.1 New Files

```
wp-plugin/
  includes/
    class-error-handler.php          (NEW - centralized error handler)
    class-health-monitor.php         (NEW - admin health dashboard)
```

### 2.2 Core Design: `Ontario_Obituaries_Error_Handler`

A singleton class that replaces scattered `error_log()` calls with structured, leveled, contextual logging that writes to both the PHP error log AND a custom database table for admin visibility.

```
                    +---------------------------+
                    |  Error Handler (singleton) |
                    +---------------------------+
                    |  log( level, code, msg,   |
                    |        context )           |
                    |  get_recent_errors()       |
                    |  get_error_counts()        |
                    |  maybe_send_alert()        |
                    |  cleanup_old_entries()     |
                    +---------------------------+
                           |
              +------------+-------------+
              |            |             |
         PHP error_log  DB table    WP transient
         (always)       (structured) (rate alerts)
```

### 2.3 Error Levels

| Level | Constant | When | Admin visibility |
|-------|----------|------|------------------|
| `debug` | `LEVEL_DEBUG` | Verbose flow tracing (cron ticks, batch counts) | Hidden unless debug mode on |
| `info` | `LEVEL_INFO` | Normal operations (scrape completed, image localized) | Health dashboard only |
| `warning` | `LEVEL_WARNING` | Recoverable issues (retry, skip, rate limit) | Yellow badge in admin bar |
| `error` | `LEVEL_ERROR` | Operation failed (DB write failed, HTTP 500) | Red badge in admin bar |
| `critical` | `LEVEL_CRITICAL` | System-level failure (table missing, cron died) | Red badge + email alert |

### 2.4 Error Codes (Namespaced)

Every error gets a unique code for filtering and dashboarding:

```
SCRAPE_HTTP_FAIL        Source returned non-200
SCRAPE_PARSE_FAIL       HTML parsing returned 0 results
SCRAPE_ADAPTER_CRASH    Adapter threw exception
DB_INSERT_FAIL          $wpdb->insert() returned false
DB_UPDATE_FAIL          $wpdb->update() returned false
DB_QUERY_FAIL           $wpdb->query() returned false
DB_TABLE_MISSING        Required table does not exist
HTTP_TIMEOUT            wp_remote_* timed out
HTTP_SSL_FAIL           SSL verification failed
HTTP_DNS_FAIL           Could not resolve host
CRON_LOCK_STUCK         Cron lock older than 2x interval
CRON_MISSED             Expected cron didn't run within window
IMAGE_DOWNLOAD_FAIL     Image download/validation failed
IMAGE_WRITE_FAIL        File write or rename failed
AI_GROQ_FAIL            Groq API returned error
AI_RATE_LIMIT           Groq rate limit hit
AI_PARSE_FAIL           AI response couldn't be parsed
AJAX_NONCE_FAIL         Nonce verification failed
AJAX_PERMISSION_FAIL    Capability check failed
TEMPLATE_RENDER_FAIL    Template caught exception
SEO_SITEMAP_FAIL        Sitemap generation failed
GOFUNDME_SEARCH_FAIL    GoFundMe lookup failed
DEDUP_FAIL              Deduplication batch failed
SUPPRESSION_FAIL        Suppression rule application failed
SCHEMA_MISMATCH         Expected column missing
UNINSTALL_PARTIAL       Cleanup didn't complete fully
```

---

## 3. Implementation Plan — File by File

### 3.1 New: `includes/class-error-handler.php` (est. ~350 lines)

```php
<?php
/**
 * Centralized Error Handler for Ontario Obituaries
 *
 * Provides structured, leveled logging with DB persistence,
 * admin visibility, and optional email alerts.
 *
 * @since 6.0.0
 */
class Ontario_Obituaries_Error_Handler {

    /* ─── Singleton ─── */
    private static $instance = null;

    /* ─── Constants ─── */
    const TABLE_SUFFIX   = 'ontario_obituaries_errors';
    const MAX_ENTRIES    = 5000;       // keep last 5000 rows
    const CLEANUP_HOOK   = 'ontario_obituaries_error_cleanup';
    const ALERT_COOLDOWN = 3600;       // 1 alert per hour max
    const LEVEL_DEBUG    = 'debug';
    const LEVEL_INFO     = 'info';
    const LEVEL_WARNING  = 'warning';
    const LEVEL_ERROR    = 'error';
    const LEVEL_CRITICAL = 'critical';

    /* ─── Severity rank (for filtering) ─── */
    private static $severity = [
        'debug'    => 0,
        'info'     => 1,
        'warning'  => 2,
        'error'    => 3,
        'critical' => 4,
    ];

    /* ─── In-memory buffer for batch writes ─── */
    private $buffer = [];
    private $buffer_limit = 50;

    /**
     * Get singleton instance.
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Flush buffer on shutdown (catches all buffered logs)
        add_action( 'shutdown', [ $this, 'flush_buffer' ] );
    }

    /* ═══════════════════════════════════════════
     *  PUBLIC API
     * ═══════════════════════════════════════════ */

    /**
     * Log an event.
     *
     * @param string $level   One of: debug, info, warning, error, critical.
     * @param string $code    Namespaced error code (e.g., 'DB_INSERT_FAIL').
     * @param string $message Human-readable description.
     * @param array  $context Optional associative array of metadata:
     *                        - 'obit_id'     => int
     *                        - 'source'      => string (class name or adapter)
     *                        - 'file'        => string (__FILE__)
     *                        - 'line'        => int    (__LINE__)
     *                        - 'wp_error'    => WP_Error object
     *                        - 'exception'   => Exception object
     *                        - 'data'        => mixed  (extra payload, truncated at 1KB)
     * @return void
     */
    public static function log( $level, $code, $message, $context = [] ) {
        $self = self::instance();

        // Always write to PHP error log (backward compatible)
        $prefix = sprintf( '[Ontario Obituaries][%s][%s]', strtoupper( $level ), $code );
        error_log( $prefix . ' ' . $message );

        // Skip DB writes for debug unless debug mode is on
        $settings = function_exists('ontario_obituaries_get_settings')
            ? ontario_obituaries_get_settings() : [];
        if ( 'debug' === $level && empty( $settings['debug_logging'] ) ) {
            return;
        }

        // Extract exception/WP_Error details into context
        if ( isset( $context['exception'] ) && $context['exception'] instanceof \Exception ) {
            $context['exception_class']   = get_class( $context['exception'] );
            $context['exception_message'] = $context['exception']->getMessage();
            $context['exception_file']    = $context['exception']->getFile();
            $context['exception_line']    = $context['exception']->getLine();
            unset( $context['exception'] ); // don't serialize full object
        }
        if ( isset( $context['wp_error'] ) && is_wp_error( $context['wp_error'] ) ) {
            $context['wp_error_code']    = $context['wp_error']->get_error_code();
            $context['wp_error_message'] = $context['wp_error']->get_error_message();
            unset( $context['wp_error'] );
        }

        // Buffer for batch insert
        $self->buffer[] = [
            'level'      => $level,
            'code'       => substr( $code, 0, 50 ),
            'message'    => substr( $message, 0, 500 ),
            'context'    => substr( wp_json_encode( $context ), 0, 1024 ),
            'created_at' => current_time( 'mysql', true ), // UTC
        ];

        // Flush if buffer full OR if error/critical (immediate)
        if ( count( $self->buffer ) >= $self->buffer_limit
             || self::$severity[ $level ] >= self::$severity['error'] ) {
            $self->flush_buffer();
        }

        // Alert on critical
        if ( 'critical' === $level ) {
            $self->maybe_send_alert( $code, $message, $context );
        }
    }

    /**
     * Convenience methods.
     */
    public static function debug( $code, $msg, $ctx = [] )    { self::log( 'debug', $code, $msg, $ctx ); }
    public static function info( $code, $msg, $ctx = [] )     { self::log( 'info', $code, $msg, $ctx ); }
    public static function warning( $code, $msg, $ctx = [] )  { self::log( 'warning', $code, $msg, $ctx ); }
    public static function error( $code, $msg, $ctx = [] )    { self::log( 'error', $code, $msg, $ctx ); }
    public static function critical( $code, $msg, $ctx = [] ) { self::log( 'critical', $code, $msg, $ctx ); }

    /**
     * Wrap a callable in try/catch, log exceptions automatically.
     *
     * @param callable $fn       The function to execute.
     * @param string   $code     Error code if exception occurs.
     * @param array    $context  Additional context.
     * @param mixed    $fallback Return value on failure.
     * @return mixed             Return value of $fn or $fallback.
     */
    public static function safe_call( $fn, $code, $context = [], $fallback = null ) {
        try {
            return call_user_func( $fn );
        } catch ( \Exception $e ) {
            self::error( $code, $e->getMessage(), array_merge( $context, [
                'exception' => $e,
            ]));
            return $fallback;
        } catch ( \Error $e ) {
            self::critical( $code, $e->getMessage(), array_merge( $context, [
                'exception_class'   => get_class( $e ),
                'exception_message' => $e->getMessage(),
                'exception_file'    => $e->getFile(),
                'exception_line'    => $e->getLine(),
            ]));
            return $fallback;
        }
    }

    /**
     * Check a $wpdb result and log on failure.
     *
     * @param mixed  $result   Return value of $wpdb->insert/update/query/etc.
     * @param string $code     Error code.
     * @param string $message  Description.
     * @param array  $context  Extra context.
     * @return bool            True if $result is truthy, false otherwise.
     */
    public static function check_db( $result, $code, $message, $context = [] ) {
        global $wpdb;
        if ( false === $result ) {
            $context['last_error'] = $wpdb->last_error;
            $context['last_query'] = $wpdb->last_query;
            self::error( $code, $message, $context );
            return false;
        }
        return true;
    }

    /**
     * Check a wp_remote_* response and log on failure.
     *
     * @param mixed  $response  Return of wp_remote_get/post/head.
     * @param string $code      Error code.
     * @param string $message   Description.
     * @param array  $context   Extra context.
     * @return bool             True if response is valid, false otherwise.
     */
    public static function check_http( $response, $code, $message, $context = [] ) {
        if ( is_wp_error( $response ) ) {
            $context['wp_error'] = $response;
            self::error( $code, $message, $context );
            return false;
        }
        $status = wp_remote_retrieve_response_code( $response );
        if ( $status >= 400 ) {
            $context['http_status'] = $status;
            self::warning( $code, $message . " (HTTP $status)", $context );
            return false;
        }
        return true;
    }

    /* ═══════════════════════════════════════════
     *  QUERY API (for admin dashboard)
     * ═══════════════════════════════════════════ */

    /**
     * Get recent error log entries.
     *
     * @param array $args {
     *     @type string $level     Minimum level (default 'warning').
     *     @type string $code      Filter by error code.
     *     @type int    $limit     Max rows (default 50).
     *     @type string $since     ISO datetime (UTC).
     * }
     * @return array
     */
    public static function get_recent( $args = [] ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        $level = isset( $args['level'] ) ? $args['level'] : 'warning';
        $min   = isset( self::$severity[ $level ] ) ? self::$severity[ $level ] : 2;
        $limit = isset( $args['limit'] ) ? absint( $args['limit'] ) : 50;
        $code  = isset( $args['code'] )  ? sanitize_text_field( $args['code'] ) : '';
        $since = isset( $args['since'] ) ? sanitize_text_field( $args['since'] ) : '';

        $where = [ '1=1' ];
        $vals  = [];

        // Level filter: include all levels with severity >= min
        $allowed_levels = [];
        foreach ( self::$severity as $l => $s ) {
            if ( $s >= $min ) { $allowed_levels[] = $l; }
        }
        $placeholders = implode( ',', array_fill( 0, count( $allowed_levels ), '%s' ) );
        $where[] = "level IN ($placeholders)";
        $vals    = array_merge( $vals, $allowed_levels );

        if ( $code ) {
            $where[] = 'code = %s';
            $vals[]  = $code;
        }
        if ( $since ) {
            $where[] = 'created_at >= %s';
            $vals[]  = $since;
        }

        $sql = sprintf(
            "SELECT * FROM `%s` WHERE %s ORDER BY created_at DESC LIMIT %d",
            $table,
            implode( ' AND ', $where ),
            $limit
        );

        return $wpdb->get_results( $wpdb->prepare( $sql, $vals ) );
    }

    /**
     * Get counts grouped by level for the last N hours.
     *
     * @param int $hours Lookback window (default 24).
     * @return array     [ 'warning' => 12, 'error' => 3, 'critical' => 0 ]
     */
    public static function get_counts( $hours = 24 ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        $since = gmdate( 'Y-m-d H:i:s', time() - ( $hours * 3600 ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT level, COUNT(*) AS cnt FROM `%1s` WHERE created_at >= %s GROUP BY level",
            $table,
            $since
        ) );

        $counts = [ 'debug' => 0, 'info' => 0, 'warning' => 0, 'error' => 0, 'critical' => 0 ];
        if ( $rows ) {
            foreach ( $rows as $row ) {
                $counts[ $row->level ] = (int) $row->cnt;
            }
        }
        return $counts;
    }

    /* ═══════════════════════════════════════════
     *  INTERNALS
     * ═══════════════════════════════════════════ */

    /**
     * Flush buffered log entries to the database.
     */
    public function flush_buffer() {
        if ( empty( $this->buffer ) ) { return; }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        foreach ( $this->buffer as $entry ) {
            $wpdb->insert( $table, $entry, [ '%s', '%s', '%s', '%s', '%s' ] );
        }
        $this->buffer = [];
    }

    /**
     * Send an alert email for critical errors (rate-limited).
     */
    private function maybe_send_alert( $code, $message, $context ) {
        $transient_key = 'oo_error_alert_' . md5( $code );
        if ( get_transient( $transient_key ) ) { return; }
        set_transient( $transient_key, 1, self::ALERT_COOLDOWN );

        $to      = get_option( 'admin_email' );
        $subject = sprintf( '[Ontario Obituaries] CRITICAL: %s', $code );
        $body    = sprintf(
            "A critical error occurred in the Ontario Obituaries plugin.\n\n" .
            "Code: %s\nMessage: %s\nTime: %s (UTC)\nContext: %s\n\n" .
            "Review: %s",
            $code,
            $message,
            current_time( 'mysql', true ),
            wp_json_encode( $context, JSON_PRETTY_PRINT ),
            admin_url( 'admin.php?page=ontario-obituaries&tab=health' )
        );
        wp_mail( $to, $subject, $body );
    }

    /**
     * Cleanup old entries. Called by daily cron.
     */
    public static function cleanup() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        // Keep only MAX_ENTRIES most recent rows
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );
        if ( $count > self::MAX_ENTRIES ) {
            $delete = $count - self::MAX_ENTRIES;
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM `%1s` ORDER BY created_at ASC LIMIT %d",
                $table,
                $delete
            ) );
        }

        // Also delete anything older than 30 days
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM `%1s` WHERE created_at < %s",
            $table,
            gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) )
        ) );
    }

    /* ═══════════════════════════════════════════
     *  SCHEMA
     * ═══════════════════════════════════════════ */

    /**
     * Create the error log table. Called from plugin activation.
     */
    public static function create_table() {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE_SUFFIX;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `$table` (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            level      VARCHAR(10)     NOT NULL DEFAULT 'info',
            code       VARCHAR(50)     NOT NULL DEFAULT '',
            message    VARCHAR(500)    NOT NULL DEFAULT '',
            context    TEXT,
            created_at DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_level      (level),
            KEY idx_code       (code),
            KEY idx_created_at (created_at)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Drop the error log table. Called from uninstall.
     */
    public static function drop_table() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        $wpdb->query( "DROP TABLE IF EXISTS `$table`" );
    }
}
```

### 3.2 New: `includes/class-health-monitor.php` (est. ~250 lines)

Renders a "Health" tab in the Ontario Obituaries admin page showing:

```
+--------------------------------------------------+
|  ONTARIO OBITUARIES — SYSTEM HEALTH              |
+--------------------------------------------------+
|  Last 24h:  0 critical  |  2 errors  |  8 warnings
|                                                    |
|  [ CRON STATUS ]                                   |
|  Collection     ✅ last ran 12 min ago             |
|  AI Rewriter    ✅ last ran 3 min ago              |
|  Image Migration ✅ last ran 7 min ago             |
|  Dedup Daily    ✅ last ran 6 hours ago            |
|  GoFundMe       ✅ last ran 2 hours ago            |
|  Authenticity   ❌ not scheduled                   |
|  Error Cleanup  ✅ last ran 18 hours ago           |
|                                                    |
|  [ RECENT ERRORS ]                                 |
|  2026-02-20 14:32  ERROR  DB_INSERT_FAIL           |
|    "Failed to insert obituary: Duplicate entry"    |
|    Context: { obit_id: 4231, source: legacy-com }  |
|                                                    |
|  2026-02-20 14:28  WARN   SCRAPE_HTTP_FAIL         |
|    "remembering.ca returned HTTP 503"              |
|                                                    |
|  [ SUBSYSTEM STATUS ]                              |
|  Database table    ✅ exists, 14 columns            |
|  Error log table   ✅ exists, 847 entries           |
|  Upload directory  ✅ writable                      |
|  Groq API key      ✅ set                           |
|  PHP memory        ✅ 256M (limit)                  |
|  WP Cron           ✅ enabled                       |
+--------------------------------------------------+
```

**Key methods:**

```php
class Ontario_Obituaries_Health_Monitor {

    /**
     * Render the Health tab content.
     */
    public static function render_tab() { /* ... */ }

    /**
     * Get cron status for all plugin hooks.
     * Returns array of [ 'hook' => ..., 'next_run' => ..., 'status' => 'ok'|'overdue'|'missing' ]
     */
    public static function get_cron_status() { /* ... */ }

    /**
     * Get subsystem health checks.
     * Verifies: DB table exists, columns correct, upload dir writable,
     * API keys set, cron enabled, memory adequate.
     */
    public static function get_subsystem_checks() { /* ... */ }

    /**
     * Admin bar badge showing error/warning count.
     * Adds a red/yellow dot to the WP admin bar when errors exist.
     */
    public static function admin_bar_badge( $wp_admin_bar ) { /* ... */ }

    /**
     * REST endpoint for AJAX health refresh.
     * GET /wp-json/ontario-obituaries/v1/health
     */
    public static function rest_health() { /* ... */ }
}
```

---

## 4. Modifications to Existing Files (All 38)

### 4.1 Pattern: Database Operation Wrapping

**BEFORE** (current pattern — no check):
```php
$wpdb->insert( $table, $data );
// continues silently even if insert failed
```

**AFTER:**
```php
$result = $wpdb->insert( $table, $data );
Ontario_Obituaries_Error_Handler::check_db(
    $result,
    'DB_INSERT_FAIL',
    sprintf( 'Failed to insert obituary: %s', $wpdb->last_error ),
    [ 'source' => __CLASS__, 'data' => $data ]
);
```

**Files affected (by DB operation count):**

| File | DB ops | Changes needed |
|------|--------|----------------|
| `ontario-obituaries.php` | 39 | Wrap activation/migration queries |
| `class-source-registry.php` | 18 | Wrap all registry CRUD |
| `class-suppression-manager.php` | 18 | Wrap suppression queries |
| `class-ontario-obituaries-seo.php` | 13 | Wrap sitemap/meta queries |
| `class-groq-rate-limiter.php` | 12 | Wrap rate limiter DB ops |
| `class-ai-authenticity-checker.php` | 10 | Wrap audit queries |
| `class-source-collector.php` | 9 | Wrap insert/update/provenance |
| `class-ontario-obituaries-scraper.php` | 9 | Wrap legacy scraper queries |
| `class-ai-rewriter.php` | 9 | Wrap rewrite status updates |
| `class-ontario-obituaries-reset-rescan.php` | 7 | Wrap reset/rescan ops |
| `class-image-localizer.php` | 7 | Already partially handled |
| `class-gofundme-linker.php` | 7 | Wrap search/update |
| `class-ontario-obituaries-display.php` | 4 | Wrap display queries |
| Others | 5 | Remaining files |

### 4.2 Pattern: HTTP Call Wrapping

**BEFORE:**
```php
$response = wp_remote_get( $url, $args );
$body = wp_remote_retrieve_body( $response );
// no error check — $body could be empty string on failure
```

**AFTER:**
```php
$response = wp_safe_remote_get( $url, $args );
if ( ! Ontario_Obituaries_Error_Handler::check_http(
    $response, 'SCRAPE_HTTP_FAIL',
    "Failed to fetch $url",
    [ 'source' => __CLASS__ ]
) ) {
    return new WP_Error( 'http_fail', 'Source unavailable' );
}
$body = wp_remote_retrieve_body( $response );
```

**Files affected:**

| File | HTTP calls | Notes |
|------|-----------|-------|
| `class-source-adapter-base.php` | 2-3 | Main scraper HTTP |
| `class-image-localizer.php` | 2 | HEAD + GET (already done) |
| `class-image-pipeline.php` | 2 | HEAD + GET |
| `class-ai-rewriter.php` | 2 | Groq API calls |
| `class-ai-chatbot.php` | 1 | Groq chat API |
| `class-ai-authenticity-checker.php` | 1 | Groq audit API |
| `class-gofundme-linker.php` | 2 | GoFundMe search |
| `class-indexnow.php` | 1 | IndexNow ping |
| `class-google-ads-optimizer.php` | 2 | Google Ads API |
| Source adapters (5) | ~5 | Various scrape endpoints |

### 4.3 Pattern: AJAX Nonce + Capability Enforcement

**BEFORE** (most AJAX handlers):
```php
public function handle_manual_scrape() {
    // no nonce check, no capability check
    $this->do_scrape();
    wp_send_json_success();
}
```

**AFTER:**
```php
public function handle_manual_scrape() {
    check_ajax_referer( 'ontario_obituaries_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        Ontario_Obituaries_Error_Handler::warning(
            'AJAX_PERMISSION_FAIL',
            'Unauthorized AJAX call to handle_manual_scrape',
            [ 'user_id' => get_current_user_id() ]
        );
        wp_send_json_error( 'Unauthorized', 403 );
    }
    $this->do_scrape();
    wp_send_json_success();
}
```

**Files affected:**
- `class-ontario-obituaries-ajax.php` — ALL handlers (~15 endpoints)
- `class-ontario-obituaries.php` — Chatbot AJAX handlers
- `class-ontario-obituaries-admin.php` — Admin AJAX
- `class-ontario-obituaries-reset-rescan.php` — Reset/rescan handlers

### 4.4 Pattern: Template Safe Rendering

**BEFORE:**
```php
// templates/obituaries.php
<?php foreach ( $obituaries as $obituary ) : ?>
    <div class="obituary-card">
        <img src="<?php echo esc_url( $obituary->image_url ); ?>" />
        <h3><?php echo esc_html( $obituary->name ); ?></h3>
    </div>
<?php endforeach; ?>
```

**AFTER:**
```php
// templates/obituaries.php
<?php
try {
    if ( empty( $obituaries ) || ! is_array( $obituaries ) ) {
        throw new \RuntimeException( 'No obituary data available' );
    }
    foreach ( $obituaries as $obituary ) :
        if ( ! is_object( $obituary ) || empty( $obituary->name ) ) { continue; }
?>
    <div class="obituary-card">
        <?php if ( ! empty( $obituary->image_url ) ) : ?>
            <img src="<?php echo esc_url( $obituary->image_url ); ?>"
                 loading="lazy"
                 alt="<?php echo esc_attr( sprintf( 'Photo of %s', $obituary->name ) ); ?>" />
        <?php endif; ?>
        <h3><?php echo esc_html( $obituary->name ); ?></h3>
    </div>
<?php
    endforeach;
} catch ( \Exception $e ) {
    Ontario_Obituaries_Error_Handler::error(
        'TEMPLATE_RENDER_FAIL',
        'Obituary grid template failed: ' . $e->getMessage(),
        [ 'file' => __FILE__ ]
    );
    echo '<p class="ontario-error">Unable to load obituaries. Please try again later.</p>';
}
?>
```

**Templates affected:**
- `templates/obituaries.php`
- `templates/obituary-detail.php`
- `templates/seo/hub-city.php`
- `templates/seo/hub-ontario.php`
- `templates/seo/individual.php`
- `templates/seo/wrapper.php`

### 4.5 Pattern: Cron Job Wrapping

**BEFORE:**
```php
function ontario_obituaries_collection_handler() {
    $collector = new Ontario_Obituaries_Source_Collector();
    $collector->run();
}
```

**AFTER:**
```php
function ontario_obituaries_collection_handler() {
    Ontario_Obituaries_Error_Handler::safe_call(
        function() {
            $collector = new Ontario_Obituaries_Source_Collector();
            $result    = $collector->run();
            Ontario_Obituaries_Error_Handler::info(
                'CRON_COLLECTION_DONE',
                sprintf( 'Collection completed: %d new, %d skipped', $result['new'], $result['skipped'] ),
                [ 'result' => $result ]
            );
        },
        'CRON_COLLECTION_CRASH',
        [ 'hook' => 'ontario_obituaries_collection_event' ]
    );
}
```

**Cron hooks affected:**
- `ontario_obituaries_collection_event` (source collection)
- `ontario_obituaries_ai_rewrite_batch` (AI rewriting)
- `ontario_obituaries_image_migration` (image localization)
- `ontario_obituaries_dedup_daily` (deduplication)
- `ontario_obituaries_dedup_once` (one-time dedup)
- `ontario_obituaries_gofundme_batch` (GoFundMe linking)
- `ontario_obituaries_authenticity_audit` (AI authenticity)
- `ontario_obituaries_google_ads_analysis` (Google Ads)
- NEW: `ontario_obituaries_error_cleanup` (error log pruning)

### 4.6 Integration Points in `ontario-obituaries.php`

```php
/* ─── In activation hook ─── */
Ontario_Obituaries_Error_Handler::create_table();

// Schedule error cleanup
if ( ! wp_next_scheduled( 'ontario_obituaries_error_cleanup' ) ) {
    wp_schedule_event( time(), 'daily', 'ontario_obituaries_error_cleanup' );
}

/* ─── In deactivation hook ─── */
wp_clear_scheduled_hook( 'ontario_obituaries_error_cleanup' );

/* ─── In includes section ─── */
require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-error-handler.php';
require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-health-monitor.php';

/* ─── Hook the cleanup ─── */
add_action( 'ontario_obituaries_error_cleanup', [ 'Ontario_Obituaries_Error_Handler', 'cleanup' ] );

/* ─── Hook the admin bar badge ─── */
add_action( 'admin_bar_menu', [ 'Ontario_Obituaries_Health_Monitor', 'admin_bar_badge' ], 100 );

/* ─── Replace ontario_obituaries_log() ─── */
function ontario_obituaries_log( $message, $level = 'info' ) {
    // Backward-compatible wrapper → routes to new handler
    Ontario_Obituaries_Error_Handler::log( $level, 'LEGACY_LOG', $message );
}
```

### 4.7 Uninstall Additions

```php
/* ─── In uninstall.php ─── */
// Drop error log table
if ( class_exists( 'Ontario_Obituaries_Error_Handler' ) ) {
    Ontario_Obituaries_Error_Handler::drop_table();
} else {
    global $wpdb;
    $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}ontario_obituaries_errors`" );
}

// Clear error cleanup cron
wp_clear_scheduled_hook( 'ontario_obituaries_error_cleanup' );

// Delete alert cooldown transients
$wpdb->query( "DELETE FROM `{$wpdb->prefix}options` WHERE option_name LIKE '_transient_oo_error_alert_%'" );
```

---

## 5. Rollout Strategy

### Phase 1 — Foundation (v5.3.0) — ✅ COMPLETED (PR #96, 2026-02-20)
- [x] Create `class-error-handler.php` with full API (779 lines)
- [x] Create health counters via wp_options (no DB table — simpler approach)
- [x] Add `oo_log()` structured logging with subsystem + code + run_id + context
- [x] Add `oo_safe_call()`, `oo_safe_http()`, `oo_db_check()` wrappers
- [x] Add `require_once` in ontario-obituaries.php
- [x] Add log deduplication (5-min window, discriminator-based)
- [x] Add SQL redaction by default (opt-in via OO_DEBUG_LOG_SQL)
- **Actual approach:** No DB table — health counters use transients + single wp_option. Lighter weight than proposed.
- **Result:** Foundation merged and deployed. All existing `ontario_obituaries_log()` calls still work.

### Phase 2a — Cron Handler Hardening (v5.3.1) — ✅ COMPLETED (PR #97, 2026-02-20)
- [x] Wrap `ai_rewrite_batch()` with try/catch/finally, bootstrap crash coverage, settings gate
- [x] Wrap `gofundme_batch()`, `authenticity_audit()`, `google_ads_daily_analysis()` with `oo_safe_call()`
- [x] Upgrade `cleanup_duplicates`, `shutdown_rewriter`, `dedup_once` catch blocks to `oo_log()`
- [x] Add 13 new error codes
- [x] Add `oo_health_record_ran()` / `oo_health_record_success()` for subsystem tracking
- **QC review:** 3 required fixes + 2 recommended fixes all implemented. QC approved for merge.
- **Result:** All 8 cron handlers now have structured error handling. Pipeline health monitoring active.

### Phase 2a Hotfix — Name Validation (v5.3.1) — ✅ COMPLETED (PR #99, 2026-02-20)
- [x] Strip parenthesized nicknames from names before validation
- [x] Demote name_missing from hard-fail to warning
- **Root cause:** Phase 2a health monitoring revealed queue-blocking bug (101 consecutive failures on ID 1083)
- **Result:** Queue unblocked, pending count dropping.

### Phase 2b — HTTP Wrappers (15 call sites) — ✅ COMPLETED (PR #100, v5.3.2)
- [x] Route all `wp_remote_get/post/head` through `oo_safe_http_get/post/head` wrappers
- [x] Add URL validation (`esc_url_raw` + `wp_http_validate_url`) before every request
- [x] Add URL redaction for safe logging (`oo_redact_url` strips query strings)
- [x] Add header allowlist for error data (`oo_safe_error_headers` — 6 safe headers only)
- [x] Add safety clamps: `sslverify` filter-only, `redirection` ≤ 5, `timeout` 1–60 s
- [x] Enrich WP_Error with `status`, `body` (≤4 KB), allowlisted `headers`
- [x] Add helpers: `oo_http_error_status()`, `oo_http_error_body()` (re-caps to 2 KB), `oo_http_error_header()`
- [x] Remove all duplicate `oo_log()` calls from callers (wrapper handles logging)
- [x] Preserve status-code branching (401/403/429) via helper functions
- **Files:** class-error-handler.php (enhanced), class-source-adapter-base.php, class-adapter-remembering-ca.php, class-ai-rewriter.php, class-ai-chatbot.php, class-ai-authenticity-checker.php, class-gofundme-linker.php, class-indexnow.php, class-google-ads-optimizer.php, class-image-pipeline.php, ontario-obituaries.php
- **Risk:** Low-Medium — each conversion was mechanical; callers branch on WP_Error via helpers
- **QC gates passed:** raw HTTP = 0, no dup logging, status preserved, no secrets, version = 5.3.2
- **Allowed exceptions:** `wp_safe_remote_*` inside `class-error-handler.php` (wrapper internals) and `class-image-localizer.php` (stream-to-disk has its own handling)

### Phase 2c — Top 10 DB Hotspots — ✅ COMPLETE (PR #102, v5.3.3)
- [x] 35 `oo_db_check()` calls across 8 files (all unchecked `$wpdb` writes wrapped)
- [x] Strict `false === $result` prevents treating return 0 as error
- [x] `verification_token` NULL fix: raw query with `$wpdb->prepare()`
- **Risk:** Low — additive only

### Phase 2d — AJAX + Remaining DB Checks — ✅ COMPLETE (PR #104, v5.3.4)
- [x] AJAX delete: `oo_db_check()` + audit log gated on `$result > 0`
- [x] Reset/rescan purge: `oo_db_check()` on batch DELETE
- [x] Groq rate limiter: `oo_db_check()` on START TRANSACTION + COMMIT; legacy log guarded
- [x] Display: `oo_log` warning on `get_obituary()` when `$wpdb->last_error` set
- [x] All 21 AJAX handlers audited — nonce + capability checks already present
- **Risk:** Low — additive wrappers only, no JS changes needed

### Phase 3 — Health Dashboard (v5.3.5) — ✅ COMPLETE (PR #106, v5.3.5)
- [x] Built `class-health-monitor.php` (~350 lines)
- [x] Admin submenu page: Ontario Obituaries → System Health
- [x] Admin bar badge (yellow/red with issue count)
- [x] REST endpoint: `GET /wp-json/ontario-obituaries/v1/health` (admin-only)
- [x] Cron status table, subsystem checks, error code breakdown, last success timestamps
- [x] No new DB table — reads from existing wp_options + transients
- **Risk:** Low — UI only, no data path changes

### Phase 4 — Advanced (v6.0.0) — ⬜ PENDING
- [ ] Create DB error table for persistent structured logging
- [ ] Add email alert on critical errors
- [ ] Wrap all remaining 177 DB operations with `check_db()`
- [ ] Schedule daily error cleanup cron
- **Risk:** Medium — high volume of changes, but each is mechanical

---

## 6. Progress Summary (updated 2026-02-20)

| Phase | Status | PR(s) | Version | Files Changed |
|-------|--------|-------|---------|---------------|
| Phase 1 — Foundation | ✅ Merged + Deployed | #96 | v5.3.0 | 2 (new class-error-handler.php + ontario-obituaries.php) |
| Phase 2a — Cron Hardening | ✅ Merged + Deployed | #97, #98 | v5.3.1 | 2 (ontario-obituaries.php, class-error-handler.php) |
| Phase 2a Hotfix | ✅ Merged + Deployed | #99 | v5.3.1 | 1 (class-ai-rewriter.php) |
| Phase 2b — HTTP Wrappers | ✅ Merged + Deployed | #100 | v5.3.2 | 11 (error-handler + 9 app files + ontario-obituaries.php) |
| Phase 2c — DB Hotspots | ✅ Merged + Deployed | #102, #103 | v5.3.3 | 8 files (35 oo_db_check calls) |
| Phase 2d — AJAX + Remaining DB | ✅ Merged + Deployed | #104, #105 | v5.3.4 | 4 files (+49/−8) |
| Phase 3 — Health Dashboard | ✅ Merged + Deployed | #106 | v5.3.5 | 3 files (1 new + 2 modified) |
| Phase 4 — Advanced | ⬜ Pending | — | — | ~20 files |
| **Overall** | **40% complete** | | | |

### Key Findings from Phase 2b Deployment

1. **Wrapper design simplifies callers**: Callers no longer need individual `is_wp_error()` + status code checks — the wrapper handles transport failures AND HTTP errors uniformly.
2. **Status-code branching via helpers**: Complex callers (rewriter validate_api_key, Google Ads OAuth) use `oo_http_error_status()` to branch on 401/403/429 while the wrapper handles logging.
3. **Body access for API error parsing**: `oo_http_error_body()` provides the response body (capped at 2 KB) so callers can decode API-specific error messages from 401/403 responses.
4. **No secrets leakage**: URL query strings are stripped via `oo_redact_url()` before logging. Only 6 safe headers stored in error data. Response body and request headers never appear in `oo_log()`.
5. **SSRF protection now universal**: All HTTP requests go through `wp_safe_remote_*` which blocks private/reserved IPs, plus `esc_url_raw()` rejects non-HTTP schemes.
6. **QC-mandated hardening (3 required + 1 optional):**
   - `oo_http_error_header()` now normalizes the lookup key to lower-case and normalizes array keys — callers can pass any casing.
   - Redirection argument clamped to 0–5 (not just ≤ 5) — prevents negative values from reaching WP HTTP API.
   - `oo_redact_url()` handles `wp_parse_url()` returning false on malformed input — returns `'(malformed-url)'` sentinel.
   - `oo_safe_error_headers()` normalizes array header keys via `array_change_key_case()` — consistent matching regardless of server header casing.

---

## 7. Estimated Remaining Effort

| Phase | Files touched | Estimated lines changed | Time | Status |
|-------|--------------|------------------------|------|--------|
| Phase 1 | 2 | ~800 new | 3 hours | ✅ Done |
| Phase 2a | 2 | ~200 modified | 3 hours | ✅ Done |
| Phase 2b | ~11 | ~580 modified | 4 hours | ✅ Done |
| Phase 2c | ~8 | ~173 modified | 2-3 hours | ✅ Done (PR #102) |
| Phase 2d | ~4 | ~49 modified | 2-3 hours | ✅ Done (PR #104) |
| Phase 3 | 3 | ~350 new, ~20 modified | 3-4 hours | ✅ Done (PR #106) |
| Phase 4 | ~20 | ~500 modified + new | 5-6 hours | ⬜ |
| **Total** | **~35** | **~2500** | **~22-27 hours** | **25% done** |

---

## 8. What Does NOT Change

- **Database schema** for obituaries table — no columns added/removed
- **Frontend URLs/routes** — all permalinks unchanged
- **REST API contracts** — same endpoints, same response shapes
- **Cron schedules** — same hooks, same intervals (plus 1 new daily cleanup)
- **Plugin settings** — no new user-facing settings (health tab is read-only)
- **Existing `ontario_obituaries_log()` calls** — all 169 calls continue working via backward-compatible wrapper

---

## 9. Success Criteria

After full rollout, these conditions must hold:

1. **Zero unchecked DB operations** — every `$wpdb->` call has a `check_db()` wrapper
2. **Zero unchecked HTTP calls** — every `wp_remote_*` has `check_http()` or `is_wp_error()`
3. **100% AJAX nonce coverage** — every `wp_ajax_*` handler verifies a nonce
4. **All cron jobs wrapped** — every cron callback uses `safe_call()`
5. **All templates safe** — every template has try/catch with user-friendly fallback
6. **Admin visibility** — errors visible in Health tab within 1 page load
7. **Critical alerts** — email sent within 60 seconds of a critical error
8. **No performance regression** — buffered writes, max 50 inserts per request
9. **Self-cleaning** — error log auto-prunes to 5000 entries / 30 days
10. **Clean uninstall** — `uninstall.php` removes error table and all related options/transients

---

## 10. Verification Query

After deployment, run this to confirm error handling is active:

```sql
-- Verify error table exists and is collecting
SELECT COUNT(*) AS total_entries,
       MIN(created_at) AS oldest,
       MAX(created_at) AS newest,
       SUM(level = 'error') AS errors,
       SUM(level = 'critical') AS critical
FROM wp_ontario_obituaries_errors;

-- Expected: total_entries > 0 after first cron tick,
--           errors and critical = 0 under normal operation
```

---

## 11. QC Review Checklist

- [ ] Approve overall architecture (singleton handler + health monitor)
- [ ] Approve error code taxonomy (Section 2.4)
- [ ] Approve DB table schema for error log
- [ ] Approve AJAX nonce enforcement approach (will require JS changes)
- [ ] Approve phased rollout (4 phases vs. big-bang)
- [ ] Approve email alerting on critical errors
- [ ] Flag any concerns about performance (buffered writes)
- [ ] Flag any concerns about table growth (5000 row cap + 30-day TTL)

**Phase 1 + Phase 2a + Phase 2b + Phase 2c + Phase 2d + Phase 3 approved/completed. Phase 4 (advanced logging) is next.**
