<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationAttemptRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationOutboxRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationSuppressionRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationTemplateRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationWorkflowRepository;

/**
 * §6.3–§6.5/§8.1 — the S notification authority: observe an intent, derive the schedule, enqueue, defer
 * and close.
 *
 * The aggregate row is the §10 serialisation root, so every mutation locks it first and then its outbox
 * row. Cross-module reads (subject history, the persisted tier-F instant, recipient facts) happen outside
 * the S transaction and are revalidated under the guard when the write depends on them. No business fact
 * is ever written here: every transition is a post-commit action against an already-durable fact.
 */
final class NotificationService {
    private const CAPABILITY='dzn_manage_notifications';
    public function __construct(
        private ?NotificationRepository $repository=null,
        private ?NotificationWorkflowRepository $workflows=null,
        private ?NotificationOutboxRepository $outbox=null,
        private ?NotificationAttemptRepository $attempts=null,
        private ?NotificationTemplateRepository $templates=null,
        private ?NotificationSuppressionRepository $suppressions=null,
        private ?NotificationRecipientReadPort $recipients=null,
        private ?NotificationSubjectReadPort $subjects=null
    ){
        $this->repository??=new NotificationRepository();
        $this->workflows??=new NotificationWorkflowRepository();
        $this->outbox??=new NotificationOutboxRepository();
        $this->attempts??=new NotificationAttemptRepository();
        $this->templates??=new NotificationTemplateRepository();
        $this->suppressions??=new NotificationSuppressionRepository();
    }

