# Phase 2A.2-M0 — Canonical Enrolment lifecycle authority

Status: **COMPLETE / INDEPENDENTLY REVIEWED / MERGED / CLOSED**. Approved candidate `66ba811c47a3494f12f47dbed03775ca7c4e5ba0`, approved tree `6a9631505a86893abf1fcefa89a78675e7ed3901`, implementation merge `c316a5153c7a56b810732495b3785786771c695a`.

Schema 19 / `019_canonical_enrolment_lifecycle_authority` adds explicit administrator-only lifecycle commands for canonical Enrolments. The legal graph is `authorised -> current`, `current -> paused`, `paused -> current`, and closure from any of `authorised`, `current`, or `paused`. Closed is terminal.

`authorised`, `current`, and `paused` remain applicable with slot `1`; closure atomically clears the slot. Pause blocks future canonical Lesson issuance, but deliberately does not change the Phase J/L rules for Teacher Assignments or canonical Terms.

Commands require `dzn_manage_canonical_enrolment_lifecycle`, an exact expected state, controlled evidence, and a digest-only idempotency key. Schema 19 adds only immutable `enrolment_lifecycle_commands`; it performs no backfill or lifecycle inference. Exact replay binds to the historical result event and remains valid after later legal progress. Corrupt commands, history, evidence, projections, or slot state fail closed.

Protected canonical lifecycle reads validate the complete projection and ordered history before returning any event. Integrity conflicts return no partially trusted history; legacy Enrolment history retains its pre-M0 read behavior.

Lock order is Student–Course identity root, canonical Enrolment, ordered Enrolment lifecycle events, and—only for closure—ordered canonical Terms and evidence followed by ordered Teacher Assignments and evidence. Applicable Terms or Assignments block closure; subordinate authority is never cascaded or mutated.

Canonical Lesson authority remains non-authoritative. When it is introduced in Schema 20, Enrolment closure must additionally reject non-terminal canonical Lesson authority without cascading Lesson mutation.
