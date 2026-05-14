<?php
/**
 * Report Generator — Human-readable PHP 8 readiness reports
 *
 * Creates executive summaries, per-component reports, and exportable data.
 */

declare(strict_types=1);

class PHPCC_Report_Generator {

    /**
     * Build the executive summary for the entire site
     */
    public function build_executive_summary(array $results): array {
        $total = count($results);
        $critical = 0;
        $warning = 0;
        $info = 0;
        $ready = 0;
        $active_plugins = 0;
        $total_with_impact = 0;

        $risky_plugins = [];
        $ready_plugins = [];
        $needs_review = [];

        foreach ($results as $r) {
            $c = $r['issue_counts']['critical'] ?? 0;
            $w = $r['issue_counts']['warning'] ?? 0;
            $i = $r['issue_counts']['info'] ?? 0;

            if ($r['type'] === 'plugin' && $r['status'] === 'Active') {
                $active_plugins++;
            }

            if ($c > 0) {
                $critical += $c;
                $risky_plugins[] = [
                    'name'    => $r['name'],
                    'slug'    => $r['slug'],
                    'type'    => $r['type'],
                    'status'  => $r['status'],
                    'critical'=> $c,
                    'warning' => $w,
                    'impact'  => $r['impact']['overall_risk'] ?? 'unknown',
                    'reason'  => $this->get_critical_reason($r),
                ];
            } elseif ($w > 0) {
                $warning += $w;
                $needs_review[] = [
                    'name'    => $r['name'],
                    'slug'    => $r['slug'],
                    'warnings'=> $w,
                ];
            } elseif ($i > 0) {
                $info += $i;
            } else {
                $ready++;
                $ready_plugins[] = $r['name'];
            }

            if (!empty($r['impact']) && ($r['impact']['overall_risk'] ?? 'low') !== 'low') {
                $total_with_impact++;
            }
        }

        $overall_score = $total > 0 ? round(array_sum(array_column($results, 'readiness_score')) / $total) : 0;
        $overall_status = $this->score_to_status($overall_score, $critical);

        return [
            'overall_score'      => $overall_score,
            'overall_status'     => $overall_status,
            'status_label'       => $this->status_to_label($overall_status),
            'total_components'   => $total,
            'active_plugins'     => $active_plugins,
            'ready_count'        => $ready,
            'critical_count'     => count($risky_plugins),
            'warning_count'      => count($needs_review),
            'total_critical'     => $critical,
            'total_warnings'     => $warning,
            'total_info'         => $info,
            'risky_plugins'      => $risky_plugins,
            'needs_review'       => $needs_review,
            'ready_plugins'      => $ready_plugins,
            'plugins_with_impact'=> $total_with_impact,
        ];
    }

    /**
     * Build detailed report for a single component
     */
    public function build_component_report(array $result): array {
        $report = [
            'header'        => $this->build_component_header($result),
            'readiness'     => $this->build_readiness_section($result),
            'php8_issues'   => $this->build_php8_issues_section($result),
            'features'      => $this->build_features_section($result),
            'impact'        => $this->build_impact_section($result),
            'actions'       => $this->build_actions_section($result),
        ];
        return $report;
    }

    /**
     * Export results as a structured CSV for client documentation
     */
    public function export_csv(array $results, string $filename = ''): string {
        if (empty($filename)) {
            $filename = 'php8-readiness-' . date('Y-m-d') . '.csv';
        }

        $lines = [];
        $lines[] = implode(',', [
            'Component Name',
            'Type',
            'Status',
            'Version',
            'PHP 8 Readiness Score',
            'Readiness Status',
            'Critical Issues',
            'Warnings',
            'Info',
            'Max PHP Version',
            'Impact Risk',
            'Recommendation',
            'Key Features',
        ]);

        foreach ($results as $r) {
            $features = [];
            if (!empty($r['features'])) {
                foreach ($r['features'] as $f) {
                    $features[] = sprintf('%s (%d)', $f['label'], $f['count']);
                }
            }

            $impact_risk = $r['impact']['overall_risk'] ?? 'low';
            $recommendation = $r['impact']['recommendation'] ?? '';

            $lines[] = implode(',', [
                $this->csv_escape($r['name']),
                $r['type'],
                $r['status'],
                $r['version'],
                $r['readiness_score'] ?? 0,
                $this->csv_escape($r['readiness_label'] ?? 'Unknown'),
                $r['issue_counts']['critical'] ?? 0,
                $r['issue_counts']['warning'] ?? 0,
                $r['issue_counts']['info'] ?? 0,
                $r['php_max'] ?? 'unknown',
                $impact_risk,
                $this->csv_escape($recommendation),
                $this->csv_escape(implode('; ', $features)),
            ]);
        }

        return implode("\n", $lines);
    }

