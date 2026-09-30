# Phase 2A.2-V — Provider-Neutral Google Calendar & Meet Integration (implementation record)

Status: **RECONSTRUCTED IMPLEMENTATION CANDIDATE — CORRECTION ROUND 5 APPLIED — NOT INDEPENDENTLY
REVIEWED / NOT MERGED / NOT DEPLOYED.** No live credential, no provider traffic, no production data,
no Theme change, no deployment and no public cutover is authorised by this document or by the code it
describes.

> **Why this record exists.** The previously recorded Phase-V candidate
> `3724edb3c36959f3657e6b495ab96f26d347c476` / tree `5402cf890e2db898bccb15ba8ffc97176ced6457` is no
> longer present in any workspace, backup object store, reflog or remote branch. This candidate is a
> reconstruction of the *same approved contract* from the completed
> `PLATFORM-V-GOOGLE-CONTRACT-PREFLIGHT` contract, the authoritative current Platform code/docs and
> the preserved prior Phase-V run evidence. No new product behaviour was invented: every rule below
> is traceable to the preflight contract (`docs/PHASE-2A-2V-PROVIDER-NEUTRAL-GOOGLE-CALENDAR-MEET-INTEGRATION.md`
> in the contract-preflight workspace) or to the preserved Phase-V run records.
>
> **Correction round 2.** Independent review of the reconstructed candidate
> `6c2ab1ce32676286d10a8248fab358ed02e7694a` / tree `ed10e67cf29bb4bcfc599175df0f20d351a3a78c`
> returned FAIL / CORRECTION REQUIRED with five blocking findings. Section 9 records each finding and
> the exact correction. History is additive: nothing was reset, rebased, amended or force-pushed, and
> the corrections are a strictly additive change on top of that candidate.
>
> **Correction round 3.** Independent review of the round-2 candidate
> `d9eec89715f20c8d12c61173e4c7b6e9c5769fef` / tree `5850a5a405800cd981c667d36d99328394af6b49`
> returned FAIL / CORRECTION REQUIRED with two blocking findings. Section 10 records each finding and
> the exact correction. History remains additive: nothing was reset, rebased, amended or force-pushed,
> and this round is a strictly additive change on top of that candidate.
>
> **Correction round 4.** Independent review of the round-3 candidate
> `d9c47b60d085817009a0734e51886154352b6359` / tree `bc02802f40a780b19750d5e349e44f98f206739d`
> (materialised in this workspace as commit `eed38cda98e0a59614c2ae92872a2c1ff1032d80`, same tree)
> returned FAIL / CORRECTION REQUIRED with two blocking findings: the reverse canonical lock order of
> `lockLessonRoots()` and the non-atomic `MAX(event_sequence)+1` receipt allocation. Section 11
> records each finding and the exact correction. History remains additive: nothing was reset, rebased,
> amended or force-pushed.
>
> **Correction round 5.** Independent review of the round-4 candidate
> `389a5463877a2ae92eaefd7020789a5ad33d2943` / tree `d2965765ff8d5afb824f83f5a44d19b9ca65a410`
> returned FAIL / CORRECTION REQUIRED with one blocking finding: two concurrent deliveries of the same
> provider event key with changed contexts on different Lessons could lose the durable conflict receipt,
> because the loser of the unique-index race met an `IdempotencyConflictException` instead of
> `recordConflict()`. Section 13 records the finding and the exact correction. History remains additive:
> nothing was reset, rebased, amended or force-pushed, and this round is a strictly additive change on
> top of that candidate.

## 1. Identity

| Item | Value |
| --- | --- |
| Authoritative base | manager checkout `559b1736621c9ed32e41dd2b785dd0f040dcb647` (tree `4e7fb1736b4a30de323cd25fe5dd4b6ceaad65a1`) |
| Superseded candidates | `6c2ab1ce32676286d10a8248fab358ed02e7694a` / tree `ed10e67cf29bb4bcfc599175df0f20d351a3a78c` (round-1 review FAIL; retained in history), `d9eec89715f20c8d12c61173e4c7b6e9c5769fef` / tree `5850a5a405800cd981c667d36d99328394af6b49` (round-2 review FAIL; retained in history), `d9c47b60d085817009a0734e51886154352b6359` / tree `bc02802f40a780b19750d5e349e44f98f206739d` (round-3 review FAIL; retained in history) and `389a5463877a2ae92eaefd7020789a5ad33d2943` / tree `d2965765ff8d5afb824f83f5a44d19b9ca65a410` (round-4 review FAIL; retained in history) |
| Candidate schema | **27** |
| Candidate migration | `027_google_calendar_meet_provider_integration` |
| Candidate build | `phase2a2v-provider-neutral-google-calendar-meet-20260925.5` (correction round 5) |
| Candidate SHA / tree | recorded by the host materialisation; a commit cannot embed its own hash |

The preflight contract named `26` / `026_google_calendar_meet_provider_integration` as *placeholders
to be fixed by the approving cut*. The authoritative base already carries Schema 26
(`026_renewal_recurring_enrolment_authority`), so this candidate takes the next free number and stays
strictly additive: no prior migration is renamed, renumbered or replayed, and no existing table,
column or capability is modified. Migration 027 has never been released or deployed, so the corrected
statement of that one migration is itself the additive change.

## 2. Authority boundary (unchanged from the contract)

| Authority | Owner | Phase-V relationship |
| --- | --- | --- |
| Canonical Lesson identity/lifecycle | Phase M | Read-only. V never issues, completes, cancels or reclassifies a Lesson. |
| Canonical scheduling & Teacher capacity | Phase N | Read-only. V may project an *already-applicable* schedule version; it never creates, revises or releases one. |
| Canonical delivery/attendance outcome | Phase O | Read-only. V records no outcome and corrects none. |
| Attendance intake, identity, review, settlement | Phase P | V is a trusted evidence **source** that submits normalised facts to the Phase-P intake seam. Participant identity authority stays Phase P. |

