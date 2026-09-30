<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationTemplateRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationWorkflowRepository;

/**
 * §6.1/§6.2/§8.1 — notification workflow definitions with immutable, capability-gated versions.
 *
 * A version's rule set is *draft-only* and activation is its freeze point: the rule rows may be appended
 * only while the version is `draft` and unfrozen, and activation writes `rule_set_digest`/`rule_frozen_at`
 * together with the routing slots in one `READ COMMITTED` transaction. A post-activation append therefore
 * either hits the guarded insert (`workflow_rules_frozen`) or leaves the version failing digest
 * revalidation (`workflow_rule_set_mutated`) and un-dispatchable — it can never silently widen
 * eligibility, scheduling or retry behaviour.
 */
final class NotificationWorkflowService {
    private const CAPABILITY='dzn_manage_notification_workflows';
    public function __construct(
        private ?NotificationWorkflowRepository $repository=null,
        private ?NotificationTemplateRepository $templates=null
    ){
        $this->repository??=new NotificationWorkflowRepository();
        $this->templates??=new NotificationTemplateRepository();
    }

    /** Register the stable identity of one communication workflow. `register_workflow` is idempotent. */
    public function registerWorkflow(array $input,string $key):array{
        NotificationSupport::requireCapability(self::CAPABILITY);
        $actor=NotificationSupport::actor();
        $workflowKey=trim((string)($input['workflow_key']??''));
        if($workflowKey===''||strlen($workflowKey)>64)throw new \InvalidArgumentException('invalid_workflow_state');
        $purpose=substr(trim((string)($input['purpose']??'')),0,48);
        $digest=NotificationSupport::keyDigest($key);
        $payload=NotificationSupport::payloadDigest(array('domain'=>NotificationRule::DOMAIN,'operation'=>'register_workflow','workflow_key'=>$workflowKey,'purpose'=>$purpose));
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$result=$this->replayWorkflow($winner,$payload);$this->repository->commit();return $result;}
            $now=NotificationSupport::now();
            $id=$this->repository->insertWorkflow(array(
                'uid'=>NotificationSupport::uid(),'reference_code'=>null,'workflow_key'=>$workflowKey,'purpose'=>$purpose,
                'state'=>'draft','active_version_id'=>null,'workflow_version'=>1,
                'created_at'=>$now,'updated_at'=>$now,'created_by'=>$actor,'updated_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>NotificationSupport::uid(),'command_domain'=>NotificationRule::DOMAIN,'operation'=>'register_workflow',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'workflow_id'=>$id,'workflow_version_id'=>null,
                'result_state'=>'draft','result_id'=>$id,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->commit();
            return array('workflow_id'=>$id,'workflow_key'=>$workflowKey,'state'=>'draft','created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replayWorkflow($winner,$payload);
            throw $e;
        }
    }

    /**
     * Register a draft version bound to one consumed intent, audience, recipient kind and template.
     *
     * The §6.2.2 binding is refused here rather than at dispatch: an intent with no authoritative fact
     * (`GUARANTEE_EXPIRED`), an audience outside the authorised matrix, and a template that does not
     * resolve are all closed refusals.
     */
    public function registerVersion(int $workflowId,array $input,string $key):array{
        NotificationSupport::requireCapability(self::CAPABILITY);
        $actor=NotificationSupport::actor();
        $intent=trim((string)($input['intent_key']??''));
        $audience=trim((string)($input['audience']??''));
        $recipientKind=trim((string)($input['recipient_kind']??''));
        $templateId=(int)($input['template_id']??0);
        $locale=substr(trim((string)($input['locale']??'')),0,16);
        if(!NotificationRule::registeredIntent($intent))throw new \InvalidArgumentException('unsupported_intent');
        if(NotificationRule::unboundIntent($intent))throw new \InvalidArgumentException('intent_unbound');
        NotificationRule::assertAuthorisedPair($audience,$recipientKind);
        if($locale==='')throw new \InvalidArgumentException('invalid_version_state');
        $digest=NotificationSupport::keyDigest($key);
        $payload=NotificationSupport::payloadDigest(array('domain'=>NotificationRule::DOMAIN,'operation'=>'register_version','workflow_id'=>$workflowId,'intent_key'=>$intent,'audience'=>$audience,'recipient_kind'=>$recipientKind,'template_id'=>$templateId,'locale'=>$locale));
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$result=$this->replayVersion($winner,$payload);$this->repository->commit();return $result;}
            $workflow=$this->repository->workflow($workflowId,true);
            if(!$workflow||in_array((string)$workflow->state,array('retired'),true))throw new \InvalidArgumentException('invalid_workflow_state');
            if(!$this->templates->template($templateId))throw new \InvalidArgumentException('template_variable_mismatch');
            $now=NotificationSupport::now();
            $versionNumber=$this->repository->nextVersionNumber($workflowId);
            $fingerprint=$this->definitionFingerprint((string)$workflow->workflow_key,$versionNumber,$intent,$audience,$recipientKind,$templateId,$locale,'');
            $id=$this->repository->insertVersion(array(
                'uid'=>NotificationSupport::uid(),'workflow_id'=>$workflowId,'version_number'=>$versionNumber,
                'intent_key'=>$intent,'audience'=>$audience,'recipient_kind'=>$recipientKind,'template_id'=>$templateId,
                'locale'=>$locale,'definition_fingerprint'=>$fingerprint,'rule_set_digest'=>null,'rule_frozen_at'=>null,
                'state'=>'draft','active_slot'=>null,'intent_active_slot'=>null,'supersedes_version_id'=>null,
                'effective_from'=>$now,'retired_at'=>null,'retired_by'=>null,
                'created_at'=>$now,'updated_at'=>$now,'created_by'=>$actor,'updated_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>NotificationSupport::uid(),'command_domain'=>NotificationRule::DOMAIN,'operation'=>'register_version',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'workflow_id'=>$workflowId,'workflow_version_id'=>$id,
                'result_state'=>'draft','result_id'=>$id,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->commit();
            return array('workflow_id'=>$workflowId,'workflow_version_id'=>$id,'version_number'=>$versionNumber,'state'=>'draft','created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replayVersion($winner,$payload);
            throw $e;
        }
    }

    /** `set_eligibility_rule`, `set_schedule_rule` and `set_retry_rule` share one draft-only guarded append. */
    public function setEligibilityRule(int $versionId,array $rule,string $key):array{return $this->attachRule($versionId,'eligibility',$rule,$key);}
    public function setScheduleRule(int $versionId,array $rule,string $key):array{return $this->attachRule($versionId,'schedule',$rule,$key);}
    public function setRetryRule(int $versionId,array $rule,string $key):array{return $this->attachRule($versionId,'retry',$rule,$key);}

    private function attachRule(int $versionId,string $kind,array $rule,string $key):array{
        NotificationSupport::requireCapability(self::CAPABILITY);
        $actor=NotificationSupport::actor();
        if(!in_array($kind,NotificationRule::RULE_KINDS,true))throw new \InvalidArgumentException('invalid_version_state');
        $code=trim((string)($rule['rule_code']??''));
        $ordinal=(int)($rule['ordinal']??1);
        $parameterA=array_key_exists('parameter_a',$rule)&&$rule['parameter_a']!==null?(string)$rule['parameter_a']:null;
        $parameterB=array_key_exists('parameter_b',$rule)&&$rule['parameter_b']!==null?(string)$rule['parameter_b']:null;
        $parameterC=array_key_exists('parameter_c',$rule)&&$rule['parameter_c']!==null?(int)$rule['parameter_c']:null;
        $parameterD=array_key_exists('parameter_d',$rule)&&$rule['parameter_d']!==null?(int)$rule['parameter_d']:null;
        $digest=NotificationSupport::keyDigest($key);
        $payload=NotificationSupport::payloadDigest(array('domain'=>NotificationRule::DOMAIN,'operation'=>'set_'.$kind.'_rule','workflow_version_id'=>$versionId,'rule_code'=>$code,'ordinal'=>$ordinal,'parameter_a'=>$parameterA,'parameter_b'=>$parameterB,'parameter_c'=>$parameterC,'parameter_d'=>$parameterD));
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$result=$this->replayRule($winner,$payload);$this->repository->commit();return $result;}
            $version=$this->repository->version($versionId,true);
            // The guarded insert refuses a frozen or non-draft version; this read makes the refusal explicit
            // before the row is shaped, and the repository repeats the check under the lock.
            if(!$version)throw new \InvalidArgumentException('invalid_version_state');
            if((string)$version->state!=='draft'||$version->rule_frozen_at!==null)throw new \RuntimeException('workflow_rules_frozen');
            $now=NotificationSupport::now();
            $id=$this->repository->insertRule($versionId,array(
                'uid'=>NotificationSupport::uid(),'rule_kind'=>$kind,'rule_code'=>$code,'ordinal'=>$ordinal,
                'parameter_a'=>$parameterA,'parameter_b'=>$parameterB,'parameter_c'=>$parameterC,'parameter_d'=>$parameterD,
                'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>NotificationSupport::uid(),'command_domain'=>NotificationRule::DOMAIN,'operation'=>'set_'.$kind.'_rule',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'workflow_id'=>(int)$version->workflow_id,'workflow_version_id'=>$versionId,
                'result_state'=>'draft','result_id'=>$id,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->commit();
            return array('workflow_version_id'=>$versionId,'rule_id'=>$id,'state'=>'draft','created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replayRule($winner,$payload);
            throw $e;
        }
    }

    /**
     * Activate one draft version: validate the complete required eligibility set, the closed schedule
     * composition, the mandatory expiry and the narrow-only retry policy, freeze the rule set, claim the
     * intent routing slot and flip the predecessor in one transaction.
     *
     * A competing activation of the same intent loses the `intent_active` arbitration, fails closed with
     * `intent_routing_conflict` and rolls back whole: no partial routing state, no second active version
     * and never a silent last-writer-wins.
     */
    public function activateVersion(int $versionId,string $key):array{
        NotificationSupport::requireCapability(self::CAPABILITY);
        $actor=NotificationSupport::actor();
        $digest=NotificationSupport::keyDigest($key);
        $payload=NotificationSupport::payloadDigest(array('domain'=>NotificationRule::DOMAIN,'operation'=>'activate_version','workflow_version_id'=>$versionId));
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$result=$this->replayActivation($winner,$payload);$this->repository->commit();return $result;}
            $version=$this->repository->version($versionId,true);
            if(!$version)throw new \InvalidArgumentException('invalid_version_state');
            if((string)$version->state!=='draft')throw new \InvalidArgumentException('invalid_version_state');
            $workflow=$this->repository->workflow((int)$version->workflow_id,true);
            if(!$workflow||(string)$workflow->state==='retired')throw new \InvalidArgumentException('invalid_workflow_state');
            $intent=(string)$version->intent_key;
            if(!NotificationRule::registeredIntent($intent))throw new \InvalidArgumentException('unsupported_intent');
            if(NotificationRule::unboundIntent($intent))throw new \InvalidArgumentException('intent_unbound');
            $tier=NotificationRule::intentTier($intent);
            $rules=$this->repository->rulesFor($versionId);
            $validated=NotificationEligibility::validateRequiredSet($intent,(string)$version->audience,(string)$version->recipient_kind,array_values(array_filter($rules,static fn($row)=>(string)$row->rule_kind==='eligibility')));
            $composition=NotificationSchedule::validateComposition(array_values(array_filter($rules,static fn($row)=>(string)$row->rule_kind==='schedule')),$tier);
            NotificationEligibility::assertTierFLeadTime($validated,$composition,$tier);
            $retry=NotificationRetry::validatePolicy(array_values(array_filter($rules,static fn($row)=>(string)$row->rule_kind==='retry')));
            // A tier-F version may not activate before its durable instant source exists (§6.2.4(d)).
            if($tier==='F')NotificationIntegrity::tierFSourceAvailable($intent);
            $ruleSetDigest=$this->rulesDigest($rules);
            $fingerprint=$this->definitionFingerprint(
                (string)$workflow->workflow_key,(int)$version->version_number,$intent,(string)$version->audience,
                (string)$version->recipient_kind,(int)$version->template_id,(string)$version->locale,$ruleSetDigest
            );
            $now=NotificationSupport::now();
            $predecessor=$this->repository->activeVersionForWorkflow((int)$version->workflow_id,true);
            $this->repository->activateVersion($versionId,array(
                'state'=>'active','active_slot'=>NotificationRule::ACTIVE_SLOT,'intent_active_slot'=>NotificationRule::ACTIVE_SLOT,
                'rule_set_digest'=>$ruleSetDigest,'rule_frozen_at'=>$now,'definition_fingerprint'=>$fingerprint,
                'supersedes_version_id'=>$predecessor?(int)$predecessor->id:null,
                'updated_at'=>$now,'updated_by'=>$actor,
            ));
            if($predecessor)$this->repository->supersedeVersion((int)$predecessor->id,$now);
            $this->repository->updateWorkflow((int)$workflow->id,(int)$workflow->workflow_version,array('state'=>'active','active_version_id'=>$versionId),$now,$actor);
            $this->repository->insertCommand(array(
                'uid'=>NotificationSupport::uid(),'command_domain'=>NotificationRule::DOMAIN,'operation'=>'activate_version',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'workflow_id'=>(int)$workflow->id,'workflow_version_id'=>$versionId,
                'result_state'=>'active','result_id'=>$versionId,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->commit();
            return array(
                'workflow_id'=>(int)$workflow->id,'workflow_version_id'=>$versionId,'state'=>'active',
                'intent_key'=>$intent,'tier'=>$tier,'rule_set_digest'=>$ruleSetDigest,'retry_policy'=>$retry,'created'=>true,
            );
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replayActivation($winner,$payload);
            // The routing slot is the arbiter: a lost race is reported as a routing conflict, never retried.
            if(in_array($this->repository->duplicate($e),array('intent_active','workflow_active'),true))throw new \RuntimeException('intent_routing_conflict');
            throw $e;
        }
    }

    /** Supersede the active version of a workflow without naming a successor. */
    public function supersedeVersion(int $versionId,string $key):array{
        NotificationSupport::requireCapability(self::CAPABILITY);
        $actor=NotificationSupport::actor();
        $digest=NotificationSupport::keyDigest($key);
        $payload=NotificationSupport::payloadDigest(array('domain'=>NotificationRule::DOMAIN,'operation'=>'supersede_version','workflow_version_id'=>$versionId));
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$result=$this->replaySimple($winner,$payload,'superseded');$this->repository->commit();return $result;}
            $version=$this->repository->version($versionId,true);
            if(!$version||(string)$version->state!=='active')throw new \InvalidArgumentException('invalid_version_state');
            $workflow=$this->repository->workflow((int)$version->workflow_id,true);
            $now=NotificationSupport::now();
            $this->repository->supersedeVersion($versionId,$now);
            $this->repository->updateWorkflow((int)$workflow->id,(int)$workflow->workflow_version,array('active_version_id'=>null,'state'=>'draft'),$now,$actor);
            $this->repository->insertCommand(array(
                'uid'=>NotificationSupport::uid(),'command_domain'=>NotificationRule::DOMAIN,'operation'=>'supersede_version',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'workflow_id'=>(int)$workflow->id,'workflow_version_id'=>$versionId,
                'result_state'=>'superseded','result_id'=>$versionId,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->commit();
            return array('workflow_version_id'=>$versionId,'state'=>'superseded','created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replaySimple($winner,$payload,'superseded');
            throw $e;
        }
    }

    /** Retire a whole workflow: its active version is retired and both routing slots are cleared. */
    public function retireWorkflow(int $workflowId,string $key):array{
        NotificationSupport::requireCapability(self::CAPABILITY);
        $actor=NotificationSupport::actor();
        $digest=NotificationSupport::keyDigest($key);
        $payload=NotificationSupport::payloadDigest(array('domain'=>NotificationRule::DOMAIN,'operation'=>'retire_workflow','workflow_id'=>$workflowId));
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$result=$this->replaySimple($winner,$payload,'retired');$this->repository->commit();return $result;}
            $workflow=$this->repository->workflow($workflowId,true);
            if(!$workflow)throw new \InvalidArgumentException('workflow_not_found');
            $active=$this->repository->activeVersionForWorkflow($workflowId,true);
            $now=NotificationSupport::now();
            if($active)$this->repository->retireVersion((int)$active->id,$now,$actor);
            $this->repository->updateWorkflow($workflowId,(int)$workflow->workflow_version,array('state'=>'retired','active_version_id'=>null),$now,$actor);
            $this->repository->insertCommand(array(
                'uid'=>NotificationSupport::uid(),'command_domain'=>NotificationRule::DOMAIN,'operation'=>'retire_workflow',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'workflow_id'=>$workflowId,'workflow_version_id'=>$active?(int)$active->id:null,
                'result_state'=>'retired','result_id'=>$workflowId,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->commit();
            return array('workflow_id'=>$workflowId,'state'=>'retired','created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replaySimple($winner,$payload,'retired');
            throw $e;
        }
    }

    /**
     * The frozen definition fingerprint: the whole definition, including the frozen rule-set digest, so a
     * version can never be identified as an unchanged definition after any part of it moved.
     */
    private function definitionFingerprint(string $workflowKey,int $versionNumber,string $intent,string $audience,string $recipientKind,int $templateId,string $locale,string $ruleSetDigest):string{
        return hash_hmac('sha256','definition:'.$workflowKey.':'.$versionNumber.':'.$intent.':'.$audience.':'.$recipientKind.':'.$templateId.':'.$locale.':'.$ruleSetDigest,NotificationSupport::salt());
    }
    /** The ordered `(kind, code, ordinal, parameters)` tuples the digest freezes (§6.2). */
    private function rulesDigest(array $rules):string{
        $rows=array();
        foreach($rules as $row)$rows[]=array(
            'rule_kind'=>(string)$row->rule_kind,'rule_code'=>(string)$row->rule_code,'ordinal'=>(int)$row->ordinal,
            'parameter_a'=>$row->parameter_a,'parameter_b'=>$row->parameter_b,'parameter_c'=>$row->parameter_c,'parameter_d'=>$row->parameter_d,
        );
        return NotificationIntegrity::ruleSetDigest($rows);
    }
    private function replayWorkflow(object $command,string $payload):array{
        NotificationIntegrity::replayPayload((string)$command->command_payload_digest,$payload);
        $workflow=$this->repository->workflow((int)$command->result_id);
        if(!$workflow||(string)$workflow->state!==(string)$command->result_state)throw new \RuntimeException('workflow_version_integrity');
        return array('workflow_id'=>(int)$workflow->id,'workflow_key'=>(string)$workflow->workflow_key,'state'=>(string)$workflow->state,'created'=>false,'idempotent'=>true);
    }
    private function replayVersion(object $command,string $payload):array{
        NotificationIntegrity::replayPayload((string)$command->command_payload_digest,$payload);
        $version=$this->repository->version((int)$command->result_id);
        if(!$version||(string)$version->state!==(string)$command->result_state)throw new \RuntimeException('workflow_version_integrity');
        return array('workflow_id'=>(int)$version->workflow_id,'workflow_version_id'=>(int)$version->id,'version_number'=>(int)$version->version_number,'state'=>(string)$version->state,'created'=>false,'idempotent'=>true);
    }
    private function replayRule(object $command,string $payload):array{
        NotificationIntegrity::replayPayload((string)$command->command_payload_digest,$payload);
        return array('workflow_version_id'=>(int)$command->workflow_version_id,'rule_id'=>(int)$command->result_id,'state'=>(string)$command->result_state,'created'=>false,'idempotent'=>true);
    }
    private function replayActivation(object $command,string $payload):array{
        NotificationIntegrity::replayPayload((string)$command->command_payload_digest,$payload);
        $version=$this->repository->version((int)$command->result_id);
        if(!$version||(string)$version->state!=='active')throw new \RuntimeException('workflow_version_integrity');
        return array(
            'workflow_id'=>(int)$version->workflow_id,'workflow_version_id'=>(int)$version->id,'state'=>'active',
            'intent_key'=>(string)$version->intent_key,'tier'=>NotificationRule::intentTier((string)$version->intent_key),
            'rule_set_digest'=>(string)$version->rule_set_digest,'created'=>false,'idempotent'=>true,
        );
    }
    private function replaySimple(object $command,string $payload,string $state):array{
        NotificationIntegrity::replayPayload((string)$command->command_payload_digest,$payload);
        if((string)$command->result_state!==$state)throw new \RuntimeException('workflow_version_integrity');
        return array('result_id'=>(int)$command->result_id,'state'=>$state,'created'=>false,'idempotent'=>true);
    }
}
