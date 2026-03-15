<?php
/**
 * ACASA Child theme functions
 * Managed via Git + Codex.
 */

if (!defined('ACASA_MEGA_PANELS_ENABLED_OPTION')) {
    define('ACASA_MEGA_PANELS_ENABLED_OPTION', 'acasa_mega_panels_enabled');
}
if (!defined('ACASA_MEGA_MENU_ENABLED_OPTION')) {
    // Backward-compat alias for previously introduced option key.
    define('ACASA_MEGA_MENU_ENABLED_OPTION', 'acasa_mega_menu_enabled');
}
if (!defined('ACASA_ADMIN_MENU_SLUG')) {
    define('ACASA_ADMIN_MENU_SLUG', 'acasa-admin');
}

/**
 * Child-theme setting: enable/disable mega submenu panels only.
 * - Enabled: panel CSS/JS + GenerateBlocks panel markup override for primary nav.
 * - Disabled: standard dropdown submenus; ACASA header/icons/donate remain active.
 */
function acasa_is_mega_menu_enabled(): bool {
    $raw = get_option(ACASA_MEGA_PANELS_ENABLED_OPTION, null);
    if ($raw === null) {
        // Fallback for legacy key introduced earlier.
        $raw = get_option(ACASA_MEGA_MENU_ENABLED_OPTION, '1');
    }
    $enabled = !in_array((string) $raw, ['0', 'false', 'off', 'no', ''], true);
    return (bool) apply_filters('acasa_mega_menu_enabled', $enabled);
}

/**
 * Normalize checkbox values to stored "1"/"0".
 *
 * @param mixed $value
 */
function acasa_sanitize_mega_menu_enabled($value): string {
    return (!empty($value) && (string) $value !== '0') ? '1' : '0';
}

/**
 * Mega-panel assets manifest.
 * Future panel-only assets can be registered via `acasa_mega_panel_assets` filter.
 *
 * @return array{styles:array<int,array<string,mixed>>,scripts:array<int,array<string,mixed>>}
 */
function acasa_mega_panel_assets(): array {
    $assets = [
        'styles' => [
            [
                'handle' => 'acasa-header-v1-mega-style',
                'src' => get_stylesheet_directory_uri() . '/header-v1-mega.css',
                'deps' => [ 'acasa-header-v1' ],
                'version' => null,
                'media' => 'all',
            ],
        ],
        'scripts' => [
            [
                'handle' => 'acasa-header-v1-mega',
                'src' => get_stylesheet_directory_uri() . '/header-v1-mega.js',
                'deps' => [],
                'version' => null,
                'in_footer' => true,
            ],
        ],
    ];

    $filtered = apply_filters('acasa_mega_panel_assets', $assets);
    return is_array($filtered) ? $filtered : $assets;
}

add_action('wp_enqueue_scripts', function () {
    $theme_version = wp_get_theme()->get('Version');
    $asset_version = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? (string) time() : $theme_version;

    // Always load the child theme stylesheet.
    wp_enqueue_style(
        'acasa-child-style',
        get_stylesheet_uri(),
        [],
        $theme_version
    );

    /*
     * Header/navigation skin for the locked ACASA top-bar design.
     * CHANGE NOTE:
     * - Added for "yellow line + logo + floating nav box + inline donate menu item" look.
     * - Keep this CSS separate from style.css so header iteration stays isolated.
     */
    wp_enqueue_style(
        'acasa-header-v1',
        get_stylesheet_directory_uri() . '/header-v1.css',
        [ 'acasa-child-style' ],
        $asset_version
    );

    if (acasa_is_mega_menu_enabled() && acasa_primary_menu_term_id() > 0) {
        $assets = acasa_mega_panel_assets();

        $styles = isset($assets['styles']) && is_array($assets['styles']) ? $assets['styles'] : [];
        foreach ($styles as $style) {
            if (!is_array($style) || empty($style['handle']) || empty($style['src'])) {
                continue;
            }

            wp_enqueue_style(
                (string) $style['handle'],
                (string) $style['src'],
                isset($style['deps']) && is_array($style['deps']) ? $style['deps'] : [ 'acasa-header-v1' ],
                isset($style['version']) ? $style['version'] : $asset_version,
                isset($style['media']) ? (string) $style['media'] : 'all'
            );
        }

        $scripts = isset($assets['scripts']) && is_array($assets['scripts']) ? $assets['scripts'] : [];
        foreach ($scripts as $script) {
            if (!is_array($script) || empty($script['handle']) || empty($script['src'])) {
                continue;
            }

            wp_enqueue_script(
                (string) $script['handle'],
                (string) $script['src'],
                isset($script['deps']) && is_array($script['deps']) ? $script['deps'] : [],
                isset($script['version']) ? $script['version'] : $asset_version,
                isset($script['in_footer']) ? (bool) $script['in_footer'] : true
            );
        }
    }
}, 20);

/**
 * Keep Give iframe style handle available without duplicate CSS network request.
 * Plugin enqueues the same stylesheet under two different handles.
 */
add_action('wp_enqueue_scripts', function (): void {
    if (is_admin()) {
        return;
    }

    if (!wp_style_is('euplatesc-givewp-gateway', 'enqueued') || !wp_style_is('givewp-iframes-styles', 'enqueued')) {
        return;
    }

    wp_dequeue_style('givewp-iframes-styles');
    wp_deregister_style('givewp-iframes-styles');
    wp_register_style('givewp-iframes-styles', false, ['euplatesc-givewp-gateway', 'give-sequoia-template-css']);
    wp_enqueue_style('givewp-iframes-styles');
}, 1000);

/**
 * Determine whether current request likely needs Give frontend app assets.
 */
function acasa_request_needs_give_frontend_assets(): bool {
    if (is_admin() || is_customize_preview()) {
        return true;
    }

    $force_keep = (bool) apply_filters('acasa_force_keep_give_frontend_assets', false);
    if ($force_keep) {
        return true;
    }

    if (!is_singular()) {
        return false;
    }

    $post = get_queried_object();
    if (!($post instanceof WP_Post) || !is_string($post->post_content)) {
        return false;
    }

    $content = $post->post_content;
    $give_shortcodes = [
        'give_form',
        'give_donation_history',
        'give_receipt',
        'give_profile_editor',
    ];

    foreach ($give_shortcodes as $shortcode) {
        if (has_shortcode($content, $shortcode)) {
            return true;
        }
    }

    // GiveWP block markup in post content.
    if (strpos($content, 'wp:give/') !== false || strpos($content, 'wp:givewp/') !== false) {
        return true;
    }

    return false;
}

/**
 * Frontend performance guard:
 * remove Give React/editor payload on pages that do not render Give content.
 */
function acasa_prune_frontend_give_editor_payload(): void {
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    $enabled = (bool) apply_filters('acasa_prune_frontend_give_editor_payload', true);
    if (!$enabled || acasa_request_needs_give_frontend_assets()) {
        return;
    }

    $handles = [
        'give-funds-script-frontend',
        'givewp-entities-public',
        'wp-block-editor',
        'wp-components',
        'wp-blocks',
        'wp-date',
    ];

    foreach ($handles as $handle) {
        if (wp_script_is($handle, 'enqueued')) {
            wp_dequeue_script($handle);
        }
    }
}
add_action('wp_print_scripts', 'acasa_prune_frontend_give_editor_payload', 1);
add_action('wp_print_footer_scripts', 'acasa_prune_frontend_give_editor_payload', 1);

/**
 * Convert same-site absolute URL to root-relative URL.
 * Keeps local/live-link environments resilient when host differs.
 */
function acasa_to_root_relative_url(string $url): string {
    if ($url === '') {
        return $url;
    }

    $path = wp_parse_url($url, PHP_URL_PATH);
    $query = wp_parse_url($url, PHP_URL_QUERY);
    $fragment = wp_parse_url($url, PHP_URL_FRAGMENT);

    // For WordPress media/logo paths, force host-agnostic root-relative URLs.
    // This avoids mixed-host/scheme breakage on Local Live Links (mobile 2x srcset).
    if (is_string($path) && $path !== '' && strpos($path, '/wp-content/') === 0) {
        if (is_string($query) && $query !== '') {
            $path .= '?' . $query;
        }
        if (is_string($fragment) && $fragment !== '') {
            $path .= '#' . $fragment;
        }
        return $path;
    }

    $site_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
    $url_host = wp_parse_url($url, PHP_URL_HOST);

    if (!is_string($site_host) || $site_host === '' || !is_string($url_host) || $url_host === '') {
        return $url;
    }

    if (strcasecmp($site_host, $url_host) !== 0) {
        return $url;
    }

    if (!is_string($path) || $path === '') {
        $path = '/';
    }

    if (is_string($query) && $query !== '') {
        $path .= '?' . $query;
    }
    if (is_string($fragment) && $fragment !== '') {
        $path .= '#' . $fragment;
    }

    return $path;
}

/**
 * Normalize srcset-like values by making same-site URLs root-relative.
 */
function acasa_normalize_srcset_value(string $value): string {
    $parts = array_filter(array_map('trim', explode(',', $value)), static function ($part): bool {
        return $part !== '';
    });

    $normalized = [];
    foreach ($parts as $part) {
        if (!preg_match('/^(\S+)(\s+.+)?$/', $part, $matches)) {
            $normalized[] = $part;
            continue;
        }

        $url = acasa_to_root_relative_url($matches[1]);
        $descriptor = isset($matches[2]) ? $matches[2] : '';
        $normalized[] = trim($url . $descriptor);
    }

    return implode(', ', $normalized);
}

/**
 * Make GP logo image URLs host-agnostic for local live links/mobile previews.
 * Applies to src/srcset and common lazy-load data attributes.
 */
