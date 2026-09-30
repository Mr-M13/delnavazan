# Phase 2A.2-E — Provisional Acceptance Evidence

`accepted_pending_conditions` is immutable, privacy-minimised evidence that a defined Proposal Family, Teacher-specific Option and exact immutable Proposal Version was affirmatively accepted while accepting-subject authority remains `authority_unresolved`.

It is not final acceptance and establishes no Student identity, guardian/delegate authority, Accepted Service Arrangement, conversion readiness, Enrolment, Teacher Assignment, Lesson, reservation, payment, notification, calendar or Amelia authority.

The service locks Booking Request, Coordination Case, Proposal Family, Proposal Option and the exact current Proposal Version in that order. It rejects privacy-erased Booking Requests and stale versions, never consumes current Teacher Availability Assent, and preserves events recorded before later proposal replacement as historical evidence.

Events are append-only. Their idempotency identity is acceptance-domain-specific and stores only HMAC/digest forms of an operator-supplied key and canonical provisional command. Evidence channels are closed; evidence references are opaque server-generated values. No Booking Request contact PII or raw idempotency keys are copied into the event.
