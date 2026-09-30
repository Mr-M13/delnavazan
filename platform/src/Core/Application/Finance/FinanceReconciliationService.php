<?php
namespace Delnavazan\Platform\Core\Application\Finance;

use Delnavazan\Platform\Core\Application\Finance\Integrity\{FinancePayabilityIntegrity,FinanceRateIntegrity,FinanceReconciliationIntegrity,FinanceSnapshotIntegrity,FinanceStatementIntegrity};
use Delnavazan\Platform\Core\Infrastructure\Repository\{FinancePayabilityRepository,FinanceReconciliationRepository,FinanceSnapshotRepository,FinanceStatementRepository,TeacherRateRepository};
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Reconciliation read models and runs (contract §11).
 *
 * Reconciliation is read-only, exact and finding-coded: a run appends a run record and findings, changes
 * no Finance fact, resolves no exception by itself and repairs nothing. Amounts are compared as exact
 * integers with no tolerance, and every difference carries one bounded finding code and one exact pair of
 * values.
 */
final class FinanceReconciliationService {
    private const CAPABILITY='dzn_manage_finance_statements';

    public function __construct(
        private ?FinanceReconciliationRepository $reconciliation=null,
        private ?FinanceStatementRepository $statements=null,
        private ?FinanceSnapshotRepository $snapshots=null,
        private ?FinancePayabilityRepository $evaluations=null,
        private ?TeacherRateRepository $rates=null,
        private ?TeacherStatementService $statementService=null,
        private ?FinanceFacts $facts=null
    ){
        $this->reconciliation??=new FinanceReconciliationRepository();
        $this->statements??=new FinanceStatementRepository();
        $this->snapshots??=new FinanceSnapshotRepository();
        $this->evaluations??=new FinancePayabilityRepository();
        $this->rates??=new TeacherRateRepository();
        $this->facts??=new FinanceFacts();
        $this->statementService??=new TeacherStatementService();
    }

