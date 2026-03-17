# Plugin Consolidation Design

**Date:** 2026-03-16
**Status:** Approved

## Goal

Consolidate 4 custom plugins + 2 diagnostic mu-plugins into 2 plugins + 1 mu-plugin. Reduce admin menu fragmentation, simplify deployment, and establish a shared ACASA menu pattern.

## Before → After

### Plugins

| Before | After |
|--------|-------|
| acasa-admin-tools (admin branding/menu/settings) | **ACASA Tools** v1.5 |
| acasa-data-tools (GiveWP donor integrity) | → merged into ACASA Tools |
| acasa-content-migrator (page export/import) | → merged into ACASA Tools |
| acasa-donor-access (donor accounts/avatar/gating) | **ACASA Donor Access** v1.5 (standalone) |

### mu-plugins

| Before | After |
|--------|-------|
| acasa-avatar-diagnostics.php | **acasa-diagnostics.php** (merged) |
| acasa-mail-diagnostics.php | → merged into acasa-diagnostics.php |
| acasa-euplatesc-renewal-amount-fix.php | unchanged |
| local-mail-override.php | unchanged |

## Shared ACASA Admin Menu

Both plugins independently ensure the ACASA parent menu exists. Pattern used by both at priority 9:

```php
add_action('admin_menu', function () {
    global $menu;
    foreach ((array) $menu as $item) {
        if (($item[2] ?? '') === 'acasa-admin') return;
    }
    add_menu_page('ACASA', 'ACASA', 'manage_options', 'acasa-admin', '__return_null', 'dashicons-admin-home', 58);
}, 9);
```

Each plugin then registers its submenus at normal priority. No load-order dependency between plugins. Constant `ACASA_ADMIN_MENU_SLUG` defined by whichever loads first (guarded by `defined()` check).

## ACASA Donor Access (v1.5)

**Scope:** Frontend + admin. Standalone — no dependency on ACASA Tools.

### File structure

```
plugins/acasa-donor-access/
  acasa-donor-access.php          Entry point, shared menu creation, loads includes
  includes-donor-access.php       Core logic (~1,718 lines, unchanged)
  includes-hooks-admin.php        Admin hooks (updated: shared menu, simplified registration)
  includes-hooks-front.php        Front hooks (unchanged)
  changelog.md
```

### Changes from current

- Add shared ACASA menu creation at priority 9 in entry point
- Define `ACASA_ADMIN_MENU_SLUG` if not already defined
- Bump version to 1.5
- Add changelog.md
- **Simplify `acasa_register_sync_donors_admin_page()`:** remove `acasa_admin_parent_exists()` gate — the shared menu pattern guarantees the parent exists at `admin_menu` time. Always register under `ACASA_ADMIN_MENU_SLUG`.
- **Sync Donors ownership:** Donor Access owns the "Sync Donors" submenu. The duplicate registration in data-tools is removed during merge into ACASA Tools (see below). The `class_exists('Acasa_Data_Tools')` guard in `includes-hooks-admin.php` is removed — Donor Access always registers its own page.

## ACASA Tools (v1.5)

**Scope:** Admin-only. `if (!is_admin()) return;` guard in entry point.

### File structure

```
plugins/acasa-tools/
  acasa-tools.php                 Entry point, constants, shared menu, activation hook, module loader
  modules/
    admin-tools.php               Branding, settings, menu element (from acasa-admin-tools.php)
    data-tools.php                GiveWP donor tools main class (from acasa-data-tools.php)
    data-tools/                   Subclasses: includes/, views/ (from acasa-data-tools/)
    content-migrator.php          Page export/import (from acasa-content-migrator.php)
  changelog.md
```

### Entry point (acasa-tools.php)

1. Plugin header: `Plugin Name: ACASA Tools`, `Version: 1.5`
2. `if (!is_admin() && !wp_doing_ajax()) return;` (data-tools has AJAX endpoints)
3. Define all shared constants (guarded by `defined()`):
   - `ACASA_ADMIN_MENU_SLUG` = `'acasa-admin'`
   - `ACASA_MEGA_PANELS_ENABLED_OPTION` = `'acasa_mega_panels_enabled'`
   - `ACASA_PRIMARY_NAV_LAYOUT_SLUG` = value from current admin-tools
   - `ACASA_PRIMARY_NAV_LAYOUT_TITLE` = value from current admin-tools
   - `ACASA_PRIMARY_NAV_LAYOUT_OPTION` = value from current admin-tools
   - `ACASA_PRIMARY_NAV_LAYOUT_SIGNATURE_KEY` = value from current admin-tools
   - `ACASA_PRIMARY_NAV_LAYOUT_SIGNATURE_VALUE` = value from current admin-tools
4. Define `ACASA_DT_PATH` pointing to `plugin_dir_path(__FILE__) . 'modules/data-tools/'`
5. Register shared ACASA menu at priority 9
6. Register activation hook on `__FILE__` (calls `Acasa_Data_Tools::on_activation()`)
7. Load modules:
   - `modules/admin-tools.php` — always (branding/settings/menu)
   - `modules/data-tools.php` — always (registers own GiveWP check)
   - `modules/content-migrator.php` — always

