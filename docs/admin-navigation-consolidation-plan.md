# Delnavazan admin navigation consolidation — implementation plan and capability-gap report

**Status:** approved for phased implementation. PR 1 is presentation-only; PR 2 adds the shared record-action foundation. No production configuration, records, services or deployments have been changed.

**Prepared:** 2026-10-24
**Implementation branch:** `feat/admin-nav-pr1-shell-academy-20261024`
**Evidence baseline:** `origin/main` at `3aab56d`, plus the existing source audit in `docs/admin-workflow-ia-audit.md`.

## 1. Executive summary

The branch already contains a partial consolidation: six primary areas (`Academy`, `Teachers`, `Students`, `Enrolment`, `Finance`, `Settings`) and teacher-scoped Finance read views. It does **not** yet match the requested target. The main remaining work is:

- remove all three Academy sub-tabs and make Academy one combined page;
- reduce Teachers from four tabs to exactly two (`Overview`, `Onboarding & Invitations`);
- move lesson payability into Finance and move teacher rates/reconciliation into each teacher record;
- reduce Enrolment from eleven tabs to four (`Classes & Schedules`, `Bookings & Enrolments`, `Courses & Instruments`, `Attendance & Post-intro`);
- replace routine use of `Core dataset readiness` with contextual record actions;
- add the shared contextual-action layer (server-side record context, generated idempotency keys, capability/state gating, consequence warnings, RTL/mobile behaviour).

Most domain write authorities already exist. The biggest gaps are **presentation, context resolution, read models, and a small number of missing CRUD/lifecycle services** — not a need to replace the canonical domain model.

No database migration is required for the navigation itself. Any new student/teacher/catalogue lifecycle capability may require a migration and must be reviewed separately.

## 2. Current navigation audit

### 2.1 Current primary tabs and sub-tabs

| Primary area | Current sub-tabs | Current source |
| --- | --- | --- |
| Academy | Overview; Today and upcoming lessons; Attention needed | `AdminShellController::TABS`, `AcademyOperationsController` |
| Teachers | Teacher directory and profiles; Teacher-specific rates; Lesson payability; Teacher reconciliation | `AdminShellController::TABS`, `ScreenController`, `FinanceRateController`, `FinancePayabilityController`, `FinanceStatementController` |
| Students | Student directory; Profiles and learning progress | `ScreenController`, `StudentIdentityAuthorityController` |
| Enrolment | Classes and schedule; Booking requests; Request coordination; Enrolments; Terms; Courses and instruments; Instruments; Attendance review; Catalogue and operational exceptions; Canonical workflow actions; Post-intro automation | `AdminShellController::TABS` plus the registered routes in `Menu.php` and controller-specific menus |
| Finance | Student pricing; Payments; Central ledger; Reconciliation; Finance policies; Stripe integration | `AdminShellController::TABS`, `CommercialCatalogueController`, `PaymentExecutionController`, `OperationalCashbookController`, `FinanceReconciliationController`, `FinancePolicyController`, `IntegrationControlPlaneController` |
| Settings | General; Email; Meta WhatsApp Cloud API; SMS fallback; Communications health; Notification templates; Integrations; Google OAuth / Calendar; Staff access; System health and technical diagnostics; Portal capabilities | `AdminShellController::TABS`, `Menu.php`, controller-specific menus |

### 2.2 Route and capability facts

