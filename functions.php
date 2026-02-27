<?php
/**
 * ACASA Child theme functions
 * Managed via Git + Codex.
 */

add_action('wp_enqueue_scripts', function () {
    // Always load the child theme stylesheet.
    wp_enqueue_style(
        'acasa-child-style',
        get_stylesheet_uri(),
        [],
        wp_get_theme()->get('Version')
    );
}, 20);

/* ============================================================================
   ACASA Branding Tool (Option B: DB seeder + snapshot + rollback)
   - Tools → ACASA Branding
   - Dry run, Apply (safe), Apply (force), Snapshot, Rollback
============================================================================ */

add_action('admin_menu', function () {
    add_management_page(
        'ACASA Branding',
        'ACASA Branding',
        'manage_options',
        'acasa-branding',
        'acasa_render_branding_tools_page'
    );
});

/**
 * Define the desired branding state.
 *
 * IMPORTANT:
 * - Leave this empty for now; we will populate it from your branding manual
 *   and/or by extracting tuned settings from your reference site.
 * - Structure:
 *   [
 *     'options' => [
 *        'generate_settings' => [ 'some_key' => 'value', ... ],
 *        // you can add other option arrays here
 *     ],
 *     'theme_mods' => [
 *        'custom_logo' => 123, // attachment ID
 *        // etc...
 *     ],
 *   ]
 *
 * NOTE: GeneratePress stores many settings in the 'generate_settings' option.
 */
function acasa_branding_target_map(): array {
    return [
        'options' => [
            // 'generate_settings' => [
            //     'container_width' => '1200',
            //     'link_color'      => '#fcd602',
            // ],
        ],
        'theme_mods' => [
            // 'custom_logo' => 123,
        ],
    ];
}

/**
 * Returns a snapshot of the current state for keys we care about.
 * This does NOT crawl the entire DB; it reads only relevant options + theme_mods.
 */
function acasa_capture_current_state(array $target_map): array {
    $current = [
        'captured_at' => current_time('mysql'),
        'site_url'    => site_url(),
        'theme'       => wp_get_theme()->get('Name'),
        'template'    => wp_get_theme()->get_template(),
        'stylesheet'  => wp_get_theme()->get_stylesheet(),
        'options'     => [],
        'theme_mods'  => [],
    ];

    // Options
    foreach (($target_map['options'] ?? []) as $option_name => $desired_pairs) {
        $opt = get_option($option_name, null);

        // We only record the keys that exist in the desired set.
        // If the option itself is not an array, we store it raw.
        if (is_array($opt) && is_array($desired_pairs)) {
            $subset = [];
            foreach ($desired_pairs as $k => $_v) {
                $subset[$k] = $opt[$k] ?? null;
            }
            $current['options'][$option_name] = $subset;
        } else {
            $current['options'][$option_name] = $opt;
        }
    }

    // Theme mods
    foreach (($target_map['theme_mods'] ?? []) as $mod_name => $_desired) {
        $current['theme_mods'][$mod_name] = get_theme_mod($mod_name, null);
    }

    return $current;
}

/**
 * Computes a change plan: what differs between current and desired.
 */
function acasa_compute_plan(array $target_map): array {
    $plan = [
        'options'    => [], // [option_name][key] => ['from'=>..., 'to'=>...]
        'theme_mods' => [], // [mod_name] => ['from'=>..., 'to'=>...]
        'counts'     => ['options' => 0, 'theme_mods' => 0],
    ];

    // Options (array options like generate_settings)
    foreach (($target_map['options'] ?? []) as $option_name => $desired_pairs) {
        $current_opt = get_option($option_name, null);

        if (!is_array($desired_pairs)) {
            // Non-array desired value (rare): compare whole option value
            $from = $current_opt;
            $to   = $desired_pairs;
            if ($from !== $to) {
                $plan['options'][$option_name]['__whole__'] = ['from' => $from, 'to' => $to];
                $plan['counts']['options']++;
            }
            continue;
        }

        if (!is_array($current_opt)) {
            $current_opt = [];
        }

        foreach ($desired_pairs as $k => $to) {
            $from = $current_opt[$k] ?? null;
            if ($from !== $to) {
                if (!isset($plan['options'][$option_name])) {
                    $plan['options'][$option_name] = [];
                }
                $plan['options'][$option_name][$k] = ['from' => $from, 'to' => $to];
                $plan['counts']['options']++;
            }
        }
    }

    // Theme mods
    foreach (($target_map['theme_mods'] ?? []) as $mod_name => $to) {
        $from = get_theme_mod($mod_name, null);
        if ($from !== $to) {
            $plan['theme_mods'][$mod_name] = ['from' => $from, 'to' => $to];
            $plan['counts']['theme_mods']++;
        }
    }

    return $plan;
}

