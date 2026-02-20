<?php
/**
 * Plugin Name: Ontario Obituaries
 * Description: Ontario-wide obituary data ingestion with coverage-first, rights-aware publishing — Compatible with Obituary Assistant
 * Version: 5.3.2
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Author: Monaco Monuments
 * Author URI: https://monacomonuments.ca
 * Text Domain: ontario-obituaries
 * Domain Path: /languages
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'ONTARIO_OBITUARIES_VERSION', '5.3.2' );
define( 'ONTARIO_OBITUARIES_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ONTARIO_OBITUARIES_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ONTARIO_OBITUARIES_PLUGIN_FILE', __FILE__ );

// v5.2.0: Request start time — used by Image Localizer to avoid exceeding
// LiteSpeed's 120s timeout during long-running scrape requests.
if ( ! defined( 'ONTARIO_OBITUARIES_REQUEST_START' ) ) {
    define( 'ONTARIO_OBITUARIES_REQUEST_START', time() );
}

/**
 * Status whitelist — single source of truth.
 *
 * Only these values may be written to the `status` column:
 *   - 'pending'   — scraped, awaiting AI rewrite
 *   - 'published' — AI rewrite complete, fully enriched
 *
 * Write points (exhaustive):
 *   1. class-source-collector.php:481 — INSERT with 'pending'
 *   2. class-ai-rewriter.php:388     — UPDATE to 'published'
 *   3. ontario-obituaries.php:459    — backfill dirty → 'pending'
 *
 * Read filters:
 *   - Display class (get_obituaries, count_obituaries) accepts optional
 *     status arg validated against this list.
 *   - REST API forces status='published' for backward compatibility.
 *   - Frontend/SEO shows all non-suppressed records (no status filter).
 *
 * @since 5.0.4
 */
function ontario_obituaries_valid_statuses() {
    return array( 'pending', 'published' );
}

/**
 * Validate a status value against the whitelist.
 *
 * @param string $status Value to check.
 * @return bool True if valid, false otherwise.
 * @since 5.0.4
 */
function ontario_obituaries_is_valid_status( $status ) {
    return in_array( $status, ontario_obituaries_valid_statuses(), true );
}

/**
 * v4.2.0: Domain lock — plugin only operates on authorized domains.
 *
 * Prevents unauthorized use if the codebase is copied to another site.
 * The scraper, AI rewriter, and cron hooks are disabled on non-authorized domains.
 * Admin pages still load so the owner can see the lock message.
 *
 * Authorized domains: monacomonuments.ca (production), localhost (development).
 */
define( 'ONTARIO_OBITUARIES_AUTHORIZED_DOMAINS', 'monacomonuments.ca,localhost,127.0.0.1' );

function ontario_obituaries_is_authorized_domain() {
    $host = wp_parse_url( home_url(), PHP_URL_HOST );
    if ( empty( $host ) ) {
        return false;
    }
    $host       = strtolower( $host );
    $authorized = array_map( 'trim', explode( ',', ONTARIO_OBITUARIES_AUTHORIZED_DOMAINS ) );
    $authorized = array_filter( $authorized, 'strlen' ); // QC-R2: Drop empty entries from trailing commas.
    foreach ( $authorized as $domain ) {
        $domain = strtolower( $domain );
        // BUG-H6 FIX (v5.0.5): Use exact match or strict subdomain suffix match.
        // Previously used strpos() which allowed substring spoofing
        // (e.g., "evilmonacomonuments.ca" would pass).
        // Now: "monacomonuments.ca" matches exactly, or "staging.monacomonuments.ca"
        // matches via ".monacomonuments.ca" suffix — but "evilmonacomonuments.ca" fails.
        if ( $host === $domain ) {
            return true;
        }
        // Allow subdomains: host must end with ".{domain}" (leading dot prevents substring abuse).
        if ( strlen( $host ) > strlen( $domain ) && substr( $host, -( strlen( $domain ) + 1 ) ) === '.' . $domain ) {
            return true;
        }
    }
    return false;
}

/**
 * Load plugin textdomain for translations (ARCH-05 FIX)
 */
