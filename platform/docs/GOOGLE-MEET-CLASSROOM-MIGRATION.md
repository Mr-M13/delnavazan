# Google Meet classroom migration (LiveKit → Delnavazan-owned Workspace)

Status: **source implementation complete on branch `feat/google-meet-classroom-20261104`. Production
deployment and live-provider acceptance have not been performed from this workspace.** This document
separates what the code does, what Google officially supports, and what still needs a real tenant
test. No claim below should be read as a passed production test.

## 1. Archive of the previous classroom

| Item | Value |
| --- | --- |
| Final LiveKit commit on `main` | `cbd2c5ad` |
| Immutable tag | `livekit-classroom-final-main-20261104` |
| Archival branch | `archive/livekit-classroom-final-main` |
| Recovery document | `platform/docs/LIVEKIT-ARCHIVE-AND-RECOVERY.md` on the archival branch |

The source, vendor SDK, tests, legal copy and production notes are preserved. The earlier tag
`livekit-classroom-final-20261104` (`ebb45607`) remains as an additional checkpoint. No LiveKit
credential or recording is committed here.

## 2. Organizer model and OAuth scopes

One Delnavazan-controlled account, `classroom@delnavazan.com` (override with
`DZN_MEET_ORGANIZER_EMAIL`), owns every Meet space. Teachers are external co-hosts and students are
regular participants. Teachers do **not** connect personal Google accounts.

Minimum scope set requested by `GoogleWorkspaceClassroomRule::SCOPE_SNAPSHOT`:

| Scope | Why |
| --- | --- |
| `https://www.googleapis.com/auth/calendar.events` | create/update/cancel the lesson event and its `conferenceData` |
| `https://www.googleapis.com/auth/meetings.space.created` | read/patch the created space and create/delete co-host members |
| `https://www.googleapis.com/auth/meetings.space.settings` | patch access and automatic-recording configuration |
| `https://www.googleapis.com/auth/meetings.space.readonly` | list conference records, participants, sessions and recordings |
| `https://www.googleapis.com/auth/drive.readonly` | verify the MP4 artifact and stream it to an authorized Delnavazan user |
| `openid email` | prove that the authorized account is exactly the configured organizer |

Full `drive`, full `calendar`, `gmail`, `admin.directory` and browser automation are not requested.

OAuth state and PKCE verifier are one-time, digest-only in `meet_workspace_authorizations` (the verifier
also lives in a short server-side transient), and the callback verifies the verified organizer email.
Credentials are sealed with `IntegrationSecretService` and rotated on refresh. Connection health is
shown at **Settings → Integrations → Google Workspace classroom**.

Authorized redirect URI to register in Google Cloud:

```
https://<production-host>/wp-admin/admin-post.php?action=dzn_meet_oauth_callback
```

The settings page displays the exact value.

## 3. Classroom creation workflow

Entry points:

- canonical term lessons: `PostIntroActivationService::projectCalendar()` calls
  `GoogleWorkspaceClassroomService::ensureForLesson()`;
- introductory bookings: `BookingCalendarProjectionService::dispatch()` calls
  `ensureForBooking()` before confirming the booking;
- migration of already-scheduled future lessons/bookings: `wp dzn-meet migrate`;
- reschedules: `LessonRescheduleCalendarService` calls `rescheduleLesson()` and PATCHes the same event;
- teacher replacement: `replaceTeacher()` removes other co-hosts and assigns the new co-host;
- cancellation: `cancelLesson()` cancels the Calendar event and ends an active conference, preserving
  the classroom row and artifacts.

Order of operations:

1. Re-read the canonical lesson/schedule version (or the confirmed booking).
2. Create or converge the Calendar event with a deterministic event id, `conferenceData.createRequest`
   and `sendUpdates=all`.
3. Read the official Meet space by meeting code.
4. Create the teacher member with role `COHOST`. If the tenant refuses, the exact HTTP status/error
   class/message is written to `meet_classroom_events`, moderation is switched off, and the teacher
   remains a Calendar invitee/participant. No browser automation is used.
5. Patch access to `OPEN` (override with `DZN_MEET_ACCESS_TYPE=OPEN|TRUSTED|RESTRICTED`) and
   moderation `ON`; patch `artifactConfig.recordingConfig.autoRecordingGeneration` to `ON` only when
   `RecordingDecisionAuthorityService::lessonRecordingEligibility()` returns eligible, otherwise
   `OFF`.
6. Seal the event id and Meet URI in `provider_projection_secrets` (`meet_classroom`) and persist the
   canonical lesson id, schedule version id, teacher id/email digest, student id/email digest,
   organizer, event digest, space name, Meet URI digest and recording consent snapshot.

Idempotency: deterministic event id, one active classroom row per lesson/booking, unique command/audit
digests, and `409`/GET convergence on Calendar creation.

## 4. Recording lifecycle (official APIs only)

