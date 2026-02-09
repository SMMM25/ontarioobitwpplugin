/**
 * Ontario Obituaries Frontend JavaScript
 *
 * Handles:
 *   - Per-card "Request removal" link (populates form with obituary ID)
 *   - Disclaimer "Request a removal or correction" link (general request)
 *   - Removal request form (AJAX) — P0-SUP FIX
 *
 * v3.10.1: Modal handlers removed (Quick View button no longer exists in template).
 *          Per-card removal-link handler preserved (now a text link, not flag icon).
 * FIX-C  : Removed the duplicate Facebook share handler; sharing is now
 *          handled exclusively by ontario-obituaries-facebook.js.
 * P0-SUP : Added removal-request form handling with honeypot + AJAX POST.
 */
(function($) {
    'use strict';

    $(document).ready(function() {

        var $container = $('.ontario-obituaries-container');
        var $removalForm = $('#ontario-obituaries-removal-form');
        var $removalSubmit = $('#ontario-obituaries-removal-submit');

        // ── Removal Request Form (P0-SUP FIX) ────────────────────────────

        // Show form when per-card "Request removal" text link is clicked
        // v3.10.1: link changed from flag icon to text; same class + data attrs
        $container.on('click', '.ontario-obituaries-remove-link', function(e) {
            e.preventDefault();
            var $link = $(this);
            var obituaryId   = $link.data('id');
            var obituaryName = $link.data('name');

            // Populate the hidden field and scroll to the form
            $('#removal-obituary-id').val(obituaryId);
            $removalForm.find('h3').text('Request Removal: ' + obituaryName);
            $removalForm.slideDown(300);

            // Clear previous state
            $removalForm.find('.ontario-obituaries-removal-message').remove();
            $removalSubmit.find('button[type="submit"]').prop('disabled', false).text(
                ontario_obituaries_ajax.i18n_submit || 'Submit Removal Request'
            );

            // Scroll into view
            $('html, body').animate({ scrollTop: $removalForm.offset().top - 100 }, 400);
        });

        // Show form from the disclaimer "Request a removal or correction" link (general request)
        $container.on('click', '.ontario-obituaries-request-removal', function(e) {
            e.preventDefault();
            $('#removal-obituary-id').val('');
            $removalForm.slideDown(300);
            $('html, body').animate({ scrollTop: $removalForm.offset().top - 100 }, 400);
        });

        // Cancel button
        $removalForm.on('click', '.ontario-obituaries-removal-cancel', function() {
            $removalForm.slideUp(300);
        });

        // Submit handler — AJAX POST to wp-admin/admin-ajax.php
        $removalSubmit.on('submit', function(e) {
            e.preventDefault();

            var obituaryId  = $('#removal-obituary-id').val();
            var name        = $('#removal-name').val();
            var email       = $('#removal-email').val();
            var relationship = $('#removal-relationship').val();
            var notes       = $('#removal-notes').val();
            var website     = $('#removal-website').val(); // Honeypot field

            // Basic client-side validation
            if (!obituaryId || obituaryId === '0') {
                showRemovalMessage('Please select an obituary to request removal for.', 'error');
                return;
            }
            if (!name || !name.trim()) {
                showRemovalMessage('Your name is required.', 'error');
                return;
            }
            if (!email || !email.trim()) {
                showRemovalMessage('A valid email address is required.', 'error');
                return;
            }

            // Disable button to prevent double-submission
            var $submitBtn = $removalSubmit.find('button[type="submit"]');
            $submitBtn.prop('disabled', true).text(
                ontario_obituaries_ajax.i18n_submitting || 'Submitting...'
            );

            $.ajax({
                url:  ontario_obituaries_ajax.ajax_url,
                type: 'POST',
                data: {
                    action:       'ontario_obituaries_removal_request',
                    nonce:        ontario_obituaries_ajax.nonce,
                    obituary_id:  obituaryId,
                    name:         name,
                    email:        email,
                    relationship: relationship,
                    notes:        notes,
                    website:      website   // Honeypot — must be empty
                },
                timeout: 30000,
                success: function(response) {
                    if (response.success) {
                        showRemovalMessage(response.data.message, 'success');
                        // Clear form fields
                        $removalSubmit[0].reset();
                        $('#removal-obituary-id').val('');
                    } else {
                        var msg = (response.data && response.data.message)
                            ? response.data.message
                            : 'An error occurred. Please try again.';
                        showRemovalMessage(msg, 'error');
                        $submitBtn.prop('disabled', false).text(
                            ontario_obituaries_ajax.i18n_submit || 'Submit Removal Request'
                        );
                    }
                },
                error: function(xhr, status) {
                    var msg = (status === 'timeout')
                        ? 'Request timed out. Please try again.'
                        : 'An error occurred. Please try again.';
                    showRemovalMessage(msg, 'error');
                    $submitBtn.prop('disabled', false).text(
                        ontario_obituaries_ajax.i18n_submit || 'Submit Removal Request'
                    );
                }
            });
        });

        /**
         * Display a message inside the removal form.
         */
        function showRemovalMessage(message, type) {
            $removalForm.find('.ontario-obituaries-removal-message').remove();
            var cssClass = (type === 'success')
                ? 'ontario-obituaries-removal-success'
                : 'ontario-obituaries-removal-error';
            $removalSubmit.before(
                '<div class="ontario-obituaries-removal-message ' + cssClass + '">' +
                '<p>' + $('<span>').text(message).html() + '</p>' +
                '</div>'
            );
        }

    });

})(jQuery);
