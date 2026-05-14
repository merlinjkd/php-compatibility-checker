<?php
/**
 * Admin UI — Comprehensive PHP 8 Readiness Dashboard
 */

declare(strict_types=1);

class PHPCC_Admin {

    private $scanner;
    private $report;

    public function __construct($scanner) {
        $this->scanner = $scanner;
        require_once PHPCC_PLUGIN_DIR . 'includes/class-report-generator.php';
        $this->report = new PHPCC_Report_Generator();
    }

    public function render_dashboard(): void {
        $this->render_dashboard_internal(false);
    }

    public function render_network_dashboard(): void {
        $this->render_dashboard_internal(true);
    }

    private function render_dashboard_internal(bool $is_network): void {
        $results = $this->scanner->get_cached_results();
        $summary = ($results !== false && !empty($results)) ? $this->report->build_executive_summary($results) : null;

        echo '<div class="wrap phpcc-wrap">';
        echo '<h1>' . esc_html__('PHP 8 Readiness Dashboard', 'phpcc') . '</h1>';

        // System status banner
        $this->render_status_banner();

        // Executive Summary Cards
        if ($summary) {
            $this->render_executive_summary($summary);
        }

        // Controls
        $this->render_controls();

        // Component cards
        echo '<h2>' . esc_html__('Component Reports', 'phpcc') . '</h2>';

        if ($results === false) {
            echo '<div class="phpcc-no-results">' . esc_html__('No scan results. Click "Scan All" to analyze your site for PHP 8 readiness.', 'phpcc') . '</div>';
        } elseif (empty($results)) {
            echo '<p>' . esc_html__('No components found to scan.', 'phpcc') . '</p>';
        } else {
            echo '<div class="phpcc-cards-grid">';
            foreach ($results as $result) {
                echo $this->report->render_component_card($result);
            }
            echo '</div>';
        }

        echo '</div>'; // .wrap

        // Detail modal container
        echo '<div id="phpcc-detail-modal" class="phpcc-modal"><div class="phpcc-modal-content">';
        echo '<span class="phpcc-modal-close">&times;</span>';
        echo '<div id="phpcc-modal-body"></div>';
        echo '</div></div>';

        // Pass results to JS
        echo '<script>window.PHPCC_Results = ' . wp_json_encode($results ?: []) . ';</script>';

        // Inline detail templates for each component
        if ($results && is_array($results)) {
            foreach ($results as $result) {
                $component_report = $this->report->build_component_report($result);
                echo '<script type="text/template" id="phpcc-detail-' . esc_attr($result['slug']) . '">' . "\n";
                echo wp_json_encode($component_report, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
                echo "\n" . '</script>';
            }
        }
    }

    // ------------------------------------------------------------------
    // Render methods
    // ------------------------------------------------------------------

    private function render_status_banner(): void {
        $phpcs_available = $this->scanner->is_phpcs_available();
        $phpcs_version = $phpcs_available ? $this->scanner->get_phpcs_version() : null;
        $standards = $this->scanner->get_standards_status();

        if ($phpcs_available && $phpcs_version && $standards['PHPCompatibilityWP']) {
            echo '<div class="notice notice-success inline">';
            echo '<p><span class="dashicons dashicons-yes-alt"></span> ';
            echo sprintf(esc_html__('PHPCS %s ready with PHPCompatibilityWP — targeting PHP 7.4 to 8.5 compatibility', 'phpcc'), esc_html($phpcs_version));
            echo '</p></div>';
        } else {
            echo '<div class="notice notice-error inline">';
            echo '<p><span class="dashicons dashicons-warning"></span> ';
            echo '<strong>' . esc_html__('PHPCS Not Available', 'phpcc') . '</strong><br>';
            if (!$phpcs_available) {
                echo esc_html__('The bundled PHPCS is missing. Please reinstall the plugin.', 'phpcc');
            } elseif (!$standards['PHPCompatibilityWP']) {
                echo esc_html__('PHPCompatibilityWP standard is missing. Please reinstall the plugin.', 'phpcc');
            }
            echo '</p></div>';
        }

        // PHP version alert
        $php_version = phpversion();
        if (version_compare($php_version, '8.0', '<')) {
            echo '<div class="notice notice-warning inline">';
            echo '<p><span class="dashicons dashicons-info"></span> ';
            echo sprintf(esc_html__('Your server is running PHP %s. This report identifies components that are ready for PHP 8.0+.', 'phpcc'), esc_html($php_version));
            echo '</p></div>';
        }
    }

    private function render_executive_summary(array $summary): void {
        echo '<div class="phpcc-executive">';
        echo '<div class="phpcc-exec-header">';
        echo '<h2>' . esc_html__('Executive Summary', 'phpcc') . '</h2>';
        $status = $summary['overall_status'];
        $badge_color = [
            'critical'        => '#d63638',
            'needs_attention' => '#d63638',
            'caution'         => '#dba617',
            'ready'           => '#00a32a',
        ][$status] ?? '#646970';
        echo '<span class="phpcc-exec-status" style="background:' . esc_attr($badge_color) . '">' . esc_html($summary['status_label']) . '</span>';
        echo '</div>';

        echo '<div class="phpcc-exec-grid">';
        $this->exec_card(__('Site Readiness Score', 'phpcc'), $summary['overall_score'] . '%', null);
        $this->exec_card(__('Components Scanned', 'phpcc'), number_format_i18n($summary['total_components']), null);
        $this->exec_card(__('PHP 8 Ready', 'phpcc'), number_format_i18n($summary['ready_count']), '#00a32a');
        $this->exec_card(__('Critical Issues', 'phpcc'), number_format_i18n($summary['critical_count']), '#d63638');
        $this->exec_card(__('Need Review', 'phpcc'), number_format_i18n($summary['warning_count']), '#dba617');
        $this->exec_card(__('Plugins with Content Impact', 'phpcc'), number_format_i18n($summary['plugins_with_impact'] ?? 0), '#2271b1');
        echo '</div>';

        // Risky plugins alert
        if (!empty($summary['risky_plugins'])) {
            echo '<div class="phpcc-risky-plugins">';
            echo '<h3>' . esc_html__('Requires Immediate Action', 'phpcc') . '</h3>';
            echo '<ul>';
            foreach (array_slice($summary['risky_plugins'], 0, 5) as $plugin) {
                echo '<li>';
                echo '<strong>' . esc_html($plugin['name']) . '</strong> — ' . esc_html($plugin['reason']);
                if ($plugin['impact'] !== 'low' && $plugin['impact'] !== 'unknown') {
                    echo ' / <em>Site impact: ' . esc_html(ucfirst($plugin['impact'])) . '</em>';
                }
                echo '</li>';
            }
            if (count($summary['risky_plugins']) > 5) {
                echo '<li><em>' . sprintf(esc_html__('...and %d more', 'phpcc'), count($summary['risky_plugins']) - 5) . '</em></li>';
            }
            echo '</ul></div>';
        }

        echo '</div>';
    }

    private function render_controls(): void {
        $phpcs_available = $this->scanner->is_phpcs_available();

        echo '<div class="phpcc-controls">';

        if ($phpcs_available) {
            echo '<button id="phpcc-rescan" class="button button-primary button-hero">' . esc_html__('Scan All Components', 'phpcc') . '</button>';
        } else {
            echo '<button class="button button-hero button-disabled" disabled>' . esc_html__('Scan All Components', 'phpcc') . '</button>';
        }

        echo '<button id="phpcc-export" class="button">' . esc_html__('Export CSV', 'phpcc') . '</button>';
        echo '<button id="phpcc-export-report" class="button">' . esc_html__('Print Report', 'phpcc') . '</button>';
        echo '<button id="phpcc-export-markdown" class="button">' . esc_html__('Download Markdown', 'phpcc') . '</button>';
        echo '<button id="phpcc-clear-cache" class="button">' . esc_html__('Clear Results', 'phpcc') . '</button>';

        // Filters
        echo '<select id="phpcc-filter" class="phpcc-filter">';
        echo '<option value="all">' . esc_html__('All Components', 'phpcc') . '</option>';
        echo '<option value="plugin">' . esc_html__('Plugins Only', 'phpcc') . '</option>';
        echo '<option value="theme">' . esc_html__('Themes Only', 'phpcc') . '</option>';
        echo '<option value="active">' . esc_html__('Active Only', 'phpcc') . '</option>';
        echo '<option value="critical">' . esc_html__('Critical Issues', 'phpcc') . '</option>';
        echo '<option value="warning">' . esc_html__('Has Warnings', 'phpcc') . '</option>';
        echo '<option value="php8-ready">' . esc_html__('PHP 8 Ready', 'phpcc') . '</option>';
        echo '</select>';

        echo '</div>';
    }

    private function exec_card(string $label, string $value, ?string $color): void {
        $style = $color ? 'color:' . esc_attr($color) : '';
        echo '<div class="phpcc-exec-card">';
        echo '<div class="phpcc-exec-value" style="' . esc_attr($style) . '">' . esc_html($value) . '</div>';
        echo '<div class="phpcc-exec-label">' . esc_html($label) . '</div>';
        echo '</div>';
    }

    // ------------------------------------------------------------------
    // Sub pages
    // ------------------------------------------------------------------

    public function render_settings(): void {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Settings', 'phpcc') . '</h1>';

        echo '<p>' . esc_html__('PHP Compatibility Checker is fully bundled. No external configuration required.', 'phpcc') . '</p>';

        echo '<h2>' . esc_html__('Bundled Components', 'phpcc') . '</h2>';
        echo '<table class="widefat">';
        echo '<tr><th>' . esc_html__('Component', 'phpcc') . '</th><th>' . esc_html__('Status', 'phpcc') . '</th></tr>';

        $phpcs_available = $this->scanner->is_phpcs_available();
        echo '<tr><td>PHP_CodeSniffer (PHPCS)</td><td>' . ($phpcs_available ? '<span style="color:#00a32a;">✓ Available</span>' : '<span style="color:#d63638;">✗ Missing</span>') . '</td></tr>';
        if ($phpcs_available) {
            echo '<tr><td colspan="2" style="color:#646970;">Version: ' . esc_html($this->scanner->get_phpcs_version()) . '</td></tr>';
        }

        $standards = $this->scanner->get_standards_status();
        echo '<tr><td>PHPCompatibility Standard</td><td>' . ($standards['PHPCompatibility'] ? '<span style="color:#00a32a;">✓</span>' : '<span style="color:#d63638;">✗</span>') . '</td></tr>';
        echo '<tr><td>PHPCompatibilityWP Standard</td><td>' . ($standards['PHPCompatibilityWP'] ? '<span style="color:#00a32a;">✓</span>' : '<span style="color:#d63638;">✗</span>') . '</td></tr>';
        echo '</table>';

        // PHP version info
        echo '<h2>' . esc_html__('Current Environment', 'phpcc') . '</h2>';
        echo '<p><strong>PHP Version:</strong> ' . esc_html(phpversion()) . '<br>';
        echo '<strong>WordPress:</strong> ' . esc_html(get_bloginfo('version')) . '<br>';
        echo '<strong>Target PHP for Reports:</strong> 8.0+</p>';

        echo '</div>';
    }

    public function render_network_settings(): void {
        $this->render_settings();
    }

    public function render_sysinfo(): void {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('System Information', 'phpcc') . '</h1>';

        echo '<table class="widefat phpcc-sysinfo-table">';
        echo '<tr><th>' . esc_html__('PHP Version', 'phpcc') . '</th><td>' . esc_html(phpversion()) . '</td></tr>';
        echo '<tr><th>' . esc_html__('WordPress Version', 'phpcc') . '</th><td>' . esc_html(get_bloginfo('version')) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Server OS', 'phpcc') . '</th><td>' . esc_html(PHP_OS) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Server API', 'phpcc') . '</th><td>' . esc_html(php_sapi_name()) . '</td></tr>';

        $phpcs_available = $this->scanner->is_phpcs_available();
        echo '<tr><th>' . esc_html__('PHPCS Available', 'phpcc') . '</th><td>' . ($phpcs_available ? esc_html__('Yes (bundled)', 'phpcc') : esc_html__('No', 'phpcc')) . '</td></tr>';

        if ($phpcs_available) {
            $version = $this->scanner->get_phpcs_version();
            $standards = $this->scanner->get_standards_status();
            echo '<tr><th>' . esc_html__('PHPCS Version', 'phpcc') . '</th><td>' . esc_html($version) . '</td></tr>';
            echo '<tr><th>' . esc_html__('Standards', 'phpcc') . '</th><td>';
            foreach ($standards as $name => $available) {
                echo esc_html($name) . ': ' . ($available ? '✓' : '✗') . '<br>';
            }
            echo '</td></tr>';
        }

        echo '<tr><th>' . esc_html__('Plugin Directory', 'phpcc') . '</th><td><code>' . esc_html(PHPCC_PLUGIN_DIR) . '</code></td></tr>';
        echo '</table>';

        echo '<h2>' . esc_html__('Diagnostics', 'phpcc') . '</h2>';
        if ($phpcs_available) {
            echo '<p>' . esc_html__('PHPCS is available. Ready to scan.', 'phpcc') . '</p>';
        } else {
            echo '<div class="notice notice-error"><p>' . esc_html__('PHPCS not found.', 'phpcc') . ' <code>' . esc_html(PHPCC_PLUGIN_DIR . 'bin/phpcs.phar') . '</code></p></div>';
        }

        echo '</div>';
    }

