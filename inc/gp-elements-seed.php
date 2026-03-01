<?php
/**
 * ACASA GeneratePress Elements seed stub.
 *
 * Non-destructive scaffold:
 * - admin-only execution
 * - deterministic signature strategy
 * - ensure_* functions only report intended actions
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ACASA_GP_ELEMENTS_SEED_SIGNATURE_KEY')) {
    define('ACASA_GP_ELEMENTS_SEED_SIGNATURE_KEY', '_acasa_seed_signature');
}

if (!defined('ACASA_GP_ELEMENTS_SEED_SIGNATURE_NS')) {
    define('ACASA_GP_ELEMENTS_SEED_SIGNATURE_NS', 'acasa-gp-elements-v1');
}

/**
 * Limit seed operations to privileged admin requests.
 */
function acasa_gp_elements_seed_is_admin_context(): bool {
    return is_admin() && current_user_can('manage_options');
}

/**
 * Build a deterministic signature value for a model id.
 */
function acasa_gp_elements_seed_signature(string $model_id): string {
    $model_id = sanitize_key($model_id);
    return ACASA_GP_ELEMENTS_SEED_SIGNATURE_NS . ':' . $model_id;
}

/**
 * Compute a deterministic slug for a model id.
 */
function acasa_gp_elements_seed_slug(string $model_id): string {
    $model_id = sanitize_title($model_id);
    return 'acasa-' . $model_id;
}

/**
 * Report intended operations without mutating DB.
 */
function acasa_gp_elements_seed_log_intent(array $intent): void {
    if (!acasa_gp_elements_seed_is_admin_context()) {
        return;
    }

    $json = wp_json_encode($intent);
    if (!is_string($json)) {
        $json = '{}';
    }

    error_log('[acasa-gp-elements-seed] ' . $json);
}

/**
 * Build a read-only plan for a GP Element model.
 *
 * @param array<string,mixed> $model
 * @return array<string,mixed>
 */
function acasa_gp_elements_ensure_model(array $model): array {
    $model_id = isset($model['id']) ? sanitize_key((string) $model['id']) : '';
    if ($model_id === '') {
        return [
            'ok' => false,
            'reason' => 'missing_model_id',
            'action' => 'noop',
        ];
    }

    $slug = isset($model['slug'])
        ? sanitize_title((string) $model['slug'])
        : acasa_gp_elements_seed_slug($model_id);

    $signature_key = ACASA_GP_ELEMENTS_SEED_SIGNATURE_KEY;
    $signature = acasa_gp_elements_seed_signature($model_id);

    if (isset($model['signature']) && is_array($model['signature'])) {
        $meta_key = $model['signature']['meta_key'] ?? '';
        $value = $model['signature']['value'] ?? '';

        $meta_key = is_string($meta_key) ? sanitize_key($meta_key) : '';
        $value = is_string($value) ? (string) $value : '';

        if ($value !== '') {
            $signature = $value;
            if ($meta_key !== '') {
                $signature_key = $meta_key;
            }
        }
    } elseif (isset($model['signature']) && is_string($model['signature']) && $model['signature'] !== '') {
        $signature = (string) $model['signature'];
    }

    $existing = get_page_by_path($slug, OBJECT, 'gp_elements');

    $intent = [
        'ok' => true,
        'model_id' => $model_id,
        'post_type' => 'gp_elements',
        'slug' => $slug,
        'signature_key' => $signature_key,
        'signature' => $signature,
        'action' => $existing instanceof WP_Post ? 'update' : 'create',
        'existing_id' => $existing instanceof WP_Post ? (int) $existing->ID : 0,
        'mutations' => [
            'post' => $existing instanceof WP_Post ? 'would_update' : 'would_insert',
            'meta' => 'would_upsert_signature',
            'rules' => 'would_sync_display_rules',
        ],
        'write_enabled' => false,
    ];

    acasa_gp_elements_seed_log_intent($intent);

    return $intent;
}

/**
 * Read-only ensure wrapper for layout elements.
 *
 * @param array<string,mixed> $model
 * @return array<string,mixed>
 */
function acasa_gp_elements_ensure_layout(array $model): array {
    $model['type'] = 'layout';
    return acasa_gp_elements_ensure_model($model);
}

/**
 * Read-only ensure wrapper for hook elements.
 *
 * @param array<string,mixed> $model
 * @return array<string,mixed>
 */
function acasa_gp_elements_ensure_hook(array $model): array {
    $model['type'] = 'hook';
    return acasa_gp_elements_ensure_model($model);
}

/**
 * Read-only ensure wrapper for block elements.
 *
 * @param array<string,mixed> $model
 * @return array<string,mixed>
 */
function acasa_gp_elements_ensure_block(array $model): array {
    $model['type'] = 'block';
    return acasa_gp_elements_ensure_model($model);
}

/**
 * Optional admin init entrypoint for manual test runs.
 * No-op unless explicitly enabled via filter.
 */
add_action('admin_init', function (): void {
    if (!acasa_gp_elements_seed_is_admin_context()) {
        return;
    }

    $enabled = (bool) apply_filters('acasa_gp_elements_seed_stub_enable', false);
    if (!$enabled) {
        return;
    }

    $plan = acasa_gp_elements_ensure_layout([
        'id' => 'layout-default',
        'slug' => 'acasa-layout-default',
        'title' => 'ACASA Layout Default',
    ]);

    acasa_gp_elements_seed_log_intent([
        'event' => 'stub_bootstrap',
        'plan' => $plan,
    ]);
});