    /**
     * Observe one pending R2 intent: resolve its single active workflow version, freeze the §6.2.3 bound
     * evidence, persist `observed_at` and the resolved single shared `timezone`, derive the §6.3 schedule
     * and mirror it to the outbox row.
     *
     * Two outcomes close the observation terminally instead of waiting: an unavailable tier-F instant
     * (`tier_f_instant_unavailable`) and an unresolvable timezone basis (`schedule_timezone_unresolved`).
     * Neither derives an instant, and neither leaves a `pending` row behind.
     */
    public function observeIntent(int $outboxId,array $input,string $key):array{
        NotificationSupport::requireCapability(self::CAPABILITY);
        $actor=NotificationSupport::actor();
        $evidence=NotificationSupport::evidence($input);
        $digest=NotificationSupport::keyDigest($key);
        $row=$this->outbox->row($outboxId);
        if(!$row)throw new \RuntimeException('notification_not_found');
        if($row->invitation_id!==null||$row->generation_id!==null)throw new \InvalidArgumentException('unregistered_intent');
        $intent=(string)$row->event_type;
        if(!NotificationRule::registeredIntent($intent))throw new \InvalidArgumentException('unregistered_intent');
        if($row->notification_id!==null)return $this->replayObservedIntent((int)$row->notification_id,$outboxId);
        $version=$this->workflows->activeVersionForIntent($intent);
        if(!$version)throw new \InvalidArgumentException('unroutable_intent');
        $integrity=$this->validatedVersion($version,$this->workflows->rulesFor((int)$version->id));
        $composition=$integrity['composition'];$retry=$integrity['retry'];
        // §6.4: the frozen template identity is resolved to an exact active `template_version_id` here, so
        // the observation can freeze the rendered-parameter snapshot and bind it to the aggregate.
        $templateVersion=$this->resolveTemplateVersion($version);
        $tier=NotificationRule::intentTier($intent);
        $observedAt=trim((string)($input['observed_at']??''))?:NotificationSupport::now();
        if(NotificationSupport::seconds($observedAt)===null)throw new \InvalidArgumentException('schedule_derivation_divergence');
        $binding=NotificationRule::requiredBinding($intent);
        $subjectId=(int)$row->aggregate_id;
        $subject=$this->subjects?->subject($binding['aggregate'],$subjectId,$binding['instant']);
        if(!$subject||empty($subject['exists']))throw new \InvalidArgumentException('eligibility_unresolved');
        $recipient=$this->recipients?->recipient((string)$version->audience,(string)$version->recipient_kind,$subjectId);
        if(!$recipient||empty($recipient['resolvable']))throw new \InvalidArgumentException('recipient_unresolved');
        // §6.2.3: the bound evidence is decided from the subject's immutable append-only history, bounded by
        // this notification's own observed instant, and is digest-frozen with the derived schedule.
        $bound=NotificationEligibility::boundEvidence($intent,$subjectId,$observedAt);
        $boundDigest=$bound!==null?NotificationEligibility::boundEvidenceDigest($intent,$subjectId,$bound):null;
        $subjectInstant=$tier==='F'?($subject['instant']??null):null;
        if($tier==='F'&&($subjectInstant===null||NotificationSupport::seconds((string)$subjectInstant)===null)){
            return $this->closeTerminalObservation($outboxId,$row,$version,$intent,$observedAt,'','tier_f_instant_unavailable',$evidence,$digest,$actor,$input);
        }
        $timezone=$this->resolveBasis($composition,$recipient,$subject);
        if($composition['timezone_basis']!==null&&$timezone===null){
            return $this->closeTerminalObservation($outboxId,$row,$version,$intent,$observedAt,'','schedule_timezone_unresolved',$evidence,$digest,$actor,$input);
        }
        $timezone=(string)($timezone??'');
        $anchor=NotificationSchedule::anchor($composition,$observedAt,$subjectInstant);
        $bucket=NotificationSupport::coalesceBucket($anchor,$composition['coalesce']!==null?(int)$composition['coalesce']['coalesce_window_minutes']:null);
        $workflowKey=(string)$integrity['workflow_key'];
        $notificationKey=NotificationSupport::notificationKeyDigest($workflowKey,(int)$version->version_number,$intent,(string)$version->audience,(string)$recipient['recipient_digest'],$binding['aggregate'],$subjectId,$bucket);
        $tierFDigest=$tier==='F'?NotificationSupport::tierFEvidenceDigest($binding['aggregate'],$subjectId,(string)$binding['instant'],(string)$subjectInstant):null;
        $this->repository->begin();
        try{
            // Revalidate the frozen rule set under the guard: an append between the read and the write can
            // never take effect (it either hit the draft-only guard or diverges from the frozen digest).
            $guarded=$this->workflows->version((int)$version->id,true);
            $this->validatedVersion($guarded,$this->workflows->rulesFor((int)$guarded->id));
            $existing=$this->repository->byKeyDigest($notificationKey,true);
            if($existing){$this->repository->commit();return $this->describeNotification($existing,$outboxId);}
            $now=NotificationSupport::now();
            $notificationId=$this->repository->insertNotification($this->notificationFields(
                $notificationKey,$version,$intent,$recipient,$timezone,$observedAt,$binding,$subjectId,
                (string)($subject['subject_reference_digest']??$recipient['recipient_digest']),$outboxId,$input['priority']??null,$now,$actor,(int)$templateVersion->id
            ));
            $this->repository->insertEvent($this->eventRow($notificationId,1,'observed',null,'pending','observed',$evidence,$now,$actor,$boundDigest??$tierFDigest));
            // The immutable rendered snapshot is frozen in the same observation transaction, and both
            // references are persisted on the aggregate, so dispatch always has the encrypted parameters.
            $snapshotId=$this->freezeRenderedSnapshot($notificationId,$templateVersion,$input,$timezone,$now,$actor);
            $this->repository->attachRenderedSnapshot($notificationId,$snapshotId);
            // The write-boundary audit hook: a listener that throws here rolls the whole observation back,
            // which is exactly the failure case the S failure suite injects.
            NotificationSupport::hook('dzn_phase_2a2s_after_notification_event_insert','observe_intent',$notificationId);
            $this->outbox->enrichIdentity($outboxId,$this->identityFields($notificationId,$workflowKey,$version,$intent,$input['priority']??null));
            try{
                $derived=NotificationSchedule::derive($composition,array(
                    'observed_at'=>$observedAt,'subject_instant'=>$subjectInstant,'timezone'=>$timezone,'deferral_count'=>0,
                ));
            }catch(\InvalidArgumentException $scheduleError){
                if($scheduleError->getMessage()!=='eligibility_expired')throw $scheduleError;
                return $this->terminalFromPending($notificationId,$outboxId,$evidence,$digest,'observe_intent','eligibility_expired',$now,$actor);
            }
            $this->repository->transition($notificationId,'pending',array(
                'state'=>'scheduled','schedule_anchor_at'=>$derived['schedule_anchor_at'],'scheduled_for'=>$derived['scheduled_for'],
                'expires_at'=>$derived['expires_at'],'deferral_count'=>$derived['deferral_count'],'updated_at'=>$now,'updated_by'=>$actor,
            ));
            $this->repository->insertEvent($this->eventRow($notificationId,2,'scheduled','pending','scheduled','scheduled',$evidence,$now,$actor,$boundDigest??$tierFDigest));
            NotificationSupport::hook('dzn_phase_2a2s_after_notification_event_insert','schedule',$notificationId);
            $this->outbox->mirrorSchedule($outboxId,array('scheduled_for'=>$derived['scheduled_for'],'expires_at'=>$derived['expires_at'],'deferral_count'=>$derived['deferral_count'],'priority'=>$input['priority']??null));
            $this->recordCommand($digest,'observe_intent',$notificationId,'scheduled',$now,$actor);
            $this->repository->commit();
            return array(
                'notification_id'=>$notificationId,'outbox_id'=>$outboxId,'state'=>'scheduled','intent_key'=>$intent,'tier'=>$tier,
                'schedule_anchor_at'=>$derived['schedule_anchor_at'],'scheduled_for'=>$derived['scheduled_for'],
                'expires_at'=>$derived['expires_at'],'deferral_count'=>0,'coalesce_bucket'=>$bucket,'retry_policy'=>$retry,'created'=>true,
            );
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replayCommand($winner,$outboxId);
            if($this->repository->duplicate($e)==='notification_key_digest'){
                $existing=$this->repository->byKeyDigest($notificationKey);
                if($existing)return $this->describeNotification($existing,$outboxId);
            }
            throw $e;
        }
    }

