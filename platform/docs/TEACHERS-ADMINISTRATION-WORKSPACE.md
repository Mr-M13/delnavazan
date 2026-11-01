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

The profile is an in-page full-width record. Teacher-specific decisions stay in
the profile wherever the existing authority can be used safely:

- rate entry, effective rate timeline, statement draft/issue, statement payment
  and statement reconciliation are embedded;
- student pause/resume uses the canonical Enrolment lifecycle authority;
- pending reschedule proposals can be confirmed or cancelled inline;
- attendance cases expose the same canonical reviewed-outcome commands;
- class rows expose an in-page inspection detail and a show-all control for the
  complete retrieved history.

The following remain with their owning authority for a precise reason:

- **Teacher-assignment replacement** remains in Enrolments because it is a
  cross-teacher agreement with commercial/authority evidence, not a single-record
  Teacher edit.
- **Canonical class scheduling/evidence recording** remains in Classes for complex
  schedule reconstruction and delivery-evidence commands; the profile links to the
  selected class.
- **Statement email/PDF export and cross-teacher central-ledger reconciliation**
  remain in Finance because they are transport and multi-teacher operations.
- **Google reconnection** remains Teacher-owned in the Teacher portal and is never
  impersonated by an administrator.

The profile has these sections:

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

The administrator explicitly chooses:

- **Until reactivated** — one durable open-ended sentinel exception. The canonical
  `TeacherAvailabilityService::effective()` read blocks every future occurrence
  from the effective date until an administrator reactivates the Teacher. There is
  no automatic 90-day expiry.
- **A bounded period** — one dated all-day `blocked` exception per day in the
  selected period. Bounded periods are limited to 366 days.

Both forms preserve weekly availability, historical attendance and confirmed
lessons. Before saving, the workspace identifies future canonical lessons and
confirmed introductory bookings in the period (the conflict review window is two
years for open-ended periods). Conflicting lessons are shown and must be
acknowledged; they are never cancelled silently. Reactivation removes only the
open sentinel and future bounded temporary-unavailability exceptions.

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

## Failed-onboarding recovery tests

`make -C acceptance teacher-workspace` exercises a real disposable
WordPress/MariaDB runtime:

1. invite a new email and inspect the queued row;
2. resend without creating a duplicate Teacher;
3. revoke and confirm the row leaves the active onboarding view;
4. re-invite, then revoke-and-remove an invitation-only Teacher and re-invite the
   same email;
5. reset an account-linked Teacher whose only other role is `subscriber`, proving
   the account and role are preserved;
6. reset an account whose only role is `dzn_teacher`, proving only the exclusive
   account is deleted;
7. reset an account with authored content, proving the account and content are
   preserved;
8. create protected finance history, prove reset is refused, and prove the archive
   path remains available;
9. invite the same email again after reset and prove one Teacher identity remains;
10. apply open-ended unavailability, prove booking eligibility is blocked, then
    reactivate and prove it is no longer blocked;
11. change the display name and prove the directory follows it while English and
    Persian name fields remain intact;
12. change review, expiry, delivery, Google and availability records and prove the
    onboarding table refreshes from current state.

## Verification

The source and pure-state contracts are:

- `platform/tests/teacher-workspace-admin-contract.php`
- `platform/tests/teacher-workspace-state-unit.php`
- `platform/tests/teacher-workspaces-contract.php`
- `platform/tests/teacher-admin-ux-contract.php`
- `platform/tests/onboarding-admin-authority-contract.php`
- `platform/tests/golden-hour-coverage-view-unit.php`
- `platform/tests/admin-dashboard-ux-contract.php`
- `acceptance/fixtures/teacher-workspace-recovery.php`

Runtime evidence:

```text
make -C acceptance teacher-workspace
Teacher workspace recovery acceptance: PASS
```

Screenshots:

- `platform/docs/evidence/teachers-directory-desktop-2026-11-02.png`
- `platform/docs/evidence/teachers-profile-desktop-2026-11-02.png`
- `platform/docs/evidence/teachers-onboarding-desktop-2026-11-02.png`
- `platform/docs/evidence/teachers-directory-mobile-2026-11-02.png`
- `platform/docs/evidence/teachers-profile-mobile-2026-11-02.png`
- `platform/docs/evidence/teachers-onboarding-mobile-2026-11-02.png`

The workspace fails closed when a canonical read cannot be proven; it does not
fabricate a statistic.
