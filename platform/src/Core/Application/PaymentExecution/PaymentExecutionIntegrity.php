<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

/**
 * Fail-closed Phase 2A.2-T aggregate proof (contract §8.2, §9.5, §12.3, §12.4, §14).
 *
 * Every Phase-T aggregate is proved before it is returned as authority: a command is immutable with no
 * terminal column of its own, the dispatch claim is the only mutable execution row, [C9-1] the per-event
 * decision claim is the only mutable intake row, a result names its own attempt exactly once, and an
 * append-only receipt/event/decision row carries only controlled vocabulary. A malformed aggregate is
 * refused, never repaired and never presented as authority.
 */
final class PaymentExecutionIntegrity {
    /** The effective command state, derived — never stored twice (rule 1). */
    public static function commandState(?object $command,?object $claim,?object $result):string{
        if(!$command)throw new \RuntimeException('payment_execution_command_required');
        if($result)return (string)$result->result_state;
        if($claim&&PaymentExecutionRule::member((string)$claim->dispatch_state,array('claimed','in_flight')))return 'dispatching';
        // A committed command with neither a result row nor a live claim is corruption (rule 2).
        throw new \RuntimeException('payment_execution_command_unowned');
    }

    /** The operation-specific selector shape of §8.1: owned selectors equal, unowned selectors NULL. */
    public static function commandShape(object $command):void{
        $operation=(string)$command->operation;
        if(PaymentExecutionRule::operation($operation)===null)throw new \RuntimeException('payment_execution_command_required');
        $selector=function(string $field)use($command):?int{return $command->{$field}===null?null:(int)$command->{$field};};
        foreach(array('student_id','provider_account_id','obligation_id') as $field)if($selector($field)===null||$selector($field)<1)throw new \RuntimeException('payment_execution_command_contaminated');
        if($operation==='submit_collection'){
            foreach(array('purchase_id','collection_intent_id','renewal_cycle_id') as $field)if($selector($field)===null||$selector($field)<1)throw new \RuntimeException('payment_execution_command_contaminated');
        }else{
            foreach(array('collection_intent_id') as $field)if($selector($field)===null||$selector($field)<1)throw new \RuntimeException('payment_execution_command_contaminated');
            // A foreign-but-valid identifier in an unowned selector is contamination, not evidence.
            foreach(array('purchase_id','renewal_cycle_id') as $field)if($selector($field)!==null)throw new \RuntimeException('payment_execution_command_contaminated');
        }
        if(PaymentExecutionRule::provider((string)$command->provider_key)===null)throw new \RuntimeException('payment_execution_command_contaminated');
        if(PaymentExecutionRule::mode((string)$command->mode)===null)throw new \RuntimeException('payment_execution_command_contaminated');
        if((int)$command->amount_minor<0||preg_match('/^[A-Z]{3}$/D',(string)$command->currency)!==1)throw new \RuntimeException('payment_execution_command_contaminated');
        if(!PaymentExecutionSupport::digestOrNull($command->provider_reference_digest))throw new \RuntimeException('payment_execution_command_contaminated');
    }

    /** A command row is insert-once, updateless and carries no terminal column of its own ([C2-1]). */
    public static function commandRowIsImmutable(array $columns):void{
        foreach(array('updated_at','result_state','result_id','updated_by') as $forbidden)if(in_array($forbidden,$columns,true))throw new \RuntimeException('payment_execution_command_mutable');
    }

