<?php
/**
 * Plugin Name: Ontario Obituaries
 * Description: Ontario-wide obituary data ingestion with coverage-first, rights-aware publishing — Compatible with Obituary Assistant
 * Version: 3.0.0
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
define( 'ONTARIO_OBITUARIES_VERSION', '3.0.0' );
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
        'frequency'      => 'daily',
        'time'           => '03:00',
        'regions'        => array( 'Toronto', 'Ottawa', 'Hamilton' ),
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
}
register_activation_hook( __FILE__, 'ontario_obituaries_activate' );

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
