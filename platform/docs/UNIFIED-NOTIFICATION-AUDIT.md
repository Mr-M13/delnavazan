# Unified notification management — architecture audit

Date: 2026-10-29
Branch: `feature/unified-notification-management`
Base: `origin/main` (`b67f510`)

## 1. Executive summary

Delnavazan already has a well-built **canonical notification authority** (Schema 032): workflow
versions, immutable template versions, rendered-parameter snapshots, eligibility/suppression,
leases, retries and delivery receipts. That authority, however, was **not the copy authority** and was
**not connected to everyday business email**:

- Five operational senders call `wp_mail()` directly with copy hard-coded in PHP.
- `dzn_notification_copy_definitions` and `dzn_notification_transport_bindings` had no producer, so the
  canonical worker could not install or dispatch anything.
- The only admin copy editor was a single teacher-invitation textarea stored in a WordPress option.
- There was no persistent student/teacher notification inbox, no read state and no bell.
- Nothing mapped business events to channels, provider templates, consent classes or editorial rules.

This change adds one definition catalogue, a versioned copy layer **on top of** the existing immutable
template model, a branded email shell, an admin workspace («اعلان‌ها و پیام‌رسانی»), and a persistent,
ownership-scoped website inbox. It does **not** activate new live messaging: transport bindings, Meta
template approvals, Telnyx policy and delivery receipts remain explicit operational gates.

## 2. Authorities found

| Layer | Authority | Notes |
|---|---|---|
| Intent → workflow → schedule → lease → retry | `NotificationService`, `NotificationWorkflowService`, `NotificationDispatchService`, `NotificationRetry` | Closed intent vocabulary in `NotificationRule`; capability-gated. |
| Immutable template identity/version | `NotificationTemplateService`, `NotificationTemplateRepository` | Stores digests and the variable contract; bodies previously lived with the caller. |
| Rendered snapshot | `dzn_notification_rendered_snapshots` | Encrypted parameters; a queued notification keeps its exact `template_version_id`. |
| Copy | `ConfiguredNotificationCopy` + `dzn_notification_copy_definitions` filter | No definitions were registered anywhere. |
| Transport | `NotificationTransportRouter`, `CompositeNotificationTransport` | Email, or mobile → WhatsApp then SMS fallback on `contact_unusable`/`send_refused`. No bindings were registered. |
| Teacher invitation | `TeacherInvitationDeliveryWorker` + `TeacherInvitationPresentation` option | Deliberate separate authority: the one-time secret is created at the delivery boundary. |
| Student account claim | `StudentAccountClaimNotificationService` | Canonical intent with an encrypted one-time `claim_code`. |
| Booking/post-intro notifications | `BookingStudentNotificationWorker` | Durable leased queue, direct `wp_mail()`. |
| Reschedule/replacement | `LessonRescheduleNotificationService` | Durable idempotent queue, direct `wp_mail()`. |
| Attendance alerts | `CanonicalAttendanceNotificationService` | Best-effort direct `wp_mail()` after attendance commits. |
| Post-intro teacher alert | `PostIntroActivationService::notify()` | Direct `wp_mail()` plus a durable booking-notification row. |
| Diagnostics | `NotificationDiagnosticsReadService`, `IntegrationControlPlaneReadService` | Read-only, secret-free; state counts are not delivery proof. |

## 3. Notification inventory

`status` distinguishes **implemented** (code produces it) from **configured** (provider/copy wired) from
**verified** (receipt/test evidence). No event in this repository is production-verified for delivery.

### 3.1 Account and security

| Stable event key | Trigger / producer | Recipient | Channels | Authority | Wording before | Status |
|---|---|---|---|---|---|---|
| `account.claim_issued.student` | `STUDENT_ACCOUNT_CLAIM_ISSUED` published by `StudentAccountClaimNotificationService` | Student | email (mobile/WhatsApp/SMS reserved) | canonical | no definition → unrenderable | Producer implemented; copy now versioned; dispatch needs transport binding; **no inbox entry** (one-time code) |
| `teacher.invitation_issued.teacher` | Admin issues invitation; `TeacherInvitationDeliveryWorker` | Teacher | email | dedicated | WP option, editable textarea | Implemented; dedicated secret boundary preserved; **not** editable from the unified editor |

