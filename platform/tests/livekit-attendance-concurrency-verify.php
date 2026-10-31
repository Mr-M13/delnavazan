<?php
/** Verify that two concurrent reconciliation workers produced exactly one canonical consequence. */
if(getenv('DZN_LIVEKIT_ATTENDANCE_CONCURRENCY')!=='verify'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"LiveKit concurrency verifier refused.\n");exit(1);}
global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_lka_concurrency_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$fixture=get_option('dzn_livekit_attendance_concurrency_fixture');
dzn_lka_concurrency_assert(is_array($fixture)&&(int)($fixture['lesson_id']??0)>0,'LiveKit concurrency fixture missing');
$lessonId=(int)$fixture['lesson_id'];$versionId=(int)$fixture['version_id'];
$case=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}canonical_attendance_cases WHERE lesson_id=%d AND schedule_version_id=%d",$lessonId,$versionId));
dzn_lka_concurrency_assert($case!==null&&(string)$case->state==='settled','Concurrent reconciliation did not converge the case');
$outcomes=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}canonical_lesson_delivery_outcomes WHERE lesson_id=%d",$lessonId))?:array();
dzn_lka_concurrency_assert(count($outcomes)===1&&(string)$outcomes[0]->outcome_code==='delivered','Concurrent reconciliation must create exactly one delivered outcome');
$completed=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_lesson_lifecycle_events WHERE lesson_id=%d AND to_state='completed'",$lessonId));
dzn_lka_concurrency_assert($completed===1,'Concurrent reconciliation must create exactly one completion event');
$settlements=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}canonical_attendance_decisions WHERE case_id=%d AND decision_kind='settlement_completed'",(int)$case->id));
dzn_lka_concurrency_assert($settlements===1,'Concurrent reconciliation must append exactly one settlement decision');
$snapshots=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}finance_lesson_snapshots WHERE lesson_id=%d",$lessonId));
$before=(int)($fixture['finance_snapshots']??0);
dzn_lka_concurrency_assert($snapshots<=$before+1,'Concurrent reconciliation must not duplicate finance capture');
dzn_lka_concurrency_assert($snapshots<=1,'A Lesson may not have more than one finance snapshot');
echo "LiveKit concurrent reconciliation passed\n";
