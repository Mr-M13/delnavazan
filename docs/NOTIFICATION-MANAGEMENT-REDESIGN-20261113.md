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

## Live verification still required

No provider credentials or authorized provider session are stored in this repository. Staging or production verification is therefore still required for:

- Telnyx SMS and WhatsApp readiness, approved language-specific templates, and received-message evidence.
- SMS.ir write-only credentials, sender readiness, explicit send test and delivery diagnostic.
- Destination-normalized SMS routing for Iranian and non-Iranian numbers.
- A representative received HTML email in at least one inbox client, including the stable HTTPS logo asset and text fallback.

The PR performs no deployment, provider mutation, live send, or production-record change.