/**
 * Applies the desired branding map.
 *
 * $force=false: only write values that differ (idempotent).
 * $force=true : write all targeted keys regardless.
 */
function acasa_apply_branding_seed(bool $force = false): array {
    $target = acasa_branding_target_map();

    // Compute plan first, so we can report what changed.
    $plan = acasa_compute_plan($target);

    // Apply options
    foreach (($target['options'] ?? []) as $option_name => $desired_pairs) {
        if (!is_array($desired_pairs)) {
            $current_opt = get_option($option_name, null);
            if ($force || $current_opt !== $desired_pairs) {
                update_option($option_name, $desired_pairs);
            }
            continue;
        }

        $current_opt = get_option($option_name, []);
        if (!is_array($current_opt)) {
            $current_opt = [];
        }

        $dirty = false;
        foreach ($desired_pairs as $k => $to) {
            $from = $current_opt[$k] ?? null;
            if ($force || $from !== $to) {
                $current_opt[$k] = $to;
                $dirty = true;
            }
        }

        if ($dirty) {
            update_option($option_name, $current_opt);
        }
    }

    // Apply theme mods
    foreach (($target['theme_mods'] ?? []) as $mod_name => $to) {
        $from = get_theme_mod($mod_name, null);
        if ($force || $from !== $to) {
            set_theme_mod($mod_name, $to);
        }
    }

    // Version marker (useful later when you evolve your branding baseline)
    update_option('acasa_branding_seed_version', 1);

    return $plan;
}

/**
 * Stores a snapshot (of current targeted keys) for rollback.
 */
function acasa_store_snapshot(): void {
    $target   = acasa_branding_target_map();
    $snapshot = acasa_capture_current_state($target);
    update_option('acasa_branding_snapshot', wp_json_encode($snapshot, JSON_PRETTY_PRINT));
    update_option('acasa_branding_snapshot_at', current_time('mysql'));
}

/**
 * Restores from the last snapshot, if present.
 */
function acasa_rollback_snapshot(): bool {
    $raw = get_option('acasa_branding_snapshot', '');
    if (!is_string($raw) || $raw === '') {
        return false;
    }

    $snapshot = json_decode($raw, true);
    if (!is_array($snapshot)) {
        return false;
    }

    // Restore options
    if (!empty($snapshot['options']) && is_array($snapshot['options'])) {
        foreach ($snapshot['options'] as $option_name => $value) {
            update_option($option_name, $value);
        }
    }

    // Restore theme mods
    if (!empty($snapshot['theme_mods']) && is_array($snapshot['theme_mods'])) {
        foreach ($snapshot['theme_mods'] as $mod_name => $value) {
            set_theme_mod($mod_name, $value);
        }
    }

    return true;
}