- Visible navigation is rendered by `AdminShellController`; WordPress submenu registrations in `Menu.php` remain the direct-route and capability authority and are visually hidden.
- The shell currently derives route visibility from the same `TABS` array used for presentation. Hidden or contextual-only routes are therefore easy to accidentally expose or drop. This must be split into separate **primary tabs**, **registered routes**, and **hidden/technical routes**.
- Existing direct routes include: `dzn-academy-dashboard`, `dzn-teachers-menu`, `dzn-student`, `dzn-student-identity-authority`, `dzn-lesson`, `dzn-booking-requests`, `dzn-booking-request-coordination`, `dzn-enrolment`, `dzn-term`, `dzn-course`, `dzn-instrument`, `dzn-attendance-review`, `dzn-exception`, `dzn-core-dataset-readiness`, `dzn-post-intro`, `dzn-commercial-catalogue`, `dzn-payment-execution`, `dzn-finance-cashbook`, `dzn-finance-reconciliation`, `dzn-finance-policies`, `dzn-finance-rates`, `dzn-finance-payability`, `dzn-finance-statements`, `dzn-stripe-settings`, `dzn-general`, `dzn-integrations`, `dzn-integration-email`, `dzn-integration-whatsapp`, `dzn-integration-sms`, `dzn-integration-google`, `dzn-communications`, `dzn-communications-templates`, `dzn-staff-access`, `dzn-system-health`, `dzn-portal-capabilities`.
- Existing narrow capabilities include `dzn_manage_teachers`, `dzn_manage_onboarding`, `dzn_issue_teacher_invitations`, `dzn_revoke_teacher_invitations`, `dzn_manage_students`, `dzn_view_student_acceptance_eligibility`, `dzn_resolve_booking_request_student_identity`, `dzn_manage_student_acceptance_authority`, `dzn_manage_booking_request_coordination`, `dzn_convert_service_arrangements_to_enrolments`, `dzn_issue_student_account_claims`, `dzn_manage_teacher_assignments`, `dzn_manage_enrolments`, `dzn_manage_canonical_enrolment_lifecycle`, `dzn_manage_canonical_terms`, `dzn_manage_canonical_lessons`, `dzn_manage_canonical_lesson_delivery`, `dzn_manage_canonical_lesson_schedules`, `dzn_manage_canonical_attendance_review`, `dzn_manage_post_intro_automation`, `dzn_manage_teaching_eligibility`, `dzn_manage_teacher_availability`, `dzn_manage_courses`, `dzn_manage_commercial_catalogue`, `dzn_view_finance_authority`, `dzn_manage_teacher_rates`, `dzn_manage_finance_statements`, `dzn_manage_lesson_payability`, `dzn_view_payment_execution_authority`, `dzn_manage_payment_execution`, `dzn_manage_payment_providers`, `dzn_view_cash_ledger`, `dzn_manage_exceptions`, `dzn_manage_provider_integrations`, `dzn_access_*` composite capabilities.
- Composite capabilities currently exist for `dzn_access_teacher_operations`, `dzn_access_core_workflow`, `dzn_access_integrations_overview`, `dzn_access_provider_integration_settings` and `dzn_access_system_health`. They map to an actor’s existing narrow capability; they do not grant a new action.

### 2.3 Existing record surfaces and actions

| Record | Existing surface | Existing actions / gaps |
| --- | --- | --- |
| Teacher | `dzn-teachers-menu&id=…` | Profile edit; eligibility; availability; onboarding review; archive/restore/offboard; invitation panel is on the list page. Missing: per-teacher rates, statements, reconciliation, assigned students/lessons links, suspend/deactivate policy, onboarding blockers/next action, separate Google Calendar vs Google Meet readiness. |
| Student | `dzn-student&id=…` | Overview/account/administration sections; archive/restore. Missing: profile edit, enrolment management, invitation/claim actions in context, lesson history, status activation/deactivation. |
| Enrolment | `dzn-enrolment&id=…` | Lifecycle history and canonical activate/pause/resume/close. Missing: conversion, student claim, initial teacher assignment, replacement, term creation and first lesson actions in context. |
| Term | `dzn-term&id=…` | Canonical term history and activate/close/cancel. Missing: contextual entry points from Enrolment and next-term guidance. |
| Lesson / class | `dzn-lesson&id=…` | Legacy/canonical schedule, revise, release, introductory outcome. Missing: complete, cancel, postpone definition, attendance link, teacher replacement, delivery outcome in context, auto-generated keys. |
| Booking request | `dzn-booking-requests&request_id=…` | Raw request detail, current advisory assessment, duplicate review, privacy erasure. Missing: curated human-readable detail, coordination/enrolment journey, next action, conversion/claim/assignment links. |
| Coordination case | `dzn-booking-request-coordination&case_id=…` | Candidates, assent, proposal, provisional acceptance, final acceptance. Missing: unified booking-to-enrolment view; many raw IDs; manual idempotency/evidence entry. |
| Attendance case | `dzn-attendance-review` | Evidence review and adjudication. Missing: lesson/record context, automatic idempotency key, link from Lesson and Attendance & Post-intro workspace. |
| Post-intro exception | `dzn-post-intro` | Preparation/activation retry and actor provisioning. Missing: booking/lesson context, unified view with attendance and introductory outcomes. |
| Course / instrument | `dzn-course`, `dzn-instrument` | Curated list/detail, create, archive/restore. Missing: update/edit authority, grouped instrument→course display, catalogue pricing and exceptions in context. |
| Teacher finance | `dzn-finance-rates`, `dzn-finance-payability`, `dzn-finance-statements` | Read-only rate timeline, payability list, statements and statement reconciliation. Missing: record/close/withdraw rate; draft/issue/withdraw/supersede statement; evaluate/override payability; contextual teacher entry points. |

