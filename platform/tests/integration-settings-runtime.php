<?php
/** Isolated runtime contract for admin integration settings and authenticated secret storage. */
$GLOBALS['dzn_test_options']=array();
function get_option(string $key,mixed $default=false):mixed{return $GLOBALS['dzn_test_options'][$key]??$default;}
function update_option(string $key,mixed $value,mixed $autoload=null):bool{$changed=($GLOBALS['dzn_test_options'][$key]??null)!==$value;$GLOBALS['dzn_test_options'][$key]=$value;return $changed;}
function wp_salt(string $scheme='auth'):string{return 'isolated-runtime-contract-salt-material-'.$scheme;}
function sanitize_text_field(string $value):string{return trim(strip_tags($value));}
function sanitize_email(string $value):string{return filter_var($value,FILTER_VALIDATE_EMAIL)?$value:'';}
function is_email(string $value):string|false{return filter_var($value,FILTER_VALIDATE_EMAIL)?$value:false;}
function esc_url_raw(string $value):string{return filter_var($value,FILTER_VALIDATE_URL)?$value:'';}
function admin_url(string $path=''):string{return 'https://academy.example/wp-admin/'.$path;}
require_once dirname(__DIR__).'/src/Core/Application/IntegrationSettingsService.php';

function dzn_settings_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$settingsClass='Delnavazan\\Platform\\Core\\Application\\IntegrationSettingsService';
$settings=new $settingsClass();
$settings->save('email',array('mode'=>'smtp','host'=>'mail.delnavazan.com','port'=>'587','encryption'=>'tls','username'=>'no-reply@delnavazan.com','password'=>'mail-runtime-secret','from_email'=>'no-reply@delnavazan.com'));
$raw=get_option($settingsClass::OPTION);
dzn_settings_assert($settings->ready('email'),'valid SMTP settings must be locally ready');
dzn_settings_assert(!str_contains(serialize($raw),'mail-runtime-secret'),'mail secret must not persist in plaintext');
dzn_settings_assert($settings->redacted('email')['password']===true,'redacted settings must show secret presence only');
$settings->save('email',array('password'=>''));
dzn_settings_assert($settings->ready('email'),'blank secret update must preserve the active secret');

$settings->save('whatsapp',array('app_id'=>'meta-app','business_account_id'=>'meta-business','phone_number_id'=>'meta-phone','access_token'=>'meta-runtime-token','app_secret'=>'meta-app-secret'));
dzn_settings_assert($settings->ready('whatsapp'),'complete Meta configuration must be locally ready');
dzn_settings_assert(!str_contains(serialize(get_option($settingsClass::OPTION)),'meta-runtime-token'),'Meta token must not persist in plaintext');

$settings->save('sms',array('endpoint'=>'https://sms.example/api','api_key'=>'sms-runtime-key'));
dzn_settings_assert(!$settings->ready('sms'),'SMS must remain unconfigured without a selected provider');
$settings->save('sms',array('provider'=>'operator-selected-provider','sender_id'=>'DLN','api_key'=>'sms-runtime-key'));
dzn_settings_assert($settings->ready('sms'),'vendor-neutral SMS configuration must be representable');

$callback=admin_url('admin-post.php?action=dzn_google_oauth_callback');
$settings->save('google',array('client_id'=>'google-client','client_secret'=>'google-runtime-secret','redirect_uri'=>$callback));
dzn_settings_assert($settings->ready('google'),'Google readiness must accept the exact HTTPS callback');
dzn_settings_assert(!str_contains(serialize(get_option($settingsClass::OPTION)),'google-runtime-secret'),'Google client secret must not persist in plaintext');
$settings->save('google',array('redirect_uri'=>'https://attacker.example/callback'));
dzn_settings_assert(!$settings->ready('google'),'Google readiness must reject a callback that differs from the exact registered URI');

$stored=get_option($settingsClass::OPTION);$stored['google']['client_secret']='v1:'.base64_encode(random_bytes(40));
update_option($settingsClass::OPTION,$stored,false);
dzn_settings_assert(!$settings->ready('google'),'tampered Google secret must fail closed as not ready');
echo "Integration settings encrypted storage runtime contract passed\n";
