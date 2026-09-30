# Phase 2A.2-T — Provider-Neutral Payment Execution Seam & Stripe Adapter/Webhook (Schema 029)

**Status:** implementation contract (preflight). Planning/audit only.
**Schema:** 029 (`028_payment_execution_seam_provider_adapter`, plus — since correction round 10 — the decision-claim aggregate `029_payment_event_decision_claim_authority`)
**Build:** `phase2a2t-payment-execution-seam-stripe-adapter-20260925.14` (proposed; correction round 14)
**Correction round 2:** independent review of commit `adbd78795f6e11ee7731c985975a7276f8351659`
(tree `f96569c8e70254d436b8571db64be0d057583fef`) returned FAIL on six blocking findings. §0 records
each finding and its correction, and every corrected clause carries a `[C2]` marker so a reviewer can
locate all six changes without re-reading the document.
**Correction round 3:** independent review of commit `13f0c8ba4ccd2c2bdf1dad9927cfe0a64cb96c68`
(tree `b0e0686a8d9b9a32086e19f5ee170fd99045aa14`) returned FAIL on two blocking findings — the
execution path was not recoverable or serialised across the provider-call boundary, and the Schema 028
verifier contradicted its own reference model while leaving one identifier reference undefined. §0A
records each finding and its correction, and every corrected clause carries a `[C3-1]` or `[C3-2]`
marker so a reviewer can locate both changes without re-reading the document.
**Correction round 4:** independent review of commit `111c7624c6ec4f032e6a3042658e37f01f710e20`
(tree `3c020be9fec1d387f7df06b317d630aa7797fdeb`) returned FAIL on three blocking findings — a
re-drive could not rebuild the request it must settle or re-issue (every provider/account/object
reference the port needs was raw and memory-only), an expired `in_flight` lease was described as
takeable with no atomic takeover or fencing, and the validated worker principal was never actually
adopted, so anonymous intake had no authorised actor. §0B records each finding and its correction, and
every corrected clause carries a `[C4-1]`, `[C4-2]` or `[C4-3]` marker so a reviewer can locate all
three changes without re-reading the document.
**Correction round 5:** independent review of commit `ffe06d12980615a2ae55721a0b2a66178678ae6f`
(tree `60320eb0c04dc44a593abc360b77a6ecc4793728`) returned FAIL on three blocking findings — the
descriptor-refusal path could not be represented (a durable `refused` result could not coexist with an
undeletable dispatch claim that rule 5 called corruption), an expired-lease takeover had no fenced
route back to a re-issue because the only acquisition transition accepts `claimed` at generation `1`,
and the sealed descriptor carried no binding value even though the required transplant-detection proof
has to live inside a `sodium_crypto_secretbox` payload with no additional-authenticated-data channel.
§0C records each finding and its correction, and every corrected clause carries a `[C5-1]`, `[C5-2]` or
`[C5-3]` marker so a reviewer can locate all three changes without re-reading the document.
**Correction round 6:** independent review of commit `da294851fe814bc9947a694c5ba27f75938702e1`
(tree `2645e8612fe2f5281b70ea403a610ea8517daa76`) returned FAIL on one blocking finding — the initial
dispatch moved its claim `claimed → in_flight` before the port opened and bound the descriptor, so a
descriptor failure that preceded any provider call could not take the contract's sole
`claimed → released` / `refused` descriptor-refusal path and had to be recorded as an attempted outcome
or left permanently dispatching. §0D records the finding and its correction, and every corrected clause
carries a `[C6-1]` marker so a reviewer can locate the change without re-reading the document.
**Correction round 7:** independent review of commit `61c62be359919599a27f4043af93cb3953127700`
(tree `314bf4bb7e1e5a359e3c1e4a68194152e0d7dc94`) returned FAIL on two blocking findings — the
initial-dispatch preflight could not prove the descriptor was bound to the *actual claim* (it received
neither the claim nor its stored idempotency-key digest, and no Core comparison between its `ok` verdict
and the durable command and claim rows was required before the lease was acquired), and the second
descriptor open inside `submit`/`cancel`/`reconcile` reintroduced a post-lease no-call failure path that
no fenced transition could represent. §0E records each finding and its correction, and every corrected
clause carries a `[C7-1]` or `[C7-2]` marker so a reviewer can locate both changes without re-reading the
document.
**Correction round 8:** independent review of commit `91bf288cd1cf4d5c08e7c99cb310a4d0743113b1`
(tree `dec38b700c70f116cae53a1df64db011dbd99081`) returned FAIL on four blocking findings — a
non-`POST` delivery was dropped by routing so it could never be receipted and a precheck-refused request
had its raw body replaced before receipt, attribution accepted any historical mapping of the event object
instead of proving the mapping owned the obligation the event named, a duplicate delivery that lost the
unique event index was translated instead of converging, and a `drain()` compared only the event
reference so a changed but validly signed payload could be translated as the recorded event. §0F records
each finding and its correction, and every corrected clause carries a `[C8-1]`…`[C8-4]` marker so a
reviewer can locate all four changes without re-reading the document.
**Correction round 9:** independent review of commit `5b477b72d97a329e7bc02ce041be8958af85fe81`
(tree `a45d56fb8e1cdbe9785175ff272cf8ef5947b434`) returned FAIL on four blocking findings — a verified
event that was recorded but left with no decision was never completed by a redelivery, a pending-decision
retry was not concurrency-safe (two deliveries could both run the translation and the R2 consequence and
then collide on the decision sequence), the §9.2 HTTPS requirement ignored the declared proxy-header
allowlist and had no configured-proxy trust path, and the route's declared `OPTIONS` method was answered
by WordPress's own `OPTIONS` handler — which runs outside normal route dispatch — so that registered
method was never receipted. §0G records each finding and its correction, and every corrected clause
carries a `[C9-1]`…`[C9-4]` marker so a reviewer can locate all four changes without re-reading the
document.
**Correction round 10:** independent review of commit `5d8c379f92cef13baf0a818850661fcb7658509a`
(tree `47f9062eea42aabf75fdc21bb80190c07f7fc4be`) returned FAIL on two blocking findings — the durable
per-event decision claim was added by the already-completed migration `028` while the schema identity
stayed `28`, so an installation that had completed `028` skipped its installer and then failed the
strengthened verifier for a table no scheduled migration would create; and the claim's 120-second lease
could expire while the worker that owned it was still executing `decide()` and its R1/R2 work, so a
successor could take the claim over, complete the decision, and then have the original worker resume and
execute the same R1/R2 work before its fence failure was discovered only at the append. §0H records each
finding and its correction, and every corrected clause carries a `[C10-1]` or `[C10-2]` marker so a
reviewer can locate both changes without re-reading the document.
**Correction round 11:** independent review of commit `d60544a7c30231ec82b887b8238561b4c46dfce0`
(tree `0f000a8d9df3312488534729efd44ce961579f27`) returned FAIL on one blocking finding — the round-10
lease renewal fences *entry* to an R1/R2 work unit, not the duration of its work: the renewal commits
before `CommercialPaymentService::ingest()` or an R2 command begins, those units run in their own
transactions and invoke WordPress hooks, so either may outlive the 120-second lease, another worker can
then take the expired claim over atomically, and the original worker still completes the R1/R2 mutation it
had already started — its next gate notices the loss, but too late. §0I records the finding and its
correction, and every corrected clause carries a `[C11-1]` marker so a reviewer can locate the change
without re-reading the document.
**Correction round 12:** independent review of commit `09c4134381ba31c8332a0561bec96c9cbd5238e9`
(tree `944b45c7fcb9570442d18468664396ee823994e4`) returned FAIL on one blocking finding — the decision
append was fenced by claim state, generation and token only, so it did not require an active, unexpired
lease: a lease that lapsed after the last R1/R2 work unit but before the decision-append transaction still
allowed the stale generation to settle the claim and publish its decision, even though the lease is the
bounded window the *whole* decision operation, the append included, runs inside. §0J records the finding and
its correction, and every corrected clause carries a `[C12-1]` marker so a reviewer can locate the change
without re-reading the document.
**Correction round 13:** independent review of commit `e92a62747b82f8a37838f886a1034eb770f65e37`
(tree `0f4ab9b6ab8de3021e0aa7b7e1e0f48f079c34df`) returned FAIL on one blocking finding — §0J's fenced
`claimed → settled` transition did require the owner's live slot and an unexpired `lease_expires_at`, but it
judged that lease against an instant the intake captured **before** the observable append seam: a hook
callback at that seam (or any delay before the statement acquired its row lock) could therefore outlive the
120-second lease while the update still compared the lease against the older instant, settling the claim and
appending a decision after the window had closed. The transition now takes its verdict at the instant the
statement itself runs, from one database-time expression that fences the predicate and stamps the settlement
alike, so no instant read before the seam can settle a lapsed window. §0K records the finding and its
correction, and every corrected clause carries a `[C13-1]` marker so a reviewer can locate the change
without re-reading the document.
**Correction round 14:** independent review of commit `057acaeea06bf8d0c2eef2f3039c66295988a3d9`
(tree `1c5545c585e49b46b2f7608d89965f07bf3dd3a5`) returned FAIL on one blocking finding — the append
transition took its database-time window verdict from the same statement that waited for the claim row's
lock, and MySQL evaluates `UTC_TIMESTAMP()` once at statement start, so an append that blocked on another
transaction's lock of that row until after the lease had lapsed still settled the claim and published its
decision using the instant it began waiting. The transition now takes the claim row's lock first, with its
own fenced locking read — where a wait decides nothing — and runs the conditional `claimed → settled`
update only once the row is held, so its verdict is taken after any wait for it. §0L records the finding
and its correction, and every corrected clause carries a `[C14-1]` marker so a reviewer can locate the
change without re-reading the document.
**Base:** `main` with Phase 2A.2-R2 merged (Schema 26). R1 is already authoritative on `main`;
R2 / Schema 26 is the active candidate and is a hard dependency of the R2-collection half of this
contract (§6.2, §19).

This contract is implementation-ready for the Phase T execution seam, the provider object mapping
registry, the signing/secret isolation boundary and the provider event (webhook) intake. It does not
authorise deployment, merge, live provider calls, credential provisioning, charges, refunds,
notification delivery, production access, or Theme work. Every unresolved renewal/provider policy
remains unresolved by design (§20).

## 0. Correction round 2 (independent review of `adbd787` / tree `f96569c8`)

The reviewed candidate declared its execution commands append-only while finalising `result_state` /
`result_id` on them, left child references without a declared parent identifier, allowed a renewal to
settle R1 without confirming the R2 cycle, selected a webhook signing secret from a provider-only
route, exposed a capability-authorized surface that could store a Stripe credential, and permitted
several active unscoped secrets. Each blocking finding is resolved below. No clause outside these six
findings is changed by this round, and no authority, table, capability or policy is added beyond what
the findings require.

| # | Blocking finding (review of `adbd787`) | Correction made in this round |
| --- | --- | --- |
| C2-1 | §8.2–§8.3/§12.2: execution commands were declared append-only, yet the flow inserted `authorised` and then "finalised" `result_state`/`result_id`; no immutable terminal-event structure existed. | `payment_execution_commands` is now **immutable authorisation evidence** — insert-once, no result column, no `updated_at` — and the terminal state lives only in the new append-only `payment_execution_results` table (one row per command). The effective command state is derived from that row and is never stored twice (§8.1–§8.3, §12.2, §12.3). |
| C2-2 | §12.2: `payment_execution_attempts.execution_command_id`, `payment_provider_events.receipt_id` and `payment_provider_event_decisions.provider_event_id` referenced tables whose schemas omitted `id`, and the §8.4 decision hook required a `$decisionId`. | Every Schema 028 table now declares `id bigint unsigned NOT NULL AUTO_INCREMENT` with `PRIMARY KEY(id)`, and §12.3 fixes the exact type, nullability, owning table, index and application-enforced ownership rule of **every** `*_id` reference. The §8.4 hook ids are exactly the declared `id` of `payment_provider_events` and `payment_provider_event_decisions`. |
| C2-3 | §9.7/§10: the worker held only the payment-event, R1-ingest and collection-intent capabilities, so a successful renewal could settle R1 and confirm the intent while the cycle stayed in `payment_required`, stranding `bind_next_term`. | §9.7 fixes the worker's exact capability set (now including `dzn_manage_renewal_cycles`) and §10.1 specifies the ordered, idempotent, resumable R2 consequence that confirms **both** the collection intent (`confirmed`) and the renewal cycle (`collected`), with a recorded, operator-visible refusal when the cycle is not collectable. No new R2 operation and no new R2 authority is invented. |
| C2-4 | §9.1/§9.4: the route and translator interface identified only a provider, while signature verification requires the matching account, mode and signing secret; reading the body to obtain the account would violate verify-before-parse. | §9.1 adds a non-secret, pre-parse account selector to the route, §9.4 defines the resolved verification context (`account id`, `mode`, `key_version`) that is passed into verification, allows exactly one secret per request, and fails closed on a missing, unknown, ambiguous, inactive or mode-mismatched selection. |
| C2-5 | §11.3/§11.5/§13: `PaymentSecretVault::store` was capability-authorized for any provider, so an authorized caller could store a Stripe API key and defeat the claim that no code path can write a Stripe credential. | §11.3/§11.5 now refuse **every** provider secret write while the locked `PROVISIONABLE_PROVIDERS` constant is empty. The only storage path in the build is the constant-gated test vault (`DZN_PLATFORM_PAYMENT_TEST_VAULT`), and §17 adds the proof that every production surface rejects the write. |
| C2-6 | §12.1: `payment_provider_secrets.payment_provider_account_id` permitted NULL while a composite unique key was relied on to guarantee one active secret per scope; MySQL permits repeated NULLs, so several active unscoped secrets could exist. | §12.1 makes the account scope `bigint unsigned NOT NULL` for every secret row, states the active-slot uniqueness invariant over the complete scope, and §12.4/§17 verify both the index and the invariant. |

## 0A. Correction round 3 (independent review of `13f0c8b` / tree `b0e0686a`)

The reviewed candidate returned FAIL on two blocking findings: the outbound execution path was neither
recoverable nor serialised across the provider-call boundary, and the Schema 028 verifier contradicted
its own reference model while leaving one identifier reference undefined. Each is resolved below.
No clause outside these two findings is changed by this round, and no authority, table, capability or
policy is added beyond what the findings require.

| # | Blocking finding (review of `13f0c8b`) | Correction made in this round |
| --- | --- | --- |
| C3-1 | §8.3: transaction 1 committed an `authorised` command with neither an attempt nor a terminal result, and the port call ran outside any transaction, so a crash left no durable dispatch ownership and a retry could strand the command or issue a second call; §14's claimed `submit`-vs-`cancel` serialisation was not real, because both commands passed their short account-root transactions and then made opposing provider calls. This violated T-D13's single-call/idempotency invariant. | A durable per-command **dispatch claim** (`payment_execution_dispatches`, §12.1) is now written inside transaction 1 **before the account-root lock is released**, carrying the command's arbitration subject and the digest of a deterministically re-derivable provider idempotency key (§8.1, §8.3). A cross-operation unique index (`subject_claim`) allows only one live claim per intent/obligation, so a `submit` and a `cancel` for one intent can never both be in flight — the loser is refused durably with `dispatch_in_flight` and makes no call (§6.4, §8.3, §14). A crash before the call leaves a `claimed` claim (no call was ever issued); a crash after it leaves an `in_flight` claim under a lease, and the idempotent `PaymentExecutionService::redrive()` reconciles with the **same** idempotency key before it may re-issue, adopting the reconciliation outcome as the command's single attempt (§8.3, §13). Failure and concurrency suites now cover a crash before the call, a crash after the call and the submit-vs-cancel race (§17). |
| C3-2 | §12.4: the verifier required every `*_id` parent to be a Schema 028 table, but §12.3 deliberately points `student_id`, the commercial references and the R2 references at pre-existing tables, so the verifier would reject the intended schema; and `payment_provider_account_commands.result_id` (with the object pair) was declared with no parent, index, nullability semantics or ownership rule in §12.3, despite C2-2's completeness claim. | §12.3 now declares two disjoint parent sets — Phase-T parents and the frozen **external authoritative parents** (`students`, `commercial_offers`, `commercial_purchases`, `commercial_offer_obligations`, `commercial_payment_evidence`, `collection_intents`, `renewal_cycles`, `recurring_enrolments`) — and §12.4's verifier accepts either kind while still proving, physically, that the parent declares `id bigint unsigned NOT NULL AUTO_INCREMENT` with `PRIMARY KEY(id)`. `payment_provider_account_commands.result_id` and `payment_provider_object_commands.result_id` are now fully specified: `bigint unsigned NOT NULL`, parent = the append-only event row the command produced, indexed, written in the same transaction, and corruption if missing, foreign or cross-command (§12.1, §12.3, §12.4). |

## 0B. Correction round 4 (independent review of `111c762` / tree `3c020be9`)

The reviewed candidate returned FAIL on three blocking findings: a crash-recovery re-drive could not
reconstruct the request it must reconcile or re-issue, an expired lease had no atomic fenced takeover,
and the bounded worker principal was recorded but never adopted, leaving anonymous intake with no
authorised actor for the settlement and consequence calls. Each is resolved below. No clause outside
these three findings is changed by this round, and no authority, table, capability or policy is added
beyond what the findings require.

| # | Blocking finding (review of `111c762`) | Correction made in this round |
| --- | --- | --- |
| C4-1 | §5.3/§8.3/§12.2: `PaymentExecutionRequest` required raw, memory-only provider/account/object references, while the command, account, mapping and dispatch rows retained only keyed digests, so `redrive($commandId)` could neither reconcile nor safely re-issue a dispatch it owns. | The request now carries **no** raw provider reference at all (§5.3): its identifiers are the durable canonical/Phase-T ids already stored on the immutable command row, and the only provider-facing reference material is a **sealed provider dispatch descriptor** — an adapter-produced, adapter-opened authenticated ciphertext envelope written once with the claim inside transaction 1 and never readable by Core, an admin screen, a read model, an export or a log (§8.1, §8.3, §12.1). `redrive()` rebuilds the request deterministically from the command row plus that descriptor, and the descriptor carries a keyed digest bound to the command's own key digest, so the reconstruction is provable rather than assumed; a caller cannot inject a reference the mapping registry does not already own, because the raw claim is validated by keyed digest against `payment_provider_accounts`/`payment_provider_objects` before it is sealed. The sealed field set is a locked vocabulary, so the envelope cannot be used as a general secret store (§11.6). |
| C4-2 | §8.3/§14: an expired `in_flight` lease was merely "takeable", with no atomic takeover transition, so two concurrent re-drives could both observe expiry, both reconcile, both conclude the request was absent, and both issue the mutating call — defeating the one-command/one-dispatch invariant. | §8.3 now specifies the lease transition as a **conditional, compare-and-swap lease acquisition** that issues a new `claim_generation` and `claim_token_digest`, and the takeover is only ever proven by the affected-row count of that single statement. Reconciliation, re-issue and settlement are all **fenced** by the same generation and token: every durable write is a conditional update that must affect exactly one row, the owner's structured call timeout is structurally guaranteed to be shorter than the lease it holds, and an owner that loses its fence records no attempt and no result — it discards its outcome and reports the pending state, so the successor's reconciliation (with the same deterministically re-derivable idempotency key) adopts the provider state exactly once. §17 adds the required `concurrent_expired_lease` test. |
| C4-3 | §9.7: the worker principal was only an option value and was "never impersonated", but the R1/R2 consequence boundaries authorise through `current_user_can()` and record `get_current_user_id()`, so anonymous webhook/drain processing had no authorised actor and could not settle an obligation or confirm an R2 cycle. | §9.7 now specifies the **scoped, restored worker execution context**: a single non-re-entrant helper that resolves the validated principal, sets it as the WordPress current user for exactly the bounded translation and consequence work, verifies under that identity that the four capabilities are actually effective, and restores the previous user unconditionally in a `finally` — before any response, hook or admin surface can observe it. The execution surface keeps its own authenticated actor and is never satisfied by this context, so the two bounded identities never merge. Phase T still creates, escalates and widens nothing: it adopts an operator-provisioned non-human principal that must hold exactly the four capabilities and no administrative capability, and sets no other user id, ever. §17 proves that anonymous intake settles only through that bounded identity (the R1/R2 rows record the principal's user id, not an administrator's), that the previous identity is restored, and that the unset, invalid or over-privileged principal settles nothing. |

## 0C. Correction round 5 (independent review of `ffe06d1` / tree `60320eb0`)

The reviewed candidate returned FAIL on three blocking findings: the descriptor-refusal path the
contract itself requires was not representable, an expired-lease takeover could not reach a re-issue
through any defined transition, and the sealed descriptor carried no binding value even though the
required transplant-detection proof must live inside an authenticated payload with no AAD channel.
Each is resolved below. No clause outside these three findings is changed by this round, and no
authority, table, capability or policy is added beyond what the findings require.

| # | Blocking finding (review of `ffe06d1`) | Correction made in this round |
| --- | --- | --- |
| C5-1 | §8.2 rule 5 vs §8.3: §8.3 requires a `claimed` claim with an unopenable descriptor to receive a durable `refused` result, but rule 5 called **any** claim whose command already has a result row corruption, and a claim can never be deleted — so the required failure path was non-representable. | `DISPATCH_STATES` gains the terminal `released` member (§5.2), distinct from `settled`: `released` ends a claim that never issued a call. §8.3 now writes that refusal in **one fenced transaction** that conditionally moves the claim `claimed → released` (clearing `active_claim_slot`, releasing the subject's live slot) and — only when that statement affected exactly one row — appends the command's `refused`/`dispatch_descriptor_unavailable` result in the same transaction. §8.2 rule 5 now permits exactly that single claim/result pairing and still treats every other claim-carrying-a-result as corruption (§8.2, §8.3, §12.3, §12.4). |
| C5-2 | §8.3: an expired-lease takeover leaves the claim `in_flight` at `claim_generation > 1`, but the re-issue was routed through the only acquisition transition, which accepts `claimed` at generation `1` and can therefore never apply to a takeover claim; recovery had no defined fenced path back to a re-issue. | §8.3 now defines a **distinct conditional pre-call ownership check** for a takeover generation, *not* step 2's generation-1 acquisition: one conditional statement that must prove `dispatch_state = 'in_flight'`, the exact acquired `claim_generation` and `claim_token_digest`, and an **unexpired** lease, renewing the lease for the coming call. Exactly one affected row is required; only after it returns one row may the winner issue the single mutating call, and only through the same generation/token-fenced settlement of transaction 2 (§8.3, §13, §14, §17). |
| C5-3 | §5.2/§5.3/§8.3/§11.6: the re-drive must prove the sealed plaintext binds the command and idempotency-key digests, but the locked `DISPATCH_DESCRIPTOR_FIELDS` held no binding value and `sodium_crypto_secretbox` authenticates without an additional-authenticated-data channel, so the transplant-detection proof could not be implemented without breaking the locked field set. | The permitted sealed payload gains the explicit binding pair `command_key_digest` and `idempotency_key_digest` (§5.2) — both already-stored keyed digests, never raw references, so no exposure is added. `sealDispatchDescriptor()` writes them from the request, the request carries the command's `command_key_digest` as a durable row value (§5.3), and every `submit`/`cancel`/`reconcile` (and the re-drive's reconstruction proof of §8.3) must verify the sealed pair against the command row and the claim **before any call**. A mismatch is `dispatch_descriptor_unavailable` and the envelope is never re-derived, guessed or partly returned (§5.2, §5.3, §6.4, §8.3, §11.6, §17). |

## 0D. Correction round 6 (independent review of `da294851` / tree `2645e861`)

