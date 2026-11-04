# Consent Lifecycle — Implementation, Audit and Release Evidence

**Branch:** `feat/consent-lifecycle-complete`
**Base:** `origin/main` (`f6b24445`)
**Status:** implementation complete for controlled staging review. Capture and ML export remain disabled. No merge, deployment, production recording or production dataset ingestion was performed.

## 1. Existing implementation audit

| Component | Classification | Evidence / defect |
| --- | --- | --- |
| Append-only recording decision ledger (`recording_decisions`, `recording_decision_commands`) | Implemented and verified | Migration 055, `RecordingDecisionAuthorityService`, behaviour and migration runtime tests. Replays and conflicts were already covered. |
| Immutable notice publication (`recording_notice_versions`, publication controller) | Implemented but incomplete | Only three audiences; no teacher recording or teacher research purpose. Publication was reachable only as a protected General link. |
| Teacher onboarding / account consent | Implemented but incomplete | Teacher terms only. No independent teacher lesson-recording or teacher ML research choice. |
| Adult student account / post-intro consent | Implemented and integrated | `RecordingDecisionUiReadService`, `recording-choices.php`, post-intro and Account Settings. Recording and research were already separate. |
| Guardian decisions | Missing (deliberately disabled) | `recordGuardianChoiceForResolvedStudent()` threw `recording_guardian_scope_unavailable`; the contract test encoded that refusal. Guardian authority grants already existed for service acceptance. |
| Recording enforcement gate | Missing | LiveKit participant tokens correctly set `roomRecord=false`, but no server-side consent gate existed before a capture request or dataset use. |
| Google Meet recording | Missing / blocked | No automated recording pathway existed. Any future pathway cannot enforce consent, so it is explicitly refused. |
| ML dataset eligibility and manifest | Missing | No dataset item provenance, eligibility result, exclusion state or export authority existed. |
| Withdrawal lifecycle | Implemented but incomplete | A withdrawal appended a new decision but created no downstream tasks, quarantine or re-evaluation. |
| Settings → Policies | Missing | The Settings redesign had four sections and no consent operations surface. |
| Consent notifications | Missing | The catalogue had no consent-required, recorded, policy-updated, withdrawal or administrator-attention events. |
| Legal policy pages | Implemented, not approved | Draft pages and the recording/research information route exist. Final wording remains owner/legal approval. |
| LiveKit classroom authority | Implemented and verified | Canonical lesson/schedule authority is re-proved per token. Recording was not part of that grant. |

Key defects found before implementation:

1. Guardian consent was unreachable even for a verified minor/guardian relationship.
2. Teacher consent had no separate ML research purpose; accepting recorded-work terms could be misread as research participation.
3. Notice audiences could not represent teacher recording or teacher research.
4. No gate connected a canonical Lesson and its participants to a capture decision.
5. No dataset eligibility authority, manifest or exclusion state.
6. Withdrawal did not stop or quarantine downstream material.
7. No administrative view of pending, withdrawn, blocked or failed consent work.
8. No consent notification events.
9. UI/contract tests asserted the old “guardian unavailable” and “four Settings sections” behaviour; these were updated with the feature rather than bypassed.

## 2. Changes delivered

### Schema and evidence model
- Migration `066_consent_lifecycle_authority` in `platform/src/Core/Infrastructure/Migration/Migrator.php`.
- Extended `recording_decisions` with guardian grant/version, statement digest, channel, workflow, scope, supersession/withdrawal references, lesson/enrolment association and effective period.
- Added `approval_state`, `approved_at` and `approved_by` to `recording_notice_versions`.
- Added `consent_purposes`, `consent_recording_authorisations`, `consent_dataset_items`, `consent_processing_jobs`, `consent_processing_events`.
- Seeded six purposes idempotently. The migration performs no decision backfill and never assigns consent to an existing user.

### Canonical services
- `ConsentPurposeRegistry` — dependency-free purpose definitions and purpose-specific positive/withdrawal semantics.
- `RecordingConsentPolicy` — pure, fail-closed recording decision table.
- `MlDatasetEligibilityPolicy` — pure, fail-closed dataset decision table.
- Extended `RecordingDecisionAuthorityService`:
  - separate teacher recording and teacher research decisions;
  - guardian decisions through an active canonical `guardian_representative` grant;
  - guardian grant ID/version and explicit consent statement evidence;
  - append-only supersession and withdrawal references;
  - adult self-service and minor guardian state resolution with ambiguity/capacity fail-closed behaviour.
