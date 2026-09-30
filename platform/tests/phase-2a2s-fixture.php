<?php
/**
 * Shared disposable Phase 2A.2-S fixture: synthetic subject rows, an authoritative intent row on the
 * shared seam, registered workflow versions and the read-port doubles S consumes.
 *
 * Nothing here performs an external send, reaches a provider or touches another phase's authority; the
 * subject rows are the minimum durable facts a tier-F notification needs, and the ports are read-only
 * projections exactly as §8.2 declares them (test doubles live only under `tests/`).
 */
use Delnavazan\Platform\Core\Application\NotificationRecipientReadPort;
use Delnavazan\Platform\Core\Application\NotificationSubjectReadPort;
use Delnavazan\Platform\Core\Application\NotificationDispatchService;
use Delnavazan\Platform\Core\Application\NotificationRule;
use Delnavazan\Platform\Core\Application\NotificationService;
use Delnavazan\Platform\Core\Application\NotificationSupport;
use Delnavazan\Platform\Core\Application\NotificationTemplateService;
use Delnavazan\Platform\Core\Application\NotificationTransportPort;
use Delnavazan\Platform\Core\Application\NotificationWorkflowService;
use Delnavazan\Platform\Core\Support\Identifier;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationSuppressionRepository;

function dzn_s_fix_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('Phase 2A.2-S: '.$message);}
function dzn_s_fix_key(string $label):string{return 'dzn-2a2s-'.$label.'-'.wp_generate_uuid4();}
function dzn_s_fix_evidence(string $reference):array{return array('evidence_channel'=>'staff_record','evidence_reference'=>$reference,'evidence_at'=>gmdate('Y-m-d H:i:s'));}
function dzn_s_fix_rejected(callable $callable,string $code,string $message):void{
    try{$callable();}catch(Throwable $error){dzn_s_fix_assert($error->getMessage()===$code,'the refusal for '.$message.' must report '.$code.', got '.$error->getMessage());return;}
    throw new RuntimeException('Phase 2A.2-S: the refusal for '.$message.' was not raised');
}
function dzn_s_fix_count(string $table):int{global $wpdb;return (int)$wpdb->get_var('SELECT COUNT(*) FROM '.$wpdb->prefix.'dzn_'.$table);}

/** The disposable S tables and every S-owned outbox row, in dependency order. */
function dzn_s_fix_reset():void{
    global $wpdb;$p=$wpdb->prefix.'dzn_';
    delete_option('dzn_phase_2a2s_concurrency_port_calls');
    foreach(array(
        'notification_privacy_tombstones','notification_suppression_commands','notification_suppression_events','notification_suppressions',
        'notification_deliveries','notification_attempt_events','notification_attempts','notification_commands','notification_events',
        'notification_rendered_snapshots','notifications','notification_template_commands','notification_template_versions','notification_templates',
        'notification_workflow_commands','notification_workflow_rules','notification_workflow_versions','notification_workflows',
    ) as $table)dzn_s_fix_assert($wpdb->query("DELETE FROM {$p}{$table}")!==false,'Failed to reset disposable S storage: '.$table);
    dzn_s_fix_assert($wpdb->query("DELETE FROM {$p}platform_outbox WHERE notification_id IS NOT NULL OR workflow_key IS NOT NULL")!==false,'Failed to reset the S-owned seam rows');
}

/**
 * The concurrency transport double: S registers no real binding, so the race counts the hand-offs that
 * actually reached the port and stores the count where the separately invoked verifier can read it.
 */
final class DznSConcurrencyTransport implements NotificationTransportPort {
    public function handoff(array $authorisedCommand):array{
        update_option('dzn_phase_2a2s_concurrency_port_calls',(int)get_option('dzn_phase_2a2s_concurrency_port_calls',0)+1,false);
        return array('acknowledged'=>true,'permanent_failure'=>null);
    }
}
function dzn_s_fix_port_calls():int{return (int)get_option('dzn_phase_2a2s_concurrency_port_calls',0);}
/** One dispatch runtime wired to the fixture's read ports and the counting transport double. */
function dzn_s_fix_dispatch(array $ready,NotificationTransportPort $transport):NotificationDispatchService{
    return new NotificationDispatchService(null,null,null,null,$transport,$ready['subjects'],$ready['recipients'],new NotificationSuppressionRepository());
}

