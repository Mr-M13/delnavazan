<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationAttemptRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationDeliveryRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationOutboxRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationProviderRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationSuppressionRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationTemplateRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationWorkflowRepository;

/**
 * The single authority allowed to create an automated SMS fallback.
 *
 * It is deliberately not a router preference.  It runs only after a verified Telnyx delivery fact (or
 * a provider rejection recorded by the dispatch authority) proves that a specific WhatsApp attempt
 * terminated in failure, and it derives a separate SMS notification in the existing notification,
 * outbox, attempt and delivery tables.  One source notification can produce at most one authorised
 * fallback because the server-written reference code, notification identity and command digest are
 * all unique.
 */
final class NotificationSmsFallbackService {
    public const REFERENCE_PREFIX='SMSF';
    public const COMMAND_AUTHORISED='sms_fallback_authorised';
    public const COMMAND_BLOCKED='sms_fallback_blocked';
    public const COMMAND_PROVIDER_FAILURE='whatsapp_terminal_failure';
    public const COMMAND_SOURCE='sms_fallback_source';
    private const CACHED_BLOCK_REASONS=array(
        'whatsapp_delivered','sms_consent_absent','sms_suppressed','sms_policy_blocked',
        'sms_recipient_ineligible','fallback_source_invalid','fallback_already_used','failure_unverified',
    );

    public function __construct(
        private ?NotificationRepository $notifications=null,
        private ?NotificationOutboxRepository $outbox=null,
        private ?NotificationAttemptRepository $attempts=null,
        private ?NotificationDeliveryRepository $deliveries=null,
        private ?NotificationTemplateRepository $templates=null,
        private ?NotificationWorkflowRepository $workflows=null,
        private ?NotificationSuppressionRepository $suppressions=null,
        private ?NotificationProviderRepository $provider=null,
        private ?NotificationRecipientReadPort $recipients=null,
        private $smsPolicy=null
    ){
        $this->notifications??=new NotificationRepository();
        $this->outbox??=new NotificationOutboxRepository();
        $this->attempts??=new NotificationAttemptRepository();
        $this->deliveries??=new NotificationDeliveryRepository();
        $this->templates??=new NotificationTemplateRepository();
        $this->workflows??=new NotificationWorkflowRepository();
        $this->suppressions??=new NotificationSuppressionRepository();
        $this->provider??=new NotificationProviderRepository();
    }

