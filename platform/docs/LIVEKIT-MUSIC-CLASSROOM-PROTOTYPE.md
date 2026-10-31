# Delnavazan LiveKit music classroom

LiveKit is the primary one-to-one classroom for Delnavazan term lessons. The existing Google
Calendar/Meet integration remains the explicit backup. The classroom is an additive protected route
at `/music-classroom/`; it does not replace canonical lesson, attendance, finance, or provider
authority.

## Architecture

- **Platform REST session endpoint** — `POST /wp-json/delnavazan-platform/v1/livekit/session`.
  Requires a signed-in WordPress user, the WordPress REST nonce, a signed launch ticket, and passes
  a per-user token budget.
- **Launch boundary** — role-specific `admin-post.php` actions set by the portal:
  `dzn_teacher_livekit_class` and `dzn_student_livekit_class`. The action has its own nonce, re-proves
  the canonical lesson, and redirects to the classroom with an opaque signed ticket in the URL
  fragment. The page never receives lesson/schedule identifiers or credentials.
- **Canonical authorities reused** — canonical term Lessons are re-proved through
  `PortalAccessPolicy` (which composes the canonical Lesson, schedule, assignment, delivery, and
  attendance validators). Confirmed introductory bookings use the existing
  `BookingTeacherDeliveryRepository`. There is no second lesson or booking authority.
- **LiveKit control plane** — the server creates or verifies the room with
  `max_participants = 2` through the LiveKit RoomService Twirp API. Participant tokens are
  room-scoped, short-lived, and cannot create, administer, or record rooms.
- **Webhook** — signed intake at `POST /wp-json/delnavazan-platform/v1/livekit/webhook`. It rejects
  a third identity and closes a room whose canonical authorisation has been withdrawn. It is
  defence in depth, not an attendance authority.
- **Portal presentation** — the shared component
  `theme/template-parts/components/classroom-actions.php` renders the primary
  **ورود به کلاس** action and the secondary **ورود با گوگل میت** action from server-issued URLs,
  nonces, and booleans.

## Safe credential configuration

Credentials are read only from the PHP process configuration, never from WordPress options,
JavaScript, the repository, a web-served `.env`, browser logs, or chat. Constants in the protected
WordPress configuration take precedence over environment variables.

```text
DZN_LIVEKIT_URL=wss://rtc.delnavazan.com
DZN_LIVEKIT_API_KEY=…
DZN_LIVEKIT_API_SECRET=…
# Optional; derived as https://<wss host> when absent.
DZN_LIVEKIT_API_URL=https://rtc.delnavazan.com
```

`LiveKitConfiguration` rejects a non-`wss` URL, embedded credentials, query/fragment components,
and a non-HTTPS API URL. A disposable `local`/`development` runtime may use `ws://` and `http://`
only for a loopback host; production always requires `wss://` and `https://`. The API secret is only used to sign short-lived participant tokens, 60-second
RoomService tokens with exactly the grant each control-plane call needs (`roomList`, `roomCreate`,
or `roomAdmin`), and webhook verification.

## Production configuration (deployed)

- Deployed build: `main-cee1d2f23157` from `cee1d2f23157e958220c9af6234a1ac877a478d9`.
- Platform schema: 57.
- Production credentials are stored outside the web root:
  `/home/delnavaz/domains/delnavazan.com/private/livekit-config.php`
  (mode `0600`, owner `delnavaz:delnavaz`).
- `wp-config.php` loads that file only if it is readable. The WordPress web process and WP-CLI both
  read the same protected file.
- No LiveKit credential is present in Git, the database, JavaScript, logs, or this document.
- The existing LiveKit API key/secret was rotated during release preparation because the previous
  value appeared in a local diagnostic output. The rotated secret is not recorded anywhere in the
  repository.
- Production API URL: `https://rtc.delnavazan.com`; browser endpoint:
  `wss://rtc.delnavazan.com`.
- Production classroom route is protected and redirects signed-out visitors to login.
- The portal hides **ورود به کلاس** when credentials are absent; it does not offer a broken primary
  action.

## Webhook configuration (verified)

