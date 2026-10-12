<?php
namespace Delnavazan\Platform\Core\Application;

$GLOBALS['dzn_invitation_cron_test']=array();

function add_filter(string $hook,callable $callback):void{
    $state=&$GLOBALS['dzn_invitation_cron_test'];
    $state['calls'][]='filter:'.$hook;
    $state['filters'][$hook][]=$callback;
}

function add_action(string $hook,callable $callback):void{
    $GLOBALS['dzn_invitation_cron_test']['calls'][]='action:'.$hook;
}

function wp_next_scheduled(string $hook):int|false{
    $GLOBALS['dzn_invitation_cron_test']['calls'][]='next:'.$hook;
    return ($GLOBALS['dzn_invitation_cron_test']['scheduled']??false)?123:false;
}

function wp_schedule_event(int $timestamp,string $recurrence,string $hook):bool{
    $state=&$GLOBALS['dzn_invitation_cron_test'];
    $state['calls'][]='schedule:'.$recurrence;
    $state['attempts']=($state['attempts']??0)+1;
    $schedules=array();
    foreach($state['filters']['cron_schedules']??array() as $callback)$schedules=$callback($schedules);
    if(!isset($schedules[$recurrence]))return false;
    if(($state['failures_remaining']??0)>0){$state['failures_remaining']--;return false;}
    $state['scheduled']=true;
    $state['created']=($state['created']??0)+1;
    return true;
}

function dzn_invitation_cron_reset(int $failures=0):void{
    $GLOBALS['dzn_invitation_cron_test']=array(
        'calls'=>array(),
        'filters'=>array(),
        'scheduled'=>false,
        'attempts'=>0,
        'created'=>0,
        'failures_remaining'=>$failures,
    );
}

function dzn_invitation_cron_assert(bool $condition,string $message):void{
    if(!$condition)throw new \RuntimeException($message);
}

require_once dirname(__DIR__).'/src/Core/Application/TeacherInvitationDeliveryWorker.php';

// WordPress validates a custom recurrence while wp_schedule_event() runs, so the
// cron_schedules filter must already be present when the first scheduling attempt occurs.
dzn_invitation_cron_reset();
TeacherInvitationDeliveryWorker::register();
$state=$GLOBALS['dzn_invitation_cron_test'];
$filterIndex=array_search('filter:cron_schedules',$state['calls'],true);
$scheduleIndex=array_search('schedule:five_minutes',$state['calls'],true);
dzn_invitation_cron_assert($filterIndex!==false&&$scheduleIndex!==false&&$filterIndex<$scheduleIndex,'The five-minute recurrence must be registered before scheduling');
dzn_invitation_cron_assert($state['created']===1,'The first registration must create one scheduled event');

// A failed scheduling call remains retryable. Once a retry succeeds, later
// registrations converge without creating or attempting a duplicate event.
dzn_invitation_cron_reset(1);
TeacherInvitationDeliveryWorker::register();
dzn_invitation_cron_assert($GLOBALS['dzn_invitation_cron_test']['attempts']===1&&!$GLOBALS['dzn_invitation_cron_test']['scheduled'],'A failed scheduling attempt must remain unscheduled');
TeacherInvitationDeliveryWorker::register();
TeacherInvitationDeliveryWorker::register();
$state=$GLOBALS['dzn_invitation_cron_test'];
dzn_invitation_cron_assert($state['attempts']===2,'Registration must retry once after failure and stop attempting after success');
dzn_invitation_cron_assert($state['created']===1&&$state['scheduled'],'The retry must converge on exactly one scheduled event');

echo "teacher-invitation-worker-scheduling-unit: pass\n";
