/**
 * Ontario Obituaries Admin JavaScript
 *
 * FIX-A: Updated selectors to match admin page markup.
 *        - Delegation container: .wrap (admin page wrapper)
 *        - Delete link class: .ontario-obituaries-delete (matches PHP output)
 *        - Row identified by closest <tr> instead of #obituary-{id}
 *        - Notice container created dynamically if absent
 */
(function($) {
    'use strict';

    $(document).ready(function() {

        /**
         * Ensure a notice container exists in the admin page.
         */
        function getNoticeContainer() {
            var $container = $('#ontario-obituaries-admin-notice');
            if (!$container.length) {
                // P1-2 QC FIX: use inline display:none instead of 'hidden' class
                $container = $('<div id="ontario-obituaries-admin-notice" class="notice" style="display:none;"><p></p></div>');
                $('.wrap h1').first().after($container);
            }
            return $container;
        }

        /**
         * Delete obituary – delegated from the .wrap container so it works
         * even when the table is re-rendered.
         */
        $('.wrap').on('click', '.ontario-obituaries-delete', function(e) {
            e.preventDefault();

            var $link  = $(this);
            var id     = $link.data('id');
            var $row   = $link.closest('tr');

            if (!confirm(ontario_obituaries_admin.confirm_delete)) {
                return;
            }

            var originalText = $link.text();
            $link.addClass('disabled').css('pointer-events', 'none').text(ontario_obituaries_admin.deleting);

            $.ajax({
                url:  ontario_obituaries_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'ontario_obituaries_delete',
                    id:     id,
                    nonce:  ontario_obituaries_admin.nonce
                },
                success: function(response) {
                    var $notice = getNoticeContainer();

                    if (response.success) {
                        $notice
                            .removeClass('notice-error')
                            .addClass('notice-success is-dismissible')
                            .show()
                            .find('p')
                            .text(response.data.message);

                        $row.fadeOut(300, function() {
                            $(this).remove();
                        });
                    } else {
                        $notice
                            .removeClass('notice-success')
                            .addClass('notice-error is-dismissible')
                            .show()
                            .find('p')
                            .text(response.data.message);

                        $link.removeClass('disabled').css('pointer-events', '').text(originalText);
                    }
                },
                error: function() {
                    var $notice = getNoticeContainer();

                    $notice
                        .removeClass('notice-success')
                        .addClass('notice-error is-dismissible')
                        .show()
                        .find('p')
                        .text('An error occurred while processing your request.');

                    $link.removeClass('disabled').css('pointer-events', '').text(originalText);
                }
            });
        });
    });
})(jQuery);
