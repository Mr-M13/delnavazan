# Delnavazan admin dashboard redesign — 2026-10-29

## Review summary

The WordPress admin was functionally complete but visually and structurally split across
several generations of screens:

- the Academy landing page was a long vertical document with zero-count action rows,
  duplicated “needs attention” content, a search form before the summary, and no visual
  hierarchy;
- Settings exposed twelve sibling tabs, mixing channels, templates, diagnostics, access and
  recovery commands;
- the Delnavazan admin used untouched WordPress colours and typography, unlike the public
  site and the Finance workspace;
- teacher finance links pointed at a future implementation stage instead of the existing
  scoped rate, statement and payability views;
- payability, rate and statement read views exposed raw identifiers and minor-unit amounts;
- there was no per-user way to reduce the dashboard to the sections an operator uses.

## Implemented

### 1. Task-first Academy dashboard

`AcademyOperationsController` now renders a capability-aware dashboard with:

- a branded hero and quick actions that only appear when the operator can use them;
- six key numbers (students, teachers, enrolments, open bookings, today’s classes, open
  action items) with an explicit restricted state instead of a false zero;
- an action centre that omits zero-count queues and links each decision to its authority;
- the learner pipeline as a proportional stage chart, clickable to filter the queue;
- today/upcoming classes and attention-needed classes in one two-column area;
- a 14-day activity chart for new bookings and completed classes (CSS only, no chart
  dependency);
- a finance snapshot for payment, statement, payability and reconciliation queues;
- the searchable learner-journey queue and class-time-change decisions lower on the page.

All data comes from the capability-gated `AcademyDashboardReadService`; the controller
remains read-only and does not query lesson tables directly.

### 2. Native Screen Options

The Academy page now uses WordPress Screen Options:

- ten optional sections: quick actions, key numbers, action centre, today/upcoming,
  learner pipeline, activity, finance, attention, journey queue and class-time changes;
- preferences are stored per user in `dzn_academy_sections`;
- the submitted map is sanitised server-side;
- the Apply button is only added on the Academy screen, not on unrelated WordPress screens.

### 3. Grouped Settings navigation

Settings remains one protected area, but its twelve routes are now presented as five work
groups:

| Group | Routes |
| --- | --- |
| General | General |
| Messaging | Email, Meta WhatsApp Cloud API, SMS fallback, Communications health, Notification templates |
| Integrations | Integrations overview, Google OAuth / Calendar |
| Access | Staff access, Portal capabilities |
| System | System health and technical diagnostics, Advanced Recovery |

Every route remains registered in `Menu.php` with its original capability. The group row
opens the first permitted member; a compact member row appears only when the group has more
than one permitted route.

### 4. Shared Delnavazan admin design

A new `platform/src/Admin/Assets/admin.css` is loaded only on `dzn-*` pages. It uses the
public-site palette (`#FBF8F2`, `#1E7B70`, `#104A4D`, `#A33E48`, `#D4AB4F`), Vazirmatn,
logical properties for RTL, responsive KPI/panel/chart layouts, and the existing Finance and
Communications card patterns. The mobile admin-bar offset is handled explicitly so the
shared shell never sits behind the WordPress toolbar.

### 5. Filled presentation gaps

- Teacher records now link directly to the existing scoped **Rate timeline**, **Statements
  & reconciliation** and **Lesson payability** views instead of a “next implementation
  stage” placeholder.
- Finance → Settings now opens a **Finance review** area with live counts for pending
  payability, open finance exceptions, draft/issued statements and a link to rate
  timelines.
- Teacher rate timelines hide raw rate IDs, show `vN`, human scope labels, formatted dates,
  status badges and formatted currency amounts.
- Statement lists show statement version, period label, state badge and formatted payable
  amount instead of raw minor units.
- Payability lists show the class reference, student, course, human disposition and basis
  labels, current/history applicability and a clear override badge instead of internal
  policy/override IDs.

## Deliberately deferred domain work

The following remain backend gaps from the earlier navigation audit and were not invented
in this presentation change:

- student profile edit and active/inactive/suspended transitions;
- course/instrument update authority;
- teacher rate/statement write forms beyond the existing Finance settings;
- payability evaluate/override command UI and finance-policy write UI;
- contextual booking → enrolment conversion, account-claim and teacher-assignment actions
  outside Advanced Recovery.

Those need their own domain PRs, contracts and evidence rules. The dashboard links to the
existing protected authorities in the meantime.

## Verification

- `platform/tests/admin-dashboard-ux-contract.php`
- `platform/tests/admin-navigation-target-contract.php`
- `platform/tests/admin-navigation-regression-contract.php`
- `platform/tests/admin-navigation-teacher-finance-contract.php`
- `platform/tests/admin-shell-route-capability-runtime.php`
- `platform/tests/student-operations-journey-contract.php`
- `platform/tests/teacher-workspaces-contract.php`
- `platform/tests/booking-coverage-operator-handoff-contract.php`
- `platform/tests/booking-student-journey-contract.php`
- `platform/tests/finance-administration-contract.php`

Local acceptance inspection covered the Academy dashboard, Screen Options save/reload,
Settings groups/member tabs, Finance review, teacher finance links and the mobile shell
offset.

## Rollback

This is a presentation-only change. Reverting the controllers, the new assets and the new
read service restores the previous screens without a database migration or state repair.
The Screen Options value is per-user meta and can be deleted safely.
