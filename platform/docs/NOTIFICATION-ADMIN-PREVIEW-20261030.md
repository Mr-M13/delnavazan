# Notification preview fatal and administration consolidation

Date: 2026-10-30

Branch: `fix/notification-admin-preview-20261030`

## Root cause

`NotificationTemplateLibrary::preview()` called the private `ctaFor()` helper with four arguments,
but `ctaFor()` requires five (`$eventKey, $body, $variables, $audience, $locale`). Any email preview
whose body contains an action placeholder (`portal_url`, `teacher_portal_url`, `claim_url` or
`payment_url`) therefore raised:

```
ArgumentCountError: Too few arguments to function
NotificationTemplateLibrary::ctaFor(), 4 passed ... and exactly 5 expected
```

The preview is rendered while the admin editor loads, so WordPress showed its critical-error page.
The fix passes `$locale` and adds a focused regression suite that previews every catalogue
event/channel, including email, portal, WhatsApp and SMS, with synthetic data only.

## Student Account Invitation warnings

Before this change the template list showed four warning entries for
`account.claim_issued.student`:

1. **Email — “A canonical event cannot be dispatched without a published version.”** Genuine. The
   canonical authority renders only a frozen published version, so this remains visible until an
   email version is published.
2. **WhatsApp — the same canonical-version warning.** Misleading for this event because the
   Security & Access category policy disables WhatsApp. It is no longer an actionable readiness
   warning.
3. **WhatsApp — “No Meta-approved template is mapped.”** Also misleading for the same reason; no
   WhatsApp send can be authorised for a security-only event. The provider state is still reported
   factually as `not_mapped` (never “ready”), but the mapping warning is not presented as a blocker
   for an unavailable channel.
4. **SMS — the canonical-version warning.** Misleading because SMS is disabled for the Security &
   Access category. The channel is no longer listed as an applicable delivery choice.

Underlying defect: the status projection evaluated every catalogue channel without checking the
effective category policy, while the editor still advertised WhatsApp/SMS copy for a security event.
`NotificationTemplateLibrary` now exposes `channelPermitted()` / `permittedChannels()`, and the
template workspace lists only permitted channels. Provider state remains factual; a provider is
never marked ready because a channel is hidden.

## Interface changes

- Settings → **Notifications** is the entry point. Channel settings are ordered **WhatsApp, Email,
  SMS**.
- `dzn-communications` (Communications health) and `dzn-communications-templates` (legacy
  Notification templates) remain registered, protected routes but are hidden from visible Settings
  navigation. Both are still reachable from bookmarks and from contextual links.
- The main Notifications screen defaults to **Templates**, grouped one row per event with its copy and
  applicable delivery choices together.
- Old `tab=rules`, `tab=channels`, `tab=monitoring` and `tab=website` URLs resolve to the new
  Channel settings / Diagnostics views rather than 404ing.
- WhatsApp provider-template mapping moved into the WhatsApp copy editor. The WhatsApp settings page
  states clearly that Twilio WhatsApp is not implemented and that no Twilio credentials are read or
  written. The current provider route uses Telnyx with approved Meta WABA templates.
- Delivery rules and event channel mapping live under Channel settings. Delivery monitoring, portal
  notification summary/retention and lease recovery live under Diagnostics.
- Booking request and class records show a compact notification summary using the shared vocabulary:
  **Queued**, **Handed off to provider** (not confirmed) and **Delivery confirmed**, with a protected
  details link. Linked portal inbox entries are shown separately as **Available in portal**; an inbox
  entry is not evidence that its recipient read it. The class view includes reschedule and attendance
  portal entries. An immediate attendance email has no persisted delivery record, so the screen does
  not infer its hand-off from the portal entry.

## Safety and preservation

- No database migration, option rewrite, credential change or provider activation.
- Published template versions, drafts, queued messages and delivery records are untouched.
- Previews never call `wp_mail()` or a provider transport.
- One-time `claim_code` / `invitation_code` values are always replaced with `[one-time code]` in a
  preview, even if a caller supplies variables directly.
- Dedicated teacher-invitation delivery remains separate and is not editable from the general
  template library.
- A successful provider hand-off is never reported as confirmed delivery.
- The operational category retains its existing mandatory policy; this admin change does not relax
  delivery authority.

## Verification

Run from the repository root:

```sh
php platform/tests/notification-preview-unit.php
php platform/tests/notification-delivery-status-unit.php
php platform/tests/notification-management-unit.php
php platform/tests/notification-management-contract.php
php platform/tests/notification-admin-language-contract.php
php platform/tests/admin-shell-route-capability-runtime.php
php platform/tests/admin-dashboard-ux-contract.php
```

All of the above pass locally. PHP 8.3 lint passes on every changed PHP file.

A disposable local WordPress runtime (fresh schema, synthetic data only) was also exercised through the
real admin UI:

- Student Account Invitation → Email opened without a fatal and rendered the branded preview with
  `[one-time code]` and no real secret.
- Email, Portal, WhatsApp and SMS previews were opened from the template editor; each used synthetic
  data and no transport ran.
- Settings → Messaging showed **Notifications, WhatsApp, Email, SMS** in that order and no visible
  Communications health or legacy Notification templates entry.
- The old `tab=rules`, `tab=channels`, `tab=monitoring` and `tab=website` URLs resolved to the new
  views.
- WhatsApp settings showed the existing Meta fields and the explicit “Twilio WhatsApp is not
  implemented” notice without exposing secrets.
- A draft save and a synthetic booking-request notification showed the new Queued / Handed off /
  Delivery confirmed vocabulary; the details link opened the delivery record table.

## Remaining limitation

This change has **not** been deployed to production and the production WordPress admin has not been
checked. The production recovery email/PHP fatal log was not available in this environment. Do not
claim the production critical error is fixed until the release is deployed and the Student Account
Invitation preview is opened on the production admin.
