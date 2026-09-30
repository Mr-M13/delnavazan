<?php
namespace Delnavazan\Platform\Core\Application\Finance;

use Delnavazan\Platform\Core\Infrastructure\Repository\FinancePolicyRepository;

/**
 * Versioned finance policy authority (contract §6).
 *
 * `finance_policies` mirrors the commercial registry: immutable-in-value rows, one row per key and
 * version, an explicit recorded effective instant, an explicit recorded status, and "unset" as the
 * absence of a covering version rather than a null-valued row. A version is never recorded into time an
 * already-recorded dependent Finance fact has consumed (§6.3, U-D19), and the three mutating commands
 * take the global policy serialisation root exclusively so a competing version of one key can never
 * interleave with the guard's read.
 */
final class FinancePolicyService {
    private const CAPABILITY='dzn_manage_finance_policies';

    public function __construct(private ?FinancePolicyRepository $policies=null){$this->policies??=new FinancePolicyRepository();}

    /**
     * §6.1: the version that covers an instant — the greatest `effective_from <= $atUtc` — never the
     * live row. A `superseded` version is normal history; a `withdrawn` version resolves to unset but
     * reports its version so a consumer can tell "never set" from "retracted at T".
     *
     * @return array{key:string,set:bool,value:?string,value_type:?string,version:?int,status:?string,withdrawn:bool}
     */
    public function resolve(string $policyKey,string $atUtc):array{
        if(!FinanceRule::policyKey($policyKey))throw new FinanceRefusalException('finance_policy_key_not_allowed','Structural finance invariants are not configurable finance policies');
        if(!FinanceRule::utc($atUtc))throw new \InvalidArgumentException('Valid UTC instant required');
        $row=$this->policies->covering($policyKey,$atUtc);
        if(!$row)return array('key'=>$policyKey,'set'=>false,'value'=>null,'value_type'=>null,'version'=>null,'status'=>null,'withdrawn'=>false);
        $withdrawn=(string)$row->status==='withdrawn';
        return array('key'=>$policyKey,'set'=>!$withdrawn,'value'=>$withdrawn?null:(string)$row->policy_value,'value_type'=>$withdrawn?null:(string)$row->value_type,'version'=>(int)$row->policy_version,'status'=>(string)$row->status,'withdrawn'=>$withdrawn);
    }
    /** The recorded version history of one key (administrator read). */
    public function history(string $policyKey):array{
        FinanceSupport::requireCapability('dzn_view_finance_authority');
        $rows=array();
        foreach($this->policies->versions($policyKey) as $row)$rows[]=array('policy_id'=>(int)$row->id,'policy_version'=>(int)$row->policy_version,'policy_value'=>(string)$row->policy_value,'value_type'=>(string)$row->value_type,'effective_from'=>(string)$row->effective_from,'status'=>(string)$row->status,'reason_code'=>$row->reason_code===null?null:(string)$row->reason_code,'recorded_at'=>(string)$row->recorded_at,'recorded_by'=>(int)$row->recorded_by);
        return $rows;
    }
    /** The exact recorded version row of one key, for an internal consumer that must prove its own pair. */
    public function versionRow(string $policyKey,int $policyVersion):?object{
        return $this->policies->byKeyVersion($policyKey,$policyVersion);
    }

