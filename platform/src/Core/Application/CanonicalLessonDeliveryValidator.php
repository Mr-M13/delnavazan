<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalAcademyObligationRepository,CanonicalLessonDeliveryRepository,CanonicalLessonAuthorityRepository,CanonicalLessonScheduleRepository};

/**
 * Single hydration and integrity gate for the canonical Lesson delivery/attendance aggregate.
 *
 * The aggregate is an append-only outcome history where the most recent outcome is the single
 * applicable one. An outcome states what happened to the teaching occurrence; it is never a
 * Lesson lifecycle state and it never mutates scheduling or lifecycle authority.
 *
 * Outcome vocabulary (locked Phase 2A.2-O decisions):
 *   delivered            teacher delivered, student attended, no remedy
 *   student_no_show      teacher delivered, student absent, no remedy (lesson is used)
 *   teacher_non_delivery the academy failed to deliver, academy-funded remedy owed
 *   interruption         occurrence delivered but interrupted, review material, no remedy
 *   review_required      occurrence outcome is ambiguous and must not be guessed
 *
 * Only `teacher_non_delivery` and `review_required` block Lesson completion, because O-D1 makes
 * `completed` mean delivered.
 *
 * Provider observations are evidence, never authority. The evidence channel vocabulary is the
 * existing controlled set, so a later provider (for example meeting-join evidence) can be
 * recorded through the same boundary without the domain acquiring any provider coupling.
 */
final class CanonicalLessonDeliveryValidator {
    private const MODEL='canonical_term_lesson_v1';
    private const CHANNELS=array('staff_record','authenticated_platform','document_reference');
    private const CODES=array('delivered','student_no_show','teacher_non_delivery','interruption','review_required');
    private const BLOCKING=array('teacher_non_delivery','review_required');
    private const COMPETING_CANCELLATION='canonical_lesson_cancelled_replacement_eligible';

    /** Derived, non-caller-choosable profile of one controlled outcome code. */
    public static function profile(string $code):?array{
        return match($code){
            'delivered'=>array('delivery_state'=>'delivered','attendance_state'=>'attended','remedy_class'=>'none','occurrence_attempted'=>1),
            'student_no_show'=>array('delivery_state'=>'delivered','attendance_state'=>'student_absent','remedy_class'=>'none','occurrence_attempted'=>1),
            'teacher_non_delivery'=>array('delivery_state'=>'not_delivered','attendance_state'=>'unknown','remedy_class'=>'academy_obligation','occurrence_attempted'=>1),
            'interruption'=>array('delivery_state'=>'delivered','attendance_state'=>'unknown','remedy_class'=>'none','occurrence_attempted'=>1),
            'review_required'=>array('delivery_state'=>'unknown','attendance_state'=>'unknown','remedy_class'=>'none','occurrence_attempted'=>0),
            default=>null,
        };
    }

    public static function blocksCompletion(string $code):bool{return in_array($code,self::BLOCKING,true);}
    public static function codes():array{return self::CODES;}

    /** Hydrate and validate the complete delivery/attendance aggregate of one canonical Lesson. */
    public static function validForLesson(int $lessonId,?CanonicalLessonDeliveryRepository $repository=null,?CanonicalLessonScheduleRepository $schedules=null,?CanonicalLessonAuthorityRepository $lessons=null,bool $lock=false):bool{
        $repository??=new CanonicalLessonDeliveryRepository();
        $schedules??=new CanonicalLessonScheduleRepository();
        $lessons??=new CanonicalLessonAuthorityRepository();
        $lesson=$lessons->lesson($lessonId,$lock);
        if(!$lesson)return false;
        $outcomes=$repository->outcomesForLesson($lessonId,$lock);
        $commands=$repository->commandsForLesson($lessonId,$lock);
        $obligations=(new CanonicalAcademyObligationRepository())->forLesson($lessonId,$lock);
        $versions=$schedules->versionsForLesson($lessonId,$lock);
        $lifecycle=$lessons->events($lessonId,$lock);
        $assignment=$lessons->assignmentById((int)($lesson->teacher_assignment_id??0),$lock);
        return self::valid($lesson,$outcomes,$commands,$obligations,$versions,$lifecycle,$assignment);
    }

