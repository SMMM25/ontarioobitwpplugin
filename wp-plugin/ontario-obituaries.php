<?php
/**
 * Plugin Name: Ontario Obituaries
 * Description: Ontario-wide obituary data ingestion with coverage-first, rights-aware publishing — Compatible with Obituary Assistant
 * Version: 3.14.0
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
define( 'ONTARIO_OBITUARIES_VERSION', '3.14.0' );
define( 'ONTARIO_OBITUARIES_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ONTARIO_OBITUARIES_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ONTARIO_OBITUARIES_PLUGIN_FILE', __FILE__ );

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

    // SEO — needed on frontend for rewrite rules
    require_once ONTARIO_OBITUARIES_PLUGIN_DIR . 'includes/class-ontario-obituaries-seo.php';

    // P2-1 FIX: Only load collector, adapters, and image pipeline in admin/cron contexts
    if ( is_admin() || wp_doing_cron() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
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
}
add_action( 'ontario_obituaries_collection_event', 'ontario_obituaries_scheduled_collection' );

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

        if ( 0 === $current_count && class_exists( 'Ontario_Obituaries_Source_Collector' ) ) {
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

    ontario_obituaries_log( sprintf( 'Plugin updated to v%s — caches purged, rewrite rules flushed.', ONTARIO_OBITUARIES_VERSION ), 'info' );
}
add_action( 'init', 'ontario_obituaries_on_plugin_update', 1 );

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
