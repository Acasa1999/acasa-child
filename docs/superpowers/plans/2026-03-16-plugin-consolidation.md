# Plugin Consolidation Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Consolidate 4 custom plugins + 2 diagnostic mu-plugins into 2 plugins + 1 mu-plugin, reducing admin menu fragmentation and simplifying deployment.

**Architecture:** Three workstreams: (1) Build `acasa-tools/` by merging admin-tools, data-tools, and content-migrator into a single plugin with a module loader; (2) Update `acasa-donor-access/` with shared ACASA menu pattern and simplified hook registration; (3) Merge two diagnostic mu-plugins into one with a shared guard function. Both plugins independently ensure the ACASA parent menu exists at priority 9, so neither depends on the other's load order.

**Tech Stack:** WordPress 6.x, PHP 8.0+, GiveWP v2+

**Spec:** `docs/superpowers/specs/2026-03-16-plugin-consolidation-design.md`

---

## File Structure

### New: `plugins/acasa-tools/`

| File | Responsibility |
|------|---------------|
| `acasa-tools.php` | Entry point: plugin header, admin guard, constants, shared menu at priority 9, activation hook, module loader |
| `modules/admin-tools.php` | From `acasa-admin-tools.php` minus constants, menu creation, and donor notice |
| `modules/data-tools.php` | From `acasa-data-tools.php` minus `ACASA_DT_PATH`, activation hook, `admin_parent_exists()`, Sync Donors submenu |
| `modules/data-tools/includes/*.php` | Unchanged from `acasa-data-tools/includes/` (4 class files) |
| `modules/data-tools/views/*.php` | Unchanged from `acasa-data-tools/views/` (4 view files) |
| `modules/content-migrator.php` | From `acasa-content-migrator.php` minus `resolve_parent_slug()` and `admin_parent_exists()` |
| `changelog.md` | Version history |

### Modified: `plugins/acasa-donor-access/`

| File | Change |
|------|--------|
| `acasa-donor-access.php` | Add shared menu creation at priority 9, define `ACASA_ADMIN_MENU_SLUG`, bump version to 1.5 |
| `includes-hooks-admin.php` | Remove `class_exists('Acasa_Data_Tools')` gate — always register Sync Donors menu + AJAX |
| `changelog.md` | New file |

### New: `mu-plugins/acasa-diagnostics.php`

Single file merging avatar + mail diagnostics with shared `acasa_diag_should_run()` guard.

### Modified: `themes/acasa-child/functions.php`

Add `admin_notices` hook for Donor Access dependency check.

---

## Chunk 1: ACASA Tools Plugin

### Task 1: Create entry point (`acasa-tools.php`)

**Files:**
- Create: `plugins/acasa-tools/acasa-tools.php`

- [ ] **Step 1: Create the entry point file**

