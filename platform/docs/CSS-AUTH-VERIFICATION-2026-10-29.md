# Authenticated Student/Teacher Portal verification — 29 October 2026

Follow-up to the CSS consolidation in PR #402. This record covers the
authenticated presentation checks that could not be completed against the
production database because no authorised production Student/Teacher test
accounts are configured.

## Production state

| Item | Value |
|---|---|
| Deployed SHA | `9ba61e639b1a42dcf4ed95e8062894a78238f6a7` |
| Build | `main-9ba61e639b1a` |
| Deploy run | `40820864508` / follow-up `40820942398` — success |
| Schema | `54` |
| Current `origin/main` at verification | `099d6d0` (PR #403 ops diagnostic on top of the deployed merge) |
| Production CSS | `theme.css` 108,637 B, `teacher-portal.css` 34,908 B, `booking.css` 20,600 B |

The deployed CSS was fetched from production and compared with the local
worktree. The live public pages were re-checked at 320–1440 px with no document
overflow.

## Authenticated test environment

Production test accounts are not available:

- no QA Student/Teacher secrets exist in the repository or the protected
  `production` environment;
- creating accounts or synthetic lessons on production would modify live data
  and was therefore not done.

Authenticated flows were exercised in the repository's isolated acceptance
runtime (`http://127.0.0.1:18081`, loopback only) with:

- the real theme and CSS from this branch;
- the current Platform schema;
- the Phase-J synthetic fixture and the post-intro runtime, producing
  **12 canonical lessons, schedules and enrolments**;
- two authenticated principals:
  - Student `post-intro-main-*` → `/student-portal/`;
  - Teacher `dzn-j-teacher-0-*` → `/teacher-portal/`;
- teacher timezone `Asia/Tehran`, student timezone `Australia/Brisbane`.

No production data, provider, payment or mail transport was touched.

## Student Portal results

| Check | Result |
|---|---|
| Login and role routing | PASS → `/student-portal/` |
| Dashboard/greeting | PASS — “درود بر شما، باران” |
| Today/upcoming schedule | PASS — authenticated lessons rendered |
| Lesson cards and absence disclosure | PASS — 12 disclosures; opening exposes 3 form fields and the labelled submit action |
| Teacher/course names | PASS — Persian names and course title |
| Date/time/timezone | PASS — Brisbane timezone label, Gregorian dates, UTC instants converted correctly |
| Absence interface | PASS — disclosure opens; no submission made |
| Success/error notice | PASS — read-only `absence_status=absence_recorded` renders the status message |
| Replacement/reschedule proposals | Not present in this synthetic dataset |
| Notifications | Not present (0 rows) |
| Payments | Not rendered (payments presentation flag/state unavailable) |
| Account settings | PASS — profile fields, timezone and calendar controls |
| Mobile navigation | PASS — tab bar fits, 54 px targets |
| Empty/pending/error/completed states | Empty/error states verified in the prior synthetic-template pass; authenticated data has no empty student state |

## Teacher Portal results

| Check | Result |
|---|---|
| Login and role routing | PASS → `/teacher-portal/` |
| Dashboard | PASS — authenticated teacher name and today screen |
| Today/upcoming classes | PASS — 20 upcoming compact cards plus today section |
| Class-card disclosure | PASS — `aria-expanded` false→true→false; details panel hidden→visible→hidden |
| Student/course names | PASS — Persian student and course data |
| Date/time/Jalali | PASS — `پنجشنبه ۷ آبان ۱۴۰۵` and Tehran timezone |
| Availability settings | PASS — 320 px viewport, no overflow |
| Rescheduling presentation | PASS — one form per future card, unique `tp-reschedule-*` ID |
| Absence/replacement attention cards | Not present in this synthetic dataset (prior demo-model evidence remains) |
| Notifications | No announcement/attention rows in this dataset |
| Mobile tabs | PASS — 4 equal columns, 54 px links, content clears the 96 px bottom padding |
| Dialog | PASS — tour opens, Escape closes, focus returns to the trigger |
| Form validation | PASS — required reschedule fields report invalid without submission |
| Error notice | PASS — `class_status=join_unavailable` renders an `role="alert"` notice |

## Responsive and accessibility

Authenticated sweep: **36 checks** — Student home/account and Teacher
home/classes/finance/settings at **320, 375, 430, 768, 1024 and 1440 px**.
Every page reported `scrollWidth === viewport width`, zero duplicate IDs, zero
unnamed controls, zero missing image alternatives.

- 200% text resize: Student home/account, Teacher home/classes/finance/settings
  all `scrollWidth === viewport width` after the fix below.
- Keyboard sequence on Teacher classes: 10/10 focused controls, including
  chevron, calendar links and reschedule summaries, showed a 3 px
  `:focus-visible` outline.
- Dialog focus return: PASS.
- Touch targets: mobile tab links 89 × 54 px.
- RTL disclosure alignment and Persian wrapping: verified visually in the
  captured states.

## Confirmed defects fixed in this follow-up

1. **Teacher classes overflowed by 5 px at 200% text.**
   Cause: `.dzn-tp-state` forced a single line (`white-space: nowrap`) for long
   Persian status text such as “پایان یافته — ثبت گزارش”, which exceeded the
   card’s info column at 200% text.
   Fix: the state label now allows wrapping, is capped at `max-inline-size:
   100%` and uses `overflow-wrap: anywhere`.

2. **Repeated `_wpnonce` DOM ids on multi-form portal screens.**
   Cause: WordPress core renders every nonce field with `id="_wpnonce"`.
   Teacher settings and other multi-form screens produced duplicate ids.
   Fix: `dzn_theme_nonce_field()` delegates to `wp_nonce_field()` but assigns a
   request-unique id, and all portal/teacher templates now use it. Field names,
   actions and verification are unchanged.

Both fixes are covered by `platform/tests/portal-nonce-id-contract.php` and the
existing CSS architecture contract.

## Evidence

- `platform/docs/evidence/css-audit-2026-10-29/authenticated-2026-10-29/verification.json`
- `student-home-mobile.webp`
- `student-account-mobile.webp`
- `teacher-classes-mobile.webp`
- `teacher-settings-narrow.webp`
- `teacher-class-details-expanded.webp`

## Outstanding limitation

Authenticated **production** Student/Teacher sessions remain unverified because
no authorised production test accounts are available. The isolated
authenticated verification above uses the deployed theme/CSS and real template
paths with synthetic data; it does not claim to prove production permissions,
live lesson data or provider state. Production public smoke, signed-out routing,
CSS serving and deploy identity were verified separately.
