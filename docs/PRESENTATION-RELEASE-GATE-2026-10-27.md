# Presentation release gate

Status: **released**. Package 9 artefact retained as the release-gate record.
Deployed to production as `6c7c625c3ab9f0ba25344c2668077efc87692388`; see
`platform/docs/RELEASE-2026-10-27-PRESENTATION.md` for deployment evidence.
Baseline: `origin/main` at `c952fe0de043244edeb78a4e71ec831bdc5fbdb8`, with the
presentation packages pushed as the stacked draft PRs **#367–#376**.
Final integration: current `origin/main` `bae83f7e76b2fad784b74dab37890c91dafb725f`
(including the lesson-detail hotfix) merged into
`pkg9/release-gate-20261027`; candidate `f5254d40ce18981f5a46b10d21e36337cf240b05`.

## 1. What is enabled

| Surface | State after Packages 0–8 | Gate |
| --- | --- | --- |
| Public home and editorial pages | Editorial shell at all widths; visible regional price selector; responsive hero/instrument derivatives | Performance and visual sign-off |
| `/enrol/` | Canonical booking flow; legacy `/booking/` forwards allowlisted instrument on a 302 | Booking regression fixtures |
| Student home | Next/in-progress class, term progress, requests, post-intro, announcement, history | Existing authorised read |
| Student schedule | Offered only when the lesson read returns rows; active first; no student join for canonical term lessons | Lesson read evidence |
| Student payments | Hidden by default behind `DZN_THEME_STUDENT_PAYMENTS`; when enabled, offered only for returned obligations and the existing REST checkout | Payment owner + Stripe test-mode evidence |
| Student account | Profile, payments, notifications, history; notifications say unavailable when no read exists | Existing account read |
| Teacher Today | Four-item navigation; active class first; honest join/report states | Existing teacher read |
| Teacher Classes | Dedicated screen over the same authorised rows | Teacher lane owner |
| Teacher Finance | Formatted minor units, explicit empty/unavailable states | Finance owner |
| Teacher Account | Settings screen from the page-template and canonical entries | Teacher lane owner |
| Teacher Onboarding | Server first-incomplete step; timezone confirmed; HHMM/2400 preserved; review/Google honest | Teacher owner POST-by-POST |

## 2. Protected response contract

Canonical auth and protected routes call `dzn_theme_send_protected_response_headers()` after
`nocache_headers()` and **before any dashboard or signed-out redirect**:

```text
X-Robots-Tag: noindex, nofollow
Cache-Control: no-store, private, max-age=0
Pragma: no-cache
Referrer-Policy: same-origin
```

Routes covered: `/login/`, `/dashboard/`, `/student-portal/`, `/teacher-portal/`,
`/teacher-invitation/`, `/student-account-claim/`, `/admin-operations/`.
`/enrol/` remains public and is not in the protected list. The priority-0
credential and password-reset POST handlers send the same headers before their
redirects, because they exit before the priority-1 virtual-route block.

Verify on staging with `curl -I` for a signed-out and a signed-in session. Do not
assume a CDN or page cache honours these headers; confirm the edge behaviour.

## 3. Verification matrix

### Automated

```bash
for c in \
  presentation-contract-inventory-contract public-acquisition-contract \
  portal-truth-contract responsive-shell-contract student-home-account-contract \
  student-schedule-payments-contract teacher-classes-contract \
  teacher-onboarding-presentation-contract public-hardening-contract \
  release-presentation-gate-contract student-payments-flag-contract; do
  php "platform/tests/$c.php"
done
```

Also run the existing PR runtime gate. The new contracts are listed in
`.github/workflows/pr-runtime-gate.yml`.

### Real browser (owner-provided test principals)

Run `cd acceptance && make protected-headers` against a disposable runtime to assert the
actual `302` responses carry `X-Robots-Tag` and `no-store/private`, not only the rendered pages.

- Homepage instrument tile → `/enrol/?instrument=…` keeps the instrument.
- Legacy `/booking/?instrument=…` forwards the instrument on a temporary redirect.
- Pricing selector is visible and changing the region changes only the displayed price.
- Student home at `start−1s`, `start`, mid-class, `end−1s`, `end`, `end+1s`.
- Student absence eligible → confirmation → explicit reported state only if the Platform supplies it.
- Student payments: obligation → existing checkout → Stripe **test** return/cancel/retry.
- Teacher Today/Classes: active class remains first; join/report only where Platform allows.
- Teacher onboarding: pending → returned/rejected → approved; query step cannot skip ahead.
- Signed-out deep link, wrong-role URL, session expiry, 404, logout.
- Desktop and mobile use the same canonical IDs and the same POST action.

Viewports: 320, 375, 430, 768, 1024, 1440; short landscape; 200% text zoom;
device safe areas; reduced motion; forced colours.