```php
<?php
/**
 * Plugin Name: ACASA Tools
 * Description: Admin tools for ACASA — branding, menu editor, GiveWP data tools, content migrator. Admin-only.
 * Version:     1.5
 * Author:      ACASA Dev
 * Requires PHP: 8.0
 * License:     GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

// Admin-only (allow AJAX for data-tools endpoints + WP-CLI for donor health).
$is_admin = is_admin();
$is_ajax  = function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();
$is_cli   = defined( 'WP_CLI' ) && WP_CLI;
if ( ! $is_admin && ! $is_ajax && ! $is_cli ) {
    return;
}

/* ── Constants (guarded — child theme or Donor Access may define first) ── */
if ( ! defined( 'ACASA_ADMIN_MENU_SLUG' ) ) {
    define( 'ACASA_ADMIN_MENU_SLUG', 'acasa-admin' );
}
if ( ! defined( 'ACASA_MEGA_PANELS_ENABLED_OPTION' ) ) {
    define( 'ACASA_MEGA_PANELS_ENABLED_OPTION', 'acasa_mega_panels_enabled' );
}
if ( ! defined( 'ACASA_PRIMARY_NAV_LAYOUT_SLUG' ) ) {
    define( 'ACASA_PRIMARY_NAV_LAYOUT_SLUG', 'acasa-menu-layout-editor' );
}
if ( ! defined( 'ACASA_PRIMARY_NAV_LAYOUT_TITLE' ) ) {
    define( 'ACASA_PRIMARY_NAV_LAYOUT_TITLE', 'ACASA Menu Layout (Element)' );
}
if ( ! defined( 'ACASA_PRIMARY_NAV_LAYOUT_OPTION' ) ) {
    define( 'ACASA_PRIMARY_NAV_LAYOUT_OPTION', 'acasa_primary_nav_layout_post_id' );
}
if ( ! defined( 'ACASA_PRIMARY_NAV_LAYOUT_SIGNATURE_KEY' ) ) {
    define( 'ACASA_PRIMARY_NAV_LAYOUT_SIGNATURE_KEY', '_acasa_primary_nav_layout_signature' );
}
if ( ! defined( 'ACASA_PRIMARY_NAV_LAYOUT_SIGNATURE_VALUE' ) ) {
    define( 'ACASA_PRIMARY_NAV_LAYOUT_SIGNATURE_VALUE', 'acasa-primary-nav-layout-v1' );
}
if ( ! defined( 'ACASA_DT_PATH' ) ) {
    define( 'ACASA_DT_PATH', plugin_dir_path( __FILE__ ) . 'modules/data-tools/' );
}

/* ── Shared ACASA admin menu (priority 9, idempotent) ── */
add_action( 'admin_menu', function () {
    global $menu;
    foreach ( (array) $menu as $item ) {
        if ( ( $item[2] ?? '' ) === 'acasa-admin' ) {
            return;
        }
    }
    add_menu_page( 'ACASA', 'ACASA', 'manage_options', 'acasa-admin', '__return_null', 'dashicons-admin-home', 58 );
}, 9 );

/* ── Activation hook (data-tools audit table) ── */
register_activation_hook( __FILE__, function () {
    // Load data-tools classes if not yet loaded.
    $health_file = plugin_dir_path( __FILE__ ) . 'modules/data-tools/includes/class-donor-health.php';
    if ( file_exists( $health_file ) && ! class_exists( 'Acasa_Donor_Health' ) ) {
        require_once $health_file;
    }
    if ( class_exists( 'Acasa_Donor_Health' ) ) {
        try {
            Acasa_Donor_Health::create_audit_table();
        } catch ( \Throwable $e ) {
            error_log( 'ACASA Tools activation: ' . $e->getMessage() );
        }
    }
} );

/* ── Load modules ── */
require_once __DIR__ . '/modules/admin-tools.php';
require_once __DIR__ . '/modules/data-tools.php';
require_once __DIR__ . '/modules/content-migrator.php';
```

- [ ] **Step 2: PHP lint the entry point**

Run: `"$PHP" -l "W:/www/acasa/app/public/wp-content/plugins/acasa-tools/acasa-tools.php"`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit entry point**

```bash
git add plugins/acasa-tools/acasa-tools.php
git commit -m "feat(acasa-tools): add entry point with constants, shared menu, activation hook"
```

### Task 2: Create `modules/admin-tools.php`

**Files:**
- Source: `plugins/acasa-admin-tools/acasa-admin-tools.php` (lines 1–982)
- Create: `plugins/acasa-tools/modules/admin-tools.php`

- [ ] **Step 1: Copy source file as starting point**

```bash
cp "plugins/acasa-admin-tools/acasa-admin-tools.php" "plugins/acasa-tools/modules/admin-tools.php"
```

- [ ] **Step 2: Remove plugin header, guards, and child theme check (lines 1–32)**

Replace the entire top section — plugin header (lines 1–10), ABSPATH guard (lines 12–14), `is_admin()` guard (lines 19–21), and child theme active guard (lines 23–32, the `admin_init` hook checking `wp_get_theme()`) — with a simple ABSPATH guard. The admin-only and child theme guards are unnecessary: the entry point already guards admin context, and the merged plugin doesn't need to verify the child theme.

```php
<?php
/**
 * ACASA Tools — Admin Tools module
 * Branding, settings, menu editor, GP Elements seed.
 * Loaded by acasa-tools.php entry point (admin-only context guaranteed).
 */

defined( 'ABSPATH' ) || exit;
```

- [ ] **Step 3: Remove constant definitions (lines 37–57)**