/** A synthetic `renewal_cycle` subject row: the durable tier-F instant source the owning phase writes. */
function dzn_s_fix_cycle(?string $automaticChargeAt,?string $guaranteeDeadlineAt,string $label,int $recurringId=0):int{
    global $wpdb;$p=$wpdb->prefix.'dzn_';
    $now=gmdate('Y-m-d H:i:s');
    $recurringId=$recurringId>0?$recurringId:1;
    $ok=$wpdb->insert($p.'renewal_cycles',array(
        'uid'=>Identifier::uid(),'reference_code'=>null,'recurring_enrolment_id'=>$recurringId,'sequence'=>1,
        'source_term_id'=>1,'next_term_id'=>null,'collection_mode'=>'automatic','currency'=>'AUD','amount_minor'=>25000,
        'boundary_derived_at'=>gmdate('Y-m-d H:i:s',strtotime('+30 days')),'automatic_charge_at'=>$automaticChargeAt,
        'guarantee_deadline_at'=>$guaranteeDeadlineAt,'state'=>'pending','renewal_cycle_version'=>1,
        'created_at'=>$now,'updated_at'=>$now,'created_by'=>1,'updated_by'=>1,
    ));
    dzn_s_fix_assert($ok!==false,'Failed to insert the synthetic cycle subject: '.$wpdb->last_error);
    $cycleId=(int)$wpdb->insert_id;
    dzn_s_fix_cycle_event($cycleId,'opened',null,'pending',$label);
    return $cycleId;
}
function dzn_s_fix_cycle_event(int $cycleId,string $eventType,?string $from,string $to,string $label):void{
    global $wpdb;$p=$wpdb->prefix.'dzn_';
    $now=gmdate('Y-m-d H:i:s');
    $sequence=1+(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(event_sequence),0) FROM {$p}renewal_cycle_events WHERE renewal_cycle_id=%d",$cycleId));
    dzn_s_fix_assert($wpdb->insert($p.'renewal_cycle_events',array(
        'uid'=>Identifier::uid(),'renewal_cycle_id'=>$cycleId,'event_sequence'=>$sequence,'event_type'=>$eventType,
        'from_state'=>$from,'to_state'=>$to,'reason_code'=>$eventType,'evidence_channel'=>'staff_record',
        'evidence_reference_digest'=>hash_hmac('sha256',$label,wp_salt('dzn_recurring')),'evidence_at'=>$now,
        'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>1,'created_at'=>$now,'created_by'=>1,
    ))!==false,'Failed to insert the synthetic cycle event '.$eventType);
}
/** Drive one legal successor transition of a cycle, so late observation/dispatch can be proved. */
function dzn_s_fix_cycle_transition(int $cycleId,string $operation,string $to,string $label):void{
    global $wpdb;$p=$wpdb->prefix.'dzn_';
    $cycle=$wpdb->get_row($wpdb->prepare("SELECT state FROM {$p}renewal_cycles WHERE id=%d",$cycleId));
    dzn_s_fix_assert($cycle!==null,'the synthetic cycle must exist');
    dzn_s_fix_cycle_event($cycleId,$operation,(string)$cycle->state,$to,$label);
    dzn_s_fix_assert($wpdb->update($p.'renewal_cycles',array('state'=>$to),array('id'=>$cycleId))!==false,'Failed to advance the synthetic cycle state');
}
/** Publish one authoritative intent row the way R2 does: intent name only, keyed digest identity. */
function dzn_s_fix_intent(string $aggregate,int $aggregateId,string $intent,string $label):int{
    global $wpdb;$p=$wpdb->prefix.'dzn_';
    $key=hash_hmac('sha256','recurring_intent:'.$aggregate.':'.$aggregateId.':'.$intent,wp_salt('dzn_recurring'));
    $existing=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}platform_outbox WHERE idempotency_key=%s",$key));
    if($existing>0)return $existing;
    $now=gmdate('Y-m-d H:i:s');
    dzn_s_fix_assert($wpdb->insert($p.'platform_outbox',array(
        'aggregate_type'=>$aggregate,'aggregate_id'=>$aggregateId,'event_type'=>$intent,'invitation_id'=>null,'generation_id'=>null,
        'idempotency_key'=>$key,'status'=>'pending','available_at'=>$now,'leased_at'=>null,'processed_at'=>null,'attempt_count'=>0,'created_at'=>$now,
    ))!==false,'Failed to publish the synthetic intent '.$intent);
    return (int)$wpdb->insert_id;
}

