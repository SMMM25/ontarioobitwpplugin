
/**
 * Ontario Obituaries Debug JavaScript
 */
jQuery(document).ready(function($) {
    // Variables
    var ajaxUrl = ontarioObituariesData.ajaxUrl;
    var nonce = ontarioObituariesData.nonce;
    var isTestingAll = false;
    var regionsToTest = [];
    var currentRegionIndex = 0;
    
    /**
     * Add a log message to the debug log
     */
    function addLogMessage(message, type) {
        var logClass = 'log-' + (type || 'info');
        var timestamp = new Date().toLocaleTimeString();
        var logItem = $('<div class="log-item ' + logClass + '"></div>');
        
        logItem.text('[' + timestamp + '] ' + message);
        $('#ontario-debug-log').prepend(logItem);
    }
    
    /**
     * Update region status
     */
    function updateRegionStatus(region, status, success) {
        var statusClass = success ? 'success' : 'error';
        $('.region-status[data-region="' + region + '"]')
            .text(status)
            .removeClass('success error')
            .addClass(statusClass);
    }
    
    /**
     * Test connection to a region
     */
    function testRegionConnection(region, url) {
        var data = {
            action: 'ontario_obituaries_test_connection',
            nonce: nonce,
            region: region
        };
        
        if (url) {
            data.url = url;
        }
        
        addLogMessage('Testing connection to ' + region + '...', 'info');
        
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: data,
            success: function(response) {
                if (response.success) {
                    var message = response.data.message;
                    addLogMessage(region + ': ' + message, 'success');
                    updateRegionStatus(region, 'Success: ' + message, true);
                } else {
                    var message = response.data.message;
                    addLogMessage(region + ': ' + message, 'error');
                    updateRegionStatus(region, 'Failed: ' + message, false);
                }
                
                // If we're testing all regions, continue with the next one
                if (isTestingAll) {
                    currentRegionIndex++;
                    if (currentRegionIndex < regionsToTest.length) {
                        testNextRegion();
                    } else {
                        isTestingAll = false;
                        $('#ontario-test-all-regions').text('Test All Regions').prop('disabled', false);
                        addLogMessage('Completed testing all regions.', 'info');
                    }
                }
            },
            error: function(xhr, status, error) {
                addLogMessage(region + ': AJAX error - ' + error, 'error');
                updateRegionStatus(region, 'Error: ' + error, false);
                
                // If we're testing all regions, continue with the next one
                if (isTestingAll) {
                    currentRegionIndex++;
                    if (currentRegionIndex < regionsToTest.length) {
                        testNextRegion();
                    } else {
                        isTestingAll = false;
                        $('#ontario-test-all-regions').text('Test All Regions').prop('disabled', false);
                        addLogMessage('Completed testing all regions.', 'info');
                    }
                }
            }
        });
    }
    
    /**
     * Test the next region in the queue
     */
    function testNextRegion() {
        var region = regionsToTest[currentRegionIndex];
        var url = $('.region-url[data-region="' + region + '"]').val();
        testRegionConnection(region, url);
    }
    
    /**
     * Event handler for test region button
     */
    $('.test-region').on('click', function() {
        var region = $(this).data('region');
        var url = $('.region-url[data-region="' + region + '"]').val();
        testRegionConnection(region, url);
    });
    
    /**
     * Event handler for test all regions button
     */
    $('#ontario-test-all-regions').on('click', function() {
        if (isTestingAll) {
            return;
        }
        
        isTestingAll = true;
        $(this).text('Testing...').prop('disabled', true);
        
        // Clear the log
        $('#ontario-debug-log').empty();
        addLogMessage('Starting to test all regions...', 'info');
        
        // Get all regions
        regionsToTest = [];
        $('.test-region').each(function() {
            regionsToTest.push($(this).data('region'));
        });
        
        // Reset the index and start testing
        currentRegionIndex = 0;
        testNextRegion();
    });
});
