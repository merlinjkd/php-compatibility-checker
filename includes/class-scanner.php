<?php
/**
 * PHP 8 Compatibility Scanner
 *
 * Targeted PHPCS scanning with issue categorization:
 * - Critical: Will break on PHP 8.0+
 * - Warning: Deprecated in PHP 7.4, removed/fatal in PHP 8.x
 * - Info: Minor compatibility concerns
 *
 * Also triggers feature detection and impact analysis per component.
 */

declare(strict_types=1);

class PHPCC_Scanner {

    private $phpcs_path;
    private $standards_path;
    private $max_exec_time = 300;

    // PHP 8 specific rules we care about
    private $critical_sources = [
        'PHPCompatibility.FunctionUse.RemovedFunctions',
        'PHPCompatibility.Variables.RemovedPredefinedGlobalVariables',
        'PHPCompatibility.ParameterValues.RemovedImplodeFlexibleParamOrder',
        'PHPCompatibility.FunctionUse.ArgumentFunctionsUsage',
        'PHPCompatibility.Operators.ForbiddenNegativeBitshift',
        'PHPCompatibility.Syntax.ForbiddenCallTimePassByReference',
        'PHPCompatibility.Classes.ForbiddenAbstractPrivateMethods',
        'PHPCompatibility.IniDirectives.RemovedIniDirectives',
    ];

    private $warning_sources = [
        'PHPCompatibility.FunctionUse.NewFunctionParameters',
        'PHPCompatibility.FunctionUse.OptionalToRequiredFunctionParameters',
        'PHPCompatibility.TypeCasts.RemovedTypeCasts',
        'PHPCompatibility.Extensions.RemovedExtensions',
        'PHPCompatibility.Interfaces.InternalInterfaces',
    ];

    public function __construct() {
        $this->phpcs_path = PHPCC_PLUGIN_DIR . 'bin/phpcs.phar';
        $this->standards_path = PHPCC_PLUGIN_DIR . 'standards/';
    }

    public function is_phpcs_available(): bool {
        return file_exists($this->phpcs_path) && is_readable($this->phpcs_path);
    }

    public function get_phpcs_version(): ?string {
        if (!$this->is_phpcs_available()) return null;
        $cmd = sprintf('php %s --version 2>&1', escapeshellarg($this->phpcs_path));
        $output = shell_exec($cmd);
        if (preg_match('/version\s+(\d+\.\d+\.\d+)/i', $output, $m)) return $m[1];
        return null;
    }

    public function get_standards_status(): array {
        return [
            'PHPCompatibility' => is_dir($this->standards_path . 'PHPCompatibility'),
            'PHPCompatibilityWP' => is_dir($this->standards_path . 'PHPCompatibilityWP'),
        ];
    }

    /**
     * Scan all plugins and themes for PHP 8 readiness
     */
    public function scan_all(): array {
        require_once PHPCC_PLUGIN_DIR . 'includes/class-feature-detector.php';
        require_once PHPCC_PLUGIN_DIR . 'includes/class-impact-analyzer.php';

        $results = [];
        $feature_detector = new PHPCC_Feature_Detector();
        $impact_analyzer = new PHPCC_Impact_Analyzer();

        if (!$this->is_phpcs_available()) {
            return [new WP_Error('phpcs_not_found', 'PHPCS not available. Please reinstall the plugin.')];
        }

        // Scan plugins
        $plugins = get_plugins();
        foreach ($plugins as $file => $data) {
            $plugin_dir = $this->get_plugin_path($file);
            if (!$plugin_dir || !file_exists($plugin_dir)) continue;

            $scan = $this->scan_component($plugin_dir, 'plugin', $file, $data, $feature_detector, $impact_analyzer);
            if (!is_wp_error($scan)) {
                $results[] = $scan;
            }
        }

        // Scan active theme
        $theme = wp_get_theme();
        $theme_dir = $theme->get_stylesheet_directory();
        if (file_exists($theme_dir)) {
            $scan = $this->scan_component($theme_dir, 'theme', $theme->get_stylesheet(), [
                'Name'    => $theme->get('Name'),
                'Version' => $theme->get('Version'),
            ], $feature_detector, $impact_analyzer);
            if (!is_wp_error($scan)) {
                $results[] = $scan;
            }
        }

        $this->cache_results($results);
        return $results;
    }

