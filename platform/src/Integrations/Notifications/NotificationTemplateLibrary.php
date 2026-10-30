<?php
namespace Delnavazan\Platform\Integrations\Notifications;

use Delnavazan\Platform\Core\Application\NotificationSupport;
use Delnavazan\Platform\Core\Application\NotificationTemplateService;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationTemplateRepository;

/**
 * The editable copy layer over the immutable canonical template model.
 *
 * Identity, version number, activation state, supersession and the variable contract stay in
 * `dzn_notification_templates` / `dzn_notification_template_versions` and are written only through
 * `NotificationTemplateService`. The human-authored subject and body are stored once per immutable
 * `template_version_id` in a non-autoloaded option, so a queued notification that froze version 7 can
 * always render version 7 exactly, even after version 8 is published.
 *
 * Nothing here sends. A publish creates a new version and activates it; a rollback is simply a new
 * version carrying an older body. Provider template names and language tags are configuration only and
 * never co-exist with credentials.
 */
final class NotificationTemplateLibrary {
    public const OPTION_COPY_VERSIONS='dzn_notification_copy_versions';
    public const OPTION_DRAFTS='dzn_notification_copy_drafts';
    public const OPTION_AUDIT='dzn_notification_copy_audit';
    public const OPTION_WHATSAPP='dzn_notification_whatsapp_templates';
    public const OPTION_CHANNEL_POLICY='dzn_notification_channel_policy';
    public const OPTION_SEEDED='dzn_notification_catalogue_seeded';
    public const AUDIT_LIMIT=1000;

    public function __construct(
        private ?NotificationTemplateService $service=null,
        private ?NotificationTemplateRepository $repository=null
    ){
        $this->service??=new NotificationTemplateService();
        $this->repository??=new NotificationTemplateRepository();
    }

    /** The active copy of one event/channel/locale, or the catalogue default when none is published. */
    public function activeCopy(string $eventKey,string $channel,string $locale):?array{
        $template=$this->identity($eventKey,$channel,$locale);
        if($template){
            $version=$this->repository->activeVersion((int)$template->id);
            if($version){
                $copy=$this->versionCopy((int)$version->id);
                if($copy!==null)return array_merge($copy,array('source'=>'published','template_id'=>(int)$template->id,'template_version_id'=>(int)$version->id,'version_number'=>(int)$version->version_number));
            }
        }
        $default=NotificationCatalogue::defaultCopy($eventKey,$channel,$locale);
        return $default===null?null:array_merge($default,array('source'=>'default','template_id'=>null,'template_version_id'=>null,'version_number'=>null));
    }

    /** The copy a canonical dispatch should render for one frozen template version. */
    public function copyForVersion(int $versionId):?array{
        $copy=$this->versionCopy($versionId);
        if($copy===null)return null;
        return array('subject'=>(string)($copy['subject']??''),'body'=>(string)($copy['body']??''));
    }

    /**
     * The copy port definitions consumed by the canonical runtime: only the active version of each
     * template, keyed exactly as `ConfiguredNotificationCopy` expects.
     *
     * @return array<string,array{subject:string,body:string}>
     */
    public function copyDefinitions():array{
        if(!function_exists('get_option'))return array();
        $bodies=$this->versionBodies();
        if($bodies===array())return array();
        global $wpdb;
        $prefix=$wpdb->prefix.'dzn_';
        $rows=$wpdb->get_results("SELECT t.template_key,v.id version_id FROM {$prefix}notification_templates t INNER JOIN {$prefix}notification_template_versions v ON v.template_id=t.id AND v.state='active'");
        if(!is_array($rows))return array();
        $definitions=array();
        foreach($rows as $row){
            $versionId=(int)$row->version_id;
            if(!isset($bodies[$versionId]))continue;
            $parts=explode('.',(string)$row->template_key);
            if(count($parts)<3)continue;
            $locale=array_pop($parts);$channel=array_pop($parts);
            $definitions[$versionId.':'.$channel.':'.$locale]=array('subject'=>(string)$bodies[$versionId]['subject'],'body'=>(string)$bodies[$versionId]['body']);
        }
        return $definitions;
    }

