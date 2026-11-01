# Teacher quick-start visual evidence — 2026-11-01

Captured in the disposable local acceptance runtime with a synthetic Teacher. No production
records were used.

| File | What it shows |
| --- | --- |
| `quickstart-desktop.png` | one-page quick-start with 9 short steps and progress |
| `quickstart-mobile.png` | mobile one-page layout |
| `quickstart-home-highlight-desktop.png` | step jump: highlighted Today classes and fixed explanation banner |
| `quickstart-home-highlight-top-desktop.png` | top-of-page banner and compact dashboard card |
| `quickstart-join-highlight-desktop.png` | join-step jump and Google Meet backup explanation |
| `quickstart-acceptance.json` | automated acceptance results |

Acceptance verified: 9 cards, progress `0 از 9`, step POST redirect, highlight banner, target
highlight, persisted `1 از 9`, first card completed, join-step banner, and mobile tabs.

Tests: `platform/tests/teacher-induction-unit.php`,
`platform/tests/teacher-induction-contract.php`,
`platform/tests/teacher-induction-presentation.php`, plus `acceptance: make teacher-onboarding`.
