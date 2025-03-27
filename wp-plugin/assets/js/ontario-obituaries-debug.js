
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
        
        // Also log to browser console for additional debugging
        console.log('[Ontario Obituaries] ' + message);
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
        // Add validation for URL and region
        if (!region) {
            addLogMessage('Region name is required', 'error');
            return;
        }
        
        if (!url) {
            addLogMessage('No URL provided for ' + region, 'error');
            updateRegionStatus(region, 'Error: No URL provided', false);
            continueTestingAll();
            return;
        }
        
        var data = {
            action: 'ontario_obituaries_test_connection',
            nonce: nonce,
            region: region,
            url: url
        };
        
        addLogMessage('Testing connection to ' + region + ' (' + url + ')...', 'info');
        
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: data,
            dataType: 'json',
            timeout: 30000, // 30 seconds timeout
            success: function(response) {
                if (response && response.success) {
                    var message = response.data.message;
                    addLogMessage(region + ': ' + message, 'success');
                    updateRegionStatus(region, 'Success: ' + message, true);
                } else {
                    var message = response && response.data ? response.data.message : 'Unknown error';
                    addLogMessage(region + ': ' + message, 'error');
                    updateRegionStatus(region, 'Failed: ' + message, false);
                }
                
                continueTestingAll();
            },
            error: function(xhr, status, error) {
                var errorMessage = '';
                
                if (status === 'timeout') {
                    errorMessage = 'Request timed out. The server took too long to respond.';
                } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMessage = xhr.responseJSON.data.message;
                } else {
                    errorMessage = error || 'Unknown AJAX error';
                }
                
                addLogMessage(region + ': AJAX error - ' + errorMessage, 'error');
                updateRegionStatus(region, 'Error: ' + errorMessage, false);
                
                continueTestingAll();
            }
        });
    }
    
    /**
     * Continue testing all regions if needed
     */
    function continueTestingAll() {
        if (!isTestingAll) {
            return;
        }
        
        currentRegionIndex++;
        if (currentRegionIndex < regionsToTest.length) {
            setTimeout(function() {
                testNextRegion();
            }, 1000); // Add a small delay between requests
        } else {
            isTestingAll = false;
            $('#ontario-test-all-regions').text('Test All Regions').prop('disabled', false);
            addLogMessage('Completed testing all regions.', 'info');
        }
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
     * Save URL for a region
     */
    function saveRegionUrl(region, url) {
        var data = {
            action: 'ontario_obituaries_save_region_url',
            nonce: nonce,
            region: region,
            url: url
        };
        
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: data,
            success: function(response) {
                if (response.success) {
                    addLogMessage('Saved URL for ' + region, 'success');
                } else {
                    addLogMessage('Failed to save URL for ' + region + ': ' + (response.data ? response.data.message : 'Unknown error'), 'error');
                }
            },
            error: function(xhr, status, error) {
                addLogMessage('Error saving URL for ' + region + ': ' + error, 'error');
            }
        });
    }
    
    /**
     * Add test data to the database
     */
    function addTestData() {
        var data = {
            action: 'ontario_obituaries_add_test_data',
            nonce: nonce
        };
        
        $('#ontario-add-test-data').text('Adding...').prop('disabled', true);
        addLogMessage('Adding test obituaries to the database...', 'info');
        
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: data,
            dataType: 'json',
            success: function(response) {
                if (response && response.success) {
                    addLogMessage(response.data.message, 'success');
                } else {
                    var message = response && response.data ? response.data.message : 'Unknown error';
                    addLogMessage('Failed to add test data: ' + message, 'error');
                }
                $('#ontario-add-test-data').text('Add Test Data').prop('disabled', false);
            },
            error: function(xhr, status, error) {
                addLogMessage('Error adding test data: ' + error, 'error');
                $('#ontario-add-test-data').text('Add Test Data').prop('disabled', false);
            }
        });
    }
    
    /**
     * Check database connection
     */
    function checkDatabaseConnection() {
        var data = {
            action: 'ontario_obituaries_check_database',
            nonce: nonce
        };
        
        $('#ontario-check-database').text('Checking...').prop('disabled', true);
        addLogMessage('Checking database connection...', 'info');
        
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: data,
            dataType: 'json',
            success: function(response) {
                if (response && response.success) {
                    addLogMessage('Database check: ' + response.data.message, 'success');
                } else {
                    var message = response && response.data ? response.data.message : 'Unknown error';
                    addLogMessage('Database check failed: ' + message, 'error');
                }
                $('#ontario-check-database').text('Check Database').prop('disabled', false);
            },
            error: function(xhr, status, error) {
                addLogMessage('Error checking database: ' + error, 'error');
                $('#ontario-check-database').text('Check Database').prop('disabled', false);
            }
        });
    }
    
    /**
     * Event handler for URL input changes
     */
    $('.region-url').on('change', function() {
        var region = $(this).data('region');
        var url = $(this).val();
        
        if (region && url) {
            saveRegionUrl(region, url);
        }
    });
    
    /**
     * Event handler for test region button
     */
    $('.test-region').on('click', function() {
        var region = $(this).data('region');
        var url = $('.region-url[data-region="' + region + '"]').val();
        
        // Clear previous results for this region
        updateRegionStatus(region, 'Testing...', false);
        
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
    
    /**
     * Event handler for add test data button
     */
    $('#ontario-add-test-data').on('click', function() {
        addTestData();
    });
    
    /**
     * Event handler for check database button
     */
    $('#ontario-check-database').on('click', function() {
        checkDatabaseConnection();
    });
    
    /**
     * Event handler for run manual scrape button
     */
    $('#ontario-run-scrape').on('click', function() {
        var $button = $(this);
        $button.text('Running...').prop('disabled', true);
        addLogMessage('Running manual scrape...', 'info');
        
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'ontario_obituaries_run_scrape',
                nonce: nonce
            },
            dataType: 'json',
            success: function(response) {
                if (response && response.success) {
                    addLogMessage('Scrape completed: ' + response.data.message, 'success');
                } else {
                    var message = response && response.data ? response.data.message : 'Unknown error';
                    addLogMessage('Scrape failed: ' + message, 'error');
                }
                $button.text('Run Manual Scrape').prop('disabled', false);
            },
            error: function(xhr, status, error) {
                addLogMessage('Error during scrape: ' + error, 'error');
                $button.text('Run Manual Scrape').prop('disabled', false);
            }
        });
    });
    
    // Initialize the page
    addLogMessage('Debug page loaded. Ready to test connections.', 'info');
});
