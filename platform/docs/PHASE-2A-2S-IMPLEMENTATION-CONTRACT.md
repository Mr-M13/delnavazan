# Phase 2A.2-S — Canonical Notification & Communications Authority

**Status:** implementation contract (preflight). Planning/audit only.
**Schema:** 032 (`032_notification_communications_authority`)
**Build:** `phase2a2s-notification-communications-authority-20260924.1` (proposed)
**Current-source addendum (2026-09-27):** This Schema-032 contract and its build stamp are historical
slice provenance. The checked-in package now declares Schema 33. Migration
`033_core_dataset_technical_prerequisites` is additive after Schema 32 and installs only
`core_dataset_provenance`, `core_dataset_reconciliation_runs`,
`core_dataset_reconciliation_findings`, `core_dataset_operator_commands` and
`core_dataset_corrections`. It is verified before it is recorded in the migration ledger and before the
schema option advances; it creates no domain rows, performs no backfill and authorises no notification
delivery, provider contact or deployment.
**Base (inspection):** `origin/main` @ `2ab0c71f5cc53ca8aa4241db0b2f7d100de65997` (Schema 31 / W candidate,
host-materialised) for this Schema-32 re-land. The re-land carries the §6.2.4 R2 amendment itself — the
durable, immutable tier-F authoritative instant and the single authoritative publication site of
`AUTOMATIC_RENEWAL_UPCOMING` — as one coupled change, so the re-land base need not already persist a
tier-F instant. The historical inspection base `main` @ `559b1736621c9ed32e41dd2b785dd0f040dcb647`
(Schema 26 / R2 candidate) is an ancestor of the re-land base.
**Depends on:** Phase 2A.2-R2 (Schema 26) merged and closed. S consumes the R2 finalised intent set
verbatim; it must not be merged ahead of R2. S additionally requires the bounded R2 amendment stated in
§6.2.4 — the durable, immutable tier-F authoritative instant **and** the single authoritative publication
site of `AUTOMATIC_RENEWAL_UPCOMING` — before any tier-F workflow version may be registered; until that
amendment is independently reviewed and merged, `AUTOMATIC_RENEWAL_UPCOMING` stays unregisterable
(`tier_f_instant_unavailable`) and no tier-F notification may dispatch.
**Revision:** correction round 15 (failed candidate `c563f1c` / tree `2539b4e6`); this document's revision
sequence continues at 15 for the host ledger's correction round 16, which failed that candidate. Corrects
one blocking finding. The terminal-reason rule was scoped to a notification that was **already** `failed`:
§7.3 validated the closed vocabulary and the attempt/notification equality only on a `failed` notification
whose closing attempt carries `failure_class = 'terminal'`, and §6.6 stated the closure's shape without a
guard on the resulting status, so a forged `terminal`-class attempt persisted beside an `expired`
notification — with a matching, valid terminal reason on both records — passed every verifier branch: it
is not a ceiling closure, not a window exhaustion (§7.3 scopes that rule to a non-terminal closing
attempt) and not a terminal-reason violation, because the notification was never `failed`. The
notification's status is now part of the terminal invariant rather than an assumed precondition: §6.6
requires a `terminal`-class closure to close the notification as terminal `failed` and refuses any other
status whole with `terminal_reason_invalid`, §7.3 rejects a notification whose closing attempt carries
`failure_class = 'terminal'` when its state is not terminal `failed`, §14 names the forged
`terminal`-beside-`expired` shape and records that the window diagnostic is never applied to a
`terminal`-class attempt, §2/§3 state the closure status as a locked invariant, §15's retry suite gains
the **forged-`expired` terminal-class** case, and §18 records the status condition as a done condition
(§2, §3, §6.6, §7.2, §7.3, §14, §15, §18).

Corrected earlier: round 14 (failed candidate `e40e7f9` / tree `6648ed7`, the host ledger's correction
round 15) fixed two blocking findings. (1) The two exhaustion causes did not share one mapping: §6.6's `abandoned` bullet
closed a clamp that leaves no usable window as `failed`/`retry_exhausted`, §9 called `retry_exhausted` the
code of "exactly this ceiling and ... its clamped-window sibling", and §14's `retry_exhausted` diagnostic
counted the window cause as well, while §9 itself, §6.6's `expired` bullet and §7.3 close that same cause
as `expired`/`retry_window_exhausted`. Every section now maps **ceiling exhaustion only** to
`failed`/`retry_exhausted` and **usable-window failure only** to `expired`/`retry_window_exhausted`,
`retry_exhaustion_invalid` is scoped to the ceiling shape, and the window shape gets its own diagnostic
(`retry_window_exhaustion_invalid`) and its own retry-suite coverage across the retry, defer, abandoned
and lease-expiry paths (§2, §6.6, §7.3, §9, §14, §15, §18). (2) The contract declared no outcome for the
reachable state in which **both** gates fail — the closing attempt is at `retry_max_attempts` and its
clamp also leaves no usable window — so replay, recovery and verification were not deterministic. The two
gates now have a declared precedence — **the ceiling is evaluated first** — so that state is
unambiguously ceiling exhaustion (`failed`/`retry_exhausted`) and the window shape always carries an
attempt remaining, stated in §2, §3, §6.6, §7.3, §9, §14 and §18 with a retry-suite case that exercises
both failed gates together (§2, §3, §6.6, §7.3, §9, §14, §15, §18).

Corrected earlier: round 13 (failed candidate `7a00ef1` / tree `51205e84`, the host ledger's correction
round 14) fixed one blocking finding. §9 stated both outcomes for the same closure — a window-exhausted
closure that
derives and persists no retry schedule, and a closing attempt that persists the
`applied_jitter_bp`/`base_backoff_seconds`/`backoff_seconds`/`next_available_at` quadruple and appends a
`retry_scheduled` event — and its lease-expiry path re-armed whenever an attempt remained, without the
usable-window gate, so §7.3's accepted exhaustion shape was unreachable on those paths. Quadruple
persistence, the `retry_scheduled` event and the re-arm are now scoped to the closures that actually
re-arm — an attempt remaining **and** a §9 clamp that leaves a usable window — and a lease expiry whose
clamp leaves no usable window is explicitly routed to `expired`/`retry_window_exhausted` with no
schedule, no event and no re-arm, exactly like a port-reported retry, an attempt-level `defer` closure or
an operator `abandoned` release (§2, §6.6, §7.2, §9, §15, §18).

Corrected earlier: round 12 (failed candidate `f38687e` / tree `c47f7b2`, the host ledger's correction
round 13) fixed one blocking finding. §7.3's persisted-retry-schedule rule required the
`applied_jitter_bp`/`base_backoff_seconds`/`backoff_seconds`/`next_available_at` quadruple on **every**
attempt row that closed `retryable`, but §6.6/§9 define two valid exhaustion closures that derive no
schedule and re-arm nothing — a non-terminal closure at the final permitted attempt
(`attempt_sequence = retry_max_attempts`) and a closure whose §9 clamp leaves no usable window — so §15's
required exhausted-retry verifier-pass case was rejected by the contract's own verifier. The requirement
is now **scoped to the closures that actually re-arm** (an attempt remaining **and** a usable window), and
the two exhaustion shapes are explicitly accepted — and required — in their NULL shape: no retry schedule,
the closing attempt keeping its non-terminal `failure_class` and its own closure code, and the notification
closed `failed`/`retry_exhausted` at the ceiling or `expired`/`retry_window_exhausted` by the window (§2,
§6.6, §7.2, §7.3, §14, §15, §18).

Corrected earlier: round 11 (failed candidate `3a72a54` / tree `6854f98`, the host ledger's correction
round 12) fixed two blocking findings. (1) §7.3 scoped the closed terminal-reason vocabulary and the
attempt/notification equality rule to **every** `failed` notification, but §6.6 requires a valid exhausted
retry to close as `failed`
with `failure_reason_code = retry_exhausted` — a code deliberately outside the three-member terminal
vocabulary — so a required, valid lifecycle state failed its own integrity verification. The rule is now
scoped to a notification **whose closing attempt carries `failure_class = 'terminal'`** (a scope round
15 extends over the notification's status, refusing a `terminal` attempt persisted beside an `expired`
notification), a
non-terminal exhaustion is validated separately as exactly `retry_exhausted` (§6.6, §7.3, §14), and §15
adds the passing verifier case for a normal exhausted retry. (2) §9's lease-expiry bullet said final
lease expiry changes `failure_class` to `terminal` while closing the notification with
`failure_reason_code = retry_exhausted` — exactly the terminal-class borrowing the closed invariant
forbids. Final lease expiry now stays the non-terminal/retryable exhaustion path (the attempt remains
`expired` with `outcome_code = lease_expired` and `failure_class = retryable`) and never borrows a
terminal vocabulary member (§6.6, §9, §18).

Corrected earlier: round 10 (failed candidate `4246464` / tree `a3522c3`) fixed three blocking findings:
(1) §9 called its retry intervals the "narrow only" form of the class defaults, but the
intervals merely bounded the parameters: a version could register `retry_max_attempts = 100` against the
default `3` and `retry_max_backoff_seconds = 525600000` against `3600`, so retry behaviour was
**broadened** while the contract claimed it could not be. §9 now declares one **real partial order** — the
coordinatewise order on the five parameters with the approved class baseline as its maximum element — so
every parameter's admissible interval is bounded above by the baseline (`retry_max_attempts` `1…3`,
`retry_initial_backoff_seconds` `1…120`, `retry_backoff_multiplier_bp` `10000…30000`,
`retry_max_backoff_seconds` `retry_initial_backoff_seconds…3600`, `retry_jitter_bp` `0…1000`) and "narrow
only" is literally the per-parameter check `declared ≤ baseline`; §2, §3, §5, §7.3, §8.1, §14, §15 and
§18 state the same intervals, and the derived arithmetic and column-width bounds are recomputed for them.
(2) §6.6/§9 required a `terminal` attempt class to close with "its own declared terminal reason code",
but no terminal-reason vocabulary, mapping or persistence path existed — `failure_class` carries only
`terminal` and the unconstrained `outcome_code` could not establish the matching code — so the verifier
could not prove the invariant; §6.6 now declares a closed, channel-neutral terminal-reason vocabulary
(`contact_unusable`, `send_refused`, `no_route`) and its single authoritative normalising writer, §7.3
requires the attempt and notification records to carry the same member and rejects a missing, non-member,
borrowed or mismatched code, §14 adds the refusal diagnostic and §15 adds forgery/missing-code coverage
(§3, §6.6, §7.3, §8.1, §9, §14, §15, §18). (3) §9 claimed the bounded recurrence "reproduces the
closed-form sequence whenever the closed form is representable", which is false for a valid policy
(`retry_initial_backoff_seconds = 1`, `retry_backoff_multiplier_bp = 15000`, `retry_max_backoff_seconds ≥ 2`
gives `base_backoff(3) = 1` by the recurrence but `floor(2.25) = 2` by the closed form); the recurrence is
now declared the **sole** canonical semantics, the equivalence claim is withdrawn explicitly, and §15 pins
the divergence case (§9, §15, §18).

Corrected earlier: round 9 (failed candidate `1558a9b` / tree `467ecd0`) fixed three blocking findings: a
retry that moved the outbox row's `available_at` **and** `scheduled_for` (which contradicts the immutable
schedule mirror of §6.3(c)(8)/§6.5/§7.3, so a retry now moves `available_at` alone); a `terminal` class
that was closed as `failed`/`retry_exhausted` rather than with its own reason code (`retry_exhausted` is now
reserved for a `retryable`, `defer`, lease-expiry or `abandoned` closure at the attempt ceiling); and a
retry rule with class defaults but no canonical encoding, cardinality or ranges (now a canonical
one-row-per-parameter encoding with a declared interval per parameter, activation validation
`retry_policy_invalid`, and an overflow-safe bounded recurrence — those intervals are superseded by round
10's narrow-only partial order).

Corrected earlier: round 8 (failed candidate `b27b553` / tree `6a8a3e0`) fixed four blocking findings: a
standalone outbox schedule re-derivation the outbox schema could not support (the row is now defined as a
mirror and dispatch index compared row-to-row with the aggregate's locked derivation, §6.3(c)(8)); a
verifier rule requiring a version's `timezone_basis` to resolve against a "named subject" that does not
exist at activation (vocabulary and inter-rule equality only at activation, resolution once per
notification, an unresolvable basis closing the observation terminally as `schedule_timezone_unresolved`);
undeclared `defer_ceiling_minutes`/`max_deferrals` encodings and ranges driving the deferred instant (now
the canonical encoding, bounded ranges, a bounded deferral product and overflow-safe integer-seconds
arithmetic refused at activation); and a `pending` row without `expires_at` that the verifier rejected
(the non-null rule now covers `scheduled_for` and `expires_at` together, keyed on
`scheduled_for IS NOT NULL`, exempting pre-scheduling rows).

Corrected earlier: round 7 (failed candidate `7128332` / tree `452ac97`) fixed three blocking findings:
a deferred row could not satisfy the contract's own deterministic verification (a re-derivable base
instant, the persisted `deferral_count` and a base-anchored `expires_at`); the §7.1 additive column list
omitted the `deferral_count` mirror; and two timezone-sensitive rules of one version could name different
bases while only one zone is persisted (one shared basis, `schedule_timezone_basis_conflict`).

Corrected earlier: round 6 (failed candidate `069d929` / tree `4168e5d`) bound
`AUTOMATIC_RENEWAL_UPCOMING` to `opened` alone (removing its second publish site) and replaced the
self-contradictory tier-F equality assertion with the strict-before relationship; the `expires_at` anchor
that round introduced is superseded by round 7's base anchor. Round 5 (failed candidate `afcfd8c` /
tree `4719f64`) made the tier-F announced instants durable R2 facts and made expiry mandatory with one
explicit NULL rule; round 4 (failed candidate `d88acb9` / tree `6a60b4d`) bound B2 to the closed §6.2.2
authoritative-fact matrix and enforced the bounded retry ceiling; round 3 (failed candidate `cb664d8`)
defined the complete required eligibility set (§6.2.1) and the deterministic keyed jitter; round 2 (failed
candidate `ef0b601`) corrected intent-level routing arbitration, draft-only frozen rule sets and
`platform_outbox` `notification_id` uniqueness. Preflight contract text only: no product code, migration,
merge or deploy.

This contract is implementation-ready for the notification/communications authority layer, subject to
the two hard prerequisites of §17 (the amended R2 base that carries the durable tier-F instant and
publishes the advance notice from one site only). It does not authorise deployment, merge, provider
transport, external communication (Meta, email, SMS, WhatsApp), credential work, Theme work, or Amelia
changes.

## 1. Verified authoritative state

Read-only inspection of this checkout on 2026-09-24 (no writes, no provider calls, no browser
session):

| Fact | Verified value |
| --- | --- |
| Repository root | workspace root (`delnavazan-platform` checkout) |
| Branch / remote | `main...origin/main` |
| HEAD | `559b1736621c9ed32e41dd2b785dd0f040dcb647` (`PLATFORM-R2-IMPLEMENTATION: host-materialized candidate`) |
| Platform version | 0.1.0 |
| Schema constant | `DZN_PLATFORM_SCHEMA_VERSION = '26'` (`delnavazan-platform.php`) |
| Build constant | `DZN_PLATFORM_BUILD_ID = 'phase2a2r2-renewal-next-term-collection-recovery-20260923.1'` |
| Migration ledger | `001`–`026`; latest `026_renewal_recurring_enrolment_authority` |
| R2 merge state | candidate only; not authoritative until independently reviewed and merged |

Four facts are load-bearing for S and were confirmed in source:

1. The shared dispatch seam already exists. `Migrator::install()` creates
   `{prefix}dzn_platform_outbox` with exactly:
   `id`, `aggregate_type varchar(32)`, `aggregate_id bigint unsigned`, `event_type varchar(64)`,
   `invitation_id bigint unsigned NULL`, `generation_id bigint unsigned NULL`,
   `idempotency_key char(64)`, `status varchar(16)`, `available_at datetime`,
   `leased_at datetime NULL`, `processed_at datetime NULL`,
   `attempt_count smallint unsigned NOT NULL DEFAULT 0`, `created_at datetime`,
   `PRIMARY KEY(id)`, `UNIQUE KEY idempotency_key(idempotency_key)`,
   `KEY available(status,available_at)`, `KEY invitation_generation(invitation_id,generation_id)`.
2. R2 finalised the channel-neutral intent set as intent names only, written into that seam by
   `RecurringOutboxRepository::publish()` with a keyed digest identity
   (`hash_hmac('sha256','recurring_intent:…', wp_salt('dzn_recurring'))`, 64 chars) and no template,
   recipient, or delivery state. `RecurringRule::NOTIFICATION_INTENTS` is the authoritative list.
3. **No tier-F authoritative instant is durably available in the R2 candidate today.** The automatic
   charge instant is computed, not persisted: `RenewalCycleService::openCycle()` calls
   `RenewalCycleService::automaticChargeAt()`, which reads the *current* value of the
   `AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME` policy through `CommercialPolicyService::current()` (a versioned,
   latest-version-wins read) and returns it to the caller as `charge_at`.
   `dzn_renewal_cycles` stores no automatic-charge column, and `CollectionIntentService::open()`
   recomputes the same instant from that current policy when the `automatic_charge` collection intent —
   the row that carries `charge_at` — is opened later, so the announced instant and the charged instant
   can diverge after a policy version. `dzn_renewal_cycles.guarantee_deadline_at` *is* persisted, but
   `RenewalCycleService::activateManualGuarantee()` falls back to `guaranteeFallback()`, which re-resolves
   the deadline from the current `dzn_commercial_recurring_patterns` row (timezone, wall time, duration,
   buffer) and the cycle boundary whenever the column is NULL. §6.2.4 turns this into a bounded R2
   prerequisite — persist and bind the instant before publishing, never fall back — and removes every
   S-side recomputation from current policy or schedule state.
4. **`AUTOMATIC_RENEWAL_UPCOMING` has two publish sites in the R2 candidate, and its durable seam
   identity cannot tell them apart.** `RenewalCycleService::openCycle()` publishes the intent in the
   transaction that inserts the cycle, its `opened` event (`(empty) → pending`) and the persisted charge
   instant, and only when that instant is non-null. But
   `RenewalCycleService::intentForTransition()` returns the *same* intent name for `require_payment` in
   `automatic` mode, so `RenewalCycleService::transition()` publishes it a second time on the
   `payment_required` event (`pending → payment_required`, or `guarantee_protected → payment_required`).
   `RecurringOutboxRepository::intentKey()` is
   `hash_hmac('sha256','recurring_intent:' || aggregate || ':' || aggregate_id || ':' || intent, …)` and
   `publish()` returns early when that key already exists, so the second publication is deduplicated onto
   the row the cycle-open fact created: one row, two candidate bound transitions, and the seam carries no
   originating-event reference. S therefore could not determine which event tuple to freeze for B2
   evidence. §6.2.2 binds this intent to `opened` alone and §6.2.4(b) requires R2 to stop re-publishing it
   on `require_payment`; no other consumed intent has more than one publish site today.

The seven canonical planning documents still describe Schema 24/25 in places. That stale-documentation
debt is listed as a pre-implementation prerequisite (§17), not part of S authority code.

## 2. Locked invariants S must preserve

- A business fact commits independently of any notification. Notification work never participates in
  another module's transaction outcome and never rolls back a business event.
- `platform_outbox` is the single durable dispatch seam. S extends it; S does not create a second
  queue, a second lease mechanism, or a provider-specific delivery table.
- The required eligibility set is closed: every activated version carries the §6.2.1 mandatory baseline
  plus its intent/audience tier, so neither `recipient_resolvable`, nor `recipient_opted_in`, nor the
  guardian-authority rule can be omitted, and no version may dispatch a narrower set than the matrix.
- Retry scheduling is deterministic and replayable: the jitter and the resulting `next_available` derive
  only from immutable notification identity, attempt sequence and frozen policy, never from an RNG or an
  ambient clock, and recovery reproduces the persisted instant. That policy is a declared,
  activation-validated retry rule inside the §9 bounded envelope (canonical one-row-per-parameter
  encoding, one declared **narrow-only** interval per parameter, overflow-safe integer-seconds arithmetic),
  where "narrow only" is a real partial order against the approved class baseline and not merely a bound:
  the ordering is the coordinatewise order on the five declared parameters, the baseline is its maximum
  element, and each parameter's admissible interval is bounded above by the baseline value, so no version
  may declare more attempts, a larger first-delay budget, a faster growth factor, a higher delay ceiling or
  a wider jitter span than the baseline, and no declared policy can make the derivation undefined or
  overflow.
  Expiry is **mandatory** on every activated
  version, so every S-owned notification that has reached `scheduled` — and every outbox row enriched for
  one — carries a non-null `expires_at`, while a row that has not derived an instant carries
  `scheduled_for` and `expires_at` NULL together (§6.3/§6.5). Where a row carries NULL `expires_at` (an
  S-owned pre-scheduling row, a legacy `platform_outbox` row S never claims, or a deliberately forced
  fixture) the expiry clamp term and every expiry-window check are omitted, exactly as §6.3/§9 define, so
  the formula is total and never depends on an undefined comparison.
- Attempts are bounded: `retry_max_attempts` caps the **total number of lease acquisitions** one
  notification may make (`1 ≤ retry_max_attempts`, so `retry_max_attempts = 1` means no retry), and every
  re-arm path — the normal retry closure, the attempt-level `defer` closure, an operator `abandoned`
  release, and lease-expiry recovery — re-arms only while an attempt remains
  (`attempt_sequence < retry_max_attempts`) **and** the §9 clamp leaves a usable window. The two gates are
  read in one fixed order — **the ceiling first, the window second** — so exhaustion is deterministic
  even when both fail: a closure at `attempt_sequence = retry_max_attempts` is ceiling exhaustion and
  closes the notification `failed`/`retry_exhausted` whatever its clamp does, and the window rule applies
  only to a closure with an attempt remaining, where a clamp that leaves no usable window is window
  exhaustion — `expired`/`retry_window_exhausted` — never a re-arm (§9). Exhaustion closes the
  notification terminally and changes
  **only** the notification's reason code: the closing attempt keeps its own non-terminal `failure_class`
  and closure code — a lease expiry stays `expired`/`lease_expired` with `failure_class = retryable` and is
  never reclassified as `terminal` — so a non-terminal closure at the ceiling is the valid
  `failed`/`retry_exhausted` shape the verifier accepts, and a below-ceiling closure whose clamp leaves
  no usable window is the valid `expired`/`retry_window_exhausted` shape (§6.6, §7.3, §9). A `terminal`
  class is the one class that closes the notification as terminal `failed` with a member of the closed
  §6.6 terminal-reason vocabulary, and it never accompanies another notification status: a `terminal`
  attempt persisted beside an `expired`, `suppressed` or `cancelled` notification is refused and rejected
  with `terminal_reason_invalid`, so the window's `expired` status is closed to a `terminal` closing
  attempt and the lifecycle partition is exact (§6.6, §7.3). Neither
  exhaustion shape persists a retry schedule, so the verifier requires the persisted quadruple only where
  a closure actually re-arms — an attempt remaining **and** a clamp that leaves a usable window (§7.2,
  §7.3) — and no path can ever produce attempt `retry_max_attempts + 1`.
- `subject_state_is` is an evidence predicate, not a current-state lookup: it passes only when the subject
  aggregate's append-only history carries the intent's bound authoritative transition (§6.2.2), and its
  allowlist is derived from that matrix rather than authored by a version. A later legal successor
  transition never invalidates a bound intent, and a missing bound fact is the only state failure.
- One intent binds one fact: each §6.2.2 row names exactly one authoritative event type, so the frozen B2
  evidence tuple is unique per notification. Because the seam carries aggregate type, aggregate id and
  intent name only — never the originating event — an intent name that R2 can publish from more than one
  transition is not consumable until publication is narrowed to the bound site;
  `AUTOMATIC_RENEWAL_UPCOMING` is bound to `opened` alone and the §6.2.4(b) R2 amendment removes its
  second publish site. An ambiguity of that kind is never resolved by choosing a candidate at dispatch.
- Every tier-F intent's announced instant is likewise an **evidence fact, not a computation**: it is read
  exactly as the owning subject row committed it (§6.2.2/§6.2.4) and is never recomputed by S from the
  current commercial policy, the current pattern/schedule, a fallback derivation or any ambient clock. A
  later policy version or a later schedule/pattern change never moves that frozen instant, and an intent
  whose bound instant is absent is unregisterable and undeliverable (`tier_f_instant_unavailable`).
- Scheduling is one frozen, total function, not an author's assembly of rules: an activated version has
  exactly one anchor rule, a closed composition of optional placement rules and exactly one expiry rule
  (§6.3), all of its timezone-sensitive rules name one shared `timezone_basis`, and the derived
  `schedule_anchor_at`, base instant, `scheduled_for` and `expires_at` are pure functions of the persisted
  subject instant, the persisted observation instant, the persisted `deferral_count` and that frozen rule
  set — with `expires_at` anchored to the base instant, so a deferral can never re-anchor the window.
  Every re-derivation reproduces the persisted values exactly, and no candidate instant, window bound,
  deferral or retry decision rests on a comparison the contract leaves undefined or on a derivation two
  rules could disagree about.
- Each registered intent routes to **exactly one** active workflow version, enforced by a storage-level
  intent routing slot rather than by convention; and an activated version's rule set is frozen, so
  eligibility, scheduling and retry behaviour can never change under a live dispatch without a new
  version and a new fingerprint.
- Intent publication stays provider- and channel-neutral. R2 (and every earlier writer) keeps its
  existing `publish()` shape unchanged.
- S owns eligibility, workflow versioning, scheduling, idempotency/retry policy, rendered-template
  parameter snapshots, the notification/attempt/delivery lifecycle, and channel-independent
  diagnostics. S does not own identity, contact source-of-truth, or transport.
- Money, Term, Lesson, schedule, attendance, Enrolment and capacity authority remain with their
  owning phases. S reads them through read models and never locks or mutates them.
- Provider facts are never business authority. Only a verified, normalised delivery fact may move a
  delivery state, and it may never advance business state.
- PII is minimised by construction: keyed digests for durable identity, authenticated encryption for
  the narrow dispatch envelopes, no raw provider payload, no secret in any log, notice, export,
  diagnostic or doc.

## 3. Authority boundaries

### In scope

1. **Notification workflow definitions with immutable versions** — a versioned, capability-gated
   definition bound to one consumed intent, one audience, one template version and one rule set.
2. **Eligibility policy** — an enumerated, versioned rule set evaluated at enqueue and re-evaluated
   immediately before hand-off.
3. **Scheduling policy** — one frozen, total derivation algorithm (§6.3): a closed rule composition
   validated at activation (exactly one anchor, at most one each of local placement, send window,
   deferral and coalesce, exactly one **mandatory** expiry, and one shared timezone basis across every
   timezone-sensitive rule, plus one canonical parameter encoding with declared per-parameter ranges and
   overflow-safe integer arithmetic), the anchor derived from the persisted tier-F announced instant
   (§6.2.4) or the persisted observation instant, the base instant, `scheduled_for` as the base plus the
   persisted `deferral_count` × the frozen step, and the exact base-anchored expiry formula for
   forward-looking and post-fact intents alike; the timezone basis is vocabulary- and equality-validated
   at activation and resolved once per notification at observation (§6.3); inputs and results persisted,
   no wall-clock guessing and no recomputation from current policy or schedule state.
4. **Idempotency and retry policy** — one durable notification per logical event, lease-based
   attempts, a bounded back-off whose declared rule-set is canonically encoded and validated at
   activation against the §9 narrow-only partial order, and `terminal` failure classes that close the
   notification as terminal `failed` with a code from the closed, channel-neutral terminal-reason
   vocabulary persisted identically on the attempt and the notification — never alongside any other
   notification status, so a `terminal` attempt beside an `expired`, `suppressed` or `cancelled`
   notification is refused and rejected with `terminal_reason_invalid` — alongside non-terminal
   exhaustion, whose two gates are read ceiling-first and
   which therefore closes the notification either as `failed`/`retry_exhausted` at the attempt ceiling or
   — with an attempt remaining — as `expired`/`retry_window_exhausted` by the window, while the closing
   attempt keeps its own non-terminal class and closure code (§6.6, §9).
5. **Rendered-template parameter snapshots** — immutable, encrypted, digest-anchored parameter sets
   frozen at enqueue, plus the allowlisted variable-code set actually used.
6. **Notification / attempt / delivery lifecycle** — explicit state machines over S-owned storage,
   with append-only history and digest-only command evidence.
7. **Channel-independent diagnostics and operational read models** — capability-protected, PII-free
   projections over notification, attempt, delivery and outbox facts.
8. **Consumption of the finalised R2 intents** — routing each registered intent to exactly one
   workflow version; unroutable and unregistered intents fail safe and remain visible.

### Out of scope

- Meta/Graph/WhatsApp, email and SMS transport, credentials, webhook endpoints or provider template
  registration (Phase T and later).
- Any live or test send to a real recipient; no external communication occurs in S.
- Amelia notification templates, triggers or scheduled-send configuration.
- Reminder/absence workflow authority. `lesson.scheduled` reminders and
  `attendance.absence_reported` alerts are *illustrative future flows* in
  [MODULE-BOUNDARIES.md](MODULE-BOUNDARIES.md) §13; registering them requires an authorising phase.
- Consent/opt-in ownership. S consumes a resolved, channel-neutral eligibility signal and records the
  decision; it never becomes the consent source of truth.
- Theme, portals, wp-admin screens beyond the existing capability-gated admin conventions, deployment,
  production cutover, and merge.

### Authority-preservation rule

S is a **consumer and dispatcher of facts other phases already own**. It must never re-derive a Term,
Lesson, schedule, attendance outcome, obligation, settlement, renewal cycle or refund decision; it
reads the authoritative read model at enqueue and re-reads it before hand-off. Every dispatch is a
post-commit action against an already-durable fact.

## 4. Consumed intents

S consumes exactly the R2 finalised intent names, plus the two Phase-1 delivery-request event types it
shares the seam with (as **legacy-excluded** names it must never claim). The registry is
closed-by-default: an intent with no registered workflow version is `unregistered_intent`, and a
registered intent with no active version is `unroutable_intent`. Neither is delivered, deleted or
silently dropped.

R2 published intent **names** only — not audiences and not recipients. The `aggregate_type` below is
the `platform_outbox.aggregate_type` R2 actually wrote at its publish site; the audience is resolved
by the S workflow definition, never from the intent name.

| Consumed intent | R2 outbox `aggregate_type` | Proposed S workflow key | Audience (proposed) |
| --- | --- | --- | --- |
| `AUTOMATIC_RENEWAL_UPCOMING` | `renewal_cycle` | `renewal.automatic_upcoming` | `student` |
| `AUTOMATIC_RENEWAL_CHARGED` | `collection_intent` | `renewal.automatic_charged` | `student` |
| `AUTOMATIC_RENEWAL_FAILED` | `collection_intent` | `renewal.automatic_failed` | `student` |
| `MANUAL_RENEWAL_PAYMENT_REQUIRED` | `renewal_cycle` | `renewal.manual_payment_required` | `student` |
| `GUARANTEE_DEADLINE_APPROACHING` | `renewal_cycle` | `renewal.guarantee_deadline_approaching` | `student` |
| `GUARANTEE_EXPIRED` | `renewal_cycle` | `renewal.guarantee_deadline_expired` | `student` |
| `PAYMENT_FAILED` | `collection_intent` | `payment.failed` | `student` |
| `PAYMENT_RECOVERED` | `recovery_case` | `payment.recovered` | `student` |
| `TERM_LAPSED` | `renewal_cycle` | `renewal.term_lapsed` | `student` |
| `REFUND_REVIEW_REQUIRED` | `refund_review` | `refund.review_required` | `student` |
| `REFUND_RESOLVED` | `refund_review` | `refund.resolved` | `student` |

Each consumed intent's authoritative subject fact — the subject aggregate, the owning append-only event
table, the originating event type, the committed transition, the tier and (for tier F) the announced
instant — is bound by the closed matrix in §6.2.2. `GUARANTEE_EXPIRED` is declared by R2 as an intent name
but has **no writer** in the R2 source today, so it is *reserved-unbound*: S refuses to register or
activate a workflow version for it (`intent_unbound`) until an authorising phase publishes it with a bound
transition, and it is never routed onto another intent's transition, silently mapped to a nearby state, or
delivered.

For the three tier-F intents the matrix binds the announced instant to a **persisted subject column**, not
to a computation: §6.2.4 states the durability rule R2 must satisfy before publishing (a single immutable
instant written in the same transaction as the bound fact), the conditional publication rule (no instant,
no intent), S's read-only consumption rule (current policy and current schedule are never inputs), and the
fail-closed outcome when the instant is absent (`tier_f_instant_unavailable`). Until the R2 amendment it
requires is merged, `AUTOMATIC_RENEWAL_UPCOMING` is unregisterable and no tier-F notification dispatches.

Legacy-excluded (Phase 1 invitation seam, never claimed by S):
`teacher_invitation.delivery_requested` — the one delivery-request event the Phase-1 foundation
actually publishes today (`PrincipalInvitationService` → `PrincipalInvitationRepository::outbox()`).
The student account invitation generations exist in storage and share the same `generation_id` seam;
whatever delivery-request name that flow publishes when it is implemented is reserved as
legacy-excluded until a later slice explicitly migrates it. An `academy`/staff audience variant for any
intent is a new workflow version and requires the authorising phase to state the audience explicitly;
S must not invent one.

[COMMERCIAL-POLICY-REGISTRY.md](COMMERCIAL-POLICY-REGISTRY.md) also lists
`INSTALMENT_PAYMENT_REQUIRED` and `UPCOMING_INSTALMENT` as intent names that the instalment due-date
policy will drive. No implementation publishes either today, so neither is consumed by S and both stay
unregistered until an authorising phase publishes them.

## 5. Product decisions and safe defaults

The following remain product-owner decisions. S must not invent them.

| Decision | S safe default / seam | Gate |
| --- | --- | --- |
| Channel choice and per-channel opt-in | Workflow definitions are channel-neutral; a `channel_class` value is never stored as business state and no default channel is chosen | Future channel-preference sub-slice |
| Quiet hours and send window | `send_window_*` unset means the workflow is eligible at its derived instant with no deferral; a window must be explicitly registered, and it evaluates in the same `timezone_basis` as local placement (§6.3) | Per-workflow registration |
| Schedule composition | Closed and validated at activation (§6.3): exactly one anchor rule (`lead_time` with `lead_time_minutes ≥ 1` on tier F, `immediate` on tier P), at most one each of `fixed_local_time`, `send_window`, `deferral` and `coalesce`, exactly one `expiry`; any other cardinality or a tier-independent code is refused with `schedule_composition_invalid` | Not deferrable (composition) |
| Timezone basis | One basis per version, shared by every timezone-sensitive rule: `fixed_local_time` and `send_window` must name the same `timezone_basis`, refused at activation otherwise with `schedule_timezone_basis_conflict`, because the contract persists one resolved zone and defines no cross-zone order. Activation validates the basis **vocabulary** and that equality only — a version has no recipient or subject instance to resolve against; the basis is resolved once per notification at observation, and a basis that cannot resolve there closes the observation terminally with `schedule_timezone_unresolved` (§6.3) | Not deferrable (basis) |
| Expiry window | **Not optional and not defaulted**: every activated version must register an `expiry` rule with `1 ≤ expiry_minutes ≤ 525600000` (§6.3), so `expires_at` is non-null on every S-owned notification that has reached `scheduled` (and NULL, together with `scheduled_for`, on a row that has not); activation refuses a version without one (`schedule_expiry_missing`). The window is derived deterministically as §6.3 defines — `expires_at = derivation_base_at + expiry_minutes × 60` from the frozen base instant, capped at the announced subject instant for a tier-F intent and never re-anchored by a deferral — so it is reproducible from persisted values. S invents no value, and the explicit NULL rule of §6.3/§9 applies to rows S never owns and to S-owned rows that have not yet derived an instant | Per-workflow registration (the value); not deferrable (the requirement) |
| Retry ceiling and back-off | The §9 class baseline (the class defaults) applies when a version registers no retry rule. A registered rule is stored in the canonical §9 one-row-per-parameter encoding and every parameter must lie inside its declared **narrow-only** interval, whose upper bound is the approved class baseline — the ordering is the coordinatewise partial order on the five parameters, the baseline is its maximum (widest) element, so a version can only narrow it, never exceed it (`retry_policy_invalid` otherwise) — and the §9 bounded-attempt accounting (re-arm only while an attempt remains, a `retry_exhausted` closure at the ceiling for a non-terminal class, each `terminal` class closing with the same declared terminal reason code on the attempt and the notification otherwise, §6.6) is a contract invariant a version can never relax | Per-workflow registration (values); not deferrable (accounting) |
| Tier-F announced instant | Read-only from the owning subject row's persisted, immutable column (§6.2.4); S never derives it from the current commercial policy, the current pattern/schedule or a fallback. A missing instant refuses registration/activation and fails an observed notification closed; R2 must persist and bind it before publishing, and must not publish the intent without it | Not deferrable (R2 amendment prerequisite) |
| Notification retention and erasure | `NOTIFICATION_RETENTION_POLICY` unset means no automatic purge; erasure happens only on an explicit, evidence-recorded request | Future retention sub-slice |
| Consent source | S consumes a resolved `recipient_opted_in` eligibility fact; it never stores or derives consent | Future consent-authority sub-slice |
| Eligibility requirement set | The §6.2.1 mandatory baseline and intent/audience tier are contract invariants, not configuration: a version may add rules, but it can never omit, duplicate or rebind a mandated one | Not deferrable |
| Template copy authoring and localisation | S stores versioned template identity plus digests and the variable contract; the human-authored body lives with the adapter/copy owner until a later slice | Future template-authoring sub-slice |
| Dispatch runtime cadence | S defines the lease contract only; the invoking runtime (WP-Cron, CLI or admin command) is deployment configuration, not S authority | Future runtime sub-slice |

## 6. Domain model

### 6.1 Workflow and versioning

`notification_workflows` is the stable identity of one communication workflow:
state `draft | active | retired`; exactly one active *version* at a time, and at most one active version
per consumed intent across every workflow.

`notification_workflow_versions` is **immutable once activated**, including its rule set. A version binds:

- the consumed `intent_key` (allowlisted, §4);
- the `audience` and `recipient_kind` the workflow addresses;
- the `template_id` (resolved to an exact `template_version_id` at enqueue);
- the rule set in `notification_workflow_rules` (eligibility, scheduling, retry);
- a `definition_fingerprint char(64)` computed over the whole definition.

Version lifecycle: `draft → active → superseded | retired`. Activation writes the new `active` row and
flips the predecessor to `superseded` in one transaction, so a dispatch in flight always names a version
that still resolves. A retired or superseded version is never re-activated; a change is always a new
version. Editing an activated version is impossible by construction (no mutable definition column).

