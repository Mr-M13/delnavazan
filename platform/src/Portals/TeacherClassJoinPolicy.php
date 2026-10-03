<?php
namespace Delnavazan\Platform\Portals;

/**
 * Single authority for the authenticated Teacher class launch window.
 *
 * The same bounded window feeds the Teacher read model and is re-enforced by
 * the join action so presentation cannot drift from action authority.
 */
final class TeacherClassJoinPolicy {
    public const EARLY_SECONDS = 3600;
    public const LATE_SECONDS = 7200;

    public static function available(string $startsAtUtc,string $endsAtUtc,?int $now=null):bool {
        $start=strtotime(trim($startsAtUtc).' UTC');
        $end=strtotime(trim($endsAtUtc).' UTC');
        if(!$start||!$end||$end<$start)return false;
        $instant=$now??time();
        return $instant >= $start-self::EARLY_SECONDS && $instant <= $end+self::LATE_SECONDS;
    }
}
