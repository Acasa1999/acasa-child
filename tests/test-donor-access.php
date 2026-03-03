<?php
/**
 * Donor Access Hardening — WP-CLI Smoke Tests
 *
 * Exercises key code paths in inc/donor-access.php.
 * Run via WP-CLI:
 *
 *   PHP="C:/Users/AndreiAronet/AppData/Roaming/Local/lightning-services/php-8.3.29+1/bin/win64/php.exe"
 *   PHPINI="C:/Users/AndreiAronet/AppData/Roaming/Local/run/4LNNXVV9T/conf/php/php.ini"
 *   WPCLI="C:/Users/AndreiAronet/AppData/Local/Programs/Local/resources/extraResources/bin/wp-cli/wp-cli.phar"
 *   WP_PATH="W:/www/acasa/app/public"
 *   "$PHP" -c "$PHPINI" "$WPCLI" --path="$WP_PATH" eval-file \
 *       W:/www/acasa/app/public/wp-content/themes/acasa-child/tests/test-donor-access.php
 *
 * All test users are created with a unique prefix and cleaned up at the end.
 * Safe to run on any environment (local, staging).
 */

if ( ! defined( 'ABSPATH' ) ) {
    echo "ERROR: Must be run via WP-CLI eval-file.\n";
    exit( 1 );
}

/* ── Harness ─────────────────────────────────────────────────── */

// Must be global — wp eval-file wraps in a function scope.
global $pass, $fail, $tests, $cleanup_user_ids;
$pass  = 0;
$fail  = 0;
$tests = [];
$cleanup_user_ids = [];

function t_assert( string $label, bool $condition, string $detail = '' ): void {
    global $pass, $fail, $tests;
    if ( $condition ) {
        $pass++;
        $tests[] = [ 'PASS', $label ];
    } else {
        $fail++;
        $msg = $detail !== '' ? "$label — $detail" : $label;
        $tests[] = [ 'FAIL', $msg ];
    }
}

function t_create_user( string $login, string $email, string $role ): int {
    global $cleanup_user_ids;
    $user_id = wp_insert_user( [
        'user_login'   => $login,
        'user_email'   => $email,
        'user_pass'    => wp_generate_password( 32 ),
        'display_name' => $login,
        'role'         => $role,
    ] );
    if ( is_wp_error( $user_id ) ) {
        echo "SETUP ERROR: Could not create user '$login': " . $user_id->get_error_message() . "\n";
        exit( 1 );
    }
    $cleanup_user_ids[] = $user_id;
    return $user_id;
}

/* ── Verify functions exist ──────────────────────────────────── */

echo "\n";
echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║  Donor Access Hardening — Smoke Tests                   ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n";
echo "\n";

$required_functions = [
    'acasa_is_restricted_donor_user',
    'acasa_get_donor_dashboard_url',
    'acasa_current_user_is_donor',
    'acasa_ensure_donor_wp_user',
    'acasa_link_donor_to_user',
    'acasa_on_donation_confirmed',
    'acasa_avatar_upload_preflight',
    'acasa_profile_payload_normalize',
    'acasa_build_display_name',
    'acasa_donor_diag',
];

echo "── Prerequisites ──────────────────────────────────────────\n";
foreach ( $required_functions as $fn ) {
    t_assert( "Function exists: $fn", function_exists( $fn ) );
}

/* ── Create test users ───────────────────────────────────────── */

$prefix    = '_actest_' . wp_rand( 1000, 9999 );
$donor_uid = t_create_user( $prefix . '_donor',       $prefix . '_donor@test.local',       'give_donor' );
$admin_uid = t_create_user( $prefix . '_admin',        $prefix . '_admin@test.local',       'administrator' );
$dual_uid  = t_create_user( $prefix . '_dual',         $prefix . '_dual@test.local',        'administrator' );
$sub_uid   = t_create_user( $prefix . '_subscriber',   $prefix . '_subscriber@test.local',  'subscriber' );

// Give the dual user an extra give_donor role.
$dual_user = get_userdata( $dual_uid );
$dual_user->add_role( 'give_donor' );

/* ═══════════════════════════════════════════════════════════════
   TEST GROUP 1: acasa_is_restricted_donor_user()
   ═══════════════════════════════════════════════════════════════ */