### Accessibility

Target WCAG 2.2 AA. Check keyboard order, skip link, the two navigation variants
(exactly one exposed per breakpoint), `aria-current`, live regions, dialog focus
return, contrast, touch targets and error summaries. Run axe or equivalent plus
a manual screen reader pass.

### Performance budgets

Measure before optimising. Targets at p75 on representative 4G/mid-range Android:
LCP ≤ 2.5s, CLS ≤ 0.1, INP ≤ 200ms on public home and portal home. Aim for
≤ 500KB compressed first-view imagery; portal JS ≤ 60KB gzip and portal CSS
≤ 40KB gzip after the shared base is extracted. Record transferred bytes,
request count and a lab trace separately from field data.

## 4. Feature gates

- Student schedule tab: rendered only when `lessons` or confirmed booking classes exist.
- Student payments tab: hidden unless `DZN_THEME_STUDENT_PAYMENTS` (or the explicit filter) is enabled; even then rendered only when the owner-bound commercial read returns obligations. A direct payments route renders a disabled state while the flag is off. The flag is a **presentation gate only**: the existing owner-bound REST checkout endpoints remain registered, and production Stripe stays in test mode.
- Persistent absence “reported” state: not shown. Only the post-submit query confirmation is displayed until the Platform read model exposes an explicit canonical fact.
- Teacher Classes: available to a ready teacher; onboarding still overrides the shell.
- No notification badge, unread count, receipt, profile edit, teacher private note, deadline,
  instalment promise or persisted absence-report claim is inferred.
- No service worker, offline protected cache, or separate `/app/` router is part of this
  release. Installability is a later, separately reviewed decision.

## 5. Rollback

1. Deployments follow the existing protected production workflow; no new pipeline is added.
2. Before deploy, record the release commit, asset derivative filenames and the CI run.
3. Smoke `/`, `/enrol/`, `/login/`, `/student-portal/`, `/teacher-portal/`, the protected
   headers, the instrument redirect and the enabled tabs.
4. On a primary-journey regression, roll back to the previous artefact with the existing
   deployment rollback. Keep unready feature tabs off; do not hotfix payment or booking
   authority in the Theme.

## 6. Evidence still required

| Evidence | Owner | Status |
| --- | --- | --- |
| Production/deployed commit reconciliation | Release owner | Verified — build `main-6c7c625c3ab9` |
| Authenticated student, teacher-ready, onboarding-pending/returned principals | Owner | Not available |
| Stripe test-mode checkout and return | Payment owner | Offline E2E PASS; live test checkout/return not run |
| Screenshots at the six viewports and 200% zoom | Design reviewer | Not captured |
| Real-browser accessibility and screen-reader pass | Accessibility reviewer | DOM/console pass only; axe/screen-reader not run |
| Measured waterfall and field vitals | Performance owner | Baseline not captured |
| Edge/CDN cache-header confirmation | Platform owner | Verified on live auth/protected routes |

No item in this document is a substitute for that evidence. The specification's
launch gates remain in force.

## 7. Evidence recorded in this run (27 October 2026)

### Final integration and review closure