## 3. Old-to-new navigation mapping

### 3.1 Target primary navigation

| Primary area | Target tabs | Notes |
| --- | --- | --- |
| Academy | **None** | Clicking Academy opens the combined dashboard. Keep alerts, journey pipeline, today/upcoming classes and attention content on the one page, using anchors rather than replacement sub-tabs. |
| Teachers | **Overview**, **Onboarding & Invitations** | Exactly two tabs. Teacher detail remains a record view opened from Overview. |
| Students | **Directory**, **Profiles & Learning Progress** | Preserve the existing directory and profile/progress functions; add contextual actions to the record. |
| Enrolment | **Classes & Schedules**, **Bookings & Enrolments**, **Courses & Instruments**, **Attendance & Post-intro** | Exactly four tabs. |
| Finance | **Lesson Payability**, **Pricing**, **Payments**, **Ledger**, **Reconciliation**, **Policies**, **Stripe Integration** | Lesson payability moves here. Central teacher reporting can live under Reconciliation while individual teacher views live on teacher records. |
| Settings | **General**, **Integrations**, **Messaging**, **Google**, **Staff Access**, **System Health**, **Portal Capabilities**, **Advanced Recovery** | Advanced Recovery is restricted and only added after contextual replacements are verified. |

### 3.2 Current tab → target destination

| Current tab | Target |
| --- | --- |
| Academy → Overview | Academy combined page |
| Academy → Today and upcoming lessons | Academy combined page, “Today and upcoming” section/anchor |
| Academy → Attention needed | Academy combined page, “Attention needed” section/anchor |
| Teachers → Teacher directory and profiles | Teachers → Overview |
| Teachers → Teacher-specific rates | Teacher record → Rates; central reporting remains under Finance → Reconciliation |
| Teachers → Lesson payability | Finance → Lesson Payability |
| Teachers → Teacher reconciliation | Teacher record → Statements/Reconciliation; central reporting under Finance → Reconciliation |
| Students → Student directory | Students → Directory |
| Students → Profiles and learning progress | Students → Profiles & Learning Progress |
| Enrolment → Classes and schedule | Enrolment → Classes & Schedules |
| Enrolment → Booking requests | Enrolment → Bookings & Enrolments |
| Enrolment → Request coordination | Enrolment → Bookings & Enrolments |
| Enrolment → Enrolments | Enrolment → Bookings & Enrolments |
| Enrolment → Terms | Enrolment → Classes & Schedules, with term actions on the Enrolment/Term record |
| Enrolment → Courses and instruments | Enrolment → Courses & Instruments |
| Enrolment → Instruments | Enrolment → Courses & Instruments |
| Enrolment → Attendance review | Enrolment → Attendance & Post-intro |
| Enrolment → Catalogue and operational exceptions | Enrolment → Courses & Instruments (catalogue exceptions); System Health / Advanced Recovery (technical exceptions) |
| Enrolment → Canonical workflow actions | Contextual record actions; route retained as Settings → Advanced Recovery |
| Enrolment → Post-intro automation | Enrolment → Attendance & Post-intro; retry action on the affected booking/lesson |
| Finance → Student pricing | Finance → Pricing |
| Finance → Payments | Finance → Payments |
| Finance → Central ledger | Finance → Ledger |
| Finance → Reconciliation | Finance → Reconciliation |
| Finance → Finance policies | Finance → Policies |
| Finance → Stripe integration | Finance → Stripe Integration |
| Settings → General | Settings → General |
| Settings → Email | Settings → Messaging |
| Settings → Meta WhatsApp Cloud API | Settings → Messaging |
| Settings → SMS fallback | Settings → Messaging |
| Settings → Communications health | Settings → Messaging |
| Settings → Notification templates | Settings → Messaging |
| Settings → Integrations | Settings → Integrations |
| Settings → Google OAuth / Calendar | Settings → Google |
| Settings → Staff access | Settings → Staff Access |
| Settings → System health and technical diagnostics | Settings → System Health |
| Settings → Portal capabilities | Settings → Portal Capabilities |

