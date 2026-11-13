# Notification family review

This review separates catalogue identity from operational readiness. It does not
activate a declared or partial event, delete historical attempts, or change a
producer's stable event key.

| Decision | Families | Treatment in this PR |
|---|---|---|
| Keep | Account access, onboarding, booking confirmations/cancellations, lesson and attendance outcomes, consent decisions, and payment outcomes | Keep distinct because each has a different recipient, consent boundary, audit meaning, or next action. A row is only effective when its template and provider are ready. |
| Combine presentation only | `reschedule.change.*`, `reschedule.replacement.*`, and the related student, teacher, and admin reschedule rows | Show a searchable “Reschedule family” grouping in the table. Preserve every event key, producer, template, and delivery audit identity. |
| Retire or hold | Declared finance events and any consent/finance entry marked `partial` or `declared` | Do not activate from the catalogue alone. The owning workflow must register a producer, approved copy, and tests before these become effective. Existing history remains available. |
| Retire from active UI when confirmed redundant | Duplicate routine administrative notices and internal test-only notifications | Candidate cleanup for a separate evidence-backed pass; this PR does not remove keys or historical records without confirming all producers. |

The practical rule is that a visible catalogue row is not proof of a live
notification. The table now exposes the effective channel state, including a
short missing-template or provider reason, and the channel editor preserves
the table filters when it closes.

Provider credentials, approved templates, and staging delivery evidence remain
required before any held family or provider-dependent channel is described as
complete.