    /** §11.2 `run`: append one run and one finding row per difference; never repair anything. */
    public function run(string $periodStartUtc,string $periodEndUtc,?int $teacherId,string $rawKey,array $input=array()):array{
        FinanceSupport::requireCapability(self::CAPABILITY);
        $actor=FinanceSupport::actor();$now=FinanceSupport::now();$digest=FinanceSupport::key($rawKey);
        $teacherId=$teacherId===null?null:FinanceSupport::positiveInt($teacherId,'Valid Teacher required');
        $payload=FinanceSupport::payload(array('start'=>$periodStartUtc,'end'=>$periodEndUtc,'teacher_id'=>$teacherId,'operation'=>'run'));
        $command=array('command_domain'=>FinanceRule::DOMAIN,'operation'=>'run','command_key_digest'=>$digest,'command_payload_digest'=>$payload,'teacher_id'=>$teacherId,'run_id'=>null,'exception_id'=>null,'result_state'=>FinanceRule::commandSuccessState('run'),'result_run_id'=>null,'result_exception_id'=>null,'reason_code'=>null,'created_at'=>$now,'created_by'=>$actor);
        // §15.1 role 3: a period-wide run has no Teacher of its own, so it takes the global policy root
        // shared; a Teacher-scoped run takes that Teacher's root and no policy root at all.
        $lock=$teacherId===null?static fn()=>FinanceSupport::lockPolicyRoot(false):static fn()=>FinanceSupport::lockTeacherRoot($teacherId,$actor);
        return FinanceSupport::runCommand($lock,'finance_reconciliation_commands',$command,function()use($periodStartUtc,$periodEndUtc,$teacherId,$actor,$now,$digest,$payload,$input,&$command){
            if($existing=$this->reconciliation->command($digest))return $this->replay($existing,$payload,'run');
            if(!FinanceRule::utc($periodStartUtc)||!FinanceRule::utc($periodEndUtc)||$periodEndUtc<=$periodStartUtc)throw new FinanceRefusalException('finance_vocabulary_member_not_allowed','A reconciliation run needs a half-open UTC period',array('teacher_id'=>$teacherId));
            $scope=$teacherId===null?$this->scopeTeachers($periodStartUtc,$periodEndUtc):array($teacherId);
            try{
                $findings=array();
                foreach($scope as $scopedTeacher)foreach($this->findingsFor((int)$scopedTeacher,$periodStartUtc,$periodEndUtc,$input) as $finding)$findings[]=$finding;
            }catch(\Throwable$e){
                if((string)$e->getMessage()!=='upstream_aggregate_invalid')throw $e;
                $runId=$this->reconciliation->insertRun(array('uid'=>Identifier::uid(),'reference_code'=>null,'period_start_utc'=>$periodStartUtc,'period_end_utc'=>$periodEndUtc,'teacher_id'=>$teacherId,'rule_version'=>FinanceRule::RECONCILIATION_RULE_VERSION,'state'=>'failed','failure_reason_code'=>'upstream_aggregate_invalid','line_count'=>0,'matched_count'=>0,'mismatch_count'=>0,'unresolved_count'=>0,'findings_digest'=>FinanceReconciliationIntegrity::findingsDigest(array()),'created_at'=>$now,'created_by'=>$actor));
                $this->reconciliation->assignPublicHandle($runId);
                FinanceSupport::audit('finance_reconciliation_runs',$runId,'run',$actor,$digest,'upstream_aggregate_invalid',$now,null);
                $command['run_id']=$runId;$command['result_state']=FinanceRule::COMMAND_FAILED_STATE;$command['result_run_id']=$runId;$command['reason_code']='upstream_aggregate_invalid';
                $commandId=$this->reconciliation->insertCommand($command);
                return array('run_id'=>$runId,'state'=>'failed','failure_reason_code'=>'upstream_aggregate_invalid','findings'=>0,'command_id'=>$commandId);
            }
            $mismatch=count($findings);$blocking=0;
            foreach($findings as $finding)if((string)$finding['severity']==='blocking')$blocking++;
            $runId=$this->reconciliation->insertRun(array('uid'=>Identifier::uid(),'reference_code'=>null,'period_start_utc'=>$periodStartUtc,'period_end_utc'=>$periodEndUtc,'teacher_id'=>$teacherId,'rule_version'=>FinanceRule::RECONCILIATION_RULE_VERSION,'state'=>'completed','failure_reason_code'=>null,'line_count'=>count($scope),'matched_count'=>max(0,count($scope)-$mismatch),'mismatch_count'=>$mismatch,'unresolved_count'=>$blocking,'findings_digest'=>FinanceReconciliationIntegrity::findingsDigest($findings),'created_at'=>$now,'created_by'=>$actor));
            $this->reconciliation->assignPublicHandle($runId);
            FinanceSupport::audit('finance_reconciliation_runs',$runId,'run',$actor,$digest,null,$now,null);
            $sequence=0;
            foreach($findings as $finding){
                $sequence++;
                $findingId=$this->reconciliation->insertFinding(array(
                    'run_id'=>$runId,'finding_sequence'=>$sequence,'finding_code'=>(string)$finding['finding_code'],'severity'=>(string)$finding['severity'],
                    'teacher_id'=>$finding['teacher_id']??null,'lesson_id'=>$finding['lesson_id']??null,'snapshot_id'=>$finding['snapshot_id']??null,
                    'evaluation_id'=>$finding['evaluation_id']??null,'statement_id'=>$finding['statement_id']??null,'line_id'=>$finding['line_id']??null,
                    'rate_id'=>$finding['rate_id']??null,'expected_digest'=>$finding['expected_digest']??null,'observed_digest'=>$finding['observed_digest']??null,
                    'expected_amount_minor'=>$finding['expected_amount_minor']??null,'observed_amount_minor'=>$finding['observed_amount_minor']??null,
                    'detected_at'=>$now,'created_at'=>$now,'created_by'=>$actor,
                ));
                FinanceSupport::audit('finance_reconciliation_findings',$findingId,'finding',$actor,$digest,(string)$finding['finding_code'],$now,null);
            }
            // §17: a blocking finding raises the one reconciliation intent, once per run.
            if($blocking>0)FinanceSupport::outboxIntent('finance_reconciliation_runs',$runId,'FINANCE_RECONCILIATION_EXCEPTION_RAISED',$now);
            $command['run_id']=$runId;$command['result_run_id']=$runId;
            $commandId=$this->reconciliation->insertCommand($command);
            return array('run_id'=>$runId,'state'=>'completed','mismatch_count'=>$mismatch,'unresolved_count'=>$blocking,'findings'=>$mismatch,'command_id'=>$commandId);
        });
    }

