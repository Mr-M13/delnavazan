# Production synthetic dataset — scope and safety model

**Status: proposal for review. No production writes. No behaviour change.**
This document does not enable production seeding. The existing
`synthetic-dataset-policy.php` still fails closed on production until the
decisions in section 6 are approved and the implementation PRs in section 8
are merged and rehearsed.

Existing fixture: `platform/tests/synthetic-dataset.php` (see
[`SYNTHETIC-DATASET.md`](./SYNTHETIC-DATASET.md)).
Host policy: `platform/tests/synthetic-dataset-policy.php`.

---

## 1. Goal and hard constraints

Goal: put a coherent, unmistakably synthetic dataset on the live site so the
whole product can be exercised end to end (multiple teachers and students,
enrolments, scheduled and completed classes, booking/intro states, payments,
notifications) without touching genuine data.

Hard constraints (must hold for every phase):

1. **Courses are never created or modified.** The fixture references existing
   course records only, selected by explicit operator-supplied reference code.
   Instruments are treated the same way: they are reached through the existing
   course's instrument, never created.
2. **No fabricated Google Meet links and no provider network calls.** The
   fixture must never invent a `meet.google.com` URL and must never call
   Google/Stripe/provider APIs.
3. **No live charges.** Payments are in scope only through an approved
   test-mode path (open decision 3) or are excluded.
4. **No email to real recipients.** Notification records/intents only, with
   `@example.invalid` or suppressed recipients. No transport dispatch.
5. **No mixing.** Every row written is manifest-owned and unmistakably labelled
   `DZN TEST` / `@synthetic.example.invalid`.
6. **Reversible.** Every mutation is idempotent, dry-runnable (`plan`), and
   teardown removes only manifest-owned rows and refuses when referenced.

---

## 2. What exists today

`SYNTHETIC-DATASET.md` and `synthetic-dataset.php` already provide:

- Modes `preview`, `seed`, `verify`, `reconcile`, `teardown`.
- A marker-checked private manifest option (`dzn_synthetic_dataset_v1`,
  marker `DZN-SYNTHETIC-DATASET-V1`) holding exact created IDs.
- Namespace-collision refusal when `DZN TEST %` rows exist without a manifest.
- Coverage: instruments, courses, three teachers (one walked fully to
  `active/ready` through invitation → claim → availability → onboarding),
  two students, one student account-claim intent, one matchable booking
  request.
- Teardown that deletes only manifest-owned core IDs (students, teachers,
  courses, instruments) after refusing if any platform table references them.

Why production is refused today (`synthetic-dataset-policy.php`):

- `WP_ENVIRONMENT_TYPE === 'production'` ⇒ always `false`.
- Allowed: `local`/`development` (opt-in) or the now-retired
  `staging.delnavazan.com` with a second acknowledgement.

Known limitation already documented: "many operational tables are append-only,
teardown currently covers only these four core tables", and the guidance is not
to extend the fixture with a workflow whose records cannot be enumerated and
removed safely. This is the central tension for the production dataset
(see decision 5).

---

## 3. Proposed authorisation model (additive, fail-closed)

Keep all existing local/development/staging behaviour unchanged. Add a
production branch that cannot be satisfied accidentally. Every condition must
hold:

1. `WP_CLI` is defined (WP-CLI only).
2. `DZN_SYNTHETIC_DATASET=1` (primary opt-in, unchanged).
3. `DZN_SYNTHETIC_DATASET_PRODUCTION=I_UNDERSTAND_PRODUCTION_TEST_DATA`
   (production-only acknowledgement).
4. `wp_get_environment_type() === 'production'` and
   `wp_parse_url( home_url('/'), PHP_URL_HOST ) === 'delnavazan.com'`
   (no `www`, no subdomain).
5. A **human-only attestation option** exists and is current:
   `dzn_synthetic_dataset_production_attestation` =
   `{ marker, attested_by_user_id, attested_by_login, attested_at_utc, evidence_reference }`.
   The fixture **reads but never writes** this option; it refuses when absent
   or stale. It is created by an operator as the recorded outcome of the
   data-safety verification (decision 1).
6. All existing operator capabilities required by `dzn_synthetic_dataset_guard()`.
7. Namespace-collision refusal, unchanged.

Additional modes/guarantees:

- `plan` — dry run. Prints the exact intended writes (tables, counts, labels)
  and exits before any mutation.
- `verify` / `reconcile` / `teardown` — as today, restricted to manifest IDs.
- Every mutation appends an audit row (actor + fixture marker) so the synthetic
  history is identifiable.
- **Mirror rehearsal gate:** teardown must be proven on a production mirror
  (seed → verify → teardown → re-verify absent) before the first production
  seed. Evidence recorded in a PR, not on the server.

Policy contract tests will be extended so that production remains refused
unless every condition above is met.

---

## 4. Coverage plan (phased) and table mapping

Entities and their canonical services. "Removal class" drives teardown safety:
**A** = fully enumerable and safely deletable leaf-first; **B** = append-only
command/event history that cannot be deleted without breaking invariant
records; **C** = external-effect surface, excluded or intent-only.

