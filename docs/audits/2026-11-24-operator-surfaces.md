# Operator-surface audit — 2026-11-24

Status: **read-only investigation.** No production change, no schema change, no menu change, no code
change accompanies this document except the read-only checker in `docs/audits/tools/`.

- Session: `ses_335B10DB-9BAD-44B9-BE00-B7116E6A0046` (`audit/operator-surfaces-20261124`)
- Base: `origin/main` at `74080db4` (merge of #785)
- Extends `docs/ADMIN-CLASS-WORKFLOW-AUDIT-2026-11-19.md` and
  `docs/ADMIN-CLASS-WORKFLOW-UX-SPEC.md`; judged against
  `docs/ENROLMENT-DESK-SPEC-2026-11-23.md`. It does not repeat the 2026-11-19 findings.

## 1. Method

The 2026-11-22/23 incident was not a list of unrelated bugs. Each symptom belonged to a class that
will produce the next incident. This audit therefore traces **operator journeys** to code and hunts
the classes:

| Class key | Meaning |
|---|---|
| `CALL-DRIFT` | a call site names a method the receiver does not declare |
| `TEST-PINS-BUG` | a contract test asserts the wrong spelling/shape and keeps the defect green |
| `UI-NARROWER` | the control offers fewer values than the domain accepts |
| `PROJECTION` | a read model contradicts the authority it summarises |
| `WRONG-SOURCE` | a diagnostic reads a legacy or local source instead of the source of truth |
| `NO-PATH` | a guard refuses, and no authorised path exists to satisfy it |
| `IRREVERSIBLE` | a state has no exit |
| `DEAD-END` | an object can be created that can never be completed or corrected |
| `CLOCK` | a fixture derives a fact from the wall clock |
| `OVERCLAIM` | a surface states a stronger fact than the evidence supports |
| `SILENT` | a refusal or failure the operator never sees |

**Evidence rules used here.** Every finding has a `file:line` from this tree or a command output
quoted in §2. Where a claim depends on reachability I say so and give the caller chain. Nothing is
inferred from method names, the docs, or green CI.

### What was deliberately not verified

- No runtime suite was executed. The suites need Docker and are CI-only for sessions
  (`AGENTS.md` §2.2). The *content* of the fixtures was inspected instead.
- No production database was read. Statements about live rows are described as predicates over the
  schema, not as observations.
- The theme's portal JavaScript behaviour was not exercised; only the server-rendered controls were
  read.
- WordPress capability inheritance (`administrator` role) was read from `Migrator.php`, not from a
  live site.

## 2. The cheap checks, and what they returned

### 2.1 Call sites vs definitions (`CALL-DRIFT`)

Written as `docs/audits/tools/method-call-check.php` (read-only, no writes, exits 1 on a finding).
It resolves `$this->m()`, `$this->prop->m()`, `$var->m()` (from `new Type(`, typed parameters,
`@var`, `instanceof`) and `Type::m()`, then reports a call only when the receiver's class is known in
the tree and declares no such method. Vendor parents and `__call` classes are skipped so the output
stays trustworthy.

Current tree:

```text
$ php docs/audits/tools/method-call-check.php --root=platform/src --verbose
Scanned 811 files, 829 classes/traits.
Resolved 20656 typed call sites; skipped 6671 whose receiver type is not in the tree.

UNDEFINED METHOD CALLS: 3
platform/src/Admin/Controller/ScreenController.php:308   self::teacherInvitationPanel()
platform/src/Admin/Controller/ScreenController.php:315   self::teacherDirectory()
platform/src/Core/Application/GodMode/GodModeAttendanceVerifier.php:54
    $this->repository->participantMappings()
    receiver type: …\GoogleWorkspaceRepository — declares no such method
```

Recall proof — run against the commits *before* the two historical hotfixes:

```text
$ git worktree add --detach /tmp/ops-recall 41c99027^ && php check.php --root=platform/src
platform/src/Core/Application/AdministratorIntroBookingService.php:207
    $requests->studentHasActiveInstrumentEnrollment()
    receiver type: …\BookingRequestRepository — declares no such method

$ git worktree add --detach /tmp/ops-recall2 18429228^ && php check.php --root=platform/src
platform/src/Integrations/GoogleWorkspace/MeetRecordingService.php:256
    $this->repository->recordings()
    receiver type: …\GoogleWorkspaceRepository — declares no such method
```

Both are the exact defects recorded in PRs #773 and #772/#773's follow-up. The checker finds them
without knowing the fix.

### 2.2 Spelling drift

```text
$ grep -rn "Enrollment\|cancelation\|recieve\|seperate" platform/src
platform/src/Core/Application/BookingRequestInstrumentEnrollmentException.php:5
platform/src/Core/Application/BookingRequestSubmissionService.php:106
platform/src/Public/BookingRequestRestController.php:10,107
```

One live drift: the exception class uses the American `Enrollment` while the rest of the platform
uses `Enrolment`. It is internally consistent, so it does not fatal, but it is exactly the pattern
that produced the 2026-11-22 bug and it was not caught by any test. See `OPS-07`.

### 2.3 Silent guards in render paths

```text
$ grep -rn "return;" platform/src/Admin/View platform/src/Admin/Controller | wc -l
319
```

Most are legitimate empty-state returns that print a sentence first. The dangerous subset is
`catch (\Throwable) { … }` in a *control builder*: 21 occurrences in
`platform/src/Admin/RecordAction/RecordActionRegistry.php` (`:798`, `:808`, `:815`, `:825`, `:832`,
`:842`, `:849`, `:859`, `:866`, `:876`, `:883`, `:893`, `:918`, `:943`, `:949`, `:959`, `:966`,
`:976`, `:983`, `:997`, `:1003`). See `OPS-15`.

### 2.4 Irreversible transitions

```text
$ grep -n "'cancelled'\|'closed'\|'expired'\|'declined'\|'skipped'" \
       platform/src/Core/Application/BookingJourneyReadService.php
156: if ($state === 'cancelled') return $this->make('closed', 'The intro was cancelled.', …)
168: resolution_state === 'declined' → declined
171: otherwise → closed
```

`BookingJourneyReadService.php:156` maps a cancelled intro to `closed` with the single action
`View history` and no `more` actions. See `OPS-10`.

### 2.5 Clock-dependent fixtures

```text
$ grep -rn "strtotime('+\|strtotime(\"+\|gmdate('Y-m-d'.*20[0-9][0-9]" platform/tests platform/runtime | wc -l
94 `strtotime('+…)` calls; 742 lines carry an absolute date literal
```

Representative: `platform/tests/phase-2a2e-concurrency-fixture.php:9`,
`platform/tests/phase-2a2n-concurrency-setup.php:47`,
`platform/tests/enrolment-workspace-runtime-fixture.php:75`. See `OPS-20`.

### 2.6 Duplicate command entry points

```text
$ grep -rn "AdministratorIntroBookingService\|recordAdministrativeCompletion\|continueAsAdministrator\|markStandardPaid" platform/src | grep -v tests
EnrolmentWorkspaceController.php:330   AdministratorIntroBookingService::create
EnrolmentWorkspaceController.php:397   PostIntroPreparationService::recordAdministrativeCompletion
AdministratorAgreementService.php:208  PostIntroPreparationService::recordAdministrativeCompletion
EnrolmentDeskController.php:83         PostIntroContinuationService::continueAsAdministrator
AdministratorAgreementService.php:228  PostIntroContinuationService::continueAsAdministrator
FinanceAdminController.php:426         ManualPaymentSettlementService::markStandardPaid
EnrolmentDeskController.php:110        ManualPaymentSettlementService::markStandardPaid
PostIntroContinuationService.php:719  ManualPaymentSettlementService::markStandardPaid
```

Each pair has its own capability check. That is where `OPS-26` lives: the same money command is
offered behind a capability that the downstream authority does not accept.

### 2.7 Referenced anchors

```text
$ grep -rno "dzn-[a-z-]*" platform/src/Admin/View | sort -u | head
dzn-coordination (72)  dzn-enrolment (54)  dzn-coverage (54)  dzn-clock (54) …
```

Most are CSS class names, so the raw comparison is noise. The Desk's deep links are the meaningful
set and they were checked by hand against `id="…"`: `dzn-record-outcome`,
`dzn-intro-complete`, `dzn-execute-agreement`, `dzn-create-intro` all exist in
`platform/src/Admin/View/EnrolmentWorkspaceView.php` and are pinned by
`platform/tests/enrolment-desk-contract.php`. No dead anchor found.

### 2.8 Refusal coverage (not in the brief; added because it is the highest-yield UX check)

```text
$ # every snake_case refusal thrown in Core/Application + Integrations,
$ # then whether the literal appears anywhere in platform/src/Admin
distinct snake_case refusal codes thrown: 1162
not present as a quoted string anywhere in platform/src/Admin: 926
```

The admin layer can therefore only explain 20% of the refusals the domain can produce. The rest fall
through the generic fallback at `platform/src/Admin/Controller/EnrolmentWorkspaceController.php:837`.
See `OPS-11`.

## 3. Journey maps

Each map is the sequence an operator actually performs, the control they use, and the refusal path.

### 3.1 Enrolment — intake → booking → intro → outcome → payment → term

```
Student self-service
  └─ submitPublic()                                  BookingRequestSubmissionService::submitPublic()
       refuses: validation sentence (mapped)          BookingRequestValidationService
Operator: Desk list
  └─ EnrolmentDeskController::screen()               gated by dzn_use_enrolment_desk
       people() → bookingCases()                      requires dzn_view_booking_requests
                                                  │   or dzn_manage_booking_request_coordination
       confirmedIntros() → BookingJourneyReadService::status()
                                                  │   requires one of five other capabilities
Operator: Desk detail  (person())
  └─ steps() + paymentFacts()                       no journey status — workspace stage only
Operator: step 2 — create/agree
  └─ dzn-enrolment-workspace &dzn_open=intro#dzn-create-intro
       create_intro    AdministratorIntroBookingService::create()
       total_override  BookingTeacherResponseService::administratorTotalOverride…
       execute_agreement AdministratorAgreementService::execute()
Operator: step 3 — intro
  └─ intro_reschedule  IntroLessonLifecycleService::reschedule()
  └─ intro_cancel      IntroLessonLifecycleService::cancel()
Operator: step 4 — outcome
  └─ record_outcome                        PostIntroPreparationService
  └─ intro_administrative_completion       recordAdministrativeCompletion()
Operator: step 5 — payment
  └─ send_payment_link   continueAsAdministrator()            needs dzn_manage_post_intro_automation
  └─ record_offline_payment  markStandardPaid()               needs dzn_manage_payment_execution
                                                          AND dzn_ingest_commercial_payment_evidence
Operator: term
  └─ PostIntroActivationWorker → PostIntroActivationService::activate()   (queued, not synchronous)
```

Refusal paths that matter:

| Step | Refusal | What the operator sees |
|---|---|---|
| Desk list | any of the read capabilities missing | **uncaught `RuntimeException('Unauthorized')`** (`OPS-03`) |
| Desk status | unknown stage key | raw key as the label (`OPS-01`) |
| outcome | future confirmation | allowed by design (`recordAdministrativeCompletion`) |
| cancel | — | succeeds; the journey then has no next action (`OPS-10`) |
| payment | delegate lacking ingest | generic "You do not have the authority…" (`OPS-26`) |

### 3.2 Class admin — schedule, reschedule, attendance, completion, finance capture

```
Classes list            dzn-lesson → ScreenController::screen('lesson')
  └─ entityList()/entityDetail()        via RecordActionRegistry actions
Class change            class_reschedule → CanonicalLessonScheduleService::revise()/schedule()
                        class_change_teacher
Class cancel            class_cancel
Delivery                class_delivery → CanonicalLessonDeliveryService::record()
                          refuses occurrence_not_started / occurrence_not_ended
Attendance review       class_attendance → CanonicalAttendanceIntakeService::adjudicate()/reassess()
Finance capture         delivery outcome feeds statements via the delivery authority
```

Refusal paths:

| Control | Refusal | What the operator sees |
|---|---|---|
| Record delivery | class has not reached its scheduled **end** | generic "The action could not be completed" (`OPS-11`, `OPS-12`) |
| Change teacher | `active_future_schedule_exists` | mapped sentence |
| Any record action | option builder throws | empty select, no message (`OPS-15`) |
| Legacy record actions | capability missing | button hidden (predicate returns false) |

### 3.3 Finance — manual payments, statements, refunds, reconciliation

```
Finance screen            dzn-finance → FinanceAdminController
  ├─ manual payment       ManualPaymentSettlementService::markStandardPaid/markIntroPaid
  │                        requires dzn_manage_payment_execution
  │                        AND, inside CommercialPaymentService::ingest,
  │                        dzn_ingest_commercial_payment_evidence
  ├─ refund               ManualRefundService::record     (obligation only)
  ├─ refund review        RefundReviewService::routeForReview/resolve/dismiss
  ├─ statement            TeacherStatementPaymentService::record
  └─ cashbook             OperationalCashLedgerService::record/reconcile/reverse
Reconciliation            dzn-finance-reconciliation → view-only
Legacy/imported term      TermService::create(payment_state=…)
```

Refusal / dead-end paths:

| Surface | Condition | Result |
|---|---|---|
| manual payment, delegate with only "Execute payments" | ingest capability absent | fails after submit (`OPS-26`) |
| imported unpaid term | no offer/obligation | no settlement path exists (`OPS-13`) |
| finance exception | any | visible, no resolve action; resolving service has no caller (`OPS-24`) |
| legacy term `payment_state` | any | operator-asserted money with no evidence (`OPS-25`) |
| refund on an un-activated term | allowed | the term is not reversed (`OPS-25` note) |

### 3.4 Notifications

```
Operator: Notifications → per-event channel grid
  └─ readiness            NotificationTemplateLibrary::status()
       provider truth     TelnyxTemplateAuthority (status='APPROVED')
       BUT manual mapping  NotificationTemplateLibrary.php:190-193 → provider_state='mapped'
Operator: Diagnostics → register
  └─ Retry exact notification   only for state='failed'; 'expired' shows "Window closed — no retry"
Operator: Manual send
  └─ "Manual send complete: N sent"   N = adapter acknowledged, not delivered
Operator: case page
  └─ "Sent to transport" / "Provider receipt: <state>"
```

### 3.5 Teacher and Student portals

Read at the server-rendered controls only. The Teacher intro form uses a native
`<input type="time">` (`theme/template-parts/teacher-portal/intro-actions.php:76`) — minute
granularity. The Admin equivalent is a 15-minute `<select>` (`OPS-14`). No portal surface was found
that offers an action the role cannot perform; the portal capability guards are on every POST
handler inspected.

### 3.6 Capabilities

Menu, bundles and screens were cross-checked. Two delegation systems exist and disagree:
`StaffAccessService` (work-area checkboxes) and `AccessRoleService` (role templates over
`PermissionRegistry`).

| Check | Result |
|---|---|
| Menu pages with no capability check | none (all 34 re-check; listed in the audit trail) |
| Route cap ≠ callback cap | 3 screens (`OPS-28`) |
| Capability granted to nobody | none, except `dzn_access_god_mode`, which is intentionally bound to one account (`GodModeAuthority.php:44`) |
| Capability in the catalogue that opens no screen | several; concrete examples in `OPS-30` |
| "View" that grants write | 6 areas (`OPS-27`) |
| Desk capability reachable from a role template | no (`OPS-29`) |
| Control rendered without its capability | Desk payment form (`OPS-04`), offline-payment command (`OPS-26`) |
| Denial that renders nothing | 8 screens (`OPS-31`) |

## 4. Findings

Ranked by **operator time lost × frequency**, not by code aesthetics.

**Severity meaning.** `blocks money` — an operator cannot record or correct money. `blocks
enrolment` — a Student cannot be moved forward. `confusing` — the operator cannot tell what is true
or what to do. `cosmetic` — wrong word, no work lost.

| ID | Surface | Symptom (one line) | Class | Severity | Fix size |
|---|---|---|---|---|---|
| OPS-01 | Enrolment → Desk list | statuses read `Teacher match`, `Post intro`, `Intro outcome` | PROJECTION | confusing, daily | one PR |
| OPS-02 | Enrolment → Desk detail | list says "Intro booked", detail says "Intro scheduled" | PROJECTION | confusing | one PR |
| OPS-03 | Enrolment → Desk | delegated Desk user gets an uncaught Unauthorized | capability | confusing (latent) | one PR |
| OPS-04 | Enrolment → Desk payment | "Send payment link" shown without its capability | capability | confusing | one PR |
| OPS-05 | Enrolment → Desk payment | malformed received-date silently becomes today | SILENT | confusing, audit | one PR |
| OPS-06 | God Mode attendance | verifier calls a method on the wrong repository → fatal | CALL-DRIFT | blocks a feature | one PR |
| OPS-07 | Teachers screen (dead branch) | calls two deleted methods | CALL-DRIFT | cosmetic (latent) | one PR |
| OPS-08 | Overview pipeline | "Coordination needed" still counts a booked intro | PROJECTION | confusing, daily | one PR |
| OPS-09 | Overview / case page | unknown stage silently reads "New request" | PROJECTION | confusing | one PR |
| OPS-10 | Enrolment → cancel intro | cancelled intro has no next action on the Desk | IRREVERSIBLE | confusing | decision |
| OPS-11 | All admin commands | 926/1162 refusal codes have no sentence | SILENT | confusing, daily | ratchet + audit |
| OPS-12 | Class admin → delivery | class that ended early cannot be recorded until its scheduled end | NO-PATH | confusing | decision |
| OPS-13 | Enrolment → import | imported unpaid Term can never be paid | DEAD-END | blocks money | decision |
| OPS-14 | Enrolment → reschedule/agreement | 15-minute grid cannot express an accepted minute | UI-NARROWER | confusing | one PR |
| OPS-15 | Class/Student record actions | empty select with no explanation | SILENT | confusing | one PR |
| OPS-16 | Notifications (internal) | `outbox.status='delivered'` written on transport ACK | OVERCLAIM | cosmetic (latent) | one PR |
| OPS-17 | Notifications → manual send | "N sent" counts transport ACK | OVERCLAIM | confusing | one PR |
| OPS-18 | Notifications → WhatsApp | "mapped" can come from a local manual mapping | WRONG-SOURCE | confusing | one PR |
| OPS-19 | Notifications → retry | `expired` retryable but UI says no retry; two queues have none | NO-PATH | confusing | one PR |
| OPS-20 | CI fixtures | wall-clock-derived dates remain | CLOCK | release risk | one PR (harness) |
| OPS-21 | Enrolment → Desk payment | Desk can only send full-term, pending | UI-NARROWER | confusing | decision |
| OPS-22 | Contracts | the intro contract still asserts the call-site spelling | TEST-PINS-BUG | release risk | one PR |
| OPS-23 | Contracts | the Desk contract asserts labels but not the key domain | TEST-PINS-BUG | release risk | one PR |
| OPS-24 | Finance → reconciliation | exceptions visible, nothing can resolve them | DEAD-END | blocks money | decision |
| OPS-25 | Finance → legacy/imported Term | `payment_state` is operator-asserted money | OVERCLAIM | blocks money | decision |
| OPS-26 | Finance / Desk → offline payment | "Execute payments" alone is refused by the service | capability | blocks money | one PR |
| OPS-27 | Delegation → any area | a "View" tick grants the write capability | OVERCLAIM | authority | decision |
| OPS-28 | Menu → three screens | a bundle opens the page, the callback refuses it | capability | confusing | one PR |
| OPS-29 | Delegation → Academy coordinator | the built-in coordination role cannot open the Desk | capability | blocks enrolment | one PR |
| OPS-30 | Delegation → finance/commercial | capabilities are granted that open no screen | DEAD-END | confusing | one PR |
| OPS-31 | Several finance screens | unauthorised access renders a blank page | SILENT | confusing | one PR |

---

### OPS-01 — The Desk lists raw stage keys as statuses

| Field | Content |
|---|---|
| Surface / journey | Enrolment → Desk list (`?page=dzn-enrolment-desk`) |
| Symptom | Rows read **"Teacher match"**, **"Intro outcome"**, **"Post intro"**, **"Converted"**, **"Expired"**, **"Provisioning failed"**, **"Intro payment pending"** instead of the spec's "Waiting for teacher / Outcome due / Waiting for payment / Active term". |
| Root cause | `platform/src/Core/Application/EnrolmentDeskReadService.php:38-48` looks up `self::STATUSES[$stageKey]`; the table (`:19-31`) only has the *journey* keys (`identity,new,awaiting_teacher,trial_scheduled,provisioning,outcome_due,continuation,enrolled,declined,closed,needs_review`). But `decorate()` (`:279`) is fed the *workspace* keys produced by `EnrolmentWorkspaceReadService::bookingStage()` (`:1124-1173`: `archived,closed,converted,expired,post_intro,intro_outcome,intro_scheduled,provisioning_failed,provisioning_pending,intro_payment_pending,teacher_match,teacher_agreed,identity,new`). Eleven of those keys are absent, so the fallback at `:42-47` renders `ucfirst(str_replace('_',' ',$stageKey))`. The wrong assumption: two different key vocabularies were assumed to be one. |
| Class | PROJECTION |
| Severity | confusing, on every intake row, every day |
| Evidence | `EnrolmentDeskReadService.php:38-48` + `:279`; `EnrolmentWorkspaceReadService.php:1055` (`'stage_key' => $stage['key']`) and `:1124-1173`. The workspace already computes the right answer and the Desk throws it away: `composeBookingRow()` also returns `stage_label`. |
| Proposed fix | Map through the existing shared function, `BookingJourneyReadService::statusForStage()`, exactly as `EnrolmentWorkspaceReadService.php:256` already does, and delete the duplicate table; or make `decorate()` prefer `stage_label`. No new vocabulary. |
| Test that would have caught it | A unit test that feeds **every key `bookingStage()` can return** into `EnrolmentDeskReadService::status()` and asserts the label is in the approved vocabulary — see `OPS-23`. |

### OPS-02 — The Desk list and the Desk detail use different status models

| Field | Content |
|---|---|
| Surface / journey | Enrolment → Desk list vs Desk detail, same person |
| Symptom | A confirmed intro reads **"Intro booked"** in the list and **"Intro scheduled"** on its own page. The status word changes when the operator clicks the row. |
| Root cause | `people()` overwrites the row's stage key with the journey status for confirmed intros — `platform/src/Core/Application/EnrolmentDeskReadService.php:131-136` (`$case['stage_key'] = $status['status']`). `person()` does not: `:147-161` builds the desk status from `$case['stage_key']` returned by `EnrolmentWorkspaceReadService::bookingCase()`. Two projections, one screen. |
| Class | PROJECTION |
| Severity | confusing |
| Evidence | `EnrolmentDeskReadService.php:131-136` vs `:151-157`. |
| Proposed fix | Resolve the journey status once, inside `person()`, and use it for both paths (the Desk contract already declares `BookingJourneyReadService` the authority at `platform/tests/enrolment-desk-contract.php:140`). |
| Test | Assert `people()` and `person()` return the same `desk_status` for the same request id across a synthetic matrix. |

### OPS-03 — The Desk's own capability is rejected by every read service it calls

| Field | Content |
|---|---|
| Surface / journey | Enrolment → Desk, delegated user |
| Symptom | A user granted only `dzn_use_enrolment_desk` opens the Desk and gets an uncaught `RuntimeException('Unauthorized')` (HTTP 500), not a refusal sentence. |
| Root cause | `EnrolmentDeskController.php:25` admits the Desk capability; `EnrolmentDeskReadService::people()` then calls `EnrolmentWorkspaceReadService::bookingCases()` (`EnrolmentDeskReadService.php:62`) which requires `dzn_view_booking_requests` **or** `dzn_manage_booking_request_coordination` (`EnrolmentWorkspaceReadService.php:37`, enforced at `:2307-2310`). Confirmed intros additionally call `BookingJourneyReadService::status()` which requires one of five *other* capabilities (`BookingJourneyReadService.php:65`). `dzn_use_enrolment_desk` is in none of those lists. |
| Class | capability (also SILENT, because nothing catches it) |
| Severity | confusing today, **hard block** the moment a narrower grant exists |
| Evidence | `grep -rn dzn_use_enrolment_desk platform/src` returns only `EnrolmentDeskController.php:17`, `StaffAccessService.php:55`, `Migrator.php:1497-1498` and the contract test. It never appears in a `requireAny` list, and it is **absent from `PermissionRegistry`** (`grep -c dzn_use_enrolment_desk PermissionRegistry.php` → 0), so no role template can grant it either (see `OPS-29`). Reachability today: the only grant path, the Enrolment **manage** bundle (`StaffAccessService.php:52-56`), also grants `dzn_manage_enrolments`, so the crash is latent for that path. It is **not** latent for the role templates — see `OPS-29`. The Desk spec's decision 4 ("granted to the current coordination role and available to delegates") is not honoured by the code. |
| Proposed fix | Add `dzn_use_enrolment_desk` to the `requireAny` lists the Desk traverses, **or** drop the separate capability and gate the Desk on the read capability it actually needs. Pick one; the current pair is incoherent. |
| Test | A capability-matrix test: for each bundle in `StaffAccessService`, call each screen's read path with exactly that bundle's caps and assert no uncaught exception. |

### OPS-04 — "Send payment link" is rendered without the capability its handler requires

| Field | Content |
|---|---|
| Surface / journey | Enrolment → Desk → Payment |
| Symptom | A Desk user without `dzn_manage_post_intro_automation` sees an enabled **Send payment link** form, fills it in, submits, and is told "You do not have the authority for that action." |
| Root cause | `platform/src/Admin/View/EnrolmentDeskView.php:111-119` renders the form with no capability check; the handler requires it at `EnrolmentDeskController.php:70`. The sibling form *does* check its capability two branches later (`EnrolmentDeskView.php:127`), so the omission is local, not a policy. |
| Class | capability / SILENT |
| Severity | confusing |
| Evidence | `EnrolmentDeskView.php:111-119` (no `current_user_can`) vs `EnrolmentDeskController.php:70`; contrast `EnrolmentDeskView.php:127-130`. |
| Proposed fix | Guard the form exactly like the sibling: if `! current_user_can('dzn_manage_post_intro_automation')`, print the one-sentence reason and stop. |
| Test | Render the payment step with a user lacking the capability and assert the button is absent. |

### OPS-05 — A malformed received-date silently becomes today

| Field | Content |
|---|---|
| Surface / journey | Enrolment → Desk → Record offline payment |
| Symptom | The operator types a wrong date (or a browser sends nothing); the payment is recorded with **today's** date and the operator is told it succeeded. |
| Root cause | `platform/src/Admin/Controller/EnrolmentDeskController.php:101-104`: if `received_on` does not match `YYYY-MM-DD`, it is replaced with `gmdate('Y-m-d')` instead of refused. `ManualPaymentSettlementService::occurredAt()` would have validated it (`ManualPaymentSettlementService.php:46`). |
| Class | SILENT |
| Severity | confusing; the audit record's date is wrong |
| Evidence | `EnrolmentDeskController.php:101-104`; the finance service validates the same field at `ManualPaymentSettlementService.php:128-137`. |
| Proposed fix | Refuse with the existing sentence ("Record the date the payment was received") instead of substituting. |
| Test | Post a malformed date and assert a refusal and no settlement row. |

### OPS-06 — God Mode attendance verification calls a method on the wrong repository

| Field | Content |
|---|---|
| Surface / journey | God Mode → test-class participant evidence |
| Symptom | `Call to undefined method GoogleWorkspaceRepository::participantMappings()`; the verifier returns HTTP 500 instead of participant evidence. |
| Root cause | `platform/src/Core/Application/GodMode/GodModeAttendanceVerifier.php:54` calls `$this->repository->participantMappings()`, but `$this->repository` is typed `GoogleWorkspaceRepository` (`:12`) and the method is declared on `GodModeRepository` (`platform/src/Core/Application/GodMode/GodModeRepository.php:140`). |
| Class | CALL-DRIFT |
| Severity | blocks a feature (owner-only diagnostic) |
| Evidence | `docs/audits/tools/method-call-check.php` output in §2.1; `grep -rn participantMappings platform/` returns only `GodModeRepository.php:140`, the verifier call, and `platform/tests/god-mode-runtime.php:39` (which uses the right class, so the suite is green). |
| Proposed fix | Inject/instantiate `GodModeRepository` for the mapping read, or move the method. Smallest correct change: give the verifier a `GodModeRepository` dependency for this one call. |
| Test | A contract test in `platform/tests/google-meet-classroom-contract.php` style: parse every `$this->repository->m(` in the verifier against the declared class — the pattern #773 already introduced for `MeetRecordingService`. |

### OPS-07 — Two calls to methods that were deleted on 2026-11-02 (+ the spelling drift that will cause the next one)

| Field | Content |
|---|---|
| Surface / journey | Teachers legacy branch of `ScreenController::entityList()` |
| Symptom | None today. If the early return at `ScreenController.php:60` is ever removed, the Teachers screen fatals. |
| Root cause | Commit `3ca0bcea` ("consolidate administration workspace") deleted `teacherDirectory()` and `teacherInvitationPanel()` but left the calls at `platform/src/Admin/Controller/ScreenController.php:308,315`. `screen('teacher')` returns at `:60` before `entityList('teacher')` can run, which is the only reason this is latent. Separately, `BookingRequestInstrumentEnrollmentException` (`platform/src/Core/Application/BookingRequestInstrumentEnrollmentException.php:5`) uses `Enrollment` while every other identifier uses `Enrolment`. |
| Class | CALL-DRIFT |
| Severity | cosmetic today, blocks the Teachers screen if the guard moves |
| Evidence | §2.1 output; `git log --oneline -S "self::teacherDirectory" -- platform/src` → `3ca0bcea`, `156851e1`; deletion visible in `git show 3ca0bcea -- platform/src/Admin/Controller/ScreenController.php`. |
| Proposed fix | Delete the two dead calls (and the dead `teacher` branch). Rename the exception in its own PR (class + 3 references) with a contract test that spells `Enrolment`. |
| Test | Add `docs/audits/tools/method-call-check.php` to the PR gate's production-tooling step (governance PR; see §6). |

### OPS-08 — The pipeline predicates are not mutually exclusive; a booked intro still counts as "Coordination needed"

| Field | Content |
|---|---|
| Surface / journey | Overview → pipeline cards and counts |
| Symptom | A confirmed introduction appears in **both** "Introduction booked" and "Coordination needed"; the counts sum to more than the number of people. This is the same contradiction #785 fixed for "Acceptance pending", left in the sibling stage. |
| Root cause | `platform/src/Core/Application/AcademyJourneyReadService.php:43` defines `needs_coordination => 'ta.id IS NULL AND cc.id IS NULL'`, while `:58-60` defines `intro_booked => 'ta.id IS NULL AND EXISTS (… confirmation …)'`. `stageSql()` adds the "not a booked intro" exclusion only to `needs_acceptance` (`:49-56`). The class comment at `:36-40` still claims "a booking with several missing links counts in exactly one stage", which stopped being true when `intro_booked` was added. Confirmed intros created by the total-override and administrator-intro paths have **no coordination case** (`grep -n coordination BookingTeacherResponseService.php` → capability checks only; same for `AdministratorIntroBookingService.php`), so `cc.id IS NULL` holds. |
| Class | PROJECTION |
| Severity | confusing, on every Overview load |
| Evidence | `AcademyJourneyReadService.php:34,43,49-60,103-119`. |
| Evidence of the product intent | `docs/ADMIN-CLASS-WORKFLOW-AUDIT-2026-11-19.md` §3 F3: "the system has several parallel state machines … there is no single journey-status model". |
| Proposed fix | Apply the same `NOT EXISTS (… state IN ('confirmed','awaiting_payment'))` exclusion to `needs_coordination` (and audit `needs_identity`/`needs_enrolment` for the same overlap), then replace the comment with an assertion. |
| Test | A unit test over the seven predicates with a synthetic row matrix asserting **exactly one** true stage per row. |

### OPS-09 — An unknown stage silently reads "New request"

| Field | Content |
|---|---|
| Surface / journey | Overview, Enrolment queue, case page |
| Symptom | Any stage key the map does not know is presented to the operator as a brand-new request. |
| Root cause | `platform/src/Core/Application/BookingJourneyReadService.php:46-57`: `statusForStage()`'s `match` ends `default => 'new'`. |
| Class | PROJECTION / SILENT |
| Severity | confusing |
| Evidence | `BookingJourneyReadService.php:56`. |
| Proposed fix | Return a `needs_review` status with the raw key as secondary detail. A silent default is how the next stage misses the map. |
| Test | Assert `statusForStage('anything_unknown')` is not `new`. |

### OPS-10 — A cancelled intro is terminal, and the Desk offers no next action

| Field | Content |
|---|---|
| Surface / journey | Enrolment → Cancel intro → Desk |
| Symptom | After a cancellation the Desk shows "Closed" and the only action is **View history**. Nothing says a new request is the way forward. |
| Root cause | `platform/src/Core/Application/IntroLessonLifecycleService.php:82-83` sets the request lifecycle to `cancelled` and closes the case; there is no restore command. `BookingJourneyReadService.php:156` maps `cancelled` → `closed` with `primary=View history` and empty `more`. The workspace says it aloud — `EnrolmentWorkspaceReadService.php:1142` `'next_action' => 'No further action; …'`. The information needed to reverse exists but is unused: `IntroLessonLifecycleService.php:77-78` writes `previous_request_lifecycle` / `previous_request_resolution`, and `grep -rn previous_request_lifecycle platform/src` finds no reader. |
| Class | IRREVERSIBLE |
| Severity | confusing; the Student is not enrolled and the operator has no stated route |
| Evidence | `IntroLessonLifecycleService.php:77-83`; `BookingJourneyReadService.php:156`; `EnrolmentWorkspaceReadService.php:1142`; `grep -rn "previous_request_lifecycle" platform/src` → only the writer and the migration. |
| Proposed fix | **Decision required** (see §5). Options: (a) on cancel, offer "Create a replacement request for this Student" prefilled from the cancellation evidence; (b) add an audited `reinstate` command using the recorded `previous_*` values, which would need a new authority and a governance note; (c) document "new request is the rebooking path" in the Desk copy (cheapest, keeps the invariant). |
| Test | A journey matrix asserting no terminal status has an empty action list, or that the empty list carries an explicit sentence. |

### OPS-11 — 926 of 1162 domain refusals have no operator sentence

| Field | Content |
|---|---|
| Surface / journey | Every admin command |
| Symptom | The operator is told "The action could not be completed; no change was saved." (plus an `EWI-` reference) instead of which control refused and why. This is the class behind the incident's opaque `EWI-…`, one level up. |
| Root cause | `platform/src/Admin/Controller/EnrolmentWorkspaceController.php:837-841`: the `match` has 97 explicit arms, then a regex that passes through sentences beginning with `The/A/An/This/No/Choose/…`, then generic fallbacks. Refusal codes in the domain are machine keys (`occurrence_not_ended`, `commercial_obligation_required`, `schedule_version_conflict`, …); 926 of 1162 never appear as a literal anywhere in `platform/src/Admin` (counted by `platform/tests/operator-refusal-coverage-contract.php`, which is now the authority). |
| Class | SILENT |
| Severity | confusing, on any unmapped refusal |
| Evidence | §2.8 command and output. Spot check: `occurrence_not_ended` is thrown at `CanonicalLessonDeliveryService.php:296` and appears nowhere in `platform/src/Admin`. |
| Proposed fix | Make mapping the *default*: give refusal codes a `label`/`next` at their definition (a small registry) and let one renderer print `label — next`. Do not grow the `match`. This is the Desk spec's goal 3 applied to the whole admin surface. |
| Test | A contract test that collects every `throw new \InvalidArgumentException('<snake_case>')` and asserts each has a registered operator sentence. That test is the deliverable, not the sentences. |

### OPS-12 — A class that ended early cannot be recorded until its scheduled end

| Field | Content |
|---|---|
| Surface / journey | Class admin → Record delivery |
| Symptom | A 60-minute class that finished after 40 minutes cannot be recorded for another 20 minutes: "The action could not be completed; no change was saved." |
| Root cause | `platform/src/Core/Application/CanonicalLessonDeliveryService.php:295-296` refuses while `starts_at_utc > now` and while `ends_at_utc > now`, with no override. The intro path received an authorised bypass (`PostIntroPreparationService::recordAdministrativeCompletion`, PR #774); the class path did not. |
| Class | NO-PATH |
| Severity | confusing, low frequency |
| Evidence | `CanonicalLessonDeliveryService.php:295-296`; the authorised intro bypass at `PostIntroPreparationService.php:62-106`; no equivalent in the class path (`grep -n "function record\|function correct\|function dispute\|function reconcile" CanonicalLessonDeliveryService.php`). |
| Proposed fix | **Decision required.** Either (a) allow `record()` once `starts_at_utc < now` with `evidence_channel='staff_record'` and a note (a delivered class cannot have been delivered before it started, but it can end early), or (b) provide a documented wait and map `occurrence_not_ended` to a sentence that says how long. Do not weaken the "no false attendance" invariant. |
| Test | Record a delivery at `starts_at + 1 minute` for a class that ended, and assert the defined behaviour. |

### OPS-13 — An imported unpaid Term can never be paid

| Field | Content |
|---|---|
| Surface / journey | Enrolment → Import existing enrolment → Finance |
| Symptom | The operator imports a historical course with `payment_status = unpaid`; no screen can later record money against it, and no screen can undo the import. |
| Root cause | `platform/src/Core/Application/HistoricalEnrolmentImportService.php:114-121` creates the Term via `TermService::create()` with `payment_state` taken from the form. No offer, obligation, purchase or settlement is created (the method body at `:60-178`). Every later settlement path resolves an offer first — `ManualPaymentSettlementService.php:50-51` `$offer = offerByUid(); if (!$offer) throw 'manual_payment_offer_required'`. The only reversal is the workflow-only `retire-imported-enrolment.yml` (AGENTS.md §2), which is not an admin action. |
| Class | DEAD-END |
| Severity | blocks money |
| Evidence | `HistoricalEnrolmentImportService.php:114-121,200-203`; `ManualPaymentSettlementService.php:50-53`; `AGENTS.md` §2 "Imported-enrolment retirement … no compensating action in the admin UI". |
| Proposed fix | **Decision required.** Either (a) make the import create a settled-zero obligation so history is real, or (b) require `payment_status` to stay `not_required`/`unknown` on import and record money only through a separate "historical payment" evidence command, or (c) add an admin "complete this import's payment" command that writes one evidence row with a receipt. (a) and (c) both touch finance authority and need a governance note. |
| Test | Import with `unpaid`, then assert (per the chosen decision) that a settlement is possible or that the UI says it is impossible. |

### OPS-14 — The admin time control is narrower than the domain

| Field | Content |
|---|---|
| Surface / journey | Enrolment → Reschedule intro / Execute agreement |
| Symptom | The Teacher proposes 18:47 through the portal; the admin cannot re-enter 18:47 when rescheduling or recording the agreed slot. Only `:00/:15/:30/:45` are offered. |
| Root cause | `platform/src/Admin/View/EnrolmentWorkspaceView.php:1160-1169` generates a 15-minute `<select>`. The domain accepts any minute: `platform/src/Core/Application/BookingRequestValidationService.php:119` matches `^([01][0-9]\|2[0-3]):[0-5][0-9]$`; the Teacher portal uses a native time input with no `step` (`theme/template-parts/teacher-portal/intro-actions.php:76`). The comment at `EnrolmentWorkspaceView.php:1162-1163` asserts the intake only offers `:15`/`:45`, which the intake code does not enforce. |
| Class | UI-NARROWER |
| Severity | confusing |
| Evidence | the three lines above; `grep -rn 'step="900"' theme platform/src` → nothing. |
| Proposed fix | Use the same native `<input type="time">` the create-intro form already uses (`EnrolmentWorkspaceView.php:756`), or generate every 5-minute value. Keep the server-side validation. |
| Test | Assert that any value the validator accepts can be submitted by every admin control that edits the same field. |

### OPS-15 — Record actions lose their options silently

| Field | Content |
|---|---|
| Surface / journey | Students / Classes → record actions menu |
| Symptom | An action appears with an empty dropdown ("Choose current enrolment" and nothing else) and no explanation. |
| Root cause | `platform/src/Admin/RecordAction/RecordActionRegistry.php:798,808,815,825,832,842,849,859,866,876,883,893,918,943,949,959,966,976,983,997,1003` wrap the option builders in `catch (\Throwable) { return false; }` / `{ return []; }`. A read failure therefore removes the options rather than reporting it. |
| Class | SILENT |
| Severity | confusing |
| Evidence | the lines above; `enrolmentActionOptions()` is the call being swallowed at `:799`. |
| Proposed fix | Let the failure surface as a disabled control with one sentence ("The current enrolments could not be read; reload or report this with the reference"), and log the class. Do not swallow. |
| Test | Stub the read service to throw and assert the rendered control carries a message, not an empty list. |

### OPS-16 — `platform_outbox.status='delivered'` is written on transport acknowledgement

| Field | Content |
|---|---|
| Surface / journey | Notification outbox (internal; not rendered today) |
| Symptom | None visible yet. The row says `delivered` before any delivery receipt exists. |
| Root cause | `platform/src/Core/Application/NotificationDispatchService.php:330` `$this->outbox->markDelivered(...)` runs inside the `if ($acknowledged)` branch, where the comment two lines above says delivery is "only ever written from a verified, normalised delivery fact". `platform/src/Core/Infrastructure/Repository/NotificationOutboxRepository.php:129-131` stores `status='delivered'`. |
| Class | OVERCLAIM |
| Severity | cosmetic today; becomes a false release claim as soon as it is displayed |
| Evidence | `NotificationDispatchService.php:324-331`; `NotificationOutboxRepository.php:129-131`. |
| Proposed fix | Rename to `acknowledged`/`handed_off`, or write `delivered` only from `NotificationDeliveryStateService` when the provider fact applies. |
| Test | Assert the outbox never holds `delivered` without a `delivered_at` from a provider fact. |

### OPS-17 — "Manual send complete: N sent" counts transport acknowledgement

| Field | Content |
|---|---|
| Surface / journey | Notifications → manual send |
| Symptom | The operator reads "N sent" and treats it as delivered. |
| Root cause | `platform/src/Admin/Controller/NotificationManagementController.php:405` prints the summary; `platform/src/Integrations/Notifications/NotificationManualSendService.php:165-167` increments `$sent` when the adapter returns `acknowledged`. The per-event surfaces elsewhere are careful ("Handed off to provider — delivery not confirmed", `NotificationManagementController.php:1551-1552`), so this is an outlier. |
| Class | OVERCLAIM |
| Severity | confusing |
| Evidence | the two lines above. |
| Proposed fix | Say "N handed to the provider" and add the existing "delivery not confirmed" note. |
| Test | Assert the flash wording does not contain "sent" unless a provider receipt was read. |

### OPS-18 — WhatsApp "mapped" can be satisfied by a local manual mapping

| Field | Content |
|---|---|
| Surface / journey | Integrations → WhatsApp → readiness |
| Symptom | The channel reports `mapped` (ready) with no Meta-approved template behind it. |
| Root cause | `platform/src/Integrations/Notifications/NotificationTemplateLibrary.php:190-193`: when `$mapping['source']==='manual'` and the local copy digest matches, `$providerState='mapped'`. The manual mapping is an option typed by an admin (`:808-813` `$map=$this->option(self::OPTION_WHATSAPP); … 'source'=>'manual'`). Publish is stricter (`:323,342-351`), so a send is still blocked there; the *readiness badge* is the wrong answer. |
| Class | WRONG-SOURCE |
| Severity | confusing |
| Evidence | the lines above; the approved source of truth is `TelnyxTemplateAuthority` reading `telnyx_templates.status='APPROVED'` (`NotificationProviderRepository.php:23`). |
| Proposed fix | A manual mapping may produce `mapped_unverified`, never `mapped`; only the provider authority produces `mapped`. |
| Test | Feed the library a manual mapping with no provider row and assert the state is not `mapped`. |

### OPS-19 — Notification retry paths disagree with the service

| Field | Content |
|---|---|
| Surface / journey | Notifications → Diagnostics; lesson-reschedule channel |
| Symptom | An `expired` notification is refused although the service accepts it; a failed lesson-reschedule or manual-send recipient has no retry at all. |
| Root cause | `platform/src/Admin/Controller/DiagnosticsController.php:222,229-230` offers retry only for `failed` and prints "Window closed — no retry." for `expired`, while `platform/src/Core/Application/NotificationManualDeliveryService.php:100` accepts `('failed','expired')`. `platform/src/Integrations/Notifications/LessonRescheduleNotificationService.php:59` writes `failed`, and the only dispatcher query selects `state IN ('queued','retry')` (`platform/src/Core/Infrastructure/Repository/LessonRescheduleRepository.php:223`), so `failed` is terminal with no UI. |
| Class | NO-PATH |
| Severity | confusing |
| Evidence | the lines above; also the subject allowlist at `NotificationManualDeliveryService.php:20` excludes `renewal_cycle`, `collection_intent` and `refund_review`, so a failed notification on those has no retry either. |
| Proposed fix | Make the UI derive its retryability from the service (one call), not from a second `in_array`; add the missing subject aggregates or state in the UI that retry is impossible and why. |
| Test | Assert every state the service accepts has a rendered action, driven by the service's own predicate. |

### OPS-20 — Clock-derived fixtures remain after two point fixes

| Field | Content |
|---|---|
| Surface / journey | CI suites (release risk) |
| Symptom | Intermittent failures that a re-run hides; a release blocked by a red suite that is not about the change. |
| Root cause | Fixtures derive absolute times from `now` (742 lines carrying a date literal; 94 `strtotime('+…')` calls). #768 and #769 fixed two instances (`lesson-replacement-service-unit.php:202` now derives from a future instant, `phase-2a2o-concurrency-setup.php:116` from `+11 days`). There is no lint that forbids a wall-clock-derived assertion. |
| Class | CLOCK |
| Severity | release risk |
| Evidence | §2.5; `platform/tests/enrolment-workspace-runtime-test.php:36,69` (`+33 days` used as both fixture and assertion). |
| Proposed fix | A single fixture clock: a `DZN_TEST_NOW` constant read by helpers, with `strtotime('+N days')` replaced by `clock()->add(N)`. Add a lint that fails on `gmdate(..., strtotime('+` in `platform/tests` without the helper. Both are test-only changes. |
| Test | Run the suite with a forced clock at 23:59 on a DST boundary and on the last day of a month. |

### OPS-21 — The Desk can only issue a full-term, pending payment step

| Field | Content |
|---|---|
| Surface / journey | Enrolment → Desk → Send payment link |
| Symptom | The Desk always sends "full term, payment pending". Instalments and "already received" are not reachable from the Desk, though the service supports them. |
| Root cause | `platform/src/Admin/Controller/EnrolmentDeskController.php:86-90` hardcodes `'plan_kind' => 'full'`, `'payment_mode' => 'pending'`. |
| Class | UI-NARROWER |
| Severity | confusing |
| Evidence | the line above; the supported values are visible at `platform/src/Core/Application/PostIntroContinuationService.php` (`plan_kind`, `payment_mode`) and used by the workspace's Execute agreement form. |
| Proposed fix | **Decision required**: the spec's decision 1 says a returning Student gets no intro; it does not say the Desk may only sell full-term. Either expose the two options (preferred; the service already validates them) or state in the Desk copy that instalments are recorded on the full case. |
| Test | Assert every `plan_kind`/`payment_mode` the service accepts is reachable from at least one admin control. |

### OPS-22 — The intro contract still pins the call-site spelling

| Field | Content |
|---|---|
| Surface / journey | Contract tests |
| Symptom | The exact defect of 2026-11-22 can return green. |
| Root cause | `platform/tests/enrolment-intro-review-contract.php:129-131` asserts `studentHasActiveInstrumentEnrolment($studentId` **as a string in the service source**, and `:130-132` asserts the misspelling is absent. Neither compares the call to `BookingRequestRepository`'s declaration, so a third spelling would pass. |
| Class | TEST-PINS-BUG |
| Severity | release risk |
| Evidence | the lines above; compare the correct pattern introduced by #773 at `platform/tests/google-meet-classroom-contract.php:96-105`, which parses the call and the declaration. |
| Proposed fix | Replace the string asserts with the parse: extract `$requests->m(` from the service, `function m(` from the repository, and require set membership. |
| Test | The replaced contract itself. |

### OPS-23 — The Desk contract proves the vocabulary exists, not that any real key is in it

| Field | Content |
|---|---|
| Surface / journey | Contract tests |
| Symptom | `OPS-01` shipped green. |
| Root cause | `platform/tests/enrolment-desk-contract.php:69-77` asserts four label strings and the shape of the lookup. It never evaluates `status()` against the keys `bookingStage()` actually returns. |
| Class | TEST-PINS-BUG |
| Severity | release risk |
| Evidence | the lines above; `EnrolmentWorkspaceReadService.php:1124-1173` enumerates the real domain. |
| Proposed fix | A table-driven unit test: for each of the 14 keys `bookingStage()` can return, assert `EnrolmentDeskReadService::status($key)['label']` is in the spec's vocabulary and not equal to `ucfirst(str_replace('_',' ',$key))`. |
| Test | That unit test. |

### OPS-24 — Finance exceptions are visible but cannot be resolved

| Field | Content |
|---|---|
| Surface / journey | Finance → Finance reconciliation |
| Symptom | The operator sees a table of open exceptions with counts and no action, and a "Latest run" panel that no screen can create. |
| Root cause | `platform/src/Admin/Controller/FinanceReconciliationController.php:22-40` is read-only by design ("A run repairs nothing"). The resolving authority, `platform/src/Core/Application/Finance/FinanceReconciliationService.php:108` `resolveException()`, has **no caller in `platform/src`**: `grep -rn "FinanceReconciliationService" platform/src` matches only its own declaration. The same is true for `CommercialExceptionService::resolve` (instantiated only inside payment services). |
| Class | DEAD-END |
| Severity | blocks money (an exception can never be closed) |
| Evidence | the grep above; `FinanceReconciliationController.php:9-10` "View-only diagnostics … never an amount that is not already recorded". |
| Proposed fix | **Decision required**: wire `resolveException` behind `dzn_manage_finance_*` with a reason and an audit event, or remove the exceptions table from the operator surface and state that reconciliation is a support function. Leaving it visible with no path is the worst of the three. |
| Test | Assert every listed exception has at least one permitted transition rendered. |

### OPS-25 — `payment_state` on a legacy/imported Term is money with no evidence

| Field | Content |
|---|---|
| Surface / journey | Enrolment → Import; ScreenController → create term; Finance |
| Symptom | An operator can set a Term to `paid` or `refunded` with nothing behind it. Finance cannot see it as a settlement and cannot reverse it. |
| Root cause | `platform/src/Core/Application/TermService.php:19-21` accepts `payment_state` from the form defaults; `platform/src/Admin/Controller/ScreenController.php:44,1098` exposes the selector; `HistoricalEnrolmentImportService.php:114-121,200-203` stores only a hashed reference in an audit row. Canonical Terms are created `not_applicable` (`CanonicalTermAuthorityService.php:57`), so this is a legacy-only field — but it is presented as the same concept to the operator. |
| Class | OVERCLAIM |
| Severity | blocks money (a refund cannot be attributed) |
| Evidence | the lines above; `ManualRefundService` has no `term`/`enrolment` reference (grep returns zero), so a refund cannot be tied back to this assertion. |
| Proposed fix | **Decision required**: rename the legacy field to `payment_claimed` (display "recorded, not verified") and keep it out of any finance total, or migrate imported terms to a real obligation. The first is a docs/UI change; the second is a finance change needing governance. |
| Test | Assert no finance read model sums `terms.payment_state`. |

### OPS-26 — "Execute payments" alone cannot record an offline payment, but the screens offer it

| Field | Content |
|---|---|
| Surface / journey | Finance → manual payment; Enrolment → Desk → Record offline payment |
| Symptom | A delegate granted the **Execute payments** bundle sees the form, submits, and is told "You do not have the authority for that action." |
| Root cause | Both screens gate on `ManualPaymentSettlementService::MANAGE_CAPABILITY` (`FinanceAdminController.php:417`, `EnrolmentDeskController.php:96`), but `markStandardPaid()` calls `CommercialPaymentService::ingest()`, which requires a second capability: `platform/src/Core/Application/CommercialPaymentService.php:18,31` `dzn_ingest_commercial_payment_evidence`. The delegation UI offers the two as separate bundles (`StaffAccessService.php:85-95`). |
| Class | capability |
| Severity | blocks money (for that delegate) |
| Evidence | the lines above; `FinanceSupport::requireCapability` throws `RuntimeException('Unauthorized')` (`FinanceSupport.php:29-30`), which the Desk maps to a generic denial. |
| Proposed fix | Either make the screens require both capabilities (and say so in the bundle description), or have `markStandardPaid` use a dedicated authority that does not require raw ingestion. Prefer the first: it is one condition, and it makes the two bundles honest. |
| Test | For each `SENSITIVE` bundle, call each offered command with exactly that bundle's caps and assert success or a capability sentence **before** submission. |

### OPS-27 — A "View" permission grants the write capability

| Field | Content |
|---|---|
| Surface / journey | Roles / delegated access → any area |
| Symptom | The owner ticks **View teachers** (described "Read teacher records and profile history") and grants `dzn_manage_teachers` — the same capability that creates, edits and archives. The label says read; the authority is write. |
| Root cause | `platform/src/Core/Application/PermissionRegistry.php:29` `'view' => self::action('View teachers', 'Read teacher records and profile history.', array('dzn_manage_teachers'))` is identical to `:30-32` (`create`, `edit`, `archive`). The same duplication exists at `:45` (students), `:117` (enrolments), `:123` (terms), `:64` (classes) and `:77` (attendance). The word "View" is a label over a capability that has no read-only variant. |
| Class | OVERCLAIM (authority) |
| Severity | authority overclaim; the owner believes a delegate can only read |
| Evidence | the lines above. `StaffAccessService.php:29` makes the sibling bundle's `view` empty (`'view' => array(),`), and `:193` can therefore never save it true, so the two delegation systems disagree about what "view" means. |
| Proposed fix | **Decision required.** Either introduce real read capabilities (large, touches every controller) or rename the tick to "Use teacher records (read and edit)" and drop the separate create/edit/archive ticks that grant the same capability. The second is a copy + registry change and is the honest fix. |
| Test | For each permission action, assert that a "view"-labelled entry's cap set is a subset of every write entry's cap set is **false** — i.e. that a read-only grant exists, or that no entry is labelled "View". |

### OPS-28 — A bundle can open a page the callback then refuses

| Field | Content |
|---|---|
| Surface / journey | Menu → Class admin, Notifications, Integration settings |
| Symptom | The menu link renders, the operator clicks it, and the page says "Access denied" (or `wp_die` 403) because the callback wants a second capability the granted action does not include. |
| Root cause | Three instances, same shape: <br>• `Menu.php:181` gates Class admin on `dzn_manage_booking_request_coordination`, but `CoordinationCaseController.php:87` requires **both** that and `dzn_view_booking_requests` (`:36`). `PermissionRegistry.php:100` `coordinate` grants only the first. <br>• `Menu.php:100-105` maps `dzn-notifications` from `dzn_manage_notification_workflows` and `dzn_operate_notification_dispatch`, but `NotificationManagementController.php:1337` accepts only `dzn_view_notification_authority` / `dzn_manage_notification_templates` / `manage_options`. <br>• `Menu.php:208-212` maps the five integration-settings pages from `dzn_manage_payment_providers`, but `IntegrationControlPlaneController.php:71` accepts only `dzn_manage_provider_integrations` / `manage_options`; `PermissionRegistry.php:195` grants the former. |
| Class | capability |
| Severity | confusing |
| Evidence | the lines above. |
| Proposed fix | Make the route capability the union of what the callback needs, or drop the second check where the first already implies it. One list, one source. |
| Test | For every menu slug, evaluate the `mapMetaCap` result against the callback's own gate with a synthetic role built from each `PermissionRegistry` action. |

### OPS-29 — The built-in "Academy coordinator" role cannot open the Desk

| Field | Content |
|---|---|
| Surface / journey | Roles → Academy coordinator → Enrolment desk |
| Symptom | The role described as "Day-to-day booking coordination … and enrolment follow-through" has no path to the Desk the spec says it should own. |
| Root cause | `platform/src/Core/Application/AccessRoleService.php:39-59` builds the role from `PermissionRegistry` keys. No `PermissionRegistry` action grants `dzn_use_enrolment_desk` (`grep -c` → 0), and `Menu.php:179` / `EnrolmentDeskController.php:25` require it. The capability exists only in the *other* delegation system (`StaffAccessService.php:55`) and on the administrator role (`Migrator.php:1497`). |
| Class | capability |
| Severity | blocks enrolment for the intended role |
| Evidence | the greps above; `docs/ENROLMENT-DESK-SPEC-2026-11-23.md` §11 decision 4. |
| Proposed fix | Add `dzn_use_enrolment_desk` to the `enrolment` area in `PermissionRegistry` (and to the coordinator template), or delete the separate capability per `OPS-03`. Do not leave two delegation systems disagreeing. |
| Test | Assert every built-in role can open every menu page its description names. |

### OPS-30 — Capabilities are granted that open no screen

| Field | Content |
|---|---|
| Surface / journey | Roles / delegated access → Finance, Policies, Portal capabilities |
| Symptom | The owner grants "manage finance statements", "manage teacher rates" or "manage portal capabilities"; the delegate logs in and finds no screen for it. |
| Root cause | The route capabilities are disjoint from the write capabilities: `Menu.php:89-94` (`FINANCE_WORKSPACE_CAP`) lists only view caps and `dzn_manage_commercial_catalogue`, while `PermissionRegistry.php:138,143,144,148` grant `dzn_manage_finance_policies`, `dzn_manage_teacher_rates`, `dzn_manage_finance_statements`, `dzn_manage_cash_ledger`. `PortalCapabilityController.php:7,10` gates on `dzn_view_portal_capabilities` while `PermissionRegistry.php:205` grants `dzn_manage_portal_capabilities`. A related case: `PermissionRegistry.php:24` registers `dzn_finance_export` as a permission, but that string is an `admin_post` action and a nonce name (`FinanceAdminController.php:74,393`), not a capability — `current_user_can('dzn_finance_export')` appears nowhere. |
| Class | DEAD-END |
| Severity | confusing; the grant has no effect |
| Evidence | the lines above. |
| Proposed fix | Add the write caps to the route mapping (they are legitimately needed *inside* the screen), or remove them from the permission catalogue. Remove `dzn_finance_export` from `PermissionRegistry`. |
| Test | Assert every capability in `PermissionRegistry` appears in at least one route's accept-list or in a documented secondary-cap list. |

### OPS-31 — Unauthorised access renders a blank page

| Field | Content |
|---|---|
| Surface / journey | Finance reconciliation, Finance policy, Finance rates, Finance statements, Finance payability, Payment execution, Portal capabilities, Academy coverage |
| Symptom | The operator opens the page and sees an empty admin frame — no sentence, no 403, no redirect. |
| Root cause | These callbacks deny with a bare `return;`: `FinanceReconciliationController.php:23`, `FinancePolicyController.php:24`, `FinanceRateController.php:24`, `FinanceStatementController.php:32`, `FinancePayabilityController.php:27`, `PaymentExecutionController.php:50`, `PortalCapabilityController.php:48`, `AcademyCoverageController.php:20`. |
| Class | SILENT |
| Severity | confusing |
| Evidence | the lines above. |
| Proposed fix | Use the platform's existing denial: `wp_die('Forbidden', …, 403)`. One line each. |
| Test | Assert every `render()` that checks a capability also emits a response. |

## 5. One PR each vs owner decisions

**One PR each** (the fix is unambiguous and inside existing authority):
`OPS-01, OPS-02, OPS-03, OPS-04, OPS-05, OPS-06, OPS-07, OPS-08, OPS-09, OPS-11, OPS-14, OPS-15,
OPS-16, OPS-17, OPS-18, OPS-19, OPS-20, OPS-22, OPS-23, OPS-26, OPS-28, OPS-29, OPS-30, OPS-31.`

**Needs an owner decision** (the invariant and the product expectation disagree; do not patch the
invariant):

| ID | Question | Consequence of each answer |
|---|---|---|
| OPS-10 | After a cancelled intro, is the recovery a replacement request, an audited reinstate, or documented copy? | Reinstating needs a new authority and a governance note; the other two are UI/docs. |
| OPS-12 | May a class be recorded as delivered once it has *started*, or only once it has *ended*? | Starting earlier improves same-evening workflow; ending-only keeps the "no false attendance" invariant tightest. |
| OPS-13 | Is an imported unpaid Term a receivable or a historical claim? | Receivable needs an obligation and a finance surface; claim needs a different word and no settlement path. |
| OPS-21 | May the Desk sell instalments and record an already-received payment? | Yes: expose the existing options. No: state it in the Desk copy. |
| OPS-24 | Are finance exceptions resolved in the admin, or is reconciliation a support function? | Wiring `resolveException` adds a finance authority and an audit shape; removal simplifies the menu. |
| OPS-25 | Is legacy `payment_state` verified money or a claim? | Rename only (cheap) or migrate to obligations (finance migration). |
| OPS-27 | Does "View" become a real read-only grant, or does the delegation UI stop claiming one? | Real read caps touch every controller; relabelling is a registry + copy change but halves the authority a delegate receives. |

## 6. Proposed test additions (each is the test named in its finding)

1. `platform/tests/operator-refusal-coverage-contract.php` — every thrown `snake_case` refusal has
   an operator sentence (`OPS-11`). This is the highest-yield new test after the call checker.
2. `platform/tests/enrolment-desk-status-unit.php` — every key `bookingStage()` can return maps to
   an approved label, list and detail agree (`OPS-01`, `OPS-02`, `OPS-23`).
3. `platform/tests/operator-capability-matrix-unit.php` — each `StaffAccessService` bundle and each
   `AccessRoleService` template can use what its screens offer, and each `PermissionRegistry` action
   opens at least one screen (`OPS-03`, `OPS-04`, `OPS-26`, `OPS-28`, `OPS-29`, `OPS-30`).
4. `platform/tests/pipeline-stage-exclusivity-unit.php` — exactly one pipeline predicate per row
   (`OPS-08`).
5. `platform/tests/admin-time-input-parity-unit.php` — every accepted minute is offerable (`OPS-14`).
6. `platform/tests/admin-denial-response-unit.php` — every capability check in a `render()` emits a
   response, never a bare `return` (`OPS-31`).

**Promoting the checker.** `docs/audits/tools/method-call-check.php` runs in ~1s, needs only PHP, and
is quiet on the current tree except for the three real findings. Moving it to `tools/ci/` and adding
it to the PR gate's production-tooling step is a **governance change** (`.github/workflows/**` +
`AGENTS.md` §2/§6 in the same commit) and is deliberately not done here.

## 7. What this audit did not find (stated so it is not assumed clean)

- Every admin POST handler inspected has a nonce check and a capability check. The defects are in
  *which* capability, not *whether* one exists.
- No menu item was found with no capability at all.
- The notification delivery vocabulary is, in the main surface, careful about
  hand-off vs delivery (`NotificationManagementController.php:937,1551-1552`; the case page says
  "A transport hand-off is not recipient delivery"). `OPS-16`/`OPS-17` are the exceptions.
- The payment gate itself holds: `ManualPaymentSettlementService` cannot settle an unknown offer, an
  already-settled obligation, or an amount below 1, and the Desk cannot bypass it.

## 8. Issue index

Each finding has one issue: `OPS-01` → #788 … `OPS-31` → #818, in order.

| ID | Issue | ID | Issue | ID | Issue | ID | Issue |
|---|---|---|---|---|---|---|---|
| OPS-01 | #788 | OPS-09 | #796 | OPS-17 | #804 | OPS-25 | #812 |
| OPS-02 | #789 | OPS-10 | #797 | OPS-18 | #805 | OPS-26 | #813 |
| OPS-03 | #790 | OPS-11 | #798 | OPS-19 | #806 | OPS-27 | #814 |
| OPS-04 | #791 | OPS-12 | #799 | OPS-20 | #807 | OPS-28 | #815 |
| OPS-05 | #792 | OPS-13 | #800 | OPS-21 | #808 | OPS-29 | #816 |
| OPS-06 | #793 | OPS-14 | #801 | OPS-22 | #809 | OPS-30 | #817 |
| OPS-07 | #794 | OPS-15 | #802 | OPS-23 | #810 | OPS-31 | #818 |
| OPS-08 | #795 | OPS-16 | #803 | OPS-24 | #811 | | |

Files added by this audit:

- `docs/audits/2026-11-24-operator-surfaces.md` (this document)
- `docs/audits/tools/method-call-check.php` (read-only checker, §2.1)

## 9. Status after the first fix batch (2026-11-24)

Merged fixes, one concern per PR:

| Finding | PR | What changed |
|---|---|---|
| OPS-06 | #819 | God Mode verifier reads participant mappings from `GodModeRepository` |
| OPS-07 | #820 | Dead teacher branch removed; exception spelling aligned |
| OPS-01, OPS-02, OPS-09, OPS-23 | #821 | Desk status derived from the key vocabulary it is given; unknown stage → `needs_review`; contract evaluates every real key |
| OPS-08 | #822 | One booked-intro exclusion for every negotiation/conversion stage |
| OPS-26 | #823 | Offline standard payment asks for both authorities, and says so |
| OPS-03, OPS-29 | #824 | Desk capability accepted by its read path and grantable by a role template |
| OPS-11 | #825 | Refusal-coverage ratchet (926 of 1162 baselined, no new opaque refusal) |

Still open and unambiguous (one PR each): `OPS-04, OPS-05, OPS-14, OPS-15, OPS-16, OPS-17, OPS-18,
OPS-19, OPS-20, OPS-21, OPS-22, OPS-24, OPS-25, OPS-27, OPS-28, OPS-30, OPS-31`.

Waiting on an owner decision: `OPS-10, OPS-12, OPS-13, OPS-21, OPS-24, OPS-25, OPS-27`.

### New evidence for OPS-20 (clock-dependent suites)

While merging the batch, the `Interim attendance runtime` job failed twice with the same fatal and
passed on re-run, and a `main` run failed the same way:

```text
PHP Fatal error: Uncaught InvalidArgumentException: post_intro_invitation_not_ready
  in platform/src/Core/Application/PostIntroContinuationService.php:55
  at platform/tests/post-intro-enrolment-automation-runtime.php:268
```

The branch (`PostIntroContinuationService.php:55`) is reached only when the preparation state is not
`prepared`/`checkout_pending`, so the fixture occasionally does not reach the state the next line
assumes. It is the same class as #768/#769: a suite that depends on elapsed wall-clock time. It is not
fixed here because it belongs to OPS-20 and needs the fixture clock decision.
