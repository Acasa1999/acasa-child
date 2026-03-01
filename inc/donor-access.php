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

// All hooks below depend on GiveWP. Bail if the plugin is not active.
if ( ! class_exists( 'Give' ) ) {
    return;
}

/* =========================================================================
   Shared helpers
   ========================================================================= */

/**
 * Build a display name from available parts.
 *
 * Priority: first+last → first → last → first 3 chars of email + "…".
 * Full email is never returned (privacy).
 *
 * @return string Display name (may be empty if all inputs are empty).
 */
function acasa_build_display_name( string $first, string $last, string $email = '' ): string {
    $first = trim( $first );
    $last  = trim( $last );

    if ( $first !== '' && $last !== '' ) {
        return "$first $last";
    }
    if ( $first !== '' ) {
        return $first;
    }
    if ( $last !== '' ) {
        return $last;
    }
    if ( $email !== '' ) {
        $local = strstr( $email, '@', true );
        if ( $local === false ) {
            $local = $email;
        }
        $prefix = function_exists( 'mb_substr' )
            ? mb_substr( $local, 0, 3 )
            : substr( $local, 0, 3 );
        return $prefix . '…';
    }
    return '';
}

/**
 * Default account label used in header when no manual override exists.
 *
 * Priority: first_name -> display_name (if not email) -> privacy-safe fallback.
 */
function acasa_default_account_label( WP_User $user ): string {
    $first = trim( (string) get_user_meta( (int) $user->ID, 'first_name', true ) );
    if ( $first !== '' ) {
        return $first;
    }

    $display = trim( (string) $user->display_name );
    if ( $display !== '' && $display !== (string) $user->user_email ) {
        return $display;
    }

    $fallback = acasa_build_display_name(
        (string) get_user_meta( (int) $user->ID, 'first_name', true ),
        (string) get_user_meta( (int) $user->ID, 'last_name', true ),
        (string) $user->user_email
    );

    return $fallback !== '' ? $fallback : 'Contul meu';
}

/**
 * Mark display_name as manually overridden when user explicitly chooses
 * something other than the theme default label in profile settings.
 *
 * This lets header keep first-name default while honoring deliberate edits.
 */
function acasa_track_display_name_override( int $user_id, WP_User $old_user_data ): void {
    if ( ! current_user_can( 'edit_user', $user_id ) ) {
        return;
    }

    if ( ! isset( $_POST['display_name'] ) ) {
        return;
    }

    $raw_display_name = wp_unslash( $_POST['display_name'] );
    $selected_display = sanitize_text_field( (string) $raw_display_name );

    // If display_name was not changed on this profile save, keep prior flag.
    if ( $selected_display === (string) $old_user_data->display_name ) {
        return;
    }

    $user = get_userdata( $user_id );
    if ( ! $user instanceof WP_User ) {
        return;
    }

    $default_label = acasa_default_account_label( $user );
    $is_manual_override = $selected_display !== '' && $selected_display !== $default_label;

    if ( $is_manual_override ) {
        update_user_meta( $user_id, 'acasa_display_name_override', '1' );
    } else {
        delete_user_meta( $user_id, 'acasa_display_name_override' );
    }
}
add_action( 'profile_update', 'acasa_track_display_name_override', 20, 2 );

/**
 * Check whether a user is allowed to have a custom avatar.
 *
 * Today: give_donor role. Future: add 'volunteer' here.
 */
function acasa_user_can_have_avatar( int $user_id ): bool {
    $user = get_userdata( $user_id );
    if ( ! $user ) {
        return false;
    }
    return in_array( 'give_donor', (array) $user->roles, true );
}

/**
 * Resolve canonical donor avatar attachment ID from GiveWP donor meta.
 *
 * For compatibility, migrate legacy acasa_avatar_id usermeta to GiveWP once.
 */
function acasa_get_donor_avatar_id( int $user_id ): int {
    if ( $user_id <= 0 ) {
        return 0;
    }

    $donor = new Give_Donor( $user_id, true );
    if ( ! $donor || empty( $donor->id ) ) {
        return 0;
    }

    $avatar_id = (int) Give()->donor_meta->get_meta( $donor->id, '_give_donor_avatar_id', true );
    if ( $avatar_id > 0 ) {
        return $avatar_id;
    }

    $legacy_avatar_id = (int) get_user_meta( $user_id, 'acasa_avatar_id', true );
    if ( $legacy_avatar_id > 0 ) {
        Give()->donor_meta->update_meta( $donor->id, '_give_donor_avatar_id', $legacy_avatar_id );
        delete_user_meta( $user_id, 'acasa_avatar_id' );
        return $legacy_avatar_id;
    }

    return 0;
}

/**
 * Resolve avatar URL from attachment ID with a preferred square size.
 */
