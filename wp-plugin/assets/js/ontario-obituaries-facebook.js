
/**
 * Ontario Obituaries Facebook Integration
 */
(function($) {
    'use strict';
    
    // Initialize Facebook SDK
    function initFacebook() {
        if (typeof ontario_obituaries_data === 'undefined' || !ontario_obituaries_data.fb_enabled) {
            return;
        }
        
        // Only initialize if FB app ID is provided
        if (ontario_obituaries_data.fb_app_id) {
            window.fbAsyncInit = function() {
                FB.init({
                    appId: ontario_obituaries_data.fb_app_id,
                    autoLogAppEvents: true,
                    xfbml: true,
                    version: 'v18.0'
                });
            };
            
            // Load Facebook SDK
            (function(d, s, id) {
                var js, fjs = d.getElementsByTagName(s)[0];
                if (d.getElementById(id)) return;
                js = d.createElement(s); js.id = id;
                js.src = "https://connect.facebook.net/en_US/sdk.js";
                fjs.parentNode.insertBefore(js, fjs);
            }(document, 'script', 'facebook-jssdk'));
        }
    }
    
    // Share to Facebook
    function shareToFacebook(url, name, description, callback) {
        if (typeof FB === 'undefined') {
            console.error('Facebook SDK not loaded');
            alert('Facebook sharing is not available. Please try again later.');
            return;
        }
        
        FB.ui({
            method: 'share',
            href: url,
            quote: name + ' - ' + description
        }, function(response) {
            if (callback && typeof callback === 'function') {
                callback(response);
            }
            
            if (response && !response.error_code) {
                // Show success message
                const successMessage = $('<div class="ontario-obituaries-share-success">Successfully shared to Facebook</div>');
                $('body').append(successMessage);
                
                // Remove the message after 3 seconds
                setTimeout(function() {
                    successMessage.fadeOut(300, function() {
                        $(this).remove();
                    });
                }, 3000);
            }
        });
    }
    
    $(document).ready(function() {
        // Initialize Facebook SDK
        initFacebook();
        
        // Handle Facebook share button click
        $('.ontario-obituaries-container').on('click', '.ontario-obituaries-share-fb', function(e) {
            e.preventDefault();
            
            const button = $(this);
            const url = button.data('url');
            const name = button.data('name');
            const description = button.data('description');
            
            if (!ontario_obituaries_data.fb_enabled || !ontario_obituaries_data.fb_app_id) {
                alert('Facebook sharing is not configured. Please contact the site administrator.');
                return;
            }
            
            // Share to Facebook
            shareToFacebook(url, name, description);
        });
    });
})(jQuery);