The reviewed candidate returned FAIL on one blocking finding: the initial dispatch acquired the
`in_flight` lease before the adapter opened and bound the descriptor, so a descriptor that could not be
opened — or whose binding did not name this command and this claim — was discovered only after
`in_flight`, the one state that must remain pending because a call may already have been issued. The
contract's sole `dispatch_descriptor_unavailable` refusal transaction is limited to `claimed →
released`, so on the normal submit/cancel path this condition was unrepresentable: it could only be
recorded as an attempted/settled outcome despite no provider call, or left permanently dispatching.
Both violate the invariant that the condition is a durable `refused` result on a terminal `released`
claim with no attempt. The finding is resolved below. No clause outside this finding is changed by this
round, and no authority, table, capability or policy is added beyond what the finding requires.

| # | Blocking finding (review of `da294851`) | Correction made in this round |
| --- | --- | --- |
| C6-1 | §5.3/§6.4/§8.3: step 2 acquired the lease (`claimed → in_flight`) and only then invoked the port, which is where the descriptor is opened and its binding proved — so an unavailable or binding-mismatched descriptor on the normal submit/cancel path was discovered after `in_flight`, while the sole `dispatch_descriptor_unavailable` refusal transaction is limited to `claimed → released`. The condition was therefore unrepresentable on that path: it could only be recorded as an attempted/settled outcome despite no provider call, or left permanently dispatching, instead of the required durable `refused` result with a terminal `released` claim and no attempt. | §5.3 adds an adapter-scoped, **non-mutating descriptor preflight** (`preflightDispatchDescriptor()`, verdict vocabulary `DESCRIPTOR_PREFLIGHT_STATES`), and §8.3 step 2 now fixes the order **preflight → lease → call**: the preflight — Core's reconstruction proof plus the adapter's open-and-binding verdict of §5.3 — runs while the claim is still `claimed`, **before any lease is acquired**; only an `ok` verdict may perform the existing conditional `claimed → in_flight` acquisition and invoke the provider. A `dispatch_descriptor_unavailable` verdict takes the **existing** fenced `claimed → released` refusal transaction of §8.3 — one conditional update under the claim row lock whose affected-row count must be exactly `1`, and, only then, the command's `refused`/`dispatch_descriptor_unavailable` result appended in the same transaction — so no attempt is recorded, no provider call is made, exactly one refused result exists and the subject's live slot is released. §5.4, §6.4, §11.6, §12.1, §13, §14 and §17 are updated to match, and §17 adds the required initial-dispatch descriptor-failure runtime and concurrency coverage. |

## 0E. Correction round 7 (independent review of `61c62be` / tree `314bf4bb`)

The reviewed candidate returned FAIL on two blocking findings: the initial-dispatch preflight could not
prove the descriptor was bound to the actual claim, and the second descriptor open reintroduced a
post-lease no-call failure path with no fenced representation. Each is resolved below. No clause outside
these two findings is changed by this round, and no authority, table, capability or policy is added
beyond what the findings require.

| # | Blocking finding (review of `61c62be`) | Correction made in this round |
| --- | --- | --- |
| C7-1 | §5.3/§8.3 step 2: `preflightDispatchDescriptor()` received only the request and the descriptor — never the claim or its stored idempotency-key digest — so although its verdict exposed the sealed digests for Core to compare, the initial-dispatch flow did not require that comparison before treating `ok` as permission to acquire the lease. An `ok` verdict therefore proved only that the envelope was internally self-consistent: it could not prove the descriptor was bound to *this* claim, so the C5-3 binding invariant was not enforced on the path that matters most. | The expected claim binding is now an explicit input and an explicit ordered obligation. §5.3's port method becomes `preflightDispatchDescriptor(PaymentExecutionRequest $request, ProviderDispatchDescriptor $descriptor, string $expectedClaimIdempotencyKeyDigest)`: an `ok` verdict requires the sealed `command_key_digest` to equal the request's durable `command_key_digest` **and** the sealed `idempotency_key_digest` to equal the expected claim digest that Core read from the live claim row. §8.3 step 2 additionally makes **Core's** comparison mandatory and ordered: under the claim row lock, immediately before the `claimed → in_flight` acquisition, the owner re-reads the command row's `command_key_digest` and the live claim's `idempotency_key_digest` and requires both to equal the verdict's two reported sealed digests (and the claim digest to equal the digest of the request's derived `idempotency_key`); any inequality takes the fenced `claimed → released` refusal with `dispatch_descriptor_unavailable` and makes no call. §17 adds an initial-dispatch runtime case for a claim-digest mismatch with an otherwise valid, openable descriptor. |
| C7-2 | §5.3/§8.3 step 2: after a successful preflight the claim moved `claimed → in_flight`, and `submit`/`cancel`/`reconcile` then had to open and validate the descriptor again; a failure on that required second open occurred **after** `in_flight` but before any provider call, while the only descriptor-refusal transition is `claimed → released`. The command could therefore again be recorded as an attempt despite no call, or stay `dispatching`, instead of ending as a terminal `released` claim with a `refused` result — the exact C6-1 invariant breach, merely relocated. | A successful preflight now mints an adapter-owned, opaque, **one-use dispatch capability** (`ProviderDispatchCapability`) that carries the already-opened, already-bound envelope state. `submit`/`cancel`/`reconcile` accept and consume that capability and **never** reopen the descriptor, so on the initial-dispatch path no open and no binding check ever happens after the claim leaves `claimed`. For the residual case — a capability the adapter cannot consume, which therefore makes no outbound request — §8.3 defines a **provably-no-call abort** `in_flight → released`: one conditional statement that demands the owner's own generation-1 acquisition (`claim_generation = 1`, matching `claim_token_digest`, unexpired lease), clears `lease_expires_at` and `active_claim_slot`, and, only when it affects exactly one row, appends the command's `refused`/`dispatch_descriptor_unavailable` result in the same transaction. It is provably call-free from durable rows alone: generation `1` is only ever created by the acquisition from `claimed`, and an unexpired lease proves no takeover occurred, so no owner other than this one can have issued a call. A takeover generation (`claim_generation > 1`) can never take it — there a descriptor failure keeps the existing `in_flight` behaviour (make no call, leave the command `dispatching`, record the operator exception). §17 adds the required runtime case that forces the post-preflight capability/open failure and proves no attempt, no provider call and a terminal `released`/`refused` claim. |

## 0F. Correction round 8 (independent review of `91bf288` / tree `dec38b70`)

The reviewed candidate returned FAIL on four blocking findings: a non-`POST` delivery never reached the
controller (so it could not be receipted) and every precheck-refused request was receipted against an
empty body instead of the bytes that arrived; webhook attribution resolved the obligation from metadata
alone and accepted *any* historical mapping of the provider object; a duplicate delivery that lost the
unique event index was translated instead of converging; and `drain()` validated only the event
reference, so a changed but validly signed payload could be translated as the recorded event. Each is
resolved below. No clause outside these four findings is changed by this round, and no authority, table,
capability or policy is added beyond what the findings require.

| # | Blocking finding (review of `91bf288`) | Correction made in this round |
| --- | --- | --- |
| C8-1 | §9.1/§9.2/§9.3: the route was registered for `POST` only, and WordPress matches a route's declared methods *before* it reaches a callback, so a `GET`/`PUT`/`PATCH`/`DELETE` delivery was answered by routing and never receipted — although §9.3 requires a durable receipt for every inbound request that reaches the controller. The controller also replaced the raw body with `''` for every precheck refusal (`method_not_allowed`, `https_required`, `unsupported_content_type`, `payload_too_large`, `empty_payload`, `unexpected_request_shape`), so an oversized, wrong-content-type or otherwise refused request recorded a zero-byte/different `request_digest` and a `body_bytes` of `0` — destroying the exact-raw-body audit invariant of §9.3. | Both webhook routes are now registered for **every** HTTP method through one locked method set, so the §9.2 method requirement is decided inside the controlled handler and a non-`POST` delivery is receipted as `method_not_allowed` with the provider-appropriate `405` (§9.1, §9.2). The controller now **always** passes the exact raw bytes that arrived to `receive()`; the §9.2 prechecks decide whether the request may be parsed and verified, never which bytes are recorded, so every refusal — including `payload_too_large` and `unsupported_content_type` — carries the true body digest and byte count (§9.2, §9.3). |
| C8-2 | §10 rule 1: `decide()` resolved the obligation directly from the event's own metadata reference, and `objectIsMapped()` accepted *any* stored mapping of the event's provider object — regardless of its canonical kind, its canonical id or its active state. A signed event for an object linked to one canonical row could therefore submit evidence for a *different* obligation named in metadata, and a superseded or detached mapping kept attributing evidence. | Attribution is now exact. §10 rule 1 requires the event's own provider object to carry **exactly one active mapping** (`active_slot = 1`, `state = linked`; a historical row is never authority) and requires the canonical obligation that mapping already owns — `canonical_id` for an `obligation` mapping, the mapped collection intent's own `obligation_id` for a `collection_intent` mapping — to be exactly the obligation the event resolved. An absent or historical mapping is refused `unmapped_provider_object`; more than one active candidate, a mapping that owns a different obligation, and a mapping that owns no obligation while the event names one are each refused `ambiguous_obligation_attribution`, with no evidence submitted. An event that names no obligation and an object whose mapping owns none remain compatible, and R1's own `unmatched_payment_evidence` routing still decides. |
| C8-3 | §9.5: two concurrent deliveries of one event identity could both observe no existing event; the loser of the insert then received the winner's event id after the unique-key collision and **unconditionally** called `decide()`, appending a second decision and running a second R1/R2 consequence. Identical deliveries therefore did not converge, and a materially different duplicate bypassed the recorded fact-digest conflict path. | Insert-or-resolve now reports whether *this* worker created the event. A worker that meets `UNIQUE provider_event (provider_key, event_reference_digest)` adopts the winner's recorded event with `created = false` and is routed through the **same** convergence path a read duplicate takes: identical recorded facts converge idempotently (the single exception is an event whose R2 consequence is still `pending`, which may append the next consequence decision), and materially different recorded facts preserve the original event and append the controlled `conflicting_provider_event` decision. No collision path can translate, decide or append an R1/R2 decision of its own (§9.5). |
| C8-4 | §9.7/§9.5: `drain()` reconstructed the event identity from the re-delivered body but compared only `event_reference_digest`. A body with the same event id but changed immutable facts — validly signed, because the provider controls its own payload — was therefore accepted as the recorded event and translated, bypassing the immutable-event-fact and conflicting-duplicate invariants of §9.5. | `drain()` now recomputes the **full** event fact digest of the re-delivered envelope and requires it to equal the recorded `event_fact_digest` before any decision is appended. A mismatch preserves the recorded event unchanged, appends the controlled `conflicting_provider_event` decision (with the R1 commercial exception) and submits **no** evidence: a drain may never translate facts the event never recorded (§9.5, §9.7). |

## 0G. Correction round 9 (independent review of `5b477b7` / tree `a45d56fb`)

The reviewed candidate returned FAIL on four blocking findings: a durably recorded event that owed its
first decision was never completed by a redelivery, a pending-decision retry could be executed twice by
two deliveries, the §9.2 transport requirement ignored the declared `HTTPS_PROXY_HEADERS` allowlist and
had no configured-proxy trust path, and the `OPTIONS` method the route declares was answered by
WordPress's own `OPTIONS` handler — which runs outside normal route dispatch — so no receipt was ever
written for it. Each is resolved below. No clause outside these four findings is changed by this round,
and no authority, capability, policy or provider call is added beyond what the findings require. The two
intake findings share one mechanism and therefore one new aggregate: the **durable per-event decision
claim**, which is exactly the dispatch claim of §8.3 applied to an event's owed decision.

| # | Blocking finding (review of `5b477b7`) | Correction made in this round |
| --- | --- | --- |
| C9-1 | §9.5: `convergeExisting()` returned `recorded` when the recorded event had no decision row at all, so an event that was inserted and then left owing its decision — a process crash after the event insert committed, before the first decision — was never completed by a redelivery. This violated the recovery invariant that a redelivery completes an event still owing a decision. | [C9-2]'s single serialised decision path now treats "no decision at all" as an owed decision: an identical redelivery of a recorded event with no decision row takes the event's decision claim, runs the first decision, and appends it exactly once. Converged delivery is therefore never equivalent to "recorded forever": an event owes a decision while its decision timeline (never a conflict record, see below) is empty or still `pending`. §17 proves the recovery, its serialisation and the takeover of an abandoned claim. |
| C9-2 | §9.5/§14: pending-decision retries were not concurrency-safe. Two deliveries could both read the same `pending` decision, both run `decide()` and the R1/R2 consequence, and both allocate the same next `decision_sequence`, so one of them failed on the unique index after already performing the work — violating the exactly-once bounded-consequence requirement. | A durable **per-event decision claim** (`payment_provider_event_decision_claims`, §12.1) with its own `UNIQUE event_claim (provider_event_id, active_claim_slot)` index is now taken **before any decision work runs**. The unique index — never a read — arbitrates two deliveries: the winner must own the claim to run the translation and the R2 consequence, the claim's `claim_generation`/`claim_token_digest` fence the `claimed → settled` transition inside the same transaction that inserts the decision (so the next `decision_sequence` is allocated under the claim row's lock), and the loser performs **no** work at all: it re-reads the decision the owner published, waits a bounded, structural window for it, and converges. An expired claim is taken over by exactly one conditional statement that issues a new generation and token. §9.5, §12.1, §12.3, §12.4, §13, §14 and §17 are updated, and the concurrency matrix adds `pending_decision_retry` and `undecided_event_recovery`. |
| C9-3 | §9.2: the HTTPS requirement consulted only `is_ssl()` (plus the local environment), ignoring the declared `PaymentExecutionRule::HTTPS_PROXY_HEADERS` and offering no configured-proxy trust path, so a TLS-terminated deployment relying on a trusted `X-Forwarded-Proto: https` had every webhook rejected as `https_required`. | §9.2 now states the complete transport rule: direct TLS, the local development environment, or a proxy header that **the operator has configured this site to trust** (`PaymentExecutionRule::TRUSTED_PROXY_OPTION`, §5.2) **and** that is a member of the locked `HTTPS_PROXY_HEADERS` allowlist. The option names headers, never authority: an unset, empty, malformed or non-allowlisted configuration trusts nothing, so a client can never satisfy the HTTPS requirement by sending its own header, and only a leftmost `https` in the header's scheme chain marks the delivery secure. The verdict is a pure function of its inputs and is proved by the webhook runtime suite. |
| C9-4 | §9.1/§9.3: registering `OPTIONS` in the route's declared method set does not deliver an `OPTIONS` request to the controller. WordPress answers `OPTIONS` in `rest_handle_options_request()`, itself a `rest_pre_dispatch` filter, so the delivery is answered *before* `dispatch()` resolves a route and reaches a callback, and **no receipt is written** — breaking the invariant that every registered method, `OPTIONS` included, is durably receipted. | `StripeWebhookController::register()` now also adds its own `rest_pre_dispatch` interception at priority `1`, ahead of the core handler's `10`. It answers **only** an `OPTIONS` delivery to **only** this endpoint's two registered route shapes (case-insensitively, with an optional trailing separator) and returns every other filter input untouched, so no other route's `OPTIONS` handling changes. A matched delivery is handed to the same controlled `process()` path a routed `GET`/`PUT`/`PATCH`/`DELETE` takes: the §9.2 method requirement decides it inside the controller, the durable receipt is written `refused`/`method_not_allowed` with the **exact raw body** digest and byte count (§9.3), the response is the controlled `405` and no event is ever created. §9.1, §9.2, §9.8 and §17 record the interception, and `tests/phase-2a2t-webhook-runtime.php` proves it end to end. |

## 0H. Correction round 10 (independent review of `5d8c379` / tree `47f9062e`)

The reviewed candidate returned FAIL on two blocking findings: the decision-claim aggregate was added to
an already-completed migration while the schema identity stayed `28`, so a database that had completed
`028` was left without the table and with no scheduled migration to create it; and the claim's lease was
the only thing serialising a decision, so an owner whose lease expired mid-decision could be displaced,
have its decision completed by a successor, and still resume and execute the same R1/R2 work. Each is
resolved below. No clause outside these two findings is changed by this round, and no authority,
capability, policy, provider call or table beyond the one aggregate the claim already required is added.

| # | Blocking finding (review of `5d8c379`) | Correction made in this round |
| --- | --- | --- |
| C10-1 | §12.4: `028_payment_execution_seam_provider_adapter` had been extended with the per-event decision-claim table while the schema identity stayed `28`. A database that had already completed `028` skipped the migration (the ledger never re-applies a completed migration), the strengthened `verify_payment_execution_schema()` then failed closed on the missing table at the pre-activation guard, and no migration existed to create it — violating additive, recoverable migration behaviour. | The decision-claim aggregate is now **migration `029_payment_event_decision_claim_authority`'s own storage** and the plugin declares Schema `29`. Migration `028` is restored to its fifteen-table set, its verifier neither requires nor validates the claim table (it tolerates it as a scheduled sibling instead of failing closed on a state the ledger can repair), and `verify_payment_event_decision_claim_schema()` runs after `029`, on current-schema verification and unconditionally before the schema option advances to `29` — the same three call sites as every other phase verifier. A completed-`028` database is therefore repaired by the scheduled migration: `029` creates exactly the claim table, additively, with no backfill, no claim inferred and no obligation settled. §12.1, §12.4, §13, §17 and §18 are updated, and the migration-runtime suite adds the completed-`028` repair rehearsal. |
| C10-2 | §9.5/§14: the claim's 120-second lease fenced the decision *row*, not the work the claim is meant to serialise. A worker whose lease expired while it was still executing `decide()` and its R1/R2 mutations could be superseded by exactly one takeover generation that completed the decision; the original worker then resumed and executed the same R1/R2 work, discovering that it had lost the claim only when its `claimed → settled` append affected zero rows. That violated the invariant that only the claim owner performs decision and consequence work. | Ownership now covers the whole decision operation. The claim's lease is the **bounded window the owner works inside**: the worker opens that window before any decision work runs, re-proves and renews it at every work-unit boundary — immediately before the R1 evidence submission and immediately before every R2 command — with one fenced conditional statement that requires its own live generation *and* an unexpired lease, and closes it when the decision is published. The statement can never resurrect an expired lease, so a generation whose window has closed (its lease lapsed, or exactly one successor generation took the claim over) stops **before** the next work unit, performs no further decision or consequence work, appends nothing and converges on the owner's decision. The append stays fenced exactly as before. §9.5, §12.1, §13, §14 and §17 are updated, and the concurrency matrix adds `stale_owner_after_lease_expiry`, which stalls the owning worker past its own lease, proves the successor generation takes the claim over and completes the decision, and proves the stale generation reached no R1/R2 work boundary at all. |

## 0I. Correction round 11 (independent review of `d60544a` / tree `0f000a8d`)

The reviewed candidate returned FAIL on one blocking finding: the decision-claim lease fenced a unit's
*entry*, not the duration of its work. `assertDecisionWorkWindow()` proved and renewed the claim's window
and committed that renewal **before** `CommercialPaymentService::ingest()` or an R2 command ran; those
units open their own transactions and invoke WordPress hooks, so either may block far longer than the
120-second lease. During that interval a successor can take the expired claim over atomically — and the
original worker still completes the R1/R2 mutation it had already started, inside a window that had
closed, discovering the loss only at its next gate. The finding is resolved below. No clause outside it is
changed by this round, and no authority, table, capability, policy or provider call is added.

| # | Blocking finding (review of `d60544a`) | Correction made in this round |
| --- | --- | --- |
| C11-1 | §9.5/§14: the fenced renewal committed before the R1/R2 unit began, so the lease bounded the decision row and the unit's *entry* only. A unit that outlived the 120-second lease — its own transaction, or a hook a listener ran inside it, blocked longer than the window — could be superseded by a take-over generation while the original worker nevertheless committed the already-started R1/R2 mutation. The implementation therefore only fenced entry, not the duration of work, and C10-2's requirement that the claim owner be the only worker performing decision/consequence work (and that an expired generation stop *before* work) was violated for the whole duration of a unit. | A decision work unit is now fenced at the **connection's statement boundary, from inside the unit's own transaction**. The intake registers one listener on `PaymentExecutionRule::DECISION_UNIT_FENCE_FILTER` (`query`) for exactly the unit's duration and removes it again in a `finally`; the listener never rewrites a statement, passes transaction control and session configuration through untouched (`START TRANSACTION`, `ROLLBACK`, `SET …`), and precedes **every other statement — the unit transaction's `COMMIT` included — with the fenced window proof**: one `SELECT … FOR UPDATE` on the claim row that must return exactly one row (this worker's own `claim_generation` and `claim_token_digest`, `claim_state = 'claimed'`, its live slot and an unexpired `lease_expires_at`) and that, when the statement runs outside any transaction, additionally renews the window first. The locking read holds the claim row for the rest of the unit's transaction, so a decision-claim take-over — which needs that same row *and* an expired lease — can never interleave with a unit, and a unit's commit can never land outside the window it was granted. A proof that finds no live window raises the controlled `DecisionClaimWindowClosed` **before** the statement executes, so the R1/R2 service that owns the transaction rolls the whole unit back: a closed window can no longer be discovered only after an already-started mutation committed. The stale generation then releases the live claim it appended nothing to (its own generation and token fence the release) and converges, so the event is completed by the next generation instead of waiting out a lease nobody is working inside; a take-over generation releases nothing (§9.5, §12.1, §13, §14, §17). |

## 0J. Correction round 12 (independent review of `09c4134` / tree `944b45c7`)

The reviewed candidate returned FAIL on one blocking finding: the fenced `claimed → settled` transition of
the decision append required only the owner's claim state, its `claim_generation` and its
`claim_token_digest`. The claim's lease therefore bounded the R1/R2 work units but **not** the append that
publishes their outcome: if the lease lapsed after the last work unit and before the append transaction, the
stale generation still settled its claim and appended its decision, publishing authority inside a window the
contract had already closed. The finding is resolved below. No clause outside it is changed by this round,
and no authority, table, capability, policy or provider call is added.

| # | Blocking finding (review of `09c4134`) | Correction made in this round |
| --- | --- | --- |
| C12-1 | §9.5 C10-2/§12.1/§14: `PaymentProviderRepository::settleDecisionClaim()` fenced only `claim_state`, `claim_generation` and `claim_token_digest`, so it required neither the claim's live slot nor an unexpired `lease_expires_at`. A generation whose lease lapsed after its final R1/R2 work unit — but before the decision-append transaction — therefore settled its live claim and appended its decision, even though C10-2 makes the lease the bounded window the *entire* decision operation runs inside and requires a lapsed generation to append nothing and converge. The intake then left the event owing its decision behind a lease nobody was working inside, because the zero-row convergence path did not release the live claim the stale owner had appended nothing to. | The append is now bounded by the same window that bounded the work. `settleDecisionClaim()` requires, in its one conditional statement, the owner's own `claim_state = 'claimed'`, its `claim_generation`, its `claim_token_digest`, its `active_claim_slot = 1` **and** a non-null, unexpired `lease_expires_at`, judged against the very `$now` that statement stamps the row with, so an append that runs after the claim's lease has lapsed affects zero rows and publishes nothing — exactly as a replaced generation publishes nothing. `PaymentEventIntakeService::appendDecisionUnderClaim()` treats that zero-row outcome as a closed window: it rolls back, **releases** the live claim it appended nothing to (fenced by its own generation and token, so a successor's claim is never touched) and converges, so an expired-but-not-yet-taken-over claim never strands the event and the next delivery completes it. The seam between the final work unit and the fence is observable through the new §8.4 hook `dzn_phase_2a2t_before_provider_event_decision_append` (ids only, outside the worker context and outside any transaction). §9.5, §12.1, §13, §14 and §17 are updated; the failure suite proves the transition refuses an append that runs past the lease; and the concurrency matrix adds `stale_owner_at_decision_append`, which completes every R1/R2 work unit and then lets the window the owner still exclusively holds lapse at the append seam, and proves that the append — and never a take-over — refuses the stale generation, that the owner's own generation-1 claim is released with no live slot and no generation above 1 exists, that the owner appended nothing, and that the next delivery is the one that completes the event's decision exactly once. |

## 0K. Correction round 13 (independent review of `e92a627` / tree `0f4ab9b6`)

The reviewed candidate returned FAIL on one blocking finding: the round-12 fenced `claimed → settled`
transition of the decision append required the owner's live slot and an unexpired `lease_expires_at`, but the
intake captured the instant that would be compared against the lease **before** it fired the observable
append seam `dzn_phase_2a2t_before_provider_event_decision_append` and before the transaction took the claim
row's lock. The finding is that a hook callback registered on that seam — the seam exists precisely so an
observer can hold the operation at its last bounded step — or any other delay between the seam and the
statement's row lock can outlive the 120-second lease, while the update still compares `lease_expires_at`
against the instant read before the delay. A generation whose window has already closed therefore still
settled its live claim and appended its decision, publishing authority inside a window the contract had
closed: the append must be fenced at the moment it runs, not at the moment its caller last looked at the
clock. The finding is resolved below. No clause outside it is changed by this round, and no authority, table,
capability, policy or provider call is added.

| # | Blocking finding (review of `e92a627`) | Correction made in this round |
| --- | --- | --- |
| C13-1 | §9.5 C10-2/§12.1/§14 (`PaymentEventIntakeService.php:642-649`): `appendDecisionUnderClaim()` captured `$now` **before** firing the append seam and passed that captured instant into `settleDecisionClaim()` as the value the lease predicate and the settlement stamps were judged against. A seam callback that delays the append past the claim's lease — a decision operation simply outliving its 120-second window at its last bounded step — therefore still found `lease_expires_at >= $now` true, settled the live claim and appended the decision after the window had closed, because the comparison used the instant read before the seam rather than the instant the statement ran. | The conditional transition now takes its verdict **at statement execution**, and takes it from one database-time expression: `settleDecisionClaim()` requires, in its single conditional statement, the owner's own `claim_state = 'claimed'`, its `claim_generation`, its `claim_token_digest`, its `active_claim_slot = 1` **and** a non-null `lease_expires_at >= UTC_TIMESTAMP()`, and stamps `settled_at`/`updated_at` with that same `UTC_TIMESTAMP()` — the settlement and the window verdict can no longer be derived from two different instants. The transition takes no instant parameter at all, so no caller can supply one; `PaymentEventIntakeService::appendDecisionUnderClaim()` now reads the instants it records on the appended row only *after* the append seam. A seam callback, or any delay before the statement acquires the claim row's lock, therefore changes the verdict itself: an append that runs after the lease has lapsed affects zero rows and publishes nothing — exactly as a replaced generation publishes nothing — and the owner releases the live claim it appended nothing to and converges, so the event is completed by the next delivery instead of being stranded behind a lease nobody was working inside. §9.5, §12.1, §13, §14 and §17 are updated; the contract suite pins the database-time fence and the missing instant parameter in source; the failure suite proves behaviourally that a *real* delay past the claim's live window settles nothing and leaves the claim live; and the concurrency matrix's `stale_owner_at_decision_append` now lets the owner's own window lapse **in real elapsed time**, with the claim row never written, and its verifier proves the lapse was a real one — the instant the owner read from its own live claim at the seam is its structural 120-second window forward of the instant the claim was taken, and the release that follows the refused append lands strictly after it. |

## 0L. Correction round 14 (independent review of `057acae` / tree `1c5545c5`)

The reviewed candidate returned FAIL on one blocking finding: the round-13 append fence took its verdict from
one database-time expression *inside* its own conditional statement (`lease_expires_at >= UTC_TIMESTAMP()`,
with `settled_at`/`updated_at` stamped from that same expression), but MySQL evaluates such a function **once,
when the statement starts** — not when the statement reaches the row it is judging. An append `UPDATE` that
had to wait for another transaction's lock of the claim row — a statement that starts while the lease is live,
blocks, and acquires the row only after the lease has expired — therefore still satisfied
`lease_expires_at >= UTC_TIMESTAMP()` with the instant it began waiting, settled the live claim and appended
the decision after the window had closed. That violates C10-2, which makes the lease the bounded window the
*whole* decision operation, the append included, runs inside and requires that a delay before the append
acquires its row lock change the verdict. The finding is resolved below. No clause outside it is changed by
this round, and no authority, table, capability, policy or provider call is added.

| # | Blocking finding (review of `057acae`) | Correction made in this round |
| --- | --- | --- |
| C14-1 | §9.5 C10-2/§12.1/§14 (`PaymentProviderRepository.php:178`): the round-13 `claimed → settled` transition judged the lease with `UTC_TIMESTAMP()` in the same statement that settled the claim, so the verdict was taken at that statement's *start*. A row lock the statement had to wait for was therefore invisible to the verdict: an append that began while the lease was live, blocked on another transaction's lock of the claim row until after expiry, and acquired the row only afterwards still settled the live claim and appended its decision, because the comparison used the instant read before the wait. | The transition now takes the claim row's lock **first** and retains it for the rest of the append transaction. `settleDecisionClaim()` issues a fenced `SELECT … FOR UPDATE` that carries the owner's own `claim_state = 'claimed'`, its `claim_generation`, its `claim_token_digest`, its `active_claim_slot = 1` and the same non-null `lease_expires_at >= UTC_TIMESTAMP()` predicate, and only then runs the conditional `claimed → settled` update — whose lease predicate and settlement stamps may still share one `UTC_TIMESTAMP()` expression, because the update can no longer wait for that row. The wait for the row therefore lands on the locking read, where it decides nothing, and the update takes its database-time verdict *after* the wait rather than before it. The locking read can only ever refuse a claim the update would also refuse (a row it cannot see is not this generation's live claim); the update's affected-row count remains the only proof of ownership, so a window that lapsed while the append was queued settles nothing, stamps nothing and publishes nothing. §9.5, §12.1, §13, §14 and §17 are updated; the contract suite pins the lock-then-update order and the locking read's fence in source; and the concurrency matrix adds `append_blocked_on_claim_row`, which holds the claim row in a **separate transaction** until the owner's live window has lapsed, queues the owner's append behind that lock (attributed to that exact row with `performance_schema.data_lock_waits`/`data_locks` where the runtime exposes them, and otherwise through InnoDB's `LOCK WAIT` state), proves the queue was observed while the window was still open and that the lock outlived it, and proves the queued append settled no claim and appended no decision before the lock was released — the state an update that judged the window with the instant it began waiting would have turned into a settlement and a published decision. |

## 1. Verified authoritative state

| Fact | Verified value (this checkout) |
| --- | --- |
| Repository root | `git rev-parse --show-toplevel` = this workspace |
| Branch / remote | `main...origin/main`; clean tree |
| HEAD | `559b1736621c9ed32e41dd2b785dd0f040dcb647` |
| Platform version | 0.1.0 |
| Schema | `DZN_PLATFORM_SCHEMA_VERSION` = `26`; migration ledger 001–026, latest `026_renewal_recurring_enrolment_authority` |
| Build | `phase2a2r2-renewal-next-term-collection-recovery-20260923.1` |
| R1 | merged and closed on `main` at `f9df3bfb0fda79fba7dee916c4687464ee67d480` (Schema 25) |
| R2 | candidate on this checkout, unmerged (Schema 26) |
| Capability markers | `dzn_platform_capability_version` = `2a2n`, plus per-phase markers through `dzn_platform_capability_version_2a2r2` = `2a2r2` |
| Outbound capability today | **none** — no `wp_remote_*`, `curl_*`, `wp_schedule_event`, `wp_schedule_single_event` or `cron` reference exists anywhere under `src/` |
| Secret-at-rest capability today | **none** — no `sodium_*`, `openssl_encrypt` or equivalent helper exists anywhere under `src/` |
| Provider surfaces today | no Stripe SDK, class, constant, column or webhook endpoint; the only REST route is `delnavazan-platform/v1/booking-requests` (anonymous POST, `idempotency-key` header, best-effort rate limit) |
| Existing provider-neutral evidence seam | `CommercialPaymentService::ingest()` under capability `dzn_ingest_commercial_payment_evidence`; storage `commercial_payment_evidence` (`UNIQUE provider_reference (provider_key, evidence_reference_digest)`), `commercial_payment_facts`, `commercial_obligation_settlements` |
| Existing R2 collection seam | `CollectionIntentService` states `pending`, `submitted`, `confirmed`, `failed`, `recovered`, `cancelled`; kinds `manual_payment_required`, `automatic_charge`; `submit` performs no external call; a caller-supplied `charge_at` is refused and the instant is policy-derived |
| Outbox seam | `platform_outbox` (`UNIQUE idempotency_key`, `status`, `available_at`, `leased_at`, `attempt_count`), published by `RecurringOutboxRepository` |
| Phase T is already named by the authorities | R2 contract §4/§10 and the continuity record name the Stripe/charge adapter as Phase T; no Phase T code, contract, table or capability exists |
| Documentation state | README and the seven canonical docs still describe pre-R1 state (Schema 24/25 / "R1 unmerged"); stale documentation only |
| Surface size | 184 files under `tests/`, 121 classes under `src/Core/Application`, 37 docs |

Two consequences are structural, not incidental:

1. Phase T introduces the **first outbound-capable boundary** on the Platform. R1-D12 and R2's
   `integrity` rules keep every commercial table provider-neutral today; Phase T is the only place
   where a provider key, a provider reference and a provider secret may exist, and they must exist
   only as a controlled vocabulary member, a keyed digest and an authenticated ciphertext (§11).
2. Phase T introduces the **first externally supplied, unauthenticated request path that can reach
   commercial authority** (the webhook). It therefore must not be able to change commercial truth
   directly: it may only produce provider-neutral evidence that the existing R1 acceptance boundary
   re-validates (§10, T-D6).

## 2. What this phase owns

In scope:

1. **Provider-neutral execution seam** — port interfaces, request/outcome value objects and a
   provider registry, defined in Core and implemented by adapters (§5).
2. **Provider account and object mapping registry** — provider account identity (mode/state) and
   canonical ↔ provider object linkage, as data, never as authority (§7).
3. **Execution command authority** — durable, digest-only execution commands bound to one exact R1
   obligation and (for recurring collection) one exact R2 collection intent (§6, §8). [C4-1] The
   command itself stays digest-only: the only provider reference material Phase T ever stores is the
   sealed, adapter-opened dispatch descriptor committed with its dispatch claim (§5.3, §8.3, §11.6).
4. **Stripe adapter** — protocol translation, normalisation, redacted error handling and an outbound
   path that is provably incapable of a live call in this build (§5.4, §9.6, T-D13).
5. **Webhook intake** — signature verification over the exact raw body, durable receipt before
   acknowledgement, event idempotency, out-of-order safety and normalised translation (§9, §10).
6. **Secret isolation** — authenticated encryption at rest, adapter-scoped decryption, rotation,
   redaction and fail-closed behaviour (§11).
7. **Schema 028/029** — additive storage for the above, with its fail-closed verifiers (§12):
   [C10-1] the fifteen-table seam of `028` and the decision-claim aggregate of
   `029_payment_event_decision_claim_authority`.

Out of scope (unchanged by this phase):

- any live provider call, API key, webhook secret value, product/price creation, customer creation,
  charge, refund, payout or provider dashboard change;
- any decision about provider recurring semantics (Stripe Billing vs. per-Term payment links), the
  automatic-charge lead time, the recovery policy, or refund academic consequences;
- notification delivery, templates, attempts or transport (Phase S);
- Teacher payout, accounting, tax, invoices (Phase U);
- Portals, public routes, Theme, deployment, production cutover, Amelia writes/removal.

## 3. Preserved invariants (must not regress)

- Money is an integer number of minor units with an explicit ISO-4217 currency; no floats, no
  conversion, no per-charge discount.
- The Platform-issued offer remains the only whole-Term price snapshot; no browser, redirect, provider
  payload or adapter input can establish a payable amount (R1-D4).
- Settlement is distinct from academic effectiveness; tranche prerequisites are unchanged (R1-D6).
- Funded allowance is derived from accepted obligations; no mutable funded-session counter (R1-D7).
- No unfunded occurrence ever becomes a Lesson (R1-D8); Phase T can never create a Term, Enrolment,
  Lesson, schedule, attendance outcome or academy obligation.
- Capacity succession is unchanged (R1-D10): the Phase-Q hold → R1 claim → Phase-N schedule chain is
  never touched by an execution or a webhook.
- Unset policy stays unset (R1-D11 / R2 §4): no advance charge instant, no lapse, no default
  automatic-renewal opt-in exists because Phase T exists.
- Provider neutrality of commercial storage (R1-D12) is preserved: Phase T adds no column to any
  `commercial_*` or R2 table and adds no provider-specific column to commercial storage.
- R1 evidence convergence is preserved exactly: identical immutable facts converge idempotently, a
  material difference is preserved and routed, and Phase T never manufactures settlement truth.
- [C9-1]/[C9-2] `payment_provider_events` stays write-once: an event's decision claim is its own
  mutable intake aggregate (§12.1), so the recorded event identity is never updated, and a decision is
  appended exactly once by exactly one claimed worker — including the first decision of an event that a
  crash left owing one.

## 4. Locked Phase T decisions (T-D1 … T-D14)

| Decision | Locked meaning |
| --- | --- |
| T-D1 provider neutrality of the seam | Core defines the port, the request/outcome vocabulary and the registry; adapters implement the port. Core never names a provider SDK class, endpoint, header or payload field. |
| T-D2 authority is upstream | An execution command exists only for an unsettled R1 obligation of an accepted purchase, and (for recurring collection) only for a live R2 collection intent of that exact obligation. Phase T invents no obligation, no amount and no due date. |
| T-D3 no client-supplied economics | Amount, currency and beneficiary are copied from the authoritative R1 obligation/purchase rows inside the guarded transaction. Any adapter- or caller-supplied amount is compared, never adopted. |
| T-D4 verify-then-record | A provider event is verified against the provider-supported signature over the exact raw request before anything is parsed into authority, and the verification outcome is durably receipted. An unverified request is never translated. |
| T-D5 one durable event identity | `(provider_key, event_reference_digest)` is the event identity; the reference is persisted only as a keyed digest. Identical facts converge; a materially different fact set for the same identity is preserved and routed as a conflict. |
| T-D6 intake routes through R1 | Translation submits provider-neutral evidence to the existing `CommercialPaymentService::ingest()` boundary, which alone decides acceptance, settlement, purchase and entitlement. The adapter writes no `commercial_*` row. |
| T-D7 secret isolation | Signing secrets and API keys exist only as authenticated ciphertext under a domain-separated key; only the adapter may decrypt; nothing else may read, log, export or expose them. |
| T-D8 mapping is not authority | A provider account or object mapping links identifiers. It never authorises, settles, protects capacity or substitutes for a canonical reference. |
| T-D9 unresolved policy stays unresolved | No automatic-charge lead time, recovery threshold, refund consequence, or provider recurring model is chosen. Where policy is unset, Phase T records the fact and refuses the dependent execution. |
| T-D10 no academic or delivery authority | A webhook or an execution outcome can never create or change a Term, Enrolment, Lesson, schedule, attendance outcome, academy obligation or funded allowance. |
| T-D11 refunds are recorded, not executed | `refund_recorded` becomes R1 `refund` evidence and routes to the R2 refund/reversal review case. Phase T issues no refund, and `academic_consequence` stays NULL. |
| T-D12 fail closed and visible | Unknown provider, unknown object, unmapped account, unsupported event type, unset secret, stale signature and conflicting duplicate are recorded as controlled refusals/conflicts with a reason code — never a silent success and never a silent repair. |
| T-D13 no outbound call inside a transaction, and no unowned dispatch | The guarded validation transaction commits before the port is invoked; the outcome is recorded in a second transaction. [C3-1] That first commit also leaves a durable per-command **dispatch claim** (§8.3), so no command is ever unowned between the two transactions: `claimed` proves no call was issued, `in_flight` means one may have been, and a re-drive reconciles with the same deterministically re-derivable idempotency key before it may re-issue. [C6-1] A claim may move `claimed → in_flight` only after the adapter's non-mutating descriptor preflight returns `ok`, so a descriptor failure that precedes any provider call is a durable `refused` result on a terminal `released` claim — never an attempted/settled outcome with no call and never a permanently dispatching command. [C7-1] That `ok` verdict is only permission when it proves the descriptor binds *this claim*: the preflight receives the claim's stored idempotency-key digest and Core re-proves both sealed digests against the durable command and claim rows under the claim lock, immediately before the acquisition, with any mismatch taking the same fenced `refused`/`released` path. [C7-2] The open happens exactly once per provider invocation, in that pre-call preflight, and its result is handed to the call as a one-use capability, so `submit`/`cancel`/`reconcile` never reopen the envelope after the lease; if such a capability cannot be consumed the seam makes no call and ends a provably call-free generation-1 claim through the fenced no-call abort of §8.3 (a takeover generation is never aborted). An adapter is called **at most once per command** with a stable idempotency key, and a second live claim for one arbitration subject is refused, never dispatched. |
| T-D14 live execution is not reachable in this build | The outbound path refuses unless the provider account records `execution_state = enabled`, configured credentials and a non-live mode, and no path in this build can provision a Stripe credential or enable a live account. [C2-5] The vault write surface is one of those paths and refuses by construction: `PROVISIONABLE_PROVIDERS` is empty, so no caller can store a Stripe secret (§11.3, §11.5). |

## 5. Module layout and the provider-neutral seam

### 5.1 Layout

```text
src/Core/Application/PaymentExecution/          provider-neutral seam (Core owns the vocabulary)
  PaymentExecutionPort.php                      interface implemented by every adapter
  PaymentExecutionRequest.php                   immutable provider-neutral request
  PaymentExecutionOutcome.php                   immutable provider-neutral outcome
  ProviderDispatchDescriptor.php                [C4-1] sealed dispatch descriptor (adapter-sealed, adapter-opened)
  DispatchDescriptorPreflight.php               [C6-1] non-mutating descriptor open/binding preflight verdict
  ProviderDispatchCapability.php                [C7-2] opaque, adapter-owned, one-use opened/bound dispatch handle
  ProviderReferenceClaims.php                   [C4-1] memory-only raw reference claims, sealed then discarded
  ProviderEventEnvelope.php                     immutable normalised provider event
  ProviderEventTranslator.php                   interface implemented by every adapter
  PaymentProviderRegistry.php                   controlled provider_key → adapter resolution
  PaymentExecutionService.php                   command, durable dispatch-claim and re-drive authority
  PaymentEventIntakeService.php                 receipt, verification, idempotency, translation
  PaymentProviderAccountService.php             account/mode/state registry
  PaymentProviderObjectService.php              canonical ↔ provider object mapping registry
  PaymentExecutionWorkerContext.php             [C4-3] scoped, restored worker principal execution context
  PaymentExecutionRule.php                      locked vocabulary and structural constants
  PaymentExecutionSupport.php                   shared fail-closed input/actor/lock helpers
  PaymentExecutionIdempotency.php               keyed digests (keys, payloads, references, facts)
  PaymentExecutionIntegrity.php                 fail-closed aggregate integrity checks
  PaymentSecretVault.php                        authenticated encryption and adapter-scoped reveal
src/Core/Infrastructure/Repository/             repositories for the Schema 028 tables
src/Integrations/Payment/Stripe/
  StripePaymentAdapter.php                      implements PaymentExecutionPort
  StripeEventTranslator.php                     implements ProviderEventTranslator
  StripeSignatureVerifier.php                   exact-raw-body signature verification
  StripeWebhookController.php                   the only public entry point (registered on rest_api_init;
                                                [C9-4] also intercepts its own OPTIONS delivery on rest_pre_dispatch)
src/Admin/Controller/                           admin read/diagnostic surfaces (no secret values)
```

The seam lives under `src/Core/Application/` because MODULE-BOUNDARIES gives Core provider-neutral
interfaces implemented by other modules, and the adapter lives under a new `src/Integrations/`
namespace because MODULE-BOUNDARIES gives Integrations provider clients, protocol translation,
webhook authentication/normalisation and provider-specific references.

### 5.2 Locked vocabulary

```php
final class PaymentExecutionRule {
    public const RULE_VERSION='payment_execution_v1';
    public const DOMAIN='payment_execution_v1';

    /** Controlled provider vocabulary. A provider is a key, never a class name in Core. */
    public const PROVIDERS=array('stripe');
    /** Controlled account modes. `live` exists as recorded data only; it is never reachable here. */
    public const MODES=array('test','live');
    public const ACCOUNT_STATES=array('active','suspended','closed');
    public const EXECUTION_STATES=array('disabled','enabled');
    public const CREDENTIAL_STATES=array('unconfigured','configured','invalid');

    public const OBJECT_KINDS=array('customer','payment_method','intent','charge','subscription','mandate');
    public const CANONICAL_KINDS=array('student','purchase','obligation','collection_intent','recurring_enrolment');
    public const OBJECT_STATES=array('linked','superseded','detached');

    /** Execution commands this phase can record. */
    public const OPERATIONS=array('submit_collection','cancel_collection','reconcile_collection');
    public const COMMAND_STATES=array('authorised','dispatching','completed','refused','conflicted');
    /** [C3-1] Durable per-command dispatch-claim lifecycle (the phase's own mutable execution row).
     * [C5-1] Two terminal members, and they mean different things: `settled` ends a claim whose single
     * call outcome (or reconciled outcome) was adopted, while `released` ends a claim that was ended
     * *without* a settlement because no call could ever be issued for it — the descriptor-refusal path
     * of §8.3. Both clear `active_claim_slot` and release the subject's live slot. Structural, never a
     * setting. [C7-2] `released` is reached from `claimed` (the pre-lease refusal, before any lease
     * exists) or by the provably call-free no-call abort of a generation-1 `in_flight` claim whose owner
     * acquired it directly from `claimed`; a takeover generation is never released. */
    public const DISPATCH_STATES=array('claimed','in_flight','settled','released');
    /** [C2] Terminal results a `payment_execution_results` row may record; `authorised` is never stored. */
    public const RESULT_STATES=array('completed','refused','conflicted');
    public const COMMAND_CONFLICT_REASON='duplicate_command_key_materially_different_payload';
    /** Provider-neutral attempt outcomes: never a provider's own status string. */
    public const OUTCOME_STATES=array('accepted_by_provider','declined','requires_action','unavailable','invalid_request','not_attempted');
    public const ATTEMPT_REASONS=array(
        'provider_accepted','provider_declined','provider_requires_action','provider_unavailable',
        'payment_execution_not_authorised','provider_account_inactive','provider_execution_disabled',
        'provider_credentials_unconfigured','live_execution_not_authorised','collection_intent_not_submitted',
        'obligation_already_settled','collection_kind_conflict','charge_time_not_due',
        'provider_request_rejected','provider_response_unusable',
        'dispatch_in_flight','provider_reconciled',
        /** [C4-1] Sealed-dispatch-descriptor failures: never a silent re-issue, never a guessed call.
         * [C7-2] `dispatch_descriptor_unavailable` carried by `outcome_state = not_attempted` is the
         * port's structured pre-call signal that a dispatch capability could not be consumed and that
         * **no** outbound request was made; reporting it after issuing a request violates the port
         * contract and is corruption, not a refusal. */
        'dispatch_descriptor_unavailable','dispatch_descriptor_incomplete',
    );

    /** Normalised provider event vocabulary: the only event types that may reach authority. */
    public const EVENT_TYPES=array(
        'payment_succeeded','payment_failed','payment_requires_action','refund_recorded',
        'mandate_recorded','provider_recurring_semantics_unresolved','unrecognised_provider_event',
    );
    public const DECISION_STATES=array('translated','ignored','refused','conflicted');
    public const DECISION_REASONS=array(
        'evidence_submitted','obligation_not_yet_accepted','stale_provider_event','duplicate_provider_event',
        'unmapped_provider_account','unmapped_provider_object','ambiguous_obligation_attribution',
        'provider_recurring_semantics_unresolved','unrecognised_provider_event',
        'conflicting_provider_event','payment_worker_principal_required','provider_event_not_authoritative',
    );
    public const SECRET_CLASSES=array('webhook_signing_secret','api_key');
    public const CIPHER='sodium_secretbox_v1';
    /** [C2-5] The only providers whose secrets this build may store. Empty: no credential is writable. */
    public const PROVISIONABLE_PROVIDERS=array();
    /** [C2-5] Secret-vault audit vocabulary: one audit row per write, rotation, retire or reveal failure. */
    public const SECRET_AUDIT_TYPES=array('stored','rotated','retired','revoked','write_refused','decrypt_failed');
    public const SECRET_REASONS=array(
        'provider_secret_write_not_authorised','payment_secret_unavailable','unknown_cipher_version',
        'unknown_key_version','secret_scope_mismatch','test_vault_override_active',
    );
    /** [C2-4] Webhook verification outcome and the controlled receipt reason codes of §9.2/§9.4. */
    public const VERIFICATION_STATES=array('verified','refused');
    public const WEBHOOK_REASONS=array(
        'signature_verified','signature_invalid','signature_outside_tolerance','webhook_secret_unconfigured',
        'unsupported_signature_scheme','webhook_account_unresolved','provider_account_inactive',
        'unsupported_payment_provider','method_not_allowed','https_required','unsupported_content_type',
        'payload_too_large','empty_payload','unexpected_request_shape',
    );
    /** [C2-3] The bounded R2 consequence recorded on a decision row (§10.1). */
    public const R2_CONSEQUENCE_STATES=array('not_applicable','pending','applied','refused');
    public const R2_CONSEQUENCE_REASONS=array(
        'renewal_cycle_not_collectable','collection_intent_not_submitted','obligation_not_settled',
        'accepted_payment_evidence_required',
    );
    /** [C9-1]/[C9-2] Durable per-event decision-claim lifecycle: the phase's own mutable intake row, the
     * one owner of one event's owed decision. [C10-2] `claimed` is live under a bounded lease window the
     * owner must re-prove and renew before every R1/R2 work unit it runs; `settled` ends a claim whose
     * owner appended the decision; [C11-1] `released` ends a claim whose owner appended nothing — the
     * descriptor-refusal case of §8.3 and the closed-window case of §9.5 alike. */
    public const DECISION_CLAIM_STATES=array('claimed','settled','released');

    /** Structural constants: never configurable. */
    public const SIGNATURE_TOLERANCE_SECONDS=300;
    public const MAX_WEBHOOK_BYTES=262144;
    public const TIMESTAMP_TOLERANCE_CLAMP_SECONDS=600;
    /** [C9-1]/[C9-2] Decision-claim lease: the **bounded window one owner works inside**, expressed as how
     * long it runs from its last renewal before a later delivery may take the claim over and complete the
     * event's decision. [C10-2] It bounds the work, not merely the row: the owner re-proves and renews the
     * window immediately before every R1/R2 work unit, a renewal can never resurrect an expired window, and
     * a generation whose window has closed performs no further decision or consequence work and appends
     * nothing. [C11-1] It is proved and renewed from *inside* the unit's own transaction as well, before
     * every statement that transaction runs, so the claim row is held for the whole transaction a unit
     * runs in: a unit can neither be displaced mid-transaction nor commit a statement outside the window
     * it was granted. Structural, never a setting. */
    public const DECISION_CLAIM_LEASE_SECONDS=120;
    /** [C9-1]/[C9-2] How long a delivery that cannot own the claim waits for the owner's decision before
     * it reports the event as durably still owing one. Structural, never a setting. */
    public const DECISION_CLAIM_WAIT_MILLISECONDS=1000;
    /** [C11-1] The connection statement boundary every R1/R2 work unit is fenced at. WordPress filters
     * every statement — `$wpdb->query()`, and therefore every insert, update, delete and read that goes
     * through it — through this filter before it runs, so one listener registered for exactly one unit's
     * duration sees the exact statements of that unit's own transaction. Structural, never a setting. */
    public const DECISION_UNIT_FENCE_FILTER='query';
    /** [C11-1] The statements the fence never inspects and never blocks: the transaction opener, an unwind
     * and session/transaction configuration. They are not unit work, and a rollback must never be blocked
     * by a fence; every other statement — the unit transaction's own `COMMIT` included — is preceded by the
     * fenced proof of the window it runs inside. Structural, never a setting. */
    public const DECISION_UNIT_UNFENCED_STATEMENTS='^(START\s+TRANSACTION|ROLLBACK|SET\s)';
    /** [C3-1] Dispatch-claim lease: how long one owner may hold an `in_flight` claim before a re-drive
     * may take it over and reconcile instead of re-issuing. Structural, never a setting. */
    public const DISPATCH_LEASE_SECONDS=120;
    /** [C4-2] The structured outbound call timeout every adapter must use, and the margin that must
     * remain between that call and the lease it runs under. Together they make lease expiry provably
     * unreachable while an owner is still inside a call, so a takeover can only ever displace an owner
     * whose call already returned or whose process already died. Structural, never a setting. */
    public const DISPATCH_CALL_TIMEOUT_SECONDS=30;
    public const DISPATCH_LEASE_MARGIN_SECONDS=60;
    /** [C4-1] Sealed provider dispatch descriptor: the domain-separated key-derivation domain and the
     * locked field set an adapter may seal. Nothing outside this set can be sealed, so the envelope is
     * structurally incapable of becoming a general-purpose secret or credential store (§11.6).
     * [C5-3] The last two members are the binding pair. `sodium_crypto_secretbox` authenticates the
     * ciphertext but offers no additional-authenticated-data channel, so the proof that this envelope
     * belongs to exactly one command has to ride *inside* the sealed plaintext: the envelope names the
     * command's own `command_key_digest` and the claim's `idempotency_key_digest`, and an adapter must
     * match both against the durable rows before it makes any provider call (§5.3, §8.3, §11.6) — [C7-1]
     * the claim half is passed in as the expected value and re-proven by Core under the claim lock before
     * the lease, and [C7-2] the match happens in the single pre-call preflight, whose `ok` verdict hands
     * the call a one-use capability instead of an envelope to reopen. Both
     * are keyed digests already stored on the command and claim rows — neither is a raw reference, a
     * secret or an identifier — so the locked field set still adds no exposure. */
    public const DISPATCH_DESCRIPTOR_DOMAIN='payment_dispatch_descriptor_v1';
    public const DISPATCH_DESCRIPTOR_FIELDS=array(
        'provider_account_reference','provider_object_references','operation','provider_key','mode',
        'student_id','purchase_id','obligation_id','collection_intent_id','renewal_cycle_id',
        'amount_minor','currency','idempotency_key','sealed_at',
        'command_key_digest','idempotency_key_digest',
    );
    /** [C6-1] The only two outcomes of the non-mutating, pre-call descriptor preflight of §5.3/§8.3.
     * `ok` means the envelope opened and its sealed binding pair matches this command and this claim, so
     * the claim may move `claimed → in_flight`; `dispatch_descriptor_unavailable` means it may not, and
     * the seam takes the fenced `claimed → released` refusal path instead of acquiring the lease. No
     * third state exists: the preflight never returns a raw reference, a partial open or a guess.
     * [C7-1] The claim half of that equality is an explicit input: the preflight receives the live
     * claim's stored `idempotency_key_digest` and may return `ok` only when the sealed
     * `idempotency_key_digest` equals it (and the sealed `command_key_digest` equals the request's own),
     * and Core re-proves both equalities against the durable rows under the claim lock before the lease.
     * [C7-2] An `ok` verdict also mints the one-use `ProviderDispatchCapability` that the next provider
     * invocation consumes, so the open never has to be repeated after the lease is acquired. */
    public const DESCRIPTOR_PREFLIGHT_STATES=array('ok','dispatch_descriptor_unavailable');
    /** Reserved for an explicitly authorised later slice; empty in this phase. */
    public const LIVE_EXECUTION_PROVIDERS=array();