    /**
     * Generate HTML report card for a single component (for dashboard display)
     */
    public function render_component_card(array $result): string {
        $color = $this->readiness_color($result);
        $score = $result['readiness_score'] ?? 0;
        $critical = $result['issue_counts']['critical'] ?? 0;
        $warning = $result['issue_counts']['warning'] ?? 0;
        $has_impact = !empty($result['impact']) && ($result['impact']['overall_risk'] ?? 'low') !== 'low';

        $html = '<div class="phpcc-card phpcc-card-' . esc_attr($color) . '" data-slug="' . esc_attr($result['slug']) . '">';
        $html .= '<div class="phpcc-card-header">';
        $html .= '<h3>' . esc_html($result['name']) . '</h3>';
        $html .= '<span class="phpcc-card-score" style="background:' . esc_attr($this->score_hex_color($score)) . '">' . (int)$score . '</span>';
        $html .= '</div>';

        $html .= '<div class="phpcc-card-meta">';
        $html .= '<span class="phpcc-card-type">' . esc_html(ucfirst($result['type'])) . '</span>';
        $html .= '<span class="phpcc-card-status phpcc-card-status-' . ($result['status'] === 'Active' ? 'active' : 'inactive') . '">' . esc_html($result['status']) . '</span>';
        if ($has_impact) {
            $html .= '<span class="phpcc-card-impact phpcc-card-impact-' . esc_attr($result['impact']['overall_risk']) . '">Impact: ' . esc_html(ucfirst($result['impact']['overall_risk'])) . '</span>';
        }
        $html .= '</div>';

        $html .= '<div class="phpcc-card-issues">';
        if ($critical > 0) {
            $html .= '<span class="phpcc-card-issue-critical">' . sprintf(__('%d Critical', 'phpcc'), $critical) . '</span>';
        }
        if ($warning > 0) {
            $html .= '<span class="phpcc-card-issue-warning">' . sprintf(__('%d Warnings', 'phpcc'), $warning) . '</span>';
        }
        if ($critical === 0 && $warning === 0) {
            $html .= '<span class="phpcc-card-issue-clean">' . __('PHP 8 Ready', 'phpcc') . '</span>';
        }
        $html .= '</div>';

        // Feature summary mini
        if (!empty($result['features'])) {
            $feature_labels = array_slice(array_column($result['features'], 'label'), 0, 3);
            $html .= '<div class="phpcc-card-features">' . esc_html(implode(' • ', $feature_labels)) . '</div>';
        }

        $html .= '<button class="button phpcc-card-details-btn" data-slug="' . esc_attr($result['slug']) . '">' . __('View Details', 'phpcc') . '</button>';
        $html .= '</div>';

        return $html;
    }

    // ------------------------------------------------------------------
    // Report section builders
    // ------------------------------------------------------------------

    private function build_component_header(array $result): array {
        return [
            'name'    => $result['name'],
            'version' => $result['version'],
            'type'    => $result['type'],
            'status'  => $result['status'],
        ];
    }

    private function build_readiness_section(array $result): array {
        $score = $result['readiness_score'] ?? 0;
        $label = $result['readiness_label'] ?? 'Unknown';

        $verdict = '';
        if ($score >= 95) {
            $verdict = 'This plugin/theme appears compatible with PHP 8.0 and above. No blocking issues detected.';
        } elseif ($score >= 70) {
            $verdict = 'Mostly compatible with PHP 8.0+, but some warnings may cause minor issues or deprecation notices.';
        } elseif ($score >= 40) {
            $verdict = 'Has compatibility concerns. Some features may not work correctly on PHP 8.0+ and require updates.';
        } else {
            $verdict = 'NOT ready for PHP 8.0+. Critical errors will occur if PHP is upgraded. Do not proceed without updates or replacement.';
        }

        return [
            'score'   => $score,
            'label'   => $label,
            'verdict' => $verdict,
            'php_max' => $result['php_max'] ?? 'unknown',
        ];
    }

    private function build_php8_issues_section(array $result): array {
        $issues = $result['issues'] ?? [];
        $sections = [];

        if (!empty($issues['critical'])) {
            $sections['critical'] = [
                'title'       => 'Critical PHP 8 Issues',
                'description' => 'These will cause fatal errors or broken functionality on PHP 8.0+:',
                'items'       => array_slice($issues['critical'], 0, 20),
                'total'       => count($issues['critical']),
            ];
        }

        if (!empty($issues['warning'])) {
            $sections['warning'] = [
                'title'       => 'Warnings',
                'description' => 'Deprecated features that may stop working in future PHP versions:',
                'items'       => array_slice($issues['warning'], 0, 20),
                'total'       => count($issues['warning']),
            ];
        }

        if (!empty($issues['info'])) {
            $sections['info'] = [
                'title'       => 'Informational',
                'description' => 'Minor compatibility notes:',
                'items'       => array_slice($issues['info'], 0, 10),
                'total'       => count($issues['info']),
            ];
        }

        return $sections;
    }

    private function build_features_section(array $result): array {
        return $result['features'] ?? [];
    }