### 3.3 Route compatibility

All existing `page=` slugs remain registered with their existing capability checks. The new navigation may add `view`/`section` parameters, but it must not remove or silently repurpose protected routes.

| Existing route | Compatibility behaviour |
| --- | --- |
| `dzn-academy-dashboard&view=upcoming` / `view=attention` | Render the combined Academy page and move focus to the corresponding section; do not show sub-tabs. |
| `dzn-teachers-menu` | Overview. |
| `dzn-teachers-menu&view=onboarding` | Onboarding & Invitations. |
| `dzn-teachers-menu&id=…` | Teacher record; add `section=rates|statements|reconciliation|students|lessons` for deep links. |
| `dzn-finance-rates&teacher_id=…` | Keep readable; redirect or link to the teacher record Rates section. Central cross-teacher view remains available from Finance → Reconciliation. |
| `dzn-finance-statements&teacher_id=…` | Keep readable; redirect or link to the teacher record Statements/Reconciliation section. |
| `dzn-core-dataset-readiness` | Keep registered and capability-protected; remove from daily navigation, then expose under Settings → Advanced Recovery. Existing query context may be prefilled into the new record action. |
| `dzn-booking-requests`, `dzn-booking-request-coordination`, `dzn-enrolment`, `dzn-term`, `dzn-course`, `dzn-instrument`, `dzn-attendance-review`, `dzn-post-intro`, `dzn-exception` | Remain valid direct routes and record detail pages; linked from the new workspaces. |
| Settings and Finance routes | Remain valid; only their navigation grouping changes. |

## 4. Contextual action map

Every action below must resolve its target from server-side record context, check the existing narrow capability, generate its own idempotency key and system metadata, and call the existing application service rather than writing SQL.

