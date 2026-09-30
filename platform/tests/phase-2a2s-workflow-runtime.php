<?php
/**
 * Disposable production-path Phase 2A.2-S workflow/versioning proof.
 *
 * Covers §6.1/§6.2: draft registration, the draft-only rule freeze with its post-activation append refusal,
 * the complete required eligibility set at activation, the closed schedule composition, the mandatory
 * expiry, the narrow-only retry policy, the intent routing arbitration (one active version per consumed
 * intent, fail-closed `intent_routing_conflict`), and the fail-closed read of a mutated version.
 */
if(getenv('DZN_PHASE_2A2S_RUNTIME_TEST')!=='workflow'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-S workflow runtime refused.\n");exit(1);}
require __DIR__.'/phase-2a2s-fixture.php';
use Delnavazan\Platform\Core\Application\NotificationRule;
use Delnavazan\Platform\Core\Application\NotificationWorkflowReadService;
use Delnavazan\Platform\Core\Application\NotificationWorkflowService;
global $wpdb;$p=$wpdb->prefix.'dzn_';
dzn_s_fix_reset();
wp_set_current_user(1);
$workflows=new NotificationWorkflowService();

// 1. A version registers as a draft and its rule set is draft-only until activation freezes it.
$ready=dzn_s_fix_version('AUTOMATIC_RENEWAL_CHARGED','wf-basic');
$versionId=(int)$ready['workflow_version_id'];
$draft=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notification_workflow_versions WHERE id=%d",$versionId));
dzn_s_fix_assert((string)$draft->state==='active'&&(int)$draft->active_slot===1&&(int)$draft->intent_active_slot===1,'an activated version must claim both routing slots');
dzn_s_fix_assert($draft->rule_set_digest!==null&&$draft->rule_frozen_at!==null,'activation must freeze the rule set');
dzn_s_fix_rejected(fn()=>$workflows->setEligibilityRule($versionId,array('rule_code'=>'not_suppressed'),dzn_s_fix_key('frozen')),'workflow_rules_frozen','a post-activation rule append');
dzn_s_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}notification_workflow_rules WHERE workflow_version_id=%d",$versionId))===8,'a refused append must not change the frozen rule count');

// 2. Only one active version may route one consumed intent, whatever workflow attempts it.
$other=$workflows->registerWorkflow(array('workflow_key'=>'renewal.automatic_charged.second','purpose'=>'notification'),dzn_s_fix_key('wf-second'));
$otherTemplate=dzn_s_fix_version('AUTOMATIC_RENEWAL_CHARGED','wf-second-template-source');
$secondVersion=$workflows->registerVersion((int)$other['workflow_id'],array('intent_key'=>'AUTOMATIC_RENEWAL_CHARGED','audience'=>'student','recipient_kind'=>'student','template_id'=>(int)$otherTemplate['template_id'],'locale'=>'fa'),dzn_s_fix_key('wfv-second'));
$secondId=(int)$secondVersion['workflow_version_id'];
$binding=NotificationRule::requiredBinding('AUTOMATIC_RENEWAL_CHARGED');
foreach(array(
    array('rule_code'=>'subject_exists','parameter_a'=>$binding['aggregate']),
    array('rule_code'=>'subject_state_is','parameter_a'=>$binding['aggregate'],'parameter_b'=>'confirmed'),
    array('rule_code'=>'recipient_resolvable'),array('rule_code'=>'recipient_opted_in'),
    array('rule_code'=>'guardian_authority_present'),array('rule_code'=>'not_suppressed'),
) as $rule)$workflows->setEligibilityRule($secondId,$rule,dzn_s_fix_key('second-'.($rule['rule_code'])));
foreach(array(array('rule_code'=>'immediate'),array('rule_code'=>'expiry','parameter_a'=>'60')) as $rule)$workflows->setScheduleRule($secondId,$rule,dzn_s_fix_key('second-sched-'.($rule['rule_code']??'x')));
dzn_s_fix_rejected(fn()=>$workflows->activateVersion($secondId,dzn_s_fix_key('second-act')),'intent_routing_conflict','a competing activation of one consumed intent');
$stillFirst=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notification_workflow_versions WHERE id=%d",$secondId));
dzn_s_fix_assert((string)$stillFirst->state==='draft'&&$stillFirst->active_slot===null&&$stillFirst->intent_active_slot===null,'the losing activation must roll back whole');
dzn_s_fix_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}notification_workflow_versions WHERE intent_key='AUTOMATIC_RENEWAL_CHARGED' AND state='active'")===1,'exactly one active version may route an intent');

