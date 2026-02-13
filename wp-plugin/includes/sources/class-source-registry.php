<?php
/**
 * Source Registry
 *
 * Manages the registry of obituary data sources (funeral homes, newspapers, etc.)
 * and their associated adapter types. Stored in a custom DB table.
 *
 * Each source has:
 *   - domain (unique key)
 *   - base_url (the obituary listing page)
 *   - adapter_type (which adapter class handles this platform)
 *   - config (JSON — adapter-specific selectors, paths, etc.)
 *   - region/city tags
 *   - enabled flag
 *   - rate limits and circuit breaker state
 *
 * @package Ontario_Obituaries
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ontario_Obituaries_Source_Registry {

    /** @var string Table name (without prefix). */
    const TABLE_NAME = 'ontario_obituaries_sources';

    /** @var array Registered adapter instances, keyed by type slug. */
    private static $adapters = array();

    /* ─────────────────────── ADAPTER REGISTRATION ─────────────────── */

    /**
     * Register a source adapter.
     *
     * @param Ontario_Obituaries_Source_Adapter $adapter Adapter instance.
     */
    public static function register_adapter( $adapter ) {
        self::$adapters[ $adapter->get_type() ] = $adapter;
    }

    /**
     * Get a registered adapter by type slug.
     *
     * @param string $type Adapter type.
     * @return Ontario_Obituaries_Source_Adapter|null
     */
    public static function get_adapter( $type ) {
        return isset( self::$adapters[ $type ] ) ? self::$adapters[ $type ] : null;
    }

    /**
     * Get all registered adapters.
     *
     * @return Ontario_Obituaries_Source_Adapter[]
     */
    public static function get_all_adapters() {
        return self::$adapters;
    }

    /* ─────────────────────── DATABASE OPERATIONS ──────────────────── */

    /**
     * Get the full table name (with prefix).
     *
     * @return string
     */
    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Create the sources table.
     * Called during plugin activation.
     */
    public static function create_table() {
        global $wpdb;
        $table_name      = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            domain varchar(255) NOT NULL,
            name varchar(255) NOT NULL DEFAULT '',
            base_url varchar(2083) NOT NULL,
            adapter_type varchar(50) NOT NULL DEFAULT 'generic_html',
            config longtext DEFAULT NULL,
            city varchar(100) NOT NULL DEFAULT '',
            region varchar(100) NOT NULL DEFAULT '',
            province varchar(10) NOT NULL DEFAULT 'ON',
            enabled tinyint(1) NOT NULL DEFAULT 1,
            image_allowlisted tinyint(1) NOT NULL DEFAULT 0,
            max_pages_per_run smallint UNSIGNED NOT NULL DEFAULT 5,
            min_request_interval float NOT NULL DEFAULT 2.0,
            consecutive_failures smallint UNSIGNED NOT NULL DEFAULT 0,
            circuit_open_until datetime DEFAULT NULL,
            last_success datetime DEFAULT NULL,
            last_failure datetime DEFAULT NULL,
            last_failure_reason varchar(500) DEFAULT NULL,
            total_collected bigint UNSIGNED NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_domain (domain),
            KEY idx_adapter_type (adapter_type),
            KEY idx_city (city),
            KEY idx_region (region),
            KEY idx_enabled (enabled)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Get all enabled sources, respecting circuit breaker.
     *
     * @return array[] Source rows.
     */
    public static function get_active_sources() {
        global $wpdb;
        $table = self::table_name();
        $now   = current_time( 'mysql', true );

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}`
                 WHERE enabled = 1
                   AND ( circuit_open_until IS NULL OR circuit_open_until < %s )
                 ORDER BY last_success ASC",
                $now
            ),
            ARRAY_A
        );
    }

    /**
     * Get sources filtered by region or city.
     *
     * @param string $region Region name (optional).
     * @param string $city   City name (optional).
     * @return array[]
     */
    public static function get_sources_by_location( $region = '', $city = '' ) {
        global $wpdb;
        $table = self::table_name();
        $now   = current_time( 'mysql', true );

        $where = array( 'enabled = 1', $wpdb->prepare( '( circuit_open_until IS NULL OR circuit_open_until < %s )', $now ) );

        if ( ! empty( $region ) ) {
            $where[] = $wpdb->prepare( 'region = %s', $region );
        }
        if ( ! empty( $city ) ) {
            $where[] = $wpdb->prepare( 'city = %s', $city );
        }

        $where_clause = implode( ' AND ', $where );
        return $wpdb->get_results( "SELECT * FROM `{$table}` WHERE {$where_clause} ORDER BY domain ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Record a successful collection for a source.
     *
     * @param int $source_id Source ID.
     * @param int $count     Number of obituaries collected.
     */
    public static function record_success( $source_id, $count = 0 ) {
        global $wpdb;
        $table = self::table_name();

        // P0-3 FIX: Single atomic query — removed broken $wpdb->update() with stdClass.
        $wpdb->query( $wpdb->prepare(
            "UPDATE `{$table}` SET
                last_success = %s,
                consecutive_failures = 0,
                circuit_open_until = NULL,
                total_collected = total_collected + %d
             WHERE id = %d",
            current_time( 'mysql', true ),
            $count,
            $source_id
        ) );
    }

    /**
     * Record a failure for a source. Opens circuit breaker after threshold.
     *
     * @param int    $source_id Source ID.
     * @param string $reason    Error message.
     * @param int    $threshold Failures before circuit opens (default 10).
     */
    public static function record_failure( $source_id, $reason = '', $threshold = 10 ) {
        global $wpdb;
        $table = self::table_name();

        // Increment failures
        $wpdb->query( $wpdb->prepare(
            "UPDATE `{$table}` SET
                last_failure = %s,
                last_failure_reason = %s,
                consecutive_failures = consecutive_failures + 1
             WHERE id = %d",
            current_time( 'mysql', true ),
            $reason,
            $source_id
        ) );

        // Check if we should open the circuit breaker
        $failures = $wpdb->get_var( $wpdb->prepare(
            "SELECT consecutive_failures FROM `{$table}` WHERE id = %d",
            $source_id
        ) );

        if ( intval( $failures ) >= $threshold ) {
            // Open circuit for 24 hours
            $reopen = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
            $wpdb->update(
                $table,
                array( 'circuit_open_until' => $reopen ),
                array( 'id' => $source_id ),
                array( '%s' ),
                array( '%d' )
            );
            ontario_obituaries_log(
                sprintf( 'Circuit breaker OPEN for source %d until %s (%d consecutive failures)', $source_id, $reopen, $failures ),
                'warning'
            );
        }
    }

    /**
     * Add or update a source.
     *
     * v3.9.0: Enforces a key whitelist and typed format map so only known
     * columns are written — prevents accidental writes of unexpected keys
     * whether called from seed_defaults() or an admin UI.
     *
     * NOTE on the 'domain' field: this is a **unique source slug**, NOT a DNS
     * hostname. Sources that share the same host but serve different cities
     * (e.g. dignitymemorial.com/newmarket-on vs dignitymemorial.com/toronto-on)
     * use path segments to create unique slugs. The obituary record's
     * 'source_domain' is derived separately from extract_domain(base_url) and
     * returns the actual hostname. Never compare 'domain' to 'source_domain'.
     *
     * @param array $data Source data.
     * @return int|false Source ID or false on failure.
     */
    public static function upsert_source( $data ) {
        global $wpdb;
        $table = self::table_name();

        $defaults = array(
            'domain'               => '',
            'name'                 => '',
            'base_url'             => '',
            'adapter_type'         => 'generic_html',
            'config'               => '{}',
            'city'                 => '',
            'region'               => '',
            'province'             => 'ON',
            'enabled'              => 1,
            'image_allowlisted'    => 0,
            'max_pages_per_run'    => 5,
            'min_request_interval' => 2.0,
        );

        // v3.9.0: Key whitelist — only these columns may be written.
        $allowed_keys = array_keys( $defaults );

        // v3.9.0: Typed format map for $wpdb->insert() / $wpdb->update().
        $format_map = array(
            'domain'               => '%s',
            'name'                 => '%s',
            'base_url'             => '%s',
            'adapter_type'         => '%s',
            'config'               => '%s',
            'city'                 => '%s',
            'region'               => '%s',
            'province'             => '%s',
            'enabled'              => '%d',
            'image_allowlisted'    => '%d',
            'max_pages_per_run'    => '%d',
            'min_request_interval' => '%f',
        );

        $data = wp_parse_args( $data, $defaults );

        // Strip any keys not in the whitelist.
        $data = array_intersect_key( $data, array_flip( $allowed_keys ) );

        // Validate JSON config — invalid JSON falls back to '{}'.
        if ( ! empty( $data['config'] ) && null === json_decode( $data['config'] ) ) {
            $data['config'] = '{}';
        }

        // Build format array in the same key order as $data.
        $formats = array();
        foreach ( array_keys( $data ) as $key ) {
            $formats[] = isset( $format_map[ $key ] ) ? $format_map[ $key ] : '%s';
        }

        // Check if exists (domain is UNIQUE KEY).
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE domain = %s",
            $data['domain']
        ) );

        if ( $existing ) {
            $wpdb->update( $table, $data, array( 'id' => $existing ), $formats, array( '%d' ) );
            return intval( $existing );
        }

        $wpdb->insert( $table, $data, $formats );
        return $wpdb->insert_id ? intval( $wpdb->insert_id ) : false;
    }

    /**
     * Delete a source by ID.
     *
     * @param int $source_id Source ID.
     * @return bool
     */
    public static function delete_source( $source_id ) {
        global $wpdb;
        return (bool) $wpdb->delete( self::table_name(), array( 'id' => $source_id ), array( '%d' ) );
    }

    /**
     * Get a single source by ID.
     *
     * @param int $source_id Source ID.
     * @return array|null
     */
    public static function get_source( $source_id ) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `" . self::table_name() . "` WHERE id = %d", $source_id ),
            ARRAY_A
        );
    }

    /**
     * Get total source counts by status.
     *
     * @return array { total, enabled, disabled, circuit_open }
     */
    public static function get_stats() {
        global $wpdb;
        $table = self::table_name();
        $now   = current_time( 'mysql', true );

        return array(
            'total'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ),
            'enabled'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE enabled = 1" ),
            'disabled'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE enabled = 0" ),
            'circuit_open' => (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE circuit_open_until IS NOT NULL AND circuit_open_until >= %s",
                $now
            ) ),
        );
    }

    /**
     * Seed the registry with default southern Ontario sources.
     *
     * Called during activation and by the v3.9.0 re-seed guard in
     * on_plugin_update() when the sources table is empty.
     *
     * Uses upsert_source() so re-running is safe (idempotent).
     *
     * v3.9.0 changes:
     *   - Dead/unreachable sources are seeded with enabled = 0 so their
     *     domain key is preserved in the DB (history, circuit-breaker state)
     *     but they don't waste scrape cycles. They can be re-enabled via
     *     admin UI once the underlying site is verified reachable.
     *   - Source 'domain' is a unique slug (may include path segments for
     *     hosts that serve multiple city pages). See upsert_source() docs.
     */
    public static function seed_defaults() {
        $sources = array(

            // ══════════════════════════════════════════════════════════════
            // PRIORITY 1 — York Region / Newmarket (Monaco Monuments home turf)
            // These run FIRST and drive local SEO + funeral home referrals.
            // ══════════════════════════════════════════════════════════════
            // v3.10.0: DISABLED — FrontRunner JS-rendered site; server-side HTML
            // contains only {name}/{date} template placeholders. The FrontRunner
            // JSON API (obituaries.frontrunnerpro.com) returns 0 records server-side.
            array(
                'domain'       => 'roadhouseandrose.com',
                'name'         => 'Roadhouse & Rose Funeral Home',
                'base_url'     => 'https://www.roadhouseandrose.com/obituaries',
                'adapter_type' => 'frontrunner',
                'city'         => 'Newmarket',
                'region'       => 'York Region',
                'enabled'      => 0,
            ),
            // v3.13.0: DISABLED — arbormemorial.ca is a React SPA (Adobe AEM +
            // client-side rendering). Server-side HTML contains only <div id="spa-root"></div>
            // with no obituary data. The generic_html adapter cannot extract cards from
            // JS-rendered content. Confirmed via curl: HTTP 200, 4 KB HTML shell, 0 cards.
            array(
                'domain'       => 'arbormemorial.ca/taylor',
                'name'         => 'Taylor Funeral Home (Arbor Memorial)',
                'base_url'     => 'https://www.arbormemorial.ca/en/taylor/obituaries.html',
                'adapter_type' => 'generic_html',
                'city'         => 'Newmarket',
                'region'       => 'York Region',
                'enabled'      => 0,
            ),
            // v3.10.0: DISABLED — FrontRunner JS-rendered site (same issue as roadhouseandrose).
            array(
                'domain'       => 'forrestandtaylor.com',
                'name'         => 'Forrest & Taylor Funeral Home',
                'base_url'     => 'https://www.forrestandtaylor.com/obituaries',
                'adapter_type' => 'frontrunner',
                'city'         => 'Sutton',
                'region'       => 'York Region',
                'enabled'      => 0,
            ),
            // v3.10.0: DISABLED — marshallfuneralhome.com redirects to arbormemorial.ca
            // (React SPA, not FrontRunner). Updated base_url + adapter_type for accuracy.
            array(
                'domain'       => 'marshallfuneralhome.com',
                'name'         => 'Marshall Funeral Home',
                'base_url'     => 'https://www.arbormemorial.ca/en/marshall/obituaries.html',
                'adapter_type' => 'generic_html',
                'city'         => 'Richmond Hill',
                'region'       => 'York Region',
                'enabled'      => 0,
            ),
            // v3.9.0: DISABLED — thompsonfh-aurora.com redirects to dignitymemorial.com (403).
            // Kept in seed so domain key is preserved; re-enable when site is reachable.
            array(
                'domain'       => 'thompsonfh-aurora.com',
                'name'         => 'Thompson Funeral Home - Aurora',
                'base_url'     => 'https://www.thompsonfh-aurora.com/obituaries',
                'adapter_type' => 'frontrunner',
                'city'         => 'Aurora',
                'region'       => 'York Region',
                'enabled'      => 0,
            ),
            // v3.13.0: DISABLED — dignitymemorial.com returns HTTP 403 (WAF/bot
            // protection). Confirmed via Rescan-Only diagnostics 2026-02-11.
            array(
                'domain'       => 'dignitymemorial.com/newmarket-on',
                'name'         => 'Dignity Memorial - Newmarket',
                'base_url'     => 'https://www.dignitymemorial.com/obituaries/newmarket-on',
                'adapter_type' => 'dignity_memorial',
                'city'         => 'Newmarket',
                'region'       => 'York Region',
                'enabled'      => 0,
            ),
            // v3.10.0: DISABLED — FrontRunner JS-rendered site (same issue as roadhouseandrose).
            array(
                'domain'       => 'wardfuneralhome.com',
                'name'         => 'Ward Funeral Home - Brampton/Bolton',
                'base_url'     => 'https://www.wardfuneralhome.com/obituaries',
                'adapter_type' => 'frontrunner',
                'city'         => 'Brampton',
                'region'       => 'York Region',
                'enabled'      => 0,
            ),
            // v3.9.0: DISABLED — skinnerfuneralhome.ca DNS timeout / unreachable.
            array(
                'domain'       => 'skinnerfuneralhome.ca',
                'name'         => 'Skinner & Middlebrook Funeral Home',
                'base_url'     => 'https://www.skinnerfuneralhome.ca/obituaries',
                'adapter_type' => 'frontrunner',
                'city'         => 'Bradford',
                'region'       => 'York Region',
                'enabled'      => 0,
            ),
            // v3.9.0: DISABLED — peacefultransition.ca/obituaries returns HTTP 404.
            array(
                'domain'       => 'peacefultransition.ca',
                'name'         => 'Peaceful Transition - Newmarket',
                'base_url'     => 'https://www.peacefultransition.ca/obituaries',
                'adapter_type' => 'generic_html',
                'city'         => 'Newmarket',
                'region'       => 'York Region',
                'enabled'      => 0,
            ),
            // v3.13.0: DISABLED — dignitymemorial.com returns HTTP 403.
            array(
                'domain'       => 'dignitymemorial.com/aurora-on',
                'name'         => 'Dignity Memorial - Aurora',
                'base_url'     => 'https://www.dignitymemorial.com/obituaries/aurora-on',
                'adapter_type' => 'dignity_memorial',
                'city'         => 'Aurora',
                'region'       => 'York Region',
                'enabled'      => 0,
            ),
            // v3.13.0: DISABLED — dignitymemorial.com returns HTTP 403.
            array(
                'domain'       => 'dignitymemorial.com/richmond-hill-on',
                'name'         => 'Dignity Memorial - Richmond Hill',
                'base_url'     => 'https://www.dignitymemorial.com/obituaries/richmond-hill-on',
                'adapter_type' => 'dignity_memorial',
                'city'         => 'Richmond Hill',
                'region'       => 'York Region',
                'enabled'      => 0,
            ),
            // York Region aggregators (highest volume local sources)
            array(
                'domain'       => 'obituaries.yorkregion.com',
                'name'         => 'York Region News Obituaries',
                'base_url'     => 'https://obituaries.yorkregion.com/obituaries/obituaries/search',
                'adapter_type' => 'remembering_ca',
                'city'         => '',
                'region'       => 'York Region',
            ),
            // v3.13.0: DISABLED — legacy.com returns HTTP 403 (WAF/bot protection).
            // Confirmed via Rescan-Only diagnostics 2026-02-11.
            array(
                'domain'       => 'legacy.com/ca/obituaries/yorkregion',
                'name'         => 'Legacy.com - York Region',
                'base_url'     => 'https://www.legacy.com/ca/obituaries/yorkregion/today',
                'adapter_type' => 'legacy_com',
                'city'         => '',
                'region'       => 'York Region',
                'enabled'      => 0,
            ),

            // ══════════════════════════════════════════════════════════════
            // PRIORITY 2 — Greater Toronto Area (GTA)
            // Drives broader regional traffic + positions Monaco Monuments
            // as the Ontario obituary resource.
            // ══════════════════════════════════════════════════════════════
            // v3.13.0: DISABLED — dignitymemorial.com returns HTTP 403.
            array(
                'domain'       => 'dignitymemorial.com/toronto-on',
                'name'         => 'Dignity Memorial - Toronto',
                'base_url'     => 'https://www.dignitymemorial.com/obituaries/toronto-on',
                'adapter_type' => 'dignity_memorial',
                'city'         => 'Toronto',
                'region'       => 'Greater Toronto Area',
                'enabled'      => 0,
            ),
            // v3.13.0: DISABLED — dignitymemorial.com returns HTTP 403.
            array(
                'domain'       => 'dignitymemorial.com/markham-on',
                'name'         => 'Dignity Memorial - Markham',
                'base_url'     => 'https://www.dignitymemorial.com/obituaries/markham-on',
                'adapter_type' => 'dignity_memorial',
                'city'         => 'Markham',
                'region'       => 'York Region',
                'enabled'      => 0,
            ),
            // v3.13.0: DISABLED — dignitymemorial.com returns HTTP 403.
            array(
                'domain'       => 'dignitymemorial.com/vaughan-on',
                'name'         => 'Dignity Memorial - Vaughan',
                'base_url'     => 'https://www.dignitymemorial.com/obituaries/vaughan-on',
                'adapter_type' => 'dignity_memorial',
                'city'         => 'Vaughan',
                'region'       => 'York Region',
                'enabled'      => 0,
            ),
            // v3.13.0: DISABLED — legacy.com returns HTTP 403.
            array(
                'domain'       => 'legacy.com/ca/obituaries/thestar',
                'name'         => 'Legacy.com - Toronto Star',
                'base_url'     => 'https://www.legacy.com/ca/obituaries/thestar/today',
                'adapter_type' => 'legacy_com',
                'city'         => '',
                'region'       => 'Greater Toronto Area',
                'enabled'      => 0,
            ),
            // v3.9.0: DISABLED — remembering.ca/obituaries/toronto-on returns HTTP 404.
            array(
                'domain'       => 'remembering.ca/toronto',
                'name'         => 'Remembering.ca - Toronto',
                'base_url'     => 'https://www.remembering.ca/obituaries/toronto-on',
                'adapter_type' => 'remembering_ca',
                'city'         => '',
                'region'       => 'Greater Toronto Area',
                'enabled'      => 0,
            ),

            // ══════════════════════════════════════════════════════════════
            // PRIORITY 3 — Ontario-wide (provincial coverage for SEO reach)
            // ══════════════════════════════════════════════════════════════

            // v3.13.0: ALL Legacy.com sources DISABLED — HTTP 403 (WAF/bot protection).
            // Confirmed via Rescan-Only diagnostics 2026-02-11. Domain keys preserved
            // in the DB for history; can be re-enabled if access is restored.

            // Hamilton / Niagara
            array(
                'domain'       => 'legacy.com/ca/obituaries/thespec',
                'name'         => 'Legacy.com - Hamilton Spectator',
                'base_url'     => 'https://www.legacy.com/ca/obituaries/thespec/today',
                'adapter_type' => 'legacy_com',
                'city'         => '',
                'region'       => 'Hamilton',
                'enabled'      => 0,
            ),
            // Ottawa
            array(
                'domain'       => 'legacy.com/ca/obituaries/ottawacitizen',
                'name'         => 'Legacy.com - Ottawa Citizen',
                'base_url'     => 'https://www.legacy.com/ca/obituaries/ottawacitizen/today',
                'adapter_type' => 'legacy_com',
                'city'         => '',
                'region'       => 'Ottawa',
                'enabled'      => 0,
            ),
            // London
            array(
                'domain'       => 'legacy.com/ca/obituaries/lfpress',
                'name'         => 'Legacy.com - London Free Press',
                'base_url'     => 'https://www.legacy.com/ca/obituaries/lfpress/today',
                'adapter_type' => 'legacy_com',
                'city'         => '',
                'region'       => 'London',
                'enabled'      => 0,
            ),
            // Kitchener / Waterloo
            array(
                'domain'       => 'legacy.com/ca/obituaries/therecord',
                'name'         => 'Legacy.com - Waterloo Region Record',
                'base_url'     => 'https://www.legacy.com/ca/obituaries/therecord/today',
                'adapter_type' => 'legacy_com',
                'city'         => '',
                'region'       => 'Kitchener-Waterloo',
                'enabled'      => 0,
            ),
            // Barrie / Simcoe
            array(
                'domain'       => 'legacy.com/ca/obituaries/barrieexaminer',
                'name'         => 'Legacy.com - Barrie Examiner',
                'base_url'     => 'https://www.legacy.com/ca/obituaries/barrieexaminer/today',
                'adapter_type' => 'legacy_com',
                'city'         => '',
                'region'       => 'Barrie',
                'enabled'      => 0,
            ),
            // Windsor
            array(
                'domain'       => 'legacy.com/ca/obituaries/windsorstar',
                'name'         => 'Legacy.com - Windsor Star',
                'base_url'     => 'https://www.legacy.com/ca/obituaries/windsorstar/today',
                'adapter_type' => 'legacy_com',
                'city'         => '',
                'region'       => 'Windsor',
                'enabled'      => 0,
            ),

            // ══════════════════════════════════════════════════════════════
            // PRIORITY 4 — Postmedia / Remembering.ca Network (v4.0.0)
            // Same HTML structure as obituaries.yorkregion.com. Uses the
            // existing remembering_ca adapter with zero code changes.
            // Confirmed reachable (HTTP 200) and parseable 2026-02-13.
            // ══════════════════════════════════════════════════════════════

            // Toronto Star — GTA's largest newspaper obituary section.
            // ~26 obituaries per page, covers all of Greater Toronto.
            array(
                'domain'       => 'obituaries.thestar.com',
                'name'         => 'Toronto Star Obituaries',
                'base_url'     => 'https://obituaries.thestar.com/obituaries/obituaries/search',
                'adapter_type' => 'remembering_ca',
                'city'         => '',
                'region'       => 'Greater Toronto Area',
            ),
            // Kitchener-Waterloo Record — Tri-cities region.
            array(
                'domain'       => 'obituaries.therecord.com',
                'name'         => 'Waterloo Region Record Obituaries',
                'base_url'     => 'https://obituaries.therecord.com/obituaries/obituaries/search',
                'adapter_type' => 'remembering_ca',
                'city'         => '',
                'region'       => 'Kitchener-Waterloo',
            ),
            // Hamilton Spectator — Hamilton / Burlington / Dundas area.
            array(
                'domain'       => 'obituaries.thespec.com',
                'name'         => 'Hamilton Spectator Obituaries',
                'base_url'     => 'https://obituaries.thespec.com/obituaries/obituaries/search',
                'adapter_type' => 'remembering_ca',
                'city'         => '',
                'region'       => 'Hamilton',
            ),
            // Simcoe.com — Barrie / Orillia / Simcoe County region.
            array(
                'domain'       => 'obituaries.simcoe.com',
                'name'         => 'Simcoe County Obituaries',
                'base_url'     => 'https://obituaries.simcoe.com/obituaries/obituaries/search',
                'adapter_type' => 'remembering_ca',
                'city'         => '',
                'region'       => 'Barrie',
            ),
            // Niagara Falls Review — Niagara Region.
            array(
                'domain'       => 'obituaries.niagarafallsreview.ca',
                'name'         => 'Niagara Falls Review Obituaries',
                'base_url'     => 'https://obituaries.niagarafallsreview.ca/obituaries/obituaries/search',
                'adapter_type' => 'remembering_ca',
                'city'         => '',
                'region'       => 'Niagara',
            ),
            // St. Catharines Standard — St. Catharines / Niagara region.
            array(
                'domain'       => 'obituaries.stcatharinesstandard.ca',
                'name'         => 'St. Catharines Standard Obituaries',
                'base_url'     => 'https://obituaries.stcatharinesstandard.ca/obituaries/obituaries/search',
                'adapter_type' => 'remembering_ca',
                'city'         => '',
                'region'       => 'St. Catharines',
            ),
        );

        foreach ( $sources as $source ) {
            self::upsert_source( $source );
        }
    }
}
