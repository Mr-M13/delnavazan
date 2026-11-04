# Enrolment administration workspace

Date: 2026-11-02

Branch: `feat/enrolment-administration-workspace-20261102`

Integration branch prepared with the in-progress Students workspace:
`integration/enrolment-students-20261102` (both workspaces retained; shared
navigation and CSS conflicts resolved).

Deployment status: **not merged to `main` and not deployed**. The feature branch
and the combined integration branch are local commits awaiting review.

## Shape

The visible Enrolment area now has three sub-tabs:

1. **Intro** — incoming requests, identity and coordination blockers, Teacher matching, the introductory booking, the recorded outcome, the post-intro decision/payment period and conversion reporting. See [ENROLMENT-INTRO-REVIEW-2026-11-02.md](ENROLMENT-INTRO-REVIEW-2026-11-02.md) for the 2026-11-02 follow-up changes.
2. **Review** — active introductory requests with attendance, payment-link, reminder and follow-up facts; requests are removed from this queue 14 days after a payment link with no decision or payment, while the underlying case remains in History.
3. **Enrolled classes** — today/upcoming and historical classes, Terms, regular/replacement classes, attendance evidence, schedule changes and the shared class action component.

Course and instrument configuration moved to **Settings → Catalogue → Courses & instruments**. Its routes and `dzn_manage_courses` authority are unchanged. The protected legacy routes remain registered and bookmarkable, including `dzn-lesson`, `dzn-booking-requests`, `dzn-booking-request-coordination`, `dzn-enrolment`, `dzn-term`, `dzn-course`, `dzn-instrument`, `dzn-attendance-review`, `dzn-post-intro` and `dzn-attendance-workspace`.

## Existing action mapping

| Existing action / route | New place | Status |
| --- | --- | --- |
| Booking request list and detail (`dzn-booking-requests`) | Intro case table and expandable case | Carried into the workspace read surface |
| Match assessment (`dzn_prepare_booking_request_matches`) | Case next-action and manual match hard-constraint check | Carried |
| Duplicate review and privacy erasure (`dzn-booking-requests`) | Protected booking-request route | Remains protected because it is a privacy/duplicate-evidence operation, not an everyday Enrolment decision. |
| Coordination open/add candidate (`dzn-booking-request-coordination`) | Case actions | Carried |
| Teacher Availability Assent | Case actions | Carried |
| Proposal issue | Case actions | Carried |
| Proposal acceptance and final arrangement | Protected coordination record | Runs on the old protected route until the dedicated acceptance forms are carried over. |
| Enrolment conversion (`dzn_convert_service_arrangements_to_enrolments`) | Protected conversion route | Remains protected; no duplicate conversion logic was added. |
| Classes list/detail (`dzn-lesson`) | Enrolled classes + contextual class detail | Carried |
| Canonical schedule create/revise/release | Shared class action component | Carried |
| Canonical Lesson delivery/completion | Shared class action component and attendance evidence | Carried |
| Attendance review (`dzn-attendance-review`) | Class attendance evidence and reviewed decision | Carried contextually; protected review queue remains available |
| Introductory outcome (`dzn-post-intro` outcome form) | Intro case action | Carried |
| Post-intro preparation retry / activation retry / automation identity | Protected Post-intro diagnostics, linked only from an exception | Deliberately kept technical and out of the ordinary workflow |
| Term lifecycle (`dzn-term`) | Class detail Term link and protected route | Read context is carried; Term create/activate/close remains on its protected authority screen. |
| Course/instrument configuration | Settings → Catalogue | Moved in navigation; authority and routes kept |

## Safety decisions

- Administrator manual match records Teacher agreement, the staff reason and the availability-preference override in append-only `platform_audit_events`. It still performs identity, eligibility, invalid-time, canonical occupancy and active-hold checks. A real collision is refused; no double booking is created.
- The manual match reuses the same `booking_request_confirmations`, delivery state, command evidence and post-intro notification obligations as the Teacher-owned confirmation path.
- Notification states distinguish **queued**, **sent to transport (not receipt)**, **delivered only with provider evidence**, **failed** and **unknown**. A transport hand-off is never labelled delivered.
- Post-intro hold expiry is the earlier of the first regular slot and six days after the introductory lesson boundary. The view uses the authoritative reservation row, shows whether it is capacity-effective and shows an exact countdown.
- Conversion reporting has an explicit denominator, reporting period and reason. A completed introduction with no payment today is awaiting decision/payment, not failed.
- Attendance uses participant evidence (identity, join/leave, source, verification and missing/disputed state). Administrator presence and a clicked link are never attendance. Reviewed decisions use the canonical case version and idempotency key.
- Admin classroom access reuses the PR #490 administrator grant. Copied participant links keep normal sign-in and joining rules.

