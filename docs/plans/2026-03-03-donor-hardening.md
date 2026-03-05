# Donor Access Hardening Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Harden donor avatar uploads, prevent duplicate WP users from multi-email donors, stabilize donor-user linking, and add a Donor Health diagnostic tab to acasa-data-tools.

**Architecture:** Part 1 adds REST preflight hooks and rewrites the donation-confirmed flow in `wp-content/plugins/acasa-donor-access/includes-donor-access.php`. Part 2 adds a new `Acasa_Donor_Health` class and admin view to the existing `acasa-data-tools` plugin, following the same static-method + AJAX pattern as existing tabs.

**Tech Stack:** WordPress REST API hooks (`rest_request_before_callbacks`), GiveWP `Give_Donor` class, `Give()->donor_meta` API, `give_donormeta` table (`additional_email` meta_key), `dbDelta()` for audit table.

**Design doc:** `W:/www/acasa/app/public/sources/donor-access-hardening-acasa-give-checks-design.md` (v4)

**Workflow doc:** `W:/www/acasa/CLAUDE-CODEX-WORKFLOW.md` -- work branch, no auto-deploy.

**Repos:**
- Child theme: `W:/www/acasa/app/public/wp-content/themes/acasa-child` (git, remote `origin` on GitHub)
- Plugin: `W:/www/acasa/app/public/wp-content/plugins/acasa-data-tools` (git, local only)

**WP-CLI:**
```bash
PHP="C:/Users/AndreiAronet/AppData/Roaming/Local/lightning-services/php-8.3.29+1/bin/win64/php.exe"
PHPINI="C:/Users/AndreiAronet/AppData/Roaming/Local/run/4LNNXVV9T/conf/php/php.ini"
WPCLI="C:/Users/AndreiAronet/AppData/Local/Programs/Local/resources/extraResources/bin/wp-cli/wp-cli.phar"
WP_PATH="W:/www/acasa/app/public"
"$PHP" -c "$PHPINI" "$WPCLI" --path="$WP_PATH" <command>
```

---

## Task 1: Create work branch

**Files:** none (git operation only)

**Step 1: Create branch in child theme repo**
```bash
cd W:/www/acasa/app/public/wp-content/themes/acasa-child
git checkout -b task/donor-hardening-codex
```

**Step 2: Create branch in plugin repo**
```bash
cd W:/www/acasa/app/public/wp-content/plugins/acasa-data-tools
git checkout -b task/donor-hardening-codex
```

---

## Task 2: Add diagnostic logging helper

**Files:**
- Modify: `acasa-donor-access/includes-donor-access.php` (add after line 16, before shared helpers)

**Step 1: Add the helper function**

After `if ( ! defined( 'ABSPATH' ) ) exit;` add:

```php
/**
 * Log a donor-access diagnostic message.
 *
 * Only logs when ACASA_DONOR_DIAG is truthy. Minimizes PII:
 * emails are hashed (first 8 chars of SHA-256) + domain.
 *
 * @param string $tag     Short label (e.g. 'avatar-preflight', 'donation-confirmed').
 * @param array  $context Key-value data. Keys named '*_email' are auto-hashed.
 */
function acasa_donor_diag( string $tag, array $context = [] ): void {
    if ( empty( $_SERVER['ACASA_DONOR_DIAG'] ) && ! ( defined( 'ACASA_DONOR_DIAG' ) && ACASA_DONOR_DIAG ) ) {
        return;
    }

    foreach ( $context as $key => $value ) {
        if ( str_ends_with( $key, '_email' ) && is_string( $value ) && $value !== '' ) {
            $domain = strstr( $value, '@' );
            $hash   = substr( hash( 'sha256', strtolower( trim( $value ) ) ), 0, 8 );
            $context[ $key ] = $hash . ( $domain ?: '@?' );
        }
    }

    error_log( '[acasa-donor-diag][' . $tag . '] ' . wp_json_encode( $context ) );
}
```

**Step 2: Verify file loads**
```bash
"$PHP" -c "$PHPINI" "$WPCLI" --path="$WP_PATH" eval 'echo "loaded";'
```
Expected: `loaded`

**Step 3: Commit**
```bash
git -C "W:/www/acasa/app/public/wp-content/plugins/acasa-donor-access" add includes-donor-access.php
git -C "W:/www/acasa/app/public/wp-content/themes/acasa-child" commit -m "feat(donor): add diagnostic logging helper with PII hashing"
```

