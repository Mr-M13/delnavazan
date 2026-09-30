<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationTemplateRepository;

/**
 * §6.4/§8.1 — versioned, immutable template identity and rendered-snapshot freezing.
 *
 * S stores versioned template identity plus digests and the variable contract; the human-authored body
 * lives with the adapter/copy owner until a later slice, and no provider template name, id or language tag
 * is ever stored (that is Phase T).
 */
final class NotificationTemplateService {
    private const CAPABILITY='dzn_manage_notification_templates';
    public function __construct(private ?NotificationTemplateRepository $repository=null){$this->repository??=new NotificationTemplateRepository();}

    public function registerTemplate(array $input,string $key):array{
        NotificationSupport::requireCapability(self::CAPABILITY);
        $actor=NotificationSupport::actor();
        $templateKey=trim((string)($input['template_key']??''));
        if($templateKey===''||strlen($templateKey)>64)throw new \InvalidArgumentException('template_variable_mismatch');
        $digest=NotificationSupport::keyDigest($key);
        $payload=NotificationSupport::payloadDigest(array('domain'=>NotificationRule::DOMAIN,'operation'=>'register_template','template_key'=>$templateKey));
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$this->repository->commit();return array('template_id'=>(int)$winner->result_id,'created'=>false,'idempotent'=>true);}
            $now=NotificationSupport::now();
            $id=$this->repository->insertTemplate(array(
                'uid'=>NotificationSupport::uid(),'reference_code'=>null,'template_key'=>$templateKey,
                'purpose'=>substr(trim((string)($input['purpose']??'')),0,48),'locale'=>substr(trim((string)($input['locale']??'')),0,16),
                'state'=>'draft','current_version'=>0,'template_version'=>1,
                'created_at'=>$now,'updated_at'=>$now,'created_by'=>$actor,'updated_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>NotificationSupport::uid(),'command_domain'=>NotificationRule::DOMAIN,'operation'=>'register_template',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'template_id'=>$id,'result_state'=>'draft','result_id'=>$id,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->commit();
            return array('template_id'=>$id,'template_key'=>$templateKey,'state'=>'draft','created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return array('template_id'=>(int)$winner->result_id,'created'=>false,'idempotent'=>true);
            throw $e;
        }
    }

    /**
     * Register one immutable template version.
     *
     * The variable contract digest, the required-variable count and the body/subject digests are frozen
     * together: a snapshot whose allowlisted variable set disagrees with the contract fails closed with
     * `template_variable_mismatch`, never with a partially rendered message.
     */
    public function registerVersion(int $templateId,array $input,string $key):array{
        NotificationSupport::requireCapability(self::CAPABILITY);
        $actor=NotificationSupport::actor();
        foreach(array('subject_template_digest','body_template_digest') as $field){
            $value=(string)($input[$field]??'');
            if(!preg_match('/^[0-9a-f]{64}$/',$value))throw new \InvalidArgumentException('template_variable_mismatch');
        }
        // §6.4: the canonical variable contract is *derived* here — the sorted, deduplicated allowlisted
        // codes as comma-separated text, with the digest and the required count computed over exactly that
        // text. A caller-supplied digest or count that disagrees with the derived pair is a contract
        // failure, never an alternative spelling of it, so what is frozen is always the code set the
        // rendered snapshots are proved against.
        $contract=NotificationSupport::variableContract((array)($input['variable_codes']??array()));
        if(isset($input['variable_contract_digest'])&&trim((string)$input['variable_contract_digest'])!==''&&(string)$input['variable_contract_digest']!==$contract['variable_contract_digest'])throw new \InvalidArgumentException('template_variable_mismatch');
        if(isset($input['required_variable_count'])&&(int)$input['required_variable_count']!==$contract['variable_count'])throw new \InvalidArgumentException('template_variable_mismatch');
        $required=$contract['variable_count'];
        $digest=NotificationSupport::keyDigest($key);
        $payload=NotificationSupport::payloadDigest(array('domain'=>NotificationRule::DOMAIN,'operation'=>'register_template_version','template_id'=>$templateId,'body_template_digest'=>(string)$input['body_template_digest']));
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$this->repository->commit();return array('template_id'=>$templateId,'template_version_id'=>(int)$winner->result_id,'created'=>false,'idempotent'=>true);}
            $template=$this->repository->template($templateId,true);
            if(!$template||(string)$template->state==='retired')throw new \InvalidArgumentException('template_variable_mismatch');
            $now=NotificationSupport::now();
            $versionNumber=$this->repository->nextVersionNumber($templateId);
            $fingerprint=hash_hmac('sha256','template_definition:'.$templateId.':'.$versionNumber.':'.(string)$input['subject_template_digest'].':'.(string)$input['body_template_digest'].':'.$contract['variable_contract_digest'],NotificationSupport::salt());
            $id=$this->repository->insertVersion(array(
                'uid'=>NotificationSupport::uid(),'template_id'=>$templateId,'version_number'=>$versionNumber,
                'locale'=>substr(trim((string)($input['locale']??$template->locale)),0,16),
                'subject_template_digest'=>(string)$input['subject_template_digest'],'body_template_digest'=>(string)$input['body_template_digest'],
                'variable_contract_digest'=>$contract['variable_contract_digest'],'required_variable_count'=>$required,
                'variable_contract'=>$contract['variable_contract'],
                'definition_fingerprint'=>$fingerprint,'state'=>'draft','effective_from'=>$now,'supersedes_version_id'=>null,
                'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->insertCommand(array(
                'uid'=>NotificationSupport::uid(),'command_domain'=>NotificationRule::DOMAIN,'operation'=>'register_template_version',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'template_id'=>$templateId,'result_state'=>'draft','result_id'=>$id,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->commit();
            return array('template_id'=>$templateId,'template_version_id'=>$id,'version_number'=>$versionNumber,'state'=>'draft','created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return array('template_id'=>$templateId,'template_version_id'=>(int)$winner->result_id,'created'=>false,'idempotent'=>true);
            throw $e;
        }
    }

    /** Activate one draft version and supersede its predecessor in one transaction. */
    public function activateVersion(int $templateId,int $versionId,string $key):array{
        NotificationSupport::requireCapability(self::CAPABILITY);
        $actor=NotificationSupport::actor();
        $digest=NotificationSupport::keyDigest($key);
        $payload=NotificationSupport::payloadDigest(array('domain'=>NotificationRule::DOMAIN,'operation'=>'activate_template_version','template_id'=>$templateId,'template_version_id'=>$versionId));
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$this->repository->commit();return array('template_version_id'=>$versionId,'state'=>'active','created'=>false,'idempotent'=>true);}
            $template=$this->repository->template($templateId,true);
            $version=$this->repository->version($versionId,true);
            if(!$template||!$version||(int)$version->template_id!==$templateId||(string)$version->state!=='draft')throw new \InvalidArgumentException('template_variable_mismatch');
            $now=NotificationSupport::now();
            $predecessor=$this->repository->activeVersion($templateId,true);
            if($predecessor)$this->repository->supersedeVersion((int)$predecessor->id);
            $this->repository->activateVersion($versionId,array('state'=>'active','supersedes_version_id'=>$predecessor?(int)$predecessor->id:null));
            $this->repository->updateTemplate($templateId,(int)$template->template_version,array('state'=>'active','current_version'=>(int)$version->version_number),$now,$actor);
            $this->repository->insertCommand(array(
                'uid'=>NotificationSupport::uid(),'command_domain'=>NotificationRule::DOMAIN,'operation'=>'activate_template_version',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'template_id'=>$templateId,'result_state'=>'active','result_id'=>$versionId,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->commit();
            return array('template_id'=>$templateId,'template_version_id'=>$versionId,'state'=>'active','created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return array('template_version_id'=>$versionId,'state'=>'active','created'=>false,'idempotent'=>true);
            throw $e;
        }
    }

    /** Retire a template; its active version stops resolving for new enqueues. */
    public function retireTemplate(int $templateId,string $key):array{
        NotificationSupport::requireCapability(self::CAPABILITY);
        $actor=NotificationSupport::actor();
        $digest=NotificationSupport::keyDigest($key);
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$this->repository->commit();return array('template_id'=>$templateId,'state'=>'retired','created'=>false,'idempotent'=>true);}
            $template=$this->repository->template($templateId,true);
            if(!$template)throw new \InvalidArgumentException('template_variable_mismatch');
            $now=NotificationSupport::now();
            $this->repository->updateTemplate($templateId,(int)$template->template_version,array('state'=>'retired'),$now,$actor);
            $this->repository->insertCommand(array(
                'uid'=>NotificationSupport::uid(),'command_domain'=>NotificationRule::DOMAIN,'operation'=>'retire_template',
                'command_key_digest'=>$digest,'command_payload_digest'=>NotificationSupport::payloadDigest(array('domain'=>NotificationRule::DOMAIN,'operation'=>'retire_template','template_id'=>$templateId)),
                'template_id'=>$templateId,'result_state'=>'retired','result_id'=>$templateId,'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->commit();
            return array('template_id'=>$templateId,'state'=>'retired','created'=>true);
        }catch(\Throwable $e){
            $this->repository->rollback();
            throw $e;
        }
    }

    /**
     * Freeze one rendered parameter snapshot (§6.4). The parameters are stored under authenticated
     * encryption with their cipher version; reads expose only `params_digest` and `variable_codes`.
     */
    public function freezeSnapshot(int $notificationId,int $templateVersionId,array $parameters,array $variableCodes,string $locale):int{
        NotificationSupport::requireCapability(self::CAPABILITY);
        $actor=NotificationSupport::actor();
        $version=$this->repository->version($templateVersionId);
        if(!$version)throw new \InvalidArgumentException('template_variable_mismatch');
        // §6.4: the declared code set must be the frozen contract *and* the encrypted parameter keys must
        // equal it exactly, so a snapshot can never claim a required code set while carrying another (or an
        // empty) parameter map; the digest is the canonical key-ordered one the read path reproduces.
        $proof=NotificationIntegrity::renderParameters($version,$parameters);
        $codes=$variableCodes===array()?$proof['contract']['variable_codes']:array_values(array_unique(array_map('strval',$variableCodes)));
        sort($codes);
        if(implode(',',$codes)!==$proof['variable_codes'])throw new \InvalidArgumentException('template_variable_mismatch');
        $envelope=NotificationSupport::encryptEnvelope($parameters);
        if($envelope===null)throw new \RuntimeException('envelope_decrypt_failure');
        $now=NotificationSupport::now();
        return $this->repository->insertSnapshot(array(
            'uid'=>NotificationSupport::uid(),'notification_id'=>$notificationId,'sequence'=>$this->repository->nextSnapshotSequence($notificationId),
            'template_version_id'=>$templateVersionId,'supersedes_snapshot_id'=>$this->repository->effectiveSnapshot($notificationId)?(int)$this->repository->effectiveSnapshot($notificationId)->id:null,
            'params_digest'=>$proof['params_digest'],
            'variable_codes'=>$proof['variable_codes'],'variable_count'=>$proof['variable_count'],
            'rendered_params_envelope'=>$envelope['envelope'],'cipher_version'=>$envelope['cipher_version'],
            'locale'=>$locale,'timezone_basis'=>'', 'rendered_at'=>$now,'rendered_by'=>$actor,'created_at'=>$now,
        ));
    }
}
