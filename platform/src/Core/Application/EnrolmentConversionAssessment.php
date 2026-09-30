<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\EnrolmentConversionRepository;

/** Immutable readiness result. It is informational and never bearer authority. */
final class EnrolmentConversionAssessment {
    public function __construct(
        public readonly string $classification,
        public readonly ?object $arrangement = null,
        public readonly ?object $predecessor = null,
        public readonly ?object $linkedEnrolment = null
    ) {}

    public static function evaluate(EnrolmentConversionRepository $repository, ?array $graph, array $enrolments, array $events, ?object $student, ?object $course, ?object $teacher): self {
        if (!$graph || !self::validSourceGraph($graph)) return new self('source_integrity_conflict');
        $arrangement = $graph['arrangement'];
        if ($arrangement->course_id === null || (int) $arrangement->course_id < 1 || $arrangement->unresolved_course_spec !== null) return new self('source_not_final', $arrangement);
        if (!$teacher || (int) $teacher->id !== (int) $arrangement->teacher_id) return new self('source_integrity_conflict', $arrangement);

        $legacy = false; $canonical = false; $applicable = array(); $closed = array(); $linked = array();
        foreach ($enrolments as $row) {
            if ($row->record_model === 'legacy_phase1') {
                if (!self::validLegacy($row)) return new self('data_integrity_conflict', $arrangement);
                $legacy = true; continue;
            }
            $history=array_values(array_filter($events,static fn($event)=>(int)$event->enrolment_id===(int)$row->id));
            if ($row->record_model !== 'canonical_student_course_v1' || !self::validCanonical($row, $repository, $history)) return new self('data_integrity_conflict', $arrangement);
            $canonical = true;
            if ((int) $row->accepted_service_arrangement_id === (int) $arrangement->id) $linked[] = $row;
            if ($row->lifecycle_state === 'closed') $closed[] = $row; else $applicable[] = $row;
        }
        if ($legacy && $canonical) return new self('data_integrity_conflict', $arrangement);
        if (count($linked) > 1 || count($applicable) > 1) return new self('data_integrity_conflict', $arrangement);
        if ($linked) {
            $command=$repository->commandForSource((int)$arrangement->id);
            $history=array_values(array_filter($events,static fn($event)=>(int)$event->enrolment_id===(int)$linked[0]->id));
            if(!self::validExistingResult($repository,$graph,$linked[0],$history,$command,EnrolmentConversionIdempotency::payloadDigest((int)$arrangement->id)))return new self('data_integrity_conflict',$arrangement);
            return new self('already_converted', $arrangement, null, $linked[0]);
        }
        if ($legacy) return new self('legacy_review_required', $arrangement);
        if ($applicable) return new self('canonical_conflict', $arrangement);

        $predecessor = null;
        if ($closed) {
            $predecessor = self::closedPredecessor($closed, $events);
            if (!$predecessor) return new self('data_integrity_conflict', $arrangement);
        }
        if (!$student || (int) $student->id !== (int) $arrangement->student_id || $student->status !== 'active' || $student->archived_at !== null) return new self('student_not_current', $arrangement, $predecessor);
        if (!$course || (int) $course->id !== (int) $arrangement->course_id || $course->status !== 'active' || $course->archived_at !== null) return new self('course_not_current', $arrangement, $predecessor);
        return new self('ready', $arrangement, $predecessor);
    }

