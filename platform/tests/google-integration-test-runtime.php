<?php
/** Isolated Google explicit-test regression coverage; all provider responses are deterministic fixtures. */
$GLOBALS['dzn_test_options']=array();
$GLOBALS['dzn_google_http_response']=null;
$GLOBALS['dzn_google_http_requests']=array();
function get_option(string $key,mixed $default=false):mixed{return $GLOBALS['dzn_test_options'][$key]??$default;}
function update_option(string $key,mixed $value,mixed $autoload=null):bool{$GLOBALS['dzn_test_options'][$key]=$value;return true;}
function wp_salt(string $scheme='auth'):string{return 'isolated-google-test-salt-material-'.$scheme;}
function sanitize_text_field(string $value):string{return trim(strip_tags($value));}
function sanitize_email(string $value):string{return filter_var($value,FILTER_VALIDATE_EMAIL)?$value:'';}
function is_email(string $value):string|false{return filter_var($value,FILTER_VALIDATE_EMAIL)?$value:false;}
function esc_url_raw(string $value):string{return filter_var($value,FILTER_VALIDATE_URL)?$value:'';}
function admin_url(string $path=''):string{return 'https://academy.example/wp-admin/'.$path;}
function wp_remote_get(string $url,array $args=array()){$GLOBALS['dzn_google_http_requests'][]=array($url,$args);return $GLOBALS['dzn_google_http_response'];}
function is_wp_error($value):bool{return $value instanceof WP_Error;}
function wp_remote_retrieve_response_code($response):int{return (int)($response['response']['code']??0);}
class WP_Error {private string $message;public function __construct(string $code,string $message){$this->message=$message;}public function get_error_message():string{return $this->message;}}
require_once dirname(__DIR__).'/src/Core/Application/IntegrationSettingsService.php';
require_once dirname(__DIR__).'/src/Integrations/IntegrationTestRunner.php';
function dzn_google_test_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$settings=new \Delnavazan\Platform\Core\Application\IntegrationSettingsService();
$settings->save('google',array('client_id'=>'saved-google-client','client_secret'=>'saved-google-secret','redirect_uri'=>admin_url('admin-post.php?action=dzn_google_oauth_callback')));
$runner=new \Delnavazan\Platform\Integrations\IntegrationTestRunner();
$GLOBALS['dzn_google_http_response']=array('response'=>array('code'=>200));
$result=$runner->run('google',array());
dzn_google_test_assert($result===array('state'=>'success','code'=>'oauth_configuration_and_google_reachable'),'valid OIDC discovery response should pass the explicit Google test');
dzn_google_test_assert($GLOBALS['dzn_google_http_requests'][0][0]==='https://accounts.google.com/.well-known/openid-configuration','Google test must call the canonical discovery endpoint');
dzn_google_test_assert($GLOBALS['dzn_google_http_requests'][0][1]===array('timeout'=>15,'redirection'=>0),'Google discovery request must retain bounded timeout and disabled redirects');
$GLOBALS['dzn_google_http_response']=array('response'=>array('code'=>404));
$result=$runner->run('google',array());
dzn_google_test_assert($result['code']==='google_provider_refused_http_404','an HTTP 404 must report a provider HTTP response, not provider unreachability');
dzn_google_test_assert(!str_contains(serialize($result),'unreachable'),'HTTP refusal must not be classified as transport unreachability');
$GLOBALS['dzn_google_http_response']=new WP_Error('http_request_failed','cURL error 60: SSL certificate verify failed; saved-google-secret');
$result=$runner->run('google',array());
dzn_google_test_assert($result['code']==='google_transport_tls_failed','TLS transport failures must have a distinct safe diagnostic');
dzn_google_test_assert(!str_contains(serialize($result),'saved-google-secret')&&!str_contains(serialize($result),'certificate verify failed'),'raw transport text and credentials must not be returned');
echo "Google explicit integration test runtime contract passed\n";
