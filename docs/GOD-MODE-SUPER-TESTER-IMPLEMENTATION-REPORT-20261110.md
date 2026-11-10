# God Mode / Super Tester — Implementation Report (2026-11-10)

## 1. Scope and status

This implementation adds a permanent, server-authorized testing capability for the verified
WordPress account associated with `hamed.moqadasi@yahoo.com`. It is a working platform capability,
not a set of hardcoded UI shortcuts.

Implemented in this branch:

- Dedicated user capability and persisted authority by WordPress user ID.
- A single English-only **Admin → God Mode** dashboard with six tabs.
- Explicit test-session aggregate with `activity_type = test` and append-only evidence.
- Direct and Workflow test booking modes, conflict acknowledgement and teacher participation.
- Real Google Calendar / Meet provisioning through the existing classroom service.
- Separate recording-configuration and recording-availability verification.
- Notification tests over the real portal/email/Telnyx pathways, with an observed SMS fallback test.
- Test scenarios covering the required teacher, Google, attendance, notification and end-to-end areas.
- Financial isolation at the domain boundary and in finance read models.
- Read-only, dependency-aware legacy student-data inventory and a recoverable inventory snapshot.
- Safe test-data archive and permanent-cleanup rules that cannot delete genuine records.

Not executed (requires separate approval):

- Destructive cleanup of the account's previous student records.
- Production deployment, merge, or provider-side deletion of retained recordings.

## 2. Architecture decisions

### 2.1 Dedicated test aggregate instead of a fake canonical Lesson

A test session is stored in `dzn_god_mode_sessions`, not in `dzn_lessons` or its canonical
Enrolment/Term stack. This is deliberate:

- It cannot accidentally create teacher payability, term consumption, attendance settlement,
  post-intro automation or payment obligations.
- It avoids fabricating the canonical invariants that the production booking/enrolment services
  rely on.
- It keeps test occupancy non-authoritative, so a test class can never block a genuine student.

The existing aggregates still receive additive `activity_type` / `god_mode_session_id` columns.
Those columns make future test-aware policy explicit and ensure financial/reporting code can reject
test activity even if a future migration introduces linked rows.

### 2.2 Reused production services

| Concern | Reused path |
| --- | --- |
| Teacher occupancy conflict evidence | `BookingTeacherOccupancyReadService` |
| Calendar/Meet provisioning, co-host assignment, retries | `GoogleWorkspaceClassroomService`, `GoogleWorkspaceRepository` |
| Meeting access reference vault | `ProviderReferenceVault` |
| Recording/participant evidence | `GoogleWorkspaceApiClient` conference/recording/participant APIs |
| Email hand-off | `DelnavazanEmailRenderer` |
| Portal inbox | `dzn_portal_notifications` (isolated `god_mode_test` source kind) |
| SMS / WhatsApp | `TelnyxSmsAdapter`, `TelnyxWhatsAppAdapter` explicit test methods |
| Teacher principal authority | `dzn_teacher_principal_links` + `dzn_view_own_portal_schedule` |

### 2.3 Authority model

- Capability: `dzn_access_god_mode`.
- Authority is persisted in `dzn_god_mode_authorisations` by `wordpress_user_id` and a keyed
  digest of the account's email.
- Every authority decision verifies **all** of:
  1. the stored designated user ID equals the current user ID;
  2. the account still has `manage_options`;
  3. the account holds the dedicated capability;
  4. the authority row is `active` (not revoked);
  5. the current email digest matches the stored digest using `hash_equals`.
- No God Mode role is created. Ordinary administrators cannot self-assign.
- Revocation removes the capability and marks the authority row revoked. Historical test rows keep
  their classification and remain auditable.

### 2.4 Test classification lifecycle

`activity_type = test` is written at test-session creation and never depends on current
permissions. The classification travels through:

- session creation and every timeline event;
- provider provisioning/retry/reschedule/cancellation;
- attendance and completion evidence;
- notification attempts and scenario runs;
- cleanup eligibility and audit receipts.

## 3. Database migration

Migration: `074_god_mode_super_tester`. The package keeps `DZN_PLATFORM_SCHEMA_VERSION = '60'` for
compatibility with the existing release/runtime convention; the migration ledger carries the new
`074_god_mode_super_tester` entry, exactly as the preceding `073` migration does.

New tables:

- `dzn_god_mode_authorisations`
- `dzn_god_mode_sessions`
- `dzn_god_mode_session_events`
- `dzn_god_mode_scenario_runs`
- `dzn_god_mode_audit_events`
- `dzn_god_mode_notification_tests`
- `dzn_god_mode_cleanup_receipts`

Additive columns:

- `dzn_lessons.activity_type`, `dzn_lessons.god_mode_session_id`
- `dzn_enrolments.activity_type`, `dzn_enrolments.god_mode_session_id`
- `dzn_booking_requests.activity_type`, `dzn_booking_requests.god_mode_session_id`
- `dzn_meet_classrooms.test_session_id` plus `test_session_active`

The migration creates no canonical Lesson, Enrolment, Term, payment, invoice or notification
campaign. It resolves the designated WordPress account once, requires `manage_options`, records the
authority row by user ID and grants the per-user capability.

## 4. Security controls

- All privileged admin actions are `admin_post_` handlers with `check_admin_referer`.
- Every handler calls `GodModeAuthority::require()`; the menu destination is capability-filtered.
- No public AJAX or unauthenticated route is added.
- Teacher actions re-prove the `teacher_principal_links` ownership and the teacher portal capability.
- Teacher actions require explicit `activity_type = test`; they grant no God Mode capability.
- Provider references remain vaulted; the inspector never prints raw OAuth tokens, webhook secrets
  or vaulted join references. Diagnostic export excludes recipient addresses and recording content.
- Every privileged POST and security-sensitive settings change writes an append-only audit event.
- Settings cannot change the designated identity. The identity is fixed server-side by verified
  WordPress user ID.
- Revocation is explicit and audited.

## 5. Financial isolation

- Test sessions never create canonical Lessons or Enrolments, and therefore no term balance,
  attendance settlement, commerce obligation or teacher assignment record.
- `LessonFinanceSnapshotService::capture()` refuses any Lesson whose `activity_type` is `test`
  (`finance_test_activity_isolated`).
- Teacher finance summary queries explicitly exclude `activity_type = 'test'`.
- Academy operational counts and teacher workspace metrics exclude test-classified Lessons and
  Enrolments.
- Test notification paths only call explicit transport tests; they cannot trigger payment reminders,
  renewal campaigns or enrolment messages.
- Payment-link scenario produces a non-chargeable simulated reference; it makes no Stripe call and
  creates no invoice or charge.

## 6. Booking behaviour

### Direct Test Mode

1. Validate teacher, instrument/course, timezone, local date/time and duration.
2. Read genuine occupancy only for conflict evidence.
3. If a genuine or test conflict exists, require explicit acknowledgement.
4. Create the isolated test session and booking event.
5. Provision Calendar/Meet synchronously when Google is configured.
6. Record requested, attempted, confirmed, failed or not-applicable evidence per stage.

### Workflow Test Mode

1. Create the workflow test session and teacher-assigned evidence.
2. The participating teacher accepts in the normal teacher portal.
3. Server-side provisioning runs after acceptance, using the same classroom pathway.
4. Teacher or operator records attendance/completion; no finance snapshot is created.

Test sessions never appear as canonical occupancy and therefore cannot block genuine availability.

## 7. Teacher portal

- Test classes are returned in a separate `god_mode_test_classes` model.
- They render in a clearly labelled **Test classes** section on the teacher Home and Classes screens.
- The teacher can join during the normal join window, accept a workflow test, reschedule, cancel and
  confirm attendance.
- The section explains that the class has no financial effect and is not counted in real performance
  metrics.
- Teacher language, calendar and timezone preferences are preserved.

## 8. Dashboard

Single route: `admin.php?page=dzn-god-mode`.

Tabs:

1. **Overview** — test-only metrics, recent activity and design principles.
2. **Book Test Class** — teacher, instrument, scenario, mode, date, time, timezone, duration,
   recording, notification plan, description, conflict acknowledgement; Check Conflicts and Preview.
3. **Test Scenarios** — all required scenario groups with per-run prerequisites/steps and run actions.
4. **Test History** — filters by teacher, status, scenario, mode, result, date range, search and sort;
   actions include inspect, join, duplicate, retry, cancel, archive and eligible removal; bulk
   archive/removal.
5. **Session Inspector** — chronological timeline, provider evidence, errors, retry eligibility,
   notification attempts, participant/recording verification and sanitized diagnostic export.
