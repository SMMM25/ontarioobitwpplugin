/**
 * Ontario Obituaries Frontend JavaScript
 *
 * Handles the obituary detail modal via AJAX.
 *
 * FIX-C: Removed the duplicate Facebook share handler; sharing is now
 *        handled exclusively by ontario-obituaries-facebook.js.
 */
(function($) {
    'use strict';

    $(document).ready(function() {

        // ── Modal: load obituary detail via AJAX ──────────────────────────

        // Use delegation so dynamically-rendered buttons also work
        $('.ontario-obituaries-container').on('click', '.ontario-obituaries-read-more', function() {
            var obituaryId = $(this).data('id');

            // Show modal with loading spinner
            $('#ontario-obituaries-modal').fadeIn(300);
            $('#ontario-obituaries-modal-body').html(
                '<div class="ontario-obituaries-modal-loading">' +
                '<div class="ontario-obituaries-spinner"></div>' +
                '<p>Loading...</p></div>'
            );

            // BUG-01 FIX: action name, GET method, param name
            $.ajax({
                url:  ontario_obituaries_ajax.ajax_url,
                type: 'GET',
                data: {
                    action: 'ontario_obituaries_get_detail',
                    id:     obituaryId,
                    nonce:  ontario_obituaries_ajax.nonce
                },
                timeout: 15000,
                success: function(response) {
                    if (response.success && response.data && response.data.html) {
                        $('#ontario-obituaries-modal-body').html(response.data.html);
                    } else {
                        var errorMsg = (response.data && response.data.message)
                            ? response.data.message
                            : 'Error loading obituary details.';
                        $('#ontario-obituaries-modal-body').html(
                            '<p class="ontario-obituaries-error">' + errorMsg + '</p>'
                        );
                    }
                },
                error: function(xhr, status) {
                    var errorMsg = (status === 'timeout')
                        ? 'Request timed out. Please try again.'
                        : 'Error loading obituary details.';
                    $('#ontario-obituaries-modal-body').html(
                        '<p class="ontario-obituaries-error">' + errorMsg + '</p>'
                    );
                }
            });
        });

        // ── Modal: close behaviour ────────────────────────────────────────

        $('.ontario-obituaries-modal-close').on('click', function() {
            $('#ontario-obituaries-modal').fadeOut(300);
        });

        $(window).on('click', function(event) {
            if ($(event.target).is('#ontario-obituaries-modal')) {
                $('#ontario-obituaries-modal').fadeOut(300);
            }
        });

        $(document).on('keydown', function(event) {
            if (event.key === 'Escape' && $('#ontario-obituaries-modal').is(':visible')) {
                $('#ontario-obituaries-modal').fadeOut(300);
            }
        });
    });

})(jQuery);
