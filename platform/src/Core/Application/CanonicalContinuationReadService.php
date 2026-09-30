<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalContinuationRepository;

/**
 * Internal privacy-minimised protected read for the Phase-Q continuation aggregate.
 *
 * It exposes only what later Portal/Payment orchestration needs: stable references, the current
 * continuation decision, whether administrator action is required, the single expected first regular
 * slot, its frozen hold expiry and whether that hold is currently capacity-effective. It validates the
 * complete aggregate, fails closed on corrupted lineage or impossible reservation state, remains
 * read-only, never mutates state or opens a write transaction, and returns no guardian evidence
 * internals and no unnecessary PII.
 */
final class CanonicalContinuationReadService {
    private const CAPABILITY='dzn_view_canonical_continuation';
    public function __construct(private ?CanonicalContinuationRepository $repository=null){
        $this->repository??=new CanonicalContinuationRepository();
    }

    /** @return array<string,mixed> */
    public function forIntroLesson(int $introLessonId):array{
        if(!current_user_can(self::CAPABILITY))throw new \RuntimeException('Unauthorized');
        $case=$this->repository->caseForIntro($introLessonId);
        if(!$case)throw new \InvalidArgumentException('continuation_case_required');
        return $this->compose($case);
    }

    /** @return array<string,mixed> */
    public function forCase(int $caseId):array{
        if(!current_user_can(self::CAPABILITY))throw new \RuntimeException('Unauthorized');
        $case=$this->repository->caseById($caseId);
        if(!$case)throw new \InvalidArgumentException('continuation_case_required');
        return $this->compose($case);
    }

    /** @return array<string,mixed> */
    private function compose(object $case):array{
        $caseId=(int)$case->id;
        if(!CanonicalContinuationValidator::validForCase($caseId,$this->repository))throw new \InvalidArgumentException('canonical_continuation_integrity_conflict');
        $reservation=$this->repository->reservationForCase($caseId);
        $interventions=$this->repository->interventionsForCase($caseId);
        $decisions=$this->repository->decisionsForCase($caseId);
        $now=gmdate('Y-m-d H:i:s');
        $hold=$reservation===null?null:array(
            'reservation_id'=>(int)$reservation->id,
            'state'=>(string)$reservation->state,
            'teacher_id'=>(int)$reservation->teacher_id,
            'starts_at_utc'=>(string)$reservation->starts_at_utc,
            'ends_at_utc'=>(string)$reservation->ends_at_utc,
            'occupied_ends_at_utc'=>(string)$reservation->occupied_ends_at_utc,
            'schedule_timezone'=>(string)$reservation->schedule_timezone,
            'local_wall_date'=>(string)$reservation->local_wall_date,
            'local_wall_time'=>(string)$reservation->local_wall_time,
            'duration_minutes'=>(int)$reservation->duration_minutes,
            'buffer_minutes'=>(int)$reservation->buffer_minutes,
            'reserved_at'=>(string)$reservation->reserved_at,
            'expires_at'=>(string)$reservation->expires_at,
            'capacity_effective'=>CanonicalContinuationRule::capacityEffective((string)$reservation->state,(string)$reservation->expires_at,$now),
            'rule_version'=>(string)$reservation->rule_version,
        );
        $reasons=array();
        $currentReasons=array();$history=array();
        foreach($interventions as$intervention){
            $reason=(string)$intervention->reason_code;
            $history[]=$reason;
            if((string)$intervention->state==='required')$currentReasons[]=$reason;
        }
        // A resolved requirement is retained as history and is no longer actionable.
        return array(
            'case'=>array(
                'continuation_case_id'=>$caseId,'case_uid'=>(string)$case->uid,'case_version'=>(int)$case->case_version,
                'current_decision'=>(string)$case->current_decision,'decision_at'=>$case->decision_at===null?null:(string)$case->decision_at,
                'decision_count'=>count($decisions),'rule_version'=>(string)$case->rule_version,
                'admin_action_required'=>(bool)$currentReasons,
            ),
            'source'=>array(
                'intro_lesson_id'=>(int)$case->intro_lesson_id,'intro_schedule_version_id'=>(int)$case->intro_schedule_version_id,
                'intro_starts_at_utc'=>(string)$case->intro_starts_at_utc,'intro_ends_at_utc'=>(string)$case->intro_ends_at_utc,
                'intro_timezone'=>(string)$case->intro_timezone,
                'student_id'=>(int)$case->student_id,'teacher_id'=>(int)$case->teacher_id,'course_id'=>(int)$case->course_id,
                'enrolment_id'=>$case->enrolment_id===null?null:(int)$case->enrolment_id,
                'teacher_assignment_id'=>$case->teacher_assignment_id===null?null:(int)$case->teacher_assignment_id,
                'accepted_service_arrangement_id'=>$case->accepted_service_arrangement_id===null?null:(int)$case->accepted_service_arrangement_id,
            ),
            'reservation'=>$hold,
            'reservation_decision_id'=>$reservation===null?null:(int)$reservation->decision_id,
            'admin'=>array(
                'intervention_count'=>count($interventions),
                'current_intervention_count'=>count($currentReasons),
                'reason_codes'=>$history,'current_reason_codes'=>$currentReasons,
            ),
        );
    }
}
