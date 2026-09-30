<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationAttemptRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationOutboxRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationSuppressionRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationTemplateRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationWorkflowRepository;

/**
 * §6.6/§9/§8.1 — the system-actor dispatch runtime: lease, hand off, close, recover.
 *
 * S owns no transport: hand-off happens only through `NotificationTransportPort`, which receives an
 * already-authorised, idempotent, channel-neutral command. The lease counter is the existing
 * `platform_outbox.attempt_count`, incremented exactly once per acquisition, and the two exhaustion gates
 * are read in the contract's fixed order — the ceiling first, the window second — so every closure has
 * exactly one deterministic shape.
 */
final class NotificationDispatchService {
    private const CAPABILITY='dzn_operate_notification_dispatch';
    public function __construct(
        private ?NotificationRepository $repository=null,
        private ?NotificationWorkflowRepository $workflows=null,
        private ?NotificationOutboxRepository $outbox=null,
        private ?NotificationAttemptRepository $attempts=null,
        private ?NotificationTransportPort $transport=null,
        private ?NotificationSubjectReadPort $subjects=null,
        private ?NotificationRecipientReadPort $recipients=null,
        private ?NotificationSuppressionRepository $suppressions=null,
        private ?NotificationTemplateRepository $templates=null
    ){
        $this->repository??=new NotificationRepository();
        $this->workflows??=new NotificationWorkflowRepository();
        $this->outbox??=new NotificationOutboxRepository();
        $this->attempts??=new NotificationAttemptRepository();
        $this->suppressions??=new NotificationSuppressionRepository();
        $this->templates??=new NotificationTemplateRepository();
    }