// 3. The complete required set is validated at activation, one refusal code per missing rule.
dzn_s_fix_rejected(fn()=>dzn_s_fix_version('TERM_LAPSED','wf-missing-baseline',array('drop'=>'recipient_opted_in')),'eligibility_rule_set_incomplete','a version missing the consent rule');
dzn_s_fix_rejected(fn()=>dzn_s_fix_version('TERM_LAPSED','wf-duplicate',array('duplicate'=>'not_suppressed')),'eligibility_rule_set_incomplete','a duplicated mandatory rule');
dzn_s_fix_rejected(fn()=>dzn_s_fix_version('TERM_LAPSED','wf-unauthorised',array('audience'=>'guardian','recipient_kind'=>'guardian')),'audience_not_authorised','the reserved guardian audience');
dzn_s_fix_rejected(fn()=>dzn_s_fix_version('AUTOMATIC_RENEWAL_UPCOMING','wf-no-expiry',array(),true,60,array(array('rule_code'=>'lead_time','parameter_a'=>'60'))),'schedule_expiry_missing','a version without the mandatory expiry rule');
dzn_s_fix_rejected(fn()=>dzn_s_fix_version('AUTOMATIC_RENEWAL_UPCOMING','wf-p-anchor',array(),true,60,array(array('rule_code'=>'immediate'),array('rule_code'=>'expiry','parameter_a'=>'60'))),'schedule_composition_invalid','a tier-F version with a tier-P anchor');
dzn_s_fix_rejected(fn()=>dzn_s_fix_version('TERM_LAPSED','wf-bad-basis',array(),true,60,array(array('rule_code'=>'immediate'),array('rule_code'=>'send_window','ordinal'=>1,'parameter_a'=>'09:00'),array('rule_code'=>'send_window','ordinal'=>2,'parameter_a'=>'17:00'),array('rule_code'=>'send_window','ordinal'=>3,'parameter_a'=>'62'),array('rule_code'=>'send_window','ordinal'=>4,'parameter_a'=>'nope'),array('rule_code'=>'expiry','parameter_a'=>'60'))),'schedule_composition_invalid','a timezone basis outside the three-value vocabulary');
dzn_s_fix_rejected(fn()=>dzn_s_fix_version('TERM_LAPSED','wf-basis-conflict',array(),true,60,array(array('rule_code'=>'immediate'),array('rule_code'=>'fixed_local_time','ordinal'=>1,'parameter_a'=>'09:00'),array('rule_code'=>'fixed_local_time','ordinal'=>2,'parameter_a'=>'recipient_local'),array('rule_code'=>'send_window','ordinal'=>1,'parameter_a'=>'09:00'),array('rule_code'=>'send_window','ordinal'=>2,'parameter_a'=>'17:00'),array('rule_code'=>'send_window','ordinal'=>3,'parameter_a'=>'62'),array('rule_code'=>'send_window','ordinal'=>4,'parameter_a'=>'academy_local'),array('rule_code'=>'expiry','parameter_a'=>'60'))),'schedule_timezone_basis_conflict','two timezone-sensitive rules naming different bases');

