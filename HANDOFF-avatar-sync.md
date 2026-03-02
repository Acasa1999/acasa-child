# Avatar Sync Handoff (GiveWP Canonical)

## Read First
This handoff documents the current implementation state for donor avatar/profile sync and what to do next.

## Branch + Current Head
- Branch: `fix/givewp-avatar-sync`
- Head commit: `03f0134`
- Repo: `W:\www\acasa\app\public\wp-content\themes\acasa-child`

## Working Tree
- Status: clean (no uncommitted changes).

## Goal
Keep GiveWP donor avatar as the **single canonical source** (`_give_donor_avatar_id`), while:
- showing avatar in WP profile + header,
- keeping WP profile as **read/delete only** (no upload/change there),
- keeping avatar upload/change in GiveWP Donor Dashboard only.

## Priority Alert (2026-03-02)
P0 for next release:
- Fix donor-to-WP relinking side effect when a logged-in donor donates with an alternate email.
- Current risk: `acasa_on_donation_confirmed()` -> `acasa_ensure_donor_wp_user()` can reassign `give_donors.user_id` based on payment email, even when donor is already linked.
- Required direction:
  1. Preserve stable donor ownership link once set.
  2. Do not auto-relink linked donors by alternate checkout email.
  3. Only create/link WP user automatically when donor has no linked `user_id`.
  4. Log/report conflict when checkout email points to a different WP user than donor-linked user.

## Commit Timeline (Latest)
1. `03f0134` feat: improve donor sync admin reporting and robust donor linking
2. `8039787` feat: mega-panel toggle, donor name fixes, perf caches, security hardening
3. `190e32f` chore: align account menu comment with local avatar behavior
4. `673ed50` perf(nav): cache generated primary nav markup and conditionally load header script
5. `3bb5143` hardening: scope donor query filter and tighten admin/nonce handling
6. `6c5c3ef` fix(avatar): keep local avatar fallback active when GiveWP is unavailable
7. `0b15017` chore(repo): track avatar default asset and ignore phar artifacts
8. `6e98153` feat(header): first-name default label with manual display-name override tracking
9. `283ffb2` docs: add avatar sync handoff instructions
10. `74b9d10` fix(avatar): keep GiveWP as canonical avatar source; WP profile read/delete only

## Current Behavior
1. Canonical avatar source is GiveWP donor meta (`_give_donor_avatar_id`).
2. Legacy `acasa_avatar_id` usermeta is migrated once on read, then cleared.
3. WP profile avatar controls are read/delete only (no upload/change).
4. Delete flow clears donor meta and deletes attachment only if the user owns it.
5. Avatar rendering uses local pipeline via `pre_get_avatar_data` with `avatar-default.svg` fallback.
6. Local avatar fallback remains active even when GiveWP runtime is unavailable.
7. Name edits in GiveWP Donor Dashboard sync to WP user profile fields.
8. First-name deletion in GiveWP Donor Dashboard is preserved by child-theme request-intent capture.
9. Header label defaults to first name unless user manually changed "Display name publicly as".
10. If first name is empty and no manual override exists, header label is `Cont`.
11. Long account labels are truncated in header UI to avoid menu breakage.
12. Sync Donors admin tool now provides detailed tabular reports and robust donor link fallback when Give donor service is unavailable.

## Files Changed
- `inc/donor-access.php`
- `functions.php`
- `header-v1.css`
- `.gitignore`
- `avatar-default.svg`

## Admin Tool Behavior (Sync Donors)
- Menu placement:
  - Primary: `ACASA -> Sync Donors`
  - Fallback: `Tools -> Sync Donor Accounts` (if ACASA menu slug missing)
- Report sections:
  - Summary
  - Matched users
  - Not matched users
  - Invalid donor emails
  - Technical errors
- Donor linking path:
  - Primary: `Give()->donors->update(...)`
  - Fallback: direct SQL update on `{$wpdb->donors}.user_id`

## Key Anchors
- `inc/donor-access.php`: functions
  - `acasa_default_account_label`
  - `acasa_track_display_name_override`
  - `acasa_capture_give_profile_name_intent`
  - `acasa_take_give_profile_name_intent`
  - `acasa_apply_name_intent_to_donor`
  - `acasa_get_donor_avatar_id`
  - `acasa_get_avatar_url_from_attachment`
  - `acasa_local_avatar_data`
  - `acasa_admin_avatar_field`
  - `acasa_admin_avatar_save`
  - `acasa_sync_donor_profile_to_wp`
- `functions.php`
  - `acasa_account_display_payload`
  - `acasa_primary_navigation_cache_version`
  - `acasa_primary_navigation_bump_cache_version`
- `header-v1.css`
  - account-label truncation rules

## Important Constraints
- Do **not** reintroduce WP-profile upload/change for donor avatars.
- Keep using GiveWP Donor Dashboard avatar pipeline (`give-api/v2/donor-dashboard/avatar` + `profile`).
- Do not grant extra permissions just to enable WP-profile uploads.
- Keep header label override semantics:
  - default first-name label
  - explicit user display-name edits take precedence
- Keep `Cont` fallback when first name is empty and no manual override exists.
- For donor first-name deletion, Donor Dashboard is the supported donor-facing flow.

## Related Docs
- `W:\www\acasa\app\public\sources\donor-access-design.md`
- `W:\www\acasa\app\public\sources\donor-acces-implementation-plan.md`

## Validation Checklist
1. Donor uploads avatar in GiveWP Donor Dashboard.
2. Avatar persists and appears:
   - in Donor Dashboard profile,
   - in site header account UI,
   - in WP profile avatar section.
3. WP profile does not offer upload/change action (only preview + delete action).
4. WP profile delete clears avatar and fallback appears in header/profile.
5. Donor can upload a new avatar again from GiveWP dashboard after delete.
6. No "not allowed" errors in intended flows.
7. Donor Dashboard first-name deletion persists after save/reload.
8. Header shows `Cont` when first name is empty and no manual display-name override exists.
9. Header shows first name by default, unless "Display name publicly as" was manually changed.
10. Very long names do not break header layout.
11. Nav cache invalidates correctly when menu/layout changes.

## If Additional Work Is Needed
- Focus on integration/edge cases, not new avatar storage models.
- Keep GiveWP ownership checks and security behavior intact.
