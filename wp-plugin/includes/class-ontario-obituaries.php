<?php
/**
 * The main plugin class
 *
 * ARCH-01 FIX: This is now the single admin menu registration point
 * BUG-04 FIX: scrape() return type handled correctly
 * BUG-10 FIX: Admin actions processed before HTML output
 * SEC-02 FIX: All admin GET actions now require nonce verification
 * P0-1  FIX: Unified settings group = 'ontario_obituaries_settings' everywhere
 * P1-6  FIX: REST API returns whitelisted / sanitized shape
 * FIX-B/C  : Facebook sharing uses sharer-only (no SDK/App ID required)
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ontario_Obituaries {

    /** @var Ontario_Obituaries_Display */
    private $display;

    /** @var Ontario_Obituaries_Reset_Rescan|null Shared instance (set by ontario_obituaries_includes). */
    private static $reset_rescan_instance = null;

    /**
     * Store the shared Reset & Rescan instance.
     * Called once from ontario_obituaries_includes() to avoid double instantiation.
     *
     * @param Ontario_Obituaries_Reset_Rescan $instance The initialized instance.
     */
    public static function set_reset_rescan_instance( $instance ) {
        self::$reset_rescan_instance = $instance;
    }

    /**
     * Initialize the plugin.
     */
    public function __construct() {
        $this->display = new Ontario_Obituaries_Display();

        // ARCH-01: single admin menu registration point
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );

        // Shortcode
        add_shortcode( 'ontario_obituaries', array( $this, 'render_shortcode' ) );
        // NOTE: Do NOT register [obituaries] as an alias — the /obituaries/
        // Elementor page contains literal "[obituaries]" text that was never
        // meant to be a shortcode. Activating it breaks that page's layout.

        // Assets
        add_action( 'wp_enqueue_scripts',    array( $this, 'enqueue_scripts' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );

        // REST API
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

        // Settings — P0-1 FIX: single registration with unified group
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    /* ────────────────────────── ADMIN MENU ─────────────────────────────── */

    /**
     * Register admin menu (ARCH-01: consolidated single menu).
     */
    public function register_admin_menu() {
        add_menu_page(
            __( 'Ontario Obituaries', 'ontario-obituaries' ),
            __( 'Ontario Obituaries', 'ontario-obituaries' ),
            'manage_options',
            'ontario-obituaries',
            array( $this, 'admin_page' ),
            'dashicons-list-view',
            30
        );

        add_submenu_page(
            'ontario-obituaries',
            __( 'Settings', 'ontario-obituaries' ),
            __( 'Settings', 'ontario-obituaries' ),
            'manage_options',
            'ontario-obituaries-settings',
            array( $this, 'settings_page' )
        );

        // v3.11.0: Reset & Rescan tool
        add_submenu_page(
            'ontario-obituaries',
            __( 'Reset & Rescan', 'ontario-obituaries' ),
            __( 'Reset & Rescan', 'ontario-obituaries' ),
            'manage_options',
            'ontario-obituaries-reset-rescan',
            array( $this, 'reset_rescan_page' )
        );
    }

    /* ────────────────────────── SETTINGS ───────────────────────────────── */

    /**
     * Register plugin settings.
     * P0-1 FIX: group = 'ontario_obituaries_settings' to match settings_fields() call.
     */
    public function register_settings() {
        register_setting(
            'ontario_obituaries_settings',        // option group
            'ontario_obituaries_settings',        // option name
            array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) )
        );
    }

    /**
     * Sanitize settings callback.
     *
     * @param array $input Raw input.
     * @return array Sanitized.
     */
    public function sanitize_settings( $input ) {
        $sanitized = array();

        // Booleans
        foreach ( array( 'enabled', 'auto_publish', 'notify_admin', 'adaptive_mode', 'debug_logging', 'fuzzy_dedupe', 'ai_rewrite_enabled', 'gofundme_enabled', 'authenticity_enabled' ) as $key ) {
            $sanitized[ $key ] = ! empty( $input[ $key ] );
        }

        // Text
        foreach ( array( 'time', 'filter_keywords' ) as $key ) {
            $sanitized[ $key ] = isset( $input[ $key ] ) ? sanitize_text_field( $input[ $key ] ) : '';
        }

        // v4.2.3: Groq API key — stored separately in its own option for security.
        if ( isset( $input['groq_api_key'] ) ) {
            $key_value = sanitize_text_field( $input['groq_api_key'] );
            if ( ! empty( $key_value ) ) {
                update_option( 'ontario_obituaries_groq_api_key', $key_value );
            } elseif ( empty( $key_value ) && isset( $input['groq_api_key_clear'] ) ) {
                delete_option( 'ontario_obituaries_groq_api_key' );
            }
        }

        // v4.2.4: When AI rewrites are enabled AND a key is set, schedule the
        // first rewrite batch immediately (30s delay). Previously the batch only
        // fired after a collection event, meaning enabling AI rewrites wouldn't
        // do anything until the next 12h cron cycle.
        $groq_key = get_option( 'ontario_obituaries_groq_api_key', '' );
        if ( ! empty( $sanitized['ai_rewrite_enabled'] ) && ! empty( $groq_key ) ) {
            if ( ! wp_next_scheduled( 'ontario_obituaries_ai_rewrite_batch' ) ) {
                wp_schedule_single_event( time() + 30, 'ontario_obituaries_ai_rewrite_batch' );
                if ( function_exists( 'ontario_obituaries_log' ) ) {
                    ontario_obituaries_log( 'v4.2.4: AI Rewrite batch scheduled (30s) — triggered by settings save.', 'info' );
                }
            }
        }

        // v4.3.0: Trigger GoFundMe batch immediately on settings save if enabled.
        if ( ! empty( $sanitized['gofundme_enabled'] ) ) {
            if ( ! wp_next_scheduled( 'ontario_obituaries_gofundme_batch' ) ) {
                wp_schedule_single_event( time() + 60, 'ontario_obituaries_gofundme_batch' );
                if ( function_exists( 'ontario_obituaries_log' ) ) {
                    ontario_obituaries_log( 'v4.3.0: GoFundMe batch scheduled (60s) — triggered by settings save.', 'info' );
                }
            }
        }

        // v4.3.0: Trigger Authenticity audit on settings save if enabled.
        if ( ! empty( $sanitized['authenticity_enabled'] ) && ! empty( $groq_key ) ) {
            if ( ! wp_next_scheduled( 'ontario_obituaries_authenticity_audit' ) ) {
                wp_schedule_single_event( time() + 120, 'ontario_obituaries_authenticity_audit' );
                if ( function_exists( 'ontario_obituaries_log' ) ) {
                    ontario_obituaries_log( 'v4.3.0: Authenticity audit scheduled (120s) — triggered by settings save.', 'info' );
                }
            }
        }

        // Numeric with bounds
        $sanitized['max_age']         = isset( $input['max_age'] )         ? max( 1, min( 365, intval( $input['max_age'] ) ) )         : 7;
        $sanitized['retry_attempts']  = isset( $input['retry_attempts'] )  ? max( 1, min( 10,  intval( $input['retry_attempts'] ) ) )  : 3;
        $sanitized['timeout']         = isset( $input['timeout'] )         ? max( 5, min( 120, intval( $input['timeout'] ) ) )         : 30;

        // Whitelist select
        $allowed_frequencies  = array( 'hourly', 'daily', 'twicedaily', 'weekly' );
        $sanitized['frequency'] = ( isset( $input['frequency'] ) && in_array( $input['frequency'], $allowed_frequencies, true ) )
            ? $input['frequency']
            : 'daily';

        // Array: regions
        $sanitized['regions'] = ( isset( $input['regions'] ) && is_array( $input['regions'] ) )
            ? array_map( 'sanitize_text_field', $input['regions'] )
            : array( 'Toronto', 'Ottawa', 'Hamilton' );

        // Reschedule cron when frequency or time changes (P0-4 FIX)
        $old = ontario_obituaries_get_settings();
        $freq_changed = ( isset( $old['frequency'] ) && $old['frequency'] !== $sanitized['frequency'] );
        $time_changed = ( isset( $old['time'] )      && $old['time']      !== $sanitized['time'] );

        if ( $freq_changed || $time_changed ) {
            // P0-1 QC FIX: Clear ALL scheduled instances, not just the next one
            wp_clear_scheduled_hook( 'ontario_obituaries_collection_event' );
            $next      = ontario_obituaries_next_cron_timestamp( $sanitized['time'] );
            $scheduled = wp_schedule_event( $next, $sanitized['frequency'], 'ontario_obituaries_collection_event' );
            if ( false === $scheduled || is_wp_error( $scheduled ) ) {
                ontario_obituaries_log(
                    'Failed to reschedule cron after settings change (frequency: ' . $sanitized['frequency'] . ', time: ' . $sanitized['time'] . ')',
                    'error'
                );
            }
        }

        return $sanitized;
    }

    /* ────────────────────────── ADMIN PAGE ─────────────────────────────── */

    /**
     * Display the main admin page.
     * BUG-10 FIX: process actions before output.
     * SEC-02 FIX: nonce-protected GET actions.
     */
    public function admin_page() {
        $action_result = null;
        $action_type   = null;

        if ( isset( $_GET['action'], $_GET['_wpnonce'] ) ) {
            if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'ontario_obituaries_admin_action' ) ) {
                switch ( sanitize_text_field( wp_unslash( $_GET['action'] ) ) ) {
                    case 'add_test':
                        $action_type   = 'add_test';
                        $action_result = $this->add_test_data();
                        break;
                    case 'scrape':
                        $action_type   = 'scrape';
                        $action_result = $this->run_scrape();
                        break;
                }
            }
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Ontario Obituaries', 'ontario-obituaries' ); ?></h1>

            <?php if ( 'add_test' === $action_type && null !== $action_result ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php printf( esc_html__( 'Added %d test obituaries successfully.', 'ontario-obituaries' ), intval( $action_result ) ); ?></p>
                </div>
            <?php elseif ( 'scrape' === $action_type && null !== $action_result ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php printf(
                        esc_html__( 'Scraper completed. Found %d obituaries, added %d new obituaries.', 'ontario-obituaries' ),
                        intval( $action_result['obituaries_found'] ),
                        intval( $action_result['obituaries_added'] )
                    ); ?></p>
                </div>
                <?php if ( ! empty( $action_result['errors'] ) ) : ?>
                    <div class="notice notice-warning is-dismissible"><ul>
                        <?php foreach ( $action_result['errors'] as $source_id => $errors ) :
                            foreach ( (array) $errors as $error ) : ?>
                                <li><strong><?php echo esc_html( $source_id ); ?></strong>: <?php echo esc_html( $error ); ?></li>
                            <?php endforeach;
                        endforeach; ?>
                    </ul></div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="card">
                <h2><?php esc_html_e( 'Recent Obituaries', 'ontario-obituaries' ); ?></h2>
                <p><?php esc_html_e( 'Here are the most recently added obituaries:', 'ontario-obituaries' ); ?></p>

                <?php
                $obituaries = $this->get_recent_obituaries();

                if ( ! empty( $obituaries ) ) : ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Name', 'ontario-obituaries' ); ?></th>
                                <th><?php esc_html_e( 'Date of Death', 'ontario-obituaries' ); ?></th>
                                <th><?php esc_html_e( 'Location', 'ontario-obituaries' ); ?></th>
                                <th><?php esc_html_e( 'Funeral Home', 'ontario-obituaries' ); ?></th>
                                <th><?php esc_html_e( 'Actions', 'ontario-obituaries' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $obituaries as $obituary ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $obituary->name ); ?></td>
                                    <td><?php echo esc_html( $obituary->date_of_death ); ?></td>
                                    <td><?php echo esc_html( $obituary->location ); ?></td>
                                    <td><?php echo esc_html( $obituary->funeral_home ); ?></td>
                                    <td>
                                        <a href="#" class="ontario-obituaries-view" data-id="<?php echo esc_attr( $obituary->id ); ?>"><?php esc_html_e( 'View', 'ontario-obituaries' ); ?></a> |
                                        <a href="#" class="ontario-obituaries-delete" data-id="<?php echo esc_attr( $obituary->id ); ?>"><?php esc_html_e( 'Delete', 'ontario-obituaries' ); ?></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p><?php esc_html_e( 'No obituaries found. Add some test data by clicking the button below.', 'ontario-obituaries' ); ?></p>
                <?php endif; ?>

                <?php
                $add_test_url = wp_nonce_url(
                    admin_url( 'admin.php?page=ontario-obituaries&action=add_test' ),
                    'ontario_obituaries_admin_action'
                );
                $scrape_url = wp_nonce_url(
                    admin_url( 'admin.php?page=ontario-obituaries&action=scrape' ),
                    'ontario_obituaries_admin_action'
                );
                ?>
                <p>
                    <a href="<?php echo esc_url( $add_test_url ); ?>" class="button button-primary"><?php esc_html_e( 'Add Test Data', 'ontario-obituaries' ); ?></a>
                    <a href="<?php echo esc_url( $scrape_url ); ?>" class="button"><?php esc_html_e( 'Run Manual Scrape', 'ontario-obituaries' ); ?></a>
                </p>
            </div>

            <div class="card">
                <h2><?php esc_html_e( 'Using the Shortcode', 'ontario-obituaries' ); ?></h2>
                <p><?php esc_html_e( 'Use the shortcode [ontario_obituaries] to display obituaries on any page or post.', 'ontario-obituaries' ); ?></p>
                <p><?php esc_html_e( 'Optional parameters:', 'ontario-obituaries' ); ?></p>
                <ul>
                    <li><code>limit</code> - <?php esc_html_e( 'Number of obituaries to display (default: 20)', 'ontario-obituaries' ); ?></li>
                    <li><code>location</code> - <?php esc_html_e( 'Filter by location (default: all locations)', 'ontario-obituaries' ); ?></li>
                    <li><code>funeral_home</code> - <?php esc_html_e( 'Filter by funeral home (default: all funeral homes)', 'ontario-obituaries' ); ?></li>
                    <li><code>days</code> - <?php esc_html_e( 'Only show obituaries from the last X days (default: 0, show all)', 'ontario-obituaries' ); ?></li>
                </ul>
                <p><?php esc_html_e( 'Example:', 'ontario-obituaries' ); ?> <code>[ontario_obituaries limit="12" location="Toronto" days="60"]</code></p>
            </div>

            <?php if ( function_exists( 'ontario_obituaries_check_dependency' ) && ontario_obituaries_check_dependency() ) : ?>
            <div class="card">
                <h2><?php esc_html_e( 'Obituary Assistant Integration', 'ontario-obituaries' ); ?></h2>
                <p><?php esc_html_e( 'This plugin is integrated with Obituary Assistant for enhanced functionality.', 'ontario-obituaries' ); ?></p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ────────────────────────── SETTINGS PAGE ─────────────────────────── */

    /**
     * Display the settings page.
     */
    public function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'ontario-obituaries' ) );
        }

        $settings = ontario_obituaries_get_settings();

        // Handle manual scrape (POST, before output — BUG-10 FIX)
        $scrape_results = null;
        if ( isset( $_POST['ontario_obituaries_scrape'] ) && check_admin_referer( 'ontario_obituaries_manual_scrape', 'ontario_obituaries_nonce' ) ) {
            $scraper = new Ontario_Obituaries_Scraper();
            $scrape_results = $scraper->collect();

            // Persist last collection
            update_option( 'ontario_obituaries_last_collection', array(
                'timestamp' => current_time( 'mysql' ),
                'completed' => current_time( 'mysql' ),
                'results'   => $scrape_results,
            ) );

            delete_transient( 'ontario_obituaries_locations_cache' );
            delete_transient( 'ontario_obituaries_funeral_homes_cache' );
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Ontario Obituaries Settings', 'ontario-obituaries' ); ?></h1>

            <?php if ( null !== $scrape_results ) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php printf(
                        esc_html__( 'Scraper completed. Found %d obituaries, added %d new obituaries.', 'ontario-obituaries' ),
                        intval( $scrape_results['obituaries_found'] ),
                        intval( $scrape_results['obituaries_added'] )
                    ); ?>
                </p></div>
                <?php if ( ! empty( $scrape_results['errors'] ) ) : ?>
                    <div class="notice notice-warning is-dismissible"><ul>
                        <?php foreach ( $scrape_results['errors'] as $source_id => $errors ) :
                            foreach ( (array) $errors as $error ) : ?>
                                <li><strong><?php echo esc_html( $source_id ); ?></strong>: <?php echo esc_html( $error ); ?></li>
                            <?php endforeach;
                        endforeach; ?>
                    </ul></div>
                <?php endif; ?>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php
                // P0-1 FIX: group matches register_setting() group
                settings_fields( 'ontario_obituaries_settings' );
                ?>

                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e( 'Enable Scraper', 'ontario-obituaries' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="ontario_obituaries_settings[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
                                <?php esc_html_e( 'Automatically collect obituaries from Ontario', 'ontario-obituaries' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e( 'Scrape Frequency', 'ontario-obituaries' ); ?></th>
                        <td>
                            <select name="ontario_obituaries_settings[frequency]">
                                <option value="hourly" <?php selected( $settings['frequency'], 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'ontario-obituaries' ); ?></option>
                                <option value="twicedaily" <?php selected( $settings['frequency'], 'twicedaily' ); ?>><?php esc_html_e( 'Twice Daily', 'ontario-obituaries' ); ?></option>
                                <option value="daily" <?php selected( $settings['frequency'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'ontario-obituaries' ); ?></option>
                                <option value="weekly" <?php selected( $settings['frequency'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'ontario-obituaries' ); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e( 'How often to check for new obituaries', 'ontario-obituaries' ); ?></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e( 'Scrape Time', 'ontario-obituaries' ); ?></th>
                        <td>
                            <input type="time" name="ontario_obituaries_settings[time]" value="<?php echo esc_attr( $settings['time'] ); ?>" />
                            <p class="description"><?php esc_html_e( 'When to run the scraper (24h format, site timezone)', 'ontario-obituaries' ); ?></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e( 'Regions to Scrape', 'ontario-obituaries' ); ?></th>
                        <td>
                            <?php
                            $all_regions = array( 'Toronto', 'Ottawa', 'Hamilton', 'London', 'Windsor', 'Kitchener', 'Sudbury', 'Thunder Bay' );
                            foreach ( $all_regions as $region ) :
                            ?>
                                <label style="display: block; margin-bottom: 8px;">
                                    <input type="checkbox" name="ontario_obituaries_settings[regions][]" value="<?php echo esc_attr( $region ); ?>" <?php checked( in_array( $region, (array) $settings['regions'], true ) ); ?> />
                                    <?php echo esc_html( $region ); ?>
                                </label>
                            <?php endforeach; ?>
                            <p class="description"><?php esc_html_e( 'Select which regions to collect obituaries from', 'ontario-obituaries' ); ?></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e( 'Auto-Publish', 'ontario-obituaries' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="ontario_obituaries_settings[auto_publish]" value="1" <?php checked( ! empty( $settings['auto_publish'] ) ); ?> />
                                <?php esc_html_e( 'Automatically publish new obituaries without review', 'ontario-obituaries' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e( 'Email Notifications', 'ontario-obituaries' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="ontario_obituaries_settings[notify_admin]" value="1" <?php checked( ! empty( $settings['notify_admin'] ) ); ?> />
                                <?php esc_html_e( 'Send email when new obituaries are found', 'ontario-obituaries' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e( 'Retention Period (days)', 'ontario-obituaries' ); ?></th>
                        <td>
                            <input type="number" name="ontario_obituaries_settings[max_age]" value="<?php echo esc_attr( $settings['max_age'] ); ?>" min="1" max="365" />
                            <p class="description"><?php esc_html_e( 'How many days to keep obituaries before archiving', 'ontario-obituaries' ); ?></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e( 'Filtering Keywords', 'ontario-obituaries' ); ?></th>
                        <td>
                            <input type="text" class="regular-text" name="ontario_obituaries_settings[filter_keywords]" value="<?php echo esc_attr( $settings['filter_keywords'] ); ?>" placeholder="<?php esc_attr_e( 'Enter comma-separated terms', 'ontario-obituaries' ); ?>" />
                            <p class="description"><?php esc_html_e( 'Optional: Filter obituaries containing these terms', 'ontario-obituaries' ); ?></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e( 'Debug Logging', 'ontario-obituaries' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="ontario_obituaries_settings[debug_logging]" value="1" <?php checked( ! empty( $settings['debug_logging'] ) ); ?> />
                                <?php esc_html_e( 'Enable detailed logging to debug.log (useful for troubleshooting)', 'ontario-obituaries' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e( 'Fuzzy Deduplication', 'ontario-obituaries' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="ontario_obituaries_settings[fuzzy_dedupe]" value="1" <?php checked( ! empty( $settings['fuzzy_dedupe'] ) ); ?> />
                                <?php esc_html_e( 'Enable fuzzy duplicate detection (may reduce near-duplicates but uses slower queries)', 'ontario-obituaries' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <h2 style="margin-top:30px;"><?php esc_html_e( 'AI Rewrite Engine', 'ontario-obituaries' ); ?></h2>
                <p class="description" style="margin-bottom:15px;">
                    <?php esc_html_e( 'Automatically rewrites obituary text into unique, professional prose to avoid copyright issues. Uses Groq API (free tier, no credit card needed).', 'ontario-obituaries' ); ?>
                </p>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e( 'Enable AI Rewrites', 'ontario-obituaries' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="ontario_obituaries_settings[ai_rewrite_enabled]" value="1" <?php checked( ! empty( $settings['ai_rewrite_enabled'] ) ); ?> />
                                <?php esc_html_e( 'Automatically rewrite obituaries after each collection', 'ontario-obituaries' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'Requires a Groq API key below. Rewrites 25 obituaries per batch, 1 request every 6 seconds.', 'ontario-obituaries' ); ?></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e( 'Groq API Key', 'ontario-obituaries' ); ?></th>
                        <td>
                            <?php
                            $groq_key = get_option( 'ontario_obituaries_groq_api_key', '' );
                            $key_masked = ! empty( $groq_key ) ? substr( $groq_key, 0, 8 ) . str_repeat( '*', max( 0, strlen( $groq_key ) - 12 ) ) . substr( $groq_key, -4 ) : '';
                            ?>
                            <input type="text" class="regular-text" name="ontario_obituaries_settings[groq_api_key]" value="" placeholder="<?php echo esc_attr( ! empty( $key_masked ) ? $key_masked : 'gsk_...' ); ?>" autocomplete="off" />
                            <?php if ( ! empty( $groq_key ) ) : ?>
                                <span style="color:green;">&#10003; <?php esc_html_e( 'Key is set', 'ontario-obituaries' ); ?></span>
                            <?php else : ?>
                                <span style="color:red;">&#10007; <?php esc_html_e( 'No key set', 'ontario-obituaries' ); ?></span>
                            <?php endif; ?>
                            <p class="description">
                                <?php printf(
                                    esc_html__( 'Get a free API key at %s (no credit card needed). Paste it here and click Save. Leave blank to keep the current key.', 'ontario-obituaries' ),
                                    '<a href="https://console.groq.com" target="_blank">console.groq.com</a>'
                                ); ?>
                            </p>
                        </td>
                    </tr>
                    <?php if ( ! empty( $groq_key ) ) : ?>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e( 'AI Rewrite Status', 'ontario-obituaries' ); ?></th>
                        <td>
                            <?php
                            if ( class_exists( 'Ontario_Obituaries_AI_Rewriter' ) ) {
                                $rewriter = new Ontario_Obituaries_AI_Rewriter();
                                $stats    = $rewriter->get_stats();
                                printf(
                                    '<strong>%s</strong> total &nbsp;|&nbsp; <strong style="color:green;">%s</strong> rewritten &nbsp;|&nbsp; <strong style="color:orange;">%s</strong> pending &nbsp;|&nbsp; <strong>%s%%</strong> complete',
                                    esc_html( number_format_i18n( $stats['total'] ) ),
                                    esc_html( number_format_i18n( $stats['rewritten'] ) ),
                                    esc_html( number_format_i18n( $stats['pending'] ) ),
                                    esc_html( number_format_i18n( $stats['percent_complete'], 1 ) )
                                );
                            } else {
                                esc_html_e( 'AI Rewriter class not loaded.', 'ontario-obituaries' );
                            }
                            ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>

                <h2 style="margin-top:30px;"><?php esc_html_e( 'GoFundMe Auto-Linker', 'ontario-obituaries' ); ?></h2>
                <p class="description" style="margin-bottom:15px;">
                    <?php esc_html_e( 'Automatically searches GoFundMe for memorial/funeral campaigns matching your obituary records. Uses strict 3-point verification (name + death date + location) to prevent false matches. No API key required.', 'ontario-obituaries' ); ?>
                </p>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e( 'Enable GoFundMe Linking', 'ontario-obituaries' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="ontario_obituaries_settings[gofundme_enabled]" value="1" <?php checked( ! empty( $settings['gofundme_enabled'] ) ); ?> />
                                <?php esc_html_e( 'Automatically search for matching GoFundMe campaigns', 'ontario-obituaries' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'Checks 20 obituaries per batch, 1 search every 3 seconds. Verified links appear on memorial pages as a "Support the Family" button.', 'ontario-obituaries' ); ?></p>
                        </td>
                    </tr>
                    <?php if ( ! empty( $settings['gofundme_enabled'] ) ) : ?>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e( 'GoFundMe Status', 'ontario-obituaries' ); ?></th>
                        <td>
                            <?php
                            if ( class_exists( 'Ontario_Obituaries_GoFundMe_Linker' ) ) {
                                $linker     = new Ontario_Obituaries_GoFundMe_Linker();
                                $gfm_stats  = $linker->get_stats();
                                printf(
                                    '<strong>%s</strong> total &nbsp;|&nbsp; <strong style="color:green;">%s</strong> matched &nbsp;|&nbsp; <strong>%s</strong> checked &nbsp;|&nbsp; <strong style="color:orange;">%s</strong> pending',
                                    esc_html( number_format_i18n( $gfm_stats['total'] ) ),
                                    esc_html( number_format_i18n( $gfm_stats['matched'] ) ),
                                    esc_html( number_format_i18n( $gfm_stats['checked'] ) ),
                                    esc_html( number_format_i18n( $gfm_stats['pending'] ) )
                                );
                            }
                            ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>

                <h2 style="margin-top:30px;"><?php esc_html_e( 'AI Authenticity Checker', 'ontario-obituaries' ); ?></h2>
                <p class="description" style="margin-bottom:15px;">
                    <?php esc_html_e( 'Runs 24/7 random audits of obituary data using AI to verify dates, names, locations, and cross-reference consistency. Flags and optionally auto-corrects issues. Uses the same Groq API key as the AI Rewriter.', 'ontario-obituaries' ); ?>
                </p>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e( 'Enable Authenticity Checks', 'ontario-obituaries' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="ontario_obituaries_settings[authenticity_enabled]" value="1" <?php checked( ! empty( $settings['authenticity_enabled'] ) ); ?> />
                                <?php esc_html_e( 'Run automated data quality audits every 4 hours', 'ontario-obituaries' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'Audits 10 random obituaries per cycle (8 never-audited + 2 re-checks). Requires Groq API key. Flags issues for admin review and auto-corrects high-confidence errors.', 'ontario-obituaries' ); ?></p>
                        </td>
                    </tr>
                    <?php if ( ! empty( $settings['authenticity_enabled'] ) && ! empty( $groq_key ) ) : ?>
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e( 'Audit Status', 'ontario-obituaries' ); ?></th>
                        <td>
                            <?php
                            if ( class_exists( 'Ontario_Obituaries_Authenticity_Checker' ) ) {
                                $checker      = new Ontario_Obituaries_Authenticity_Checker();
                                $audit_stats  = $checker->get_stats();
                                printf(
                                    '<strong>%s</strong> total &nbsp;|&nbsp; <strong style="color:green;">%s</strong> passed &nbsp;|&nbsp; <strong style="color:red;">%s</strong> flagged &nbsp;|&nbsp; <strong style="color:orange;">%s</strong> never audited',
                                    esc_html( number_format_i18n( $audit_stats['total'] ) ),
                                    esc_html( number_format_i18n( $audit_stats['passed'] ) ),
                                    esc_html( number_format_i18n( $audit_stats['flagged'] ) ),
                                    esc_html( number_format_i18n( $audit_stats['never_audited'] ) )
                                );

                                // Show recently flagged items.
                                $flagged = $checker->get_flagged( 5 );
                                if ( ! empty( $flagged ) ) {
                                    echo '<div style="margin-top:10px;padding:10px;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;">';
                                    echo '<strong>' . esc_html__( 'Recently Flagged:', 'ontario-obituaries' ) . '</strong><ul style="margin:5px 0 0 15px;">';
                                    foreach ( $flagged as $f ) {
                                        $flags = json_decode( $f->audit_flags, true );
                                        $flag_text = is_array( $flags ) ? implode( '; ', array_slice( $flags, 0, 2 ) ) : '';
                                        printf(
                                            '<li><strong>ID %d</strong> %s — <em>%s</em></li>',
                                            intval( $f->id ),
                                            esc_html( $f->name ),
                                            esc_html( $flag_text )
                                        );
                                    }
                                    echo '</ul></div>';
                                }
                            }
                            ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>

                <?php submit_button( __( 'Save Settings', 'ontario-obituaries' ) ); ?>
            </form>

            <hr>

            <h2><?php esc_html_e( 'Manual Control', 'ontario-obituaries' ); ?></h2>
            <form method="post" action="">
                <?php wp_nonce_field( 'ontario_obituaries_manual_scrape', 'ontario_obituaries_nonce' ); ?>
                <p>
                    <input type="submit" name="ontario_obituaries_scrape" class="button button-primary" value="<?php esc_attr_e( 'Run Scraper Now', 'ontario-obituaries' ); ?>">
                </p>
            </form>

            <hr>

            <h2><?php esc_html_e( 'Last Scrape Information', 'ontario-obituaries' ); ?></h2>
            <?php
            $last_collection = get_option( 'ontario_obituaries_last_collection' );

            if ( $last_collection && isset( $last_collection['completed'] ) ) {
                echo '<p><strong>' . esc_html__( 'Last Run:', 'ontario-obituaries' ) . '</strong> ' . esc_html( $last_collection['completed'] ) . '</p>';

                if ( isset( $last_collection['results'] ) ) {
                    $results = $last_collection['results'];
                    echo '<p><strong>' . esc_html__( 'Results:', 'ontario-obituaries' ) . '</strong> ';
                    printf(
                        esc_html__( 'Found %d obituaries, added %d new obituaries.', 'ontario-obituaries' ),
                        intval( $results['obituaries_found'] ),
                        intval( $results['obituaries_added'] )
                    );
                    echo '</p>';
                }
            } else {
                echo '<p>' . esc_html__( 'No scrapes have been run yet.', 'ontario-obituaries' ) . '</p>';
            }
            ?>
        </div>
        <?php
    }

    /* ────────────────────── RESET & RESCAN PAGE ───────────────────────── */

    /**
     * Render the Reset & Rescan admin page.
     * v3.11.0: Delegates to the shared Ontario_Obituaries_Reset_Rescan instance
     *          (set by ontario_obituaries_includes) to avoid double instantiation.
     */
    public function reset_rescan_page() {
        if ( self::$reset_rescan_instance ) {
            self::$reset_rescan_instance->render_page();
        } else {
            // Defensive fallback — should never happen in normal flow.
            wp_die( esc_html__( 'Reset & Rescan handler not initialized.', 'ontario-obituaries' ) );
        }
    }

    /* ────────────────────────── SHORTCODE ──────────────────────────────── */

    /**
     * Render the [ontario_obituaries] shortcode.
     *
     * Sends LiteSpeed-specific cache-control headers to keep listing pages
     * fresh after dedup or scrape changes.  The shortcode page is set to
     * a short TTL (10 min) so LiteSpeed serves cached copies but refreshes
     * quickly when new obituaries arrive.
     */
    public function render_shortcode( $atts ) {
        // Tell LiteSpeed Cache to use a short TTL for this page
        // (keeps pages cached for performance, but refreshes after 10 min).
        if ( ! headers_sent() ) {
            header( 'X-LiteSpeed-Cache-Control: public,max-age=600' );
            header( 'X-LiteSpeed-Tag: ontario_obits,ontario_obits_listing' );
        }

        $atts = shortcode_atts( array(
            'limit'        => 20,
            'location'     => '',
            'funeral_home' => '',
            'days'         => 0,
        ), $atts, 'ontario_obituaries' );

        return $this->display->render_obituaries( $atts );
    }

    /* ────────────────────────── ADMIN DATA ─────────────────────────────── */

    /**
     * Get recent obituaries for admin listing.
     *
     * @param int $limit Number of records.
     * @return array
     */
    public function get_recent_obituaries( $limit = 10 ) {
        return $this->display->get_obituaries( array(
            'limit'   => $limit,
            'orderby' => 'date_of_death',
            'order'   => 'DESC',
        ) );
    }

    /**
     * Add test data to the database.
     *
     * @return int Number of test records added.
     */
    private function add_test_data() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ontario_obituaries';

        $test_data = array(
            array(
                'name'          => 'John Test Smith',
                'date_of_birth' => gmdate( 'Y-m-d', strtotime( '-80 years' ) ),
                'date_of_death' => gmdate( 'Y-m-d', strtotime( '-2 days' ) ),
                'age'           => 80,
                'funeral_home'    => 'Toronto Funeral Services',
                'location'        => 'Toronto',
                'city_normalized' => 'Toronto',
                'image_url'       => '',
                'description'     => 'This is a test obituary for demonstration purposes. John enjoyed gardening, fishing, and spending time with his family.',
                'source_url'      => home_url(),
            ),
            array(
                'name'          => 'Mary Test Johnson',
                'date_of_birth' => gmdate( 'Y-m-d', strtotime( '-75 years' ) ),
                'date_of_death' => gmdate( 'Y-m-d', strtotime( '-3 days' ) ),
                'age'           => 75,
                'funeral_home'    => 'Ottawa Funeral Home',
                'location'        => 'Ottawa',
                'city_normalized' => 'Ottawa',
                'image_url'       => '',
                'description'     => 'This is another test obituary. Mary was a dedicated teacher for over 30 years and loved by all her students.',
                'source_url'      => home_url(),
            ),
            array(
                'name'          => 'Robert Test Williams',
                'date_of_birth' => gmdate( 'Y-m-d', strtotime( '-90 years' ) ),
                'date_of_death' => gmdate( 'Y-m-d', strtotime( '-1 day' ) ),
                'age'           => 90,
                'funeral_home'    => 'Hamilton Funeral Services',
                'location'        => 'Hamilton',
                'city_normalized' => 'Hamilton',
                'image_url'       => '',
                'description'     => 'Robert was a respected community leader and devoted family man. This is a test obituary for debugging purposes.',
                'source_url'      => home_url(),
            ),
        );

        $count = 0;

        foreach ( $test_data as $obituary ) {
            $result = $wpdb->insert(
                $table_name,
                array(
                    'name'            => $obituary['name'],
                    'date_of_birth'   => $obituary['date_of_birth'],
                    'date_of_death'   => $obituary['date_of_death'],
                    'age'             => $obituary['age'],
                    'funeral_home'    => $obituary['funeral_home'],
                    'location'        => $obituary['location'],
                    'city_normalized' => isset( $obituary['city_normalized'] ) ? $obituary['city_normalized'] : '',
                    'image_url'       => $obituary['image_url'],
                    'description'     => $obituary['description'],
                    'source_url'      => $obituary['source_url'],
                    'created_at'      => current_time( 'mysql' ),
                ),
                array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
            );

            if ( false !== $result ) {
                $count++;
            }
        }

        delete_transient( 'ontario_obituaries_locations_cache' );
        delete_transient( 'ontario_obituaries_funeral_homes_cache' );

        return $count;
    }

    /**
     * Run a manual scrape.
     *
     * @return array Results array.
     */
    private function run_scrape() {
        $scraper = new Ontario_Obituaries_Scraper();
        $results = $scraper->collect();

        update_option( 'ontario_obituaries_last_collection', array(
            'timestamp' => current_time( 'mysql' ),
            'completed' => current_time( 'mysql' ),
            'results'   => $results,
        ) );

        delete_transient( 'ontario_obituaries_locations_cache' );
        delete_transient( 'ontario_obituaries_funeral_homes_cache' );

        return $results;
    }

    /* ────────────────────────── REST HELPERS ──────────────────────────── */

    /**
     * Truncate a string to a maximum length (mbstring-safe with fallback).
     * P0-3 QC FIX: Avoids fatal on hosts without the mbstring extension.
     *
     * @param string $string    Input string.
     * @param int    $max_chars Maximum characters.
     * @param string $suffix    Appended when truncated.
     * @return string
     */
    private function safe_truncate( $string, $max_chars = 500, $suffix = '...' ) {
        $len_fn = function_exists( 'mb_strlen' ) ? 'mb_strlen' : 'strlen';
        $sub_fn = function_exists( 'mb_substr' ) ? 'mb_substr' : 'substr';

        if ( $len_fn( $string ) > $max_chars ) {
            return $sub_fn( $string, 0, $max_chars ) . $suffix;
        }
        return $string;
    }

    /* ────────────────────────── REST API ───────────────────────────────── */

    /**
     * Register REST routes.
     */
    public function register_rest_routes() {
        register_rest_route( 'ontario-obituaries/v1', '/obituaries', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_obituaries_api' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'limit'  => array(
                    'default'           => 20,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function ( $param ) {
                        return is_numeric( $param ) && intval( $param ) > 0 && intval( $param ) <= 100;
                    },
                ),
                'offset' => array(
                    'default'           => 0,
                    'sanitize_callback' => 'absint',
                ),
            ),
        ) );

        register_rest_route( 'ontario-obituaries/v1', '/obituary/(?P<id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_obituary_api' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'id' => array(
                    'validate_callback' => function ( $param ) {
                        return is_numeric( $param ) && intval( $param ) > 0;
                    },
                ),
            ),
        ) );
    }

    /**
     * REST: list obituaries.
     * P1-6 FIX: returns whitelisted / sanitized shape.
     */
    public function get_obituaries_api( $request ) {
        $params = $request->get_params();

        $query_args = array(
            'limit'        => min( 100, max( 1, intval( $params['limit'] ?? 20 ) ) ),
            'offset'       => max( 0, intval( $params['offset'] ?? 0 ) ),
            'location'     => isset( $params['location'] )     ? sanitize_text_field( $params['location'] )     : '',
            'funeral_home' => isset( $params['funeral_home'] ) ? sanitize_text_field( $params['funeral_home'] ) : '',
            'days'         => isset( $params['days'] )         ? absint( $params['days'] )                      : 0,
            'search'       => isset( $params['search'] )       ? sanitize_text_field( $params['search'] )       : '',
            'orderby'      => isset( $params['orderby'] )      ? sanitize_text_field( $params['orderby'] )      : 'date_of_death',
            'order'        => isset( $params['order'] )        ? sanitize_text_field( $params['order'] )        : 'DESC',
        );

        $raw_obituaries = $this->display->get_obituaries( $query_args );

        // Fix D: pass only filter keys to count_obituaries (no limit/offset)
        $count_args = array(
            'location'     => $query_args['location'],
            'funeral_home' => $query_args['funeral_home'],
            'days'         => $query_args['days'],
            'search'       => $query_args['search'],
        );
        $total = $this->display->count_obituaries( $count_args );

        // P1-6 FIX: whitelist + sanitize output shape
        $obituaries = array();
        foreach ( $raw_obituaries as $row ) {
            $desc = isset( $row->description ) ? $row->description : '';
            // P0-3 QC FIX: mbstring-safe truncation
            $desc = $this->safe_truncate( $desc, 500 );

            $obituaries[] = array(
                'id'              => intval( $row->id ),
                'name'            => isset( $row->name )          ? sanitize_text_field( $row->name )          : '',
                'date_of_birth'   => isset( $row->date_of_birth ) && '0000-00-00' !== $row->date_of_birth ? $row->date_of_birth : null,
                'date_of_death'   => isset( $row->date_of_death ) ? $row->date_of_death                       : '',
                'age'             => isset( $row->age ) && $row->age > 0 ? intval( $row->age )                : null,
                'funeral_home'    => isset( $row->funeral_home )  ? sanitize_text_field( $row->funeral_home )  : '',
                'location'        => isset( $row->location )      ? sanitize_text_field( $row->location )      : '',
                'image_url'       => isset( $row->image_url )     ? esc_url_raw( $row->image_url )             : '',
                'source_url'      => isset( $row->source_url )    ? esc_url_raw( $row->source_url )            : '',
                'description'     => wp_strip_all_tags( $desc ),
                'source_domain'   => isset( $row->source_domain ) ? sanitize_text_field( $row->source_domain ) : '',
                'source_type'     => isset( $row->source_type )   ? sanitize_text_field( $row->source_type )   : '',
                'city_normalized' => isset( $row->city_normalized ) ? sanitize_text_field( $row->city_normalized ) : '',
            );
        }

        return rest_ensure_response( array(
            'total'      => $total,
            'obituaries' => $obituaries,
        ) );
    }

    /**
     * REST: single obituary.
     * P1-6 FIX: returns whitelisted / sanitized shape.
     */
    public function get_obituary_api( $request ) {
        $id  = absint( $request->get_param( 'id' ) );
        $row = $this->display->get_obituary( $id );

        if ( ! $row ) {
            return new WP_Error( 'not_found', __( 'Obituary not found', 'ontario-obituaries' ), array( 'status' => 404 ) );
        }

        $desc = isset( $row->description ) ? $row->description : '';
        // P0-3 QC FIX: mbstring-safe truncation
        $desc = $this->safe_truncate( $desc, 500 );

        $obituary = array(
            'id'            => intval( $row->id ),
            'name'          => isset( $row->name )          ? sanitize_text_field( $row->name )          : '',
            'date_of_birth' => isset( $row->date_of_birth ) && '0000-00-00' !== $row->date_of_birth ? $row->date_of_birth : null,
            'date_of_death' => isset( $row->date_of_death ) ? $row->date_of_death                       : '',
            'age'           => isset( $row->age ) && $row->age > 0 ? intval( $row->age )                : null,
            'funeral_home'  => isset( $row->funeral_home )  ? sanitize_text_field( $row->funeral_home )  : '',
            'location'      => isset( $row->location )      ? sanitize_text_field( $row->location )      : '',
            'image_url'     => isset( $row->image_url )     ? esc_url_raw( $row->image_url )             : '',
            'source_url'    => isset( $row->source_url )    ? esc_url_raw( $row->source_url )            : '',
            'description'   => wp_strip_all_tags( $desc ),
        );

        return rest_ensure_response( $obituary );
    }

    /* ────────────────────────── FRONTEND ASSETS ───────────────────────── */

    /**
     * Enqueue scripts and styles for the frontend.
     * FIX-B/C: Facebook sharing uses sharer-only (no App ID / SDK needed).
     */
    public function enqueue_scripts() {
        global $post;
        if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'ontario_obituaries' ) ) {
            return;
        }

        wp_enqueue_style( 'dashicons' );

        // v3.3.0: Use filemtime() for cache-busting — guarantees a new ver= on every file change,
        //         even if the plugin version constant hasn't been incremented yet.
        $css_file = ONTARIO_OBITUARIES_PLUGIN_DIR . 'assets/css/ontario-obituaries.css';
        $css_ver  = file_exists( $css_file ) ? filemtime( $css_file ) : ONTARIO_OBITUARIES_VERSION;

        $js_file = ONTARIO_OBITUARIES_PLUGIN_DIR . 'assets/js/ontario-obituaries.js';
        $js_ver  = file_exists( $js_file ) ? filemtime( $js_file ) : ONTARIO_OBITUARIES_VERSION;

        wp_enqueue_style(
            'ontario-obituaries-css',
            ONTARIO_OBITUARIES_PLUGIN_URL . 'assets/css/ontario-obituaries.css',
            array(),
            $css_ver
        );

        wp_enqueue_script(
            'ontario-obituaries-js',
            ONTARIO_OBITUARIES_PLUGIN_URL . 'assets/js/ontario-obituaries.js',
            array( 'jquery' ),
            $js_ver,
            true
        );

        wp_localize_script( 'ontario-obituaries-js', 'ontario_obituaries_ajax', array(
            'ajax_url'        => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'ontario-obituaries-nonce' ),
            'i18n_submit'     => __( 'Submit Removal Request', 'ontario-obituaries' ),
            'i18n_submitting' => __( 'Submitting...', 'ontario-obituaries' ),
        ) );

        // FIX-B/C: Always enqueue the sharer-only Facebook script (no SDK dependency)
        $fb_file = ONTARIO_OBITUARIES_PLUGIN_DIR . 'assets/js/ontario-obituaries-facebook.js';
        $fb_ver  = file_exists( $fb_file ) ? filemtime( $fb_file ) : ONTARIO_OBITUARIES_VERSION;

        wp_enqueue_script(
            'ontario-obituaries-facebook',
            ONTARIO_OBITUARIES_PLUGIN_URL . 'assets/js/ontario-obituaries-facebook.js',
            array( 'jquery', 'ontario-obituaries-js' ),
            $fb_ver,
            true
        );
    }

    /* ────────────────────────── ADMIN ASSETS ──────────────────────────── */

    /**
     * Enqueue scripts and styles for admin pages.
     *
     * @param string $hook Current admin page hook.
     */
    public function admin_enqueue_scripts( $hook ) {
        if ( false === strpos( $hook, 'ontario-obituaries' ) ) {
            return;
        }

        wp_add_inline_style( 'wp-admin', '
            .ontario-obituaries-widget ul { list-style: disc; margin-left: 20px; }
            .ontario-obituaries-error { color: #d63638; }
        ' );

        $admin_js_file = ONTARIO_OBITUARIES_PLUGIN_DIR . 'assets/js/ontario-obituaries-admin.js';
        $admin_js_ver  = file_exists( $admin_js_file ) ? filemtime( $admin_js_file ) : ONTARIO_OBITUARIES_VERSION;

        wp_enqueue_script(
            'ontario-obituaries-admin-js',
            ONTARIO_OBITUARIES_PLUGIN_URL . 'assets/js/ontario-obituaries-admin.js',
            array( 'jquery' ),
            $admin_js_ver,
            true
        );

        wp_localize_script( 'ontario-obituaries-admin-js', 'ontario_obituaries_admin', array(
            'ajax_url'       => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'ontario-obituaries-admin-nonce' ),
            'confirm_delete' => __( 'Are you sure you want to delete this obituary? This action cannot be undone.', 'ontario-obituaries' ),
            'deleting'       => __( 'Deleting...', 'ontario-obituaries' ),
        ) );

        // Debug page JS
        if ( false !== strpos( $hook, 'ontario-obituaries-debug' ) ) {
            $debug_js_file = ONTARIO_OBITUARIES_PLUGIN_DIR . 'assets/js/ontario-obituaries-debug.js';
            $debug_js_ver  = file_exists( $debug_js_file ) ? filemtime( $debug_js_file ) : ONTARIO_OBITUARIES_VERSION;

            wp_enqueue_script(
                'ontario-obituaries-debug-js',
                ONTARIO_OBITUARIES_PLUGIN_URL . 'assets/js/ontario-obituaries-debug.js',
                array( 'jquery' ),
                $debug_js_ver,
                true
            );

            wp_localize_script( 'ontario-obituaries-debug-js', 'ontarioObituariesData', array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'ontario-obituaries-admin-nonce' ),
            ) );
        }

        // v3.11.0: Reset & Rescan page JS + CSS
        // Tight match: only enqueue on the exact admin page, not on any page
        // whose hook-suffix happens to contain the substring.
        $is_reset_page = isset( $_GET['page'] ) && 'ontario-obituaries-reset-rescan' === $_GET['page'];
        if ( $is_reset_page ) {
            $rr_css_file = ONTARIO_OBITUARIES_PLUGIN_DIR . 'assets/css/ontario-obituaries-reset-rescan.css';
            $rr_css_ver  = file_exists( $rr_css_file ) ? filemtime( $rr_css_file ) : ONTARIO_OBITUARIES_VERSION;

            wp_enqueue_style(
                'ontario-obituaries-reset-rescan-css',
                ONTARIO_OBITUARIES_PLUGIN_URL . 'assets/css/ontario-obituaries-reset-rescan.css',
                array(),
                $rr_css_ver
            );

            $rr_js_file = ONTARIO_OBITUARIES_PLUGIN_DIR . 'assets/js/ontario-obituaries-reset-rescan.js';
            $rr_js_ver  = file_exists( $rr_js_file ) ? filemtime( $rr_js_file ) : ONTARIO_OBITUARIES_VERSION;

            wp_enqueue_script(
                'ontario-obituaries-reset-rescan-js',
                ONTARIO_OBITUARIES_PLUGIN_URL . 'assets/js/ontario-obituaries-reset-rescan.js',
                array( 'jquery' ),
                $rr_js_ver,
                true
            );

            wp_localize_script( 'ontario-obituaries-reset-rescan-js', 'ontarioResetRescan', array(
                'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
                'nonce'       => wp_create_nonce( 'ontario_obituaries_reset_rescan' ),
                'viewUrl'     => admin_url( 'admin.php?page=ontario-obituaries' ),
                'debugUrl'    => admin_url( 'admin.php?page=ontario-obituaries-debug' ),
                'frontendUrl' => home_url( '/obituaries/ontario/' ),
                'progress'    => get_option( 'ontario_obituaries_reset_progress', array() ),
            ) );
        }
    }
}
