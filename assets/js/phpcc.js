/**
 * PHP Compatibility Checker v2.0 — Admin JavaScript
 */

(function($) {
    'use strict';

    $(document).ready(function() {

        // ---- Rescan ----
        $('#phpcc-rescan').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            if ($btn.hasClass('loading')) return;

            $btn.addClass('loading').text(PHPCC_Vars.strings.scanning);

            $.post(PHPCC_Vars.ajaxUrl, {
                action: 'phpcc_rescan',
                nonce: PHPCC_Vars.nonce
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(PHPCC_Vars.strings.errorPrefix + ' ' + (response.data?.message || 'Unknown error'));
                }
            }).fail(function() {
                alert(PHPCC_Vars.strings.errorPrefix + ' Server error. Check System Info.');
            }).always(function() {
                $btn.removeClass('loading').text(PHPCC_Vars.strings.rescan);
            });
        });

        // ---- Clear Cache ----
        $('#phpcc-clear-cache').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            $btn.prop('disabled', true).text('Clearing...');

            $.post(PHPCC_Vars.ajaxUrl, {
                action: 'phpcc_clear_cache',
                nonce: PHPCC_Vars.nonce
            }, function(response) {
                if (response.success) location.reload();
            }).always(function() {
                $btn.prop('disabled', false).text('Clear Results');
            });
        });

        // ---- Export CSV ----
        $('#phpcc-export').on('click', function(e) {
            e.preventDefault();

            if (!window.PHPCC_Results || !Array.isArray(window.PHPCC_Results) || window.PHPCC_Results.length === 0) {
                alert(PHPCC_Vars.strings.noData);
                return;
            }

            var headers = [
                'Component Name', 'Type', 'Status', 'Version',
                'PHP 8 Readiness Score', 'Readiness Status',
                'Critical Issues', 'Warnings', 'Info',
                'Max PHP Version', 'Impact Risk', 'Key Features'
            ];
            var csv = [headers.join(',')];

            window.PHPCC_Results.forEach(function(item) {
                var features = [];
                if (item.features) {
                    item.features.forEach(function(f) {
                        features.push(f.label + ' (' + f.count + ')');
                    });
                }
                var risk = item.impact ? item.impact.overall_risk : 'low';
                var row = [
                    '"' + (item.name || '').replace(/"/g, '""') + '"',
                    item.type,
                    item.status,
                    item.version,
                    item.readiness_score || 0,
                    item.readiness_label || 'Unknown',
                    item.issue_counts?.critical || 0,
                    item.issue_counts?.warning || 0,
                    item.issue_counts?.info || 0,
                    item.php_max || 'unknown',
                    risk,
                    '"' + features.join('; ').replace(/"/g, '""') + '"'
                ];
                csv.push(row.join(','));
            });

            var blob = new Blob([csv.join('\n')], { type: 'text/csv' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = 'php8-readiness-' + new Date().toISOString().split('T')[0] + '.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        });

        // ---- Print Report ----
        $('#phpcc-export-report').on('click', function(e) {
            e.preventDefault();
            window.print();
        });

        // ---- Download Markdown ----
        $('#phpcc-export-markdown').on('click', function(e) {
            e.preventDefault();

            if (!window.PHPCC_Results || !Array.isArray(window.PHPCC_Results) || window.PHPCC_Results.length === 0) {
                alert(PHPCC_Vars.strings.noData);
                return;
            }

            // Build a payload and let PHP generate the markdown via AJAX
            $.post(PHPCC_Vars.ajaxUrl, {
                action: 'phpcc_export_markdown',
                nonce: PHPCC_Vars.nonce
            }, function(response) {
                if (response.success && response.data.markdown) {
                    var blob = new Blob([response.data.markdown], { type: 'text/markdown;charset=utf-8' });
                    var url = URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = 'php8-readiness-' + new Date().toISOString().split('T')[0] + '.md';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                } else {
                    alert('Failed to generate markdown: ' + (response.data?.message || 'Unknown error'));
                }
            }).fail(function() {
                alert('Failed to generate markdown report.');
            });
        });

        // ---- Deactivate Incompatible Plugins ----
        $('#phpcc-deactivate-bad').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);

            // Step 1: Preview what will be deactivated
            $btn.prop('disabled', true).text('Checking...');

            $.post(PHPCC_Vars.ajaxUrl, {
                action: 'phpcc_deactivate_incompatible',
                preview: '1',
                nonce: PHPCC_Vars.nonce
            }, function(response) {
                $btn.prop('disabled', false).text('Deactivate Incompatible');

                if (response.success && response.data.preview) {
                    var plugins = response.data.plugins;

                    if (plugins.length === 0) {
                        alert('No incompatible active plugins found. All your plugins appear PHP 8 compatible.');
                        return;
                    }

                    // Build a confirmation message
                    var msg = 'The following ' + plugins.length + ' plugin(s) will be deactivated:\n\n';
                    plugins.forEach(function(p) {
                        msg += '  ' + p.name + ' v' + p.version + ' (' + p.reason + ')\n';
                    });
                    msg += '\nA backup will be saved so you can undo. Proceed?';

                    if (confirm(msg)) {
                        // Step 2: Execute
                        $btn.prop('disabled', true).text('Deactivating...');

                        $.post(PHPCC_Vars.ajaxUrl, {
                            action: 'phpcc_deactivate_incompatible',
                            nonce: PHPCC_Vars.nonce
                        }, function(execResponse) {
                            if (execResponse.success) {
                                var names = execResponse.data.plugins.map(function(p) { return p.name; }).join('\n  ');
                                var resultMsg = 'Deactivated ' + execResponse.data.count + ' plugin(s):\n\n  ' + names + '\n\nA backup was saved. Use "Restore Previous State" to undo.';
                                alert(resultMsg);
                                location.reload();
                            } else {
                                alert('Deactivation failed: ' + (execResponse.data?.message || 'Unknown'));
                            }
                        }).fail(function() {
                            alert('Deactivation request failed.');
                        }).always(function() {
                            $btn.prop('disabled', false).text('Deactivate Incompatible');
                        });
                    }
                } else {
                    alert('Preview failed: ' + (response.data?.message || 'Run a scan first'));
                    $btn.prop('disabled', false).text('Deactivate Incompatible');
                }
            }).fail(function() {
                $btn.prop('disabled', false).text('Deactivate Incompatible');
                alert('Failed to check for incompatible plugins.');
            });
        });

        // ---- Restore Previous Plugin State ----
        $('#phpcc-restore-plugins').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);

            if (!confirm('Restore all plugins to their state before the last deactivation?')) return;

            $btn.prop('disabled', true).text('Restoring...');

            $.post(PHPCC_Vars.ajaxUrl, {
                action: 'phpcc_restore_plugins',
                nonce: PHPCC_Vars.nonce
            }, function(response) {
                if (response.success) {
                    var msg = 'Restored ' + response.data.restored + ' plugin(s).';
                    if (response.data.failed > 0) {
                        var errors = response.data.errors.map(function(e) { return e.file + ': ' + e.error; }).join('\n');
                        msg += '\n\n' + response.data.failed + ' plugin(s) could not be restored:\n' + errors;
                    }
                    alert(msg);
                    location.reload();
                } else {
                    alert('Restore failed: ' + (response.data?.message || 'No backup found'));
                }
            }).fail(function() {
                alert('Restore request failed.');
            }).always(function() {
                $btn.prop('disabled', false).text('Restore Previous State');
            });
        });

        // ---- Filter Cards ----
        $('#phpcc-filter').on('change', function() {
            var filter = $(this).val();

            $('.phpcc-card').each(function() {
                var $card = $(this);
                var type = $card.find('.phpcc-card-type').text().toLowerCase();
                var status = $card.find('.phpcc-card-status').text().trim();
                var hasCritical = $card.find('.phpcc-card-issue-critical').length > 0;
                var hasWarning = $card.find('.phpcc-card-issue-warning').length > 0;
                var isReady = !hasCritical && !hasWarning;

                var show = false;
                if (filter === 'all') show = true;
                else if (filter === type) show = true;
                else if (filter === 'active' && status === 'Active') show = true;
                else if (filter === 'critical' && hasCritical) show = true;
                else if (filter === 'warning' && hasWarning) show = true;
                else if (filter === 'php8-ready' && isReady) show = true;

                $card.toggle(show);
            });
        });

        // ---- Component Detail Modal ----
        $(document).on('click', '.phpcc-card-details-btn', function(e) {
            e.preventDefault();
            var slug = $(this).data('slug');
            openDetailModal(slug);
        });

        function openDetailModal(slug) {
            // Use getElementById to avoid jQuery selector issues with dots/slashes in slugs
            var templateId = 'phpcc-detail-' + slug;
            var templateEl = document.getElementById(templateId);
            if (templateEl) {
                try {
                    var report = JSON.parse(templateEl.textContent);
                    renderModal(report);
                    return;
                } catch (e) {
                    console.warn('Template parse error, falling back to AJAX:', e);
                }
            }

            // Load via AJAX
            $.post(PHPCC_Vars.ajaxUrl, {
                action: 'phpcc_get_detail',
                slug: slug,
                nonce: PHPCC_Vars.nonce
            }, function(response) {
                if (response.success) {
                    renderModal(response.data);
                } else {
                    alert('Could not load details: ' + (response.data?.message || 'Unknown'));
                }
            }).fail(function() {
                alert('Failed to load details.');
            });
        }

        function renderModal(report) {
            var $body = $('#phpcc-modal-body');
            $body.empty();

            // Header
            $body.append('<h2>' + escapeHtml(report.header.name) + '</h2>');
            $body.append('<p><strong>Version:</strong> ' + escapeHtml(report.header.version) +
                ' | <strong>Type:</strong> ' + escapeHtml(report.header.type) +
                ' | <strong>Status:</strong> ' + escapeHtml(report.header.status) + '</p>');

            // Readiness
            var readiness = report.readiness;
            var scoreColor = readiness.score >= 95 ? '#00a32a' : (readiness.score >= 70 ? '#dba617' : '#d63638');
            $body.append('<div class="phpcc-modal-section">' +
                '<h3>PHP 8 Readiness</h3>' +
                '<div style="font-size:32px;font-weight:700;color:' + scoreColor + '">' + readiness.score + '%</div>' +
                '<p><strong>' + escapeHtml(readiness.label) + '</strong></p>' +
                '<p>' + escapeHtml(readiness.verdict) + '</p>' +
                '<p>Maximum tested PHP version: <strong>' + escapeHtml(readiness.php_max) + '</strong></p>' +
                '</div>');

            // PHP 8 Issues
            if (report.php8_issues && Object.keys(report.php8_issues).length > 0) {
                $body.append('<div class="phpcc-modal-section"><h3>Compatibility Issues</h3>');

                $.each(report.php8_issues, function(severity, section) {
                    var severityClass = 'phpcc-issue-' + severity;
                    $body.append('<div class="phpcc-issues-group phpcc-issues-' + severity + '">');
                    $body.append('<h4>' + escapeHtml(section.title) +
                        (section.total > section.items.length ? ' (' + section.items.length + ' of ' + section.total + ' shown)' : ' (' + section.total + ')') +
                        '</h4>');
                    $body.append('<p>' + escapeHtml(section.description) + '</p>');

                    if (section.items.length > 0) {
                        $body.append('<ul class="phpcc-issue-list">');
                        section.items.forEach(function(issue) {
                            $body.append('<li class="phpcc-issue-item ' + severityClass + '">' +
                                '<code>' + escapeHtml(issue.file) + ':' + issue.line + '</code><br>' +
                                '<strong>' + escapeHtml(issue.message) + '</strong><br>' +
                                '<small>' + escapeHtml(issue.source) + '</small>' +
                                '</li>');
                        });
                        $body.append('</ul>');
                    }
                    $body.append('</div>');
                });
                $body.append('</div>');
            }

            // Features
            if (report.features && report.features.length > 0) {
                $body.append('<div class="phpcc-modal-section"><h3>What This Component Provides</h3>');
                $body.append('<ul>');
                report.features.forEach(function(f) {
                    $body.append('<li><strong>' + escapeHtml(f.label) + ':</strong> ' + escapeHtml(f.description) + '</li>');
                });
                $body.append('</ul></div>');
            }

            // Impact
            if (report.impact && report.impact.has_impact) {
                $body.append('<div class="phpcc-modal-section phpcc-impact-section phpcc-impact-' + report.impact.risk_level + '">');
                $body.append('<h3>Impact if Removed</h3>');
                $body.append('<p><strong>Risk Level:</strong> <span class="phpcc-impact-badge">' + escapeHtml(report.impact.risk_level.toUpperCase()) + '</span></p>');
                $body.append('<p>' + nl2br(escapeHtml(report.impact.recommendation)) + '</p>');
                $body.append('<div class="phpcc-impact-details">' + nl2br(escapeHtml(report.impact.summary_text)) + '</div>');
                $body.append('</div>');
            }

            // Actions
            if (report.actions && report.actions.length > 0) {
                $body.append('<div class="phpcc-modal-section"><h3>Recommended Actions</h3><ul>');
                report.actions.forEach(function(a) {
                    var priorityClass = 'phpcc-action-' + a.priority;
                    $body.append('<li class="phpcc-action-item ' + priorityClass + '">' + escapeHtml(a.action) + '</li>');
                });
                $body.append('</ul></div>');
            }

            $('#phpcc-detail-modal').show();
        }

        // Close modal
        $('.phpcc-modal-close, #phpcc-detail-modal').on('click', function(e) {
            if (e.target === this || $(e.target).hasClass('phpcc-modal-close')) {
                $('#phpcc-detail-modal').hide();
            }
        });

        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') {
                $('#phpcc-detail-modal').hide();
            }
        });

        // Helper functions
        function escapeHtml(text) {
            if (!text) return '';
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function nl2br(text) {
            return text.replace(/\n/g, '<br>');
        }
    });
})(jQuery);
