# Donor Questions FAQ (Full List)

This file keeps the complete Q&A set for donor profile/avatar issues.

The website page should display only the user-facing `A` answers.
The `A2` notes are for support/developers.

1. **Who can upload a profile picture (avatar)?**
   - A: Only donor accounts can upload or change an avatar, and only in the Donor Dashboard. If you are not a donor, your account uses the standard default avatar.
   - A2: ACASA allows custom avatars only for users with `give_donor` role; non-donor users are forced to the local default avatar.

2. **What image formats are accepted for avatar upload?**
   - A: For avatar upload, use JPG, PNG, or GIF.
   - A2: GiveWP donor dashboard dropzone accepts `image/jpeg`, `image/png`, `image/gif`.

3. **Is there a size limit for avatar images?**
   - A: Yes. Large files can fail during upload. Best results: square image, ideally under 2 MB.
   - A2: Current local limits are `upload_max_filesize=2M`, `post_max_size=8M`.

4. **Why does avatar upload sometimes fail without a visible error?**
   - A: In some edge cases, GiveWP does not display a clear error message. This behavior comes from the GiveWP plugin flow, not from ACASA page content.
   - A2: Some GiveWP API error paths do not surface user-friendly frontend errors; this is plugin-core behavior.

5. **What should I do if avatar upload failed or did not stick?**
   - A: Use this sequence: 1) Refresh the page. 2) Log in again. 3) Upload a smaller JPG/PNG. 4) Click Update Profile once and wait. 5) Refresh to confirm.
   - A2: GiveWP avatar update is a 2-step flow (upload returns `avatarId`, then profile update saves it). If upload response lacks a valid ID, avatar does not persist.

6. **Why can it work on the 2nd or 3rd attempt?**
   - A: The first attempt may hit a temporary session or upload state issue. Refresh and re-login usually fix it.
   - A2: Typical causes include nonce/token/session mismatch or incomplete upload response.

7. **Why should I update first and last name together?**
   - A: For best reliability, edit both first and last name in the same save action, even if only one changed.
   - A2: GiveWP donor profile/name pipeline can behave inconsistently on partial name updates; full-name save avoids common edge cases.

8. **I changed only one name field and it reverted. What now?**
   - A: Reopen profile, fill both name fields exactly as desired, click Update Profile, then refresh to confirm.
   - A2: Partial name edits may not be fully reflected depending on profile update path/state.

9. **Can two donors use the exact same avatar file?**
   - A: Yes. Same image content is normally allowed and should not block upload by itself.
   - A2: WordPress usually creates separate attachment records per upload; content duplication itself is not the blocker.

10. **What should I send support if updates still fail?**
    - A: Send account email, exact time of attempt, what changed (avatar/name/email), and screenshots before/after pressing Update Profile.
    - A2: Needed to correlate donor/session/API events and logs (`donorId`, avatar/profile route behavior, timestamps).

11. **Can ACASA directly patch this silent-fail behavior?**
    - A: We can provide workarounds and support, but this specific silent-fail behavior is in GiveWP internals. For stability, ACASA does not hot-patch GiveWP core files in production.
    - A2: Root issue is in GiveWP donor-dashboard request/response/error-handling paths; local theme code is not the primary source of this silent-error pattern.