| Phase | Entity | Canonical service | Primary tables | Removal class |
|---|---|---|---|---|
| 1 | Extra teachers (multiple) incl. ready + onboarding + inactive | `TeacherService`, `PrincipalInvitationService`, `TeacherAvailabilityService`, `TeacherOnboardingService`, `TeachingEligibilityService`, `TeacherAcceptingStateService` | `teachers`, `teacher_principal_links`, `teacher_invitations*`, `teacher_availability_*`, `teacher_onboarding_*`, `teacher_course_eligibilities`, `teacher_accepting_states` | A + B |
| 1 | Extra students (multiple) with portal access | `StudentService`, `StudentAcceptanceAuthorityService`, student claim services | `students`, `student_principal_links`, `student_account_claim_*` | A + B |
| 2 | Terms | `TermService`, `CanonicalTermAuthorityService` | `terms`, `term_commands`, `term_lifecycle_events` | A + B |
| 2 | Enrolments (referencing existing courses only) | `EnrolmentService`, `EnrolmentConversionService`, `CanonicalEnrolmentLifecycleService` | `enrolments`, `enrolment_identity_roots`, `enrolment_lifecycle_*`, `enrolment_conversion_commands` | A + B |
| 2 | Teacher assignments | `TeacherAssignmentService` | `teacher_assignments`, `teacher_assignment_*` | A + B |
| 3 | Scheduled classes (future) | `LessonService`, `CanonicalLessonAuthorityService`, `CanonicalLessonScheduleService` | `lessons`, `canonical_lesson_schedule_versions`, `canonical_lesson_*` | A + B |
| 3 | Completed classes + attendance | `CanonicalLessonDeliveryService`, `CanonicalAttendanceIntakeService` | `canonical_lesson_delivery_*`, `canonical_attendance_*` | B |
| 4 | Booking states: pending, delivered/awaiting, accepted+confirmed intro | `BookingRequestSubmissionService`, `BookingRequestMatchAssessmentService`, `BookingTeacherDeliveryService`, `BookingTeacherResponseService` | `booking_requests`, `booking_request_*`, `booking_request_confirmations`, `booking_request_student_notifications` | A + B |
| 5 | Payments (test-mode only) | `CommercialPaymentService`, `CollectionIntentService`, Checkout/*, PaymentExecution/* | `commercial_*`, `payment_*`, `checkout_sessions`, `collection_intent*` | C (decision 3) |
| 5 | Notifications (intent-only) | `NotificationService`, `NotificationDispatchService` | `notifications`, `notification_*` | C (decision 4) |

Removal class B is the crux: canonical lifecycle/command/event tables are
append-only by design, so a "clean teardown" that removes a synthetic
enrolment/lesson/attendance chain may be impossible without deleting invariant
history. See decision 5.

---

## 5. Non-goals

- Not a production-capable CRUD seeder; every entity goes through its canonical
  lifecycle service.
- No new parallel identity, scheduling, payment, or notification authority.
- No course or instrument creation/modification.
- No external provider calls, no charges, no real email.
- Not a substitute for the isolated acceptance harness
  (`acceptance/`), which remains the safe venue for provider/Meet/payment
  flows that must never touch production.

---

## 6. Open decisions (owner input required)

1. **Data-safety attestation.** Who confirms production is free of genuine
   academy data, with what evidence, and who may set
   `dzn_synthetic_dataset_production_attestation`? Without a concrete answer the
   production path stays closed.
2. **Confirmed-intro / Meet reference.** We cannot fabricate a Meet URL, and
   provider calls are forbidden. Options: (a) exclude the confirmed-intro/Meet
   state from the production dataset and exercise it only in isolation;
   (b) let the real Google projection create a genuine event for a synthetic
   teacher (needs OAuth; creates a real Meet); (c) one owner-supervised real
   event. Recommend (a) for the first production dataset.
3. **Payments.** No live charges. Options: (a) exclude; (b) test-mode records
   without any network call (reconciliation may then be incomplete);
   (c) Stripe test-mode keys on production (needs explicit approval and secret
   handling). Recommend (a) initially, then (b).
4. **Notifications.** Options: (a) intent/record-only (recommended);
   (b) dispatch to `@example.invalid` (still an external call by the worker);
   (c) a local catcher. Recommend (a).
5. **Append-only history.** Accept labelled, inert `DZN TEST` command/event
   history that teardown cannot remove (recommended, with a documented residue
   list), or restrict the production dataset to entities whose full record set
   is enumerable and deletable.
6. **Policy change approval.** This proposal changes a documented invariant
   ("production is never permitted"). It needs explicit owner sign-off recorded
   in the PR before implementation.

---

## 7. Acceptance criteria and test plan

- Extended `synthetic-dataset-policy-contract.php` cases:
  production ⇒ refused without all six conditions; accepted only with every
  gate including a valid attestation; `www.delnavazan.com` refused.
- Extended `synthetic-dataset-contract.php` coverage assertions (no course
  creation, no `wp_mail`/provider/payment calls, manifest-only teardown, new
  modes).
- `plan` mode produces zero writes (assert row counts unchanged).
- Mirror rehearsal: seed → verify → teardown → verify absent, with the residue
  list for class B recorded and reviewed.
- Production run (separate, owner-approved): `plan` → `seed` → `verify` →
  `deployment/qa/production-smoke.sh` still PASS → `teardown` dry-run.
- Rollback: teardown deletes only manifest IDs and refuses on any reference.

---

## 8. PR sequence

1. **This PR** — scope document + (optionally) the extended policy contract
   cases. No behaviour change; production remains refused.
2. Authorisation implementation behind the gates above (still refused without
   the attestation).
3. Entity phases 1–3 through canonical services.
4. Phase 4 booking states (per decision 2).
5. Phase 5 payments/notifications (per decisions 3–4), or explicit exclusion.
6. Mirror rehearsal evidence PR.
7. Production seed — separate, owner-approved, with the attestation in place.

---

## 9. References

- `platform/tests/synthetic-dataset.php`, `synthetic-dataset-policy.php`
- `platform/tests/SYNTHETIC-DATASET.md`
- `platform/src/Core/Infrastructure/Migration/Migrator.php` (209 platform tables)
- `README.md` (production acceptance-data rule)
- `acceptance/README.md` (isolated harness)
