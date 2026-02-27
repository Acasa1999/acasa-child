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
            'generate_settings' => [
                // Palette
                'global_colors' => [
                    [ 'name' => 'contrast',   'slug' => 'contrast',   'color' => '#101014' ],
                    [ 'name' => 'contrast-2', 'slug' => 'contrast-2', 'color' => '#101014' ],
                    [ 'name' => 'contrast-3', 'slug' => 'contrast-3', 'color' => '#101014' ],
                    [ 'name' => 'base',       'slug' => 'base',       'color' => 'rgba(252,215,3,0.11)' ],
                    [ 'name' => 'base-2',     'slug' => 'base-2',     'color' => '#FFFBE8' ],
                    [ 'name' => 'base-3',     'slug' => 'base-3',     'color' => '#ffffff' ],
                    [ 'name' => 'accent',     'slug' => 'accent',     'color' => '#FCD602' ],
                    [ 'name' => 'accent-2',   'slug' => 'accent-2',   'color' => '#EDF5DA' ],
                    [ 'name' => 'accent-3',   'slug' => 'accent-3',   'color' => '#fcd602' ],
                ],

                // Colors
                'background_color'                  => 'var(--base-3)',
                'header_background_color'           => 'var(--base-3)',
                'content_background_color'          => 'var(--base-3)',
                'content_text_color'                => 'var(--contrast)',
                'content_title_color'               => 'var(--contrast)',

                'link_color'                        => 'var(--contrast)',
                'link_color_hover'                  => 'var(--contrast-3)',
                'content_link_color'                => 'var(--accent)',
                'content_link_hover_color'          => 'var(--accent-3)',

                'navigation_text_color'             => 'var(--contrast)',
                'navigation_text_hover_color'       => 'var(--accent-3)',
                'navigation_text_current_color'     => 'var(--contrast)',

                'subnavigation_background_color'            => 'var(--base-2)',
                'subnavigation_background_hover_color'      => 'var(--accent-3)',
                'subnavigation_background_current_color'    => 'var(--accent-3)',
                'subnavigation_text_color'                  => 'var(--contrast)',
                'subnavigation_text_hover_color'            => 'var(--contrast)',
                'subnavigation_text_current_color'          => 'var(--contrast)',

                'slideout_background_color'          => 'var(--base-2)',
                'slideout_background_hover_color'    => 'var(--base-2)',
                'slideout_background_current_color'  => 'var(--base-2)',
                'slideout_text_color'                => 'var(--contrast)',
                'slideout_text_hover_color'          => 'var(--accent-3)',
                'slideout_text_current_color'        => 'var(--accent)',

                'slideout_submenu_background_color'         => 'var(--base-2)',
                'slideout_submenu_background_hover_color'   => 'var(--base-2)',
                'slideout_submenu_background_current_color' => 'var(--base-2)',
                'slideout_submenu_text_color'               => 'var(--accent)',
                'slideout_submenu_text_hover_color'         => 'var(--accent-3)',
                'slideout_submenu_text_current_color'       => 'var(--accent)',

                'search_modal_bg_color'              => 'var(--base-2)',

                // Buttons
                'form_button_background_color'       => 'var(--accent)',
                'form_button_background_color_hover' => '#E8C400',
                'form_button_text_color'             => 'var(--base-3)',
                'form_button_text_color_hover'       => 'var(--base-3)',

                // Headings
                'h1_color' => 'var(--contrast)',
                'h2_color' => 'var(--contrast)',
                'h3_color' => 'var(--contrast)',
                'h4_color' => 'var(--contrast)',
                'h5_color' => 'var(--contrast)',
                'h6_color' => 'var(--contrast)',

                // Typography
                'typography' => [
                    [
                        'selector' => 'body',
                        'customSelector' => '',
                        'fontFamily' => 'var(--gp-font--inter)',
                        'fontWeight' => '',
                        'textTransform' => '',
                        'textDecoration' => '',
                        'fontStyle' => '',
                        'fontSize' => '1.125rem',
                        'fontSizeTablet' => '',
                        'fontSizeMobile' => '',
                        'fontSizeUnit' => '',
                        'lineHeight' => '1.5em',
                        'lineHeightTablet' => '',
                        'lineHeightMobile' => '',
                        'lineHeightUnit' => '',
                        'letterSpacing' => '',
                        'letterSpacingTablet' => '',
                        'letterSpacingMobile' => '',
                        'letterSpacingUnit' => '',
                        'marginBottom' => '1.5em',
                        'marginBottomUnit' => '',
                        'module' => 'core',
                        'group' => 'base',
                    ],
                    [
                        'selector' => 'primary-menu-items',
                        'customSelector' => '',
                        'fontFamily' => 'inherit',
                        'fontWeight' => '600',
                        'textTransform' => '',
                        'textDecoration' => '',
                        'fontStyle' => '',
                        'fontSize' => '1rem',
                        'fontSizeTablet' => '',
                        'fontSizeMobile' => '',
                        'fontSizeUnit' => '',
                        'lineHeight' => '1.5em',
                        'lineHeightTablet' => '',
                        'lineHeightMobile' => '',
                        'lineHeightUnit' => '',
                        'letterSpacing' => '',
                        'letterSpacingTablet' => '',
                        'letterSpacingMobile' => '',
                        'letterSpacingUnit' => '',
                        'marginBottom' => '',
                        'marginBottomTablet' => '',
                        'marginBottomMobile' => '',
                        'marginBottomUnit' => '',
                        'module' => 'core',
                        'group' => 'primaryNavigation',
                    ],
                    [
                        'selector' => 'primary-menu-toggle',
                        'customSelector' => '',
                        'fontFamily' => 'inherit',
                        'fontWeight' => '',
                        'textTransform' => '',
                        'textDecoration' => '',
                        'fontStyle' => '',
                        'fontSize' => '1.5rem',
                        'fontSizeTablet' => '',
                        'fontSizeMobile' => '',
                        'fontSizeUnit' => '',
                        'lineHeight' => '',
                        'lineHeightTablet' => '',
                        'lineHeightMobile' => '',
                        'lineHeightUnit' => '',
                        'letterSpacing' => '',
                        'letterSpacingTablet' => '',
                        'letterSpacingMobile' => '',
                        'letterSpacingUnit' => '',
                        'marginBottom' => '',
                        'marginBottomTablet' => '',
                        'marginBottomMobile' => '',
                        'marginBottomUnit' => '',
                        'module' => 'core',
                        'group' => 'primaryNavigation',
                    ],
                    [
                        'selector' => 'h1',
                        'customSelector' => '',
                        'fontFamily' => 'var(--gp-font--barlow)',
                        'fontWeight' => '800',
                        'textTransform' => '',
                        'textDecoration' => '',
                        'fontStyle' => '',
                        'fontSize' => 'clamp(2.375rem, 2.315vw + 1.264rem, 3rem)',
                        'fontSizeTablet' => '',
                        'fontSizeMobile' => '',
                        'fontSizeUnit' => '',
                        'lineHeight' => 'calc(3px + 2ex)',
                        'lineHeightTablet' => '',
                        'lineHeightMobile' => '',
                        'lineHeightUnit' => '',
                        'letterSpacing' => '',
                        'letterSpacingTablet' => '',
                        'letterSpacingMobile' => '',
                        'letterSpacingUnit' => '',
                        'marginBottom' => '2rem',
                        'marginBottomUnit' => '',
                        'module' => 'core',
                        'group' => 'content',
                    ],
                    [
                        'selector' => 'h2',
                        'customSelector' => '',
                        'fontFamily' => 'var(--gp-font--barlow)',
                        'fontWeight' => '800',
                        'textTransform' => '',
                        'textDecoration' => '',
                        'fontStyle' => '',
                        'fontSize' => 'clamp(1.75rem, 1.389vw + 1.083rem, 2.125rem)',
                        'fontSizeTablet' => '',
                        'fontSizeMobile' => '',
                        'fontSizeUnit' => '',
                        'lineHeight' => 'calc(5px + 2ex)',
                        'lineHeightTablet' => '',
                        'lineHeightMobile' => '',
                        'lineHeightUnit' => '',
                        'letterSpacing' => '',
                        'letterSpacingTablet' => '',
                        'letterSpacingMobile' => '',
                        'letterSpacingUnit' => '',
                        'marginBottom' => '2rem',
                        'marginBottomUnit' => '',
                        'module' => 'core',
                        'group' => 'content',
                    ],
                    [
                        'selector' => 'h3',
                        'customSelector' => '',
                        'fontFamily' => 'var(--gp-font--barlow)',
                        'fontWeight' => '800',
                        'textTransform' => '',
                        'textDecoration' => '',
                        'fontStyle' => '',
                        'fontSize' => 'clamp(1.313rem, 1.157vw + 0.757rem, 1.625rem)',
                        'fontSizeTablet' => '',
                        'fontSizeMobile' => '',
                        'fontSizeUnit' => '',
                        'lineHeight' => 'calc(5px + 2ex)',
                        'lineHeightTablet' => '',
                        'lineHeightMobile' => '',
                        'lineHeightUnit' => '',
                        'letterSpacing' => '',
                        'letterSpacingTablet' => '',
                        'letterSpacingMobile' => '',
                        'letterSpacingUnit' => '',
                        'marginBottom' => '2rem',
                        'marginBottomUnit' => '',
                        'module' => 'core',
                        'group' => 'content',
                    ],
                    [
                        'selector' => 'h4',
                        'customSelector' => '',
                        'fontFamily' => 'var(--gp-font--barlow)',
                        'fontWeight' => '800',
                        'textTransform' => '',
                        'textDecoration' => '',
                        'fontStyle' => '',
                        'fontSize' => 'clamp(1.25rem, 0.926vw + 0.806rem, 1.5rem)',
                        'fontSizeTablet' => '',
                        'fontSizeMobile' => '',
                        'fontSizeUnit' => '',
                        'lineHeight' => 'calc(6px + 2ex)',
                        'lineHeightTablet' => '',
                        'lineHeightMobile' => '',
                        'lineHeightUnit' => '',
                        'letterSpacing' => '',
                        'letterSpacingTablet' => '',
                        'letterSpacingMobile' => '',
                        'letterSpacingUnit' => '',
                        'marginBottom' => '2rem',
                        'module' => 'core',
                        'group' => 'content',
                    ],
                    [
                        'selector' => 'primary-sub-menu-items',
                        'customSelector' => '',
                        'fontFamily' => 'inherit',
                        'fontWeight' => '600',
                        'textTransform' => '',
                        'textDecoration' => '',
                        'fontStyle' => '',
                        'fontSize' => '1rem',
                        'fontSizeTablet' => '',
                        'fontSizeMobile' => '',
                        'lineHeight' => '1.5em',
                        'lineHeightTablet' => '',
                        'lineHeightMobile' => '',
                        'letterSpacing' => '',
                        'letterSpacingTablet' => '',
                        'letterSpacingMobile' => '',
                        'marginBottom' => '',
                        'marginBottomTablet' => '',
                        'marginBottomMobile' => '',
                        'marginBottomUnit' => '',
                        'module' => 'core',
                        'group' => 'primaryNavigation',
                    ],
                    [
                        'selector' => 'h5',
                        'customSelector' => '',
                        'fontFamily' => 'var(--gp-font--barlow)',
                        'fontWeight' => '700',
                        'textTransform' => '',
                        'textDecoration' => '',
                        'fontStyle' => '',
                        'fontSize' => '1.1875rem',
                        'fontSizeTablet' => '',
                        'fontSizeMobile' => '',
                        'fontSizeUnit' => '',
                        'lineHeight' => 'calc(6px + 2ex)',
                        'lineHeightTablet' => '',
                        'lineHeightMobile' => '',
                        'lineHeightUnit' => '',
                        'letterSpacing' => '',
                        'letterSpacingTablet' => '',
                        'letterSpacingMobile' => '',
                        'letterSpacingUnit' => '',
                        'marginBottom' => '2rem',
                        'module' => 'core',
                        'group' => 'content',
                    ],
                    [
                        'selector' => 'h6',
                        'customSelector' => '',
                        'fontFamily' => 'var(--gp-font--barlow)',
                        'fontWeight' => '700',
                        'textTransform' => '',
                        'textDecoration' => '',
                        'fontStyle' => '',
                        'fontSize' => '1.125rem',
                        'fontSizeTablet' => '',
                        'fontSizeMobile' => '',
                        'fontSizeUnit' => '',
                        'lineHeight' => 'calc(6px + 2ex)',
                        'lineHeightTablet' => '',
                        'lineHeightMobile' => '',
                        'lineHeightUnit' => '',
                        'letterSpacing' => '',
                        'letterSpacingTablet' => '',
                        'letterSpacingMobile' => '',
                        'letterSpacingUnit' => '',
                        'marginBottom' => '2rem',
                        'module' => 'core',
                        'group' => 'content',
                    ],
                ],

                // Layout defaults
                'layout_setting'         => 'no-sidebar',
                'single_layout_setting'  => 'no-sidebar',
                'blog_layout_setting'    => 'no-sidebar',
                'content_layout_setting' => 'separate-containers',
                'container_width'        => 1200,
                'container_alignment'    => 'boxes',

                // UX defaults
                'underline_links'  => 'never',
                'smooth_scroll'    => true,
                'nav_search_modal' => true,

                // Misc safe brand-affecting toggles
                'css_print_method'      => 'file',
                'dynamic_css_cache'     => false,
                'nav_alignment_setting' => 'left',
                'logo_width'            => 250,
				'hide_title'  => true,
				'hide_tagline' => true,

                // Intentional exclusions for now:
                // - 'retina_logo' (environment-specific URL)
                // - menu/widget theme_mods
                // - 'hide_title'/'hide_tagline' might be content/presentation policy; add later if desired
            ],
        ],
        'theme_mods' => [
    'display_header_text' => false,
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
    $export_data  = [
        'generate_settings' => get_option('generate_settings', null),
        'theme_mods'        => get_theme_mods(),
    ];
    $export_json = wp_json_encode($export_data, JSON_PRETTY_PRINT);
    if (!is_string($export_json)) {
        $export_json = '{}';
    }
    // Keep JSON readable in <textarea> while preventing accidental tag breakout.
    $export_json = str_replace('</textarea', '<\/textarea', $export_json);

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

    $render_diff_value = static function ($value): string {
        $json = wp_json_encode($value, JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            $json = 'null';
        }

        $limit   = 200;
        $preview = function_exists('mb_substr') ? mb_substr($json, 0, $limit) : substr($json, 0, $limit);
        $length  = function_exists('mb_strlen') ? mb_strlen($json) : strlen($json);
        if ($length > $limit) {
            $preview .= '...';
        }

        $out  = '<code style="display:block;white-space:pre-wrap;word-break:break-word;">' . esc_html($preview) . '</code>';
        $out .= '<details style="margin-top:6px"><summary>Show full</summary>';
        $out .= '<pre style="margin:8px 0 0;white-space:pre-wrap;word-break:break-word;">' . esc_html($json) . '</pre>';
        $out .= '</details>';

        return $out;
    };

    // Render plan table
    echo '<style>';
    echo '#acasa-dry-run-table{table-layout:fixed;width:100%;max-width:1200px}';
    echo '#acasa-dry-run-table th,#acasa-dry-run-table td{vertical-align:top;overflow-wrap:anywhere;word-break:break-word}';
    echo '</style>';
    echo '<table id="acasa-dry-run-table" class="widefat striped">';
    echo '<thead><tr><th>Type</th><th>Name</th><th>Key</th><th>From</th><th>To</th></tr></thead><tbody>';

    $rows = 0;

    foreach ($plan['options'] as $option_name => $pairs) {
        foreach ($pairs as $k => $delta) {
            $rows++;
            echo '<tr>';
            echo '<td>Option</td>';
            echo '<td>' . esc_html($option_name) . '</td>';
            echo '<td>' . esc_html((string)$k) . '</td>';
            echo '<td>' . $render_diff_value($delta['from']) . '</td>';
            echo '<td>' . $render_diff_value($delta['to']) . '</td>';
            echo '</tr>';
        }
    }

    foreach ($plan['theme_mods'] as $mod_name => $delta) {
        $rows++;
        echo '<tr>';
        echo '<td>Theme mod</td>';
        echo '<td>' . esc_html($mod_name) . '</td>';
        echo '<td>—</td>';
        echo '<td>' . $render_diff_value($delta['from']) . '</td>';
        echo '<td>' . $render_diff_value($delta['to']) . '</td>';
        echo '</tr>';
    }

    if ($rows === 0) {
        echo '<tr><td colspan="5"><em>No differences detected.</em></td></tr>';
    }

    echo '</tbody></table>';

    echo '<hr>';
    echo '<h2>Export current settings (read-only)</h2>';
    echo '<p>Copy the JSON below for auditing or migration purposes.</p>';
    echo '<textarea readonly rows="24" style="width:100%;max-width:1200px;font-family:monospace;">';
    echo $export_json;
    echo '</textarea>';

    echo '<p style="margin-top:12px"><em>Note:</em> After applying branding on a cached site, you may need to purge page/CSS caches (Autoptimize, Cloudflare, server cache) to see changes immediately.</p>';

    echo '</div>';
}
