<?php
/**
 * Admin UI Class
 * Handles dashboard, settings, and system info rendering
 */

declare(strict_types=1);

class PHPCC_Admin {
    
    private $scanner;
    
    public function __construct($scanner) {
        $this->scanner = $scanner;
    }
    
    /**
     * Render main dashboard
     */
    public function render_dashboard(): void {
        $this->render_dashboard_internal(false);
    }
    
    /**
     * Render network dashboard
     */
    public function render_network_dashboard(): void {
        $this->render_dashboard_internal(true);
    }
    
    private function render_dashboard_internal(bool $is_network): void {
        $stats = $this->get_environment_stats();
        $results = $this->scanner->get_cached_results();
        
        // Check PHPCS status
        $phpcs_available = $this->scanner->is_phpcs_available();
        $phpcs_version = $phpcs_available ? $this->scanner->get_phpcs_version() : null;
        $standards_status = $this->scanner->get_standards_status();
        
        echo '<div class="wrap phpcc-wrap">';
        echo '<h1>' . esc_html__('PHP Compatibility Checker', 'phpcc') . '</h1>';
        
        // Status banner
        if ($phpcs_available && $phpcs_version && $standards_status['PHPCompatibilityWP']) {
            echo '<div class="notice notice-success inline">';
            echo '<p><span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span> ';
            echo sprintf(esc_html__('PHPCS %s ready with PHPCompatibilityWP', 'phpcc'), esc_html($phpcs_version));
            echo '</p></div>';
        } else {
            echo '<div class="notice notice-error inline">';
            echo '<p><span class="dashicons dashicons-warning" style="color:#d63638;"></span> ';
            echo '<strong>' . esc_html__('PHPCS Not Available', 'phpcc') . '</strong><br>';
            if (!$phpcs_available) {
                echo esc_html__('The bundled PHPCS is missing. Please reinstall the plugin.', 'phpcc');
            } elseif (!$standards_status['PHPCompatibilityWP']) {
                echo esc_html__('PHPCompatibilityWP standard is missing. Please reinstall the plugin.', 'phpcc');
            }
            echo '</p></div>';
        }
        
        // Stats cards
        echo '<div class="phpcc-stats">';
        $this->stat_card(__('WordPress', 'phpcc'), $stats['wp_version'], 'dashicons-wordpress');
        $this->stat_card(__('PHP', 'phpcc'), $stats['php_version'], 'dashicons-editor-code');
        $this->stat_card(__('Plugins', 'phpcc'), $stats['plugin_count'], 'dashicons-plugins-checked');
        $this->stat_card(__('Themes', 'phpcc'), $stats['theme_count'], 'dashicons-appearance');
        echo '</div>';
        
        // Controls
        echo '<div class="phpcc-controls">';
        
        if ($phpcs_available) {
            echo '<button id="phpcc-rescan" class="button button-primary">' . esc_html__('Rescan', 'phpcc') . '</button>';
        } else {
            echo '<button class="button button-disabled" disabled title="PHPCS not available">' . esc_html__('Rescan', 'phpcc') . '</button>';
        }
        
        echo '<button id="phpcc-export" class="button">' . esc_html__('Export CSV', 'phpcc') . '</button>';
        echo '<button id="phpcc-clear-cache" class="button">' . esc_html__('Clear Cache', 'phpcc') . '</button>';
        
        // Filter
        echo '<select id="phpcc-filter" class="phpcc-filter">';
        echo '<option value="all">' . esc_html__('All Components', 'phpcc') . '</option>';
        echo '<option value="plugin">' . esc_html__('Plugins Only', 'phpcc') . '</option>';
        echo '<option value="theme">' . esc_html__('Themes Only', 'phpcc') . '</option>';
        echo '<option value="active">' . esc_html__('Active Only', 'phpcc') . '</option>';
        echo '<option value="issues">' . esc_html__('With Issues', 'phpcc') . '</option>';
        echo '</select>';
        echo '</div>';
        
        // Results table
        echo '<h2>' . esc_html__('Compatibility Results', 'phpcc') . '</h2>';
        
        if (!$phpcs_available) {
            echo '<div class="notice notice-warning">';
            echo '<p>' . esc_html__('Scanning is unavailable. Please check the System Info page for diagnostics.', 'phpcc') . '</p>';
            echo '</div>';
        } elseif (false === $results) {
            echo '<p>' . esc_html__('No scan results available. Click Rescan to check compatibility.', 'phpcc') . '</p>';
        } elseif (empty($results)) {
            echo '<p>' . esc_html__('No components found to scan.', 'phpcc') . '</p>';
        } else {
            $this->render_results_table($results);
        }
        
        echo '</div>';
        
        // Export data for JS
        echo '<script>window.PHPCC_Export = ' . wp_json_encode($results ?: []) . ';</script>';
    }
    
