<?php
/**
 * Phase 2A.2-P locked attendance rule proof; pure computation, no WordPress or database.
 *
 * v3 (`canonical_attendance_presence_v3`) is the live owner policy: two or more participants other
 * than the organizer/liaison, simultaneously present for 1200 seconds inside
 * [start − 30 min, end + 30 min). v2 remains readable for cases admitted under the identity rule.
 */
require dirname(__DIR__).'/src/Core/Application/CanonicalAttendanceValidator.php';
require dirname(__DIR__).'/src/Core/Application/CanonicalAttendanceRule.php';

use Delnavazan\Platform\Core\Application\CanonicalAttendanceRule as Rule;

function dzn_p_ov_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
/** 2026-09-19 10:00–10:30 UTC occurrence: qualifying window 09:30–11:00 (v3: 30 minutes each side). */
$start='2026-09-19 10:00:00'; $end='2026-09-19 10:30:00';
$i=static fn(string $join,?string $leave):array=>array('join_at_utc'=>$join,'leave_at_utc'=>$leave);

$window=Rule::window($start,$end);
dzn_p_ov_assert($window['window_start_utc']==='2026-09-19 09:30:00','window must start 30 minutes before the scheduled start');
dzn_p_ov_assert($window['window_end_utc']==='2026-09-19 11:00:00','window must end 30 minutes after the scheduled end');
dzn_p_ov_assert(Rule::PRE_GRACE_SECONDS===1800,'pre-class grace must be 30 minutes');
dzn_p_ov_assert(Rule::POST_GRACE_SECONDS===1800,'post-class grace must be 30 minutes');
dzn_p_ov_assert(Rule::THRESHOLD_SECONDS===1200,'threshold must be exactly 1200 seconds');
dzn_p_ov_assert(Rule::MIN_PARTICIPANTS===2,'presence must require at least two participants');
dzn_p_ov_assert(Rule::RULE_VERSION==='canonical_attendance_presence_v3','live rule version must be the presence rule');

// Legacy v2 windows stay frozen for cases admitted under them.
$legacy=Rule::window($start,$end,'canonical_attendance_overlap_v2');
dzn_p_ov_assert($legacy['window_start_utc']==='2026-09-19 09:00:00'&&$legacy['window_end_utc']==='2026-09-19 11:30:00','v2 window must stay one hour either side');

$two=Rule::presence(array(
    'account-a'=>array($i('2026-09-19 10:00:00','2026-09-19 10:20:00')),
    'account-b'=>array($i('2026-09-19 10:00:00','2026-09-19 10:20:00')),
),$start,$end);
dzn_p_ov_assert($two['seconds']===1200,'two people for exactly 20 minutes must yield 1200 (got '.$two['seconds'].')');
dzn_p_ov_assert($two['eligible']===true,'exactly 20 minutes with two people must settle');

$short=Rule::presence(array(
    'account-a'=>array($i('2026-09-19 10:00:00','2026-09-19 10:19:59')),
    'account-b'=>array($i('2026-09-19 10:00:00','2026-09-19 10:19:59')),
),$start,$end);
dzn_p_ov_assert($short['seconds']===1199&&$short['eligible']===false,'19:59 must fail');

$alone=Rule::presence(array('account-a'=>array($i('2026-09-19 10:00:00','2026-09-19 10:30:00'))),$start,$end);
dzn_p_ov_assert($alone['seconds']===0&&$alone['eligible']===false,'one participant is never enough');
dzn_p_ov_assert($alone['participants']===1,'one participant must be counted as one');

// A participant swap keeps the room continuously occupied by two people.
$swap=Rule::presence(array(
    'account-a'=>array($i('2026-09-19 10:00:00','2026-09-19 10:20:00')),
    'account-b'=>array($i('2026-09-19 10:00:00','2026-09-19 10:10:00')),
    'account-c'=>array($i('2026-09-19 10:10:00','2026-09-19 10:20:00')),
),$start,$end);
dzn_p_ov_assert($swap['seconds']===1200,'a handover that keeps two people present must stay continuous (got '.$swap['seconds'].')');