6. **Settings** — identity display, timezone/duration/mode, notification defaults, approved
   recipients, recording default, retention, automatic eligibility, conflict and diagnostic
   preferences, legacy inventory/snapshot and age-based archive pass.

The dashboard uses `dir="ltr"` and contains no Persian labels. All labels, messages and
confirmation text are English.

## 9. Scenario library

Covered areas:

- Teacher/booking: onboarding, introductory and direct booking, assignment, acceptance, conflict,
  cancellation, rescheduling.
- Google: Calendar event, Meet provisioning, invitation, host/co-host, teacher/student join,
  recording configuration, recording availability.
- Attendance/enrolment: automatic participant evidence, manual attendance, completion, continuation,
  payment-link simulation, enrolment simulation.
- Notifications: portal, email, WhatsApp, primary SMS, SMS fallback, failure/retry.
- End-to-end: teacher onboarding, introductory class, regular class, Meet+recording, booking to
  attendance.

Result vocabulary: `not_tested`, `running`, `passed`, `failed`, `warning`,
`awaiting_manual_verification`.

Honesty rules:

- Human participation, actual participant joining and actual recording availability are never
  fabricated.
- Transport hand-off is reported as `warning` unless delivery evidence exists.
- A successful WhatsApp message is not reported as a passed SMS-fallback test; the fallback condition
  must actually be observed.
- `notification_failure_retry` remains awaiting manual verification until an operator induces and
  retries a controlled failure.

## 10. Legacy student-data cleanup preparation

The inventory is read-only and dependency-aware. It groups records by domain, marks financial,
consent and identity records as retained/immutable, and never executes deletion.

Prepared artefacts:

- Dry-run inventory in Settings.
- Recoverable inventory snapshot (record IDs/counts and classifications) written atomically with
  restrictive permissions under the uploads archive directory.
- Audit event for every inventory/snapshot run.

Not yet executed:

- Any deletion, anonymisation or financial-record correction.
- Any copying of full row contents or recording artifacts.

A destructive cleanup still requires a separate approved runbook and a full database backup.

## 11. Test and regression evidence

Added tests:

- `platform/tests/god-mode-rule-unit.php`
- `platform/tests/god-mode-scenario-library-unit.php`
- `platform/tests/god-mode-schema-contract.php`
- `platform/tests/god-mode-security-contract.php`
- `platform/tests/god-mode-dashboard-language-contract.php`
- `platform/tests/god-mode-financial-isolation-unit.php`
- `platform/tests/god-mode-cleanup-contract.php`
- `platform/tests/god-mode-teacher-participation-contract.php`
- `platform/tests/god-mode-runtime.php` (WP-CLI disposable runtime smoke; not part of source CI)

These are added to the PR runtime gate. Local results on this branch:

- All God Mode tests pass.
- PHP lint across all tracked PHP files passes.
- Existing Google Meet, admin shell/navigation, finance clarity, teacher portal phase 2, notification
  management and portal completion contracts pass.
- PR runtime gate runs `42719315075`, `42726603110` and `42727508392` passed every job, including PHP
  source contracts, Finance, Booking release, interim attendance, Students workspace, catalogue,
  promotion, post-intro, recording, Stripe, OAuth and CodeQL.
- A dedicated **God Mode runtime** job now installs the plugin from scratch in disposable
  WordPress/MariaDB, verifies the `074` ledger entry, all God Mode tables/columns, performs a
  test-session lifecycle with participant mapping and cleanup, and asserts that the finance-snapshot
  count is unchanged. It passed in runs `42726603110` and `42727508392`.

Relevant pre-existing issue discovered:

- `platform/tests/teacher-today-classes-settings-contract.php` fails on baseline `main` because
  `theme/template-parts/components/classroom-actions.php` no longer contains the `meet_label` field
  the test expects. It is unrelated to God Mode and was not modified.

## 12. Unresolved limitations

1. **No live Google provider runtime was executed here.** The local workstation had no Docker, so
   the dedicated WP-CLI God Mode smoke was not run locally. The PR runtime gate did install the
   migration and schema in disposable MariaDB runtimes successfully, but production-like provider
   behaviour still requires a configured Google organizer grant.