/** The recipient projection double: digests and resolved channel-neutral facts only. */
final class DznSRecipientPort implements NotificationRecipientReadPort {
    public bool $resolvable=true; public bool $optedIn=true; public bool $guardian=true;
    public ?string $timezone='Australia/Brisbane'; public ?string $academyTimezone='Australia/Brisbane';
    public string $locale='fa';
    public function recipient(string $audience,string $recipientKind,int $subjectAggregateId):?array{
        if(!$this->resolvable)return null;
        return array(
            'resolvable'=>true,'opted_in'=>$this->optedIn,'guardian_authority_present'=>$this->guardian,
            'recipient_digest'=>hash_hmac('sha256','recipient:'.$audience.':'.$recipientKind.':'.$subjectAggregateId,NotificationSupport::salt()),
            'contact_digest'=>hash_hmac('sha256','contact:'.$subjectAggregateId,NotificationSupport::salt()),
            'contact_envelope'=>null,'contact_cipher_version'=>null,'contact_expires_at'=>null,
            'timezone'=>$this->timezone,'academy_timezone'=>$this->academyTimezone,'locale'=>$this->locale,
        );
    }
}
/**
 * The subject projection double. It reads the *persisted* column the owning module committed, exactly as
 * §6.2.4 requires, so a NULL column really does produce `tier_f_instant_unavailable`.
 */
final class DznSSubjectPort implements NotificationSubjectReadPort {
    public bool $exists=true;
    public function subject(string $aggregate,int $aggregateId,?string $instantColumn):?array{
        if(!$this->exists)return null;
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $table=NotificationRule::SUBJECT_TABLES[$aggregate];
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}{$table} WHERE id=%d",$aggregateId));
        if(!$row)return null;
        return array(
            'exists'=>true,
            'instant'=>$instantColumn!==null&&isset($row->{$instantColumn})?$row->{$instantColumn}:null,
            'timezone'=>'Australia/Brisbane',
            'subject_reference_digest'=>hash_hmac('sha256','subject:'.$aggregate.':'.$aggregateId,NotificationSupport::salt()),
        );
    }
}

/**
 * Register a template, a workflow and one activated version with the complete required rule set.
 *
 * `$overrides` lets a test replace or drop a single rule, so the per-code negative matrix and the
 * binding-matrix suite drive the real activation path rather than a hand-built row.
 */
function dzn_s_fix_version(string $intent,string $label,array $overrides=array(),bool $activate=true,int $leadMinutes=60,array $scheduleRules=array()):array{
    $audience=$overrides['audience']??'student';
    $recipientKind=$overrides['recipient_kind']??'student';
    $tier=NotificationRule::intentTier($intent);
    $templates=new NotificationTemplateService();
    $template=$templates->registerTemplate(array('template_key'=>'tpl-'.$label.'-'.substr(md5($label),0,8),'purpose'=>'notification','locale'=>'fa'),dzn_s_fix_key('tpl-'.$label));
    $templateVersion=$templates->registerVersion((int)$template['template_id'],array(
        'subject_template_digest'=>hash('sha256','subject-'.$label),'body_template_digest'=>hash('sha256','body-'.$label),
        // §6.4: the canonical variable contract is derived from the declared code set — the fixture declares
        // the empty contract, so the frozen snapshot needs no parameter input and the contract/digest proofs
        // are exercised by the corruption suite instead.
        'variable_codes'=>array(),'locale'=>'fa',
    ),dzn_s_fix_key('tplver-'.$label));
    $templates->activateVersion((int)$template['template_id'],(int)$templateVersion['template_version_id'],dzn_s_fix_key('tplact-'.$label));
    $workflows=new NotificationWorkflowService();
    $workflow=$workflows->registerWorkflow(array('workflow_key'=>NotificationRule::INTENT_WORKFLOW_KEYS[$intent].'.'.$label,'purpose'=>'notification'),dzn_s_fix_key('wf-'.$label));
    $version=$workflows->registerVersion((int)$workflow['workflow_id'],array(
        'intent_key'=>$intent,'audience'=>$audience,'recipient_kind'=>$recipientKind,
        'template_id'=>(int)$template['template_id'],'locale'=>'fa',
    ),dzn_s_fix_key('wfv-'.$label));
    $versionId=(int)$version['workflow_version_id'];
    $binding=NotificationRule::requiredBinding($intent);
    $rules=array(
        array('rule_code'=>'subject_exists','parameter_a'=>$binding['aggregate']),
        array('rule_code'=>'subject_state_is','parameter_a'=>$binding['aggregate'],'parameter_b'=>implode(',',$binding['to_states'])),
        array('rule_code'=>'recipient_resolvable'),
        array('rule_code'=>'recipient_opted_in'),
        array('rule_code'=>'guardian_authority_present'),
        array('rule_code'=>'not_suppressed'),
    );
    if($tier==='F'){
        $rules[]=array('rule_code'=>'subject_instant_in_future');
        $rules[]=array('rule_code'=>'lead_time_at_least','parameter_c'=>$leadMinutes);
    }
    foreach($rules as $rule){
        if(isset($overrides['drop'])&&$overrides['drop']===$rule['rule_code'])continue;
        if(isset($overrides[$rule['rule_code']]))$rule=array_merge($rule,$overrides[$rule['rule_code']]);
        $workflows->setEligibilityRule($versionId,$rule,dzn_s_fix_key('rule-'.$label.'-'.$rule['rule_code']));
    }
    if(isset($overrides['duplicate'])&&$overrides['duplicate']!==''){
        $workflows->setEligibilityRule($versionId,array('rule_code'=>$overrides['duplicate'],'parameter_a'=>$binding['aggregate'],'parameter_b'=>implode(',',$binding['to_states'])),dzn_s_fix_key('rule-dup-'.$label));
    }
    $schedule=$scheduleRules!==array()?$scheduleRules:($tier==='F'
        ?array(array('rule_code'=>'lead_time','parameter_a'=>(string)$leadMinutes),array('rule_code'=>'expiry','parameter_a'=>'1440'))
        :array(array('rule_code'=>'immediate'),array('rule_code'=>'expiry','parameter_a'=>'1440')));
    foreach($schedule as $rule)$workflows->setScheduleRule($versionId,$rule,dzn_s_fix_key('sched-'.$label.'-'.($rule['rule_code']??'x').'-'.($rule['ordinal']??1)));
    if(isset($overrides['retry'])&&$overrides['retry']!==array())foreach($overrides['retry'] as $rule)$workflows->setRetryRule($versionId,$rule,dzn_s_fix_key('retry-'.$label.'-'.($rule['ordinal']??1)));
    $activated=null;
    if($activate)$activated=$workflows->activateVersion($versionId,dzn_s_fix_key('act-'.$label));
    return array(
        'template_id'=>(int)$template['template_id'],'template_version_id'=>(int)$templateVersion['template_version_id'],
        'workflow_id'=>(int)$workflow['workflow_id'],'workflow_version_id'=>$versionId,'activated'=>$activated,
    );
}
/** One activated version plus the read ports a test needs, wired into a NotificationService. */
function dzn_s_fix_ready(string $intent,string $label,array $overrides=array(),int $leadMinutes=60,array $scheduleRules=array()):array{
    $version=dzn_s_fix_version($intent,$label,$overrides,true,$leadMinutes,$scheduleRules);
    $recipients=new DznSRecipientPort();$subjects=new DznSSubjectPort();
    $service=new NotificationService(null,null,null,null,null,null,$recipients,$subjects);
    return array('version'=>$version,'recipients'=>$recipients,'subjects'=>$subjects,'service'=>$service);
}
/**
 * One **draft** successor of a ready fixture's active version: the same workflow, the same intent and the
 * same frozen rule set, left unactivated so an activation race can supersede the predecessor while a
 * dispatch of the already-observed notification is in flight (§15 `activation_vs_dispatch`).
 */