Delete the 7 `if (!defined(...))` blocks — these are now in the entry point.

- [ ] **Step 4: Remove `add_menu_page()` call (lines 79–88)**

Delete the `add_menu_page(...)` block. Keep the three `add_submenu_page()` calls (Settings, Menu Element, Branding) — but wrap them in a new `admin_menu` action at normal priority (not inside the old one that also created the menu):

The existing `add_action('admin_menu', function(): void {` (line 79) should be kept but its body should only contain the three `add_submenu_page()` calls. The `add_menu_page()` call on lines 80–88 should be removed. The callback for the Settings page changes from `ACASA_ADMIN_MENU_SLUG` (which was the parent) to just calling the render function directly — but actually it already uses `ACASA_ADMIN_MENU_SLUG` as the submenu slug which is correct for the "first submenu replaces parent" pattern. Keep it as-is.

- [ ] **Step 5: Remove donor access notice (lines 857–866)**

Delete the `add_action('admin_notices', ...)` block that checks for `acasa_current_user_is_donor`. This notice moves to the child theme's `functions.php`.

- [ ] **Step 6: Remove `class_exists('Acasa_Data_Tools')` guards in Settings page**

At lines 155–166 of the source, the Settings page render function wraps Donor Tools links in `class_exists('Acasa_Data_Tools')` / `function_exists('acasa_render_sync_donors_page')` guards. In the merged plugin these are always true. Replace lines 155–166 with unconditional rendering:

```php
    echo '<hr />';
    echo '<h2>Donor Tools</h2>';
    echo '<p><a class="button button-secondary" href="' . esc_url($sync_donors_url) . '">Open Donor Sync</a></p>';
    echo '<p><a class="button button-secondary" href="' . esc_url($email_blocks_url) . '">Open Donor Email Blocks</a></p>';
    echo '<p><a class="button button-secondary" href="' . esc_url($donor_quick_fix_url) . '">Open Donor Quick Fix</a></p>';
    echo '<p><a class="button button-secondary" href="' . esc_url($donor_health_url) . '">Open Donor Health</a></p>';
```

- [ ] **Step 7: PHP lint**

Run: `"$PHP" -l "W:/www/acasa/app/public/wp-content/plugins/acasa-tools/modules/admin-tools.php"`
Expected: `No syntax errors detected`

- [ ] **Step 8: Commit**

```bash
git add plugins/acasa-tools/modules/admin-tools.php
git commit -m "feat(acasa-tools): add admin-tools module (branding, settings, menu editor)"
```

### Task 3: Create `modules/data-tools.php` and copy subdirectories

**Files:**
- Source: `plugins/acasa-data-tools/acasa-data-tools.php` (211 lines)
- Source: `plugins/acasa-data-tools/includes/` (4 class files)
- Source: `plugins/acasa-data-tools/views/` (4 view files)
- Create: `plugins/acasa-tools/modules/data-tools.php`
- Create: `plugins/acasa-tools/modules/data-tools/includes/` (copy)
- Create: `plugins/acasa-tools/modules/data-tools/views/` (copy)

- [ ] **Step 1: Copy includes/ and views/ directories**

```bash
mkdir -p "plugins/acasa-tools/modules/data-tools"
cp -r "plugins/acasa-data-tools/includes" "plugins/acasa-tools/modules/data-tools/"
cp -r "plugins/acasa-data-tools/views" "plugins/acasa-tools/modules/data-tools/"
```

These files are unchanged — all `require ACASA_DT_PATH . 'includes/...'` and `require ACASA_DT_PATH . 'views/...'` will resolve correctly because `ACASA_DT_PATH` now points to `modules/data-tools/`.

- [ ] **Step 2: Copy source as starting point**

```bash
cp "plugins/acasa-data-tools/acasa-data-tools.php" "plugins/acasa-tools/modules/data-tools.php"
```

- [ ] **Step 3: Remove plugin header (lines 1–9)**

Replace with module header:

```php
<?php
/**
 * ACASA Tools — Data Tools module
 * GiveWP donor email blocks, data integrity, donor quick fix, donor health.
 * Loaded by acasa-tools.php entry point.
 */

defined( 'ABSPATH' ) || exit;
```

