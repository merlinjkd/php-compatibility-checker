# Changelog

## 1.1.0 - Zero-Dependency Release

### Major Changes
- **Bundled PHPCS and Standards** - PHPCS 3.7.2, PHPCompatibility 9.3.5, and PHPCompatibilityWP 2.1.5 are now included with the plugin
- **Zero External Dependencies** - Works out of the box with no `composer install` or external setup required
- **Auto-detection Removed** - Plugin no longer searches for global PHPCS installation

### Improvements
- Added "Clear Cache" button to manually clear scan results
- Added "With Issues" filter to show only components with compatibility problems
- Improved error messages when bundled components are missing
- Added bundled component status display in Settings page
- Scanner now uses bundled PHPCS via `php phpcs.phar` execution

### Technical Changes
- Scanner class now looks for PHPCS in `bin/phpcs.phar`
- Standards loaded from `standards/PHPCompatibility/` and `standards/PHPCompatibilityWP/`
- Removed PHPCS path configuration option (no longer needed)
- Updated Admin class to show bundled component status
- Added `get_standards_status()` method to check bundled standards

## 1.0.0 - Initial Release

### Features
- Local PHPCS scanning with PHPCompatibilityWP standard
- PHP 7.0-8.5 compatibility checking
- Plugin and theme scanning
- CSV export functionality
- Multisite support
- AJAX-based rescanning
- Progress indicators
- Filter by type and status
