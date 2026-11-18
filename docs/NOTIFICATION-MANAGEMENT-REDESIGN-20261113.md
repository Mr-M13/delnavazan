# Notification management redesign

## Before / after inventory

| Area | Before | After |
|---|---|---|
| Navigation | Notifications appeared under Settings | Notifications is a primary workspace, ordered after Enrolment; old routes remain capability-checked aliases |
| Catalogue | Task-oriented rows mixed readiness, delivery history and actions | One event table: Category, Notification/purpose, Recipient, Portal, Email, WhatsApp and SMS |
| Search | Event/task/audience filters | One combined search across label, purpose, stable key and active/default copy, plus Category, Recipient, Channel and Status |
| Channel state | Stored enablement could look ready despite provider/template blockers | Effective state fails closed with a short reason and an owning editor, integration or diagnostic destination |
| Copy | Separate legacy/template surfaces | Event/channel editor retains Persian/English, synthetic preview, draft, publish, restore and immutable history |
| Email appearance | Operator-editable global appearance | Code-owned branded shell; event subjects and bodies remain editable per event |
| Delivery | Booking workers could call email independently of the event switch | Booking email producers consult the event/channel authority before handing off |

The catalogue still preserves every source event key and does not silently activate partial finance or consent entries. Repetitive lesson/reschedule families remain individually searchable; grouping can be added without changing their delivery identity.

## 2026-11-18 follow-up

- The catalogue card is now a title, the four delivery switches (Portal, Email, WhatsApp, SMS) and Open. Green means effectively active; red means inactive or blocked. The purpose paragraph and the duplicate event-level control were removed.
- The editor consolidates Delivery, Variable Contract and Status into one overview card, then shows one tab per delivery method with that method's preview, copy, provider mapping, draft approval and history.
- A Teacher's new introductory booking request creates the whole Term in one projection. The per-class access-ready notice is suppressed for that projection; the existing enrolment-activated notice is the single Term-level message. A normal single schedule still sends access-ready.
- The one-time catalogue action approves and publishes every stored draft once. It is capability/nonce gated, requires the literal confirmation `APPROVE ALL`, records its use and refuses a second run. It never enables a channel or sends a message.
- The WhatsApp registry variable order now matches the approved provider manifest for all 40 templates. A discovery refresh after deploy is required so the corrected local mapping replaces the previously truncated rows.
- SMS.ir has two approved templates. Every booking/lesson/renewal SMS is mapped to the generic account-referral template except `booking.new.teacher`, which uses the new-intro-booking template. Optional provider template IDs under Integrations → SMS.ir switch the adapter to the template endpoint; otherwise the exact approved body is sent through the bulk endpoint.


## Live verification still required

No provider credentials or authorized provider session are stored in this repository. Staging or production verification is therefore still required for:

- Telnyx SMS and WhatsApp readiness, approved language-specific templates, and received-message evidence.
- SMS.ir write-only credentials, sender readiness, a successful explicit authorized test, and delivery diagnostic. Until then, an SMS.ir-selected route is unavailable.
- Destination-normalized SMS routing for Iranian and non-Iranian numbers.
- A representative received HTML email in at least one inbox client, including the stable HTTPS logo asset and text fallback.

The PR performs no deployment, provider mutation, live send, or production-record change.
