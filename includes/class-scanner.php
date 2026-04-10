<?php
/**
 * PHPCS Scanner with Bundled Dependencies
 * No external PHPCS installation required
 */

declare(strict_types=1);

class PHPCC_Scanner {
    
    private $phpcs_path;
    private $standards_path;
    private $max_exec_time = 300; // 5 minutes
    
    public function __construct() {
        // Use bundled PHPCS
        $this->phpcs_path = PHPCC_PLUGIN_DIR . 'bin/phpcs.phar';
        $this->standards_path = PHPCC_PLUGIN_DIR . 'standards/';
    }
    
    /**
     * Check if bundled PHPCS is available
     */
    public function is_phpcs_available(): bool {
        return file_exists($this->phpcs_path) && is_readable($this->phpcs_path);
    }
    
    /**
     * Get PHPCS version
     */
    public function get_phpcs_version(): ?string {
        if (!$this->is_phpcs_available()) {
            return null;
        }
        
        $cmd = sprintf(
            'php %s --version 2>&1',
            escapeshellarg($this->phpcs_path)
        );
        
        $output = shell_exec($cmd);
        if (preg_match('/version\s+(\d+\.\d+\.\d+)/i', $output, $matches)) {
            return $matches[1];
        }
        return null;
    }
    
    /**
     * Check if standards are properly installed
     */
    public function get_standards_status(): array {
        $status = [
            'PHPCompatibility' => is_dir($this->standards_path . 'PHPCompatibility'),
            'PHPCompatibilityWP' => is_dir($this->standards_path . 'PHPCompatibilityWP'),
        ];
        return $status;
    }
    
    /**
     * Scan all installed plugins and themes
     */
    public function scan_all(): array {
        $results = [];
        
        // Check PHPCS availability
        if (!$this->is_phpcs_available()) {
            return [new WP_Error('phpcs_not_found', 'PHPCS not available. Please reinstall the plugin.')];
        }
        
        // Scan plugins
        $plugins = get_plugins();
        foreach ($plugins as $file => $data) {
            $plugin_dir = $this->get_plugin_path($file);
            
            if (!$plugin_dir || !file_exists($plugin_dir)) {
                continue;
            }
            
            $scan = $this->scan_path($plugin_dir, 'plugin', $file, $data);
            if (!is_wp_error($scan)) {
                $results[] = $scan;
            }
        }
        
        // Scan active theme
        $theme = wp_get_theme();
        $theme_dir = $theme->get_stylesheet_directory();
        if (file_exists($theme_dir)) {
            $scan = $this->scan_path($theme_dir, 'theme', $theme->get_stylesheet(), [
                'Name'    => $theme->get('Name'),
                'Version' => $theme->get('Version'),
            ]);
            if (!is_wp_error($scan)) {
                $results[] = $scan;
            }
        }
        
        // Cache results
        $this->cache_results($results);
        
        return $results;
    }
    
    /**
     * Get plugin directory path
     */
    private function get_plugin_path(string $file): ?string {
        $dir = dirname($file);
        if ($dir === '.') {
            // Single file plugin
            return WP_PLUGIN_DIR . '/' . $file;
        }
        return WP_PLUGIN_DIR . '/' . $dir;
    }
    
    /**
     * Scan a specific path with bundled PHPCS
     */
    private function scan_path(string $path, string $type, string $slug, array $info) {
        // Validate path is within allowed directories
        if (!$this->is_valid_path($path)) {
            return new WP_Error('invalid_path', 'Invalid scan path');
        }
        
        // Build PHPCS command with bundled standard
        $standard_path = $this->standards_path . 'PHPCompatibilityWP';
        
        $cmd = sprintf(
            'php %s --standard=%s --report=json --extensions=php --ignore=*/vendor/*,*/node_modules/*,*/tests/*,*/test/* %s 2>&1',
            escapeshellarg($this->phpcs_path),
            escapeshellarg($standard_path),
            escapeshellarg($path)
        );
        
        // Execute with timeout
        $output = $this->execute_with_timeout($cmd, $this->max_exec_time);
        
        if (is_wp_error($output)) {
            // Return empty scan on error
            return $this->create_empty_result($slug, $info, $type, $output->get_error_message());
        }
        
        $json = json_decode($output, true);
        
        if (!$json || !isset($json['files'])) {
            // No PHP files found or no issues
            return $this->create_empty_result($slug, $info, $type);
        }
        
        return $this->parse_phpcs_results($json, $slug, $info, $type, $path);
    }
    
    /**
     * Execute command with timeout
     */
    private function execute_with_timeout(string $cmd, int $timeout) {
        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];
        
        $process = proc_open($cmd, $descriptors, $pipes);
        
        if (!is_resource($process)) {
            return new WP_Error('process_failed', 'Failed to start PHPCS process');
        }
        
        // Close stdin immediately
        fclose($pipes[0]);
        
