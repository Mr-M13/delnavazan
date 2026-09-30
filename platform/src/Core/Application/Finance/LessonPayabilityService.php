<?php
namespace Delnavazan\Platform\Core\Application\Finance;

use Delnavazan\Platform\Core\Application\Finance\Integrity\{FinancePayabilityIntegrity,FinanceSnapshotIntegrity};
use Delnavazan\Platform\Core\Infrastructure\Repository\{FinancePayabilityRepository,FinanceSnapshotRepository};

/**
 * Lesson payability authority (contract §9).
 *
 * Payability is a versioned derivation from canonical facts under
 * `FinanceRule::PAYABILITY_DERIVATION_VERSION`, plus an audited, additive administrator override. The
 * evaluation chain is append-only with exactly one applicable row per Lesson; a `pending` disposition
 * blocks statement issuance and can never be silently treated as payable or non-payable.
 */
final class LessonPayabilityService {
    private const CAPABILITY='dzn_manage_lesson_payability';

    public function __construct(
        private ?FinancePayabilityRepository $evaluations=null,
        private ?FinanceSnapshotRepository $snapshots=null,
        private ?FinanceFacts $facts=null,
        private ?FinancePolicyService $policies=null
    ){
        $this->evaluations??=new FinancePayabilityRepository();
        $this->snapshots??=new FinanceSnapshotRepository();
        $this->facts??=new FinanceFacts();
        $this->policies??=new FinancePolicyService();
    }

    /**
     * The §5.3 derivation table, in one place.
     *
     * The table is evaluated in the numbered order and the first matching row decides. A governed row
     * whose key resolves to unset reports `unset_policy_key` instead of substituting a value, so the
     * caller can record `finance_policy_unset` (evaluation) or provenance (snapshot) exactly as its own
     * section requires.
     *
     * @return array{disposition:?string,basis_code:?string,policy_key:?string,policy_version:?int,unset_policy_key:?string}
     */
    public static function derive(array $context,array $policies,array $rows=FinanceRule::PAYABILITY_DERIVATION):array{
        $outcome=$context['outcome'];
        $code=$outcome?(string)$outcome->outcome_code:null;
        foreach($rows as $row){
            $policyKey=$row['policy_key'];
            $governed=$policyKey!==null;
            $set=$governed?(bool)($policies[$policyKey]['set']??false):true;
            $value=$governed?($policies[$policyKey]['value']??null):null;
            $version=$governed?($policies[$policyKey]['version']??null):null;
            switch($row['fact']){
                case 'academy_obligation':
                    if($context['obligation']===null)continue 2;
                    break;
                case 'not_finalised_with_snapshot':
                    if($context['finalised'])continue 2;
                    break;
                case 'outcome_review_required':
                    if($code!=='review_required')continue 2;
                    break;
                case 'outcome_teacher_non_delivery':
                    if($code!=='teacher_non_delivery')continue 2;
                    break;
                case 'introductory_non_payable':
                    if((string)$context['kind']!=='introductory')continue 2;
                    if(!$set)return array('disposition'=>null,'basis_code'=>null,'policy_key'=>$policyKey,'policy_version'=>null,'unset_policy_key'=>$policyKey);
                    if($value!=='non_payable')continue 2;
                    break;
                case 'completed_without_outcome':
                    if((string)$context['state']!=='completed'||$outcome!==null)continue 2;
                    break;
                case 'outcome_delivered':
                    if($code!=='delivered')continue 2;
                    break;
                case 'outcome_student_no_show':
                case 'outcome_interruption':
                    $expected=$row['fact']==='outcome_student_no_show'?'student_no_show':'interruption';
                    if($code!==$expected)continue 2;
                    if(!$set)return array('disposition'=>null,'basis_code'=>null,'policy_key'=>$policyKey,'policy_version'=>null,'unset_policy_key'=>$policyKey);
                    if($value==='payable')$disposition='payable';
                    elseif($value==='non_payable')$disposition='non_payable';
                    else return array('disposition'=>null,'basis_code'=>null,'policy_key'=>$policyKey,'policy_version'=>null,'unset_policy_key'=>$policyKey);
                    return array('disposition'=>$disposition,'basis_code'=>$row['basis_code'],'policy_key'=>$policyKey,'policy_version'=>$version===null?null:(int)$version,'unset_policy_key'=>null);
                case 'cancelled_before_occurrence':
                    if((string)$context['state']!=='cancelled'||$outcome!==null)continue 2;
                    break;
                case 'administrator_override':
                    continue 2;
                default:
                    continue 2;
            }
            return array('disposition'=>$row['disposition'],'basis_code'=>$row['basis_code'],'policy_key'=>$policyKey,'policy_version'=>$version===null?null:(int)$version,'unset_policy_key'=>null);
        }
        // No declared row matched: the facts are ambiguous, so the derivation blocks rather than guesses.
        return array('disposition'=>'pending','basis_code'=>'delivery_review_required','policy_key'=>null,'policy_version'=>null,'unset_policy_key'=>null);
    }

