<?php
namespace Delnavazan\Platform\Core\Application;

/** Admin-managed provider configuration. Public reads are redacted; secrets use authenticated encryption. */
final class IntegrationSettingsService {
    public const OPTION='dzn_integration_settings_v1';
    private const KEY_VERSION='dzn_integration_settings_v1';
    private const SECRET_FIELDS=array(
        'email'=>array('password'), 'whatsapp'=>array('access_token','app_secret','verify_token'),
        'sms'=>array('api_key'), 'google'=>array('client_secret'),
    );

    /** Save only known fields; blank secret fields preserve the current value. */
    public function save(string $provider,array $input):void {
        $schema=$this->schema(); if(!isset($schema[$provider]))throw new \InvalidArgumentException('integration_provider_unknown');
        $all=(array)get_option(self::OPTION,array()); $row=(array)($all[$provider]??array());
        foreach($schema[$provider] as $field=>$rule){
            if(in_array($field,self::SECRET_FIELDS[$provider]??array(),true)){
                $value=trim((string)($input[$field]??''));
                if($value!=='')$row[$field]=$this->seal($provider,$field,$value);
            } elseif(array_key_exists($field,$input)) {
                $value=trim(sanitize_text_field((string)$input[$field]));
                if($rule==='email')$value=sanitize_email($value);
                if($rule==='url')$value=esc_url_raw($value);
                if($provider==='email'&&$field==='mode'&&!in_array($value,array('host_mail','smtp'),true))throw new \InvalidArgumentException('integration_email_mode_invalid');
                if($provider==='email'&&$field==='encryption'&&!in_array($value,array('tls','ssl','none'),true))throw new \InvalidArgumentException('integration_email_encryption_invalid');
                if($field==='endpoint'&&$value!==''&&!str_starts_with($value,'https://'))throw new \InvalidArgumentException('integration_endpoint_https_required');
                if($provider==='google'&&$field==='redirect_uri'&&$value!==''&&!str_starts_with($value,'https://'))throw new \InvalidArgumentException('google_redirect_https_required');
                $row[$field]=$value;
            }
        }
        $all[$provider]=$row;
        if(!update_option(self::OPTION,$all,false)&&get_option(self::OPTION)!==$all)throw new \RuntimeException('integration_settings_save_failed');
    }

    /** Internal trusted read. Secret values are decrypted only for transports and never returned to screens. */
    public function value(string $provider,string $field):string {
        $all=(array)get_option(self::OPTION,array());$row=(array)($all[$provider]??array());$value=(string)($row[$field]??'');
        if($value===''&&$provider==='email'&&$field==='from_email')return 'no-reply@delnavazan.com';
        if($value===''&&$provider==='email'&&$field==='mode')return 'host_mail';
        return in_array($field,self::SECRET_FIELDS[$provider]??array(),true)&&$value!==''?$this->open($provider,$field,$value):$value;
    }
    /** Fields safe to render, with secret presence represented as a boolean. */
    public function redacted(string $provider):array {
        $schema=$this->schema(); if(!isset($schema[$provider]))return array();
        $out=array();foreach($schema[$provider] as $field=>$rule){$raw=$this->valueRaw($provider,$field);$out[$field]=in_array($field,self::SECRET_FIELDS[$provider]??array(),true)?($raw!==''):$raw;}
        return $out;
    }
    public function ready(string $provider):bool {
        try{
            $v=fn($key)=>$this->value($provider,$key);
            if($provider==='email')return is_email($v('from_email'))&&($v('mode')!=='smtp'||($v('host')!==''&&$v('username')!==''&&$v('password')!==''&&(int)$v('port')>0&&(int)$v('port')<=65535));
            if($provider==='whatsapp')return $v('app_id')!==''&&$v('business_account_id')!==''&&$v('phone_number_id')!==''&&$v('access_token')!==''&&stripos($v('test_template_name'),'test')!==false;
            if($provider==='sms')return $v('api_key')!==''&&($v('messaging_profile_id')!==''||$v('sender_id')!=='');
            if($provider==='google'){
                $expected=function_exists('admin_url')?admin_url('admin-post.php?action=dzn_google_oauth_callback'):'';
                $client=$v('client_id')?:((defined('DZN_GOOGLE_OAUTH_CLIENT_ID'))?(string)constant('DZN_GOOGLE_OAUTH_CLIENT_ID'):'');
                $secret=$v('client_secret')?:((defined('DZN_GOOGLE_OAUTH_CLIENT_SECRET'))?(string)constant('DZN_GOOGLE_OAUTH_CLIENT_SECRET'):'');
                return $client!==''&&$secret!==''&&$expected!==''&&hash_equals($expected,$v('redirect_uri'));
            }
            return false;
        }catch(\Throwable){return false;}
    }
    public static function providerSchemas():array{return (new self())->schema();}
    private function schema():array{return array(
        'email'=>array('mode'=>'text','host'=>'text','port'=>'text','encryption'=>'text','username'=>'text','password'=>'secret','from_name'=>'text','from_email'=>'email'),
        'whatsapp'=>array('app_id'=>'text','business_account_id'=>'text','phone_number_id'=>'text','test_template_name'=>'text','test_template_language'=>'text','access_token'=>'secret','app_secret'=>'secret','verify_token'=>'secret'),
        'sms'=>array('messaging_profile_id'=>'text','sender_id'=>'text','api_key'=>'secret'),
        'google'=>array('client_id'=>'text','client_secret'=>'secret','redirect_uri'=>'url'),
    );}
    private function valueRaw(string $provider,string $field):string{$all=(array)get_option(self::OPTION,array());$row=(array)($all[$provider]??array());return (string)($row[$field]??'');}
    private function seal(string $provider,string $field,string $plain):string{
        if(!function_exists('openssl_encrypt'))throw new \RuntimeException('integration_secret_encryption_unavailable');
        $nonce=random_bytes(12);$tag='';$cipher=openssl_encrypt($plain,'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,$nonce,$tag,$provider.'|'.$field.'|'.self::KEY_VERSION);
        if(!is_string($cipher))throw new \RuntimeException('integration_secret_encryption_failed');return 'v1:'.base64_encode($nonce.$tag.$cipher);
    }
    private function open(string $provider,string $field,string $sealed):string{
        if(!str_starts_with($sealed,'v1:'))throw new \RuntimeException('integration_secret_format_invalid');$raw=base64_decode(substr($sealed,3),true);
        if(!is_string($raw)||strlen($raw)<29)throw new \RuntimeException('integration_secret_format_invalid');
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$this->key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),$provider.'|'.$field.'|'.self::KEY_VERSION);
        if(!is_string($plain))throw new \RuntimeException('integration_secret_authentication_failed');return $plain;
    }
    private function key():string{$salt=wp_salt('secure_auth');if(!is_string($salt)||strlen($salt)<16)throw new \RuntimeException('integration_secret_key_unavailable');return hash_hmac('sha256','dzn_settings_secret_v1',$salt,true);}
}