### 3.2 Booking, intro and post-intro

| Stable event key | Trigger / producer | Recipient | Channels | Authority | Wording before | Status |
|---|---|---|---|---|---|---|
| `booking.confirmed.student` | `BookingCalendarProjectionService` → `dzn_booking_student_notification_dispatch` | Student | email, website, WhatsApp (mapped later) | queue | hard-coded English | Implemented; copy versioned; inbox recorded |
| `booking.declined.student` | `BookingTeacherResponseService` → worker | Student | email, website | queue | hard-coded English | Implemented; copy versioned; inbox recorded |
| `post_intro.invitation.student` | `PostIntroPreparationService` → worker | Student | email, website | queue | hard-coded English | Implemented; copy versioned; inbox recorded |
| `post_intro.activated.student` | `PostIntroActivationService` → worker | Student | email, website | queue | hard-coded English | Implemented; copy versioned; inbox recorded |
| `post_intro.activated.teacher` | `PostIntroActivationService::notify()` | Teacher | email, website | queue | hard-coded Persian | Implemented; copy versioned; inbox recorded |

### 3.3 Reschedule and replacement

Produced combinations (`LessonRescheduleService::notify()`):

| State | Audiences produced | Channels | Copy before |
|---|---|---|---|
| `proposed` | student, admin | email, website | hard-coded Persian |
| `accepted` | student, teacher, admin | email, website | hard-coded Persian |
| `rejected` | teacher, admin | email, website | hard-coded Persian |
| `failed` | student, teacher, admin | email, website | hard-coded Persian |
| `cancelled` | student, teacher, admin | email, website | hard-coded Persian |

Both `change` and `replacement` kinds exist → 28 produced event keys (30 defined; `proposed.teacher` and
`rejected.student` are defined for completeness but not produced). All are durable, idempotent and
retry-bounded. This change adds versioned copy and student/teacher inbox records; email remains the only
transport.

### 3.4 Attendance

| Stable event key | Trigger / producer | Recipient | Channels | Wording before |
|---|---|---|---|---|
| `attendance.absence_reported.teacher` | `StudentAbsenceController` → `CanonicalAttendanceNotificationService` | Teacher | email, website | hard-coded Persian |
| `attendance.missed_class_claim.teacher` | same | Teacher | email, website | hard-coded Persian |

Both are best-effort and deliberately never roll back attendance evidence.

### 3.5 Financial and renewal intents

`AUTOMATIC_RENEWAL_UPCOMING`, `AUTOMATIC_RENEWAL_CHARGED`, `AUTOMATIC_RENEWAL_FAILED`,
`MANUAL_RENEWAL_PAYMENT_REQUIRED`, `GUARANTEE_DEADLINE_APPROACHING`, `PAYMENT_FAILED`,
`PAYMENT_RECOVERED`, `TERM_LAPSED`, `REFUND_REVIEW_REQUIRED`, `REFUND_RESOLVED`.

The owning finance services publish intents (`RecurringSupport::publishIntent`), but **no workflow or
template version is registered** and no transport is bound, so `NotificationDiagnosticsReadService`
reports them as unroutable. `GUARANTEE_EXPIRED` is reserved-unbound and must not be registered.
Catalogue entries exist with clearly marked **draft financial copy requiring legal/financial review**.

### 3.6 Admin

Reschedule admin emails (propose/accept/reject/fail/cancel × change/replacement) are produced for the
academy administrator. The website admin inbox is deliberately separate from student/teacher rows; the
unified workspace also exposes diagnostic counts.

## 4. Gaps and root causes

1. **Copy was not an authority.** Template versions stored digests only, so the canonical worker could
   not render anything without an out-of-band array. Root cause: the template slice deliberately left
   bodies with the caller, and no later slice supplied them.
2. **Duplicate senders.** Booking, reschedule, attendance, post-intro and invitations each own a queue and
   call `wp_mail()` directly. They are **not** duplicated sends of one event, but they duplicate copy,
   branding, locale handling and diagnostics.
3. **Hard-coded messages.** Four senders hard-code Persian or English copy in PHP; changing wording
   required a deploy.
