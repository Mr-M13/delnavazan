# Booking Coordination command centre — operational redesign

**Date:** 2026-11-03
**Route:** `Bookings → Coordination` in the updated admin navigation, served by the protected `dzn-booking-request-coordination` route inside the consolidated **Enrolment** area.
**Status:** implemented for review; **not merged, not deployed, and no production booking record was modified.**
**Evidence:** disposable WordPress + MariaDB acceptance runtime, synthetic `@example.invalid` identities only.

## 1. Executive summary

Booking Coordination is now a single-page operational command centre rather than a collection of raw technical forms. An administrator can open one case and review, coordinate, correct and finalise supported work in place:

- human-readable Student, request, offer, schedule, Teacher, arrangement and Enrolment context;
- contextual blockers with what is missing, why it matters, the evidence required, the supported resolution and what becomes possible;
- a permission-aware **Recommended next action** plus other valid alternatives;
- in-place Student identity and capacity/authority review using the canonical services;
- advisory Teacher candidates, real eligibility/availability/workload/collision review, and contextual eligibility/accepting-state updates;
- Teacher agreement, Proposal issuance/revision, provisional response, final acceptance, Enrolment conversion, initial Teacher assignment and Student account invitation;
- payment and notification evidence with only the supported retry/settlement actions;
- activity/audit history and a collapsible advanced section for technical references.

The redesign does not create a parallel booking workflow. Routine bookings that can be matched and accepted normally remain in the standard booking queue. Coordination is for exceptional intervention and recovery.

## 2. Audit method

The following were inspected before implementation:

- `CoordinationCaseController`, `CoordinationCaseService`, `CoordinationCaseRepository`;
- `CoordinationWorkspaceReadService` (new read projection) and the existing `EnrolmentWorkspaceReadService`;
- `TeacherAvailabilityAssentService` / repository, `ProposalService`, `ProposalAcceptanceService`, `FinalAcceptanceService`, `AcceptedServiceArrangementRepository`;
- `StudentIdentityResolutionService`, `StudentAcceptanceAuthorityService`, `StudentAcceptanceAuthorityReadService`, `StudentIdentityAuthorityController`;
- `TeachingEligibilityService`, `TeacherAcceptingStateService`, `TeacherAvailabilityService`, `CanonicalTeacherOccupancyReadService`;
- `EnrolmentConversionService`, `EnrolmentConversionReadinessService`, `TeacherAssignmentService`, `StudentAccountClaimNotificationService`;
- `NotificationDeliveryStatusReadService`, `NotificationManualDeliveryService`; `IntroPaymentService`;
- `BookingRequestSubmissionService`, `BookingRequestValidationService`, `AdministratorBookingMatchService`, `AdministratorIntroBookingService`, `BookingRequestMatchAssessmentService`;
- `EnrolmentWorkspaceView`, `AcademyOperationsController`, `AdminShellController`, `Menu`, `admin.css`;
- the Phase 2A.2-B through 2A.2-J contracts, runtime fixtures, privacy and concurrency tests, plus the consolidated admin navigation contracts.

## 3. Capability matrix

Legend for **Was in old Coordination**: *Yes* = exposed in the old route; *No* = only elsewhere, missing or read-only. **New status** is what the command centre now exposes.

