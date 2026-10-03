<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\Port\ProviderOAuthPort;

/**
 * Live Google OAuth transport.
 *
 * Credentials are deployment configuration only. Nothing in this class persists, logs or renders
 * client secrets, access tokens or refresh tokens. The transport is inert unless both constants are
 * defined and the authority-supplied client reference exactly matches the configured client id.
 */
final class GoogleOAuthTransport implements ProviderOAuthPort {
    private const TOKEN_URI='https://oauth2.googleapis.com/token';
    private const REVOKE_URI='https://oauth2.googleapis.com/revoke';
    private const TOKEN_INFO_URI='https://oauth2.googleapis.com/tokeninfo';

    public function authorizationUri(array $request):string{
        $this->configuration((string)($request['client_reference']??''));
        return (new GoogleCalendarMeetAdapter())->authorizationUri($request);
    }

    public function exchange(array $request):array{
        [$client,$secret]=$this->configuration((string)($request['client_reference']??''));
        $response=$this->post(self::TOKEN_URI,array(
            'client_id'=>$client,'client_secret'=>$secret,'code'=>(string)($request['code']??''),
            'code_verifier'=>(string)($request['code_verifier']??''),'grant_type'=>'authorization_code',
            'redirect_uri'=>(string)($request['redirect_uri']??''),
        ));
        $access=$this->required($response,'access_token');
        $refresh=$this->required($response,'refresh_token');
        $scope=$this->required($response,'scope');
        $subject=$this->subject($access);
        return array(
            'granted_scope_snapshot'=>$scope,
            'provider_subject_reference'=>$subject,
            'credential_material'=>$this->encodeCredential($access,$refresh,$response),
            'access_valid_until_utc'=>gmdate('Y-m-d H:i:s',time()+max(60,(int)($response['expires_in']??3600))),
            'consent_version'=>'google-oauth2-v2',
        );
    }

    public function refresh(array $connection):array{
        $credential=$this->decodeCredential((string)($connection['credential_material']??''));
        [$client,$secret]=$this->configuration();
        $response=$this->post(self::TOKEN_URI,array(
            'client_id'=>$client,'client_secret'=>$secret,'refresh_token'=>$credential['refresh_token'],
            'grant_type'=>'refresh_token',
        ));
        $access=$this->required($response,'access_token');
        $expires=max(60,(int)($response['expires_in']??3600));
        return array(
            'credential_material'=>$this->encodeCredential($access,$credential['refresh_token'],$response),
            'access_valid_until_utc'=>gmdate('Y-m-d H:i:s',time()+$expires),
        );
    }

    public function revoke(array $connection):array{
        $credential=$this->decodeCredential((string)($connection['credential_material']??''));
        $response=wp_remote_post(self::REVOKE_URI,array(
            'timeout'=>15,'redirection'=>0,
            'headers'=>array('Content-Type'=>'application/x-www-form-urlencoded'),
            'body'=>http_build_query(array('token'=>$credential['refresh_token']),'','&',PHP_QUERY_RFC3986),
        ));
        if(is_wp_error($response))return array('revoked'=>false,'failure_reason_code'=>'provider_transport_failed');
        $code=(int)wp_remote_retrieve_response_code($response);
        return array('revoked'=>$code>=200&&$code<300,'failure_reason_code'=>$code>=200&&$code<300?null:'provider_revoke_refused');
    }

    public function inspect(array $connection):array{
        try{
            $credential=$this->decodeCredential((string)($connection['credential_material']??''));
            $response=wp_remote_get(self::TOKEN_INFO_URI.'?'.http_build_query(array('access_token'=>$credential['access_token']),'','&',PHP_QUERY_RFC3986),array('timeout'=>15,'redirection'=>0));
            if(is_wp_error($response)||(int)wp_remote_retrieve_response_code($response)!==200)return array('usable'=>false,'provider_subject_reference'=>null,'granted_scope_snapshot'=>null,'failure_reason_code'=>'provider_inspection_failed');
            $body=json_decode((string)wp_remote_retrieve_body($response),true);
            if(!is_array($body))throw new \RuntimeException('provider_response_invalid');
            return array('usable'=>true,'provider_subject_reference'=>$this->required($body,'sub'),'granted_scope_snapshot'=>$this->required($body,'scope'),'failure_reason_code'=>null);
        }catch(\Throwable){
            return array('usable'=>false,'provider_subject_reference'=>null,'granted_scope_snapshot'=>null,'failure_reason_code'=>'provider_inspection_failed');
        }
    }

    private function configuration(string $expectedClient=''):array{
        if(!defined('DZN_GOOGLE_OAUTH_CLIENT_ID')||!defined('DZN_GOOGLE_OAUTH_CLIENT_SECRET'))throw new \RuntimeException('google_oauth_not_configured');
        $client=trim((string)constant('DZN_GOOGLE_OAUTH_CLIENT_ID'));
        $secret=trim((string)constant('DZN_GOOGLE_OAUTH_CLIENT_SECRET'));
        if($client===''||$secret==='')throw new \RuntimeException('google_oauth_not_configured');
        if($expectedClient!==''&&!hash_equals($client,$expectedClient))throw new \RuntimeException('google_oauth_client_mismatch');
        return array($client,$secret);
    }

    private function post(string $uri,array $fields):array{
        foreach($fields as $value)if(!is_scalar($value)||trim((string)$value)==='')throw new \InvalidArgumentException('google_oauth_request_incomplete');
        $response=wp_remote_post($uri,array(
            'timeout'=>15,'redirection'=>0,
            'headers'=>array('Content-Type'=>'application/x-www-form-urlencoded'),
            'body'=>http_build_query($fields,'','&',PHP_QUERY_RFC3986),
        ));
        if(is_wp_error($response))throw new \RuntimeException('google_oauth_transport_failed');
        $code=(int)wp_remote_retrieve_response_code($response);
        $body=json_decode((string)wp_remote_retrieve_body($response),true);
        if($code<200||$code>=300||!is_array($body))throw new \RuntimeException('google_oauth_provider_refused');
        return $body;
    }

    private function subject(string $access):string{
        $response=wp_remote_get(self::TOKEN_INFO_URI.'?'.http_build_query(array('access_token'=>$access),'','&',PHP_QUERY_RFC3986),array('timeout'=>15,'redirection'=>0));
        if(is_wp_error($response)||(int)wp_remote_retrieve_response_code($response)!==200)throw new \RuntimeException('google_oauth_subject_unavailable');
        $body=json_decode((string)wp_remote_retrieve_body($response),true);
        if(!is_array($body))throw new \RuntimeException('google_oauth_subject_unavailable');
        return $this->required($body,'sub');
    }

    private function encodeCredential(string $access,string $refresh,array $response):string{
        $value=wp_json_encode(array('access_token'=>$access,'refresh_token'=>$refresh,'token_type'=>(string)($response['token_type']??'Bearer'),'expires_in'=>(int)($response['expires_in']??3600)),JSON_UNESCAPED_SLASHES);
        if(!is_string($value))throw new \RuntimeException('google_oauth_credential_encode_failed');
        return $value;
    }

    private function decodeCredential(string $material):array{
        $value=json_decode($material,true);
        if(!is_array($value)||trim((string)($value['access_token']??''))===''||trim((string)($value['refresh_token']??''))==='')throw new \RuntimeException('google_oauth_credential_invalid');
        return $value;
    }

    private function required(array $source,string $key):string{
        $value=trim((string)($source[$key]??''));
        if($value==='')throw new \RuntimeException('google_oauth_response_incomplete');
        return $value;
    }
}