    /** §9.2 `evaluate`: append a new evaluation only when it differs from the effective one. */
    public function evaluate(int $lessonId,string $rawKey):array{
        FinanceSupport::requireCapability(self::CAPABILITY);
        $actor=FinanceSupport::actor();$now=FinanceSupport::now();$digest=FinanceSupport::key($rawKey);
        $hint=$this->facts->lesson($lessonId);
        if(!$hint)throw new FinanceRefusalException('finance_parent_not_live','The canonical Lesson does not exist');
        $teacherId=(int)$hint->teacher_id;
        $payload=FinanceSupport::payload(array('lesson_id'=>$lessonId,'operation'=>'evaluate'));
        $command=array('command_domain'=>FinanceRule::DOMAIN,'operation'=>'evaluate','command_key_digest'=>$digest,'command_payload_digest'=>$payload,'teacher_id'=>$teacherId,'lesson_id'=>$lessonId,'evaluation_id'=>null,'override_id'=>null,'result_state'=>FinanceRule::commandSuccessState('evaluate'),'result_evaluation_id'=>null,'result_override_id'=>null,'reason_code'=>null,'created_at'=>$now,'created_by'=>$actor);
        $lock=static fn()=>FinanceSupport::lockPolicyRootThenTeacher($teacherId,$actor);
        return FinanceSupport::runCommand($lock,'finance_payability_commands',$command,function()use($lessonId,$actor,$now,$payload,$digest,&$command){
            if($existing=$this->evaluations->command($digest))return $this->replay($existing,$payload,'evaluate');
            $context=$this->facts->context($lessonId,true);
            $effective=FinanceSnapshotIntegrity::effective($lessonId,$this->snapshots);
            $snapshot=$effective['snapshot'];
            $instant=(string)$snapshot->snapshot_instant_utc;
            $policies=array();
            foreach(array('INTRO_PAYABILITY_POLICY','STUDENT_NO_SHOW_COMPENSATION_POLICY','INTERRUPTION_COMPENSATION_POLICY') as $key)$policies[$key]=$this->policies->resolve($key,$instant);
            $derived=self::derive($context,$policies);
            if($derived['unset_policy_key']!==null)throw new FinanceRefusalException('finance_policy_unset','A governed derivation needs the recorded version that covers its snapshot instant',array('teacher_id'=>(int)$context['teacher_id'],'lesson_id'=>$lessonId));
            if($derived['disposition']===null||$derived['basis_code']===null)throw new FinanceRefusalException('payability_conflicts_with_delivery_fact','The recorded delivery facts decide no declared payability row',array('teacher_id'=>(int)$context['teacher_id'],'lesson_id'=>$lessonId));
            $outcome=$context['outcome'];
            $current=$this->evaluations->applicable($lessonId,true);
            $values=array('lesson_id'=>$lessonId,'disposition'=>$derived['disposition'],'basis_code'=>$derived['basis_code'],'lesson_kind'=>$context['kind'],'delivery_outcome_id'=>$outcome?(int)$outcome->id:null,'delivery_state'=>$outcome?(string)$outcome->delivery_state:null,'attendance_state'=>$outcome?(string)$outcome->attendance_state:null,'remedy_class'=>$outcome?(string)$outcome->remedy_class:null,'academy_obligation_id'=>$context['obligation']?(int)$context['obligation']->id:null,'schedule_version_id'=>$context['schedule_version_id']>0?$context['schedule_version_id']:null,'snapshot_id'=>(int)$snapshot->id,'override_id'=>null,'policy_key'=>$derived['policy_key'],'policy_version'=>$derived['policy_version']);
            $recomputed=FinancePayabilityIntegrity::digest($values);
            $proof=FinanceSupport::evidence(array('evidence_channel'=>'authenticated_platform','evidence_reference'=>'finance-evaluate-'.$digest,'evidence_at'=>$now));
            if($current&&(string)$current->derivation_digest===$recomputed&&$current->override_id===null)return array('evaluation_id'=>(int)$current->id,'lesson_id'=>$lessonId,'disposition'=>(string)$current->disposition,'appended'=>false,'idempotent'=>true);
            $sequence=$this->evaluations->nextEvaluationSequence($lessonId);
            // §15.4: the appended evaluation carries an empty slot until its predecessor has released the
            // Lesson's one applicable slot — the declared `UNIQUE lesson_applicable` admits exactly one
            // non-NULL slot, so the append must not pre-claim the slot it is about to replace.
            $evaluationId=$this->evaluations->insertEvaluation(array(
                'lesson_id'=>$lessonId,'evaluation_sequence'=>$sequence,'applicable_slot'=>null,
                'disposition'=>$derived['disposition'],'basis_code'=>$derived['basis_code'],'lesson_kind'=>$context['kind'],
                'delivery_outcome_id'=>$values['delivery_outcome_id'],'delivery_state'=>$values['delivery_state'],
                'attendance_state'=>$values['attendance_state'],'remedy_class'=>$values['remedy_class'],
                'academy_obligation_id'=>$values['academy_obligation_id'],'schedule_version_id'=>$values['schedule_version_id'],
                'snapshot_id'=>$values['snapshot_id'],'override_id'=>null,
                'policy_key'=>$derived['policy_key'],'policy_version'=>$derived['policy_version'],
                'derivation_digest'=>$recomputed,'rule_version'=>FinanceRule::PAYABILITY_DERIVATION_VERSION,'reason_code'=>'operator_decision',
                'evidence_channel'=>$proof['channel'],'evidence_reference_digest'=>$proof['digest'],'evidence_at'=>$proof['at'],
                'superseded_at'=>null,'superseded_by_evaluation_id'=>null,
                'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            FinanceSupport::audit('finance_payability_evaluations',$evaluationId,'evaluate',$actor,$digest,null,$now,null);
            if($current){
                if($this->evaluations->supersedeEvaluation((int)$current->id,$evaluationId,$now)!==1)throw new FinanceRefusalException('payability_supersession_conflict','The previous applicable evaluation was already replaced',array('teacher_id'=>(int)$context['teacher_id'],'lesson_id'=>$lessonId));
                FinanceSupport::audit('finance_payability_evaluations',(int)$current->id,'supersede',$actor,$digest,null,$now,null);
            }
            if($this->evaluations->claimApplicable($evaluationId)!==1)throw new FinanceRefusalException('payability_supersession_conflict','The appended evaluation could not claim the Lesson\'s one applicable slot',array('teacher_id'=>(int)$context['teacher_id'],'lesson_id'=>$lessonId));
            $command['evaluation_id']=$evaluationId;$command['result_evaluation_id']=$evaluationId;
            $commandId=$this->evaluations->insertCommand($command);
            return array('evaluation_id'=>$evaluationId,'lesson_id'=>$lessonId,'disposition'=>$derived['disposition'],'basis_code'=>$derived['basis_code'],'appended'=>true,'command_id'=>$commandId);
        });
    }

    /** §9.3 `override`: append a new evaluation carrying the audited override, leaving the derivation intact. */
    public function override(int $lessonId,string $disposition,string $reasonCode,array $input,string $rawKey):array{
        FinanceSupport::requireCapability(self::CAPABILITY);
        $actor=FinanceSupport::actor();$now=FinanceSupport::now();$digest=FinanceSupport::key($rawKey);
        $hint=$this->facts->lesson($lessonId);
        if(!$hint)throw new FinanceRefusalException('finance_parent_not_live','The canonical Lesson does not exist');
        $teacherId=(int)$hint->teacher_id;
        $payload=FinanceSupport::payload(array('lesson_id'=>$lessonId,'disposition'=>$disposition,'reason'=>$reasonCode,'operation'=>'override'));
        $command=array('command_domain'=>FinanceRule::DOMAIN,'operation'=>'override','command_key_digest'=>$digest,'command_payload_digest'=>$payload,'teacher_id'=>$teacherId,'lesson_id'=>$lessonId,'evaluation_id'=>null,'override_id'=>null,'result_state'=>FinanceRule::commandSuccessState('override'),'result_evaluation_id'=>null,'result_override_id'=>null,'reason_code'=>$reasonCode,'created_at'=>$now,'created_by'=>$actor);
        $lock=static fn()=>FinanceSupport::lockPolicyRootThenTeacher($teacherId,$actor);
        return FinanceSupport::runCommand($lock,'finance_payability_commands',$command,function()use($lessonId,$disposition,$reasonCode,$input,$actor,$now,$payload,$digest,&$command){
            if($existing=$this->evaluations->command($digest))return $this->replay($existing,$payload,'override');
            if(!FinanceRule::member($disposition,FinanceRule::DISPOSITIONS))throw new FinanceRefusalException('finance_vocabulary_member_not_allowed','A disposition is one of the declared members');
            FinanceSupport::requireReason($reasonCode);
            if(!FinanceRule::operatorReason($reasonCode))throw new FinanceRefusalException('finance_vocabulary_member_not_allowed','An override reason is chosen from the declared operator set');
            $context=$this->facts->context($lessonId,true);
            $current=$this->evaluations->applicable($lessonId,true);
            if(!$current)throw new FinanceRefusalException('payability_override_target_invalid','An override names the exact prior evaluation it replaces',array('teacher_id'=>(int)$context['teacher_id'],'lesson_id'=>$lessonId));
            $snapshot=$this->snapshots->byId((int)$current->snapshot_id);
            if(!$snapshot)throw new FinanceRefusalException('snapshot_missing_for_lesson','The evaluation is not bound to a snapshot',array('teacher_id'=>(int)$context['teacher_id'],'lesson_id'=>$lessonId));
            $proof=FinanceSupport::evidence($input);
            $sequence=$this->evaluations->nextOverrideSequence($lessonId);
            $overrideId=$this->evaluations->insertOverride(array(
                'lesson_id'=>$lessonId,'override_sequence'=>$sequence,'prior_evaluation_id'=>(int)$current->id,
                'prior_derivation_digest'=>(string)$current->derivation_digest,'disposition'=>$disposition,'reason_code'=>$reasonCode,
                'note'=>($input['note']??null)===null?null:substr((string)$input['note'],0,190),
                'evidence_channel'=>$proof['channel'],'evidence_reference_digest'=>$proof['digest'],'evidence_at'=>$proof['at'],
                'result_evaluation_id'=>null,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $values=array('lesson_id'=>$lessonId,'disposition'=>$disposition,'basis_code'=>'administrator_override','lesson_kind'=>(string)$current->lesson_kind,'delivery_outcome_id'=>$current->delivery_outcome_id===null?null:(int)$current->delivery_outcome_id,'delivery_state'=>$current->delivery_state===null?null:(string)$current->delivery_state,'attendance_state'=>$current->attendance_state===null?null:(string)$current->attendance_state,'remedy_class'=>$current->remedy_class===null?null:(string)$current->remedy_class,'academy_obligation_id'=>$current->academy_obligation_id===null?null:(int)$current->academy_obligation_id,'schedule_version_id'=>$current->schedule_version_id===null?null:(int)$current->schedule_version_id,'snapshot_id'=>(int)$current->snapshot_id,'override_id'=>$overrideId,'policy_key'=>null,'policy_version'=>null);
            $digestValue=FinancePayabilityIntegrity::digest($values);
            $evaluationId=$this->evaluations->insertEvaluation(array(
                'lesson_id'=>$lessonId,'evaluation_sequence'=>$this->evaluations->nextEvaluationSequence($lessonId),'applicable_slot'=>null,
                'disposition'=>$disposition,'basis_code'=>'administrator_override','lesson_kind'=>$values['lesson_kind'],
                'delivery_outcome_id'=>$values['delivery_outcome_id'],'delivery_state'=>$values['delivery_state'],
                'attendance_state'=>$values['attendance_state'],'remedy_class'=>$values['remedy_class'],
                'academy_obligation_id'=>$values['academy_obligation_id'],'schedule_version_id'=>$values['schedule_version_id'],
                'snapshot_id'=>$values['snapshot_id'],'override_id'=>$overrideId,'policy_key'=>null,'policy_version'=>null,
                'derivation_digest'=>$digestValue,'rule_version'=>FinanceRule::PAYABILITY_DERIVATION_VERSION,'reason_code'=>$reasonCode,
                'evidence_channel'=>$proof['channel'],'evidence_reference_digest'=>$proof['digest'],'evidence_at'=>$proof['at'],
                'superseded_at'=>null,'superseded_by_evaluation_id'=>null,
                'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->evaluations->attachOverrideResult($overrideId,$evaluationId);
            FinanceSupport::audit('finance_payability_overrides',$overrideId,'override',$actor,$digest,$reasonCode,$now,null);
            FinanceSupport::audit('finance_payability_evaluations',$evaluationId,'override',$actor,$digest,$reasonCode,$now,null);
            if($this->evaluations->supersedeEvaluation((int)$current->id,$evaluationId,$now)!==1)throw new FinanceRefusalException('payability_supersession_conflict','The previous applicable evaluation was already replaced',array('teacher_id'=>(int)$context['teacher_id'],'lesson_id'=>$lessonId));
            FinanceSupport::audit('finance_payability_evaluations',(int)$current->id,'supersede',$actor,$digest,null,$now,null);
            if($this->evaluations->claimApplicable($evaluationId)!==1)throw new FinanceRefusalException('payability_supersession_conflict','The appended override evaluation could not claim the Lesson\'s one applicable slot',array('teacher_id'=>(int)$context['teacher_id'],'lesson_id'=>$lessonId));
            $command['evaluation_id']=(int)$current->id;$command['override_id']=$overrideId;$command['result_evaluation_id']=$evaluationId;$command['result_override_id']=$overrideId;
            $commandId=$this->evaluations->insertCommand($command);
            return array('evaluation_id'=>$evaluationId,'override_id'=>$overrideId,'lesson_id'=>$lessonId,'disposition'=>$disposition,'appended'=>true,'command_id'=>$commandId);
        });
    }

    /** The effective payability of one Lesson, proved through the chain validator. */
    public function effective(int $lessonId):?array{
        $row=FinancePayabilityIntegrity::effective($lessonId,$this->evaluations,$this->snapshots);
        if(!$row)return null;
        return array('evaluation_id'=>(int)$row->id,'lesson_id'=>(int)$row->lesson_id,'disposition'=>(string)$row->disposition,'basis_code'=>(string)$row->basis_code,'snapshot_id'=>(int)$row->snapshot_id,'override_id'=>$row->override_id===null?null:(int)$row->override_id,'policy_key'=>$row->policy_key===null?null:(string)$row->policy_key,'policy_version'=>$row->policy_version===null?null:(int)$row->policy_version);
    }
    /**
     * §15.3/§9.1: an identical replay converges on the recorded evaluation — and only after that row has
     * been re-loaded under the held root and re-proved against its own chain.
     *
     * The evaluation must exist, still name the command's own Lesson, still reproduce its declared
     * derivation digest and vocabulary, and still be bound to a snapshot of that Lesson; an override's
     * recorded override row must still name the evaluation it produced and carry the recorded reason.
     * The command's exact typed result shape is re-proved first, and every required reference is
     * cross-linked: `evaluate` records `result_evaluation_id` alone, so an evaluation that carries an
     * override, or a command row that carries a `result_override_id` it never recorded, fails closed;
     * `override` must name one override row whose own typed result is the re-loaded evaluation, whose
     * `result_override_id` and `override_id` selector both name that row, and whose prior evaluation is
     * the one the command recorded — so a substituted secondary result id can never be returned as a
     * converged replay. A missing or mismatched row fails closed
     * (`upstream_aggregate_invalid`/`snapshot_missing_for_lesson` for a corrupt shape,
     * `command_replay_conflict` for a result that no longer matches the command).
     */
    private function replay(object $row,string $payload,string $operation):array{
        if(!hash_equals((string)$row->command_payload_digest,$payload)||(string)$row->operation!==$operation)throw new FinanceRefusalException('command_replay_conflict','A materially different replay is refused and the original record is preserved');
        FinanceSupport::assertReplayState($row,$operation);
        FinanceSupport::assertReplayResultShape($row,'finance_payability_commands',$operation);
        $evaluation=FinanceSupport::replayResultRow((int)$row->result_evaluation_id,fn(int $id)=>$this->evaluations->evaluationById($id,true),array(
            'lesson_id'=>(int)$row->lesson_id,
        ),'finance_payability_evaluations');
        $values=array();
        foreach(FinanceRule::EVALUATION_DIGEST_FIELDS as $field)$values[$field]=$evaluation->{$field}??null;
        if(!hash_equals((string)$evaluation->derivation_digest,FinancePayabilityIntegrity::digest($values)))throw new FinanceRefusalException('upstream_aggregate_invalid','The replayed evaluation no longer reproduces its recorded derivation digest');
        if(!FinanceRule::member((string)$evaluation->disposition,FinanceRule::DISPOSITIONS)||!FinanceRule::member((string)$evaluation->basis_code,FinanceRule::BASIS_CODES))throw new FinanceRefusalException('upstream_aggregate_invalid','The replayed evaluation no longer carries a declared disposition and basis');
        $snapshot=$this->snapshots->byId((int)$evaluation->snapshot_id,true);
        if(!$snapshot||(int)$snapshot->lesson_id!==(int)$evaluation->lesson_id)throw new FinanceRefusalException('snapshot_missing_for_lesson','The replayed evaluation is no longer bound to a snapshot of its own Lesson');
        $overrideId=null;
        if($operation==='evaluate'){
            if($evaluation->override_id!==null)throw new FinanceRefusalException('command_replay_conflict','A replayed derivation names an evaluation that carries an override it never recorded');
            FinanceSupport::assertReplayPayload((string)$row->command_payload_digest,array('lesson_id'=>(int)$evaluation->lesson_id,'operation'=>'evaluate'),'finance_payability_evaluations');
        }
        if($operation==='override'){
            if($evaluation->override_id===null)throw new FinanceRefusalException('command_replay_conflict','A replayed override names an evaluation that carries no override');
            $override=$this->evaluations->overrideById((int)$evaluation->override_id,true);
            if(!$override||(int)$override->result_evaluation_id!==(int)$evaluation->id||(int)$override->lesson_id!==(int)$evaluation->lesson_id)throw new FinanceRefusalException('payability_override_target_invalid','The replayed override no longer names the evaluation it produced');
            if((int)$row->result_override_id!==(int)$override->id)throw new FinanceRefusalException('command_replay_conflict','The recorded override result no longer names the override the replayed evaluation carries');
            if($row->override_id===null||(int)$row->override_id!==(int)$override->id)throw new FinanceRefusalException('command_replay_conflict','The recorded override selector no longer names the override the replayed evaluation carries');
            if($row->evaluation_id===null||(int)$row->evaluation_id!==(int)$override->prior_evaluation_id)throw new FinanceRefusalException('command_replay_conflict','The recorded override no longer names the prior evaluation the command recorded');
            FinanceSupport::assertReplayPayload((string)$row->command_payload_digest,array('lesson_id'=>(int)$evaluation->lesson_id,'disposition'=>(string)$evaluation->disposition,'reason'=>(string)$row->reason_code,'operation'=>'override'),'finance_payability_overrides');
            $overrideId=(int)$override->id;
        }
        return array('evaluation_id'=>(int)$evaluation->id,'override_id'=>$overrideId,'recorded'=>true,'idempotent'=>true,'command_id'=>(int)$row->id);
    }
}