2. **Automatic attendance ownership mapping still needs an explicit operator mapping.** Official
   Meet evidence exposes opaque signed-in account resource names, not email addresses. The
   implementation now records participant digests and requires an explicit teacher/student mapping
   before attendance can be reported as verified. Unmapped evidence is a warning, never a pass.
3. **Student-portal test joining is not a separate authenticated student journey.** The isolated test
   aggregate uses a synthetic student and does not contaminate the designated account's real student
   profile. The admin/teacher join path and provider join reference are real.
4. **Legacy cleanup snapshot is a manifest, not a full data backup.** A destructive cleanup requires
   an operational database backup in addition to the manifest.
5. **No scheduled automatic cleanup worker was added.** Age-based archiving is an explicit operator
   action; permanent removal remains explicit, audited and eligibility-checked.
6. **Recordings are never deleted by cleanup.** Sessions with configured/verified recording evidence
   are refused for permanent removal; those need a retention/legal review.

## 13. Production cleanup plan (requires approval)

1. Take a verified full database and uploads backup.
2. Run the God Mode inventory and write the recoverable snapshot.
3. Review the dependency report with finance/legal owners.
4. Archive, do not delete, financial and consent-bearing evidence.
5. Use existing domain lifecycle services for cancellations/closure, not raw SQL.
6. Remove only genuine test-unrelated student experience records after a second dry run.
7. Re-run the inventory and confirm no orphaned jobs, provider events or balances.
8. Record the approved cleanup receipt and audit evidence.

## 14. Deployment readiness and recommended order

1. Merge the schema migration and code together.
2. Deploy to a staging/disposable WordPress + MariaDB environment and run:
   - `php platform/tests/god-mode-*.php`
   - `wp eval-file platform/tests/god-mode-runtime.php`
   - existing Google Meet and finance regression contracts.
3. Verify the designated account resolves and the God Mode tab appears only for that account.
4. Verify normal administrator access and normal student/teacher flows are unchanged.
5. Confirm Google organizer connectivity before expecting Meet provisioning to report `ready`.
6. Enable production deployment only after review. Do not run destructive cleanup as part of the
   deployment.

## 15. Independent review hardening (2026-11-10)

The review identified and fixed the following:

- **Migration prefix bug:** `verify_god_mode_super_tester_schema()` passed unprefixed index table
  names to `has_index_columns()`. Every disposable runtime failed activation until this was fixed.
  The verifier now uses the real `$wpdb->prefix . 'dzn_'` table names.
- **Schema compatibility:** the package constant remains `60`; `074_god_mode_super_tester` is the
  ledger entry, exactly like the preceding `073`. The migration is now also verified early whenever
  its ledger entry is present.
- **Revocation during rerun:** the migration bootstrap no longer re-adds the capability when the
  authority row is already revoked.
- **Participant evidence mapping:** added `dzn_god_mode_participant_mappings`, opaque participant
  digests, explicit teacher/student role mapping, and verified-attendance outcomes. The scenario
  passes only when both roles are mapped and participant sessions exist.
- **Recording evidence:** conference records must belong to the classroom space; a recording is only
  `verified` when a finalised artifact has a Drive destination. Configuration is reported separately.
- **Scenario honesty:** added `framework_gap` metadata and early gating for incomplete scenarios.
  Framework-gap scenarios cannot create synthetic test sessions or report `passed`. Google scenarios
  now require confirmed event/invitation/co-host/recording evidence. Cancellation/rescheduling only
  pass when a ready provider event was actually exercised.
- **Asynchronous financial isolation:** completion candidate selection, renewal reminders and
  canonical academy obligations now exclude/refuse test-classified activity. Finance snapshot
  capture remains the final domain guard.
- **Teacher isolation:** added a source contract proving test classes remain a separate teacher read
  model and do not enter finance or workload metrics.
- **Audit redaction:** phone numbers and bearer credentials are redacted from audit detail.
- **Teacher provisioning authorization:** the non-God-Mode provisioning path now re-proves the
  owning teacher principal and the `teacher_accepted` workflow state.
- **Cleanup runbook:** added `docs/GOD-MODE-LEGACY-CLEANUP-RUNBOOK.md` with full backup, dry run,
  rollback, approvals and separation of test/financial evidence.

## 16. Pre-existing teacher-classes contract failure

