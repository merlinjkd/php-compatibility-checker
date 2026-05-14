<?php
/**
 * Impact Analyzer — Assesses what happens if a plugin/theme is removed
 *
 * Cross-references plugin features with actual site content to answer:
 * "If I remove this plugin, what breaks?"
 */

declare(strict_types=1);

class PHPCC_Impact_Analyzer {

    /**
     * Analyze impact of removing a specific component
     */
    public function analyze_impact(array $features, string $slug, string $type): array {
        $impact = [
            'shortcode_usage'    => [],
            'cpt_content_count'  => [],
            'widget_usage'       => [],
            'option_values'      => [],
            'db_table_rows'      => [],
            'active_hooks'       => [],
            'admin_page_access'  => [],
            'cron_scheduled'     => [],
            'woocommerce_active' => false,
            'block_usage_count'  => 0,
            'menu_locations'     => [],
            'overall_risk'       => 'low',
            'recommendation'     => '',
        ];

        // Shortcode usage in posts
        if (!empty($features['shortcodes'])) {
            $impact['shortcode_usage'] = $this->count_shortcode_usage($features['shortcodes']);
        }

        // CPT content counts
        if (!empty($features['custom_post_types'])) {
            $impact['cpt_content_count'] = $this->count_cpt_posts($features['custom_post_types']);
        }

        // Widget usage
        if (!empty($features['widgets'])) {
            $impact['widget_usage'] = $this->count_widget_usage($features['widgets']);
        }

        // Options with stored data
        if (!empty($features['options'])) {
            $impact['option_values'] = $this->get_option_values($features['options']);
        }

        // DB table row counts
        if (!empty($features['db_tables'])) {
            $impact['db_table_rows'] = $this->count_table_rows($features['db_tables']);
        }

        // Cron job schedules
        if (!empty($features['cron_jobs'])) {
            $impact['cron_scheduled'] = $this->check_cron_schedules($features['cron_jobs']);
        }

        // Block usage (if blocks registered)
        if (!empty($features['blocks'])) {
            $impact['block_usage_count'] = $this->count_block_usage($features['blocks']);
        }

        // Menu locations
        if (!empty($features['menus'])) {
            $impact['menu_locations'] = $this->get_menu_locations();
        }

        // WooCommerce integration check
        if (!empty($features['woocommerce'])) {
            $impact['woocommerce_active'] = class_exists('WooCommerce');
        }

        // Calculate overall risk
        $impact['overall_risk'] = $this->calculate_risk($impact);
        $impact['recommendation'] = $this->generate_recommendation($impact, $slug);

        return $impact;
    }

    /**
     * Get a human-readable impact summary
     */
    public function get_impact_summary(array $impact): string {
        $parts = [];

        $total_shortcodes = array_sum(array_column($impact['shortcode_usage'], 'count'));
        if ($total_shortcodes > 0) {
            $parts[] = sprintf(
                '**%d posts/pages** use shortcodes from this plugin. Removing it will leave raw shortcode tags in content.',
                $total_shortcodes
            );
        }

        $total_cpts = array_sum(array_column($impact['cpt_content_count'], 'count'));
        if ($total_cpts > 0) {
            $parts[] = sprintf(
                '**%d %s** of custom post types will become inaccessible.',
                $total_cpts,
                _n('item', 'items', $total_cpts)
            );
        }

        $total_widgets = array_sum(array_column($impact['widget_usage'], 'count'));
        if ($total_widgets > 0) {
            $parts[] = sprintf(
                '**%d widget instance(s)** will disappear from sidebars.',
                $total_widgets
            );
        }

        $non_empty_options = count(array_filter($impact['option_values'], fn($o) => !empty($o['value'])));
        if ($non_empty_options > 0) {
            $parts[] = sprintf(
                '**%d configuration option(s)** will be lost.',
                $non_empty_options
            );
        }

        $total_db_rows = array_sum(array_column($impact['db_table_rows'], 'rows'));
        if ($total_db_rows > 0) {
            $parts[] = sprintf(
                '**%d database rows** in custom tables will be orphaned.',
                $total_db_rows
            );
        }

        $total_cron = count($impact['cron_scheduled']);
        if ($total_cron > 0) {
            $parts[] = sprintf(
                '**%d scheduled task(s)** will stop running.',
                $total_cron
            );
        }

        if ($impact['block_usage_count'] > 0) {
            $parts[] = sprintf(
                '**%d block instance(s)** in post content will show block errors.',
                $impact['block_usage_count']
            );
        }

        if ($impact['woocommerce_active'] && !empty($parts)) {
            $parts[] = 'This plugin integrates with **WooCommerce** — removing it may affect checkout/product functionality.';
        }

        if (empty($parts)) {
            $parts[] = 'No active site content appears to depend on this plugin. It should be safe to remove after confirming functionality manually.';
        }

        return implode("\n\n", $parts);
    }

    // ------------------------------------------------------------------
    // Impact counting methods
    // ------------------------------------------------------------------