    /** [C9-3] The proxy-header set that may be trusted only when the site is configured behind a proxy.
     * This is the whole of the allowlist: a header name outside it can never mark a delivery secure,
     * whatever an operator or a request says. */
    public const HTTPS_PROXY_HEADERS=array('HTTP_X_FORWARDED_PROTO');
    /** [C9-3] The operator-provisioned option declaring which proxy header this site trusts. It holds
     * header names, never authority: only a member of `HTTPS_PROXY_HEADERS` is ever honoured, and an
     * unset, empty, malformed or non-member configuration trusts no header at all. */
    public const TRUSTED_PROXY_OPTION='dzn_platform_payment_trusted_proxy';
    /** [C9-3] The only proxy-header value that marks a delivery as client-facing TLS. */
    public const TRUSTED_PROXY_HTTPS_VALUE='https';
    /** [C9-3] The proxy headers this site is configured to trust — always a subset of the locked
     * allowlist and never a header named by a request. The operator option is the configuration signal of
     * §9.2, and every name it may contribute is intersected with the allowlist. */
    public static function trustedProxyHeaders(): array;
    /** [C9-3] One *trusted* proxy header's verdict on the client-facing scheme: the header name must be
     * trusted by configuration and a member of the locked allowlist, the value is read as the
     * provider-facing convention does (a comma-separated chain whose leftmost element is the original
     * client scheme), and only a leftmost `https` marks the delivery secure. */
    public static function proxyHeaderIndicatesHttps( string $headerName, ?string $value ): bool;
}
```

Any vocabulary member added later is a code change with its own review, never a configuration value
and never a `dzn_commercial_policies` row. [C9-3] The same holds for the trusted-proxy allowlist: the
operator option may only *name* a member of the locked `HTTPS_PROXY_HEADERS` set, so widening it is a
reviewed code change and the option itself can never mark an arbitrary header trusted.

### 5.3 Port interfaces

```php
interface PaymentExecutionPort {
    public function key(): string;                                     // must be a PROVIDERS member
    public function supports(PaymentExecutionRequest $request): bool;   // operation + mode support
    /**
     * [C4-1] Seals the descriptor whose plaintext carries the provider-facing references this request
     * needs. Pure computation: no outbound call, no storage, and never a transaction of its own. Only
     * the adapter that sealed an envelope may open it, and DISPATCH_DESCRIPTOR_FIELDS is the whole of
     * what may be sealed. [C5-3] It also writes the binding pair of §5.2 — the request's
     * `command_key_digest` and the digest of its deterministically re-derivable `idempotency_key` — so
     * the envelope is provably bound to exactly one command and one claim.
     */
    public function sealDispatchDescriptor(PaymentExecutionRequest $request, ProviderReferenceClaims $claims): ProviderDispatchDescriptor;
    /**
     * [C6-1] Adapter-scoped, **non-mutating** descriptor preflight, and the only place in Phase T that
     * opens an envelope (§8.3 step 2). It opens the envelope and proves the binding equality against the
     * two durable expectations it is handed — this request's `command_key_digest` and [C7-1] the live
     * claim's stored `idempotency_key_digest` (`$expectedClaimIdempotencyKeyDigest`, which Core reads
     * from the claim row and which must also equal the digest of the request's deterministically
     * re-derivable `idempotency_key`) — and reports exactly one `DESCRIPTOR_PREFLIGHT_STATES` member. It
     * makes no outbound call, writes nothing and opens no transaction of its own, and it never returns a
     * raw reference: an `ok` verdict is what permits the `claimed → in_flight` acquisition, and a
     * `dispatch_descriptor_unavailable` verdict forbids it so the claim is ended by the fenced
     * `claimed → released` refusal transaction instead (§6.4, §8.3).
     * [C7-2] An `ok` verdict carries the one-use `ProviderDispatchCapability` it minted for the single
     * invocation that follows: the preflight alone opens the envelope, so no port call ever opens,
     * re-opens or re-validates one.
     */
    public function preflightDispatchDescriptor(PaymentExecutionRequest $request, ProviderDispatchDescriptor $descriptor, string $expectedClaimIdempotencyKeyDigest): DispatchDescriptorPreflight;
    /**
     * [C7-2] `submit`, `cancel` and `reconcile` consume the capability the pre-call preflight minted and
     * never open, re-open or re-derive an envelope: the authenticated open and the binding proof already
     * happened — before the lease was acquired — in the preflight that produced this capability. A
     * capability is one-use, adapter-owned and opaque: it is never persisted, serialised, logged,
     * exported or re-derivable, it is valid only for the single invocation it was minted for, and it
     * never substitutes for the claim's ownership fence (§8.3). [C5-3] The binding equality it encodes
     * is what makes a transplanted descriptor unusable, and it is proven against durable row values, so
     * no port call needs any memory of the sealing call. An adapter handed a capability it did not mint,
     * has already consumed, or cannot resolve must issue **no** outbound request and report the pre-call
     * refusal outcome — `outcome_state = not_attempted` with
     * `outcome_reason_code = dispatch_descriptor_unavailable` (§5.2, §6.4, §8.3).
     */
    public function submit(PaymentExecutionRequest $request, ProviderDispatchCapability $capability): PaymentExecutionOutcome;
    public function cancel(PaymentExecutionRequest $request, ProviderDispatchCapability $capability): PaymentExecutionOutcome;
    public function reconcile(PaymentExecutionRequest $request, ProviderDispatchCapability $capability): PaymentExecutionOutcome;
}

/**
 * [C4-1] The durable provider dispatch descriptor: an adapter-sealed, adapter-opened authenticated
 * ciphertext envelope. It is the only carrier of a raw provider reference anywhere in Phase T. It is
 * written once with the dispatch claim inside transaction 1, it is never updated afterwards, and it is
 * never readable by Core, a read model, an admin screen, a REST response, the outbox, a log, an
 * exception message or an export. Core transports it as an opaque handle and never opens it.
 */
final class ProviderDispatchDescriptor {
    public function cipherVersion(): string;   // the locked descriptor cipher of §11.6
    public function keyVersion(): string;      // the descriptor key version the envelope was sealed under
    public function nonce(): string;           // 24-byte secretbox nonce, hex
    public function ciphertext(): string;      // authenticated ciphertext of the locked field set
    public function digest(): string;          // keyed HMAC over (cipher_version, key_version, nonce, ciphertext)
}

/**
 * [C7-2] The adapter-owned, opaque, one-use dispatch capability. A successful preflight mints it, and the
 * single provider invocation that follows consumes it; it carries (inside the adapter, never in Core) the
 * already-opened and already-bound envelope state, so the plaintext a provider call needs is never
 * re-opened, re-decrypted or re-validated after the claim leaves `claimed`. Core transports it as an
 * opaque handle: it never constructs, inspects, serialises, persists, logs, exports or re-derives one,
 * and no Phase T table, option, transient or diagnostic carries one. It is not ownership: the claim's
 * conditional generation/token fence of §8.3 remains the only proof that an owner may call.
 */
final class ProviderDispatchCapability {
    public function digest(): string;  // keyed digest of this capability instance; never a reference, never a secret
}

/**
 * [C6-1] The immutable verdict of the non-mutating descriptor preflight (§5.3, §8.3). `state` is a
 * `DESCRIPTOR_PREFLIGHT_STATES` member: `ok` means the envelope opened and its sealed binding pair
 * equals the request's `command_key_digest` and [C7-1] the expected claim digest it was handed (and
 * therefore the claim's stored `idempotency_key_digest` that Core read and passed in), so the claim may
 * move `claimed → in_flight`; `dispatch_descriptor_unavailable` means it may not, and the seam takes the
 * fenced `claimed → released` refusal path of §8.3. There is no third state, and the verdict never
 * carries a raw reference, a partial open or a guessed value. [C6-1] The verdict also reports the sealed
 * binding pair it read from the envelope — the two keyed digests *only*, never a raw reference, and
 * empty strings when the envelope could not be opened — so Core's `PaymentExecutionIntegrity` can
 * compare them against the command row's `command_key_digest` and the claim's `idempotency_key_digest`
 * without Core ever holding an open API. [C7-2] The verdict carries the one-use capability that the
 * following provider invocation consumes; it is non-null exactly when `state` is `ok`.
 */
final class DispatchDescriptorPreflight {
    public function state(): string;                  // DESCRIPTOR_PREFLIGHT_STATES member
    public function sealedCommandKeyDigest(): string; // digest only; never a raw reference
    public function sealedIdempotencyKeyDigest(): string; // digest only; never a raw reference
    public function capability(): ?ProviderDispatchCapability; // non-null iff state() is `ok`
}

/**
 * [C4-1] Caller-supplied raw provider references for exactly one command: memory-only, never stored,
 * never logged, never returned. Each claim is proven against the mapping registry row it must already
 * own (§5.3), handed to sealDispatchDescriptor(), and discarded with the request that carried it.
 */
final class ProviderReferenceClaims {
    public function providerAccountReference(): string;                 // raw, memory-only
    public function objectReference(string $canonicalKind): ?string;    // raw, memory-only
}

/**
 * [C2-4] The verification scope resolved from the request *before* any payload parsing.
 * It is built only by the intake service from one resolved `payment_provider_accounts` row, it never
 * carries a secret value, and it is the only handle through which an adapter can reach a signing
 * secret. An adapter that is asked to verify without a complete context must refuse.
 */
final class ProviderVerificationContext {
    public function providerKey(): string;   // PROVIDERS member, from the route segment
    public function accountId(): int;        // payment_provider_accounts.id, from the account selector
    public function mode(): string;          // MODES member, copied from the resolved account row
    public function keyVersion(): string;    // active webhook signing secret version, '' when none exists
}

/** [C2-4] Immutable verification verdict: state ∈ VERIFICATION_STATES, reason ∈ WEBHOOK_REASONS. */
final class SignatureVerdict {
    public function state(): string;
    public function reason(): string;
    public function keyVersion(): string;
}

interface ProviderEventTranslator {
    public function key(): string;
    /** [C2-4] Verifies the exact raw request against one resolved account/mode scope; never throws. */
    public function verify(string $rawBody, array $headers, ProviderVerificationContext $context): SignatureVerdict;
    /** Translates a verified request into zero or more normalised envelopes. Verifies nothing itself. */
    public function translate(string $rawBody, array $headers, ProviderVerificationContext $context, string $receivedAt): array;
}
```

Rules that the seam itself enforces:

- [C4-1] `PaymentExecutionRequest` is **fully durable and fully re-derivable**: it carries only
  `provider_key`, `mode`, `operation`, `provider_account_id`, `student_id`, `obligation_id`,
  `purchase_id`, `collection_intent_id` and `renewal_cycle_id` (nullable, exactly the §8.1 selector
  shape), `amount_minor`, `currency`, `idempotency_key`, `requested_at` and — [C5-3] — the command's own
  `command_key_digest` — each one already stored on the immutable command row or copied from the
  revalidated aggregate inside transaction 1: `requested_at` is the command row's `authorised_at`,
  `command_key_digest` is the command row's `command_key_digest`, and `idempotency_key` is
  deterministically re-derivable from that key digest and must match the claim's `idempotency_key_digest`
  before it is used — [C7-1] an equality the preflight receives as an explicit expectation and Core
  re-proves against the live claim row under the claim lock before the lease. ([C5-3]
  `command_key_digest` and the derived `idempotency_key_digest` are the
  descriptor's binding pair of §5.2: neither is a raw provider reference, a secret or a new identifier,
  and both are already durable on the rows the re-drive reads.) It carries **no** raw provider, account
  or object reference, no beneficiary PII, no card/IBAN/CVV field, no provider object JSON and no
  free-text description. The only provider-facing reference material a port call ever receives is the
  sealed `ProviderDispatchDescriptor` — opened once by the preflight, whose [C7-2] one-use
  `ProviderDispatchCapability` is what the invocation actually consumes — so the request a re-drive
  rebuilds from the command row plus that descriptor is the same request that authorised the dispatch —
  nothing has to be remembered in memory across a crash for the command to stay recoverable.
- [C5-3] **The sealed descriptor is bound to exactly one command.** `DISPATCH_DESCRIPTOR_FIELDS` carries
  the binding pair of §5.2, and `sealDispatchDescriptor()` writes it from the request. Because
  `sodium_crypto_secretbox` authenticates the ciphertext without an additional-authenticated-data
  channel, the binding travels *inside* the authenticated payload rather than beside it: the envelope is
  opened exactly once per invocation, by the pre-call preflight, which refuses
  `dispatch_descriptor_unavailable` unless the sealed `command_key_digest` equals the command row's own
  key digest and the sealed `idempotency_key_digest` equals the claim's `idempotency_key_digest` — and
  the re-drive's reconstruction proof of §8.3 requires the same two equalities before it may branch. A
  descriptor sealed for another command, or transplanted onto another claim, therefore fails closed and
  is never re-derived, guessed or partly returned.
  [C7-1] The claim half of that equality is never inferred: the preflight receives the live claim's
  stored `idempotency_key_digest` as an explicit parameter and may return `ok` only when the sealed value
  equals it, and Core independently re-reads the immutable command row's `command_key_digest` and the
  live claim row's `idempotency_key_digest` under the claim lock and requires them to equal the verdict's
  two reported sealed digests (and the request's derived `idempotency_key` digest) immediately before the
  `claimed → in_flight` acquisition. A mismatch — including the case of an otherwise perfectly valid,
  openable envelope whose sealed claim digest names a different claim — is refused with
  `dispatch_descriptor_unavailable` through the fenced refusal of §8.3 and never reaches the lease.
  [C6-1] On the dispatch path the same two equalities are first proven by the adapter's non-mutating
  `preflightDispatchDescriptor()` **while the claim is still `claimed` and before the lease is acquired**:
  a failing preflight forbids the `claimed → in_flight` acquisition entirely and takes the fenced
  `claimed → released` refusal of §8.3, so a descriptor that cannot be opened or bound is refused
  before any call can be issued — never after `in_flight`, where a call may already have happened — and
  only a preflight that returns `ok` **and** Core's pre-lease digest comparison may let the claim become
  `in_flight` and invoke the provider (§6.4, §8.3).
- [C7-2] **The envelope is opened once, and the call cannot reopen it.** The preflight is the single
  place an envelope is opened for a provider invocation: its `ok` verdict mints the adapter-owned,
  opaque, one-use `ProviderDispatchCapability` carrying the opened, bound state, and the single
  invocation that follows (`submit`, `cancel` or `reconcile`) consumes that capability instead of an
  envelope handle. `submit`/`cancel`/`reconcile` therefore never open, re-open, re-derive or re-validate
  a descriptor, and there is no second, lazy open whose failure could surface after the claim left
  `claimed`. A capability is never persisted, serialised, logged, exported or re-derived, and it is
  never a substitute for the §8.3 ownership fence: it proves the descriptor binding (C5-3, C7-1), and
  the conditional generation/token statement proves the right to call. An adapter handed a capability it
  did not mint, has already consumed, or cannot resolve makes no outbound request and reports
  `not_attempted` / `dispatch_descriptor_unavailable`; on a generation-1 claim that the caller acquired
  directly from `claimed` the seam then takes the fenced no-call abort of §8.3 (a terminal `released`
  claim and a durable `refused` result, no attempt), and on a takeover generation it records the
  operator-visible exception and leaves the command `dispatching`, exactly as any other descriptor
  failure discovered while `in_flight`.
- [C4-1] The descriptor is sealed inside transaction 1 by the resolved adapter, from claims the service
  has already proven against the registry: for every canonical kind the operation needs, the keyed
  digest of the supplied raw reference must equal the recorded
  `payment_provider_objects.object_reference_digest` of the one active mapping for that
  `(account, canonical_kind, canonical_id, object_kind)`, and the raw account reference's keyed digest
  must equal `payment_provider_accounts.account_reference_digest` for the exact account the command
  names. A claim that is missing, unknown, inactive, kind-mismatched or digest-mismatched is refused
  with `dispatch_descriptor_incomplete` before any command or claim row is written, so a caller can
  never introduce a provider reference the registry does not already own (T-D8), and the raw claim is
  discarded with the request that carried it.
- The registry resolves a provider by `provider_key` through `PROVIDERS` only; an unregistered key
  fails closed with `unsupported_payment_provider` and never falls back to a default adapter.
- [C2-4] Account, mode and signing-secret selection happen **before** the body is parsed: the intake
  service resolves the request's account selector (§9.1), builds one `ProviderVerificationContext`, and
  hands it to `verify()`. Neither `verify()` nor `translate()` can be called without a context, and no
  adapter may enumerate accounts, try a second secret or select an account from the payload.
- Raw references exist in memory only inside the seal/open boundary of §11.6 and for the duration of
  one port call; `PaymentExecutionIdempotency` digests them before any storage. [C4-1] The one place a
  raw provider reference is ever written down is the authenticated ciphertext of the sealed dispatch
  descriptor. No raw reference, provider payload, secret, card field or IBAN is ever written in
  plaintext to a table, option, transient, log, exception message, admin notice, export, outbox row or
  diagnostic, and the §12.4 verifier rejects any declared column that could hold one.

### 5.4 The Stripe adapter

- `StripePaymentAdapter::key()` returns `stripe`; it is the only adapter in this build.
- No Stripe SDK dependency is added. The adapter performs provider HTTP itself through
  `wp_remote_post()`/`wp_remote_get()` with `redirection => 0`, a bounded body and no user-supplied URL;
  every returned error body is redacted before it can reach a log or exception. [C4-2] Its timeout is
  the structural `DISPATCH_CALL_TIMEOUT_SECONDS`, which is by construction shorter than the
  `DISPATCH_LEASE_SECONDS` lease the call runs under, less the `DISPATCH_LEASE_MARGIN_SECONDS` margin —
  so a lease can only expire once its owner's call has already returned or its process has already died.
- [C4-1] The adapter is the only component in Phase T that seals or opens a
  `ProviderDispatchDescriptor`; `sealDispatchDescriptor()` runs inside the command's transaction 1 and
  makes no outbound call, [C6-1] `preflightDispatchDescriptor()` opens the envelope and proves the
  binding pair — [C7-1] including the claim's stored `idempotency_key_digest`, which Core passes in as
  the expected value — while the claim is still `claimed`, with no outbound call, no write and no
  transaction of its own, and only its `ok` verdict lets the claim move to `in_flight` (§8.3).
  [C7-2] The same call mints the one-use `ProviderDispatchCapability` that carries the opened, bound
  state, and `submit()`/`cancel()`/`reconcile()` consume that capability to obtain the references the
  provider protocol needs: they never open an envelope themselves, so no provider call can ever perform
  a second open, and a capability the adapter did not mint or cannot consume makes no outbound request
  and reports `not_attempted` / `dispatch_descriptor_unavailable`. A descriptor whose cipher or key
  version is unknown, whose authentication fails, or whose sealed field set is not exactly
  `DISPATCH_DESCRIPTOR_FIELDS` is refused with `dispatch_descriptor_unavailable` — [C6-1] the same
  verdict the preflight reports, so the refusal happens before any lease is taken and never after
  `in_flight` — and is never re-derived.
- `submit()`/`cancel()`/`reconcile()` first resolve the account row and refuse, in this order, with
  `provider_account_inactive`, `provider_execution_disabled`, `provider_credentials_unconfigured`
  or `live_execution_not_authorised`. [C2-5] Because this build provisions no Stripe credential (§11.5) and
  `LIVE_EXECUTION_PROVIDERS` is empty, the Stripe adapter is **provably incapable of any outbound
  call** in this release; the tests prove that refusal and the seam's full behaviour is proven with a
  network-free fake adapter.
- The adapter never decides eligibility, never computes an amount from a provider response, never
  writes commercial storage and never retries delivery of a customer message.

## 6. What authorises an execution command

### 6.1 R1 authority (both collection kinds ultimately settle through R1)

An execution command may exist only for an **unsettled obligation of an accepted R1 purchase**. Before
any command row is written, the service proves, inside the guarded transaction, through the existing
R1 validators (never by reimplementing them):

1. the obligation belongs to the offer of the accepted purchase, with the recorded currency and plan;
2. the commitment chain validates (`CommercialLineageValidator` / `CommercialCommitmentValidator`
   semantics) so a repointed purchase, entitlement or claim can never be collected against;
3. the obligation has no `commercial_obligation_settlements` row (otherwise
   `obligation_already_settled`);
4. the recorded `amount_minor` and `currency` are copied from `commercial_offer_obligations`;
5. the beneficiary's `commercial_account_roots` row is locked first, preserving the fixed R1 lock
   order (`commercial account root → canonical Enrolment → ascending Teacher → Assignment →
   per-Teacher scheduling root`).

### 6.2 R2 authority (recurring collection)

For `submit_collection` the R1 chain above is necessary and not sufficient. The command additionally
requires a live R2 collection intent:

1. the intent is in `submitted` state for **this exact** obligation (`collection_intent_not_submitted`
   otherwise), and its cycle is in a live state (`pending`, `guarantee_protected`, `payment_required`,
   `collected`, `term_bound` — never `lapsed`, `cancelled`, `closed`);
2. the intent kind agrees with the cycle's **frozen** collection mode
   (`manual` → `manual_payment_required`, `automatic` → `automatic_charge`); a mismatch is
   `collection_kind_conflict`;
3. when the intent carries a `charge_at`, that instant has arrived; a future instant is
   `charge_time_not_due`, and an intent with no `charge_at` may be collected only for the `manual`
   kind (an automatic collection without a policy-derived instant does not exist — T-D9);
4. the Cycle's `renewal_cycle_id` and the intent's `renewal_cycle_id` are the same row, and the
   obligation is one of that cycle's own commitment's obligations.

### 6.3 Provider account authority

The account row must be `active`, must record `execution_state = enabled`, must be `configured` for
credentials, and must be a non-live mode unless its provider key is listed in
`LIVE_EXECUTION_PROVIDERS` (empty in this phase, so a live account is always refused with
`live_execution_not_authorised`). The account's `provider_key` must be a `PROVIDERS` member, and the
account's mode must be the mode the request carries.

`credential_state` is a recorded fact, not a secret: a runtime fixture may record `configured` for the
network-free fake provider so the seam can be exercised end to end, while the Stripe adapter
additionally resolves the vault (§11) and therefore still refuses with
`provider_credentials_unconfigured` in this build. [C2-5] No fixture, test or production path can turn
that recorded fact into a stored provider secret: the vault's write surface refuses every provider
(§11.3), and only the constant-gated test vault can hold a value at all (§11.5), so `credential_state =
configured` on a Stripe account remains an assertion that no Stripe code path can satisfy here.

### 6.4 Refusal vocabulary

Every refusal is a recorded, reasoned outcome — never an exception that loses the attempt and never a
silent no-op: `payment_execution_not_authorised`, `provider_account_inactive`,
`provider_execution_disabled`, `provider_credentials_unconfigured`, `live_execution_not_authorised`,
`collection_intent_not_submitted`, `collection_kind_conflict`, `obligation_already_settled`,
`charge_time_not_due`, `unsupported_payment_provider`, and — [C3-1] — `dispatch_in_flight` (another
live dispatch claim already owns this command's arbitration subject, so no provider call is made).
Refusals are return values with a durable command row; only malformed input and integrity corruption
throw.

[C4-1] Two further refusals belong to the sealed dispatch descriptor of §5.3/§8.3 and follow the same
discipline:

- `dispatch_descriptor_incomplete` — a caller's `ProviderReferenceClaims` do not cover a reference the
  operation needs, or a supplied raw reference does not digest-match the mapping/account row that must
  already own it. The refusal happens inside transaction 1, no command row and no claim row are written,
  and no provider is reached.
- `dispatch_descriptor_unavailable` — a dispatch claim exists but its sealed envelope cannot be opened
  (unknown cipher or key version, failed authentication, wrong sealed field set, a salt change, or — [C5-3]
  — a binding pair that does not name this command's `command_key_digest` and this claim's
  `idempotency_key_digest`, including — [C7-1] — a perfectly valid, openable envelope whose sealed claim
  digest does not equal the live claim row's `idempotency_key_digest`). It is a **durable refusal on a
  claim that provably never issued a call**, and the command's result row is written with this code.
  [C6-1] It is produced on **every** dispatch path that can encounter it, not only on recovery: §8.3
  step 2 runs the adapter's non-mutating preflight while the claim is still `claimed` and **before** any
  lease is acquired, so an initial `submit`/`cancel` whose descriptor cannot be opened or bound is
  refused here — with a durable `refused` result, a terminal `released` claim and no attempt — instead
  of being discovered after `in_flight`; the re-drive's `claimed` reconstruction check produces the
  identical refusal for a recovered claim. [C5-1] That result is not written on its own: §8.3 writes it
  in the single fenced transaction that also ends the claim (`claimed → released`, or — [C7-2] — the
  provably call-free generation-1 no-call abort) and releases the subject's live slot, so the refusal is
  representable without deleting the claim and without ever leaving a live claim over a command that
  already has a result. [C7-2] The other reachable source of this code is the port's own pre-call refusal
  — `outcome_state = not_attempted` — for a capability it cannot consume: that report is valid only when
  **no** outbound request was made, and it is what the seam's fenced no-call abort turns into this
  durable refusal on a generation-1 claim; on a takeover generation, where an earlier call is possible,
  the code is never written. On an `in_flight` claim that is not such a generation-1 acquisition, where
  a call may already have been issued, Phase T refuses nothing and repairs nothing: the re-drive makes
  no call, leaves the command durably `dispatching`, records a commercial exception for an operator (§13)
  and reports the pending state, because a refusal there would falsely assert that no provider request
  exists.

## 7. Mapping registry (canonical ↔ provider)

| Canonical concept | Provider object kind | Cardinality | Stored as | Authority |
| --- | --- | --- | --- | --- |
| Platform Student | `customer` | at most one active per (account, Student) | `payment_provider_objects` with `canonical_kind = student` | none — a mapping never grants record access (PRODUCT-DECISIONS / DATA-MODEL) |
| R1 purchase | `payment_method` (`mandate` for direct debit) | at most one active per (account, purchase, kind) | `payment_provider_objects` | none |
| R1 obligation | `intent` / `charge` | one active per (account, obligation, kind) | `payment_provider_objects` | none |
| R2 collection intent | `intent` | one active per (account, collection intent) | `payment_provider_objects` | none |
| R2 recurring enrolment | `subscription` | mapping is recorded but performs nothing | `payment_provider_objects` | none; provider recurring semantics stay unresolved (T-D9) |

Rules:

- The registry stores `object_reference_digest` (keyed) and never the raw provider object id; the
  raw id may exist in memory during one call only.
- A mapping links **one** canonical row to **one** provider object of one kind. The uniqueness
  arbitration is `UNIQUE (payment_provider_account_id, object_kind, object_reference_digest)` for the
  provider side and `UNIQUE (payment_provider_account_id, canonical_kind, canonical_id, object_kind,
  active_slot)` for the canonical side, using the repository's existing `active_slot` pattern
  (`1` = active, `NULL` = historical) so exactly one active link can exist and history is retained.
- A mapping row is **never** sufficient input on its own: the webhook translation resolves the
  canonical row from the mapping, then asks R1/R2 whether the resulting evidence is acceptable.
- Detaching or superseding a mapping is an append-only event; it never rewrites commercial history and
  never deletes payment evidence.
- A mapping may be created only by an administrator holding `dzn_manage_payment_providers`, with
  recorded evidence, and is refused for a provider account in `closed` state.

## 8. Execution commands and attempts

### 8.1 Command shape (operation-specific selectors)

Reusing the R1 `CommercialCommandShape` discipline, every persisted execution command carries a
complete, operation-specific selector shape. Owned selectors must equal the revalidated aggregate;
every other selector must remain exactly NULL.

| Operation | Owned selectors (must equal the revalidated aggregate) | Selectors that must be exactly NULL |
| --- | --- | --- |
| `submit_collection` | `student_id`, `provider_account_id`, `purchase_id`, `obligation_id`, `collection_intent_id`, `renewal_cycle_id` | — (owns every selector its command row carries) |
| `cancel_collection` | `student_id`, `provider_account_id`, `obligation_id`, `collection_intent_id` | `purchase_id`, `renewal_cycle_id` |
| `reconcile_collection` | `student_id`, `provider_account_id`, `obligation_id`, `collection_intent_id` | `purchase_id`, `renewal_cycle_id` |

[C2-1] The command row is **immutable authorisation evidence**: it is inserted exactly once, is never
updated, and carries `provider_key`, `mode`, `amount_minor`, `currency`, `provider_reference_digest`
(nullable), `authorised_at` and the audit pair. It carries **no** `result_state` and **no** `result_id`
column, and it has no `updated_at`: the terminal state of a command is recorded only in the append-only
`payment_execution_results` table (§8.2, §12.2), so a reviewer-enforced append-only invariant holds for
every column of both tables. A foreign-but-valid identifier left in an unowned selector is
contamination rather than evidence and fails the replay (the R1 `C6-MAJOR-001` lesson).
`reconcile_collection` is a read-only provider-state fetch that records outcomes as attempts; it
settles nothing.

[C3-1] Every command that may reach a provider also carries a **dispatch claim** in the mutable
`payment_execution_dispatches` aggregate (§12.1), written inside the guarded transaction that inserts
the command and therefore **before the account-root lock is released**. The claim records the command's
*arbitration subject*: the exact `collection_intents` row named by `collection_intent_id` when the
operation owns one, otherwise the exact `commercial_offer_obligations` row named by `obligation_id`.
The claim is coordination only — it records dispatch ownership and the digest of a deterministically
re-derivable provider idempotency key, never the command's terminal state, which stays only in the
append-only `payment_execution_results` row (§8.2). It is what makes a crash recoverable and what makes
two opposing operations for one intent impossible to run concurrently (§8.3, §14).
[C4-1] The same insert also carries the **sealed provider dispatch descriptor** for that command
(§5.3, §12.1): the adapter-produced envelope holding the provider-facing references the call needs,
plus the keyed digest of that envelope. The descriptor is what makes a claim *reconstructible* rather
than merely ownerful — without it a recovered owner would know it owns a dispatch but not what to send.
It is written once with the claim, never updated, and it is the only row of Phase T that carries
provider reference material at all — and then only as authenticated ciphertext Core cannot open.
[C4-2] The claim additionally carries its `claim_generation` and `claim_token_digest`: the monotonic
fencing pair of §8.3, without which two owners could take one expired lease over and both call the
provider.
[C5-1] A claim whose owner never issued a call may additionally be ended `released` — the fenced
descriptor-refusal transaction of §8.3 — which is the single case in which a claim row and its
command's terminal result row legitimately coexist (§8.2 rule 5).

### 8.2 Lifecycle

```text
payment_execution_commands        (immutable: inserted once, never updated)
  └── derived state (derived only; never stored on the command row)
        ├── dispatching → a live `payment_execution_dispatches` claim exists (`claimed` or
        │                  `in_flight`) and no result row exists  [durable, recoverable dispatch owner]
        ├── completed   → payment_execution_results(result_state='completed',
        │                  result_id = the command's attempt id)  [after the single port call]
        ├── refused     → payment_execution_results(result_state='refused',
        │                  reason_code = a §6.4 code, result_id NULL)  [gate/claim refused, no call]
        │                  [C5-1] a `dispatch_descriptor_unavailable` refusal additionally leaves the
        │                  claim terminal in `released` — [C7-2] reached from `claimed` by the pre-lease
        │                  refusal, or by the provably call-free generation-1 no-call abort
        │                  (the one claim+result pairing rule 5 permits)
        └── conflicted  → payment_execution_results(result_state='conflicted',
                           reason_code = COMMAND_CONFLICT_REASON, result_id NULL)
```

Result-record and claim rules ([C2-1], [C3-1], enforced by `PaymentExecutionIntegrity` and re-checked
by the read model and the Schema 028 verifier's runtime probe):

1. A command row is never updated. The command's **effective state** is derived and never stored
   twice: its `payment_execution_results` row's `result_state` when one exists; otherwise `dispatching`
   when it holds a live dispatch claim; otherwise `authorised`.
2. [C3-1] A committed command is never `authorised`-with-no-owner. Transaction 1 writes the command
   row together with either its terminal `refused` result (§6.4) or its live dispatch claim (§8.3), so
   a persisted command whose derived state is `authorised` — no result row **and** no live claim — is
   corruption: it fails the integrity check, is never dispatched and is never silently repaired.
   `authorised` remains in the vocabulary only for the instant inside transaction 1 before the result
   or claim is written, and it can never be persisted as a result (`RESULT_STATES` excludes it).
3. `payment_execution_results` accepts exactly one row per `execution_command_id`
   (`UNIQUE command_result(execution_command_id)`) and is insert-only: no `updated_at`, no update path,
   no delete path.
4. A `completed` result must name, in `result_id`, the single attempt row of its own command
   (`payment_execution_attempts.execution_command_id` must equal `execution_command_id`), and a
   `refused`/`conflicted` result must name no attempt and must carry the controlled reason code of the
   gate refusal (§6.4) or of the command-key arbitration that produced it. A result row that violates
   either rule is corruption: it fails closed, manufactures no settlement and is never silently
   repaired.
5. [C3-1] The dispatch claim is the **only** mutable execution row (§12.1): exactly one per command
   (`UNIQUE command_dispatch(execution_command_id)`), at most one live claim per arbitration subject
   (`UNIQUE subject_claim(arbitration_subject_kind, arbitration_subject_id, active_claim_slot)`, with
   `active_claim_slot = 1` live and `NULL` once terminal — terminal being `settled` or, [C5-1],
   `released`), `dispatch_state` ∈ `DISPATCH_STATES`, and no command terminal state stored on it.
   A claim whose `dispatch_state` is not a `DISPATCH_STATES` member, or that shares a subject's live
   slot, is corruption and fails closed. A claim whose command already has a result row is corruption
   too — with **one** permitted exception, [C5-1]: the claim is terminal in `released`, and the result
   row it coexists with is that command's own `refused` row carrying the `dispatch_descriptor_unavailable`
   code, written by the single fenced transaction of §8.3. [C7-2] Both release edges produce that one
   pairing — the pre-lease `claimed → released` refusal and the fence-proven generation-1 no-call abort —
   and the abort clears `lease_expires_at` in its own statement, so the "released carries no lease" rule
   below is unchanged. That pairing is exactly what makes the
   descriptor-refusal path representable without deleting a claim; any other claim carrying, or sharing a
   command with, a result row fails closed and is never silently repaired.
   [C4-1] Its arbitration subject, idempotency-key digest and sealed descriptor columns are
   **write-once**: after the insert, only `dispatch_state`, `claim_generation`, `claim_token_digest`,
   `lease_expires_at`, `settled_at`, `active_claim_slot`, `updated_at` and `updated_by` may ever change.
   A claim whose stored `descriptor_digest` no longer matches its stored envelope, or whose envelope is
   not a complete sealed descriptor, is corruption and fails closed rather than being re-derived.
   [C4-2] `claim_generation` is a positive, monotonically increasing integer that starts at `1` and is
   advanced only by the conditional takeover of §8.3. A claim whose generation is not positive, whose
   `in_flight` state carries no `lease_expires_at`, or whose `claimed` or `released` state carries one,
   is corruption and fails closed: it is never dispatched and never silently repaired.
6. Fields are validated against a locked vocabulary: `result_state` ∈ `RESULT_STATES`, `refused`
   reasons ∈ the §6.4 refusal codes, `conflicted` reason = `COMMAND_CONFLICT_REASON`.

An attempt row is append-only: `attempt_sequence` is `1` for the command's single port invocation,
`outcome_state` is a `PaymentExecutionRule::OUTCOME_STATES` member, and the raw provider status string
is never stored — only a controlled reason code. **One command authorises at most one attempt**; there
is no mutable attempt counter on the command row and no second attempt for a replayed key: a replayed
key converges on the recorded command — on its result when one exists, or on its pending dispatch claim
while the dispatch is still unresolved — and never invokes the port again (§8.3). A retry after a
recorded outcome is always a new command row with a new idempotency key.

### 8.3 Transaction and call discipline (T-D13)

[C3-1] Three durable, ordered steps — the provider call never inside a transaction and never unowned:

1. **Transaction 1 (guarded; the account-root lock is held until commit):** lock the account root,
   revalidate §6 and arbitrate the command key, then
   - when a §6 gate refuses — insert the immutable command row **and** the terminal `refused` result row
     in this same transaction (§6.4 requires the refusal to be durable), take **no** dispatch claim, and
     commit. The derived state is `refused` and the command can never reach a provider.
   - when every gate passes — first seal the adapter's `ProviderDispatchDescriptor` for this command
     from the caller's already-validated `ProviderReferenceClaims` (a pure computation with no outbound
     call and no I/O, §5.3), then insert the immutable command row **and** its
     `payment_execution_dispatches` claim in this same transaction: `dispatch_state = 'claimed'`,
     `claim_generation = 1`, `claim_token_digest = <fresh keyed digest>`, `active_claim_slot = 1`, the
     arbitration subject of §8.1, the digest of the deterministic provider idempotency key, and the
     sealed descriptor of [C4-1] with its envelope digest. Commit. That commit *is* the durable dispatch
     ownership: a crash after it leaves a `dispatching` command whose owner, subject, key, fence and
     sealed descriptor are all recoverable — never a stranded or unsendable command. If the arbitration
     subject already holds a live claim, the unique index
     `UNIQUE subject_claim(arbitration_subject_kind, arbitration_subject_id, active_claim_slot)` rejects
     the insert, so the arriving command is refused durably with `dispatch_in_flight` in the same
     transaction and makes **no** provider call (§14); the descriptor sealed for the loser is discarded
     with the rollback and never reaches a table.
2. **Outside any transaction:** dispatch exactly once, with a stable, deterministically re-derivable
   idempotency key, and only for a command whose derived state is `dispatching`. [C6-1] This step has a
   fixed order — **preflight, then Core's binding comparison, then lease, then call** — so a descriptor
   failure is always discovered while the claim is still `claimed` and can take the fenced
   `claimed → released` refusal path below: a claim that fails its preflight or its Core binding
   comparison is never dispatched, is never recorded as an attempted outcome and is never left
   permanently `dispatching`. [C7-2] The open happens in the preflight and nowhere else: its `ok` verdict
   mints the one-use capability that the single call below consumes, so no port invocation ever opens or
   re-validates a descriptor — and the only failure this step can still meet after the acquisition is a
   capability the port cannot consume, which makes no outbound request and is represented by the fenced
   no-call abort below, never by a refused-after-`in_flight` descriptor reopen and never by a phantom
   attempt. [C4-2] Every ownership change in this step is a **single conditional
   statement whose affected-row count is the proof of ownership**; an owner that is not the proven
   current generation makes no call and writes nothing:
   - **[C6-1] Pre-call descriptor preflight (the claim is still `claimed`; no lease exists yet).** The
     owner rebuilds the port request from durable rows only — the immutable command row plus the claim's
     sealed descriptor, which it hands to the adapter as an opaque handle ([C4-1]) — and proves the
     descriptor *before* it acquires any lease, with two checks that make no outbound call and write
     nothing: Core's reconstruction proof (the claim's stored `descriptor_digest` equals the envelope's
     digest, and the revalidated aggregate still equals the command's selector shape, amount and
     currency, as in the crash-and-re-drive protocol below) and the adapter's **non-mutating
     `preflightDispatchDescriptor()`** (§5.3), whose verdict is a `DESCRIPTOR_PREFLIGHT_STATES` member.
     [C7-1] Core passes the expected claim binding explicitly: it reads the live claim row's
     `idempotency_key_digest` and hands it to the preflight together with the request that already
     carries the command's `command_key_digest`, so an `ok` verdict itself requires the sealed pair to
     name *this* command **and** *this* claim rather than merely being self-consistent:
     - `ok` — the envelope opened and its sealed `command_key_digest`/`idempotency_key_digest` equal this
       request's `command_key_digest` and the expected claim digest Core passed in: the owner may proceed
       to Core's binding comparison and the lease acquisition below, and the verdict carries the one-use
       `ProviderDispatchCapability` minted for the single call this dispatch will make.
     - `dispatch_descriptor_unavailable` — the envelope cannot be opened (unknown cipher or key version,
       failed authentication, wrong sealed field set, a salt change) or its binding does not name this
       command's `command_key_digest` and this claim's `idempotency_key_digest`: because the claim is
       still `claimed`, **no call can have been issued**, so the owner takes the fenced refusal path
       defined under the re-drive protocol below — one conditional `claimed → released` update under the
       claim row lock whose affected-row count must be exactly `1` and, only when it is, the command's
       `refused`/`dispatch_descriptor_unavailable` result appended in the same transaction. `0` affected
       rows means another owner moved the claim first: nothing is written, nothing is called and the
       pending state is reported. This is the **initial-dispatch** descriptor refusal of §6.4: exactly
       one refused result, no attempt row and no provider call, the subject's live slot released, and the
       claim/result pairing that §8.2 rule 5 permits. A stored `descriptor_digest` that no longer matches
       its own envelope is *not* this path — that is corruption and fails closed (§8.2 rule 5).
     A failing preflight never reaches the lease acquisition, so its claim is never observed `in_flight`,
     and because the preflight is non-mutating, two concurrent owners may both pass it safely: the lease
     acquisition below remains the single proof of ownership.
   - **[C7-1] Core's mandatory binding comparison, under the claim lock and immediately before the
     acquisition.** The owner takes the claim row lock for the acquisition and, in that same locked
     step, re-reads the claim row's `idempotency_key_digest` and the immutable command row's
     `command_key_digest`, and requires `verdict.sealedCommandKeyDigest() === command.command_key_digest`
     **and** `verdict.sealedIdempotencyKeyDigest() === claim.idempotency_key_digest === <digest of
     request.idempotency_key>`. Both equalities are a precondition of the acquisition below, not a second
     open: the digests are values the `ok` verdict already reported and the command row is immutable, so
     nothing can go stale between them. **Any inequality forbids the `claimed → in_flight` acquisition**
     and takes the same fenced `claimed → released` refusal as a failing preflight (`refused` /
     `dispatch_descriptor_unavailable`, no attempt, no provider call, live slot released), because a
     claim whose descriptor does not bind it must never be dispatched. This is the path an otherwise
     valid, openable envelope takes when its sealed claim digest names a different claim.
   - then, in that same locked step, the owner acquires the lease from `claimed` with one compare-and-swap
     update (`… SET dispatch_state = 'in_flight', claim_token_digest = <fresh keyed digest>,
     lease_expires_at = <now +
     DISPATCH_LEASE_SECONDS> WHERE id = <claim> AND dispatch_state = 'claimed' AND claim_generation = 1`),
     committing that short transition. Exactly one affected row is required; zero rows means another
     owner moved the claim first, so this owner writes nothing, makes no call, discards the capability and
     reports the pending state. **`claimed` therefore means no call has been issued, and `in_flight`
     means a call may have been issued** — the distinction the recovery protocol below relies on, and the
     fence the takeover below can only move forward;
   - the owner then performs the single port invocation, handing it the capability the preflight minted,
     and never re-issues it inside this dispatch. [C7-2] `submit`/`cancel`/`reconcile` consume that
     capability and never open, re-open or re-validate an envelope, so the descriptor is opened exactly
     once and always *before* the claim leaves `claimed`; the preflight above is what decided that this
     claim could become `in_flight` at all, so no lease is ever acquired, and no call is ever made, for a
     claim that failed its preflight or its binding comparison. Its structured timeout is
     `DISPATCH_CALL_TIMEOUT_SECONDS`, which the structural constants guarantee is shorter than the lease
     it holds, less `DISPATCH_LEASE_MARGIN_SECONDS` — so its lease cannot expire while that call is still
     running, and an expired lease therefore always means the owner's call has returned or its process has
     died ([C4-2]). [C7-2] If the port cannot consume the capability it is handed, it makes **no**
     outbound request and reports `not_attempted` / `dispatch_descriptor_unavailable`; the seam then
     writes no attempt and no `completed` result and takes the fenced no-call abort defined under the
     re-drive protocol below — one conditional `in_flight → released` update for the owner's own
     generation-1 acquisition that clears `lease_expires_at` and `active_claim_slot` and, only when it
     affects exactly one row, appends the command's `refused`/`dispatch_descriptor_unavailable` result in
     the same transaction. That abort is available **only** for a generation-1 claim the owner acquired
     directly from `claimed`, never for a takeover generation: generation `1` can only have been created
     by the acquisition from `claimed`, and an unexpired lease proves no takeover happened, so the claim
     is provably call-free.
3. **Transaction 2 (guarded and fenced):** the owner settles with one conditional update that requires
   the exact `claim_generation` and `claim_token_digest` it acquired (`… SET dispatch_state = 'settled',
   active_claim_slot = NULL, settled_at = <now> WHERE id = <claim> AND dispatch_state = 'in_flight' AND
   claim_generation = <own generation> AND claim_token_digest = <own token>`), releasing the subject's
   slot for a later command; **only when that update affected exactly one row** does it append the
   command's single attempt and its terminal result row (`completed`, naming that attempt in `result_id`)
   in the same transaction, then publish the hook after the commit. [C4-2] A zero-row settlement means
   the owner has been fenced out — its lease expired and a successor took over — so it records **no**
   attempt and **no** result, discards its in-memory outcome and reports the pending state, because the
   successor's reconciliation with the same deterministic idempotency key is what adopts the provider's
   actual state. The attempt, the result and the settlement of the claim are therefore always written by
   exactly one fenced owner, and never by two.

**Crash and re-drive protocol.** `PaymentExecutionService::redrive($commandId)` is the explicit,
idempotent, repeat-safe entry point for a later scheduler or worker (§13). For a command whose derived
state is `dispatching` it resolves exactly as the recorded claim dictates:

- It takes no request, no reference and no user id as input, and it rebuilds everything it needs from
  durable rows, so it is safe to call blind. [C4-3] It is **not** an anonymous entry point: it requires
  an authenticated caller holding `dzn_manage_payment_execution` — an operator surface, or a later
  scheduler principal that holds it — because it writes execution rows carrying a
  `recorded_by`/`created_by` actor. The event worker principal of §9.7 is deliberately never used here
  and never holds that capability, so the two bounded identities never merge and the webhook identity
  can never drive a dispatch.
- **Reconstruction is proven before any branch ([C4-1], [C5-3]).** The re-drive rebuilds the port request
  from the immutable command row plus the claim's sealed descriptor, and `PaymentExecutionIntegrity`
  proves the pairing: the command's selector shape, amount and currency must still equal the revalidated
  aggregate (§6); the descriptor's envelope digest must equal
  `payment_execution_dispatches.descriptor_digest`; and the sealed plaintext's **binding pair** must equal
  this command's `command_key_digest` and this claim's `idempotency_key_digest` ([C5-3]). Opening the
  envelope is adapter-scoped (§11.6): Core never decrypts and holds no open API, so the proof is the same
  non-mutating `preflightDispatchDescriptor()` call of §5.3 — [C7-1] handed the live claim's
  `idempotency_key_digest` as its expected value — whose verdict reports the two sealed digests *only*,
  never a raw reference, for `PaymentExecutionIntegrity` to compare against the command and claim rows.
  [C7-2] That preflight is the only open: its `ok` verdict carries the one-use capability the following
  invocation consumes, and `submit`/`cancel`/`reconcile` never re-open, re-decrypt or re-prove the
  envelope themselves, so a binding mismatch is refused exactly once — by the proof, before any call.
  A descriptor that cannot be opened or whose binding does not match never becomes a call:
  - on a `claimed` claim the command is durably refused with `dispatch_descriptor_unavailable` (§6.4).
    [C5-1] That refusal and the end of the claim are one fenced transaction, never two writes: first a
    single conditional statement —
    `UPDATE … SET dispatch_state = 'released', active_claim_slot = NULL, settled_at = <now>
    WHERE id = <claim> AND dispatch_state = 'claimed' AND claim_generation = <observed generation>
    AND claim_token_digest = <observed token>`, again with every comparison bound to **one** captured
    instant and executed under the claim row lock — whose affected-row count must be exactly `1`. **Only
    when it is** does that same transaction append the command's `refused` result row with
    `reason_code = dispatch_descriptor_unavailable`. So the claim is ended and its result is written
    together or not at all, the terminal `released` claim clears the live slot exactly as a settlement
    does, and a later command for the same subject is never blocked by an unopenable envelope. `0`
    affected rows means another owner moved the claim first: nothing is written, nothing is called and
    the pending state is reported. Because the claim was `claimed`, no provider request can have been
    issued, so the refusal asserts nothing false — and the coexistence it produces is the single
    claim/result pairing §8.2 rule 5 permits.
    [C6-1] This fenced `claimed → released` refusal transaction is the **same** transaction §8.3 step 2
    runs for an initial-dispatch preflight failure: the normal submit/cancel path and the re-drive's
    `claimed` path share one refusal mechanism, one resulting claim state and one reason code, so a
    descriptor failure that precedes any provider call has exactly one representation whether it is met
    on first dispatch or on recovery. The only difference is which owner takes it — step 2's owner does
    so before it ever acquires the lease, and the re-drive does so on a claim it reconstructed.
  - on a generation-1 `in_flight` claim whose owner acquired it directly from `claimed` and whose port
    reported the pre-call refusal `not_attempted` / `dispatch_descriptor_unavailable` — [C7-2] a
    capability the adapter could not consume, so **no** outbound request was made — the seam writes no
    attempt and no `completed` result and takes the **fenced no-call abort** instead of the `claimed →
    released` refusal above: one conditional statement
    `UPDATE … SET dispatch_state = 'released', lease_expires_at = NULL, active_claim_slot = NULL,
    settled_at = <now> WHERE id = <claim> AND dispatch_state = 'in_flight' AND claim_generation = 1
    AND claim_token_digest = <this owner's token> AND lease_expires_at IS NOT NULL
    AND lease_expires_at > <the same captured now>`, taken under the claim row lock, whose affected-row
    count must be exactly `1`; only then does that same transaction append the command's `refused` row
    with `reason_code = dispatch_descriptor_unavailable`. The predicate is a proof, not a guess:
    generation `1` is only ever created by the acquisition from `claimed`, and an unexpired lease means no
    takeover ever happened, so no owner other than this one can have issued a call — and the abort is
    taken instead of writing any attempt. `0` affected rows means this owner was fenced out or the claim
    was already ended: nothing is written, nothing is called and the pending state is reported. This is
    the representation §8.3 step 2 uses for a post-preflight capability failure, and it is the contract's
    only `in_flight → released` edge.
  - on an `in_flight` claim, where a call may already have been issued, Phase T refuses nothing and
    repairs nothing: the re-drive makes no call, leaves the command durably `dispatching`, records the
    operator-visible exception of §6.4 rather than assert that no provider request exists, and leaves the
    claim `in_flight` under its lease for the ordinary re-drive protocol below. [C7-2] This is also what a
    takeover generation (`claim_generation > 1`) does when its capability cannot be consumed: a
    generation-1 abort is never available there, because a previous owner's call is possible.
- claim `claimed` (no call was ever issued): **only when the reconstruction proof above succeeded** —
  the envelope opened and its binding pair matched this command and this claim — the re-drive may
  perform the initial dispatch of step 2, with the same deterministic idempotency key, through the same
  conditional lease acquisition, so two concurrent re-drives of one `claimed` claim cannot both acquire
  it and cannot both call. A `claimed` claim whose reconstruction proof **failed** takes the
  `dispatch_descriptor_unavailable` release path instead and is never dispatched. [C6-1] For a
  `claimed` claim the reconstruction proof above **is** step 2's preflight — Core's reconstruction proof
  plus the adapter's non-mutating `preflightDispatchDescriptor()` — so a re-drive performs the identical
  preflight → [C7-1] Core binding comparison → lease → call order and a failure at either pre-lease check
  takes the identical fenced refusal transaction.
- claim `in_flight` with an **unexpired** lease: another owner may be alive; the re-drive performs **no**
  provider call, leaves the command durably `dispatching` and reports the pending state. Lease expiry is
  the only thing that ever makes a claim takeable again.
- claim `in_flight` with an **expired** lease (the previous owner crashed, before or after its call):
  the re-drive **takes the claim over atomically, then reconciles before it re-issues.** Only the
  takeover winner may act:
  - **Atomic, fenced takeover ([C4-2]).** The takeover is exactly one conditional statement —
    `UPDATE … SET dispatch_state = 'in_flight', claim_generation = claim_generation + 1,
    claim_token_digest = <fresh keyed digest>, lease_expires_at = <now + DISPATCH_LEASE_SECONDS>
    WHERE id = <claim> AND dispatch_state = 'in_flight' AND claim_generation = <observed generation>
    AND lease_expires_at IS NOT NULL AND lease_expires_at < <now>`, with every comparison bound to **one**
    captured instant and executed under the claim row lock. Exactly one affected row is required. Two
    re-drives that observe the same expiry therefore cannot both proceed: whichever commits first bumps
    the generation and writes a future lease, and the other's predicate is then false on both counts, so
    its affected-row count is `0`, it writes nothing, it calls nothing and it reports the pending state.
    A stale observer holding an older generation is refused the same way, and no observer can guess a
    generation because the pair is read and compared inside the same statement.
  - **Reconcile and re-issue under the new fence ([C5-2]).** The winner — and only the winner — invokes
    the read-only `reconcile` operation with the *same* idempotency key and the reconstructed request,
    [C7-2] handing it a capability freshly minted by a non-mutating preflight performed under the
    winner's own fence — a takeover generation always mints its own capability, because the claim is
    already `in_flight` and the generation-1 no-call abort is never available to it — and
    adopts the reconciled provider state as the command's single attempt (`outcome_state` from
    `OUTCOME_STATES`, `outcome_reason_code = provider_reconciled`, the provider reference digest when
    reconcile returns one) and settles it through the fenced transaction 2 above. Only when
    reconciliation proves the provider never received the request may the winner issue the original
    mutating call once, with the same key — and it may only do so through a **distinct conditional
    pre-call ownership check** that is deliberately *not* step 2's generation-1 acquisition: a takeover
    claim is `in_flight` at a generation greater than `1`, and the acquisition transition (`… WHERE
    dispatch_state = 'claimed' AND claim_generation = 1`) can therefore never apply to it. The check is
    exactly one conditional statement —
    `UPDATE … SET lease_expires_at = <one captured now + DISPATCH_LEASE_SECONDS>
    WHERE id = <claim> AND dispatch_state = 'in_flight'
    AND claim_generation = <the generation this winner acquired>
    AND claim_token_digest = <the token this winner acquired>
    AND lease_expires_at IS NOT NULL AND lease_expires_at > <the same captured now>`, taken under the
    claim row lock — whose affected-row count must be exactly `1`. That single statement both proves the
    winner still holds the fence it acquired and renews the lease so the structural timeout inequality
    again covers the coming call. `0` rows means the winner has been fenced out — its lease expired and a
    successor took the claim over — so it issues no call and writes nothing, and the successor reconciles
    again. Only after that check returns exactly one row may the winner make the call, and its attempt and
    result are written only by a settlement that proves that same generation and token. If the winner
    loses its own fence before it settles, it writes nothing and discards its outcome, and the next taker
    reconciles again. [C7-2] That mutating call consumes its own freshly minted capability, and — like the
    reconcile invocation above — never opens the envelope itself: a capability failure before it makes no
    outbound request and is never turned into a `refused` result, because on a takeover generation the
    command stays `dispatching` with the operator-visible exception (the rule immediately above).
  - It never issues a second mutating call after a call that may have succeeded, and a re-drive never
    creates a second claim, a second attempt or a second command. The deterministically re-derivable
    idempotency key is the final backstop: even in a pathological race that reached the provider twice,
    both requests carry the same key, so the provider deduplicates them and at most one provider object
    can exist.

A duplicate command key replays idempotently: the recorded command row is revalidated against the
authoritative aggregate (R1 obligation + R2 intent), against its terminal result row and against the
attempt that result names, before any idempotent outcome is reported — and the port is never invoked a
second time for the same command. A concurrent duplicate that loses the unique race recovers through
the recorded winner, never through a second outbound call; a replayed key on a still-unresolved
dispatch converges on that command and its claim and reports the pending state rather than dispatching
again. A port invocation that raises is recorded as an attempt with `unavailable` /
`provider_unavailable` and a `completed` result, and its claim is `settled` in that same transaction, so
a later retry is explicit, observable and a new command.

### 8.4 Events published

`do_action('dzn_phase_2a2t_after_execution_command', $commandId)` after commit and
`do_action('dzn_phase_2a2t_after_provider_event_decision', $eventId, $decisionId)` after a translation
decision commits. These are test/observability hooks, mirroring the R1/R2 hook convention; they carry
ids only and no provider payload. [C4-3] Both hooks fire **after** the worker execution context of
§9.7 has exited and after the commit, so no listener ever observes — or inherits — the worker
principal's authority. [C2-2] Every id passed to a hook is the declared `id` of the table it
names — `payment_execution_commands.id`, `payment_provider_events.id` and
`payment_provider_event_decisions.id` — so each hook argument is resolvable against a declared primary
identifier (§12.3) and is never a uid, digest or raw reference.
[C12-1] One further observability hook, `do_action('dzn_phase_2a2t_before_provider_event_decision_append',
$eventId, $claimGeneration)`, fires at the append seam of one decision operation: after every R1/R2 work
unit of §9.5 has finished and before the fenced `claimed → settled` transaction that publishes the
decision. It carries the declared `payment_provider_events.id` and the claim's own `claim_generation` —
never a token, payload or reference — and it fires outside any transaction and outside the §9.7 worker
context, so it can never observe the worker principal's authority or a half-open window. It exists so an
observer can prove the bounded window the operation ran inside is still *the owner's own* at the moment the
append is fenced, which is exactly what makes a lapsed window refuse the append instead of publishing
through it.
[C13-1] Because this seam exists precisely so an observer can hold the operation at its last bounded step,
the fence it precedes judges the claim's window at the instant its own statement runs — from one
database-time expression, never from an instant the intake read before this hook fires — so holding the
seam open past the lease refuses the append instead of settling the claim and publishing through it.

## 9. Webhook contract

### 9.1 Endpoint

```text
POST /wp-json/delnavazan-platform/v1/payment-provider-events/{provider}/{account}
```

Registered on `rest_api_init` from `StripeWebhookController::register()`, with
`permission_callback` that performs **no** WordPress authentication (the caller is a provider, not a
principal) and defers the entire trust decision to signature verification.

Provider segment: must be a `PROVIDERS` member; an unknown segment returns `404` with no body detail
about registered providers.

[C2-4] Account segment: the pre-parse account selector, because verification needs the matching
provider account, mode and signing secret and the body must not be read to find them.

- The selector is `payment_provider_accounts.reference_code`: a non-secret, stable, unique handle that
  identifies exactly one account row, and therefore exactly one `(provider_key, mode)` pair. It is a
  **selector, never a credential and never authority** — it authenticates nothing, grants nothing, and
  is recorded on the receipt only as a keyed digest (§9.3).
- The route is otherwise inert: every account is reached through the same handler, and the resolution
  is one indexed lookup on `UNIQUE reference_code`. A provider account registered for a provider whose
  adapter accepts webhooks must therefore carry a non-empty `reference_code` (the registration command
  records it and refuses an empty or duplicate value), and the adapter's operator-facing documentation
  gives each `(account, mode)` pair its own webhook URL.
- Resolution happens before the body is read, and fails closed with `404` and a durable refused receipt
  (§9.3) when the provider segment is unknown, the account segment is missing or empty, no account
  matches the pair, more than one account matches (defence in depth — the unique index makes this
  unreachable, and an ambiguous result is refused rather than arbitrated), or the resolved row is not
  `active` with a `MODES` member mode. The refusal reason is recorded (`webhook_account_unresolved` /
  `provider_account_inactive` / `unsupported_payment_provider`) but the response body carries no
  account, provider or mapping detail.
- So that **every** inbound request is receipted (§9.3) and not only those that match the
  account-scoped route, the controller registers the bare provider route as well
  (`…/payment-provider-events/{provider}`). That route never resolves an account, always refuses with
  `webhook_account_unresolved` and a durable receipt, and is never a fallback to a default account.
- [C8-1] Both routes are registered for **every** HTTP method (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`,
  `HEAD`, `OPTIONS`) through one locked method set. WordPress matches a route's declared methods before
  it reaches a callback, so a route registered for `POST` alone would answer a `GET`/`PUT`/`PATCH`/
  `DELETE` delivery with a routing-level error and receipt nothing — an unlogged inbound request. The
  method requirement is therefore decided *inside* the controlled handler (§9.2) and produces the same
  durable receipt as every other refusal; the endpoint remains one handler for every method, and a
  non-`POST` request never reaches account resolution, verification or translation.