- `RecordingAuthorisationGate` — canonical Lesson/participant/policy/guardian gate, immutable authorisation evidence, provider refusal for Google Meet, and a capture assertion seam.
- `MlDatasetEligibilityService` / `ConsentDatasetPort` — separate research eligibility, pseudonymised manifest, ingestion refusal, re-evaluation and capability-gated export.
- `ConsentWithdrawalService` — appends withdrawal and queues idempotent dataset/recording disposition work.
- `ConsentLifecycleWorker` — leased, retryable, bounded worker with truthful `no_capture_assets` recording outcome.
- `ConsentOperationsReadService` / `ConsentOperationsPort` — authoritative counts, pending queues, exceptions, jobs and evidence reads.
- `ConsentNotificationService` — canonical inbox recording plus a neutral action for the existing delivery authority; no direct transport.

### Portals and LiveKit
- `RecordingDecisionUiReadService` now exposes separate teacher terms/recording/research/privacy forms and adult/guardian student state.
- `RecordingDecisionController` gained the teacher-choice action; student withdrawal routes through the durable withdrawal service.
- Theme teacher and student consent surfaces updated for independent purposes, guardian authority, privacy acknowledgement, no preselected optional choices and RTL Persian.
- Booking review shows the authenticated consent form when an authorised principal exists; otherwise it states truthfully that binding consent is collected after identity/account resolution.
- `LiveKitClassroomAuthorizer` refuses `recording=true` unless the canonical gate authorises it. Participant tokens still cannot record (`roomRecord=false`).

### Administration
- `PoliciesController` at Settings → Policies (`dzn-policies`), capability `dzn_manage_consent_policies`.
- Policy library, version history, publication delegation to the existing immutable route, consent indicators, pending teachers/students, guardian attention, withdrawals, exceptions, job status, retry, dataset re-evaluation and access-controlled evidence export.
- No administrator write path creates, edits or overrides a decision.
- Settings shell now exposes five sections: General, Policies, Notifications, Integrations, Diagnostics.

### Notifications
- Catalogue events for teacher/student/guardian consent required, consent recorded, policy updated, withdrawal confirmed and administrator attention.
- Guardian inbox recipient resolution through the active canonical grant.
- Consent events are `partial` until their email/WhatsApp/SMS workflow and templates are approved and activated; the website inbox is recorded now.

## 3. Remaining blockers

### Critical
1. Owner and legal approval of final Persian/English recording, research, privacy and recorded-work notices; publication is an operator action and no text was invented as approved.
2. Legal approval of child/guardian consent, cross-border transfer, retention and withdrawal limitations.
3. Production recording provider, regions, deletion API, backup/version behaviour and monitoring agreement. Capture remains disabled.
4. A real-provider end-to-end capture/withdrawal/deletion test. No production media was created or deleted.

### High
1. LiveKit Egress (or another approved capture path) is not implemented; the gate and refusal seam exist.
2. Google Meet automated recording is disabled because it cannot be consent-enforced before capture.
3. Dataset storage/export is not connected to a real media pipeline; eligibility and manifest are implemented.
4. Consent email/SMS/WhatsApp delivery is catalogue-defined but not activated; only the canonical website inbox is recorded.
5. Existing production users are intentionally not backfilled. They receive the portal consent path and reminders; this must be operated after approval.

### Medium
1. Policy publication requires `manage_options`; staff with only the Policies capability can review but not publish.
2. Policy-publish notification fan-out is bounded to 200 active subjects per event; a production backfill should batch through the canonical notification worker.
3. Mobile/RTL screenshot evidence was not captured in this run; source and runtime rendering tests pass.
4. The consent worker is registered on the hourly `dzn_consent_processing` cron hook; confirm WP-Cron or the system scheduler is operating in the deployment environment.

### Low
1. Build identity is unchanged; update it in the release package step.
2. Additional purpose definitions require only a registry row and notice publication, not a schema change.

## 4. Test evidence

All commands were run against the feature branch with PHP 8.3 and disposable WordPress/MariaDB.

### Source/pure suite
```text
platform/runtime/bin/run-pure-tests.sh
```
Result: complete pass, including:
```text
Static PHP lint passed
Consent policy unit: PASS
Consent lifecycle contract passed
Recording choices UI contract passed
Recording decision authority contract passed
Recording notice publication contract passed
Settings redesign contract passed
Notification management contract/unit: PASS
Communications brand contract: OK
Admin navigation/shell contracts: PASS
```

