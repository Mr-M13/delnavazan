<?php
namespace Delnavazan\Platform\Core\Application;

/** Capability-protected, PII-minimised read seam for the Collection Intent aggregate. */
final class CollectionReadService {
    private const CAPABILITY='dzn_view_recurring_authority';
    public function one(int $id):array{
        $validated=$this->validated($id);
        $row=$validated[0];
        return array('collection_intent_id'=>(int)$row->id,'renewal_cycle_id'=>(int)$row->renewal_cycle_id,'obligation_id'=>(int)$row->obligation_id,'kind'=>(string)$row->kind,'state'=>(string)$row->state,'charge_at'=>$row->charge_at===null?null:(string)$row->charge_at,'failure_reason_code'=>$row->failure_reason_code===null?null:(string)$row->failure_reason_code);
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
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}collection_intents WHERE id=%d",$id));
        if(!$row)throw new \InvalidArgumentException('collection_intent_not_found');
        // §7.3: the intent must still describe its own cycle's collection — an R1 obligation issued to
        // the cycle's own beneficiary Student and Course in the cycle's frozen currency, collected in the
        // kind that the cycle's frozen mode authorises — and only an automatic intent may carry a charge
        // instant. A manual intent that names a charge time, or an intent whose kind contradicts its
        // cycle's mode, is malformed rather than authority.
        $cycle=$wpdb->get_row($wpdb->prepare("SELECT cycle.collection_mode AS collection_mode,cycle.currency AS currency,recurring.student_id AS student_id,recurring.course_id AS course_id FROM {$p}renewal_cycles cycle JOIN {$p}recurring_enrolments recurring ON recurring.id=cycle.recurring_enrolment_id WHERE cycle.id=%d",(int)$row->renewal_cycle_id));
        $obligation=$wpdb->get_row($wpdb->prepare("SELECT offer.currency AS currency,offer.beneficiary_student_id AS student_id,offer.course_id AS course_id FROM {$p}commercial_offer_obligations obligation JOIN {$p}commercial_offers offer ON offer.id=obligation.offer_id WHERE obligation.id=%d",(int)$row->obligation_id));
        RecurringSupport::integrity(
            RecurringRule::validState((string)$row->state,RecurringRule::COLLECTION_INTENT_STATES)
            &&in_array((string)$row->kind,RecurringRule::COLLECTION_KINDS,true)
            &&(int)$row->renewal_cycle_id>0
            &&(int)$row->obligation_id>0
            &&(int)$row->collection_intent_version>0
            &&RecurringSupport::utcOrNull($row->charge_at)
            &&RecurringSupport::controlledReasonOrNull($row->failure_reason_code)
            &&$cycle!==null&&$obligation!==null
            &&(int)$obligation->student_id===(int)$cycle->student_id
            &&(int)$obligation->course_id===(int)$cycle->course_id
            &&(string)$obligation->currency===(string)$cycle->currency
            &&RecurringRule::intentKindForMode((string)$cycle->collection_mode)===(string)$row->kind
            &&((string)$row->kind!=='manual_payment_required'||$row->charge_at===null),
            'collection_intent_integrity_conflict'
        );
        $events=$this->history($id);
        RecurringIntegrity::aggregate('collection_intent',$row,$events,'collection_intent_integrity_conflict');
        return array($row,$events);
    }
    /** The stored append-only history of the aggregate, in event order. */
    private function history(int $id):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}collection_intent_events WHERE collection_intent_id=%d ORDER BY event_sequence",$id))?:array();
    }
}