| # | Action and business purpose | Authoritative service / endpoint | Permission and preconditions | Was in old Coordination | Available elsewhere | New status | Safe to embed? |
|---|---|---|---|---|---|---|---|
| 1 | Open an exceptional coordination case | `CoordinationCaseService::open` | `dzn_manage_booking_request_coordination`; request not erased; submitted/unresolved or closed/teacher-confirmed | Yes (raw request ID) | Enrolment workspace | **Yes** — human request selector | Yes |
| 2 | Change advisory case state | `CoordinationCaseService::transition` | Coordination capability; exact current version; allowed transition; closed state is terminal | Yes (`unsigned` state codes) | — | **Yes** — human labels, terminal states explained | Yes |
| 3 | Add advisory Teacher candidate | `CoordinationCaseService::addCandidate` | Coordination capability; active Teacher; case not closed; duplicate pair returns existing | Yes (raw Teacher ID) | Enrolment workspace | **Yes** — Teacher selector with readiness flags | Yes |
| 4 | Change candidate consideration status | `CoordinationCaseService::transitionCandidate` | Coordination capability; exact current candidate version | Yes (raw candidate ID/version) | — | **Yes** — human status choices | Yes |
| 5 | Record Teacher Availability Assent | `TeacherAvailabilityAssentService::recordAdministratorAttestation` | `dzn_manage_teacher_availability_assent`; viable candidate; active/ready/eligible Teacher; attributable evidence; current facts; future validity | Yes (very technical form) | Teacher workflows | **Yes** — local time controls, generated evidence reference | Yes |
| 6 | Withdraw or invalidate an agreement | `TeacherAvailabilityAssentService::withdraw` / `invalidate` | Assent capability; exact current version; recorded state | Yes | — | **Yes** — contextual reason controls | Yes |
| 7 | Issue an initial Proposal | `ProposalService::issueInitial` | `dzn_issue_booking_request_proposals`; current recorded agreement; frozen facts; idempotency | Yes (manual fingerprint/key/IDs) | — | **Yes** — fingerprint/key/IDs resolved server-side | Yes |
| 8 | Revise a Proposal Version | `ProposalService::issueReplacement` | Proposal capability; current Option/Version; current agreement; reason; idempotency | Yes (manual UIDs/version) | — | **Yes** — exact current Version resolved server-side | Yes |
| 9 | Record provisional acceptance evidence | `ProposalAcceptanceService::record` | `dzn_record_booking_request_provisional_acceptance`; exact current Family/Option/Version | Yes (manual UIDs/ref) | — | **Yes** — select current Proposal Version | Yes |
| 10 | Create final Accepted Service Arrangement | `FinalAcceptanceService::accept` | `dzn_finalize_service_arrangements` + coordination; resolved identity; adult/minor capacity; current adult self or guardian authority; exact provisional event; locks; idempotency | Yes (highly technical) | — | **Yes** — principal selector and local timestamp | Yes |
| 11 | Resolve booking identity to an existing Student | `StudentIdentityResolutionService::resolveExisting` | `dzn_resolve_booking_request_student_identity` + `dzn_view_student_acceptance_eligibility`; request unresolved | No — separate identity page | Student Identity authority | **Yes** | Yes |
| 12 | Create a reviewed Student and resolve | `StudentIdentityResolutionService::createAndResolve` | Same identity authority; reviewed display name; no derived identity from contact fields | No | Student Identity authority | **Yes** | Yes |
| 13 | Record reviewed identity outcome | `StudentIdentityResolutionService::recordOutcome` | Same identity authority; controlled outcome | No | Student Identity authority | **Yes** | Yes |
| 14 | Classify adult/minor/unknown capacity | `StudentAcceptanceAuthorityService::classify` | `dzn_manage_student_acceptance_authority`; active Student; reviewed evidence | No | Student Identity authority | **Yes** — the key in-place authority resolution | Yes |
| 15 | Establish Student principal | `StudentAcceptanceAuthorityService::establishPrincipal` | Authority capability; active Student; verified WordPress account; no conflicting current authority | No | Student Identity authority | **Yes** | Yes |
| 16 | Grant guardian representative authority | `StudentAcceptanceAuthorityService::grantGuardian` | Authority capability; active Student; verified account; evidence; optional end date | No | Student Identity authority | **Yes** | Yes |
| 17 | Read acceptance eligibility | `StudentAcceptanceAuthorityReadService::assess` | `dzn_view_student_acceptance_eligibility` | No | Student Identity authority | **Yes** — read-only context | Yes |
| 18 | Review Teacher/Course eligibility | `TeachingEligibilityService::effectivelyEligible` | Read in context; write requires `dzn_manage_teaching_eligibility` | No | Teaching eligibility screen | **Yes** — visible; active/inactive update in candidate context | Yes |
| 19 | Change Teacher accepting state | `TeacherAcceptingStateService::set` | `dzn_manage_teaching_eligibility`; active Teacher | No | Teaching eligibility screen | **Yes** — candidate context | Yes |
| 20 | Review Teacher availability and workload | `TeacherAvailabilityService::effective`, canonical occupancy/read models | Coordination capability for context; no mutation | Partial (ID only) | Teacher availability screen | **Yes** — coverage and conflict labels | Yes |
| 21 | Convert final arrangement to Enrolment | `EnrolmentConversionService::convert` | `dzn_convert_service_arrangements_to_enrolments`; final arrangement; conversion readiness; idempotency | No | Enrolment workspace / recovery screen | **Yes** | Yes |
| 22 | Assign the accepted Teacher | `TeacherAssignmentService::assignInitial` | `dzn_manage_teacher_assignments`; converted Enrolment; retained arrangement; idempotency | No | Enrolment / recovery screen | **Yes** | Yes |
| 23 | Issue Student account invitation | `StudentAccountClaimNotificationService::issue` | `dzn_issue_student_account_claims`; resolved Student; canonical notification route | No | Enrolment / recovery screen | **Yes** — delivery remains separate | Yes |
| 24 | Retry an exact failed/expired notification | `NotificationManualDeliveryService::retry` | `dzn_operate_notification_dispatch`; exact original notification; eligible window | No | Notifications workspace | **Yes** — only exact eligible rows | Yes |
| 25 | Record verified offline intro payment | `IntroPaymentService::recordManualSettlement` | `dzn_manage_payment_execution`; pending/failed obligation; receipt evidence; amount from stored obligation | No | Finance workspace | **Yes** — no invented amount or refund | Yes |
| 26 | Assess current matchability | `BookingRequestMatchAssessmentService::assess` | `dzn_prepare_booking_request_matches` + view | No | Booking Request page | **Yes** — advisory result only | Yes |
| 27 | Review duplicate flags | `BookingRequestIntakeRepository` | `dzn_review_booking_request_duplicates` | No | Booking Request page | **No** — read-only request context only | Needs a small application-service seam before embedding |
| 28 | Erase booking-request PII | `BookingRequestPrivacyService::erase` | `dzn_erase_booking_request_pii` | No | Booking Request page | **No** — intentionally not routine | Keep on protected intake route |
| 29 | Edit submitted preferred times | *(no administrator edit command)* | — | No | — | **No** — explicitly explained | Requires a new append-only correction authority |
| 30 | Cancel the booking request itself | *(no cancellation command)* | — | No | — | **No** — case closure is advisory only | Requires a new booking-request cancellation authority |
| 31 | Reopen a closed coordination case | *(no reopen transition)* | — | No | — | **No** — explicitly explained | New authority and policy required |
| 32 | Reschedule/cancel a confirmed introductory booking | *(no administrator command for confirmed intro)* | — | No | — | **No** — explained; not simulated | Needs canonical booking-reschedule/cancel authority |
| 33 | Retry Google Calendar/Meet projection | Existing projection worker / diagnostics | Provider integration authority | No | Integrations / diagnostics | **Read-only evidence** | Do not fake provider success |
| 34 | Refund/adjust a payment | Existing finance authorities | Separate finance capabilities | No | Finance workspace | **Read-only evidence** | Not in coordination scope |
| 35 | Create Term / issue or schedule a Lesson | Canonical Term/Lesson/Schedule authorities | Separate enrolment/class capabilities | No | Enrolled classes | **Read-only follow-through explanation** | Deliberately not duplicated here |
| 36 | Edit recurring Teacher availability rules | `TeacherAvailabilityService` admin methods | `dzn_manage_teacher_availability` | No | Teacher availability screen | **Read-only in this view** | Context drawer is the next smallest improvement |
| 37 | Revoke/supersede Student principal or guardian | `StudentAcceptanceAuthorityService` | Authority capability; exact link/grant version | No | Student Identity authority | **Not exposed** | Requires careful historical-authority UI |
| 38 | Replace an assigned Teacher on an Enrolment | `TeacherAssignmentService::replace` | Assignment capability; future-schedule blocker | No | Enrolled classes / recovery | **Not exposed** | Keep with class scheduling authority |

