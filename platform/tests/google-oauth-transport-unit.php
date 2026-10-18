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
    $GLOBALS['dzn_google_transport_token_info']=array();

    function wp_remote_post(string $uri,array $args):array{
        if($uri!=='https://oauth2.googleapis.com/token')throw new \RuntimeException('Unexpected Google POST');
        return array('response'=>array('code'=>200),'body'=>json_encode(array(
            'access_token'=>'access-token','refresh_token'=>'refresh-token','scope'=>'https://www.googleapis.com/auth/calendar.events','expires_in'=>3600,'token_type'=>'Bearer',
        ),JSON_UNESCAPED_SLASHES));
    }
    function wp_remote_get(string $uri,array $args):array{
        if(!str_starts_with($uri,'https://oauth2.googleapis.com/tokeninfo?'))throw new \RuntimeException('Unexpected Google GET');
        $body=array_shift($GLOBALS['dzn_google_transport_token_info']);
        return array('response'=>array('code'=>200),'body'=>json_encode($body,JSON_UNESCAPED_SLASHES));
    }
    function is_wp_error(mixed $value):bool{return false;}
    function wp_remote_retrieve_response_code(array $response):int{return (int)($response['response']['code']??0);}
    function wp_remote_retrieve_body(array $response):string{return (string)($response['body']??'');}
    function wp_json_encode(mixed $value,int $flags=0):string|false{return json_encode($value,$flags);}

    require_once dirname(__DIR__).'/src/Integrations/GoogleOAuthTransport.php';

    function dzn_google_transport_assert(bool $condition,string $message):void{if(!$condition)throw new \RuntimeException($message);}

    $transport=new GoogleOAuthTransport();
    $GLOBALS['dzn_google_transport_token_info'][]=array('user_id'=>'legacy-google-subject','scope'=>'https://www.googleapis.com/auth/calendar.events');
    $exchange=$transport->exchange(array(
        'client_reference'=>GoogleOAuthTransport::clientReference(),'redirect_uri'=>'https://academy.example/callback','code'=>'authorization-code','code_verifier'=>'pkce-verifier',
    ));
    dzn_google_transport_assert($exchange['provider_subject_reference']==='legacy-google-subject','Calendar-only token introspection must accept Google user_id as the stable subject');
    dzn_google_transport_assert($exchange['granted_scope_snapshot']==='https://www.googleapis.com/auth/calendar.events','Exchange must preserve the exact granted Calendar scope');
    $credential=json_decode((string)$exchange['credential_material'],true);
    dzn_google_transport_assert(is_array($credential)&&$credential['refresh_token']==='refresh-token','Exchange must retain renewable credential material');

    $GLOBALS['dzn_google_transport_token_info'][]=array('sub'=>'current-google-subject','user_id'=>'legacy-google-subject','scope'=>'https://www.googleapis.com/auth/calendar.events');
    $inspection=$transport->inspect(array('credential_material'=>$exchange['credential_material']));
    dzn_google_transport_assert($inspection['usable']===true&&$inspection['provider_subject_reference']==='current-google-subject','Inspection must prefer the current Google sub field when both subject names are present');

    $GLOBALS['dzn_google_transport_token_info'][]=array('scope'=>'https://www.googleapis.com/auth/calendar.events');
    $missing=$transport->inspect(array('credential_material'=>$exchange['credential_material']));
    dzn_google_transport_assert($missing['usable']===false&&$missing['failure_reason_code']==='provider_inspection_failed','Inspection must fail closed when Google returns no stable subject');

    echo "Google OAuth transport unit passed\n";
}