    /** §6.2 `record`: one new version, admissible under §6.3's temporal rule. */
    public function record(string $policyKey,array $input,string $rawKey):array{
        FinanceSupport::requireCapability(self::CAPABILITY);
        $actor=FinanceSupport::actor();
        $now=FinanceSupport::now();
        $digest=FinanceSupport::key($rawKey);
        $evidence=array('channel'=>($input['evidence_channel']??'staff_record'),'at'=>(string)($input['evidence_at']??$now));
        $value=(string)($input['policy_value']??'');
        $valueType=(string)($input['value_type']??'');
        $effectiveFrom=(string)($input['effective_from']??'');
        $payload=FinanceSupport::payload(array('key'=>$policyKey,'value'=>$value,'value_type'=>$valueType,'effective_from'=>$effectiveFrom));
        $command=array('command_domain'=>FinanceRule::DOMAIN,'operation'=>'record','command_key_digest'=>$digest,'command_payload_digest'=>$payload,'policy_key'=>$policyKey,'policy_version'=>null,'policy_id'=>null,'result_state'=>'recorded','result_policy_id'=>null,'reason_code'=>null,'created_at'=>$now,'created_by'=>$actor);
        $lock=static fn()=>FinanceSupport::lockPolicyRoot(true);
        return FinanceSupport::runCommand($lock,'finance_policy_commands',$command,function()use($policyKey,$input,$value,$valueType,$effectiveFrom,$actor,$now,$payload,$digest,&$command){
            $this->assertKey($policyKey);
            if($effectiveFrom===''||!FinanceRule::utc($effectiveFrom))throw new FinanceRefusalException('finance_policy_effective_from_missing','A finance policy version always records its effective instant',array());
            if(!in_array($valueType,FinanceRule::POLICY_VALUE_TYPES,true)||$value==='')throw new FinanceRefusalException('finance_policy_value_type_invalid','A finance policy version is always value-bearing',array());
            $this->assertValue($policyKey,$value,$valueType);
            $proof=FinanceSupport::evidence($input);
            $existing=$this->policies->command($digest);
            if($existing)return $this->replay($existing,$payload,'record');
            $latest=$this->policies->latest($policyKey,true);
            $version=$latest?(int)$latest->policy_version+1:1;
            if($latest&&$effectiveFrom<=(string)$latest->effective_from)throw new FinanceRefusalException('finance_policy_timeline_overlap','No two versions of one policy key may claim one instant');
            $consumed=$this->policies->consumptionMaximum($policyKey);
            if($consumed!==null&&$effectiveFrom<=$consumed)throw new FinanceRefusalException('policy_effective_from_precedes_recorded_consumption','A policy version may never be recorded into time a recorded dependent Finance fact has already consumed');
            $policyId=$this->policies->insertPolicy(array(
                'policy_key'=>$policyKey,'policy_version'=>$version,'policy_value'=>$value,'value_type'=>$valueType,
                'effective_from'=>$effectiveFrom,'status'=>'active','reason_code'=>null,
                'evidence_channel'=>$proof['channel'],'evidence_reference_digest'=>$proof['digest'],'evidence_at'=>$proof['at'],
                'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,'updated_at'=>$now,
            ));
            // §6.2: `record()` inserts one version and moves nothing. The predecessor's `active → superseded`
            // transition belongs to the separate, audited `supersede()` command, so an auto-supersession here
            // would consume that command's only transition and leave it with no result of its own.
            FinanceSupport::audit('finance_policies',$policyId,'record',$actor,$digest,null,$now,null);
            $command['policy_version']=$version;$command['policy_id']=$policyId;$command['result_policy_id']=$policyId;
            $commandId=$this->policies->insertCommand($command);
            return array('policy_id'=>$policyId,'policy_key'=>$policyKey,'policy_version'=>$version,'policy_value'=>$value,'value_type'=>$valueType,'recorded'=>true,'command_id'=>$commandId);
        });
    }