## 4. Before / after interface summary

| Area | Before | After |
|---|---|---|
| Landing | Raw `Booking Request ID` input, raw state codes, technical counts | Operational queue with Learner, request reference, offer, stage, progress, exceptions and contextual open action |
| Case overview | Database property dump; IDs and versions | Human Student/request/schedule/Teacher/arrangement/Enrolment summary |
| Blockers | One-line messages such as “Final acceptance is unavailable…” | Prioritised issue panel with what/why/evidence/action/unlocks and in-place links |
| Next action | None | Permission-aware recommended action plus non-linear alternatives |
| Identity | Separate identity page, external navigation | Review, resolve, create, classify capacity and establish authority directly in the case |
| Teacher | Raw candidate IDs and available-assent form | Candidate comparison, readiness, eligibility, availability, workload, real collisions, contextual eligibility/accepting edits |
| Agreement | Long technical evidence form | Simplified evidence form with local time controls and server-generated references |
| Proposal | Manual Family UID, Option UID, fingerprint and key | Current agreement and Option/Version resolved server-side; immutable history visible |
| Acceptance | Manual UIDs/version/event/key; one-line capacity error | Current Proposal selector, provisional evidence, in-place authority resolution and final arrangement form |
| Follow-through | None | Conversion readiness, conversion, assignment and account invitation in the same workspace |
| Finance/notifications | Not visible | Payment obligation and notification evidence; only supported retry/settlement actions |
| Audit | Raw identifiers mixed into forms | Chronological actor/action/time/outcome history; technical references in a collapsed advanced section |
| Responsive | Basic media query | Purpose-built responsive summary grid, action panels, workspace navigation and forms |