    /** §8.1 `schedule`: derive and persist the schedule of a `pending` observation. */
    public function schedule(int $notificationId,array $input,string $key):array{
        NotificationSupport::requireCapability(self::CAPABILITY);
        $actor=NotificationSupport::actor();
        $evidence=NotificationSupport::evidence($input);
        $digest=NotificationSupport::keyDigest($key);
        $notification=$this->repository->find($notificationId,true);
        if(!$notification)throw new \RuntimeException('notification_not_found');
        if((string)$notification->state!=='pending')throw new \InvalidArgumentException('invalid_notification_state');
        $version=$this->workflows->version((int)$notification->workflow_version_id,true);
        $integrity=$this->validatedVersion($version,$this->workflows->rulesFor((int)$version->id));
        $tier=NotificationRule::intentTier((string)$notification->intent_key);
        $binding=NotificationRule::requiredBinding((string)$notification->intent_key);
        $subject=$this->subjects?->subject($binding['aggregate'],(int)$notification->subject_aggregate_id,$binding['instant']);
        $subjectInstant=$tier==='F'?($subject['instant']??null):null;
        if($tier==='F'&&$subjectInstant===null)throw new \InvalidArgumentException('tier_f_instant_unavailable');
        $derived=NotificationSchedule::derive($integrity['composition'],array(
            'observed_at'=>(string)$notification->observed_at,'subject_instant'=>$subjectInstant,
            'timezone'=>(string)$notification->timezone,'deferral_count'=>(int)$notification->deferral_count,
        ));
        $this->repository->begin();
        try{
            $row=$this->outbox->forNotification($notificationId,true);
            if(!$row)throw new \RuntimeException('notification_outbox_row_required');
            $now=NotificationSupport::now();
            $this->repository->transition($notificationId,'pending',array(
                'state'=>'scheduled','schedule_anchor_at'=>$derived['schedule_anchor_at'],'scheduled_for'=>$derived['scheduled_for'],
                'expires_at'=>$derived['expires_at'],'deferral_count'=>$derived['deferral_count'],'updated_at'=>$now,'updated_by'=>$actor,
            ));
            $this->repository->insertEvent($this->eventRow($notificationId,$this->repository->nextSequence($notificationId),'scheduled','pending','scheduled','scheduled',$evidence,$now,$actor,null));
            $this->outbox->mirrorSchedule((int)$row->id,array('scheduled_for'=>$derived['scheduled_for'],'expires_at'=>$derived['expires_at'],'deferral_count'=>$derived['deferral_count']));
            $this->recordCommand($digest,'schedule',$notificationId,'scheduled',$now,$actor);
            $this->repository->commit();
            return array('notification_id'=>$notificationId,'state'=>'scheduled','scheduled_for'=>$derived['scheduled_for'],'expires_at'=>$derived['expires_at'],'created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replayCommand($winner,null);
            throw $e;
        }
    }

    /**
     * §6.3(e) — one bounded pre-dispatch deferral: the persisted count moves by exactly one and
     * `scheduled_for` is re-derived from the frozen base rather than shifted from its previous value.
     *
     * A refusal is terminal rather than a deferral into an undispatchable state: crossing the frozen
     * window closes `expired`/`retry_window_exhausted`, breaching the tier-F strict-before postcondition
     * closes `expired`/`eligibility_expired`, and losing the declared lead-time slack closes
     * `expired`/`lead_time_insufficient`.
     */
    public function defer(int $notificationId,array $input,string $key):array{
        NotificationSupport::requireCapability(self::CAPABILITY);
        $actor=NotificationSupport::actor();
        $evidence=NotificationSupport::evidence($input);
        $digest=NotificationSupport::keyDigest($key);
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$result=$this->replayCommand($winner,null);$this->repository->commit();return $result;}
            $notification=$this->repository->find($notificationId,true);
            if(!$notification)throw new \RuntimeException('notification_not_found');
            if(!in_array((string)$notification->state,array('scheduled','queued'),true))throw new \InvalidArgumentException('invalid_notification_state');
            $version=$this->workflows->version((int)$notification->workflow_version_id,true);
            $integrity=$this->validatedVersion($version,$this->workflows->rulesFor((int)$version->id));
            $composition=$integrity['composition'];
            $tier=NotificationRule::intentTier((string)$notification->intent_key);
            $subjectInstant=null;
            if($tier==='F'){
                $binding=NotificationRule::requiredBinding((string)$notification->intent_key);
                $subject=$this->subjects?->subject($binding['aggregate'],(int)$notification->subject_aggregate_id,$binding['instant']);
                $subjectInstant=$subject['instant']??null;
                if($subjectInstant===null)throw new \InvalidArgumentException('tier_f_instant_unavailable');
            }
            $lead=$integrity['eligibility'][NotificationRule::LEAD_TIME_RULE]['parameter_c']??null;
            $row=$this->outbox->forNotification($notificationId,true);
            if(!$row)throw new \RuntimeException('notification_outbox_row_required');
            $now=NotificationSupport::now();
            $base=NotificationSchedule::base($composition,(string)$notification->schedule_anchor_at,(string)$notification->timezone);
            try{
                $moved=NotificationSchedule::defer($composition,array(
                    'derivation_base_at'=>$base,'deferral_count'=>(int)$notification->deferral_count,'expires_at'=>(string)$notification->expires_at,
                ),$subjectInstant,$lead===null?null:(int)$lead);
            }catch(\InvalidArgumentException $refusal){
                $code=$refusal->getMessage();
                if(!in_array($code,array('retry_window_exhausted','eligibility_expired','lead_time_insufficient'),true))throw $refusal;
                $this->repository->transition($notificationId,(string)$notification->state,array('state'=>'expired','failure_reason_code'=>$code,'updated_at'=>$now,'updated_by'=>$actor));
                $this->repository->insertEvent($this->eventRow($notificationId,$this->repository->nextSequence($notificationId),'expired',(string)$notification->state,'expired',$code,$evidence,$now,$actor,null));
                $this->outbox->closeAny((int)$row->id,'expired',$code);
                $this->recordCommand($digest,'defer',$notificationId,'expired',$now,$actor);
                $this->repository->commit();
                return array('notification_id'=>$notificationId,'state'=>'expired','failure_reason_code'=>$code,'created'=>true);
            }
            $this->repository->updateNotification($notificationId,(int)$notification->notification_version,array('scheduled_for'=>$moved['scheduled_for'],'deferral_count'=>$moved['deferral_count']),$now,$actor);
            $this->repository->insertEvent($this->eventRow($notificationId,$this->repository->nextSequence($notificationId),'deferred',(string)$notification->state,(string)$notification->state,'deferred',$evidence,$now,$actor,null));
            $this->outbox->mirrorDeferral((int)$row->id,$moved['scheduled_for'],$moved['deferral_count']);
            $this->recordCommand($digest,'defer',$notificationId,(string)$notification->state,$now,$actor);
            $this->repository->commit();
            return array('notification_id'=>$notificationId,'state'=>(string)$notification->state,'scheduled_for'=>$moved['scheduled_for'],'deferral_count'=>$moved['deferral_count'],'created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replayCommand($winner,null);
            throw $e;
        }
    }

