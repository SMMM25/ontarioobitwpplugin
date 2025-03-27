
(function($) {
    'use strict';
    
    $(document).ready(function() {
        // Modal functionality
        $('.ontario-obituaries-read-more').on('click', function() {
            var obituaryId = $(this).data('id');
            $('#ontario-obituaries-modal').fadeIn(300);
            
            // Load obituary details via AJAX
            $.ajax({
                url: ontario_obituaries_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'ontario_get_obituary',
                    obituary_id: obituaryId,
                    nonce: ontario_obituaries_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#ontario-obituaries-modal-body').html(response.data);
                    } else {
                        $('#ontario-obituaries-modal-body').html('<p>Error loading obituary details.</p>');
                    }
                },
                error: function() {
                    $('#ontario-obituaries-modal-body').html('<p>Error loading obituary details.</p>');
                }
            });
        });
        
        // Close modal
        $('.ontario-obituaries-modal-close').on('click', function() {
            $('#ontario-obituaries-modal').fadeOut(300);
        });
        
        // Close modal when clicking outside
        $(window).on('click', function(event) {
            if ($(event.target).is('#ontario-obituaries-modal')) {
                $('#ontario-obituaries-modal').fadeOut(300);
            }
        });
        
        // Share to Facebook
        $('.ontario-obituaries-share-fb').on('click', function() {
            var name = $(this).data('name');
            var url = $(this).data('url');
            var description = $(this).data('description');
            
            var shareUrl = 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(url) + 
                          '&quote=' + encodeURIComponent('Obituary for ' + name + ': ' + description);
            
            window.open(shareUrl, 'Share on Facebook', 'width=600,height=400');
        });
    });
    
})(jQuery);
