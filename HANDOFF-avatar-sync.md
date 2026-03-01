# Avatar Sync Handoff (GiveWP Canonical)

## Read First
This handoff documents the current implementation state for donor avatar/profile sync and what to do next.

## Branch + Commit
- Branch: `fix/givewp-avatar-sync`
- Commit: `74b9d10`
- Repo: `W:\www\acasa\app\public\wp-content\themes\acasa-child`

## Goal
Keep GiveWP donor avatar as the **single canonical source** (`_give_donor_avatar_id`), while:
- showing avatar in WP profile + header,
- keeping WP profile as **read/delete only** (no upload/change there),
- keeping avatar upload/change in GiveWP Donor Dashboard only.

## What Was Implemented
1. Canonical avatar source switched to GiveWP donor meta (`_give_donor_avatar_id`).
2. Legacy `acasa_avatar_id` usermeta is migrated one-time to GiveWP donor meta.
3. WP profile custom avatar UI is read/delete only.
4. WP profile upload flow (`wp.media`) was removed.
5. Delete flow clears donor meta and deletes attachment only when post author matches user.
6. `display_name` is no longer overwritten by donor sync hook (WP "Display name publicly as" stays user-controlled).
7. Header account label uses `display_name` (with safe fallback).

## Files Changed
- `inc/donor-access.php`
- `functions.php`

## Key Anchors
- `inc/donor-access.php`: functions
  - `acasa_get_donor_avatar_id`
  - `acasa_get_avatar_url_from_attachment`
  - `acasa_local_avatar_data`
  - `acasa_admin_avatar_field`
  - `acasa_admin_avatar_save`
  - `acasa_sync_donor_profile_to_wp`
- `functions.php`: `acasa_account_display_payload`

## Important Constraints
- Do **not** reintroduce WP-profile upload/change for donor avatars.
- Keep using GiveWP Donor Dashboard avatar pipeline (`give-api/v2/donor-dashboard/avatar` + `profile`).
- Do not grant extra permissions just to enable WP-profile uploads.

## Current Working Tree Notes
Untracked files currently exist and were intentionally not committed:
- `avatar-default.svg`
- `wp-cli.phar`

Handle these explicitly if needed; do not assume they are part of commit `74b9d10`.

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

## If Additional Work Is Needed
- Focus on integration/edge cases, not new avatar storage models.
- Keep GiveWP ownership checks and security behavior intact.
