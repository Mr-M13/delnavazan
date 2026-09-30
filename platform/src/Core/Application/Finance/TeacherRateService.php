<?php
namespace Delnavazan\Platform\Core\Application\Finance;

use Delnavazan\Platform\Core\Application\Finance\Integrity\FinanceRateIntegrity;
use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherRateRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Effective-dated teacher rate authority (contract §7).
 *
 * A rate is scoped (`teacher` or `teacher_course`), currency-explicit and interval-bounded, and its
 * interval is half-open. There is deliberately no "current rate" column and no mutable rate value:
 * after insert exactly three physical columns may move — `effective_until`, `status` and `active_slot` —
 * through at most two conditional statements, each reporting its affected-row count. Resolution returns
 * the interval that *covers* the requested instant, never the live row, and detects withdrawn coverage
 * at the winning specificity before any scope fallback.
 */
final class TeacherRateService {
    private const CAPABILITY='dzn_manage_teacher_rates';
    private const OPEN_END='9999-12-31 23:59:59';

    public function __construct(private ?TeacherRateRepository $rates=null,private ?FinanceFacts $facts=null){
        $this->rates??=new TeacherRateRepository();
        $this->facts??=new FinanceFacts();
    }

    /** §7.1/§7.2: record one complete rate version, closing and superseding its predecessor atomically. */
    public function record(int $teacherId,array $input,string $rawKey):array{
        FinanceSupport::requireCapability(self::CAPABILITY);
        $actor=FinanceSupport::actor();$now=FinanceSupport::now();$digest=FinanceSupport::key($rawKey);
        $scopeKind=(string)($input['scope_kind']??'');
        $courseScopeId=(int)($input['course_scope_id']??0);
        $amount=FinanceSupport::amount($input['amount_minor']??null,'Exact integer amount in minor units required');
        $currency=strtoupper((string)($input['currency']??''));
        $effectiveFrom=(string)($input['effective_from']??'');
        $basis=(string)($input['compensation_basis']??'per_session');
        $payload=FinanceSupport::payload(array('teacher_id'=>$teacherId,'scope_kind'=>$scopeKind,'course_scope_id'=>$courseScopeId,'amount_minor'=>$amount,'currency'=>$currency,'effective_from'=>$effectiveFrom,'compensation_basis'=>$basis));
        $command=array('command_domain'=>FinanceRule::DOMAIN,'operation'=>'record','command_key_digest'=>$digest,'command_payload_digest'=>$payload,'teacher_id'=>$teacherId,'rate_id'=>null,'scope_kind'=>$scopeKind,'course_scope_id'=>$courseScopeId,'result_state'=>FinanceRule::commandSuccessState('record'),'result_rate_id'=>null,'reason_code'=>null,'created_at'=>$now,'created_by'=>$actor);
        $lock=static fn()=>FinanceSupport::lockTeacherRoot($teacherId,$actor);
        return FinanceSupport::runCommand($lock,'finance_teacher_rate_commands',$command,function()use($teacherId,$input,$scopeKind,$courseScopeId,$amount,$currency,$effectiveFrom,$basis,$actor,$now,$payload,$digest,&$command){
            $this->facts->teacher($teacherId,true);
            if(!FinanceRule::member($scopeKind,FinanceRule::RATE_SCOPE_KINDS))throw new FinanceRefusalException('finance_vocabulary_member_not_allowed','A rate scope is one of the declared kinds');
            if(!FinanceRule::scopeValid($scopeKind,$courseScopeId))throw new FinanceRefusalException('rate_scope_violation','A teacher-scoped rate carries the declared zero sentinel and a course-scoped rate a real Course',array('teacher_id'=>$teacherId));
            if($scopeKind==='teacher_course')$this->facts->course($courseScopeId);
            if(!FinanceRule::member($basis,FinanceRule::COMPENSATION_BASES))throw new FinanceRefusalException('finance_vocabulary_member_not_allowed','Only the declared compensation basis may be recorded',array('teacher_id'=>$teacherId));
            if($effectiveFrom===''||!FinanceRule::utc($effectiveFrom))throw new FinanceRefusalException('finance_policy_effective_from_missing','A rate always records its effective instant',array('teacher_id'=>$teacherId));
            if(preg_match('/^[A-Z]{3}$/D',$currency)!==1)throw new FinanceRefusalException('finance_amount_not_exact','An explicit ISO-4217 currency is required',array('teacher_id'=>$teacherId));
            if($existing=$this->rates->command($digest))return $this->replay($existing,$payload,'record');
            $this->assertIntervalFree($teacherId,$scopeKind,$courseScopeId,$effectiveFrom);
            $consumed=$this->rates->snapshotInstants($teacherId);
            if($consumed!==array()&&max($consumed)>=$effectiveFrom)throw new FinanceRefusalException('rate_effective_from_precedes_snapshot','A rate is never inserted into an interval a committed snapshot has already resolved',array('teacher_id'=>$teacherId));
            $proof=FinanceSupport::evidence($input);
            $predecessor=$this->rates->liveForScope($teacherId,$scopeKind,$courseScopeId,true);
            $version=$this->rates->maxVersion($teacherId,$scopeKind,$courseScopeId)+1;
            // §7.2 rules 1–2: the predecessor relinquishes the scope's live slot *before* the successor
            // claims it, because the declared `UNIQUE teacher_scope_slot` admits exactly one live row per
            // scope. The closure and the status move are each a conditional statement whose affected-row
            // count is checked, and both run inside this command's single transaction, so a failure after
            // the predecessor moved rolls the whole command back instead of leaving a closed gap.
            $moved=null;
            if($predecessor){
                $moved=(int)$predecessor->id;
                if($this->rates->closeInterval($moved,$effectiveFrom,$now,$actor)!==1)throw new FinanceRefusalException('teacher_rate_state_not_resolvable','The successor could not close its predecessor\'s open interval',array('teacher_id'=>$teacherId,'rate_id'=>$moved));
                if($this->rates->moveStatus($moved,'active','superseded',$now,$actor)!==1)throw new FinanceRefusalException('teacher_rate_state_not_resolvable','The supersession lost its compare-and-swap against the predecessor\'s live status',array('teacher_id'=>$teacherId,'rate_id'=>$moved));
                $sequence=$this->rates->eventCount($moved)+1;
                $this->rates->insertEvent(array('rate_id'=>$moved,'event_sequence'=>$sequence,'event_type'=>'closed','from_status'=>'active','to_status'=>'active','effective_until'=>$effectiveFrom,'reason_code'=>'operator_decision','evidence_channel'=>$proof['channel'],'evidence_reference_digest'=>$proof['digest'],'evidence_at'=>$proof['at'],'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor));
                $this->rates->insertEvent(array('rate_id'=>$moved,'event_sequence'=>$sequence+1,'event_type'=>'superseded','from_status'=>'active','to_status'=>'superseded','effective_until'=>$effectiveFrom,'reason_code'=>'operator_decision','evidence_channel'=>$proof['channel'],'evidence_reference_digest'=>$proof['digest'],'evidence_at'=>$proof['at'],'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor));
                FinanceSupport::audit('finance_teacher_rates',$moved,'supersede',$actor,$digest,null,$now,null);
            }
            $rateId=$this->rates->insertRate(array(
                'uid'=>Identifier::uid(),'reference_code'=>null,'teacher_id'=>$teacherId,'scope_kind'=>$scopeKind,
                'course_scope_id'=>$courseScopeId,'compensation_basis'=>$basis,'amount_minor'=>$amount,'currency'=>$currency,
                'effective_from'=>$effectiveFrom,'effective_until'=>null,'status'=>'active','reason_code'=>null,
                'evidence_channel'=>$proof['channel'],'evidence_reference_digest'=>$proof['digest'],'evidence_at'=>$proof['at'],
                'rate_version'=>$version,'active_slot'=>1,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,'updated_at'=>$now,
            ));
            $this->rates->insertEvent(array('rate_id'=>$rateId,'event_sequence'=>1,'event_type'=>'recorded','from_status'=>null,'to_status'=>'active','effective_until'=>null,'reason_code'=>'operator_decision','evidence_channel'=>$proof['channel'],'evidence_reference_digest'=>$proof['digest'],'evidence_at'=>$proof['at'],'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor));
            FinanceSupport::audit('finance_teacher_rates',$rateId,'record',$actor,$digest,null,$now,null);
            $command['rate_id']=$moved;$command['result_rate_id']=$rateId;
            $commandId=$this->rates->insertCommand($command);
            return array('rate_id'=>$rateId,'teacher_id'=>$teacherId,'rate_version'=>$version,'scope_kind'=>$scopeKind,'course_scope_id'=>$courseScopeId,'amount_minor'=>$amount,'currency'=>$currency,'effective_from'=>$effectiveFrom,'superseded_rate_id'=>$moved,'recorded'=>true,'command_id'=>$commandId);
        });
    }

    /** §7.2 rule 1: write `effective_until` at most once, from `NULL` to the declared instant. */
    public function close(int $rateId,string $effectiveUntil,array $input,string $rawKey):array{
        FinanceSupport::requireCapability(self::CAPABILITY);
        $actor=FinanceSupport::actor();$now=FinanceSupport::now();$digest=FinanceSupport::key($rawKey);
        $hint=$this->rates->byId($rateId);
        if(!$hint)throw new FinanceRefusalException('finance_parent_not_live','The rate row does not exist');
        $teacherId=(int)$hint->teacher_id;
        $payload=FinanceSupport::payload(array('rate_id'=>$rateId,'effective_until'=>$effectiveUntil,'operation'=>'close'));
        $command=array('command_domain'=>FinanceRule::DOMAIN,'operation'=>'close','command_key_digest'=>$digest,'command_payload_digest'=>$payload,'teacher_id'=>$teacherId,'rate_id'=>$rateId,'scope_kind'=>(string)$hint->scope_kind,'course_scope_id'=>(int)$hint->course_scope_id,'result_state'=>'closed','result_rate_id'=>$rateId,'reason_code'=>null,'created_at'=>$now,'created_by'=>$actor);
        $lock=static fn()=>FinanceSupport::lockTeacherRoot($teacherId,$actor);
        return FinanceSupport::runCommand($lock,'finance_teacher_rate_commands',$command,function()use($rateId,$effectiveUntil,$actor,$now,$payload,$digest,&$command){
            if($existing=$this->rates->command($digest))return $this->replay($existing,$payload,'close');
            $row=$this->rates->byId($rateId,true);
            if(!$row||$row->effective_until!==null)throw new FinanceRefusalException('teacher_rate_state_not_resolvable','An interval is only ever closed once');
            if(!FinanceRule::utc($effectiveUntil)||$effectiveUntil<=(string)$row->effective_from)throw new FinanceRefusalException('teacher_rate_timeline_overlap','A closure must be strictly later than the interval start');
            $this->assertIntervalFree((int)$row->teacher_id,(string)$row->scope_kind,(int)$row->course_scope_id,$effectiveUntil,$rateId);
            $proof=FinanceSupport::evidence($input);
            if($this->rates->closeInterval($rateId,$effectiveUntil,$now,$actor)!==1)throw new FinanceRefusalException('teacher_rate_state_not_resolvable','The closure lost its compare-and-swap');
            $this->rates->insertEvent(array('rate_id'=>$rateId,'event_sequence'=>$this->rates->eventCount($rateId)+1,'event_type'=>'closed','from_status'=>(string)$row->status,'to_status'=>(string)$row->status,'effective_until'=>$effectiveUntil,'reason_code'=>'operator_decision','evidence_channel'=>$proof['channel'],'evidence_reference_digest'=>$proof['digest'],'evidence_at'=>$proof['at'],'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor));
            FinanceSupport::audit('finance_teacher_rates',$rateId,'close',$actor,$digest,null,$now,null);
            $commandId=$this->rates->insertCommand($command);
            return array('rate_id'=>$rateId,'effective_until'=>$effectiveUntil,'closed'=>true,'command_id'=>$commandId);
        });
    }

    /** §7.4: retract a rate — refused while any snapshot references it; `withdrawn` is terminal. */
    public function withdraw(int $rateId,array $input,string $rawKey):array{
        FinanceSupport::requireCapability(self::CAPABILITY);
        $actor=FinanceSupport::actor();$now=FinanceSupport::now();$digest=FinanceSupport::key($rawKey);
        $hint=$this->rates->byId($rateId);
        if(!$hint)throw new FinanceRefusalException('finance_parent_not_live','The rate row does not exist');
        $teacherId=(int)$hint->teacher_id;
        $reason=FinanceSupport::operatorReason($input);
        $payload=FinanceSupport::payload(array('rate_id'=>$rateId,'reason'=>$reason,'operation'=>'withdraw'));
        $command=array('command_domain'=>FinanceRule::DOMAIN,'operation'=>'withdraw','command_key_digest'=>$digest,'command_payload_digest'=>$payload,'teacher_id'=>$teacherId,'rate_id'=>$rateId,'scope_kind'=>(string)$hint->scope_kind,'course_scope_id'=>(int)$hint->course_scope_id,'result_state'=>'withdrawn','result_rate_id'=>$rateId,'reason_code'=>$reason,'created_at'=>$now,'created_by'=>$actor);
        $lock=static fn()=>FinanceSupport::lockTeacherRoot($teacherId,$actor);
        return FinanceSupport::runCommand($lock,'finance_teacher_rate_commands',$command,function()use($rateId,$actor,$now,$payload,$digest,&$command,$reason){
            if($existing=$this->rates->command($digest))return $this->replay($existing,$payload,'withdraw');
            $row=$this->rates->byId($rateId,true);
            if(!$row||(string)$row->status==='withdrawn')throw new FinanceRefusalException('teacher_rate_state_not_resolvable','Only an active or superseded row may be withdrawn');
            if($this->rates->referencedBySnapshot($rateId)>0)throw new FinanceRefusalException('rate_referenced_by_snapshot','A rate referenced by a snapshot can never be withdrawn or rewritten',array('teacher_id'=>(int)$row->teacher_id));
            $proof=FinanceSupport::evidence($input);
            if((int)$row->active_slot!==1&&$row->effective_until===null)throw new FinanceRefusalException('teacher_rate_state_not_resolvable','A withdrawn row always carries a closed interval');
            // §7.2 rule 1: only a still-open interval is closed here, and only then must the withdrawal
            // instant be free of another interval — a row a successor already closed is rewritten by
            // nothing, so its own past instant is never re-judged against a later successor's interval.
            if($row->effective_until===null){
                $this->assertIntervalFree((int)$row->teacher_id,(string)$row->scope_kind,(int)$row->course_scope_id,$now,$rateId);
                if($this->rates->closeInterval($rateId,$now,$now,$actor)!==1)throw new FinanceRefusalException('teacher_rate_state_not_resolvable','The retraction closure lost its compare-and-swap');
            }
            $from=(string)$row->status;
            if($this->rates->moveStatus($rateId,$from,'withdrawn',$now,$actor)!==1)throw new FinanceRefusalException('teacher_rate_state_not_resolvable','The withdrawal lost its compare-and-swap');
            $this->rates->insertEvent(array('rate_id'=>$rateId,'event_sequence'=>$this->rates->eventCount($rateId)+1,'event_type'=>'withdrawn','from_status'=>$from,'to_status'=>'withdrawn','effective_until'=>$row->effective_until===null?$now:(string)$row->effective_until,'reason_code'=>$reason,'evidence_channel'=>$proof['channel'],'evidence_reference_digest'=>$proof['digest'],'evidence_at'=>$proof['at'],'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor));
            FinanceSupport::audit('finance_teacher_rates',$rateId,'withdraw',$actor,$digest,$reason,$now,null);
            $commandId=$this->rates->insertCommand($command);
            return array('rate_id'=>$rateId,'withdrawn'=>true,'command_id'=>$commandId);
        });
    }

    /**
     * §7.3: the single ordered resolution algorithm.
     *
     * It builds the coverage set from interval containment alone, fixes the winning specificity from that
     * set, detects withdrawn coverage at that specificity *before* any scope fallback, and returns exactly
     * one eligible interval. Coverage outcomes are returned, never defaulted.
     *
     * @return array{resolved:bool,rate:?object,reason:?string,gap:bool}
     */
    public function resolveFor(int $teacherId,int $courseId,string $instantUtc,bool $lock=false):array{
        $this->facts->teacher($teacherId,$lock);
        $this->facts->course($courseId);
        if(!FinanceRule::utc($instantUtc))throw new \InvalidArgumentException('Valid UTC instant required');
        $rows=$this->rates->covering($teacherId,$courseId,$instantUtc,$lock);
        foreach($rows as $row)FinanceRateIntegrity::validate($row);
        $course=array_values(array_filter($rows,static fn($row)=>(string)$row->scope_kind==='teacher_course'&&(int)$row->course_scope_id===$courseId));
        $teacher=array_values(array_filter($rows,static fn($row)=>(string)$row->scope_kind==='teacher'));
        $winning=$course!==array()?$course:$teacher;
        if($winning===array())return array('resolved'=>false,'rate'=>null,'reason'=>'rate_missing_for_lesson','gap'=>true);
        $withdrawn=array_values(array_filter($winning,static fn($row)=>(string)$row->status==='withdrawn'));
        if(count($withdrawn)===count($winning))return array('resolved'=>false,'rate'=>null,'reason'=>'rate_missing_for_lesson','gap'=>true);
        $eligible=array_values(array_filter($winning,static fn($row)=>in_array((string)$row->status,array('active','superseded'),true)));
        if(count($eligible)>1)throw new FinanceRefusalException('ambiguous_teacher_rate','Two eligible intervals cover one instant at the winning specificity',array('teacher_id'=>$teacherId));
        if($eligible===array())return array('resolved'=>false,'rate'=>null,'reason'=>'rate_missing_for_lesson','gap'=>true);
        return array('resolved'=>true,'rate'=>$eligible[0],'reason'=>null,'gap'=>false);
    }

    /** §11.1 `teacherRateCoverage`: the recorded timeline with its overlaps and gaps, per scope. */
    public function timeline(int $teacherId):array{
        FinanceSupport::requireCapability('dzn_view_finance_authority');
        $rates=$this->rates->ratesForTeacher($teacherId);
        FinanceRateIntegrity::timeline($rates);
        $rows=array();
        foreach($rates as $rate)$rows[]=array(
            'rate_id'=>(int)$rate->id,'rate_version'=>(int)$rate->rate_version,'scope_kind'=>(string)$rate->scope_kind,
            'course_scope_id'=>(int)$rate->course_scope_id,'compensation_basis'=>(string)$rate->compensation_basis,
            'amount_minor'=>(int)$rate->amount_minor,'currency'=>(string)$rate->currency,
            'effective_from'=>(string)$rate->effective_from,'effective_until'=>$rate->effective_until===null?null:(string)$rate->effective_until,
            'status'=>(string)$rate->status,'active_slot'=>$rate->active_slot===null?null:(int)$rate->active_slot,
        );
        return array('teacher_id'=>$teacherId,'rates'=>$rows);
    }
    /**
     * §15.3: an identical replay converges on the recorded rate — and only after that rate row has been
     * re-loaded under the held Teacher root and re-proved.
     *
     * The row must exist, still carry the command's own Teacher and scope selectors, pass the §7
     * integrity proof of the owning section, and still reproduce the payload the command recorded: the
     * recorded version's own facts for `record`, the exact written closure for `close`, and the exact
     * recorded retraction instant and reason for `withdraw`. Anything else — an absent row, a moved
     * amount, a re-opened interval, a rate that is not withdrawn — fails closed and preserves the
     * original command row.
     */
    private function replay(object $row,string $payload,string $operation):array{
        if(!hash_equals((string)$row->command_payload_digest,$payload)||(string)$row->operation!==$operation)throw new FinanceRefusalException('command_replay_conflict','A materially different replay is refused and the original record is preserved');
        FinanceSupport::assertReplayState($row,$operation);
        FinanceSupport::assertReplayResultShape($row,'finance_teacher_rate_commands',$operation);
        $rate=FinanceSupport::replayResultRow((int)$row->result_rate_id,fn(int $id)=>$this->rates->byId($id,true),array(
            'teacher_id'=>(int)$row->teacher_id,
            'scope_kind'=>(string)$row->scope_kind,
            'course_scope_id'=>(int)$row->course_scope_id,
        ),'finance_teacher_rates');
        FinanceRateIntegrity::validate($rate);
        if($operation==='record')FinanceSupport::assertReplayPayload((string)$row->command_payload_digest,array(
            'teacher_id'=>(int)$rate->teacher_id,'scope_kind'=>(string)$rate->scope_kind,'course_scope_id'=>(int)$rate->course_scope_id,
            'amount_minor'=>(int)$rate->amount_minor,'currency'=>(string)$rate->currency,'effective_from'=>(string)$rate->effective_from,
            'compensation_basis'=>(string)$rate->compensation_basis,
        ),'finance_teacher_rates');
        if($operation==='close'){
            if($rate->effective_until===null)throw new FinanceRefusalException('command_replay_conflict','A replayed rate closure names an interval that is still open');
            FinanceSupport::assertReplayPayload((string)$row->command_payload_digest,array('rate_id'=>(int)$rate->id,'effective_until'=>(string)$rate->effective_until,'operation'=>'close'),'finance_teacher_rates');
        }
        if($operation==='withdraw'){
            if((string)$rate->status!=='withdrawn')throw new FinanceRefusalException('command_replay_conflict','A replayed rate withdrawal names a rate that is not withdrawn');
            FinanceSupport::assertReplayPayload((string)$row->command_payload_digest,array('rate_id'=>(int)$rate->id,'reason'=>(string)$row->reason_code,'operation'=>'withdraw'),'finance_teacher_rates');
        }
        return array('rate_id'=>(int)$rate->id,'result_state'=>(string)$row->result_state,'recorded'=>true,'idempotent'=>true,'command_id'=>(int)$row->id);
    }
    /** §7.1: no two intervals of one scope may overlap; the record-time proof runs before any write. */
    private function assertIntervalFree(int $teacherId,string $scopeKind,int $courseScopeId,string $effectiveFrom,int $ignoreRateId=0):void{
        foreach($this->rates->ratesForTeacher($teacherId) as $row){
            if((string)$row->scope_kind!==$scopeKind||(int)$row->course_scope_id!==$courseScopeId)continue;
            if((int)$row->id===$ignoreRateId)continue;
            $end=$row->effective_until===null?self::OPEN_END:(string)$row->effective_until;
            if($row->effective_until===null){
                if((int)$row->active_slot===1&&$effectiveFrom>(string)$row->effective_from)continue;
                throw new FinanceRefusalException('teacher_rate_timeline_overlap','Two intervals of one rate scope may not overlap',array('teacher_id'=>$teacherId));
            }
            if($effectiveFrom<$end)throw new FinanceRefusalException('teacher_rate_timeline_overlap','Two intervals of one rate scope may not overlap',array('teacher_id'=>$teacherId));
        }
    }
}
