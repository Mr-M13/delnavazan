<?php
namespace Delnavazan\Platform\Core\Application;

/** Capability-protected, PII-minimised read seam for the Refund/Reversal review aggregate. */
final class RefundReviewReadService {
    private const CAPABILITY='dzn_view_recurring_authority';
    public function one(int $id):array{
        $validated=$this->validated($id);
        $row=$validated[0];
        return array('refund_review_id'=>(int)$row->id,'purchase_id'=>(int)$row->purchase_id,'obligation_id'=>(int)$row->obligation_id,'evidence_id'=>(int)$row->evidence_id,'kind'=>(string)$row->kind,'amount_minor'=>(int)$row->amount_minor,'currency'=>(string)$row->currency,'state'=>(string)$row->state,'academic_consequence'=>null,'academic_consequence_state'=>'unresolved','resolution_note'=>$row->resolution_note===null?null:(string)$row->resolution_note);
    }
    public function events(int $id):array{
        // §7.3: the public history read proves the same aggregate — current row *and* append-only
        // history — before shaping a single event, so a malformed or orphaned history is refused here
        // exactly as `one()` refuses it.
        $validated=$this->validated($id);
        return array_map(static fn($e)=>array('event_sequence'=>(int)$e->event_sequence,'event_type'=>(string)$e->event_type,'from_state'=>$e->from_state===null?null:(string)$e->from_state,'to_state'=>(string)$e->to_state,'occurred_at'=>(string)$e->occurred_at),$validated[1]);
    }
    /** The proved aggregate as `array($row,$events)`, shared by both public read seams. */
    private function validated(int $id):array{
        RecurringSupport::requireCapability(self::CAPABILITY);
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}refund_review_cases WHERE id=%d",$id));
        if(!$row)throw new \InvalidArgumentException('refund_review_not_found');
        // §7.3: the review must still describe its own authoritative evidence. The reviewed purchase and
        // obligation must belong to each other and carry the recorded currency, and the reviewed evidence
        // must be that obligation's *accepted* evidence of exactly the recorded kind, amount and currency
        // — the provenance §5.5 requires before a refund/reversal may be represented at all.
        $purchase=$wpdb->get_row($wpdb->prepare("SELECT id,offer_id,currency FROM {$p}commercial_purchases WHERE id=%d",(int)$row->purchase_id));
        $obligation=$wpdb->get_row($wpdb->prepare("SELECT id,offer_id,currency FROM {$p}commercial_offer_obligations WHERE id=%d",(int)$row->obligation_id));
        $evidence=$wpdb->get_row($wpdb->prepare("SELECT id,evidence_kind,processing_state,amount_minor,currency,purchase_id,obligation_id FROM {$p}commercial_payment_evidence WHERE id=%d",(int)$row->evidence_id));
        // The academic consequence is an unresolved product decision: R2 never records it, so a
        // stored value is corruption rather than configuration, and the read fails closed. While it
        // is unset the read model reports the explicit `unresolved` state and no clawback exists.
        RecurringSupport::integrity(
            RecurringRule::validState((string)$row->state,RecurringRule::REFUND_REVIEW_STATES)
            &&in_array((string)$row->kind,RecurringRule::REFUND_REVIEW_KINDS,true)
            &&(int)$row->purchase_id>0
            &&(int)$row->obligation_id>0
            &&(int)$row->evidence_id>0
            &&preg_match('/^\d{1,15}$/D',(string)$row->amount_minor)===1
            &&RecurringSupport::supportedCurrency((string)$row->currency)
            &&(int)$row->refund_review_version>0
            &&$row->academic_consequence===null
            &&$purchase!==null&&$obligation!==null&&$evidence!==null
            &&(int)$obligation->offer_id===(int)$purchase->offer_id
            &&(string)$purchase->currency===(string)$row->currency
            &&(string)$obligation->currency===(string)$row->currency
            &&(string)$evidence->evidence_kind===(string)$row->kind
            &&(string)$evidence->processing_state==='accepted'
            &&(int)$evidence->obligation_id===(int)$row->obligation_id
            &&($evidence->purchase_id===null||(int)$evidence->purchase_id===(int)$row->purchase_id)
            &&$evidence->amount_minor!==null&&(int)$evidence->amount_minor===(int)$row->amount_minor
            &&(string)$evidence->currency===(string)$row->currency,
            'refund_review_integrity_conflict'
        );
        $events=$this->history($id);
        RecurringIntegrity::aggregate('refund_review',$row,$events,'refund_review_integrity_conflict');
        return array($row,$events);
    }
    /** The stored append-only history of the aggregate, in event order. */
    private function history(int $id):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}refund_review_events WHERE refund_review_id=%d ORDER BY event_sequence",$id))?:array();
    }
}
