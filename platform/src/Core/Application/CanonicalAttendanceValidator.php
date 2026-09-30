<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalAttendanceRepository,CanonicalLessonAuthorityRepository,CanonicalLessonScheduleRepository};

/**
 * Single hydration and integrity gate for canonical attendance intake aggregates.
 *
 * Intake evidence and decisions describe *what was observed or claimed*; they are never delivery
 * truth. Phase O remains the only owner of canonical delivery/attendance truth, and Lesson authority
 * remains the only owner of completion. This validator therefore proves only that the intake
 * aggregate is coherent, exactly bound to its canonical Lesson occurrence, bound to the exact
 * prospective cutover policy that admitted it, and that every provider interval resolves through the
 * durable provider-neutral participant identity registry rather than through caller assertion.
 */
final class CanonicalAttendanceValidator {
    public const STATES=array('open','ready_for_review','settlement_pending','settled','adjudicated','closed_no_change','superseded');
    public const EVIDENCE_KINDS=array('provider_interval','advance_absence_claim','attendance_claim','delivery_claim','review_request','admin_note');
    public const SOURCE_KINDS=array('provider','student','teacher','administrator');
    public const PARTICIPANT_ROLES=array('teacher','student');
    public const IDENTITY_STATES=array('resolved','unknown','ambiguous');
    public const VERIFICATION_STATES=array('verified','unverified');
    public const MAPPING_STATES=array('verified','unverified','revoked');
    public const CONFLICT_KINDS=array('changed_payload','cross_context','cross_lesson','cross_schedule_version');
    public const DECISION_KINDS=array('assessment','settlement_requested','settlement_completed','anomaly_opened','admin_adjudication','closed_no_change','late_evidence_review');
    public const ANOMALY_CODES=array(
        'teacher_participation_unproven','student_participation_unproven','overlap_below_threshold',
        'provider_evidence_missing','provider_evidence_incomplete','provider_evidence_conflicting',
        'participant_unknown','participant_ambiguous','participant_identity_unverified','participant_identity_mismatch','provider_identity_unmapped',
        'duplicate_event_conflict','impossible_interval',
        'late_evidence','provider_outage','lesson_cancelled','canonical_outcome_exists',
        'canonical_truth_conflict','schedule_version_conflict','canonical_integrity_conflict',
        'cutover_policy_missing','cutover_policy_conflict',
    );

    /** Hydrate and validate one intake aggregate against its canonical Lesson occurrence. */
    public static function validForCase(int $caseId,?CanonicalAttendanceRepository $repository=null,?CanonicalLessonAuthorityRepository $lessons=null,?CanonicalLessonScheduleRepository $schedules=null,bool $lock=false,?array $capabilityAudit=null):bool{
        $repository??=new CanonicalAttendanceRepository();
        $lessons??=new CanonicalLessonAuthorityRepository();
        $schedules??=new CanonicalLessonScheduleRepository();
        $case=$repository->caseById($caseId,$lock);
        if(!$case)return false;
        $lesson=$lessons->lesson((int)$case->lesson_id,$lock);
        $version=$schedules->version((int)$case->schedule_version_id,$lock);
        $assignment=$lessons->assignmentById((int)$case->teacher_assignment_id,$lock);
        $evidence=$repository->evidenceForCase($caseId,$lock);
        $digests=array();
        foreach($evidence as$row)$digests[]=(string)($row->provider_account_digest??'');
        // The identity registry and the prospective cutover policy are immutable authority rows shared
        // by many occurrences: they are read without row locks so one occurrence can never serialise
        // unrelated intake work, and no lock-order edge is introduced into the write path.
        $mappings=$repository->mappingsForDigests($digests,false);
        $policy=$repository->policy((int)($case->cutover_policy_id??0),false);
        $ok=self::valid(
            $case,
            $evidence,
            $repository->decisionsForCase($caseId,$lock),
            $repository->anomaliesForCase($caseId),
            $lesson,
            $version,
            $assignment,
            $mappings,
            $policy,
            $capabilityAudit,
            $lock
        );        if(!$ok)return false;
        // Any recorded Phase-O result link must resolve to an outcome of this exact Lesson.
        $outcomeIds=array();
        foreach((new \Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalLessonDeliveryRepository())->outcomesForLesson((int)$case->lesson_id,$lock)as$outcome)$outcomeIds[(int)$outcome->id]=(string)$outcome->lesson_id;
        foreach($repository->decisionsForCase($caseId,$lock)as$decision){
            $linked=$decision->result_outcome_id===null?null:(int)$decision->result_outcome_id;
            if($linked!==null&&!isset($outcomeIds[$linked]))return false;
        }
        return true;
    }