| Record | Contextual action | Existing authority / service | Current UI status |
| --- | --- | --- | --- |
| Booking / Enrolment | Convert accepted Service Arrangement to Enrolment | `EnrolmentConversionService`, `dzn_convert_service_arrangements_to_enrolments` | Only in Core Dataset Readiness |
| Booking / Student / Enrolment | Issue Student account claim | `StudentAccountClaimNotificationService`, `dzn_issue_student_account_claims` | Only in Core Dataset Readiness |
| Enrolment | Assign initial Teacher | `TeacherAssignmentService::assignInitial`, `dzn_manage_teacher_assignments` | Only in Core Dataset Readiness |
| Enrolment / Lesson | Replace Teacher by agreement | `TeacherAssignmentService::replace`, `dzn_manage_teacher_assignments` | Only in Core Dataset Readiness; must surface active-future-schedule blocker |
| Enrolment | Activate / pause / resume / close | `CanonicalEnrolmentLifecycleService`, `dzn_manage_canonical_enrolment_lifecycle` | Present on Enrolment detail |
| Enrolment / Term | Create / activate / close / cancel Term | `CanonicalTermAuthorityService`, `dzn_manage_canonical_terms` | Create on Term page; lifecycle on Term detail; needs contextual entry from Enrolment |
| Term / Lesson | Issue standard or replacement Lesson | `CanonicalLessonAuthorityService`, `dzn_manage_canonical_lessons` | Create form on Classes page and Core Dataset Readiness |
| Lesson | Schedule / revise / release | `CanonicalLessonScheduleService`, `dzn_manage_canonical_lesson_schedules` | Present on Lesson detail and Core Dataset Readiness |
| Lesson | Complete / cancel | `CanonicalLessonAuthorityService::complete/cancel`, `dzn_manage_canonical_lessons` | Only in Core Dataset Readiness |
| Lesson | Record delivery outcome | `CanonicalLessonDeliveryService`, `dzn_manage_canonical_lesson_delivery` | Only in Core Dataset Readiness |
| Lesson / Attendance | Review attendance and record adjudication | `CanonicalAttendanceIntakeService`, `dzn_manage_canonical_attendance_review` | Standalone route; manual case ID and idempotency key |
| Intro booking / Lesson | Record introductory outcome; retry post-intro preparation | `PostIntroPreparationService`, `PostIntroAdminController`, `dzn_manage_post_intro_automation` | Lesson detail for outcome; standalone Post-intro page for retry |
| Teacher | Edit profile; eligibility; availability; onboarding review; archive/restore/offboard | `TeacherOnboardingService`, `TeachingEligibilityService`, `TeacherAvailabilityService`, `TeacherLifecycleService` | Present on teacher detail, but not organised as contextual actions |
| Teacher | Record/close/withdraw rate | `TeacherRateService`, `dzn_manage_teacher_rates` | Missing UI |
| Teacher | Draft/issue/withdraw/supersede statement | `TeacherStatementService`, `dzn_manage_finance_statements` | Missing UI |
| Teacher | View reconciliation and payment history | `TeacherStatementReadService`, `TeacherRateReadService`, `dzn_view_finance_authority` | Read views exist; need teacher-context links |
| Lesson | Evaluate/override payability | `LessonPayabilityService`, `dzn_manage_lesson_payability` | Missing UI; read list exists |
| Student | Edit profile; manage status | No update authority found; `StudentService` only creates | Missing backend capability |
| Student | Manage enrolments, invitations, lesson history | Existing enrolment/claim/read services | Missing contextual read model and links |
| Course / Instrument | Edit course/instrument | `CatalogueService` only creates | Missing backend capability |
| Course / Instrument | Catalogue pricing and exceptions | `CommercialCatalogueService`, `CommercialExceptionService` | Existing separate screens; missing grouped read model |

## 5. Capability-gap report

### 5.1 Ready now: navigation and relocation only

These need no new domain authority:

- split `AdminShellController` navigation data into primary tabs, visible destinations, and hidden/technical routes;
- remove Academy sub-tabs and render the combined dashboard;
- reduce Teachers to two tabs and move rates/payability/reconciliation out of the Teachers tab;
- reduce Enrolment to four tabs with workspace/hub pages;
- move lesson payability to Finance;
- group Settings messaging screens without duplicating forms;
- keep all old routes registered and capability-protected.

### 5.2 Missing backend capabilities

| Gap | Why it is needed | Recommended decision |
| --- | --- | --- |
| Student profile update and status transitions | Student record actions require editing and active/inactive management; only `create` and archive exist. | Add an audited `StudentProfileService` using `dzn_manage_students`, with explicit allowed transitions. Decide whether “suspend” is distinct from inactive/archive. |
| Course and instrument update | Requirement includes contextual course/instrument editing; `CatalogueService` only creates. | Add audited update methods under `dzn_manage_courses`. Decide whether renaming an instrument cascades to auto-named courses or is blocked when generated names exist. |
| Teacher suspend/partial deactivation | Requirement says activate, suspend or deactivate “where supported”; only archive/restore/offboard exist. | Prefer archive/offboard as deactivate and restore+reinvite as reactivate unless a real suspend state is required. Do not invent a new state without policy. |
| Teacher rate write UI | `TeacherRateService` supports record/close/withdraw but no admin form. | Add contextual form on teacher record with `dzn_manage_teacher_rates`; keep effective-dated semantics and evidence. |
| Teacher statement generation | `TeacherStatementService` supports draft/issue/withdraw/supersede but no admin form. | Add contextual actions on teacher record with `dzn_manage_finance_statements`; prefill period from closed lesson period and show blockers. |
| Payability evaluate/override UI | `LessonPayabilityService` supports evaluate/override but no admin form. | Add on Finance → Lesson Payability and/or Lesson record; override remains separately capability-gated and evidenced. |
| Finance policy write UI | `FinancePolicyService` has record/supersede/withdraw but the screen is read-only. | Decide whether policy configuration is in scope now; if yes, add a tightly gated form under Finance → Policies. |
| Explicit lesson postponement | No `postpone` command exists. | Define postponement as the existing release-then-reschedule workflow; do not add a second schedule authority. |
| Per-lesson teacher change | Teacher change is assignment-level, not lesson-level. | Route “change teacher” through `TeacherAssignmentService::replace` on the Enrolment, with lesson context and future-schedule blocker. |
| Post-intro retry idempotency/context | Current retry takes preparation/job IDs and operation without record context. | Add contextual retry from booking/lesson and generate an idempotency key; confirm the service’s replay/audit contract. |
| Google Meet readiness evidence | Onboarding currently knows Google Calendar connection state, not operational Meet readiness. | Add a read-only readiness projection combining connection, calendar projection and meeting mapping/evidence. Never label “connected” as “Meet works”. |
| Onboarding blockers / next action | `reviewRows()` exposes states but not a complete blocker/next-action model. | Add a read model that returns each teacher’s completed steps, blockers and the next permitted action. |