Two orthogonal single-current-row invariants use the established repo pattern (a nullable slot column
plus a named unique key), and both are written inside the same activation transaction:

- `active_slot tinyint unsigned NULL` with `UNIQUE KEY workflow_active(workflow_id,active_slot)` — at
  most one active version per *workflow*.
- `intent_active_slot tinyint unsigned NULL` with
  `UNIQUE KEY intent_active(intent_key,intent_active_slot)` — at most one active version per *consumed
  intent*, across every workflow, including two different workflows claiming the same intent.

The intent-level slot is the routing arbiter, and it is what makes "each registered intent routes to
exactly one workflow version" (§3.8) a storage-enforced fact. `KEY intent_state(intent_key,state)`
remains a lookup index only and is never the arbiter. Both slots are NULL on `draft`, `superseded` and
`retired` rows, and MySQL permits unlimited NULL combinations, so history is never lost.

Activation is a single `READ COMMITTED` transaction that (1) locks the workflow row
(`notification_workflows.active_version_id`) and the intent's current active version, (2) validates the
complete required eligibility set against the §6.2.1 mandatory baseline and intent/audience matrix — a
missing, duplicated, misbound or unauthorised requirement refuses activation, (3) computes the rule-set
digest over the frozen rules and writes
`rule_set_digest`/`rule_frozen_at` together with `definition_fingerprint` (§6.2), (4) nulls the
predecessor's two slots and marks it `superseded`, (5) updates the workflow's
`active_version_id`/`workflow_version`, and (6) writes the `intent_active` routing claim. A competing
activation of the same intent loses the named-index arbitration, fails closed with
`intent_routing_conflict`, and rolls back whole: no partial routing state, no second active version, and
never a silent last-writer-wins.

### 6.2 Eligibility

Eligibility is an enumerated, versioned predicate set in `notification_workflow_rules`
(`rule_kind = 'eligibility'`). Registered rule codes:

| Rule code | Meaning | Fail-closed outcome |
| --- | --- | --- |
| `subject_exists` | the named subject aggregate row resolves through its read model | `eligibility_unresolved` |
| `subject_state_is` | the subject aggregate's append-only history contains the intent's bound authoritative transition (§6.2.2), and the declared allowlist equals that binding's committed `to_state` set | `ineligible_subject_state` |
| `subject_instant_in_future` | the bound subject instant is strictly ahead of the notification's persisted `scheduled_for` (`scheduled_for < subject_instant`, §6.3) — equality is not "in future" and fails closed | `eligibility_expired` |
| `recipient_resolvable` | a mapped recipient resolves with a deliverable contact for the version's audience | `recipient_unresolved` |
| `recipient_opted_in` | the resolved channel-neutral consent fact is affirmative | `consent_absent` |
| `not_suppressed` | no active suppression matches recipient + purpose | `suppressed` |
| `guardian_authority_present` | the resolved contact belongs to an adult, or a present guardian authority covers the minor it belongs to | `authority_absent` |
| `lead_time_at_least` | at least `parameter_c` minutes remain between the persisted `scheduled_for` and the subject instant (`subject_instant − scheduled_for ≥ parameter_c × 60`, evaluated from persisted values, never a fresh clock read) | `lead_time_insufficient` |

#### 6.2.1 The complete required eligibility set (mandatory baseline + intent/audience matrix)

The "complete required eligibility set" is a closed definition, not an author's judgement: it is the
**union** of the mandatory baseline (a) and the intent/audience-specific mandatory tier (b). Activation
validates the union as a set of exactly-one members with a fixed binding; a version that omits,
duplicates, misbinds or claims an unauthorised combination fails closed with one of

- `eligibility_rule_set_incomplete` — a mandatory rule code for the version's intent tier or audience is
  absent, or the same `(rule_kind, rule_code)` is registered more than once;
- `eligibility_binding_mismatch` — a mandated rule is bound to the wrong subject aggregate, or
  `subject_state_is` declares an allowlist that does not equal its §6.2.2 binding's `to_state` set (empty,
  widened, narrowed or rebound), or a declared state is outside the owning module's state vocabulary;
- `audience_not_authorised` — the version's `audience`/`recipient_kind` pair is not an authorised pair in
  the matrix below.

The validated set is what `rule_set_digest` and `definition_fingerprint` freeze at activation (§6.1), so a
mandatory rule can neither be omitted at activation nor removed, swapped or rebound afterwards: a
post-activation change to `notification_workflow_rules` either hits the draft-only guard
(`workflow_rules_frozen`) or leaves the version failing digest revalidation
(`workflow_rule_set_mutated`) and un-dispatchable. Each read, enqueue and hand-off path re-derives the
required set from the version's own `intent_key`, `audience` and `recipient_kind` and re-checks it against
the frozen rules, so an activated version can never dispatch with a narrower set than the matrix requires.

**a. Mandatory baseline — required on every version of every intent and audience.**

| # | Required rule code | Binding enforced at activation | Absent/unsatisfied outcome |
| --- | --- | --- | --- |
| B1 | `subject_exists` | `parameter_a` is the subject aggregate §4 declares for this intent; the row resolves through that module's read model | `eligibility_unresolved` |
| B2 | `subject_state_is` | `parameter_a` is the subject aggregate of the intent's §6.2.2 binding, and the declared allowlist equals that binding's committed `to_state` set — derived from the matrix, never an author's choice — with every state drawn from the owning module's published vocabulary | `ineligible_subject_state` |
| B3 | `recipient_resolvable` | the version's audience/`recipient_kind` pair resolves a mapped recipient with a deliverable contact for that audience | `recipient_unresolved` |
| B4 | `recipient_opted_in` | the resolved channel-neutral consent fact is affirmative for that audience; S reads it and never derives it | `consent_absent` |
| B5 | `guardian_authority_present` | present on every activated version, whatever the audience: it passes through for an adult recipient and demands a present guardian authority whenever the resolved contact is a minor's or is held by a guardian acting for a minor | `authority_absent` |
| B6 | `not_suppressed` | no active suppression matches recipient + purpose | `suppressed` |

**b. Intent/audience-specific mandatory tier.**

| Tier | Consumed intents | Additional mandatory codes |
| --- | --- | --- |
| **F — forward-looking** (the send strictly precedes the subject instant) | `AUTOMATIC_RENEWAL_UPCOMING`, `MANUAL_RENEWAL_PAYMENT_REQUIRED`, `GUARANTEE_DEADLINE_APPROACHING` | `subject_instant_in_future` (strict: `scheduled_for < subject_instant`); `lead_time_at_least` with an explicitly declared `parameter_c = lead_time_minutes ≥ 0`, never greater than the version's §6.3 anchor rule `lead_time` |
| **P — post-fact** (the send reports an already-committed subject fact) | `AUTOMATIC_RENEWAL_CHARGED`, `AUTOMATIC_RENEWAL_FAILED`, `GUARANTEE_EXPIRED`, `PAYMENT_FAILED`, `PAYMENT_RECOVERED`, `TERM_LAPSED`, `REFUND_REVIEW_REQUIRED`, `REFUND_RESOLVED` | none beyond the baseline; B2 is the bound-evidence predicate of §6.2.2 and its allowlist is the binding's committed `to_state` set, never an author's choice |

The tier-F lead time is declared by the version twice, in two different roles, and S validates both and
never invents either value: the §6.3 **anchor rule** `lead_time` carries `lead_time_minutes ≥ 1` (how far
ahead of the announced instant the reminder is sent, so the derived instant is always strictly earlier
than the subject instant), and the eligibility rule carries `parameter_c = lead_time_minutes ≥ 0` (how
much slack must remain at the derived instant). Activation refuses a tier-F version whose anchor rule is
missing or below one minute with `schedule_composition_invalid` (§6.3(a)), and refuses an eligibility lead
time greater than the anchor rule's `lead_time_minutes` (the pair would be self-contradictory) with
`eligibility_binding_mismatch`. Tier F
therefore cannot activate without both eligibility codes, a declared non-negative eligibility lead time
and a declared anchor lead time of at least one minute, and no tier can activate without the bound
baseline evidence of §6.2.2 — a tier-F version additionally binds the authoritative instant its matrix
row names, and that instant must already be durably recorded on the owning subject row before the intent
is published: a version is refused at registration and activation with `tier_f_instant_unavailable` when
the bound instant is absent, and S never substitutes a recomputation from current policy or schedule
state (§6.2.4). `GUARANTEE_EXPIRED` stays listed in tier P because R2 declares the intent name, but it
binds no authoritative fact (§6.2.2), so it is reserved-unbound and stays unregisterable, unroutable and
undeliverable until a writer exists.

**c. Authorised audience/recipient_kind pairs (closed by default).**

| Audience | `recipient_kind` | Status | Required set |
| --- | --- | --- | --- |
| `student` | `student` | authorised; every §4 intent routes here | baseline B1–B6 + its tier (F or P) |
| `guardian` | `guardian` | **not authorised in S** — reserved for the authorising phase; activation fails closed with `audience_not_authorised` | — |
| `academy` | `staff` | **not authorised in S** (`academy`/staff is a new authorising phase, §4); activation fails closed with `audience_not_authorised` | — |

Unreadable required sources never default to eligible: a mandatory rule whose source cannot be read
resolves to its fail-closed outcome and the notification does not dispatch.

Rule attachment is **draft-only**, and activation is the freeze point. `set_eligibility_rule`,
`set_schedule_rule` and `set_retry_rule` refuse any version whose `state` is not `draft` or whose
`rule_frozen_at` is set, failing closed with `workflow_rules_frozen`; that predicate is the guarded
write, not an advisory check. Activation re-reads the complete rule set, validates it against the §6.2.1
mandatory baseline and tier, and writes
`rule_set_digest char(64)` — a digest over the ordered `(rule_kind, rule_code, ordinal, parameters)`
tuples — plus `rule_frozen_at` on the version row, together with `definition_fingerprint` and the routing
slots, in one transaction. Every read, enqueue and dispatch path recomputes that digest from the stored
rule rows and compares it with `rule_set_digest`; a mismatch (the signature of a post-activation append,
possibly applied outside the service layer) fails closed with `workflow_rule_set_mutated`, raises the
`workflow_version_integrity` diagnostic, and refuses hand-off rather than silently using the appended
rule. An activated version can therefore never gain eligibility, scheduling or retry behaviour without a
new version number and a new fingerprint.

#### 6.2.2 The closed intent → authoritative-fact binding matrix

B2 (`subject_state_is`) is not free-form and is never evaluated against a mutable aggregate row. Every
consumed intent binds exactly one **authoritative subject fact**: the append-only event the owning module
appended when that intent's fact committed. The binding is closed, is derived read-only from the R2
source, and no version can add to it, widen it, rebind it or substitute another aggregate's state.
Transitions below are written `from → to` with a null `from_state` shown as `(empty)`; every triple is a
legal entry of the owning module's locked transition table, so a bound event type that does not record
its claimed transition is a rewritten history rather than an alternative spelling of it.

Each row therefore names **exactly one** bound event type. Where one event type can legitimately record
more than one `from → to` variant (a `payment_required` event after either `pending` or
`guarantee_protected`; a `failed` collection intent after `submitted`; a `recovered` case after `open` or
`recovering`; a `lapsed` cycle after four predecessors), the variants are enumerated in that same row and
the frozen evidence tuple of §6.2.3 records the variant that actually occurred. An intent whose name R2
can publish from **two different event types** has no such row: the seam carries aggregate type,
aggregate id and intent name only, so nothing in the durable evidence could tell the two bound facts
apart. `AUTOMATIC_RENEWAL_UPCOMING` is exactly that case in the unamended R2 candidate (§1 fact 4), so the
matrix binds it to `opened` alone and §6.2.4(b) requires R2 to stop publishing it on `require_payment`;
the alternative — carrying an immutable originating-event reference in the seam — would be a deliberate
change to the shared Phase-1/R2 compatibility contract and is not taken here.

| Consumed intent | Subject aggregate | Bound event type | Bound committed transition(s) | B2 allowlist (`to_state` set) | Tier | Authoritative instant (tier F) |
| --- | --- | --- | --- | --- | --- | --- |
| `AUTOMATIC_RENEWAL_UPCOMING` | `renewal_cycle` | `opened` — the **sole** authoritative publish site: the advance notice is published in the cycle-open transaction, only when a durably recorded automatic charge instant exists, and never on a later transition (R2 amendment §6.2.4(b)) | `(empty) → pending` | `{pending}` | F | the subject cycle's persisted, immutable `automatic_charge_at` (§6.2.4): written once, in the same transaction as the cycle's `opened` event, from the `AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME` policy current at that instant, and bound before `AUTOMATIC_RENEWAL_UPCOMING` is published. S reads that column and nothing else; a NULL means the instant is not durably available, so the intent is not published, not registered and not delivered |
| `MANUAL_RENEWAL_PAYMENT_REQUIRED` | `renewal_cycle` | `payment_required` | `pending → payment_required`, or `guarantee_protected → payment_required` | `{payment_required}` | F | the subject cycle's persisted `guarantee_deadline_at`, exactly as recorded at (or before) the bound transition in the owning transaction (§6.2.4). S never derives it and never falls back to a same-slot re-resolution: a NULL at the bound fact is `tier_f_instant_unavailable` |
| `GUARANTEE_DEADLINE_APPROACHING` | `renewal_cycle` | `guarantee_protected` | `pending → guarantee_protected` | `{guarantee_protected}` | F | the subject cycle's persisted `guarantee_deadline_at`, written in the same transaction as the bound `guarantee_protected` event (§6.2.4). S never derives it and never falls back to a same-slot re-resolution: a NULL at the bound fact is `tier_f_instant_unavailable` |
| `GUARANTEE_EXPIRED` | — | — | **reserved-unbound: no R2 writer publishes this intent**, so it binds no transition | — | P (declared only) | — |
| `AUTOMATIC_RENEWAL_CHARGED` | `collection_intent` | `confirmed` | `submitted → confirmed` | `{confirmed}` | P | — |
| `AUTOMATIC_RENEWAL_FAILED` | `collection_intent` | `failed` | `submitted → failed` | `{failed}` | P | — |
| `PAYMENT_FAILED` | `collection_intent` | `failed` | `submitted → failed` | `{failed}` | P | — |
| `PAYMENT_RECOVERED` | `recovery_case` | `recovered` | `open → recovered`, or `recovering → recovered` | `{recovered}` | P | — |
| `TERM_LAPSED` | `renewal_cycle` | `lapsed` | `pending → lapsed`, or `guarantee_protected → lapsed`, or `payment_required → lapsed`, or `collected → lapsed` | `{lapsed}` | P | — |
| `REFUND_REVIEW_REQUIRED` | `refund_review` | `review_required` | `open → review_required` | `{review_required}` | P | — |
| `REFUND_RESOLVED` | `refund_review` | `resolved` | `review_required → resolved` | `{resolved}` | P | — |

Evidence for the table (read-only, 2026-09-24): the R2 publish sites
`RenewalCycleService::openCycle()` (the bound site of `AUTOMATIC_RENEWAL_UPCOMING`),
`RenewalCycleService::activateManualGuarantee()`, `RenewalCycleService::requirePayment()` (the bound site
of `MANUAL_RENEWAL_PAYMENT_REQUIRED` in `manual` mode only), `RenewalCycleService::lapse()`,
`CollectionIntentService::recordFailure()`/`confirm()`, `RecoveryService::markRecovered()` and
`RefundReviewService::routeForReview()`/`resolve()`, each paired with the append-only event it inserts,
against the locked `RecurringRule::AGGREGATE_EVENT_TRANSITIONS` table. Every one of those sites is the
single publish site of the intent it writes, except `RenewalCycleService::intentForTransition()`, which
in the unamended candidate also returns `AUTOMATIC_RENEWAL_UPCOMING` for `require_payment` in `automatic`
mode — the second site §1 fact 4 records and §6.2.4(b)(6) removes. A binding is the *only* durable
evidence an S notification may use: the outbox row R2 writes carries aggregate type, aggregate id and
intent name only, so the intent name plus the subject aggregate identifies the bound fact and nothing else
may stand in for it.

Because R2 writes at most one outbox row per `(aggregate, aggregate_id, intent)` and the locked
transition table makes every bound transition reachable at most once per aggregate, the bound fact is
unique for a given notification — which is what lets B2 be decided from history alone without a mutable
"current state" read. That uniqueness is a consequence of the one-event-type-per-row rule above: with the
amended publication (§6.2.4(b)) the row's bound event type occurs at most once in the cycle's history, so
a late observation after `payment_required`, `collected`, `term_bound` or even `lapsed` still resolves to
the same single `opened` fact. The same uniqueness is what makes the tier-F announced instant of §6.2.4
unique per notification: one bound fact, one persisted instant, one derived send instant, with no
re-derivation and no second candidate instant to choose from.

#### 6.2.3 Bound-evidence evaluation, successor states and terminal handling

`subject_state_is` is evaluated as a **history predicate over the bound transition set**, identically at
enqueue/observation and again on the dispatch claim path:

1. **Resolve.** The predicate resolves the subject aggregate row named by `parameter_a` through the
   owning module's read model and reads that aggregate's append-only event history
   (`dzn_renewal_cycle_events`, `dzn_collection_intent_events`, `dzn_recovery_case_events` or
   `dzn_refund_review_events`).
2. **Match.** It passes iff the history contains at least one event whose
   `(event_type, from_state, to_state)` triple equals a bound transition of the §6.2.2 row for the
   version's intent — one of that row's enumerated variants where the row lists more than one, all with
   the row's single bound event type — recorded no later than the notification's persisted `observed_at`
   (§7.2). The event's `to_state` must be a member of the version's declared allowlist, and activation
   requires that allowlist to *equal* the matrix row's `to_state` set — a version may neither widen nor
   narrow its own B2 binding, and `parameter_a` must be the matrix row's aggregate. The matched variant is
   the one the §6.2.3 evidence digest freezes, so a later reader reproduces exactly the tuple B2 was
   decided on.
3. **No match.** Absence of every bound transition — an intent whose fact never committed, a forged or
   rewritten history, an intent whose fact belongs to a different aggregate, and the reserved-unbound
   `GUARANTEE_EXPIRED` — fails closed with `ineligible_subject_state`. An unreadable source fails closed
   the same way. Nothing is repaired, inferred from another intent's transition, or evaluated from the
   aggregate's current row.

**Successor-state semantics.** A bound fact is immutable history. Later legal transitions of the same
subject aggregate — for example a cycle that reaches `payment_required`, `collected` or `term_bound`
after an `AUTOMATIC_RENEWAL_UPCOMING` advance notice was published from the cycle's `opened` fact, or a
`collection_intent` that reaches `recovered` after a `PAYMENT_FAILED` — never invalidate the binding and
never move B2 from pass to fail. The `opened` fact is what the intent is bound to, not the cycle's
current state, so a cycle that has already moved through `payment_required` when S first reads it still
resolves to that one bound transition. Equally, a later transition never makes an unbound intent valid:
an intent still needs its own bound transition in the history, with the row's own event type. Late
observation (S first reads the row after the aggregate has moved on) and late dispatch (the notification
was enqueued earlier and is claimed after further R2 transitions) therefore evaluate the same immutable
evidence and reach the same verdict, and the `observed_at` used to bound the match is the notification's
own persisted observation instant rather than a fresh clock reading.

**Terminal handling.** A bound notification's terminal decisions remain S's own: `expired` when the
derived instant is no longer valid (`eligibility_expired` for a tier-F instant that is no longer ahead of
the send instant, or the scheduling expiry), `suppressed` on a suppression, `cancelled` by explicit
command, and `failed` on retry exhaustion (`retry_exhausted`, the non-terminal ceiling) or on a
`terminal`-class closure (its own declared reason code, §6.6/§9). None of them is caused by the subject
aggregate moving on, and no successor transition re-arms a terminal notification or revives an expired
one. Where a
subject aggregate becomes terminal while an earlier intent is still undelivered, the earlier intent stays
bound to its own frozen evidence and the later fact (for example `TERM_LAPSED`) is observed as its own
notification; only the workflow's `coalesce` rule (§6.3) may fold them, never a subject-state lookup.

Every enqueue and dispatch evaluation records the frozen bound tuple as digest-only evidence on the
notification event:
`hash_hmac('sha256','bound_evidence:' || subject_aggregate || ':' || subject_aggregate_id || ':' ||
event_type || ':' || from_state || ':' || to_state || ':' || occurred_at, wp_salt('dzn_notification'))`.
(The digests use the stored column values; a null `from_state` is the empty string, exactly as the owning
tables spell it.) A later reader, replay or recovery re-derives that digest from the subject history and
must reproduce it exactly, so it can prove which immutable fact a dispatch was decided on; a divergence
fails closed with `ineligible_subject_state` rather than re-deciding from the aggregate's current row.

#### 6.2.4 Tier-F authoritative instants: durable source, binding and fail-closed consumption

A tier-F send precedes the fact it announces, so its announced instant cannot be read from the bound
transition's own recorded timestamps and cannot be derived again later without depending on mutable
configuration. It must therefore be a **persisted subject fact, recorded before the intent is published**,
and it is the only instant S may schedule from. This subsection is the closed definition of that source,
of the R2 prerequisite that makes it durable, and of the behaviour when it is absent.

**a. Durable source per tier-F intent.** The instant is one column value on the bound subject aggregate's
own row — the same row §6.2.2 binds — read through that module's read model:

| Tier-F intent | Authoritative instant | Recorded |
| --- | --- | --- |
| `AUTOMATIC_RENEWAL_UPCOMING` | `dzn_renewal_cycles.automatic_charge_at` | once, in the transaction that inserts the cycle and its bound `opened` event, from the `AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME` policy version current at that instant |
| `MANUAL_RENEWAL_PAYMENT_REQUIRED` | `dzn_renewal_cycles.guarantee_deadline_at` | at the cycle's `opened` event when the cycle opened with a resolved deadline (today's writer), and otherwise in the same transaction as the bound `guarantee_protected` event; never filled after the bound fact commits |
| `GUARANTEE_DEADLINE_APPROACHING` | `dzn_renewal_cycles.guarantee_deadline_at` | in the same transaction as the bound `guarantee_protected` event |

Both columns are immutable once written: a later correction is a new recorded fact or a new aggregate
version, never an in-place rewrite of the instant an already-published notice announced. Where the owning
service already writes the deadline (today's `guarantee_deadline_at`), the amendment below binds it as
immutable-after-write rather than introducing a second source.

**b. The required R2 amendment (a prerequisite, §17).** Because the R2 candidate recomputes rather than
records the automatic charge instant, S requires this bounded amendment before any tier-F version is
registered or activated:

1. `dzn_renewal_cycles` gains `automatic_charge_at datetime NULL`. It is nullable only because the
   `AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME` policy may be *unset* — an unset policy is a recorded deliberate
   state (`CommercialPolicyService::current()` returns `set = false`), not a missing one — and the column
   is written at most once and never rewritten.
2. `RenewalCycleService::openCycle()` writes that value in the same transaction as the cycle insert and
   the `opened` event, from the policy current at that instant — the last moment at which the announced
   instant is still `boundary_derived_at − lead`, so it is the correct and only moment to freeze it.
3. `AUTOMATIC_RENEWAL_UPCOMING` is published **only when the persisted value is non-null**; when it is
   NULL the intent is not published at all, so no S-visible evidence exists for an announcement that was
   never scheduled. That is the current behaviour of `open_cycle` and, with item 6 below, the only publish
   site of that intent.
4. `CollectionIntentService::open()` reads the persisted cycle column instead of calling
   `RenewalCycleService::automaticChargeAt()`, so the `automatic_charge` collection intent's `charge_at`
   is the same instant the advance notice announced even if the policy changed in between, and no charge
   can ever be scheduled from a policy version the notice never saw.
5. `RenewalCycleService::activateManualGuarantee()` keeps writing `guarantee_deadline_at` in the bound
   transaction, and the column is never rewritten once non-null; its internal
   `guaranteeFallback()`/`guaranteeDeadlineForPattern()` derivation remains R2's own write-time
   resolution and is **never** an S input — S reads the committed column only.
6. `RenewalCycleService::intentForTransition()` must not return `AUTOMATIC_RENEWAL_UPCOMING` for
   `require_payment` in `automatic` mode, so a `payment_required` transition publishes no second copy of
   the advance notice: that intent is published exactly once, in the transaction that commits its bound
   `opened` fact (§6.2.2), and its name identifies that fact rather than any later transition the cycle
   reaches. The `MANUAL_RENEWAL_PAYMENT_REQUIRED` publication for `require_payment` in `manual` mode is
   unchanged, because that intent is bound to the `payment_required` event in its own right. Because the
   R2 candidate already deduplicates the second publication onto the cycle-open row
   (`RecurringOutboxRepository::intentKey()`), removing it changes no durable row, no replay result and no
   existing test expectation; it removes an ambiguous claim rather than behaviour. The deliberate
   consequence is that a cycle opened while `AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME` was unset is never
   announced and is never announced late: with no recorded instant there was no advance notice to
   schedule, and reaching `payment_required` later cannot manufacture one (§5 safe default,
   §6.2.4(b)(3)).

Renaming or dropping either column, filling the instant after the bound fact commits, or publishing a
tier-F intent without a recorded instant is refused, not adapted to. The same applies to re-widening this
intent's publication: an implementation that publishes `AUTOMATIC_RENEWAL_UPCOMING` from `require_payment`
again makes the §6.2.2 binding unsatisfiable and is refused, not accommodated with a candidate-choice
rule at dispatch.

**c. S-side consumption rule.** S schedules a tier-F notification from exactly one input: the persisted
column value of the bound subject row, read in the same read-model resolution that produces the §6.2.3
bound evidence and no later than the notification's recorded observation instant.

- `CommercialPolicyService`, `CommercialRule` policy values, `RecurringRule::MANUAL_RENEWAL_SLOT_GUARANTEE_WEEKS`,
  the pattern wall-clock resolver and the current schedule rows are **never** S inputs. S does not import,
  call or read them, does not compute a charge lead time, and does not re-resolve a guarantee window.
- The derived base instant, `scheduled_for`, `expires_at` and `platform_outbox.available_at` are a pure
  function of the frozen instant, the version's frozen schedule rules (including the single shared
  `timezone_basis`), the notification's frozen identity and its persisted `deferral_count` (§6.3). A later
  re-derivation for replay, recovery or diagnostics must reproduce the persisted instant exactly;
  a divergence raises `tier_f_instant_divergence` and refuses to schedule or dispatch — never a silent
  reschedule from current configuration.
- The frozen instant is digest-bound into the notification's dispatch evidence alongside the §6.2.3 bound
  tuple, so a later reader can prove which persisted instant a dispatch was decided on.

**d. Fail-closed behaviour when the instant is unavailable.** An unavailable instant is never repaired,
defaulted, or waited on:

- Registration or activation of a tier-F version is refused with `tier_f_instant_unavailable` when the
  bound intent's instant source is absent from storage (the R2 amendment not yet merged) or the column is
  not readable as the version's matrix row requires. The refusal is a closed failure, not a deferral.
- Observation of a tier-F intent whose bound subject row carries a NULL instant creates no dispatchable
  notification: the observation closes terminally with `failure_reason_code = tier_f_instant_unavailable`,
  raises the same-named diagnostic, and schedules, leases and sends nothing. The notification is never
  left `pending` waiting for configuration.
- An already-observed notification whose persisted instant can no longer be read (row deleted, column
  nulled, digest mismatch) closes the same way on the next read rather than being re-derived.
- None of these paths may fall back to `automaticChargeAt()`, to a pattern re-resolution, to a
  `boundary_derived_at − lead` recomputation or to any wall-clock estimate.
- The one alternative to failing closed — a later phase persisting every immutable derivation input a
  tier-F instant needs (lead-time policy version, boundary, pattern timezone/wall time/duration/buffer) so
  the instant is reproducible from frozen rows — is **not** available to S: it would require its own
  authorising phase, its own contract amendment and its own coverage. Until then, a missing deadline is
  `tier_f_instant_unavailable` and never a recomputation.

**e. Post-publication invariance.** Once the instant is recorded, no later change may move it or the
instant derived from it: a new `AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME` policy version, a changed
`MANUAL_RENEWAL_SLOT_GUARANTEE_WEEKS` value, a mutated `dzn_commercial_recurring_patterns` row (timezone,
wall time, duration or buffer), a moved boundary, a superseded schedule version, a restored backup or a
restarted process leaves the frozen instant, the derived `scheduled_for`, the §6.2.3 B2 verdict and the
notification identity unchanged. The only legitimate movement of `scheduled_for` is a bounded deferral
derived from the frozen base and the persisted `deferral_count` (§6.3(e)) — an internal, contract-defined
step that no external change can cause, and one that leaves the frozen instant, the base instant,
`expires_at` and the identity untouched. §15 proves this with dedicated coverage for both the announcement
and the collection side.

Evidence for this gap and for the amendment (read-only, 2026-09-24): `dzn_renewal_cycles` is created with
no automatic-charge column, `RenewalCycleService::automaticChargeAt()` returns the policy-derived instant
(or NULL when `AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME` is unset), `openCycle()` publishes
`AUTOMATIC_RENEWAL_UPCOMING` only when that value is non-null, `activateManualGuarantee()` fills
`guarantee_deadline_at` through `guaranteeFallback()` when the column is NULL, and
`CollectionIntentService::open()` re-derives `charge_at` from the same policy call. The durability gap is
therefore confirmed in source rather than inferred, and the amendment above is the smallest change that
closes it without introducing a second scheduling authority. The publication ambiguity of §1 fact 4 is
confirmed the same way, in the same two functions: `openCycle()` publishes the intent at the `opened`
event, `intentForTransition('require_payment','automatic')` returns the same name for the later
`payment_required` event, and `RecurringOutboxRepository::publish()` returns early on the already-present
`intentKey()`, so the second site adds a claim of authority with no second row to carry it. Amendment
item 6 removes the claim; it does not change what any cycle records.

### 6.3 Scheduling

Scheduling is one frozen, total function. It derives exactly one instant set from authoritative facts —
never wall-clock guessing, never `intro + n`, never a provider timestamp — and it is defined in full here,
because a list of rule codes without a composition, an ordering, a timezone basis, a re-derivable deferral
term and an expiry anchor would leave the retry window, the deferred instant and the tier-F send ordering
unreproducible.

Registered schedule rule codes (`rule_kind = 'schedule'`): `immediate`, `lead_time`
(`lead_time_minutes`), `fixed_local_time` (`local_time`, `timezone_basis`), `send_window`
(`send_window_start_local`, `send_window_end_local`, `weekday_mask`, `timezone_basis`), `deferral`
(`defer_ceiling_minutes`, `max_deferrals`), `coalesce` (`coalesce_window_minutes`), `expiry`
(`expiry_minutes`).

**Canonical parameter encoding (one row per parameter).** A registered schedule rule is written to
`dzn_notification_workflow_rules` as one row per parameter, `ordinal` counting from `1` with no gap, the
parameter value in `parameter_a` as canonical text and `parameter_b`/`parameter_c`/`parameter_d` NULL; a
code that carries no parameter (`immediate`) is written as exactly one row with `ordinal = 1` and every
parameter column NULL. Canonical text is a decimal integer `0` or `[1-9][0-9]*` — no sign, no leading
zero, no whitespace, no fractional part — or, where the table names a non-numeric parameter, the exact
lexical form it names. No schedule bound is therefore ever carried through a signed `int` column, and a
negative, zero-padded, floating, extra, missing or non-contiguous parameter is a malformed rule rather
than a value to interpret. `ordinal` is the parameter position, so a code's row count and ordinals are
part of the frozen composition and of `rule_set_digest`.

| Code | `ordinal` → parameter | Canonical encoding | Admissible range (validated at activation) |
| --- | --- | --- | --- |
| `immediate` | `1` → (none) | — (every parameter column NULL) | — |
| `lead_time` | `1` → `lead_time_minutes` | decimal minutes | `1 ≤ lead_time_minutes ≤ 525600000` |
| `fixed_local_time` | `1` → `local_time`; `2` → `timezone_basis` | `HH:MM` 24-hour zero-padded; basis vocabulary | `00:00 ≤ local_time ≤ 23:59`; basis ∈ `{recipient_local, academy_local, subject_local}` |
| `send_window` | `1` → `send_window_start_local`; `2` → `send_window_end_local`; `3` → `weekday_mask`; `4` → `timezone_basis` | `HH:MM`; decimal bitmask; basis vocabulary | `00:00 ≤ start < end ≤ 23:59` (a zero-length or reversed window is malformed); `1 ≤ weekday_mask ≤ 127` with at least one bit set; basis ∈ the three-value vocabulary |
| `deferral` | `1` → `defer_ceiling_minutes`; `2` → `max_deferrals` | decimal minutes; decimal count | `1 ≤ defer_ceiling_minutes ≤ 52560000`; `0 ≤ max_deferrals ≤ 65535`; and `defer_ceiling_minutes × max_deferrals ≤ 52560000` |
| `coalesce` | `1` → `coalesce_window_minutes` | decimal minutes | `1 ≤ coalesce_window_minutes ≤ 525600000` |
| `expiry` | `1` → `expiry_minutes` | decimal minutes | `1 ≤ expiry_minutes ≤ 525600000` |

The deferral bounds are load-bearing, not cosmetic. `defer_ceiling_minutes` **is** the whole schedule
movement one deferral can cause, so a step of `0` would leave the derivation unmoved and a negative step
(unrepresentable in canonical text) would move it backwards; `deferral_count` is `smallint unsigned`
(§7.2), so a maximum above `65535` names a count the persisted column could never hold; and the product
bound `defer_ceiling_minutes × max_deferrals ≤ 52560000` (one hundred years of minutes) bounds the
largest offset the derivation can ever add. The outer bounds of `lead_time_minutes`,
`coalesce_window_minutes` and `expiry_minutes` serve the same purpose: they keep the anchor, bucket and
window arithmetic inside the same domain. Every instant in the derivation below is computed in 64-bit
**integer seconds** — never a float and never a calendar-arithmetic shortcut — and must land inside the
`datetime` domain the schema stores (`1000-01-01 00:00:00` … `9999-12-31 23:59:59` UTC). An out-of-range
parameter or a composition whose maximum offset cannot be derived is refused at activation
(`schedule_composition_invalid`); an out-of-domain intermediate or result at derivation time fails closed
as a non-reproducing derivation (`schedule_derivation_divergence`) — never by wrapping, clamping or
truncating.

`timezone_basis` is a **vocabulary value** — exactly one of `recipient_local`, `academy_local`,
`subject_local` — and nothing more at activation time. A workflow **version** is a definition: it has no
concrete recipient and no concrete subject instance, so `recipient_local`/`subject_local` have nothing to
resolve against and activation validates the vocabulary and the inter-rule equality rule below, never a
resolution. The basis is resolved **once per notification**, in `observe_intent`
(§6.3(b)/§8.1), against the concrete recipient/subject instance that observation resolves, and the
resolved IANA zone is persisted as `dzn_notifications.timezone`. A basis that cannot resolve at
observation — an unknown, absent or unreadable recorded zone on the resolved instance — is a terminal,
fail-closed **observation outcome**, not an activation or verifier finding: no dispatchable notification
is created, the observation closes terminally with `failure_reason_code = schedule_timezone_unresolved`
and the same-named diagnostic, no instant is derived or persisted, and nothing is scheduled, leased or
sent. It is never defaulted to a system zone, never deferred, and never left `pending`.

**Every timezone-sensitive rule of one version names the same basis.** `fixed_local_time` and
`send_window` are the two timezone-sensitive rule codes, and the derivation persists exactly one resolved
zone (`dzn_notifications.timezone`). A version registering both with different bases therefore has no
unique persisted derivation — placement in one zone and windowing in another would be unverifiable from
one stored zone — so it is refused at activation with `schedule_timezone_basis_conflict` and is **not**
resolved by preferring either zone or by defining a cross-zone order: one version, one basis, one resolved
zone, used by every wall-clock step of the derivation below. A different basis is a different version.
Where a version registers no timezone-sensitive rule there is no basis to name and nothing to resolve
(`timezone` is persisted empty, §6.3(b)).

**a. Composition (closed; validated at activation).** A version registers exactly one anchor rule and a
fixed optional set; every other cardinality, a tier-mismatched anchor and an unregistered
`rule_kind = 'schedule'` code are refused at activation with `schedule_composition_invalid`:

| Slot | Cardinality | Code and parameters | Permitted tier |
| --- | --- | --- | --- |
| Anchor | exactly one | `lead_time` with `1 ≤ lead_time_minutes ≤ 525600000` | F only |
| Anchor | exactly one | `immediate` | P only |
| Local placement | 0 or 1 | `fixed_local_time` (`local_time`, `timezone_basis`) | F and P |
| Send window | 0 or 1 | `send_window` (`send_window_start_local`, `send_window_end_local`, `weekday_mask`, `timezone_basis`) | F and P |
| Deferral | 0 or 1 | `deferral` (`1 ≤ defer_ceiling_minutes ≤ 52560000`, `0 ≤ max_deferrals ≤ 65535`, `defer_ceiling_minutes × max_deferrals ≤ 52560000`) | F and P |
| Coalesce | 0 or 1 | `coalesce` (`coalesce_window_minutes`) | F and P |
| Expiry | exactly one | `expiry` with `1 ≤ expiry_minutes ≤ 525600000` | F and P |

Zero or two anchors, a `lead_time` anchor on a tier-P intent, an `immediate` anchor on a tier-F intent, a
second instance of any 0-or-1 code, a schedule parameter outside the canonical encoding or the admissible
range of the table above (a signed, zero-padded, fractional, extra, missing or non-contiguous parameter
row, a `defer_ceiling_minutes` below `1`, a `max_deferrals` above `65535`, or a deferral product above its
bound), a basis outside the three-value vocabulary, two timezone-sensitive rules naming different bases
(its own `schedule_timezone_basis_conflict`), and the missing or malformed `expiry` rule (refused with its
own `schedule_expiry_missing`, below) are all activation failures — the composition failures with
`schedule_composition_invalid`. The composition — including each rule's parameters, their ordinals and the
shared basis — is part of `rule_set_digest`/`definition_fingerprint`, so it can never change under a live
dispatch (§6.2). A tier-F version's eligibility `lead_time_at_least` must not exceed its anchor rule's
`lead_time_minutes` (§6.2.1), which keeps the declared pair satisfiable.

Activation validates the basis **vocabulary and inter-rule equality only**: a version has no recipient
instance and no subject instance, so a basis is never resolved here, and an `active` version is never
rejected for a basis that has nothing to resolve against yet (§6.3 opening paragraph). Resolution
belongs to observation.

