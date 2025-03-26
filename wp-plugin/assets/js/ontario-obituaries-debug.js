
jQuery(document).ready(function($) {
    // Test scraper button click handler
    $('#ontario-test-scraper').on('click', function() {
        var $button = $(this);
        var buttonText = $button.text();
        
        // Show loading state
        $button.prop('disabled', true).html('<span class="ontario-spinner"></span> Testing...');
        
        // Clear previous results
        $('#ontario-success-connections, #ontario-failed-connections, #ontario-debug-log-entries').empty();
        $('#ontario-debug-results').hide();
        
        // Make AJAX request
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'ontario_obituaries_test_scraper',
                nonce: ontario_obituaries_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Show results container
                    $('#ontario-debug-results').show();
                    
                    // Display successful connections
                    if (response.data.successful.length > 0) {
                        $.each(response.data.successful, function(index, region) {
                            $('#ontario-success-connections').append(
                                '<span class="ontario-connection-badge success">' + 
                                '<span class="dashicons dashicons-yes"></span> ' + 
                                region + 
                                '</span>'
                            );
                        });
                    } else {
                        $('#ontario-success-connections').append(
                            '<p class="description">No successful connections</p>'
                        );
                    }
                    
                    // Display failed connections
                    if (response.data.failed.length > 0) {
                        $.each(response.data.failed, function(index, region) {
                            $('#ontario-failed-connections').append(
                                '<span class="ontario-connection-badge error">' + 
                                '<span class="dashicons dashicons-warning"></span> ' + 
                                region + 
                                '</span>'
                            );
                        });
                    } else {
                        $('#ontario-failed-connections').append(
                            '<p class="description">No failed connections</p>'
                        );
                    }
                    
                    // Display log entries
                    $.each(response.data.log, function(index, entry) {
                        $('#ontario-debug-log-entries').append(
                            '<div class="ontario-log-entry">' +
                            '<span class="ontario-log-timestamp">[' + entry.timestamp + ']</span> ' +
                            '<span class="ontario-log-message ' + entry.type + '">' + entry.message + '</span>' +
                            '</div>'
                        );
                    });
                    
                    // Scroll log to bottom
                    var logContainer = document.getElementById('ontario-debug-log-entries');
                    logContainer.scrollTop = logContainer.scrollHeight;
                } else {
                    alert(response.data.message || 'An error occurred while testing the scraper.');
                }
            },
            error: function() {
                alert('An error occurred while communicating with the server.');
            },
            complete: function() {
                // Restore button state
                $button.prop('disabled', false).text(buttonText);
            }
        });
    });
});