$gap=Rule::presence(array(
    'account-a'=>array($i('2026-09-19 10:00:00','2026-09-19 10:20:00')),
    'account-b'=>array($i('2026-09-19 10:00:00','2026-09-19 10:10:00')),
    'account-c'=>array($i('2026-09-19 10:10:01','2026-09-19 10:20:00')),
),$start,$end);
dzn_p_ov_assert($gap['seconds']===1199,'a one-second gap must break the run (got '.$gap['seconds'].')');

$duplicate=Rule::presence(array(
    'account-a'=>array($i('2026-09-19 10:00:00','2026-09-19 10:10:00'),$i('2026-09-19 10:05:00','2026-09-19 10:20:00')),
    'account-b'=>array($i('2026-09-19 10:00:00','2026-09-19 10:20:00')),
),$start,$end);
dzn_p_ov_assert($duplicate['seconds']===1200,'two devices of one participant must union, not double-count (got '.$duplicate['seconds'].')');

$early=Rule::presence(array(
    'account-a'=>array($i('2026-09-19 09:35:00','2026-09-19 10:00:00')),
    'account-b'=>array($i('2026-09-19 09:35:00','2026-09-19 10:00:00')),
),$start,$end);
dzn_p_ov_assert($early['seconds']===1500,'an early start inside the 30-minute grace must count (got '.$early['seconds'].')');

$tooEarly=Rule::presence(array(
    'account-a'=>array($i('2026-09-19 09:00:00','2026-09-19 09:30:00')),
    'account-b'=>array($i('2026-09-19 09:00:00','2026-09-19 09:30:00')),
),$start,$end);
dzn_p_ov_assert($tooEarly['seconds']===0,'minutes before the 30-minute grace must be excluded');

$nextDay=Rule::presence(array(
    'account-a'=>array($i('2026-09-20 10:00:00','2026-09-20 10:30:00')),
    'account-b'=>array($i('2026-09-20 10:00:00','2026-09-20 10:30:00')),
),$start,$end);
dzn_p_ov_assert($nextDay['seconds']===0,'a meeting on another date through the same link must never count');

$afterGrace=Rule::presence(array(
    'account-a'=>array($i('2026-09-19 11:00:00','2026-09-19 11:30:00')),
    'account-b'=>array($i('2026-09-19 11:00:00','2026-09-19 11:30:00')),
),$start,$end);
dzn_p_ov_assert($afterGrace['seconds']===0,'minutes after end+30 must be excluded');

$impossible=Rule::presence(array(
    'account-a'=>array($i('2026-09-19 10:10:00','2026-09-19 10:05:00')),
    'account-b'=>array($i('2026-09-19 10:00:00','2026-09-19 10:30:00')),
),$start,$end);
dzn_p_ov_assert($impossible['seconds']===0,'impossible intervals must not contribute');
dzn_p_ov_assert(in_array(Rule::EXCLUDED_IMPOSSIBLE_INTERVAL,array_column($impossible['excluded'],'reason'),true),'impossible interval must be reported as excluded');

$open=Rule::presence(array(
    'account-a'=>array($i('2026-09-19 10:00:00',null)),
    'account-b'=>array($i('2026-09-19 10:00:00','2026-09-19 10:30:00')),
),$start,$end);
dzn_p_ov_assert($open['seconds']===0,'open intervals must never qualify');
dzn_p_ov_assert(in_array(Rule::EXCLUDED_OPEN_INTERVAL,array_column($open['excluded'],'reason'),true),'open interval must be reported as excluded');

// The retired v2 identity rule still computes exactly as it did for admitted cases.
$legacyAssess=Rule::assess(
    array($i('2026-09-19 10:00:00','2026-09-19 10:10:00'),$i('2026-09-19 10:15:00','2026-09-19 10:35:00')),
    array($i('2026-09-19 10:05:00','2026-09-19 10:30:00')),
    $start,$end
);
dzn_p_ov_assert($legacyAssess['seconds']===1200&&$legacyAssess['eligible']===true,'the legacy v2 assessment must stay unchanged');

echo "window_and_constants=pass\nworked_example_1200=pass\n1199_fails=pass\nsingle_participant_fails=pass\nhandover_continuous=pass\ngap_breaks_run=pass\nduplicate_devices=pass\nearly_start_counts=pass\npre_window_excluded=pass\nnext_day_excluded=pass\nafter_grace_excluded=pass\nimpossible_interval=pass\nopen_interval=pass\nlegacy_v2_unchanged=pass\nPhase 2A.2-P presence rule runtime passed\n";
