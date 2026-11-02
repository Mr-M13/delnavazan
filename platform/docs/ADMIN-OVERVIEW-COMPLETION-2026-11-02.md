# Admin Overview completion — 2026-11-02

This branch reconciles PR #490 (finance Snapshot and administrator classroom access) with PR #491
(the consolidated Teachers workspace), then completes the remaining Overview requirements.

PR #491 remains a separate open change. Its commit is merged into this branch for integration
review so the Teachers workspace, navigation, CSS and tests are not lost.

## Final checklist

| # | Requirement | Status | Evidence |
|---|---|---|---|
| 1 | Seven primary areas: Overview, Teachers, Students, Classes, Enrolment, Finance, Settings | **Complete** | `AdminShellController::TABS`; rendered tabs in `admin-overview-combined-desktop-2026-11-02.png`; `admin-overview-completion-contract` / `admin-navigation-target-contract` |
| 2 | Rename Academy to Overview, page title and Screen Options wording | **Complete** | `Menu.php` route label; `<h1>Overview</h1>`; `AcademyDashboardScreen` legend and labels; rendered screenshot |
| 3 | Use the approved Delnavazan logo | **Complete** | `AdminShellController` loads `theme/assets/images/delnavazan-logo.png`; improvised SVG removed; rendered header |
| 4 | Quick actions: Add student, Teacher onboarding, Issue class, Issue statement, Class resolutions, Enrolment resolutions | **Complete** | `AcademyOperationsController::quickActions()`; direct onboarding anchor; statements tab; classes resolution filter; new Enrolment resolutions queue; old Add teacher/Bookings/Finance shortcuts removed |
| 5 | Retain six useful Overview numbers | **Complete** | `kpiCards()` retains students, teachers, enrolments, bookings, today classes, open action items |
| 6 | One combined pending-work area including class scheduling/integrity and class-time decisions | **Complete** | `pendingActions()` / `pendingActionItems()`; class attention counts; one class-time item; `id="dzn-pending-work"`; `admin-overview-completion-contract` |
| 7 | Remove separate Attention needed and Class time changes panels/options | **Complete** | `dzn-academy-attention` removed; `reschedules` Screen Option removed; `Attention needed` panel removed; class-time details live inside pending work |
| 8 | Move class-time decisions into pending work | **Complete** | `rescheduleQueue()` renders `id="dzn-pending-reschedules"` inside `pendingActions()`; `RescheduleReviewController` returns to that anchor |
| 9 | Today’s classes above Learner pipeline | **Complete** | `screen()` order: quick, KPIs, today classes, pending work, pipeline, activity, finance, coverage, journeys |
| 10 | Today’s classes only, academy timezone, no upcoming list | **Complete** | `scheduleDateInAcademyTimezone()`; `todayOnly` filtering; “Next classes” removed; screenshot |
| 11 | Class details: time, student, instrument, teacher, completed, remaining, term, replacement allowance | **Complete** | `LessonOperationsReadService::enrichTodayRows()`; `completed_class_count`, `remaining_class_count`, `term_label`, `replacement_remaining`, instrument; screenshot |
| 12 | Administrator mark-attended action through canonical attendance | **Complete** | `AdminAttendanceConfirmationService`; `AcademyClassOperationsController::markAttended()`; canonical `settle_delivered`; runtime settled case and completed Lesson; audit row |
| 13 | Administrator change-time action through canonical schedule | **Complete** | `AcademyClassOperationsController::rescheduleClass()` calls `CanonicalLessonScheduleService::revise()`; runtime created schedule version 3 |
| 14 | Preserve PR #490 LiveKit/Meet/participant links and pre-class access | **Complete** | Existing `LiveKitAdministratorClassroomService` and controller retained; actions visible in classroom screenshot; existing unit/source tests |
| 15 | Real 30-day activity dataset, labels, empty state, accessibility and Screen Option | **Complete** | `AcademyDashboardReadService::summary(int $days = 30)`; 30 labels from 3 Oct–1 Nov; explicit range; CSS 30 columns; `30-day activity` |
| 16 | Preserve separate manual Iran/Australia balances and Stripe receipts | **Complete** | Finance panel unchanged in source boundaries; screenshot `A$200.00` net receipts, `12,500.00 AUD` / `980,000,000 TOM`; no combined net |
| 17 | No duplicate Issue statement shortcut inside Finance panel | **Complete** | Quick action only; `admin-overview-completion-contract` asserts absence inside `financePanel()` |
| 18 | Retain Learner pipeline and Learner journey below classes | **Complete** | Both sections remain; pipeline moved below today classes; journey queue remains |

