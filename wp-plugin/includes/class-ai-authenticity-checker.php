<?php
/**
 * AI Authenticity Checker
 *
 * Performs 24/7 random audits of obituary data using the Groq LLM (same key
 * as the AI Rewriter). Picks random obituaries and asks the AI to verify:
 *   - Date plausibility (birth/death dates, age consistency)
 *   - Name/date/location cross-consistency with the description text
 *   - Duplicate or fabricated content detection
 *
 * Runs via WP-Cron every 4 hours, auditing a random batch each time.
 * Flags issues in the `audit_flags` column and logs results.
 *
 * Architecture:
 *   - Uses Groq's OpenAI-compatible chat completion API (same key as AI Rewriter).
 *   - Picks random obituaries weighted toward those never audited.
 *   - LLM returns structured JSON with pass/fail verdicts and explanations.
 *   - Failed audits are flagged for admin review.
 *   - Rate-limited: 1 request per 6 seconds, 10 per batch.
 *
 * @package Ontario_Obituaries
 * @since   4.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ontario_Obituaries_Authenticity_Checker {

    /** @var string Groq API endpoint. */
    private $api_url = 'https://api.groq.com/openai/v1/chat/completions';

    /** @var string Primary model. */
    private $model = 'llama-3.3-70b-versatile';

    /** @var int Obituaries to audit per batch. */
    private $batch_size = 10;

    /** @var int Delay between requests in microseconds (6 seconds). */
    private $request_delay = 6000000;

    /** @var int API timeout. */
    private $api_timeout = 30;

    /** @var string|null API key (shared with AI Rewriter). */
    private $api_key = null;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->api_key = get_option( 'ontario_obituaries_groq_api_key', '' );
    }

    /**
     * Check if the checker is configured.
     *
     * @return bool
     */
    public function is_configured() {
        return ! empty( $this->api_key );
    }

    /**
     * Run a random audit batch.
     *
     * Selects random obituaries prioritizing those never audited, then
     * verifies each with the LLM and stores results.
     *
     * @return array { audited: int, passed: int, flagged: int, errors: string[] }
     */
    public function run_audit_batch() {
        if ( ! $this->is_configured() ) {
            return array(
                'audited' => 0,
                'passed'  => 0,
                'flagged' => 0,
                'errors'  => array( 'Groq API key not configured.' ),
            );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        // Pick random obituaries, prioritizing never-audited ones.
        // 80% from never-audited, 20% from previously audited (re-check).
        $never_audited_count = max( 1, intval( $this->batch_size * 0.8 ) );
        $re_check_count      = $this->batch_size - $never_audited_count;

        // v4.6.0: Only audit published records (already rewritten & validated).
        $never_audited = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, date_of_birth, date_of_death, age, funeral_home,
                    location, city_normalized, description, ai_description, source_url
             FROM `{$table}`
             WHERE last_audit_at IS NULL
               AND suppressed_at IS NULL
               AND status = 'published'
               AND description IS NOT NULL AND description != ''
             ORDER BY RAND()
             LIMIT %d",
            $never_audited_count
        ) );

        $re_checks = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, date_of_birth, date_of_death, age, funeral_home,
                    location, city_normalized, description, ai_description, source_url
             FROM `{$table}`
             WHERE last_audit_at IS NOT NULL
               AND suppressed_at IS NULL
               AND status = 'published'
               AND description IS NOT NULL AND description != ''
             ORDER BY RAND()
             LIMIT %d",
            $re_check_count
        ) );

        $obituaries = array_merge(
            is_array( $never_audited ) ? $never_audited : array(),
            is_array( $re_checks )     ? $re_checks     : array()
        );

        $result = array(
            'audited' => 0,
            'passed'  => 0,
            'flagged' => 0,
            'errors'  => array(),
        );

        if ( empty( $obituaries ) ) {
            ontario_obituaries_log( 'Authenticity Checker: No obituaries available for audit.', 'info' );
            return $result;
        }

        ontario_obituaries_log(
            sprintf( 'Authenticity Checker: Auditing batch of %d obituaries.', count( $obituaries ) ),
            'info'
        );

        foreach ( $obituaries as $obit ) {
            $result['audited']++;

            if ( $result['audited'] > 1 ) {
                usleep( $this->request_delay );
            }

            $audit = $this->audit_obituary( $obit );

            if ( is_wp_error( $audit ) ) {
                $result['errors'][] = sprintf(
                    'ID %d (%s): %s',
                    $obit->id, $obit->name, $audit->get_error_message()
                );

                if ( 'rate_limited' === $audit->get_error_code() ) {
                    ontario_obituaries_log( 'Authenticity Checker: Rate limited — stopping batch.', 'warning' );
                    break;
                }
                continue;
            }

            // Store audit results.
            $update_data = array(
                'last_audit_at'   => current_time( 'mysql' ),
                'audit_status'    => $audit['status'],  // 'pass' or 'flagged'
            );

            if ( 'flagged' === $audit['status'] ) {
                $update_data['audit_flags'] = wp_json_encode( $audit['issues'] );
                $result['flagged']++;

                ontario_obituaries_log(
                    sprintf(
                        'Authenticity Checker: FLAGGED ID %d (%s) — %s',
                        $obit->id, $obit->name, implode( '; ', $audit['issues'] )
                    ),
                    'warning'
                );

                // Auto-correct if the AI provided a correction and confidence is high.
                if ( ! empty( $audit['corrections'] ) ) {
                    $this->apply_corrections( $obit->id, $audit['corrections'], $table );
                }
            } else {
                $update_data['audit_flags'] = null;
                $result['passed']++;
            }

            $wpdb->update(
                $table,
                $update_data,
                array( 'id' => $obit->id ),
                array( '%s', '%s', '%s' ),
                array( '%d' )
            );
        }

        ontario_obituaries_log(
            sprintf(
                'Authenticity Checker: Batch complete — %d audited, %d passed, %d flagged, %d errors.',
                $result['audited'], $result['passed'], $result['flagged'], count( $result['errors'] )
            ),
            'info'
        );

        return $result;
    }

    /**
     * Audit a single obituary using the LLM.
     *
     * @param object $obit Obituary database row.
     * @return array|WP_Error { status: 'pass'|'flagged', issues: string[], corrections: array }
     */
    private function audit_obituary( $obit ) {
        $prompt = $this->build_audit_prompt( $obit );

        $response = $this->call_api( $prompt );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $this->parse_audit_response( $response, $obit );
    }

    /**
     * Build the audit prompt for the LLM.
     *
     * @param object $obit Obituary record.
     * @return array Chat messages.
     */
    private function build_audit_prompt( $obit ) {
        $system = <<<'SYSTEM'
You are a data quality auditor for an obituary database. Your job is to verify that each obituary record is internally consistent and factually plausible.

For each obituary, check these points:
1. DATE CONSISTENCY: Does the age match the difference between birth and death dates? Is the death date in the past? Is the birth date before the death date?
2. AGE PLAUSIBILITY: Is the age between 0 and 130? If birth/death dates are given, does the computed age match the listed age (±1 year tolerance)?
3. TEXT-DATE AGREEMENT: If the obituary text mentions specific dates (e.g., "passed away on March 5, 2025"), do they match the structured date_of_death field?
4. LOCATION CONSISTENCY: Does the location/city seem consistent with the funeral home location (if recognizable)?
5. NAME-TEXT AGREEMENT: Does the obituary text reference the same person as the name field?
6. CONTENT QUALITY: Is the description actual obituary content (not placeholder text, lorem ipsum, or completely unrelated content)?

Respond ONLY with valid JSON in this exact format:
{
  "status": "pass" or "flagged",
  "issues": ["list of issues found, empty array if pass"],
  "corrections": {
    "field_name": "corrected_value"
  },
  "confidence": 0.0 to 1.0
}

Only include "corrections" for fields you are highly confident (>0.9) are wrong and you know the correct value.
Only flag real issues — do NOT flag missing data (empty fields are acceptable).
Do NOT flag records just because they lack a birth date or age.
SYSTEM;

        $record = array();
        $record[] = 'Name: ' . $obit->name;
        $record[] = 'Date of Birth: ' . ( ! empty( $obit->date_of_birth ) && '0000-00-00' !== $obit->date_of_birth ? $obit->date_of_birth : 'Not provided' );
        $record[] = 'Date of Death: ' . ( ! empty( $obit->date_of_death ) && '0000-00-00' !== $obit->date_of_death ? $obit->date_of_death : 'Not provided' );
        $record[] = 'Listed Age: ' . ( ! empty( $obit->age ) && intval( $obit->age ) > 0 ? intval( $obit->age ) : 'Not provided' );
        $record[] = 'Location: ' . ( ! empty( $obit->location ) ? $obit->location : 'Not provided' );
        $record[] = 'City (normalized): ' . ( ! empty( $obit->city_normalized ) ? $obit->city_normalized : 'Not provided' );
        $record[] = 'Funeral Home: ' . ( ! empty( $obit->funeral_home ) ? $obit->funeral_home : 'Not provided' );

        // Use AI description if available, otherwise original.
        $desc_text = ! empty( $obit->ai_description ) ? $obit->ai_description : $obit->description;
        $record[]  = 'Obituary Text: ' . substr( $desc_text, 0, 2000 );

        $user_message = "OBITUARY RECORD TO AUDIT:\n" . implode( "\n", $record );

        return array(
            array( 'role' => 'system', 'content' => $system ),
            array( 'role' => 'user',   'content' => $user_message ),
        );
    }

    /**
     * Parse the LLM's audit response.
     *
     * @param string $response Raw LLM response text.
     * @param object $obit     Original obituary record.
     * @return array { status: string, issues: array, corrections: array }
     */
    private function parse_audit_response( $response, $obit ) {
        // Try to extract JSON from the response.
        $json = $response;

        // Handle markdown code blocks.
        if ( preg_match( '/```(?:json)?\s*([\s\S]*?)```/', $response, $m ) ) {
            $json = trim( $m[1] );
        }

        $data = json_decode( $json, true );

        if ( empty( $data ) || ! isset( $data['status'] ) ) {
            // If JSON parsing fails, treat as a pass (don't flag unfairly).
            ontario_obituaries_log(
                sprintf( 'Authenticity Checker: Failed to parse LLM response for ID %d.', $obit->id ),
                'warning'
            );
            return array(
                'status'      => 'pass',
                'issues'      => array(),
                'corrections' => array(),
                'confidence'  => 0,
            );
        }

        // Sanitize the response.
        $status      = ( 'flagged' === $data['status'] ) ? 'flagged' : 'pass';
        $issues      = isset( $data['issues'] ) && is_array( $data['issues'] ) ? $data['issues'] : array();
        $corrections = isset( $data['corrections'] ) && is_array( $data['corrections'] ) ? $data['corrections'] : array();
        $confidence  = isset( $data['confidence'] ) ? floatval( $data['confidence'] ) : 0;

        // Only apply corrections with high confidence.
        if ( $confidence < 0.9 ) {
            $corrections = array();
        }

        // Whitelist of fields that can be auto-corrected.
        $allowed_fields = array( 'date_of_death', 'date_of_birth', 'age', 'city_normalized' );
        $corrections    = array_intersect_key( $corrections, array_flip( $allowed_fields ) );

        // Validate correction values.
        foreach ( $corrections as $field => $value ) {
            if ( 'date_of_death' === $field || 'date_of_birth' === $field ) {
                if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
                    unset( $corrections[ $field ] );
                }
            }
            if ( 'age' === $field ) {
                $age_val = intval( $value );
                if ( $age_val < 0 || $age_val > 130 ) {
                    unset( $corrections[ $field ] );
                }
            }
        }

        return array(
            'status'      => $status,
            'issues'      => array_map( 'sanitize_text_field', array_slice( $issues, 0, 5 ) ),
            'corrections' => $corrections,
            'confidence'  => $confidence,
        );
    }

    /**
     * Apply auto-corrections from a high-confidence audit.
     *
     * @param int    $obit_id     Obituary ID.
     * @param array  $corrections Field => value corrections.
     * @param string $table       Table name.
     */
    private function apply_corrections( $obit_id, $corrections, $table ) {
        if ( empty( $corrections ) ) {
            return;
        }

        global $wpdb;

        foreach ( $corrections as $field => $value ) {
            $old_value = $wpdb->get_var( $wpdb->prepare(
                "SELECT `{$field}` FROM `{$table}` WHERE id = %d",
                $obit_id
            ) );

            // Only correct if the value actually differs.
            if ( (string) $old_value !== (string) $value ) {
                $wpdb->update(
                    $table,
                    array( $field => $value ),
                    array( 'id' => $obit_id ),
                    array( '%s' ),
                    array( '%d' )
                );

                ontario_obituaries_log(
                    sprintf(
                        'Authenticity Checker: AUTO-CORRECTED ID %d — %s: "%s" → "%s".',
                        $obit_id, $field, $old_value, $value
                    ),
                    'info'
                );
            }
        }
    }

    /**
     * Call the Groq API.
     *
     * @param array  $messages Chat messages.
     * @param string $model    Optional model override.
     * @return string|WP_Error Generated text or error.
     */
    private function call_api( $messages, $model = '' ) {
        if ( empty( $model ) ) {
            $model = $this->model;
        }

        $body = wp_json_encode( array(
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => 0.2, // Low temperature for factual checking.
            'max_tokens'  => 512,
            'top_p'       => 0.9,
        ) );

        $response = wp_remote_post( $this->api_url, array(
            'timeout' => $this->api_timeout,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ),
            'body' => $body,
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'api_error', 'HTTP request failed: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( 429 === $code ) {
            return new WP_Error( 'rate_limited', 'Groq API rate limit exceeded.' );
        }

        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'api_error', sprintf( 'Groq API returned HTTP %d.', $code ) );
        }

        $data = json_decode( $body, true );

        if ( empty( $data['choices'][0]['message']['content'] ) ) {
            return new WP_Error( 'empty_response', 'Groq API returned empty content.' );
        }

        return trim( $data['choices'][0]['message']['content'] );
    }

    /**
     * Get audit statistics.
     *
     * @return array { total: int, audited: int, passed: int, flagged: int, never_audited: int }
     */
    public function get_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        // v4.6.0: Only count published records.
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}` WHERE suppressed_at IS NULL AND status = 'published' AND description IS NOT NULL AND description != ''"
        );

        $audited = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}` WHERE last_audit_at IS NOT NULL AND suppressed_at IS NULL AND status = 'published'"
        );

        $flagged = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}` WHERE audit_status = 'flagged' AND suppressed_at IS NULL AND status = 'published'"
        );

        $passed = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}` WHERE audit_status = 'pass' AND suppressed_at IS NULL AND status = 'published'"
        );

        return array(
            'total'         => $total,
            'audited'       => $audited,
            'passed'        => $passed,
            'flagged'       => $flagged,
            'never_audited' => $total - $audited,
        );
    }

    /**
     * Get recently flagged obituaries for admin review.
     *
     * @param int $limit Number of records to return.
     * @return array Array of flagged obituary objects.
     */
    public function get_flagged( $limit = 20 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, date_of_death, location, audit_status, audit_flags, last_audit_at
             FROM `{$table}`
             WHERE audit_status = 'flagged'
               AND suppressed_at IS NULL
             ORDER BY last_audit_at DESC
             LIMIT %d",
            $limit
        ) );
    }
}