    private static function validSourceGraph(array $g): bool {
        foreach (array('request','case','family','option','version','provisional','outcome','arrangement','identityResolution','capacityClassification') as $key) if (!isset($g[$key]) || !is_object($g[$key])) return false;
        $a=$g['arrangement'];$r=$g['request'];$c=$g['case'];$f=$g['family'];$o=$g['option'];$v=$g['version'];$p=$g['provisional'];$x=$g['outcome'];$identity=$g['identityResolution'];$capacity=$g['capacityClassification'];
        return (int)$a->booking_request_id===(int)$r->id
            && (int)$a->coordination_case_id===(int)$c->id && (int)$c->booking_request_id===(int)$r->id
            && (int)$a->proposal_family_id===(int)$f->id && (int)$f->booking_request_id===(int)$r->id && (int)$f->coordination_case_id===(int)$c->id
            && (int)$a->proposal_option_id===(int)$o->id && (int)$o->proposal_family_id===(int)$f->id
            && (int)$a->proposal_version_id===(int)$v->id && (int)$v->proposal_family_id===(int)$f->id && (int)$v->proposal_option_id===(int)$o->id && (int)$o->current_version_id===(int)$v->id
            && (int)$a->provisional_acceptance_event_id===(int)$p->id && $p->event_kind==='accepted_pending_conditions' && $p->accepting_subject_state==='authority_unresolved'
            && (int)$p->booking_request_id===(int)$r->id && (int)$p->coordination_case_id===(int)$c->id && (int)$p->proposal_family_id===(int)$f->id && (int)$p->proposal_option_id===(int)$o->id && (int)$p->proposal_version_id===(int)$v->id
            && (string)$p->family_uid===(string)$f->uid && (string)$p->option_uid===(string)$o->uid && (string)$p->version_uid===(string)$v->uid && (int)$p->version_number===(int)$v->version_number && (string)$p->version_fingerprint===(string)$v->version_fingerprint && (string)$p->prospective_subject_ref===(string)$v->prospective_subject_ref
            && (int)$x->accepted_service_arrangement_id===(int)$a->id && (int)$x->proposal_family_id===(int)$f->id && (int)$x->proposal_option_id===(int)$o->id && (int)$x->proposal_version_id===(int)$v->id && $x->outcome==='accepted'
            && (string)$x->version_uid===(string)$v->uid && (int)$x->version_number===(int)$v->version_number && (string)$x->version_fingerprint===(string)$v->version_fingerprint
            && (string)$a->family_uid===(string)$f->uid && (string)$a->option_uid===(string)$o->uid && (string)$a->version_uid===(string)$v->uid
            && (int)$a->version_number===(int)$v->version_number && (string)$a->version_fingerprint===(string)$v->version_fingerprint && (string)$a->arrangement_fingerprint===(string)$v->arrangement_fingerprint
            && (int)$a->teacher_id===(int)$o->teacher_id && (int)$a->teacher_id===(int)$v->teacher_id && (int)$a->student_id>0
            && ($r->student_id===null || (int)$r->student_id===(int)$a->student_id)
            && self::same($a->prospective_subject_ref,$v->prospective_subject_ref) && self::same($a->course_id,$v->course_id) && self::same($a->unresolved_course_spec,$v->unresolved_course_spec)
            && self::same($a->delivery_mode,$v->delivery_mode) && self::same($a->location_scope,$v->location_scope) && (int)$a->frequency_per_week===(int)$v->frequency_per_week
            && (int)$a->expected_duration_minutes===(int)$v->expected_duration_minutes && self::same($a->schedule_constraints,$v->schedule_constraints)
            && self::same($a->commencement_window_start,$v->commencement_window_start) && self::same($a->commencement_window_end,$v->commencement_window_end)
            && self::same($a->timezone,$v->timezone) && self::same($a->conditions_code,$v->conditions_code) && $a->confirmation_value==='affirmed'
            && (int)$a->identity_resolution_event_id===(int)$identity->id && (int)$identity->booking_request_id===(int)$r->id
            && (int)$a->identity_resolution_sequence===(int)$identity->resolution_sequence && $identity->outcome==='resolved'
            && (int)$identity->student_id===(int)$a->student_id && (string)$identity->prospective_subject_ref===(string)$a->prospective_subject_ref
            && self::validEvidence($identity,'verified_at','verified_by')
            && (int)$a->capacity_classification_id===(int)$capacity->id && (int)$capacity->student_id===(int)$a->student_id
            && (string)$a->capacity_classification===(string)$capacity->classification && in_array((string)$capacity->classification,array('adult','minor'),true)
            && self::validEvidence($capacity,'classified_at','classified_by');
    }

    private static function validLegacy(object $row): bool {
        return in_array((string)$row->status,array('draft','active','paused','ending','completed','cancelled','archived'),true)
            && $row->accepted_service_arrangement_id===null && $row->lifecycle_state===null && $row->applicable_slot===null && $row->predecessor_enrolment_id===null && $row->lineage_meaning===null;
    }

