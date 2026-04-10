=== PHP Compatibility Checker ===
Contributors: OpenClaw Community
Tags: compatibility, phpcs, php compatibility, php 8.5, static analysis, bundled
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.2
Stable tag: 1.1.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Check WordPress plugins and themes for PHP version compatibility. Works out of the box - no external dependencies required!

== Description ==

**PHP Compatibility Checker** is a free, open-source WordPress plugin that checks your installed plugins and themes for PHP version compatibility using the industry-standard PHPCompatibilityWP sniffs.

Unlike other solutions, this plugin **requires NO external setup** - PHPCS and all required standards are bundled with the plugin.

= Zero Configuration =

1. Install and activate the plugin
2. Click "Rescan"
3. View compatibility results

That's it! No `composer install`, no PHPCS setup, no configuration files.

= Features =

* **Works Out of the Box** - PHPCS and PHPCompatibilityWP bundled, zero external dependencies
* **PHP 7.0 through 8.5 Support** - Check compatibility with all modern PHP versions.
* **Plugin & Theme Scanning** - Scans all installed plugins and your active theme.
* **Detailed Results** - See minimum and maximum supported PHP versions for each component.
* **Issue Detection** - Identifies deprecated functions, removed features, and compatibility issues.
* **Export to CSV** - Download compatibility reports for documentation or sharing.
* **Filter & Search** - Filter results by component type (plugin/theme), status (active/inactive), or issues.
* **Multisite Support** - Full support for WordPress multisite installations.
* **Timeout Protection** - Chunked scanning prevents timeouts on large plugins.

= Requirements =

* PHP 7.2 or higher
* That's it! PHPCS is bundled.

= Installation =

1. Upload the plugin to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu
3. Go to "PHP Compatibility" in the admin menu
4. Click "Rescan" to check your plugins/themes

= How It Works =

The plugin includes:
* PHP_CodeSniffer 3.7.2 (phpcs.phar)
* PHPCompatibility 9.3.5
* PHPCompatibilityWP 2.1.5

These are all bundled in the plugin directory - no external installation required.

== Frequently Asked Questions ==

= Is this plugin free? =

Yes, completely free and open source under GPLv2. No subscriptions, no licensing fees.

= Do I need to install PHPCS? =

No. PHPCS and all required standards are bundled with the plugin. It works immediately after activation.

= What PHP versions does it check? =

It checks compatibility with PHP 7.0 through PHP 8.5 using the PHPCompatibilityWP standard.

= Will it work on shared hosting? =

Yes! The plugin uses the bundled PHPCS and requires no shell access or special permissions.

= Can I scan custom/premium plugins? =

Yes, any plugin in your `/wp-content/plugins/` directory can be scanned.

= Does it work on Windows? =

Yes, the bundled PHPCS works on Windows, macOS, and Linux.

== Screenshots ==

1. Dashboard showing compatibility statistics and scan results
2. Settings page showing bundled components status
3. System information and diagnostics

== Changelog ==

= 1.1.0 =
* Bundled PHPCS and PHPCompatibilityWP - zero external dependencies
* Added timeout protection with chunked scanning
* Added "Clear Cache" button
* Added "With Issues" filter
* Improved error handling and diagnostics

= 1.0.0 =
* Initial release
* Local PHPCS scanning
* PHP 7.0-8.5 compatibility checks
* Plugin and theme scanning
* CSV export
* Multisite support

== Upgrade Notice ==

= 1.1.0 =
Now with bundled dependencies - works immediately without installing PHPCS!