    /**
     * @param array $evidence an already-verified provider evidence envelope
     *        (`evidence_channel`, `evidence_reference`, `evidence_at`)
     * @return array{authorised:bool,reason:string,fallback_notification_id:?int,replayed:bool}
     */
    public function authoriseFromVerifiedFailure(int $sourceNotificationId,int $sourceAttemptId,string $failureDigest,array $evidence,bool $withinTransaction=false):array{
        $actor=NotificationSupport::actor();
        $now=NotificationSupport::now();
        if(!$withinTransaction)$this->notifications->begin();
        try{
            $source=$this->notifications->find($sourceNotificationId,true);
            if(!$source)throw new \RuntimeException('notification_not_found');

            $blocked=function(string $reason)use($sourceNotificationId,$sourceAttemptId,$failureDigest,$now,$actor,$withinTransaction):array{
                $this->recordBlocked($sourceNotificationId,$sourceAttemptId,$reason,$failureDigest,$now,$actor);
                if(!$withinTransaction)$this->notifications->commit();
                return array('authorised'=>false,'reason'=>$reason,'fallback_notification_id'=>null,'replayed'=>false);
            };

            if((string)$source->state==='delivered'||$this->deliveries->hasAppliedDelivered($sourceNotificationId))return $blocked('whatsapp_delivered');

            $existing=$this->existingDecision($sourceNotificationId,$sourceAttemptId);
            if($existing!==null){if(!$withinTransaction)$this->notifications->commit();return $existing;}

            if(!in_array((string)$source->state,array('dispatching','dispatched','failed'),true))return $blocked('fallback_source_invalid');
            if($source->expires_at===null||(string)$source->expires_at<=$now)return $blocked('sms_window_closed');

            $attempt=$this->attempts->find($sourceAttemptId,true);
            if(!$attempt||(int)$attempt->notification_id!==$sourceNotificationId)return $blocked('fallback_source_invalid');
            if(!in_array((string)$attempt->state,array('handed_off','acknowledged','failed'),true))return $blocked('fallback_source_invalid');

            $failure=$this->verifiedFailure($source,$attempt,$failureDigest);
            if($failure===null)return $blocked('failure_unverified');

            $context=$this->smsContext($source,$now);
            if(($context['allowed']??false)!==true)return $blocked((string)($context['reason']??'sms_recipient_ineligible'));

            $reference=self::REFERENCE_PREFIX.$sourceNotificationId;
            $existingNotification=$this->notifications->byReferenceCode($reference,true);
            if($existingNotification){
                $this->recordAuthorised($source,$attempt,(int)$existingNotification->id,$failure['digest'],$evidence,$now,$actor);
                if(!$withinTransaction)$this->notifications->commit();
                return array('authorised'=>true,'reason'=>'authorised','fallback_notification_id'=>(int)$existingNotification->id,'replayed'=>true);
            }

            $fallback=$this->createFallback($source,$attempt,$reference,$context,$failure['digest'],$evidence,$now,$actor);
            if(!$withinTransaction)$this->notifications->commit();
            return array('authorised'=>true,'reason'=>'authorised','fallback_notification_id'=>$fallback,'replayed'=>false);
        }catch(\Throwable $error){
            if(!$withinTransaction)$this->notifications->rollback();
            if($this->notifications->duplicate($error)==='reference_code'||$this->notifications->duplicate($error)==='notification_key_digest'){
                $existing=$this->notifications->byReferenceCode(self::REFERENCE_PREFIX.$sourceNotificationId);
                if($existing&&!$withinTransaction)$this->notifications->commit();
                if($existing)return array('authorised'=>true,'reason'=>'authorised','fallback_notification_id'=>(int)$existing->id,'replayed'=>true);
            }
            throw $error;
        }
    }

    /** Reconcile a delivery failure that arrived while the WhatsApp hand-off was still being acknowledged. */
    public function authoriseExistingFailure(int $sourceNotificationId,int $sourceAttemptId,bool $withinTransaction=false):?array{
        $failure=$this->deliveries->appliedFailureForAttempt($sourceNotificationId,$sourceAttemptId);
        if(!$failure)return null;
        return $this->authoriseFromVerifiedFailure(
            $sourceNotificationId,(int)$failure->attempt_id,(string)$failure->provider_fact_digest,
            array(
                'evidence_channel'=>'provider_evidence',
                'evidence_reference'=>'telnyx-failure:'.(string)$failure->provider_fact_digest,
                'evidence_at'=>(string)$failure->occurred_at,
            ),
            $withinTransaction
        );
    }

    /**
     * Persist a verified synchronous provider rejection, then authorise the fallback in a separate
     * transaction.  The source closure has already committed, so fallback persistence can never undo it.
     */
    public function authoriseFromProviderRejection(int $sourceNotificationId,int $sourceAttemptId,string $failureDigest,array $evidence):array{
        $this->notifications->begin();
        try{
            $source=$this->notifications->find($sourceNotificationId,true);
            $attempt=$this->attempts->find($sourceAttemptId,true);
            if(!$source||!$attempt)throw new \RuntimeException('notification_not_found');
            $this->recordProviderFailure($source,$attempt,$failureDigest,$evidence);
            $this->notifications->commit();
        }catch(\Throwable $error){
            $this->notifications->rollback();
            throw $error;
        }
        try{
            return $this->authoriseFromVerifiedFailure($sourceNotificationId,$sourceAttemptId,$failureDigest,$evidence,false);
        }catch(\Throwable $error){
            try{
                $this->notifications->begin();
                $this->recordBlocked($sourceNotificationId,$sourceAttemptId,'fallback_creation_failed',$failureDigest,NotificationSupport::now(),NotificationSupport::actor());
                $this->notifications->commit();
            }catch(\Throwable){$this->notifications->rollback();}
            return array('authorised'=>false,'reason'=>'fallback_creation_failed','fallback_notification_id'=>null,'replayed'=>false);
        }
    }

