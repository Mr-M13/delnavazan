# Project memory and business rules
Historical decisions supplied during development; verify implementation before relying on them.

## Academy
Persian-first online academy serving Iranian diaspora in Australia/NZ, Europe, UK, US and Canada. Minimum student age 7. Tar and Setar are key instruments; others include Santur, Kamancheh, Daf, Tombak, Ney, Oud, Ghanoon, voice, saxophone, piano, violin and guitar.

## Enrolment
New student: 1 free introductory session plus 12 teaching sessions (13 total). Renewal: 12 sessions. Teaching time 30 minutes with a 15-minute buffer. Payment is requested after the first paid teaching session (session 2), per the recorded business decision; verify latest payment implementation. Renewal reminder around session 10. A half-term instalment option and beta discount have been discussed; do not assume all promotions are active.

## Attendance and changes
Student class actions include attend/cannot attend. Meet presence of at least 15 minutes was proposed as evidence of attendance, with teacher confirmation as fallback when automated evidence fails. An absence must not silently trigger rescheduling. Rescheduling requires eligibility checks and appropriate admin authority. Renewal cancellation notice at least one week before renewal; term refund/make-up policies require checking against current published legal documents.

## Roles and experience
Single login with role routing to student/teacher/admin. Student portal: upcoming lessons, counterpart, instrument, preferred timezone/calendar, and secondary add-to-calendar. Teacher portal: today/upcoming classes, timezone-aware date/time changes, available slots and teacher finance statements. Persian/Jalali/RTL support is essential.

## Teacher onboarding
Recorded desired defaults: Asia/Tehran timezone, Persian calendar, Iran country; simplify password/name fields; remove language field. Explicit mandatory teacher consent to applicable terms, recording and research was requested, with distinguishable stored records. Legal validity and current production behaviour require independent verification.

## Coverage and golden hours
Golden hours are higher-demand hours that may earn higher teacher compensation. Latest UX concept (2026-11-12): one 24-hour coverage clock per instrument; uncovered slots greyed out, available teacher coverage coloured; when a booking exhausts the only available teacher for a slot, that slot becomes unavailable/grey. Teacher profile may display the clock for their instrument above availability. This is a **design proposal**, not verified implementation.

## Communications
WhatsApp primary, email alternative, SMS fallback only after documented terminal WhatsApp failure when enabled. More recent admin UX proposal: notification management by business task with per-channel toggles for portal, email, WhatsApp, SMS fallback and primary SMS; templates edited from each row, filterable headers, teacher onboarding coverage, diagnostics in Settings. Distinguish configured channels from actual delivery reliability.

## Recording
Earlier LiveKit capture architecture was superseded: current intended class recording uses Google Meet -> Google Drive -> later SpaceIn inbox. Do not restore LiveKit capture merely because older code/docs mention it.
