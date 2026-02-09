/**
 * Ontario Obituaries Facebook Sharing (Sharer-only approach)
 *
 * FIX-B/C: Removed SDK initialization. Uses the simple Facebook sharer URL
 *          which requires no App ID and avoids SDK loading overhead.
 *          This is the SINGLE handler for .ontario-obituaries-share-fb buttons.
 *          The duplicate handler in ontario-obituaries.js has been removed.
 */
(function($) {
    'use strict';

    $(document).ready(function() {

        // Delegated click handler for share buttons (works with dynamic content)
        $('.ontario-obituaries-container').on('click', '.ontario-obituaries-share-fb', function(e) {
            e.preventDefault();

            var $btn        = $(this);
            var url         = $btn.data('url')         || '';
            var name        = $btn.data('name')        || '';
            var description = $btn.data('description') || '';

            if (!url) {
                return;
            }

            var shareUrl = 'https://www.facebook.com/sharer/sharer.php?u=' +
                           encodeURIComponent(url) +
                           '&quote=' +
                           encodeURIComponent('Obituary for ' + name + ': ' + description);

            window.open(shareUrl, 'ShareOnFacebook', 'width=600,height=400,menubar=no,toolbar=no');
        });
    });

})(jQuery);
