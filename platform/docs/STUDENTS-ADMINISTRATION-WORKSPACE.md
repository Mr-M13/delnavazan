# Students Administration Workspace

**Branch:** `feat/students-administration-workspace-20261102`
**Base:** `origin/main` at `fb1625b5`
**Status:** implemented, runtime-tested, screenshot-reviewed. Not merged and not deployed.

## 1. Outcome

Students is one student-centred workspace:

1. The directory opens immediately with current active students ranked by recent activity.
2. Inactive and archived records remain findable through the status filter and search.
3. A profile contains details, identity/account/guardian, enrolment, courses/teachers, terms/renewals, lessons/attendance, finance, notifications, consent, tasks and administrative actions.
4. Student-specific mutations use the shared contextual RecordAction endpoint and existing canonical services.
5. The specialist identity-authority route remains protected and registered, but it is no longer a visible Students submenu.

## 2. Discovery mapping

| Profile section | Existing owner reused |
|---|---|
| Student details | `dzn_students`, `StudentService`, `Normalizer`, `StudentRepository` |
| Identity / account | `student_principal_links`, `StudentAcceptanceAuthorityService`, `StudentAccountClaimInvitationService`, `StudentIdentityResolutionService` |
| Guardian | `student_acceptance_authority_grants`, `StudentAcceptanceAuthorityService` |
| Enrolment | `enrolments`, `booking_requests`, `booking_request_requested_times`, `booking_request_confirmations`, `CanonicalEnrolmentLifecycleService` |
| Courses / teachers | `enrolments`, `courses`, `instruments`, `teacher_assignments`, `TeacherAssignmentService` |
| Terms / renewals | `terms`, `renewal_cycles`, `recurring_enrolments`, `CanonicalTermAuthorityService`, `TermReplacementAllocationRule` via `LessonRescheduleRepository` |
| Lessons / attendance | `lessons`, `lesson_schedule_versions`, `canonical_lesson_schedule_versions`, `CanonicalLessonScheduleService`, `canonical_lesson_delivery_outcomes`, `CanonicalAttendanceIntakeService`, `canonical_attendance_*` |
| Finance | `commercial_offers`, `commercial_offer_obligations`, `commercial_obligation_settlements`, `commercial_payment_evidence`, `commercial_promotion_redemptions`, `checkout_sessions`, `intro_payment_obligations` |
| Notifications | `notifications`, `portal_notifications`, `telnyx_messages`, `notification_suppressions`, `NotificationDeliveryStatusReadService` |
| Consent | `recording_decisions`, `RecordingDecisionAuthorityService` constants |
| Tasks | `operational_exceptions`, `ExceptionService` |

## 3. Directory behaviour

- Default filter: `active` current students.
- Default sort: `recent` descending.
- Recent activity is the deterministic `GREATEST()` of canonical timestamps: completed lessons, class creation, enrolment updates, booking-request updates, next scheduled lesson and student creation.
- `introductory`: a student with a non-expired booking request (`submitted`, or `closed` with `teacher_confirmed`).
- `awaiting_enrolment`: no current enrolment but a non-expired introductory request.
- `renewal_due`: an active renewal cycle is not yet `term_bound`, `closed`, `lapsed` or `cancelled`.
- `payment_due`: an actionable offer has an unsettled obligation with a positive amount.
- Search covers name, display name, email, phone, WhatsApp, public student ID, instrument, assigned teacher and — only with acceptance-eligibility authority — guardian name/email.
- Phone search compares digits only after removing spaces, dashes, plus signs and brackets.
- Inactive and archived records are available through the Inactive, Archived and All filters and search.
- No more than 20 records are loaded per page.

## 4. Profile behaviour and action audit

Legend: **Profile** = completed inside the student profile; **Linked** = central workflow opened with the exact student/record and a validated return link; **Read-only** = no authorised administrator action exists; **Missing** = not implemented.