- [ ] **Step 4: Remove `ACASA_DT_PATH` definition (line 13–15)**

Delete the `if ( ! defined( 'ACASA_DT_PATH' ) )` block — now in entry point.

- [ ] **Step 5: Remove `register_menus()` — `admin_parent_exists()` fallback + Sync Donors submenu**

In `register_menus()` method (line 64):
1. Remove `$parent = 'tools.php';` and the `if (defined('ACASA_ADMIN_MENU_SLUG') && $this->admin_parent_exists(...))` block. Replace with: `$parent = ACASA_ADMIN_MENU_SLUG;`
2. Remove the Sync Donors `add_submenu_page()` call (lines 110–117) — Donor Access owns this page.

The method becomes:

```php
public function register_menus(): void {
    if ( ! class_exists( 'Give' ) ) {
        return;
    }

    $parent = ACASA_ADMIN_MENU_SLUG;

    add_submenu_page( $parent, 'Donor Email Blocks', 'Donors: Email Blocks', 'manage_options', 'acasa-email-blocks', [ $this, 'render_email_blocks_page' ] );
    add_submenu_page( $parent, 'Data Integrity', 'Data Integrity', 'manage_options', 'acasa-data-integrity', [ $this, 'render_data_integrity_page' ] );
    add_submenu_page( $parent, 'Donor Quick Fix', 'Donors: Quick Fix', 'manage_options', 'acasa-donor-quick-fix', [ $this, 'render_donor_quick_fix_page' ] );
    add_submenu_page( $parent, 'Donor Health', 'Donors: Health', 'manage_options', 'acasa-donor-health', [ $this, 'render_donor_health_page' ] );
}
```

- [ ] **Step 6: Remove `render_sync_donors_page()` and `ajax_sync_donors()` methods**

Delete `render_sync_donors_page()` (lines 136–144) and `ajax_sync_donors()` (lines 169–176). Donor Access handles these.

- [ ] **Step 7: Remove Sync Donors AJAX registration**

In `register_ajax()` (line 146), remove the `wp_ajax_acasa_sync_donors` action (lines 165–166).

- [ ] **Step 8: Remove `admin_parent_exists()` method (lines 178–194)**

Delete the entire private method — no longer needed.

- [ ] **Step 9: Remove `register_activation_hook()` call (line 207)**

Delete `register_activation_hook( __FILE__, ... )` — moved to entry point.

- [ ] **Step 10: Remove `on_activation()` static method (lines 47–54)**

Delete — moved to entry point's activation hook closure.

- [ ] **Step 11: Simplify bottom-of-file bootstrap (lines 197–210)**

The current code has a conditional guard `if ($is_admin_request || $is_ajax_request || $is_cli_request)` wrapping the 4 require statements and `Acasa_Data_Tools::instance()`. Since the entry point already handles this guard, remove the conditional and keep the contents unconditionally:

```php
require_once ACASA_DT_PATH . 'includes/class-email-blocks.php';
require_once ACASA_DT_PATH . 'includes/class-data-integrity.php';
require_once ACASA_DT_PATH . 'includes/class-donor-quick-fix.php';
require_once ACASA_DT_PATH . 'includes/class-donor-health.php';

Acasa_Data_Tools::instance();
```

Also remove the three variable assignments at lines 197–199 (`$is_ajax_request`, `$is_admin_request`, `$is_cli_request`) — the entry point handles context checks.

- [ ] **Step 12: PHP lint**

Run: `"$PHP" -l "W:/www/acasa/app/public/wp-content/plugins/acasa-tools/modules/data-tools.php"`
Expected: `No syntax errors detected`

- [ ] **Step 13: Commit**

```bash
git add plugins/acasa-tools/modules/data-tools.php plugins/acasa-tools/modules/data-tools/
git commit -m "feat(acasa-tools): add data-tools module with includes and views"
```

### Task 4: Create `modules/content-migrator.php`

**Files:**
- Source: `plugins/acasa-content-migrator/acasa-content-migrator.php` (1483 lines)
- Create: `plugins/acasa-tools/modules/content-migrator.php`

- [ ] **Step 1: Copy source as starting point**