    /** @return array<string,array> One row per event/channel/locale the catalogue supports. */
    public function catalogueRows(?string $channel=null,?string $locale=null):array{
        $locales=$locale===null?NotificationCatalogue::SUPPORTED_LOCALES:array(NotificationCatalogue::normaliseLocale($locale));
        $rows=array();
        foreach(NotificationCatalogue::events() as $eventKey=>$event){
            foreach((array)$event['channels'] as $eventChannel){
                if($channel!==null&&$eventChannel!==$channel)continue;
                // A channel the category policy forbids is not an applicable delivery choice. Its
                // default copy may stay in the catalogue as a reserved fallback, but it must not be
                // advertised in the workspace or produce actionable readiness warnings.
                if(!$this->channelPermitted($eventKey,$eventChannel))continue;
                foreach($locales as $eventLocale){
                    $rows[]=$this->status($eventKey,$eventChannel,$eventLocale);
                }
            }
        }
        return $rows;
    }

    /** Whether an event's category policy currently permits this delivery channel. */
    public function channelPermitted(string $eventKey,string $channel):bool{
        $event=NotificationCatalogue::get($eventKey);
        if($event===null||!in_array($channel,(array)$event['channels'],true))return false;
        $policy=$this->channelPolicy((string)$event['category']);
        return !empty($policy[$channel]);
    }

    /**
     * Channels an editor may show for one event, in delivery preference order. Email and portal are
     * defaults where the catalogue offers them; WhatsApp and SMS remain opt-in and policy-gated.
     *
     * @return string[]
     */
    public function permittedChannels(string $eventKey):array{
        $event=NotificationCatalogue::get($eventKey);
        if($event===null)return array();
        $order=array('website','email','whatsapp','sms');
        $channels=array();
        foreach($order as $channel)if($this->channelPermitted($eventKey,$channel))$channels[]=$channel;
        return $channels;
    }

    /** Configuration, version and provider-mapping status for one event/channel/locale. */
    public function status(string $eventKey,string $channel,string $locale):array{
        $locale=NotificationCatalogue::normaliseLocale($locale);
        $event=NotificationCatalogue::get($eventKey)??array();
        $templateKey=NotificationCatalogue::templateKey($eventKey,$channel,$locale);
        $template=$this->identity($eventKey,$channel,$locale);
        $active=$template?$this->repository->activeVersion((int)$template->id):null;
        $activeBody=$active?$this->versionCopy((int)$active->id):null;
        $default=NotificationCatalogue::defaultCopy($eventKey,$channel,$locale);
        $issues=array();
        if(!$template){
            // A catalogue default is a usable, reviewed fallback for the operational queues. It is only a
            // configuration gap when there is no default at all, or when the canonical authority (which
            // renders strictly from a frozen version) is expected to dispatch this event.
            if(!is_array($default))$issues[]='No default copy and no published version exist.';
            elseif((string)($event['authority']??'')==='canonical')$issues[]='A canonical event cannot be dispatched without a published version.';
        }elseif(!$active)$issues[]='No active version exists for this template.';
        elseif($activeBody===null)$issues[]='The active version body is unavailable; publish again.';
        $permitted=$this->channelPermitted($eventKey,$channel);
        $mapping=$this->providerMapping($templateKey);
        $providerState='not_applicable';
        if($channel==='whatsapp'){
            if($mapping['name']===''){$providerState='not_mapped';$issues[]='No Meta-approved template is mapped. Business-initiated sending will not start.';}
            elseif($activeBody!==null&&!hash_equals((string)$mapping['body_digest'],$this->copyDigest((string)$activeBody['body']))){
                $providerState='approval_required';
                $issues[]='Local copy changed after the last mapping; the template must be reviewed and approved again in Meta.';
            }else{$providerState='mapped';}
        }elseif($channel==='sms'){
            $providerState='restricted_fallback';
        }
        if(!$permitted){
            // A forbidden channel is not a readiness failure of the message or provider. Saying that
            // WhatsApp needs a mapping (or that canonical copy is missing) for a security-only event is
            // misleading, because no send on that channel can ever be authorised by the current policy.
            // The provider state stays factual (for example not_mapped); only the actionable warning is
            // replaced by the policy explanation.
            $issues=array('Not offered for this event: the '.(string)(NotificationCatalogue::CATEGORIES[(string)($event['category']??'')]['label']??'current').' category disables this channel.');
        }
        return array(
            'event_key'=>$eventKey,'label'=>(string)($event['label']??$eventKey),'category'=>(string)($event['category']??''),
            'channel'=>$channel,'locale'=>$locale,'template_key'=>$templateKey,
            'template_id'=>$template?(int)$template->id:null,
            'active_version_id'=>$active?(int)$active->id:null,'active_version_number'=>$active?(int)$active->version_number:null,
            'active_state'=>$template?(string)$template->state:'unregistered',
            'published'=>is_array($activeBody),'has_default'=>is_array($default),
            'draft'=>$this->draft($eventKey,$channel,$locale),
            'permitted'=>$permitted,
            'provider_state'=>$providerState,'issues'=>array_values(array_unique($issues)),
            'updated_at'=>$activeBody['saved_at']??null,
        );
    }