    /** Pure aggregate validation over hydrated rows. */
    public static function valid(object $lesson,array $outcomes,array $commands,array $obligations,array $versions,array $lifecycle,?object $assignment):bool{
        $lessonId=(int)($lesson->id??0);
        if($lessonId<1||(string)($lesson->record_model??'')!==self::MODEL)return false;
        if(($lesson->archived_at??null)!==null||($lesson->replacement_for_lesson_id??null)!==null)return false;
        // Academy-owed occurrences are validated against their own source fact before anything is
        // served or mutated, so a detached or contradictory entitlement fails closed.
        if(!CanonicalAcademyObligationValidator::valid($lesson,$obligations,$outcomes,$lifecycle))return false;
        // A Lesson with no recorded outcome is ordinary delivery, but durable command evidence
        // naming a result that is no longer attached to this Lesson is never acceptable.
        if(!$outcomes)return $commands===array();
        if(!$versions)return false;
        $latest=$versions[count($versions)-1];
        $latestVersionId=(int)($latest->id??0);
        $occurrence=(string)($latest->starts_at_utc??'');
        $occurrenceEnd=(string)($latest->ends_at_utc??'');
        if(!self::utc($occurrence)||!self::utc($occurrenceEnd)||$occurrenceEnd<=$occurrence)return false;
        $count=count($outcomes);$applicable=0;
        foreach($outcomes as$index=>$outcome){
            if((int)($outcome->outcome_sequence??0)!==$index+1||(int)($outcome->lesson_id??0)!==$lessonId)return false;
            if((int)($outcome->enrolment_id??0)!==(int)($lesson->enrolment_id??0)||(int)($outcome->term_id??0)!==(int)($lesson->term_id??0))return false;
            if((int)($outcome->teacher_assignment_id??0)!==(int)($lesson->teacher_assignment_id??0)||(int)($outcome->teacher_id??0)!==(int)($lesson->teacher_id??0))return false;
            $profile=self::profile((string)($outcome->outcome_code??''));
            if(!$profile)return false;
            if((string)($outcome->delivery_state??'')!==$profile['delivery_state'])return false;
            if((string)($outcome->attendance_state??'')!==$profile['attendance_state'])return false;
            if((string)($outcome->remedy_class??'')!==$profile['remedy_class'])return false;
            if((int)($outcome->occurrence_attempted??0)!==$profile['occurrence_attempted'])return false;
            // The outcome is bound to the exact canonical schedule version that governed it.
            if((int)($outcome->schedule_version_id??0)!==$latestVersionId)return false;
            if((string)($outcome->occurrence_starts_at_utc??'')!==$occurrence)return false;
            if((string)($outcome->occurrence_ends_at_utc??'')!==$occurrenceEnd)return false;
            if(!self::evidenceShape($outcome->reason_code??null,$outcome->evidence_channel??null,$outcome->evidence_reference_digest??null,$outcome->evidence_at??null))return false;
            if(!self::utc($outcome->recorded_at??null)||(int)($outcome->recorded_by??0)<1||(int)($outcome->created_by??0)<1)return false;
            $slot=$outcome->applicable_slot===null?0:(int)$outcome->applicable_slot;
            if($slot===1){$applicable++;if(($outcome->superseded_at??null)!==null)return false;}
            elseif($slot===0){if(($outcome->superseded_at??null)===null)return false;}
            else return false;
        }
        if($applicable!==1)return false;
        // Supersession lineage: every historical outcome names its exact successor, the applicable
        // outcome has no successor, and the applicable outcome is the last recorded fact.
        for($i=0;$i<$count-1;$i++){
            $stored=$outcomes[$i]->superseded_by_outcome_id===null?null:(int)$outcomes[$i]->superseded_by_outcome_id;
            if($stored!==(int)$outcomes[$i+1]->id)return false;
        }
        $last=$outcomes[$count-1];
        if((int)($last->applicable_slot??0)!==1||($last->superseded_at??null)!==null||($last->superseded_by_outcome_id??null)!==null)return false;
        $effective=(string)$last->outcome_code;
        // O-D1/O-D8: `completed` means delivered, so a known non-delivery or unresolved occurrence
        // may only coexist with a completed Lesson through the explicit append-only reconciliation
        // lineage that names the immutable historical completion event it supersedes.
        $reconciled=$last->reconciles_completion_event_id===null?null:(int)$last->reconciles_completion_event_id;
        // Canonical Lesson lifecycle authority is consumed whenever reconciliation lineage exists,
        // so completion is never established from a local approximation. A Lesson with no
        // reconciliation lineage is untouched by this gate, which keeps the Phase-M lifecycle
        // transition window (row updated before its append-only event) free of false conflicts.
        $terminal=null;
        if($reconciled!==null){
            if(!CanonicalLessonAuthorityValidator::valid($lesson,$lifecycle))return false;
            $terminal=$lifecycle[count($lifecycle)-1];
        }
        if(self::blocksCompletion($effective)&&(string)($lesson->lifecycle_state??'')==='completed'){
            if($reconciled===null||(int)($terminal->id??0)!==$reconciled||(string)($terminal->to_state??'')!=='completed')return false;
        }
        if($reconciled!==null){
            if((string)($lesson->lifecycle_state??'')!=='completed')return false;
            if($effective!=='teacher_non_delivery')return false;
            if((int)($terminal->id??0)!==$reconciled)return false;
        }
        // O-D5: one real-world event has exactly one authoritative meaning. A post-occurrence
        // non-delivery outcome may never coexist with the replacement-eligible cancellation reason
        // that Phase M uses for advance non-delivery attestation.
        if($effective==='teacher_non_delivery'){
            foreach($lifecycle as$event)if((string)($event->to_state??'')==='cancelled'&&(string)($event->reason_code??'')===self::COMPETING_CANCELLATION)return false;
        }
        if(!$assignment)return false;
        if((int)($assignment->id??0)!==(int)($lesson->teacher_assignment_id??0))return false;
        if((int)($assignment->enrolment_id??0)!==(int)($lesson->enrolment_id??0))return false;
        if((int)($assignment->teacher_id??0)!==(int)($lesson->teacher_id??0))return false;
        // Durable binding: every recorded command must still resolve to the exact outcome it
        // recorded for this Lesson, so a detached or re-parented outcome can never be served.
        $byId=array();
        foreach($outcomes as$outcome)$byId[(int)$outcome->id]=$outcome;
        foreach($commands as$command){
            if((int)($command->lesson_id??0)!==$lessonId)return false;
            $resultId=(int)($command->result_outcome_id??0);
            if($resultId<1||!isset($byId[$resultId]))return false;
            $recorded=$byId[$resultId];
            if((string)$recorded->outcome_code!==(string)$command->outcome_code)return false;
            if((string)$recorded->reason_code!==(string)$command->reason_code)return false;
            if((string)$recorded->evidence_channel!==(string)$command->evidence_channel)return false;
            if(!hash_equals((string)$recorded->evidence_reference_digest,(string)$command->evidence_reference_digest))return false;
            if((string)$recorded->evidence_at!==(string)$command->evidence_at)return false;
            if((string)$recorded->recorded_at!==(string)$command->created_at)return false;
            if(($command->supersedes_outcome_id??null)!==null){
                $prior=(int)$command->supersedes_outcome_id;
                if(!isset($byId[$prior]))return false;
                if((int)($byId[$prior]->superseded_by_outcome_id??0)!==$resultId)return false;
            }
        }
        return true;
    }

