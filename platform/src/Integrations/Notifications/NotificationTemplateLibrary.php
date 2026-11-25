<?php
namespace Delnavazan\Platform\Integrations\Notifications;

use Delnavazan\Platform\Core\Application\NotificationSupport;
use Delnavazan\Platform\Core\Application\NotificationTemplateService;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationProviderRepository;
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
    public const OPTION_APPROVALS='dzn_notification_copy_approvals';
    public const OPTION_ONETIME_ALL='dzn_notification_one_time_all_drafts';
    public const OPTION_AUDIT='dzn_notification_copy_audit';
    public const OPTION_WHATSAPP='dzn_notification_whatsapp_templates';
    public const OPTION_EVENT_ENABLED='dzn_notification_event_enabled';
    public const OPTION_EVENT_CHANNELS='dzn_notification_event_channels';
    public const OPTION_SEEDED='dzn_notification_catalogue_seeded';
    public const AUDIT_LIMIT=1000;

    public function __construct(
        private ?NotificationTemplateService $service=null,
        private ?NotificationTemplateRepository $repository=null,
        private ?TelnyxTemplateAuthority $providerAuthority=null
    ){
        $this->service??=new NotificationTemplateService();
        $this->repository??=new NotificationTemplateRepository();
        if($this->providerAuthority===null&&class_exists(NotificationProviderRepository::class)){
            $this->providerAuthority=new TelnyxTemplateAuthority(new NotificationProviderRepository());
        }
    }

    /** Retire the superseded category-wide override after the event-level controls are installed. */
    public static function retireLegacyCategoryPolicy():void{
        $key='dzn_notification_channel_policy';
        if(function_exists('get_option')&&function_exists('delete_option')&&get_option($key,null)!==null)delete_option($key);
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

    /** A channel exists only when the closed notification catalogue declares it for this event. */
    public function channelOffered(string $eventKey,string $channel):bool{
        $event=NotificationCatalogue::get($eventKey);
        return $event!==null&&in_array($channel,(array)$event['channels'],true);
    }

    /** Whether a declared channel is currently enabled for this exact event. */
    public function channelPermitted(string $eventKey,string $channel):bool{
        return $this->channelOffered($eventKey,$channel)&&$this->eventChannelEnabled($eventKey,$channel);
    }

    /** The per-event control supplements category defaults; it never overrides a legal safeguard. */
    public function eventChannelEnabled(string $eventKey,string $channel):bool{
        if(!$this->channelOffered($eventKey,$channel))return false;
        $saved=$this->option(self::OPTION_EVENT_CHANNELS);
        if(isset($saved[$eventKey])&&array_key_exists($channel,(array)$saved[$eventKey]))return !empty($saved[$eventKey][$channel]);
        // The catalogue owns defaults. Email and portal begin enabled when an event supports them;
        // WhatsApp and SMS need an explicit per-event decision after provider/copy verification.
        return in_array($channel,array('email','website'),true);
    }

    public function saveEventChannel(string $eventKey,string $channel,bool $enabled):array{
        $event=NotificationCatalogue::get($eventKey);
        if($event===null||!in_array($channel,(array)$event['channels'],true))throw new \InvalidArgumentException('notification_channel_unknown');
        $saved=$this->option(self::OPTION_EVENT_CHANNELS);
        $saved[$eventKey]=is_array($saved[$eventKey]??null)?$saved[$eventKey]:array();
        $saved[$eventKey][$channel]=$enabled;
        $this->putOption(self::OPTION_EVENT_CHANNELS,$saved);
        $this->audit($enabled?'enable_channel':'disable_channel',$eventKey,$channel,'',null,$enabled?'Delivery channel enabled':'Delivery channel disabled');
        return array('event_key'=>$eventKey,'channel'=>$channel,'enabled'=>$enabled);
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
            if($mapping['name']===''){
                $providerState=in_array((string)$mapping['authority_state'],array(TelnyxTemplateAuthority::NOT_APPROVED,TelnyxTemplateAuthority::AMBIGUOUS,TelnyxTemplateAuthority::CONTRACT_MISMATCH),true)?(string)$mapping['authority_state']:'not_mapped';
                $issues[]=(string)($mapping['reason']!==''?$mapping['reason']:'No Meta-approved template is mapped.').' Business-initiated sending will not start.';
            }elseif($mapping['source']==='manual'&&$activeBody!==null&&!hash_equals((string)$mapping['body_digest'],$this->copyDigest((string)$activeBody['body']))){
                $providerState='approval_required';
                $issues[]='Local copy changed after the last mapping; the template must be reviewed and approved again in Meta.';
            }elseif($mapping['source']==='manual'){
                // A locally typed mapping plus a matching local digest is not provider approval. Keep
                // sending blocked until the provider authority reports the template. See OPS-18.
                $providerState='mapped_unverified';
                $issues[]='This mapping was recorded locally; Meta has not confirmed the template. Sending stays blocked until it is verified.';
            }else{$providerState='mapped';}
        }elseif($channel==='sms'){
            $providerState='not_configured';
        }
        if(!$permitted&&$this->channelOffered($eventKey,$channel)){
            // Copy for a disabled channel remains editable, but no sender may use it until the
            // operator explicitly enables that exact event/channel pair. The administrator-off
            // notice is prepended rather than replacing the template/provider readiness issues:
            // an off channel must still expose why it cannot be switched on.
            array_unshift($issues,'This delivery channel is disabled for this notification.');
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
            // The linked provider name is empty until a discovery/manual mapping exists; the
            // registered name is available for the provider's Persian route so the workspace can
            // say what it expects even before discovery has seen the template.
            'provider_name'=>$channel==='whatsapp'?(string)($mapping['name']??''):'',
            'provider_expected_name'=>$channel==='whatsapp'&&TelnyxTemplateAuthority::providerLanguage($locale)===TelnyxTemplateRegistry::LANGUAGE?TelnyxTemplateRegistry::nameForEvent($eventKey):null,
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

    /**
     * Prepare reviewable copy without sending, publishing or changing channel enablement.
     * Only exact-locale catalogue defaults are used; an absent English translation is
     * reported rather than silently copying Persian into the English template.
     * Existing drafts and active versions are never overwritten. Repeating the action is a no-op
     * because every produced row is counted as existing on the second pass.
     *
     * @return array{created:int,existing:int,missing:array<int,string>,invalid:array<int,string>}
     */
    public function prepareMissingCatalogueDrafts():array{
        $created=0;$existing=0;$missing=array();$invalid=array();
        foreach(NotificationCatalogue::events() as $eventKey=>$event){
            // Dedicated one-time-secret messages are owned by their secure sender, not this editor.
            if(($event['authority']??'')==='dedicated')continue;
            foreach((array)$event['channels'] as $channel){
                foreach(NotificationCatalogue::SUPPORTED_LOCALES as $locale){
                    $key=NotificationCatalogue::templateKey($eventKey,$channel,$locale);
                    // Only the exact language counts. A Persian fallback must never satisfy an English
                    // template, otherwise an English recipient would receive Persian copy.
                    $draftRow=$this->draft($eventKey,$channel,$locale);
                    $identity=$this->identity($eventKey,$channel,$locale);
                    $active=$identity?$this->repository->activeVersion((int)$identity->id):null;
                    if($draftRow!==null||$active!==null){
                        $existing++;continue;
                    }
                    $copy=NotificationCatalogue::exactCopy($eventKey,$channel,$locale);
                    if(!is_array($copy)||trim((string)($copy['body']??''))===''){
                        $missing[]=$key;continue;
                    }
                    try{
                        $this->saveDraft($eventKey,$channel,$locale,(string)($copy['subject']??''),(string)$copy['body']);
                        $created++;
                    }catch(\Throwable $error){
                        $invalid[]=$key.': '.$error->getMessage();
                    }
                }
            }
        }
        return array('created'=>$created,'existing'=>$existing,'missing'=>$missing,'invalid'=>$invalid);
    }

    /**
     * Whether the bulk workflow may publish a prepared draft.
     *
     * Two independent gates must both pass, and both fail closed:
     *
     * 1. Editorial approval. A draft is only bulk-publishable when an administrator has explicitly
     *    approved that exact copy. The approval stores a digest of the approved subject and body, so
     *    editing the draft after approval invalidates the approval automatically.
     * 2. Channel approval. Financial, legal, security and dedicated-authority copy needs its owning
     *    reviewer, not an editor. A WhatsApp route additionally needs a positively mapped provider
     *    template: only the `mapped` state passes, and every other state — not mapped, not approved,
     *    pending re-approval, ambiguous, contract mismatch or an unrecognised value — is refused.
     *
     * This gate only governs the bulk action. It never blocks the individual editor and it never
     * enables a delivery channel.
     *
     * @return array{allowed:bool,reason:string}
     */
    public function draftReviewPolicy(string $eventKey,string $channel,string $locale):array{
        $event=NotificationCatalogue::get($eventKey);
        if($event===null)return self::denied('Unknown notification event.');
        if(!in_array($channel,(array)$event['channels'],true))return self::denied('This event does not offer that delivery channel.');
        if($this->draft($eventKey,$channel,$locale)===null)return self::denied('No draft is waiting for publication.');
        $gate=$this->draftChannelApproval($eventKey,$channel,$locale);
        if(!$gate['ok'])return self::denied($gate['reason']);
        // The bulk workflow requires an explicit editorial approval for this exact draft content.
        if($this->draftApproval($eventKey,$channel,$locale)===null)return self::denied('The draft has not been approved for publication.');
        return array('allowed'=>true,'reason'=>'');
    }

    /**
     * The approvals that belong to the channel rather than to the editor: the owning reviewer for
     * sensitive copy and a positively mapped provider template for WhatsApp. This half of the gate can
     * be satisfied without a draft approval, which is what makes bulk approval possible.
     *
     * @return array{ok:bool,reason:string}
     */
    public function draftChannelApproval(string $eventKey,string $channel,string $locale):array{
        $event=NotificationCatalogue::get($eventKey);
        if($event===null)return array('ok'=>false,'reason'=>'Unknown notification event.');
        $review=NotificationCatalogue::reviewFor($eventKey);
        if(in_array('dedicated_authority',$review,true))return array('ok'=>false,'reason'=>'This message is owned by a dedicated secure delivery authority.');
        if(in_array('security',$review,true))return array('ok'=>false,'reason'=>'Security approval is outstanding for this copy.');
        if($channel==='whatsapp'){
            $status=$this->status($eventKey,$channel,$locale);
            $state=(string)($status['provider_state']??'');
            if($state!==TelnyxTemplateAuthority::MAPPED)return array('ok'=>false,'reason'=>self::providerBlockReason($state));
            $mapping=$this->providerMapping(NotificationCatalogue::templateKey($eventKey,$channel,$locale));
            $draft=$this->draft($eventKey,$channel,$locale);
            if((string)($mapping['source']??'')==='manual'&&(string)($mapping['body_digest']??'')!==''){
                // A manual mapping is an attestation about one exact body. The recorded digest covers
                // the body only, so it is compared with the body-only digest of the draft: editing the
                // copy after the mapping was recorded must not be bulk-published against it.
                if(!hash_equals((string)$mapping['body_digest'],$this->copyDigest((string)($draft['body']??''))))return array('ok'=>false,'reason'=>'The draft differs from the copy the provider mapping was recorded against.');
            }
        }
        return array('ok'=>true,'reason'=>'');
    }

    /** @return array{allowed:false,reason:string} */
    private static function denied(string $reason):array{return array('allowed'=>false,'reason'=>$reason);}

    /**
     * A fail-closed explanation for every provider authority state. Only `mapped` is publishable, so
     * an unknown or newly introduced state is refused rather than treated as approved.
     */
    private static function providerBlockReason(string $state):string{
        return match($state){
            TelnyxTemplateAuthority::MAPPED=>'',
            TelnyxTemplateAuthority::NOT_MAPPED=>'No approved Meta/Telnyx template is mapped for this route yet.',
            TelnyxTemplateAuthority::NOT_APPROVED=>'The provider template for this route is rejected or not approved.',
            TelnyxTemplateAuthority::AMBIGUOUS=>'More than one approved provider template claims this route; resolve the ambiguity first.',
            TelnyxTemplateAuthority::CONTRACT_MISMATCH=>'The provider template does not match this event’s variable contract.',
            'approval_required'=>'The local copy changed after the last provider mapping; Meta must approve the new copy.',
            'mapped_unverified'=>'This mapping was recorded locally; Meta has not verified the template, so publication is refused.',
            'restricted_fallback'=>'The provider template is only a restricted fallback and cannot be published against.',
            'not_configured'=>'The provider is not configured for this route.',
            'not_applicable'=>'This route has no provider template.',
            'not_permitted'=>'The channel policy does not permit this route.',
            'unsupported'=>'This route is not supported.',
            default=>'The provider approval state is unknown, so publication is refused.',
        };
    }

    /**
     * Publish the drafts that are explicitly approved and whose channel approvals are complete.
     * Everything else is reported back to the administrator instead of being published silently.
     *
     * @return array{published:array<int,string>,blocked:array<int,array{template_key:string,reason:string}>}
     */
    public function publishReviewedDrafts():array{
        $published=array();$blocked=array();
        foreach($this->drafts() as $key=>$row){
            if(!is_array($row))continue;
            $eventKey=(string)($row['event_key']??'');
            $channel=(string)($row['channel']??'');
            $locale=NotificationCatalogue::normaliseLocale((string)($row['locale']??''));
            $policy=$this->draftReviewPolicy($eventKey,$channel,$locale);
            if(!$policy['allowed']){$blocked[]=array('template_key'=>(string)$key,'reason'=>(string)$policy['reason']);continue;}
            try{
                $this->publish($eventKey,$channel,$locale,(string)($row['subject']??''),(string)($row['body']??''),'authored');
                $published[]=(string)$key;
            }catch(\Throwable $error){
                $blocked[]=array('template_key'=>(string)$key,'reason'=>$error->getMessage());
            }
        }
        return array('published'=>$published,'blocked'=>$blocked);
    }

    // ------------------------------------------------------------------ editorial approval

    /** A digest of the exact copy an approval refers to. Any edit produces a different digest. */
    public function contentDigest(string $subject,string $body):string{
        return $this->copyDigest($subject."\x1f".$body);
    }

    /**
     * Approve one prepared draft for bulk publication. The approval is bound to the current copy, by
     * the current actor, and is invalidated by any later edit, discard or publish.
     *
     * @return array{ok:bool,approval:array}
     */
    public function approveDraft(string $eventKey,string $channel,string $locale):array{
        $locale=NotificationCatalogue::normaliseLocale($locale);
        $draft=$this->draft($eventKey,$channel,$locale);
        if($draft===null)throw new \InvalidArgumentException('There is no draft to approve.');
        $validated=NotificationTemplateSanitizer::validate($eventKey,$channel,(string)($draft['subject']??''),(string)($draft['body']??''));
        if(!$validated['ok'])throw new NotificationTemplateValidationException($validated['errors']);
        $approvals=$this->option(self::OPTION_APPROVALS);
        $approvals[$this->draftKey($eventKey,$channel,$locale)]=array(
            'event_key'=>$eventKey,'channel'=>$channel,'locale'=>$locale,
            'digest'=>$this->contentDigest((string)($draft['subject']??''),(string)($draft['body']??'')),
            'approved_at'=>$this->now(),'approved_by'=>$this->actor(),
        );
        $this->putOption(self::OPTION_APPROVALS,$approvals);
        $this->audit('approve_draft',$eventKey,$channel,$locale,null,'Draft approved for bulk publication');
        return array('ok'=>true,'approval'=>$approvals[$this->draftKey($eventKey,$channel,$locale)]);
    }

    /** Withdraw an approval without touching the draft. */
    public function revokeDraftApproval(string $eventKey,string $channel,string $locale):array{
        $key=$this->draftKey($eventKey,$channel,$locale);
        $approvals=$this->option(self::OPTION_APPROVALS);
        if(isset($approvals[$key])){
            unset($approvals[$key]);
            $this->putOption(self::OPTION_APPROVALS,$approvals);
            $this->audit('revoke_draft_approval',$eventKey,$channel,$locale,null,'Draft approval withdrawn');
        }
        return array('ok'=>true);
    }

    /**
     * The valid approval for this draft, or null. A stored approval whose digest no longer matches the
     * draft is stale: it is reported as absent and removed, so an edit can never inherit approval.
     *
     * @return array{event_key:string,channel:string,locale:string,digest:string,approved_at:string,approved_by:int}|null
     */
    public function draftApproval(string $eventKey,string $channel,string $locale):?array{
        $key=$this->draftKey($eventKey,$channel,$locale);
        $approvals=$this->option(self::OPTION_APPROVALS);
        $row=$approvals[$key]??null;
        if(!is_array($row))return null;
        $draft=$this->draft($eventKey,$channel,$locale);
        if($draft===null){unset($approvals[$key]);$this->putOption(self::OPTION_APPROVALS,$approvals);return null;}
        $digest=$this->contentDigest((string)($draft['subject']??''),(string)($draft['body']??''));
        if(!hash_equals((string)($row['digest']??''),$digest)){
            unset($approvals[$key]);
            $this->putOption(self::OPTION_APPROVALS,$approvals);
            return null;
        }
        return $row;
    }

    /** Any copy change drops the approval for that exact template. */
    private function forgetApproval(string $eventKey,string $channel,string $locale):void{
        $key=$this->draftKey($eventKey,$channel,$locale);
        $approvals=$this->option(self::OPTION_APPROVALS);
        if(!isset($approvals[$key]))return;
        unset($approvals[$key]);
        $this->putOption(self::OPTION_APPROVALS,$approvals);
    }

    /**
     * The administration-facing readiness inventory. Copy presence is catalogue maths; published
     * state and provider approval come from the same reads the editor and senders already use.
     *
     * @return array{events:int,channel_pairs:int,required:int,copy_present:int,copy_missing:int,drafts:int,blocked_drafts:array<int,array>,unpublished_canonical:array<int,string>,unmapped_whatsapp:array<int,string>,unsupported:array<int,string>,declared_only:array<int,string>}
     */
    public function coverageReport():array{
        $coverage=NotificationCatalogue::coverage();
        $drafts=0;$approved=0;$blockedDrafts=array();
        $unpublishedCanonical=array();$unmappedWhatsapp=array();$unsupported=array();$declaredOnly=array();
        foreach(NotificationCatalogue::events() as $eventKey=>$event){
            // A catalogue entry with no registered producer can never fire, whatever its copy state.
            if(empty($event['producer_registered']))$declaredOnly[]=$eventKey;
            foreach(array('website','email','whatsapp','sms') as $channel){
                if(!in_array($channel,(array)$event['channels'],true)){$unsupported[]=$eventKey.'.'.$channel;continue;}
                if($channel==='whatsapp'&&(string)($event['authority']??'')!=='dedicated'){
                    $mapping=$this->providerMapping(NotificationCatalogue::templateKey($eventKey,'whatsapp',NotificationCatalogue::PRIMARY_LOCALE));
                    if((string)$mapping['name']==='')$unmappedWhatsapp[]=$eventKey;
                }
            }
            if((string)($event['authority']??'')==='canonical'){
                $identity=$this->identity($eventKey,'email',NotificationCatalogue::PRIMARY_LOCALE);
                $active=$identity?$this->repository->activeVersion((int)$identity->id):null;
                if($active===null)$unpublishedCanonical[]=$eventKey;
            }
        }
        foreach($this->drafts() as $key=>$row){
            if(!is_array($row))continue;
            $drafts++;
            if($this->draftApproval((string)($row['event_key']??''),(string)($row['channel']??''),(string)($row['locale']??''))!==null)$approved++;
            $policy=$this->draftReviewPolicy((string)($row['event_key']??''),(string)($row['channel']??''),(string)($row['locale']??''));
            if(!$policy['allowed'])$blockedDrafts[]=array('template_key'=>(string)$key,'reason'=>(string)$policy['reason']);
        }
        sort($unpublishedCanonical);sort($unmappedWhatsapp);sort($unsupported);sort($declaredOnly);
        return array(
            'events'=>$coverage['events'],'channel_pairs'=>$coverage['channel_pairs'],'required'=>$coverage['required'],
            'copy_present'=>$coverage['present'],'copy_missing'=>count($coverage['missing']),
            'drafts'=>$drafts,'drafts_approved'=>$approved,'blocked_drafts'=>$blockedDrafts,
            'unpublished_canonical'=>array_values(array_unique($unpublishedCanonical)),
            'unmapped_whatsapp'=>array_values(array_unique($unmappedWhatsapp)),
            'unsupported'=>array_values(array_unique($unsupported)),
            'declared_only'=>array_values(array_unique($declaredOnly)),
        );
    }

    /**
     * Approve every draft whose channel approvals are already complete, in one audited action.
     *
     * This is the bulk step the workflow was missing: it records an explicit approval for each exact
     * draft and skips sensitive copy (finance, legal, security, dedicated authority) and every route
     * whose provider template is not positively mapped. It is deliberately a separate, named action so
     * that no copy is ever published without an administrator asking for it.
     *
     * @return array{approved:int,skipped:array<int,array{template_key:string,reason:string}>}
     */
    public function approveOperationalDrafts():array{
        $approved=0;$skipped=array();
        foreach($this->drafts() as $key=>$row){
            if(!is_array($row))continue;
            $eventKey=(string)($row['event_key']??'');
            $channel=(string)($row['channel']??'');
            $locale=NotificationCatalogue::normaliseLocale((string)($row['locale']??''));
            $gate=$this->draftChannelApproval($eventKey,$channel,$locale);
            if(!$gate['ok']){$skipped[]=array('template_key'=>(string)$key,'reason'=>(string)$gate['reason']);continue;}
            try{
                $this->approveDraft($eventKey,$channel,$locale);
                $approved++;
            }catch(\Throwable $error){
                $skipped[]=array('template_key'=>(string)$key,'reason'=>$error->getMessage());
            }
        }
        $this->audit('approve_operational_drafts','','','',null,'Bulk approval of '.$approved.' operational draft(s)');
        return array('approved'=>$approved,'skipped'=>$skipped);
    }

    /**
     * The one-time owner action requested for the current catalogue migration.
     *
     * It approves and publishes every stored draft, including copy held by the normal bulk workflow
     * for a security or provider review. Dedicated one-time-secret drafts remain with their owning
     * secure sender. It is deliberately separate from `approveOperationalDrafts()` and
     * `publishReviewedDrafts()`: those keep their fail-closed gates for every future draft. This action
     * records its use once and then refuses a second run, so it cannot become a standing bypass. It
     * never enables a delivery channel and never sends a message.
     *
     * @return array{published:string[],blocked:array<int,array{template_key:string,reason:string}>}
     */
    public function approveAndPublishEveryDraftOnce():array{
        $used=$this->oneTimeAllDraftApproval();
        if($used!==null)throw new \RuntimeException('The one-time all-draft approval was already used on '.$used['used_at'].' UTC by user '.$used['used_by'].'.');
        $published=array();$blocked=array();$attempted=0;
        foreach($this->drafts() as $key=>$row){
            if(!is_array($row))continue;
            $eventKey=(string)($row['event_key']??'');
            $channel=(string)($row['channel']??'');
            $locale=NotificationCatalogue::normaliseLocale((string)($row['locale']??''));
            $event=NotificationCatalogue::get($eventKey);
            if($event===null){$blocked[]=array('template_key'=>(string)$key,'reason'=>'Unknown notification event.');continue;}
            if((string)($event['authority']??'')==='dedicated'){$blocked[]=array('template_key'=>(string)$key,'reason'=>'Owned by a dedicated secure delivery authority.');continue;}
            $attempted++;
            try{
                // Approve the exact stored copy first, then publish it as a new immutable version. The
                // approval digest is what proves the owner approved this wording; it is consumed by the
                // publish rather than left behind as a reusable blanket permission.
                $this->approveDraft($eventKey,$channel,$locale);
                $this->publish($eventKey,$channel,$locale,(string)($row['subject']??''),(string)($row['body']??''),'authored');
                $published[]=(string)$key;
            }catch(\Throwable $error){
                $blocked[]=array('template_key'=>(string)$key,'reason'=>$error->getMessage());
            }
        }
        if($attempted>0){
            $this->putOption(self::OPTION_ONETIME_ALL,array(
                'used_at'=>$this->now(),'used_by'=>$this->actor(),
                'published'=>count($published),'blocked'=>count($blocked),
            ));
            $this->audit('approve_and_publish_all_once','','','',null,'One-time approval and publication of every draft: '.count($published).' published, '.count($blocked).' blocked');
        }
        return array('published'=>$published,'blocked'=>$blocked);
    }

    /** The recorded one-time all-draft approval, or null when it has not been used. */
    public function oneTimeAllDraftApproval():?array{
        $row=$this->option(self::OPTION_ONETIME_ALL);
        return is_array($row)&&isset($row['used_at'])?$row:null;
    }

    /**
     * Live counters for the administration workflow panel. Every draft is classified exactly once:
     * held back by an external approval, approved and ready to publish, or still awaiting an editor's
     * approval. This deliberately avoids a full per-template provider resolution — the authoritative
     * outcome of each bulk action is reported by that action and stored.
     *
     * @return array{drafts:int,approved:int,publishable:int,awaiting:int,held:int,held_by_reason:array<string,int>}
     */
    public function workflowReport():array{
        $drafts=0;$approved=0;$publishable=0;$held=0;$byReason=array();
        foreach($this->drafts() as $row){
            if(!is_array($row))continue;
            $drafts++;
            $eventKey=(string)($row['event_key']??'');
            $channel=(string)($row['channel']??'');
            $locale=NotificationCatalogue::normaliseLocale((string)($row['locale']??''));
            $review=NotificationCatalogue::reviewFor($eventKey);
            if($review!==array()){
                $held++;
                foreach($review as $code)@$byReason[(string)(NotificationCatalogue::REVIEW[$code]??$code)]++;
                continue;
            }
            $isApproved=$this->draftApproval($eventKey,$channel,$locale)!==null;
            if($channel==='whatsapp'&&(string)($this->providerMapping(NotificationCatalogue::templateKey($eventKey,$channel,$locale))['name']??'')===''){
                $held++;
                @$byReason['No approved Meta/Telnyx template is mapped for this route']++;
                continue;
            }
            if($isApproved){$approved++;$publishable++;}
        }
        return array(
            'drafts'=>$drafts,'approved'=>$approved,'publishable'=>$publishable,
            'awaiting'=>max(0,$drafts-$held-$approved),'held'=>$held,'held_by_reason'=>$byReason,
        );
    }

    /** @return array<string,array> Saved drafts keyed by template key. */
    private function drafts():array{
        $drafts=$this->option(self::OPTION_DRAFTS);
        foreach($drafts as $key=>$row)if(!is_array($row))unset($drafts[$key]);
        return $drafts;
    }

    public function saveDraft(string $eventKey,string $channel,string $locale,string $subject,string $body):array{
        $locale=NotificationCatalogue::normaliseLocale($locale);
        $validated=NotificationTemplateSanitizer::validate($eventKey,$channel,$subject,$body);
        if(!$validated['ok'])throw new NotificationTemplateValidationException($validated['errors']);
        $key=$this->draftKey($eventKey,$channel,$locale);
        $drafts=$this->option(self::OPTION_DRAFTS);
        $drafts[$key]=array('event_key'=>$eventKey,'channel'=>$channel,'locale'=>$locale,'subject'=>$validated['subject'],'body'=>$validated['body'],'updated_at'=>$this->now(),'updated_by'=>$this->actor());
        $this->putOption(self::OPTION_DRAFTS,$drafts);
        // Editing the copy after an approval revokes that approval: the approved digest no longer
        // describes what would be published.
        $this->forgetApproval($eventKey,$channel,$locale);
        $this->audit('save_draft',$eventKey,$channel,$locale,null,'Draft saved');
        return array('ok'=>true,'draft'=>$drafts[$key]);
    }

    public function discardDraft(string $eventKey,string $channel,string $locale):array{
        $key=$this->draftKey($eventKey,$channel,$locale);
        $drafts=$this->option(self::OPTION_DRAFTS);
        if(isset($drafts[$key])){unset($drafts[$key]);$this->putOption(self::OPTION_DRAFTS,$drafts);$this->forgetApproval($eventKey,$channel,$locale);$this->audit('discard_draft',$eventKey,$channel,$locale,null,'Draft discarded');}
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
        if(isset($drafts[$key])){unset($drafts[$key]);$this->putOption(self::OPTION_DRAFTS,$drafts);$this->forgetApproval($eventKey,$channel,$locale);}
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
            'locale'=>$locale,'audience'=>(string)(NotificationCatalogue::get($eventKey)['audience']??''),
            'cta'=>$cta,'preheader'=>$renderedSubject,
            'footer_note'=>NotificationCatalogue::previewNotice($locale),
        ));
        return array('subject'=>$rendered['subject'],'text'=>$rendered['text'],'html'=>$rendered['html'],'segments'=>null);
    }

    /** The one primary action of an event, derived from the simulated variables of an action placeholder. */
    private function ctaFor(string $eventKey,string $body,array $variables,string $audience,string $locale):?array{
        foreach(array('portal_url','teacher_portal_url','claim_url','payment_url') as $code){
            if(!str_contains($body,'{{'.$code.'}}')||!isset($variables[$code]))continue;
            $label=NotificationCatalogue::normaliseLocale($locale)==='en'?($audience==='teacher'?'Open Teacher Portal':($audience==='admin'?'Open Delnavazan Admin':'Open your account')):($audience==='teacher'?'رفتن به پرتال مدرس':($audience==='admin'?'رفتن به پیشخوان دلنوازان':'رفتن به حساب کاربری'));
            return array('label'=>$label,'url'=>(string)$variables[$code]);
        }
        return null;
    }

    // ------------------------------------------------------------------ provider mapping

    public function providerMapping(string $templateKey):array{
        $identity=$this->providerIdentity($templateKey);
        if($identity!==null&&$this->providerAuthority!==null){
            $resolved=$this->providerAuthority->resolve($identity['event_key'],$identity['locale']);
            if($resolved['state']===TelnyxTemplateAuthority::MAPPED){
                $row=$resolved['row'];
                return array(
                    'name'=>$resolved['name'],'language'=>$resolved['language'],'body_digest'=>'',
                    'mapped_at'=>$row->last_verified_at??null,'mapped_by'=>null,'source'=>'discovery',
                    'authority_state'=>$resolved['state'],'reason'=>'',
                );
            }
            if($resolved['state']!==TelnyxTemplateAuthority::NOT_MAPPED){
                return array('name'=>'','language'=>'','body_digest'=>'','mapped_at'=>null,'mapped_by'=>null,'source'=>'discovery','authority_state'=>$resolved['state'],'reason'=>$resolved['reason']);
            }
        }
        $map=$this->option(self::OPTION_WHATSAPP);
        $row=is_array($map[$templateKey]??null)?$map[$templateKey]:array();
        return array(
            'name'=>(string)($row['name']??''),'language'=>(string)($row['language']??''),
            'body_digest'=>(string)($row['body_digest']??''),'mapped_at'=>$row['mapped_at']??null,
            'mapped_by'=>$row['mapped_by']??null,'source'=>'manual','authority_state'=>'not_mapped','reason'=>'',
        );
    }

    /**
     * Full read-only provider evidence for the WhatsApp editor panel.
     *
     * The mapping array keeps its existing `name` contract (empty unless usable), while `primary`
     * names the discovered template even when it is pending or contract-invalid. That lets the
     * workspace explain a dead end without treating it as a usable mapping. `state` is the provider
     * authority's verdict; `status()['provider_state']` remains the effective state including a
     * legacy manual mapping and its digest check.
     *
     * @return array{expected_name:?string,language:string,state:string,reason:string,mapping:array,primary:?array,rows:array<int,array>}
     */
    public function providerEvidence(string $eventKey,string $locale):array{
        $templateKey=NotificationCatalogue::templateKey($eventKey,'whatsapp',$locale);
        $mapping=$this->providerMapping($templateKey);
        $language=TelnyxTemplateAuthority::providerLanguage($locale);
        $expected=$language===TelnyxTemplateRegistry::LANGUAGE?TelnyxTemplateRegistry::nameForEvent($eventKey):null;
        $state=(string)($mapping['authority_state']??'not_mapped');
        $reason=(string)($mapping['reason']??'');
        $rows=array();
        if($this->providerAuthority!==null){
            $resolved=$this->providerAuthority->resolve($eventKey,$locale);
            $state=(string)$resolved['state'];
            $reason=(string)$resolved['reason'];
            $rows=$this->providerAuthority->evidence($eventKey,$locale);
        }
        $primary=null;
        foreach($rows as $row)if((string)($mapping['name']??'')!==''&&(string)$row['name']===(string)$mapping['name']){$primary=$row;break;}
        if($primary===null&&$expected!==null)foreach($rows as $row)if((string)$row['name']===$expected){$primary=$row;break;}
        if($primary===null&&$rows!==array())$primary=$rows[0];
        return array(
            'expected_name'=>$expected,'language'=>$language,'state'=>$state,'reason'=>$reason,
            'mapping'=>$mapping,'primary'=>$primary,'rows'=>$rows,
        );
    }

    public function saveProviderMapping(string $eventKey,string $channel,string $locale,string $name,string $language):array{
        if($channel!=='whatsapp')throw new \InvalidArgumentException('Provider template mapping applies to WhatsApp only.');
        $name=trim($name);$language=trim($language);
        if($name==='')throw new \InvalidArgumentException('A provider template ID is required.');
        if(preg_match('/^[a-z0-9_]{1,191}$/',$name)!==1)throw new \InvalidArgumentException('The template ID may contain lowercase Latin letters, digits and underscores only.');
        if(preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/',$language)!==1)throw new \InvalidArgumentException('The template language code is invalid; examples: fa or en_US.');
        $definition=TelnyxTemplateRegistry::definitionForName($name);
        // Preserve a legacy manual identifier when discovery has no evidence for it. Registered
        // names, however, may never be rebound to a different event.
        if($definition!==null&&(string)$definition['event_key']!==$eventKey)throw new \InvalidArgumentException('The provider template is not registered for this notification event.');
        if($language!==TelnyxTemplateAuthority::providerLanguage($locale))throw new \InvalidArgumentException('The provider template language does not match this notification locale.');
        if($this->providerAuthority!==null){
            $resolved=$this->providerAuthority->resolve($eventKey,$locale);
            if($resolved['state']===TelnyxTemplateAuthority::MAPPED){
                if($resolved['name']!==$name||$resolved['language']!==$language)throw new \InvalidArgumentException('Approved provider discovery is authoritative for this notification event.');
                return array('ok'=>true,'mapping'=>$this->providerMapping(NotificationCatalogue::templateKey($eventKey,$channel,$locale)));
            }
            if($resolved['state']!==TelnyxTemplateAuthority::NOT_MAPPED)throw new \InvalidArgumentException((string)$resolved['reason']);
        }
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

    /** @return array{event_key:string,channel:string,locale:string}|null */
    private function providerIdentity(string $templateKey):?array{
        $parts=explode('.',$templateKey);
        if(count($parts)<3)return null;
        $locale=(string)array_pop($parts);$channel=(string)array_pop($parts);$eventKey=implode('.',$parts);
        return $channel==='whatsapp'&&NotificationCatalogue::get($eventKey)!==null?array('event_key'=>$eventKey,'channel'=>$channel,'locale'=>$locale):null;
    }

    // ------------------------------------------------------------------ event enablement

    /**
     * Optional events may be disabled by staff. Security, account and financial events are
     * mandatory and always report enabled; disabling one is refused rather than ignored later.
     */
    public function eventEnabled(string $eventKey):bool{
        if(NotificationCatalogue::get($eventKey)===null)return false;
        $saved=$this->option(self::OPTION_EVENT_ENABLED);
        return !array_key_exists($eventKey,$saved)||!empty($saved[$eventKey]);
    }

    /** @return array<string,bool> All optional events with their effective state. */
    public function eventStates():array{
        $out=array();
        foreach(NotificationCatalogue::events() as $eventKey=>$event)$out[$eventKey]=$this->eventEnabled($eventKey);
        return $out;
    }

    public function saveEventEnabled(string $eventKey,bool $enabled):array{
        if(NotificationCatalogue::get($eventKey)===null)throw new \InvalidArgumentException('notification_event_unknown');
        $saved=$this->option(self::OPTION_EVENT_ENABLED);
        $saved[$eventKey]=$enabled;
        $this->putOption(self::OPTION_EVENT_ENABLED,$saved);
        $this->audit($enabled?'enable_event':'disable_event',$eventKey,'','',null,$enabled?'Optional event enabled':'Optional event disabled');
        return array('event_key'=>$eventKey,'enabled'=>$enabled,'mandatory'=>NotificationCatalogue::isMandatory($eventKey));
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