add_filter('generate_logo_output', function ($html) {
    if (!is_string($html) || $html === '') {
        return $html;
    }

    static $cache = [];
    $cache_key = md5($html);
    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }

    $attrs = [
        'src' => false,
        'data-src' => false,
        'srcset' => true,
        'data-srcset' => true,
    ];

    foreach ($attrs as $attr => $is_srcset) {
        $pattern = '/\b' . preg_quote($attr, '/') . '=(["\'])(.*?)\1/i';
        $html = preg_replace_callback($pattern, static function (array $matches) use ($attr, $is_srcset): string {
            $raw = isset($matches[2]) ? html_entity_decode((string) $matches[2], ENT_QUOTES, 'UTF-8') : '';
            $value = $is_srcset ? acasa_normalize_srcset_value($raw) : acasa_to_root_relative_url($raw);
            return $attr . '=' . $matches[1] . esc_attr($value) . $matches[1];
        }, $html);
    }

    // Prevent lazy-loader plugins from withholding logo render on live-link/mobile.
    $html = preg_replace('/\sdata-src=(["\']).*?\1/i', '', $html);
    $html = preg_replace('/\sdata-srcset=(["\']).*?\1/i', '', $html);

    $html = preg_replace_callback('/\bclass=(["\'])(.*?)\1/i', static function (array $matches): string {
        $classes = preg_split('/\s+/', trim((string) $matches[2])) ?: [];
        $classes = array_values(array_filter($classes, static function (string $class_name): bool {
            return !in_array(strtolower($class_name), ['lazyload', 'lazyloading', 'lazyloaded'], true);
        }));
        return 'class=' . $matches[1] . esc_attr(implode(' ', $classes)) . $matches[1];
    }, $html);

    if (stripos($html, ' loading=') === false) {
        $html = preg_replace('/<img\b/i', '<img loading="eager"', $html, 1);
    } else {
        $html = preg_replace('/\bloading=(["\']).*?\1/i', 'loading="eager"', $html);
    }

    if (stripos($html, ' fetchpriority=') === false) {
        $html = preg_replace('/<img\b/i', '<img fetchpriority="high"', $html, 1);
    } else {
        $html = preg_replace('/\bfetchpriority=(["\']).*?\1/i', 'fetchpriority="high"', $html);
    }

    $cache[$cache_key] = $html;
    return $html;
}, 20);

/**
 * Remove default menu-bar items (search icon etc.) in the main header nav.
 * The locked header design uses a dedicated donate CTA on the right.
 *
 * CHANGE NOTE:
 * - We hide GP menu-bar utility output to keep header visually clean.
 * - Donate is no longer injected via header hook; it lives in the primary menu.
 */
add_filter('generate_menu_bar_items', '__return_empty_string');

/**
 * Resolve the term ID assigned to the primary menu location.
 */
function acasa_primary_menu_term_id(): int {
    static $id = null;
    if ($id !== null) {
        return $id;
    }

    $locations = get_nav_menu_locations();
    if (!is_array($locations) || !isset($locations['primary'])) {
        $id = 0;
        return $id;
    }

    $id = (int) $locations['primary'];
    return $id;
}

/**
 * Check whether a menu reference points to the primary menu.
 *
 * @param mixed $menu Menu arg as passed to wp_nav_menu().
 */
function acasa_menu_is_primary($menu): bool {
    $primary_id = acasa_primary_menu_term_id();
    if ($primary_id <= 0 || empty($menu)) {
        return false;
    }

    if (is_numeric($menu)) {
        return (int) $menu === $primary_id;
    }

    if (is_object($menu) && isset($menu->term_id)) {
        return (int) $menu->term_id === $primary_id;
    }

    if (is_string($menu)) {
        $menu_obj = wp_get_nav_menu_object($menu);
        if ($menu_obj && isset($menu_obj->term_id)) {
            return (int) $menu_obj->term_id === $primary_id;
        }
    }

    return false;
}

/**
 * Match classic and block-menu rendering contexts for the primary menu.
 *
 * @param mixed $args wp_nav_menu() args object.
 */
function acasa_is_primary_menu_render_context($args): bool {
    if (!is_object($args)) {
        return false;
    }

    if (isset($args->theme_location) && $args->theme_location === 'primary') {
        return true;
    }

    return isset($args->menu) && acasa_menu_is_primary($args->menu);
}

/**
 * Register child-theme header settings.
 */
add_action('admin_init', function (): void {
    register_setting(
        'acasa-header-settings',
        ACASA_MEGA_PANELS_ENABLED_OPTION,
        [
            'type' => 'string',
            'sanitize_callback' => 'acasa_sanitize_mega_menu_enabled',
            'default' => '1',
        ]
    );
});

/**
 * Render ACASA header settings page.
 */
function acasa_render_header_settings_page(): void {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'acasa-child'));
    }

    $enabled = acasa_is_mega_menu_enabled();
    $menu_editor_url = admin_url('admin.php?page=acasa-menu-layout-editor');
    $branding_url = admin_url('admin.php?page=acasa-branding');
    $sync_donors_url = admin_url('admin.php?page=acasa-sync-donors');
    $email_blocks_url = admin_url('admin.php?page=acasa-email-blocks');
    $donor_quick_fix_url = admin_url('admin.php?page=acasa-donor-quick-fix');
    $donor_health_url = admin_url('admin.php?page=acasa-donor-health');

    echo '<div class="wrap">';
    echo '<h1>ACASA</h1>';
    echo '<form method="post" action="options.php">';
    settings_fields('acasa-header-settings');
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr>';
    echo '<th scope="row">Enable Mega Submenu Panels</th>';
    echo '<td>';
    echo '<label>';
    echo '<input type="hidden" name="' . esc_attr(ACASA_MEGA_PANELS_ENABLED_OPTION) . '" value="0" />';
    echo '<input type="checkbox" name="' . esc_attr(ACASA_MEGA_PANELS_ENABLED_OPTION) . '" value="1" ' . checked(true, $enabled, false) . ' />';
    echo ' Load desktop mega submenu panels and panel-only assets';
    echo '</label>';
    echo '<p class="description">When disabled, only mega panel behavior is removed (panel markup override, panel CSS, panel JS). Core ACASA header/menu styling, icons, and donate logic remain active.</p>';
    echo '</td>';
    echo '</tr>';
    echo '</tbody></table>';
    submit_button('Save Changes');
    echo '</form>';
    echo '<p><a class="button button-secondary" href="' . esc_url($menu_editor_url) . '">Open ACASA Menu Element Editor</a></p>';
    echo '<p><a class="button button-secondary" href="' . esc_url($branding_url) . '">Open ACASA Branding Tool</a></p>';
    if (class_exists('Acasa_Data_Tools') || function_exists('acasa_render_sync_donors_page')) {
        echo '<hr />';
        echo '<h2>Donor Tools</h2>';
        if (function_exists('acasa_render_sync_donors_page') || class_exists('Acasa_Data_Tools')) {
            echo '<p><a class="button button-secondary" href="' . esc_url($sync_donors_url) . '">Open Donor Sync</a></p>';
        }
        if (class_exists('Acasa_Data_Tools')) {
            echo '<p><a class="button button-secondary" href="' . esc_url($email_blocks_url) . '">Open Donor Email Blocks</a></p>';
            echo '<p><a class="button button-secondary" href="' . esc_url($donor_quick_fix_url) . '">Open Donor Quick Fix</a></p>';
            echo '<p><a class="button button-secondary" href="' . esc_url($donor_health_url) . '">Open Donor Health</a></p>';
        }
    }
    echo '</div>';
}

/**
 * Single ACASA admin menu, grouping all child-theme tools.
 */
add_action('admin_menu', function (): void {
    add_menu_page(
        'ACASA',
        'ACASA',
        'manage_options',
        ACASA_ADMIN_MENU_SLUG,
        'acasa_render_header_settings_page',
        'dashicons-admin-home',
        58
    );

    add_submenu_page(
        ACASA_ADMIN_MENU_SLUG,
        'ACASA',
        'Settings',
        'manage_options',
        ACASA_ADMIN_MENU_SLUG,
        'acasa_render_header_settings_page'
    );

    add_submenu_page(
        ACASA_ADMIN_MENU_SLUG,
        'ACASA Menu Element',
        'Menu Element',
        'manage_options',
        'acasa-menu-layout-editor',
        'acasa_render_menu_layout_editor_page'
    );

    add_submenu_page(
        ACASA_ADMIN_MENU_SLUG,
        'ACASA Branding',
        'Branding',
        'manage_options',
        'acasa-branding',
        'acasa_render_branding_tools_page'
    );
});

/**
 * Editable menu-layout post wiring.
 * - Preferred editor surface is a GeneratePress Element (`gp_elements`).
 * - Falls back to a private Page if Elements post type is unavailable.
 * - Menu items still come from Appearance > Menus > Primary.
 */
if (!defined('ACASA_PRIMARY_NAV_LAYOUT_SLUG')) {
    define('ACASA_PRIMARY_NAV_LAYOUT_SLUG', 'acasa-menu-layout-editor');
}
if (!defined('ACASA_PRIMARY_NAV_LAYOUT_TITLE')) {
    define('ACASA_PRIMARY_NAV_LAYOUT_TITLE', 'ACASA Menu Layout (Element)');
}
if (!defined('ACASA_PRIMARY_NAV_LAYOUT_OPTION')) {
    define('ACASA_PRIMARY_NAV_LAYOUT_OPTION', 'acasa_primary_nav_layout_post_id');
}
if (!defined('ACASA_PRIMARY_NAV_LAYOUT_SIGNATURE_KEY')) {
    define('ACASA_PRIMARY_NAV_LAYOUT_SIGNATURE_KEY', '_acasa_primary_nav_layout_signature');
}
if (!defined('ACASA_PRIMARY_NAV_LAYOUT_SIGNATURE_VALUE')) {
    define('ACASA_PRIMARY_NAV_LAYOUT_SIGNATURE_VALUE', 'acasa-primary-nav-layout-v1');
}

/**
 * Resolve preferred post type for menu layout editor content.
 */
function acasa_primary_navigation_preferred_layout_post_type(): string {
    return post_type_exists('gp_elements') ? 'gp_elements' : 'page';
}