### Module changes

Each module has its `add_menu_page()` / menu creation code removed. They only call `add_submenu_page(ACASA_ADMIN_MENU_SLUG, ...)` at their original priorities.

**admin-tools.php:**
- Remove lines 37-57 (constant definitions — moved to entry point)
- Remove lines 79-88 (add_menu_page call)
- Keep submenu registrations (Settings, Menu Element, Branding)
- Remove the existing "ACASA Donor Access not installed" admin notice (lines ~857-866) — this responsibility moves to the child theme's `functions.php`
- Remove `class_exists('Acasa_Data_Tools')` and `function_exists('acasa_render_sync_donors_page')` conditional UI guards in Settings page — in the merged plugin these are always true, so simplify to unconditional rendering
- Keep all branding/snapshot/rollback logic unchanged

**data-tools.php:**
- Remove `ACASA_DT_PATH` definition (moved to entry point)
- Remove `admin_parent_exists()` check and `tools.php` fallback — always use `ACASA_ADMIN_MENU_SLUG`
- Remove `register_activation_hook(__FILE__, ...)` (moved to entry point)
- Remove the "Donors: Sync" submenu registration — Donor Access owns this page
- Keep singleton pattern, AJAX endpoints, all view/include requires unchanged (they use `ACASA_DT_PATH` which now resolves to `modules/data-tools/`)

**content-migrator.php:**
- Remove `resolve_parent_slug()` method and `admin_parent_exists()`
- Always use `ACASA_ADMIN_MENU_SLUG`
- Keep export/import handlers unchanged

### Admin submenu layout

Under ACASA menu:
1. Settings (admin-tools)
2. Menu Element (admin-tools)
3. Branding (admin-tools)
4. Donors: Email Blocks (data-tools)
5. Data Integrity (data-tools)
6. Donors: Quick Fix (data-tools)
7. Donors: Health (data-tools)
8. Donors: Sync (donor-access — always, regardless of which plugins are active)
9. Content Migrator (content-migrator)

## Child Theme: Donor Access Dependency Notice

In `functions.php`, add on `admin_notices`:

```php
add_action('admin_notices', function () {
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if (is_plugin_active('acasa-donor-access/acasa-donor-access.php')) return;
    echo '<div class="notice notice-warning"><p><strong>ACASA Donor Access</strong> nu este instalat sau activat. Donatorii nu vor primi conturi WordPress automat și nu vor avea acces la Donor Dashboard.</p></div>';
});
```

Non-dismissible. Shows on every admin page until plugin is activated. This is the single source for this notice — the old notice in `acasa-admin-tools.php` is removed during module migration.

## mu-plugins Consolidation

### acasa-diagnostics.php

Merge avatar diagnostics + mail diagnostics into one file:
- Single shared `acasa_diag_should_run()` function at the top
- One early-return: `if (!acasa_diag_should_run()) return;`
- Host guard: only runs on `q.asociatiaacasa.ro`, `asociatiaacasa.ro`, `www.asociatiaacasa.ro`
- After the guard, all hooks are registered unconditionally (no per-section bare returns)
- Section 1: Avatar diagnostics (upload prefilter, REST request logging)
- Section 2: Mail diagnostics (wp_mail filter, phpmailer_init, success/failure logging)
- Combined ~350 lines

### Unchanged

- `acasa-euplatesc-renewal-amount-fix.php` — stays separate (surgical fix, different concern)
- `local-mail-override.php` — stays separate (local-only, different guard pattern)

## Migration Steps

1. Build `acasa-tools/` and `acasa-diagnostics.php`
2. Update `acasa-donor-access/` with shared menu pattern
3. Update child theme `functions.php` with dependency notice
4. Deactivate old plugins first: `acasa-admin-tools`, `acasa-data-tools`, `acasa-content-migrator`
5. Activate new plugins: `acasa-tools`, updated `acasa-donor-access`
6. Test locally: verify all submenus appear, verify donor access works, verify AJAX endpoints
7. Replace old mu-plugins: delete `acasa-avatar-diagnostics.php` + `acasa-mail-diagnostics.php`, add `acasa-diagnostics.php`
8. Deploy to Q

**Note:** During step 4→5 transition, there is a brief window where the ACASA menu disappears. This is expected — deactivate old and activate new in the same admin session.

## Risks

- **Data Tools activation hook** creates a DB table (`donor_health_audit`). The activation hook moves to `acasa-tools.php` entry point. Since we're activating a new plugin slug, WordPress fires it fresh — table creation runs (harmless if table exists, uses `CREATE TABLE IF NOT EXISTS`).
- **Option references** — all `wp_options` keys used by admin-tools and data-tools stay the same. No migration needed.
- **AJAX action names** — unchanged. All `wp_ajax_acasa_*` handlers keep their names.
- **`ACASA_DT_PATH` resolution** — defined in entry point as `plugin_dir_path(__FILE__) . 'modules/data-tools/'`. All existing `require ACASA_DT_PATH . 'views/...'` and `require ACASA_DT_PATH . 'includes/...'` will resolve correctly because the views/ and includes/ directories move to `modules/data-tools/`.
