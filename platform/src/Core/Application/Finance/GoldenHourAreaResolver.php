<?php
namespace Delnavazan\Platform\Core\Application\Finance;

/**
 * Resolve golden-hour eligibility only from the explicit canonical Enrolment service area.
 *
 * Country, Teacher timezone, browser timezone and the size of a bonus are deliberately not inputs.
 * A blank family value is unresolved and requires service-area review; it is never guessed.
 */
final class GoldenHourAreaResolver {
    /** @return array{area:?string,review_reason:?string} */
    public static function resolve(?string $serviceAreaKey): array {
        return GoldenHourRule::serviceAreaResolution($serviceAreaKey);
    }
}