    /** §6.1: the single conditional `active → superseded` move on the row a recorded successor replaces. */
    public function supersede(string $policyKey,int $policyVersion,array $input,string $rawKey):array{
        FinanceSupport::requireCapability(self::CAPABILITY);
        $actor=FinanceSupport::actor();$now=FinanceSupport::now();$digest=FinanceSupport::key($rawKey);
        $payload=FinanceSupport::payload(array('key'=>$policyKey,'version'=>$policyVersion,'operation'=>'supersede'));
        $command=array('command_domain'=>FinanceRule::DOMAIN,'operation'=>'supersede','command_key_digest'=>$digest,'command_payload_digest'=>$payload,'policy_key'=>$policyKey,'policy_version'=>$policyVersion,'policy_id'=>null,'result_state'=>'superseded','result_policy_id'=>null,'reason_code'=>null,'created_at'=>$now,'created_by'=>$actor);
        $lock=static fn()=>FinanceSupport::lockPolicyRoot(true);
        return FinanceSupport::runCommand($lock,'finance_policy_commands',$command,function()use($policyKey,$policyVersion,$actor,$now,$payload,$digest,&$command){
            $this->assertKey($policyKey);
            if($existing=$this->policies->command($digest))return $this->replay($existing,$payload,'supersede');
            $row=$this->policies->byKeyVersion($policyKey,$policyVersion,true);
            if(!$row||(string)$row->status!=='active')throw new FinanceRefusalException('finance_policy_version_conflict','Only an active version with a recorded successor may be superseded');
            $successor=$this->policies->latest($policyKey,true);
            if(!$successor||(int)$successor->policy_version<=(int)$row->policy_version)throw new FinanceRefusalException('finance_policy_version_conflict','A policy version is only superseded by a successor that record() admitted');
            if($this->policies->supersede((int)$row->id,$now,$actor)!==1)throw new FinanceRefusalException('finance_policy_version_conflict','The policy supersession lost its compare-and-swap');
            FinanceSupport::audit('finance_policies',(int)$row->id,'supersede',$actor,$digest,null,$now,null);
            $command['policy_id']=(int)$row->id;$command['result_policy_id']=(int)$row->id;
            $commandId=$this->policies->insertCommand($command);
            return array('policy_id'=>(int)$row->id,'policy_key'=>$policyKey,'policy_version'=>$policyVersion,'superseded'=>true,'command_id'=>$commandId);
        });
    }

    /** §6.1: the single conditional `active|superseded → withdrawn` move; `withdrawn` is terminal. */
    public function withdraw(string $policyKey,int $policyVersion,array $input,string $rawKey):array{
        FinanceSupport::requireCapability(self::CAPABILITY);
        $actor=FinanceSupport::actor();$now=FinanceSupport::now();$digest=FinanceSupport::key($rawKey);
        $reason=FinanceSupport::operatorReason($input);
        $payload=FinanceSupport::payload(array('key'=>$policyKey,'version'=>$policyVersion,'reason'=>$reason,'operation'=>'withdraw'));
        $command=array('command_domain'=>FinanceRule::DOMAIN,'operation'=>'withdraw','command_key_digest'=>$digest,'command_payload_digest'=>$payload,'policy_key'=>$policyKey,'policy_version'=>$policyVersion,'policy_id'=>null,'result_state'=>'withdrawn','result_policy_id'=>null,'reason_code'=>$reason,'created_at'=>$now,'created_by'=>$actor);
        $lock=static fn()=>FinanceSupport::lockPolicyRoot(true);
        return FinanceSupport::runCommand($lock,'finance_policy_commands',$command,function()use($policyKey,$policyVersion,$actor,$now,$payload,$digest,&$command,$reason){
            $this->assertKey($policyKey);
            if($existing=$this->policies->command($digest))return $this->replay($existing,$payload,'withdraw');
            $row=$this->policies->byKeyVersion($policyKey,$policyVersion,true);
            if(!$row||(string)$row->status==='withdrawn')throw new FinanceRefusalException('finance_policy_version_conflict','Only an active or superseded version may be withdrawn');
            if($this->policies->withdraw((int)$row->id,$now,$actor,$reason)!==1)throw new FinanceRefusalException('finance_policy_version_conflict','The policy withdrawal lost its compare-and-swap');
            FinanceSupport::audit('finance_policies',(int)$row->id,'withdraw',$actor,$digest,$reason,$now,null);
            $command['policy_id']=(int)$row->id;$command['result_policy_id']=(int)$row->id;
            $commandId=$this->policies->insertCommand($command);
            return array('policy_id'=>(int)$row->id,'policy_key'=>$policyKey,'policy_version'=>$policyVersion,'withdrawn'=>true,'command_id'=>$commandId);
        });
    }

