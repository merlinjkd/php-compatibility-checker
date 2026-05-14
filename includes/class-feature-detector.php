<?php
/**
 * Feature Detector — Analyzes what functionality each plugin/theme provides
 *
 * Scans code to identify: shortcodes, CPTs, taxonomies, widgets, blocks,
 * admin menus, REST endpoints, cron jobs, WooCommerce integrations,
 * custom DB tables, options, filters/hooks, and capabilities.
 */

declare(strict_types=1);

class PHPCC_Feature_Detector {

    /**
     * Analyze a plugin or theme directory and extract all functionality signatures
     */
    public function analyze_component(string $path, string $type, string $slug, array $info): array {
        $features = [
            'shortcodes'      => [],
            'custom_post_types'=> [],
            'taxonomies'      => [],
            'widgets'         => [],
            'blocks'          => [],
            'admin_pages'     => [],
            'rest_endpoints'  => [],
            'cron_jobs'       => [],
            'options'         => [],
            'db_tables'       => [],
            'capabilities'    => [],
            'woocommerce'     => [],
            'hooks'           => ['actions' => [], 'filters' => []],
            'meta_boxes'      => [],
            'rewrite_rules'   => [],
            'menus'           => [],
            'enqueue_scripts' => [],
            'enqueue_styles'  => [],
        ];

        if (!is_dir($path)) {
            return $features;
        }

        $files = $this->get_php_files($path);

        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content === false) continue;

            $rel_path = str_replace(trailingslashit($path), '', $file);

