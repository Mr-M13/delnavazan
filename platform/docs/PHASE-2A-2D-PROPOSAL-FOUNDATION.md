# Phase 2A.2-D — Proposal Foundation

> **Historical phase record:** Phase 2A.2-D was subsequently validated and merged. This document preserves its at-the-time boundary; current status and downstream authority are recorded in `DELNAVAZAN-CORE-CONTINUITY.md`.

## Authority boundary

A Proposal Family groups alternatives and is not an acceptance target. A Proposal Option is one independently acceptable Teacher-specific lineage. A Proposal Version belongs to exactly one Option and is immutable after issuance. Teacher A and Teacher B therefore have separate Options; A2 supersedes A1 only and cannot supersede B1. Any future acceptance must identify the exact Family UID, Option UID, and Version number.

Issuance creates offer authority only. It creates no acceptance, Accepted Service Arrangement, Student, Enrolment, Teacher Assignment, Lesson, capacity reservation, payment, notification, calendar, or Amelia authority. There is no public Proposal endpoint.

## Schema 10 / migration 010

- `dzn_proposal_families` is canonical by both Booking Request and Coordination Case.
- `dzn_proposal_options` is unique by Family/Candidate and Family/Teacher. Its guarded `current_version_id` pointer is the only mutable lineage state.
- `dzn_proposal_versions` stores an append-only historical offer. Unique Option/version, Option/fingerprint, superseded-Version, and command-key constraints prevent forks and duplicates.
- Versions deliberately have no `updated_at`, `updated_by`, state, archive, or delete lifecycle.
- ULID-style UIDs and `PRF` / `PRO` / `PRV` reference codes follow existing identity conventions.

## Assent authority and transaction order

Both initial issuance and replacement call:

`TeacherAvailabilityAssentService::consumeCurrentForFutureProposalIssuance(int $candidateId, string $fingerprint, callable $consume): mixed`

The Proposal callback extends that existing transaction and never starts a nested transaction. The effective lock order is:

1. Booking Request
2. Coordination Case
3. Candidate Teacher
4. Teacher identity/readiness/accepting state
5. Assent snapshot
6. current Assent
7. Course
8. Teaching Eligibility
9. Proposal Family
10. Proposal Option
11. Proposal Version / idempotency rows

Replacement performs only a non-locking Option read before entry, solely to locate the Candidate. All Proposal locks happen after the authoritative Assent path.

## Idempotency and concurrency

Raw client idempotency keys are validated and HMAC-digested before persistence. The canonical command payload is independently SHA-256 digested.

- Same key and same canonical command returns the original Version.
- Same key with another command throws `IdempotencyConflictException`.
- A different key cannot create an equivalent immutable Version.
- Family and Option creation tolerate unique-race retries.
- Version number, supersession, material fingerprint, and command identity are database-unique.
- Replacement requires the caller's expected current Version number.
- Option pointer advancement checks both its row version and old pointer in one transaction.
- Overlapping MySQL-session tests are provided but are not claimed passed until run.

## Frozen facts and privacy

A Version freezes controlled identities, source Assent identity/version, the canonical arrangement facts, limited evidence provenance, issuance reason, and issuing actor/time. This makes historical offer authority independently intelligible after later Assent withdrawal, invalidation, or Booking Request privacy erasure.

It does not copy name, email or digest, mobile/WhatsApp or digest, address, city, contact narrative, communication preferences, arbitrary notes/messages, or raw contact snapshots. Existing privacy erasure invalidates current Assent authority first; later issuance therefore fails at the shared Assent gate while legitimate immutable Proposal history is not rewritten.

## Protected internal surface

Administrators receive the dedicated `dzn_issue_booking_request_proposals` capability through the existing capability repair mechanism. The existing protected Booking Request Coordination screen exposes initial/replacement issuance forms only to this capability. Nonces and the coordination/view capability remain additional gates. There is no acceptance action and no external communication.

## Runtime validation

The source contract can run with:

`php tests/phase-2a2d-contract.php`

On a disposable local/development WordPress + MySQL fixture, set `DZN_PHASE_2A2D_RUNTIME_TEST=isolated` and prepare the documented `dzn_phase_2a2d_fixture` option before running:

- `tests/phase-2a2d-migration-runtime.php`
- `tests/phase-2a2d-isolated-runtime.php`
- `tests/phase-2a2d-concurrency-setup.php`
- two independent `tests/phase-2a2d-concurrency-worker.php` processes released by `/gate/phase2a2d.release`
- `tests/phase-2a2d-concurrency-verify.php`

Run the race twice: `DZN_PHASE_2A2D_RACE=revision` with two `issue` workers, and `DZN_PHASE_2A2D_RACE=invalidation` with one `issue` and one `invalidate` worker. The fixture must supply current Assents for A1, A2 and B1, rejected stale/withdrawn/expired/ineligible cases, and separate `race_revision` / `race_invalidation` records as described in the test source.
