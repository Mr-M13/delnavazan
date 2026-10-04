<?php
namespace Delnavazan\Platform\Core\Application\Checkout;

use Delnavazan\Platform\Core\Application\{CommercialPaymentService,CommercialSupport};
use Delnavazan\Platform\Core\Application\PaymentExecution\{PaymentExecutionIdempotency,PaymentExecutionSupport,PaymentExecutionWorkerContext};
use Delnavazan\Platform\Core\Infrastructure\Repository\{CheckoutSessionRepository,CommercialAuthorityRepository,CommercialPaymentRepository};
use Delnavazan\Platform\Integrations\Payment\Stripe\StripeCheckoutAdapter;

/** Recover a missed Stripe Checkout success only from a locally owned attempt and exact provider event. */
final class StripeCheckoutReconciliationService {
    public function __construct(
        private ?CheckoutSessionRepository $sessions=null,
        private ?CheckoutSessionReferenceService $references=null,
        private ?CommercialAuthorityRepository $authority=null,
        private ?CommercialPaymentRepository $paymentsRepository=null,
        private ?StripeCheckoutAdapter $stripe=null,
        private ?CommercialPaymentService $payments=null,
        private ?PaymentExecutionWorkerContext $worker=null
    ){
        $this->sessions??=new CheckoutSessionRepository();
        $this->references??=new CheckoutSessionReferenceService();
        $this->authority??=new CommercialAuthorityRepository();
        $this->paymentsRepository??=new CommercialPaymentRepository();
        $this->stripe??=new StripeCheckoutAdapter();
        $this->payments??=new CommercialPaymentService();
        $this->worker??=new PaymentExecutionWorkerContext();
    }

    /** @return array{state:string,reason_code:?string} */
    public function reconcile(array $input):array{
        PaymentExecutionSupport::requireCapability('dzn_manage_payment_execution');
        PaymentExecutionSupport::actor();
        if(array_keys($input)!==array('attempt_uid'))throw new \InvalidArgumentException('checkout_reconciliation_unavailable');
        $attemptUid=trim((string)($input['attempt_uid']??''));
        if(preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D',$attemptUid)!==1)throw new \InvalidArgumentException('checkout_reconciliation_unavailable');
        $session=$this->sessions->byUid($attemptUid);
        if(!$session||(string)$session->provider_key!=='stripe'||!in_array((string)$session->state,array('creating','open','completed','expired'),true))return $this->unresolved('checkout_attempt_unavailable');
        $stored=$this->references->references((int)$session->id);
        if(!$stored)return $this->unresolved('provider_reference_unavailable');
        $providerReference=(string)($stored['provider_object_reference']??'');
        if(preg_match('/^cs_test_[A-Za-z0-9]+$/D',$providerReference)!==1
            ||!hash_equals((string)$session->provider_reference_digest,PaymentExecutionIdempotency::reference($providerReference)))return $this->unresolved('provider_reference_unavailable');

        $obligation=$this->authority->obligation((int)$session->obligation_id);
        $offer=$obligation?$this->authority->offer((int)$obligation->offer_id):null;
        if(!$obligation||!$offer||(int)$obligation->id!==(int)$session->obligation_id||(int)$obligation->offer_id!==(int)$session->offer_id
            ||(int)$offer->id!==(int)$session->offer_id||(int)$offer->beneficiary_student_id!==(int)$session->student_id
            ||(int)$session->amount_minor!==(int)$obligation->amount_minor||strtoupper((string)$session->currency)!==strtoupper((string)$obligation->currency))return $this->unresolved('checkout_authority_mismatch');
        $obligationReference=CommercialSupport::obligationReference((string)$offer->uid,(int)$obligation->obligation_sequence);

        $provider=$this->stripe->retrieve($providerReference);
        if(($provider['state']??'')!=='complete'||($provider['payment_state']??'')!=='paid')return $this->unresolved((string)($provider['reason_code']??'provider_payment_unconfirmed'));
        if(!hash_equals($providerReference,(string)($provider['provider_reference']??''))
            ||!$this->sameProviderCorrelation($provider,$attemptUid,$obligationReference))return $this->unresolved('provider_session_mismatch');
        $event=$this->stripe->completionEvent($providerReference,(int)$provider['session_created_at']);
        if(($event['state']??'')!=='found')return $this->unresolved((string)($event['reason_code']??'provider_success_event_unavailable'));
        if(!$this->sameProviderCorrelation($event,$attemptUid,$obligationReference)
            ||!hash_equals((string)$provider['account_reference'],(string)$event['account_reference'])
            ||(int)($event['amount_minor']??-1)!==(int)($provider['amount_minor']??-2)
            ||strtoupper((string)($event['currency']??''))!==strtoupper((string)($provider['currency']??''))
            ||!is_string($event['provider_reference']??null)||preg_match('/^evt_[A-Za-z0-9]+$/D',$event['provider_reference'])!==1
            ||!is_string($event['provider_occurred_at']??null))return $this->unresolved('provider_event_mismatch');

        // Stripe's exact paid completion Event proves this provider Session is terminal even if R1
        // later rejects the payment against current commercial authority.
        $this->markCompleted($session);

        $eventId=$event['provider_reference'];
        $result=$this->worker->run(fn():array=>$this->payments->ingest(array(
            'provider_key'=>'stripe','provider_reference'=>$eventId,'evidence_kind'=>'success',
            'amount_minor'=>(int)$event['amount_minor'],'currency'=>(string)$event['currency'],
            'obligation_reference'=>$obligationReference,'provider_occurred_at'=>(string)$event['provider_occurred_at'],
            'provider_account_reference'=>(string)$event['account_reference'],'evidence_channel'=>'provider_evidence',
            'evidence_at'=>gmdate('Y-m-d H:i:s'),'evidence_reference'=>'dzn_checkout_reconcile:'.$eventId,
        ),'dzn_stripe_checkout_reconcile:'.$eventId));
        if(($result['processing_state']??'')!=='accepted'||($result['conflicting']??false)===true)return $this->unresolved((string)($result['reason_code']??'commercial_evidence_not_accepted'));
        if(!$this->paymentsRepository->settlementForObligation((int)$session->obligation_id))return $this->unresolved('canonical_settlement_not_observed');
        return array('state'=>'settled','reason_code'=>null);
    }

    /** Amount and currency pass unchanged to R1, which alone accepts or records their mismatch. */
    private function sameProviderCorrelation(array $facts,string $attemptUid,string $obligationReference):bool{
        return hash_equals($attemptUid,(string)($facts['attempt_uid']??''))
            &&hash_equals($obligationReference,(string)($facts['obligation_reference']??''))
            &&is_string($facts['account_reference']??null)&&trim($facts['account_reference'])!=='';
    }

    private function unresolved(string $reason):array{
        if(preg_match('/^[a-z0-9_]{1,64}$/D',$reason)!==1)$reason='provider_reconciliation_unavailable';
        return array('state'=>'unresolved','reason_code'=>$reason);
    }

    private function markCompleted(object $session):void{
        $this->authority->begin();
        try{
            $actor=PaymentExecutionSupport::actor();
            $this->authority->lockAccountRoot((int)$session->student_id,$actor);
            $active=$this->sessions->activeForObligation((int)$session->obligation_id,true);
            if($active&&(int)$active->id===(int)$session->id)
                $this->sessions->close((int)$session->id,'completed','provider_payment_confirmed',gmdate('Y-m-d H:i:s'));
            $this->authority->commit();
        }catch(\Throwable $error){
            $this->authority->rollback();
            throw $error;
        }
    }
}
