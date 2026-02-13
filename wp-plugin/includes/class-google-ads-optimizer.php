<?php
/**
 * Google Ads Campaign Optimizer
 *
 * Connects to the Google Ads REST API to monitor, analyze, and optimize
 * ad campaigns for Monaco Monuments. Provides:
 *   - Campaign performance dashboard (impressions, clicks, CTR, cost, conversions)
 *   - Automated bid adjustment recommendations via AI (Groq LLM)
 *   - Keyword performance analysis + negative keyword suggestions
 *   - Ad copy optimization suggestions via AI
 *   - Budget pacing alerts
 *   - Search term mining for new keyword opportunities
 *   - Competitor insight analysis
 *   - Scheduled daily performance reports
 *
 * Authentication: OAuth2 (refresh token flow) + Developer Token.
 * API: Google Ads REST v18 (direct HTTP, no client library needed).
 *
 * @package Ontario_Obituaries
 * @since   4.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ontario_Obituaries_Google_Ads_Optimizer {

    /** @var string Google Ads REST API base URL */
    const API_BASE = 'https://googleads.googleapis.com';

    /** @var string API version */
    const API_VERSION = 'v18';

    /** @var int Max requests per batch */
    const BATCH_SIZE = 50;

    /** @var int Rate limit delay (seconds) between API calls */
    const RATE_LIMIT_DELAY = 1;

    /** @var string Option key for Google Ads credentials */
    const OPTION_CREDENTIALS = 'ontario_obituaries_google_ads_credentials';

    /** @var string Option key for cached performance data */
    const OPTION_PERF_CACHE = 'ontario_obituaries_google_ads_perf_cache';

    /** @var string Option key for optimization recommendations */
    const OPTION_RECOMMENDATIONS = 'ontario_obituaries_google_ads_recommendations';

    /**
     * Get stored credentials.
     *
     * @return array {
     *     @type string $developer_token  Google Ads API developer token.
     *     @type string $client_id        OAuth2 client ID.
     *     @type string $client_secret    OAuth2 client secret.
     *     @type string $refresh_token    OAuth2 refresh token.
     *     @type string $customer_id      Google Ads customer ID (no dashes).
     *     @type string $manager_id       Optional MCC manager ID.
     *     @type string $access_token     Cached access token.
     *     @type int    $token_expires    Timestamp when access token expires.
     * }
     */
    public function get_credentials() {
        $defaults = array(
            'developer_token' => '',
            'client_id'       => '',
            'client_secret'   => '',
            'refresh_token'   => '',
            'customer_id'     => '',
            'manager_id'      => '',
            'access_token'    => '',
            'token_expires'   => 0,
        );
        $creds = get_option( self::OPTION_CREDENTIALS, array() );
        return wp_parse_args( $creds, $defaults );
    }

    /**
     * Save credentials.
     *
     * @param array $creds Credential array.
     */
    public function save_credentials( $creds ) {
        update_option( self::OPTION_CREDENTIALS, $creds );
    }

    /**
     * Check if the optimizer is configured with minimum required credentials.
     *
     * @return bool
     */
    public function is_configured() {
        $creds = $this->get_credentials();
        return ! empty( $creds['developer_token'] )
            && ! empty( $creds['client_id'] )
            && ! empty( $creds['client_secret'] )
            && ! empty( $creds['refresh_token'] )
            && ! empty( $creds['customer_id'] );
    }

    /**
     * Get a valid OAuth2 access token, refreshing if expired.
     *
     * @return string|WP_Error Access token or error.
     */
    public function get_access_token() {
        $creds = $this->get_credentials();

        // Return cached token if still valid (5-minute buffer).
        if ( ! empty( $creds['access_token'] ) && $creds['token_expires'] > ( time() + 300 ) ) {
            return $creds['access_token'];
        }

        // Refresh the token.
        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
            'timeout' => 15,
            'body'    => array(
                'client_id'     => $creds['client_id'],
                'client_secret' => $creds['client_secret'],
                'refresh_token' => $creds['refresh_token'],
                'grant_type'    => 'refresh_token',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            $this->log( 'OAuth2 refresh failed: ' . $response->get_error_message(), 'error' );
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['access_token'] ) ) {
            $error_msg = isset( $body['error_description'] ) ? $body['error_description'] : 'Unknown OAuth2 error';
            $this->log( 'OAuth2 refresh error: ' . $error_msg, 'error' );
            return new WP_Error( 'oauth2_error', $error_msg );
        }

        // Cache the new token.
        $creds['access_token']  = $body['access_token'];
        $creds['token_expires'] = time() + intval( $body['expires_in'] );
        $this->save_credentials( $creds );

        return $creds['access_token'];
    }

    /**
     * Execute a Google Ads Query Language (GAQL) query.
     *
     * @param string $query GAQL query string.
     * @return array|WP_Error Parsed results or error.
     */
    public function query( $query ) {
        $access_token = $this->get_access_token();
        if ( is_wp_error( $access_token ) ) {
            return $access_token;
        }

        $creds       = $this->get_credentials();
        $customer_id = preg_replace( '/[^0-9]/', '', $creds['customer_id'] );

        $url = sprintf(
            '%s/%s/customers/%s/googleAds:searchStream',
            self::API_BASE,
            self::API_VERSION,
            $customer_id
        );

        $headers = array(
            'Authorization'  => 'Bearer ' . $access_token,
            'developer-token' => $creds['developer_token'],
            'Content-Type'   => 'application/json',
        );

        if ( ! empty( $creds['manager_id'] ) ) {
            $headers['login-customer-id'] = preg_replace( '/[^0-9]/', '', $creds['manager_id'] );
        }

        $response = wp_remote_post( $url, array(
            'timeout' => 30,
            'headers' => $headers,
            'body'    => wp_json_encode( array( 'query' => $query ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            $this->log( 'GAQL query failed: ' . $response->get_error_message(), 'error' );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $error_msg = isset( $body[0]['error']['message'] ) ? $body[0]['error']['message'] : 'HTTP ' . $code;
            $this->log( 'GAQL error: ' . $error_msg, 'error' );
            return new WP_Error( 'gaql_error', $error_msg, array( 'status' => $code ) );
        }

        // Flatten the streaming response.
        $results = array();
        if ( is_array( $body ) ) {
            foreach ( $body as $chunk ) {
                if ( isset( $chunk['results'] ) && is_array( $chunk['results'] ) ) {
                    $results = array_merge( $results, $chunk['results'] );
                }
            }
        }

        return $results;
    }

    /**
     * Execute a mutate operation on Google Ads resources.
     *
     * @param array $operations Array of mutate operations.
     * @return array|WP_Error Response or error.
     */
    public function mutate( $operations ) {
        $access_token = $this->get_access_token();
        if ( is_wp_error( $access_token ) ) {
            return $access_token;
        }

        $creds       = $this->get_credentials();
        $customer_id = preg_replace( '/[^0-9]/', '', $creds['customer_id'] );

        $url = sprintf(
            '%s/%s/customers/%s/googleAds:mutate',
            self::API_BASE,
            self::API_VERSION,
            $customer_id
        );

        $headers = array(
            'Authorization'  => 'Bearer ' . $access_token,
            'developer-token' => $creds['developer_token'],
            'Content-Type'   => 'application/json',
        );

        if ( ! empty( $creds['manager_id'] ) ) {
            $headers['login-customer-id'] = preg_replace( '/[^0-9]/', '', $creds['manager_id'] );
        }

        $response = wp_remote_post( $url, array(
            'timeout' => 30,
            'headers' => $headers,
            'body'    => wp_json_encode( array(
                'mutateOperations' => $operations,
                'partialFailure'   => true,
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return json_decode( wp_remote_retrieve_body( $response ), true );
    }

    /* ═══════════════════════════════════════════════════════════════════
       PERFORMANCE DATA RETRIEVAL
       ═══════════════════════════════════════════════════════════════════ */

    /**
     * Fetch campaign performance data for the last N days.
     *
     * @param int $days Number of days to fetch (default 30).
     * @return array|WP_Error Performance data or error.
     */
    public function get_campaign_performance( $days = 30 ) {
        $date_from = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
        $date_to   = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

        $query = "SELECT
            campaign.id,
            campaign.name,
            campaign.status,
            campaign.advertising_channel_type,
            campaign_budget.amount_micros,
            metrics.impressions,
            metrics.clicks,
            metrics.ctr,
            metrics.average_cpc,
            metrics.cost_micros,
            metrics.conversions,
            metrics.conversions_value,
            metrics.cost_per_conversion,
            metrics.search_impression_share,
            metrics.search_rank_lost_impression_share,
            metrics.search_budget_lost_impression_share
        FROM campaign
        WHERE segments.date BETWEEN '{$date_from}' AND '{$date_to}'
            AND campaign.status != 'REMOVED'
        ORDER BY metrics.cost_micros DESC";

        $results = $this->query( $query );
        if ( is_wp_error( $results ) ) {
            return $results;
        }

        $campaigns = array();
        foreach ( $results as $row ) {
            $campaign = isset( $row['campaign'] ) ? $row['campaign'] : array();
            $metrics  = isset( $row['metrics'] ) ? $row['metrics'] : array();
            $budget   = isset( $row['campaignBudget'] ) ? $row['campaignBudget'] : array();

            $id = isset( $campaign['id'] ) ? $campaign['id'] : '';

            if ( ! isset( $campaigns[ $id ] ) ) {
                $campaigns[ $id ] = array(
                    'id'            => $id,
                    'name'          => isset( $campaign['name'] ) ? $campaign['name'] : '',
                    'status'        => isset( $campaign['status'] ) ? $campaign['status'] : '',
                    'channel'       => isset( $campaign['advertisingChannelType'] ) ? $campaign['advertisingChannelType'] : '',
                    'daily_budget'  => isset( $budget['amountMicros'] ) ? intval( $budget['amountMicros'] ) / 1000000 : 0,
                    'impressions'   => 0,
                    'clicks'        => 0,
                    'cost'          => 0,
                    'conversions'   => 0,
                    'conv_value'    => 0,
                    'imp_share'     => 0,
                    'rank_lost'     => 0,
                    'budget_lost'   => 0,
                );
            }

            $campaigns[ $id ]['impressions'] += isset( $metrics['impressions'] ) ? intval( $metrics['impressions'] ) : 0;
            $campaigns[ $id ]['clicks']      += isset( $metrics['clicks'] ) ? intval( $metrics['clicks'] ) : 0;
            $campaigns[ $id ]['cost']        += isset( $metrics['costMicros'] ) ? intval( $metrics['costMicros'] ) / 1000000 : 0;
            $campaigns[ $id ]['conversions'] += isset( $metrics['conversions'] ) ? floatval( $metrics['conversions'] ) : 0;
            $campaigns[ $id ]['conv_value']  += isset( $metrics['conversionsValue'] ) ? floatval( $metrics['conversionsValue'] ) : 0;

            // Last-value metrics (impression share).
            if ( isset( $metrics['searchImpressionShare'] ) ) {
                $campaigns[ $id ]['imp_share'] = floatval( $metrics['searchImpressionShare'] );
            }
            if ( isset( $metrics['searchRankLostImpressionShare'] ) ) {
                $campaigns[ $id ]['rank_lost'] = floatval( $metrics['searchRankLostImpressionShare'] );
            }
            if ( isset( $metrics['searchBudgetLostImpressionShare'] ) ) {
                $campaigns[ $id ]['budget_lost'] = floatval( $metrics['searchBudgetLostImpressionShare'] );
            }
        }

        // Calculate derived metrics.
        foreach ( $campaigns as &$c ) {
            $c['ctr']  = $c['impressions'] > 0 ? ( $c['clicks'] / $c['impressions'] ) * 100 : 0;
            $c['cpc']  = $c['clicks'] > 0 ? $c['cost'] / $c['clicks'] : 0;
            $c['cpa']  = $c['conversions'] > 0 ? $c['cost'] / $c['conversions'] : 0;
            $c['roas'] = $c['cost'] > 0 ? $c['conv_value'] / $c['cost'] : 0;
        }
        unset( $c );

        $data = array(
            'campaigns'  => array_values( $campaigns ),
            'date_from'  => $date_from,
            'date_to'    => $date_to,
            'fetched_at' => current_time( 'mysql' ),
        );

        // Cache results for 4 hours.
        update_option( self::OPTION_PERF_CACHE, $data );

        return $data;
    }

    /**
     * Get keyword-level performance.
     *
     * @param int $days Days to look back.
     * @return array|WP_Error
     */
    public function get_keyword_performance( $days = 30 ) {
        $date_from = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
        $date_to   = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

        $query = "SELECT
            ad_group_criterion.keyword.text,
            ad_group_criterion.keyword.match_type,
            ad_group_criterion.status,
            ad_group_criterion.quality_info.quality_score,
            campaign.name,
            ad_group.name,
            metrics.impressions,
            metrics.clicks,
            metrics.ctr,
            metrics.average_cpc,
            metrics.cost_micros,
            metrics.conversions,
            metrics.cost_per_conversion
        FROM keyword_view
        WHERE segments.date BETWEEN '{$date_from}' AND '{$date_to}'
            AND ad_group_criterion.status != 'REMOVED'
        ORDER BY metrics.cost_micros DESC
        LIMIT 100";

        $results = $this->query( $query );
        if ( is_wp_error( $results ) ) {
            return $results;
        }

        $keywords = array();
        foreach ( $results as $row ) {
            $criterion = isset( $row['adGroupCriterion'] ) ? $row['adGroupCriterion'] : array();
            $kw        = isset( $criterion['keyword'] ) ? $criterion['keyword'] : array();
            $metrics   = isset( $row['metrics'] ) ? $row['metrics'] : array();
            $quality   = isset( $criterion['qualityInfo'] ) ? $criterion['qualityInfo'] : array();

            $keywords[] = array(
                'text'          => isset( $kw['text'] ) ? $kw['text'] : '',
                'match_type'    => isset( $kw['matchType'] ) ? $kw['matchType'] : '',
                'status'        => isset( $criterion['status'] ) ? $criterion['status'] : '',
                'quality_score' => isset( $quality['qualityScore'] ) ? intval( $quality['qualityScore'] ) : null,
                'campaign'      => isset( $row['campaign']['name'] ) ? $row['campaign']['name'] : '',
                'ad_group'      => isset( $row['adGroup']['name'] ) ? $row['adGroup']['name'] : '',
                'impressions'   => isset( $metrics['impressions'] ) ? intval( $metrics['impressions'] ) : 0,
                'clicks'        => isset( $metrics['clicks'] ) ? intval( $metrics['clicks'] ) : 0,
                'ctr'           => isset( $metrics['ctr'] ) ? floatval( $metrics['ctr'] ) * 100 : 0,
                'avg_cpc'       => isset( $metrics['averageCpc'] ) ? intval( $metrics['averageCpc'] ) / 1000000 : 0,
                'cost'          => isset( $metrics['costMicros'] ) ? intval( $metrics['costMicros'] ) / 1000000 : 0,
                'conversions'   => isset( $metrics['conversions'] ) ? floatval( $metrics['conversions'] ) : 0,
                'cpa'           => isset( $metrics['costPerConversion'] ) ? intval( $metrics['costPerConversion'] ) / 1000000 : 0,
            );
        }

        return $keywords;
    }

    /**
     * Get search terms report (what users actually searched).
     *
     * @param int $days Days to look back.
     * @return array|WP_Error
     */
    public function get_search_terms( $days = 30 ) {
        $date_from = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
        $date_to   = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

        $query = "SELECT
            search_term_view.search_term,
            search_term_view.status,
            campaign.name,
            metrics.impressions,
            metrics.clicks,
            metrics.ctr,
            metrics.cost_micros,
            metrics.conversions
        FROM search_term_view
        WHERE segments.date BETWEEN '{$date_from}' AND '{$date_to}'
        ORDER BY metrics.impressions DESC
        LIMIT 200";

        $results = $this->query( $query );
        if ( is_wp_error( $results ) ) {
            return $results;
        }

        $terms = array();
        foreach ( $results as $row ) {
            $stv     = isset( $row['searchTermView'] ) ? $row['searchTermView'] : array();
            $metrics = isset( $row['metrics'] ) ? $row['metrics'] : array();

            $terms[] = array(
                'term'        => isset( $stv['searchTerm'] ) ? $stv['searchTerm'] : '',
                'status'      => isset( $stv['status'] ) ? $stv['status'] : '',
                'campaign'    => isset( $row['campaign']['name'] ) ? $row['campaign']['name'] : '',
                'impressions' => isset( $metrics['impressions'] ) ? intval( $metrics['impressions'] ) : 0,
                'clicks'      => isset( $metrics['clicks'] ) ? intval( $metrics['clicks'] ) : 0,
                'ctr'         => isset( $metrics['ctr'] ) ? floatval( $metrics['ctr'] ) * 100 : 0,
                'cost'        => isset( $metrics['costMicros'] ) ? intval( $metrics['costMicros'] ) / 1000000 : 0,
                'conversions' => isset( $metrics['conversions'] ) ? floatval( $metrics['conversions'] ) : 0,
            );
        }

        return $terms;
    }

    /**
     * Get ad-level performance with copy text.
     *
     * @param int $days Days.
     * @return array|WP_Error
     */
    public function get_ad_performance( $days = 30 ) {
        $date_from = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
        $date_to   = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

        $query = "SELECT
            ad_group_ad.ad.id,
            ad_group_ad.ad.type,
            ad_group_ad.ad.responsive_search_ad.headlines,
            ad_group_ad.ad.responsive_search_ad.descriptions,
            ad_group_ad.ad.final_urls,
            ad_group_ad.status,
            campaign.name,
            ad_group.name,
            metrics.impressions,
            metrics.clicks,
            metrics.ctr,
            metrics.cost_micros,
            metrics.conversions,
            metrics.average_cpc
        FROM ad_group_ad
        WHERE segments.date BETWEEN '{$date_from}' AND '{$date_to}'
            AND ad_group_ad.status != 'REMOVED'
        ORDER BY metrics.impressions DESC
        LIMIT 50";

        $results = $this->query( $query );
        if ( is_wp_error( $results ) ) {
            return $results;
        }

        $ads = array();
        foreach ( $results as $row ) {
            $ad_data  = isset( $row['adGroupAd']['ad'] ) ? $row['adGroupAd']['ad'] : array();
            $metrics  = isset( $row['metrics'] ) ? $row['metrics'] : array();
            $rsa      = isset( $ad_data['responsiveSearchAd'] ) ? $ad_data['responsiveSearchAd'] : array();

            // Extract headline/description text.
            $headlines    = array();
            $descriptions = array();
            if ( isset( $rsa['headlines'] ) ) {
                foreach ( $rsa['headlines'] as $h ) {
                    $headlines[] = isset( $h['text'] ) ? $h['text'] : '';
                }
            }
            if ( isset( $rsa['descriptions'] ) ) {
                foreach ( $rsa['descriptions'] as $d ) {
                    $descriptions[] = isset( $d['text'] ) ? $d['text'] : '';
                }
            }

            $ads[] = array(
                'id'           => isset( $ad_data['id'] ) ? $ad_data['id'] : '',
                'type'         => isset( $ad_data['type'] ) ? $ad_data['type'] : '',
                'headlines'    => $headlines,
                'descriptions' => $descriptions,
                'final_urls'   => isset( $ad_data['finalUrls'] ) ? $ad_data['finalUrls'] : array(),
                'status'       => isset( $row['adGroupAd']['status'] ) ? $row['adGroupAd']['status'] : '',
                'campaign'     => isset( $row['campaign']['name'] ) ? $row['campaign']['name'] : '',
                'ad_group'     => isset( $row['adGroup']['name'] ) ? $row['adGroup']['name'] : '',
                'impressions'  => isset( $metrics['impressions'] ) ? intval( $metrics['impressions'] ) : 0,
                'clicks'       => isset( $metrics['clicks'] ) ? intval( $metrics['clicks'] ) : 0,
                'ctr'          => isset( $metrics['ctr'] ) ? floatval( $metrics['ctr'] ) * 100 : 0,
                'cost'         => isset( $metrics['costMicros'] ) ? intval( $metrics['costMicros'] ) / 1000000 : 0,
                'conversions'  => isset( $metrics['conversions'] ) ? floatval( $metrics['conversions'] ) : 0,
                'avg_cpc'      => isset( $metrics['averageCpc'] ) ? intval( $metrics['averageCpc'] ) / 1000000 : 0,
            );
        }

        return $ads;
    }

    /* ═══════════════════════════════════════════════════════════════════
       AI-POWERED OPTIMIZATION ENGINE
       ═══════════════════════════════════════════════════════════════════ */

    /**
     * Run the full AI optimization analysis.
     *
     * Fetches performance data, sends it to Groq LLM for analysis, and
     * generates actionable recommendations for bid, budget, keyword, and
     * ad copy improvements.
     *
     * @return array|WP_Error Recommendations or error.
     */
    public function run_optimization_analysis() {
        $this->log( 'Starting AI optimization analysis.', 'info' );

        // Fetch all performance data.
        $campaign_data = $this->get_campaign_performance( 30 );
        if ( is_wp_error( $campaign_data ) ) {
            return $campaign_data;
        }

        $keyword_data = $this->get_keyword_performance( 30 );
        if ( is_wp_error( $keyword_data ) ) {
            $keyword_data = array(); // Non-fatal.
        }

        $search_terms = $this->get_search_terms( 30 );
        if ( is_wp_error( $search_terms ) ) {
            $search_terms = array(); // Non-fatal.
        }

        $ad_data = $this->get_ad_performance( 30 );
        if ( is_wp_error( $ad_data ) ) {
            $ad_data = array(); // Non-fatal.
        }

        // Build the AI prompt.
        $prompt = $this->build_optimization_prompt( $campaign_data, $keyword_data, $search_terms, $ad_data );

        // Send to Groq LLM.
        $groq_key = get_option( 'ontario_obituaries_groq_api_key', '' );
        if ( empty( $groq_key ) ) {
            return new WP_Error( 'no_groq_key', 'Groq API key not set. Required for AI optimization.' );
        }

        $ai_response = $this->call_groq( $groq_key, $prompt );
        if ( is_wp_error( $ai_response ) ) {
            return $ai_response;
        }

        // Parse and store recommendations.
        $recommendations = array(
            'generated_at' => current_time( 'mysql' ),
            'period'       => $campaign_data['date_from'] . ' to ' . $campaign_data['date_to'],
            'analysis'     => $ai_response,
            'campaign_summary' => array(
                'total_campaigns' => count( $campaign_data['campaigns'] ),
                'total_spend'     => array_sum( wp_list_pluck( $campaign_data['campaigns'], 'cost' ) ),
                'total_clicks'    => array_sum( wp_list_pluck( $campaign_data['campaigns'], 'clicks' ) ),
                'total_impressions' => array_sum( wp_list_pluck( $campaign_data['campaigns'], 'impressions' ) ),
                'total_conversions' => array_sum( wp_list_pluck( $campaign_data['campaigns'], 'conversions' ) ),
            ),
            'top_keywords'   => array_slice( $keyword_data, 0, 20 ),
            'search_terms_count' => count( $search_terms ),
        );

        update_option( self::OPTION_RECOMMENDATIONS, $recommendations );

        $this->log( 'AI optimization analysis complete. Recommendations generated.', 'info' );

        return $recommendations;
    }

    /**
     * Build the optimization prompt for the AI.
     *
     * @param array $campaigns Campaign data.
     * @param array $keywords  Keyword data.
     * @param array $search_terms Search terms.
     * @param array $ads Ad data.
     * @return string Prompt text.
     */
    private function build_optimization_prompt( $campaigns, $keywords, $search_terms, $ads ) {
        $prompt = "You are an expert Google Ads strategist specializing in local service businesses.\n";
        $prompt .= "Analyze the following Google Ads account data for Monaco Monuments, a monument and headstone company in Newmarket, Ontario, Canada.\n";
        $prompt .= "Their service area includes York Region, Newmarket, Aurora, Richmond Hill, Markham, Vaughan, and Southern Ontario.\n";
        $prompt .= "Phone: (905) 392-0778. Address: 1190 Twinney Dr. Unit #8, Newmarket, ON.\n\n";

        // Campaign summary.
        $prompt .= "=== CAMPAIGN PERFORMANCE (Last 30 Days) ===\n";
        if ( ! empty( $campaigns['campaigns'] ) ) {
            foreach ( $campaigns['campaigns'] as $c ) {
                $prompt .= sprintf(
                    "Campaign: %s | Status: %s | Budget: $%.2f/day | Impressions: %s | Clicks: %d | CTR: %.2f%% | Cost: $%.2f | CPC: $%.2f | Conversions: %.1f | CPA: $%.2f | ROAS: %.2fx | Imp Share: %.1f%% | Rank Lost: %.1f%% | Budget Lost: %.1f%%\n",
                    $c['name'], $c['status'], $c['daily_budget'],
                    number_format( $c['impressions'] ), $c['clicks'], $c['ctr'],
                    $c['cost'], $c['cpc'], $c['conversions'], $c['cpa'], $c['roas'],
                    $c['imp_share'] * 100, $c['rank_lost'] * 100, $c['budget_lost'] * 100
                );
            }
        } else {
            $prompt .= "No active campaigns found.\n";
        }

        // Top keywords.
        $prompt .= "\n=== TOP KEYWORDS BY SPEND ===\n";
        $top_kw = array_slice( $keywords, 0, 30 );
        foreach ( $top_kw as $kw ) {
            $prompt .= sprintf(
                "Keyword: \"%s\" [%s] | QS: %s | Impressions: %d | Clicks: %d | CTR: %.2f%% | CPC: $%.2f | Cost: $%.2f | Conv: %.1f\n",
                $kw['text'], $kw['match_type'],
                $kw['quality_score'] !== null ? $kw['quality_score'] : 'N/A',
                $kw['impressions'], $kw['clicks'], $kw['ctr'],
                $kw['avg_cpc'], $kw['cost'], $kw['conversions']
            );
        }

        // Search terms.
        $prompt .= "\n=== SEARCH TERMS (What Users Actually Searched) ===\n";
        $top_st = array_slice( $search_terms, 0, 40 );
        foreach ( $top_st as $st ) {
            $prompt .= sprintf(
                "Search: \"%s\" | Status: %s | Impressions: %d | Clicks: %d | Cost: $%.2f | Conv: %.1f\n",
                $st['term'], $st['status'],
                $st['impressions'], $st['clicks'], $st['cost'], $st['conversions']
            );
        }

        // Ad copy performance.
        $prompt .= "\n=== AD COPY PERFORMANCE ===\n";
        foreach ( array_slice( $ads, 0, 10 ) as $ad ) {
            $prompt .= sprintf(
                "Ad (Campaign: %s): Headlines: %s | Descriptions: %s | Impressions: %d | CTR: %.2f%% | Conv: %.1f\n",
                $ad['campaign'],
                implode( ' | ', array_slice( $ad['headlines'], 0, 3 ) ),
                implode( ' | ', array_slice( $ad['descriptions'], 0, 2 ) ),
                $ad['impressions'], $ad['ctr'], $ad['conversions']
            );
        }

        $prompt .= "\n=== ANALYSIS REQUIRED ===\n";
        $prompt .= "Provide your analysis in valid JSON format with these keys:\n";
        $prompt .= "{\n";
        $prompt .= '  "overall_score": (1-100 optimization score),' . "\n";
        $prompt .= '  "summary": "2-3 sentence executive summary",' . "\n";
        $prompt .= '  "budget_recommendations": [{"action": "increase/decrease/maintain", "campaign": "name", "reason": "why", "amount": "$X.XX/day"}],' . "\n";
        $prompt .= '  "bid_recommendations": [{"keyword": "text", "action": "increase/decrease/pause", "reason": "why", "suggested_bid": "$X.XX"}],' . "\n";
        $prompt .= '  "negative_keywords": [{"term": "search term to add as negative", "reason": "why it wastes spend"}],' . "\n";
        $prompt .= '  "new_keywords": [{"keyword": "suggested keyword", "match_type": "PHRASE/EXACT", "reason": "why it would help"}],' . "\n";
        $prompt .= '  "ad_copy_suggestions": [{"headline": "new headline text (max 30 chars)", "description": "new description (max 90 chars)", "reason": "why"}],' . "\n";
        $prompt .= '  "quick_wins": ["immediate action 1", "immediate action 2"],' . "\n";
        $prompt .= '  "warnings": ["critical issue 1", "critical issue 2"],' . "\n";
        $prompt .= '  "monument_specific_tips": ["industry-specific optimization tip"]' . "\n";
        $prompt .= "}\n";
        $prompt .= "\nFocus on ROI maximization for a local monument/headstone business. Consider seasonal trends (peak: spring/fall for monument installations). ";
        $prompt .= "Identify wasted spend on irrelevant searches. Suggest high-intent keywords that indicate someone ready to purchase a monument. ";
        $prompt .= "Consider location-based targeting opportunities for York Region and Southern Ontario.";

        return $prompt;
    }

    /**
     * Call Groq LLM API.
     *
     * @param string $api_key API key.
     * @param string $prompt  Prompt text.
     * @return array|WP_Error Parsed JSON response or error.
     */
    private function call_groq( $api_key, $prompt ) {
        $response = wp_remote_post( 'https://api.groq.com/openai/v1/chat/completions', array(
            'timeout' => 60,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'model'       => 'llama-3.3-70b-versatile',
                'messages'    => array(
                    array( 'role' => 'system', 'content' => 'You are an expert Google Ads strategist. Always respond with valid JSON only. No markdown, no code fences, no explanatory text outside the JSON.' ),
                    array( 'role' => 'user', 'content' => $prompt ),
                ),
                'temperature' => 0.3,
                'max_tokens'  => 4000,
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            $this->log( 'Groq API call failed: ' . $response->get_error_message(), 'error' );
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['choices'][0]['message']['content'] ) ) {
            $error = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Empty Groq response';
            $this->log( 'Groq error: ' . $error, 'error' );
            return new WP_Error( 'groq_error', $error );
        }

        $content = $body['choices'][0]['message']['content'];

        // Strip any markdown code fences.
        $content = preg_replace( '/^```(?:json)?\s*/i', '', $content );
        $content = preg_replace( '/\s*```\s*$/', '', $content );

        $parsed = json_decode( trim( $content ), true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $this->log( 'Groq response not valid JSON: ' . json_last_error_msg(), 'error' );
            // Return raw text as fallback.
            return array( 'raw_response' => $content, 'parse_error' => json_last_error_msg() );
        }

        return $parsed;
    }

    /* ═══════════════════════════════════════════════════════════════════
       AUTOMATED OPTIMIZATION ACTIONS
       ═══════════════════════════════════════════════════════════════════ */

    /**
     * Apply a negative keyword to a campaign.
     *
     * @param string $campaign_id Campaign resource name.
     * @param string $keyword     Negative keyword text.
     * @param string $match_type  EXACT, PHRASE, or BROAD.
     * @return array|WP_Error
     */
    public function add_negative_keyword( $campaign_id, $keyword, $match_type = 'PHRASE' ) {
        $creds       = $this->get_credentials();
        $customer_id = preg_replace( '/[^0-9]/', '', $creds['customer_id'] );

        $operations = array(
            array(
                'campaignCriterionOperation' => array(
                    'create' => array(
                        'campaign' => sprintf( 'customers/%s/campaigns/%s', $customer_id, $campaign_id ),
                        'negative' => true,
                        'keyword'  => array(
                            'text'      => $keyword,
                            'matchType' => strtoupper( $match_type ),
                        ),
                    ),
                ),
            ),
        );

        $result = $this->mutate( $operations );

        if ( ! is_wp_error( $result ) ) {
            $this->log( sprintf( 'Added negative keyword "%s" [%s] to campaign %s.', $keyword, $match_type, $campaign_id ), 'info' );
        }

        return $result;
    }

    /**
     * Update a campaign's daily budget.
     *
     * @param string $budget_resource_name Budget resource name.
     * @param float  $new_daily_budget     New daily budget in dollars.
     * @return array|WP_Error
     */
    public function update_budget( $budget_resource_name, $new_daily_budget ) {
        $operations = array(
            array(
                'campaignBudgetOperation' => array(
                    'update' => array(
                        'resourceName' => $budget_resource_name,
                        'amountMicros' => strval( intval( $new_daily_budget * 1000000 ) ),
                    ),
                    'updateMask' => 'amount_micros',
                ),
            ),
        );

        $result = $this->mutate( $operations );

        if ( ! is_wp_error( $result ) ) {
            $this->log( sprintf( 'Updated budget %s to $%.2f/day.', $budget_resource_name, $new_daily_budget ), 'info' );
        }

        return $result;
    }

    /**
     * Pause or enable a campaign.
     *
     * @param string $campaign_id Campaign ID.
     * @param string $status      ENABLED or PAUSED.
     * @return array|WP_Error
     */
    public function set_campaign_status( $campaign_id, $status = 'PAUSED' ) {
        $creds       = $this->get_credentials();
        $customer_id = preg_replace( '/[^0-9]/', '', $creds['customer_id'] );

        $operations = array(
            array(
                'campaignOperation' => array(
                    'update' => array(
                        'resourceName' => sprintf( 'customers/%s/campaigns/%s', $customer_id, $campaign_id ),
                        'status'       => strtoupper( $status ),
                    ),
                    'updateMask' => 'status',
                ),
            ),
        );

        return $this->mutate( $operations );
    }

    /* ═══════════════════════════════════════════════════════════════════
       STATS & REPORTING
       ═══════════════════════════════════════════════════════════════════ */

    /**
     * Get cached performance summary for the admin dashboard.
     *
     * @return array
     */
    public function get_dashboard_stats() {
        $cached = get_option( self::OPTION_PERF_CACHE, array() );
        $recommendations = get_option( self::OPTION_RECOMMENDATIONS, array() );

        $defaults = array(
            'total_spend'       => 0,
            'total_clicks'      => 0,
            'total_impressions' => 0,
            'total_conversions' => 0,
            'avg_ctr'           => 0,
            'avg_cpc'           => 0,
            'avg_cpa'           => 0,
            'campaigns_count'   => 0,
            'last_fetched'      => 'Never',
            'optimization_score' => null,
            'quick_wins_count'  => 0,
            'warnings_count'    => 0,
        );

        if ( ! empty( $cached['campaigns'] ) ) {
            $campaigns = $cached['campaigns'];
            $total_spend  = array_sum( wp_list_pluck( $campaigns, 'cost' ) );
            $total_clicks = array_sum( wp_list_pluck( $campaigns, 'clicks' ) );
            $total_imp    = array_sum( wp_list_pluck( $campaigns, 'impressions' ) );
            $total_conv   = array_sum( wp_list_pluck( $campaigns, 'conversions' ) );

            $defaults['total_spend']       = $total_spend;
            $defaults['total_clicks']      = $total_clicks;
            $defaults['total_impressions'] = $total_imp;
            $defaults['total_conversions'] = $total_conv;
            $defaults['avg_ctr']           = $total_imp > 0 ? ( $total_clicks / $total_imp ) * 100 : 0;
            $defaults['avg_cpc']           = $total_clicks > 0 ? $total_spend / $total_clicks : 0;
            $defaults['avg_cpa']           = $total_conv > 0 ? $total_spend / $total_conv : 0;
            $defaults['campaigns_count']   = count( $campaigns );
            $defaults['last_fetched']      = isset( $cached['fetched_at'] ) ? $cached['fetched_at'] : 'Unknown';
        }

        if ( ! empty( $recommendations['analysis'] ) ) {
            $analysis = $recommendations['analysis'];
            $defaults['optimization_score'] = isset( $analysis['overall_score'] ) ? intval( $analysis['overall_score'] ) : null;
            $defaults['quick_wins_count']   = isset( $analysis['quick_wins'] ) ? count( $analysis['quick_wins'] ) : 0;
            $defaults['warnings_count']     = isset( $analysis['warnings'] ) ? count( $analysis['warnings'] ) : 0;
        }

        return $defaults;
    }

    /**
     * Get latest AI recommendations.
     *
     * @return array
     */
    public function get_recommendations() {
        return get_option( self::OPTION_RECOMMENDATIONS, array() );
    }

    /* ═══════════════════════════════════════════════════════════════════
       OAUTH2 HELPER — GENERATE AUTHORIZATION URL
       ═══════════════════════════════════════════════════════════════════ */

    /**
     * Generate the OAuth2 authorization URL for the user to grant access.
     *
     * @return string Authorization URL.
     */
    public function get_auth_url() {
        $creds = $this->get_credentials();
        $redirect_uri = admin_url( 'admin.php?page=ontario-obituaries-settings' );

        return sprintf(
            'https://accounts.google.com/o/oauth2/v2/auth?%s',
            http_build_query( array(
                'client_id'     => $creds['client_id'],
                'redirect_uri'  => $redirect_uri,
                'response_type' => 'code',
                'scope'         => 'https://www.googleapis.com/auth/adwords',
                'access_type'   => 'offline',
                'prompt'        => 'consent',
                'state'         => wp_create_nonce( 'ontario_google_ads_oauth' ),
            ) )
        );
    }

    /**
     * Exchange an authorization code for tokens.
     *
     * @param string $code Authorization code.
     * @return bool|WP_Error
     */
    public function exchange_auth_code( $code ) {
        $creds        = $this->get_credentials();
        $redirect_uri = admin_url( 'admin.php?page=ontario-obituaries-settings' );

        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
            'timeout' => 15,
            'body'    => array(
                'client_id'     => $creds['client_id'],
                'client_secret' => $creds['client_secret'],
                'code'          => $code,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => $redirect_uri,
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['access_token'] ) || empty( $body['refresh_token'] ) ) {
            $error = isset( $body['error_description'] ) ? $body['error_description'] : 'Failed to get tokens';
            return new WP_Error( 'oauth2_exchange_error', $error );
        }

        $creds['access_token']  = $body['access_token'];
        $creds['refresh_token'] = $body['refresh_token'];
        $creds['token_expires'] = time() + intval( $body['expires_in'] );
        $this->save_credentials( $creds );

        $this->log( 'Google Ads OAuth2 tokens saved successfully.', 'info' );

        return true;
    }

    /* ═══════════════════════════════════════════════════════════════════
       LOGGING
       ═══════════════════════════════════════════════════════════════════ */

    /**
     * Log a message.
     *
     * @param string $message Message text.
     * @param string $level   info, warning, error.
     */
    private function log( $message, $level = 'info' ) {
        if ( function_exists( 'ontario_obituaries_log' ) ) {
            ontario_obituaries_log( 'Google Ads Optimizer: ' . $message, $level );
        }
    }
}