function acasa_render_branding_tools_page(): void {
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions.');
    }

    $target = acasa_branding_target_map();
    $plan   = acasa_compute_plan($target);

    $notice = '';
    $error  = '';

    // Handle actions
    if (isset($_POST['acasa_branding_action'])) {
        check_admin_referer('acasa_branding_tools');

        $action = sanitize_text_field($_POST['acasa_branding_action']);

        try {
            if ($action === 'snapshot') {
                acasa_store_snapshot();
                $notice = 'Snapshot stored. You can rollback to it later.';
            } elseif ($action === 'rollback') {
                $ok = acasa_rollback_snapshot();
                $notice = $ok ? 'Rollback completed.' : 'No valid snapshot found to rollback.';
            } elseif ($action === 'apply_safe') {
                acasa_store_snapshot(); // automatic safety net
                $applied_plan = acasa_apply_branding_seed(false);
                $notice = 'Branding applied (safe). Snapshot was stored before applying. '
                        . 'Changes planned: options ' . intval($applied_plan['counts']['options'])
                        . ', theme mods ' . intval($applied_plan['counts']['theme_mods']) . '.';
            } elseif ($action === 'apply_force') {
                acasa_store_snapshot(); // automatic safety net
                $applied_plan = acasa_apply_branding_seed(true);
                $notice = 'Branding applied (force). Snapshot was stored before applying. '
                        . 'Changes targeted: options ' . intval($applied_plan['counts']['options'])
                        . ', theme mods ' . intval($applied_plan['counts']['theme_mods']) . '.';
            }
        } catch (Throwable $t) {
            $error = 'Error: ' . $t->getMessage();
        }

        // Recompute plan after any action
        $plan = acasa_compute_plan($target);
    }

    $seed_version = intval(get_option('acasa_branding_seed_version', 0));
    $snap_at      = get_option('acasa_branding_snapshot_at', '');

    echo '<div class="wrap">';
    echo '<h1>ACASA Branding</h1>';

    if ($notice) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notice) . '</p></div>';
    }
    if ($error) {
        echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
    }

    echo '<p><strong>Theme:</strong> ' . esc_html(wp_get_theme()->get('Name')) . ' | ';
    echo '<strong>Template:</strong> ' . esc_html(wp_get_theme()->get_template()) . ' | ';
    echo '<strong>Stylesheet:</strong> ' . esc_html(wp_get_theme()->get_stylesheet()) . '</p>';

    echo '<p><strong>Seed version in DB:</strong> ' . esc_html((string)$seed_version) . '<br>';
    echo '<strong>Last snapshot at:</strong> ' . esc_html(is_string($snap_at) ? $snap_at : '') . '</p>';

    // Action buttons
    echo '<form method="post" style="margin: 12px 0;">';
    wp_nonce_field('acasa_branding_tools');

    echo '<button class="button" name="acasa_branding_action" value="snapshot" type="submit">Create snapshot</button> ';
    echo '<button class="button" name="acasa_branding_action" value="rollback" type="submit" ';
    echo 'onclick="return confirm(\'Rollback will restore the last snapshot. Continue?\')">Rollback to snapshot</button> ';

    echo '<button class="button button-primary" name="acasa_branding_action" value="apply_safe" type="submit" ';
    echo 'onclick="return confirm(\'This will store a snapshot, then apply branding changes (safe). Continue?\')">Apply branding (safe)</button> ';

    echo '<button class="button button-secondary" name="acasa_branding_action" value="apply_force" type="submit" ';
    echo 'style="margin-left:8px" ';
    echo 'onclick="return confirm(\'DANGER: This will store a snapshot, then overwrite targeted keys (force). Continue?\')">Apply branding (force)</button>';

    echo '</form>';

    echo '<hr>';

    echo '<h2>Dry run</h2>';
    echo '<p>This shows the differences between current settings and the desired branding map. '
       . 'Right now, if the branding map is empty, this will show zero changes.</p>';

    echo '<p><strong>Planned changes:</strong> Options: ' . intval($plan['counts']['options'])
       . ' | Theme mods: ' . intval($plan['counts']['theme_mods']) . '</p>';

    // Render plan table
    echo '<table class="widefat striped" style="max-width:1200px">';
    echo '<thead><tr><th>Type</th><th>Name</th><th>Key</th><th>From</th><th>To</th></tr></thead><tbody>';

    $rows = 0;

    foreach ($plan['options'] as $option_name => $pairs) {
        foreach ($pairs as $k => $delta) {
            $rows++;
            echo '<tr>';
            echo '<td>Option</td>';
            echo '<td>' . esc_html($option_name) . '</td>';
            echo '<td>' . esc_html((string)$k) . '</td>';
            echo '<td><code>' . esc_html(wp_json_encode($delta['from'])) . '</code></td>';
            echo '<td><code>' . esc_html(wp_json_encode($delta['to'])) . '</code></td>';
            echo '</tr>';
        }
    }

    foreach ($plan['theme_mods'] as $mod_name => $delta) {
        $rows++;
        echo '<tr>';
        echo '<td>Theme mod</td>';
        echo '<td>' . esc_html($mod_name) . '</td>';
        echo '<td>—</td>';
        echo '<td><code>' . esc_html(wp_json_encode($delta['from'])) . '</code></td>';
        echo '<td><code>' . esc_html(wp_json_encode($delta['to'])) . '</code></td>';
        echo '</tr>';
    }

    if ($rows === 0) {
        echo '<tr><td colspan="5"><em>No differences detected.</em></td></tr>';
    }

    echo '</tbody></table>';

    echo '<p style="margin-top:12px"><em>Note:</em> After applying branding on a cached site, you may need to purge page/CSS caches (Autoptimize, Cloudflare, server cache) to see changes immediately.</p>';

    echo '</div>';
}