/**
 * Allowed post types for menu layout editor content.
 *
 * @return array<int,string>
 */
function acasa_primary_navigation_allowed_layout_post_types(): array {
    return ['gp_elements', 'page'];
}

/**
 * Find an existing layout editor post by slug + post type.
 */
function acasa_primary_navigation_find_layout_post_id(string $post_type): int {
    if ($post_type === '' || !post_type_exists($post_type)) {
        return 0;
    }

    $existing = get_page_by_path(ACASA_PRIMARY_NAV_LAYOUT_SLUG, OBJECT, $post_type);
    return $existing instanceof WP_Post ? (int) $existing->ID : 0;
}

/**
 * Build default GenerateBlocks Navigation block payload for the primary menu.
 */
function acasa_primary_navigation_default_block_markup(int $menu_id): string {
    if ($menu_id < 0) {
        return '';
    }

    return sprintf(
        '<!-- wp:generateblocks-pro/navigation {"uniqueId":"navacasa01","subMenuType":"hover","tagName":"nav","htmlAttributes":{"data-gb-mobile-breakpoint":"1320px","aria-label":"Primary menu"}} -->'
        . '<nav class="main-nav gb-navigation gb-navigation-navacasa01" data-gb-mobile-breakpoint="1320px" aria-label="Primary menu">'
        . '<!-- wp:generateblocks-pro/classic-menu {"menu":"%1$d","uniqueId":"cmacasa01"} /-->'
        . '</nav>'
        . '<!-- /wp:generateblocks-pro/navigation -->',
        $menu_id
    );
}

/**
 * Build stable panel keys from top-level menu item titles.
 */
function acasa_menu_item_panel_key($item): string {
    if (!is_object($item)) {
        return '';
    }

    $raw_title = isset($item->title) ? trim((string) $item->title) : '';
    $panel_key = sanitize_title($raw_title);
    if ($panel_key !== '') {
        return $panel_key;
    }

    if (isset($item->ID) && (int) $item->ID > 0) {
        return 'item-' . (int) $item->ID;
    }

    return '';
}

/**
 * Build panel definitions from top-level primary menu items.
 *
 * @return array<int,array<string,mixed>>
 */
function acasa_primary_navigation_panel_items(): array {
    static $panels = null;
    if ($panels !== null) {
        return $panels;
    }

    if (!acasa_is_mega_menu_enabled()) {
        $panels = [];
        return $panels;
    }

    $menu_id = acasa_primary_menu_term_id();
    if ($menu_id <= 0) {
        $panels = [];
        return $panels;
    }

    $menu_items = wp_get_nav_menu_items($menu_id);
    if (!is_array($menu_items) || $menu_items === []) {
        $panels = [];
        return $panels;
    }

    $has_children = [];
    foreach ($menu_items as $menu_item) {
        if (!is_object($menu_item)) {
            continue;
        }

        $parent_id = isset($menu_item->menu_item_parent) ? (int) $menu_item->menu_item_parent : 0;
        if ($parent_id > 0) {
            $has_children[$parent_id] = true;
        }
    }

    $seen_keys = [];
    $panels = [];

    foreach ($menu_items as $menu_item) {
        if (!is_object($menu_item)) {
            continue;
        }

        $parent_id = isset($menu_item->menu_item_parent) ? (int) $menu_item->menu_item_parent : 0;
        if ($parent_id !== 0) {
            continue;
        }

        $roles = function_exists('acasa_menu_item_roles')
            ? acasa_menu_item_roles($menu_item)
            : [ 'contact' => false, 'account' => false, 'donate' => false, 'tool' => false ];

        if (!empty($roles['tool']) || !empty($roles['donate'])) {
            continue;
        }

        $panel_key = acasa_menu_item_panel_key($menu_item);
        if ($panel_key === '' || isset($seen_keys[$panel_key])) {
            continue;
        }
        $seen_keys[$panel_key] = true;

        $title = isset($menu_item->title) ? trim((string) $menu_item->title) : '';
        if ($title === '') {
            $title = ucwords(str_replace('-', ' ', $panel_key));
        }

        $menu_item_id = isset($menu_item->ID) ? (int) $menu_item->ID : 0;
        $panels[] = [
            'key' => $panel_key,
            'title' => $title,
            'has_children' => $menu_item_id > 0 && isset($has_children[$menu_item_id]),
        ];
    }

    return $panels;
}

/**
 * Build deterministic GenerateBlocks unique IDs for menu scaffolding blocks.
 */
function acasa_primary_navigation_block_unique_id(string $seed, string $prefix = 'acm'): string {
    $normalized_prefix = sanitize_key($prefix);
    if ($normalized_prefix === '') {
        $normalized_prefix = 'acm';
    }

    $hash = substr(md5($seed), 0, 8);
    return substr($normalized_prefix . $hash, 0, 12);
}

/**
 * Build editable block markup for one mega panel using GenerateBlocks Grid.
 */
function acasa_primary_navigation_panel_markup(array $panel): string {
    $key = isset($panel['key']) ? sanitize_html_class((string) $panel['key']) : '';
    if ($key === '') {
        return '';
    }

    $title = isset($panel['title']) ? trim((string) $panel['title']) : '';
    if ($title === '') {
        $title = ucwords(str_replace('-', ' ', $key));
    }

    $panel_class = 'acasa-mega-panel acasa-mega-panel-' . $key;
    $intro = !empty($panel['has_children'])
        ? 'Edit this submenu panel visually. Use columns, images, text, and links.'
        : 'Edit this feature panel visually for items without submenu children.';

    $is_programs = ($key === 'programe');
    $main_width = $is_programs ? 75 : 70;
    $aside_width = 100 - $main_width;
    $aside_heading = $is_programs ? 'Other items' : 'Quick links';
    $aside_list = $is_programs
        ? '<ul><li>Sanatate</li><li>Educatie</li><li>Proiect C.O.R.E.</li></ul>'
        : '<ul><li>Add link</li><li>Add link</li><li>Add link</li></ul>';

    $main_cta = $is_programs
        ? '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"className":"acasa-mega-panel__cta"} --><div class="wp-block-button acasa-mega-panel__cta"><a class="wp-block-button__link wp-element-button" href="#">Comunitatea Britta Sofia</a></div><!-- /wp:button --></div><!-- /wp:buttons -->'
        : '';

    $panel_uid = acasa_primary_navigation_block_unique_id('panel-' . $key, 'amp');
    $panel_grid_uid = acasa_primary_navigation_block_unique_id('panel-grid-' . $key, 'amg');
    $main_uid = acasa_primary_navigation_block_unique_id('panel-main-' . $key, 'amm');
    $aside_uid = acasa_primary_navigation_block_unique_id('panel-aside-' . $key, 'ama');

    return
        '<!-- wp:generateblocks/container {"uniqueId":"' . esc_attr($panel_uid) . '","isDynamic":true,"blockVersion":3,"isGrid":true,"width":100,"widthTablet":100,"widthMobile":100,"useInnerContainer":false,"className":"' . esc_attr($panel_class) . '"} -->'
        . '<!-- wp:generateblocks/grid {"uniqueId":"' . esc_attr($panel_grid_uid) . '","isDynamic":true,"blockVersion":3,"horizontalGap":0,"verticalGap":0,"className":"acasa-mega-panel__grid"} -->'
        . '<!-- wp:generateblocks/container {"uniqueId":"' . esc_attr($main_uid) . '","isDynamic":true,"blockVersion":3,"isGrid":true,"width":' . (int) $main_width . ',"widthTablet":100,"widthMobile":100,"useInnerContainer":false,"className":"acasa-mega-panel__main"} -->'
        . '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">' . esc_html($title) . '</h3><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>' . esc_html($intro) . '</p><!-- /wp:paragraph -->'
        . $main_cta
        . '<!-- /wp:generateblocks/container -->'
        . '<!-- wp:generateblocks/container {"uniqueId":"' . esc_attr($aside_uid) . '","isDynamic":true,"blockVersion":3,"isGrid":true,"width":' . (int) $aside_width . ',"widthTablet":100,"widthMobile":100,"useInnerContainer":false,"className":"acasa-mega-panel__aside"} -->'
        . '<!-- wp:heading {"level":4} --><h4 class="wp-block-heading">' . esc_html($aside_heading) . '</h4><!-- /wp:heading -->'
        . '<!-- wp:list -->' . $aside_list . '<!-- /wp:list -->'
        . '<!-- /wp:generateblocks/container -->'
        . '<!-- /wp:generateblocks/grid -->'
        . '<!-- /wp:generateblocks/container -->';
}

/**
 * Build editable mega shell scaffold markup.
 */
function acasa_primary_navigation_mega_shell_markup(): string {
    $panels = acasa_primary_navigation_panel_items();
    if ($panels === []) {
        return '';
    }

    $panel_markup = '';
    foreach ($panels as $panel) {
        $panel_markup .= acasa_primary_navigation_panel_markup($panel);
    }

    if ($panel_markup === '') {
        return '';
    }

    $shell_uid = acasa_primary_navigation_block_unique_id('mega-shell', 'ams');
    $shell_grid_uid = acasa_primary_navigation_block_unique_id('mega-shell-grid', 'asg');

    return
        '<!-- wp:generateblocks/container {"uniqueId":"' . esc_attr($shell_uid) . '","isDynamic":true,"blockVersion":3,"useInnerContainer":false,"className":"acasa-mega-shell"} -->'
        . '<!-- wp:generateblocks/grid {"uniqueId":"' . esc_attr($shell_grid_uid) . '","isDynamic":true,"blockVersion":3,"horizontalGap":0,"verticalGap":0,"className":"acasa-mega-shell__grid"} -->'
        . $panel_markup
        . '<!-- /wp:generateblocks/grid -->'
        . '<!-- /wp:generateblocks/container -->';
}

/**
 * Check whether content already contains mega shell scaffolding.
 */
function acasa_primary_navigation_has_mega_shell(string $content): bool {
    return strpos($content, 'acasa-mega-shell') !== false;
}