    /** Record the verified provider rejection that lets the dispatch path use the same authority. */
    public function recordProviderFailure(object $notification,object $attempt,string $failureDigest,array $evidence):void{
        if($failureDigest==='')return;
        $actor=NotificationSupport::actor();
        $now=NotificationSupport::now();
        $digest=NotificationSupport::keyDigest('whatsapp-terminal-failure:'.$notification->id.':'.$attempt->id);
        if($this->notifications->command($digest))return;
        $this->notifications->insertCommand(array(
            'uid'=>NotificationSupport::uid(),'command_domain'=>NotificationRule::DOMAIN,'operation'=>self::COMMAND_PROVIDER_FAILURE,
            'command_key_digest'=>$digest,
            'command_payload_digest'=>NotificationSupport::payloadDigest(array('domain'=>NotificationRule::DOMAIN,'operation'=>self::COMMAND_PROVIDER_FAILURE,'notification_id'=>(int)$notification->id,'attempt_id'=>(int)$attempt->id,'failure_digest'=>$failureDigest)),
            'notification_id'=>(int)$notification->id,'result_state'=>'failed','result_id'=>(int)$attempt->id,'created_at'=>$now,'created_by'=>$actor,
        ));
    }

    /** Cancel a queued or dispatching fallback when the source WhatsApp notification is delivered. */
    public function cancelForDeliveredSource(int $sourceNotificationId,string $now,int $actor,?string $deliveredDigest=null):int{
        $fallback=$this->authorisedFallbackForSource($sourceNotificationId);
        if(!$fallback)return 0;
        $this->notifications->begin();
        try{
            $notification=$this->notifications->find((int)$fallback->id,true);
            if(!$notification||!in_array((string)$notification->state,array('pending','scheduled','queued','dispatching'),true)){
                $this->notifications->commit();
                return 0;
            }
            $row=$this->outbox->forNotification((int)$notification->id,true);
            if((string)$notification->state==='dispatching'){
                $attempt=$this->attempts->openForNotification((int)$notification->id,true);
                if($attempt){
                    $source=(string)$attempt->state;
                    $this->attempts->closeAttempt((int)$attempt->id,array('state'=>'abandoned','finished_at'=>$now,'outcome_code'=>'cancelled','failure_class'=>NotificationRule::LEASE_CANCELLED_CLASS,'updated_at'=>$now,'updated_by'=>$actor));
                    $this->attempts->insertEvent(array(
                        'uid'=>NotificationSupport::uid(),'attempt_id'=>(int)$attempt->id,'event_sequence'=>$this->attempts->nextSequence((int)$attempt->id),
                        'event_type'=>'abandoned','from_state'=>$source,'to_state'=>'abandoned','reason_code'=>'cancelled',
                        'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
                    ));
                }
            }
            $previous=(string)$notification->state;
            $this->notifications->transition((int)$notification->id,$previous,array('state'=>'cancelled','failure_reason_code'=>'whatsapp_delivered','updated_at'=>$now,'updated_by'=>$actor));
            $digest=$deliveredDigest!==null&&preg_match('/^[a-f0-9]{64}$/',$deliveredDigest)===1
                ?$deliveredDigest:hash_hmac('sha256','sms-fallback-cancel:'.$sourceNotificationId,NotificationSupport::salt());
            $this->notifications->insertEvent(array(
                'uid'=>NotificationSupport::uid(),'notification_id'=>(int)$notification->id,'event_sequence'=>$this->notifications->nextSequence((int)$notification->id),
                'event_type'=>'cancelled','from_state'=>$previous,'to_state'=>'cancelled','reason_code'=>'whatsapp_delivered',
                'evidence_channel'=>'provider_evidence','evidence_reference_digest'=>$digest,
                'evidence_at'=>$now,'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            if($row)$this->outbox->closeAny((int)$row->id,'cancelled','whatsapp_delivered');
            $this->notifications->commit();
            return 1;
        }catch(\Throwable $error){
            $this->notifications->rollback();
            throw $error;
        }
    }

    /**
     * Revalidate a fallback notification's authority immediately before an SMS hand-off.  A late
     * WhatsApp delivery, a withdrawn authorisation or a replaced failure fact refuses the send.
     */
    public function authorisedForNotification(object $fallback):bool{
        $sourceId=$this->sourceId($fallback);
        if($sourceId<1||(int)($fallback->id??0)<1)return false;
        $source=$this->notifications->find($sourceId);
        if(!$source||!in_array((string)$source->state,array('dispatching','dispatched','failed'),true))return false;
        if((string)$source->state==='delivered'||$this->deliveries->hasAppliedDelivered($sourceId))return false;
        $command=$this->authorisedCommandForSource($sourceId);
        if(!$command||(int)$command->result_id!==(int)$fallback->id||(string)$command->result_state!=='queued')return false;
        $attemptId=$this->sourceAttemptForFallback((int)$fallback->id);
        if($attemptId<1)return false;
        // Consent, suppression, category policy, phone validity and the current SMS template are
        // re-proved at hand-off time, not only when the fallback was first authorised.
        $context=$this->smsContext($source,NotificationSupport::now());
        if(($context['allowed']??false)!==true)return false;
        if($this->deliveries->appliedFailureForAttempt($sourceId,$attemptId)!==null)return true;
        $failure=$this->notifications->command(NotificationSupport::keyDigest('whatsapp-terminal-failure:'.$sourceId.':'.$attemptId));
        return $failure!==null&&(string)$failure->operation===self::COMMAND_PROVIDER_FAILURE&&(string)$failure->result_state==='failed';
    }

    public function authorisedFallbackForSource(int $sourceNotificationId):?object{
        $command=$this->authorisedCommandForSource($sourceNotificationId);
        return $command?$this->notifications->find((int)$command->result_id):null;
    }

    /**
     * Recover a verified provider rejection whose source closure committed but whose fallback write did
     * not.  The durable command is the evidence; no provider payload or recipient value is read.
     */
    public function reconcilePending(int $limit=25):array{
        $authorised=0;$blocked=0;$failed=0;
        foreach($this->notifications->commandsByOperation(self::COMMAND_PROVIDER_FAILURE,$limit) as $command){
            $sourceId=(int)$command->notification_id;$attemptId=(int)$command->result_id;
            if($sourceId<1||$attemptId<1)continue;
            if($this->existingDecision($sourceId,$attemptId)!==null)continue;
            try{
                $result=$this->authoriseFromVerifiedFailure(
                    $sourceId,$attemptId,(string)$command->command_key_digest,
                    array(
                        'evidence_channel'=>'provider_evidence',
                        'evidence_reference'=>'telnyx-failure-command:'.(string)$command->command_key_digest,
                        'evidence_at'=>(string)$command->created_at,
                    ),
                    false
                );
                if($result['authorised'])$authorised++;else $blocked++;
            }catch(\Throwable){$failed++;}
        }
        return array('authorised'=>$authorised,'blocked'=>$blocked,'failed'=>$failed);
    }

    public function sourceId(object $fallback):int{
        $reference=(string)($fallback->reference_code??'');
        if(preg_match('/^'.self::REFERENCE_PREFIX.'([1-9][0-9]*)$/',$reference,$match)!==1)return 0;
        return (int)$match[1];
    }

    private function existingDecision(int $sourceId,int $attemptId):?array{
        $command=$this->notifications->command(NotificationSupport::keyDigest(self::COMMAND_AUTHORISED.':'.$sourceId.':'.$attemptId));
        if($command)return array('authorised'=>true,'reason'=>'authorised','fallback_notification_id'=>(int)$command->result_id,'replayed'=>true);
        $blocked=$this->notifications->command(NotificationSupport::keyDigest(self::COMMAND_BLOCKED.':'.$sourceId.':'.$attemptId));
        if($blocked&&in_array((string)$blocked->result_state,self::CACHED_BLOCK_REASONS,true)){
            return array('authorised'=>false,'reason'=>(string)$blocked->result_state,'fallback_notification_id'=>null,'replayed'=>true);
        }
        return null;
    }

    /** @return array{digest:string,occurred_at:string}|null */
    private function verifiedFailure(object $source,object $attempt,string $failureDigest):?array{
        $fact=$this->deliveries->appliedFailureForAttempt((int)$source->id,(int)$attempt->id);
        if($fact){
            $message=$this->provider->messageForAttempt((int)$source->id,(int)$attempt->id);
            if(!$message||(string)$message->channel!=='whatsapp')return null;
            if($failureDigest!==''&&!hash_equals((string)$fact->provider_fact_digest,$failureDigest))return null;
            return array('digest'=>(string)$fact->provider_fact_digest,'occurred_at'=>(string)$fact->occurred_at);
        }
        if($failureDigest==='')return null;
        $commandDigest=NotificationSupport::keyDigest('whatsapp-terminal-failure:'.(int)$source->id.':'.(int)$attempt->id);
        $command=$this->notifications->command($commandDigest);
        if(!$command||(string)$command->operation!==self::COMMAND_PROVIDER_FAILURE||(string)$command->result_state!=='failed'||(int)$command->result_id!==(int)$attempt->id)return null;
        // A recovery pass uses the durable command's own keyed digest as evidence: the command was
        // written only after an actual provider rejection, so it is the authoritative persisted fact.
        if(hash_equals((string)$command->command_key_digest,$failureDigest))return array('digest'=>$failureDigest,'occurred_at'=>(string)$command->created_at);
        $expected=NotificationSupport::payloadDigest(array('domain'=>NotificationRule::DOMAIN,'operation'=>self::COMMAND_PROVIDER_FAILURE,'notification_id'=>(int)$source->id,'attempt_id'=>(int)$attempt->id,'failure_digest'=>$failureDigest));
        if(!hash_equals((string)$command->command_payload_digest,$expected))return null;
        return array('digest'=>$failureDigest,'occurred_at'=>NotificationSupport::now());
    }

    /** Resolve SMS consent, suppression, policy, template and copy before a fallback aggregate exists. */
    private function smsContext(object $source,string $now):array{
        $version=$this->templates->version((int)$source->template_version_id);
        $template=$version?$this->templates->template((int)$version->template_id):null;
        if(!$version||!$template)return array('allowed'=>false,'reason'=>'fallback_source_invalid');
        $parts=explode('.',(string)$template->template_key);
        if(count($parts)<3)return array('allowed'=>false,'reason'=>'fallback_source_invalid');
        $locale=array_pop($parts);$channel=array_pop($parts);$eventKey=implode('.',$parts);
        if($channel!=='whatsapp')return array('allowed'=>false,'reason'=>'fallback_source_invalid');
        $policyAllowed=is_callable($this->smsPolicy)&&(bool)($this->smsPolicy)($eventKey,'sms');
        $binding=NotificationRule::requiredBinding((string)$source->intent_key);
        if($this->recipients instanceof NotificationBoundRecipientReadPort){
            $recipient=$this->recipients->recipientForBinding((string)$source->audience,(string)$source->recipient_kind,(int)$source->subject_aggregate_id,$binding);
        }else{
            $recipient=$this->recipients?->recipient((string)$source->audience,(string)$source->recipient_kind,(int)$source->subject_aggregate_id);
        }
        $consent=is_array($recipient)&&!empty($recipient['resolvable'])&&!empty($recipient['sms_opted_in']??$recipient['opted_in']??false)&&!empty($recipient['guardian_authority_present'])
            &&(string)($recipient['recipient_digest']??'')===(string)$source->recipient_digest;
        $contact=NotificationSupport::decryptEnvelope($recipient['contact_envelope']??null,$recipient['contact_cipher_version']??null);
        if(!is_array($contact))return array('allowed'=>false,'reason'=>'sms_recipient_ineligible');
        $phone=(string)($contact['sms']??'');
        $phoneValid=$this->validPhone($phone);
        $suppression=$this->suppressions->active((string)$source->recipient_digest,(string)$source->intent_key,$now,NotificationRule::providerStopApplies((string)$source->intent_key,'mobile'));
        $decision=NotificationSmsFallbackPolicy::decide(array(
            'whatsapp_status'=>NotificationSmsFallbackPolicy::STATUS_TERMINAL_FAILED,
            'failure_verified'=>true,'sms_consent'=>$consent,'sms_suppressed'=>$suppression!==null,
            'sms_policy_allowed'=>$policyAllowed,'phone_valid'=>$phoneValid,'fallback_used'=>false,
        ));
        if($decision['action']!==NotificationSmsFallbackPolicy::SEND_SMS)return array('allowed'=>false,'reason'=>$decision['reason']);
        $smsTemplate=$this->templates->templateByKey($eventKey.'.sms.'.$locale);
        $smsVersion=$smsTemplate?$this->templates->activeVersion((int)$smsTemplate->id):null;
        if(!$smsVersion||(string)$smsVersion->state!=='active')return array('allowed'=>false,'reason'=>'sms_template_unavailable');

        $snapshot=$source->rendered_snapshot_id===null?null:$this->templates->snapshot((int)$source->rendered_snapshot_id);
        if(!$snapshot)return array('allowed'=>false,'reason'=>'sms_template_unavailable');
        $parameters=NotificationSupport::decryptEnvelope($snapshot->rendered_params_envelope,$snapshot->cipher_version===null?null:(string)$snapshot->cipher_version);
        if($parameters===null)return array('allowed'=>false,'reason'=>'sms_template_variable_mismatch');
        try{$proof=NotificationIntegrity::renderParameters($smsVersion,$parameters);}
        catch(\Throwable){return array('allowed'=>false,'reason'=>'sms_template_variable_mismatch');}

        return array(
            'allowed'=>true,'reason'=>'authorised','event_key'=>$eventKey,'locale'=>$locale,
            'sms_version'=>$smsVersion,'snapshot'=>$snapshot,'parameters'=>$parameters,'proof'=>$proof,
            'recipient'=>$recipient,'phone'=>$phone,
        );
    }

    private function createFallback(object $source,object $attempt,string $reference,array $context,string $failureDigest,array $evidence,string $now,int $actor):int{
        $workflow=$this->workflows->workflow((int)$source->workflow_id);
        $version=$this->workflows->version((int)$source->workflow_version_id);
        if(!$workflow||!$version)throw new \RuntimeException('workflow_not_found');
        $rules=array();
        foreach($this->workflows->rulesFor((int)$version->id) as $row)$rules[]=array(
            'rule_kind'=>(string)$row->rule_kind,'rule_code'=>(string)$row->rule_code,'ordinal'=>(int)$row->ordinal,
            'parameter_a'=>$row->parameter_a,'parameter_b'=>$row->parameter_b,'parameter_c'=>$row->parameter_c,'parameter_d'=>$row->parameter_d,
        );
        $composition=NotificationSchedule::validateComposition(array_values(array_filter($rules,static fn(array $row):bool=>$row['rule_kind']==='schedule')),NotificationRule::intentTier((string)$source->intent_key));
        $bucket=NotificationSupport::coalesceBucket((string)$source->schedule_anchor_at,$composition['coalesce']!==null?(int)$composition['coalesce']['coalesce_window_minutes']:null);
        $key=NotificationSupport::notificationKeyDigest(
            (string)$workflow->workflow_key,(int)$version->version_number,(string)$source->intent_key,
            (string)$source->audience,(string)$source->recipient_digest,(string)$source->subject_aggregate,
            (int)$source->subject_aggregate_id,$bucket,$reference
        );
        $idempotency=NotificationSupport::keyDigest('sms-fallback-outbox:'.$source->id.':'.$attempt->id);
        $outboxId=$this->outbox->insertQueued(array(
            'aggregate_type'=>'notification','aggregate_id'=>(int)$source->subject_aggregate_id,'event_type'=>(string)$source->intent_key,
            'invitation_id'=>null,'generation_id'=>null,'idempotency_key'=>$idempotency,'status'=>'queued',
            'available_at'=>(string)$source->scheduled_for,'leased_at'=>null,'processed_at'=>null,'attempt_count'=>0,'created_at'=>$now,
            'notification_id'=>null,'workflow_key'=>(string)$workflow->workflow_key,'workflow_version'=>(int)$version->version_number,
            'intent_key'=>(string)$source->intent_key,'audience'=>(string)$source->audience,
            'scheduled_for'=>(string)$source->scheduled_for,'expires_at'=>(string)$source->expires_at,
            'deferral_count'=>(int)$source->deferral_count,'priority'=>$source->priority,'lease_token_digest'=>null,'failure_reason_code'=>null,
        ));
        $scope=hash_hmac('sha256','notification_scope:'.(int)$source->workflow_id.':'.(int)$version->version_number.':'.(string)$source->intent_key.':'.(string)$source->audience,NotificationSupport::salt());
        $fallbackId=$this->notifications->insertNotification(array(
            'uid'=>NotificationSupport::uid(),'reference_code'=>$reference,'notification_key_digest'=>$key,
            'workflow_id'=>(int)$source->workflow_id,'workflow_version_id'=>(int)$source->workflow_version_id,
            'intent_key'=>(string)$source->intent_key,'audience'=>(string)$source->audience,'recipient_kind'=>(string)$source->recipient_kind,
            'recipient_digest'=>(string)$source->recipient_digest,'recipient_contact_digest'=>(string)$source->recipient_contact_digest,
            'recipient_contact_envelope'=>$source->recipient_contact_envelope,'contact_cipher_version'=>$source->contact_cipher_version,
            'contact_expires_at'=>$source->contact_expires_at,'locale'=>$source->locale,'timezone'=>(string)$source->timezone,
            'observed_at'=>$source->observed_at,'schedule_anchor_at'=>$source->schedule_anchor_at,
            'subject_aggregate'=>(string)$source->subject_aggregate,'subject_aggregate_id'=>(int)$source->subject_aggregate_id,
            'subject_reference_digest'=>(string)$source->subject_reference_digest,
            'template_version_id'=>(int)$context['sms_version']->id,'rendered_snapshot_id'=>null,
            'notification_key_scope_digest'=>$scope,'outbox_id'=>$outboxId,'state'=>'queued',
            'scheduled_for'=>$source->scheduled_for,'expires_at'=>$source->expires_at,'priority'=>$source->priority,
            'deferral_count'=>(int)$source->deferral_count,'suppression_id'=>null,'failure_reason_code'=>null,'delivered_at'=>null,
            'notification_version'=>1,'created_at'=>$now,'updated_at'=>$now,'created_by'=>$actor,'updated_by'=>$actor,
        ));
        $this->linkOutbox($outboxId,$fallbackId);
        $snapshotId=$this->templates->insertSnapshot(array(
            'uid'=>NotificationSupport::uid(),'notification_id'=>$fallbackId,'sequence'=>1,
            'template_version_id'=>(int)$context['sms_version']->id,'supersedes_snapshot_id'=>null,
            'params_digest'=>$context['proof']['params_digest'],'variable_codes'=>$context['proof']['variable_codes'],
            'variable_count'=>$context['proof']['variable_count'],
            'rendered_params_envelope'=>$context['snapshot']->rendered_params_envelope,
            'cipher_version'=>$context['snapshot']->cipher_version,'locale'=>(string)$context['locale'],
            'timezone_basis'=>$context['snapshot']->timezone_basis,'rendered_at'=>$now,'rendered_by'=>$actor,'created_at'=>$now,
        ));
        $this->notifications->attachRenderedSnapshot($fallbackId,$snapshotId);
        $eventBase=array(
            'uid'=>NotificationSupport::uid(),'notification_id'=>$fallbackId,
            'evidence_channel'=>(string)($evidence['evidence_channel']??'provider_evidence'),
            'evidence_reference_digest'=>$failureDigest,'evidence_at'=>(string)($evidence['evidence_at']??$now),
            'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
        );
        $this->notifications->insertEvent($eventBase+array('event_sequence'=>1,'event_type'=>'observed','from_state'=>null,'to_state'=>'pending','reason_code'=>'whatsapp_terminal_failure'));
        $this->notifications->insertEvent($eventBase+array('event_sequence'=>2,'event_type'=>'scheduled','from_state'=>'pending','to_state'=>'scheduled','reason_code'=>'whatsapp_terminal_failure'));
        $this->notifications->insertEvent($eventBase+array('event_sequence'=>3,'event_type'=>'queued','from_state'=>'scheduled','to_state'=>'queued','reason_code'=>'whatsapp_terminal_failure'));
        $this->recordAuthorised($source,$attempt,$fallbackId,$failureDigest,$evidence,$now,$actor);
        return $fallbackId;
    }
    private function linkOutbox(int $outboxId,int $notificationId):void{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $changed=$wpdb->update($p.'platform_outbox',array('notification_id'=>$notificationId),array('id'=>$outboxId));
        if($changed!==1)throw new \RuntimeException('notification_outbox_state_conflict');
    }
    private function recordAuthorised(object $source,object $attempt,int $fallbackId,string $failureDigest,array $evidence,string $now,int $actor):void{
        $digest=NotificationSupport::keyDigest(self::COMMAND_AUTHORISED.':'.$source->id.':'.$attempt->id);
        if(!$this->notifications->command($digest)){
            $this->notifications->insertCommand(array(
                'uid'=>NotificationSupport::uid(),'command_domain'=>NotificationRule::DOMAIN,'operation'=>self::COMMAND_AUTHORISED,
                'command_key_digest'=>$digest,
                'command_payload_digest'=>NotificationSupport::payloadDigest(array('domain'=>NotificationRule::DOMAIN,'operation'=>self::COMMAND_AUTHORISED,'notification_id'=>(int)$source->id,'attempt_id'=>(int)$attempt->id,'fallback_notification_id'=>$fallbackId,'failure_digest'=>$failureDigest)),
                'notification_id'=>(int)$source->id,'result_state'=>'queued','result_id'=>$fallbackId,'created_at'=>$now,'created_by'=>$actor,
            ));
        }
        $sourceDigest=NotificationSupport::keyDigest(self::COMMAND_SOURCE.':'.(int)$source->id.':'.(int)$attempt->id);
        if(!$this->notifications->command($sourceDigest)){
            $this->notifications->insertCommand(array(
                'uid'=>NotificationSupport::uid(),'command_domain'=>NotificationRule::DOMAIN,'operation'=>self::COMMAND_SOURCE,
                'command_key_digest'=>$sourceDigest,
                'command_payload_digest'=>NotificationSupport::payloadDigest(array('domain'=>NotificationRule::DOMAIN,'operation'=>self::COMMAND_SOURCE,'source_notification_id'=>(int)$source->id,'attempt_id'=>(int)$attempt->id,'fallback_notification_id'=>$fallbackId)),
                'notification_id'=>$fallbackId,'result_state'=>'whatsapp_attempt','result_id'=>(int)$attempt->id,'created_at'=>$now,'created_by'=>$actor,
            ));
        }
    }
    private function recordBlocked(int $sourceId,int $attemptId,string $reason,string $failureDigest,string $now,int $actor):void{
        $digest=NotificationSupport::keyDigest(self::COMMAND_BLOCKED.':'.$sourceId.':'.$attemptId);
        if($this->notifications->command($digest))return;
        $this->notifications->insertCommand(array(
            'uid'=>NotificationSupport::uid(),'command_domain'=>NotificationRule::DOMAIN,'operation'=>self::COMMAND_BLOCKED,
            'command_key_digest'=>$digest,
            'command_payload_digest'=>NotificationSupport::payloadDigest(array('domain'=>NotificationRule::DOMAIN,'operation'=>self::COMMAND_BLOCKED,'notification_id'=>$sourceId,'attempt_id'=>$attemptId,'reason'=>$reason,'failure_digest'=>$failureDigest)),
            'notification_id'=>$sourceId,'result_state'=>substr($reason,0,32),'result_id'=>$attemptId,'created_at'=>$now,'created_by'=>$actor,
        ));
    }
    private function authorisedCommandForSource(int $sourceId):?object{
        $rows=$this->notifications->commandsForNotification($sourceId,self::COMMAND_AUTHORISED);
        return $rows[0]??null;
    }
    private function sourceAttemptForFallback(int $fallbackId):int{
        $rows=$this->notifications->commandsForNotification($fallbackId,self::COMMAND_SOURCE);
        return $rows===array()?0:(int)$rows[0]->result_id;
    }
    private function validPhone(string $phone):bool{
        $digits=preg_replace('/[^0-9]/','',$phone)??'';
        return strlen($digits)>=8&&strlen($digits)<=15;
    }
}
