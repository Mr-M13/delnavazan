<?php
/** Phase 2A.2-P locked overlap/threshold rule proof; pure computation, no WordPress or database. */
require dirname(__DIR__).'/src/Core/Application/CanonicalAttendanceValidator.php';
require dirname(__DIR__).'/src/Core/Application/CanonicalAttendanceRule.php';

use Delnavazan\Platform\Core\Application\CanonicalAttendanceRule as Rule;

function dzn_p_ov_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
/** 2026-09-19 10:00–10:30 UTC occurrence: qualifying window 09:00–11:30 (v2: one hour each side). */
$start='2026-09-19 10:00:00'; $end='2026-09-19 10:30:00';
$i=static fn(string $join,?string $leave):array=>array('join_at_utc'=>$join,'leave_at_utc'=>$leave);

$window=Rule::window($start,$end);
dzn_p_ov_assert($window['window_start_utc']==='2026-09-19 09:00:00','window must start one hour before the scheduled start');
dzn_p_ov_assert($window['window_end_utc']==='2026-09-19 11:30:00','window must end one hour after the scheduled end');
dzn_p_ov_assert(Rule::PRE_GRACE_SECONDS===3600,'pre-class grace must be one hour');
dzn_p_ov_assert(Rule::POST_GRACE_SECONDS===3600,'post-class grace must be one hour');
dzn_p_ov_assert(Rule::THRESHOLD_SECONDS===1200,'threshold must be exactly 1200 seconds');

$worked=Rule::assess(array($i('2026-09-19 10:00:00','2026-09-19 10:10:00'),$i('2026-09-19 10:15:00','2026-09-19 10:35:00')),array($i('2026-09-19 10:05:00','2026-09-19 10:30:00')),$start,$end);
dzn_p_ov_assert($worked['seconds']===1200,'worked example must yield exactly 20 minutes (got '.$worked['seconds'].')');
dzn_p_ov_assert($worked['eligible']===true,'exactly 20 minutes must pass');

$short=Rule::assess(array($i('2026-09-19 10:00:00','2026-09-19 10:30:00')),array($i('2026-09-19 10:00:00','2026-09-19 10:19:59')),$start,$end);
dzn_p_ov_assert($short['seconds']===1199&&$short['eligible']===false,'19:59 must fail');

$teacherLong=Rule::assess(array($i('2026-09-19 10:00:00','2026-09-19 10:30:00')),array($i('2026-09-19 10:00:00','2026-09-19 10:05:00')),$start,$end);
dzn_p_ov_assert($teacherLong['seconds']===300&&$teacherLong['eligible']===false,'Teacher 30 / Student 5 must fail');

$split=Rule::assess(array($i('2026-09-19 10:00:00','2026-09-19 10:12:00')),array($i('2026-09-19 10:00:00','2026-09-19 10:06:00'),$i('2026-09-19 10:06:00','2026-09-19 10:12:00')),$start,$end);
dzn_p_ov_assert($split['seconds']===720,'adjacent student intervals must union (got '.$split['seconds'].')');

$duplicate=Rule::assess(array($i('2026-09-19 10:00:00','2026-09-19 10:20:00')),array($i('2026-09-19 10:00:00','2026-09-19 10:10:00'),$i('2026-09-19 10:05:00','2026-09-19 10:20:00')),$start,$end);
dzn_p_ov_assert($duplicate['seconds']===1200,'overlapping duplicate devices must not double-count (got '.$duplicate['seconds'].')');

// v2: a class that starts early still counts, but only inside the one-hour early window.
$earlyStart=Rule::assess(array($i('2026-09-19 09:15:00','2026-09-19 10:20:00')),array($i('2026-09-19 09:15:00','2026-09-19 10:20:00')),$start,$end);
dzn_p_ov_assert($earlyStart['seconds']===3900,'an early start inside the grace must count (got '.$earlyStart['seconds'].')');
dzn_p_ov_assert($earlyStart['eligible']===true,'an early start must be able to confirm attendance');
$preStart=Rule::assess(array($i('2026-09-19 08:00:00','2026-09-19 09:00:00')),array($i('2026-09-19 08:00:00','2026-09-19 09:00:00')),$start,$end);
dzn_p_ov_assert($preStart['seconds']===0,'minutes before the early window must be excluded');

$throughGrace=Rule::assess(array($i('2026-09-19 10:30:00','2026-09-19 11:30:00')),array($i('2026-09-19 10:30:00','2026-09-19 11:30:00')),$start,$end);
dzn_p_ov_assert($throughGrace['seconds']===3600,'minutes through end+60 must count (got '.$throughGrace['seconds'].')');

$afterGrace=Rule::assess(array($i('2026-09-19 11:30:00','2026-09-19 12:30:00')),array($i('2026-09-19 11:30:00','2026-09-19 12:30:00')),$start,$end);
dzn_p_ov_assert($afterGrace['seconds']===0,'minutes after end+60 must be excluded');

$impossible=Rule::assess(array($i('2026-09-19 10:10:00','2026-09-19 10:05:00')),array($i('2026-09-19 10:00:00','2026-09-19 10:30:00')),$start,$end);
dzn_p_ov_assert($impossible['seconds']===0,'impossible intervals must not contribute');
$reasons=array_column($impossible['excluded'],'reason');
dzn_p_ov_assert(in_array(Rule::EXCLUDED_IMPOSSIBLE_INTERVAL,$reasons,true),'impossible interval must be reported as excluded');

$open=Rule::assess(array($i('2026-09-19 10:00:00',null)),array($i('2026-09-19 10:00:00','2026-09-19 10:30:00')),$start,$end);
dzn_p_ov_assert($open['seconds']===0,'open intervals must never qualify');
dzn_p_ov_assert(in_array(Rule::EXCLUDED_OPEN_INTERVAL,array_column($open['excluded'],'reason'),true),'open interval must be reported as excluded');

$otherOccurrence=Rule::assess(array($i('2026-09-19 12:00:00','2026-09-19 13:00:00')),array($i('2026-09-19 12:00:00','2026-09-19 13:00:00')),$start,$end);
dzn_p_ov_assert($otherOccurrence['seconds']===0,'a different occurrence must never contribute overlap');

echo "window_and_constants=pass\nworked_example_1200=pass\n1199_fails=pass\nteacher_long_student_short=pass\nsplit_intervals=pass\nduplicate_devices=pass\nearly_start_counts=pass\npre_window_excluded=pass\npost_grace_through_60m=pass\nafter_grace_excluded=pass\nimpossible_interval=pass\nopen_interval=pass\nother_occurrence=pass\nPhase 2A.2-P overlap rule runtime passed\n";
