<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * One bounded window for Teacher-owned manual attendance and introductory outcomes.
 *
 * Automatic evidence reconciliation polls for seven days after the occurrence, and the
 * post-intro continuation hold is about six days. A Teacher therefore has seven days after the
 * class end to supply the human fallback. After that window only the administrator correction
 * path remains, so an old class cannot be bulk-marked long after the fact.
 */
final class ManualAttendanceWindowRule {
    public const DAYS=7;

    /** The inclusive UTC instant at which the Teacher manual window closes. */
    public static function closesAt(string $endsAtUtc):?string{
        $end=strtotime(trim($endsAtUtc).' UTC');
        if($end===false)return null;
        return gmdate('Y-m-d H:i:s',$end+self::DAYS*86400);
    }

    /** Whether the Teacher may still submit a manual outcome for this occurrence. */
    public static function open(string $endsAtUtc,?string $now=null):bool{
        $close=self::closesAt($endsAtUtc);
        if($close===null)return false;
        $now??=gmdate('Y-m-d H:i:s');
        return $now<=$close;
    }

    /** Stable user-facing duration. */
    public static function label():string{
        return self::DAYS.' days';
    }
}