## Tests and acceptance

Source and unit checks:

- `admin-overview-completion-contract.php`
- `admin-dashboard-ux-contract.php`
- `admin-dashboard-screen-unit.php`
- `admin-dashboard-review-fixes-contract.php`
- `admin-dashboard-finance-classroom-contract.php`
- `admin-navigation-target-contract.php`
- `admin-navigation-regression-contract.php`
- `admin-navigation-teacher-finance-contract.php`
- `admin-shell-route-capability-runtime.php`
- `teacher-workspace-admin-contract.php`
- `teacher-workspace-state-unit.php`
- `teacher-workspaces-contract.php`
- `teacher-admin-ux-contract.php`
- `onboarding-admin-authority-contract.php`
- `finance-administration-contract.php`
- `finance-monthly-activity-unit.php`
- `livekit-administrator-classroom-unit.php`
- `livekit-classroom-authorization-unit.php`
- `static.php`

Disposable WordPress/MariaDB acceptance (isolated Compose project, port 18082):

- Login-routing smoke: **PASS**.
- 30-day trend returned 30 dates: `2026-10-03` through `2026-11-01`.
- Today-only rows showed `0 completed`, `12 remaining`, `2 replacements left`, term
  `DZN-TRM-000001`, instrument and teacher.
- Finance showed `12,500.00 AUD`, `980,000,000 TOM`, `1` student paid,
  `A$240.00` gross − `A$40.00` refund = `A$200.00`, and `A$90.00` Teacher payments.
- Administrator attendance confirmation: canonical attendance case `settled`, Lesson
  `completed`, one `admin_classroom.attendance_confirmed` audit row. No LiveKit presence
  was used as evidence.
- Administrator schedule change: canonical revision created schedule version `3` from the
  expected version, preserving the existing schedule authority.
- Rendered tabs: Overview, Teachers, Students, Classes, Enrolment, Finance, Settings.
- Quick actions rendered: Add student, Teacher onboarding, Issue class, Issue statement,
  Class resolutions, Enrolment resolutions.

## Screenshots

- `evidence/admin-overview-combined-desktop-2026-11-02.png`
- `evidence/admin-overview-combined-mobile-2026-11-02.png`
- `evidence/admin-overview-classroom-actions-2026-11-02.png`

## Unresolved dependencies / honest limits

- **Attendance policy dependency:** administrative attendance confirmation requires the existing
  canonical attendance cutover policy and review capability. If that authority is not configured,
  the action fails closed rather than inventing attendance. Acceptance inserted a disposable policy.
- **Schedule dependency:** time changes require a current canonical schedule version, current
  Teacher Assignment, scheduling capability, future time and capacity/availability checks. These
  are the existing authority rules, not new restrictions.
- **Introductory bookings:** term/completion/replacement fields are shown as not applicable or
  unavailable because a confirmed intro is not yet a canonical Term Lesson. The booking remains
  the authoritative record until conversion.
- **Meet provisioning:** open/copy only works when the existing verified Google Calendar/Meet
  projection and active vaulted reference exist. Missing provisioning is shown truthfully.
- **PR #491:** still an open, separate Teachers workspace PR. This branch contains its commit for
  integration; do not treat this branch as authorization to close or merge #491 without review.

## Merge/deployment

Not merged. Not deployed.