---

## Task 3: Avatar preflight guard + upload size guard

**Files:**
- Modify: `acasa-donor-access/includes-donor-access.php` (add new section after the avatar admin save block, before the GiveWP-required guard at line 366)

**Step 1: Add the REST preflight hook**

Insert before `// Everything below requires GiveWP runtime and data tables.` (line 366):

```php
/* =========================================================================
   Avatar upload guards (REST preflight)
   ========================================================================= */

/**
 * Preflight checks on GiveWP avatar upload route.
 *
 * Runs before GiveWP's own callback via rest_request_before_callbacks.
 * Clears stale/orphan/mismatched avatar meta so GiveWP's ownership
 * check does not reject a legitimate upload.
 * Also catches oversized uploads before they reach GiveWP.
 */
add_filter( 'rest_request_before_callbacks', 'acasa_avatar_upload_preflight', 10, 3 );

function acasa_avatar_upload_preflight( $response, $handler, WP_REST_Request $request ) {
    $route = (string) $request->get_route();

    // Only act on avatar upload route.
    if ( strpos( $route, '/give-api/v2/donor-dashboard/avatar' ) === false ) {
        return $response;
    }

    $user_id = get_current_user_id();
    if ( $user_id <= 0 ) {
        return $response;
    }

    // --- Upload size guard ---
    $content_length = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
    $max_upload     = wp_max_upload_size();

    if ( $content_length > 0 && empty( $_FILES ) ) {
        $max_mb = round( $max_upload / ( 1024 * 1024 ), 1 );
        acasa_donor_diag( 'avatar-size-rejected', [
            'user_id'        => $user_id,
            'content_length' => $content_length,
            'max_upload'     => $max_upload,
        ] );
        return new WP_Error(
            'acasa_upload_too_large',
            sprintf(
                'Imaginea este prea mare. Dimensiunea maxima permisa este %s MB.',
                $max_mb
            ),
            [ 'status' => 413 ]
        );
    }

    // --- Avatar meta preflight (clear stale references) ---
    if ( ! class_exists( 'Give_Donor' ) || ! function_exists( 'Give' ) ) {
        return $response;
    }

    $donor = new Give_Donor( $user_id, true );
    if ( ! $donor || empty( $donor->id ) ) {
        return $response;
    }

    // Verify donor's linked user matches current user (strict identity binding).
    $donor_user_id = isset( $donor->user_id ) ? (int) $donor->user_id : 0;
    if ( $donor_user_id !== $user_id ) {
        return $response;
    }

    $avatar_id = (int) Give()->donor_meta->get_meta( $donor->id, '_give_donor_avatar_id', true );
    if ( $avatar_id <= 0 ) {
        return $response;
    }

    $should_clear = false;
    $reason       = '';

    // Check 1: attachment post missing.
    if ( ! get_post( $avatar_id ) ) {
        $should_clear = true;
        $reason       = 'attachment_missing';
    }

    // Check 2: attachment exists but file on disk is missing.
    if ( ! $should_clear ) {
        $file_path = get_attached_file( $avatar_id );
        if ( ! $file_path || ! file_exists( $file_path ) ) {
            $should_clear = true;
            $reason       = 'file_missing';
        }
    }

    // Check 3: attachment owner mismatch.
    if ( ! $should_clear ) {
        $post_author = (int) get_post_field( 'post_author', $avatar_id );
        if ( $post_author !== $user_id ) {
            $should_clear = true;
            $reason       = 'owner_mismatch';
        }
    }

    if ( $should_clear ) {
        Give()->donor_meta->update_meta( $donor->id, '_give_donor_avatar_id', '' );
        acasa_donor_diag( 'avatar-preflight-cleared', [
            'donor_id'  => $donor->id,
            'user_id'   => $user_id,
            'avatar_id' => $avatar_id,
            'reason'    => $reason,
        ] );

        // Store reason so profile normalization shim knows the cause.
        if ( ! isset( $GLOBALS['acasa_avatar_preflight'] ) ) {
            $GLOBALS['acasa_avatar_preflight'] = [];
        }
        $GLOBALS['acasa_avatar_preflight'][ $user_id ] = $reason;
    }

    return $response;
}
```

