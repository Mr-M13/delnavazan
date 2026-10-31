# LiveKit Canonical Attendance Adapter

Status: **IMPLEMENTED IN SOURCE — NOT DEPLOYED, NOT ACTIVATED, NO CUTOVER PERFORMED.**

This adapter turns signed LiveKit participant lifecycle evidence into the existing Phase-P
canonical attendance path. It does **not** introduce a second attendance policy, delivery
authority, completion authority or finance authority. It is disabled by default.

## Locked policy reused

The adapter delegates every temporal and identity decision to the existing
`CanonicalAttendanceRule` and `CanonicalAttendanceIntakeService`:

| Fact | Value |
| --- | --- |
| Automatic delivery threshold | **1200 seconds** of simultaneous Teacher/Student presence |
| Exact boundary | 1200 passes; 1199 does not |
| Qualifying window | `[scheduled start, scheduled end + 900s)` |
| Pre-start grace | **0 seconds** |
| Post-end grace | **900 seconds** |
| Multiple connections/devices | Union per participant, then intersect Teacher and Student |
| Open/missing leave | Never qualifies and is never inferred |
| Below threshold / partial | Phase-P evidence and anomaly/review; no blame, no-show, Phase-O `review_required`, or financial consequence |
| Human reports | Remain claims requiring the existing adjudication process |
| Late evidence after Term closure | Retained and flagged `late_evidence`; no automatic settlement |
| Cutover | Prospective only, one immutable policy per instant, no historical backfill |

The adapter adds `livekit` to the controlled Phase-P provider-code list. It does not change the
rule version, threshold, window, union/intersection semantics, identity registry or settlement
stages.

## Architecture

```text
authenticated token issuance
  -> server-owned grant (lesson, schedule version, room, teacher/student identities)
  -> durable room binding + durable provider identity mappings

signed LiveKit webhook
  -> signature/body verification (existing verifier)
  -> normalized join/leave/room-finished fact
  -> append-only event receipt + exact connection correlation
  -> closed interval (provider join/leave timestamps only)
  -> CanonicalAttendanceIntakeService::ingestProviderEvidence()
  -> Phase-P assessment / anomaly review
  -> existing staged settlement: Phase-O delivered + Lesson completion
```

The webhook never calls settlement, completion, finance or lifecycle services directly.

### Durable correlation storage

The adapter installs three feature-local, additive tables. It does not advance the platform-wide
schema option, so an unrelated in-flight platform migration cannot be activated by this change.

| Table | Purpose |
| --- | --- |
| `dzn_livekit_attendance_rooms` | Exact room → Lesson + immutable schedule version + assigned Teacher/Student + qualifying window |
| `dzn_livekit_attendance_events` | Append-only normalized provider event receipts (keyed digests, provider instant, receipt instant, outcome) |
| `dzn_livekit_attendance_connections` | One row per exact connection/session; joins the trusted leave; stores durable ingest state and evidence id |

The event table is append-only. The connection table is derived correlation state, not attendance
truth. No raw webhook body, token, launch ticket, API secret, contact value or provider payload is
stored. Only keyed digests of event ids, connection SIDs, identities and interval facts are kept.

### Exact connection binding

A leave closes only the connection whose **room + participant identity + LiveKit participant SID**
matches the join. A stale leave from an earlier connection can never close a newer connection.
Reconnects are separate connection rows. Duplicate tabs and overlapping devices are separate rows
that the locked rule unions, so they cannot double-count.

Duplicate event ids are idempotent at the database boundary. A changed join/leave for the same
connection is retained as an append-only conflict event; the first trusted interval is never
overwritten. The changed interval is also submitted to Phase P with the same provider event key and a
new command key, which leaves a durable canonical conflict receipt and opens review.

### Provider timestamps and receipt time