    /** Recommended copy for the editor: the active version, else the saved draft, else the default. */
    public function workingCopy(string $eventKey,string $channel,string $locale):array{
        $active=$this->activeCopy($eventKey,$channel,$locale);
        $draft=$this->draft($eventKey,$channel,$locale);
        $default=NotificationCatalogue::defaultCopy($eventKey,$channel,$locale);
        return array(
            'active'=>$active,
            'draft'=>$draft,
            'default'=>$default,
            'editable'=>$draft??$active??$default??array('subject'=>'','body'=>''),
            'source'=>$draft!==null?'draft':(($active['source']??'')==='published'?'published':'default'),
        );
    }

    public function saveDraft(string $eventKey,string $channel,string $locale,string $subject,string $body):array{
        $locale=NotificationCatalogue::normaliseLocale($locale);
        $validated=NotificationTemplateSanitizer::validate($eventKey,$channel,$subject,$body);
        if(!$validated['ok'])throw new NotificationTemplateValidationException($validated['errors']);
        $key=$this->draftKey($eventKey,$channel,$locale);
        $drafts=$this->option(self::OPTION_DRAFTS);
        $drafts[$key]=array('event_key'=>$eventKey,'channel'=>$channel,'locale'=>$locale,'subject'=>$validated['subject'],'body'=>$validated['body'],'updated_at'=>$this->now(),'updated_by'=>$this->actor());
        $this->putOption(self::OPTION_DRAFTS,$drafts);
        $this->audit('save_draft',$eventKey,$channel,$locale,null,'Draft saved');
        return array('ok'=>true,'draft'=>$drafts[$key]);
    }

    public function discardDraft(string $eventKey,string $channel,string $locale):array{
        $key=$this->draftKey($eventKey,$channel,$locale);
        $drafts=$this->option(self::OPTION_DRAFTS);
        if(isset($drafts[$key])){unset($drafts[$key]);$this->putOption(self::OPTION_DRAFTS,$drafts);$this->audit('discard_draft',$eventKey,$channel,$locale,null,'Draft discarded');}
        return array('ok'=>true);
    }

