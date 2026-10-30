<?php
namespace Delnavazan\Platform\Integrations\Notifications;

/**
 * Resolves the explicit WhatsApp test template without a silent fallback.
 *
 * The setting may be either an approved provider template name or a local event key. An unknown
 * non-empty value is refused; only an empty setting falls back to the first approved mapped template.
 * A name may have several language rows, so a pending variant never hides an approved variant.
 */
final class TelnyxWhatsAppTestTemplate {
    /** @param array<int,object|array> $templates rows from TelnyxTemplateDiscoveryService::status() */
    public static function resolve(string $setting,array $templates):?array{
        $setting=trim($setting);
        $name='';
        if($setting!==''){
            if(TelnyxTemplateRegistry::definitionForName($setting)!==null)$name=$setting;
            else $name=(string)(TelnyxTemplateRegistry::nameForEvent($setting)??'');
            if($name==='')return null;
        }
        $rows=array();
        foreach($templates as $row){
            $row=(array)$row;
            if((string)($row['template_name']??'')==='')continue;
            $rows[]=$row;
        }
        if($name===''){
            foreach($rows as $row)if(strtoupper((string)($row['status']??''))==='APPROVED'&&(string)($row['event_key']??'')!==''){$name=(string)$row['template_name'];break;}
            if($name==='')return null;
        }
        $approved=array();
        foreach($rows as $row)if((string)$row['template_name']===$name&&strtoupper((string)($row['status']??''))==='APPROVED')$approved[]=$row;
        if($approved===array())return null;
        $preferred=TelnyxTemplateRegistry::LANGUAGE;
        if(preg_match('/_([a-z]{2})(?:_[A-Z]{2})?$/',$name,$match)===1)$preferred=$match[1];
        $selected=null;
        foreach($approved as $row)if(strtolower((string)($row['language']??''))===$preferred){$selected=$row;break;}
        if($selected===null)$selected=$approved[0];
        $eventKey=(string)($selected['event_key']??TelnyxTemplateRegistry::eventKeyForName($name)??'');
        if($eventKey==='')return null;
        return array('name'=>$name,'language'=>(string)($selected['language']??$preferred),'event_key'=>$eventKey);
    }
}