        // Set streams to non-blocking
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        
        $output = '';
        $start = time();
        
        while (true) {
            $status = proc_get_status($process);
            
            if (!$status['running']) {
                break;
            }
            
            if (time() - $start > $timeout) {
                proc_terminate($process, 9);
                return new WP_Error('timeout', 'PHPCS scan timed out after ' . $timeout . ' seconds');
            }
            
            $output .= stream_get_contents($pipes[1]) ?? '';
            usleep(100000); // 100ms
        }
        
        // Get remaining output
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
    
    /**
     * Validate path is within allowed WordPress directories
     */
    private function is_valid_path(string $path): bool {
        $allowed_prefixes = [
            WP_PLUGIN_DIR,
            get_theme_root(),
            WP_CONTENT_DIR . '/themes/',
        ];
        
        foreach ($allowed_prefixes as $prefix) {
            if (strpos(realpath($path), realpath($prefix)) === 0) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Parse PHPCS JSON results
     */
    private function parse_phpcs_results(array $json, string $slug, array $info, string $type, string $base_path): array {
        $issues = [];
        $errors = 0;
        $warnings = 0;
        $php_versions = [];
        
        foreach ($json['files'] as $file => $data) {
            if (empty($data['messages'])) {
                continue;
            }
            
            foreach ($data['messages'] as $msg) {
                $issues[] = [
                    'file'    => str_replace($base_path, '', $file),
                    'line'    => $msg['line'],
                    'type'    => $msg['type'],
                    'message' => $msg['message'],
                    'source'  => $msg['source'],
                ];
                
                if ($msg['type'] === 'ERROR') {
                    $errors++;
                } else {
                    $warnings++;
                }
                
                // Extract PHP version from message
                if (preg_match('/PHP\s+(\d+\.\d+)/', $msg['message'], $m)) {
                    $php_versions[] = $m[1];
                }
            }
        }
        
        // Determine PHP compatibility range
        $min_php = '7.0';
        $max_php = '8.5';
        
        if (!empty($php_versions)) {
            $max_issue_version = max($php_versions);
            if (version_compare($max_issue_version, '8.5', '>=')) {
                $max_php = '8.4';
            } elseif (version_compare($max_issue_version, '8.4', '>=')) {
                $max_php = '8.3';
            } elseif (version_compare($max_issue_version, '8.3', '>=')) {
                $max_php = '8.2';
            } elseif (version_compare($max_issue_version, '8.2', '>=')) {
                $max_php = '8.1';
            } elseif (version_compare($max_issue_version, '8.1', '>=')) {
                $max_php = '8.0';
            } elseif (version_compare($max_issue_version, '8.0', '>=')) {
                $max_php = '7.4';
            }
        }
        
        return [
            'slug'          => $slug,
            'name'          => $info['Name'] ?? $slug,
            'version'       => $info['Version'] ?? 'unknown',
            'type'          => $type,
            'status'        => $this->get_component_status($slug, $type),
            'php_min'       => $min_php,
            'php_max'       => $max_php,
            'issues'        => $issues,
            'error_count'   => $errors,
            'warning_count' => $warnings,
        ];
    }
    
    /**
     * Create empty result for when no PHP files found or error
     */
    private function create_empty_result(string $slug, array $info, string $type, string $error_note = null): array {
        return [
            'slug'          => $slug,
            'name'          => $info['Name'] ?? $slug,
            'version'       => $info['Version'] ?? 'unknown',
            'type'          => $type,
            'status'        => $this->get_component_status($slug, $type),
            'php_min'       => '7.0',
            'php_max'       => '8.5',
            'issues'        => [],
            'error_count'   => 0,
            'warning_count' => 0,
            'note'          => $error_note,
        ];
    }
    
    /**
     * Get component status (active/inactive)
     */
    private function get_component_status(string $slug, string $type): string {
        if ($type === 'plugin') {
            return is_plugin_active($slug) ? 'Active' : 'Inactive';
        }
        return 'Active';
    }
    
    /**
     * Cache scan results
     */
    private function cache_results(array $results): void {
        $transient_key = is_multisite() ? '_site_transient_' : '_transient_';
        $key = 'phpcc_scan_results';
        $ttl = HOUR_IN_SECONDS;
        
        if (is_multisite()) {
            set_site_transient($key, $results, $ttl);
        } else {
            set_transient($key, $results, $ttl);
        }
    }
    
    /**
     * Get cached results
     */
    public function get_cached_results() {
        $key = 'phpcc_scan_results';
        
        if (is_multisite()) {
            return get_site_transient($key);
        }
        return get_transient($key);
    }
    
    /**
     * Clear cached results
     */
    public function clear_cache(): void {
        $key = 'phpcc_scan_results';
        
        if (is_multisite()) {
            delete_site_transient($key);
        } else {
            delete_transient($key);
        }
    }
}
