<?php
/**
 * Plugin Name: PHP Compatibility Checker
 * Plugin URI: https://github.com/merlinjkd/php-compatibility-checker
 * Description: Comprehensive PHP 8 readiness scanner with feature detection, impact analysis, and human-readable reports. Bundled PHPCS — zero dependencies.
 * Version: 2.0.3
 * Author: WP Essential Support
 * Author URI: https://greatbeardesign.com
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.8
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
define('PHPCC_VERSION', '2.0.3');
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
        add_action('wp_ajax_phpcc_rescan', [$this->admin, 'ajax_rescan']);
        add_action('wp_ajax_phpcc_clear_cache', [$this->admin, 'ajax_clear_cache']);
        add_action('wp_ajax_phpcc_get_detail', [$this->admin, 'ajax_get_detail']);
        add_action('wp_ajax_phpcc_export_markdown', [$this->admin, 'ajax_export_markdown']);
        add_action('wp_ajax_phpcc_deactivate_incompatible', [$this->admin, 'ajax_deactivate_incompatible']);
        add_action('wp_ajax_phpcc_restore_plugins', [$this->admin, 'ajax_restore_plugins']);

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
            'strings' => [
                'scanning'    => __('Scanning...', 'phpcc'),
                'rescan'      => __('Scan All Components', 'phpcc'),
                'errorPrefix' => __('Scan failed:', 'phpcc'),
                'noData'      => __('No data to export.', 'phpcc'),
                'close'       => __('Close', 'phpcc'),
            ],
        ]);
    }

    public function register_menus(): void {
        add_menu_page(
            __('PHP Compatibility Checker', 'phpcc'),
            __('PHP 8 Readiness', 'phpcc'),
            'manage_options',
            self::MENU_SLUG,
            [$this->admin, 'render_dashboard'],
            'dashicons-code-standards',
            90
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Dashboard', 'phpcc'),
            __('Dashboard', 'phpcc'),
            'manage_options',
            self::MENU_SLUG,
            [$this->admin, 'render_dashboard']
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
            __('PHP 8 Readiness', 'phpcc'),
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
}

// Initialize plugin
new PHP_Compatibility_Checker();