    /**
     * Claim one lease: re-read the aggregate and the outbox row under the S lock order, re-evaluate the
     * frozen required set, then write `status='leased'` with the attempt row in the same transaction.
     *
     * A lost race simply loses the claim: the status transition is guarded on the exact prior value, and the
     * lease token's unique key makes a duplicate lease impossible.
     */
    public function claimLease(array $input,string $key):array{
        NotificationSupport::requireCapability(self::CAPABILITY);
        $actor=NotificationSupport::actor();
        $evidence=NotificationSupport::evidence($input);
        $digest=NotificationSupport::keyDigest($key);
        $now=NotificationSupport::now();
        // §6.5/§9: a queued notification whose mandatory window has already closed is expired before any
        // claim set is read, so it can never be leased and sent outside its window.
        $this->expireOverdue($evidence,$now,$actor);
        $candidates=$this->outbox->claimable($now,1);
        if($candidates===array())return array('claimed'=>false,'reason'=>'no_claimable_work');
        $row=$candidates[0];
        $notificationId=(int)$row->notification_id;
        $this->repository->begin();
        try{
            $notification=$this->repository->find($notificationId,true);
            if(!$notification||(string)$notification->state!=='queued')throw new \RuntimeException('invalid_notification_state');
            $locked=$this->outbox->row((int)$row->id,true);
            // §10: the claim set is re-validated under the guard, not trusted from the pre-lock read — a row a
            // concurrent deferral or retry moved into the future is no longer claimable, so the loser observes
            // the moved instant instead of leasing work the notification itself postponed.
            if(!$locked||(string)$locked->status!=='queued'||$locked->leased_at!==null||(string)$locked->expires_at<=$now
                ||$locked->scheduled_for===null||(string)$locked->available_at>$now)throw new \RuntimeException('notification_lease_not_acquired');
            $version=$this->workflows->version((int)$notification->workflow_version_id,true);
            $this->requireFrozenVersion($version);
            // §7.3/§10: while the aggregate and its outbox row are locked and *before* any eligibility
            // verdict or lease, the claim re-derives the frozen composition, the persisted tier-F instant,
            // the frozen identity and the attempt history and runs the shared aggregate verification, so a
            // corrupted schedule, mirror or prior closure refuses the claim whole.
            $integrity=$this->aggregateGuard($notification,$version,$locked);
            $policy=$integrity['policy'];
            // §9: the claim re-evaluates the complete frozen required set and the suppression check before
            // any lease exists, and a notification that is no longer eligible is closed terminally here.
            $guard=$this->guardEligibility($notification,$version,$now);
            if((string)$guard['outcome']['outcome']!=='pass')return $this->refuseClaim($notification,$locked,$guard,$evidence,$digest,$now,$actor);
            $token=NotificationSupport::uid();
            $tokenDigest=hash_hmac('sha256','lease:'.$token,NotificationSupport::salt());
            $leaseExpires=NotificationSupport::addSeconds($now,(int)$policy['retry_initial_backoff_seconds']);
            if($leaseExpires===null)throw new \InvalidArgumentException('retry_schedule_divergence');
            $attemptCount=$this->outbox->lease((int)$locked->id,$now,$tokenDigest);
            $attemptId=$this->attempts->insertAttempt(array(
                'uid'=>NotificationSupport::uid(),'notification_id'=>$notificationId,'outbox_id'=>(int)$locked->id,
                'attempt_sequence'=>$attemptCount,'lease_token_digest'=>$tokenDigest,'state'=>'leased',
                'leased_at'=>$now,'lease_expires_at'=>$leaseExpires,'finished_at'=>null,'outcome_code'=>null,'failure_class'=>null,
                'duration_ms'=>null,'applied_jitter_bp'=>null,'base_backoff_seconds'=>null,'backoff_seconds'=>null,'next_available_at'=>null,
                'attempt_version'=>1,'created_at'=>$now,'updated_at'=>$now,'created_by'=>$actor,'updated_by'=>$actor,
            ));
            $this->repository->transition($notificationId,'queued',array('state'=>'dispatching','updated_at'=>$now,'updated_by'=>$actor));
            $this->repository->insertEvent($this->eventRow($notificationId,'dispatching','queued','dispatching','dispatching',$evidence,$now,$actor,$guard['bound_digest']));
            $this->attempts->insertEvent(array(
                'uid'=>NotificationSupport::uid(),'attempt_id'=>$attemptId,'event_sequence'=>1,'event_type'=>'leased',
                'from_state'=>null,'to_state'=>'leased','reason_code'=>'leased','occurred_at'=>$now,'recorded_at'=>$now,
                'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>NotificationSupport::uid(),'command_domain'=>NotificationRule::DOMAIN,'operation'=>'claim_lease',
                'command_key_digest'=>$digest,'command_payload_digest'=>NotificationSupport::payloadDigest(array('domain'=>NotificationRule::DOMAIN,'operation'=>'claim_lease','notification_id'=>$notificationId,'attempt_sequence'=>$attemptCount)),
                'notification_id'=>$notificationId,'result_state'=>'dispatching','result_id'=>$attemptId,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->commit();
            return array('claimed'=>true,'notification_id'=>$notificationId,'attempt_id'=>$attemptId,'attempt_sequence'=>$attemptCount,'lease_expires_at'=>$leaseExpires,'state'=>'dispatching');
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return array('claimed'=>false,'reason'=>'idempotent_replay','attempt_id'=>(int)$winner->result_id,'state'=>(string)$winner->result_state);
            if(str_contains($e->getMessage(),'notification_lease_not_acquired'))return array('claimed'=>false,'reason'=>'lost_race');
            throw $e;
        }
    }

    /**
     * §9 — re-evaluate the complete frozen required set and the suppression check on the claim path.
     *
     * A notification that is no longer eligible moves to its controlled terminal state without a send.
     */
    public function reEvaluateEligibility(int $attemptId,array $input,string $key):array{
        NotificationSupport::requireCapability(self::CAPABILITY);
        $actor=NotificationSupport::actor();
        $evidence=NotificationSupport::evidence($input);
        $digest=NotificationSupport::keyDigest($key);
        $attempt=$this->attempts->find($attemptId);
        if(!$attempt||$attempt->finished_at!==null)throw new \RuntimeException('notification_not_found');
        $notificationId=(int)$attempt->notification_id;
        $notification=$this->repository->find($notificationId);
        if(!$notification)throw new \RuntimeException('notification_not_found');
        $version=$this->workflows->version((int)$notification->workflow_version_id);
        $this->requireFrozenVersion($version);
        $this->repository->begin();
        try{
            $now=NotificationSupport::now();
            // §6.2/§6.8: every required fact is resolved through its own read port under the guard — subject,
            // recipient, consent, guardian authority and the active suppression — never hard-coded eligible.
            $guard=$this->guardEligibility($notification,$version,$now);
            $outcome=$guard['outcome'];
            if($outcome['outcome']!=='pass'){
                $code=(string)$outcome['code'];
                $state=NotificationRule::controlledState($code);
                $fields=array('state'=>$state,'failure_reason_code'=>$code,'updated_at'=>$now,'updated_by'=>$actor);
                if($code==='suppressed'&&$guard['suppression_id']!==null)$fields['suppression_id']=(int)$guard['suppression_id'];
                $this->repository->transition($notificationId,(string)$notification->state,$fields);
                $this->repository->insertEvent($this->eventRow($notificationId,$state,(string)$notification->state,$state,$code,$evidence,$now,$actor,$guard['bound_digest']));
                // §6.6/§9: an attempt that was already open when the re-evaluation refused the notification
                // is its own audited closure — `eligibility_abort` carrying the refusal code identically on
                // the attempt and the notification — never a retryable retry closure. It derives and
                // persists no retry schedule, appends its own attempt event and re-arms nothing.
                $this->attempts->closeAttempt((int)$attempt->id,array(
                    'state'=>'failed','finished_at'=>$now,'outcome_code'=>$code,
                    'failure_class'=>NotificationRule::ELIGIBILITY_ABORT_CLASS,'updated_at'=>$now,'updated_by'=>$actor,
                ));
                $this->attempts->insertEvent($this->attemptEvent((int)$attempt->id,'failed',(string)$attempt->state,'failed',$code,$now,$actor));
                $this->outbox->closeAny((int)$attempt->outbox_id,$state,$code);
                $this->repository->commit();
                return array('eligible'=>false,'outcome'=>$code,'state'=>$state);
            }
            $this->repository->insertEvent($this->eventRow($notificationId,'dispatching',(string)$notification->state,'dispatching','dispatching',$evidence,$now,$actor,$guard['bound_digest']));
            $this->repository->commit();
            return array('eligible'=>true,'outcome'=>'pass');
        }catch(\Throwable $e){
            $this->repository->rollback();
            throw $e;
        }
    }

    /**
     * §13 — hand the already-authorised command to the channel-neutral port. S stores no provider
     * identifier and no raw payload: only the acknowledgement and, on a permanent refusal, one member of
     * the closed §6.6 terminal-reason vocabulary.
     *
     * The command is built here from the persisted aggregate and its proved frozen snapshot alone; the
     * caller's `evidence_channel`/`evidence_reference`/`evidence_at` envelope is used for the audit trail
     * and can contribute nothing to what the transport receives.
     */
    public function handOff(int $attemptId,array $authorisedCommand,string $key):array{
        NotificationSupport::requireCapability(self::CAPABILITY);
        if($this->transport===null)throw new \RuntimeException('notification_transport_port_required');
        $actor=NotificationSupport::actor();
        $digest=NotificationSupport::keyDigest($key);
        $attempt=$this->attempts->find($attemptId);
        if(!$attempt||$attempt->finished_at!==null)throw new \RuntimeException('notification_not_found');
        // §9/§6.8: a successful re-evaluation of the complete frozen required set — every fact re-resolved
        // through the subject, recipient and suppression sources — is an unavoidable prerequisite to any
        // hand-off, so a consent withdrawal or a suppression that appeared while the work sat queued closes
        // the notification without a send.
        $eligibility=$this->reEvaluateEligibility($attemptId,$authorisedCommand,$key.':eligibility');
        if(($eligibility['eligible']??false)!==true)return array('attempt_id'=>$attemptId,'state'=>(string)($eligibility['state']??'failed'),'eligible'=>false,'outcome'=>(string)($eligibility['outcome']??'ineligible'),'acknowledged'=>false,'permanent_failure'=>null);
        $notification=$this->repository->find((int)$attempt->notification_id);
        if(!$notification)throw new \RuntimeException('notification_not_found');
        // §6.4/§13: the channel-neutral command is built from the frozen snapshot — the resolved
        // `template_version_id`, the contract-proved variable codes and the decrypted parameter envelope —
        // never from a caller-supplied payload. It is built *before* the reservation, so an unavailable
        // cipher or an unprovable variable contract leaves the attempt `leased` and the work claimable.
        $command=$this->authorisedCommand($notification,$attempt);
        // §6.6/§10: the durable hand-off reservation — the exact `leased → handed_off` transition plus its
        // digest-only command row — commits *before* the port is called, so one attempt can never be handed
        // off twice and a replay of the same key never reaches the port again.
        $reservation=$this->reserveHandOff($attemptId,$digest,$actor);
        if(($reservation['reserved']??false)!==true)return $reservation;
        // The attempt stays open until the outcome is recorded: `handed_off → acknowledged | failed`.
        $response=$this->transport->handoff($command);
        return array('attempt_id'=>$attemptId,'state'=>'handed_off','handed_off'=>true,'acknowledged'=>(bool)($response['acknowledged']??false),'permanent_failure'=>$response['permanent_failure']??null);
    }

    /**
     * §6.6/§10 — the durable, idempotent hand-off reservation.
     *
     * One transaction locks the aggregate, its outbox row and the attempt row in the fixed S lock order and
     * moves the attempt `leased → handed_off` exactly once: the transition is guarded on the persisted source
     * state, on the outbox row carrying the *same* lease token and on a lease that has not elapsed, and the
     * digest-only command row is written in the same transaction. A replay of the same key, and a second
     * hand-off of an attempt already reserved, both return the persisted reservation without touching the
     * port; a closed attempt, a lease held under another token and an elapsed lease are refused outright —
     * an elapsed lease belongs to recovery, never to the port.
     */
    private function reserveHandOff(int $attemptId,string $digest,int $actor):array{
        $now=NotificationSupport::now();
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$this->repository->commit();return $this->replayHandOff($attemptId,$winner);}
            $attempt=$this->attempts->find($attemptId);
            if(!$attempt||$attempt->finished_at!==null)throw new \RuntimeException('notification_not_found');
            $notification=$this->repository->find((int)$attempt->notification_id,true);
            if(!$notification)throw new \RuntimeException('notification_not_found');
            $row=$this->outbox->row((int)$attempt->outbox_id,true);
            if(!$row)throw new \RuntimeException('notification_outbox_row_required');
            $locked=$this->attempts->find($attemptId,true);
            if(!$locked||$locked->finished_at!==null)throw new \RuntimeException('notification_not_found');
            if((string)$locked->state==='handed_off'){$this->repository->commit();return array('attempt_id'=>$attemptId,'state'=>'handed_off','handed_off'=>true,'acknowledged'=>false,'permanent_failure'=>null,'replay'=>true);}
            if((string)$locked->state!=='leased')throw new \RuntimeException('notification_attempt_state_conflict');
            if((string)$locked->lease_expires_at<=$now){$this->repository->commit();return array('attempt_id'=>$attemptId,'state'=>'leased','handed_off'=>false,'acknowledged'=>false,'permanent_failure'=>null,'outcome'=>NotificationRule::LEASE_EXPIRED_OUTCOME);}
            if((string)$notification->state!=='dispatching'||(string)$row->status!=='leased'||$row->leased_at===null||(string)$row->lease_token_digest!==(string)$locked->lease_token_digest)throw new \RuntimeException('notification_attempt_state_conflict');
            $this->attempts->closeAttempt($attemptId,array('state'=>'handed_off','updated_at'=>$now,'updated_by'=>$actor));
            $this->attempts->insertEvent($this->attemptEvent($attemptId,'handed_off','leased','handed_off','handed_off',$now,$actor));
            $this->repository->insertCommand(array(
                'uid'=>NotificationSupport::uid(),'command_domain'=>NotificationRule::DOMAIN,'operation'=>'hand_off',
                'command_key_digest'=>$digest,'command_payload_digest'=>NotificationSupport::payloadDigest(array('domain'=>NotificationRule::DOMAIN,'operation'=>'hand_off','attempt_id'=>$attemptId)),
                'notification_id'=>(int)$locked->notification_id,'result_state'=>'handed_off','result_id'=>$attemptId,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->commit();
            return array('attempt_id'=>$attemptId,'state'=>'handed_off','handed_off'=>true,'acknowledged'=>false,'permanent_failure'=>null,'reserved'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replayHandOff($attemptId,$winner);
            throw $e;
        }
    }
    /** One hand-off key names one attempt: a replay never reaches the port and never rewrites a result. */
    private function replayHandOff(int $attemptId,object $winner):array{
        if((int)$winner->result_id!==$attemptId)throw new \RuntimeException(NotificationRule::REPLAY_CONFLICT);
        return array('attempt_id'=>$attemptId,'state'=>(string)$winner->result_state,'handed_off'=>true,'acknowledged'=>false,'permanent_failure'=>null,'replay'=>true);
    }

    /**
     * §6.6/§9 — close one attempt with a non-terminal or terminal class.
     *
     * A terminal class closes the notification as terminal `failed` with its own declared vocabulary
     * member, at every attempt sequence. A non-terminal closure is measured by the two gates in order: at
     * the ceiling it is `failed`/`retry_exhausted`; with an attempt remaining a clamp that leaves a usable
     * window re-arms by moving `available_at` alone, and a clamp that leaves none is
     * `expired`/`retry_window_exhausted`.
     */
    public function recordOutcome(int $attemptId,array $input,string $key,string $operation='record_outcome'):array{
        NotificationSupport::requireCapability(self::CAPABILITY);
        $actor=NotificationSupport::actor();
        $evidence=NotificationSupport::evidence($input);
        $digest=NotificationSupport::keyDigest($key);
        $acknowledged=!empty($input['acknowledged']);
        $failureClass=trim((string)($input['failure_class']??''));
        if(!$acknowledged&&!in_array($failureClass,NotificationRule::FAILURE_CLASSES,true))throw new \InvalidArgumentException('terminal_reason_invalid');
        $reason=trim((string)($input['reason_code']??''));
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$this->repository->commit();return array('attempt_id'=>$attemptId,'state'=>(string)$winner->result_state,'created'=>false,'idempotent'=>true);}
            // §10: the attempt row is only ever locked *last*, after the aggregate and its outbox row, so the
            // fixed order aggregate → outbox row → attempt row holds on every path and a hand-off racing a
            // closure on one attempt can never deadlock. The ids are discovered by an unlocked read first.
            $attempt=$this->attempts->find($attemptId);
            if(!$attempt||$attempt->finished_at!==null)throw new \RuntimeException('notification_not_found');
            $notification=$this->repository->find((int)$attempt->notification_id,true);
            if(!$notification)throw new \RuntimeException('notification_not_found');
            $row=$this->outbox->row((int)$attempt->outbox_id,true);
            if(!$row)throw new \RuntimeException('notification_outbox_row_required');
            $attempt=$this->attempts->find($attemptId,true);
            if(!$attempt||$attempt->finished_at!==null)throw new \RuntimeException('notification_not_found');
            // §6.6/§10: the locked lifecycle admits exactly one acknowledgement source — `handed_off` — and
            // two closure sources: an open `leased` attempt and an already handed-off one. An attempt that is
            // still `leased` can never be acknowledged without a hand-off, and a state outside the open
            // vocabulary can never be closed again.
            $source=(string)$attempt->state;
            if($acknowledged){
                if($source!=='handed_off')throw new \RuntimeException('notification_attempt_state_conflict');
            }elseif(!in_array($source,NotificationRule::ATTEMPT_OPEN_STATES,true)){
                throw new \RuntimeException('notification_attempt_state_conflict');
            }
            $policy=$this->retryPolicy($this->workflows->version((int)$notification->workflow_version_id,true));
            $now=NotificationSupport::now();
            if($acknowledged){
                // §6.5: the hand-off acknowledgement moves the notification to `dispatched`; delivery itself
                // is only ever written from a verified, normalised delivery fact.
                $this->attempts->closeAttempt($attemptId,array('state'=>'acknowledged','finished_at'=>$now,'outcome_code'=>NotificationRule::ACKNOWLEDGED_OUTCOME,'failure_class'=>null,'updated_at'=>$now,'updated_by'=>$actor));
                $this->attempts->insertEvent($this->attemptEvent($attemptId,'acknowledged',(string)$attempt->state,'acknowledged','acknowledged',$now,$actor));
                $this->repository->transition((int)$notification->id,(string)$notification->state,array('state'=>'dispatched','updated_at'=>$now,'updated_by'=>$actor));
                $this->repository->insertEvent($this->eventRow((int)$notification->id,'dispatched',(string)$notification->state,'dispatched','acknowledged',$evidence,$now,$actor));
                $this->outbox->markDelivered((int)$row->id,$now);
                $this->recordDispatchCommand($digest,$operation,(int)$notification->id,'dispatched',$now,$actor);
                $this->repository->commit();
                return array('attempt_id'=>$attemptId,'notification_id'=>(int)$notification->id,'state'=>'dispatched','created'=>true);
            }
            if($failureClass==='terminal'){
                // The single authoritative normaliser: the port's normalised cause maps onto the vocabulary,
                // and the same member is written on the attempt and on the notification.
                $code=NotificationIntegrity::normaliseTerminalReason($reason);
                $this->attempts->closeAttempt($attemptId,array('state'=>'failed','finished_at'=>$now,'outcome_code'=>$code,'failure_class'=>'terminal','updated_at'=>$now,'updated_by'=>$actor));
                $this->attempts->insertEvent($this->attemptEvent($attemptId,'failed',(string)$attempt->state,'failed',$code,$now,$actor));
                $this->repository->transition((int)$notification->id,(string)$notification->state,array('state'=>'failed','failure_reason_code'=>$code,'updated_at'=>$now,'updated_by'=>$actor));
                $this->repository->insertEvent($this->eventRow((int)$notification->id,'failed',(string)$notification->state,'failed',$code,$evidence,$now,$actor));
                $this->outbox->closeExhausted((int)$row->id,$code);
                $this->recordDispatchCommand($digest,$operation,(int)$notification->id,'failed',$now,$actor);
                $this->repository->commit();
                return array('attempt_id'=>$attemptId,'notification_id'=>(int)$notification->id,'state'=>'failed','failure_reason_code'=>$code,'class'=>'terminal','created'=>true);
            }
            $outcome=NotificationRule::nonTerminalClosureCode($failureClass)??'retryable';
            $closure=NotificationRetry::closure($policy,array(
                'attempt_sequence'=>(int)$attempt->attempt_sequence,'finished_at'=>$now,
                'expires_at'=>$notification->expires_at,'notification_key_digest'=>(string)$notification->notification_key_digest,
                'workflow_version'=>(int)$row->workflow_version,
            ));
            if($closure['re_arm']===true){
                // §9: one digest-only retry evidence proves the re-arm on **both** append-only histories, so
                // it is derived once, here, from the schedule this transaction persists.
                $retryEvidence=$this->retryEvidence($notification,$attempt,$closure);
                $this->attempts->closeAttempt($attemptId,array(
                    'state'=>'failed','finished_at'=>$now,'outcome_code'=>$outcome,'failure_class'=>$failureClass,
                    'applied_jitter_bp'=>$closure['applied_jitter_bp'],'base_backoff_seconds'=>$closure['base_backoff_seconds'],
                    'backoff_seconds'=>$closure['backoff_seconds'],'next_available_at'=>$closure['next_available_at'],
                    'updated_at'=>$now,'updated_by'=>$actor,
                ));
                $this->attempts->insertEvent($this->attemptEvent($attemptId,'failed',(string)$attempt->state,'failed',$outcome,$now,$actor));
                $this->attempts->insertEvent(array(
                    'uid'=>NotificationSupport::uid(),'attempt_id'=>$attemptId,'event_sequence'=>$this->attempts->nextSequence($attemptId),
                    'event_type'=>NotificationRule::RETRY_SCHEDULED_EVENT,'from_state'=>'failed','to_state'=>'failed','reason_code'=>$outcome,
                    'evidence_reference_digest'=>$retryEvidence,
                    'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
                ));
                $this->repository->transition((int)$notification->id,(string)$notification->state,array('state'=>'queued','updated_at'=>$now,'updated_by'=>$actor));
                $this->repository->insertEvent($this->eventRow((int)$notification->id,'queued',(string)$notification->state,'queued',$outcome,$evidence,$now,$actor));
                // §9: the notification's own append-only history records the same digest-only retry evidence,
                // appended in the same transaction as the schedule, the transition and the re-arm, as the
                // audit companion of the `queued` row the re-arm just produced.
                $this->repository->insertEvent($this->eventRow((int)$notification->id,NotificationRule::RETRY_SCHEDULED_EVENT,'queued','queued',$outcome,$evidence,$now,$actor,$retryEvidence));
                // Only a closure that re-arms moves `available_at`: the mirrored `scheduled_for` follows the
                // aggregate and is never rewritten by a retry.
                $this->outbox->rearm((int)$row->id,(string)$closure['next_available_at']);
                $this->recordDispatchCommand($digest,$operation,(int)$notification->id,'queued',$now,$actor);
                $this->repository->commit();
                return array('attempt_id'=>$attemptId,'notification_id'=>(int)$notification->id,'state'=>'queued','re_arm'=>true,'next_available_at'=>$closure['next_available_at'],'created'=>true);
            }
            // Exhaustion derives nothing and re-arms nothing; the closing attempt keeps its own
            // non-terminal class and closure code, and only the notification's reason code is rewritten.
            $this->attempts->closeAttempt($attemptId,array('state'=>'failed','finished_at'=>$now,'outcome_code'=>$outcome,'failure_class'=>$failureClass,'updated_at'=>$now,'updated_by'=>$actor));
            $this->attempts->insertEvent($this->attemptEvent($attemptId,'failed',(string)$attempt->state,'failed',$outcome,$now,$actor));
            // §6.6/§9: the gate→code mapping is single-sourced, so ceiling exhaustion always closes
            // `failed`/`retry_exhausted` and window exhaustion always closes `expired`/`retry_window_exhausted`.
            $exhaustionCode=NotificationRetry::exhaustionReasonCode($closure)??NotificationRule::WINDOW_EXHAUSTION_CODE;
            if($exhaustionCode===NotificationRule::CEILING_EXHAUSTION_CODE){
                $this->repository->transition((int)$notification->id,(string)$notification->state,array('state'=>'failed','failure_reason_code'=>$exhaustionCode,'updated_at'=>$now,'updated_by'=>$actor));
                $this->repository->insertEvent($this->eventRow((int)$notification->id,'failed',(string)$notification->state,'failed',$exhaustionCode,$evidence,$now,$actor));
                $this->outbox->closeExhausted((int)$row->id,$exhaustionCode);
                $this->recordDispatchCommand($digest,$operation,(int)$notification->id,'failed',$now,$actor);
                $this->repository->commit();
                return array('attempt_id'=>$attemptId,'notification_id'=>(int)$notification->id,'state'=>'failed','failure_reason_code'=>$exhaustionCode,'exhaustion'=>'ceiling','created'=>true);
            }
            $this->repository->transition((int)$notification->id,(string)$notification->state,array('state'=>'expired','failure_reason_code'=>$exhaustionCode,'updated_at'=>$now,'updated_by'=>$actor));
            $this->repository->insertEvent($this->eventRow((int)$notification->id,'expired',(string)$notification->state,'expired',$exhaustionCode,$evidence,$now,$actor));
            $this->outbox->closeExhausted((int)$row->id,$exhaustionCode);
            $this->recordDispatchCommand($digest,$operation,(int)$notification->id,'expired',$now,$actor);
            $this->repository->commit();
            return array('attempt_id'=>$attemptId,'notification_id'=>(int)$notification->id,'state'=>'expired','failure_reason_code'=>$exhaustionCode,'exhaustion'=>'window','created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return array('attempt_id'=>$attemptId,'state'=>(string)$winner->result_state,'created'=>false,'idempotent'=>true);
            throw $e;
        }
    }

    /**
     * §6.6/§9 — release a held lease without a send.
     *
     * The release is a **non-terminal** closure bounded exactly like every other one: it re-arms only while
     * an attempt remains **and** the §9 clamp leaves a usable window, and otherwise exhausts through the same
     * ceiling-first two gates. It therefore closes through the shared `record_outcome` path — never as a
     * bare `abandoned` row that a protected read would refuse as an undeclared non-terminal closure — and an
     * attempt that was never a live lease is refused by the same source-state guard.
     */
    public function releaseLease(int $attemptId,array $input,string $key):array{return $this->recordOutcome($attemptId,array_merge($input,array('failure_class'=>'retryable','reason_code'=>(string)($input['reason_code']??'released'))),$key,'release_lease');}

    /**
     * An operator/integrity release of a stuck lease. The release is a **non-terminal** closure, so it can
     * never borrow a terminal-class reason code, and it follows the same ceiling-first two-gate rule.
     */
    public function abandonLease(int $attemptId,array $input,string $key):array{return $this->recordOutcome($attemptId,array_merge($input,array('failure_class'=>'retryable','reason_code'=>(string)($input['reason_code']??'abandoned'))),$key,'abandon_lease');}

    /**
     * §9 — recover every expired lease from its persisted attempt row.
     *
     * The closure instant is the persisted `lease_expires_at`, never a fresh clock read, so a crash between
     * persisting the schedule and re-arming the row cannot change the instant and a second pass replays the
     * persisted values without double-counting the ceiling.
     */
    public function recoverExpiredLeases(array $input,string $key):array{
        NotificationSupport::requireCapability(self::CAPABILITY);
        $actor=NotificationSupport::actor();
        $evidence=NotificationSupport::evidence($input);
        $now=NotificationSupport::now();
        $recovered=0;$closed=0;
        foreach($this->attempts->expiredLeases($now) as $attempt){
            $attemptId=(int)$attempt->id;
            // §10: the same fixed order every other path uses — the aggregate, then its outbox row, then the
            // attempt row — so recovery cannot deadlock against a concurrent hand-off or closure.
            $attempt=$this->attempts->find($attemptId);
            if(!$attempt||$attempt->finished_at!==null)continue;
            $notification=$this->repository->find((int)$attempt->notification_id,true);
            if(!$notification)continue;
            $row=$this->outbox->row((int)$attempt->outbox_id,true);
            if(!$row)continue;
            $attempt=$this->attempts->find($attemptId,true);
            if(!$attempt||$attempt->finished_at!==null)continue;
            $policy=$this->retryPolicy($this->workflows->version((int)$notification->workflow_version_id,true));
            $this->repository->begin();
            try{
                $closure=NotificationRetry::closure($policy,array(
                    'attempt_sequence'=>(int)$attempt->attempt_sequence,'finished_at'=>(string)$attempt->lease_expires_at,
                    'expires_at'=>$notification->expires_at,'notification_key_digest'=>(string)$notification->notification_key_digest,
                    'workflow_version'=>(int)$row->workflow_version,
                ));
                if($closure['re_arm']===true){
                    $retryEvidence=$this->retryEvidence($notification,$attempt,$closure);
                    // A repeated recovery pass over an already-closed attempt replays the persisted values.
                    $this->attempts->closeAttempt($attemptId,array(
                        'state'=>'expired','finished_at'=>$now,'outcome_code'=>NotificationRule::LEASE_EXPIRED_OUTCOME,'failure_class'=>NotificationRule::LEASE_EXPIRED_CLASS,
                        'applied_jitter_bp'=>$closure['applied_jitter_bp'],'base_backoff_seconds'=>$closure['base_backoff_seconds'],
                        'backoff_seconds'=>$closure['backoff_seconds'],'next_available_at'=>$closure['next_available_at'],
                        'updated_at'=>$now,'updated_by'=>$actor,
                    ));
                    $this->attempts->insertEvent($this->attemptEvent($attemptId,'expired','leased','expired',NotificationRule::LEASE_EXPIRED_OUTCOME,$now,$actor));
                    $this->attempts->insertEvent(array(
                        'uid'=>NotificationSupport::uid(),'attempt_id'=>$attemptId,'event_sequence'=>$this->attempts->nextSequence($attemptId),
                        'event_type'=>NotificationRule::RETRY_SCHEDULED_EVENT,'from_state'=>'expired','to_state'=>'expired','reason_code'=>NotificationRule::LEASE_EXPIRED_OUTCOME,
                        'evidence_reference_digest'=>$retryEvidence,
                        'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
                    ));
                    $this->repository->transition((int)$notification->id,(string)$notification->state,array('state'=>'queued','updated_at'=>$now,'updated_by'=>$actor));
                    $this->repository->insertEvent($this->eventRow((int)$notification->id,'queued',(string)$notification->state,'queued',NotificationRule::LEASE_EXPIRED_OUTCOME,$evidence,$now,$actor));
                    // §9: the notification's own history records the same digest-only retry evidence, so the
                    // lease-expiry re-arm is proved on both histories exactly like a port-reported one.
                    $this->repository->insertEvent($this->eventRow((int)$notification->id,NotificationRule::RETRY_SCHEDULED_EVENT,'queued','queued',NotificationRule::LEASE_EXPIRED_OUTCOME,$evidence,$now,$actor,$retryEvidence));
                    $this->outbox->rearm((int)$row->id,(string)$closure['next_available_at']);
                    $this->recordDispatchCommand(NotificationSupport::keyDigest($key.'-'.$attemptId),'recover_expired_leases',(int)$notification->id,'queued',$now,$actor);
                    $this->repository->commit();
                    $recovered++;
                    continue;
                }
                $this->attempts->closeAttempt($attemptId,array('state'=>'expired','finished_at'=>$now,'outcome_code'=>NotificationRule::LEASE_EXPIRED_OUTCOME,'failure_class'=>NotificationRule::LEASE_EXPIRED_CLASS,'updated_at'=>$now,'updated_by'=>$actor));
                $this->attempts->insertEvent($this->attemptEvent($attemptId,'expired','leased','expired',NotificationRule::LEASE_EXPIRED_OUTCOME,$now,$actor));
                // §6.6/§9: the same single-sourced gate→code mapping the closure path uses, so recovery can
                // never report a different code for the same gate.
                $code=NotificationRetry::exhaustionReasonCode($closure);
                if($code===null)throw new \InvalidArgumentException('retry_schedule_divergence');
                $state=$code===NotificationRule::CEILING_EXHAUSTION_CODE?'failed':'expired';
                $this->repository->transition((int)$notification->id,(string)$notification->state,array('state'=>$state,'failure_reason_code'=>$code,'updated_at'=>$now,'updated_by'=>$actor));
                $this->repository->insertEvent($this->eventRow((int)$notification->id,$state,(string)$notification->state,$state,$code,$evidence,$now,$actor));
                $this->outbox->closeExhausted((int)$row->id,$code);
                $this->recordDispatchCommand(NotificationSupport::keyDigest($key.'-'.$attemptId),'recover_expired_leases',(int)$notification->id,$state,$now,$actor);
                $this->repository->commit();
                $closed++;
            }catch(\Throwable $e){
                $this->repository->rollback();
                throw $e;
            }
        }
        return array('recovered'=>$recovered,'closed'=>$closed);
    }

    private function retryPolicy(?object $version):array{
        if(!$version)throw new \InvalidArgumentException('unroutable_intent');
        $rows=$this->ruleRows($version,'retry');
        return NotificationRetry::validatePolicy($rows);
    }
    /**
     * §7.3/§9/§10 — prove the aggregate under its own locks before any eligibility verdict or lease.
     *
     * The frozen composition and retry policy, the persisted tier-F announced instant, the frozen logical
     * identity and the attempt history are re-derived while the notification and its outbox row are locked,
     * and the shared aggregate verification runs over them. It returns the policy the closure arithmetic
     * uses, so the claim and the closures are decided from one proved read.
     *
     * @throws \RuntimeException the shared aggregate verification code when the row disagrees with itself.
     */
    private function aggregateGuard(object $notification,?object $version,?object $row):array{
        $this->requireFrozenVersion($version);
        $tier=NotificationRule::intentTier((string)$notification->intent_key);
        $composition=NotificationSchedule::validateComposition($this->ruleRows($version,'schedule'),$tier);
        $policy=NotificationRetry::validatePolicy($this->ruleRows($version,'retry'));
        $binding=NotificationRule::requiredBinding((string)$notification->intent_key);
        $subjectInstant=$tier==='F'
            ?NotificationIntegrity::persistedInstant((string)$binding['aggregate'],(int)$notification->subject_aggregate_id,$binding['instant'])
            :null;
        $workflow=$this->workflows->workflow((int)$version->workflow_id);
        NotificationIntegrity::aggregateIntegrity(
            $notification,$composition,$policy,$subjectInstant,$row,
            $this->attempts->attemptsFor((int)$notification->id),(int)$version->version_number,
            array(
                'workflow_key'=>$workflow?(string)$workflow->workflow_key:'',
                'workflow_version'=>(int)$version->version_number,
                'intent_key'=>(string)$version->intent_key,'audience'=>(string)$version->audience,
            )
        );
        return array('composition'=>$composition,'policy'=>$policy,'subject_instant'=>$subjectInstant);
    }
    /** The frozen rule set of one version, proved against its stored digest before any decision uses it. */
    private function requireFrozenVersion(?object $version):object{
        if(!$version)throw new \InvalidArgumentException('unroutable_intent');
        $rows=array();
        foreach($this->workflows->rulesFor((int)$version->id) as $row){
            $rows[]=array(
                'rule_kind'=>(string)$row->rule_kind,'rule_code'=>(string)$row->rule_code,'ordinal'=>(int)$row->ordinal,
                'parameter_a'=>$row->parameter_a,'parameter_b'=>$row->parameter_b,'parameter_c'=>$row->parameter_c,'parameter_d'=>$row->parameter_d,
            );
        }
        NotificationIntegrity::versionIntegrity($version,$rows);
        return $version;
    }
    /** One rule kind of one version, in the canonical decode shape the validators consume. */
    private function ruleRows(object $version,string $kind):array{
        $rows=array();
        foreach($this->workflows->rulesFor((int)$version->id) as $row){
            if((string)$row->rule_kind!==$kind)continue;
            $rows[]=array('rule_kind'=>(string)$row->rule_kind,'rule_code'=>(string)$row->rule_code,'ordinal'=>(int)$row->ordinal,'parameter_a'=>$row->parameter_a,'parameter_b'=>$row->parameter_b,'parameter_c'=>$row->parameter_c,'parameter_d'=>$row->parameter_d);
        }
        return $rows;
    }
    private function eligibility(?object $version):array{
        if(!$version)throw new \InvalidArgumentException('unroutable_intent');
        return NotificationEligibility::validateRequiredSet((string)$version->intent_key,(string)$version->audience,(string)$version->recipient_kind,$this->ruleRows($version,'eligibility'));
    }
    /**
     * §6.2/§6.8 — resolve every required fact through its own read port and evaluate the frozen set.
     *
     * The bound evidence is read from the subject's immutable history bounded by the notification's own
     * `observed_at`, the recipient facts come from the identity/consent projection, and the suppression
     * check is the active register match for the recipient digest and the workflow's purpose. No fact
     * defaults to eligible: an unreadable source refuses exactly as its rule declares.
     */
    private function guardEligibility(object $notification,?object $version,string $now):array{
        $eligibility=$this->eligibility($version);
        $intent=(string)$notification->intent_key;
        $binding=NotificationRule::requiredBinding($intent);
        $subjectId=(int)$notification->subject_aggregate_id;
        $subject=$this->subjects?->subject((string)$binding['aggregate'],$subjectId,$binding['instant']);
        $recipient=$this->recipients?->recipient((string)$notification->audience,(string)$notification->recipient_kind,$subjectId);
        $bound=NotificationEligibility::boundEvidence($intent,$subjectId,(string)$notification->observed_at);
        $suppression=$this->suppressions->active((string)$notification->recipient_digest,$intent,$now);
        $facts=array(
            'subject_exists'=>(bool)($subject['exists']??false),'bound_evidence'=>$bound,
            'recipient_resolvable'=>(bool)($recipient['resolvable']??false),
            'recipient_opted_in'=>(bool)($recipient['opted_in']??false),
            'guardian_authority_present'=>(bool)($recipient['guardian_authority_present']??false),
            'suppressed'=>$suppression!==null,'scheduled_for'=>(string)$notification->scheduled_for,
            'subject_instant'=>NotificationRule::intentTier($intent)==='F'?($subject['instant']??null):null,
        );
        return array(
            'outcome'=>NotificationEligibility::evaluate($eligibility,$facts),'facts'=>$facts,
            'bound_digest'=>$bound!==null?NotificationEligibility::boundEvidenceDigest($intent,$subjectId,$bound):null,
            'suppression_id'=>$suppression!==null?(int)$suppression->id:null,
        );
    }
    /**
     * A claim refused on eligibility closes the queued notification in its controlled terminal state without
     * a lease, records the digest-only command for replay, closes the outbox row in place and commits.
     */
    private function refuseClaim(object $notification,object $row,array $guard,array $evidence,string $digest,string $now,int $actor):array{
        $notificationId=(int)$notification->id;
        $code=(string)$guard['outcome']['code'];
        // §6.5/§6.8: the refusal path, the abort closure and the verifier share one state mapping.
        $state=NotificationRule::controlledState($code);
        $fields=array('state'=>$state,'failure_reason_code'=>$code,'updated_at'=>$now,'updated_by'=>$actor);
        if($code==='suppressed'&&$guard['suppression_id']!==null)$fields['suppression_id']=(int)$guard['suppression_id'];
        $this->repository->transition($notificationId,'queued',$fields);
        $this->repository->insertEvent($this->eventRow($notificationId,$state,'queued',$state,$code,$evidence,$now,$actor,$guard['bound_digest']));
        $this->outbox->closeAny((int)$row->id,$state,$code);
        $this->repository->insertCommand(array(
            'uid'=>NotificationSupport::uid(),'command_domain'=>NotificationRule::DOMAIN,'operation'=>'claim_lease',
            'command_key_digest'=>$digest,'command_payload_digest'=>NotificationSupport::payloadDigest(array('domain'=>NotificationRule::DOMAIN,'operation'=>'claim_lease','notification_id'=>$notificationId,'result_state'=>$state)),
            'notification_id'=>$notificationId,'result_state'=>$state,'result_id'=>$notificationId,'created_at'=>$now,'created_by'=>$actor,
        ));
        $this->repository->commit();
        return array('claimed'=>false,'reason'=>$code,'notification_id'=>$notificationId,'state'=>$state);
    }
    /**
     * §6.5/§9 — expire every queued notification whose mandatory window has already closed before a claim.
     *
     * The transition and the outbox closure are one transaction, so the overdue row is never claimable and
     * never sent outside its window. The aggregate and then its outbox row are locked in the S lock order, so
     * a competing pass simply observes the terminal state and does nothing.
     */
    private function expireOverdue(array $evidence,string $now,int $actor):void{
        foreach($this->outbox->overdue($now,50) as $row){
            $this->repository->begin();
            try{
                $notification=$this->repository->find((int)$row->notification_id,true);
                if(!$notification||(string)$notification->state!=='queued'||(string)$notification->expires_at>$now){$this->repository->rollback();continue;}
                $locked=$this->outbox->row((int)$row->id,true);
                if(!$locked||(string)$locked->status!=='queued'||$locked->leased_at!==null){$this->repository->rollback();continue;}
                $code=NotificationRule::WINDOW_EXHAUSTION_CODE;
                $this->repository->transition((int)$notification->id,'queued',array('state'=>'expired','failure_reason_code'=>$code,'updated_at'=>$now,'updated_by'=>$actor));
                $this->repository->insertEvent($this->eventRow((int)$notification->id,'expired','queued','expired',$code,$evidence,$now,$actor));
                $this->outbox->expireOverdue((int)$row->id,$code);
                $this->repository->commit();
            }catch(\Throwable $e){
                $this->repository->rollback();
                throw $e;
            }
        }
    }
    /**
     * §6.4/§13 — the authoritative, channel-neutral command, a strict allowlist built from the persisted
     * aggregate and its proved frozen snapshot.
     *
     * Exactly the six frozen fields cross the port boundary; nothing the caller supplied is merged in, so
     * no raw payload, provider-specific field or operator envelope can reach `NotificationTransportPort`.
     */
    private function authorisedCommand(object $notification,object $attempt):array{
        $snapshotId=(int)($notification->rendered_snapshot_id??0);
        if($snapshotId<1)throw new \RuntimeException('template_variable_mismatch');
        $snapshot=$this->templates->snapshot($snapshotId);
        if(!$snapshot||(int)$snapshot->template_version_id!==(int)$notification->template_version_id)throw new \RuntimeException('template_variable_mismatch');
        $parameters=NotificationSupport::decryptEnvelope($snapshot->rendered_params_envelope,$snapshot->cipher_version===null?null:(string)$snapshot->cipher_version);
        if($parameters===null)throw new \RuntimeException('envelope_decrypt_failure');
        // §6.4: the snapshot must first prove its frozen variable contract, its declared code set and a
        // reproducing key-ordered parameter digest, or nothing crosses the boundary.
        $version=$this->templates->version((int)$notification->template_version_id);
        if(!$version)throw new \RuntimeException('template_variable_mismatch');
        $proof=NotificationIntegrity::renderedSnapshot($version,$snapshot,$parameters);
        return array(
            'notification_key_digest'=>(string)$notification->notification_key_digest,
            'attempt_sequence'=>(int)$attempt->attempt_sequence,
            'audience'=>(string)$notification->audience,
            'template_version_id'=>(int)$notification->template_version_id,
            'variable_codes'=>$proof['contract']['variable_codes'],
            'parameters'=>$parameters,
        );
    }
    private function attemptEvent(int $attemptId,string $eventType,?string $from,string $to,string $reason,string $now,int $actor):array{
        return array(
            'uid'=>NotificationSupport::uid(),'attempt_id'=>$attemptId,'event_sequence'=>$this->attempts->nextSequence($attemptId),
            'event_type'=>$eventType,'from_state'=>$from,'to_state'=>$to,'reason_code'=>$reason,
            'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
        );
    }
    private function eventRow(int $notificationId,string $eventType,?string $from,string $to,?string $reason,array $evidence,string $now,int $actor,?string $evidenceDigest=null):array{
        return array(
            'uid'=>NotificationSupport::uid(),'notification_id'=>$notificationId,'event_sequence'=>$this->repository->nextSequence($notificationId),
            'event_type'=>$eventType,'from_state'=>$from,'to_state'=>$to,'reason_code'=>$reason,'evidence_channel'=>$evidence['channel'],
            'evidence_reference_digest'=>$evidenceDigest??$evidence['digest'],'evidence_at'=>$evidence['at'],
            'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
        );
    }
    /**
     * §9 — the one digest-only retry evidence of a re-arm, shared by the closing attempt's audit companion
     * and the notification's own `retry_scheduled` row so both histories prove the identical persisted
     * schedule: the immutable notification identity, the attempt sequence and the persisted jittered
     * back-off are its only inputs.
     */
    private function retryEvidence(object $notification,object $attempt,array $closure):string{
        return NotificationSupport::retryEvidenceDigest((string)$notification->notification_key_digest,(int)$attempt->attempt_sequence,(int)$closure['applied_jitter_bp'],(int)$closure['backoff_seconds']);
    }
    private function recordDispatchCommand(string $digest,string $operation,int $notificationId,string $resultState,string $now,int $actor):void{
        $this->repository->insertCommand(array(
            'uid'=>NotificationSupport::uid(),'command_domain'=>NotificationRule::DOMAIN,'operation'=>$operation,
            'command_key_digest'=>$digest,'command_payload_digest'=>NotificationSupport::payloadDigest(array('domain'=>NotificationRule::DOMAIN,'operation'=>$operation,'notification_id'=>$notificationId,'result_state'=>$resultState)),
            'notification_id'=>$notificationId,'result_state'=>$resultState,'result_id'=>$notificationId,'created_at'=>$now,'created_by'=>$actor,
        ));
    }
}
