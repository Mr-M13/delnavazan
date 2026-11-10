<?php
/**
 * Disposable WordPress/MariaDB runtime smoke for God Mode.
 *
 * Run with WP-CLI in a local/development disposable environment only:
 *   wp eval-file platform/tests/god-mode-runtime.php
 *
 * It creates and removes one isolated test session and never calls a provider or a payment API.
 */
if(!defined('WP_CLI')||!WP_CLI){fwrite(STDERR,"This test requires WP-CLI.\n");exit(1);}
if(!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Refused outside local/development.\n");exit(1);}
use Delnavazan\Platform\Core\Application\GodMode\{GodModeAuthority,GodModeBookingService,GodModeInventoryService,GodModeRepository,GodModeRule,GodModeSessionService,GodModeSettings};

global $wpdb;$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$assert(GodModeAuthority::designatedUserId()>0,'The designated account was not resolved.');
$assert(GodModeAuthority::isAuthorised(GodModeAuthority::designatedUserId()),'The designated account does not hold God Mode authority.');
$p=$wpdb->prefix.'dzn_';
foreach(array('god_mode_sessions','god_mode_session_events','god_mode_audit_events','god_mode_cleanup_receipts','god_mode_participant_mappings') as $table)$assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$p.$table))===$p.$table,'Missing table '.$table);
foreach(array('lessons','enrolments','booking_requests') as $table)$assert((bool)$wpdb->get_row("SHOW COLUMNS FROM {$p}{$table} LIKE 'activity_type'"),'Missing activity_type on '.$table);
$options=(new GodModeBookingService())->bookingOptions();
$assert(!empty($options['teachers'])&&!empty($options['instruments']),'A teacher and instrument are required for the runtime smoke.');
$teacherId=(int)$options['teachers'][0]->id;$instrumentId=(int)$options['instruments'][0]->id;
$start=(new DateTimeImmutable('+2 days 10:00',new DateTimeZone('UTC')));
$before=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}finance_lesson_snapshots");
$repo=new GodModeRepository();
$sessionId=$repo->createSession(array(
    'scenario_code'=>'runtime_financial_isolation','mode'=>GodModeRule::MODE_DIRECT,'status'=>GodModeRule::STATUS_SCHEDULED,
    'result'=>GodModeRule::RESULT_RUNNING,'teacher_id'=>$teacherId,'student_id'=>$repo->testerStudentId(),
    'instrument_id'=>$instrumentId,'title'=>'Runtime God Mode isolation smoke',
    'schedule_timezone'=>'UTC','local_wall_date'=>$start->format('Y-m-d'),'local_wall_time'=>$start->format('H:i:s'),
    'starts_at_utc'=>$start->format('Y-m-d H:i:s'),'ends_at_utc'=>$start->modify('+30 minutes')->format('Y-m-d H:i:s'),
    'duration_minutes'=>30,'meet_state'=>'not_configured','recording_state'=>'not_requested','created_by'=>(int)get_current_user_id(),
));
$assert($sessionId>0,'Test session was not created.');
$session=$repo->session($sessionId);
$assert((string)$session->activity_type==='test','Explicit test classification was lost.');
$digest=hash('sha256','runtime-participant-evidence');
(new GodModeSessionService($repo))->mapParticipant($sessionId,$digest,'signedin','teacher');
$assert(count($repo->participantMappings($sessionId))===1,'Participant evidence mapping was not persisted.');
(new GodModeSessionService($repo))->confirmAttendance($sessionId,true,'runtime smoke');
$after=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}finance_lesson_snapshots");
$assert($before===$after,'Test completion created a finance snapshot.');
$assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}lessons WHERE god_mode_session_id=%d",$sessionId))===0,'A canonical Lesson was created for test activity.');
(new GodModeSessionService($repo))->archive($sessionId);
$result=(new GodModeSessionService($repo))->deleteEligible($sessionId,false);
$assert(!empty($result['deleted']),'Eligible test data was not removed.');
$assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}god_mode_participant_mappings WHERE session_id=%d",$sessionId))===0,'Cleanup left orphaned participant mappings.');
$assert($before===(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}finance_lesson_snapshots"),'Cleanup changed finance evidence.');
$inventory=(new GodModeInventoryService($repo))->inventoryForUser(GodModeAuthority::designatedUserId());
$assert(isset($inventory['destructive_action_executed'])&&$inventory['destructive_action_executed']===false,'Inventory must be read-only.');
echo "God Mode runtime smoke passed\n";