- `origin/main` `bae83f7` (including hotfix #366) merged into the release branch.
- Final candidate: `f5254d4` on PR **#376**, retargeted from the stacked base
  to `main`. Packages #371–#375 are the same commits carried by #376.
- Independent review findings closed on the candidate:
  - Protected headers now cover the priority-0 login and password-reset POST
    redirects, with source-contract assertions.
  - Student and teacher join availability follows the backend's full
    `start − 1h … end + 2h` window instead of stopping at the scheduled end.
  - Withdrawn teacher statements map to the canonical `withdrawn` state.
  - The fixed mobile tab bar no longer obscures the document footer.
  - The bounded portal lesson read selects the newest window, so a principal
    with more than 80 lifetime Lessons still receives current/upcoming rows.
  - Home carries a machine-readable start instant; the schedule renders the
    backend join explanation and marks a booking without instants as unknown.
  - The production diagnostic now checks the lesson-screen class resolution and
    service methods without writing production state.

### Pull requests and source gate

- Draft stacked PRs **#367–#376**, each based on its predecessor.
- Each PR: **7/7 checks pass** on the final rebased stack (PHP source contracts,
  Post-intro runtime lifecycle, Interim attendance runtime, Catalogue runtime,
  Finance runtime, OAuth scope MariaDB regression, Stripe runtime E2E).
- The final integrated branch passes the full local contract list plus the ten
  new presentation contracts, PHP lint, Node parse and `git diff --check`.
- Review fixes carried into the relevant layers:
  - Protected no-store/noindex headers are sent **before** dashboard,
    signed-out, login-POST and password-reset redirects.
  - Payments are behind a **default-off** `DZN_THEME_STUDENT_PAYMENTS`
    presentation flag; the tab and commercial read are skipped while off. The
    existing owner-bound REST checkout remains registered, so this is not a
    server-side checkout kill switch.
  - The unsupported persisted absence-report claim is removed; only the
    post-submit query confirmation remains.
  - Every Platform UTC timestamp uses the empty-safe parser, with a source guard.
- An empty UTC instant was parsed as “now”; fixed through
  `dzn_theme_platform_utc_timestamp()`.
- Package 1 initially failed CI because its redirect contract update sat one
  layer higher. The test was moved down to Package 1 and the stack was rebased;
  all ten PRs are green afterwards.

### Local regression evidence on the final candidate

| Check | Result |
| --- | --- |
| Full source-contract list from `pr-runtime-gate.yml` | PASS |
| PHP lint for every changed PHP file | PASS |
| `node --check` for changed JavaScript | PASS |
| `git diff --check` | PASS |
| `make lifecycle-regression` (pure + Stripe test-mode E2E + post-intro + interim attendance) | PASS on `2ca93a4`; rerun on `f5254d4` before merge |
| `production-release-diagnostic-contract.sh` | PASS |

### Local WordPress acceptance

Run against the integrated branch with the disposable Docker harness:

| Target | Result |
| --- | --- |
| `make smoke` | PASS — student, teacher, dual-role and administrator routing; stale form; wrong password; invalid nonce |
| `make booking-student-account` | PASS |
| `make protected-headers` | PASS — signed-out student/teacher/dashboard 302s carry noindex + no-store/private; login 200 does too; legacy booking keeps the instrument |
| `make student-account-claim` | FAIL on the integrated branch **and on clean `origin/main`** at `TeacherAvailabilityAssentService`: “Candidate Teacher is not currently eligible for assent” |
| `make teacher-onboarding` | FAIL on the integrated branch **and on clean `origin/main`** at `TeacherOnboardingService`: “Teacher onboarding is incomplete” |

The two failures are pre-existing fixture blockers, not regressions from this
stack. They still block the deeper authenticated claim/onboarding journeys and
must be repaired before those gates can pass.

### HTTP behaviour on the integrated branch

- Signed-out `/student-portal/`, `/teacher-portal/` and `/dashboard/`: `302`
  with `X-Robots-Tag: noindex, nofollow` and `Cache-Control: no-store, private`
  asserted on the redirect itself.
- `/booking/?instrument=setar&instrument_name=…`: `302` to
  `/enrol/?instrument=setar&instrument_name=…`.
- Protected and auth responses: `X-Robots-Tag: noindex, nofollow`,
  `Cache-Control: no-store, private, max-age=0`, `Referrer-Policy: same-origin`.
- Student and teacher portal shells render for authenticated synthetic sessions;
  the student no-data state and the forced teacher onboarding state were observed.
- `/enrol/?instrument=setar` could not prove the final selected instrument because
  the acceptance catalogue has no active introductory instruments; the page
  correctly showed the unavailable state instead.
- `/teacher-portal/?teacher-view=classes` correctly forced the not-ready synthetic
  teacher to onboarding.
- With the payments flag off, the tab is absent and a direct payments route would
  render the disabled state. The synthetic student has no enrolments, so the
  direct-route runtime was not reachable; `student-payments-flag-contract.php`
  covers the valid-obligation case with the flag off and on.

### Not run or not available

- **Staging:** retired in `deployment/README.md`; no staging workflow exists in the tree.
- **Stripe test-mode checkout/return:** no test principals or Stripe test credentials
  are available here, and the acceptance harness does not cover checkout/providers.
- **Screenshots, keyboard, screen-reader and axe:** no browser automation is installed;
  an attempted Chrome headless capture attached to an existing profile and produced no
  evidence, so none is claimed.
- **Production headers/CDN cache:** not tested; only local response headers were observed.
- **Measured waterfall/field vitals:** not captured.

### Merge and deploy decision

**Merged and deployed.** PR #376 merged as `7e1ff81`; the corrected production
smoke contract merged as #377 (`6c7c625`). Production deployment run `40493845862`
succeeded on `6c7c625`: build `main-6c7c625c3ab9`, schema 52, plugin/theme active,
maintenance off, Stripe test mode only, scheduled jobs present, and the
lesson-screen class/method resolution check passed. See
`platform/docs/RELEASE-2026-10-27-PRESENTATION.md` for the full record and backup
path.

Known follow-ups not blocking this release: cancelled canonical lessons are not
yet given a dedicated label on the new schedule/classes projections; the
payments flag is presentation-only; the post-intro runtime fixture is
time/occupancy sensitive and flaked once before a clean rerun; and the
authenticated claim/onboarding fixture blockers recorded above remain open for
their own gates.