### 5.3 Missing read models

| Read model | Consumers |
| --- | --- |
| Teacher 360: profile, onboarding progress, Google/Meet readiness, eligibility, availability, assigned students/lessons, rates, statements, reconciliation | Teachers → Overview/Onboarding; teacher record |
| Student 360: profile, enrolments, invitations/claims, lessons, term progress, status | Students → Directory/Profiles & Learning Progress; student record |
| Booking-to-enrolment journey: booking, coordination, arrangement, enrolment, assignment, term, lessons, blockers, next action | Enrolment → Bookings & Enrolments |
| Catalogue workspace: instrument → introductory/standard courses → products/prices → catalogue exceptions | Enrolment → Courses & Instruments |
| Attendance and post-intro operations: attendance cases, introductory outcomes, preparation exceptions, retryable failures, next action | Enrolment → Attendance & Post-intro |
| Lesson operations: schedule state, attendance case, delivery outcome, payability, next action | Lesson record; Finance → Lesson Payability |
| Finance teacher reporting: cross-teacher rates/statements/reconciliation with links to records | Finance → Reconciliation |

### 5.4 Shared UX, security and accessibility gaps

- No shared contextual-action component. Forms are inline and repeat raw IDs, evidence fields and idempotency keys.
- No server-side action-context resolver that derives the target record from the current page/route and rejects client-supplied cross-record IDs.
- No consequence-warning contract for scheduling, payment, notification and calendar effects.
- No shared unavailable-action explanation based on status + capability.
- Booking request detail currently renders raw object fields; generic list/detail projections can still leak internal IDs.
- Admin CSS uses physical margins (`margin-left`/`margin-right`) rather than logical properties; RTL behaviour is not regression-tested.
- Dialogs/focused forms are not standardised; mobile behaviour is limited to a media query.
- Navigation and route registration are coupled; tests derive routes from visible tabs, making hidden protected routes fragile.

### 5.5 Approved policy decisions

The product decisions for the first implementation phase are:

1. **Student status:** Active, Inactive, Suspended and Archived. Design the domain authority and reversible transitions before enabling the new states.
2. **Teacher suspension:** a distinct reversible Suspended state, separate from archive/offboard. Define its effects on availability, existing bookings and future lessons before implementation.
3. **Course/instrument renaming:** support editing without silently changing historical records. Preview dependent generated names and require explicit confirmation.
4. **Finance placement:** teacher-specific rates and statements live on individual teacher records; central reporting stays in Finance. Stripe operations stay in Finance; integration configuration belongs in Settings.
5. **Evidence:** generate system-owned timestamps, IDs and idempotency keys automatically. Require genuine operator evidence for attestations, agreements, attendance and consequential changes.
6. **Onboarding access:** allow appropriately scoped, read-only teacher access with permission and privacy checks.
7. **Advanced Recovery:** Settings → Advanced Recovery, restricted to authorised technical administrators.

Two policy details still require explicit definition during their own PRs: the exact student/teacher suspension transition rules, and the evidence fields required by each consequential action. PR 1 does not introduce either status model.

### 5.6 Earlier options considered

