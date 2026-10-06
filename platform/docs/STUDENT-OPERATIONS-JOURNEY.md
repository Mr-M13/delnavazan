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
