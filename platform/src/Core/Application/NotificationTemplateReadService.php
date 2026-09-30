<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationTemplateRepository;

/**
 * §8.4 — the template read seam. Digests, the variable contract and the counts only: never a provider
 * template name, id, language tag, or a rendered parameter value.
 */
final class NotificationTemplateReadService {
    public function __construct(private ?NotificationTemplateRepository $repository=null){$this->repository??=new NotificationTemplateRepository();}
    public function template(int $id):array{
        NotificationSupport::requireCapability(NotificationRule::READ_CAPABILITY);
        $template=$this->repository->template($id);
        if(!$template)throw new \RuntimeException('template_variable_mismatch');
        $versions=array();
        foreach($this->repository->versionsFor($id) as $version)$versions[]=$this->shapeVersion($version);
        return array(
            'template_id'=>(int)$template->id,'template_key'=>(string)$template->template_key,'purpose'=>(string)$template->purpose,
            'locale'=>(string)$template->locale,'state'=>(string)$template->state,'current_version'=>(int)$template->current_version,
            'versions'=>$versions,
        );
    }
    public function version(int $id):array{
        NotificationSupport::requireCapability(NotificationRule::READ_CAPABILITY);
        $version=$this->repository->version($id);
        if(!$version)throw new \RuntimeException('template_variable_mismatch');
        return $this->shapeVersion($version);
    }
    /** The frozen snapshots of one notification: digest and the allowlisted variable codes actually used. */
    public function snapshots(int $notificationId):array{
        NotificationSupport::requireCapability(NotificationRule::READ_CAPABILITY);
        $rows=array();
        foreach($this->repository->snapshots($notificationId) as $snapshot)$rows[]=array(
            'snapshot_id'=>(int)$snapshot->id,'sequence'=>(int)$snapshot->sequence,'template_version_id'=>(int)$snapshot->template_version_id,
            'params_digest'=>(string)$snapshot->params_digest,'variable_codes'=>(string)$snapshot->variable_codes,
            'variable_count'=>(int)$snapshot->variable_count,'locale'=>(string)$snapshot->locale,
            'cipher_version'=>$snapshot->cipher_version===null?null:(string)$snapshot->cipher_version,
            'encrypted'=>(bool)($snapshot->rendered_params_envelope!==null),'rendered_at'=>(string)$snapshot->rendered_at,
        );
        return $rows;
    }
    private function shapeVersion(object $version):array{
        // §6.4: the read exposes the proved variable contract (the code set a frozen snapshot must equal)
        // alongside its digest and count, so a caller never has to re-derive what a version requires.
        $contract=NotificationIntegrity::variableContract($version);
        return array(
            'template_version_id'=>(int)$version->id,'template_id'=>(int)$version->template_id,
            'version_number'=>(int)$version->version_number,'locale'=>(string)$version->locale,'state'=>(string)$version->state,
            'subject_template_digest'=>(string)$version->subject_template_digest,'body_template_digest'=>(string)$version->body_template_digest,
            'variable_contract_digest'=>(string)$version->variable_contract_digest,'required_variable_count'=>(int)$version->required_variable_count,
            'variable_contract'=>$contract['variable_contract'],'variable_codes'=>$contract['variable_codes'],
            'definition_fingerprint'=>(string)$version->definition_fingerprint,
        );
    }
}