**b. Inputs (immutable, and all persisted).** `observed_at` — the notification's own persisted observation
instant (`dzn_notifications.observed_at`, written exactly once in the observation transaction).
`subject_instant` — the tier-F authoritative instant read from the owning subject row's persisted column
(§6.2.4); absent for tier P. `timezone` — the resolved IANA timezone of the version's **single shared**
basis (above), resolved **once per notification**, in the observation transaction and against the
concrete instance the observation resolved — never at activation, where no such instance exists — and
persisted as `dzn_notifications.timezone`; it is the zone of every wall-clock step below, and it is
persisted empty exactly when the version registers no timezone-sensitive rule. An unresolvable basis here
closes the observation terminally with `schedule_timezone_unresolved` (§6.3 opening paragraph) instead of
persisting a row that could never be derived. The zone is frozen with the row: a later change to the
recipient's or the subject's recorded timezone never re-resolves it, so replay reproduces the persisted
value (`schedule_derivation_divergence` otherwise). `deferral_count` — the notification's own persisted count
(`dzn_notifications.deferral_count`), `0` when the row is created and incremented exactly once per
accepted deferral (§6.3(e)); the deferred instant is recomputed from it, never shifted from the previously
stored instant. `rules` — the version's frozen schedule rules. Nothing else is an input: no ambient clock,
no current commercial policy, no current pattern or schedule row, and no previously persisted
`scheduled_for` (§6.2.4(c)).

**c. Derivation (one frozen order).**

1. **Anchor.** `anchor_at = observed_at` for the `immediate` anchor (tier P). `anchor_at = subject_instant
   − lead_time_minutes × 60` for the `lead_time` anchor (tier F, `lead_time_minutes ≥ 1`), so a tier-F
   anchor is always strictly earlier than the instant it announces. The anchor is frozen at the
   `scheduled` transition (`schedule_anchor_at`) and is never moved by a deferral: a deferral moves the
   derived instant, not the anchor.
2. **Local placement.** With `fixed_local_time` registered, `placed_at` is the earliest instant `≥
   anchor_at` whose local wall clock in `timezone` equals `local_time` exactly, resolved through the
   canonical Phase-Q wall-clock rule so a DST transition moves the UTC instant without moving the agreed
   local time. Without the rule, `placed_at = anchor_at`.
3. **Send window.** With `send_window` registered, `window_at` is the earliest instant `≥ placed_at` inside
   a permitted window: the weekday *in the same shared `timezone`* is in `weekday_mask`, and the local
   time-of-day lies in the half-open interval `[send_window_start_local, send_window_end_local)` — an
   instant at the start is inside, an instant at the end is outside. Without the rule, `window_at =
   placed_at`. The roll-forward is bounded: if the mask admits no permitted instant within 14 days of
   `placed_at`, the derivation fails closed with `schedule_composition_invalid` and nothing is scheduled.
   Steps 2 and 3 evaluate wall clocks in the one shared zone, so there is no cross-zone ordering to
   define and no second persisted timezone.
4. **Base instant.** `derivation_base_at = window_at` in UTC. This is the frozen base of the window and of
   every deferral: it is not a separate column, because it is re-derived exactly from the persisted anchor
   and the frozen rules, and it is what the persisted results below are both defined against.
5. **Deferral term.** `scheduled_for = derivation_base_at + deferral_count × defer_ceiling_minutes × 60`
   when a `deferral` rule is registered; when none is registered `deferral_count` must be `0` and
   `scheduled_for = derivation_base_at`. So the deferred instant is one integer multiple of a frozen step
   away from the base — never an accumulated re-shift of the previous `scheduled_for` — and the count is
   the only runtime input. The product and its addition are evaluated in 64-bit integer seconds over the
   bounded parameters of the encoding table above, so the term cannot overflow. `deferral_count ≤
   max_deferrals` always; a row carrying `deferral_count > 0`
   under a version that registers no `deferral` rule, or a count above `max_deferrals`, fails closed with
   `schedule_derivation_divergence` (it is never clamped silently).
6. **Expiry — anchored to the base, never to the deferred instant.** `expires_at = derivation_base_at +
   expiry_minutes × 60` for a tier-P intent, and `expires_at = min(subject_instant, derivation_base_at +
   expiry_minutes × 60)` for a tier-F intent. The window therefore does **not** move with
   `deferral_count`: it is anchored to the same frozen base as the anchor, which is exactly what makes "a
   deferral never crosses the frozen window" (§6.3(e)) a checkable statement, and it is why the verifier
   compares `expires_at` against the base formula rather than against the persisted `scheduled_for`
   (§7.3). The tier-F cap keeps a forward-looking window from outliving the fact it announces. Both forms
   are pure functions of persisted values, so replay, recovery and diagnostics reproduce `expires_at`
   exactly. `expires_at` is written at the `scheduled` transition and a deferral never rewrites it.
7. **Coalesce bucket.** With `coalesce` registered, `coalesce_bucket = floor(anchor_at /
   (coalesce_window_minutes × 60))` — a decimal bucket index over the epoch, computed from `anchor_at` in
   UTC; without the rule it is the empty string. It is anchor-derived, so a deferral never re-buckets a
   row. It enters the §9 identity, so intents of one workflow, audience and recipient whose anchors fall
   in one bucket converge on exactly one notification, the row created for a bucket keeps the
   `scheduled_for` derived from the first observation in it (subsequently moved only by its own bounded
   deferrals, §6.3(e), never by a later observation), and a later observation in the same bucket never
   moves that instant.