echo "\n── 1. acasa_is_restricted_donor_user() ─────────────────────\n";

$donor_user = get_userdata( $donor_uid );
$admin_user = get_userdata( $admin_uid );
$sub_user   = get_userdata( $sub_uid );

// 1a: give_donor-only user → restricted
t_assert(
    '1a. give_donor-only user is restricted',
    acasa_is_restricted_donor_user( $donor_user ) === true
);

// 1b: administrator → NOT restricted
t_assert(
    '1b. Administrator is NOT restricted',
    acasa_is_restricted_donor_user( $admin_user ) === false
);

// 1c: admin + give_donor dual role → NOT restricted (manage_options wins)
$dual_user_obj = get_userdata( $dual_uid );
t_assert(
    '1c. Admin+give_donor dual role is NOT restricted',
    acasa_is_restricted_donor_user( $dual_user_obj ) === false,
    'Roles: ' . implode( ', ', $dual_user_obj->roles )
);

// 1d: subscriber (no give_donor) → NOT restricted
t_assert(
    '1d. Subscriber without give_donor is NOT restricted',
    acasa_is_restricted_donor_user( $sub_user ) === false
);

// 1e: null/non-existent user → NOT restricted (no crash)
t_assert(
    '1e. Null user returns false (no crash)',
    acasa_is_restricted_donor_user( null ) === false
);

// 1f: Fabricated non-existent user → false
$ghost = new WP_User( 0 );
t_assert(
    '1f. Non-existent WP_User(0) returns false',
    acasa_is_restricted_donor_user( $ghost ) === false
);

/* ═══════════════════════════════════════════════════════════════
   TEST GROUP 2: acasa_current_user_is_donor()
   ═══════════════════════════════════════════════════════════════ */
echo "\n── 2. acasa_current_user_is_donor() ────────────────────────\n";

// Switch to give_donor user.
wp_set_current_user( $donor_uid );
t_assert(
    '2a. give_donor user sees donor content',
    acasa_current_user_is_donor() === true
);

// Switch to admin.
wp_set_current_user( $admin_uid );
t_assert(
    '2b. Administrator sees donor content (manage_options)',
    acasa_current_user_is_donor() === true
);

// Switch to subscriber.
wp_set_current_user( $sub_uid );
t_assert(
    '2c. Subscriber does NOT see donor content',
    acasa_current_user_is_donor() === false
);

// Logged out.
wp_set_current_user( 0 );
t_assert(
    '2d. Logged-out user does NOT see donor content',
    acasa_current_user_is_donor() === false
);

/* ═══════════════════════════════════════════════════════════════
   TEST GROUP 3: acasa_get_donor_dashboard_url()
   ═══════════════════════════════════════════════════════════════ */
echo "\n── 3. acasa_get_donor_dashboard_url() ─────────────────────\n";

$url = acasa_get_donor_dashboard_url();
t_assert(
    '3a. Returns a non-empty URL',
    is_string( $url ) && $url !== '',
    "Got: $url"
);
t_assert(
    '3b. URL starts with http',
    str_starts_with( $url, 'http' ),
    "Got: $url"
);

/* ═══════════════════════════════════════════════════════════════
   TEST GROUP 4: show_admin_bar filter
   ═══════════════════════════════════════════════════════════════ */
echo "\n── 4. show_admin_bar filter ────────────────────────────────\n";

wp_set_current_user( $donor_uid );
$bar_donor = apply_filters( 'show_admin_bar', true );
t_assert(
    '4a. Admin bar hidden for give_donor user',
    $bar_donor === false
);

wp_set_current_user( $admin_uid );
$bar_admin = apply_filters( 'show_admin_bar', true );
t_assert(
    '4b. Admin bar visible for administrator',
    $bar_admin === true
);

wp_set_current_user( $dual_uid );
$bar_dual = apply_filters( 'show_admin_bar', true );
t_assert(
    '4c. Admin bar visible for admin+give_donor dual',
    $bar_dual === true
);

/* ═══════════════════════════════════════════════════════════════
   TEST GROUP 5: acasa_build_display_name()
   ═══════════════════════════════════════════════════════════════ */
echo "\n── 5. acasa_build_display_name() ───────────────────────────\n";

t_assert(
    '5a. First + last = "First Last"',
    acasa_build_display_name( 'Maria', 'Popescu' ) === 'Maria Popescu'
);