- [C9-4] `OPTIONS` is the one registered method that no route registration can deliver to a callback:
  WordPress answers it in `rest_handle_options_request()`, which is itself a `rest_pre_dispatch` filter
  and therefore runs **before** `dispatch()` resolves a route and calls the endpoint's handler.
  `StripeWebhookController::register()` therefore also registers the endpoint's own `rest_pre_dispatch`
  interception (`StripeWebhookController::interceptOptions()`) at priority `1`, ahead of the core
  handler's `10`. The interception is scoped in both directions: it ignores every request that is not an
  `OPTIONS` delivery and returns every path outside this endpoint's two registered route shapes to the
  filter chain untouched (matched case-insensitively, with the trailing separator WordPress may have
  removed), so no other route's `OPTIONS` handling changes. A matched delivery is handed to the same
  controlled `process()` path a routed `GET`/`PUT`/`PATCH`/`DELETE` takes, so it is receipted and refused
  exactly like them: `405` `method_not_allowed` with the exact raw body (§9.2, §9.3). There is no second
  refusal implementation and no delivery for which the method can be judged twice.
- A resolved account never authorises anything by itself: acceptance still requires a valid signature
  over the raw body under **that** account's secret (§9.4), and a verified payload whose own provider
  account reference disagrees with the selected account is refused at translation with
  `unmapped_provider_account` (§10). Nothing in the response distinguishes "unknown provider" from
  "unknown account" from "unmapped object".

### 9.2 Request requirements (rejected before any storage)

| Requirement | Failure |
| --- | --- |
| method is exactly `POST` | `405` `method_not_allowed` |
| [C9-3] HTTPS request — direct TLS (`is_ssl()`), the local development environment, or a proxy header that the site is **configured** to trust and that is a member of the locked `HTTPS_PROXY_HEADERS` allowlist | `400` `https_required` |
| `Content-Type` is `application/json` | `400` `unsupported_content_type` |
| body is non-empty and `≤ MAX_WEBHOOK_BYTES` | `413` `payload_too_large` / `400` `empty_payload` |
| [C2-4] provider segment is a `PROVIDERS` member and the account segment resolves before parsing | `404` `unsupported_payment_provider` / `webhook_account_unresolved` / `provider_account_inactive` |
| no other route parameter or query argument changes the meaning | `400` `unexpected_request_shape` |

Every rejected request is receipted (§9.3) with `verification_state = refused` and a controlled
reason code. Nothing in the response distinguishes "unknown provider" from "unknown account" from
"unmapped object".

[C9-3] **The transport requirement is satisfied by exactly three things and nothing else.** A delivery
passes when the transport itself is TLS (`is_ssl()`), when the site runs in the local development
environment, or when a proxy header that the operator has declared this site trusts — through
`PaymentExecutionRule::TRUSTED_PROXY_OPTION`, the only configuration signal for "this site is behind a
TLS-terminating proxy" — names the client-facing scheme `https`. The declaration may only name a member
of the locked `HTTPS_PROXY_HEADERS` allowlist: an unset, empty, malformed or non-member configuration
trusts no header at all, and a header that is not trusted is ignored whatever it says, so a client can
never satisfy the requirement by sending `X-Forwarded-Proto` to a site that is not behind such a proxy.
The value is read as the proxy convention does — a comma-separated scheme chain whose leftmost element is
the original client scheme — and only a leftmost `https` counts; `http`, an empty value and any other
value are `https_required`. Header names are compared without their separators, because WordPress
normalises the same inbound header to `x-forwarded-proto` on the server path and to `x_forwarded_proto`
when a request is built in process; neither spelling is ever trusted on its own. The verdict is a pure
function of the direct-TLS flag, the environment type, the request's headers and the two locked constants,
so it is proved directly by the runtime suite (§17).

[C8-1] The requirements above are evaluated **before any parsing**, but never before the receipt: the
controller hands the exact raw bytes it received to `receive()` for *every* outcome, including a refusal
decided before parsing. A precheck refusal therefore records a real `request_digest` (`body_bytes` of the
bytes that actually arrived) and never a zero-byte or rewritten substitute for an oversized,
unsupported-content-type or otherwise refused request. These prechecks are also the only place the
method is judged: because the routes accept every method, a non-`POST` delivery is receipted with
`method_not_allowed` rather than dropped by routing. [C9-4] That single judgement covers `OPTIONS` too,
even though WordPress answers `OPTIONS` itself: the endpoint intercepts its own `OPTIONS` delivery ahead
of the core handler (§9.1) and routes it through the same precheck, receipt and response the routed
`GET`/`PUT`/`PATCH`/`DELETE` deliveries take, so a refused `OPTIONS` delivery records the same controlled
reason, the same `405` and the same exact raw body digest and byte count as any other method.

### 9.3 Durable receipt before acknowledgement

`payment_provider_event_receipts` records, for **every** inbound request that reaches the controller
(including refusals): a keyed digest of the exact raw body, a keyed digest of the signature header, the
signature key version when one is resolvable, the verification state, the refusal reason, a keyed
digest of the source address (never the address), the body byte count, and the receive instant.
[C8-1] "The exact raw body" is literal: the digest and the byte count are always computed over the bytes
the request actually carried, whatever reason refused it, so the audit trail can never describe a body
the provider did not send.
[C2-4] It also records the resolved `payment_provider_account_id` (NULL when no account resolved) and a
keyed digest of the account selector, never the selector itself. `created_by` is NULL: the origin is
unauthenticated and is never impersonated as a human principal.

### 9.4 Signature verification

- Verification is performed over the **exact raw request body bytes** as received, before any JSON
  decode, using the pre-parse scope resolution of §9.1. [C2-4] Verification receives exactly one
  `ProviderVerificationContext` — the resolved `payment_provider_accounts.id`, its `mode` and its active
  signing-secret `key_version` — and verifies with the provider-supported scheme and the one
  `webhook_signing_secret` stored for that exact `(provider_key, payment_provider_account_id, mode)`.
- [C2-4] **Exactly one secret is ever tried.** The adapter has no API to list accounts or secrets, no
  fallback to another account, another mode or a retired `key_version`, and no ordering over candidate
  secrets: a request that does not verify under the resolved scope's single active secret fails, even
  if another account on the same provider would have accepted it. A signature that carries an
  unsupported scheme is `unsupported_signature_scheme`; a missing, malformed, wrong-key-version or
  wrong-mode signature is `signature_invalid`; a retired secret is never used, so a request signed with
  one is `signature_invalid` (this build ships no dual-accept rotation window — see §11.5).
- Comparison is constant-time (`hash_equals`). If no active signing secret exists for the resolved
  account/mode, the request is refused with `webhook_secret_unconfigured` and HTTP `503` (fail closed,
  retryable). A missing, unknown, ambiguous, inactive or mode-mismatched selection never reaches
  verification at all; it is refused with the §9.1 reason codes.
- The signature timestamp must fall inside `SIGNATURE_TOLERANCE_SECONDS` of the receive instant, with
  the tolerance clamped to `TIMESTAMP_TOLERANCE_CLAMP_SECONDS`; a stale or future timestamp is
  `signature_outside_tolerance`. A replayed request is not refused for being old if the provider
  permits it — it converges on the recorded event (§9.5).
- The signing secret is never echoed, logged or returned; verification failure reporting contains only
  the controlled reason code and the key version.

### 9.5 Event identity and idempotency (T-D5)

- Identity: `(provider_key, event_reference_digest)` where the digest is keyed from the provider event
  id. `UNIQUE provider_event (provider_key, event_reference_digest)` arbitrates concurrent duplicates.
- [C8-3] The unique index — never a read — is the arbiter, so insert-or-resolve reports whether *this*
  worker created the event. Two workers that both observed no event can therefore both reach the insert;
  the loser adopts the winner's recorded event with `created = false` and enters the **same** convergence
  path a detected duplicate enters. It never translates, decides or appends an R1/R2 consequence of its
  own, so identical deliveries converge on one event and one decision (the single `pending`-consequence
  exception below) and a materially different duplicate is recorded as a conflict.
- `event_fact_digest` (keyed HMAC over the canonical immutable facts: provider key, event reference
  digest, normalised type, raw type digest, payload digest, provider occurrence instant, extracted
  amount/currency, obligation reference digest) is compared on every duplicate:
  - identical facts ⇒ converge idempotently; the recorded event and its decision are returned, and the
    response is `200` with no new decision row. [C2-3] The only exception is an event whose R2
    consequence (§10.1) is still `pending`: the duplicate delivery is allowed to append the *next*
    decision row that records the consequence's terminal state, and it never appends a row for a
    consequence that is already `applied`, `refused` or `not_applicable`;
  - materially different facts ⇒ preserve the original event unchanged, append a
    `conflicted` decision with reason `conflicting_provider_event`, record the R1 commercial
    exception with the same reason code, and still answer `200` (a duplicate the provider may
    legitimately retry) while never converging and never manufacturing authority.
- [C8-4] The recorded event is immutable and is the *only* thing a `drain()` may complete. A drain
  therefore recomputes the **full** `event_fact_digest` of the re-delivered envelope (not merely the
  event reference) and requires it to equal the recorded one before any decision is appended. A changed
  but validly signed payload for the same event id — the provider controls its own payload, so a valid
  signature proves origin, not immutability — takes the conflicting-duplicate path above: the original
  event is preserved unchanged, the controlled conflict decision is appended, the R1 commercial
  exception is recorded, and **no** evidence is submitted. A drain never translates facts the event
  never recorded.
- Storage happens before acknowledgement: `receipt → event → decision` are durable before a `200` is
  returned. If the decision cannot be attempted (for example the worker principal is unset, §9.7), the
  event stays durably `received` and a later drain completes it; the response is still `200` because
  the work is durably recorded, and the pending state is visible in the read model.
- [C9-1]/[C9-2] **A decision is appended by exactly one claimed worker, and an event that owes one is
  always completed.** Every path that may append a decision — the first decision of a newly recorded
  event, the first decision of an event that was recorded and then left owing one, the terminal
  consequence of a decision that is still `pending` or was refused for want of the §9.7 principal, a
  `drain()` and the controlled conflict decision — goes through the event's **durable decision claim**
  (`payment_provider_event_decision_claims`, §12.1). The worker must own the claim *before* it runs any
  translation or R1/R2 consequence; the claim's `claim_generation` and `claim_token_digest` fence the
  `claimed → settled` transition **inside the transaction that inserts the decision**, so the next
  `decision_sequence` is allocated under the claim row's lock and two deliveries can never derive the
  same one; and a delivery that cannot own the claim performs **no** decision or consequence work at all
  — it re-reads the decision the owner published, waits a bounded, structural window for it, and
  converges. The unique `event_claim` index, never a read, arbitrates two workers that both saw a
  decision owed, exactly as `UNIQUE provider_event` arbitrates two event inserts. An abandoned claim
  (its lease has expired) is taken over by exactly one conditional statement that issues a new fencing
  generation and token, so a worker that died holding a claim never strands the event. `convergeExisting()`
  therefore never reports an event as merely `recorded` while it owes a decision: no decision at all is
  an owed decision, and a redelivery completes it.
- [C10-2] **Ownership covers the whole decision operation, not just the row the decision is appended
  through.** The claim's lease is the *bounded window the owner works inside*: the worker opens that
  window (the lease issued with its generation and token) before any decision work runs, re-proves and
  renews it at every work-unit boundary — immediately before the R1 evidence submission and immediately
  before every R2 command, each of which is one local transaction and never a provider call — and closes
  it when the decision is published. A renewal is one fenced conditional statement that requires the
  worker's own live generation **and** an unexpired lease, and it can never resurrect an expired window.
  A generation whose window has closed — its lease lapsed before the next unit began, or exactly one
  successor generation took the claim over — therefore stops *before* that unit, performs no further
  decision or consequence work, appends nothing, and converges on the decision the current owner
  publishes. Ownership is never asserted from memory or from a read: every work unit is preceded by the
  affected-row proof that this generation still owns the event and that its window still covers the work
  about to run, and the decision append stays fenced by the same generation and token inside the
  transaction that inserts it. [C12-1] The append is the *last* step that same window covers, so closing
  the window is part of the append's own fence and not a step before it: the `claimed → settled`
  transition requires the owner's live slot and an unexpired lease **as well as** its generation and
  token, all judged against the instant the append runs, so a window that lapsed after the final work
  unit settles nothing here — the same zero-row outcome a replaced generation produces. Because the
  zero-row outcome is a closed window, the owner does not merely converge: it releases the live claim it
  appended nothing to (the release is fenced by its own generation and token, so a successor's claim is
  never touched) and *then* converges, so an expired-but-not-yet-taken-over claim never strands the event
  behind a lease nobody is working inside and the next delivery completes it.
  [C13-1] "Judged against the instant the append runs" is enforced, not merely intended: the transition
  takes its verdict from one **database-time** expression inside its own statement — `lease_expires_at >=
  UTC_TIMESTAMP()`, with `settled_at`/`updated_at` stamped from that same expression — and it accepts no
  instant parameter at all, so a hook callback on the append seam, or any other delay before the statement
  acquires the claim row's lock, changes the verdict itself instead of being compared against an instant
  read before the seam.
  [C14-1] "Judged after the append acquires its row lock" is enforced as well, because a database-time
  expression is evaluated **once, when its statement starts**: a single conditional update that had to wait
  for another transaction's lock of the claim row would judge the window with the instant it began waiting.
  The transition therefore takes that row's lock with its own fenced `SELECT … FOR UPDATE` — where a wait
  decides nothing — and runs the conditional update only once the row is held, so an append that was queued
  behind another transaction's lock is judged *after* that wait, never with the instant the waiting statement
  began with.