**Step 2: Verify file loads**
```bash
"$PHP" -c "$PHPINI" "$WPCLI" --path="$WP_PATH" eval 'echo "loaded";'
```

**Step 3: Commit**
```bash
git -C "W:/www/acasa/app/public/wp-content/plugins/acasa-donor-access" add includes-donor-access.php
git -C "W:/www/acasa/app/public/wp-content/themes/acasa-child" commit -m "feat(donor): avatar preflight guard + upload size guard"
```

---

## Task 4: Profile payload normalization shim

**Files:**
- Modify: `acasa-donor-access/includes-donor-access.php` (add after the avatar preflight hook)

**Step 1: Add the profile normalization hook**

Insert after the avatar preflight function, before `// Everything below requires GiveWP runtime`:

```php
/**
 * Normalize GiveWP profile payload when avatar upload failed for a known reason.
 *
 * Injects current avatarId to prevent Undefined property warnings in GiveWP
 * ProfileRoute. Only injects when the failure cause is known (preflight
 * cleared stale ref or size exceeded). Unknown failures are left untouched
 * so the warning surfaces for investigation.
 */
add_filter( 'rest_request_before_callbacks', 'acasa_profile_payload_normalize', 10, 3 );

function acasa_profile_payload_normalize( $response, $handler, WP_REST_Request $request ) {
    $route = (string) $request->get_route();
    if ( strpos( $route, '/give-api/v2/donor-dashboard/profile' ) === false ) {
        return $response;
    }

    $user_id = get_current_user_id();
    if ( $user_id <= 0 ) {
        return $response;
    }

    // Only act if we know the avatar failure was from our preflight or size guard.
    $known_failure = isset( $GLOBALS['acasa_avatar_preflight'][ $user_id ] );
    if ( ! $known_failure ) {
        return $response;
    }

    $data = $request->get_param( 'data' );
    if ( ! is_array( $data ) || array_key_exists( 'avatarId', $data ) ) {
        return $response;
    }

    // Inject current donor avatar ID (may be 0 after preflight cleared it).
    if ( class_exists( 'Give_Donor' ) && function_exists( 'Give' ) ) {
        $donor = new Give_Donor( $user_id, true );
        if ( $donor && ! empty( $donor->id ) ) {
            $current_id = (int) Give()->donor_meta->get_meta( $donor->id, '_give_donor_avatar_id', true );
            $data['avatarId'] = $current_id;
            $request->set_param( 'data', $data );
        }
    }

    return $response;
}
```

**Step 2: Verify file loads**
```bash
"$PHP" -c "$PHPINI" "$WPCLI" --path="$WP_PATH" eval 'echo "loaded";'
```

**Step 3: Commit**
```bash
git -C "W:/www/acasa/app/public/wp-content/plugins/acasa-donor-access" add includes-donor-access.php
git -C "W:/www/acasa/app/public/wp-content/themes/acasa-child" commit -m "feat(donor): profile payload normalization for known avatar failures"
```

---

## Task 5: Rewrite donation-confirmed flow (link stability + multi-email consolidation)

This is the most critical task. Rewrites `acasa_on_donation_confirmed()` with the new resolution order: linked user -> primary email user -> checkout email user -> create new.

**Files:**
- Modify: `acasa-donor-access/includes-donor-access.php:488-511` (replace entire function)

**Step 1: Replace `acasa_on_donation_confirmed()`**

Replace lines 488-511 with:

```php
/**
 * Hook: ensure donor has a WP user when a donation is confirmed.
 *
 * Resolution order (first match wins):
 *   1. Donor already has a valid linked user_id -> stop.
 *   2. WP user exists matching donor's PRIMARY email -> link + add role.
 *   3. WP user exists matching CHECKOUT email -> link + add role.
 *   4. No match -> create new WP user with donor's primary email.
 *
 * This prevents duplicate WP users for multi-email donors and avoids
 * relinking when an existing link is valid.
 */
add_action( 'give_update_payment_status', 'acasa_on_donation_confirmed', 10, 3 );

function acasa_on_donation_confirmed( $payment_id, $new_status, $old_status ) {
    if ( 'publish' !== $new_status || 'publish' === $old_status ) {
        return;
    }

    $checkout_email = give_get_payment_user_email( $payment_id );
    $donor_id       = (int) give_get_payment_donor_id( $payment_id );

    if ( $donor_id <= 0 ) {
        acasa_donor_diag( 'donation-confirmed-skip', [
            'payment_id'     => $payment_id,
            'reason'         => 'no_donor_id',
            'checkout_email' => $checkout_email,
        ] );
        return;
    }

    // Load donor record.
    $donor = new Give_Donor( $donor_id );
    if ( ! $donor || empty( $donor->id ) ) {
        acasa_donor_diag( 'donation-confirmed-skip', [
            'payment_id' => $payment_id,
            'donor_id'   => $donor_id,
            'reason'     => 'donor_not_found',
        ] );
        return;
    }

    $primary_email   = isset( $donor->email ) ? trim( (string) $donor->email ) : '';
    $linked_user_id  = isset( $donor->user_id ) ? (int) $donor->user_id : 0;

    // --- Step 1: Donor already linked to a valid WP user? ---
    if ( $linked_user_id > 0 ) {
        $linked_user = get_userdata( $linked_user_id );
        if ( $linked_user ) {
            // Ensure role is present on the already-linked user.
            if ( ! in_array( 'give_donor', (array) $linked_user->roles, true ) ) {
                $linked_user->add_role( 'give_donor' );
            }
            acasa_donor_diag( 'donation-confirmed-linked-exists', [
                'payment_id'     => $payment_id,
                'donor_id'       => $donor_id,
                'user_id'        => $linked_user_id,
                'checkout_email' => $checkout_email,
                'primary_email'  => $primary_email,
            ] );
            return; // Done. Do not relink.
        }
        // Linked user_id is stale (user deleted). Fall through to find/create.
        acasa_donor_diag( 'donation-confirmed-stale-link', [
            'payment_id'    => $payment_id,
            'donor_id'      => $donor_id,
            'stale_user_id' => $linked_user_id,
        ] );
    }

    // --- Step 2: WP user matching donor's primary email? ---
    $user = null;
    if ( $primary_email !== '' && is_email( $primary_email ) ) {
        $user = get_user_by( 'email', $primary_email );
    }

    // --- Step 3: WP user matching checkout email? ---
    if ( ! $user && $checkout_email !== '' && $checkout_email !== $primary_email && is_email( $checkout_email ) ) {
        $user = get_user_by( 'email', $checkout_email );
    }

    if ( $user ) {
        // Found existing user. Add role and link.
        if ( ! in_array( 'give_donor', (array) $user->roles, true ) ) {
            $user->add_role( 'give_donor' );
        }
        acasa_link_donor_to_user( $donor_id, (int) $user->ID );
        acasa_donor_diag( 'donation-confirmed-linked-existing', [
            'payment_id'    => $payment_id,
            'donor_id'      => $donor_id,
            'user_id'       => $user->ID,
            'matched_email' => $user->user_email,
        ] );
        return;
    }

    // --- Step 4: Create new WP user with donor's primary email. ---
    $create_email = ( $primary_email !== '' && is_email( $primary_email ) )
        ? $primary_email
        : $checkout_email;

    if ( empty( $create_email ) || ! is_email( $create_email ) ) {
        acasa_donor_diag( 'donation-confirmed-skip', [
            'payment_id' => $payment_id,
            'donor_id'   => $donor_id,
            'reason'     => 'no_valid_email',
        ] );
        return;
    }

    $user_info  = give_get_payment_meta_user_info( $payment_id );
    $first_name = $user_info['first_name'] ?? '';
    $last_name  = $user_info['last_name'] ?? '';

    $result = acasa_ensure_donor_wp_user( $create_email, $first_name, $last_name, $donor_id );
    acasa_donor_diag( 'donation-confirmed-created', [
        'payment_id'   => $payment_id,
        'donor_id'     => $donor_id,
        'create_email' => $create_email,
        'result'       => is_wp_error( $result ) ? $result->get_error_message() : $result,
    ] );
}
```

**Step 2: Verify file loads**
```bash
"$PHP" -c "$PHPINI" "$WPCLI" --path="$WP_PATH" eval 'echo "loaded";'
```

**Step 3: Test with WP-CLI -- simulate the resolution order**
```bash
"$PHP" -c "$PHPINI" "$WPCLI" --path="$WP_PATH" eval '
$d = new Give_Donor(1);
echo "Donor 1: email=" . $d->email . " user_id=" . $d->user_id . "\n";
'
```
Verify donor data is accessible.