    private function stat_card(string $label, string $value, string $icon): void {
        echo '<div class="phpcc-stat-card">';
        echo '<span class="dashicons ' . esc_attr($icon) . '"></span>';
        echo '<div class="phpcc-stat-value">' . esc_html($value) . '</div>';
        echo '<div class="phpcc-stat-label">' . esc_html($label) . '</div>';
        echo '</div>';
    }
    
    private function render_results_table(array $results): void {
        echo '<table class="wp-list-table widefat fixed striped phpcc-table" id="phpcc-results">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>' . esc_html__('Component', 'phpcc') . '</th>';
        echo '<th>' . esc_html__('Type', 'phpcc') . '</th>';
        echo '<th>' . esc_html__('Status', 'phpcc') . '</th>';
        echo '<th>' . esc_html__('PHP Min', 'phpcc') . '</th>';
        echo '<th>' . esc_html__('PHP Max', 'phpcc') . '</th>';
        echo '<th>' . esc_html__('Issues', 'phpcc') . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($results as $item) {
            $error_count = isset($item['error_count']) ? (int) $item['error_count'] : 0;
            $warning_count = isset($item['warning_count']) ? (int) $item['warning_count'] : 0;
            
            $row_class = $error_count > 0 ? 'phpcc-has-errors' : ($warning_count > 0 ? 'phpcc-has-warnings' : 'phpcc-clean');
            $has_issues = $error_count > 0 || $warning_count > 0;
            
            echo '<tr class="' . esc_attr($row_class) . '" data-type="' . esc_attr($item['type']) . '" data-status="' . esc_attr($item['status']) . '" data-issues="' . ($has_issues ? 'yes' : 'no') . '">';
            echo '<td><strong>' . esc_html($item['name']) . '</strong><br><code>' . esc_html($item['slug']) . '</code></td>';
            echo '<td>' . esc_html(ucfirst($item['type'])) . '</td>';
            echo '<td>' . esc_html($item['status']) . '</td>';
            echo '<td>' . esc_html($item['php_min']) . '</td>';
            echo '<td>' . esc_html($item['php_max']) . '</td>';
            echo '<td>';
            if ($error_count > 0) {
                echo '<span class="phpcc-badge phpcc-error">' . sprintf(esc_html__('%d errors', 'phpcc'), $error_count) . '</span>';
            }
            if ($warning_count > 0) {
                echo '<span class="phpcc-badge phpcc-warning">' . sprintf(esc_html__('%d warnings', 'phpcc'), $warning_count) . '</span>';
            }
            if ($error_count === 0 && $warning_count === 0) {
                echo '<span class="phpcc-badge phpcc-success">' . esc_html__('Clean', 'phpcc') . '</span>';
            }
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
    }
    
    public function render_settings(): void {
        // No settings needed - everything is bundled
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Settings', 'phpcc') . '</h1>';
        
        echo '<p>' . esc_html__('PHP Compatibility Checker is fully self-contained. No external configuration required.', 'phpcc') . '</p>';
        
        // Show bundled status
        echo '<h2>' . esc_html__('Bundled Components', 'phpcc') . '</h2>';
        echo '<table class="widefat">';
        echo '<tr><th>' . esc_html__('Component', 'phpcc') . '</th><th>' . esc_html__('Status', 'phpcc') . '</th></tr>';
        
        $phpcs_available = $this->scanner->is_phpcs_available();
        echo '<tr>';
        echo '<td>PHP_CodeSniffer (PHPCS)</td>';
        echo '<td>' . ($phpcs_available ? '<span style="color:#00a32a;">✓ Available</span>' : '<span style="color:#d63638;">✗ Missing</span>') . '</td>';
        echo '</tr>';
        
        if ($phpcs_available) {
            $version = $this->scanner->get_phpcs_version();
            echo '<tr><td colspan="2" style="color:#646970;">Version: ' . esc_html($version) . '</td></tr>';
        }
        
        $standards = $this->scanner->get_standards_status();
        echo '<tr>';
        echo '<td>PHPCompatibility Standard</td>';
        echo '<td>' . ($standards['PHPCompatibility'] ? '<span style="color:#00a32a;">✓ Available</span>' : '<span style="color:#d63638;">✗ Missing</span>') . '</td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<td>PHPCompatibilityWP Standard</td>';
        echo '<td>' . ($standards['PHPCompatibilityWP'] ? '<span style="color:#00a32a;">✓ Available</span>' : '<span style="color:#d63638;">✗ Missing</span>') . '</td>';
        echo '</tr>';
        
        echo '</table>';
        
        echo '</div>';
    }
    
    public function render_network_settings(): void {
        $this->render_settings();
    }
    
    public function render_sysinfo(): void {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('System Information', 'phpcc') . '</h1>';
        
        echo '<table class="widefat">';
        echo '<tr><th>' . esc_html__('PHP Version', 'phpcc') . '</th><td>' . esc_html(phpversion()) . '</td></tr>';
        echo '<tr><th>' . esc_html__('WordPress Version', 'phpcc') . '</th><td>' . esc_html(get_bloginfo('version')) . '</td></tr>';
        
        // PHPCS status
        $phpcs_available = $this->scanner->is_phpcs_available();
        echo '<tr><th>' . esc_html__('PHPCS Available', 'phpcc') . '</th><td>' . ($phpcs_available ? esc_html__('Yes (bundled)', 'phpcc') : esc_html__('No', 'phpcc')) . '</td></tr>';
        
        if ($phpcs_available) {
            $version = $this->scanner->get_phpcs_version();
            echo '<tr><th>' . esc_html__('PHPCS Version', 'phpcc') . '</th><td>' . esc_html($version) . '</td></tr>';
            
            $standards = $this->scanner->get_standards_status();
            echo '<tr><th>' . esc_html__('Standards', 'phpcc') . '</th><td>';
            foreach ($standards as $name => $available) {
                echo esc_html($name) . ': ' . ($available ? '✓' : '✗') . '<br>';
            }
            echo '</td></tr>';
        }
        
        echo '<tr><th>' . esc_html__('Server OS', 'phpcc') . '</th><td>' . esc_html(PHP_OS) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Server API', 'phpcc') . '</th><td>' . esc_html(php_sapi_name()) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Plugin Directory', 'phpcc') . '</th><td><code>' . esc_html(PHPCC_PLUGIN_DIR) . '</code></td></tr>';
        echo '</table>';
        
        // Diagnostics section
        echo '<h2>' . esc_html__('Diagnostics', 'phpcc') . '</h2>';
        
        if ($phpcs_available) {
            echo '<p>' . esc_html__('PHPCS is available. Test scan a single file:', 'phpcc') . '</p>';
            echo '<button id="phpcc-test-scan" class="button">' . esc_html__('Run Test Scan', 'phpcc') . '</button>';
            echo '<div id="phpcc-test-results" style="margin-top:10px;"></div>';
            
            wp_enqueue_script('phpcc-diagnostics', PHPCC_PLUGIN_URL . 'assets/js/diagnostics.js', ['jquery'], PHPCC_VERSION, true);
            wp_localize_script('phpcc-diagnostics', 'PHPCCDiag', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('phpcc_test_nonce'),
            ]);
        } else {
            echo '<div class="notice notice-error">';
            echo '<p>' . esc_html__('PHPCS is not available. The bundled phpcs.phar file may be missing or corrupted.', 'phpcc') . '</p>';
            echo '<p>' . esc_html__('Expected location:', 'phpcc') . ' <code>' . esc_html(PHPCC_PLUGIN_DIR . 'bin/phpcs.phar') . '</code></p>';
            echo '</div>';
        }
        
        echo '</div>';
    }
    
    private function get_environment_stats(): array {
        return [
            'wp_version'    => get_bloginfo('version'),
            'php_version'   => phpversion(),
            'plugin_count'  => count(get_plugins()),
            'theme_count'   => count(wp_get_themes()),
        ];
    }
}
