<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\Checkout\CheckoutInitiationService;

final class StudentCheckoutController {
    public static function register():void{add_action('wp_ajax_dzn_student_checkout_start',array(__CLASS__,'start'));}
    public static function start():never{
        if(!is_user_logged_in())self::respond(false,'checkout_unavailable',401);
        if(!check_ajax_referer('dzn_student_checkout','nonce',false))self::respond(false,'checkout_unavailable',403);
        $allowed=array('action','nonce','obligation_id');
        foreach(array_keys($_POST) as $key)if(!in_array((string)$key,$allowed,true))self::respond(false,'checkout_unavailable',400);
        $raw=wp_unslash($_POST['obligation_id']??'');
        if(!is_string($raw)||preg_match('/^\d{1,15}$/D',$raw)!==1)self::respond(false,'checkout_unavailable',400);
        try{
            $result=(new CheckoutInitiationService())->initiate((int)$raw);
            self::respond(true,array('checkout_uid'=>$result['checkout_uid'],'state'=>$result['state']),200);
        }catch(\Throwable){self::respond(false,'checkout_unavailable',409);}
    }
    private static function respond(bool $ok,array|string $value,int $status):never{
        if($ok)wp_send_json_success($value,$status);
        wp_send_json_error(array('code'=>$value),$status);
    }
}