The candidate contains no `UPDATE`/`INSERT` of canonical Lesson, schedule, delivery, attendance,
payment or notification storage. Its only writes are to its own ten integration tables and to the
Phase-P intake seam through `CanonicalAttendanceIntakeService::ingestProviderEvidence`.

## 3. Storage

Ten provider-neutral tables (verifier: `verify_google_calendar_meet_provider_integration_schema`,
run after the migration, on current-schema verification and unconditionally before schema
activation, including the retained-migration/stale-version path):

| Table | Purpose |
| --- | --- |
| `dzn_integration_connections` | One active Teacher+provider connection with append-preserving lifecycle generations. |
| `dzn_integration_credentials` | Sealed renewable credential: `ciphertext` + `nonce` + `key_version` + `cipher_version` only. |
| `dzn_integration_oauth_authorizations` | One-time consent intent bound to its **exact** `connection_id`: state digest, verifier digest, client/scope/redirect/principal binding, expiry, consumed outcome. |
| `dzn_provider_identity_mappings` | Provider subject → Core Teacher connection identity, versioned with supersession. |
| `dzn_provider_calendar_event_mappings` | Provider calendar event → exact Lesson + canonical schedule version; may be `pending` until a provider result is acknowledged. |
| `dzn_provider_meeting_mappings` | Provider conference/join reference → exact Lesson + canonical schedule version; may be `pending` as above. |
| `dzn_provider_ingest_events` | **Immutable** receipt of one authenticated provider delivery, with separate provider/local instants and the authenticating transport plus its proof digest. |
| `dzn_provider_ingest_outcomes` | **Append-only** handoff outcome per receipt attempt (`admitted`/`refused`) with its reason, attempt number and the Phase-P result digest it was written for. |
| `dzn_provider_event_conflicts` | Append-only durable conflict receipts for a changed provider-event context. |
| `dzn_provider_integration_commands` | Append-only digest-only command evidence. |

The verifier rejects a provider-specific column, a plaintext credential column, a missing credential
key/cipher version, a mutable column on the four append-only tables, a malformed digest, a missing
unique index (`active_connection`, `provider_subject`, `lesson_version`, `provider_event`/
`provider_conference`, `handoff_sequence`, `conflict_identity`, `command_key_digest`), a
non-transactional engine, an unexpected table claiming a Phase-V prefix, and any attempt to smuggle
academic, attendance, delivery, notification or payment storage into the phase.

## 4. Behaviour locked by the contract

- **V-D2 lifecycle.** `disconnected → authorizing → connected`, with `refresh_failed`, `revoking`,
  `revoke_failed`, `revoked`; a revoked connection is terminal, and a reconnect always starts a new
  consent lifecycle. A reconnect may be started while an earlier connection is still connected: the
  earlier connection keeps the active slot and stays usable until the new consent *completes*, and
  completion then settles every competing lifecycle explicitly. A failed provider revoke is recorded
  and retryable and never leaves the connection usable locally (the credential is quarantined
  *before* the provider is asked).
- **V-D3 initiation.** State and PKCE verifier are generated server-side, returned once, and stored
  only as digests. The intent binds the principal, exact Core Teacher, client, ordered scope set,
  exact HTTPS redirect target and **the exact connection the command created**, carries an absolute
  expiry, and is consumed exactly once. The command's replay digest is taken over the deterministic
  request facts only — one-time material and the expiry are generated strictly after the recorded
  command is checked — so a repeated idempotency key reconstructs the identical digest and returns
  the recorded connection result without re-issuing or persisting any one-time material. Scope
  mismatch, identity mismatch, unknown/expired/replayed state and a redirect mismatch all fail
  closed. Teacher self-connect revalidates the Phase-J `teacher_principal_links` row inside the
  transaction. Completion locks the intent and settles exactly the bound connection; a competing
  authorizing sibling is closed and its own issued intent is recorded as `rejected`.
- **V-D4 credentials.** `sodium_crypto_secretbox` (AES-256-GCM fallback) with a unique nonce per row,
  a key derived from the WordPress salts with `dzn_provider_integration` domain separation, a stored
  key/cipher version, and an envelope bound to exactly one connection + provider code. Decrypt fails
  closed on an unknown version, a malformed nonce, a truncated ciphertext or a failed
  authentication; rotation is explicit; nothing is ever logged, exported or placed in a notice.
- **V-D5 mappings.** A provider subject/account/calendar/event/conference value is stored only as a
  keyed digest and never re-identifies a Teacher or Lesson. Supersession/revocation is
  append-preserving.
- **V-D6/V-D7 projections.** A calendar/meet projection copies one exact applicable canonical
  schedule version's UTC interval and wall-clock provenance, and is refused when the version is
  stale, unusable or the Lesson's canonical aggregate does not validate. A port that can perform the
  provider write returns the acknowledged opaque reference and the mapping is recorded `verified`. A
  translation-only port — the only Google seam in this phase has no transport, credential or HTTP
  client — returns the exact provider request plus its fact digest and **no** reference: the
  projection is recorded `pending` with no active slot, and only a separate `acknowledge_*` command
  carrying the acknowledged provider result may promote it to `verified`. A retraction quarantines
  the local reference only (a pending translation is retractable too): it never cancels, releases or
  reschedules the Lesson.
- **V-D8 evidence.** Provider facts reach Phase P only through
  `CanonicalAttendanceIntakeService::ingestProviderEvidence`; the integration layer never resolves a
  participant, never asserts verification, never writes Phase-O/P storage, and holds no integration
  row across the Phase-P transaction. A raw provider delivery is refused outright: the only accepted
  input is a delivery envelope a named trusted transport already authenticated — transport identity,
  authenticated instant, the exact body it validated bound by digest, and the transport's own proof
  reference — and only that envelope is handed to the normaliser. A header, a body without an
  envelope, or a Google channel token is never proof. Phase V performs no provider cryptography and
  holds no provider secret.
