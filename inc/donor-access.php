<?php
/**
 * Donor Access
 *
 * Handles WP user creation on donation, magic link WP session upgrade,
 * donor-only content gating, and GiveWP update safety warning.
 *
 * Hook dependency: Give()->email_access->token_exists / token_email
 * set at wp@14 (Give_Email_Access::init). Our hooks run at wp@15.
 *
 * WARNING: If GiveWP is updated, verify Give_Email_Access::init() still
 * fires on the `wp` action at priority 14 and still sets token_exists
 * and token_email before priority 15.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Create or update a WP user account for a donor.
 *
 * @param string $email         Donor email.
 * @param string $first_name    Donor first name (may be empty).
 * @param string $last_name     Donor last name (may be empty).
 * @param int    $give_donor_id GiveWP donor ID (for linking).
 * @return int|WP_Error WP user ID on success, WP_Error on failure.
 */
function acasa_ensure_donor_wp_user( $email, $first_name = '', $last_name = '', $give_donor_id = 0 ) {
    if ( empty( $email ) || ! is_email( $email ) ) {
        return new WP_Error( 'invalid_email', 'Invalid donor email.' );
    }

    $existing = get_user_by( 'email', $email );

    if ( $existing ) {
        // User exists — ensure give_donor role is present.
        if ( ! in_array( 'give_donor', (array) $existing->roles, true ) ) {
            $existing->add_role( 'give_donor' );
        }
        $user_id = $existing->ID;
    } else {
        // Build display name from available data.
        // Priority: first+last → first alone → email fallback (last alone is not valid).
        if ( ! empty( $first_name ) && ! empty( $last_name ) ) {
            $display_name = $first_name . ' ' . $last_name;
        } elseif ( ! empty( $first_name ) ) {
            $display_name = $first_name;
        } else {
            $display_name = $email;
        }

        // Suppress WP's new-user notification for this creation only.
        add_filter( 'wp_send_new_user_notifications', '__return_false' );

        $user_id = wp_insert_user( [
            'user_email'   => $email,
            'user_login'   => $email,
            'user_pass'    => wp_generate_password( 64, true, true ),
            'display_name' => $display_name,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'role'         => 'give_donor',
        ] );

        remove_filter( 'wp_send_new_user_notifications', '__return_false' );

        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }
    }

    // Link WP user to GiveWP donor record.
    if ( $give_donor_id > 0 ) {
        Give()->donors->update( $give_donor_id, [ 'user_id' => $user_id ] );
    }

    return $user_id;
}

/**
 * Hook: create WP user when a donation is confirmed.
 * Fires on status transition to 'publish' (successful payment).
 */
add_action( 'give_update_payment_status', 'acasa_on_donation_confirmed', 10, 3 );

function acasa_on_donation_confirmed( $payment_id, $new_status, $old_status ) {
    // Only act on transitions TO 'publish' (confirmed payment).
    if ( 'publish' !== $new_status ) {
        return;
    }
    // Guard against re-running if already was publish.
    if ( 'publish' === $old_status ) {
        return;
    }

    $email      = give_get_payment_user_email( $payment_id );
    $user_info  = give_get_payment_meta_user_info( $payment_id );
    $first_name = $user_info['first_name'] ?? '';
    $last_name  = $user_info['last_name'] ?? '';
    $donor_id   = give_get_payment_donor_id( $payment_id );

    acasa_ensure_donor_wp_user( $email, $first_name, $last_name, (int) $donor_id );
}

/**
 * Returns true if the current user is a logged-in give_donor.
 */
function acasa_current_user_is_donor(): bool {
    return is_user_logged_in() && current_user_can( 'give_donor' );
}

/**
 * Exclude 'donatori' category from all queries for non-donors.
 */
add_action( 'pre_get_posts', 'acasa_exclude_donatori_from_queries' );

function acasa_exclude_donatori_from_queries( WP_Query $query ) {
    if ( acasa_current_user_is_donor() ) {
        return;
    }

    $donatori = get_category_by_slug( 'donatori' );
    if ( ! $donatori ) {
        return; // Category doesn't exist yet — do nothing.
    }

    $excluded = $query->get( 'category__not_in' );
    $excluded = array_values( array_filter( array_map( 'intval', (array) $excluded ) ) );
    $excluded[] = $donatori->term_id;
    $query->set( 'category__not_in', $excluded );
}

