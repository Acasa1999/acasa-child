<?php
/**
 * ACASA Child theme functions
 * Managed via Git + Codex.
 *
 * Admin-only tools (branding, menu editor, GP Elements seed) live in the
 * acasa-admin-tools plugin. This file contains only frontend + shared code.
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
 * Mega-panel assets manifest.
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
 */
function acasa_to_root_relative_url(string $url): string {
    if ($url === '') {
        return $url;
    }

    $path = wp_parse_url($url, PHP_URL_PATH);
    $query = wp_parse_url($url, PHP_URL_QUERY);
    $fragment = wp_parse_url($url, PHP_URL_FRAGMENT);

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

/* ============================================================================
   Menu layout editor content wiring.
   ============================================================================ */
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

function acasa_primary_navigation_preferred_layout_post_type(): string {
    return post_type_exists('gp_elements') ? 'gp_elements' : 'page';
}

function acasa_primary_navigation_allowed_layout_post_types(): array {
    return ['gp_elements', 'page'];
}

function acasa_primary_navigation_find_layout_post_id(string $post_type): int {
    if ($post_type === '' || !post_type_exists($post_type)) {
        return 0;
    }
    $existing = get_page_by_path(ACASA_PRIMARY_NAV_LAYOUT_SLUG, OBJECT, $post_type);
    return $existing instanceof WP_Post ? (int) $existing->ID : 0;
}

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

        $roles = acasa_menu_item_roles($menu_item);
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

function acasa_primary_navigation_block_unique_id(string $seed, string $prefix = 'acm'): string {
    $normalized_prefix = sanitize_key($prefix);
    if ($normalized_prefix === '') {
        $normalized_prefix = 'acm';
    }
    $hash = substr(md5($seed), 0, 8);
    return substr($normalized_prefix . $hash, 0, 12);
}

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

function acasa_primary_navigation_has_mega_shell(string $content): bool {
    return strpos($content, 'acasa-mega-shell') !== false;
}

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
 * Called from admin tools plugin, but defined here because it uses
 * many layout functions that are also needed on frontend.
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
 * Replace GP primary menu markup with a GenerateBlocks Navigation block render.
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

/* ============================================================================
   Menu semantic role system.
   ============================================================================ */

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
 * Mobile header quick-action icons.
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

/* ============================================================================
   Nav menu filters — CSS classes, link attributes, item titles, anchoring.
   ============================================================================ */

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
   [acasa_copy] shortcode — click-to-copy inline field.
   ============================================================================ */
add_shortcode('acasa_copy', function ($atts) {
    $a = shortcode_atts(['value' => ''], $atts);
    $v = esc_js($a['value']);
    $id = 'acasa-copy-' . wp_unique_id();
    return '<span class="acasa-copy-field" id="' . $id . '" style="display:inline-flex;align-items:center;gap:8px;background:#f5f5f5;border:2px solid var(--accent-3,#FCD602);border-radius:var(--r-btn,8px);padding:8px 14px;font-family:monospace;font-size:0.95em;cursor:pointer;user-select:all;transition:background .15s" onmouseenter="this.style.background=\'#fff\'" onmouseleave="this.style.background=\'#f5f5f5\'" onclick="navigator.clipboard.writeText(\'' . $v . '\');var s=this.querySelector(\'em\');s.textContent=\'Copiat!\';setTimeout(function(){s.textContent=\'Copiază\'},1500)" title="Copiază">'
        . '<strong>' . esc_html($a['value']) . '</strong>'
        . '<em style="font-style:normal;font-family:sans-serif;font-size:0.8em;color:#888">Copiază</em>'
        . '</span>';
});

/* ============================================================================
   GiveWP iframe form styles — brand fonts + contrast-safe colors.
   ============================================================================ */
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

/* Frequency toggle: selected tab */
[aria-checked="true"],
[data-testid*="billing-period"][aria-pressed="true"],
[role="tab"][aria-selected="true"],
input[type="radio"]:checked + label,
button[aria-current="true"] {
    color: #1C1C2A !important;
}

/* Amount selector: selected amount */
[aria-pressed="true"],
[data-testid*="amount"][aria-current="true"],
.givewp-fields-amount--selected,
button.givewp-fields-amount--selected {
    color: #1C1C2A !important;
}

/* All buttons — dark text on yellow backgrounds (exclude back-button) */
button:not(.givewp-donation-form__steps-header-previous-button),
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

/* ── Brand typography ── */
body, .givewp-donation-form {
    font-family: 'Inter', system-ui, sans-serif;
}
h1, h2, h3, h4, h5, h6,
button, input[type=submit], [role=button] {
    font-family: 'Barlow', 'Inter', system-ui, sans-serif;
}

GIVECSS;
}, 10, 1);
