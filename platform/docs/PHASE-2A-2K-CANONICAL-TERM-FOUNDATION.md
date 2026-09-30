# Phase 2A.2-K — Canonical Term Foundation

Schema 17 / migration `017_canonical_term_foundation` is an additive storage and protected-read foundation. It does not create ordinary canonical Term authority.

## Identity and authority

- Record models are `legacy_phase1` and `canonical_enrolment_term_v1`.
- Canonical identity is canonical Enrolment plus immutable server-owned sequence.
- Canonical lifecycle progression is `authorised → current → closed`, with `cancelled` as the terminal alternative.
- `authorised` and `current` occupy the one applicable slot; future-Term staging is unavailable.
- An applicable Term requires an applicable parent Enrolment (`authorised`, `current`, or `paused` with slot `1`). A valid closed Enrolment with an `authorised` or `current` Term is `data_integrity_conflict`; terminal Term history remains valid.
- A canonical Term contains no Teacher identity. Teacher Assignment is the only current-Teacher authority.
- Allocation origin is 12 standard Lessons and 2 eligible replacements. These are not consumption counters or Lesson authority.

## Compatibility and evidence

Migration 017 classifies existing Terms as legacy through a non-null default and does not rewrite their status, payment, allocation, dates, sequence or archive state. Legacy Term creation and Lesson compatibility remain constrained to legacy Enrolments and Terms. Generic insertion and archive/restore cannot create or mutate canonical authority.

Canonical lifecycle history is append-only, actor-attributed and digest-only. Capability-protected assessment fails closed for malformed record models, mixed legacy/canonical rows, wrong Enrolment models, sequence gaps, invalid applicability, invalid allocation/payment sentinels, and missing or invalid history. Protected reads omit Teacher and payment authority.

## Explicit exclusions

Phase K adds no canonical Term creator, lifecycle mutation command, command idempotency, Lesson, remaining-session calculation, scheduling, Teacher Assignment mutation, availability/capacity, payment/Stripe, renewal, notification, calendar/Meet, Amelia, attendance, finance, portal, Hamnavaz, CRM, Theme, NIU or production authority.
