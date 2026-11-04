<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

/**
 * Administrator-only browser flow for the single Delnavazan Workspace organizer account.
 * Teachers never see or use these actions.
 */
final class GoogleWorkspaceOAuthController {
    public static function register():void{
        add_action('admin_post_dzn_meet_connect',array(__CLASS__,'connect'));
        add_action('admin_post_dzn_meet_oauth_callback',array(__CLASS__,'callback'));
        add_action('admin_post_dzn_meet_disconnect',array(__CLASS__,'disconnect'));
        add_action('admin_post_dzn_meet_revoke',array(__CLASS__,'revoke'));
    }

    public static function connect():never{
        self::authorise();
        check_admin_referer('dzn_meet_connect');
        try{
            $uri=(new GoogleWorkspaceOAuthService())->begin();
            $parts=parse_url($uri);
            if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||strtolower((string)($parts['host']??''))!=='accounts.google.com')throw new \RuntimeException('meet_oauth_target_untrusted');
            wp_redirect($uri,302,'Delnavazan');exit;
        }catch(\Throwable $error){
            self::returnToSettings(self::status($error));
        }
    }

    public static function callback():never{
        self::authorise();
        try{
            $result=(new GoogleWorkspaceOAuthService())->complete((string)($_GET['state']??''),(string)($_GET['code']??''));
            self::returnToSettings((string)($result['connection_state']??'')==='connected'?'meet_connected':'meet_callback_failed');
        }catch(\Throwable $error){
            self::returnToSettings(self::status($error));
        }
    }

    public static function disconnect():never{
        self::authorise();
        check_admin_referer('dzn_meet_disconnect');
        try{
            $result=(new GoogleWorkspaceOAuthService())->disconnect(false);
            self::returnToSettings((string)($result['connection_state']??'')==='disconnected'?'meet_disconnected':'meet_disconnect_failed');
        }catch(\Throwable $error){self::returnToSettings(self::status($error));}
    }

    public static function revoke():never{
        self::authorise();
        check_admin_referer('dzn_meet_revoke');
        try{
            $result=(new GoogleWorkspaceOAuthService())->disconnect(true);
            self::returnToSettings((string)($result['connection_state']??'')==='revoked'?'meet_revoked':'meet_revoke_incomplete');
        }catch(\Throwable $error){self::returnToSettings(self::status($error));}
    }

    private static function authorise():void{
        if(!is_user_logged_in())auth_redirect();
        if(!current_user_can('manage_options')&&!current_user_can('dzn_manage_provider_integrations'))wp_die('Forbidden',403);
    }

    private static function returnToSettings(string $status):never{
        wp_safe_redirect(add_query_arg(array('page'=>'dzn-integration-google','meet_result'=>$status),admin_url('admin.php')));exit;
    }

    private static function status(\Throwable $error):string{
        return match($error->getMessage()){
            'meet_oauth_not_configured'=>'meet_not_configured',
            'meet_oauth_https_redirect_required'=>'meet_redirect_https_required',
            'meet_oauth_redirect_mismatch'=>'meet_redirect_mismatch',
            'meet_oauth_scope_mismatch'=>'meet_scope_mismatch',
            'meet_oauth_organizer_mismatch'=>'meet_organizer_mismatch',
            'meet_oauth_state_expired','meet_oauth_state_settled','meet_oauth_state_invalid','meet_oauth_callback_invalid','meet_oauth_verifier_unavailable'=>'meet_callback_invalid',
            'meet_not_connected'=>'meet_not_connected',
            'Unauthorized'=>'meet_forbidden',
            default=>'meet_connect_failed',
        };
    }
}