/**
 * Check if a parsed block has a specific class in its className attribute.
 */
function acasa_primary_navigation_block_has_class(array $block, string $class_name): bool {
    if ($class_name === '') {
        return false;
    }

    $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
    $class_attr = isset($attrs['className']) ? trim((string) $attrs['className']) : '';
    if ($class_attr === '') {
        return false;
    }

    $classes = preg_split('/\s+/', $class_attr) ?: [];
    return in_array($class_name, $classes, true);
}

/**
 * Remove shell blocks from top-level block list and report shell state.
 *
 * @param array<int,array<string,mixed>> $blocks
 * @return array{blocks:array<int,array<string,mixed>>,has_shell:bool,has_grid_shell:bool}
 */
function acasa_primary_navigation_strip_shell_blocks(array $blocks): array {
    $filtered = [];
    $has_shell = false;
    $has_grid_shell = false;

    foreach ($blocks as $block) {
        if (!is_array($block)) {
            $filtered[] = $block;
            continue;
        }

        if (!acasa_primary_navigation_block_has_class($block, 'acasa-mega-shell')) {
            $filtered[] = $block;
            continue;
        }

        $has_shell = true;
        $block_name = isset($block['blockName']) ? (string) $block['blockName'] : '';
        if ($block_name === 'generateblocks/container') {
            $has_grid_shell = true;
        }
    }

    return [
        'blocks' => $filtered,
        'has_shell' => $has_shell,
        'has_grid_shell' => $has_grid_shell,
    ];
}

/**
 * Append or migrate mega shell scaffolding in menu layout content.
 */
function acasa_primary_navigation_append_mega_shell_to_content(string $content): string {
    if (!acasa_is_mega_menu_enabled()) {
        return $content;
    }

    if (trim($content) === '') {
        return $content;
    }

    $shell_markup = acasa_primary_navigation_mega_shell_markup();
    if ($shell_markup === '') {
        return $content;
    }

    $blocks = parse_blocks($content);
    if (is_array($blocks) && $blocks !== []) {
        $shell_state = acasa_primary_navigation_strip_shell_blocks($blocks);
        if (!empty($shell_state['has_grid_shell'])) {
            return $content;
        }

        $shell_blocks = parse_blocks($shell_markup);
        if (!is_array($shell_blocks) || $shell_blocks === []) {
            return $content;
        }

        $merged_blocks = array_merge($shell_state['blocks'], $shell_blocks);
        $serialized = serialize_blocks($merged_blocks);
        if (is_string($serialized) && trim($serialized) !== '') {
            return $serialized;
        }

        return $content;
    }

    if (acasa_primary_navigation_has_mega_shell($content)) {
        return $content;
    }

    return rtrim($content) . "\n\n" . $shell_markup;
}

/**
 * Persist mega shell scaffolding into layout post content when missing.
 */
function acasa_primary_navigation_ensure_mega_shell_in_post(int $post_id): void {
    if ($post_id <= 0) {
        return;
    }

    $content = (string) get_post_field('post_content', $post_id);
    if (trim($content) === '') {
        return;
    }

    $updated_content = acasa_primary_navigation_append_mega_shell_to_content($content);
    if ($updated_content === $content) {
        return;
    }

    wp_update_post([
        'ID' => $post_id,
        'post_content' => $updated_content,
    ]);
}

/**
 * Resolve the editor Element/Page used for visual menu layout editing.
 */
function acasa_primary_navigation_layout_post_id(): int {
    static $post_id = null;
    if ($post_id !== null) {
        return $post_id;
    }

    $stored_id = (int) get_option(ACASA_PRIMARY_NAV_LAYOUT_OPTION, 0);
    if ($stored_id > 0) {
        $stored_post = get_post($stored_id);
        if ($stored_post instanceof WP_Post && in_array($stored_post->post_type, acasa_primary_navigation_allowed_layout_post_types(), true)) {
            $post_id = (int) $stored_post->ID;
            return $post_id;
        }
    }

    $preferred_type = acasa_primary_navigation_preferred_layout_post_type();
    $preferred_id = acasa_primary_navigation_find_layout_post_id($preferred_type);
    if ($preferred_id > 0) {
        $post_id = $preferred_id;
        return $post_id;
    }

    foreach (acasa_primary_navigation_allowed_layout_post_types() as $type) {
        if ($type === $preferred_type) {
            continue;
        }

        $fallback_id = acasa_primary_navigation_find_layout_post_id($type);
        if ($fallback_id > 0) {
            $post_id = $fallback_id;
            return $post_id;
        }
    }

    $post_id = 0;
    return $post_id;
}

/**
 * Versioned cache key space for primary navigation block markup.
 * Bumped when menu structure or layout post changes.
 */
function acasa_primary_navigation_cache_version(): int {
    return max(1, (int) get_option('acasa_primary_nav_cache_version', 1));
}

function acasa_primary_navigation_bump_cache_version(): void {
    update_option('acasa_primary_nav_cache_version', time(), false);
}

add_action('wp_update_nav_menu', function (): void {
    acasa_primary_navigation_bump_cache_version();
});

add_action('save_post', function (int $post_id): void {
    if ($post_id <= 0 || wp_is_post_revision($post_id)) {
        return;
    }

    $layout_post_id = acasa_primary_navigation_layout_post_id();
    if ($layout_post_id > 0 && $post_id === $layout_post_id) {
        acasa_primary_navigation_bump_cache_version();
    }
}, 10, 1);

/**
 * Force every GenerateBlocks classic-menu block to use Primary menu term ID.
 *
 * @param array<int,array<string,mixed>> $blocks
 * @return array<int,array<string,mixed>>
 */
function acasa_primary_navigation_bind_menu_id(array $blocks, int $menu_id, bool &$found): array {
    foreach ($blocks as $index => $block) {
        if (!is_array($block)) {
            continue;
        }

        $block_name = isset($block['blockName']) && is_string($block['blockName']) ? $block['blockName'] : '';
        if ($block_name === 'generateblocks-pro/classic-menu') {
            $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
            $attrs['menu'] = (string) $menu_id;
            $block['attrs'] = $attrs;
            $found = true;
        }

        if (isset($block['innerBlocks']) && is_array($block['innerBlocks']) && $block['innerBlocks'] !== []) {
            $block['innerBlocks'] = acasa_primary_navigation_bind_menu_id($block['innerBlocks'], $menu_id, $found);
        }

        $blocks[$index] = $block;
    }

    return $blocks;
}

/**
 * Load visually-editable menu layout markup from the editor page.
 */
function acasa_primary_navigation_editor_markup(int $menu_id): string {
    if ($menu_id <= 0) {
        return '';
    }

    $layout_post_id = acasa_primary_navigation_layout_post_id();
    if ($layout_post_id <= 0) {
        return '';
    }

    $content = (string) get_post_field('post_content', $layout_post_id);
    if (trim($content) === '') {
        return '';
    }

    $content = acasa_primary_navigation_append_mega_shell_to_content($content);

    $blocks = parse_blocks($content);
    if (!is_array($blocks) || $blocks === []) {
        return '';
    }

    $found_classic_menu = false;
    $blocks = acasa_primary_navigation_bind_menu_id($blocks, $menu_id, $found_classic_menu);
    if (!$found_classic_menu) {
        return '';
    }

    $serialized = serialize_blocks($blocks);
    return is_string($serialized) ? $serialized : '';
}

/**
 * Build GenerateBlocks Navigation block payload for primary menu rendering.
 * Uses editor-managed layout if present, otherwise falls back to default markup.
 */
function acasa_primary_navigation_block_markup(int $menu_id): string {
    if ($menu_id <= 0) {
        return '';
    }

    $layout_post_id = acasa_primary_navigation_layout_post_id();
    $layout_modified_gmt = $layout_post_id > 0 ? (string) get_post_field('post_modified_gmt', $layout_post_id) : 'none';
    $cache_version = acasa_primary_navigation_cache_version();
    $cache_key = 'acasa_nav_markup_' . md5($menu_id . '|' . $layout_post_id . '|' . $layout_modified_gmt . '|' . $cache_version);

    $cached_markup = get_transient($cache_key);
    if (is_string($cached_markup) && trim($cached_markup) !== '') {
        return $cached_markup;
    }

    $markup = acasa_primary_navigation_editor_markup($menu_id);
    if ($markup === '') {
        $markup = acasa_primary_navigation_default_block_markup($menu_id);
    }

    if ($markup !== '') {
        set_transient($cache_key, $markup, 12 * HOUR_IN_SECONDS);
    }

    return $markup;
}

/**
 * Ensure visual menu-layout editor Element/Page exists and return its ID.
 * Never overwrites existing editor content.
 */
