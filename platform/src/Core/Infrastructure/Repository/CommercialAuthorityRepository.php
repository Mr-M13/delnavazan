<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Persistence boundary for the Phase 2A.2-R1 commercial authority.
 *
 * This repository owns the commercial account root, the versioned runtime commercial policy
 * registry, the sellable product/price catalogue, promotions and promotion redemptions,
 * account-specific adjustments, immutable purchase offers with their pricing snapshot and ordered
 * payment obligations, purchases, bounded commercial entitlements and Term funding plans.
 *
 * It never writes Term, Lesson, schedule, delivery, attendance or provider storage: Term creation
 * stays with Phase L, Lesson issuance with Phase M, scheduling with Phase N and payment evidence
 * with the commercial payment boundary.
 */
final class CommercialAuthorityRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}

    /**
     * The single commercial serialization device, keyed by beneficiary Student. Every commercial
     * command that can change money, funding or entitlement state takes this row before any other
     * commercial row; no code path may acquire it after an Enrolment-chain or Teacher-root lock.
     */
    public function lockAccountRoot(int $studentId,int $actor):object{
        global $wpdb;
        $now=gmdate('Y-m-d H:i:s');
        $sql=$wpdb->prepare("INSERT INTO {$this->p}commercial_account_roots(student_id,created_at,created_by) VALUES(%d,%s,%d) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",$studentId,$now,$actor);
        if($wpdb->query($sql)===false)throw new \RuntimeException('Commercial account root persistence failed');
        $id=(int)$wpdb->insert_id;
        if($id<1)$id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->p}commercial_account_roots WHERE student_id=%d",$studentId));
        $row=$this->one("SELECT * FROM {$this->p}commercial_account_roots WHERE id=%d FOR UPDATE",$id);
        if(!$row||(int)$row->student_id!==$studentId)throw new \RuntimeException('Commercial account root lock failed');
        return $row;
    }
    public function accountRoot(int $studentId):?object{return $this->one("SELECT * FROM {$this->p}commercial_account_roots WHERE student_id=%d",$studentId);}
    /** The current canonical Enrolment for a Student + Course; Term creation stays with Phase L. */
    public function canonicalEnrolmentFor(int $studentId,int $courseId,bool $lock=false):?object{
        global $wpdb;$suffix=$lock?' FOR UPDATE':'';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}enrolments WHERE student_id=%d AND course_id=%d AND record_model='canonical_student_course_v1' AND applicable_slot=1 AND archived_at IS NULL{$suffix}",$studentId,$courseId));
    }

    // ------------------------------------------------------------------ runtime commercial policy

    public function policy(int $policyId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}commercial_policies WHERE id=%d".($lock?' FOR UPDATE':''),$policyId);}
    public function policyVersion(string $policyKey,int $policyVersion):?object{return $this->one("SELECT * FROM {$this->p}commercial_policies WHERE policy_key=%s AND policy_version=%d",$policyKey,$policyVersion);}
    public function latestPolicy(string $policyKey,bool $lock=false):?object{
        global $wpdb;$suffix=$lock?' FOR UPDATE':'';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}commercial_policies WHERE policy_key=%s ORDER BY policy_version DESC, id DESC LIMIT 1{$suffix}",$policyKey));
    }
    public function policies():array{
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->p}commercial_policies ORDER BY policy_key, policy_version")?:array();
    }
    public function insertPolicy(array $data):int{return $this->insert('commercial_policies',$data,'Commercial policy persistence failed');}

    // --------------------------------------------------------------------------- sellable product

    public function product(int $productId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}commercial_products WHERE id=%d".($lock?' FOR UPDATE':''),$productId);}
    public function productsForCourse(int $courseId):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}commercial_products WHERE course_id=%d ORDER BY id",$courseId))?:array();
    }
    public function insertProduct(array $data):int{return $this->insert('commercial_products',$data,'Commercial product persistence failed');}
    public function updateProduct(int $productId,array $changes):void{
        global $wpdb;
        if($wpdb->update($this->p.'commercial_products',$changes,array('id'=>$productId))===false)throw new \RuntimeException('Commercial product update failed');
    }

    public function price(int $priceId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}commercial_prices WHERE id=%d".($lock?' FOR UPDATE':''),$priceId);}
    public function priceForRegion(int $productId,string $regionCode,bool $lock=false):?object{
        $suffix=$lock?' FOR UPDATE':'';
        return $this->one("SELECT * FROM {$this->p}commercial_prices WHERE product_id=%d AND region_code=%s AND active_slot=1 AND status='active' AND archived_at IS NULL".$suffix,$productId,$regionCode);
    }
    public function priceHistory(int $priceId):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}commercial_prices WHERE product_id=(SELECT product_id FROM {$this->p}commercial_prices WHERE id=%d) ORDER BY id",$priceId))?:array();
    }
    public function insertPrice(array $data):int{return $this->insert('commercial_prices',$data,'Commercial price persistence failed');}
    public function setActivePriceSlot(int $priceId,?int $slot,array $changes=array()):void{
        global $wpdb;
        $data=array_merge(array('active_slot'=>$slot),$changes);
        if($wpdb->update($this->p.'commercial_prices',$data,array('id'=>$priceId))===false)throw new \RuntimeException('Commercial price update failed');
    }

    // --------------------------------------------------------------------------------- promotions

    public function promotion(int $promotionId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}commercial_promotions WHERE id=%d".($lock?' FOR UPDATE':''),$promotionId);}
    public function promotionByCodeDigest(string $codeDigest,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}commercial_promotions WHERE code_digest=%s".($lock?' FOR UPDATE':''),$codeDigest);
    }
    public function promotions():array{
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->p}commercial_promotions ORDER BY id")?:array();
    }
    public function insertPromotion(array $data):int{return $this->insert('commercial_promotions',$data,'Commercial promotion persistence failed');}
    public function updatePromotion(int $promotionId,array $changes):void{
        global $wpdb;
        if($wpdb->update($this->p.'commercial_promotions',$changes,array('id'=>$promotionId))===false)throw new \RuntimeException('Commercial promotion update failed');
    }
    public function redemptionCount(int $promotionId,bool $lock=false):int{
        global $wpdb;$suffix=$lock?' FOR UPDATE':'';
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->p}commercial_promotion_redemptions WHERE promotion_id=%d AND state='consumed'{$suffix}",$promotionId));
    }
    public function beneficiaryRedemptionCount(int $promotionId,int $studentId,bool $lock=false):int{
        global $wpdb;$suffix=$lock?' FOR UPDATE':'';
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->p}commercial_promotion_redemptions WHERE promotion_id=%d AND beneficiary_student_id=%d AND state='consumed'{$suffix}",$promotionId,$studentId));
    }
    public function redemptionForOffer(int $offerId):?object{return $this->one("SELECT * FROM {$this->p}commercial_promotion_redemptions WHERE offer_id=%d",$offerId);}
    public function insertRedemption(array $data):int{return $this->insert('commercial_promotion_redemptions',$data,'Promotion redemption persistence failed');}

    // --------------------------------------------------------------------- account adjustments

    public function adjustment(int $adjustmentId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}commercial_account_adjustments WHERE id=%d".($lock?' FOR UPDATE':''),$adjustmentId);}
    public function grantedAdjustments(int $studentId,bool $lock=false):array{
        global $wpdb;$suffix=$lock?' FOR UPDATE':'';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}commercial_account_adjustments WHERE beneficiary_student_id=%d AND state='granted' ORDER BY id{$suffix}",$studentId))?:array();
    }
    public function adjustmentsForStudent(int $studentId):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}commercial_account_adjustments WHERE beneficiary_student_id=%d ORDER BY id",$studentId))?:array();
    }
    public function insertAdjustment(array $data):int{return $this->insert('commercial_account_adjustments',$data,'Account adjustment persistence failed');}
    public function updateAdjustmentState(int $adjustmentId,int $expectedVersion,array $changes):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'commercial_account_adjustments',array_merge($changes,array('adjustment_version'=>$expectedVersion+1)),array('id'=>$adjustmentId,'adjustment_version'=>$expectedVersion));
        if($changed!==1)throw new \RuntimeException('Stale account adjustment');
    }
    public function adjustmentEvents(int $adjustmentId,bool $lock=false):array{
        global $wpdb;$suffix=$lock?' FOR UPDATE':'';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}commercial_account_adjustment_events WHERE adjustment_id=%d ORDER BY event_sequence,id{$suffix}",$adjustmentId))?:array();
    }
    public function maxAdjustmentEventSequence(int $adjustmentId):int{
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(event_sequence),0) FROM {$this->p}commercial_account_adjustment_events WHERE adjustment_id=%d",$adjustmentId));
    }
    public function insertAdjustmentEvent(array $data):int{return $this->insert('commercial_account_adjustment_events',$data,'Account adjustment event persistence failed');}

    // ------------------------------------------------------------------------------- purchase offer

    public function offer(int $offerId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}commercial_offers WHERE id=%d".($lock?' FOR UPDATE':''),$offerId);}
    public function offersForContinuation(int $caseId):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}commercial_offers WHERE continuation_case_id=%d ORDER BY id",$caseId))?:array();
    }
    public function offersForBeneficiary(int $studentId):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}commercial_offers WHERE beneficiary_student_id=%d ORDER BY id DESC",$studentId))?:array();
    }
    public function insertOffer(array $data):int{return $this->insert('commercial_offers',$data,'Commercial offer persistence failed');}
    public function updateOfferState(int $offerId,int $expectedVersion,array $changes):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'commercial_offers',array_merge($changes,array('offer_version'=>$expectedVersion+1)),array('id'=>$offerId,'offer_version'=>$expectedVersion));
        if($changed!==1)throw new \RuntimeException('Stale commercial offer');
    }
    public function offerAdjustments(int $offerId,bool $lock=false):array{
        global $wpdb;$suffix=$lock?' FOR UPDATE':'';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}commercial_offer_adjustments WHERE offer_id=%d ORDER BY application_order,id{$suffix}",$offerId))?:array();
    }
    public function insertOfferAdjustment(array $data):int{return $this->insert('commercial_offer_adjustments',$data,'Offer adjustment snapshot persistence failed');}
    public function offerPolicies(int $offerId):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}commercial_offer_policies WHERE offer_id=%d ORDER BY policy_key",$offerId))?:array();
    }
    public function insertOfferPolicy(array $data):int{return $this->insert('commercial_offer_policies',$data,'Offer policy reference persistence failed');}
    public function obligationsForOffer(int $offerId,bool $lock=false):array{
        global $wpdb;$suffix=$lock?' FOR UPDATE':'';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}commercial_offer_obligations WHERE offer_id=%d ORDER BY obligation_sequence,id{$suffix}",$offerId))?:array();
    }
    public function obligation(int $obligationId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}commercial_offer_obligations WHERE id=%d".($lock?' FOR UPDATE':''),$obligationId);}
    public function obligationByReferenceDigest(string $digest,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}commercial_offer_obligations WHERE obligation_reference_digest=%s".($lock?' FOR UPDATE':''),$digest);
    }
    public function insertObligation(array $data):int{return $this->insert('commercial_offer_obligations',$data,'Offer obligation persistence failed');}

    // ------------------------------------------------------------------------ purchase/entitlement

    public function purchase(int $purchaseId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}commercial_purchases WHERE id=%d".($lock?' FOR UPDATE':''),$purchaseId);}
    public function purchaseByOffer(int $offerId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}commercial_purchases WHERE offer_id=%d".($lock?' FOR UPDATE':''),$offerId);}
    public function purchasesForBeneficiary(int $studentId):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}commercial_purchases WHERE beneficiary_student_id=%d ORDER BY id DESC",$studentId))?:array();
    }
    public function insertPurchase(array $data):int{return $this->insert('commercial_purchases',$data,'Commercial purchase persistence failed');}
    public function updatePurchase(int $purchaseId,int $expectedVersion,array $changes):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'commercial_purchases',array_merge($changes,array('purchase_version'=>$expectedVersion+1)),array('id'=>$purchaseId,'purchase_version'=>$expectedVersion));
        if($changed!==1)throw new \RuntimeException('Stale commercial purchase');
    }
    public function entitlement(int $entitlementId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}commercial_entitlements WHERE id=%d".($lock?' FOR UPDATE':''),$entitlementId);}
    public function entitlementForPurchase(int $purchaseId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}commercial_entitlements WHERE purchase_id=%d".($lock?' FOR UPDATE':''),$purchaseId);}
    public function entitlementsForBeneficiary(int $studentId):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}commercial_entitlements WHERE beneficiary_student_id=%d ORDER BY id DESC",$studentId))?:array();
    }
    public function insertEntitlement(array $data):int{return $this->insert('commercial_entitlements',$data,'Commercial entitlement persistence failed');}
    public function bindEntitlementToTerm(int $entitlementId,int $expectedVersion,int $termId,int $enrolmentId,string $now,int $actor):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'commercial_entitlements',array(
            'state'=>'term_bound','term_id'=>$termId,'enrolment_id'=>$enrolmentId,'bound_at'=>$now,
            'entitlement_version'=>$expectedVersion+1,'updated_at'=>$now,'updated_by'=>$actor,
        ),array('id'=>$entitlementId,'entitlement_version'=>$expectedVersion,'state'=>'issued'));
        if($changed!==1)throw new \RuntimeException('Stale commercial entitlement');
    }

    public function fundingPlanForTerm(int $termId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}commercial_term_funding_plans WHERE term_id=%d".($lock?' FOR UPDATE':''),$termId);}
    public function fundingPlanForEntitlement(int $entitlementId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}commercial_term_funding_plans WHERE entitlement_id=%d".($lock?' FOR UPDATE':''),$entitlementId);}
    public function fundingPlansForEnrolment(int $enrolmentId):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}commercial_term_funding_plans WHERE enrolment_id=%d ORDER BY id",$enrolmentId))?:array();
    }
    public function insertFundingPlan(array $data):int{return $this->insert('commercial_term_funding_plans',$data,'Term funding plan persistence failed');}

    public function command(string $digest):?object{return $this->one("SELECT * FROM {$this->p}commercial_commands WHERE command_key_digest=%s",$digest);}
    public function insertCommand(array $data):int{return $this->insert('commercial_commands',$data,'Commercial command persistence failed');}

    /** Only named unique constraints belonging to this aggregate may arbitrate. */
    public function duplicate(\Throwable $e):?string{
        if(!preg_match("/Duplicate entry .* for key ['`](?:[^'`.]+\\.)?([^'`]+)['`]/i",$e->getMessage(),$m))return null;
        $key=strtolower((string)$m[1]);
        return in_array($key,array(
            'command_key_digest','uid','reference_code','policy_version','product_region','code_digest','offer_id',
            'promotion_sequence','offer_application','offer_policy','offer_sequence','obligation_reference_digest',
            'purchase_id','term_id','entitlement_id','student_id','source_slot_authority_id',
        ),true)?$key:null;
    }

    private function one(string $sql,...$args):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare($sql,...$args));}
    private function insert(string $table,array $data,string $message):int{
        global $wpdb;
        $old=$wpdb->suppress_errors(true);
        $ok=$wpdb->insert($this->p.$table,$data);
        $error=$wpdb->last_error;
        $wpdb->suppress_errors($old);
        if($ok===false)throw new \RuntimeException($message.': '.$error);
        return (int)$wpdb->insert_id;
    }
}