    private function count_shortcode_usage(array $shortcodes): array {
        global $wpdb;
        $results = [];

        $codes = array_column($shortcodes, 'name');
        foreach ($codes as $code) {
            $like = '%[' . $wpdb->esc_like($code) . '%';
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status IN ('publish', 'draft', 'private', 'future')",
                $like
            ));
            $results[] = ['shortcode' => $code, 'count' => (int) $count];
        }
        return $results;
    }

    private function count_cpt_posts(array $cpts): array {
        $results = [];
        foreach ($cpts as $cpt) {
            $name = $cpt['name'];
            $count = wp_count_posts($name);
            $total = 0;
            if (!is_wp_error($count) && is_object($count)) {
                foreach ($count as $status => $num) {
                    $total += (int) $num;
                }
            }
            $results[] = [
                'post_type' => $name,
                'count'     => $total,
                'label'     => get_post_type_object($name)?->label ?? $name,
            ];
        }
        return $results;
    }

    private function count_widget_usage(array $widgets): array {
        $results = [];
        $sidebars = get_option('sidebars_widgets', []);

        foreach ($widgets as $widget) {
            $class = $widget['name'] ?? '';
            $count = 0;
            foreach ($sidebars as $sidebar_id => $widget_ids) {
                if (!is_array($widget_ids)) continue;
                foreach ($widget_ids as $widget_id) {
                    if (strpos($widget_id, strtolower($class)) === 0 || strpos($widget_id, $class) === 0) {
                        $count++;
                    }
                }
            }
            $results[] = ['widget' => $class, 'count' => $count];
        }
        return $results;
    }

    private function get_option_values(array $options): array {
        $results = [];
        foreach ($options as $opt) {
            $name = $opt['name'];
            $value = get_option($name, '__PHPCC_NOT_SET__');
            $results[] = [
                'name'      => $name,
                'has_value' => $value !== '__PHPCC_NOT_SET__',
                'is_empty'  => empty($value) || $value === '' || $value === [] || $value === false,
                'size_est'  => $this->estimate_size($value),
            ];
        }
        return $results;
    }

    private function count_table_rows(array $tables): array {
        global $wpdb;
        $results = [];

        foreach ($tables as $table) {
            $name = $table['table'];
            $full_name = $wpdb->prefix . ltrim($name, 'wp_');
            // Check if table exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $full_name
            ));
            if ($exists) {
                $count = $wpdb->get_var("SELECT COUNT(*) FROM `{$full_name}`");
                $results[] = ['table' => $full_name, 'rows' => (int) $count];
            } else {
                $results[] = ['table' => $full_name, 'rows' => 0];
            }
        }
        return $results;
    }

    private function check_cron_schedules(array $cron_jobs): array {
        $results = [];
        $crons = _get_cron_array();
        if (!is_array($crons)) return $results;

        foreach ($cron_jobs as $job) {
            $hook = $job['hook'];
            $found = false;
            foreach ($crons as $timestamp => $hooks) {
                if (isset($hooks[$hook])) {
                    $found = true;
                    break;
                }
            }
            $results[] = ['hook' => $hook, 'scheduled' => $found];
        }
        return $results;
    }

    private function count_block_usage(array $blocks): int {
        global $wpdb;
        $total = 0;
        foreach ($blocks as $block) {
            $name = $block['name'] ?? '';
            if (!$name) continue;
            // Check for wp: prefix or just the block name
            $search = 'wp:' . ltrim($name, '/');
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status IN ('publish', 'draft', 'future', 'private')",
                '%<!-- ' . $wpdb->esc_like($search) . ' %'
            ));
            $total += (int) $count;
        }
        return $total;
    }

    private function get_menu_locations(): array {
        $locations = get_registered_nav_menus();
        return array_keys($locations);
    }

    private function estimate_size($value): string {
        if ($value === '__PHPCC_NOT_SET__') return 'not set';
        $bytes = strlen(serialize($value));
        if ($bytes > 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes > 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' bytes';
    }

    private function calculate_risk(array $impact): string {
        $score = 0;

        $total_shortcodes = array_sum(array_column($impact['shortcode_usage'], 'count'));
        $score += min($total_shortcodes, 100);

        $total_cpts = array_sum(array_column($impact['cpt_content_count'], 'count'));
        $score += min($total_cpts * 5, 100);

        $total_widgets = array_sum(array_column($impact['widget_usage'], 'count'));
        $score += $total_widgets * 10;

        $non_empty_options = count(array_filter($impact['option_values'], fn($o) => !empty($o['has_value']) && empty($o['is_empty'])));
        $score += $non_empty_options * 5;

        $total_db = array_sum(array_column($impact['db_table_rows'], 'rows'));
        $score += min($total_db / 100, 50);

        $total_cron = count($impact['cron_scheduled']);
        $score += $total_cron * 5;

        $score += $impact['block_usage_count'];

        if ($impact['woocommerce_active']) {
            $score += 30;
        }

        if ($score >= 100) return 'critical';
        if ($score >= 50) return 'high';
        if ($score >= 20) return 'medium';
        return 'low';
    }

    private function generate_recommendation(array $impact, string $slug): string {
        $risk = $impact['overall_risk'];

        switch ($risk) {
            case 'critical':
                return 'This plugin is deeply integrated into your site. Do NOT remove without first migrating content, shortcodes, and settings to an alternative. Create a full backup before any changes.';
            case 'high':
                return 'Significant site content depends on this plugin. Plan a replacement strategy before removal. Test thoroughly on a staging site first.';
            case 'medium':
                return 'Some content or functionality depends on this plugin. Review the specific impact details below before deciding. Consider disabling first to test.';
            case 'low':
            default:
                return 'Low impact. Safe to remove after a quick manual check. No active content appears to depend on this plugin.';
        }
    }
}