**Step 4: Commit**
```bash
git -C "W:/www/acasa/app/public/wp-content/plugins/acasa-donor-access" add includes-donor-access.php
git -C "W:/www/acasa/app/public/wp-content/themes/acasa-child" commit -m "feat(donor): rewrite donation-confirmed flow with multi-email consolidation

Resolution order: linked user -> primary email -> checkout email -> create.
Prevents duplicate WP users for multi-email donors.
Skips relinking when existing link is valid.
Logs conflicts with hashed email + domain."
```

---

## Task 6: Implementation report + child theme complete

**Files:**
- Create: `acasa-child/reports/completed-donor-hardening-implementation.md`

**Step 1: Write the report**

Summarize Part 1 changes: what was changed, why, what each commit does. List acceptance criteria status.

**Step 2: Commit**
```bash
git -C "W:/www/acasa/app/public/wp-content/themes/acasa-child" add reports/ docs/
git -C "W:/www/acasa/app/public/wp-content/themes/acasa-child" commit -m "docs: add implementation report and plan for donor hardening"
```

---

## Task 7: Donor Health class -- scan methods

**Files:**
- Create: `acasa-data-tools/includes/class-donor-health.php`

**Step 1: Create the class with all 11 scan checks**

Create `includes/class-donor-health.php` with:
- `Acasa_Donor_Health` class
- `static scan(): array` -- returns categorized findings for all 11 checks
- Check methods: `scan_orphan_avatar()`, `scan_file_missing()`, `scan_owner_mismatch()`, `scan_donor_user_missing()`, `scan_email_mismatch()`, `scan_duplicate_avatar()`, `scan_stale_legacy_meta()`, `scan_orphaned_donor_wp_user()`, `scan_additional_email_has_wp_user()`, `scan_multiple_donors_same_user()`
- Each returns array of finding rows with: `donor_id`, `donor_name`, `donor_email` (masked), `user_id`, `issue`, `tier` (1 or 2)
- `static repair( string $type, array $ids ): array` -- executes Tier 1 repairs only; returns results
- `static log_repair( string $check_type, string $action, array $before, array $after ): void` -- writes to audit table
- AJAX handlers: `ajax_scan()`, `ajax_repair()`

Key SQL patterns:
- Orphan avatar: JOIN `give_donormeta` (meta_key=`_give_donor_avatar_id`) LEFT JOIN `wp_posts` WHERE `wp_posts.ID IS NULL`
- File missing: load attachment IDs from donor meta, check `get_attached_file()` + `file_exists()`
- Orphaned WP user: query `wp_usermeta` for `wp_capabilities` LIKE `give_donor`, LEFT JOIN `give_donors` ON `user_id`, WHERE `give_donors.id IS NULL`
- Additional email duplicate: query `give_donormeta` WHERE `meta_key='additional_email'`, join `wp_users` on `user_email`, exclude donor's own linked user

**Step 2: Verify syntax**
```bash
"$PHP" -c "$PHPINI" -l "W:/www/acasa/app/public/wp-content/plugins/acasa-data-tools/includes/class-donor-health.php"
```

**Step 3: Commit**
```bash
git -C "W:/www/acasa/app/public/wp-content/plugins/acasa-data-tools" add includes/class-donor-health.php
git -C "W:/www/acasa/app/public/wp-content/plugins/acasa-data-tools" commit -m "feat: add Donor Health scan class with 11 integrity checks"
```

---

## Task 8: Audit table + plugin bootstrap

**Files:**
- Modify: `acasa-data-tools/acasa-data-tools.php`

**Step 1: Add audit table creation on activation**

Add to the class:
- `register_activation_hook` that calls `create_audit_table()`
- `create_audit_table()` method using `dbDelta()` to create `{prefix}acasa_repair_log`
- Schema: `id`, `created_at`, `actor_user_id`, `environment`, `check_type`, `action_type`, `target_donor_id`, `target_user_id`, `before_value` (TEXT), `after_value` (TEXT)

**Step 2: Register Donor Health menu + AJAX**

In `register_menus()`, add:
```php
add_submenu_page(
    $parent,
    'Donor Health',
    'Donor Health',
    'manage_options',
    'acasa-donor-health',
    [ $this, 'render_donor_health_page' ]
);
```

