# Phase 2A.1-D — Intake idempotency and privacy

The public Booking Request endpoint remains an intake boundary. It does not route,
reserve, create Students or Lessons, inspect Amelia, or admit/reject a request from
Teacher availability.

`Idempotency-Key` is mandatory. Its raw value is used only in memory: Core stores an
HMAC digest with an HMAC digest of the normalized request for 24 hours. Object-field
ordering is canonicalized, while requested-time list ordering is deliberately retained.
Within that window a matching replay returns the original reference; a different request
or a revoked/in-progress operation returns a safe conflict. The unique digest row is
created and completed in the same transaction as the aggregate.

Duplicate signals are exact HMAC comparisons of normalized email, mobile, and WhatsApp
values. They create non-blocking review flags only; they never merge, bind, reject, or
create a Student. Review outcomes are protected by a dedicated capability and nonce.

Privacy erasure is separately capability-gated. Only submitted, unresolved, unconverted
requests can be erased. It closes request workflow authority before clearing snapshot
PII and contact digests, revokes live replay authority, removes every duplicate-review row
involving the erased request (including candidate-side rows and their signal digests), and
retains an audit tombstone plus non-identifying operational request facts. This phase has
no automatic retention purge.

Anonymous rate limiting uses only an HMAC of the client network signal in transient
storage. It is deliberately fail-open when transient storage cannot be used; validation
and idempotency remain the integrity controls.
