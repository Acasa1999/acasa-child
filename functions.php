<?php
/**
 * ACASA Child theme functions
 */

add_action('wp_enqueue_scripts', function () {
    // Always load the child theme stylesheet.
    wp_enqueue_style(
        'acasa-child-style',
        get_stylesheet_uri(),
        [],
        wp_get_theme()->get('Version')
    );
}, 20);