```bash
cp "plugins/acasa-content-migrator/acasa-content-migrator.php" "plugins/acasa-tools/modules/content-migrator.php"
```

- [ ] **Step 2: Replace plugin header (lines 1–7)**

```php
<?php
/**
 * ACASA Tools — Content Migrator module
 * Export and import selected pages between ACASA environments.
 * Loaded by acasa-tools.php entry point.
 */
```

Keep the `if (!class_exists('ACASA_Content_Migrator'))` wrapper (line 13) and the `new ACASA_Content_Migrator();` instantiation (line 1482) — the class_exists guard prevents double-loading if the old standalone plugin is accidentally left active, and the constructor call initializes the module.

- [ ] **Step 3: Remove `resolve_parent_slug()` method (lines 1398–1406)**

Delete entirely.

- [ ] **Step 4: Remove `admin_parent_exists()` method (lines 1408–1425)**

Delete entirely.

- [ ] **Step 5: Update `register_admin_page()` to use constant**

Replace `$parent_slug = $this->resolve_parent_slug();` (line 34) with `$parent_slug = ACASA_ADMIN_MENU_SLUG;`

Or more simply, inline it:

```php
public function register_admin_page(): void
{
    add_submenu_page(
        ACASA_ADMIN_MENU_SLUG,
        'Content Migrator',
        'Content Migrator',
        'manage_options',
        self::PAGE_SLUG,
        [$this, 'render_admin_page']
    );
}
```

- [ ] **Step 6: PHP lint**

Run: `"$PHP" -l "W:/www/acasa/app/public/wp-content/plugins/acasa-tools/modules/content-migrator.php"`
Expected: `No syntax errors detected`

- [ ] **Step 7: Commit**

```bash
git add plugins/acasa-tools/modules/content-migrator.php
git commit -m "feat(acasa-tools): add content-migrator module"
```

### Task 5: Add changelog

**Files:**
- Create: `plugins/acasa-tools/changelog.md`

- [ ] **Step 1: Write changelog**

```markdown
# ACASA Tools — Changelog

## 1.5 — 2026-03-16

- **Consolidation:** Merged `acasa-admin-tools`, `acasa-data-tools`, and `acasa-content-migrator` into single `acasa-tools` plugin.
- Shared ACASA admin menu at priority 9 (idempotent — works with or without ACASA Donor Access).
- Constants centralized in entry point (`ACASA_ADMIN_MENU_SLUG`, `ACASA_DT_PATH`, nav layout constants).
- Activation hook creates `donor_health_audit` table (non-fatal on failure).
- Removed Sync Donors submenu — owned by ACASA Donor Access.
- Removed `tools.php` fallback for submenu parent — always uses ACASA menu.
- Removed Donor Access activation notice — moved to child theme.
```

- [ ] **Step 2: Commit**

```bash
git add plugins/acasa-tools/changelog.md
git commit -m "docs(acasa-tools): add changelog for v1.5"
```

---

## Chunk 2: ACASA Donor Access Update

### Task 6: Update entry point with shared menu

**Files:**
- Modify: `plugins/acasa-donor-access/acasa-donor-access.php`

- [ ] **Step 1: Update plugin header version to 1.5**

Change `Version: 1.0.0` to `Version: 1.5`.

- [ ] **Step 2: Add `ACASA_ADMIN_MENU_SLUG` constant after ABSPATH guard**

After the `ACASA_DONOR_ACCESS_PATH` definition, add:

```php
if ( ! defined( 'ACASA_ADMIN_MENU_SLUG' ) ) {
    define( 'ACASA_ADMIN_MENU_SLUG', 'acasa-admin' );
}
```

- [ ] **Step 3: Add shared ACASA menu creation at priority 9**

After the constant, before the `require_once` lines:

```php
/* Shared ACASA admin menu (priority 9, idempotent). */
add_action( 'admin_menu', function () {
    global $menu;
    foreach ( (array) $menu as $item ) {
        if ( ( $item[2] ?? '' ) === 'acasa-admin' ) {
            return;
        }
    }
    add_menu_page( 'ACASA', 'ACASA', 'manage_options', 'acasa-admin', '__return_null', 'dashicons-admin-home', 58 );
}, 9 );
```

