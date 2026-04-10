/* PHP Compatibility Checker - Admin Scripts */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        // Rescan button
        $('#phpcc-rescan').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            
            if ($btn.hasClass('loading')) return;
            
            $btn.addClass('loading').text('Scanning...');
            
            $.post(PHPCC_Vars.ajaxUrl, {
                action: 'phpcc_rescan',
                nonce: PHPCC_Vars.nonce
            }, function(response) {
                if (response.success) {
                    alert(response.data.message || 'Scan completed successfully!');
                    location.reload();
                } else {
                    alert('Scan failed: ' + (response.data?.message || 'Unknown error'));
                }
            }).fail(function() {
                alert('Scan failed. Please check System Info for diagnostics.');
            }).always(function() {
                $btn.removeClass('loading').text('Rescan');
            });
        });
        
        // Clear cache button
        $('#phpcc-clear-cache').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            
            $btn.prop('disabled', true).text('Clearing...');
            
            $.post(PHPCC_Vars.ajaxUrl, {
                action: 'phpcc_clear_cache',
                nonce: PHPCC_Vars.nonce
            }, function(response) {
                if (response.success) {
                    alert('Cache cleared!');
                    location.reload();
                } else {
                    alert('Failed to clear cache.');
                }
            }).always(function() {
                $btn.prop('disabled', false).text('Clear Cache');
            });
        });
        
        // Export to CSV
        $('#phpcc-export').on('click', function(e) {
            e.preventDefault();
            
            if (!window.PHPCC_Export || !Array.isArray(window.PHPCC_Export)) {
                alert('No data to export.');
                return;
            }
            
            var data = window.PHPCC_Export;
            var headers = ['Name', 'Slug', 'Type', 'Status', 'PHP Min', 'PHP Max', 'Errors', 'Warnings'];
            var csv = [headers.join(',')];
            
            data.forEach(function(item) {
                var row = [
                    '"' + (item.name || '').replace(/"/g, '""') + '"',
                    item.slug,
                    item.type,
                    item.status,
                    item.php_min,
                    item.php_max,
                    item.error_count || 0,
                    item.warning_count || 0
                ];
                csv.push(row.join(','));
            });
            
            var blob = new Blob([csv.join('\n')], { type: 'text/csv' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = 'php-compatibility-' + new Date().toISOString().split('T')[0] + '.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        });
        
        // Filter table
        $('#phpcc-filter').on('change', function() {
            var filter = $(this).val();
            
            $('#phpcc-results tbody tr').each(function() {
                var $row = $(this);
                var type = $row.data('type');
                var status = $row.data('status');
                var hasIssues = $row.data('issues') === 'yes';
                
                var show = false;
                
                if (filter === 'all') {
                    show = true;
                } else if (filter === type) {
                    show = true;
                } else if (filter === 'active' && status === 'Active') {
                    show = true;
                } else if (filter === 'issues' && hasIssues) {
                    show = true;
                }
                
                if (show) {
                    $row.show();
                } else {
                    $row.hide();
                }
            });
        });
    });
})(jQuery);
