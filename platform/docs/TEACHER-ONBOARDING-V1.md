# Teacher onboarding v1

Teacher onboarding is invitation-only. Platform services remain authoritative; the Theme renders their state and posts commands to bounded Platform controllers.

## Lifecycle

| State | Meaning | Allowed next state |
|---|---|---|
| `linked_pending` | Invitation claim linked a WordPress principal; normal portal access is denied | `in_progress` |
| `in_progress` | Teacher may edit the canonical profile and own availability | `pending_review` after explicit submission |
| `pending_review` | Complete submission is locked while staff reviews it | `active`, `returned`, or `rejected` |
| `returned` | Staff requested corrections; Teacher may edit and resubmit | `pending_review` |
| `rejected` | Staff declined the submitted profile; portal access remains denied | archive/re-invitation workflow |
| `active` | Staff-approved and readiness is `ready` | `in_progress` if required availability is removed, or `offboarded` |
| `offboarded` | Principal authority revoked | terminal in v1 |

Every lifecycle transition is appended to `teacher_onboarding_events`. Progress-only updates increment the existing onboarding aggregate version.

## Readiness gate

Submission and approval require all conditions:

- canonical Teacher is active and not archived;
- display name, valid email, country code, IANA timezone, locale and existing calendar preference are present;
- the active canonical availability profile matches the Teacher timezone;
- at least one active `preferred` or `requestable` recurring availability rule exists;
- a usable Google Calendar connection exists with an active credential, verified identity and the exact calendar scope. This is required by `TeacherOnboardingService::progress()` because event and Meet projection depends on it. A connected account is not proof that Meet is operational;
- agreement state is `not_required` for v1 because the repository defines no concrete agreement or document requirement.

A claimed account is deliberately `linked_pending / not_ready`. Normal teacher portal reads call the existing principal authority check and fail closed until the teacher submits complete information and staff records approval as `active / ready`.

## Boundaries

- Invitation issue/reissue/revoke, recipient binding, digest storage, claim attempts and principal linking continue to use `PrincipalInvitationService`.
- Teacher availability mutations continue to use `TeacherAvailabilityService`; self-service methods scope commands to the logged-in linked Teacher.
- `dzn_manage_onboarding` controls approve, return and reject decisions after submission.
- The Theme contains no lifecycle decisions or duplicate readiness calculation.
- Returned teachers may correct and resubmit. Rejected submissions remain denied until the controlled archive/re-invitation workflow establishes a new onboarding cycle.

## Invitation delivery

Issuing creates a delivery intent in the existing outbox. The admin screen exposes the safe claim destination, but never renders, stores, logs or places the one-time secret in a URL. A configured delivery worker calls the existing delivery preparation boundary and transmits its ephemeral code. Transport acceptance is not recipient delivery.

## Staging validation

1. Run schema migration 034 and verify the lifecycle columns and append-only event table.
2. Issue an email-only invitation and verify it creates one blank canonical Teacher profile.
3. Prepare/deliver the code through the authorized delivery boundary, then claim at `/teacher-invitation/`.
4. Confirm claim produces `linked_pending / not_ready` and redirects the first login to onboarding.
5. Save canonical profile, synchronize timezone, add a requestable/preferred rule and connect a usable Google Calendar credential.
6. Submit and confirm `pending_review / not_ready`; verify normal portal access remains denied.
7. Exercise return and resubmission, then approve and confirm `active / ready`. Exercise rejection on a separate submission and confirm access remains denied.
8. Offboard; confirm authority and portal access are revoked and an event is appended.
