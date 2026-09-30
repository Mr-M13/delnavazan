<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationWorkflowRepository;

/**
 * §8.4 — the capability-protected workflow read seam.
 *
 * Every version is proved against its own frozen rule set before it is returned, so a caller never reads
 * authority from a version whose routing, freeze state or rules disagree with themselves.
 */
final class NotificationWorkflowReadService {
    public function __construct(private ?NotificationWorkflowRepository $repository=null){$this->repository??=new NotificationWorkflowRepository();}
    public function workflows():array{
        NotificationSupport::requireCapability(NotificationRule::READ_CAPABILITY);
        $rows=array();
        foreach($this->repository->workflows() as $workflow)$rows[]=$this->shapeWorkflow($workflow);
        return $rows;
    }
    public function workflow(int $id):array{
        NotificationSupport::requireCapability(NotificationRule::READ_CAPABILITY);
        $workflow=$this->repository->workflow($id);
        if(!$workflow)throw new \RuntimeException('workflow_not_found');
        return $this->shapeWorkflow($workflow);
    }
    public function versions(int $workflowId):array{
        NotificationSupport::requireCapability(NotificationRule::READ_CAPABILITY);
        $rows=array();
        foreach($this->repository->versionsFor($workflowId) as $version)$rows[]=$this->shapeVersion($version);
        return $rows;
    }
    /** One validated version, including its frozen rules as digest-anchored descriptors. */
    public function version(int $id):array{
        NotificationSupport::requireCapability(NotificationRule::READ_CAPABILITY);
        $version=$this->repository->version($id);
        if(!$version)throw new \RuntimeException('workflow_not_found');
        return $this->shapeVersion($version);
    }
    /** The single active version routed to one consumed intent, or null when the intent is unroutable. */
    public function activeForIntent(string $intent):?array{
        NotificationSupport::requireCapability(NotificationRule::READ_CAPABILITY);
        if(!NotificationRule::registeredIntent($intent))throw new \InvalidArgumentException('unregistered_intent');
        $version=$this->repository->activeVersionForIntent($intent);
        return $version===null?null:$this->shapeVersion($version);
    }
    private function shapeVersion(object $version):array{
        $rules=array();
        foreach($this->repository->rulesFor((int)$version->id) as $row)$rules[]=array(
            'rule_kind'=>(string)$row->rule_kind,'rule_code'=>(string)$row->rule_code,'ordinal'=>(int)$row->ordinal,
            'parameter_a'=>$row->parameter_a,'parameter_b'=>$row->parameter_b,'parameter_c'=>$row->parameter_c,'parameter_d'=>$row->parameter_d,
        );
        NotificationIntegrity::versionIntegrity($version,$rules);
        return array(
            'workflow_version_id'=>(int)$version->id,'workflow_id'=>(int)$version->workflow_id,
            'version_number'=>(int)$version->version_number,'intent_key'=>(string)$version->intent_key,
            'audience'=>(string)$version->audience,'recipient_kind'=>(string)$version->recipient_kind,
            'template_id'=>(int)$version->template_id,'locale'=>(string)$version->locale,'state'=>(string)$version->state,
            'tier'=>NotificationRule::intentTier((string)$version->intent_key),
            'rule_set_digest'=>$version->rule_set_digest,'rule_frozen_at'=>$version->rule_frozen_at,
            'definition_fingerprint'=>(string)$version->definition_fingerprint,'rules'=>$rules,
        );
    }
    private function shapeWorkflow(object $workflow):array{
        return array(
            'workflow_id'=>(int)$workflow->id,'workflow_key'=>(string)$workflow->workflow_key,
            'purpose'=>(string)$workflow->purpose,'state'=>(string)$workflow->state,
            'active_version_id'=>$workflow->active_version_id===null?null:(int)$workflow->active_version_id,
            'workflow_version'=>(int)$workflow->workflow_version,
        );
    }
}
