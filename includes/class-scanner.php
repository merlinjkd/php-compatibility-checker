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

    // Required PHP functions for scanning
    private $required_functions = ['proc_open', 'shell_exec', 'proc_get_status', 'proc_terminate'];

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
        if (!function_exists('shell_exec')) return null;
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
     * Check if required PHP process functions are available
     */
    public function check_system_requirements(): array {
        $missing = [];
        foreach ($this->required_functions as $func) {
            if (!function_exists($func)) {
                $missing[] = $func;
            }
        }
        return [
            'pass'   => empty($missing),
            'missing'=> $missing,
        ];
    }

    /**
     * Scan all plugins and themes for PHP 8 readiness
     */
    public function scan_all(): array {
        require_once PHPCC_PLUGIN_DIR . 'includes/class-feature-detector.php';
        require_once PHPCC_PLUGIN_DIR . 'includes/class-impact-analyzer.php';

        // Increase memory for large scans
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        } elseif (function_exists('ini_set')) {
            @ini_set('memory_limit', '512M');
        }

        $results = [];
        $feature_detector = new PHPCC_Feature_Detector();
        $impact_analyzer = new PHPCC_Impact_Analyzer();

        if (!$this->is_phpcs_available()) {
            return [new WP_Error('phpcs_not_found', 'PHPCS not available. Please reinstall the plugin.')];
        }

        // Check required PHP functions
        $reqs = $this->check_system_requirements();
        if (!$reqs['pass']) {
            return [new WP_Error(
                'missing_functions',
                sprintf(
                    'Required PHP functions are disabled: %s. Contact your host to enable them.',
                    implode(', ', $reqs['missing'])
                )
            )];
        }

        // Scan plugins
        $plugins = get_plugins();
        foreach ($plugins as $file => $data) {
            try {
                $plugin_dir = $this->get_plugin_path($file);
                if (!$plugin_dir || !file_exists($plugin_dir)) continue;

                $scan = $this->scan_component($plugin_dir, 'plugin', $file, $data, $feature_detector, $impact_analyzer);
                if (!is_wp_error($scan)) {
                    $results[] = $scan;
                }
            } catch (Throwable $e) {
                // Log plugin scan failure and continue
                $results[] = $this->error_result($file, $data, $e);
            }
        }

        // Scan active theme
        try {
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
        } catch (Throwable $e) {
            $results[] = $this->error_result('active-theme', ['Name' => 'Active Theme', 'Version' => ''], $e, 'theme');
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

        try {
            $process = @proc_open($cmd, $descriptors, $pipes);
        } catch (Throwable $e) {
            $process = false;
        }
        if (!is_resource($process)) {
            return new WP_Error('process_failed', 'Failed to start PHPCS process. Check that proc_open() is not disabled and PHP CLI is available.');
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
        // Filter out WP_Error entries before caching
        $clean = [];
        foreach ($results as $r) {
            if (is_array($r) && isset($r['slug'])) {
                $clean[] = $r;
            }
        }
        $key = 'phpcc_scan_results_v2';
        $ttl = HOUR_IN_SECONDS;
        if (is_multisite()) {
            set_site_transient($key, $clean, $ttl);
        } else {
            set_transient($key, $clean, $ttl);
        }
    }

    public function get_cached_results() {
        $key = 'phpcc_scan_results_v2';
        $data = is_multisite() ? get_site_transient($key) : get_transient($key);
        if (!is_array($data)) return false;
        // Strip any WP_Error objects that may have snuck in
        return array_values(array_filter($data, function($r) { return is_array($r) && isset($r['slug']); }));
    }

    public function clear_cache(): void {
        $key = 'phpcc_scan_results_v2';
        if (is_multisite()) delete_site_transient($key);
        else delete_transient($key);
    }

    /**
     * Create a placeholder result for a component that crashed during scanning
     */
    private function error_result($slug, array $info, Throwable $e, string $type = 'plugin'): array {
        return [
            'slug'            => $slug,
            'name'            => $info['Name'] ?? $slug,
            'version'         => $info['Version'] ?? 'unknown',
            'type'            => $type,
            'status'          => 'Error',
            'php_min'         => 'unknown',
            'php_max'         => 'unknown',
            'issues'          => ['critical' => [], 'warning' => [], 'info' => []],
            'issue_counts'    => ['critical' => 0, 'warning' => 0, 'info' => 0],
            'readiness_score' => 0,
            'readiness_label' => 'Scan Error',
            'features'        => [],
            'impact'          => null,
            'scan_error'      => $e->getMessage(),
        ];
    }
}