    /** §11.2 `findings`: the recorded findings of one run, proved against the finding vocabulary. */
    public function findings(int $runId):array{
        FinanceSupport::requireCapability('dzn_view_finance_authority');
        $run=$this->reconciliation->runById($runId);
        if(!$run)throw new \InvalidArgumentException('finance_reconciliation_run_not_found');
        $findings=$this->reconciliation->findings($runId);
        FinanceReconciliationIntegrity::assertRun($run,$findings);
        $rows=array();
        foreach($findings as $finding)$rows[]=array('finding_sequence'=>(int)$finding->finding_sequence,'finding_code'=>(string)$finding->finding_code,'severity'=>(string)$finding->severity,'lesson_id'=>$finding->lesson_id===null?null:(int)$finding->lesson_id,'expected_amount_minor'=>$finding->expected_amount_minor===null?null:(int)$finding->expected_amount_minor,'observed_amount_minor'=>$finding->observed_amount_minor===null?null:(int)$finding->observed_amount_minor);
        return array('run_id'=>$runId,'state'=>(string)$run->state,'failure_reason_code'=>$run->failure_reason_code===null?null:(string)$run->failure_reason_code,'findings'=>$rows);
    }

    /**
     * §14.1 `resolveException`: move only a `finance_exceptions` row, under the root its own target scope
     * selects — a teacher-scoped exception under that Teacher's finance root, a teacher-less exception
     * (including a policy-command refusal) under the shared global policy root.
     */
    public function resolveException(int $exceptionId,array $input,string $rawKey):array{
        FinanceSupport::requireCapability(self::CAPABILITY);
        $actor=FinanceSupport::actor();$now=FinanceSupport::now();$digest=FinanceSupport::key($rawKey);
        $hint=$this->reconciliation->exceptionById($exceptionId);
        if(!$hint)throw new FinanceRefusalException('finance_parent_not_live','The exception does not exist');
        $teacherId=$hint->teacher_id===null?null:(int)$hint->teacher_id;
        $payload=FinanceSupport::payload(array('exception_id'=>$exceptionId,'operation'=>'resolve_exception'));
        $command=array('command_domain'=>FinanceRule::DOMAIN,'operation'=>'resolve_exception','command_key_digest'=>$digest,'command_payload_digest'=>$payload,'teacher_id'=>$teacherId,'run_id'=>null,'exception_id'=>$exceptionId,'result_state'=>FinanceRule::commandSuccessState('resolve_exception'),'result_run_id'=>null,'result_exception_id'=>$exceptionId,'reason_code'=>null,'created_at'=>$now,'created_by'=>$actor);
        $lock=$teacherId===null?static fn()=>FinanceSupport::lockPolicyRoot(false):static fn()=>FinanceSupport::lockTeacherRoot($teacherId,$actor);
        return FinanceSupport::runCommand($lock,'finance_reconciliation_commands',$command,function()use($exceptionId,$input,$actor,$now,$payload,$digest,&$command){
            if($existing=$this->reconciliation->command($digest))return $this->replay($existing,$payload,'resolve_exception');
            $exception=$this->reconciliation->exceptionById($exceptionId,true);
            if(!$exception||(string)$exception->state!=='open')throw new FinanceRefusalException('finance_parent_not_live','Only an open exception may be resolved',array('teacher_id'=>$exception?(int)$exception->teacher_id:null));
            $note=(string)($input['resolution_note']??'');
            if(trim($note)==='')throw new FinanceRefusalException('finance_vocabulary_member_not_allowed','A resolution records a short bounded note');
            if($this->reconciliation->resolveException($exceptionId,$actor,$now,substr($note,0,190))!==1)throw new FinanceRefusalException('finance_parent_not_live','The resolution lost its compare-and-swap',array('teacher_id'=>$exception->teacher_id===null?null:(int)$exception->teacher_id));
            FinanceSupport::audit('finance_exceptions',$exceptionId,'resolve_exception',$actor,$digest,null,$now,null);
            $commandId=$this->reconciliation->insertCommand($command);
            return array('exception_id'=>$exceptionId,'state'=>'resolved','command_id'=>$commandId);
        });
    }

