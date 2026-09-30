<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

use Delnavazan\Platform\Core\Infrastructure\Repository\PaymentProviderRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Provider object-mapping registry (contract §7).
 *
 * A mapping links one canonical row to one provider object of one kind. The registry stores a keyed
 * `object_reference_digest` and never the raw provider object id; the raw id may exist in memory during
 * one call only. A mapping is never authority: it grants no record access, settles nothing, protects no
 * capacity and never substitutes for a canonical reference. Detaching or superseding a mapping is an
 * append-only event that never rewrites commercial history and never deletes payment evidence.
 */
final class PaymentProviderObjectService {
    private const CAPABILITY='dzn_manage_payment_providers';
    private const VIEW_CAPABILITY='dzn_view_payment_execution_authority';

    public function __construct(private ?PaymentProviderRepository $repository=null){$this->repository??=new PaymentProviderRepository();}

    public function link(array $input,string $key):array{
        PaymentExecutionSupport::requireCapability(self::CAPABILITY);
        $actor=PaymentExecutionSupport::actor();
        $accountId=PaymentExecutionSupport::positiveInt($input['provider_account_id']??null,'Valid provider account required');
        $objectKind=(string)($input['object_kind']??'');
        if(!PaymentExecutionRule::member($objectKind,PaymentExecutionRule::OBJECT_KINDS))throw new \InvalidArgumentException('Controlled provider object kind required');
        $canonicalKind=(string)($input['canonical_kind']??'');
        if(!PaymentExecutionRule::member($canonicalKind,PaymentExecutionRule::CANONICAL_KINDS))throw new \InvalidArgumentException('Controlled canonical kind required');
        $canonicalId=PaymentExecutionSupport::positiveInt($input['canonical_id']??null,'Valid canonical row required');
        $reference=trim((string)($input['object_reference']??''));
        if($reference==='')throw new \InvalidArgumentException('Provider object reference required');
        $referenceDigest=PaymentExecutionIdempotency::reference($reference);
        $digest=PaymentExecutionSupport::keyString($key);
        $payload=PaymentExecutionIdempotency::payload(array('domain'=>PaymentExecutionRule::DOMAIN,'operation'=>'link_provider_object','provider_account_id'=>$accountId,'object_kind'=>$objectKind,'canonical_kind'=>$canonicalKind,'canonical_id'=>$canonicalId,'object_reference_digest'=>$referenceDigest));
        $this->repository->begin();
        try{
            if($this->repository->objectCommand($digest))throw new \RuntimeException('Idempotency conflict');
            $account=$this->repository->account($accountId,true);
            if(!$account)throw new \InvalidArgumentException('payment_provider_account_required');
            PaymentExecutionIntegrity::account($account);
            if((string)$account->state==='closed')throw new \InvalidArgumentException('payment_provider_account_inactive');
            // The canonical row must exist and be the kind the mapping claims; a mapping never grants
            // access, so a foreign, missing or kind-mismatched id is refused.
            if(!$this->canonicalExists($canonicalKind,$canonicalId))throw new \InvalidArgumentException('payment_provider_object_canonical_conflict');
            $now=PaymentExecutionSupport::now();
            $objectId=$this->repository->insertObject(array(
                'uid'=>Identifier::uid(),'reference_code'=>null,'payment_provider_account_id'=>$accountId,
                'object_kind'=>$objectKind,'canonical_kind'=>$canonicalKind,'canonical_id'=>$canonicalId,
                'object_reference_digest'=>$referenceDigest,'state'=>'linked','active_slot'=>1,'link_version'=>1,
                'created_at'=>$now,'updated_at'=>$now,'created_by'=>$actor,'updated_by'=>$actor,
            ));
            $eventId=$this->repository->insertObjectEvent(array(
                'uid'=>Identifier::uid(),'payment_provider_object_id'=>$objectId,'event_sequence'=>1,
                'event_type'=>'linked','from_state'=>null,'to_state'=>'linked','reason_code'=>'provider_object_linked',
                'evidence_channel'=>'authenticated_platform','evidence_reference_digest'=>PaymentExecutionIdempotency::reference('provider-object-'.$objectKind.'-'.$canonicalKind.'-'.$canonicalId),
                'evidence_at'=>$now,'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->insertObjectCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>PaymentExecutionRule::DOMAIN,'operation'=>'link_provider_object',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'payment_provider_object_id'=>$objectId,
                'result_state'=>'linked','result_id'=>$eventId,'created_at'=>$now,'created_by'=>$actor,
            ));
            PaymentExecutionSupport::hook('dzn_phase_2a2t_after_provider_object_event','link_provider_object',$objectId);
            $this->repository->commit();
            return array('provider_object_id'=>$objectId,'state'=>'linked','created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            throw $e;
        }
    }

    public function supersede(int $objectId,array $input,string $key):array{
        return $this->transition($objectId,'superseded','provider_object_superseded',$input,$key);
    }
    public function detach(int $objectId,array $input,string $key):array{
        return $this->transition($objectId,'detached','provider_object_detached',$input,$key);
    }

    /**
     * Resolve the one active mapping for one canonical row and object kind.
     *
     * Returns null when no active mapping exists; a mapping is never sufficient input on its own — the
     * caller must still ask R1/R2 whether the resulting evidence is acceptable.
     */
    public function resolve(int $accountId,string $canonicalKind,int $canonicalId,string $objectKind):?object{
        PaymentExecutionSupport::requireCapability(self::VIEW_CAPABILITY);
        if(!PaymentExecutionRule::member($objectKind,PaymentExecutionRule::OBJECT_KINDS))throw new \InvalidArgumentException('Controlled provider object kind required');
        if(!PaymentExecutionRule::member($canonicalKind,PaymentExecutionRule::CANONICAL_KINDS))throw new \InvalidArgumentException('Controlled canonical kind required');
        $object=$this->repository->activeObject($accountId,$canonicalKind,$canonicalId,$objectKind);
        if(!$object)return null;
        PaymentExecutionIntegrity::mapping($object);
        return $object;
    }

    /** Resolve the canonical row a provider object reference already owns (inbound webhook attribution). */
    public function resolveByReferenceDigest(int $accountId,string $objectKind,string $referenceDigest,?string $canonicalKind=null):?object{
        $object=$this->repository->objectByReferenceDigest($accountId,$objectKind,$referenceDigest);
        if(!$object||(int)$object->active_slot!==1)return null;
        if($canonicalKind!==null&&(string)$object->canonical_kind!==$canonicalKind)return null;
        PaymentExecutionIntegrity::mapping($object);
        return $object;
    }

    public function objects():array{
        PaymentExecutionSupport::requireCapability(self::VIEW_CAPABILITY);
        return array_map(array($this,'view'),$this->repository->allObjects());
    }

    private function transition(int $objectId,string $toState,string $eventType,array $input,string $key):array{
        PaymentExecutionSupport::requireCapability(self::CAPABILITY);
        $actor=PaymentExecutionSupport::actor();
        $digest=PaymentExecutionSupport::keyString($key);
        $payload=PaymentExecutionIdempotency::payload(array('domain'=>PaymentExecutionRule::DOMAIN,'operation'=>$eventType,'provider_object_id'=>$objectId,'to_state'=>$toState));
        $this->repository->begin();
        try{
            if($this->repository->objectCommand($digest))throw new \RuntimeException('Idempotency conflict');
            $object=$this->repository->object($objectId,true);
            if(!$object)throw new \InvalidArgumentException('payment_provider_object_required');
            PaymentExecutionIntegrity::mapping($object);
            if((string)$object->state!=='linked')throw new \InvalidArgumentException('invalid_payment_provider_object_state');
            $now=PaymentExecutionSupport::now();
            $this->repository->updateObject($objectId,(int)$object->link_version,array('state'=>$toState,'active_slot'=>null),$now,$actor);
            $eventId=$this->repository->insertObjectEvent(array(
                'uid'=>Identifier::uid(),'payment_provider_object_id'=>$objectId,
                'event_sequence'=>$this->repository->maxObjectEventSequence($objectId),'event_type'=>$eventType,
                'from_state'=>'linked','to_state'=>$toState,'reason_code'=>$eventType,
                'evidence_channel'=>'authenticated_platform',
                'evidence_reference_digest'=>PaymentExecutionIdempotency::reference('provider-object-'.$eventType.'-'.$objectId),
                'evidence_at'=>$now,'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->insertObjectCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>PaymentExecutionRule::DOMAIN,'operation'=>$eventType,
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'payment_provider_object_id'=>$objectId,
                'result_state'=>$toState,'result_id'=>$eventId,'created_at'=>$now,'created_by'=>$actor,
            ));
            PaymentExecutionSupport::hook('dzn_phase_2a2t_after_provider_object_event',$eventType,$objectId);
            $this->repository->commit();
            return array('provider_object_id'=>$objectId,'state'=>$toState,'created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            throw $e;
        }
    }

    private function canonicalExists(string $canonicalKind,int $canonicalId):bool{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $table=match($canonicalKind){
            'student'=>'students','purchase'=>'commercial_purchases','obligation'=>'commercial_offer_obligations',
            'collection_intent'=>'collection_intents','recurring_enrolment'=>'recurring_enrolments',
            default=>null,
        };
        if($table===null)return false;
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}{$table} WHERE id=%d",$canonicalId))===1;
    }
    private function view(object $object):array{
        return array(
            'provider_object_id'=>(int)$object->id,'payment_provider_account_id'=>(int)$object->payment_provider_account_id,
            'object_kind'=>(string)$object->object_kind,'canonical_kind'=>(string)$object->canonical_kind,
            'canonical_id'=>(int)$object->canonical_id,'state'=>(string)$object->state,
            'link_version'=>(int)$object->link_version,
        );
    }
}
