=== PHP Compatibility Checker ===
Contributors: merlinjkd
Tags: compatibility, phpcs, php compatibility, php 8, php 8.1, static analysis, bundled, migration
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.2
Stable tag: 2.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Comprehensive PHP 8 readiness analysis with feature detection, impact assessment, and human-readable reports. Zero external dependencies.

== Description ==

**PHP Compatibility Checker** is a free, open-source WordPress plugin that analyzes your installed plugins and themes for PHP version compatibility — with a powerful twist.

Unlike basic PHPCS wrappers, this plugin goes far deeper:

**1. PHP 8 Remediation Scanning**
Scans every plugin and theme with the industry-standard PHPCompatibilityWP standard. Issues are categorized by severity (Critical, Warning, Info) so you know exactly what must be fixed before upgrading from PHP 7.4.

**2. Feature Detection**
Discover what each plugin actually *does*. Shortcodes, custom post types, taxonomies, widgets, Gutenberg blocks, admin pages, REST endpoints, cron jobs, custom database tables, WooCommerce integrations, settings options, and more.

**3. Impact Analysis**
If you remove a plugin, what breaks? Counts posts using shortcodes, CPT content, widget placements, stored options, DB table rows, scheduled tasks, and block instances. Evaluates overall removal risk as Low, Medium, High, or Critical.

**4. Human-Readable Reports**
Every plugin gets an executive-style report card: readiness score, specific PHP 8 issues, feature inventory, impact summary, and recommended actions. Export everything to CSV for client documentation.

**5. Zero Dependencies**
PHPCS + PHPCompatibility + PHPCompatibilityWP are all bundled in the plugin. No `composer install`, no server shell access, no external setup.

= Use Case =

You're managing 40+ WordPress client sites all on PHP 7.4. You want to upgrade to PHP 8.0 or 8.1, but you can't afford breakage. Install this plugin on each site, run a scan, and get:

- Which plugins will **break** on PHP 8.0+
- Which plugins are **safe** to upgrade
- What content depends on each plugin (so you know what migrates first)
- A printable/CSV export for client approval or your migration playbook

= Key Features =

* **PHP 8.0–8.5 targeted scanning** with bundled PHPCS 3.7.2
* **Readiness score** per component (0–100%)
* **Critical/Warning/Info categorization**
* **Feature detection**: shortcodes, CPTs, taxonomies, widgets, blocks, menus, hooks, cron, REST, WooCommerce, DB tables, options
* **Impact assessment**: what breaks if the plugin is removed
* **Executive summary**: site-wide readiness at a glance
* **CSV export** for documentation
* **Print-ready reports**
* **Multisite support**
* **Chunked scanning** to prevent timeouts on large plugins
* **Filterable dashboard**: view by type, status, critical issues, PHP 8 ready

= Requirements =

* WordPress 5.8+
* PHP 7.2+ (to run the scanner)
* No other requirements — PHPCS is bundled

== Installation ==

1. Upload the plugin to `/wp-content/plugins/`
2. Activate through the Plugins menu
3. Go to "PHP 8 Readiness" in the admin sidebar
4. Click "Scan All Components"
5. Review results, export CSV as needed

== Frequently Asked Questions ==

= Is this plugin free? =

Yes. GPLv2. No subscriptions or licensing fees.

= Do I need to install PHPCS? =

No. PHPCS and all required standards are bundled. Works out of the box.

= What PHP versions does it check? =

It analyzes code for compatibility from PHP 7.0 up through PHP 8.5 using the PHPCompatibilityWP standard.

= Will it work on shared hosting? =

Yes. Uses bundled PHPCS with chunked scanning. No shell access required.

= Can I scan premium/custom plugins? =

Yes. Any plugin in `/wp-content/plugins/` can be scanned.

= Does it work on Windows? =

Yes. Works on Windows, macOS, and Linux.

= What does "impact" mean? =

Impact analysis counts your actual site content that depends on the plugin (shortcodes in posts, widgets in sidebars, CPT entries, etc.). This helps you decide if a plugin can be safely removed or must be replaced before deletion.

== Changelog ==

= 2.0.0 =
* **Major rewrite** — comprehensive v2 architecture
* Added feature detection (shortcodes, CPTs, taxonomies, widgets, blocks, hooks, REST, cron, WooCommerce, DB tables, options)
* Added impact analysis — what breaks if you remove a plugin
* Added executive summary dashboard with readiness scores
* Added per-component detail modals with actionable reports
* Added print-ready report styles
* Added filterable card grid view
* PHP 8.0+ targeted categorization (Critical, Warning, Info)
* Enhanced CSV export with impact and feature columns
* Bundled PHPCS + PHPCompatibilityWP standards
* Multisite support

= 1.1.0 =
* Bundled PHPCS and PHPCompatibilityWP — zero external dependencies
* Added timeout protection with chunked scanning
* Added "Clear Cache" button
* Added "With Issues" filter
* Improved error handling and diagnostics

= 1.0.0 =
* Initial release
* Local PHPCS scanning
* PHP 7.0–8.5 compatibility checks
* Plugin and theme scanning
* CSV export

== Upgrade Notice ==

= 2.0.0 =
Major upgrade — feature detection, impact analysis, and human-readable readiness reports. Clear your cache after upgrading for full v2 data.