- **V-D9/V-D10 idempotency.** A provider event key is deduplicated against full immutable context
  equality: an exact duplicate converges, a changed context is durably refused as one of
  `changed_payload`, `cross_lesson`, `cross_schedule_version`, `cross_context`, `cross_participant`,
  `cross_interval`, and the original receipt is never overwritten. Commands persist
  `command_key_digest` + `command_payload_digest`; replay reconstructs the complete facts through the
  same canonical digest path and compares the payload digest unconditionally.
- **V-D8/V-D9 durable handoff.** The ingest receipt is immutable and is written exactly once, in its
  receive state, before the Phase-P handoff — so an interruption always leaves evidence of the
  delivery. Admission is a separate appended outcome row written only *after* the handoff succeeded,
  and carries the Phase-P result digest it was written for. A duplicate request with no admitted
  outcome re-attempts the handoff (Phase P is idempotent on the provider event key) instead of
  reporting an admission that never reached Phase P. The receipt table is never updated by the phase.
- **V-D11 no raw payload.** Only normalised facts and keyed digests are stored.
- **V-D12/V-D14 no I/O.** No migration, activation or verification path reads a credential,
  configuration option or network resource; the phase contains no HTTP/OAuth/webhook client.
- **V-D13 least privilege.** Administrator: all five capabilities. `dzn_teacher`: only
  `dzn_connect_own_provider_calendar` and `dzn_view_provider_integrations`, and the Teacher role is
  actively stripped of the management, revocation and ingestion grants. A read requires the
  management capability, or the view capability **and** exact ownership of the Teacher resolved
  through the Phase-J principal link — being linked to a Teacher never by itself grants a read.
  Background ingestion is a named system actor with the bounded `dzn_ingest_provider_events` grant;
  participant identity and delivery outcomes are never delegated to that actor.

## 5. Modules

| Artefact | Responsibility |
| --- | --- |
| `src/Core/Application/ProviderIntegrationRule.php` | Controlled vocabulary, lifecycle transitions, exact redirect/scope comparison, proof-transport vocabulary, the authenticated delivery-envelope rule, projection-fact extraction from a canonical schedule version. |
| `src/Core/Application/ProviderIntegrationIdempotency.php` | Domain-separated key/payload/evidence/subject/provider-event/proof digests. |
| `src/Core/Application/IntegrationSecretService.php` | Seal/open/rotate the integration secret class; fail closed; redacted reporting. |
| `src/Core/Application/ProviderIntegrationValidator.php` | Fail-closed row shapes (connection, credential, mapping — including `pending` projections — receipt, handoff outcome, conflict, command) plus the composed canonical Lesson/schedule/delivery validity a projection requires. |
| `src/Core/Application/ProviderIntegrationService.php` | Connection lifecycle, exact-lifecycle consent completion and competing-lifecycle settlement, identity mappings, projections and retractions, pending→acknowledged projection promotion, digest-only command evidence and replay. |
| `src/Core/Application/ProviderEventIngestService.php` | Authenticated-envelope ingestion, immutable receipts, append-only handoff outcomes, dedup/conflict receipts, delegation to the Phase-P seam. |
| `src/Core/Application/ProviderIntegrationReadService.php` | Capability-gated, object-level-authorised reads; credentials reported redacted only; receipts report their immutable receive state and their effective outcome separately. |
| `src/Core/Application/Port/*.php` | `ProviderOAuthPort`, `ProviderCalendarPort`, `ProviderMeetingPort`, `ProviderEventNormalizer`. |
| `src/Integrations/GoogleCalendarMeetAdapter.php` | The only Google-specific translation seam: pure, no HTTP client, no OAuth library, no credential, no webhook, translation-only projections. |
| `src/Integrations/ContractProviderAdapters.php` | Deterministic no-I/O adapters implementing all four ports for the evidence runs; credential material is never recorded. |
| `src/Core/Infrastructure/Repository/ProviderIntegrationRepository.php` | All ten tables plus the Phase-V lock order (`Student–Course identity root → Enrolment → Term → canonical Lesson`, taken in that order by `lockLessonRoots()` and revalidated against the locked rows before a caller sees them) and the provider-scoped serialisation of the receipt sequence (`lockProviderEventSequence()` / `releaseProviderEventSequence()`). |

Core never imports `Delnavazan\Platform\Integrations\*`; the adapters are injected through the ports.

## 6. Test surface and exact disposable-runtime commands

