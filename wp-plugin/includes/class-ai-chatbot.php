<?php
/**
 * AI Chatbot for Monaco Monuments
 *
 * A sophisticated AI-powered customer service chatbot that:
 *   - Greets visitors with warm, professional messaging
 *   - Answers questions about Monaco Monuments services, products, and process
 *   - Directs customers to the intake form PDF on the Contact Us page
 *   - Connects to info@monacomonuments.ca for email inquiries
 *   - Uses Groq LLM (free tier) for intelligent conversation
 *   - Collects visitor info and forwards to business email
 *   - Zero cost to operate (Groq free tier + WP-native)
 *
 * @package Ontario_Obituaries
 * @since   4.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Ontario_Obituaries_AI_Chatbot {

    /** @var string Option key for chatbot settings */
    const OPTION_SETTINGS = 'ontario_obituaries_chatbot_settings';

    /** @var string Option key for conversation logs */
    const OPTION_CONVERSATIONS = 'ontario_obituaries_chatbot_conversations';

    /** @var string REST namespace */
    const REST_NAMESPACE = 'ontario-obituaries/v1';

    /**
     * Initialize hooks.
     */
    public function init() {
        // Frontend: inject chatbot widget on all pages.
        add_action( 'wp_footer', array( $this, 'render_chatbot_widget' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // REST API endpoints for chat.
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

        // AJAX fallback for non-REST environments.
        add_action( 'wp_ajax_ontario_chatbot_message', array( $this, 'handle_ajax_message' ) );
        add_action( 'wp_ajax_nopriv_ontario_chatbot_message', array( $this, 'handle_ajax_message' ) );
        add_action( 'wp_ajax_ontario_chatbot_send_email', array( $this, 'handle_ajax_email' ) );
        add_action( 'wp_ajax_nopriv_ontario_chatbot_send_email', array( $this, 'handle_ajax_email' ) );
    }

    /**
     * Get chatbot settings with defaults.
     *
     * @return array
     */
    public function get_settings() {
        $defaults = array(
            'enabled'        => true,
            'business_email' => 'info@monacomonuments.ca',
            'business_phone' => '(905) 392-0778',
            'business_name'  => 'Monaco Monuments',
            'business_address' => '1190 Twinney Dr. Unit #8, Newmarket, ON L3Y 1C8',
            'contact_page'   => 'https://monacomonuments.ca/contact/',
            'catalog_page'   => 'https://monacomonuments.ca/catalog/',
            'intake_form_info' => 'Our intake form is available on our Contact Us page. Filling it out does not hold you to any financial cost — it simply puts you at the top of the list for customers to get serviced first.',
            'greeting'       => 'Welcome to Monaco Monuments! I\'m here to help you with any questions about our custom monuments, headstones, and memorial services. How can I assist you today?',
            'accent_color'   => '#2c3e50',
            'position'       => 'bottom-right',
        );
        $settings = get_option( self::OPTION_SETTINGS, array() );
        return wp_parse_args( $settings, $defaults );
    }

    /**
     * Check if chatbot is enabled and configured.
     *
     * @return bool
     */
    public function is_enabled() {
        $settings = $this->get_settings();
        if ( empty( $settings['enabled'] ) ) {
            return false;
        }
        // Chatbot works with or without Groq key — falls back to rule-based responses.
        return true;
    }

    /**
     * Register REST routes.
     */
    public function register_rest_routes() {
        register_rest_route( self::REST_NAMESPACE, '/chatbot/message', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_rest_message' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'message' => array(
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_textarea_field',
                ),
                'history' => array(
                    'default' => array(),
                ),
            ),
        ) );

        register_rest_route( self::REST_NAMESPACE, '/chatbot/email', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_rest_email' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'name'    => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
                'email'   => array( 'required' => true, 'sanitize_callback' => 'sanitize_email' ),
                'phone'   => array( 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
                'message' => array( 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ),
            ),
        ) );
    }

    /**
     * Build the system prompt with full business context.
     *
     * @return string
     */
    private function build_system_prompt() {
        $settings = $this->get_settings();

        $prompt = "You are a warm, professional, and knowledgeable AI assistant for Monaco Monuments, a family-owned monument and headstone company located in Newmarket, Ontario, Canada.\n\n";

        $prompt .= "=== BUSINESS INFORMATION ===\n";
        $prompt .= "Company: Monaco Monuments\n";
        $prompt .= "Address: 1190 Twinney Dr. Unit #8, Newmarket, ON L3Y 1C8\n";
        $prompt .= "Phone: (905) 392-0778\n";
        $prompt .= "Email: info@monacomonuments.ca\n";
        $prompt .= "Website: https://monacomonuments.ca\n";
        $prompt .= "Service Area: York Region, Newmarket, Aurora, Richmond Hill, Markham, Vaughan, and all of Southern Ontario\n";
        $prompt .= "Hours: Monday-Friday 9am-5pm\n\n";

        $prompt .= "=== SERVICES ===\n";
        $prompt .= "- Custom monuments and headstones (granite, marble, bronze)\n";
        $prompt .= "- Memorial plaques and markers\n";
        $prompt .= "- Cemetery lettering and monument restoration\n";
        $prompt .= "- Personalized engravings (photos, symbols, custom artwork)\n";
        $prompt .= "- Monument installation and foundation work\n";
        $prompt .= "- Pet memorials\n";
        $prompt .= "- Serving ALL cemeteries in Ontario (no cemetery restrictions)\n\n";

        $prompt .= "=== KEY SELLING POINTS ===\n";
        $prompt .= "- Each monument is unique, a one-of-a-kind celebration of life\n";
        $prompt .= "- Family-owned business with personalized service\n";
        $prompt .= "- Competitive pricing, transparent quotes\n";
        $prompt .= "- High-quality materials sourced from trusted suppliers\n";
        $prompt .= "- No pressure, compassionate approach\n";
        $prompt .= "- Free consultations available\n\n";

        $prompt .= "=== INTAKE FORM / GETTING STARTED (CRITICAL - ALWAYS MENTION WHEN RELEVANT) ===\n";
        $prompt .= "The QUICKEST way for customers to start the process is by filling out the intake form PDF on our Contact Us page at: https://monacomonuments.ca/contact/\n";
        $prompt .= "IMPORTANT: Filling out the intake form does NOT hold them to any financial cost or obligation.\n";
        $prompt .= "It simply puts them at the TOP of the list for customers to get serviced first.\n";
        $prompt .= "The intake form asks for basic information to help us prepare a personalized quote.\n";
        $prompt .= "Always encourage customers to fill out the intake form as their next step.\n\n";

        $prompt .= "=== CONVERSATION GUIDELINES ===\n";
        $prompt .= "1. Be warm, empathetic, and respectful. Many visitors are grieving.\n";
        $prompt .= "2. Keep responses concise (2-4 sentences max) unless detailed info is asked.\n";
        $prompt .= "3. Always guide toward ACTION: fill out the intake form, call (905) 392-0778, or email info@monacomonuments.ca.\n";
        $prompt .= "4. When asked about pricing, explain that every monument is custom and pricing depends on size, material, and design. Encourage them to fill out the intake form for a free personalized quote.\n";
        $prompt .= "5. If asked about timelines, explain that typical monument production takes 8-12 weeks depending on complexity. Starting with the intake form speeds things up.\n";
        $prompt .= "6. Browse our catalog at: https://monacomonuments.ca/catalog/\n";
        $prompt .= "7. NEVER provide specific prices — always direct to intake form or phone call.\n";
        $prompt .= "8. If asked about something you don't know, say you'll have a team member follow up and suggest they email or call.\n";
        $prompt .= "9. Do NOT use markdown formatting. Respond in plain text only.\n";
        $prompt .= "10. When someone seems ready to proceed, always mention the intake form as the fastest way to get started.\n";

        return $prompt;
    }

    /**
     * Generate an AI response to a user message.
     *
     * @param string $message User's message.
     * @param array  $history Previous conversation history.
     * @return string Response text.
     */
    public function generate_response( $message, $history = array() ) {
        $groq_key = get_option( 'ontario_obituaries_groq_api_key', '' );

        // If no Groq key, use rule-based fallback.
        if ( empty( $groq_key ) ) {
            return $this->rule_based_response( $message );
        }

        // Build messages array for the API.
        $messages = array(
            array( 'role' => 'system', 'content' => $this->build_system_prompt() ),
        );

        // Add conversation history (last 10 messages max to stay within token limits).
        if ( ! empty( $history ) && is_array( $history ) ) {
            $recent = array_slice( $history, -10 );
            foreach ( $recent as $entry ) {
                if ( isset( $entry['role'] ) && isset( $entry['content'] ) ) {
                    $role = in_array( $entry['role'], array( 'user', 'assistant' ), true ) ? $entry['role'] : 'user';
                    $messages[] = array( 'role' => $role, 'content' => sanitize_textarea_field( $entry['content'] ) );
                }
            }
        }

        // Add current message.
        $messages[] = array( 'role' => 'user', 'content' => $message );

        $response = wp_remote_post( 'https://api.groq.com/openai/v1/chat/completions', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $groq_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'model'       => 'llama-3.3-70b-versatile',
                'messages'    => $messages,
                'temperature' => 0.7,
                'max_tokens'  => 300,
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            $this->log( 'Groq API error: ' . $response->get_error_message(), 'error' );
            return $this->rule_based_response( $message );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['choices'][0]['message']['content'] ) ) {
            $error = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Empty response';
            $this->log( 'Groq chatbot error: ' . $error, 'error' );
            return $this->rule_based_response( $message );
        }

        return trim( $body['choices'][0]['message']['content'] );
    }

    /**
     * Rule-based fallback when Groq is not available.
     *
     * @param string $message User's message.
     * @return string
     */
    private function rule_based_response( $message ) {
        $msg = strtolower( $message );
        $settings = $this->get_settings();

        // Greeting patterns.
        if ( preg_match( '/^(hi|hello|hey|good\s+(morning|afternoon|evening)|howdy)/i', $msg ) ) {
            return $settings['greeting'];
        }

        // Price / cost questions.
        if ( preg_match( '/(price|cost|how much|pricing|quote|estimate|afford|expensive|cheap|budget)/i', $msg ) ) {
            return 'Every monument we create is unique, so pricing depends on the size, material, and design you choose. The quickest way to get a personalized quote is to fill out our intake form on our Contact Us page at monacomonuments.ca/contact/ -- it\'s completely free with no obligation! You can also call us at (905) 392-0778.';
        }

        // Intake form / getting started.
        if ( preg_match( '/(start|begin|process|order|intake|form|next step|how do i|how to)/i', $msg ) ) {
            return 'The fastest way to get started is by filling out our intake form PDF on our Contact Us page at monacomonuments.ca/contact/. ' . $settings['intake_form_info'] . ' You can also call us directly at (905) 392-0778.';
        }

        // Contact information.
        if ( preg_match( '/(contact|email|phone|call|reach|address|location|where|visit|hours|open)/i', $msg ) ) {
            return 'You can reach us at: Phone: (905) 392-0778, Email: info@monacomonuments.ca, Address: 1190 Twinney Dr. Unit #8, Newmarket, ON. We\'re open Monday-Friday 9am-5pm. Visit our Contact Us page at monacomonuments.ca/contact/ to fill out our intake form.';
        }

        // Catalog / browse.
        if ( preg_match( '/(catalog|browse|gallery|see|look|options|designs|styles|types|monument|headstone|gravestone|marker|plaque)/i', $msg ) ) {
            return 'We offer a wide range of custom monuments, headstones, markers, and memorial plaques in granite, marble, and bronze. Browse our catalog at monacomonuments.ca/catalog/ for design inspiration. Each piece is fully customizable. Fill out our intake form at monacomonuments.ca/contact/ to discuss your vision with our team.';
        }

        // Timeline / how long.
        if ( preg_match( '/(how long|timeline|when|time|weeks|months|delivery|ready|wait)/i', $msg ) ) {
            return 'Typical monument production takes 8-12 weeks depending on complexity and design. The sooner you fill out our intake form at monacomonuments.ca/contact/, the sooner we can begin the process. There\'s no cost or obligation -- it just gets you to the front of the line!';
        }

        // Thank you.
        if ( preg_match( '/(thank|thanks|appreciate|helpful)/i', $msg ) ) {
            return 'You\'re very welcome! If you have any other questions, I\'m here to help. When you\'re ready to take the next step, fill out our intake form at monacomonuments.ca/contact/ or call us at (905) 392-0778. We look forward to helping you create a beautiful memorial.';
        }

        // Cemetery questions.
        if ( preg_match( '/(cemetery|cemeter|burial|grave|plot)/i', $msg ) ) {
            return 'We serve ALL cemeteries throughout Ontario -- there are no restrictions on where we can install. Whether it\'s a local cemetery in York Region or anywhere in Southern Ontario, we can help. Fill out our intake form at monacomonuments.ca/contact/ and include your cemetery details.';
        }

        // Obituary questions.
        if ( preg_match( '/(obituary|obituaries|memorial page|memorial service)/i', $msg ) ) {
            return 'We maintain a comprehensive Ontario obituaries directory as a community service. If you\'re looking for a monument to honour a loved one, we\'re here to help. The quickest way to start is our intake form at monacomonuments.ca/contact/ -- no cost, no obligation.';
        }

        // Default response.
        return 'Thank you for reaching out to Monaco Monuments! I\'d be happy to help you with that. For the most personalized assistance, I recommend filling out our intake form at monacomonuments.ca/contact/ (no cost or obligation) or calling us at (905) 392-0778. Our team will be glad to assist you.';
    }

    /**
     * Handle REST API message.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function handle_rest_message( $request ) {
        if ( ! $this->is_enabled() ) {
            return new WP_Error( 'chatbot_disabled', 'Chatbot is not enabled.', array( 'status' => 403 ) );
        }

        $message = $request->get_param( 'message' );
        $history = $request->get_param( 'history' );

        if ( empty( $message ) || strlen( trim( $message ) ) < 1 ) {
            return new WP_Error( 'empty_message', 'Message cannot be empty.', array( 'status' => 400 ) );
        }

        // Rate limit: 1 request per 2 seconds per IP.
        $ip = $this->get_client_ip();
        $rate_key = 'chatbot_rate_' . md5( $ip );
        if ( get_transient( $rate_key ) ) {
            return new WP_Error( 'rate_limited', 'Please wait a moment before sending another message.', array( 'status' => 429 ) );
        }
        set_transient( $rate_key, 1, 2 );

        $response_text = $this->generate_response( $message, is_array( $history ) ? $history : array() );

        // Log conversation (last 100 conversations, lightweight).
        $this->log_conversation( $message, $response_text, $ip );

        return rest_ensure_response( array(
            'reply'   => $response_text,
            'success' => true,
        ) );
    }

    /**
     * Handle AJAX message (fallback for non-REST).
     */
    public function handle_ajax_message() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'ontario_chatbot_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
        }

        if ( ! $this->is_enabled() ) {
            wp_send_json_error( array( 'message' => 'Chatbot is not enabled.' ), 403 );
        }

        $message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
        $history = isset( $_POST['history'] ) ? json_decode( wp_unslash( $_POST['history'] ), true ) : array();

        if ( empty( $message ) ) {
            wp_send_json_error( array( 'message' => 'Message cannot be empty.' ), 400 );
        }

        // Rate limit.
        $ip = $this->get_client_ip();
        $rate_key = 'chatbot_rate_' . md5( $ip );
        if ( get_transient( $rate_key ) ) {
            wp_send_json_error( array( 'message' => 'Please wait a moment.' ), 429 );
        }
        set_transient( $rate_key, 1, 2 );

        $response_text = $this->generate_response( $message, is_array( $history ) ? $history : array() );
        $this->log_conversation( $message, $response_text, $ip );

        wp_send_json_success( array( 'reply' => $response_text ) );
    }

    /**
     * Handle email send via REST.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function handle_rest_email( $request ) {
        $name    = $request->get_param( 'name' );
        $email   = $request->get_param( 'email' );
        $phone   = $request->get_param( 'phone' );
        $message = $request->get_param( 'message' );

        return rest_ensure_response( $this->send_lead_email( $name, $email, $phone, $message ) );
    }

    /**
     * Handle email send via AJAX.
     */
    public function handle_ajax_email() {
        if ( ! check_ajax_referer( 'ontario_chatbot_nonce', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
        }

        $name    = isset( $_POST['name'] )    ? sanitize_text_field( wp_unslash( $_POST['name'] ) )    : '';
        $email   = isset( $_POST['email'] )   ? sanitize_email( wp_unslash( $_POST['email'] ) )        : '';
        $phone   = isset( $_POST['phone'] )   ? sanitize_text_field( wp_unslash( $_POST['phone'] ) )   : '';
        $message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

        $result = $this->send_lead_email( $name, $email, $phone, $message );

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    /**
     * Send lead email to business.
     *
     * @param string $name    Customer name.
     * @param string $email   Customer email.
     * @param string $phone   Customer phone.
     * @param string $message Customer message.
     * @return array
     */
    private function send_lead_email( $name, $email, $phone, $message ) {
        if ( empty( $name ) || empty( $email ) || empty( $message ) ) {
            return array( 'success' => false, 'message' => 'Name, email, and message are required.' );
        }

        if ( ! is_email( $email ) ) {
            return array( 'success' => false, 'message' => 'Please provide a valid email address.' );
        }

        $settings = $this->get_settings();
        $to = $settings['business_email'];

        $subject = sprintf( '[Monaco Monuments Chatbot] New inquiry from %s', $name );

        $body  = "New inquiry received via the Monaco Monuments website chatbot:\n\n";
        $body .= "Name: {$name}\n";
        $body .= "Email: {$email}\n";
        if ( ! empty( $phone ) ) {
            $body .= "Phone: {$phone}\n";
        }
        $body .= "\nMessage:\n{$message}\n\n";
        $body .= "---\n";
        $body .= "This message was sent from the AI chatbot on " . home_url() . "\n";
        $body .= "Date: " . current_time( 'F j, Y g:i a' ) . "\n";

        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'Reply-To: ' . $name . ' <' . $email . '>',
        );

        $sent = wp_mail( $to, $subject, $body, $headers );

        if ( $sent ) {
            $this->log( sprintf( 'Chatbot lead email sent: %s (%s)', $name, $email ), 'info' );
            return array(
                'success' => true,
                'message' => 'Thank you! Your message has been sent to our team. We\'ll get back to you as soon as possible. In the meantime, you can also fill out our intake form at monacomonuments.ca/contact/ to speed up the process.',
            );
        } else {
            $this->log( 'Chatbot lead email failed for: ' . $email, 'error' );
            return array(
                'success' => false,
                'message' => 'Sorry, there was a problem sending your message. Please try emailing us directly at info@monacomonuments.ca or call (905) 392-0778.',
            );
        }
    }

    /**
     * Log a conversation summary (lightweight, capped).
     *
     * @param string $user_msg  User message.
     * @param string $bot_reply Bot reply.
     * @param string $ip        Client IP.
     */
    private function log_conversation( $user_msg, $bot_reply, $ip ) {
        $conversations = get_option( self::OPTION_CONVERSATIONS, array() );

        $conversations[] = array(
            'timestamp' => current_time( 'mysql' ),
            'ip_hash'   => md5( $ip ),
            'user_msg'  => wp_trim_words( $user_msg, 30 ),
            'bot_reply' => wp_trim_words( $bot_reply, 30 ),
        );

        // Keep only last 200 entries.
        if ( count( $conversations ) > 200 ) {
            $conversations = array_slice( $conversations, -200 );
        }

        update_option( self::OPTION_CONVERSATIONS, $conversations, false );
    }

    /**
     * Enqueue frontend CSS and JS.
     */
    public function enqueue_assets() {
        if ( is_admin() || ! $this->is_enabled() ) {
            return;
        }

        $settings = $this->get_settings();

        $css_file = ONTARIO_OBITUARIES_PLUGIN_DIR . 'assets/css/ontario-chatbot.css';
        $css_ver  = file_exists( $css_file ) ? filemtime( $css_file ) : ONTARIO_OBITUARIES_VERSION;

        wp_enqueue_style(
            'ontario-chatbot-css',
            ONTARIO_OBITUARIES_PLUGIN_URL . 'assets/css/ontario-chatbot.css',
            array(),
            $css_ver
        );

        $js_file = ONTARIO_OBITUARIES_PLUGIN_DIR . 'assets/js/ontario-chatbot.js';
        $js_ver  = file_exists( $js_file ) ? filemtime( $js_file ) : ONTARIO_OBITUARIES_VERSION;

        wp_enqueue_script(
            'ontario-chatbot-js',
            ONTARIO_OBITUARIES_PLUGIN_URL . 'assets/js/ontario-chatbot.js',
            array(),
            $js_ver,
            true
        );

        wp_localize_script( 'ontario-chatbot-js', 'ontarioChatbot', array(
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'restUrl'    => esc_url_raw( rest_url( self::REST_NAMESPACE . '/chatbot/' ) ),
            'nonce'      => wp_create_nonce( 'ontario_chatbot_nonce' ),
            'restNonce'  => wp_create_nonce( 'wp_rest' ),
            'greeting'   => $settings['greeting'],
            'accentColor' => $settings['accent_color'],
            'businessName' => $settings['business_name'],
            'phone'      => $settings['business_phone'],
            'email'      => $settings['business_email'],
            'contactUrl' => $settings['contact_page'],
            'catalogUrl' => $settings['catalog_page'],
        ) );
    }

    /**
     * Render the chatbot widget HTML in the footer.
     */
    public function render_chatbot_widget() {
        if ( is_admin() || ! $this->is_enabled() ) {
            return;
        }

        $settings = $this->get_settings();
        ?>
        <!-- Monaco Monuments AI Chatbot v4.5.0 -->
        <div id="ontario-chatbot-widget" class="ontario-chatbot-widget" style="display:none;">
            <!-- Toggle Button -->
            <button id="ontario-chatbot-toggle" class="ontario-chatbot-toggle" aria-label="<?php esc_attr_e( 'Chat with us', 'ontario-obituaries' ); ?>" title="<?php esc_attr_e( 'Chat with Monaco Monuments', 'ontario-obituaries' ); ?>">
                <svg id="ontario-chatbot-icon-open" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                <svg id="ontario-chatbot-icon-close" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                <span class="ontario-chatbot-badge" id="ontario-chatbot-badge">1</span>
            </button>

            <!-- Chat Window -->
            <div id="ontario-chatbot-window" class="ontario-chatbot-window" style="display:none;">
                <!-- Header -->
                <div class="ontario-chatbot-header">
                    <div class="ontario-chatbot-header-info">
                        <div class="ontario-chatbot-avatar">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M3 10h18M5 6l7-3 7 3M4 10v11M20 10v11M8 14v4M12 14v4M16 14v4"></path></svg>
                        </div>
                        <div>
                            <div class="ontario-chatbot-title"><?php echo esc_html( $settings['business_name'] ); ?></div>
                            <div class="ontario-chatbot-status"><?php esc_html_e( 'Online', 'ontario-obituaries' ); ?></div>
                        </div>
                    </div>
                    <button id="ontario-chatbot-close" class="ontario-chatbot-close" aria-label="<?php esc_attr_e( 'Close chat', 'ontario-obituaries' ); ?>">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </button>
                </div>

                <!-- Messages -->
                <div id="ontario-chatbot-messages" class="ontario-chatbot-messages">
                    <!-- Messages inserted by JS -->
                </div>

                <!-- Quick Actions -->
                <div id="ontario-chatbot-quick-actions" class="ontario-chatbot-quick-actions">
                    <button class="ontario-chatbot-quick-btn" data-message="<?php esc_attr_e( 'How do I get started?', 'ontario-obituaries' ); ?>"><?php esc_html_e( 'Get Started', 'ontario-obituaries' ); ?></button>
                    <button class="ontario-chatbot-quick-btn" data-message="<?php esc_attr_e( 'What are your prices?', 'ontario-obituaries' ); ?>"><?php esc_html_e( 'Pricing', 'ontario-obituaries' ); ?></button>
                    <button class="ontario-chatbot-quick-btn" data-message="<?php esc_attr_e( 'What types of monuments do you offer?', 'ontario-obituaries' ); ?>"><?php esc_html_e( 'Catalog', 'ontario-obituaries' ); ?></button>
                    <button class="ontario-chatbot-quick-btn" data-message="<?php esc_attr_e( 'How can I contact you?', 'ontario-obituaries' ); ?>"><?php esc_html_e( 'Contact', 'ontario-obituaries' ); ?></button>
                </div>

                <!-- Input -->
                <div class="ontario-chatbot-input-area">
                    <form id="ontario-chatbot-form" class="ontario-chatbot-form">
                        <input type="text" id="ontario-chatbot-input" class="ontario-chatbot-input" placeholder="<?php esc_attr_e( 'Type your message...', 'ontario-obituaries' ); ?>" autocomplete="off" maxlength="500" />
                        <button type="submit" id="ontario-chatbot-send" class="ontario-chatbot-send" aria-label="<?php esc_attr_e( 'Send message', 'ontario-obituaries' ); ?>">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
                        </button>
                    </form>
                    <div class="ontario-chatbot-powered">
                        <a href="<?php echo esc_url( $settings['contact_page'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Fill out our intake form', 'ontario-obituaries' ); ?></a> &bull;
                        <a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $settings['business_phone'] ) ); ?>"><?php echo esc_html( $settings['business_phone'] ); ?></a>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Get client IP address.
     *
     * @return string
     */
    private function get_client_ip() {
        $headers = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
        foreach ( $headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
                return trim( $ips[0] );
            }
        }
        return '0.0.0.0';
    }

    /**
     * Log a message.
     *
     * @param string $message Message.
     * @param string $level   Level.
     */
    private function log( $message, $level = 'info' ) {
        if ( function_exists( 'ontario_obituaries_log' ) ) {
            ontario_obituaries_log( 'AI Chatbot: ' . $message, $level );
        }
    }

    /**
     * Get conversation stats for admin dashboard.
     *
     * @return array
     */
    public function get_stats() {
        $conversations = get_option( self::OPTION_CONVERSATIONS, array() );
        $total = count( $conversations );

        // Count conversations from last 24h and 7d.
        $last_24h = 0;
        $last_7d  = 0;
        $cutoff_24h = strtotime( '-24 hours' );
        $cutoff_7d  = strtotime( '-7 days' );

        foreach ( $conversations as $conv ) {
            $ts = isset( $conv['timestamp'] ) ? strtotime( $conv['timestamp'] ) : 0;
            if ( $ts >= $cutoff_24h ) {
                $last_24h++;
            }
            if ( $ts >= $cutoff_7d ) {
                $last_7d++;
            }
        }

        // Count unique visitors (by IP hash).
        $unique_ips = array();
        foreach ( $conversations as $conv ) {
            if ( isset( $conv['ip_hash'] ) ) {
                $unique_ips[ $conv['ip_hash'] ] = true;
            }
        }

        return array(
            'total_messages' => $total,
            'last_24h'       => $last_24h,
            'last_7d'        => $last_7d,
            'unique_visitors' => count( $unique_ips ),
        );
    }
}