    private static function validCanonical(object $row, EnrolmentConversionRepository $repository,array$history): bool {
        if (!self::validCanonicalAggregate($row)) return false;
        $graph=$repository->sourceGraphForRead((int)$row->accepted_service_arrangement_id);$source=$graph['arrangement']??null;
        if (!$graph||!self::validSourceGraph($graph)||!$source || (int)$source->student_id!==(int)$row->student_id || (int)$source->course_id!==(int)$row->course_id || (int)$source->teacher_id!==(int)$row->teacher_id || !self::validLifecycle($row,$history,$source)) return false;
        $command=$repository->commandForSource((int)$source->id);if(!self::validCommand($command,(int)$source->id,(int)$row->id,EnrolmentConversionIdempotency::payloadDigest((int)$source->id)))return false;
        if ($row->predecessor_enrolment_id!==null) {
            $predecessor=$repository->enrolmentById((int)$row->predecessor_enrolment_id);
            if (!$predecessor || (int)$predecessor->id>=(int)$row->id || $predecessor->record_model!=='canonical_student_course_v1' || $predecessor->lifecycle_state!=='closed' || $predecessor->applicable_slot!==null || (int)$predecessor->student_id!==(int)$row->student_id || (int)$predecessor->course_id!==(int)$row->course_id) return false;
        }
        return true;
    }

    private static function closedPredecessor(array $closed, array $events): ?object {
        $byEnrolment=array();
        foreach($events as$event)$byEnrolment[(int)$event->enrolment_id][]=$event;
        $closures=array();
        foreach($closed as$row){$history=$byEnrolment[(int)$row->id]??array();if(!self::validLifecycle($row,$history))return null;usort($history,static fn($a,$b)=>(int)$a->event_sequence<=>(int)$b->event_sequence);$last=end($history);if(!$last||$last->to_state!=='closed')return null;$closures[]=array($row,(string)$last->occurred_at);}
        usort($closures,static fn($a,$b)=>strcmp($b[1],$a[1]));
        if(count($closures)>1&&$closures[0][1]===$closures[1][1])return null;
        return$closures[0][0]??null;
    }

    private static function same(mixed $left,mixed $right):bool{return $left===null||$right===null?$left===$right:(string)$left===(string)$right;}

    public static function validExistingResult(EnrolmentConversionRepository$repository,array$graph,object$enrolment,array$events,?object$command,string$payload):bool{
        if(!self::validSourceGraph($graph))return false;$a=$graph['arrangement'];
        if(!self::validCommand($command,(int)$a->id,(int)$enrolment->id,$payload))return false;
        if(!self::validCanonicalAggregate($enrolment))return false;
        if((int)$enrolment->student_id!==(int)$a->student_id||(int)$enrolment->course_id!==(int)$a->course_id||(int)$enrolment->teacher_id!==(int)$a->teacher_id||(int)$enrolment->accepted_service_arrangement_id!==(int)$a->id)return false;
        if(($enrolment->predecessor_enrolment_id===null)!==($enrolment->lineage_meaning===null)||($enrolment->lineage_meaning!==null&&$enrolment->lineage_meaning!=='return_after_closure'))return false;
        if($enrolment->predecessor_enrolment_id!==null){$predecessor=$repository->enrolmentById((int)$enrolment->predecessor_enrolment_id);$predecessorEvents=$predecessor?$repository->lifecycleForEnrolment((int)$predecessor->id):array();if(!$predecessor||!self::validCanonical($predecessor,$repository,$predecessorEvents)||(int)$predecessor->student_id!==(int)$enrolment->student_id||(int)$predecessor->course_id!==(int)$enrolment->course_id)return false;}
        if(!self::validLifecycle($enrolment,$events,$a))return false;usort($events,static fn($left,$right)=>(int)$left->event_sequence<=>(int)$right->event_sequence);
        return self::validInitialConversionEvidence($enrolment,$events[0],$a);
    }

    private static function validCanonicalAggregate(object$row):bool{
        if($row->record_model!=='canonical_student_course_v1'||$row->status!=='canonical'||$row->archived_at!==null||!in_array((string)$row->lifecycle_state,array('authorised','current','paused','closed'),true)||(int)($row->accepted_service_arrangement_id??0)<1)return false;
        $slot=in_array((string)$row->lifecycle_state,array('authorised','current','paused'),true)?1:null;
        if(($row->applicable_slot===null?null:(int)$row->applicable_slot)!==$slot)return false;
        if(($row->predecessor_enrolment_id===null)!==($row->lineage_meaning===null))return false;
        return$row->lineage_meaning===null||in_array((string)$row->lineage_meaning,array('successor','return_after_closure','correction','distinct_concurrent_service'),true);
    }

