<?php
/**
 * Ontario Obituaries Display
 *
 * Handles the frontend display of obituaries.
 *
 * FIX LOG:
 *  P1-3  : $wpdb->prepare() no longer called with empty $values array
 *  P2-3  : get_locations() and get_funeral_homes() use transient caching
 *  P1-10 : render_obituaries() passes pre-fetched data to the template to
 *           avoid double queries (template uses passed variables)
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ontario_Obituaries_Display {

    /**
     * Cache duration for list queries (1 hour).
     * P1-10 FIX: Increased from 300s (5 min) to 3600s (1 hour).
     * Caches are explicitly invalidated on insert/delete/scrape.
     */
    const CACHE_TTL = 3600;

    /**
     * Get the table name (with prefix).
     *
     * @return string
     */
    private function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'ontario_obituaries';
    }

    /**
     * Check whether the plugin table exists (cached per-request).
     *
     * @return bool
     */
    private function table_exists() {
        global $wpdb;
        static $exists = null;

        if ( null === $exists ) {
            $table = $this->table_name();
            $exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
        }

        return $exists;
    }

    /**
     * Render obituaries via the shortcode.
     *
     * P1-10 FIX: All data is fetched here and passed to the template as
     *            local variables. The template no longer instantiates a
     *            new Display object or re-queries.
     *
     * @param array $atts Shortcode attributes.
     * @return string Rendered HTML.
     */
    public function render_obituaries( $atts ) {
        $atts = shortcode_atts( array(
            'limit'        => 20,
            'location'     => '',
            'funeral_home' => '',
            'days'         => 0,
        ), $atts, 'ontario_obituaries' );

        ob_start();

        try {
            if ( ! file_exists( ONTARIO_OBITUARIES_PLUGIN_DIR . 'templates/obituaries.php' ) ) {
                echo '<p>' . esc_html__( 'Error: Obituary template not found.', 'ontario-obituaries' ) . '</p>';
                return ob_get_clean();
            }

            // Gather request parameters
            $search = isset( $_GET['ontario_obituaries_search'] )   ? sanitize_text_field( wp_unslash( $_GET['ontario_obituaries_search'] ) )   : '';
            $location_filter = isset( $_GET['ontario_obituaries_location'] ) ? sanitize_text_field( wp_unslash( $_GET['ontario_obituaries_location'] ) ) : $atts['location'];
            $page   = isset( $_GET['ontario_obituaries_page'] )    ? max( 1, intval( $_GET['ontario_obituaries_page'] ) ) : 1;
            $limit  = max( 1, intval( $atts['limit'] ) );

            $args = array(
                'limit'        => $limit,
                'offset'       => $limit * ( $page - 1 ),
                'location'     => $location_filter,
                'funeral_home' => $atts['funeral_home'],
                'days'         => intval( $atts['days'] ),
                'search'       => $search,
            );

            // Pre-fetch all data so the template doesn't duplicate queries
            $obituaries    = $this->get_obituaries( $args );
            $total         = $this->count_obituaries( array(
                'location'     => $location_filter,
                'funeral_home' => $atts['funeral_home'],
                'days'         => intval( $atts['days'] ),
                'search'       => $search,
            ) );
            $total_pages   = ceil( $total / $limit );
            $locations     = $this->get_locations();
            $funeral_homes = $this->get_funeral_homes();

            // Make variables available to the template
            $location = $location_filter; // alias used in template
            include ONTARIO_OBITUARIES_PLUGIN_DIR . 'templates/obituaries.php';

        } catch ( Exception $e ) {
            echo '<p>' . esc_html__( 'Error rendering obituaries:', 'ontario-obituaries' ) . ' ' . esc_html( $e->getMessage() ) . '</p>';
        }

        return ob_get_clean();
    }

    /**
     * Retrieve obituaries from the database.
     *
     * P1-3 FIX: $wpdb->prepare() is only called when there are placeholders.
     *
     * @param array $args Query arguments.
     * @return array Array of obituary objects.
     */
    public function get_obituaries( $args = array() ) {
        global $wpdb;

        if ( ! $this->table_exists() ) {
            return array();
        }

        $defaults = array(
            'limit'        => 20,
            'offset'       => 0,
            'location'     => '',
            'funeral_home' => '',
            'days'         => 0,
            'search'       => '',
            'orderby'      => 'date_of_death',
            'order'        => 'DESC',
        );

        $args = wp_parse_args( $args, $defaults );
        $table = $this->table_name();

        // Build WHERE
        $where_clauses = array( '1=1' );
        $values        = array();

        if ( ! empty( $args['location'] ) ) {
            $where_clauses[] = 'location = %s';
            $values[]        = $args['location'];
        }
        if ( ! empty( $args['funeral_home'] ) ) {
            $where_clauses[] = 'funeral_home = %s';
            $values[]        = $args['funeral_home'];
        }
        if ( ! empty( $args['days'] ) && is_numeric( $args['days'] ) ) {
            $where_clauses[] = 'date_of_death >= DATE_SUB(CURDATE(), INTERVAL %d DAY)';
            $values[]        = intval( $args['days'] );
        }
        if ( ! empty( $args['search'] ) ) {
            $where_clauses[] = '(name LIKE %s OR description LIKE %s)';
            $term            = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $values[]        = $term;
            $values[]        = $term;
        }

        $where = implode( ' AND ', $where_clauses );

        // Sanitize ORDER BY
        $allowed_orderby = array( 'date_of_death', 'name', 'location', 'funeral_home', 'created_at' );
        $orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'date_of_death';
        $order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

        $limit  = max( 0, intval( $args['limit'] ) );
        $offset = max( 0, intval( $args['offset'] ) );

        $query = "SELECT * FROM `{$table}` WHERE {$where} ORDER BY {$orderby} {$order}";

        if ( $limit > 0 ) {
            $query   .= ' LIMIT %d';
            $values[] = $limit;

            if ( $offset > 0 ) {
                $query   .= ' OFFSET %d';
                $values[] = $offset;
            }
        }

        // P1-3 FIX: Only call prepare() when there are actual placeholders
        if ( ! empty( $values ) ) {
            $query = $wpdb->prepare( $query, $values );
        }

        $results = $wpdb->get_results( $query );
        return $results ? $results : array();
    }

    /**
     * Get a single obituary by ID.
     *
     * @param int $id Obituary ID.
     * @return object|null
     */
    public function get_obituary( $id ) {
        global $wpdb;

        if ( ! $this->table_exists() ) {
            return null;
        }

        $id = absint( $id );
        if ( 0 === $id ) {
            return null;
        }

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$this->table_name()}` WHERE id = %d",
            $id
        ) );
    }

    /**
     * Get unique locations (P2-3 FIX: cached with transient).
     *
     * @return array
     */
    public function get_locations() {
        $cached = get_transient( 'ontario_obituaries_locations_cache' );
        if ( false !== $cached ) {
            return $cached;
        }

        global $wpdb;
        if ( ! $this->table_exists() ) {
            return array();
        }

        $locations = $wpdb->get_col(
            "SELECT DISTINCT location FROM `{$this->table_name()}` WHERE location != '' ORDER BY location"
        );

        $locations = $locations ? $locations : array();
        set_transient( 'ontario_obituaries_locations_cache', $locations, self::CACHE_TTL );

        return $locations;
    }

    /**
     * Get unique funeral homes (P2-3 FIX: cached with transient).
     *
     * @return array
     */
    public function get_funeral_homes() {
        $cached = get_transient( 'ontario_obituaries_funeral_homes_cache' );
        if ( false !== $cached ) {
            return $cached;
        }

        global $wpdb;
        if ( ! $this->table_exists() ) {
            return array();
        }

        $homes = $wpdb->get_col(
            "SELECT DISTINCT funeral_home FROM `{$this->table_name()}` WHERE funeral_home != '' ORDER BY funeral_home"
        );

        $homes = $homes ? $homes : array();
        set_transient( 'ontario_obituaries_funeral_homes_cache', $homes, self::CACHE_TTL );

        return $homes;
    }

    /**
     * Count obituaries matching the given filters.
     *
     * P1-3 FIX: $wpdb->prepare() only called with actual placeholders.
     *
     * @param array $args Filter arguments.
     * @return int
     */
    public function count_obituaries( $args = array() ) {
        global $wpdb;

        if ( ! $this->table_exists() ) {
            return 0;
        }

        $defaults = array(
            'location'     => '',
            'funeral_home' => '',
            'days'         => 0,
            'search'       => '',
        );

        $args  = wp_parse_args( $args, $defaults );
        $table = $this->table_name();

        $where_clauses = array( '1=1' );
        $values        = array();

        if ( ! empty( $args['location'] ) ) {
            $where_clauses[] = 'location = %s';
            $values[]        = $args['location'];
        }
        if ( ! empty( $args['funeral_home'] ) ) {
            $where_clauses[] = 'funeral_home = %s';
            $values[]        = $args['funeral_home'];
        }
        if ( ! empty( $args['days'] ) && is_numeric( $args['days'] ) ) {
            $where_clauses[] = 'date_of_death >= DATE_SUB(CURDATE(), INTERVAL %d DAY)';
            $values[]        = intval( $args['days'] );
        }
        if ( ! empty( $args['search'] ) ) {
            $where_clauses[] = '(name LIKE %s OR description LIKE %s)';
            $term            = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $values[]        = $term;
            $values[]        = $term;
        }

        $where = implode( ' AND ', $where_clauses );
        $query = "SELECT COUNT(*) FROM `{$table}` WHERE {$where}";

        // P1-3 FIX: avoid calling prepare() with zero placeholders
        if ( ! empty( $values ) ) {
            $query = $wpdb->prepare( $query, $values );
        }

        return intval( $wpdb->get_var( $query ) );
    }
}
