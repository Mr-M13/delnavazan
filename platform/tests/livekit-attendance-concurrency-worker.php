<?php
/**
 * One disposable reconciliation worker for the concurrent-settlement proof.
 *
 * Two of these run at the same time against the same durable pending evidence. The Phase-P command
 * keys and the adapter's room/connection locks must converge on one outcome, one completion event
 * and at most one finance capture.
 */
if(getenv('DZN_LIVEKIT_ATTENDANCE_CONCURRENCY')!=='worker'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"LiveKit concurrency worker refused.\n");exit(1);}
use Delnavazan\Platform\Integrations\LiveKit\LiveKitAttendanceIntegration;
$fixture=get_option('dzn_livekit_attendance_concurrency_fixture');
if(!is_array($fixture)||(int)($fixture['lesson_id']??0)<1){fwrite(STDERR,"LiveKit concurrency fixture missing.\n");exit(1);}
try{
    $result=(new LiveKitAttendanceIntegration())->reconcile();
    echo wp_json_encode($result,JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(\Throwable $error){
    fwrite(STDERR,get_class($error).': '.$error->getMessage().PHP_EOL);
    exit(1);
}
