# Settings redesign — 2026-11-02

## Outcome

Delnavazan Settings now shows five work sections:

0. **Policies** — the consent policy library, authoritative collection status, guardian authority attention, recording/ML eligibility exceptions, withdrawal processing and access-controlled evidence export. Policy publication reuses the existing append-only recording-notice authority.
1. **General** — academy information, public contact/presence, real WordPress pages, legal drafts, the versioned introductory booking horizon and “Who can use each work area”.
2. **Notifications** — the event table is the opening view; templates, global email appearance, delivery rules and restricted retry live inside the same workspace.
3. **Integrations** — provider configuration and readiness cards for Email, WhatsApp, SMS, Google, Stripe and LiveKit when active.
4. **Diagnostics** — filtered runtime evidence, support summary/bundle and separate capability-gated technical actions.

No Settings Overview was added. Policies is the single consent administration surface; capture and ML export remain disabled. The WordPress submenu is still registered but visually hidden by the existing shell, so every old route remains resolvable.

## Old control → destination

| Old visible control / route | Old slug | Destination | Protected legacy route? |
|---|---|---|---|
| General | `dzn-general` | Settings → General | Canonical |
| Recording notices | `dzn-recording-notices` | General → Legal and policy pages link | Yes; no separate visible entry |
| Notifications | `dzn-notifications` | Settings → Notifications | Canonical |
| WhatsApp | `dzn-integration-whatsapp` | Integrations → WhatsApp card → Configure | Yes |
| Email | `dzn-integration-email` | Integrations → Email card → Configure | Yes |
| SMS | `dzn-integration-sms` | Integrations → SMS card → Configure | Yes |
| Integrations | `dzn-integrations` | Settings → Integrations | Canonical |
| Google OAuth / Calendar | `dzn-integration-google` | Integrations → Google card → Configure | Yes |
| Staff access | `dzn-staff-access` | General → Who can use each work area | Yes |
| System health / technical diagnostics | `dzn-system-health` | Settings → Diagnostics (same protected route) | Canonical route reused |
| Portal capabilities | `dzn-portal-capabilities` | Diagnostics → Technical actions link | Yes |
| Advanced Recovery / core dataset | `dzn-core-dataset-readiness` | Diagnostics → Technical actions link | Yes |
| Communications health | `dzn-communications` | Notifications/Diagnostics; route now links to the new evidence | Yes |
| Notification templates | `dzn-communications-templates` | Notifications → Templates; dedicated teacher-invitation template remains protected | Yes |
| Stripe setup | `dzn-stripe-settings` | Integrations → Stripe card → Finance settings; old route remains | Yes |
| Finance → booking horizon control | Finance `INTRO_BOOKING_HORIZON` form | General → Introductory booking horizon | Finance history remains |
| Notifications tab “rules/channels” | `tab=rules`, `tab=channels` | Notifications → Events → Delivery rules | Aliases retained |
| Notifications tab “monitoring/website” | `tab=monitoring`, `tab=website` | Diagnostics | Aliases retained |
| General “Open system health” / “Open integrations” buttons | — | Removed; both sections are directly in Settings navigation | n/a |

## Key implementation points

### General

- Site name, website URL, WordPress home URL, platform/build/schema version and a plain-language schema state.
- Verified contact details:
  - `admin@delnavazan.com` — the supplied `admin@DelLavazon.com` was corrected against the approved repository record.
  - `0413 413 004` — the supplied `0413 4113 004` was corrected against `platform/docs/DELNAVAZAN-CORE-CONTINUITY.md` and `theme/inc/legal.php`.
  - `https://delnavazan.com`
  - `https://www.instagram.com/insta.delnavazan/`
- The frontline number is explicitly separated from the Telnyx notification sender.
- Pages are discovered with `get_pages()`; no page or URL is invented.
- Legal pages still use the draft-only `LegalPageProvisioner`; opening or saving General never publishes a policy.
- Booking horizon writes through `CommercialPolicyService::set('INTRO_BOOKING_HORIZON', ...)`. `BookingRequestValidationService` now reads the current versioned policy (weeks × 7) with the 90-day constant as a fail-safe fallback.
- Staff access is embedded from `StaffAccessController::renderPanel()`; the legacy route renders the same panel.

### Notifications

- Opening view is the event table.
- Filters: event text, channel, audience, problem state.
- Row data: purpose, audience, active channels, enabled, template readiness, latest delivery evidence, action needed, and permitted actions.
- Security/account/financial events remain mandatory. Operational booking/class events are optional and can be disabled.
- Category channel rules continue to gate real routing through `NotificationChannelPolicy`; SMS remains fallback-only.
- Template editor keeps the immutable version/draft/publish/rollback workflow and provider mapping remains in the WhatsApp editor.
- One global email appearance option stores logo/font/shared footer; the renderer applies it only to HTML email.
- Manual “Send now / Retry” requires the existing dispatch capability, an existing notification ID, a failed/expired queue row inside its window, and an allowed subject aggregate. It re-arms the existing outbox row with an idempotent command; it cannot choose a new recipient or template.
- Channel health is a strip with links to Integrations/Diagnostics; provider forms and full logs are not duplicated.

### Integrations

