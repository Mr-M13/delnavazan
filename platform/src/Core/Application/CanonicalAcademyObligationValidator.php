<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalAcademyObligationRepository,CanonicalLessonAuthorityRepository,CanonicalLessonDeliveryRepository};

/**
 * Integrity gate for canonical academy-owed occurrences.
 *
 * An obligation is trustworthy only while it still names the exact source occurrence and the exact
 * source fact that created it: either the effective `teacher_non_delivery` outcome, or the
 * controlled advance-cancellation reason `canonical_lesson_cancelled_academy_unavailable`.
 */
final class CanonicalAcademyObligationValidator {
    private const MODEL='canonical_term_lesson_v1';
    private const KINDS=array('teacher_non_delivery','academy_cancellation');
    private const ACADEMY_CANCELLATION_REASON='canonical_lesson_cancelled_academy_unavailable';
    private const CHANNELS=array('staff_record','authenticated_platform','document_reference');
    public static function academyCancellationReason():string{return self::ACADEMY_CANCELLATION_REASON;}
    public static function kinds():array{return self::KINDS;}

    /** Hydrate and validate the obligations of one canonical Lesson against its source facts. */
    public static function validForLesson(int $lessonId,?CanonicalAcademyObligationRepository $repository=null,?CanonicalLessonAuthorityRepository $lessons=null,?CanonicalLessonDeliveryRepository $delivery=null,bool $lock=false):bool{
        $repository??=new CanonicalAcademyObligationRepository();
        $lessons??=new CanonicalLessonAuthorityRepository();
        $delivery??=new CanonicalLessonDeliveryRepository();
        $lesson=$lessons->lesson($lessonId,$lock);
        if(!$lesson)return false;
        $outcomes=$delivery->outcomesForLesson($lessonId,$lock);
        $lifecycle=$lessons->events($lessonId,$lock);
        return self::valid($lesson,$repository->forLesson($lessonId,$lock),$outcomes,$lifecycle,$lessons);
    }

