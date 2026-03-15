# Plugin Extraction & Theme Optimization

**Date:** 2026-03-16
**Status:** Approved

## Goal

Separate admin-only code from the child theme so nothing backend-only loads on the frontend. Fix security issues. Clean up CSS redundancy.

## Changes

### 1. New plugin: `acasa-admin-tools`

Location: `wp-content/plugins/acasa-admin-tools/acasa-admin-tools.php`

Extracted from `functions.php`:
- Branding Tool: target map, capture, compute plan, apply, snapshot, rollback, render page (~880 lines)
- Admin menu registration (add_menu_page, add_submenu_page)
- Header settings page render
- Menu layout editor admin page + ensure_layout_post()
- Admin notices (menu semantics hint, donor access warning)
- register_setting for mega panels option
- admin_enqueue_scripts for media picker
- GP Elements seed stub (from inc/gp-elements-seed.php)

Plugin depends on child theme functions (acasa_is_mega_menu_enabled, acasa_primary_menu_term_id, etc.) and checks for their existence before calling.

### 2. Slimmed `functions.php` (~1,450 lines)

Retains only frontend-needed + shared utility code:
- Mega menu constants + enable/disable check
- Asset enqueue (child CSS, header-v1, mega panel conditional)
- GiveWP duplicate CSS dequeue + frontend asset pruning
- Root-relative URL helpers + logo filter
- Menu semantic role system (CSS classes, link attributes, nav_menu filters)
- Account display payload + mobile quick actions
- wp_nav_menu block rendering override
- Menu panel items/markup generation (needed for frontend rendering)
- Navigation cache versioning
- `[acasa_copy]` shortcode (with esc_js fix)
- GiveWP iframe CSS injection filter
- Donate fallback in nav items

### 3. Security fixes

- `[acasa_copy]` shortcode: `esc_attr()` → `esc_js()` in onclick JS context (XSS)
- `wp_die()` in branding page: add `esc_html__()` for consistency

### 4. CSS cleanup (6 fixes)

1. style.css: Remove 7 redundant hover-state a/h3 a selectors (keep only p)
2. style.css: Remove 3 already-excluded selectors in content link exclusion
3. functions.php GiveWP CSS: Fix back-button color with :not() exclusion
4. header-v1-mega.css: Remove duplicate gap declaration
5. header-v1-mega.css: Remove duplicate min-height:220px
6. header-v1.css: Merge two display:none rules into one grouped selector

### 5. Dead code removal

- Delete `inc/gp-elements-seed.php` (never included from functions.php)
- Delete empty `inc/` directory

## Expected impact

| Metric | Before | After |
|--------|--------|-------|
| Frontend PHP parse | 2,711 lines | ~1,450 lines |
| functions.php size | 108 KB | ~57 KB |
| CSS redundant rules | 16 lines | 0 |
| Security issues | 1 critical XSS | 0 |
| Admin tools on frontend | Always loaded | Never loaded |
