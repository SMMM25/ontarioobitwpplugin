<?php
/**
 * Health Monitor — Phase 3
 *
 * Provides an admin-visible System Health submenu page, an admin-bar badge
 * showing the current error/warning count, and a REST endpoint for
 * programmatic health checks.
 *
 * Reads data exclusively from the existing Phase 1 health functions
 * (oo_health_get_summary, oo_health_get_counts) stored in wp_options
 * and transients — no new DB table required.
 *
 * @package  OntarioObituaries
 * @since    5.3.5
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ontario_Obituaries_Health_Monitor {

    /* ═══════════════════════════════════════════════════════════════════
     *  HOOKS — called once from ontario-obituaries.php
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Register all hooks.
     */
    public static function init() {
        add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar_badge' ), 999 );
        add_action( 'rest_api_init',  array( __CLASS__, 'register_rest_route' ) );
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  ADMIN BAR BADGE
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Add a badge to the WordPress admin bar showing issue count.
     *
     * - Green (no badge) when 0 issues.
     * - Yellow dot when warnings only.
     * - Red dot when errors or critical exist.
     *
     * Only visible to users with manage_options capability.
     *
     * @param WP_Admin_Bar $wp_admin_bar The admin bar instance.
     */
    public static function admin_bar_badge( $wp_admin_bar ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! function_exists( 'oo_health_get_summary' ) ) {
            return;
        }

        $summary = oo_health_get_summary();
        $total   = isset( $summary['total_issues_24h'] ) ? (int) $summary['total_issues_24h'] : 0;

        if ( 0 === $total ) {
            return; // Clean — no badge.
        }

        // Determine severity for colour.
        $counts = isset( $summary['top_codes'] ) ? $summary['top_codes'] : array();
        $has_critical = ! empty( $summary['last_critical'] );
        $colour = $has_critical ? '#dc3232' : '#ffb900'; // Red or yellow.

        $badge_text = $total > 99 ? '99+' : (string) $total;

        $wp_admin_bar->add_node( array(
            'id'    => 'ontario-obituaries-health',
            'title' => sprintf(
                '<span style="background:%s;color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;margin-left:4px;">OO %s</span>',
                esc_attr( $colour ),
                esc_html( $badge_text )
            ),
            'href'  => admin_url( 'admin.php?page=ontario-obituaries-health' ),
            'meta'  => array(
                'title' => sprintf(
                    /* translators: %d = number of issues */
                    __( 'Ontario Obituaries: %d issues in last 24h', 'ontario-obituaries' ),
                    $total
                ),
            ),
        ) );
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  REST ENDPOINT — GET /wp-json/ontario-obituaries/v1/health
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Register the REST health route.
     */
    public static function register_rest_route() {
        register_rest_route( 'ontario-obituaries/v1', '/health', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_health' ),
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
        ) );
    }

    /**
     * REST handler: return health summary as JSON.
     *
     * @return WP_REST_Response
     */
    public static function rest_health() {
        if ( ! function_exists( 'oo_health_get_summary' ) ) {
            return new WP_REST_Response( array( 'error' => 'Health functions not loaded' ), 503 );
        }

        $summary = oo_health_get_summary();
        $cron    = self::get_cron_status();
        $checks  = self::get_subsystem_checks();

        return new WP_REST_Response( array(
            'version'    => defined( 'ONTARIO_OBITUARIES_VERSION' ) ? ONTARIO_OBITUARIES_VERSION : 'unknown',
            'summary'    => $summary,
            'cron'       => $cron,
            'subsystems' => $checks,
            'generated'  => current_time( 'mysql', true ),
        ), 200 );
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  ADMIN PAGE — Health submenu
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Render the System Health page.
     *
     * Called as the callback for the "Health" submenu registered
     * in Ontario_Obituaries::register_admin_menu().
     */
    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'ontario-obituaries' ) );
        }

        // Prevent cache.
        if ( ! headers_sent() ) {
            header( 'X-LiteSpeed-Cache-Control: no-cache' );
            nocache_headers();
        }

        $summary = function_exists( 'oo_health_get_summary' ) ? oo_health_get_summary() : array();
        $cron    = self::get_cron_status();
        $checks  = self::get_subsystem_checks();
        $counts  = function_exists( 'oo_health_get_counts' ) ? oo_health_get_counts() : array();

        // Sort counts descending.
        arsort( $counts );

        $version  = defined( 'ONTARIO_OBITUARIES_VERSION' ) ? ONTARIO_OBITUARIES_VERSION : '?';
        $total    = isset( $summary['total_issues_24h'] ) ? (int) $summary['total_issues_24h'] : 0;
        $unique   = isset( $summary['unique_codes'] ) ? (int) $summary['unique_codes'] : 0;
        $healthy  = ! empty( $summary['pipeline_healthy'] );

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Ontario Obituaries — System Health', 'ontario-obituaries' ); ?>
                <span style="font-size:13px;color:#666;margin-left:12px;">v<?php echo esc_html( $version ); ?></span>
            </h1>

            <?php // ── Summary Banner ── ?>
            <div class="card" style="max-width:800px;margin:16px 0;padding:16px 20px;border-left:4px solid <?php echo $healthy ? '#46b450' : '#dc3232'; ?>;">
                <h2 style="margin-top:0;">
                    <?php echo $healthy
                        ? '<span style="color:#46b450;">&#10003;</span> ' . esc_html__( 'Pipeline Healthy', 'ontario-obituaries' )
                        : '<span style="color:#dc3232;">&#10007;</span> ' . esc_html__( 'Pipeline Needs Attention', 'ontario-obituaries' ); ?>
                </h2>
                <p>
                    <?php printf(
                        /* translators: 1 = issue count, 2 = unique code count */
                        esc_html__( 'Last 24 hours: %1$d issues across %2$d error codes.', 'ontario-obituaries' ),
                        $total,
                        $unique
                    ); ?>
                </p>
                <?php if ( ! empty( $summary['last_critical'] ) ) : ?>
                    <p style="color:#dc3232;font-weight:600;">
                        <?php printf(
                            esc_html__( 'Last critical: %s — %s', 'ontario-obituaries' ),
                            esc_html( $summary['last_critical']['code'] ?? '' ),
                            esc_html( $summary['last_critical']['message'] ?? '' )
                        ); ?>
                    </p>
                <?php endif; ?>
            </div>

            <?php // ── Cron Status ── ?>
            <h2><?php esc_html_e( 'Cron Jobs', 'ontario-obituaries' ); ?></h2>
            <table class="widefat fixed striped" style="max-width:800px;">
                <thead>
                    <tr>
                        <th style="width:30%;"><?php esc_html_e( 'Job', 'ontario-obituaries' ); ?></th>
                        <th style="width:15%;"><?php esc_html_e( 'Status', 'ontario-obituaries' ); ?></th>
                        <th style="width:25%;"><?php esc_html_e( 'Next Run', 'ontario-obituaries' ); ?></th>
                        <th style="width:30%;"><?php esc_html_e( 'Last Success', 'ontario-obituaries' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $cron as $job ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $job['label'] ); ?></strong></td>
                            <td>
                                <?php if ( 'ok' === $job['status'] ) : ?>
                                    <span style="color:#46b450;">&#10003; <?php esc_html_e( 'Scheduled', 'ontario-obituaries' ); ?></span>
                                <?php elseif ( 'overdue' === $job['status'] ) : ?>
                                    <span style="color:#ffb900;">&#9888; <?php esc_html_e( 'Overdue', 'ontario-obituaries' ); ?></span>
                                <?php else : ?>
                                    <span style="color:#dc3232;">&#10007; <?php esc_html_e( 'Not Scheduled', 'ontario-obituaries' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $job['next_run'] ); ?></td>
                            <td><?php echo esc_html( $job['last_success'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php // ── Subsystem Checks ── ?>
            <h2><?php esc_html_e( 'Subsystem Checks', 'ontario-obituaries' ); ?></h2>
            <table class="widefat fixed striped" style="max-width:800px;">
                <thead>
                    <tr>
                        <th style="width:40%;"><?php esc_html_e( 'Check', 'ontario-obituaries' ); ?></th>
                        <th style="width:15%;"><?php esc_html_e( 'Status', 'ontario-obituaries' ); ?></th>
                        <th style="width:45%;"><?php esc_html_e( 'Detail', 'ontario-obituaries' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $checks as $check ) : ?>
                        <tr>
                            <td><?php echo esc_html( $check['label'] ); ?></td>
                            <td>
                                <?php echo $check['ok']
                                    ? '<span style="color:#46b450;">&#10003; OK</span>'
                                    : '<span style="color:#dc3232;">&#10007; FAIL</span>'; ?>
                            </td>
                            <td><?php echo esc_html( $check['detail'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php // ── Error Code Breakdown ── ?>
            <h2><?php esc_html_e( 'Error Codes (Last 24h)', 'ontario-obituaries' ); ?></h2>
            <?php if ( empty( $counts ) ) : ?>
                <p style="color:#46b450;"><?php esc_html_e( 'No errors recorded in the last 24 hours.', 'ontario-obituaries' ); ?></p>
            <?php else : ?>
                <table class="widefat fixed striped" style="max-width:800px;">
                    <thead>
                        <tr>
                            <th style="width:50%;"><?php esc_html_e( 'Code', 'ontario-obituaries' ); ?></th>
                            <th style="width:20%;"><?php esc_html_e( 'Count', 'ontario-obituaries' ); ?></th>
                            <th style="width:30%;"><?php esc_html_e( 'Severity', 'ontario-obituaries' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $counts as $code => $count ) : ?>
                            <tr>
                                <td><code><?php echo esc_html( $code ); ?></code></td>
                                <td><?php echo (int) $count; ?></td>
                                <td><?php echo esc_html( self::severity_for_code( $code ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php // ── Last Success Timestamps ── ?>
            <h2><?php esc_html_e( 'Last Success Timestamps', 'ontario-obituaries' ); ?></h2>
            <table class="widefat fixed striped" style="max-width:800px;">
                <thead>
                    <tr>
                        <th style="width:30%;"><?php esc_html_e( 'Subsystem', 'ontario-obituaries' ); ?></th>
                        <th style="width:35%;"><?php esc_html_e( 'Last Ran', 'ontario-obituaries' ); ?></th>
                        <th style="width:35%;"><?php esc_html_e( 'Last Success', 'ontario-obituaries' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $ran       = isset( $summary['last_ran'] ) ? $summary['last_ran'] : array();
                    $successes = isset( $summary['last_success'] ) ? $summary['last_success'] : array();
                    $all_subs  = array_unique( array_merge( array_keys( $ran ), array_keys( $successes ) ) );
                    sort( $all_subs );
                    foreach ( $all_subs as $sub ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $sub ); ?></strong></td>
                            <td><?php echo esc_html( isset( $ran[ $sub ] ) ? $ran[ $sub ] : '—' ); ?></td>
                            <td><?php echo esc_html( isset( $successes[ $sub ] ) ? $successes[ $sub ] : '—' ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p style="color:#999;margin-top:24px;font-size:12px;">
                <?php printf(
                    esc_html__( 'Generated at %s UTC. Data from wp_options transients (no extra DB table).', 'ontario-obituaries' ),
                    esc_html( current_time( 'mysql', true ) )
                ); ?>
            </p>
        </div>
        <?php
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  DATA HELPERS
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Get the status of all plugin cron hooks.
     *
     * @return array[] Each entry: [ 'hook', 'label', 'status', 'next_run', 'last_success' ]
     */
    public static function get_cron_status() {
        $hooks = array(
            'ontario_obituaries_collection_event'  => array( 'label' => 'Collection (scrape)',  'subsystem' => 'SCRAPE' ),
            'ontario_obituaries_ai_rewrite_batch'  => array( 'label' => 'AI Rewriter (5 min)',  'subsystem' => 'REWRITE' ),
            'ontario_obituaries_dedup_daily'       => array( 'label' => 'Dedup (daily)',         'subsystem' => 'DEDUP' ),
            'ontario_obituaries_gofundme_batch'    => array( 'label' => 'GoFundMe linker',      'subsystem' => 'GOFUNDME' ),
            'ontario_obituaries_authenticity_audit' => array( 'label' => 'Authenticity audit',  'subsystem' => 'AUTHENTICITY' ),
        );

        $successes = get_option( 'oo_last_success', array() );
        if ( ! is_array( $successes ) ) {
            $successes = array();
        }

        $result = array();
        foreach ( $hooks as $hook => $info ) {
            $next = wp_next_scheduled( $hook );

            if ( false === $next ) {
                $status   = 'missing';
                $next_run = __( 'Not scheduled', 'ontario-obituaries' );
            } elseif ( $next < time() ) {
                $status   = 'overdue';
                $ago      = human_time_diff( $next, time() );
                $next_run = sprintf( __( 'Overdue by %s', 'ontario-obituaries' ), $ago );
            } else {
                $status   = 'ok';
                $in       = human_time_diff( time(), $next );
                $next_run = sprintf( __( 'In %s', 'ontario-obituaries' ), $in );
            }

            $sub_key      = $info['subsystem'];
            $last_success = isset( $successes[ $sub_key ] ) ? $successes[ $sub_key ] : __( 'Never', 'ontario-obituaries' );

            $result[] = array(
                'hook'         => $hook,
                'label'        => $info['label'],
                'status'       => $status,
                'next_run'     => $next_run,
                'last_success' => $last_success,
            );
        }

        return $result;
    }

    /**
     * Run subsystem health checks.
     *
     * @return array[] Each entry: [ 'label', 'ok', 'detail' ]
     */
    public static function get_subsystem_checks() {
        global $wpdb;
        $checks = array();

        // 1. Main obituaries table exists.
        $table = $wpdb->prefix . 'ontario_obituaries';

        // QC-R2: Validate derived table name against allowlist regex before raw SQL.
        if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
            if ( function_exists( 'oo_log' ) ) {
                oo_log( 'warning', 'HEALTH', 'TABLE_NAME_INVALID', 'Derived table name failed allowlist regex', array( 'table' => $table ) );
            }
            $checks[] = array(
                'label'  => __( 'Obituaries table', 'ontario-obituaries' ),
                'ok'     => false,
                'detail' => __( 'Invalid table prefix', 'ontario-obituaries' ),
            );
        } else {
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
            $count  = $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ) : 0;
            $checks[] = array(
                'label'  => __( 'Obituaries table', 'ontario-obituaries' ),
                'ok'     => ! empty( $exists ),
                'detail' => $exists
                    ? sprintf( __( '%d rows', 'ontario-obituaries' ), $count )
                    : __( 'Table missing', 'ontario-obituaries' ),
            );
        }

        // 2. Groq API key set.
        $groq_key = get_option( 'ontario_obituaries_groq_api_key', '' );
        $checks[] = array(
            'label'  => __( 'Groq API key', 'ontario-obituaries' ),
            'ok'     => ! empty( $groq_key ),
            'detail' => ! empty( $groq_key )
                ? __( 'Set', 'ontario-obituaries' ) . ' (' . substr( $groq_key, 0, 8 ) . '...)'
                : __( 'Not configured', 'ontario-obituaries' ),
        );

        // 3. Upload directory writable.
        $upload_dir = wp_upload_dir();
        $writable   = ! empty( $upload_dir['basedir'] ) && wp_is_writable( $upload_dir['basedir'] );
        $checks[] = array(
            'label'  => __( 'Upload directory', 'ontario-obituaries' ),
            'ok'     => $writable,
            'detail' => $writable ? __( 'Writable', 'ontario-obituaries' ) : __( 'Not writable', 'ontario-obituaries' ),
        );

        // 4. WP-Cron enabled.
        $cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
        $checks[] = array(
            'label'  => __( 'WP-Cron', 'ontario-obituaries' ),
            'ok'     => ! $cron_disabled,
            'detail' => $cron_disabled
                ? __( 'Disabled (DISABLE_WP_CRON = true). Use server cron.', 'ontario-obituaries' )
                : __( 'Enabled', 'ontario-obituaries' ),
        );

        // 5. PHP memory.
        $memory_limit = ini_get( 'memory_limit' );
        $memory_bytes = wp_convert_hr_to_bytes( $memory_limit );
        $checks[] = array(
            'label'  => __( 'PHP memory limit', 'ontario-obituaries' ),
            'ok'     => $memory_bytes >= 128 * 1024 * 1024, // 128M minimum.
            'detail' => $memory_limit,
        );

        // 6. Error handler loaded.
        $handler_ok = function_exists( 'oo_log' ) && function_exists( 'oo_db_check' );
        $checks[] = array(
            'label'  => __( 'Error handler', 'ontario-obituaries' ),
            'ok'     => $handler_ok,
            'detail' => $handler_ok ? __( 'All functions loaded', 'ontario-obituaries' ) : __( 'Missing', 'ontario-obituaries' ),
        );

        return $checks;
    }

    /* ═══════════════════════════════════════════════════════════════════
     *  UTILITIES
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Map error code prefix to a human-readable severity label.
     *
     * @param string $code Error code like DB_INSERT_FAIL.
     * @return string      Severity label.
     */
    private static function severity_for_code( $code ) {
        $map = array(
            'CRON_'     => 'warning',
            'SCRAPE_'   => 'warning',
            'REWRITE_'  => 'warning',
            'DB_'       => 'error',
            'IMAGE_'    => 'warning',
            'AI_'       => 'warning',
            'AJAX_'     => 'error',
            'TEMPLATE_' => 'error',
            'SYSTEM_'   => 'critical',
        );
        foreach ( $map as $prefix => $level ) {
            if ( 0 === strpos( $code, $prefix ) ) {
                return $level;
            }
        }
        return 'warning';
    }
}
