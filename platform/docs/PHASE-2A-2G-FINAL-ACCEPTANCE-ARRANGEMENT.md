# Phase 2A.2-G — Final Acceptance and Accepted Service Arrangement Foundation

Schema 13 adds the first final-acceptance authority boundary. An authorised administrator may create one immutable Accepted Service Arrangement for one exact Proposal Family only after the command supplies a fresh literal `affirmed` confirmation and the service revalidates all current authority under database locks.

The locked lineage is Booking Request, Coordination Case, Proposal Family, every sibling Option and current Version, the exact provisional `accepted_pending_conditions / authority_unresolved` event, existing final evidence, Student, current identity resolution, current capacity classification, WordPress principal, principal history, and guardian authority. Adult Students require the active self-principal. Minor Students require an active `guardian_representative / service_acceptance` grant. Adult delegation is not supported.

`accepted_service_arrangements` freezes only canonical identifiers, Proposal material facts and fingerprints, identity/capacity evidence, authority provenance, opaque confirmation evidence, and HMAC idempotency digests. It contains no Booking Request contact or personal fields. `proposal_option_outcome_events` records exactly one `accepted` sibling and closes every other sibling as `closed_competing`; both tables are append-only and have no `updated_at` column. The earlier provisional event remains unchanged.

After a Family is accepted, Proposal issuance for that Family is rejected. Privacy erasure that wins the Booking Request lock first makes final acceptance fail; privacy erasure after acceptance removes Booking Request PII without deleting or mutating the PII-free arrangement, whose idempotent replay remains available.

This phase does not create an Enrolment, assignment, Lesson, booking, payment, notification, calendar event, or Amelia authority. It does not deploy or modify production.

Migration: `013_final_acceptance_arrangement_foundation`

Capability marker: `2a2g`

Capability: `dzn_finalize_service_arrangements`

Build: `phase2a2g-final-acceptance-arrangement-20260910.1`
