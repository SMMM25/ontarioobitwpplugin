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
 *   - Processes obituaries that have a description but no ai_description.
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

    /** @var int Delay between API requests in microseconds (6 seconds = 6,000,000). */
    private $request_delay = 6000000;

    /** @var int API timeout in seconds. */
    private $api_timeout = 30;

    /** @var string|null Cached API key. */
    private $api_key = null;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->api_key = get_option( 'ontario_obituaries_groq_api_key', '' );
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

        // Get obituaries with original description but no AI rewrite yet.
        $obituaries = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, date_of_birth, date_of_death, age, funeral_home,
                    location, description
             FROM `{$table}`
             WHERE description IS NOT NULL
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

            // Save the validated rewrite.
            $wpdb->update(
                $table,
                array( 'ai_description' => sanitize_textarea_field( $rewritten ) ),
                array( 'id' => $obituary->id ),
                array( '%s' ),
                array( '%d' )
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
1. PRESERVE every fact: full name, dates, locations, funeral home, age. Never change or omit any fact.
2. NEVER invent details not present in the original text. If the original doesn't mention something, neither should you.
3. Write 2-4 short paragraphs of compassionate, professional prose.
4. Use third person ("He passed away" or "She is survived by").
5. Do NOT include headings, bullet points, or formatting. Plain prose only.
6. Do NOT include phrases like "according to the obituary" or "the original notice stated".
7. Do NOT add condolence messages or calls-to-action.
8. If the original is very short (just a name and dates), write a single brief sentence acknowledging the passing.
9. Output ONLY the rewritten obituary text. No preamble, no commentary.
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
     * Checks:
     *   1. The rewrite mentions the deceased's name (or a recognizable part of it).
     *   2. The rewrite is not suspiciously short (< 50 chars) or long (> 5000 chars).
     *   3. The rewrite doesn't contain obvious LLM artifacts.
     *
     * @param string $rewritten  The LLM-generated text.
     * @param object $obituary   The original database row.
     * @return true|WP_Error True if valid.
     */
    private function validate_rewrite( $rewritten, $obituary ) {
        // Length check.
        $len = strlen( $rewritten );
        if ( $len < 50 ) {
            return new WP_Error( 'too_short', sprintf( 'Rewrite too short (%d chars).', $len ) );
        }
        if ( $len > 5000 ) {
            return new WP_Error( 'too_long', sprintf( 'Rewrite too long (%d chars).', $len ) );
        }

        // Name check: extract the last name (most reliable part) and verify it appears.
        $name_parts = preg_split( '/\s+/', trim( $obituary->name ) );
        $last_name  = end( $name_parts );

        // Remove common suffixes/titles for matching.
        $last_name = preg_replace( '/^(Jr|Sr|III|II|IV)\.?$/i', '', $last_name );
        $last_name = trim( $last_name );

        if ( strlen( $last_name ) >= 3 && false === stripos( $rewritten, $last_name ) ) {
            // Try the first name as well.
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

        // LLM artifact check.
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
        );

        $lower = strtolower( $rewritten );
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

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$table}`
             WHERE description IS NOT NULL
               AND description != ''
               AND (ai_description IS NULL OR ai_description = '')
               AND suppressed_at IS NULL"
        );
    }

    /**
     * Get rewrite statistics.
     *
     * @return array { total: int, rewritten: int, pending: int, pct: float }
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

        return array(
            'total'     => $total,
            'rewritten' => $rewritten,
            'pending'   => $total - $rewritten,
            'pct'       => $total > 0 ? round( ( $rewritten / $total ) * 100, 1 ) : 0,
        );
    }
}
