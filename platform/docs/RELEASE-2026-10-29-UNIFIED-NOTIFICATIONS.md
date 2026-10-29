# Unified notification management — release record

Date: 2026-10-29
Branch: `feature/unified-notification-management`
Base: `origin/main` `b67f510`

## Admin language follow-up (2026-10-29)

The WordPress administration surface for this feature is now English: navigation, tabs, headings,
controls, field labels, statuses, provider/consent wording, diagnostics and error messages. Notification
copy sent to students and teachers remains Persian, and the editor/preview/history areas keep
`lang="fa" dir="rtl"` inside an otherwise LTR WordPress admin. Internal event keys, capabilities, nonces,
validation, version history and delivery behaviour are unchanged. See
`tests/notification-admin-language-contract.php` and the refreshed `docs/evidence/admin-*.html` captures.

## Production deployment (2026-10-29)

- Pull request: #404 (`feature/unified-notification-management`).
- Merge commit and deployed source: `25857feb8b2246fddb3a67d54b7f3c2396e77384`.
- Deploy workflow run: `40824363269` (Deploy production, target `production`).
- Deployed build identity: `main-25857feb8b22`; plugin active; maintenance inactive.
- Schema before/after: `54`/`54`. The deploy calls `Migrator::maybe_upgrade()` and fails closed;
  `verify_current_schema()` requires `056_notification_management`, so the additive inbox table and its
  verifier completed successfully.
- Read-only release diagnostic: run `40824807952`, schema `54`, build `main-25857feb8b22`.
- Production smoke: `RESULT: PASS` (read-only; no booking, payment, Google event or notification send).
- Backup stamp recorded by the deployment:
  `20261029T053945Z-3705951-25857feb8b2246fddb3a67d54b7f3c2396e77384`.
- Provider readiness stayed closed: copy definitions are now `configured`; notification runtime and all
  transport channels remain `not_configured`. No new recipient category or live campaign was activated.
- The source constant `DZN_PLATFORM_BUILD_ID` was not bumped in this release; the workflow-stamped
  `main-25857feb8b22` identity is authoritative. A future release should update the constant.

## What changed

- One **notification definition catalogue** (`NotificationCatalogue`) covering 50 business
  event/audience keys: trigger, producer, delivery authority, recipient, channels, consent class,
  mandatory flag, variable contract and reviewed Persian/English defaults.
- **Editable, versioned copy** over the existing immutable template model
  (`NotificationTemplateLibrary`). Drafts, validation, synthetic preview, publication, rollback,
  restore-default, history, WhatsApp provider mapping and category channel policy.
- **Admin workspace** «اعلان‌ها و پیام‌رسانی» (`dzn-notifications`) with five sections:
  قالب پیام‌ها، قوانین ارسال، وضعیت ارسال، تنظیمات کانال‌ها، اعلان‌های پرتال.
- **Branded email shell** (`DelnavazanEmailRenderer`): RTL, ivory page, turquoise primary action,
  pomegranate accent, logo, table layout, inline styles, no JavaScript/web font, plain-text alternative.
  The operational senders deliver this HTML body and set the authored text as `AltBody`; a host without
  the renderer or `wp_mail` keeps the previous plain-text path.
- **Website inbox** (`PortalNotificationInbox`, Schema 056): student/teacher bell, unread count, list,
  read/unread, mark-all-read and retention. Admin rows are separate; one-time secrets never enter it.
- **Copy read-through** for the five operational queues. Their delivery authority, idempotency and retry
  behaviour are unchanged; only wording becomes editable. A missing/invalid stored copy falls back to the
  sender's reviewed copy. These queues render at hand-off, so an edit applies to a message that is still
  waiting to be sent; the canonical authority freezes `template_version_id` at observation and is never
  affected by a later edit.
- **Monitoring terminology**: `dispatched` is labelled “handed off to provider”, never “delivered”;
  `delivered` appears only for a verified receipt. Safe lease recovery is available to the dispatch
  capability.
- Persian editorial standard and full audit/inventory in
  `NOTIFICATION-EDITORIAL-STANDARD.md` and `UNIFIED-NOTIFICATION-AUDIT.md`.

## Changed files