1. `spaces.patch` sets automatic recording generation.
2. After the lesson, the worker calls `conferenceRecords.list` filtered by `space.meeting_code`.
3. `conferenceRecords.recordings.list` returns `STARTED`, `ENDED` or `FILE_GENERATED`.
4. For `ENDED`/`FILE_GENERATED`, `driveDestination.file` is persisted and verified with
   `files.get` (`mimeType=video/mp4`, non-zero size) and a one-byte `alt=media` range probe.
5. `permissions` are inspected. An `anyone` permission raises `recording_publicly_shared`.
6. Playback is proxied through `admin-post.php?action=dzn_meet_recording_play`, which re-proves lesson
   ownership and current recording eligibility on every request. The browser never receives a Drive
   URL or access token.
7. Missing/unreadable/unexpectedly ended recordings raise administrator alerts shown in
   Settings → Integrations.

### Supported / unsupported Google controls

| Control | Status |
| --- | --- |
| Auto-recording configuration | Supported by `spaces.patch` (`artifactConfig.recordingConfig.autoRecordingGeneration`). Requires tenant policy and a participant with recording privilege. |
| Recording retrieval and Drive file id | Supported by Meet API + Drive API. |
| Restrict “Stop recording” independently of co-host permissions | **Not supported by the public Meet API.** Co-hosts with host management can stop recording. Delnavazan monitors the artifact and alerts; it does not conceal recording or override Google controls. |
| Start/stop recording through Delnavazan | Not implemented. No supported API to force a recording session without a privileged participant. |
| Codec, bitrate, noise suppression, echo cancellation through API | **Not supported.** These remain client/device controls. |
| Music mode via API | **Not supported.** Persian guidance is shown on the entry page. |
| Custom meeting background / organization branding injected by Delnavazan | **Not supported.** Only the Delnavazan entry page is branded. No scripts are injected into Meet. |
| Recording watermark | Not a Meet API feature. Post-processing a copy is allowed only while preserving the original. |
| Participant sessions | Supported (`participantSessions`), including anonymous/phone guests but without a Google account identifier. |
| Co-host assignment | Supported (`spaces.members` role `COHOST`) where tenant policy permits. Exact refusal is recorded. |

## 5. Audio quality guidance

Google Meet exposes no API for codec bitrate, noise suppression or echo cancellation. The entry page
gives Persian guidance: use headphones, keep a fixed microphone distance, open Meet audio settings,
disable noise cancellation when it colours the instrument, and choose the music/original-sound option
when the client offers it. Workspace administrators can set organization-level defaults in the Google
Admin console, but those settings are outside this application and are not asserted by code.

## 5a. Recording privacy, co-host readiness and playback policy (hardening)

**Drive privacy verification.** Metadata and permissions are separate calls. The service calls
`driveFile` and then `driveFilePermissions`, and classifies the explicit permission list:

| State | Meaning | Playback |
| --- | --- | --- |
| `verified_private` | non-empty permission list, organizer only | allowed |
| `public_shared` | any `anyone` permission | denied + alert |
| `domain_shared` | any domain-wide permission | denied + alert |
| `shared_with_others` | any other user/group or unexpected permission type | denied + alert |
| `verification_failed` | API refusal, transport failure, empty or malformed permissions | denied + alert |

Missing permissions data is never treated as private. The actual HTTP status/error class/message is
stored in the alert evidence.

**Playback access.** Media is returned only for:

- the owning Student or Teacher with current recording consent and `verified_private` storage;
- a named compliance capability (`dzn_manage_recording_operations` or
  `dzn_manage_recording_legal_hold`) with a documented purpose and reason. This exceptional access is
  appended to `meet_recording_access_events`.

An ordinary administrator receives metadata only and cannot bypass consent. Research/dataset access
is refused here and remains a separate authority. Every allow/deny decision is audited.

**Guardian authority.** For a minor Student, the latest recording decision must reference an active,
current `student_acceptance_authority_grants` row with the matching version. A missing, stale or
wrong-student grant makes recording ineligible.

**Co-host readiness.** A classroom is `ready` only when the teacher is verified as a `COHOST` member
(or an administrator explicitly approved a documented exception). A failed assignment is `blocked`
and visible in Settings → Integrations. Transport/429/5xx failures retry with bounded backoff; a
permanent refusal does not. There is no automatic moderation downgrade or silent participant
fallback. Approval:

```sh
wp dzn-meet approve-cohost-exception --lesson_id=456 --reason="Documented operational exception"
wp dzn-meet retry-classroom --lesson_id=456
```

**Meeting access.** `OPEN` remains the least-permissive setting that reliably admits an external
teacher co-host and a Student without a Google account while the organizer is absent. `TRUSTED` and
`RESTRICTED` can require sign-in/knocking and are not safe defaults for that exact participation
pattern. Compensating controls: the Meet URI is vaulted and only released to an authenticated,
ownership-checked participant inside the join window; links are not rendered in public pages; access
is audited. Hiding a link in WordPress does not prevent anyone who obtains the URL from joining —
this limitation is explicit.

