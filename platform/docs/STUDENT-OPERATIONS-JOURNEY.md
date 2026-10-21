# Student operations and enrolment journey

This note records the operator-facing path and its authority boundaries. The Academy queue is a read projection; commands remain on their existing authority screens.

## Canonical stages

1. A submitted Booking Request may be opened as an advisory Coordination Case. Coordination and candidate/availability-assent records do not accept a service or assign a Teacher.
2. Student identity is resolved from human verification evidence. Acceptance capacity and the active Student or guardian authority are separately maintained; contact details are not an identity match.
3. Final acceptance validates the current Proposal Version, provisional evidence, resolved Student, current capacity classification, and adult-self or guardian authority. It creates the Accepted Service Arrangement.
4. `EnrolmentConversionService` converts that accepted arrangement into the canonical Enrolment. Generic Enrolment creation is not a substitute.
5. `TeacherAssignmentService::assignInitial` derives its initial Teacher from the retained accepted arrangement. Initial assignment is not a free Teacher selection.
6. Teacher replacement records a successor to the current assignment and requires the configured agreement evidence. It refuses replacement while an active future Lesson schedule exists.
7. Canonical Term and Lesson services own their respective stages after Enrolment and assignment.

## Operator journey changes

The Academy queue searches by booking reference, Student/contact name, contact email, instrument, and course; it returns 25 rows per page and excludes privacy-erased requests. Queue links carry the request, arrangement, Enrolment, assignment, or Term context to the canonical screen. The journey detail lists completed/pending stages, current blocker, and next capability-gated action.

Identity review offers bounded Student search by name, email, or reference. Final acceptance carries the already resolved Student and offers searchable current principal choices for adult capacity or current guardian grants for minor capacity. The accepting authority service remains the final validator.

Core readiness retains its action-specific capability and nonce checks. Contextual links prefill stable record references. Replacement teacher search is bounded to 20 course-eligible, active, ready, accepting teachers and shows current assignment count. No admin screen writes Teacher Assignment rows directly.

## Product authority question

The current replacement command validates that the selected Teacher is active, checks agreement evidence, and rejects an active future Lesson schedule. It does not itself enforce current course eligibility, accepting state, or load. The replacement search presents those current read-model facts to help operators choose, but this does not make them command invariants. Product/domain owners should decide whether replacement eligibility must be enforced by `TeacherAssignmentService`; this journey does not silently change that authority.

If the bounded eligible-teacher search has no results, review course eligibility, onboarding readiness, and accepting state. A booking coverage exception should be handled through the existing coverage and availability authorities. Missing identity, acceptance, arrangement, Enrolment, or assignment is surfaced as a predecessor-stage blocker; operators must resolve it through its owning authority.

## Post-introductory continuation automation

A free introductory booking that the Teacher confirmed is the single authority for continuing with that same Teacher. The Teacher's acceptance is not reopened after the introductory lesson: there is no second acceptance, recommendation step, or post-intro Teacher decision.

### Lifecycle

1. After the scheduled introductory window has ended, the Teacher records the real outcome in the Teacher Portal: delivered, Student no-show, Teacher cancelled, or Student cancelled. Only `delivered` starts continuation. A no-show or cancellation becomes an admin exception and never sends an invitation.
2. Preparation resolves the instrument's single active standard Course, its single active commercial product, and the contact region's active price. Ambiguity or absence is an admin exception; no Course, product, price, or region is invented.
3. Preparation imports the confirmed introductory interval as a completed introductory Lesson, records the first regular weekly slot from the confirmed recurrence, opens the existing Coordination Case, records the existing Teacher-assent authority, and issues the existing initial Proposal. It then enqueues a durable `post_intro_invitation` notification.
4. The Student Portal shows **Complete Payment / Confirm Enrolment**. The action is owned by the Student principal. Canonical provisional/final acceptance, Enrolment conversion, the continuation decision and hold, the recurring pattern, and the commercial offer all run through their existing authorities. Checkout is the existing Student-owned Stripe Checkout seam.
5. Verified settlement enqueues exactly one durable activation job inside the settlement transaction. Activation converges the canonical Enrolment, capacity handoff, Term, Teacher Assignment, future Lessons, committed schedules, both portals, notification, and Google Calendar/Meet projection.
6. Every command uses a deterministic idempotency key derived from the preparation UID. A retry after a partial provider or notification failure re-enters the same authority and converges; it never creates a second Enrolment, Term, Assignment, Lesson, schedule, or settlement.

### States

| State | Meaning | Student action |
| --- | --- | --- |
| `preparing` | Preparation is running. | Wait. |
| `prepared` | Offer and payment action are ready. | Complete payment. |
| `checkout_pending` | A Checkout attempt exists or payment is being prepared. | Resume payment. |
| `payment_received` | Verified payment is waiting for activation. | Wait; no second payment. |
| `activated` | Enrolment, Term, schedule and future Lessons are active. | Use the normal class schedule. |
| `expired` | The first-slot hold expired before payment. | Contact support for a new time. |
| `exception` | A genuine identity, commercial, scheduling, assignment, or provider exception needs review. | Contact support. |

### Admin recovery

`Post-intro automation` lists only preparation exceptions, failed/retryable activation jobs, and introductory outcomes that the Teacher has not recorded. The capability is `dzn_manage_post_intro_automation`.

- **Retry preparation** re-runs the same recorded outcome. It never replaces the outcome or fabricates a Course/product/price.
- **Retry activation** requeues the same purchase job with a fresh retry budget. Core canonical truth is re-proved before any provider write.
- **Record outcome** is the administrative fallback for a Teacher who cannot use the portal. It records the real delivery result; it does not silently mark a non-delivered lesson as delivered.
- A missing Google connection, expired credential, transient Google failure, or mail hand-off failure is retryable. A `provider_*` job failure leaves the canonical enrolment, Term, Assignment and Lessons intact and retries only the projection.
- An explicit Teacher block, `teacher_unavailable`, `teacher_slot_conflict`, `continuation_reservation_expired`, or a durable commercial/assignment exception is not auto-overridden. Repair the owning authority, then retry the job.

### Invariants

- The introductory booking's Teacher acceptance is the ongoing Teacher authority; the automation principal is least-privilege and is not an administrator.
- The confirmed booking is the ongoing Teacher authority, but the committed weekly slot must still be covered by the Teacher's published availability. Missing or blocked availability becomes `teacher_unavailable` for admin resolution; it is never auto-overridden.
- Only introductory lessons completed on or after `2026-10-21 00:00:00` enter the automation. Historical confirmations are not surfaced as new payment invitations.
- The introductory Lesson records the booked introductory Course in `lessons.intro_course_id` while `lessons.course_id` carries the continuous standard Course used by the canonical continuation lineage.
- A Student who already has an active recurring pattern for the same Course but a different Teacher fails closed as `commercial_pattern_teacher_conflict`; the automation never reuses another Teacher's slot.
- Adult capacity is based on the booking intake's explicit adult self-attestation, with the Student Portal self-attestation as the legacy fallback. The canonical capacity classification records `self_attestation` so the policy decision remains auditable.
- The automation never fabricates a Meet URL. A Calendar projection is pending until a provider result acknowledges it; the Student cannot join from a manufactured link.
- Privacy-erased requests, cross-Student actions, unresolved identity, minor/guardian gaps, ambiguous Courses/products/prices, and expired holds fail closed.
- The existing accepted-arrangement, commercial obligation, Stripe settlement, Term/Lesson, and Google integration authorities remain the only writers of their truth.
