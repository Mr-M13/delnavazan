<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\IntegrationSettingsService;

/** Telnyx Messages API adapter used only by the explicit admin test action. */
final class TelnyxSmsAdapter {
    public function __construct(private IntegrationSettingsService $settings){}
    public function sendTest(string $destination):array {
        $from=$this->settings->value('sms','sender_id');
        $profile=$this->settings->value('sms','messaging_profile_id');
        if($from===''&&$profile==='')return array('state'=>'failed','code'=>'telnyx_sender_not_configured');
        $payload=array('to'=>$destination,'text'=>'[Delnavazan TEST] SMS transport check. This is not a notification.');
        if($from!=='')$payload['from']=$from;else $payload['messaging_profile_id']=$profile;
        $response=wp_remote_post('https://api.telnyx.com/v2/messages',array('timeout'=>20,'redirection'=>0,'headers'=>array('Authorization'=>'Bearer '.$this->settings->value('sms','api_key'),'Content-Type'=>'application/json'),'body'=>wp_json_encode($payload)));
        if(is_wp_error($response))return array('state'=>'failed','code'=>'telnyx_transport_failed');
        $status=(int)wp_remote_retrieve_response_code($response);
        return array('state'=>$status>=200&&$status<300?'success':'failed','code'=>$status>=200&&$status<300?'telnyx_accepted':'telnyx_http_'.$status);
    }
}