LiveKit 1.13.11 configuration at `/opt/livekit/livekit.yaml` contains:

```yaml
webhook:
  api_key: <the existing LiveKit API key>
  urls:
    - https://delnavazan.com/wp-json/delnavazan-platform/v1/livekit/webhook
```

The LiveKit webhook token is sent directly in the `Authorization` header (not with a `Bearer`
prefix in this server version). The verifier accepts that form and the Bearer form. It verifies the
HS256 signature, issuer, expiry, and the base64-encoded SHA-256 body digest.

Verified events from the real production LiveKit server:

- `room_started`
- `participant_joined`
- `participant_left`
- `room_finished`

An actual test room and participant from the production LiveKit server reached the production
WordPress endpoint and received `200`. The participant was removed with
`PARTICIPANT_REMOVED`, and the LiveKit participant count returned to zero.

Direct endpoint tests also passed:

- valid signed delivery: `200`
- invalid signature: `401`
- tampered body: `401`
- duplicate delivery: `200`
- out-of-order `room_finished` and `participant_left`: `200`

## Classroom authorization rules

1. Resolve exactly one canonical Teacher or Student principal for the signed-in WordPress user.
2. Prove ownership and visibility through the existing portal authority.
3. Require a canonical `authorised` lifecycle. Cancelled, completed, legacy, and archived lessons
   cannot be opened.
4. Require the exact current `applicable_slot = 1` schedule version. A superseded version (including
   after a reschedule) is refused as `livekit_stale_schedule`.
5. Enforce the existing joining window: one hour before the start until two hours after the end.
6. Include exactly two derived participant identities — the assigned Teacher and the enrolled
   Student. A duplicate tab or reconnect uses the same identity, so LiveKit replaces the old
   connection rather than admitting a third person.
7. Create or verify the room with `max_participants = 2` before minting a token. Participant tokens
   set `roomCreate = false`, `roomAdmin = false`, `roomRecord = false`, and
   `canPublishData = false`.
8. Re-run steps 1–5 on every token request. The launch ticket is not authority.
9. Mint a five-minute participant JWT. The browser receives only the room token, never the API key
   or secret.

Room names are opaque HMAC-derived identifiers bound to the lesson/booking and current schedule
version. They contain no learner name, booking code, or database identifier.

### Token revocation limitation and mitigation

LiveKit participant JWTs are stateless. An already-issued five-minute token cannot be relied on to
disappear at the exact moment a Lesson is cancelled. `RemoveParticipant` is called with a
`revoke_token_ts`; LiveKit servers that implement token revocation invalidate older tokens, while a
server that does not still enforces the five-minute `exp`, room-scoped token. Delnavazan further
mitigates this with:

- a five-minute token lifetime;
- room names that change when the schedule version changes, so an old reschedule token cannot join
  the new occurrence;
- `maxParticipants = 2` and deterministic identities, so duplicate connections replace rather than
  add a person;
- canonical lifecycle/schedule hooks that schedule targeted room deletion and participant removal;
- the signed webhook, which rejects unknown identities and re-checks canonical authorisation on a
  participant join.

If a cancellation occurs after a token was issued, the room is deleted as soon as the out-of-band
revocation job runs. The residual worst case is bounded by the five-minute token lifetime and by the
room deletion. Do not describe this as instantaneous or universal token revocation; confirm the
behaviour of the deployed LiveKit server version.

## Google Meet fallback policy

- **Primary:** LiveKit classroom.
- **Backup:** existing Google Calendar/Meet link.
- The Meet link continues to be created and maintained by the existing canonical Google Calendar
  integration. LiveKit start does not delete, rotate, or replace it.
- Reschedules and replacement lessons continue through the existing calendar job/worker path.
- The classroom never redirects automatically to Meet. The user explicitly chooses
  **ورود با گوگل میت**.
- Both the primary and fallback actions are nonce-protected POSTs. The Meet URL is opened by the
  existing `TeacherClassJoinService`, `StudentClassJoinService`, or `BookingClassJoinService` after
  ownership and window checks. It is never rendered into the classroom DOM or returned by the
  session JSON.
