# Enrolment workspace evidence

Date: 2026-11-02

All screenshots were taken against the disposable WordPress + MariaDB acceptance environment with synthetic `@example.invalid` identities. No production data, provider, charge or live message was used.

| Screenshot | What it shows |
| --- | --- |
| `enrolment-new-bookings-desktop-2026-11-02.png` | Action-needed queue, conversion report, guided intro action and collapsed History |
| `enrolment-new-bookings-tablet-2026-11-02.png` | Tablet layout of the same queue |
| `enrolment-new-bookings-mobile-2026-11-02.png` | Mobile layout with responsive filters and action cards |
| `enrolment-trial-case-desktop-2026-11-02.png` | Full trial case, hold deadline/countdown, notification evidence, prior bookings and post-intro actions |
| `enrolment-trial-case-mobile-2026-11-02.png` | Mobile trial case |
| `enrolment-classes-desktop-2026-11-02.png` | Today/upcoming classes, Term progress, remaining classes, replacement allowance, attendance and import entry |
| `enrolment-classes-tablet-2026-11-02.png` | Tablet class list |
| `enrolment-classes-mobile-2026-11-02.png` | Mobile class list with horizontal table scroll |
| `enrolment-class-detail-desktop-2026-11-02.png` | Contextual class detail, administrator classroom actions, shared class actions and attendance evidence |
| `enrolment-class-detail-mobile-2026-11-02.png` | Mobile class detail |
| `enrolment-import-preview-desktop-2026-11-02.png` | Historical import preview showing 5 completed / 7 remaining future classes and explicit historical-status warnings |

## 2026-11-08 update: unified Enrolment landing

The three Enrolment sub-tabs were replaced by one operational surface:

- Overview snapshot for introductory lessons (total, scheduled, completed, converted, awaiting, completed/elapsed) and Terms (active, renewed, almost ending).
- Manual `Create introductory class` and `Create standard class` actions. Standard allocation supports both canonical and historical/legacy Enrolments through their owning Lesson and Schedule authorities.
- Collapsible intro conversion, post-intro survey results, onboarding review queue and class history.
- One attention-first intro and class queue with type/attention/today/stage/Teacher/Student/Course/Term/date filters, colour highlighting, `Open Class admin` and direct Google Meet retry.
- Visible coordination labels renamed to **Class admin**; the protected route remains stable.
- Class detail shows the Teacher's private note when the schema and note exist.

Runtime verification on the disposable WordPress/MariaDB harness (schema 59 plus the working-tree overlay) rendered the landing page, filters, class/intro detail, failed-Meet retry button and historical standard-class creation without PHP or database errors. Canonical standard-class creation remains covered by its source contracts; the disposable fixture could not construct a canonical Enrolment identity graph.