## 5. Workspace shape

1. **Header** — case title, Student, status, identity/capacity badges, essential quick actions.
2. **Compact summary** — Student, offer, preferred schedule, Teacher, arrangement, Enrolment.
3. **Action required** — prioritised blockers with in-place resolution and disabled-prerequisite explanations.
4. **Recommended next action** — authoritative state/permission based, with alternatives.
5. **Workspace navigation** — Request details, Student, Teachers, Agreements, Proposals, Acceptance, Enrolment, Scheduling, Finance, Communications, Case state, Activity.
6. **Main workspace** — request/identity/authority/matching/agreements/proposals/acceptance/follow-through/scheduling.
7. **Side context** — payment, communications, case state, audit, advanced/audit details.

## 6. Actions now available directly inside Coordination

- Review/open the case and change advisory case state.
- Add/replace advisory Teacher candidates and change candidate consideration status.
- Review Teacher readiness, accepting state, course eligibility, availability, workload and real Teacher/Student schedule collisions.
- Update Teacher/Course eligibility and accepting state with the canonical eligibility authority.
- Record, withdraw or invalidate Teacher agreement.
- Issue and revise Proposal Versions.
- Record provisional acceptance evidence.
- Resolve identity, create a reviewed Student, record identity outcomes, classify capacity, establish Student principal and grant guardian authority.
- Create the final arrangement with an exact principal and local confirmation time.
- Convert the arrangement to an Enrolment, assign the accepted Teacher and issue the Student account invitation.
- Retry the exact eligible failed notification and record verified offline intro payment evidence.
- Assess matchability and inspect all audit/technical references.

## 7. Actions intentionally still unavailable

| Action | Authority reason | Smallest safe improvement |
|---|---|---|
| Edit submitted preferred times | No append-only request-correction command exists; silently rewriting intake would destroy evidence | Add a reviewed `BookingRequestCorrectionService` with a reason, evidence and new requested-time sequence |
| Cancel the booking request | Coordination case closure is not booking cancellation | Add an explicit booking-request cancellation authority that coordinates notifications and payments |
| Reopen a closed coordination case | `CoordinationCaseService` has terminal states and no reopen command | Define permitted reopen transitions, actor capability and audit evidence |
| Reschedule/cancel a confirmed intro | No administrator command for that confirmed booking lifecycle | Add a canonical confirmed-intro reschedule/cancel authority |
| Retry Google/Meet projection manually | Provider success cannot be fabricated; worker owns retry | Add an operator-safe retry command that records provider evidence |
| Refund/adjust payments | Separate finance authority and evidence required | Keep in Finance; add a contextual read-only link or drawer |
| Create/activate Term, issue/schedule Lesson | These are Enrolment/Class authorities, not booking coordination | Compose selected canonical actions in the Enrolled Classes record |
| Edit recurring availability rules | Authority-wide effect; needs full rule evidence and overlap checks | Add a drawer that calls the existing availability service with explicit scope warnings |
| Duplicate-flag review | Only repository-level operation; no application-service seam | Add a small duplicate-review application service under the existing capability |
| Revoke/supersede principal/guardian | Historical authority and conflict handling need their own review UI | Add a dedicated append-only authority-history panel |
| Teacher replacement on an enrolled course | Requires future-schedule blocker and assignment agreement | Keep in Enrolled Classes / assignment authority |