- Meet availability is shown as a boolean, computed from the existing verified provider mapping and
  active vault secret. This adds no Google API call.

## Room lifecycle and reconnect behavior

- The room is created or verified on the server before the token is issued.
- Empty rooms expire after the LiveKit `empty_timeout` setting (300 seconds in the RoomService call).
- `participant_left` and `room_finished` do not alter canonical attendance.
- The browser uses LiveKit's built-in reconnect for transient network loss. On a full disconnect it
  requests a fresh token with bounded exponential backoff (four attempts). It does not request a new
  token on every reconnect event and stops on duplicate identity, expiry, cancellation, or leave.
- `DUPLICATE_IDENTITY` is shown as a Persian message instead of entering a reconnect loop.
- A participant may arrive before the other; the UI shows an explicit waiting state.
- Teacher and student can leave and return with a new launch action while still inside the canonical
  window.

## Music Mode settings and browser limitations

Music Mode requests:

- Opus audio (WebRTC default), no uncompressed/lossless claim;
- stereo where supported;
- 48 kHz capture where supported;
- `AudioPresets.musicHighQualityStereo` (128 kbps) where supported;
- `dtx = false`, `red = false`;
- `echoCancellation = false`, `noiseSuppression = false`, `autoGainControl = false`;
- `channelCount = 2`.

Normal conversation mode uses the speech preset, mono capture, and the browser's normal echo
cancellation/noise suppression/AGC.

Switching modes mutes and re-publishes the microphone with the new capture constraints and publish
preset; it does not reload the classroom. A short audio gap during the switch is expected.

Browser support is advisory. Safari on iPad/iPhone commonly ignores or partially applies
echo-cancellation, noise-suppression, AGC, stereo, and sample-rate constraints. The UI states that
the device may not accept every setting. Unsupported constraints degrade to the browser's available
capture rather than failing the class. Device enumeration on iOS may show generic labels until
permission is granted.

## Branding and watermark

- The classroom header uses the official `theme/assets/images/delnavazan-logo.png`.
- Both local and remote video tiles carry a small, transparent, low-opacity Delnavazan watermark in
  a video corner. It has no white background and uses a subtle dual drop-shadow for legibility over
  light and dark video.
- The watermark uses CSS logical properties (`inset-inline-*`) so it follows RTL. It does not overlap
  the participant label, face area, or controls, and it is `pointer-events: none`.
- The watermark is intentionally a UI overlay. **Recording is not implemented, and a CSS overlay
  would not appear in recorded video.** Any future recording/egress implementation must composite
  the mark into the recorded output separately.
- On desktop the remote participant is the dominant tile and the self-view is a smaller side tile.
- Fullscreen keeps the remote video large and places the self-view in a corner; controls remain
  available as a bottom overlay. Mobile fullscreen places the self-view above the controls.
- A **چرخش نمایش** control rotates the video presentation between landscape and portrait without
  rotating labels, controls, or the watermark. This is a per-participant display rotation; it does
  not re-encode or rotate the published camera stream for the other participant.
- The page uses Persian labels and RTL-aware responsive layouts for desktop, phone, and iPad.

## Deployment procedure

1. Create `/home/delnavaz/domains/delnavazan.com/private/` with mode `0700` and a private
   `livekit-config.php` with mode `0600`, owned by the PHP service account. It defines
   `DZN_LIVEKIT_URL`, `DZN_LIVEKIT_API_URL`, `DZN_LIVEKIT_API_KEY`, and
   `DZN_LIVEKIT_API_SECRET`. Do not write the secret into `wp-config.php`, a served `.env`, a
   WordPress option, JavaScript, or chat.
2. Add the guarded `require_once` for that private file to `wp-config.php`; the application only
   loads it when readable.
3. Deploy the reviewed plugin and theme through the existing GitHub → PR → `main` → protected manual
   deployment workflow. There is no database schema migration in this change.
4. Confirm the LiveKit server API is reachable over HTTPS at
   `https://rtc.delnavazan.com/twirp/livekit.RoomService/ListRooms` with the configured credentials.
