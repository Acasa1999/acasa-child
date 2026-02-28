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
