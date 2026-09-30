<?php
namespace Delnavazan\Platform\Core\Application\Finance;

use Delnavazan\Platform\Core\Application\Finance\Integrity\{FinancePayabilityIntegrity,FinanceSnapshotIntegrity};
use Delnavazan\Platform\Core\Infrastructure\Repository\{FinanceSnapshotRepository,FinanceStatementRepository,TeacherRateRepository};

/**
 * Audited corrections (contract §12).
 *
 * A correction is the only way an already-recorded Finance fact may acquire a different meaning. Every
 * correction is append-only, names its exact target row and that row's digest, and leaves the target
 * untouched: the snapshot keeps its row, the derivation keeps its evaluation and the issued statement
 * keeps its lines. Nothing here is batch-applied — each correction is one capability-gated, evidenced
 * administrator command.
 */
final class FinanceCorrectionService {
    private const CAPABILITY_STATEMENT='dzn_manage_finance_statements';

    public function __construct(
        private ?FinanceSnapshotRepository $snapshots=null,
        private ?TeacherRateRepository $rates=null,
        private ?FinanceStatementRepository $statements=null,
        private ?LessonPayabilityService $payability=null,
        private ?TeacherStatementService $statementService=null
    ){
        $this->snapshots??=new FinanceSnapshotRepository();
        $this->rates??=new TeacherRateRepository();
        $this->statements??=new FinanceStatementRepository();
        $this->payability??=new LessonPayabilityService();
        $this->statementService??=new TeacherStatementService();
    }

