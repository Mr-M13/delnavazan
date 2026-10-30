<?php
require_once dirname(__DIR__).'/src/Core/Application/NotificationSmsFallbackPolicy.php';
require_once dirname(__DIR__).'/src/Core/Application/NotificationTransportPort.php';
require_once dirname(__DIR__).'/src/Core/Application/NotificationTransportRouter.php';
use Delnavazan\Platform\Core\Application\NotificationSmsFallbackPolicy as Policy;
use Delnavazan\Platform\Core\Application\NotificationTransportPort;
use Delnavazan\Platform\Core\Application\NotificationTransportRouter;

$send=array('failure_verified'=>true,'sms_consent'=>true,'sms_suppressed'=>false,'sms_policy_allowed'=>true,'phone_valid'=>true,'fallback_used'=>false);
$expect=static function(array $state,string $action,string $reason,string $label):void{
    $result=Policy::decide($state);
    if($result['action']!==$action||$result['reason']!==$reason)throw new RuntimeException($label.' => '.$result['action'].'/'.$result['reason']);
};
// 1. delivered -> never SMS.
$expect(array('whatsapp_status'=>Policy::STATUS_DELIVERED)+$send,Policy::BLOCK,'whatsapp_delivered','delivered');
// 2. accepted / 3. pending / 4. queued / 5. retrying -> no SMS, no retry.
foreach(array(Policy::STATUS_ACCEPTED,Policy::STATUS_PENDING,Policy::STATUS_QUEUED,Policy::STATUS_RETRYING) as $status){
    $expect(array('whatsapp_status'=>$status)+$send,Policy::BLOCK,'whatsapp_not_terminally_failed',$status);
}
// 6. temporary failure -> retry WhatsApp, never SMS.
$expect(array('whatsapp_status'=>Policy::STATUS_TEMPORARY_FAILED)+$send,Policy::RETRY_WHATSAPP,'whatsapp_temporary_failure','temporary');
// 7. terminal failure with every eligibility requirement -> exactly one SMS.
$expect(array('whatsapp_status'=>Policy::STATUS_TERMINAL_FAILED)+$send,Policy::SEND_SMS,'authorised','terminal');
// 8. terminal failure without consent.
$expect(array('whatsapp_status'=>Policy::STATUS_TERMINAL_FAILED,'failure_verified'=>true,'sms_consent'=>false,'sms_suppressed'=>false,'sms_policy_allowed'=>true,'phone_valid'=>true,'fallback_used'=>false),Policy::BLOCK,'sms_consent_absent','no consent');
// 9. terminal failure with suppression.
$expect(array('whatsapp_status'=>Policy::STATUS_TERMINAL_FAILED,'failure_verified'=>true,'sms_consent'=>true,'sms_suppressed'=>true,'sms_policy_allowed'=>true,'phone_valid'=>true,'fallback_used'=>false),Policy::BLOCK,'sms_suppressed','suppressed');
// 10. duplicate/reused fallback authorisation.
$expect(array('whatsapp_status'=>Policy::STATUS_TERMINAL_FAILED,'failure_verified'=>true,'sms_consent'=>true,'sms_suppressed'=>false,'sms_policy_allowed'=>true,'phone_valid'=>true,'fallback_used'=>true),Policy::BLOCK,'fallback_already_used','duplicate');
// 11. provider terminal rejection after an actual attempt -> SMS.
$expect(array('whatsapp_status'=>Policy::STATUS_PROVIDER_REJECTED)+$send,Policy::SEND_SMS,'authorised','provider rejected');
// 12. unknown status -> no SMS.
$expect(array('whatsapp_status'=>Policy::STATUS_UNKNOWN)+$send,Policy::BLOCK,'whatsapp_not_terminally_failed','unknown');
// 13. not configured -> no SMS.
$expect(array('whatsapp_status'=>Policy::STATUS_NOT_CONFIGURED)+$send,Policy::BLOCK,'whatsapp_not_terminally_failed','not configured');
// 14. recipient ineligible -> no SMS.
$expect(array('whatsapp_status'=>Policy::STATUS_INELIGIBLE)+$send,Policy::BLOCK,'whatsapp_not_terminally_failed','ineligible');
// 15. an unverified terminal claim can never authorise SMS.
$expect(array('whatsapp_status'=>Policy::STATUS_TERMINAL_FAILED,'failure_verified'=>false,'sms_consent'=>true,'sms_suppressed'=>false,'sms_policy_allowed'=>true,'phone_valid'=>true,'fallback_used'=>false),Policy::BLOCK,'failure_unverified','unverified');

// Direct automated mobile routing is WhatsApp-only. SMS is reachable only through the explicit
// verified-failure authorisation marker.
final class DznPolicyTransport implements NotificationTransportPort{public function handoff(array $c):array{return array('acknowledged'=>true,'permanent_failure'=>null);}}
$port=new DznPolicyTransport();
$router=new NotificationTransportRouter(array(
    array('channel'=>'whatsapp','configured'=>true,'port'=>$port),
    array('channel'=>'sms','configured'=>true,'port'=>$port),
));
$channels=static fn(array $rows):array=>array_map(static fn(array $row):string=>$row['channel'],$rows);
if($channels($router->route('mobile',array('whatsapp_eligible'=>true,'sms_eligible'=>true)))!==array('whatsapp'))throw new RuntimeException('direct route must be WhatsApp only');
if($router->route('mobile',array('whatsapp_eligible'=>false,'sms_eligible'=>true))!==array())throw new RuntimeException('unavailable WhatsApp must not select SMS');
if($channels($router->route('mobile',array('sms_fallback_authorised'=>true,'whatsapp_terminal_failure_digest'=>str_repeat('c',64),'whatsapp_eligible'=>false,'sms_eligible'=>true)))!==array('sms'))throw new RuntimeException('verified fallback must select SMS');
echo "Notification SMS fallback policy unit passed\n";
