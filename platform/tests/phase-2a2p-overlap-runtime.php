<?php
/** Phase 2A.2-P locked overlap/threshold rule proof; pure computation, no WordPress or database. */
require dirname(__DIR__).'/src/Core/Application/CanonicalAttendanceValidator.php';
require dirname(__DIR__).'/src/Core/Application/CanonicalAttendanceRule.php';

use Delnavazan\Platform\Core\Application\CanonicalAttendanceRule as Rule;

function dzn_p_ov_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
/** 2026-09-19 10:00–10:30 UTC occurrence: qualifying window 10:00–10:45. */
$start='2026-09-19 10:00:00'; $end='2026-09-19 10:30:00';
$i=static fn(string $join,?string $leave):array=>array('join_at_utc'=>$join,'leave_at_utc'=>$leave);

$window=Rule::window($start,$end);
dzn_p_ov_assert($window['window_start_utc']==='2026-09-19 10:00:00','window must start at the scheduled start');
dzn_p_ov_assert($window['window_end_utc']==='2026-09-19 10:45:00','window must end at scheduled end plus 15 minutes');
dzn_p_ov_assert(Rule::PRE_GRACE_SECONDS===0,'pre-class grace must be zero');
dzn_p_ov_assert(Rule::POST_GRACE_SECONDS===900,'post-class grace must be exactly 15 minutes');
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

$preStart=Rule::assess(array($i('2026-09-19 09:30:00','2026-09-19 10:00:00')),array($i('2026-09-19 09:30:00','2026-09-19 10:00:00')),$start,$end);
dzn_p_ov_assert($preStart['seconds']===0,'pre-start minutes must be excluded');

$throughGrace=Rule::assess(array($i('2026-09-19 10:30:00','2026-09-19 10:45:00')),array($i('2026-09-19 10:30:00','2026-09-19 10:45:00')),$start,$end);
dzn_p_ov_assert($throughGrace['seconds']===900,'minutes through end+15 must count (got '.$throughGrace['seconds'].')');

$afterGrace=Rule::assess(array($i('2026-09-19 10:45:00','2026-09-19 11:30:00')),array($i('2026-09-19 10:45:00','2026-09-19 11:30:00')),$start,$end);
dzn_p_ov_assert($afterGrace['seconds']===0,'minutes after end+15 must be excluded');

$impossible=Rule::assess(array($i('2026-09-19 10:10:00','2026-09-19 10:05:00')),array($i('2026-09-19 10:00:00','2026-09-19 10:30:00')),$start,$end);
dzn_p_ov_assert($impossible['seconds']===0,'impossible intervals must not contribute');
$reasons=array_column($impossible['excluded'],'reason');
dzn_p_ov_assert(in_array(Rule::EXCLUDED_IMPOSSIBLE_INTERVAL,$reasons,true),'impossible interval must be reported as excluded');

$open=Rule::assess(array($i('2026-09-19 10:00:00',null)),array($i('2026-09-19 10:00:00','2026-09-19 10:30:00')),$start,$end);
dzn_p_ov_assert($open['seconds']===0,'open intervals must never qualify');
dzn_p_ov_assert(in_array(Rule::EXCLUDED_OPEN_INTERVAL,array_column($open['excluded'],'reason'),true),'open interval must be reported as excluded');

$otherOccurrence=Rule::assess(array($i('2026-09-19 12:00:00','2026-09-19 13:00:00')),array($i('2026-09-19 12:00:00','2026-09-19 13:00:00')),$start,$end);
dzn_p_ov_assert($otherOccurrence['seconds']===0,'a different occurrence must never contribute overlap');

echo "window_and_constants=pass\nworked_example_1200=pass\n1199_fails=pass\nteacher_long_student_short=pass\nsplit_intervals=pass\nduplicate_devices=pass\npre_start_excluded=pass\npost_grace_through_15m=pass\nafter_grace_excluded=pass\nimpossible_interval=pass\nopen_interval=pass\nother_occurrence=pass\nPhase 2A.2-P overlap rule runtime passed\n";
