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
            $status.text('Running\u2026 Scanning listing pages from each source. This typically takes 1\u20132 minutes.').css('color', '#826200');

            // Remove any previous inline result
            $('#rescan-only-inline-result').remove();

            // v4.6.2: Source-by-source pattern.
            // Step 1: Get the list of active sources.
            // Step 2: Process each source one at a time (each request ~5-20s).
            // This guarantees every request finishes well within the server timeout.
            $.post(ajaxUrl, {
                action: 'ontario_obituaries_rescan_only',
                nonce:  nonce
            }, function(response) {
                if (!response.success || !response.data || !response.data.sources) {
                    var msg = (response.data && response.data.message) || 'Failed to load sources.';
                    $status.html('\u274C ' + escHtml(msg)).css('color', '#d63638');
                    $btn.prop('disabled', false);
                    return;
                }

                var sources    = response.data.sources;
                var totalSrc   = sources.length;
                var currentIdx = 0;
                var totalFound = 0;
                var totalAdded = 0;
                var allPerSource = {};

                if (totalSrc === 0) {
                    $status.html('\u26A0\uFE0F No active sources found in the Source Registry.').css('color', '#826200');
                    $btn.prop('disabled', false);
                    return;
                }

                processNextSource();

                function processNextSource() {
                    if (currentIdx >= totalSrc) {
                        // All done!
                        $status.html('\u2705 Rescan complete. Found ' + totalFound + ' obituaries, added ' + totalAdded + ' new.').css('color', '#00a32a');

                        if (Object.keys(allPerSource).length > 0) {
                            var summaryHtml = '<p style="margin-top:12px;"><strong>Summary:</strong> '
                                + 'Found ' + totalFound
                                + ', Added ' + totalAdded
                                + '</p>';
                            var tableHtml = buildPerSourceTable(allPerSource, 'Per-Source Breakdown');
                            var $result = $('<div id="rescan-only-inline-result" style="margin-top:16px;">' + summaryHtml + tableHtml + '</div>');
                            $btn.closest('.card').append($result);
                        }

                        $btn.prop('disabled', false);
                        return;
                    }

                    var src = sources[currentIdx];
                    var isLast = (currentIdx === totalSrc - 1) ? '1' : '0';
                    $status.text('Scanning source ' + (currentIdx + 1) + '/' + totalSrc + ': ' + src.name + '\u2026').css('color', '#826200');

                    $.ajax({
                        url: ajaxUrl,
                        type: 'POST',
                        timeout: 60000, // v4.6.5: 60s per source (listing pages only, no detail fetches)
                        data: {
                            action:    'ontario_obituaries_rescan_one_source',
                            nonce:     nonce,
                            source_id: src.id,
                            is_last:   isLast
                        }
                    }).done(function(resp) {
                        var d = resp.data || {};
                        totalFound += (d.found || 0);
                        totalAdded += (d.added || 0);

                        if (d.per_source) {
                            $.each(d.per_source, function(domain, ps) {
                                allPerSource[domain] = ps;
                            });
                        }

                        currentIdx++;
                        processNextSource();

                    }).fail(function(jqXHR, textStatus) {
                        // Source timed out or failed — log it and move on.
                        allPerSource[src.domain] = {
                            name: src.name,
                            found: 0,
                            added: 0,
                            errors: [textStatus === 'timeout' ? 'Request timed out (>2 min)' : 'Network error'],
                            http_status: '',
                            error_message: textStatus === 'timeout' ? 'Timed out' : 'Network error',
                            final_url: '',
                            duration_ms: 0
                        };
                        currentIdx++;
                        processNextSource();
                    });
                }

            }).fail(function() {
                $status.html('\u274C Could not load sources. Check your connection and try again.').css('color', '#d63638');
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

        /* ───────── AI Rewriter Manual Trigger (v4.6.3) ───────── */

        var rewriterRunning = false;
        var rewriterStopped = false;
        var rewriterTotalProcessed = 0;
        var rewriterTotalSucceeded = 0;
        var rewriterTotalFailed    = 0;
        var rewriterConsecutiveFailures = 0; // v4.6.5: Track consecutive total-failure batches.
        var rewriterBackoffDelay = 2000;     // v4.6.5: Adaptive delay between batches (ms).

        $('#btn-run-rewriter').on('click', function() {
            var $btn     = $(this);
            var $stopBtn = $('#btn-stop-rewriter');
            var $status  = $('#rewriter-status');
            var $prog    = $('#rewriter-progress');
            var $bar     = $('#rewriter-progress-bar');
            var $text    = $('#rewriter-progress-text');
            var $log     = $('#rewriter-log');
            var $stats   = $('#rewriter-stats');

            if (rewriterRunning) return;

            rewriterRunning = true;
            rewriterStopped = false;
            rewriterTotalProcessed = 0;
            rewriterTotalSucceeded = 0;
            rewriterTotalFailed    = 0;
            rewriterConsecutiveFailures = 0;
            rewriterBackoffDelay = 2000;

            $btn.prop('disabled', true);
            $stopBtn.show();
            $status.text('Starting AI rewriter\u2026').css('color', '#826200');
            $prog.show();
            $log.show().empty();
            $bar.css('width', '0%');

            runRewriterBatch();

            function logRewriter(msg) {
                var time = new Date().toLocaleTimeString();
                $log.append('<div>[' + time + '] ' + escHtml(msg) + '</div>');
                $log.scrollTop($log[0].scrollHeight);
            }

            function runRewriterBatch() {
                if (rewriterStopped) {
                    finishRewriter('Stopped by user.', -1);
                    return;
                }

                logRewriter('Processing batch\u2026 (up to 5 obituaries, ~30s)');

                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    timeout: 120000, // v4.6.5: 120s — 5 records × (6s + up to 15s backoff) + margin
                    data: {
                        action: 'ontario_obituaries_run_rewriter',
                        nonce:  nonce
                    }
                }).done(function(response) {
                    if (!response.success) {
                        var errMsg = (response.data && response.data.message) || 'Unknown error.';
                        logRewriter('\u274C Error: ' + errMsg);
                        finishRewriter('Error: ' + errMsg, -1);
                        return;
                    }

                    var d = response.data;
                    rewriterTotalProcessed += (d.processed || 0);
                    rewriterTotalSucceeded += (d.succeeded || 0);
                    rewriterTotalFailed    += (d.failed || 0);

                    logRewriter(
                        'Batch: ' + d.processed + ' processed, '
                        + d.succeeded + ' published, '
                        + d.failed + ' failed. '
                        + d.pending + ' still pending.'
                    );

                    // Log any errors from this batch.
                    if (d.errors && d.errors.length > 0) {
                        for (var i = 0; i < d.errors.length; i++) {
                            logRewriter('  \u26A0\uFE0F ' + d.errors[i]);
                        }
                    }

                    // Update progress bar.
                    var totalEst = rewriterTotalProcessed + (d.pending || 0);
                    var pct = totalEst > 0 ? Math.round((rewriterTotalSucceeded / totalEst) * 100) : 0;
                    $bar.css('width', pct + '%');
                    $text.text(
                        rewriterTotalSucceeded + ' published / '
                        + rewriterTotalProcessed + ' processed. '
                        + d.pending + ' remaining.'
                    );

                    // Update the stats display.
                    // v4.6.4 FIX: Correct operator precedence and calculate published count properly.
                    var currentPublished = (parseInt($stats.find('strong:eq(1)').text(), 10) || 0);
                    $stats.html(
                        '<strong style="color:orange;">' + d.pending + '</strong> pending'
                        + ' &nbsp;|&nbsp; <strong style="color:green;">'
                        + (currentPublished + d.succeeded)
                        + '</strong> published'
                    );

                    // v4.6.7: Abort immediately on auth errors — no point retrying.
                    if (d.auth_error) {
                        logRewriter('\u274C API key or model permission error. Fix the API key before retrying.');
                        logRewriter('Go to https://console.groq.com/keys to generate a new key.');
                        finishRewriter('API key or model permission error. Fix the key and try again.', d.pending || -1);
                        return;
                    }

                    if (d.done || d.pending < 1) {
                        logRewriter('\u2705 All obituaries processed!');
                        finishRewriter('Complete! ' + rewriterTotalSucceeded + ' published, ' + rewriterTotalFailed + ' failed.', 0);
                        return;
                    }

                    if (rewriterStopped) {
                        finishRewriter('Stopped. ' + d.pending + ' still pending.', d.pending);
                        return;
                    }

                    // v4.6.5: Adaptive backoff based on rate-limit status.
                    if (d.rate_limited) {
                        // Server hit rate limits — wait longer before next batch.
                        rewriterBackoffDelay = Math.min(rewriterBackoffDelay * 2, 30000);
                        logRewriter('Rate limited by Groq API. Waiting ' + (rewriterBackoffDelay / 1000) + 's before next batch\u2026');
                    } else if (d.succeeded > 0) {
                        // Success — reset backoff to normal pace.
                        rewriterBackoffDelay = 2000;
                        rewriterConsecutiveFailures = 0;
                    }

                    // v4.6.5: Track consecutive all-fail batches.
                    if (d.processed > 0 && d.succeeded === 0) {
                        rewriterConsecutiveFailures++;
                        if (rewriterConsecutiveFailures >= 5) {
                            logRewriter('\u26A0\uFE0F 5 consecutive batches with 0 published. Stopping to avoid wasting API calls.');
                            finishRewriter(
                                'Paused after 5 failed batches. ' + rewriterTotalSucceeded + ' published, '
                                + d.pending + ' still pending. Try again in a few minutes.',
                                d.pending
                            );
                            return;
                        }
                    } else if (d.succeeded > 0) {
                        rewriterConsecutiveFailures = 0;
                    }

                    logRewriter('Pausing ' + (rewriterBackoffDelay / 1000) + 's before next batch\u2026');
                    setTimeout(runRewriterBatch, rewriterBackoffDelay);

                }).fail(function(jqXHR, textStatus, errorThrown) {
                    var errMsg = '';
                    if (textStatus === 'timeout') {
                        errMsg = 'Request timed out (>90s). The batch may still be processing server-side.';
                    } else if (jqXHR.status === 403) {
                        errMsg = 'Permission denied (403). Try refreshing the page to get a fresh session.';
                    } else if (jqXHR.status === 0) {
                        errMsg = 'Connection failed. Check your internet connection.';
                    } else {
                        errMsg = 'HTTP ' + jqXHR.status + ': ' + (errorThrown || textStatus);
                    }
                    // Try to extract WordPress error from response body.
                    try {
                        var body = JSON.parse(jqXHR.responseText);
                        if (body && body.data && body.data.message) {
                            errMsg += ' \u2014 ' + body.data.message;
                        }
                    } catch(e) {}
                    logRewriter('\u274C ' + errMsg);

                    // v4.6.5: On timeout/network error, retry up to 3 times with backoff
                    // instead of immediately giving up (LiteSpeed may have killed the request
                    // but the rewriter still has pending records).
                    rewriterConsecutiveFailures++;
                    if (rewriterConsecutiveFailures < 3 && !rewriterStopped) {
                        var retryDelay = 10000 * rewriterConsecutiveFailures;
                        logRewriter('Will retry in ' + (retryDelay / 1000) + 's (' + rewriterConsecutiveFailures + '/3)\u2026');
                        setTimeout(runRewriterBatch, retryDelay);
                    } else {
                        finishRewriter(errMsg + ' ' + rewriterTotalSucceeded + ' published so far.', -1);
                    }
                });
            }

            function finishRewriter(msg, pendingLeft) {
                rewriterRunning = false;
                $stopBtn.hide();
                var isDone = msg.indexOf('Complete') !== -1 || msg.indexOf('No pending') !== -1;
                var hasErrors = rewriterTotalFailed > 0 || msg.indexOf('Error') !== -1 || msg.indexOf('error') !== -1;
                var icon = isDone ? '\u2705' : (hasErrors ? '\u274C' : '\u26A0\uFE0F');
                var color = isDone ? '#00a32a' : (hasErrors ? '#d63638' : '#826200');
                $status.html(icon + ' ' + escHtml(msg)).css('color', color);
                // v4.6.4 FIX: Disable button when all records are processed (no pending left).
                // Re-enable only if there might be more work to do.
                if (isDone || pendingLeft === 0) {
                    $btn.prop('disabled', true).text('All Done \u2714');
                } else {
                    $btn.prop('disabled', false);
                }
                // Update stats display with latest counts.
                if (rewriterTotalSucceeded > 0) {
                    logRewriter('\u2705 Published ' + rewriterTotalSucceeded + ' obituaries. Refresh the page to see updated counts.');
                }
            }
        });

        $('#btn-stop-rewriter').on('click', function() {
            rewriterStopped = true;
            $(this).prop('disabled', true).text('Stopping\u2026');
        });

        // v4.6.7: Test API Key button handler.
        $('#btn-test-api-key').on('click', function() {
            var $btn = $(this);
            var $status = $('#api-key-status');

            $btn.prop('disabled', true).text('Testing\u2026');
            $status.text('Connecting to Groq API\u2026').css('color', '#826200');

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                timeout: 30000,
                data: {
                    action: 'ontario_obituaries_validate_api_key',
                    nonce: nonce
                }
            }).done(function(response) {
                if (response.success) {
                    var status = response.data.status || 'ok';
                    var color = status === 'ok' ? '#00a32a' : '#826200';
                    var icon = status === 'ok' ? '\u2705' : '\u26A0\uFE0F';
                    $status.html(icon + ' ' + escHtml(response.data.message)).css('color', color);
                } else {
                    var msg = (response.data && response.data.message) || 'Unknown error.';
                    $status.html('\u274C ' + escHtml(msg)).css('color', '#d63638');
                }
            }).fail(function(jqXHR, textStatus) {
                $status.html('\u274C Connection failed: ' + escHtml(textStatus)).css('color', '#d63638');
            }).always(function() {
                $btn.prop('disabled', false).text('Test API Key');
            });
        });

    });
})(jQuery);
