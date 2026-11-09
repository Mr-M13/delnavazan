<?php
namespace Delnavazan\Platform\Core\Application\GodMode;

/**
 * Server-side designation and revocation of the permanent Super Tester account.
 *
 * There is no public role and no self-service assignment. The only persisted authority is one
 * WordPress user id plus a keyed digest of that account's verified email, a per-user capability and
 * an append-only authorisation row. Ordinary administrators cannot acquire God Mode by request.
 */
final class GodModeAuthority {
    public const USER_OPTION='dzn_god_mode_super_tester_user_id';
    public const DIGEST_OPTION='dzn_god_mode_super_tester_email_digest';

    public static function bootstrap():void{
        try{
            global $wpdb;
            $table=$wpdb->prefix.'dzn_god_mode_authorisations';
            if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table)return;
            $userId=(int)get_option(self::USER_OPTION,0);
            if($userId<1){
                // One-time designation bootstrap only. The granted authority is still persisted by
                // user id and re-proved through the stored digest on every later decision.
                $candidate=get_user_by('email',GodModeRule::DESIGNATED_EMAIL);
                if(!$candidate||empty($candidate->ID)||!user_can($candidate,'manage_options'))return;
                $userId=(int)$candidate->ID;
                $existing=$wpdb->get_row($wpdb->prepare("SELECT state FROM {$table} WHERE wordpress_user_id=%d",$userId));
                if($existing&&(string)$existing->state==='revoked')return;
                $digest=self::emailDigest(GodModeRule::DESIGNATED_EMAIL);
                if($digest==='')return;
                update_option(self::USER_OPTION,$userId,false);
                update_option(self::DIGEST_OPTION,$digest,false);
            }
            $user=get_userdata($userId);
            if(!$user||empty($user->ID))return;
            $email=strtolower(trim((string)($user->user_email??'')));
            if($email!==GodModeRule::DESIGNATED_EMAIL)return;
            if(!user_can($user,'manage_options')){self::removeCapability($userId);return;}
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE wordpress_user_id=%d",$userId));
            if($row&&(string)$row->state==='revoked'){self::removeCapability($userId);return;}
            $digest=self::emailDigest($email);
            if($digest==='')return;
            if((string)get_option(self::DIGEST_OPTION,'')!==$digest)update_option(self::DIGEST_OPTION,$digest,false);
            if(!$user->has_cap(GodModeRule::CAPABILITY))$user->add_cap(GodModeRule::CAPABILITY);
            if(!$row){
                $now=gmdate('Y-m-d H:i:s');
                $wpdb->insert($table,array('uid'=>\Delnavazan\Platform\Core\Support\Identifier::uid(),'wordpress_user_id'=>$userId,'email_digest'=>$digest,'state'=>'active','capability_version'=>GodModeRule::CAPABILITY_VERSION,'granted_at'=>$now,'granted_by'=>0,'created_at'=>$now,'updated_at'=>$now));
            }
        }catch(\Throwable $error){do_action('dzn_god_mode_bootstrap_failed',get_class($error));}
    }

    public static function currentUserId():int{
        $userId=function_exists('get_current_user_id')?(int)get_current_user_id():0;
        return self::isAuthorised($userId)?$userId:0;
    }

    public static function current():int{
        $userId=self::currentUserId();
        if($userId<1)throw new \RuntimeException('god_mode_forbidden');
        return $userId;
    }

    public static function require():int{return self::current();}

    public static function isAuthorised(int $userId):bool{
        if($userId<1)return false;
        $context=self::context($userId);
        return GodModeRule::authorityAllows($context);
    }

    /** @return array<string,mixed> */
    public static function context(int $userId):array{
        $user=$userId>0?get_userdata($userId):false;
        $designated=(int)get_option(self::USER_OPTION,0);
        $email=$user&&is_object($user)?strtolower(trim((string)($user->user_email??''))):'';
        $state='missing';
        try{
            global $wpdb;$table=$wpdb->prefix.'dzn_god_mode_authorisations';
            if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table){
                $row=$wpdb->get_row($wpdb->prepare("SELECT state FROM {$table} WHERE wordpress_user_id=%d",$userId));
                if($row)$state=(string)$row->state;
            }
        }catch(\Throwable){}
        return array(
            'designated_user_id'=>$designated,
            'user_id'=>$userId,
            'email_digest'=>$email===''?'':self::emailDigest($email),
            'stored_email_digest'=>(string)get_option(self::DIGEST_OPTION,''),
            'manage_options'=>$user&&is_object($user)?(bool)user_can($user,'manage_options'):false,
            'capability'=>$user&&is_object($user)?(bool)$user->has_cap(GodModeRule::CAPABILITY):false,
            'auth_state'=>$state,
        );
    }

    public static function emailDigest(string $email):string{
        $email=strtolower(trim($email));
        if($email==='')return '';
        return hash_hmac('sha256','god-mode-email:'.$email,function_exists('wp_salt')?wp_salt('dzn_god_mode'):'dzn-god-mode-local');
    }

    public static function designatedUserId():int{return (int)get_option(self::USER_OPTION,0);}

    /** Safe revocation: capability and authority row stop immediately, history is untouched. */
    public static function revoke(int $actorUserId,string $reasonCode):void{
        $actor=self::require();
        if($actor!==$actorUserId)throw new \RuntimeException('god_mode_forbidden');
        $reason=preg_replace('/[^a-z0-9_]/','',strtolower($reasonCode));
        if($reason===''||strlen($reason)>64)$reason='operator_revoked';
        $now=gmdate('Y-m-d H:i:s');
        global $wpdb;$table=$wpdb->prefix.'dzn_god_mode_authorisations';
        $changed=$wpdb->update($table,array('state'=>'revoked','revoked_at'=>$now,'revoked_by'=>$actor,'revocation_reason'=>$reason,'updated_at'=>$now),array('wordpress_user_id'=>$actor));
        if($changed===false||$changed<1)throw new \RuntimeException('god_mode_revocation_failed');
        self::removeCapability($actor);
        GodModeAudit::record($actor,'authority_revoke','authorisation',$actor,$reason);
    }

    private static function removeCapability(int $userId):void{
        $user=get_userdata($userId);
        if($user&&is_object($user)&&$user->has_cap(GodModeRule::CAPABILITY))$user->remove_cap(GodModeRule::CAPABILITY);
    }
}