Participation facts use the provider event instant (`participant.joinedAt` for joins, the event
`createdAt` for leaves). Receipt time is stored separately. A missing provider leave instant is
**never** replaced with the current time, scheduled end, grace end or room termination. It remains
open and non-qualifying. A delayed join can still pair with an earlier orphan leave through the same
exact SID; an orphan leave with no join never creates an interval.

### Server-owned identity mapping

`LiveKitClassroomSessionService` records both canonical participant mappings during the authenticated
token-issuance path, using the exact server-issued LiveKit identity (never a display name, email,
browser claim or client verification flag). Recording runs under a narrow, explicitly configured
integration actor:

- `dzn_ingest_canonical_attendance_evidence`
- `dzn_manage_canonical_attendance_identity`

The actor is refused if it also holds `manage_options` or `dzn_manage_provider_integrations`. It is
not an administrator and cannot decide attendance, review, delivery or finance.

If a mapping is missing at ingest time, the connection is held as `mapping_pending`; the adapter
does not guess an identity and does not create immutable unverified evidence. The reconciliation
worker retries once the mapping exists. Ambiguous, revoked or mismatched mappings are still
submitted as evidence and remain review-only.

### Settlement safety and the two-actor boundary

The webhook/ingest actor cannot complete a Lesson. The existing staged settlement runs only from the
scheduled reconciliation worker under a separate settlement actor that holds the two capabilities
the canonical delivery/completion services already require. Both the delivery and completion
services revalidate the durable Phase-P case, exact schedule version, policy and lifecycle; the
settlement actor cannot choose an outcome or adjudicate attendance.

Closed intervals are only flushed to Phase P when the room has no live `open` connection. This
prevents an automatic completion from revoking a classroom that is still in use. If the occurrence
has not ended yet, Phase P records the evidence and leaves the case in `settlement_pending`; the
reconciliation worker retries after the occurrence end. That retry path is exactly the existing Phase
P convergence path, so a partial settlement failure cannot duplicate delivered truth or completion.

Room termination (`room_finished`) marks remaining connections as `room_finished_open` and flushes
already-closed intervals. It never invents a leave. After a seven-day retention window, a still-open
connection is marked `stale_open` and submitted only as **open** evidence for review. The adapter
never converts that into qualifying attendance.

### Google Meet fallback

No LiveKit evidence creates no attendance case and no absence. The existing Google Meet fallback and
the interim presumed-completion policy remain untouched. A missing LiveKit room must never be read as
a no-show.

### Introductory bookings

The adapter binds only `canonical_term_lesson_v1` Lessons. Confirmed introductory bookings keep the
existing LiveKit classroom behaviour but are excluded from this canonical term-Lesson attendance
adapter.

## Configuration

| Option | Meaning |
| --- | --- |
| `dzn_livekit_attendance_capture` | Set to `enabled` to capture facts. Any other value (or absent) leaves the webhook on the pre-adapter lifecycle path. |
| `dzn_provider_evidence_system_user_id` | WordPress user id of the narrow webhook/ingest actor. It may record evidence and provider identity mappings only. |
| `dzn_attendance_settlement_system_user_id` | WordPress user id of the separate scheduled settlement actor. It holds only the capabilities the existing staged settlement coordinator requires and is never used by the webhook. |
| `dzn_livekit_attendance_schema_version` | Feature-local schema marker; written only after the three tables verify. |

The adapter is **disabled by default**. Installing the code does not capture attendance.

## Activation (prospective, separate from deployment)

Do not run these steps as part of a code review or merge. Activation is a separate operational change.

1. Deploy the reviewed code through the normal release process. Do **not** merge or deploy as part of
   this document's review.
2. Confirm `LiveKitAttendanceSchema::verify()` passes and the three correlation tables exist.
3. Create a dedicated WordPress user for the webhook/ingest actor. Grant only
   `dzn_ingest_canonical_attendance_evidence` and `dzn_manage_canonical_attendance_identity`. Do not
   grant `manage_options`, `dzn_manage_provider_integrations`, attendance review/adjudication,
   Lesson delivery or Lesson authority capabilities to this user.