    /**
     * Publish a new immutable version and activate it. A queued notification keeps the exact version it
     * already froze, so this only changes message copy for future observations.
     */
    public function publish(string $eventKey,string $channel,string $locale,string $subject,string $body,string $source='authored',?int $restoredFrom=null,string $correlation=''):array{
        $locale=NotificationCatalogue::normaliseLocale($locale);
        $validated=NotificationTemplateSanitizer::validate($eventKey,$channel,$subject,$body);
        if(!$validated['ok'])throw new NotificationTemplateValidationException($validated['errors']);
        $templateKey=NotificationCatalogue::templateKey($eventKey,$channel,$locale);
        $subjectDigest=$this->copyDigest($validated['subject']);
        $bodyDigest=$this->copyDigest($validated['body']);
        $variables=NotificationCatalogue::variables($eventKey);
        $contentKey=static fn(string $suffix):string=>hash('sha256',$templateKey.'|'.$subjectDigest.'|'.$bodyDigest.'|'.implode(',',$variables).'|'.$correlation.$suffix);
        $template=$this->identity($eventKey,$channel,$locale);
        if(!$template){
            $registered=$this->service->registerTemplate(array(
                'template_key'=>$templateKey,'purpose'=>mb_substr((string)(NotificationCatalogue::get($eventKey)['label']??$eventKey),0,48),'locale'=>$locale,
            ),'notification-template-register:'.$templateKey);
            $templateId=(int)$registered['template_id'];
        }else{$templateId=(int)$template->id;}
        $register=function(string $suffix)use($templateId,$subjectDigest,$bodyDigest,$variables,$locale,$templateKey,$contentKey):array{
            return $this->service->registerVersion($templateId,array(
                'subject_template_digest'=>$subjectDigest,'body_template_digest'=>$bodyDigest,
                'variable_codes'=>$variables,'locale'=>$locale,
            ),'notification-template-publish:'.$templateKey.':'.$contentKey($suffix));
        };
        $version=$register('');
        $versionId=(int)$version['template_version_id'];
        $this->storeVersionBody($versionId,array(
            'event_key'=>$eventKey,'channel'=>$channel,'locale'=>$locale,
            'subject'=>$validated['subject'],'body'=>$validated['body'],
            'source'=>$source,'restored_from'=>$restoredFrom,'saved_at'=>$this->now(),'saved_by'=>$this->actor(),
        ));
        $existing=$this->repository->version($versionId);
        if($existing&&(string)$existing->state==='active'){
            // A repeated submit of the copy that is already active is a successful no-op, not a second
            // activation attempt of a non-draft version.
            return array('ok'=>true,'template_id'=>$templateId,'template_version_id'=>$versionId,'version_number'=>(int)$existing->version_number,'state'=>'active','idempotent'=>true);
        }
        if(!$existing||(string)$existing->state!=='draft'){
            // This exact content already exists as a superseded/retired version (for example after a
            // rollback). Re-publishing identical wording is a new intent, so register a fresh version.
            $version=$register(':republish:'.gmdate('YmdHis'));
            $versionId=(int)$version['template_version_id'];
            $this->storeVersionBody($versionId,array(
                'event_key'=>$eventKey,'channel'=>$channel,'locale'=>$locale,
                'subject'=>$validated['subject'],'body'=>$validated['body'],
                'source'=>$source,'restored_from'=>$restoredFrom,'saved_at'=>$this->now(),'saved_by'=>$this->actor(),
            ));
        }
        $activated=$this->service->activateVersion($templateId,$versionId,'notification-template-activate:'.$templateKey.':'.$versionId);
        $key=$this->draftKey($eventKey,$channel,$locale);
        $drafts=$this->option(self::OPTION_DRAFTS);
        if(isset($drafts[$key])){unset($drafts[$key]);$this->putOption(self::OPTION_DRAFTS,$drafts);}
        $this->audit($source==='rollback'?'rollback_publish':'publish',$eventKey,$channel,$locale,$versionId,'Version '.($version['version_number']??'?').' published and active');
        return array('ok'=>true,'template_id'=>$templateId,'template_version_id'=>$versionId,'version_number'=>(int)($version['version_number']??0),'state'=>(string)($activated['state']??'active'),'idempotent'=>(bool)($version['idempotent']??false));
    }

    /** Re-publish an older body as a new active version; the older version itself is never mutated. */
    public function restoreVersion(string $eventKey,string $channel,string $locale,int $versionId):array{
        $copy=$this->versionCopy($versionId);
        if($copy===null)throw new \InvalidArgumentException('The selected version has no stored copy.');
        // A rollback is a new publish intent, so it must not collapse onto the older content's
        // idempotency key; the minute-stable correlation makes a double click converge.
        return $this->publish($eventKey,$channel,$locale,(string)$copy['subject'],(string)$copy['body'],'rollback',$versionId,'restore:'.$versionId.':'.gmdate('YmdHi'));
    }