`platform/tests/teacher-today-classes-settings-contract.php` fails on baseline `main` because
`theme/template-parts/components/classroom-actions.php` does not contain the `meet_label` field the
test expects, while `classes.php` passes that argument. This is a pre-existing compatibility gap in
the shared classroom-actions component.

It does **not** affect God Mode teacher portal behaviour: test classes use
`theme/template-parts/teacher-portal/test-classes.php` and its own server-issued join/accept/
reschedule/cancel/attendance forms. No correction was made in this PR; it should be handled in a
separately scoped theme fix.

## 17. Requirement review table

| Review requirement | Status | Supporting files | Evidence |
| --- | --- | --- | --- |
| 1. Lifecycle financial isolation, including async, attendance, completion, retries, reporting and statements | Verified | `LessonFinanceSnapshotService.php`, `CanonicalLessonAuthorityRepository.php`, `RenewalReminderWorker.php`, `CanonicalAcademyObligationService.php`, `TeacherFinanceSummaryReadService.php`, `AcademyDashboardReadService.php`, `TeacherWorkspaceReadService.php` | `god-mode-financial-isolation-unit.php`, PR runtime gate finance/booking/attendance jobs |
| 2. Migration 074 compatibility, ordering, idempotency, interrupted upgrades, existing DBs | Verified | `Migrator.php`, `delnavazan-platform.php` | `god-mode-migration-contract.php`, `god-mode-schema-contract.php`, dedicated God Mode CI runtime plus fresh-install runtime jobs |
| 3. Direct/Workflow modes exercise genuine services without false claims | Partially Verified | `GodModeBookingService.php`, `GodModeScenarioLibrary.php`, `GodModeScenarioRunner.php` | Genuine teacher-portal acceptance, provider provisioning, rescheduling, cancellation and attendance are exercised. Real booking-request, continuation and enrolment pipelines are explicitly `framework_gap` and cannot pass. |
| 4. Meet provisioning, co-host, recording verification, participant identity mapping | Verified | `GoogleWorkspaceClassroomService.php`, `GodModeRecordingVerifier.php`, `GodModeAttendanceVerifier.php`, `GodModeSessionService.php` | `god-mode-scenario-honesty-contract.php`; mapping requires explicit verified roles; recording requires finalised artifact + space match + Drive destination |
| 5. Scenario Passed means intended behaviour verified; incomplete scenarios gated | Verified | `GodModeScenarioRunner.php`, `GodModeScenarioLibrary.php` | `god-mode-scenario-honesty-contract.php`, `god-mode-scenario-library-unit.php` |
| 6. English-only dashboard, navigation, settings, errors, diagnostics, filters, cleanup | Verified | `GodModeView.php`, `GodModeController.php`, `Menu.php`, `AdminShellController.php`, `god-mode.css` | `god-mode-dashboard-language-contract.php` |
| 7. Authority, revocation, endpoints, nonces, audit, sensitive data, cleanup authorization | Verified | `GodModeAuthority.php`, `GodModeAudit.php`, `GodModeController.php`, `GodModeTeacherController.php` | `god-mode-security-contract.php`, `god-mode-rule-unit.php`, `god-mode-cleanup-contract.php` |
| 8. Teacher visibility without earnings/statements/workload/capacity | Verified | `PortalAuthenticatedReadService.php`, `GodModeTeacherReadService.php`, teacher portal templates, finance/workload reads | `god-mode-teacher-participation-contract.php`, `god-mode-teacher-isolation-contract.php` |
| 9. Cleanup runbook with backup, inventory, dry run, rollback, evidence separation | Verified (runbook only; not executed) | `docs/GOD-MODE-LEGACY-CLEANUP-RUNBOOK.md`, `GodModeInventoryService.php` | Runbook reviewed; inventory contract and dashboard dry-run controls; destructive cleanup not executed |
| 10. Investigate pre-existing teacher-classes failure | Verified (analysis only) | `theme/template-parts/components/classroom-actions.php`, `classes.php`, `test-classes.php` | Failure reproduced on `main`; God Mode uses its own template, so no God Mode impact. Unrelated fix intentionally not included. |

**Blocked items (require external approval/runtime):**

- Live production Google organizer evidence for `ready`/`verified` states.
- Full participant email mapping requires a Google Directory/Admin integration or explicit operator
  mapping; email addresses are not exposed by the Meet participant API.
- Destructive cleanup of the account's previous genuine student records.
- Production deployment and merge.
