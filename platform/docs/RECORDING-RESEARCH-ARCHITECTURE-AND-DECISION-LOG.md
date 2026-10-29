# Recording and Persian Music Research: Architecture and Decision Log

**Status:** proposed; capture and research export are disabled.  This document is not a consent notice,
legal advice, or authorisation to collect material.

## Release boundary

No recording provider may be invoked and no asset may enter research, a dataset, labelling, or model
training until the separately approved capture and research-export flags are enabled for an approved
cohort.  Both flags default to off.  A production provider is required for either flag; a contract or
test provider is never a fallback.

PR #380 is a rejected design reference, not a migration source.  Main has already released Schema 54.
A future complete authority must use the next unused migration number at merge time and may not alter,
reuse, or claim migration 053.

## Decisions requiring owner and legal review

The following values are deliberately unresolved and must be recorded as versioned decisions before
any feature flag can be enabled:

| Decision | Required approver | State |
| --- | --- | --- |
| Production recording provider, regions, capabilities, deletion API, version and backup behaviour | Owner and security/operations | Pending |
| English and Persian teacher terms and student notices, including the exact capture scope | Owner and legal | Pending |
| Jurisdiction-specific lawful basis for recording and each research/model use | Legal | Pending |
| Adult capacity and guardian-authority rules by jurisdiction | Legal and owner | Pending |
| Retention periods for raw audio, mixed recordings, metadata, labels, datasets, derived assets and backups | Legal, owner and operations | Pending |
| Whether any default-on recording choice is lawful for a cohort and provider | Legal | Pending |
| Research sharing, access controls, withdrawal/deletion limits and model-treatment statement | Legal, owner and security | Pending |

Until a reviewed decision exists, `unknown`, `unreviewed`, conflicting, expired, withdrawn and
unsupported states deny the affected capture or use.  Existing users are not backfilled as agreed.

## Canonical decisions and evidence

Each decision is append-only evidence, not a mutable consent flag.  A command derives its actor and
subject from authenticated server-side principal links.  Browser-supplied teacher, student, guardian
or principal IDs are ignored.

| Evidence | Subject | Purpose | Validity |
| --- | --- | --- | --- |
| Teacher terms acceptance | Canonical Teacher | recorded-work terms | Exact terms version, language, acceptance actor/time; required before assignment to recorded work |
| Student recording preference | Canonical Student | future lesson capture | Explicit allow or decline; unanswered is not allow |
| Guardian recording decision | Authorised guardian acting for Canonical Student | future lesson capture | Required where capacity rules require it; authority is revalidated |
| Student research participation | Canonical Student or authorised guardian | research and model training | Separate active opt-in; never inferred from recording permission |
| Guardian research decision | Authorised guardian acting for Canonical Student | research and model training | Separate from recording decision |
| Policy/notice snapshot | Capture/use event | scope and retention | Exact policy, notice, language and effective version used at that event |

A material purpose, legal, terms or notice change creates a new version and may require a new decision.
The product does not reprompt per lesson; it resolves stored evidence silently.

## Pre-capture gate

Before `capture()` is reachable, the authority locks and resolves the canonical Lesson, applicable
schedule version, current participant list and policy version.  It requires valid teacher terms,
recording authorisation for every participant, and guardian authority where applicable.  For a mixed
group recording, one ineligible participant denies capture unless a separately verified exclusion
mechanism proves that person cannot be captured.

The gate emits one of `authorised`, `skipped_unknown`, `skipped_declined`, `skipped_withdrawn`,
`skipped_guardian_unresolved`, `skipped_teacher_terms_missing`, `skipped_policy_unavailable`,
`skipped_provider_unavailable`, or a reviewed future code.  A skip is durable evidence and does not
change lesson delivery, attendance, enrolment, payment or scheduling.

Post-capture verification is a secondary safeguard only.  It cannot turn an unauthorised capture into
authorised material.

## Asset and retention model

A capture creates separate assets for raw audio, mixed recording, metadata, labels, dataset membership
and derived assets.  Each asset records its capture/use policy version, purpose, state, expiry, provider
object/version reference held in the reference vault, and deletion evidence.  Provenance points to a
set of participant authorisation evidence, never a single grant.

Deletion and expiry create retryable operations with a claim token, lease, attempts, partial-failure
state, provider acknowledgement, reconciliation and truthful counts.  An asset becomes `purged` only
after the Provider acknowledgement of the applicable deletion is verified.  Backups, provider versions and
model-training limits are reported truthfully; no unlearning promise is made unless a reviewed process
exists.

## Separate research gate

Every research export, dataset membership, labelling and model-training job resolves the asset's exact
participant evidence and policy snapshot.  Recording authorisation never satisfies research eligibility.
Every identifiable participant must have valid research participation or another reviewed lawful basis.
Withdrawal blocks future export and use immediately; treatment of already-exported material follows the
reviewed policy and is recorded per asset.

Practice uploads use the same purpose checks but exist only after a deliberate student submission.
No background practice capture is permitted.

## Delivery sequence

1. Architecture, legal decisions, notices, provider and retention schedule are approved.
2. A dedicated migration and identity-bound decision authority are independently reviewed and tested.
3. Capture gate and provider adapter are reviewed with capture disabled.
4. Research gate and asset/deletion workers are reviewed with export disabled.
5. English/Persian onboarding, enrolment, Account Settings and project page are reviewed against the
   archived notice versions.
6. A real-provider test tenant completes capture, skip, research inclusion/exclusion, withdrawal,
   deletion and retention journeys.
7. An authorised release deploys schema and choices with both flags off, verifies production identity
   and settings flows, then enables only approved purpose/cohort flags.