### Disposable runtime suites
```text
platform/runtime/bin/run-consent-lifecycle-runtime.sh
  Consent lifecycle migration runtime: PASS
  Consent lifecycle behavior runtime: PASS
  Consent lifecycle runtime: PASS

platform/runtime/bin/run-recording-decision-behavior-runtime.sh
  Recording decision behavior runtime: PASS

platform/runtime/bin/run-recording-decision-migration-runtime.sh
  Recording decision migration runtime: PASS

platform/runtime/bin/run-recording-choices-ui-runtime.sh
  Recording choices UI runtime: PASS
```

The consent behaviour runtime proves, on a real WordPress database:

- teacher terms, teacher recording and teacher research are independent;
- adult student recording and research are independent;
- duplicate submissions replay and do not append;
- a complete participant set authorises recording; missing/declined/superseded/withdrawn facts deny;
- Google Meet recording is refused;
- a recording with recording consent but no research consent is refused for dataset ingestion;
- withdrawal quarantines and excludes the dataset item, and the worker completes the job;
- export excludes withdrawn material;
- a newer policy invalidates the old decision until a new one is recorded;
- teacher research withdrawal independently excludes material;
- a minor is authorised only through an active guardian grant, and revoking the grant denies the gate;
- authoritative admin indicators and evidence are readable;
- Settings → Policies renders without a decision-writing form.

### Failed scenarios encountered and resolved during development
- The first runtime run exposed a wrong argument ordering in the teacher decision call; corrected and re-run.
- Dataset export initially ran as a non-admin test user; the test now switches to the authorised capability, proving the gate rather than weakening it.
- A stale policy produced `recording_missing` instead of `policy_superseded`; the pure policy now distinguishes present-but-superseded evidence.
- Existing UI/authority contracts encoded “guardian unavailable” and old teacher copy; they were updated to the new intended behaviour rather than deleted.

## 5. Operational readiness

| Area | Verdict | Detail |
| --- | --- | --- |
| Teacher consent collection | Ready for controlled staging | Onboarding and Account Settings collect terms, recording and research independently. Blocked for production by approved notice text. |
| Student consent collection | Ready for controlled staging | Post-intro, booking (authenticated) and Account Settings. Adult self-service verified. |
| Guardian consent | Implemented and runtime verified | Active canonical grant required; revocation closes the writable state and gate. Production blocked by legal age policy. |
| LiveKit recording | Not ready | No capture/egress implementation; gate refuses unauthorised and unsupported providers. `CAPTURE_ENABLED=false`. |
| ML dataset ingestion | Not ready for production | Eligibility, manifest, exclusion and export gates are implemented; no real media/storage pipeline. `RESEARCH_EXPORT_ENABLED=false`. |
| Withdrawal and deletion | Partially ready | Decision, quarantine, durable retry and truthful no-asset result are implemented. Media deletion awaits a real capture provider. |
| Administration and monitoring | Ready for staging | Authoritative counts, exceptions, jobs, retry and evidence export. No consent fabrication path. |

## 6. Deployment plan

1. Review and merge only after approval. Do not enable capture or export.
2. Apply migrations in ledger order; `066_consent_lifecycle_authority` is additive and follows `065_confirmed_intro_cancellation`.
3. Verify `dzn_platform_schema_version` and the `065` ledger entry, then run `Migrator::verify_current_schema()`.
4. Confirm `CAPTURE_ENABLED=false` and `RESEARCH_EXPORT_ENABLED=false` in the deployed package.
5. Publish only owner/legal-approved notices from Settings → Policies (or the protected publication route) for Persian and English, per purpose.
6. Run smoke tests: teacher onboarding, adult account choices, minor guardian grant, withdrawal, Settings → Policies counts, denied capture, refused dataset ingestion.
7. Enable capture only after the provider agreement, real-provider test tenant, retention/deletion evidence and monitoring/rollback gates pass. Enable dataset export only after separate research/legal approval.
8. Rollback: disable the deployment/feature flags first. The schema is additive; leave tables in place for evidence preservation. Destructive rollback of evidence tables is not approved and would lose audit history.

## 7. PR details

- Branch: `feat/consent-lifecycle-complete`
- Base: `origin/main` (rebased onto `ee66e22a`)
- PR: https://github.com/Mr-M13/delnavazan/pull/512
- Verified implementation SHA: `44b2540fe8642f9e69a508dc632044960ef9f901`
- CI: PR runtime gate run `41546942241` succeeded for all jobs (PHP source contracts and all runtime suites). This final documentation-only commit re-runs the same gate.
- Merge state: `CLEAN` / `MERGEABLE` at verification. Review requested; **not merged**, **not deployed** and **not production-activated** by this run.
