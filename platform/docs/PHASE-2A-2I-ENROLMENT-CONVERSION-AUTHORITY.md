# Phase 2A.2-I — Enrolment Conversion Authority

> **Historical phase record:** Phase 2A.2-I was subsequently independently reviewed, merged and closed. This document preserves its at-the-time authority contract; current status is recorded in `DELNAVAZAN-CORE-CONTINUITY.md`.

Schema 15 / migration `015_enrolment_conversion_authority` adds a capability-protected informational readiness boundary and a separate explicit, atomic Accepted Service Arrangement conversion command.

## Readiness and authority

`EnrolmentConversionReadinessService` writes nothing and returns only `ready`, `already_converted`, `legacy_review_required`, `canonical_conflict`, `source_integrity_conflict`, `student_not_current`, `course_not_current`, `source_not_final`, or `data_integrity_conflict`. A readiness result is not bearer authority. `EnrolmentConversionService` independently reloads and revalidates the exact immutable source graph and complete Student + Course Enrolment aggregate under locks.

The source graph includes the exact frozen Booking Request identity-resolution event and Student capacity-classification record referenced by the arrangement. Their aggregate identity, sequence, Student, prospective-subject relationship, classification and evidence structure must match the immutable final snapshot. Historical evidence need not remain current; principal/guardian authority is not re-authorised and privacy erasure remains non-blocking.

Both boundaries require `dzn_convert_service_arrangements_to_enrolments`. No public REST route or administrator conversion UI is added.

## Conversion result

A successful conversion atomically writes exactly:

- one `canonical_student_course_v1` Enrolment in `authorised`, with the exact arrangement Student, resolved Course, frozen Teacher context, and Accepted Service Arrangement provenance;
- one sequence-1 lifecycle event from `NULL` to `authorised`, with reason `accepted_service_arrangement_conversion` and channel `platform_conversion_command`;
- one immutable, digest-only conversion command record.

The frozen Teacher is historical service context only. It is not Teacher Assignment, capacity, scheduling, Term, Lesson, payment, notification, calendar or Amelia authority.

When clean closed history exists, the predecessor is selected by the most recent unambiguous authoritative closure event and the new row uses `return_after_closure`. It is never selected by maximum Enrolment ID. Ambiguous or malformed history fails as `data_integrity_conflict`.

Every canonical lifecycle history is validated from contiguous sequence 1: null initial state, valid state chain, final-state agreement, lineage/provenance consistency and ordered UTC occurrence/recording timestamps. Replay and already-converted responses centrally revalidate the command, source, canonical Enrolment, frozen Teacher, lifecycle evidence and predecessor relationship; contaminated evidence never returns a successful identity.

## Persistence, idempotency and locking

`dzn_enrolment_identity_roots` provides only a concrete unique `(student_id, course_id)` serialization row. `dzn_enrolment_conversion_commands` stores unique command-key digest, canonical payload digest, source arrangement and resulting Enrolment; raw idempotency keys are never stored.

The lock direction is Booking Request → Coordination Case → Proposal Family → Proposal Options → exact Proposal Version → provisional acceptance → accepted outcome → Accepted Service Arrangement → Student+Course identity root → Student → Course → frozen Teacher → discovered Enrolments and lifecycle events. Database uniqueness is final arbitration.

Same key and payload replays the exact result. The same key with another source throws `IdempotencyConflictException`. A different key for a clean already-converted source returns that Enrolment without another event or success record. A different source competing for the same Student + Course loses with `canonical_conflict`.

## Privacy and exclusions

A retained final PII-free arrangement remains convertible after Booking Request privacy erasure. The request row is still the first serialization point; conversion copies no contact PII and cannot reverse erasure.

Phase I creates no Teacher Assignment, Term, Lesson, Attendance, scheduling, capacity reservation, payment, refund, notification, outbox, calendar, Amelia, Hamnavaz, CRM or Theme authority. It does not merge or deploy itself.
