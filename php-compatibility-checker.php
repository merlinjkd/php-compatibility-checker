<?php
/**
 * Plugin Name: PHP Compatibility Checker
 * Plugin URI: https://github.com/openclaw/php-compatibility-checker
 * Description: Check WordPress plugins and themes for PHP version compatibility using PHPCompatibilityWP. No licensing required - works out of the box with bundled PHPCS.
 * Version: 1.1.0
 * Author: OpenClaw Community
 * Author URI: https://github.com/openclaw
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * Text Domain: phpcc
 * Domain Path: /languages
 *
 * @package PHPCompatibilityChecker
 */

declare(strict_types=1);

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define constants
define('PHPCC_VERSION', '1.1.0');
define('PHPCC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PHPCC_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once PHPCC_PLUGIN_DIR . 'includes/class-scanner.php';
require_once PHPCC_PLUGIN_DIR . 'includes/class-admin.php';

/**
 * Main plugin class
 */
class PHP_Compatibility_Checker {
    
    const MENU_SLUG = 'phpcc_dashboard';
    const SUBMENU_SETTINGS = 'phpcc_settings';
    const SUBMENU_SYSINFO = 'phpcc_sysinfo';
    
    private $scanner;
    private $admin;
    
    public function __construct() {
        $this->scanner = new PHPCC_Scanner();
        $this->admin = new PHPCC_Admin($this->scanner);
        
        add_action('init', [$this, 'init']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        
        // AJAX handlers
        add_action('wp_ajax_phpcc_rescan', [$this, 'ajax_rescan']);
        add_action('wp_ajax_phpcc_clear_cache', [$this, 'ajax_clear_cache']);
        
        // Register menus
        add_action('admin_menu', [$this, 'register_menus']);
        add_action('network_admin_menu', [$this, 'register_network_menus']);
    }
    
    public function init(): void {
        load_plugin_textdomain('phpcc', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }
    
    public function enqueue_assets($hook): void {
        if (strpos($hook, 'phpcc_') === false) {
            return;
        }
        
        wp_enqueue_style('phpcc-css', PHPCC_PLUGIN_URL . 'assets/css/phpcc.css', [], PHPCC_VERSION);
        wp_enqueue_script('phpcc-js', PHPCC_PLUGIN_URL . 'assets/js/phpcc.js', ['jquery'], PHPCC_VERSION, true);
        
        wp_localize_script('phpcc-js', 'PHPCC_Vars', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('phpcc_nonce'),
        ]);
    }
    
    public function register_menus(): void {
        add_menu_page(
            __('PHP Compatibility Checker', 'phpcc'),
            __('PHP Compatibility', 'phpcc'),
            'manage_options',
            self::MENU_SLUG,
            [$this->admin, 'render_dashboard'],
            'dashicons-code-standards',
            90
        );
        
        add_submenu_page(
            self::MENU_SLUG,
            __('Settings', 'phpcc'),
            __('Settings', 'phpcc'),
            'manage_options',
            self::SUBMENU_SETTINGS,
            [$this->admin, 'render_settings']
        );
        
        add_submenu_page(
            self::MENU_SLUG,
            __('System Info', 'phpcc'),
            __('System Info', 'phpcc'),
            'manage_options',
            self::SUBMENU_SYSINFO,
            [$this->admin, 'render_sysinfo']
        );
    }
    
    public function register_network_menus(): void {
        add_menu_page(
            __('PHP Compatibility Checker', 'phpcc'),
            __('PHP Compatibility', 'phpcc'),
            'manage_network',
            self::MENU_SLUG,
            [$this->admin, 'render_network_dashboard'],
            'dashicons-code-standards',
            90
        );
        
        add_submenu_page(
            self::MENU_SLUG,
            __('Settings', 'phpcc'),
            __('Settings', 'phpcc'),
            'manage_network',
            self::SUBMENU_SETTINGS,
            [$this->admin, 'render_network_settings']
        );
        
        add_submenu_page(
            self::MENU_SLUG,
            __('System Info', 'phpcc'),
            __('System Info', 'phpcc'),
            'manage_network',
            self::SUBMENU_SYSINFO,
            [$this->admin, 'render_sysinfo']
        );
    }
    
    public function ajax_rescan(): void {
        check_ajax_referer('phpcc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }
        
        // Clear cached results
        $this->scanner->clear_cache();
        
        // Check PHPCS availability
        if (!$this->scanner->is_phpcs_available()) {
            wp_send_json_error(['message' => 'PHPCS is not available. Please check System Info.'], 500);
        }
        
        // Run new scan
        $results = $this->scanner->scan_all();
        
        // Check if scan returned errors
        $has_errors = false;
        foreach ($results as $result) {
            if (is_wp_error($result)) {
                $has_errors = true;
                break;
            }
        }
        
        if ($has_errors && empty(array_filter($results, function($r) { return !is_wp_error($r); }))) {
            wp_send_json_error(['message' => 'Scan failed. Check System Info for diagnostics.'], 500);
        }
        
        wp_send_json_success([
            'message' => 'Scan completed successfully',
            'count'   => count($results)
        ]);
    }
    
    public function ajax_clear_cache(): void {
        check_ajax_referer('phpcc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }
        
        $this->scanner->clear_cache();
        
        wp_send_json_success(['message' => 'Cache cleared']);
    }
}

// Initialize plugin
new PHP_Compatibility_Checker();
