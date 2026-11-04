<?php
namespace Delnavazan\Platform\Portals;

/**
 * The single classroom joining window shared by Teacher and Student access.
 *
 * A participant may open the classroom from 30 minutes before the start
 * instant through 30 minutes after the end instant. Administrators keep their
 * separate testing grant; this class applies only to lesson participants.
 */
final class ParticipantJoinWindow {
    public const EARLY_SECONDS = 1800;
    public const LATE_SECONDS = 1800;

    public static function contains(int $start, int $end, int $now): bool {
        return $start > 0
            && $end > 0
            && $now >= $start - self::EARLY_SECONDS
            && $now <= $end + self::LATE_SECONDS;
    }
}