            $features['shortcodes']      = array_merge($features['shortcodes'],      $this->extract_shortcodes($content, $rel_path));
            $features['custom_post_types']= array_merge($features['custom_post_types'],$this->extract_cpts($content, $rel_path));
            $features['taxonomies']      = array_merge($features['taxonomies'],      $this->extract_taxonomies($content, $rel_path));
            $features['widgets']         = array_merge($features['widgets'],         $this->extract_widgets($content, $rel_path));
            $features['blocks']          = array_merge($features['blocks'],          $this->extract_blocks($content, $rel_path));
            $features['admin_pages']     = array_merge($features['admin_pages'],     $this->extract_admin_pages($content, $rel_path));
            $features['rest_endpoints']  = array_merge($features['rest_endpoints'],  $this->extract_rest_endpoints($content, $rel_path));
            $features['cron_jobs']       = array_merge($features['cron_jobs'],       $this->extract_cron_jobs($content, $rel_path));
            $features['options']         = array_merge($features['options'],         $this->extract_options($content, $rel_path));
            $features['db_tables']       = array_merge($features['db_tables'],       $this->extract_db_tables($content, $rel_path));
            $features['capabilities']    = array_merge($features['capabilities'],    $this->extract_capabilities($content, $rel_path));
            $features['woocommerce']     = array_merge($features['woocommerce'],     $this->extract_woocommerce($content, $rel_path));
            $features['hooks']['actions']= array_merge($features['hooks']['actions'], $this->extract_hooks($content, 'add_action', $rel_path));
            $features['hooks']['filters']= array_merge($features['hooks']['filters'], $this->extract_hooks($content, 'add_filter', $rel_path));
            $features['meta_boxes']      = array_merge($features['meta_boxes'],      $this->extract_meta_boxes($content, $rel_path));
            $features['rewrite_rules']   = array_merge($features['rewrite_rules'],   $this->extract_rewrite_rules($content, $rel_path));
            $features['menus']           = array_merge($features['menus'],           $this->extract_menus($content, $rel_path));
            $features['enqueue_scripts'] = array_merge($features['enqueue_scripts'], $this->extract_enqueues($content, 'wp_enqueue_script', $rel_path));
            $features['enqueue_styles']  = array_merge($features['enqueue_styles'],  $this->extract_enqueues($content, 'wp_enqueue_style', $rel_path));
        }

        // Deduplicate by name
        foreach ($features as $key => &$list) {
            if ($key === 'hooks') continue;
            $list = $this->unique_by_name($list);
        }
        $features['hooks']['actions'] = $this->unique_by_name($features['hooks']['actions']);
        $features['hooks']['filters'] = $this->unique_by_name($features['hooks']['filters']);

        return $features;
    }

    /**
     * Get a human-readable summary of what a component provides
     */
    public function summarize_features(array $features): array {
        $summary = [];

        if (!empty($features['shortcodes'])) {
            $names = array_column($features['shortcodes'], 'name');
            $summary[] = [
                'type'    => 'shortcodes',
                'label'   => 'Shortcodes',
                'count'   => count($features['shortcodes']),
                'items'   => $names,
                'description' => sprintf('Provides %d shortcode(s): %s', count($names), implode(', ', array_slice($names, 0, 5)) . (count($names) > 5 ? '...' : '')),
            ];
        }

        if (!empty($features['custom_post_types'])) {
            $names = array_column($features['custom_post_types'], 'name');
            $summary[] = [
                'type'    => 'custom_post_types',
                'label'   => 'Custom Post Types',
                'count'   => count($features['custom_post_types']),
                'items'   => $names,
                'description' => sprintf('Registers %d custom post type(s): %s', count($names), implode(', ', $names)),
            ];
        }

        if (!empty($features['taxonomies'])) {
            $names = array_column($features['taxonomies'], 'name');
            $summary[] = [
                'type'    => 'taxonomies',
                'label'   => 'Custom Taxonomies',
                'count'   => count($features['taxonomies']),
                'items'   => $names,
                'description' => sprintf('Registers %d taxonomy(ies): %s', count($names), implode(', ', $names)),
            ];
        }

        if (!empty($features['widgets'])) {
            $names = array_column($features['widgets'], 'name');
            $summary[] = [
                'type'    => 'widgets',
                'label'   => 'Widgets',
                'count'   => count($features['widgets']),
                'items'   => $names,
                'description' => sprintf('Registers %d widget type(s): %s', count($names), implode(', ', $names)),
            ];
        }

        if (!empty($features['blocks'])) {
            $names = array_column($features['blocks'], 'name');
            $summary[] = [
                'type'    => 'blocks',
                'label'   => 'Gutenberg Blocks',
                'count'   => count($features['blocks']),
                'items'   => $names,
                'description' => sprintf('Registers %d block type(s)', count($names)),
            ];
        }

        if (!empty($features['admin_pages'])) {
            $names = array_column($features['admin_pages'], 'title');
            $summary[] = [
                'type'    => 'admin_pages',
                'label'   => 'Admin Pages',
                'count'   => count($features['admin_pages']),
                'items'   => $names,
                'description' => sprintf('Adds %d admin menu page(s): %s', count($names), implode(', ', array_slice($names, 0, 3)) . (count($names) > 3 ? '...' : '')),
            ];
        }

        if (!empty($features['rest_endpoints'])) {
            $names = array_column($features['rest_endpoints'], 'route');
            $summary[] = [
                'type'    => 'rest_endpoints',
                'label'   => 'REST API Endpoints',
                'count'   => count($features['rest_endpoints']),
                'items'   => $names,
                'description' => sprintf('Registers %d REST endpoint(s): %s', count($names), implode(', ', array_slice($names, 0, 3)) . (count($names) > 3 ? '...' : '')),
            ];
        }

        if (!empty($features['woocommerce'])) {
            $summary[] = [
                'type'    => 'woocommerce',
                'label'   => 'WooCommerce Integration',
                'count'   => count($features['woocommerce']),
                'items'   => [],
                'description' => 'Integrates with WooCommerce (product types, shipping, payments, or checkout)',
            ];
        }

        if (!empty($features['cron_jobs'])) {
            $names = array_column($features['cron_jobs'], 'hook');
            $summary[] = [
                'type'    => 'cron_jobs',
                'label'   => 'Scheduled Tasks',
                'count'   => count($features['cron_jobs']),
                'items'   => $names,
                'description' => sprintf('Schedules %d cron job(s): %s', count($names), implode(', ', array_slice($names, 0, 3)) . (count($names) > 3 ? '...' : '')),
            ];
        }

        if (!empty($features['db_tables'])) {
            $names = array_column($features['db_tables'], 'table');
            $summary[] = [
                'type'    => 'db_tables',
                'label'   => 'Custom Database Tables',
                'count'   => count($features['db_tables']),
                'items'   => $names,
                'description' => sprintf('Creates %d custom DB table(s): %s', count($names), implode(', ', $names)),
            ];
        }

        if (!empty($features['options'])) {
            $names = array_column($features['options'], 'name');
            $summary[] = [
                'type'    => 'options',
                'label'   => 'Settings/Options',
                'count'   => count($features['options']),
                'items'   => $names,
                'description' => sprintf('Uses %d option(s): %s', count($names), implode(', ', array_slice($names, 0, 5)) . (count($names) > 5 ? '...' : '')),
            ];
        }

        $total_hooks = count($features['hooks']['actions']) + count($features['hooks']['filters']);
        if ($total_hooks > 0) {
            $summary[] = [
                'type'    => 'hooks',
                'label'   => 'WordPress Hooks',
                'count'   => $total_hooks,
                'items'   => [],
                'description' => sprintf('Attaches to %d WordPress hooks (%d actions, %d filters)', $total_hooks, count($features['hooks']['actions']), count($features['hooks']['filters'])),
            ];
        }

        return $summary;
    }

    // ------------------------------------------------------------------
    // Extraction helpers
    // ------------------------------------------------------------------

    private function get_php_files(string $path): array {
        $files = [];
        if (!is_dir($path)) {
            return $files;
        }
        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        } catch (Throwable $e) {
            // Directory couldn't be iterated — skip silently
        }
        return $files;
    }

    private function extract_shortcodes(string $content, string $file): array {
        $found = [];
        if (preg_match_all('/add_shortcode\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
            foreach ($m[1] as $name) {
                $found[] = ['name' => $name, 'file' => $file];
            }
        }
        return $found;
    }

    private function extract_cpts(string $content, string $file): array {
        $found = [];
        if (preg_match_all('/register_post_type\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
            foreach ($m[1] as $name) {
                $found[] = ['name' => $name, 'file' => $file];
            }
        }
        return $found;
    }

    private function extract_taxonomies(string $content, string $file): array {
        $found = [];
        if (preg_match_all('/register_taxonomy\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
            foreach ($m[1] as $name) {
                $found[] = ['name' => $name, 'file' => $file];
            }
        }
        return $found;
    }

    private function extract_widgets(string $content, string $file): array {
        $found = [];
        if (preg_match_all('/register_widget\s*\(\s*[\'"]?([^\'"\(\),]+)[\'"]?\s*\)/', $content, $m)) {
            foreach ($m[1] as $name) {
                $found[] = ['name' => trim($name), 'file' => $file];
            }
        }
        if (preg_match_all('/class\s+(\w+)\s+extends\s+WP_Widget/', $content, $m)) {
            foreach ($m[1] as $name) {
                $found[] = ['name' => $name, 'file' => $file];
            }
        }
        return $found;
    }

    private function extract_blocks(string $content, string $file): array {
        $found = [];
        if (preg_match_all('/register_block_type\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
            foreach ($m[1] as $name) {
                $found[] = ['name' => $name, 'file' => $file];
            }
        }
        if (preg_match_all('/registerBlockType\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
            foreach ($m[1] as $name) {
                $found[] = ['name' => $name, 'file' => $file];
            }
        }
        return $found;
    }

    private function extract_admin_pages(string $content, string $file): array {
        $found = [];
        $funcs = ['add_menu_page', 'add_submenu_page', 'add_options_page', 'add_management_page'];
        foreach ($funcs as $func) {
            if (preg_match_all('/' . $func . '\s*\([^\)]*\)/', $content, $m)) {
                foreach ($m[0] as $call) {
                    $title = $this->extract_nth_arg($call, 1) ?: $this->extract_nth_arg($call, 0) ?: 'Untitled';
                    $found[] = ['title' => $title, 'file' => $file];
                }
            }
        }
        return $found;
    }

    private function extract_rest_endpoints(string $content, string $file): array {
        $found = [];
        if (preg_match_all('/register_rest_route\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
            foreach ($m[1] as $ns) {
                $found[] = ['route' => $ns, 'file' => $file];
            }
        }
        return $found;
    }

    private function extract_cron_jobs(string $content, string $file): array {
        $found = [];
        if (preg_match_all('/wp_schedule_event\s*\([^\)]*\)/', $content, $m)) {
            foreach ($m[0] as $call) {
                $hook = $this->extract_nth_arg($call, 2) ?: 'unknown';
                $found[] = ['hook' => $hook, 'file' => $file];
            }
        }
        return $found;
    }

    private function extract_options(string $content, string $file): array {
        $found = [];
        $funcs = ['add_option', 'update_option', 'get_option', 'delete_option'];
        foreach ($funcs as $func) {
            if (preg_match_all('/' . $func . '\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
                foreach ($m[1] as $name) {
                    $found[] = ['name' => $name, 'file' => $file];
                }
            }
        }
        return $found;
    }

    private function extract_db_tables(string $content, string $file): array {
        $found = [];
        if (preg_match_all('/\$wpdb->prefix\s*\.\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
            foreach ($m[1] as $name) {
                $found[] = ['table' => 'wp_' . $name, 'file' => $file];
            }
        }
        if (preg_match_all('/CREATE\s+TABLE\s+[`\']?(?:\{\$wpdb->prefix\}|wp_)?([^`\'\s(]+)/i', $content, $m)) {
            foreach ($m[1] as $name) {
                $found[] = ['table' => 'wp_' . $name, 'file' => $file];
            }
        }
        return $found;
    }

    private function extract_capabilities(string $content, string $file): array {
        $found = [];
        if (preg_match_all('/add_cap\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
            foreach ($m[1] as $cap) {
                $found[] = ['name' => $cap, 'file' => $file];
            }
        }
        if (preg_match_all('/manage_[a-z_]+/', $content, $m)) {
            foreach (array_unique($m[0]) as $cap) {
                $found[] = ['name' => $cap, 'file' => $file];
            }
        }
        return $found;
    }

    private function extract_woocommerce(string $content, string $file): array {
        $found = [];
        $markers = [
            'product_type', 'product_types', 'shipping_method',
            'payment_gateways', 'checkout_field', 'wc_',
            'WooCommerce', 'woocommerce'
        ];
        foreach ($markers as $marker) {
            if (stripos($content, $marker) !== false) {
                $found[] = ['type' => 'integration', 'file' => $file];
                break;
            }
        }
        return $found;
    }

    private function extract_hooks(string $content, string $func, string $file): array {
        $found = [];
        if (preg_match_all('/' . $func . '\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
            foreach ($m[1] as $hook) {
                $found[] = ['name' => $hook, 'file' => $file];
            }
        }
        return $found;
    }

    private function extract_meta_boxes(string $content, string $file): array {
        $found = [];
        if (preg_match_all('/add_meta_box\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
            foreach ($m[1] as $id) {
                $found[] = ['id' => $id, 'file' => $file];
            }
        }
        return $found;
    }

    private function extract_rewrite_rules(string $content, string $file): array {
        $found = [];
        if (preg_match_all('/add_rewrite_rule\s*\(/', $content, $m)) {
            $found[] = ['has_rules' => true, 'file' => $file];
        }
        return $found;
    }

    private function extract_menus(string $content, string $file): array {
        $found = [];
        if (preg_match_all('/register_nav_menu\s*\(/', $content, $m)) {
            $found[] = ['has_menus' => true, 'file' => $file];
        }
        return $found;
    }

    private function extract_enqueues(string $content, string $func, string $file): array {
        $found = [];
        if (preg_match_all('/' . $func . '\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
            foreach ($m[1] as $handle) {
                $found[] = ['handle' => $handle, 'file' => $file];
            }
        }
        return $found;
    }

    private function extract_nth_arg(string $call, int $n): ?string {
        preg_match_all('/[\'"]([^\'"]*+)[\'"]/', $call, $m);
        return $m[1][$n] ?? null;
    }

    private function unique_by_name(array $items): array {
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            $name = $item['name'] ?? ($item['title'] ?? ($item['route'] ?? ($item['hook'] ?? ($item['table'] ?? ($item['id'] ?? ($item['handle'] ?? ''))))));
            $key = strtolower($name);
            if ($key && !isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $item;
            }
        }
        return $out;
    }
}
