<?php
/**
 * Phase 2A.2-S concurrency fixture: the prepared state of one §15 race.
 *
 * One mode per invocation. Every fixture is built through the production path — the real workflow,
 * notification and dispatch services — and the race state is handed to the workers through one option.
 */
if(getenv('DZN_PHASE_2A2S_RUNTIME_TEST')!=='concurrency'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-S concurrency refused.\n");exit(1);}
require __DIR__.'/phase-2a2s-fixture.php';
use Delnavazan\Platform\Core\Application\NotificationRule;
use Delnavazan\Platform\Core\Application\NotificationSupport;
use Delnavazan\Platform\Core\Support\Identifier;

$modes=array(
    'dispatch_vs_retry','lease_expiry_vs_handoff','retry_exhaustion_vs_recovery',
    'subject_transition_after_enqueue_vs_dispatch','policy_change_after_publication_vs_dispatch',
    'deferral_vs_claim','activation_vs_dispatch','competing_activation_same_intent',
    'rule_attach_vs_activation','suppress_vs_enqueue','cancel_vs_dispatch',
    'delivery_vs_attempt_close','erase_vs_dispatch','unrelated_notifications',
);
$mode=(string)getenv('DZN_PHASE_2A2S_MODE');
$gate=(string)getenv('DZN_PHASE_2A2S_GATE_DIR');
if(!in_array($mode,$modes,true)){fwrite(STDERR,"DZN_PHASE_2A2S_MODE must name one of the fourteen declared modes.\n");exit(1);}
if($gate===''||!is_dir($gate)){fwrite(STDERR,"DZN_PHASE_2A2S_GATE_DIR required.\n");exit(1);}
global $wpdb;$p=$wpdb->prefix.'dzn_';
dzn_s_fix_reset();
wp_set_current_user(1);
$fixture=array('mode'=>$mode,'gate'=>$gate);

/** The narrow retry policy the exhaustion fixture walks: three attempts, one second apart, no jitter. */
$narrowRetry=array(
    array('rule_code'=>'retry','ordinal'=>1,'parameter_a'=>'3'),
    array('rule_code'=>'retry','ordinal'=>2,'parameter_a'=>'1'),
    array('rule_code'=>'retry','ordinal'=>3,'parameter_a'=>'10000'),
    array('rule_code'=>'retry','ordinal'=>4,'parameter_a'=>'1'),
    array('rule_code'=>'retry','ordinal'=>5,'parameter_a'=>'0'),
);
/** The composition the deferral race needs: one bounded step that stays inside the frozen window. */
$deferSchedule=array(
    array('rule_code'=>'immediate'),
    array('rule_code'=>'deferral','ordinal'=>1,'parameter_a'=>'1'),
    array('rule_code'=>'deferral','ordinal'=>2,'parameter_a'=>'3'),
    array('rule_code'=>'expiry','parameter_a'=>'1440'),
);
/** One queued TERM_LAPSED notification, observed and enqueued through the production path. */
$queued=static function(string $label,array $overrides=array(),array $schedule=array(),?array $reuse=null):array{
    $ready=$reuse??dzn_s_fix_ready('TERM_LAPSED','conc-'.$label,$overrides,60,$schedule);
    $cycle=dzn_s_fix_cycle(null,null,'conc-'.$label);
    dzn_s_fix_cycle_transition($cycle,'lapsed','lapsed','conc-'.$label);
    $outbox=dzn_s_fix_intent('renewal_cycle',$cycle,'TERM_LAPSED','conc-'.$label);
    $observed=$ready['service']->observeIntent($outbox,array_merge(dzn_s_fix_evidence('conc-'.$label),array('observed_at'=>gmdate('Y-m-d H:i:s'))),dzn_s_fix_key('obs-conc-'.$label));
    $ready['service']->enqueue((int)$observed['notification_id'],dzn_s_fix_evidence('conc-enq-'.$label),dzn_s_fix_key('enq-conc-'.$label));
    return array('ready'=>$ready,'cycle'=>$cycle,'outbox'=>(int)$outbox,'observed'=>$observed,'label'=>$label);
};
/** One queued tier-F advance notice: the intent bound to the cycle's single authoritative `opened` fact. */
$tierF=static function(string $label):array{
    $ready=dzn_s_fix_ready('AUTOMATIC_RENEWAL_UPCOMING','conc-'.$label);
    $cycle=dzn_s_fix_cycle(gmdate('Y-m-d H:i:s',strtotime('+2 days')),null,'conc-'.$label);
    $outbox=dzn_s_fix_intent('renewal_cycle',$cycle,'AUTOMATIC_RENEWAL_UPCOMING','conc-'.$label);
    $observed=$ready['service']->observeIntent($outbox,array_merge(dzn_s_fix_evidence('conc-'.$label),array('observed_at'=>gmdate('Y-m-d H:i:s'))),dzn_s_fix_key('obs-conc-'.$label));
    $ready['service']->enqueue((int)$observed['notification_id'],dzn_s_fix_evidence('conc-enq-'.$label),dzn_s_fix_key('enq-conc-'.$label));
    return array('ready'=>$ready,'cycle'=>$cycle,'outbox'=>(int)$outbox,'observed'=>$observed,'label'=>$label);
};
/** The frozen derivation a dispatch must leave untouched, whatever drifts around it. */
$snapshot=static function(array $prepared)use($wpdb,$p):array{
    $row=$wpdb->get_row($wpdb->prepare("SELECT notification_key_digest,schedule_anchor_at,scheduled_for,expires_at,deferral_count,timezone FROM {$p}notifications WHERE id=%d",(int)$prepared['observed']['notification_id']));
    return array(
        'notification_key_digest'=>(string)$row->notification_key_digest,'schedule_anchor_at'=>(string)$row->schedule_anchor_at,
        'scheduled_for'=>(string)$row->scheduled_for,'expires_at'=>(string)$row->expires_at,
        'deferral_count'=>(int)$row->deferral_count,'timezone'=>(string)$row->timezone,
    );
};
/** The shared fixture fields: the notification, its outbox row and the frozen snapshot. */
$bind=static function(array &$fixture,array $prepared,array $snapshot):void{
    $fixture['notification_id']=(int)$prepared['observed']['notification_id'];
    $fixture['outbox_id']=(int)$prepared['outbox'];
    $fixture['snapshot']=$snapshot;
};
$claim=static function(array $prepared):array{
    $dispatch=dzn_s_fix_dispatch($prepared['ready'],new DznSConcurrencyTransport());
    return $dispatch->claimLease(dzn_s_fix_evidence('conc-claim-'.$prepared['label']),dzn_s_fix_key('claim-conc-'.$prepared['label']));
};
/** Move one lease into the past, so the lease-expiry paths have a deterministic expired lease to recover. */
$expireLease=static function(int $attemptId)use($wpdb,$p):void{
    $wpdb->update($p.'notification_attempts',array('lease_expires_at'=>gmdate('Y-m-d H:i:s',time()-120)),array('id'=>$attemptId));
};
/**
 * Walk one notification to the retry ceiling through the production lease path: every below-ceiling closure
 * re-arms, and the final attempt's lease is left elapsed so the closure path and the recovery path meet the
 * same ceiling.
 */
$toCeiling=static function(array $prepared,array $evidence)use($expireLease):array{
    $ceiling=null;
    for($sequence=1;$sequence<=3;$sequence++){
        $deadline=microtime(true)+30;$claimed=array();
        do{
            $claimed=dzn_s_fix_dispatch($prepared['ready'],new DznSConcurrencyTransport())->claimLease($evidence,dzn_s_fix_key('ceiling-claim-'.$sequence.'-'.microtime(true)));
            if(!empty($claimed['claimed']))break;
            usleep(250000);
        }while(microtime(true)<$deadline);
        dzn_s_fix_assert(!empty($claimed['claimed']),'the ceiling walk must acquire attempt '.$sequence);
        $ceiling=$claimed;
        if($sequence<3){
            $closed=dzn_s_fix_dispatch($prepared['ready'],new DznSConcurrencyTransport())->recordOutcome((int)$claimed['attempt_id'],array_merge($evidence,array('failure_class'=>'retryable','reason_code'=>'retryable')),dzn_s_fix_key('ceiling-close-'.$sequence.'-'.microtime(true)));
            dzn_s_fix_assert((string)($closed['state']??'')==='queued','every below-ceiling closure must re-arm the notification');
        }
    }
    $expireLease((int)$ceiling['attempt_id']);
    return $ceiling;
};

if($mode==='rule_attach_vs_activation'){
    // A draft version and one appended rule: the worker either appends (winning the draft window) or the
    // activation freezes the set and refuses the append.
    $ready=dzn_s_fix_version('TERM_LAPSED','conc-rule',array(),false);
    $fixture['workflow_version_id']=(int)$ready['workflow_version_id'];
    $fixture['workflow_id']=(int)$ready['workflow_id'];
    $fixture['template_id']=(int)$ready['template_id'];
}elseif($mode==='competing_activation_same_intent'){
    // Two draft versions claim one consumed intent; the named routing slot decides.
    $first=dzn_s_fix_version('TERM_LAPSED','conc-act-1',array(),false);
    $second=dzn_s_fix_version('TERM_LAPSED','conc-act-2',array(),false);
    $fixture['first_version_id']=(int)$first['workflow_version_id'];
    $fixture['second_version_id']=(int)$second['workflow_version_id'];
}elseif($mode==='activation_vs_dispatch'){
    // One queued notification under the intent's active version, whose workflow also holds a complete draft
    // successor the holder activates while the contender dispatches the already-observed notification.
    $prepared=$queued($mode);
    $fixture['version_id']=(int)$prepared['ready']['version']['workflow_version_id'];
    $fixture['successor_version_id']=dzn_s_fix_draft_successor($prepared['ready'],'TERM_LAPSED','conc-'.$mode);
    $bind($fixture,$prepared,$snapshot($prepared));
}elseif($mode==='dispatch_vs_retry'){
    // One live lease: the holder reserves the hand-off (or replays it) while the contender closes the same
    // attempt with a retryable retry. At most one hand-off and at most one port call may exist.
    $prepared=$queued($mode);
    $claimed=$claim($prepared);
    $fixture['attempt_id']=(int)$claimed['attempt_id'];
    $bind($fixture,$prepared,$snapshot($prepared));
}elseif($mode==='lease_expiry_vs_handoff'){
    // One elapsed lease: recovery owns it and the transport port must never see it.
    $prepared=$queued($mode);
    $claimed=$claim($prepared);
    $fixture['attempt_id']=(int)$claimed['attempt_id'];
    $expireLease((int)$claimed['attempt_id']);
    $bind($fixture,$prepared,$snapshot($prepared));
}elseif($mode==='retry_exhaustion_vs_recovery'){
    // A lease at the ceiling: the closure path and the recovery path must agree on one exhaustion shape.
    $prepared=$queued($mode,array('retry'=>$narrowRetry));
    $ceiling=$toCeiling($prepared,dzn_s_fix_evidence('conc-'.$mode));
    $fixture['attempt_id']=(int)$ceiling['attempt_id'];
    $bind($fixture,$prepared,$snapshot($prepared));
}elseif($mode==='subject_transition_after_enqueue_vs_dispatch'){
    // One queued notification whose subject aggregate is driven through a later legal transition while the
    // dispatch is in flight: the frozen evidence tuple must still decide the same verdict.
    $prepared=$queued($mode);
    $fixture['cycle_id']=(int)$prepared['cycle'];
    $bind($fixture,$prepared,$snapshot($prepared));
}elseif($mode==='policy_change_after_publication_vs_dispatch'){
    // One queued tier-F notice whose commercial policy and recurring pattern both drift inside the holder's
    // locked window: the persisted announced instant and the frozen derivation must not move.
    $prepared=$tierF($mode);
    $now=gmdate('Y-m-d H:i:s');
    $wpdb->insert($p.'commercial_recurring_patterns',array(
        'uid'=>Identifier::uid(),'reference_code'=>null,'student_id'=>1,'teacher_id'=>1,'course_id'=>1,
        'source_kind'=>'intro_continuation','source_slot_authority_id'=>900000+(int)$prepared['cycle'],'intro_lesson_id'=>1,
        'weekday'=>1,'local_wall_time'=>'09:00:00','schedule_timezone'=>'Australia/Brisbane',
        'duration_minutes'=>60,'buffer_minutes'=>0,'anchor_starts_at_utc'=>gmdate('Y-m-d H:i:s',strtotime('+1 day')),
        'anchor_local_wall_date'=>gmdate('Y-m-d',strtotime('+1 day')),'pattern_version'=>1,'rule_version'=>'r2',
        'state'=>'active','superseded_by_pattern_id'=>null,'evidence_channel'=>'staff_record',
        'evidence_reference_digest'=>hash('sha256','conc-pattern'),'evidence_at'=>$now,'recorded_at'=>$now,
        'recorded_by'=>1,'created_at'=>$now,'created_by'=>1,'updated_at'=>$now,'updated_by'=>1,
    ));
    dzn_s_fix_assert($wpdb->insert_id>0,'the post-publication pattern row must exist');
    $fixture['pattern_id']=(int)$wpdb->insert_id;
    $fixture['policy_version']=1+(int)$wpdb->get_var("SELECT COALESCE(MAX(policy_version),0) FROM {$p}commercial_policies WHERE policy_key='AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME'");
    $fixture['cycle_id']=(int)$prepared['cycle'];
    $bind($fixture,$prepared,$snapshot($prepared));
}elseif($mode==='deferral_vs_claim'){
    // One queued notification with a bounded deferral rule: a deferral and a claim race on the same root.
    $prepared=$queued($mode,array(),$deferSchedule);
    $bind($fixture,$prepared,$snapshot($prepared));
}elseif($mode==='suppress_vs_enqueue'){
    // One observed but **not** enqueued notification: the suppression and the enqueue race on the same root,
    // and the loser must never lease work.
    $ready=dzn_s_fix_ready('TERM_LAPSED','conc-'.$mode);
    $cycle=dzn_s_fix_cycle(null,null,'conc-'.$mode);
    dzn_s_fix_cycle_transition($cycle,'lapsed','lapsed','conc-'.$mode);
    $outbox=dzn_s_fix_intent('renewal_cycle',$cycle,'TERM_LAPSED','conc-'.$mode);
    $observed=$ready['service']->observeIntent($outbox,array_merge(dzn_s_fix_evidence('conc-'.$mode),array('observed_at'=>gmdate('Y-m-d H:i:s'))),dzn_s_fix_key('obs-conc-'.$mode));
    $fixture['notification_id']=(int)$observed['notification_id'];
    $fixture['outbox_id']=(int)$outbox;
}elseif($mode==='cancel_vs_dispatch'){
    // One queued notification: a terminal command and a claim race on the same root, and the terminal
    // command must never leave the live lease behind it.
    $prepared=$queued($mode);
    $bind($fixture,$prepared,$snapshot($prepared));
}elseif($mode==='delivery_vs_attempt_close'){
    // One reserved hand-off: the acknowledgement and one normalised delivery fact race on the same root.
    $prepared=$queued($mode);
    $claimed=$claim($prepared);
    $reserved=dzn_s_fix_dispatch($prepared['ready'],new DznSConcurrencyTransport())->handOff((int)$claimed['attempt_id'],dzn_s_fix_evidence('conc-handoff-'.$mode),dzn_s_fix_key('handoff-conc-'.$mode));
    dzn_s_fix_assert(($reserved['handed_off']??false)===true,'the delivery fixture needs a reserved hand-off');
    $fixture['attempt_id']=(int)$claimed['attempt_id'];
    $bind($fixture,$prepared,$snapshot($prepared));
}elseif($mode==='erase_vs_dispatch'){
    // One queued notification: erasure and the claim race on the same root, and an in-flight dispatch is
    // never erased behind a live lease.
    $prepared=$queued($mode);
    $bind($fixture,$prepared,$snapshot($prepared));
}elseif($mode==='unrelated_notifications'){
    // Two notifications under the same consumed intent but with their own aggregate rows and subjects: two
    // unrelated serialisation roots, so the contender must complete while the holder's transaction is open.
    $prepared=$queued('unrelated');
    $second=$queued('unrelated-second',array(),array(),$prepared['ready']);
    $bind($fixture,$prepared,$snapshot($prepared));
    $fixture['second_notification_id']=(int)$second['observed']['notification_id'];
}
update_option('dzn_phase_2a2s_concurrency_fixture',$fixture,false);
echo "Phase 2A.2-S concurrency fixture ready\n";