function acasa_get_avatar_url_from_attachment( int $attachment_id, $args = [] ): string {
    if ( $attachment_id <= 0 ) {
        return '';
    }

    $size = isset( $args['size'] ) ? max( 16, (int) $args['size'] ) : 96;
    $image = wp_get_attachment_image_src( $attachment_id, [ $size, $size ] );
    if ( is_array( $image ) && ! empty( $image[0] ) ) {
        return (string) $image[0];
    }

    $url = wp_get_attachment_url( $attachment_id );
    return is_string( $url ) ? $url : '';
}

/* =========================================================================
   Avatar system — local-only, no Gravatar
   ========================================================================= */

/**
 * Intercept every avatar lookup. Serve local attachment or "A" SVG default.
 * Gravatar URLs are never generated.
 */
add_filter( 'pre_get_avatar_data', 'acasa_local_avatar_data', 10, 2 );

function acasa_local_avatar_data( $args, $id_or_email ) {
    // Resolve to a WP user ID.
    $user_id = 0;
    if ( is_numeric( $id_or_email ) ) {
        $user_id = (int) $id_or_email;
    } elseif ( $id_or_email instanceof WP_User ) {
        $user_id = $id_or_email->ID;
    } elseif ( $id_or_email instanceof WP_Post ) {
        $user_id = (int) $id_or_email->post_author;
    } elseif ( $id_or_email instanceof WP_Comment ) {
        if ( ! empty( $id_or_email->user_id ) ) {
            $user_id = (int) $id_or_email->user_id;
        }
    } elseif ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
        $user = get_user_by( 'email', $id_or_email );
        if ( $user ) {
            $user_id = $user->ID;
        }
    }

    // Default: "A" SVG from child theme.
    $default_url = get_stylesheet_directory_uri() . '/avatar-default.svg';

    if ( $user_id <= 0 ) {
        $args['url']          = $default_url;
        $args['found_avatar'] = true;
        return $args;
    }

    // Canonical source: GiveWP donor meta (_give_donor_avatar_id).
    $attachment_id = acasa_get_donor_avatar_id( $user_id );
    $url = acasa_get_avatar_url_from_attachment( $attachment_id, $args );

    // 3. Serve resolved URL or fall back to "A" SVG.
    if ( $url ) {
        $args['url']          = $url;
        $args['found_avatar'] = true;
        return $args;
    }

    $args['url']          = $default_url;
    $args['found_avatar'] = true;
    return $args;
}

/* =========================================================================
   Avatar controls in WP profile (read + delete only)
   ========================================================================= */

/**
 * Replace WP's Gravatar profile picture section with local avatar controls.
 *
 * Upload/change stays in GiveWP Donor Dashboard.
 * WP profile only shows current avatar and allows delete.
 */
add_filter( 'user_profile_picture_description', '__return_empty_string' );

add_action( 'admin_head-profile.php',  'acasa_avatar_admin_styles' );
add_action( 'admin_head-user-edit.php','acasa_avatar_admin_styles' );

function acasa_avatar_admin_styles() {
    echo '<style>'
        . '.user-profile-picture td > .avatar { display: none !important; }'
        . '.user-profile-picture td > .description { display: none !important; }'
        . '#acasa-avatar-stage { display: none; }'
        . '</style>';
}

add_action( 'show_user_profile', 'acasa_admin_avatar_field' );
add_action( 'edit_user_profile', 'acasa_admin_avatar_field' );

function acasa_admin_avatar_field( WP_User $user ) {
    $is_donor = acasa_user_can_have_avatar( $user->ID );
    $can_manage = $is_donor && current_user_can( 'edit_user', $user->ID );
    $attachment_id = $is_donor ? acasa_get_donor_avatar_id( $user->ID ) : 0;
    $preview_url = acasa_get_avatar_url_from_attachment( $attachment_id, [ 'size' => 96 ] );
    $default_url   = get_stylesheet_directory_uri() . '/avatar-default.svg';

    if ( $can_manage ) {
        wp_nonce_field( 'acasa_avatar_delete', 'acasa_avatar_nonce' );
    }
    ?>
    <div id="acasa-avatar-stage">
        <div id="acasa-avatar-preview" style="margin-bottom:8px;">
            <img src="<?php echo esc_url( $preview_url ?: $default_url ); ?>" style="max-width:96px;height:auto;border-radius:50%;" />
        </div>
        <?php if ( $can_manage ) : ?>
            <input type="hidden" id="acasa_avatar_delete" name="acasa_avatar_delete" value="0" />
            <button
                type="button"
                id="acasa-avatar-remove"
                class="button"
                <?php echo $attachment_id <= 0 ? 'style="display:none;"' : ''; ?>
            >
                <?php esc_html_e( 'Șterge', 'acasa-child' ); ?>
            </button>
            <p class="description" style="margin-top:8px;">
                <?php esc_html_e( 'Schimbarea imaginii se face din Panoul Donatorului (GiveWP).', 'acasa-child' ); ?>
            </p>
        <?php endif; ?>
    </div>
    <script>
    jQuery(function($){
        // Move our controls into the existing profile picture cell.
        var $stage = $('#acasa-avatar-stage');
        var $cell = $('.user-profile-picture td');
        if ($cell.length && $stage.length) {
            $stage.prependTo($cell).show();
        }
        <?php if ( $can_manage ) : ?>
        var defaultUrl = <?php echo wp_json_encode( $default_url ); ?>;
        $('#acasa-avatar-remove').on('click', function(e){
            e.preventDefault();
            $('#acasa_avatar_delete').val('1');
            $('#acasa-avatar-preview').html('<img src="'+defaultUrl+'" style="max-width:96px;height:auto;border-radius:50%;" />');
            $(this).hide();
        });
        <?php endif; ?>
    });
    </script>
    <?php
}

