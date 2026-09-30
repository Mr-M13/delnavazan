<?php
/**
 * Disposable Phase-T concurrency worker. Synthetic local data only.
 *
 * Two families of race share one worker:
 *
 * - the execution race: the holder opens a transaction that locks the R1 commercial account root for its
 *   Student and gates on the runner's release file; the contender then attempts the opposing operation.
 *   Exactly one of every opposing pair may dispatch, the loser must be refused durably with
 *   `dispatch_in_flight`, and an expired lease may be taken over exactly once;
 * - [C8-3] the duplicate-delivery race (`duplicate_webhook`, `conflicting_duplicate_webhook`): both workers
 *   announce themselves in the gate directory, wait for each other, and then submit one provider event
 *   identity at the same moment. The unique `provider_event` index and the recorded immutable fact digest
 *   — never a read-then-insert — decide which worker owns the event and whether the other converges or
 *   records the controlled conflict.
 * - [C9-1] the decision-claim races (`pending_decision_retry`, `undecided_event_recovery`): two workers
 *   deliver one body for an event that already owes its decision (a deferred decision, or no decision row
 *   at all). The unique `event_claim` index decides which worker may run the translation and the R2
 *   consequence; the other performs no work and converges on the decision the owner appended.
 * - [C10-2] the stale-owner race (`stale_owner_after_lease_expiry`): the first worker takes the claim for
 *   an owed decision and then stalls until its own bounded lease has expired, the second worker takes the
 *   claim over and completes the decision, and the first worker then resumes. It must perform **no** R1/R2
 *   work: the gate in front of every work unit refuses the closed window instead of discovering the loss
 *   later, so exactly one worker ever reaches the R1 boundary and the R2 consequence.
 * - [C12-1] the append race (`stale_owner_at_decision_append`): the first worker completes every R1/R2 work
 *   unit of the decision operation and then lets the very window it is still inside lapse — in real elapsed
 *   time, without the claim row being written at all — at the append seam, with no successor having taken its
 *   claim over. The fenced `claimed → settled` transition of the append must refuse it because that statement
 *   judges the window itself, at the instant it runs: the lease, never a replaced generation, is what closes
 *   the window, so the stale generation appends nothing, releases the live claim it appended nothing to, and
 *   converges, and the next delivery completes the event exactly once.
 */
if(getenv('DZN_PHASE_2A2T_RUNTIME_TEST')!=='concurrency'||!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){fwrite(STDERR,"Phase 2A.2-T concurrency worker refused.\n");exit(1);}
use Delnavazan\Platform\Core\Application\PaymentExecution\{PaymentEventIntakeService,PaymentExecutionDispatchSeal,PaymentExecutionService,PaymentExecutionSupport,PaymentProviderRegistry,ProviderReferenceClaims};
use Delnavazan\Platform\Integrations\Payment\ContractPaymentAdapter;
use Delnavazan\Platform\Integrations\Payment\Stripe\{StripeEventTranslator,StripeSignatureVerifier};
global $wpdb;$p=$wpdb->prefix.'dzn_';
function dzn_tcw_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
/**
 * [C14-1] Attribute the append that is queued behind this worker's own claim-row lock.
 *
 * The waiting transaction must be *someone else's* — this worker's own transaction is the one holding the
 * row — and it must be waiting on exactly this claim row, so the observation is the append this race queued
 * and not unrelated contention. MySQL exposes the pair through Performance Schema (the blocking thread is
 * this worker's own, and the requested lock names the claim row's primary key); MariaDB reports the same
 * wait through InnoDB's transaction state, where the waiting statement itself is recorded. Returns null
 * while no such wait is visible, so the caller keeps polling inside the owner's live window.
 */
