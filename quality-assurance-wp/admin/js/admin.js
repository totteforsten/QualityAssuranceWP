/**
 * Quality Assurance WP - Admin JavaScript
 */
(function($) {
    'use strict';

    const QA = {
        apiBase: flavorQA.apiBase,
        nonce: flavorQA.nonce,

        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Start scan.
            $('#flavor-qa-start-scan').on('click', this.startScan.bind(this));

            // Fix single issue.
            $(document).on('click', '.flavor-qa-fix-issue', this.fixIssue.bind(this));

            // Resolve/dismiss single issue.
            $(document).on('click', '.flavor-qa-resolve-issue', this.resolveIssue.bind(this));

            // Bulk actions.
            $(document).on('click', '.flavor-qa-apply-bulk', this.applyBulkAction.bind(this));

            // Fix all button.
            $('#flavor-qa-fix-all').on('click', this.fixAll.bind(this));

            // Fix by category.
            $(document).on('click', '.flavor-qa-fix-category', this.fixCategory.bind(this));

            // Dismiss all.
            $('#flavor-qa-dismiss-all').on('click', this.dismissAll.bind(this));

            // Select all checkbox.
            $(document).on('change', '#flavor-qa-select-all', function() {
                $('input[name="issue_ids[]"], input[name="link_ids[]"]').prop('checked', this.checked);
            });

            // Delete scan.
            $(document).on('click', '.flavor-qa-delete-scan', this.deleteScan.bind(this));
        },

        // ─── API Helpers ────────────────────────────────────

        apiRequest: function(endpoint, method, data) {
            return $.ajax({
                url: this.apiBase + endpoint,
                method: method || 'GET',
                data: data ? JSON.stringify(data) : undefined,
                contentType: 'application/json',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', QA.nonce);
                }
            });
        },

        // ─── Scan ───────────────────────────────────────────

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

            this.apiRequest('scan/start', 'POST', { types: types })
                .done(function(response) {
                    QA.pollScanProgress(response.scan_id, response.total_items);
                })
                .fail(function(xhr) {
                    alert(flavorQA.i18n.error);
                    $btn.prop('disabled', false).text('Start Full Scan');
                    $('#flavor-qa-scan-progress').hide();
                });
        },

        pollScanProgress: function(scanId, totalItems) {
            const poll = function() {
                QA.apiRequest('scan/' + scanId)
                    .done(function(scan) {
                        const progress = scan.progress || 0;
                        $('.flavor-qa-progress-fill').css('width', progress + '%');
                        $('.flavor-qa-progress-text').text(Math.round(progress) + '%');
                        $('.flavor-qa-progress-status').text(
                            scan.completed_items + ' / ' + scan.total_items + ' items processed'
                        );

                        if (scan.status === 'completed') {
                            $('.flavor-qa-progress-status').text(flavorQA.i18n.completed + ' - ' + scan.issues_found + ' issues found');
                            $('#flavor-qa-start-scan').prop('disabled', false).text('Start Full Scan');
                            // Reload after a brief pause so user sees completion.
                            setTimeout(function() { location.reload(); }, 2000);
                        } else if (scan.status === 'running') {
                            setTimeout(poll, 3000);
                        }
                    })
                    .fail(function() {
                        setTimeout(poll, 5000);
                    });
            };

            setTimeout(poll, 2000);
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

            // Collect all auto-fixable issue IDs from the page.
            const ids = [];
            $('input[name="issue_ids[]"]').each(function() {
                if ($(this).data('fixable') == 1) {
                    ids.push(parseInt($(this).val(), 10));
                }
            });

            // If no checkboxes on page, try the API with the scan data.
            if (ids.length === 0) {
                // Fetch all fixable issues via API and fix them.
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
                return;
            }

            this.processBulkFix(ids, 0, ids.length);
        },

        processBulkFix: function(ids, offset, total) {
            // Process in batches of 10.
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

                    if (response.fixed) {
                        const $success = $('.flavor-qa-bulk-success');
                        const current = parseInt($success.data('count') || 0, 10);
                        $success.data('count', current + response.fixed)
                            .text((current + response.fixed) + ' issues fixed');
                    }

                    QA.processBulkFix(ids, offset + batchSize, total);
                })
                .fail(function() {
                    // Continue with next batch even on failure.
                    QA.processBulkFix(ids, offset + batchSize, total);
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
                    if (response.failed === 0) {
                        $btn.closest('tr').addClass('flavor-qa-row-fixed');
                    }
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

            // Get all issue IDs.
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
