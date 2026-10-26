# Release 2026-10-26 — integrated payment plans, attendance and Calendar reliability

Status: **deployed to production**. This record is the operational source of truth for the
integration release; it does not replace the PR review threads, CI runs or the deployment record.

## Release identity

| Fact | Value |
| --- | --- |
| Source branch | `main` |
| Deployed source SHA | `b1acd1d47b6f18756283fc0a3f3326ab595f993d` |
| Production build stamp | `main-b1acd1d47b6f` |
| Platform schema | `52` (`052_payment_plan_choice`) |
| Previous production SHA | `97a1864c5d655a6b9a70cbf982c682c0c5c03258` (schema 51) |
| Deployment workflow | `Deploy production`, run `40305480295` |
| Production backup | `20261026T101444Z-2286329-b1acd1d47b6f18756283fc0a3f3326ab595f993d` under the configured production backup directory |

## Merged PRs

| PR | Scope | Merge SHA |
| --- | --- | --- |
| #354 | B1 instalment schedule and Student payment-plan truth | `439c95aa9e57083636bf271fa05b7db4555a0409` |
| #357 | B2a pre-offer plan choice and incremental fulfilment | `d1a88dc8befaaab5dd63c6598979295f11b8a0d0` |
| #361 | B2b configurable 168-hour second-instalment deadline | `27560096da160fccd65ac5fafe1abde3a33edac3` |
| #358 | Interim manual attendance and presumed completion | `4c6e89fd06ebfc30e833a7d6006576421d2e6dc2` |
| #360 | Paid lifecycle regression bundle | `7195a3c56459314c978975ef7a2bb46261f9cded` |
| #356 | Post-intro Calendar retry acknowledgement regression | `12363c88e0496f5f6319ea0ec139c2fad9e0d7ed` |
| #359 | Automatic Calendar transport retry classification | `b1acd1d47b6f18756283fc0a3f3326ab595f993d` |

## Behaviour in this release

- Full-term payment remains the default. Two instalments are an explicit Student opt-in made before
  offer issuance; the offer and its obligations are immutable after issuance.
- An accepted two-instalment commitment keeps tranche 2 collectable after the initial checkout hold.
  Tranche 2 is blocked until tranche 1 settles and remains subject to the optional B2b deadline.
- `INSTALMENT_DUE_DATE_POLICY` remains **unset in production**. The approved value is exactly 168
  hours before canonical Lesson 7; when unset there is no invented deadline.
- Incremental fulfilment materialises the funded tranche only: full payment → 12 Lessons; two
  instalments → Lessons 1–6, then 7–12. Out-of-sequence settlement records money but does not
  activate Lessons before its prerequisite; later settlement re-arms the activation target.
- Ended canonical Lessons may become **presumed completed** from silence after their UTC end. This is
  a distinct lifecycle reason, never a Phase-O `delivered` outcome. Finance derives
  `pending / presumed_completion_review` until an administrator records a verified outcome.
- Teachers may report delivered or an issue; Students may report a missed or disputed class.
  Administrators may record, correct, reconcile or dispute outcomes; corrections are append-only and
  auditable. Teacher payments are never released from presumed completion alone.
- Calendar transport failures are retried with the existing bounded exponential backoff (30s
  doubling, capped at 1800s, 8 attempts). Permanent refusals and identity mismatches remain
  non-retryable. Calendar writes use deterministic event identities and pending mappings; retries
  do not duplicate canonical or provider records.
- No Google Meet attendance retrieval or participant tracking was added.

## Verification evidence

| Check | Result |
| --- | --- |
| PR runtime gate on exact `main` `b1acd1d` | PASS — source contracts, Stripe test-mode E2E, post-intro, catalogue, finance, OAuth scope and interim attendance |
| `make lifecycle-regression` on exact `main` | PASS — pure contracts, Stripe E2E, post-intro (12 Lessons, portals, partial recovery), interim attendance/presumed completion, Calendar retry unit |
| Fresh install (zero → schema 52) | PASS |
| Retained/repeat migration | PASS — ledger unchanged |
| Finance runtime | PASS |
| Interim attendance runtime | PASS |
| Production deployment source gate | PASS (same seven runtime jobs on `b1acd1d`) |
| Production identity check | schema `51` → `52`; build `main-b1acd1d47b6f`; plugin active; `delnavazan-theme` active |
| Production read-only smoke | PASS — home, enrol, login, admin operations, portal login redirects, REST routes, sitemap, www redirect, assets |
| Production Google OAuth diagnostic | PASS — one connected Calendar identity, `identity_state=verified`, provider reachable |
| Stripe | Test-mode E2E PASS; no live keys present in public pages; production was not switched to live |

## Rollback

The deployment script automatically restores the previous application files on a failed deploy and
leaves maintenance mode enabled. It never restores the database automatically. The pre-deploy database
export and application-file backup are retained under the production backup directory.

Schema 52 is additive (`post_intro_plan_choices`, `post_intro_activation_jobs.funding_target`,
`post_intro_activation_jobs.funding_materialised`). A code-only rollback to `97a1864` is safe for the
application but leaves those additive structures and any presumed-completion/correction history in
place. Never delete offers, settlements, purchases, Terms, Lessons, lifecycle events, delivery
outcomes, audit rows or statements to make a rollback easier. If a database restore is unavoidable,
take a fresh backup first and follow the operator decision recorded by the deployment script.

## Outstanding limitations and follow-ups

1. **Calendar HTTP 429/5xx classification.** The transport still maps non-2xx provider responses to
   `google_calendar_projection_refused`, which is permanent. Transient 429/5xx responses should be
   classified as retryable in a separate follow-up; the current release deliberately does not widen
   the deployment scope.
2. **B2b remains unset.** Do not activate the 168-hour policy in production until the business
   explicitly chooses to enable it. The policy is implemented and tested but off.
3. **Presumed-completion backfill.** The worker evaluates ended `authorised` Lessons in bounded
   batches. Monitor Finance `presumed_completion_review` counts and the admin exception surface after
   release; verify corrections and statement issuance behave as expected.
4. **Teacher report window.** Recently ended canonical classes remain visible to Teachers for seven
   days for delivery/issue reports. Older corrections remain an administrator action.
5. **Lifecycle bundle is manual.** `make lifecycle-regression` is an operator command; CI continues to
   run the individual runtime jobs. Do not treat the manual bundle as a replacement for CI.
6. **No live payments.** Stripe remains in test mode. Live payment activation, live webhooks and real
   student charges are explicitly out of scope for this release.
