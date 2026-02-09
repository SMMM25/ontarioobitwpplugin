<?php
/**
 * Debug class for Ontario Obituaries
 *
 * FIX LOG:
 *  P0-7  : All AJAX handlers now check current_user_can('manage_options')
 *  SEC-03: Permission checks added before any data-modifying operation
 *  P2-13 : Renders last scrape errors stored by the scraper
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ontario_Obituaries_Debug {

    /**
     * Initialize the debug class.
     */
    public function init() {
        add_action( 'admin_menu', array( $this, 'add_debug_page' ) );

        // AJAX handlers (admin-only)
        add_action( 'wp_ajax_ontario_obituaries_test_connection', array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_ontario_obituaries_save_region_url', array( $this, 'ajax_save_region_url' ) );
        add_action( 'wp_ajax_ontario_obituaries_add_test_data',   array( $this, 'ajax_add_test_data' ) );
        add_action( 'wp_ajax_ontario_obituaries_check_database',  array( $this, 'ajax_check_database' ) );
        add_action( 'wp_ajax_ontario_obituaries_run_scrape',      array( $this, 'ajax_run_scrape' ) );
    }

    /**
     * Add debug page to admin menu.
     */
    public function add_debug_page() {
        add_submenu_page(
            'ontario-obituaries',
            __( 'Debug Tools', 'ontario-obituaries' ),
            __( 'Debug Tools', 'ontario-obituaries' ),
            'manage_options',
            'ontario-obituaries-debug',
            array( $this, 'render_debug_page' )
        );
    }

    /**
     * Render the debug page.
     */
    public function render_debug_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'ontario-obituaries' ) );
        }

        $settings = ontario_obituaries_get_settings();
        $regions  = ! empty( $settings['regions'] ) ? $settings['regions'] : array( 'Toronto', 'Ottawa', 'Hamilton', 'London', 'Windsor' );

        // P2-13 FIX: Retrieve stored scrape errors for display
        $last_scrape_errors = get_option( 'ontario_obituaries_last_scrape_errors', array() );

        ?>
        <div class="wrap ontario-obituaries-debug">
            <h1><?php esc_html_e( 'Ontario Obituaries Debug Tools', 'ontario-obituaries' ); ?></h1>

            <?php if ( ! empty( $last_scrape_errors ) ) : ?>
            <div class="notice notice-warning">
                <p><strong><?php esc_html_e( 'Last Scrape Errors:', 'ontario-obituaries' ); ?></strong></p>
                <ul style="list-style: disc; margin-left: 20px;">
                    <?php foreach ( $last_scrape_errors as $err_region => $err_msg ) : ?>
                        <li><strong><?php echo esc_html( $err_region ); ?></strong>: <?php echo esc_html( is_array( $err_msg ) ? implode( '; ', $err_msg ) : $err_msg ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <div class="card">
                <h2><?php esc_html_e( 'Database Tools', 'ontario-obituaries' ); ?></h2>
                <p><?php esc_html_e( 'Tools to check and manage the database.', 'ontario-obituaries' ); ?></p>

                <div class="ontario-debug-actions" style="margin-bottom: 20px;">
                    <button id="ontario-check-database" class="button button-secondary">
                        <?php esc_html_e( 'Check Database', 'ontario-obituaries' ); ?>
                    </button>
                    <button id="ontario-add-test-data" class="button button-secondary">
                        <?php esc_html_e( 'Add Test Data', 'ontario-obituaries' ); ?>
                    </button>
                    <button id="ontario-run-scrape" class="button button-primary">
                        <?php esc_html_e( 'Run Manual Scrape', 'ontario-obituaries' ); ?>
                    </button>
                </div>
            </div>

            <div class="card">
                <h2><?php esc_html_e( 'Test Scraper Connections', 'ontario-obituaries' ); ?></h2>
                <p><?php esc_html_e( 'Test the connection to each region\'s obituary sources.', 'ontario-obituaries' ); ?></p>

                <div class="ontario-debug-actions">
                    <button id="ontario-test-all-regions" class="button button-primary">
                        <?php esc_html_e( 'Test All Regions', 'ontario-obituaries' ); ?>
                    </button>
                </div>

                <div class="ontario-debug-regions">
                    <h3><?php esc_html_e( 'Configure Regions', 'ontario-obituaries' ); ?></h3>
                    <table class="widefat fixed" cellspacing="0">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Region', 'ontario-obituaries' ); ?></th>
                                <th><?php esc_html_e( 'Source URL', 'ontario-obituaries' ); ?></th>
                                <th><?php esc_html_e( 'Actions', 'ontario-obituaries' ); ?></th>
                                <th><?php esc_html_e( 'Status', 'ontario-obituaries' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $scraper = new Ontario_Obituaries_Scraper();
                            foreach ( $regions as $region ) :
                                $url = $scraper->get_region_url( $region );
                            ?>
                                <tr>
                                    <td><?php echo esc_html( $region ); ?></td>
                                    <td>
                                        <input type="text" class="widefat region-url"
                                               data-region="<?php echo esc_attr( $region ); ?>"
                                               value="<?php echo esc_attr( $url ); ?>">
                                    </td>
                                    <td>
                                        <button class="button test-region" data-region="<?php echo esc_attr( $region ); ?>">
                                            <?php esc_html_e( 'Test Connection', 'ontario-obituaries' ); ?>
                                        </button>
                                    </td>
                                    <td>
                                        <span class="region-status" data-region="<?php echo esc_attr( $region ); ?>">
                                            <?php
                                            if ( isset( $last_scrape_errors[ $region ] ) ) {
                                                echo '<span style="color:#d63638;">' . esc_html__( 'Error', 'ontario-obituaries' ) . '</span>';
                                            } else {
                                                esc_html_e( 'Not tested', 'ontario-obituaries' );
                                            }
                                            ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="ontario-debug-logs">
                    <h3><?php esc_html_e( 'Debug Logs', 'ontario-obituaries' ); ?></h3>
                    <div id="ontario-debug-log" class="ontario-debug-log">
                        <div class="log-item log-info">[<?php echo esc_html( gmdate( 'H:i:s' ) ); ?>] <?php esc_html_e( 'Debug interface loaded. Test connections to see logs.', 'ontario-obituaries' ); ?></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2><?php esc_html_e( 'Troubleshooting Tips', 'ontario-obituaries' ); ?></h2>
                <ul>
                    <li><?php esc_html_e( 'Check that your website has proper permissions to connect to external sites.', 'ontario-obituaries' ); ?></li>
                    <li><?php esc_html_e( 'Ensure your web host allows outbound connections to the funeral home websites.', 'ontario-obituaries' ); ?></li>
                    <li><?php esc_html_e( 'Verify the scraper selectors match the current structure of the funeral home websites.', 'ontario-obituaries' ); ?></li>
                    <li><?php esc_html_e( 'Try increasing the retention period to find older obituaries.', 'ontario-obituaries' ); ?></li>
                    <li><?php esc_html_e( 'Check the WordPress debug log (wp-content/debug.log) for PHP errors.', 'ontario-obituaries' ); ?></li>
                    <li><?php esc_html_e( 'If no obituaries are showing, try adding test data using the button above.', 'ontario-obituaries' ); ?></li>
                </ul>
            </div>
        </div>
        <?php
    }

    /* ─────────────────────── AJAX HANDLERS ─────────────────────────────── */

    /**
     * Shared permission gate for all debug AJAX handlers.
     */
    private function verify_ajax_permissions() {
        check_ajax_referer( 'ontario-obituaries-admin-nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ontario-obituaries' ) ) );
        }
    }

    /**
     * Test connection to a region.
     */
    public function ajax_test_connection() {
        $this->verify_ajax_permissions();

        $region = isset( $_POST['region'] ) ? sanitize_text_field( wp_unslash( $_POST['region'] ) ) : '';
        if ( empty( $region ) ) {
            wp_send_json_error( array( 'message' => __( 'No region specified.', 'ontario-obituaries' ) ) );
        }

        $url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
        if ( empty( $url ) ) {
            wp_send_json_error( array( 'message' => __( 'No URL specified.', 'ontario-obituaries' ) ) );
        }

        $this->save_region_url( $region, $url );

        try {
            $scraper = new Ontario_Obituaries_Scraper();
            $result  = $scraper->test_connection( $region );

            if ( $result['success'] ) {
                wp_send_json_success( $result );
            } else {
                wp_send_json_error( $result );
            }
        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * Save a region URL.
     */
    public function ajax_save_region_url() {
        $this->verify_ajax_permissions();

        $region = isset( $_POST['region'] ) ? sanitize_text_field( wp_unslash( $_POST['region'] ) ) : '';
        if ( empty( $region ) ) {
            wp_send_json_error( array( 'message' => __( 'No region specified.', 'ontario-obituaries' ) ) );
        }

        $url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
        if ( empty( $url ) ) {
            wp_send_json_error( array( 'message' => __( 'No URL specified.', 'ontario-obituaries' ) ) );
        }

        if ( $this->save_region_url( $region, $url ) ) {
            wp_send_json_success( array( 'message' => __( 'URL saved successfully.', 'ontario-obituaries' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Failed to save URL.', 'ontario-obituaries' ) ) );
        }
    }

    /**
     * Add test data.
     */
    public function ajax_add_test_data() {
        $this->verify_ajax_permissions();

        $scraper = new Ontario_Obituaries_Scraper();
        $result  = $scraper->add_test_data();

        $result['success'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
    }

    /**
     * Check database structure.
     */
    public function ajax_check_database() {
        $this->verify_ajax_permissions();

        $scraper = new Ontario_Obituaries_Scraper();
        $result  = $scraper->check_database();

        $result['success'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
    }

    /**
     * Run manual scrape.
     */
    public function ajax_run_scrape() {
        $this->verify_ajax_permissions();

        $scraper = new Ontario_Obituaries_Scraper();
        $results = $scraper->collect();

        // Persist last collection
        update_option( 'ontario_obituaries_last_collection', array(
            'timestamp' => current_time( 'mysql' ),
            'completed' => current_time( 'mysql' ),
            'results'   => $results,
        ) );

        // Clear caches
        delete_transient( 'ontario_obituaries_locations_cache' );
        delete_transient( 'ontario_obituaries_funeral_homes_cache' );

        wp_send_json_success( array(
            'message' => sprintf(
                __( 'Scrape completed. Found %d obituaries, added %d new.', 'ontario-obituaries' ),
                intval( $results['obituaries_found'] ),
                intval( $results['obituaries_added'] )
            ),
            'results' => $results,
        ) );
    }

    /* ──────────────────────── PRIVATE HELPERS ──────────────────────────── */

    /**
     * Persist a region URL.
     *
     * @param string $region Region name.
     * @param string $url    URL to save.
     * @return bool
     */
    private function save_region_url( $region, $url ) {
        if ( empty( $region ) || empty( $url ) ) {
            return false;
        }

        $region_urls            = get_option( 'ontario_obituaries_region_urls', array() );
        $region_urls[ $region ] = $url;

        return update_option( 'ontario_obituaries_region_urls', $region_urls );
    }
}
