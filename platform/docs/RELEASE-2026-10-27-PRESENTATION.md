# Release 2026-10-27 — presentation, portals and protected release gate

Status: **deployed to production and verified**. This record is the operational
source of truth for the release; it does not replace the PR review threads, CI
runs or deployment artifacts.

## Release identity

| Fact | Value |
| --- | --- |
| Source branch | `main` |
| Deployed source SHA | `508a8224e76943f25c5e56bd5996f41f08cb12aa` |
| Production build stamp | `main-508a8224e769` |
| Platform schema | `52` (`052_payment_plan_choice`), unchanged |
| Previous production SHA | `c952fe0de043244edeb78a4e71ec831bdc5fbdb8` (build `main-c952fe0de043`, schema 52) |
| Successful deployment workflow | `Deploy production`, run `40494901611` |
| Read-only production diagnostic | run `40495430901` |
| Production backup | `/home/delnavaz/backups/delnavazan-production-cutover/20261027T111939Z-1750281-508a8224e76943f25c5e56bd5996f41f08cb12aa` |

The deployed application contains the fully tested presentation candidate
`bc4a9e2`, the corrected production smoke contract from #377, and the instrument
belt fix from #378. `#378` was independently reviewed and passed 7/7 checks.

### Deployment attempts

| Run | SHA | Outcome |
| --- | --- | --- |
| `40492504675` | `7e1ff81` | Application deployed successfully; smoke gate failed on the retired `/booking/` `301` expectation, so the workflow left maintenance mode enabled. |
| `40493845862` | `6c7c625` | PASS. Source gate 7/7, deploy, smoke, maintenance off. |
| `40494901611` | `508a822` | PASS. Includes #378 instrument-belt fix; source gate 7/7, deploy, smoke, maintenance off. |

The first failure was a smoke-contract mismatch, not an application failure. PR
#377 corrected the smoke assertion to the released temporary `302` that preserves
the allowlisted instrument, then the exact `main` was redeployed through the same
protected workflow. #378 then fixed the homepage instrument belt's fractional
scroll accumulator; that exact new `main` was deployed and smoke-tested.

## Merged PRs

| PR | Scope | Merge SHA |
| --- | --- | --- |
| #376 | Presentation packages 4–9, protected headers, release gate and audit fixes | `7e1ff8148cb08830485045ffc13b50b8946cddd5` |
| #377 | Production smoke contract for the temporary booking redirect | `6c7c625c3ab9f0ba25344c2668077efc87692388` |
| #378 | Homepage instrument belt fractional scroll accumulator | `508a8224e76943f25c5e56bd5996f41f08cb12aa` |
| #371–#375 | Subset package PRs; their commits are included in #376 | auto-merged via #376 |

