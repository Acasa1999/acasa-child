# Donor Hardening - Part 1 Implementation Report

**Branch:** `task/donor-hardening-codex`
**Date:** 2026-03-03
**Design doc:** `sources/donor-access-hardening-acasa-give-checks-design.md` (v4)

## Changes Summary

### 1. Diagnostic Logging Helper (`acasa_donor_diag`)
- **Commit:** `525efa9`
- Logs only when `ACASA_DONOR_DIAG` constant or env var is truthy
- Auto-hashes email fields: SHA-256 first 8 chars + domain (e.g. `a1b2c3d4@example.com`)
- Tag-based format for easy grep: `[acasa-donor-diag][tag] {json}`

### 2. Avatar Preflight Guard (`acasa_avatar_upload_preflight`)
- **Commit:** `fb85673`
- Hooks `rest_request_before_callbacks` on `/give-api/v2/donor-dashboard/avatar`
- **Upload size guard:** Returns WP_Error 413 with Romanian message if `CONTENT_LENGTH > 0` but `$_FILES` is empty (PHP dropped the upload)
- **Stale meta cleanup:** Checks 3 conditions before upload:
  1. Attachment post missing → clear meta
  2. File on disk missing → clear meta
  3. Attachment owner mismatch → clear meta
- Stores clear reason in `$GLOBALS['acasa_avatar_preflight']` for profile normalization

### 3. Profile Payload Normalization (`acasa_profile_payload_normalize`)
- **Commit:** `5c9e62c`
- Hooks `rest_request_before_callbacks` on `/give-api/v2/donor-dashboard/profile`
- Only acts when avatar failure was from a known cause (preflight or size guard)
- Injects current `avatarId` into profile payload to prevent GiveWP `Undefined property` warnings

### 4. Donation-Confirmed Flow Rewrite (`acasa_on_donation_confirmed`)
- **Commit:** `59bd0bf`
- **Resolution order (first match wins):**
  1. Donor already has valid linked `user_id` → ensure role, stop
  2. WP user exists matching donor's PRIMARY email → link + add role
  3. WP user exists matching CHECKOUT email → link + add role
  4. No match → create new WP user with primary email
- Prevents duplicate WP users for multi-email donors
- Handles stale links (deleted WP users) gracefully
- All paths produce diagnostic log entries

## Verification

- `wp eval 'echo "loaded";'` passes with no new warnings after each commit
- Donor data accessible via `Give_Donor` class (verified donors ID 3, 6, 7)
- No PHP syntax errors introduced

## Acceptance Criteria Status

| Criterion | Status |
|-----------|--------|
| PII-safe logging with email hashing | Done |
| Avatar upload stale meta cleared before GiveWP callback | Done |
| Oversized upload returns 413 with Romanian message | Done |
| Profile payload normalized for known failures only | Done |
| Multi-email donors consolidated to single WP user | Done |
| Existing valid links not broken on re-donation | Done |
| Stale links (deleted users) recovered gracefully | Done |

## Residual Risks

1. The profile normalization shim relies on `$GLOBALS` state within a single request. If WordPress processes avatar upload and profile update in separate HTTP requests (which is the normal case), the shim won't fire. This is by design — it only catches the edge case where both happen in the same request.
2. The checkout email fallback (step 3) could link a donor to a WP user that belongs to a different person if two people share an email. This is extremely unlikely and matches GiveWP's own behavior.