- [C11-1] **Ownership also covers the duration of every work unit, not only its entry.** The window is
  proved — and, outside a transaction, renewed first — *inside the unit's own transaction*, at the
  connection's statement boundary (`DECISION_UNIT_FENCE_FILTER`), before every statement that transaction
  runs: `START TRANSACTION`, `ROLLBACK` and session `SET` statements pass through untouched, every other
  statement (the unit transaction's own `COMMIT` included) is preceded by one locking read of the claim
  row that must return exactly one row — the worker's own `claim_generation` and `claim_token_digest`,
  `claim_state = 'claimed'`, its live slot and an unexpired lease — and a read that returns none raises
  the controlled closed-window stop *before* that statement executes. Two consequences are load-bearing:
  the locking read holds the claim row for the rest of the unit's transaction, so a take-over — which
  needs the same row and an expired lease — can never interleave with a unit; and a unit whose window
  closed mid-transaction is rolled back by its own R1/R2 service, so the stale generation commits **no**
  statement of that unit instead of completing a mutation it had already started and only then noticing
  the loss. The stale generation releases the live claim it appended nothing to and converges; the
  event's decision is completed by the next generation.
- [C9-1] **A conflict record is not the event's decision.** A `conflicting_provider_event` row records
  that a *different* delivery carried materially different facts; it never discharges or replaces the
  event's own decision. Whether an event still owes a decision is therefore asked of its non-conflict
  decision timeline, so a conflicting duplicate can neither suppress the owed first decision nor turn a
  deferred one into "decided".

### 9.6 Processing state, ordering and regression safety

- A verified event is parsed into a normalised `ProviderEventEnvelope`; the raw payload is **not**
  stored (only `payload_digest` and `raw_type_digest`).
- Decisions are derived from the provider occurrence instant, then by decision sequence; the receive
  order is never authority. An event whose occurrence precedes an already-recorded authoritative
  outcome for the same obligation is recorded as `ignored` with reason `stale_provider_event` and can
  never change settlement, funding, capacity, cycle state or collection-intent state.
- A `payment_failed` decision never unsets a settlement; the R1 acceptance boundary refuses a
  conflicting success and Phase T never deletes, rewrites or reverses an accepted evidence row.
- No provider event can create, mutate or delete a Term, Enrolment, Lesson, schedule, attendance
  outcome, academy obligation, protected claim, funding plan or notification record (T-D10).

### 9.7 Execution principal (bounded system actor)

Translation runs under one explicitly provisioned, non-human service principal recorded in the
`dzn_platform_payment_worker_principal` option, because the R1/R2 consequence boundaries require a
named actor and refuse an anonymous one.

- [C2-3] The principal must exist, be active, and hold **exactly** these four capabilities — no more
  and no fewer:

  | Capability | Needed for |
  | --- | --- |
  | `dzn_ingest_payment_provider_events` | the translation decision and the operator drain surface (the pre-parse receipt, verification and event-identity steps are authorised by the verified signature itself, §13) |
  | `dzn_ingest_commercial_payment_evidence` | the R1 acceptance boundary (`CommercialPaymentService::ingest()`) that alone settles |
  | `dzn_manage_collection_intents` | the R2 collection-intent confirmation of §10.1 |
  | `dzn_manage_renewal_cycles` | the R2 renewal-cycle confirmation of §10.1 (`RenewalCycleService::confirmCollection()` requires this capability; without it a successful renewal would settle R1 and leave the cycle in `payment_required`, blocking the `bind_next_term` path) |

  The principal must **not** hold `manage_options`, `dzn_manage_payment_execution`,
  `dzn_manage_payment_providers` or any other administrative capability. The contract test asserts the
  check exists and names all four capabilities; the runtime test asserts a principal missing any one of
  the four is refused with `payment_worker_principal_required`, and that a principal holding an extra
  administrative capability is refused the same way. The principal is provisioned by operations and is
  never created, escalated or widened by Phase T code.
- While the option is unset or invalid, the translation step refuses, records the refusal decision and
  leaves the event durably `received` for a later drain. The endpoint itself never establishes an
  administrator and never raises the current user's authority: outside the context window of [C4-3]
  below an anonymous request still has no user at all, and inside it the current user is only ever the
  exactly-four-capability service principal.
- A re-delivery of a `received` event converges on the same event identity and completes the pending
  decision exactly once; the drain entry point is idempotent and is safe to run repeatedly.
  [C9-1]/[C9-2] "The pending decision" includes an event that has **no** decision row at all (a worker
  that committed the event and then died): the redelivery or drain completes it through the event's
  decision claim, and a drain that races a webhook redelivery appends one decision, the loser converging
  on it (§9.5).
  [C8-4] "Completes the pending decision" is bounded by the recorded facts: the drain re-supplies a body
  and must prove the **full** recorded `event_fact_digest` from it (§9.5) before it may append the owed
  decision. A body that matches only the event reference is refused as a conflicting duplicate, so a
  drain can never be used to translate a payload the recorded event did not carry.

[C4-3] **Scoped, restored worker execution context.** The principal is not merely recorded — it is
adopted, because the R1/R2 boundaries it must satisfy authorise through `current_user_can()` and record
`get_current_user_id()`, and an anonymous webhook request supplies neither. `PaymentExecutionWorkerContext::run(callable $work)`
is the **only** place in Phase T that sets a WordPress current user, and it does so bounded, proven and
restored:

1. It resolves and validates the principal from `dzn_platform_payment_worker_principal` against the
   exact-capability rule above — refusing with `payment_worker_principal_required` when the option is
   unset, the user is missing or inactive, any one of the four capabilities is absent, or any
   administrative capability is present — **before** any identity change happens.
2. It captures the current user id (`$previous`) from the caller's own context.
3. It sets the principal with `wp_set_current_user((int) $principalId)`: the option value and nothing
   else. The context accepts no identity from a request, header, payload, query argument or caller.
4. It proves the identity is effective before running any work: `get_current_user_id()` equals the
   principal id **and** `current_user_can()` is true for each of the four capabilities, so the context
   can never report success while the boundary it is establishing would still refuse the call.
5. It runs `$work` inside a `try` and restores `wp_set_current_user($previous)` in the matching
   `finally`, on every path including a thrown exception, so the caller's identity is always exactly the
   one it started with — `0` for the anonymous webhook path, the administrator for an admin-triggered
   drain.

The context is entered by exactly one surface: the translation/consequence step of
`PaymentEventIntakeService::receive()`/`drain()`, including the anonymous REST controller's inline
translation after signature verification. The receipt, verification and event-identity step of
§9.3–§9.5 runs **without** it, so a receipt's `created_by` stays NULL and the origin is still never
impersonated as a human principal. It is deliberately **not** used by the execution surface:
`PaymentExecutionService::submitCollection`/`cancelCollection`/`reconcileCollection`/`redrive` keep
their own actor rule (an authenticated caller holding `dzn_manage_payment_execution`), the §9.7
principal never holds that capability, and this context can therefore never be used to satisfy it.
The two bounded identities never merge, in either direction.

It is bounded, non-escalating and non-inheritable: it never sets user `1`, never adds a role or a
capability, never mutates the option, never widens the principal, and never leaves the current user
changed outside its callback. It is not re-entrant — an entry attempt while a Phase T worker context is
already active is an integrity fault, refused before any identity change, so one restoration always
belongs to exactly one entry. The §8.4 hooks fire after the context has exited and after commit, the
REST response is produced after it has exited, and every admin screen, read model, diagnostic and
export runs outside it. R1/R2 keep their existing `current_user_can()`/`get_current_user_id()` checks
unchanged: Phase T adds no bypass, no filter, no "system actor" parameter and no R1/R2 code change
(T-D6, §15).

### 9.8 Context and noise control

The controller raises the REST request size limit only if the platform's own limit is below
`MAX_WEBHOOK_BYTES` and answers provider retries with the provider-appropriate success code for an
already-processed duplicate. [C9-4] Its only `rest_pre_dispatch` participation is the `OPTIONS`
interception of §9.1: an `OPTIONS` delivery to this endpoint's own routes, refused `method_not_allowed`
and receipted exactly like any other non-`POST` delivery. That participation never reads, rewrites or
re-decides a `POST` delivery, so a `POST` is still verified against — and only against — the bytes it
carried: no filter, this endpoint's own included, may change the verified body. Rate limiting is **not**
applied to the webhook path: signature verification is the control, and a rate limit would discard
legitimately retried provider traffic. A burst of unverified requests is observable through the receipt
refusal counters, not through suppression.

## 10. Event → authority mapping

The adapter normalises provider events into the following vocabulary. The translation never writes
commercial storage; it submits provider-neutral input to R1 through the existing service interface and
uses the R1 result to record its own decision.

[C4-1] The `provider_reference`, `obligation_reference` and `provider_account_reference` columns below
are the **inbound** evidence inputs of the R1 `ingest()` boundary, translated from an
already-verified provider event and digested by R1 exactly as they are today. They are not execution
request fields: an execution request carries no raw reference at all (§5.3), and the two boundaries
never share reference material — the same event supplies no input to a dispatch, and no descriptor
travels inbound.

| Normalised event | Recognised provider events (current Stripe mapping) | R1 evidence submitted | R1 `evidence_kind` | R2 consequence | Never |
| --- | --- | --- | --- | --- | --- |
| `payment_succeeded` | `payment_intent.succeeded`, `charge.succeeded`, `invoice.paid` | `provider_reference`, exact `amount_minor` + `currency`, `obligation_reference`, `provider_account_reference`, `provider_occurred_at`, `evidence_channel = provider_evidence` | `success` | on accepted evidence, the bounded ordered R2 consequence of §10.1 confirms the collection intent **and** the renewal cycle | never a settlement Phase T created; never a funding plan, entitlement, claim or Term |
| `payment_failed` | `payment_intent.payment_failed`, `charge.failed`, `invoice.payment_failed` | same inputs, with the provider-reported amount/currency when present | `failure` | R2 `record_failure` for the exact intent; recovery remains representation-only | never a state regression; never a lapse while `PAYMENT_RECOVERY_POLICY` is unset |
| `payment_requires_action` | `payment_intent.processing`, `payment_intent.requires_action` | same inputs, amount/currency optional | `attempt` | none | never treated as a decline or a success |
| `refund_recorded` | `charge.refunded`, `refund.created`, `refund.updated` | provider reference, exact refunded `amount_minor` + `currency`, `obligation_reference` | `refund` | R2 refund/reversal review case (`academic_consequence` stays NULL) | never an executed refund, never a clawback of funded sessions |
| `mandate_recorded` | `setup_intent.succeeded`, `payment_method.attached`, `mandate.updated` | provider reference, optional amount/currency (usually none) | `mandate` | mapping registry may link the `payment_method`/`mandate` object | never a settlement; never an automatic-renewal opt-in |
| `provider_recurring_semantics_unresolved` | `customer.subscription.*`, `invoice.upcoming`, `invoice.created` | none | — | none | recorded as `refused`/`ignored`; the provider recurring model stays an explicit owner decision (T-D9) |
| `unrecognised_provider_event` | any verified event outside the mapping | none | — | none | recorded as `refused` with `unrecognised_provider_event` |

Translation rules that carry the R1/R2 invariants:

1. **Attribution is exact or refused.** The envelope's obligation reference must resolve to exactly one
   R1 obligation (through the recorded mapping and the provider's own referenced object). Zero or
   multiple candidates are refused with `ambiguous_obligation_attribution`; the evidence is **not**
   submitted with a guessed obligation. A verified success that cannot be attributed is preserved by
   R1's existing unattributed path only when the adapter can supply the provider reference without an
   obligation reference; R1's `unmatched_payment_evidence` routing then applies unchanged.
   [C8-2] The mapping is not decoration and never a historical row: the event's own provider object must
   carry **exactly one active mapping** (`active_slot = 1` with `state = linked`), and the canonical
   obligation that mapping already owns — `canonical_id` for a `canonical_kind = obligation` mapping, the
   mapped collection intent's own `obligation_id` for a `canonical_kind = collection_intent` mapping —
   must equal the obligation the event resolved. An absent or historical (`superseded`/`detached`)
   mapping is refused `unmapped_provider_object`; more than one active candidate, a mapping owning a
   *different* obligation, and a mapping owning no obligation while the event names one are each refused
   `ambiguous_obligation_attribution` with no evidence submitted. A signed event for an object linked to
   one canonical row can therefore never submit evidence for another obligation named in metadata. An
   event that names no obligation and an object whose mapping owns none are compatible — neither claims
   an attribution — and R1's own routing decides.
2. **Amounts are compared, never adopted.** The adapter passes the provider-observed amount and
   currency; R1 compares them to the obligation and records `amount_mismatch` / `currency_mismatch`
   rejections. The adapter must not filter such events out, must not round, must not convert currency
   and must not treat a zero-decimal currency as a two-decimal one (an unsupported minor-unit
   convention is refused with `currency_minor_unit_unsupported` and recorded).
3. **Evidence channel is always `provider_evidence`**, `evidence_at` is the provider occurrence
   instant (never the receive instant), and `provider_key` is the controlled vocabulary member.
4. **One event, one R1 call.** A retried decision submits the same immutable facts and therefore
   converges on the recorded R1 evidence; a materially different fact set is preserved by R1 and
   routed as a conflict, exactly as §9.5 requires.
5. **Payment method neutrality.** Card, `iDEAL | Wero`, SEPA Direct Debit and wallet flows are
   normalised to a provider-neutral `method_family` (`card`, `bank_redirect`, `direct_debit`, `wallet`,
   `other`) inside the adapter only; that family is never stored as authority, never used for pricing,
   and never selects a provider behaviour. The live academy currently offers Card, `iDEAL | Wero` and
   SEPA Direct Debit on its Enrolment Fee payment link; Phase T records facts about them and decides
   nothing about them.

### 10.1 R2 consequence — ordered, idempotent and never stranded ([C2-3])

An accepted `payment_succeeded` settles the obligation through R1 only. The renewal consequence that
consumes that settlement is a **bounded, ordered pair of delegated R2 commands**, run in this order,
each with a deterministic command key derived from the decision so any re-drive converges:

| Order | Delegated command | Required pre-state | Terminal state | Notes |
| --- | --- | --- | --- | --- |
| 1 | `CollectionIntentService::confirm($intentId, …)` | intent `submitted` for this exact obligation | intent `confirmed` | R2 itself re-proves accepted R1 settlement (`RecurringSupport::settlementReason()`); an already-`confirmed` intent converges |
| 2 | `RenewalCycleService::confirmCollection($cycleId, …)` | cycle `payment_required` | cycle `collected` | R2 itself re-proves the cycle's obligation is settled; an already-`collected` or `term_bound` cycle converges |

Step outcomes are a closed mapping — never a guess, never a silent success:

| Recorded pre-state | Action | Consequence state | Reason |
| --- | --- | --- | --- |
| intent `confirmed` | converge, do not re-issue | continue to step 2 | — |
| intent `submitted` | delegate `confirm()` | `applied` when step 2 is satisfied | — |
| intent `pending` | not attemptable yet; stop, leave the event durably visible | `pending` | `collection_intent_not_submitted` |
| intent `failed` / `recovered` / `cancelled` | refuse; step 2 is not attempted | `refused` | `collection_intent_not_submitted` |
| cycle `collected` / `term_bound` | converge, do not re-issue | `applied` (both steps satisfied) | — |
| cycle `payment_required` | delegate `confirmCollection()` | `applied` on success | — |
| cycle `pending` / `guarantee_protected` / `lapsed` / `cancelled` / `closed` | refuse; the cycle is never advanced by Phase T | `refused` | `renewal_cycle_not_collectable` |
| delegated step refused by R2 for its own reason | preserve R2's exact reason; never retried into a success | `refused` | `obligation_not_settled` / `accepted_payment_evidence_required` |

Rules that make the pair bounded, idempotent and non-stranding:

1. **Both steps, in this order, from one decision unit.** The consequence runs under the worker
   principal of §9.7 through the scoped, restored execution context of [C4-3] — so the R1 `ingest()`
   call and both delegated R2 commands execute while that bounded identity is the current user — and
   only after the R1 ingest transaction has committed. Each delegated R2 command
   takes the R1 `commercial_account_roots` lock for the beneficiary first, exactly as R2's own command
   contract requires — the fixed lock order is inherited, never re-implemented and never inverted — and
   the decision unit issues step 2 immediately after step 1 commits. The window between the two steps
   is therefore bounded and cannot be exploited: the only transitions R2 still permits from the
   post-step-1 states are idempotent repeats, so a racing writer either converges or records its own
   reasoned refusal. Step 2 is never skipped while the cycle is collectable, because step 2 is the
   state that unblocks the required `bind_next_term` path: a renewal may not end with a confirmed
   intent and a cycle still sitting in `payment_required`.
2. **Idempotency is delegated, not invented.** Each step's key is
   `dzn_phase_2a2t_r2_consequence:<decision uid>:<step>`, so a repeated delivery or a repeated `drain()`
   re-issues the identical R2 command and converges on R2's own recorded result. A step whose recorded
   terminal state is already satisfied is never re-issued; the consequence reads the recorded states and
   resumes at the first unsatisfied step.
3. **The consequence is recorded on the decision row.** `payment_provider_event_decisions` records the
   intent id/state, the cycle id/state and `r2_consequence_state` ∈ `R2_CONSEQUENCE_STATES`. The state is
   `not_applicable` when the event has no R2 subject (a non-recurring R1 payment: no collection intent
   for that obligation); `applied` when both steps are satisfied (step 1 `confirmed` and step 2
   `collected`/`term_bound`); `pending` when the mapping above says a step is not yet attemptable, with
   the event left durably visible for the next drain or delivery; and `refused` with the exact reason
   from the mapping. A `pending` consequence is never reported as a success and never leaves the event
   invisible, and every step outcome is recorded with the R2 state it actually observed.
4. **Not collectable is refused, never invented.** When step 2 finds the cycle outside
   `payment_required`/`collected`/`term_bound` (for example still `guarantee_protected`, or already
   `lapsed`/`cancelled`/`closed`), the consequence records `refused` with
   `renewal_cycle_not_collectable`. A step-1 intent confirmation that already committed is left exactly
   as R2 recorded it — Phase T never reverses an R2 transition it legitimately issued — and the cycle
   keeps its own state for an operator or R2's own command surface to resolve. Phase T never advances
   the cycle itself, never calls `requirePayment`, never lapses, recovers or re-opens a cycle, and never
   touches a Term, protection or funding plan. The refusal is operator-visible in the read model and in
   `commercial_exceptions` (§13), so the event is never silently stranded.
5. **No new R2 authority.** Phase T adds no R2 command, table, policy or state. It calls the two
   existing R2 commands with the existing evidence channel (`provider_evidence`), an evidence reference
   of `dzn_phase_2a2t_r2_consequence:<decision uid>` and the provider occurrence instant as
   `evidence_at`; it never writes an R2 table directly, and R2's own validators refuse every unauthorised
   transition.

## 11. Secret isolation

### 11.1 Storage

Provider secrets live only in `dzn_payment_provider_secrets` as `ciphertext` + `nonce` +
`cipher_version` + `key_version`, scoped by `(provider_key, payment_provider_account_id,
secret_class, mode)`, with exactly one active row per scope (`active_slot = 1`; historical rows carry
`NULL`). No plaintext column, option, transient, object-cache entry, log line, notice, export, outbox
row, exception message or diagnostic may contain a secret value.

[C2-6] `payment_provider_account_id` is **`bigint unsigned NOT NULL`** — there is no unscoped secret
and no NULL sentinel. Every secret belongs to exactly one resolved provider account, so a secret can
never be verified or rotated against an ambiguous scope, and the composite unique key
`secret_slot(provider_key, secret_class, payment_provider_account_id, mode, active_slot)` is a real
invariant rather than one MySQL's NULL semantics can defeat. (A scope that genuinely needs no account
is a different secret class with its own declared columns and its own migration, never a NULL account
id on this table.) The single-active-row invariant is enforced by that index under the account row
lock taken by the write path, and is re-verified (§12.4) after every write and by the runtime suite.

### 11.2 Encryption

- Algorithm: `sodium_crypto_secretbox` (`CIPHER = sodium_secretbox_v1`), with a 24-byte random nonce
  generated per write by `random_bytes()`, stored with the ciphertext.
- Key derivation: `hash_hmac('sha256', 'dzn_payment_secret_vault:' . $secretClass . ':' . $keyVersion,
  wp_salt('dzn_payment_secret_vault'), true)` — domain separation per secret class, a recorded
  `key_version`, and no reuse of the commercial/recurring salt domains.
- Fail closed: if `sodium_crypto_secretbox` is unavailable, if the salt is missing, if the cipher
  version or key version is unknown, or if authentication fails, `reveal()` returns
  `payment_secret_unavailable` and records a `decrypt_failed` audit row. It never returns a partial,
  guessed or re-derived value.

### 11.3 Access control

- `reveal()` requires an explicit `secretClass` + scope and an `adapter_scope` argument naming the
  adapter; only an adapter registered for that `provider_key` may decrypt its own class. Core
  services, read models, admin screens, REST responses and the outbox cannot decrypt.
- Storing, rotating, retiring or revoking a secret requires `dzn_manage_payment_providers` (an
  administrative capability) plus an intent-specific nonce on the admin surface, and writes an
  append-only `payment_provider_secret_events` row.
- [C2-5] **Capability and nonce are necessary but not sufficient.** Every write path
  (`store`, `rotate`, `retire`, `revoke`) first refuses, before any capability check, any
  `provider_key` that is not a `PROVISIONABLE_PROVIDERS` member: the refusal is
  `provider_secret_write_not_authorised`, it records a `write_refused` audit row
  (`payment_provider_secret_events`) and it stores nothing. Because `PROVISIONABLE_PROVIDERS` is empty
  in this build, **no caller — however privileged, however nonced — can write a Stripe credential, a
  Stripe signing secret or any other provider secret.** The refusal is a locked constant comparison, not
  a policy check, a filter, an option or an environment variable, and it cannot be reached around by
  choosing a different `secret_class`, `mode` or account state.
- `payment_provider_accounts.credential_state` and `credential_key_version` are the only credential
  facts exposed anywhere else; they exist so operations can see `unconfigured`/`configured`/`invalid`
  without ever seeing a value.

### 11.4 Redaction and observability

- Every diagnostic, admin notice, exception message, provider-error description and export passes
  through one redaction helper that removes the active secret values, any signature header, any
  `Authorization` header and any provider key-shaped token, replacing them with `[REDACTED_SECRET]`.
- An adapter must never place a secret in a WordPress filter return value that another module could
  persist; the outbound request is assembled inside the adapter and discarded.
- The secret vault audit records only: audit type, provider key, secret class, account id, mode, key
  version, command key digest, reason code and instants.

### 11.5 Provisioning is deliberately absent

This phase ships the vault, the encryption, the rotation/retire lifecycle, the audit and the
fail-closed behaviour — and **no path that can write a Stripe credential**: no admin form, no WP-CLI
command, no REST route, no importer and no default.

[C2-5] That claim was previously supported only by the absence of a *surface*; it is now a property of
the storage function itself, so an authorized caller cannot write a Stripe secret through the vault
either:

1. `PaymentSecretVault::store`/`rotate`/`retire`/`revoke` refuse every `provider_key` outside the
   locked `PROVISIONABLE_PROVIDERS` constant (§11.3). `PROVISIONABLE_PROVIDERS` is `array()` in this
   build, so **every** provider secret write — Stripe `api_key`, Stripe `webhook_signing_secret`, or
   any future class — is refused with `provider_secret_write_not_authorised` and an auditable
   `write_refused` row. Nothing about the calling principal, the nonce, the mode, the account state or
   the secret class changes that outcome, and no filter, option, transient, REST route, WP-CLI command
   or admin screen can add a member to the constant.
2. The only storage path that exists in the build is the ephemeral test vault selected by the test-only
   constant gate (`dzn_payment_secret_vault_override`, resolvable only while
   `DZN_PLATFORM_PAYMENT_TEST_VAULT` is defined). That override is a compile-time constant check, not a
   runtime setting: with the constant undefined the override is unresolvable, and the test vault writes
   only to the disposable runtime's own database so that no real credential is ever created, read or
   committed. The vault records `test_vault_override_active` on every row written under the gate, so a
   test vault can never be mistaken for a provisioned production secret.
3. Consequently `StripePaymentAdapter` always resolves `provider_credentials_unconfigured` in this
   build (§5.4, §6.3), no Stripe signature can ever verify (§9.4 always answers
   `webhook_secret_unconfigured`), and the absence of live calls and of verified webhooks is a property
   of the code rather than of an operator's restraint. §17 proves it from both directions: the
   production write path rejects every provider secret write, and the test vault is unreachable while
   the constant is undefined.

### 11.6 The sealed dispatch descriptor is not a credential path ([C4-1])

The sealed provider dispatch descriptor of §5.3 is protected *reference* material, not a credential,
and it is deliberately not the vault: it has its own sealing helper, its own key domain and its own
locked payload.

- **Own mechanism, own domain.** `PaymentExecutionDispatchSeal` seals and opens the envelope with the
  same authenticated-cipher family as §11.2 (`sodium_crypto_secretbox`, a 24-byte random nonce per
  write, recorded `cipher_version` and `key_version`), but under its own domain-separated key
  `hash_hmac('sha256', 'dzn_payment_secret_vault:' . DISPATCH_DESCRIPTOR_DOMAIN . ':' . $keyVersion,
  wp_salt('dzn_payment_secret_vault'), true)`. It is not `PaymentSecretVault::store()`, and it cannot be
  reached through `store`, `rotate`, `retire`, `revoke` or `reveal`.
- **Not a credential store.** The sealed plaintext must be exactly `DISPATCH_DESCRIPTOR_FIELDS` and
  nothing else: the adapter refuses to seal an unknown field, a nested structure outside the locked
  provider-object map, or a value that is not a string or an integer. The envelope therefore cannot hold
  an API key, a signing secret or arbitrary configuration, and sealing can never become a
  general-purpose write. §11.3/§11.5 are unchanged: `PROVISIONABLE_PROVIDERS` stays empty, so **no**
  write path — this one included — can store a Stripe credential, a Stripe signing secret or any other
  provider secret.
- **Bound to exactly one command ([C5-3]).** The sealed payload carries the binding pair
  `command_key_digest` and `idempotency_key_digest`. Both are keyed digests that already exist on the
  command and dispatch rows, so the pair adds no raw reference, no identifier and no secret to the
  envelope — it makes the envelope provably unusable anywhere but the one command it was sealed for.
  `sodium_crypto_secretbox` authenticates the ciphertext but offers no additional-authenticated-data
  channel, so the binding rides *inside* the authenticated payload: the open that proves it must compare
  both sealed values against the command and the claim the call is being made for, and a mismatch is
  refused with `dispatch_descriptor_unavailable` and never re-derived. [C7-2] That comparison happens
  exactly once, in the pre-call preflight that alone opens an envelope — never inside a provider call —
  and it is corroborated by Core's own locked comparison of the verdict's reported digests against the
  command and claim rows ([C7-1]) before any lease is taken. A descriptor sealed for one command, or
  moved onto another claim, therefore fails closed
  (§5.3, §8.3, §17), and transplant detection needs no construction outside the locked field set.
  [C6-1] The binding is proven on the dispatch path by the adapter's **non-mutating
  `preflightDispatchDescriptor()`** while the claim is still `claimed`, before any lease is acquired
  (§8.3 step 2) — so the check that an envelope belongs to exactly this one command happens *before* the
  claim can become `in_flight`, never only after it. [C7-1] The claim half of the pair is an explicit
  input to that preflight (the live claim row's `idempotency_key_digest`, read by Core and passed in) and
  Core re-proves both digests against the durable command and claim rows under the claim lock immediately
  before the acquisition, so an `ok` verdict names the actual claim — not merely a self-consistent
  envelope — and an otherwise valid envelope sealed for another claim is refused, never dispatched.
- **Adapter-scoped and one-way.** Only the adapter that sealed an envelope may open it; Core transports
  the descriptor as an opaque handle and has no decrypt API. A failed authentication, an unknown cipher
  or key version, a missing salt, or a sealed field set that is not exactly the locked list is refused
  with `dispatch_descriptor_unavailable` (§6.4) and is never re-derived, guessed or partially returned.
  [C6-1] The adapter exposes that open as the non-mutating `preflightDispatchDescriptor()` verdict, whose
  only use is the pre-call decision of §8.3 step 2: an `ok` verdict is required before the lease may be
  acquired, and any other verdict forbids the `claimed → in_flight` acquisition and sends the claim down
  the fenced `claimed → released` refusal path instead. [C7-2] It is also the **only** open: an `ok`
  verdict carries the adapter-owned, opaque, one-use `ProviderDispatchCapability` that the single
  following invocation consumes, and `submit`/`cancel`/`reconcile` never open, re-open, re-decrypt or
  re-validate an envelope. The capability holds no core-readable plaintext, is never persisted,
  serialised, logged, exported or re-derived, and a capability the adapter cannot consume makes no
  outbound request — it reports `not_attempted` / `dispatch_descriptor_unavailable` and is then either
  the fenced generation-1 no-call abort or, on a takeover generation, the ordinary `in_flight` pending
  state (§6.4, §8.3).
- **Storage and observability.** The envelope lives only on the dispatch claim (`cipher_version`,
  `key_version`, `nonce`, `ciphertext` and the keyed `descriptor_digest`, §12.1). It is never copied to
  an option, transient, object-cache entry, outbox row, log, notice, exception message, export or
  diagnostic, and the §11.4 redaction helper applies to every adapter error path that could carry one.
  Diagnostics report the claim's state, generation and age only — never an envelope, nonce, key version,
  digest or raw reference.

## 12. Schema 028/029 data model and migration

The phase's declared table set is the fifteen seam tables of migration `028` plus the per-event
decision-claim aggregate of migration `029` ([C10-1]): where the text below says "Schema 028" of a table
that is not declared here as belonging to `029`, it means the phase's seam storage, and the
`payment_provider_event_decision_claims` table is created and verified by `029` alone (§12.4).

Additive only. No backfill, no inferred mapping, no provider call, no `ALTER` of any existing table,
no foreign key and no CHECK constraint (the repository relies on application/verifier enforcement).
All tables use `ENGINE=InnoDB $c` and the `dbDelta` + `throw new \RuntimeException('Migration
operation failed: ' . $wpdb->last_error)` convention. `$p = $wpdb->prefix . 'dzn_'`.

### 12.1 Mutable aggregates

`payment_provider_accounts`

```text
id bigint unsigned, uid char(26) NOT NULL, reference_code varchar(32) NULL,
provider_key varchar(32) NOT NULL, mode varchar(8) NOT NULL,
account_reference_digest char(64) NOT NULL, state varchar(16) NOT NULL,
execution_state varchar(16) NOT NULL, credential_state varchar(16) NOT NULL,
credential_key_version varchar(32) NULL, account_version int unsigned NOT NULL,
created_at, updated_at, created_by NULL, updated_by NULL
PRIMARY KEY(id), UNIQUE uid(uid), UNIQUE reference_code(reference_code),
UNIQUE provider_account(provider_key, account_reference_digest, mode),
KEY provider_state(provider_key, state)
```

`payment_provider_account_events` (append-only):

```text
id bigint unsigned, uid char(26) NOT NULL, payment_provider_account_id bigint unsigned NOT NULL,
event_sequence int unsigned NOT NULL, event_type varchar(48) NOT NULL, from_state varchar(16) NULL,
to_state varchar(16) NOT NULL, from_execution_state varchar(16) NULL,
to_execution_state varchar(16) NOT NULL, reason_code varchar(64) NOT NULL,
evidence_channel varchar(32) NOT NULL, evidence_reference_digest char(64) NOT NULL,
evidence_at datetime NOT NULL, occurred_at datetime NOT NULL, recorded_at datetime NOT NULL,
recorded_by bigint unsigned NOT NULL, created_at datetime NOT NULL, created_by bigint unsigned NOT NULL
PRIMARY KEY(id), UNIQUE uid(uid), UNIQUE account_sequence(payment_provider_account_id, event_sequence)
```

`payment_provider_account_commands` (digest-only, immutable):

```text
id bigint unsigned, uid char(26) NOT NULL, command_domain varchar(48) NOT NULL,
operation varchar(48) NOT NULL, command_key_digest char(64) NOT NULL,
command_payload_digest char(64) NOT NULL, payment_provider_account_id bigint unsigned NOT NULL,
result_state varchar(16) NOT NULL, result_id bigint unsigned NOT NULL, created_at datetime NOT NULL,
created_by bigint unsigned NOT NULL
PRIMARY KEY(id), UNIQUE uid(uid), UNIQUE command_key_digest(command_key_digest),
KEY provider_account(payment_provider_account_id), KEY result_id(result_id)
```

[C2-2] Both rows are written **in the same transaction** as the account state change they record, so the
digest-only command's `result_state`/`result_id` are known at insert and the row is immutable
afterwards. This is the R1/R2 convention and it is safe because nothing external happens between the
decision and the write; the **execution** command is the deliberate exception, because its result is
produced by a later transaction after a provider call, which is why its terminal state lives in
`payment_execution_results` instead (§8, §12.2).

[C3-2] `result_id` on this pair is not an undefined column: it is a fully specified reference to the
append-only event row the command produced (`payment_provider_account_events.id`, or
`payment_provider_object_events.id` for the object pair), it is `bigint unsigned NOT NULL` because every
account/object command writes exactly one event row in its own transaction, and §12.3 declares its
parent, index, nullability and ownership rule like every other reference.

`payment_provider_objects`

```text
id bigint unsigned, uid char(26) NOT NULL, reference_code varchar(32) NULL,
payment_provider_account_id bigint unsigned NOT NULL, object_kind varchar(32) NOT NULL,
canonical_kind varchar(32) NOT NULL, canonical_id bigint unsigned NOT NULL,
object_reference_digest char(64) NOT NULL, state varchar(16) NOT NULL,
active_slot tinyint unsigned NULL, link_version int unsigned NOT NULL,
created_at, updated_at, created_by NULL, updated_by NULL
PRIMARY KEY(id), UNIQUE uid(uid), UNIQUE reference_code(reference_code),
UNIQUE provider_object(payment_provider_account_id, object_kind, object_reference_digest),
UNIQUE canonical_object(payment_provider_account_id, canonical_kind, canonical_id, object_kind, active_slot),
KEY canonical(canonical_kind, canonical_id), KEY provider_account(payment_provider_account_id)
```

`payment_provider_object_events` (append-only) and `payment_provider_object_commands` (digest-only,
immutable): identical column discipline and identifier rule to the account pair above, with
`payment_provider_object_id bigint unsigned NOT NULL`,
`UNIQUE object_sequence(payment_provider_object_id, event_sequence)`,
`KEY provider_object(payment_provider_object_id)`, `KEY result_id(result_id)` and
`UNIQUE command_key_digest(command_key_digest)`.

`payment_provider_secrets`

```text
id bigint unsigned, uid char(26) NOT NULL, provider_key varchar(32) NOT NULL,
payment_provider_account_id bigint unsigned NOT NULL, secret_class varchar(32) NOT NULL,
mode varchar(8) NOT NULL, cipher_version varchar(16) NOT NULL, key_version varchar(32) NOT NULL,
nonce varchar(64) NOT NULL, ciphertext text NOT NULL, state varchar(16) NOT NULL,
active_slot tinyint unsigned NULL, secret_version int unsigned NOT NULL, created_at, updated_at,
created_by NULL, updated_by NULL, retired_at NULL, retired_by NULL
PRIMARY KEY(id), UNIQUE uid(uid),
UNIQUE secret_slot(provider_key, secret_class, payment_provider_account_id, mode, active_slot),
KEY provider_account(payment_provider_account_id), KEY secret_scope(provider_key, secret_class, state)
```

[C2-6] The account scope is `bigint unsigned NOT NULL` (no NULL sentinel, no unscoped secret), so
`secret_slot` guarantees at most one row with `active_slot = 1` per
`(provider_key, secret_class, payment_provider_account_id, mode)` — historical rows carry
`active_slot IS NULL` and may repeat freely. Rotation writes the new active row and retires the previous
one in one transaction under the account row lock, so the invariant is never transiently violated.

`payment_execution_dispatches` ([C3-1] the phase's **only** mutable execution row — the durable,
per-command dispatch claim of §8.3, and [C4-1] the only home of the sealed provider dispatch
descriptor):

```text
id bigint unsigned, uid char(26) NOT NULL, execution_command_id bigint unsigned NOT NULL,
arbitration_subject_kind varchar(32) NOT NULL, arbitration_subject_id bigint unsigned NOT NULL,
idempotency_key_digest char(64) NOT NULL, dispatch_state varchar(16) NOT NULL,
claim_generation int unsigned NOT NULL, claim_token_digest char(64) NOT NULL,
lease_expires_at datetime NULL,
descriptor_cipher_version varchar(16) NOT NULL, descriptor_key_version varchar(32) NOT NULL,
descriptor_nonce varchar(64) NOT NULL, descriptor_ciphertext text NOT NULL,
descriptor_digest char(64) NOT NULL,
claimed_at datetime NOT NULL, settled_at datetime NULL, active_claim_slot tinyint unsigned NULL,
created_at, updated_at, created_by NULL, updated_by NULL
PRIMARY KEY(id), UNIQUE uid(uid), UNIQUE command_dispatch(execution_command_id),
UNIQUE subject_claim(arbitration_subject_kind, arbitration_subject_id, active_claim_slot),
KEY subject(arbitration_subject_kind, arbitration_subject_id), KEY dispatch_state(dispatch_state)
```

[C3-1] It is mutable by design — the owner's `claimed → in_flight → settled` transitions and the lease
are the coordination state — and it is the only execution row that carries `updated_at`. [C5-1] A claim
whose owner never issued a call may instead end `claimed → released` in the fenced
descriptor-refusal transaction of §8.3, so the terminal members are `settled` and `released` and the
transition graph is exactly `claimed → {in_flight, released}` and `in_flight → {settled, released}`
[C7-2] (the only `in_flight → released` edge is the fenced no-call abort of a generation-1 claim that
this owner acquired directly from `claimed`, defined in §8.3; it clears `lease_expires_at` in the same
statement, so the "released carries no lease" rule is unchanged). `settled_at` is
the claim's terminal instant on either terminal path — set by the `in_flight → settled` settlement or by
either release, and `NULL` while the claim is live — and no other timestamp on the row
moves after the insert. It stores no
command terminal state (that stays in `payment_execution_results`, §12.2) and no readable raw
reference: the idempotency key and the claim token are keyed digests, the arbitration subject is an
identifier the guarded transaction already revalidated, and the only reference material present is the
sealed envelope's ciphertext. `active_claim_slot` is `1` while the claim is live and `NULL`
once it is terminal, so `UNIQUE subject_claim` guarantees at most one live claim per
`(arbitration_subject_kind, arbitration_subject_id)`: this is exactly the cross-operation arbitration
that stops a `submit` and a `cancel` for one intent from both being in flight. Exactly one command
holds one claim, and the claim is written only by `PaymentExecutionService`.
[C6-1] The `claimed → released` edge is reached by an **initial** dispatch whose pre-call descriptor
preflight fails at step 2 — the claim is still `claimed`, so no call can have been issued — exactly as it
is reached by a re-drive's `claimed` reconstruction failure; a claim can never move `claimed → in_flight`
without a preflight that returned `ok` **and** [C7-1] Core's pre-lease comparison of the verdict's two
sealed digests against the durable command and claim rows.
[C4-1] The five `descriptor_*` columns are the sealed provider dispatch descriptor of §5.3 and
§11.6 — the envelope's cipher version, key version, nonce and authenticated ciphertext, plus the keyed
digest of that envelope. They carry no plaintext and no readable reference: the ciphertext is the only
place in Schema 028 where a provider-facing reference exists at all, and only the adapter that sealed
it can open it. They are written once at the claim insert, and the repository exposes no method that
can change them.
[C5-3] The descriptor's binding pair (`command_key_digest`, `idempotency_key_digest`) is part of that
sealed plaintext, so it adds **no** column and no readable value to the claim: the binding is proven by
opening the envelope (§5.3, §8.3), never by reading the row. [C7-1] The claim row's own stored
`idempotency_key_digest` is, however, the expected value Core hands to the preflight and re-compares
under the claim lock, so `ok` requires the sealed pair to match **both** durable halves — the command's
`command_key_digest` and this row's digest — and the envelope is never treated as authoritative about
which claim it belongs to.
[C4-2] `claim_generation` is the claim's fencing generation: `1` at insert, and incremented only by the
single conditional lease take-over of §8.3, always together with a fresh `claim_token_digest` and a
future `lease_expires_at`. The generation and the token are what make a take-over exclusive and every
later write — reconciliation, re-issue and settlement alike — attributable to exactly one owner. The
mutable surface of the row is therefore exactly `dispatch_state`, `claim_generation`,
`claim_token_digest`, `lease_expires_at`, `settled_at`, `active_claim_slot`, `updated_at` and
`updated_by`; everything else on the row is write-once.

`payment_provider_event_decision_claims` — **[C9-1]/[C9-2] added by migration
`029_payment_event_decision_claim_authority`, [C10-1]**; the mutable per-event decision claim of §9.5,
the intake counterpart of the §8.3 dispatch claim (mutable by design: the owner's
`claimed → settled`/`released` transitions and the lease *are* the coordination state):

```text
id bigint unsigned, uid char(26) NOT NULL, provider_event_id bigint unsigned NOT NULL,
claim_state varchar(16) NOT NULL, claim_generation int unsigned NOT NULL,
claim_token_digest char(64) NOT NULL, lease_expires_at datetime NULL,
claimed_at datetime NOT NULL, settled_at datetime NULL, active_claim_slot tinyint unsigned NULL,
created_at, updated_at, created_by NULL, updated_by NULL
PRIMARY KEY(id), UNIQUE uid(uid), UNIQUE event_claim(provider_event_id, active_claim_slot),
KEY provider_event(provider_event_id), KEY claim_state(claim_state)
```

Exactly one event holds one claim row per decision, and the claim is written only by
`PaymentEventIntakeService`. `claim_state` is a `DECISION_CLAIM_STATES` member and the transition graph
is exactly `claimed → {settled, released}`: `settled` ends a claim whose owner appended the decision, and
`released` ends a claim whose owner appended nothing (a stale generation, or a worker whose decision work
raised). `active_claim_slot` is `1` while the claim is live and `NULL` once it is terminal, so
`UNIQUE event_claim` guarantees **at most one live claim per event** — that index, never a read, is what
arbitrates two deliveries that both saw a decision owed (§9.5). `claim_generation` is `1` at insert and
incremented only by the single conditional expired-lease take-over, always together with a fresh
`claim_token_digest` and a future `lease_expires_at`; the generation and token fence the settlement, so
exactly one owner can ever append the decision and a replaced owner writes nothing. `claimed_at` is the
instant the current generation took the claim and `settled_at` is its terminal instant — `NULL` while the
claim is live — and the row's mutable surface is exactly `claim_state`, `claim_generation`,
`claim_token_digest`, `lease_expires_at`, `claimed_at`, `settled_at`, `active_claim_slot`, `updated_at`
and `updated_by`. [C10-2] `lease_expires_at` (with `updated_at`) is renewed at every work-unit boundary
of the decision operation, always by one conditional statement that requires the owner's own live
generation **and** an unexpired lease: the lease is therefore the bounded window the owner works inside,
it can never be renewed once it has expired, and a generation that cannot renew performs no further
decision or R1/R2 consequence work at all. [C11-1] The same window is proved — and, outside the unit's
own transaction, renewed — before every statement the unit's transaction runs, by the locking read of
§9.5: the row is therefore locked for the whole transaction a unit runs in, a take-over cannot interleave
with one, and a unit whose window closed mid-transaction is rolled back instead of committing part of
itself. A claim whose owner's window closed while it appended nothing is released by that owner itself
(fenced by its own generation and token), so the event is never held for the rest of a lease nobody is
working inside. It stores no decision outcome (that stays in the append-only
`payment_provider_event_decisions`, §12.2) and no provider reference: the token is a keyed digest and the
event reference is the identifier the owning boundary already recorded.
[C12-1] The window closes on the append itself, so the `claimed → settled` transition requires the same
proof every work unit does: the owner's own live generation and token, its live `active_claim_slot` **and**
a non-null, unexpired `lease_expires_at`, judged at the instant the statement itself runs — [C13-1] one
database-time `UTC_TIMESTAMP()` expression fences that predicate and stamps the settlement, and the
transition takes no instant parameter, so a delay at the append seam or before the statement's row lock
can never settle a window that has closed in the meantime. A window that lapsed after the final work unit
settles nothing and appends nothing, exactly like a replaced generation, and the
owner then releases the live claim it appended nothing to before it converges — so the row ends `released`
(no live slot, no lease) rather than a terminal `settled` row for a decision that was never published.
[C14-1] The transition takes the claim row's lock **before** the statement that judges the window, with one
fenced `SELECT … FOR UPDATE` of that row — the wait for the row therefore happens where it decides nothing —
so the conditional update that follows can no longer wait for it, and its one database-time verdict is taken
after any such wait: an append queued behind another transaction's lock of the claim row is refused by the
lapsed window, not settled by the instant it began waiting.

### 12.2 Append-only evidence

[C2-2] Every table in this section declares an explicit integer identity — `id bigint unsigned NOT NULL
AUTO_INCREMENT` with `PRIMARY KEY(id)` — plus `uid char(26) NOT NULL` with `UNIQUE uid(uid)`, so every
`*_id` child reference below has a declared parent column to point at (§12.3). Nullability is part of
the contract: a `NULL` column is optional by design, and the only optional `char(64)` digest columns are
the six listed in §12.3.

`payment_execution_commands` — **immutable authorisation evidence** ([C2-1]: insert-once, no
`updated_at`, no `result_state`, no `result_id`) with the §8.1 selector shape:
`id bigint unsigned`, `uid char(26)`, `command_domain varchar(48) NOT NULL`,
`operation varchar(48) NOT NULL`, `command_key_digest char(64) NOT NULL`,
`command_payload_digest char(64) NOT NULL`, `student_id bigint unsigned NOT NULL`,
`provider_account_id bigint unsigned NOT NULL`, `purchase_id bigint unsigned NULL`,
`obligation_id bigint unsigned NOT NULL`, `collection_intent_id bigint unsigned NULL`,
`renewal_cycle_id bigint unsigned NULL`, `provider_key varchar(32) NOT NULL`, `mode varchar(8) NOT NULL`,
`amount_minor bigint unsigned NOT NULL`, `currency char(3) NOT NULL`,
`provider_reference_digest char(64) NULL`, `authorised_at datetime NOT NULL`,
`created_at datetime NOT NULL`, `created_by bigint unsigned NOT NULL`;
`PRIMARY KEY(id)`, `UNIQUE uid(uid)`, `UNIQUE command_key_digest(command_key_digest)`,
`KEY obligation(obligation_id)`, `KEY intent(collection_intent_id)`, `KEY cycle(renewal_cycle_id)`,
`KEY purchase(purchase_id)`, `KEY provider_account(provider_account_id)`, `KEY student(student_id)`.

`payment_execution_attempts` — append-only, **at most one row per command** (the command's single port
invocation, §8.2): `id bigint unsigned`, `uid char(26)`,
`execution_command_id bigint unsigned NOT NULL`, `attempt_sequence int unsigned NOT NULL`,
`outcome_state varchar(24) NOT NULL`, `outcome_reason_code varchar(64) NULL`,
`provider_reference_digest char(64) NULL`, `provider_occurred_at datetime NULL`,
`attempted_at datetime NOT NULL`, `recorded_at datetime NOT NULL`, `recorded_by bigint unsigned NOT NULL`,
`created_at datetime NOT NULL`, `created_by bigint unsigned NOT NULL`;
`PRIMARY KEY(id)`, `UNIQUE uid(uid)`, `UNIQUE attempt_sequence(execution_command_id, attempt_sequence)`,
`KEY command(execution_command_id)`.

`payment_execution_results` — **[C2-1] new in this correction round**; append-only, exactly one row per
command, and the only place a command's terminal state is ever stored:
`id bigint unsigned`, `uid char(26)`, `execution_command_id bigint unsigned NOT NULL`,
`result_state varchar(16) NOT NULL`, `result_id bigint unsigned NULL`, `reason_code varchar(64) NULL`,
`resulted_at datetime NOT NULL`, `recorded_at datetime NOT NULL`,
`recorded_by bigint unsigned NOT NULL`, `created_at datetime NOT NULL`,
`created_by bigint unsigned NOT NULL`;
`PRIMARY KEY(id)`, `UNIQUE uid(uid)`, `UNIQUE command_result(execution_command_id)`,
`KEY result_state(result_state)`, `KEY result_id(result_id)`.
`result_state` must be a `RESULT_STATES` member; `authorised` is never stored as a result. A
`completed` row must name the command's own attempt in `result_id`; `refused`/`conflicted` rows carry
`result_id IS NULL` and a controlled `reason_code`. The repository layer exposes no update or delete
method for this table, and the runtime suite proves a second row for one command is rejected by the
unique index and that no code path rewrites an existing row (§8.2, §17).

`payment_provider_event_receipts` — `id bigint unsigned`, `uid char(26)`,
`provider_key varchar(32) NOT NULL`, `payment_provider_account_id bigint unsigned NULL` (NULL only when
the §9.1 selector did not resolve), `account_selector_digest char(64) NULL`,
`request_digest char(64) NOT NULL`, `signature_digest char(64) NULL`,
`signature_key_version varchar(32) NULL`, `verification_state varchar(16) NOT NULL`,
`refusal_reason_code varchar(64) NULL`, `source_digest char(64) NULL`,
`body_bytes int unsigned NOT NULL`, `received_at datetime NOT NULL`, `created_at datetime NOT NULL`,
`created_by bigint unsigned NULL`;
`PRIMARY KEY(id)`, `UNIQUE uid(uid)`, `KEY provider_received(provider_key, received_at)`,
`KEY request_digest(request_digest)`, `KEY provider_account(payment_provider_account_id)`.
`verification_state` is a `VERIFICATION_STATES` member and `refusal_reason_code` a `WEBHOOK_REASONS`
member.

`payment_provider_events` — `id bigint unsigned`, `uid char(26)`, `receipt_id bigint unsigned NOT NULL`,
`provider_key varchar(32) NOT NULL`, `payment_provider_account_id bigint unsigned NOT NULL` (an event row
exists only for a request whose signature verified against a resolved account, §9.4),
`event_reference_digest char(64) NOT NULL`, `event_fact_digest char(64) NOT NULL`,
`event_type varchar(48) NOT NULL`, `raw_type_digest char(64) NOT NULL`,
`payload_digest char(64) NOT NULL`, `provider_occurred_at datetime NULL`,
`received_at datetime NOT NULL`, `created_at datetime NOT NULL`, `created_by bigint unsigned NULL`;
`PRIMARY KEY(id)`, `UNIQUE uid(uid)`, `UNIQUE provider_event(provider_key, event_reference_digest)`,
`KEY receipt(receipt_id)`, `KEY received_at(received_at)`,
`KEY provider_account(payment_provider_account_id)`.
Intake state is frozen in the row; processing outcomes are separate rows, so this table is never
updated. [C9-1]/[C9-2] The event's decision claim is therefore **not** a column here: it is its own
mutable aggregate (§12.1), so this row stays write-once and the claim's lifecycle is never mixed into the
immutable event identity.

`payment_provider_event_decisions` — `id bigint unsigned`, `uid char(26)`,
`provider_event_id bigint unsigned NOT NULL`, `decision_sequence int unsigned NOT NULL`,
`decision_state varchar(24) NOT NULL`, `reason_code varchar(64) NULL`,
`evidence_kind varchar(16) NULL`, `offer_id bigint unsigned NULL`,
`obligation_id bigint unsigned NULL`, `purchase_id bigint unsigned NULL`,
`commercial_evidence_id bigint unsigned NULL`, `collection_intent_id bigint unsigned NULL`,
`renewal_cycle_id bigint unsigned NULL`, `renewal_cycle_state varchar(24) NULL`,
`collection_intent_state varchar(16) NULL`, `r2_consequence_state varchar(16) NULL`,
`r2_reason_code varchar(64) NULL`, `execution_command_id bigint unsigned NULL`,
`decided_at datetime NOT NULL`, `recorded_at datetime NOT NULL`,
`recorded_by bigint unsigned NULL`, `created_at datetime NOT NULL`, `created_by bigint unsigned NULL`;
`PRIMARY KEY(id)`, `UNIQUE uid(uid)`,
`UNIQUE decision_sequence(provider_event_id, decision_sequence)`, `KEY event(provider_event_id)`,
`KEY offer(offer_id)`, `KEY obligation(obligation_id)`, `KEY purchase(purchase_id)`,
`KEY commercial_evidence(commercial_evidence_id)`, `KEY intent(collection_intent_id)`,
`KEY cycle(renewal_cycle_id)`, `KEY execution_command(execution_command_id)`.
[C2-3] `r2_consequence_state` is a `R2_CONSEQUENCE_STATES` member and `r2_reason_code` a
`R2_CONSEQUENCE_REASONS` member (the ordered consequence of §10.1); `decision_state` is a
`DECISION_STATES` member and `reason_code` a `DECISION_REASONS` member. `recorded_by` is the worker
principal, or NULL when the refusal precedes any actor. [C9-1] A `conflicting_provider_event` row records
that a different delivery carried materially different facts; it is never the event's own decision, so
whether an event still owes a decision is asked of its non-conflict rows (§9.5), and a conflict row can
neither suppress a first decision nor turn a deferred one into "decided".

`payment_provider_secret_events` — `id bigint unsigned`, `uid char(26)`,
`provider_key varchar(32) NOT NULL`, `payment_provider_account_id bigint unsigned NULL` (NULL only for a
`write_refused` row whose account selector did not resolve — a write always requires a resolved
account, §11.1), `secret_class varchar(32) NOT NULL`, `mode varchar(8) NULL`,
`audit_type varchar(24) NOT NULL`, `key_version varchar(32) NULL`,
`command_key_digest char(64) NULL`, `reason_code varchar(64) NULL`,
`occurred_at datetime NOT NULL`, `recorded_at datetime NOT NULL`,
`recorded_by bigint unsigned NOT NULL`, `created_at datetime NOT NULL`,
`created_by bigint unsigned NOT NULL`;
`PRIMARY KEY(id)`, `UNIQUE uid(uid)`,
`KEY secret_timeline(provider_key, secret_class, occurred_at)`,
`KEY provider_account(payment_provider_account_id)`.
`audit_type` is a `SECRET_AUDIT_TYPES` member and `reason_code` a `SECRET_REASONS` member.

### 12.3 Primary identifiers, reference ownership and optional digests ([C2-2], [C2-6], [C3-2])

Identity rule: every Schema 028 table declares `id bigint unsigned NOT NULL AUTO_INCREMENT` with
`PRIMARY KEY(id)`, and every table that exposes a stable public handle declares `uid char(26) NOT NULL`
with `UNIQUE uid(uid)`. No table relies on an implicit or inherited identity, no `*_id` reference points
at a table without a declared `id`, and no reference is a digest used as a foreign key. Every reference
column is `bigint unsigned` — never `varchar`, never a digest, never a raw provider id.

[C3-2] Two disjoint parent sets are declared, and every `*_id` column below names exactly one of them:

1. **Phase-T parents** — the Schema 028 tables of §12.1/§12.2, each of which declares `id bigint
   unsigned NOT NULL AUTO_INCREMENT` with `PRIMARY KEY(id)`.
2. **External authoritative parents** — the frozen set of pre-existing tables this phase references but
   does not own, each of which already declares `id bigint unsigned NOT NULL AUTO_INCREMENT` with
   `PRIMARY KEY(id)` in its own phase migration and its own verifier: `students` (Schema 001),
   `commercial_offers`, `commercial_purchases`, `commercial_offer_obligations` and
   `commercial_payment_evidence` (Schema 025 / R1), and `collection_intents`, `renewal_cycles` and
   `recurring_enrolments` (Schema 026 / R2).

Phase T adds no column, index, row, trigger or foreign key to any external parent and never writes one;
it stores only an identifier the owning boundary already proved inside the same serialised transaction.
A `*_id` column whose parent is neither set — or whose Phase-T *or* external parent does not itself
declare `id bigint unsigned NOT NULL AUTO_INCREMENT` with `PRIMARY KEY(id)` — is rejected (§12.4).

| Reference column | Null | Parent (`table.column`) | Index | Application-enforced ownership rule |
| --- | --- | --- | --- | --- |
| `payment_provider_account_events.payment_provider_account_id` | NOT NULL | `payment_provider_accounts.id` | `UNIQUE account_sequence` (leading column) | written only by `PaymentProviderAccountService`, in the transaction that changed that account, after the account row was re-read under its lock |
| `payment_provider_account_commands.payment_provider_account_id` | NOT NULL | `payment_provider_accounts.id` | `UNIQUE command_key_digest` + owner `KEY` | same transaction as the state change it records; a foreign or missing account id is corruption and is refused |
| `payment_provider_objects.payment_provider_account_id` | NOT NULL | `payment_provider_accounts.id` | `KEY provider_account` + `UNIQUE provider_object` (leading column) | written by `PaymentProviderObjectService` after the account row is read and refused for a `closed` account |
| `payment_provider_objects.canonical_id` | NOT NULL | the row named by `canonical_kind`: `students.id`, `commercial_purchases.id`, `commercial_offer_obligations.id`, `collection_intents.id` or `recurring_enrolments.id` | `UNIQUE canonical_object` + `KEY canonical` | kind-scoped: the service re-reads the canonical row under the owning lock and refuses a foreign, missing or kind-mismatched id; a mapping never grants access |
| `payment_provider_object_events.payment_provider_object_id` / `…_commands.payment_provider_object_id` | NOT NULL | `payment_provider_objects.id` | `UNIQUE object_sequence` / `UNIQUE command_key_digest` | written only in the transaction that changed that mapping |
| `payment_provider_account_commands.result_id` / `payment_provider_object_commands.result_id` ([C3-2]) | NOT NULL | the append-only event row the command produced: `payment_provider_account_events.id` / `payment_provider_object_events.id` | `KEY result_id` | `bigint unsigned NOT NULL` because every account/object command writes exactly one event row in its own transaction; a missing, foreign or cross-command event id is corruption and is refused |
| `payment_provider_secrets.payment_provider_account_id` | NOT NULL ([C2-6]) | `payment_provider_accounts.id` | `UNIQUE secret_slot` (scope column) | the write path resolves the account first and holds its row lock, so no secret can exist without an owning account and no scope is ambiguous |
| `payment_execution_commands.student_id` | NOT NULL | `students.id` | `KEY student` | copied from the revalidated R1 obligation's purchase chain inside the guarded transaction |
| `payment_execution_commands.provider_account_id` | NOT NULL | `payment_provider_accounts.id` | `KEY provider_account` | the exact account row revalidated by §6.3; a different account is refused |
| `payment_execution_commands.obligation_id` | NOT NULL | `commercial_offer_obligations.id` | `KEY obligation` | the command's own obligation; ownership re-proven through `CommercialLineageValidator`/`CommercialCommitmentValidator` semantics |
| `payment_execution_commands.purchase_id` | NULL unless the operation owns it (§8.1) | `commercial_purchases.id` | `KEY purchase` | an unowned selector must be exactly NULL; a foreign-but-valid id is contamination and fails the replay |
| `payment_execution_commands.collection_intent_id` / `.renewal_cycle_id` | NULL unless the operation owns it (§8.1) | `collection_intents.id` / `renewal_cycles.id` | `KEY intent` / `KEY cycle` | the R2 intent and cycle must be the same row the §6.2 proof resolved; a mismatch is `collection_kind_conflict` / `renewal_cycle_not_collectable` |
| `payment_execution_attempts.execution_command_id` | NOT NULL | `payment_execution_commands.id` | `UNIQUE attempt_sequence` + `KEY command` | closed by §8.2 rule 3: one attempt per command, and the command's `completed` result must name it |
| `payment_execution_results.execution_command_id` | NOT NULL | `payment_execution_commands.id` | `UNIQUE command_result` | `PaymentExecutionService` only, in transaction 2, under the same command row it just dispatched |
| `payment_execution_results.result_id` | NULL except for `completed` | `payment_execution_attempts.id` | `KEY result_id` | must be the attempt of the same command; a `refused`/`conflicted` row must keep it NULL (§8.2 rule 3) |
| `payment_execution_dispatches.execution_command_id` ([C3-1]) | NOT NULL | `payment_execution_commands.id` | `UNIQUE command_dispatch` | written by `PaymentExecutionService` in transaction 1, in the same transaction as the command row it claims; a missing or foreign command id is corruption and is refused, and a claim for a command that already has a result row is corruption (§8.2 rule 5) — [C5-1] except that one permission, the terminal `released` claim the descriptor-refusal transaction of §8.3 writes together with its own `refused` result row |
| `payment_execution_dispatches.arbitration_subject_id` ([C3-1]) | NOT NULL | the row named by `arbitration_subject_kind`: `collection_intents.id` (kind `collection_intent`) or `commercial_offer_obligations.id` (kind `obligation`) | `UNIQUE subject_claim` (leading columns) + `KEY subject` | kind-scoped: the service re-reads the subject under the account-root lock in the same transaction and refuses a foreign, missing or kind-mismatched id; the unique index over the live slot is what arbitrates two opposing operations for one intent |
| `payment_provider_event_receipts.payment_provider_account_id` | NULL only when the §9.1 selector did not resolve | `payment_provider_accounts.id` | `KEY provider_account` | written by the controller's pre-parse resolution; NULL is a recorded refusal, never an accepted scope |
| `payment_provider_events.receipt_id` | NOT NULL | `payment_provider_event_receipts.id` | `KEY receipt` | written by the intake service in the same transaction that recorded the verified receipt (receipt → event → decision) |
| `payment_provider_events.payment_provider_account_id` | NOT NULL | `payment_provider_accounts.id` | `KEY provider_account` | the account the signature actually verified against (§9.4); a payload that names another account is refused with `unmapped_provider_account` |
| `payment_provider_event_decisions.provider_event_id` | NOT NULL | `payment_provider_events.id` | `UNIQUE decision_sequence` + `KEY event` | written only by `PaymentEventIntakeService` for the event it just verified or drained; the §8.4 hook passes this same `id` |
| `payment_provider_event_decision_claims.provider_event_id` ([C9-1]/[C9-2]) | NOT NULL | `payment_provider_events.id` | `UNIQUE event_claim` (leading column) + `KEY provider_event` | written only by `PaymentEventIntakeService`, for the event it owns the decision of, before any translation or R1/R2 consequence runs; a foreign or missing event id is corruption and is refused |
| `payment_provider_event_decisions.obligation_id` / `.purchase_id` / `.offer_id` / `.commercial_evidence_id` / `.collection_intent_id` / `.renewal_cycle_id` / `.execution_command_id` | NULL (kind-specific) | `commercial_offer_obligations.id`, `commercial_purchases.id`, `commercial_offers.id`, `commercial_payment_evidence.id`, `collection_intents.id`, `renewal_cycles.id`, `payment_execution_commands.id` | `KEY offer`, `KEY obligation`, `KEY purchase`, `KEY commercial_evidence`, `KEY intent`, `KEY cycle`, `KEY execution_command` | each is written only from an id the same decision actually resolved through the mapping registry and the R1/R2 acceptance boundary; an unresolved attribution stores NULL and records the controlled refusal instead |
| `payment_provider_secret_events.payment_provider_account_id` | NULL only for an unresolvable `write_refused` row | `payment_provider_accounts.id` | `KEY provider_account` | the vault records the resolved account on every write, rotation, retire or decrypt failure |

No reference is enforced by a database foreign key, matching the repository's existing convention
(§12.4): the owning service proves the reference inside its serialised transaction, the verifier proves
the declared column, type, index and `id` parent exist, and the runtime corruption suite proves a
re-pointed reference fails closed at the owning boundary (§17). [C3-2] The `id` parent is proved for a
Phase-T parent and an external authoritative parent alike (§12.4), so the external references
`student_id`, `purchase_id`, `offer_id`, `obligation_id`, `commercial_evidence_id`,
`collection_intent_id`, `renewal_cycle_id` and `recurring_enrolment`-based `canonical_id` are as fully
covered as the Phase-T ones.

Optional `char(64)` digest columns — the only digest columns that may be declared `NULL`, each because
its absence is a meaningful, recorded state:

1. `payment_execution_commands.provider_reference_digest` (a gate-refused command never reached a provider),
2. `payment_execution_attempts.provider_reference_digest` (a refusal or an unavailable provider returns none),
3. `payment_provider_event_receipts.signature_digest` (a request with no signature header),
4. `payment_provider_event_receipts.account_selector_digest` (a request with no resolvable selector),
5. `payment_provider_event_receipts.source_digest` (a request whose source address is unavailable),
6. `payment_provider_secret_events.command_key_digest` (an audit row written without a command).

Every other `char(64)` column in Schema 028 is `NOT NULL`. In particular the §12.1 aggregates and their
command/event tables follow the same rule:
`payment_provider_accounts.account_reference_digest`, `payment_provider_objects.object_reference_digest`
and every `evidence_reference_digest` on the account and object command/event tables are
`char(64) NOT NULL`, [C3-1] as are
`payment_execution_dispatches.idempotency_key_digest` and `payment_execution_dispatches.claim_token_digest`,
and no aggregate, mapping, command or dispatch table may declare a nullable digest that is not in the
six-column list above.

[C4-1], [C4-2] Three further `payment_execution_dispatches` columns are declared here so nothing about
the claim is left to inference: `descriptor_digest` is `char(64) NOT NULL` — a keyed digest of the
sealed envelope, never a reference and never a parent — and `claim_generation` is
`int unsigned NOT NULL` — a fencing generation, again neither an identifier reference nor a digest.
Neither is an `*_id` column, so neither appears in the reference table above; both are structurally
validated by §12.4 and behaviourally by §17.

### 12.4 Migration rules

- `028_payment_execution_seam_provider_adapter` installs only the fifteen seam tables above, and exactly
  these: `payment_provider_accounts`, `payment_provider_account_events`,
  `payment_provider_account_commands`, `payment_provider_objects`,
  `payment_provider_object_events`, `payment_provider_object_commands`, `payment_provider_secrets`,
  `payment_execution_commands`, `payment_execution_attempts`, `payment_execution_results`,
  `payment_execution_dispatches`, `payment_provider_event_receipts`, `payment_provider_events`,
  `payment_provider_event_decisions`, `payment_provider_secret_events`.
- [C10-1] `029_payment_event_decision_claim_authority` installs exactly one table —
  `payment_provider_event_decision_claims` — and nothing else. It exists because the claim aggregate
  must never be added to an already-completed migration: an installation that completed `028` before the
  aggregate existed has no claim table, and a completed migration is never re-applied, so the repair is
  **scheduled** rather than assumed. The installer is additive from the current schema — `dbDelta` of
  that single table, no backfill, no `UPDATE`/`INSERT`/`ALTER`, no claim inferred or taken over, nothing
  settled — so a repaired installation starts with an empty claim timeline, which is exactly the state a
  delivery that owes a decision expects. It creates no academic, notification or settlement table and
  adds no column to any existing table.
- `verify_payment_execution_schema()` runs after migration 028, on current-schema verification, and
  unconditionally before the schema option advances to 29 (including the retained-028/stale-version
  path) — the same three call sites the R1/R2 verifiers use. [C10-1] It proves the fifteen-table seam
  and deliberately neither requires nor validates the decision-claim aggregate — it tolerates the table
  migration `029` owns — so a database the ledger can still repair is never failed closed before that
  repair runs.
- [C10-1] `verify_payment_event_decision_claim_schema()` runs after migration 029, on current-schema
  verification and unconditionally before the schema option advances to 29 — again the same three call
  sites. It proves the claim aggregate's own shape: the declared identity (`id`/`PRIMARY KEY`,
  `uid`/`UNIQUE uid`), the declared columns and `InnoDB` engine, the arbitration index
  `UNIQUE event_claim(provider_event_id, active_claim_slot)` with its `KEY provider_event` and
  `KEY claim_state`, the non-null `char(64)` token digest field, the nullable
  `lease_expires_at`/`settled_at`/`active_claim_slot` fields, the mutable row's `created_at`/`updated_at`,
  the `bigint unsigned` parented `provider_event_id` (parent `payment_provider_events.id`), and the row
  rules of §12.1. A completed-029 installation whose claim table has vanished fails closed and is never
  silently repaired; the ledger-owned repair is the scheduled migration itself.
- The verifier rejects: a non-InnoDB table; a missing declared table; a created table outside the
  declared set; `updated_at` or any raw/reference column on an append-only table; a missing uniqueness
  or lookup index (§12.1–§12.2); any column matching `%stripe%`, `%card%`, `%pan%`, `%cvc%`, `%iban%`,
  `%plaintext%`, `%secret_value%`, `%raw_body%`, `%provider_subscription%` or `%provider_intent%`; and
  any academic, notification, scheduling or commercial table smuggled into the phase (`payment_terms`,
  `payment_lessons`, `payment_schedules`, `payment_notifications`, `payment_evidence`,
  `payment_settlements`). [C4-1] It additionally rejects a column outside the declared set whose name
  ends in `_reference` or `_ref` — a raw reference column, since only the declared
  `*_reference_digest` forms may exist — and a `%descriptor%` column on any table other than
  `payment_execution_dispatches`, because exactly one row in Schema 028 may carry the sealed envelope.
- [C2-2], [C3-2] The verifier additionally rejects: a declared table with no `id bigint unsigned NOT
  NULL AUTO_INCREMENT` column and no `PRIMARY KEY(id)` index; a table with no `uid char(26) NOT NULL`
  unique index; a declared `*_id` column that is not `bigint unsigned`; a declared `*_id` column that
  has no named index of the exact shape declared in §12.3; and a declared `*_id` column whose declared
  parent (§12.3) is neither a declared Schema 028 table nor one of the frozen external authoritative
  parents. For either kind of parent the verifier then proves the identity **physically** — the parent
  table exists and declares `id bigint unsigned NOT NULL AUTO_INCREMENT` with `PRIMARY KEY(id)` — so a
  Phase-T parent such as `payment_execution_commands.id` and an external authoritative parent such as
  `commercial_offer_obligations.id` or `collection_intents.id` are validated by the same rule, and the
  earlier "parent must be in Schema 028" reading is expressly superseded. `payment_execution_results`
  and `payment_provider_account_commands`/`_object_commands` (`result_id` → the produced event row) are
  checked for the same discipline, including `UNIQUE command_result(execution_command_id)` and the
  absence of `updated_at` on the fully append-only tables.
- [C3-1] The verifier rejects a dispatch-claim defect: a `payment_execution_dispatches` table missing
  `UNIQUE command_dispatch(execution_command_id)`, missing
  `UNIQUE subject_claim(arbitration_subject_kind, arbitration_subject_id, active_claim_slot)` or its
  `KEY subject`, a `dispatch_state` that is not a `DISPATCH_STATES` member, a claim row that stores any
  command terminal state, and a second live claim (`active_claim_slot = 1`) sharing one arbitration
  subject. The runtime suite then proves the arbitration behaviourally: two opposing operations for one
  intent cannot both hold a live claim, and a claim left `in_flight` by a crash is taken over and
  reconciled exactly once.
    [C5-1] It also enforces the single permitted claim/result pairing: the only claim that may coexist
  with a result row is a **terminal `released` claim** whose command's result is that command's own
  `refused` row with `reason_code = dispatch_descriptor_unavailable` (§8.2 rule 5, §8.3). Every other
  coexistence — a live (`claimed`/`in_flight`) claim beside a result, a `settled` claim beside a result,
  or a `released` claim whose result is `completed`/`conflicted` or carries any other reason — is
  corruption and is refused.
  [C4-1]/[C4-2] It also rejects a claim whose sealed envelope is incomplete (any of
  `descriptor_cipher_version`, `descriptor_key_version`, `descriptor_nonce`, `descriptor_ciphertext` or
  `descriptor_digest` missing or empty), whose `descriptor_digest` disagrees with the stored envelope,
  whose `claim_generation` is not a positive integer, whose `claimed` state carries a
  `lease_expires_at`, whose [C5-1] `released` state carries one, or whose `in_flight` state carries none.
  [C7-2] The no-call abort of §8.3 obeys that rule by construction: it clears `lease_expires_at` in the
  same conditional statement that moves the claim `in_flight → released`, so a released claim can never
  retain a lease on either release edge, and the abort affects exactly one row or writes nothing.
  The runtime suite then proves the fencing
  behaviourally, not just structurally: a concurrent expired-lease re-drive yields exactly one takeover —
  the loser's conditional update affects `0` rows, it calls nothing and it writes nothing — and only the
  generation holder's settlement is accepted.
- [C2-6] The verifier rejects a `payment_provider_secrets.payment_provider_account_id` that is declared
  NULL-able, a `secret_slot` index whose column list or order differs from
  `(provider_key, secret_class, payment_provider_account_id, mode, active_slot)`, and any other unique
  index that would let two rows with `active_slot = 1` share one scope. The runtime suite then proves
  the invariant behaviourally: a second active row for one `(provider_key, secret_class, account, mode)`
  is rejected, a rotation leaves exactly one active row and one history row, and a NULL account scope is
  rejected by the column definition.
- [C9-1]/[C9-2] [C10-1] The claim aggregate's own verifier
  (`verify_payment_event_decision_claim_schema()`, migration 029) rejects a decision-claim defect: a
  `payment_provider_event_decision_claims` table missing
  `UNIQUE event_claim(provider_event_id, active_claim_slot)` or its `KEY provider_event`, a
  `claim_state` that is not a `DECISION_CLAIM_STATES` member, a non-positive `claim_generation`, a
  malformed `claim_token_digest`, a live `claimed` row without its live slot, its lease or without a
  `NULL` terminal instant, a terminal row that kept the live slot, kept a lease or recorded no terminal
  instant, two live decision claims sharing one provider event, and a `settled` claim whose event
  carries no decision row at all. The runtime suites then prove the behaviour: a decision that is
  appended by exactly one claimed worker, a loser that performs no work and converges, an abandoned
  claim that exactly one later generation takes over, and a claim-agent whose aggregate proof fails
  closed on every mutated shape above.
- Digest nullability is checked too: a `char(64)` column is accepted as `NOT NULL`, or as `NULL` only
  when it is one of the six optional digest columns enumerated in §12.3; anything else is rejected, and
  a declared-NOT-NULL digest that is NULL-able is rejected.
- The verifier additionally proves the phase added **no** column to any existing table by asserting
  that every `commercial_*` and R2 table still matches its own phase verifier's spec (the R1 and R2
  verifiers are re-run, not duplicated).
