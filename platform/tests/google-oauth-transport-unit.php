<?php
declare(strict_types=1);

namespace {
    require_once dirname(__DIR__).'/src/Core/Application/Port/ProviderOAuthPort.php';
}

namespace Delnavazan\Platform\Core\Application {
    final class IntegrationSettingsService {
        public function value(string $provider,string $field):string{
            return $provider==='google'&&$field==='client_id'?'client-id.example.apps.googleusercontent.com':($provider==='google'&&$field==='client_secret'?'client-secret':'');
        }
    }
}

namespace Delnavazan\Platform\Integrations {
    $GLOBALS['dzn_google_transport_user_info']=array();
    $GLOBALS['dzn_google_transport_gets']=array();

    function wp_remote_post(string $uri,array $args):array{
        if($uri!=='https://oauth2.googleapis.com/token')throw new \RuntimeException('Unexpected Google POST');
        return array('response'=>array('code'=>200),'body'=>json_encode(array(
            'access_token'=>'access-token','refresh_token'=>'refresh-token','scope'=>'openid https://www.googleapis.com/auth/calendar.events','expires_in'=>3600,'token_type'=>'Bearer',
        ),JSON_UNESCAPED_SLASHES));
    }
    function wp_remote_get(string $uri,array $args):array{
        if($uri!=='https://openidconnect.googleapis.com/v1/userinfo')throw new \RuntimeException('Unexpected Google GET');
        $GLOBALS['dzn_google_transport_gets'][]=array($uri,$args);
        $body=array_shift($GLOBALS['dzn_google_transport_user_info']);
        return array('response'=>array('code'=>200),'body'=>json_encode($body,JSON_UNESCAPED_SLASHES));
    }
    function is_wp_error(mixed $value):bool{return false;}
    function wp_remote_retrieve_response_code(array $response):int{return (int)($response['response']['code']??0);}
    function wp_remote_retrieve_body(array $response):string{return (string)($response['body']??'');}
    function wp_json_encode(mixed $value,int $flags=0):string|false{return json_encode($value,$flags);}

    require_once dirname(__DIR__).'/src/Integrations/GoogleOAuthTransport.php';

    function dzn_google_transport_assert(bool $condition,string $message):void{if(!$condition)throw new \RuntimeException($message);}

    $transport=new GoogleOAuthTransport();
    $GLOBALS['dzn_google_transport_user_info'][]=array('sub'=>'immutable-google-subject','email'=>'different-teacher-account@gmail.com');
    $exchange=$transport->exchange(array(
        'client_reference'=>GoogleOAuthTransport::clientReference(),'redirect_uri'=>'https://academy.example/callback','code'=>'authorization-code','code_verifier'=>'pkce-verifier',
    ));
    dzn_google_transport_assert($exchange['provider_subject_reference']==='immutable-google-subject','Exchange must use the immutable OpenID subject and ignore e-mail');
    dzn_google_transport_assert($exchange['granted_scope_snapshot']==='openid https://www.googleapis.com/auth/calendar.events','Exchange must preserve the exact Calendar and OpenID grant');
    dzn_google_transport_assert($GLOBALS['dzn_google_transport_gets'][0][0]==='https://openidconnect.googleapis.com/v1/userinfo','Identity must come from the supported UserInfo endpoint');
    dzn_google_transport_assert(($GLOBALS['dzn_google_transport_gets'][0][1]['headers']['Authorization']??'')==='Bearer access-token','UserInfo must receive the access token in the Authorization header');
    dzn_google_transport_assert(!str_contains($GLOBALS['dzn_google_transport_gets'][0][0],'access-token'),'The access token must never appear in the UserInfo URL');
    $credential=json_decode((string)$exchange['credential_material'],true);
    dzn_google_transport_assert(is_array($credential)&&$credential['refresh_token']==='refresh-token','Exchange must retain renewable credential material');

    $GLOBALS['dzn_google_transport_user_info'][]=array('sub'=>'immutable-google-subject');
    $inspection=$transport->inspect(array('credential_material'=>$exchange['credential_material'],'scope_snapshot'=>'https://www.googleapis.com/auth/calendar.events openid'));
    dzn_google_transport_assert($inspection['usable']===true&&$inspection['provider_subject_reference']==='immutable-google-subject','Inspection must re-prove the same immutable OpenID subject');
    dzn_google_transport_assert($inspection['granted_scope_snapshot']==='https://www.googleapis.com/auth/calendar.events openid','Inspection must retain the verified stored scope snapshot');

    $GLOBALS['dzn_google_transport_user_info'][]=array('user_id'=>'legacy-google-subject','email'=>'different@example.com');
    $missing=$transport->inspect(array('credential_material'=>$exchange['credential_material'],'scope_snapshot'=>'https://www.googleapis.com/auth/calendar.events openid'));
    dzn_google_transport_assert($missing['usable']===false&&$missing['failure_reason_code']==='provider_inspection_failed','Inspection must fail closed when UserInfo returns no OpenID sub');

    echo "Google OAuth transport unit passed\n";
}