/**
 * Save avatar delete action from WP admin profile form.
 */
add_action( 'personal_options_update',  'acasa_admin_avatar_save' );
add_action( 'edit_user_profile_update', 'acasa_admin_avatar_save' );

function acasa_admin_avatar_save( int $user_id ) {
    if (
        ! isset( $_POST['acasa_avatar_nonce'] ) ||
        ! wp_verify_nonce( $_POST['acasa_avatar_nonce'], 'acasa_avatar_delete' )
    ) {
        return;
    }
    if ( ! current_user_can( 'edit_user', $user_id ) ) {
        return;
    }
    if ( ! acasa_user_can_have_avatar( $user_id ) ) {
        return;
    }

    $should_delete = isset( $_POST['acasa_avatar_delete'] )
        && '1' === sanitize_text_field( wp_unslash( $_POST['acasa_avatar_delete'] ) );
    if ( ! $should_delete ) {
        return;
    }

    $donor = new Give_Donor( $user_id, true );
    if ( ! $donor || ! $donor->id ) {
        return;
    }

    $avatar_id = (int) Give()->donor_meta->get_meta( $donor->id, '_give_donor_avatar_id', true );
    if ( $avatar_id > 0 ) {
        $owner_id = (int) get_post_field( 'post_author', $avatar_id );
        if ( $owner_id === $user_id ) {
            wp_delete_attachment( $avatar_id, true );
        }
    }

    Give()->donor_meta->update_meta( $donor->id, '_give_donor_avatar_id', '' );
    delete_user_meta( $user_id, 'acasa_avatar_id' );
}

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
        // Priority: first+last → first → last → truncated email.
        // Full email is never used as display name (privacy).
        $display_name = acasa_build_display_name( $first_name, $last_name, $email );

        // Double fail-safe: wp_insert_user() alone does not trigger the
        // new-user email (that only happens via higher-level registration
        // flows). This filter is a defensive second layer — if a plugin or
        // future WP core change ever wires wp_insert_user() into the
        // notification path, the filter will silently suppress the email.
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
 * Returns true if the current user should see donor-only content.
 * Grants access to give_donor role holders AND site administrators.
 */
