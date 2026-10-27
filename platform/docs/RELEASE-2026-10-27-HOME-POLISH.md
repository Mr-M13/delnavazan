# Release 2026-10-27 — home menu, instrument preselection and currency selector

Status: **deployed to production and verified**. Follows the presentation/portal
release `508a822`.

## Release identity

| Fact | Value |
| --- | --- |
| Deployed source SHA | `9d568c69bd604d2e57fa551e3c2173a3287402d9` |
| Production build stamp | `main-9d568c69bd60` |
| Platform schema | `52`, unchanged |
| Previous production SHA | `508a8224e76943f25c5e56bd5996f41f08cb12aa` |
| Deployment workflow | `Deploy production`, run `40500551506` |
| Backup | `/home/delnavaz/backups/delnavazan-production-cutover/20261027T120440Z-823742-9d568c69bd604d2e57fa551e3c2173a3287402d9` |

## Behaviour

- The primary header menu is now **خانه / ورود / مقالات**. The duplicate
  enrolment action is removed because the homepage hero already owns it; the
  login CTA is labelled **ورود**. The same filter applies to a real WordPress
  menu if one is assigned.
- Each homepage instrument tile carries a server-built canonical enrolment URL
  using the Platform catalogue name. Clicking the tile opens `/enrol/` with the
  same instrument selected. This handles catalogue label differences such as
  `سه‌تار` → `سه تار` and `تنبک` → `تمبک`.
- The visible «منطقهٔ قیمت‌گذاری» label and the approximation/privacy copy were
  removed.
- The currency selector is compact and lists ISO codes: **AUD, NZD, USD, CAD,
  EUR, GBP**. The Platform remains the sole price authority.

## Verification

| Check | Result |
| --- | --- |
| PR #381 runtime gate | PASS — 7/7 jobs |
| Deployment source gate on `9d568c6` | PASS — 7/7 jobs |
| Production read-only smoke | PASS |
| Menu on `https://delnavazan.com/` | خانه / ورود / مقالات |
| Setar tile click | `/enrol/?instrument_name=سه تار`; option id `15` selected |
| Tombak tile click | `/enrol/?instrument_name=تمبک`; option id `17` selected |
| Currency selector | ~111px wide, 37.5px high; options AUD, NZD, USD, CAD, EUR, GBP |
| Currency change | AUD updates the displayed tuition to `A$۲۵۰.۰۰` |
| Removed pricing copy | absent from the rendered pricing block |

No migration ran; schema remains 52. A code-only rollback to `508a822` is safe.
