<?php
namespace Delnavazan\Platform\Core\Application\Finance\Statements;

use Delnavazan\Platform\Core\Application\Finance\Integrity\FinanceStatementIntegrity;
use Delnavazan\Platform\Core\Application\Finance\{FinanceIdempotency,FinanceRefusalException,FinanceRule,FinanceSupport};
use Delnavazan\Platform\Core\Infrastructure\Repository\{FinancePayabilityRepository,FinanceSnapshotRepository,FinanceStatementRepository};

/**
 * Records the settlement of one issued Teacher compensation statement.
 *
 * This is an additive statement-lifecycle fact, not a second compensation authority:
 * the issued statement remains the recorded amount owed, and `payment_recorded` is the
 * append-only evidence that the academy paid it. No bank-account or card data is stored.
 * A statement is settled in full exactly once; partial settlement is deliberately not
 * modelled because the statement is the smallest recorded payable unit.
 */
final class TeacherStatementPaymentService {
    private const CAPABILITY='dzn_manage_finance_statements';
    private const METHODS=array('manual_bank_transfer','cash','other');

    public function __construct(
        private ?FinanceStatementRepository $statements=null,
        private ?FinanceSnapshotRepository $snapshots=null,
        private ?FinancePayabilityRepository $evaluations=null
    ){
        $this->statements??=new FinanceStatementRepository();
        $this->snapshots??=new FinanceSnapshotRepository();
        $this->evaluations??=new FinancePayabilityRepository();
    }

    /**
     * @param array{paid_at?:string,method_code?:string,evidence_channel?:string,evidence_reference?:string,reason?:string} $input
     * @return array{statement_id:int,state:string,paid_at:string,idempotent?:bool,command_id:int}
     */
    public function record(int $statementId,array $input,string $rawKey):array{
        FinanceSupport::requireCapability(self::CAPABILITY);
        if($statementId<1)throw new FinanceRefusalException('finance_parent_not_live','The statement does not exist');
        $actor=FinanceSupport::actor();$now=FinanceSupport::now();$digest=FinanceSupport::key($rawKey);
        $paidAt=trim((string)($input['paid_at']??$now));
        if(!FinanceRule::utc($paidAt)||$paidAt>$now)throw new FinanceRefusalException('statement_payment_instant_invalid','A settlement instant must be a valid past-or-present UTC instant',array('statement_id'=>$statementId));
        $method=(string)($input['method_code']??'manual_bank_transfer');
        if(!in_array($method,self::METHODS,true))throw new FinanceRefusalException('finance_vocabulary_member_not_allowed','A settlement method is one of the declared kinds',array('statement_id'=>$statementId));
        $reference=trim((string)($input['evidence_reference']??''));
        if($reference==='')throw new FinanceRefusalException('statement_payment_evidence_invalid','A settlement requires an external evidence reference',array('statement_id'=>$statementId));
        $reason=(string)($input['reason']??'operator_decision');
        if(!FinanceRule::operatorReason($reason))throw new FinanceRefusalException('finance_vocabulary_member_not_allowed','A settlement reason is an operator reason',array('statement_id'=>$statementId));
        $hint=$this->statements->byId($statementId);
        if(!$hint)throw new FinanceRefusalException('finance_parent_not_live','The statement does not exist');
        $teacherId=(int)$hint->teacher_id;
        $channel=(string)($input['evidence_channel']??'authenticated_platform');
        $evidence=FinanceSupport::evidence(array('evidence_channel'=>$channel,'evidence_reference'=>$reference,'evidence_at'=>$paidAt));
        $payload=FinanceSupport::payload(array('statement_id'=>$statementId,'operation'=>'record_payment','paid_at'=>$paidAt,'method_code'=>$method,'evidence_reference_digest'=>$evidence['digest']));
        $command=array(
            'command_domain'=>FinanceRule::DOMAIN,'operation'=>'record_payment','command_key_digest'=>$digest,
            'command_payload_digest'=>$payload,'teacher_id'=>$teacherId,'statement_id'=>$statementId,
            'result_state'=>FinanceRule::commandSuccessState('record_payment'),'result_statement_id'=>$statementId,
            'reason_code'=>$reason,'created_at'=>$now,'created_by'=>$actor,
        );
        $lock=static function()use($statementId,$actor):void{
            $statement=(new FinanceStatementRepository())->byId($statementId);
            if(!$statement)throw new FinanceRefusalException('finance_parent_not_live','The statement does not exist');
            FinanceSupport::lockPolicyRootThenTeacher((int)$statement->teacher_id,$actor);
        };
        return FinanceSupport::runCommand($lock,'finance_statement_commands',$command,function()use($statementId,$paidAt,$method,$evidence,$reason,$actor,$now,$digest,$payload,&$command):array{
            if($existing=$this->statements->command($digest)){
                if(!hash_equals((string)$existing->command_payload_digest,$payload))throw new FinanceRefusalException('command_replay_conflict','The replay payload differs from the recorded settlement command');
                FinanceSupport::assertReplayState($existing,'record_payment');
                $event=$this->statements->paymentFor($statementId);
                if(!$event)throw new FinanceRefusalException('command_replay_conflict','The recorded settlement has no payment event');
                return array('statement_id'=>$statementId,'state'=>'paid','paid_at'=>(string)$event->occurred_at,'idempotent'=>true,'command_id'=>(int)$existing->id);
            }
            $statement=$this->statements->byId($statementId,true);
            if(!$statement)throw new FinanceRefusalException('finance_parent_not_live','The statement does not exist');
            $scope=array('teacher_id'=>(int)$statement->teacher_id,'statement_id'=>$statementId);
            if((string)$statement->state!=='issued')throw new FinanceRefusalException('statement_state_transition_conflict','Only an issued statement can be settled (current state: '.(string)$statement->state.')',$scope);
            if($paidAt<(string)$statement->issued_at)throw new FinanceRefusalException('statement_payment_instant_invalid','A settlement cannot precede the statement issue instant',$scope);
            FinanceStatementIntegrity::assertTotals($statementId,$this->statements,$this->snapshots,$this->evaluations);
            if($this->statements->paymentFor($statementId)!==null)throw new FinanceRefusalException('statement_state_transition_conflict','This statement already carries a settlement event',$scope);
            if($this->statements->markPaid($statementId,$actor,$now)!==1)throw new FinanceRefusalException('statement_state_transition_conflict','The settlement lost its compare-and-swap against the statement state',$scope);
            $this->statements->insertEvent(array(
                'statement_id'=>$statementId,'event_sequence'=>$this->statements->nextEventSequence($statementId),
                'event_type'=>'payment_recorded','from_state'=>'issued','to_state'=>'paid','superseded_by_statement_id'=>null,
                'reason_code'=>$reason,'evidence_channel'=>$evidence['channel'],'evidence_reference_digest'=>$evidence['digest'],
                'evidence_at'=>$paidAt,'occurred_at'=>$paidAt,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            FinanceSupport::audit('finance_statements',$statementId,'record_payment',$actor,$digest,null,$now,null);
            $commandId=$this->statements->insertCommand($command);
            return array('statement_id'=>$statementId,'state'=>'paid','paid_at'=>$paidAt,'method_code'=>$method,'command_id'=>$commandId);
        });
    }
}