| Section | Visible action | Status | Evidence / authority |
|---|---|---|---|
| Details | Edit student details | Profile | `student-profile` → `StudentAdministrationService::updateProfile` |
| Details | View public ID, email, contact, timezone | Read-only | canonical `dzn_students` |
| Identity / account | Send password reset | Profile | WordPress `retrieve_password()` with rate limit; never sets a password |
| Identity / account | Send account claim | Profile | `StudentAccountClaimInvitationService`; only when no active account |
| Identity / account | Link verified account | Profile | `StudentAcceptanceAuthorityService::establishPrincipal` |
| Identity / account | Replace linked account | Profile | `StudentAcceptanceAuthorityService::supersedePrincipal` |
| Identity / account | Deactivate linked account | Profile | `revokePrincipal`; WordPress user retained |
| Identity / account | Record age / authority category | Profile | `StudentAcceptanceAuthorityService::classify` |
| Identity anomaly | Principal-link conflict | Profile | Evidence shows both accounts; `student-supersede-account` |
| Identity anomaly | Account email mismatch | Profile | Evidence shows both emails; `student-supersede-account` |
| Identity anomaly | Account ownership unverified | Profile | Evidence shows claim state; replace-link action plus protected authority link |
| Identity anomaly | Guardian required but missing | Profile | Evidence shows capacity and active-grant count; guardian grant / capacity actions |
| Identity anomaly | Capacity unknown | Profile | Reviewed capacity action |
| Identity anomaly | Multiple guardian grants | Profile | Grant list evidence; supersede/revoke actions |
| Identity anomaly | Unresolved booking matches student | Profile | Contact-match evidence; resolve booking or record non-resolved outcome |
| Identity anomaly | Duplicate student candidate | Linked | Candidate table and protected identity authority; no safe merge authority exists |
| Identity anomaly | Linked booking has no resolution event | Linked | Protected identity authority; current authority cannot safely re-resolve a linked request |
| Guardian | Grant guardian authority | Profile | `grantGuardian`; no relationship or authority inferred from age |
| Guardian | Replace guardian authority | Profile | `supersedeGuardian` |
| Guardian | Revoke guardian authority | Profile | `revokeGuardian` |
| Guardian | View relationship | Read-only | No relationship field exists in the canonical grant; shown as representative (service acceptance) |
| Enrolment | Open introductory request | Linked | Exact `request_id` in Bookings; return link to profile |
| Enrolment | Open coordination case | Linked | Exact `case_id`; return link |
| Enrolment | Activate / pause / resume / close enrolment | Profile | `CanonicalEnrolmentLifecycleService` |
| Enrolment | Open exact enrolment | Linked | Exact enrolment id; return link |
| Enrolment | Start / change enrolment | Linked | `dzn-enrolment&student_id=…`, filtered list, return link |
| Courses / teachers | Replace teacher | Profile | `TeacherAssignmentService::replace` with staff-attested evidence |
| Courses / teachers | Change course / instrument | Linked | No single canonical in-profile course-change command; Enrolments workflow with student filter |
| Terms | Activate / close / cancel term | Profile | `CanonicalTermAuthorityService` |
| Terms | Open exact term | Linked | Exact term id; return link |
| Terms | Review renewal | Linked | Student-filtered Enrolments; read-only renewal milestone in profile |
| Lessons | Open exact lesson | Linked | Exact lesson id; return link |
| Lessons | Revise schedule | Profile | `CanonicalLessonScheduleService::revise` with current assignment/version |
| Lessons | Review attendance | Profile | `CanonicalAttendanceIntakeService::reassess/adjudicate` with current case version |
| Lessons | Decide reschedule proposal | Profile | `LessonRescheduleService::confirm/cancelAsAdministrator` |
| Lessons | Browse older history | Profile | Server-side pagination, real total, 50 per page |
| Finance | View summary / instalments / discounts / history | Read-only | Canonical finance projections; no in-profile plan-change authority |
| Finance | Open exact student in Finance | Linked | Finance search by offer reference; return link |
| Notifications | View evidence / inbox / channels / suppression | Read-only | No per-notification administrator retry authority exists |
| Notifications | Open communication diagnostics | Linked | Notifications diagnostics; return link |
| Consent | View recording/research decisions and history | Read-only | Current recording authority disables writes; historical evidence unchanged |
| Consent | Learning-content uploads | Read-only | No canonical storage/upload workflow exists in this release |
| Tasks | Acknowledge / resolve / dismiss exception | Profile | `ExceptionService`; same authority as Overview Action Centre |
| Tasks | Payment/renewal/notification derived blockers | Linked | Explain missing action and open the relevant canonical workflow |
| Administrative | Deactivate / archive / restore / reactivate | Profile | Distinct meanings, dependency checks, no protected history deletion |
| Administrative | Start re-enrolment | Linked | Same student identity, filtered Enrolments workflow, return link |
| Administrative | WordPress account handling | Profile | Principal authority only; never deletes the WordPress user |

### Remaining exceptions to in-profile design

- **Duplicate candidate merge:** no canonical merge authority exists. The profile shows candidates and opens the protected identity route; it does not fabricate a merge.
- **Already-linked booking with no resolution event:** the canonical resolver only resolves unlinked requests. The profile explains this and opens the protected identity route.
- **Course/instrument change:** no single canonical operator command creates a replacement course relationship. The profile opens the student-filtered Enrolments workflow.
- **Payment-plan change, settlement and refund:** these belong to the Finance authority. The profile opens the exact student in Finance and never re-prices or records a payment itself.
- **Notification retry:** no per-notification administrator retry command exists. The profile shows evidence and opens diagnostics; it does not bypass the dispatch policy.
- **Recording/AI writes:** current authority is disabled for capture/research export. Consent history is read-only.
- **Guardian relationship label:** the canonical grant has authority type/scope but no relationship field. The UI does not invent one.

## 5. Lifecycle and identity cleanup