    /**
     * §12.2 `snapshot_correction`: append a complete, evidenced correction and make it the effective
     * snapshot for every later derivation, without editing the snapshot it corrects.
     */
    public function correctSnapshot(int $lessonId,array $input,string $rawKey):array{
        FinanceSupport::requireCapability(self::CAPABILITY_STATEMENT);
        $actor=FinanceSupport::actor();$now=FinanceSupport::now();$digest=FinanceSupport::key($rawKey);
        $snapshot=$this->snapshots->byLesson($lessonId);
        if(!$snapshot)throw new FinanceRefusalException('snapshot_missing_for_lesson','A correction names the exact prior snapshot it corrects');
        $teacherId=(int)$snapshot->teacher_id;
        $reason=FinanceSupport::operatorReason($input);
        $payload=FinanceSupport::payload(self::correctionFacts(
            $lessonId,(int)$snapshot->id,
            self::canonicalInt($input['corrected_rate_id']??0),self::canonicalInt($input['corrected_rate_version']??0),
            self::canonicalInt($input['corrected_rate_amount_minor']??null),
            self::canonicalInt($input['corrected_derived_amount_minor']??null),self::canonicalCurrency($input['corrected_currency']??''),
            $reason
        ));
        $command=array('command_domain'=>FinanceRule::DOMAIN,'operation'=>'correct_snapshot','command_key_digest'=>$digest,'command_payload_digest'=>$payload,'teacher_id'=>$teacherId,'lesson_id'=>$lessonId,'snapshot_id'=>(int)$snapshot->id,'correction_id'=>null,'result_state'=>FinanceRule::commandSuccessState('correct_snapshot'),'result_snapshot_id'=>(int)$snapshot->id,'result_correction_id'=>null,'reason_code'=>$reason,'created_at'=>$now,'created_by'=>$actor);
        $lock=static fn()=>FinanceSupport::lockTeacherRoot($teacherId,$actor);
        return FinanceSupport::runCommand($lock,'finance_snapshot_commands',$command,function()use($lessonId,$snapshot,$input,$actor,$now,$digest,$payload,$reason,&$command){
            if($existing=$this->snapshots->command($digest))return $this->replay($existing,$payload,'correct_snapshot');
            $scope=array('teacher_id'=>(int)$snapshot->teacher_id,'lesson_id'=>$lessonId,'snapshot_id'=>(int)$snapshot->id);
            $rateId=(int)($input['corrected_rate_id']??0);
            $rateVersion=(int)($input['corrected_rate_version']??0);
            $rateAmount=array_key_exists('corrected_rate_amount_minor',$input)?FinanceSupport::amount($input['corrected_rate_amount_minor'],'Exact integer amount required'):-1;
            $currency=array_key_exists('corrected_currency',$input)?strtoupper((string)$input['corrected_currency']):'';
            $derivedAmount=array_key_exists('corrected_derived_amount_minor',$input)?FinanceSupport::amount($input['corrected_derived_amount_minor'],'Exact integer amount required'):-1;
            if($rateId<1||$rateVersion<1||$rateAmount<0||preg_match('/^[A-Z]{3}$/D',$currency)!==1||$derivedAmount<0)throw new FinanceRefusalException('snapshot_correction_incomplete','A correction names its rate row and version, the corrected amount, the currency and the derived amount',$scope);
            $rate=$this->rates->byId($rateId);
            if(!$rate||(int)$rate->rate_version!==$rateVersion)throw new FinanceRefusalException('snapshot_correction_incomplete','The corrected rate row and version must exist',$scope);
            // §12.2: the restated intro pair is the correction's own immutable restatement of the snapshot's.
            $introKey=$input['intro_policy_key']??null;$introVersion=array_key_exists('intro_policy_version',$input)&&$input['intro_policy_version']!==null?(int)$input['intro_policy_version']:null;
            if((string)$snapshot->lesson_kind==='introductory'){if($introKey!=='INTRO_PAYABILITY_POLICY'||$introVersion===null)throw new FinanceRefusalException('snapshot_correction_incomplete','An introductory correction restates both halves of the intro policy pair',$scope);}
            elseif($introKey!==null||$introVersion!==null)throw new FinanceRefusalException('snapshot_correction_incomplete','A non-introductory correction records no intro policy pair',$scope);
            FinanceSnapshotIntegrity::assertDigest($snapshot);
            $proof=FinanceSupport::evidence($input);
            $sequence=$this->snapshots->nextCorrectionSequence((int)$snapshot->id);
            $applicable=$this->snapshots->applicableCorrection((int)$snapshot->id,true);
            if($applicable&&!hash_equals((string)$applicable->derivation_digest,(string)$input['prior_correction_digest']??'')&&array_key_exists('prior_correction_digest',$input))throw new FinanceRefusalException('command_replay_conflict','The named prior correction does not match the applicable one',$scope);
            $values=array('snapshot_id'=>(int)$snapshot->id,'lesson_id'=>$lessonId,'corrected_rate_id'=>$rateId,'corrected_rate_version'=>$rateVersion,'corrected_rate_amount_minor'=>$rateAmount,'corrected_currency'=>$currency,'corrected_derived_amount_minor'=>$derivedAmount,'intro_policy_key'=>$introKey,'intro_policy_version'=>$introVersion,'prior_snapshot_digest'=>(string)$snapshot->derivation_digest);
            // §15.4: the appended correction carries an empty slot until the applicable one has released
            // the snapshot's single `UNIQUE snapshot_applicable` slot; it claims it afterwards, under the
            // same conditional-statement discipline, so a snapshot never carries two applicable rows.
            $correctionId=$this->snapshots->insertCorrection(array(
                'snapshot_id'=>(int)$snapshot->id,'lesson_id'=>$lessonId,'correction_sequence'=>$sequence,'applicable_slot'=>null,
                'corrected_rate_id'=>$rateId,'corrected_rate_version'=>$rateVersion,'corrected_rate_amount_minor'=>$rateAmount,
                'corrected_currency'=>$currency,'corrected_derived_amount_minor'=>$derivedAmount,
                'intro_policy_key'=>$introKey,'intro_policy_version'=>$introVersion,
                'prior_snapshot_digest'=>(string)$snapshot->derivation_digest,'derivation_digest'=>FinanceSupport::digest(FinanceRule::CORRECTION_DIGEST_FIELDS,$values),
                'reason_code'=>$reason,'note'=>($input['note']??null)===null?null:substr((string)$input['note'],0,190),
                'evidence_channel'=>$proof['channel'],'evidence_reference_digest'=>$proof['digest'],'evidence_at'=>$proof['at'],
                'superseded_at'=>null,'superseded_by_correction_id'=>null,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            if($applicable&&$this->snapshots->supersedeCorrection((int)$applicable->id,$correctionId,$now)!==1)throw new FinanceRefusalException('snapshot_correction_incomplete','The previous applicable correction was already replaced',$scope);
            if($this->snapshots->claimApplicable($correctionId)!==1)throw new FinanceRefusalException('snapshot_correction_incomplete','The appended correction could not claim the snapshot\'s one applicable slot',$scope);
            FinanceSupport::audit('finance_snapshot_corrections',$correctionId,'correct_snapshot',$actor,$digest,$reason,$now,null);
            if($applicable)FinanceSupport::audit('finance_snapshot_corrections',(int)$applicable->id,'supersede',$actor,$digest,null,$now,null);
            // §12.2: an asserted rate that does not cover the snapshot instant is admitted only with explicit
            // historical-rate evidence and is reported as an informational difference rather than hidden.
            if((string)$rate->effective_from>(string)$snapshot->snapshot_instant_utc||($rate->effective_until!==null&&(string)$rate->effective_until<=(string)$snapshot->snapshot_instant_utc))FinanceSupport::exception('snapshot_derivation_mismatch','The corrected rate interval does not contain the snapshot instant; historical-rate evidence is recorded',$scope,$actor,$now,null);
            // §12.2: a correction that would change the totals of an issued statement must be paired with its
            // supersession, so a difference can never exist between an issued statement and its authority.
            foreach($this->statements->statementsFor((int)$snapshot->teacher_id) as $statement){
                if((string)$statement->state!=='issued')continue;
                foreach($this->statements->lines((int)$statement->id) as $line)if((int)$line->lesson_id===$lessonId)throw new FinanceRefusalException('statement_supersession_required','A correction that would change an issued statement must be paired with its supersession',array('teacher_id'=>(int)$snapshot->teacher_id,'statement_id'=>(int)$statement->id,'lesson_id'=>$lessonId));
            }
            $command['correction_id']=$correctionId;$command['result_correction_id']=$correctionId;
            $commandId=$this->snapshots->insertCommand($command);
            return array('correction_id'=>$correctionId,'snapshot_id'=>(int)$snapshot->id,'lesson_id'=>$lessonId,'corrected_derived_amount_minor'=>$derivedAmount,'command_id'=>$commandId);
        });
    }

    /** §12.1 `payability_override`: the audited override of §9.3, with the payability capability. */
    public function overridePayability(int $lessonId,string $disposition,string $reasonCode,array $input,string $rawKey):array{
        return $this->payability->override($lessonId,$disposition,$reasonCode,$input,$rawKey);
    }
    /** §12.1 `statement_supersession`: the append-only successor version of §10.6. */
    public function supersedeStatement(int $statementId,array $input,string $rawKey):array{
        return $this->statementService->supersede($statementId,$input,$rawKey);
    }
    /** The effective payability of one Lesson, read through the chain validator. */
    public function effectivePayability(int $lessonId):?object{
        return FinancePayabilityIntegrity::effective($lessonId);
    }
    /**
     * §15.3/§12.2: an identical correction replay converges on the recorded correction — and only after
     * that append-only row has been re-loaded under the held Teacher root and re-proved.
     *
     * The correction must exist, still name the command's own Lesson and prior snapshot, still reproduce
     * its declared derivation digest over its own recorded restatement, still name a corrected rate row
     * and version that exist, and still carry the command's recorded reason. Its base snapshot must still
     * reproduce the digest the correction named as its prior snapshot, and — the correction replay's own
     * missing link — the re-loaded correction must still reproduce the *exact command payload* the command
     * recorded, reconstituted from the correction row's own canonical facts, and must still be the
     * snapshot the command recorded as its typed result. A corrupted `result_correction_id` that names a
     * different, self-consistent correction of the same Lesson and snapshot therefore fails closed
     * instead of converging on the substituted row. Anything else fails closed and preserves the original
     * command row.
     */
    private function replay(object $row,string $payload,string $operation):array{
        if(!hash_equals((string)$row->command_payload_digest,$payload)||(string)$row->operation!==$operation)throw new FinanceRefusalException('command_replay_conflict','A materially different replay is refused and the original record is preserved');
        FinanceSupport::assertReplayState($row,$operation);
        FinanceSupport::assertReplayResultShape($row,'finance_snapshot_commands',$operation);
        $correction=FinanceSupport::replayResultRow((int)$row->result_correction_id,fn(int $id)=>$this->snapshots->correctionById($id,true),array(
            'snapshot_id'=>(int)$row->snapshot_id,
            'lesson_id'=>(int)$row->lesson_id,
        ),'finance_snapshot_corrections');
        if((string)$correction->reason_code!==(string)$row->reason_code)throw new FinanceRefusalException('command_replay_conflict','The replayed correction no longer carries the reason the command recorded');
        // §15.3: the recorded command payload is a pure function of the correction row's own recorded
        // facts, so the re-loaded correction must still reproduce it exactly.
        FinanceSupport::assertReplayPayload((string)$row->command_payload_digest,self::correctionFacts(
            (int)$correction->lesson_id,(int)$correction->snapshot_id,
            (int)$correction->corrected_rate_id,(int)$correction->corrected_rate_version,
            (int)$correction->corrected_rate_amount_minor,
            (int)$correction->corrected_derived_amount_minor,(string)$correction->corrected_currency,
            (string)$correction->reason_code
        ),'finance_snapshot_corrections');
        if((int)$row->result_snapshot_id!==(int)$correction->snapshot_id)throw new FinanceRefusalException('command_replay_conflict','The recorded correction result no longer names the snapshot the command recorded as its result');
        if($row->correction_id!==null&&(int)$row->correction_id!==(int)$correction->id)throw new FinanceRefusalException('command_replay_conflict','The recorded correction selector no longer names the correction the command recorded as its result');
        $values=array(
            'snapshot_id'=>(int)$correction->snapshot_id,'lesson_id'=>(int)$correction->lesson_id,
            'corrected_rate_id'=>(int)$correction->corrected_rate_id,'corrected_rate_version'=>(int)$correction->corrected_rate_version,
            'corrected_rate_amount_minor'=>(int)$correction->corrected_rate_amount_minor,'corrected_currency'=>(string)$correction->corrected_currency,
            'corrected_derived_amount_minor'=>(int)$correction->corrected_derived_amount_minor,
            'intro_policy_key'=>$correction->intro_policy_key,'intro_policy_version'=>$correction->intro_policy_version===null?null:(int)$correction->intro_policy_version,
            'prior_snapshot_digest'=>(string)$correction->prior_snapshot_digest,
        );
        if(!hash_equals((string)$correction->derivation_digest,FinanceSupport::digest(FinanceRule::CORRECTION_DIGEST_FIELDS,$values)))throw new FinanceRefusalException('snapshot_derivation_mismatch','The replayed correction no longer reproduces its recorded derivation digest');
        $correctedRate=$this->rates->byId((int)$correction->corrected_rate_id,true);
        if(!$correctedRate||(int)$correctedRate->rate_version!==(int)$correction->corrected_rate_version)throw new FinanceRefusalException('snapshot_correction_incomplete','The replayed correction names a corrected rate row and version that do not exist');
        $snapshot=FinanceSupport::replayResultRow((int)$correction->snapshot_id,fn(int $id)=>$this->snapshots->byId($id,true),array('lesson_id'=>(int)$correction->lesson_id),'finance_lesson_snapshots');
        FinanceSnapshotIntegrity::assertDigest($snapshot);
        if(!hash_equals((string)$snapshot->derivation_digest,(string)$correction->prior_snapshot_digest))throw new FinanceRefusalException('snapshot_derivation_mismatch','The replayed correction no longer names the digest of the snapshot it corrected');
        return array('correction_id'=>(int)$correction->id,'snapshot_id'=>(int)$correction->snapshot_id,'recorded'=>true,'idempotent'=>true,'command_id'=>(int)$row->id);
    }
    /**
     * §15.3: the canonical command facts of one `correct_snapshot` command.
     *
     * The recorded payload is a pure function of the correction row the command wrote — its Lesson and
     * snapshot, its corrected rate row, version and restated rate amount, its corrected derived amount and
     * currency, and its operator reason — so both the write path and the replay path build the digested
     * facts here, and a replay reconstitutes exactly these facts from the re-loaded correction row. Every
     * material correction fact is carried: a second self-consistent correction of the same Lesson,
     * snapshot and reason whose corrected rate amount *or* corrected derived amount differs therefore
     * moves the payload and fails closed `command_replay_conflict` instead of converging on a substitute.
     */
    private static function correctionFacts(int $lessonId,int $snapshotId,mixed $rateId,mixed $rateVersion,mixed $rateAmount,mixed $derivedAmount,mixed $currency,string $reason):array{
        return array('lesson_id'=>$lessonId,'snapshot_id'=>$snapshotId,'rate_id'=>$rateId,'version'=>$rateVersion,'rate_amount'=>$rateAmount,'derived_amount'=>$derivedAmount,'currency'=>$currency,'reason'=>$reason,'operation'=>'correct_snapshot');
    }
    /** A canonical exact-integer when the caller supplied one, otherwise the raw value (refused later). */
    private static function canonicalInt(mixed $value):mixed{
        if(is_int($value))return $value;
        if(is_string($value)&&preg_match('/^\d{1,15}$/D',trim($value))===1)return (int)trim($value);
        return $value;
    }
    /** A canonical ISO-4217 literal (upper-cased, trimmed) whatever casing the caller supplied. */
    private static function canonicalCurrency(mixed $value):string{return strtoupper(trim((string)$value));}
}
