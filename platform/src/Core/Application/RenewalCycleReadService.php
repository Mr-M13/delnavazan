<?php
namespace Delnavazan\Platform\Core\Application;

/** Capability-protected, PII-minimised read seam for the Renewal Cycle aggregate. */
final class RenewalCycleReadService {
    private const CAPABILITY='dzn_view_recurring_authority';
    public function one(int $id):array{
        $validated=$this->validated($id);
        return $this->shape($validated[0]);
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
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}renewal_cycles WHERE id=%d",$id));
        if(!$row)throw new \InvalidArgumentException('renewal_cycle_not_found');
        // The cycle's frozen commercial facts and derived instants are part of the aggregate: a
        // corrupted currency, collection mode, price snapshot, boundary or guarantee window must
        // never be presented as authority.
        // The linked ownership facts are validated too: the cycle must sit on a recurring enrolment of
        // the same frozen currency whose own canonical Enrolment owns the recorded source Term, and the
        // next-Term identity must agree with the recorded state (only `term_bound`/`closed` name one).
        $recurring=$wpdb->get_row($wpdb->prepare("SELECT id,enrolment_id,student_id,course_id,currency FROM {$p}recurring_enrolments WHERE id=%d",(int)$row->recurring_enrolment_id));
        $term=$wpdb->get_row($wpdb->prepare("SELECT id,enrolment_id,archived_at FROM {$p}terms WHERE id=%d",(int)$row->source_term_id));
        RecurringSupport::integrity(
            RecurringRule::validState((string)$row->state,RecurringRule::CYCLE_STATES)
            &&in_array((string)$row->collection_mode,RecurringRule::COLLECTION_MODES,true)
            &&RecurringSupport::supportedCurrency((string)$row->currency)
            &&preg_match('/^\d{1,15}$/D',(string)$row->amount_minor)===1
            &&(int)$row->sequence>0
            &&(int)$row->source_term_id>0
            &&(int)$row->renewal_cycle_version>0
            &&RecurringSupport::utcOrNull($row->boundary_derived_at)
            &&$row->boundary_derived_at!==null
            &&RecurringSupport::utcOrNull($row->guarantee_deadline_at)
            &&($row->next_term_id===null||(int)$row->next_term_id>0)
            &&($row->next_term_id!==null)===in_array((string)$row->state,array('term_bound','closed'),true)
            &&$recurring!==null&&$term!==null&&$term->archived_at===null
            &&(int)$term->enrolment_id===(int)$recurring->enrolment_id
            &&(string)$recurring->currency===(string)$row->currency,
            'renewal_cycle_integrity_conflict'
        );
        $events=$this->history($id);
        RecurringIntegrity::aggregate('renewal_cycle',$row,$events,'renewal_cycle_integrity_conflict');
        return array($row,$events);
    }
    /** The stored append-only history of the aggregate, in event order. */
    private function history(int $id):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}renewal_cycle_events WHERE renewal_cycle_id=%d ORDER BY event_sequence",$id))?:array();
    }
    private function shape(object $row):array{
        return array('renewal_cycle_id'=>(int)$row->id,'recurring_enrolment_id'=>(int)$row->recurring_enrolment_id,'sequence'=>(int)$row->sequence,'source_term_id'=>(int)$row->source_term_id,'next_term_id'=>$row->next_term_id===null?null:(int)$row->next_term_id,'collection_mode'=>(string)$row->collection_mode,'currency'=>(string)$row->currency,'amount_minor'=>(int)$row->amount_minor,'boundary_derived_at'=>(string)$row->boundary_derived_at,'guarantee_deadline_at'=>$row->guarantee_deadline_at===null?null:(string)$row->guarantee_deadline_at,'state'=>(string)$row->state);
    }
}