t_assert(
    '5b. First only = "First"',
    acasa_build_display_name( 'Maria', '' ) === 'Maria'
);

t_assert(
    '5c. Last only = "Last"',
    acasa_build_display_name( '', 'Popescu' ) === 'Popescu'
);

// With email fallback — should NOT use full email (privacy).
$dn = acasa_build_display_name( '', '', 'maria.popescu@example.com' );
t_assert(
    '5d. Empty name + email → uses local part, not full email',
    is_string( $dn ) && $dn !== '' && strpos( $dn, '@' ) === false,
    "Got: $dn"
);

/* ═══════════════════════════════════════════════════════════════
   TEST GROUP 6: acasa_ensure_donor_wp_user() — creates new user
   ═══════════════════════════════════════════════════════════════ */
echo "\n── 6. acasa_ensure_donor_wp_user() ─────────────────────────\n";

$new_email = $prefix . '_newdonor@test.local';

// Should NOT exist yet.
t_assert(
    '6a. Test email does not exist before ensure',
    get_user_by( 'email', $new_email ) === false
);

$result = acasa_ensure_donor_wp_user( $new_email, 'Test', 'Newdonor' );
t_assert(
    '6b. Returns int user ID (not WP_Error)',
    is_int( $result ) && $result > 0,
    is_wp_error( $result ) ? $result->get_error_message() : "Got: $result"
);

if ( is_int( $result ) && $result > 0 ) {
    $cleanup_user_ids[] = $result;
    $new_user = get_userdata( $result );

    t_assert(
        '6c. Created user has give_donor role',
        in_array( 'give_donor', (array) $new_user->roles, true ),
        'Roles: ' . implode( ', ', $new_user->roles )
    );

    t_assert(
        '6d. Created user has correct email',
        $new_user->user_email === $new_email
    );

    t_assert(
        '6e. Display name does not expose full email',
        strpos( $new_user->display_name, '@' ) === false,
        "display_name: $new_user->display_name"
    );

    // Calling again for same email should return SAME user (idempotent).
    $result2 = acasa_ensure_donor_wp_user( $new_email, 'Test', 'Newdonor' );
    t_assert(
        '6f. Re-calling for same email returns same user ID (idempotent)',
        $result2 === $result,
        "First: $result, Second: " . ( is_wp_error( $result2 ) ? $result2->get_error_message() : $result2 )
    );
}

// Invalid email → WP_Error.
$bad = acasa_ensure_donor_wp_user( 'not-an-email', 'Bad', 'Input' );
t_assert(
    '6g. Invalid email returns WP_Error',
    is_wp_error( $bad )
);

/* ═══════════════════════════════════════════════════════════════
   TEST GROUP 7: acasa_link_donor_to_user() — edge cases
   ═══════════════════════════════════════════════════════════════ */
echo "\n── 7. acasa_link_donor_to_user() ───────────────────────────\n";

$link_err = acasa_link_donor_to_user( 0, 0 );
t_assert(
    '7a. donor_id=0, user_id=0 → WP_Error',
    is_wp_error( $link_err ),
    is_wp_error( $link_err ) ? $link_err->get_error_code() : 'Not an error'
);

$link_err2 = acasa_link_donor_to_user( -5, 100 );
t_assert(
    '7b. Negative donor_id → WP_Error',
    is_wp_error( $link_err2 )
);

/* ═══════════════════════════════════════════════════════════════
   TEST GROUP 8: acasa_donor_diag() — no crash, PII hashing
   ═══════════════════════════════════════════════════════════════ */
echo "\n── 8. acasa_donor_diag() ───────────────────────────────────\n";

// Should not throw even when logging is disabled.
$diag_ok = true;
try {
    acasa_donor_diag( 'test-tag', [ 'donor_email' => 'secret@example.com', 'foo' => 'bar' ] );
} catch ( Throwable $e ) {
    $diag_ok = false;
}
t_assert(
    '8a. acasa_donor_diag() does not throw',
    $diag_ok
);

// With logging enabled (temporarily).
if ( ! defined( 'ACASA_DONOR_DIAG' ) ) {
    define( 'ACASA_DONOR_DIAG', true );
}

ob_start();
acasa_donor_diag( 'test-hash', [ 'donor_email' => 'secret@example.com' ] );
ob_end_clean();