- **Deactivate** sets the student to `inactive`. History and the WordPress user remain.
- **Archive** uses `ArchiveService`; refused while operational enrolments depend on the student. Protected history and the WordPress user remain.
- **Restore** makes an archived profile visible again as `inactive`. It does not restore portal access.
- **Reactivate** returns an inactive student to `active`. It does not restore portal access.
- **Account deactivation** revokes the Student principal link only.
- **Account linking/replacement** uses `StudentAcceptanceAuthorityService`.
- **Guardian grant/revoke/supersede** uses the same representative-authority service.
- **Password reset** calls WordPress `retrieve_password()`, never displays or sets a password, is rate-limited per student, and never claims confirmed delivery.
- **Account claim** uses `StudentAccountClaimInvitationService`.
- **Re-enrolment** reuses the same student identity, restores/reactivates where required, and opens the canonical Enrolments workflow. No duplicate student or account is created.

Raw technical controls remain available only on the protected identity-authority route. They are not rendered in the Students workspace:

- Create distinct unresolved student
- Record non-resolved review outcome
- Classify access
- Establish / revoke / supersede Student principal link

## 6. Security and integrity

- Every read is gated by `dzn_manage_students`; finance and communications require their own authorities.
- Every write re-checks its narrow capability at the RecordAction boundary and inside the application service.
- Record target ids are re-resolved server-side; signed context and nonce protections remain unchanged.
- No `$wpdb` writes exist in the Students controller.
- No password, password hash, provider secret, token digest or raw identity-link id is rendered.
- Guardian, principal, payment, attendance, consent and notification authorities are reused rather than reimplemented.
- Archive never cascades to WordPress users or protected history.
- Add Student rejects an existing email or normalized phone identity and uses a hashed form token for idempotency.
- Deep links use a validated `dzn_return_to` destination; redirects preserve it only within the admin host.

## 7. Tests and evidence

### Source contracts

- `platform/tests/student-workspace-filters-unit.php`
- `platform/tests/student-workspaces-contract.php`
- `platform/tests/student-admin-ux-contract.php`
- `platform/tests/record-action-registry-unit.php`
- `platform/tests/attendance-runtime-midnight-guard-contract.php`
- `platform/tests/static.php`
- existing teacher, admin navigation, dashboard, student journey and onboarding contracts

### Disposable WordPress/MariaDB runtime

- Runner: `platform/runtime/bin/run-student-workspace-runtime.sh`
- Journey: `platform/tests/student-workspace-runtime.php`
- Passes: Add Student duplicate rejection; active/inactive/archived directory filters; returning identity reuse; deactivate/archive/restore/reactivate; account link/deactivate/relink; preserved lesson/payment/attendance/consent history; minor + guardian grant/supersede/revoke; capacity anomaly; principal conflict resolution; account email mismatch resolution; matching booking identity resolution; canonical enrolment pause/resume; term creation; 12-lesson history pagination; in-profile lesson reschedule; in-profile action visibility.
- The runtime also proves archiving a student with an operational enrolment is refused.

### Screenshots

Directory and profile at desktop, tablet and mobile:

- `platform/docs/evidence/students-workspace-20261102/students-directory-desktop.png`
- `platform/docs/evidence/students-workspace-20261102/students-profile-desktop.png`
- `platform/docs/evidence/students-workspace-20261102/students-directory-tablet.png`
- `platform/docs/evidence/students-workspace-20261102/students-profile-tablet.png`
- `platform/docs/evidence/students-workspace-20261102/students-directory-mobile.png`
- `platform/docs/evidence/students-workspace-20261102/students-profile-mobile.png`
- `platform/docs/evidence/students-workspace-20261102/students-profile-account-desktop.png`

### Attendance CI fixture

The former interim-attendance failure was a deterministic fixture defect: the LiveKit runtime scheduled `now + 20 minutes` for 30 minutes against a `00:00:00–23:59:59` UTC availability window. Near UTC midnight the lesson crossed into the next day and was refused as `teacher_unavailable`.

Fixed by `dzn_lk_runtime_safe_wall()` in `platform/tests/livekit-classroom-runtime.php`, with the guard contract `attendance-runtime-midnight-guard-contract.php`. The attendance runtime is expected to rerun green.

## 8. Known limitations

- Guardian “relationship” is not a stored field in the canonical grant model; the UI labels it `Representative (service acceptance)`.
- Duplicate-candidate merge and already-linked booking re-resolution remain protected specialist operations because no safe automatic authority exists.
- Course/instrument changes remain in the Enrolments workspace; the profile opens the student-filtered workflow.
- Finance plan changes, settlement and refunds remain in the Finance workspace; the profile opens the exact student there.
- Notification retry remains with the canonical dispatch/worker policy; no per-notification admin retry command exists.
- The directory query is bounded but intentionally rich; very large academies may add covering indexes or a materialised activity projection later.

## 9. Review and deployment status

- Pull request: https://github.com/Mr-M13/delnavazan/pull/492
- Not merged. Not deployed. Awaiting review approval.