function acasa_current_user_is_donor(): bool {
    return is_user_logged_in()
        && ( current_user_can( 'give_donor' ) || current_user_can( 'manage_options' ) );
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

    // Dismiss: verify nonce and option before deleting.
    if (
        isset( $_GET['acasa_dismiss_give_notice'] ) &&
        get_option( 'acasa_give_update_warning' ) &&
        check_admin_referer( 'acasa_dismiss_give_notice' )
    ) {
        delete_option( 'acasa_give_update_warning' );
        return;
    }

    if ( ! get_option( 'acasa_give_update_warning' ) ) {
        return;
    }

    $dismiss_url = wp_nonce_url(
        add_query_arg( 'acasa_dismiss_give_notice', '1' ),
        'acasa_dismiss_give_notice'
    );
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
 * On any logout, fully revoke GiveWP email-access so the session cannot
 * be re-established from either the give_nl cookie or the original magic
 * link URL (?give_nl=TOKEN).
 *
 * Three things are cleared:
 *   1. give_nl cookie         — prevents cookie-based re-auth on next request.
 *   2. give_donors DB columns — clears verify_key and token so the URL token
 *                               is also invalid; GiveWP validates against these
 *                               columns, so wiping them makes the magic link
 *                               URL permanently dead after logout.
 *   3. In-memory token state  — prevents anything else in this request from
 *                               re-using the token.
 *
 * Two hooks cover all logout paths:
 *
 *   wp_logout              — fires for wp-admin logout and donor dashboard REST
 *                            logout (LogoutRoute). WordPress passes $user_id as
 *                            the action argument (since WP 5.9), which we use to
 *                            look up the donor email for the DB query.
 *
 *   give_after_user_logout — fires for GiveWP URL logout
 *                            (?give_action=user_logout), which strips all
 *                            wp_logout hooks before calling wp_logout(), so
 *                            wp_logout alone would miss this path. No user_id
 *                            is passed; we fall back to the email stored on the
 *                            Give email-access object (set at wp@14 from the
 *                            give_nl cookie that was present on the request).
 *
 * The function is idempotent: safe to fire twice on the same request (REST
 * logout fires both wp_logout and give_after_user_logout).
 */
add_action( 'wp_logout',              'acasa_clear_email_access_on_logout', 10, 1 );
add_action( 'give_after_user_logout', 'acasa_clear_email_access_on_logout', 10, 0 );

function acasa_clear_email_access_on_logout( $user_id = 0 ) {
    global $wpdb;

    // 1. Expire the give_nl cookie in the browser.
    if ( isset( $_COOKIE['give_nl'] ) ) {
        // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie
        @setcookie( 'give_nl', '', time() - YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, false );
        unset( $_COOKIE['give_nl'] );
    }

    // 2. Wipe the DB token so the original magic link URL is also dead.
    // Resolve donor email: prefer user_id (passed by wp_logout action),
    // fall back to the email-access object populated at wp@14.
    $email = '';
    if ( $user_id ) {
        $user = get_userdata( (int) $user_id );
        if ( $user ) {
            $email = $user->user_email;
        }
    }
    if ( empty( $email ) && Give()->email_access && ! empty( Give()->email_access->token_email ) ) {
        $email = Give()->email_access->token_email;
    }

    if ( ! empty( $email ) ) {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no user-supplied table name, $wpdb->donors is a registered property
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->donors} SET verify_key = '', token = '' WHERE email = %s LIMIT 1",
                $email
            )
        );
    }

    // 3. Reset in-memory state so nothing else in this request re-uses the token.
    if ( Give()->email_access ) {
        Give()->email_access->token_exists = false;
        Give()->email_access->token_email  = false;
        Give()->email_access->token        = false;
    }
}

/**
 * Sync GiveWP donor profile changes → WordPress user profile.
 *
 * GiveWP already syncs WP→GiveWP (admin-actions.php hooks on
 * edit_user_profile_update / profile_update). This closes the reverse
 * direction: donor dashboard edits → WP user first_name, last_name,
 * user_email.
 *
 * No-loop: GiveWP's own WP→GiveWP sync uses the legacy Give_DB_Donors
 * API which does NOT fire givewp_donor_updated, so this hook cannot
 * re-trigger itself.
 */
add_action( 'givewp_donor_updated', 'acasa_sync_donor_profile_to_wp', 10, 1 );

function acasa_sync_donor_profile_to_wp( $donor ) {
    if ( empty( $donor->userId ) ) {
        return;
    }

    $wp_user = get_userdata( (int) $donor->userId );
    if ( ! $wp_user ) {
        return;
    }

    $first = isset( $donor->firstName ) ? trim( (string) $donor->firstName ) : '';
    $last  = isset( $donor->lastName )  ? trim( (string) $donor->lastName )  : '';
    $email = isset( $donor->email )     ? trim( (string) $donor->email )     : '';

    // Collect only changed fields.
    $update = [ 'ID' => $wp_user->ID ];
    if ( $first !== get_user_meta( $wp_user->ID, 'first_name', true ) ) {
        $update['first_name'] = $first;
    }
    if ( $last !== get_user_meta( $wp_user->ID, 'last_name', true ) ) {
        $update['last_name'] = $last;
    }
    // Keep wp-admin "Display name publicly as" under WP user control.
    if ( $email !== '' && $email !== $wp_user->user_email ) {
        $update['user_email'] = $email;
    }

    // Nothing changed — skip wp_update_user() entirely.
    if ( count( $update ) === 1 ) {
        return;
    }

    // Suppress WP's "your email was changed" notification — the donor
    // already confirmed the change in GiveWP's dashboard UI.
    $email_changed = isset( $update['user_email'] );
    if ( $email_changed ) {
        add_filter( 'send_email_change_email', '__return_false' );
    }

    wp_update_user( $update );

    if ( $email_changed ) {
        remove_filter( 'send_email_change_email', '__return_false' );
    }
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
        wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
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
    // purchase_count > 0: GiveWP denormalised counter, incremented on confirm,
    // decremented on refund/cancel. May drift in some v3 recalculation paths,
    // but is a reliable enough proxy for "has at least one donation".
    global $wpdb;
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no user-supplied parameters, static query
    $donors = $wpdb->get_results( "
        SELECT id, email, name
        FROM {$wpdb->donors}
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
            // Ensure GiveWP donor record is linked to the WP user.
            Give()->donors->update( (int) $donor->id, [ 'user_id' => $existing->ID ] );
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