    /** Pure aggregate validation over hydrated rows. */
    public static function valid(object $case,array $evidence,array $decisions,array $anomalies,?object $lesson,?object $version,?object $assignment,array $mappings=array(),?object $policy=null,?array $capabilityAudit=null,bool $lock=false):bool{
        $caseId=(int)($case->id??0);
        if($caseId<1||(string)($case->uid??'')==='')return false;
        foreach(array('lesson_id','schedule_version_id','enrolment_id','term_id','student_id','teacher_id','teacher_assignment_id')as$field)if((int)($case->{$field}??0)<1)return false;
        $start=(string)($case->occurrence_start_utc??'');$end=(string)($case->occurrence_end_utc??'');$windowEnd=(string)($case->window_end_utc??'');
        if(!self::utc($start)||!self::utc($end)||!self::utc($windowEnd))return false;
        if((int)strtotime($end.' UTC')<=(int)strtotime($start.' UTC'))return false;
        if((int)strtotime($windowEnd.' UTC')!==(int)strtotime($end.' UTC')+CanonicalAttendanceRule::POST_GRACE_SECONDS)return false;
        if(!in_array((string)($case->state??''),self::STATES,true))return false;
        if((int)($case->case_version??0)<1)return false;
        if(!self::utc($case->created_at??null))return false;
        $publicCapabilityAudit=self::validPublicCapabilityAudit($case,$evidence,$capabilityAudit,$lock);
        if((int)($case->created_by??0)<1&&((int)($case->created_by??0)!==0||!$publicCapabilityAudit))return false;
        // Locked rule identity: a case may never claim a rule version the platform does not recognise.
        if((string)($case->rule_version??'')!==CanonicalAttendanceRule::RULE_VERSION)return false;
        // Exact prospective-cutover-policy binding frozen at admission.
        $policyId=(int)($case->cutover_policy_id??0);
        if($policyId<1||!$policy)return false;
        if((int)($policy->id??0)!==$policyId)return false;
        if((string)($policy->policy_version??'')==='')return false;
        if((string)($policy->rule_version??'')!==CanonicalAttendanceRule::RULE_VERSION)return false;
        if((int)($policy->threshold_seconds??-1)!==CanonicalAttendanceRule::THRESHOLD_SECONDS)return false;
        if((int)($policy->pre_grace_seconds??-1)!==CanonicalAttendanceRule::PRE_GRACE_SECONDS)return false;
        if((int)($policy->post_grace_seconds??-1)!==CanonicalAttendanceRule::POST_GRACE_SECONDS)return false;
        if(!self::utc($policy->cutover_utc??null))return false;
        if((int)strtotime($start.' UTC')<(int)strtotime((string)$policy->cutover_utc.' UTC'))return false;
        if(!$lesson||(string)($lesson->record_model??'')!=='canonical_term_lesson_v1')return false;
        if((int)$lesson->enrolment_id!==(int)$case->enrolment_id||(int)$lesson->term_id!==(int)$case->term_id)return false;
        if((int)$lesson->student_id!==(int)$case->student_id||(int)$lesson->teacher_id!==(int)$case->teacher_id)return false;
        if((int)$lesson->teacher_assignment_id!==(int)$case->teacher_assignment_id)return false;
        if(!$version||(int)$version->lesson_id!==(int)$case->lesson_id)return false;
        if((string)$version->starts_at_utc!==$start||(string)$version->ends_at_utc!==$end)return false;
        if(!$assignment||(int)$assignment->id!==(int)$case->teacher_assignment_id||(int)$assignment->enrolment_id!==(int)$case->enrolment_id)return false;
        $registry=self::registry($mappings);
        $seenEventKeys=array();
        foreach($evidence as$row){
            if((int)($row->case_id??0)!==$caseId)return false;
            if((int)($row->lesson_id??0)!==(int)$case->lesson_id||(int)($row->schedule_version_id??0)!==(int)$case->schedule_version_id)return false;
            if(!in_array((string)($row->evidence_kind??''),self::EVIDENCE_KINDS,true))return false;
            if(!in_array((string)($row->source_kind??''),self::SOURCE_KINDS,true))return false;
            if(!self::utc($row->received_at??null)||!self::utc($row->observed_at??null))return false;
            if((int)($row->created_by??0)<1&&((int)($row->created_by??0)!==0||!$publicCapabilityAudit||(string)($row->attribution??'')!=='public_capability_on_behalf'||(string)($row->reason_code??'')!=='public_capability_absence'))return false;
            if((string)($row->evidence_reference_digest??'')==='')return false;
            if(!preg_match('/^[a-f0-9]{64}$/D',(string)$row->evidence_reference_digest))return false;
            $eventKey=(string)($row->provider_event_key_digest??'');
            if($eventKey!==''){
                if(!preg_match('/^[a-f0-9]{64}$/D',$eventKey))return false;
                if(isset($seenEventKeys[$eventKey]))return false;
                $seenEventKeys[$eventKey]=true;
                if(!self::digest($row->provider_payload_digest??null))return false;
                if((string)($row->provider_code??'')==='')return false;
                if(!in_array((string)($row->verification_state??''),self::VERIFICATION_STATES,true))return false;
                // Provider identity must be a durable, provider-neutral account digest.
                if(!self::digest($row->provider_account_digest??null))return false;
            }
            $identity=(string)($row->participant_identity_state??'');
            if($identity!=='')if(!in_array($identity,self::IDENTITY_STATES,true))return false;
            $role=(string)($row->participant_role??'');
            if($role!=='')if(!in_array($role,self::PARTICIPANT_ROLES,true))return false;
            if($identity==='resolved'){
                if($role==='teacher'){
                    $resolved=(int)($row->resolved_teacher_id??0);
                    if($resolved<1||(int)($row->resolved_student_id??0)>0)return false;
                }elseif($role==='student'){
                    $resolved=(int)($row->resolved_student_id??0);
                    if($resolved<1||(int)($row->resolved_teacher_id??0)>0)return false;
                }else{
                    return false;
                }
                // A resolved participant must be justified by exactly one verified durable mapping for
                // this exact provider account and role. Caller assertion can never justify resolution,
                // and an account bound to a different canonical participant is a recorded fact that
                // never qualifies for this occurrence.
                $account=(string)($row->provider_account_digest??'');
                $provider=(string)($row->provider_code??'');
                $bucket=$registry[$provider.'|'.$account.'|'.$role]??array();
                $verified=array();
                foreach($bucket as$mapping)if((string)($mapping->state??'')==='verified')$verified[(int)$mapping->participant_id]=true;
                if(count($verified)!==1||!isset($verified[$resolved]))return false;
            }elseif($role!==''&&(string)($row->source_kind??'')==='provider'){
                // Unresolved provider identity is permitted as retained evidence, but must carry no target.
                if((int)($row->resolved_teacher_id??0)>0||(int)($row->resolved_student_id??0)>0)return false;
            }
            $join=$row->join_at_utc??null;$leave=$row->leave_at_utc??null;
            if($join!==null&&!self::utc($join))return false;
            if($leave!==null&&!self::utc($leave))return false;
        }
        foreach($mappings as$mapping){
            if(!in_array((string)($mapping->state??''),self::MAPPING_STATES,true))return false;
            if(!in_array((string)($mapping->participant_role??''),self::PARTICIPANT_ROLES,true))return false;
            if((int)($mapping->participant_id??0)<1)return false;
            if((string)($mapping->provider_code??'')==='')return false;
            if(!self::digest($mapping->provider_account_digest??null))return false;
            if(!self::digest($mapping->provenance_digest??null))return false;
            if(!self::digest($mapping->evidence_reference_digest??null))return false;
            if((string)$mapping->state==='verified'){
                if(!self::utc($mapping->verified_at??null)||$mapping->revoked_at!==null)return false;
            }
            if((string)$mapping->state==='revoked'){
                if(!self::utc($mapping->revoked_at??null))return false;
            }
        }
        $sequence=1;$latest=0;
        foreach($decisions as$decision){
            if((int)($decision->case_id??0)!==$caseId)return false;
            if((int)($decision->decision_sequence??0)!==$sequence++)return false;
            if(!in_array((string)($decision->decision_kind??''),self::DECISION_KINDS,true))return false;
            if(!in_array((string)($decision->state_after??''),self::STATES,true))return false;
            if(!self::utc($decision->created_at??null)||(int)($decision->created_by??0)<1)return false;
            $rule=(string)($decision->rule_version??'');
            if($rule!==''&&$rule!==CanonicalAttendanceRule::RULE_VERSION)return false;
            $threshold=$decision->threshold_seconds===null?null:(int)$decision->threshold_seconds;
            if($threshold!==null&&$threshold!==CanonicalAttendanceRule::THRESHOLD_SECONDS)return false;
            $overlap=$decision->qualifying_overlap_seconds===null?null:(int)$decision->qualifying_overlap_seconds;
            if($overlap!==null&&$overlap<0)return false;
            $latest=(int)$decision->id;
        }
        $latestDecision=$case->latest_decision_id===null?null:(int)$case->latest_decision_id;
        if($latestDecision!==null&&$latestDecision!==$latest)return false;
        foreach($anomalies as$anomaly){
            if((int)($anomaly->case_id??0)!==$caseId)return false;
            if(!in_array((string)($anomaly->code??''),self::ANOMALY_CODES,true))return false;
        }
        return true;
    }

