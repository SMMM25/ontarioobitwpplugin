/**
 * Ontario Obituaries Frontend JavaScript
 *
 * Handles:
 *   - Per-card "Request removal" link (populates form with obituary ID)
 *   - Disclaimer "Request a removal or correction" link (general request)
 *   - Removal request form (AJAX) — P0-SUP FIX
 *   - Obituary search for general removal requests — v6.0.1
 *
 * v3.10.1: Modal handlers removed (Quick View button no longer exists in template).
 *          Per-card removal-link handler preserved (now a text link, not flag icon).
 * FIX-C  : Removed the duplicate Facebook share handler; sharing is now
 *          handled exclusively by ontario-obituaries-facebook.js.
 * P0-SUP : Added removal-request form handling with honeypot + AJAX POST.
 * v6.0.1 : Added obituary search autocomplete for the general "Request a removal
 *           or correction" link. When the form opens without a pre-selected obituary,
 *           a search field appears so the user can find and select the correct listing.
 */
(function($) {
    'use strict';

    $(document).ready(function() {

        var $container = $('.ontario-obituaries-container');
        var $removalForm = $('#ontario-obituaries-removal-form');
        var $removalSubmit = $('#ontario-obituaries-removal-submit');
        var $searchWrap = $('#removal-obituary-search-wrap');
        var $searchInput = $('#removal-obituary-search');
        var $searchResults = $('#removal-obituary-results');
        var $selectedDisplay = $('#removal-obituary-selected');
        var searchTimer = null;

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

            // v6.0.1: Hide the search field — obituary is already selected via card link
            $searchWrap.hide();
            $searchInput.val('');
            $searchResults.hide().empty();
            $selectedDisplay.hide().text('');

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
            $removalForm.find('h3').text(ontario_obituaries_ajax.i18n_removal_title || 'Request Obituary Removal');

            // v6.0.1: Show the obituary search field since no obituary was pre-selected
            $searchWrap.show();
            $searchInput.val('');
            $searchResults.hide().empty();
            $selectedDisplay.hide().text('');

            $removalForm.slideDown(300);

            // Clear previous state
            $removalForm.find('.ontario-obituaries-removal-message').remove();
            $removalSubmit.find('button[type="submit"]').prop('disabled', false).text(
                ontario_obituaries_ajax.i18n_submit || 'Submit Removal Request'
            );

            $('html, body').animate({ scrollTop: $removalForm.offset().top - 100 }, 400);

            // Focus the search field after animation
            setTimeout(function() { $searchInput.focus(); }, 400);
        });

        // ── v6.0.1: Obituary Search Autocomplete ─────────────────────────

        $searchInput.on('input', function() {
            var query = $.trim($(this).val());

            clearTimeout(searchTimer);
            $searchResults.hide().empty();

            if (query.length < 2) {
                return;
            }

            searchTimer = setTimeout(function() {
                $.ajax({
                    url:  ontario_obituaries_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'ontario_obituaries_search_names',
                        nonce:  ontario_obituaries_ajax.nonce,
                        q:      query
                    },
                    timeout: 10000,
                    success: function(response) {
                        if (response.success && response.data && response.data.length > 0) {
                            $searchResults.empty();
                            $.each(response.data, function(i, item) {
                                var $li = $('<li>')
                                    .attr('data-id', item.id)
                                    .attr('data-name', item.name)
                                    .text(item.name + (item.location ? ' — ' + item.location : ''))
                                    .on('click', function() {
                                        var selectedId = $(this).data('id');
                                        var selectedName = $(this).data('name');
                                        $('#removal-obituary-id').val(selectedId);
                                        $searchInput.val('');
                                        $searchResults.hide().empty();
                                        $selectedDisplay.html(
                                            '<strong>' + $('<span>').text(selectedName).html() + '</strong>' +
                                            ' <a href="#" class="ontario-obituaries-change-obituary">' +
                                            (ontario_obituaries_ajax.i18n_change || 'Change') + '</a>'
                                        ).show();
                                        $removalForm.find('h3').text(
                                            (ontario_obituaries_ajax.i18n_removal_prefix || 'Request Removal: ') + selectedName
                                        );
                                    });
                                $searchResults.append($li);
                            });
                            $searchResults.show();
                        } else {
                            $searchResults.empty().append(
                                $('<li>').addClass('no-results').text(
                                    ontario_obituaries_ajax.i18n_no_results || 'No obituaries found. Try a different search.'
                                )
                            ).show();
                        }
                    }
                });
            }, 300); // 300ms debounce
        });

        // "Change" link to re-search
        $removalForm.on('click', '.ontario-obituaries-change-obituary', function(e) {
            e.preventDefault();
            $('#removal-obituary-id').val('');
            $selectedDisplay.hide().text('');
            $searchInput.val('').show().focus();
            $removalForm.find('h3').text(ontario_obituaries_ajax.i18n_removal_title || 'Request Obituary Removal');
        });

        // Hide results when clicking outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('#removal-obituary-search-wrap').length) {
                $searchResults.hide();
            }
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
                // v6.0.1: If search wrap is visible, focus it for convenience
                if ($searchWrap.is(':visible')) {
                    $searchInput.focus();
                }
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
                        $searchInput.val('');
                        $searchResults.hide().empty();
                        $selectedDisplay.hide().text('');
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