4. **Unregistered events.** The canonical intent set does not include booking/reschedule/attendance; their
   producers write to separate queues. Widening the closed S intent vocabulary would violate its binding
   matrix, so this change adds a **catalogue above** both models instead of rebinding S.
5. **No channel definition.** Nothing recorded which event may use email, WhatsApp, SMS or the website, or
   which messages are mandatory/security/financial.
6. **No provider-template mapping.** Meta template name/language and local copy were disconnected.
7. **No inbox.** Portals hard-coded `notifications_available = false`; there was no read state anywhere.
8. **Terminology drift.** «تغییر زمان»، «جلسهٔ جبرانی»، «کلاس آشنایی» and «تمدید دوره» were used
   inconsistently across senders.
9. **Monitoring ambiguity.** `dispatched` could be read as “delivered”; delivery receipts are a separate,
   verified fact.

## 5. What this change implements

- `NotificationCatalogue`: one definition per business event/audience (trigger, producer, authority,
  recipient, channels, consent class, mandatory flag, variable contract, fa/en defaults, provider state).
- `NotificationTemplateLibrary`: drafts, validation, publication, rollback, restore-default, history,
  provider mapping and channel policy. Bodies are stored **once per immutable `template_version_id`**;
  publication always goes through `NotificationTemplateService`.
- `NotificationCopyResolver`: fail-soft read-through for the existing queues (a missing/invalid stored
  copy never blocks an operational message).
- `DelnavazanEmailRenderer`: one table-based, inline-styled, RTL, ivory/turquoise/pomegranate shell with a
  plain-text alternative and no JavaScript or web font.
- `NotificationManagementController`: five-tab admin workspace with capability + nonce checks.
- `NotificationChannelPolicy`: mandatory categories lock email/website on and security channels off;
  optional channels (website, future WhatsApp/SMS) are consulted before use.
- `dzn_portal_notifications` (Schema 056) + `PortalNotificationInbox`: ownership-scoped, deduplicated,
  retention-bounded website inbox with read/unread state; student/teacher bells and lists.
- Diagnostics dashboard terminology and safe lease recovery.

## 6. Deliberately not activated

- No new transport binding is registered. `NotificationRuntimeFactory` still refuses to install a worker
  without an explicit deployment binding.
- No WhatsApp or SMS business send is enabled. Mapping is configuration/preview only; Meta approval is
  external and must be re-confirmed after any local copy change.
- Financial copy is **not** approved for delivery; it is visible and versionable, labelled as requiring
  financial/legal review.
- The teacher invitation secret remains in its dedicated authority and is not copied into the general
  template library or the inbox.
- No real recipient is messaged by this change or by the tests.

## 7. Migration and rollback

- Additive migration `056_notification_management` creates one table. No backfill, no notification write,
  no contact column, no foreign key or CHECK constraint.
- Rollback: the table can be dropped and the code reverted; canonical notification tables and queued
  messages are untouched. Published copy versions live in WordPress options keyed by version id and are
  inert without their template versions.
- Existing canonical notifications keep their frozen `template_version_id` and encrypted snapshot. Publishing
  a new version cannot rewrite an already-rendered or committed message.
- The five operational queues render at hand-off and therefore read the active version at that moment. This
  is documented in the editor; moving them onto frozen snapshots would require a queue-schema change and is
  not part of this slice.

## 8. Verification evidence

- 32 pure tests pass locally, including the two new notification-management suites.
- A disposable WordPress install (fresh schema, synthetic identities only) completed migration 056,
  passed `make smoke`, re-ran `Migrator::maybe_upgrade()` with all verifiers, and exercised the real
  admin POSTs: save draft, publish, rollback, repeated publish (idempotent), script rejection, channel
  policy lock and the admin-only inbox test.
- Inbox recording, dedupe, unread counts and student/teacher separation were exercised against the
  installed site; provider readiness remained `copy=configured`, `runtime=not_configured`.
- Static HTML evidence for every dashboard tab, both portal bells/inboxes and the branded email is in
  `docs/evidence/`. PNG capture was unavailable because the automation desktop window was not visible;
  the HTML uses the same DOM/CSS.
- `phase-2a2p-contract.php` and `phase-2a2s-contract.php` fail identically on the base commit for
  unrelated stale assertions; neither is in the pure-test bundle.
