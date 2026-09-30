<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalAttendanceRepository,CanonicalLessonAuthorityRepository,CanonicalLessonScheduleRepository};
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Canonical attendance intake and review authority (Phase 2A.2-P).
 *
 * Phase P evaluates evidence and owns intake/review. It does NOT own canonical delivery truth:
 * ordinary automatic success is delegated to Phase O (`delivered`), and Lesson completion is
 * delegated to Lesson authority, both through {@see CanonicalAttendanceSettlementService}. Nothing
 * here writes Phase-O, lifecycle, obligation or replacement storage directly, and nothing here can
 * create a Phase-M replacement, academy obligation, refund, remedial Lesson or reschedule.
 *
 * Owner locks implemented:
 *  - ordinary delivery settles automatically only from trusted provider evidence whose provider
 *    account resolves through the durable participant identity registry to the exact canonical
 *    Teacher and Student of the occurrence, with >=1200s of qualifying simultaneous overlap inside
 *    [start, end + 15 minutes);
 *  - caller assertion of identity or verification is never accepted: a missing, unverified, revoked,
 *    ambiguous or mismatched mapping can never contribute qualifying attendance;
 *  - failure to prove success never identifies responsibility: no outcome, obligation or
 *    replacement is created, only an anomaly/review;
 *  - Student "Notify Absence" is only an advance claim about expected non-attendance;
 *  - human claims are evidence, never settlement, and never outrank provider evidence;
 *  - conflicting evidence requires administrative adjudication (no last-write-wins), and every
 *    refused conflicting provider event leaves a durable conflict receipt;
 *  - unresolved Phase-P review never blocks Term closure and is never auto-published as Phase-O
 *    `review_required`;
 *  - Phase P is prospective only: a cutover instant must be prospective at activation, and every
 *    admitted case is bound to the exact immutable policy row that admitted it.
 */
final class CanonicalAttendanceIntakeService {
    public const PROVIDER_CODES=array('google_meet','zoom','teams','other_trusted_provider');
    private const PROVIDER_CAPABILITY='dzn_ingest_canonical_attendance_evidence';
    private const STUDENT_CLAIM_CAPABILITY='dzn_submit_own_attendance_claim';
    private const TEACHER_CLAIM_CAPABILITY='dzn_submit_own_delivery_claim';
    private const REVIEW_CAPABILITY='dzn_manage_canonical_attendance_review';
    private const POLICY_VERSION='canonical_attendance_cutover_v1';
    // Zero is the schema-permitted non-user audit actor. Capability proof and attribution remain
    // the authoritative caller evidence; this value is never treated as a WordPress principal.
    private const PUBLIC_CAPABILITY_AUDIT_ACTOR=0;
    private const CLAIM_KINDS=array('advance_absence_claim','attendance_claim','delivery_claim','review_request');
    private const ADJUDICATIONS=array('settle_delivered','record_no_change','review_required','teacher_non_delivery');
    public function __construct(
        private ?CanonicalAttendanceRepository $repository=null,
        private ?CanonicalLessonAuthorityRepository $lessons=null,
        private ?CanonicalLessonScheduleRepository $schedules=null,
        private ?CanonicalAttendanceSettlementService $settlement=null
    ){
        $this->repository??=new CanonicalAttendanceRepository();
        $this->lessons??=new CanonicalLessonAuthorityRepository();
        $this->schedules??=new CanonicalLessonScheduleRepository();
        $this->settlement??=new CanonicalAttendanceSettlementService();
    }

    /**
     * Record the durable prospective cutover boundary. No production cutover is performed here, and
     * a cutover instant may never be backdated: the activation instant must still be in the future.
     */
    public function recordCutoverPolicy(string $cutoverUtc,string $key):array{
        $this->requireCapability(self::REVIEW_CAPABILITY);
        if(!CanonicalAttendanceValidator::utc($cutoverUtc))throw new \InvalidArgumentException('Valid UTC cutover instant required');
        $actor=$this->actor();
        $digest=CanonicalAttendanceIdempotency::key($key);
        $facts=array('domain'=>'canonical_attendance_v1','operation'=>'record_cutover_policy','policy_version'=>self::POLICY_VERSION,'cutover_utc'=>$cutoverUtc,'rule_version'=>CanonicalAttendanceRule::RULE_VERSION);
        $payload=CanonicalAttendanceIdempotency::payload($facts);
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){ $this->repository->commit(); return $this->replayCommand($winner,$payload,'record_cutover_policy'); }
            // Prospective-only applies to a genuinely NEW command; an exact replay of an already
            // recorded durable command is resolved above and never re-validated against the wall clock.
            if((int)strtotime($cutoverUtc.' UTC')<=(int)strtotime(gmdate('Y-m-d H:i:s').' UTC'))throw new \InvalidArgumentException('cutover_instant_not_prospective');
            // One immutable policy per cutover instant: a different command identity attempting the
            // same instant is a genuine authority conflict, never a silent database-id tie-break.
            if($this->repository->policyForInstant($cutoverUtc,true))throw new \InvalidArgumentException('duplicate_cutover_instant');
            $now=gmdate('Y-m-d H:i:s');
            $id=$this->repository->insertCutoverPolicy(array(
                'uid'=>Identifier::uid(),'policy_version'=>self::POLICY_VERSION,'cutover_utc'=>$cutoverUtc,
                'rule_version'=>CanonicalAttendanceRule::RULE_VERSION,
                'threshold_seconds'=>CanonicalAttendanceRule::THRESHOLD_SECONDS,
                'pre_grace_seconds'=>CanonicalAttendanceRule::PRE_GRACE_SECONDS,
                'post_grace_seconds'=>CanonicalAttendanceRule::POST_GRACE_SECONDS,
                'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->insertCommand($digest,$payload,'record_cutover_policy',$facts,null,null,'recorded',null,$now,$actor);
            $this->repository->commit();
            return array('policy_id'=>$id,'cutover_utc'=>$cutoverUtc,'created'=>true,'operation'=>'record_cutover_policy');
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replayCommand($winner,$payload,'record_cutover_policy');
            if($this->repository->duplicate($e)==='cutover_instant')throw new \InvalidArgumentException('duplicate_cutover_instant');
            throw $e;
        }
    }