    public function restoreDefault(string $eventKey,string $channel,string $locale):array{
        $default=NotificationCatalogue::defaultCopy($eventKey,$channel,$locale);
        if($default===null)throw new \InvalidArgumentException('No default copy is defined for this event and channel.');
        return $this->publish($eventKey,$channel,$locale,(string)($default['subject']??''),(string)($default['body']??''),'default',null,'default:'.gmdate('YmdHi'));
    }

    /** @return array<int,array> Newest first, with the body only when it is stored for that version. */
    public function history(string $eventKey,string $channel,string $locale):array{
        $template=$this->identity($eventKey,$channel,$locale);
        if(!$template)return array();
        $rows=array();
        foreach($this->repository->versionsFor((int)$template->id) as $version){
            $copy=$this->versionCopy((int)$version->id);
            $rows[]=array(
                'template_version_id'=>(int)$version->id,'version_number'=>(int)$version->version_number,
                'state'=>(string)$version->state,'locale'=>(string)$version->locale,
                'created_at'=>(string)$version->created_at,'created_by'=>(int)$version->created_by,
                'supersedes_version_id'=>$version->supersedes_version_id===null?null:(int)$version->supersedes_version_id,
                'body_present'=>$copy!==null,'subject'=>$copy['subject']??null,'body'=>$copy['body']??null,
                'source'=>$copy['source']??null,'restored_from'=>$copy['restored_from']??null,
                'subject_digest'=>(string)$version->subject_template_digest,'body_digest'=>(string)$version->body_template_digest,
                'variable_contract'=>(string)($version->variable_contract??''),
            );
        }
        usort($rows,static fn(array $a,array $b):int=>$b['version_number']<=>$a['version_number']);
        return $rows;
    }

    /**
     * Preview with synthetic values only. The rendered HTML is escaped by the branded shell.
     *
     * Preview never sends and never accepts a caller-supplied one-time secret: the code placeholders are
     * always replaced with a visible synthetic marker, even when a caller passes variables directly.
     */
    public function preview(string $eventKey,string $channel,string $subject,string $body,string $locale,array $variables=array()):array{
        $synthetic=NotificationCatalogue::previewVariables($eventKey);
        $variables=$variables===array()?$synthetic:array_merge($synthetic,$variables);
        foreach(array('claim_code','invitation_code') as $secret){
            if(in_array($secret,NotificationCatalogue::variables($eventKey),true))$variables[$secret]='[one-time code]';
        }
        $replace=static function(string $text)use($variables):string{
            return preg_replace_callback('/\{\{([a-z][a-z0-9_]{0,63})\}\}/',static function(array $m)use($variables):string{
                return array_key_exists($m[1],$variables)?(string)$variables[$m[1]]:'['.$m[1].']';
            },$text);
        };
        $renderedSubject=$replace($subject);$renderedBody=$replace($body);
        if($channel==='sms'){
            $segments=NotificationTemplateSanitizer::smsSegmentation($renderedBody);
            return array('subject'=>'','text'=>$renderedBody,'html'=>'','segments'=>$segments);
        }
        if($channel!=='email'){
            return array('subject'=>$renderedSubject,'text'=>$renderedBody,'html'=>'','segments'=>null);
        }
        $cta=$this->ctaFor($eventKey,$body,$variables,(string)(NotificationCatalogue::get($eventKey)['audience']??'student'),$locale);
        $rendered=DelnavazanEmailRenderer::render($renderedSubject,$renderedBody,array(
            'locale'=>$locale,'cta'=>$cta,'preheader'=>$renderedSubject,
            'footer_note'=>NotificationCatalogue::previewNotice($locale),
        ));
        return array('subject'=>$rendered['subject'],'text'=>$rendered['text'],'html'=>$rendered['html'],'segments'=>null);
    }