New: `NotificationCatalogue`, `NotificationTemplateLibrary`, `NotificationTemplateSanitizer`,
`NotificationTemplateValidationException`, `NotificationCopyResolver`, `NotificationChannelPolicy`,
`DelnavazanEmailRenderer`, `NotificationManagementController`, `PortalNotificationInbox`,
`PortalNotificationRecorder`, `PortalNotificationController`, teacher inbox partial, evidence, docs and
two test files.

Modified: plugin bootstrap (registration + copy filter), admin menu/shell, Migrator (Schema 056),
booking/post-intro/attendance/reschedule senders (copy + inbox seams), student/teacher shells and
platform bridge, portal inbox partial, portal CSS, control-plane QA doc.

## Database

- `056_notification_management` adds `dzn_portal_notifications` (one table).
- Additive only: no backfill, no notification write, no contact/provider column, no FK/CHECK.
- The global schema option remains `54`; the migration is tracked in
  `dzn_platform_completed_migrations` and `verify_current_schema()` now requires it.
- Rollback: revert code and drop the table; canonical notification tables, queued messages and published
  template versions are untouched.

## Verification performed

| Check | Result |
|---|---|
| PHP lint (all changed/new platform + theme files) | pass |
| Pure test bundle (`run-pure-tests.sh` list, local PHP 8.3) | 32/32 pass |
| `notification-management-unit.php` | pass (catalogue, sanitizer, XSS, SMS segmentation, renderer, publish/rollback/history, provider mapping, policy lock, resolver fallback, inbox ownership) |
| `notification-management-contract.php` | pass (capability/nonce/escaping/immutability/contact-free inbox/source guards) |
| `portal-truth-contract.php`, `student-home-account-contract.php`, `teacher-portal-*` | pass |
| `booking-student-notification-unit.php`, `lesson-reschedule-notification-unit.php` | pass |
| Disposable WordPress acceptance (`make smoke`) | pass (fresh install, migration 056, login routing) |
| `Migrator::maybe_upgrade()` re-run on installed site | pass (verifiers incl. Schema 056) |
| Real admin POSTs: save draft, publish v2, rollback → new version, repeated publish idempotent | pass |
| Real admin POSTs: script/injection rejected, channel policy lock, safe inbox test | pass |
| Inbox end-to-end: record, dedupe, unread counts, student/teacher separation | pass |
| Provider readiness after deployment | copy `configured`; runtime/channels `not_configured` |
| `phase-2a2p-contract.php` | pre-existing failure on main (attendance settlement wording), unrelated |

`phase-2a2s-contract.php` is also stale on `main` (it asserts schema 32). It is outside the pure-test
bundle and fails identically on the base commit.

## Provider readiness and activation gates

- **Email**: no new transport binding. Existing operational queues keep their current mailers. The
  canonical worker still requires an explicit `dzn_notification_transport_bindings` deployment binding.
- **WhatsApp**: Meta Cloud API boundary preserved. Mapping stores only template name/language; local copy
  changes mark the mapping as requiring re-approval. No business-initiated WhatsApp is sent.
- **SMS**: Telnyx boundary preserved and constrained to an explicit fallback policy. No SMS is sent.
- **Financial/renewal copy**: versionable and previewable, labelled as requiring financial/legal review;
  not activated.
- **Teacher invitations**: remain with their dedicated one-time-secret delivery authority.
- **Website inbox**: implemented and safe; read state is not delivery evidence.

## Evidence

`docs/evidence/` contains HTML captures of all five admin sections, the student/teacher portals with the
bell and inbox, and the branded email. Nonces are redacted. See `docs/evidence/README.md`.

## Remaining work

1. Approve and bind the production email transport; verify a controlled message on staging.
2. Approve Meta templates and consent policy before enabling WhatsApp; wire a delivery-receipt intake.
3. Decide whether any SMS fallback is allowed; supply Telnyx policy, spend limits and opt-out handling.
4. Review financial/renewal copy with the finance/legal owner before activation.
5. Provide a guardian principal path if guardian notifications are required; the catalogue currently
   scopes to student/teacher/admin.
6. Capture PNG screenshots on a visible desktop for the PR body if desired; HTML evidence is committed.
