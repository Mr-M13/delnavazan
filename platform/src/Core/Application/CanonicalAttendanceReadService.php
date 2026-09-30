<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalAcademyObligationRepository,CanonicalAttendanceRepository,CanonicalLessonAuthorityRepository,CanonicalLessonDeliveryRepository,CanonicalLessonScheduleRepository};

/**
 * Protected Phase-P occurrence-review read model.
 *
 * It composes the existing validated canonical reads (Lesson lifecycle, schedule version, Phase-O
 * delivery truth, academy obligation) with Phase-P intake state. It exposes no raw provider payload,
 * it never recomputes under current code and presents a corrupt stored case as trusted, and it fails
 * closed when any composed canonical aggregate — identity registry, cutover policy binding, rule
 * version, decision chain, anomaly classification or Phase-O result link — is corrupt.
 */
final class CanonicalAttendanceReadService {
    private const CAPABILITY='dzn_view_canonical_attendance_review';
    public function __construct(
        private ?CanonicalAttendanceRepository $repository=null,
        private ?CanonicalLessonAuthorityRepository $lessons=null,
        private ?CanonicalLessonScheduleRepository $schedules=null,
        private ?CanonicalLessonDeliveryRepository $delivery=null,
        private ?CanonicalAcademyObligationRepository $obligations=null
    ){
        $this->repository??=new CanonicalAttendanceRepository();
        $this->lessons??=new CanonicalLessonAuthorityRepository();
        $this->schedules??=new CanonicalLessonScheduleRepository();
        $this->delivery??=new CanonicalLessonDeliveryRepository();
        $this->obligations??=new CanonicalAcademyObligationRepository();
    }