/**
 * Return 404 for direct URL access to 'donatori' posts by non-donors.
 */
add_action( 'template_redirect', 'acasa_gate_donatori_single' );

function acasa_gate_donatori_single() {
    if ( ! is_singular( 'post' ) ) {
        return;
    }
    if ( acasa_current_user_is_donor() ) {
        return;
    }
    if ( has_category( 'donatori', get_queried_object_id() ) ) {
        global $wp_query;
        $wp_query->set_404();
        status_header( 404 );
        nocache_headers();
    }
}

/**
 * Set a flag when GiveWP is updated, so we can warn about hook verification.
 */
add_action( 'upgrader_process_complete', 'acasa_detect_give_update', 10, 2 );

function acasa_detect_give_update( $upgrader, $options ) {
    if (
        isset( $options['action'], $options['type'], $options['plugins'] ) &&
        $options['action'] === 'update' &&
        $options['type'] === 'plugin' &&
        in_array( 'give/give.php', (array) $options['plugins'], true )
    ) {
        update_option( 'acasa_give_update_warning', '1' );
    }
}

/**
 * Show persistent admin notice after GiveWP update until dismissed.
 */
add_action( 'admin_notices', 'acasa_give_update_admin_notice' );

function acasa_give_update_admin_notice() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Dismiss: only delete if the warning actually exists.
    if ( isset( $_GET['acasa_dismiss_give_notice'] ) && get_option( 'acasa_give_update_warning' ) ) {
        delete_option( 'acasa_give_update_warning' );
        return;
    }

    if ( ! get_option( 'acasa_give_update_warning' ) ) {
        return;
    }

    $dismiss_url = add_query_arg( 'acasa_dismiss_give_notice', '1' );
    printf(
        '<div class="notice notice-warning"><p>'
        . '<strong>ACASA Donor Access:</strong> GiveWP was updated. '
        . 'Verify that <code>Give_Email_Access::init()</code> still fires on the <code>wp</code> '
        . 'action at priority&nbsp;14 and sets <code>token_exists</code> / <code>token_email</code> '
        . 'before priority&nbsp;15 — donor magic link login depends on it. '
        . '<a href="%s">Dismiss once verified</a>.</p></div>',
        esc_url( $dismiss_url )
    );
}

/**
 * After GiveWP validates an email-access token (wp@14), upgrade
 * the GiveWP session to a real WordPress login session (wp@15).
 *
 * Depends on: Give_Email_Access::init() at wp@14
 * Hook to verify after GiveWP updates: Give_Email_Access::init() in
 *   give/includes/class-give-email-access.php
 */
add_action( 'wp', 'acasa_upgrade_email_access_to_wp_session', 15 );

function acasa_upgrade_email_access_to_wp_session() {
    // Already logged in — nothing to do.
    if ( is_user_logged_in() ) {
        return;
    }

    // GiveWP hasn't validated a token on this request.
    if ( ! Give()->email_access->token_exists ) {
        return;
    }

    $email = Give()->email_access->token_email;
    if ( empty( $email ) ) {
        return;
    }

    $user = get_user_by( 'email', $email );
    if ( ! $user ) {
        // Donor has no WP account yet — defensive fallback.
        return;
    }

    // Use GiveWP's session lifetime setting for the WP auth cookie.
    $expiry = (int) give_get_option( 'session_lifetime', 172800 );

    $expiry_filter = function() use ( $expiry ) {
        return $expiry;
    };
    add_filter( 'auth_cookie_expiration', $expiry_filter );

    wp_set_current_user( $user->ID );
    wp_set_auth_cookie( $user->ID, true );

    remove_filter( 'auth_cookie_expiration', $expiry_filter );
}

/**
 * Register Tools > Sync Donor Accounts admin page.
 */
add_action( 'admin_menu', function() {
    add_management_page(
        'Sync Donor Accounts',
        'Sync Donor Accounts',
        'manage_options',
        'acasa-sync-donors',
        'acasa_render_sync_donors_page'
    );
} );

