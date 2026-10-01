# Booking experience public read contract

Build phase2a3-booking-experience-read-contract-20260930.1 adds two presentation-only public endpoints to the existing booking request authority. It adds no tables and no booking, payment, identity, routing, reservation or lesson authority.

## GET /wp-json/delnavazan-platform/v1/booking-options

Returns only active, unarchived Instruments with an active, unarchived default Introductory Course whose Instrument relationship is valid. The safe public fields are Instrument ID, slug and names, default Intro Course ID/name, duration and buffer. The read path uses InstrumentIntroCourseDefaultService and the existing Instrument/Course repositories. It does not expose Teachers or inactive catalogue rows.

## POST /wp-json/delnavazan-platform/v1/booking-availability/preview

Accepts exactly instrument_id, course_id, and one to three requested_times, each containing local_date, local_start_time and an IANA timezone. Platform resolves the active booking option, uses the Course's current duration and buffer, rejects invalid/DST-gap/DST-fold wall times, and checks current course-eligible Teachers against Core Teacher availability and accepting state.

Each time is labelled:

- strong: complete buffer-inclusive coverage by an accepting Teacher using only preferred availability.
- possible: complete coverage exists but requires requestable availability or a Teacher with limited accepting state.
- none: no currently matching eligible Teacher coverage exists; the request may still be submitted.
- blocked: the requested occupied interval overlaps the academy-wide Iran-time blackout (01:00–06:00 in `Asia/Tehran`); it cannot be submitted.

The response contains only the submitted sequence number and one status. It never contains Teacher identities, availability facts, candidate lists, contact data or a reservation. It is a non-persisting read and exposes no Teacher identities. Teacher matching remains advisory, while the fixed blackout is enforced again by the existing public Booking Request submission validator. `none` remains requestable; `blocked` is rejected at submission.

## Existing booking request submission

POST /wp-json/delnavazan-platform/v1/booking-requests remains the only public intake write. Its strict field allowlist, IANA wall-time conversion, DST checks, 24-month private contact snapshot retention, idempotency, rate limit and opaque REQ-* reference remain authoritative. A successful request is submitted / unresolved; it does not create a Student, Lesson, Teacher assignment, payment, notification or reserved slot.

The free introductory meeting carries no payment choice at this stage. The 01:00–06:00 Iran-time blackout is an application rule in the existing normalization and validation paths; it adds no database table, migration, new route or booking authority. Later continuation/payment flows remain governed by their separate Platform authorities.
