<?php
require_once dirname(__DIR__).'/src/Integrations/Notifications/NotificationCatalogue.php';

use Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue;

$expect=static function(string $event,string $task):void{
    if(NotificationCatalogue::taskKey($event)!==$task)throw new RuntimeException($event.' was not assigned to '.$task);
};
foreach(array('enrolment_booking','classes_scheduling','finance_payments','renewals','accounts_onboarding','support','system') as $task){
    if(NotificationCatalogue::task($task)===null)throw new RuntimeException('Missing task metadata for '.$task);
}
$expect('booking.confirmed.student','enrolment_booking');
$expect('post_intro.activated.teacher','enrolment_booking');
$expect('lesson.reminder.student','classes_scheduling');
$expect('reschedule.change.accepted.teacher','classes_scheduling');
$expect('payment.recovered.student','finance_payments');
$expect('renewal.automatic_upcoming.student','renewals');
$expect('account.claim_issued.student','accounts_onboarding');
$expect('teacher.invitation_issued.teacher','accounts_onboarding');
$expect('consent.student_required.student','accounts_onboarding');
$expect('system.inbox_check.admin','system');
$expect('support.manual_follow_up.admin','support');
if(NotificationCatalogue::categoryPolicy('payment.recovered.student')!=='financial')throw new RuntimeException('Task projection changed financial policy');
if(NotificationCatalogue::isMandatory('account.claim_issued.student')!==true)throw new RuntimeException('Task projection changed account mandatory status');
echo "Notification task taxonomy unit passed\n";