- [C10-1] Schema 26 → 29 (and 25 → 29) is repeat-safe and leaves every R1 and R2 row unchanged: the fifteen-table migration 028 followed by the scheduled one-table migration 029, which is also the repair path for a database that completed 028 before the claim aggregate existed. There is no
  backfill, no default provider account and no default mapping.

## 13. Services, repositories, read models, diagnostics

| Surface | Capability | Notes |
| --- | --- | --- |
| `PaymentProviderAccountService::register`, `set_execution_state`, `suspend`, `resume`, `close` | `dzn_manage_payment_providers` | `execution_state` may be set to `enabled` only for a non-live mode while `LIVE_EXECUTION_PROVIDERS` is empty; every change is an append-only event; `register` records the non-secret `reference_code` the webhook route uses as its account selector (§9.1) and refuses an empty or duplicate value |
| `PaymentProviderObjectService::link`, `supersede`, `detach`, `resolve` | `dzn_manage_payment_providers` | mapping only; refuses a closed account, a foreign canonical row and a duplicate active link |
| `PaymentExecutionService::submitCollection`, `cancelCollection`, `reconcileCollection`, `redrive` | `dzn_manage_payment_execution` | §6/§8; proves the caller's raw `ProviderReferenceClaims` against the registry and seals the adapter's dispatch descriptor inside transaction 1, writes the durable dispatch claim (`payment_execution_dispatches`, §12.1) before releasing the account-root lock, rebuilds the port request from the command row plus that descriptor ([C4-1]) and [C6-1] proves its binding with the adapter's **non-mutating** `preflightDispatchDescriptor()` — [C7-1] handing it the live claim's stored `idempotency_key_digest` and then re-comparing the verdict's two sealed digests against the durable command and claim rows under the claim lock, immediately before the acquisition — while the claim is still `claimed` and **before** the lease is acquired (a non-`ok` verdict or any digest inequality takes the fenced `claimed → released` refusal path of §8.3 and makes no call), [C7-2] delegates the single provider invocation to the port with the one-use capability that preflight minted (so no call ever opens the envelope), and records the command's single attempt and its terminal `payment_execution_results` row only through a settlement fenced by the claim's generation and token ([C4-2]); it never updates the command row ([C2-1], [C3-1]). `redrive($commandId)` is the idempotent, repeat-safe recovery entry point of §8.3: it accepts no request, reference or user id, reconstructs every port input from durable rows, [C5-1] ends a `claimed` claim it cannot open — [C6-1] including a first-dispatch claim whose preflight failed — with the single fenced transaction that writes the command's `refused`/`dispatch_descriptor_unavailable` result and releases the live slot, [C7-2] ends a generation-1 `in_flight` claim whose port refused a capability pre-call (no outbound request, no attempt) with the fenced no-call abort that clears the lease and writes that same refusal, takes an expired lease over atomically before it reconciles, [C5-2] proves the takeover fence with one conditional pre-call ownership check before it re-issues, re-issues only under the new fence and only when reconciliation proves the provider never received the request, and never issues a second mutating call |
| `PaymentEventIntakeService::receive`, `drain` | `dzn_ingest_payment_provider_events` (operator surface only) plus the §9.7 worker principal | §9; `receive` resolves the account selector before parsing and is also invoked anonymously by the REST controller, so it performs **no caller capability check** for receipt, verification and durable recording — the verified signature is the authority there — while `drain` invoked from an operator surface requires `dzn_ingest_payment_provider_events`. [C4-3] The translation and the §10.1 consequence always run inside `PaymentExecutionWorkerContext` under the §9.7 principal's four bounded capabilities, never under the caller's identity |
| `PaymentEventIntakeService::outstandingDecisionClaims` | `dzn_view_payment_execution_authority` | [C9-1]/[C9-2] §9.5/§13; the live per-event decision claims by state, age and fencing generation — never a token, a payload or a provider reference — so an operator can see which events one worker is completing, how long it has held them and which generation owns them |
| `PaymentExecutionWorkerContext::run` | none (internal; no administrative surface, and it never satisfies an execution capability) | [C4-3] §9.7; the only place in Phase T that sets a WordPress current user. Validates the principal, sets it, proves all four capabilities are effective under it, runs the bounded translation/consequence work, and restores the previous identity in a `finally`. Refuses `payment_worker_principal_required` before any identity change when the option is unset, the user is missing or inactive, a required capability is absent or an administrative capability is present, and refuses re-entry as an integrity fault. It is never entered by `PaymentExecutionService`, whose surface keeps its own `dzn_manage_payment_execution` actor |
| `PaymentExecutionDispatchSeal::seal`, `open` | adapter scope only (no administrative surface) | [C4-1] §11.6; the sealed dispatch descriptor's locked field set, domain-separated key derivation and fail-closed open. `seal` is called only by the resolved adapter inside the command's transaction 1, and [C5-3] `open` only by that same adapter — [C6-1] during the non-mutating pre-call preflight of §8.3 step 2 (the `preflightDispatchDescriptor()` verdict that must be `ok` before a claim may leave `claimed`) and during the re-drive's reconstruction proof (§8.3), where it reports the sealed binding pair (digests only, never a raw reference) for `PaymentExecutionIntegrity` to compare. [C7-2] `open` is never called by `submit`/`cancel`/`reconcile`: those consume the one-use `ProviderDispatchCapability` the `ok` verdict minted, so the envelope is opened exactly once per provider invocation and never inside a call; neither method is a credential path and neither can store a secret |
| `PaymentSecretVault::store`, `rotate`, `retire`, `revoke`, `reveal` | `dzn_manage_payment_providers` (store/rotate/retire/revoke); adapter scope (reveal) | §11; **[C2-5]** every write path first refuses any `provider_key` outside `PROVISIONABLE_PROVIDERS` with `provider_secret_write_not_authorised`, so no production caller can store a provider secret in this build |
| `PaymentExecutionReadService`, `PaymentProviderReadService` | `dzn_view_payment_execution_authority` | PII-minimised, digest-only, secret-free; fail closed on a malformed aggregate |

