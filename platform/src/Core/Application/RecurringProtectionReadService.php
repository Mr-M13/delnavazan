<?php
namespace Delnavazan\Platform\Core\Application;

/** Capability-protected, PII-minimised read seam for the continuous protection aggregate. */
final class RecurringProtectionReadService {
    private const CAPABILITY='dzn_view_recurring_authority';
    public function one(int $id):array{
        $validated=$this->validated($id);
        $row=$validated[0];
        return array('recurring_protection_id'=>(int)$row->id,'renewal_cycle_id'=>(int)$row->renewal_cycle_id,'claim_id'=>(int)$row->claim_id,'state'=>(string)$row->state);
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
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recurring_protections WHERE id=%d",$id));
        if(!$row)throw new \InvalidArgumentException('recurring_protection_not_found');
        // §7.3/§5.6: a protection must still bind the cycle's own current-Term claim — an R1 claim of
        // that cycle's beneficiary Student and Course whose recorded Term is the cycle's own source Term.
        // A protection that has drifted onto another active claim of the same Student is malformed.
        $cycle=$wpdb->get_row($wpdb->prepare("SELECT cycle.id AS id,cycle.source_term_id AS source_term_id,recurring.student_id AS student_id,recurring.course_id AS course_id FROM {$p}renewal_cycles cycle JOIN {$p}recurring_enrolments recurring ON recurring.id=cycle.recurring_enrolment_id WHERE cycle.id=%d",(int)$row->renewal_cycle_id));
        $claim=$wpdb->get_row($wpdb->prepare("SELECT id,term_id,student_id,course_id FROM {$p}commercial_capacity_claims WHERE id=%d",(int)$row->claim_id));
        RecurringSupport::integrity(
            RecurringRule::validState((string)$row->state,RecurringRule::PROTECTION_STATES)
            &&(int)$row->renewal_cycle_id>0
            &&(int)$row->claim_id>0
            &&(int)$row->recurring_protection_version>0
            &&$cycle!==null&&$claim!==null
            &&$claim->term_id!==null&&(int)$claim->term_id===(int)$cycle->source_term_id
            &&(int)$claim->student_id===(int)$cycle->student_id
            &&(int)$claim->course_id===(int)$cycle->course_id,
            'recurring_protection_integrity_conflict'
        );
        $events=$this->history($id);
        RecurringIntegrity::aggregate('recurring_protection',$row,$events,'recurring_protection_integrity_conflict');
        return array($row,$events);
    }
    /** The stored append-only history of the aggregate, in event order. */
    private function history(int $id):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}recurring_protection_events WHERE recurring_protection_id=%d ORDER BY event_sequence",$id))?:array();
    }
}