- [ ] **Step 4: PHP lint**

Run: `"$PHP" -l "W:/www/acasa/app/public/wp-content/plugins/acasa-donor-access/acasa-donor-access.php"`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add plugins/acasa-donor-access/acasa-donor-access.php
git commit -m "feat(donor-access): add shared ACASA menu, bump to v1.5"
```

### Task 7: Simplify `includes-hooks-admin.php`

**Files:**
- Modify: `plugins/acasa-donor-access/includes-hooks-admin.php` (lines 34–50)

- [ ] **Step 1: Remove `class_exists('Acasa_Data_Tools')` fallback gate**

Replace lines 34–50 (the `plugins_loaded` action with `class_exists` check) with unconditional registration:

```php
// Sync Donors submenu — always registered by Donor Access.
if ( function_exists( 'acasa_register_sync_donors_admin_page' ) ) {
    add_action( 'admin_menu', 'acasa_register_sync_donors_admin_page', 40 );
}
if ( function_exists( 'acasa_ajax_sync_donors' ) ) {
    add_action( 'wp_ajax_acasa_sync_donors', 'acasa_ajax_sync_donors' );
}
```

This removes the `plugins_loaded` wrapper and the `class_exists('Acasa_Data_Tools')` early return. Donor Access always owns the Sync Donors page regardless of which other plugins are active.

- [ ] **Step 2: Simplify `acasa_register_sync_donors_admin_page()` in `includes-donor-access.php`**

At `includes-donor-access.php:1292-1313`, the function has an `acasa_admin_parent_exists()` gate with a `tools.php` fallback. With the shared menu at priority 9, the parent always exists by the time this runs at priority 40. Simplify the function:

Replace lines 1292–1313:
```php
function acasa_register_sync_donors_admin_page() {
    add_submenu_page(
        ACASA_ADMIN_MENU_SLUG,
        'Sync Donor Accounts',
        'Sync Donors',
        'manage_options',
        'acasa-sync-donors',
        'acasa_render_sync_donors_page'
    );
}
```

Also delete the now-unused `acasa_admin_parent_exists()` function at lines 1274–1290.

- [ ] **Step 3: PHP lint both files**

Run: `"$PHP" -l "W:/www/acasa/app/public/wp-content/plugins/acasa-donor-access/includes-hooks-admin.php"`
Run: `"$PHP" -l "W:/www/acasa/app/public/wp-content/plugins/acasa-donor-access/includes-donor-access.php"`
Expected: `No syntax errors detected` (both)

- [ ] **Step 4: Commit**

```bash
git add plugins/acasa-donor-access/includes-hooks-admin.php plugins/acasa-donor-access/includes-donor-access.php
git commit -m "refactor(donor-access): simplify sync donors registration, remove data-tools dependency"
```

### Task 8: Add Donor Access changelog

**Files:**
- Create: `plugins/acasa-donor-access/changelog.md`

- [ ] **Step 1: Write changelog**

```markdown
# ACASA Donor Access — Changelog

## 1.5 — 2026-03-16

- Added shared ACASA admin menu creation at priority 9 (works standalone or alongside ACASA Tools).
- Defined `ACASA_ADMIN_MENU_SLUG` constant (guarded).
- Simplified Sync Donors registration — always registers directly, no longer checks for `Acasa_Data_Tools`.
- Version bumped to 1.5.
```

- [ ] **Step 2: Commit**

```bash
git add plugins/acasa-donor-access/changelog.md
git commit -m "docs(donor-access): add changelog for v1.5"
```

---

## Chunk 3: mu-plugins Merge + Child Theme Notice

### Task 9: Create merged `acasa-diagnostics.php`

**Files:**
- Source: `mu-plugins/acasa-avatar-diagnostics.php` (~212 lines)
- Source: `mu-plugins/acasa-mail-diagnostics.php` (~142 lines)
- Create: `mu-plugins/acasa-diagnostics.php`

- [ ] **Step 1: Create the merged file**

Structure:
1. Single shared `acasa_diag_should_run()` function at the top (same host list as both originals)
2. One early return: `if ( ! acasa_diag_should_run() ) return;`
3. Section 1: Avatar diagnostics — copy all hook registrations and helper functions from `acasa-avatar-diagnostics.php` (everything after line 36)
4. Section 2: Mail diagnostics — copy all hook registrations and helper functions from `acasa-mail-diagnostics.php` (everything after line 34)

```php
<?php
/**
 * Plugin Name: ACASA Diagnostics
 * Description: Avatar upload + mail transport diagnostics for q/prod troubleshooting.
 */

