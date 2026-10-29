<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{
    CanonicalAcademyObligationRepository,
    CanonicalAttendanceRepository,
    CanonicalLessonAuthorityRepository,
    CanonicalLessonDeliveryRepository,
    CanonicalLessonScheduleRepository
};

/**
 * Interim Lesson completion policy (Delnavazan Live Class compatible).
 *
 * A scheduled occurrence stays `authorised` until its canonical UTC end. After that, an occurrence
 * with no recorded outcome, no unresolved attendance review and no conflicting report may be
 * presumed completed. The presumption is a Lesson lifecycle fact only: it is deliberately NOT a
 * Phase-O `delivered` outcome, so it never claims independently verified attendance and cannot by
 * itself make a Lesson payable.
 *
 * The guard is provider-neutral. A future Live Class join/leave evidence source can submit claims
 * and evidence through the existing Phase-P intake authority; nothing here depends on Google Meet.
 */
final class CanonicalLessonCompletionPolicy {
    public const REASON_PRESUMED='canonical_lesson_presumed_completed';
    public const REASON_VERIFIED='canonical_lesson_completed';
    private const UNRESOLVED_CASE_STATES=array('open','ready_for_review','settlement_pending');

    public function __construct(
        private ?CanonicalLessonAuthorityRepository $lessons=null,
        private ?CanonicalLessonScheduleRepository $schedules=null,
        private ?CanonicalLessonDeliveryRepository $delivery=null,
        private ?CanonicalAttendanceRepository $attendance=null,
        private ?CanonicalAcademyObligationRepository $obligations=null
    ){
        $this->lessons??=new CanonicalLessonAuthorityRepository();
        $this->schedules??=new CanonicalLessonScheduleRepository();
        $this->delivery??=new CanonicalLessonDeliveryRepository();
        $this->attendance??=new CanonicalAttendanceRepository();
        $this->obligations??=new CanonicalAcademyObligationRepository();
    }

    /**
     * Prove, under the caller's held canonical locks, that silence is the only fact recorded for an
     * ended occurrence.
     */
    public function assertPresumable(int $lessonId,object $lesson,array $lifecycle,?string $now=null):void{
        $now??=gmdate('Y-m-d H:i:s');
        if((int)($lesson->id??0)!==$lessonId||(string)($lesson->record_model??'')!=='canonical_term_lesson_v1')throw new \InvalidArgumentException('canonical_lesson_required');
        if((string)($lesson->lifecycle_state??'')!=='authorised')throw new \InvalidArgumentException('stale_lesson_state');
        if(!CanonicalLessonAuthorityValidator::valid($lesson,$lifecycle,$this->lessons,true))throw new \InvalidArgumentException('canonical_lesson_integrity_conflict');

        $versions=$this->schedules->versionsForLesson($lessonId,true);
        $assignment=$this->lessons->assignmentById((int)($lesson->teacher_assignment_id??0),true);
        if(!CanonicalLessonScheduleValidator::validForLesson($lessonId,$this->schedules,$this->lessons,true))throw new \InvalidArgumentException('canonical_schedule_integrity_conflict');
        if(!$versions)throw new \InvalidArgumentException('schedule_required_for_completion_presumption');
        $version=$versions[count($versions)-1];
        // A released schedule retracts scheduling authority. Its historical version still exists,
        // but it is not a canonical occurrence and must never be presumed completed.
        if((int)($version->applicable_slot??0)!==1||$version->superseded_at!==null)throw new \InvalidArgumentException('occurrence_not_scheduled');
        if(!CanonicalLessonScheduleValidator::utc($now))throw new \InvalidArgumentException('canonical_completion_instant_required');
        if((string)$version->starts_at_utc>$now)throw new \InvalidArgumentException('occurrence_not_started');
        if((string)$version->ends_at_utc>$now)throw new \InvalidArgumentException('occurrence_not_ended');

        $outcomes=$this->delivery->outcomesForLesson($lessonId,true);
        $commands=$this->delivery->commandsForLesson($lessonId,true);
        $obligations=$this->obligations->forLesson($lessonId,true);
        if(!CanonicalLessonDeliveryValidator::valid($lesson,$outcomes,$commands,$obligations,$versions,$lifecycle,$assignment))throw new \InvalidArgumentException('canonical_delivery_integrity_conflict');
        if($outcomes!==array())throw new \InvalidArgumentException('delivery_outcome_exists');

        $cases=$this->attendance->casesForLesson($lessonId,true);
        $resolved=array();
        foreach($cases as $case){
            $state=(string)($case->state??'');
            if(in_array($state,self::UNRESOLVED_CASE_STATES,true))throw new \InvalidArgumentException('attendance_issue_unresolved');
            $resolved[(int)$case->id]=$state;
        }
        foreach($this->attendance->conflictsForLesson($lessonId,true) as $conflict){
            $caseId=(int)($conflict->case_id??0);
            $state=$resolved[$caseId]??'unresolved';
            if(in_array($state,self::UNRESOLVED_CASE_STATES,true)||!in_array($state,array('settled','closed_no_change','adjudicated'),true))throw new \InvalidArgumentException('attendance_conflict_unresolved');
        }
    }

    /** The recorded completion basis of a canonical Lesson: verified, presumed or none. */
    public static function completionBasis(array $lifecycle):string{
        $last=null;
        foreach($lifecycle as $event){
            if((string)($event->to_state??'')==='completed')$last=$event;
        }
        if($last===null)return 'none';
        return (string)($last->reason_code??'')===self::REASON_PRESUMED?'presumed':'verified';
    }
}
