<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * §8.2/§6.2.4 — the read-only projection of the subject owner's authoritative read model.
 *
 * For a tier-F intent the port returns the *persisted, immutable* announced instant exactly as the owning
 * module committed it; S never recomputes it from the current commercial policy, the current
 * pattern/schedule or a fallback derivation, and a NULL means the instant is not durably available.
 */
interface NotificationSubjectReadPort {
    /**
     * @return array|null `exists`, `instant` (the tier-F persisted column, null when absent),
     *         `timezone` (for a `subject_local` basis) and `subject_reference_digest`.
     */
    public function subject(string $aggregate,int $aggregateId,?string $instantColumn):?array;
}