    /** O-D4: an academy/Teacher-caused non-delivery owes the Student a funded occurrence. */
    public static function academyObligation(object $origin,array $outcomes):bool{
        if((string)($origin->record_model??'')!==self::MODEL)return false;
        if((string)($origin->lesson_type??'')!=='standard')return false;
        if((string)($origin->lifecycle_state??'')==='completed')return false;
        $effective=null;
        foreach($outcomes as$outcome)if((int)($outcome->applicable_slot??0)===1)$effective=$outcome;
        if(!$effective)return false;
        $profile=self::profile((string)$effective->outcome_code);
        return (bool)($profile&&$profile['remedy_class']==='academy_obligation');
    }

    /** Effective outcome of a hydrated aggregate, or null when nothing exceptional is recorded. */
    public static function effective(array $outcomes):?object{
        $effective=null;
        foreach($outcomes as$outcome)if((int)($outcome->applicable_slot??0)===1)$effective=$outcome;
        return $effective;
    }

    /** Controlled reason/channel/digest/timestamp shape shared with Phase M and Phase N evidence. */
    public static function evidenceShape(mixed $reason,mixed $channel,mixed $digest,mixed $at):bool{
        $reason=(string)($reason??'');
        if($reason===''||strlen($reason)>64||!preg_match('/^[a-z0-9_]+$/D',$reason))return false;
        if(!in_array((string)($channel??''),self::CHANNELS,true))return false;
        if(!preg_match('/^[a-f0-9]{64}$/D',(string)($digest??'')))return false;
        return self::utc($at);
    }

    public static function utc(mixed $value):bool{
        $value=(string)($value??'');
        if(!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D',$value))return false;
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new \DateTimeZone('UTC'));
        return (bool)($parsed&&$parsed->format('Y-m-d H:i:s')===$value);
    }
}