In `register_ajax()`, add:
```php
add_action( 'wp_ajax_acasa_scan_donor_health', [ 'Acasa_Donor_Health', 'ajax_scan' ] );
add_action( 'wp_ajax_acasa_repair_donor_health', [ 'Acasa_Donor_Health', 'ajax_repair' ] );
```

Add `require_once` for the new class. Add `render_donor_health_page()` method.

**Step 3: Verify plugin loads**
```bash
"$PHP" -c "$PHPINI" "$WPCLI" --path="$WP_PATH" eval 'echo "loaded";'
```

**Step 4: Commit**
```bash
git -C "W:/www/acasa/app/public/wp-content/plugins/acasa-data-tools" add acasa-data-tools.php
git -C "W:/www/acasa/app/public/wp-content/plugins/acasa-data-tools" commit -m "feat: register Donor Health tab, audit table, and AJAX handlers"
```

---

## Task 9: Donor Health admin view

**Files:**
- Create: `acasa-data-tools/views/page-donor-health.php`

**Step 1: Create the admin page**

Follow the same pattern as `page-data-integrity.php`:
- Scan button
- Environment indicator at top (shows `$_SERVER['HTTP_HOST']`)
- Results rendered as categorized tables
- Per-row "Fix" button for Tier 1 items
- Per-row "Review" button for Tier 2 items (opens detail with confirmation dialog showing hostname)
- Per-category "Fix All Safe" button only for Tier 1 categories
- Email masking: show `a***@domain.com` by default, full on click
- AJAX calls to `acasa_scan_donor_health` and `acasa_repair_donor_health`

**Step 2: Verify page renders**

Visit `http://acasa.local/wp-admin/admin.php?page=acasa-donor-health` -- page must render without errors.

**Step 3: Commit**
```bash
git -C "W:/www/acasa/app/public/wp-content/plugins/acasa-data-tools" add views/page-donor-health.php
git -C "W:/www/acasa/app/public/wp-content/plugins/acasa-data-tools" commit -m "feat: Donor Health admin view with tiered repair UX"
```

---

## Task 10: Activate audit table + smoke test

**Step 1: Deactivate and reactivate plugin to trigger table creation**
```bash
"$PHP" -c "$PHPINI" "$WPCLI" --path="$WP_PATH" plugin deactivate acasa-data-tools
"$PHP" -c "$PHPINI" "$WPCLI" --path="$WP_PATH" plugin activate acasa-data-tools
```

**Step 2: Verify audit table exists**
```bash
"$PHP" -c "$PHPINI" "$WPCLI" --path="$WP_PATH" eval '
global $wpdb;
$table = $wpdb->prefix . "acasa_repair_log";
$exists = $wpdb->get_var("SHOW TABLES LIKE \"$table\"");
echo $exists ? "OK: $table exists" : "FAIL: table missing";
'
```

**Step 3: Run a scan from the admin page**

Visit `http://acasa.local/wp-admin/admin.php?page=acasa-donor-health`, click "Run Scan", verify results display.

**Step 4: Commit any fixes if needed**

---

## Task 11: Plugin implementation report

**Files:**
- Create: `acasa-data-tools/reports/completed-donor-health-implementation.md`

**Step 1: Write report summarizing Part 2 implementation**

**Step 2: Commit**
```bash
git -C "W:/www/acasa/app/public/wp-content/plugins/acasa-data-tools" add reports/
git -C "W:/www/acasa/app/public/wp-content/plugins/acasa-data-tools" commit -m "docs: add Donor Health implementation report"
```

---

## Verification Checklist (human + reviewer)

Before requesting deploy approval:

1. [ ] WP-CLI `eval 'echo "loaded";'` passes with no warnings
2. [ ] Donor dashboard profile edit works (name, email, avatar)
3. [ ] Avatar upload with oversized image shows Romanian error message
4. [ ] Donor Health scan runs and returns results
5. [ ] "Fix All Safe" only affects Tier 1 items
6. [ ] Tier 2 items show "Review" button with environment-aware confirmation
7. [ ] Audit table records repairs correctly
8. [ ] Magic link login + logout cycle works
9. [ ] Header avatar renders correctly for logged-in donor
10. [ ] No new PHP warnings in error log during normal operation

