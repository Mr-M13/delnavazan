<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

use Delnavazan\Platform\Core\Infrastructure\Repository\PaymentProviderRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Provider-account authority (contract §6.3, §7, §13).
 *
 * An account row records provider identity, mode, state and the non-secret webhook selector. It
 * authorises nothing on its own: acceptance still requires a valid signature under that account's
 * secret, and every account change is an append-only event. No account can ever be enabled for live
 * execution while `LIVE_EXECUTION_PROVIDERS` is empty.
 */
final class PaymentProviderAccountService {
    private const CAPABILITY='dzn_manage_payment_providers';
    private const VIEW_CAPABILITY='dzn_view_payment_execution_authority';

    public function __construct(private ?PaymentProviderRepository $repository=null){$this->repository??=new PaymentProviderRepository();}

    /** Register one provider account; the non-secret `reference_code` is the webhook account selector. */
    public function register(array $input,string $key):array{
        PaymentExecutionSupport::requireCapability(self::CAPABILITY);
        $actor=PaymentExecutionSupport::actor();
        $providerKey=$this->provider($input);
        $mode=$this->mode($input);
        $referenceCode=trim((string)($input['reference_code']??''));
        if($referenceCode==='')throw new \InvalidArgumentException('Provider account reference code required');
        if(preg_match('/^[A-Za-z0-9_-]{1,32}$/D',$referenceCode)!==1)throw new \InvalidArgumentException('Provider account reference code required');
        $accountReference=trim((string)($input['account_reference']??''));
        if($accountReference==='')throw new \InvalidArgumentException('Provider account reference required');
        $executionState=(string)($input['execution_state']??'disabled');
        if(!PaymentExecutionRule::member($executionState,PaymentExecutionRule::EXECUTION_STATES))throw new \InvalidArgumentException('Controlled execution state required');
        $credentialState=(string)($input['credential_state']??'unconfigured');
        if(!PaymentExecutionRule::member($credentialState,PaymentExecutionRule::CREDENTIAL_STATES))throw new \InvalidArgumentException('Controlled credential state required');
        if($executionState==='enabled'&&$mode==='live'&&!PaymentExecutionRule::liveExecutionAuthorised($providerKey))throw new \InvalidArgumentException('live_execution_not_authorised');
        $digest=PaymentExecutionSupport::keyString($key);
        $digestRef=PaymentExecutionIdempotency::reference($accountReference);
        $payload=PaymentExecutionIdempotency::payload(array('domain'=>PaymentExecutionRule::DOMAIN,'operation'=>'register_provider_account','provider_key'=>$providerKey,'mode'=>$mode,'reference_code'=>$referenceCode,'account_reference_digest'=>$digestRef,'execution_state'=>$executionState,'credential_state'=>$credentialState));
        $this->repository->begin();
        try{
            if($winner=$this->repository->accountCommand($digest)){$result=$this->replay($winner,$payload);$this->repository->commit();return $result;}
            $now=PaymentExecutionSupport::now();
            $accountId=$this->repository->insertAccount(array(
                'uid'=>Identifier::uid(),'reference_code'=>$referenceCode,'provider_key'=>$providerKey,'mode'=>$mode,
                'account_reference_digest'=>$digestRef,'state'=>'active','execution_state'=>$executionState,
                'credential_state'=>$credentialState,'credential_key_version'=>null,'account_version'=>1,
                'created_at'=>$now,'updated_at'=>$now,'created_by'=>$actor,'updated_by'=>$actor,
            ));
            $eventId=$this->recordEvent($accountId,'registered',null,'active',null,$executionState,'account_registered',$input,$actor,$now);
            $this->repository->insertAccountCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>PaymentExecutionRule::DOMAIN,'operation'=>'register_provider_account',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'payment_provider_account_id'=>$accountId,
                'result_state'=>'registered','result_id'=>$eventId,'created_at'=>$now,'created_by'=>$actor,
            ));
            PaymentExecutionSupport::hook('dzn_phase_2a2t_after_provider_account_event','register_provider_account',$accountId);
            $this->repository->commit();
            return array('provider_account_id'=>$accountId,'provider_key'=>$providerKey,'mode'=>$mode,'state'=>'active','execution_state'=>$executionState,'reference_code'=>$referenceCode,'created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->accountCommand($digest)))return $this->replay($winner,$payload);
            throw $e;
        }
    }

    /** Enable or disable execution for one account. `live` still refuses while the locked list is empty. */
    public function setExecutionState(int $accountId,array $input,string $key):array{
        PaymentExecutionSupport::requireCapability(self::CAPABILITY);
        $actor=PaymentExecutionSupport::actor();
        $target=(string)($input['execution_state']??'');
        if(!PaymentExecutionRule::member($target,PaymentExecutionRule::EXECUTION_STATES))throw new \InvalidArgumentException('Controlled execution state required');
        return $this->transition($accountId,$target,$target==='enabled'?'execution_enabled':'execution_disabled',$input,$key,$actor);
    }
    public function suspend(int $accountId,array $input,string $key):array{
        PaymentExecutionSupport::requireCapability(self::CAPABILITY);
        return $this->transition($accountId,'suspended','account_suspended',$input,$key,PaymentExecutionSupport::actor());
    }
    public function resume(int $accountId,array $input,string $key):array{
        PaymentExecutionSupport::requireCapability(self::CAPABILITY);
        return $this->transition($accountId,'active','account_resumed',$input,$key,PaymentExecutionSupport::actor());
    }
    public function close(int $accountId,array $input,string $key):array{
        PaymentExecutionSupport::requireCapability(self::CAPABILITY);
        return $this->transition($accountId,'closed','account_closed',$input,$key,PaymentExecutionSupport::actor());
    }

    public function accounts():array{
        PaymentExecutionSupport::requireCapability(self::VIEW_CAPABILITY);
        return array_map(array($this,'view'),$this->repository->allAccounts());
    }

    /** Resolve the account a webhook request named, before the body is read (contract §9.1). */
    public function resolveByReferenceCode(string $referenceCode,bool $lock=false):?object{
        $matches=$this->repository->accountsByReferenceCode($referenceCode,$lock);
        if(count($matches)!==1)return null;
        $account=$matches[0];
        if((string)$account->state!=='active')return null;
        return $account;
    }

    private function transition(int $accountId,string $toState,string $eventType,array $input,string $key,int $actor):array{
        $digest=PaymentExecutionSupport::keyString($key);
        $payload=PaymentExecutionIdempotency::payload(array('domain'=>PaymentExecutionRule::DOMAIN,'operation'=>$eventType,'payment_provider_account_id'=>$accountId,'to_state'=>$toState));
        $this->repository->begin();
        try{
            if($winner=$this->repository->accountCommand($digest)){$result=$this->replay($winner,$payload);$this->repository->commit();return $result;}
            $account=$this->repository->account($accountId,true);
            if(!$account)throw new \InvalidArgumentException('payment_provider_account_required');
            PaymentExecutionIntegrity::account($account);
            $fromState=(string)$account->state;
            $fromExecution=(string)$account->execution_state;
            if($toState==='closed'&&$fromState==='closed')throw new \InvalidArgumentException('invalid_payment_provider_account_state');
            if(($toState==='active'||$toState==='suspended')&&$fromState==='closed')throw new \InvalidArgumentException('invalid_payment_provider_account_state');
            if($toState==='enabled'&&(string)$account->mode==='live'&&!PaymentExecutionRule::liveExecutionAuthorised((string)$account->provider_key))throw new \InvalidArgumentException('live_execution_not_authorised');
            $now=PaymentExecutionSupport::now();
            $changes=$toState==='enabled'||$toState==='disabled'?array('execution_state'=>$toState):array('state'=>$toState,'execution_state'=>$toState==='closed'?'disabled':$fromExecution);
            $this->repository->updateAccount($accountId,(int)$account->account_version,$changes,$now,$actor);
            $eventId=$this->recordEvent($accountId,$eventType,$fromState,$toState,$fromExecution,(string)($changes['execution_state']??$fromExecution),$eventType,$input,$actor,$now);
            $this->repository->insertAccountCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>PaymentExecutionRule::DOMAIN,'operation'=>$eventType,
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'payment_provider_account_id'=>$accountId,
                'result_state'=>$toState,'result_id'=>$eventId,'created_at'=>$now,'created_by'=>$actor,
            ));
            PaymentExecutionSupport::hook('dzn_phase_2a2t_after_provider_account_event',$eventType,$accountId);
            $this->repository->commit();
            return array('provider_account_id'=>$accountId,'state'=>$toState,'execution_state'=>(string)($changes['execution_state']??$fromExecution),'created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->accountCommand($digest)))return $this->replay($winner,$payload);
            throw $e;
        }
    }

    private function recordEvent(int $accountId,string $eventType,?string $fromState,string $toState,?string $fromExecution,string $toExecution,string $reason,array $input,int $actor,string $now):int{
        $evidence=$this->evidence($input,$now);
        return $this->repository->insertAccountEvent(array(
            'uid'=>Identifier::uid(),'payment_provider_account_id'=>$accountId,
            'event_sequence'=>$this->repository->maxAccountEventSequence($accountId),'event_type'=>$eventType,
            'from_state'=>$fromState,'to_state'=>$toState,'from_execution_state'=>$fromExecution,'to_execution_state'=>$toExecution,
            'reason_code'=>$reason,'evidence_channel'=>$evidence['channel'],'evidence_reference_digest'=>$evidence['digest'],
            'evidence_at'=>$evidence['at'],'occurred_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,
            'created_at'=>$now,'created_by'=>$actor,
        ));
    }
    private function evidence(array $input,string $now):array{
        $channel=(string)($input['evidence_channel']??'staff_record');
        if(!in_array($channel,array('staff_record','authenticated_platform','document_reference','provider_evidence'),true))throw new \InvalidArgumentException('Controlled evidence channel required');
        $reference=trim((string)($input['evidence_reference']??''));
        if($reference==='')throw new \InvalidArgumentException('Evidence reference required');
        $at=isset($input['evidence_at'])&&$input['evidence_at']!==''?PaymentExecutionSupport::utc($input['evidence_at'],'Valid UTC evidence time required'):$now;
        if($at>$now)throw new \InvalidArgumentException('Valid past-or-present UTC evidence time required');
        return array('channel'=>$channel,'at'=>$at,'digest'=>PaymentExecutionIdempotency::reference($reference));
    }
    private function provider(array $input):string{
        $providerKey=PaymentExecutionRule::provider((string)($input['provider_key']??''));
        if($providerKey===null)throw new \InvalidArgumentException('unsupported_payment_provider');
        return $providerKey;
    }
    private function mode(array $input):string{
        $mode=PaymentExecutionRule::mode((string)($input['mode']??''));
        if($mode===null)throw new \InvalidArgumentException('Controlled account mode required');
        return $mode;
    }
    private function view(object $account):array{
        return array(
            'provider_account_id'=>(int)$account->id,'reference_code'=>$account->reference_code,
            'provider_key'=>(string)$account->provider_key,'mode'=>(string)$account->mode,
            'state'=>(string)$account->state,'execution_state'=>(string)$account->execution_state,
            'credential_state'=>(string)$account->credential_state,'credential_key_version'=>$account->credential_key_version,
            'account_version'=>(int)$account->account_version,
        );
    }
    private function replay(object $command,string $payload):array{
        if(!hash_equals((string)$command->command_payload_digest,$payload))throw new \RuntimeException('Idempotency conflict');
        $account=$this->repository->account((int)$command->payment_provider_account_id);
        if(!$account)throw new \RuntimeException('Contaminated provider account command');
        return array('provider_account_id'=>(int)$account->id,'provider_key'=>(string)$account->provider_key,'mode'=>(string)$account->mode,'state'=>(string)$account->state,'execution_state'=>(string)$account->execution_state,'reference_code'=>$account->reference_code,'created'=>false,'idempotent'=>true);
    }
}