    private function build_impact_section(array $result): array {
        if (empty($result['impact'])) {
            return ['has_impact' => false, 'summary' => 'Impact analysis not available for inactive plugins.'];
        }

        $impact = $result['impact'];

        require_once PHPCC_PLUGIN_DIR . 'includes/class-impact-analyzer.php';
        $analyzer = new PHPCC_Impact_Analyzer();
        $summary = $analyzer->get_impact_summary($impact);

        return [
            'has_impact'      => true,
            'risk_level'      => $impact['overall_risk'] ?? 'low',
            'recommendation'  => $impact['recommendation'] ?? '',
            'summary_text'    => $summary,
            'details'         => $this->filter_impact_details($impact),
        ];
    }

    private function build_actions_section(array $result): array {
        $actions = [];
        $score = $result['readiness_score'] ?? 0;
        $impact_risk = $result['impact']['overall_risk'] ?? 'low';

        if ($score < 40) {
            $actions[] = [
                'priority' => 'urgent',
                'action'   => 'Do not upgrade PHP until this plugin is updated or replaced.',
            ];
        }

        if ($score >= 40 && $score < 95) {
            $actions[] = [
                'priority' => 'high',
                'action'   => 'Update this plugin to its latest version before upgrading PHP. Contact the developer if no update is available.',
            ];
        }

        if ($impact_risk === 'critical' || $impact_risk === 'high') {
            $actions[] = [
                'priority' => 'high',
                'action'   => 'Site content depends on this plugin. Plan migration/replacement strategy before removal.',
            ];
        }

        if ($score >= 95 && $impact_risk === 'low') {
            $actions[] = [
                'priority' => 'low',
                'action'   => 'Safe to use with PHP 8.0+. Monitor for deprecation notices after upgrade.',
            ];
        }

        if (empty($actions)) {
            $actions[] = [
                'priority' => 'low',
                'action'   => 'Review manually before upgrading. Test on a staging environment first.',
            ];
        }

        return $actions;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function score_to_status(int $score, int $critical_count): string {
        if ($critical_count > 0) return 'critical';
        if ($score < 70) return 'needs_attention';
        if ($score < 95) return 'caution';
        return 'ready';
    }

    private function status_to_label(string $status): string {
        return [
            'critical'        => 'Critical — Immediate Action Required',
            'needs_attention'   => 'Needs Attention Before Upgrade',
            'caution'         => 'Proceed with Caution',
            'ready'           => 'Ready for PHP 8',
        ][$status] ?? 'Unknown';
    }

    private function readiness_color(array $result): string {
        $score = $result['readiness_score'] ?? 0;
        $critical = $result['issue_counts']['critical'] ?? 0;
        if ($critical > 0) return 'critical';
        if ($score >= 95) return 'success';
        if ($score >= 70) return 'warning';
        return 'danger';
    }

    private function score_hex_color(int $score): string {
        if ($score >= 95) return '#00a32a';
        if ($score >= 70) return '#dba617';
        return '#d63638';
    }

    private function get_critical_reason(array $result): string {
        $issues = $result['issues']['critical'] ?? [];
        if (empty($issues)) return '';

        $sources = array_unique(array_column($issues, 'source'));
        $first = reset($sources);

        if (stripos($first, 'RemovedFunctions') !== false) {
            return 'Uses PHP functions removed in PHP 8.0+';
        }
        if (stripos($first, 'ArgumentFunctions') !== false) {
            return 'Deprecated variable argument functions incompatibility';
        }
        if (stripos($first, 'RemovedConstants') !== false) {
            return 'Uses constants removed in newer PHP';
        }
        if (stripos($first, 'Forbidden') !== false) {
            return 'Uses forbidden PHP constructs';
        }

        return 'Critical PHP compatibility issues found';
    }

    private function filter_impact_details(array $impact): array {
        $details = [];

        $shortcode = array_sum(array_column($impact['shortcode_usage'] ?? [], 'count'));
        if ($shortcode > 0) $details['shortcode_posts'] = $shortcode;

        $cpt = array_sum(array_column($impact['cpt_content_count'] ?? [], 'count'));
        if ($cpt > 0) $details['cpt_items'] = $cpt;

        $widgets = array_sum(array_column($impact['widget_usage'] ?? [], 'count'));
        if ($widgets > 0) $details['widget_instances'] = $widgets;

        $options = count(array_filter($impact['option_values'] ?? [], function($o) { return !empty($o['has_value']); }));
        if ($options > 0) $details['config_options'] = $options;

        $db = array_sum(array_column($impact['db_table_rows'] ?? [], 'rows'));
        if ($db > 0) $details['db_rows'] = $db;

        $cron = count($impact['cron_scheduled'] ?? []);
        if ($cron > 0) $details['cron_jobs'] = $cron;

        if ($impact['block_usage_count'] ?? 0 > 0) {
            $details['block_instances'] = $impact['block_usage_count'];
        }

        return $details;
    }

    private function csv_escape(string $text): string {
        if (strpos($text, ',') !== false || strpos($text, '"') !== false || strpos($text, "\n") !== false) {
            return '"' . str_replace('"', '""', $text) . '"';
        }
        return $text;
    }
}
