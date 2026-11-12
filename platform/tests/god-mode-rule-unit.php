<?php
require_once dirname(__DIR__).'/src/Core/Application/GodMode/GodModeRule.php';
use Delnavazan\Platform\Core\Application\GodMode\GodModeRule;

$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$base=array('designated_user_id'=>42,'user_id'=>42,'email_digest'=>'abc','stored_email_digest'=>'abc','manage_options'=>true,'capability'=>true,'auth_state'=>'active');
$assert(GodModeRule::authorityAllows($base),'The exact designated, verified and capable account must be authorised.');
foreach(array(
    'different user'=>array_merge($base,array('user_id'=>43)),
    'email digest mismatch'=>array_merge($base,array('email_digest'=>'other')),
    'missing stored digest'=>array_merge($base,array('stored_email_digest'=>'')),
    'not administrator'=>array_merge($base,array('manage_options'=>false)),
    'missing capability'=>array_merge($base,array('capability'=>false)),
    'revoked authority'=>array_merge($base,array('auth_state'=>'revoked')),
    'zero designated user'=>array_merge($base,array('designated_user_id'=>0)),
) as $label=>$context)$assert(!GodModeRule::authorityAllows($context),'Authority must fail closed for '.$label.'.');

$clear=GodModeRule::conflictDecision(array(),array(),false);
$assert($clear['allowed']&&!$clear['requires_acknowledgement'],'A clear slot must not require acknowledgement.');
$real=GodModeRule::conflictDecision(array(array('lesson_id'=>1)),array(),false);
$assert(!$real['allowed']&&$real['real_conflicts']===1,'A genuine conflict must require explicit acknowledgement.');
$ack=GodModeRule::conflictDecision(array(array('lesson_id'=>1)),array(),true);
$assert($ack['allowed'],'An explicitly acknowledged genuine conflict may proceed.');
$test=GodModeRule::conflictDecision(array(),array(array('id'=>7)),false);
$assert(!$test['allowed']&&$test['test_conflicts']===1,'Overlapping test sessions must also require acknowledgement.');

$assert(GodModeRule::isTestActivity(array('activity_type'=>'test')),'Explicit test classification must be recognised.');
$assert(!GodModeRule::isTestActivity(array('activity_type'=>'production')),'Production activity must not be classified as test.');
try{GodModeRule::assertBillable(array('activity_type'=>'test'));throw new RuntimeException('Test activity was billable.');}catch(InvalidArgumentException $error){$assert($error->getMessage()==='finance_test_activity_isolated','The finance refusal must be explicit.');}
$assert(GodModeRule::isBillable(array('activity_type'=>'production')),'Production activity must remain billable.');

$session=array('activity_type'=>'test','status'=>GodModeRule::STATUS_ARCHIVED);
$assert(GodModeRule::cleanupRefusal($session,array('pending_provider_operations'=>0,'recording_retained'=>false))===null,'An eligible archived test session must be removable.');
$assert(GodModeRule::cleanupRefusal(array('activity_type'=>'production','status'=>'archived'),array('pending_provider_operations'=>0,'recording_retained'=>false))==='not_a_test_session','Genuine production data must never be removable through God Mode cleanup.');
$assert(GodModeRule::cleanupRefusal(array('activity_type'=>'test','status'=>'completed'),array('pending_provider_operations'=>0,'recording_retained'=>false))==='session_not_archived','Active or completed tests must be archived first.');
$assert(GodModeRule::cleanupRefusal($session,array('pending_provider_operations'=>1,'recording_retained'=>false))==='pending_provider_operations','Pending provider work must block cleanup.');
$assert(GodModeRule::cleanupRefusal($session,array('pending_provider_operations'=>0,'recording_retained'=>true))==='recording_evidence_must_be_retained','Retained recording evidence must block cleanup.');

echo "God Mode rule unit passed\n";