// 4. A registered retry rule must lie inside the narrow-only partial order.
dzn_s_fix_rejected(fn()=>dzn_s_fix_version('TERM_LAPSED','wf-retry-wide',array('retry'=>array(array('rule_code'=>'retry','ordinal'=>1,'parameter_a'=>'4'),array('rule_code'=>'retry','ordinal'=>2,'parameter_a'=>'120'),array('rule_code'=>'retry','ordinal'=>3,'parameter_a'=>'30000'),array('rule_code'=>'retry','ordinal'=>4,'parameter_a'=>'3600'),array('rule_code'=>'retry','ordinal'=>5,'parameter_a'=>'1000')))),'retry_policy_invalid','a retry policy wider than the class baseline on one axis');
dzn_s_fix_rejected(fn()=>dzn_s_fix_version('TERM_LAPSED','wf-retry-gap',array('retry'=>array(array('rule_code'=>'retry','ordinal'=>1,'parameter_a'=>'2'),array('rule_code'=>'retry','ordinal'=>2,'parameter_a'=>'10'),array('rule_code'=>'retry','ordinal'=>4,'parameter_a'=>'3600'),array('rule_code'=>'retry','ordinal'=>5,'parameter_a'=>'100')))),'retry_policy_invalid','a non-contiguous retry parameter encoding');
$narrow=dzn_s_fix_version('TERM_LAPSED','wf-retry-narrow',array('retry'=>array(array('rule_code'=>'retry','ordinal'=>1,'parameter_a'=>'2'),array('rule_code'=>'retry','ordinal'=>2,'parameter_a'=>'10'),array('rule_code'=>'retry','ordinal'=>3,'parameter_a'=>'15000'),array('rule_code'=>'retry','ordinal'=>4,'parameter_a'=>'60'),array('rule_code'=>'retry','ordinal'=>5,'parameter_a'=>'250'))));
dzn_s_fix_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}notification_workflow_rules WHERE workflow_version_id=%d AND rule_kind='retry'",(int)$narrow['workflow_version_id']))===5,'a narrow-only retry policy must persist its five canonical rows');

// 5. Every activated version reads back through its own integrity proof.
$read=new NotificationWorkflowReadService();
$projection=$read->version($versionId);
dzn_s_fix_assert($projection['state']==='active'&&$projection['tier']==='P','the read seam must return the validated version');
dzn_s_fix_assert(count($projection['rules'])===8,'the read seam must expose the frozen rule set');

// 6. Supersession clears both slots and record the successor lineage.
$successor=$workflows->registerVersion((int)$ready['workflow_id'],array('intent_key'=>'AUTOMATIC_RENEWAL_CHARGED','audience'=>'student','recipient_kind'=>'student','template_id'=>(int)$ready['template_id'],'locale'=>'fa'),dzn_s_fix_key('wfv-successor'));
$successorId=(int)$successor['workflow_version_id'];
foreach(array(
    array('rule_code'=>'subject_exists','parameter_a'=>'collection_intent'),
    array('rule_code'=>'subject_state_is','parameter_a'=>'collection_intent','parameter_b'=>'confirmed'),
    array('rule_code'=>'recipient_resolvable'),array('rule_code'=>'recipient_opted_in'),
    array('rule_code'=>'guardian_authority_present'),array('rule_code'=>'not_suppressed'),
) as $rule)$workflows->setEligibilityRule($successorId,$rule,dzn_s_fix_key('succ-'.($rule['rule_code'])));
foreach(array(array('rule_code'=>'immediate'),array('rule_code'=>'expiry','parameter_a'=>'60')) as $rule)$workflows->setScheduleRule($successorId,$rule,dzn_s_fix_key('succ-sched-'.($rule['rule_code']??'x')));
$workflows->activateVersion($successorId,dzn_s_fix_key('succ-act'));
$predecessor=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notification_workflow_versions WHERE id=%d",$versionId));
$newActive=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notification_workflow_versions WHERE id=%d",$successorId));
dzn_s_fix_assert((string)$predecessor->state==='superseded'&&$predecessor->active_slot===null&&$predecessor->intent_active_slot===null,'the predecessor must be superseded with both slots cleared');
dzn_s_fix_assert((string)$newActive->state==='active'&&(int)$newActive->supersedes_version_id===$versionId,'the successor must record its lineage');

// 7. A post-activation append on the successor leaves it failing its own integrity proof.
$wpdb->query($wpdb->prepare("INSERT INTO {$p}notification_workflow_rules (uid,workflow_version_id,rule_kind,rule_code,ordinal,parameter_a,parameter_b,parameter_c,parameter_d,recorded_at,recorded_by,created_at,created_by) VALUES (%s,%d,'eligibility','not_suppressed',1,NULL,NULL,NULL,NULL,%s,1,%s,1)",Delnavazan\Platform\Core\Support\Identifier::uid(),$successorId,gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')));
dzn_s_fix_rejected(fn()=>$read->version($successorId),'workflow_rule_set_mutated','a raw post-activation rule append');
echo "Phase 2A.2-S workflow runtime passed\n";
