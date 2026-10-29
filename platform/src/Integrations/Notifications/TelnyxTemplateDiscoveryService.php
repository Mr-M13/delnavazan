<?php
namespace Delnavazan\Platform\Integrations\Notifications;

use Delnavazan\Platform\Core\Application\NotificationSupport;
use Delnavazan\Platform\Core\Infrastructure\Repository\NotificationProviderRepository;

/**
 * Explicit, read-only provider discovery followed by a local mapping write.
 *
 * Discovery never sends a message. It reads the WABA's templates, records their approved/pending
 * status and maps the Delnavazan naming convention to a local event. Unknown templates are retained
 * as unmapped so an operator can see them instead of silently ignoring them.
 */
final class TelnyxTemplateDiscoveryService {
    public function __construct(private $settings,private $http=null,private ?NotificationProviderRepository $repository=null){$this->repository??=new NotificationProviderRepository();}

    public function configured():bool{
        return (string)$this->settings->value('sms','api_key')!==''&&$this->waba()!=='';
    }

    /** @return array{ok:bool,error:?string,templates:array<int,array>} */
    public function discover():array{
        if(!$this->configured())return array('ok'=>false,'error'=>'telnyx_template_discovery_unconfigured','templates'=>array());
        $key=(string)$this->settings->value('sms','api_key');$waba=$this->waba();$all=array();$page=1;
        do{
            $url='https://api.telnyx.com/v2/whatsapp/message_templates?page[number]='.$page.'&page[size]=250&filter[waba_id]='.rawurlencode($waba);
            $response=$this->get($url,array('timeout'=>20,'redirection'=>0,'headers'=>array('Authorization'=>'Bearer '.$key,'Accept'=>'application/json')));
            if(is_wp_error($response))return array('ok'=>false,'error'=>'telnyx_template_discovery_transport','templates'=>array());
            $status=(int)wp_remote_retrieve_response_code($response);
            $body=json_decode((string)wp_remote_retrieve_body($response),true);
            if($status<200||$status>=300)return array('ok'=>false,'error'=>'telnyx_template_discovery_http_'.$status,'templates'=>array());
            foreach((array)($body['data']??array()) as $template){
                $name=(string)($template['name']??'');$language=(string)($template['language']??'');
                if($name==='')continue;
                $definition=TelnyxTemplateRegistry::definitionForName($name);
                $components=(array)($template['components']??array());
                $bodyText='';foreach($components as $component)if(is_array($component)&&($component['type']??'')==='BODY')$bodyText=(string)($component['text']??'');
                $variableCount=(int)preg_match_all('/\{\{[0-9]+\}\}/',$bodyText);
                $all[]=array(
                    'name'=>$name,'language'=>$language,'status'=>strtoupper((string)($template['status']??'UNKNOWN')),
                    'category'=>(string)($template['category']??''),'template_id'=>(string)($template['id']??''),
                    'event_key'=>$definition['event_key']??null,'audience'=>$definition['audience']??null,
                    'variable_order'=>$definition['variable_order']??array(),'provider_variable_count'=>$variableCount,
                    'variable_contract_matches'=>$definition===null?false:$variableCount===count($definition['variable_order']),
                    'rejection_reason'=>(string)($template['rejection_reason']??''),
                );
            }
            $pages=(int)($body['meta']['total_pages']??1);$page++;
        }while($page<=$pages);
        return array('ok'=>true,'error'=>null,'templates'=>$all);
    }

    /** Persist the discovered mapping on an explicit admin action. Never sends. */
    public function sync():array{
        $discovery=$this->discover();
        if(!($discovery['ok']??false))return array('ok'=>false,'error'=>(string)($discovery['error']??'telnyx_template_discovery_failed'),'discovered'=>0,'mapped'=>0,'approved'=>0);
        $now=NotificationSupport::now();$mapped=0;$approved=0;
        foreach($discovery['templates'] as $template){
            if(($template['status']??'')==='APPROVED')$approved++;
            if(($template['event_key']??null)!==null&&($template['status']??'')==='APPROVED')$mapped++;
            $this->repository->upsertTemplate(array(
                'uid'=>NotificationSupport::uid(),'provider'=>'telnyx','template_name'=>$template['name'],
                'language'=>$template['language'],'provider_template_id'=>$template['template_id'],
                'category'=>$template['category'],'status'=>$template['status'],'event_key'=>$template['event_key'],
                'audience'=>$template['audience'],'variable_order'=>implode(',',$template['variable_order']),
                'last_verified_at'=>$now,'created_at'=>$now,'updated_at'=>$now,
            ));
        }
        return array('ok'=>true,'error'=>null,'discovered'=>count($discovery['templates']),'approved'=>$approved,'mapped'=>$mapped);
    }

    public function status():array{
        $rows=$this->repository->templates('telnyx');$counts=array('approved'=>0,'pending'=>0,'rejected'=>0,'other'=>0,'mapped'=>0,'unmapped'=>0,'variable_mismatch'=>0);
        foreach($rows as $row){
            $status=strtoupper((string)$row->status);
            if($status==='APPROVED')$counts['approved']++;elseif($status==='PENDING')$counts['pending']++;elseif($status==='REJECTED')$counts['rejected']++;else$counts['other']++;
            if((string)$row->event_key!=='')$counts['mapped']++;else$counts['unmapped']++;
            $order=array_values(array_filter(array_map('trim',explode(',',(string)$row->variable_order))));
            $known=TelnyxTemplateRegistry::definitionForName((string)$row->template_name);
            if($known!==null&&count($known['variable_order'])!==count($order))$counts['variable_mismatch']++;
        }
        return array('counts'=>$counts,'templates'=>$rows);
    }
    private function waba():string{
        $waba=(string)$this->settings->value('whatsapp','waba_id');
        return $waba!==''?$waba:(string)$this->settings->value('whatsapp','business_account_id');
    }
    private function get(string $url,array $args){return is_callable($this->http)?($this->http)($url,$args):wp_remote_get($url,$args);}
}