function acasa_primary_navigation_ensure_layout_post(): int {
    $preferred_type = acasa_primary_navigation_preferred_layout_post_type();
    if (!post_type_exists($preferred_type)) {
        return 0;
    }

    $existing_id = acasa_primary_navigation_layout_post_id();
    if ($existing_id > 0) {
        $existing_post = get_post($existing_id);
        if ($existing_post instanceof WP_Post && $existing_post->post_type === $preferred_type) {
            update_option(ACASA_PRIMARY_NAV_LAYOUT_OPTION, $existing_id, false);
            update_post_meta($existing_id, ACASA_PRIMARY_NAV_LAYOUT_SIGNATURE_KEY, ACASA_PRIMARY_NAV_LAYOUT_SIGNATURE_VALUE);
            acasa_primary_navigation_ensure_mega_shell_in_post($existing_id);
            return $existing_id;
        }
    }

    $source_content = '';
    if ($existing_id > 0) {
        $source_content = (string) get_post_field('post_content', $existing_id);
    }

    if (trim($source_content) === '') {
        $default_menu_id = acasa_primary_menu_term_id();
        $source_content = acasa_primary_navigation_default_block_markup(max(0, $default_menu_id));
    }

    $source_content = acasa_primary_navigation_append_mega_shell_to_content($source_content);

    if (trim($source_content) === '') {
        return 0;
    }

    $preferred_existing_id = acasa_primary_navigation_find_layout_post_id($preferred_type);
    if ($preferred_existing_id > 0) {
        update_option(ACASA_PRIMARY_NAV_LAYOUT_OPTION, $preferred_existing_id, false);
        update_post_meta($preferred_existing_id, ACASA_PRIMARY_NAV_LAYOUT_SIGNATURE_KEY, ACASA_PRIMARY_NAV_LAYOUT_SIGNATURE_VALUE);

        $preferred_existing_content = (string) get_post_field('post_content', $preferred_existing_id);
        if (trim($preferred_existing_content) === '') {
            wp_update_post([
                'ID' => $preferred_existing_id,
                'post_content' => $source_content,
            ]);
        }

        if ($preferred_type === 'gp_elements') {
            update_post_meta($preferred_existing_id, '_generate_element_type', 'block');
        }

        acasa_primary_navigation_ensure_mega_shell_in_post($preferred_existing_id);
        return $preferred_existing_id;
    }

    $created_post_id = wp_insert_post([
        'post_type' => $preferred_type,
        'post_status' => $preferred_type === 'gp_elements' ? 'publish' : 'private',
        'post_title' => ACASA_PRIMARY_NAV_LAYOUT_TITLE,
        'post_name' => ACASA_PRIMARY_NAV_LAYOUT_SLUG,
        'post_content' => $source_content,
        'comment_status' => 'closed',
        'ping_status' => 'closed',
    ], true);

    if (is_wp_error($created_post_id) || !is_numeric($created_post_id) || (int) $created_post_id <= 0) {
        return 0;
    }

    $created_post_id = (int) $created_post_id;
    update_option(ACASA_PRIMARY_NAV_LAYOUT_OPTION, $created_post_id, false);
    update_post_meta($created_post_id, ACASA_PRIMARY_NAV_LAYOUT_SIGNATURE_KEY, ACASA_PRIMARY_NAV_LAYOUT_SIGNATURE_VALUE);

    if ($preferred_type === 'gp_elements') {
        update_post_meta($created_post_id, '_generate_element_type', 'block');
    }

    acasa_primary_navigation_ensure_mega_shell_in_post($created_post_id);
    return $created_post_id;
}

/**
 * Ensure visual menu-layout editor page exists.
 * This runs only in wp-admin and never overwrites editor content.
 */
add_action('admin_init', function (): void {
    if (!acasa_is_mega_menu_enabled()) {
        return;
    }

    if (!current_user_can('edit_theme_options')) {
        return;
    }

    acasa_primary_navigation_ensure_layout_post();
});

/**
 * Render ACASA Menu Element admin page.
 */
function acasa_render_menu_layout_editor_page(): void {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'acasa-child'));
    }

    if (!acasa_is_mega_menu_enabled()) {
        $settings_url = admin_url('admin.php?page=' . ACASA_ADMIN_MENU_SLUG);
        echo '<div class="wrap"><h1>ACASA Menu Element</h1><p>Mega submenu panels are disabled. Enable them in <a href="' . esc_url($settings_url) . '">ACASA -> Settings</a> to edit panel layouts.</p></div>';
        return;
    }

    $layout_post_id = acasa_primary_navigation_ensure_layout_post();
    $edit_url = $layout_post_id > 0 ? get_edit_post_link($layout_post_id, 'raw') : '';
    if (is_string($edit_url) && $edit_url !== '') {
        wp_safe_redirect($edit_url);
        exit;
    }

    echo '<div class="wrap"><h1>ACASA Menu Element</h1><p>The menu element editor is not available yet. Reload this page once.</p></div>';
}

/**
 * Replace GP primary menu markup with a GenerateBlocks Navigation block render.
 * This keeps the existing Primary menu data source while enabling block-based nav.
 */
add_filter('wp_nav_menu', function (string $nav_menu, $args): string {
    if (!acasa_is_mega_menu_enabled()) {
        return $nav_menu;
    }

    if (
        is_admin()
        || !is_object($args)
        || !isset($args->theme_location)
        || $args->theme_location !== 'primary'
    ) {
        return $nav_menu;
    }

    static $is_rendering = false;
    if ($is_rendering) {
        return $nav_menu;
    }

    $primary_menu_id = acasa_primary_menu_term_id();
    if ($primary_menu_id <= 0) {
        return $nav_menu;
    }

    $block_markup = acasa_primary_navigation_block_markup($primary_menu_id);
    if ($block_markup === '') {
        return $nav_menu;
    }

    $is_rendering = true;
    $rendered = do_blocks($block_markup);
    $is_rendering = false;

    return is_string($rendered) && trim($rendered) !== '' ? $rendered : $nav_menu;
}, 20, 2);

/**
 * Normalize menu item classes for semantic role matching.
 */
function acasa_normalize_menu_classes($item): array {
    $raw = [];
    if (is_object($item) && isset($item->classes) && is_array($item->classes)) {
        $raw = $item->classes;
    }

    $out = [];
    foreach ($raw as $class_name) {
        if (!is_string($class_name) || $class_name === '') {
            continue;
        }
        $normalized = strtolower(trim($class_name));
        if ($normalized !== '') {
            $out[] = $normalized;
        }
    }

    return array_values(array_unique($out));
}

/**
 * Resolve semantic roles from user-defined menu classes.
 *
 * Supported class contract (case-insensitive):
 * - isContact  -> contact tool item
 * - isAccount  -> account tool item
 * - isDonate   -> donate CTA item
 * - isTool     -> generic tool item (icon-mode, right-zone group)
 */
function acasa_menu_item_roles($item): array {
    $classes = acasa_normalize_menu_classes($item);

    $is_contact = in_array('iscontact', $classes, true) || in_array('is-contact', $classes, true);
    $is_account = in_array('isaccount', $classes, true) || in_array('is-account', $classes, true);
    $is_donate = in_array('isdonate', $classes, true) || in_array('is-donate', $classes, true);
    $is_tool = in_array('istool', $classes, true) || in_array('is-tool', $classes, true) || $is_contact || $is_account;

    return [
        'contact' => $is_contact,
        'account' => $is_account,
        'donate' => $is_donate,
        'tool' => $is_tool,
    ];
}

/**
 * Read semantic utility items from the primary menu.
 * Used by collapsed controls so URLs exactly mirror main-menu items.
 */
function acasa_primary_menu_semantics(): array {
    static $cached_state = null;
    if (is_array($cached_state)) {
        return $cached_state;
    }

    $state = [
        'contact' => [ 'present' => false, 'url' => '', 'title' => '' ],
        'account' => [ 'present' => false, 'url' => '', 'title' => '' ],
        'donate' => [ 'present' => false, 'url' => '', 'title' => '' ],
    ];

    $locations = get_nav_menu_locations();
    if (!is_array($locations) || !isset($locations['primary']) || !$locations['primary']) {
        $cached_state = $state;
        return $cached_state;
    }

    $menu_items = wp_get_nav_menu_items((int) $locations['primary']);
    if (!is_array($menu_items)) {
        $cached_state = $state;
        return $cached_state;
    }

    foreach ($menu_items as $item) {
        if (!is_object($item) || !isset($item->menu_item_parent) || (int) $item->menu_item_parent !== 0) {
            continue;
        }

        $roles = acasa_menu_item_roles($item);
        $title = isset($item->title) ? trim((string) $item->title) : '';
        $url = isset($item->url) ? trim((string) $item->url) : '';

        if (!$state['contact']['present'] && $roles['contact']) {
            $state['contact'] = [ 'present' => true, 'url' => $url, 'title' => $title ];
        }

        if (!$state['account']['present'] && $roles['account']) {
            $state['account'] = [ 'present' => true, 'url' => $url, 'title' => $title ];
        }

        if (!$state['donate']['present'] && $roles['donate']) {
            $state['donate'] = [ 'present' => true, 'url' => $url, 'title' => $title ];
        }
    }

    // Safety fallback: donate can be auto-appended later by wp_nav_menu_items.
    if (!$state['donate']['present']) {
        $state['donate'] = [
            'present' => true,
            'url' => home_url('/donatii-online/'),
            'title' => 'Doneaza',
        ];
    }

    $cached_state = $state;
    return $cached_state;
}

/**
 * Resolve account display payload for menu UI.
 *
 * @return array{is_logged_in:bool,label:string,avatar_html:string,user_id:int}
 */
function acasa_account_display_payload(int $avatar_size = 30, string $avatar_class = 'acasa-account-avatar'): array {
    static $cache = [];
    $cache_key = $avatar_size . '|' . $avatar_class;
    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }

    $payload = [
        'is_logged_in' => false,
        'label' => 'Login',
        'avatar_html' => '',
        'user_id' => 0,
    ];

    if (!is_user_logged_in()) {
        $cache[$cache_key] = $payload;
        return $payload;
    }

    $user = wp_get_current_user();
    if (!$user instanceof WP_User || (int) $user->ID <= 0) {
        $cache[$cache_key] = $payload;
        return $payload;
    }

    // Header rule:
    // - default: first name (or safe fallback),
    // - manual "Display name publicly as" override: use chosen display_name.
    $override = (string) get_user_meta( (int) $user->ID, 'acasa_display_name_override', true ) === '1';
    if ( $override ) {
        $label = trim( (string) $user->display_name );
    } else {
        $label = function_exists( 'acasa_default_account_label' )
            ? acasa_default_account_label( $user )
            : trim( (string) get_user_meta( (int) $user->ID, 'first_name', true ) );
    }

    if ( $label === '' || $label === $user->user_email ) {
        $label = 'Cont';
    }
    if ( $label === '' ) {
        $label = 'Cont';
    }

    $payload['is_logged_in'] = true;
    $payload['label'] = $label;
    $payload['user_id'] = (int) $user->ID;

    $avatar_markup = get_avatar(
        (int) $user->ID,
        max(16, (int) $avatar_size),
        '',
        $label,
        [
            'class' => $avatar_class,
            'force_display' => true,
            'extra_attr' => 'loading="eager" decoding="async"',
        ]
    );

    if (is_string($avatar_markup) && $avatar_markup !== '') {
        $payload['avatar_html'] = $avatar_markup;
    }

    $cache[$cache_key] = $payload;
    return $payload;
}