    /**
     * §9 — enqueue a scheduled notification: re-evaluate the complete frozen required set and the
     * suppression check, then move it into the one claimable state, or into its controlled terminal state.
     *
     * A refusal that is neither a suppression nor an instant-based expiry leaves the row `scheduled` —
     * visible and reported by code — rather than dispatching it or losing it.
     */
    public function enqueue(int $notificationId,array $input,string $key):array{
        NotificationSupport::requireCapability(self::CAPABILITY);
        $actor=NotificationSupport::actor();
        $evidence=NotificationSupport::evidence($input);
        $digest=NotificationSupport::keyDigest($key);
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$result=$this->replayCommand($winner,null);$this->repository->commit();return $result;}
            $notification=$this->repository->find($notificationId,true);
            if(!$notification)throw new \RuntimeException('notification_not_found');
            if((string)$notification->state!=='scheduled')throw new \InvalidArgumentException('invalid_notification_state');
            $version=$this->workflows->version((int)$notification->workflow_version_id,true);
            $integrity=$this->validatedVersion($version,$this->workflows->rulesFor((int)$version->id));
            $row=$this->outbox->forNotification($notificationId,true);
            if(!$row||$row->scheduled_for===null||$row->expires_at===null)throw new \RuntimeException('schedule_derivation_divergence');
            $tier=NotificationRule::intentTier((string)$notification->intent_key);
            $binding=NotificationRule::requiredBinding((string)$notification->intent_key);
            $subject=$this->subjects?->subject($binding['aggregate'],(int)$notification->subject_aggregate_id,$binding['instant']);
            $recipient=$this->recipients?->recipient((string)$version->audience,(string)$version->recipient_kind,(int)$notification->subject_aggregate_id);
            $bound=NotificationEligibility::boundEvidence((string)$notification->intent_key,(int)$notification->subject_aggregate_id,(string)$notification->observed_at);
            $now=NotificationSupport::now();
            $suppression=$this->suppressions->active((string)$notification->recipient_digest,(string)$version->intent_key,$now);
            $facts=array(
                'subject_exists'=>(bool)($subject['exists']??false),'bound_evidence'=>$bound,
                'recipient_resolvable'=>(bool)($recipient['resolvable']??false),
                'recipient_opted_in'=>(bool)($recipient['opted_in']??false),
                'guardian_authority_present'=>(bool)($recipient['guardian_authority_present']??false),
                'suppressed'=>$suppression!==null,'scheduled_for'=>(string)$notification->scheduled_for,
                'subject_instant'=>$tier==='F'?($subject['instant']??null):null,
            );
            $outcome=NotificationEligibility::evaluate($integrity['eligibility'],$facts);
            if($outcome['outcome']!=='pass'){
                $code=(string)$outcome['code'];
                if($code==='suppressed')return $this->closeFromScheduled($notification,$row,$evidence,$digest,'suppressed','suppressed',$now,$actor,array('suppression_id'=>(int)$suppression->id));
                if(in_array($code,array('eligibility_expired','lead_time_insufficient'),true))return $this->closeFromScheduled($notification,$row,$evidence,$digest,'expired',$code,$now,$actor,array());
                $this->recordCommand($digest,'enqueue',$notificationId,'scheduled',$now,$actor);
                $this->repository->commit();
                return array('notification_id'=>$notificationId,'state'=>'scheduled','outcome'=>$code,'created'=>true);
            }
            $this->repository->transition($notificationId,'scheduled',array('state'=>'queued','updated_at'=>$now,'updated_by'=>$actor));
            $this->repository->insertEvent($this->eventRow($notificationId,$this->repository->nextSequence($notificationId),'queued','scheduled','queued','queued',$evidence,$now,$actor,$bound!==null?NotificationEligibility::boundEvidenceDigest((string)$notification->intent_key,(int)$notification->subject_aggregate_id,$bound):null));
            $this->outbox->queue((int)$row->id);
            $this->recordCommand($digest,'enqueue',$notificationId,'queued',$now,$actor);
            $this->repository->commit();
            return array('notification_id'=>$notificationId,'state'=>'queued','created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replayCommand($winner,null);
            throw $e;
        }
    }

    /** Cancel a non-terminal notification by explicit command; nothing is sent and nothing is reopened. */
    public function cancel(int $notificationId,array $input,string $key):array{return $this->closeByCommand($notificationId,$input,$key,'cancel','cancelled',NotificationSupport::reason($input,'reason_code'));}
    /** Expire a non-terminal notification by explicit command. */
    public function expire(int $notificationId,array $input,string $key):array{return $this->closeByCommand($notificationId,$input,$key,'expire','expired',NotificationSupport::reason($input,'reason_code'));}
    /** Suppress a non-terminal notification by explicit command (the §6.8 register is its own service). */
    public function suppress(int $notificationId,array $input,string $key):array{return $this->closeByCommand($notificationId,$input,$key,'suppress','suppressed',NotificationSupport::reason($input,'reason_code'));}

    /** A re-issue is a *fresh* notification for a new subject instant; a terminal row is never mutated. */
    public function reissue(int $notificationId,array $input,string $key):array{
        NotificationSupport::requireCapability(self::CAPABILITY);
        $notification=$this->repository->find($notificationId);
        if(!$notification)throw new \RuntimeException('notification_not_found');
        if(!NotificationRule::terminalNotificationState((string)$notification->state))throw new \InvalidArgumentException('invalid_notification_state');
        // The owning phase re-publishes the intent; S only observes that fresh pending row. The terminal row
        // is never mutated and the new notification carries its own identity.
        $fresh=$this->outbox->row((int)($input['outbox_id']??0));
        if(!$fresh||$fresh->notification_id!==null)throw new \InvalidArgumentException('invalid_notification_state');
        return $this->observeIntent((int)$fresh->id,$input,$key);
    }

    private function closeFromScheduled(object $notification,object $row,array $evidence,string $digest,string $state,string $reason,string $now,int $actor,array $extra):array{
        $this->repository->transition((int)$notification->id,(string)$notification->state,array_merge(array('state'=>$state,'failure_reason_code'=>$reason,'updated_at'=>$now,'updated_by'=>$actor),$extra));
        $this->repository->insertEvent($this->eventRow((int)$notification->id,$this->repository->nextSequence((int)$notification->id),$state,(string)$notification->state,$state,$reason,$evidence,$now,$actor,null));
        $this->outbox->closeAny((int)$row->id,$state,$reason);
        $this->recordCommand($digest,'enqueue',(int)$notification->id,$state,$now,$actor);
        $this->repository->commit();
        return array('notification_id'=>(int)$notification->id,'state'=>$state,'failure_reason_code'=>$reason,'created'=>true);
    }
    private function closeByCommand(int $notificationId,array $input,string $key,string $operation,string $state,string $reason):array{
        NotificationSupport::requireCapability(self::CAPABILITY);
        $actor=NotificationSupport::actor();
        $evidence=NotificationSupport::evidence($input);
        $digest=NotificationSupport::keyDigest($key);
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$result=$this->replayCommand($winner,null);$this->repository->commit();return $result;}
            $notification=$this->repository->find($notificationId,true);
            if(!$notification)throw new \RuntimeException('notification_not_found');
            if(NotificationRule::terminalNotificationState((string)$notification->state))throw new \InvalidArgumentException('invalid_notification_state');
            $row=$this->outbox->forNotification($notificationId,true);
            if(!$row)throw new \RuntimeException('notification_outbox_row_required');
            $now=NotificationSupport::now();
            // §6.6/§10: a terminal command that reaches a `dispatching` notification resolves the live lease in
            // the same transaction through its own audited cancellation class, so a terminal notification can
            // never keep an open attempt behind it and no live outbox row is left behind either.
            $this->resolveLiveAttempt($notification,$state,$now,$actor);
            $this->repository->transition($notificationId,(string)$notification->state,array('state'=>$state,'failure_reason_code'=>$reason,'updated_at'=>$now,'updated_by'=>$actor));
            $this->repository->insertEvent($this->eventRow($notificationId,$this->repository->nextSequence($notificationId),$state,(string)$notification->state,$state,$reason,$evidence,$now,$actor,null));
            $this->outbox->closeAny((int)$row->id,$state,$reason);
            $this->recordCommand($digest,$operation,$notificationId,$state,$now,$actor);
            $this->repository->commit();
            return array('notification_id'=>$notificationId,'state'=>$state,'failure_reason_code'=>$reason,'created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replayCommand($winner,null);
            throw $e;
        }
    }
    /**
     * §6.6/§10 — resolve the live lease of one `dispatching` notification inside the caller's transaction.
     *
     * The aggregate row is already locked, so the attempt is read under the S lock order and closed through
     * its own audited cancellation class: `abandoned`, carrying the terminal state the command produced as
     * its `outcome_code`, appending its own attempt event and deriving and persisting no retry schedule. A
     * notification that holds no open attempt (a `scheduled`/`queued` command, or a row whose lease is
     * already closed) resolves to nothing.
     */
    private function resolveLiveAttempt(object $notification,string $state,string $now,int $actor):void{
        $attempt=$this->attempts->openForNotification((int)$notification->id,true);
        if($attempt===null)return;
        $source=(string)$attempt->state;
        if(!in_array($source,NotificationRule::ATTEMPT_OPEN_STATES,true))throw new \RuntimeException('attempt_lifecycle_invalid');
        $this->attempts->closeAttempt((int)$attempt->id,array(
            'state'=>'abandoned','finished_at'=>$now,'outcome_code'=>$state,
            'failure_class'=>NotificationRule::LEASE_CANCELLED_CLASS,'updated_at'=>$now,'updated_by'=>$actor,
        ));
        $this->attempts->insertEvent(array(
            'uid'=>NotificationSupport::uid(),'attempt_id'=>(int)$attempt->id,
            'event_sequence'=>$this->attempts->nextSequence((int)$attempt->id),
            'event_type'=>'abandoned','from_state'=>$source,'to_state'=>'abandoned','reason_code'=>$state,
            'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
        ));
    }
    /**
     * The two terminal pre-scheduling observation outcomes: a row is created and closed in one
     * transaction, no instant is derived, nothing is scheduled, leased or sent, and the intent is never
     * left `pending` waiting for configuration.
     */
    private function closeTerminalObservation(int $outboxId,object $row,object $version,string $intent,string $observedAt,string $timezone,string $code,array $evidence,string $digest,int $actor,array $input=array()):array{
        $binding=NotificationRule::requiredBinding($intent);
        $recipient=$this->recipients?->recipient((string)$version->audience,(string)$version->recipient_kind,(int)$row->aggregate_id);
        if(!$recipient||empty($recipient['resolvable']))throw new \InvalidArgumentException('recipient_unresolved');
        $workflow=$this->workflows->workflow((int)$version->workflow_id);
        if(!$workflow)throw new \InvalidArgumentException('workflow_not_found');
        $templateVersion=$this->resolveTemplateVersion($version);
        // No instant was derived, so the identity carries the empty coalesce bucket exactly as §9 defines
        // it for a version whose coalesce rule is absent.
        $notificationKey=NotificationSupport::notificationKeyDigest((string)$workflow->workflow_key,(int)$version->version_number,$intent,(string)$version->audience,(string)$recipient['recipient_digest'],$binding['aggregate'],(int)$row->aggregate_id,'');
        $this->repository->begin();
        try{
            $now=NotificationSupport::now();
            $notificationId=$this->repository->insertNotification($this->notificationFields(
                $notificationKey,$version,$intent,$recipient,$timezone,$observedAt,$binding,(int)$row->aggregate_id,
                (string)$recipient['recipient_digest'],$outboxId,null,$now,$actor,(int)$templateVersion->id
            ));
            $this->repository->insertEvent($this->eventRow($notificationId,1,'observed',null,'pending','observed',$evidence,$now,$actor,null));
            $snapshotId=$this->freezeRenderedSnapshot($notificationId,$templateVersion,$input,(string)$timezone,$now,$actor);
            $this->repository->attachRenderedSnapshot($notificationId,$snapshotId);
            $this->outbox->enrichIdentity($outboxId,$this->identityFields($notificationId,(string)$workflow->workflow_key,$version,$intent,null));
            return $this->terminalFromPending($notificationId,$outboxId,$evidence,$digest,'observe_intent',$code,$now,$actor);
        }catch(\Throwable $e){
            $this->repository->rollback();
            throw $e;
        }
    }
    /** Close a `pending` observation terminally with `pending → <state>` and nothing derived. */
    private function terminalFromPending(int $notificationId,int $outboxId,array $evidence,string $digest,string $operation,string $code,string $now,int $actor):array{
        $this->repository->transition($notificationId,'pending',array('state'=>'failed','failure_reason_code'=>$code,'updated_at'=>$now,'updated_by'=>$actor));
        $this->repository->insertEvent($this->eventRow($notificationId,$this->repository->nextSequence($notificationId),'failed','pending','failed',$code,$evidence,$now,$actor,null));
        $this->outbox->closeAny($outboxId,'failed',$code);
        $this->recordCommand($digest,$operation,$notificationId,'failed',$now,$actor);
        $this->repository->commit();
        return array('notification_id'=>$notificationId,'state'=>'failed','failure_reason_code'=>$code,'created'=>true,'outbox_id'=>$outboxId);
    }
    /** The shared aggregate shape: identity digests, the resolved zone and no derived instant yet. */
    private function notificationFields(string $notificationKey,object $version,string $intent,array $recipient,string $timezone,string $observedAt,array $binding,int $subjectId,string $subjectReference,int $outboxId,?int $priority,string $now,int $actor,int $templateVersionId):array{
        $scopeDigest=hash_hmac('sha256','notification_scope:'.(int)$version->workflow_id.':'.(int)$version->version_number.':'.$intent.':'.(string)$version->audience,NotificationSupport::salt());
        return array(
            'uid'=>NotificationSupport::uid(),'reference_code'=>null,'notification_key_digest'=>$notificationKey,
            'workflow_id'=>(int)$version->workflow_id,'workflow_version_id'=>(int)$version->id,'intent_key'=>$intent,
            'audience'=>(string)$version->audience,'recipient_kind'=>(string)$version->recipient_kind,
            'recipient_digest'=>(string)$recipient['recipient_digest'],'recipient_contact_digest'=>(string)$recipient['contact_digest'],
            'recipient_contact_envelope'=>$recipient['contact_envelope']??null,'contact_cipher_version'=>$recipient['contact_cipher_version']??null,
            'contact_expires_at'=>$recipient['contact_expires_at']??null,'locale'=>(string)($recipient['locale']??$version->locale),
            'timezone'=>$timezone,'observed_at'=>$observedAt,'schedule_anchor_at'=>null,
            'subject_aggregate'=>$binding['aggregate'],'subject_aggregate_id'=>$subjectId,'subject_reference_digest'=>$subjectReference,
            'template_version_id'=>$templateVersionId,'rendered_snapshot_id'=>null,'notification_key_scope_digest'=>$scopeDigest,
            'outbox_id'=>$outboxId,'state'=>'pending','scheduled_for'=>null,'expires_at'=>null,'priority'=>$priority,
            'deferral_count'=>0,'suppression_id'=>null,'failure_reason_code'=>null,'delivered_at'=>null,'notification_version'=>1,
            'created_at'=>$now,'updated_at'=>$now,'created_by'=>$actor,'updated_by'=>$actor,
        );
    }
    private function identityFields(int $notificationId,string $workflowKey,object $version,string $intent,?int $priority):array{
        return array('notification_id'=>$notificationId,'workflow_key'=>$workflowKey,'workflow_version'=>(int)$version->version_number,'intent_key'=>$intent,'audience'=>(string)$version->audience,'priority'=>$priority);
    }
    /** §6.1/§6.4 — resolve a version's template identity to its exact active `template_version_id`. */
    private function resolveTemplateVersion(object $version):object{
        $templateVersion=$this->templates->activeVersion((int)$version->template_id);
        if(!$templateVersion||(string)$templateVersion->state!=='active')throw new \InvalidArgumentException('template_variable_mismatch');
        return $templateVersion;
    }
    /**
     * §6.4/§11 — freeze the immutable rendered-parameter snapshot in the observation transaction.
     *
     * The snapshot is proved against the template version's frozen variable contract before it exists: the
     * declared code set and the encrypted parameter-key set must both equal the contract exactly, and the
     * stored `params_digest` is the canonical key-ordered one the read and dispatch paths reproduce. A
     * disagreement fails closed with `template_variable_mismatch`, so a snapshot can never claim the
     * required codes while carrying a different (or empty) parameter map; the parameters are stored under
     * authenticated encryption with their cipher version, and an unavailable cipher fails closed with
     * `envelope_decrypt_failure` rather than freezing an unreadable snapshot.
     */
    private function freezeRenderedSnapshot(int $notificationId,object $templateVersion,array $input,string $timezone,string $now,int $actor):int{
        $parameters=(array)($input['template_parameters']??array());
        $proof=NotificationIntegrity::renderParameters($templateVersion,$parameters);
        if(isset($input['variable_codes'])){
            $declared=array_values(array_unique(array_map('strval',(array)$input['variable_codes'])));
            sort($declared);
            if(implode(',',$declared)!==$proof['variable_codes'])throw new \InvalidArgumentException('template_variable_mismatch');
        }
        $envelope=NotificationSupport::encryptEnvelope($parameters);
        if($envelope===null)throw new \RuntimeException('envelope_decrypt_failure');
        return $this->templates->insertSnapshot(array(
            'uid'=>NotificationSupport::uid(),'notification_id'=>$notificationId,'sequence'=>1,
            'template_version_id'=>(int)$templateVersion->id,'supersedes_snapshot_id'=>null,
            'params_digest'=>$proof['params_digest'],
            'variable_codes'=>$proof['variable_codes'],'variable_count'=>$proof['variable_count'],
            'rendered_params_envelope'=>$envelope['envelope'],'cipher_version'=>$envelope['cipher_version'],
            'locale'=>(string)($input['locale']??$templateVersion->locale),'timezone_basis'=>$timezone,
            'rendered_at'=>$now,'rendered_by'=>$actor,'created_at'=>$now,
        ));
    }
    /** Resolve the version's single shared timezone basis against the concrete instance, once (§6.3(b)). */
    private function resolveBasis(array $composition,array $recipient,array $subject):?string{
        $basis=$composition['timezone_basis'];
        if($basis===null)return '';
        $zone=match($basis){
            'recipient_local'=>(string)($recipient['timezone']??''),
            'academy_local'=>(string)($recipient['academy_timezone']??''),
            'subject_local'=>(string)($subject['timezone']??''),
            default=>'',
        };
        if($zone==='')return null;
        try{new \DateTimeZone($zone);}catch(\Throwable $error){return null;}
        return $zone;
    }
    /** Validate a version's frozen state and rules together, returning the parsed composition and policy. */
    private function validatedVersion(?object $version,array $rules):array{
        if(!$version)throw new \InvalidArgumentException('unroutable_intent');
        $asArrays=array();
        foreach($rules as $row)$asArrays[]=array(
            'rule_kind'=>(string)$row->rule_kind,'rule_code'=>(string)$row->rule_code,'ordinal'=>(int)$row->ordinal,
            'parameter_a'=>$row->parameter_a,'parameter_b'=>$row->parameter_b,'parameter_c'=>$row->parameter_c,'parameter_d'=>$row->parameter_d,
        );
        $state=NotificationIntegrity::versionIntegrity($version,$asArrays);
        $tier=$state['tier'];
        $eligibility=NotificationEligibility::validateRequiredSet((string)$version->intent_key,(string)$version->audience,(string)$version->recipient_kind,array_values(array_filter($asArrays,static fn(array $row):bool=>$row['rule_kind']==='eligibility')));
        $composition=NotificationSchedule::validateComposition(array_values(array_filter($asArrays,static fn(array $row):bool=>$row['rule_kind']==='schedule')),$tier);
        NotificationEligibility::assertTierFLeadTime($eligibility,$composition,$tier);
        $retry=NotificationRetry::validatePolicy(array_values(array_filter($asArrays,static fn(array $row):bool=>$row['rule_kind']==='retry')));
        if($tier==='F')NotificationIntegrity::tierFSourceAvailable((string)$version->intent_key);
        $workflow=$this->workflows->workflow((int)$version->workflow_id);
        return array('tier'=>$tier,'eligibility'=>$eligibility,'composition'=>$composition,'retry'=>$retry,'workflow_key'=>$workflow?(string)$workflow->workflow_key:'');
    }
    private function eventRow(int $notificationId,int $sequence,string $eventType,?string $from,string $to,?string $reason,array $evidence,string $now,int $actor,?string $evidenceDigest):array{
        return array(
            'uid'=>NotificationSupport::uid(),'notification_id'=>$notificationId,'event_sequence'=>$sequence,'event_type'=>$eventType,
            'from_state'=>$from,'to_state'=>$to,'reason_code'=>$reason,'evidence_channel'=>$evidence['channel'],
            'evidence_reference_digest'=>$evidenceDigest??$evidence['digest'],'evidence_at'=>$evidence['at'],
            'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
        );
    }
    /** The command payload is recomputable from the stored row, so a same-key/different-payload replay fails. */
    private function commandPayload(string $operation,int $notificationId,string $resultState):string{
        $outbox=$this->outbox->forNotification($notificationId);
        return NotificationSupport::payloadDigest(array(
            'domain'=>NotificationRule::DOMAIN,'operation'=>$operation,'notification_id'=>$notificationId,
            'result_state'=>$resultState,'outbox_id'=>$outbox?(int)$outbox->id:null,
        ));
    }
    private function recordCommand(string $digest,string $operation,int $notificationId,string $resultState,string $now,int $actor):void{
        $this->repository->insertCommand(array(
            'uid'=>NotificationSupport::uid(),'command_domain'=>NotificationRule::DOMAIN,'operation'=>$operation,
            'command_key_digest'=>$digest,'command_payload_digest'=>$this->commandPayload($operation,$notificationId,$resultState),
            'notification_id'=>$notificationId,'result_state'=>$resultState,'result_id'=>$notificationId,'created_at'=>$now,'created_by'=>$actor,
        ));
    }
    private function replayObservedIntent(int $notificationId,int $outboxId):array{
        $notification=$this->repository->find($notificationId);
        if(!$notification)throw new \RuntimeException('notification_not_found');
        return $this->describeNotification($notification,$outboxId);
    }
    private function replayCommand(object $command,?int $outboxId):array{
        NotificationIntegrity::replayPayload((string)$command->command_payload_digest,$this->commandPayload((string)$command->operation,(int)$command->result_id,(string)$command->result_state));
        $notification=$this->repository->find((int)$command->result_id);
        if(!$notification||(string)$notification->state!==(string)$command->result_state)throw new \RuntimeException('workflow_version_integrity');
        return $this->describeNotification($notification,$outboxId??(int)($notification->outbox_id??0));
    }
    private function describeNotification(object $notification,?int $outboxId):array{
        return array(
            'notification_id'=>(int)$notification->id,'outbox_id'=>$outboxId??($notification->outbox_id===null?null:(int)$notification->outbox_id),
            'state'=>(string)$notification->state,'intent_key'=>(string)$notification->intent_key,
            'scheduled_for'=>$notification->scheduled_for,'expires_at'=>$notification->expires_at,
            'deferral_count'=>(int)$notification->deferral_count,'failure_reason_code'=>$notification->failure_reason_code,
            'created'=>false,'idempotent'=>true,'converged'=>true,
        );
    }
}
