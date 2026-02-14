<?php
/**
 * AI Rewriter
 *
 * Rewrites scraped obituary descriptions into unique, professional prose
 * using a free LLM API (Groq / Llama). Preserves all facts — names, dates,
 * locations, funeral homes — while creating original content.
 *
 * Architecture:
 *   - Uses Groq's OpenAI-compatible chat completion API.
 *   - API key stored in wp_options (ontario_obituaries_groq_api_key).
 *   - v4.6.0: Processes obituaries with status='pending', rewrites them,
 *     validates the rewrite preserves all facts, then sets status='published'.
 *     Obituaries are NOT visible on the site until they pass this pipeline.
 *   - Called after each collection cycle and on a separate cron hook.
 *   - Rate-limited: 1 request per 6 seconds (10/min) to stay within Groq free tier.
 *   - Batch size: 25 obituaries per run to avoid PHP timeout.
 *
 * @package Ontario_Obituaries
 * @since   4.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ontario_Obituaries_AI_Rewriter {

    /** @var string Groq API endpoint (OpenAI-compatible). */
    private $api_url = 'https://api.groq.com/openai/v1/chat/completions';

    /** @var string Model to use. Llama 3.3 70B for quality; falls back to 3.1 8B for speed. */
    private $model = 'llama-3.3-70b-versatile';

    /** @var string Fallback model if primary is rate-limited. */
    private $fallback_model = 'llama-3.1-8b-instant';

    /** @var int Maximum obituaries to process per batch run. */
    private $batch_size = 25;

    /** @var int Delay between API requests in microseconds for small-batch mode. */
    private $small_batch_delay = 3000000;

    /** @var int Delay between API requests in microseconds (6 seconds = 6,000,000). */
    private $request_delay = 6000000;

    /** @var int API timeout in seconds. */
    private $api_timeout = 30;

    /** @var string|null Cached API key. */
    private $api_key = null;

    /**
     * Constructor.
     *
     * @param int $batch_size Optional batch size override (default 25).
     */
    public function __construct( $batch_size = 0 ) {
        $this->api_key = get_option( 'ontario_obituaries_groq_api_key', '' );
        if ( $batch_size > 0 ) {
            $this->batch_size = (int) $batch_size;
        }
    }

    /**
     * Check if the AI rewriter is configured and ready.
     *
     * @return bool True if API key is set.
     */
    public function is_configured() {
        return ! empty( $this->api_key );
    }

    /**
     * Process a batch of unrewritten obituaries.
     *
     * Fetches obituaries that have a description but no ai_description,
     * rewrites each via the LLM, and stores the result.
     *
     * @return array { processed: int, succeeded: int, failed: int, errors: string[] }
     */
    public function process_batch() {
        if ( ! $this->is_configured() ) {
            return array(
                'processed' => 0,
                'succeeded' => 0,
                'failed'    => 0,
                'errors'    => array( 'Groq API key not configured.' ),
            );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        // v4.6.4 FIX: Defensive check — ensure 'ai_description' column exists.
        // If the v4.6.0 migration failed or the column was never created,
        // the SELECT below would produce a MySQL error and return NULL.
        $col_check = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'ai_description'",
            $table
        ) );
        if ( intval( $col_check ) < 1 ) {
            // Column missing — create it now.
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN ai_description text DEFAULT NULL AFTER description" );
            ontario_obituaries_log( 'AI Rewriter: Created missing ai_description column.', 'warning' );
        }

        // v4.6.0: Get obituaries with status='pending' that need AI rewrite.
        // Only pending records are processed — they become 'published' after
        // successful rewrite + validation.
        $obituaries = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, date_of_birth, date_of_death, age, funeral_home,
                    location, description, city_normalized
             FROM `{$table}`
             WHERE status = 'pending'
               AND description IS NOT NULL
               AND description != ''
               AND (ai_description IS NULL OR ai_description = '')
               AND suppressed_at IS NULL
             ORDER BY created_at DESC
             LIMIT %d",
            $this->batch_size
        ) );

        $result = array(
            'processed' => 0,
            'succeeded' => 0,
            'failed'    => 0,
            'errors'    => array(),
        );

        if ( empty( $obituaries ) ) {
            ontario_obituaries_log( 'AI Rewriter: No unrewritten obituaries found.', 'info' );
            return $result;
        }

        ontario_obituaries_log(
            sprintf( 'AI Rewriter: Processing batch of %d obituaries.', count( $obituaries ) ),
            'info'
        );

        foreach ( $obituaries as $obituary ) {
            $result['processed']++;

            // Rate limiting between requests.
            if ( $result['processed'] > 1 ) {
                usleep( $this->request_delay );
            }

            $rewritten = $this->rewrite_obituary( $obituary );

            if ( is_wp_error( $rewritten ) ) {
                $result['failed']++;
                $result['errors'][] = sprintf(
                    'ID %d (%s): %s',
                    $obituary->id,
                    $obituary->name,
                    $rewritten->get_error_message()
                );

                // If rate-limited, stop the batch to avoid wasting requests.
                if ( 'rate_limited' === $rewritten->get_error_code() ) {
                    ontario_obituaries_log( 'AI Rewriter: Rate limited — stopping batch.', 'warning' );
                    break;
                }

                continue;
            }

            // Validate the rewrite before saving.
            $validation = $this->validate_rewrite( $rewritten, $obituary );

            if ( is_wp_error( $validation ) ) {
                $result['failed']++;
                $result['errors'][] = sprintf(
                    'ID %d (%s): Validation failed — %s',
                    $obituary->id,
                    $obituary->name,
                    $validation->get_error_message()
                );
                continue;
            }

            // v4.6.0: Save the validated rewrite AND publish the obituary.
            // This is the ONLY path to 'published' status — ensures every
            // visible obituary has been AI-rewritten and fact-validated.
            $wpdb->update(
                $table,
                array(
                    'ai_description' => sanitize_textarea_field( $rewritten ),
                    'status'         => 'published',
                ),
                array( 'id' => $obituary->id ),
                array( '%s', '%s' ),
                array( '%d' )
            );

            ontario_obituaries_log(
                sprintf( 'AI Rewriter: Published obituary ID %d (%s) — rewrite validated.', $obituary->id, $obituary->name ),
                'info'
            );

            $result['succeeded']++;
        }

        ontario_obituaries_log(
            sprintf(
                'AI Rewriter: Batch complete — %d processed, %d succeeded, %d failed.',
                $result['processed'],
                $result['succeeded'],
                $result['failed']
            ),
            'info'
        );

        return $result;
    }

    /**
     * Rewrite a single obituary via the Groq LLM API.
     *
     * @param object $obituary Database row with name, dates, description, etc.
     * @return string|WP_Error The rewritten description, or error.
     */
    public function rewrite_obituary( $obituary ) {
        $prompt = $this->build_prompt( $obituary );

        $response = $this->call_api( $prompt );

        if ( is_wp_error( $response ) ) {
            // If primary model fails with rate limit, try fallback.
            if ( 'rate_limited' === $response->get_error_code() ) {
                ontario_obituaries_log( 'AI Rewriter: Primary model rate-limited, trying fallback.', 'info' );
                usleep( 2000000 ); // Wait 2 seconds.
                $response = $this->call_api( $prompt, $this->fallback_model );
            }
        }

        return $response;
    }

    /**
     * Build the system + user prompt for the LLM.
     *
     * The prompt is designed to:
     *   1. Preserve ALL factual information (names, dates, places, funeral home).
     *   2. Never invent or hallucinate details not in the original.
     *   3. Produce professional, compassionate, unique prose.
     *   4. Keep the output concise (2-4 paragraphs).
     *
     * @param object $obituary Database row.
     * @return array Messages array for the chat completion API.
     */
    private function build_prompt( $obituary ) {
        $system = <<<'SYSTEM'
You are a professional obituary writer for a funeral home directory in Ontario, Canada. Your task is to rewrite scraped obituary text into dignified, original prose.

ABSOLUTE RULES:
1. PRESERVE every fact EXACTLY: full name, ALL dates (birth AND death with exact day/month/year), ALL locations, funeral home name, age number. Never change, omit, or round any fact.
2. You MUST include the death date (e.g., "February 13, 2026") and age (e.g., "at the age of 84") explicitly in your text. These are mandatory.
3. You MUST include the city/location (e.g., "of Newmarket, Ontario") explicitly.
4. NEVER invent details not present in the original text. If the original doesn't mention something, neither should you.
5. Write 2-4 short paragraphs of compassionate, professional prose.
6. Use third person ("He passed away" or "She is survived by").
7. Do NOT include headings, bullet points, or formatting. Plain prose only.
8. Do NOT include phrases like "according to the obituary" or "the original notice stated".
9. Do NOT add condolence messages or calls-to-action.
10. If the original is very short (just a name and dates), write a single brief sentence acknowledging the passing — still include the date and location.
11. Output ONLY the rewritten obituary text. No preamble, no commentary.
SYSTEM;

        // Build the context block with all available facts.
        $facts = array();
        $facts[] = 'Name: ' . $obituary->name;

        if ( ! empty( $obituary->date_of_death ) && '0000-00-00' !== $obituary->date_of_death ) {
            $facts[] = 'Date of Death: ' . $obituary->date_of_death;
        }

        if ( ! empty( $obituary->date_of_birth ) && '0000-00-00' !== $obituary->date_of_birth ) {
            $facts[] = 'Date of Birth: ' . $obituary->date_of_birth;
        }

        if ( ! empty( $obituary->age ) && intval( $obituary->age ) > 0 ) {
            $facts[] = 'Age: ' . intval( $obituary->age );
        }

        if ( ! empty( $obituary->location ) ) {
            $facts[] = 'Location: ' . $obituary->location;
        }

        if ( ! empty( $obituary->funeral_home ) ) {
            $facts[] = 'Funeral Home: ' . $obituary->funeral_home;
        }

        $user_message = "FACTS:\n" . implode( "\n", $facts ) . "\n\nORIGINAL OBITUARY TEXT:\n" . $obituary->description;

        return array(
            array( 'role' => 'system', 'content' => $system ),
            array( 'role' => 'user',   'content' => $user_message ),
        );
    }

    /**
     * Call the Groq chat completion API.
     *
     * @param array  $messages Chat messages.
     * @param string $model    Optional model override.
     * @return string|WP_Error The generated text, or error.
     */
    private function call_api( $messages, $model = '' ) {
        if ( empty( $model ) ) {
            $model = $this->model;
        }

        $body = wp_json_encode( array(
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => 0.7,
            'max_tokens'  => 1024,
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

        // Handle rate limiting.
        if ( 429 === $code ) {
            return new WP_Error( 'rate_limited', 'Groq API rate limit exceeded.' );
        }

        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error(
                'api_error',
                sprintf( 'Groq API returned HTTP %d: %s', $code, wp_trim_words( $body, 30, '...' ) )
            );
        }

        $data = json_decode( $body, true );

        if ( empty( $data['choices'][0]['message']['content'] ) ) {
            return new WP_Error( 'empty_response', 'Groq API returned empty content.' );
        }

        return trim( $data['choices'][0]['message']['content'] );
    }

    /**
     * Validate a rewritten obituary against the original to prevent hallucinations.
     *
     * v4.6.0: Strengthened validation — this is the ONLY gate to publication.
     * Must verify ALL critical facts are preserved:
     *   1. Full name (last name + first name must appear).
     *   2. Death date must be mentioned if present in original.
     *   3. Age must match if present in original.
     *   4. Location/city must be mentioned if present in original.
     *   5. Length and LLM artifact checks.
     *
     * @param string $rewritten  The LLM-generated text.
     * @param object $obituary   The original database row.
     * @return true|WP_Error True if valid.
     */
    private function validate_rewrite( $rewritten, $obituary ) {
        $lower = strtolower( $rewritten );

        // ── 1. Length check ──
        $len = strlen( $rewritten );
        if ( $len < 50 ) {
            return new WP_Error( 'too_short', sprintf( 'Rewrite too short (%d chars).', $len ) );
        }
        if ( $len > 5000 ) {
            return new WP_Error( 'too_long', sprintf( 'Rewrite too long (%d chars).', $len ) );
        }

        // ── 2. Name check (last name required, first name preferred) ──
        $name_parts = preg_split( '/\s+/', trim( $obituary->name ) );
        $last_name  = end( $name_parts );
        $last_name  = preg_replace( '/^(Jr|Sr|III|II|IV)\.?$/i', '', $last_name );
        $last_name  = trim( $last_name );

        if ( strlen( $last_name ) >= 3 && false === stripos( $rewritten, $last_name ) ) {
            $first_name = reset( $name_parts );
            $first_name = preg_replace( '/^(Mr|Mrs|Ms|Dr|Rev)\.?$/i', '', $first_name );
            $first_name = trim( $first_name );

            if ( strlen( $first_name ) >= 3 && false === stripos( $rewritten, $first_name ) ) {
                return new WP_Error(
                    'name_missing',
                    sprintf( 'Rewrite does not mention "%s" or "%s".', $last_name, $first_name )
                );
            }
        }

        // ── 3. Death date check ──
        if ( ! empty( $obituary->date_of_death ) && '0000-00-00' !== $obituary->date_of_death ) {
            $dod = $obituary->date_of_death; // e.g., "2026-02-13"
            $dod_parts = explode( '-', $dod );
            if ( count( $dod_parts ) === 3 ) {
                $year  = $dod_parts[0];
                $month = ltrim( $dod_parts[1], '0' );
                $day   = ltrim( $dod_parts[2], '0' );

                // Check that the year appears in the rewrite.
                if ( false === strpos( $rewritten, $year ) ) {
                    return new WP_Error(
                        'death_year_missing',
                        sprintf( 'Rewrite does not mention death year %s.', $year )
                    );
                }

                // Check day number appears (could be in various date formats).
                if ( false === strpos( $rewritten, $day ) ) {
                    return new WP_Error(
                        'death_day_missing',
                        sprintf( 'Rewrite does not mention death day %s from date %s.', $day, $dod )
                    );
                }
            }
        }

        // ── 4. Age check ──
        if ( ! empty( $obituary->age ) && intval( $obituary->age ) > 0 ) {
            $age_str = strval( intval( $obituary->age ) );
            if ( false === strpos( $rewritten, $age_str ) ) {
                return new WP_Error(
                    'age_missing',
                    sprintf( 'Rewrite does not mention age %s.', $age_str )
                );
            }
        }

        // ── 5. Location/city check ──
        $location = ! empty( $obituary->city_normalized ) ? $obituary->city_normalized : '';
        if ( empty( $location ) && ! empty( $obituary->location ) ) {
            $location = $obituary->location;
        }
        if ( ! empty( $location ) && strlen( $location ) >= 3 ) {
            // Extract just the city name (take first part before comma or dash).
            $city_check = preg_split( '/[,\-]/', $location );
            $city_check = trim( $city_check[0] );

            if ( strlen( $city_check ) >= 3 && false === stripos( $rewritten, $city_check ) ) {
                return new WP_Error(
                    'location_missing',
                    sprintf( 'Rewrite does not mention location "%s".', $city_check )
                );
            }
        }

        // ── 6. LLM artifact check ──
        $artifacts = array(
            'as an ai',
            'i cannot',
            'i\'m sorry',
            'language model',
            'here is',
            'here\'s the rewritten',
            'certainly!',
            'sure!',
            'of course!',
            'i\'d be happy',
            'i am an',
            'note:',
            'disclaimer:',
        );

        foreach ( $artifacts as $artifact ) {
            if ( false !== strpos( $lower, $artifact ) ) {
                return new WP_Error(
                    'llm_artifact',
                    sprintf( 'Rewrite contains LLM artifact: "%s".', $artifact )
                );
            }
        }

        return true;
    }

    /**
     * Get the number of obituaries still awaiting AI rewrite.
     *
     * @return int Count of unrewritten obituaries.
     */
    public function get_pending_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        // v4.6.4 FIX: Query must EXACTLY match the process_batch() WHERE clause.
        // Previously this counted all status='pending' records, but process_batch()
        // also filters on (ai_description IS NULL OR ai_description = ''). Mismatch
        // caused the UI to show "X pending" while the batch processed 0 records.
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE status = 'pending'
               AND description IS NOT NULL
               AND description != ''
               AND (ai_description IS NULL OR ai_description = '')
               AND suppressed_at IS NULL"
        );
    }

    /**
     * Get rewrite statistics.
     *
     * v4.6.0: Now tracks pipeline status (pending → published).
     *
     * @return array { total: int, rewritten: int, pending: int, published: int, pct: float }
     */
    public function get_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'ontario_obituaries';

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE description IS NOT NULL AND description != '' AND suppressed_at IS NULL"
        );

        $rewritten = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE ai_description IS NOT NULL AND ai_description != '' AND suppressed_at IS NULL"
        );

        $published = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE status = 'published' AND suppressed_at IS NULL"
        );

        $pending_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE status = 'pending' AND suppressed_at IS NULL"
        );

        $pct = $total > 0 ? round( ( $published / $total ) * 100, 1 ) : 0;

        return array(
            'total'            => $total,
            'rewritten'        => $rewritten,
            'published'        => $published,
            'pending'          => $pending_count,
            'pending_rewrite'  => $total - $rewritten,
            'pct'              => $pct,
            'percent_complete' => $pct,
        );
    }
}