    // ------------------------------------------------------------------
    // AJAX handlers
    // ------------------------------------------------------------------

    public function ajax_rescan(): void {
        check_ajax_referer('phpcc_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }

        $this->scanner->clear_cache();

        if (!$this->scanner->is_phpcs_available()) {
            wp_send_json_error(['message' => 'PHPCS is not available.'], 500);
        }

        $results = $this->scanner->scan_all();

        if (empty($results)) {
            wp_send_json_error(['message' => 'No components found to scan.'], 500);
        }

        wp_send_json_success([
            'message' => 'Scan completed. Found ' . count($results) . ' components.',
            'count'   => count($results),
        ]);
    }

    public function ajax_clear_cache(): void {
        check_ajax_referer('phpcc_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }
        $this->scanner->clear_cache();
        wp_send_json_success(['message' => 'Results cleared']);
    }

    public function ajax_get_detail(): void {
        check_ajax_referer('phpcc_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }

        $slug = sanitize_text_field($_POST['slug'] ?? '');
        if (empty($slug)) {
            wp_send_json_error(['message' => 'No slug provided'], 400);
        }

        $results = $this->scanner->get_cached_results();
        if (empty($results)) {
            wp_send_json_error(['message' => 'No cached results'], 404);
        }

        foreach ($results as $result) {
            if ($result['slug'] === $slug) {
                $report = $this->report->build_component_report($result);
                wp_send_json_success($report);
                return;
            }
        }

        wp_send_json_error(['message' => 'Component not found'], 404);
    }

    public function ajax_export_markdown(): void {
        check_ajax_referer('phpcc_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }

        $results = $this->scanner->get_cached_results();
        if (empty($results)) {
            wp_send_json_error(['message' => 'No scan results to export'], 404);
        }

        $markdown = $this->report->export_markdown($results);
        wp_send_json_success(['markdown' => $markdown]);
    }
}
