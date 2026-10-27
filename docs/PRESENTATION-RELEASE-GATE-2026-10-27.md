# Presentation release gate

Status: Package 9 artefact. This document prepares a release decision; it does
**not** authorise a merge, deployment or production change.
Baseline: `origin/main` at `c952fe0de043244edeb78a4e71ec831bdc5fbdb8`, with the
presentation packages pushed as the stacked draft PRs **#367–#376**.

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
`/enrol/` remains public and is not in the protected list.

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
  release-presentation-gate-contract; do
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
- Student payments tab: hidden unless `DZN_THEME_STUDENT_PAYMENTS` (or the explicit filter) is enabled; even then rendered only when the owner-bound commercial read returns obligations. A direct payments route shows a disabled state while the flag is off.
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
| Production/deployed commit reconciliation | Release owner | Not available in this workspace |
| Authenticated student, teacher-ready, onboarding-pending/returned principals | Owner | Not available |
| Stripe test-mode checkout and return | Payment owner | Not run |
| Screenshots at the six viewports and 200% zoom | Design reviewer | Not captured |
| Real-browser accessibility and screen-reader pass | Accessibility reviewer | Not run |
| Measured waterfall and field vitals | Performance owner | Baseline not captured |
| Edge/CDN cache-header confirmation | Platform owner | Not run |

No item in this document is a substitute for that evidence. The specification's
launch gates remain in force.

## 7. Evidence recorded in this run (27 October 2026)

### Pull requests and source gate

- Draft stacked PRs **#367–#376**, each based on its predecessor.
- Each PR: **7/7 checks pass** on the final rebased stack (PHP source contracts,
  Post-intro runtime lifecycle, Interim attendance runtime, Catalogue runtime,
  Finance runtime, OAuth scope MariaDB regression, Stripe runtime E2E).
- The final integrated branch passes the full local contract list plus the ten
  new presentation contracts, PHP lint, Node parse and `git diff --check`.
- Review found one real defect: an empty UTC instant was parsed as “now”. It is
  fixed in the final layer through `dzn_theme_platform_utc_timestamp()`.
- Package 1 initially failed CI because its redirect contract update sat one
  layer higher. The test was moved down to Package 1 and the stack was rebased;
  all ten PRs are green afterwards.

### Local WordPress acceptance

Run against the integrated branch with the disposable Docker harness:

| Target | Result |
| --- | --- |
| `make smoke` | PASS — student, teacher, dual-role and administrator routing; stale form; wrong password; invalid nonce |
| `make booking-student-account` | PASS |
| `make student-account-claim` | FAIL on the integrated branch **and on clean `origin/main`** at `TeacherAvailabilityAssentService`: “Candidate Teacher is not currently eligible for assent” |
| `make teacher-onboarding` | FAIL on the integrated branch **and on clean `origin/main`** at `TeacherOnboardingService`: “Teacher onboarding is incomplete” |

The two failures are pre-existing fixture blockers, not regressions from this
stack. They still block the deeper authenticated claim/onboarding journeys and
must be repaired before those gates can pass.

### HTTP behaviour on the integrated branch

- Signed-out `/student-portal/`: `302` to `/login/?redirect_to=…`.
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

**Not approved.** The PRs remain drafts. Merge only after the runtime matrix above
is completed on an owner-provided staging/acceptance environment with test
principals, and the PRs are reviewed. Then rerun CI on the final integrated commit
and deploy that exact commit through the protected production workflow with the
documented rollback ready.
