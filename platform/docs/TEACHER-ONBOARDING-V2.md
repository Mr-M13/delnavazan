# Teacher Onboarding v2

An invitation authorises a person to create or connect a Teacher account; it does not grant teaching readiness. A linked teacher completes the profile, chooses an IANA timezone, declares recurring availability, and submits the result. `TeacherOnboardingService` stores `pending_review / not_ready` until a user with `dzn_manage_onboarding` approves it. Staff may return a submission for editable corrections or reject it without granting portal access.

For a new account, invitation claim creates a WordPress user with the password entered on the mobile-friendly claim form, finalizes the canonical principal link, signs the user in, and routes directly to onboarding. An already-authenticated existing-account claim does not alter that account's password. The invitation remains a 64-hex one-time secret accepted only in a POST body.

Recurring times are entered as 24-hour `HHMM`. `0000` is midnight at the start of the day. `2400` is accepted only for an interval end and represents the next local day's midnight boundary. Recurring intervals on the same weekday use half-open semantics; overlap is rejected and adjacency is allowed. Day copying is a single canonical transaction and aborts without writes if any selected destination conflicts.

Golden-hour examples convert student-region windows into the teacher's selected timezone using PHP IANA timezone data. They show the upcoming Monday as a DST-aware guide. Availability writes and readiness remain authoritative in the Platform services.

The Theme presents five honest steps: welcome, personal/professional details, availability, review/submission, and staff-decision status. The review step lists missing requirements with direct links and states Google’s mandatory status from the authoritative readiness policy. The status step distinguishes pending, returned, rejected and approved without weakening access or lifecycle transitions.

Readiness is complete only when profile, matching weekly availability and a usable Google Calendar connection are all present. Google is required by the current runtime policy; a connected account still does not prove that Google Meet is operational.

Teacher induction is a separate, non-blocking educational experience documented in
`platform/docs/TEACHER-INDUCTION-V2.md`. Its progress never appears in
`teacher_onboarding_states` and never activates, blocks or offboards a Teacher.

Validation contract: `platform/tests/teacher-onboarding-v2-contract.php` plus
`platform/tests/teacher-onboarding-presentation-contract.php`,
`platform/tests/teacher-induction-unit.php` and
`platform/tests/teacher-induction-contract.php`. PHP lint and those contracts should run in the
repository CI/runtime because the local workspace may not provide PHP.
