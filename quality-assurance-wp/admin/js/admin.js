/**
 * Quality Assurance WP - Admin JavaScript
 */
(function($) {
    'use strict';

    const QA = {
        apiBase: flavorQA.apiBase,
        nonce: flavorQA.nonce,

        /** How many posts to process per AJAX batch */
        BATCH_SIZE: 3,

        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            $('#flavor-qa-start-scan').on('click', this.startScan.bind(this));
            $(document).on('click', '.flavor-qa-fix-issue', this.fixIssue.bind(this));
            $(document).on('click', '.flavor-qa-resolve-issue', this.resolveIssue.bind(this));
            $(document).on('click', '.flavor-qa-apply-bulk', this.applyBulkAction.bind(this));
            $('#flavor-qa-fix-all').on('click', this.fixAll.bind(this));
            $('#flavor-qa-install-puppeteer').on('click', this.installPuppeteer.bind(this));
            $(document).on('click', '.flavor-qa-fix-category', this.fixCategory.bind(this));
            $('#flavor-qa-dismiss-all').on('click', this.dismissAll.bind(this));
            $(document).on('change', '#flavor-qa-select-all', function() {
                $('input[name="issue_ids[]"], input[name="link_ids[]"]').prop('checked', this.checked);
            });
            $(document).on('click', '.flavor-qa-delete-scan', this.deleteScan.bind(this));
        },

        // ─── API Helpers ────────────────────────────────────

        apiRequest: function(endpoint, method, data) {
            return $.ajax({
                url: this.apiBase + endpoint,
                method: method || 'GET',
                data: data ? JSON.stringify(data) : undefined,
                contentType: 'application/json',
                timeout: 120000, // 2 min timeout for batch processing.
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', QA.nonce);
                }
            });
        },

        // ─── Scan (Chunked AJAX) ────────────────────────────

        startScan: function(e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const types = [];

            $('input[name="scan_types[]"]:checked').each(function() {
                types.push($(this).val());
            });

            if (types.length === 0) {
                alert('Please select at least one scan type.');
                return;
            }

            $btn.prop('disabled', true).text(flavorQA.i18n.scanning);
            $('#flavor-qa-scan-progress').show();
            $('#flavor-qa-scan-log').show().find('.flavor-qa-log-entries').empty();

            this.apiRequest('scan/start', 'POST', { types: types })
                .done(function(response) {
                    QA.logAppend('info', 'Scan #' + response.scan_id + ' started — ' +
                        response.total_posts + ' pages, ' + types.join(', '));

                    // Start processing batches.
                    QA.processScanBatch(response.scan_id, 0, response.total_posts, response.total_items);
                })
                .fail(function(xhr) {
                    const msg = xhr.responseJSON ? xhr.responseJSON.message : flavorQA.i18n.error;
                    QA.logAppend('error', 'Failed to start scan: ' + msg);
                    $btn.prop('disabled', false).text('Start Full Scan');
                });
        },

        /**
         * Process the next batch of posts, then call itself for the next chunk.
         */
        processScanBatch: function(scanId, offset, totalPosts, totalItems) {
            this.apiRequest('scan/process-batch', 'POST', {
                scan_id: scanId,
                offset: offset,
                limit: this.BATCH_SIZE,
            })
            .done(function(response) {
                // Append log entries.
                if (response.log) {
                    response.log.forEach(function(entry) {
                        QA.logAppend(entry.type, entry.message);
                    });
                }

                // Update progress bar.
                var pct = response.progress || 0;
                $('.flavor-qa-progress-fill').css('width', pct + '%');
                $('.flavor-qa-progress-text').text(Math.round(pct) + '%');
                $('.flavor-qa-progress-status').text(
                    response.completed_items + ' / ' + response.total_items + ' items'
                );

                if (response.is_complete) {
                    QA.logAppend('complete', 'All done! Reloading results...');
                    $('#flavor-qa-start-scan').prop('disabled', false).text('Start Full Scan');
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    // Process next batch immediately.
                    var nextOffset = offset + QA.BATCH_SIZE;
                    QA.processScanBatch(scanId, nextOffset, totalPosts, totalItems);
                }
            })
            .fail(function(xhr) {
                var msg = 'Request failed';
                if (xhr.responseJSON) {
                    // Show detailed PHP error if available.
                    msg = xhr.responseJSON.message || xhr.responseJSON.data?.message || JSON.stringify(xhr.responseJSON).substring(0, 300);
                } else if (xhr.responseText) {
                    // Strip HTML tags for readability.
                    msg = xhr.responseText.replace(/<[^>]*>/g, ' ').substring(0, 300).trim();
                }
                QA.logAppend('error', 'Batch error: ' + msg);
                QA.logAppend('info', 'Retrying...');

                // Retry this batch once after a short delay.
                setTimeout(function() {
                    QA.apiRequest('scan/process-batch', 'POST', {
                        scan_id: scanId,
                        offset: offset,
                        limit: QA.BATCH_SIZE,
                    })
                    .done(function(response) {
                        if (response.log) {
                            response.log.forEach(function(entry) {
                                QA.logAppend(entry.type, entry.message);
                            });
                        }
                        var pct = response.progress || 0;
                        $('.flavor-qa-progress-fill').css('width', pct + '%');
                        $('.flavor-qa-progress-text').text(Math.round(pct) + '%');

                        if (response.is_complete) {
                            QA.logAppend('complete', 'All done! Reloading...');
                            setTimeout(function() { location.reload(); }, 2000);
                        } else {
                            QA.processScanBatch(scanId, offset + QA.BATCH_SIZE, totalPosts, totalItems);
                        }
                    })
                    .fail(function() {
                        QA.logAppend('error', 'Batch failed again. Skipping to next batch.');
                        QA.processScanBatch(scanId, offset + QA.BATCH_SIZE, totalPosts, totalItems);
                    });
                }, 2000);
            });
        },

        /**
         * Append a message to the live scan log.
         */
        logAppend: function(type, message) {
            var $log = $('#flavor-qa-scan-log .flavor-qa-log-entries');
            var time = new Date().toLocaleTimeString();
            var cssClass = 'flavor-qa-log-' + type;
            $log.append(
                '<div class="flavor-qa-log-entry ' + cssClass + '">' +
                '<span class="flavor-qa-log-time">' + time + '</span> ' +
                message +
                '</div>'
            );
            // Auto-scroll to bottom.
            $log.scrollTop($log[0].scrollHeight);
        },

        // ─── Fix Issue ──────────────────────────────────────

        fixIssue: function(e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const issueId = $btn.data('issue-id');

            if (!confirm(flavorQA.i18n.confirm_fix)) return;

            $btn.prop('disabled', true).text('Fixing...');

            this.apiRequest('issues/fix', 'POST', { issue_id: issueId })
                .done(function() {
                    $btn.closest('tr').fadeOut(300, function() { $(this).remove(); });
                })
                .fail(function(xhr) {
                    const msg = xhr.responseJSON ? xhr.responseJSON.message : flavorQA.i18n.error;
                    alert(msg);
                    $btn.prop('disabled', false).text('Fix');
                });
        },

        // ─── Resolve Issue ──────────────────────────────────

        resolveIssue: function(e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const issueId = $btn.data('issue-id');

            $btn.prop('disabled', true);

            this.apiRequest('issues/resolve', 'POST', { ids: [issueId] })
                .done(function() {
                    $btn.closest('tr').fadeOut(300, function() { $(this).remove(); });
                })
                .fail(function() {
                    alert(flavorQA.i18n.error);
                    $btn.prop('disabled', false);
                });
        },

        // ─── Bulk Actions ───────────────────────────────────

        applyBulkAction: function(e) {
            e.preventDefault();
            const action = $('#flavor-qa-bulk-action').val();
            if (!action) {
                alert('Please select an action.');
                return;
            }

            const ids = [];
            $('input[name="issue_ids[]"]:checked').each(function() {
                ids.push(parseInt($(this).val(), 10));
            });

            if (ids.length === 0) {
                alert('Please select at least one issue.');
                return;
            }

            if (!confirm(flavorQA.i18n.confirm_bulk)) return;

            const endpoint = action === 'fix' ? 'issues/bulk-fix' : 'issues/resolve';

            this.apiRequest(endpoint, 'POST', { ids: ids })
                .done(function(response) {
                    let msg = '';
                    if (action === 'fix') {
                        msg = response.fixed + ' fixed, ' + response.failed + ' failed.';
                    } else {
                        msg = response.resolved + ' issues resolved.';
                    }
                    alert(msg);
                    location.reload();
                })
                .fail(function() {
                    alert(flavorQA.i18n.error);
                });
        },

        // ─── Fix All ────────────────────────────────────────

        fixAll: function(e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const count = parseInt($btn.data('count'), 10);

            if (!confirm('Apply auto-fix to all ' + count + ' issues? Make sure you have a database backup.')) return;

            $btn.prop('disabled', true);
            $('#flavor-qa-bulk-progress').show();

            // Fetch all fixable issues and batch-fix.
            this.apiRequest('issues', 'GET', { per_page: 500, is_resolved: 0 })
                .done(function(issues) {
                    const fixableIds = issues
                        .filter(function(i) { return i.auto_fixable == 1; })
                        .map(function(i) { return i.id; });

                    if (fixableIds.length === 0) {
                        alert('No auto-fixable issues found.');
                        $btn.prop('disabled', false);
                        return;
                    }

                    QA.processBulkFix(fixableIds, 0, fixableIds.length);
                });
        },

        processBulkFix: function(ids, offset, total) {
            const batchSize = 10;
            const batch = ids.slice(offset, offset + batchSize);

            if (batch.length === 0) {
                $('#flavor-qa-bulk-results').show();
                setTimeout(function() { location.reload(); }, 2000);
                return;
            }

            this.apiRequest('issues/bulk-fix', 'POST', { ids: batch })
                .done(function(response) {
                    const processed = Math.min(offset + batchSize, total);
                    const pct = Math.round((processed / total) * 100);
                    $('.flavor-qa-progress-fill').css('width', pct + '%');
                    $('.flavor-qa-progress-text').text(processed + ' / ' + total);
                    QA.processBulkFix(ids, offset + batchSize, total);
                })
                .fail(function() {
                    QA.processBulkFix(ids, offset + batchSize, total);
                });
        },

        // ─── Install Puppeteer ──────────────────────────────

        installPuppeteer: function(e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const $status = $('#flavor-qa-install-status');

            $btn.prop('disabled', true).text('Installing...');
            $status.text('This may take a minute...').css('color', '#646970');

            this.apiRequest('screenshots/install', 'POST')
                .done(function() {
                    $status.text('Puppeteer installed successfully!').css('color', '#00a32a');
                    $btn.text('Installed').addClass('button-disabled');
                    setTimeout(function() { location.reload(); }, 2000);
                })
                .fail(function(xhr) {
                    const msg = xhr.responseJSON ? xhr.responseJSON.message : 'Installation failed.';
                    $status.text(msg).css('color', '#d63638');
                    $btn.prop('disabled', false).text('Retry Install');
                });
        },

        // ─── Fix Category ───────────────────────────────────

        fixCategory: function(e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const ids = JSON.parse($btn.attr('data-ids'));

            if (!confirm('Fix ' + ids.length + ' issues in this category?')) return;

            $btn.prop('disabled', true).text('Fixing...');

            this.apiRequest('issues/bulk-fix', 'POST', { ids: ids })
                .done(function(response) {
                    $btn.text(response.fixed + ' fixed, ' + response.failed + ' failed');
                })
                .fail(function() {
                    alert(flavorQA.i18n.error);
                    $btn.prop('disabled', false).text('Fix');
                });
        },

        // ─── Dismiss All ────────────────────────────────────

        dismissAll: function(e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const count = parseInt($btn.data('count'), 10);

            if (!confirm('Dismiss all ' + count + ' unresolved issues?')) return;

            $btn.prop('disabled', true);

            this.apiRequest('issues', 'GET', { per_page: 500, is_resolved: 0 })
                .done(function(issues) {
                    const ids = issues.map(function(i) { return i.id; });
                    QA.apiRequest('issues/resolve', 'POST', { ids: ids })
                        .done(function() {
                            alert('All issues dismissed.');
                            location.reload();
                        });
                })
                .fail(function() {
                    alert(flavorQA.i18n.error);
                    $btn.prop('disabled', false);
                });
        },

        // ─── Delete Scan ────────────────────────────────────

        deleteScan: function(e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const scanId = $btn.data('scan-id');

            if (!confirm('Delete scan #' + scanId + ' and all its data?')) return;

            $btn.prop('disabled', true);

            this.apiRequest('scan/' + scanId, 'DELETE')
                .done(function() {
                    $btn.closest('tr').fadeOut(300, function() { $(this).remove(); });
                })
                .fail(function() {
                    alert(flavorQA.i18n.error);
                    $btn.prop('disabled', false);
                });
        }
    };

    $(document).ready(function() {
        QA.init();
    });

})(jQuery);
