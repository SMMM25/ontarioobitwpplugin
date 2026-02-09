/**
 * Ontario Obituaries — Reset & Rescan Tool (Admin JS)
 *
 * Handles the multi-step AJAX workflow:
 *   1. Export JSON backup (direct download)
 *   2. Confirmation gate validation
 *   3. Start session (acquire lock)
 *   4. Batched purge loop (200 rows per request)
 *   5. Rescan via Source Collector (single request)
 *   6. Results display
 *
 * @package Ontario_Obituaries
 * @since   3.11.0
 */
(function($) {
    'use strict';

    // Localized data from PHP
    var config = window.ontarioResetRescan || {};
    var ajaxUrl    = config.ajaxUrl || '';
    var nonce      = config.nonce   || '';
    var sessionId  = '';

    $(document).ready(function() {

        /* ───────── Gate validation ───────── */

        var $gateUnderstand = $('#gate-understand');
        var $gateBackup     = $('#gate-backup');
        var $gatePhrase     = $('#gate-phrase');
        var $btnStart       = $('#btn-start-reset');

        function checkGates() {
            var ok = $gateUnderstand.is(':checked')
                  && $gateBackup.is(':checked')
                  && $gatePhrase.val().trim() === 'DELETE ALL OBITUARIES';
            $btnStart.prop('disabled', !ok);
        }

        $gateUnderstand.on('change', checkGates);
        $gateBackup.on('change', checkGates);
        $gatePhrase.on('input', checkGates);

        /* ───────── Export ───────── */

        $('#btn-export-json').on('click', function() {
            var $btn    = $(this);
            var $status = $('#export-status');

            $btn.prop('disabled', true).text('Exporting…');
            $status.text('');

            // Use a hidden form-post approach to trigger the download
            // This avoids AJAX blob issues; we POST to admin-ajax.php
            var $form = $('<form>', {
                method: 'POST',
                action: ajaxUrl,
                target: '_blank'
            }).append(
                $('<input>', { type: 'hidden', name: 'action', value: 'ontario_obituaries_reset_export' }),
                $('<input>', { type: 'hidden', name: 'nonce',  value: nonce })
            );

            $('body').append($form);
            $form.submit();
            $form.remove();

            setTimeout(function() {
                $btn.prop('disabled', false).text('Download JSON Backup');
                $status.text('✅ Export initiated — check your downloads.');
            }, 1500);
        });

        /* ───────── Cancel ───────── */

        $('#btn-cancel-reset').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).text('Cancelling…');

            $.post(ajaxUrl, {
                action:  'ontario_obituaries_reset_cancel',
                nonce:   nonce
            }, function(response) {
                if (response.success) {
                    // Reload page to show clean state
                    location.reload();
                } else {
                    alert(response.data && response.data.message ? response.data.message : 'Cancel failed.');
                    $btn.prop('disabled', false).text('Cancel & Unlock');
                }
            }).fail(function() {
                alert('Network error cancelling reset.');
                $btn.prop('disabled', false).text('Cancel & Unlock');
            });
        });

        /* ───────── Resume ───────── */

        $('#btn-resume-reset').on('click', function() {
            var progress = config.progress || {};
            sessionId = progress.session_id || '';

            if (!sessionId) {
                alert('No session ID found. Please cancel and start fresh.');
                return;
            }

            // Show progress panel, hide lock notice + main panel
            $('#reset-lock-notice').hide();
            $('#reset-main-panel').hide();
            showProgressPanel();

            if (progress.phase === 'purge') {
                var remaining = (progress.total_rows_at_start || 0) - (progress.deleted_rows_count || 0);
                logMsg('Resuming purge. Approx ' + remaining + ' rows remaining.');
                purgeBatchLoop();
            } else if (progress.phase === 'rescan') {
                logMsg('Resuming rescan…');
                doRescan();
            } else {
                logMsg('Unknown phase: ' + progress.phase + '. Please cancel and retry.');
            }
        });

        /* ───────── Start ───────── */

        $btnStart.on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true);

            $.post(ajaxUrl, {
                action:         'ontario_obituaries_reset_start',
                nonce:          nonce,
                gate_understand: $gateUnderstand.is(':checked') ? '1' : '0',
                gate_backup:     $gateBackup.is(':checked')     ? '1' : '0',
                gate_phrase:     $gatePhrase.val().trim()
            }, function(response) {
                if (!response.success) {
                    alert(response.data && response.data.message ? response.data.message : 'Start failed.');
                    $btn.prop('disabled', false);
                    return;
                }

                sessionId = response.data.session_id;
                var totalRows = response.data.total_rows || 0;

                // Switch to progress view
                $('#reset-main-panel').hide();
                showProgressPanel();

                logMsg('Session started. ' + formatNum(totalRows) + ' rows to purge.');
                logMsg('Batch size: ' + response.data.batch_size + ' rows per request.');

                purgeBatchLoop();

            }).fail(function() {
                alert('Network error starting reset. Please try again.');
                $btn.prop('disabled', false);
            });
        });

        /* ───────── Batched purge loop ───────── */

        function purgeBatchLoop() {
            updatePhase('Purging obituaries…');

            $.post(ajaxUrl, {
                action:     'ontario_obituaries_reset_purge',
                nonce:      nonce,
                session_id: sessionId
            }, function(response) {
                if (!response.success) {
                    updatePhase('❌ Purge error');
                    logMsg('ERROR: ' + (response.data && response.data.message ? response.data.message : 'Unknown error'));
                    return;
                }

                var d = response.data;
                var deleted   = d.total_deleted || 0;
                var remaining = d.remaining     || 0;
                var total     = deleted + remaining;
                var pct       = total > 0 ? Math.round((deleted / total) * 100) : 100;

                updateProgress(pct);
                updateDetail('Deleted ' + formatNum(deleted) + ' of ' + formatNum(total) + ' rows (' + remaining + ' remaining)');
                logMsg('Batch: deleted ' + d.deleted_this_batch + ' rows. ' + remaining + ' remaining.');

                if (d.done) {
                    logMsg('✅ Purge complete. All obituary rows deleted.');
                    logMsg('Starting rescan…');
                    doRescan();
                } else {
                    // Continue loop
                    purgeBatchLoop();
                }
            }).fail(function() {
                updatePhase('❌ Network error during purge');
                logMsg('Network error during purge batch. You can Resume after checking your connection.');
            });
        }

        /* ───────── Rescan ───────── */

        function doRescan() {
            updatePhase('Running Source Collector (first-page cap)…');
            updateProgress(100); // Purge done, rescan is indeterminate
            updateDetail('Scanning active sources… This may take 30–90 seconds.');

            $.post(ajaxUrl, {
                action:     'ontario_obituaries_reset_rescan',
                nonce:      nonce,
                session_id: sessionId
            }, function(response) {
                if (!response.success) {
                    updatePhase('⚠️ Rescan had issues');
                    logMsg('Rescan error: ' + (response.data && response.data.message ? response.data.message : 'Unknown'));
                    // Still show partial results if available
                    if (response.data && response.data.last_result) {
                        showResults(response.data.last_result);
                    }
                    return;
                }

                var d = response.data;
                logMsg('✅ Rescan complete. Found: ' + d.found + ', Added: ' + d.added + ', Skipped: ' + d.skipped + ', Errors: ' + d.errors);

                if (d.per_source) {
                    $.each(d.per_source, function(domain, ps) {
                        logMsg('  → ' + domain + ': found=' + ps.found + ', added=' + ps.added);
                    });
                }

                showResults(d.last_result);

            }).fail(function() {
                updatePhase('❌ Network error during rescan');
                logMsg('Network error during rescan. The collector may still have completed server-side. Refresh the page to check.');
            });
        }

        /* ───────── Results display ───────── */

        function showResults(lastResult) {
            $('#reset-progress-panel').hide();
            var $panel = $('#reset-results-panel').show();

            var r  = lastResult.rescan_result || {};
            var dp = lastResult.deleted_rows_count || 0;

            // Summary
            var summaryHtml = '<p><strong>Rows purged:</strong> ' + formatNum(dp) + '</p>';
            summaryHtml += '<p><strong>Rescan results:</strong> Found ' + (r.found || 0) + ', Added ' + (r.added || 0) + ', Skipped ' + (r.skipped || 0) + ', Errors ' + (r.errors || 0) + '</p>';
            $('#results-summary').html(summaryHtml);

            // Per-source table
            if (r.per_source && Object.keys(r.per_source).length > 0) {
                var tableHtml = '<h3>Per-Source Breakdown</h3><table class="widefat striped"><thead><tr><th>Source</th><th>Found</th><th>Added</th><th>Errors</th></tr></thead><tbody>';
                $.each(r.per_source, function(domain, ps) {
                    var errCount = (ps.errors && ps.errors.length) ? ps.errors.length : 0;
                    tableHtml += '<tr><td>' + escHtml(domain) + '</td><td>' + ps.found + '</td><td>' + ps.added + '</td><td>' + errCount + '</td></tr>';
                });
                tableHtml += '</tbody></table>';
                $('#results-per-source').html(tableHtml);
            }

            // Next steps
            var nextHtml = '<strong>Next Steps:</strong><ul style="margin-top:4px;">';
            if ((r.added || 0) > 0) {
                nextHtml += '<li>✅ New obituaries were added. Check the <a href="' + config.viewUrl + '">Obituaries admin page</a> and the <a href="' + config.frontendUrl + '" target="_blank">frontend</a>.</li>';
                nextHtml += '<li>The regular cron schedule will continue to collect additional pages over time.</li>';
            } else {
                nextHtml += '<li>⚠️ The rescan found 0 new obituaries. Possible causes:</li>';
                nextHtml += '<li style="margin-left:20px;">• All active sources are using adapters with stale selectors (check Debug page).</li>';
                nextHtml += '<li style="margin-left:20px;">• Network connectivity issues from this server to source websites.</li>';
                nextHtml += '<li style="margin-left:20px;">• All enabled sources are circuit-broken or disabled.</li>';
                nextHtml += '<li>Check <a href="' + config.debugUrl + '">Debug Tools</a> for last scrape errors.</li>';
            }
            nextHtml += '</ul>';
            $('#results-next-steps').html(nextHtml);
        }

        /* ───────── UI helpers ───────── */

        function showProgressPanel() {
            $('#reset-progress-panel').show();
            $('#progress-log').empty();
        }

        function updatePhase(text) {
            $('#progress-phase').text(text);
        }

        function updateProgress(pct) {
            $('#progress-bar').css('width', Math.min(100, pct) + '%');
        }

        function updateDetail(text) {
            $('#progress-detail').text(text);
        }

        function logMsg(msg) {
            var time = new Date().toLocaleTimeString();
            var $log = $('#progress-log');
            $log.append('<div>[' + time + '] ' + escHtml(msg) + '</div>');
            $log.scrollTop($log[0].scrollHeight);
        }

        function formatNum(n) {
            return Number(n).toLocaleString();
        }

        function escHtml(str) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }
    });
})(jQuery);
