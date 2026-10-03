<?php
namespace Delnavazan\Platform\Integrations;

/**
 * Single authority for when an authenticated Teacher may launch a scheduled class.
 *
 * Presentation code may expose the action only when this policy says the schedule
 * is inside the same bounded window enforced again by TeacherClassJoinService.
 */
final class TeacherClassJoinPolicy {
    public const EARLY_SECONDS = 3600;
    public const LATE_SECONDS = 7200;

    public static function available(string $startsAtUtc, string $endsAtUtc, ?int $now = null): bool {
        $start = strtotime(trim($startsAtUtc) . ' UTC');
        $end = strtotime(trim($endsAtUtc) . ' UTC');
        if (!$start || !$end || $end < $start) {
            return false;
        }

        $instant = $now ?? time();
        return $instant >= $start - self::EARLY_SECONDS
            && $instant <= $end + self::LATE_SECONDS;
    }
}
