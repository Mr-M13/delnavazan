# Teacher Onboarding v2

Teacher invitation is the approval decision. A linked teacher completes the profile, chooses an IANA timezone, declares recurring availability, and reviews readiness in separate portal steps. When the canonical profile and availability checks pass, `TeacherOnboardingService` stores `active / ready` and records the transition; no routine admin review is required. Admin screens retain invitation delivery and onboarding diagnostics.

For a new account, invitation claim creates a WordPress user with a random temporary password, finalizes the invitation claim, and asks WordPress to send its native new-user password setup email. The temporary password is never shown. An already-authenticated existing-account claim does not alter that account's password.

Recurring times are entered as 24-hour `HHMM`. `0000` is midnight at the start of the day. `2400` is accepted only for an interval end and represents the next local day's midnight boundary. Recurring intervals on the same weekday use half-open semantics; overlap is rejected and adjacency is allowed. Day copying is a single canonical transaction and aborts without writes if any selected destination conflicts.

Golden-hour examples convert student-region windows into the teacher's selected timezone using PHP IANA timezone data. They show the upcoming Monday as a DST-aware guide. Availability writes and readiness remain authoritative in the Platform services.

Validation contract: `platform/tests/teacher-onboarding-v2-contract.php`. PHP lint and that contract should run in the repository CI/runtime because the local workspace may not provide PHP.
