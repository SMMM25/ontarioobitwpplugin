<?php
/**
 * Plugin Name: Ontario Obituaries
 * Description: Ontario-wide obituary data ingestion with coverage-first, rights-aware publishing — Compatible with Obituary Assistant
 * Version: 4.6.3
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
define( 'ONTARIO_OBITUARIES_VERSION', '4.6.3' );
define( 'ONTARIO_OBITUARIES_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ONTARIO_OBITUARIES_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ONTARIO_OBITUARIES_PLUGIN_FILE', __FILE__ );

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
    $authorized = array_map( 'trim', explode( ',', ONTARIO_OBITUARIES_AUTHORIZED_DOMAINS ) );
    foreach ( $authorized as $domain ) {
        if ( $host === $domain || false !== strpos( $host, $domain ) ) {
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

        // Fresh start: wipe all existing obituaries so the new pipeline
        // (scrape → AI rewrite → validate → publish) starts clean.
        // Suppression records are kept so do-not-republish rules survive.
        $wpdb->query( "TRUNCATE TABLE `{$table_name}`" );
        ontario_obituaries_log( 'v4.6.0: Fresh start — truncated obituaries table for new scrape→rewrite→publish pipeline.', 'info' );

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

    // P0-1 FIX: Schedule initial collection as a one-time cron event
    // instead of running synchronously during activation (which causes timeouts).
    if ( ! wp_next_scheduled( 'ontario_obituaries_initial_collection' ) ) {
        wp_schedule_single_event( time() + 30, 'ontario_obituaries_initial_collection' );
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
 * Two-pass approach:
 *   Pass 1 — Exact match: same name + same date_of_death (fast SQL GROUP BY).
 *   Pass 2 — Fuzzy match: normalized name + same date_of_death (catches
 *            case/punctuation differences like "Ronald McLEOD" vs "Ronald McLeod").
 *            Runs even when records have different funeral_home values.
 *
 * For each group, keeps the record with the longest description and enriches
 * it with funeral_home, city_normalized, location, image_url, and source_url
 * from duplicates.
 *
 * Runs:
 *   - On every admin page load (lightweight — the COUNT query exits fast).
 *   - On frontend: once per plugin version via option flag.
 *   - After every scheduled scrape (hooked to the collection event).
 *   - After WP Pusher deploy via on_plugin_update() clearing the flag.
 */
function ontario_obituaries_cleanup_duplicates() {
    // On frontend: run once per version to avoid slowing every page load.
    // On admin/cron: always check.
    $version_key = 'ontario_obituaries_dedup_' . ONTARIO_OBITUARIES_VERSION;
    if ( ! is_admin() && ! wp_doing_cron() && get_option( $version_key ) ) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'ontario_obituaries';

    // Check table exists
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
        return;
    }

    $removed = 0;

    // v3.6.0: Auto-clean test data on production (records with 'Test' in name
    // and source_url pointing to home_url — added via add_test_data() debug action).
    $test_removed = $wpdb->query(
        "DELETE FROM `{$table}` WHERE name LIKE '%Test %' AND ( source_url = '' OR source_url LIKE '%monacomonuments.ca%' OR source_url LIKE '%example.com%' )"
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

        foreach ( $name_only_groups as $group ) {
            if ( count( $group ) <= 1 ) {
                continue;
            }
            $ids = wp_list_pluck( $group, 'id' );
            $removed += ontario_obituaries_merge_duplicate_ids( $table, $ids );
        }

        if ( $removed > 0 ) {
            ontario_obituaries_log( sprintf( 'Pass 3 (name-only): merged %d duplicate records', $removed ), 'info' );
        }
    }

    update_option( $version_key, true );

    // Clear transient caches so pages reflect the deduped data
    delete_transient( 'ontario_obituaries_locations_cache' );
    delete_transient( 'ontario_obituaries_funeral_homes_cache' );

    if ( $removed > 0 ) {
        ontario_obituaries_log( sprintf( 'Duplicate cleanup v%s: removed %d duplicate obituaries', ONTARIO_OBITUARIES_VERSION, $removed ), 'info' );

        // v3.5.0: Purge LiteSpeed listing caches so deduped results appear immediately.
        ontario_obituaries_purge_litespeed( 'ontario_obits' );
    }
}
add_action( 'init', 'ontario_obituaries_cleanup_duplicates', 5 );

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

    // v3.0.0: Remove SEO rewrite rules
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'ontario_obituaries_deactivate' );

/**
 * Scheduled task hook for collecting obituaries.
 * v3.0.0: Uses Source Collector (adapter-based) as primary, falls back to legacy scraper.
 */
function ontario_obituaries_scheduled_collection() {
    // v4.2.0: Domain lock — don't scrape on unauthorized domains.
    if ( ! ontario_obituaries_is_authorized_domain() ) {
        ontario_obituaries_log( 'Domain lock: Scheduled collection blocked — unauthorized domain.', 'warning' );
        return;
    }

    // v3.0.0: Use the new Source Collector
    if ( class_exists( 'Ontario_Obituaries_Source_Collector' ) ) {
        $collector = new Ontario_Obituaries_Source_Collector();
        $results   = $collector->collect();
    } else {
        // Fallback to legacy scraper
        if ( ! class_exists( 'Ontario_Obituaries_Scraper' ) ) {
            require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries-scraper.php';
        }
        $scraper = new Ontario_Obituaries_Scraper();
        $results = $scraper->collect();
    }

    update_option( 'ontario_obituaries_last_collection', array(
        'timestamp' => current_time( 'mysql' ),
        'completed' => current_time( 'mysql' ),
        'results'   => $results,
    ) );

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

    // v3.3.0: Run dedup after every scrape to catch cross-source duplicates immediately
    ontario_obituaries_cleanup_duplicates();

    // v4.6.0: IndexNow is now triggered after AI rewrite publishes records,
    // not after scraping. Pending records should NOT be submitted to search engines.
    // See ontario_obituaries_ai_rewrite_batch() for the IndexNow trigger.

    // v4.6.3: After collection, schedule AI rewrite batch if Groq key is configured.
    // No longer gated behind 'ai_rewrite_enabled' checkbox — the v4.6.0 pipeline
    // requires AI rewrites for ANY record to become published. Having a key IS the intent.
    $groq_key_for_rewrite = get_option( 'ontario_obituaries_groq_api_key', '' );
    if ( ! empty( $groq_key_for_rewrite ) ) {
        if ( ! wp_next_scheduled( 'ontario_obituaries_ai_rewrite_batch' ) ) {
            wp_schedule_single_event( time() + 30, 'ontario_obituaries_ai_rewrite_batch' );
            ontario_obituaries_log( 'v4.6.3: Scheduled AI rewrite batch (30s after collection).', 'info' );
        }
    }

    // v4.3.0: After collection, schedule GoFundMe linker batch if enabled.
    $settings_for_rewrite = ontario_obituaries_get_settings();
    if ( ! empty( $settings_for_rewrite['gofundme_enabled'] ) ) {
        if ( ! wp_next_scheduled( 'ontario_obituaries_gofundme_batch' ) ) {
            wp_schedule_single_event( time() + 180, 'ontario_obituaries_gofundme_batch' );
            ontario_obituaries_log( 'v4.3.0: Scheduled GoFundMe linker batch (180s after collection).', 'info' );
        }
    }
}
add_action( 'ontario_obituaries_collection_event', 'ontario_obituaries_scheduled_collection' );

/**
 * v4.1.0: AI Rewrite batch cron handler.
 *
 * Processes up to 25 unrewritten obituaries per run.
 * Self-reschedules if more remain.
 */
