<?php
/**
 * Pure locked-policy proof for the LiveKit attendance adapter.
 *
 * The adapter does not own a second attendance rule: these assertions exercise the same
 * `CanonicalAttendanceRule` Phase P uses, including the exact 1200/1199 boundary.
 */
require_once dirname(__DIR__).'/src/Core/Application/CanonicalAttendanceValidator.php';
require_once dirname(__DIR__).'/src/Core/Application/CanonicalAttendanceRule.php';

use Delnavazan\Platform\Core\Application\CanonicalAttendanceRule;

function dzn_lk_threshold_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function dzn_lk_threshold_interval(string $join,?string $leave):array{return array('join_at_utc'=>$join,'leave_at_utc'=>$leave);}
function dzn_lk_threshold_assess(array $teacher,array $student,string $start='2026-10-31 10:00:00',string $end='2026-10-31 10:05:00'):array{
    return CanonicalAttendanceRule::assess($teacher,$student,$start,$end);
}

$t=static fn(string $join,?string $leave)=>dzn_lk_threshold_interval($join,$leave);

// Exactly 1200 seconds passes; 1199 does not.
$exact=dzn_lk_threshold_assess(array($t('2026-10-31 10:00:00','2026-10-31 10:20:00')),array($t('2026-10-31 10:00:00','2026-10-31 10:20:00')));
dzn_lk_threshold_assert($exact['seconds']===1200,'exactly 1200 seconds must measure 1200');
dzn_lk_threshold_assert($exact['eligible']===true,'exactly 1200 seconds must pass');
$below=dzn_lk_threshold_assess(array($t('2026-10-31 10:00:00','2026-10-31 10:19:59')),array($t('2026-10-31 10:00:00','2026-10-31 10:19:59')));
dzn_lk_threshold_assert($below['seconds']===1199,'1199 seconds must measure 1199');
dzn_lk_threshold_assert($below['eligible']===false,'1199 seconds must fail');

// Time before the scheduled start contributes zero.
$preStart=dzn_lk_threshold_assess(array($t('2026-10-31 09:50:00','2026-10-31 10:10:00')),array($t('2026-10-31 10:00:00','2026-10-31 10:20:00')));
dzn_lk_threshold_assert($preStart['seconds']===600,'pre-start presence must be clipped to 600 seconds');
$fullyBefore=dzn_lk_threshold_assess(array($t('2026-10-31 09:00:00','2026-10-31 10:00:00')),array($t('2026-10-31 10:00:00','2026-10-31 10:20:00')));
dzn_lk_threshold_assert($fullyBefore['seconds']===0,'an interval ending at the start must contribute zero');

// The window ends exactly at scheduled end + 900 seconds and is exclusive.
$grace=dzn_lk_threshold_assess(array($t('2026-10-31 10:19:59','2026-10-31 10:40:00')),array($t('2026-10-31 10:19:59','2026-10-31 10:40:00')));
dzn_lk_threshold_assert($grace['seconds']===1,'only the single second before the grace boundary may count');
$afterGrace=dzn_lk_threshold_assess(array($t('2026-10-31 10:20:00','2026-10-31 10:40:00')),array($t('2026-10-31 10:20:00','2026-10-31 10:40:00')));
dzn_lk_threshold_assert($afterGrace['seconds']===0,'the grace boundary is exclusive');

// Separate participant totals cannot substitute for simultaneous overlap.
$totals=dzn_lk_threshold_assess(array($t('2026-10-31 10:00:00','2026-10-31 10:10:00')),array($t('2026-10-31 10:00:00','2026-10-31 10:20:00')));
dzn_lk_threshold_assert($totals['seconds']===600,'individual totals must never substitute for overlap');

// A disconnected gap contributes zero.
$gap=dzn_lk_threshold_assess(
    array($t('2026-10-31 10:00:00','2026-10-31 10:05:00'),$t('2026-10-31 10:10:00','2026-10-31 10:15:00')),
    array($t('2026-10-31 10:00:00','2026-10-31 10:15:00'))
);
dzn_lk_threshold_assert($gap['seconds']===600,'a disconnected gap must not count');

// Reconnects and duplicate devices are unioned, never double-counted.
$reconnect=dzn_lk_threshold_assess(
    array($t('2026-10-31 10:00:00','2026-10-31 10:10:00'),$t('2026-10-31 10:05:00','2026-10-31 10:20:00')),
    array($t('2026-10-31 10:00:00','2026-10-31 10:20:00'))
);
dzn_lk_threshold_assert($reconnect['seconds']===1200,'overlapping reconnect intervals must be unioned to 1200');
$duplicate=dzn_lk_threshold_assess(
    array($t('2026-10-31 10:00:00','2026-10-31 10:20:00'),$t('2026-10-31 10:00:00','2026-10-31 10:20:00')),
    array($t('2026-10-31 10:00:00','2026-10-31 10:20:00'))
);
dzn_lk_threshold_assert($duplicate['seconds']===1200,'duplicate overlapping intervals must not double-count');

// Missing leave never qualifies, and the reason is reported for review.
$open=dzn_lk_threshold_assess(array($t('2026-10-31 10:00:00',null)),array($t('2026-10-31 10:00:00','2026-10-31 10:20:00')));
dzn_lk_threshold_assert($open['seconds']===0,'an open interval must never qualify');
dzn_lk_threshold_assert(in_array(CanonicalAttendanceRule::EXCLUDED_OPEN_INTERVAL,array_column($open['excluded'],'reason'),true),'an open interval must be reported as excluded');

// An impossible interval is refused rather than repaired.
$impossible=dzn_lk_threshold_assess(array($t('2026-10-31 10:10:00','2026-10-31 10:05:00')),array($t('2026-10-31 10:00:00','2026-10-31 10:20:00')));
dzn_lk_threshold_assert($impossible['seconds']===0,'an impossible interval must not qualify');
dzn_lk_threshold_assert(in_array(CanonicalAttendanceRule::EXCLUDED_IMPOSSIBLE_INTERVAL,array_column($impossible['excluded'],'reason'),true),'an impossible interval must be reported');

// The rule identity is locked.
dzn_lk_threshold_assert(CanonicalAttendanceRule::THRESHOLD_SECONDS===1200,'the locked threshold must remain 1200');
dzn_lk_threshold_assert(CanonicalAttendanceRule::PRE_GRACE_SECONDS===0,'pre-start grace must remain zero');
dzn_lk_threshold_assert(CanonicalAttendanceRule::POST_GRACE_SECONDS===900,'post-end grace must remain 900');

echo "LiveKit attendance threshold unit passed\n";
