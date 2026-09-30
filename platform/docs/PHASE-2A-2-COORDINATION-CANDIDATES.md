# Phase 2A.2-B — Coordination Case + Candidate Teacher Foundation

This increment provides an internal operational context for a submitted,
unresolved, non-erased Booking Request. A Coordination Case is not identity,
assignment, availability assent, reservation, proposal, acceptance, conversion,
Enrolment, Term, Lesson, payment, notification, or provider authority.

## Implemented case states

The V1 subset is deliberately limited to `open`, `candidate_search`,
`manual_search`, `waiting_for_availability`, `withdrawn`, `declined`,
`unable_to_arrange`, and `abandoned`. The last four are terminal. `waiting_for_availability`
means only that staff are waiting for further operational information; it is not
Teacher Availability Assent and does not represent capacity or a commitment.

At the Phase 2A.2-B stopping point, later vocabulary such as proposal,
acceptance, conversion, and Teacher-assent states was intentionally absent
from this schema and service. Phase 2A.2-C adds its separate, bounded assent
records without changing the advisory meaning of Cases or Candidates.

## Candidate Teacher Considerations

Candidates are one advisory record per Case/Teacher pair. Allowed provenance is
`advisory_match`, `manual_search`, `student_or_guardian_preference`,
`teacher_referral`, `operational_referral`, or `approved_exception`. The
implemented advisory statuses are `unreviewed`, `under_discussion`,
`not_available`, `not_suitable`, `withdrawn_from_consideration`, `superseded`,
and `closed`.

There is no `availability_requested` or `available_in_principle` status in this
increment, so a Candidate cannot be mistaken for an Availability Assent,
selection, assignment, reservation, or offer. Candidate creation requires an
active, unarchived Core Teacher but does not make that Teacher eligible,
contacted, reserved, or selected.

## Privacy, audit, and concurrency

The tables contain only authoritative IDs, controlled state/source/reason codes,
timestamps, versions, and actor provenance. They contain no Booking Request
contact fields, contact digests, communications, candidate snapshots, ranking,
or free-text narrative. Consequential changes add privacy-safe audit events.

Case creation locks its source Booking Request and has one canonical Case per
request. Case and candidate state transitions require the expected current
version and reject stale changes. Privacy erasure shares the Booking Request
lock: it closes the Case as `abandoned`, closes all non-terminal Candidates, and
records only controlled audit facts before existing erasure removes request PII.
