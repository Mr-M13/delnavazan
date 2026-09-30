<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalLessonAuthorityRepository;

/**
 * Single canonical hydration and integrity gate for canonical Lesson aggregates.
 *
 * Structural lifecycle validity alone is insufficient: a canonical Lesson is trustworthy
 * only while every immutable relationship recorded at issuance stays coherent. The
 * aggregate context is hydrated here, through the canonical Lesson persistence boundary,
 * so no consumer can validate a subtly weaker version of the same aggregate.
 *
 * A historical Lesson must stay valid after its Teacher Assignment was replaced. The
 * recorded Assignment is therefore validated as structurally belonging to the Lesson
 * enrolment with the recorded immutable Teacher; it is never required to remain the
 * applicable (current) Assignment.
 */
final class CanonicalLessonAuthorityValidator {
    private const MODEL='canonical_term_lesson_v1';
    private const TERM_MODEL='canonical_enrolment_term_v1';
    private const ENROLMENT_MODEL='canonical_student_course_v1';
    private const STATES=array('authorised','completed','cancelled');
    private const TYPES=array('standard','replacement');
    private const REPLACEMENT_ELIGIBLE_REASON='canonical_lesson_cancelled_replacement_eligible';

    /**
     * Hydrate and validate the complete immutable canonical Lesson aggregate.
     *
     * Callers inside an existing transaction pass their repository and lock intent so the
     * aggregate context is read on the same connection and lock scope as the mutation.
     */
    public static function valid(object $lesson,array $events,?CanonicalLessonAuthorityRepository $repository=null,bool $lock=false):bool{
        $repository??=new CanonicalLessonAuthorityRepository();
        $origin=null;$originEvents=array();$originTerm=null;$originEnrolment=null;$originAssignment=null;
        $originId=(int)($lesson->canonical_replacement_origin_lesson_id??0);
        if((string)($lesson->lesson_type??'')==='replacement'&&$originId>0){
            $origin=$repository->lesson($originId,$lock);
            if($origin){
                $originEvents=$repository->events((int)($origin->id??0),$lock);
                $originTerm=$repository->term((int)($origin->term_id??0),$lock);
                $originEnrolment=$repository->enrolment((int)($origin->enrolment_id??0),$lock);
                $originAssignment=$repository->assignmentById((int)($origin->teacher_assignment_id??0),$lock);
            }
        }
        return self::integrity(
            $lesson,
            $events,
            $repository->term((int)($lesson->term_id??0),$lock),
            $repository->enrolment((int)($lesson->enrolment_id??0),$lock),
            $repository->assignmentById((int)($lesson->teacher_assignment_id??0),$lock),
            $origin,
            $originEvents,
            $originTerm,
            $originEnrolment,
            $originAssignment
        );
    }

    /**
     * Controlled replacement eligibility: generic cancellation alone is never sufficient. This is
     * the Phase-M Student replacement allowance only; a Phase-O academy-owed occurrence is DISTINCT
     * canonical authority and is never materialised as a `replacement` Lesson.
     */
    public static function replacementEligible(object $origin,array $events):bool{
        if((string)($origin->record_model??'')!==self::MODEL)return false;
        if((string)($origin->lifecycle_state??'')!=='cancelled')return false;
        foreach($events as$event)if((string)($event->to_state??'')==='cancelled'&&(string)($event->reason_code??'')===self::REPLACEMENT_ELIGIBLE_REASON)return true;
        return false;
    }

    private static function integrity(object $lesson,array $events,?object $term,?object $enrolment,?object $assignment,?object $origin,array $originEvents,?object $originTerm,?object $originEnrolment,?object $originAssignment):bool{
        if(!self::structure($lesson,$events))return false;
        if((string)($lesson->status??'')!=='canonical')return false;
        if(($lesson->archived_at??null)!==null||$lesson->replacement_for_lesson_id!==null)return false;
        if((string)($lesson->lesson_type??'')==='standard'){
            if($lesson->canonical_replacement_origin_lesson_id!==null)return false;
            return self::provenance($lesson,$term,$enrolment,$assignment);
        }
        if((int)($lesson->canonical_replacement_origin_lesson_id??0)<1)return false;
        if(!self::provenance($lesson,$term,$enrolment,$assignment))return false;
        if(!$origin||!self::structure($origin,$originEvents))return false;
        if((string)($origin->lesson_type??'')!=='standard')return false;
        if((int)($origin->id??0)===(int)($lesson->id??0))return false;
        if((int)($origin->term_id??0)!==(int)($lesson->term_id??0))return false;
        if(!self::replacementEligible($origin,$originEvents))return false;
        if(!self::provenance($origin,$originTerm,$originEnrolment,$originAssignment))return false;
        return true;
    }

    /** Immutable provenance recorded at issuance; a replaced Assignment stays structurally valid. */
    private static function provenance(object $lesson,?object $term,?object $enrolment,?object $assignment):bool{
        if(!$term||(string)($term->record_model??'')!==self::TERM_MODEL)return false;
        if(!$enrolment||(string)($enrolment->record_model??'')!==self::ENROLMENT_MODEL)return false;
        if((int)($lesson->term_id??0)!==(int)($term->id??0))return false;
        if((int)($term->enrolment_id??0)!==(int)($enrolment->id??0))return false;
        if((int)($lesson->enrolment_id??0)!==(int)($term->enrolment_id??0))return false;
        if((int)($lesson->enrolment_id??0)!==(int)($enrolment->id??0))return false;
        if((int)($lesson->student_id??0)<1||(int)($lesson->student_id??0)!==(int)($enrolment->student_id??0))return false;
        if((int)($lesson->course_id??0)<1||(int)($lesson->course_id??0)!==(int)($enrolment->course_id??0))return false;
        if(!$assignment)return false;
        if((int)($assignment->id??0)!==(int)($lesson->teacher_assignment_id??0))return false;
        if((int)($assignment->enrolment_id??0)!==(int)($lesson->enrolment_id??0))return false;
        if((int)($lesson->teacher_id??0)<1||(int)($lesson->teacher_id??0)!==(int)($assignment->teacher_id??0))return false;
        return true;
    }

    /** Append-only lifecycle chain: one legal progression from authorised to the recorded state. */
    private static function structure(object $lesson,array $events):bool{
        if(($lesson->record_model??null)!==self::MODEL||!in_array((string)($lesson->lifecycle_state??''),self::STATES,true)||(int)($lesson->enrolment_id??0)<1||(int)($lesson->term_id??0)<1||(int)($lesson->teacher_assignment_id??0)<1||(int)($lesson->teacher_id??0)<1||(int)($lesson->canonical_sequence??0)<1||!in_array((string)($lesson->lesson_type??''),self::TYPES,true))return false;
        if(!$events)return false;$state=null;$sequence=1;
        foreach($events as$event){if((int)($event->event_sequence??0)!==$sequence++||(string)($event->to_state??'')===''||!in_array((string)$event->to_state,self::STATES,true)||$event->from_state!==$state)return false;if($state===null&&$event->to_state!=='authorised')return false;if($state!==null&&$state!=='authorised')return false;$state=$event->to_state;}
        return $state===$lesson->lifecycle_state;
    }
}