function dzn_s_fix_draft_successor(array $ready,string $intent,string $label):int{
    global $wpdb;$p=$wpdb->prefix.'dzn_';
    $workflows=new NotificationWorkflowService();
    $predecessorId=(int)$ready['version']['workflow_version_id'];
    $predecessor=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}notification_workflow_versions WHERE id=%d",$predecessorId));
    dzn_s_fix_assert($predecessor!==null,'the successor\'s predecessor must exist');
    $successor=$workflows->registerVersion((int)$predecessor->workflow_id,array(
        'intent_key'=>$intent,'audience'=>(string)$predecessor->audience,
        'recipient_kind'=>(string)$predecessor->recipient_kind,
        'template_id'=>(int)$predecessor->template_id,'locale'=>(string)$predecessor->locale,
    ),dzn_s_fix_key('wfv-succ-'.$label));
    $successorId=(int)$successor['workflow_version_id'];
    // The successor repeats the predecessor's frozen composition, so only the routing slot decides the race.
    foreach($wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}notification_workflow_rules WHERE workflow_version_id=%d ORDER BY rule_kind,rule_code,ordinal",$predecessorId)) as $rule){
        $input=array(
            'rule_code'=>(string)$rule->rule_code,'ordinal'=>(int)$rule->ordinal,
            'parameter_a'=>$rule->parameter_a,'parameter_b'=>$rule->parameter_b,
            'parameter_c'=>$rule->parameter_c,'parameter_d'=>$rule->parameter_d,
        );
        $tag='succ-'.$label.'-'.(string)$rule->rule_kind.'-'.(string)$rule->rule_code.'-'.(int)$rule->ordinal;
        if((string)$rule->rule_kind==='eligibility')$workflows->setEligibilityRule($successorId,$input,dzn_s_fix_key($tag));
        elseif((string)$rule->rule_kind==='schedule')$workflows->setScheduleRule($successorId,$input,dzn_s_fix_key($tag));
        elseif((string)$rule->rule_kind==='retry')$workflows->setRetryRule($successorId,$input,dzn_s_fix_key($tag));
    }
    return $successorId;
}