5. Configure `/opt/livekit/livekit.yaml` with the `webhook` block above, using the existing API key.
   Restart only the `livekit` container and verify the YAML parses and the container returns healthy.
6. Verify with one teacher account and one enrolled student account in separate browsers/devices:
   primary LiveKit entry, primary join, Music Mode on both sides, device switching, reconnect,
   explicit Meet fallback, and cancellation/reschedule refusal.
7. Verify a third account and a guessed/stale link are refused.
8. Run `deployment/qa/production-smoke.sh https://delnavazan.com`.

The repository does not contain the LiveKit VPS Compose file. The deployment procedure above was
executed against the real VPS; do not edit it again unless a verified defect requires it.

## Troubleshooting

| Symptom | Likely cause | Action |
| --- | --- | --- |
| `livekit_unavailable` | API key/secret missing, API URL unreachable, or RoomService failure | Check protected configuration and `https://rtc.delnavazan.com` from the production host. Do not print the secret. |
| `livekit_link_expired` | Signed ticket older than six hours, or the page was left open too long | Return to the portal and select **ورود به کلاس** again. |
| `livekit_access_denied` | Wrong account, cancelled/completed lesson, stale schedule, outside window, or third participant | Check canonical lesson state and the signed-in principal. |
| `livekit_stale_schedule` in logs | Old reschedule/schedule version | Refresh the portal; the current card issues the current schedule version. |
| Join succeeds but no audio | Browser permission denied, device busy, or Safari capture limitation | Check the Persian media-permission message, device selector, and meeting mode. |
| Third person enters | Webhook not configured and room was created outside the application | Keep API key/secret private; delete the room; verify only the application can mint tokens. |
| Meet fallback unavailable | Provider mapping or vault secret missing | Use the existing calendar projection recovery procedure; do not fabricate a Meet URL. |
| Tunnel/proxy error | Caddy/LiveKit/Redis unavailable | Check the LiveKit VPS containers and restart policy. |

Never log the API secret, participant JWT, launch ticket, Meet URL, or raw provider reference.

## Rollback procedure

1. Roll back the plugin and theme to the previous deployed release through the standard deployment
   workflow. No database rollback is needed because this change adds no table or column.
2. To disable LiveKit without changing code, remove the guarded `require_once` from `wp-config.php`
   or rename the private `livekit-config.php`. The portal readiness gate then hides the LiveKit
   action; Google Meet remains available.
3. Remove the `webhook` block from `/opt/livekit/livekit.yaml` and restart only the `livekit`
   container if webhook delivery must stop.
4. Existing Google Meet links, calendar mappings, canonical lessons, schedules, attendance, and
   finance records are untouched and remain valid.
5. Active rooms may survive until empty timeout; delete them from the LiveKit control plane if
   necessary.
6. On any credential exposure, rotate the LiveKit API key/secret on the VPS, update the protected
   production config, and restart LiveKit.

## Known limitations

- LiveKit stateless participant JWTs cannot be individually revoked before `exp`; see the
  five-minute mitigation above.
- Webhook enforcement is enabled on the production VPS. LiveKit sends all webhook events; the
  application acts on `participant_joined` and `room_finished` and safely ignores unrelated events.
- The webhook endpoint is intentionally public and fails closed without a valid signed JWT.
- The LiveKit SDK is vendored from `livekit-client@2.23.0` under Apache-2.0. It must be reviewed and
  re-vendored deliberately on upgrade.
- Browser capture constraints are advisory. Safari/iOS may ignore stereo, sample rate, and audio
  processing flags.
- The classroom rotation control rotates the local presentation only. Publishing a physically
  rotated camera stream would require a canvas/video processor and is not implemented.
- No recording, egress, transcription, analytics, data-channel messaging, or PixelBox storage is
  implemented.
- Real two-browser Chrome testing was completed against the production LiveKit server. Physical
  iPad/iPhone Safari and real microphone/speaker quality were not tested in this release.
- Production authenticated teacher/student portal click-through was not performed because no
  controlled production test accounts were available; the same deployed code was exercised in the
  disposable WordPress runtime with the production LiveKit server.