| Artefact | Coverage | Status |
| --- | --- | --- |
| `tests/phase-2a2v-contract.php` | Static source contract: migration/verifier wiring, ten tables, provider neutrality, digest-only storage, capability wiring, seam names, authenticated-envelope requirement, pending→acknowledged projection boundary, immutable-outcome storage, consent-to-connection binding, read-capability requirement, no external call. | NOT EXECUTED HERE (see §7) |
| `tests/phase-2a2v-isolated-runtime.php` | Pure runtime proof of the rule, the authenticated delivery envelope, the digest boundary, credential sealing (unique nonce/binding/fail-closed/rotation), the translation-only Google seam, deterministic adapters (acknowledging and translation-only) and fail-closed shapes. The suite prints its own assertion count. | NOT EXECUTED HERE (see §7) |
| `tests/phase-2a2v-migration-runtime.php` | Fresh Schema 27, additive 26→27, no backfill, repeat safety, per-capability repair, retained-027 and stale-version verification, malformed-storage rejection for provider/plaintext/mutable/digest/index/extra-table/missing-outcome-table probes. | NOT EXECUTED HERE (needs WP-CLI + MariaDB) |
| `tests/phase-2a2v-runtime.php` | Consent lifecycle with deterministic digest replay, exact-lifecycle completion with a competing pending consent, sealing, mappings, calendar/Meet projection against the exact canonical version, pending→acknowledged promotion, stale-version refusal, retraction without canonical mutation, authenticated Phase-P evidence seam, immutable receipt with an appended admission, duplicate convergence, durable conflict, capability denial, object-level reads including the view-capability requirement, digest-only persistence. | NOT EXECUTED HERE |
| `tests/phase-2a2v-corruption-runtime.php` | Corrupt connection state/identity digest, credential versions and sealed material, mapping shapes (including a pending projection), provider-event context/state/proof, handoff outcome, conflict kind and command evidence; every path fails closed and recovers after repair. | NOT EXECUTED HERE |
| `tests/phase-2a2v-failure-runtime.php` | Injected write boundaries at the connection, projection and ingest gates: full rollback, no orphan provider reference, no falsely replayable command, no Phase-P fact from a rolled-back ingest, and no reported admission for a receipt whose handoff outcome is absent. | NOT EXECUTED HERE |
| `tests/phase-2a2v-concurrency-runner.sh` (+ setup/worker/verify) | Deterministic process-level matrix of §12: `connect_vs_revoke`, `authorization_replay` (two processes consuming one identical authorization state with one identical command key), `projection_vs_release`, `projection_vs_completion`, `duplicate_vs_conflicting_event`, `cross_lesson_event_key_race` (one event key delivered twice with a materially changed context on two different Lessons — one immutable receipt for the winner, one durable conflict receipt for the loser), `provider_event_sequence_race` (two distinct event keys for two different Lessons delivered together — disjoint canonical chains, one provider-scoped receipt sequence), `ingest_vs_canonical_authority` (a provider ingest raced against a canonical schedule authority holding the complete canonical chain), `mapping_revoke_vs_ingest`, `teacher_archival_vs_connection`, `unrelated_teacher`. | NOT EXECUTED HERE |

Runtime suites are gated on `DZN_PHASE_2A2V_RUNTIME_TEST` ∈
`migration|authority|corruption|failure|concurrency` and refuse to run outside WP-CLI on a
`local`/`development` environment. In the disposable WordPress/MariaDB harness:

```sh
php tests/phase-2a2v-contract.php
php tests/phase-2a2v-isolated-runtime.php
DZN_PHASE_2A2V_RUNTIME_TEST=migration  wp eval-file tests/phase-2a2v-migration-runtime.php --user=1 --allow-root
DZN_PHASE_2A2V_RUNTIME_TEST=authority  wp eval-file tests/phase-2a2v-runtime.php --user=1 --allow-root
DZN_PHASE_2A2V_RUNTIME_TEST=corruption wp eval-file tests/phase-2a2v-corruption-runtime.php --user=1 --allow-root
DZN_PHASE_2A2V_RUNTIME_TEST=failure    wp eval-file tests/phase-2a2v-failure-runtime.php --user=1 --allow-root
DZN_PHASE_2A2V_REPO=<repo> DZN_PHASE_2A2V_WP_DIR=<wp> DZN_PHASE_2A2V_NET=<net> \
  sh tests/phase-2a2v-concurrency-runner.sh <mode>
```

## 7. Evidence executed for this correction round

### Correction round 5

The environment constraint is unchanged: no PHP interpreter is on the `PATH`, no Docker runtime is
reachable (the Docker CLI reports `permission denied while trying to connect to the docker API`) and
network access is restricted, so no PHP file could be linted and no suite could be executed here.
Executed instead, against the reviewed finding:

- a structural balance check over every touched PHP file (delimiters, strings and comments scanned as
  tokens; all clean);
- a line-by-line review of the corrected path: the re-read of the committed receipt under the
  provider-scoped lock was traced against the two-Lesson race the new mode stages, and the
  unique-index guard was traced through `duplicate()`, `conflict()` and `insertConflict()` to the
  conflict receipt it must leave;
- a symbol-resolution check over every touched PHP file: every referenced class, method and helper
  (`GET_LOCK`/`RELEASE_LOCK` are driver statements, not PHP symbols) resolves to an imported,
  same-namespace or fully-qualified name; `IdempotencyConflictException` is deliberately no longer
  raised by the ingest seam, and the class itself is untouched for the callers that own it;
- a static contract extension in `tests/phase-2a2v-contract.php` that now *machine-checks* the
  re-read of the committed receipt under the provider-scoped serialisation before the insert, the
  presence of the durable conflict recorder on the guarded path and the absence of any bare
  `IdempotencyConflictException` rejection of a materially changed context, and that lists the new
  concurrency mode;
- `sh -n tests/phase-2a2v-concurrency-runner.sh` (the runner script gains one mode);
- `git diff --check` and a complete `git status` review before publication;
- `php -l` over every touched PHP file: **NOT EXECUTED** (no PHP runtime in this environment) and must
  be run in the disposable harness before merge, together with the runtime suites.

Every suite in §6, including the two that need no WordPress, is marked NOT EXECUTED HERE. This round
changed source and test surface that those suites assert on — in particular the ingest seam and the
concurrency matrix — so the earlier candidate's results do not carry over and must be reproduced by the
reviewer's harness before this candidate is accepted. No live credential, no provider traffic, no
production data, no Theme change and no deployment were used or produced.

### Correction round 4

The environment constraint is unchanged: no PHP interpreter is on the `PATH`, no Docker runtime is
reachable (the Docker CLI reports `permission denied while trying to connect to the docker API`) and
network access is restricted, so no PHP file could be linted and no suite could be executed here.
Executed instead, against the two reviewed findings:

- a structural balance check over every touched PHP file (all clean);
- a line-by-line review of each corrected path, including a trace of `lockLessonRoots()` against the
  canonical authorities it must interleave with (`CanonicalLessonAuthorityService`,
  `CanonicalLessonScheduleService`, `CanonicalLessonDeliveryService`, `CanonicalAttendanceIntakeService`)
  and a trace of the provider-sequence allocation against the two-connection race the new mode stages;
- a symbol-resolution check over every touched PHP file: each referenced class and helper
  (`GET_LOCK`/`RELEASE_LOCK` are driver statements, not PHP symbols) resolves to an imported,
  same-namespace or fully-qualified name;
- a static contract extension in `tests/phase-2a2v-contract.php` that now *machine-checks* the declared
  lock order (the identity root is locked before the Enrolment, the Enrolment before the Term and the
  Term before the Lesson), the presence of the three relationship revalidations, the fact that an
  acknowledgement locks the canonical chain before the mapping row, and the fact that the ingest takes
  the provider-scoped sequence serialisation before the receipt insert and releases it on every path;
- `sh -n tests/phase-2a2v-concurrency-runner.sh` (the runner script gained two modes);
- `git diff --check` and a complete `git status` review before publication;
- `php -l` over every touched PHP file: **NOT EXECUTED** (no PHP runtime in this environment) and must
  be run in the disposable harness before merge, together with the runtime suites.

Every suite in §6, including the two that need no WordPress, is marked NOT EXECUTED HERE. This round
changed source and test surface that those suites assert on, so the earlier candidate's results do not
carry over and must be reproduced by the reviewer's harness before this candidate is accepted. No live
credential, no provider traffic, no production data, no Theme change and no deployment were used or
produced.

### Correction round 3

Executed in the correcting environment (no PHP interpreter is on the `PATH` and no Docker runtime is
reachable there — the Docker CLI reports `permission denied while trying to connect to the docker
API` — and network access is restricted, so no PHP file could be linted or executed):

- a structural balance check over every touched PHP file (all clean);
- a full manual review of every touched PHP file against both reviewed findings, including a trace of
  each corrected path through the shipped fixtures;
- a symbol-resolution check over every PHP file in `src/` and `tests/`: each static class reference,
  `new`/`extends`/`implements`/`catch` target and return type resolves to an imported name, a
  same-namespace class or a fully-qualified name (this is what confirmed the adapter's missing
  `ProviderIntegrationRule` import; the only remaining unqualified references in `src/` are two
  pre-existing gaps in the merged Phase-N admin screen, recorded below rather than changed here);
- a content-level tree check: rebuilding the reviewed candidate's tree from its tracked path/mode/blob
  set reproduces `5850a5a405800cd981c667d36d99328394af6b49` exactly, and the same method applied to this
  corrected working tree produces the candidate tree reported with this round;
- `sh -n` is **not** applicable: the runner script was not modified;
- `git diff --check` and a complete `git status` review before publication;
- `php -l` over every touched PHP file: **NOT EXECUTED** (no PHP runtime in this environment) and
  must be run in the disposable harness before merge, together with the runtime suites above.

Every suite in §6, including the two that need no WordPress, is therefore marked NOT EXECUTED HERE.
This correction round changed source that those suites assert on, so the earlier candidate's
EXECUTED — PASS results no longer carry over and must be reproduced by the reviewer's harness before
this candidate is accepted. No live credential, no provider traffic, no production data, no Theme
change and no deployment were used or produced.

## 8. Explicit non-authority / deferrals

No Lesson issuance/lifecycle mutation; no scheduling, revision or release; no canonical delivery or
attendance outcome write; no attendance settlement or adjudication; no participant identity
resolution; no payment/Stripe, notification, WhatsApp/Meta, renewal, payroll or Finance authority; no
calendar import/availability authority; no public join action or signed capability; no portal or
Theme change; no Amelia dependency; no production OAuth/webhook activation; no production cutover and
no deployment.

The five preflight open product decisions remain open and un-implemented by design: the exact approved
Google scope set, the calendar projection create/update-versus-delete retraction policy, failed-revoke
credential retention, webhook push versus bounded polling, and whether Meet join/leave evidence
auto-settles ordinary delivery through Phase P. The candidate takes the fail-closed option for each:
the scope set is deployment configuration validated as an exact set, a retraction quarantines locally
and records a retryable outcome rather than assuming a delete, a revoked credential stays quarantined,
transport is a deployment concern behind the normaliser port (and must hand over an authenticated
envelope), and settlement remains Phase P's own decision.

## 9. Correction round 2 — blocking findings and their resolution

Reviewed candidate `6c2ab1ce32676286d10a8248fab358ed02e7694a` / tree
`ed10e67cf29bb4bcfc599175df0f20d351a3a78c`. Every finding is corrected in code, in storage, in the
module documentation and in the test surface:

1. **Consent replay could never converge (`ProviderIntegrationService.php`).** `beginAuthorization()`
   generated the state/PKCE digests and the expiry *before* checking the recorded command, so a
   repeated idempotency key always produced a different payload digest and the replay also omitted
   `connection_id`. *Corrected:* the payload digest is computed from the deterministic request facts
   only (domain, operation, provider, Teacher, principal, client, redirect, scope) and is compared
   before any one-time material exists; the replay returns the recorded `connection_id` and the
   recorded intent (`authorization_id`, `scope_snapshot`, `expires_at`, state) with no state, verifier
   or challenge, which are still returned exactly once at creation and never persisted.
   `tests/phase-2a2v-runtime.php` now asserts convergence, the recorded result and the absence of
   one-time material on replay.