    private static function validLifecycle(object$row,array$events,?object$source=null):bool{
        if(!$events)return false;usort($events,static fn($a,$b)=>(int)$a->event_sequence<=>(int)$b->event_sequence);if(!CanonicalEnrolmentLifecycleValidator::valid($row,$events))return false;$states=array('authorised','current','paused','closed');$previous=null;$previousOccurred=null;
        foreach($events as$index=>$event){$sequence=$index+1;if((int)$event->enrolment_id!==(int)$row->id||(int)$event->event_sequence!==$sequence)return false;if($index===0){if(!self::validInitialConversionEvidence($row,$event,$source))return false;}elseif((string)$event->from_state!==$previous||$event->lineage_meaning!==null)return false;if(!in_array((string)$event->to_state,$states,true)||($event->from_state!==null&&!in_array((string)$event->from_state,$states,true))||!CanonicalEnrolmentLifecycleValidator::legalTransition($event->from_state===null?null:(string)$event->from_state,(string)$event->to_state))return false;if(!self::validLifecycleEventAudit($event)||($previousOccurred!==null&&(string)$event->occurred_at<$previousOccurred))return false;$previous=(string)$event->to_state;$previousOccurred=(string)$event->occurred_at;}
        return$previous===(string)$row->lifecycle_state;
    }

    private static function validInitialConversionEvidence(object$row,object$event,?object$source=null):bool{
        if((int)$event->enrolment_id!==(int)$row->id||(int)$event->event_sequence!==1||$event->from_state!==null||$event->to_state!=='authorised'||$event->reason_code!=='accepted_service_arrangement_conversion'||$event->evidence_channel!=='platform_conversion_command'||!self::same($event->lineage_meaning,$row->lineage_meaning)||!self::validLifecycleEventAudit($event))return false;
        return$source===null||hash_equals(hash_hmac('sha256','enrolment-conversion:'.$source->uid,wp_salt('dzn_enrolment_conversion_evidence')),(string)$event->evidence_reference);
    }

    private static function validLifecycleEventAudit(object$event):bool{return preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D',(string)$event->uid)===1&&self::utc($event->occurred_at)&&self::utc($event->recorded_at)&&self::utc($event->created_at)&&(string)$event->recorded_at>=(string)$event->occurred_at&&(string)$event->created_at>=(string)$event->recorded_at&&trim((string)$event->reason_code)!==''&&trim((string)$event->evidence_channel)!==''&&trim((string)$event->evidence_reference)!==''&&(int)$event->recorded_by>0&&(int)$event->created_by>0&&(int)$event->recorded_by===(int)$event->created_by;}

    private static function validEvidence(object$row,string$decisionAt,string$decisionBy):bool{$previous=$row->supersedes_resolution_event_id??$row->supersedes_classification_id??null;return preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D',(string)$row->uid)===1&&trim((string)$row->verification_basis)!==''&&trim((string)$row->evidence_channel)!==''&&trim((string)$row->evidence_reference)!==''&&self::utc($row->evidence_at)&&self::utc($row->{$decisionAt})&&self::utc($row->created_at)&&(string)$row->evidence_at<=(string)$row->{$decisionAt}&&(string)$row->{$decisionAt}<=(string)$row->created_at&&(int)$row->{$decisionBy}>0&&(int)$row->created_by>0&&($previous===null||(int)$previous!==(int)$row->id);}
    private static function validCommand(?object$command,int$sourceId,int$enrolmentId,string$payload):bool{return$command&&preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D',(string)$command->uid)===1&&preg_match('/^[a-f0-9]{64}$/D',(string)$command->command_key_digest)===1&&preg_match('/^[a-f0-9]{64}$/D',(string)$command->command_payload_digest)===1&&hash_equals((string)$command->command_payload_digest,$payload)&&(int)$command->accepted_service_arrangement_id===$sourceId&&(int)$command->enrolment_id===$enrolmentId&&self::utc($command->created_at)&&(int)$command->created_by>0;}
    private static function utc(mixed$value):bool{return is_string($value)&&preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$value)===1&&strtotime($value.' UTC')!==false;}
}
