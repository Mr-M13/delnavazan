<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

use Delnavazan\Platform\Core\Infrastructure\Repository\PaymentExecutionRepository;

/**
 * PII-minimised, digest-only, secret-free execution read model (contract §13).
 *
 * It fails closed on a malformed aggregate, reports counts and states only, and never returns a
 * provider payload, secret, raw reference, raw signature or provider status string.
 */
final class PaymentExecutionReadService {
    private const VIEW_CAPABILITY='dzn_view_payment_execution_authority';
    public function __construct(private ?PaymentExecutionRepository $repository=null){$this->repository??=new PaymentExecutionRepository();}

    /** Every command with its derived state, proved against its claim and its result row. */
    public function commands(int $limit=50):array{
        PaymentExecutionSupport::requireCapability(self::VIEW_CAPABILITY);
        $rows=array();
        foreach($this->repository->commands() as $command){
            $claim=$this->repository->dispatchForCommand((int)$command->id);
            $result=$this->repository->resultForCommand((int)$command->id);
            $rows[]=$this->commandView($command,$claim,$result);
        }
        return array_slice($rows,-max(1,min($limit,200)));
    }

    public function command(int $commandId):array{
        PaymentExecutionSupport::requireCapability(self::VIEW_CAPABILITY);
        $command=$this->repository->commandById($commandId);
        if(!$command)throw new \InvalidArgumentException('payment_execution_command_required');
        return $this->commandView($command,$this->repository->dispatchForCommand($commandId),$this->repository->resultForCommand($commandId));
    }

    /** Execution commands by derived result state (§13 diagnostics). */
    public function commandStates():array{
        PaymentExecutionSupport::requireCapability(self::VIEW_CAPABILITY);
        $counts=array('authorised'=>0,'dispatching'=>0,'completed'=>0,'refused'=>0,'conflicted'=>0);
        foreach($this->commands(200) as $row)if(isset($counts[$row['derived_state']]))$counts[$row['derived_state']]++;
        return $counts;
    }
    /** Attempts by provider-neutral outcome. */
    public function attemptOutcomes():array{
        PaymentExecutionSupport::requireCapability(self::VIEW_CAPABILITY);
        $counts=array_fill_keys(PaymentExecutionRule::OUTCOME_STATES,0);
        foreach($this->repository->commands() as $command){
            $attempt=$this->repository->attemptForCommand((int)$command->id);
            if($attempt&&isset($counts[(string)$attempt->outcome_state]))$counts[(string)$attempt->outcome_state]++;
        }
        return $counts;
    }
    /** Outstanding dispatch claims by state, age and fencing generation — never a token or a digest. */
    public function outstandingDispatches():array{
        PaymentExecutionSupport::requireCapability(self::VIEW_CAPABILITY);
        $rows=array();
        foreach($this->repository->dispatches() as $claim){
            if(!PaymentExecutionRule::member((string)$claim->dispatch_state,array('claimed','in_flight')))continue;
            $rows[]=array(
                'execution_command_id'=>(int)$claim->execution_command_id,
                'arbitration_subject_kind'=>(string)$claim->arbitration_subject_kind,
                'arbitration_subject_id'=>(int)$claim->arbitration_subject_id,
                'dispatch_state'=>(string)$claim->dispatch_state,
                'claim_generation'=>(int)$claim->claim_generation,
                'age_seconds'=>max(0,time()-(int)strtotime((string)$claim->claimed_at.' UTC')),
            );
        }
        return $rows;
    }
    /** Descriptor-refusal releases and generation-1 no-call aborts by reason code (§13 diagnostics). */
    public function descriptorRefusals():array{
        PaymentExecutionSupport::requireCapability(self::VIEW_CAPABILITY);
        $released=0;$refused=0;
        foreach($this->repository->dispatches() as $claim)if((string)$claim->dispatch_state==='released')$released++;
        foreach($this->repository->results() as $result)if((string)$result->reason_code==='dispatch_descriptor_unavailable')$refused++;
        return array('released_claims'=>$released,'refused_results'=>$refused,'reason_code'=>'dispatch_descriptor_unavailable');
    }

    private function commandView(object $command,?object $claim,?object $result):array{
        PaymentExecutionIntegrity::commandRowIsImmutable(array_keys(get_object_vars($command)));
        PaymentExecutionIntegrity::commandShape($command);
        if($claim)PaymentExecutionIntegrity::dispatchClaim($claim,$result);
        if($result)PaymentExecutionIntegrity::result($result,$result->result_id===null?null:$this->repository->attemptForCommand((int)$command->id),(int)$command->id);
        return array(
            'execution_command_id'=>(int)$command->id,'operation'=>(string)$command->operation,
            'provider_key'=>(string)$command->provider_key,'mode'=>(string)$command->mode,
            'student_id'=>(int)$command->student_id,'obligation_id'=>(int)$command->obligation_id,
            'purchase_id'=>$command->purchase_id===null?null:(int)$command->purchase_id,
            'collection_intent_id'=>$command->collection_intent_id===null?null:(int)$command->collection_intent_id,
            'renewal_cycle_id'=>$command->renewal_cycle_id===null?null:(int)$command->renewal_cycle_id,
            'amount_minor'=>(int)$command->amount_minor,'currency'=>(string)$command->currency,
            'derived_state'=>PaymentExecutionIntegrity::commandState($command,$claim,$result),
            'result_state'=>$result?(string)$result->result_state:null,
            'reason_code'=>$result?$result->reason_code:null,
            'dispatch_state'=>$claim?(string)$claim->dispatch_state:null,
            'claim_generation'=>$claim?(int)$claim->claim_generation:null,
            'authorised_at'=>(string)$command->authorised_at,
        );
    }
}