function ontario_obituaries_ai_rewrite_batch() {
    if ( ! class_exists( 'Ontario_Obituaries_AI_Rewriter' ) ) {
        require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ai-rewriter.php';
    }

    $rewriter = new Ontario_Obituaries_AI_Rewriter();

    if ( ! $rewriter->is_configured() ) {
        ontario_obituaries_log( 'AI Rewriter: Skipping batch — Groq API key not configured.', 'info' );
        return;
    }

    $result = $rewriter->process_batch();

    // Log results.
    ontario_obituaries_log(
        sprintf(
            'AI Rewriter batch: %d processed, %d succeeded, %d failed.',
            $result['processed'],
            $result['succeeded'],
            $result['failed']
        ),
        'info'
    );

    // If there are more pending, schedule another batch in 5 minutes.
    $pending = $rewriter->get_pending_count();
    if ( $pending > 0 && ! wp_next_scheduled( 'ontario_obituaries_ai_rewrite_batch' ) ) {
        wp_schedule_single_event( time() + 300, 'ontario_obituaries_ai_rewrite_batch' );
        ontario_obituaries_log(
            sprintf( 'AI Rewriter: %d obituaries pending — scheduling next batch in 5 min.', $pending ),
            'info'
        );
    }

    // Purge cache and submit newly published records to IndexNow.
    if ( $result['succeeded'] > 0 ) {
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
}
add_action( 'ontario_obituaries_ai_rewrite_batch', 'ontario_obituaries_ai_rewrite_batch' );

/**
 * v4.6.3: Piggyback AI rewriter on admin page loads.
 *
 * WP cron is unreliable on LiteSpeed hosting (cron events don't fire).
 * This hook runs on every admin page load, throttled to once per 3 minutes
 * via a transient. Processes a SMALL batch (3 records = ~18 seconds max)
 * to avoid blocking the admin dashboard.
 *
 * v4.6.3 FIX: The previous version gated this behind 'ai_rewrite_enabled'
 * which defaults to false. Since the entire v4.6.0 pipeline requires AI
 * rewrites (pending→published), having a configured Groq API key IS the
 * intent signal. We now only require: (a) API key exists, (b) pending
 * records exist. The checkbox controls the CRON path, not this safety net.
 */
function ontario_obituaries_maybe_run_rewriter() {
    // Throttle: only run once per 3 minutes.
    if ( get_transient( 'ontario_obituaries_rewriter_throttle' ) ) {
        return;
    }

    // Only run on authorized domain.
    if ( function_exists( 'ontario_obituaries_is_authorized_domain' ) && ! ontario_obituaries_is_authorized_domain() ) {
        return;
    }

    if ( ! class_exists( 'Ontario_Obituaries_AI_Rewriter' ) ) {
        if ( defined( 'ONTARIO_OBITUARIES_PLUGIN_DIR' ) ) {
            require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ai-rewriter.php';
        } else {
            return;
        }
    }

    // v4.6.3: Use small batch (3 records). 3 × 6s delay = 18s max.
    // This won't block the admin page noticeably.
    $rewriter = new Ontario_Obituaries_AI_Rewriter( 3 );
    if ( ! $rewriter->is_configured() ) {
        return;
    }

    // Check if there are pending records.
    $pending = $rewriter->get_pending_count();
    if ( $pending < 1 ) {
        return;
    }

    // Set throttle lock BEFORE processing (3 minutes).
    set_transient( 'ontario_obituaries_rewriter_throttle', 1, 180 );

    // Extend PHP timeout for this request.
    @set_time_limit( 120 );

    $result = $rewriter->process_batch();

    if ( function_exists( 'ontario_obituaries_log' ) ) {
        ontario_obituaries_log(
            sprintf(
                'v4.6.3 Piggyback rewriter: %d processed, %d succeeded, %d failed. %d still pending.',
                $result['processed'],
                $result['succeeded'],
                $result['failed'],
                $rewriter->get_pending_count()
            ),
            'info'
        );
    }

    // Purge cache if any records were published.
    if ( $result['succeeded'] > 0 && function_exists( 'ontario_obituaries_purge_litespeed' ) ) {
        ontario_obituaries_purge_litespeed( 'ontario_obits' );
    }
}
add_action( 'admin_init', 'ontario_obituaries_maybe_run_rewriter' );

/**
 * v4.6.3: Also piggyback on front-end page loads.
 * Ensures the pipeline moves even if no admin visits the dashboard.
 * Same throttle transient prevents double-running.
 */
add_action( 'wp_loaded', 'ontario_obituaries_maybe_run_rewriter' );

/**
 * v4.6.3: AJAX endpoint to manually trigger AI rewriter from admin UI.
 *
 * Processes a batch of 10 obituaries (60s max) and returns progress.
 * The front-end calls this sequentially to chew through the backlog.
 * No dependency on WP-cron, no dependency on ai_rewrite_enabled checkbox.
 */
function ontario_obituaries_ajax_run_rewriter() {
    // Verify nonce and permissions.
    check_ajax_referer( 'ontario_obituaries_rescan', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Permission denied.' ) );
    }

    @set_time_limit( 300 );

    if ( ! class_exists( 'Ontario_Obituaries_AI_Rewriter' ) ) {
        require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ai-rewriter.php';
    }

    // Process 10 records per AJAX call (10 × 6s = 60s max).
    $rewriter = new Ontario_Obituaries_AI_Rewriter( 10 );

    if ( ! $rewriter->is_configured() ) {
        wp_send_json_error( array(
            'message' => 'Groq API key not configured. Go to Settings → AI Rewrite Engine and enter your key.',
        ) );
    }

    $pending_before = $rewriter->get_pending_count();

    if ( $pending_before < 1 ) {
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
                'v4.6.3 Manual rewriter: %d processed, %d succeeded, %d failed. %d → %d pending.',
                $result['processed'],
                $result['succeeded'],
                $result['failed'],
                $pending_before,
                $pending_after
            ),
            'info'
        );
    }

    wp_send_json_success( array(
        'message'   => sprintf(
            'Processed %d: %d published, %d failed. %d still pending.',
            $result['processed'],
            $result['succeeded'],
            $result['failed'],
            $pending_after
        ),
        'processed' => $result['processed'],
        'succeeded' => $result['succeeded'],
        'failed'    => $result['failed'],
        'errors'    => $result['errors'],
        'pending'   => $pending_after,
        'done'      => ( $pending_after < 1 ),
    ) );
}
add_action( 'wp_ajax_ontario_obituaries_run_rewriter', 'ontario_obituaries_ajax_run_rewriter' );

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
    $result = $linker->process_batch();

    ontario_obituaries_log(
        sprintf(
            'GoFundMe Linker batch: %d processed, %d matched, %d no match.',
            $result['processed'], $result['matched'], $result['no_match']
        ),
        'info'
    );

    // Schedule next batch if there are pending obituaries.
    $stats = $linker->get_stats();
    if ( $stats['pending'] > 0 && ! wp_next_scheduled( 'ontario_obituaries_gofundme_batch' ) ) {
        wp_schedule_single_event( time() + 300, 'ontario_obituaries_gofundme_batch' );
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

    $result = $checker->run_audit_batch();

    ontario_obituaries_log(
        sprintf(
            'Authenticity audit: %d audited, %d passed, %d flagged.',
            $result['audited'], $result['passed'], $result['flagged']
        ),
        'info'
    );

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

    if ( ! empty( $settings['authenticity_enabled'] ) && ! wp_next_scheduled( 'ontario_obituaries_authenticity_audit' ) ) {
        wp_schedule_event( time() + 300, 'four_hours', 'ontario_obituaries_authenticity_audit' );
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

    $result = $optimizer->run_optimization_analysis();

    if ( is_wp_error( $result ) ) {
        ontario_obituaries_log( 'Google Ads Optimizer: Analysis failed — ' . $result->get_error_message(), 'error' );
    } else {
        $score = isset( $result['analysis']['overall_score'] ) ? $result['analysis']['overall_score'] : 'N/A';
        ontario_obituaries_log( sprintf( 'Google Ads Optimizer: Daily analysis complete. Score: %s.', $score ), 'info' );
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
 * v3.5.0: WP Pusher / plugin-update integration.
 *
 * When WP Pusher pushes a new version, the activation hook does NOT re-fire.
 * This callback re-registers rewrite rules, flushes them, and purges known
 * cache plugins so that template and version changes take effect immediately.
 */
function ontario_obituaries_on_plugin_update() {
    $stored_version = get_option( 'ontario_obituaries_deployed_version', '' );
    if ( $stored_version === ONTARIO_OBITUARIES_VERSION ) {
        return; // Already deployed this version.
    }

    // Re-register rewrite rules BEFORE flushing so they are included in the
    // regeneration. This is critical for WP Pusher deploys where the activation
    // hook does not fire and rules would otherwise be missing.
    if ( class_exists( 'Ontario_Obituaries_SEO' ) ) {
        Ontario_Obituaries_SEO::flush_rules();
    } else {
        flush_rewrite_rules();
    }

    // Purge LiteSpeed Cache — the sole cache layer on monacomonuments.ca.
    // Full purge on version update to ensure all pages serve the new HTML/CSS/JS.
    ontario_obituaries_purge_litespeed();

    // Clear WordPress object cache
    wp_cache_flush();

    // Clear our own transients
    delete_transient( 'ontario_obituaries_locations_cache' );
    delete_transient( 'ontario_obituaries_funeral_homes_cache' );

    // Force dedup to run for the new version
    delete_option( 'ontario_obituaries_dedup_' . $stored_version );

    // Mark this version as deployed
    update_option( 'ontario_obituaries_deployed_version', ONTARIO_OBITUARIES_VERSION );

    // v3.9.0: Source registry health check — re-seed if the sources table is empty.
    // This catches cases where the table was truncated, a failed migration cleared it,
    // or the activation hook's seed ran before the table was created.
    // Runs once per deploy via the deployed_version guard above.
    if ( class_exists( 'Ontario_Obituaries_Source_Registry' ) ) {
        $source_stats = Ontario_Obituaries_Source_Registry::get_stats();
        if ( isset( $source_stats['total'] ) && 0 === intval( $source_stats['total'] ) ) {
            Ontario_Obituaries_Source_Registry::seed_defaults();
            $new_stats = Ontario_Obituaries_Source_Registry::get_stats();
            ontario_obituaries_log(
                sprintf( 'v3.9.0: Source registry was empty — re-seeded %d sources.', intval( $new_stats['total'] ) ),
                'info'
            );
        }
    }

    // v3.7.0–3.9.0: Comprehensive one-time data repair.
    //
    // Previous versions had three data corruption issues:
    //  1) normalize_date("1926") → strtotime → today's date stored as date_of_death
    //  2) Missing city_normalized for records inserted before v3.0 columns were added
    //  3) Test data (name LIKE '%Test %') not cleaned up
    //
    // Repair strategy:
    //  - Delete all records from yorkregion.com that have corrupted dates
    //    (date_of_death = 2026-02-09 — the day the broken scrape ran).
    //  - Backfill city_normalized from location for any rows missing it.
    //  - Test data is handled separately by ontario_obituaries_cleanup_duplicates().
    if ( version_compare( $stored_version, '3.9.0', '<' ) ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';
        $corrupt_date = '2026-02-09'; // The specific date from the broken scrape

        // Remove yorkregion records with the known corrupt date (they all show 2026-02-09).
        $corrupt_removed = $wpdb->query( $wpdb->prepare(
            "DELETE FROM `{$table}` WHERE date_of_death = %s AND source_domain = 'obituaries.yorkregion.com'",
            $corrupt_date
        ) );
        if ( $corrupt_removed > 0 ) {
            ontario_obituaries_log( sprintf( 'v3.9.0 data repair: removed %d yorkregion records with corrupted date_of_death=%s', $corrupt_removed, $corrupt_date ), 'info' );
        }

        // Also remove records where BOTH dates equal the corrupt date (generic strtotime corruption).
        $both_corrupt = $wpdb->query( $wpdb->prepare(
            "DELETE FROM `{$table}` WHERE date_of_death = %s AND date_of_birth = %s",
            $corrupt_date, $corrupt_date
        ) );
        if ( $both_corrupt > 0 ) {
            ontario_obituaries_log( sprintf( 'v3.9.0 data repair: removed %d records with DoD=DoB=%s', $both_corrupt, $corrupt_date ), 'info' );
        }

        // Backfill city_normalized from location where it's empty but location is not.
        $backfilled = $wpdb->query(
            "UPDATE `{$table}` SET city_normalized = TRIM(location) WHERE city_normalized = '' AND location != '' AND location IS NOT NULL"
        );
        if ( $backfilled > 0 ) {
            ontario_obituaries_log( sprintf( 'v3.9.0 data repair: backfilled city_normalized for %d records from location', $backfilled ), 'info' );
        }

        // v3.9.0: Always schedule a re-scrape on upgrade to ensure data is current
        // after pagination fix + source registry re-seed.
        // Guard: check wp_next_scheduled() to prevent duplicate scheduling if
        // multiple requests hit the upgrade path concurrently.
        if ( ! wp_next_scheduled( 'ontario_obituaries_initial_collection' ) ) {
            wp_schedule_single_event( time() + 60, 'ontario_obituaries_initial_collection' );
            ontario_obituaries_log( 'v3.9.0: Scheduled re-scrape after data repair + source re-seed.', 'info' );
        }

        // Log the current record count so we can verify data state post-deploy.
        $current_count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE suppressed_at IS NULL" );
        ontario_obituaries_log( sprintf( 'v3.9.0 post-repair: %d active obituary records in database.', intval( $current_count ) ), 'info' );
    }

    // v3.10.0: Disable broken FrontRunner/redirect sources in the registry and
    // run an immediate synchronous scrape if the obituaries table is empty.
    //
    // Why synchronous instead of WP-Cron:
    //   WP-Cron only fires on page visits. On LiteSpeed-cached sites, cached
    //   page loads skip PHP entirely, so wp_schedule_single_event() may never
    //   fire. A synchronous scrape on deploy guarantees data appears immediately.
    //
    // Timeout safety:
    //   The synchronous scrape caps each source to 1 page via a temporary filter
    //   on discover_listing_urls(). With broken sources disabled, only
    //   yorkregion.com is active → 1 page × ~1s = ~1s. Well within the
    //   typical 30s PHP max_execution_time. The full multi-page scrape is
    //   then scheduled via WP-Cron for background completion.
    if ( version_compare( $stored_version, '3.10.0', '<' ) ) {
        // Re-seed to pick up enabled=0 changes for broken FrontRunner/redirect sources.
        //
        // IDEMPOTENCE NOTE: upsert_source() checks for an existing row by 'domain'
        // (UNIQUE KEY) and does a full UPDATE if found. This means every field in
        // seed_defaults() is overwritten on re-seed — including 'enabled'. This is
        // the desired behavior for v3.10.0: we WANT to force-disable broken
        // FrontRunner/redirect sources even if an admin previously re-enabled them.
        // Sources without an explicit 'enabled' key in seed_defaults() default to
        // enabled=1 via wp_parse_args(), so they are unaffected.
        if ( class_exists( 'Ontario_Obituaries_Source_Registry' ) ) {
            Ontario_Obituaries_Source_Registry::seed_defaults();

            // Condition 3: Log exactly which domains were force-disabled so admins
            // can trace "why did my source turn off?" without guessing. This is a
            // policy decision, not a bug — these sources return 0 records server-side.
            $forced_disabled = array(
                'roadhouseandrose.com',
                'forrestandtaylor.com',
                'wardfuneralhome.com',
                'marshallfuneralhome.com',
            );
            ontario_obituaries_log(
                sprintf(
                    'v3.10.0: Re-seeded source registry. Force-disabled %d broken sources: %s. '
                    . 'Reason: FrontRunner JS-rendered (0 records server-side) or redirect to SPA. '
                    . 'Re-enable via WP admin after verifying the source returns parseable HTML.',
                    count( $forced_disabled ),
                    implode( ', ', $forced_disabled )
                ),
                'info'
            );
        }

        // Guard: verify the obituaries table exists before querying it.
        // On a fresh install where activation didn't complete, the table may be missing.
        // Treat a missing table as 0 records (no data to show) so the synchronous
        // scrape can attempt to create it via the collector's own table-check.
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';
        $table_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
        $current_count = $table_exists
            ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE suppressed_at IS NULL" )
            : 0;

        if ( 0 === $current_count && ontario_obituaries_ensure_collector_loaded() ) {
            ontario_obituaries_log( 'v3.10.0: Obituaries table is empty — running synchronous first-page scrape.', 'info' );

            // Cap the synchronous scrape to 1 page per source to avoid timeouts.
            // The filter limits discover_listing_urls() to return only the first
            // page URL (~25 cards on yorkregion.com). The full multi-page scrape
            // is scheduled via WP-Cron below for background completion.
            $cap_pages = function( $urls, $source = null ) {
                return array_slice( (array) $urls, 0, 1 );
            };
            add_filter( 'ontario_obituaries_discovered_urls', $cap_pages, 10, 2 );

            // Condition 2: try/finally guarantees filter removal even if collect()
            // throws an uncaught exception or triggers an unexpected code path.
            try {
                $collector    = new Ontario_Obituaries_Source_Collector();
                $sync_results = $collector->collect();
            } finally {
                remove_filter( 'ontario_obituaries_discovered_urls', $cap_pages );
            }

            ontario_obituaries_log(
                sprintf(
                    'v3.10.0: Synchronous first-page scrape complete — %d found, %d added.',
                    isset( $sync_results['obituaries_found'] ) ? intval( $sync_results['obituaries_found'] ) : 0,
                    isset( $sync_results['obituaries_added'] ) ? intval( $sync_results['obituaries_added'] ) : 0
                ),
                'info'
            );

            // Purge caches so new data appears immediately.
            ontario_obituaries_purge_litespeed( 'ontario_obits' );
            delete_transient( 'ontario_obituaries_locations_cache' );
            delete_transient( 'ontario_obituaries_funeral_homes_cache' );
        }

        // Schedule a full multi-page background scrape via WP-Cron so the
        // remaining pages (2–5) are collected without blocking the current request.
        if ( ! wp_next_scheduled( 'ontario_obituaries_initial_collection' ) ) {
            wp_schedule_single_event( time() + 120, 'ontario_obituaries_initial_collection' );
            ontario_obituaries_log( 'v3.10.0: Scheduled full background scrape (all pages) in 2 minutes.', 'info' );
        }

        // Schedule the recurring cron event if not already scheduled.
        if ( ! wp_next_scheduled( 'ontario_obituaries_collection_event' ) ) {
            $settings  = ontario_obituaries_get_settings();
            $frequency = isset( $settings['frequency'] ) ? $settings['frequency'] : 'twicedaily';
            $time      = isset( $settings['time'] ) ? $settings['time'] : '03:00';
            wp_schedule_event(
                ontario_obituaries_next_cron_timestamp( $time ),
                $frequency,
                'ontario_obituaries_collection_event'
            );
            ontario_obituaries_log( 'v3.10.0: Recurring collection cron was missing — re-scheduled.', 'info' );
        }
    }

    // v3.13.2: Force-disable 403/SPA sources that were added to seed_defaults()
    // in v3.13.0 but never applied to the live database. The seed_defaults()
    // changes only affect fresh installs or empty registries; existing sites
    // retained enabled=1 for these sources, wasting ~30s of scrape time on
    // every cron run hitting 403 walls and an empty React SPA shell.
    //
    // This uses targeted UPDATE queries (not seed_defaults/upsert) to:
    //   - Disable only the specific broken domains
    //   - Preserve all other source state (circuit breaker, last_success, etc.)
    //   - Not overwrite any admin-customized fields
    //
    // Runs once via the deployed_version guard at the top of this function.
    if ( version_compare( $stored_version, '3.13.2', '<' ) ) {
        global $wpdb;
        $sources_table = $wpdb->prefix . 'ontario_obituaries_sources';

        // Only run if the sources table exists
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sources_table ) ) === $sources_table ) {

            // Domains confirmed broken via Rescan-Only diagnostics on 2026-02-11:
            //   - 7x legacy.com/* : HTTP 403 (WAF/bot protection)
            //   - 6x dignitymemorial.com/* : HTTP 403 (WAF/bot protection)
            //   - 1x arbormemorial.ca/taylor : HTTP 200 but React SPA, 0 cards
            $broken_domains = array(
                'legacy.com/ca/obituaries/yorkregion',
                'legacy.com/ca/obituaries/thestar',
                'legacy.com/ca/obituaries/thespec',
                'legacy.com/ca/obituaries/ottawacitizen',
                'legacy.com/ca/obituaries/lfpress',
                'legacy.com/ca/obituaries/therecord',
                'legacy.com/ca/obituaries/barrieexaminer',
                'legacy.com/ca/obituaries/windsorstar',
                'dignitymemorial.com/newmarket-on',
                'dignitymemorial.com/aurora-on',
                'dignitymemorial.com/richmond-hill-on',
                'dignitymemorial.com/markham-on',
                'dignitymemorial.com/vaughan-on',
                'dignitymemorial.com/toronto-on',
                'arbormemorial.ca/taylor',
            );

            $disabled_count = 0;
            foreach ( $broken_domains as $domain ) {
                $updated = $wpdb->query( $wpdb->prepare(
                    "UPDATE `{$sources_table}` SET enabled = 0 WHERE domain = %s AND enabled = 1",
                    $domain
                ) );
                if ( $updated > 0 ) {
                    $disabled_count++;
                }
            }

            if ( $disabled_count > 0 ) {
                ontario_obituaries_log(
                    sprintf(
                        'v3.13.2: Disabled %d broken sources (HTTP 403 / React SPA). '
                        . 'Domains: %s. Re-enable via WP Admin > Ontario Obituaries > Source Registry '
                        . 'after verifying the source returns parseable HTML.',
                        $disabled_count,
                        implode( ', ', $broken_domains )
                    ),
                    'info'
                );
            } else {
                ontario_obituaries_log( 'v3.13.2: All broken sources were already disabled. No changes needed.', 'info' );
            }

            // Log post-update registry state for verification.
            if ( class_exists( 'Ontario_Obituaries_Source_Registry' ) ) {
                $post_stats = Ontario_Obituaries_Source_Registry::get_stats();
                ontario_obituaries_log(
                    sprintf(
                        'v3.13.2: Source registry state: %d total, %d active, %d disabled, %d circuit-broken.',
                        intval( $post_stats['total'] ),
                        intval( $post_stats['enabled'] ),
                        intval( $post_stats['disabled'] ),
                        intval( $post_stats['circuit_open'] )
                    ),
                    'info'
                );
            }
        }
    }

    // v3.14.0: UI redesign + full obituary data retrieval.
    //
    // Changes:
    //   - Complete CSS overhaul matching Monaco Monuments brand (Inter + Cardo fonts)
    //   - Remembering.ca adapter now fetches detail pages for full obituary text
    //   - Description cap increased from 200 to 2000 chars
    //   - Image extraction from detail pages (respects image_allowlisted flag)
    //   - Birth/death date extraction from obituary text on detail pages
    //
    // Upgrade action: Schedule a background re-scrape so that existing records
    // get enriched with full descriptions and dates from detail pages. Existing
    // records won't be re-scraped automatically (they exist via provenance hash
    // dedup), but NEW records from this point forward will have full data.
    // The cross-source dedup in insert_obituary() will also enrich existing
    // records if the new scrape provides longer descriptions.
    if ( version_compare( $stored_version, '3.14.0', '<' ) ) {
        // Schedule a full background scrape to pick up the enriched data.
        if ( ! wp_next_scheduled( 'ontario_obituaries_initial_collection' ) ) {
            wp_schedule_single_event( time() + 120, 'ontario_obituaries_initial_collection' );
            ontario_obituaries_log( 'v3.14.0: Scheduled background re-scrape for full obituary data enrichment.', 'info' );
        }

        ontario_obituaries_log( 'v3.14.0: UI redesign deployed. CSS, templates, and adapter updated.', 'info' );
    }

    // v3.14.1: Detail page UI fix + data enrichment re-scrape.
    //
    // Changes:
    //   - Individual obituary detail page redesigned with hero layout,
    //     visible birth/death dates, image display, and full Monaco branding
    //   - AJAX detail modal updated with dates and improved formatting
    //   - CSS enhanced for detail page hero section and responsive design
    //
    // Upgrade action: Force a fresh background scrape so existing records
    // are enriched with full descriptions, dates, and images from detail pages.
    // The cross-source dedup will update existing records with longer descriptions.
    if ( version_compare( $stored_version, '3.14.1', '<' ) ) {
        // Schedule a full background scrape for data enrichment.
        if ( ! wp_next_scheduled( 'ontario_obituaries_initial_collection' ) ) {
            wp_schedule_single_event( time() + 60, 'ontario_obituaries_initial_collection' );
            ontario_obituaries_log( 'v3.14.1: Scheduled background re-scrape for data enrichment (full descriptions, dates, images).', 'info' );
        }

        ontario_obituaries_log( 'v3.14.1: Detail page UI redesign deployed. Individual, modal, and CSS updated.', 'info' );
    }

    // v3.15.0: Forced enrichment sweep for existing records.
    //
    // ROOT CAUSE: v3.14.1 added image extraction from listing pages and dedup
    // enrichment for image_url/date_of_birth/age. However, the dedup enrichment
    // in insert_obituary() only fires when a NEW scrape produces records whose
    // death dates match existing records. This worked for some records but missed
    // others due to:
    //   1. Timing: the rescan might have run before v3.14.1 was merged
    //   2. Name normalization: fuzzy dedup might not match all variations
    //   3. No year_death extraction from content paragraph (only from dates div)
    //
    // FIX: On deploy, run a synchronous single-page scrape. For each card that
    // produces an image_url, directly UPDATE matching records in the database.
    // This bypasses dedup entirely — we match on normalized name + approximate
    // death date window and write the image URL directly.
    if ( version_compare( $stored_version, '3.15.0', '<' ) ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';
        $table_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );

        if ( $table_exists && ontario_obituaries_ensure_collector_loaded()
             && class_exists( 'Ontario_Obituaries_Source_Registry' )
             && class_exists( 'Ontario_Obituaries_Adapter_Remembering_Ca' ) ) {

            ontario_obituaries_log( 'v3.15.0: Starting forced enrichment sweep for existing records.', 'info' );

            // Get the yorkregion source (the only active one)
            $sources = Ontario_Obituaries_Source_Registry::get_active_sources();
            $enriched_count = 0;

            foreach ( $sources as $source ) {
                $adapter = Ontario_Obituaries_Source_Registry::get_adapter( $source['adapter_type'] );
                if ( ! $adapter ) continue;

                $source['config_parsed'] = ! empty( $source['config'] ) ? json_decode( $source['config'], true ) : array();
                if ( ! is_array( $source['config_parsed'] ) ) {
                    $source['config_parsed'] = array();
                }

                // Fetch first page only (avoid timeout)
                $listing_urls = $adapter->discover_listing_urls( $source, 7 );
                $listing_urls = array_slice( $listing_urls, 0, 1 );

                foreach ( $listing_urls as $url ) {
                    $html = $adapter->fetch_listing( $url, $source );
                    if ( is_wp_error( $html ) || empty( $html ) ) continue;

                    $cards = $adapter->extract_obit_cards( $html, $source );

                    foreach ( $cards as $card ) {
                        $record = $adapter->normalize( $card, $source );
                        if ( empty( $record['name'] ) ) continue;

                        // Build the normalized name for matching
                        $clean_name = strtolower( trim( $record['name'] ) );
                        $clean_name = preg_replace( '/\b(mr|mrs|ms|dr|jr|sr|ii|iii|obituary)\b\.?/i', '', $clean_name );
                        $clean_name = preg_replace( '/\([^)]*\)/', '', $clean_name );
                        $clean_name = preg_replace( '/[^a-z\s]/', '', $clean_name );
                        $clean_name = preg_replace( '/\s+/', ' ', trim( $clean_name ) );

                        if ( strlen( $clean_name ) < 4 ) continue;

                        // Find matching records in the database by name LIKE pattern
                        // Use the first and last parts of the name for matching
                        $name_parts = explode( ' ', $clean_name );
                        $like_pattern = '%' . $wpdb->esc_like( $name_parts[0] ) . '%' . $wpdb->esc_like( end( $name_parts ) ) . '%';

                        $matches = $wpdb->get_results( $wpdb->prepare(
                            "SELECT id, name, image_url, date_of_birth, age FROM `{$table}`
                             WHERE LOWER(name) LIKE %s
                               AND suppressed_at IS NULL
                             LIMIT 5",
                            $like_pattern
                        ) );

                        foreach ( $matches as $match ) {
                            $update_data = array();

                            // Update image_url if empty
                            if ( ( empty( $match->image_url ) || strlen( $match->image_url ) < 10 ) && ! empty( $record['image_url'] ) ) {
                                $update_data['image_url'] = $record['image_url'];
                            }

                            // Update date_of_birth if empty
                            if ( ( empty( $match->date_of_birth ) || '0000-00-00' === $match->date_of_birth ) && ! empty( $record['date_of_birth'] ) ) {
                                $update_data['date_of_birth'] = $record['date_of_birth'];
                            }

                            // Update age if 0
                            if ( ( empty( $match->age ) || 0 === intval( $match->age ) ) && ! empty( $record['age'] ) && intval( $record['age'] ) > 0 ) {
                                $update_data['age'] = intval( $record['age'] );
                            }

                            if ( ! empty( $update_data ) ) {
                                $wpdb->update( $table, $update_data, array( 'id' => $match->id ) );
                                $enriched_count++;
                            }
                        }
                    }
                }
            }

            ontario_obituaries_log(
                sprintf( 'v3.15.0: Forced enrichment sweep complete — %d records updated with images/dates/age.', $enriched_count ),
                'info'
            );

            // Also schedule a full background scrape for remaining pages
            if ( ! wp_next_scheduled( 'ontario_obituaries_initial_collection' ) ) {
                wp_schedule_single_event( time() + 120, 'ontario_obituaries_initial_collection' );
            }

            // Purge caches
            ontario_obituaries_purge_litespeed();
            delete_transient( 'ontario_obituaries_locations_cache' );
            delete_transient( 'ontario_obituaries_funeral_homes_cache' );
        }

        ontario_obituaries_log( 'v3.15.0: Forced enrichment + year_death extraction fix deployed.', 'info' );
    }

    // v3.15.1: Comprehensive forced re-scrape + enrichment sweep.
    //
    // ROOT CAUSE OF REMAINING ISSUES:
    //   1. Images appeared zoomed: adapter extracted 200×200 thumbnails (data-src
    //      from lazy-loaded img). The listing page has 300×400 print-only images
    //      that look much better. Fix: prefer print-only img with src= attr.
    //   2. Description truncated: extract_card() only grabbed the first <p> tag.
    //      Ralph Pyle's listing has full survivor text in <p class="content"> that
    //      was being cut off. Fix: concatenate all .content paragraphs.
    //   3. SPA detail fetch wasting time: all Remembering.ca detail pages are
    //      React/Next.js SPAs returning empty HTML. The adapter now returns null
    //      immediately from fetch_detail(), saving ~25-50s per scrape cycle.
    //   4. Enrichment incomplete: v3.15.0's forced sweep only updated image_url,
    //      date_of_birth, and age. This version also updates description when the
    //      new scrape provides a longer one.
    //
    // FIX: On deploy, run a synchronous single-page scrape with the new
    // adapter logic. For each card, directly UPDATE matching records with:
    //   - image_url (now 300×400 print-only CDN image)
    //   - description (full concatenated content paragraphs)
    //   - age (from year_birth/year_death when available)
    //   - date_of_birth (if a full date is found in text)
    if ( version_compare( $stored_version, '3.15.1', '<' ) ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';
        $table_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );

        if ( $table_exists && ontario_obituaries_ensure_collector_loaded()
             && class_exists( 'Ontario_Obituaries_Source_Registry' )
             && class_exists( 'Ontario_Obituaries_Adapter_Remembering_Ca' ) ) {

            ontario_obituaries_log( 'v3.15.1: Starting comprehensive enrichment sweep (images + descriptions + dates).', 'info' );

            $sources = Ontario_Obituaries_Source_Registry::get_active_sources();
            $enriched_count = 0;

            foreach ( $sources as $source ) {
                $adapter = Ontario_Obituaries_Source_Registry::get_adapter( $source['adapter_type'] );
                if ( ! $adapter ) continue;

                $source['config_parsed'] = ! empty( $source['config'] ) ? json_decode( $source['config'], true ) : array();
                if ( ! is_array( $source['config_parsed'] ) ) {
                    $source['config_parsed'] = array();
                }

                // Fetch first page only (avoid timeout)
                $listing_urls = $adapter->discover_listing_urls( $source, 7 );
                $listing_urls = array_slice( $listing_urls, 0, 1 );

                foreach ( $listing_urls as $url ) {
                    $html = $adapter->fetch_listing( $url, $source );
                    if ( is_wp_error( $html ) || empty( $html ) ) continue;

                    $cards = $adapter->extract_obit_cards( $html, $source );

                    foreach ( $cards as $card ) {
                        $record = $adapter->normalize( $card, $source );
                        if ( empty( $record['name'] ) ) continue;

                        // Build normalized name for matching
                        $clean_name = strtolower( trim( $record['name'] ) );
                        $clean_name = preg_replace( '/\b(mr|mrs|ms|dr|jr|sr|ii|iii|obituary)\b\.?/i', '', $clean_name );
                        $clean_name = preg_replace( '/\([^)]*\)/', '', $clean_name );
                        $clean_name = preg_replace( '/[^a-z\s]/', '', $clean_name );
                        $clean_name = preg_replace( '/\s+/', ' ', trim( $clean_name ) );

                        if ( strlen( $clean_name ) < 4 ) continue;

                        // Find matching records by name LIKE pattern
                        $name_parts = explode( ' ', $clean_name );
                        $like_pattern = '%' . $wpdb->esc_like( $name_parts[0] ) . '%' . $wpdb->esc_like( end( $name_parts ) ) . '%';

                        $matches = $wpdb->get_results( $wpdb->prepare(
                            "SELECT id, name, image_url, date_of_birth, age, description FROM `{$table}`
                             WHERE LOWER(name) LIKE %s
                               AND suppressed_at IS NULL
                             LIMIT 5",
                            $like_pattern
                        ) );

                        foreach ( $matches as $match ) {
                            $update_data = array();

                            // Always update image_url (v3.15.1 extracts better 300×400 images)
                            if ( ! empty( $record['image_url'] ) && (
                                empty( $match->image_url )
                                || strlen( $match->image_url ) < 10
                                || strpos( $match->image_url, 'w%22%3A200' ) !== false  // Old 200×200 thumbnail
                                || strpos( $match->image_url, '"w":200' ) !== false
                            ) ) {
                                $update_data['image_url'] = $record['image_url'];
                            }

                            // Update description if new is longer (fuller content extraction)
                            $new_desc_len = ! empty( $record['description'] ) ? strlen( $record['description'] ) : 0;
                            $old_desc_len = ! empty( $match->description ) ? strlen( $match->description ) : 0;
                            if ( $new_desc_len > $old_desc_len && $new_desc_len > 30 ) {
                                $update_data['description'] = $record['description'];
                            }

                            // Update date_of_birth if empty
                            if ( ( empty( $match->date_of_birth ) || '0000-00-00' === $match->date_of_birth ) && ! empty( $record['date_of_birth'] ) ) {
                                $update_data['date_of_birth'] = $record['date_of_birth'];
                            }

                            // Update age if 0
                            if ( ( empty( $match->age ) || 0 === intval( $match->age ) ) && ! empty( $record['age'] ) && intval( $record['age'] ) > 0 ) {
                                $update_data['age'] = intval( $record['age'] );
                            }

                            if ( ! empty( $update_data ) ) {
                                $wpdb->update( $table, $update_data, array( 'id' => $match->id ) );
                                $enriched_count++;
                            }
                        }
                    }
                }
            }

            ontario_obituaries_log(
                sprintf( 'v3.15.1: Comprehensive enrichment complete — %d records updated (images/descriptions/dates/age).', $enriched_count ),
                'info'
            );

            // Schedule full background scrape for remaining pages
            if ( ! wp_next_scheduled( 'ontario_obituaries_initial_collection' ) ) {
                wp_schedule_single_event( time() + 120, 'ontario_obituaries_initial_collection' );
            }

            // Purge caches
            ontario_obituaries_purge_litespeed();
            delete_transient( 'ontario_obituaries_locations_cache' );
            delete_transient( 'ontario_obituaries_funeral_homes_cache' );
        }

        ontario_obituaries_log( 'v3.15.1: Better images (300x400) + full descriptions + SPA skip deployed.', 'info' );
    }

    // v3.15.3: Two-phase enrichment sweep for ALL existing records.
    //
    // TESTED on live data (2026-02-11): ~75-80% of detail pages return full
    // SSR HTML (300KB+); ~20-25% return "Proxy Handler Error" (64 bytes) for
    // expired/old obituaries. Location extraction from RSC JSON fails on all
    // pages — city data is only available from listing cards or description.
    //
    // PHASE 1 — LISTING PAGE SCRAPE:
    //   Fetch all listing pages from active sources. For each card, match to
    //   existing DB records by source_url (exact, 100% reliable). Update:
    //     - image_url (print-only 300×400 CDN image)
    //     - description (listing excerpt ~300 chars, if existing is shorter)
    //     - age (from year range in listing text or card dates)
    //     - date_of_death (from death phrases in listing text)
    //   This covers ALL visible records, including those with dead detail pages.
    //
    // PHASE 2 — DETAIL PAGE FETCH:
    //   For each record that could benefit from more data (short description,
    //   no funeral home, etc.), fetch the detail page by source_url. Extract:
    //     - Full obituary from div#obituaryDescription (1000+ chars)
    //     - Funeral home from "Funeral Arrangements by" section
    //     - High-res image from d1q40j6jx1d8h6.cloudfront.net
    //     - Death date, age, birth date from full text
    //   ~75-80% of pages will return usable data. Records with proxy errors
    //   are already covered by Phase 1 listing data.
    //
    // This two-phase approach guarantees EVERY record gets the best available
    // data — listing-level at minimum, detail-level when the page is live.
    if ( version_compare( $stored_version, '3.15.3', '<' ) ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';
        $table_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );

        if ( $table_exists && class_exists( 'Ontario_Obituaries_Adapter_Remembering_Ca' )
             && class_exists( 'Ontario_Obituaries_Source_Registry' ) ) {

            ontario_obituaries_log( 'v3.15.3: Starting two-phase enrichment for ALL records.', 'info' );

            $adapter = new Ontario_Obituaries_Adapter_Remembering_Ca();

            // ════════════════════════════════════════════════════════════
            // PHASE 1: LISTING PAGE ENRICHMENT
            // Scrape listing pages → match by source_url → update records
            // ════════════════════════════════════════════════════════════

            $sources = Ontario_Obituaries_Source_Registry::get_active_sources();
            $phase1_count = 0;

            // Build a lookup map: source_url → DB record (for fast matching)
            $all_records = $wpdb->get_results(
                "SELECT id, name, source_url, description, funeral_home, location,
                        image_url, date_of_birth, date_of_death, age, city_normalized
                 FROM `{$table}`
                 WHERE suppressed_at IS NULL
                 ORDER BY id ASC"
            );
            $url_map = array();
            foreach ( $all_records as $rec ) {
                if ( ! empty( $rec->source_url ) ) {
                    $url_map[ $rec->source_url ] = $rec;
                }
            }
            $total_records = count( $all_records );

            ontario_obituaries_log( sprintf( 'v3.15.3 Phase 1: %d records in DB, %d with source_url.', $total_records, count( $url_map ) ), 'info' );

            foreach ( $sources as $source ) {
                $src_adapter = Ontario_Obituaries_Source_Registry::get_adapter( $source['adapter_type'] );
                if ( ! $src_adapter ) continue;

                $source['config_parsed'] = ! empty( $source['config'] ) ? json_decode( $source['config'], true ) : array();
                if ( ! is_array( $source['config_parsed'] ) ) {
                    $source['config_parsed'] = array();
                }

                // Fetch ALL listing pages (not just page 1)
                $listing_urls = $src_adapter->discover_listing_urls( $source, 7 );

                foreach ( $listing_urls as $listing_url ) {
                    $html = $src_adapter->fetch_listing( $listing_url, $source );
                    if ( is_wp_error( $html ) || empty( $html ) ) continue;

                    $cards = $src_adapter->extract_obit_cards( $html, $source );

                    foreach ( $cards as $card ) {
                        // Skip cards without a detail URL (can't match)
                        if ( empty( $card['detail_url'] ) ) continue;

                        $card_url = esc_url_raw( $card['detail_url'] );

                        // Match by source_url (exact match = 100% reliable)
                        if ( ! isset( $url_map[ $card_url ] ) ) continue;
                        $db_rec = $url_map[ $card_url ];

                        // Normalize the card to get processed fields
                        $norm = $src_adapter->normalize( $card, $source );
                        if ( empty( $norm['name'] ) ) continue;

                        $update_data = array();

                        // Image: prefer listing's print-only image if DB has none or low-res
                        if ( ! empty( $norm['image_url'] ) ) {
                            $current_img = ! empty( $db_rec->image_url ) ? $db_rec->image_url : '';
                            if ( empty( $current_img ) || strlen( $current_img ) < 10 ) {
                                $update_data['image_url'] = $norm['image_url'];
                            }
                        }

                        // Description: update if listing text is longer than stored
                        if ( ! empty( $norm['description'] ) ) {
                            $new_len = strlen( $norm['description'] );
                            $old_len = ! empty( $db_rec->description ) ? strlen( $db_rec->description ) : 0;
                            if ( $new_len > $old_len && $new_len > 30 ) {
                                $update_data['description'] = $norm['description'];
                            }
                        }

                        // Age
                        if ( ( empty( $db_rec->age ) || 0 === intval( $db_rec->age ) ) && ! empty( $norm['age'] ) && intval( $norm['age'] ) > 0 ) {
                            $update_data['age'] = intval( $norm['age'] );
                        }

                        // Death date
                        if ( ! empty( $norm['date_of_death'] ) && $norm['date_of_death'] !== '0000-00-00' ) {
                            if ( empty( $db_rec->date_of_death ) || $db_rec->date_of_death === '0000-00-00' ) {
                                $update_data['date_of_death'] = $norm['date_of_death'];
                            }
                        }

                        // Birth date
                        if ( ! empty( $norm['date_of_birth'] ) && $norm['date_of_birth'] !== '0000-00-00' ) {
                            if ( empty( $db_rec->date_of_birth ) || $db_rec->date_of_birth === '0000-00-00' ) {
                                $update_data['date_of_birth'] = $norm['date_of_birth'];
                            }
                        }

                        // Location / city_normalized
                        if ( empty( $db_rec->location ) && ! empty( $norm['location'] ) ) {
                            $update_data['location'] = $norm['location'];
                        }
                        if ( empty( $db_rec->city_normalized ) && ! empty( $norm['city_normalized'] ) ) {
                            $update_data['city_normalized'] = $norm['city_normalized'];
                        }

                        if ( ! empty( $update_data ) ) {
                            $wpdb->update( $table, $update_data, array( 'id' => $db_rec->id ) );
                            $phase1_count++;

                            // Update local cache so Phase 2 sees the changes
                            foreach ( $update_data as $k => $v ) {
                                $db_rec->$k = $v;
                            }
                        }
                    }

                    // Pause between listing page fetches
                    usleep( 300000 );
                }
            }

            ontario_obituaries_log(
                sprintf( 'v3.15.3 Phase 1 complete: %d records updated from listing pages.', $phase1_count ),
                'info'
            );

            // ════════════════════════════════════════════════════════════
            // PHASE 2: DETAIL PAGE ENRICHMENT
            // For each record, fetch detail page → extract full content
            // ════════════════════════════════════════════════════════════

            $phase2_count = 0;
            $phase2_skipped = 0;

            foreach ( $all_records as $record ) {
                $detail_url = $record->source_url;
                if ( empty( $detail_url ) || strpos( $detail_url, 'http' ) !== 0 ) {
                    continue;
                }

                // Skip records that are already fully enriched
                // (has long description + funeral home + high-res image)
                $desc_len = ! empty( $record->description ) ? strlen( $record->description ) : 0;
                $has_fh   = ! empty( $record->funeral_home );
                $has_hires = ! empty( $record->image_url ) && strpos( $record->image_url, 'cloudfront.net/Obituaries' ) !== false;
                if ( $desc_len > 500 && $has_fh && $has_hires ) {
                    $phase2_skipped++;
                    continue;
                }

                $detail_data = $adapter->fetch_detail( $detail_url, array(), array( 'base_url' => $detail_url ) );

                $update_data = array();

                // ── Description ─────────────────────────────────────────
                if ( ! empty( $detail_data['detail_full_description'] ) ) {
                    $new_len = strlen( $detail_data['detail_full_description'] );
                    $old_len = ! empty( $record->description ) ? strlen( $record->description ) : 0;
                    if ( $new_len > $old_len && $new_len > 50 ) {
                        $update_data['description'] = $detail_data['detail_full_description'];
                    }
                }

                // ── Funeral home ────────────────────────────────────────
                if ( empty( $record->funeral_home ) && ! empty( $detail_data['detail_funeral_home'] ) ) {
                    $update_data['funeral_home'] = sanitize_text_field( $detail_data['detail_funeral_home'] );
                }

                // ── Image URL ───────────────────────────────────────────
                if ( ! empty( $detail_data['detail_image_url'] ) ) {
                    $current_img = ! empty( $record->image_url ) ? $record->image_url : '';
                    if ( empty( $current_img )
                         || strlen( $current_img ) < 10
                         || strpos( $current_img, 'cloudfront.net/Obituaries' ) === false ) {
                        $update_data['image_url'] = esc_url_raw( $detail_data['detail_image_url'] );
                    }
                }

                // ── Location ────────────────────────────────────────────
                if ( empty( $record->location ) && ! empty( $detail_data['detail_location'] ) ) {
                    $update_data['location'] = sanitize_text_field( $detail_data['detail_location'] );
                    if ( empty( $record->city_normalized ) ) {
                        $update_data['city_normalized'] = sanitize_text_field( $detail_data['detail_location'] );
                    }
                }

                // ── Death date ──────────────────────────────────────────
                if ( ! empty( $detail_data['detail_death_date'] ) ) {
                    $parsed_death = date( 'Y-m-d', strtotime( trim( $detail_data['detail_death_date'] ) ) );
                    if ( $parsed_death && '1970-01-01' !== $parsed_death ) {
                        if ( empty( $record->date_of_death ) || $record->date_of_death === '0000-00-00' ) {
                            $update_data['date_of_death'] = $parsed_death;
                        }
                    }
                }
                // Fallback: extract death date from best available description
                if ( empty( $update_data['date_of_death'] ) && ( empty( $record->date_of_death ) || $record->date_of_death === '0000-00-00' ) ) {
                    $text_for_death = isset( $update_data['description'] ) ? $update_data['description'] : ( ! empty( $record->description ) ? $record->description : '' );
                    if ( ! empty( $text_for_death ) ) {
                        $month_pat = '(?:January|February|March|April|May|June|July|August|September|October|November|December)';
                        $dp_list = array(
                            '/(?:passed\s+away|passed\s+peacefully|passed\s+suddenly|peacefully\s+passed)(?:\s+\w+){0,5}?\s+(' . $month_pat . '\s+\d{1,2},?\s+\d{4})/iu',
                            '/(?:peaceful\s+)?passing\s+of(?:\s+\w+){0,10}?(?:\s+on|\s+at)(?:\s+\w+){0,3}?\s+(' . $month_pat . '\s+\d{1,2},?\s+\d{4})/iu',
                            '/left\s+us\s+(?:peacefully\s+)?(?:on\s+)?(' . $month_pat . '\s+\d{1,2},?\s+\d{4})/iu',
                            '/entered\s+into\s+rest(?:\s+on)?\s+(' . $month_pat . '\s+\d{1,2},?\s+\d{4})/iu',
                            '/died(?:\s+\w+){0,8}?\s+(?:on\s+)?(' . $month_pat . '\s+\d{1,2},?\s+\d{4})/iu',
                            '/(?:went\s+to\s+be\s+with|called\s+home)(?:\s+\w+){0,4}?\s+(?:on\s+)?(' . $month_pat . '\s+\d{1,2},?\s+\d{4})/iu',
                            '/on\s+\w+,?\s+(' . $month_pat . '\s+\d{1,2},?\s+\d{4}),?\s+in\s+(?:his|her)/iu',
                            '/[Oo]n\s+(' . $month_pat . '\s+\d{1,2},?\s+\d{4})\s+(?:\w+\s+){0,3}(?:passed\s+away|passed|died)/iu',
                        );
                        foreach ( $dp_list as $dp ) {
                            if ( preg_match( $dp, $text_for_death, $dm ) ) {
                                $fd = date( 'Y-m-d', strtotime( trim( $dm[1] ) ) );
                                if ( $fd && '1970-01-01' !== $fd ) {
                                    $update_data['date_of_death'] = $fd;
                                    break;
                                }
                            }
                        }
                    }
                }

                // ── Age ─────────────────────────────────────────────────
                if ( empty( $record->age ) || 0 === intval( $record->age ) ) {
                    if ( ! empty( $detail_data['detail_age'] ) && intval( $detail_data['detail_age'] ) > 0 ) {
                        $update_data['age'] = intval( $detail_data['detail_age'] );
                    } else {
                        $text_for_age = isset( $update_data['description'] ) ? $update_data['description'] : ( ! empty( $record->description ) ? $record->description : '' );
                        if ( ! empty( $text_for_age ) ) {
                            if ( preg_match( '/in\s+(?:his|her)\s+(\d+)(?:st|nd|rd|th)\s+year/i', $text_for_age, $am ) ) {
                                $update_data['age'] = intval( $am[1] );
                            } elseif ( preg_match( '/\bage(?:d)?\s+(\d+)/i', $text_for_age, $am2 ) ) {
                                $update_data['age'] = intval( $am2[1] );
                            } elseif ( preg_match( '/at\s+the\s+age\s+of\s+(\d+)/i', $text_for_age, $am3 ) ) {
                                $update_data['age'] = intval( $am3[1] );
                            }
                        }
                        // Year range fallback
                        if ( empty( $update_data['age'] ) ) {
                            if ( ! empty( $detail_data['detail_year_birth'] ) && ! empty( $detail_data['detail_year_death'] ) ) {
                                $yr_age = intval( $detail_data['detail_year_death'] ) - intval( $detail_data['detail_year_birth'] );
                                if ( $yr_age > 0 && $yr_age <= 130 ) {
                                    $update_data['age'] = $yr_age;
                                }
                            }
                            if ( empty( $update_data['age'] ) && ! empty( $text_for_age ) ) {
                                if ( preg_match( '/\b(\d{4})\s*[-\x{2013}\x{2014}~]\s*(\d{4})\b/u', $text_for_age, $yr_m ) ) {
                                    $yb = intval( $yr_m[1] );
                                    $yd = intval( $yr_m[2] );
                                    if ( $yd > $yb && $yd >= 2020 && $yd <= 2030 && $yb >= 1890 ) {
                                        $update_data['age'] = $yd - $yb;
                                    }
                                }
                            }
                        }
                    }
                }

                // ── Birth date ──────────────────────────────────────────
                if ( ( empty( $record->date_of_birth ) || '0000-00-00' === $record->date_of_birth ) && ! empty( $detail_data['detail_birth_date'] ) ) {
                    $pb = date( 'Y-m-d', strtotime( trim( $detail_data['detail_birth_date'] ) ) );
                    if ( $pb && '1970-01-01' !== $pb ) {
                        $update_data['date_of_birth'] = $pb;
                    }
                }

                if ( ! empty( $update_data ) ) {
                    $wpdb->update( $table, $update_data, array( 'id' => $record->id ) );
                    $phase2_count++;
                }

                // Rate limit: 0.5s between detail page fetches
                usleep( 500000 );
            }

            ontario_obituaries_log(
                sprintf( 'v3.15.3 Phase 2 complete: %d records updated from detail pages (%d skipped as already enriched).', $phase2_count, $phase2_skipped ),
                'info'
            );

            ontario_obituaries_log(
                sprintf( 'v3.15.3: Total enrichment — Phase 1 (listing): %d, Phase 2 (detail): %d of %d records.', $phase1_count, $phase2_count, $total_records ),
                'info'
            );

            // Schedule a full background scrape to catch multi-page records
            if ( ! wp_next_scheduled( 'ontario_obituaries_initial_collection' ) ) {
                wp_schedule_single_event( time() + 120, 'ontario_obituaries_initial_collection' );
            }

            // Purge caches
            ontario_obituaries_purge_litespeed();
            delete_transient( 'ontario_obituaries_locations_cache' );
            delete_transient( 'ontario_obituaries_funeral_homes_cache' );
        }

        ontario_obituaries_log( 'v3.15.3: Two-phase enrichment (listing + detail) deployed.', 'info' );
    }

    // v3.16.0: Fix WP-Cron reliability on LiteSpeed-cached sites.
    //
    // ROOT CAUSE (discovered 2026-02-11):
    //   WP-Cron only fires when a PHP page is loaded. LiteSpeed Cache
    //   serves cached HTML for most requests, bypassing PHP entirely.
    //   This means wp_schedule_event('twicedaily') NEVER fires because
    //   no PHP execution occurs between scheduled runs.
    //
    //   Result: the scraper ran ONCE (on the v3.15.3 deploy) and never
    //   again. The database has been stuck at 124 records since the
    //   initial scrape. New obituaries posted to yorkregion.com (e.g.,
    //   Douglas Rush on Feb 11, 2026) never appear on the site.
    //
    // FIX:
    //   1. Added ontario_obituaries_maybe_spawn_cron() — a lightweight
    //      heartbeat on every uncached PHP load that checks data freshness
    //      and spawns WP-Cron if stale. If very stale (>2× interval),
    //      runs a synchronous first-page scrape as a safety net.
    //
    //   2. Added REST API endpoint /wp-json/ontario-obituaries/v1/cron
    //      for external server-side cron. Add to crontab:
    //        */15 * * * * curl -s https://monacomonuments.ca/wp-json/ontario-obituaries/v1/cron
    //
    //   3. Run an immediate synchronous scrape on this deploy to catch
    //      new obituaries posted since the last (only) scrape.
    //
    //   4. Re-ensure the recurring cron event is scheduled.
    if ( version_compare( $stored_version, '3.16.0', '<' ) ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';
        $table_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );

        // Run an immediate full scrape to catch new obituaries.
        // v3.16.1: Use ensure_collector_loaded() to handle frontend page loads
        // where the Collector isn't pre-loaded (P2-1 gate: admin/cron/AJAX only).
        if ( $table_exists && ontario_obituaries_ensure_collector_loaded() ) {
            ontario_obituaries_log( 'v3.16.0: Running immediate scrape to catch new obituaries (cron was broken).', 'info' );

            $collector    = new Ontario_Obituaries_Source_Collector();
            $sync_results = $collector->collect();

            update_option( 'ontario_obituaries_last_collection', array(
                'timestamp' => current_time( 'mysql' ),
                'completed' => current_time( 'mysql' ),
                'results'   => $sync_results,
            ) );

            ontario_obituaries_log(
                sprintf(
                    'v3.16.0: Immediate scrape complete — %d found, %d added.',
                    isset( $sync_results['obituaries_found'] ) ? intval( $sync_results['obituaries_found'] ) : 0,
                    isset( $sync_results['obituaries_added'] ) ? intval( $sync_results['obituaries_added'] ) : 0
                ),
                'info'
            );

            // Purge all caches so new data appears.
            ontario_obituaries_purge_litespeed();
            delete_transient( 'ontario_obituaries_locations_cache' );
            delete_transient( 'ontario_obituaries_funeral_homes_cache' );
        }

        // Re-ensure recurring cron is scheduled.
        if ( ! wp_next_scheduled( 'ontario_obituaries_collection_event' ) ) {
            $settings  = ontario_obituaries_get_settings();
            $frequency = isset( $settings['frequency'] ) ? $settings['frequency'] : 'twicedaily';
            $time      = isset( $settings['time'] ) ? $settings['time'] : '03:00';
            wp_schedule_event(
                ontario_obituaries_next_cron_timestamp( $time ),
                $frequency,
                'ontario_obituaries_collection_event'
            );
            ontario_obituaries_log( 'v3.16.0: Re-scheduled recurring collection cron.', 'info' );
        }

        // Clear stale spawn transient so heartbeat runs immediately.
        delete_transient( 'ontario_obituaries_last_cron_spawn' );

        ontario_obituaries_log( 'v3.16.0: WP-Cron reliability fix deployed. Heartbeat + REST cron endpoint active.', 'info' );
    }

    // ── v3.16.1: Fix collector class loading for REST/frontend contexts ──
    //
    // BUG (discovered in v3.16.0 code review):
    //   The Source Collector and all adapters were only loaded in admin/cron/AJAX
    //   contexts (P2-1 gate in ontario_obituaries_includes()). The three cron
    //   reliability features added in v3.16.0 all run OUTSIDE these contexts:
    //     1. REST API /cron endpoint  → REST_REQUEST, not admin/cron/AJAX
    //     2. Frontend heartbeat sync scrape → frontend init, not admin/cron/AJAX
    //     3. Deploy migration → init priority 1, on whichever context triggers first
    //
    //   class_exists('Ontario_Obituaries_Source_Collector') always returned false,
    //   so none of the v3.16.0 scrape paths would actually execute.
    //
    // FIX:
    //   1. Extracted collector loading into ontario_obituaries_load_collector_classes()
    //   2. Added ontario_obituaries_ensure_collector_loaded() on-demand loader
    //   3. All three scrape paths now call ensure_collector_loaded() instead of
    //      class_exists() checks
    //   4. REST_REQUEST constant added to the includes() pre-load condition
    //   5. Added /wp-json/ontario-obituaries/v1/status health endpoint
    if ( version_compare( $stored_version, '3.16.1', '<' ) ) {
        ontario_obituaries_log( 'v3.16.1: Fixed collector class loading for REST/frontend contexts. Status endpoint added.', 'info' );

        // If v3.16.0 migration ran but couldn't scrape (collector wasn't loaded),
        // run the scrape now with the fix in place.
        $last = get_option( 'ontario_obituaries_last_collection', array() );
        $last_ts = ! empty( $last['completed'] ) ? strtotime( $last['completed'] ) : 0;
        $hours_since = ( time() - $last_ts ) / 3600;

        if ( $hours_since > 12 && ontario_obituaries_ensure_collector_loaded() ) {
            ontario_obituaries_log( 'v3.16.1: Data is stale (' . round( $hours_since ) . 'h) — running collection with fixed loader.', 'info' );

            $collector    = new Ontario_Obituaries_Source_Collector();
            $sync_results = $collector->collect();

            update_option( 'ontario_obituaries_last_collection', array(
                'timestamp' => current_time( 'mysql' ),
                'completed' => current_time( 'mysql' ),
                'results'   => $sync_results,
            ) );

            ontario_obituaries_log(
                sprintf(
                    'v3.16.1: Collection complete — %d found, %d added.',
                    isset( $sync_results['obituaries_found'] ) ? intval( $sync_results['obituaries_found'] ) : 0,
                    isset( $sync_results['obituaries_added'] ) ? intval( $sync_results['obituaries_added'] ) : 0
                ),
                'info'
            );

            ontario_obituaries_purge_litespeed();
            delete_transient( 'ontario_obituaries_locations_cache' );
            delete_transient( 'ontario_obituaries_funeral_homes_cache' );
        }

        // Re-ensure recurring cron is scheduled.
        if ( ! wp_next_scheduled( 'ontario_obituaries_collection_event' ) ) {
            $settings  = ontario_obituaries_get_settings();
            $frequency = isset( $settings['frequency'] ) ? $settings['frequency'] : 'twicedaily';
            $time      = isset( $settings['time'] ) ? $settings['time'] : '03:00';
            wp_schedule_event(
                ontario_obituaries_next_cron_timestamp( $time ),
                $frequency,
                'ontario_obituaries_collection_event'
            );
            ontario_obituaries_log( 'v3.16.1: Re-scheduled recurring collection cron.', 'info' );
        }
    }

    // v4.0.0: Expand source coverage — 6 new Postmedia/Remembering.ca sources.
    //
    // New sources (all use existing remembering_ca adapter — zero code changes):
    //   - obituaries.thestar.com (Toronto Star)
    //   - obituaries.therecord.com (Kitchener-Waterloo Record)
    //   - obituaries.thespec.com (Hamilton Spectator)
    //   - obituaries.simcoe.com (Barrie / Simcoe County)
    //   - obituaries.niagarafallsreview.ca (Niagara Falls Review)
    //   - obituaries.stcatharinesstandard.ca (St. Catharines Standard)
    //
    // All confirmed reachable (HTTP 200) and parseable on 2026-02-13.
    // Same Postmedia HTML structure as obituaries.yorkregion.com.
    //
    // Upgrade action: Re-seed source registry to add new sources.
    // upsert_source() is idempotent — existing sources updated, new sources inserted.
    // New sources default to enabled=1 (no explicit 'enabled' => 0 in seed data).
    if ( version_compare( $stored_version, '4.0.0', '<' ) ) {
        if ( class_exists( 'Ontario_Obituaries_Source_Registry' ) ) {
            Ontario_Obituaries_Source_Registry::seed_defaults();

            $stats = Ontario_Obituaries_Source_Registry::get_stats();
            ontario_obituaries_log(
                sprintf(
                    'v4.0.0: Source registry re-seeded with 6 new Postmedia sources. '
                    . 'Registry state: %d total, %d active, %d disabled.',
                    intval( $stats['total'] ),
                    intval( $stats['enabled'] ),
                    intval( $stats['disabled'] )
                ),
                'info'
            );
        }

        // Schedule a background scrape so new sources are collected promptly.
        if ( ! wp_next_scheduled( 'ontario_obituaries_initial_collection' ) ) {
            wp_schedule_single_event( time() + 60, 'ontario_obituaries_initial_collection' );
            ontario_obituaries_log( 'v4.0.0: Scheduled initial collection for new sources (60s delay).', 'info' );
        }
    }

    // v4.0.1: Clean funeral home logos from existing obituary image_url fields.
    //
    // Problem: The adapter was scraping funeral home logos (Ogden, Ridley, etc.)
    // as obituary photos. These are copyrighted business logos, not portraits.
    // Logos on the Cloudfront CDN are typically < 15 KB; real portraits are 20 KB+.
    //
    // Fix: Clear image_url for any existing records where the image is under 15 KB.
    // Future scrapes use the new is_likely_portrait() filter in the adapter.
    if ( version_compare( $stored_version, '4.0.1', '<' ) ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        // Find all cloudfront CDN images and check their sizes.
        $rows = $wpdb->get_results(
            "SELECT id, image_url FROM {$table} WHERE image_url LIKE '%cloudfront.net/Obituaries%' AND image_url != ''",
            ARRAY_A
        );

        $cleaned = 0;
        if ( $rows ) {
            foreach ( $rows as $row ) {
                $head = wp_remote_head( $row['image_url'], array(
                    'timeout'    => 5,
                    'user-agent' => 'Mozilla/5.0 (compatible; OntarioObituaries/4.0)',
                ) );

                if ( is_wp_error( $head ) ) {
                    continue;
                }

                $content_length = wp_remote_retrieve_header( $head, 'content-length' );
                if ( ! empty( $content_length ) && intval( $content_length ) < 15360 ) {
                    $wpdb->update(
                        $table,
                        array( 'image_url' => '' ),
                        array( 'id' => intval( $row['id'] ) ),
                        array( '%s' ),
                        array( '%d' )
                    );
                    $cleaned++;
                }
            }
        }

        if ( $cleaned > 0 ) {
            ontario_obituaries_log(
                sprintf( 'v4.0.1: Cleared %d funeral home logo images from obituary records.', $cleaned ),
                'info'
            );
            ontario_obituaries_purge_litespeed( 'ontario_obits' );
        }

        ontario_obituaries_log( 'v4.0.1: Logo image filter deployed — future scrapes will skip images < 15 KB.', 'info' );
    }

    // v4.1.0: Add ai_description column for AI-rewritten obituary text.
    if ( version_compare( $stored_version, '4.1.0', '<' ) ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        // Check if column already exists before adding.
        $existing_cols = $wpdb->get_col( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
            $table
        ) );

        if ( ! in_array( 'ai_description', $existing_cols, true ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN ai_description text DEFAULT NULL AFTER description" );
            ontario_obituaries_log( 'v4.1.0: Added ai_description column to obituaries table.', 'info' );
        }

        ontario_obituaries_log( 'v4.1.0: AI Rewriter infrastructure deployed. Set Groq API key to activate.', 'info' );
    }

    // v4.2.2: Repair city_normalized data quality issues.
    //
    // Problem: Some source adapters stored full street addresses, truncated city
    // names, garbled/encoded strings, out-of-province cities, biographical text,
    // or facility names in the city_normalized column. This produces bad sitemap
    // URLs and broken city hub pages.
    //
    // Fix: A multi-pass repair that:
    //   1) Maps known truncated names to their full form (hamilt → Hamilton, etc.)
    //   2) Maps known typos (kitchner → Kitchener, stoiuffville → Stouffville)
    //   3) Extracts the actual city from street addresses (e.g. "King Street East, Hamilton" → Hamilton)
    //   4) Clears garbled/encoded values (q2l0eq, mself-__next_f, etc.)
    //   5) Clears biographical text stored as city (e.g. "Jan was born in Toronto")
    //   6) Clears facility/institution names stored as city
    //   7) Flags out-of-province records but keeps them (they are valid obituaries)
    if ( version_compare( $stored_version, '4.2.3', '<' ) ) {
        global $wpdb;
        $table   = $wpdb->prefix . 'ontario_obituaries';
        $repaired = 0;

        // ── Pass 1: Direct replacements for truncated / misspelled city names ──
        $direct_fixes = array(
            'Hamilt'        => 'Hamilton',
            'Burlingt'      => 'Burlington',
            'Sutt'          => 'Sutton',
            'Lond'          => 'London',
            'Milt'          => 'Milton',
            'Tivert'        => 'Tiverton',
            'Harrist'       => 'Harriston',
            'Frederict'     => 'Fredericton',
            'Kingst'        => 'Kingston',
            'Leamingt'      => 'Leamington',
            'Beet'          => 'Beeton',
            'Dalton Road, Sutt' => 'Sutton',
            'Autlan'        => 'Aurora', // likely mis-parsed
            'Allist'        => 'Alliston',
            'Catharines'    => 'St. Catharines',
            'Kitchner'      => 'Kitchener',
            'Stoiuffville'  => 'Stouffville',
        );

        foreach ( $direct_fixes as $bad => $good ) {
            $updated = $wpdb->query( $wpdb->prepare(
                "UPDATE `{$table}` SET city_normalized = %s WHERE city_normalized = %s",
                $good,
                $bad
            ) );
            if ( $updated > 0 ) {
                $repaired += $updated;
            }
        }

        // ── Pass 2: Extract city from street addresses ──
        // Pattern: "Street Name, City" or "Street Name City" with known city at end.
        $address_to_city = array(
            '%Street%Hamilton'     => 'Hamilton',
            '%Street%Kitchener'    => 'Kitchener',
            '%Street%Dundas'       => 'Dundas',
            '%Street%Welland'      => 'Welland',
            '%Street%Waterloo'     => 'Waterloo',
            '%Street%Cambridge'    => 'Cambridge',
            '%Street%Elmira'       => 'Elmira',
            '%Avenue%Toronto'      => 'Toronto',
            '%Avenue%Hamilton'     => 'Hamilton',
            '%Avenue%Markham'      => 'Markham',
            '%Drive%Toronto'       => 'Toronto',
            '%Drive%Burlington'    => 'Burlington',
            '%Drive%Elmira'        => 'Elmira',
            '%Drive%Stouffville'   => 'Stouffville',
            '%Drive%Georgina'      => 'Georgina',
            '%Parkway%Newmarket'   => 'Newmarket',
            '%Road%Niagara Falls'  => 'Niagara Falls',
            '%Road%Mount Hope'     => 'Mount Hope',
            '%Road%Bradford'       => 'Bradford',
            '%Road%Georgina'       => 'Georgina',
            '%Gage%Hamilton'       => 'Hamilton',
            '%Guelph Line%'        => 'Burlington',
            '%Wellington%Hamilton' => 'Hamilton',
            '%Industrial Parkway%Aurora'  => 'Aurora',
            '%Hurontario%Mississauga'     => 'Mississauga',
            '%King St%Midland'     => 'Midland',
            '%Fennell%Hamilton'    => 'Hamilton',
            '%Ottawa Street%Hamilton' => 'Hamilton',
            '%Rymal%Hamilton'      => 'Hamilton',
            '%Queen St%Lakefield'  => 'Lakefield',
            '%Reach St%Port Perry' => 'Port Perry',
            '%Botanical%Burlington'=> 'Burlington',
            '%Brant St%Burlington' => 'Burlington',
            '%Hatt Street%Dundas'  => 'Dundas',
            '%Pine Street%Collingwood' => 'Collingwood',
            '%Maple Street%Niagara Falls' => 'Niagara Falls',
            '%Drummond Road%Niagara Falls' => 'Niagara Falls',
            '%East Main%Welland'   => 'Welland',
            '%West Main%Welland'   => 'Welland',
            '%Main Street%Dundas'  => 'Dundas',
            '%Main Street%Niagara Falls' => 'Niagara Falls',
            '%Main Street%Markham' => 'Markham',
            '%Main Street%Mount Forest'  => 'Mount Forest',
            '%Main Street%Newmarket'     => 'Newmarket',
            '%Park Road%Brantford' => 'Brantford',
            '%Connor Drive%Toronto'=> 'Toronto',
            '%Underhill%Toronto'   => 'Toronto',
            '%Kipling%Etobicoke'   => 'Toronto',
            '%Woodbine%Markham'    => 'Markham',
            '%St Andrews%Cambridge'=> 'Cambridge',
            '%Arthur Street%Elmira'=> 'Elmira',
            '%Barnswallow%Elmira'  => 'Elmira',
            '%Church St%Saint Catharines' => 'St. Catharines',
            '%Dalton%Georgina'     => 'Georgina',
            '%King Street%Hamilt'  => 'Hamilton',
            '%Ottawa Street%Hamilt'=> 'Hamilton',
            '%Gage Ave%Hamilt'     => 'Hamilton',
            '%Gage Avenue%Hamilt'  => 'Hamilton',
            '%Wellington%Hamilt'   => 'Hamilton',
            '%Fennell%Hamilt'      => 'Hamilton',
            '%Rymal%Hamilt'        => 'Hamilton',
            // v4.2.3: Additional patterns found after first deploy
            '%Botanical%Burlingt%' => 'Burlington',
            '%Brant St%Burlingt%'  => 'Burlington',
            '%Brant-St%Burlingt%'  => 'Burlington',
            'Brant-St-Burlington'  => 'Burlington',
            'Botanical-Drive-Burlington' => 'Burlington',
            '%Clarence%Port Colborne'    => 'Port Colborne',
            '%Clarence%Port-Colborne'    => 'Port Colborne',
            '%Dalton Road%Sutton'  => 'Sutton',
            'Dalton-Road-Sutton'   => 'Sutton',
            '%East Street%Sutton'  => 'Sutton',
            'East-Street-Sutton'   => 'Sutton',
            '%Mostar%Stouffville'  => 'Stouffville',
            'Mostar-Street-Stouffville' => 'Stouffville',
            '%Russell%Leamington'  => 'Leamington',
            'Russell-Street-Leamington' => 'Leamington',
            '%Simcoe Rd%Bradford'  => 'Bradford',
            'Simcoe-Rd-Bradford'   => 'Bradford',
        );

        foreach ( $address_to_city as $pattern => $city ) {
            $updated = $wpdb->query( $wpdb->prepare(
                "UPDATE `{$table}` SET city_normalized = %s WHERE city_normalized LIKE %s AND city_normalized != %s",
                $city,
                $pattern,
                $city
            ) );
            if ( $updated > 0 ) {
                $repaired += $updated;
            }
        }

        // ── Pass 3: Clear garbled / encoded / biographical values ──
        // These are base64 artifacts, JS injection, or sentences stored as city names.
        $garbage_patterns = array(
            'q2l0eq',
            'mself-%',
            '%__next_f%',
            '%was born in%',
            '%settled in%',
            '%Midtown in%',
        );

        foreach ( $garbage_patterns as $pattern ) {
            // Try to extract a real city name from the garbage, otherwise clear it.
            if ( strpos( $pattern, 'born in' ) !== false || strpos( $pattern, 'settled in' ) !== false || strpos( $pattern, 'Midtown in' ) !== false ) {
                // Extract city from biographical text: "Jan was born in Toronto" → "Toronto"
                $bio_rows = $wpdb->get_results(
                    $wpdb->prepare( "SELECT id, city_normalized FROM `{$table}` WHERE city_normalized LIKE %s", $pattern )
                );
                foreach ( (array) $bio_rows as $bio_row ) {
                    $extracted = '';
                    if ( preg_match( '/(?:born in|settled in|Midtown in)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)/i', $bio_row->city_normalized, $m ) ) {
                        $extracted = ucwords( strtolower( trim( $m[1] ) ) );
                    }
                    $wpdb->update( $table, array( 'city_normalized' => $extracted ), array( 'id' => $bio_row->id ) );
                    $repaired++;
                }
            } else {
                $updated = $wpdb->query(
                    $wpdb->prepare( "UPDATE `{$table}` SET city_normalized = '' WHERE city_normalized LIKE %s", $pattern )
                );
                if ( $updated > 0 ) {
                    $repaired += $updated;
                }
            }
        }

        // ── Pass 4: Clear facility / institution names stored as city ──
        $facility_patterns = array(
            'Sunrise of %',
            'Sunrise Of %',
            'St. Joseph%Health%',
            'St Joseph%Health%',
            'St Josephs Health%',
            'The Villages',
            'China Grove',
        );

        foreach ( $facility_patterns as $pattern ) {
            $updated = $wpdb->query(
                $wpdb->prepare( "UPDATE `{$table}` SET city_normalized = '' WHERE city_normalized LIKE %s", $pattern )
            );
            if ( $updated > 0 ) {
                $repaired += $updated;
            }
        }

        // ── Pass 5: General address cleanup ──
        // Any city_normalized that contains "Street", "Avenue", "Drive", "Road",
        // "Parkway", "Boulevard" etc. and wasn't caught above is likely an address.
        // Extract the last word(s) as the city, or clear if ambiguous.
        $address_rows = $wpdb->get_results(
            "SELECT id, city_normalized FROM `{$table}`
             WHERE (
                city_normalized LIKE '% Street%'
                OR city_normalized LIKE '% Avenue%'
                OR city_normalized LIKE '% Drive%'
                OR city_normalized LIKE '% Road%'
                OR city_normalized LIKE '% Parkway%'
                OR city_normalized LIKE '% Boulevard%'
                OR city_normalized LIKE '% Line%'
                OR city_normalized LIKE '% Rd %'
                OR city_normalized LIKE '% St %'
                OR city_normalized LIKE '% Ave %'
                OR city_normalized LIKE '% Dr %'
             )
             AND suppressed_at IS NULL"
        );

        if ( ! empty( $address_rows ) ) {
            foreach ( $address_rows as $addr_row ) {
                // Try to extract city after the last comma.
                $parts = explode( ',', $addr_row->city_normalized );
                if ( count( $parts ) > 1 ) {
                    $candidate = ucwords( strtolower( trim( end( $parts ) ) ) );
                    // Remove province suffixes.
                    $candidate = preg_replace( '/\s*(On|Ontario|Canada)\s*$/i', '', $candidate );
                    $candidate = trim( $candidate );
                    if ( strlen( $candidate ) >= 3 && strlen( $candidate ) <= 30 ) {
                        $wpdb->update( $table, array( 'city_normalized' => $candidate ), array( 'id' => $addr_row->id ) );
                        $repaired++;
                        continue;
                    }
                }
                // If no comma, clear the field — better no city than a bad address slug.
                $wpdb->update( $table, array( 'city_normalized' => '' ), array( 'id' => $addr_row->id ) );
                $repaired++;
            }
        }

        if ( $repaired > 0 ) {
            ontario_obituaries_log(
                sprintf( 'v4.2.3: Repaired city_normalized for %d obituary records (truncated names, addresses, garbled data).', $repaired ),
                'info'
            );
            ontario_obituaries_purge_litespeed( 'ontario_obits' );
        }

        ontario_obituaries_log( 'v4.2.3: City data quality repair migration complete.', 'info' );
    }

    // ── v4.2.4: Death date cross-validation repair ──
    // Fix obituaries where the structured date (from Remembering.ca metadata) is wrong.
    // The source platform sometimes updates the death year to the current year when
    // republishing/redisplaying older obituaries (e.g., "1950-2026" metadata but the
    // obituary text says "passed away on April 9, 2025").
    //
    // This migration compares date_of_death against dates found in the description
    // text using death-keyword phrases. If they disagree on the year, the text wins.
    if ( version_compare( $stored_version, '4.2.4', '<' ) ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';
        $date_repairs = 0;

        // Get all obituaries that have both a death date and a description
        $rows = $wpdb->get_results(
            "SELECT id, name, date_of_death, description FROM {$table} WHERE date_of_death != '' AND description != '' AND description IS NOT NULL"
        );

        $month_pattern = '(?:January|February|March|April|May|June|July|August|September|October|November|December)';
        $death_phrases = array(
            '/(?:passed\s+away|passed\s+peacefully|passed\s+suddenly|peacefully\s+passed)(?:\s+\w+){0,8}?\s+(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/iu',
            '/(?:peaceful\s+)?passing\s+of(?:\s+\w+){0,10}?(?:\s+on|\s+at)(?:\s+\w+){0,3}?\s+(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/iu',
            '/entered\s+into\s+rest(?:\s+on)?\s+(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/iu',
            '/died(?:\s+\w+){0,8}?\s+(?:on\s+)?(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/iu',
            '/(?:went\s+to\s+be\s+with|called\s+home)(?:\s+\w+){0,4}?\s+(?:on\s+)?(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/iu',
            '/left\s+us\s+(?:peacefully\s+)?(?:on\s+)?(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/iu',
            '/[Oo]n\s+(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})\s+(?:\w+\s+){0,3}(?:passed\s+away|passed|died)/iu',
        );

        $today = date( 'Y-m-d' );
        foreach ( $rows as $row ) {
            $text_death_date = '';
            foreach ( $death_phrases as $pattern ) {
                if ( preg_match( $pattern, $row->description, $dm ) ) {
                    $raw_date = preg_replace( '/\s+/', ' ', trim( $dm[1] ) );
                    $ts = strtotime( $raw_date );
                    if ( $ts ) {
                        $text_death_date = date( 'Y-m-d', $ts );
                    }
                    break;
                }
            }

            if ( empty( $text_death_date ) ) {
                continue; // No phrase-based date found in text
            }

            $db_year   = intval( substr( $row->date_of_death, 0, 4 ) );
            $text_year = intval( substr( $text_death_date, 0, 4 ) );

            // Only fix if years disagree AND the text date is valid (not future)
            if ( $db_year !== $text_year && $text_death_date <= $today && $text_year >= 2018 ) {
                $wpdb->update(
                    $table,
                    array( 'date_of_death' => $text_death_date ),
                    array( 'id' => $row->id ),
                    array( '%s' ),
                    array( '%d' )
                );
                ontario_obituaries_log(
                    sprintf(
                        'v4.2.4: Fixed death date for "%s" (ID %d): %s → %s (text phrase override).',
                        $row->name, $row->id, $row->date_of_death, $text_death_date
                    ),
                    'info'
                );
                $date_repairs++;
            }

            // Also catch future dates even if text year matches
            if ( $db_year === $text_year && $row->date_of_death > $today ) {
                // Future date but same year — might be wrong month/day from metadata
                // If text date is in the past, use it
                if ( $text_death_date <= $today ) {
                    $wpdb->update(
                        $table,
                        array( 'date_of_death' => $text_death_date ),
                        array( 'id' => $row->id ),
                        array( '%s' ),
                        array( '%d' )
                    );
                    ontario_obituaries_log(
                        sprintf(
                            'v4.2.4: Fixed future death date for "%s" (ID %d): %s → %s.',
                            $row->name, $row->id, $row->date_of_death, $text_death_date
                        ),
                        'info'
                    );
                    $date_repairs++;
                }
            }
        }

        if ( $date_repairs > 0 ) {
            ontario_obituaries_log(
                sprintf( 'v4.2.4: Repaired death dates for %d obituary records (year mismatch / future dates).', $date_repairs ),
                'info'
            );
            ontario_obituaries_purge_litespeed( 'ontario_obits' );
        }

        // Also clean the q2l0eq garbled city slug that survived previous migrations
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET city_normalized = '' WHERE city_normalized = %s",
                'q2l0eq'
            )
        );

        ontario_obituaries_log( 'v4.2.4: Death date cross-validation repair complete.', 'info' );
    }

    // ── v4.2.5: Re-run death date repair with whitespace fix ──
    // v4.2.4 migration failed silently because PHP's strtotime("May 7,\n\n2024")
    // ignores the year after newlines and returns the CURRENT year (2026).
    // Fix: collapse \s+ to single space before strtotime(). This block is
    // identical to v4.2.4 but with the preg_replace whitespace normalization.
    if ( version_compare( $stored_version, '4.2.5', '<' ) ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';
        $date_repairs = 0;

        $rows = $wpdb->get_results(
            "SELECT id, name, date_of_death, description FROM {$table} WHERE date_of_death != '' AND description != '' AND description IS NOT NULL"
        );

        $month_pattern = '(?:January|February|March|April|May|June|July|August|September|October|November|December)';
        $death_phrases = array(
            '/(?:passed\s+away|passed\s+peacefully|passed\s+suddenly|peacefully\s+passed)(?:\s+\w+){0,8}?\s+(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/isu',
            '/(?:peaceful\s+)?passing\s+of(?:\s+\w+){0,10}?(?:\s+on|\s+at)(?:\s+\w+){0,3}?\s+(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/isu',
            '/entered\s+into\s+rest(?:\s+on)?\s+(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/isu',
            '/died(?:\s+\w+){0,8}?\s+(?:on\s+)?(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/isu',
            '/(?:went\s+to\s+be\s+with|called\s+home)(?:\s+\w+){0,4}?\s+(?:on\s+)?(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/isu',
            '/left\s+us\s+(?:peacefully\s+)?(?:on\s+)?(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})/isu',
            '/[Oo]n\s+(' . $month_pattern . '\s+\d{1,2},?\s+\d{4})\s+(?:\w+\s+){0,3}(?:passed\s+away|passed|died)/isu',
        );

        $today = date( 'Y-m-d' );
        foreach ( $rows as $row ) {
            $text_death_date = '';
            foreach ( $death_phrases as $pattern ) {
                if ( preg_match( $pattern, $row->description, $dm ) ) {
                    // v4.2.5 FIX: collapse newlines/whitespace before strtotime
                    $raw_date = preg_replace( '/\s+/', ' ', trim( $dm[1] ) );
                    $ts = strtotime( $raw_date );
                    if ( $ts ) {
                        $text_death_date = date( 'Y-m-d', $ts );
                    }
                    break;
                }
            }

            if ( empty( $text_death_date ) ) {
                continue;
            }

            $db_year   = intval( substr( $row->date_of_death, 0, 4 ) );
            $text_year = intval( substr( $text_death_date, 0, 4 ) );

            if ( $db_year !== $text_year && $text_death_date <= $today && $text_year >= 2018 ) {
                $wpdb->update(
                    $table,
                    array( 'date_of_death' => $text_death_date ),
                    array( 'id' => $row->id ),
                    array( '%s' ),
                    array( '%d' )
                );
                ontario_obituaries_log(
                    sprintf(
                        'v4.2.5: Fixed death date for "%s" (ID %d): %s → %s (whitespace-normalized text override).',
                        $row->name, $row->id, $row->date_of_death, $text_death_date
                    ),
                    'info'
                );
                $date_repairs++;
            }

            if ( $db_year === $text_year && $row->date_of_death > $today ) {
                if ( $text_death_date <= $today ) {
                    $wpdb->update(
                        $table,
                        array( 'date_of_death' => $text_death_date ),
                        array( 'id' => $row->id ),
                        array( '%s' ),
                        array( '%d' )
                    );
                    ontario_obituaries_log(
                        sprintf(
                            'v4.2.5: Fixed future death date for "%s" (ID %d): %s → %s.',
                            $row->name, $row->id, $row->date_of_death, $text_death_date
                        ),
                        'info'
                    );
                    $date_repairs++;
                }
            }
        }

        if ( $date_repairs > 0 ) {
            ontario_obituaries_log(
                sprintf( 'v4.2.5: Repaired death dates for %d obituary records (whitespace-in-date fix).', $date_repairs ),
                'info'
            );
            ontario_obituaries_purge_litespeed( 'ontario_obits' );
        } else {
            ontario_obituaries_log( 'v4.2.5: Death date re-check complete — no additional repairs needed.', 'info' );
        }

        ontario_obituaries_log( 'v4.2.5: Whitespace-normalized death date repair complete.', 'info' );
    }

    // ── v4.3.0: Add GoFundMe + Authenticity Checker columns ──
    if ( version_compare( $stored_version, '4.3.0', '<' ) ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        $existing_cols = $wpdb->get_col( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
            $table
        ) );

        $new_cols = array(
            // GoFundMe columns.
            'gofundme_url'        => "ALTER TABLE `{$table}` ADD COLUMN gofundme_url varchar(2083) DEFAULT NULL",
            'gofundme_checked_at' => "ALTER TABLE `{$table}` ADD COLUMN gofundme_checked_at datetime DEFAULT NULL",
            // Authenticity Checker columns.
            'last_audit_at'       => "ALTER TABLE `{$table}` ADD COLUMN last_audit_at datetime DEFAULT NULL",
            'audit_status'        => "ALTER TABLE `{$table}` ADD COLUMN audit_status varchar(20) DEFAULT NULL",
            'audit_flags'         => "ALTER TABLE `{$table}` ADD COLUMN audit_flags text DEFAULT NULL",
        );

        $added = 0;
        foreach ( $new_cols as $col => $sql ) {
            if ( ! in_array( $col, $existing_cols, true ) ) {
                $wpdb->query( $sql );
                $added++;
            }
        }

        // Add indexes for the new columns.
        $wpdb->query( "ALTER TABLE `{$table}` ADD KEY idx_gofundme_checked (gofundme_checked_at)" );
        $wpdb->query( "ALTER TABLE `{$table}` ADD KEY idx_audit_status (audit_status)" );
        $wpdb->query( "ALTER TABLE `{$table}` ADD KEY idx_last_audit_at (last_audit_at)" );

        ontario_obituaries_log(
            sprintf( 'v4.3.0: Added %d new columns (GoFundMe linker + Authenticity Checker).', $added ),
            'info'
        );
    }

    ontario_obituaries_log( sprintf( 'Plugin updated to v%s — caches purged, rewrite rules flushed.', ONTARIO_OBITUARIES_VERSION ), 'info' );
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
 * v3.16.0: Register a REST API endpoint for server-side cron.
 *
 * This allows setting up a real crontab entry on the server:
 *   Every 15 min:  curl -s https://monacomonuments.ca/wp-json/ontario-obituaries/v1/cron > /dev/null 2>&1
 *
 * The endpoint triggers the collection event if the data is stale.
 * No authentication required (the endpoint only triggers a read/scrape).
 */
function ontario_obituaries_register_cron_rest_route() {
    register_rest_route( 'ontario-obituaries/v1', '/cron', array(
        'methods'             => 'GET',
        'callback'            => 'ontario_obituaries_handle_cron_rest',
        'permission_callback' => '__return_true',
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

        // Also run dedup after collection.
        ontario_obituaries_cleanup_duplicates();
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