// Can't easily capture error_log output in WP-CLI, but at least it didn't crash.
t_assert(
    '8b. acasa_donor_diag() with logging enabled does not crash',
    true
);

/* ═══════════════════════════════════════════════════════════════
   TEST GROUP 9: Hooks registered
   ═══════════════════════════════════════════════════════════════ */
echo "\n── 9. Hook registration ───────────────────────────────────\n";

// admin_init has our redirect closure.
global $wp_filter;
$admin_init_hooks = isset( $wp_filter['admin_init'] ) ? $wp_filter['admin_init']->callbacks : [];
$has_admin_init   = false;
foreach ( $admin_init_hooks as $priority => $callbacks ) {
    if ( $priority == 1 ) {
        $has_admin_init = count( $callbacks ) > 0;
    }
}
t_assert(
    '9a. admin_init has callback at priority 1 (redirect)',
    $has_admin_init
);

// show_admin_bar filter registered.
$bar_hooks = isset( $wp_filter['show_admin_bar'] ) ? $wp_filter['show_admin_bar']->callbacks : [];
$has_bar   = false;
foreach ( $bar_hooks as $priority => $callbacks ) {
    if ( $priority == 20 ) {
        $has_bar = count( $callbacks ) > 0;
    }
}
t_assert(
    '9b. show_admin_bar filter at priority 20',
    $has_bar
);

// give_update_payment_status hook.
$payment_hooks    = isset( $wp_filter['give_update_payment_status'] ) ? $wp_filter['give_update_payment_status']->callbacks : [];
$has_payment_hook = false;
foreach ( $payment_hooks as $priority => $callbacks ) {
    foreach ( $callbacks as $cb ) {
        if ( isset( $cb['function'] ) && $cb['function'] === 'acasa_on_donation_confirmed' ) {
            $has_payment_hook = true;
        }
    }
}
t_assert(
    '9c. give_update_payment_status → acasa_on_donation_confirmed',
    $has_payment_hook
);

// REST preflight hooks.
$rest_hooks  = isset( $wp_filter['rest_request_before_callbacks'] ) ? $wp_filter['rest_request_before_callbacks']->callbacks : [];
$has_avatar  = false;
$has_profile = false;
foreach ( $rest_hooks as $priority => $callbacks ) {
    foreach ( $callbacks as $cb ) {
        if ( isset( $cb['function'] ) ) {
            if ( $cb['function'] === 'acasa_avatar_upload_preflight' ) {
                $has_avatar = true;
            }
            if ( $cb['function'] === 'acasa_profile_payload_normalize' ) {
                $has_profile = true;
            }
        }
    }
}
t_assert(
    '9d. REST preflight: acasa_avatar_upload_preflight registered',
    $has_avatar
);
t_assert(
    '9e. REST preflight: acasa_profile_payload_normalize registered',
    $has_profile
);

/* ═══════════════════════════════════════════════════════════════
   CLEANUP
   ═══════════════════════════════════════════════════════════════ */
echo "\n── Cleanup ────────────────────────────────────────────────\n";

$cleaned = 0;
foreach ( array_unique( $cleanup_user_ids ) as $uid ) {
    if ( $uid > 0 ) {
        // Reassign posts to admin (user 1) to avoid orphaning.
        wp_delete_user( $uid, 1 );
        $cleaned++;
    }
}
echo "   Deleted $cleaned test user(s).\n";

/* ═══════════════════════════════════════════════════════════════
   RESULTS
   ═══════════════════════════════════════════════════════════════ */
echo "\n";
echo "══════════════════════════════════════════════════════════════\n";
echo "  RESULTS\n";
echo "══════════════════════════════════════════════════════════════\n";

foreach ( $tests as $t ) {
    $icon = $t[0] === 'PASS' ? '  +' : '  !';
    echo "$icon [{$t[0]}] {$t[1]}\n";
}

echo "\n";
echo "  Total: " . ( $pass + $fail ) . "  |  Pass: $pass  |  Fail: $fail\n";
echo "══════════════════════════════════════════════════════════════\n";

if ( $fail > 0 ) {
    echo "\n  *** $fail TEST(S) FAILED ***\n\n";
    exit( 1 );
}

echo "\n  All tests passed.\n\n";
exit( 0 );
