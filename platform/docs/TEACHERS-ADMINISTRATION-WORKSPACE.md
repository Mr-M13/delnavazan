# Teachers Administration Workspace

## Purpose

The Teachers area is now one management workspace. It replaces the previous
Overview / Onboarding & Invitations split with:

1. a complete Teacher directory;
2. an in-page Teacher management record;
3. a consolidated invitation and onboarding table at the bottom of the page.

The route remains `admin.php?page=dzn-teachers-menu`. Existing protected
capabilities continue to decide what each operator can see and do.

## Composition

| Surface | Implementation |
|---|---|
| Workspace controller | `platform/src/Admin/Controller/TeacherWorkspaceController.php` |
| Presentation | `platform/src/Admin/View/TeacherWorkspaceView.php` |
| Read model | `platform/src/Core/Application/TeacherWorkspaceReadService.php` |
| Temporary unavailability | `platform/src/Core/Application/TeacherUnavailabilityService.php` |
| Invitation-only cleanup / onboarding reset | `platform/src/Core/Application/TeacherOnboardingLifecycleService.php` |
| Existing route and mutations | `platform/src/Admin/Controller/ScreenController.php` |

No new identity, scheduling, attendance, availability or finance authority was
introduced. The workspace reads canonical records and calls the existing owning
services for mutations.

## Directory

- All non-archived Teachers are listed with no mandatory filter and no pagination.
- Archived Teachers are available through **Archived records** only.
- Active Teachers sort first, then pending onboarding, then unavailable records.
- Teacher names use the existing display name. Email appears underneath.
- No internal Teacher ID, WordPress user ID or reference code is used as the
  display label.
- The table exposes contact, instruments, status, weekly availability and
  timezone, selected golden-hour coverage, current students, current-month
  teaching, current-month introductions, conversion, current rate, payment
  status and actions.

## Statistics

- **Current students**: distinct students with a non-closed canonical Enrolment
  and applicable Teacher Assignment. Cancelled, closed or archived assignments
  are not counted.
- **Teaching time**: the sum of canonical delivery outcomes whose effective
  `delivery_state` is `delivered` during the current calendar month. Cancelled
  and non-delivered outcomes are excluded. Legacy completed Lessons are included
  only when their current schedule can be verified.
- **Introductions**: distinct students with a completed introductory outcome in
  the current month.
- **Conversion**: converted distinct students divided by converted + unsuccessful
  distinct students. A recorded enrolment is required to count as converted.
  Outcomes inside the 30-day attribution window remain pending; older outcomes
  without an enrolment are unsuccessful. If no resolved outcome exists, the
  value is **Not available**.
- **Golden hours**: the current finance policy is projected into the Teacher's
  timezone and intersected with active weekly availability using the shared
  `TeacherAvailabilitySummary` projection.
- **Payment**: derived from the latest canonical statement and its append-only
  `payment_recorded` event. An issued statement is never treated as paid. The
  canonical Teacher statement authority has no due-date field, so the workspace
  never fabricates an **Overdue** state. Unsupported states show **Not available**
  or **Payment pending** rather than a guessed outcome.

## Teacher profile

The profile is an in-page full-width record with these sections:

1. Teacher details
2. Operational status and student intake
3. Onboarding and account
4. Instruments and course eligibility
5. Weekly availability and golden hours
6. Students and teaching activity
7. Classes and attendance
8. Tasks and response performance
9. Finance and compensation
10. Administrative actions

Record actions continue to use `RecordActionRenderer` and the existing action
registry. Eligibility, availability, intake, onboarding review, offboarding and
archive therefore continue to use their canonical services.

## Temporary unavailability

Temporary unavailability is represented as dated all-day `blocked` availability
exceptions in the Teacher's own timezone. It does not alter weekly availability,
historical attendance or confirmed lessons. The canonical
`TeacherAvailabilityService::effective()` read automatically removes those dates
from new booking eligibility.

Before saving, the workspace identifies future canonical lessons and confirmed
introductory bookings in the period. Conflicting lessons are shown and must be
acknowledged; they are never cancelled silently. Reactivation removes only future
temporary-unavailability exceptions.

## Onboarding lifecycle

The consolidated table joins the existing invitation and onboarding read models
into one current-state row per Teacher.

| Scenario | Action | Effect |
|---|---|---|
| Invitation only, no account or history | **Revoke & remove** | Revokes generations, cancels delivery outboxes, anonymises terminal recipient data, removes the Teacher row and retains audit evidence. |
| Account created, no protected history | **Reset onboarding** | Revokes invitations, revokes the Teacher principal link, clears the failed onboarding state, removes Teacher-only access and allows a fresh invitation to the same email. A WordPress user is deleted only when it is demonstrably exclusive to onboarding. |
| Teaching, finance or attendance history exists | **Archive Teacher** | Uses the existing archive authority. Historical records are preserved, access is revoked and future dependencies must be resolved. |

Both cleanup operations are transactional and idempotent. They never cascade-delete
teaching, attendance, statement, payment or audit records.

## Golden-hour location

- **Academy Overview** now renders the academy-wide Golden Hour Coverage Atlas.
- **Teachers** shows each Teacher's individual golden-hour coverage and selected
  windows.
- **Settings → General** exposes the canonical golden-hour definitions and rules
  entry, which remains owned by Finance settings.

## Verification

The source and pure-state contracts are:

- `platform/tests/teacher-workspace-admin-contract.php`
- `platform/tests/teacher-workspace-state-unit.php`
- `platform/tests/teacher-workspaces-contract.php`
- `platform/tests/teacher-admin-ux-contract.php`
- `platform/tests/onboarding-admin-authority-contract.php`
- `platform/tests/golden-hour-coverage-view-unit.php`
- `platform/tests/admin-dashboard-ux-contract.php`

Runtime verification still requires the repository's disposable WordPress/MySQL
acceptance environment. The workspace deliberately fails closed when a canonical
read cannot be proven; it does not fabricate a statistic.
