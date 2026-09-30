# Phase 2A.2-T — Provider-Neutral Payment Execution Seam & Stripe Adapter (implementation record)

**Status:** candidate — awaiting independent review. Not merged, not deployed, not production-authorised.
**Schema:** 029 / migrations `028_payment_execution_seam_provider_adapter` and `029_payment_event_decision_claim_authority`
**Build:** `phase2a2t-payment-execution-seam-stripe-adapter-20260925.14`
**Base:** `main` at the Phase-V candidate tree (Schema 27), strictly additive on top of R1 (Schema 25, authoritative) and R2 (Schema 26, candidate).

This record documents the implementation of the contract in
[PHASE-2A-2T-PAYMENT-EXECUTION-SEAM-STRIPE-ADAPTER-CONTRACT.md](PHASE-2A-2T-PAYMENT-EXECUTION-SEAM-STRIPE-ADAPTER-CONTRACT.md)
(correction round 14, SHA-256 `bdd88f8f0a1a0d8ac16ffa82fc081d938c683d1f359ce515c1cb7c8158aa16ad`). The contract
is normative; this record states what was built, what could be executed in this environment, and what
remains deliberately unresolved.

## 1. What this phase owns

1. **Provider-neutral execution seam** — `src/Core/Application/PaymentExecution/`: the port
   (`PaymentExecutionPort`), the durable request/outcome value objects, the sealed dispatch descriptor,
   the one-use dispatch capability, the descriptor preflight verdict, the reference claims, the event
   envelope/translator boundary and the controlled provider registry.
2. **Provider account and object mapping registry** — `PaymentProviderAccountService`,
   `PaymentProviderObjectService`, both digest-only and authority-free.
3. **Execution command authority** — `PaymentExecutionService` plus the immutable command, the
   append-only attempt and result rows and the single mutable dispatch claim.
4. **Stripe adapter** — `src/Integrations/Payment/Stripe/`: protocol translation, normalisation,
   redaction, exact-raw-body signature verification and the single public webhook route.
5. **Webhook intake** — `PaymentEventIntakeService`: durable receipt, verification, event identity,
   the durable per-event decision claim, translation and the bounded ordered R2 consequence.
6. **Secret isolation** — `PaymentSecretVault` plus its own repository; no provider secret is writable in
   this build.
7. **Schema 028/029** — the fifteen additive seam tables with the fail-closed verifier
   `verify_payment_execution_schema()`, plus [C10-1] the one-table decision-claim aggregate of migration
   `029_payment_event_decision_claim_authority` with its own fail-closed verifier
   `verify_payment_event_decision_claim_schema()`.

## 2. Structural properties the candidate must satisfy

- The **first outbound-capable boundary** on the Platform: a provider key, a provider reference and a
  provider secret may exist only as a controlled vocabulary member, a keyed digest and authenticated
  ciphertext. Core holds no open API for a sealed envelope; only an adapter seals or opens one.
- The **first unauthenticated request path that can reach commercial authority** (the webhook). It can
  never change commercial truth directly: translation submits provider-neutral evidence to the existing
  R1 `CommercialPaymentService::ingest()` boundary, which alone decides acceptance and settlement.
- **No command is ever unowned between the two transactions.** Transaction 1 commits the immutable
  command row together with the durable dispatch claim (the sealed descriptor included) before the
  account-root lock is released; the provider call happens outside any transaction, and transaction 2
  writes the single attempt and its terminal result row only through a settlement fenced by the claim's
  generation and token.
- **The envelope is opened exactly once**, in the non-mutating pre-call preflight, and its `ok` verdict
  mints the one-use capability the single invocation consumes. `submit`/`cancel`/`reconcile` never reopen
  a descriptor, so no second open can fail after the claim leaves `claimed`.
- **A descriptor failure precedes any call.** A failing preflight, or a failing Core binding comparison
  under the claim lock, ends a `claimed` claim `released` with a durable `refused`/
  `dispatch_descriptor_unavailable` result — the one claim/result pairing §8.2 rule 5 permits. A
  generation-1 `in_flight` claim whose capability cannot be consumed takes the provably call-free no-call
  abort; a takeover generation never takes it and keeps the ordinary `in_flight` pending behaviour.
