# M0 canonical Enrolment lifecycle read audit

Production reads of `enrolment_lifecycle_events` were classified during correction round 1:

- `CoreReadService`: protected detail, list, and lifecycle-history reads validate canonical projection plus complete ordered history; legacy rows bypass canonical validation.
- `EnrolmentApplicabilityService`: canonical applicability and linked-source reads validate the complete history.
- `EnrolmentConversionAssessment` / `EnrolmentConversionService`: readiness, already-converted, and replay paths validate the shared graph and stricter conversion provenance.
- `CanonicalEnrolmentLifecycleService`: mutation preconditions, postconditions, and replay validate complete canonical history.
- `EnrolmentRepository`: raw persistence helper only; production callers above own capability and validation semantics.
- `EnrolmentConversionRepository` and `CanonicalEnrolmentLifecycleRepository`: aggregate persistence/locking helpers only; their authority services validate before returning success.
- Direct event-table writes under `tests/` are fixture or deliberate corruption injection only.