function dzn_tcw_append_wait_probe(string $table,int $claimId):?array{
    global $wpdb;
    $own=(int)$wpdb->get_var('SELECT CONNECTION_ID()');
    $db=(string)$wpdb->get_var('SELECT DATABASE()');
    $has=$wpdb->get_col("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='performance_schema' AND TABLE_NAME IN ('data_lock_waits','data_locks','threads')")?:array();
    if(count($has)===3){
        $thread=(int)$wpdb->get_var($wpdb->prepare("SELECT THREAD_ID FROM performance_schema.threads WHERE PROCESSLIST_ID=%d",$own));
        if($thread>0){
            $wait=$wpdb->get_row($wpdb->prepare("SELECT w.REQUESTING_THREAD_ID,w.BLOCKING_THREAD_ID,l.OBJECT_SCHEMA,l.OBJECT_NAME,l.INDEX_NAME,l.LOCK_TYPE,l.LOCK_MODE,l.LOCK_DATA FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID WHERE w.BLOCKING_THREAD_ID=%d AND l.OBJECT_SCHEMA=%s AND l.OBJECT_NAME=%s AND l.INDEX_NAME='PRIMARY' AND l.LOCK_DATA=%s LIMIT 1",$thread,$db,$table,(string)$claimId));
            if($wait)return array('mechanism'=>'performance_schema','at'=>gmdate('Y-m-d H:i:s'),'db_time'=>(string)$wpdb->get_var('SELECT UTC_TIMESTAMP()'),'claim_id'=>$claimId,'own_connection_id'=>$own,'blocking_thread_id'=>(int)$wait->BLOCKING_THREAD_ID,'requesting_thread_id'=>(int)$wait->REQUESTING_THREAD_ID,'table'=>(string)$wait->OBJECT_NAME,'index'=>(string)$wait->INDEX_NAME,'lock_type'=>(string)$wait->LOCK_TYPE,'lock_mode'=>(string)$wait->LOCK_MODE,'lock_data'=>(string)$wait->LOCK_DATA);
        }
    }
    $waiting=$wpdb->get_results($wpdb->prepare("SELECT trx_id,trx_mysql_thread_id,trx_state,trx_query FROM information_schema.INNODB_TRX WHERE trx_state='LOCK WAIT' AND trx_mysql_thread_id<>%d",$own))?:array();
    if(count($waiting)===1){
        $row=$waiting[0];
        return array('mechanism'=>'information_schema_innodb_trx','at'=>gmdate('Y-m-d H:i:s'),'db_time'=>(string)$wpdb->get_var('SELECT UTC_TIMESTAMP()'),'claim_id'=>$claimId,'own_connection_id'=>$own,'trx_id'=>(string)$row->trx_id,'requesting_connection_id'=>(int)$row->trx_mysql_thread_id,'state'=>(string)$row->trx_state,'table'=>$table,'query_available'=>$row->trx_query!==null,'query_matches_claim_table'=>str_contains((string)($row->trx_query??''),'payment_provider_event_decision_claims'));
    }
    return null;
}
$gate=(string)getenv('DZN_PHASE_2A2T_GATE_DIR');
$worker=(string)getenv('DZN_PHASE_2A2T_WORKER');
$fixture=get_option('dzn_phase_2a2t_concurrency_fixture');
dzn_tcw_assert(is_array($fixture)&&count($fixture['rows']??array())>=2,'the concurrency fixture must exist');
wp_set_current_user(1);
$mode=(string)$fixture['mode'];
if(in_array($mode,array('duplicate_webhook','conflicting_duplicate_webhook','pending_decision_retry','undecided_event_recovery','stale_owner_after_lease_expiry','stale_owner_inside_r1_unit','stale_owner_inside_r2_unit','stale_owner_at_decision_append','append_blocked_on_claim_row'),true)){
    if(!defined('DZN_PLATFORM_PAYMENT_TEST_VAULT'))define('DZN_PLATFORM_PAYMENT_TEST_VAULT',true);
    PaymentProviderRegistry::registerTranslator(new StripeEventTranslator(new StripeSignatureVerifier()));
    dzn_tcw_assert(isset($fixture['webhook']['selector'],$fixture['webhook']['body'],$fixture['webhook']['body_changed']),'the duplicate-webhook race fixture must exist');
    $webhook=$fixture['webhook'];
    // [C10-2] The stale-owner race needs two independent observations of the same decision operation:
    // every inherited R1/R2 work hook records which worker actually reached that boundary, and the
    // decision-claim hook lets the first worker let its own bounded window expire while it still owns the
    // claim. Both are written into the runner's gate directory, one file per worker.
    if($mode==='stale_owner_after_lease_expiry'&&$gate!==''&&is_dir($gate)){
        $mark=function(...$arguments)use($gate,$worker):void{file_put_contents($gate.'/'.$worker.'.work','1',FILE_APPEND);};
        add_action('dzn_phase_2a2r1_after_evidence_insert',$mark,10,1);
        add_action('dzn_phase_2a2r1_after_settlement',$mark,10,1);
        add_action('dzn_phase_2a2r2_after_collection_intent_event_insert',$mark,10,2);
        add_action('dzn_phase_2a2r2_after_cycle_event_insert',$mark,10,2);
        $stall=function(int $eventId,int $generation)use($gate,$worker,$wpdb,$p):void{
            if($worker!=='w1')return;
            // The window this worker owns is now older than its lease: exactly what a stall longer than
            // DECISION_CLAIM_LEASE_SECONDS produces, expressed here without waiting two minutes.
            $wpdb->query($wpdb->prepare("UPDATE {$p}payment_provider_event_decision_claims SET lease_expires_at=%s WHERE provider_event_id=%d AND active_claim_slot=1 AND claim_generation=%d",gmdate('Y-m-d H:i:s',time()-5),$eventId,$generation));
            file_put_contents($gate.'/'.$worker.'.claimed','1');
            $waited=0;
            while(!file_exists($gate.'/w2.json')&&$waited<900){usleep(100000);$waited++;}
        };
        add_action('dzn_phase_2a2t_after_provider_event_decision_claim',$stall,10,2);
    }
    // [C12-1] The append-seam race: every R1/R2 work unit of the decision operation has finished and the
    // decision is about to be published. The first worker lets the window it is still inside lapse *at that
    // seam* by real elapsed time — exactly what a decision operation that outlives
    // DECISION_CLAIM_LEASE_SECONDS between its last work unit and its append produces — and no successor
    // generation takes its claim over, so the fenced append itself, and never a take-over, is what must
    // refuse it. The claim row is never written here: the fence has to judge the window itself, at the
    // instant it runs, which is why this mode really does run for the structural lease.
    if($mode==='stale_owner_at_decision_append'&&$gate!==''&&is_dir($gate)){
        $mark=function(...$arguments)use($gate,$worker):void{file_put_contents($gate.'/'.$worker.'.work','1',FILE_APPEND);};
        add_action('dzn_phase_2a2r1_after_evidence_insert',$mark,10,1);
        add_action('dzn_phase_2a2r1_after_settlement',$mark,10,1);
        add_action('dzn_phase_2a2r2_after_collection_intent_event_insert',$mark,10,2);
        add_action('dzn_phase_2a2r2_after_cycle_event_insert',$mark,10,2);
        $expire=function(int $eventId,int $generation)use($gate,$worker,$wpdb,$p):void{
            if($worker!=='w1')return;
            // The owner's own live claim — its own generation, its live slot, its own token — is still
            // exclusively the owner's: no successor has taken it over, and the row is left exactly as the
            // owner took it. The window it is running inside is allowed to lapse in real time instead.
            $expires=(string)$wpdb->get_var($wpdb->prepare("SELECT lease_expires_at FROM {$p}payment_provider_event_decision_claims WHERE provider_event_id=%d AND active_claim_slot=1 AND claim_generation=%d",$eventId,$generation));
            file_put_contents($gate.'/w1.expired_at_append',$expires);
            if($expires!==''){
                $until=strtotime($expires.' UTC')+1;
                while(time()<$until)sleep(1);
            }
        };
        add_action('dzn_phase_2a2t_before_provider_event_decision_append',$expire,10,2);
    }
    // [C14-1] The queued-append race: a *separate transaction* — the second worker — holds the claim row the
    // owner is about to append through until the live window the owner holds has lapsed, and the owner's
    // append is queued behind that lock while the window is still open. A database-time expression is
    // evaluated once, when its statement starts, so a transition whose *own* statement waited for that lock
    // would settle the claim with the instant it began waiting. The owner therefore holds at the append seam
    // until the blocker really holds the row: the fenced locking read the append takes first — never the
    // conditional update — is then the statement that waits, and the update that judges the window runs only
    // once the row is held.
    if($mode==='append_blocked_on_claim_row'&&$gate!==''&&is_dir($gate)){
        $mark=function(...$arguments)use($gate,$worker):void{file_put_contents($gate.'/'.$worker.'.work','1',FILE_APPEND);};
        add_action('dzn_phase_2a2r1_after_evidence_insert',$mark,10,1);
        add_action('dzn_phase_2a2r1_after_settlement',$mark,10,1);
        add_action('dzn_phase_2a2r2_after_collection_intent_event_insert',$mark,10,2);
        add_action('dzn_phase_2a2r2_after_cycle_event_insert',$mark,10,2);
        $queue=function(int $eventId,int $generation)use($gate,$worker,$wpdb):void{
            if($worker!=='w1')return;
            // The window this owner holds is still live; it is about to append under it. Allow this
            // connection to wait for that row for the rest of the structural window — the server's default
            // row-lock timeout is 50 s, shorter than the 120-second lease — so the wait that must not decide
            // the window is not aborted by the server instead. Then announce the append and hold until the
            // blocker's own transaction really holds the claim row.
            $wpdb->query('SET SESSION innodb_lock_wait_timeout=600');
            file_put_contents($gate.'/w1.at_append',(string)$eventId.':'.(string)$generation);
            $waited=0;
            while(!file_exists($gate.'/w2.locked')&&$waited<3600){usleep(100000);$waited++;}
        };
        add_action('dzn_phase_2a2t_before_provider_event_decision_append',$queue,10,2);
    }
    // [C11-1] The in-unit fence race: the first worker stalls *inside* the R1 (or R2) mutation it is
    // running, with the bounded window that unit is running inside aged past expiry. The fence of the
    // unit's own transaction must therefore roll that unit back before its next statement — the stale
    // generation commits no part of it and releases the claim nobody is working inside any more — and the
    // second worker, which delivers while the first is stalled and can own nothing, records what the stale
    // generation actually committed before completing the event's decision as the next generation.
    if(in_array($mode,array('stale_owner_inside_r1_unit','stale_owner_inside_r2_unit'),true)&&$gate!==''&&is_dir($gate)){
        $stall=function(...$arguments)use($gate,$worker,$wpdb,$p):void{
            if($worker!=='w1')return;
            file_put_contents($gate.'/w1.work','1',FILE_APPEND);
            // The unit this worker is running inside is now older than the window it was granted: exactly
            // what a unit that outlives DECISION_CLAIM_LEASE_SECONDS produces, expressed here without
            // waiting two minutes. It is written inside the unit's own transaction, so the fence of that
            // transaction is the only thing that can stop the unit.
            $eventId=(int)$wpdb->get_var($wpdb->prepare("SELECT provider_event_id FROM {$p}payment_provider_event_decision_claims WHERE active_claim_slot=1 ORDER BY id DESC LIMIT 1"));
            $wpdb->query($wpdb->prepare("UPDATE {$p}payment_provider_event_decision_claims SET lease_expires_at=%s WHERE provider_event_id=%d AND active_claim_slot=1",gmdate('Y-m-d H:i:s',time()-5),$eventId));
            file_put_contents($gate.'/w1.aged','1');
            $waited=0;
            while(!file_exists($gate.'/w2.probe.json')&&$waited<900){usleep(100000);$waited++;}
        };
        if($mode==='stale_owner_inside_r1_unit')add_action('dzn_phase_2a2r1_after_evidence_insert',$stall,10,1);
        else add_action('dzn_phase_2a2r2_after_collection_intent_event_insert',$stall,10,2);
    }
    // The two deliveries must be in flight together: announce this worker, then wait for its sibling.
    if($gate!==''&&is_dir($gate)){
        file_put_contents($gate.'/'.$worker.'.started','1');
        $waited=0;
        while((!file_exists($gate.'/w1.started')||!file_exists($gate.'/w2.started'))&&$waited<300){usleep(100000);$waited++;}
        // [C10-2] The stale-owner contender may only deliver once the owner has taken its claim and let
        // the window lapse, so the race exercises the takeover itself — never a plain claim insert.
        if($mode==='stale_owner_after_lease_expiry'&&$worker==='w2'){
            $waited=0;
            while(!file_exists($gate.'/w1.claimed')&&$waited<900){usleep(100000);$waited++;}
        }
        // [C12-1] The append-race contender may only deliver once the owner has let its own window lapse at the
        // append seam and returned, having released the claim it appended nothing to. The refusal the stale
        // generation observes is therefore the lapsed lease of the claim it still owns — never a successor's
        // take-over — and this delivery is the one that completes the event. The wait has to cover the owner's
        // whole real-time lapse (the structural 120-second decision-claim lease), not just a few seconds.
        if($mode==='stale_owner_at_decision_append'&&$worker==='w2'){
            $waited=0;
            while(!file_exists($gate.'/w1.json')&&$waited<3600){usleep(100000);$waited++;}
        }
    }
    if(in_array($mode,array('stale_owner_inside_r1_unit','stale_owner_inside_r2_unit'),true)){
        $deliver=function(string $body)use($webhook):array{
            $signature='t='.time().',v1='.hash_hmac('sha256',time().'.'.$body,(string)$webhook['secret']);
            try{
                return array('outcome'=>(new PaymentEventIntakeService())->receive('stripe',(string)$webhook['selector'],$body,array('stripe-signature'=>$signature)),'error'=>null);
            }catch(Throwable$e){
                return array('outcome'=>null,'error'=>$e->getMessage());
            }
        };
        $raceBody=(string)$webhook['body'];
        if($worker==='w1'){
            $result=$deliver($raceBody);
            if($gate!=='')file_put_contents($gate.'/w1.json',(string)wp_json_encode(array('worker'=>'w1','operation'=>'webhook_delivery','outcome'=>$result['outcome'],'error'=>$result['error'],'at'=>gmdate('Y-m-d H:i:s'))));
            if($result['error']!==null)throw new RuntimeException('the stale generation must stop as a controlled closed window, never as a failure: '.$result['error']);
            echo "phase-2a2t-concurrency-worker w1 done\n";
            return;
        }
        // The contender may only deliver once the owner is inside the mutation with its window aged, so the
        // probe really races a unit that is running outside its window.
        $waited=0;
        while(!file_exists($gate.'/w1.aged')&&$waited<600){usleep(100000);$waited++;}
        dzn_tcw_assert(file_exists($gate.'/w1.aged'),'the stale generation never aged its window inside the work unit');
        $probe=$deliver($raceBody);
        if($gate!=='')file_put_contents($gate.'/w2.probe.json',(string)wp_json_encode(array('worker'=>'w2','operation'=>'probe_delivery','outcome'=>$probe['outcome'],'error'=>$probe['error'],'at'=>gmdate('Y-m-d H:i:s'))));
        $waited=0;
        while(!file_exists($gate.'/w1.json')&&$waited<900){usleep(100000);$waited++;}
        dzn_tcw_assert(file_exists($gate.'/w1.json'),'the stale generation never returned');
        // What the stale generation actually committed, observed after its unit was rolled back and before
        // this delivery completes the decision: the fence's proof that the stale unit committed nothing.
        $observation=array(
            'evidence'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_payment_evidence WHERE obligation_id=%d",(int)$webhook['obligation_id'])),
            'settlements'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d",(int)$webhook['obligation_id'])),
            'intent'=>(string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}collection_intents WHERE id=%d",(int)$webhook['intent_id'])),
            'cycle'=>(string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}renewal_cycles WHERE id=%d",(int)$webhook['cycle_id'])),
            'intent_events'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}collection_intent_events WHERE collection_intent_id=%d AND event_type='confirmed'",(int)$webhook['intent_id'])),
            'intent_commands'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}collection_intent_commands WHERE collection_intent_id=%d AND operation='confirm_collection_intent'",(int)$webhook['intent_id'])),
            'claims'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_event_decision_claims WHERE provider_event_id=%d",(int)$fixture['prepared_event_id'])),
            'live_claims'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_event_decision_claims WHERE provider_event_id=%d AND active_claim_slot=1",(int)$fixture['prepared_event_id'])),
        );
        if($gate!=='')file_put_contents($gate.'/w2.pre.json',(string)wp_json_encode(array('worker'=>'w2','operation'=>'stale_state_observation','probe_outcome'=>$probe['outcome'],'observation'=>$observation,'at'=>gmdate('Y-m-d H:i:s'))));
        $second=$deliver($raceBody);
        if($gate!=='')file_put_contents($gate.'/w2.json',(string)wp_json_encode(array('worker'=>'w2','operation'=>'webhook_delivery','outcome'=>$second['outcome'],'error'=>$second['error'],'at'=>gmdate('Y-m-d H:i:s'))));
        if($second['error']!==null)throw new RuntimeException($second['error']);
        echo "phase-2a2t-concurrency-worker w2 done\n";
        return;
    }
    // [C14-1] The queued-append race itself: the second worker is the *blocker*. It holds the owner's claim
    // row in its own transaction until the owner's live window has lapsed, while the owner's append — whose
    // first statement is the fenced locking read, not the conditional update — is queued behind that lock.
    // It only delivers after the owner has returned from the refused append, so what it observes first is
    // exactly what the queued append left behind: no settled claim and no appended decision.
    if($mode==='append_blocked_on_claim_row'){
        $deliver=function(string $body)use($webhook):array{
            $signature='t='.time().',v1='.hash_hmac('sha256',time().'.'.$body,(string)$webhook['secret']);
            try{
                return array('outcome'=>(new PaymentEventIntakeService())->receive('stripe',(string)$webhook['selector'],$body,array('stripe-signature'=>$signature)),'error'=>null);
            }catch(Throwable$e){
                return array('outcome'=>null,'error'=>$e->getMessage());
            }
        };
        $raceBody=(string)$webhook['body'];
        if($worker==='w1'){
            $result=$deliver($raceBody);
            if($gate!=='')file_put_contents($gate.'/w1.json',(string)wp_json_encode(array('worker'=>'w1','operation'=>'webhook_delivery','outcome'=>$result['outcome'],'error'=>$result['error'],'at'=>gmdate('Y-m-d H:i:s'))));
            if($result['error']!==null)throw new RuntimeException('a queued append must stop as a controlled closed window, never as a failure: '.$result['error']);
            echo "phase-2a2t-concurrency-worker w1 done\n";
            return;
        }
        $waited=0;
        while(!file_exists($gate.'/w1.at_append')&&$waited<3600){usleep(100000);$waited++;}
        dzn_tcw_assert(file_exists($gate.'/w1.at_append'),'the owner never reached the append seam');
        $claimId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}payment_provider_event_decision_claims WHERE provider_event_id=%d AND active_claim_slot=1 ORDER BY id ASC LIMIT 1",(int)$fixture['prepared_event_id']));
        dzn_tcw_assert($claimId>0,'the owner\'s live decision claim must exist before this worker locks it');
        // Hold the claim row — this worker's own transaction, for the whole remaining window.
        $wpdb->query('START TRANSACTION');
        $locked=$wpdb->get_row($wpdb->prepare("SELECT id,claim_generation,lease_expires_at FROM {$p}payment_provider_event_decision_claims WHERE id=%d FOR UPDATE",$claimId));
        dzn_tcw_assert($locked!==null,'the blocker must hold the claim row the owner appends through');
        $lease=(string)$locked->lease_expires_at;
        if($gate!=='')file_put_contents($gate.'/w2.locked',(string)wp_json_encode(array('worker'=>'w2','operation'=>'claim_row_lock','claim_id'=>$claimId,'claim_generation'=>(int)$locked->claim_generation,'connection_id'=>(int)$wpdb->get_var('SELECT CONNECTION_ID()'),'lease_expires_at'=>$lease,'db_time'=>(string)$wpdb->get_var('SELECT UTC_TIMESTAMP()'),'at'=>gmdate('Y-m-d H:i:s'))));
        // Prove the append is queued behind this lock *inside* the owner's live window, attributed to this
        // exact claim-row lock, before the lock is released.
        $queued=null;
        for($i=0;$i<600&&$queued===null;$i++){$queued=dzn_tcw_append_wait_probe($p.'payment_provider_event_decision_claims',$claimId);if($queued===null)usleep(100000);}
        dzn_tcw_assert(is_array($queued),'the append must be queued behind the claim row lock before it is released');
        if($gate!=='')file_put_contents($gate.'/w2.queued',(string)wp_json_encode($queued));
        // Hold until the window the queued append was granted has genuinely lapsed, then release the row.
        $until=strtotime($lease.' UTC')+1;
        while(time()<$until)sleep(1);
        if($gate!=='')file_put_contents($gate.'/w2.expired',(string)wp_json_encode(array('worker'=>'w2','operation'=>'window_lapsed','lease_expires_at'=>$lease,'db_time'=>(string)$wpdb->get_var('SELECT UTC_TIMESTAMP()'),'at'=>gmdate('Y-m-d H:i:s'))));
        if($gate!=='')file_put_contents($gate.'/w2.released',(string)wp_json_encode(array('worker'=>'w2','operation'=>'claim_row_release','db_time'=>(string)$wpdb->get_var('SELECT UTC_TIMESTAMP()'),'at'=>gmdate('Y-m-d H:i:s'))));
        $wpdb->query('COMMIT');
        $waited=0;
        while(!file_exists($gate.'/w1.json')&&$waited<3600){usleep(100000);$waited++;}
        dzn_tcw_assert(file_exists($gate.'/w1.json'),'the queued owner never returned from its append');
        // What the refused, queued append left behind — observed after it returned and *before* this delivery
        // completes the event: the queued append settled no claim and appended no decision of its own.
        $observation=array(
            'claims'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_event_decision_claims WHERE provider_event_id=%d",(int)$fixture['prepared_event_id'])),
            'settled_claims'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_event_decision_claims WHERE provider_event_id=%d AND claim_state='settled'",(int)$fixture['prepared_event_id'])),
            'live_claims'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_event_decision_claims WHERE provider_event_id=%d AND active_claim_slot=1",(int)$fixture['prepared_event_id'])),
            'decisions'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}payment_provider_event_decisions WHERE provider_event_id=%d",(int)$fixture['prepared_event_id'])),
            'owner_claim_state'=>(string)$wpdb->get_var($wpdb->prepare("SELECT claim_state FROM {$p}payment_provider_event_decision_claims WHERE id=%d",$claimId)),
            'evidence'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_payment_evidence WHERE obligation_id=%d",(int)$webhook['obligation_id'])),
            'settlements'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}commercial_obligation_settlements WHERE obligation_id=%d",(int)$webhook['obligation_id'])),
            'intent'=>(string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}collection_intents WHERE id=%d",(int)$webhook['intent_id'])),
            'cycle'=>(string)$wpdb->get_var($wpdb->prepare("SELECT state FROM {$p}renewal_cycles WHERE id=%d",(int)$webhook['cycle_id'])),
        );
        if($gate!=='')file_put_contents($gate.'/w2.pre.json',(string)wp_json_encode(array('worker'=>'w2','operation'=>'queued_append_observation','observation'=>$observation,'at'=>gmdate('Y-m-d H:i:s'))));
        $second=$deliver($raceBody);
        if($gate!=='')file_put_contents($gate.'/w2.json',(string)wp_json_encode(array('worker'=>'w2','operation'=>'webhook_delivery','outcome'=>$second['outcome'],'error'=>$second['error'],'at'=>gmdate('Y-m-d H:i:s'))));
        if($second['error']!==null)throw new RuntimeException($second['error']);
        echo "phase-2a2t-concurrency-worker w2 done\n";
        return;
    }
    $body=(string)($mode==='conflicting_duplicate_webhook'&&$worker==='w2'?$webhook['body_changed']:$webhook['body']);
    $signature='t='.time().',v1='.hash_hmac('sha256',time().'.'.$body,(string)$webhook['secret']);
    $outcome=null;$caught=null;
    try{
        $outcome=(new PaymentEventIntakeService())->receive('stripe',(string)$webhook['selector'],$body,array('stripe-signature'=>$signature));
    }catch(Throwable$e){$caught=$e;}
    if($gate!=='')file_put_contents($gate.'/'.$worker.'.json',(string)wp_json_encode(array('worker'=>$worker,'operation'=>'webhook_delivery','outcome'=>$outcome,'error'=>$caught?$caught->getMessage():null,'mutating_calls'=>0,'reconcile_calls'=>0,'total_calls'=>0,'at'=>gmdate('Y-m-d H:i:s'))));
    if($caught!==null)throw $caught;
    echo "phase-2a2t-concurrency-worker ".$worker." done\n";
    return;
}
$row=$worker==='w1'?$fixture['rows'][0]:$fixture['rows'][1];
$adapter=new ContractPaymentAdapter();
\Delnavazan\Platform\Core\Application\PaymentExecution\PaymentProviderRegistry::registerExecutionPort($adapter);
$service=new PaymentExecutionService();
$claims=new ProviderReferenceClaims('acct-conc',array('student'=>$row['references']['student'],'obligation'=>$row['references']['obligation'],'collection_intent'=>$row['references']['collection_intent']));
$target=$worker==='w1'?$fixture['rows'][0]:$fixture['rows'][1];
$operation=((string)$fixture['mode']==='submit_vs_cancel'||(string)$fixture['mode']==='submit_vs_cancel_in_flight')&&$worker==='w2'?'cancel':'submit';
if($gate!==''&&is_dir($gate)){
    // Holder: take the account-root lock and hold it until the runner releases the gate.
    $repository=new \Delnavazan\Platform\Core\Infrastructure\Repository\PaymentExecutionRepository();
    $repository->begin();
    PaymentExecutionSupport::lockAccountRoot((int)$row['student_id'],1);
    file_put_contents($gate.'/'.$worker.'.started','1');
    $i=0;while(!file_exists($gate.'/release')&&$i<1200){usleep(100000);$i++;}
    $repository->commit();
}
$outcome=null;$caught=null;
try{
    $outcome=$operation==='cancel'
        ?$service->cancelCollection((int)$row['intent_id'],array('provider_account_id'=>(int)$fixture['account_id'],'obligation_id'=>(int)$row['obligation_id']),$claims,'dzn-2a2tc-'.$fixture['mode'].'-'.$worker)
        :$service->submitCollection(array('provider_account_id'=>(int)$fixture['account_id'],'obligation_id'=>(int)$row['obligation_id'],'collection_intent_id'=>(int)$row['intent_id']),$claims,'dzn-2a2tc-'.$fixture['mode'].'-'.$worker);
}catch(Throwable$e){$caught=$e;}
$record=array('worker'=>$worker,'operation'=>$operation,'outcome'=>$outcome,'error'=>$caught?$caught->getMessage():null,'mutating_calls'=>$adapter->mutatingCalls(),'reconcile_calls'=>$adapter->calls('reconcile'),'total_calls'=>$adapter->totalCalls(),'at'=>gmdate('Y-m-d H:i:s'));
if($gate!=='')file_put_contents($gate.'/'.$worker.'.json',(string)wp_json_encode($record));
if($caught!==null&&$caught->getMessage()!==\Delnavazan\Platform\Core\Application\PaymentExecution\PaymentExecutionRule::COMMAND_CONFLICT_REASON&&$caught->getMessage()!=='dispatch_in_flight')throw $caught;
echo "phase-2a2t-concurrency-worker ".$worker." done\n";
