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

/* =========================================================================
   Diagnostic logging
   ========================================================================= */

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
 * Priority: first_name -> "Cont".
 */
function acasa_default_account_label( WP_User $user ): string {
    $first = trim( (string) get_user_meta( (int) $user->ID, 'first_name', true ) );
    if ( $first !== '' ) {
        return $first;
    }

    return 'Cont';
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
    if ( ! class_exists( 'Give' ) ) {
        return false;
    }

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
    static $cache = [];
    if ( isset( $cache[ $user_id ] ) ) {
        return $cache[ $user_id ];
    }

    if ( $user_id <= 0 ) {
        return 0;
    }

    if ( ! class_exists( 'Give_Donor' ) || ! function_exists( 'Give' ) ) {
        return 0;
    }

    $donor = new Give_Donor( $user_id, true );
    if ( ! $donor || empty( $donor->id ) ) {
        $cache[ $user_id ] = 0;
        return 0;
    }

    $avatar_id = (int) Give()->donor_meta->get_meta( $donor->id, '_give_donor_avatar_id', true );
    if ( $avatar_id > 0 ) {
        $cache[ $user_id ] = $avatar_id;
        return $avatar_id;
    }

    $legacy_avatar_id = (int) get_user_meta( $user_id, 'acasa_avatar_id', true );
    if ( $legacy_avatar_id > 0 ) {
        Give()->donor_meta->update_meta( $donor->id, '_give_donor_avatar_id', $legacy_avatar_id );
        delete_user_meta( $user_id, 'acasa_avatar_id' );
        $cache[ $user_id ] = $legacy_avatar_id;
        return $legacy_avatar_id;
    }

    $cache[ $user_id ] = 0;
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
    $nonce = isset( $_POST['acasa_avatar_nonce'] )
        ? sanitize_text_field( wp_unslash( $_POST['acasa_avatar_nonce'] ) )
        : '';
    if ( $nonce === '' || ! wp_verify_nonce( $nonce, 'acasa_avatar_delete' ) ) {
        return;
    }
    if ( ! current_user_can( 'edit_user', $user_id ) ) {
        return;
    }
    if ( ! acasa_user_can_have_avatar( $user_id ) ) {
        return;
    }
    if ( ! class_exists( 'Give_Donor' ) || ! function_exists( 'Give' ) ) {
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

// Everything below requires GiveWP runtime and data tables.
if ( ! class_exists( 'Give' ) || ! class_exists( 'Give_Donor' ) || ! function_exists( 'Give' ) ) {
    return;
}

/**
 * Link GiveWP donor row to a WordPress user.
 *
 * Primary path: Give()->donors->update().
 * Fallback path: direct SQL update on {$wpdb->donors}.user_id.
 *
 * @param int         $donor_id GiveWP donor ID.
 * @param int         $user_id  WordPress user ID.
 * @param string|null $mode     Output mode: service|sql|no_change.
 * @return true|WP_Error
 */
function acasa_link_donor_to_user( int $donor_id, int $user_id, ?string &$mode = null ) {
    $mode = null;

    if ( $donor_id <= 0 || $user_id <= 0 ) {
        return new WP_Error( 'invalid_link_ids', 'Invalid donor_id or user_id for link operation.' );
    }

    try {
        $donors_service = Give()->donors;
        if ( is_object( $donors_service ) && method_exists( $donors_service, 'update' ) ) {
            $donors_service->update( $donor_id, [ 'user_id' => $user_id ] );
            $mode = 'service';
            return true;
        }
    } catch ( Throwable $e ) {
        // Fall through to SQL fallback.
    }

    global $wpdb;
    if ( ! isset( $wpdb->donors ) || ! is_string( $wpdb->donors ) || $wpdb->donors === '' ) {
        return new WP_Error( 'missing_donors_table', 'GiveWP donors table is unavailable for fallback link.' );
    }

    $updated = $wpdb->update(
        $wpdb->donors,
        [ 'user_id' => $user_id ],
        [ 'id' => $donor_id ],
        [ '%d' ],
        [ '%d' ]
    );

    if ( $updated === false ) {
        return new WP_Error(
            'donor_link_sql_failed',
            'Direct donor link update failed: ' . (string) $wpdb->last_error
        );
    }

    $mode = $updated === 0 ? 'no_change' : 'sql';
    return true;
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
        $link_mode = null;
        $link_result = acasa_link_donor_to_user( (int) $give_donor_id, (int) $user_id, $link_mode );
        if ( is_wp_error( $link_result ) ) {
            return $link_result;
        }
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
 * Restrict wp-admin access for non-admin donor accounts.
 * Admin users remain allowed even if they also have give_donor role.
 */
function acasa_is_restricted_donor_user( ?WP_User $user = null ): bool {
    $user = $user ?: wp_get_current_user();
    if ( ! ( $user instanceof WP_User ) || ! $user->exists() ) {
        return false;
    }

    if ( user_can( $user, 'manage_options' ) ) {
        return false;
    }

    return in_array( 'give_donor', (array) $user->roles, true );
}

/**
 * Resolve donor dashboard URL from GiveWP settings.
 */
function acasa_get_donor_dashboard_url(): string {
    if ( function_exists( 'give_get_option' ) ) {
        $page_id = (int) give_get_option( 'donor_dashboard_page' );
        if ( $page_id > 0 ) {
            $url = get_permalink( $page_id );
            if ( is_string( $url ) && $url !== '' ) {
                return $url;
            }
        }
    }

    return home_url( '/' );
}

add_action( 'admin_init', function (): void {
    if ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
        return;
    }

    if ( ! is_user_logged_in() ) {
        return;
    }

    if ( ! acasa_is_restricted_donor_user() ) {
        return;
    }

    wp_safe_redirect( acasa_get_donor_dashboard_url() );
    exit;
}, 1 );

add_filter( 'show_admin_bar', function ( bool $show ): bool {
    if ( ! is_user_logged_in() ) {
        return $show;
    }

    if ( acasa_is_restricted_donor_user() ) {
        return false;
    }

    return $show;
}, 20 );

/**
 * Exclude 'donatori' category from all queries for non-donors.
 */
add_action( 'pre_get_posts', 'acasa_exclude_donatori_from_queries' );

function acasa_exclude_donatori_from_queries( WP_Query $query ) {
    if ( is_admin() || ! $query->is_main_query() ) {
        return;
    }
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
        return;
    }

    if ( acasa_current_user_is_donor() ) {
        return;
    }

    static $term_id = null;
    if ( $term_id === null ) {
        $donatori = get_category_by_slug( 'donatori' );
        $term_id = $donatori ? (int) $donatori->term_id : 0;
    }
    if ( $term_id <= 0 ) {
        return;
    }

    $excluded = $query->get( 'category__not_in' );
    $excluded = array_values( array_filter( array_map( 'intval', (array) $excluded ) ) );
    $excluded[] = $term_id;
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
        @setcookie( 'give_nl', '', time() - YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
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

    if ( ! empty( $email ) && isset( $wpdb->donors ) ) {
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

/**
 * Capture donor profile edit intent from GiveWP REST request so empty
 * first/last names are not lost by GiveWP's strict profile updater.
 */
add_filter( 'rest_request_before_callbacks', 'acasa_capture_give_profile_name_intent', 10, 3 );

function acasa_capture_give_profile_name_intent( $response, $handler, WP_REST_Request $request ) {
    $route = (string) $request->get_route();
    if ( strpos( $route, '/give-api/v2/donor-dashboard/profile' ) === false ) {
        return $response;
    }

    $data = $request->get_param( 'data' );
    if ( ! is_array( $data ) ) {
        return $response;
    }

    if ( ! array_key_exists( 'firstName', $data ) && ! array_key_exists( 'lastName', $data ) ) {
        return $response;
    }

    $user_id = get_current_user_id();
    if ( $user_id <= 0 ) {
        return $response;
    }

    $first_name = array_key_exists( 'firstName', $data )
        ? trim( sanitize_text_field( (string) $data['firstName'] ) )
        : null;
    $last_name = array_key_exists( 'lastName', $data )
        ? trim( sanitize_text_field( (string) $data['lastName'] ) )
        : null;

    if ( ! isset( $GLOBALS['acasa_give_profile_name_intent'] ) || ! is_array( $GLOBALS['acasa_give_profile_name_intent'] ) ) {
        $GLOBALS['acasa_give_profile_name_intent'] = [];
    }

    $GLOBALS['acasa_give_profile_name_intent'][ $user_id ] = [
        'first_name' => $first_name,
        'last_name'  => $last_name,
    ];

    return $response;
}

/**
 * Consume and clear pending Give profile name intent for a WP user.
 *
 * @return array{first_name:?string,last_name:?string}|null
 */
function acasa_take_give_profile_name_intent( int $user_id ): ?array {
    if ( $user_id <= 0 ) {
        return null;
    }

    $all_intents = $GLOBALS['acasa_give_profile_name_intent'] ?? null;
    if ( ! is_array( $all_intents ) || ! array_key_exists( $user_id, $all_intents ) ) {
        return null;
    }

    $intent = $all_intents[ $user_id ];
    unset( $GLOBALS['acasa_give_profile_name_intent'][ $user_id ] );

    if ( ! is_array( $intent ) ) {
        return null;
    }

    return [
        'first_name' => array_key_exists( 'first_name', $intent ) ? $intent['first_name'] : null,
        'last_name'  => array_key_exists( 'last_name', $intent ) ? $intent['last_name'] : null,
    ];
}

/**
 * Persist first/last donor names through GiveWP donor meta, including empty
 * first-name values intentionally submitted from donor dashboard.
 */
function acasa_apply_name_intent_to_donor( int $donor_id, ?string $first_name, ?string $last_name ): void {
    if ( $donor_id <= 0 || ! function_exists( 'Give' ) || ! class_exists( 'Give_Donor' ) ) {
        return;
    }

    $current = new Give_Donor( $donor_id );
    if ( ! $current || empty( $current->id ) ) {
        return;
    }

    $resolved_first = $first_name;
    if ( $resolved_first === null ) {
        $resolved_first = trim( (string) $current->get_meta( '_give_donor_first_name', true ) );
    }

    $resolved_last = $last_name;
    if ( $resolved_last === null ) {
        $resolved_last = trim( (string) $current->get_meta( '_give_donor_last_name', true ) );
    }

    $composed_name = trim( $resolved_first . ' ' . $resolved_last );
    if ( $composed_name !== '' && isset( Give()->donors ) && is_object( Give()->donors ) ) {
        Give()->donors->update(
            $donor_id,
            [
                'name' => $composed_name,
            ]
        );
    }

    Give()->donor_meta->update_meta( $donor_id, '_give_donor_first_name', $resolved_first );
    Give()->donor_meta->update_meta( $donor_id, '_give_donor_last_name', $resolved_last );
}

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

    $intent = acasa_take_give_profile_name_intent( (int) $wp_user->ID );
    if ( is_array( $intent ) ) {
        if ( array_key_exists( 'first_name', $intent ) && is_string( $intent['first_name'] ) ) {
            $first = $intent['first_name'];
        }
        if ( array_key_exists( 'last_name', $intent ) && is_string( $intent['last_name'] ) ) {
            $last = $intent['last_name'];
        }

        if ( ! empty( $donor->id ) ) {
            acasa_apply_name_intent_to_donor( (int) $donor->id, $first, $last );
        }
    }

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
 * Register ACASA > Sync Donors admin page.
 */
add_action( 'admin_menu', 'acasa_register_sync_donors_admin_page', 40 );

function acasa_register_sync_donors_admin_page() {
    if ( defined( 'ACASA_ADMIN_MENU_SLUG' ) && ACASA_ADMIN_MENU_SLUG ) {
        add_submenu_page(
            ACASA_ADMIN_MENU_SLUG,
            'Sync Donor Accounts',
            'Sync Donors',
            'manage_options',
            'acasa-sync-donors',
            'acasa_render_sync_donors_page'
        );
        return;
    }

    // Fallback if ACASA admin menu slug is unavailable.
    add_management_page(
        'Sync Donor Accounts',
        'Sync Donor Accounts',
        'manage_options',
        'acasa-sync-donors',
        'acasa_render_sync_donors_page'
    );
}

function acasa_render_sync_donors_page() {
    ?>
    <div class="wrap">
        <h1>Sync Donor Accounts</h1>
        <p>Creates WP user accounts (role: <code>give_donor</code>) for all donors with at least one
        confirmed donation. Safe to run multiple times — existing accounts are skipped.</p>
        <h2>How to use</h2>
        <ol>
            <li>Open <code>ACASA &gt; Sync Donors</code>.</li>
            <li>Click <strong>Run Sync</strong>.</li>
            <li>Review summary and detailed reports for matched and not matched users.</li>
        </ol>

        <h2>What this tool does</h2>
        <ul>
            <li>Reads GiveWP donors from <code>give_donors</code> where <code>purchase_count &gt; 0</code> and email is not empty.</li>
            <li>Matches donor to WP user by email.</li>
            <li>If matched: ensures <code>give_donor</code> role and links donor <code>user_id</code>.</li>
            <li>If not matched: creates WP user with <code>give_donor</code> role and links it to donor record.</li>
            <li>Parses donor full name into first and last name for newly created users.</li>
        </ul>

        <h2>What this tool does not do</h2>
        <ul>
            <li>Does not modify donation records or totals.</li>
            <li>Does not reset passwords for existing users.</li>
            <li>Does not overwrite existing profile fields for matched users (except adding missing donor role).</li>
        </ul>

        <button id="acasa-sync-btn" class="button button-primary">Run Sync</button>
        <span id="acasa-sync-status" style="margin-left:12px;"></span>
        <div id="acasa-sync-results" style="margin-top:16px;"></div>
    </div>
    <script>
    document.getElementById('acasa-sync-btn').addEventListener('click', function() {
        var btn = this;
        btn.disabled = true;
        document.getElementById('acasa-sync-status').textContent = 'Running\u2026';
        document.getElementById('acasa-sync-results').innerHTML = '';

        fetch(ajaxurl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=acasa_sync_donors&_wpnonce=<?php echo esc_js( wp_create_nonce( 'acasa_sync_donors' ) ); ?>'
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            document.getElementById('acasa-sync-status').textContent = data.success ? 'Done.' : 'Error.';
            if (data && data.data && data.data.report_html) {
                document.getElementById('acasa-sync-results').innerHTML = data.data.report_html;
            } else if (data && data.data && data.data.message) {
                document.getElementById('acasa-sync-results').textContent = data.data.message;
            } else {
                document.getElementById('acasa-sync-results').textContent = 'No report data returned.';
            }
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
    $summary = sprintf(
        'Created: %d | Role added: %d | Skipped: %d | Errors: %d',
        (int) $results['created'],
        (int) $results['role_added'],
        (int) $results['skipped'],
        (int) $results['errors']
    );

    wp_send_json_success( [
        'message'     => $summary,
        'summary'     => $summary,
        'report_html' => acasa_render_sync_donors_report_html( $results ),
    ] );
}

/**
 * Core sync logic. Idempotent — safe to run multiple times.
 *
 * Queries the give_donors custom table (GiveWP v3+ stores donor data there,
 * not in wp_postmeta). Donors with purchase_count > 0 have at least one
 * confirmed donation.
 *
 * @return array{
 *   created:int,
 *   role_added:int,
 *   skipped:int,
 *   errors:int,
 *   total_donors:int,
 *   matched:array<int,array<string,mixed>>,
 *   not_matched:array<int,array<string,mixed>>,
 *   invalid_email:array<int,array<string,mixed>>,
 *   error_items:array<int,array<string,mixed>>
 * }
 */
function acasa_run_donor_sync(): array {
    $results = [
        'created'       => 0,
        'role_added'    => 0,
        'skipped'       => 0,
        'errors'        => 0,
        'total_donors'  => 0,
        'matched'       => [],
        'not_matched'   => [],
        'invalid_email' => [],
        'error_items'   => [],
    ];

    global $wpdb;
    if ( ! isset( $wpdb->donors ) || ! is_string( $wpdb->donors ) || $wpdb->donors === '' ) {
        $results['errors']++;
        $results['error_items'][] = [
            'scope'   => 'bootstrap',
            'message' => 'GiveWP donors table is unavailable.',
        ];
        return $results;
    }

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no user-supplied parameters, static query
    $donors = $wpdb->get_results( "
        SELECT id, email, name
        FROM {$wpdb->donors}
        WHERE purchase_count > 0
          AND email != ''
    " );
    $results['total_donors'] = is_array( $donors ) ? count( $donors ) : 0;

    if ( ! is_array( $donors ) ) {
        $results['errors']++;
        $results['error_items'][] = [
            'scope'   => 'query',
            'message' => 'Failed querying donor records.',
        ];
        return $results;
    }

    foreach ( $donors as $donor ) {
        $donor_id = isset( $donor->id ) ? (int) $donor->id : 0;
        $email    = isset( $donor->email ) ? trim( (string) $donor->email ) : '';
        $name     = isset( $donor->name ) ? trim( (string) $donor->name ) : '';

        if ( ! is_email( $email ) ) {
            $results['errors']++;
            $results['invalid_email'][] = [
                'donor_id' => $donor_id,
                'email'    => $email,
                'name'     => $name,
                'reason'   => 'Invalid email format',
            ];
            continue;
        }

        $existing = get_user_by( 'email', $email );
        if ( $existing ) {
            $had_role = in_array( 'give_donor', (array) $existing->roles, true );
            if ( $had_role ) {
                $results['skipped']++;
            } else {
                $existing->add_role( 'give_donor' );
                $results['role_added']++;
            }

            $link_status = 'linked';
            $link_mode = null;
            $link_result = acasa_link_donor_to_user( $donor_id, (int) $existing->ID, $link_mode );
            if ( is_wp_error( $link_result ) ) {
                $link_status = 'link_failed';
                $results['errors']++;
                $results['error_items'][] = [
                    'scope'    => 'donor_link',
                    'donor_id' => $donor_id,
                    'email'    => $email,
                    'message'  => $link_result->get_error_message(),
                ];
            } else {
                $link_status = (string) ( $link_mode ?: 'linked' );
            }

            $results['matched'][] = [
                'donor_id'    => $donor_id,
                'email'       => $email,
                'name'        => $name,
                'user_id'     => (int) $existing->ID,
                'role_action' => $had_role ? 'already_had_role' : 'role_added',
                'link_status' => $link_status,
            ];
            continue;
        }

        $full_name = $name;
        $space_pos = strpos( $full_name, ' ' );
        if ( $space_pos !== false ) {
            $first_name = substr( $full_name, 0, $space_pos );
            $last_name  = substr( $full_name, $space_pos + 1 );
        } else {
            $first_name = $full_name;
            $last_name  = '';
        }

        $result = acasa_ensure_donor_wp_user( $email, $first_name, $last_name, $donor_id );
        if ( is_wp_error( $result ) ) {
            $results['errors']++;
            $message = $result->get_error_message();
            $results['error_items'][] = [
                'scope'    => 'create_user',
                'donor_id' => $donor_id,
                'email'    => $email,
                'message'  => $message,
            ];
            $results['not_matched'][] = [
                'donor_id'   => $donor_id,
                'email'      => $email,
                'name'       => $name,
                'first_name' => $first_name,
                'last_name'  => $last_name,
                'action'     => 'create_failed',
                'user_id'    => 0,
                'error'      => $message,
            ];
        } else {
            $results['created']++;
            $results['not_matched'][] = [
                'donor_id'   => $donor_id,
                'email'      => $email,
                'name'       => $name,
                'first_name' => $first_name,
                'last_name'  => $last_name,
                'action'     => 'created',
                'user_id'    => (int) $result,
                'error'      => '',
            ];
        }
    }

    return $results;
}

/**
 * Render detailed sync report HTML for the admin page.
 *
 * @param array<string,mixed> $results
 */
function acasa_render_sync_donors_report_html( array $results ): string {
    $created      = (int) ( $results['created'] ?? 0 );
    $role_added   = (int) ( $results['role_added'] ?? 0 );
    $skipped      = (int) ( $results['skipped'] ?? 0 );
    $errors       = (int) ( $results['errors'] ?? 0 );
    $total_donors = (int) ( $results['total_donors'] ?? 0 );
    $matched      = isset( $results['matched'] ) && is_array( $results['matched'] ) ? $results['matched'] : [];
    $not_matched  = isset( $results['not_matched'] ) && is_array( $results['not_matched'] ) ? $results['not_matched'] : [];
    $invalid      = isset( $results['invalid_email'] ) && is_array( $results['invalid_email'] ) ? $results['invalid_email'] : [];
    $error_items  = isset( $results['error_items'] ) && is_array( $results['error_items'] ) ? $results['error_items'] : [];

    ob_start();
    ?>
    <div style="margin-top:16px;">
        <h2>Summary</h2>
        <table class="widefat striped" style="max-width:900px;">
            <tbody>
                <tr><th>Total eligible GiveWP donors</th><td><?php echo esc_html( (string) $total_donors ); ?></td></tr>
                <tr><th>Matched to existing WP users</th><td><?php echo esc_html( (string) count( $matched ) ); ?></td></tr>
                <tr><th>Not matched (create path)</th><td><?php echo esc_html( (string) count( $not_matched ) ); ?></td></tr>
                <tr><th>Created new users</th><td><?php echo esc_html( (string) $created ); ?></td></tr>
                <tr><th>Role added on existing users</th><td><?php echo esc_html( (string) $role_added ); ?></td></tr>
                <tr><th>Skipped (already correct)</th><td><?php echo esc_html( (string) $skipped ); ?></td></tr>
                <tr><th>Invalid donor emails</th><td><?php echo esc_html( (string) count( $invalid ) ); ?></td></tr>
                <tr><th>Errors</th><td><?php echo esc_html( (string) $errors ); ?></td></tr>
            </tbody>
        </table>

        <h2 style="margin-top:20px;">Detailed report: matched users</h2>
        <?php if ( empty( $matched ) ) : ?>
            <p>No matched users found.</p>
        <?php else : ?>
            <table class="widefat striped" style="max-width:1200px;">
                <thead>
                    <tr>
                        <th>Donor ID</th>
                        <th>Email</th>
                        <th>Name</th>
                        <th>WP User ID</th>
                        <th>Role action</th>
                        <th>Link status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $matched as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( (string) ( $row['donor_id'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['email'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['name'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['user_id'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['role_action'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['link_status'] ?? '' ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2 style="margin-top:20px;">Detailed report: not matched users</h2>
        <?php if ( empty( $not_matched ) ) : ?>
            <p>No unmatched users found.</p>
        <?php else : ?>
            <table class="widefat striped" style="max-width:1200px;">
                <thead>
                    <tr>
                        <th>Donor ID</th>
                        <th>Email</th>
                        <th>Name</th>
                        <th>Parsed first name</th>
                        <th>Parsed last name</th>
                        <th>Action</th>
                        <th>WP User ID</th>
                        <th>Error</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $not_matched as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( (string) ( $row['donor_id'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['email'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['name'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['first_name'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['last_name'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['action'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['user_id'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['error'] ?? '' ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2 style="margin-top:20px;">Invalid donor emails</h2>
        <?php if ( empty( $invalid ) ) : ?>
            <p>No invalid emails found.</p>
        <?php else : ?>
            <table class="widefat striped" style="max-width:1200px;">
                <thead>
                    <tr>
                        <th>Donor ID</th>
                        <th>Email</th>
                        <th>Name</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $invalid as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( (string) ( $row['donor_id'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['email'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['name'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['reason'] ?? '' ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ( ! empty( $error_items ) ) : ?>
            <h2 style="margin-top:20px;">Technical errors</h2>
            <table class="widefat striped" style="max-width:1200px;">
                <thead>
                    <tr>
                        <th>Scope</th>
                        <th>Donor ID</th>
                        <th>Email</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $error_items as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( (string) ( $row['scope'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['donor_id'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['email'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['message'] ?? '' ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php

    return (string) ob_get_clean();
}