function ontario_obituaries_load_textdomain() {
    load_plugin_textdomain( 'ontario-obituaries', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'ontario_obituaries_load_textdomain', 5 );

/**
 * Check if Obituary Assistant plugin is active
 *
 * @return bool Whether Obituary Assistant is active
 */
function ontario_obituaries_check_dependency() {
    if ( ! function_exists( 'is_plugin_active' ) ) {
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    return is_plugin_active( 'obituary-assistant/obituary-assistant.php' );
}

/**
 * Get default plugin settings (centralized defaults - ARCH-03 FIX)
 *
 * @return array Default settings
 */
function ontario_obituaries_get_defaults() {
    return array(
        'enabled'        => true,
        'frequency'      => 'twicedaily',
        'time'           => '03:00',
        'regions'        => array( 'York Region', 'Newmarket', 'Toronto', 'Ottawa', 'Hamilton' ),
        'max_age'        => 7,
        'filter_keywords' => '',
        'auto_publish'   => true,
        'notify_admin'   => true,
        'retry_attempts' => 3,
        'timeout'        => 30,
        'adaptive_mode'  => true,
        'debug_logging'  => false,
        'fuzzy_dedupe'   => false,
        'ai_rewrite_enabled' => false,
    );
}

/**
 * Get plugin settings (single source of truth - ARCH-03 FIX)
 *
 * @return array Merged settings with defaults
 */
function ontario_obituaries_get_settings() {
    $defaults = ontario_obituaries_get_defaults();
    $settings = get_option( 'ontario_obituaries_settings', array() );
    return wp_parse_args( $settings, $defaults );
}

/**
 * Plugin logging wrapper (P2-12 FIX)
 *
 * - 'error' level messages are ALWAYS logged regardless of the debug_logging setting.
 * - 'info' and 'warning' messages are only logged when debug_logging is enabled.
 *
 * @param string $message Log message.
 * @param string $level   One of: info, warning, error.
 */
function ontario_obituaries_log( $message, $level = 'info' ) {
    $settings = ontario_obituaries_get_settings();
    if ( empty( $settings['debug_logging'] ) && 'error' !== $level ) {
        return;
    }
    error_log( sprintf( '[Ontario Obituaries][%s] %s', strtoupper( $level ), $message ) );
}

/**
 * v3.16.1: Load the Source Collector, Image Pipeline, and all Source Adapters.
 *
 * Extracted from ontario_obituaries_includes() so it can be called on-demand
 * by the REST cron endpoint, the frontend stale-cron heartbeat, and the
 * deploy migration — all of which need the Collector but may run outside
 * admin/cron/AJAX contexts where these classes were previously loaded.
 *
 * Safe to call multiple times — uses require_once and a static flag.
 *
 * @since 3.16.1
 */
function ontario_obituaries_load_collector_classes() {
    static $loaded = false;
    if ( $loaded ) {
        return;
    }
    $loaded = true;

    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/sources/class-source-collector.php';
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/pipelines/class-image-pipeline.php';

    // Source Adapters
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/sources/class-adapter-generic-html.php';
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/sources/class-adapter-frontrunner.php';
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/sources/class-adapter-tribute-archive.php';
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/sources/class-adapter-legacy-com.php';
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/sources/class-adapter-remembering-ca.php';
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/sources/class-adapter-dignity-memorial.php';

    // Register all adapters with the Source Registry
    Ontario_Obituaries_Source_Registry::register_adapter( new Ontario_Obituaries_Adapter_Generic_HTML() );
    Ontario_Obituaries_Source_Registry::register_adapter( new Ontario_Obituaries_Adapter_FrontRunner() );
    Ontario_Obituaries_Source_Registry::register_adapter( new Ontario_Obituaries_Adapter_Tribute_Archive() );
    Ontario_Obituaries_Source_Registry::register_adapter( new Ontario_Obituaries_Adapter_Legacy_Com() );
    Ontario_Obituaries_Source_Registry::register_adapter( new Ontario_Obituaries_Adapter_Remembering_Ca() );
    Ontario_Obituaries_Source_Registry::register_adapter( new Ontario_Obituaries_Adapter_Dignity_Memorial() );
}

/**
 * v3.16.1: Ensure the Source Collector is available, loading it on-demand if needed.
 *
 * Call this before any code that needs Ontario_Obituaries_Source_Collector
 * when running outside admin/cron/AJAX contexts (e.g., REST API, frontend heartbeat).
 *
 * @since 3.16.1
 * @return bool Whether the Collector class is available after loading.
 */
function ontario_obituaries_ensure_collector_loaded() {
    if ( ! class_exists( 'Ontario_Obituaries_Source_Collector' ) ) {
        ontario_obituaries_load_collector_classes();
    }
    return class_exists( 'Ontario_Obituaries_Source_Collector' );
}

/**
 * Include required files
 */
function ontario_obituaries_includes() {
    // v6.0.0: Error Handling Foundation — loaded first so all classes can use wrappers.
    // Provides: oo_log(), oo_safe_call(), oo_safe_http_get/head/post(), oo_db_check(),
    //           oo_run_id(), oo_health_get_summary(). No DB table — uses transients + wp_options.
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-error-handler.php';

    // Core classes (v2.x)
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries.php';
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries-display.php';
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries-ajax.php';
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries-scraper.php';
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries-admin.php';
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries-debug.php';
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries-reset-rescan.php';

    // v3.0 Source Adapter Architecture — registry always needed for suppression checks
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/sources/interface-source-adapter.php';
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/sources/class-source-adapter-base.php';
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/sources/class-source-registry.php';

    // Pipelines — suppression is needed on frontend for SEO/noindex
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/pipelines/class-suppression-manager.php';

    // v4.1.0: AI Rewriter — always loaded so stats/display work on frontend
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ai-rewriter.php';

    // v5.0.6 BUG-M1 FIX: Shared Groq rate limiter — coordinates TPM usage
    // across AI Rewriter, AI Chatbot, and Authenticity Checker. Must load
    // before any consumer class makes API calls.
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-groq-rate-limiter.php';

    // v4.2.0: IndexNow — instant search engine notification on new obituaries
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-indexnow.php';

    // v4.3.0: GoFundMe Auto-Linker — matches obituaries to memorial fundraisers
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-gofundme-linker.php';

    // v4.3.0: AI Authenticity Checker — 24/7 random data quality audits
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ai-authenticity-checker.php';

    // v4.4.0: Google Ads Campaign Optimizer — REST API integration + AI analysis
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-google-ads-optimizer.php';

    // v4.5.0: AI Chatbot — Groq-powered customer service assistant
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ai-chatbot.php';

    // v5.2.0: Image Localizer — downloads external images to local server
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-image-localizer.php';

    // SEO — needed on frontend for rewrite rules
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries-seo.php';

    // P2-1 FIX: Only load collector, adapters, and image pipeline in admin/cron contexts.
    // v3.16.1: Also check REST_REQUEST for early REST detection (note: REST_REQUEST
    // is set late in WP lifecycle, so this check may not match at plugins_loaded time).
    // The primary fix for REST/frontend contexts is ontario_obituaries_ensure_collector_loaded()
    // which is called on-demand by the REST cron handler, frontend heartbeat, and
    // deploy migration before they instantiate the Source Collector.
    if ( is_admin() || wp_doing_cron() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
        ontario_obituaries_load_collector_classes();
    }

    $ajax_handler = new Ontario_Obituaries_Ajax();
    $ajax_handler->init();

    $admin = new Ontario_Obituaries_Admin();
    $admin->init();

    $debug = new Ontario_Obituaries_Debug();
    $debug->init();

    // v3.11.0: Reset & Rescan tool — AJAX hooks only (render is called via main class)
    $reset_rescan = new Ontario_Obituaries_Reset_Rescan();
    $reset_rescan->init();

    // Store the shared instance so Ontario_Obituaries can call render_page()
    // without creating a second instance (prevents duplicate AJAX hook registration).
    Ontario_Obituaries::set_reset_rescan_instance( $reset_rescan );

    // Initialize SEO
    $seo = new Ontario_Obituaries_SEO();
    $seo->init();

    // v4.5.0: AI Chatbot
    $chatbot = new Ontario_Obituaries_AI_Chatbot();
    $chatbot->init();
}

/**
 * Compute the next cron timestamp honouring the configured time.
 * (P0-4 FIX)
 *
 * @param string $time_setting HH:MM in 24-hour format.
 * @return int Unix timestamp for the next occurrence.
 */
function ontario_obituaries_next_cron_timestamp( $time_setting = '03:00' ) {
    $tz  = wp_timezone();
    $now = new DateTimeImmutable( 'now', $tz );

    // Parse the desired hour:minute
    $parts = explode( ':', $time_setting );
    $hour  = isset( $parts[0] ) ? intval( $parts[0] ) : 3;
    $min   = isset( $parts[1] ) ? intval( $parts[1] ) : 0;

    $target = $now->setTime( $hour, $min, 0 );

    // If the target time already passed today, schedule for tomorrow
    if ( $target <= $now ) {
        $target = $target->modify( '+1 day' );
    }

    return $target->getTimestamp();
}

/**
 * Activation function
 */
function ontario_obituaries_activate() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table_name      = $wpdb->prefix . 'ontario_obituaries';

    // v3.0.0: Extended schema with provenance + suppression fields
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        name varchar(200) NOT NULL,
        date_of_birth date DEFAULT NULL,
        date_of_death date NOT NULL,
        age smallint(3) UNSIGNED DEFAULT NULL,
        funeral_home varchar(200) NOT NULL DEFAULT '',
        location varchar(100) NOT NULL DEFAULT '',
        image_url varchar(2083) DEFAULT NULL,
        image_local_url varchar(2083) DEFAULT NULL,
        image_thumb_url varchar(2083) DEFAULT NULL,
        description text DEFAULT NULL,
        ai_description text DEFAULT NULL,
        source_url varchar(2083) DEFAULT NULL,
        source_domain varchar(255) NOT NULL DEFAULT '',
        source_type varchar(50) NOT NULL DEFAULT '',
        city_normalized varchar(100) NOT NULL DEFAULT '',
        provenance_hash varchar(40) NOT NULL DEFAULT '',
        status varchar(20) NOT NULL DEFAULT 'pending',
        suppressed_at datetime DEFAULT NULL,
        suppressed_reason varchar(100) DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_date_death (date_of_death),
        KEY idx_location (location),
        KEY idx_funeral_home (funeral_home),
        KEY idx_name (name(100)),
        KEY idx_created_at (created_at),
        KEY idx_source_domain (source_domain),
        KEY idx_source_type (source_type),
        KEY idx_city_normalized (city_normalized),
        KEY idx_provenance_hash (provenance_hash),
        KEY idx_suppressed_at (suppressed_at),
        KEY idx_status (status),
        UNIQUE KEY idx_unique_obituary (name(100), date_of_death, funeral_home(100))
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    $current_db_version = get_option( 'ontario_obituaries_db_version', '0' );

    // P0-2 QC FIX: Explicit column migration for v2.x → v2.2.0
    if ( version_compare( $current_db_version, '2.2.0', '<' ) ) {
        $fh_col = $wpdb->get_row( "SHOW COLUMNS FROM `{$table_name}` LIKE 'funeral_home'" );
        if ( $fh_col && ( 'YES' === $fh_col->Null || '' !== (string) $fh_col->Default ) ) {
            $wpdb->query( "ALTER TABLE `{$table_name}` MODIFY funeral_home varchar(200) NOT NULL DEFAULT ''" );
        }

        $loc_col = $wpdb->get_row( "SHOW COLUMNS FROM `{$table_name}` LIKE 'location'" );
        if ( $loc_col && ( 'YES' === $loc_col->Null || '' !== (string) $loc_col->Default ) ) {
            $wpdb->query( "ALTER TABLE `{$table_name}` MODIFY location varchar(100) NOT NULL DEFAULT ''" );
        }

        $wpdb->query( "UPDATE `{$table_name}` SET funeral_home = '' WHERE funeral_home IS NULL" );
        $wpdb->query( "UPDATE `{$table_name}` SET location = '' WHERE location IS NULL" );
    }

    // v3.0.0 migration: Add new columns if upgrading from < 3.0.0
    if ( version_compare( $current_db_version, '3.0.0', '<' ) ) {
        // P2-4 FIX: Use prepare() for the INFORMATION_SCHEMA query
        $existing_cols = $wpdb->get_col( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
            $table_name
        ) );

        $new_columns = array(
            'source_domain'    => "ALTER TABLE `{$table_name}` ADD COLUMN source_domain varchar(255) NOT NULL DEFAULT '' AFTER source_url",
            'source_type'      => "ALTER TABLE `{$table_name}` ADD COLUMN source_type varchar(50) NOT NULL DEFAULT '' AFTER source_domain",
            'city_normalized'  => "ALTER TABLE `{$table_name}` ADD COLUMN city_normalized varchar(100) NOT NULL DEFAULT '' AFTER source_type",
            'provenance_hash'  => "ALTER TABLE `{$table_name}` ADD COLUMN provenance_hash varchar(40) NOT NULL DEFAULT '' AFTER city_normalized",
            'suppressed_at'    => "ALTER TABLE `{$table_name}` ADD COLUMN suppressed_at datetime DEFAULT NULL AFTER provenance_hash",
            'suppressed_reason' => "ALTER TABLE `{$table_name}` ADD COLUMN suppressed_reason varchar(100) DEFAULT NULL AFTER suppressed_at",
            'image_local_url'  => "ALTER TABLE `{$table_name}` ADD COLUMN image_local_url varchar(2083) DEFAULT NULL AFTER image_url",
            'image_thumb_url'  => "ALTER TABLE `{$table_name}` ADD COLUMN image_thumb_url varchar(2083) DEFAULT NULL AFTER image_local_url",
        );

        foreach ( $new_columns as $col_name => $alter_sql ) {
            if ( ! in_array( $col_name, $existing_cols, true ) ) {
                $wpdb->query( $alter_sql );
            }
        }

        // Add new indexes if missing
        $indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table_name}`", ARRAY_A );
        $index_names = array_column( $indexes, 'Key_name' );

        $new_indexes = array(
            'idx_source_domain'    => "ALTER TABLE `{$table_name}` ADD KEY idx_source_domain (source_domain)",
            'idx_source_type'      => "ALTER TABLE `{$table_name}` ADD KEY idx_source_type (source_type)",
            'idx_city_normalized'  => "ALTER TABLE `{$table_name}` ADD KEY idx_city_normalized (city_normalized)",
            'idx_provenance_hash'  => "ALTER TABLE `{$table_name}` ADD KEY idx_provenance_hash (provenance_hash)",
            'idx_suppressed_at'    => "ALTER TABLE `{$table_name}` ADD KEY idx_suppressed_at (suppressed_at)",
        );

        foreach ( $new_indexes as $idx_name => $idx_sql ) {
            if ( ! in_array( $idx_name, $index_names, true ) ) {
                $wpdb->query( $idx_sql );
            }
        }
    }

    // v3.0.0: Create source registry table
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/sources/class-source-registry.php';
    Ontario_Obituaries_Source_Registry::create_table();

    // v3.0.0: Create suppressions table (P0-SUP FIX: updated schema with suppress-after-verify)
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/pipelines/class-suppression-manager.php';
    Ontario_Obituaries_Suppression_Manager::create_table();

    // v3.0.0: Migrate suppressions table for suppress-after-verify schema changes
    $supp_table = $wpdb->prefix . 'ontario_obituaries_suppressions';
    $supp_cols  = $wpdb->get_col( $wpdb->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
        $supp_table
    ) );
    if ( ! empty( $supp_cols ) ) {
        // Add new columns if they don't exist (dbDelta may miss ALTER on existing tables)
        $supp_migrations = array(
            'token_created_at' => "ALTER TABLE `{$supp_table}` ADD COLUMN token_created_at datetime DEFAULT NULL AFTER verification_token",
            'requester_ip'     => "ALTER TABLE `{$supp_table}` ADD COLUMN requester_ip varchar(45) NOT NULL DEFAULT '' AFTER reviewed_at",
            'created_at'       => "ALTER TABLE `{$supp_table}` ADD COLUMN created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER do_not_republish",
        );
        foreach ( $supp_migrations as $col => $sql ) {
            if ( ! in_array( $col, $supp_cols, true ) ) {
                $wpdb->query( $sql );
            }
        }

        // Modify suppressed_at to allow NULL (suppress-after-verify requires NULL default)
        $supp_at_col = $wpdb->get_row( "SHOW COLUMNS FROM `{$supp_table}` LIKE 'suppressed_at'" );
        if ( $supp_at_col && 'NO' === $supp_at_col->Null ) {
            $wpdb->query( "ALTER TABLE `{$supp_table}` MODIFY suppressed_at datetime DEFAULT NULL" );
        }

        // Modify do_not_republish default to 0 (was 1; now 0 until verified)
        $dnr_col = $wpdb->get_row( "SHOW COLUMNS FROM `{$supp_table}` LIKE 'do_not_republish'" );
        if ( $dnr_col && '1' === (string) $dnr_col->Default ) {
            $wpdb->query( "ALTER TABLE `{$supp_table}` MODIFY do_not_republish tinyint(1) NOT NULL DEFAULT 0" );
        }
    }

    // v3.0.0: Seed default sources on fresh install
    if ( version_compare( $current_db_version, '3.0.0', '<' ) ) {
        Ontario_Obituaries_Source_Registry::seed_defaults();
    }

    // v4.6.0 migration: Add status column + fresh start (scrape → rewrite → publish pipeline)
    if ( version_compare( $current_db_version, '4.6.0', '<' ) ) {
        $existing_cols_460 = $wpdb->get_col( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
            $table_name
        ) );

        // Add 'status' column if it doesn't exist.
        if ( ! in_array( 'status', $existing_cols_460, true ) ) {
            $wpdb->query( "ALTER TABLE `{$table_name}` ADD COLUMN status varchar(20) NOT NULL DEFAULT 'pending' AFTER provenance_hash" );
            $wpdb->query( "ALTER TABLE `{$table_name}` ADD KEY idx_status (status)" );
        }

        // Add 'ai_description' column if it doesn't exist.
        // v4.6.4 FIX: This column is required by the AI Rewriter to store rewritten text.
        // It was missing from the original v4.6.0 migration, causing silent INSERT failures.
        if ( ! in_array( 'ai_description', $existing_cols_460, true ) ) {
            $wpdb->query( "ALTER TABLE `{$table_name}` ADD COLUMN ai_description text DEFAULT NULL AFTER description" );
        }

        // v5.0.0 FIX: REMOVED the TRUNCATE that was here. It wiped all obituaries
        // on every delete-and-reinstall cycle because uninstall.php deletes the
        // db_version option, causing this migration to re-run.
        // v5.0.4 FIX: Normalize ALL invalid/dirty status values to 'pending'.
        // Canonical whitelist: ontario_obituaries_valid_statuses() in this file.
        // Uses LOWER() for collation safety (works on both _ci and _bin).
        // Uses LIMIT 500 to bound execution time on large tables; runs once
        // per plugin update so stragglers are caught on next activation.
        $valid = implode( "','", array_map( 'esc_sql', ontario_obituaries_valid_statuses() ) );
        $wpdb->query( "UPDATE `{$table_name}` SET status = 'pending' WHERE LOWER(COALESCE(status, '')) NOT IN ('{$valid}') LIMIT 500" );
        ontario_obituaries_log( 'v4.6.0 migration: Added status/ai_description columns. Existing records set to pending.', 'info' );

        // Clear cached data.
        delete_transient( 'ontario_obituaries_locations_cache' );
        delete_transient( 'ontario_obituaries_funeral_homes_cache' );
        delete_option( 'ontario_obituaries_last_collection' );
        delete_option( 'ontario_obituaries_last_scrape' );
    }

    update_option( 'ontario_obituaries_db_version', ONTARIO_OBITUARIES_VERSION );
    set_transient( 'ontario_obituaries_activation_notice', true, 300 );

    // Schedule cron
    $settings  = ontario_obituaries_get_settings();
    // P0-4 FIX: Whitelist valid WP cron recurrences
    $allowed_frequencies = array( 'hourly', 'twicedaily', 'daily', 'weekly' );
    $frequency = isset( $settings['frequency'] ) && in_array( $settings['frequency'], $allowed_frequencies, true )
        ? $settings['frequency']
        : 'daily';
    $time      = isset( $settings['time'] ) ? $settings['time'] : '03:00';

    wp_clear_scheduled_hook( 'ontario_obituaries_collection_event' );
    $scheduled = wp_schedule_event(
        ontario_obituaries_next_cron_timestamp( $time ),
        $frequency,
        'ontario_obituaries_collection_event'
    );
    if ( false === $scheduled || is_wp_error( $scheduled ) ) {
        ontario_obituaries_log( 'Failed to schedule cron on activation (frequency: ' . $frequency . ', time: ' . $time . ')', 'error' );
    }

    // v3.0.0: Flush rewrite rules for SEO URLs
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries-seo.php';
    Ontario_Obituaries_SEO::flush_rules();

    // BUG-C4 FIX (v5.0.4): Schedule daily dedup cron.
    // Runs once per day as a safety net; primary dedup runs post-scrape via
    // ontario_obituaries_maybe_cleanup_duplicates(). Uses wp_next_scheduled()
    // guard to avoid duplicate events (non-atomic but safe: worst case is two
    // near-simultaneous events, and the transient lock prevents both from running).
    wp_clear_scheduled_hook( 'ontario_obituaries_dedup_daily' );
    if ( ! wp_next_scheduled( 'ontario_obituaries_dedup_daily' ) ) {
        wp_schedule_event( time() + 3600, 'daily', 'ontario_obituaries_dedup_daily' );
    }

    // P0-1 FIX: Schedule initial collection as a one-time cron event
    // instead of running synchronously during activation (which causes timeouts).
    if ( ! wp_next_scheduled( 'ontario_obituaries_initial_collection' ) ) {
        wp_schedule_single_event( time() + 30, 'ontario_obituaries_initial_collection' );
    }

    // v5.1.5: Register AI rewrite batch as a repeating 5-minute event.
    // Only if AI rewrites are enabled AND a Groq key is configured.
    // A repeating event stays in the schedule permanently.
    //
    // DEPLOY-FIX: When upgrading via "Delete + Upload", uninstall.php wipes
    // ontario_obituaries_settings and ontario_obituaries_groq_api_key.
    // The user must re-enter the Groq key and check AI Rewrite in settings
    // after a delete-upgrade. However, if settings are absent but a Groq key
    // IS present (e.g. restored via WP-CLI), treat ai_rewrite_enabled as true
    // because the key itself signals intent.
    wp_clear_scheduled_hook( 'ontario_obituaries_ai_rewrite_batch' );
    $activation_settings  = ontario_obituaries_get_settings();
    $activation_groq_key  = get_option( 'ontario_obituaries_groq_api_key', '' );
    $raw_settings         = get_option( 'ontario_obituaries_settings', false );
    // If settings were never saved (false = option doesn't exist), AND a key
    // is present, assume the user wants rewrites on. This covers the
    // delete-upgrade path where uninstall.php wiped settings but the key was
    // restored before activation.
    $ai_enabled = ( false === $raw_settings && ! empty( $activation_groq_key ) )
        ? true
        : ! empty( $activation_settings['ai_rewrite_enabled'] );
    if ( $ai_enabled && ! empty( $activation_groq_key ) ) {
        wp_schedule_event( time() + 120, 'ontario_five_minutes', 'ontario_obituaries_ai_rewrite_batch' );
        ontario_obituaries_log( 'v5.1.5: Repeating AI rewrite batch registered on activation (5-min).', 'info' );
    }

    // v5.2.0: Schedule image localization migration if external images exist.
    // Runs every 5 min, processes 20 images/batch, self-removes when done.
    if ( class_exists( 'Ontario_Obituaries_Image_Localizer' ) ) {
        Ontario_Obituaries_Image_Localizer::maybe_schedule_migration();
    }

    // v3.0.0: Auto-create the Obituaries page with shortcode if it doesn't exist.
    ontario_obituaries_create_page();
}
register_activation_hook( __FILE__, 'ontario_obituaries_activate' );

/**
 * Create the Obituaries page with the plugin shortcode.
 *
 * Checks for an existing page (by slug or shortcode content) before
 * creating a new one. Stores the page ID in options for reference.
 */
function ontario_obituaries_create_page() {
    // Check if we already created the page.
    $existing_page_id = get_option( 'ontario_obituaries_page_id' );
    if ( $existing_page_id && get_post_status( $existing_page_id ) ) {
        return $existing_page_id;
    }

    // Check if a page with the shortcode already exists.
    global $wpdb;
    $found = $wpdb->get_var(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_type = 'page'
           AND post_status IN ('publish','draft','private')
           AND post_content LIKE '%[ontario_obituaries%'
         LIMIT 1"
    );
    if ( $found ) {
        update_option( 'ontario_obituaries_page_id', intval( $found ) );
        return intval( $found );
    }

    // Check if a page with slug 'obituaries' already exists.
    $by_slug = get_page_by_path( 'obituaries' );
    if ( $by_slug ) {
        // Page exists but doesn't have our shortcode — add it.
        wp_update_post( array(
            'ID'           => $by_slug->ID,
            'post_content' => $by_slug->post_content . "\n\n[ontario_obituaries]",
        ) );
        update_option( 'ontario_obituaries_page_id', $by_slug->ID );
        return $by_slug->ID;
    }

    // Create a new Obituaries page.
    $page_id = wp_insert_post( array(
        'post_title'   => 'Obituaries',
        'post_name'    => 'obituaries',
        'post_content' => '[ontario_obituaries]',
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_author'  => 1,
    ) );

    if ( $page_id && ! is_wp_error( $page_id ) ) {
        update_option( 'ontario_obituaries_page_id', $page_id );
        ontario_obituaries_log( 'Obituaries page created (ID: ' . $page_id . ')', 'info' );
        return $page_id;
    }

    return false;
}

/**
 * Normalize an obituary name for fuzzy dedup comparison.
 *
 * Strips case, extra whitespace, common suffixes like "Obituary",
 * and punctuation so "Ronald McLEOD" matches "Ronald McLeod".
 *
 * @param string $name Raw name.
 * @return string Normalized name for comparison.
 */
function ontario_obituaries_normalize_name( $name ) {
    // Lowercase
    $name = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );
    // Remove common suffixes
    $name = preg_replace( '/\s+(obituary|obit)\.?$/i', '', $name );
    // Remove punctuation except hyphens and spaces
    $name = preg_replace( '/[^a-z0-9\s\-]/u', '', $name );
    // Collapse whitespace
    $name = preg_replace( '/\s+/', ' ', trim( $name ) );
    return $name;
}

/**
 * Duplicate cleanup: remove duplicate obituaries from the database.
 *
 * Three-pass approach:
 *   Pass 1 — Exact match: same name + same date_of_death (fast SQL GROUP BY).
 *   Pass 2 — Fuzzy match: normalized name + same date_of_death (catches
 *            case/punctuation differences like "Ronald McLEOD" vs "Ronald McLeod").
 *            Runs even when records have different funeral_home values.
 *   Pass 3 — Name-only match: normalized name regardless of date_of_death
 *            (catches cross-source date variants). Requires name >= 6 chars.
 *
 * For each group, keeps the record with the longest description and enriches
 * it with funeral_home, city_normalized, location, image_url, and source_url
 * from duplicates.
 *
 * BUG-C4 FIX (v5.0.4): Removed from `init` hook. Now runs only via:
 *   - Daily cron: `ontario_obituaries_dedup_daily` (registered on activation).
 *   - Post-scrape: deferred one-shot cron `ontario_obituaries_dedup_once` (10s after collection).
 *   - Post-REST-cron: deferred one-shot cron `ontario_obituaries_dedup_once` (10s after /cron collection).
 *   - Admin rescan: via maybe_cleanup_duplicates($force=true) — bypasses cooldown.
 *   All automated callers go through ontario_obituaries_maybe_cleanup_duplicates()
 *   which enforces a WP-native lock via add_option()/get_option() and a
 *   1-hour cooldown between runs.
 *
 * @see ontario_obituaries_maybe_cleanup_duplicates() Throttled wrapper.
 */
function ontario_obituaries_cleanup_duplicates() {

    global $wpdb;
    $table = $wpdb->prefix . 'ontario_obituaries';

    // Check table exists
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
        return;
    }

    $removed = 0;

    // v3.6.0: Auto-clean test data on production (records with 'Test' in name
    // and source_url pointing to home_url — added via add_test_data() debug action).
    // QC-R4 FIX: Tightened pattern from '%Test %' to 'Test Obituary%' or 'Test Record%'
    // to prevent accidental deletion of legitimate obituaries containing "Test"
    // (e.g., a real person named "Tester Smith" or "Contest Winner").
    // Also requires source_url to be empty OR match known test domains specifically.
    $test_removed = $wpdb->query(
        "DELETE FROM `{$table}` WHERE ( name LIKE 'Test Obituary%' OR name LIKE 'Test Record%' OR name LIKE 'Test Data%' ) AND ( source_url = '' OR source_url LIKE '%monacomonuments.ca%' OR source_url LIKE '%example.com%' OR source_url LIKE '%localhost%' )"
    );
    if ( $test_removed > 0 ) {
        ontario_obituaries_log( sprintf( 'Removed %d test data records', $test_removed ), 'info' );
        $removed += $test_removed;
    }

    // v3.7.0: Backfill city_normalized from location for records that have a
    // location but no city_normalized (caused by legacy scraper or pre-v3 inserts).
    // This prevents double-slash sitemap URLs like /ontario//name-slug/.
    $backfill_result = $wpdb->query(
        "UPDATE `{$table}` SET city_normalized = TRIM(location) WHERE city_normalized = '' AND location != '' AND location IS NOT NULL AND suppressed_at IS NULL"
    );
    if ( $backfill_result > 0 ) {
        ontario_obituaries_log( sprintf( 'Backfilled city_normalized for %d records', $backfill_result ), 'info' );
    }

    // v3.17.1: Strip trailing 'Obituary' / 'Obit' suffix from names in the DB.
    // Uses simple LIKE + TRIM instead of REGEXP_REPLACE for MySQL 5.7 / MariaDB compat.
    $name_cleaned = 0;
    $obit_rows = $wpdb->get_results(
        "SELECT id, name FROM `{$table}` WHERE (name LIKE '% Obituary' OR name LIKE '% Obit' OR name LIKE '% Obit.') AND suppressed_at IS NULL"
    );
    if ( ! empty( $obit_rows ) ) {
        foreach ( $obit_rows as $orow ) {
            $clean = preg_replace( '/\s+(Obituary|Obit\.?)$/i', '', $orow->name );
            if ( $clean !== $orow->name ) {
                $wpdb->update( $table, array( 'name' => $clean ), array( 'id' => $orow->id ) );
                $name_cleaned++;
            }
        }
        if ( $name_cleaned > 0 ) {
            ontario_obituaries_log( sprintf( 'Stripped Obituary suffix from %d record names', $name_cleaned ), 'info' );
        }
    }

    // ── Pass 1: Exact duplicates (same name + date_of_death) ──────────
    $duplicates = $wpdb->get_results(
        "SELECT name, date_of_death, COUNT(*) as cnt
         FROM `{$table}`
         WHERE suppressed_at IS NULL
         GROUP BY name, date_of_death
         HAVING cnt > 1"
    );

    if ( ! empty( $duplicates ) ) {
        foreach ( $duplicates as $dup ) {
            $removed += ontario_obituaries_merge_duplicate_group( $table, $dup->name, $dup->date_of_death );
        }
    }

    // ── Pass 2: Fuzzy duplicates (normalized name + date_of_death) ────
    // Fetch all active records and group by normalized name + death date.
    $all_rows = $wpdb->get_results(
        "SELECT id, name, date_of_death FROM `{$table}` WHERE suppressed_at IS NULL ORDER BY id"
    );

    if ( ! empty( $all_rows ) ) {
        $fuzzy_groups = array();
        foreach ( $all_rows as $row ) {
            $norm_key = ontario_obituaries_normalize_name( $row->name ) . '|' . $row->date_of_death;
            $fuzzy_groups[ $norm_key ][] = $row;
        }

        foreach ( $fuzzy_groups as $group ) {
            if ( count( $group ) <= 1 ) {
                continue;
            }
            // Use the first record's name/date as the canonical lookup
            // Re-run merge on all IDs in this fuzzy group
            $ids = wp_list_pluck( $group, 'id' );
            $removed += ontario_obituaries_merge_duplicate_ids( $table, $ids );
        }
    }

    // ── Pass 3: Name-only duplicates (v3.17.1) ──────────────────────
    // Catches duplicates scraped with different death dates or from
    // sources that report slightly different dates for the same person.
    // Groups by normalized name only; keeps the record with the longest
    // description and most recent death date.
    //
    // BUG-M4 FIX (v5.0.5): Added 90-day date-range guard to prevent merging
    // distinct individuals who share the same normalized name but died in
    // different periods (e.g., "John Smith" who died in 2022 vs. a different
    // "John Smith" who died in 2024).  Without this guard, Pass 3 would merge
    // them into one record — violating the "100% factual" publishing rule.
    //
    // Guard logic: within each name group, only merge records whose death dates
    // are ALL within 90 days of the earliest death date in the group.  If the
    // spread exceeds 90 days, the group is split into sub-groups by proximity.
    // 90 days is generous — most cross-source date discrepancies are <7 days,
    // but some funeral homes backdate notices by weeks.
    $all_rows_p3 = $wpdb->get_results(
        "SELECT id, name, date_of_death FROM `{$table}` WHERE suppressed_at IS NULL ORDER BY id"
    );

    if ( ! empty( $all_rows_p3 ) ) {
        $name_only_groups = array();
        foreach ( $all_rows_p3 as $row ) {
            $norm_name = ontario_obituaries_normalize_name( $row->name );
            if ( strlen( $norm_name ) < 6 ) {
                continue; // Skip very short names to avoid false positives
            }
            $name_only_groups[ $norm_name ][] = $row;
        }

        $pass3_removed = 0;
        foreach ( $name_only_groups as $group ) {
            if ( count( $group ) <= 1 ) {
                continue;
            }

            // BUG-M4 FIX: Split group into date-proximity sub-groups (90 days).
            // Sort by death date, then cluster: any row >90 days from the
            // sub-group's earliest date starts a new sub-group.
            usort( $group, function ( $a, $b ) {
                return strcmp( $a->date_of_death, $b->date_of_death );
            } );

            $sub_groups    = array();
            $current_sub   = array( $group[0] );
            $anchor_ts     = strtotime( $group[0]->date_of_death );
            $max_spread    = 90 * DAY_IN_SECONDS; // 90 days.

            for ( $i = 1, $len = count( $group ); $i < $len; $i++ ) {
                $row_ts = strtotime( $group[ $i ]->date_of_death );
                // If death date is unparseable (0 or false), treat as unknown —
                // group with previous to avoid orphaning.
                if ( $row_ts && $anchor_ts && ( $row_ts - $anchor_ts ) > $max_spread ) {
                    // Start a new sub-group.
                    $sub_groups[]  = $current_sub;
                    $current_sub   = array( $group[ $i ] );
                    $anchor_ts     = $row_ts;
                } else {
                    $current_sub[] = $group[ $i ];
                }
            }
            $sub_groups[] = $current_sub; // Push final sub-group.

            // Merge only within each date-proximate sub-group.
            foreach ( $sub_groups as $sub ) {
                if ( count( $sub ) <= 1 ) {
                    continue;
                }
                $ids = wp_list_pluck( $sub, 'id' );
                $pass3_removed += ontario_obituaries_merge_duplicate_ids( $table, $ids );
            }
        }
        $removed += $pass3_removed;

        if ( $pass3_removed > 0 ) {
            ontario_obituaries_log( sprintf( 'Pass 3 (name-only, 90-day guard): merged %d duplicate records', $pass3_removed ), 'info' );
        }
    }

    // Clear transient caches so pages reflect the deduped data
    delete_transient( 'ontario_obituaries_locations_cache' );
    delete_transient( 'ontario_obituaries_funeral_homes_cache' );

    if ( $removed > 0 ) {
        ontario_obituaries_log( sprintf( 'Duplicate cleanup v%s: removed %d duplicate obituaries', ONTARIO_OBITUARIES_VERSION, $removed ), 'info' );

        // v3.5.0: Purge LiteSpeed listing caches so deduped results appear immediately.
        ontario_obituaries_purge_litespeed( 'ontario_obits' );
    }
}
/**
 * Throttled wrapper for duplicate cleanup — WP-native lock via add_option/get_option.
 *
 * BUG-C4 FIX (v5.0.4): Replaces the `init` hook.
 *
 * Design decisions addressing QC review (rounds 1–3):
 *   1. Uses add_option()/get_option() instead of raw INSERT IGNORE so the WP
 *      object-cache and notoptions-cache stay in sync.  add_option() returns
 *      false without writing when the key already exists (atomic enough for a
 *      distributed-cron mutex — worst case is two near-simultaneous runs,
 *      which is harmless because the dedup merge is deterministic: both
 *      SELECT queries use ORDER BY desc_len DESC, id ASC, so the same winner
 *      is always picked, and DELETE on already-deleted IDs is a no-op).
 *   2. Lock value is a JSON object {ts:<epoch>,state:"running"|"done"} so we
 *      can distinguish "actively running" from "completed cooldown".
 *      json_decode failure treated as stale-running (safe fallback).
 *   3. Stale-lock TTL is 1 hour: the dedup job processes ≤725 rows with 3
 *      lightweight passes.  PHP max_execution_time (30-300s) will kill the
 *      process long before 1 hour.  A future scale increase should raise
 *      stale_ttl proportionally.
 *   4. No recursive retry: stale-lock clearance returns false; the next
 *      scheduled cron invocation (daily or one-shot) will re-acquire.
 *   5. Cooldown timestamp written only on success (inside try body, not
 *      finally), so fatal errors don't mark the lock as "done".
 *   6. On fatal: lock left with state="running" and stale_ttl auto-clears
 *      on next attempt.  deactivate() and on_plugin_update() also delete it.
 *   7. Logging: errors always; stale-lock warnings always; success at 'debug';
 *      throttled hits silent except once per day (low-frequency heartbeat so
 *      a stuck lock is still observable).
 *   8. $force=true (admin rescan) bypasses 1-hour cooldown but NOT a 60-second
 *      refractory window — prevents admin-click-spam from triggering repeated
 *      full-table scans.
 *
 * Accepted residual risks (documented, not fixable without external deps):
 *   - add_option() is not a true mutex under persistent object cache with
 *     sub-ms race windows.  Mitigated: dedup merge is deterministic
 *     (ORDER BY id ASC tiebreaker), so concurrent runs converge to same state.
 *   - wp_next_scheduled() in schedule_dedup_once() is non-atomic; duplicate
 *     one-shot events possible.  Mitigated: lock prevents double execution.
 *   - stale_ttl is a time assumption; extremely slow DBs could exceed it.
 *     Mitigated: 120× safety margin over observed execution time (<30s).
 *
 * Lock lifecycle:
 *   add_option succeeds  →  state=running  →  cleanup  →  state=done (cooldown)
 *   add_option fails     →  check state:
 *     running + age > stale_ttl  →  delete_option + return false (next cron retries)
 *     done    + age > cooldown   →  update_option to running → cleanup → done
 *     done    + $force + age>60s →  update_option to running → cleanup → done
 *     json_decode fails          →  treated as running with ts=0 (→ stale path)
 *     otherwise                  →  return false (throttled)
 *
 * @param bool $force  If true, bypass 1-hour cooldown (but not 60s refractory).
 * @return bool True if cleanup ran, false if throttled/locked.
 */
function ontario_obituaries_maybe_cleanup_duplicates( $force = false ) {
    $lock_key    = 'ontario_obituaries_dedup_lock';
    $now         = time();
    $cooldown    = HOUR_IN_SECONDS;     // 1-hour minimum between normal runs.
    $refractory  = 60;                  // 60-second minimum even for $force.
    $stale_ttl   = HOUR_IN_SECONDS;     // Force-clear running locks after 1 hour.

    // ── Attempt lock acquisition via WP-native add_option ────────────
    // add_option() returns false if the key already exists, keeping the
    // WP object-cache and notoptions-cache in sync (unlike raw SQL).
    $lock_data = wp_json_encode( array( 'ts' => $now, 'state' => 'running' ) );
    $acquired  = add_option( $lock_key, $lock_data, '', false );

    if ( ! $acquired ) {
        // Lock row exists — read current state.
        $raw  = get_option( $lock_key, '' );
        $lock = is_string( $raw ) ? json_decode( $raw, true ) : null;

        // Defensive: if json_decode fails (corrupt value, legacy scalar, etc.)
        // treat as stale running lock with ts=0 so the stale_ttl path clears it.
        if ( ! is_array( $lock ) || ! isset( $lock['ts'] ) || ! isset( $lock['state'] ) ) {
            $lock = array( 'ts' => 0, 'state' => 'running' );
        }

        $lock_ts = (int) $lock['ts'];
        $lock_st = (string) $lock['state'];
        $age     = $now - $lock_ts;

        // Case A: Lock is "running" and older than stale_ttl → crash recovery.
        // Delete and return false; the next scheduled event will retry.
        // No recursive call — avoids unbounded retry on repeated fatals.
        if ( 'running' === $lock_st && $age > $stale_ttl ) {
            delete_option( $lock_key );
            ontario_obituaries_log( 'Dedup: stale running-lock cleared (age=' . $age . 's).', 'warning' );
            return false;
        }

        // Case B: Lock is "done" (cooldown period).
        if ( 'done' === $lock_st ) {
            // $force bypasses the 1-hour cooldown but NOT the 60s refractory.
            // This prevents admin click-spam from triggering repeated full scans.
            $threshold = $force ? $refractory : $cooldown;
            if ( $age >= $threshold ) {
                // Re-acquire: overwrite with running state.
                update_option( $lock_key, wp_json_encode( array( 'ts' => $now, 'state' => 'running' ) ), false );
                // Fall through to run cleanup below.
            } else {
                // Within cooldown/refractory — skip.
                // Low-frequency heartbeat: log once per day so a permanently
                // throttled state is still observable.
                $last_throttle_log = (int) get_option( 'ontario_obituaries_dedup_throttle_log', 0 );
                if ( ( $now - $last_throttle_log ) > DAY_IN_SECONDS ) {
                    ontario_obituaries_log( 'Dedup throttled (cooldown ' . $age . '/' . $threshold . 's). Daily heartbeat.', 'debug' );
                    update_option( 'ontario_obituaries_dedup_throttle_log', $now, false );
                }
                return false;
            }
        } else {
            // Lock is "running" and within stale_ttl — another process is active.
            return false;
        }
    }

    // ── Run cleanup inside try/catch ─────────────────────────────────
    // On success: update lock to "done" with current timestamp (cooldown starts).
    // On failure: leave lock as "running" — stale_ttl will auto-clear it,
    // or deactivate/update will delete it.  No cooldown timestamp written on
    // failure so the next retry isn't blocked.
    $ran_ok = false;
    try {
        ontario_obituaries_cleanup_duplicates();
        $ran_ok = true;
        // Mark completion — starts the cooldown window.
        update_option( $lock_key, wp_json_encode( array( 'ts' => time(), 'state' => 'done' ) ), false );
        ontario_obituaries_log( 'Dedup completed successfully.', 'debug' );
        // v5.3.0 Phase 2a: Record "ran" + "progress" on successful dedup.
        if ( function_exists( 'oo_health_record_ran' ) ) {
            oo_health_record_ran( 'DEDUP' );
        }
        if ( function_exists( 'oo_health_record_success' ) ) {
            oo_health_record_success( 'DEDUP' );
        }
    } catch ( \Throwable $e ) {
        // QC-R5 FIX: catch \Throwable (not just \Exception) for PHP 7+ coverage.
        // \TypeError, OOM-triggered \Error, etc. bypass \Exception catch blocks.
        // QC-R6 FIX: Clear the lock on caught errors to avoid 1-hour functional
        // freeze.  Only truly unrecoverable fatals (segfault, OOM kill by OS)
        // leave the lock in "running" state for stale_ttl to clear.
        delete_option( $lock_key );
        // v5.3.0 Phase 2a: Structured error logging replaces plain string.
        if ( function_exists( 'oo_log' ) ) {
            oo_log( 'error', 'DEDUP', 'CRON_DEDUP_CRASH', $e->getMessage(), array(
                'exception' => $e,
                'hook'      => 'ontario_obituaries_dedup',
            ) );
        } else {
            ontario_obituaries_log( 'Dedup failed: ' . $e->getMessage(), 'error' );
        }
    }
    // On process-kill fatal (not caught by \Throwable): lock stays as "running";
    // stale_ttl (1 hour) clears it on next attempt.

    return $ran_ok;
}

// BUG-C4 FIX (v5.0.4): Daily cron hook for recurring scheduled dedup.
// Registered in ontario_obituaries_activate(). Fires once per day.
add_action( 'ontario_obituaries_dedup_daily', 'ontario_obituaries_maybe_cleanup_duplicates' );

// BUG-C4 FIX (v5.0.4): Separate one-shot hook for post-scrape/post-REST dedup.
// Uses a distinct hook name so wp_clear_scheduled_hook('ontario_obituaries_dedup_daily')
// does not cancel pending one-shot events, and vice versa.
add_action( 'ontario_obituaries_dedup_once', 'ontario_obituaries_maybe_cleanup_duplicates' );

/**
 * Schedule a one-shot dedup event (10s from now) if none is already pending.
 *
 * Uses the dedicated 'ontario_obituaries_dedup_once' hook (separate from the
 * daily cron) so clearing/rescheduling the daily event doesn't cancel post-scrape
 * cleanup, and vice versa.
 *
 * Guard: wp_next_scheduled() prevents duplicate events from repeated calls
 * within the same cron cycle.  Even if two events slip through (non-atomic
 * check), the lock in maybe_cleanup_duplicates() prevents double execution.
 *
 * @return bool True if a new event was scheduled, false if one already exists.
 */
function ontario_obituaries_schedule_dedup_once() {
    if ( ! wp_next_scheduled( 'ontario_obituaries_dedup_once' ) ) {
        wp_schedule_single_event( time() + 10, 'ontario_obituaries_dedup_once' );
        return true;
    }
    return false;
}

/**
 * Merge a group of exact duplicates by name + date_of_death.
 *
 * @param string $table         Table name.
 * @param string $name          Obituary name.
 * @param string $date_of_death Death date.
 * @return int Number of rows removed.
 */
function ontario_obituaries_merge_duplicate_group( $table, $name, $date_of_death ) {
    global $wpdb;

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, funeral_home, location, city_normalized, image_url, source_url, description,
                CHAR_LENGTH(COALESCE(description, '')) as desc_len
         FROM `{$table}`
         WHERE name = %s AND date_of_death = %s AND suppressed_at IS NULL
         ORDER BY desc_len DESC, id ASC",
        $name,
        $date_of_death
    ) );

    if ( count( $rows ) <= 1 ) {
        return 0;
    }

    return ontario_obituaries_merge_rows( $table, $rows );
}

/**
 * Merge a group of duplicates by explicit ID list (used for fuzzy dedup).
 *
 * @param string $table Table name.
 * @param array  $ids   Array of obituary IDs.
 * @return int Number of rows removed.
 */
function ontario_obituaries_merge_duplicate_ids( $table, $ids ) {
    global $wpdb;

    if ( count( $ids ) <= 1 ) {
        return 0;
    }

    $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, funeral_home, location, city_normalized, image_url, source_url, description,
                CHAR_LENGTH(COALESCE(description, '')) as desc_len
         FROM `{$table}`
         WHERE id IN ({$placeholders}) AND suppressed_at IS NULL
         ORDER BY desc_len DESC, id ASC",
        $ids
    ) );

    if ( count( $rows ) <= 1 ) {
        return 0;
    }

    return ontario_obituaries_merge_rows( $table, $rows );
}

/**
 * Core merge logic: keep the best record, enrich from duplicates, delete rest.
 *
 * @param string $table Table name.
 * @param array  $rows  Array of row objects (longest desc first).
 * @return int Number of rows removed.
 */
function ontario_obituaries_merge_rows( $table, $rows ) {
    global $wpdb;

    $keep = $rows[0];
    $enrichments = array();
    $removed = 0;

    for ( $i = 1; $i < count( $rows ); $i++ ) {
        $dupe = $rows[ $i ];

        // Enrich: fill empty funeral_home from duplicate
        if ( empty( $keep->funeral_home ) && ! empty( $dupe->funeral_home ) ) {
            $enrichments['funeral_home'] = $dupe->funeral_home;
            $keep->funeral_home = $dupe->funeral_home;
        }

        // Enrich: fill empty city_normalized from duplicate
        if ( empty( $keep->city_normalized ) && ! empty( $dupe->city_normalized ) ) {
            $enrichments['city_normalized'] = $dupe->city_normalized;
            $keep->city_normalized = $dupe->city_normalized;
        }

        // Enrich: fill empty location from duplicate
        if ( empty( $keep->location ) && ! empty( $dupe->location ) ) {
            $enrichments['location'] = $dupe->location;
            $keep->location = $dupe->location;
        }

        // Enrich: fill empty image_url from duplicate
        if ( empty( $keep->image_url ) && ! empty( $dupe->image_url ) ) {
            $enrichments['image_url'] = $dupe->image_url;
            $keep->image_url = $dupe->image_url;
        }

        // Enrich: fill empty source_url from duplicate
        if ( empty( $keep->source_url ) && ! empty( $dupe->source_url ) ) {
            $enrichments['source_url'] = $dupe->source_url;
            $keep->source_url = $dupe->source_url;
        }

        // Enrich: prefer longer description (use mb_strlen for multibyte safety)
        $keep_desc_len = function_exists( 'mb_strlen' ) ? mb_strlen( (string) $keep->description, 'UTF-8' ) : strlen( (string) $keep->description );
        $dupe_desc_len = function_exists( 'mb_strlen' ) ? mb_strlen( (string) $dupe->description, 'UTF-8' ) : strlen( (string) $dupe->description );
        if ( ! empty( $dupe->description ) && $dupe_desc_len > $keep_desc_len ) {
            $enrichments['description'] = $dupe->description;
            $keep->description = $dupe->description;
        }

        $wpdb->delete( $table, array( 'id' => $dupe->id ), array( '%d' ) );
        $removed++;
    }

    // Apply enrichments to the kept record
    if ( ! empty( $enrichments ) ) {
        $wpdb->update( $table, $enrichments, array( 'id' => $keep->id ) );
    }

    return $removed;
}

/**
 * One-time page creation on next page load (for updates via WP Pusher
 * where the activation hook doesn't re-fire).
 */
function ontario_obituaries_maybe_create_page() {
    // v3.0.1: Reset stale menu flag so the improved detection can re-run.
    if ( get_option( 'ontario_obituaries_menu_added' ) && ! get_option( 'ontario_obituaries_menu_v2' ) ) {
        delete_option( 'ontario_obituaries_menu_added' );
        update_option( 'ontario_obituaries_menu_v2', true );
    }

    if ( get_option( 'ontario_obituaries_page_id' ) && get_post_status( get_option( 'ontario_obituaries_page_id' ) ) ) {
        // Page exists — make sure it's in the nav menu.
        ontario_obituaries_add_to_menu();
        return;
    }
    ontario_obituaries_create_page();
    ontario_obituaries_add_to_menu();
}
add_action( 'init', 'ontario_obituaries_maybe_create_page' );

/**
 * Add the Obituaries page to the primary navigation menu.
 *
 * Finds the active nav menu (tries 'menu-monaco', primary location,
 * or the first available menu) and adds the Obituaries page if not
 * already present. Runs once and stores a flag to avoid repeated checks.
 */
function ontario_obituaries_add_to_menu() {
    $page_id = get_option( 'ontario_obituaries_page_id' );
    if ( ! $page_id || ! get_post_status( $page_id ) ) {
        return;
    }

    // Collect all menus and check every one for the Obituaries page.
    $all_menus = wp_get_nav_menus();
    if ( empty( $all_menus ) ) {
        return;
    }

    // Check if Obituaries is already in ANY menu.
    foreach ( $all_menus as $check_menu ) {
        $items = wp_get_nav_menu_items( $check_menu->term_id );
        if ( $items ) {
            foreach ( $items as $item ) {
                if ( (int) $item->object_id === (int) $page_id && 'page' === $item->object ) {
                    update_option( 'ontario_obituaries_menu_added', true );
                    return; // Already in a menu.
                }
            }
        }
    }

    // Already attempted and succeeded before — don't re-add.
    if ( get_option( 'ontario_obituaries_menu_added' ) ) {
        return;
    }

    // Find the header menu. The site uses 'menu-monaco' (id from HTML).
    // Try multiple detection strategies.
    $target_menu = null;

    // Strategy 1: Find menu containing the known menu items (Home, About us, etc.)
    foreach ( $all_menus as $candidate ) {
        $items = wp_get_nav_menu_items( $candidate->term_id );
        if ( $items && count( $items ) >= 4 ) {
            foreach ( $items as $item ) {
                if ( in_array( strtolower( $item->title ), array( 'home', 'about us', 'catalog', 'gallery', 'contact us' ), true ) ) {
                    $target_menu = $candidate;
                    break 2;
                }
            }
        }
    }

    // Strategy 2: Try by slug/name.
    if ( ! $target_menu ) {
        foreach ( array( 'menu-monaco', 'monaco', 'Monaco', 'Menu Monaco' ) as $slug ) {
            $target_menu = wp_get_nav_menu_object( $slug );
            if ( $target_menu ) break;
        }
    }

    // Strategy 3: Try theme locations.
    if ( ! $target_menu ) {
        $locations = get_nav_menu_locations();
        foreach ( array( 'primary', 'main', 'header', 'top', 'main-menu', 'primary-menu' ) as $loc ) {
            if ( ! empty( $locations[ $loc ] ) ) {
                $target_menu = wp_get_nav_menu_object( $locations[ $loc ] );
                if ( $target_menu ) break;
            }
        }
    }

    // Strategy 4: First menu with 3+ items (likely the header nav).
    if ( ! $target_menu ) {
        foreach ( $all_menus as $candidate ) {
            $items = wp_get_nav_menu_items( $candidate->term_id );
            if ( $items && count( $items ) >= 3 ) {
                $target_menu = $candidate;
                break;
            }
        }
    }

    if ( ! $target_menu ) {
        return;
    }

    // Add the Obituaries page to the menu.
    $menu_item_id = wp_update_nav_menu_item( $target_menu->term_id, 0, array(
        'menu-item-title'     => 'Obituaries',
        'menu-item-object'    => 'page',
        'menu-item-object-id' => $page_id,
        'menu-item-type'      => 'post_type',
        'menu-item-status'    => 'publish',
    ) );

    if ( $menu_item_id && ! is_wp_error( $menu_item_id ) ) {
        update_option( 'ontario_obituaries_menu_added', true );
        ontario_obituaries_log( 'Obituaries added to nav menu: ' . $target_menu->name . ' (item ID: ' . $menu_item_id . ')', 'info' );
    }
}

/**
 * Deactivation hook
 */
function ontario_obituaries_deactivate() {
    delete_transient( 'ontario_obituaries_activation_notice' );
    delete_transient( 'ontario_obituaries_table_exists' );
    delete_transient( 'ontario_obituaries_locations_cache' );
    delete_transient( 'ontario_obituaries_funeral_homes_cache' );

    // P0-1 QC FIX: Clear ALL scheduled instances, not just the next one
    wp_clear_scheduled_hook( 'ontario_obituaries_collection_event' );
    wp_clear_scheduled_hook( 'ontario_obituaries_initial_collection' );

    // v5.1.4: Clear the repeating AI rewrite batch event.
    // Without this, the cron keeps firing after plugin deactivation.
    wp_clear_scheduled_hook( 'ontario_obituaries_ai_rewrite_batch' );

    // v5.2.0: Clear image localizer cron and lock.
    if ( class_exists( 'Ontario_Obituaries_Image_Localizer' ) ) {
        Ontario_Obituaries_Image_Localizer::deactivate();
    }

    // BUG-C4 FIX (v5.0.4): Clear both dedup cron hooks and the lock.
    wp_clear_scheduled_hook( 'ontario_obituaries_dedup_daily' );
    wp_clear_scheduled_hook( 'ontario_obituaries_dedup_once' );
    delete_option( 'ontario_obituaries_dedup_lock' );
    delete_option( 'ontario_obituaries_dedup_throttle_log' );
    delete_transient( 'ontario_obituaries_cron_rest_limiter' ); // QC-R5: REST /cron rate limiter.
    // BUG-M1 FIX (v5.0.6): Shared Groq rate limiter cleanup.
    // v5.0.9: Migrated from transient to wp_options for atomic CAS.
    delete_transient( 'ontario_obituaries_groq_tpm_window' );    // Legacy transient (pre-v5.0.7).
    if ( class_exists( 'Ontario_Obituaries_Groq_Rate_Limiter' ) ) {
        Ontario_Obituaries_Groq_Rate_Limiter::reset();
    } else {
        delete_option( 'ontario_obituaries_groq_rate_window' );
    }
    delete_transient( 'ontario_obituaries_collection_cooldown' ); // QC-R10 (v5.0.10): Collection burst guard.

    // v3.0.0: Remove SEO rewrite rules
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'ontario_obituaries_deactivate' );

/**
 * Scheduled task hook for collecting obituaries.
 * v3.0.0: Uses Source Collector (adapter-based) as primary, falls back to legacy scraper.
 *
 * QC-R10 FIX (v5.0.10): Collection cooldown (10 min) set ONLY after
 * $collector->collect() returns successfully. This ensures that:
 *   - Domain check failures do NOT set cooldown (retries immediately).
 *   - Constructor failures do NOT set cooldown (retries immediately).
 *   - collect() exceptions/fatals do NOT set cooldown (retries immediately).
 * Only a successful scrape-start-and-return sets the 10-min guard.
 */
function ontario_obituaries_scheduled_collection() {
    // v4.2.0: Domain lock — don't scrape on unauthorized domains.
    if ( ! ontario_obituaries_is_authorized_domain() ) {
        ontario_obituaries_log( 'Domain lock: Scheduled collection blocked — unauthorized domain.', 'warning' );
        return;
    }

    // QC-R10 FIX (v5.0.10): Prevent burst scraping (duplicate WP-Cron fires,
    // rapid admin rescrape clicks). Minimum 10 min between collection runs.
    // Cooldown is checked here but SET only AFTER collect() returns (below).
    if ( get_transient( 'ontario_obituaries_collection_cooldown' ) ) {
        ontario_obituaries_log( 'Collection: Skipped — cooldown active (< 10 min since last run).', 'info' );
        return;
    }

    // v3.0.0: Use the new Source Collector
    if ( class_exists( 'Ontario_Obituaries_Source_Collector' ) ) {
        $collector = new Ontario_Obituaries_Source_Collector();
    } else {
        // Fallback to legacy scraper
        if ( ! class_exists( 'Ontario_Obituaries_Scraper' ) ) {
            require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries-scraper.php';
        }
        $collector = new Ontario_Obituaries_Scraper();
    }

    // NOTE: Cooldown is NOT set here. It is set AFTER collect() returns
    // successfully (below). If collect() throws an exception or fatal,
    // the cooldown transient is never written, allowing immediate retry.
    //
    // v6.0.0: Wrapped in oo_safe_call() for structured error logging.
    // On Throwable: logs subsystem=SCRAPE, code=CRON_COLLECTION_CRASH,
    // returns null (so cooldown is NOT set, and next tick retries).
    $results = function_exists( 'oo_safe_call' )
        ? oo_safe_call( 'SCRAPE', 'CRON_COLLECTION_CRASH', function() use ( $collector ) {
            return $collector->collect();
        }, null, array( 'hook' => 'ontario_obituaries_collection_event' ) )
        : $collector->collect();

    // v6.0.0: If oo_safe_call caught a Throwable, $results is null.
    // Bail out without setting cooldown — next tick retries immediately.
    if ( null === $results ) {
        return;
    }

    // QC-R10 FIX (v5.0.10): Cooldown set ONLY after collect() completed.
    // If $collector->collect() threw an uncaught exception or triggered a
    // PHP fatal, execution never reaches this line — no cooldown is set,
    // and the next cron tick can retry immediately.
    set_transient( 'ontario_obituaries_collection_cooldown', time(), 600 ); // 10 min TTL.

    update_option( 'ontario_obituaries_last_collection', array(
        'timestamp' => current_time( 'mysql' ),
        'completed' => current_time( 'mysql' ),
        'results'   => $results,
    ) );

    // v6.0.0: Record successful collection for health monitoring (QC Tweak 5).
    // v5.3.0 Phase 2a QC-Adj-3: Record "ran" unconditionally, "progress" on success.
    if ( function_exists( 'oo_health_record_ran' ) ) {
        oo_health_record_ran( 'SCRAPE' );
    }
    if ( function_exists( 'oo_health_record_success' ) ) {
        oo_health_record_success( 'SCRAPE' );
    }

    delete_transient( 'ontario_obituaries_locations_cache' );
    delete_transient( 'ontario_obituaries_funeral_homes_cache' );

    if ( empty( $results['errors'] ) ) {
        delete_option( 'ontario_obituaries_last_scrape_errors' );
    }

    // v3.5.0: Purge LiteSpeed listing/hub caches after scrape so new obituaries
    // appear immediately.  Uses tag-based purge to avoid clearing the entire site.
    ontario_obituaries_purge_litespeed( 'ontario_obits' );

    // v3.8.0: Diagnostic — if the scraper found 0 obituaries across ALL sources,
    // something is wrong (adapter selectors stale, network issue, etc.). Always log this.
    if ( isset( $results['obituaries_found'] ) && 0 === intval( $results['obituaries_found'] ) ) {
        ontario_obituaries_log(
            sprintf(
                'CRITICAL: Scheduled collection found 0 obituaries across %d sources. Sources processed: %d, skipped: %d. Check adapter selectors and source URLs.',
                isset( $results['sources_processed'] ) ? intval( $results['sources_processed'] ) : 0,
                isset( $results['sources_processed'] ) ? intval( $results['sources_processed'] ) : 0,
                isset( $results['sources_skipped'] ) ? intval( $results['sources_skipped'] ) : 0
            ),
            'error'
        );
    }

    // v3.3.0: Run dedup after every scrape to catch cross-source duplicates.
    // BUG-C4 FIX (v5.0.4): Deferred to a one-shot cron event (10s later) so
    // cleanup runs in its own request, not blocking the scrape response.
    // Uses 'ontario_obituaries_dedup_once' (separate from daily cron) so
    // wp_clear_scheduled_hook on one doesn't cancel the other.
    ontario_obituaries_schedule_dedup_once();

    // v4.6.0: IndexNow is now triggered after AI rewrite publishes records,
    // not after scraping. Pending records should NOT be submitted to search engines.
    // See ontario_obituaries_ai_rewrite_batch() for the IndexNow trigger.

    // v5.1.4: Rewrite batch is now a repeating event. Ensure it exists if
    // AI rewrites are enabled and a Groq key is configured.
    $groq_key_for_rewrite  = get_option( 'ontario_obituaries_groq_api_key', '' );
    $settings_for_rewrite  = ontario_obituaries_get_settings();
    if ( ! empty( $settings_for_rewrite['ai_rewrite_enabled'] ) && ! empty( $groq_key_for_rewrite ) ) {
        if ( ! wp_next_scheduled( 'ontario_obituaries_ai_rewrite_batch' ) ) {
            wp_schedule_event( time() + 60, 'ontario_five_minutes', 'ontario_obituaries_ai_rewrite_batch' );
            ontario_obituaries_log( 'v5.1.4: Rewrite schedule was missing after collection — re-registered.', 'warning' );
        }
    }

    // v4.3.0: After collection, schedule GoFundMe linker batch if enabled.
    // BUG-M5 FIX (v5.0.6): Staggered +300s to avoid overlap with rewrite (+60s).
    $settings_for_rewrite = ontario_obituaries_get_settings();
    if ( ! empty( $settings_for_rewrite['gofundme_enabled'] ) ) {
        if ( ! wp_next_scheduled( 'ontario_obituaries_gofundme_batch' ) ) {
            wp_schedule_single_event( time() + 300, 'ontario_obituaries_gofundme_batch' );
            ontario_obituaries_log( 'v4.3.0: Scheduled GoFundMe linker batch (300s after collection).', 'info' );
        }
    }
}
add_action( 'ontario_obituaries_collection_event', 'ontario_obituaries_scheduled_collection' );

/**
 * v4.1.0: AI Rewrite batch cron handler.
 *
 * v5.0.1: Simplified — processes ONE obituary at a time in a loop.
 *
 * When triggered by cPanel cron (php wp-cron.php every 5 min), this runs
 * server-side. It processes 1 obituary per iteration with 12s delays,
 * looping for up to 4 minutes = ~18 per run (theoretical max).
 * In practice, ~15 per 5-min window before Groq free-tier 6,000 TPM hit.
 *
 * Uses a transient lock so it won’t overlap with the shutdown hook or AJAX.
 * Self-reschedules if more remain.
 */
function ontario_obituaries_ai_rewrite_batch() {
    @set_time_limit( 300 ); // Allow up to 5 minutes.

    // ── Required fix #2: Settings gate ──────────────────────────────────
    // If the UI checkbox exists, it must gate execution. Having a configured
    // key alone is not enough — the admin may have deliberately disabled rewrites.
    $settings = function_exists( 'ontario_obituaries_get_settings' )
        ? ontario_obituaries_get_settings()
        : get_option( 'ontario_obituaries_settings', array() );
    if ( empty( $settings['ai_rewrite_enabled'] ) ) {
        if ( function_exists( 'oo_log' ) ) {
            oo_log( 'info', 'REWRITE', 'CRON_REWRITE_DISABLED', 'AI Rewriter: Skipping batch — disabled in settings.' );
        } else {
            ontario_obituaries_log( 'AI Rewriter: Skipping batch — disabled in settings.', 'info' );
        }
        return;
    }

    // ── Required fix #1: Bootstrap in try/catch ─────────────────────────
    // require_once or constructor can throw (file missing, TypeError, etc.).
    // Catch early so even bootstrap failures get structured logging.
    try {
        if ( ! class_exists( 'Ontario_Obituaries_AI_Rewriter' ) ) {
            require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ai-rewriter.php';
        }
        $rewriter = new Ontario_Obituaries_AI_Rewriter( 1 );
    } catch ( \Throwable $e ) {
        if ( function_exists( 'oo_log' ) ) {
            oo_log( 'error', 'REWRITE', 'CRON_REWRITE_BOOTSTRAP_CRASH', $e->getMessage(), array(
                'exception' => $e,
                'hook'      => 'ontario_obituaries_ai_rewrite_batch',
            ) );
        } else {
            ontario_obituaries_log( 'AI Rewriter bootstrap crash: ' . $e->getMessage(), 'error' );
        }
        return;
    }

    if ( ! $rewriter->is_configured() ) {
        if ( function_exists( 'oo_log' ) ) {
            oo_log( 'info', 'REWRITE', 'CRON_REWRITE_NO_KEY', 'AI Rewriter: Skipping batch — Groq API key not configured.' );
        } else {
            ontario_obituaries_log( 'AI Rewriter: Skipping batch — Groq API key not configured.', 'info' );
        }
        return;
    }

    // ── MUTUAL EXCLUSION: Prevent overlap with shutdown hook / AJAX ──
    if ( get_transient( 'ontario_obituaries_rewriter_running' ) ) {
        if ( function_exists( 'oo_log' ) ) {
            oo_log( 'info', 'REWRITE', 'CRON_REWRITE_LOCKED', 'AI Rewriter WP-Cron: Another instance running (transient lock). Skipping.' );
        } else {
            ontario_obituaries_log( 'AI Rewriter WP-Cron: Another instance running (transient lock). Skipping.', 'info' );
        }
        return;
    }
    set_transient( 'ontario_obituaries_rewriter_running', 'wp-cron', 300 ); // 5-min TTL.

    // v5.3.0 Phase 2a QC-Adj-1: Initialize ALL counters before try{} so
    // post-processing code never references undefined variables on early crash.
    $start_time        = time();
    // QC-R10 FIX (v5.0.10): max_runtime=60s, reschedule every 150-210s (3 min ± 30s).
    //   Gap analysis: max_runtime (60s) + minimum reschedule interval (150s) = 210s.
    //   A prior job CANNOT overlap the next because 60s runtime + 150s delay = 210s
    //   minimum gap, while cron resolution is 60s. Even if WP-Cron fires early by
    //   60s, the transient lock ('ontario_obituaries_rewriter_running', 300s TTL)
    //   prevents overlap.
    //   Throughput: 2-4 obituaries/batch × ~20 batches/hr ≈ 40-80 obituaries/hr.
    //   CPU duty cycle: 60s work / 210s cycle ≈ 29%, safe for shared hosting.
    //   JITTER PATHS AUDIT (QC-R10): Only this code block reschedules the rewriter.
    //     - ontario_obituaries_scheduled_collection() schedules a one-shot +60s event
    //       ONLY if no event exists (wp_next_scheduled guard at line ~1414).
    //     - shutdown rewriter does NOT self-reschedule (it's a one-shot piggyback).
    //     - AJAX rewriter does NOT self-reschedule (JS auto-repeats client-side).
    //     - cron-rewriter.php (CLI) has its own 12s delay loop, does not use WP-Cron.
    //     Therefore, this is the ONLY self-reschedule path. No double-scheduling.
    $max_runtime       = 60;
    $total_ok          = 0;
    $total_fail        = 0;
    $consecutive_fails = 0;
    $iteration         = 0;
    // BUG-H4 FIX (v5.0.5): Initialize $result before the loop.
    // If the loop body never executes (0 pending, or time-guard fires first),
    // the post-loop code would reference an undefined variable.
    // QC-R2: Full key set matching process_batch() return signature.
    $result            = array( 'processed' => 0, 'succeeded' => 0, 'failed' => 0, 'errors' => array() );

    // v5.3.0 Phase 2a QC-Adj-2: try/catch/finally guarantees lock release on crash.
    // Once the transient is set (above), there are NO early returns — every exit
    // path goes through finally{} which deletes the transient.
    try {

        while ( ( time() - $start_time ) < $max_runtime ) {

            $remaining = $rewriter->get_pending_count();
            if ( $remaining < 1 ) {
                if ( function_exists( 'oo_log' ) ) {
                    oo_log( 'info', 'REWRITE', 'CRON_REWRITE_COMPLETE', 'AI Rewriter WP-Cron: All obituaries processed!' );
                } else {
                    ontario_obituaries_log( 'AI Rewriter WP-Cron: All obituaries processed!', 'info' );
                }
                break;
            }

            // Time guard: need at least 15s for one API call.
            if ( ( $max_runtime - ( time() - $start_time ) ) < 15 ) {
                break;
            }

            $iteration++;
            $result = $rewriter->process_batch(); // Processes 1 obituary.
            $total_ok   += $result['succeeded'];
            $total_fail += $result['failed'];

            // Recommended fix #1: Use actual remaining count after processing.
            $remaining_after = $rewriter->get_pending_count();

            ontario_obituaries_log(
                sprintf(
                    'AI Rewriter WP-Cron #%d: %s (total: %d ok, %d fail, %d remaining) [%ds]',
                    $iteration,
                    $result['succeeded'] > 0 ? 'PUBLISHED' : 'FAILED',
                    $total_ok, $total_fail, $remaining_after,
                    time() - $start_time
                ),
                'info'
            );

            // Auth error — stop immediately.
            if ( ! empty( $result['auth_error'] ) ) {
                if ( function_exists( 'oo_log' ) ) {
                    oo_log( 'error', 'REWRITE', 'CRON_REWRITE_AUTH_ERROR', 'AI Rewriter WP-Cron: Auth error, stopping.' );
                } else {
                    ontario_obituaries_log( 'AI Rewriter WP-Cron: Auth error, stopping.', 'error' );
                }
                break;
            }

            // Track consecutive failures.
            if ( $result['succeeded'] === 0 ) {
                $consecutive_fails++;
                if ( $consecutive_fails >= 3 ) {
                    if ( function_exists( 'oo_log' ) ) {
                        oo_log( 'warning', 'REWRITE', 'CRON_REWRITE_CONSECUTIVE_FAIL', 'AI Rewriter WP-Cron: 3 consecutive failures, stopping.', array(
                            'total_ok'  => $total_ok,
                            'total_fail' => $total_fail,
                        ) );
                    } else {
                        ontario_obituaries_log( 'AI Rewriter WP-Cron: 3 consecutive failures, stopping.', 'warning' );
                    }
                    break;
                }
                sleep( 10 ); // Brief backoff after failure.
            } else {
                $consecutive_fails = 0;
                sleep( 12 ); // 12s pause before next — ~5 req/min, within 6,000 TPM.
            }
        }

        // ── Post-loop logging and post-processing (inside try) ────────

        ontario_obituaries_log(
            sprintf(
                'AI Rewriter WP-Cron: FINISHED — %d published, %d failed in %ds (%d iterations). %d remaining.',
                $total_ok, $total_fail, time() - $start_time, $iteration, $rewriter->get_pending_count()
            ),
            'info'
        );

        // v5.1.5: Repeating schedule — no self-reschedule needed.
        // Safety net: if the repeating event was somehow lost (DB corruption,
        // manual wp_clear_scheduled_hook, etc.), re-register it once.
        // Required fix #3: Check wp_schedule_event() return and log failure.
        if ( ! wp_next_scheduled( 'ontario_obituaries_ai_rewrite_batch' ) ) {
            $scheduled = wp_schedule_event( time() + 300, 'ontario_five_minutes', 'ontario_obituaries_ai_rewrite_batch' );
            if ( false === $scheduled ) {
                if ( function_exists( 'oo_log' ) ) {
                    oo_log( 'warning', 'REWRITE', 'CRON_REWRITE_RESCHEDULE_FAIL', 'Failed to schedule repeating rewrite cron.', array(
                        'hook'       => 'ontario_obituaries_ai_rewrite_batch',
                        'recurrence' => 'ontario_five_minutes',
                    ) );
                } else {
                    ontario_obituaries_log( 'AI Rewriter: Failed to re-register repeating schedule.', 'warning' );
                }
            } else {
                ontario_obituaries_log( 'AI Rewriter: Repeating schedule was missing — re-registered.', 'warning' );
            }
        }

        // Purge cache and submit newly published records to IndexNow.
        // BUG-H4 FIX (v5.0.5): Use $total_ok (accumulator) instead of $result['succeeded']
        // (last iteration only). Also avoids undefined $result if loop never ran.
        if ( $total_ok > 0 ) {
            ontario_obituaries_purge_litespeed( 'ontario_obits' );

            // v4.6.0: Submit newly published obituaries to IndexNow.
            if ( class_exists( 'Ontario_Obituaries_IndexNow' ) ) {
                global $wpdb;
                $table = $wpdb->prefix . 'ontario_obituaries';
                // Get IDs of obituaries published in the last 10 minutes (this batch).
                $recent_ids = $wpdb->get_col( $wpdb->prepare(
                    "SELECT id FROM `{$table}` WHERE status = 'published' AND ai_description IS NOT NULL AND ai_description != '' AND created_at >= %s AND suppressed_at IS NULL ORDER BY id DESC LIMIT 100",
                    gmdate( 'Y-m-d H:i:s', strtotime( '-10 minutes' ) )
                ) );
                if ( ! empty( $recent_ids ) ) {
                    $indexnow = new Ontario_Obituaries_IndexNow();
                    $indexnow->submit_urls( $recent_ids );
                    ontario_obituaries_log( sprintf( 'v4.6.0: Submitted %d newly published obituaries to IndexNow.', count( $recent_ids ) ), 'info' );
                }
            }
        }

        // v5.3.0 Phase 2a QC-Adj-3: Record "ran" unconditionally (clean completion).
        // Record "progress" only when actual work was done.
        if ( function_exists( 'oo_health_record_ran' ) ) {
            oo_health_record_ran( 'REWRITE' );
        }
        if ( $total_ok > 0 && function_exists( 'oo_health_record_success' ) ) {
            oo_health_record_success( 'REWRITE' );
        }

    } catch ( \Throwable $e ) {
        // v5.3.0 Phase 2a: Structured error logging for cron crash.
        if ( function_exists( 'oo_log' ) ) {
            oo_log( 'error', 'REWRITE', 'CRON_REWRITE_CRASH', $e->getMessage(), array(
                'exception' => $e,
                'hook'      => 'ontario_obituaries_ai_rewrite_batch',
                'iteration' => $iteration,
                'total_ok'  => $total_ok,
            ) );
        } else {
            ontario_obituaries_log( 'AI Rewriter cron crash: ' . $e->getMessage(), 'error' );
        }
    } finally {
        // v5.3.0 Phase 2a QC-Adj-2: GUARANTEE lock release on every exit path.
        delete_transient( 'ontario_obituaries_rewriter_running' );
    }
}
add_action( 'ontario_obituaries_ai_rewrite_batch', 'ontario_obituaries_ai_rewrite_batch' );

/**
 * v5.2.0: Image Localizer migration cron handler.
 *
 * Processes a batch of external images, downloading them to the local server.
 * Self-removes the cron hook when all images have been localized.
 * Runs every 5 minutes via the 'ontario_five_minutes' schedule.
 */
function ontario_obituaries_image_migration_handler() {
    if ( ! class_exists( 'Ontario_Obituaries_Image_Localizer' ) ) {
        require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-image-localizer.php';
    }
    // v6.0.0: Replaced manual try/catch with oo_safe_call() for structured logging.
    // On crash: logs subsystem=IMAGE, code=CRON_IMAGE_MIGRATION_CRASH, with run_id.
    // The recurring schedule stays intact and the next tick retries.
    if ( function_exists( 'oo_safe_call' ) ) {
        oo_safe_call( 'IMAGE', 'CRON_IMAGE_MIGRATION_CRASH', function() {
            Ontario_Obituaries_Image_Localizer::handle_migration_cron();
        }, null, array( 'hook' => 'ontario_obituaries_image_migration' ) );
    } else {
        try {
            Ontario_Obituaries_Image_Localizer::handle_migration_cron();
        } catch ( \Throwable $e ) {
            ontario_obituaries_log(
                sprintf( 'Image migration cron error: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine() ),
                'error'
            );
        }
    }
}
add_action( 'ontario_obituaries_image_migration', 'ontario_obituaries_image_migration_handler' );

/**
 * v4.6.3: Piggyback AI rewriter on admin page loads using 'shutdown' hook.
 *
 * WP cron is unreliable on LiteSpeed hosting (cron events don't fire).
 * The 'shutdown' hook fires AFTER the response has been sent to the browser,
 * so the admin page loads instantly and the rewriter works in the background.
 *
 * Architecture:
 *   - admin_init: lightweight check — only sets a flag if work is needed.
 *   - shutdown: does the actual API calls, only if the flag was set.
 *   - Throttled to once per 5 minutes via a transient.
 *   - Processes 1 record per admin page load (~8s background work).
 *   - Only runs on admin pages (not frontend — no visitor impact).
 *
 * v4.6.3 FIX: No longer gated behind 'ai_rewrite_enabled' checkbox.
 * Having a configured Groq API key IS the intent signal.
 */
function ontario_obituaries_check_rewriter_needed() {
    // Only on admin pages, not AJAX requests (those have their own handler).
    if ( wp_doing_ajax() || wp_doing_cron() ) {
        return;
    }

    // Throttle: only run once per 5 minutes.
    if ( get_transient( 'ontario_obituaries_rewriter_throttle' ) ) {
        return;
    }

    // Only run on authorized domain.
    if ( function_exists( 'ontario_obituaries_is_authorized_domain' ) && ! ontario_obituaries_is_authorized_domain() ) {
        return;
    }

    // Quick check: is the Groq API key set? (Cheap DB lookup.)
    $groq_key = get_option( 'ontario_obituaries_groq_api_key', '' );
    if ( empty( $groq_key ) ) {
        return;
    }

    // v4.6.4 FIX: Do NOT set the throttle here. Set it inside the shutdown hook
    // AFTER confirming work actually started. This prevents a failed shutdown
    // (PHP fatal, memory limit) from blocking all future runs for 5 minutes.
    // Instead, set a short-lived "intent" transient to prevent duplicate scheduling
    // within the same request cycle.
    if ( get_transient( 'ontario_obituaries_rewriter_scheduling' ) ) {
        return;
    }
    set_transient( 'ontario_obituaries_rewriter_scheduling', 1, 30 );

    // Schedule the actual work for after the response is sent.
    add_action( 'shutdown', 'ontario_obituaries_shutdown_rewriter' );
}
add_action( 'admin_init', 'ontario_obituaries_check_rewriter_needed' );

/**
 * Runs AFTER the response is sent to the browser (shutdown hook).
 * Does the actual Groq API calls without blocking the admin UI.
 *
 * v5.0.1: Processes ONE obituary per admin page load. Respects the
 * mutual exclusion transient so it won’t overlap with WP-Cron or AJAX.
 */
function ontario_obituaries_shutdown_rewriter() {
    // BUG-H5 FIX (v5.0.5): Throttle transient moved AFTER successful processing.
    // Previously set here (before any work), so failures would block all future
    // runs for 5 minutes.  Now set only after process_batch() succeeds.
    // Original line: set_transient( 'ontario_obituaries_rewriter_throttle', 1, 300 );

    // ── MUTUAL EXCLUSION: Don’t run if WP-Cron or AJAX is already processing ──
    if ( get_transient( 'ontario_obituaries_rewriter_running' ) ) {
        return; // Another handler is active — skip silently.
    }
    set_transient( 'ontario_obituaries_rewriter_running', 'shutdown', 60 ); // 1-min TTL.

    // Flush output buffers and close the connection first.
    if ( function_exists( 'litespeed_finish_request' ) ) {
        litespeed_finish_request();
    } elseif ( function_exists( 'fastcgi_finish_request' ) ) {
        fastcgi_finish_request();
    } else {
        if ( ! headers_sent() ) {
            header( 'Connection: close' );
            header( 'Content-Length: 0' );
        }
        while ( ob_get_level() > 0 ) {
            ob_end_flush();
        }
        flush();
    }

    ignore_user_abort( true );
    @set_time_limit( 30 ); // Only need ~10s for 1 obituary.

    if ( ! class_exists( 'Ontario_Obituaries_AI_Rewriter' ) ) {
        if ( defined( 'ONTARIO_OBITUARIES_PLUGIN_DIR' ) ) {
            require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ai-rewriter.php';
        } else {
            delete_transient( 'ontario_obituaries_rewriter_running' );
            return;
        }
    }

    // Process 1 record only. Quick and safe.
    $rewriter = new Ontario_Obituaries_AI_Rewriter( 1 );
    if ( ! $rewriter->is_configured() ) {
        delete_transient( 'ontario_obituaries_rewriter_running' );
        return;
    }

    $pending = $rewriter->get_pending_count();
    if ( $pending < 1 ) {
        delete_transient( 'ontario_obituaries_rewriter_running' );
        return;
    }

    // QC-R2: Wrap in try/finally to guarantee lock cleanup on \Error/\Throwable.
    // PHP 7+ \TypeError, OOM, etc. bypass \Exception catch blocks; without
    // finally{} the running lock persists until TTL (60s).
    $result = array( 'processed' => 0, 'succeeded' => 0, 'failed' => 0, 'errors' => array() );
    try {
        $result = $rewriter->process_batch();

        // BUG-H5 FIX (v5.0.5): Set throttle AFTER processing, not before.
        // On success: 5-minute cooldown prevents wasting quota on rapid admin page loads.
        // On failure: short 60s cooldown to bound retry rate (QC-R2: prevents burst
        // loops on repeated all-fail batches — caps at 1 retry/min vs previous 1/30s).
        if ( $result['succeeded'] > 0 ) {
            set_transient( 'ontario_obituaries_rewriter_throttle', 1, 300 );
        } else {
            set_transient( 'ontario_obituaries_rewriter_throttle', 1, 60 );
        }
    } catch ( \Throwable $e ) {
        // v5.3.0 Phase 2a: Structured error logging (minimal — shutdown context).
        if ( function_exists( 'oo_log' ) ) {
            oo_log( 'error', 'REWRITE', 'SHUTDOWN_REWRITER_CRASH', $e->getMessage(), array(
                'exception' => $e,
                'hook'      => 'shutdown_rewriter',
            ) );
        } elseif ( function_exists( 'ontario_obituaries_log' ) ) {
            ontario_obituaries_log( 'Shutdown rewriter fatal: ' . $e->getMessage(), 'error' );
        }
    } finally {
        delete_transient( 'ontario_obituaries_rewriter_running' );
    }

    if ( function_exists( 'ontario_obituaries_log' ) ) {
        ontario_obituaries_log(
            sprintf(
                'Shutdown rewriter: %s. %d still pending.',
                $result['succeeded'] > 0 ? 'PUBLISHED 1' : 'FAILED',
                $rewriter->get_pending_count()
            ),
            'info'
        );
    }

    if ( $result['succeeded'] > 0 && function_exists( 'ontario_obituaries_purge_litespeed' ) ) {
        ontario_obituaries_purge_litespeed( 'ontario_obits' );
    }
}

/**
 * v4.6.3: AJAX endpoint to manually trigger AI rewriter from admin UI.
 *
 * Processes 1 obituary per call and returns progress.
 * The front-end auto-repeats to chew through the backlog.
 * No dependency on WP-cron, no dependency on ai_rewrite_enabled checkbox.
 */
function ontario_obituaries_ajax_run_rewriter() {
    // v4.6.3 FIX: Nonce action MUST match the one created in wp_localize_script().
    // The Reset & Rescan page creates the nonce with action 'ontario_obituaries_reset_rescan'.
    check_ajax_referer( 'ontario_obituaries_reset_rescan', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Permission denied.' ) );
    }

    @set_time_limit( 300 );

    if ( ! class_exists( 'Ontario_Obituaries_AI_Rewriter' ) ) {
        require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ai-rewriter.php';
    }

    // v5.0.1: Process 1 record per AJAX call — prevents browser timeout.
    // The JS auto-repeats, so all records get processed sequentially.
    // MUTUAL EXCLUSION: Claim the lock so WP-Cron / shutdown don’t overlap.
    set_transient( 'ontario_obituaries_rewriter_running', 'ajax', 120 ); // 2-min TTL.

    $rewriter = new Ontario_Obituaries_AI_Rewriter( 1 );

    if ( ! $rewriter->is_configured() ) {
        delete_transient( 'ontario_obituaries_rewriter_running' );
        wp_send_json_error( array(
            'message' => 'Groq API key not configured. Go to Settings → AI Rewrite Engine and enter your key.',
        ) );
    }

    $pending_before = $rewriter->get_pending_count();

    if ( $pending_before < 1 ) {
        delete_transient( 'ontario_obituaries_rewriter_running' );
        wp_send_json_success( array(
            'message'   => 'No pending obituaries to process.',
            'processed' => 0,
            'succeeded' => 0,
            'failed'    => 0,
            'pending'   => 0,
            'done'      => true,
        ) );
    }

    // Clear the piggyback throttle so it doesn't conflict.
    delete_transient( 'ontario_obituaries_rewriter_throttle' );

    $result = $rewriter->process_batch();

    $pending_after = $rewriter->get_pending_count();

    // Purge cache if any records were published.
    if ( $result['succeeded'] > 0 && function_exists( 'ontario_obituaries_purge_litespeed' ) ) {
        ontario_obituaries_purge_litespeed( 'ontario_obits' );
    }

    if ( function_exists( 'ontario_obituaries_log' ) ) {
        ontario_obituaries_log(
            sprintf(
                'v4.6.4 Manual rewriter: %d processed, %d succeeded, %d failed. %d → %d pending.',
                $result['processed'],
                $result['succeeded'],
                $result['failed'],
                $pending_before,
                $pending_after
            ),
            'info'
        );
    }

    // v4.6.4: Sanitize error messages — strip Groq API key fragments and internal URLs.
    $safe_errors = array();
    if ( ! empty( $result['errors'] ) ) {
        foreach ( $result['errors'] as $err ) {
            // Strip anything that looks like a Bearer token or API key.
            $err = preg_replace( '/Bearer\s+[a-zA-Z0-9\-_.]+/', 'Bearer ***', $err );
            // Strip full API URLs, keep just the domain.
            $err = preg_replace( '#https?://[^\s]+#', '[URL redacted]', $err );
            $safe_errors[] = sanitize_text_field( $err );
        }
    }

    // Release the lock after processing.
    delete_transient( 'ontario_obituaries_rewriter_running' );

    wp_send_json_success( array(
        'message'   => sprintf(
            'Processed %d: %d published, %d failed. %d still pending.',
            $result['processed'],
            $result['succeeded'],
            $result['failed'],
            $pending_after
        ),
        'processed'    => $result['processed'],
        'succeeded'    => $result['succeeded'],
        'failed'       => $result['failed'],
        'errors'       => $safe_errors,
        'pending'      => $pending_after,
        'done'         => ( $pending_after < 1 ),
        'rate_limited' => ! empty( $result['rate_limited'] ), // v4.6.5: Tell JS to slow down.
        'auth_error'   => ! empty( $result['auth_error'] ),   // v4.6.7: Tell JS the key/model is broken.
    ) );
}
add_action( 'wp_ajax_ontario_obituaries_run_rewriter', 'ontario_obituaries_ajax_run_rewriter' );

/**
 * v4.6.7: AJAX endpoint to validate the Groq API key.
 *
 * Makes a minimal test request to Groq to verify the key is valid,
 * the primary model is accessible, and falls back to alternative models
 * if the primary is permission-blocked. Returns clear, actionable
 * error messages the user can act on.
 */
function ontario_obituaries_ajax_validate_api_key() {
    check_ajax_referer( 'ontario_obituaries_reset_rescan', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Permission denied.' ) );
    }

    if ( ! class_exists( 'Ontario_Obituaries_AI_Rewriter' ) ) {
        require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ai-rewriter.php';
    }

    $rewriter = new Ontario_Obituaries_AI_Rewriter();

    if ( ! $rewriter->is_configured() ) {
        wp_send_json_error( array(
            'message' => 'No Groq API key is set. Go to Settings → Ontario Obituaries and enter your key.',
            'status'  => 'not_configured',
        ) );
    }

    $result = $rewriter->validate_api_key();

    if ( true === $result ) {
        wp_send_json_success( array(
            'message' => 'API key is valid and the primary model is accessible. The AI Rewriter is ready to go.',
            'status'  => 'ok',
        ) );
    }

    // Special case: primary model blocked but fallback works.
    if ( is_wp_error( $result ) && 'model_blocked_but_fallback_ok' === $result->get_error_code() ) {
        wp_send_json_success( array(
            'message' => $result->get_error_message(),
            'status'  => 'fallback_ok',
        ) );
    }

    wp_send_json_error( array(
        'message' => $result->get_error_message(),
        'status'  => $result->get_error_code(),
    ) );
}
add_action( 'wp_ajax_ontario_obituaries_validate_api_key', 'ontario_obituaries_ajax_validate_api_key' );

/**
 * v5.1.3: AJAX endpoint for live AI Rewrite Status refresh.
 *
 * Called every 30 seconds by the settings page JavaScript to update the
 * rewrite counters without a full page reload. This bypasses LiteSpeed cache
 * because AJAX POST requests are never cached.
 */
function ontario_obituaries_ajax_rewrite_status() {
    check_ajax_referer( 'oo_rewrite_status' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }

    if ( ! class_exists( 'Ontario_Obituaries_AI_Rewriter' ) ) {
        wp_send_json_error( 'Rewriter class not loaded.' );
    }

    $rewriter = new Ontario_Obituaries_AI_Rewriter();
    $stats    = $rewriter->get_stats();

    $html = sprintf(
        '<strong>%s</strong> scraped &nbsp;|&nbsp; <strong style="color:blue;">%s</strong> rewritten &nbsp;|&nbsp; <strong style="color:green;">%s</strong> published (live) &nbsp;|&nbsp; <strong style="color:orange;">%s</strong> pending &nbsp;|&nbsp; <strong>%s%%</strong> complete',
        esc_html( number_format_i18n( $stats['total'] ) ),
        esc_html( number_format_i18n( $stats['rewritten'] ) ),
        esc_html( number_format_i18n( isset( $stats['published'] ) ? $stats['published'] : 0 ) ),
        esc_html( number_format_i18n( $stats['pending'] ) ),
        esc_html( number_format_i18n( $stats['percent_complete'], 1 ) )
    );

    wp_send_json_success( array( 'html' => $html, 'stats' => $stats ) );
}
add_action( 'wp_ajax_oo_rewrite_status', 'ontario_obituaries_ajax_rewrite_status' );

/**
 * v4.3.0: GoFundMe Auto-Linker batch.
 *
 * Runs after collection and on its own schedule. Searches GoFundMe for
 * memorial campaigns matching obituary records using 3-point verification.
 */
function ontario_obituaries_gofundme_batch() {
    $settings = get_option( 'ontario_obituaries_settings', array() );

    if ( empty( $settings['gofundme_enabled'] ) ) {
        return;
    }

    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-gofundme-linker.php';
    $linker = new Ontario_Obituaries_GoFundMe_Linker();

    // v5.3.0 Phase 2a: Wrap in oo_safe_call for crash protection.
    // QC-Adj-4: null === crash; WP_Error preserved for existing is_wp_error() logic.
    $result = function_exists( 'oo_safe_call' )
        ? oo_safe_call( 'GOFUNDME', 'CRON_GOFUNDME_CRASH', function() use ( $linker ) {
            return $linker->process_batch();
        }, null, array( 'hook' => 'ontario_obituaries_gofundme_batch' ) )
        : $linker->process_batch();

    // oo_safe_call caught a Throwable → bail (already logged).
    if ( null === $result ) {
        return;
    }

    ontario_obituaries_log(
        sprintf(
            'GoFundMe Linker batch: %d processed, %d matched, %d no match.',
            $result['processed'], $result['matched'], $result['no_match']
        ),
        'info'
    );

    // v5.3.0 Phase 2a QC-Adj-3: Record "ran" unconditionally, "progress" on match.
    if ( function_exists( 'oo_health_record_ran' ) ) {
        oo_health_record_ran( 'GOFUNDME' );
    }
    if ( $result['matched'] > 0 && function_exists( 'oo_health_record_success' ) ) {
        oo_health_record_success( 'GOFUNDME' );
    }

    // Schedule next batch if there are pending obituaries.
    // QC-R10 FIX (v5.0.10): Jitter with floor to prevent negative/zero intervals.
    $stats = $linker->get_stats();
    if ( $stats['pending'] > 0 && ! wp_next_scheduled( 'ontario_obituaries_gofundme_batch' ) ) {
        $interval = 300 + wp_rand( 0, 60 ); // 300-360s (5-6 min).
        wp_schedule_single_event( time() + $interval, 'ontario_obituaries_gofundme_batch' );
    }

    if ( $result['matched'] > 0 ) {
        ontario_obituaries_purge_litespeed( 'ontario_obits' );
    }
}
add_action( 'ontario_obituaries_gofundme_batch', 'ontario_obituaries_gofundme_batch' );

/**
 * v4.3.0: AI Authenticity Checker batch.
 *
 * Runs every 4 hours via WP-Cron. Picks random obituaries and audits them
 * for data consistency using the Groq LLM.
 */
function ontario_obituaries_authenticity_audit() {
    $settings = get_option( 'ontario_obituaries_settings', array() );

    if ( empty( $settings['authenticity_enabled'] ) ) {
        return;
    }

    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ai-authenticity-checker.php';
    $checker = new Ontario_Obituaries_Authenticity_Checker();

    if ( ! $checker->is_configured() ) {
        ontario_obituaries_log( 'Authenticity Checker: Skipped — no Groq API key.', 'info' );
        return;
    }

    // v5.3.0 Phase 2a: Wrap in oo_safe_call for crash protection.
    // QC-Adj-4: null === crash; non-null result (including WP_Error) passed through.
    $result = function_exists( 'oo_safe_call' )
        ? oo_safe_call( 'AUDIT', 'CRON_AUTHENTICITY_CRASH', function() use ( $checker ) {
            return $checker->run_audit_batch();
        }, null, array( 'hook' => 'ontario_obituaries_authenticity_audit' ) )
        : $checker->run_audit_batch();

    // oo_safe_call caught a Throwable → bail (already logged).
    if ( null === $result ) {
        return;
    }

    ontario_obituaries_log(
        sprintf(
            'Authenticity audit: %d audited, %d passed, %d flagged.',
            $result['audited'], $result['passed'], $result['flagged']
        ),
        'info'
    );

    // v5.3.0 Phase 2a QC-Adj-3: Record "ran" unconditionally, "progress" on audit > 0.
    if ( function_exists( 'oo_health_record_ran' ) ) {
        oo_health_record_ran( 'AUDIT' );
    }
    if ( $result['audited'] > 0 && function_exists( 'oo_health_record_success' ) ) {
        oo_health_record_success( 'AUDIT' );
    }

    if ( $result['flagged'] > 0 ) {
        ontario_obituaries_purge_litespeed( 'ontario_obits' );
    }
}
add_action( 'ontario_obituaries_authenticity_audit', 'ontario_obituaries_authenticity_audit' );

/**
 * v4.3.0: Schedule recurring authenticity audit every 4 hours.
 * Hooked to init so it runs on every page load check.
 */
function ontario_obituaries_schedule_authenticity_audit() {
    $settings = get_option( 'ontario_obituaries_settings', array() );

    // QC-R10 FIX (v5.0.10): Jitter (+0-120s) to prevent exact alignment with
    // other 4-hour recurring events. wp_rand() is WP's crypto-safe random.
    // Interval: 300-420s (5-7 min) from now.
    if ( ! empty( $settings['authenticity_enabled'] ) && ! wp_next_scheduled( 'ontario_obituaries_authenticity_audit' ) ) {
        $offset = 300 + wp_rand( 0, 120 );
        wp_schedule_event( time() + $offset, 'four_hours', 'ontario_obituaries_authenticity_audit' );
    }
}
add_action( 'init', 'ontario_obituaries_schedule_authenticity_audit', 20 );

/**
 * v4.4.0: Google Ads daily optimization analysis.
 *
 * Runs once daily via WP-Cron. Fetches campaign performance data,
 * sends to AI for analysis, and stores optimization recommendations.
 */
function ontario_obituaries_google_ads_daily_analysis() {
    $settings = get_option( 'ontario_obituaries_settings', array() );

    if ( empty( $settings['google_ads_enabled'] ) ) {
        return;
    }

    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-google-ads-optimizer.php';
    $optimizer = new Ontario_Obituaries_Google_Ads_Optimizer();

    if ( ! $optimizer->is_configured() ) {
        ontario_obituaries_log( 'Google Ads Optimizer: Skipped — not configured.', 'info' );
        return;
    }

    // v5.3.0 Phase 2a: Wrap in oo_safe_call for crash protection.
    // QC-Adj-4: null === crash; WP_Error preserved for existing is_wp_error() check below.
    $result = function_exists( 'oo_safe_call' )
        ? oo_safe_call( 'ADS', 'CRON_ADS_ANALYSIS_CRASH', function() use ( $optimizer ) {
            return $optimizer->run_optimization_analysis();
        }, null, array( 'hook' => 'ontario_obituaries_google_ads_analysis' ) )
        : $optimizer->run_optimization_analysis();

    // oo_safe_call caught a Throwable → bail (already logged).
    if ( null === $result ) {
        return;
    }

    // EXISTING is_wp_error() handling — UNCHANGED (QC-Adj-4).
    if ( is_wp_error( $result ) ) {
        ontario_obituaries_log( 'Google Ads Optimizer: Analysis failed — ' . $result->get_error_message(), 'error' );
    } else {
        $score = isset( $result['analysis']['overall_score'] ) ? $result['analysis']['overall_score'] : 'N/A';
        ontario_obituaries_log( sprintf( 'Google Ads Optimizer: Daily analysis complete. Score: %s.', $score ), 'info' );

        // v5.3.0 Phase 2a QC-Adj-3: Record "progress" only on non-error completion.
        if ( function_exists( 'oo_health_record_success' ) ) {
            oo_health_record_success( 'ADS' );
        }
    }

    // v5.3.0 Phase 2a QC-Adj-3: Record "ran" unconditionally (handler completed).
    if ( function_exists( 'oo_health_record_ran' ) ) {
        oo_health_record_ran( 'ADS' );
    }
}
add_action( 'ontario_obituaries_google_ads_analysis', 'ontario_obituaries_google_ads_daily_analysis' );

/**
 * v4.4.0: Schedule daily Google Ads analysis.
 */
function ontario_obituaries_schedule_google_ads_analysis() {
    $settings = get_option( 'ontario_obituaries_settings', array() );

    if ( ! empty( $settings['google_ads_enabled'] ) && ! wp_next_scheduled( 'ontario_obituaries_google_ads_analysis' ) ) {
        wp_schedule_event( time() + 600, 'daily', 'ontario_obituaries_google_ads_analysis' );
    }
}
add_action( 'init', 'ontario_obituaries_schedule_google_ads_analysis', 25 );

/**
 * v4.3.0: Register custom cron interval for authenticity checker.
 */
function ontario_obituaries_cron_intervals( $schedules ) {
    if ( ! isset( $schedules['four_hours'] ) ) {
        $schedules['four_hours'] = array(
            'interval' => 14400,
            'display'  => __( 'Every 4 Hours', 'ontario-obituaries' ),
        );
    }
    // v5.1.4: 5-minute interval for the AI rewrite batch.
    // Matches the cPanel */5 server cron cadence for reliable autonomous operation.
    if ( ! isset( $schedules['ontario_five_minutes'] ) ) {
        $schedules['ontario_five_minutes'] = array(
            'interval' => 300,
            'display'  => __( 'Every 5 Minutes (Ontario Obituaries)', 'ontario-obituaries' ),
        );
    }
    return $schedules;
}
add_filter( 'cron_schedules', 'ontario_obituaries_cron_intervals' );

/**
 * P0-1 FIX: One-time initial collection (runs via WP-Cron after activation).
 */
function ontario_obituaries_initial_collection() {
    ontario_obituaries_scheduled_collection();
}
add_action( 'ontario_obituaries_initial_collection', 'ontario_obituaries_initial_collection' );

/**
 * Admin notices hook
 * P1-7 FIX: Guard get_current_screen()
 */
function ontario_obituaries_admin_notices() {
    if ( get_transient( 'ontario_obituaries_activation_notice' ) ) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'Ontario Obituaries plugin has been activated successfully!', 'ontario-obituaries' ); ?></p>
            <p><?php esc_html_e( 'An initial collection has been triggered to populate obituaries data.', 'ontario-obituaries' ); ?></p>
        </div>
        <?php
        delete_transient( 'ontario_obituaries_activation_notice' );
    }

    // P1-7 FIX: guard get_current_screen
    if ( ! function_exists( 'get_current_screen' ) ) {
        return;
    }
    $screen = get_current_screen();
    if ( $screen && false !== strpos( $screen->id, 'ontario-obituaries' ) && ! ontario_obituaries_check_dependency() ) {
        ?>
        <div class="notice notice-info is-dismissible">
            <p><?php esc_html_e( 'Ontario Obituaries plugin works best with Obituary Assistant plugin. Please install and activate it for full functionality.', 'ontario-obituaries' ); ?></p>
        </div>
        <?php
    }
}
add_action( 'admin_notices', 'ontario_obituaries_admin_notices' );

/**
 * Initialize the plugin
 */
function ontario_obituaries_init() {
    ontario_obituaries_includes();
    new Ontario_Obituaries();

    // v4.2.0: Serve IndexNow verification key file.
    if ( class_exists( 'Ontario_Obituaries_IndexNow' ) ) {
        $indexnow = new Ontario_Obituaries_IndexNow();
        add_action( 'template_redirect', array( $indexnow, 'serve_verification_file' ), 0 );
    }
}
add_action( 'plugins_loaded', 'ontario_obituaries_init' );

/**
 * v3.5.0: Centralized LiteSpeed Cache purge.
 *
 * LiteSpeed is the ONLY cache layer on monacomonuments.ca.
 * W3 Total Cache must not be active — see ontario_obituaries_cache_conflict_notice().
 *
 * Supports two modes:
 *   1) Full purge (default)  — clears everything; used on deploy/version update.
 *   2) Tag-based purge       — clears only pages tagged with 'ontario_obits*';
 *      used after scrape/dedup so the rest of the site cache is preserved.
 *
 * @param string $tag  Optional. A LiteSpeed tag to purge (e.g. 'ontario_obits').
 *                     When empty, does a full purge_all.
 * @return bool Whether a purge was executed.
 */
function ontario_obituaries_purge_litespeed( $tag = '' ) {
    // LiteSpeed Cache plugin API (v3.x+)
    if ( class_exists( 'LiteSpeed_Cache_API' ) ) {
        if ( ! empty( $tag ) && method_exists( 'LiteSpeed_Cache_API', 'purge' ) ) {
            // Tag-based purge: only the pages we tagged with X-LiteSpeed-Tag
            LiteSpeed_Cache_API::purge( $tag );
            ontario_obituaries_log( 'LiteSpeed Cache purged tag: ' . $tag, 'info' );
        } else {
            LiteSpeed_Cache_API::purge_all();
            ontario_obituaries_log( 'LiteSpeed Cache purged (full).', 'info' );
        }
        return true;
    }

    // Legacy LiteSpeed function (no tag support)
    if ( function_exists( 'litespeed_purge_all' ) ) {
        litespeed_purge_all();
        ontario_obituaries_log( 'LiteSpeed Cache purged via legacy function.', 'info' );
        return true;
    }

    // LiteSpeed server is running but the WP plugin might not be active —
    // send a purge header so the server-level cache is cleared too.
    if ( ! headers_sent() ) {
        if ( ! empty( $tag ) ) {
            header( 'X-LiteSpeed-Purge: tag=' . sanitize_text_field( $tag ) );
        } else {
            header( 'X-LiteSpeed-Purge: *' );
        }
        ontario_obituaries_log( 'LiteSpeed Cache: sent X-LiteSpeed-Purge header (no WP plugin detected).', 'info' );
        return true;
    }

    return false;
}

/**
 * v3.5.0: Warn if W3 Total Cache is active alongside LiteSpeed.
 *
 * Decision: LiteSpeed Cache is the sole caching layer (server-native on
 * monacomonuments.ca). W3 Total Cache MUST be deactivated because its page
 * cache serves stale HTML that LiteSpeed has already purged, causing version
 * mismatches, persistent "View Original" buttons, and duplicate obituaries.
 */
function ontario_obituaries_cache_conflict_notice() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Check if W3 Total Cache is active
    if ( ! function_exists( 'is_plugin_active' ) ) {
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $w3tc_active = is_plugin_active( 'w3-total-cache/w3-total-cache.php' );
    if ( ! $w3tc_active ) {
        return;
    }

    // Check if LiteSpeed Cache is also active
    $litespeed_active = is_plugin_active( 'litespeed-cache/litespeed-cache.php' );
    $is_litespeed_server = ( isset( $_SERVER['SERVER_SOFTWARE'] ) && stripos( $_SERVER['SERVER_SOFTWARE'], 'litespeed' ) !== false );

    if ( $litespeed_active || $is_litespeed_server ) {
        ?>
        <div class="notice notice-error">
            <p><strong><?php esc_html_e( 'Ontario Obituaries — Cache Conflict Detected', 'ontario-obituaries' ); ?></strong></p>
            <p><?php esc_html_e( 'W3 Total Cache and LiteSpeed Cache are both active. This causes stale HTML, version mismatches, and persistent display bugs (e.g., old "View Original" buttons, duplicated cards).', 'ontario-obituaries' ); ?></p>
            <p><?php printf(
                /* translators: %s: link to plugins page */
                esc_html__( 'Please %s W3 Total Cache and use LiteSpeed Cache exclusively — it is the native cache layer for this server.', 'ontario-obituaries' ),
                '<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '"><strong>' . esc_html__( 'deactivate', 'ontario-obituaries' ) . '</strong></a>'
            ); ?></p>
        </div>
        <?php
    }
}
add_action( 'admin_notices', 'ontario_obituaries_cache_conflict_notice' );

/**
 * v5.0.3: Plugin version-change handler.
 *
 * Runs on 'init' (priority 1) to handle WP Pusher deploys where the
 * activation hook does not fire. Flushes rewrite rules, purges caches,
 * and ensures essential state (source registry, cron) is intact.
 *
 * HISTORY: Prior to v5.0.3 this function contained ~1,700 lines of
 * historical migration blocks (v3.9.0–v4.3.0) that ran synchronous
 * HTTP scrapes, usleep() enrichment loops, and unguarded ALTER TABLE
 * statements. These were one-time data repairs from the v3-v4 upgrade
 * cycle. All have been executed on the live site and their work is
 * preserved in the database. The schema they created is now handled
 * by ontario_obituaries_activate(). Data freshness is handled by the
 * scheduled cron (ontario_obituaries_collection_event) and the
 * frontend heartbeat (ontario_obituaries_maybe_spawn_cron).
 *
 * BUG-C1 FIX: Removes activation cascade — 5 synchronous HTTP blocks
 *   that could block for 6+ minutes on fresh install or option loss.
 * BUG-C3 FIX: Removes non-idempotent migration blocks — unguarded
 *   ALTER TABLE, duplicate cron scheduling, and unconditional data
 *   backfills that caused errors on re-execution.
 *
 * If a future version needs migrations, add them below the version
 * floor guard with these rules:
 *   - No synchronous HTTP (use wp_schedule_single_event)
 *   - Schema changes must check column/index existence first
 *   - Data changes must be idempotent (UPDATE with WHERE, not TRUNCATE)
 *   - Each block must be safe to re-run if a previous block fatals
 *
 * KNOWN LIMITATIONS (documented, not addressed in this PR):
 *   - Hook is 'init' priority 1 (frontend-reachable). Changing to
 *     admin_init would break WP Pusher deploys. The early return on
 *     version match makes this a single get_option() on normal loads.
 *   - No current_user_can() gate. Adding one would prevent cron/CLI
 *     contexts from completing upgrades after auto-updates.
 *   - wp_cache_flush() has high blast radius. Removal is a separate
 *     one-line change for a follow-up PR.
 *   - ontario_obituaries_log() renders via WP admin — callers must
 *     not interpolate unsanitized user input. All values below are
 *     integer casts or the ONTARIO_OBITUARIES_VERSION constant.
 *
 * @since 3.5.0 (original), 5.0.3 (rewritten — BUG-C1, BUG-C3)
 */
function ontario_obituaries_on_plugin_update() {
    $stored_version = get_option( 'ontario_obituaries_deployed_version', '' );
    if ( $stored_version === ONTARIO_OBITUARIES_VERSION ) {
        return; // Already at current version.
    }

    // ── Rewrite rules ────────────────────────────────────────────────
    // Re-register before flushing so new rules are included.
    // Critical for WP Pusher deploys where activation hook doesn't fire.
    if ( class_exists( 'Ontario_Obituaries_SEO' ) ) {
        Ontario_Obituaries_SEO::flush_rules();
    } else {
        flush_rewrite_rules();
    }

    // ── Cache purge ──────────────────────────────────────────────────
    if ( function_exists( 'ontario_obituaries_purge_litespeed' ) ) {
        ontario_obituaries_purge_litespeed();
    }
    wp_cache_flush();

    // ── Transient cleanup ────────────────────────────────────────────
    delete_transient( 'ontario_obituaries_locations_cache' );
    delete_transient( 'ontario_obituaries_funeral_homes_cache' );

    // ── Dedup schedule reset (BUG-C4 FIX v5.0.4) ─────────────────────
    // Clear the lock so dedup runs on next trigger after update.
    // Also clean up any legacy version-keyed options from pre-v5.0.4.
    delete_option( 'ontario_obituaries_dedup_lock' );
    delete_option( 'ontario_obituaries_dedup_throttle_log' );
    if ( '' !== $stored_version ) {
        $safe_version = preg_replace( '/[^0-9.]/', '', substr( $stored_version, 0, 20 ) );
        if ( '' !== $safe_version ) {
            delete_option( 'ontario_obituaries_dedup_' . $safe_version );
        }
    }
    // Ensure daily dedup cron exists (may have been lost if activation didn't fire).
    // wp_clear_scheduled_hook first to prevent duplicate events from concurrent
    // update requests (wp_next_scheduled is non-atomic).
    // Also clear any pending one-shot events — a fresh dedup is more appropriate
    // after plugin update than an old deferred one.
    wp_clear_scheduled_hook( 'ontario_obituaries_dedup_daily' );
    wp_clear_scheduled_hook( 'ontario_obituaries_dedup_once' );
    if ( ! wp_next_scheduled( 'ontario_obituaries_dedup_daily' ) ) {
        wp_schedule_event( time() + 3600, 'daily', 'ontario_obituaries_dedup_daily' );
    }

    // ── Source registry health check ─────────────────────────────────
    // Re-seed if empty (covers truncated table, failed activation, etc.)
    // Idempotent: upsert_source() updates existing rows by domain key.
    if ( class_exists( 'Ontario_Obituaries_Source_Registry' ) ) {
        $source_stats = Ontario_Obituaries_Source_Registry::get_stats();
        if ( isset( $source_stats['total'] ) && 0 === intval( $source_stats['total'] ) ) {
            Ontario_Obituaries_Source_Registry::seed_defaults();
            $new_stats = Ontario_Obituaries_Source_Registry::get_stats();
            if ( function_exists( 'ontario_obituaries_log' ) ) {
                ontario_obituaries_log(
                    sprintf(
                        'Source registry was empty — re-seeded %d sources.',
                        intval( $new_stats['total'] )
                    ),
                    'info'
                );
            }
        }
    }

    // ── Ensure recurring cron is scheduled ───────────────────────────
    // Covers WP Pusher deploys where activation hook (which schedules
    // the cron) doesn't fire, and cases where the event was lost.
    if ( ! wp_next_scheduled( 'ontario_obituaries_collection_event' ) ) {
        $settings  = ontario_obituaries_get_settings();
        // Whitelist valid WP cron recurrences (matches activation hook).
        $allowed_frequencies = array( 'hourly', 'twicedaily', 'daily', 'weekly' );
        $frequency = isset( $settings['frequency'] ) && in_array( $settings['frequency'], $allowed_frequencies, true )
            ? $settings['frequency']
            : 'twicedaily';
        $time = isset( $settings['time'] ) ? $settings['time'] : '03:00';
        wp_schedule_event(
            ontario_obituaries_next_cron_timestamp( $time ),
            $frequency,
            'ontario_obituaries_collection_event'
        );
        if ( function_exists( 'ontario_obituaries_log' ) ) {
            ontario_obituaries_log( 'Recurring collection cron was missing — re-scheduled.', 'info' );
        }
    }

    // v5.2.0: Ensure image localizer migration is scheduled on version update.
    // Covers WP Pusher deploys where activation hook doesn't fire.
    if ( class_exists( 'Ontario_Obituaries_Image_Localizer' ) ) {
        Ontario_Obituaries_Image_Localizer::maybe_schedule_migration();
    }

    // ── Future migrations go here ────────────────────────────────────
    // Version floor: all migrations below 5.0.3 have been removed.
    // They were one-time data repairs from the v3-v4 upgrade cycle,
    // already executed on the live site. Schema is now handled by
    // ontario_obituaries_activate(); data freshness by cron + heartbeat.
    //
    // Add new version_compare blocks below this line for v5.0.3+.
    // Rules: no sync HTTP, idempotent ops, check before ALTER TABLE.

    // ── Mark deployment complete ─────────────────────────────────────
    // Written LAST so a fatal/timeout in any block above causes
    // re-execution on the next request. Every operation above is
    // idempotent, so re-runs are safe.
    update_option( 'ontario_obituaries_deployed_version', ONTARIO_OBITUARIES_VERSION );

    if ( function_exists( 'ontario_obituaries_log' ) ) {
        ontario_obituaries_log(
            sprintf(
                'Plugin updated to v%s — rewrite rules flushed, caches purged.',
                ONTARIO_OBITUARIES_VERSION
            ),
            'info'
        );
    }
}
add_action( 'init', 'ontario_obituaries_on_plugin_update', 1 );

/**
 * v3.16.0: Frontend stale-cron heartbeat.
 *
 * WP-Cron relies on page visits to fire, but LiteSpeed Cache serves
 * cached HTML for most requests, bypassing PHP entirely. This means
 * wp_schedule_event() events may NEVER fire on cached sites.
 *
 * FIX: On every uncached PHP page load (admin or frontend), check if
 * the last collection is older than the configured interval. If stale,
 * spawn a lightweight async loopback request to wp-cron.php so the
 * scheduled events execute.
 *
 * This runs on 'init' (priority 99, after on_plugin_update) and is
 * intentionally lightweight: one get_option() + one time comparison.
 * The actual scraping happens asynchronously via the loopback request,
 * so it doesn't block the current page load.
 *
 * Also adds a REST API endpoint for server-side cron:
 *   curl https://monacomonuments.ca/wp-json/ontario-obituaries/v1/cron
 * This allows setting up a real crontab entry that isn't affected by caching.
 */
function ontario_obituaries_maybe_spawn_cron() {
    // Skip if this IS a cron request (avoid recursion).
    if ( wp_doing_cron() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
        return;
    }

    // Lightweight check: how long since last collection?
    $last = get_option( 'ontario_obituaries_last_collection', array() );
    $last_ts = 0;
    if ( ! empty( $last['completed'] ) ) {
        $last_ts = strtotime( $last['completed'] );
    }

    // Also check when we last attempted a spawn (throttle to once per 30 min).
    $last_spawn = (int) get_transient( 'ontario_obituaries_last_cron_spawn' );
    if ( $last_spawn && ( time() - $last_spawn ) < 1800 ) {
        return; // Spawned within last 30 min, don't spam.
    }

    // Determine the collection interval from settings.
    // Default: twicedaily = 12 hours. We trigger if stale > interval.
    $settings  = ontario_obituaries_get_settings();
    $frequency = isset( $settings['frequency'] ) ? $settings['frequency'] : 'twicedaily';
    $intervals = array(
        'hourly'     => HOUR_IN_SECONDS,
        'twicedaily' => 12 * HOUR_IN_SECONDS,
        'daily'      => DAY_IN_SECONDS,
        'weekly'     => WEEK_IN_SECONDS,
    );
    $max_age = isset( $intervals[ $frequency ] ) ? $intervals[ $frequency ] : ( 12 * HOUR_IN_SECONDS );

    // Is the data stale?
    $age = time() - $last_ts;
    if ( $age < $max_age ) {
        return; // Data is fresh enough.
    }

    // Data is stale — spawn WP-Cron to run pending events.
    // This is the same mechanism WordPress core uses in wp_cron().
    set_transient( 'ontario_obituaries_last_cron_spawn', time(), 3600 );

    // Method 1: Try spawn_cron() which does a non-blocking loopback.
    if ( function_exists( 'spawn_cron' ) ) {
        spawn_cron();
    }

    // Method 2: If no collection event is even scheduled, schedule one now.
    // This catches the case where cron events were lost (DB migration, etc.).
    if ( ! wp_next_scheduled( 'ontario_obituaries_collection_event' ) ) {
        $time = isset( $settings['time'] ) ? $settings['time'] : '03:00';
        wp_schedule_event(
            ontario_obituaries_next_cron_timestamp( $time ),
            $frequency,
            'ontario_obituaries_collection_event'
        );
        ontario_obituaries_log( 'v3.16.0: Cron event was missing — re-scheduled.', 'info' );
    }

    // Method 3: If the last collection was VERY stale (> 2× interval),
    // run a synchronous lightweight collection on THIS request.
    // This is a safety net for sites where spawn_cron() also fails
    // (e.g., loopback blocked by firewall/WAF).
    // v3.16.1: Use ensure_collector_loaded() — on frontend requests the Collector
    // class is not pre-loaded by ontario_obituaries_includes() (P2-1 gate).
    if ( $age > ( $max_age * 2 ) && ontario_obituaries_ensure_collector_loaded() ) {
        // Cap to first page only for speed (avoid blocking the page load).
        $cap_pages = function( $urls ) {
            return array_slice( (array) $urls, 0, 1 );
        };
        add_filter( 'ontario_obituaries_discovered_urls', $cap_pages, 10, 1 );

        try {
            ontario_obituaries_log( 'v3.16.0: Data very stale (' . round( $age / 3600 ) . 'h) — running synchronous first-page scrape.', 'info' );
            $collector = new Ontario_Obituaries_Source_Collector();
            $results   = $collector->collect();

            update_option( 'ontario_obituaries_last_collection', array(
                'timestamp' => current_time( 'mysql' ),
                'completed' => current_time( 'mysql' ),
                'results'   => $results,
            ) );

            // Purge caches so new data appears immediately.
            ontario_obituaries_purge_litespeed( 'ontario_obits' );
            delete_transient( 'ontario_obituaries_locations_cache' );
            delete_transient( 'ontario_obituaries_funeral_homes_cache' );

            ontario_obituaries_log(
                sprintf(
                    'v3.16.0: Synchronous scrape complete — %d found, %d added.',
                    isset( $results['obituaries_found'] ) ? intval( $results['obituaries_found'] ) : 0,
                    isset( $results['obituaries_added'] ) ? intval( $results['obituaries_added'] ) : 0
                ),
                'info'
            );
        } finally {
            remove_filter( 'ontario_obituaries_discovered_urls', $cap_pages );
        }
    }
}
add_action( 'init', 'ontario_obituaries_maybe_spawn_cron', 99 );

/**
 * QC-R5/R6 FIX: Rate-limit + optional auth for the public /cron REST endpoint.
 *
 * Replaces '__return_true' which allowed unlimited unauthenticated hits.
 *
 * Two-layer defense:
 *   1. Optional shared-secret: if the option 'ontario_obituaries_cron_secret'
 *      is set, the request must include ?secret=<value> to proceed.  This
 *      converts the endpoint from "public" to "authenticated" for sites that
 *      want full DoS protection.  The cPanel cron command becomes:
 *        curl -s https://monacomonuments.ca/wp-json/ontario-obituaries/v1/cron?secret=MY_KEY
 *   2. Transient rate limiter: caps to 1 request per 60 seconds regardless.
 *      Uses wp_cache_add() for best-effort atomicity when a persistent object
 *      cache is available (Redis/Memcached), falling back to set_transient()
 *      which is non-atomic but sufficient for the single-server shared-hosting
 *      environment.  Worst case on TOCTOU: 2 concurrent requests both pass —
 *      harmless because the callback itself checks staleness and the collection
 *      runner uses its own lock.
 *
 * Returns WP_Error on rate-limit or auth failure so the REST framework returns
 * the appropriate HTTP status code (403 or 429).
 *
 * @return true|WP_Error
 */
function ontario_obituaries_cron_rest_rate_limit() {
    // Layer 1: Optional shared-secret authentication.
    $secret = get_option( 'ontario_obituaries_cron_secret', '' );
    if ( ! empty( $secret ) ) {
        $provided = isset( $_GET['secret'] ) ? sanitize_text_field( wp_unslash( $_GET['secret'] ) ) : '';
        if ( ! hash_equals( $secret, $provided ) ) {
            return new WP_Error(
                'rest_forbidden',
                'Invalid or missing cron secret.',
                array( 'status' => 403 )
            );
        }
    }

    // Layer 2: Transient-based rate limiter (1 req / 60 s).
    $transient_key = 'ontario_obituaries_cron_rest_limiter';
    if ( get_transient( $transient_key ) ) {
        return new WP_Error(
            'rest_rate_limited',
            'Rate limited. Try again in 60 seconds.',
            array( 'status' => 429 )
        );
    }
    set_transient( $transient_key, 1, 60 );
    return true;
}

/**
 * v3.16.0: Register a REST API endpoint for server-side cron.
 *
 * This allows setting up a real crontab entry on the server:
 *   Every 15 min:  curl -s https://monacomonuments.ca/wp-json/ontario-obituaries/v1/cron > /dev/null 2>&1
 *   With secret:   curl -s '.../v1/cron?secret=MY_KEY' > /dev/null 2>&1
 *
 * The endpoint triggers the collection event if the data is stale.
 * QC-R5/R6 FIX: Rate-limited to 1 req/60s + optional shared-secret auth.
 */
function ontario_obituaries_register_cron_rest_route() {
    register_rest_route( 'ontario-obituaries/v1', '/cron', array(
        'methods'             => 'GET',
        'callback'            => 'ontario_obituaries_handle_cron_rest',
        // QC-R5/R6 FIX: Rate-limited + optional-secret permission callback
        // replaces '__return_true'.  Two layers: (1) optional shared-secret
        // via 'ontario_obituaries_cron_secret' option, (2) transient-based
        // 60-second rate limiter.  Together they reduce the DoS surface from
        // "unlimited unauthenticated" to "1 req/60s, optionally authed".
        'permission_callback' => 'ontario_obituaries_cron_rest_rate_limit',
    ) );

    // v3.16.1: Health/status endpoint for monitoring.
    // Returns: version, record counts, last collection time, cron status, source stats.
    // v3.17.0: Restricted to logged-in admins to prevent data leakage.
    register_rest_route( 'ontario-obituaries/v1', '/status', array(
        'methods'             => 'GET',
        'callback'            => 'ontario_obituaries_handle_status_rest',
        'permission_callback' => function() {
            return current_user_can( 'manage_options' );
        },
    ) );

    // v4.1.0: AI Rewriter status endpoint (admin-only).
    register_rest_route( 'ontario-obituaries/v1', '/ai-rewriter', array(
        'methods'             => 'GET',
        'callback'            => 'ontario_obituaries_handle_ai_rewriter_rest',
        'permission_callback' => function() {
            return current_user_can( 'manage_options' );
        },
    ) );
}
add_action( 'rest_api_init', 'ontario_obituaries_register_cron_rest_route' );

function ontario_obituaries_handle_cron_rest() {
    // Trigger pending cron events.
    if ( function_exists( 'spawn_cron' ) ) {
        spawn_cron();
    }

    // Also check if a collection should run now.
    $last = get_option( 'ontario_obituaries_last_collection', array() );
    $last_ts = ! empty( $last['completed'] ) ? strtotime( $last['completed'] ) : 0;
    $age_hours = round( ( time() - $last_ts ) / 3600, 1 );

    $settings  = ontario_obituaries_get_settings();
    $frequency = isset( $settings['frequency'] ) ? $settings['frequency'] : 'twicedaily';
    $intervals = array(
        'hourly'     => 1,
        'twicedaily' => 12,
        'daily'      => 24,
        'weekly'     => 168,
    );
    $max_hours = isset( $intervals[ $frequency ] ) ? $intervals[ $frequency ] : 12;

    $result = array(
        'status'             => 'ok',
        'last_collection'    => ! empty( $last['completed'] ) ? $last['completed'] : 'never',
        'age_hours'          => $age_hours,
        'max_interval_hours' => $max_hours,
        'stale'              => $age_hours > $max_hours,
        'cron_spawned'       => true,
    );

    // If very stale, run synchronous collection.
    // v3.16.1: Use ensure_collector_loaded() — REST API requests are not
    // admin/cron/AJAX, so the Collector may not be pre-loaded.
    if ( $age_hours > $max_hours && ontario_obituaries_ensure_collector_loaded() ) {
        $collector    = new Ontario_Obituaries_Source_Collector();
        $collect_res  = $collector->collect();

        update_option( 'ontario_obituaries_last_collection', array(
            'timestamp' => current_time( 'mysql' ),
            'completed' => current_time( 'mysql' ),
            'results'   => $collect_res,
        ) );

        ontario_obituaries_purge_litespeed( 'ontario_obits' );
        delete_transient( 'ontario_obituaries_locations_cache' );
        delete_transient( 'ontario_obituaries_funeral_homes_cache' );

        $result['collection_ran'] = true;
        $result['found']          = isset( $collect_res['obituaries_found'] ) ? intval( $collect_res['obituaries_found'] ) : 0;
        $result['added']          = isset( $collect_res['obituaries_added'] ) ? intval( $collect_res['obituaries_added'] ) : 0;

        // BUG-C4 FIX (v5.0.4): Dedup deferred to one-shot cron (10s later).
        // Removed inline call — avoids adding heavy DB scans to this already
        // heavy REST handler, and prevents DoS via the public /cron endpoint.
        // Uses ontario_obituaries_schedule_dedup_once() which has a built-in
        // wp_next_scheduled guard to prevent cron-queue churn from repeated
        // /cron hits (each hit is a no-op if a one-shot event already exists).
        ontario_obituaries_schedule_dedup_once();
    }

    return rest_ensure_response( $result );
}

/**
 * v3.16.1: Status/health endpoint for monitoring.
 *
 * Returns a JSON summary of the plugin's current state:
 *   - version
 *   - total active obituary records
 *   - records added in the last 7 days
 *   - last collection timestamp and results
 *   - next scheduled cron event
 *   - active source count
 *   - collector class availability
 *
 * Useful for external monitoring dashboards, uptime checks, and debugging.
 *
 * @since 3.16.1
 * @return WP_REST_Response
 */
function ontario_obituaries_handle_status_rest() {
    global $wpdb;
    $table = $wpdb->prefix . 'ontario_obituaries';

    $status = array(
        'status'  => 'ok',
        'version' => ONTARIO_OBITUARIES_VERSION,
    );

    // Check if table exists
    $table_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
    $status['table_exists'] = $table_exists;

    if ( $table_exists ) {
        $status['total_records']   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE suppressed_at IS NULL" );
        $status['recent_7d']       = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE date_of_death >= %s AND suppressed_at IS NULL",
            gmdate( 'Y-m-d', strtotime( '-7 days' ) )
        ) );
        $status['suppressed']      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE suppressed_at IS NOT NULL" );
    }

    // Last collection
    $last = get_option( 'ontario_obituaries_last_collection', array() );
    $status['last_collection'] = ! empty( $last['completed'] ) ? $last['completed'] : 'never';
    if ( ! empty( $last['completed'] ) ) {
        $age_h = round( ( time() - strtotime( $last['completed'] ) ) / 3600, 1 );
        $status['age_hours'] = $age_h;
    }
    if ( isset( $last['results'] ) ) {
        $status['last_found'] = isset( $last['results']['obituaries_found'] ) ? intval( $last['results']['obituaries_found'] ) : 0;
        $status['last_added'] = isset( $last['results']['obituaries_added'] ) ? intval( $last['results']['obituaries_added'] ) : 0;
    }

    // Cron status
    $next = wp_next_scheduled( 'ontario_obituaries_collection_event' );
    $status['cron_scheduled']  = (bool) $next;
    $status['cron_next']       = $next ? gmdate( 'Y-m-d H:i:s', $next ) : null;

    // Source registry stats
    if ( class_exists( 'Ontario_Obituaries_Source_Registry' ) ) {
        $src_stats = Ontario_Obituaries_Source_Registry::get_stats();
        $status['sources_total']  = isset( $src_stats['total'] )  ? intval( $src_stats['total'] )  : 0;
        $status['sources_active'] = isset( $src_stats['active'] ) ? intval( $src_stats['active'] ) : 0;
    }

    // Collector class availability
    $status['collector_loaded'] = class_exists( 'Ontario_Obituaries_Source_Collector' );

    // v4.1.0: AI Rewriter stats
    if ( class_exists( 'Ontario_Obituaries_AI_Rewriter' ) ) {
        $rewriter = new Ontario_Obituaries_AI_Rewriter();
        $status['ai_rewriter'] = array(
            'configured' => $rewriter->is_configured(),
            'stats'      => $rewriter->get_stats(),
        );
    }

    return rest_ensure_response( $status );
}

/**
 * v4.1.0: AI Rewriter REST endpoint.
 *
 * GET: Returns rewriter status and stats.
 * Supports ?action=trigger to manually start a rewrite batch.
 *
 * @return WP_REST_Response
 */
function ontario_obituaries_handle_ai_rewriter_rest( $request ) {
    if ( ! class_exists( 'Ontario_Obituaries_AI_Rewriter' ) ) {
        require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ai-rewriter.php';
    }

    $rewriter = new Ontario_Obituaries_AI_Rewriter();

    $result = array(
        'configured' => $rewriter->is_configured(),
        'stats'      => $rewriter->get_stats(),
    );

    // Allow manual trigger via ?action=trigger
    $action = $request->get_param( 'action' );
    if ( 'trigger' === $action ) {
        if ( ! $rewriter->is_configured() ) {
            $result['error'] = 'Groq API key not configured. Set ontario_obituaries_groq_api_key in wp_options.';
        } else {
            $batch_result = $rewriter->process_batch();
            $result['batch_result'] = $batch_result;
            $result['stats']        = $rewriter->get_stats(); // Refresh stats after batch.
        }
    }

    return rest_ensure_response( $result );
}

/**
 * v3.16.0: LiteSpeed-aware cron spawning.
 *
 * When LiteSpeed Cache is active, tell it to exclude wp-cron.php from
 * caching so the loopback request from spawn_cron() actually executes PHP.
 * Also add the nocache header on cron requests.
 */
function ontario_obituaries_litespeed_cron_fix() {
    // If we're serving a wp-cron.php request, ensure it's not cached.
    if ( defined( 'DOING_CRON' ) && DOING_CRON && ! headers_sent() ) {
        header( 'X-LiteSpeed-Cache-Control: no-cache' );
    }
}
add_action( 'init', 'ontario_obituaries_litespeed_cron_fix', 0 );

/**
 * Add integration with Obituary Assistant
 */
function ontario_obituaries_integration() {
    if ( ontario_obituaries_check_dependency() ) {
        add_filter( 'obituary_assistant_sources', 'ontario_obituaries_register_source' );
    }
}
add_action( 'plugins_loaded', 'ontario_obituaries_integration', 20 );

/**
 * Register as a source for Obituary Assistant
 */
function ontario_obituaries_register_source( $sources ) {
    $sources['ontario'] = array(
        'name'        => 'Ontario Obituaries',
        'description' => 'Obituaries from Ontario, Canada',
        'callback'    => 'ontario_obituaries_get_data_for_assistant',
    );
    return $sources;
}

/**
 * Provide data to Obituary Assistant
 */
function ontario_obituaries_get_data_for_assistant() {
    $display = new Ontario_Obituaries_Display();
    return $display->get_obituaries( array( 'limit' => 50 ) );
}

/**
 * Add a dashboard widget for obituary statistics
 */
function ontario_obituaries_add_dashboard_widget() {
    wp_add_dashboard_widget(
        'ontario_obituaries_stats',
        __( 'Ontario Obituaries Statistics', 'ontario-obituaries' ),
        'ontario_obituaries_render_dashboard_widget'
    );
}
add_action( 'wp_dashboard_setup', 'ontario_obituaries_add_dashboard_widget' );

/**
 * Render the dashboard widget
 */
function ontario_obituaries_render_dashboard_widget() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ontario_obituaries';

    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
        echo '<p>' . esc_html__( 'Obituaries table not found. Please deactivate and reactivate the plugin.', 'ontario-obituaries' ) . '</p>';
        return;
    }

    // P1-3 FIX: Exclude suppressed obituaries from dashboard counts
    $total_count     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table_name}` WHERE suppressed_at IS NULL" );
    $last_week_count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM `{$table_name}` WHERE date_of_death >= %s AND suppressed_at IS NULL",
        gmdate( 'Y-m-d', strtotime( '-7 days' ) )
    ) );
    $source_counts = $wpdb->get_results(
        "SELECT funeral_home, COUNT(*) as count FROM `{$table_name}` WHERE funeral_home != '' AND suppressed_at IS NULL GROUP BY funeral_home ORDER BY count DESC LIMIT 10"
    );
    $last_collection = get_option( 'ontario_obituaries_last_collection' );

    echo '<div class="ontario-obituaries-widget">';
    echo '<p>' . sprintf( esc_html__( 'Total Obituaries: %s', 'ontario-obituaries' ), '<strong>' . intval( $total_count ) . '</strong>' ) . '</p>';
    echo '<p>' . sprintf( esc_html__( 'Added in Last 7 Days: %s', 'ontario-obituaries' ), '<strong>' . intval( $last_week_count ) . '</strong>' ) . '</p>';

    if ( ! empty( $source_counts ) ) {
        echo '<h4>' . esc_html__( 'Top Sources:', 'ontario-obituaries' ) . '</h4><ul>';
        foreach ( $source_counts as $source ) {
            echo '<li>' . esc_html( $source->funeral_home ) . ': ' . intval( $source->count ) . '</li>';
        }
        echo '</ul>';
    }

    if ( ! empty( $last_collection ) && isset( $last_collection['completed'] ) ) {
        $completed_ts = strtotime( $last_collection['completed'] );
        if ( $completed_ts ) {
            $time_ago = human_time_diff( $completed_ts, current_time( 'U' ) );
            echo '<p>' . sprintf( esc_html__( 'Last Collection: %s ago', 'ontario-obituaries' ), '<strong>' . esc_html( $time_ago ) . '</strong>' ) . '</p>';
        }
        if ( isset( $last_collection['results'] ) ) {
            $r = $last_collection['results'];
            echo '<p>' . sprintf(
                esc_html__( 'Last Run: %1$d found, %2$d added', 'ontario-obituaries' ),
                intval( $r['obituaries_found'] ),
                intval( $r['obituaries_added'] )
            ) . '</p>';
        }
    }

    echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=ontario-obituaries' ) ) . '">' . esc_html__( 'Manage Obituaries', 'ontario-obituaries' ) . '</a></p>';
    echo '</div>';
}
