<?php
namespace Delnavazan\Platform\Integrations\Notifications;

/**
 * Read-only readiness projection for the WhatsApp provider-template mapping.
 *
 * A provider route can be configured and an event channel can be switched on while the exact
 * provider template is missing, unapproved or still bound to an older variable contract. The
 * WhatsApp adapter fails closed in that state, so global diagnostics must report it instead of
 * treating local provider configuration as sufficient delivery evidence.
 *
 * The projection reads only catalogue metadata, provider discovery evidence and the per-event
 * channel switches. It never calls the provider and never changes a channel or a template.
 */
final class WhatsAppProviderReadinessReadService {
    public function __construct(private $library=null){$this->library??=new NotificationTemplateLibrary();}

    /**
     * @return array{enabled:int,ready:int,blocked:array<string,string>,unverified:array<string,string>,disabled_blocked:array<string,string>}
     */
    public function report():array{
        $enabled=0;$ready=0;$blocked=array();$unverified=array();$disabledBlocked=array();
        foreach(NotificationCatalogue::events() as $eventKey=>$event){
            $eventKey=(string)$eventKey;
            if(!in_array('whatsapp',(array)($event['channels']??array()),true))continue;
            // A method with no producer can never send, however ready its provider route looks.
            if(!NotificationCatalogue::producesMobileChannel($eventKey,'whatsapp'))continue;
            // A globally disabled event cannot send on any channel; its provider route is not
            // currently actionable and must not make the delivery category look broken.
            if(!(bool)$this->library->eventEnabled($eventKey))continue;
            $evidence=$this->library->providerEvidence($eventKey,NotificationCatalogue::PRIMARY_LOCALE);
            $mapping=(array)($evidence['mapping']??array());
            $primary=$evidence['primary']??null;
            $state=(string)($evidence['state']??TelnyxTemplateAuthority::NOT_MAPPED);
            $problem=null;
            if($state!==TelnyxTemplateAuthority::MAPPED){
                $problem=(string)($mapping['source']??'')==='manual'&&!is_array($primary)
                    ?'manual_unverified'
                    :($state!==''?$state:TelnyxTemplateAuthority::NOT_MAPPED);
            }
            if((bool)$this->library->eventChannelEnabled($eventKey,'whatsapp')){
                $enabled++;
                if($problem===null)$ready++;
                elseif($problem==='manual_unverified')$unverified[$eventKey]=$problem;
                else $blocked[$eventKey]=$problem;
            }elseif($problem!==null){
                $disabledBlocked[$eventKey]=$problem;
            }
        }
        return array('enabled'=>$enabled,'ready'=>$ready,'blocked'=>$blocked,'unverified'=>$unverified,'disabled_blocked'=>$disabledBlocked);
    }
}