    /** Ingest trusted provider evidence for one exact Lesson occurrence, then assess. */
    public function ingestProviderEvidence(int $lessonId,int $scheduleVersionId,array $input,string $key):array{
        $this->requireCapability(self::PROVIDER_CAPABILITY);
        $intent=$this->providerIntent($input);
        $digest=CanonicalAttendanceIdempotency::key($key);
        $this->repository->begin();
        try{
            [$case,$lesson,$version,$created]=$this->lockOccurrence($lessonId,$scheduleVersionId);
            $intent=$this->resolveIdentity($intent);
            $payload=CanonicalAttendanceIdempotency::payload($this->evidenceFacts($case,$intent));
            $replay=$this->ingestReplay($digest,$payload,$case);
            if($replay!==null){
                $this->repository->commit();
                if(!empty($replay['converge']))$replay=$this->convergeSettlement((int)$case->id,$replay);
                return $replay;
            }
            $evidenceId=null;
            $existing=$this->repository->evidenceByEventKey((string)$intent['provider_event_key_digest'],true);
            if($existing){
                if(!$this->sameEvidenceContext($existing,$case,$intent)){
                    $this->recordConflict($case,$existing,$intent,$this->conflictKind($existing,$case));
                    $this->repository->commit();
                    throw new IdempotencyConflictException('Idempotency conflict');
                }
                // Exact duplicate provider event: retain the receipt, do not double-count, still assess.
                $evidenceId=(int)$existing->id;
            }else{
                $now=gmdate('Y-m-d H:i:s');
                $evidenceId=$this->insertEvidence($case,$lesson,$intent,$now,$this->actor());
            }
            // P-1: the complete aggregate, including the row just inserted, must validate before any
            // assessment, judgement or canonical settlement is attempted.
            if(!CanonicalAttendanceValidator::validForCase((int)$case->id,$this->repository,$this->lessons,$this->schedules,true))throw new \InvalidArgumentException('canonical_attendance_integrity_conflict');
            $now=gmdate('Y-m-d H:i:s');
            $this->insertCommand($digest,CanonicalAttendanceIdempotency::payload($this->evidenceFacts($case,$intent)),'ingest_provider_evidence',$this->evidenceFacts($case,$intent),$case,$evidenceId,'recorded',null,$now,$this->actor());
            $assessment=$this->assessAndDecide($case,$lesson);
            $this->repository->commit();
            $settlement=$this->maybeSettle($case,$assessment);
            return array('case_id'=>(int)$case->id,'evidence_id'=>$evidenceId,'assessment'=>$assessment,'settlement'=>$settlement,'created'=>$created);
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replayIngestCommand($winner,$lessonId,$scheduleVersionId,$intent);
            throw $e;
        }
    }

    /**
     * Submit a human claim (advance absence, attendance, delivery or review request).
     * Claims are evidence only: they never settle, complete, cancel or create entitlement.
     */
    public function submitClaim(int $lessonId,int $scheduleVersionId,array $input,string $key):array{
        $kind=(string)($input['claim_kind']??'');
        if(!in_array($kind,self::CLAIM_KINDS,true))throw new \InvalidArgumentException('Controlled claim kind required');
        $this->requireCapability($kind==='delivery_claim'?self::TEACHER_CLAIM_CAPABILITY:self::STUDENT_CLAIM_CAPABILITY);
        $actor=$this->actor();
        $intent=$this->claimIntent($kind,$input);
        $digest=CanonicalAttendanceIdempotency::key($key);
        $this->repository->begin();
        try{
            [$case,$lesson,$version,$created]=$this->lockOccurrence($lessonId,$scheduleVersionId);
            $facts=$this->claimFacts($case,$intent);
            $payload=CanonicalAttendanceIdempotency::payload($facts);
            if($winner=$this->repository->command($digest)){ $replay=$this->replayCommand($winner,$payload,'submit_claim'); $this->repository->commit(); return $replay; }
            $late=$this->isAfterTermClosure((int)$case->term_id);
            $this->authoriseOwnership($kind,$case,$late);
            $now=gmdate('Y-m-d H:i:s');
            $intent['attribution']=$this->isAdministrator()?'administrator_on_behalf':'own_principal';
            $evidenceId=$this->insertEvidence($case,$lesson,$intent,$now,$actor);
            if(!CanonicalAttendanceValidator::validForCase((int)$case->id,$this->repository,$this->lessons,$this->schedules,true))throw new \InvalidArgumentException('canonical_attendance_integrity_conflict');
            if($late)$this->openAnomaly($case,'late_evidence',$now,$actor);
            $assessment=$this->assessAndDecide($case,$lesson);
            $this->insertCommand($digest,$payload,'submit_claim',$facts,$case,$evidenceId,'recorded',$assessment['decision_id'],$now,$actor);
            $this->repository->commit();
            return array('case_id'=>(int)$case->id,'evidence_id'=>$evidenceId,'assessment'=>$assessment,'settlement'=>null,'created'=>$created);
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replayClaimCommand($winner,$lessonId,$scheduleVersionId,$intent);
            throw $e;
        }
    }