4. Create a second dedicated WordPress user for the scheduled settlement actor. Grant only
   `dzn_ingest_canonical_attendance_evidence`, `dzn_manage_canonical_lesson_delivery` and
   `dzn_manage_canonical_lessons`. Do not grant `manage_options`,
   `dzn_manage_provider_integrations` or `dzn_manage_canonical_attendance_review`. This actor is used
   only by the five-minute reconciliation worker, never by the public webhook.
5. Set `dzn_provider_evidence_system_user_id` and `dzn_attendance_settlement_system_user_id`.
6. Set `dzn_livekit_attendance_capture` to `enabled`.
7. Record a **future** cutover instant through
   `CanonicalAttendanceIntakeService::recordCutoverPolicy()`. The instant must still be prospective;
   a backdated cutover is refused. Occurrences before the cutover are never admitted, and no
   historical attendance is backfilled.
8. Observe `dzn_livekit_attendance_*` actions, the Phase-P attendance review queue and Finance
   pending states. Do not expect automatic confirmation for occurrences that started before the
   cutover.

Activation order matters: configure both actors and verify mappings **before** enabling capture.
Enabling capture without the ingest actor leaves closed evidence in `unconfigured`/`mapping_pending`
state and never guesses identity. Enabling capture without the settlement actor leaves a validated
case in `pending_settlement`; the worker retries once the settlement actor is configured, and the
webhook itself never gains the authority to complete a Lesson.

## Rollback (separate from deployment rollback)

1. Set `dzn_livekit_attendance_capture` to `disabled` (or remove the option). New webhook deliveries
   immediately return to the pre-adapter lifecycle-only path.
2. Optionally clear the scheduled hook
   `wp_clear_scheduled_hook('dzn_livekit_attendance_reconcile')`.
3. Leave the correlation and Phase-P evidence tables in place. They are durable evidence; do not
   delete them to "undo" attendance.
4. Do not delete or rewrite the immutable cutover policy. To stop future automatic attendance,
   disabling capture is sufficient.
5. Already-settled Phase-O delivered truth and Lesson completion are **not** reversed by disabling
   capture. Correct an incorrect historical outcome only through the existing append-only
   adjudication/correction authority.
6. The existing Google Meet fallback, interim presumed completion, Finance and notification paths are
   unaffected.

## Verification

Run:

```sh
php platform/tests/livekit-attendance-threshold-unit.php
php platform/tests/livekit-attendance-contract.php
DZN_RUNTIME_STATE_DIR=/tmp/dzn-livekit-attendance-runtime \
DZN_COMPOSE_PROJECT=dzn-livekit-attendance-runtime \
DZN_WP_PORT=18091 \
platform/runtime/bin/run-attendance-runtime.sh
```

The runtime suite covers: exact 1200/1199, reconnect union, duplicate tabs, pre-start/post-grace
clipping, open intervals, invalid signatures, unknown participants, identity ambiguity/mismatch,
cross-schedule reschedule, cancellation, presumed completion correction, late evidence after Term
closure, exact replay, changed-payload conflicts, room cleanup, Meet fallback and no duplicate
outcome/completion/finance capture.

## Known gaps / non-goals

- A mid-class reschedule supersedes the old schedule version. Old-room evidence is retained but
  refused as `schedule_version_conflict`; it is not reinterpreted against the new version.
- A leave that arrives after the seven-day stale-open retention can still be durably recorded, but the
  already-submitted open evidence is immutable; the case remains review-only rather than
  automatically settling.
- The adapter does not backfill historical LiveKit or Google Meet attendance.
- The adapter does not create recordings, egress, transcripts, Analytics, PixelBox storage or any
  provider-side data.
- A true multi-process concurrency storm is covered by the existing Phase-P command/evidence unique
  keys and concurrency suites; this adapter adds database uniqueness for event and connection
  identity and retries races deterministically.