    /**
     * Scan a single component with feature detection and impact analysis
     */
    private function scan_component(string $path, string $type, string $slug, array $info, PHPCC_Feature_Detector $feature_detector, PHPCC_Impact_Analyzer $impact_analyzer) {
        if (!$this->is_valid_path($path)) {
            return new WP_Error('invalid_path', 'Invalid scan path');
        }

        // Run PHPCS
        $cmd = sprintf(
            'php %s --standard=%s --report=json --extensions=php --ignore=*/vendor/*,*/node_modules/*,*/tests/*,*/test/* %s 2>&1',
            escapeshellarg($this->phpcs_path),
            escapeshellarg($this->standards_path . 'PHPCompatibilityWP'),
            escapeshellarg($path)
        );

        $output = $this->execute_with_timeout($cmd, $this->max_exec_time);

        // Parse PHPCS results
        if (is_wp_error($output)) {
            $phpcs_data = ['files' => []];
        } else {
            $json = json_decode($output, true);
            $phpcs_data = ($json && isset($json['files'])) ? $json : ['files' => []];
        }

        // Categorize issues
        $categorized = $this->categorize_issues($phpcs_data, $path);

        // Feature detection
        $features = $feature_detector->analyze_component($path, $type, $slug, $info);
        $feature_summary = $feature_detector->summarize_features($features);

        // Impact analysis (only for active plugins to avoid false positives)
        $impact = null;
        if ($type === 'plugin' && is_plugin_active($slug)) {
            $impact = $impact_analyzer->analyze_impact($features, $slug, $type);
        } elseif ($type === 'theme') {
            $impact = $impact_analyzer->analyze_impact($features, $slug, $type);
        }

        // Calculate PHP 8 readiness score
        $readiness = $this->calculate_readiness($categorized);

        return [
            'slug'            => $slug,
            'name'            => $info['Name'] ?? $slug,
            'version'         => $info['Version'] ?? 'unknown',
            'type'            => $type,
            'status'          => $type === 'plugin' ? (is_plugin_active($slug) ? 'Active' : 'Inactive') : 'Active',
            'php_min'         => '7.0',
            'php_max'         => $this->determine_max_php($categorized),
            'issues'          => $categorized,
            'issue_counts'    => [
                'critical' => count($categorized['critical']),
                'warning'  => count($categorized['warning']),
                'info'     => count($categorized['info']),
            ],
            'readiness_score' => $readiness['score'],
            'readiness_label' => $readiness['label'],
            'features'        => $feature_summary,
            'impact'          => $impact,
            'scan_error'      => is_wp_error($output) ? $output->get_error_message() : null,
        ];
    }

    /**
     * Categorize PHPCS issues by severity for PHP 8 migration
     */
    private function categorize_issues(array $json, string $base_path): array {
        $result = ['critical' => [], 'warning' => [], 'info' => []];

        if (empty($json['files'])) return $result;

        foreach ($json['files'] as $file => $data) {
            if (empty($data['messages'])) continue;

            foreach ($data['messages'] as $msg) {
                $issue = [
                    'file'    => str_replace(trailingslashit($base_path), '', $file),
                    'line'    => $msg['line'],
                    'type'    => $msg['type'],
                    'message' => $msg['message'],
                    'source'  => $msg['source'],
                ];

                $source = $msg['source'];

                // Critical: functions/features removed in PHP 8.0
                if ($this->is_critical_for_php8($source, $msg['message'])) {
                    $result['critical'][] = $issue;
                }
                // Warning: deprecated in 7.4, removed in 8.0+
                elseif ($this->is_warning_for_php8($source, $msg['message'])) {
                    $result['warning'][] = $issue;
                }
                // Everything else
                else {
                    $result['info'][] = $issue;
                }
            }
        }

        return $result;
    }

