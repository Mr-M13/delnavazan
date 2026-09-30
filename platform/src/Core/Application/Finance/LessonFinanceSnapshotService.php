<?php
namespace Delnavazan\Platform\Core\Application\Finance;

use Delnavazan\Platform\Core\Application\Finance\Integrity\{FinanceRateIntegrity,FinanceSnapshotIntegrity};
use Delnavazan\Platform\Core\Infrastructure\Repository\{FinanceSnapshotRepository,TeacherRateRepository};
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Per-Lesson rate/currency snapshot authority (contract §8).
 *
 * Exactly one immutable snapshot exists per canonical Lesson, derived from the rate in force at the
 * Lesson's locked occurrence-start instant (U-D4) and from the policy version that covers that instant.
 * Capture is idempotent and deterministic; a capture that cannot resolve an effective rate or an
 * applicable occurrence writes no row and records the exact blocker, and a snapshot is never mutated —
 * only an audited correction (§12.2) can change its effective meaning.
 */
final class LessonFinanceSnapshotService {
    private const CAPABILITY='dzn_manage_finance_statements';

    public function __construct(
        private ?FinanceSnapshotRepository $snapshots=null,
        private ?TeacherRateRepository $rates=null,
        private ?TeacherRateService $rateService=null,
        private ?FinanceFacts $facts=null,
        private ?FinancePolicyService $policies=null
    ){
        $this->snapshots??=new FinanceSnapshotRepository();
        $this->rates??=new TeacherRateRepository();
        $this->facts??=new FinanceFacts();
        $this->rateService??=new TeacherRateService($this->rates,$this->facts);
        $this->policies??=new FinancePolicyService();
    }