The original open questions were: whether to invent statuses before domain authority existed; whether renaming should cascade into generated names; whether Stripe configuration should be duplicated in Settings; and whether old teacher-finance tabs should redirect or remain central reports. The approved decisions in §5.5 resolve the product direction; implementation details are deferred to the relevant domain PRs.

## 6. Implementation plan in reviewable PRs

Each PR must include its own contract tests and must not weaken existing contracts.

| PR | Scope | Depends on | Primary tests |
| --- | --- | --- | --- |
| **0. Plan** | This document. No runtime changes. | — | Review only |
| **1. Navigation shell and Academy** | Split primary tabs from route registry; remove Academy sub-tabs; render combined Academy; keep `view` URLs compatible. | 0 | `admin-navigation-regression-contract`, `admin-shell-route-capability-runtime`, new target-navigation contract |
| **2. Shared contextual-action foundation** | Server-side record context resolver; generated idempotency keys; capability/state gate helper; focused form/dialog pattern; consequence warnings; RTL/mobile helpers; audit notice pattern. | 1 | New action-context unit tests; capability denial tests; RTL/mobile source contracts |
| **3. Teachers overview and onboarding** | Two tabs; onboarding blockers/next action; invitation lifecycle; profile completion; availability readiness; approval; Google connection vs Meet readiness; contextual teacher actions. | 1, 2 | Teacher admin UX, onboarding readiness, invitation, navigation contracts |
| **4. Teacher finance actions** | Teacher record rates, statements, reconciliation and payment history. Add rate/statement write forms and permission checks. | 2, 3, policy decisions | Phase 2A.2U contracts, finance status/audit tests, teacher navigation contract |
| **5. Student record actions** | Student profile edit/status; enrolment management; invitations; lesson history; avoid duplicate forms. Add missing student update authority if approved. | 2, policy decisions | Student admin UX, claim, enrolment, audit/state tests |
| **6. Enrolment workspaces** | Four tabs; booking-to-enrolment journey; contextual conversion, claim, initial assignment, replacement and term actions. | 1, 2, 5 | Student journey, conversion, assignment, term contracts |
| **7. Lesson and attendance actions** | Contextual schedule/revise/release, complete/cancel, attendance, delivery outcome, postponement, teacher replacement blocker. | 2, 6 | Lesson operator, schedule, delivery, attendance contracts |
| **8. Catalogue workspace** | Instrument→course grouping; pricing and exceptions; contextual course/instrument edit if approved. | 1, 2, policy decisions | Catalogue, commercial catalogue, exception contracts |
| **9. Attendance and post-intro workspace** | Unified operational view; introductory outcome monitoring; retryable failures; auto idempotency/context. | 2, 7 | Post-intro, attendance, runtime tests |
| **10. Advanced recovery and cleanup** | Move Core commands to a restricted Settings → Advanced Recovery; remove Core from everyday navigation only after contextual replacements pass; docs. | 6–9 | Core entrypoint contracts, capability denial tests, navigation contract |
| **11. UX hardening** | RTL, mobile, dialog accessibility, curated fields, route compatibility verification, acceptance smoke. | 1–10 | RTL/mobile contracts, acceptance harness, full CI |

Suggested merge order may combine PRs 8 and 9 if review capacity is limited, but navigation and action-foundation work should remain separate.

## 7. Shared contextual-action contract

Every new record action must follow this shape:

1. **Resolve context on the server.** The record type and ID come from the authenticated route/record, not from a hidden client field. Any posted target ID must be checked against that context.
2. **Authorise first.** Check the exact existing narrow capability. Composite navigation capabilities may only select a destination; they never authorise a domain action.
3. **Check state and permission.** Show only actions available for the current lifecycle state and actor. Explain unavailable actions in plain language.
4. **Collect only required input.** Prepopulate current values. Ask for evidence, agreement, reason or timestamp only where the domain authority requires it. Generate idempotency keys and system-owned metadata server-side.
5. **Warn about consequences.** Before submitting, show relevant scheduling, payment, notification and calendar effects in plain language.
6. **Call the authority.** Use the existing application service. Do not write SQL from the admin controller and do not create a parallel state.
7. **Preserve audit.** Keep the service’s command/event/audit records; show success or failure without exposing raw IDs or tokens.
8. **Support Persian/RTL and mobile.** Use logical CSS properties, accessible labels, keyboard-operable dialogs and responsive layouts.

