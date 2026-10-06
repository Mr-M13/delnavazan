<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\IntegrationSettingsService;

/** Explicit, operator initiated provider checks. Results are deliberately reduced to safe codes. */
final class IntegrationTestRunner {
    public function run(string $provider, array $input):array {
        $settings=new IntegrationSettingsService();
        try {
            if($provider==='google')return $this->google($settings->ready('google'));
            if(!$settings->ready($provider))return array('state'=>'failed','code'=>'local_configuration_incomplete');
            return match($provider){
                'email'=>$this->email($input),
                'whatsapp'=>$this->whatsapp($settings,$input),
                'sms'=>$this->sms($settings,$input),
                'google'=>array('state'=>'failed','code'=>'provider_unknown'),
                default=>array('state'=>'failed','code'=>'provider_unknown'),
            };
        } catch(\Throwable) { return array('state'=>'failed','code'=>'test_execution_failed'); }
    }
    private function email(array $input):array {
        $to=sanitize_email((string)($input['destination']??''));
        if(!is_email($to))return array('state'=>'failed','code'=>'destination_invalid');
        $mailerError='';
        $capture=static function($error)use(&$mailerError):void{
            if(is_object($error)&&method_exists($error,'get_error_message'))$mailerError=(string)$error->get_error_message();
        };
        add_action('wp_mail_failed',$capture,10,1);
        try{$sent=wp_mail($to,'[Delnavazan TEST] Email transport check','This is an explicitly requested Delnavazan TEST email. It is not a notification or delivery authority.');}
        finally{remove_action('wp_mail_failed',$capture,10);}
        if($sent)return array('state'=>'success','code'=>'mailer_accepted');
        global $phpmailer;
        if(is_object($phpmailer)&&isset($phpmailer->ErrorInfo))$mailerError.=' '.(string)$phpmailer->ErrorInfo;
        return array('state'=>'failed','code'=>$this->safeMailFailureCode($mailerError));
    }
    /** Reduce provider diagnostics to a fixed safe category; raw mailer text is never stored or returned. */
    private function safeMailFailureCode(string $error):string {
        $error=strtolower(strip_tags($error));
        if(preg_match('/(tls|ssl|crypto|certificate|handshake|encryption)/',$error))return 'smtp_tls_failed';
        if(preg_match('/(auth|authentication|credentials|login|username|password)/',$error))return 'smtp_authentication_failed';
        if(preg_match('/(recipient|rcpt to|mailbox unavailable|user unknown|address not accepted)/',$error))return 'smtp_recipient_rejected';
        if(preg_match('/(sender|from address|mail from|unauthorized sender)/',$error))return 'smtp_sender_rejected';
        if(preg_match('/(connect|connection|timed out|timeout|network|could not connect|name resolution|dns)/',$error))return 'smtp_connection_failed';
        if(preg_match('/(data not accepted|message rejected|content rejected|policy|spam)/',$error))return 'smtp_message_rejected';
        return 'mailer_rejected_unknown';
    }
    private function whatsapp(IntegrationSettingsService $s,array $input):array {
        $to=preg_replace('/[^0-9]/','',(string)($input['destination']??''));
        if(strlen($to)<8||strlen($to)>15)return array('state'=>'failed','code'=>'destination_invalid');
        $template=preg_replace('/[^a-zA-Z0-9_]/','',(string)$s->value('whatsapp','test_template_name'));
        if($template===''||stripos($template,'test')===false)return array('state'=>'failed','code'=>'test_template_not_configured');
        $payload=array('messaging_product'=>'whatsapp','to'=>$to,'type'=>'template','template'=>array('name'=>$template,'language'=>array('code'=>(string)($s->value('whatsapp','test_template_language')?:'en_US'))));
        $response=wp_remote_post('https://graph.facebook.com/v21.0/'.rawurlencode($s->value('whatsapp','phone_number_id')).'/messages',array('timeout'=>20,'redirection'=>0,'headers'=>array('Authorization'=>'Bearer '.$s->value('whatsapp','access_token'),'Content-Type'=>'application/json'),'body'=>wp_json_encode($payload)));
        return $this->httpResult($response,'meta');
    }
    private function sms(IntegrationSettingsService $s,array $input):array {
        $to=preg_replace('/[^0-9+]/','',(string)($input['destination']??''));
        if(!preg_match('/^\+[1-9][0-9]{7,14}$/',$to))return array('state'=>'failed','code'=>'destination_invalid');
        return (new TelnyxSmsAdapter($s))->sendTest($to);
    }
    private function google(bool $configured):array {
        $response=wp_remote_get('https://oauth2.googleapis.com/.well-known/openid-configuration',array('timeout'=>15,'redirection'=>0));
        $reachable=!is_wp_error($response)&&(int)wp_remote_retrieve_response_code($response)>=200&&(int)wp_remote_retrieve_response_code($response)<300;
        return array('state'=>$configured&&$reachable?'success':'failed','code'=>!$configured?($reachable?'local_oauth_configuration_incomplete':'local_oauth_configuration_incomplete_and_google_unreachable'):($reachable?'oauth_configuration_and_google_reachable':'google_provider_unreachable'));
    }
    private function httpResult($response,string $provider):array {
        if(is_wp_error($response))return array('state'=>'failed','code'=>$provider.'_transport_failed');
        $status=(int)wp_remote_retrieve_response_code($response);
        return array('state'=>$status>=200&&$status<300?'success':'failed','code'=>$status>=200&&$status<300?$provider.'_accepted':$provider.'_http_'.$status);
    }
}
