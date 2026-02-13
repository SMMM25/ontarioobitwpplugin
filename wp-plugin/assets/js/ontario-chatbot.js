/**
 * Monaco Monuments AI Chatbot — v4.5.0
 *
 * Self-contained vanilla JS chatbot widget.
 * Uses AJAX (primary) or REST API for messaging.
 * No external dependencies.
 */
(function () {
    'use strict';

    // Exit if config not loaded.
    if (typeof ontarioChatbot === 'undefined') return;

    var config = ontarioChatbot;
    var history = [];
    var isOpen = false;
    var isLoading = false;

    // ──── DOM References ─────────────────────────────────────
    var widget, toggle, chatWindow, messagesContainer, form, input, sendBtn;
    var badge, iconOpen, iconClose, closeBtn, quickActions;

    function init() {
        widget = document.getElementById('ontario-chatbot-widget');
        if (!widget) return;

        toggle = document.getElementById('ontario-chatbot-toggle');
        chatWindow = document.getElementById('ontario-chatbot-window');
        messagesContainer = document.getElementById('ontario-chatbot-messages');
        form = document.getElementById('ontario-chatbot-form');
        input = document.getElementById('ontario-chatbot-input');
        sendBtn = document.getElementById('ontario-chatbot-send');
        badge = document.getElementById('ontario-chatbot-badge');
        iconOpen = document.getElementById('ontario-chatbot-icon-open');
        iconClose = document.getElementById('ontario-chatbot-icon-close');
        closeBtn = document.getElementById('ontario-chatbot-close');
        quickActions = document.getElementById('ontario-chatbot-quick-actions');

        // Show widget.
        widget.style.display = 'block';

        // Event listeners.
        toggle.addEventListener('click', toggleChat);
        closeBtn.addEventListener('click', closeChat);
        form.addEventListener('submit', onSubmit);

        // Quick action buttons.
        var quickBtns = quickActions.querySelectorAll('.ontario-chatbot-quick-btn');
        for (var i = 0; i < quickBtns.length; i++) {
            quickBtns[i].addEventListener('click', onQuickAction);
        }

        // Auto-open after 30 seconds on first visit.
        if (!sessionStorage.getItem('ontario_chatbot_opened')) {
            setTimeout(function () {
                if (!isOpen) {
                    // Just pulse the badge, don't auto-open (less intrusive).
                    badge.classList.remove('ontario-chatbot-hidden');
                }
            }, 5000);
        }
    }

    // ──── Toggle / Open / Close ──────────────────────────────
    function toggleChat() {
        if (isOpen) {
            closeChat();
        } else {
            openChat();
        }
    }

    function openChat() {
        isOpen = true;
        chatWindow.style.display = 'flex';
        iconOpen.style.display = 'none';
        iconClose.style.display = 'block';
        badge.classList.add('ontario-chatbot-hidden');
        sessionStorage.setItem('ontario_chatbot_opened', '1');

        // Add greeting if first open.
        if (messagesContainer.children.length === 0) {
            addBotMessage(config.greeting);
        }

        // Focus input.
        setTimeout(function () { input.focus(); }, 300);
    }

    function closeChat() {
        isOpen = false;
        chatWindow.style.display = 'none';
        iconOpen.style.display = 'block';
        iconClose.style.display = 'none';
    }

    // ──── Message Handling ────────────────────────────────────
    function onSubmit(e) {
        e.preventDefault();
        var message = input.value.trim();
        if (!message || isLoading) return;

        sendMessage(message);
        input.value = '';
    }

    function onQuickAction(e) {
        var message = e.target.getAttribute('data-message');
        if (message && !isLoading) {
            // Open chat if not already.
            if (!isOpen) openChat();
            sendMessage(message);
            // Hide quick actions after first use.
            quickActions.style.display = 'none';
        }
    }

    function sendMessage(message) {
        // Add user message to UI.
        addUserMessage(message);

        // Add to history.
        history.push({ role: 'user', content: message });

        // Check for email trigger.
        if (shouldTriggerEmailForm(message)) {
            showEmailForm();
            return;
        }

        // Show typing indicator.
        showTyping();
        isLoading = true;
        sendBtn.disabled = true;

        // Send to server via AJAX.
        var xhr = new XMLHttpRequest();
        xhr.open('POST', config.ajaxUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.timeout = 30000;

        xhr.onload = function () {
            hideTyping();
            isLoading = false;
            sendBtn.disabled = false;

            try {
                var resp = JSON.parse(xhr.responseText);
                if (resp.success && resp.data && resp.data.reply) {
                    addBotMessage(resp.data.reply);
                    history.push({ role: 'assistant', content: resp.data.reply });
                } else if (resp.data && resp.data.reply) {
                    addBotMessage(resp.data.reply);
                    history.push({ role: 'assistant', content: resp.data.reply });
                } else {
                    addBotMessage(getFallbackResponse());
                }
            } catch (err) {
                addBotMessage(getFallbackResponse());
            }

            input.focus();
        };

        xhr.onerror = function () {
            hideTyping();
            isLoading = false;
            sendBtn.disabled = false;
            addBotMessage(getFallbackResponse());
            input.focus();
        };

        xhr.ontimeout = function () {
            hideTyping();
            isLoading = false;
            sendBtn.disabled = false;
            addBotMessage('I\'m taking a bit longer than usual. Please try again, or call us directly at ' + config.phone + '.');
            input.focus();
        };

        var params = 'action=ontario_chatbot_message'
            + '&nonce=' + encodeURIComponent(config.nonce)
            + '&message=' + encodeURIComponent(message)
            + '&history=' + encodeURIComponent(JSON.stringify(history.slice(-10)));

        xhr.send(params);
    }

    function getFallbackResponse() {
        return 'Thank you for reaching out! For the fastest assistance, please fill out our intake form at monacomonuments.ca/contact/ or call us at ' + config.phone + '. Our team will be happy to help.';
    }

    // ──── UI Helpers ─────────────────────────────────────────
    function addBotMessage(text) {
        var div = document.createElement('div');
        div.className = 'ontario-chatbot-msg ontario-chatbot-msg-bot';
        div.textContent = text;
        messagesContainer.appendChild(div);
        scrollToBottom();
    }

    function addUserMessage(text) {
        var div = document.createElement('div');
        div.className = 'ontario-chatbot-msg ontario-chatbot-msg-user';
        div.textContent = text;
        messagesContainer.appendChild(div);
        scrollToBottom();
    }

    function showTyping() {
        var div = document.createElement('div');
        div.className = 'ontario-chatbot-typing';
        div.id = 'ontario-chatbot-typing-indicator';
        div.innerHTML = '<span class="ontario-chatbot-typing-dot"></span>'
            + '<span class="ontario-chatbot-typing-dot"></span>'
            + '<span class="ontario-chatbot-typing-dot"></span>';
        messagesContainer.appendChild(div);
        scrollToBottom();
    }

    function hideTyping() {
        var indicator = document.getElementById('ontario-chatbot-typing-indicator');
        if (indicator) indicator.remove();
    }

    function scrollToBottom() {
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    // ──── Email Form ─────────────────────────────────────────
    function shouldTriggerEmailForm(msg) {
        var lower = msg.toLowerCase();
        var triggers = [
            'email', 'send.*message', 'contact.*form', 'reach out',
            'leave.*message', 'get back.*me', 'call.*back', 'quote.*email',
            'send.*info', 'write.*to'
        ];
        for (var i = 0; i < triggers.length; i++) {
            if (new RegExp(triggers[i]).test(lower)) {
                return true;
            }
        }
        return false;
    }

    function showEmailForm() {
        addBotMessage('I\'d be happy to connect you with our team! Please fill out the form below and we\'ll get back to you promptly. You can also fill out our full intake form at monacomonuments.ca/contact/ for faster service.');

        history.push({ role: 'assistant', content: 'Showing email form.' });

        var formDiv = document.createElement('div');
        formDiv.className = 'ontario-chatbot-email-form';
        formDiv.innerHTML = '<h5>Send us a message</h5>'
            + '<input type="text" id="ontario-chatbot-email-name" placeholder="Your Name" required />'
            + '<input type="email" id="ontario-chatbot-email-addr" placeholder="Your Email" required />'
            + '<input type="tel" id="ontario-chatbot-email-phone" placeholder="Phone (optional)" />'
            + '<textarea id="ontario-chatbot-email-msg" placeholder="Your Message"></textarea>'
            + '<button type="button" id="ontario-chatbot-email-send">Send Message</button>';

        messagesContainer.appendChild(formDiv);
        scrollToBottom();

        document.getElementById('ontario-chatbot-email-send').addEventListener('click', function () {
            var name = document.getElementById('ontario-chatbot-email-name').value.trim();
            var email = document.getElementById('ontario-chatbot-email-addr').value.trim();
            var phone = document.getElementById('ontario-chatbot-email-phone').value.trim();
            var msg = document.getElementById('ontario-chatbot-email-msg').value.trim();

            if (!name || !email) {
                alert('Please enter your name and email.');
                return;
            }

            this.disabled = true;
            this.textContent = 'Sending...';
            var self = this;

            var xhr = new XMLHttpRequest();
            xhr.open('POST', config.ajaxUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

            xhr.onload = function () {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.success) {
                        formDiv.innerHTML = '<p style="color:#15803d;font-weight:600;margin:0;">Message sent! We\'ll get back to you soon.</p>';
                        addBotMessage('Your message has been sent to our team at info@monacomonuments.ca. We typically respond within one business day. In the meantime, feel free to browse our catalog at monacomonuments.ca/catalog/');
                    } else {
                        self.disabled = false;
                        self.textContent = 'Send Message';
                        addBotMessage('Sorry, there was an issue. Please email us directly at info@monacomonuments.ca or call ' + config.phone + '.');
                    }
                } catch (e) {
                    self.disabled = false;
                    self.textContent = 'Send Message';
                    addBotMessage('Sorry, something went wrong. Please email us at info@monacomonuments.ca.');
                }
            };

            xhr.onerror = function () {
                self.disabled = false;
                self.textContent = 'Send Message';
                addBotMessage('Sorry, there was an error. Please email us at info@monacomonuments.ca.');
            };

            var params = 'action=ontario_chatbot_send_email'
                + '&nonce=' + encodeURIComponent(config.nonce)
                + '&name=' + encodeURIComponent(name)
                + '&email=' + encodeURIComponent(email)
                + '&phone=' + encodeURIComponent(phone)
                + '&message=' + encodeURIComponent(msg || 'Customer inquiry from website chatbot');

            xhr.send(params);
        });
    }

    // ──── Initialize on DOM ready ────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