if ( ! function_exists( 'acasa_diag_should_run' ) ) {
    function acasa_diag_should_run(): bool {
        $host = '';
        if ( ! empty( $_SERVER['HTTP_HOST'] ) ) {
            $host = strtolower( trim( (string) $_SERVER['HTTP_HOST'] ) );
        } else {
            $parsed = wp_parse_url( home_url(), PHP_URL_HOST );
            $host   = is_string( $parsed ) ? strtolower( $parsed ) : '';
        }
        if ( $host === '' ) {
            return false;
        }
        return in_array( $host, [
            'q.asociatiaacasa.ro',
            'asociatiaacasa.ro',
            'www.asociatiaacasa.ro',
        ], true );
    }
}

if ( ! acasa_diag_should_run() ) {
    return;
}

/* ════════════════════════════════════════════════════════════════════════════
   Section 1: Avatar Diagnostics
   ════════════════════════════════════════════════════════════════════════════ */

// ... (paste all functions and hooks from acasa-avatar-diagnostics.php after the guard)

/* ════════════════════════════════════════════════════════════════════════════
   Section 2: Mail Diagnostics
   ════════════════════════════════════════════════════════════════════════════ */

// ... (paste all functions and hooks from acasa-mail-diagnostics.php after the guard)
```

Key changes from originals:
- Both `acasa_avatar_diag_should_run()` and `acasa_mail_diag_should_run()` replaced by single `acasa_diag_should_run()`
- Both per-section bare `return;` removed — single early return at top, then all hooks register unconditionally
- `acasa_avatar_diag_log()` and `acasa_mail_diag_log()` functions kept as-is (different log formats)

- [ ] **Step 2: PHP lint**

Run: `"$PHP" -l "W:/www/acasa/app/public/wp-content/mu-plugins/acasa-diagnostics.php"`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit (do NOT delete old files yet)**

```bash
git add mu-plugins/acasa-diagnostics.php
git commit -m "feat(mu-plugins): merge avatar + mail diagnostics into acasa-diagnostics.php"
```

### Task 10: Add Donor Access dependency notice to child theme

**Files:**
- Modify: `themes/acasa-child/functions.php` (append after line 1440)

- [ ] **Step 1: Add admin notice at end of functions.php**

Append after the closing `});` of the GiveWP CSS filter:

```php

/* ============================================================================
   Donor Access dependency notice
   ============================================================================ */
