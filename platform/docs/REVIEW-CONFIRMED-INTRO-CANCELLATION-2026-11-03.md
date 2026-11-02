# Review — confirmed introductory booking cancellation authority

Status: **separate review candidate, not merged and not deployed.** No production record was cancelled, deleted or modified.

## Scope

The authority cancels exactly one **confirmed** introductory booking and records why. It does not
edit or delete the retained booking, the Student, the Teacher, financial history or attendance
evidence. The later duplicate is marked cancelled and its provider projection is retracted.

## Permission model

The existing authority already proves ownership for three principals:

- Administrator: `dzn_manage_booking_request_coordination`.
- Confirming Teacher: an active Teacher portal principal whose Teacher id equals the confirmation.
- Student/guardian: an active Student portal principal whose Student id equals the request.

There is no cross-principal override. The new dashboard controls use the administrator path; the
existing Student/Teacher portal controls keep their ownership checks. No new broad capability is
introduced.

## State and evidence

Migration `065_confirmed_intro_cancellation` adds three additive tables:

1. `booking_request_cancellations` — immutable evidence: confirmation, request, delivery, Teacher,
   Student, reason, evidence digest, previous states, cancellation instant, actor and command digest.
   Unique per confirmation and command.
2. `booking_request_cancellation_commands` — digest-only command idempotency and replay result.
3. `booking_request_provider_retraction_jobs` — durable provider outbox with state, bounded attempts,
   lease, next-attempt, safe error code and completion evidence.

The schema version remains the repository’s append-only `57` convention; migration `065` is added to
the completed ledger and the schema verifier.

## Transaction and lock behaviour

- One `READ COMMITTED` transaction.
- Confirmation row is locked first, then request/delivery, then the Student scheduling root.
- Replay is command-digest based. A second key for an already-cancelled confirmation converges on
  the existing cancellation rather than appending another.
- Version-safe confirmation transition: expected version + `state='confirmed'`.
- Request, delivery, coordination case, notification and retraction job are committed together.
- Any failure rolls back every state change; no partial cancellation is visible.
- The provider projection secret is **not** retired at cancellation time. It remains available to the
  retraction worker and is retired only after a successful retraction.

## Provider retraction

`BookingCancellationRetractionWorker` uses the existing encrypted credential and provider-reference
vaults:

- leases a due job and marks it `sending`;
- refreshes an expired Google credential through the existing refresh authority;
- opens the sealed event reference;
- calls the new `GoogleCalendarProjectionTransport::retract()` DELETE path;
- treats `404`/`410` as already retracted, so retries converge;
- retires the sealed projection only after success;
- retries with exponential backoff up to six attempts, then records a terminal, safely coded failure;
- leaves the committed cancellation intact if the provider is unavailable.

## Notification and coordination

- The existing booking notification queue receives one `cancelled` notice per confirmation/delivery.
- The notification worker has a dedicated `booking.cancelled.student` event in the catalogue with
  Persian and English copy and a website inbox entry.
- Open coordination cases are closed with `state_reason_code='booking_cancelled'`; candidates,
  assents, proposals and prior history remain untouched.
- The workspace read model reports the confirmation as `Cancelled` and moves the case to a closed
  stage with no further cancellation action.

## Dashboard control

Both the Booking Coordination case screen and the redesigned Coordination Command Centre now render
a capability-gated cancellation panel for a confirmed introductory booking that has no recorded outcome. It requires a controlled reason and an
operator evidence reference, shows a destructive confirmation, and submits through the existing
`EnrolmentWorkspaceController` admin-post/nonce boundary with a generated idempotency key.

## Test evidence

- `confirmed-intro-cancellation-contract.php`: source/security/idempotency/migration/UI contract.
- `confirmed-intro-cancellation-runtime.php` (fresh disposable WordPress/MariaDB): **PASS**
  - first cancellation and same-command replay;
  - a later command converges on the existing cancellation;
  - exactly one cancellation, command row, retraction job and student notice;
  - confirmation/delivery cancelled, request resolution `cancelled_duplicate`, coordination case closed;
  - provider reference remains active until retraction;
  - first provider attempt fails safely and schedules a retry;
  - second attempt retracts and retires the sealed projection;
  - retraction job becomes `retracted`.
- Full pure source/contract bundle: **PASS**.
- Fresh install and schema verification: **PASS**.

## Existing production duplicates

The two existing records remain untouched. The earlier analysis still stands:

- retain `REQ-01M6E0V3H3FSPDW0SR4X2W7W6E` / confirmation 6 (earlier, notification handed off,
  duplicate flags point to it);
- cancel `REQ-01M6E6APWX3QGD7ZFK6HXK133V` / confirmation 7 only after this authority is reviewed,
  merged and deployed; its notifications failed and it has the open duplicate-review coordination case.

No production cancellation or backfill was performed.