Packages 0–3 (#367, #368, #369, #370) were already on `main` before this release.
Superseded PRs #306, #331, #346 and #369 were closed with evidence; #309
(notification hardening) and #327 (production synthetic dataset scope) remain
deferred.

## Behaviour in this release

- Student and teacher portal shells are responsive; the fixed mobile tab bar
  reserves footer space and the desktop/mobile navigation variants share the
  same canonical destinations.
- Student schedule is offered only when an authorised read returns rows, keeps
  active classes first, uses the backend join window (`start − 1h … end + 2h`),
  and renders the backend join explanation instead of a dead action.
- Student payments remain behind the default-off `DZN_THEME_STUDENT_PAYMENTS`
  presentation flag. While off, the tab, commercial read and checkout root are
  absent. The flag is not a server-side checkout kill switch; the existing
  owner-bound REST endpoints remain registered.
- Teacher Today/Classes/Finance use the existing authorised reads and show only
  Platform-issued actions. Withdrawn statements map to the canonical
  `withdrawn` state.
- Teacher onboarding resumes at the first incomplete server step and cannot skip
  ahead through a query parameter.
- Auth and protected routes send `X-Robots-Tag: noindex, nofollow`,
  `Cache-Control: no-store, private, max-age=0`, `Pragma: no-cache` and
  `Referrer-Policy: same-origin` before redirects, including the priority-0
  login/password-reset POST handlers and the portal page templates.
- The bounded portal lesson read selects the newest window, so more than 80
  lifetime Lessons cannot hide current/upcoming classes.
- The homepage instrument belt keeps a fractional scroll accumulator, so the
  intended slow motion is not rounded back to a static position each frame.
- No Platform capability, checkout, migration, notification-transport, Google
  Meet attendance or provider integration was added.

## Verification evidence

| Check | Result |
| --- | --- |
| PR #376 final gate on `bc4a9e2` | PASS — 7/7 required jobs |
| PR #377 final gate on `6c7c625` | PASS after rerunning one flaky post-intro job |
| PR #378 final gate on `415e01d` | PASS — 7/7 required jobs |
| Deployment source gate on `508a822` | PASS — 7/7 required jobs |
| Full local source-contract list | PASS |
| `make lifecycle-regression` on final candidate | PASS — pure, Stripe test-mode E2E, post-intro, interim attendance/presumed completion |
| PHP lint, `node --check`, `git diff --check` | PASS |
| Production smoke `40494901611` | PASS — home, enrol, assets, catalogue, booking redirect with instrument, login, admin operations, portal redirects, REST, sitemap, www |
| Independent local production smoke | PASS (`RESULT: PASS`) |
| Production identity | build `main-508a8224e769`; schema `52`; plugin active; `delnavazan-theme` active; maintenance inactive |
| Lesson detail fatal regression | `ScreenController` loaded; `CanonicalLessonScheduleReadService` loaded; stale admin namespace absent; `forLesson`, `forLessonCompletion`, `forLessonDelivery` all present |
| Scheduled jobs | `dzn_post_intro_activation_dispatch` and `dzn_lesson_completion_automation_dispatch` scheduled |
| Stripe | Test mode only — one active account `mode=test`; test-mode environment and activation allowed; no live mode |
| Browser checks | Home: expected hero and instrument tiles, no horizontal overflow, no console errors; `/login/`: branded form and both credential fields; `/enrol/`: 12 instrument choices, no PHP error marker |
| Instrument belt source | `node --check` PASS; `homepage-instrument-belt-contract.php` PASS on the deployed source |
| Protected headers | `/login/` 200 and signed-out `/student-portal/`, `/teacher-portal/` 302s carry noindex + no-store/private + same-origin referrer policy |
| Booking compatibility | `/booking/?instrument=setar&instrument_name=Setar` → `302` to `/enrol/` with both parameters |

Database state observed by the diagnostic: `completed_052=true`, 12 authorised
canonical Lessons, 1 activated preparation, 1 settlement, 0 plan choices, 0
attendance cases/conflicts, 0 pending payability, `INSTALMENT_DUE_DATE_POLICY`
unset. No migration ran for this release; schema stayed at 52.

## Rollback

The deployment script took a database export and an application-file backup
before mutation, and restores application files automatically on a failed deploy.
It never restores the database automatically. This release has no schema change:
a code-only rollback to `c952fe0` (build `main-c952fe0de043`) is safe and leaves
schema 52 and all existing records intact. If a database restore is unavoidable,
take a fresh backup first and follow the operator record.

## Outstanding limitations and follow-ups

1. **Cancelled canonical lessons** do not yet have a dedicated label on the new
   schedule/classes projections; they are still shown by their time state. No
   cancelled Lessons exist in the current production state.
2. **Payments flag is presentation-only.** The owner-bound REST checkout remains
   registered while the tab is off. Production remains Stripe test mode; do not
   switch to live without the separate payment approval.
3. **Post-intro CI flake.** One PR #377 run failed at
   `CanonicalContinuationService::holdFirstRegularSlot` with
   `teacher_slot_conflict`; an immediate rerun and the deployment source gate
   both passed. The fixture is time/occupancy sensitive and should be hardened.
4. **Existing failed activation job.** One job created 2026-10-26 remains
   `failed / provider_credential_refresh_required` with 8 attempts. It predates
   this release and was not rewritten; repair the owning provider credential and
   use the admin retry surface.
5. **Authenticated interactive journeys.** No production administrator/student/
   teacher credentials were available in this environment, so the logged-in
   lesson detail page was verified by class/method resolution rather than a live
   browser session. The authenticated claim/onboarding fixtures remain blocked
   as recorded in `docs/PRESENTATION-RELEASE-GATE-2026-10-27.md`.
6. **Visual/accessibility evidence.** Browser DOM and console checks passed, but
   screenshots, a manual screen-reader/axe pass and measured field vitals were not
   captured. The server-level PHP error log was not directly readable from this
   environment; public pages and the read-only diagnostic showed no new fatal.
7. **Deferred PRs.** #309 notification transport hardening and #327 production
   synthetic dataset scope were not merged. #309 needs a split/owner decision on
   replacing real provider tests with mocks; #327 is a production data-safety
   decision and remains deferred.
