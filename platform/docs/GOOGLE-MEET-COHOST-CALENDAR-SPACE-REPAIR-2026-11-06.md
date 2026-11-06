# Google Meet co-host repair — Calendar-created space limitation

Status: **code-only repair prepared; not validated against a live Google tenant and not applied to
production.** This document records the production evidence, the official Google API support
boundary, the implemented design, the historical-booking limitation, the staging-only acceptance test
and the per-booking production recovery plan that requires separate approval.

## 1. Production evidence (read-only)

| Item | Observed |
| --- | --- |
| Booking confirmations | #9–#12 failed with `meet_cohost_assignment_blocked` |
| Latest failure | #12 |
| Teacher acceptance | preserved (`delivery_state=accepting`) |
| Calendar/Meet event creation | HTTP 200 |
| Member assignment | `spaces.members.create` → HTTP 403 `permission_denied` |
| Provider detail | "Permission denied on resource Member (or it might not exist)." |
| Organizer OAuth | connected, verified, correctly scoped, refreshed successfully |
| Worker | scheduled and present |
| Manual organizer test | owner created a Meet event, assigned the same teacher as co-host, ran and recorded the class successfully with the organizer account |

The manual success proves the tenant and the teacher can use Meet host management. It does **not**
prove that the same operation is allowed through the Meet API on a space created by Calendar.

## 2. Official Google documentation findings

The current official documentation establishes this support boundary:

1. The `https://www.googleapis.com/auth/meetings.space.created` scope is described as:
   “Allow apps to create, modify, and read metadata about meeting spaces created by your app.”
   ([Authenticate and authorize](https://developers.google.com/workspace/meet/api/guides/authenticate-authorize))
2. The meeting-space OAuth table separates app-created spaces from spaces created by other apps
   (for example Calendar). For external spaces it lists settings and artifact read scopes, but no
   member-management permission.
   ([Meeting spaces overview — OAuth scopes by use case](https://developers.google.com/workspace/meet/api/guides/meeting-spaces-overview#oauth-scopes-by-use-case))
3. `spaces.members.create` requires `meetings.space.created`; member methods require the exact
   `spaces/{space}` parent, not the `spaces/{meetingCode}` alias.
   ([spaces.members.create](https://developers.google.com/workspace/meet/api/reference/rest/v2/spaces.members/create),
   [Manage meeting space members](https://developers.google.com/workspace/meet/api/guides/meeting-space-members))
4. The documented lifecycle is: call `spaces.create`, then configure settings and manage members on
   the space that call returned.
   ([Manage meeting spaces](https://developers.google.com/workspace/meet/api/guides/manage-meeting-spaces))
5. Calendar's `conferenceData` can represent either a **new** conference (`createRequest`) or
   **existing** conference details (`conferenceSolution` plus at least one `entryPoint`). For
   `hangoutsMeet`, `conferenceId` is the meeting code. Calendar explicitly warns that conference data
   must not be reused across different events.
   ([Events resource — conferenceData](https://developers.google.com/workspace/calendar/api/v3/reference/events#resource),
   [Create events — add video conferences](https://developers.google.com/workspace/calendar/api/guides/create-events#add_video_and_phone_conferences_to_events),
   [events.insert](https://developers.google.com/workspace/calendar/api/v3/reference/events/insert))

### Conclusion

The production 403 is consistent with a **provenance** problem, not a missing OAuth grant:

- the old flow created the conference through Calendar `conferenceData.createRequest`;
- Google Calendar therefore owned/created the Meet space;
- the app then tried to manage a member of that Calendar-created space with
  `meetings.space.created`, which only covers spaces created by the app.

This is now the leading supported explanation. It is still a hypothesis until the staging-only live
test in §6 observes the same space identity and a verified `COHOST` membership end to end. The repair
does not claim that the Google tenant has already confirmed it.

The leading alternative explanations were checked and are weaker:

- **Exact space name / alias:** member methods require `spaces/{space}`. The code now resolves and
  re-proves the exact opaque resource name and never uses `spaces/{meetingCode}` for members.
- **Organizer identity:** the space is created with the same connected organizer grant, and the
  sealed reference binds `space_organizer` to the configured organizer e-mail.
- **Teacher identity:** the teacher e-mail is normalized and read back from the member list; a
  different identity cannot be treated as verified.
- **Moderation state:** the app sets moderation `ON` before assigning the co-host.
- **Tenant policy:** external Teacher co-host eligibility remains a tenant question; a documented
  refusal would still be permanent, not a transient error.

## 3. Implemented design (smallest supported path)

New lessons/bookings now use this order:

1. **Create the app-owned space first** with `spaces.create`.
2. **Seal a pending reference** containing the exact `spaces/{space}` name, join URI,
   `space_origin=app` and organizer identity before the Calendar event exists.
3. **Create or converge the Calendar event** with the deterministic event id and existing conference
   details:
   - `conferenceSolution.key.type = hangoutsMeet`
   - `conferenceId = <meeting code>`
   - one `video` `entryPoint` with the app space's join URI
   - **no** `createRequest`
4. **Verify the exact space** returned by `spaces.get`; a different name is an identity failure.
5. **Configure moderation/access/recording** before membership.
6. **Create and verify the teacher as `COHOST`** on the exact app space.
7. Mark ready only after the provider read-back confirms the role.

Preserved invariants:

- one deterministic Calendar event id per lesson/booking;
- one active classroom row and one meeting per lesson/booking;
- Calendar attendees/invitations remain on the same event;
- `409` Calendar responses re-read the exact event and converge;
- a different existing conference identity fails closed instead of silently adopting it;
- an ambiguous `spaces.create` (transport/5xx) is never retried automatically because the response
  may have contained the only copy of the new space name;
- transient member/config/lookup failures keep bounded retries; a 403 remains permanent;
- cancellation and reschedule operate on the same event and app space;
- recording remains consent-gated and is configured on the app space;
- a retry of an app-owned blocked booking reuses the existing row, event and space;
- a retry of a blocked/failed **term lesson** re-opens the latest classroom row instead of creating a
  new projection, so the sealed event/space is reused; if provider identity exists but the sealed
  reference is unavailable, the retry fails as `meet_classroom_reference_missing` before any
  `spaces.create` or Calendar write.

### Historical Cal-created rows

A reference produced by the old flow has a Calendar-created space and no `space_origin=app` marker.
The repair does **not** call `spaces.members.create` on it again. It persists
`meet_cohost_calendar_space_unsupported`, keeps the same row/event/space, and leaves the booking
blocked. This is deliberate: creating a second space during an ordinary retry would violate the
one-meeting invariant. Historical #9–#12 therefore need the separately approved recovery in §7.

## 4. Failure classification

| Condition | Result |
| --- | --- |
| `spaces.create` 429 | `meet_space_create_retryable`, bounded retry |
| `spaces.create` transport/408/5xx | `meet_space_create_ambiguous`, permanent until operator reconciliation |
| `spaces.create` other 4xx | `meet_space_create_refused`, permanent |
| `spaces.get` wrong resource name | `meet_space_identity_mismatch`, permanent |
| Calendar 409 with a different conference | `meet_calendar_conference_mismatch`, permanent |
| Row has provider identity but no open sealed reference | `meet_classroom_reference_missing`, permanent, no provider call |
| Member 403 `permission_denied` | `meet_cohost_assignment_blocked`, permanent |
| Member transport/429/5xx | `meet_cohost_assignment_retryable`, bounded retry |
| Legacy Calendar-created reference | `meet_cohost_calendar_space_unsupported`, permanent, no provider call |

## 5. What can and cannot be claimed from tests

The PHP tests prove the source contract and the state machine with fake transports:

- exact app space creation and sealed identity;
- Calendar attachment uses `entryPoints`/`conferenceId`, never `createRequest`;
- co-host mutation uses `spaces/{opaque space}`, not the meeting-code alias;
- provider-verified role is required for readiness;
- 403 is permanent and 5xx is bounded-retryable;
- duplicate runs, Calendar `409`, transient member retry and blocked app-owned recovery converge on
  the same event/space;
- legacy Calendar-created rows fail closed without new objects;
- recording, reschedule and cancellation still target the same app space/event.

They do **not** prove that Google Calendar in this tenant accepts an app-created space through
`conferenceData.entryPoints`, that Meet shows the native join action, or that the tenant grants the
external Teacher `COHOST`. Those require the staging-only live test below.

## 6. Staging-only live acceptance test (not executed here)

**Execution status: blocked in this workspace.** The repository records that Google OAuth is not
configured on staging, and this task explicitly prohibits calling live Google APIs. The test below is
the required next gate and must be run in an isolated staging environment with staging-only
credentials. Passing mocked PHP tests is not evidence that Google accepts this Calendar attachment.

Prerequisites: a non-production staging site, a staging Google Cloud project, a staging organizer
account, a staging test Teacher, a staging test Student, and no production credentials or data.

1. Connect the staging organizer grant and record the granted scope diagnostics (`scope_match=true`).
2. Create one staging test booking/lesson with the test Teacher and Student.
3. Run the normal provisioning path once.
4. Verify from the provider, not from the UI alone:
   - exactly one Calendar event with the deterministic id;
   - `conferenceData.conferenceId` equals `spaces.get(space).meetingCode`;
   - `conferenceData.entryPoints[video].uri` equals the app space's `meetingUri`;
   - `spaces.get` returns the exact sealed `spaces/{space}` name;
   - `spaces.members.list` contains the Teacher e-mail with role `COHOST`;
   - moderation is `ON`.
5. Open the event as the organizer in Calendar and confirm the native Meet join action points at the
   same meeting code.
6. Join as the test Teacher and confirm co-host controls; join as the test Student and confirm
   participant access.
7. Reschedule the staging booking; verify the **same** event id and space name.
8. Cancel the staging booking; verify the same event is cancelled and the row is preserved.
9. Record only if the staging consent path is eligible; otherwise verify auto-recording is `OFF`.
10. Clean up only staging provider objects and staging rows. Do not run this against production.

Acceptance is only the provider-backed result from step 4 plus the UI check in step 5. A fake
transport or a successful Calendar HTTP 200 is not acceptance.

## 7. Per-booking production recovery plan (requires separate approval)

Historical #9–#12 already own a Calendar event and a Calendar-created space. A plain retry cannot
convert that space. Do not run `retry-booking` repeatedly hoping for a different result. The following
is a proposed plan, not an authorised production action.

For one confirmation at a time, after a new read-only review:

1. **Freeze** scheduled retries for that classroom and record the current confirmation version,
   delivery state, classroom row version, event id, join URI and sealed reference.
2. **Prove eligibility:** the booking is not cancelled, ended or reallocated; the acceptance delivery
   still belongs to the same confirmation; no live teacher offer exists. Historical #9–#11 rows still
   in `pending_request` continue through the existing guarded, version-checked recovery path, not a
   new offer.
3. **Preserve the Calendar invitation:** keep the existing event. Create one new app-owned space with
   `spaces.create` under the organizer, then PATCH the same event with the new space's
   `conferenceData` (`conferenceSolution` + `conferenceId` + `entryPoints`, `conferenceDataVersion=1`).
   Do not create a second event.
4. **Verify attachment:** re-read the event and require `conferenceId` to equal the new app space's
   meeting code. On mismatch, stop and restore the prior sealed reference; do not assign a co-host.
5. **Configure and verify:** set moderation `ON`/access, apply recording only if consent is current,
   assign the Teacher `COHOST` on the exact new space, and read the membership back.
6. **Commit atomically:** retire the old sealed reference and persist the new event/space reference,
   pending app-space reference, provider evidence and audit. Mark the classroom ready only after the
   provider read-back.
7. **Finish the booking through the existing guarded recovery path** so the acceptance delivery stays
   intact and no new teacher offer is issued. If any provider step fails, leave the row blocked; no
   partial ready state.
8. **Orphan handling:** the old Calendar-created space remains unreferenced and expires by Google's
   meeting-code lifecycle. Record it as orphaned operational evidence; do not delete Calendar rows or
   recordings.

This requires a dedicated, audited operator command or an approved one-off runbook because it
intentionally creates a replacement space for a historical booking. It must not be wired into the
automatic worker or into ordinary `retry-booking`. Approval must name the confirmation ids, versions,
operator, reason and rollback point.

## 8. Remaining blockers / tenant questions

- Confirm in staging that Calendar accepts and preserves an app-created space supplied through
  `conferenceData.entryPoints` and that the event shows the native Meet join action.
- Confirm the staging organizer remains the effective owner and `spaces.members.create` succeeds with
  the same grant after the Calendar attachment.
- Confirm the tenant permits the external test Teacher to receive `COHOST`; if not, the refusal is
  permanent and needs a documented exception or a different Teacher identity.
- Confirm Google does not rewrite the supplied conference id or create a second conference on insert.
- Confirm recording/attendance still resolve the same meeting code after the attachment.
- Confirm the historical per-booking recovery policy before touching #9–#12.

No production claim should be made until the staging provider checks in §6 pass and the per-booking
recovery in §7 is separately approved and executed.