    /** Phase-W handoff: capability proof is re-bound to the exact occurrence before intake. */
    public function submitCapabilityClaim(\Delnavazan\Platform\Portals\PublicCapabilityReadSubject $subject,string $redemptionReference):array{
        if($subject->purpose!=='lesson_absence'||$subject->studentId===null)throw new \InvalidArgumentException('portal_capability_binding_mismatch');
        if(trim($redemptionReference)==='')throw new \InvalidArgumentException('portal_confirmation_invalid');
        $digest=CanonicalAttendanceIdempotency::key('portal_absence:'.$redemptionReference);
        $redemptionDigest=hash('sha256',$redemptionReference);
        $payload=CanonicalAttendanceIdempotency::payload(array('operation'=>'submit_capability_claim','capability_id'=>$subject->capabilityId,'lesson_id'=>$subject->lessonId,'schedule_version_id'=>$subject->scheduleVersionId,'generation'=>$subject->generation,'redemption_reference_digest'=>$redemptionDigest));
        $this->repository->begin();
        try{
            global $wpdb;$p=$wpdb->prefix.'dzn_';
            $cap=$wpdb->get_row($wpdb->prepare("SELECT c.* FROM {$p}portal_public_capabilities c INNER JOIN {$p}portal_public_action_events a ON a.id=c.consumed_action_event_id AND a.action_state='confirmed_submitting' WHERE c.id=%d AND c.lesson_id=%d AND c.schedule_version_id=%d AND c.purpose=%s AND c.generation=%d AND c.subject_student_id=%d AND c.state='consumed' FOR UPDATE",$subject->capabilityId,$subject->lessonId,$subject->scheduleVersionId,\Delnavazan\Platform\Portals\PortalRule::ABSENCE,$subject->generation,$subject->studentId));
            if(!$cap)throw new \InvalidArgumentException('portal_capability_binding_mismatch');
            // Resolve an exact durable result before mutable finality, window, and term gates.
            if($winner=$this->repository->command($digest)){$replay=$this->replayCommand($winner,$payload,'submit_capability_claim');$this->repository->commit();return array_merge($replay,array('lesson_id'=>$subject->lessonId,'schedule_version_id'=>$subject->scheduleVersionId,'student_id'=>$subject->studentId,'attribution'=>'public_capability_on_behalf','redemption_reference_digest'=>$redemptionDigest));}
            $studentLink=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}student_principal_links WHERE student_id=%d AND status='active' AND revoked_at IS NULL LIMIT 1",$subject->studentId));
            if(!$studentLink)throw new \InvalidArgumentException('portal_capability_binding_mismatch');
            $capabilityAudit=array('capability_id'=>(int)$cap->id,'generation'=>(int)$cap->generation,'redemption_reference_digest'=>$redemptionDigest,'evidence_reference_digest'=>CanonicalAttendanceIdempotency::evidence($redemptionReference),'attribution'=>'public_capability_on_behalf');
            [$case,$lesson,$version,$created]=$this->lockOccurrence($subject->lessonId,$subject->scheduleVersionId,self::PUBLIC_CAPABILITY_AUDIT_ACTOR,$capabilityAudit);
            if((int)$case->student_id!==$subject->studentId)throw new \InvalidArgumentException('portal_capability_binding_mismatch');
            if((string)$case->state==='settled')throw new \InvalidArgumentException('portal_absence_outcome_final');
            if(gmdate('Y-m-d H:i:s')>=(string)$case->window_end_utc)throw new \InvalidArgumentException('portal_absence_window_closed');
            if($this->isAfterTermClosure((int)$case->term_id))throw new \InvalidArgumentException('portal_absence_late_evidence');
            $intent=array('source_kind'=>'student','evidence_kind'=>'advance_absence_claim','provider_code'=>null,'provider_account_digest'=>null,'provider_session_digest'=>null,'provider_event_key_digest'=>null,'provider_payload_digest'=>null,'participant_role'=>null,'participant_identity_state'=>null,'resolved_student_id'=>null,'resolved_teacher_id'=>null,'verification_state'=>'unverified','join_at_utc'=>null,'leave_at_utc'=>null,'observed_at'=>gmdate('Y-m-d H:i:s'),'provenance_digest'=>null,'reason_code'=>'public_capability_absence','evidence_reference_digest'=>CanonicalAttendanceIdempotency::evidence($redemptionReference),'attribution'=>'public_capability_on_behalf');
            $evidenceId=$this->insertEvidence($case,$lesson,$intent,gmdate('Y-m-d H:i:s'),self::PUBLIC_CAPABILITY_AUDIT_ACTOR);
            if(!CanonicalAttendanceValidator::validForCase((int)$case->id,$this->repository,$this->lessons,$this->schedules,true,$capabilityAudit))throw new \InvalidArgumentException('canonical_attendance_integrity_conflict');
            $this->insertCommand($digest,$payload,'submit_capability_claim',array('lesson_id'=>$subject->lessonId,'schedule_version_id'=>$subject->scheduleVersionId),$case,$evidenceId,'submitted',null,gmdate('Y-m-d H:i:s'),self::PUBLIC_CAPABILITY_AUDIT_ACTOR);
            $this->repository->commit();
            return array('case_id'=>(int)$case->id,'evidence_id'=>$evidenceId,'lesson_id'=>$subject->lessonId,'schedule_version_id'=>$subject->scheduleVersionId,'student_id'=>$subject->studentId,'attribution'=>'public_capability_on_behalf','redemption_reference_digest'=>hash('sha256',$redemptionReference),'state'=>'submitted');
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest))){
                $payload=CanonicalAttendanceIdempotency::payload(array('operation'=>'submit_capability_claim','capability_id'=>$subject->capabilityId,'lesson_id'=>$subject->lessonId,'schedule_version_id'=>$subject->scheduleVersionId,'generation'=>$subject->generation,'redemption_reference_digest'=>hash('sha256',$redemptionReference)));
                $replay=$this->replayCommand($winner,$payload,'submit_capability_claim');
                return array_merge($replay,array('lesson_id'=>$subject->lessonId,'schedule_version_id'=>$subject->scheduleVersionId,'student_id'=>$subject->studentId,'attribution'=>'public_capability_on_behalf','redemption_reference_digest'=>hash('sha256',$redemptionReference)));
            }
            throw $e;
        }
    }

    /**
     * Non-mutating public-capability preflight. Phase W owns the durable redemption claim; this
     * read port must never create or lock a canonical attendance case before that claim commits.
     * The handoff repeats the mutable checks inside its own transaction because this proof can race
     * with finality.
     */
    public function assertCapabilityClaimAdmissible(\Delnavazan\Platform\Portals\PublicCapabilityReadSubject $subject):void{
        if($subject->purpose!=='lesson_absence'||$subject->studentId===null)throw new \InvalidArgumentException('portal_capability_binding_mismatch');
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        if((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}student_principal_links WHERE student_id=%d AND status='active' AND revoked_at IS NULL AND active_slot=1",$subject->studentId))!==1)throw new \InvalidArgumentException('portal_principal_required');
        // An existing case is read only; absence is valid and must not be materialised here.
        $case=$this->repository->caseFor($subject->lessonId,$subject->scheduleVersionId,false);
        if(!$case){
            // No case exists yet, so derive the owner occurrence directly. This is deliberately
            // read-only: Phase W must prove the mutable gates before it consumes the capability.
            $lesson=$this->lessons->lesson($subject->lessonId);
            $version=$this->schedules->version($subject->scheduleVersionId);
            $versions=$this->schedules->versionsForLesson($subject->lessonId);
            $latest=$versions?$versions[count($versions)-1]:null;
            if(!$lesson||!$version||!$latest||(int)$version->lesson_id!==$subject->lessonId||(int)$latest->id!==$subject->scheduleVersionId)throw new \InvalidArgumentException('schedule_version_conflict');
            if((int)$lesson->student_id!==$subject->studentId)throw new \InvalidArgumentException('portal_capability_binding_mismatch');
            $window=CanonicalAttendanceRule::window((string)$version->starts_at_utc,(string)$version->ends_at_utc);
            if(gmdate('Y-m-d H:i:s')>=(string)$window['window_end_utc'])throw new \InvalidArgumentException('portal_absence_window_closed');
            if($this->isAfterTermClosure((int)$lesson->term_id))throw new \InvalidArgumentException('portal_absence_late_evidence');
            return;
        }
        if((int)$case->student_id!==$subject->studentId)throw new \InvalidArgumentException('portal_capability_binding_mismatch');
        if((string)$case->state==='settled')throw new \InvalidArgumentException('portal_absence_outcome_final');
        if(gmdate('Y-m-d H:i:s')>=(string)$case->window_end_utc)throw new \InvalidArgumentException('portal_absence_window_closed');
        if($this->isAfterTermClosure((int)$case->term_id))throw new \InvalidArgumentException('portal_absence_late_evidence');
    }

    /** Administrative adjudication: the only Phase-P path allowed to change effective canonical truth. */
    public function adjudicate(int $caseId,array $input,string $key):array{
        $this->requireCapability(self::REVIEW_CAPABILITY);
        $outcome=(string)($input['adjudication']??'');
        if(!in_array($outcome,self::ADJUDICATIONS,true))throw new \InvalidArgumentException('Controlled adjudication required');
        // P-6: the administrator must adjudicate the exact case version they reviewed.
        $expected=(int)($input['expected_case_version']??0);
        if($expected<1)throw new \InvalidArgumentException('expected_case_version_required');
        $actor=$this->actor();
        $digest=CanonicalAttendanceIdempotency::key($key);
        $this->repository->begin();
        try{
            $case=$this->hydrateDurableCase($caseId,'adjudicate');
            $facts=array('case_id'=>$caseId,'adjudication'=>$outcome,'expected_case_version'=>$expected,'lesson_id'=>(int)$case->lesson_id);
            $payload=CanonicalAttendanceIdempotency::payload($facts);
            if($winner=$this->repository->command($digest)){ $replay=$this->replayCommand($winner,$payload,'adjudicate'); $this->repository->commit(); return $replay; }
            if((int)$case->case_version!==$expected)throw new \RuntimeException('stale_case_version');
            $now=gmdate('Y-m-d H:i:s');
            if($outcome==='record_no_change'){
                $decisionId=$this->recordDecision($case,'admin_adjudication','closed_no_change',null,'administrative_no_change',array(),$now,$actor);
                $this->insertCommand($digest,$payload,'adjudicate',$facts,$case,null,'closed_no_change',$decisionId,$now,$actor);
                $this->repository->commit();
                return array('case_id'=>$caseId,'adjudication'=>$outcome,'state'=>'closed_no_change','decision_id'=>$decisionId,'settlement'=>null);
            }
            // Outbound authority actions (Phase-O delivered truth, Phase-O review/non-delivery) are
            // performed OUTSIDE this transaction: durable intent first, then delegation, then the
            // final adjudication decision, so a retry converges without duplicating authority.
            $intentDecision=$this->recordDecision($case,'admin_adjudication','settlement_pending',null,'administrative_'.$outcome,array(),$now,$actor);
            $this->repository->commit();
            $settlement=null;
            if($outcome==='settle_delivered')$settlement=$this->settlement->settleDelivered($caseId,true);
            else $this->publishPhaseOutcome($caseId,$outcome==='review_required'?'review_required':'teacher_non_delivery');
            $this->repository->begin();
            $case=$this->repository->caseById($caseId,true);
            $state=$outcome==='settle_delivered'?'settled':'adjudicated';
            $decisionId=$this->recordDecision($case,'admin_adjudication',$state,null,'administrative_'.$outcome,array(),gmdate('Y-m-d H:i:s'),$actor,$settlement===null?null:(int)$settlement['outcome_id']);
            $this->insertCommand($digest,$payload,'adjudicate',$facts,$case,null,$state,$decisionId,gmdate('Y-m-d H:i:s'),$actor);
            $this->repository->commit();
            return array('case_id'=>(int)$case->id,'adjudication'=>$outcome,'state'=>$state,'decision_id'=>$decisionId,'settlement'=>$settlement,'intent_decision_id'=>$intentDecision);
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replayAdjudicationCommand($winner,$caseId,$outcome,$expected);
            throw $e;
        }
    }

    /** Re-run assessment for a case (administrator) so a pending settlement can converge. */
    public function reassess(int $caseId,array $input,string $key):array{
        $this->requireCapability(self::REVIEW_CAPABILITY);
        $expected=(int)($input['expected_case_version']??0);
        if($expected<1)throw new \InvalidArgumentException('expected_case_version_required');
        $digest=CanonicalAttendanceIdempotency::key($key);
        $this->repository->begin();
        try{
            $case=$this->hydrateDurableCase($caseId,'reassess');
            $lesson=$this->lessons->lesson((int)$case->lesson_id,true);
            $facts=array('case_id'=>$caseId,'operation'=>'reassess','expected_case_version'=>$expected);
            $payload=CanonicalAttendanceIdempotency::payload($facts);
            if($winner=$this->repository->command($digest)){ $replay=$this->replayCommand($winner,$payload,'reassess'); $this->repository->commit(); return $replay; }
            if((int)$case->case_version!==$expected)throw new \RuntimeException('stale_case_version');
            $assessment=$this->assessAndDecide($case,$lesson);
            $this->insertCommand($digest,$payload,'reassess',$facts,$case,null,$assessment['state'],$assessment['decision_id'],gmdate('Y-m-d H:i:s'),$this->actor());
            $this->repository->commit();
            $settlement=$this->maybeSettle($case,$assessment);
            return array('case_id'=>$caseId,'assessment'=>$assessment,'settlement'=>$settlement);
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replayReassessCommand($winner,$caseId,$expected);
            throw $e;
        }
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /** @return array{0:object,1:object,2:object,3:bool} */
    private function lockOccurrence(int $lessonId,int $scheduleVersionId,?int $auditActor=null,?array $capabilityAudit=null):array{
        $hint=$this->lessons->lesson($lessonId);
        if(!$hint||(string)($hint->record_model??'')!=='canonical_term_lesson_v1')throw new \InvalidArgumentException('canonical_lesson_required');
        $enrolmentHint=$this->lessons->enrolment((int)$hint->enrolment_id);
        if(!$enrolmentHint)throw new \InvalidArgumentException('canonical_enrolment_required');
        $this->lessons->lockRoot($enrolmentHint);
        $lesson=$this->lessons->lesson($lessonId,true);
        $versions=$this->schedules->versionsForLesson($lessonId,true);
        $version=null;
        foreach($versions as$candidate)if((int)$candidate->id===$scheduleVersionId)$version=$candidate;
        if(!$version)throw new \InvalidArgumentException('schedule_version_conflict');
        $latest=$versions?$versions[count($versions)-1]:null;
        if(!$latest||(int)$latest->id!==$scheduleVersionId)throw new \InvalidArgumentException('schedule_version_conflict');
        // Policy rows are immutable authority: read without a lock so occurrences that share one
        // policy never serialise against each other.
        $policy=$this->repository->applicablePolicy((string)$version->starts_at_utc,false);
        if(!$policy){
            if($this->repository->latestPolicy())throw new \InvalidArgumentException('occurrence_before_cutover');
            throw new \InvalidArgumentException('cutover_policy_required');
        }
        $case=$this->repository->caseFor($lessonId,$scheduleVersionId,true);
        $created=false;
        if(!$case){
            $window=CanonicalAttendanceRule::window((string)$version->starts_at_utc,(string)$version->ends_at_utc);
            $now=gmdate('Y-m-d H:i:s');
            $id=$this->repository->insertCase(array(
                'uid'=>Identifier::uid(),'lesson_id'=>$lessonId,'schedule_version_id'=>$scheduleVersionId,
                'enrolment_id'=>(int)$lesson->enrolment_id,'term_id'=>(int)$lesson->term_id,
                'student_id'=>(int)$lesson->student_id,'teacher_id'=>(int)$lesson->teacher_id,
                'teacher_assignment_id'=>(int)$lesson->teacher_assignment_id,
                'occurrence_start_utc'=>(string)$version->starts_at_utc,'occurrence_end_utc'=>(string)$version->ends_at_utc,
                'window_end_utc'=>(string)$window['window_end_utc'],'rule_version'=>CanonicalAttendanceRule::RULE_VERSION,
                'cutover_policy_id'=>(int)$policy->id,
                'state'=>'open','case_version'=>1,'latest_decision_id'=>null,
                'created_at'=>$now,'created_by'=>$this->actor($auditActor),'updated_at'=>$now,'updated_by'=>$this->actor($auditActor),
            ));
            $case=$this->repository->caseById($id,true);
            $created=true;
            do_action('dzn_phase_2a2p_after_case_insert',$lessonId,$scheduleVersionId);
        }
        do_action('dzn_phase_2a2p_occurrence_locks_held',$lessonId,$scheduleVersionId);
        if(!CanonicalAttendanceValidator::validForCase((int)$case->id,$this->repository,$this->lessons,$this->schedules,true,$capabilityAudit))throw new \InvalidArgumentException('canonical_attendance_integrity_conflict');
        return array($case,$lesson,$version,$created);
    }

    /** A durable case is re-validated in full before any canonical consequence may be attempted. */
    private function hydrateDurableCase(int $caseId,string $operation):object{
        $case=$this->repository->caseById($caseId,true);
        if(!$case)throw new \InvalidArgumentException('canonical_attendance_case_required');
        if(!CanonicalAttendanceValidator::validForCase($caseId,$this->repository,$this->lessons,$this->schedules,true))throw new \InvalidArgumentException('canonical_attendance_integrity_conflict');
        $versions=$this->schedules->versionsForLesson((int)$case->lesson_id,true);
        $latest=$versions?$versions[count($versions)-1]:null;
        if(!$latest||(int)$latest->id!==(int)$case->schedule_version_id)throw new \InvalidArgumentException('schedule_version_conflict');
        do_action('dzn_phase_2a2p_case_hydrated',$caseId,$operation);
        return $case;
    }

    /** Assess the evidence set and durably record the assessment (and anomaly) decisions. */
    private function assessAndDecide(object $case,?object $lesson):array{
        $lesson??=$this->lessons->lesson((int)$case->lesson_id,true);
        $evidence=$this->repository->evidenceForCase((int)$case->id,true);
        $teacherIntervals=array();$studentIntervals=array();$anomalies=array();
        $providerSeen=0;$unverified=0;$unmapped=0;$ambiguous=0;$mismatched=0;
        foreach($evidence as$row){
            if((string)$row->evidence_kind!=='provider_interval')continue;
            $providerSeen++;
            $identity=(string)($row->participant_identity_state??'');
            if((string)($row->verification_state??'')!=='verified'){
                $unverified++;
                if($identity==='ambiguous')$ambiguous++;else $unmapped++;
                continue;
            }
            if($identity!=='resolved'){$unmapped++;continue;}
            $role=(string)$row->participant_role;
            $expected=$role==='teacher'?(int)$case->teacher_id:(int)$case->student_id;
            $resolved=$role==='teacher'?(int)($row->resolved_teacher_id??0):(int)($row->resolved_student_id??0);
            if($role!=='teacher'&&$role!=='student'){$unmapped++;continue;}
            if($resolved!==$expected){$mismatched++;continue;}
            $interval=array('join_at_utc'=>(string)($row->join_at_utc??''),'leave_at_utc'=>$row->leave_at_utc===null?null:(string)$row->leave_at_utc);
            if($role==='teacher')$teacherIntervals[]=$interval;
            else $studentIntervals[]=$interval;
        }
        if($providerSeen===0)$anomalies[]='provider_evidence_missing';
        if($unverified>0)$anomalies[]='provider_evidence_incomplete';
        if($unmapped>0)$anomalies[]='provider_identity_unmapped';
        if($ambiguous>0)$anomalies[]='participant_ambiguous';
        if($mismatched>0)$anomalies[]='participant_identity_mismatch';
        $assessment=CanonicalAttendanceRule::assess($teacherIntervals,$studentIntervals,(string)$case->occurrence_start_utc,(string)$case->occurrence_end_utc);
        foreach($assessment['excluded'] as$excluded){
            $reason=(string)($excluded['reason']??'');
            if(in_array($reason,array(CanonicalAttendanceRule::EXCLUDED_IMPOSSIBLE_INTERVAL,CanonicalAttendanceRule::EXCLUDED_OPEN_INTERVAL),true))$anomalies[]=$reason;
        }
        if(!$teacherIntervals)$anomalies[]='teacher_participation_unproven';
        if(!$studentIntervals)$anomalies[]='student_participation_unproven';
        if($providerSeen>0&&$assessment['seconds']<CanonicalAttendanceRule::THRESHOLD_SECONDS&&$teacherIntervals&&$studentIntervals)$anomalies[]='overlap_below_threshold';
        $now=gmdate('Y-m-d H:i:s');
        $lessonState=(string)($lesson->lifecycle_state??'');
        $termClosed=$this->isAfterTermClosure((int)$case->term_id);
        $effective=(new CanonicalLessonDeliveryGuard())->effective((int)$case->lesson_id);
        if($lessonState==='cancelled')$anomalies[]='lesson_cancelled';
        if($effective&&(string)$effective->outcome_code!=='delivered')$anomalies[]='canonical_outcome_exists';
        if($termClosed)$anomalies[]='late_evidence';
        $eligible=$assessment['eligible']&&!$anomalies&&$lessonState!=='cancelled'&&!$termClosed
            &&(!$effective||(string)$effective->outcome_code==='delivered');
        $anomalies=array_values(array_unique($anomalies));
        $stateAfter=$eligible?'settlement_pending':'ready_for_review';
        $decisionId=$this->recordDecision($case,'assessment',$stateAfter,$assessment['seconds'],$eligible?'automatically_eligible':'review',array(),$now,$this->actor());
        if(!$eligible){
            foreach($anomalies as$code)$this->openAnomaly($case,$code,$now,$this->actor(),$decisionId);
            $decisionId=$this->recordDecision($case,'anomaly_opened','ready_for_review',$assessment['seconds'],'review',$anomalies,$now,$this->actor());
        }
        return array(
            'eligible'=>(bool)$eligible,'state'=>$stateAfter,'decision_id'=>$decisionId,
            'qualifying_overlap_seconds'=>(int)$assessment['seconds'],
            'threshold_seconds'=>CanonicalAttendanceRule::THRESHOLD_SECONDS,
            'rule_version'=>CanonicalAttendanceRule::RULE_VERSION,
            'segments'=>$assessment['segments'],'excluded'=>$assessment['excluded'],
            'anomalies'=>$anomalies,'term_closed'=>$termClosed,
        );
    }

    /** Run settlement outside the Phase-P transaction and durably record the converged result. */
    private function maybeSettle(object $case,array $assessment):?array{
        if(empty($assessment['eligible']))return null;
        $settlement=$this->settlement->settleDelivered((int)$case->id,false);
        $decisionId=$this->recordSettlementResult((int)$case->id,(int)$settlement['outcome_id']);
        return array_merge($settlement,array('decision_id'=>$decisionId));
    }

    /**
     * P-5: an exact replay of the original ingest command resumes forward settlement when the durable
     * case state says settlement is still pending, so an interrupted ordinary settlement converges
     * without manual reassessment and without duplicating canonical authority.
     */
    private function convergeSettlement(int $caseId,array $result):array{
        $fresh=$this->repository->caseById($caseId);
        if(!$fresh)throw new \InvalidArgumentException('canonical_attendance_case_required');
        if((string)$fresh->state!=='settlement_pending')return array_merge($result,array('state'=>(string)$fresh->state));
        $settlement=$this->settlement->settleDelivered($caseId,false);
        $decisionId=$this->recordSettlementResult($caseId,(int)$settlement['outcome_id']);
        return array_merge($result,array('settlement'=>$settlement,'decision_id'=>$decisionId,'state'=>'settled','converged'=>true));
    }

    /** Record the final settlement decision exactly once per case, and return the recorded decision. */
    private function recordSettlementResult(int $caseId,int $outcomeId):int{
        $case=$this->repository->caseById($caseId);
        if(!$case)throw new \InvalidArgumentException('canonical_attendance_case_required');
        $overlap=(int)$this->repository->maxAssessmentOverlap($caseId);
        $digest=CanonicalAttendanceIdempotency::key('attendance-settlement-record-'.$case->uid);
        $this->repository->begin();
        try{
            $existing=$this->repository->command($digest);
            $fresh=$this->repository->caseById($caseId,true);
            // A settlement recorded earlier is never repeated, but new evidence may have legitimately
            // returned the case to a pending state; converge it explicitly and append-only.
            if($existing&&(string)$fresh->state==='settled'){$this->repository->commit();return (int)$existing->result_decision_id;}
            $now=gmdate('Y-m-d H:i:s');
            $decisionId=$this->recordDecision($fresh,'settlement_completed','settled',$overlap,'accepted',array(),$now,$this->actor(),$outcomeId);
            if($existing){$this->repository->commit();return $decisionId;}
            do_action('dzn_phase_2a2p_after_settlement_result',$caseId,$decisionId);
            $this->insertCommand($digest,CanonicalAttendanceIdempotency::payload(array('case_id'=>$caseId,'outcome_id'=>$outcomeId)),'settlement_completed',array('case_id'=>$caseId),$fresh,null,'settled',$decisionId,$now,$this->actor());
            $this->repository->commit();
            return $decisionId;
        }catch(\Throwable$e){
            $this->repository->rollback();
            throw $e;
        }
    }

    /** Delegate a non-delivery/review outcome to Phase O; Phase O owns any consequence. */
    private function publishPhaseOutcome(int $caseId,string $outcomeCode):void{
        $case=$this->repository->caseById($caseId);
        if(!$case)throw new \InvalidArgumentException('canonical_attendance_case_required');
        $lesson=$this->lessons->lesson((int)$case->lesson_id);
        $state=(string)($lesson->lifecycle_state??'');
        if(!in_array($state,array('authorised','completed'),true))throw new \InvalidArgumentException('lesson_not_delivery_recordable');
        (new CanonicalLessonDeliveryService())->record((int)$case->lesson_id,$state,array(
            'outcome_code'=>$outcomeCode,'reason_code'=>'attendance_administrative_adjudication',
            'evidence_channel'=>'authenticated_platform','evidence_reference'=>'attendance-case-'.$case->uid,
            'evidence_at'=>gmdate('Y-m-d H:i:s'),
        ),'attendance-adjudication-outcome-'.$case->uid.'-'.$outcomeCode);
    }

    private function recordDecision(object $case,string $kind,string $stateAfter,?int $overlap,?string $basis,array $anomalies,string $now,int $actor,?int $outcomeId=null):int{
        $sequence=$this->repository->maxDecisionSequence((int)$case->id)+1;
        $id=$this->repository->insertDecision(array(
            'uid'=>Identifier::uid(),'case_id'=>(int)$case->id,'decision_sequence'=>$sequence,
            'decision_kind'=>$kind,'state_after'=>$stateAfter,'rule_version'=>CanonicalAttendanceRule::RULE_VERSION,
            'threshold_seconds'=>CanonicalAttendanceRule::THRESHOLD_SECONDS,'qualifying_overlap_seconds'=>$overlap,
            'decision_basis'=>$basis,'result_outcome_id'=>$outcomeId,
            'created_at'=>$now,'created_by'=>$actor,
        ));
        do_action('dzn_phase_2a2p_after_decision_insert',(int)$case->id,$id);
        foreach($anomalies as$code)$this->openAnomaly($case,$code,$now,$actor,$id);
        $this->repository->updateCaseState((int)$case->id,(int)$case->case_version,$stateAfter,$id,$now,$actor);
        $case->case_version=(int)$case->case_version+1;
        $case->state=$stateAfter;
        return $id;
    }

    private function openAnomaly(object $case,string $code,string $now,int $actor,?int $decisionId=null):int{
        return $this->repository->insertAnomaly(array(
            'uid'=>Identifier::uid(),'case_id'=>(int)$case->id,'code'=>$code,'decision_id'=>$decisionId,
            'raised_at'=>$now,'raised_by'=>$actor,
        ));
    }

    private function insertEvidence(object $case,object $lesson,array $intent,string $now,int $actor):int{
        $id=$this->repository->insertEvidence($this->evidenceBase($case,$lesson,$intent,$now,$actor));
        do_action('dzn_phase_2a2p_after_evidence_insert',(int)$case->id,$id);
        return $id;
    }

    private function evidenceBase(object $case,object $lesson,array $intent,string $now,int $actor):array{
        return array(
            'uid'=>Identifier::uid(),'case_id'=>(int)$case->id,'lesson_id'=>(int)$case->lesson_id,
            'schedule_version_id'=>(int)$case->schedule_version_id,
            'source_kind'=>$intent['source_kind'],'evidence_kind'=>$intent['evidence_kind'],
            'provider_code'=>$intent['provider_code'],'provider_account_digest'=>$intent['provider_account_digest'],
            'provider_session_digest'=>$intent['provider_session_digest'],'provider_event_key_digest'=>$intent['provider_event_key_digest'],
            'provider_payload_digest'=>$intent['provider_payload_digest'],
            'participant_role'=>$intent['participant_role'],'participant_identity_state'=>$intent['participant_identity_state'],
            'resolved_student_id'=>$intent['resolved_student_id'],'resolved_teacher_id'=>$intent['resolved_teacher_id'],
            'verification_state'=>$intent['verification_state'],
            'join_at_utc'=>$intent['join_at_utc'],'leave_at_utc'=>$intent['leave_at_utc'],
            'observed_at'=>$intent['observed_at'],'received_at'=>$now,
            'provenance_digest'=>$intent['provenance_digest'],'reason_code'=>$intent['reason_code'],
            'evidence_reference_digest'=>$intent['evidence_reference_digest'],
            'attribution'=>$intent['attribution']??null,
            'created_at'=>$now,'created_by'=>$actor,
        );
    }

    /**
     * Parse the immutable caller intent. No participant identity or verification state is accepted
     * from the caller: both are derived from the durable identity registry after the occurrence is
     * locked, so a caller can never assert its own resolution or verification.
     */
    private function providerIntent(array $input):array{
        $providerCode=(string)($input['provider_code']??'');
        if(!in_array($providerCode,self::PROVIDER_CODES,true))throw new \InvalidArgumentException('Controlled provider code required');
        $role=(string)($input['participant_role']??'');
        if(!in_array($role,CanonicalAttendanceValidator::PARTICIPANT_ROLES,true))throw new \InvalidArgumentException('Controlled participant role required');
        $account=trim((string)($input['provider_account_key']??''));
        if($account==='')throw new \InvalidArgumentException('Provider account identity required');
        $observed=(string)($input['observed_at']??'');
        if(!CanonicalAttendanceValidator::utc($observed)||$observed>gmdate('Y-m-d H:i:s'))throw new \InvalidArgumentException('Valid past-or-present UTC observed time required');
        $join=$input['join_at_utc']??null;$leave=$input['leave_at_utc']??null;
        if($join!==null&&!CanonicalAttendanceValidator::utc($join))throw new \InvalidArgumentException('Valid UTC join instant required');
        if($leave!==null&&!CanonicalAttendanceValidator::utc($leave))throw new \InvalidArgumentException('Valid UTC leave instant required');
        return array(
            'source_kind'=>'provider','evidence_kind'=>'provider_interval',
            'provider_code'=>$providerCode,
            'provider_account_digest'=>CanonicalAttendanceIdempotency::evidence($account),
            'provider_session_digest'=>$this->optionalDigest($input,'provider_session_key'),
            'provider_event_key_digest'=>CanonicalAttendanceIdempotency::providerEventKey((string)($input['provider_event_key']??'')),
            'provider_payload_digest'=>CanonicalAttendanceIdempotency::providerPayload((string)($input['provider_payload_key']??'')),
            'participant_role'=>$role,
            'participant_identity_state'=>'unknown','resolved_student_id'=>null,'resolved_teacher_id'=>null,
            'verification_state'=>'unverified','identity_reason'=>'provider_identity_unmapped',
            'join_at_utc'=>$join,'leave_at_utc'=>$leave,'observed_at'=>$observed,
            'provenance_digest'=>CanonicalAttendanceIdempotency::evidence((string)($input['provenance_reference']??$input['provider_event_key']??'')),
            'reason_code'=>'provider_attendance_evidence',
            'evidence_reference_digest'=>CanonicalAttendanceIdempotency::evidence((string)($input['evidence_reference']??$input['provider_event_key']??'')),
        );
    }

    /**
     * P-1: derive the participant identity from the durable registry only.
     *
     * The caller's claim is irrelevant; the account digest decides. A verified mapping to the exact
     * expected canonical participant is the only way evidence can contribute qualifying attendance.
     */
    private function resolveIdentity(array $intent):array{
        // Registry rows are read without row locks: two occurrences that share one provider account
        // must never contend on the registry row, only on their own occurrence aggregate.
        $rows=$this->repository->mappingsForAccount((string)$intent['provider_code'],(string)$intent['provider_account_digest'],(string)$intent['participant_role'],false);
        $resolved=CanonicalAttendanceIdentityService::resolve($rows);
        $intent['identity_reason']=$resolved['reason'];
        if($resolved['state']==='resolved'){
            $intent['participant_identity_state']='resolved';
            $intent['verification_state']='verified';
            $participantId=(int)$resolved['participant_id'];
            if((string)$intent['participant_role']==='teacher')$intent['resolved_teacher_id']=$participantId;
            else $intent['resolved_student_id']=$participantId;
            return $intent;
        }
        $intent['participant_identity_state']=$resolved['state']==='ambiguous'?'ambiguous':'unknown';
        $intent['verification_state']='unverified';
        $intent['resolved_student_id']=null;$intent['resolved_teacher_id']=null;
        return $intent;
    }

    private function claimIntent(string $kind,array $input):array{
        $observed=(string)($input['observed_at']??gmdate('Y-m-d H:i:s'));
        if(!CanonicalAttendanceValidator::utc($observed))throw new \InvalidArgumentException('Valid UTC observed time required');
        $reason=Normalizer::text($input['reason_code']??'attendance_claim',64,true);
        if(!preg_match('/^[a-z0-9_]+$/D',(string)$reason))throw new \InvalidArgumentException('Controlled reason code required');
        return array(
            'source_kind'=>$kind==='delivery_claim'?'teacher':'student','evidence_kind'=>$kind,
            'provider_code'=>null,'provider_account_digest'=>null,'provider_session_digest'=>null,
            'provider_event_key_digest'=>null,'provider_payload_digest'=>null,
            // Human claims are attributed to an actor, not to a provider-resolved participant, so they
            // carry no participant identity claim of their own.
            'participant_role'=>null,'participant_identity_state'=>null,
            'resolved_student_id'=>null,'resolved_teacher_id'=>null,'verification_state'=>'unverified',
            'join_at_utc'=>null,'leave_at_utc'=>null,'observed_at'=>$observed,
            'provenance_digest'=>null,'reason_code'=>$reason,
            'evidence_reference_digest'=>CanonicalAttendanceIdempotency::evidence((string)($input['evidence_reference']??('claim-'.$kind))),
        );
    }

    /** Immutable command context: caller-supplied facts only, so an exact replay stays comparable. */
    private function evidenceFacts(object $case,array $intent):array{
        return array(
            'domain'=>'canonical_attendance_v1','operation'=>'ingest_provider_evidence',
            'case_id'=>(int)$case->id,'lesson_id'=>(int)$case->lesson_id,'schedule_version_id'=>(int)$case->schedule_version_id,
            'provider_code'=>(string)$intent['provider_code'],
            'provider_account_digest'=>(string)($intent['provider_account_digest']??''),
            'provider_event_key_digest'=>(string)($intent['provider_event_key_digest']??''),
            'provider_payload_digest'=>(string)($intent['provider_payload_digest']??''),
            'participant_role'=>(string)($intent['participant_role']??''),
            'join_at_utc'=>$intent['join_at_utc']??null,'leave_at_utc'=>$intent['leave_at_utc']??null,
            'observed_at'=>(string)($intent['observed_at']??''),
            'provenance_digest'=>(string)($intent['provenance_digest']??''),
        );
    }

    private function claimFacts(object $case,array $intent):array{
        return array('domain'=>'canonical_attendance_v1','operation'=>'submit_claim','case_id'=>(int)$case->id,'lesson_id'=>(int)$case->lesson_id,'schedule_version_id'=>(int)$case->schedule_version_id,'evidence_kind'=>$intent['evidence_kind'],'reason_code'=>$intent['reason_code'],'observed_at'=>$intent['observed_at'],'evidence_reference_digest'=>$intent['evidence_reference_digest']);
    }

    /** P-3/P-4: full immutable context equality for a replayed provider event. */
    private function sameEvidenceContext(object $existing,object $case,array $intent):bool{
        if((int)$existing->case_id!==(int)$case->id)return false;
        if((int)$existing->lesson_id!==(int)$case->lesson_id)return false;
        if((int)$existing->schedule_version_id!==(int)$case->schedule_version_id)return false;
        if((string)$existing->provider_code!==(string)$intent['provider_code'])return false;
        if((string)($existing->provider_account_digest??'')!==(string)($intent['provider_account_digest']??''))return false;
        if((string)($existing->participant_role??'')!==(string)($intent['participant_role']??''))return false;
        if(!hash_equals((string)$existing->provider_payload_digest,(string)$intent['provider_payload_digest']))return false;
        if((string)($existing->provenance_digest??'')!==(string)($intent['provenance_digest']??''))return false;
        if((string)($existing->join_at_utc??'')!==(string)($intent['join_at_utc']??''))return false;
        $existingLeave=$existing->leave_at_utc===null?null:(string)$existing->leave_at_utc;
        $incomingLeave=$intent['leave_at_utc']===null?null:(string)$intent['leave_at_utc'];
        if($existingLeave!==$incomingLeave)return false;
        if((string)$existing->observed_at!==(string)$intent['observed_at'])return false;
        return true;
    }

    /**
     * P-4: a refused conflicting provider event must still leave durable, reviewable evidence of the
     * conflict. The original evidence row is never overwritten and no canonical truth is touched.
     */
    private function conflictKind(object $existing,object $case):string{
        if((int)$existing->lesson_id!==(int)$case->lesson_id)return 'cross_lesson';
        if((int)$existing->schedule_version_id!==(int)$case->schedule_version_id)return 'cross_schedule_version';
        if((int)$existing->case_id!==(int)$case->id)return 'cross_context';
        return 'changed_payload';
    }

    private function recordConflict(object $case,object $existing,array $intent,string $kind):void{
        global $wpdb;
        if(!in_array($kind,CanonicalAttendanceValidator::CONFLICT_KINDS,true))$kind='cross_context';
        $now=gmdate('Y-m-d H:i:s');
        try{
            $this->repository->insertConflict(array(
                'uid'=>Identifier::uid(),'case_id'=>(int)$case->id,'lesson_id'=>(int)$case->lesson_id,
                'schedule_version_id'=>(int)$case->schedule_version_id,
                'provider_code'=>(string)$intent['provider_code'],
                'provider_event_key_digest'=>(string)$intent['provider_event_key_digest'],
                'conflict_kind'=>$kind,'existing_evidence_id'=>(int)$existing->id,
                'incoming_payload_digest'=>(string)($intent['provider_payload_digest']??''),
                'incoming_reference_digest'=>(string)$intent['evidence_reference_digest'],
                'detected_at'=>$now,'created_at'=>$now,'created_by'=>$this->actor(),
            ));
        }catch(\Throwable$e){
            // The conflict receipt is not itself a reason to lose the conflict: it is retried below by
            // an anomaly, which carries the same classification without depending on the unique key.
            if($wpdb->last_error==='')throw $e;
        }
        $this->openAnomaly($case,'duplicate_event_conflict',$now,$this->actor());
    }

    private function authoriseOwnership(string $kind,object $case,bool $late):void{
        if($this->isAdministrator())return; // administrator-on-behalf is recorded in attribution
        if($late)throw new \InvalidArgumentException('late_evidence_administrator_required');
        $user=get_current_user_id();
        if($kind==='delivery_claim'){
            if(!$this->hasTeacherPrincipal((int)$case->teacher_id,$user))throw new \RuntimeException('Unauthorized');
            return;
        }
        if(!$this->hasStudentPrincipal((int)$case->student_id,$user))throw new \RuntimeException('Unauthorized');
    }

    private function hasTeacherPrincipal(int $teacherId,int $userId):bool{
        global $wpdb;
        $p=$wpdb->prefix.'dzn_';
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}teacher_principal_links WHERE teacher_id=%d AND wordpress_user_id=%d AND status='active' AND revoked_at IS NULL",$teacherId,$userId))===1;
    }

    private function hasStudentPrincipal(int $studentId,int $userId):bool{
        global $wpdb;
        $p=$wpdb->prefix.'dzn_';
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}student_principal_links WHERE student_id=%d AND wordpress_user_id=%d AND status='active' AND revoked_at IS NULL",$studentId,$userId))===1;
    }

    private function isAfterTermClosure(int $termId):bool{
        global $wpdb;
        $p=$wpdb->prefix.'dzn_';
        $state=(string)$wpdb->get_var($wpdb->prepare("SELECT lifecycle_state FROM {$p}terms WHERE id=%d",$termId));
        return !in_array($state,array('current'),true);
    }

    private function optionalDigest(array $input,string $field):?string{
        $value=$input[$field]??null;
        if($value===null||$value==='')return null;
        return CanonicalAttendanceIdempotency::evidence((string)$value);
    }

    private function insertCommand(string $digest,?string $payload,string $operation,array $facts,?object $case,?int $evidenceId,string $state,?int $decisionId,string $now,int $actor):int{
        return $this->repository->insertCommand(array(
            'uid'=>Identifier::uid(),'command_domain'=>'canonical_attendance_v1','operation'=>$operation,
            'command_key_digest'=>$digest,'command_payload_digest'=>$payload??CanonicalAttendanceIdempotency::payload($facts),
            'case_id'=>$case?(int)$case->id:null,'lesson_id'=>(int)($facts['lesson_id']??0)>0?(int)$facts['lesson_id']:null,
            'schedule_version_id'=>isset($facts['schedule_version_id'])?(int)$facts['schedule_version_id']:null,
            'subject_id'=>null,
            'expected_case_version'=>$facts['expected_case_version']??null,
            'result_evidence_id'=>$evidenceId,'result_decision_id'=>$decisionId,'result_state'=>$state,
            'created_at'=>$now,'created_by'=>$actor,
        ));
    }

    private function replayCommand(object $command,string $payload,string $operation):array{
        if(!hash_equals((string)$command->command_payload_digest,$payload))throw new IdempotencyConflictException('Idempotency conflict');
        if((string)$command->command_domain!=='canonical_attendance_v1'||(string)$command->operation!==$operation)throw new \RuntimeException('Contaminated canonical attendance command');
        return array(
            'case_id'=>$command->case_id===null?null:(int)$command->case_id,
            'operation'=>$operation,'idempotent'=>true,
            'evidence_id'=>$command->result_evidence_id===null?null:(int)$command->result_evidence_id,
            'decision_id'=>$command->result_decision_id===null?null:(int)$command->result_decision_id,
            'state'=>(string)$command->result_state,
        );
    }

    private function ingestReplay(string $digest,string $payload,object $case):?array{
        $winner=$this->repository->command($digest);
        if(!$winner)return null;
        if(!hash_equals((string)$winner->command_payload_digest,$payload))throw new IdempotencyConflictException('Idempotency conflict');
        if((string)$winner->command_domain!=='canonical_attendance_v1'||(string)$winner->operation!=='ingest_provider_evidence')throw new \RuntimeException('Contaminated canonical attendance command');
        if((int)$winner->lesson_id!==(int)$case->lesson_id||$winner->schedule_version_id===null||(int)$winner->schedule_version_id!==(int)$case->schedule_version_id)throw new IdempotencyConflictException('Idempotency conflict');
        $fresh=$this->repository->caseById((int)$case->id,true);
        $converge=$fresh!==null&&(string)$fresh->state==='settlement_pending';
        return array('case_id'=>(int)$case->id,'operation'=>'ingest_provider_evidence','idempotent'=>true,'evidence_id'=>$winner->result_evidence_id===null?null:(int)$winner->result_evidence_id,'decision_id'=>$winner->result_decision_id===null?null:(int)$winner->result_decision_id,'state'=>$fresh===null?(string)$winner->result_state:(string)$fresh->state,'assessment'=>null,'settlement'=>null,'converge'=>$converge);
    }

    // ---------------------------------------------------------------------
    // Duplicate-key recovery: the expected payload and context are always compared, never assumed.
    // ---------------------------------------------------------------------

    private function replayIngestCommand(object $winner,int $lessonId,int $scheduleVersionId,array $intent):array{
        if((string)$winner->operation!=='ingest_provider_evidence'||$winner->case_id===null)throw new IdempotencyConflictException('Idempotency conflict');
        if((int)$winner->lesson_id!==$lessonId||(int)($winner->schedule_version_id??0)!==$scheduleVersionId)throw new IdempotencyConflictException('Idempotency conflict');
        $case=$this->repository->caseById((int)$winner->case_id);
        if(!$case)throw new IdempotencyConflictException('Idempotency conflict');
        $expected=CanonicalAttendanceIdempotency::payload($this->evidenceFacts($case,$intent));
        return $this->replayCommand($winner,$expected,'ingest_provider_evidence');
    }

    private function replayClaimCommand(object $winner,int $lessonId,int $scheduleVersionId,array $intent):array{
        if((string)$winner->operation!=='submit_claim'||$winner->case_id===null)throw new IdempotencyConflictException('Idempotency conflict');
        if((int)$winner->lesson_id!==$lessonId||(int)($winner->schedule_version_id??0)!==$scheduleVersionId)throw new IdempotencyConflictException('Idempotency conflict');
        $case=$this->repository->caseById((int)$winner->case_id);
        if(!$case)throw new IdempotencyConflictException('Idempotency conflict');
        $expected=CanonicalAttendanceIdempotency::payload($this->claimFacts($case,$intent));
        return $this->replayCommand($winner,$expected,'submit_claim');
    }

    private function replayAdjudicationCommand(object $winner,int $caseId,string $outcome,int $expected):array{
        if((string)$winner->operation!=='adjudicate'||(int)($winner->case_id??0)!==$caseId)throw new IdempotencyConflictException('Idempotency conflict');
        $case=$this->repository->caseById($caseId);
        if(!$case)throw new IdempotencyConflictException('Idempotency conflict');
        $facts=array('case_id'=>$caseId,'adjudication'=>$outcome,'expected_case_version'=>$expected,'lesson_id'=>(int)$case->lesson_id);
        return $this->replayCommand($winner,CanonicalAttendanceIdempotency::payload($facts),'adjudicate');
    }

    private function replayReassessCommand(object $winner,int $caseId,int $expected):array{
        if((string)$winner->operation!=='reassess'||(int)($winner->case_id??0)!==$caseId)throw new IdempotencyConflictException('Idempotency conflict');
        $facts=array('case_id'=>$caseId,'operation'=>'reassess','expected_case_version'=>$expected);
        return $this->replayCommand($winner,CanonicalAttendanceIdempotency::payload($facts),'reassess');
    }

    private function requireCapability(string $capability):void{if(!current_user_can($capability))throw new \RuntimeException('Unauthorized');}
    private function isAdministrator():bool{return current_user_can(self::REVIEW_CAPABILITY);}
    private function actor(?int $anonymousActor=null):int{$id=get_current_user_id();if($id>0)return$id;if($anonymousActor===0)return 0;throw new \RuntimeException('Canonical attendance actor unavailable');}
}