add_action( 'admin_notices', function () {
    if ( ! function_exists( 'is_plugin_active' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if ( is_plugin_active( 'acasa-donor-access/acasa-donor-access.php' ) ) {
        return;
    }
    echo '<div class="notice notice-warning"><p><strong>ACASA Donor Access</strong> nu este instalat sau activat. Donatorii nu vor primi conturi WordPress automat și nu vor avea acces la Donor Dashboard.</p></div>';
} );
```

- [ ] **Step 2: PHP lint functions.php**

Run: `"$PHP" -l "W:/www/acasa/app/public/wp-content/themes/acasa-child/functions.php"`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add themes/acasa-child/functions.php
git commit -m "feat(child-theme): add Donor Access dependency admin notice"
```

---

## Chunk 4: Local Testing + Cleanup

### Task 11: Local activation test

- [ ] **Step 1: Deactivate old plugins via WP-CLI**

```bash
"$PHP" -c "$PHPINI" "$WPCLI" --path="$WP_PATH" plugin deactivate acasa-admin-tools acasa-data-tools acasa-content-migrator
```

Expected: `Plugin 'acasa-admin-tools' deactivated.` (×3, or "not active" warnings — both OK)

- [ ] **Step 2: Activate new plugins**

```bash
"$PHP" -c "$PHPINI" "$WPCLI" --path="$WP_PATH" plugin activate acasa-tools acasa-donor-access
```

Expected: `Plugin 'acasa-tools' activated.` and `Plugin 'acasa-donor-access' activated.`

- [ ] **Step 3: Verify ACASA menu exists with all submenus**

```bash
"$PHP" -c "$PHPINI" "$WPCLI" --path="$WP_PATH" eval '
global $submenu;
if ( isset( $submenu["acasa-admin"] ) ) {
    foreach ( $submenu["acasa-admin"] as $item ) {
        echo $item[0] . " → " . $item[2] . "\n";
    }
} else {
    echo "ACASA menu not found!\n";
}
'
```

Expected output (9 submenu items):
```
Settings → acasa-admin
Menu Element → acasa-menu-layout-editor
Branding → acasa-branding
Donors: Email Blocks → acasa-email-blocks
Data Integrity → acasa-data-integrity
Donors: Quick Fix → acasa-donor-quick-fix
Donors: Health → acasa-donor-health
Sync Donors → acasa-sync-donors
Content Migrator → acasa-content-migrator
```

- [ ] **Step 4: Verify AJAX endpoints are registered**

```bash
"$PHP" -c "$PHPINI" "$WPCLI" --path="$WP_PATH" eval '
$ajax_actions = [
    "acasa_scan_email_blocks",
    "acasa_unlock_donor",
    "acasa_scan_integrity",
    "acasa_dqf_scan",
    "acasa_scan_donor_health",
    "acasa_sync_donors",
];
foreach ($ajax_actions as $action) {
    $tag = "wp_ajax_" . $action;
    $has = has_action($tag);
    echo ($has ? "OK" : "MISSING") . " — $tag\n";
}
'
```

Expected: All `OK`

- [ ] **Step 5: Verify Donor Access standalone mode**

Deactivate acasa-tools, verify Donor Access still creates the ACASA menu and registers Sync Donors:

```bash
"$PHP" -c "$PHPINI" "$WPCLI" --path="$WP_PATH" plugin deactivate acasa-tools
"$PHP" -c "$PHPINI" "$WPCLI" --path="$WP_PATH" eval '
global $submenu;
if ( isset( $submenu["acasa-admin"] ) ) {
    foreach ( $submenu["acasa-admin"] as $item ) {
        echo $item[0] . " → " . $item[2] . "\n";
    }
} else {
    echo "ACASA menu not found!\n";
}
'
```

Expected: At minimum `Sync Donors → acasa-sync-donors`

Then reactivate:

```bash
"$PHP" -c "$PHPINI" "$WPCLI" --path="$WP_PATH" plugin activate acasa-tools
```

- [ ] **Step 6: Verify dependency notice shows when Donor Access is off**

```bash
"$PHP" -c "$PHPINI" "$WPCLI" --path="$WP_PATH" plugin deactivate acasa-donor-access
"$PHP" -c "$PHPINI" "$WPCLI" --path="$WP_PATH" eval '
// Simulate admin_notices
do_action("admin_notices");
'
```

Expected: Contains "ACASA Donor Access nu este instalat sau activat"

Then reactivate:

```bash
"$PHP" -c "$PHPINI" "$WPCLI" --path="$WP_PATH" plugin activate acasa-donor-access
```

### Task 12: Replace old mu-plugins

- [ ] **Step 1: Verify acasa-diagnostics.php loads without errors**

```bash
"$PHP" -l "W:/www/acasa/app/public/wp-content/mu-plugins/acasa-diagnostics.php"
```

- [ ] **Step 2: Delete old mu-plugin files (after testing)**

```bash
rm mu-plugins/acasa-avatar-diagnostics.php mu-plugins/acasa-mail-diagnostics.php
```

- [ ] **Step 3: Commit cleanup**

```bash
git add mu-plugins/
git commit -m "chore(mu-plugins): remove old avatar + mail diagnostics (merged into acasa-diagnostics.php)"
```

### Task 13: Final commit and handoff

- [ ] **Step 1: Update SESSION-HANDOFF.md with new state**

- [ ] **Step 2: Present to user for review**

All old plugins (`acasa-admin-tools`, `acasa-data-tools`, `acasa-content-migrator`) remain in place but deactivated — they can be deleted after Q testing confirms the consolidated plugins work correctly.