These restrictions are explained in the interface; no disabled control claims that the operation exists.

## 8. New backend authority introduced

**No new tables, routes, permissions or external-provider authority were introduced.**

Three narrow continuity corrections were required so in-place identity review does not make the rest of coordination unusable:

1. `CoordinationCaseService::validRequest` now accepts a submitted/unresolved request whether or not its Student identity has already been linked. The existing state, privacy-erasure and confirmed-booking checks remain.
2. `TeacherAvailabilityAssentService::recordableContextForUpdate` uses the same lifecycle predicate rather than requiring `student_id === null`.
3. `TeacherAvailabilityAssentRepository::currentSourceReliance` no longer drops a current assent merely because identity was resolved.

**Why:** the original coordinator could only record agreement/proposals before identity was resolved. The new in-place identity action would otherwise strand the case. The change does **not** weaken final acceptance: `FinalAcceptanceService` still locks and revalidates the exact identity resolution, capacity classification, current principal/guardian authority, Proposal Version, provisional event, Proposal Family finality and idempotency. The advisory candidate and agreement remain prospective and do not become an assignment.

The new `CoordinationWorkspaceReadService` is read-only. The new `CoordinationBlockerAssessment` is a pure state/permission explainer. The new `CoordinationCapabilities` class only names existing narrow capabilities.

## 9. Security and concurrency review

- Every mutation checks the existing narrow capability in the controller and again in the owning service.
- The route remains protected by both `dzn_manage_booking_request_coordination` and `dzn_view_booking_requests`.
- Generic per-case nonces protect every command; final acceptance carries an additional named nonce; all forms use POST and redirect.
- Record targets are resolved server-side from case/request/candidate/context. Hidden IDs are validated by the service; a posted cross-record ID cannot bypass ancestry checks.
- Idempotency keys, evidence references and system timestamps are generated server-side. Operators do not type candidate/family/option/version/fingerprint/key values.
- The controller does not write `$wpdb` tables. The read service contains no `INSERT`, `UPDATE` or `DELETE`.
- Existing pessimistic locks, `FOR UPDATE` reads, expected versions, idempotent commands, append-only evidence and terminal-state checks are preserved.
- Stale or concurrent changes surface a safe, explicit message; no silent overwrite.
- Local date/time inputs are converted to canonical UTC; raw UTC is kept in advanced details/audit.
- Notification retry is bound to the exact original notification and eligible window; recipient/template cannot change.
- Manual payment settlement takes amount/currency from the stored obligation; an already-settled obligation cannot be settled again.
- The view does not treat a candidate as an assignment, an assent as a booking, provisional evidence as final acceptance, an arrangement as an Enrolment, or a provider hand-off as delivery.
- Disabled actions state the exact missing capability; they are never rendered as working buttons.

## 10. Routine bookings versus exceptional coordination

The landing page explicitly distinguishes the two. Coordination does not sit on the normal path:

- ordinary Student request → normal match/Teacher acceptance → confirmed introductory booking stays in the standard flow;
- coordination is used for identity/authority ambiguity, matching exceptions, evidence repair, payment/notification/projection exceptions or recovery;
- the queue only offers unresolved intake or an authorised teacher-confirmed continuation;
- a routine confirmed booking is not forced through manual agreement, proposal or final-acceptance steps.

## 11. Test results