    /** §5.4: a structural invariant can never be recorded as a configurable policy. */
    private function assertKey(string $policyKey):void{
        if(!FinanceRule::policyKey($policyKey))throw new FinanceRefusalException('finance_policy_key_not_allowed','Structural finance invariants are not configurable finance policies');
    }
    /** §6.1: the value each key accepts; an unset key is simply absent, never a null-valued row. */
    private function assertValue(string $policyKey,string $value,string $valueType):void{
        if($policyKey===FinanceRule::UNSET_POLICY_KEY){
            if($valueType!=='timezone'||!FinanceRule::timezone($value))throw new FinanceRefusalException('finance_policy_value_type_invalid','The statement timezone policy records an IANA timezone');
            return;
        }
        $allowed=$policyKey==='INTRO_PAYABILITY_POLICY'?array('payable','non_payable'):(FinanceRule::COMPENSATION_POLICY_VALUES[$policyKey]??array());
        if($valueType!=='policy_reference'||!in_array($value,$allowed,true))throw new FinanceRefusalException('finance_policy_value_type_invalid','The recorded value is not a member of the key\'s declared vocabulary');
    }
    /**
     * §15.3: an identical replay converges on the original result — and only after the version row the
     * command recorded has been re-loaded under the held global policy root and re-proved.
     *
     * A different intent is refused. So is a recorded result that is absent, that names another key or
     * version, that no longer carries a well-formed member of the key's declared vocabulary, that no
     * longer reproduces the command's own payload (`record`), or whose status contradicts the recorded
     * conditional move (`supersede`/`withdraw`). Every one of those fails closed with
     * `command_replay_conflict` and preserves the original command row.
     */
    private function replay(object $row,string $payload,string $operation):array{
        if(!hash_equals((string)$row->command_payload_digest,$payload)||(string)$row->operation!==$operation)throw new FinanceRefusalException('command_replay_conflict','A materially different replay is refused and the original record is preserved');
        FinanceSupport::assertReplayState($row,$operation);
        FinanceSupport::assertReplayResultShape($row,'finance_policy_commands',$operation);
        $policy=FinanceSupport::replayResultRow((int)$row->result_policy_id,fn(int $id)=>$this->policies->byId($id,true),array(
            'policy_key'=>(string)$row->policy_key,
            'policy_version'=>$row->policy_version===null?null:(int)$row->policy_version,
        ),'finance_policies');
        $this->assertKey((string)$policy->policy_key);
        $this->assertValue((string)$policy->policy_key,(string)$policy->policy_value,(string)$policy->value_type);
        if(!FinanceRule::utc((string)$policy->effective_from)||(int)$policy->policy_version<1)throw new FinanceRefusalException('command_replay_conflict','The recorded policy version no longer carries a valid instant and version');
        if(!in_array((string)$policy->status,array('active','superseded','withdrawn'),true))throw new FinanceRefusalException('command_replay_conflict','The recorded policy version no longer carries a declared lifecycle status');
        if($operation==='record')FinanceSupport::assertReplayPayload((string)$row->command_payload_digest,array('key'=>(string)$policy->policy_key,'value'=>(string)$policy->policy_value,'value_type'=>(string)$policy->value_type,'effective_from'=>(string)$policy->effective_from),'finance_policies');
        if($operation==='supersede'&&(string)$policy->status==='active')throw new FinanceRefusalException('command_replay_conflict','A replayed policy supersession names a version that is still active');
        if($operation==='withdraw'&&(string)$policy->status!=='withdrawn')throw new FinanceRefusalException('command_replay_conflict','A replayed policy withdrawal names a version that is not withdrawn');
        return array('policy_id'=>(int)$policy->id,'policy_key'=>(string)$policy->policy_key,'policy_version'=>(int)$policy->policy_version,'recorded'=>true,'idempotent'=>true,'command_id'=>(int)$row->id);
    }
}
