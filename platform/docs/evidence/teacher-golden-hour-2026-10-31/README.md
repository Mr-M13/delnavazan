# Teacher Golden Hour Visual

Static presentation fixture for the Teacher account golden-hour planner.

- `golden-hours.png` shows the real `golden-hours` component with synthetic policy data:
  - 20% bonus;
  - 16:00–21:00 Asia/Tehran policy window;
  - Teacher timezone America/Toronto, rendered as 07:30–12:30 local.

The screenshot was rendered locally with the checked-in theme CSS and headless Google
Chrome. No WordPress runtime, database, provider call, deployment, or metered service
was used.

The component intentionally shows only the Teacher's local clock and the bonus
percentage. It does not show academy-region clock times or other Teachers'
availability.
