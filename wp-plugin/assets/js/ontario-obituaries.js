
/**
 * Ontario Obituaries Frontend JavaScript
 */
(function($) {
    'use strict';
    
    // Initialize Facebook SDK
    function initFacebookSDK() {
        // Load the Facebook JavaScript SDK asynchronously
        (function(d, s, id) {
            var js, fjs = d.getElementsByTagName(s)[0];
            if (d.getElementById(id)) return;
            js = d.createElement(s); js.id = id;
            js.src = "https://connect.facebook.net/en_US/sdk.js";
            fjs.parentNode.insertBefore(js, fjs);
        }(document, 'script', 'facebook-jssdk'));
        
        // Initialize the Facebook SDK once loaded
        window.fbAsyncInit = function() {
            FB.init({
                appId: ontario_obituaries_data.fb_app_id || '', // Will be set in localized data
                version: 'v18.0',
                xfbml: true
            });
        };
    }
    
    $(document).ready(function() {
        // Initialize Facebook SDK
        initFacebookSDK();
        
        // Modal functionality
        const modal = $('#ontario-obituaries-modal');
        const modalBody = $('#ontario-obituaries-modal-body');
        const closeBtn = $('.ontario-obituaries-modal-close');
        
        // Open obituary details in modal
        $('.ontario-obituaries-grid').on('click', '.ontario-obituaries-read-more', function() {
            const id = $(this).data('id');
            
            // Show modal
            modal.addClass('ontario-obituaries-show-modal');
            
            // Show loading spinner
            modalBody.html('<div class="ontario-obituaries-modal-loading"><div class="ontario-obituaries-spinner"></div><p>Loading...</p></div>');
            
            // Fetch obituary details via AJAX
            $.ajax({
                url: ontario_obituaries_data.ajaxUrl,
                type: 'GET',
                data: {
                    action: 'ontario_obituaries_get_details',
                    id: id,
                    nonce: ontario_obituaries_data.nonce
                },
                success: function(response) {
                    if (response.success) {
                        modalBody.html(response.data.html);
                    } else {
                        modalBody.html('<p class="ontario-obituaries-error">Error loading obituary details.</p>');
                    }
                },
                error: function() {
                    modalBody.html('<p class="ontario-obituaries-error">An error occurred while loading the obituary details.</p>');
                }
            });
        });
        
        // Close modal when clicking the close button
        closeBtn.on('click', function() {
            modal.removeClass('ontario-obituaries-show-modal');
        });
        
        // Close modal when clicking outside of it
        $(window).on('click', function(event) {
            if ($(event.target).is(modal)) {
                modal.removeClass('ontario-obituaries-show-modal');
            }
        });
        
        // Share to Facebook functionality
        $('.ontario-obituaries-grid').on('click', '.ontario-obituaries-share-fb', function(e) {
            e.preventDefault();
            const button = $(this);
            const name = button.data('name');
            const url = button.data('url');
            const description = button.data('description');
            
            // First check if FB SDK is loaded
            if (typeof FB !== 'undefined') {
                shareToFacebook(name, url, description);
            } else {
                // If FB SDK isn't loaded yet, wait and try again
                alert('Preparing Facebook sharing. Please try again in a moment.');
                initFacebookSDK();
            }
        });
        
        // Function to handle sharing to Facebook
        function shareToFacebook(name, url, description) {
            FB.ui({
                method: 'share_open_graph',
                action_type: 'og.shares',
                action_properties: JSON.stringify({
                    object: {
                        'og:url': url,
                        'og:title': 'In Memory of ' + name,
                        'og:description': description,
                        'og:site_name': ontario_obituaries_data.site_name
                    }
                })
            }, function(response) {
                if (response && !response.error_code) {
                    // Success message
                    const successMsg = $('<div class="ontario-obituaries-share-success">Successfully shared to Facebook</div>');
                    $('body').append(successMsg);
                    setTimeout(function() {
                        successMsg.fadeOut(300, function() {
                            $(this).remove();
                        });
                    }, 3000);
                } else {
                    // Error message (optional)
                    console.log('Error sharing to Facebook');
                }
            });
        }
    });
})(jQuery);
