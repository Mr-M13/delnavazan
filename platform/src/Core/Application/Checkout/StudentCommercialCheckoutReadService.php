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
            CommercialLineageValidator::assertForOffer($offer,false,$this->authority);
            $canPay=in_array((string)$offer->state,array('issued','accepted'),true)
                &&($offer->expires_at===null||(string)$offer->expires_at>$now);
            foreach($this->authority->obligationsForOffer((int)$offer->id) as $obligation){
                $settlement=$this->payments->settlementForObligation((int)$obligation->id);
                $active=$settlement?null:$this->sessions->activeForObligation((int)$obligation->id);
                $checkoutState='unavailable';$paymentState='unavailable';$action=null;$attemptUid=null;
                if($settlement){
                    $checkoutState='completed';$paymentState='paid';
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
                    'obligation_sequence'=>(int)$obligation->obligation_sequence,
                    'sessions_covered'=>(int)$obligation->sessions_covered,
                    'amount_minor'=>(int)$obligation->amount_minor,'currency'=>(string)$obligation->currency,
                    'due_at'=>$obligation->due_at===null?null:(string)$obligation->due_at,
                    'offer_expires_at'=>$offer->expires_at===null?null:(string)$offer->expires_at,
                    'payment_state'=>$paymentState,'checkout_state'=>$checkoutState,'action'=>$action,
                    'retry_allowed'=>in_array($action,array('pay','resume','retry'),true),
                );
            }
        }
        return array('state'=>'ok','obligations'=>$rows);
    }
}