| Test | Result |
|---|---|
| `php platform/tests/coordination-blocker-assessment-unit.php` | **PASS** — blockers, evidence, recommendations, alternatives and missing-capability behaviour |
| `php platform/tests/coordination-command-centre-contract.php` | **PASS** — sections, human forms, capability gates, read-only seam, responsive design and unsupported boundaries |
| `php platform/tests/phase-2a2b-contract.php` | **PASS** |
| `php platform/tests/phase-2a2c-contract.php` | **PASS** |
| `php platform/tests/phase-2a2d-contract.php` | **PASS** |
| `php platform/tests/phase-2a2e-contract.php` | **PASS** |
| `php platform/tests/phase-2a2g-contract.php` | **PASS** |
| `php platform/tests/booking-coverage-operator-handoff-contract.php` | **PASS** |
| `php platform/tests/student-operations-journey-contract.php` | **PASS** |
| Admin navigation, Overview and dashboard UX contracts | **PASS** |
| Source regression discovery (`acceptance/bin/source-regression-batch.sh`) | **PASS for this change:** 305 passed, 2 skipped; the 2 failures are pre-existing unrelated notification/Stripe authority contracts (`notification-diagnostics-contract`, `phase-2a2u-contract`) that do not read any file changed here |
| `acceptance/bin/run-coordination-command-centre-acceptance.sh` | **PASS** on disposable WordPress/MariaDB |
| Manual disposable UI journey | **PASS** — add candidate → agreement → proposal → provisional → identity resolution → final arrangement → conversion → initial assignment; account-claim step correctly refused because the acceptance runtime has no configured notification route (`routable_intent`) and reported no false success |
| PHP syntax checks on all changed PHP files | **PASS** |

The acceptance fixture covers: no candidate; multiple/alternative candidate context; agreement; proposal; provisional evidence; adult final acceptance; resolved/minor-ambiguous authority panel; identity blocker; Student and Teacher occupancy conflicts; conversion readiness/conflict; existing arrangement; notifications/payment evidence; and returning to an unfinished case. Concurrency is covered by the existing locked Phase 2A.2 runtime suites; this UI calls those services rather than reimplementing locks.

## 12. Screenshots

Desktop:

- [Coordination queue](evidence/coordination-command-centre-2026-11-03/desktop-index.png)
- [Case top / summary](evidence/coordination-command-centre-2026-11-03/desktop-case-top.png)
- [Full case with capacity blocker](evidence/coordination-command-centre-2026-11-03/desktop-case-blockers.png)
- [Action-required panel](evidence/coordination-command-centre-2026-11-03/desktop-case-action.png)
- [Identity-blocked case](evidence/coordination-command-centre-2026-11-03/desktop-case-identity.png)
- [Arrangement / Enrolment follow-through](evidence/coordination-command-centre-2026-11-03/desktop-case-enrolment.png)

Mobile (390 px):

- [Mobile queue](evidence/coordination-command-centre-2026-11-03/mobile-index-top.png)
- [Mobile case top](evidence/coordination-command-centre-2026-11-03/mobile-case-top.png)
- [Mobile action-required panel](evidence/coordination-command-centre-2026-11-03/mobile-case-action.png)
- [Mobile summary cards](evidence/coordination-command-centre-2026-11-03/mobile-case-summary.png)
- [Mobile full case](evidence/coordination-command-centre-2026-11-03/mobile-case-blockers.png)

## 13. Delivery details

- **Branch:** `feat/coordination-command-centre-20261102`
- **Base:** latest `origin/main` at `78c5d2c8` (2026-11-02)
- **Commit SHA:** recorded in the final delivery response after commit
- **Pull request:** to be opened from the branch; no merge or deployment performed
- **Database migration:** none
- **Production records modified:** none
- **External providers called:** none by tests or fixtures

## 14. Remaining blockers and recommended next steps

1. **Booking-request correction and cancellation authority** — the largest product gap. Add an append-only correction service and explicit cancellation command, with notification/payment consequences.
2. **Confirmed-intro reschedule/cancel** — add the missing operational command; until then the interface explicitly does not simulate it.
3. **Duplicate review service seam** — move duplicate-flag review behind a small application service, then embed it.
4. **Teacher availability drawer** — reuse existing rule services with explicit scope/overlap evidence.
5. **Provider retry command** — operator-safe Google/Meet projection retry with recorded provider evidence.
6. **Finance drawer** — contextual read-only refund/payment history, with any adjustment staying in Finance.
7. **Closed-case recovery policy** — define whether reopening is ever permitted and under which evidence.
8. **Screenshot refresh in CI** — add a disposable browser capture job now that the runtime fixture is reproducible.