/**
 * Add quick-action icon buttons in mobile header controls.
 * Order in row: Content toggle, optional Contact, optional My Account, Donate.
 * Contact/My Account/Donate presence and URLs mirror primary menu items.
 */
add_action('generate_inside_mobile_menu_control_wrapper', function (): void {
    $sem = acasa_primary_menu_semantics();

    if (!empty($sem['contact']['present'])) {
        $contact_url = is_string($sem['contact']['url']) && $sem['contact']['url'] !== '' ? $sem['contact']['url'] : home_url('/contact/');
        $contact_title = is_string($sem['contact']['title']) && $sem['contact']['title'] !== '' ? $sem['contact']['title'] : 'Contact';
        echo '<a class="acasa-mobile-quick-link acasa-mobile-quick-link--contact" href="' . esc_url($contact_url) . '" aria-label="' . esc_attr($contact_title) . '">';
        echo '<span class="acasa-mobile-quick-link__icon" aria-hidden="true">';
        echo '<svg viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg" width="1em" height="1em"><path fill="currentColor" d="M13.601 2.326A7.85 7.85 0 0 0 8.017 0C3.676 0 .14 3.534.14 7.873a7.84 7.84 0 0 0 1.066 3.93L0 16l4.307-1.127a7.9 7.9 0 0 0 3.71.945h.003c4.34 0 7.878-3.534 7.878-7.873a7.85 7.85 0 0 0-2.297-5.619zM8.02 14.49h-.002a6.57 6.57 0 0 1-3.35-.92l-.24-.142-2.554.668.682-2.49-.156-.255a6.55 6.55 0 0 1-1.004-3.478c0-3.627 2.96-6.58 6.6-6.58 1.762 0 3.415.684 4.659 1.926a6.54 6.54 0 0 1 1.93 4.656c-.001 3.628-2.962 6.58-6.595 6.58z"/><path fill="currentColor" d="M11.596 9.536c-.199-.1-1.173-.578-1.355-.645-.181-.067-.313-.1-.446.1-.133.2-.512.645-.628.779-.116.133-.232.15-.43.05-.199-.099-.84-.309-1.6-.985-.592-.527-.992-1.178-1.108-1.377-.116-.2-.012-.307.087-.406.09-.09.199-.232.298-.348.1-.116.133-.2.2-.332.066-.133.033-.25-.017-.349-.05-.1-.446-1.073-.611-1.47-.161-.387-.325-.334-.446-.34-.116-.005-.249-.006-.382-.006s-.349.05-.53.249c-.182.2-.695.679-.695 1.654 0 .978.712 1.922.811 2.055.1.133 1.394 2.128 3.38 2.983.472.203.84.324 1.127.415.473.151.904.13 1.244.079.38-.056 1.173-.479 1.339-.941.166-.464.166-.861.116-.944-.049-.083-.181-.133-.38-.232z"/></svg>';
        echo '</span>';
        echo '<span class="screen-reader-text">' . esc_html($contact_title) . '</span>';
        echo '</a>';
    }

    if (!empty($sem['account']['present'])) {
        $account_url = is_string($sem['account']['url']) && $sem['account']['url'] !== '' ? $sem['account']['url'] : home_url('/donatii-online/panou-de-control/');
        $account_title = is_string($sem['account']['title']) && $sem['account']['title'] !== '' ? $sem['account']['title'] : 'Contul meu';
        $account_display = acasa_account_display_payload(20, 'acasa-mobile-account-avatar');
        echo '<a class="acasa-mobile-quick-link acasa-mobile-quick-link--account" href="' . esc_url($account_url) . '" aria-label="' . esc_attr($account_title) . '">';
        echo '<span class="acasa-mobile-quick-link__icon" aria-hidden="true">';
        if (!empty($account_display['is_logged_in']) && !empty($account_display['avatar_html']) && is_string($account_display['avatar_html'])) {
            echo wp_kses_post( $account_display['avatar_html'] );
        } else {
            echo '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" width="1em" height="1em"><path fill="currentColor" d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5zm0 2c-4.42 0-8 2.24-8 5v1h16v-1c0-2.76-3.58-5-8-5z"/></svg>';
        }
        echo '</span>';
        echo '<span class="screen-reader-text">' . esc_html($account_title) . '</span>';
        echo '</a>';
    }

    if (!empty($sem['donate']['present'])) {
        $donate_url = is_string($sem['donate']['url']) && $sem['donate']['url'] !== '' ? $sem['donate']['url'] : home_url('/donatii-online/');
        $donate_title = is_string($sem['donate']['title']) && $sem['donate']['title'] !== '' ? $sem['donate']['title'] : 'Doneaza';
        echo '<a class="acasa-mobile-quick-link acasa-mobile-quick-link--donate" href="' . esc_url($donate_url) . '" aria-label="' . esc_attr($donate_title) . '">';
        echo '<span class="acasa-mobile-quick-link__icon" aria-hidden="true">';
        echo '<svg viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg" width="1em" height="1em"><polygon fill="currentColor" points="426.2 243 312 243 359.1 290 473.4 290 426.2 243"/><polygon fill="currentColor" points="85.8 243 38.6 290 152.8 290 200 243 85.8 243"/><path fill="currentColor" d="M349.6 336.2c-6.1 0-12-2.4-16.3-6.7l-54.2-53.9v204.1h133.5v-143.4h-63.1z"/><path fill="currentColor" d="M178.7 329.5c-4.3 4.3-10.2 6.7-16.3 6.7h-63.1v143.4h133.5v-204.1l-54.2 54z"/><path fill="currentColor" d="M303.1 32.4c-17.8 0-35.6 8.4-47.1 22-11.5-13.6-29.3-22-47.1-22-32.4 0-57.6 25.1-57.6 57.6s35.6 72.3 90 120.4l14.7 12.7 14.7-12.7c54.4-48.1 90-80.6 90-120.4 0-32.4-25.1-57.6-57.5-57.6z"/><line x1="157.3" y1="247.8" x2="232.9" y2="247.8" stroke="currentColor" stroke-miterlimit="10" stroke-width="10"/><line x1="278.7" y1="247.8" x2="354.3" y2="247.8" stroke="currentColor" stroke-miterlimit="10" stroke-width="10"/></svg>';
        echo '</span>';
        echo '<span class="screen-reader-text">' . esc_html($donate_title) . '</span>';
        echo '</a>';
    }
}, 5);

add_action('admin_notices', function (): void {
    global $pagenow;
    if ($pagenow !== 'nav-menus.php' || !current_user_can('edit_theme_options')) {
        return;
    }

    echo '<div class="notice notice-info"><p><strong>ACASA menu semantic classes:</strong> Add CSS classes in <em>Appearance > Menus</em> to control header behavior. '
        . '<code>isContact</code> = contact tool icon, <code>isAccount</code> = account tool icon, <code>isDonate</code> = donate CTA, '
        . '<code>isTool</code> = generic tool item in right-side tool group.</p></div>';
});

/**
 * Add donate styling class to matching primary menu item.
 * This keeps donate styling robust across menu reorders.
 *
 * CHANGE NOTE:
 * - Class added: "acasa-menu-item-donate"
 * - Selector target in CSS: ".main-nav > ul > li.acasa-menu-item-donate > a"
 * - Semantic contract source: user classes in Appearance > Menus
 *   (isContact, isAccount, isDonate, isTool).
 */
add_filter('nav_menu_css_class', function (array $classes, $item, $args, int $depth): array {
    if (!acasa_is_primary_menu_render_context($args)) {
        return $classes;
    }

    if ($depth !== 0 || !is_object($item)) {
        return $classes;
    }

    $roles = acasa_menu_item_roles($item);

    if ($roles['donate'] && !in_array('acasa-menu-item-donate', $classes, true)) {
        $classes[] = 'acasa-menu-item-donate';
    }

    if ($roles['tool'] && !in_array('acasa-menu-item-tool', $classes, true)) {
        $classes[] = 'acasa-menu-item-tool';
    }

    if ($roles['contact'] && !in_array('acasa-menu-item-contact', $classes, true)) {
        $classes[] = 'acasa-menu-item-contact';
    }

    if ($roles['account'] && !in_array('acasa-menu-item-account', $classes, true)) {
        $classes[] = 'acasa-menu-item-account';
    }

    if ($roles['account']) {
        $account_state_class = is_user_logged_in() ? 'acasa-account--logged-in' : 'acasa-account--logged-out';
        if (!in_array($account_state_class, $classes, true)) {
            $classes[] = $account_state_class;
        }
    }

    if (acasa_is_mega_menu_enabled() && !$roles['tool'] && !$roles['donate']) {
        $panel_key = acasa_menu_item_panel_key($item);
        if ($panel_key !== '') {
            $panel_class = 'acasa-panel-key-' . $panel_key;
            if (!in_array($panel_class, $classes, true)) {
                $classes[] = $panel_class;
            }
        }
    }

    return $classes;
}, 10, 4);

/**
 * Add top-level panel key data attributes used by mega-panel interactions.
 */
add_filter('nav_menu_link_attributes', function (array $atts, $item, $args, int $depth): array {
    if (!acasa_is_mega_menu_enabled()) {
        return $atts;
    }

    if (!acasa_is_primary_menu_render_context($args) || $depth !== 0 || !is_object($item)) {
        return $atts;
    }

    $roles = acasa_menu_item_roles($item);
    if ($roles['tool'] || $roles['donate']) {
        return $atts;
    }

    $panel_key = acasa_menu_item_panel_key($item);
    if ($panel_key !== '') {
        $atts['data-acasa-panel-key'] = $panel_key;
    }

    return $atts;
}, 10, 4);

/**
 * Render primary account menu item as:
 * - logged out: icon + "Login"
 * - logged in: local avatar + resolved account label
 *
 * Name label is hidden by CSS when tools collapse.
 */
