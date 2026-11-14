# Current status and verification queue
As of 2026-11-12. **Reported historical checkpoints, not a live PR/CI audit.** Refresh GitHub and production status before acting.

## Recent PRs
- #588 God Mode Super Tester: reported draft, reconciled with main, head `bd428850`, mergeable and exact-head runtime gate passed. Confirm current status. Must remain tightly permissioned, auditable, test-only and excluded from teacher pay.
- #592 Teacher introductory opportunity board: reported draft on `feat/teacher-intro-opportunity-board`; cards, deadline timezone, preference selection and rejection reasons. Confirm current status.
- #552 Post-intro survey/continuation: reported draft with historical migration `060_post_intro_feedback` collision with `060_manual_refund_reservations`; verify whether resolved.
- #551 Booking acceptance recovery: reported merged, but a subsequent deployment failed and site outage was reported on 2026-11-07. Do not infer current production health from that incident.
- Other reported earlier merges: #506, #511, #513 booking hardening.

## High-priority unresolved questions
1. Google Meet creation after teacher acceptance: recovery/error banner, event/URL consistency, teacher co-host authority, automatic recording.
2. Attendance: Meet evidence and manual teacher-confirmed fallback must reliably trigger post-intro payment and enrolment.
3. Student #7 historical half-term discrepancy: 8 remaining shown, 2 paid sessions absent from upcoming schedule. Data may be synthetic; investigate before claiming bug.
4. Booking overlap prevention and teacher request/opportunity matching.
5. Notifications: Telnyx template mapping, WhatsApp reliability, SMS fallback and primary SMS, channel toggle reversibility.
6. Teacher class rescheduling: missing Meet URL despite 'provisioned' status; Jalali date input mismatch.
7. Settings/permission granularity and diagnostics consolidation; preserve local uncommitted work.
8. Golden-hour instrument coverage clock concept, not yet verified in code.
9. AI chatbot architecture and human handover: planning/review only; no deployment authorised.

## Verification steps
Inspect PR state/head/CI, latest migration inventory, deployed SHA, read-only application logs, integration tests, staging workflows and production health. Document findings with links, timestamps and evidence. Distinguish test fixtures from genuine student/teacher records.

## Local worktree warning
Desktop Commander on 2026-11-12 found branch `feat/settings-rbac-diagnostics-20261110` with uncommitted edits in finance, booking, promotions, student workspace, CSS, checkout and tests. Preserve exactly; this handover branch was created through GitHub to avoid touching it.