    /**
     * Pure validation over hydrated rows.
     *
     * Canonical Lesson completion is decided by Lesson authority, never re-derived here. The source
     * Lesson aggregate (identity, provenance, replacement lineage and the append-only lifecycle
     * chain) must be canonical before any obligation lineage is accepted. The call is one-way —
     * Lesson authority never consults academy-obligation authority — so there is no cycle, no
     * recursive validation and no lock-order change; only reads are performed.
     */
    public static function valid(object $lesson,array $obligations,array $outcomes,array $lifecycle,?CanonicalLessonAuthorityRepository $lessons=null):bool{
        $lessonId=(int)($lesson->id??0);
        if($lessonId<1||(string)($lesson->record_model??'')!==self::MODEL)return false;
        // The gate runs exactly when completion lineage is being accepted, never for a Lesson that
        // carries no obligation. Lesson authority remains the only decider of canonical completion.
        if($obligations&&!CanonicalLessonAuthorityValidator::valid($lesson,$lifecycle,$lessons))return false;
        $effective=CanonicalLessonDeliveryValidator::effective($outcomes);
        $academyCancellation=false;
        foreach($lifecycle as$event)if((string)($event->to_state??'')==='cancelled'&&(string)($event->reason_code??'')===self::ACADEMY_CANCELLATION_REASON)$academyCancellation=true;
        $expected=array();
        if($effective&&(string)$effective->outcome_code==='teacher_non_delivery')$expected['teacher_non_delivery']=(int)$effective->id;
        if($academyCancellation)$expected['academy_cancellation']=null;
        if(!$expected)return $obligations===array();
        if(count($obligations)!==count($expected))return false;
        foreach($obligations as$obligation){
            $kind=(string)($obligation->source_kind??'');
            if(!in_array($kind,self::KINDS,true)||!array_key_exists($kind,$expected))return false;
            if((int)($obligation->source_lesson_id??0)!==$lessonId)return false;
            // Selector identities must agree with the validated source authority, never merely exist.
            if((int)($obligation->enrolment_id??0)!==(int)($lesson->enrolment_id??0))return false;
            if((int)($obligation->term_id??0)!==(int)($lesson->term_id??0))return false;
            if((int)($obligation->student_id??0)!==(int)($lesson->student_id??0))return false;
            if((int)($obligation->course_id??0)!==(int)($lesson->course_id??0))return false;
            if((int)($obligation->teacher_id??0)!==(int)($lesson->teacher_id??0))return false;
            if((int)($obligation->teacher_assignment_id??0)!==(int)($lesson->teacher_assignment_id??0))return false;
            if((string)($obligation->state??'')!=='owed')return false;
            if(!self::evidenceShape($obligation->reason_code??null,$obligation->evidence_channel??null,$obligation->evidence_reference_digest??null,$obligation->evidence_at??null))return false;
            if(!CanonicalLessonDeliveryValidator::utc($obligation->recorded_at??null)||(int)($obligation->recorded_by??0)<1||(int)($obligation->created_by??0)<1)return false;
            $sourceOutcome=$obligation->source_outcome_id===null?null:(int)$obligation->source_outcome_id;
            if($expected[$kind]!==null&&$sourceOutcome!==$expected[$kind])return false;
            // Occurrence anchors, when stored, must agree with the governing schedule version.
            if($obligation->schedule_version_id!==null){
                $version=null;
                foreach($outcomes as$outcome)if((int)($outcome->schedule_version_id??0)===(int)$obligation->schedule_version_id)$version=$outcome;
                if(!$version)return false;
                if((string)($obligation->occurrence_starts_at_utc??'')!==(string)$version->occurrence_starts_at_utc)return false;
                if((string)($obligation->occurrence_ends_at_utc??'')!==(string)$version->occurrence_ends_at_utc)return false;
            }elseif($kind==='teacher_non_delivery')return false;
            if($kind==='teacher_non_delivery'){
                if($sourceOutcome===null)return false;
                if((int)($obligation->schedule_version_id??0)!==(int)($effective->schedule_version_id??0))return false;
                // O-D8 lineage: the obligation is subordinate to the canonical reconciliation truth.
                // An ordinary non-delivered occurrence carries no completion event; a reconciled one
                // must name exactly the immutable completion event the effective outcome supersedes,
                // and that event must still be the Lesson's latest canonical completed event.
                $reconciledEvent=$effective->reconciles_completion_event_id===null?null:(int)$effective->reconciles_completion_event_id;
                $storedEvent=$obligation->source_event_id===null?null:(int)$obligation->source_event_id;
                if($reconciledEvent===null){
                    if($storedEvent!==null)return false;
                }else{
                    if($storedEvent===null||$storedEvent!==$reconciledEvent)return false;
                    if((string)($lesson->lifecycle_state??'')!=='completed')return false;
                    // The canonical chain validated above proves one legal progression from
                    // `authorised`, so the completed event is necessarily the chain's terminal event.
                    // Bind to that canonical fact rather than scanning raw rows for a maximum id.
                    $terminal=$lifecycle[count($lifecycle)-1];
                    if((int)($terminal->id??0)!==$reconciledEvent)return false;
                    if((string)($terminal->to_state??'')!=='completed')return false;
                    if((int)($terminal->lesson_id??0)!==$lessonId)return false;
                }
                // The obligation carries the exact evidence of the source fact it was raised from.
                if((string)($obligation->reason_code??'')!==(string)$effective->reason_code)return false;
                if((string)($obligation->evidence_channel??'')!==(string)$effective->evidence_channel)return false;
                if(!hash_equals((string)($obligation->evidence_reference_digest??''),(string)$effective->evidence_reference_digest))return false;
                if((string)($obligation->evidence_at??'')!==(string)$effective->evidence_at)return false;
            }else{
                $sourceEvent=null;
                foreach($lifecycle as$event)if((int)($event->id??0)===(int)($obligation->source_event_id??0))$sourceEvent=$event;
                if(!$sourceEvent||(string)($sourceEvent->reason_code??'')!==self::ACADEMY_CANCELLATION_REASON)return false;
                if((string)($obligation->reason_code??'')!==(string)$sourceEvent->reason_code)return false;
                if((string)($obligation->evidence_channel??'')!==(string)$sourceEvent->evidence_channel)return false;
                if(!hash_equals((string)($obligation->evidence_reference_digest??''),(string)$sourceEvent->evidence_reference_digest))return false;
                if((string)($obligation->evidence_at??'')!==(string)$sourceEvent->occurred_at)return false;
            }
        }
        return true;
    }

    private static function evidenceShape(mixed $reason,mixed $channel,mixed $digest,mixed $at):bool{
        $reason=(string)($reason??'');
        if($reason===''||strlen($reason)>64||!preg_match('/^[a-z0-9_]+$/D',$reason))return false;
        if(!in_array((string)($channel??''),self::CHANNELS,true))return false;
        if(!preg_match('/^[a-f0-9]{64}$/D',(string)($digest??'')))return false;
        return CanonicalLessonDeliveryValidator::utc($at);
    }
}