add_filter('nav_menu_item_title', function (string $title, $item, $args, int $depth): string {
    if (!acasa_is_primary_menu_render_context($args) || $depth !== 0 || !is_object($item)) {
        return $title;
    }

    $roles = acasa_menu_item_roles($item);
    if (!$roles['account']) {
        return $title;
    }

    $account_display = acasa_account_display_payload(30, 'acasa-account-avatar');
    if (empty($account_display['is_logged_in'])) {
        return '<span class="acasa-account-label">Login</span>';
    }

    $avatar_html = (is_string($account_display['avatar_html']) && $account_display['avatar_html'] !== '')
        ? wp_kses_post( $account_display['avatar_html'] )
        : '<span class="acasa-account-avatar-fallback" aria-hidden="true"></span>';

    return
        '<span class="acasa-account-avatar-wrap" aria-hidden="true">' . $avatar_html . '</span>'
        . '<span class="acasa-account-label">' . esc_html((string) $account_display['label']) . '</span>';
}, 10, 4);

/**
 * Anchor the two-zone desktop header layout.
 * - If tools exist, first tool gets the "right zone starts here" class.
 * - Otherwise donate becomes the anchor so it stays right aligned.
 */
add_filter('wp_nav_menu_objects', function (array $items, $args): array {
    if (!acasa_is_primary_menu_render_context($args)) {
        return $items;
    }

    $tool_index = null;
    $donate_index = null;

    foreach ($items as $index => $item) {
        if (!is_object($item) || !isset($item->menu_item_parent) || (int) $item->menu_item_parent !== 0) {
            continue;
        }

        $roles = acasa_menu_item_roles($item);

        if ($tool_index === null && $roles['tool']) {
            $tool_index = $index;
        }
        if ($donate_index === null && $roles['donate']) {
            $donate_index = $index;
        }
    }

    $anchor_index = $tool_index !== null ? $tool_index : $donate_index;
    if ($anchor_index !== null && isset($items[$anchor_index]) && is_object($items[$anchor_index])) {
        $classes = isset($items[$anchor_index]->classes) && is_array($items[$anchor_index]->classes)
            ? $items[$anchor_index]->classes
            : [];
        if (!in_array('acasa-menu-item-right-anchor', $classes, true)) {
            $classes[] = 'acasa-menu-item-right-anchor';
            $items[$anchor_index]->classes = $classes;
        }
    }

    return $items;
}, 30, 2);

/**
 * Ensure donate exists as the final top-level primary nav item.
 * If it's already present in menu data, this does nothing.
 *
 * CHANGE NOTE:
 * - This is a safety fallback for branding/apply/reset workflows.
 * - If menu editors remove donate accidentally, header layout still keeps CTA.
 */
add_filter('wp_nav_menu_items', function (string $items, $args): string {
    if (!acasa_is_primary_menu_render_context($args)) {
        return $items;
    }

    if (strpos($items, '/donatii-online/') !== false || strpos($items, '/donatii-online"') !== false) {
        return $items;
    }

    $donate = sprintf(
        '<li class="menu-item menu-item-type-custom menu-item-object-custom acasa-menu-item-donate"><a href="%s">Doneaza</a></li>',
        esc_url(home_url('/donatii-online/'))
    );

    return $items . $donate;
}, 20, 2);

/* ============================================================================
   ACASA Branding Tool (Option B: DB seeder + snapshot + rollback)
   - ACASA -> Branding
   - Dry run, Apply (safe), Apply (force), Snapshot, Rollback
============================================================================ */