2. **An authorization intent could complete another lifecycle.** Intents had no `connection_id` and
   completion selected the newest authorizing connection for the Teacher/provider, so two pending
   consent attempts could cross. *Corrected:* `integration_oauth_authorizations.connection_id` binds
   every intent to the exact lifecycle its command created (`connection_authorization` index);
   completion locks the intent, loads and locks **that** connection, requires it to be the matching
   `authorizing` row, and `settleCompetingLifecycles()` explicitly rejects every other issued intent
   and closes every competing authorizing or connected sibling before the new one takes the active
   slot. `tests/phase-2a2v-runtime.php` §12 covers two pending attempts; the concurrency matrix's
   `authorization_replay` mode now races two processes against one identical state.
3. **No real Calendar/Meet projection could be recorded.** The only Google adapter returns a
   translation with no `provider_object_reference`, while the service required that value and threw
   `provider_reference_unusable`. *Corrected:* the port boundary now states both result shapes. A
   translation-only result is persisted as a `pending` projection (no active slot, translation digest
   as the provisional reference) and can never be read as a verified provider object; only a separate
   `acknowledge_calendar_projection` / `acknowledge_meeting_projection` command carrying the
   acknowledged provider result (digested immediately) may promote it to `verified`, with the unique
   provider-reference index as the durable guard. `tests/phase-2a2v-runtime.php` §13 and the
   translation-only adapter mode cover the flow.
4. **A fabricated delivery was trusted as evidence.** The Google seam accepted any non-empty body with
   any `x-goog-channel-token` header. *Corrected:* `ProviderIntegrationRule::deliveryEnvelope()`
   requires a named trusted transport (`google_channel_jwt`, `google_pubsub_oidc`,
   `deployment_gateway`), an explicit authentication flag, a UTC authenticated instant, the exact
   body bound by SHA-256 digest, and a transport proof reference (stored only as a digest). A raw
   delivery, a bare body or a channel token is refused, and only the authenticated envelope is handed
   to the normaliser.
5. **Admission was committed before the Phase-P handoff, and the append-only receipt was mutated.**
   *Corrected:* `dzn_provider_ingest_events` is now immutable — a receipt is written once in its
   `received` state and is never updated — and admission is a separate append-only row in the new
   `dzn_provider_ingest_outcomes` table, written only after a successful Phase-P handoff and carrying
   that handoff's result digest. A duplicate request without an admitted outcome re-runs the handoff
   (idempotent in Phase P) instead of reporting a false admission; `markProcessingState()` and the
   direct receipt update are gone. `tests/phase-2a2v-failure-runtime.php` §3b proves that a receipt
   whose outcome is absent is reported as `received`, never as `admitted`.
6. **A linked Teacher could read without the view capability.** `ProviderIntegrationReadService`
   had a second own-Teacher branch that bypassed the capability check. *Corrected:* a self-service
   read requires the view capability **and** exact Teacher ownership; the management capability still
   covers every Teacher. `tests/phase-2a2v-runtime.php` §10 removes the capability from the linked
   Teacher and proves the refusal.

Behaviour that the review confirmed and that this round deliberately preserves: no canonical
Lesson/schedule/delivery/attendance write, credential sealing and binding, append-preserving
supersession, digest-only command evidence with unconditional payload-digest replay comparison, the
immutable provider-event history, and the fail-closed option on every open product decision.

## 10. Correction round 3 — blocking findings and their resolution

Reviewed candidate `d9eec89715f20c8d12c61173e4c7b6e9c5769fef` / tree
`5850a5a405800cd981c667d36d99328394af6b49`. Both findings are corrected in code and in the test
surface that asserts the corrected behaviour:

1. **The deterministic normaliser read an outer `facts` field the seam deliberately strips.**
   `ProviderIntegrationRule::deliveryEnvelope()` reduces a delivery to the provider code, the
   transport, the authenticated instant, the exact body plus its digest and the proof digest — it
   carries no `facts` field — and `ProviderEventIngestService` hands exactly that sanitised envelope to
   `ProviderEventNormalizer::normalise()`. `ContractProviderAdapters::normalise()` nevertheless read
   `$envelope['facts']`, so every fixture ingesting through the deterministic adapters was refused
   with an empty fact set before any evidence could be handed over; the adapter also named
   `ProviderIntegrationRule` without importing it, so `verify()` failed before a fact was ever read.
   *Corrected:* the adapter imports the rule, verifies the delivery-envelope shape in `verify()`, and
   normalises only from the digest-bound `raw_body` of the envelope it is handed. It re-checks
   `hash_equals(hash('sha256',$raw),$body_digest)` itself, decodes that body, validates the controlled
   facts (provider event key, provider account, participant role, UTC observed instant, UTC join/leave
   instants) and takes the occurrence binding only from the authenticated body. An outer `facts` array
   is never consulted, so a caller can never state the facts a delivery is ingested under.
   `tests/phase-2a2v-isolated-runtime.php` §8 now asserts the body-bound normalisation, the sanitised
   envelope the seam actually hands over, the refusal of a body that does not match its declared
   digest, and that a forged outer fact set cannot override the body;
   `tests/phase-2a2v-runtime.php` §9 now re-binds the authenticated body (recomputing its digest) to
   prove a delivery whose body names another Lesson is refused, and proves that an authenticated body
   binds the occurrence with no caller hint at all.
2. **Handoff-outcome allocation was a non-transactional `MAX(...)+1`.** The attempt number was chosen
   outside any transaction and the ingest seam appended the outcome after the receipt transaction had
   closed, so two concurrent retries of the same received event could both choose the same attempt and
   one would violate the unique `(provider_ingest_event_id, handoff_attempt)` index *after* Phase P had
   already been invoked: the caller received a persistence failure instead of converging. *Corrected:*
   the attempt is now allocated inside a transaction that locks the parent receipt row
   (`SELECT … FOR UPDATE` by primary key), so the second contender waits for the first allocation to
   commit and then takes the next attempt. A contender that finds the effective outcome already
   recorded — including a refusal offered for a receipt that already carries an admission, which is
   never superseded — is reported by a `0` return instead of a driver failure, and
   `ProviderEventIngestService` re-reads the durable outcome and converges on it rather than surfacing
   the failure. `tests/phase-2a2v-concurrency-verify.php` now additionally asserts, for the
   `duplicate_vs_conflicting_event` mode, that attempt numbers are allocated contiguously for the
   receipt, that neither contender is answered with a duplicate-key failure, and that converged
   duplicates leave the recorded admission as the effective outcome.