    /**
     * Index the durable identity registry by provider + account digest + role so one provider account
     * can never silently resolve to a different canonical participant at validation time.
     *
     * @param array<int,object> $mappings
     * @return array<string,array<int,object>>
     */
    public static function registry(array $mappings):array{
        $registry=array();
        foreach($mappings as$mapping){
            $key=(string)($mapping->provider_code??'').'|'.(string)($mapping->provider_account_digest??'').'|'.(string)($mapping->participant_role??'');
            $registry[$key][]=$mapping;
        }
        return $registry;
    }

    /**
     * Re-prove a zero-actor absence from durable portal evidence. The admission call occurs before
     * the portal can append its final submitted handoff receipt, so request-local audit is only an
     * admission-time fallback; later reads and consequences derive the binding from stored rows.
     */
    private static function validPublicCapabilityAudit(object $case,array $evidence,?array $audit,bool $lock=false):bool{
        global $wpdb;
        $p=$wpdb->prefix.'dzn_';
        $forUpdate=$lock?' FOR UPDATE':'';
        $claims=array();
        foreach($evidence as$claim){
            if((int)($claim->created_by??0)!==0)continue;
            if((string)($claim->attribution??'')!=='public_capability_on_behalf'||(string)($claim->reason_code??'')!=='public_capability_absence'||(string)($claim->source_kind??'')!=='student'||(string)($claim->evidence_kind??'')!=='advance_absence_claim')return false;
            $claims[]=$claim;
        }
        if(count($claims)>1)return false;

        // Durable post-handoff proof: evidence -> submitted action -> consumed capability ->
        // confirmation action. This path deliberately ignores the request-local audit argument.
        if(count($claims)===1){
            $claim=$claims[0];
            $row=$wpdb->get_row($wpdb->prepare("SELECT c.id FROM {$p}portal_public_action_events submitted INNER JOIN {$p}portal_public_capabilities c ON c.id=submitted.capability_id INNER JOIN {$p}portal_public_action_events confirmed ON confirmed.id=c.consumed_action_event_id AND confirmed.capability_id=c.id AND confirmed.action_state='confirmed_submitting' AND confirmed.confirmation_digest=submitted.confirmation_digest WHERE submitted.action_state='submitted' AND submitted.handoff_target='canonical_attendance_evidence' AND submitted.handoff_reference_id=%d AND submitted.lesson_id=%d AND c.lesson_id=%d AND c.schedule_version_id=%d AND c.subject_student_id=%d AND c.purpose='lesson_absence' AND c.state='consumed'{$forUpdate}",(int)$claim->id,(int)$case->lesson_id,(int)$case->lesson_id,(int)$case->schedule_version_id,(int)$case->student_id));
            if($row)return true;
        }

        // Admission-time only: PortalPublicActionService appends the submitted receipt after this
        // validation returns. The durable capability and confirmation remain authoritative here.
        if(!$audit||($audit['attribution']??'')!=='public_capability_on_behalf')return false;
        $row=$wpdb->get_row($wpdb->prepare("SELECT c.id FROM {$p}portal_public_capabilities c INNER JOIN {$p}portal_public_action_events a ON a.id=c.consumed_action_event_id AND a.capability_id=c.id WHERE c.id=%d AND c.lesson_id=%d AND c.schedule_version_id=%d AND c.subject_student_id=%d AND c.generation=%d AND c.purpose='lesson_absence' AND c.state='consumed' AND a.action_state='confirmed_submitting' AND a.confirmation_digest=%s{$forUpdate}",(int)($audit['capability_id']??0),(int)$case->lesson_id,(int)$case->schedule_version_id,(int)$case->student_id,(int)($audit['generation']??0),(string)($audit['redemption_reference_digest']??'')));
        return (bool)$row&&(count($claims)===0||hash_equals((string)$claims[0]->evidence_reference_digest,(string)($audit['evidence_reference_digest']??'')));
    }

    public static function digest(mixed $value):bool{return (bool)preg_match('/^[a-f0-9]{64}$/D',(string)($value??''));}

    public static function utc(mixed $value):bool{
        $value=(string)($value??'');
        if(!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D',$value))return false;
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new \DateTimeZone('UTC'));
        return (bool)($parsed&&$parsed->format('Y-m-d H:i:s')===$value);
    }
}