    /** §8.2 `capture`: the one immutable snapshot of a Lesson, or a recorded blocker. */
    public function capture(int $lessonId,string $rawKey):array{
        FinanceSupport::requireCapability(self::CAPABILITY);
        $actor=FinanceSupport::actor();$now=FinanceSupport::now();$digest=FinanceSupport::key($rawKey);
        $hint=$this->facts->lesson($lessonId);
        if(!$hint)throw new FinanceRefusalException('finance_parent_not_live','The canonical Lesson does not exist');
        $teacherId=(int)$hint->teacher_id;
        $payload=FinanceSupport::payload(array('lesson_id'=>$lessonId,'operation'=>'capture'));
        $command=array('command_domain'=>FinanceRule::DOMAIN,'operation'=>'capture','command_key_digest'=>$digest,'command_payload_digest'=>$payload,'teacher_id'=>$teacherId,'lesson_id'=>$lessonId,'snapshot_id'=>null,'correction_id'=>null,'result_state'=>FinanceRule::commandSuccessState('capture'),'result_snapshot_id'=>null,'result_correction_id'=>null,'reason_code'=>null,'created_at'=>$now,'created_by'=>$actor);
        $lock=static fn()=>FinanceSupport::lockPolicyRootThenTeacher($teacherId,$actor);
        return FinanceSupport::runCommand($lock,'finance_snapshot_commands',$command,function()use($lessonId,$rawKey,$actor,$now,$payload,$digest,&$command){
            if($existing=$this->snapshots->command($digest))return $this->replay($existing,$payload,'capture');
            $context=$this->facts->context($lessonId,true);
            if(!$context['finalised'])throw new FinanceRefusalException('snapshot_lesson_not_finalised','A plan can still move under Phase N, so only a finalised Lesson is snapshotted',array('teacher_id'=>(int)$context['teacher_id'],'lesson_id'=>$lessonId));
            if(!FinanceRule::member((string)$context['kind'],array('standard','replacement','introductory')))throw new FinanceRefusalException('finance_lesson_kind_not_allowed','The Lesson kind is not one of the three canonical kinds',array('teacher_id'=>(int)$context['teacher_id'],'lesson_id'=>$lessonId));
            $instant=(string)$context['occurrence_starts_at_utc'];
            $resolution=$this->rateService->resolveFor((int)$context['teacher_id'],(int)$context['course_id'],$instant,true);
            if(!$resolution['resolved'])throw new FinanceRefusalException('rate_missing_for_lesson','No effective teacher rate covers the Lesson\'s locked instant (recorded gap)',array('teacher_id'=>(int)$context['teacher_id'],'lesson_id'=>$lessonId));
            $rate=$resolution['rate'];
            $introKey=null;$introVersion=null;
            if((string)$context['kind']==='introductory'){
                $intro=$this->policies->resolve('INTRO_PAYABILITY_POLICY',$instant);
                if(!$intro['set'])throw new FinanceRefusalException('finance_policy_unset','An introductory Lesson needs the intro payability version that covers its instant',array('teacher_id'=>(int)$context['teacher_id'],'lesson_id'=>$lessonId));
                $introKey=(string)$intro['key'];$introVersion=(int)$intro['version'];
            }
            $policies=array();
            foreach(array('INTRO_PAYABILITY_POLICY','STUDENT_NO_SHOW_COMPENSATION_POLICY','INTERRUPTION_COMPENSATION_POLICY') as $key)$policies[$key]=$this->policies->resolve($key,$instant);
            $provenance=LessonPayabilityService::derive($context,$policies);
            $disposition=$provenance['disposition']??'pending';
            // U-D1/§8.3: `per_session` is the only basis, so the derived amount is an exact copy.
            $derivedAmount=(int)$rate->amount_minor;
            $values=array(
                'lesson_id'=>$lessonId,'enrolment_id'=>$context['enrolment_id'],'term_id'=>$context['term_id'],
                'teacher_id'=>(int)$context['teacher_id'],'teacher_assignment_id'=>(int)$context['teacher_assignment_id'],
                'course_id'=>(int)$context['course_id'],'lesson_kind'=>(string)$context['kind'],
                'schedule_version_id'=>(int)$context['schedule_version_id'],'occurrence_starts_at_utc'=>(string)$context['occurrence_starts_at_utc'],
                'occurrence_ends_at_utc'=>(string)$context['occurrence_ends_at_utc'],'duration_minutes'=>(int)$context['duration_minutes'],
                'snapshot_instant_utc'=>$instant,'snapshot_boundary'=>FinanceRule::SNAPSHOT_BOUNDARY,
                'rate_id'=>(int)$rate->id,'rate_version'=>(int)$rate->rate_version,'scope_kind'=>(string)$rate->scope_kind,
                'compensation_basis'=>(string)$rate->compensation_basis,'rate_amount_minor'=>(int)$rate->amount_minor,
                'currency'=>(string)$rate->currency,'derived_amount_minor'=>$derivedAmount,
                'intro_policy_key'=>$introKey,'intro_policy_version'=>$introVersion,
            );
            $digestValue=FinanceSnapshotIntegrity::digest($values);
            $recorded=$this->snapshots->byLesson($lessonId,true);
            if($recorded){
                // §8.2 replay rules: an identical capture converges only after the stored row is re-verified.
                FinanceSnapshotIntegrity::assertDigest($recorded);
                if((int)$recorded->rate_id!==(int)$rate->id||(int)$recorded->rate_version!==(int)$rate->rate_version||(int)$recorded->derived_amount_minor!==$derivedAmount||(string)$recorded->currency!==(string)$rate->currency||(string)$recorded->snapshot_instant_utc!==$instant||!hash_equals((string)$recorded->derivation_digest,$digestValue))throw new FinanceRefusalException('command_replay_conflict','A capture whose intent differs from the recorded snapshot is refused and never overwrites it',array('teacher_id'=>(int)$context['teacher_id'],'lesson_id'=>$lessonId));
                $command['snapshot_id']=(int)$recorded->id;$command['result_snapshot_id']=(int)$recorded->id;
                $commandId=$this->snapshots->insertCommand($command);
                return array('snapshot_id'=>(int)$recorded->id,'lesson_id'=>$lessonId,'converged'=>true,'command_id'=>$commandId);
            }
            $snapshotId=$this->snapshots->insertSnapshot(array(
                'uid'=>Identifier::uid(),'reference_code'=>null,
                'lesson_id'=>$lessonId,'enrolment_id'=>$values['enrolment_id'],'term_id'=>$values['term_id'],
                'teacher_id'=>$values['teacher_id'],'teacher_assignment_id'=>$values['teacher_assignment_id'],
                'course_id'=>$values['course_id'],'lesson_kind'=>$values['lesson_kind'],
                'schedule_version_id'=>$values['schedule_version_id'],'occurrence_starts_at_utc'=>$values['occurrence_starts_at_utc'],
                'occurrence_ends_at_utc'=>$values['occurrence_ends_at_utc'],'duration_minutes'=>$values['duration_minutes'],
                'snapshot_instant_utc'=>$instant,'snapshot_boundary'=>FinanceRule::SNAPSHOT_BOUNDARY,
                'rate_id'=>$values['rate_id'],'rate_version'=>$values['rate_version'],'scope_kind'=>$values['scope_kind'],
                'compensation_basis'=>$values['compensation_basis'],'rate_amount_minor'=>$values['rate_amount_minor'],
                'rate_effective_from'=>(string)$rate->effective_from,'currency'=>$values['currency'],
                'derived_amount_minor'=>$derivedAmount,'intro_policy_key'=>$introKey,'intro_policy_version'=>$introVersion,
                'payability_disposition_at_capture'=>$disposition,'derivation_digest'=>$digestValue,
                'rule_version'=>FinanceRule::SNAPSHOT_RULE_VERSION,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->snapshots->assignPublicHandle($snapshotId);
            FinanceSupport::audit('finance_lesson_snapshots',$snapshotId,'capture',$actor,$digest,null,$now,null);
            $command['snapshot_id']=$snapshotId;$command['result_snapshot_id']=$snapshotId;
            $commandId=$this->snapshots->insertCommand($command);
            return array('snapshot_id'=>$snapshotId,'lesson_id'=>$lessonId,'rate_id'=>(int)$rate->id,'rate_version'=>(int)$rate->rate_version,'derived_amount_minor'=>$derivedAmount,'currency'=>(string)$rate->currency,'snapshot_instant_utc'=>$instant,'captured'=>true,'command_id'=>$commandId);
        });
    }
    /**
     * §15.3/§8.2: an identical capture converges on the recorded snapshot — and only after that row has
     * been re-loaded under the held root and re-proved end to end.
     *
     * The snapshot must exist, still name the command's own Lesson and Teacher, still reproduce its
     * declared derivation digest over its own recorded facts, and still name a rate row and version that
     * exist and cover its own locked snapshot instant (`snapshot_derivation_mismatch`, §8.5, is the
     * owning code for a corrupt shape). A deleted or mismatched snapshot is never reported as a
     * converged capture, and the command's exact typed result shape is re-proved first: a `capture`
     * records `result_snapshot_id` and nothing else, so a `result_correction_id` a corrupted command row
     * smuggled onto it fails closed instead of being returned as a converged secondary result.
     */
    private function replay(object $row,string $payload,string $operation):array{
        if(!hash_equals((string)$row->command_payload_digest,$payload)||(string)$row->operation!==$operation)throw new FinanceRefusalException('command_replay_conflict','A materially different replay is refused and the original record is preserved');
        FinanceSupport::assertReplayState($row,$operation);
        FinanceSupport::assertReplayResultShape($row,'finance_snapshot_commands',$operation);
        $snapshot=FinanceSupport::replayResultRow((int)$row->result_snapshot_id,fn(int $id)=>$this->snapshots->byId($id,true),array(
            'lesson_id'=>(int)$row->lesson_id,
            'teacher_id'=>(int)$row->teacher_id,
        ),'finance_lesson_snapshots');
        FinanceSnapshotIntegrity::assertDigest($snapshot);
        $rate=$this->rates->byId((int)$snapshot->rate_id,true);
        if(!$rate||(int)$rate->rate_version!==(int)$snapshot->rate_version)throw new FinanceRefusalException('snapshot_derivation_mismatch','The replayed snapshot names a rate row and version that do not exist');
        if(!FinanceRateIntegrity::covers($rate,(string)$snapshot->snapshot_instant_utc))throw new FinanceRefusalException('snapshot_derivation_mismatch','The replayed snapshot names a rate interval that does not cover its own locked instant');
        if((int)$row->snapshot_id!==(int)$row->result_snapshot_id)throw new FinanceRefusalException('command_replay_conflict','The recorded capture no longer names its own snapshot as both its selector and its typed result');
        FinanceSupport::assertReplayPayload((string)$row->command_payload_digest,array('lesson_id'=>(int)$snapshot->lesson_id,'operation'=>'capture'),'finance_lesson_snapshots');
        return array('snapshot_id'=>(int)$snapshot->id,'correction_id'=>null,'recorded'=>true,'idempotent'=>true,'command_id'=>(int)$row->id);
    }
}