add_action('admin_enqueue_scripts', function (string $hook_suffix): void {
    if (strpos($hook_suffix, 'acasa-branding') !== false) {
        wp_enqueue_media();
    }
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
    $custom_logo_id = absint(get_option('acasa_brand_logo_id', 0));
    $favicon_id = absint(get_option('acasa_brand_favicon_id', 0));
    $theme_mods = [
        'display_header_text' => false,
    ];
    if ($custom_logo_id > 0) {
        $theme_mods['custom_logo'] = $custom_logo_id;
    }

    $options = [
        'generate_settings' => [
                // Palette
                'global_colors' => [
                    [ 'name' => 'contrast',   'slug' => 'contrast',   'color' => '#1C1C2A' ],
                    [ 'name' => 'contrast-2', 'slug' => 'contrast-2', 'color' => '#1C1C2A' ],
                    [ 'name' => 'contrast-3', 'slug' => 'contrast-3', 'color' => '#1C1C2A' ],
                    [ 'name' => 'base',       'slug' => 'base',       'color' => '#FFFBE8' ],
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
                // Accessibility rule: never white text on yellow.
                'form_button_text_color'             => 'var(--contrast)',
                'form_button_text_color_hover'       => 'var(--contrast)',

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
                // - menu/widget theme_mods
                // - 'hide_title'/'hide_tagline' might be content/presentation policy; add later if desired
            ],
    ];
    if ($favicon_id > 0) {
        $options['site_icon'] = $favicon_id;
    }

    return [
        'options' => $options,
        'theme_mods' => $theme_mods,
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
 * Compare values with minimal normalization for WordPress option/theme-mod storage.
 * Avoid false diffs where DB returns numeric strings and target map uses ints.
 */
function acasa_branding_values_equal($from, $to): bool {
    if ($from === $to) {
        return true;
    }

    $from_is_int_like = is_int($from) || (is_string($from) && ctype_digit($from));
    $to_is_int_like = is_int($to) || (is_string($to) && ctype_digit($to));
    if ($from_is_int_like && $to_is_int_like) {
        return (int) $from === (int) $to;
    }

    return false;
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
            if (!acasa_branding_values_equal($from, $to)) {
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
            if (!acasa_branding_values_equal($from, $to)) {
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
        if (!acasa_branding_values_equal($from, $to)) {
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
    $retina_logo_id = absint(get_option('acasa_brand_logo_retina_id', 0));
    if ($retina_logo_id > 0) {
        $retina_logo_url = wp_get_attachment_url($retina_logo_id);
        if (is_string($retina_logo_url) && $retina_logo_url !== '') {
            if (!isset($target['options']['generate_settings']) || !is_array($target['options']['generate_settings'])) {
                $target['options']['generate_settings'] = [];
            }
            $target['options']['generate_settings']['retina_logo'] = $retina_logo_url;
        }
    }

    // Compute plan first, so we can report what changed.
    $plan = acasa_compute_plan($target);

    // Apply options
    foreach (($target['options'] ?? []) as $option_name => $desired_pairs) {
        if (!is_array($desired_pairs)) {
            $current_opt = get_option($option_name, null);
            if ($force || !acasa_branding_values_equal($current_opt, $desired_pairs)) {
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
            if ($force || !acasa_branding_values_equal($from, $to)) {
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
        if ($force || !acasa_branding_values_equal($from, $to)) {
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

        $action = sanitize_text_field(wp_unslash($_POST['acasa_branding_action']));

        try {
            if ($action === 'save_logo_ids') {
                $logo_id        = isset($_POST['acasa_brand_logo_id']) ? absint($_POST['acasa_brand_logo_id']) : 0;
                $logo_retina_id = isset($_POST['acasa_brand_logo_retina_id']) ? absint($_POST['acasa_brand_logo_retina_id']) : 0;
                $favicon_id     = isset($_POST['acasa_brand_favicon_id']) ? absint($_POST['acasa_brand_favicon_id']) : 0;
                update_option('acasa_brand_logo_id', $logo_id);
                update_option('acasa_brand_logo_retina_id', $logo_retina_id);
                update_option('acasa_brand_favicon_id', $favicon_id);
                $notice = 'Logo settings saved.';
            } elseif ($action === 'snapshot') {
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
        $target = acasa_branding_target_map();
        $plan = acasa_compute_plan($target);
    }

    $seed_version = intval(get_option('acasa_branding_seed_version', 0));
    $snap_at      = get_option('acasa_branding_snapshot_at', '');
    $logo_id      = absint(get_option('acasa_brand_logo_id', 0));
    $retina_id    = absint(get_option('acasa_brand_logo_retina_id', 0));
    $favicon_id   = absint(get_option('acasa_brand_favicon_id', 0));
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

    echo '<h2>Logo enforcement</h2>';
    echo '<p>Select Media Library images to enforce as logo settings.</p>';
    echo '<table class="form-table" role="presentation" style="max-width:1200px"><tbody>';

    echo '<tr>';
    echo '<th scope="row"><label for="acasa_brand_logo_id">Standard logo</label></th>';
    echo '<td>';
    echo '<input type="hidden" id="acasa_brand_logo_id" name="acasa_brand_logo_id" value="' . esc_attr((string)$logo_id) . '">';
    echo '<button type="button" class="button" id="acasa-pick-logo">Select image</button> ';
    echo '<button type="button" class="button" id="acasa-clear-logo">Clear</button>';
    echo '<p><strong>Attachment ID:</strong> <span id="acasa-logo-id-text">' . esc_html((string)$logo_id) . '</span></p>';
    echo '<div id="acasa-logo-preview">';
    if ($logo_id > 0) {
        echo wp_get_attachment_image($logo_id, 'thumbnail', false, ['style' => 'max-width:120px;height:auto;']);
    }
    echo '</div>';
    echo '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th scope="row"><label for="acasa_brand_logo_retina_id">Retina logo (optional)</label></th>';
    echo '<td>';
    echo '<input type="hidden" id="acasa_brand_logo_retina_id" name="acasa_brand_logo_retina_id" value="' . esc_attr((string)$retina_id) . '">';
    echo '<button type="button" class="button" id="acasa-pick-retina-logo">Select image</button> ';
    echo '<button type="button" class="button" id="acasa-clear-retina-logo">Clear</button>';
    echo '<p><strong>Attachment ID:</strong> <span id="acasa-retina-id-text">' . esc_html((string)$retina_id) . '</span></p>';
    echo '<div id="acasa-retina-preview">';
    if ($retina_id > 0) {
        echo wp_get_attachment_image($retina_id, 'thumbnail', false, ['style' => 'max-width:120px;height:auto;']);
    }
    echo '</div>';
    echo '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th scope="row"><label for="acasa_brand_favicon_id">Favicon (site icon)</label></th>';
    echo '<td>';
    echo '<input type="hidden" id="acasa_brand_favicon_id" name="acasa_brand_favicon_id" value="' . esc_attr((string)$favicon_id) . '">';
    echo '<button type="button" class="button" id="acasa-pick-favicon">Select image</button> ';
    echo '<button type="button" class="button" id="acasa-clear-favicon">Clear</button>';
    echo '<p><strong>Attachment ID:</strong> <span id="acasa-favicon-id-text">' . esc_html((string)$favicon_id) . '</span></p>';
    echo '<div id="acasa-favicon-preview">';
    if ($favicon_id > 0) {
        echo wp_get_attachment_image($favicon_id, 'thumbnail', false, ['style' => 'max-width:120px;height:auto;']);
    }
    echo '</div>';
    echo '</td>';
    echo '</tr>';

    echo '</tbody></table>';
    echo '<p><button class="button" name="acasa_branding_action" value="save_logo_ids" type="submit">Save logo settings</button></p>';

    echo '<button class="button" name="acasa_branding_action" value="snapshot" type="submit">Create snapshot</button> ';
    echo '<button class="button" name="acasa_branding_action" value="rollback" type="submit" ';
    echo 'onclick="return confirm(\'Rollback will restore the last snapshot. Continue?\')">Rollback to snapshot</button> ';

    echo '<button class="button button-primary" name="acasa_branding_action" value="apply_safe" type="submit" ';
    echo 'onclick="return confirm(\'This will store a snapshot, then apply branding changes (safe). Continue?\')">Apply branding (safe)</button> ';

    echo '<button class="button button-secondary" name="acasa_branding_action" value="apply_force" type="submit" ';
    echo 'style="margin-left:8px" ';
    echo 'onclick="return confirm(\'DANGER: This will store a snapshot, then overwrite targeted keys (force). Continue?\')">Apply branding (force)</button>';

    echo '<script>';
    echo '(function($){';
    echo 'function bindPicker(buttonSel, clearSel, inputSel, textSel, previewSel){';
    echo 'var frame;';
    echo '$(buttonSel).on("click", function(e){e.preventDefault();';
    echo 'if(frame){frame.open();return;}';
    echo 'frame = wp.media({title:"Select logo image",button:{text:"Use this image"},multiple:false,library:{type:"image"}});';
    echo 'frame.on("select", function(){';
    echo 'var attachment = frame.state().get("selection").first().toJSON();';
    echo '$(inputSel).val(attachment.id || "0");';
    echo '$(textSel).text(String(attachment.id || 0));';
    echo 'var thumb = (attachment.sizes && attachment.sizes.thumbnail && attachment.sizes.thumbnail.url) ? attachment.sizes.thumbnail.url : attachment.url;';
    echo 'if(thumb){$(previewSel).empty().append($("<img>",{src:String(thumb),style:"max-width:120px;height:auto;",alt:""}));}';
    echo '});';
    echo 'frame.open();';
    echo '});';
    echo '$(clearSel).on("click", function(e){e.preventDefault();$(inputSel).val("0");$(textSel).text("0");$(previewSel).empty();});';
    echo '}';
    echo 'bindPicker("#acasa-pick-logo","#acasa-clear-logo","#acasa_brand_logo_id","#acasa-logo-id-text","#acasa-logo-preview");';
    echo 'bindPicker("#acasa-pick-retina-logo","#acasa-clear-retina-logo","#acasa_brand_logo_retina_id","#acasa-retina-id-text","#acasa-retina-preview");';
    echo 'bindPicker("#acasa-pick-favicon","#acasa-clear-favicon","#acasa_brand_favicon_id","#acasa-favicon-id-text","#acasa-favicon-preview");';
    echo '})(jQuery);';
    echo '</script>';

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
    echo esc_textarea($export_json);
    echo '</textarea>';

    echo '<p style="margin-top:12px"><em>Note:</em> After applying branding on a cached site, you may need to purge page/CSS caches (Autoptimize, Cloudflare, server cache) to see changes immediately.</p>';

    echo '</div>';
}

add_action('admin_notices', function (): void {
    if (!current_user_can('activate_plugins')) {
        return;
    }

    if (function_exists('acasa_current_user_is_donor')) {
        return;
    }

    echo '<div class="notice notice-warning"><p><strong>ACASA Child:</strong> Please activate <code>ACASA Donor Access</code> to enable donor account flows, avatar sync, and donor tools.</p></div>';
});

/* ── [acasa_copy] shortcode — click-to-copy inline field ── */
add_shortcode('acasa_copy', function ($atts) {
    $a = shortcode_atts(['value' => ''], $atts);
    $v = esc_attr($a['value']);
    $id = 'acasa-copy-' . wp_unique_id();
    return '<span class="acasa-copy-field" id="' . $id . '" style="display:inline-flex;align-items:center;gap:8px;background:#f5f5f5;border:2px solid var(--accent-3,#FCD602);border-radius:var(--r-btn,8px);padding:8px 14px;font-family:monospace;font-size:0.95em;cursor:pointer;user-select:all;transition:background .15s" onmouseenter="this.style.background=\'#fff\'" onmouseleave="this.style.background=\'#f5f5f5\'" onclick="navigator.clipboard.writeText(\'' . $v . '\');var s=this.querySelector(\'em\');s.textContent=\'Copiat!\';setTimeout(function(){s.textContent=\'Copiază\'},1500)" title="Copiază">'
        . '<strong>' . esc_html($a['value']) . '</strong>'
        . '<em style="font-style:normal;font-family:sans-serif;font-size:0.8em;color:#888">Copiază</em>'
        . '</span>';
});

/* ==========================================================================
   GiveWP iframe form styles — brand fonts + contrast-safe colors
   --------------------------------------------------------------------------
   GiveWP donation forms render inside an iframe with isolated CSS.
   The only way to inject theme styles is via the custom_form_styles option,
   which GiveWP exposes through the give_get_option_custom_form_styles filter.
   We load Barlow/Inter from the GP Font Library and override the CSS vars
   to ensure dark-on-yellow button text (no white-on-yellow anywhere).
   ========================================================================== */
add_filter('give_get_option_custom_form_styles', function ($css) {
    $font_base = '/wp-content/uploads/generatepress/fonts';
    return $css . <<<GIVECSS

/* ── ACASA brand fonts (loaded from GP Font Library) ── */
@font-face {
    font-display: swap;
    font-family: "Barlow";
    font-style: normal;
    font-weight: 600;
    src: url('{$font_base}/barlow/7cHqv4kjgoGqM7E30-8c5VAtlT47dw.woff2') format('woff2');
}
@font-face {
    font-display: swap;
    font-family: "Barlow";
    font-style: normal;
    font-weight: 700;
    src: url('{$font_base}/barlow/7cHqv4kjgoGqM7E3t-4c5VAtlT47dw.woff2') format('woff2');
}
@font-face {
    font-display: swap;
    font-family: "Barlow";
    font-style: normal;
    font-weight: 800;
    src: url('{$font_base}/barlow/7cHqv4kjgoGqM7E3q-0c5VAtlT47dw.woff2') format('woff2');
}
@font-face {
    font-display: swap;
    font-family: "Inter";
    font-style: normal;
    font-weight: 100 900;
    src: url('{$font_base}/inter/UcC73FwrK3iLTeHuS_nVMrMxCp50SjIa1ZL7W0I5nvwU.woff2') format('woff2');
}

/* ── Contrast-safe color overrides (dark text on yellow buttons) ── */
:root:not([data-theme=dark]), [data-theme=light] {
    --primary-inverse: #1C1C2A;
    --primary-hover: #E8C400;
    --primary-focus: rgba(252, 214, 2, 0.2);
}

/* ── Force dark text on every yellow-background interactive element ── */

/* Frequency toggle: selected tab ("O singură dată" / "Lunar") */
[aria-checked="true"],
[data-testid*="billing-period"][aria-pressed="true"],
[role="tab"][aria-selected="true"],
input[type="radio"]:checked + label,
button[aria-current="true"] {
    color: #1C1C2A !important;
}

/* Amount selector: selected amount ("RON 200.00") */
[aria-pressed="true"],
[data-testid*="amount"][aria-current="true"],
.givewp-fields-amount--selected,
button.givewp-fields-amount--selected {
    color: #1C1C2A !important;
}

/* All buttons — dark text on yellow backgrounds */
button,
input[type="submit"],
[role="button"] {
    color: #1C1C2A !important;
}
/* GiveWP back-arrow button: icon-only by design, hide text */
.givewp-donation-form__steps-header-previous-button {
    font-size: 0 !important;
    color: transparent !important;
}

/* Final submit button only — full CTA styling */
button[type="submit"],
input[type="submit"] {
    font-weight: 700 !important;
    letter-spacing: 0.06em !important;
    text-transform: uppercase !important;
    border-radius: 8px !important;
    border: none !important;
    transition: filter 0.16s ease, transform 0.12s ease !important;
}
button[type="submit"]:hover,
input[type="submit"]:hover {
    filter: brightness(1.06) !important;
    transform: translateY(-2px) !important;
}
button[type="submit"]:active,
input[type="submit"]:active {
    filter: brightness(0.95) !important;
    transform: translateY(0) !important;
}

/* ── Brand typography ──
   @font-face names above are unavoidable (iframe has no parent CSS access),
   but we set font-family ONCE here. If brand fonts change, update the
   @font-face blocks + these two lines only. */
body, .givewp-donation-form {
    font-family: 'Inter', system-ui, sans-serif;
}
h1, h2, h3, h4, h5, h6,
button, input[type=submit], [role=button] {
    font-family: 'Barlow', 'Inter', system-ui, sans-serif;
}

GIVECSS;
}, 10, 1);