- Compact cards show local configuration, last explicit verification, supported work and protected Configure/Inspect links.
- “Credentials stored” is not presented as “provider working”.
- Frontline WhatsApp and Telnyx notification sender are separately labelled.
- Stripe remains under Finance; no second secret store.
- Advanced controls are collapsed; secrets are never rendered.

### Diagnostics

- Status rows cover site/build/schema, scheduled jobs, notification queues, Email, WhatsApp, SMS, Portal, Google, Stripe, classroom provisioning and recent exceptions.
- Each row shows state, detail, last checked, source, last success/failure, technical code and an owner link.
- Filters: time window, component, severity.
- Structured error codes are shown; free-form exception summaries are omitted.
- Copy support summary and a downloadable JSON support bundle include versions, configuration flags, queue counts (platform outbox, notification states and portal counts), codes and audit references only.
- The bundle is allowlisted and recursively scrubbed for names, email, phone, message bodies, financial details, secrets, tokens, payloads and provider data.
- The only technical action is the existing expired-lease recovery, capability-gated and confirm-gated. No command runner or arbitrary log browser.

### Staff access

- Areas: Overview, Teachers, Students, Classes, Enrolment, Finance, Settings.
- View/manage checkboxes only map to existing narrow capabilities where they exist.
- Sensitive permissions are separate: payment execution, payment ingestion, identity resolution, provider secrets, notification dispatch, notification templates, commercial policies, portal capabilities, exceptions.
- WordPress Administrator is not in the managed catalogue. Administrator accounts are read-only here.
- Self-granting is refused. Partial existing grants are inferred from current capabilities before the first save, and unrelated capabilities/roles are preserved.
- Every save appends an audit row in `dzn_platform_audit_events`; revocation removes the capability immediately.

## Verification evidence

### Source/unit tests (local PHP 8.3)

All of these passed:

```text
tests/static.php
tests/settings-redesign-contract.php
tests/staff-access-unit.php
tests/support-bundle-unit.php
tests/notification-management-unit.php
tests/notification-management-contract.php
tests/notification-admin-language-contract.php
tests/notification-preview-unit.php
tests/notification-delivery-status-unit.php
tests/notification-sms-fallback-policy-unit.php
tests/notification-sms-fallback-contract.php
tests/admin-navigation-target-contract.php
tests/admin-navigation-regression-contract.php
tests/admin-navigation-teacher-finance-contract.php
tests/admin-shell-route-capability-runtime.php
tests/integration-control-plane-contract.php
tests/google-projection-diagnostics-contract.php
tests/finance-payment-completion-contract.php
tests/recording-notice-publication-contract.php
tests/booking-public-window-contract.php
```

`git diff --check` passed.

### Disposable WordPress runtime

An isolated WordPress + MariaDB project (separate project name, port, volume and plugin mount) was used; it was destroyed afterwards. Results:

- Administrator login through the real `wp-login.php` form: PASS.
- Four Settings sections rendered on General, Notifications, Integrations and Diagnostics: PASS.
- Old bookmarks resolved without fatal errors: `dzn-integration-email`, `dzn-integration-whatsapp`, `dzn-integration-sms`, `dzn-integration-google`, `dzn-staff-access`, `dzn-portal-capabilities`, `dzn-core-dataset-readiness`, `dzn-recording-notices`, `dzn-communications`, `dzn-communications-templates`, `dzn-stripe-settings`: PASS.
- Staff grant: `dzn_manage_students`, identity read and dispatch grant applied: PASS.
- Immediate revocation: capabilities absent immediately after save: PASS.
- Teacher portal identity preserved while receiving a separate staff grant: PASS.
- Audit rows written: 3: PASS.
- Template draft → publish → history: PASS.
- WhatsApp provider mapping recorded locally: PASS.
- Optional event disable/re-enable: PASS.
- Mandatory security event disable refused: PASS.
- Support bundle: 11 status rows, no email/phone/secret/event-body leakage: PASS.
- No live email, WhatsApp, SMS, Stripe or other paid provider request was made.

### Screenshots

`platform/docs/evidence/settings-redesign-2026-11-02/`

- `settings-general-desktop-2026-11-02.png`
- `settings-general-mobile-2026-11-02.png`
- `settings-notifications-desktop-2026-11-02.png`
- `settings-notifications-mobile-2026-11-02.png`
- `settings-integrations-desktop-2026-11-02.png`
- `settings-integrations-mobile-2026-11-02.png`
- `settings-diagnostics-desktop-2026-11-02.png`
- `settings-diagnostics-mobile-2026-11-02.png`

## Unresolved dependencies / notes

- No real provider credentials, Meta template approval, or live delivery were available. Cards correctly show “not configured”/“credentials stored, provider not working”.
- WhatsApp provider mapping is local only until Meta approval; the editor states this explicitly.
- Stripe readiness remains under the existing Finance/payment-provider authority and is intentionally not duplicated.
- The booking horizon now affects public intake through the versioned policy. Historical values remain in the commercial policy authority; changing the value does not rewrite existing bookings.
- The live acceptance runtime used for screenshots was disposable and did not contain production data.

## PR / merge / deployment status

This work was implemented in the local worktree only. No pull request was opened, merged or deployed in this run. Two shared-navigation PRs are open upstream (#490, #491) and one Students workspace PR (#492); this change was written to layer on the Settings section without moving the primary seven-area navigation. Before opening a PR, rebase onto the chosen navigation PR so `AdminShellController` and `Menu.php` merge cleanly.
