
/**
 * Ontario Obituaries Admin JavaScript
 */
(function($) {
    'use strict';
    
    $(document).ready(function() {
        // Delete obituary
        $('.obituaries-list').on('click', '.delete-obituary', function() {
            const button = $(this);
            const id = button.data('id');
            const row = $('#obituary-' + id);
            
            // Confirm deletion
            if (!confirm(ontario_obituaries_admin.confirm_delete)) {
                return;
            }
            
            // Disable button and show loading
            button.prop('disabled', true).text(ontario_obituaries_admin.deleting);
            
            // Send AJAX request
            $.ajax({
                url: ontario_obituaries_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'ontario_obituaries_delete',
                    id: id,
                    nonce: ontario_obituaries_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        $('#ontario-obituaries-delete-result')
                            .removeClass('hidden notice-error')
                            .addClass('notice-success')
                            .find('p')
                            .text(response.data.message);
                        
                        // Remove the row
                        row.fadeOut(300, function() {
                            $(this).remove();
                        });
                    } else {
                        // Show error message
                        $('#ontario-obituaries-delete-result')
                            .removeClass('hidden notice-success')
                            .addClass('notice-error')
                            .find('p')
                            .text(response.data.message);
                        
                        // Re-enable button
                        button.prop('disabled', false).text('Delete');
                    }
                },
                error: function() {
                    // Show error message
                    $('#ontario-obituaries-delete-result')
                        .removeClass('hidden notice-success')
                        .addClass('notice-error')
                        .find('p')
                        .text('An error occurred while processing your request.');
                    
                    // Re-enable button
                    button.prop('disabled', false).text('Delete');
                }
            });
        });
    });
})(jQuery);