8. **Result and mirror.** `scheduled_for` in UTC is persisted on the notification and mirrored to
   `platform_outbox.scheduled_for` — the **immutable schedule mirror**, which no retry ever rewrites —
   and to `available_at`, the claim instant the existing `available` index reads, which starts equal to
   that mirror; `expires_at` and `deferral_count` are mirrored to the outbox as well, so the dispatch
   representation is complete (§6.3(f), §7.1). The outbox row is a **mirror and dispatch index, not an
   independent derivation root**: it deliberately does not carry `observed_at`, the resolved `timezone`,
   `schedule_anchor_at` or the frozen tier-F subject instant, so no path re-derives a schedule from the
   outbox row alone. What the verifier and the dispatch path do instead is compare the mirrored
   `scheduled_for`/`expires_at`/`deferral_count` against the notification aggregate's locked §6.3
   derivation, both rows read in one transaction under the §10 lock order, and check `available_at`
   against its own two-value rule (§7.3); a mirror that disagrees with the aggregate (or with the
   aggregate's re-derived values) is a divergence (§6.3(f)/§7.3), never a second derivation to reconcile.
   `available_at` is the **only** mirrored column a §9 retry re-arms, and only for a closure that re-arms
   under both §9 gates (an attempt remaining **and** a clamp that leaves a usable window): such a
   `retryable`, `defer` or
   lease-expiry closure moves it to the persisted `next_available_at` of the closing attempt (§9) and
   never rewrites the mirrored `scheduled_for`, which follows the aggregate alone — the `scheduled`
   transition and a §6.3(e) accepted deferral are the only writes that move both together. Before the
   `scheduled` transition the outbox row carries the identity columns only, the derived triple
   (`scheduled_for`, `expires_at`, `deferral_count`) stays NULL, and the row is not claimable; the
   `available_at` value R2 wrote is left untouched until the `scheduled` transition overwrites it with
   the derived instant (§6.3(f), §7.1, §9).

**d. Tier-F postcondition (strict, never equality; applied to the final result).** After step 5 — the
deferred result, not the base — the derivation asserts `scheduled_for < subject_instant` strictly.
Equality is not "ahead of": a composition, a window roll-forward or a deferral that would place the send at
or after the announced instant fails closed — the notification closes terminally as `expired` with
`failure_reason_code = eligibility_expired`, no lease is taken and nothing is sent. The tier-F suites
therefore assert the strict relationship over the final `scheduled_for`, including after deferrals, and
its invariance; never equality with the announced instant, and never the base instant's relationship on
its own.

**e. Deferral (runtime, pre-dispatch, bounded by the frozen window).** A registered `deferral` lets a
notification be deferred at most `max_deferrals` times. A deferral increments the persisted
`deferral_count` by exactly one and re-derives `scheduled_for` with the step-5 formula — one bounded step
of `defer_ceiling_minutes` per count, recomputed from the persisted base rather than shifted from the
previous instant, so a replayed or repeated deferral cannot accumulate an off-by-one offset — persisted
together with the incremented count on the notification and mirrored to the outbox, consuming no attempt
(§9). A deferral is refused when the moved instant would break the frozen contract, and the refusal is
terminal rather than a deferral into an undispatchable state: the notification closes with nothing sent
and no lease taken when the moved instant is not strictly earlier than the frozen `expires_at`
(`expired` / `retry_window_exhausted`, so deferral never crosses a non-null `expires_at`), or when it
breaks the tier-F postcondition of (d) (`expired` / `eligibility_expired`) or another instant-based tier-F
predicate of the version evaluated over the moved instant — `lead_time_at_least`
(`expired` / `lead_time_insufficient`, §6.2).

**f. Persistence and re-derivation.** The derivation's inputs and results are durable: `observed_at`,
`timezone` and `schedule_anchor_at` (step 1) on the notification, `scheduled_for` and `deferral_count` on
the notification and mirrored to the outbox, `expires_at` on both, and the frozen rule set behind
`rule_set_digest`/`definition_fingerprint`. A re-derivation for replay, recovery or diagnostics recomputes
the whole algorithm from those persisted values — steps 1–3 for the base instant, step 5 for
`scheduled_for`, step 6 for `expires_at` — **including the persisted `deferral_count`**, and must
reproduce `schedule_anchor_at`, `scheduled_for`, `expires_at`, the persisted `timezone` and the coalesce
bucket exactly. The two formulas a verifier applies are therefore:

```
derivation_base_at = window_at(anchor_at(observed_at, subject_instant, lead_time_minutes), shared timezone, frozen rules)
scheduled_for      = derivation_base_at + deferral_count × defer_ceiling_minutes × 60   // 0 when no `deferral` rule
expires_at         = derivation_base_at + expiry_minutes × 60                           // tier P
                   = min(subject_instant, derivation_base_at + expiry_minutes × 60)     // tier F
```

and never `expires_at = persisted scheduled_for + expiry_minutes × 60`, which would silently re-anchor the
window on every accepted deferral and make a legitimately deferred row indistinguishable from a corrupted
one. A divergence raises `schedule_derivation_divergence` and refuses to schedule, defer or dispatch —
never a silent reschedule from a fresh clock, from current configuration or from the previously persisted
`scheduled_for`. This is the schedule-side counterpart of the §6.2.4 tier-F instant rule.

**Expiry is mandatory on every activated version, and non-null on every row that has derived an instant.**
Every activated version registers exactly one `expiry` rule with `1 ≤ expiry_minutes ≤ 525600000`,
declared by the version and never invented by S (presence, shape and range are validated like every other
schedule rule); activation refuses a version without it with `schedule_expiry_missing`, the verifier
rejects an `active` version without it (§7.3), and the window is derived by the exact formula of step 6
above — from the frozen base instant, `derivation_base_at + expiry_minutes × 60`, capped at the announced
subject instant for a tier-F intent, and never from the deferred `scheduled_for` — from persisted values
only. Consequently `expires_at` is non-null on every S-owned notification that has reached `scheduled`
**and on every `platform_outbox` row enriched for one**, so the §9 retry clamp always has a defined expiry
term for dispatchable work S owns. A row that has **not** reached `scheduled` — a `pending` notification,
or a terminal observation closed before any instant was derived (an unavailable tier-F instant,
§6.2.4(d); an unresolvable timezone basis, §6.3) — legitimately carries `expires_at IS NULL`, and on an
S-owned row `scheduled_for` and `expires_at` are non-null **together** and NULL **together**: there is no
state in which one is present and the other absent (§6.5, §7.2, §7.3).

**Explicit NULL rule (total, identical on every path).** A row that carries `expires_at IS NULL` — an
S-owned row that has not reached `scheduled`, a legacy `platform_outbox` row S never claims (§12), or a
deliberately forced fixture — has **no expiry window**: the expiry clamp term is omitted from the retry
minimum (§9), the "not strictly earlier than `expires_at`" window check is not evaluated, expiry-window
exhaustion is unreachable so `retry_window_exhausted` is never produced, deferral is bounded only by
`defer_ceiling_minutes`/`max_deferrals`, and no other expiry comparison runs anywhere. Schedule
derivation, persistence, replay, lease-expiry recovery, verifier checks and tests all implement this same
rule, so no comparison against `expires_at` is ever undefined. S never dispatches, leases or schedules work
from such a row: every row S schedules carries a derived, non-null `expires_at`, and the pre-scheduling
NULL state is exactly what the §7.3 verifier exempts by keying its non-null rule on
`scheduled_for IS NOT NULL`.

### 6.4 Templates and rendered snapshots

`notification_templates` is the identity root (`template_key`, `purpose`, `locale`, `state`,
`current_version`). `notification_template_versions` is immutable: `version_number`, `locale`,
`subject_template_digest char(64)`, `body_template_digest char(64)`, `variable_contract_digest char(64)`,
`required_variable_count`, `direction = 'outbound'`, `definition_fingerprint`. No provider template
name, provider template ID or provider language tag may ever be stored (that is Phase T).

`notification_rendered_snapshots` freezes exactly what was rendered:

- `notification_id`, `template_version_id`, `sequence`, `supersedes_snapshot_id`;
- `params_digest char(64)` and `variable_codes varchar(191)` (the sorted allowlisted variable names
  actually populated) plus `variable_count`;
- `rendered_params_envelope` — the parameter values under authenticated encryption (§11) with
  `cipher_version`;
- `locale`, `timezone_basis`, `rendered_at`, `rendered_by`.

Snapshots are append-only. A notification has exactly one effective snapshot (the highest sequence).
Rendering fails closed with `template_variable_mismatch` when the required variable set and the
allowlisted set disagree. Reads expose `params_digest` and `variable_codes` only; the envelope is
decryptable solely on the dispatch/delivery path.

### 6.5 Notification aggregate

`notifications` is the S-owned business aggregate. States:

`pending → scheduled → queued → dispatching → dispatched → delivered → closed`

terminal branches: `pending|scheduled|queued|dispatching → suppressed | cancelled | expired | failed`.

- `pending` — intent observed; workflow resolution or eligibility still outstanding. `observed_at`
  (the §6.3(b) input) and the resolved `timezone` are written once, in the observation transaction that
  creates the row. No instant is derived yet, so the row carries `scheduled_for IS NULL` and
  `expires_at IS NULL`; a terminal closure reached directly from observation (an unavailable tier-F
  instant, §6.2.4(d); an unresolvable timezone basis, §6.3) is written in the same shape and never
  derives one.
- `scheduled` — instant derived by the §6.3 algorithm, with `schedule_anchor_at`, `scheduled_for` and the
  mandatory non-null `expires_at` recorded in that transaction, `deferral_count` at its column default
  `0`, and the outbox row enriched with the same derived representation (`scheduled_for`, `expires_at`,
  `deferral_count`, and an `available_at` that starts equal to the mirrored `scheduled_for`).
- `queued` — eligible and inside its window; waiting for a lease.
- `dispatching` — an attempt holds the lease.
- `dispatched` — hand-off to the transport port returned a hand-off acknowledgement.
- `delivered` — a verified delivery fact reports delivery.
- `suppressed` / `cancelled` / `expired` / `failed` — terminal; never silently reopened.

State may never regress (no `delivered → dispatched`), and no business fact is written by any
transition. The outbox row keeps the durable lease/dispatch facts; the aggregate keeps the business
notification facts. They are 1:1 in storage, enforced on **both** sides: `notifications.outbox_id`
(`UNIQUE KEY outbox_id`) and `platform_outbox.notification_id` (`UNIQUE KEY notification_id`, §7.1).
The outbox-side key is what actually forbids a second outbox row from referencing one notification and
splitting its lease/delivery state across rows; MySQL permits unlimited NULLs in a unique index, so every
legacy row keeps `notification_id = NULL` and coexists.

`scheduled_for` and `expires_at` are non-null **together** on every S-owned notification from `scheduled`
onward, and NULL **together** on a row that has not reached it: `pending`, and a terminal row closed
directly from observation before any instant was derived (an unavailable tier-F instant, §6.2.4(d); an
unresolvable timezone basis, §6.3). There is no state in which one is present and the other absent — the
`scheduled` transition writes both in one transaction and no later transition writes either alone — and
the verifier keys its non-null rule on exactly that bi-conditional (§7.3). The single NULL rule of §6.3
applies to rows S never owns, and to S-owned rows that have not derived an instant, leaving no comparison
undefined in any state.

`observed_at`, `schedule_anchor_at` and `expires_at` are each written once and never rewritten. A deferral
is the sole exception to immutability, and it moves exactly two persisted values — `scheduled_for` (to the
step-5 formula of §6.3 applied to the new count) and `deferral_count` — together with their outbox mirror
(§6.3(e)); it never moves the anchor, the base instant, `expires_at` or the coalesce bucket, so the
§6.3(f) re-derivation is always checked against immutable inputs, one monotone counter and a stable
result.

### 6.6 Attempt lifecycle

`notification_attempts` has one row per lease acquisition, `attempt_sequence` starting at 1:

`leased → handed_off → acknowledged | failed | expired | abandoned`

- `leased` — lease token held; `lease_expires_at` bounded by the retry policy.
- `handed_off` — the transport port accepted an already-authorised, idempotent send command.
- `acknowledged` — the port returned a hand-off acknowledgement.
- `failed` — terminal for the attempt; the failure class decides retry eligibility.
- `expired` — the lease elapsed without acknowledgement; the attempt is closed, and because the lease
  write and the attempt insert are one transaction the closure always has a persisted attempt row to
  account against. Recovery treats it as a retryable closure of the same bounded retry path (§9): it
  persists the deterministic schedule derived from the persisted `lease_expires_at` and re-arms the
  outbox row only while an attempt remains **and** the derived clamp leaves a usable window; with an
  attempt remaining, a clamp that leaves none is exhaustion by window, so it derives no schedule,
  persists no quadruple, appends no `retry_scheduled` event, re-arms nothing and closes the notification
  as `expired`/`retry_window_exhausted`, while a closure at `attempt_sequence = retry_max_attempts` is the
  ceiling shape below whatever its clamp does — the ceiling gate is always read first (§9).
- `abandoned` — an operator or integrity check released a stuck lease; the release is bounded like every
  other closure (it re-arms only while an attempt remains **and** the clamp leaves a usable window, while
  the final attempt and, below the ceiling, a clamp that leaves no usable window are both exhaustion).
  The release is a **non-terminal** closure — `failure_class = retryable`, never `terminal` — so it can
  never borrow a terminal-class reason code. Its exhaustion follows the same ceiling-first two-gate rule
  as every other path: at `attempt_sequence = retry_max_attempts` it closes the notification
  `failed`/`retry_exhausted` whether or not the clamp would also have failed, and, only while an attempt
  remains, a release whose clamp leaves no usable window closes it `expired`/`retry_window_exhausted`
  exactly like the retry, defer and lease-expiry paths (§9).

`acknowledged` is the one closure that carries **no** `failure_class` at all: the port accepted the
hand-off, so there is no failure to classify and no retry schedule to derive. The attempt closes
`acknowledged` with the acknowledgement member as its `outcome_code` (one shared normalised member, so the
state and the code carry the same value), persists none of the four retry columns, appends no
`retry_scheduled` row on either history, and the notification moves to `dispatched`; the member is unique to
that shape, so no `retryable`, `defer`, `terminal`, abort or cancellation closure may borrow it. A closed
attempt that carries no class in any other shape — a forged `failed`, `expired` or `abandoned` row, whatever
legal event chain it presents — is malformed and is refused whole with `attempt_lifecycle_invalid` rather
than read as an acknowledgement or left unjudged by the closure partition (§7.3, §9).

Exactly one attempt row may be open per notification; `UNIQUE KEY attempt_lease(notification_id, attempt_sequence)`
plus `UNIQUE KEY lease_token_digest(lease_token_digest)` make a duplicate lease impossible. The
existing `platform_outbox.attempt_count` remains the durable lease counter (S adds no competing
counter) and is incremented exactly once per acquisition. Because the claim writes the lease and the
`dispatching` transition in one transaction, and every closure closes the attempt and the notification
together, the verifier reads that invariant in **both** directions: a `dispatching` notification whose
history holds no live lease, and any notification holding two open attempts, are refused whole
(`attempt_lifecycle_invalid`, §7.3).

Closing an attempt with a non-terminal class (`retryable`, or the attempt-level `defer`) **while an
attempt remains** (`attempt_sequence < retry_max_attempts`, §9) **and the §9 clamp leaves a usable
window** (a clamp that leaves none is exhaustion by window and persists nothing, §9) persists that
attempt's deterministic retry schedule — `applied_jitter_bp`, `base_backoff_seconds`, `backoff_seconds`
and `next_available_at` (§9) — on the same attempt row, in the same transaction that appends the
`retry_scheduled` history event, before the outbox row is re-armed. Those four columns are written exactly
once, on that transition, and never rewritten. The schedule is derived under the §9 expiry rule: a
non-null `expires_at` (always true for S-owned work that has reached scheduling, §6.3 — an attempt row
exists only past `scheduled`) clamps the instant and enables the expiry-window check, while a NULL
`expires_at` omits the expiry clamp term and every expiry-window check, so a closure can never be decided
by an undefined comparison.

A closure at the final permitted attempt (`attempt_sequence = retry_max_attempts`) of a **non-terminal**
class — `retryable`, the attempt-level `defer`, a lease expiry or an operator `abandoned` release — is
**exhaustion**: nothing is re-armed, none of the four retry columns is written, the outbox row is closed
in place (`status = 'failed'`, lease fields cleared, `available_at` left so the row is never reselected by
the claim index) and the notification closes terminally as `failed` with
`failure_reason_code = retry_exhausted`. This ceiling shape is decided by the attempt sequence alone and
is evaluated **before** the window gate, so an attempt closing at the ceiling takes this shape even when
its clamp would also have left no usable window; the window shape below belongs only to a closure with an
attempt remaining (§9). Exhaustion rewrites the **notification's** reason code only: the
closing attempt keeps its non-terminal `failure_class` (`retryable`, or `defer`'s class) and its own
non-terminal closure code on `outcome_code`, never a member of the closed terminal-reason vocabulary and
never `retry_exhausted`, so a lease expiry exhausted at
the ceiling stays an `expired`/`lease_expired`/`retryable` attempt and is never reclassified as
`terminal` (§9); `retry_exhausted` is written on the notification alone and is never an attempt outcome
code. A `terminal` class never borrows that code: it closes the
notification as `failed` with **its own declared terminal reason code** — a member of the closed
terminal-reason vocabulary declared below — wherever it occurs: at any
attempt sequence, the ceiling included. The terminal cause is therefore preserved, the outcome is
deterministic from the failure class alone, and the code is a declared vocabulary member rather than free
text. Both shapes are otherwise identical (no re-arm, no persisted
schedule, terminal `failed` notification) and differ in the closing attempt's class and in the reason
code: the terminal class carries its declared vocabulary member on the attempt and the notification,
while the exhausted non-terminal class carries its own non-terminal closure code on the attempt and
`retry_exhausted` on the notification alone. Because the ceiling rule is
the same for every non-terminal path, exhaustion reached by a lease expiry at the final permitted attempt
is identical to exhaustion reached by a port-reported retry, an attempt-level defer or an operator
`abandoned` release, and attempt `retry_max_attempts + 1` is unrepresentable on every path.

**Terminal-reason vocabulary (closed, normalised, channel-neutral).** A `terminal`-class closure is the
only attempt outcome that closes the notification as `failed` with a cause drawn from the
terminal-vocabulary below (a non-terminal **ceiling** exhaustion closes it as `failed` too, but with the
ceiling code `retry_exhausted`, while the below-ceiling window exhaustion closes it `expired` with
`retry_window_exhausted`, §9), so that cause is a closed vocabulary rather than an implementation-chosen
string. The transport port reports a permanent send
failure as exactly one member of the normalised vocabulary below — never a provider status string, a
provider identifier, a channel-specific error object or a raw payload — and
`NotificationDispatchService::record_outcome` is the single authoritative normaliser: it maps the reported
terminal cause onto the vocabulary, and the same transaction writes the member as the attempt's
`outcome_code` (with `failure_class = 'terminal'`), appends the attempt's `failed` event, writes **the
same** member to the notification's `failure_reason_code`, appends the notification's `failed` event and
closes the outbox row:

| Terminal reason code | Meaning (normalised, channel-neutral) |
| --- | --- |
| `contact_unusable` | the recipient's contact is permanently unusable on this channel (invalid, withdrawn or revoked) |
| `send_refused` | the transport permanently refused the send for this recipient (policy, content or reputation) with no retry that could change the outcome |
| `no_route` | the transport has no permitted route to this recipient on this channel |

Exactly those three codes may be the reason of a `terminal`-class closure, and a `terminal` class closes
the notification as terminal `failed` — never any other terminal branch, the window's `expired` included
— so the notification's **status** is part of the terminal invariant rather than a precondition the rule
may assume. A missing, empty, non-member or provider-specific reason, a `terminal` class that borrows a
ceiling code (`retry_exhausted`) or a window code (`retry_window_exhausted`), a `terminal`-class closure
whose attempt `outcome_code` and notification `failure_reason_code` are not **equal**, or a `terminal`
class persisted beside a notification that is not terminal `failed` (a `terminal` attempt carrying a
well-formed, matching member on both records but attached to an `expired`, `suppressed` or `cancelled`
notification — the forged terminal-class window closure) is malformed: `record_outcome` refuses the
closure whole (no attempt close, no notification transition, no history append, no outbox mutation) with
`terminal_reason_invalid`, and the verifier rejects a persisted notification **whose closing attempt
carries `failure_class = 'terminal'`** when its state is not terminal `failed`, when its
`failure_reason_code` is NULL, is not one of the three members, or is not equal to that attempt's
`outcome_code` (§7.3). The rule is scoped by the closing attempt's class because `failed` is the shape of
**two** legal closures that must not be confused: a
non-terminal exhaustion (`retryable`, `defer`, lease-expiry or `abandoned` release at
`attempt_sequence = retry_max_attempts`) also closes the notification as `failed`, and its reason code is
validated separately as exactly `retry_exhausted` and never against the three-member terminal vocabulary,
because the closing attempt is not `terminal`-class, its `outcome_code` stays that path's non-terminal
closure code and is never a member of that vocabulary, and its class is never rewritten to `terminal`. A
non-terminal-exhaustion violation is refused and rejected as `retry_exhaustion_invalid`, its own
diagnostic, and never as `terminal_reason_invalid`, whose scope stays `terminal`-class closures; the
below-ceiling window exhaustion that closes the notification `expired`/`retry_window_exhausted` is
measured by its own sibling rule (`retry_window_exhaustion_invalid`, §7.3/§14), so the terminal
vocabulary, the ceiling rule and the window rule partition every closed notification by the closing
attempt's class and the gate that selected its shape (§7.3) — and each rule also fixes the notification's
status: the terminal rule requires terminal `failed`, the ceiling rule requires `failed`, and the window
rule requires `expired` **with a non-terminal closing attempt**, so a `terminal`-class attempt beside an
`expired` notification falls to the terminal rule and is refused and rejected with
`terminal_reason_invalid` instead of being read as window exhaustion.
Both append-only histories (`dzn_notification_attempt_events` and
`dzn_notification_events`) carry the same normalised member as `reason_code`, so the persisted record
proves the code without retaining the port's raw reason, which is never stored. The vocabulary is disjoint
from the non-terminal closure codes (`retryable`/`deferred`/`lease_expired` and the ceiling codes above),
so a `terminal` closure can never be mistaken for a ceiling exhaustion, and the two `failed` shapes stay
distinguishable by the closing attempt's `failure_class` and by the reason code alone (§7.3, §9).

### 6.7 Delivery lifecycle

`notification_deliveries` is append-only and stores normalised provider facts as digests only:

`accepted → sent → delivered` (terminal) with side facts `undelivered | failed | expired`.

Each row carries `delivery_rank tinyint unsigned` (a fixed monotonic order over the vocabulary),
`provider_fact_digest`, `provider_event_reference_digest`, `occurred_at`, `recorded_at` and
`applied tinyint unsigned`. A fact that would regress state or arrive out of order is **retained with
`applied = 0`** and raises the `delivery_regression_attempt` / `delivery_event_stale` diagnostic; it
never rewrites the aggregate (SECURITY.md §7). Delivery rows may only be written through the delivery
intake port.

### 6.8 Suppression

`notification_suppressions` is a channel-neutral, purpose-scoped exclusion:
`subject_kind`, `subject_digest`, `purpose`, `reason_code`, `effective_from`, `expires_at`,
`state active | released`, plus append-only events and digest-only commands. Suppression is evaluated
at enqueue **and** immediately before hand-off; a suppression that appears while a notification sits
`queued` moves it to `suppressed` without ever having been sent.

### 6.9 Diagnostics

Diagnostics are a derived, capability-protected read model (§14) — S adds **no** diagnostics table and
writes nothing to another module's exception storage. A structural integrity failure surfaces through
the existing Core exception boundary only where that boundary is already allowed
(MODULE-BOUNDARIES.md §11), never as a silent repair.

## 7. Schema 032 data model and migration

Additive only. No backfill, no inferred notification, no external call, no provider-specific column,
no foreign key or CHECK constraint (the repository relies on application/verifier enforcement), and no
`INSERT`/`UPDATE` statement anywhere in the migration.

### 7.1 The `platform_outbox` extension

Migration 032 re-declares the canonical `platform_outbox` definition as a superset and lets `dbDelta`
apply the additive column and index differences. Every added column is nullable with no default, so
existing rows keep NULL and no implicit row rewrite occurs. Columns:

`notification_id bigint unsigned NULL`, `workflow_key varchar(64) NULL`,
`workflow_version int unsigned NULL`, `intent_key varchar(64) NULL`, `audience varchar(16) NULL`,
`scheduled_for datetime NULL`, `expires_at datetime NULL`, `deferral_count smallint unsigned NULL`,
`priority tinyint unsigned NULL`, `lease_token_digest char(64) NULL`, `failure_reason_code varchar(64) NULL`.

Added indexes: `UNIQUE KEY notification_id(notification_id)`,
`KEY dispatch(status,scheduled_for,available_at)`, `KEY intent_version(intent_key,workflow_version)`.

`deferral_count` mirrors the notification's persisted count so the outbox row carries the complete
dispatch representation §6.3(f) requires: without it, the row's `scheduled_for` could not be checked
against the frozen base and the persisted count, and the §7.3 mirror check could not run on the row S's
dispatcher claims on. It is nullable with no default like every other added column, so every legacy and R2
row keeps it NULL.

`scheduled_for`, `expires_at` and `deferral_count` stay NULL on every Phase-1 and R2 row, and stay NULL on
an S-owned row until its notification reaches `scheduled`; §6.3 requires all three to be non-null on every
row S enriches **for a notification that has derived an instant**, so the NULL handling of §6.3/§9 is
exercised by rows S does not own and by S-owned rows that have not derived one, and never by S-owned
dispatch work. `deferral_count` is `0` from the `scheduled` transition and equals the notification's
persisted value on every S-owned row thereafter (§6.3(e)). The `available_at` column is the exception the
mirror inherits from the shared seam — it is the **claim instant**, not a second copy of the schedule:
Phase-1/R2 writers populate it, an S-owned pre-scheduling row leaves it untouched and unclaimed, the
`scheduled` transition overwrites it with the derived `scheduled_for` (so it equals the mirrored
`scheduled_for` at that point), and a §9 retry then moves **it alone** to the persisted `next_available_at`
of the closing attempt while the mirrored `scheduled_for` keeps the aggregate's schedule-derived value
(§6.3(c)(8), §9). Between those writes `available_at` is therefore exactly one of two values — the
mirrored `scheduled_for`, or the `next_available_at` of the attempt row that last re-armed the row — and
the mirror check of the next paragraph accepts both while rejecting a retry-moved `scheduled_for`.

The notification/outbox 1:1 invariant is enforced on **both** sides, and the outbox-side unique key is
required rather than optional: `notifications.outbox_id` (unique) cannot on its own stop a second outbox
row from referencing the same `notification_id` and splitting one notification's lease and delivery
state across two rows. Because every added column is nullable with no default, `dbDelta` adds the unique
key to the shared legacy table without touching existing rows: MySQL permits unlimited NULLs in a unique
index, so every Phase-1 invitation row and every R2 `publish()` row keeps `notification_id = NULL` while
at most one outbox row can ever claim a given `notification_id`. The verifier asserts the key exists, is
unique and is named `notification_id`, and the migration and compatibility suites prove a duplicate
insert is rejected while legacy NULL inserts continue to succeed (§15). The same verifier asserts that
every added column of the list above — including the nullable `deferral_count` — exists with its declared
nullability, that it is not renamed or dropped by a later migration, that an S-owned row enriched for a
`scheduled` notification mirrors the notification's §6.3-derived `scheduled_for`/`expires_at`/
`deferral_count` exactly and carries an `available_at` that is either that same schedule-derived
`scheduled_for` or the persisted `next_available_at` of the attempt row that last re-armed it, and that a
pre-scheduling S-owned row keeps the derived triple NULL. The mirror is checked **row-to-row against the aggregate's locked
derivation**, not by re-deriving a schedule from the outbox row alone: the outbox row is a mirror and
dispatch index and deliberately carries none of the derivation's frozen inputs (`observed_at`, the
resolved `timezone`, `schedule_anchor_at`, the frozen tier-F subject instant), so the outbox schema is not
extended with them and no path may present the row as a standalone derivation root (§6.3(c)(8), §7.3).

Preserved exactly and asserted by the S verifier: `UNIQUE KEY idempotency_key(idempotency_key)`,
`KEY available(status,available_at)`, `KEY invitation_generation(invitation_id,generation_id)`,
`status varchar(16)`, `attempt_count smallint unsigned`, the absence of `updated_at`, and the absence
of any provider column. The added `UNIQUE KEY notification_id(notification_id)` is asserted present,
unique and correctly named.

### 7.2 S-owned tables (eighteen)

Migration 032 creates exactly these tables, each InnoDB with the repository charset/collation:

`dzn_notification_workflows` (`id`, `uid char(26)`, `reference_code`, `workflow_key varchar(64)`,
`purpose varchar(48)`, `state varchar(16)`, `active_version_id bigint unsigned NULL`,
`workflow_version int unsigned`, audit columns, `UNIQUE KEY workflow_key(workflow_key)`)

`dzn_notification_workflow_versions` (`id`, `uid`, `workflow_id`, `version_number int unsigned`,
`intent_key varchar(64)`, `audience varchar(16)`, `recipient_kind varchar(16)`, `template_id`,
`locale varchar(16)`, `definition_fingerprint char(64)`, `rule_set_digest char(64) NULL`,
`rule_frozen_at datetime NULL`, `state varchar(16)`,
`active_slot tinyint unsigned NULL`, `intent_active_slot tinyint unsigned NULL`,
`supersedes_version_id bigint unsigned NULL`,
`effective_from datetime`, `retired_at datetime NULL`, `retired_by bigint unsigned NULL`,
audit columns, `UNIQUE KEY workflow_version(workflow_id,version_number)`,
`UNIQUE KEY workflow_active(workflow_id,active_slot)`,
`UNIQUE KEY intent_active(intent_key,intent_active_slot)`, `KEY intent_state(intent_key,state)`)

Routing is storage-enforced, not conventional: `intent_active_slot` is `1` on the single active version
of an `intent_key` and NULL on every `draft`/`superseded`/`retired` row, so
`UNIQUE KEY intent_active(intent_key,intent_active_slot)` makes a second active version for the same
consumed intent impossible even when it belongs to a different workflow.

`dzn_notification_workflow_rules` (append-only and draft-only; `uid`, `workflow_version_id`,
`rule_kind varchar(16)`, `rule_code varchar(48)`, `ordinal tinyint unsigned`,
`parameter_a varchar(64) NULL`, `parameter_b varchar(64) NULL`, `parameter_c int NULL`,
`parameter_d int NULL`, `recorded_at`, `recorded_by`, `created_at`, `created_by`,
`UNIQUE KEY version_rule(workflow_version_id,rule_kind,rule_code,ordinal)`; no `updated_at`)

`ordinal` is not an ordering hint: for `rule_kind = 'schedule'` it is the canonical **parameter
position** of the §6.3 encoding table — one row per parameter, value in `parameter_a`, the other three
parameter columns NULL — so the unique key above doubles as the composition's parameter arbiter and a
duplicated, extra or missing parameter row is a malformed rule. `rule_kind = 'retry'` uses the **same
canonical parameter encoding** (§9): the retry rule-set is one code (`retry`) with five contiguous ordinal
rows (`1…5`), value in `parameter_a`, the other three parameter columns NULL, so the same unique key
arbitrates it and a duplicated, extra, missing or non-contiguous parameter row is a malformed retry policy
(`retry_policy_invalid`, refused at activation). Eligibility rules keep their own declared slot usage
(§6.2).

Rule rows may only be written while their version is `draft` and `rule_frozen_at IS NULL`; the
repository's guarded insert refuses any other version with `workflow_rules_frozen`, and activation
freezes the set by writing `rule_set_digest`/`rule_frozen_at` on the version row in the same transaction
(§6.2). Appends stay possible in raw SQL, which is exactly why the frozen digest is revalidated on every
read, enqueue and dispatch path: an appended rule makes the recomputed digest disagree with
`rule_set_digest`, fails closed with `workflow_rule_set_mutated`, and blocks hand-off instead of silently
widening eligibility, scheduling or retry behaviour.

The freeze deliberately uses no database trigger (S adds no trigger, foreign key or CHECK constraint, per
the repository convention in §7): the guarded insert plus the frozen digest revalidation is the
enforcement pair, and the verifier rejects an activated version whose stored rule rows disagree with its
`rule_set_digest`. A post-activation insert therefore cannot take effect under any path — it either hits
the guard or leaves the version failing integrity and un-dispatched.

`dzn_notification_workflow_commands` (digest-only, immutable; `uid`, `command_domain`, `operation`,
`command_key_digest char(64)`, `command_payload_digest char(64)`, `workflow_id`,
`workflow_version_id`, `result_state`, `result_id`, `created_at`, `created_by`,
`UNIQUE KEY command_key_digest(command_key_digest)`)

`dzn_notification_templates` (`id`, `uid`, `reference_code`, `template_key varchar(64)`,
`purpose varchar(48)`, `locale varchar(16)`, `state varchar(16)`, `current_version int unsigned`,
`template_version int unsigned`, audit columns, `UNIQUE KEY template_key(template_key)`)

`dzn_notification_template_versions` (immutable; `id`, `uid`, `template_id`, `version_number`,
`locale`, `subject_template_digest char(64)`, `body_template_digest char(64)`,
`variable_contract_digest char(64)`, `required_variable_count tinyint unsigned`,
`definition_fingerprint char(64)`, `state varchar(16)`, `effective_from datetime`,
`supersedes_version_id`, `created_at`, `created_by`,
`UNIQUE KEY template_version(template_id,version_number)`; **no provider template column**)

`dzn_notification_template_commands` (digest-only, immutable, same shape as the workflow commands)

`dzn_notification_rendered_snapshots` (immutable; `id`, `uid`, `notification_id`, `sequence`,
`template_version_id`, `supersedes_snapshot_id NULL`, `params_digest char(64)`,
`variable_codes varchar(191)`, `variable_count tinyint unsigned`,
`rendered_params_envelope mediumblob NULL`, `cipher_version varchar(16) NULL`,
`locale`, `timezone_basis varchar(24)`, `rendered_at`, `rendered_by`, `created_at`,
`UNIQUE KEY notification_sequence(notification_id,sequence)`; no `updated_at`)

`dzn_notifications` (`id`, `uid`, `reference_code`,
`notification_key_digest char(64)`, `workflow_id`, `workflow_version_id`, `intent_key varchar(64)`,
`audience varchar(16)`, `recipient_kind varchar(16)`, `recipient_digest char(64)`,
`recipient_contact_digest char(64)`, `recipient_contact_envelope mediumblob NULL`,
`contact_cipher_version varchar(16) NULL`, `contact_expires_at datetime NULL`,
`locale varchar(16)`, `timezone varchar(64)`, `observed_at datetime NULL`,
`schedule_anchor_at datetime NULL`, `subject_aggregate varchar(32)`,
`subject_aggregate_id bigint unsigned`, `subject_reference_digest char(64)`,
`template_version_id`, `rendered_snapshot_id bigint unsigned NULL`,
`notification_key_scope_digest char(64)`, `outbox_id bigint unsigned NULL`, `state varchar(24)`,
`scheduled_for datetime NULL` and `expires_at datetime NULL` (non-null **together** from `scheduled`
onward and NULL **together** before it, §6.3/§6.5: they are absent only on a row that has not derived an
instant), `priority tinyint unsigned NULL`,
`deferral_count smallint unsigned NOT NULL DEFAULT 0`, `suppression_id bigint unsigned NULL`,
`failure_reason_code varchar(64) NULL`, `delivered_at datetime NULL`,
`notification_version int unsigned`, audit columns,
`UNIQUE KEY notification_key_digest(notification_key_digest)`,
`UNIQUE KEY outbox_id(outbox_id)`, `KEY state_scheduled(state,scheduled_for)`,
`KEY subject(subject_aggregate,subject_aggregate_id)`, `KEY recipient(recipient_digest)`)

`observed_at` is the notification's own persisted observation instant — the tier-P anchor and the upper
bound of the §6.2.3 B2 match — written once in the observation transaction, never rewritten and never
replaced by a fresh clock read. `schedule_anchor_at` is §6.3 step 1's derived anchor, written with
`scheduled_for`/`expires_at` at the `scheduled` transition so the frozen derivation can be replayed
step by step from persisted values (`schedule_derivation_divergence` otherwise, §6.3(f)). `deferral_count`
is the §6.3(b) input: `0` at creation, incremented exactly once per accepted deferral (§6.3(e)), and
`scheduled_for` is always the step-5 formula over it — never a shift of its own previous value. `timezone`
holds the one resolved zone of the version's single shared `timezone_basis` (§6.3), written once at
observation — resolved per notification against the concrete instance, never at activation — empty when
the version registers no timezone-sensitive rule, and never re-resolved afterwards; a basis that does not
resolve closes the observation terminally with `schedule_timezone_unresolved` rather than persisting an
underivable zone.

`dzn_notification_events` (append-only; `uid`, `notification_id`, `event_sequence`, `event_type`,
`from_state`, `to_state`, `reason_code`, `evidence_channel`, `evidence_reference_digest char(64)`,
`evidence_at`, `occurred_at`, `recorded_at`, `recorded_by`, `created_at`, `created_by`,
`UNIQUE KEY notification_sequence(notification_id,event_sequence)`; no `updated_at`)

`dzn_notification_commands` (digest-only, immutable; `uid`, `command_domain`, `operation`,
`command_key_digest`, `command_payload_digest`, `notification_id`, `result_state`, `result_id`,
`created_at`, `created_by`, `UNIQUE KEY command_key_digest(command_key_digest)`)

`dzn_notification_attempts` (`id`, `uid`, `notification_id`, `outbox_id`, `attempt_sequence`,
`lease_token_digest char(64)`, `state varchar(16)`, `leased_at`, `lease_expires_at`,
`finished_at datetime NULL`, `outcome_code varchar(64) NULL`, `failure_class varchar(24) NULL`,
`duration_ms int unsigned NULL`, `applied_jitter_bp smallint unsigned NULL`,
`base_backoff_seconds int unsigned NULL`, `backoff_seconds int unsigned NULL`,
`next_available_at datetime NULL`, `attempt_version int unsigned`, audit columns,
`UNIQUE KEY attempt_lease(notification_id,attempt_sequence)`,
`UNIQUE KEY lease_token_digest(lease_token_digest)`, `KEY state_expires(state,lease_expires_at)`)

The four retry columns are the persisted, auditable result of the deterministic retry derivation (§9):
`applied_jitter_bp` is the realised jitter in basis points (distinct from the declared span parameter
`retry_jitter_bp` on the version's retry rule), and `base_backoff_seconds`/`backoff_seconds` are the
un-jittered and jittered back-offs. All four are NULL on an attempt that never closed a non-terminal
class, written exactly once when the attempt closes `retryable` — or, through the same bounded path,
`defer`, or a lease-expiry recovery applying that identical rule (§9) — with a further attempt available
**and** a §9 clamp that leaves a usable window, and never
rewritten afterwards; they stay NULL on an **exhausted** closure — at the attempt ceiling
(`attempt_sequence = retry_max_attempts`, the gate read first) and, with an attempt remaining, through a
clamp that leaves no usable window (§9) — and on a terminal closure and every closure that did not
derive a schedule. They are the recovery input, so
replay and lease-expiry recovery read the persisted quadruple
`(applied_jitter_bp, base_backoff_seconds, backoff_seconds, next_available_at)` instead of re-inventing a
schedule. A closure reached while `expires_at IS NULL` persists the same quadruple derived under the §9
NULL rule (no expiry clamp term), so replay and lease-expiry recovery reproduce it identically whether or
not a window exists.

`outcome_code` and `failure_class` are the normalised closure outcome, and they are the only persistence
path for a terminal cause: on a `terminal`-class closure `outcome_code` holds exactly one member of the
closed §6.6 terminal-reason vocabulary and is **equal** to the notification's `failure_reason_code`
(§6.6), so the attempt row and the notification row carry the same declared code and the verifier can
prove it. On a non-terminal closure the same pair is that path's non-terminal outcome — the closure code
of the retry, defer, lease-expiry or operator-release path — and `failure_class` stays non-terminal even
at `attempt_sequence = retry_max_attempts`: the attempt is **not** reclassified as `terminal` and its
`outcome_code` is never a member of the closed terminal-reason vocabulary, while the notification alone
carries the ceiling code `retry_exhausted` when it closed at the ceiling or, for a below-ceiling closure
whose clamp left no usable window, `retry_window_exhausted` (§6.6, §9). The port's raw reason is never
stored here or anywhere else (only keyed digests are), and a closure whose `outcome_code`/`failure_class`
pair is missing, inconsistent or outside the vocabulary is refused whole with `terminal_reason_invalid` when its
class is `terminal`, with `retry_exhaustion_invalid` for the ceiling shape when it is not, and with
`retry_window_exhaustion_invalid` for the below-ceiling window shape (§6.6, §7.3). Because a `terminal`
class only ever closes the notification as terminal `failed`, the status is part of that same rule: a
`terminal`-class closure persisted beside any other notification state (`expired`, `suppressed`,
`cancelled`) is refused and rejected on `terminal_reason_invalid`, never read as the window shape whose
`expired`/`retry_window_exhausted` closure is defined only for a non-terminal closing attempt (§6.6,
§7.3).

`dzn_notification_attempt_events` (append-only; `uid`, `attempt_id`, `event_sequence`, `event_type`,
`from_state`, `to_state`, `reason_code`, `occurred_at`, `recorded_at`, `recorded_by`, `created_at`,
`created_by`, `UNIQUE KEY attempt_sequence(attempt_id,event_sequence)`; no `updated_at`)

`dzn_notification_deliveries` (append-only; `uid`, `notification_id`, `attempt_id`,
`delivery_sequence`, `delivery_state varchar(16)`, `delivery_rank tinyint unsigned`,
`provider_fact_digest char(64)`, `provider_event_reference_digest char(64)`,
`occurred_at`, `recorded_at`, `applied tinyint unsigned`,
`UNIQUE KEY notification_delivery(notification_id,delivery_sequence)`,
`UNIQUE KEY provider_reference(provider_event_reference_digest)`; no `updated_at`, no raw payload)

`dzn_notification_suppressions` (`id`, `uid`, `reference_code`, `subject_kind varchar(16)`,
`subject_digest char(64)`, `purpose varchar(48)`, `reason_code varchar(64)`,
`state varchar(16)`, `effective_from datetime`, `expires_at datetime NULL`,
`released_at datetime NULL`, `released_by bigint unsigned NULL`,
`suppression_version int unsigned`, audit columns,
`KEY subject_state(subject_digest,state)`, `KEY purpose_state(purpose,state)`)

`dzn_notification_suppression_events` (append-only; same shape as `notification_events` bound to
`suppression_id`)

`dzn_notification_suppression_commands` (digest-only, immutable, bound to `suppression_id`)

`dzn_notification_privacy_tombstones` (immutable digest-only erasure evidence; `uid`,
`notification_id`, `subject_reference_digest char(64)`, `erased_scope varchar(24)`,
`erased_at`, `erased_by`, `reason_code`, `integrity_digest char(64)`,
`UNIQUE KEY notification_id(notification_id)`; no `updated_at`)

### 7.3 Migration rules

The following rules describe the historical Schema-032 S slice. They do not identify the current package,
which is Schema 33 with the separate readiness migration described in the current-source addendum.

- `032_notification_communications_authority` runs `install_notification_communications_authority()`.
- `verify_notification_communications_schema()` runs after migration 032, on current-schema
  verification, and unconditionally before the schema option advances to 32 (including the
  retained-032 / stale-version path), matching the R1/R2 wiring.
- `032_notification_communications_authority` is added to the `verify_current_schema()` required list,
  and `DZN_PLATFORM_SCHEMA_VERSION` becomes `'32'` with build
  `phase2a2s-notification-communications-authority-20260924.1`.
- The new capabilities are ensured through the existing `ensure_capabilities()` path, and the
  fresh-install capability bootstrap ordering test must stay green.
- The verifier rejects: a missing or renamed pre-existing `platform_outbox` column/index, a missing,
  non-unique or renamed added `notification_id` key, a provider-specific column, a raw-payload column,
  an `updated_at`/raw-key/reference column on any append-only or immutable table, a malformed
  `char(64)` digest, a non-InnoDB table, and any Term/Lesson/schedule/attendance/payment table smuggled
  into the phase.
- The verifier also rejects routing/freeze state that disagrees with a version's `state`: an `active`
  version without `active_slot = 1` and `intent_active_slot = 1`, with `rule_set_digest` or
  `rule_frozen_at` NULL, or whose recomputed rule digest differs from the stored `rule_set_digest`; and a
  second `active` version claiming an `intent_key` that is already routed.
- The verifier also rejects an `active` version whose frozen eligibility rules do not satisfy §6.2.1 —
  a mandatory baseline or tier code missing or duplicated, a mandated rule bound to another subject
  aggregate, an empty or out-of-vocabulary `subject_state_is` allowlist, a positive `lead_time_at_least`
  missing on a tier-F intent, or an `audience`/`recipient_kind` pair outside the authorised matrix.
- The verifier also rejects a `subject_state_is` binding that is not the §6.2.2 row for the version's
  intent: an allowlist that widens, narrows or departs from that row's `to_state` set, a `parameter_a`
  naming another aggregate, a bound event type that the owning module's locked transition table does not
  allow to record the claimed transition, a bound event type that is not the row's single authoritative
  event type (binding `AUTOMATIC_RENEWAL_UPCOMING` to `payment_required` rather than `opened`, or any
  other second-site binding), an eligibility decision recorded without the §6.2.3 bound-evidence digest
  or with a digest that does not reproduce from the subject history, and any registration or activation
  attempted for the reserved-unbound `GUARANTEE_EXPIRED` intent (refused with `intent_unbound`).
- The verifier also rejects a **persisted-retry-schedule** violation on a closure that re-arms: an attempt
  row that closed `retryable` — or, through the same bounded path, an attempt-level `defer` closure —
  **while an attempt remained and the §9 clamp left a usable window** (`attempt_sequence <
  retry_max_attempts`, the clamped instant strictly later than that closure's instant, and where
  `expires_at` is non-null strictly earlier than `expires_at`) without the persisted
  `applied_jitter_bp`/`base_backoff_seconds`/`backoff_seconds`/`next_available_at` values, or whose
  persisted values disagree with the deterministic recomputation of §9. The requirement is scoped to the
  closures that actually re-arm because the two **exhaustion** shapes persist no schedule by contract
  (§7.2): a non-terminal closure at the final permitted attempt (`attempt_sequence = retry_max_attempts`,
  §6.6) and a closure whose clamp leaves no usable window (§9) each derive nothing, write none of the four
  columns and re-arm nothing, and the verifier **accepts** them in exactly that NULL shape — all four
  columns NULL on the closing attempt, the closing attempt keeping its non-terminal `failure_class` and its
  own non-terminal closure code, and the notification closed as `failed`/`retry_exhausted` at the ceiling
  (the gate read first, §9) or, with an attempt remaining, as `expired`/`retry_window_exhausted` by the
  window (§6.6, §9) — while the same exhausted closure that carries a non-NULL retry schedule is
  rejected as a schedule that must never have been derived. The rule is read in **both** directions against
  the derivation rather than against the persisted values alone: a closure of that bounded path whose §9
  derivation re-arms must carry the quadruple that reproduces it exactly, and a closure whose derivation
  derives nothing — the two exhaustion shapes, and every closure that derived no schedule at all — must
  carry none, so a closure that should have re-armed and persisted nothing is `retry_schedule_divergence`
  rather than accepted as window exhaustion on the strength of the notification's own reason code.
- The verifier also rejects an **attempt-lifecycle** violation (`attempt_lifecycle_invalid`, §6.6/§9): a
  persisted `attempt_sequence` above the frozen `retry_max_attempts` — attempt `max + 1` is unrepresentable
  on every path, so such a row is a forged attempt that is refused rather than read as a further ceiling
  closure, and the ceiling shape belongs to the sequence **at** the ceiling alone (every sequence `>=` the
  maximum is not the same state); a `dispatching` notification whose attempt history holds no live lease, and
  any notification whose history holds two open attempts (the claim writes the lease and the `dispatching`
  transition in one transaction and every closure closes both together, so exactly one attempt may be open,
  and only while the aggregate is `dispatching`); a closed attempt that carries **no** `failure_class` in any
  shape other than the acknowledged one — a forged `failed`, `expired` or `abandoned` row with a legal event
  chain — or a closure class that borrows the acknowledgement member, the acknowledged shape being itself
  valid only beside the `dispatched` notification it produced (a NULL failure code and no other status, so an
  acknowledged-looking attempt persisted beside a `failed`, `expired`, `suppressed` or `cancelled`
  notification is that same forged pair read the other way round); and any attempt whose `attempt_sequence`
  is not contiguous from 1, whose state is outside the closed vocabulary, whose open/closed marker disagrees
  with its state, or whose append-only history is not a contiguous run of legal transitions ending on that
  state.
- The verifier also rejects a **retry-audit** violation (`attempt_lifecycle_invalid`, §6.6/§9): a closure that
  re-armed without the `retry_scheduled` audit row its persisted schedule requires, on the closing attempt
  **or** on the notification's own history; a closure that derived nothing — either exhaustion shape, a
  `terminal`, abort or cancellation class, or the acknowledged hand-off — or a notification with no re-arm at
  all, beside a `retry_scheduled` row it never earned; a `retry_scheduled` row that is not the closing
  attempt's last history row, or that is not the contiguous immediate successor of the `queued` row the same
  re-arm appended — its own `event_sequence` exactly one greater than that `queued` row's, so a pair that a
  skipped sequence leaves adjacent in the ordered read is not that successor, because adjacency in a result
  set is not the placement §9 requires — and does not restate that `queued → queued` transition; and a
  `retry_scheduled` row whose `reason_code` is not the closing attempt's own outcome code, or whose
  `evidence_reference_digest` is not exactly the digest-only retry evidence of that persisted schedule.
  The notification's rows are proved **per re-arm, in the order the lifecycle produced them**
  (`attempt_sequence` against `event_sequence`), never as an unordered set of acceptable digests, so two
  re-arms whose distinct evidences were exchanged are refused rather than each satisfying the other's
  expectation. Because the evidence is proved on both append-only histories, no re-arm can be silently
  unaudited and no exhaustion can be dressed as a re-arm.
- The verifier also rejects a retry-ceiling or reason-code violation: an attempt row whose
  `attempt_sequence` exceeds `retry_max_attempts`, a notification returned to a claimable status (or given
  a further `leased` row) after a `retryable`, `defer` or `expired` closure at
  `attempt_sequence = retry_max_attempts`, a **non-terminal** closure at the ceiling that is not terminal
  `failed` with `failure_reason_code = retry_exhausted` while the closing attempt keeps its own
  non-terminal `failure_class` and non-terminal closure code (a ceiling closure whose attempt was
  rewritten to `failure_class = 'terminal'`, or whose exhausted notification carries any other code, is
  rejected), a `terminal`-class closure that does not carry its
  own declared terminal reason code — or that carries `retry_exhausted`/`retry_window_exhausted`, which
  are reserved for the two non-terminal exhaustion shapes — a **non-terminal** closure with an attempt
  remaining whose clamp left no usable window that is not terminal `expired` with
  `failure_reason_code = retry_window_exhausted`, or that re-armed, persisted any of the four retry
  columns or appended a `retry_scheduled` event of its own, an expired-lease closure that persisted no
  schedule while an attempt remained **and the clamp left a usable window** (a window-exhausted expiry
  derives no schedule either, so it is the accepted NULL shape above rather than a finding), and a
  persisted lease-expiry schedule that disagrees with the deterministic recomputation from the persisted
  `lease_expires_at`. The two exhaustion gates are read in the contract's fixed order — the ceiling
  first, the window second — so a closure at `attempt_sequence = retry_max_attempts` is measured only
  against `retry_exhausted` and the window rule is applied only to a closure with an attempt remaining
  (`attempt_sequence < retry_max_attempts`), which keeps the combined case (a ceiling closure whose clamp
  also leaves no usable window) deterministic and judged by exactly one rule (§6.6, §9).
- The verifier also rejects a terminal-reason violation (`terminal_reason_invalid`, §6.6): a
  notification **whose closing attempt carries `failure_class = 'terminal'`** when its **state is not
  terminal `failed`** (a `terminal` attempt persisted beside an `expired`, `suppressed` or `cancelled`
  notification — the forged terminal-class window closure — whatever matching member the pair carries),
  when its `failure_reason_code` is NULL or empty, is not one of the three members of the closed
  terminal-reason
  vocabulary
  (`contact_unusable`, `send_refused`, `no_route`), or is not **equal** to the `outcome_code` of the
  attempt row that closed it; a `terminal`-class attempt whose `outcome_code` is NULL or outside that
  vocabulary; an attempt whose `failure_class` and `outcome_code` are inconsistent (a `terminal` class
  carrying a non-terminal outcome code, or a `retryable`/`defer` class carrying a vocabulary member); and
  any persisted terminal closure whose append-only notification and attempt events do not carry the same
  normalised member as the records. The vocabulary, equality and notification-status rule is **scoped by
  the closing attempt's class**, because a non-terminal exhaustion closes the notification as `failed`
  too: a `failed` notification whose closing attempt carries a non-terminal `failure_class` (`retryable`
  or `defer` — the class of every retry, defer, lease-expiry and operator-release closure) is therefore
  **not** measured against the three-member vocabulary and is **not** a `terminal_reason_invalid` finding,
  while a `terminal`-class closing attempt is measured against the notification's status as well as its
  reason code, so a well-formed, matching `terminal` reason on an `expired` notification is still
  rejected.
- The verifier also rejects a **ceiling**-exhaustion violation (`retry_exhaustion_invalid`, §14): a
  `failed` notification whose closing attempt carries a **non-terminal** `failure_class` (`retryable` or
  `defer` — the class of every retry, defer, lease-expiry and operator-release closure) and whose
  `failure_reason_code` is not exactly `retry_exhausted` — a NULL, empty, terminal-vocabulary member, the
  window code `retry_window_exhausted` or any other code — or whose closing attempt is **not** the final
  permitted attempt (`attempt_sequence = retry_max_attempts`; a notification closed terminally below the
  ceiling by a non-terminal closure is measured by the window rule below instead), and the forged attempt
  shape that would accompany
  it: a closing attempt whose `failure_class` was rewritten to `terminal` while the notification carries
  the ceiling code, or a non-terminal closing attempt whose `outcome_code` is a member of the closed
  terminal-reason vocabulary or the ceiling code `retry_exhausted`, which is written on the notification
  alone and is never an attempt outcome code. A normal exhausted retry (the attempt at
  `attempt_sequence = retry_max_attempts`
  keeping its non-terminal class, exactly as a lease expiry stays `expired`/`lease_expired`/`retryable`,
  with the notification closed `failed`/`retry_exhausted`) is the valid state this pair of rules must
  **accept**, so the two rules partition every `failed` notification by its closing attempt's class: it
  is measured against the closed terminal-reason vocabulary and its equality rule only when its closing
  attempt is `terminal`-class, and against `retry_exhausted` alone otherwise (§6.6, §9, §15).
- The verifier also rejects a **window**-exhaustion violation (`retry_window_exhaustion_invalid`, §14),
  the exact mirror of the ceiling rule one gate later: a notification closed terminally `expired` whose
  closing attempt carries a **non-terminal** `failure_class` and an attempt remaining
  (`attempt_sequence < retry_max_attempts`) and whose `failure_reason_code` is not exactly
  `retry_window_exhausted` — a NULL, empty, terminal-vocabulary member, the ceiling code
  `retry_exhausted` or any other code — or whose closure derived something the window shape must never
  have: any of the four retry columns written, a `retry_scheduled` event appended, or the outbox row
  re-armed. It is never applied to a closure at `attempt_sequence = retry_max_attempts` (the ceiling rule
  above classifies that state) or to a `terminal`-class closing attempt, which the vocabulary rule above
  classifies — including the status rule that now rejects a `terminal` attempt persisted beside an
  `expired` notification, so an `expired` notification with a `terminal`-class closing attempt is
  `terminal_reason_invalid` and never this diagnostic — so every exhaustion shape is measured by exactly
  one rule and each of the four non-terminal paths — a
  port-reported retry, an attempt-level `defer` closure, a lease-expiry recovery and an operator
  `abandoned` release — is covered by both shapes (§6.6, §9, §15).
- The verifier also rejects a retry rule-set that is not the canonical §9 encoding or lies outside its
  declared narrow-only interval (`retry_policy_invalid`): a `rule_kind = 'retry'` set carrying more than
  one `rule_code`, a duplicated, extra, missing or non-contiguous parameter ordinal, a parameter written in
  `parameter_b`/`parameter_c`/`parameter_d`, a signed, zero-padded or fractional value, an
  `retry_max_attempts` outside `1…3`, a `retry_initial_backoff_seconds` outside `1…120`, a
  `retry_max_backoff_seconds` outside `retry_initial_backoff_seconds…3600`, a
  `retry_backoff_multiplier_bp` outside `10000…30000`, or a `retry_jitter_bp` outside `0…1000` — so every
  retry a version can declare is coordinatewise narrower than or equal to the class baseline (§9), and no
  declared policy can make the modulo, the back-off sequence or the instant arithmetic undefined or
  overflowing.
- The verifier also rejects an `active` version that registers no `expiry` schedule rule, or an
  `expiry_minutes` outside its §6.3 range, with `schedule_expiry_missing`; and an S-owned
  `dzn_notifications` or enriched `platform_outbox` row that carries `expires_at IS NULL` while its
  `scheduled_for` is non-null, or the converse (`scheduled_for IS NULL` with `expires_at` present), with
  `schedule_derivation_divergence` — on an S-owned row the two columns are non-null together and NULL
  together (§6.3/§6.5). The rule is keyed on `scheduled_for IS NOT NULL`, so a row that has not derived an
  instant is **exempt**: a `pending` notification, and a terminal observation closed before any derivation
  (an unavailable tier-F instant, §6.2.4(d); an unresolvable timezone basis, §6.3), legitimately carry both
  NULL and are never rejected for it, while legacy rows that keep every added column NULL stay valid
  (§12).
- The verifier also rejects a schedule composition that is not the closed §6.3 one (`schedule_composition_invalid`):
  zero or two anchor rules, a `lead_time` anchor on a tier-P version or an `immediate` anchor on a tier-F
  version, a `lead_time_minutes` below `1` on a tier-F anchor, a second instance of any 0-or-1 rule code,
  an unregistered `rule_kind = 'schedule'` code, a tier-F eligibility lead time greater than the anchor
  rule's `lead_time_minutes`, or an `expiry_minutes` outside its range. It also rejects a schedule rule
  that is not written in the canonical §6.3 parameter encoding — a `parameter_b`/`parameter_c`/
  `parameter_d` value on a schedule row, a signed, zero-padded, fractional or non-contiguous parameter, a
  `defer_ceiling_minutes` below `1`, a `max_deferrals` above `65535`, a deferral product above its bound,
  or a `timezone_basis` outside the three-value vocabulary.
- The verifier also rejects a timezone composition that has no unique persisted derivation: a version
  whose timezone-sensitive schedule rules (`fixed_local_time`, `send_window`) name different
  `timezone_basis` values (`schedule_timezone_basis_conflict`), and an S-owned **scheduled** notification
  whose persisted `timezone` is not the frozen resolution of its version's single shared basis — empty
  while the version registers a timezone-sensitive rule, or non-empty while it registers none
  (`schedule_derivation_divergence`). A version's basis is **not** resolved at activation — a version has
  no recipient and no subject instance to resolve `recipient_local`/`subject_local` against — so activation
  only validates the vocabulary (`schedule_composition_invalid` outside it) and the inter-rule equality
  above. The resolution happens once per notification in `observe_intent`, and a basis that cannot resolve
  there is the terminal `schedule_timezone_unresolved` **observation** outcome (no instant derived, no
  dispatchable row, no lease), which is a valid closed row and never a verifier finding or a deferred
  `pending` row.
- The verifier also rejects a schedule whose persisted inputs or results disagree with the §6.3
  derivation, applying the base-anchored formulas of §6.3(f) so that a legitimately deferred row passes
  and only a non-reproducing row fails: a missing `observed_at`/`schedule_anchor_at` on a
  `scheduled`-or-later notification, a `scheduled_for` that is not the §6.3 steps 1–3 base instant plus the
  persisted `deferral_count` × the frozen `defer_ceiling_minutes` step, an `expires_at` that is not the
  §6.3 step 6 formula from the re-derived base instant (including a tier-F `expires_at` above the
  announced subject instant), a `deferral_count` above `max_deferrals` or non-zero under a version that
  registers no `deferral` rule, a `coalesce_bucket`-derived identity that does not reproduce from the
  persisted anchor, and a tier-F `scheduled_for` that is not strictly earlier than its subject instant —
  all refused with `schedule_derivation_divergence` or, at activation,
  `schedule_composition_invalid`/`schedule_timezone_basis_conflict`.
- The verifier also rejects an S-owned `platform_outbox` row whose mirrored dispatch representation
  disagrees with the notification's §6.3 derivation: a `scheduled_for` that is not the notification's
  derived `scheduled_for` (a retry never rewrites this mirror — only the `scheduled` transition and an
  accepted §6.3(e) deferral write it), an `expires_at` that is not its base-anchored `expires_at`, a
  `deferral_count` that is not the notification's persisted count, or an `available_at` that is neither
  the mirrored `scheduled_for` (no retry delay in force) nor the `next_available_at` persisted on the
  attempt row that last re-armed the row (a pending §9 retry delay), or that is earlier than the mirrored
  `scheduled_for` (`schedule_derivation_divergence`). The check is applied to a row enriched for a
  notification that has reached `scheduled`, and it is a **row-to-row comparison against the aggregate's locked derivation**:
  the outbox row is a mirror and dispatch index, not an independent derivation root, so nothing re-derives
  a schedule from the outbox row alone and the outbox schema is deliberately not extended with
  `observed_at`, `timezone`, `schedule_anchor_at` or the frozen tier-F subject instant (§6.3(c)(8),
  §7.1).
- Every S-owned notification must have its mirror row, and the verifier proves that from the aggregate side
  as a row-level **anti-join** over `notifications` (and, in the shared rule, as an unconditional mirror
  requirement): a pair whose `notifications.outbox_id` **and** `platform_outbox.notification_id` were both
  cleared leaves nothing for a lookup from either side to find, and is refused
  (`schedule_derivation_divergence`) rather than passing as an aggregate that happens to have no mirror to
  compare (§6.5, §7.1, §8.4).
- The verifier also rejects a persisted retry schedule whose derivation used the wrong expiry branch: a
  schedule that clamps to `expires_at` while the row's `expires_at IS NULL`, or one that omits the clamp
  while `expires_at` is non-null and the clamped value would have been smaller. Both branches of the §9
  rule are checked, so the NULL rule can never be used to skip the clamp that applies.
- The verifier also rejects a tier-F version or notification whose authoritative instant is not durably
  bound (§6.2.4): an `active` tier-F version whose bound intent's instant source is unreadable, a tier-F
  notification whose frozen instant is absent or NULL, and any recorded tier-F instant, derived schedule
  or dispatch evidence that does not reproduce from the persisted subject column. An absent
  `automatic_charge_at` column (the R2 amendment not yet applied) is a failure, never a silent pass; the
  verifier reads the persisted value only and never re-derives one from the commercial policy, the pattern
  wall-clock rule or the cycle boundary.
- The migration declares exactly the eighteen S tables, each exactly once, and no others.

## 8. Commands, services, repositories, events, read models

### 8.1 Application services and commands

`NotificationWorkflowService` (capability `dzn_manage_notification_workflows`)

- `register_workflow`, `register_version`, `set_eligibility_rule`, `set_schedule_rule`,
  `set_retry_rule`, `activate_version`, `supersede_version`, `retire_workflow`.

  `set_eligibility_rule`/`set_schedule_rule`/`set_retry_rule` are draft-only and refuse a frozen version
  with `workflow_rules_frozen`. `activate_version` validates the complete required eligibility set
  against the §6.2.1 baseline and intent/audience matrix (refusing a missing, duplicated, misbound or
  unauthorised requirement with `eligibility_rule_set_incomplete`, `eligibility_binding_mismatch` or
  `audience_not_authorised`), validates the closed §6.3 schedule composition for the version's tier
  (refusing any other cardinality, a tier-mismatched anchor, a tier-F anchor lead time below one minute or
  a tier-F eligibility lead time above the anchor's, a schedule parameter outside the canonical §6.3
  encoding or its admissible range — including a `defer_ceiling_minutes` below `1`, a `max_deferrals`
  above `65535` or a deferral product above its bound — and a `timezone_basis` outside the three-value
  vocabulary, all with `schedule_composition_invalid`, and refusing two timezone-sensitive rules with
  different bases with `schedule_timezone_basis_conflict`; it resolves no basis here, because a version
  has no recipient or subject instance), requires the
  mandatory §6.3 `expiry` rule (refusing its absence with `schedule_expiry_missing`), validates a
  registered retry rule against the canonical §9 encoding and its declared narrow-only intervals
  (refusing a parameter outside its narrow-only interval — any value above the class baseline, §9 — a
  duplicated, extra or missing parameter row, a non-contiguous ordinal, a signed, zero-padded or
  fractional value, or a `retry_max_backoff_seconds` below
  `retry_initial_backoff_seconds`, all with `retry_policy_invalid`; a version that registers no retry rule
  takes the §9 class baseline), refuses a tier-F
  version whose bound authoritative instant is not durably recorded (`tier_f_instant_unavailable`,
  §6.2.4), freezes the rule set (`rule_set_digest`/`rule_frozen_at`) and claims the intent routing slot in
  one transaction; a lost routing race fails closed with `intent_routing_conflict` and rolls back whole, so
  an intent never has two active versions. `supersede_version` and
  `retire_workflow` clear `active_slot`,
  `intent_active_slot` and the workflow's `active_version_id` atomically.

`NotificationTemplateService` (capability `dzn_manage_notification_templates`)

- `register_template`, `register_version`, `activate_version`, `retire_template`.

`NotificationService` (capability `dzn_manage_notifications`)

- `observe_intent` (enrich a pending outbox intent row, resolve the workflow version, persist `observed_at`
  and the `timezone` resolved for that concrete recipient/subject instance — the one place a
  `timezone_basis` is resolved, §6.3 — derive the schedule by the §6.3 algorithm from the persisted tier-F
  instant where the intent is tier F, record `schedule_anchor_at`/`scheduled_for`/`expires_at` with
  `deferral_count = 0` and mirror that representation to the outbox row, freeze the rendered
  snapshot, create the aggregate, close an intent whose bound instant is unavailable terminally rather than
  waiting or re-deriving one, close an unresolvable `timezone_basis` terminally on
  `schedule_timezone_unresolved` before any instant is derived, and close a tier-F derivation that
  violates the §6.3(d) strict-before postcondition the same way instead of scheduling a send at or after
  the announced instant; a pending row remains valid with `scheduled_for`/`expires_at` NULL until its
  `scheduled` transition, §6.5),
  `schedule`, `defer` (the §6.3(e) bounded step: increment `deferral_count`, re-derive `scheduled_for` from
  the persisted base, mirror both to the outbox, or close terminally when the moved instant breaks the
  frozen window or a tier-F instant predicate), `enqueue`, `cancel`, `expire`, `suppress`, `reissue` (a
  fresh notification for a new subject instant; never a mutation of a terminal one).

`NotificationDispatchService` (system actor, capability `dzn_operate_notification_dispatch`)

- `claim_lease`, `re_evaluate_eligibility`, `hand_off`, `record_outcome`, `release_lease`,
  `abandon_lease`, `recover_expired_leases`.

`NotificationSuppressionService` (capability `dzn_manage_notification_suppressions`)

- `suppress`, `release`.

`NotificationPrivacyService` (capability `dzn_manage_notification_privacy`)

- `erase_recipient`, `purge_expired_envelopes`, `record_tombstone`.

### 8.2 Ports (interfaces only — no transport implementation in S)

- `NotificationTransportPort::handoff(array $authorisedCommand): array` — accepts an
  already-authorised, idempotent, channel-neutral send command and returns a hand-off
  acknowledgement. S registers no real binding.
- `NotificationDeliveryIntakePort::submit(array $normalisedFacts): void` — accepts a verified,
  normalised delivery fact. S registers no real binding.
- `NotificationRecipientReadPort`, `NotificationSubjectReadPort` — read-only projections of other
  modules' authoritative read models.

Test doubles for these ports live under `tests/` only and are never loaded in production.

### 8.3 Repositories

`NotificationWorkflowRepository`, `NotificationTemplateRepository`, `NotificationRepository`,
`NotificationAttemptRepository`, `NotificationDeliveryRepository`, `NotificationSuppressionRepository`,
`NotificationPrivacyRepository`, and `NotificationOutboxRepository` (the S read/write adapter over the
extended `platform_outbox`; it does **not** replace `RecurringOutboxRepository`, which keeps its
insert-only publish contract).

Each follows the established `READ COMMITTED` transaction wrapper, named-index duplicate arbitration,
digest-only command evidence, and append-only event/history discipline.

### 8.4 Read models

`NotificationWorkflowReadService`, `NotificationTemplateReadService`, `NotificationReadService`,
`NotificationAttemptReadService`, `NotificationDeliveryReadService`,
`NotificationSuppressionReadService`, `NotificationDiagnosticsReadService`.

All reads are capability-protected, PII-minimised, envelope-opaque and fail closed on a malformed
aggregate.

## 9. Idempotency, retry, scheduling and lease semantics

- **Logical identity.** `notification_key_digest = hash_hmac('sha256', 'notification:' || workflow_key
  || ':' || workflow_version || ':' || intent_key || ':' || audience || ':' || recipient_digest || ':'
  || subject_aggregate || ':' || subject_id || ':' || coalesce_bucket, wp_salt('dzn_notification'), 64)`.
  `coalesce_bucket` is the §6.3 step 7 derivation from the persisted anchor (the empty string when the
  version registers no `coalesce` rule), so it is deterministic per anchor and never a fresh-clock read.
  `UNIQUE KEY notification_key_digest` makes a replayed intent converge on exactly one notification.
- **Replay vs conflict.** Every command records a key digest and a payload digest; an exact replay is
  idempotent, and a same-key/different-payload replay fails closed with `notification_replay_conflict`.
  Corruption is never silently repaired.
- **Intent observation.** R2 intent rows are created inside the originating transaction and are
  insert-only. S observes them after commit in its own transaction and enriches the row
  (`notification_id`, `workflow_key`, `workflow_version`, `intent_key`, `audience`, `priority`) and, at the
  `scheduled` transition, with `scheduled_for`, `expires_at`, `deferral_count` and `available_at` —
  writing `deferral_count = 0` alongside the derived instant — so the outbox row carries the same
  base-plus-count representation as the notification from the moment it is dispatchable. A row whose
  notification has not reached `scheduled` (a `pending` aggregate, or a terminal observation closed before
  any derivation on `tier_f_instant_unavailable`/`schedule_timezone_unresolved`) keeps the derived triple
  NULL and is never claimed: the claim selects only notifications that are `queued` (inside their derived
  window), so a pre-scheduling row — and a notification with no derived instant at all — is never
  dispatchable. The mirror is later checked row-to-row against the aggregate's locked derivation, never
  re-derived from the outbox row alone (§6.3(c)(8), §7.1/§7.3). Phase-1 rows and unregistered intents are
  never touched.
- **Lease.** A dispatcher claims work by re-reading the aggregate and the outbox row under the S lock
  order (§10) and writing `status = 'leased'`, `leased_at`, `lease_token_digest` and
  `attempt_count + 1`, then inserting the attempt row. `status` transitions are guarded by the exact
  prior value, so a lost race simply loses the claim.
- **Eligibility at dispatch.** The claim re-evaluates the version's complete frozen required set
  (§6.2.1 baseline + intent/audience tier), including the §6.2.3 bound-evidence `subject_state_is`
  predicate evaluated over the subject's immutable history, and the suppression check; a notification
  that is no longer eligible moves to its controlled terminal state
  (`suppressed`/`expired`/`cancelled`) without a send.
- **Retry policy: one canonical rule-set, one narrow-only partial order, bounded ceiling.** A version registers at most
  one retry rule-set (`rule_kind = 'retry'`), stored exactly like a schedule rule in the §6.3 canonical
  one-row-per-parameter encoding: one `rule_code` (`retry`), one row per parameter with `ordinal` = the
  parameter position and the value as canonical unsigned decimal text in `parameter_a`, and
  `parameter_b`/`parameter_c`/`parameter_d` NULL on every row (§7.2). Exactly five contiguous parameter
  rows (`ordinal` `1…5`) are required; a second `rule_code`, a duplicated, extra, missing or
  non-contiguous ordinal, a value in any other parameter column, or a signed, zero-padded or fractional
  value is malformed and refused at activation with `retry_policy_invalid` (§7.3, §8.1). "Narrow only" is
  a **real partial order against the approved class baseline**, not merely a bound: the ordering is the
  coordinatewise (product) order on the five declared parameters, the class baseline is its **maximum**
  element and the widest policy, and each parameter's admissible interval is bounded above by the baseline
  value, so a version that registers a retry rule-set can only declare a value **no greater than** the
  baseline on every parameter. Each parameter is an approved **maximum budget**, so a version may only
  lower an axis — fewer attempts, a shorter first back-off, slower growth, a lower delay ceiling and less
  jitter than the baseline — and may never raise one; the order is a structural order on the declared
  parameter vector, so lowering one axis never licenses exceeding another. A version that registers no
  retry rule-set takes the baseline itself:

  | Ordinal | Parameter | Admissible interval (narrower-or-equal than the baseline) | Class baseline (widest policy) |
  | --- | --- | --- | --- |
  | 1 | `retry_max_attempts` | `1 ≤ v ≤ 3` | `3` |
  | 2 | `retry_initial_backoff_seconds` | `1 ≤ v ≤ 120` | `120` |
  | 3 | `retry_backoff_multiplier_bp` | `10000 ≤ v ≤ 30000` | `30000` |
  | 4 | `retry_max_backoff_seconds` | `retry_initial_backoff_seconds ≤ v ≤ 3600` | `3600` |
  | 5 | `retry_jitter_bp` | `0 ≤ v ≤ 1000` | `1000` |

  The intervals are the machine-checkable form of that partial order — the per-parameter check
  `declared ≤ baseline` **is** the order, so there is no grammar for more attempts
  (`retry_max_attempts ≤ 3`), a longer first back-off (`retry_initial_backoff_seconds ≤ 120`), faster
  growth (`retry_backoff_multiplier_bp ≤ 30000`), a higher delay ceiling (`retry_max_backoff_seconds ≤
  3600`) or a wider jitter span (`retry_jitter_bp ≤ 1000`) than the approved baseline. The remaining
  clauses keep the derivation total rather than merely bounded: `retry_max_attempts ≥ 1` so the ceiling
  always exists, `retry_initial_backoff_seconds ≥ 1` and `retry_max_backoff_seconds ≥
  retry_initial_backoff_seconds` so the first back-off is exactly the declared initial value rather than a
  widened clamp (with `retry_initial_backoff_seconds ≤ 120` and the ceiling at `3600`, the interval is
  never empty), and `retry_backoff_multiplier_bp ≥ 10000` so the back-off sequence is monotone
  non-decreasing.
  The intervals also keep every persisted value inside its declared column width (§7.2) and every
  intermediate value inside 64-bit integer arithmetic: the largest admissible product is
  `retry_max_backoff_seconds × retry_backoff_multiplier_bp ≤ 3600 × 30000 = 1.08e8`, `applied_jitter_bp`
  `≤ 1000` fits `smallint unsigned`, the jittered `backoff_seconds` is at most `3600 × 1.1 = 3960` and
  fits `int unsigned`, and an attempt sequence or `attempt_count` of at most `3` fits `smallint unsigned`.
  `retry_jitter_bp` is the **span** of the additive jitter in basis points (`0` disables jitter
  deterministically); the value the formula below actually realises for one attempt is the distinct
  `applied_jitter_bp` persisted on that attempt row.
  `retry_max_attempts` is the ceiling on the **total number of attempts** (lease acquisitions) one
  notification may make, so `retry_max_attempts = 1` means the single attempt is the last one and no retry
  exists. The ceiling is a contract invariant a version may narrow but never remove, and attempt
  accounting is durable and acquisition-based: the acquisition that inserts `attempt_sequence = a` also
  increments `platform_outbox.attempt_count`, so a running attempt is always consumed from the ceiling
  before any closure decides anything.
  Failure classes are `retryable`, `defer`, `terminal`. A `terminal` class closes the notification as
  terminal `failed` with **its own declared terminal reason code** — one member of the closed §6.6
  terminal-reason vocabulary, persisted identically as the attempt's `outcome_code` and the notification's
  `failure_reason_code` — wherever it occurs, the attempt ceiling included, and never as
  `retry_exhausted`, and never with any other notification status: the status is part of the class's
  rule, so a `terminal` attempt persisted beside an `expired`, `suppressed` or `cancelled` notification is
  refused and rejected with `terminal_reason_invalid` rather than being accepted on a matching reason code
  (§6.6, §7.3); `defer` re-arms through exactly the same bounded path as
  `retryable` (same ceiling accounting, same persisted quadruple, same recovery convergence) with the
  distinct outcome code `deferred`. Every **non-terminal** closure follows one rule — **re-arm only while
  an attempt remains **and** the derivation leaves a usable window**: `attempt_sequence <
  retry_max_attempts`, **and** the clamp of the back-off bullet below leaving an instant strictly later
  than the closure instant that — where `expires_at` is non-null — is also strictly earlier than
  `expires_at`. The two gates are read in one fixed order — **the ceiling first, the window second** — so
  exhaustion is deterministic even when both fail and each exhaustion cause maps to exactly one reason
  code: a closure at `attempt_sequence = retry_max_attempts` is **ceiling exhaustion** and closes the
  notification terminally as `failed` with `failure_reason_code = retry_exhausted` — a code reserved for
  exactly this ceiling, written on the notification alone and never as the closing attempt's
  `outcome_code`, which never becomes `terminal` on an exhaustion path — whatever its clamp would have
  done; only a closure **below** the ceiling (`attempt_sequence < retry_max_attempts`) reaches the window
  gate, where a clamp that leaves no usable window is **window exhaustion** and closes the notification
  terminally as `expired` with `failure_reason_code = retry_window_exhausted`, so the window code always
  implies that an attempt remained and the combined case is never ambiguous. Both exhaustion shapes
  re-arm nothing, derive and persist no retry schedule, and keep the closing attempt's non-terminal class
  and closure code (`retryable`, `deferred`, or `expired`/`lease_expired`). No path — port-reported retry,
  attempt-level defer, replayed command or lease-expiry recovery — can produce attempt
  `retry_max_attempts + 1`.
  A retry always creates a new attempt sequence and re-renders nothing (the frozen snapshot stands)
  unless the definition explicitly re-renders on a declared variable change.
  Schedule-level deferral (§6.3(e)) is a distinct pre-dispatch concept: it increments the persisted
  `deferral_count` and re-derives `scheduled_for` as the §6.3 steps 1–3 base instant plus
  `deferral_count × defer_ceiling_minutes × 60`, at most `max_deferrals` times, before the first lease,
  consuming no attempt and mirroring both values to the outbox row. It moves neither the base instant nor
  the base-anchored `expires_at`, and it is refused (closing the notification terminally) when the
  re-derived instant would not be strictly earlier than the frozen `expires_at`
  (`expired`/`retry_window_exhausted`), or would break the tier-F strict-before postcondition
  (`expired`/`eligibility_expired`) or the version's `lead_time_at_least` slack
  (`expired`/`lead_time_insufficient`).
- **Deterministic keyed jitter.** Jitter is derived, never sampled, and only ever for a closure that
  leaves a further attempt available. With `a` = the `attempt_sequence` being closed
  (`1 ≤ a < retry_max_attempts`, so `retry_max_attempts ≥ 2` on every retry, defer or lease-expiry
  recovery path) and the notification's own immutable identity and frozen rule parameters as the only
  inputs:

  ```
  jitter_entropy(a)    = hexdec(substr(hash_hmac('sha256',
                           'retry_jitter:' || notification_key_digest || ':' || workflow_version || ':' || a,
                           wp_salt('dzn_notification')), 0, 8))       // uint32, fixed 8 hex chars
  applied_jitter_bp(a) = jitter_entropy(a) mod (retry_jitter_bp + 1)  // 0 … retry_jitter_bp
  base_backoff(1)      = min(retry_max_backoff_seconds, retry_initial_backoff_seconds)
  base_backoff(a)      = min(retry_max_backoff_seconds,               // bounded recurrence, a > 1
                             floor(base_backoff(a - 1)
                                   * retry_backoff_multiplier_bp / 10000))
  next_available       = min(finished_at
                                 + base_backoff(a)
                                 + floor(base_backoff(a) * applied_jitter_bp(a) / 10000),
                             finished_at + retry_max_backoff_seconds,
                             expires_at)                 // when expires_at IS NOT NULL
                       = min(finished_at
                                 + base_backoff(a)
                                 + floor(base_backoff(a) * applied_jitter_bp(a) / 10000),
                             finished_at + retry_max_backoff_seconds)
                                                         // when expires_at IS NULL: no expiry term
  ```

  `rand`/`mt_rand`/`random_int`, host or worker identity, process identity, `now()` and every other
  ambient source are **never** jitter inputs; the only time input is the closure instant persisted on the
  closing attempt row — `finished_at` for a retryable/defer closure, `lease_expires_at` for a lease-expiry
  closure — so recovery never reads a clock. `retry_jitter_bp + 1` is a divisor in `1…1001`, so the
  modulo is defined for every declared span and a span of `0` yields the single value `0` deterministically.
  The digest prefix is a fixed 8 hex characters (a 32-bit
  value that fits an integer on every supported PHP build and never depends on float formatting). The base
  back-off is computed with the **bounded recurrence above, and that recurrence is the sole canonical
  semantics**. The closed form `retry_backoff_multiplier_bp^(a - 1) / 10000^(a - 1)` is **not** an
  equivalent alternative and is never used as a specification, because the recurrence floors after every
  step while the closed form floors once, so the two are different functions. The difference first shows at
  `base_backoff(3)`: for the valid policy `retry_initial_backoff_seconds = 1`,
  `retry_backoff_multiplier_bp = 15000` and any `retry_max_backoff_seconds ≥ 2` the recurrence yields
  `base_backoff(2) = floor(1 × 1.5) = 1` and `base_backoff(3) = floor(1 × 1.5) = 1`, while the closed form
  yields `floor(1 × 1.5²) = 2`. That image is a defined value of the derivation function — the contract
  evaluates the function only while `1 ≤ a < retry_max_attempts`, so the two definitions happen to agree on
  today's evaluated domain — and the point is that they are **not** interchangeable: the recurrence is the
  declared semantics, so an implementation must never substitute the closed form, which would silently
  change the function the moment the admissible envelope admitted a larger attempt budget. The recurrence
  is also the overflow-safe form: every
  intermediate product is bounded by `retry_max_backoff_seconds × retry_backoff_multiplier_bp ≤ 1.08e8`
  and every intermediate quotient by `retry_max_backoff_seconds`, so it is exact in 64-bit integer
  arithmetic for every admissible policy, whereas the closed form's intermediate power overflows. The
  jitter product is bounded by `3.6e6`, the instant
  additions stay in 64-bit integer seconds, and every derived instant is checked against the stored
  `datetime` domain: an instant outside it (unreachable for S-owned work, whose window clamps to a
  non-null `expires_at`, and possible only for a forced NULL-expiry fixture) fails closed as
  `retry_schedule_divergence` rather than wrapping, truncating or being silently clamped. Jitter is additive only
  (`applied_jitter_bp(a) ≥ 0`), so a retry can never be scheduled earlier than the base back-off, and the
  same immutable inputs always derive the same `next_available` on any host, in any process, at any time.
  At `a = retry_max_attempts` the formula is not evaluated at all: that closure is exhaustion (§6.6),
  nothing is scheduled, and the four retry columns stay NULL — a `terminal` class at any attempt sequence
  likewise derives no schedule and closes the notification as terminal `failed` with its own declared
  reason code, one of the closed §6.6 terminal-reason vocabulary members (§6.6).
- **Expiry term: mandatory window for S-owned work, one explicit NULL rule.** Every activated version
  registers the mandatory §6.3 `expiry` rule, so every S-owned notification that has reached `scheduled`
  (and every attempt, which only exists past that transition) carries a non-null `expires_at` and the
  retry window is always bounded. Where a row carries `expires_at IS NULL` — an S-owned row that has not
  derived an instant (§6.3/§6.5), a legacy
  `platform_outbox` row S never claims (§12), or a deliberately forced fixture — the expiry term is
  **omitted from the minimum** (`expires_at` is not one of the `min` arguments), the "not strictly earlier
  than `expires_at`" window check is **skipped**, and `retry_window_exhausted` can then only arise from the
  remaining condition (a clamped instant that is not strictly later than the closure instant). Schedule
  persistence, replay, lease-expiry recovery, the verifier and the tests implement this same rule, so no
  path compares against a NULL instant and the NULL branch is never a licence to skip the clamp when a
  window does exist.
- **Back-off instant, persisted and audited.** `next_available` is the instant derived above, clamped to
  `retry_max_backoff_seconds` and — only when non-null — to `expires_at`. It is a retry **delay**, so it
  re-arms the outbox row by moving `available_at` alone: the mirrored `platform_outbox.scheduled_for`
  keeps the aggregate's schedule-derived value and is **never** rewritten by a retry (only the `scheduled`
  transition and an accepted §6.3(e) deferral write it), which is what keeps §6.3(c)(8)'s immutable
  schedule mirror and the §7.3 mirror check satisfiable on every retry. **Only a closure that re-arms
  derives and persists a schedule**, and a closure re-arms only when **both** gates hold: an attempt
  remains (`attempt_sequence < retry_max_attempts`) **and** the clamp leaves a **usable window** — the
  clamped instant is strictly later than the closure instant and, where `expires_at` is non-null, strictly
  earlier than `expires_at`. For that closure alone the closing attempt row
  persists `applied_jitter_bp`, `base_backoff_seconds`,
  `backoff_seconds` (the jittered value) and `next_available_at`, and the append-only attempt and
  notification history records a `retry_scheduled` event whose evidence is the digest-only
  `hash_hmac('sha256','retry_evidence:' || notification_key_digest || ':' || attempt_sequence || ':' ||
  applied_jitter_bp || ':' || backoff_seconds, wp_salt('dzn_notification'))`. That evidence is recorded on
  **both** append-only histories in the same transaction and before the outbox row is re-armed: the closing
  attempt appends the digest-only companion as its own last history row (a row that restates the state the
  closure produced and never moves the attempt), and the notification appends the matching
  `retry_scheduled` row directly after the `queued` row the same re-arm produced — the contiguous successor
  whose own `event_sequence` is exactly one greater than that `queued` row's and never a row that is merely
  adjacent to it in the ordered read — restating that state and
  carrying the closure's own non-terminal outcome code, so one persisted schedule is evidenced exactly once
  on each history and neither history can announce a re-arm the other did not prove. No raw payload and no
  clock reading is stored. A clamp that leaves **no** usable window — the clamped instant is not strictly
  later than the closure instant, or (when `expires_at` is non-null) not strictly earlier than
  `expires_at` — is exhaustion by window **for a closure with an attempt remaining** (the ceiling gate is
  read first, and a closure at `attempt_sequence = retry_max_attempts` is already the ceiling shape above
  whatever its clamp does, §9), and such a closure derives nothing: the notification
  closes terminally as `expired` with `failure_reason_code = retry_window_exhausted`, the closing attempt
  keeps its own non-terminal `failure_class` and closure code, none of the four retry columns is written,
  no `retry_scheduled` event is appended, and nothing is re-armed — exactly as at the attempt ceiling, and
  exactly the NULL exhaustion shape §7.2/§7.3 state and accept.
- **Replay and recovery convergence.** Replay, lease-expiry recovery and any later reconciliation
  recompute the formula from the persisted inputs — including the persisted `lease_expires_at` that is the
  closure instant of an expired lease — **under the same expiry branch the schedule was persisted with**
  (a non-null `expires_at` clamps to the window and enables the window check; a NULL `expires_at` drops the
  expiry term and skips the window check) and must reproduce the persisted
  `(applied_jitter_bp, base_backoff_seconds, backoff_seconds, next_available_at)` exactly. A divergence
  raises the `retry_schedule_divergence` diagnostic, refuses to re-arm the notification and requires
  operator correction — it is never silently rescheduled. Recovery never re-derives `next_available` from
  a fresh wall-clock read, so a crash between persisting the schedule and re-arming the row cannot change
  the instant. The attempt close, the persisted schedule, the `retry_scheduled` event and the re-arm are
  one transaction, so a second recovery pass over an already-closed attempt replays the persisted values
  and changes nothing: recovery is idempotent and never double-counts the ceiling.
- **Lease expiry as a retry class.** An expired lease always has a persisted attempt row (the lease write
  and the attempt insert are one transaction), so recovery never returns work to a claimable state
  without an accounting step. Recovery closes the attempt as `expired` with outcome code `lease_expired`
  and `failure_class = retryable` when a further attempt remains, and treats the closure as a retryable
  closure of the bounded retry path: with `a` = the expired attempt's `attempt_sequence` and the closure
  instant = the persisted `lease_expires_at` (written at acquisition, deterministic, never a fresh clock
  read), it applies the identical ceiling-and-window rule: while `a < retry_max_attempts` **and** the
  clamp leaves a usable window it persists
  `applied_jitter_bp`, `base_backoff_seconds`, `backoff_seconds` and `next_available_at` on the expired
  attempt row under the same expiry branch (§9: the window clamp and its check apply only to a non-null
  `expires_at`; a NULL `expires_at` drops the term and skips the check), appends the `retry_scheduled`
  event and only then returns the outbox row to claimable by moving **only** `available_at` to
  `next_available_at` — the mirrored `scheduled_for` is left at the aggregate's schedule-derived value.
  That usable-window gate is a second, independent gate beside the ceiling: a lease expiry whose clamp
  leaves **no** usable window is exhaustion by window even though an attempt remains — the attempt still
  closes `expired` with `outcome_code = lease_expired` and `failure_class = retryable`, but recovery
  derives nothing: none of the four retry columns is written, no `retry_scheduled` event is appended and
  the outbox row is **not** returned to claimable, while the notification closes terminally as `expired`
  with `failure_reason_code = retry_window_exhausted`, exactly like the same window exhaustion on a
  port-reported retry, an attempt-level `defer` closure or an operator `abandoned` release.
  At `a = retry_max_attempts` it is exhaustion, and it stays the **non-terminal/retryable** exhaustion
  path: the attempt remains `expired` with `outcome_code = lease_expired` and
  `failure_class = retryable` — a lease expiry is never reclassified as `terminal` and never borrows a
  terminal vocabulary member (§6.6) — nothing is re-armed, no retry schedule is derived or persisted, and
  the notification closes terminally as `failed` with `failure_reason_code = retry_exhausted`. A lease
  that expires again after a re-arm consumes the next attempt sequence exactly like a port-reported
  retry, so repeated lease expiry walks attempts
  `1 … retry_max_attempts` and terminates on the last one: recovery can never duplicate a send and can
  never outlive the ceiling. Claiming re-evaluates the frozen required eligibility set and suppression
  before each acquisition, so an expired lease that is no longer eligible closes through the controlled
  terminal path (`suppressed`/`expired`/`cancelled`) instead of re-arming. The notification identity is
  unchanged throughout, so no recovery path can create a second notification or a second outbox row. An
  operator `abandoned` release follows the same rule: it re-arms only while an attempt remains **and** the
  clamp leaves a usable window, and it never licenses an attempt beyond the ceiling, while a release
  below the ceiling whose clamp leaves no usable window is the same `expired`/`retry_window_exhausted`
  window exhaustion rather than a re-arm and a release at `attempt_sequence = retry_max_attempts` is the
  ceiling shape — `failed`/`retry_exhausted`, read first — whatever its clamp does (§6.6).

## 10. Concurrency and serialisation

- **Serialisation root:** the row of `dzn_notifications` named by the observed intent. S takes no lock
  on any other module's table.
- **Lock order (fixed, never inverted):** `notification aggregate → its platform_outbox row → its
  attempt rows`. Purge/erasure and workflow activation use the same order.
- Cross-module reads (subject state, recipient resolution, consent fact) happen outside the S
  transaction, immediately before the guarded write, and are revalidated under the guard when the
  write depends on them.
- Workflow activation serialises on `notification_workflows.active_version_id` and the intent routing
  slot; template activation on `notification_templates.current_version`. A dispatch in flight always
  resolves a version that is still readable, and a superseded version is never re-activated. Two
  activations competing for one `intent_key` are arbitrated by `intent_active`, fail closed with
  `intent_routing_conflict`, and never resolve as last-writer-wins.
- Named unique indexes are the only duplicate arbiter: `notification_key_digest`, `outbox_id`,
  `platform_outbox.notification_id`, `attempt_lease`, `lease_token_digest`, `notification_sequence`,
  `provider_reference`, `workflow_active`, `intent_active`, `version_rule`.
- A schedule-level deferral (§6.3(e)) serialises on the same root and re-derives under the guard: the
  persisted `deferral_count` is read, incremented, turned into the step-5 `scheduled_for` and mirrored to
  the outbox row inside one transaction, so two deferrals racing on one notification cannot both apply
  from the same count and a deferral cannot be applied against a stale instant. A deferral racing the
  dispatch claim (§15 `deferral_vs_claim`) resolves through the same lock order: whichever holds the root
  first completes, and the loser either sees the incremented count or loses the claim to the guarded
  `status` transition — never a moved instant under a live lease and never a partially mirrored count.

## 11. Privacy and security

- **Identity minimisation.** S stores `recipient_digest`, `recipient_contact_digest`,
  `subject_reference_digest` and `notification_key_digest`. It never stores a raw phone number, email
  address, name, or provider account identifier as a business column.
- **Dispatch envelopes.** `recipient_contact_envelope` and `rendered_params_envelope` use
  authenticated encryption per SECURITY.md §4 (Sodium secretbox or AES-256-GCM, unique random nonce,
  application key material derived from the WordPress salts with domain separation, stored
  `cipher_version`). Decryption is limited to the dispatch/delivery path capability; an unavailable
  cipher fails closed with `envelope_decrypt_failure` and the work stays claimable.
- **No raw provider payload.** Delivery stores digests plus normalised vocabulary only.
- **Capabilities.** Administrators need an explicit WordPress capability; every mutation also requires
  an intent-specific nonce (SECURITY.md §3). Read and write capabilities are separate, and the
  dispatch capability is separate from every administrative capability.
- **Retention and erasure.** `contact_expires_at` bounds the contact envelope's usefulness; the
  envelope is nulled at erasure or expiry while the digest-anchored row and its events remain as
  integrity evidence. Erasure writes `dzn_notification_privacy_tombstones` and
  `erase_recipient` refuses to erase a notification whose dispatch is in flight
  (`notification_dispatch_in_flight`).
- **Diagnostics.** No PII, no envelope content, no secret, no provider credential, and no channel-only
  field may appear in any diagnostic or read model.
- **Fail-closed reads.** A malformed aggregate, a digest of the wrong width, an unknown vocabulary
  value, or a missing required source fails closed and is reported by code.

## 12. Legacy and backward compatibility

The extension is compatibility-critical and is asserted directly:

1. Every added `platform_outbox` column is nullable with no default; no existing column is renamed,
   removed, retyped, or given a new constraint; `status varchar(16)` and `attempt_count` are unchanged.
   The new unique key constrains only the new `notification_id` column, whose value stays NULL on every
   legacy row (unlimited NULLs per unique index), so no existing writer gains a new failure mode.
2. `RecurringOutboxRepository::publish()` (R2) keeps working unchanged: it inserts only the original
   column set, and the new columns accept NULL, including the unique `notification_id` key.
3. The Phase-1 invitation delivery seam keeps working unchanged:
   `PrincipalInvitationRepository::outbox()` inserts the original column set, and
   `markDeliveryPrepared()` (`status = 'prepared'`, `leased_at`, `attempt_count + 1`) still matches its
   guard. Both keep working under the added unique `notification_id` key because they leave it NULL. The
   S dispatcher treats `prepared` as owned by the legacy seam and never claims it.
4. `verify_principal_invitation_schema()` is left byte-for-byte unchanged; it still asserts only the
   columns and the `idempotency_key` unique key it asserted before.
5. The S `status` vocabulary (`pending`, `scheduled`, `leased`, `dispatched`, `delivered`, `failed`,
   `cancelled`, `expired`, `suppressed`) is a **superset** of the existing values; `prepared` remains
   valid. S mutates only rows it owns (`workflow_key IS NOT NULL` and an S-registered `intent_key`),
   and never a row with `invitation_id`/`generation_id` set.
6. Schema 31 → 32 is repeat-safe and leaves every R1, R2 and Phase-1 row unchanged.
7. Nothing is backfilled, inferred, or retro-notified: existing pending intent rows are consumed only
   through the normal observation path and only if their intent is registered.
8. A legacy row keeps every added column NULL — including `scheduled_for`, `expires_at` and
   `deferral_count` — and no S behaviour depends on it: S never schedules or dispatches a row with a NULL
   `expires_at` or a NULL `deferral_count`, and on an S-owned row the derived `scheduled_for` and
   `expires_at` are non-null together and NULL together (§6.3/§6.5), so the explicit NULL rule of §9
   applies to rows S never owns and to S-owned rows that have not yet derived an instant, and the R2
   amendment of §6.2.4 adds a nullable column that no existing writer must populate.

## 13. Provider-neutral seams

- No provider SDK, credential, webhook, template registration, or live/test send exists in S.
- Hand-off occurs only through `NotificationTransportPort`, which receives an already-authorised,
  idempotent, channel-neutral command; the Meta/email/SMS adapter is Phase T.
- Delivery facts enter only through `NotificationDeliveryIntakePort` as verified, normalised facts.
- Provider identifiers are never stored as business columns and never act as authorisation.
- No Amelia query, hook, or scheduled-send configuration is touched.

## 14. Channel-independent diagnostics and operational read models

`NotificationDiagnosticsReadService` is a capability-protected projection over the S tables and the
extended outbox. Fixed diagnostic vocabulary (each reports a count and the affected identifiers, never
payloads):

| Diagnostic | Meaning |
| --- | --- |
| `unregistered_intent` | a pending intent row whose `intent_key` has no workflow |
| `unroutable_intent` | a registered intent with no active workflow version |
| `orchestration_backlog` | pending/scheduled rows waiting beyond their derived instant |
| `stuck_lease` | a lease past `lease_expires_at` without a closed attempt |
| `expired_lease` | recovered leases, by workflow version |
| `orphan_outbox_row` | an S-owned row with no matching notification row |
| `workflow_version_integrity` | an aggregate that fails its own validator |
| `workflow_rule_set_mutated` | a version whose recomputed rule digest disagrees with its frozen `rule_set_digest` |
| `intent_routing_conflict` | a workflow activation that lost the intent routing arbitration |
| `template_variable_mismatch` | a frozen snapshot whose variable set disagrees with the contract |
| `eligibility_rule_set_incomplete` / `eligibility_binding_mismatch` / `audience_not_authorised` | an activation refused because the §6.2.1 required set was incomplete, misbound or aimed at an unauthorised audience |
| `ineligible_subject_state` / `consent_absent` / `recipient_unresolved` | eligibility refusals by reason; the bound-evidence miss of §6.2.3 reports as `ineligible_subject_state` |
| `suppressed` | suppression refusals at enqueue and at dispatch |
| `retry_schedule_divergence` | a recomputed retry instant that disagrees with the persisted attempt schedule |
| `retry_policy_invalid` | an activation refused because the version's retry rule-set is not the canonical §9 encoding or a parameter lies outside its declared **narrow-only** interval (a duplicated, extra, missing or non-contiguous parameter row, a signed, zero-padded or fractional value, a `retry_max_attempts` outside `1…3`, an initial back-off outside `1…120`, a maximum back-off outside `retry_initial_backoff_seconds…3600`, a multiplier outside `10000…30000`, or a jitter span outside `0…1000` — i.e. any value above the class baseline, §9) |
| `retry_exhausted` | notifications closed terminally as `failed` by the bounded retry **ceiling** — a non-terminal closure at `attempt_sequence = retry_max_attempts` on any path (a port-reported retry, an attempt-level `defer` closure, a lease-expiry recovery or an operator `abandoned` release); the ceiling is the gate read **first**, so a closure at the ceiling counts here even when its clamp would also have left no usable window (§9) — never a window exhaustion, which closes the notification `expired` and is counted as `retry_window_exhausted`, and never a `terminal`-class closure, which closes with its own declared terminal reason code wherever it occurs; both exhaustion shapes derive no retry schedule, so the verifier's persisted-quadruple requirement applies only to a closure that actually re-arms with an attempt remaining **and** a usable window (§7.3), and the normal exhausted-retry shape (a non-terminal closing attempt carrying that NULL quadruple with the notification `failed`/`retry_exhausted`) is a **valid** state and never a diagnostic |
| `retry_window_exhausted` | notifications closed terminally as `expired` by the **window** gate — a non-terminal closure **with an attempt remaining** (`attempt_sequence < retry_max_attempts`) whose §9 clamp leaves no usable window (the clamped instant is not strictly later than the closure instant or, where `expires_at` is non-null, not strictly earlier than `expires_at`), reported for every path (a port-reported retry, an attempt-level `defer` closure, a lease-expiry recovery or an operator `abandoned` release); the window condition applies only when `expires_at` is non-null, so with a NULL instant the ceiling and the non-advancing clamp are the only exhaustion paths (§9) — never a closure at the ceiling (the ceiling gate is read first and reports `retry_exhausted`) and never a `terminal`-class closure (§6.6, §9) |
| `terminal_reason_invalid` | a `terminal`-class closure refused or rejected because its reason code is missing or empty, is not one of the three members of the closed §6.6 terminal-reason vocabulary, is not equal on the attempt (`outcome_code`) and the notification (`failure_reason_code`) record, borrows the ceiling or window code (`retry_exhausted`/`retry_window_exhausted`), contradicts the attempt's `failure_class`, **or is persisted beside a notification whose status is not terminal `failed`** — a `terminal` class only ever closes the notification as terminal `failed`, so the forged `terminal`-beside-`expired` closure (a matching, well-formed member on both records, but the window's status) is this diagnostic and is never read as window exhaustion, even though all of its vocabulary and equality branches would pass — the verifier applies the vocabulary, equality and notification-status rule to every notification **whose closing attempt carries `failure_class = 'terminal'`**, so a non-terminal exhaustion is judged by its own gate's rule (`retry_exhaustion_invalid` at the ceiling, `retry_window_exhaustion_invalid` by the window) and is never this diagnostic (§7.3) |
| `retry_exhaustion_invalid` | a **ceiling**-exhaustion closure refused or rejected because the closing notification is not terminal `failed` with a `failure_reason_code` of exactly `retry_exhausted` — a NULL, empty, terminal-vocabulary member, the window code `retry_window_exhausted` or any other code — or because the closing attempt is not at the ceiling (`attempt_sequence = retry_max_attempts`), or because the closing attempt borrowed a cross-class code — a non-terminal attempt whose `outcome_code` is a member of the closed §6.6 terminal-reason vocabulary or the ceiling code `retry_exhausted`, or a closing attempt reclassified to `failure_class = terminal` while the notification carries the ceiling code. It is the mirror image of `terminal_reason_invalid`, **scoped to the ceiling gate** (the gate read first), so every exhaustion closure is judged by exactly one rule: the terminal vocabulary, this ceiling rule, or the window rule below (§6.6, §7.3) |
| `retry_window_exhaustion_invalid` | a **window**-exhaustion closure refused or rejected because the closing notification is not terminal `expired` with a `failure_reason_code` of exactly `retry_window_exhausted` — a NULL, empty, terminal-vocabulary member, the ceiling code `retry_exhausted` or any other code — or because the closure is at the ceiling (which the ceiling rule classifies), or because it derived and announced a schedule the window shape must never have (any of the four retry columns written, a `retry_scheduled` event appended or the outbox row re-armed), or because the closing attempt's non-terminal class and closure code were rewritten. It is the exact mirror of `retry_exhaustion_invalid` one gate later, applied only to a non-terminal closure **with an attempt remaining** (`attempt_sequence < retry_max_attempts`, §6.6/§7.3/§9) — never to a `terminal`-class closing attempt, so an `expired` notification carrying a `terminal` attempt with a matching member is `terminal_reason_invalid` rather than this diagnostic (§6.6, §7.3) |
| `intent_unbound` | a registration or activation refused for the reserved-unbound `GUARANTEE_EXPIRED` intent, which no R2 writer publishes |
| `attempt_lifecycle_invalid` | a persisted attempt history, or one closure's shape, that does not reproduce §6.6's lifecycle: an `attempt_sequence` that is not contiguous from 1 or that exceeds the frozen `retry_max_attempts` (the ceiling is the total acquisition budget, so attempt `max + 1` is unrepresentable and is never read as a further ceiling closure), a state outside the closed vocabulary, an append-only history that is not a contiguous run of legal transitions ending on the persisted state, an open/closed marker that disagrees with that state, a `dispatching` notification holding other than exactly one live attempt, a closed attempt that carries no `failure_class` other than the acknowledged shape — the acknowledgement being itself valid only beside the `dispatched` notification it produced, with a NULL failure code — or a closure class that borrows the acknowledgement member, and a `retry_scheduled` audit row that is missing where a closure persisted its schedule (on either append-only history), present where no schedule was derived, not placed as the closure's own last row or as the contiguous immediate successor of the `queued` row a re-arm appended and restating that `queued → queued` transition, not matched in the order the lifecycle produced the re-arms (`attempt_sequence` against `event_sequence`), or whose `reason_code`/`evidence_reference_digest` disagrees with the closure (§6.6/§7.3/§9) |
| `tier_f_instant_unavailable` | a tier-F registration, activation or observation refused or closed terminally because the bound authoritative instant is not durably recorded on the subject row, including an absent `automatic_charge_at` while the §6.2.4 R2 amendment is unmerged |
| `tier_f_instant_divergence` | a tier-F instant, derived schedule or dispatch evidence that does not reproduce from the persisted subject column |
| `schedule_expiry_missing` | an activation refused because the mandatory §6.3 `expiry` rule is absent, malformed or outside its declared range |
| `schedule_composition_invalid` | an activation or a schedule derivation refused because the §6.3 rule composition is not the closed one (zero or two anchors, a tier-mismatched anchor, a duplicated 0-or-1 rule, an unregistered schedule code, a schedule parameter outside the canonical encoding or its admissible range — including a non-positive defer step, a maximum above `65535` or a deferral product above its bound — a `timezone_basis` outside the three-value vocabulary, a tier-F anchor lead time below one minute, a tier-F eligibility lead time above the anchor's, or a send window with no permitted instant within the bounded search) |
| `schedule_timezone_unresolved` / `schedule_timezone_basis_conflict` | an **observation** closed terminally because the version's shared `timezone_basis` does not resolve through the concrete recipient/subject instance this notification resolved (no instant derived, nothing scheduled, leased or sent) — never a version finding, since a version has no instance to resolve against; or an activation refused because two timezone-sensitive rules of one version name different bases (§6.3) |
| `schedule_derivation_divergence` | a recomputed `schedule_anchor_at`, base instant, `scheduled_for`, `expires_at`, persisted `timezone` or coalesce bucket that disagrees with the persisted derivation result — including a deferred `scheduled_for` that is not the base instant plus the persisted `deferral_count` × the frozen step, an `expires_at` re-anchored on a deferred `scheduled_for`, a `deferral_count` above `max_deferrals` or non-zero without a `deferral` rule, and an outbox mirror that disagrees with the aggregate — or a tier-F `scheduled_for` that is not strictly earlier than its announced subject instant (§6.3(d)/§6.3(f)) |
| `delivery_regression_attempt` / `delivery_event_stale` | retained non-applied delivery facts |
| `envelope_decrypt_failure` | cipher/key failures on the dispatch path |
| `retention_overdue` | envelopes past `contact_expires_at` |

No diagnostic depends on a channel, a provider, a provider status string, or a provider identifier.

## 15. Test matrix

| Suite | Required proof |
| --- | --- |
| `tests/phase-2a2s-contract.php` | Schema 32 identity, build shape, migration/verifier call sites, the eighteen declared tables, the exact added outbox columns/indexes — `notification_id`, `workflow_key`, `workflow_version`, `intent_key`, `audience`, `scheduled_for`, `expires_at`, `deferral_count`, `priority`, `lease_token_digest`, `failure_reason_code` plus the unique `notification_id` key — the two version slot columns and their unique keys, the rule-freeze columns, the new notification schedule columns (`observed_at`, `schedule_anchor_at`, `deferral_count`, `timezone`), the attempt-level retry schedule columns, closed-by-default intent registry containing exactly the eleven R2 intents, the §6.2.1 required-code vocabulary and the authorised audience/recipient pairs, the closed §6.3 schedule composition (one anchor per tier, 0-or-1 optional codes, one `expiry`, one shared `timezone_basis` across the timezone-sensitive codes, the canonical one-row-per-parameter encoding with its declared ranges, and the deferral step/count/product bounds), the closed §9 retry rule-set (one `retry` code with five contiguous parameters in the same canonical encoding, the declared narrow-only intervals per parameter with the class baseline at their upper bounds) and the §6.2.4 tier-F instant source for each of the three tier-F intents, the §6.2.2 one-bound-event-type-per-intent rule (with `AUTOMATIC_RENEWAL_UPCOMING` bound to `opened` alone), the §6.3/§6.5 non-null-with-`scheduled_for` expiry rule and its pre-scheduling exemption, the closed failure vocabulary (`eligibility_rule_set_incomplete`, `eligibility_binding_mismatch`, `audience_not_authorised`, `intent_unbound`, `schedule_expiry_missing`, `schedule_composition_invalid`, `schedule_timezone_unresolved`, `schedule_timezone_basis_conflict`, `schedule_derivation_divergence`, `retry_policy_invalid`, `tier_f_instant_unavailable`, `tier_f_instant_divergence`, `terminal_reason_invalid`, `retry_exhaustion_invalid`, `retry_window_exhaustion_invalid`), capability boundaries, superset status vocabulary, the draft-only/frozen rule-command predicate, no provider column, no FK/CHECK, immutable/append-only discipline, and the absence of any `CommercialPolicyService`, charge-lead-time, pattern-wall-clock or guarantee-fallback reference in the S sources |
| `tests/phase-2a2s-migration-runtime.php` | fresh Schema 32; 31→32 rehearsal; repeat-run safety; partial-capability repair; retained-032 fail-closed; no backfill; every pre-existing `platform_outbox` column/index intact; the added unique `notification_id` key present, a duplicate `notification_id` insert rejected and unlimited NULLs accepted; the added `deferral_count` column present, nullable, with no default and NULL on every pre-existing row; malformed storage rejected |
| `tests/phase-2a2s-outbox-compatibility-runtime.php` | Phase-1 invitation delivery preparation and R2 `publish()` both still work on the extended table; legacy rows insert and coexist under the new unique `notification_id` key; `prepared` rows are never claimed by S; legacy rows keep NULL in every new column (including `deferral_count`), an S-owned row enriched for a `scheduled` notification carries the mirrored `scheduled_for`/`expires_at`/`deferral_count` matching the aggregate's derivation with `available_at` equal to that same `scheduled_for`, a §9 retry closure then moves `available_at` to the persisted `next_available_at` while the mirrored `scheduled_for` still matches the aggregate (a retry-moved `scheduled_for` is rejected as a divergence), and an S-owned pre-scheduling row (`pending`, or a terminal `tier_f_instant_unavailable`/`schedule_timezone_unresolved` observation) keeps the derived triple NULL and is never claimed; `verify_principal_invitation_schema()` still passes |
| `tests/phase-2a2s-runtime.php` | observe each R2 intent; workflow/version registration and activation; draft-only rule attachment; eligibility allow/refuse under the §6.2.1 required set; scheduling from authoritative facts through the §6.3 algorithm incl. local placement, send window and the base-plus-`deferral_count` composition, plus expiry anchored to the base rather than to the deferred instant, every wall-clock step in the version's one shared timezone basis; snapshot freeze; enqueue → lease → hand-off → delivery; suppression at enqueue and at dispatch; cancel/expire; reissue; exactly one outbox row per notification |
| `tests/phase-2a2s-schedule-derivation-runtime.php` | the §6.3 algorithm end to end: composition validation — zero or two anchors, a `lead_time` anchor on tier P, an `immediate` anchor on tier F, a duplicated 0-or-1 rule, an unregistered schedule code, a tier-F anchor below one minute, a schedule parameter outside the canonical encoding or its admissible range (a schedule row carrying a value in `parameter_b`/`parameter_c`/`parameter_d`, a signed or zero-padded value, a `defer_ceiling_minutes` of `0`, a `max_deferrals` above `65535`, a deferral product above its bound, a `weekday_mask` outside `1…127`, a zero-length or reversed `send_window`) and a `timezone_basis` outside the three-value vocabulary are each refused at activation with `schedule_composition_invalid`, and a tier-F eligibility lead time above the anchor's is refused with `eligibility_binding_mismatch`; **one shared timezone basis** — `fixed_local_time` and `send_window` registering different bases are refused at activation with `schedule_timezone_basis_conflict` (no zone is preferred, no cross-zone order is applied), the same pair with one agreed basis activates and both wall-clock steps are then evaluated in the one persisted zone, while an observation whose basis cannot resolve through the concrete recipient/subject instance closes terminally with `schedule_timezone_unresolved`, derives no instant, writes no `scheduled_for`/`expires_at` and schedules, leases or sends nothing — the basis is vocabulary- and equality-validated at activation only and resolved per notification at observation, never against a `named subject` at activation; the frozen order (anchor → local placement → window → base instant → deferral term → expiry → coalesce bucket) reproduces `schedule_anchor_at`, the base instant, `scheduled_for`, `expires_at`, the persisted `timezone` and the bucket exactly across repeated runs, processes and a DST boundary; the window is half-open at its end and inclusive at its start, and a mask with no permitted instant in the bounded search fails closed; **the deferred-row formula** — `deferral_count` increments exactly once per deferral, `scheduled_for` equals the base instant plus the persisted count × the frozen `defer_ceiling_minutes` step (and never `previous scheduled_for + one step`), the count never exceeds `max_deferrals`, no attempt is consumed, and a re-derivation from the persisted base and count reproduces the deferred `scheduled_for` on every replay, with the arithmetic performed in integer seconds so no bounded parameter pair can overflow or leave the `datetime` domain; **the expiry anchor** — `expires_at` follows the step-6 base-anchored formula for both tiers (including the tier-F `min(subject_instant, …)` cap), is unchanged by every accepted deferral, and a row whose `expires_at` was re-anchored on the deferred `scheduled_for` raises `schedule_derivation_divergence` rather than validating; a version registering no `expiry` rule is refused with `schedule_expiry_missing`; a deferral crossing the frozen window closes `expired`/`retry_window_exhausted` and a tier-F deferral breaching the strict-before postcondition closes `expired`/`eligibility_expired`; a `pending` row (and a terminal pre-scheduling row) legitimately carries `scheduled_for`/`expires_at` both NULL and the verifier/aggregate validator accepts it while rejecting the one-sided states; and a corrupted `schedule_anchor_at`/base instant/`scheduled_for`/`expires_at`/`deferral_count`/`timezone` or bucket raises `schedule_derivation_divergence` and refuses to schedule, defer or dispatch |
| `tests/phase-2a2s-eligibility-matrix-runtime.php` | the negative matrix for the complete required eligibility set: for each of the eleven intents and its authorised audience, activation fails closed with `eligibility_rule_set_incomplete` when any single mandatory code of §6.2.1 is omitted (one case per baseline code B1–B6 and per tier-F code), with `eligibility_binding_mismatch` for a misbound aggregate, an empty allowlist or an out-of-vocabulary state, and with `audience_not_authorised` for the reserved `guardian`/`academy` pairs; a duplicated mandatory rule is refused; and a post-activation attempt to add or remove a mandatory rule cannot take effect (frozen digest, no dispatch) |
| `tests/phase-2a2s-binding-matrix-runtime.php` | the §6.2.2/§6.2.3 authoritative-fact binding: each intent's `subject_state_is` binding is exactly its matrix row (aggregate, single bound event type and `to_state` set), a widened, narrowed or rebound allowlist is refused with `eligibility_binding_mismatch` at both registration and activation, a bound event type that cannot record its claimed transition is refused, a second-site binding — `AUTOMATIC_RENEWAL_UPCOMING` bound to `payment_required` rather than `opened`, or the intent published again from a `payment_required` transition — is refused rather than resolved by choosing a candidate, an absent or forged bound fact fails closed with `ineligible_subject_state`, and the reserved-unbound `GUARANTEE_EXPIRED` is refused with `intent_unbound` |
| `tests/phase-2a2s-retry-runtime.php` | retryable vs terminal classes; deterministic keyed jitter — identical immutable inputs reproduce the identical `applied_jitter_bp`, `backoff_seconds` and `next_available` across repeated runs, processes and reordered attempt history; `retry_jitter_bp = 0` disables jitter; jitter is additive only (never earlier than the base back-off); back-off clamping to `retry_max_backoff_seconds` and to a non-null `expires_at`; replay and lease-expiry recovery converge on the persisted values; attempt sequencing; no duplicate notification and no duplicate attempt; the final-attempt boundary — a `retryable`, `defer` or `expired` closure at `attempt_sequence = retry_max_attempts` closes the notification terminally as `failed`/`retry_exhausted` with no re-arm, no persisted schedule and no attempt `max + 1`, the closing attempt keeping its own non-terminal `failure_class` and closure code (`retryable`, `deferred`, or `expired`/`lease_expired`/`retryable`, never `terminal`); **the both-gates-failed boundary** — the reachable state in which the closing attempt is at `attempt_sequence = retry_max_attempts` **and** its §9 clamp also leaves no usable window has exactly one deterministic outcome because the ceiling gate is read first: the notification closes `failed`/`retry_exhausted` (never `expired`/`retry_window_exhausted`), none of the four retry columns is written, no `retry_scheduled` event is appended, nothing is re-armed and no attempt `max + 1` exists, the same row replayed through `verify_notification_communications_schema()` **verifies clean**, and its control twin — the identical row whose notification reports `retry_window_exhausted` — is refused whole with `retry_exhaustion_invalid`; **the exhausted-retry verifier pass** — the persisted normal exhausted retry is presented to `verify_notification_communications_schema()` through every non-terminal path (a port-reported `retryable` closure, an attempt-level `defer` closure and a lease-expiry recovery) and **passes** integrity verification, because the closed terminal-reason vocabulary and its attempt/notification equality rule are scoped to a notification whose closing attempt carries `failure_class = 'terminal'`, while its paired negative controls — the same exhausted row with the closing attempt changed to `failure_class = 'terminal'`, and the same row with the notification `failure_reason_code` replaced by a vocabulary member or its closing `outcome_code` replaced by a terminal-vocabulary member or by `retry_exhausted` — are each refused whole (with `retry_exhaustion_invalid` for the non-terminal forgery, §14) and fail the verifier as forged rows; a `terminal` class closes the notification as terminal `failed` with its own declared terminal reason code — a member of the closed §6.6 terminal-reason vocabulary persisted identically on the attempt (`outcome_code`) and the notification (`failure_reason_code`) — at every attempt sequence, including at the ceiling, where it never reports `retry_exhausted`; **terminal-reason coverage** — each of the three vocabulary members round-trips onto both records, while a missing, empty, non-member or provider-specific reason, a code that differs between the attempt and the notification, a `terminal` class borrowing `retry_exhausted`/`retry_window_exhausted`, and a `failure_class`/`outcome_code` contradiction are each refused whole with `terminal_reason_invalid` and rejected by the verifier as a forged row that then fails integrity — with the scope control that a valid `failed`/`retry_exhausted` notification closed by a non-terminal attempt is never reported by that vocabulary rule, and the **forged-`expired` terminal-class closure**: a `terminal`-class attempt carrying a valid vocabulary member on both records and persisted beside an `expired` notification (the window's status), a row that would satisfy every vocabulary and equality branch on its own, is refused whole with `terminal_reason_invalid` by the command guard and rejected by the verifier as a forged row that fails integrity, and is never reported as `retry_window_exhaustion_invalid` (that rule is scoped to a non-terminal closing attempt) nor accepted as a valid window exhaustion; and `retry_max_attempts = 1` admits no retry at all; a retry re-arms by moving `available_at` only, so `platform_outbox.scheduled_for` still equals the aggregate's `scheduled_for` after every retry closure; repeated lease expiry walks attempts `1 … retry_max_attempts` and terminates on the last, with each recovery pass persisting and replaying the deterministic schedule derived from the persisted `lease_expires_at`, a crash between the close and the re-arm converging without double-counting the ceiling, and a clamped instant that leaves no window **with an attempt remaining** closing terminally as `expired`/`retry_window_exhausted`. **Window exhaustion on every non-terminal path:** the below-ceiling clamp failure closes the notification `expired`/`retry_window_exhausted` with none of the four retry columns written, no `retry_scheduled` event and no re-arm on each of the four paths — a port-reported `retryable` closure, an attempt-level `defer` closure, an operator `abandoned` release and a lease-expiry recovery (the lease-expiry case additionally leaving the outbox row unreturned to claimable) — each presented to `verify_notification_communications_schema()` as a **valid** row, while its paired forgeries are refused whole with `retry_window_exhaustion_invalid` (the same row with the notification reason code replaced by `retry_exhausted`, a terminal-vocabulary member or NULL; the same window-exhausted row placed at `attempt_sequence = retry_max_attempts`; and the same row carrying a persisted quadruple, a `retry_scheduled` event or a re-armed outbox row), and the ceiling twin of each row is reported `retry_exhausted` rather than `retry_window_exhausted`, so the two gates never share a reason code. **Both expiry branches:** with a non-null `expires_at` the instant is clamped to the window and a closure whose clamp leaves no window closes `expired`/`retry_window_exhausted`; with `expires_at IS NULL` (a legacy-row fixture S does not own) the expiry term is absent from the minimum, the window check is skipped, `retry_window_exhausted` is not produced, the retry still terminates through the attempt ceiling, and replay/lease-expiry recovery reproduce that same schedule exactly — the two branches never mix within one persisted schedule. Activation coverage also proves that a version registering no `expiry` rule (or `expiry_minutes < 1`) is refused with `schedule_expiry_missing`; **retry-rule encoding and ranges** — a `rule_kind = 'retry'` set with a second `rule_code`, a duplicated, extra, missing or non-contiguous parameter ordinal, a parameter written in `parameter_b`/`parameter_c`/`parameter_d`, a signed, zero-padded or fractional value, a `retry_max_attempts` outside `1…3`, an initial back-off outside `1…120`, a maximum back-off outside `retry_initial_backoff_seconds…3600`, a multiplier outside `10000…30000` and a jitter span outside `0…1000` are each refused at activation with `retry_policy_invalid` — as is, for every parameter, any value above the class baseline (the narrow-only partial order of §9) — while the class baseline and every in-range combination activate; and **recurrence-as-sole-semantics and overflow-safety** — the largest admissible parameter set (the class baseline) still yields a bounded, in-domain `next_available`; the base back-off sequence is reproduced exactly by the bounded recurrence, which is the sole canonical semantics, and the divergence case is pinned at the level of the canonical derivation function so a future implementation cannot drift toward the closed form: with `retry_initial_backoff_seconds = 1`, `retry_backoff_multiplier_bp = 15000` and `retry_max_backoff_seconds = 3600` the derivation function must return `base_backoff(2) = 1` and `base_backoff(3) = 1` (never the closed form's `floor(1 × 1.5²) = 2`), and replay and lease-expiry recovery reproduce that recurrence value |
| `tests/phase-2a2s-tier-f-instant-runtime.php` | the §6.2.4 durable tier-F instant: (a) for each of the three tier-F intents the derived `scheduled_for` is **strictly earlier** than the instant persisted on the subject row and equals the §6.3 derivation from that persisted instant (the persisted instant, the frozen `lead_time_minutes` and the persisted `deferral_count` are the only inputs, so it equals the base instant while `deferral_count = 0` and the base instant plus the count × the frozen step afterwards); the suite asserts the strict relationship `scheduled_for < subject_instant` over the final, post-deferral result and its invariance across repeated runs and accepted deferrals — never equality with the announced instant, and never a send at or after it, which closes `expired`/`eligibility_expired` under the §6.3(d) postcondition; (b) **post-publication drift is inert** — after the fact is published, a new `AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME` policy version, a changed `MANUAL_RENEWAL_SLOT_GUARANTEE_WEEKS` value and a mutated pattern row (timezone, wall time, duration, buffer) plus a moved boundary leave the frozen instant, the derived `schedule_anchor_at`/`scheduled_for`/`expires_at`, the notification identity and the §6.2.3 B2 verdict unchanged, while a control run shows the unamended derivation would have produced a different instant; (c) the collection side agrees — the `automatic_charge` collection intent's `charge_at` equals the instant the advance notice announced even after a policy version, so announcement and charge can never diverge; (d) **fail-closed** — a missing `automatic_charge_at` column (R2 amendment unmerged) or a NULL instant at the bound fact refuses registration/activation with `tier_f_instant_unavailable`, and an observed tier-F intent with a NULL instant creates no dispatchable notification, closes terminally on the same code, schedules/leases/sends nothing, is never left `pending`, and is never repaired by a fallback derivation; (e) a forced divergence between the persisted instant and a recomputed one raises `tier_f_instant_divergence` and refuses to dispatch; (f) **single-site publication** — `AUTOMATIC_RENEWAL_UPCOMING` is observed from the cycle's `opened` fact alone, and a `payment_required` transition publishes no second copy of it |
| `tests/phase-2a2s-late-subject-state-runtime.php` | delayed observation and delayed dispatch across subsequent R2 transitions: for each bound intent, the subject aggregate is driven through its later legal transitions — a cycle `pending → payment_required → collected → term_bound` after an `AUTOMATIC_RENEWAL_UPCOMING` observed from the `opened` fact, a `collection_intent` `failed → recovered`, a `refund_review` `review_required → resolved` — and the intent is then (a) observed late at enqueue and (b) claimed/dispatched while already enqueued; both keep the same verdict, a legal successor state never flips B2 from pass to fail, the `opened` binding stays the single frozen evidence tuple whatever state the cycle has reached, and no notification is re-decided from the aggregate's current row |
| `tests/phase-2a2s-corruption-runtime.php` | frozen digest/envelope/version/state corruption, a rule appended after activation, a mandatory rule removed or rebound after activation, the `expiry` rule removed or rewritten after activation, a schedule rule appended after activation so the composition no longer matches the frozen one, a forged `rule_set_digest`, a persisted retry schedule that disagrees with the deterministic recomputation (including a schedule derived with the wrong expiry branch for the row's `expires_at`), `schedule_anchor_at`/base instant/`scheduled_for` rewritten after the `scheduled` transition, an `expires_at` re-anchored on a deferred `scheduled_for`, a `deferral_count` above `max_deferrals` or non-zero with no `deferral` rule, a persisted `timezone` that is not the frozen resolution of the single shared basis, an outbox mirror disagreeing with the aggregate, a notification/outbox pair whose reciprocal pointer was split — and, separately, cleared on **both** sides at once so neither lookup finds a row — while every mirrored schedule value stays intact, a tier-F instant nulled or rewritten on the subject row after publication, invented vocabulary values, out-of-order delivery facts, forged transitions — all fail closed through the protected read and converge after restoration |
| `tests/phase-2a2s-failure-runtime.php` | injected write boundary at every owning mutation; full rollback; retry convergence; the originating business fact is never rolled back and is never duplicated |
| `tests/phase-2a2s-privacy-runtime.php` | no raw contact/parameter value in any read or diagnostic; cipher round-trip; erasure tombstone; envelope expiry nulling; capability and nonce separation |
| `tests/phase-2a2s-concurrency-runner.sh` | `dispatch_vs_retry`, `lease_expiry_vs_handoff`, `retry_exhaustion_vs_recovery`, `subject_transition_after_enqueue_vs_dispatch`, `policy_change_after_publication_vs_dispatch`, `deferral_vs_claim`, `activation_vs_dispatch`, `competing_activation_same_intent`, `rule_attach_vs_activation`, `suppress_vs_enqueue`, `cancel_vs_dispatch`, `delivery_vs_attempt_close`, `erase_vs_dispatch`, `unrelated_notifications` |
| Adjacent regressions | Phase-1 delivery preparation, `tests/phase-2a0-isolated-delivery-assertion.php`, Phase 2A.2-R1 runtime, Phase 2A.2-R2 runtime, and the fresh-install capability bootstrap re-run green |

The local runtime must execute all of the above. Fresh-install, 31→32 upgrade, outbox-compatibility,
idempotency, the eligibility-matrix negative suite, the §6.2.2 binding suite (including its single-site
publication case), the §6.3 schedule-derivation suite (including its shared-`timezone_basis` conflict
coverage, its canonical-parameter and range refusals, its terminal unresolvable-timezone observation
case, its deferred-row replay of the base-plus-count formula, its pre-scheduling NULL exemption and its
base-anchored expiry cases) and
the delayed-observation/delayed-dispatch suite, the bounded-retry
suite (its ceiling/exhaustion boundary, both expiry branches, its terminal-class terminal-reason-vocabulary
cases including forgery, missing-code and the forged `terminal`-beside-`expired` closure (refused
`terminal_reason_invalid` outright and never read as window exhaustion), its **exhausted-retry verifier
pass** — a normal exhausted
retry closing `failed`/`retry_exhausted` from a non-terminal attempt must **verify clean**, its closing
attempt carrying the NULL retry schedule that both exhaustion shapes require (an exhausted row that
persisted a quadruple, and a re-arming `retryable` closure that persisted none, each fail), while the
forged terminal-class and vocabulary-substituted variants of the same row still fail — including the
lease-expiry route: a lease expiry whose clamp leaves no usable window while an attempt remains closes
the notification `expired`/`retry_window_exhausted` with no quadruple, no `retry_scheduled` event, no
re-arm and the attempt still `expired`/`lease_expired`/`retryable` — **window exhaustion on all four
non-terminal paths** (the port-reported retry, the attempt-level defer, the operator release and the
lease-expiry recovery each close `expired`/`retry_window_exhausted`, verify clean as valid rows, and
reject their paired forgeries with `retry_window_exhaustion_invalid`, while the ceiling twin of the same
row reports `retry_exhausted`) and **the both-gates-failed boundary** (a closing attempt at
`retry_max_attempts` whose clamp also leaves no usable window is ceiling exhaustion —
`failed`/`retry_exhausted`, one deterministic outcome read ceiling-first, never
`expired`/`retry_window_exhausted`) — its narrow-only
encoding/interval refusals, its recurrence-divergence pin and overflow-safety case, and its
`available_at`-only re-arm), and the **ceiling and closure-shape gates the contract states must also be
read as assertions**: an attempt above the frozen ceiling is refused as `attempt_lifecycle_invalid` (the
ceiling shape belongs to the sequence *at* the ceiling alone, so `>=` is not a spelling of `==`), a closed
attempt may carry no `failure_class` only as the acknowledgement — with the acknowledgement member never
borrowed by a closure class and the acknowledged shape itself valid only beside the `dispatched`
notification it produced — and a `retry_scheduled` audit row must be present exactly where a closure
persisted its schedule and absent everywhere else, proved on **both** append-only histories and, on the
notification, per re-arm in the order the lifecycle produced it (the contiguous successor of that re-arm's
`queued` row — its own `event_sequence` exactly one greater than that row's, never a row only adjacent to it
in the ordered read — restating its `queued → queued` transition), and the
corruption suite carries the matching end-to-end cases (an attempt `retry_max_attempts + 1` behind a
three-attempt ceiling walk, two open attempts on one `dispatching` notification, the three class-less
closures, an acknowledged attempt beside a terminal notification, the removed/forged/reused **and
exchanged** retry evidence on either history, the audit row that does not restate its `queued → queued`
transition, the audit row that no longer follows its own `queued` row, and the audit row left adjacent to
its `queued` row only by a skipped `event_sequence`, the injected evidence beside an
exhausted closure, and a re-arm whose quadruple disagrees with the derivation even though both evidence
rows were recomputed) and the
§6.2.4 tier-F durable-instant suite (including
its post-publication policy/schedule-change coverage, its strict-before assertion over the final
post-deferral result and its fail-closed cases) are mandatory acceptance gates.

## 16. Recommended implementation task identity

| Field | Value |
| --- | --- |
| Task ID | `PHASE-2A-2S-CANONICAL-NOTIFICATION-COMMUNICATIONS-AUTHORITY` |
| Branch | `phase-2a2s-canonical-notification-communications-authority` |
| Base (re-land) | `origin/main` @ `2ab0c71f5cc53ca8aa4241db0b2f7d100de65997` (Schema 31 / W candidate). The §6.2.4 R2 amendment — the durable tier-F instant, the persisted-instant read and the single authoritative advance-notice publication site — is carried by this candidate as one coupled change; the historical R2 candidate `559b1736621c9ed32e41dd2b785dd0f040dcb647` is an ancestor of the re-land base |
| Dependency | Phase 2A.2-R2 merged and closed **with the §6.2.4 amendments** (§17.2); PLATFORM-LOCAL-TEST-RUNTIME green |
| Schema | 032 / `032_notification_communications_authority` |
| Build | `phase2a2s-notification-communications-authority-20260924.1` |
| Review posture | single coherent candidate, dual-owner independent review, additive-only descendants |

## 17. Pre-implementation prerequisites

1. Phase 2A.2-R2 must be independently reviewed, merged and closed; S consumes its finalised intent set
   and cannot be authoritative before it.
2. The bounded R2 amendment of §6.2.4 must be independently reviewed and merged: add
   `dzn_renewal_cycles.automatic_charge_at` (nullable, written at most once in the cycle-open transaction),
   publish `AUTOMATIC_RENEWAL_UPCOMING` only when that value is non-null, make
   `CollectionIntentService::open()` read the persisted column instead of re-deriving the instant from the
   current policy, treat both tier-F instant columns as immutable once written, and stop re-publishing
   `AUTOMATIC_RENEWAL_UPCOMING` from `require_payment` in `automatic` mode so the intent has exactly one
   authoritative publish site (`opened`, §6.2.2/§6.2.4(b)(6)). Until it is merged, S refuses every tier-F
   registration/activation with `tier_f_instant_unavailable`; that refusal is not to be softened,
   re-derived or worked around, and §1/§4/§15 evidence must be refreshed against the amended R2 candidate.
3. Resolve the local-runtime concurrency runner and the adjacent Phase-P/Phase-Q fixture-order failures
   so the §15 adjacent regressions can be quoted as green evidence.
4. Close the stale-documentation debt for Schema 25/26 (README, continuity, architecture, module
   boundaries, migration strategy, policy registry, data model, changelog) before the S candidate is
   published, so the seam S extends is described by current documents.
5. Obtain product-owner sign-off on §5 before registering any workflow that depends on a deferred
   decision (channel preference, retention, consent source, template authoring).

Prerequisites 1 and 2 gate S's own base and are hard dependencies: S must not be merged, and no tier-F
workflow may be registered, before the amended R2 is the authoritative base. Prerequisites 3–5 gate
execution evidence and deferred product decisions but do not block this contract text.

## 18. Definition of done

- Schema 32, its verifier and the `platform_outbox` extension are additive and preserve Schema 31 and
  every Phase-1/R2 row.
- The eleven R2 intents are consumed from the existing seam with no duplicate delivery authority and
  no second queue; `GUARANTEE_EXPIRED`, which no R2 writer publishes today, is consumed as a declared
  name only — refused at registration with `intent_unbound` and never delivered.
- Exactly one active workflow version exists per consumed intent, enforced by the `intent_active` routing
  slot under competing activation, and an activated version's rule set is digest-frozen so no
  post-activation append can change eligibility, scheduling or retry behaviour without a new version.
- Every activated version carries the complete required eligibility set of §6.2.1 — the mandatory
  baseline (including `recipient_resolvable`, `recipient_opted_in` and `guardian_authority_present`) plus
  its intent/audience tier — validated at activation, re-derived and re-evaluated on every read, enqueue
  and hand-off path, and covered by an explicit per-code negative matrix.
- Retry scheduling is deterministic and keyed: the immutable notification identity and attempt sequence
  alone decide the jitter and the resulting `next_available`, the version's retry rule is stored in the
  canonical §9 encoding inside its declared narrow-only interval (refused at activation with
  `retry_policy_invalid` otherwise, so a version can only narrow the class baseline coordinatewise and can
  never exceed it), the base back-off is the §9 bounded recurrence — the sole canonical semantics, never
  the closed form — every intermediate value is a bounded
  64-bit integer and every derived instant is checked against the `datetime` domain, the result is
  persisted and audited on the closing attempt whenever the closure re-arms (both exhaustion shapes
  persist none, §7.2/§7.3), and replay and lease-expiry recovery reproduce it exactly.
- Schedule derivation is one frozen, total function (§6.3): the version's composition is closed and
  validated at activation (`schedule_composition_invalid` otherwise) — including the canonical
  one-row-per-parameter encoding, its declared ranges, a strictly positive defer step, a `max_deferrals`
  inside the `smallint unsigned` capacity and a bounded deferral product, all evaluated in overflow-safe
  integer seconds — with one shared `timezone_basis`
  across its timezone-sensitive rules (`schedule_timezone_basis_conflict` otherwise), the basis
  vocabulary- and equality-validated at activation but resolved once per notification at observation
  (an unresolvable basis closes the observation terminally on `schedule_timezone_unresolved`, deriving no
  instant), the anchor, local
  placement, send window, base instant, deferred `scheduled_for`, expiry and coalesce bucket are derived in
  one order from persisted inputs only — the deferred instant is the base plus the persisted
  `deferral_count` × the frozen step, and `expires_at` is anchored to the base so a deferral never
  re-anchors the window — the tier-F result is strictly earlier than the announced instant (§6.3(d)) with
  equality treated as failure, and every later re-derivation reproduces the persisted `schedule_anchor_at`,
  base instant, `scheduled_for`, `expires_at`, `timezone` and coalesce bucket exactly
  (`schedule_derivation_divergence` otherwise), including for a legitimately deferred row.
- Expiry is mandatory rather than incidental: every activated version registers the §6.3 `expiry` rule
  (refused otherwise with `schedule_expiry_missing`), every S-owned notification that has reached
  `scheduled` carries a non-null `expires_at` while a row that has not carries `scheduled_for` and
  `expires_at` NULL together (the verifier's non-null rule is keyed on `scheduled_for IS NOT NULL`, so a
  `pending` or dead pre-scheduling row is exempt and a one-sided state is rejected), and the single
  explicit NULL rule — omit the expiry clamp term and skip every
  expiry-window check — is implemented identically in schedule derivation, persistence, replay,
  lease-expiry recovery, verifier checks and tests, so no retry or window decision can rest on an
  undefined comparison.
- The retry ceiling is enforced end to end: attempts are acquisition-counted, every re-arm path (the
  normal retry closure, the attempt-level `defer` closure and lease-expiry recovery) re-arms only while
  an attempt remains **and** the §9 clamp leaves a usable window. The two gates have one declared
  precedence — the ceiling is read **first**, the window second — so the reachable state in which both
  fail (a closing attempt at `retry_max_attempts` whose clamp also leaves no usable window) is
  unambiguously ceiling exhaustion and never ambiguous: a non-terminal closure at the ceiling closes the
  notification terminally as `failed`/`retry_exhausted` while the closing attempt keeps its own
  non-terminal `failure_class` and closure code (a lease expiry stays `expired`/`lease_expired`/
  `retryable`; an operator `abandoned` release stays `failure_class = retryable`; neither is ever
  reclassified as `terminal`), and with an attempt remaining a clamp that leaves none is window
  exhaustion on every path, which closes the notification `expired`/`retry_window_exhausted`, derives and
  persists no schedule, appends no `retry_scheduled` event and re-arms nothing; every re-arm moves the
  outbox row's `available_at` alone (the mirrored
  `scheduled_for` is never rewritten by a retry). Both exhaustion shapes verify clean — the ceiling shape
  as `failed`/`retry_exhausted` and the window shape as `expired`/`retry_window_exhausted` — because the verifier's
  persisted-retry-schedule requirement is scoped to the closures that actually re-arm: both exhaustion
  shapes derive no schedule and are accepted, and required, in the NULL shape (`applied_jitter_bp`,
  `base_backoff_seconds`, `backoff_seconds` and `next_available_at` all NULL, §7.2/§7.3); and
  the verifier's terminal-reason vocabulary, equality **and notification-status** rule is applied to every
  notification whose closing attempt carries `failure_class = 'terminal'`, while a `terminal` class closes
  the notification as terminal `failed` with its own
  declared terminal reason code — a member of the closed §6.6 vocabulary, persisted identically as the
  attempt's `outcome_code` and the notification's `failure_reason_code` — at every attempt sequence, and a
  `terminal`-class attempt persisted beside any other notification status (the forged `terminal` closure
  on an `expired` notification, whatever member the pair carries) is refused and rejected with
  `terminal_reason_invalid` rather than accepted on a matching reason code — so no
  notification can reach attempt
  `retry_max_attempts + 1`, no terminal cause is lost, and lease expiry neither bypasses the ceiling nor
  discards the deterministic schedule. The ceiling and the live lease are verifier-enforced rather than
  asserted: a persisted `attempt_sequence` above the frozen ceiling is refused whole, exactly one attempt
  may be open (and only beside a `dispatching` notification), a closed attempt carries no `failure_class` in
  any shape but the acknowledged hand-off — whose member no closure class may borrow — and the digest-only
  `retry_scheduled` evidence is required on **both** append-only histories exactly where a closure persisted
  its schedule, and nowhere else.
- Every activated version's `subject_state_is` is the closed §6.2.2 binding evaluated over immutable
  append-only subject evidence, with an allowlist derived from the matrix rather than authored, so a
  delayed observation or a delayed dispatch across later legal R2 transitions keeps the same verdict and
  only a missing bound fact fails.
- Each consumed intent binds exactly one authoritative event type, so the frozen B2 evidence tuple is
  unique per notification: `AUTOMATIC_RENEWAL_UPCOMING` is bound to the cycle's `opened` fact alone, R2
  publishes it from that single site (§6.2.4(b)(6)) — an amendment that adds no durable row and changes no
  replay result — and a second-site binding or a re-widened publication site is refused rather than
  resolved by choosing a candidate event at dispatch.
- Every tier-F intent's announced instant is a persisted, immutable subject fact read exactly as R2
  committed it (§6.2.4): the automatic charge instant is recorded before `AUTOMATIC_RENEWAL_UPCOMING` is
  published and is never recomputed from the current commercial policy, the guarantee deadline is never
  re-derived from current pattern/schedule state, a post-publication policy or schedule change cannot move
  the instant or the derived send instant, the derived send instant is strictly earlier than the announced
  instant, and an unavailable instant is refused (`tier_f_instant_unavailable`) rather than defaulted,
  waited on or approximated.
- A notification has exactly one outbox row, enforced by unique keys on both sides of that relationship and
  required by the shared aggregate verification for every notification (an aggregate whose mirror row was
  not supplied, or whose pair was cleared on both sides, is refused `schedule_derivation_divergence`).
- Workflow/versioning, eligibility, scheduling, idempotency/retry, rendered-template snapshots, the
  attempt/delivery lifecycle, channel-independent diagnostics, and privacy/security behave exactly as
  specified in §5–§14.
- All §15 suites pass on the disposable runtime from a fresh clone.
- No product decision is silently invented; every deferred decision sits behind the §5 safe defaults.
- No provider call, credential, external communication, Amelia change, Theme change, merge or
  deployment occurred.

## 19. Correction record

Implementation correction round 11 (host review `CORRECTION ROUND 3`; failed candidate `6673e7b`, tree
`1b2cad8a`) — the independent review refused the candidate with one blocking finding: `seconds()` accepted
a parsed value the stored `datetime` column can never hold (a valid calendar date such as
`0999-12-31 23:59:59`, below the declared `DATETIME_MIN`), so `derive()` could accept an out-of-domain
`observed_at` and derive and persist it as `schedule_anchor_at`/`scheduled_for`, and `addSeconds()`
accepted an out-of-domain base whenever the resulting timestamp happened to re-enter the domain. This
entry records the **product-code** correction only: no migration identity, table count, migration name,
build identity or contract rule change is involved — the correction makes the runtime match the stored
`datetime` domain §6.3 already declares — no schema object is added, and no merge, deploy, provider
activation, external send, Amelia or Theme change is involved. The host ledger numbers this review
`CORRECTION ROUND 3` of the current implementation attempt; this document's record sequence continues at
11.

| Blocking finding | Fix applied | Sections |
| --- | --- | --- |
| `NotificationSupport::seconds()` validated the shape and the calendar but never the declared domain, so it accepted a storable-looking value outside `1000-01-01`…`9999-12-31` (`0999-12-31 23:59:59`); consequently `NotificationSchedule::derive()` accepted an out-of-domain `observed_at` and persisted it as `schedule_anchor_at`/`scheduled_for`, violating §6.3's requirement that every derived instant be inside the stored `datetime` domain, and `addSeconds()` accepted an out-of-domain base whenever its 64-bit sum happened to land back inside the domain, laundering an unusable instant into a usable one. | The parse side is now bound by the same declared domain as the format side: `seconds()` refuses a value below `DATETIME_MIN` or above `DATETIME_MAX` before it constructs anything (the declared `YYYY-MM-DD HH:MM:SS` shape fixes a four-digit zero-padded year, so the domain comparison is exact on the canonical text), so every caller inherits one refusal — the evidence envelope of §8.1 (`notification_evidence_at_invalid`), `derive()`'s observation instant and newly-guarded non-null subject instant (`schedule_derivation_divergence`), `dayDifference()`, `coalesceBucket()`, `utcToLocal()` and `addSeconds()`'s own base — and `addSeconds()` therefore refuses an out-of-domain base even when the sum would re-enter the domain. `defer()` and `NotificationEligibility::evaluate()` additionally parse both sides of their instant comparisons explicitly, so a refused instant can never be compared as a zero second count, and a derived result that leaves the domain still fails closed exactly as before. Coverage: `tests/phase-2a2s-contract.php` §16 asserts the new `seconds()` domain rule, the subject-instant domain guard and every new runtime case label; `tests/phase-2a2s-schedule-derivation-runtime.php` §10 probes `seconds()`/`addSeconds()`/`dayDifference()`/`coalesceBucket()`/`utcToLocal()`/`evidence()` with `0999-12-31 23:59:59` beside the accepted declared boundaries, refuses an out-of-domain observation instant, an out-of-domain subject instant, an `addSeconds()` base and a derivation whose expiry leaves the domain, and proves the production observation path raises `schedule_derivation_divergence` while persisting no notification and leaving the intent row unclaimed. | §6.3, §6.3(f), §8.1, §9, §15 |

Implementation correction round 10 (host review `CORRECTION ROUND 2`; failed candidate `63f6b5b`, tree
`abd60da`) — the independent review refused the candidate with one blocking finding: `instant()` refused
every negative Unix second count (`$seconds < 0`), although §6.3 declares every derived instant over the
stored `datetime` domain (`1000-01-01 00:00:00` … `9999-12-31 23:59:59` UTC), which begins below the epoch, so
a valid persisted pre-epoch date failed schedule derivation as `schedule_derivation_divergence`. This entry
records the **product-code** correction only: no migration identity, table count, migration name, build
identity or contract rule change is involved — the correction makes the runtime match the datetime domain
§6.3 already declares — no schema object is added, and no merge, deploy, provider activation, external send,
Amelia or Theme change is involved. The host ledger numbers this review `CORRECTION ROUND 2` of the current
implementation attempt; this document's record sequence continues at 10.

| Blocking finding | Fix applied | Sections |
| --- | --- | --- |
| `NotificationSupport::instant()` refused every negative Unix second count (`if($seconds<0)return null;`) before it formatted the instant, so a persisted date between `1000-01-01` and `1969-12-31` — inside the domain §6.3 declares and the schema stores — could not be derived: `addSeconds()` returned `null` for it and every schedule path raised `schedule_derivation_divergence`, so the declared and the implemented domain disagreed across the whole pre-epoch range. | The refusal is now the declared domain alone: `instant()` formats the 64-bit integer second count and refuses only a result below `DATETIME_MIN` or above `DATETIME_MAX` (with the declared `YYYY-MM-DD HH:MM:SS` shape required), so `-30610224000` resolves to `1000-01-01 00:00:00` and `-600` to `1969-12-31 23:50:00`, while `0999-12-31 23:59:59` and a year above `9999` are refused; `addSeconds()` applies the same rule to its own 64-bit integer-second sum. The step-7 coalesce bucket was aligned with its own declared formula at the same time, because opening the domain below 1970 makes a pre-epoch anchor reachable: `coalesceBucket()` floors a negative anchor (`intdiv()` truncates toward zero), so `1969-12-31 23:50:00` lands in bucket `-1` where step 7's `floor(anchor_at / (coalesce_window_minutes × 60))` names it — the bucket the §9 identity and the verifier's re-derivation consume — while every anchor at or after the epoch keeps exactly the value it had. Coverage: `tests/phase-2a2s-contract.php` §16 asserts the domain constants, the absence of the negative refusal, the domain-only refusal and the floor bucket; `tests/phase-2a2s-schedule-derivation-runtime.php` §9 derives a tier-F composition whose frozen lead time places the anchor at `1969-12-31 21:30:00` (that anchor, its own `1969-12-31 23:00:00` tier-F expiry and the `-3` floor bucket) beside direct pre-epoch helper probes, the unchanged post-epoch bucket and the boundary probes one second below `1000-01-01` and one second above `9999-12-31`. | §6.3, §6.3(f), §9, §15 |

Implementation correction round 9 (host review `CORRECTION ROUND 7`; failed candidate `465a708`, tree
`766ef7b4`) — the independent review refused the candidate with one blocking finding: the notification-side
retry-audit proof required only that the `retry_scheduled` row's predecessor **in the ordered result set**
was the `queued` row, never that the pair's own `event_sequence` values were contiguous. This entry records
the **product-code** correction only: no migration identity, table count, migration name, build identity or
contract rule change is involved — the correction makes the persisted runtime match the retry-audit
placement §7.3 and §9 already state (the `retry_scheduled` row §6.6 appends in the re-arm's own transaction,
whose contiguous successor §14's `attempt_lifecycle_invalid` diagnostic already names) — no schema object is
added, and no merge, deploy, provider activation, external send, Amelia or Theme change is involved. The
host ledger numbers this review correction round 7 of the current implementation attempt; the identical
blocking finding was also returned as `CORRECTION ROUND 6` against this same failed candidate, with no
candidate produced in between, and this document's record sequence continues at 9.

| Blocking finding | Fix applied | Sections |
| --- | --- | --- |
| `NotificationIntegrity::retryEvidenceIntegrity()` proved only that the preceding row of the `event_sequence`-ordered result set was `queued` (`event_type = queued`, `to_state = queued`) and that the audit row itself restated `queued → queued`; it never required `retry_scheduled.event_sequence === queued.event_sequence + 1`. A forged re-arm pair whose sequences skip one — `queued` at `Q`, `retry_scheduled` at `Q + 2`, with no row between them — leaves the two adjacent in the ordered read and passed every protected read and the schema verifier, although §9 requires the audit row to be the **contiguous** immediate successor of the `queued` row its own re-arm appended, so adjacency in a result set is not the required placement. | The per-re-arm match now reads the pair's own sequence numbers: `retryEvidenceIntegrity()` refuses the audit row unless `(int) $event->event_sequence === (int) $previous->event_sequence + 1` in addition to the existing predecessor, state and reason/digest proofs, so a pair left adjacent only by a skipped `event_sequence` is refused whole with `attempt_lifecycle_invalid` on every protected read, the attempt read seam, the dispatch claim and `verify_notification_communications_schema()`. The rule is unchanged from §7.3/§9 — only the proof now measures the placement numerically instead of inferring it from result-set order. Coverage: `tests/phase-2a2s-corruption-runtime.php` §9(h) renumbers the intact ceiling walk's first re-arm audit row and every later notification-history row one higher (so the forged pair sits at `Q` and `Q + 2` while remaining adjacent in the ordered read), asserts that gap, proves the discarded read, the attempt read seam and the schema verifier each fail closed with `attempt_lifecycle_invalid`, then restores every sequence and asserts the walk reads clean again; `tests/phase-2a2s-contract.php` §15 asserts the numeric-contiguity source rule and both new runtime case labels. | §7.3, §9, §14, §15 |

Verification available here: `tests/phase-2a2s-contract.php` §15 asserts the new source rule and the new
runtime case labels. This correction environment provides no PHP or WordPress runtime, so the runtime
suites are updated and reviewed by source but were **not executed here**; no migration was re-run and no
schema object, identity or build changed.

Implementation correction round 8 (host review `CORRECTION ROUND 5`; failed candidate `52ebb18`, tree
`e821b302`) — the independent review refused the candidate with two blocking findings in the shared
integrity proofs. This is the same review the host ledger numbers correction round 5 of the current
implementation attempt; this document's record sequence continues at 8. This entry records the
**product-code** correction only: no migration identity, table count, migration name, build identity or
contract rule change is involved — every correction makes the persisted runtime match the rules §6.6,
§7.3 and §9 already state — no schema object is added, and no merge, deploy, provider activation, external
send, Amelia or Theme change is involved.

| Blocking finding | Fix applied | Sections |
| --- | --- | --- |
| The acknowledgement branch of `closureIntegrity()` accepted a class-less `acknowledged` attempt on its own shape alone, so a forged attempt with `state`/`outcome_code = acknowledged`, no `failure_class` and no retry columns passed even beside a `failed`, `expired`, `suppressed` or `cancelled` notification — the outbox mirror does not constrain the aggregate's status, and the attempt carries no class for any closure partition to judge — although §6.6 defines the acknowledgement by what it produced: the port accepted the hand-off, so the notification it closes is `dispatched`. | The acknowledged shape is now proved against the aggregate it produced and not only against its own row: the null-class branch of `closureIntegrity()` refuses unless the notification's `state` is exactly `dispatched` **and** its `failure_reason_code` is NULL, so an acknowledged-looking attempt persisted beside a terminal (or otherwise non-`dispatched`) status — or a `dispatched` row that still carries a failure code — is refused whole with `attempt_lifecycle_invalid` instead of being read as a successful hand-off, and every protected read, the dispatch claim, the attempt read seam and `verify_notification_communications_schema()` refuse it together. Coverage: `tests/phase-2a2s-retry-runtime.php` §6 refuses the acknowledged attempt beside a `failed`, an `expired` and a failure-coded `dispatched` notification; `tests/phase-2a2s-corruption-runtime.php` §9(b) proves the forged pair end-to-end — a real acknowledgement whose notification is rewritten to `failed`/`retry_exhausted` (and, separately, `expired`/`retry_window_exhausted` and a `dispatched` row carrying a failure code) — fails the protected read **and** the schema verifier, converging once the `dispatched` status is restored; `tests/phase-2a2s-contract.php` §15 asserts the source rule and the case labels. | §6.6, §7.2, §7.3, §9, §14, §15 |
| `NotificationIntegrity::retryEvidenceIntegrity()` validated the notification-side `retry_scheduled` evidence as an unordered **set**: for two retryable re-arms it accepted any assignment of the two expected digests to the two rows, so exchanging their distinct digests passed (both were expected, both preceding `queued` rows carried the same reason, and the set cardinality was unchanged), and the method never checked the audit row's own `from_state`/`to_state` at all — although §9 requires each row to follow and restate the `queued` transition of its own re-arm. | The notification's audit rows are now proved **per re-arm, in the order the lifecycle produced them**: the expected evidence is one entry per re-arming closure, ordered by the re-arming attempt's `attempt_sequence`, and the `retry_scheduled` rows are consumed in `event_sequence` order, so the *n*-th audit row must carry exactly the *n*-th re-arm's digest-only evidence and its own outcome code — a missing row, a stray row beyond the expected count and an exchanged pair are each refused — and each row must be the contiguous immediate successor of its own `queued` row while itself restating `queued → queued`. Coverage: `tests/phase-2a2s-corruption-runtime.php` §9(g) exchanges the two re-arms' distinct digests (refused) and, in turn, rewrites the audit row's `from_state`, its `to_state` and its preceding `queued` row's `to_state` (each refused), converging once restored; `tests/phase-2a2s-contract.php` §15 asserts the ordered rule and every new runtime case label. | §6.6, §7.3, §9, §14, §15 |

Verification available here: `tests/phase-2a2s-contract.php` §15 asserts every new source rule and every new
runtime case label. This correction environment provides no PHP or WordPress runtime, so the runtime suites
are updated and reviewed by source but were **not executed here**; no migration was re-run and no schema
object, identity or build changed.

Implementation correction round 7 (host review `CORRECTION ROUND 4`; failed candidate `8c83d2f`, tree
`f2a0dee5`) — the independent review refused the candidate with three blocking findings in the shared
integrity proofs. This entry records the **product-code** correction only: no migration identity, table
count, migration name, build identity or contract rule changes, no schema object is added, and no merge,
deploy, provider activation, external send, Amelia or Theme change is involved. Every correction makes the
persisted runtime match the rules §6.6, §7.2, §7.3, §9, §14, §15 and §18 already state.

| Blocking finding | Fix applied | Sections |
| --- | --- | --- |
| The attempt ceiling and the “exactly one open attempt” invariant were not enforced: `attemptHistoryIntegrity()` checked only contiguous sequences, `closureIntegrity()` accepted every `attempt_sequence >= retry_max_attempts` as a valid ceiling closure, and no proof counted open attempts — so a forged, otherwise-valid attempt 4 under a max-3 policy, and two open attempts on one `dispatching` notification, passed every protected read and the schema verifier, violating the total-attempt ceiling and the single-open-attempt requirement. | `NotificationIntegrity::attemptHistoryIntegrity()` now validates the sequence against the frozen policy (`NotificationRetry::maxAttempts()`) — a persisted attempt above the ceiling is refused whole with `attempt_lifecycle_invalid`, never read as a further ceiling closure — and counts the live leases: exactly one attempt may be open, and only while the aggregate is `dispatching`. `closureIntegrity()` reads the same frozen ceiling and refuses an above-ceiling row itself (the ceiling shape belongs to the sequence **at** the ceiling alone, so `>=` is not a spelling of `==`), and the shared aggregate verification runs the proof in every protected read, the dispatch claim, the attempt read seam and `verify_notification_communications_schema()`. Coverage: `tests/phase-2a2s-corruption-runtime.php` §8 walks one legitimate three-attempt ceiling, appends a forged `retry_max_attempts + 1` attempt carrying the ceiling attempt's own legal event chain, and proves the aggregate read, the attempt read seam and the schema verifier each fail closed and converge once the row is removed — and does the same for a second open attempt beside a live lease; `tests/phase-2a2s-retry-runtime.php` §6 asserts the above-ceiling refusal beside the passing ceiling shape; `tests/phase-2a2s-concurrency-verify.php` passes each raced notification's own frozen policy and proves both histories. | §6.6, §7.2, §7.3, §9, §14, §15, §18 |
| `closureIntegrity()` accepted **any** closed attempt with `failure_class = NULL`. Only the acknowledgement is legitimate (it writes a null class), so a forged `failed`, `expired` or `abandoned` attempt with a legal event chain bypassed every closure-partition check, violating the normalised closure outcome/class invariant. | A null class is now allowed only for the precise acknowledged shape — a closed `acknowledged` attempt carrying the shared acknowledgement outcome code (`NotificationRule::ACKNOWLEDGED_OUTCOME`, now written by the acknowledgement path too) and no persisted retry schedule — and every other class-less closed attempt is refused with `attempt_lifecycle_invalid` instead of being read as an acknowledgement or left unjudged. The member is unique to that shape, so no `retryable`, `defer`, `terminal`, abort or cancellation closure may borrow it. Coverage: `tests/phase-2a2s-retry-runtime.php` §6 round-trips the legitimate acknowledgement and refuses the three forged class-less states, and `tests/phase-2a2s-corruption-runtime.php` §9(a) proves each of them end-to-end — a real exhausted closure whose chain is rewritten into the `failed`, `expired` and `abandoned` shapes — through the protected read and the schema verifier, converging on restoration, while §9(b) proves the legitimate acknowledgement still reads clean. | §6.6, §7.2, §7.3, §9, §14, §15 |
| The required `retry_scheduled` audit evidence was not proved: the lifecycle check merely permitted a self-transition row, so a re-armed attempt with the correct retry quadruple but no event passed, and an exhausted closure with an injected self-transition event passed too — while §9 requires the re-arm to be recorded on **both** append-only histories, atomically with the persisted schedule, and nowhere else. | Event cardinality, placement, reason, evidence digest and presence/absence are now proved against the closure's own derived re-arm result. The attempt-side row must be the closing attempt's own last history row, its `reason_code` must be that closure's outcome code, and its `evidence_reference_digest` must be exactly the digest-only retry evidence of the persisted quadruple. The notification's own history is proved by the new `NotificationIntegrity::retryEvidenceIntegrity()`: exactly one `retry_scheduled` row per re-arming attempt, directly after the `queued` row the same re-arm appended, restating the state that re-arm produced and carrying the identical evidence — and none where no schedule was derived. `NotificationDispatchService` now writes that row on **both** re-arm paths (the port-reported/defer closure and the lease-expiry recovery) from one shared `retryEvidence()` derivation, inside the same transaction as the schedule, the transition and the outbox re-arm, and `retryScheduleIntegrity()` reads the same §9 derivation in both directions, so a closure the derivation re-arms must carry the quadruple that reproduces it and a closure it exhausts must carry none. Coverage: `tests/phase-2a2s-corruption-runtime.php` §9(c)–(f) removes, forges and reuses the evidence on either history, injects a row beside the exhausted closure and refuses a quadruple that disagrees with the derivation even when both evidence rows were recomputed to match it (`retry_schedule_divergence`); `tests/phase-2a2s-concurrency-verify.php` proves the notification-side evidence for every raced notification. | §6.6, §7.2, §7.3, §9, §14, §15, §18 |

Implementation correction round 6 (failed candidate `9fef9b9`, tree `301e359d`) — the independent review
refused the candidate with one blocking finding: the reciprocal pair was still accepted when corruption
cleared **both** pointers at once. This entry records the **product-code** correction only: no migration
identity, table count, migration name, build identity or contract rule changes, no schema object is added,
and no merge, deploy, provider activation, external send, Amelia or Theme change is involved.

| Blocking finding | Fix applied | Sections |
| --- | --- | --- |
| The review's required correction states that the shared aggregate verifier must require a mirror row for **every** `notifications` record, validate both reciprocal IDs, and add an anti-join/row-level migration check plus runtime coverage for clearing both pointers and restoring them. `aggregateIntegrity()` refused an absent mirror only when `notifications.outbox_id` was non-null, and `Migrator::verify_notification_authority_data()`'s row loop joined the notification through `o.notification_id` alone, so a pair whose *both* pointers were cleared — leaving nothing for either lookup to find — was read as an aggregate with no mirror to compare and passed protected reads and schema verification, violating §6.5's exact 1:1 relationship and the fail-closed authority invariant. | The mirror requirement is now unconditional in the one shared rule: `NotificationIntegrity::aggregateIntegrity()` refuses **any** notification handed over without its persisted mirror row (`schedule_derivation_divergence`) before the mirror proof — never only a mirror-backed one — so the aggregate read, the attempt read seam, the dispatch claim and schema verification all refuse the both-pointers-cleared pair. `Migrator::verify_notification_authority_data()` gained the row-level anti-join `notifications LEFT JOIN platform_outbox ON o.notification_id = n.id WHERE o.id IS NULL`, which refuses (`notification without an outbox mirror: schedule_derivation_divergence`) the row the outbox loop can no longer see, in addition to the reciprocal `n.outbox_id`/`o.id` comparison and the shared-call hand-over it already performed. `tests/phase-2a2s-contract.php` §14 asserts the unconditional requirement and the anti-join; `tests/phase-2a2s-corruption-runtime.php` §7(d) clears both pointers on a mirrored, leased pair, proves the aggregate read, the attempt read seam (both projections) and the schema verifier each fail closed while the mirrored schedule values stay untouched, and proves convergence once both pointers are restored. | §6.5, §7.1, §7.3, §8.4, §15, §18 |

Implementation correction round 5 (failed candidate `aefe39a`, tree `0a519a7d`) — the independent review
refused the candidate with one blocking finding: the new mirror proof fetched the outbox row by
`notification_id` but compared only the schedule fields, so a corrupted notification could point at a
different valid/legacy outbox row while another row referenced the notification and mirrored its schedule,
which the §6.5 1:1 invariant forbids outright. This entry records the **product-code** correction only: no
migration identity, table count, migration name, build identity or contract rule changes, no schema object
is added, and no merge, deploy, provider activation, external send, Amelia or Theme change is involved.

| Blocking finding | Fix applied | Sections |
| --- | --- | --- |
| The review's required correction demands that the shared outbox verification reject unless both reciprocal identifiers match, "including rejecting a null/mismatched aggregate pointer when a mirror is supplied", and that the migration row-level check compare `n.outbox_id` with `o.id`. `NotificationIntegrity::outboxMirror()` proved the mirrored triple and the `available_at` contract but never the identity of the pair, and `Migrator::verify_notification_authority_data()`'s row loop joined the notification only through `o.notification_id`, so a notification whose `outbox_id` was NULL — or named another valid/legacy row — passed both the protected reads and schema verification. | The pair's identity is now part of the shared outbox rule, checked before any schedule field: `outboxMirror()` refuses (`schedule_derivation_divergence`) a row that does not name the notification, and a notification whose `outbox_id` is NULL or names a different row, so the one rule every protected read, dispatch claim, attempt read seam and schema verifier runs can never accept a split pointer. `Migrator::verify_notification_authority_data()` additionally selects `n.outbox_id AS n_outbox` and refuses any mirrored row whose notification does not point back at it (`notification outbox pointer divergence`). `tests/phase-2a2s-contract.php` §14 asserts both reciprocal checks and the row-by-row comparison; `tests/phase-2a2s-corruption-runtime.php` §7 mutates either pointer while the mirrored schedule values stay intact and proves the aggregate read, the attempt read seam and the schema verifier each fail closed and converge once the pointer is restored. The corruption suite also gains the `NotificationRule` import its §5 assertion referenced without one. | §6.5, §7.1, §7.3, §8.4, §9, §15 |

Targeted correction round 4 (preserved candidate `3f67d3d`, tree `5d53556`) — a targeted correction on top
of the preserved candidate that closes the one residual shortfall in the review's required corrections. This
entry records the **product-code** correction only: no migration identity, table count, migration name,
build identity or contract rule changes, no schema object is added, and no merge, deploy, provider
activation, external send, Amelia or Theme change is involved.

| Blocking finding | Fix applied | Sections |
| --- | --- | --- |
| The first review's fourth finding requires the shared aggregate verification to validate the persisted schedule, the closure/retry integrity **and the outbox mirrors** before reads, dispatch and schema verification proceed. The dispatcher's claim and `NotificationReadService` did run the shared verification with the persisted mirror, but `NotificationAttemptReadService` invoked it with no mirror at all — so the attempt read seam returned authority without ever validating the mirrored row — and `Migrator::verify_notification_authority_data()` invoked it with no mirror as well, keeping the mirror proof outside the one shared rule. | The mirror is now part of the shared proof rather than an optional extra. `NotificationIntegrity::aggregateIntegrity()` refuses a mirror-backed notification whose persisted row was not handed over (`schedule_derivation_divergence`), so a caller can no longer skip the check by passing nothing; `NotificationAttemptReadService` injects `NotificationOutboxRepository`, resolves the notification's persisted row and hands it to the same shared verification as the aggregate seam; and `Migrator::verify_notification_authority_data()` reads each notification's persisted mirror row and hands it to the same shared call in addition to its row-by-row mirror loop. `tests/phase-2a2s-contract.php` §14 asserts the unconditional requirement and both call sites, and `tests/phase-2a2s-corruption-runtime.php` §6 proves a diverged mirror fails closed through the attempt read seam and converges once restored. | §6.5, §7.1, §7.3, §8.4, §9 |

Implementation correction round 3 (failed candidate `902b060`, tree `65c7a37f`) — the independent review
refused the candidate with three blocking findings. This entry records the **product-code** correction only:
no migration identity, table count, migration name, build identity or contract rule changes, no schema
object is added, and no merge or deploy is involved. Every correction makes the persisted runtime match the
rules §6.5, §6.6, §6.7, §6.8, §7.2, §7.3, §8.1, §9, §10 and §15 already state.

| Blocking finding | Fix applied | Sections |
| --- | --- | --- |
| Attempt transitions were not enforced: `record_outcome(... acknowledged: true)` accepted a still-`leased` attempt and marked it acknowledged without any transport hand-off, and `hand_off()` accepted an already-`handed_off` but unfinished attempt and called the transport again — so the locked `leased → handed_off → acknowledged` chain could be forged and one attempt could be handed off twice. | The hand-off is now a durable, idempotent reservation: a private `reserveHandOff()` commits the exact `leased → handed_off` transition plus its digest-only `hand_off` command row under the §10 lock order (aggregate → outbox row → attempt row), guarded on the attempt's persisted source state, on the outbox row carrying the same `lease_token_digest` and on a lease that has not elapsed, **before** `handOff()` calls `NotificationTransportPort`. A repeated hand-off, and a replay of the same key, return the persisted reservation without a second port call (`replayHandOff()`); a closed attempt, a lease held under another token and an elapsed lease are refused (`notification_attempt_state_conflict`, or the elapsed-lease outcome owned by recovery). `record_outcome` admits an acknowledgement only from `handed_off` and a closure only from an open (`leased`/`handed_off`) attempt. `NotificationIntegrity::attemptHistoryIntegrity()` (new) proves every persisted history — contiguous `attempt_sequence` from 1, the closed state vocabulary, a contiguous chain of legal transitions ending on the persisted state, the open/closed marker agreeing with it, at most one open attempt, and never an open attempt beside a terminal notification — as `attempt_lifecycle_invalid`, and the shared aggregate verification runs it in every protected read, every dispatch claim, the attempt read seam and `verify_notification_communications_schema`. | §6.6, §7.2, §7.3, §8.1, §9, §10, §14, §15 |
| `cancel`, `expire` and `suppress` could terminally close a `dispatching` notification without resolving its open leased attempt: the outbox row left `leased` while the attempt stayed open, and `recover_expired_leases()` could not re-arm or close it because its guarded updates require `status = 'leased'` — an unrecoverable open attempt, violating the attempt lifecycle and the `cancel_vs_dispatch` serialisation invariant. | A terminal command now resolves the live lease inside its own transaction through a fifth, audited closure class: the open attempt closes `abandoned` with `failure_class = 'lease_cancelled'` and `outcome_code` equal to the terminal state the command produced, appends its own attempt event, persists no retry schedule and re-arms nothing, while the outbox row closes consistently. `closureIntegrity()` gained the matching partition (`lease_cancellation_invalid`, a correction-round addition to the §14 diagnostic vocabulary, recorded here with its provenance), the attempt and notification vocabularies gained `handed_off|abandoned` and `dispatching|queued`, `release_lease` closes through the same bounded ceiling-first two-gate path as every other non-terminal closure (it previously wrote an undeclared `abandoned` attempt that no partition could judge), `erase_recipient` takes the root lock inside its own transaction, and a terminal notification with an open attempt is refused by aggregate integrity. The claim guard additionally re-validates `available_at`/`scheduled_for` under the lock, so a deferral or retry that moved the instant can no longer be leased. The §10 lock order is now uniform: `record_outcome` and `recover_expired_leases` previously locked the attempt row before the aggregate, inverting the fixed order the claim, the hand-off reservation, the terminal command, the overdue expiry and erasure already follow; both now discover the ids by an unlocked read and then lock aggregate → outbox row → attempt row, and the static contract suite asserts that order on both paths, so a hand-off racing a closure on one attempt can no longer deadlock. | §6.5, §6.6, §6.8, §7.2, §7.3, §8.1, §9, §10, §11, §14, §15 |
| The concurrency runner supported only six of the fourteen §15 modes and exited 2 on the rest, so six mandatory races — including `lease_expiry_vs_handoff`, `dispatch_vs_retry`, `retry_exhaustion_vs_recovery`, `activation_vs_dispatch`, `delivery_vs_attempt_close` and `erase_vs_dispatch` — had no harness path at all. | The runner declares `MODES` in the contract's own order and drives all fourteen modes, and the setup, worker and verifier each implement a path for every one of them (mode-fixture construction through the production services, the holder/contender pair per mode, and the invariants that must hold in every interleaving). The runner is committed executable (`100755`, previously `100644`, which alone made it unrunnable) and `tests/phase-2a2s-contract.php` asserts the complete matrix against the runner's declared list, the executable bit and the round's new lifecycle vocabulary and guards. Runtime coverage was added for the new rules: the retry suite gains the acknowledgement-refused-from-`leased`, repeated-hand-off-replay and live-lease-cancellation cases; the corruption suite gains the truncated-chain, unreachable-state and terminal-beside-open-attempt cases, each failing closed with `attempt_lifecycle_invalid` and converging when restored. | §6.6, §7.3, §9, §10, §14, §15, §18 |

Implementation correction round 2 (failed candidate `9dabd56`, tree `456a8db1`) — the independent review
refused the candidate with four blocking findings. This entry records the **product-code** correction only:
no migration identity, table count, migration name, build identity or contract rule changes, and no merge
or deploy is involved. The one schema addition is a single nullable column inside migration 032. Every
correction makes the persisted runtime match the rules §6.2, §6.4, §6.6, §7.1, §7.2, §7.3, §8.1, §9 and §13
already state.

| Blocking finding | Fix applied | Sections |
| --- | --- | --- |
| `NotificationDispatchService::authorisedCommand()` merged every caller-supplied field into the transport command and removed only the three evidence fields, so a caller could pass raw payload, provider-specific or arbitrary fields through `NotificationTransportPort`. | The command is now a strict allowlist built from the persisted aggregate and its proved snapshot alone: `authorisedCommand(object $notification,object $attempt)` takes no caller input and returns exactly the six frozen fields (`notification_key_digest`, `attempt_sequence`, `audience`, `template_version_id`, `variable_codes`, `parameters`); `hand_off` passes only the attempt id. The snapshot must first prove its frozen contract, its declared code set and a reproducing key-ordered parameter digest. The merge and the post-hoc `unset` loop are gone. | §6.4, §8.1, §13 |
| `claim_lease()` validated only the frozen workflow rules and then leased the row without the shared aggregate verification, so a corrupted persisted schedule, identity, outbox mirror or prior retry closure could still be dispatched. | The claim now calls the new `aggregateGuard()` while the notification and its outbox row are locked and before any eligibility verdict or lease: the frozen composition and retry policy, the persisted tier-F announced instant, the frozen logical identity and the attempt history are re-derived and passed to `NotificationIntegrity::aggregateIntegrity()`, so a corrupted row refuses the claim whole. | §7.3, §8.1, §9, §10 |
| An eligibility refusal on the re-evaluation path closed the attempt as `failure_class = retryable` with the refusal code but persisted no retry schedule, so `closureIntegrity()` read it as a non-terminal retry closure and rejected it as invalid window exhaustion on every protected read. | An already-open attempt refused at re-evaluation now closes through its own audited fourth closure class, `eligibility_abort` (a correction-round addition to the §14 diagnostic vocabulary, recorded here with its `eligibility_abort_invalid` refusal code): the refusal code is written identically as the attempt's `outcome_code` and the notification's `failure_reason_code`, the notification closes in the controlled state that code maps to (one shared `NotificationRule::controlledState()`), the attempt appends its own `failed` event, and none of the four retry columns is written and nothing is re-armed. `closureIntegrity()` gained the matching partition, which proves the class, the code equality, the controlled state and the empty schedule, and `FAILURE_CLASSES` stays the closed caller-declarable vocabulary so only the refusal path can write the abort class. | §6.2, §6.5, §6.6, §7.3, §8.1, §9, §14 |
| Snapshot freezing checked only that the number of caller-provided `variable_codes` equalled `required_variable_count`; it never verified that those codes were the parameter keys actually populated, nor that they matched the frozen template variable contract, so a snapshot could claim an arbitrary required code set while encrypting an empty or mismatched parameter map. | The canonical contract is persisted and proved: `notification_template_versions.variable_contract` (nullable `varchar(191)`) holds the sorted, deduplicated allowlisted codes as comma-separated text with the digest and the required count *derived* from exactly that text at registration, and `NotificationIntegrity::variableContract()` / `renderParameters()` / `renderedSnapshot()` require the declared code set, the decrypted parameter keys and the canonical key-ordered `params_digest` to equal the contract exactly. Snapshot freeze, hand-off, the template read seam and the schema verifier all enforce it, failing closed with `template_variable_mismatch`. | §6.4, §7.1, §7.2, §7.3, §8.1, §8.4, §11, §13 |

Two coherence repairs accompany them: `NotificationRetry::exhaustionReasonCode()` is now the single mapping
from an exhaustion gate to its closed reason code — both the port-reported/defer closure path and
lease-expiry recovery read it, so the ceiling and the window can never share a code — and the static
contract suite asserts the draft-only rule guard over the sources that perform the guarded write (the
workflow service and its repository, per §6.2/§7.2) rather than the migration installer.

Implementation correction round (failed candidate `a35d4f4`, tree `2a0c9532`) — the independent review
refused the Phase 2A.2-S implementation candidate with five blocking findings. This entry records the
**product-code** correction only: no migration identity, table count, migration name, build identity or
contract rule changes, and no merge or deploy is involved. Every correction makes the persisted runtime
match the rules §6.2, §6.3, §6.4, §6.5, §6.6, §7.3, §8.4 and §9 already state.

| Blocking finding | Fix applied | Sections |
| --- | --- | --- |
| `NotificationDispatchService::claim_lease` passed `null` to `event_row`'s required evidence array, so the `dispatching` history write raised a `TypeError`, the transaction rolled back and no lease could ever be acquired. | The claim normalizes its evidence envelope once at entry (`NotificationSupport::evidence`) and writes that array on every notification history row it appends — the `dispatching` row, and each controlled terminal row the claim path writes. | §8.1, §9 |
| Lease acquisition did not evaluate eligibility, while `re_evaluate_eligibility` hard-coded subject, recipient, consent, guardian and suppression facts as eligible, and `hand_off` could be called after neither check — so a notification could be dispatched after consent withdrawal or an active suppression. | The claim path now re-reads the aggregate and its outbox row under the S lock order, proves the frozen rule set against its digest, resolves the complete required set through the subject and recipient read ports plus the active suppression register, records the reproducing bound-evidence digest on the `dispatching` history row, and closes a no-longer-eligible notification in its controlled terminal state (`suppressed`/`expired`/`failed`) without a lease. `re_evaluate_eligibility` runs the same shared guard instead of hard-coded facts, and `hand_off` performs a full successful re-evaluation as an unavoidable prerequisite before it builds and hands over the command. | §6.2, §6.8, §7.3, §9 |
| The claim query did not require `expires_at > now`, so a queued notification whose window had closed stayed claimable and could be sent outside its mandatory window, contradicting the `queued` state invariant. | The claim set now requires the row to be inside its derived window, and each claim pass first expires overdue queued work in one transaction per row — the aggregate and its outbox row are locked in the S lock order, the notification closes `expired`/`retry_window_exhausted` and the outbox row is closed in place — so an overdue row is never claimable and never sent. | §6.3, §6.5, §9 |
| The protected read and the migration verifier validated neither the persisted schedule re-derivation, the closure partition, the persisted retry schedule nor the tier-F instant, so a rewritten schedule or an invalid attempt closure passed the read and schema checks. | Added one shared aggregate verification (`NotificationIntegrity::aggregate_integrity`, with `retry_schedule_integrity` and the persisted tier-F instant reader) that re-derives the schedule and the frozen identity, checks the outbox mirror row-to-row, and proves every closed attempt's closure partition and re-arming retry quadruple. It now runs in `NotificationReadService`, `NotificationAttemptReadService`, the dispatch claim path and `verify_notification_communications_schema` before any authority is returned. | §6.2.4, §6.3, §7.3, §8.4, §9 |
| Notification observation never used the template repository, persisted `template_version_id`/`rendered_snapshot_id` as NULL and froze no snapshot — violating the immutable rendered-template contract and leaving dispatch without the encrypted parameter snapshot. | Observation now resolves the version's template identity to its exact active `template_version_id`, freezes the immutable rendered-parameter snapshot in the same transaction (failing closed with `template_variable_mismatch` when the populated set disagrees with the frozen contract, and with `envelope_decrypt_failure` when the authenticated envelope cannot be produced), and persists both `template_version_id` and `rendered_snapshot_id` on the aggregate. Hand-off builds its channel-neutral command from that frozen snapshot. | §6.1, §6.4, §8.1, §13 |

Correction round 15 (failed candidate `c563f1c`, tree `2539b4e6`) fixes one blocking finding in this
contract text only. It is the same review the host ledger numbers correction round 16; this document's
own revision sequence continues at 15. No product code, migration, merge or deploy is involved, and the
schema identity, table count, migration name and build identity are unchanged.

| Blocking finding | Fix applied | Sections |
| --- | --- | --- |
| The terminal-reason rule was scoped to a notification that was **already** `failed`: §7.3 validated the closed vocabulary and the attempt/notification equality only on a `failed` notification whose closing attempt carries `failure_class = 'terminal'`, and §6.6 stated the closure's shape but no guard on the resulting status, so a forged `terminal`-class attempt persisted beside an `expired` notification with a matching, valid terminal reason on both records passed every verifier branch — the row is not a ceiling closure, not a window exhaustion (§7.3 scopes that rule to a non-terminal closing attempt) and not a terminal-reason violation, because the notification was never `failed`. That breaks §6.6's lifecycle partition (a `terminal` class closes the notification as `failed`, never `expired`) and can misclassify a permanent send failure as window expiry. | Made the notification's status part of the terminal invariant instead of an assumed precondition. §6.6 now states that a `terminal` class closes the notification as terminal `failed` wherever it occurs and that a `terminal`-class closure persisted beside any other status (`expired`, `suppressed`, `cancelled`) is malformed and refused whole with `terminal_reason_invalid` by `record_outcome`, and its partition paragraph notes that the terminal, ceiling and window rules each fix the notification's status, so a `terminal` attempt beside an `expired` notification falls to the terminal rule rather than being read as window exhaustion. §7.3's terminal-reason verifier bullet now rejects a notification whose closing attempt carries `failure_class = 'terminal'` **when its state is not terminal `failed`** as `terminal_reason_invalid`, the scope sentence is restated over the status as well as the class, and the window bullet records that it is never applied to a `terminal`-class closing attempt. §7.2 states the same for the persisted attempt/notification pair. §14's `terminal_reason_invalid` row names the forged `terminal`-beside-`expired` shape (a row whose vocabulary and equality branches would otherwise pass) and the `retry_window_exhaustion_invalid` row records that it never applies to a `terminal`-class attempt. §2 and §3 state the closure status as a locked invariant, §6.6/§9 keep the class-boundary rule consistent, §15's retry suite gains the **forged-`expired` terminal-class closure** case (refused whole with `terminal_reason_invalid`, rejected by the verifier, never reported as window exhaustion), and §18 records the status condition as a done condition. | §2, §3, §6.6, §7.2, §7.3, §9, §14, §15, §18 |

Correction round 14 (failed candidate `e40e7f9`, tree `6648ed7`) fixes two blocking findings in this
contract text only. It is the same review the host ledger numbers correction round 15; this document's
own revision sequence continues at 14. No product code, migration, merge or deploy is involved, and the
schema identity, table count, migration name and build identity are unchanged.

| Blocking finding | Fix applied | Sections |
| --- | --- | --- |
| Window exhaustion carried two contradictory reason-code mappings: §6.6's `abandoned` bullet closed a clamp that leaves no usable window as `failed`/`retry_exhausted`, §9 called `retry_exhausted` the code of the ceiling "and ... its clamped-window sibling", and §14's `retry_exhausted` diagnostic counted the window cause as well, while §9's own non-terminal rule, §6.6's `expired` bullet and §7.3 closed that same cause as `expired`/`retry_window_exhausted` — so a conforming implementation and its verifier disagreed on one closure. | Every section now maps **ceiling exhaustion only** to `failed`/`retry_exhausted` and **usable-window failure only** to `expired`/`retry_window_exhausted`: §6.6's `abandoned` bullet states the two gates' outcomes separately and its `expired` bullet and ceiling paragraph carry the same rule, §9's non-terminal rule reserves `retry_exhausted` for the ceiling alone, §14's `retry_exhausted` row is ceiling-only and a new `retry_window_exhausted` row counts the window shape for every path, `retry_exhaustion_invalid` is **scoped to the ceiling shape** with a new mirror rule (`retry_window_exhaustion_invalid`) for the window shape, §7.3 states both in its verifier bullets and §18 in its DoD, and §15's retry suite gains **window exhaustion on every non-terminal path** (the port-reported retry, the attempt-level defer, the operator `abandoned` release and the lease-expiry recovery each close `expired`/`retry_window_exhausted`, verify clean as valid rows and reject their paired forgeries with `retry_window_exhaustion_invalid`). | §2, §3, §6.6, §7.2, §7.3, §9, §14, §15, §18 |
| The contract gave no deterministic outcome for the reachable state in which **both** exhaustion gates fail: the closing attempt is at `retry_max_attempts` (the ceiling rule requires `failed`/`retry_exhausted`) and its clamp also leaves no usable window (the window rule requires `expired`/`retry_window_exhausted`), so replay, recovery and verification had two conforming answers and the lifecycle was not deterministic. | Declared one **precedence** — the ceiling gate is evaluated **first** and the window gate second — so the combined state is unambiguously ceiling exhaustion (`failed`/`retry_exhausted`) and the window shape always carries an attempt remaining (`attempt_sequence < retry_max_attempts`); equivalently, `retry_window_exhausted` always implies the ceiling was not reached. §2 states the order and what it means for the two accepted NULL exhaustion shapes, §3 states it for the in-scope retry policy, §6.6 states it in the `expired` and `abandoned` bullets and in the ceiling paragraph (that shape is "decided by the attempt sequence alone and is evaluated **before** the window gate"), §7.3 states it for the verifier's two exhaustion rules, §9's non-terminal rule, back-off bullet and operator-release sentence state it, §14's `retry_exhausted`/`retry_window_exhausted` rows state it, and §18 states it as a done condition; §15's retry suite gains **the both-gates-failed boundary** — that state closes `failed`/`retry_exhausted`, verifies clean, and its control twin reporting `retry_window_exhausted` is refused whole with `retry_exhaustion_invalid`. | §2, §3, §6.6, §7.2, §7.3, §9, §14, §15, §18 |

Correction round 13 (failed candidate `7a00ef1`, tree `51205e84`) fixes one blocking finding in this
contract text only. It is the same review the host ledger numbers correction round 14; this document's
own revision sequence continues at 13. No product code, migration, merge or deploy is involved, and the
schema identity, table count, migration name and build identity are unchanged.

| Blocking finding | Fix applied | Sections |
| --- | --- | --- |
| §9 stated two mutually exclusive outcomes for one and the same window-exhausted closure — a clamp that leaves no usable window "is exhaustion by window" with nothing re-armed and the four retry columns left NULL, and, in the same bullet, that the closing attempt row nonetheless "persists `applied_jitter_bp`, `base_backoff_seconds`, `backoff_seconds` and `next_available_at`" and appends a `retry_scheduled` event — and its lease-expiry path persisted the quadruple, appended the event and re-armed the outbox row unconditionally whenever an attempt remained (`a < retry_max_attempts`), with no usable-window gate, so §7.3's required-and-accepted NULL exhaustion shape could not be reached on the lease-expiry path and the verifier and lifecycle were unsatisfiable together. | Restructured §9's back-off bullet so the **only** shape that derives, persists and announces a schedule is the closure that actually re-arms — an attempt remaining (`attempt_sequence < retry_max_attempts`) **and** a clamp that leaves a usable window (clamped instant strictly later than the closure instant and, where `expires_at` is non-null, strictly earlier than `expires_at`) — while a clamp that leaves no usable window is exhaustion by window and derives nothing at all: the notification closes terminally as `expired`/`retry_window_exhausted`, the closing attempt keeps its own non-terminal `failure_class` and closure code, none of the four retry columns is written, no `retry_scheduled` event is appended and nothing is re-armed, exactly as at the attempt ceiling. §9's lease-expiry bullet now applies that identical **ceiling-and-window** rule: the quadruple persistence, the `retry_scheduled` event and the `available_at`-only re-arm occur only while `a < retry_max_attempts` **and** the clamp leaves a usable window, and a lease expiry whose clamp leaves none is explicitly routed to `expired`/`retry_window_exhausted` with the attempt still `expired`/`lease_expired`/`retryable`, no schedule, no event and the outbox row not returned to claimable — the same result as a port-reported retry, an attempt-level `defer` closure or an operator `abandoned` release under the same clamp. §9's non-terminal rule, §2, §6.6 (both the `expired` and `abandoned` bullets), §7.2 (the column semantics now name the lease-expiry recovery), §15's acceptance gate and §18's re-arm condition state the same two-gate rule, so no path can persist a schedule it must not have derived. | §2, §6.6, §7.2, §9, §15, §18 |

Correction round 12 (failed candidate `f38687e`, tree `c47f7b2`) fixes one blocking finding in this
contract text only. It is the same review the host ledger numbers correction round 13; this document's
own revision sequence continues at 12. No product code, migration, merge or deploy is involved, and the
schema identity, table count, migration name and build identity are unchanged.

| Blocking finding | Fix applied | Sections |
| --- | --- | --- |
| §7.3 unconditionally rejected **any** attempt row that closed `retryable` without the persisted `applied_jitter_bp`/`base_backoff_seconds`/`backoff_seconds`/`next_available_at` quadruple, but §6.6/§9 define two valid exhaustion closures that persist no schedule and re-arm nothing — a non-terminal closure at the final permitted attempt (`attempt_sequence = retry_max_attempts`) and a closure whose clamp leaves no usable window — so the §15 exhausted-retry verifier-pass case (a normal exhausted retry must **verify clean**) was rejected by the contract's own verifier, and the two required exhaustion shapes had no accepted persisted form. | Scoped the verifier's retry-schedule rule to the closures that **actually re-arm** — `attempt_sequence < retry_max_attempts` **and** a §9 clamp that leaves a usable window (clamped instant strictly later than the closure instant and, where `expires_at` is non-null, strictly earlier than `expires_at`) — and stated both exhaustion shapes as explicitly **accepted and required** in their NULL shape: none of the four columns written, the closing attempt keeping its non-terminal `failure_class` and its own closure code, the notification closed `failed`/`retry_exhausted` at the ceiling or `expired`/`retry_window_exhausted` by the window, with a non-NULL quadruple on an exhausted closure rejected as a schedule that must never have been derived (§7.2 now states the same column semantics; §6.6/§9 already derive nothing there). §2, §6.6, §7.2 and §14 state the same scope, the column semantics being amended together with the verifier rule, and §14 adds the window-exhausted sibling alongside the ceiling shape, the retry-ceiling rejection no longer reads as a blanket rule for an expired-lease closure that persisted no schedule, the `retry_exhaustion_invalid` parenthetical requires a usable window, §15's acceptance gate gains the paired schedule controls (the exhausted row's NULL quadruple verifies clean; a re-arming `retryable` closure with a NULL quadruple, and an exhausted closure with a non-NULL quadruple, each fail), and §18 records the scoped requirement. | §2, §6.6, §7.2, §7.3, §14, §15, §18 |

Correction round 11 (failed candidate `3a72a54`, tree `6854f98`) fixes two blocking findings in this
contract text only. It is the same review the host ledger numbers correction round 12; this document's
own revision sequence continues at 11. No product code, migration, merge or deploy is involved, and the
schema identity, table count, migration name and build identity are unchanged.

| Blocking finding | Fix applied | Sections |
| --- | --- | --- |
| §7.3 (with §6.6) scoped the closed terminal-reason vocabulary and the attempt/notification equality rule to **every** `failed` notification, but §6.6's own exhaustion rule requires a valid non-terminal exhaustion to close as `failed` with `failure_reason_code = retry_exhausted` — a code deliberately outside the three-member terminal vocabulary — so a required, valid lifecycle state failed its own integrity verification, and the two `failed` shapes were indistinguishable to the verifier. | The vocabulary and equality rule is now **scoped by the closing attempt's class**: the verifier applies it only to a `failed` notification **whose closing attempt carries `failure_class = 'terminal'`**, and validates a non-terminal exhaustion separately as exactly `retry_exhausted`, with the closing attempt keeping its non-terminal `failure_class` and the closure code of its own path and `retry_exhausted` written on the notification alone (§6.6, §7.2, §7.3, §9, §14) — round 15 extends this scope over the notification's status, so a `terminal`-class attempt persisted beside an `expired` notification is `terminal_reason_invalid`, never a window exhaustion; a non-terminal-exhaustion violation reports its own `retry_exhaustion_invalid` diagnostic (§14) and is never labelled a terminal-reason violation. The §15 retry suite gains the **exhausted-retry verifier pass** — a normal exhausted retry through each non-terminal path must verify clean — alongside the paired forgeries (the same row with `failure_class` rewritten to `terminal`, or with the notification code replaced by a vocabulary member, or with the attempt `outcome_code` replaced by a terminal-vocabulary member or by `retry_exhausted`), and §18 states the acceptance condition. | §2, §6.6, §7.2, §7.3, §9, §14, §15, §18 |
| §9's lease-expiry bullet said final lease expiry exhausts with `failure_class` becoming `terminal` while closing the notification with `failure_reason_code = retry_exhausted`, which is exactly the terminal-class borrowing of the ceiling code that §6.6/§7.3 forbid — a terminal class may only close with a member of its own vocabulary. | Final lease expiry now stays the non-terminal/retryable exhaustion path: the attempt remains `expired` with `outcome_code = lease_expired` and `failure_class = retryable`, is never reclassified as `terminal` and never borrows a terminal vocabulary member, and exhaustion changes the notification's reason code only. §2, §6.6, §7.3, §9, §14 and §18 state the same shape, so exhaustion reached by lease expiry, a port-reported retry, an attempt-level `defer` or an operator `abandoned` release is identical in classification and differs only in the attempt's closure code; the operator release is now explicitly `failure_class = retryable` and never `terminal` (§6.6, §18). | §2, §6.6, §7.3, §9, §14, §18 |

Correction round 10 (failed candidate `4246464`, tree `a3522c3`) fixes three blocking findings in this
contract text only. No product code, migration, merge or deploy is involved, and the schema identity,
table count, migration name and build identity are unchanged.

| Blocking finding | Fix applied | Sections |
| --- | --- | --- |
| §9 presented its retry intervals as the "narrow only" form of the class defaults, but the intervals only bounded the parameters: a version could register `retry_max_attempts = 100` against the default `3` and `retry_max_backoff_seconds = 525600000` against `3600`, so retry behaviour was **broadened** while the contract claimed it could not be, and the claimed invariant had no machine-checkable meaning. | Declared one **real partial order** — the coordinatewise (product) order on the five declared parameters, with the approved class baseline as its maximum element — so each parameter's admissible interval is bounded above by the baseline: `retry_max_attempts` `1…3`, `retry_initial_backoff_seconds` `1…120`, `retry_backoff_multiplier_bp` `10000…30000`, `retry_max_backoff_seconds` `retry_initial_backoff_seconds…3600`, `retry_jitter_bp` `0…1000`. "Narrow only" is now literally the per-parameter check `declared ≤ baseline`, the baseline both sits inside the order and is its widest member, and the derived arithmetic bounds, column widths and jitter-divisor range were recomputed for the narrower intervals. §2, §3, §5, §7.3, §8.1, §9, §14, §15, §18 and the header state the same intervals (round 9's intervals are superseded by this table). | §2, §3, §5, §7.2, §7.3, §8.1, §9, §14, §15, §18 |
| §6.6/§9 required a `terminal` attempt class to close with "its own declared terminal reason code", but the contract declared no terminal-code vocabulary, no mapping from the port's reported failure and no persistence path — `failure_class` carries only `terminal`, and the unconstrained `outcome_code` could not establish the matching code — so the verifier could not prove the invariant and an implementation could close with any string. | §6.6 now declares the closed, channel-neutral terminal-reason vocabulary (`contact_unusable`, `send_refused`, `no_route`), names `NotificationDispatchService::record_outcome` as the single authoritative normaliser, requires the member to be persisted identically as the attempt's `outcome_code` (with `failure_class = 'terminal'`) and the notification's `failure_reason_code` in one transaction with both event histories carrying it, refuses a missing, empty, non-member, provider-specific, mismatched, ceiling-borrowing or `failure_class`-contradicting code whole with `terminal_reason_invalid`, forbids persisting the port's raw reason, adds the verifier rejection and diagnostic, and adds forgery/missing-code test coverage. | §3, §6.6, §7.3, §8.1, §9, §14, §15, §18 |
| §9 claimed the bounded recurrence "reproduces the closed-form sequence whenever the closed form is representable", but the recurrence floors after every step while the closed form floors once, so the claim was false for a valid policy: with `retry_initial_backoff_seconds = 1`, `retry_backoff_multiplier_bp = 15000` and `retry_max_backoff_seconds ≥ 2` the recurrence gives `base_backoff(3) = 1` while the closed form gives `floor(2.25) = 2`. | Declared the bounded recurrence the **sole** canonical semantics, withdrew the equivalence claim explicitly (the closed form is never a specification), stated why the two functions differ, recomputed the overflow bounds for the narrower intervals, and pinned the divergence case (and the required recurrence value) in the §15 retry suite so a later implementation cannot "fix" the recurrence toward the closed form. | §9, §15, §18 |

Correction round 9 (failed candidate `1558a9b`, tree `467ecd0`) fixes three blocking findings in this
contract text only. No product code, migration, merge or deploy is involved, and the schema identity,
table count, migration name and build identity are unchanged.

| Blocking finding | Fix applied | Sections |
| --- | --- | --- |
| §9 said a retry moves the outbox row's `available_at` **and** `scheduled_for` to `next_available`, but §6.3(c)(8)/§6.5/§7.3 define `platform_outbox.scheduled_for` as the immutable mirror of the aggregate's frozen/deferral-derived `scheduled_for` (with `available_at` equal to it), so any retry either corrupted the mirror or failed the contract's own verification. | Retry now moves **only** `available_at`. §6.3(c)(8) names `platform_outbox.scheduled_for` the immutable schedule mirror and `available_at` the claim instant (written by the `scheduled` transition, moved alone by a retry), §7.1 states the same and the two-value rule, §7.3 checks `scheduled_for`/`expires_at`/`deferral_count` row-to-row and accepts `available_at` only when it is the mirrored `scheduled_for` or the persisted `next_available_at` of the attempt that re-armed the row (and never earlier than the mirror), §9's back-off and lease-expiry bullets state the `available_at`-only re-arm, §14 carries the diagnostic wording, §15's outbox-compatibility and retry suites assert it, and §18 states it as a done condition. | §6.3, §6.6, §7.1, §7.3, §9, §14, §15, §18 |
| §6.6 closed the notification as `failed`/`retry_exhausted` for every `terminal` attempt class, while §9 required each terminal class to close with its own reason code, so the terminal cause was lost (or an implementation had to choose between the two clauses). | Took the second option: `retry_exhausted` is reserved for the non-terminal ceiling — a `retryable`, `defer`, lease-expiry or `abandoned` closure at `attempt_sequence = retry_max_attempts` (with its clamped-window sibling `retry_window_exhausted`; superseded by correction round 14, which maps the window cause only to `expired`/`retry_window_exhausted` and leaves `retry_exhausted` to the ceiling alone, §9) — and every `terminal` class closes the notification as `failed` with **its own declared terminal reason code** wherever it occurs, the ceiling included. §6.6 states both shapes and the form they share, §9 states the rule at the class boundary and scopes the ceiling sentence to non-terminal closures, §7.3 rejects both a ceiling closure without `retry_exhausted` and a `terminal` closure that does not carry its own code (or that borrows `retry_exhausted`), §14 records that the `retry_exhausted` diagnostic is never a `terminal`-class closure, §15's retry suite proves the `terminal` case at every attempt sequence, and §18 records it. | §6.6, §7.3, §9, §14, §15, §18 |
| §9 declared retry defaults but no canonical encoding, cardinality or parameter ranges, and "a version may only narrow them" had no machine-checkable meaning, so a signed, zero, duplicate, missing or oversized parameter could leave the modulo undefined, make the back-off or the instant arithmetic overflow, or leave the narrowing claim unverifiable — despite the deterministic-replay invariant. | §9 now fixes the retry rule-set: one `rule_kind = 'retry'` code (`retry`) with five contiguous parameter rows in the same canonical one-row-per-parameter encoding as §6.3 (value in `parameter_a`, the other parameter columns NULL), a declared admissible range per parameter (`retry_max_attempts` `1…100`, `retry_initial_backoff_seconds` `1…525600000`, `retry_backoff_multiplier_bp` `10000…1000000`, `retry_max_backoff_seconds` `retry_initial_backoff_seconds…525600000`, `retry_jitter_bp` `0…10000`) with the class defaults inside it, so the envelope is the widest expressible policy and "narrow only" is exactly the range check (superseded by correction round 10, which replaces these intervals with the narrow-only partial order of §9); activation validates the encoding and the ranges (`retry_policy_invalid`), the base back-off is computed by a bounded recurrence in 64-bit integer seconds (never the overflowing closed-form power), the jitter divisor is bounded by `1…10001` so the modulo is always defined, and an out-of-domain derived instant fails closed as `retry_schedule_divergence` instead of wrapping or clamping. §7.2 records the shared parameter encoding, §7.3 adds the encoding/range rejection, §8.1 validates at activation, §14 adds `retry_policy_invalid`, and §2/§5/§15/§18 carry the envelope, the refusals and the overflow-safety case. | §2, §3, §5, §7.2, §7.3, §8.1, §9, §14, §15, §18 |

Correction round 8 (failed candidate `b27b553`, tree `6a8a3e0`) fixes four blocking findings in this
contract text only. No product code, migration, merge or deploy is involved, and the schema identity,
table count, migration name and build identity are unchanged.

| Blocking finding | Fix applied | Sections |
| --- | --- | --- |
| §6.3(f)/§7.1/§7.3 required the outbox row to "reproduce the derivation" without reading the aggregate, but the outbox schema carries none of the derivation's frozen inputs (`observed_at`, the resolved `timezone`, `schedule_anchor_at`, the frozen tier-F subject instant), so the stated verifier invariant was impossible and `deferral_count` alone could neither rebuild the base instant nor validate the mirror. | Took the second option: the standalone-rederivation claim is gone and the outbox row is defined as a **mirror and dispatch index** whose `scheduled_for`/`expires_at`/`deferral_count`/`available_at` are compared **row-to-row against the notification aggregate's locked derivation** (both rows read in one transaction under the §10 lock order and the §6.3 re-derivation), never re-derived from the outbox row alone. §6.3(c)(8) states the mirror semantics and names the inputs the outbox deliberately does not carry, §7.1 states the verifier's row-to-row comparison and the pre-scheduling NULL state, §7.3 scopes the mirror rejection to a row enriched for a `scheduled` notification and removes the "without reading the aggregate" clause, and §9's observation bullet states the same rule. | §6.3, §7.1, §7.3, §9, §15 |
| §6.3/§7.3/§15 required the verifier to reject an `active` version whose `timezone_basis` could not resolve through a "named subject", but a workflow version has no recipient or subject instance, so `recipient_local`/`subject_local` had nothing to resolve against at activation — an unsatisfiable verifier rule, and one that also left the unresolvable-basis outcome undefined. | Activation now validates **vocabulary and inter-rule equality only** (an out-of-vocabulary basis is `schedule_composition_invalid`; a conflicting pair stays `schedule_timezone_basis_conflict`), the basis is resolved **once per notification** in `observe_intent` against the concrete instance, and a basis that cannot resolve is a **terminal, fail-closed observation outcome**: no dispatchable notification, no derived instant, `failure_reason_code = schedule_timezone_unresolved`, no lease and no send, never defaulted, deferred or left `pending`. §6.3 (opening paragraph, §6.3(a)/(b)) defines both halves, §7.3 replaces the "named subject" rejection with the vocabulary check plus the observation outcome, §8.1 splits the activation check from the observation resolution, §14 re-describes the diagnostic as an observation closure, and §15 gains the corresponding negative case. | §2, §3, §5, §6.3, §6.5, §7.2, §7.3, §8.1, §9, §14, §15, §18 |
| `defer_ceiling_minutes` and `max_deferrals` had no declared ranges or encoding while driving the step-5 formula through generic signed integer columns, while `deferral_count` is `smallint unsigned`: a zero or negative defer step could leave the schedule unmoved or move it backwards, an unbounded maximum could exceed the persisted counter's capacity, and an unbounded product could overflow the deferred instant. | §6.3 now fixes the **canonical one-row-per-parameter encoding** (`ordinal` = parameter position, value as canonical unsigned decimal text in `parameter_a`, `parameter_b`/`parameter_c`/`parameter_d` NULL on every schedule row — so no schedule bound travels through a signed `int`) with a declared range per code: `1 ≤ defer_ceiling_minutes ≤ 52560000`, `0 ≤ max_deferrals ≤ 65535` and `defer_ceiling_minutes × max_deferrals ≤ 52560000`, plus `lead_time_minutes ≥ 1`, `expiry_minutes ≥ 1`, `coalesce_window_minutes ≥ 1`, `weekday_mask ∈ 1…127` and a non-empty `send_window`. Every derived instant is computed in 64-bit integer seconds and checked against the stored `datetime` domain; an out-of-range parameter or impossible composition is refused at activation with `schedule_composition_invalid` and an out-of-domain derivation fails closed as `schedule_derivation_divergence`, never by wrapping or clamping. §6.3(a), §7.2, §7.3, §8.1, §14, §15 and §18 carry the bounds, the encoding and the negative coverage. | §2, §3, §5, §6.3, §7.2, §7.3, §8.1, §14, §15, §18 |
| §6.5 permitted an S-owned `pending` notification to lack `expires_at` before scheduling, while §7.3 required the verifier to reject every S-owned notification or outbox row with `expires_at IS NULL`, so a valid pending aggregate failed its own integrity check (and the outbox mirror rule had the same gap for a pre-scheduling row). | Took the first option: the non-null rule is now **explicitly exempted** for rows that have not derived an instant. §6.3/§6.5 state that `scheduled_for` and `expires_at` are non-null **together** from `scheduled` onward and NULL **together** before it (a `pending` aggregate, or a terminal observation closed before any derivation on `tier_f_instant_unavailable`/`schedule_timezone_unresolved`), §7.2 records the bi-conditional on the columns, §7.3 keys the verifier rejection on `scheduled_for IS NOT NULL` (and rejects the one-sided states), §7.1 scopes the outbox mirror to a row enriched for a `scheduled` notification, §9 keeps pre-scheduling rows unclaimed, §12 states S never schedules or dispatches a NULL-expiry row, and §15/§18 carry the exemption and its coverage. | §2, §5, §6.3, §6.5, §6.6, §7.1, §7.2, §7.3, §9, §12, §14, §15, §18 |

Correction round 7 (failed candidate `7128332`, tree `452ac97`) fixes three blocking findings in this
contract text only. No product code, migration, merge or deploy is involved, and the schema identity,
table count, migration name and build identity are unchanged.

| Blocking finding | Fix applied | Sections |
| --- | --- | --- |
| Deferred rows could not satisfy the contract's own deterministic verification: a deferral increments `deferral_count` and moves `scheduled_for` while `expires_at` stays anchored to the pre-deferral instant, but the declared derivation and the verifier required `scheduled_for` to be the steps 1–4 result and `expires_at` to follow from the persisted (now deferred) `scheduled_for` without any `deferral_count` term — so every valid deferred notification either failed `schedule_derivation_divergence` or had its expiry window silently re-anchored, breaking the frozen, replayable scheduling invariant. | §6.3 now defines one re-derivable deferred-row formula: steps 1–3 produce the frozen base instant `derivation_base_at`, `deferral_count` is a declared persisted input (step 5: `scheduled_for = derivation_base_at + deferral_count × defer_ceiling_minutes × 60`, `0` when no `deferral` rule is registered, never `previous scheduled_for + one step`), and step 6 anchors `expires_at` explicitly to the **base** (`derivation_base_at + expiry_minutes × 60`, capped at `subject_instant` for tier F) so a deferral never moves the window; §6.3(d) applies the tier-F strict-before postcondition to the final, post-deferral result, §6.3(e) states the refusal conditions of a deferral over the moved instant, and §6.3(f) prints both formulas a verifier applies, naming the wrong (re-anchoring) form it must never use. §6.5 states that a deferral moves exactly `scheduled_for` and `deferral_count` and never the anchor, base, `expires_at` or bucket; §7.3 rejects a `scheduled_for` that is not base + count × step, an `expires_at` re-anchored on a deferred `scheduled_for`, and a `deferral_count` above `max_deferrals` or non-zero without a `deferral` rule; §9 restates the same composition and mirror; §10 adds the deferral serialisation rule; and §15's schedule suite covers the deferred-row replay, the base-anchored expiry and the re-anchored-expiry corruption, with the tier-F suite asserting the strict relationship over the final result and a new `deferral_vs_claim` concurrency mode. | §2, §3, §5, §6.2.4, §6.3, §6.5, §7.2, §7.3, §8.1, §9, §10, §14, §15, §18 |
| §6.3(f) required `deferral_count` to be mirrored to `platform_outbox` as part of the dispatch representation, but the §7.1 additive outbox-column list omitted it, so no migration could persist the stated representation and the verifier's mirror check had no column to read. | Chose the first option: §7.1 now declares `deferral_count smallint unsigned NULL` among the added columns — nullable with no default like every other added column, NULL on every Phase-1/R2 row, non-null (`0` until the first accepted deferral) on every S-owned row — and states why the mirror is required (the outbox row S's dispatcher claims on cannot reproduce or check the base-plus-count derivation without it). The §7.1 verifier text asserts the column's presence, nullability and immutability against rename/drop; §7.3 adds a rejection for a mirrored `scheduled_for`/`expires_at`/`deferral_count`/`available_at` that disagrees with the notification's derivation; §9 lists `deferral_count` in the observation enrichment; §12 records it among the added columns legacy rows keep NULL; and §15's contract, migration and outbox-compatibility suites assert the column, its NULL legacy behaviour and the mirror. | §6.3, §7.1, §7.2, §7.3, §9, §12, §15 |
| Scheduling was not total when the timezone-sensitive rules disagreed: `fixed_local_time` and `send_window` each accept an independent `timezone_basis`, while the algorithm persists exactly one resolved timezone (`dzn_notifications.timezone`), never requires the bases to agree and never states which zone governs the window step — so a version could register recipient-local placement and academy-local windowing with no unique persisted derivation. | Chose the first option: one version has exactly one basis. §6.3 states that `fixed_local_time` and `send_window` must name the same `timezone_basis`, that a conflicting pair is refused at activation with the new `schedule_timezone_basis_conflict` (never resolved by preferring a zone or by defining a cross-zone order), that the shared basis is part of the frozen digest, and that both wall-clock steps evaluate in that one persisted zone; (b) defines `timezone` as that single frozen resolution (empty exactly when the version registers no timezone-sensitive rule, never re-resolved after observation), and (f) adds the persisted `timezone` to the values a re-derivation must reproduce. §7.3 rejects the conflicting pair, an unresolvable basis and a persisted `timezone` that is not the frozen resolution; §8.1 refuses the conflict in `activate_version`; §14 lists both timezone codes; §15's contract suite adds them to the closed failure vocabulary and its schedule suite covers the refusal, the accepted agreeing pair and the unresolvable basis; §3/§5/§18 state the one-basis invariant. | §2, §3, §5, §6.3, §6.5, §7.2, §7.3, §8.1, §14, §15, §18 |

Correction round 6 (failed candidate `069d929`, tree `4168e5d`) fixes two blocking findings in this
contract text only. No product code, migration, merge or deploy is involved, and the schema identity,
table count, migration name and build identity are unchanged.

| Blocking finding | Fix applied | Sections |
| --- | --- | --- |
| `AUTOMATIC_RENEWAL_UPCOMING` had two possible bound events (`opened` or `payment_required`) while its outbox identity carries only aggregate + aggregate ID + intent; R2 publishes at cycle open and invokes the same intent again on automatic `requirePayment`, the outbox repository deduplicates the second publication onto the first row, and S could not determine which event tuple to freeze for B2 evidence — violating the claimed one-bound-fact invariant and making late observation nondeterministic. | Took the required first option: `opened` is now the **sole** authoritative binding. §6.2.2 binds the intent to the cycle-open `opened` event with allowlist `{pending}` and states the one-event-type-per-row rule explicitly (a row may enumerate several `from → to` variants of one event type, never two event types); the "both publishable paths" acceptance requirement is gone from §15, whose late-subject-state and tier-F suites now drive the cycle past `payment_required`/`collected`/`term_bound` on the same frozen `opened` tuple. §1 adds the source-verified fact 4 (both publish sites, the `intentKey()` dedupe, the absent originating-event reference), §2 states an intent published from more than one transition is not consumable, and the §6.2.4(b) R2 amendment gains item 6: `intentForTransition()` must not return the intent for `require_payment` in `automatic` mode, so a `payment_required` transition publishes no second copy — a behaviour-preserving change (no durable row, no replay result, no test expectation changes) whose deliberate consequence, that a cycle opened with no recorded charge instant is never announced late, is stated. §6.2.3, §6.2.4's evidence paragraph, §7.3 (single-site binding rejection), §16/§17.2 (base and amendment) and §18 carry the corresponding entries. | §1, §2, §6.2.2, §6.2.3, §6.2.4, §7.3, §15, §16, §17, §18 |
| Tier-F scheduling was contradictory and not fully deterministic: tier F requires the send to precede the subject instant and requires `subject_instant_in_future`, yet the required tier-F test asserted `scheduled_for` **equals** that instant (equality fails the "ahead of" predicate); and the document listed schedule rule codes and mandated `expiry_minutes` without defining the schedule composition or the anchor/formula for `expires_at`, so non-null expiry alone left retry-window behaviour unreproducible. | §6.3 is now one frozen, total scheduling algorithm: a closed composition validated at activation (`schedule_composition_invalid` — exactly one anchor, `lead_time` with `lead_time_minutes ≥ 1` on tier F and `immediate` on tier P, at most one each of `fixed_local_time`/`send_window`/`deferral`/`coalesce`, exactly one `expiry`), explicit immutable inputs (`observed_at`, the persisted tier-F `subject_instant`, the resolved `timezone`, the frozen rules), one derivation order (anchor → local placement → half-open send window with a bounded search → `scheduled_for` → expiry → coalesce bucket), a **strict** tier-F postcondition (`scheduled_for < subject_instant`; equality closes `expired`/`eligibility_expired`), the exact `expires_at` formula for both orientations (`scheduled_for + expiry_minutes × 60`, capped at `subject_instant` for tier F), bounded deferral that never crosses the frozen window, and persisted inputs/results (`observed_at`, `schedule_anchor_at`, `scheduled_for`, `expires_at`, `deferral_count`, `timezone`) whose re-derivation must reproduce exactly (`schedule_derivation_divergence`). The anchor lead time is declared separately from the eligibility lead time (never greater than the anchor's), the §6.2 `subject_instant_in_future`/`lead_time_at_least` rows are redefined over persisted values (a strict ordering and an inclusive minimum), §6.5/§7.2 carry the new columns, §7.3 and §14 carry the new rejections and diagnostics, §8.1 validates the composition and the postcondition, §15 replaces the equality assertion with the strict relationship and its invariance plus a dedicated `phase-2a2s-schedule-derivation-runtime` suite, and §3/§5/§9/§18 state the composition, formula and deferral rules. | §2, §3, §5, §6.2, §6.2.1, §6.3, §6.5, §7.2, §7.3, §8.1, §9, §14, §15, §18 |

Correction round 5 (failed candidate `afcfd8c`, tree `4719f64`) fixes two blocking findings in this
contract text only. No product code, migration, merge or deploy is involved, and the schema identity,
table count, migration name and build identity are unchanged.

| Blocking finding | Fix applied | Sections |
| --- | --- | --- |
| Tier-F authoritative instants were not durably available: `AUTOMATIC_RENEWAL_UPCOMING` was permitted from `renewal_cycle`, but R2 persists no automatic charge instant on that aggregate — `RenewalCycleService::automaticChargeAt()` recomputes it from the current `AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME` policy and `CollectionIntentService::open()` recomputes it again when the `automatic_charge` collection intent (the only carrier of `charge_at`) is opened later, so the announced instant could drift or not exist yet — and the nullable `guarantee_deadline_at` fallback re-derived the deadline from mutable pattern/schedule data. | Added §6.2.4, the closed tier-F instant contract: a durable per-intent source column (`dzn_renewal_cycles.automatic_charge_at`, and the already-persisted `guarantee_deadline_at`), a bounded R2 amendment prerequisite (write the instant in the transaction that commits the bound fact, publish `AUTOMATIC_RENEWAL_UPCOMING` only when it is non-null, make the collection intent read the persisted column, and keep both columns immutable once written), a read-only S consumption rule that excludes `CommercialPolicyService`, `MANUAL_RENEWAL_SLOT_GUARANTEE_WEEKS`, the pattern wall-clock resolver and every fallback derivation, fail-closed behaviour for an unavailable instant (`tier_f_instant_unavailable`, never published, registered, dispatched or left pending, never re-derived), a `tier_f_instant_divergence` guard for a non-reproducing instant, and post-publication invariance for policy changes, pattern mutations, moved boundaries and restarts. §6.2.2 now binds the three tier-F instants to those persisted columns, §5/§8.1/§14/§15/§17/§18 carry the corresponding default, refusal, diagnostic, test and prerequisite entries, and the new `phase-2a2s-tier-f-instant-runtime` suite (with its `policy_change_after_publication_vs_dispatch` concurrency mode) is an acceptance gate. | §1, §2, §3, §4, §5, §6.2.1, §6.2.2, §7.3, §8.1, §14, §15, §17, §18 |
| The §9 retry formula unconditionally clamped `next_available` to `expires_at`, but `expires_at` is nullable (`§7.1`, `§7.2`) and the contract defined neither a mandatory expiry rule nor NULL semantics, so a workflow without expiry had no specified deterministic retry calculation or window-exhaustion behaviour. | Expiry is now mandatory: every activated version must register an `expiry` rule with `expiry_minutes ≥ 1`, refused at activation with `schedule_expiry_missing` and rejected by the verifier, so every S-owned notification carries a non-null `expires_at`. In addition — and so the formula is total rather than merely unreachable — §6.3/§9 define one explicit NULL rule: when `expires_at IS NULL` the expiry term is omitted from the minimum, the "not strictly earlier than `expires_at`" window check is skipped, `retry_window_exhausted` can only arise from the non-advancing clamp, and deferral is bounded by its own ceiling. The same rule is stated for persistence, replay, lease-expiry recovery, verifier checks and the retry suite (both branches, never mixed within one persisted schedule), and §12 records that legacy rows keeping NULL stay valid while S never creates one. | §2, §3, §5, §6.3, §6.5, §6.6, §7.1, §7.2, §7.3, §9, §12, §14, §15, §18 |

Correction round 4 (failed candidate `d88acb9`, tree `6a60b4d`) fixes two blocking findings in this
contract text only. No product code, migration, merge or deploy is involved, and the schema identity,
table count, migration name and build identity are unchanged.

| Blocking finding | Fix applied | Sections |
| --- | --- | --- |
| The retry ceiling was stated but not enforced: the formula permitted a retryable closure at `attempt_sequence == retry_max_attempts` that re-armed the outbox row, producing attempt `max + 1`, and lease-expiry recovery returned work directly to a claimable state without consuming the ceiling or persisting/replaying the deterministic schedule. | §9 now defines `retry_max_attempts` as an acquisition-counted ceiling on the total number of attempts (`1 ≤ retry_max_attempts`), a single re-arm rule ("re-arm only while an attempt remains", `attempt_sequence < retry_max_attempts`), and terminal exhaustion (`failed` / `retry_exhausted`, outbox row closed in place) for a `retryable`, `defer` or `expired` closure at the final attempt; the jitter formula's domain is `1 ≤ a < retry_max_attempts` with no evaluation at the ceiling; the attempt-level `defer` class is defined as the same bounded path (outcome `deferred`); a clamp leaving no window closes terminally as `expired` / `retry_window_exhausted`. Lease expiry is now a named retry class: the expired attempt always has a persisted row, recovery derives the schedule with closure instant = the persisted `lease_expires_at`, persists the quadruple plus the `retry_scheduled` event and only then re-arms (or terminally exhausts at the ceiling), and repeated recovery replays the persisted values idempotently without double-counting. Verifier rejections, the `retry_exhausted` diagnostic and boundary/lease-expiry test coverage were added. | §2, §5, §6.6, §7.2, §7.3, §9, §14, §15, §18 |
| Mandatory B2 (`subject_state_is`) was not bound to a defined per-intent authoritative fact: the contract required only a non-empty, vocabulary-valid allowlist and said post-fact intents must contain "the state the intent reports" without defining that mapping, so an implementation could reject a valid durable intent from a later current state (for example an automatic-upcoming intent published while a cycle is `pending` that later reaches `payment_required`) or use an arbitrarily broad allowlist. | Added §6.2.2, the closed intent → authoritative-fact matrix, binding each of the eleven intents to its subject aggregate, its originating append-only event type, the committed `from → to` transition(s) from the owning module's locked transition table, the exact `to_state` allowlist, its tier and — for tier F — the authoritative announced instant; `GUARANTEE_EXPIRED`, which has no R2 writer, is reserved-unbound and refused with `intent_unbound`. Added §6.2.3, which evaluates `subject_state_is` as a history predicate over that immutable evidence (allowlist derived, never authored), states successor-state semantics so a later legal transition never flips the verdict, and defines terminal handling for delayed observation and delayed dispatch; verifier rejections, the diagnostics vocabulary, DoD and the new §15 binding and late-subject-state suites were added. | §2, §4, §5, §6.2, §6.2.1, §6.2.2, §6.2.3, §7.3, §14, §15, §18 |

Correction round 3 (failed candidate `cb664d8`, tree `68d6d4a`) fixes two blocking findings in this
contract text only. No product code, migration, merge or deploy is involved, and the schema identity,
table count, migration name and build identity are unchanged.

| Blocking finding | Fix applied | Sections |
| --- | --- | --- |
| The "complete required eligibility set" was never defined per intent or audience, so an implementation could activate a workflow without `recipient_opted_in`, `recipient_resolvable` or the applicable guardian-authority rule, defeating the consent and fail-closed eligibility invariants. | Added §6.2.1: a mandatory baseline (B1–B6, including `recipient_resolvable`, `recipient_opted_in` and a `guardian_authority_present` rule present on every version), an intent/audience-specific mandatory tier (forward-looking vs post-fact, with `subject_instant_in_future` and a declared non-negative `lead_time_at_least` on tier F), and the authorised audience/recipient pairs closed by default. Activation validates the union and fails closed with `eligibility_rule_set_incomplete`, `eligibility_binding_mismatch` or `audience_not_authorised`; the validated set is frozen into `rule_set_digest`/`definition_fingerprint` and re-derived on every read, enqueue and dispatch path; the verifier rejects an activated version that misses, duplicates or misbinds a mandatory rule, and a per-code negative matrix suite is added. | §6.1, §6.2, §7.3, §8.1, §9, §14, §15, §18 |
| Retry policy declared `retry_jitter_bp` without a calculation or stable inputs, so random or clock-dependent jitter would make retry scheduling non-deterministic and prevent replay/recovery from deriving the same `next_available`, contrary to the deterministic scheduling and idempotency contract. | Defined a deterministic keyed jitter — `applied_jitter_bp(a)` is the low 32 bits of `hash_hmac('sha256', 'retry_jitter:' \|\| notification_key_digest \|\| ':' \|\| workflow_version \|\| ':' \|\| a, wp_salt('dzn_notification'))` reduced `mod (retry_jitter_bp + 1)` against the version's declared span — added to the integer base back-off and clamped to `retry_max_backoff_seconds` and `expires_at`; RNG, host/worker identity and any clock other than the persisted `finished_at` are excluded. The closing attempt now persists `applied_jitter_bp`, `base_backoff_seconds`, `backoff_seconds` and `next_available_at`, appends a digest-only `retry_scheduled` event, and replay/lease-expiry recovery must reproduce the persisted values exactly (`retry_schedule_divergence` otherwise, never a silent reschedule), with determinism/replay/convergence coverage added. | §7.2, §7.3, §9, §14, §15, §18 |

Correction round 2 (failed candidate `ef0b601`, tree `3b3499b`) fixes three blocking findings in this
contract text only. No product code, migration, merge or deploy is involved, and the schema identity,
table count, migration name and build identity are unchanged.

| Blocking finding | Fix applied | Sections |
| --- | --- | --- |
| The schema permitted multiple active workflows for the same consumed intent: `workflow_active(workflow_id,active_slot)` limited activity per workflow only and `intent_state(intent_key,state)` was non-unique, so routing one R2 intent was ambiguous and could duplicate notification creation. | Added the intent-level routing slot `intent_active_slot` with `UNIQUE KEY intent_active(intent_key,intent_active_slot)`, renamed the registration-consistency requirement into atomic activation arbitration (fail-closed `intent_routing_conflict` with whole-transaction rollback), verifier rejection of routing/freeze state that disagrees with `state`, and competing-activation coverage. | §2, §3.8, §6.1, §7.2, §7.3, §8.1, §10, §14, §15, §18 |
| `notification_workflow_rules` stayed append-only with no draft-only rule or freeze point, so a rule appended after activation could silently change eligibility, scheduling or retries without a new version or fingerprint. | Rule attachment is now draft-only and activation is the freeze point: `rule_set_digest`/`rule_frozen_at` are written in the activation transaction, the guarded insert refuses a frozen version (`workflow_rules_frozen`), and every read/enqueue/dispatch path revalidates the frozen digest and fails closed with `workflow_rule_set_mutated`. Post-activation append coverage added to the runtime and corruption suites. | §2, §6.1, §6.2, §7.2, §7.3, §8.1, §14, §15, §18 |
| The contract required a one-to-one notification/outbox relationship, but the proposed outbox schema created only a non-unique `KEY notification_id`, so several outbox rows could reference one notification and split its lease/delivery state. | `platform_outbox` now carries `UNIQUE KEY notification_id(notification_id)`. The column stays nullable with no default, so every legacy and R2 row keeps NULL (a unique index permits unlimited NULLs) while a second row can no longer claim the same notification; the verifier asserts the named unique key and the migration/compatibility suites prove duplicate rejection with legacy inserts unaffected. | §6.5, §7.1, §7.3, §10, §12, §15, §18 |