Behaviour that the review confirmed and that this round deliberately preserves: the authenticated
envelope boundary (a raw delivery, a bare body or a channel token is never proof), the immutable
provider-event receipt with append-only handoff outcomes, admission written only after a successful
Phase-P handoff, and no write of any kind to Phase-M/N/O/P storage — the only writes remain the ten
Phase-V tables and the Phase-P intake seam.

### Pre-existing gap observed, deliberately not changed by this round

The symbol-resolution check leaves exactly one unresolved unqualified class reference in the shipped
`src/` tree, outside Phase V and outside both reviewed findings:
`src/Admin/Controller/ScreenController.php` (`canonicalScheduleAction()`) constructs
`CanonicalLessonScheduleService` and `CanonicalLessonScheduleReadService` without importing either
class, so an administrator invocation of the Phase-N canonical-schedule action would fail on an
unresolvable class name. The gap is carried by the authoritative base `559b173…`, is untouched by this
phase, and is deliberately **not** repaired here: a bounded correction round must not widen the
reviewed surface. It is recorded so the owning Phase-N slice can schedule the import fix under its own
review, together with the runtime coverage that would catch it.

## 11. Correction round 4 — blocking findings and their resolution

Reviewed candidate `d9c47b60d085817009a0734e51886154352b6359` / tree
`bc02802f40a780b19750d5e349e44f98f206739d` (materialised in this workspace as commit
`eed38cda98e0a59614c2ae92872a2c1ff1032d80`, identical tree). Both findings are corrected in code and
in the test surface that asserts the corrected behaviour:

1. **`lockLessonRoots()` took the canonical chain in the reverse of the declared order.** The Phase-V
   order is `Student–Course identity root → Enrolment → Term → canonical Lesson`, and every canonical
   authority (`CanonicalLessonAuthorityService`, `CanonicalLessonScheduleService`,
   `CanonicalLessonDeliveryService`, `CanonicalAttendanceIntakeService`) takes exactly that order via
   `CanonicalLessonAuthorityRepository::lockRoot()`. `lockLessonRoots()` instead locked the Lesson
   first, then the Enrolment, then the Term, and only then the identity root, so a Phase-V ingest or
   projection racing a canonical operation on the same aggregate could form a lock cycle — the
   integration path holds the Lesson and waits for the root while the canonical path holds the root and
   waits for the Lesson. *Corrected:* the Lesson is read first only as an **unlocked** hint that names
   its Enrolment; the identity root is located from that Enrolment and locked first; the Enrolment, the
   Term and finally the Lesson are locked in the declared order; and the locked relationship is
   revalidated before it is handed to a caller (`canonical_enrolment_identity_root_changed`,
   `canonical_lesson_enrolment_changed`, `canonical_lesson_term_changed`), so a hint that changed
   between the read and the locks can never move the lock onto a different aggregate. The same
   invariant is now honoured by `ProviderIntegrationService::acknowledge()`, which previously locked a
   mapping row and *then* took the canonical chain — the mirror image of the cycle a projection forms
   when it locks the chain and then the mapping row; the mapping is now read as an unlocked hint, the
   canonical chain is locked, and only then is the mapping row locked and revalidated against the chain
   that was locked. `tests/phase-2a2v-concurrency-runner.sh` gains the
   `ingest_vs_canonical_authority` mode, which holds the complete canonical chain inside a canonical
   schedule operation while a provider ingest takes the same chain, and asserts that the canonical
   operation fails closed leaving the canonical aggregate untouched, that the ingest still leaves its
   immutable receipt, and that neither contender is answered with a deadlock or a lock-wait timeout.
   `tests/phase-2a2v-contract.php` now additionally pins the declared order statically: the identity
   root must be locked before the Enrolment, the Enrolment before the Term and the Term before the
   Lesson, the three revalidations must be present, and an acknowledgement must lock the chain before
   the mapping row.
2. **A new receipt allocated `event_sequence` with an unsynchronised `MAX(...)+1`.** `event_sequence`
   is unique per provider, but two deliveries that name different Lessons take different canonical
   chains, so nothing serialised the read of the head: both contenders could choose the same number and
   the loser failed the unique `(provider_code,event_sequence)` index instead of recording its immutable
   receipt. *Corrected:* a new receipt now takes the provider-scoped sequence under
   `ProviderIntegrationRepository::lockProviderEventSequence()`, a provider-scoped named lock
   (`GET_LOCK` on a bounded digest of the provider code) that is taken **before** the head is read and
   released only after the receipt transaction has committed or rolled back, so a contender always
   reads the committed head and takes the next number. The unique `(provider_code,event_sequence)` index
   is preserved unchanged as the durable guard behind the serialisation, and the lock is released on
   every path (including the rollback and the duplicate-convergence path). The concurrency matrix gains
   the `provider_event_sequence_race` mode: two distinct event keys for two different Lessons are
   delivered together while the holder's receipt transaction is still open, and the verifier asserts
   that two immutable receipts exist with the two next provider sequences (no gap, no collision), that
   they belong to the two raced Lessons, and that neither contender is answered with a duplicate-key
   persistence failure or with an unavailable serialisation.

