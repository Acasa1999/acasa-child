<?php
/**
 * Debug avatar file path for echipa@ donor.
 * Run: wp eval-file .../tests/debug-avatar-path.php
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$donor = new Give_Donor( 'echipa@asociatiaacasa.ro' );
if ( ! $donor || ! $donor->id ) {
    echo "Donor not found\n";
    return;
}

echo "Donor ID: " . $donor->id . "\n";
echo "Email: " . $donor->email . "\n";
echo "User ID: " . $donor->user_id . "\n\n";

$avatar_id = (int) Give()->donor_meta->get_meta( $donor->id, '_give_donor_avatar_id', true );
echo "Avatar attachment ID: " . $avatar_id . "\n";

if ( $avatar_id <= 0 ) {
    echo "No avatar set.\n";
    return;
}

$post = get_post( $avatar_id );
echo "Attachment post exists: " . ( $post ? 'yes (type=' . $post->post_type . ')' : 'NO' ) . "\n";
echo "Post author: " . ( $post ? $post->post_author : 'n/a' ) . "\n\n";

$file = get_attached_file( $avatar_id );
echo "get_attached_file(): " . ( $file ?: '(empty)' ) . "\n";
echo "file_exists() on that: " . ( ( $file && file_exists( $file ) ) ? 'YES' : 'NO' ) . "\n\n";

// Check raw meta
$raw = get_post_meta( $avatar_id, '_wp_attached_file', true );
echo "_wp_attached_file raw: " . ( $raw ?: '(empty)' ) . "\n";

// Resolve manually
$upload_dir = wp_upload_dir();
echo "Upload basedir: " . $upload_dir['basedir'] . "\n";

if ( $raw ) {
    $full = $upload_dir['basedir'] . '/' . $raw;
    echo "Manual full path: " . $full . "\n";
    echo "Manual file_exists(): " . ( file_exists( $full ) ? 'YES' : 'NO' ) . "\n";
}

// Check URL (how the browser sees it)
$url = wp_get_attachment_url( $avatar_id );
echo "\nwp_get_attachment_url(): " . ( $url ?: '(empty)' ) . "\n";

// Check attachment metadata
$meta = wp_get_attachment_metadata( $avatar_id );
if ( $meta && isset( $meta['file'] ) ) {
    echo "Attachment metadata[file]: " . $meta['file'] . "\n";
} else {
    echo "Attachment metadata: " . ( $meta ? 'exists but no file key' : 'empty/false' ) . "\n";
}

echo "\nDone.\n";