Repositories follow the established pattern: an explicit `begin()`/`commit()`/`rollback()` wrapper,
named-index duplicate arbitration, digest-only command evidence, append-only event/history discipline,
and `READ COMMITTED` semantics. [C2-1] The execution repository exposes **insert-only** methods for
`payment_execution_commands`, `payment_execution_attempts` and `payment_execution_results` and no
update method for any of them. [C3-1] The dispatch repository is the one deliberate exception in that
repository: it
exposes the conditional `claimed → in_flight` acquisition, the conditional expired-lease take-over,
[C5-2] the conditional pre-call ownership/fence-renewal check a takeover winner must pass before it
re-issues, [C5-1] the fenced `claimed → released` release that ends a claim whose descriptor cannot be
opened together with that command's `refused` result, [C7-2] the fenced generation-1 `in_flight →
released` no-call abort (which clears the lease in the same statement) that the pre-call capability
refusal takes, and the fenced `in_flight → settled` transition —
each a single compare-and-swap whose affected-row count is the outcome, taken under a claim row lock —
and no delete method and no method that writes a command terminal state. [C4-1] It also exposes no
method that can rewrite the sealed descriptor envelope, its key/cipher version, its nonce or its digest
after the insert. [C6-1] The pre-call descriptor preflight of §8.3 step 2 adds **no** repository method
and no write — it is an adapter-scoped, read-only open of the claim's sealed envelope — [C7-2] and the
capability it mints is never stored, so the dispatch repository's mutation surface above is unchanged.
[C9-1]/[C9-2] The provider-event repository exposes the decision claim's conditional statements — the
live-slot insert, the fenced `claimed → settled` transition, the fenced `claimed → released` release and
the conditional expired-lease take-over that bumps the generation and re-issues the token — each a single
statement whose affected-row count is the outcome, [C14-1] except the `claimed → settled` transition, which
takes the claim row's lock with its own fenced locking read before that conditional statement (the wait for
the row must not be the statement that judges the window) and whose affected-row count is still the only
outcome of the transition, plus the read of an event's live claim and the
append-only decision/event/receipt reads. [C12-1] The `claimed → settled` transition is bounded by the
claim's window exactly as the work units are: besides the owner's own `claim_generation` and
`claim_token_digest` it requires `active_claim_slot = 1` **and** a non-null, unexpired `lease_expires_at`.
[C13-1] Because the append must be fenced at the instant it runs, that transition takes its verdict from
one **database-time** expression inside the statement — `lease_expires_at >= UTC_TIMESTAMP()` — and stamps
`settled_at`/`updated_at` with the same expression, and it takes no instant parameter at all: a seam
callback delay, or any delay before the statement's row lock, therefore changes the verdict itself rather
than being compared against an instant the caller read earlier, so an append that runs after the lease has
lapsed affects no row and publishes nothing. [C14-1] The row lock is taken **before** that judgement, by the
transition's own fenced locking read of the claim row: a database-time expression is evaluated once, when its
statement starts, so the statement that judges the window must not be the statement that waits for the row —
the conditional update runs only once the row is held, and therefore after any wait for it, while the locking
read that did the waiting decided nothing. [C10-2] It also exposes exactly one bounded-window renewal: a
conditional statement that requires the caller's own live generation (`claim_state = 'claimed'`, its
`claim_generation` and `claim_token_digest`, `active_claim_slot = 1`) **and** an unexpired
`lease_expires_at`, and only then extends the window — so the work-unit gate can never renew a window that
has already closed, and the generation that cannot renew stops before the work. [C11-1] That method is the
window fence: it proves the window with one `SELECT … FOR UPDATE` of the claim row (the verdict is the
read, never an affected-row count, so a renewal inside the same second is never mistaken for a closed
window), extends the lease only when the caller asked for a renewal and only after that read proved the
window live, and holds the row — from inside a work unit's own transaction — until that transaction ends.
It exposes no delete method, no method that rewrites a recorded
decision and no method that writes an event row.

New capabilities (administrator only; a repair loop must add them per capability and must remove them
from `dzn_teacher` and any student role, exactly as R1/R2 do):
`dzn_manage_payment_providers`, `dzn_manage_payment_execution`, `dzn_ingest_payment_provider_events`,
`dzn_view_payment_execution_authority`.

Diagnostics report counts and states only: accounts by state/mode, credential state, events by
verification/decision state and reason, execution commands by derived result state, attempts by
outcome, [C2-3] outstanding R2 consequences by state (`pending`, `applied`, `refused`), outstanding
`received` events, [C3-1] outstanding dispatch claims by state (`claimed`, `in_flight`) with their
arbitration subject kind, age and fencing generation (never a token, key digest, envelope or raw
reference), [C4-1] dispatch claims whose sealed descriptor could not be opened (§6.4), [C7-2]
descriptor-refusal releases and generation-1 no-call aborts by reason code (never a capability,
envelope, token or digest), [C4-3] worker-context entries and refusals by reason code, [C2-5] refused
provider-secret writes, [C9-1]/[C9-2] live per-event decision claims by state, age and fencing
generation (never a token, payload or provider reference), and decrypt failures. Never a provider
payload, secret, raw reference, raw signature, source address or provider status string.

## 14. Concurrency, idempotency and serialisation

- Serialization root: the R1 `commercial_account_roots` row for the beneficiary Student, taken first
  for every execution command and every event decision that can reach commercial authority. The fixed
  lock order is never inverted and unrelated Students never contend.
- Command idempotency: HMAC key digest + payload digest, replay-vs-conflict arbitration, fail-closed
  corruption with no silent repair, and replay only after the authoritative aggregate and the recorded
  result row (and, for `completed`, the attempt that result names) are revalidated. [C3-1] A replayed key
  on a command whose dispatch is still unresolved converges on that command and its claim and reports the
  pending state; it never dispatches a second time.
- [C2-1] Result ordering: the command row is written first and is immutable; exactly one
  `payment_execution_results` row may be written per command, and its order relative to the command and
  the attempt is fixed (command → attempt → result). A second result for one command is rejected by
  `UNIQUE command_result`, and a result that names a foreign attempt fails the integrity check rather
  than being repaired.
- [C2-3] R2 consequence ordering: the intent confirmation always precedes the cycle confirmation, both
  under the same account-root lock and worker principal; the consequence may only move a cycle from
  `payment_required` to `collected`, never backwards and never from another state, so a concurrent
  `recordFailure` or `cancel` cannot be overwritten and a losing writer records its own reasoned
  outcome.
- [C9-1]/[C9-2] Event-decision ownership: an event's decision is owned by exactly one worker through its
  durable decision claim, taken **before** any translation or R1/R2 consequence runs and held across
  that work under a lease. The claim row lock is taken for the whole of the fenced settlement
  transaction, which is the same transaction that allocates `decision_sequence`, so two deliveries can
  never derive the same sequence, and the loser of the claim does no work at all — it re-reads the
  owner's decision, waits a bounded structural window and converges. An expired claim is taken over by
  exactly one generation, so a worker that died between the event insert and its first decision leaves an
  event that the next delivery or drain completes exactly once.
- [C10-2] Ownership covers the entire decision operation, so an expired generation cannot continue into
  R1/R2. The claim's lease is the bounded window the owner works inside: it is opened before any decision
  work runs, re-proved and renewed immediately before every work unit — the R1 evidence submission and
  each R2 command, every one of which is a single local transaction and never a provider call — and closed
  when the decision is published. The renewal is one conditional statement requiring the owner's own live
  generation **and** an unexpired lease, and it can never resurrect an expired window, so a generation
  that stalled past its lease, or whose claim exactly one successor generation took over, stops *before*
  the unit it was about to run: it performs no decision work, no R1/R2 consequence work and no append, and
  converges on the decision the current owner publishes. The append itself stays fenced by the same
  generation and token inside the transaction that inserts the decision, so the fence now covers the work
  and not merely the row. §17's `stale_owner_after_lease_expiry` proves it. [C12-1] The append *closes*
  that window as part of the same fence: the `claimed → settled` transition also requires the owner's live
  slot and an unexpired lease, judged at the instant the statement itself runs — [C13-1] one database-time
  `UTC_TIMESTAMP()` expression again, taken by a transition that accepts no instant parameter, so neither a
  delay at the append seam nor a late row lock can settle against a stale instant — so a lease that lapsed
  after the final work unit settles nothing there either — the append publishes nothing, the owner releases the live
  claim it appended nothing to (fenced by its own generation and token) and converges, and the next
  delivery completes the event. §17's `stale_owner_at_decision_append` proves that refusal is the lapsed
  lease itself and never a take-over. [C14-1] Because a database-time expression is evaluated once, when its
  statement starts, that verdict is only honest if the transition already holds the row it is judging: the
  transition takes the claim row's lock with its own fenced `SELECT … FOR UPDATE` — where the wait decides
  nothing — before the conditional update, so an append that was queued behind another transaction's lock of
  that row is judged *after* the wait. §17's `append_blocked_on_claim_row` proves it: a separate transaction
  holds the claim row until the owner's live window has lapsed while the owner's append is queued behind it,
  and the queued append settles no claim and appends no decision.
- [C11-1] Ownership covers the *duration* of every work unit, not merely its entry. The window is proved
  — and, outside a transaction, renewed — from inside the unit's own transaction, before every statement
  that transaction runs (§9.5, `DECISION_UNIT_FENCE_FILTER`). The claim row is therefore held for the
  whole transaction a unit runs in, which is what makes the unit and a decision-claim take-over mutually
  exclusive: a take-over needs that same row and an *expired* lease, so it can neither interleave with a
  unit nor land between a unit's last statement and its commit. A unit whose window closed before its next
  statement executes raises the controlled closed-window stop instead of running that statement, so the
  R1/R2 service owning the transaction rolls the whole unit back: the stale generation commits **no**
  statement of the unit it had already started, releases the live claim it appended nothing to, and
  converges. §17's `stale_owner_inside_r1_unit` and `stale_owner_inside_r2_unit` prove it for the R1 and
  the R2 mutation respectively.
- [C3-1] Dispatch ordering and ownership: the dispatch claim — carrying the sealed descriptor of
  [C4-1] — is written inside transaction 1, while the account-root lock is still held and before it is
  released, so the claim and the command commit together and a recovered owner can always rebuild the
  exact request it owns. The claim's `UNIQUE subject_claim` allows exactly one live claim per
  arbitration subject, and the owner takes the claim row lock (`SELECT … FOR UPDATE`) only for the short
  `claimed → in_flight` acquisition, the expired-lease take-over and the `in_flight → settled`
  settlement — never across the provider call. [C4-2] A crashed owner's expired lease is the only thing
  that makes its claim takeable again, and the take-over is a single conditional update that must bump
  `claim_generation` and issue a fresh `claim_token_digest`: exactly one taker can succeed, every
  subsequent write (reconciliation, re-issue and settlement) is conditional on that same generation and
  token, and an owner that loses its fence writes nothing. A taker must reconcile (§8.3) before it may
  re-issue, so two concurrent re-drives of one expired lease yield one taker, one reconciliation and at
  most one mutating call. [C5-2] Because a takeover claim is `in_flight` at a generation greater than
  `1`, the re-issue does not reuse the generation-1 acquisition: the taker must first pass one
  conditional pre-call ownership check that proves its exact generation and token under an unexpired
  lease (and renews it) and that must affect exactly one row, so a taker fenced out between its
  reconciliation and its call writes nothing and calls nothing. [C5-1] A `claimed` claim whose descriptor
  cannot be opened is ended `claimed → released` in the same fenced transaction that appends its
  `refused` result, so an unusable envelope neither strands the command nor holds the subject's live
  slot; a concurrent re-drive that loses that conditional statement writes nothing and reports the
  pending state. [C6-1] That release is reachable *before* a claim can ever be `in_flight`: step 2 runs
  the adapter's non-mutating descriptor preflight while the claim is still `claimed`, and only an `ok`
  verdict permits the `claimed → in_flight` acquisition — so a descriptor failure on an initial
  `submit`/`cancel` is refused, not attempted and not left dispatching (T-D13). [C7-1] That `ok` verdict is
  not enough on its own: Core passes the live claim's stored `idempotency_key_digest` into the preflight
  and re-compares both sealed digests against the durable command and claim rows under the claim lock
  immediately before the acquisition, so an envelope that is internally valid but bound to another claim
  takes the same fenced `refused`/`released` path and never becomes `in_flight`. [C7-2] The descriptor is
  opened exactly once per provider invocation — in that pre-lease preflight — and its `ok` verdict mints
  the one-use capability the single call consumes, so `submit`/`cancel`/`reconcile` never perform a second
  open: a capability the port cannot consume makes no outbound request and ends a provably call-free
  generation-1 claim through the fenced no-call abort that clears the lease and writes the
  `refused`/`dispatch_descriptor_unavailable` result, while a takeover generation keeps the ordinary
  `in_flight` pending behaviour (no call, no attempt, operator exception).
- Event idempotency: the `(provider_key, event_reference_digest)` unique index arbitrates concurrent
  duplicates; a losing writer recovers through the recorded winner and compares
  `event_fact_digest` before converging.
- Webhook intake takes no lock until it must translate into authority (it is not a shared resource);
  the decision step takes the account-root lock and never holds two aggregate locks at once.
- [C3-1] Cross-operation arbitration: `submit`, `cancel` and `reconcile` for one intent (or, without an
  R2 intent, one obligation) share one arbitration subject, so two opposing operations **cannot both be
  in flight**: the second command's transaction-1 claim insert fails the subject's live slot, and it is
  refused durably with `dispatch_in_flight` and makes no provider call — the account-root lock alone no
  longer stands in for the claim. A decision racing an execution command is still ordered by the
  account-root lock; it observes the durable state and records its own reasoned outcome.
- No `cron`, `wp_schedule_event` or `wp_schedule_single_event` is introduced. `drain()` is the explicit,
  idempotent entry point for a later scheduler or worker owned by another phase.

## 15. Legacy and backward compatibility

- No backfill and no inferred provider account, mapping, secret or event.
- With no provider account configured, `submit_collection` refuses with
  `payment_execution_not_authorised` and every existing R1/R2 flow behaves exactly as it does today:
  R1 manual evidence ingestion remains the only settlement path and R2 collection intents remain
  provider-neutral records.
- No R1 or R2 table, column, index, verifier, capability or rule constant changes.
- A Schema 25 installation may run the phase T migration without R2 present (the execution seam and
  webhook are usable for R1 obligations); the `collection_intent` selectors then stay NULL and the R2
  half is simply unavailable. The recommended base nevertheless includes merged R2.

## 16. Notification intents

Phase T adds **no** notification intent. The R2 intent set remains authoritative, and in particular
`MANUAL_RENEWAL_PAYMENT_REQUIRED` covers the student-facing handoff for a manual collection while
`AUTOMATIC_RENEWAL_UPCOMING` / `AUTOMATIC_RENEWAL_CHARGED` / `AUTOMATIC_RENEWAL_FAILED` /
`PAYMENT_FAILED` / `PAYMENT_RECOVERED` cover the automatic and recovery paths. Delivery, templates,
recipients, attempts and transport belong to Phase S and must never be triggered from the adapter; the
webhook path publishes ids only through the existing `platform_outbox` seam.

## 17. Test matrix

| Suite | Required proof |
| --- | --- |
| `tests/phase-2a2t-contract.php` | Schema 29 identity, build shape, migration/verifier call sites, the locked vocabularies (§5.2), capability boundaries and Teacher/Student denial, the exact fifteen-table seam set plus the one-table decision-claim aggregate of §12, digest-only/append-only/no-FK/no-CHECK discipline, [C2-2] the declared identity/reference contract of §12.3 (a declared `id`+`PRIMARY KEY` and `uid`+`UNIQUE` on every table, and a `bigint unsigned` type plus named index for every `*_id` reference), [C3-2] the two declared parent sets of §12.3 and that every `*_id` parent — Phase-T or external authoritative — declares `id`/`PRIMARY KEY(id)` and the `payment_provider_account_commands`/`_object_commands.result_id` specification, [C3-1] the `payment_execution_dispatches` shape (`UNIQUE command_dispatch`, `UNIQUE subject_claim` + `KEY subject`, `DISPATCH_STATES`, no command terminal state, no delete path), the write of the claim inside transaction 1 before the lock release, the `redrive` entry point and its reconcile-before-re-issue order, [C2-6] the non-null `payment_provider_secrets` account scope, [C2-5] the empty `PROVISIONABLE_PROVIDERS` constant and the vault's unconditional write refusal, [C2-1] the absence of any update path for `payment_execution_commands`/`_attempts`/`_results`, absence of provider SDK/`curl_`/raw-payload storage in Core, absence of `cron`/scheduling, the presence of the §11.5 test-vault constant gate, [C2-3] the §9.7 principal check naming all four capabilities and the §10.1 consequence call order, [C2-4] the pre-parse account selector in the route and the single-secret `verify()` signature, [C4-1] the sealed-descriptor contract (the `sealDispatchDescriptor` port method, the five `descriptor_*` claim columns plus `claim_generation`, the locked `DISPATCH_DESCRIPTOR_FIELDS`, and a source scan proving that only an adapter can seal or open an envelope, that no Core class holds an open API for one, and that the seal helper is separate from `PaymentSecretVault::store`), [C7-1]/[C7-2] the claim-binding and single-open contract (the explicit expected-claim-digest parameter, Core's pre-lease digest-comparison order, `ProviderDispatchCapability`, and a source scan proving no port method other than `preflightDispatchDescriptor()` opens an envelope), [C4-2] the structural timeout inequality (`DISPATCH_CALL_TIMEOUT_SECONDS + DISPATCH_LEASE_MARGIN_SECONDS <= DISPATCH_LEASE_SECONDS`) and that both the expired-lease take-over and the settlement are stated as single conditional statements whose affected-row count is the proof of ownership, [C4-3] the worker-context contract (a source scan proving `wp_set_current_user` appears under `src/` only inside `PaymentExecutionWorkerContext`, that the context validates all four capabilities and restores the previous identity in a `finally`, that no Phase T path sets user `1`, adds a role or grants a capability, and that the §8.4 hooks fire after the context has exited), [C8-1] the all-method route plus the unconditional exact-raw-body receipt (both routes declare the one locked method set, no precheck substitutes an empty body, and the receipt stores the digest and byte count of the body it was given), [C8-2] the exact-attribution contract (the active-only mapping lookup, the `obligation`/`collection_intent` canonical-obligation resolution, and the equality between that obligation and the obligation the event resolved), [C8-3] the insert-or-resolve ownership contract (insert-or-resolve returns `created`, a lost unique-event race adopts the winner and routes through the one shared convergence path), [C8-4] the drain fact-digest contract (the full recorded fact digest is recomputed and a mismatch appends the controlled conflict decision), [C9-4] the OPTIONS interception contract (the endpoint's own `rest_pre_dispatch` interception registered ahead of WordPress's own `OPTIONS` handler at a lower priority, answering only an `OPTIONS` delivery to the two shared route shapes and only through the one controlled path), [C10-1] that migration 029 owns the decision-claim aggregate while migration 028 neither requires nor validates it (it tolerates the scheduled sibling instead of failing closed on a database the ledger can repair), and [C10-2] that the decision work-unit gate re-proves and renews the owner's own unexpired window before every R1/R2 unit, can never renew an expired window, and stops a closed-window generation before the work instead of at the append, [C11-1] that the same window is fenced at the connection's declared statement boundary from *inside* the unit's own transaction — the boundary and its unfenced statement set declared once in the rule, the listener registered for exactly one unit and removed again in a `finally`, never rewriting a statement, never fencing its own proof, passing `START TRANSACTION`/`ROLLBACK`/`SET` through untouched, taking its verdict from one `SELECT … FOR UPDATE` row rather than an affected-row count, and renewing the lease only for a statement that runs outside a transaction — that both the R1 submission and every R2 command run as one such fenced unit, and that a generation whose window closed releases the claim it appended nothing to, and that no `commercial_*`/R2 verifier was relaxed |
| `tests/phase-2a2t-migration-runtime.php` | fresh Schema 29 identity and storage; [C10-1] the completed-028 repair rehearsal (028 completed, 029 unrecorded, no claim table) reaching the scheduled repair, and the completed-029/missing-table case failing closed before its ledger-owned repair restores it; retained stale-version (26) advance; repeat safety; partial capability repair; malformed-storage rejection (unknown table, non-InnoDB, mutable append-only column, raw reference column, plaintext secret column, `text`/`varchar` digest, missing index, [C2-2] a table without `id`/`PRIMARY KEY` or without `uid`/`UNIQUE`, a `*_id` column that is not `bigint unsigned` or has no declared index, [C3-2] a `*_id` column whose declared parent is neither a Schema 028 table nor a frozen external authoritative parent, and a parent — Phase-T or external — that does not declare `id bigint unsigned NOT NULL AUTO_INCREMENT`/`PRIMARY KEY(id)` (accepted for a real external parent such as `commercial_offer_obligations.id`, rejected otherwise), [C3-1] a dispatch table missing `UNIQUE command_dispatch`/`UNIQUE subject_claim` or carrying a non-`DISPATCH_STATES` state, [C2-6] a NULL-able secret account scope or a `secret_slot` index with the wrong column list, [C4-1] a `%descriptor%` column on a table other than `payment_execution_dispatches`, and [C4-1]/[C4-2] a claim row with an incomplete sealed envelope, a `descriptor_digest` that disagrees with its envelope, a non-positive `claim_generation`, a `claimed` claim carrying a lease or an `in_flight` claim carrying none); proof that every R1/R2 row and column is unchanged; [C2-6] the active-slot uniqueness probe (a second active row for one scope is rejected, a rotation leaves exactly one active row); [C10-1] the completed-028 repair rehearsal (028 completed, 029 unrecorded and no claim table at all) proving the scheduled 029 installer creates the claim table, records migration 029 and reaches Schema 29, plus the completed-029 case whose claim table has vanished failing closed while the ledger-owned repair restores it |
| `tests/phase-2a2t-runtime.php` | network-free fake adapter through the registry: register account → link mapping → submit execution command for an R1 obligation → the durable dispatch claim makes the command `dispatching` between transaction 1 and transaction 2 (`claimed` before the call, `in_flight` after the short lease transition) → attempt recorded → [C2-1] terminal `completed` result row recorded without any command-row update → verified `payment_succeeded` event → R1 settlement via the existing boundary → [C2-3] ordered R2 consequence (intent `confirmed`, then cycle `collected`) and a proof that `bind_next_term` is therefore reachable; [C2-3] a cycle that is not collectable records `refused`/`renewal_cycle_not_collectable` with no invention and no confirmation of the intent, and a re-driven `drain()` completes a `pending` consequence exactly once; failure path → `payment_failed` → R2 `record_failure`; refund path → `refund_recorded` → review case with NULL academic consequence; unattributed success → R1 `unmatched_payment_evidence`; refusal matrix of §6.4 including `live_execution_not_authorised`, `provider_credentials_unconfigured`, [C3-1] `dispatch_in_flight` and [C4-1] `dispatch_descriptor_incomplete`/`dispatch_descriptor_unavailable`, each proven to leave a durable `refused` result row and never a second port call on replay; [C3-1] a crash before the call and a crash after the call are each recovered by `redrive()` through the same deterministic idempotency key — reconcile-before-re-issue after the crash — producing exactly one attempt, one terminal result row and one settled claim, never a second mutating call; [C4-1] the re-drive rebuilds **every** port input from the immutable command row plus the sealed descriptor, the rebuilt request is identical to the request that authorised the dispatch, a full scan of every Phase T table, option and transient finds no plaintext provider reference outside the sealed envelope, and an unopenable descriptor refuses durably with `dispatch_descriptor_unavailable` on a `claimed` claim while leaving the command `dispatching` with an operator-visible exception on an `in_flight` claim; [C7-1] an initial-dispatch claim-digest mismatch with an otherwise valid descriptor (forced on the claim row) ends in exactly one `refused`/`dispatch_descriptor_unavailable` result, a terminal `released` claim and no call, and a defective adapter that reports `ok` with another claim's sealed pair is caught by Core's own pre-lease comparison; [C7-2] a forced post-preflight capability failure makes no provider call, writes no attempt and ends the generation-1 claim `released` with that refusal, while the same failure on a takeover generation writes nothing and leaves the command `dispatching`; [C4-3] the R1 and R2 rows written on the anonymous intake path record the worker principal's user id and not an administrator's, and the caller's previous identity is restored before the response is produced |
| `tests/phase-2a2t-webhook-runtime.php` | valid signature converges; missing/malformed/wrong-key-version/stale/future signature refused with the exact reason and a durable refused receipt; oversized body, wrong method, wrong content type, unknown provider key, unmapped account and unmapped object all refused without information leak; [C2-4] a missing, unknown, ambiguous, inactive or mode-mismatched account selector is refused before parsing with its exact reason and a receipt that records no account; [C2-4] an unselected second account's secret never verifies a request addressed to another account; duplicate identical event converges with no new decision; duplicate with a materially different payload digest preserves the original, records `conflicting_provider_event` and creates no authority; out-of-order `payment_failed` after settlement is `ignored` as `stale_provider_event` and changes nothing; unset worker principal leaves the event durably `received` and a later `drain()` completes it exactly once; [C4-3] an anonymous intake with a valid signature settles the obligation and confirms the R2 pair **only** through the bounded worker principal — the R1 evidence/settlement actor and the decision's `recorded_by` are the principal's user id — while the request's own identity is `0` both before the translation and after the response is produced, and the §8.4 hook observes the caller's restored identity rather than the worker's; a principal holding an extra administrative capability refuses with `payment_worker_principal_required` and settles nothing, records no R1 evidence and confirms no intent or cycle; the worker context is not re-entrant, and an entry attempt while one is active is refused before any identity change; [C8-1] the registered route declares every HTTP method (so a non-`POST` delivery reaches the handler, is receipted as `method_not_allowed` with `405`, records the exact raw body digest and byte count it carried and never becomes an event), and a request refused before parsing — a wrong content type, or an oversized body refused `413` — is receipted with those same exact bytes rather than a zero-byte substitute; [C8-2] an event whose object is actively mapped to one obligation but whose metadata names another is refused `ambiguous_obligation_attribution` with no evidence, and a superseded/detached mapping is refused `unmapped_provider_object` and never attributes evidence; [C8-4] a `drain()` whose re-delivered body keeps the event id but changes the recorded immutable facts appends the controlled conflict decision, leaves the recorded `event_fact_digest` unchanged, keeps the original deferred decision, submits no R1 evidence and never translates the changed facts; [C9-4] an `OPTIONS` delivery is answered by that interception ahead of WordPress's own `OPTIONS` handler and receipted `refused`/`method_not_allowed` with the exact raw body digest and byte count it carried, writing exactly one receipt and never becoming an event, from both registered route shapes, while another route's `OPTIONS` handling and every `POST` delivery are left untouched |
| `tests/phase-2a2t-secret-runtime.php` | encryption round trip; ciphertext differs from the plaintext; nonce uniqueness across writes; key rotation keeps exactly one active row and preserves history; unknown cipher/key version fails closed; authentication failure records `decrypt_failed` and returns no value; a non-adapter scope cannot reveal; diagnostics/notices/exports/outbox contain `[REDACTED_SECRET]` and never a value; a WordPress salt change fails closed; a full storage scan finds no plaintext secret; [C2-5] **every production write path rejects a provider secret** — a fully capable administrator with a valid nonce is refused `provider_secret_write_not_authorised` for a Stripe `api_key` and for a Stripe `webhook_signing_secret`, an audit `write_refused` row is recorded, no `payment_provider_secrets` row is created, and a source scan proves no path writes that table while `DZN_PLATFORM_PAYMENT_TEST_VAULT` is undefined; [C2-6] a NULL account scope cannot be inserted and two active secrets cannot share one scope |
| `tests/phase-2a2t-corruption-runtime.php` | mutated execution command (selector shape, amount/currency), [C2-1] a mutated or duplicated `payment_execution_results` row (wrong `result_state`, `result_id` naming a foreign attempt, a second row for one command, a `completed` result with no attempt), [C3-1] a mutated dispatch claim (a claim for a command that already has a result row — [C5-1] other than the single permitted pairing of a terminal `released` claim with its own `refused`/`dispatch_descriptor_unavailable` result, a claim whose `dispatch_state` is not a `DISPATCH_STATES` member, a foreign `execution_command_id`, two live claims sharing one arbitration subject, and a `dispatching` command with no live claim), [C4-1] a mutated sealed descriptor (a tampered ciphertext, a `descriptor_digest` that no longer matches its envelope, an envelope whose sealed binding names another command's key digest, and a descriptor transplanted from another command), [C4-2] a claim whose `claim_generation` was rolled back or forged, and a settlement attempted with a stale generation or token (which must write no attempt and no result for the fenced-out owner), [C3-2] a `payment_provider_account_commands`/`_object_commands` row whose `result_id` is NULL, foreign or names another command's event row, mutated attempt, mutated event fact identity, mutated receipt verification state, corrupted account/mode, forged mapping, [C2-6] a secret row with a foreign or NULL account scope, and [C2-3] a decision row whose recorded R2 states disagree with the R2 tables — each fails closed at the owning boundary, manufactures no settlement/authority, is never silently repaired, and converges after exact restoration |
| `tests/phase-2a2t-failure-runtime.php` | injected write boundary at every owning mutation (command insert, dispatch-claim insert, [C2-1] result insert, receipt insert, event insert, decision insert, [C2-3] R2 intent-confirm step, R2 cycle-confirm step, secret write, secret audit, mapping write, account state write) — each fully rolled back with retry convergence and no partial external-call ambiguity, and no partially applied R2 consequence left invisible; [C3-1] a crash after the claim commit and before the port call (claim `claimed`) and a crash after the port call and before transaction 2 (claim `in_flight` with an expired lease) are each recovered by `redrive()` to exactly one attempt and one settled claim, with the post-call crash reconciled rather than re-issued; [C4-1] a crash between the seal and the claim insert leaves neither a command row nor a claim row and no reachable descriptor; [C4-2] a crash between the fenced settle update and the attempt/result insert leaves the claim terminal with no attempt and no result (a recorded, visible integrity fault that is never silently repaired), while a crash after the port call but before settling leaves the claim `in_flight` for a fenced re-drive, and an owner fenced out before its settlement writes no attempt and no result; [C4-3] a failure raised inside the worker execution context still restores the caller's previous identity, and a decision refused for a §10.1 reason still records the worker principal as its `recorded_by` rather than an anonymous actor |
| `tests/phase-2a2t-concurrency-runner.sh` | `duplicate_webhook`, `out_of_order_event`, `submit_vs_cancel`, `settlement_vs_attempt`, `mapping_change_vs_intake`, `secret_rotation_vs_intake`, `unrelated_students`, [C2-1] `duplicate_command_replay`, [C2-3] `settlement_vs_r2_consequence`, [C3-1] `submit_vs_cancel_in_flight` (two opposing operations for one intent race the live `subject_claim` slot: exactly one dispatches and the loser is refused durably with `dispatch_in_flight`, never two provider calls), [C3-1] `redrive_after_crash` (a concurrent `redrive()` on an expired `in_flight` claim yields exactly one mutating dispatch and one reconciled attempt, never two calls), [C4-2] `concurrent_expired_lease` — the required round-4 case: two `redrive()` calls observe one expired `in_flight` claim simultaneously; exactly one take-over succeeds (the other's conditional update affects `0` rows, so it writes nothing, calls nothing and reports the pending state), exactly one reconciliation is performed, at most one mutating call is issued, and the command ends with exactly one attempt, one terminal result row and one settled claim; [C4-2] `fenced_settlement_lost` (an owner whose lease is taken over between its call and its settlement records no attempt and no result, and the taker's reconciliation adopts the provider state once); [C7-2] `post_preflight_capability_failure` (two concurrent owners on one generation-1 `in_flight` claim both observe the pre-call capability refusal: exactly one fenced no-call abort affects `1` row, the loser affects `0`, writes nothing and reports the pending state, and the command ends with one refused result, no attempt and no provider call); [C8-3] `duplicate_webhook` is a real delivery race: two workers announce themselves in the gate directory, wait for each other and then submit one provider event identity simultaneously (neither holds a lock), and the verifier proves one recorded event, one decision, exactly one R1 settlement and evidence, one confirmed intent, one collected cycle and no conflict decision — so a worker that loses `UNIQUE provider_event` converges instead of translating; [C8-3] `conflicting_duplicate_webhook` re-delivers the same event identity with materially different recorded facts and proves one event, exactly two decisions of which exactly one is `conflicting_provider_event`, still exactly one R1 settlement/evidence, and a recorded event whose `payload_digest` is one of the two deliveries; [C10-2] `stale_owner_after_lease_expiry` — the owner takes the claim for an owed decision and then lets its own bounded lease lapse while it still owns it: the successor generation takes the claim over and completes the decision, the resumed stale generation is refused by the work-unit gate before its first R1/R2 unit and therefore performs no decision work, no R1/R2 consequence work and no append, and exactly one R1 settlement/evidence, one confirmed intent, one collected cycle, one decision and one settled generation-2 claim remain; [C11-1] `stale_owner_inside_r1_unit` and `stale_owner_inside_r2_unit` — the round-11 cases: the owner stalls *inside* the R1 evidence submission (respectively the R2 collection-intent confirmation) with the window that unit is running inside aged past expiry while the contender delivers the same event, so the contender owns nothing and works nowhere, the stalled generation's unit is rolled back *from inside its own transaction* (the successor's recorded observation shows no R1 evidence and no settlement at all in the R1 case, — after which the successor's own R1 and R2 units apply the ordered consequence exactly once (one confirmed intent, one collected cycle) — and in the R2 case the R1 unit that finished inside its window stays committed while the intent is still `submitted`, the cycle is still `payment_required` and neither a confirmation event nor a confirmation command exists, so that event's deliberately older occurrence instant makes the successor's re-decision the controlled `stale_provider_event` refusal and no generation confirms or collects anything), that generation releases its lapsed claim instead of holding the event, and exactly one R1 evidence/settlement, one decision appended by the successor, one released generation-1 claim and one settled successor claim remain; [C12-1] `stale_owner_at_decision_append` — the round-12 case: the owner completes every R1/R2 work unit of the decision operation and then lets the window it still exclusively holds lapse *at the append seam*, with no successor having taken its claim over, so the fenced `claimed → settled` transition of the append itself is what must refuse it — proven by that owner own generation-1 claim ending `released` with no live slot and by the absence of any generation above 1; the owner appends nothing, reports the event as still owing its decision and releases the live claim it appended nothing to, and the next delivery is the one that completes the event with exactly one decision (the controlled `stale_provider_event` refusal of the obligation the stale generation own committed R1 settlement already covers), while exactly one R1 evidence/settlement, one confirmed intent and one collected cycle stand from the work that generation did inside its window |
| Adjacent regressions | Phase L, M, M0, N, O, P, Q, R1 and R2 runtime suites re-run green (P and Q only where their pre-existing fixture-order limitations are recorded honestly) |

Correction round 5 adds these required proofs, each inside the suite whose row already owns the
subject:

- [C5-1] `tests/phase-2a2t-contract.php` proves `DISPATCH_STATES` is exactly
  `claimed`/`in_flight`/`settled`/`released`, that §8.2 rule 5 admits exactly one claim/result pairing,
  and that the descriptor-refusal release is written as a single conditional `claimed → released`
  statement that clears `active_claim_slot`. `tests/phase-2a2t-runtime.php` proves the behaviour: a
  `claimed` claim whose envelope cannot be opened yields exactly one `refused`/
  `dispatch_descriptor_unavailable` result and a terminal `released` claim in one commit, and a later
  command for the same arbitration subject is then admitted (the slot was released) rather than refused
  `dispatch_in_flight`. `tests/phase-2a2t-corruption-runtime.php` adds a `released` claim beside a
  `completed` result, a `settled` claim beside any result, and a live (`claimed`/`in_flight`) claim
  beside any result — each fails closed and is never silently repaired. (The legitimate succession is
  the opposite case and must *not* fail: once a claim is terminal, a later command for the same
  subject inserts its own new live claim.)
  `tests/phase-2a2t-migration-runtime.php` adds a `released` claim that carries a `lease_expires_at`
  (rejected, §12.4).
  `tests/phase-2a2t-failure-runtime.php` adds the crash between the release update and the result insert
  (a terminal claim with no result is a recorded, visible integrity fault that is never silently
  repaired and leaves the subject's slot free).
- [C5-2] `tests/phase-2a2t-contract.php` proves the takeover re-issue is gated by the conditional
  pre-call ownership check rather than by the generation-1 acquisition, and that the check compares
  `claim_generation`, `claim_token_digest` and an unexpired `lease_expires_at` in one statement.
  `tests/phase-2a2t-concurrency-runner.sh` extends `concurrent_expired_lease` with
  `takeover_reissue_fenced`: a winner whose lease expires between its reconciliation and its pre-call
  check gets `0` affected rows, issues no call and writes nothing, and the successor reconciles and
  re-issues at most once — with the command ending at exactly one attempt, one terminal result and one
  settled claim.
- [C5-3] `tests/phase-2a2t-contract.php` proves the locked `DISPATCH_DESCRIPTOR_FIELDS` contains
  `command_key_digest` and `idempotency_key_digest`, that `PaymentExecutionRequest` carries
  `command_key_digest` as a durable field, that the preflight receives the live claim's stored
  `idempotency_key_digest` as its expected value, and that [C7-1] Core re-compares both sealed digests
  against the durable command and claim rows under the claim lock **before** the acquisition — the
  comparison is Core's, ordered before the lease, and is not left to the port call.
  `tests/phase-2a2t-runtime.php` and
  `tests/phase-2a2t-corruption-runtime.php` prove the binding: a descriptor transplanted onto another
  command's claim, and a sealed pair that names another command's key digest or another claim's
  idempotency-key digest, are each refused `dispatch_descriptor_unavailable` with no provider call.

Correction round 6 adds this required proof, inside the suites whose rows already own the subject:

- [C6-1] `tests/phase-2a2t-contract.php` proves the pre-call preflight contract: the
  `preflightDispatchDescriptor()` port method exists and is adapter-implemented,
  `DESCRIPTOR_PREFLIGHT_STATES` is exactly `ok`/`dispatch_descriptor_unavailable`, §8.3 step 2 states the
  order preflight → `claimed → in_flight` acquisition → port invocation, and a source scan proves the
  preflight performs no outbound call and no storage write (it is neither `submit` nor `cancel` nor
  `reconcile`, and it reaches no `wp_remote_*`/`curl_*`/`$wpdb` write). `tests/phase-2a2t-runtime.php`
  proves the initial-dispatch descriptor failure end to end: a fake adapter whose
  `preflightDispatchDescriptor()` returns `dispatch_descriptor_unavailable` — and, separately, one whose
  `submit` would otherwise be reached — makes a first `submit_collection`/`cancel_collection` command end
  with exactly one `refused`/`dispatch_descriptor_unavailable` result, a terminal `released` claim with
  `active_claim_slot = NULL`, a released subject slot (a later command for the same subject is admitted,
  not refused `dispatch_in_flight`), **no** `payment_execution_attempts` row and **no** provider call —
  proving the claim was never `in_flight` and the command was neither left permanently `dispatching` nor
  recorded as an attempted/settled outcome. `tests/phase-2a2t-concurrency-runner.sh` adds
  `initial_dispatch_descriptor_failure`: two concurrent first dispatches of one command both observe the
  claim `claimed`, the preflight fails, and exactly one fenced `claimed → released` update affects `1`
  row (the loser affects `0`, writes nothing and reports the pending state), leaving exactly one refused
  result, no attempt and no provider call, with the claim terminal `released` and the subject's slot
  free.

Correction round 7 adds these required proofs, inside the suites whose rows already own the subject:


- [C7-1] `tests/phase-2a2t-contract.php` proves the claim-binding contract: `preflightDispatchDescriptor()`
  takes the expected claim `idempotency_key_digest` as an explicit parameter,
  `DispatchDescriptorPreflight` exposes the two sealed digests plus the capability, and §8.3 step 2 states
  the order preflight → Core's locked digest comparison → `claimed → in_flight` acquisition → port
  invocation, naming the comparison's two equalities and routing any mismatch to the fenced
  `claimed → released` refusal. `tests/phase-2a2t-runtime.php` proves the initial-dispatch claim-digest
  mismatch with an otherwise valid descriptor: a fake adapter whose preflight opens the envelope and finds
  a sealed `command_key_digest` equal to the command row's, while the claim row's stored
  `idempotency_key_digest` has been forced (fixture write) to differ from the sealed value and from the
  request's derived key digest, ends a first `submit_collection` with exactly one
  `refused`/`dispatch_descriptor_unavailable` result, a terminal `released` claim, `lease_expires_at
  NULL`, `active_claim_slot NULL`, a free subject slot, **no** `payment_execution_attempts` row, **no**
  provider call and no observation of the claim `in_flight`. The same suite proves the Core-comparison leg
  directly: a defective fake whose preflight reports `ok` with a sealed pair belonging to another claim
  (so only Core's comparison can catch it) is refused identically, before the acquisition.
- [C7-2] `tests/phase-2a2t-contract.php` proves the single-open contract: `ProviderDispatchCapability`
  exists, `submit`/`cancel`/`reconcile` take it instead of a `ProviderDispatchDescriptor`, a source scan
  proves no port method other than `preflightDispatchDescriptor()` opens an envelope, and the abort is
  stated as one conditional `in_flight → released` statement that requires `claim_generation = 1`, the
  owner's own `claim_token_digest` and an unexpired lease, and clears `lease_expires_at`.
  `tests/phase-2a2t-runtime.php` proves the forced post-preflight open/validation failure: a fake adapter
  whose preflight returns `ok` and mints a capability, but whose post-preflight validation of that
  capability then fails as if the opened envelope state were no longer usable (a consumed, unresolvable or
  otherwise invalid handle), so the port issues no request and reports
  `not_attempted` / `dispatch_descriptor_unavailable`. That makes the command
  end with exactly one `refused` result, a terminal `released` claim with `lease_expires_at NULL` and
  `active_claim_slot NULL`, a free subject slot (the next command for that subject is admitted),
  **no** `payment_execution_attempts` row, **no** provider call (the fake's call counter is `0`) and a
  derived command state of `refused` — never `dispatching` and never a `completed` result. The same suite
  proves the takeover-generation counterpart: the identical forced failure on a claim taken over at
  `claim_generation = 2` writes no refusal, no attempt and no call, leaves the command durably
  `dispatching` with the operator-visible exception, and is **not** released, because the abort is never
  available to a takeover generation. `tests/phase-2a2t-concurrency-runner.sh` adds
  `post_preflight_capability_failure`: two concurrent owners on one generation-1 `in_flight` claim both
  observe the pre-call capability refusal, and exactly one fenced no-call abort affects `1` row (the loser
  affects `0`, writes nothing and reports the pending state), leaving exactly one refused result, no
  attempt and no provider call. `tests/phase-2a2t-corruption-runtime.php` adds a `released` claim at
  `claim_generation > 1`, a `released` claim that still carries a `lease_expires_at`, and a `refused`
  result beside a live (`claimed`/`in_flight`) claim — each fails closed and is never silently repaired.
  `tests/phase-2a2t-failure-runtime.php` adds a crash between the abort's conditional release update and
  its result insert (a terminal claim with no result is a recorded, visible integrity fault that is never
  silently repaired and leaves the subject's slot free).

Correction round 8 adds these required proofs, each inside the suite whose row already owns the subject:

- [C8-1] `tests/phase-2a2t-contract.php` proves the single locked webhook method set covers both routes, the
  intake is called with the raw body unconditionally, and no precheck substitutes a rewritten body;
  `tests/phase-2a2t-webhook-runtime.php` proves the behaviour end to end — the registered route declares
  every method, a `GET` delivery is answered `405`/`method_not_allowed`, the receipt carries the exact
  bytes and byte count of a refused delivery (including an oversized body and a wrong content type), and a
  refused delivery never becomes an event.
- [C8-2] `tests/phase-2a2t-contract.php` proves the loose historical-mapping check is gone: the intake
  resolves active mappings only (`active_slot = 1`), resolves the canonical obligation of an `obligation`
  or `collection_intent` mapping, and requires it to equal the obligation the event resolved; the
  repository exposes both lookups. `tests/phase-2a2t-webhook-runtime.php` proves the behaviour: a signed
  event whose object is mapped to one obligation while its metadata names another is refused
  `ambiguous_obligation_attribution` with no evidence row and no disturbance of either obligation, and a
  mapping detached to a historical row is refused `unmapped_provider_object`.
- [C8-3] `tests/phase-2a2t-contract.php` proves insert-or-resolve reports ownership (`created`) and that
  both the read-duplicate and the lost-insert-race paths return through the one `convergeExisting()` path;
  `tests/phase-2a2t-concurrency-runner.sh` proves the race itself with `duplicate_webhook` and
  `conflicting_duplicate_webhook` (one event identity, two simultaneous deliveries, one event, one
  decision or one controlled conflict, one settlement and never a second R1/R2 consequence).
- [C8-4] `tests/phase-2a2t-contract.php` proves the drain recomputes the full recorded fact digest and
  routes a mismatch to the controlled conflict recorder; `tests/phase-2a2t-webhook-runtime.php` proves
  the behaviour: a validly signed body with the same event id but changed facts appends
  `conflicting_provider_event`, preserves the recorded event and its first decision, submits no evidence,
  and is never translated by a later identical drain. [C9-1] The *changed* facts are never translated:
  the conflict record is not the event's decision, so a later delivery of the **recorded** facts still
  completes the decision the event owes, exactly once, while the conflict row stays recorded.

Correction round 9 adds these required proofs, each inside the suite whose row already owns the subject:

- [C9-1] `tests/phase-2a2t-contract.php` proves the owed-decision rule: no decision at all is an owed
  decision, the decision timeline excludes conflict records, and the converged-`recorded` shortcut of the
  round-8 candidate is gone. `tests/phase-2a2t-webhook-runtime.php` proves the behaviour: an event that is
  durably recorded and then has no decision row at all is completed by a redelivery (`translated`,
  appended exactly once, a repeated delivery appends nothing more), and the diagnostics report no live
  claim afterwards. `tests/phase-2a2t-concurrency-runner.sh` adds `undecided_event_recovery`, where two
  workers deliver that body simultaneously: one event, one decision, one settled claim, one R1
  settlement/evidence and one confirmed intent/collected cycle.
- [C9-2] `tests/phase-2a2t-contract.php` proves the claim contract: the aggregate exists with its
  `DECISION_CLAIM_STATES` vocabulary, lease and wait constants, the unique live-slot index, the fenced
  `claimed → settled` transition **before** the decision-sequence allocation, the expired-lease takeover
  that advances the generation, the loser's convergence, and that no decision is appended outside the
  fence. `tests/phase-2a2t-webhook-runtime.php` proves the claim's own shape — settled with no live slot
  and no lease, no event holding two live claims, an abandoned claim taken over exactly once with
  generation `2` before the decision is completed — and `tests/phase-2a2t-concurrency-runner.sh` adds
  `pending_decision_retry`: two workers deliver one body for an event whose decision is still `pending`,
  and exactly one terminal decision, one settled claim, one R1 settlement/evidence and one confirmed
  intent/collected cycle result, with no second translation or consequence.
  `tests/phase-2a2t-failure-runtime.php` proves the fence's atomicity: a rollback between the settle update and the decision insert
  leaves the claim live and no decision, an expired claim is taken over by exactly one generation, and a
  stale generation never takes it twice; [C12-1] an append that runs after the claim's lease has lapsed
  settles nothing and leaves the claim live for that generation to release or for exactly one successor to
  take over. `tests/phase-2a2t-corruption-runtime.php` adds the mutated claim
  shapes — unknown state, live claim without its lease, terminal claim that kept its live slot, malformed
  token — each of which fails closed on the aggregate proof and is never repaired.
- [C9-3] `tests/phase-2a2t-contract.php` proves the transport contract in source: the locked
  `HTTPS_PROXY_HEADERS` allowlist, the operator configuration option, the two rule helpers, the
  controller's input-only verdict, and that the controller names no proxy header outside the allowlist.
  `tests/phase-2a2t-webhook-runtime.php` proves the matrix: direct TLS passes, the local development
  environment passes, a configured allowlisted header passes (in both WordPress header spellings and for
  a proxy chain whose leftmost element is `https`), and `http`, an empty value, an unconfigured site and a
  non-allowlisted header each fail; plus one end-to-end REST delivery with the configured header, which
  is receipted with its exact raw body and never as `https_required`.
- [C9-4] `tests/phase-2a2t-contract.php` proves the interception contract in source: the endpoint's own
  `rest_pre_dispatch` interception registered at a priority below WordPress's `OPTIONS` handler, the one
  shared declaration of the two route shapes, and that a matched delivery runs the one controlled path
  rather than a second refusal implementation, while only an `OPTIONS` delivery is ever intercepted.
  `tests/phase-2a2t-webhook-runtime.php` proves the behaviour end to end: the interception is registered
  ahead of `rest_handle_options_request()` on the live hook, the same pre-dispatch chain the core handler
  shares answers this endpoint's `OPTIONS` delivery with the controlled `405`/`method_not_allowed`
  refusal, exactly one durable receipt carries the exact raw body digest and byte count, no event is
  created, the bare provider route is receipted identically, an untrailingslashed path is receipted too,
  another route's `OPTIONS` delivery and every `POST` delivery are left to the chain, and an `OPTIONS`
  delivery another filter already answered is never re-answered here. The suite's disposable account
  selector fits the route's declared account segment (`[A-Za-z0-9_-]{1,32}`), so a routed delivery
  actually reaches the endpoint it is meant to exercise.

Correction round 10 adds these required proofs, each inside the suite whose row already owns the subject:

- [C10-1] `tests/phase-2a2t-contract.php` proves the migration split in source: migration 028's installer
  creates exactly the fifteen declared seam tables once each, migration 029's installer creates exactly
  the one decision-claim table, the two sets are disjoint and their union is the declared sixteen-table
  Phase-T set, migration 029 is scheduled in the ledger and is on the retained/current-schema path like
  every other phase migration, and migration 028's verifier neither requires nor validates the claim
  aggregate (it tolerates the scheduled sibling). `tests/phase-2a2t-migration-runtime.php` proves the
  repair behaviourally: fresh Schema 29 storage, repeat safety, a completed-028 database with 029
  unrecorded and no claim table at all reaching the scheduled repair (claim table created, migration 029
  recorded, schema option advanced to 29), a completed-029 database whose claim table has vanished
  failing closed on the claim verifier while the ledger-owned repair restores it, and the retained
  26 → 29 / stale-version path still re-verifying before it advances.
- [C10-2] `tests/phase-2a2t-contract.php` proves the ownership-window contract in source: the repository's
  single conditional renewal requires the owner's own `claim_generation`, `claim_token_digest` and
  `active_claim_slot = 1` **and** an unexpired `lease_expires_at`, the intake opens the window before any
  decision work, gates both the R1 submission and every R2 command on it, and converts a closed window
  into the ordinary convergence path instead of a failure. `tests/phase-2a2t-concurrency-runner.sh` adds
  `stale_owner_after_lease_expiry`: the first worker takes the event's decision claim, lets its own lease
  lapse while it still owns it and then waits for the contender's result; the second worker takes the
  claim over (proven by the settled generation-2 claim) and completes the decision; and the resumed first
  worker is refused at the gate before its first R1/R2 unit — the suite records every worker's arrival at
  the inherited R1/R2 work hooks, so it asserts that the stale worker reached no work boundary at all
  while the takeover generation did, that the stale worker appended nothing, and that exactly one R1
  settlement/evidence, one confirmed intent, one collected cycle and one decision exist.

Correction round 11 adds these required proofs, each inside the suite whose row already owns the subject:

- [C11-1] `tests/phase-2a2t-contract.php` proves the in-unit fence in source: the statement boundary and
  the statements a fence must never block are each declared once in `PaymentExecutionRule`
  (`DECISION_UNIT_FENCE_FILTER`, `DECISION_UNIT_UNFENCED_STATEMENTS`); the repository's window fence proves
  the window with one `SELECT … FOR UPDATE` of the claim row (own `claim_generation`, own
  `claim_token_digest`, `claim_state = 'claimed'`, `active_claim_slot = 1`, an unexpired lease), takes its
  verdict from that read rather than from an affected-row count, renews only when the caller asked for a
  renewal (never for a statement inside a transaction), and refuses a closed window without writing a
  lease at all; the intake runs both the R1 submission and every R2 command as one `decisionWorkUnit()`,
  registers the fence on that boundary for exactly the unit's duration and removes it again in a `finally`,
  never fences its own proof statement, passes transaction control and session configuration through
  untouched, and turns a window that closed mid-unit into the controlled closed-window stop that releases
  the claim and converges.
  `tests/phase-2a2t-concurrency-runner.sh` adds `stale_owner_inside_r1_unit` and
  `stale_owner_inside_r2_unit` (twenty-two modes): in each, the first worker takes the event's decision
  claim, reaches the R1 evidence submission (respectively the R2 collection-intent confirmation) and, from
  *inside that unit's own transaction*, lets the window the unit is running inside lapse past expiry while
  a second worker delivers the same event concurrently. The verifier proves that the contender that cannot
  own the claim performed no work and appended nothing, that the stalled generation released its lapsed
  claim instead of holding the event for the rest of a lease nobody was working inside, and that the next
  generation completed the decision exactly once. Crucially it proves the stale generation's unit was
  rolled back **inside its own transaction**: the successor's recorded observation of the state between
  the two deliveries shows no R1 evidence and no settlement at all in the R1 case (so the stalled R1
  transaction committed nothing) — after which the successor's own R1 and R2 units apply the ordered
  consequence exactly once (one confirmed intent, one collected cycle) — and, in the R2 case, the R1 unit
  that finished inside its window stays committed while the collection intent is still `submitted`, the
  cycle is still `payment_required` and neither a confirmation event nor a confirmation command exists (so
  the stalled R2 transaction committed nothing); because that event's own occurrence instant is older than
  the settlement its own R1 unit committed, the successor's re-decision is then the controlled
  `stale_provider_event` refusal, and no generation confirms or collects anything. Both cases leave
  exactly one R1 evidence/settlement, one decision appended by the successor, one released generation-1
  claim and one settled successor claim.

Correction round 12 adds these required proofs, each inside the suite whose row already owns the subject:

- [C12-1] `tests/phase-2a2t-contract.php` proves the appended-window contract in source: the repository's
  `claimed → settled` transition requires, in its one conditional statement, the owner's own
  `claim_state = 'claimed'`, its `claim_generation`, its `claim_token_digest`, its `active_claim_slot = 1`
  **and** a non-null, unexpired `lease_expires_at`; and the intake, on that transition affecting zero rows,
  releases the live claim it appended nothing to and *then* converges, after the append seam it exposes
  through `dzn_phase_2a2t_before_provider_event_decision_append`.
  `tests/phase-2a2t-failure-runtime.php` proves the transition behaviourally: an append that runs past the
  claim's lease settles nothing and leaves the claim live, for this generation to release or for exactly one
  successor to take over.
  `tests/phase-2a2t-concurrency-runner.sh` adds `stale_owner_at_decision_append` (twenty-three modes): the
  first worker takes the event's decision claim for an owed decision, completes **every** R1/R2 work unit of
  the decision operation, and then lets the window it still exclusively holds lapse at the append seam, with
  no successor generation having taken its claim over. The verifier proves the
  owner reached the R1/R2 work boundaries (so the window closed at the append, never before the work), that
  the append — and never a take-over — refused the stale generation: its own generation-1 claim ends
  `released` with no live slot and no lease, no generation above 1 exists, and no live claim survives; that
  the stale generation appended nothing and reported the event as still owing its decision; and that the
  next delivery is the generation that completes the event with its single decision, while the work the
  stale generation committed inside its window stands exactly once (one R1 evidence, one R1 settlement, one
  confirmed collection intent, one collected renewal cycle).

Correction round 13 adds these required proofs, each inside the suite whose row already owns the subject:

- [C13-1] The append is fenced at the instant it runs, never at an instant its caller read before the append
  seam. `tests/phase-2a2t-contract.php` proves it in source: the repository's `claimed → settled` transition
  requires `lease_expires_at IS NOT NULL AND lease_expires_at >= UTC_TIMESTAMP()`, stamps
  `settled_at`/`updated_at` with that same `UTC_TIMESTAMP()`, and takes **no** instant parameter, so the
  window verdict and the settlement can never be derived from two different instants; the intake calls it
  with the claim identity alone and reads the instants it records on the appended row only after the seam.
  `tests/phase-2a2t-failure-runtime.php` proves it behaviourally with a *real* delay: a claim taken with a
  short, still-live window is settled only after the clock has genuinely passed that window, and the
  statement settles nothing and leaves the claim live — a caller that had captured its own clock before the
  delay would have settled it. `tests/phase-2a2t-concurrency-runner.sh`'s `stale_owner_at_decision_append`
  now lets the owner's own window lapse **by real elapsed time**, without the claim row being written at all
  (the mode therefore runs for the structural 120-second lease), and its verifier proves the lapse was a real
  one: the instant the owner read from its own live claim at the seam is that claim's structural window —
  at least the full `DECISION_CLAIM_LEASE_SECONDS` forward of the instant the claim was taken, never an
  instant written into the past — and the release that follows the refused append lands strictly after it,
  beside the existing proof that no successor generation replaced the stale one, that the stale generation
  appended nothing, and that the next delivery completes the event exactly once.

Correction round 14 adds this required proof, inside the suite whose row already owns the subject:

- [C14-1] The append's window is judged only once the claim row is held, because a database-time expression
  is evaluated once, when its statement starts. `tests/phase-2a2t-contract.php` proves it in source: the
  repository's `claimed → settled` transition takes the claim row's lock with its own fenced `SELECT … FOR
  UPDATE` — which carries the same claim identity, live slot and unexpired `lease_expires_at >=
  UTC_TIMESTAMP()` predicate as the update — and runs the conditional `SET claim_state='settled'` only after
  that locking read, so the statement that waits for another transaction's lock of the row is never the
  statement that judges the window. `tests/phase-2a2t-concurrency-runner.sh` adds
  `append_blocked_on_claim_row` (twenty-four modes): a separate transaction — the second worker — holds the
  owner's claim row under `SELECT … FOR UPDATE` until the live window the owner's append was granted has
  lapsed, while the owner holds at the append seam and then enters the append transaction, whose first
  statement is that fenced locking read, so the append is queued behind that lock *inside* the window it was
  granted. The verifier proves the queued wait was attributed to exactly that claim row — through
  `performance_schema.data_lock_waits` joined to `data_locks` on the claim table's `PRIMARY` index with the
  lock data equal to the claim id, or, where the runtime does not expose those tables, through InnoDB's
  `LOCK WAIT` state with the waiting statement naming the claim table — that the queue was observed before
  the window lapsed while the lock was released only after it had, and that the queued append settled **no**
  claim and appended **no** decision: the states that a transition judging the window with the instant it
  began waiting would have turned into one settled claim and one published decision. The owner's own
  generation-1 claim ends `released` with no live slot and no lease, no generation above 1 exists, the owner
  reports the event as still owing its decision, and the delivery that follows the release completes the
  event with exactly one decision — the controlled `stale_provider_event` refusal of the obligation the
  owner's in-window R1 settlement already covers — while exactly one R1 evidence/settlement, one confirmed
  intent and one collected cycle stand from the work that owner did inside its window.

Fresh-install, 26 → 29 upgrade, idempotency, webhook and representative runtime tests are mandatory
acceptance gates. Every suite must run on the disposable WordPress + MariaDB runtime used by R1/R2,
with no network access: the fake adapter and the constant-gated test vault are the only providers the
tests may use.

## 18. Recommended implementation task identity

| Field | Value |
| --- | --- |
| Task ID | `PHASE-2A-2T-PAYMENT-EXECUTION-SEAM-STRIPE-ADAPTER` |
| Branch | `phase-2a2t-payment-execution-seam-stripe-adapter` |
| Base | `main` with Phase 2A.2-R2 merged (Schema 26); R1 is already authoritative |
| Dependency | PLATFORM-LOCAL-TEST-RUNTIME green (fresh + runtime + webhook + concurrency) |
| Schema | 029 / `028_payment_execution_seam_provider_adapter` plus `029_payment_event_decision_claim_authority` |
| Build | `phase2a2t-payment-execution-seam-stripe-adapter-20260925.14` (correction round 14) |
| Review posture | single coherent candidate, dual-owner independent review, additive-only descendants |

## 19. Pre-implementation prerequisites

1. **Confirm the Schema 027 reservation.** No document in this repository assigns 027; the owner
   designated 028 for Phase T. The intervening slice (notification/transport, Phase S) must be
   recorded as owning 027, or the ledger must be shown to skip 027 deliberately, before migration 028
   is written.
2. **Merge R2 / Schema 26**, or explicitly scope the first candidate to the R1-only half of this
   contract (execution seam + webhook + secrets) with the `collection_intent` selectors never used.
3. **Owner sign-off on the provider boundary**, not on provider policy: confirm that the first
   enabled provider is Stripe, that `LIVE_EXECUTION_PROVIDERS` stays empty for this phase, and that no
   credential will be provisioned.
4. **Resolve the four R2 §4 product decisions** only to the extent this phase touches them — the
   provider recurring model, automatic-charge lead time, recovery threshold and refund consequence
   must stay unresolved; the contract requires that they are *recorded as unresolved*, not decided.
5. **Close the stale-documentation debt** (README, continuity record, architecture, module boundaries,
   migration strategy, policy registry, R1 phase doc, data model, changelog) to the R2-merged state, so
   a reviewer is not reading Schema 24/25 prose.
6. **Provision the service principal** described in §9.7 as an operational precondition (a
   non-privileged account holding **[C2-3]** exactly the four bounded capabilities —
   `dzn_ingest_payment_provider_events`, `dzn_ingest_commercial_payment_evidence`,
   `dzn_manage_collection_intents`, `dzn_manage_renewal_cycles` — and no administrative capability);
   without it the webhook records events but translates nothing. [C4-3] The account must also exist and
   be active, because the worker execution context of §9.7 establishes it directly for the R1/R2
   consequence calls and refuses — settling nothing — when the option is unset, the user is missing or
   inactive, any one of the four capabilities is absent, or any administrative capability is present.
7. **Green disposable runtime** for R1/R2 before the Phase T suites are added.

None of these prerequisites block writing this contract; they gate execution.

## 20. Open owner decisions (deliberately not chosen here)

| Decision | Why Phase T must not choose it | What Phase T ships instead |
| --- | --- | --- |
| Which provider model carries renewals (Stripe Billing subscription vs. per-Term payment links vs. manual links) | It determines commercial behaviour, not transport | `provider_recurring_semantics_unresolved` refusal; `subscription` mappings recorded but inert |
| Automatic-charge lead time (`AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME`) | R2 owns the key; it is unset | No advance charge instant; automatic collection without a policy instant does not exist |
| Payment recovery thresholds (`PAYMENT_RECOVERY_POLICY`) | R2 owns the key; it is unset | Failure events are recorded; no lapse, capacity stays protected |
| Refund execution authority and academic consequences | Refund consequence is a product decision | Refunds only recorded and routed for review; `academic_consequence` NULL |
| Manual-renewal customer flow (Checkout Session vs. Payment Link vs. hosted invoice) | It is a provider/U X contract with the academy | The seam is flow-agnostic; only the normalised outcome is stored |
| Provider customer identity policy (when a provider customer is created, and how it maps) | Identity/support policy | Mapping registry only; no creation path in this phase |
| 3-D Secure / authentication ownership | Customer-experience policy | `requires_action` is recorded as a neutral outcome; nothing acts on it |
| Whether a live account is ever enabled, and by whom | Production authority | Always refused while `LIVE_EXECUTION_PROVIDERS` is empty |
| Data retention for receipts, events and attempts | Retention policy | Append-only storage with documented fields; no purge path |

## 21. Exclusions and explicit non-authorisation

This contract authorises no live Stripe API call, no API key, no webhook secret value, no provider
account provisioning, no credential provisioning, no charge, no refund, no payout, no provider
dashboard change, no deployment, no production access, no production cutover, no notification
delivery, no Theme/NIU change, no Amelia write or removal, and no merge. It creates no Term,
Enrolment, Lesson, schedule, attendance outcome, academy obligation, funding plan, protected claim or
notification record. It decides no renewal, recovery or refund policy.

## 22. Definition of done

- Schema 028/029 and their verifiers are additive, preserve Schemas 25/26 unchanged, and are repeat-safe.
  [C10-1] The decision-claim aggregate belongs to `029_payment_event_decision_claim_authority`, so a
  database that already completed the fifteen-table `028` is repaired by the scheduled migration instead
  of failing closed on a table no migration would create, and its verifier runs before the schema option
  may advance to 29. [C10-2] The claim's lease is the bounded window the owner re-proves and renews
  before every R1/R2 work unit, so an expired generation performs no further work at all. [C11-1] That
  window is also proved — and, outside a transaction, renewed — from inside the unit's own transaction,
  before every statement it runs, so the claim row is held for the whole transaction a unit runs in, a
  take-over can never interleave with one, and a unit whose window closed mid-transaction is rolled back
  by its own R1/R2 service: the stale generation commits no statement of that unit, releases the claim it
  appended nothing to, and converges.
- The provider-neutral seam, the mapping registry, the execution command authority, the webhook
  intake, the secret vault and the Stripe translation are implemented exactly as specified here.
- [C2-1] The execution command's evidence is genuinely append-only: the command row is immutable, the
  terminal state lives only in `payment_execution_results`, and no code path updates either table.
  [C3-1] The only mutable execution row is the dispatch claim of §8.3, and it stores no command
  terminal state.
- [C2-2] Every Schema 028 table has a declared `id` primary identifier, and every `*_id` reference has
  the exact type, parent table, index and application-enforced ownership rule of §12.3. [C3-2] Every
  declared parent is either a Schema 028 table or a frozen external authoritative parent, each proved
  to declare `id`/`PRIMARY KEY(id)`, and the previously unspecified
  `payment_provider_account_commands`/`_object_commands.result_id` is fully specified (parent = the
  produced event row).
- [C3-1] No command is ever unowned between its two transactions: the durable per-command dispatch claim
  is committed with the command and before the account-root lock is released, a crash before or after
  the provider call is recovered by the idempotent `redrive()` reconcile-before-re-issue protocol using
  the same deterministically re-derivable idempotency key, one command yields at most one attempt and
  one settled claim, and two opposing operations for one intent can never both be in flight (the loser
  is refused durably with `dispatch_in_flight`).
- [C2-3] A successful renewal always confirms both the R2 collection intent and the renewal cycle, in
  that order, idempotently and under the worker principal's four bounded capabilities; a cycle that
  cannot be collected is recorded as a controlled refusal rather than silently left stranded.
- [C4-1] Every port input is recoverable without persisting a plaintext provider reference: the request
  carries only durable identifiers, the raw references live solely inside the adapter-sealed dispatch
  descriptor committed with the claim, `redrive()` rebuilds and proves the identical request from durable
  rows, and an envelope that cannot be opened refuses on a `claimed` claim and is reported as an
  operator-visible exception on an `in_flight` claim — never re-derived, guessed or silently re-issued.
- [C4-2] An expired lease is taken over by exactly one owner: the take-over is a single conditional
  compare-and-swap that must advance `claim_generation` and issue a fresh `claim_token_digest`,
  reconciliation, re-issue and settlement all verify that same generation and token, an owner that loses
  its fence writes no attempt and no result, and the §17 `concurrent_expired_lease` case proves one
  take-over, one reconciliation, at most one mutating call and exactly one attempt.
- [C5-1] The descriptor-refusal path is representable and safe: a `claimed` claim whose sealed envelope
  cannot be opened is ended `claimed → released` in the **same fenced transaction** that appends the
  command's `refused`/`dispatch_descriptor_unavailable` result, so the claim is never deleted, the live
  subject slot is released, §8.2 rule 5 admits exactly that one claim/result pairing, and every other
  pairing fails closed.
- [C5-2] A takeover can reach a re-issue through a defined fenced path: the winner passes one
  conditional pre-call ownership check that proves its exact `claim_generation` and `claim_token_digest`
  under an unexpired lease (renewing it) and that must affect exactly one row, and only then may it make
  the single mutating call whose attempt and result are written only by a settlement proving the same
  generation and token.
- [C5-3] The sealed descriptor is provably bound to one command: `DISPATCH_DESCRIPTOR_FIELDS` carries
  `command_key_digest` and `idempotency_key_digest` inside the authenticated payload (no AAD channel is
  needed and no new column, identifier, reference or secret is added), and the pre-call preflight plus the
  re-drive's reconstruction proof require both sealed values to equal the command row's key digest and
  the claim's idempotency-key digest before any provider call — a transplanted or mismatched envelope is
  refused `dispatch_descriptor_unavailable` and never re-derived.
- [C7-1] The binding is proven against the actual claim on the initial-dispatch path:
  `preflightDispatchDescriptor()` receives the live claim's stored `idempotency_key_digest` as an
  explicit expected value and may return `ok` only when the sealed pair equals it and the request's
  `command_key_digest`, and Core independently re-reads the immutable command row and the live claim row
  under the claim lock and requires both to equal the verdict's two reported sealed digests (and the
  request's derived `idempotency_key` digest) immediately before the `claimed → in_flight` acquisition —
  so an `ok` verdict can never be treated as permission to acquire the lease without a mandatory,
  ordered Core comparison, and any inequality (including an otherwise valid, openable envelope bound to
  another claim) takes the fenced `refused`/`released` refusal with no call and no attempt.
- [C7-2] The envelope is opened exactly once per provider invocation, and the post-lease no-call path is
  representable and fenced: a successful preflight mints the adapter-owned, opaque, one-use
  `ProviderDispatchCapability` that the single `submit`/`cancel`/`reconcile` invocation consumes, so no
  port call ever reopens or re-validates a descriptor and no second open can fail after `in_flight` on
  the initial-dispatch path. A capability the adapter cannot consume makes no outbound request and
  reports `not_attempted` / `dispatch_descriptor_unavailable`; the seam then writes no attempt and no
  `completed` result and ends a provably call-free generation-1 claim through the fenced no-call abort
  (`in_flight → released`, requiring this owner's `claim_generation = 1` and token under an unexpired
  lease, clearing `lease_expires_at`, and writing the `refused`/`dispatch_descriptor_unavailable` result
  in the same transaction), while a takeover generation (`claim_generation > 1`) can never take that
  abort and keeps the ordinary `in_flight` pending behaviour.
- [C4-3] Anonymous intake settles only through the bounded service principal: the translation and the R2
  consequence run inside the scoped worker execution context, the R1/R2 rows record that principal's user
  id, the caller's identity is restored on every path including a thrown exception, and no Phase T path
  ever establishes an administrator or widens the principal's four capabilities.
- [C2-4] The webhook resolves its account, mode and signing secret before parsing the body, tries
  exactly one secret, and fails closed on a missing, unknown, ambiguous, inactive or mode-mismatched
  selection.
- [C2-5] No production code path can store a provider secret: the vault's write surface refuses every
  provider while `PROVISIONABLE_PROVIDERS` is empty, and the only storage path is the constant-gated
  test vault.
- [C2-6] `payment_provider_secrets` cannot hold an unscoped row, and the active-slot uniqueness
  invariant holds for every scope.
- Every §17 suite passes on the disposable runtime from a fresh clone, with no network access and no
  real credential in play.
- No provider policy was silently invented; every deferred decision is behind the recorded
  `unresolved`/`unset` seam of §20.
- The Stripe adapter is provably incapable of an outbound call in this build, and no code path can
  provision or write a credential — not even through the capability-authorized vault surface (§11.3,
  §11.5). A Stripe webhook can never verify in this build (§9.4), and the absence of both live calls
  and verified webhooks is a property of the code.
- No live call, credential, charge, notification, Theme change, merge, deployment or production
  access occurred.