    /** The one primary action of an event, derived from the simulated variables of an action placeholder. */
    private function ctaFor(string $eventKey,string $body,array $variables,string $audience,string $locale):?array{
        foreach(array('portal_url','teacher_portal_url','claim_url','payment_url') as $code){
            if(!str_contains($body,'{{'.$code.'}}')||!isset($variables[$code]))continue;
            $label=NotificationCatalogue::normaliseLocale($locale)==='en'?($audience==='teacher'?'Open Teacher Portal':($audience==='admin'?'Open Delnavazan Admin':'Open Student Portal')):($audience==='teacher'?'رفتن به پرتال مدرس':($audience==='admin'?'رفتن به پیشخوان دلنوازان':'رفتن به پرتال هنرجو'));
            return array('label'=>$label,'url'=>(string)$variables[$code]);
        }
        return null;
    }

    // ------------------------------------------------------------------ provider mapping

    public function providerMapping(string $templateKey):array{
        $map=$this->option(self::OPTION_WHATSAPP);
        $row=is_array($map[$templateKey]??null)?$map[$templateKey]:array();
        return array(
            'name'=>(string)($row['name']??''),'language'=>(string)($row['language']??''),
            'body_digest'=>(string)($row['body_digest']??''),'mapped_at'=>$row['mapped_at']??null,
            'mapped_by'=>$row['mapped_by']??null,
        );
    }

