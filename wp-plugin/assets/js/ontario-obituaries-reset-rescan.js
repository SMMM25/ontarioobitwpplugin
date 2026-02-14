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

    /* ───────── Per-source table builder (shared) ───────── */

    /**
     * Build an HTML table for a per_source object.
     * Used by both the Rescan-Only inline result and the full Reset results panel.
     *
     * @param {Object} perSource  Key=domain, value={name,found,added,errors,http_status,error_message,final_url,duration_ms}
     * @param {string} [heading]  Optional heading above the table.
     * @return {string} HTML string.
     */
    function buildPerSourceTable(perSource, heading) {
        if (!perSource || Object.keys(perSource).length === 0) {
            return '';
        }

        var html = '';
        if (heading) {
            html += '<h3>' + escHtml(heading) + '</h3>';
        }

        html += '<table class="widefat striped ontario-rr-diag-table"><thead><tr>';
        html += '<th>Source</th><th>Found</th><th>Added</th><th>Errors</th><th>HTTP</th><th>Last error</th>';
        html += '</tr></thead><tbody>';

        $.each(perSource, function(domain, ps) {
            var errCount   = (ps.errors && Array.isArray(ps.errors)) ? ps.errors.length : 0;
            var httpRaw    = (ps.http_status !== undefined && ps.http_status !== '') ? ps.http_status : '';
            var httpDisplay = httpRaw || '\u2014';
            var httpInt    = parseInt(httpRaw, 10);
            var httpClass  = '';
            if (httpRaw !== '') {
                httpClass = (httpInt >= 200 && httpInt < 400) ? 'ontario-rr-http-ok' : 'ontario-rr-http-err';
            }
            var errMsg     = ps.error_message || '';
            var errTitle   = errMsg;
            var errShort   = errMsg.length > 80 ? errMsg.substring(0, 80) + '\u2026' : errMsg;
            var finalUrl   = ps.final_url || '';
            var durationMs = ps.duration_ms || 0;
            var sourceName = ps.name || domain;

            html += '<tr>';
            html += '<td><strong>' + escHtml(sourceName) + '</strong><br><small class="ontario-rr-domain">' + escHtml(domain) + '</small></td>';
            html += '<td>' + (ps.found || 0) + '</td>';
            html += '<td>' + (ps.added || 0) + '</td>';
            html += '<td>' + errCount + '</td>';
            html += '<td class="' + httpClass + '">' + escHtml(String(httpDisplay)) + '</td>';
            html += '<td class="ontario-rr-errmsg" title="' + escAttr(errTitle) + '">' + escHtml(errShort) + '</td>';
            html += '</tr>';

            // Optional details row
            if (finalUrl || durationMs) {
                html += '<tr class="ontario-rr-details-row"><td colspan="6" style="padding-left:24px;font-size:12px;color:#666;">';
                if (finalUrl) {
                    html += '<strong>Final URL:</strong> ' + escHtml(finalUrl);
                }
                if (finalUrl && durationMs) {
                    html += ' &nbsp;|&nbsp; ';
                }
                if (durationMs) {
                    html += '<strong>Duration:</strong> ' + durationMs + ' ms';
                }
                html += '</td></tr>';
            }
        });

        html += '</tbody></table>';
        return html;
    }

    $(document).ready(function() {

        /* ───────── Rescan Only (v3.12.3) ───────── */

        $('#btn-rescan-only').on('click', function() {
            var $btn    = $(this);
            var $status = $('#rescan-only-status');

            if (!confirm('Run a rescan now? This will NOT delete any existing data.')) {
                return;
            }

            $btn.prop('disabled', true);
            $status.text('Running\u2026 This may take 30\u201390 seconds.').css('color', '#826200');

            // Remove any previous inline result
            $('#rescan-only-inline-result').remove();

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                timeout: 300000, // 5 minutes — scraping 7 sources takes time
                data: {
                    action: 'ontario_obituaries_rescan_only',
                    nonce:  nonce
                }
            }).done(function(response) {
                var d = response.data || {};
                if (response.success) {
                    $status.html('\u2705 ' + escHtml(d.message || 'Rescan complete.')).css('color', '#00a32a');
                } else {
                    var msg = d.message || 'Rescan failed.';
                    $status.html('\u274C ' + escHtml(msg)).css('color', '#d63638');
                }

                // Render inline per-source table regardless of success/error
                // (partial results are still useful on error)
                if (d.per_source && Object.keys(d.per_source).length > 0) {
                    var summaryHtml = '<p style="margin-top:12px;"><strong>Summary:</strong> '
                        + 'Found ' + (d.found || 0)
                        + ', Added ' + (d.added || 0)
                        + ', Skipped ' + (d.skipped || 0)
                        + ', Errors ' + (d.errors || 0)
                        + '</p>';
                    var tableHtml = buildPerSourceTable(d.per_source, 'Per-Source Breakdown');
                    var $result = $('<div id="rescan-only-inline-result" style="margin-top:16px;">' + summaryHtml + tableHtml + '</div>');
                    $btn.closest('.card').append($result);
                }

                $btn.prop('disabled', false);

            }).fail(function() {
                $status.html('\u274C Network error. Please try again.').css('color', '#d63638');
                $btn.prop('disabled', false);
            });
        });

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

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                timeout: 300000, // 5 minutes — scraping 7 sources takes time
                data: {
                    action:     'ontario_obituaries_reset_rescan',
                    nonce:      nonce,
                    session_id: sessionId
                }
            }).done(function(response) {
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
                        var httpInfo = ps.http_status ? ' HTTP=' + ps.http_status : '';
                        var errInfo  = ps.error_message ? ' err="' + ps.error_message + '"' : '';
                        logMsg('  → ' + domain + ': found=' + ps.found + ', added=' + ps.added + httpInfo + errInfo);
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

            // Per-source table (reuses shared builder)
            if (r.per_source && Object.keys(r.per_source).length > 0) {
                $('#results-per-source').html(buildPerSourceTable(r.per_source, 'Per-Source Breakdown'));
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

        // v3.12.2: Escape a string for use in HTML attribute values.
        function escAttr(str) {
            return escHtml(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
    });
})(jQuery);