    /** Rule 3/5: the claim is the only mutable execution row, with one live claim per subject ([C3-1]). */
    public static function dispatchClaim(object $claim,?object $result):void{
        $state=(string)$claim->dispatch_state;
        if(!PaymentExecutionRule::member($state,PaymentExecutionRule::DISPATCH_STATES))throw new \RuntimeException('payment_execution_dispatch_corrupt');
        $generation=(int)$claim->claim_generation;
        if($generation<1)throw new \RuntimeException('payment_execution_dispatch_corrupt');
        $lease=$claim->lease_expires_at===null?null:(string)$claim->lease_expires_at;
        if($state==='in_flight'&&$lease===null)throw new \RuntimeException('payment_execution_dispatch_corrupt');
        if(($state==='claimed'||$state==='released')&&$lease!==null)throw new \RuntimeException('payment_execution_dispatch_corrupt');
        $slot=$claim->active_claim_slot===null?null:(int)$claim->active_claim_slot;
        if(PaymentExecutionRule::terminalDispatchState($state)){if($slot!==null)throw new \RuntimeException('payment_execution_dispatch_corrupt');}
        elseif($slot!==1)throw new \RuntimeException('payment_execution_dispatch_corrupt');
        foreach(array('idempotency_key_digest','claim_token_digest','descriptor_digest') as $column){
            $value=(string)$claim->{$column};
            if(preg_match('/^[0-9a-f]{64}$/D',$value)!==1)throw new \RuntimeException('payment_execution_dispatch_corrupt');
        }
        foreach(array('descriptor_cipher_version','descriptor_key_version','descriptor_nonce','descriptor_ciphertext') as $column){
            if(trim((string)$claim->{$column})==='')throw new \RuntimeException('payment_execution_dispatch_descriptor_incomplete');
        }
        $descriptor=new ProviderDispatchDescriptor(
            (string)$claim->descriptor_cipher_version,(string)$claim->descriptor_key_version,
            (string)$claim->descriptor_nonce,(string)$claim->descriptor_ciphertext,(string)$claim->descriptor_digest
        );
        if(!$descriptor->complete())throw new \RuntimeException('payment_execution_dispatch_descriptor_incomplete');
        if(!hash_equals((string)$claim->descriptor_digest,PaymentExecutionDispatchSeal::digest($descriptor)))throw new \RuntimeException('payment_execution_dispatch_descriptor_tampered');
        // Rule 5 ([C5-1]): one claim/result pairing is permitted, and only one.
        if($result&&!($state==='released'&&(string)$result->result_state==='refused'&&(string)$result->reason_code==='dispatch_descriptor_unavailable')){
            throw new \RuntimeException('payment_execution_dispatch_corrupt');
        }
    }

    /** Rule 3/4: exactly one result per command; `completed` names this command's own single attempt. */
    public static function result(?object $result,?object $attempt,int $commandId):void{
        if(!$result)throw new \RuntimeException('payment_execution_result_required');
        $state=(string)$result->result_state;
        if(!PaymentExecutionRule::member($state,PaymentExecutionRule::RESULT_STATES))throw new \RuntimeException('payment_execution_result_corrupt');
        if((int)$result->execution_command_id!==$commandId)throw new \RuntimeException('payment_execution_result_corrupt');
        if($state==='completed'){
            if(!$attempt||(int)$attempt->execution_command_id!==$commandId)throw new \RuntimeException('payment_execution_result_corrupt');
            if((int)$result->result_id!==(int)$attempt->id)throw new \RuntimeException('payment_execution_result_corrupt');
            if(!PaymentExecutionRule::member((string)$attempt->outcome_state,PaymentExecutionRule::OUTCOME_STATES))throw new \RuntimeException('payment_execution_result_corrupt');
            if(!PaymentExecutionRule::member((string)$attempt->outcome_reason_code,PaymentExecutionRule::ATTEMPT_REASONS))throw new \RuntimeException('payment_execution_result_corrupt');
            if((int)$attempt->attempt_sequence!==1)throw new \RuntimeException('payment_execution_result_corrupt');
        }else{
            if($result->result_id!==null)throw new \RuntimeException('payment_execution_result_corrupt');
            $reason=(string)$result->reason_code;
            if($state==='refused'&&$reason!==PaymentExecutionRule::COMMAND_CONFLICT_REASON&&!PaymentExecutionRule::member($reason,PaymentExecutionRule::ATTEMPT_REASONS))throw new \RuntimeException('payment_execution_result_corrupt');
            if($state==='conflicted'&&$reason!==PaymentExecutionRule::COMMAND_CONFLICT_REASON)throw new \RuntimeException('payment_execution_result_corrupt');
        }
    }