**Processing delays.** Missing/unplayable recording alerts wait two hours after the lesson. Early
termination is alerted when provider state says the recording ended while the lesson was still in
progress. Existing files and consent evidence are never deleted.

## 6. Attendance and completion

`MeetAttendanceService` stores every participant session in `meet_participant_sessions`:

- signed-in users → `users/{id}` account digest;
- anonymous/phone guests → display name only, marked `unverified`;
- a session reaches `CanonicalAttendanceIntakeService` only when an administrator has recorded a
  verified Phase-P mapping for that Meet account. Name matching is never treated as verified identity.

A Calendar event or recording alone never completes a lesson or creates payability. Disputes and
administrator adjudication remain in the existing Phase-O/P workflows.

## 7. Operations

```sh
wp dzn-meet status
wp dzn-meet migrate --limit=50 --dry-run
wp dzn-meet migrate --limit=50
wp dzn-meet reconcile --limit=20
wp dzn-meet legacy-grants
wp dzn-meet map-account --role=student --participant_id=123 --account_key=users/abc123
wp dzn-meet replace-teacher --lesson_id=456 --teacher_id=789
wp dzn-meet rollback
```

- **Migrate** provisions future canonical lessons and confirmed bookings; it does not change canonical
  state and does not revoke legacy grants.
- **Reconcile** reads recordings and participant sessions for ended classrooms.
- **Legacy grants** lists Teacher Google connections for a controlled retirement decision. Nothing is
  revoked automatically.
- **Rollback** disconnects the organizer locally without deleting rows or recordings.

Emergency switch: define `DZN_MEET_CLASSROOM_ENABLED=false` to stop new provisioning while existing
Meet links remain usable; redeploy the archival LiveKit tag only as a separate, explicit rollback.

## 7a. Required Google Cloud / Workspace configuration

- Enable Calendar API, Meet REST API and Drive API in the Google Cloud project.
- Register the exact organizer callback URI and keep the OAuth client secret in encrypted settings or
  protected deployment constants.
- Enable Meet recording for the Workspace organizational unit and allow external co-hosts/participants
  as required by policy.
- Set Drive sharing so recordings are not public/domain-wide; Delnavazan verifies and alerts but the
  `drive.readonly` scope cannot rewrite sharing.
- Confirm the organizer account has a Workspace licence that includes Meet recording.
- Confirm the tenant permits `spaces.members` COHOST assignment to external users. If not, use the
  explicit administrator exception workflow and record the limitation.

## 8. Verification still required on the real tenant

The following acceptance conditions cannot be claimed from this workspace because they need live
Google Workspace traffic, real teachers/students, and a production deployment:

- one external teacher co-host joins;
- two simultaneous classrooms;
- automatic recording with the organizer absent (depends on co-host recording privilege in this
  tenant — report the exact result);
- recording processed into organizer Drive and playable;
- student without a Google account joins;
- teacher joins from Iran;
- mobile/tablet participation and instrument/speech audio quality;
- Calendar invitation delivery;
- reschedule/cancel/teacher replacement;
- consent rejection leaves recording off;
- playback restrictions and public-sharing alert;
- attendance reconciliation with a verified participant mapping;
- notification and payment workflows remain operational;
- no obsolete Teacher OAuth prompt remains in production.

Before production deployment run `php platform/tests/google-meet-classroom-unit.php`,
`php platform/tests/google-meet-classroom-contract.php`, the existing Google OAuth/booking/reschedule
tests, and the full CI gate. These are source/unit contracts; they do not prove Google behaviour.

## 9. Costs and remaining risks

- Workspace Business Standard licence(s) for the organizer and any staff accounts.
- Google Drive storage grows with recordings; retention is a business policy, not automated here.
- Meet recording may be unavailable if the tenant disables it or no participant has recording
  privilege. The system alerts; it cannot override policy.
- `drive.readonly` cannot rewrite sharing. Workspace Drive policy must prevent public links; the
  system detects and alerts but does not remediate automatically.
- Anonymous guests cannot be identity-verified from Meet alone.
- Legacy Teacher grants remain until an operator reviews `wp dzn-meet legacy-grants` after all future
  events are migrated.
- OVH/Pi infrastructure is untouched; do not cancel or delete until remaining dependencies are
  confirmed.

## 10. Rollback

1. Define `DZN_MEET_CLASSROOM_ENABLED=false` (or run `wp dzn-meet rollback`) to stop new provisioning.
2. Keep `meet_classrooms`, `meet_classroom_references`, `meet_recording_artifacts` and legacy
   `integration_*` rows. Do not drop tables or delete Drive files.
3. Existing central Meet links continue to open through the join services.
4. To restore the previous classroom UI, deploy the archival tag/branch, restore the previous
   `DZN_LIVEKIT_*` credentials from the protected production config, and re-register the LiveKit
   webhook. This is a separate change window and has not been performed here.
5. Re-run the full verification matrix before declaring either direction complete.