function acasa_render_sync_donors_page() {
    ?>
    <div class="wrap">
        <h1>Sync Donor Accounts</h1>
        <p>Creates WP user accounts (role: <code>give_donor</code>) for all donors with at least one
        confirmed donation. Safe to run multiple times — existing accounts are skipped.</p>
        <button id="acasa-sync-btn" class="button button-primary">Run Sync</button>
        <span id="acasa-sync-status" style="margin-left:12px;"></span>
        <div id="acasa-sync-results" style="margin-top:16px;font-family:monospace;white-space:pre;"></div>
    </div>
    <script>
    document.getElementById('acasa-sync-btn').addEventListener('click', function() {
        var btn = this;
        btn.disabled = true;
        document.getElementById('acasa-sync-status').textContent = 'Running\u2026';
        document.getElementById('acasa-sync-results').textContent = '';

        fetch(ajaxurl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=acasa_sync_donors&_wpnonce=<?php echo esc_js( wp_create_nonce( 'acasa_sync_donors' ) ); ?>'
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            document.getElementById('acasa-sync-status').textContent = data.success ? 'Done.' : 'Error.';
            document.getElementById('acasa-sync-results').textContent = data.data.message;
            btn.disabled = false;
        })
        .catch(function() {
            document.getElementById('acasa-sync-status').textContent = 'Request failed.';
            btn.disabled = false;
        });
    });
    </script>
    <?php
}

/**
 * AJAX handler for donor sync — runs the sync and returns JSON.
 */
add_action( 'wp_ajax_acasa_sync_donors', 'acasa_ajax_sync_donors' );

function acasa_ajax_sync_donors() {
    check_ajax_referer( 'acasa_sync_donors' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
    }

    $results = acasa_run_donor_sync();

    wp_send_json_success( [
        'message' => sprintf(
            "Created: %d\nRole added to existing user: %d\nSkipped (already correct): %d\nErrors: %d",
            $results['created'],
            $results['role_added'],
            $results['skipped'],
            $results['errors']
        ),
    ] );
}

/**
 * Core sync logic. Idempotent — safe to run multiple times.
 *
 * Queries the give_donors custom table (GiveWP v3+ stores donor data there,
 * not in wp_postmeta). Donors with purchase_count > 0 have at least one
 * confirmed donation.
 *
 * @return array{created: int, role_added: int, skipped: int, errors: int}
 */
function acasa_run_donor_sync(): array {
    $counts = [ 'created' => 0, 'role_added' => 0, 'skipped' => 0, 'errors' => 0 ];

    // GiveWP v3+ stores all donor records in give_donors (not wp_postmeta).
    // purchase_count > 0 means at least one confirmed donation exists.
    global $wpdb;
    $donors = $wpdb->get_results( "
        SELECT id, email, name
        FROM {$wpdb->prefix}give_donors
        WHERE purchase_count > 0
          AND email != ''
    " );

    foreach ( $donors as $donor ) {
        $email = trim( $donor->email );

        if ( ! is_email( $email ) ) {
            $counts['errors']++;
            continue;
        }

        $existing = get_user_by( 'email', $email );

        if ( $existing ) {
            if ( in_array( 'give_donor', (array) $existing->roles, true ) ) {
                $counts['skipped']++;
            } else {
                $existing->add_role( 'give_donor' );
                $counts['role_added']++;
            }
        } else {
            // Split the full name from give_donors.name into first / last.
            $full_name  = trim( $donor->name );
            $space_pos  = strpos( $full_name, ' ' );
            if ( $space_pos !== false ) {
                $first_name = substr( $full_name, 0, $space_pos );
                $last_name  = substr( $full_name, $space_pos + 1 );
            } else {
                $first_name = $full_name;
                $last_name  = '';
            }

            $result = acasa_ensure_donor_wp_user( $email, $first_name, $last_name, (int) $donor->id );
            if ( is_wp_error( $result ) ) {
                $counts['errors']++;
            } else {
                $counts['created']++;
            }
        }
    }

    return $counts;
}
