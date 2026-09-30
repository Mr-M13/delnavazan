# Phase 2A.0 — Principal and invitation foundation

This slice establishes Core-owned Teacher and Student principal-link foundations.
It deliberately does not create booking requests, routing, availability,
Teacher response, Intro conversion, timers, payments, portals, or providers.

## Authority boundary

WordPress accounts authenticate people; they do not confer Delnavazan domain
authority. A Teacher-domain action must require both a minimal WordPress role
or capability and an active Teacher principal-link row plus active Teacher
onboarding state. Teacher and Student principal links are separate domains, so
one legitimate WordPress account can later hold one of each without leaking
Teacher authority into Student access.

## Invitation and secret handling

An administrator can issue, reissue, or revoke an invitation for an active,
unarchived Core Teacher. Issuance creates a recipient-bound queued-for-delivery
generation and a durable delivery intent; it does not generate, retain, or
return a raw secret. Reissue supersedes an unclaimed queued or active
generation. Each generation stores an immutable, normalized Core-owned
recipient snapshot and its digest. Each generation is single-use, revocable,
and expiry-bound; a changed recipient always requires a replacement generation.

The bounded Core delivery-preparation contract is internal-only: a trusted
delivery worker locks and revalidates the current queued generation, frozen
recipient snapshot/digest, Teacher, expiry, and pending intent. It obtains the
recipient only from that Core-owned snapshot; it never accepts caller-supplied
recipient text as delivery authority. It then generates the raw secret in
memory, persists only its keyed digest plus active/delivery-preparation facts,
and returns the recipient and secret only in the ephemeral send payload to the
transport boundary. There is no provider integration in this slice. A crash or
uncertain outcome after preparation must be recovered by superseding and
replacing the generation, not by attempting to recover or reuse a lost secret.
Only the current active generation may be claimed.

Audit and outbox tables contain invitation/generation references and
idempotency keys only; no raw reusable secret appears in the database, admin
UI, audit, outbox, logs, or URL.

## Claim and WordPress provisioning recovery

Existing-account claim requires the authenticated WordPress user to own the
frozen generation recipient snapshot; matching an email alone is never
authority. Claim validation does not resolve a recipient from a mutable Teacher
profile. A new WordPress user is provisioned outside the Core SQL transaction
using the frozen snapshot and is recorded by an
untrusted-to-domain, random attempt marker. A crash or uncertain external
outcome transitions the attempt to recovery_required; it must be reconciled,
not retried by blindly creating another account or deleting one.

Core finalisation is a transaction: it locks the invitation/generation and
Teacher, rechecks current eligibility and generation, consumes the generation,
writes the unique Teacher link, updates onboarding state, and writes audit
facts. Reissue/revoke makes a stale attempt non-finalisable. Repeating the
same command returns its existing durable attempt; competing claims are
rejected by the unique generation owner and unique Teacher/User link slots.

## Recipient privacy and retention

The recipient snapshot is contact PII held only on the invitation generation;
admin state views, audit events, and outbox rows remain reference-only. A
high-trust, service-only anonymisation operation may clear generation snapshots
and recipient digests, and replaces the parent digest with a non-recipient
tombstone, only after every generation is terminal (`claimed`, `revoked`, or
`superseded`). It rejects any invitation with a queued or active generation, so
no live delivery or claim can lose its authoritative binding. The operation
records a safe reason only and never writes the recipient into audit or outbox.

## Offboarding

Offboarding revokes active Teacher invitations and Teacher principal authority
without deleting the Core Teacher, lessons, history, WordPress account, or a
separate Student-domain link. Future high-trust relink policy remains outside
this slice.

## Required isolated runtime validation

Before production use, run fresh-install/rerun migration verification and the
invitation, claim, collision, recovery, capability/nonce, and raw-secret
non-persistence cases in isolated WordPress + MySQL. No beta Teacher/Student
account or provider delivery should be used for this foundation.

The exact reversible procedure, including the 002 → 003 recipient-snapshot
upgrade, terminal-recipient retention check, and cleanup boundary, is in
`PHASE-2A-0-RUNTIME-VALIDATION.md`. Its WP-CLI-only assertion helper is test
support, not plugin runtime code and is excluded from the installable package.
