# Communications brand evidence — 2026-11-01

Generated from the disposable acceptance WordPress/MariaDB environment at `http://127.0.0.1:18081`
with synthetic `@example.invalid` identities only. No production message was sent and no real student
or teacher data is shown.

The browser was Chrome headless. Desktop viewport is 1440px; compact viewport is 500px (the headless
window minimum on this host). Email and document previews use the same renderer classes and canonical
tokens as production; only the logo URL is rewritten to the local acceptance host so the preview is
self-contained.

## Before / after

| Surface | Before | After |
|---|---|---|
| Public footer | `before-footer-desktop.png` | `after-footer-desktop.png`, `after-footer-mobile.png` |
| Student email | `before-email-student.png` | `after-email-student.png`, `after-email-student-mobile.png` |
| Teacher statement email | `before-email-statement.png` | `after-email-teacher-statement.png` |
| Student receipt | `before-receipt.png` | `after-student-receipt.png` |

Before images were rendered from commit `669da78a` (the feature branch base). After images are from the
feature branch.

## Additional after evidence

- `after-homepage-desktop.png`, `after-homepage-mobile.png` — homepage hero and composition.
- `after-teacher-notification-desktop.png`, `after-teacher-notification-mobile.png` — teacher portal
  home with an unread notification: text badge «تازه», dot, date, two read-only actions, support
  contact and the canonical footer.
- `after-onboarding-desktop.png`, `after-onboarding-mobile.png` — teacher onboarding welcome and steps.
- `after-induction-desktop.png`, `after-induction-mobile.png` — teacher induction hub and modules.
- `after-student-notification-desktop.png`, `after-student-notification-mobile.png` — the student
  notification component with synthetic data (the acceptance Student has no active enrolment, so the
  component is rendered directly with the real theme partial and stylesheet).
- `after-teacher-statement-document.png` — printable teacher statement with Persian labels, recipient
  calendar dates and timezone.

## What the evidence verifies visually

- One canonical palette: ivory page, white surface, dark teal headings, pomegranate rule, restrained
  gold accent, dark brand action.
- Vazirmatn on screen and documents; email uses the deliberate Vazirmatn/Tahoma/Segoe UI/Arial stack.
- Canonical contact identity: `admin@delnavazan.com`, `+61 413 413 004`, `tel:`, `wa.me`, canonical
  site, درود greeting and the tagline.
- Unread state is distinguishable by the text badge, not colour alone.
- Long Persian names, mixed LTR identifiers and financial values wrap or isolate without clipping.
- A proposed replacement remains described as proposed; accepted/confirmed wording stays separate.
