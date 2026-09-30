# Delnavazan Platform Finance Policy Registry (Phase 2A.2-U)

> **Candidate document (Schema 30, not authoritative until independently reviewed and merged).** It
> mirrors the commercial registry's Class-A/Class-B structure and drift-prevention rules for the four
> finance keys and for the structural invariants of the phase contract.

## Class A — structural invariants (never configurable)

These are decided by the phase contract and can never be recorded as a policy version. A command that
tries fails closed with `finance_policy_key_not_allowed` and the message *Structural finance invariants
are not configurable finance policies*.

| Invariant | Where it lives |
| --- | --- |
| The snapshot boundary is the recorded occurrence start (`occurrence_start`) | `FinanceRule::SNAPSHOT_BOUNDARY`, U-D4 |
| A statement period is half-open and at most 62 days | `FinanceRule::MAX_STATEMENT_PERIOD_DAYS`, §10.1 |
| A statement is single-currency; there is no conversion and no FX | `FinanceRule::STATEMENT_CURRENCY_RULE`, U-D13 |
| Every amount is an exact integer number of minor units | `FinanceRule::AMOUNT_EXACTNESS`, U-D1 |
| The timezone triple's only source is the recorded policy | `FinanceRule::FINANCE_TIMEZONE_SOURCE`, §10.1 |
| No policy version may be recorded into consumed time | `FinanceRule::POLICY_ADMISSIBILITY_RULE`, §6.3/U-D19 |
| The payability derivation table and its version | `FinanceRule::PAYABILITY_DERIVATION`, `PAYABILITY_DERIVATION_VERSION`, §5.3/U-D8 |
| One applicable evaluation per Lesson, one snapshot per Lesson, one live rate per scope | §9.1, §8.1, §7.1 |

## Class B — recorded finance policies

`finance_policies` stores exactly one row per `(policy_key, policy_version)`; a row is immutable in value
and mutable in exactly one column (`status`). **Unset is the absence of a covering version, never a
null-valued row.** There are four keys and no others.

| Policy key | Recorded default | Accepted value | Enforced by |
| --- | --- | --- | --- |
| `INTRO_PAYABILITY_POLICY` | `non_payable` (version 1, seeded by migration 030, active) | `payload`-free `policy_reference`: `payable` \| `non_payable` | `LessonPayabilityService::derive()` row 5; recorded on `finance_lesson_snapshots`/`finance_snapshot_corrections` as `intro_policy_key`/`intro_policy_version` |
| `STUDENT_NO_SHOW_COMPENSATION_POLICY` | `payable` (version 1, seeded, active) | `payable` \| `non_payable` | derivation row 8; the applied version is recorded on `finance_payability_evaluations` |
| `INTERRUPTION_COMPENSATION_POLICY` | `payable` (version 1, seeded, active) | `payable` \| `non_payable` | derivation row 9; recorded as above |
| `FINANCE_STATEMENT_TIMEZONE` | **unset — no row exists and none is seeded** | an IANA timezone (`value_type = timezone`) | `TeacherStatementService::draft()`/`issue()`; recorded as `period_timezone`/`period_label`/`timezone_policy_version` |

## Lifecycle and drift prevention

- A version is written once: `policy_key`, `policy_version`, `policy_value`, `value_type` and
  `effective_from` are never updated; `effective_from` must be present, strictly monotonic per key, and
  strictly later than the key's recorded consumption maximum (§6.3).
- `status` moves at most twice per row, in one declared direction: `active → superseded` (only when a
  successor exists) and then `superseded → withdrawn`, or `active → withdrawn` directly. `withdrawn` is
  terminal; each move is its own audited compare-and-swap with its own affected-row count and its own
  `finance_teacher_rate_events`-style audit evidence.
- **The two moves belong to two declared commands.** `record()` inserts one version and moves nothing —
  the version it replaces stays `active` until the operator runs `supersede()`, which is the only command
  that performs the conditional `active → superseded` move, with its own `finance_policy_commands` row and
  its own audit evidence (contract §6.2, §0g). `record()`'s own command row names the version it wrote.
- Resolution is by the covered instant, never by live status: `superseded` is normal history, `withdrawn`
  resolves to unset *and reports its version*, and a key with no version at or before the instant is
  "never set" — never a nearest-version guess and never a default.
- Every mutating policy command takes the global `finance_policy_roots` row **exclusively**; every
  policy consumer (`capture`, `evaluate`, `override`, statement `draft`/`issue`) takes the same root
  **shared and first**, so a policy change and a consumer are totally ordered.
- A version added to any vocabulary is a contract change, not a configuration change.