## Historical import

`HistoricalEnrolmentImportService` is a narrow, evidence-bounded compatibility import. It prevents an already-running legacy course from being represented as a new introductory booking. It:

- selects an active Student and Teacher and checks Course eligibility;
- records original start, completed/remaining counts, weekly schedule, next class, replacement allowance and payment status;
- requires evidence or an explicit **historical status unknown** for past attendance and payment;
- refuses duplicate Student/Course/Teacher operational Enrolments and real schedule collisions;
- creates one Enrolment, one Term and only the future standard classes;
- records import provenance in `platform_audit_events` and replays the same idempotency key without duplication.

### Known import gap

The current canonical Enrolment/Term model requires an accepted Service Arrangement as its parent. There is no canonical import parent today. The import therefore uses the existing controlled legacy Enrolment/Term/Lesson compatibility storage and is labelled as an historical import rather than a canonical conversion. A future canonical import model needs its own explicit parent/provenance authority; adding a synthetic arrangement would invent acceptance and is deliberately not done.

## Verification run

- Disposable WordPress + MariaDB acceptance environment.
- Manual match confirmed and produced a pending provider projection with the same confirmation/command rows as a normal match.
- Real collision refused and created no confirmation.
- Canonical reschedule created schedule version 2.
- Canonical cancellation released schedule version 2 and cancelled the class.
- Teacher change succeeded after release and retained assignment history (`replaced` + `assigned`).
- Attendance claim evidence displayed as unverified; adjudication settled the case; a second decision against the old case version was refused without a duplicate decision.
- Post-intro prepared state displayed the authoritative reservation expiry; resend queued the existing failed invitation; reminder created one distinct queued notification; a second reminder within 24 hours was refused.
- Imports created 7 future classes for 5 completed/7 remaining and 12 future classes for 0/12; repeated import keys created no second Enrolment or Term.
- Restricted staff: booking-reader saw only the trial queue and no match/import actions; class operator saw only Enrolled classes; direct access to the other sub-tab returned 403.
- Desktop, tablet and mobile screenshots are stored with this document in `platform/docs/evidence/enrolment-workspace-2026-11-02/`:
  - `enrolment-new-bookings-desktop-2026-11-02.png`
  - `enrolment-new-bookings-tablet-2026-11-02.png`
  - `enrolment-new-bookings-mobile-2026-11-02.png`
  - `enrolment-trial-case-desktop-2026-11-02.png`
  - `enrolment-trial-case-mobile-2026-11-02.png`
  - `enrolment-classes-desktop-2026-11-02.png`
  - `enrolment-classes-tablet-2026-11-02.png`
  - `enrolment-classes-mobile-2026-11-02.png`
  - `enrolment-class-detail-desktop-2026-11-02.png`
  - `enrolment-class-detail-mobile-2026-11-02.png`
  - `enrolment-import-preview-desktop-2026-11-02.png`

## Reproducible checks

Source and unit checks (run in the pure-test bundle):

- `platform/tests/enrolment-workspace-contract.php`
- `platform/tests/enrolment-conversion-classification-unit.php`
- `platform/tests/admin-navigation-regression-contract.php`
- `platform/tests/admin-navigation-target-contract.php`
- `platform/tests/admin-overview-completion-contract.php`
- `platform/tests/admin-navigation-teacher-finance-contract.php`
- `platform/tests/admin-navigation-composite-cap-unit.php`
- `platform/tests/booking-teacher-response-unit.php`
- `platform/tests/post-intro-enrolment-automation-contract.php`

Disposable WordPress/MariaDB runtime:

```sh
cd acceptance
make enrolment-workspace
```

That target resets only the disposable acceptance database, runs the synthetic Enrolment fixtures and executes `enrolment-workspace-runtime-test.php` and `enrolment-workspace-actions-runtime-test.php`. It calls no external provider or message transport.

The runtime suite passed with the current code. The pure-test bundle reported zero failures.

## Remaining gaps

- Proposal acceptance/final arrangement and Enrolment conversion forms still live on the protected coordination/conversion routes.
- Confirmed introductory booking reschedule/cancel/teacher-change has no canonical authority today. The case view links to the protected booking/coordination context rather than inventing a second confirmation lifecycle.
- Canonical historical import is blocked by the missing import-parent model described above.
- Term creation and lifecycle remain on the protected Term route.
- Notification click evidence is reported as **Click status unavailable** because no reliable provider click evidence exists in the current notification authority.
