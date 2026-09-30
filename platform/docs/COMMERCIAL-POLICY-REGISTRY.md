# Delnavazan Commercial Policy Registry

**Status:** introduced with the Phase 2A.2-R1 commercial authority (Schema 25, merged and closed on
`main`) and extended by the Phase 2A.2-R2 renewal/collection candidate (Schema 26, not authoritative
until independently reviewed and merged).
**Purpose:** keep `COMMERCIAL POLICY ↔ PLATFORM AUTHORITY ↔ PORTAL UX ↔ NOTIFICATION WORDING ↔ TERMS & CONDITIONS`
aligned without duplicating any rule. This document is a registry and an assertion, never a second
configurable authority: it contains no rule that Platform code does not already enforce.

## 1. Classes

| Class | Meaning | Where the value lives | May the registry own a value? |
|---|---|---|---|
| **A — structural invariant** | A fixed property of the academic/commercial model. Changing it is a platform change, not configuration. | One canonical source in code, or the canonical record itself (for example the Term's recorded allocation). | **No.** This document may only assert the value and point to the source. |
| **B — runtime configurable policy** | A commercial value the academy may set, version and change without code. | The versioned `dzn_commercial_policies` registry. | **Yes.** One row per key and version; an unset value is a deliberate recorded state. |

## 2. Class A — structural invariants

| Policy identifier | Value | Single canonical source | Enforcement |
|---|---|---|---|
| `TERM_SESSION_COUNT` | 12 | `CanonicalTermAuthorityService::SESSION_ALLOCATION`, recorded on each canonical Term as `terms.lesson_allocation` | Canonical Term creation; canonical Lesson issuance reads the recorded Term value and never a second literal |
| `TERM_STUDENT_CHANGE_ALLOWANCE` | 2 per Term | `CanonicalTermAuthorityService::REPLACEMENT_ALLOWANCE`, recorded on each canonical Term as `terms.replacement_allowance` | Canonical Lesson issuance reads the recorded Term value; academy-owed occurrences are a separate authority and never consume it |
| `INSTALMENT_TRANCHE_STRUCTURE` | two ordered contiguous tranches, sessions 1–6 then 7–12 | `CommercialRule::INSTALMENT_TRANCHES` / `TRANCHE_*` | Offer and obligation decomposition; the obligations are recorded immutably per offer |
| `TRANCHE_PREREQUISITE_ORDER` | obligation *n* is academically effective only when every lower-sequence obligation is settled | `CommercialTermFundingService::obligationStatus()` | Funding derivation; the canonical Lesson funding guard fails closed with `standard_funding_exhausted` |
| `CAPACITY_SUCCESSION` | an existing capacity authority is never released until its successor is durable under the same per-Teacher scheduling root | `CommercialCapacityService::handoffFromEntitlement()` | Phase-Q hold → R1 protected claim → Phase-N schedule; a failed handoff leaves the predecessor hold active |
| `AUTOMATIC_RENEWAL_SLOT_PROTECTION` | continuous protection while the automatic relationship is valid or in recovery | Phase R2 `dzn_recurring_protections` (one active protection per renewal cycle and per R1 claim) linked to an active R1 protected-capacity claim | R2 protection records; release is delegated to the R1 capacity authority under the same per-Teacher scheduling root and is never a silent side effect of lapse/cancel |
| `MANUAL_GUARANTEE_EXPRESSION` | the guarantee is the whole-week interval *n* before the next-Term boundary, resolved in the pattern timezone | Phase R2 `RecurringRule::MANUAL_RENEWAL_SLOT_GUARANTEE_WEEKS` (= 4), recorded as `dzn_renewal_cycles.guarantee_deadline_at` | R2 `activate_manual_guarantee`; the value of *n* remains class B (`MANUAL_RENEWAL_SLOT_GUARANTEE_WEEKS`) |
| Provider neutrality, no fake Lessons, no fake entitlement, payer ≠ beneficiary, Phase-M/O allowance separation | — | existing Platform authorities (Phases M/O/Q and this phase) | Contract tests assert them |

## 3. Class B — runtime configurable policies

Only these five keys may exist in `dzn_commercial_policies`. Each row is immutable and versioned;
a new value is a new version, and an unset policy is recorded explicitly with a null value.

| Policy identifier | Current value | Owner / enforcing seam | Notification intent it will drive | T&C subject |
|---|---|---|---|---|
| `INTRO_BOOKING_HORIZON` | 4 weeks (registered; not enforced in R1/R2) | Future booking-request intake policy; `CommercialPolicyService` owns the value | Introductory booking availability | Free introductory lesson and its booking horizon |
| `MANUAL_RENEWAL_SLOT_GUARANTEE_WEEKS` | 4 | Phase R2 renewal guarantee; `CommercialPolicyService` owns the value | `MANUAL_RENEWAL_PAYMENT_REQUIRED`, `GUARANTEE_DEADLINE_APPROACHING`, `GUARANTEE_EXPIRED` | Manual renewal and the same-slot guarantee deadline |
| `AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME` | **unset** | Phase R2 automatic charge scheduling: while unset `RecurringRule::automaticChargeAt()` returns null and no advance charge instant exists | `AUTOMATIC_RENEWAL_UPCOMING` | Advance notice before an automatic charge |
| `PAYMENT_RECOVERY_POLICY` | **unset** | Phase R2 recovery/lapse: while unset `RecoveryService::markLapsed()` refuses and capacity stays protected; only an explicit recorded administrator command may lapse | `PAYMENT_FAILED`, `PAYMENT_RECOVERED`, `TERM_LAPSED` | Failed-payment recovery |
| `INSTALMENT_DUE_DATE_POLICY` | **unset** | Phase R1 offer issuance reads an explicit authorised due instant when one is supplied; the policy value is deliberately not interpreted yet | `INSTALMENT_PAYMENT_REQUIRED`, `UPCOMING_INSTALMENT` | Two-instalment payment and the second instalment deadline |

**Never configurable here:** the Term session count, the Term change allowance, the tranche
structure, the tranche prerequisite order, the capacity-succession rule, refund academic
consequences, gift-card/stored-value rules and the automatic-renewal slot-protection behaviour.

## 4. Drift prevention

1. `CommercialRule::POLICY_KEYS` is the only allowlist, and it contains class-B keys only; a
   structural key is refused with `Structural invariants are not configurable commercial policies`.
2. The Phase-R1 contract test asserts the allowlist, the structural constants, the recorded-Term
   reads and the absence of any competing literal; the Phase-R2 contract test asserts that the three
   renewal/collection keys above are the only ones R2 reads, that `MANUAL_RENEWAL_SLOT_GUARANTEE_WEEKS`
   resolves to the locked 4-week value, and that no unset policy is silently defaulted.
3. Every offer snapshot records the class-B policy versions it applied, so a later policy change
   can never rewrite what a Student was charged or promised.
4. This document is asserted from source by the contract test (`COMMERCIAL-POLICY-REGISTRY.md` must
   exist); it is never parsed as configuration.

## 5. Terms & Conditions traceability (downstream workstream)

Final legal wording and current-law/consumer-law review remain separate from Platform work. The
following subjects must be aligned with the authorities named above before any customer-facing
wording is published: free introductory lesson; introductory booking horizon; the 12-session Term;
Regular versus Flexible scheduling; full payment; two-instalment payment and the consequence of an
unpaid second instalment; automatic renewal and advance charge notice; manual renewal and the
payment link; the four-week same-slot guarantee deadline; the guaranteed recurring slot for an
active automatic renewal; disabling automatic renewal versus cancelling the current Term; the two
Student class changes/deferments per Term; academy/teacher-caused non-delivery as a separate
authority; failed-payment recovery; promotions and discounts; refunds; the payment provider's role;
notification expectations; price and currency treatment; and applicable statutory rights.
