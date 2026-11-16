<?php
namespace Delnavazan\Platform\Core\Application;

/** Purpose-specific outcome for retrying a non-atomic consent bundle. */
final class UnifiedConsentPartialFailure extends \RuntimeException {
    public function __construct(
        public readonly array $purposeOutcomes,
        public readonly string $failedPurpose,
        \Throwable $previous
    ) {
        parent::__construct('unified_consent_partial_failure',0,$previous);
    }
}