    private function is_critical_for_php8(string $source, string $message): bool {
        // Direct matches from critical sources
        foreach ($this->critical_sources as $crit) {
            if (strpos($source, $crit) === 0) return true;
        }

        // PHP 8.0 specific removals
        if (stripos($message, 'removed in PHP 8.0') !== false) return true;
        if (stripos($message, 'fatal error') !== false && stripos($message, 'php 8') !== false) return true;
        if (stripos($message, 'signature mismatch') !== false) return true;
        if (stripos($message, 'named parameter') !== false && stripos($message, 'not supported') !== false) return true;
        if (stripos($message, 'match') !== false && stripos($message, 'reserved') !== false) return true;

        return false;
    }

    private function is_warning_for_php8(string $source, string $message): bool {
        foreach ($this->warning_sources as $warn) {
            if (strpos($source, $warn) === 0) return true;
        }

        if (stripos($message, 'deprecated') !== false && stripos($message, 'php 7.4') !== false) return true;
        if (stripos($message, 'deprecated') !== false && stripos($message, 'php 8.0') !== false) return true;
        if (stripos($message, 'not present') !== false && stripos($message, 'php') !== false) return true;
        if (stripos($message, 'behavior has changed') !== false) return true;

        return false;
    }

    private function calculate_readiness(array $categorized): array {
        $critical = count($categorized['critical']);
        $warning = count($categorized['warning']);
        $info = count($categorized['info']);

        $total_weighted = ($critical * 10) + ($warning * 3) + ($info * 1);

        if ($critical > 0) {
            return [
                'score' => max(0, 100 - min($critical * 25, 100)),
                'label' => $critical >= 5 ? 'Critical Issues' : 'Has Critical Issues',
            ];
        }

        if ($warning > 0) {
            return [
                'score' => max(40, 100 - min($warning * 5, 60)),
                'label' => 'Warnings Found',
            ];
        }

        if ($info > 0) {
            return ['score' => 95, 'label' => 'Minor Concerns'];
        }

        return ['score' => 100, 'label' => 'PHP 8 Ready'];
    }

    private function determine_max_php(array $categorized): string {
        if (count($categorized['critical']) > 0) return '7.4';
        if (count($categorized['warning']) > 0) return '8.0';
        return '8.5';
    }

    private function get_plugin_path(string $file): ?string {
        $dir = dirname($file);
        if ($dir === '.') return WP_PLUGIN_DIR . '/' . $file;
        return WP_PLUGIN_DIR . '/' . $dir;
    }

    private function is_valid_path(string $path): bool {
        $allowed = [WP_PLUGIN_DIR, get_theme_root(), WP_CONTENT_DIR . '/themes/'];
        foreach ($allowed as $prefix) {
            if (strpos(realpath($path), realpath($prefix)) === 0) return true;
        }
        return false;
    }

    private function execute_with_timeout(string $cmd, int $timeout) {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return new WP_Error('process_failed', 'Failed to start PHPCS process');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $start = time();

        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) break;
            if (time() - $start > $timeout) {
                proc_terminate($process, 9);
                return new WP_Error('timeout', 'PHPCS scan timed out after ' . $timeout . 's');
            }
            $output .= stream_get_contents($pipes[1]) ?? '';
            usleep(100000);
        }

        $output .= stream_get_contents($pipes[1]) ?? '';
        $errors = stream_get_contents($pipes[2]) ?? '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if (empty($output) && !empty($errors)) {
            return new WP_Error('phpcs_error', trim($errors));
        }

        return $output;
    }

    // Cache methods
    public function cache_results(array $results): void {
        $key = 'phpcc_scan_results_v2';
        $ttl = HOUR_IN_SECONDS;
        if (is_multisite()) {
            set_site_transient($key, $results, $ttl);
        } else {
            set_transient($key, $results, $ttl);
        }
    }

    public function get_cached_results() {
        $key = 'phpcc_scan_results_v2';
        if (is_multisite()) return get_site_transient($key);
        return get_transient($key);
    }

    public function clear_cache(): void {
        $key = 'phpcc_scan_results_v2';
        if (is_multisite()) delete_site_transient($key);
        else delete_transient($key);
    }
}
