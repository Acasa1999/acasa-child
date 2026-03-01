# Layout Models (Scaffold)

Last updated: 2026-02-27
Scope: model contract for upcoming GeneratePress Elements integration.

## Intent

Define a stable, non-destructive model that maps declarative layout rules to `gp_elements` entries.

This file is planning-only. No DB writes are performed by this scaffold.

## Proposed model shape

```php
[
  'id' => 'site-header-default',
  'type' => 'header', // header | hook | layout | block
  'slug' => 'acasa-site-header-default',
  'title' => 'ACASA Site Header Default',
  'status' => 'publish',
  'signature' => [
    'meta_key' => '_acasa_seed_signature',
    'value' => 'acasa-gp-elements-v1:site-header-default',
  ],
  'payload' => [
    // Element type specific config/meta/content
  ],
  'rules' => [
    // display/location/exclusions/users
  ],
]
```

## Signature strategy

Use both:
- deterministic slug (for human traceability)
- deterministic signature meta key/value (for machine idempotency)

Suggested defaults:
- meta key: `_acasa_seed_signature`
- signature namespace: `acasa-gp-elements-v1`

## Ensure workflow (future write-enabled phase)

For each model entry:
1. Resolve by slug and/or signature.
2. If missing: plan `create`.
3. If exists and differs: plan `update`.
4. If exists and matches: plan `noop`.

Current phase behavior:
- read + plan + log only.
- no `wp_insert_post`, no `wp_update_post`, no `update_post_meta`.

## Suggested first model set

- global header element
- global footer hook element
- page hero layout element
- archive layout element
- single post layout element

## Validation checklist for next phase

- Confirm `gp_elements` post type is registered.
- Confirm Elements module is active in GP Premium settings.
- Confirm display rule schema for installed GP/GB versions.
- Confirm multilingual interactions (WPML) before enforcing global rules.