    /** An append-only attempt row: sequence 1, controlled vocabulary, no raw provider status string. */
    public static function attempt(object $attempt):void{
        if((string)$attempt->attempt_sequence!=='1'&&(int)$attempt->attempt_sequence!==1)throw new \RuntimeException('payment_execution_attempt_corrupt');
        if(!PaymentExecutionRule::member((string)$attempt->outcome_state,PaymentExecutionRule::OUTCOME_STATES))throw new \RuntimeException('payment_execution_attempt_corrupt');
        if((string)$attempt->outcome_reason_code!==''&&!PaymentExecutionRule::member((string)$attempt->outcome_reason_code,PaymentExecutionRule::ATTEMPT_REASONS))throw new \RuntimeException('payment_execution_attempt_corrupt');
        if(!PaymentExecutionSupport::digestOrNull($attempt->provider_reference_digest))throw new \RuntimeException('payment_execution_attempt_corrupt');
    }

    public static function receipt(object $receipt):void{
        if(!PaymentExecutionRule::member((string)$receipt->verification_state,PaymentExecutionRule::VERIFICATION_STATES))throw new \RuntimeException('payment_event_receipt_corrupt');
        $reason=$receipt->refusal_reason_code===null?null:(string)$receipt->refusal_reason_code;
        if($reason!==null&&!PaymentExecutionRule::member($reason,PaymentExecutionRule::WEBHOOK_REASONS))throw new \RuntimeException('payment_event_receipt_corrupt');
        if((string)$receipt->verification_state==='refused'&&$reason===null)throw new \RuntimeException('payment_event_receipt_corrupt');
        foreach(array('request_digest') as $column)if(preg_match('/^[0-9a-f]{64}$/D',(string)$receipt->{$column})!==1)throw new \RuntimeException('payment_event_receipt_corrupt');
        foreach(array('signature_digest','account_selector_digest','source_digest') as $column)if(!PaymentExecutionSupport::digestOrNull($receipt->{$column}))throw new \RuntimeException('payment_event_receipt_corrupt');
    }

    public static function event(object $event):void{
        if(!PaymentExecutionRule::member((string)$event->event_type,PaymentExecutionRule::EVENT_TYPES))throw new \RuntimeException('payment_event_corrupt');
        foreach(array('event_reference_digest','event_fact_digest','raw_type_digest','payload_digest') as $column)
            if(preg_match('/^[0-9a-f]{64}$/D',(string)$event->{$column})!==1)throw new \RuntimeException('payment_event_corrupt');
        if((int)$event->payment_provider_account_id<1)throw new \RuntimeException('payment_event_corrupt');
    }

    public static function decision(object $decision):void{
        if(!PaymentExecutionRule::member((string)$decision->decision_state,PaymentExecutionRule::DECISION_STATES))throw new \RuntimeException('payment_event_decision_corrupt');
        $reason=$decision->reason_code===null?null:(string)$decision->reason_code;
        if($reason!==null&&!PaymentExecutionRule::member($reason,PaymentExecutionRule::DECISION_REASONS))throw new \RuntimeException('payment_event_decision_corrupt');
        $consequence=$decision->r2_consequence_state===null?null:(string)$decision->r2_consequence_state;
        if($consequence!==null&&!PaymentExecutionRule::member($consequence,PaymentExecutionRule::R2_CONSEQUENCE_STATES))throw new \RuntimeException('payment_event_decision_corrupt');
        $consequenceReason=$decision->r2_reason_code===null?null:(string)$decision->r2_reason_code;
        if($consequenceReason!==null&&!PaymentExecutionRule::member($consequenceReason,PaymentExecutionRule::R2_CONSEQUENCE_REASONS))throw new \RuntimeException('payment_event_decision_corrupt');
        if((int)$decision->decision_sequence<1)throw new \RuntimeException('payment_event_decision_corrupt');
    }

