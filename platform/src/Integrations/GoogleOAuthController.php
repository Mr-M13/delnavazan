<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\{ProviderIntegrationRule,ProviderIntegrationService};
use Delnavazan\Platform\Core\Infrastructure\Repository\ProviderIntegrationRepository;

/** Logged-in Teacher OAuth browser flow. PKCE verifier is held only in an authenticated HttpOnly cookie. */
final class GoogleOAuthController {
    private const COOKIE='dzn_google_oauth';
    public static function register():void{
        add_action('admin_post_dzn_google_connect',array(__CLASS__,'start'));
        add_action('admin_post_dzn_google_oauth_callback',array(__CLASS__,'callback'));
    }
    public static function configured():bool{
        return defined('DZN_GOOGLE_OAUTH_CLIENT_ID')&&trim((string)constant('DZN_GOOGLE_OAUTH_CLIENT_ID'))!==''
            &&defined('DZN_GOOGLE_OAUTH_CLIENT_SECRET')&&trim((string)constant('DZN_GOOGLE_OAUTH_CLIENT_SECRET'))!=='';
    }
    public static function start():void{
        if(!is_user_logged_in())auth_redirect();
        check_admin_referer('dzn_google_connect');
        if(!self::configured())self::returnToPortal('google_not_configured');
        $repository=new ProviderIntegrationRepository();
        $teacher=$repository->teacherForPrincipalUser((int)get_current_user_id(),false);
        if(!$teacher)self::returnToPortal('teacher_not_linked');
        $redirect=admin_url('admin-post.php?action=dzn_google_oauth_callback');
        if(!str_starts_with($redirect,'https://'))self::returnToPortal('oauth_redirect_unavailable');
        $scopes=ProviderIntegrationRule::scopeSnapshot(array(
            GoogleCalendarMeetAdapter::CALENDAR_SCOPE,
            GoogleCalendarMeetAdapter::MEET_SCOPE,
        ));
        try{
            $adapter=new GoogleCalendarMeetAdapter();$oauth=new GoogleOAuthTransport();
            $service=new ProviderIntegrationService(null,null,$oauth,$adapter,$adapter);
            $result=$service->beginAuthorization(array(
                'provider_code'=>GoogleCalendarMeetAdapter::PROVIDER_CODE,'teacher_id'=>(int)$teacher->id,
                'client_reference'=>(string)constant('DZN_GOOGLE_OAUTH_CLIENT_ID'),'redirect_uri'=>$redirect,
                'scope_snapshot'=>$scopes,'evidence_channel'=>'authenticated_platform',
                'evidence_reference'=>'teacher-google-connect:'.(int)$teacher->id,'evidence_at'=>gmdate('Y-m-d H:i:s'),
            ),'teacher-google-connect:'.get_current_user_id().':'.wp_generate_uuid4());
            self::setCookie(self::seal(array(
                'state'=>$result['state'],'verifier'=>$result['code_verifier'],'redirect'=>$redirect,
                'teacher_id'=>(int)$teacher->id,'expires_at'=>time()+900,
            )),time()+900);
            $uri=$oauth->authorizationUri(array(
                'client_reference'=>(string)constant('DZN_GOOGLE_OAUTH_CLIENT_ID'),'redirect_uri'=>$redirect,
                'scope_snapshot'=>$scopes,'state'=>$result['state'],'code_challenge'=>$result['code_challenge'],
            ));
            $parts=parse_url($uri);
            if(!$parts||($parts['scheme']??'')!=='https'||strtolower((string)($parts['host']??''))!=='accounts.google.com')throw new \RuntimeException('oauth_authorization_target_untrusted');
            wp_redirect($uri,302,'Delnavazan');exit;
        }catch(\Throwable){self::clearCookie();self::returnToPortal('google_connect_failed');}
    }
    public static function callback():void{
        if(!is_user_logged_in())auth_redirect();
        try{
            $payload=self::open((string)($_COOKIE[self::COOKIE]??''));
            self::clearCookie();
            $state=trim((string)($_GET['state']??''));$code=trim((string)($_GET['code']??''));
            if($state===''||$code===''||!hash_equals((string)$payload['state'],$state)||(int)$payload['expires_at']<time())throw new \RuntimeException('oauth_callback_invalid');
            $repository=new ProviderIntegrationRepository();$teacher=$repository->teacherForPrincipalUser((int)get_current_user_id(),false);
            if(!$teacher||(int)$teacher->id!==(int)$payload['teacher_id'])throw new \RuntimeException('oauth_principal_changed');
            $adapter=new GoogleCalendarMeetAdapter();$oauth=new GoogleOAuthTransport();
            $service=new ProviderIntegrationService(null,null,$oauth,$adapter,$adapter);
            $service->completeAuthorization(array(
                'state'=>$state,'code'=>$code,'code_verifier'=>(string)$payload['verifier'],'redirect_uri'=>(string)$payload['redirect'],
            ),'teacher-google-complete:'.hash('sha256',$state));
            self::returnToPortal('google_connected');
        }catch(\Throwable){self::clearCookie();self::returnToPortal('google_connect_failed');}
    }
    private static function returnToPortal(string $status):never{
        wp_safe_redirect(add_query_arg('google_status',rawurlencode($status),home_url('/teacher-portal/account/')));exit;
    }
    private static function setCookie(string $value,int $expires):void{
        setcookie(self::COOKIE,$value,array('expires'=>$expires,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax'));
    }
    private static function clearCookie():void{setcookie(self::COOKIE,'',array('expires'=>time()-3600,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax'));}
    private static function seal(array $payload):string{
        $nonce=random_bytes(12);$tag='';$plain=wp_json_encode($payload,JSON_UNESCAPED_SLASHES);
        $cipher=openssl_encrypt((string)$plain,'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,$nonce,$tag);
        if(!is_string($cipher))throw new \RuntimeException('oauth_cookie_unavailable');
        return rtrim(strtr(base64_encode($nonce.$tag.$cipher),'+/','-_'),'=');
    }
    private static function open(string $value):array{
        $raw=base64_decode(strtr($value,'-_','+/'),true);
        if(!is_string($raw)||strlen($raw)<29)throw new \RuntimeException('oauth_cookie_invalid');
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));
        $decoded=is_string($plain)?json_decode($plain,true):null;
        if(!is_array($decoded)||!isset($decoded['state'],$decoded['verifier'],$decoded['redirect'],$decoded['teacher_id'],$decoded['expires_at']))throw new \RuntimeException('oauth_cookie_invalid');
        return $decoded;
    }
    private static function key():string{return hash('sha256',wp_salt('secure_auth').'dzn_google_oauth_cookie',true);}
}
