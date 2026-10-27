# Presentation release gate

Status: Package 9 artefact. This document prepares a release decision; it does
**not** authorise a merge, deployment or production change.
Baseline: `origin/main` at `c952fe0de043244edeb78a4e71ec831bdc5fbdb8`, with the
presentation packages stacked locally as separate commits.

## 1. What is enabled

| Surface | State after Packages 0–8 | Gate |
| --- | --- | --- |
| Public home and editorial pages | Editorial shell at all widths; visible regional price selector; responsive hero/instrument derivatives | Performance and visual sign-off |
| `/enrol/` | Canonical booking flow; legacy `/booking/` forwards allowlisted instrument on a 302 | Booking regression fixtures |
| Student home | Next/in-progress class, term progress, requests, post-intro, announcement, history | Existing authorised read |
| Student schedule | Offered only when the lesson read returns rows; active first; no student join for canonical term lessons | Lesson read evidence |
| Student payments | Offered only when the commercial read returns obligations; existing REST checkout only | Payment owner + Stripe test-mode evidence |
| Student account | Profile, payments, notifications, history; notifications say unavailable when no read exists | Existing account read |
| Teacher Today | Four-item navigation; active class first; honest join/report states | Existing teacher read |
| Teacher Classes | Dedicated screen over the same authorised rows | Teacher lane owner |
| Teacher Finance | Formatted minor units, explicit empty/unavailable states | Finance owner |
| Teacher Account | Settings screen from the page-template and canonical entries | Teacher lane owner |
| Teacher Onboarding | Server first-incomplete step; timezone confirmed; HHMM/2400 preserved; review/Google honest | Teacher owner POST-by-POST |

## 2. Protected response contract

Canonical auth and protected routes call `dzn_theme_send_protected_response_headers()` after
`nocache_headers()`:

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
- Student payments tab: rendered only when the owner-bound commercial read returns obligations.
- Teacher Classes: available to a ready teacher; onboarding still overrides the shell.
- No notification badge, unread count, receipt, profile edit, teacher private note, deadline
  or instalment promise is inferred.
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
