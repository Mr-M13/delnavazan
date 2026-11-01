# Admin Overview: manual balances, Stripe activity and classroom troubleshooting

Date: 2026-11-02

## What changed

The Overview keeps its task-first structure and now
shows the two financial sources explicitly, side by side:

- **Manual bookkeeping** — the existing two-account cashbook. `Iran Balance` and
  `Australia Balance` are read from `OperationalCashReadService::accountSummaries()`,
  the same canonical balance calculation used by the cashbook and Central Ledger.
  Each balance links to the corresponding filtered ledger view. Stripe never writes
  these balances.
- **Monthly payment activity** — canonical Stripe student evidence and recorded
  Teacher statement settlements, grouped by explicit currency. The cards show
  `Students Paid`, `Student Receipts`, and `Teacher Payments` for the current
  calendar month in the Academy timezone. The period and currency are labelled.

The two sources are never added into one net figure.

Today's classes now provide compact administrator classroom actions whenever the
corresponding resource is provisioned:

- **Join Class** — administrator LiveKit entry through a short-lived, user-bound
  launch ticket. Administrators are not restricted by the participant one-hour
  joining window.
- **Copy student link / Copy teacher link** — the normal portal entry URLs. They
  carry no administrator ticket and still require participant sign-in, identity
  checks and the normal joining window.
- **Open Google Meet / Copy Meet link** — the existing vaulted Google Meet
  projection. Opening is an authenticated POST; copying is a nonce-protected REST
  request. Both are audited.
- Missing or invalid provisioning is shown as `schedule required`, `not joinable`,
  `not provisioned` or `unavailable`; no link is fabricated.

## Authority and safety

- Participant LiveKit policy is unchanged: `EARLY_SECONDS = 3600`,
  `LATE_SECONDS = 7200`, and the same identity/ownership checks.
- Administrator access uses a distinct identity in the same canonical room. The
  grant contains no API secret and the browser receives only a five-minute,
  room-scoped participant token.
- Administrative classroom access and Meet-link access write append-only
  `platform_audit_events` rows (`admin_classroom.livekit_access`,
  `admin_classroom.meet_link_accessed`) with the authenticated administrator.
- Attendance capture treats the administrator as a non-attendance observer; their
  presence is never used as attendance evidence. A pre-class administrator room is
  validated against the current canonical Lesson/schedule without weakening the
  participant window for Teacher or Student joins.
- Manual bookkeeping and Stripe remain separate authorities. The new monthly
  projection reads accepted Stripe evidence, accepted refunds, successful
  introductory payment events, and `payment_recorded` statement events only. It
  does not read checkout attempts or the cashbook.

## Verification

Source contracts and units:

- `platform/tests/admin-dashboard-finance-classroom-contract.php`
- `platform/tests/finance-monthly-activity-unit.php`
- `platform/tests/livekit-administrator-classroom-unit.php`
- `platform/tests/admin-dashboard-ux-contract.php`
- `platform/tests/admin-dashboard-screen-unit.php`
- `platform/tests/admin-dashboard-review-fixes-contract.php`
- `platform/tests/livekit-classroom-authorization-unit.php`
- `platform/tests/livekit-portal-integration-contract.php`
- `platform/tests/finance-administration-contract.php`
- `platform/tests/teacher-statement-payment-contract.php`
- `platform/tests/phase-2a2u-contract.php`
- `platform/tests/teacher-class-window-unit.php`
- `platform/tests/static.php`

Disposable WordPress/MariaDB evidence:

- Manual balances matched the canonical cashbook: `12,500.00 AUD` and
  `980,000,000 TOM`.
- Synthetic accepted Stripe evidence produced `1` distinct Student, `A$240.00`
  gross minus `A$40.00` accepted refund = `A$200.00` net receipts.
- A synthetic `payment_recorded` statement event produced `A$90.00` Teacher
  payments. An issued-but-unpaid statement did not change the figure.
- Adding another synthetic Stripe charge did not change either manual balance.
- Administrator LiveKit grant succeeded one day outside the participant window,
  returned a distinct administrator identity in the same canonical room, and
  exposed no API secret.
- The participant portal links contained no administrator token.
- The existing vaulted Meet URL was returned only to the authorised administrator
  and two audit rows were recorded.
- The disposable login-routing smoke suite passed.

## Screenshots

- `evidence/admin-overview-combined-desktop-2026-11-02.png` — full Overview.
- `evidence/admin-overview-combined-mobile-2026-11-02.png` — mobile Overview.
- `evidence/admin-overview-classroom-actions-2026-11-02.png` — administrator classroom
  actions on a provisioned class.

## Rollback

No migration. Revert the changed controllers, read service, LiveKit administrator
service/controller, assets and tests. Existing finance and classroom records are
untouched by rollback.

See `ADMIN-OVERVIEW-COMPLETION-2026-11-02.md` for the complete reconciled checklist and final evidence.
