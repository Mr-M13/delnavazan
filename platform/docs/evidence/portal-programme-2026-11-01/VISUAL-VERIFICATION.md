# Portal programme visual and authenticated staging evidence

**Date:** 2026-11-01
**Environment:** disposable local WordPress + MariaDB (`127.0.0.1:18081`), synthetic
`@example.invalid` identities, no production data or provider credentials.

## Authenticated staging fixture

The evidence was captured after:

1. `phase-2a2j-fixture.php` created canonical synthetic sources and Teacher/Student principals.
2. `teacher-statement-payment-runtime.php` created a completed delivered Lesson, a real
   completed-period issued statement and its append-only settlement.
3. `portal-programme.php` added three upcoming standard Lessons to the same Term.

The acceptance Teacher and Student users were linked to the synthetic Teacher/Student principals.
Both portals returned HTTP 200 with the authenticated session.

## Viewport results

Every page below was loaded in a fresh browser context at **1440, 820 and 390 CSS pixels**.
`document.documentElement.scrollWidth` exactly equalled `clientWidth` in all 21 loads, so there was
no horizontal overflow at desktop, tablet or mobile widths.

| Page | Authenticated markers observed |
| --- | --- |
| `/teacher-portal/` | `کلاس‌های امروز`, `اعلان‌ها`, one Today card |
| `?teacher-view=classes` | `تقویم کلاس‌های آینده`, `کلاس‌های گذشته`, one Today section |
| `?teacher-view=finance` | `دستمزد و پاداش`, `وضعیت پرداخت`, `تسویه‌شده`, `A$۵۰.۰۰` paid, `A$۰.۰۰` unpaid |
| `?teacher-view=settings` | Settings sections, exactly one capacity card for the instrument |
| `/student-portal/` | `پیشرفت دوره`, `جبرانی`, today/upcoming/past timeline |
| `?portal-view=account` | `وضعیت حساب`, `ضبط کلاس و پژوهش`, separate consent areas |
| `?portal-view=payments` | `پرداخت و اشتراک`, disabled `پرداخت پیشاپیش ترم آینده` with Persian explanation |

After inserting two canonical replacement Lessons, the Student page rendered exactly:

`جبرانی: ۰ از ۲ باقی‌مانده`

so exhausted replacement capacity is visible as zero rather than as a static allowance.

## Screenshots

All screenshots are stored in this directory.

| File | Evidence |
| --- | --- |
| `teacher-home-1440.png` | desktop Teacher Today |
| `teacher-home-820.png` | tablet Teacher Today |
| `teacher-home-390.png` | mobile Teacher Today |
| `teacher-classes-390.png` | mobile Classes/calendar/history |
| `teacher-finance-390.png` | mobile finance with settled statement and zero unpaid |
| `student-classes-1440.png` | desktop student term/timeline |
| `student-classes-390.png` | mobile student term/timeline |
| `student-exhausted-390.png` | mobile exhausted replacements (`۰ از ۲`) |
| `student-account-390.png` | mobile account status and consent section |
| `student-payments-390.png` | mobile payments and disabled advance-payment state |
| `classroom-rotation-0-1440.png` | 0° local presentation |
| `classroom-rotation-90-1440.png` | 90° local presentation |
| `classroom-rotation-180-1440.png` | 180° local presentation |
| `classroom-rotation-270-1440.png` | 270° local presentation |

The classroom rotation evidence uses a labelled test pattern with a “TOP / بالا” band so the
orientation is visible. Labels, watermark and controls remain upright while the video surface rotates.

## Additional staging proofs

- `livekit-classroom-runtime.php`: **PASS** (canonical Teacher/Student authorization and launch
  ticket; no LiveKit server or credential contacted).
- `recording-choices-ui-runtime.php`: **PASS** (separate recording/research choices, no
  preselection, audit state).
- `teacher-statement-payment-runtime.php`: **PASS** (real issued statement and settlement).
- `teacher-delivery-confirmation-runtime.php`: **PASS** (canonical completion and replay).
- `replacement-cancelled-release-runtime.php`: **PASS** (cancelled replacement releases the slot).
- `finance-administration-runtime.php`, `finance-schedule-runtime.php`,
  `finance-notification-runtime.php`: **PASS**.

## Limitations

- The two-party LiveKit audio path was not exercised against a live LiveKit server; the classroom
  evidence is the shared UI/contract and the canonical authorizer runtime.
- The student recording/research section rendered its unavailable state in the portal fixture
  because no approved notice was published in that fixture; the dedicated recording runtime proves
  the separate forms and audit choices.
- The advance-payment enabled path is unit-proven for an already-issued future-Term obligation. The
  staging fixture had no future-Term offer, so the authenticated page shows the honest disabled
  state; no invoice or booking was created.
- No real Stripe checkout or money movement was performed.
