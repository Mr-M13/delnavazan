<?php
namespace Delnavazan\Platform\Core\Application\Checkout;

use Delnavazan\Platform\Core\Application\CommercialLineageValidator;
use Delnavazan\Platform\Core\Infrastructure\Repository\{CheckoutSessionRepository,CommercialAuthorityRepository,CommercialPaymentRepository};
use Delnavazan\Platform\Portals\PortalPrincipalResolver;

/** Owner-bound, read-only payment choices for the authenticated Student Portal. */
final class StudentCommercialCheckoutReadService {
    public function __construct(
        private ?CommercialAuthorityRepository $authority=null,
        private ?CommercialPaymentRepository $payments=null,
        private ?CheckoutSessionRepository $sessions=null,
        private ?PortalPrincipalResolver $principals=null
    ){
        $this->authority??=new CommercialAuthorityRepository();
        $this->payments??=new CommercialPaymentRepository();
        $this->sessions??=new CheckoutSessionRepository();
        $this->principals??=new PortalPrincipalResolver();
    }

    /** @return array{state:string,obligations:array<int,array<string,mixed>>} */
    public function read():array{
        $principal=$this->principals->resolve('student');
        $studentId=(int)($principal['id']??0);
        if($studentId<1)throw new \InvalidArgumentException('checkout_unavailable');
        $rows=array();$now=gmdate('Y-m-d H:i:s');
        foreach($this->authority->offersForBeneficiary($studentId) as $offer){
            if((int)$offer->beneficiary_student_id!==$studentId)throw new \RuntimeException('checkout_authority_unavailable');
            // A superseded snapshot is immutable history, never a second payable obligation set.
            // Its successor is the newest offer for the same continuation and is shown instead.
            if((string)$offer->state==='superseded')continue;
            CommercialLineageValidator::assertForOffer($offer,false,$this->authority);
            // An accepted commitment keeps later instalments payable after the initial checkout
            // hold; only an unaccepted issued offer is bounded by its expiry instant.
            $offerState=(string)$offer->state;
            $canPay=in_array($offerState,array('issued','accepted'),true)
                &&($offerState==='accepted'||$offer->expires_at===null||(string)$offer->expires_at>$now);
            $funding=(new \Delnavazan\Platform\Core\Application\CommercialTermFundingService())->obligationStatus((int)$offer->id);
            $settledBySequence=array();
            foreach((array)($funding['obligations']??array()) as $fundingRow)$settledBySequence[(int)$fundingRow['obligation_sequence']]=$fundingRow;
            $termSequence=$this->authority->termSequenceForOffer((int)$offer->id);
            if($termSequence===null&&(string)$offer->source_kind==='continuation_case')$termSequence=1;
            $promotion=$offer->promotion_id===null?null:$this->authority->promotion((int)$offer->promotion_id);
            $promotionAmount=(int)($offer->promotion_amount_minor??0);
            $paidMinor=0;$totalMinor=0;$paidObligations=0;$obligations=$this->authority->obligationsForOffer((int)$offer->id);
            foreach($obligations as $obligation){$totalMinor+=(int)$obligation->amount_minor;if(!empty($settledBySequence[(int)$obligation->obligation_sequence]['settled'])){$paidMinor+=(int)$obligation->amount_minor;$paidObligations++;}}
            foreach($obligations as $obligation){
                $sequence=(int)$obligation->obligation_sequence;
                $prerequisiteSatisfied=$sequence===1||!empty($settledBySequence[$sequence-1]['settled']);
                $settlement=$this->payments->settlementForObligation((int)$obligation->id);
                $active=$settlement?null:$this->sessions->activeForObligation((int)$obligation->id);
                $checkoutState='unavailable';$paymentState='unavailable';$action=null;$attemptUid=null;
                if($settlement){
                    $checkoutState='completed';$paymentState='paid';
                    if(!$prerequisiteSatisfied)$paymentState='paid_prerequisite_pending';
                }elseif(!$prerequisiteSatisfied){
                    $checkoutState='blocked';$paymentState='prerequisite_pending';
                }elseif($canPay&&(int)$obligation->amount_minor>0&&preg_match('/^[A-Z]{3}$/D',(string)$obligation->currency)===1){
                    if(!$active){$checkoutState='not_started';$paymentState='required';$action='pay';}
                    elseif((int)$active->student_id!==$studentId||(int)$active->offer_id!==(int)$offer->id
                        ||(int)$active->amount_minor!==(int)$obligation->amount_minor
                        ||strtoupper((string)$active->currency)!==strtoupper((string)$obligation->currency)){
                        $checkoutState='unavailable';$paymentState='unavailable';
                    }else{
                        $attemptUid=(string)$active->uid;
                        $state=(string)$active->state;
                        $expired=$state==='open'&&$active->expires_at!==null&&(string)$active->expires_at<=$now;
                        if($expired){$checkoutState='expired';$paymentState='required';$action='retry';}
                        elseif($state==='open'){$checkoutState='open';$paymentState='required';$action='continue';}
                        elseif($state==='creating'){$checkoutState='creating';$paymentState='processing';$action='resume';}
                        elseif(in_array($state,array('expired','failed'),true)){$checkoutState=$state;$paymentState='required';$action='retry';}
                        elseif($state==='completed'){$checkoutState='completed';$paymentState='processing';}
                    }
                }
                $rows[]=array(
                    'obligation_uid'=>(string)$obligation->uid,'attempt_uid'=>$attemptUid,
                    'obligation_sequence'=>$sequence,
                    'sessions_from'=>(int)$obligation->sessions_from,
                    'sessions_to'=>(int)$obligation->sessions_to,
                    'sessions_covered'=>(int)$obligation->sessions_covered,
                    'amount_minor'=>(int)$obligation->amount_minor,'currency'=>(string)$obligation->currency,
                    'due_at'=>$obligation->due_at===null?null:(string)$obligation->due_at,
                    // The commitment and plan are display facts already owned by the canonical
                    // offer. They are never echoed back into a checkout command.
                    'offer_uid'=>(string)$offer->uid,
                    'offer_reference'=>$offer->reference_code===null?null:(string)$offer->reference_code,
                    'offer_state'=>(string)$offer->state,
                    'plan_kind'=>(string)$offer->plan_kind,
                    'committed_sessions'=>(int)$offer->committed_sessions,
                    'offer_amount_due_minor'=>(int)$offer->amount_due_minor,
                    'offer_base_amount_minor'=>(int)$offer->base_amount_minor,
                    'offer_discount_total_minor'=>(int)$offer->discount_total_minor,
                    'offer_promotion_amount_minor'=>$promotionAmount,
                    'offer_has_promotion'=>$promotionAmount>0,
                    'offer_promotion_percentage'=>$promotion!==null&&$promotion->percentage_bp!==null?(int)$promotion->percentage_bp/100:null,
                    'offer_promotion_kind'=>$promotion===null?null:(string)$promotion->kind,
                    'offer_currency'=>(string)$offer->currency,
                    'offer_expires_at'=>$offer->expires_at===null?null:(string)$offer->expires_at,
                    'term_sequence'=>$termSequence,
                    'term_paid'=>$totalMinor>0&&$paidMinor>=$totalMinor,
                    'paid_minor'=>$paidMinor,
                    'outstanding_minor'=>max(0,$totalMinor-$paidMinor),
                    'paid_instalments'=>$paidObligations,
                    'total_instalments'=>count($obligations),
                    'prerequisite_satisfied'=>$prerequisiteSatisfied,
                    'effective'=>$sequence===1?(!empty($settledBySequence[$sequence]['settled'])):($prerequisiteSatisfied&&!empty($settledBySequence[$sequence]['settled'])),
                    'payment_state'=>$paymentState,'checkout_state'=>$checkoutState,'action'=>$action,
                    'retry_allowed'=>in_array($action,array('pay','resume','retry'),true),
                );
            }
        }
        return array('state'=>'ok','obligations'=>$rows);
    }
}