- A live Google Meet redirect was not exercised because no controlled authenticated production
  test account was available; the existing production Meet mapping and vault reference were
  verified read-only.

## Future recording and PixelBox storage integration (not implemented)

Recording is out of scope for this task. If it is added later:

- create an explicit canonical recording decision and consent authority before any egress;
- use LiveKit Egress with a dedicated server-side participant/API credential, never a browser token;
- composite the Delnavazan watermark into the recorded output (for example with an egress layout or
  post-processing), because a CSS overlay is not captured;
- store recordings only in the approved PixelBox storage boundary with retention, access, and deletion
  policy;
- keep LiveKit webhooks and egress events out of attendance/completion authority;
- add separate security, privacy, and rollback review for recording.

## Automated checks

```sh
php platform/tests/livekit-jwt-issuer-unit.php
php platform/tests/livekit-classroom-authorization-unit.php
php platform/tests/livekit-portal-integration-contract.php
```

The disposable canonical-authorization runtime is part of the attendance runtime:

```sh
DZN_RUNTIME_STATE_DIR=/tmp/dzn-livekit-runtime \
DZN_COMPOSE_PROJECT=dzn-livekit-runtime \
DZN_WP_PORT=18088 \
platform/runtime/bin/run-attendance-runtime.sh
```

It creates a real canonical Term/Lesson/schedule through the existing authorities, links one Teacher
and one Student principal, and exercises the real authorizer (authorised teacher/student, third
participant, duplicate identity, stale schedule after reschedule, launch ticket, and cancellation).
It contacts no LiveKit server and uses no live credential.

A manual/staging browser check is available at
`platform/runtime/browser/livekit-classroom-browser-test.js`. It requires Playwright and synthetic
teacher/student/admin accounts plus a lesson inside the joining window:

```sh
npm install playwright
DZN_BROWSER_STUDENT_USER=... DZN_BROWSER_STUDENT_PASS=... \
DZN_BROWSER_TEACHER_USER=... DZN_BROWSER_TEACHER_PASS=... \
DZN_BROWSER_EXPECT=unavailable|connected \
node platform/runtime/browser/livekit-classroom-browser-test.js
```

These are automated checks. Real two-device audio/video verification is separate and requires the
actual LiveKit credentials and two physical devices; it is recorded in the release report when those
are available.

## Verification status (2026-10-31)

Deployed production build: `main-cee1d2f23157` (commit
`cee1d2f23157e958220c9af6234a1ac877a478d9`, schema 57).

Passed:

- all LiveKit unit/source contracts and the full PR runtime gate;
- real LiveKit production RoomService: create room with `max_participants=2`, verify, delete;
- real server enforcement: first two participants connected, third direct-token participant was
  rejected, room reported exactly two participants;
- two separate Chrome sessions through the Delnavazan classroom: Teacher and Student connected to
  the same real production room, both saw `۲ از ۲ نفر`, and Music Mode switched without reload;
- duplicate identity: second connection replaced the first, the first received the Persian
  duplicate-session message, and the room did not gain a third participant;
- expired token beyond LiveKit's allowed clock leeway was rejected;
- real production webhook delivery: LiveKit `room_started`, `participant_joined`,
  `participant_left`, and `room_finished` reached the production endpoint; an unknown participant
  was removed with `PARTICIPANT_REMOVED` and the server count returned to zero;
- invalid signature and tampered body were rejected with `401`;
- duplicate and out-of-order webhook deliveries were accepted safely;
- production smoke suite: `RESULT: PASS`;
- production routes: `/music-classroom/` redirects signed-out visitors to login; session and webhook
  endpoints return `401` without authentication/signature.

Google Meet production status:

- 2 verified Google Calendar mappings, 2 active calendar vault secrets, and 1 active booking-calendar
  secret;
- the newest verified Meet reference decrypted successfully, with host `meet.google.com` and a
  non-empty path, without printing the URL;
- a live redirect through an authenticated production test account was not performed.

Not verified:

- physical iPad/iPhone Safari audio/video and device switching;
- physical microphone/speaker quality on two real devices;
- a controlled authenticated production Meet redirect.
