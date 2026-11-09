# Notification task redesign — grounded audit and implementation plan

Date: 2026-11-09  
Scope: Delnavazan platform and its in-repository WordPress theme. No production or provider mutation was performed.

## Confirmed current state

* `NotificationCatalogue` is the transport-neutral event definition source. It preserves an event key, recipient audience, allowed channels, consent basis, immutable-template authority, variables and copy per channel/locale.
* The existing administration screen defaults to a **Student notifications** tab, with a separate Teacher tab. Events are individually recipient-specific, but the top-level navigation makes recipient type the operational organising principle.
* Channel controls are reversible per event and per policy category. Mandatory security, account and financial email/portal routes remain locked. This redesign does not change the category keys or options behind those controls.
* `TelnyxTemplateAuthority` joins the closed local registry to discovered approved provider templates. It fails closed for ambiguous, pending, rejected or variable-mismatched evidence. The registry contains 14 named WhatsApp templates; local source alone cannot verify that the live provider still has the same approved mapping.
* The canonical fallback path is intentionally WhatsApp-first and only permits one SMS fallback after a verified terminal WhatsApp failure, consent, no suppression, a valid phone, policy permission and an idempotency check. It records the authorisation and revalidates it before hand-off.
* The legacy booking-specific mobile dispatcher also remains WhatsApp-first. It can take an immediate permanent-provider-failure fallback path; its behaviour is intentionally left untouched by this information-architecture change and should be reconciled separately before any mobile-delivery redesign.
* Notification history remains in the existing notification/outbox/event model, while the portal inbox uses a contact-free, user-scoped, HMAC-deduplicated projection. Neither is migrated or rewritten by this work.

## Live-provider status

No provider credentials or authorised read-only provider session were available in this worktree. Therefore template names and mappings are **source-declared only**, not live verified. No Telnyx discovery refresh, template publication, resend or delivery test was attempted.

## Implemented safe slice

The branch adds a presentation-only `TASKS` taxonomy:

1. Enrolment & Booking
2. Classes & Scheduling
3. Finance & Payments
4. Renewals
5. Accounts & Onboarding
6. Support
7. System

Task assignment is deterministic from the existing event key. The Events workspace defaults to all tasks, adds a task filter, sorts by task, and retains recipient type as a row-level attribute and optional filter. Templates display the same business task and recipient-specific copy. The prior category stays visible as **Delivery policy**.

No event key, audience, copy, provider mapping, consent rule, channel eligibility, history record, delivery worker, retry state, idempotency key, portal record or toggle option changes.

## Follow-up plan requiring separate approval

1. Use an authorised read-only Telnyx discovery refresh to compare the 14 registry entries to the live approved-template state; record mismatches without sending.
2. Resolve missing approved WhatsApp templates event by event, with copy/legal approval and a provider publication authorisation before registering any new mapping.
3. Audit the booking-specific synchronous fallback against the canonical persisted fallback service. Design a converged, idempotent migration only after confirming its current production usage and delivery-history implications.
4. Validate the task-first screen in staging with existing notification history. Do not resend messages as UI test data.
5. Merge and deploy only after review and explicit approval.
