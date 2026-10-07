# Teacher onboarding v1

Teacher onboarding is invitation-only. Platform services remain authoritative; the Theme renders their state and posts commands to bounded Platform controllers.

## Lifecycle

| State | Meaning | Allowed next state |
|---|---|---|
| `linked_pending` | Invitation claim linked a WordPress principal; normal portal access is denied | `in_progress` |
| `in_progress` | Teacher may edit the canonical profile and own availability | `active` when profile and availability are complete |
| `pending_review` / `returned` / `rejected` | Legacy states from the former review workflow; reconciled on the next teacher read | `active` or `in_progress` |
| `active` | Invitation-approved and readiness is `ready` | `offboarded` through existing authority |
| `offboarded` | Principal authority revoked | terminal in v1 |

Every lifecycle transition is appended to `teacher_onboarding_events`. Progress-only updates increment the existing onboarding aggregate version.

## Readiness gate

Automatic activation requires all conditions:

- canonical Teacher is active and not archived;
- display name, valid email, country code, IANA timezone, locale and existing calendar preference are present;
- the active canonical availability profile matches the Teacher timezone;
- at least one active `preferred` or `requestable` recurring availability rule exists;
- agreement state is `not_required` for v1 because the repository defines no concrete agreement or document requirement.

A claimed account is deliberately `linked_pending / not_ready`. Normal teacher portal reads call the existing principal authority check and fail closed until the teacher's completed profile and availability produce `active / ready`. The invitation is the approval decision; no later staff review is required.

## Boundaries

- Invitation issue/reissue/revoke, recipient binding, digest storage, claim attempts and principal linking continue to use `PrincipalInvitationService`.
- Teacher availability mutations continue to use `TeacherAvailabilityService`; self-service methods scope commands to the logged-in linked Teacher.
- `dzn_manage_onboarding` controls invitation operations and diagnostics; it does not approve or reject completed profiles.
- The Theme contains no lifecycle decisions or duplicate readiness calculation.
- Historical returned and rejected teachers may correct their profile and availability; completion activates them automatically.

## Invitation delivery

Issuing creates a delivery intent in the existing outbox. The admin screen exposes the safe claim destination, but never renders, stores, logs or places the one-time secret in a URL. A configured delivery worker calls the existing delivery preparation boundary and transmits its ephemeral code. Transport acceptance is not recipient delivery.

## Staging validation

1. Run schema migration 034 and verify the lifecycle columns and append-only event table.
2. Issue an email-only invitation and verify it creates one blank canonical Teacher profile.
3. Prepare/deliver the code through the authorized delivery boundary, then claim at `/teacher-invitation/`.
4. Confirm claim produces `linked_pending / not_ready` and redirects the first login to onboarding.
5. Save canonical profile, synchronize timezone and add a requestable/preferred rule.
6. Confirm completion produces `active / ready` and normal teacher portal access without a staff review step.
7. Confirm legacy `pending_review`, `returned`, and `rejected` records become active when complete or return to setup when incomplete.
8. Offboard; confirm authority and portal access are revoked and an event is appended.
