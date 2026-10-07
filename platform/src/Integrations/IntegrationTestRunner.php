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
        $diagnostic=array('effective'=>array(),'hooks'=>$this->phpmailerHookSources(),'trace'=>array());
        $mailer=null;$previousDebug=null;$previousDebugOutput=null;
        $captureMailer=static function($instance)use(&$diagnostic,&$mailer,&$previousDebug,&$previousDebugOutput):void{
            if(!is_object($instance))return;
            $mailer=$instance;
            $host=(string)($instance->Host??'');
            // A malformed Host can contain userinfo; keep only its host portion.
            if(str_contains($host,'@'))$host=substr($host,strrpos($host,'@')+1);
            $diagnostic['effective']=array(
                'host'=>substr($host,0,255),
                'port'=>(int)($instance->Port??0),
                'smtp_secure'=>strtolower((string)($instance->SMTPSecure??'')),
                'smtp_auto_tls'=>(bool)($instance->SMTPAutoTLS??false),
                'smtp_auth'=>(bool)($instance->SMTPAuth??false),
            );
            $ssl=(array)($instance->SMTPOptions['ssl']??array());
            $diagnostic['effective']['tls_context']=array(
                'verify_peer'=>array_key_exists('verify_peer',$ssl)?(bool)$ssl['verify_peer']:'runtime default',
                'verify_peer_name'=>array_key_exists('verify_peer_name',$ssl)?(bool)$ssl['verify_peer_name']:'runtime default',
                'allow_self_signed'=>array_key_exists('allow_self_signed',$ssl)?(bool)$ssl['allow_self_signed']:'runtime default',
                'peer_name'=>isset($ssl['peer_name'])?substr(preg_replace('/[^a-zA-Z0-9.:-]/','',(string)$ssl['peer_name']),0,255):'Host default',
            );
            $previousDebug=$instance->SMTPDebug??0;
            $previousDebugOutput=$instance->Debugoutput??'echo';
            $instance->SMTPDebug=2;
            $instance->Debugoutput=static function($line,$level=0)use(&$diagnostic):void{
                $safe=IntegrationTestRunner::safeSmtpDebugLine((string)$line);
                if($safe!==''&&!in_array($safe,$diagnostic['trace'],true)&&count($diagnostic['trace'])<100)$diagnostic['trace'][]=$safe;
            };
        };
        $capture=static function($error)use(&$mailerError):void{
            if(is_object($error)&&method_exists($error,'get_error_message'))$mailerError=(string)$error->get_error_message();
        };
        add_action('phpmailer_init',$captureMailer,PHP_INT_MAX,1);
        add_action('wp_mail_failed',$capture,10,1);
        try{$sent=wp_mail($to,'[Delnavazan TEST] Email transport check','This is an explicitly requested Delnavazan TEST email. It is not a notification or delivery authority.');}
        finally{
            remove_action('phpmailer_init',$captureMailer,PHP_INT_MAX);
            remove_action('wp_mail_failed',$capture,10);
            if(is_object($mailer)){$mailer->SMTPDebug=$previousDebug;$mailer->Debugoutput=$previousDebugOutput;}
        }
        global $phpmailer;
        if(!$sent&&is_object($phpmailer)&&isset($phpmailer->ErrorInfo))$mailerError.=' '.(string)$phpmailer->ErrorInfo;
        if(!$sent)$diagnostic['trace'][]='mailer: '.$this->safeMailFailureCode($mailerError);
        return array('state'=>$sent?'success':'failed','code'=>$sent?'mailer_accepted':$this->safeMailFailureCode($mailerError),'diagnostic'=>$diagnostic);
    }
    /** Return hook callbacks as non-secret code locations without invoking them. */
    private function phpmailerHookSources():array {
        global $wp_filter;
        $hook=$wp_filter['phpmailer_init']??null;
        $callbacks=is_object($hook)&&isset($hook->callbacks)&&is_array($hook->callbacks)?$hook->callbacks:array();
        $sources=array();
        foreach($callbacks as $priority=>$entries)foreach($entries as $entry){
            $callback=$entry['function']??null;$label='unknown';$file='';$line=0;
            try{
                if(is_array($callback)&&count($callback)===2){
                    $class=is_object($callback[0])?get_class($callback[0]):(string)$callback[0];$method=(string)$callback[1];$label=$class.'::'.$method;$reflection=new \ReflectionMethod($callback[0],$method);
                }elseif($callback instanceof \Closure){$reflection=new \ReflectionFunction($callback);$label='closure';}
                elseif(is_string($callback)&&function_exists($callback)){$reflection=new \ReflectionFunction($callback);$label=$callback;}
                elseif(is_object($callback)&&method_exists($callback,'__invoke')){$reflection=new \ReflectionMethod($callback,'__invoke');$label=get_class($callback).'::__invoke';}
                else{$reflection=null;}
                if($reflection){$path=(string)$reflection->getFileName();$file=$path!==''?basename($path):'';$line=(int)$reflection->getStartLine();}
            }catch(\Throwable){$label='unresolved callback';}
            if(count($sources)<50)$sources[]=array('priority'=>(int)$priority,'callback'=>substr($label,0,160),'file'=>$file,'line'=>$line);
        }
        return $sources;
    }
    /** Convert PHPMailer debug lines to an allowlisted protocol transcript; payloads and addresses never survive. */
    public static function safeSmtpDebugLine(string $line):string {
        $line=trim(strip_tags($line));
        if(preg_match('/CLIENT\\s*->\\s*SERVER:\\s*([A-Z][A-Z0-9-]*)/i',$line,$m)){
            $command=strtoupper($m[1]);
            if($command==='AUTH')return 'client: AUTH [payload redacted]';
            if(in_array($command,array('MAIL','RCPT'),true))return 'client: '.$command.' [address redacted]';
            if(in_array($command,array('EHLO','HELO','STARTTLS','DATA','QUIT','RSET','NOOP','BDAT'),true))return 'client: '.$command;
            return '';
        }
        if(preg_match('/SERVER\\s*->\\s*CLIENT:\\s*([0-9]{3})/i',$line,$m))return 'server: '.$m[1];
        if(preg_match('/(TLS|SSL|crypto|certificate|handshake)/i',$line))return 'transport: TLS diagnostic (details redacted)';
        if(preg_match('/(connect|connection|timed out|timeout|network|could not connect|name resolution|dns)/i',$line))return 'transport: connection diagnostic (details redacted)';
        return '';
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
        $response=wp_remote_get('https://accounts.google.com/.well-known/openid-configuration',array('timeout'=>15,'redirection'=>0));
        if(is_wp_error($response))return array('state'=>'failed','code'=>$this->googleTransportFailureCode($response));
        $status=(int)wp_remote_retrieve_response_code($response);
        if($status<200||$status>=300)return array('state'=>'failed','code'=>'google_provider_refused_http_'.$status);
        return array('state'=>$configured?'success':'failed','code'=>$configured?'oauth_configuration_and_google_reachable':'local_oauth_configuration_incomplete');
    }
    /** Preserve the transport failure class without storing WP_Error text or potentially sensitive request data. */
    private function googleTransportFailureCode($error):string {
        $message=strtolower((string)$error->get_error_message());
        if(preg_match('/(resolve|resolving|dns|name lookup|host not found)/',$message))return 'google_transport_dns_failed';
        if(preg_match('/(ssl|tls|certificate|cert verify|crypto|handshake)/',$message))return 'google_transport_tls_failed';
        if(preg_match('/(timed? out|timeout)/',$message))return 'google_transport_timeout';
        if(preg_match('/(connect|connection|network|firewall|proxy|could not reach)/',$message))return 'google_transport_connectivity_failed';
        return 'google_transport_failed';
    }
    private function httpResult($response,string $provider):array {
        if(is_wp_error($response))return array('state'=>'failed','code'=>$provider.'_transport_failed');
        $status=(int)wp_remote_retrieve_response_code($response);
        return array('state'=>$status>=200&&$status<300?'success':'failed','code'=>$status>=200&&$status<300?$provider.'_accepted':$provider.'_http_'.$status);
    }
}