    /**
     * [C9-1]/[C9-2] The per-event decision claim: the phase's own mutable intake row, with its exact shape.
     *
     * A live `claimed` row holds the event's only claim slot under a lease and carries a token and a
     * positive generation; a terminal `settled`/`released` row has released the slot, cleared its lease
     * and recorded the instant it ended. Anything else — an unknown state, a missing or extra live slot,
     * a lease on a terminal row, a missing lease on a live row, a malformed token or a non-positive
     * generation — is refused, never repaired and never treated as ownership of a decision.
     */
    public static function decisionClaim(object $claim):void{
        $state=(string)$claim->claim_state;
        if(!PaymentExecutionRule::member($state,PaymentExecutionRule::DECISION_CLAIM_STATES))throw new \RuntimeException('payment_event_decision_claim_corrupt');
        if((int)$claim->claim_generation<1)throw new \RuntimeException('payment_event_decision_claim_corrupt');
        if(preg_match('/^[0-9a-f]{64}$/D',(string)$claim->claim_token_digest)!==1)throw new \RuntimeException('payment_event_decision_claim_corrupt');
        $slot=$claim->active_claim_slot===null?null:(int)$claim->active_claim_slot;
        $lease=$claim->lease_expires_at===null?null:(string)$claim->lease_expires_at;
        $settled=$claim->settled_at===null?null:(string)$claim->settled_at;
        if($state==='claimed'){
            if($slot!==1||$lease===null||$settled!==null)throw new \RuntimeException('payment_event_decision_claim_corrupt');
            return;
        }
        if($slot!==null||$lease!==null||$settled===null)throw new \RuntimeException('payment_event_decision_claim_corrupt');
    }

    public static function account(object $account):void{
        if(PaymentExecutionRule::provider((string)$account->provider_key)===null)throw new \RuntimeException('payment_provider_account_corrupt');
        if(PaymentExecutionRule::mode((string)$account->mode)===null)throw new \RuntimeException('payment_provider_account_corrupt');
        if(!PaymentExecutionRule::member((string)$account->state,PaymentExecutionRule::ACCOUNT_STATES))throw new \RuntimeException('payment_provider_account_corrupt');
        if(!PaymentExecutionRule::member((string)$account->execution_state,PaymentExecutionRule::EXECUTION_STATES))throw new \RuntimeException('payment_provider_account_corrupt');
        if(!PaymentExecutionRule::member((string)$account->credential_state,PaymentExecutionRule::CREDENTIAL_STATES))throw new \RuntimeException('payment_provider_account_corrupt');
        if(preg_match('/^[0-9a-f]{64}$/D',(string)$account->account_reference_digest)!==1)throw new \RuntimeException('payment_provider_account_corrupt');
    }

    public static function mapping(object $object):void{
        if(PaymentExecutionRule::member((string)$object->object_kind,PaymentExecutionRule::OBJECT_KINDS)===false)throw new \RuntimeException('payment_provider_object_corrupt');
        if(PaymentExecutionRule::member((string)$object->canonical_kind,PaymentExecutionRule::CANONICAL_KINDS)===false)throw new \RuntimeException('payment_provider_object_corrupt');
        if(!PaymentExecutionRule::member((string)$object->state,PaymentExecutionRule::OBJECT_STATES))throw new \RuntimeException('payment_provider_object_corrupt');
        if(preg_match('/^[0-9a-f]{64}$/D',(string)$object->object_reference_digest)!==1)throw new \RuntimeException('payment_provider_object_corrupt');
        if((int)$object->canonical_id<1)throw new \RuntimeException('payment_provider_object_corrupt');
    }
}