Behaviour that the review confirmed and that this round deliberately preserves: the authenticated
envelope boundary, the immutable provider-event receipt with append-only handoff outcomes, admission
written only after a successful Phase-P handoff, the pending→acknowledged projection boundary, and no
write of any kind to Phase-M/N/O/P storage — the only writes remain the ten Phase-V tables and the
Phase-P intake seam. No new table, column, index or migration was introduced by this round, and the
schema stays Schema 27 / migration `027_google_calendar_meet_provider_integration`.

## 12. Declared canonical lock order, the provider-scoped receipt sequence and the immutable conflict

Three invariants make the Phase-V storage safe to share with the canonical authorities. All are stated
here so that §5, the repository docblock, the static contract and the concurrency matrix describe the
same rules.

**Canonical lock order.** Anything that needs both a canonical aggregate and a Phase-V integration row
takes the canonical chain first, in exactly this order: Student–Course identity root → Enrolment →
Term → canonical Lesson. Only after the chain is held may a caller lock an integration row (a
connection, a credential, an identity mapping, a calendar or meeting mapping, an ingest receipt, a
conflict or a command). A row that is needed to *discover* the Lesson is read first as an unlocked
hint and re-read under `FOR UPDATE` after the chain is held, so a stale hint can never move the lock
onto another aggregate. `ProviderIntegrationRepository::lockLessonRoots()` implements the rule;
`ProviderIntegrationService::acknowledge()` follows it; `ProviderIntegrationService::retract()` locks
only its own mapping row and takes no canonical lock at all, so it cannot form the reverse cycle.

**Provider-scoped receipt sequence.** `event_sequence` in `dzn_provider_ingest_events` is unique per
`provider_code` and is the provider-scoped ordering of immutable receipts. Because two deliveries for
different Lessons hold disjoint canonical chains, the sequence is allocated under a provider-scoped
serialisation: `lockProviderEventSequence()` is taken before the head is read, `maxEventSequence()+1`
is computed inside that serialisation, and `releaseProviderEventSequence()` runs after the receipt
transaction has ended. The unique `(provider_code,event_sequence)` index remains the durable guard.

**Immutable conflict receipt.** `provider_event_key_digest` is unique per `provider_code`, so one
provider event key has exactly one immutable receipt, and that same provider-scoped lock decides the
key itself: the committed head is re-read under it before a new receipt is inserted, so a contender
that loses the race decides from the committed winner rather than from a stale absence. A materially
changed context — including one that arrives on a *different* Lesson and therefore holds a different
canonical chain — is then routed through `recordConflict()`, which appends the durable conflict receipt
under the unique `conflict_identity` index and converges on the recorded row if a concurrent contender
already wrote it. The unique `provider_event` index stays as the durable guard behind the
serialisation, and a delivery that still loses that index race is routed through the same recorder, so
no path reduces a materially changed context to a bare rejection.

## 13. Correction round 5 — blocking finding and its resolution

Reviewed candidate `389a5463877a2ae92eaefd7020789a5ad33d2943` / tree
`d2965765ff8d5afb824f83f5a44d19b9ca65a410` (HEAD of the reviewed round-4 candidate, materialised in
this workspace as commit `389a5463…`). The single finding is corrected in code and in the test surface
that asserts the corrected behaviour:

1. **A concurrent changed-context delivery of one provider event key could lose its durable conflict
   receipt.** Two deliveries of the same provider event key can name different Lessons, so they take
   different canonical chains and their `lockLessonRoots()` calls do not meet. Both could observe no
   receipt before either took the provider-scoped sequence lock; the winner then committed, and the
   loser — attempting its own insert — met the unique `provider_event` index, saw that the winner's
   `event_fact_digest` differed and threw `IdempotencyConflictException` without ever calling
   `recordConflict()`. The immutable-ingest contract requires the opposite: every materially changed
   context is durably *recorded* as a conflict, not merely rejected, so the losing delivery silently
   produced no evidence at all. *Corrected:* the provider event key itself is now decided under the
   provider-scoped serialisation. `lockProviderEventSequence()` is taken **before** the committed head
   is read (for every path that would insert a new receipt) and is held until the deciding transaction
   has ended, and the committed receipt is re-read under that lock, so a contender that loses the race
   reads the committed winner and — because its context materially differs — records the durable
   conflict exactly as the sequential case does. The unique `provider_event` index is preserved
   unchanged as the durable guard behind the serialisation, and a delivery that still loses that index
   race is now routed through the same recorder (`conflictReceipt()` → `recordConflict()`) instead of
   being reduced to a bare rejection; `recordConflict()` converges on the recorded row when two
   contenders carry the identical changed context, so the unique `conflict_identity` index keeps
   exactly one durable conflict receipt and no contender is answered with a driver failure in its
   place. The concurrency matrix gains the `cross_lesson_event_key_race` mode: one event key is
   delivered twice at the same instant with a materially changed context on two different Lessons
   while the holder's receipt transaction is still open, and the verifier asserts that exactly one
   immutable receipt survives (for the winning Lesson), that no second receipt is created for the
   losing Lesson, that exactly one conflict receipt exists for that key classifying the divergence as
   `cross_lesson` and naming both the receipt and the losing Lesson, that the losing contender reports
   that conflict rather than a failure, and that neither contender is answered with a duplicate-key
   persistence failure or with a bare idempotency conflict.

Behaviour that the review confirmed and that this round deliberately preserves: the authenticated
envelope boundary, the immutable provider-event receipt with append-only handoff outcomes, admission
written only after a successful Phase-P handoff, the provider-scoped receipt sequence, the declared
canonical lock order, the pending→acknowledged projection boundary, and no write of any kind to
Phase-M/N/O/P storage — the only writes remain the ten Phase-V tables and the Phase-P intake seam. No
new table, column, index or migration was introduced by this round, and the schema stays Schema 27 /
migration `027_google_calendar_meet_provider_integration`.