- **An event decision is owned by exactly one claim.** [C9-1]/[C9-2] Every decision — the first decision
  of a new event, the first decision of an event that was recorded and then left owing one, the terminal
  consequence of a `pending` decision and a `drain()` — is appended only by the worker that owns the
  event's live decision claim. The claim is taken before any translation or R1/R2 consequence runs, its
  generation and token fence the `claimed → settled` transition inside the transaction that inserts the
  decision (so the next `decision_sequence` is allocated under the claim row's lock), and a delivery that
  cannot own the claim performs no work at all: it re-reads the owner's decision, waits a bounded
  structural window and converges. An expired claim is taken over by exactly one generation, so a worker
  that died holding one never strands the event.
- **Unresolved policy stays unresolved.** `provider_recurring_semantics_unresolved` is recorded and acted
  upon by nothing; there is no advance charge instant, no lapse, no recovery threshold and no refund
  consequence; `subscription` mappings are inert; the refund path records and routes for review with
  `academic_consequence` NULL.

## 3. Deliberate implementation notes

- **The drain re-supplies the delivery.** `payment_provider_events` stores only digests by design, so a
  received event whose translation refused (for example an unset worker principal) is completed by
  `PaymentEventIntakeService::drain($eventId, $rawBody, $headers)`: the body is verified against the
  recorded account's single active signing secret before the event identity is matched, and [C8-4] the
  **full** recorded `event_fact_digest` is then recomputed and required to match, so a drain can never
  manufacture a fact, never translate a changed payload for a recorded event id, and converges on the
  recorded identity exactly once.
- [C8-1] **The route is method-complete and the receipt is byte-exact.** Both webhook routes are
  registered for every HTTP method through one locked method set, so a `GET`/`PUT`/`PATCH`/`DELETE`
  delivery reaches the controlled handler and is receipted as `method_not_allowed` instead of being
  dropped by WordPress routing; and the controller always hands the exact raw bytes it received to the
  receipt, whatever precheck refused the request, so no refusal records a zero-byte or rewritten digest.
- [C8-2] **Attribution is exact.** The event's own provider object must carry exactly one *active*
  mapping (`active_slot = 1`, `state = linked`), and the canonical obligation that mapping owns —
  `canonical_id` for an `obligation` mapping, the mapped collection intent's `obligation_id` for a
  `collection_intent` mapping — must equal the obligation the event resolved. A historical mapping is
  never authority; a mismatch is refused `ambiguous_obligation_attribution` and submits no evidence.
- [C8-3] **A lost insert race converges.** Insert-or-resolve reports whether this worker created the
  event: a worker that meets `UNIQUE provider_event` adopts the winner's row and enters the same
  fact-digest convergence path a read duplicate enters, so identical deliveries converge on one decision
  and materially different facts append the controlled conflict decision instead of a second translation.
- **Operator exception reason.** §8.3 requires an operator-visible exception when an `in_flight` claim
  cannot be reconciled. Phase T may not widen R1's locked `CommercialRule::EXCEPTION_REASONS`, so the
  candidate records that exception under the existing controlled reason `conflicting_payment_evidence`
  (`severity = warning`, `safe_detail = payment_execution_in_flight_pending:<reason>`), and the same fact
  is surfaced directly by `PaymentExecutionReadService::outstandingDispatches()`. No new R1 vocabulary
  was added.
- **Webhook transport.** The controller performs the §9.2 request checks (method, HTTPS, content type,
  body size, request shape) and passes a controlled `precheck_refusal` reason to the intake service, so a
  refusal decided before parsing is still durably receipted and the body is never read to resolve the
  account. [C9-3] The HTTPS check is the complete §9.2 rule: direct TLS, the local development
  environment, or a proxy header the operator has configured this site to trust
  (`PaymentExecutionRule::TRUSTED_PROXY_OPTION`) and that is a member of the locked
  `HTTPS_PROXY_HEADERS` allowlist. The option names headers, never authority — an unset, empty,
  malformed or non-member configuration trusts nothing — so a client can never satisfy the requirement
  with its own `X-Forwarded-Proto`, and only a leftmost `https` in the header's scheme chain counts.
- **Every registered method is receipted, `OPTIONS` included.** [C9-4] WordPress answers `OPTIONS` in
  `rest_handle_options_request()`, a `rest_pre_dispatch` filter, so no route registration can deliver one
  to a callback. `StripeWebhookController::register()` therefore also registers the endpoint's own
  `rest_pre_dispatch` interception ahead of that handler; it answers only an `OPTIONS` delivery to this
  endpoint's two route shapes and runs the same controlled `process()` path a routed
  `GET`/`PUT`/`PATCH`/`DELETE` takes, so an `OPTIONS` delivery is receipted
  `refused`/`method_not_allowed` with its exact raw body and never becomes an event.
- **The event row stays immutable.** [C9-1]/[C9-2] The decision claim is its own aggregate
  (`payment_provider_event_decision_claims`), never a column on `payment_provider_events`, so the
  recorded event identity is still write-once and the claim's lifecycle is never mixed into it. A
  `conflicting_provider_event` row is likewise not the event's decision: whether an event still owes a
  decision is asked of its non-conflict decision timeline.
- **No scheduler.** `drain()` and `redrive()` are explicit, idempotent entry points for a later scheduler
  owned by another phase; no `cron` or `wp_schedule_*` call exists anywhere in `src/`.

## 4. Correction round 8 (independent review of `91bf288` / tree `dec38b70`)

The independent review of the round-7 candidate returned **FAIL — CORRECTION REQUIRED** on four blocking
findings. Each is closed additively in this candidate; no authority, table, capability, policy or provider
call is added, and no previous commit is rewritten.

| # | Blocking finding | Correction in this candidate |
| --- | --- | --- |
| C8-1 | A non-`POST` delivery never reached the controller (WordPress matched the route method first), so it could not be receipted; and every precheck-refused request was receipted against `''` instead of the bytes that arrived, recording a zero-byte/different digest for oversized, invalid-content-type and other refusals. | Both routes declare one locked **all-method** set (`GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS`), so §9.2's method requirement is decided inside the controlled handler and receipted as `method_not_allowed`; the controller always passes the **exact raw body** to `receive()`, so a refused request records its true `request_digest` and `body_bytes`. |
| C8-2 | Attribution resolved the obligation from metadata alone while `objectIsMapped()` accepted *any* stored mapping of the event object, regardless of canonical kind/id or active state, so a signed event could submit evidence for an obligation the mapped object did not own. | The intake resolves **exactly one active mapping** for the event object (`active_slot = 1`, `state = linked`; historical rows are never authority) and requires the canonical obligation that mapping owns (`obligation` → `canonical_id`; `collection_intent` → its `obligation_id`) to equal the obligation the event resolved. Absent/historical ⇒ `unmapped_provider_object`; multiple candidates or a mismatch ⇒ `ambiguous_obligation_attribution`; no evidence is submitted. |
| C8-3 | Two workers could both observe no event; the loser of `insertEvent()` received the winner's id after the unique-key collision and then unconditionally called `decide()`, appending a second decision and running a second R1/R2 consequence. | Insert-or-resolve now returns `created`; a worker that lost `UNIQUE provider_event` adopts the winner's event and is routed through the **one shared** convergence path (identical facts converge; a `pending` R2 consequence may be completed; materially different facts append the controlled conflict decision). |
| C8-4 | `drain()` validated only the event-reference digest, so a changed but validly signed payload with the same event id bypassed the duplicate fact-digest conflict path and was translated as the recorded event. | `drain()` recomputes the **full** `event_fact_digest` before appending any decision; a mismatch preserves the recorded event unchanged, appends the controlled `conflicting_provider_event` decision with the R1 exception, and submits no evidence. |

`tests/phase-2a2t-contract.php` asserts all four source contracts; `tests/phase-2a2t-webhook-runtime.php`
proves the routing/receipt, attribution and drain behaviour; `tests/phase-2a2t-concurrency-runner.sh` makes
`duplicate_webhook` a real duplicate-delivery race and adds `conflicting_duplicate_webhook` (seventeen
modes total, up from sixteen).

## 5. Correction round 9 (independent review of `5b477b7` / tree `a45d56fb`)

The independent review of the round-8 candidate returned **FAIL — CORRECTION REQUIRED** on four blocking
findings. Each is closed additively in this candidate; no authority, capability, policy or provider call
is added, and no previous commit is rewritten.

| # | Blocking finding | Correction in this candidate |
| --- | --- | --- |
| C9-1 | `convergeExisting()` returned `recorded` when the recorded event had no decision row at all, so an event that was inserted and then left owing its decision (a crash between the event insert and its first decision) was never completed by a redelivery. | The one serialised decision path treats "no decision at all" as an owed decision: an identical redelivery takes the event's decision claim and appends the first decision exactly once. An event owes a decision while its **non-conflict** decision timeline is empty or still `pending`, so `converged = recorded` can no longer mean "undecided forever". |
| C9-2 | Pending-decision retries were not concurrency-safe: two deliveries could both read the same `pending` decision, both run `decide()` and the R1/R2 consequence, and both allocate the same next `decision_sequence`, so one failed on the unique index after performing work. | A durable per-event **decision claim** (`payment_provider_event_decision_claims`) with `UNIQUE event_claim (provider_event_id, active_claim_slot)` is taken before any decision work runs. The winner alone may translate and run the consequence; the claim's generation and token fence the settlement inside the same transaction that inserts the decision; the loser performs no work and converges on what the owner recorded; an expired claim is taken over by exactly one new generation. |
| C9-3 | The HTTPS precheck ignored the declared `PaymentExecutionRule::HTTPS_PROXY_HEADERS` and had no configured-proxy trust path, so a TLS-terminated deployment relying on a trusted `X-Forwarded-Proto: https` had every webhook refused as `https_required`. | The §9.2 transport rule is now complete and input-only: direct TLS, the local environment, or a proxy header that the operator has configured this site to trust and that is a member of the locked allowlist. An unconfigured, empty, malformed or non-allowlisted configuration trusts nothing, so a client can never mark its own delivery secure, and only a leftmost `https` in the scheme chain passes. |
| C9-4 | The route declared `OPTIONS`, but registering a method does not deliver that method to a callback: WordPress answers `OPTIONS` in `rest_handle_options_request()`, a `rest_pre_dispatch` filter that runs before normal route dispatch, so an `OPTIONS` delivery was answered with no receipt at all — breaking the invariant that every registered method is durably receipted. | The endpoint now registers its own `rest_pre_dispatch` interception at priority `1`, ahead of the core handler's `10`. It answers only an `OPTIONS` delivery to this endpoint's two registered route shapes (case-insensitively, with an optional trailing separator) and returns every other filter input untouched, so no other route is affected. A matched delivery runs the same controlled `process()` path as a routed `GET`/`PUT`/`PATCH`/`DELETE`: `refused`/`method_not_allowed`, `405`, the exact raw body digest and byte count, exactly one receipt and no event. |

`tests/phase-2a2t-contract.php` asserts all four source contracts; `tests/phase-2a2t-webhook-runtime.php`
proves the recorded-event recovery, the claim's own shape, the takeover of an abandoned claim, the
transport matrix and the `OPTIONS` interception (registered ahead of the core handler, receipted with the
exact raw body and never answered without one); `tests/phase-2a2t-failure-runtime.php` proves the claim
fence's atomicity; `tests/phase-2a2t-corruption-runtime.php` proves the claim aggregate fails closed; and
`tests/phase-2a2t-concurrency-runner.sh` adds `pending_decision_retry` and `undecided_event_recovery`
(nineteen modes total, up from seventeen).

The same round corrected one disposable-fixture defect the `OPTIONS` proof exposed: the webhook suite's
account selector was 41 characters long, but the route declares the account segment as
`[A-Za-z0-9_-]{1,32}`, so every REST-level delivery in that suite (`GET`, the proxy `POST` and the new
`OPTIONS`) would have been answered `rest_no_route` and receipted nothing — the routing proofs were
testing the fixture, not the endpoint. The selector now fits the declared segment, and the suite asserts
that it does. **Known observation, deliberately not corrected in this bounded round:**
`PaymentProviderAccountService::register()` does not bound `reference_code` to that 32-character segment,
so an operator could register a selector whose webhook URL can never match the route. That is a
registration-validation gap in the account surface rather than a method-receipt defect, and closing it
would add a refusal to the registration command; it is recorded for the owner rather than widened into
this round.

## 5A. Correction round 10 (independent review of `5d8c379` / tree `47f9062e`)

The independent review of the round-9 candidate returned **FAIL — CORRECTION REQUIRED** on two blocking
findings. Each is closed additively in this candidate; no authority, capability, policy or provider call
is added, no table beyond the one aggregate the phase already required is added, and no previous commit
is rewritten.

| # | Blocking finding | Correction in this candidate |
| --- | --- | --- |
| C10-1 | `Migrator.php` / `delnavazan-platform.php`: the new decision-claim table was added by the already-completed migration `028` while the schema identity stayed `28`. An installation that had previously completed `028` skipped its installer and then failed the strengthened verifier, because `payment_provider_event_decision_claims` did not exist and no subsequent migration was scheduled to create it — an unrecoverable state for a database the ledger could have repaired. | The claim aggregate is now migration `029_payment_event_decision_claim_authority`'s own storage and the plugin declares Schema `29`. Migration `028` is restored to its fifteen-table set; its verifier neither requires nor validates the claim table (it tolerates the scheduled sibling instead of failing closed on a repairable state); `verify_payment_event_decision_claim_schema()` runs after `029`, on current-schema verification and unconditionally before the schema option advances to `29`; and `029` is scheduled in the ledger and on the retained/current-schema paths exactly like every other phase migration. A completed-`028` database is repaired by one additive `dbDelta` of the claim table, with no backfill, no inferred claim and nothing settled. |
| C10-2 | `PaymentEventIntakeService.php` / `PaymentProviderRepository.php` / `PaymentExecutionRule.php`: the 120-second claim lease could expire while the original worker was still executing `decide()` and its R1/R2 mutations. A successor could take the claim over and complete the decision, and the original worker then resumed and executed the same R1/R2 work before its fence failure was discovered only during the later append — the token fenced the decision row, not the work it was meant to serialise. | Ownership now covers the whole decision operation. The lease is the **bounded window the owner works inside**: the intake opens it before any decision work runs, and immediately before every work unit — the R1 evidence submission and every R2 command, each one local transaction and never a provider call — it re-proves and renews the window with one fenced conditional statement that requires its own `claimed` generation, token and live slot **and** an unexpired lease, aborting the worker with `DecisionClaimWindowClosed` when the statement affects no row. A renewal can never resurrect an expired window, so a generation whose window closed (its lease lapsed, or exactly one successor generation took the claim over) performs no further decision or R1/R2 consequence work, appends nothing and converges on the current owner's decision, and the append stays fenced by the same generation and token. |

`tests/phase-2a2t-contract.php` asserts both source contracts,
`tests/phase-2a2t-migration-runtime.php` proves the completed-`028` repair rehearsal (028 completed, 029
unrecorded, no claim table → scheduled repair creates the table, records 029 and reaches Schema 29), the
completed-`029`/missing-table fail-closed case and its ledger-owned repair, and
`tests/phase-2a2t-concurrency-runner.sh` adds `stale_owner_after_lease_expiry` (twenty modes): the first
worker takes the claim for an owed decision and lets its own lease lapse while it still owns it, the
second worker takes the claim over (settled generation-2 claim) and completes the decision, and the
resumed first worker is stopped by the gate before its first R1/R2 unit. The suite records every
worker's arrival at the inherited R1/R2 work hooks, so it proves the stale generation reached no work
boundary at all, appended nothing, and left exactly one R1 settlement/evidence, one confirmed intent,
one collected cycle, one decision and one settled generation-2 claim.

The same round corrected three pre-existing self-inconsistencies in the phase's own static contract
suite, which were present in the reviewed tree and would have made `tests/phase-2a2t-contract.php` fail
regardless of this phase's behaviour: the `[C6-1]` source-order assertion compared the preflight and the
lease acquisition in the inverted direction (the code already satisfies its declared order), the "the
dispatch seal must not be the credential vault" scan matched the seal's own docblock mention of the
vault, and the sealing-boundary scan treated the inherited Phase-V `IntegrationSecretService` as an
unexpected boundary. The first is corrected to its declared meaning, the second by wording the docblock
without the class name, and the third by naming that inherited boundary explicitly in the scan. No
assertion was weakened: each still fails on the condition it names.

## 5B. Correction round 11 (independent review of `d60544a` / tree `0f000a8d`)

The independent review of the round-10 candidate returned **FAIL — CORRECTION REQUIRED** on one blocking
finding. It is closed additively here; no authority, capability, policy, provider call or table is added,
no previous commit is rewritten, and the recorded event row stays immutable.

| # | Blocking finding | Correction in this candidate |
| --- | --- | --- |
| C11-1 | `PaymentEventIntakeService.php` / `PaymentProviderRepository.php`: the fenced renewal proved and renewed the claim's window **before** a work unit ran, in its own transaction. The R1 submission and every R2 command open separate transactions and invoke WordPress hooks, so either could outlive the 120-second lease: another worker could then take the expired claim over atomically while the original worker still completed the R1/R2 mutation it had already started, noticing the loss only at its next gate. The lease bounded the decision row and the unit's entry, not the duration of the unit's work — so C10-2's "the owner is the only worker performing decision/consequence work" held only until the unit began. | A decision work unit is now fenced at the **connection's statement boundary, from inside the unit's own transaction**. The intake registers one listener on the declared `PaymentExecutionRule::DECISION_UNIT_FENCE_FILTER` (`query`) for exactly the unit's duration and removes it again in a `finally`; the listener returns every statement unchanged, passes `START TRANSACTION`/`ROLLBACK`/`SET …` through untouched (an unwind must never be blocked, and the opener is what creates the transaction the fence then holds), and precedes every other statement — the unit transaction's `COMMIT` included — with the fenced window proof: one `SELECT … FOR UPDATE` of the claim row that must return exactly one row (this worker's own `claim_generation` and `claim_token_digest`, `claim_state = 'claimed'`, its live slot and an unexpired `lease_expires_at`), renewing the lease first only when the statement runs outside any transaction. Two properties follow. The locking read holds the claim row for the rest of the unit's transaction, so a take-over — which needs that same row *and* an expired lease — can never interleave with a unit, and a unit's commit can never land outside the window it was granted. And a proof that finds no live window raises the controlled `DecisionClaimWindowClosed` **before** the statement executes, so the R1/R2 service owning the transaction rolls the whole unit back: the stale generation commits **no** statement of the unit it had already started. It then releases the live claim it appended nothing to (its own generation and token fence the release, so a take-over generation releases nothing) and converges, so the event is completed by the next generation instead of waiting out a lease nobody is working inside. |

`tests/phase-2a2t-contract.php` asserts the corrected source contract (the declared boundary and its
unfenced statement set, the locking-read verdict, the renewal-only-outside-a-transaction rule, the
per-unit registration and removal, the re-entrancy guard, both fenced unit call sites, and the release),
and `tests/phase-2a2t-concurrency-runner.sh` adds `stale_owner_inside_r1_unit` and
`stale_owner_inside_r2_unit` (twenty-two modes). In each new mode the first worker takes the event's
decision claim, reaches the R1 evidence submission (respectively the R2 collection-intent confirmation)
and, from inside that unit's own transaction, lets the window the unit is running inside lapse past
expiry; a second worker delivers the same event while the first is inside its mutation and records what
the stale generation committed before completing the decision as the next generation. The verifier proves
the stale generation's unit was rolled back inside its own transaction — no R1 evidence and no settlement
at all in the R1 case (after which the successor's own R1 and R2 units apply the ordered consequence
exactly once, confirming one intent and collecting one cycle), and in the R2 case the R1 unit that
finished inside its window stays committed while the intent is still `submitted`, the cycle is still
`payment_required` and neither a confirmation event nor a confirmation command exists. In that second case
the event's own occurrence instant is deliberately older than the settlement its own R1 unit committed, so
the successor's re-decision is the controlled `stale_provider_event` refusal — the R2 consequence the
stalled generation must not duplicate is provably absent, and no generation confirms or collects anything.
Both cases leave exactly one R1 evidence/settlement, one decision appended by the successor, one released
generation-1 claim and one settled successor claim.

The same round corrected one further pre-existing self-inconsistency in the phase's own static contract
suite, again present in the reviewed tree: the runtime-suite vocabulary check required
`provider_credentials_unconfigured`, `live_execution_not_authorised` and `dispatch_in_flight` to appear in
`tests/phase-2a2t-runtime.php`, which never contained them, so the suite would have failed on its own
content assertion regardless of behaviour. The runtime suite now asserts all three as members of the
locked `PaymentExecutionRule::ATTEMPT_REASONS` vocabulary — a real assertion of the closed outbound
boundary, not a weakened one.

## 5C. Correction round 12 (independent review of `09c4134` / tree `944b45c7`)

The independent review of the round-11 candidate returned **FAIL — CORRECTION REQUIRED** on one blocking
finding. It is closed additively here; no authority, capability, policy, provider call or table is added,
no previous commit is rewritten, and the recorded event row stays immutable.

| # | Blocking finding | Correction in this candidate |
| --- | --- | --- |
| C12-1 | `PaymentProviderRepository.php` / `PaymentEventIntakeService.php`: the fenced `claimed → settled` transition of the decision append required only `claim_state`, `claim_generation` and `claim_token_digest`, so it did not require the claim's live slot or an unexpired lease. The lease therefore bounded the R1/R2 work units but not the append that publishes their outcome: a lease that lapsed after the last work unit but before the decision-append transaction still let the stale generation settle its live claim and append its decision, publishing authority inside a window the contract had already closed (§9.5 C10-2 makes the lease the bounded window the *whole* decision operation, the append included, runs inside). Because the intake's zero-row path merely converged, an event whose lease lapsed with no successor generation having taken the claim over was also left owing its decision behind a lease nobody was working inside. | The append is now bounded by the same window as the work. `settleDecisionClaim()` additionally requires `active_claim_slot = 1` **and** a non-null, unexpired `lease_expires_at`, judged against the same `$now` the statement stamps the row with, so an append that runs after the lease has lapsed affects zero rows and publishes nothing — exactly as a replaced generation publishes nothing. `appendDecisionUnderClaim()` treats that zero-row outcome as a closed window: it rolls back, releases the live claim it appended nothing to (fenced by its own generation and token, so a successor's claim is never touched) and converges, so an expired-but-not-yet-taken-over claim never strands the event and the next delivery completes it. The append seam between the final work unit and the fence is observable through the new §8.4 hook `dzn_phase_2a2t_before_provider_event_decision_append` (ids only, outside the worker context and outside any transaction). |

`tests/phase-2a2t-contract.php` asserts the corrected source contract (the settlement's own live-slot and
unexpired-lease conditions, judged against its own `$now`; the append seam ahead of the fence; and the
release-then-converge order of a refused append), `tests/phase-2a2t-failure-runtime.php` proves the
transition behaviourally (an append that runs past the claim's lease settles nothing and leaves the claim
live, for that generation to release or for exactly one successor to take over), and
`tests/phase-2a2t-concurrency-runner.sh` adds `stale_owner_at_decision_append` (twenty-three modes). In
that mode the first worker takes the event's decision claim for an owed decision, completes **every**
R1/R2 work unit of the decision operation and then lets the window it still exclusively holds lapse at the
append seam — aged in the database, with no successor generation having taken its claim over. The verifier
proves the owner reached the R1/R2 work boundaries (so the window closed at the append, never before the
work); that the append — and never a take-over — refused the stale generation: its own generation-1 claim
ends `released` with no live slot and no lease, no generation above 1 exists and no live claim survives;
that the stale generation appended nothing and reported the event as still owing its decision; and that the
next delivery is the one that completes the event with its single decision, while the work the stale
generation committed inside its window stands exactly once (one R1 evidence, one R1 settlement, one
confirmed collection intent, one collected renewal cycle).

## 5D. Correction round 13 (independent review of `e92a627` / tree `0f4ab9b6`)

The independent review of the round-12 candidate returned **FAIL — CORRECTION REQUIRED** on one blocking
finding. It is closed additively here; no authority, capability, policy, provider call or table is added,
no previous commit is rewritten, and the recorded event row stays immutable.

| # | Blocking finding | Correction in this candidate |
| --- | --- | --- |
| C13-1 | `PaymentEventIntakeService.php:642-649`: the round-12 append fence captured `$now` **before** it fired the observable append seam `dzn_phase_2a2t_before_provider_event_decision_append`, and passed that captured instant to `settleDecisionClaim()`, which compared `lease_expires_at >= $now` and stamped `settled_at`/`updated_at` from it. A hook callback on that seam — or any delay before the statement acquired the claim row's lock — could therefore outlive the 120-second lease while the update still compared the lease against the instant read before the seam, settling the live claim and appending the decision after the window had closed. That violates C10-2, which makes the lease the bounded window the *whole* decision operation, the append included, runs inside, and requires the append to be fenced at the moment it runs. | The conditional transition now takes its verdict **at statement execution**, from one database-time expression. `settleDecisionClaim()` requires the owner's own `claim_state = 'claimed'`, its `claim_generation`, its `claim_token_digest`, its `active_claim_slot = 1` **and** a non-null `lease_expires_at >= UTC_TIMESTAMP()`, and stamps `settled_at`/`updated_at` with that same `UTC_TIMESTAMP()` — so the window verdict and the settlement can never be derived from two different clock readings. The transition takes **no** instant parameter at all, so no caller can supply an instant it read before the seam, and `appendDecisionUnderClaim()` now reads the instants it records on the appended row only *after* the seam. A seam delay therefore changes the verdict itself: an append that runs after the lease has lapsed affects zero rows and publishes nothing — exactly as a replaced generation — and the owner releases the live claim it appended nothing to and converges, so the event is completed by the next delivery instead of being stranded behind a lease nobody was working inside. |

`tests/phase-2a2t-contract.php` asserts the corrected source contract (the database-time lease predicate and
its matching settlement stamp inside the one conditional statement, the absent instant parameter, the
claim-identity-only call site and the intake's post-seam read of its own row instants);
`tests/phase-2a2t-failure-runtime.php` proves the transition behaviourally with a **real** delay — a claim
taken with a short, still-live window is settled only after the clock has genuinely passed that window, and
the statement settles nothing and leaves the claim live; and `tests/phase-2a2t-concurrency-runner.sh`'s
`stale_owner_at_decision_append` no longer writes the claim row at all: the owner's own window is let lapse
**in real elapsed time** at the append seam, so that mode runs for the structural 120-second
decision-claim lease. The verifier proves the lapse was a real one — the instant the owner read from its own
live claim at the seam is that claim's structural window, at least the full
`DECISION_CLAIM_LEASE_SECONDS` forward of the instant the claim was taken, and the release that follows the
refused append lands strictly after it — beside the existing proof that no successor generation replaced the
stale one, that the stale generation appended nothing and reported the event as still owing its decision,
and that the next delivery is the one that completes the event exactly once.

This round supersedes the round-12 mechanism recorded in §5C in two places. The append no longer judges the
window against an instant its caller captured before the seam (the transition judges it inside its own
statement, from the database's own clock), and the append race no longer ages its own claim row to simulate
the lapse (the lapse is produced by real elapsed time, with the row untouched).

## 5E. Correction round 14 (independent review of `057acae` / tree `1c5545c5`)

The independent review of the round-13 candidate returned **FAIL — CORRECTION REQUIRED** on one blocking
finding. It is closed additively here; no authority, capability, policy, provider call or table is added,
no previous commit is rewritten, and the recorded event row stays immutable.

| # | Blocking finding | Correction in this candidate |
| --- | --- | --- |
| C14-1 | `PaymentProviderRepository.php:178`: the round-13 `claimed → settled` transition took its verdict from one database-time expression *inside* the statement that also settled the claim — but MySQL evaluates `UTC_TIMESTAMP()` **once, at query start**, not when the statement reaches the row it is judging. An append `UPDATE` that started while the lease was live, blocked on another transaction's lock of the claim row until after expiry, and acquired the row only afterwards therefore still satisfied `lease_expires_at >= UTC_TIMESTAMP()` with the instant it began waiting, settled the live claim and appended the decision after the decision window had closed — violating the C13 contract that a delay before the append statement acquires its row lock must change the verdict. | The transition now takes the claim row's lock **first**, and retains it for the rest of the append transaction. `settleDecisionClaim()` issues a fenced `SELECT … FOR UPDATE` carrying the owner's own `claim_state = 'claimed'`, its `claim_generation`, its `claim_token_digest`, its `active_claim_slot = 1` and the same non-null `lease_expires_at >= UTC_TIMESTAMP()` predicate, and only then runs the conditional `claimed → settled` update — whose lease predicate and settlement stamps still share one `UTC_TIMESTAMP()` expression, because it can no longer wait for that row. The wait for the row therefore lands on the locking read, where it decides nothing, and the update takes its own database-time verdict *after* the wait rather than before it. The locking read can only ever refuse a claim the update would also refuse (a row it cannot see is not this generation's live claim); the update's affected-row count remains the only proof of ownership, so a window that lapsed while the append was queued settles nothing, stamps nothing and publishes nothing. |

`tests/phase-2a2t-contract.php` asserts the corrected source contract (the locking read's own fence over the
claim identity, live slot and unexpired lease; `FOR UPDATE` before the `SET claim_state='settled'` update; and
the append's claim-identity-only call site, database-time stamp and release-then-converge order unchanged);
and `tests/phase-2a2t-concurrency-runner.sh` adds `append_blocked_on_claim_row` (twenty-four modes): the second
worker takes its **own transaction** on the owner's claim row and holds it until the live window the owner's
append was granted has lapsed, while the owner holds at the append seam and then enters the append transaction
— whose first statement is now that fenced locking read, so the append is queued behind the blocker's lock
while its window is still open. The blocker attributes the queued wait to exactly that claim row
(`performance_schema.data_lock_waits` joined to `data_locks` on the claim table's `PRIMARY` index with the lock
data equal to the claim id; InnoDB's `LOCK WAIT` state, with the waiting statement naming the claim table,
where the runtime does not expose those tables) and releases the row only after the window had genuinely
lapsed in real elapsed time. The verifier proves the queued append **settled no claim and appended no
decision** — the states an update that judged the window with the instant it began waiting would have turned
into one settled claim and one published decision — that the owner's own generation-1 claim ends `released`
with no live slot and no lease and that no generation above 1 exists, that the owner reports the event as
still owing its decision, and that the delivery following the release completes the event with exactly one
decision (the controlled `stale_provider_event` refusal of the obligation the owner's in-window R1 settlement
already covers), while the work that owner committed inside its window stands exactly once.

This round supersedes the round-13 mechanism recorded in §5D in one place: the append's window verdict is no
longer taken by the statement that waits for the claim row's lock. The locking read takes the wait — and it
decides nothing — so the conditional update that judges the window runs only once the row is already held.

## 6. Evidence executed in this environment

| Check | Result |
| --- | --- |
| `git diff --check` | pass (no whitespace or conflict-marker defects) |
| Shell syntax of the concurrency runner (`sh -n`) | pass |
| Git object integrity (`git fsck --no-dangling`, `git status`) | pass |
| Source scans: [C10-1] migration `028` creates exactly the fifteen declared seam tables once each, migration `029` creates exactly the one decision-claim table, the two sets are disjoint and their union is the declared sixteen-table set, and `028`'s verifier neither requires nor validates the claim aggregate; [C10-2] the renewal statement requires the owner's own generation, token, live slot and an unexpired lease and can never renew an expired window, and both the R1 submission and every R2 command are gated on it; exact vocabulary strings; no forbidden column pattern; no `wp_schedule_*`/`curl_*`/`wp_remote_*` in Core; `wp_set_current_user` only inside `PaymentExecutionWorkerContext`; only an adapter seals or opens an envelope; the controller names no proxy header outside the locked allowlist | pass |
| Re-emulation of `tests/phase-2a2t-contract.php`'s static assertions in this environment (all 203 assertion sites on this tree: 177 `str_contains` with their polarity and OR-pairs, 7 `substr_count`, 11 `strpos`/ordering assertions, the schema/build identity regexes, the declared-suite file globs, and the two `CREATE TABLE` sets) | pass — no assertion in the suite fails on this tree (PHP cannot run here, so this is a faithful re-implementation of its string and ordering predicates, not the suite itself) |
| Source review of the changed sources (every changed hunk read) | pass — the endpoint's `rest_pre_dispatch` interception is its only participation on that hook, it is registered ahead of the core `OPTIONS` handler, it matches only the endpoint's two route shapes, and it re-uses the one precheck/receipt path instead of adding a second refusal implementation |
| Source scans re-run this round (the two Phase-T migrations together declare exactly the sixteen tables once each — fifteen in `028`, one in `029`; no `wp_schedule_*`/`curl_*`/`wp_remote_*` in Core; `wp_set_current_user` only inside `PaymentExecutionWorkerContext`; no proxy-header literal outside the locked allowlist; exactly one `rest_pre_dispatch` registration in the plugin) | pass |
| Delimiter/quote balance of every changed PHP file (comments and strings stripped, then `()`/`{}`/`[]` balance) | pass (a delimiter sanity check only — **not** `php -l`, which this environment cannot run) |
| Correction round 11: full AST parse of every changed PHP file and every changed test file with a real PHP 8 parser (`php-parser`), plus `sh -n` on the concurrency runner | pass — no syntax error in any changed file (this is a parser acceptance check, **not** `php -l`, which this environment cannot run) |
| Correction round 11: extended re-emulation of `tests/phase-2a2t-contract.php`'s static assertions, now covering its `foreach` needle lists — the required-mode list, the vocabulary lists and the required-absence scans — as well as its direct `str_contains`/`substr_count`/`strpos` assertions (254 literal sites, each needle-list assertion also polarity-checked by hand) | pass — every literal assertion holds on this tree, including the three runtime-suite vocabulary needles the previous round's narrower re-emulation had missed and the two new concurrency modes. The assertions that remain unresolved by name in the emulation (`$interceptBody`, interpolated needles, `&&`/`\|\|` pairs where the other operand holds) are the review of the unchanged controller and are unaffected by this round |
| Correction round 12: full AST parse of every changed PHP file and every changed test file with a real PHP 8 parser (`php-parser` 3.7.0), plus `sh -n` on the concurrency runner | pass — no syntax error in any changed file (a parser acceptance check, **not** `php -l`, which this environment cannot run) |
| Correction round 12: replay of the new `[C12-1]` assertions of `tests/phase-2a2t-contract.php`, and of the pre-existing assertions this round could disturb (the release-call count, the settlement-before-sequence-allocation order, the claim-before-decide order and the earlier stale-owner/in-unit needles), against the changed sources and the whole concurrency suite | pass — every replayed predicate holds on this tree (PHP cannot run here, so this is a faithful re-implementation of its string and ordering predicates, not the suite itself) |
| Correction round 13: replay of the new `[C13-1]` assertions of `tests/phase-2a2t-contract.php` — the database-time lease predicate (`lease_expires_at >= UTC_TIMESTAMP()`), its matching `settled_at`/`updated_at` stamp, the absence of any instant parameter on the transition, the claim-identity-only call site and the intake's post-seam read of its own row instants — plus every pre-existing assertion this round could disturb (the single-`settleDecisionClaim` count and order checks inside the append body, the settlement-before-sequence-allocation order, the append-seam-before-fence order, the release-then-converge order, the C9-1 claim-fence-before-sequence order and the stale-owner/in-unit concurrency needles), re-implemented faithfully against the changed sources and the whole concurrency suite | pass — all 25 replayed predicates hold on this tree (PHP cannot run here, so this is a faithful re-implementation of its string and ordering predicates, not the suite itself) |
| Correction round 13: `php -l` and a `php-parser` AST parse of the changed PHP sources and test files | **not executed — neither PHP nor a PHP-parser runtime is reachable in this environment**; every changed hunk was read in full and the suite's own assertions were re-emulated instead |
| Correction round 13: runtime horizon of the changed `stale_owner_at_decision_append` mode — it now lets the owner's own structural 120-second window lapse in real time, with the claim row never written, so it is the longest mode in the matrix | noted and bounded, not executed — the contender's gate wait inside the worker was widened from 90 s to 360 s for that mode; the runner's own `waitfor` markers are written before the work and are unaffected, and the runner waits for both workers without a timeout |
| Correction round 14: full AST parse of every changed PHP file and every changed test file with a real PHP 8 parser (`php-parser` 3.7.0), plus `sh -n` on the concurrency runner | pass — no syntax error in any changed file (a parser acceptance check, **not** `php -l`, which this environment cannot run) |
| Correction round 14: replay of the new `[C14-1]` assertions of `tests/phase-2a2t-contract.php` — the locking read's fence over the claim identity, live slot and unexpired lease, `FOR UPDATE` ahead of `SET claim_state='settled'`, and the new concurrency needles — plus every pre-existing assertion this round could disturb (the database-time lease predicate and its matching settlement stamp, the absent instant parameter, the claim-identity-only call site, the single-`settleDecisionClaim` count and order checks, the append-seam-before-fence order, the release-then-converge order and the stale-owner/in-unit concurrency needles), re-emulated faithfully against the changed sources and the whole concurrency suite | pass — every replayed predicate holds on this tree (PHP cannot run here, so this is a faithful re-implementation of its string and ordering predicates, not the suite itself) |
| Correction round 14: runtime horizon of the added `append_blocked_on_claim_row` mode — the blocker holds the claim row in its own transaction until the owner's structural 120-second window lapses, and the owner's connection is allowed to wait on that row for the whole window (`SET SESSION innodb_lock_wait_timeout=600`, since the server default is 50 s) | noted and bounded, not executed — the mode is bounded by the same structural lease as `stale_owner_at_decision_append`; the queue observation is polled inside the window (bounded at 60 s, well inside the 120-second lease), and the runner waits for both workers without a timeout |
| `tests/phase-2a2t-contract.php` | **not executed — PHP is unavailable in this environment** |
| `tests/phase-2a2t-migration-runtime.php`, `-runtime.php`, `-webhook-runtime.php`, `-secret-runtime.php`, `-corruption-runtime.php`, `-failure-runtime.php` | **not executed — PHP and the disposable WordPress + MariaDB runtime are unavailable in this environment** |
| `tests/phase-2a2t-concurrency-runner.sh` (all twenty-four modes) | **not executed — the disposable container runtime is unreachable in this environment** |

The runtime suites are written and wired exactly as the contract's §17 requires, and they were
**not** run here. Running them on the disposable runtime (fresh install, 26→29 upgrade, migration,
webhook, secret, corruption, failure and the full concurrency matrix) is the mandatory acceptance gate
for this candidate and remains outstanding.

## 7. Explicit non-authorisation

No live Stripe API call, credential, webhook secret value, charge, refund, payout, provider dashboard
change, notification delivery, Theme/NIU change, Amelia write/removal, deployment, production access,
production cutover or merge occurred or is authorised by this record. `LIVE_EXECUTION_PROVIDERS` and
`PROVISIONABLE_PROVIDERS` are empty, so the Stripe adapter is provably incapable of an outbound call and
no code path can store a Stripe credential — not even through the capability-authorised vault surface.

## 8. Definition of done — outstanding items

1. Execute every §17 suite on the disposable runtime and record the results.
2. Confirm the Schema 027/028 ledger assumption is recorded either way (Phase S owning 027, or 027
   deliberately skipped) as the contract's §19 prerequisite requires.
3. Independent review of the corrected candidate commit and tree. Correction round 12 closed the one
   blocking finding the review of `09c4134381ba31c8332a0561bec96c9cbd5238e9` raised — the decision append
   is bounded by the claim's window: the `claimed → settled` transition requires the owner's own live slot
   and an unexpired lease as well as its generation and token, so a lease that lapses after the final R1/R2
   work unit publishes nothing, the owner releases the live claim it appended nothing to and converges
   instead of stranding the event, and the next delivery completes it. Correction round 13 closes the one
   blocking finding the review of `e92a62747b82f8a37838f886a1034eb770f65e37` raised on top of it: that
   window is judged **at the instant the transition itself runs**, from one database-time expression that
   fences the predicate and stamps the settlement alike, and the transition takes no instant parameter — so
   a hook callback at the append seam, or any delay before the statement acquires the claim row's lock, can
   never settle a window that has already closed and publish a decision the contract does not let it own.
   Correction round 14 closes the one blocking finding the review of
   `057acaeea06bf8d0c2eef2f3039c66295988a3d9` / tree `1c5545c585e49b46b2f7608d89965f07bf3dd3a5` raised:
   because `UTC_TIMESTAMP()` is evaluated once at query start, the append's window verdict is now taken by a
   transition that already holds the claim row — a fenced locking read takes the row lock (and the wait),
   where the wait decides nothing, and the conditional `claimed → settled` update that judges the window runs
   only afterwards — and the concurrency matrix's new `append_blocked_on_claim_row` proves that an append
   queued behind another transaction's lock of that row until the lease lapsed settles no claim and appends
   no decision.
