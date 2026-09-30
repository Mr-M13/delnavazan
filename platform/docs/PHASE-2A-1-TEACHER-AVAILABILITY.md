# Phase 2A.1-B — Teacher Availability Foundation

Availability is a Core-owned, local-wall-clock fact. It is not inferred from Amelia, Google Calendar, Hamnavaz, WordPress profiles, historical bookings, or Lesson timestamps.

Migration `005_teacher_availability_foundation` introduces one versioned Teacher profile, recurring weekly rules, and dated exceptions. Rules and exceptions carry an IANA timezone and use half-open intervals: `[start, end)`. Exact facts are database-unique; mutable facts are serialized by the lock order `Teacher → availability profile → dependent rule/exception` and guarded by version checks.

The effective evaluator never routes, reserves a time, chooses a Teacher, creates a Lesson, or proves Teacher/Course eligibility. It requires an active, unarchived Core Teacher with explicit `accepting` or `limited` state, and returns no availability for `paused` or missing accepting state.

Dated exceptions outrank recurring facts. Within the same source, precedence is `blocked > preferred > requestable`. Overlaps resolve to deterministic effective segments; adjacent half-open intervals do not overlap. A full-day exception does not delete its recurring rule.

Local dates/times are interpreted through PHP IANA timezone transitions. Nonexistent spring-forward wall times and ambiguous fall-back wall times are rejected; the implementation never guesses an offset or occurrence. Cross-midnight availability is not represented in this slice: every rule/partial exception must end later on its local date.

The only user-facing surface is a protected administrator page for timezone profiles, recurring rules, and dated exceptions. It has no public write route.
