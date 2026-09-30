<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\RefundReviewRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Phase 2A.2-R2 Refund/Reversal review authority: record and route for human review only.
 *
 * `academic_consequence` stays NULL (unresolved product decision); no funded-session clawback or
 * settlement mutation is performed.
 */
final class RefundReviewService {
    private const CAPABILITY='dzn_manage_refund_reviews';
    public function __construct(private ?RefundReviewRepository $repository=null){$this->repository??=new RefundReviewRepository();}

    public function recordRefundEvidence(array $input,string $key):array{
        RecurringSupport::requireCapability(self::CAPABILITY);
        $actor=RecurringSupport::actor();
        $evidence=RecurringSupport::evidence($input);
        $purchaseId=RecurringSupport::positiveInt($input['purchase_id']??null,'Valid R1 purchase required');
        $obligationId=RecurringSupport::positiveInt($input['obligation_id']??null,'Valid R1 obligation required');
        $evidenceId=RecurringSupport::positiveInt($input['evidence_id']??null,'Valid R1 payment evidence required');
        $kind=(string)($input['kind']??'');
        if(!in_array($kind,RecurringRule::REFUND_REVIEW_KINDS,true))throw new \InvalidArgumentException('Controlled refund review kind required');
        // §5.5: the case records *R1 refund/reversal evidence*. A reversal has no authoritative R1
        // representation yet (`CommercialRule::EVIDENCE_KINDS` records none), so R2 refuses to
        // represent one rather than dressing an ordinary payment as a reversal.
        if(!RecurringRule::reviewEvidenceKind($kind))throw new \InvalidArgumentException('reversal_evidence_not_supported');
        $digest=RecurringSupport::keyString($key);
        $payload='';
        $this->repository->begin();
        try{
            // The review is created from an existing R1 purchase, so the buyer's commercial account
            // root is taken before the new aggregate row is written.
            RecurringSupport::guardPurchase($purchaseId,$actor);
            // §5.5: the case records R1 refund/reversal evidence against a purchase *and* obligation.
            // The evidence must really belong to that purchase and that obligation, be *accepted*, and
            // *be* refund evidence: its recorded kind must be the reviewed kind and its exact amount and
            // currency the reviewed amount and currency. R2 therefore adopts the reviewed sum from the
            // authoritative evidence instead of trusting a caller's assertion, so an ordinary successful
            // payment can never be represented as a refund of an amount it never carried.
            $settled=$this->assertEvidenceOwnership($purchaseId,$obligationId,$evidenceId,$kind,$input);
            $payload=RecurringIdempotency::payload(array('domain'=>RecurringRule::DOMAIN,'operation'=>'record_refund_evidence','purchase_id'=>$purchaseId,'obligation_id'=>$obligationId,'evidence_id'=>$evidenceId,'kind'=>$kind,'amount_minor'=>$settled['amount_minor'],'currency'=>$settled['currency'],'evidence_reference_digest'=>$evidence['digest']));
            if($winner=$this->repository->command($digest)){$result=$this->replay($winner,$payload);$this->repository->commit();return $result;}
            if($this->repository->forEvidence($evidenceId))throw new \InvalidArgumentException('refund_review_already_exists');
            $now=RecurringSupport::now();
            $id=$this->repository->insertCase(array(
                'uid'=>Identifier::uid(),'purchase_id'=>$purchaseId,'obligation_id'=>$obligationId,'evidence_id'=>$evidenceId,
                'kind'=>$kind,'amount_minor'=>$settled['amount_minor'],'currency'=>$settled['currency'],'state'=>'open','academic_consequence'=>null,'resolution_note'=>null,
                'refund_review_version'=>1,'created_at'=>$now,'updated_at'=>$now,'created_by'=>$actor,'updated_by'=>$actor,
            ));
            $this->repository->insertEvent(array(
                'uid'=>Identifier::uid(),'refund_review_id'=>$id,'event_sequence'=>1,'event_type'=>'opened',
                'from_state'=>null,'to_state'=>'open','reason_code'=>'opened','evidence_channel'=>$evidence['channel'],
                'evidence_reference_digest'=>$evidence['digest'],'evidence_at'=>$evidence['at'],'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>RecurringRule::DOMAIN,'operation'=>'record_refund_evidence',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'refund_review_id'=>$id,
                'result_state'=>'open','result_id'=>$id,'created_at'=>$now,'created_by'=>$actor,
            ));
            RecurringSupport::hook('dzn_phase_2a2r2_after_refund_review_event_insert','record_refund_evidence',$id);
            $this->repository->commit();
            return array('refund_review_id'=>$id,'state'=>'open','academic_consequence'=>null,'created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload);
            throw $e;
        }
    }
    public function routeForReview(int $caseId,array $input,string $key):array{return $this->transition($caseId,array('open'),'review_required','route_for_review',$input,$key);}
    public function dismiss(int $caseId,array $input,string $key):array{return $this->transition($caseId,array('open','review_required'),'dismissed','dismiss',$input,$key);}
    public function resolve(int $caseId,array $input,string $key):array{
        RecurringSupport::requireCapability(self::CAPABILITY);
        $actor=RecurringSupport::actor();
        $evidence=RecurringSupport::evidence($input);
        $note=(string)($input['resolution_note']??'');
        $digest=RecurringSupport::keyString($key);
        $payload=RecurringIdempotency::payload(array('domain'=>RecurringRule::DOMAIN,'operation'=>'resolve','refund_review_id'=>$caseId,'resolution_note'=>$note,'evidence_reference_digest'=>$evidence['digest']));
        $this->repository->begin();
        try{
            RecurringSupport::guardAggregate('refund_review',$caseId,$actor);
            if($winner=$this->repository->command($digest)){$result=$this->replay($winner,$payload);$this->repository->commit();return $result;}
            $case=$this->repository->find($caseId,true);
            if(!$case||(string)$case->state!=='review_required')throw new \InvalidArgumentException('invalid_refund_review_state');
            $now=RecurringSupport::now();
            $this->repository->updateCase($caseId,(int)$case->refund_review_version,array('state'=>'resolved','resolution_note'=>$note===''?null:$note),$now,$actor);
            $this->repository->insertEvent(array(
                'uid'=>Identifier::uid(),'refund_review_id'=>$caseId,'event_sequence'=>$this->repository->nextSequence($caseId),'event_type'=>'resolved',
                'from_state'=>'review_required','to_state'=>'resolved','reason_code'=>'resolved','evidence_channel'=>$evidence['channel'],
                'evidence_reference_digest'=>$evidence['digest'],'evidence_at'=>$evidence['at'],'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>RecurringRule::DOMAIN,'operation'=>'resolve',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'refund_review_id'=>$caseId,
                'result_state'=>'resolved','result_id'=>$caseId,'created_at'=>$now,'created_by'=>$actor,
            ));
            // The human decision is recorded; `academic_consequence` stays NULL until the product
            // decision exists, so no funded-session clawback or settlement mutation is performed.
            RecurringSupport::publishIntent('refund_review',$caseId,'REFUND_RESOLVED',$actor);
            RecurringSupport::hook('dzn_phase_2a2r2_after_refund_review_event_insert','resolve',$caseId);
            $this->repository->commit();
            return array('refund_review_id'=>$caseId,'state'=>'resolved','academic_consequence'=>null,'created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload);
            throw $e;
        }
    }

    private function transition(int $caseId,array $from,string $to,string $operation,array $input,string $key):array{
        RecurringSupport::requireCapability(self::CAPABILITY);
        $actor=RecurringSupport::actor();
        $evidence=RecurringSupport::evidence($input);
        $digest=RecurringSupport::keyString($key);
        $payload=RecurringIdempotency::payload(array('domain'=>RecurringRule::DOMAIN,'operation'=>$operation,'refund_review_id'=>$caseId,'evidence_reference_digest'=>$evidence['digest']));
        $this->repository->begin();
        try{
            RecurringSupport::guardAggregate('refund_review',$caseId,$actor);
            if($winner=$this->repository->command($digest)){$result=$this->replay($winner,$payload);$this->repository->commit();return $result;}
            $case=$this->repository->find($caseId,true);
            if(!$case||!in_array((string)$case->state,$from,true))throw new \InvalidArgumentException('invalid_refund_review_state');
            $now=RecurringSupport::now();
            $this->repository->updateCase($caseId,(int)$case->refund_review_version,array('state'=>$to),$now,$actor);
            $this->repository->insertEvent(array(
                'uid'=>Identifier::uid(),'refund_review_id'=>$caseId,'event_sequence'=>$this->repository->nextSequence($caseId),'event_type'=>$to,
                'from_state'=>(string)$case->state,'to_state'=>$to,'reason_code'=>$operation,'evidence_channel'=>$evidence['channel'],
                'evidence_reference_digest'=>$evidence['digest'],'evidence_at'=>$evidence['at'],'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>RecurringRule::DOMAIN,'operation'=>$operation,
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'refund_review_id'=>$caseId,
                'result_state'=>$to,'result_id'=>$caseId,'created_at'=>$now,'created_by'=>$actor,
            ));
            if($operation==='route_for_review')RecurringSupport::publishIntent('refund_review',$caseId,'REFUND_REVIEW_REQUIRED',$actor);
            RecurringSupport::hook('dzn_phase_2a2r2_after_refund_review_event_insert',$operation,$caseId);
            $this->repository->commit();
            return array('refund_review_id'=>$caseId,'state'=>$to,'academic_consequence'=>null,'created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload);
            throw $e;
        }
    }

    private function replay(object $command,string $payload):array{
        if(!hash_equals((string)$command->command_payload_digest,$payload))throw new \RuntimeException('Idempotency conflict');
        if((string)$command->command_domain!==RecurringRule::DOMAIN)throw new \RuntimeException('Contaminated refund review command');
        $case=$this->repository->find((int)$command->result_id);
        if(!$case||(string)$case->state!==(string)$command->result_state)throw new \RuntimeException('Contaminated refund review result');
        return array('refund_review_id'=>(int)$case->id,'state'=>(string)$case->state,'academic_consequence'=>null,'created'=>false,'idempotent'=>true);
    }
    /**
     * The reviewed purchase must exist and carry the recorded currency, the obligation must be that
     * purchase's own obligation, and the evidence must be that obligation's accepted payment evidence.
     *
     * The reviewed kind, amount and currency are *adopted from* the stored evidence, never asserted:
     * the evidence kind must equal the reviewed kind, its amount and currency must be present and
     * exact, and a caller-supplied amount or currency that disagrees with the evidence is refused
     * closed. A caller can therefore neither invent a refund sum nor describe a reversal that R1
     * never represented.
     *
     * An evidence row may legitimately name no purchase: R1 writes the evidence of the settlement
     * that *establishes* a purchase before the purchase row exists, so the purchase identity is
     * proven through the obligation's offer instead. When the evidence does name a purchase it must
     * be this one.
     */
    private function assertEvidenceOwnership(int $purchaseId,int $obligationId,int $evidenceId,string $kind,array $input):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $purchase=$wpdb->get_row($wpdb->prepare("SELECT id,offer_id,currency FROM {$p}commercial_purchases WHERE id=%d",$purchaseId));
        if(!$purchase)throw new \InvalidArgumentException('commercial_purchase_required');
        $currency=RecurringSupport::currency((string)$purchase->currency);
        $obligation=$wpdb->get_row($wpdb->prepare("SELECT id,currency FROM {$p}commercial_offer_obligations WHERE id=%d AND offer_id=%d",$obligationId,(int)$purchase->offer_id));
        if(!$obligation||(string)$obligation->currency!==$currency)throw new \InvalidArgumentException('refund_review_evidence_conflict');
        $evidence=$wpdb->get_row($wpdb->prepare("SELECT id,purchase_id,processing_state,evidence_kind,amount_minor,currency FROM {$p}commercial_payment_evidence WHERE id=%d AND obligation_id=%d",$evidenceId,$obligationId));
        if(!$evidence||($evidence->purchase_id!==null&&(int)$evidence->purchase_id!==$purchaseId))throw new \InvalidArgumentException('refund_review_evidence_conflict');
        if((string)$evidence->processing_state!=='accepted')throw new \InvalidArgumentException('accepted_payment_evidence_required');
        if((string)$evidence->evidence_kind!==$kind)throw new \InvalidArgumentException('refund_review_evidence_conflict');
        if($evidence->amount_minor===null||(string)$evidence->currency!==$currency)throw new \InvalidArgumentException('refund_review_evidence_conflict');
        $amount=RecurringSupport::amount($evidence->amount_minor,'refund_review_evidence_conflict');
        $supplied=(string)($input['currency']??'');
        if($supplied!==''&&RecurringSupport::currency($supplied)!==$currency)throw new \InvalidArgumentException('refund_review_evidence_conflict');
        if(($input['amount_minor']??null)!==null&&$input['amount_minor']!==''&&RecurringSupport::amount($input['amount_minor'],'refund_review_amount_conflict')!==$amount)throw new \InvalidArgumentException('refund_review_amount_conflict');
        return array('amount_minor'=>$amount,'currency'=>$currency);
    }
}
