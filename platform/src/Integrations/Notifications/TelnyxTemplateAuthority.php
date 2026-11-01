<?php
namespace Delnavazan\Platform\Integrations\Notifications;

use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationProviderRepository;

/**
 * One read authority for an approved Telnyx/Meta template and its local event contract.
 *
 * Discovery is provider evidence, but it is usable only when exactly one approved row agrees with
 * the closed registry, catalogue audience and variable order.  Both the transport adapter and the
 * administration projection use this resolver so they cannot disagree about readiness.
 */
final class TelnyxTemplateAuthority {
    public const MAPPED='mapped';
    public const NOT_MAPPED='not_mapped';
    public const NOT_APPROVED='not_approved';
    public const AMBIGUOUS='ambiguous';
    public const CONTRACT_MISMATCH='contract_mismatch';

    public function __construct(private NotificationProviderRepository $repository){}

    /** @return array{state:string,name:string,language:string,variable_order:string[],row:?object,reason:string} */
    public function resolve(string $eventKey,string $locale):array{
        $language=self::providerLanguage($locale);
        $rows=$this->repository->templatesForEvent('telnyx',$eventKey,$language);
        $approved=array_values(array_filter($rows,static fn(object $row):bool=>strtoupper((string)($row->status??''))==='APPROVED'));
        if(count($approved)>1)return self::result(self::AMBIGUOUS,'','','',null,'Multiple approved provider templates claim the same event and language.');
        if($approved===array()){
            if($rows!==array())return self::result(self::NOT_APPROVED,'','','',null,'The discovered provider template is not approved.');
            // Discovery deliberately clears event_key when provider variables do not match the
            // registry. Look up the one registered provider name as well, otherwise that invalid
            // evidence could be mistaken for "not discovered" and a manual option could override it.
            $expectedName=TelnyxTemplateRegistry::nameForEvent($eventKey);
            $named=$expectedName===null?null:$this->repository->templateByName('telnyx',$expectedName,$language);
            if($named!==null){
                if(strtoupper((string)($named->status??''))!=='APPROVED')return self::result(self::NOT_APPROVED,(string)$named->template_name,(string)$named->language,array(),$named,'The discovered provider template is not approved.');
                return self::result(self::CONTRACT_MISMATCH,(string)$named->template_name,(string)$named->language,self::order((string)($named->variable_order??'')),$named,'The approved provider template does not match the local event contract.');
            }
            return self::result(self::NOT_MAPPED,'','','',null,'No approved provider template is mapped.');
        }
        $row=$approved[0];
        $name=trim((string)($row->template_name??''));
        $definition=TelnyxTemplateRegistry::definitionForName($name);
        $event=NotificationCatalogue::get($eventKey);
        $order=self::order((string)($row->variable_order??''));
        $expected=is_array($definition)?array_values((array)($definition['variable_order']??array())):array();
        $available=$event===null?array():NotificationCatalogue::variables($eventKey);
        $valid=$definition!==null
            &&$event!==null
            &&(string)($definition['event_key']??'')===$eventKey
            &&(string)($row->event_key??'')===$eventKey
            &&(string)($definition['audience']??'')===(string)($event['audience']??'')
            &&(string)($row->audience??'')===(string)($event['audience']??'')
            &&(string)($row->language??'')===$language
            &&$order!==array()
            &&$order===$expected
            &&array_diff($order,$available)===array();
        if(!$valid)return self::result(self::CONTRACT_MISMATCH,$name,(string)($row->language??''),$order,$row,'The approved provider template does not match the local event contract.');
        return self::result(self::MAPPED,$name,$language,$order,$row,'');
    }

    public static function providerLanguage(string $locale):string{
        $locale=NotificationCatalogue::normaliseLocale($locale);
        return str_starts_with(strtolower($locale),'fa')?TelnyxTemplateRegistry::LANGUAGE:$locale;
    }

    /** @return string[] */
    private static function order(string $value):array{return array_values(array_filter(array_map('trim',explode(',',$value)),static fn(string $code):bool=>$code!==''));}

    /** @param string[]|string $order */
    private static function result(string $state,string $name,string $language,$order,?object $row,string $reason):array{
        return array('state'=>$state,'name'=>$name,'language'=>$language,'variable_order'=>is_array($order)?array_values($order):array(),'row'=>$row,'reason'=>$reason);
    }
}
