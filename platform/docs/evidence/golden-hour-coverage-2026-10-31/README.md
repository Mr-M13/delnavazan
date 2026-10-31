# Golden Hour Coverage Atlas evidence

Static presentation fixtures for the instrument-first Coverage Atlas.

- `overview.png` — atlas for the week of 2 November 2026. Default sort is largest gaps first.
- `detail.png` — the same atlas with **Piano · Australia** expanded. The grid shows all three measured states: `2+` (covered), `1` (fragile), and `Gap`.

## How the images were produced

The images were rendered locally from the real `AcademyCoverageView` and
`AcademyCoverageAnalyzer` with synthetic availability facts. The fixture HTML used
the checked-in `platform/src/Admin/Assets/admin.css` and a small amount of
WordPress-admin fallback styling. They were captured with headless Google Chrome.
No WordPress runtime, database, provider call, deployment, or metered service was
used.

## Data limitations shown by the design

- The view is read-only planning evidence. It uses active eligibility and declared
  preferred/requestable availability only.
- Active blocked rules and dated exceptions are applied through the existing
  `TeacherAvailabilityService::effective()` authority.
- Existing bookings and student-specific constraints are deliberately excluded.
  The booking-time scheduling authority remains the decision maker.
- A percentage is the share of the 70 golden-hour slots with at least one eligible
  teacher. It is not free capacity or a booking promise.
- No historical trend is shown because there is no reliable comparable persisted
  snapshot.