## 8. Test and verification plan

### 8.1 Navigation and routing

- Exact target contract: Academy has zero sub-tabs; Teachers has exactly two; Enrolment exactly four; Finance includes Lesson Payability; Students preserves directory/progress.
- Every visible destination has a matching registered route and capability.
- Every hidden/technical route remains registered and capability-protected.
- Legacy URLs for Academy views and teacher-finance tabs still resolve or redirect safely.
- Restricted personas see only tabs they can use; no tab or direct route widens authority.

### 8.2 Record actions and state

- For each contextual action: allowed state, blocked state, wrong capability, wrong record, stale version and idempotent replay.
- Server-side context resolution rejects a posted ID that does not match the current record.
- Generated idempotency keys are unique, server-owned and stable for a retry.
- Required evidence is enforced only for actions whose domain rules require it.
- No action performs a direct write outside its application service.
- Audit trail and lifecycle events are recorded exactly once.

### 8.3 UX, accessibility and compatibility

- Dialogs/forms have labels, focus handling, error summaries and clear unavailable-action text.
- Persian/RTL layouts are checked with `is_rtl()` and logical CSS.
- Desktop and mobile viewport checks cover tab wrapping, table overflow and form usability.
- Curated record views contain no internal IDs, raw idempotency keys or provider secrets.
- Acceptance smoke covers at least: Academy landing, teacher onboarding, student profile action, booking-to-enrolment, lesson action, attendance/post-intro and finance payability.

### 8.4 Regression and CI

- Run the full `pr-runtime-gate` contract list, PHP lint and whitespace checks.
- Run the acceptance harness where Docker and synthetic fixtures are available; do not use production data.
- Update only the navigation contracts that encode the old intermediate structure. Do not weaken permission, finance, identity, scheduling or audit contracts.
- Require independent review for every PR, especially finance and lesson/attendance actions.

## 9. Rollout, compatibility and rollback

- Navigation changes are presentation-only and require no data migration.
- Keep all existing route slugs, controllers and capability checks registered throughout the migration.
- New workspace pages may use `view`/`section` parameters; direct record URLs remain canonical.
- If a contextual action is not yet verified, leave the old authority screen available as a protected direct route and keep the contextual action hidden or labelled unavailable.
- Only remove Core Dataset Readiness from everyday navigation after the corresponding record actions pass their contracts and acceptance checks.
- Rollback is a code revert of the navigation shell/workspace PRs; no state repair is required.

## 10. Acceptance-criteria traceability

| Acceptance criterion | Planned evidence |
| --- | --- |
| Academy opens directly without sub-tabs | Target navigation contract + shell runtime test + Academy page test |
| Teachers has exactly two tabs | Target navigation contract + teacher UX tests |
| Students retains directory and progress views | Student admin UX contract + directory/profile tests |
| Enrolment has four coherent tabs | Target navigation contract + workspace route tests |
| Finance owns lesson payability | Target navigation contract + Finance capability test |
| Teacher rates/reconciliation are teacher-record actions | Teacher 360 read model + teacher action tests + finance contracts |
| Booking/enrolment progression is contextual | Booking-to-enrolment journey tests + conversion/assignment contracts |
| Core dataset readiness not required for routine administration | Navigation contract + link audit + Core route hidden test |
| No manual internal IDs or idempotency keys | Action-context and generated-key tests + source contract prohibiting raw key fields in ordinary forms |
| Domain/payment/permission/audit protections remain | Full existing contract suite + capability/state/audit tests |

## 11. Implementation status

The seven product decisions in §5.5 are approved. Implementation starts with **PR 1 (navigation shell + Academy)** and **PR 2 (shared contextual-action foundation)**. Those two are low-risk, unblock the later record work, and do not require production data or deployment.

PR 1 is presentation-only: it does not add student/teacher statuses, change catalogue rename behaviour, or alter any domain authority. The temporary Enrolment workspaces link to the existing authorised workflows until the contextual replacements in the later PRs are verified.

No production configuration, records, services or deployments were touched in preparing this plan.
