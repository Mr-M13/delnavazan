# Telnyx notification mapping authority

Date: 2026-11-01

## Problem

Telnyx discovery had persisted 14 approved Meta WhatsApp templates with valid event and variable
contracts, and the transport adapter used those rows at send time. The notification administration
workspace instead read the legacy `dzn_notification_whatsapp_templates` option. Production therefore
reported all discovered templates as mapped on the integration page while the event editors reported
them as not mapped.

## Resolution

`TelnyxTemplateAuthority` is now the shared read authority for the administration projection and the
WhatsApp transport adapter. A mapping is usable only when exactly one approved discovery row agrees
with the closed registry, event, locale, audience and ordered variable contract. Ambiguous, pending,
rejected and contract-mismatched evidence fails closed. A legacy manual mapping is considered only
when discovery has no event mapping and cannot override conflicting provider evidence.

This release does not publish copy, enable a category, send a message, change consent or suppression,
or weaken the WhatsApp-to-SMS fallback policy. SMS remains available only after a verified terminal
failure for the same WhatsApp notification and attempt.

## Production verification

After deploying the normal release artifact:

1. Refresh approved templates on **Settings → Messaging → WhatsApp**. This is a read-only provider
   call and sends no message.
2. Confirm the discovery summary remains 14 approved, 14 mapped, zero unmapped and zero variable
   mismatches.
3. Open **Settings → Messaging → Notifications** and filter to WhatsApp. The 14 registry-backed
   events must show **Mapped** with the discovered provider template name and no duplicate manual
   mapping form.
4. Confirm security events still do not offer WhatsApp or SMS.
5. Review **Diagnostics**. Do not activate financial, renewal or account-claim routes merely to
   remove a warning; investigate active intents separately from dormant or legally gated events.
6. Confirm the WhatsApp-to-SMS counters remain unchanged unless a verified terminal provider failure
   has actually occurred.

No production data reconciliation is required: the approved discovery rows already exist and become
visible through the shared read authority. Do not send a live WhatsApp or SMS test without a separately
authorised destination.
