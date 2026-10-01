# Teacher onboarding v1

Teacher onboarding is invitation-only. Platform services remain authoritative; the Theme renders their state and posts commands to bounded Platform controllers.

## Lifecycle

| State | Meaning | Allowed next state |
|---|---|---|
| `linked_pending` | Invitation claim linked a WordPress principal; normal portal access is denied | `in_progress` |
| `in_progress` | Teacher may edit the canonical profile and own availability | `pending_review` |
| `pending_review` | Snapshot is awaiting an operator with `dzn_manage_onboarding` | `active`, `returned`, or `rejected` |
| `returned` | Changes requested; teacher may edit and resubmit | `pending_review` |
| `rejected` | Not approved; teacher may edit and resubmit | `pending_review` |
| `active` | Admin-approved and readiness is `ready` | `offboarded` through existing authority |
| `offboarded` | Principal authority revoked | terminal in v1 |

Every lifecycle transition is appended to `teacher_onboarding_events`. Progress-only updates increment the existing onboarding aggregate version.

## Readiness gate

Approval revalidates all conditions:

- canonical Teacher is active and not archived;
- display name, valid email, country code, IANA timezone, locale and existing calendar preference are present;
- the active canonical availability profile matches the Teacher timezone;
- at least one active `preferred` or `requestable` recurring availability rule exists;
- agreement state is `not_required` for v1 because the repository defines no concrete agreement or document requirement.

A claimed account is deliberately `linked_pending / not_ready`. Normal teacher portal reads call the existing principal authority check and fail closed until admin approval produces `active / ready`.

## Boundaries

- Invitation issue/reissue/revoke, recipient binding, digest storage, claim attempts and principal linking continue to use `PrincipalInvitationService`.
- Teacher availability mutations continue to use `TeacherAvailabilityService`; self-service methods scope commands to the logged-in linked Teacher.
- Review authority remains `dzn_manage_onboarding`; no role was added.
- The Theme contains no lifecycle decisions or duplicate readiness calculation.
- Returned and rejected teachers may correct and resubmit.

## Invitation delivery

No concrete invitation transport is present. Issuing continues to create a delivery intent in the existing outbox. The admin screen exposes the safe code-entry destination, but never renders, stores, logs or places the one-time secret in a URL. A configured delivery worker must call the existing delivery preparation boundary and transmit its ephemeral code. No email subsystem is introduced here.

## Staging validation

1. Run schema migration 034 and verify the lifecycle columns and append-only event table.
2. Issue an invitation to an active canonical Teacher.
3. Prepare/deliver the code through the authorized delivery boundary, then claim at `/teacher-invitation/`.
4. Confirm claim produces `linked_pending / not_ready` and redirects the first login to onboarding.
5. Save canonical profile, synchronize timezone, add a requestable/preferred rule and submit.
6. Confirm normal teacher portal reads remain denied while `pending_review`.
7. Return and reject once each; confirm editing and resubmission remain available.
8. Approve; confirm `active / ready` and normal teacher portal access.
9. Offboard; confirm authority and portal access are revoked and an event is appended.
