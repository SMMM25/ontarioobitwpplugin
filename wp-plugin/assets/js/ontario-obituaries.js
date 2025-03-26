
/**
 * Ontario Obituaries Frontend JavaScript
 */
jQuery(document).ready(function($) {
    // Modal functionality
    var $modal = $('#ontario-obituaries-modal');
    var $modalBody = $('#ontario-obituaries-modal-body');
    var $modalClose = $('.ontario-obituaries-modal-close');
    
    // Open modal on "Read More" click
    $('.ontario-obituaries-read-more').on('click', function() {
        var obituaryId = $(this).data('id');
        
        // Show modal
        $modal.addClass('active');
        
        // Show loading state
        $modalBody.html(
            '<div class="ontario-obituaries-modal-loading">' +
            '<div class="ontario-obituaries-spinner"></div>' +
            '<p>Loading...</p>' +
            '</div>'
        );
        
        // Fetch obituary details via AJAX
        $.ajax({
            url: ontarioObituariesData.ajaxUrl,
            data: {
                action: 'ontario_obituaries_get_detail',
                id: obituaryId,
                nonce: ontarioObituariesData.nonce
            },
            success: function(response) {
                if (response.success) {
                    $modalBody.html(response.data.html);
                } else {
                    $modalBody.html('<p>Error loading obituary details. Please try again.</p>');
                }
            },
            error: function() {
                $modalBody.html('<p>Error loading obituary details. Please try again.</p>');
            }
        });
    });
    
    // Close modal on X click
    $modalClose.on('click', function() {
        $modal.removeClass('active');
    });
    
    // Close modal on click outside content
    $modal.on('click', function(e) {
        if ($(e.target).is($modal)) {
            $modal.removeClass('active');
        }
    });
    
    // Close modal on ESC key
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $modal.hasClass('active')) {
            $modal.removeClass('active');
        }
    });
    
    // Animate cards on scroll
    if ('IntersectionObserver' in window) {
        var cards = document.querySelectorAll('.ontario-obituaries-card');
        
        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    var index = parseInt(entry.target.dataset.index, 10);
                    
                    // Add animation with delay based on card index
                    setTimeout(function() {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }, index * 100);
                    
                    // Stop observing once animated
                    observer.unobserve(entry.target);
                }
            });
        }, {
            threshold: 0.1
        });
        
        // Set initial opacity and transform
        cards.forEach(function(card) {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            observer.observe(card);
        });
    }
});
