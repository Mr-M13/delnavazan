# Teacher induction v2 visual evidence — 2026-11-01

Captured from the disposable local acceptance runtime (`http://127.0.0.1:18081`) with a synthetic
teacher only. No production account, student, lesson, absence, payment or provider record was used.

| File | Viewport | What it shows |
| --- | --- | --- |
| `induction-hub-desktop.png` | 1440×1000 | dedicated hub, progress `2 از 7`, module list, continue/skip/help |
| `induction-hub-mobile.png` | 390×844 | mobile hub with bottom tabs and stacked cards |
| `home-card-desktop.png` | 1440×1000 | compact dashboard card; full curriculum is not shown |
| `induction-absence-module-desktop.png` | 1440×1000 | absence/replacement policy, eight scenarios, safe knowledge checks, completion |
| `induction-absence-module-mobile.png` | 390×844 | mobile module reading and completion |
| `onboarding-welcome-desktop.png` | 1440×1000 | five-step onboarding, welcome and administrator-review explanation |
| `onboarding-welcome-mobile.png` | 390×844 | mobile welcome |
| `onboarding-profile-desktop.png` | 1440×1000 | profile fields and save/save-and-continue |
| `onboarding-availability-desktop.png` | 1440×1000 | positive availability, Golden Hours, weekly summary, day off |
| `onboarding-availability-mobile.png` | 390×844 | mobile availability |
| `onboarding-review-desktop.png` | 1440×1000 | review, Google status, missing-requirement links |
| `onboarding-review-mobile.png` | 390×844 | mobile review |
| `onboarding-status-active-desktop.png` | 1440×1000 | approved/active status and induction entry |

Rendering checks are automated in `platform/tests/teacher-induction-presentation.php`; acceptance
behaviour is exercised by `acceptance/fixtures/teacher-onboarding.php`.
