<?php
namespace Delnavazan\Platform\Core\Application\Checkout;

use Delnavazan\Platform\Core\Application\{CommercialIdempotency,CommercialSupport};
use Delnavazan\Platform\Core\Infrastructure\Repository\{CheckoutSessionRepository,CommercialAuthorityRepository,CommercialPaymentRepository};
use Delnavazan\Platform\Core\Support\Identifier;
use Delnavazan\Platform\Portals\PortalPrincipalResolver;

/**
 * Authenticated customer-checkout authority.
 * Browser input identifies an obligation only; all commercial facts are re-derived under lock.
 */
final class CheckoutInitiationService {
    public function __construct(
        private ?CommercialAuthorityRepository $commercial=null,
        private ?CommercialPaymentRepository $payments=null,
        private ?CheckoutSessionRepository $sessions=null,
        private ?PortalPrincipalResolver $principals=null
    ){
        $this->commercial??=new CommercialAuthorityRepository();
        $this->payments??=new CommercialPaymentRepository();
        $this->sessions??=new CheckoutSessionRepository();
        $this->principals??=new PortalPrincipalResolver();
    }

    public function initiate(int $obligationId):array{
        if($obligationId<1)throw new \InvalidArgumentException('checkout_obligation_required');
        $principal=$this->principals->resolve('student');
        $studentId=(int)$principal['id'];
        $actor=CommercialSupport::actor('Checkout actor unavailable');
        $now=CommercialSupport::now();
        $requestDigest=CommercialIdempotency::key('checkout:'.$studentId.':'.$obligationId);
        $this->commercial->begin();
        try{
            // Commercial account root is always the first commercial lock. Checkout rows come after it.
            $this->commercial->lockAccountRoot($studentId,$actor);
            $obligation=$this->commercial->obligation($obligationId,true);
            if(!$obligation)throw new \InvalidArgumentException('checkout_unavailable');
            $offer=$this->commercial->offer((int)$obligation->offer_id,true);
            if(!$offer||(int)$offer->beneficiary_student_id!==$studentId)throw new \InvalidArgumentException('checkout_unavailable');
            if(!in_array((string)$offer->state,array('issued','accepted'),true))throw new \InvalidArgumentException('checkout_unavailable');
            if($offer->expires_at!==null&&(string)$offer->expires_at<=$now)throw new \InvalidArgumentException('checkout_unavailable');
            if((int)$obligation->amount_minor<1||(string)$obligation->currency!==strtoupper((string)$obligation->currency))throw new \InvalidArgumentException('checkout_unavailable');
            if($this->payments->settlementForObligation($obligationId,true))throw new \InvalidArgumentException('checkout_unavailable');

            $active=$this->sessions->activeForObligation($obligationId,true);
            if($active){
                if((int)$active->student_id!==$studentId||(int)$active->offer_id!==(int)$offer->id||(int)$active->amount_minor!==(int)$obligation->amount_minor||(string)$active->currency!==(string)$obligation->currency)throw new \RuntimeException('checkout_authority_conflict');
                if($active->expires_at!==null&&(string)$active->expires_at<=$now){
                    $this->sessions->close((int)$active->id,'expired','authority_expired',$now);
                }else{
                    $this->commercial->commit();
                    return $this->outcome($active,false);
                }
            }

            $id=$this->sessions->insert(array(
                'uid'=>Identifier::uid(),'student_id'=>$studentId,'offer_id'=>(int)$offer->id,'obligation_id'=>$obligationId,
                'amount_minor'=>(int)$obligation->amount_minor,'currency'=>(string)$obligation->currency,
                'state'=>'creating','active_slot'=>1,'request_key_digest'=>$requestDigest,'provider_key'=>'stripe',
                'provider_reference_digest'=>null,'created_at'=>$now,'created_by'=>$actor,
                'expires_at'=>gmdate('Y-m-d H:i:s',time()+1800),'closed_at'=>null,'close_reason'=>null,
            ));
            $created=$this->sessions->byId($id,true);
            if(!$created)throw new \RuntimeException('checkout_session_persistence_failed');
            $this->commercial->commit();
            return $this->outcome($created,true);
        }catch(\Throwable $e){
            $this->commercial->rollback();
            // The unique active slot is the final concurrent-request arbiter.
            $winner=$this->sessions->activeForObligation($obligationId);
            if($winner&&(int)$winner->student_id===$studentId)return $this->outcome($winner,false);
            throw $e;
        }
    }

    private function outcome(object $session,bool $created):array{
        return array('checkout_uid'=>(string)$session->uid,'state'=>(string)$session->state,'created'=>$created);
    }
}