    /** @return array<string,mixed> */
    public function forOccurrence(int $lessonId,int $scheduleVersionId):array{
        if(!current_user_can(self::CAPABILITY))throw new \RuntimeException('Unauthorized');
        $case=$this->repository->caseFor($lessonId,$scheduleVersionId);
        if(!$case)throw new \InvalidArgumentException('canonical_attendance_case_required');
        if(!CanonicalAttendanceValidator::validForCase((int)$case->id,$this->repository,$this->lessons,$this->schedules))throw new \InvalidArgumentException('canonical_attendance_integrity_conflict');
        $lesson=$this->lessons->lesson($lessonId);
        if(!$lesson||!CanonicalLessonAuthorityValidator::valid($lesson,$this->lessons->events($lessonId),$this->lessons))throw new \InvalidArgumentException('canonical_attendance_integrity_conflict');
        $version=$this->schedules->version($scheduleVersionId);
        if(!$version||!CanonicalLessonScheduleValidator::validForLesson($lessonId,$this->schedules,$this->lessons))throw new \InvalidArgumentException('canonical_attendance_integrity_conflict');
        $versions=$this->schedules->versionsForLesson($lessonId);
        $latest=$versions?$versions[count($versions)-1]:null;
        // The protected current-attendance read must fail closed unless the case is bound to the
        // exact CURRENT schedule version for this Lesson, exactly like reassessment, adjudication and
        // settlement. A stale historical aggregate is retained but never presented as current truth.
        if(!$latest||(int)$latest->id!==(int)$case->schedule_version_id)throw new \InvalidArgumentException('schedule_version_conflict');
        $policy=$this->repository->policy((int)$case->cutover_policy_id);
        $evidence=$this->repository->evidenceForCase((int)$case->id);
        $decisions=$this->repository->decisionsForCase((int)$case->id);
        $anomalies=$this->repository->anomaliesForCase((int)$case->id);
        $conflicts=$this->repository->conflictsForCase((int)$case->id);
        $effective=CanonicalLessonDeliveryValidator::effective($this->delivery->outcomesForLesson($lessonId));
        $outcomes=$this->delivery->outcomesForLesson($lessonId);
        $term=(string)$this->termState((int)$case->term_id);
        $latestDecision=$decisions?$decisions[count($decisions)-1]:null;
        $provider=array();$claims=array();$intervals=array();
        $counts=array('provider_interval'=>0,'human_claim'=>0,'verified'=>0,'unverified'=>0,'resolved'=>0,'unresolved'=>0,'ambiguous'=>0,'mismatch'=>0,'conflict'=>count($conflicts));
        foreach($evidence as$row){
            if((string)$row->evidence_kind==='provider_interval'){
                $counts['provider_interval']++;
                $identity=(string)$row->participant_identity_state;
                $verification=(string)$row->verification_state;
                if($verification==='verified')$counts['verified']++;else $counts['unverified']++;
                if($identity==='resolved')$counts['resolved']++;elseif($identity==='ambiguous')$counts['ambiguous']++;else $counts['unresolved']++;
                $role=(string)$row->participant_role;
                $expected=$role==='teacher'?(int)$case->teacher_id:(int)$case->student_id;
                $resolved=$role==='teacher'?(int)($row->resolved_teacher_id??0):(int)($row->resolved_student_id??0);
                $mismatch=$verification==='verified'&&$identity==='resolved'&&$resolved!==$expected;
                if($mismatch)$counts['mismatch']++;
                $provider[]=array(
                    'evidence_id'=>(int)$row->id,'provider_code'=>(string)$row->provider_code,
                    'participant_role'=>$role,
                    'participant_identity_state'=>$identity,
                    'verification_state'=>$verification,
                    'resolved_participant_matches_occurrence'=>!$mismatch&&$identity==='resolved',
                    'join_at_utc'=>$row->join_at_utc,'leave_at_utc'=>$row->leave_at_utc,
                    'observed_at'=>(string)$row->observed_at,
                );
                if($verification==='verified'&&$identity==='resolved'&&!$mismatch){
                    $intervals[]=array('role'=>$role,'join_at_utc'=>$row->join_at_utc,'leave_at_utc'=>$row->leave_at_utc);
                }
            }else{
                $counts['human_claim']++;
                $claims[]=array(
                    'evidence_id'=>(int)$row->id,'evidence_kind'=>(string)$row->evidence_kind,
                    'source_kind'=>(string)$row->source_kind,'attribution'=>$row->attribution,
                    'reason_code'=>(string)$row->reason_code,'observed_at'=>(string)$row->observed_at,
                    'received_at'=>(string)$row->received_at,'created_by'=>(int)$row->created_by,
                );
            }
        }
        $teacher=array();$student=array();
        foreach($intervals as$interval)if($interval['role']==='teacher')$teacher[]=$interval;else $student[]=$interval;
        $assessment=CanonicalAttendanceRule::assess($teacher,$student,(string)$case->occurrence_start_utc,(string)$case->occurrence_end_utc);
        // The stored decision chain must agree with the stored evidence: a divergence is corruption,
        // never a silently recomputed "trusted" answer.
        $recordedOverlap=null;
        foreach($decisions as$decision)if((string)$decision->decision_kind==='assessment'&&$decision->qualifying_overlap_seconds!==null)$recordedOverlap=(int)$decision->qualifying_overlap_seconds;
        if($recordedOverlap!==null&&$recordedOverlap!==(int)$assessment['seconds'])throw new \InvalidArgumentException('canonical_attendance_integrity_conflict');
        $decisionAnomalies=array();$decisionKinds=array();
        foreach($anomalies as$anomaly)$decisionAnomalies[]=(string)$anomaly->code;
        foreach($decisions as$decision)$decisionKinds[]=(string)$decision->decision_kind;
        return array(
            'case'=>array(
                'case_id'=>(int)$case->id,'case_uid'=>(string)$case->uid,'state'=>(string)$case->state,
                'case_version'=>(int)$case->case_version,'rule_version'=>(string)$case->rule_version,
                'latest_decision_id'=>$case->latest_decision_id===null?null:(int)$case->latest_decision_id,
            ),
            'context'=>array(
                'lesson_id'=>$lessonId,'lesson_state'=>(string)$lesson->lifecycle_state,
                'enrolment_id'=>(int)$case->enrolment_id,'enrolment_state'=>(string)$this->enrolmentState((int)$case->enrolment_id),
                'term_id'=>(int)$case->term_id,'term_state'=>$term,
                'schedule_version_id'=>$scheduleVersionId,
                'occurrence_start_utc'=>(string)$case->occurrence_start_utc,'occurrence_end_utc'=>(string)$case->occurrence_end_utc,
                'qualifying_window_end_utc'=>(string)$case->window_end_utc,
                'expected_student_id'=>(int)$case->student_id,'expected_teacher_id'=>(int)$case->teacher_id,
                'teacher_assignment_id'=>(int)$case->teacher_assignment_id,
            ),
            'cutover'=>array(
                'policy_id'=>$policy===null?null:(int)$policy->id,
                'policy_version'=>$policy===null?null:(string)$policy->policy_version,
                'cutover_utc'=>$policy===null?null:(string)$policy->cutover_utc,
                'rule_version'=>$policy===null?null:(string)$policy->rule_version,
                'threshold_seconds'=>$policy===null?null:(int)$policy->threshold_seconds,
                'pre_grace_seconds'=>$policy===null?null:(int)$policy->pre_grace_seconds,
                'post_grace_seconds'=>$policy===null?null:(int)$policy->post_grace_seconds,
            ),
            'evidence'=>array('counts'=>$counts,'provider'=>$provider,'intervals'=>$intervals,'excluded'=>$assessment['excluded']),
            'assessment'=>array(
                'rule_version'=>CanonicalAttendanceRule::RULE_VERSION,
                'threshold_seconds'=>CanonicalAttendanceRule::THRESHOLD_SECONDS,
                'pre_grace_seconds'=>CanonicalAttendanceRule::PRE_GRACE_SECONDS,
                'post_grace_seconds'=>CanonicalAttendanceRule::POST_GRACE_SECONDS,
                'qualifying_overlap_seconds'=>(int)$assessment['seconds'],
                'recorded_overlap_seconds'=>$recordedOverlap,
                'segments'=>$assessment['segments'],
                'automatic_success_eligible'=>(bool)$assessment['eligible'],
                'anomaly_codes'=>array_values(array_unique($decisionAnomalies)),
                'evidence_set_digest'=>$this->evidenceSetDigest($evidence),
            ),
            'claims'=>$claims,
            'canonical_truth'=>array(
                'effective_outcome'=>$effective===null?null:(string)$effective->outcome_code,
                'delivery_state'=>$effective===null?null:(string)$effective->delivery_state,
                'reconciled'=>$effective!==null&&$effective->reconciles_completion_event_id!==null,
                'outcome_history'=>array_map(static fn($row)=>array('outcome_id'=>(int)$row->id,'outcome_code'=>(string)$row->outcome_code,'applicable_slot'=>$row->applicable_slot===null?null:(int)$row->applicable_slot),$outcomes),
                'academy_obligation_count'=>count($this->obligations->forLesson($lessonId)),
            ),
            'review'=>array(
                'latest_decision'=>$latestDecision===null?null:array('decision_id'=>(int)$latestDecision->id,'decision_kind'=>(string)$latestDecision->decision_kind,'state_after'=>(string)$latestDecision->state_after,'created_at'=>(string)$latestDecision->created_at),
                'decision_count'=>count($decisions),
                'decision_kinds'=>$decisionKinds,
                'anomaly_count'=>count($anomalies),
                'conflict_count'=>count($conflicts),
                'conflicts'=>array_map(static fn($row)=>array('conflict_id'=>(int)$row->id,'conflict_kind'=>(string)$row->conflict_kind,'detected_at'=>(string)$row->detected_at),$conflicts),
                'late_evidence'=>in_array('late_evidence',$decisionAnomalies,true),
                'term_closed'=>$term!=='current',
                'closed_no_change'=>(string)$case->state==='closed_no_change',
            ),
        );
    }

    private function evidenceSetDigest(array $evidence):string{
        $parts=array();
        foreach($evidence as$row)$parts[]=(string)$row->uid;
        sort($parts);
        // An occurrence may legitimately carry no evidence yet; that is a distinct, stable digest
        // rather than a read failure.
        return CanonicalAttendanceIdempotency::evidence($parts?implode('|',$parts):'attendance-case-no-evidence');
    }

    private function termState(int $termId):string{
        global $wpdb;
        return (string)$wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$wpdb->prefix}dzn_terms WHERE id=%d",$termId));
    }

    private function enrolmentState(int $enrolmentId):string{
        global $wpdb;
        return (string)$wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$wpdb->prefix}dzn_enrolments WHERE id=%d",$enrolmentId));
    }
}
