<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\CommercialAuthorityRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Versioned runtime commercial policy authority (class-B policies only).
 *
 * Structural invariants are deliberately not configurable here: the canonical Term session
 * allocation lives with the Term authority and the Term change allowance lives with the Term's
 * recorded value. A policy row is immutable; a new value is a new version, and an unset value is a
 * deliberate recorded state rather than a missing one.
 */
final class CommercialPolicyService {
    private const CAPABILITY='dzn_manage_commercial_policies';
    public function __construct(private ?CommercialAuthorityRepository $repository=null){$this->repository??=new CommercialAuthorityRepository();}

    /** Derived current state: `set` is true only for a recorded non-null value. */
    public function current(string $policyKey):array{
        $this->requireKey($policyKey,'view');
        $row=$this->repository->latestPolicy($policyKey);
        if(!$row)return array('policy_key'=>$policyKey,'set'=>false,'version'=>null,'value'=>null,'value_type'=>null,'effective_from'=>null);
        // A stored row that is not a valid class-B policy (for example a hand-inserted structural
        // invariant key) fails the read closed instead of being presented as configuration.
        if(!CommercialValidator::policyValid($row))throw new \InvalidArgumentException('commercial_policy_integrity_conflict');
        return array(
            'policy_key'=>$policyKey,'set'=>$row->policy_value!==null,'version'=>(int)$row->policy_version,
            'value'=>$row->policy_value===null?null:(string)$row->policy_value,
            'value_type'=>$row->value_type===null?null:(string)$row->value_type,
            'effective_from'=>$row->effective_from===null?null:(string)$row->effective_from,
        );
    }
    public function history(string $policyKey):array{
        $this->requireCapability('dzn_view_commercial_authority');
        $this->requireKey($policyKey,'view');
        $rows=array();
        foreach($this->repository->policies() as $row){
            if((string)$row->policy_key!==$policyKey)continue;
            if(!CommercialValidator::policyValid($row))throw new \InvalidArgumentException('commercial_policy_integrity_conflict');
            $rows[]=array(
                'policy_id'=>(int)$row->id,'policy_version'=>(int)$row->policy_version,
                'value'=>$row->policy_value===null?null:(string)$row->policy_value,
                'value_type'=>$row->value_type===null?null:(string)$row->value_type,
                'effective_from'=>$row->effective_from===null?null:(string)$row->effective_from,
                'recorded_at'=>(string)$row->recorded_at,'recorded_by'=>(int)$row->recorded_by,
            );
        }
        return array('policy_key'=>$policyKey,'versions'=>$rows);
    }

    /** Record a new policy version. A null value records a deliberate "unset" state. */
    public function set(string $policyKey,array $input,string $key):array{
        CommercialSupport::requireCapability(self::CAPABILITY);
        $actor=CommercialSupport::actor();
        $this->requireKey($policyKey,'set');
        $evidence=CommercialSupport::evidence($input);
        [$value,$valueType]=$this->normaliseValue($policyKey,$input);
        $effectiveFrom=($input['effective_from']??null)===null||$input['effective_from']===''?null:CommercialSupport::utc($input['effective_from'],'Valid UTC effective time required');
        $digest=CommercialSupport::keyString($key);
        $payload=CommercialIdempotency::payload(array('policy_key'=>$policyKey,'value'=>$value,'value_type'=>$valueType,'effective_from'=>$effectiveFrom,'evidence_reference_digest'=>$evidence['digest']));
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){
                $replay=$this->replay($winner,$payload);
                $this->repository->commit();
                return $replay;
            }
            $latest=$this->repository->latestPolicy($policyKey,true);
            $version=$latest?(int)$latest->policy_version+1:1;
            $now=CommercialSupport::now();
            $id=$this->repository->insertPolicy(array(
                'uid'=>Identifier::uid(),'policy_key'=>$policyKey,'policy_version'=>$version,
                'policy_value'=>$value,'value_type'=>$valueType,'effective_from'=>$effectiveFrom,'status'=>'active',
                'reason_code'=>CommercialSupport::reason($input),'evidence_channel'=>$evidence['channel'],
                'evidence_reference_digest'=>$evidence['digest'],'evidence_at'=>$evidence['at'],
                'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>CommercialRule::DOMAIN,'operation'=>'set_policy',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,
                'result_state'=>'recorded','result_id'=>$id,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->commit();
            return array('policy_id'=>$id,'policy_key'=>$policyKey,'policy_version'=>$version,'value'=>$value,'value_type'=>$valueType,'created'=>true);
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replay($winner,$payload);
            throw $e;
        }
    }

    private function replay(object $command,string $payload):array{
        if(!hash_equals((string)$command->command_payload_digest,$payload))throw new \RuntimeException('Idempotency conflict');
        if((string)$command->command_domain!==CommercialRule::DOMAIN||(string)$command->operation!=='set_policy')throw new \RuntimeException('Contaminated commercial policy command');
        $policy=$this->repository->policy((int)$command->result_id);
        if(!$policy||!CommercialValidator::policyValid($policy))throw new \RuntimeException('Contaminated commercial policy result');
        return array('policy_id'=>(int)$policy->id,'policy_key'=>(string)$policy->policy_key,'policy_version'=>(int)$policy->policy_version,'value'=>$policy->policy_value===null?null:(string)$policy->policy_value,'value_type'=>$policy->value_type===null?null:(string)$policy->value_type,'created'=>false,'idempotent'=>true);
    }
    private function requireKey(string $policyKey,string $operation):void{
        if(!in_array($policyKey,CommercialRule::POLICY_KEYS,true))throw new \InvalidArgumentException('Structural invariants are not configurable commercial policies');
    }
    private function requireCapability(string $capability):void{CommercialSupport::requireCapability($capability);}
    /** @return array{0:?string,1:?string} */
    private function normaliseValue(string $policyKey,array $input):array{
        $raw=$input['policy_value']??null;
        if($raw===null||(is_string($raw)&&trim($raw)===''))return array(null,null);
        $type=(string)($input['value_type']??'');
        if(!in_array($type,CommercialRule::POLICY_VALUE_TYPES,true))throw new \InvalidArgumentException('Controlled policy value type required');
        $value=trim((string)$raw);
        if($type==='weeks'){
            if(preg_match('/^\d{1,3}$/D',$value)!==1)throw new \InvalidArgumentException('Whole-week policy value required');
            $weeks=(int)$value;
            if($weeks<1||$weeks>104)throw new \InvalidArgumentException('Whole-week policy value out of range');
            return array((string)$weeks,'weeks');
        }
        if($type==='duration'){
            if(preg_match('/^\d{1,6}$/D',$value)!==1)throw new \InvalidArgumentException('Whole-second duration policy value required');
            if((int)$value<1)throw new \InvalidArgumentException('Duration policy value out of range');
            return array((string)(int)$value,'duration');
        }
        if(preg_match('/^[a-z0-9_.:-]{1,64}$/D',$value)!==1)throw new \InvalidArgumentException('Policy reference value required');
        return array($value,'policy_reference');
    }
}