    /** The Teachers that hold any Finance fact overlapping the period; a period-wide run's scope. */
    private function scopeTeachers(string $startUtc,string $endUtc):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $ids=array();
        foreach(array($p.'finance_lesson_snapshots',$p.'finance_statements') as $table){
            $column=$table===$p.'finance_lesson_snapshots'?'snapshot_instant_utc':'period_start_utc';
            $endColumn=$table===$p.'finance_lesson_snapshots'?'snapshot_instant_utc':'period_end_utc';
            foreach((array)$wpdb->get_col($wpdb->prepare("SELECT DISTINCT teacher_id FROM {$table} WHERE {$column}<%s AND {$endColumn}>=%s",$endUtc,$startUtc)) as $id)$ids[(int)$id]=true;
        }
        $ids=array_keys($ids);sort($ids,SORT_NUMERIC);
        return $ids;
    }

    /** §11.1: every declared difference of one Teacher's period, with its exact compared values. */
    private function findingsFor(int $teacherId,string $startUtc,string $endUtc,array $input):array{
        $findings=array();
        $period=$this->statementService->periodLessons($teacherId,$startUtc,$endUtc);
        $statements=array();
        foreach($this->statements->statementsFor($teacherId) as $statement)if((string)$statement->period_start_utc<$endUtc&&(string)$statement->period_end_utc>$startUtc)$statements[]=$statement;
        // Rate timeline: overlaps and gaps are reported per Teacher, never repaired.
        try{FinanceRateIntegrity::timeline($this->rates->ratesForTeacher($teacherId));}catch(\Throwable$e){$findings[]=array('finding_code'=>'teacher_rate_timeline_overlap','severity'=>'blocking','teacher_id'=>$teacherId);}
        $scopes=array();
        foreach($this->rates->ratesForTeacher($teacherId) as $rate)$scopes[(string)$rate->scope_kind.'|'.(int)$rate->course_scope_id][]=$rate;
        foreach($scopes as $rows){
            usort($rows,static fn($a,$b)=>strcmp((string)$a->effective_from,(string)$b->effective_from));
            for($index=1;$index<count($rows);$index++)if($rows[$index-1]->effective_until!==null&&(string)$rows[$index-1]->effective_until!==(string)$rows[$index]->effective_from)$findings[]=array('finding_code'=>'teacher_rate_timeline_gap','severity'=>'blocking','teacher_id'=>$teacherId,'rate_id'=>(int)$rows[$index]->id);
        }
        foreach($period['active'] as $lessonId=>$context){
            $scope=array('teacher_id'=>$teacherId,'lesson_id'=>(int)$lessonId);
            $snapshot=$this->snapshots->byLesson((int)$lessonId);
            if(!$snapshot){$findings[]=$scope+array('finding_code'=>'snapshot_missing_for_lesson','severity'=>'blocking','observed_digest'=>FinanceIdempotency::evidence('anchor-'.(string)$context['occurrence_starts_at_utc']));continue;}
            $rate=$this->rates->byId((int)$snapshot->rate_id);
            if(!$rate||(int)$rate->rate_version!==(int)$snapshot->rate_version){$findings[]=$scope+array('finding_code'=>'rate_missing_for_snapshot','severity'=>'blocking','rate_id'=>$snapshot->rate_id===null?null:(int)$snapshot->rate_id);}
            try{FinanceSnapshotIntegrity::assertDigest($snapshot);}catch(\Throwable$e){$findings[]=$scope+array('finding_code'=>'snapshot_derivation_mismatch','severity'=>'blocking','snapshot_id'=>(int)$snapshot->id);}
            $correction=$this->snapshots->applicableCorrection((int)$snapshot->id);
            $evaluation=null;
            try{$evaluation=FinancePayabilityIntegrity::effective((int)$lessonId,$this->evaluations,$this->snapshots);}catch(\Throwable$e){$findings[]=$scope+array('finding_code'=>'payability_conflicts_with_delivery_fact','severity'=>'blocking','snapshot_id'=>(int)$snapshot->id);}
            if(!$evaluation)$findings[]=$scope+array('finding_code'=>'payability_pending','severity'=>'blocking','snapshot_id'=>(int)$snapshot->id);
            else{
                if((string)$evaluation->disposition==='pending')$findings[]=$scope+array('finding_code'=>'payability_pending','severity'=>'blocking','snapshot_id'=>(int)$snapshot->id,'evaluation_id'=>(int)$evaluation->id);
                if((string)$evaluation->disposition==='payable'&&$context['outcome']&&(string)$context['outcome']->outcome_code==='teacher_non_delivery')$findings[]=$scope+array('finding_code'=>'payability_conflicts_with_delivery_fact','severity'=>'blocking','evaluation_id'=>(int)$evaluation->id);
                if($evaluation->override_id!==null)$findings[]=$scope+array('finding_code'=>'override_applied','severity'=>'informational','evaluation_id'=>(int)$evaluation->id);
            }
            foreach($statements as $statement){
                $line=null;
                foreach($this->statements->lines((int)$statement->id) as $candidate)if((int)$candidate->lesson_id===(int)$lessonId)$line=$candidate;
                if((string)$statement->state==='issued'&&$line===null)$findings[]=array('finding_code'=>'lesson_missing_from_statement','severity'=>'blocking','teacher_id'=>$teacherId,'lesson_id'=>(int)$lessonId,'statement_id'=>(int)$statement->id);
                if($line===null)continue;
                $expected=$snapshot->derived_amount_minor===null?0:(int)$snapshot->derived_amount_minor;
                $observed=(int)$line->line_amount_minor;
                $recomputed=(string)$line->disposition==='payable'?$expected:0;
                if($recomputed!==$observed)$findings[]=array('finding_code'=>'line_amount_differs_from_recomputation','severity'=>'blocking','teacher_id'=>$teacherId,'lesson_id'=>(int)$lessonId,'statement_id'=>(int)$statement->id,'line_id'=>(int)$line->id,'expected_amount_minor'=>$recomputed,'observed_amount_minor'=>$observed);
                if($correction!==null&&(string)$statement->state==='issued')$findings[]=array('finding_code'=>'snapshot_corrected_after_issue','severity'=>'informational','teacher_id'=>$teacherId,'lesson_id'=>(int)$lessonId,'statement_id'=>(int)$statement->id,'line_id'=>(int)$line->id);
                $currentDelivery=$context['outcome']?(string)$context['outcome']->delivery_state:null;
                if((string)$statement->state==='issued'&&$currentDelivery!==($line->delivery_state===null?null:(string)$line->delivery_state))$findings[]=array('finding_code'=>'delivery_outcome_changed_after_issue','severity'=>'informational','teacher_id'=>$teacherId,'lesson_id'=>(int)$lessonId,'statement_id'=>(int)$statement->id,'line_id'=>(int)$line->id);
            }
        }
        // §10.4: a Lesson archived after issuance is reported, never silently removed from the issued set.
        foreach($period['archived'] as $lessonId)$findings[]=array('finding_code'=>'lesson_archived_after_issue','severity'=>'informational','teacher_id'=>$teacherId,'lesson_id'=>(int)$lessonId);
        // Statements: totals, derivation, currency, period overlap and a Lesson stated twice.
        $statedLesson=array();
        foreach($statements as $statement){
            try{FinanceStatementIntegrity::assertTotals((int)$statement->id,$this->statements,$this->snapshots,$this->evaluations);}
            catch(\Throwable$e){$code=(string)$e->getMessage();$findings[]=array('finding_code'=>FinanceRule::findingCode($code)?$code:'statement_derivation_mismatch','severity'=>'blocking','teacher_id'=>$teacherId,'statement_id'=>(int)$statement->id);}
            foreach($this->statements->lines((int)$statement->id) as $line){
                $lessonId=(int)$line->lesson_id;
                if(isset($statedLesson[$lessonId])&&$statedLesson[$lessonId]!==(int)$statement->id)$findings[]=array('finding_code'=>'lesson_stated_twice','severity'=>'blocking','teacher_id'=>$teacherId,'lesson_id'=>$lessonId,'statement_id'=>(int)$statement->id,'line_id'=>(int)$line->id);
                $statedLesson[$lessonId]=(int)$statement->id;
            }
            if((string)$statement->state==='issued'&&(int)$statement->pending_line_count>0)$findings[]=array('finding_code'=>'statement_totals_mismatch','severity'=>'blocking','teacher_id'=>$teacherId,'statement_id'=>(int)$statement->id);
        }
        foreach($statements as $left)foreach($statements as $right){
            if((int)$left->id>=(int)$right->id)continue;
            if(in_array((string)$left->state,array('draft','issued'),true)&&in_array((string)$right->state,array('draft','issued'),true)&&(string)$left->period_start_utc<(string)$right->period_end_utc&&(string)$right->period_start_utc<(string)$left->period_end_utc)$findings[]=array('finding_code'=>'statement_period_overlap','severity'=>'blocking','teacher_id'=>$teacherId,'statement_id'=>(int)$left->id);
        }
        // The controlled legacy comparison and the provider-evidence cross-check are visibility only.
        foreach((array)($input['legacy_comparison']??array()) as $comparison){
            $lessonId=(int)($comparison['lesson_id']??0);$legacy=FinanceSupport::amount($comparison['legacy_amount_minor']??null,'Exact integer legacy amount required');
            $snapshot=$lessonId>0?$this->snapshots->byLesson($lessonId):null;
            $platform=$snapshot===(null)?0:(int)$snapshot->derived_amount_minor;
            if($lessonId<1||$legacy!==$platform)$findings[]=array('finding_code'=>'legacy_flag_differs','severity'=>'informational','teacher_id'=>$teacherId,'lesson_id'=>$lessonId>0?$lessonId:null,'expected_amount_minor'=>$platform,'observed_amount_minor'=>$legacy);
        }
        foreach($this->unmatchedProviderEvidence($teacherId) as $evidenceId)$findings[]=array('finding_code'=>'provider_evidence_unmatched','severity'=>'informational','teacher_id'=>$teacherId,'observed_digest'=>FinanceIdempotency::evidence('provider-evidence-'.$evidenceId));
        return $findings;
    }

    /** §11.1 `providerEvidenceCrossCheck`: recorded evidence whose processing state is not `accepted`. */
    private function unmatchedProviderEvidence(int $teacherId):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        return array_map('intval',(array)$wpdb->get_col($wpdb->prepare("SELECT evidence.id FROM {$p}commercial_payment_evidence evidence WHERE evidence.processing_state<>'accepted' AND evidence.offer_id IN (SELECT offer.id FROM {$p}commercial_offers offer WHERE offer.teacher_id=%d) ORDER BY evidence.id LIMIT 50",$teacherId)));
    }
    /**
     * §15.3/§11.2/§14.1: an identical replay converges on the recorded result — and only after that row
     * has been re-loaded under the held root and re-proved.
     *
     * A replayed `run` must exist, still reproduce its recorded period and scope, still carry the state
     * its command row recorded, and still pass the §11.2 run/finding proof including the recomputed
     * findings digest. A replayed `resolve_exception` must name an exception that is still resolved and
     * still carries its resolution evidence. Both operations re-prove their exact typed result shape
     * first — a `run` records `result_run_id` alone and a `resolve_exception` records `result_exception_id`
     * alone — so a secondary typed result a corrupted command row smuggled onto the other operation fails
     * closed, and every required reference is cross-linked (the run selector names the run it recorded,
     * the exception selector names the exception a resolution recorded). A missing or mismatched result
     * fails closed (`upstream_aggregate_invalid` for a corrupt recorded shape, `command_replay_conflict`
     * for a result that no longer matches the command) and preserves the original command row.
     */
    private function replay(object $row,string $payload,string $operation):array{
        if(!hash_equals((string)$row->command_payload_digest,$payload)||(string)$row->operation!==$operation)throw new FinanceRefusalException('command_replay_conflict','A materially different replay is refused and the original record is preserved');
        FinanceSupport::assertReplayState($row,$operation);
        FinanceSupport::assertReplayResultShape($row,'finance_reconciliation_commands',$operation);
        if($operation==='run'){
            $run=FinanceSupport::replayResultRow((int)$row->result_run_id,fn(int $id)=>$this->reconciliation->runById($id,true),array(
                'teacher_id'=>$row->teacher_id===null?null:(int)$row->teacher_id,
            ),'finance_reconciliation_runs');
            if($row->run_id===null||(int)$row->run_id!==(int)$run->id)throw new FinanceRefusalException('command_replay_conflict','The recorded run selector no longer names the run the command recorded as its result');
            if($row->exception_id!==null)throw new FinanceRefusalException('command_replay_conflict','The recorded run carries an exception selector its own operation never records');
            if((string)$run->state!==(string)$row->result_state)throw new FinanceRefusalException('command_replay_conflict','The recorded reconciliation run no longer carries the outcome state its command recorded');
            $findings=$this->reconciliation->findings((int)$run->id);
            FinanceReconciliationIntegrity::assertRun($run,$findings);
            $ordered=array();
            foreach($findings as $finding)$ordered[]=array(
                'finding_code'=>(string)$finding->finding_code,'severity'=>(string)$finding->severity,
                'lesson_id'=>$finding->lesson_id===null?null:(int)$finding->lesson_id,
                'expected_amount_minor'=>$finding->expected_amount_minor===null?null:(int)$finding->expected_amount_minor,
                'observed_amount_minor'=>$finding->observed_amount_minor===null?null:(int)$finding->observed_amount_minor,
                'expected_digest'=>$finding->expected_digest===null?null:(string)$finding->expected_digest,
                'observed_digest'=>$finding->observed_digest===null?null:(string)$finding->observed_digest,
            );
            if(!hash_equals((string)$run->findings_digest,FinanceReconciliationIntegrity::findingsDigest($ordered)))throw new FinanceRefusalException('upstream_aggregate_invalid','The replayed reconciliation run no longer reproduces its recorded findings digest');
            FinanceSupport::assertReplayPayload((string)$row->command_payload_digest,array('start'=>(string)$run->period_start_utc,'end'=>(string)$run->period_end_utc,'teacher_id'=>$run->teacher_id===null?null:(int)$run->teacher_id,'operation'=>'run'),'finance_reconciliation_runs');
            return array('run_id'=>(int)$run->id,'exception_id'=>null,'recorded'=>true,'idempotent'=>true,'command_id'=>(int)$row->id);
        }
        $exception=FinanceSupport::replayResultRow((int)$row->result_exception_id,fn(int $id)=>$this->reconciliation->exceptionById($id,true),array(
            'teacher_id'=>$row->teacher_id===null?null:(int)$row->teacher_id,
        ),'finance_exceptions');
        if($row->exception_id===null||(int)$row->exception_id!==(int)$exception->id)throw new FinanceRefusalException('command_replay_conflict','The recorded exception selector no longer names the exception the command recorded as its result');
        if($row->run_id!==null)throw new FinanceRefusalException('command_replay_conflict','The recorded resolution carries a run selector its own operation never records');
        if((string)$exception->state!=='resolved'||$exception->resolved_at===null||$exception->resolved_by===null)throw new FinanceRefusalException('command_replay_conflict','A replayed exception resolution names an exception that never recorded its resolution evidence');
        FinanceSupport::assertReplayPayload((string)$row->command_payload_digest,array('exception_id'=>(int)$exception->id,'operation'=>'resolve_exception'),'finance_exceptions');
        return array('run_id'=>null,'exception_id'=>(int)$exception->id,'recorded'=>true,'idempotent'=>true,'command_id'=>(int)$row->id);
    }
}
