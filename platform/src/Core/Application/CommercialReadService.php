<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CommercialAuthorityRepository,CommercialCapacityRepository,CommercialPaymentRepository};

/**
 * Privacy-minimised commercial read seam.
 *
 * Portal presentation must consume canonical read models like this one; no portal, Theme or client
 * may determine payment success, price, discount eligibility, subscription/enrolment truth,
 * entitlement or slot authority for itself.
 */
final class CommercialReadService {
    private const VIEW='dzn_view_commercial_authority';
    public function __construct(
        private ?CommercialAuthorityRepository $authority=null,
        private ?CommercialCapacityRepository $capacity=null,
        private ?CommercialPaymentRepository $payments=null,
        private ?CommercialPolicyService $policies=null,
        private ?CommercialTermFundingService $funding=null
    ){
        $this->authority??=new CommercialAuthorityRepository();
        $this->capacity??=new CommercialCapacityRepository();
        $this->payments??=new CommercialPaymentRepository();
        $this->policies??=new CommercialPolicyService($this->authority);
        $this->funding??=new CommercialTermFundingService($this->authority,$this->payments);
    }

    /** The authoritative commercial state of one Student, without any provider payload. */
    public function forStudent(int $studentId):array{
        $this->requireView();
        $purchases=array();$entitlements=array();
        foreach($this->authority->purchasesForBeneficiary($studentId) as $purchase)$purchases[]=array(
            'purchase_id'=>(int)$purchase->id,'offer_id'=>(int)$purchase->offer_id,'state'=>(string)$purchase->state,
            'reconciliation_state'=>(string)$purchase->reconciliation_state,'currency'=>(string)$purchase->currency,
            'amount_minor'=>(int)$purchase->amount_minor,'plan_kind'=>(string)$purchase->plan_kind,
            'accepted_at'=>(string)$purchase->accepted_at,
        );
        foreach($this->authority->entitlementsForBeneficiary($studentId) as $entitlement)$entitlements[]=array(
            'entitlement_id'=>(int)$entitlement->id,'purchase_id'=>(int)$entitlement->purchase_id,
            'session_count'=>(int)$entitlement->session_count,'state'=>(string)$entitlement->state,
            'term_id'=>$entitlement->term_id===null?null:(int)$entitlement->term_id,
        );
        return array('student_id'=>$studentId,'purchases'=>$purchases,'entitlements'=>$entitlements);
    }

    /** Funding state of one canonical Term: how much of the committed allocation is effective. */
    public function forTerm(int $termId):array{
        $this->requireView();
        $plan=$this->funding->fundingPlanForTerm($termId);
        if($plan===null)return array('term_id'=>$termId,'commercially_funded'=>false,'committed_sessions'=>null,'effective_sessions'=>null,'allowance'=>null);
        return array(
            'term_id'=>$termId,'commercially_funded'=>true,
            'committed_sessions'=>$plan['committed_sessions'],'effective_sessions'=>$plan['effective_sessions'],
            'settled_sessions'=>$plan['settled_sessions'],'allowance'=>$this->funding->standardAllowanceForTerm($termId),
            'plan_kind'=>$plan['plan_kind'],'offer_id'=>$plan['offer_id'],'entitlement_id'=>$plan['entitlement_id'],
            'obligations'=>$this->funding->obligationStatus($plan['offer_id'])['obligations'],
        );
    }

    /** Payment evidence for one purchase, digest-only and reconciliation-safe. */
    public function paymentsForPurchase(int $purchaseId):array{
        $this->requireView();
        $rows=array();
        foreach($this->payments->evidenceForPurchase($purchaseId) as $evidence)$rows[]=array(
            'evidence_id'=>(int)$evidence->id,'provider_key'=>(string)$evidence->provider_key,
            'evidence_kind'=>(string)$evidence->evidence_kind,'processing_state'=>(string)$evidence->processing_state,
            'reason_code'=>$evidence->reason_code===null?null:(string)$evidence->reason_code,
            'amount_minor'=>$evidence->amount_minor===null?null:(int)$evidence->amount_minor,
            'currency'=>$evidence->currency===null?null:(string)$evidence->currency,
            'provider_occurred_at'=>$evidence->provider_occurred_at===null?null:(string)$evidence->provider_occurred_at,
            'ingested_at'=>(string)$evidence->ingested_at,'obligation_id'=>$evidence->obligation_id===null?null:(int)$evidence->obligation_id,
        );
        return $rows;
    }

    /** Protected capacity intervals currently owned by a paid commitment for one Term. */
    public function protectedCapacityForTerm(int $termId):array{
        $this->requireView();
        $rows=array();
        foreach($this->capacity->claimsForTerm($termId) as $claim){
            foreach($this->capacity->intervals((int)$claim->id) as $interval){
                $rows[]=array(
                    'claim_id'=>(int)$claim->id,'claim_state'=>(string)$claim->state,
                    'interval_id'=>(int)$interval->id,'expected_session'=>(int)$interval->expected_session,
                    'interval_state'=>(string)$interval->state,'starts_at_utc'=>(string)$interval->starts_at_utc,
                    'ends_at_utc'=>(string)$interval->ends_at_utc,'schedule_timezone'=>(string)$interval->schedule_timezone,
                );
            }
        }
        return $rows;
    }

    public function patternFor(int $studentId,int $courseId):array{
        $this->requireView();
        $pattern=$this->capacity->activePatternFor($studentId,$courseId);
        if(!$pattern)throw new \InvalidArgumentException('commercial_pattern_required');
        return array(
            'pattern_id'=>(int)$pattern->id,'teacher_id'=>(int)$pattern->teacher_id,'course_id'=>(int)$pattern->course_id,
            'weekday'=>(int)$pattern->weekday,'local_wall_time'=>(string)$pattern->local_wall_time,
            'schedule_timezone'=>(string)$pattern->schedule_timezone,'duration_minutes'=>(int)$pattern->duration_minutes,
            'buffer_minutes'=>(int)$pattern->buffer_minutes,'anchor_starts_at_utc'=>(string)$pattern->anchor_starts_at_utc,
        );
    }

    /** The active runtime commercial policy value, or an explicit deliberate "unset" state. */
    public function policy(string $policyKey):array{
        $this->requireView();
        return $this->policies->current($policyKey);
    }
    /** Class-B policy values a future Notification Authority or portal may consume. */
    public function commercialPolicies():array{
        $this->requireView();
        $rows=array();
        foreach(CommercialRule::POLICY_KEYS as $policyKey)$rows[$policyKey]=$this->policies->current($policyKey);
        return $rows;
    }
    public function exceptionsForStudent(int $studentId):array{
        $this->requireView();
        return (new CommercialExceptionService($this->capacity))->forStudent($studentId);
    }
    private function requireView():void{CommercialSupport::requireCapability(self::VIEW);}
}