    public function saveProviderMapping(string $eventKey,string $channel,string $locale,string $name,string $language):array{
        if($channel!=='whatsapp')throw new \InvalidArgumentException('Provider template mapping applies to WhatsApp only.');
        $name=trim($name);$language=trim($language);
        if($name==='')throw new \InvalidArgumentException('A provider template ID is required.');
        if(preg_match('/^[a-z0-9_]{1,191}$/',$name)!==1)throw new \InvalidArgumentException('The template ID may contain lowercase Latin letters, digits and underscores only.');
        if(preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/',$language)!==1)throw new \InvalidArgumentException('The template language code is invalid; examples: fa or en_US.');
        $templateKey=NotificationCatalogue::templateKey($eventKey,$channel,$locale);
        $active=$this->activeCopy($eventKey,$channel,$locale);
        $map=$this->option(self::OPTION_WHATSAPP);
        $map[$templateKey]=array(
            'name'=>$name,'language'=>$language,
            'body_digest'=>is_array($active)?$this->copyDigest((string)$active['body']):'',
            'mapped_at'=>$this->now(),'mapped_by'=>$this->actor(),
        );
        $this->putOption(self::OPTION_WHATSAPP,$map);
        $this->audit('map_provider_template',$eventKey,$channel,NotificationCatalogue::normaliseLocale($locale),null,'WhatsApp template mapping saved; Meta approval is still required separately');
        return array('ok'=>true,'mapping'=>$map[$templateKey]);
    }

    // ------------------------------------------------------------------ channel policy

    /** The effective, non-secret channel policy of one category. Mandatory channels stay locked. */
    public function channelPolicy(string $category):array{
        $defaults=$this->defaultChannelPolicy($category);
        $saved=$this->option(self::OPTION_CHANNEL_POLICY);
        $row=is_array($saved[$category]??null)?$saved[$category]:array();
        $effective=array_merge($defaults,$row);
        if(in_array($category,array('security','account','payment'),true)){
            $effective['email']=true;$effective['website']=true;$effective['mandatory']=true;
        }
        $effective['category']=$category;
        return $effective;
    }

    /** @return array<string,array> */
    public function channelPolicies():array{
        $rows=array();
        foreach(array_keys(NotificationCatalogue::CATEGORIES) as $category)$rows[$category]=$this->channelPolicy($category);
        return $rows;
    }

    /** @param array<string,array> $input category => flags */
    public function saveChannelPolicy(array $input):array{
        $saved=$this->option(self::OPTION_CHANNEL_POLICY);
        foreach(NotificationCatalogue::CATEGORIES as $category=>$meta){
            if(!isset($input[$category])||!is_array($input[$category]))continue;
            $flags=array();
            foreach(array('email','whatsapp','sms','website','sms_fallback') as $channel)$flags[$channel]=!empty($input[$category][$channel]);
            if(in_array((string)$meta['policy'],array('security','essential','financial'),true)){
                $flags['email']=true;$flags['website']=true;
                if((string)$meta['policy']==='security'){$flags['whatsapp']=false;$flags['sms']=false;$flags['sms_fallback']=false;}
            }
            $saved[$category]=$flags;
        }
        $this->putOption(self::OPTION_CHANNEL_POLICY,$saved);
        $this->audit('save_channel_policy','','','',null,'Channel rules updated');
        return $this->channelPolicies();
    }

    private function defaultChannelPolicy(string $category):array{
        $policy=(string)(NotificationCatalogue::CATEGORIES[$category]['policy']??'operational');
        return match($policy){
            'security'=>array('email'=>true,'website'=>true,'whatsapp'=>false,'sms'=>false,'sms_fallback'=>false,'mandatory'=>true),
            'essential'=>array('email'=>true,'website'=>true,'whatsapp'=>false,'sms'=>false,'sms_fallback'=>false,'mandatory'=>true),
            'financial'=>array('email'=>true,'website'=>true,'whatsapp'=>false,'sms'=>false,'sms_fallback'=>false,'mandatory'=>true),
            'operational'=>array('email'=>true,'website'=>true,'whatsapp'=>false,'sms'=>false,'sms_fallback'=>false,'mandatory'=>true),
            default=>array('email'=>true,'website'=>true,'whatsapp'=>false,'sms'=>false,'sms_fallback'=>false,'mandatory'=>false),
        };
    }

    // ------------------------------------------------------------------ audit

    public function auditLog(int $limit=50):array{
        $log=$this->option(self::OPTION_AUDIT);
        return array_slice(array_reverse(array_values($log)),0,max(1,$limit));
    }

    // ------------------------------------------------------------------ internals

    public function identity(string $eventKey,string $channel,string $locale):?object{
        $key=NotificationCatalogue::templateKey($eventKey,$channel,$locale);
        return $this->repository->templateByKey($key);
    }

    private function draft(string $eventKey,string $channel,string $locale):?array{
        $drafts=$this->option(self::OPTION_DRAFTS);
        $row=$drafts[$this->draftKey($eventKey,$channel,$locale)]??null;
        return is_array($row)?$row:null;
    }

    private function draftKey(string $eventKey,string $channel,string $locale):string{
        return NotificationCatalogue::templateKey($eventKey,$channel,$locale);
    }

    private function storeVersionBody(int $versionId,array $body):void{
        $bodies=$this->option(self::OPTION_COPY_VERSIONS);
        if(isset($bodies[$versionId])&&!hash_equals((string)$bodies[$versionId]['body'],(string)$body['body']))throw new \RuntimeException('A template version is immutable and its body cannot be rewritten.');
        if(isset($bodies[$versionId]))return;
        $bodies[$versionId]=$body;
        $this->putOption(self::OPTION_COPY_VERSIONS,$bodies);
    }

    private function versionCopy(int $versionId):?array{
        $bodies=$this->versionBodies();
        $row=$bodies[$versionId]??null;
        return is_array($row)?$row:null;
    }
    /** @return array<int,array> */
    private function versionBodies():array{return $this->option(self::OPTION_COPY_VERSIONS);}

    public function copyDigest(string $text):string{
        return hash_hmac('sha256','template_copy:'.$text,NotificationSupport::salt());
    }

    private function option(string $key):array{
        if(!function_exists('get_option'))return array();
        $value=get_option($key,array());
        return is_array($value)?$value:array();
    }
    private function putOption(string $key,array $value):void{
        if(function_exists('update_option'))update_option($key,$value,false);
    }
    private function audit(string $action,string $eventKey,string $channel,string $locale,?int $versionId,string $detail):void{
        $log=$this->option(self::OPTION_AUDIT);
        $log[]=array(
            'at'=>$this->now(),'actor'=>$this->actor(),'action'=>$action,'event_key'=>$eventKey,
            'channel'=>$channel,'locale'=>$locale,'template_version_id'=>$versionId,'detail'=>$detail,
        );
        if(count($log)>self::AUDIT_LIMIT)$log=array_slice($log,-self::AUDIT_LIMIT);
        $this->putOption(self::OPTION_AUDIT,$log);
    }
    private function now():string{return gmdate('Y-m-d H:i:s');}
    private function actor():int{return function_exists('get_current_user_id')?(int)get_current_user_id():0;}
}
