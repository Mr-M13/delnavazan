<?php
namespace Delnavazan\Platform\Public;

/** Self-registration at booking. This creates a WordPress login, not Student authority. */
final class StudentBookingAccountRestController {
    public static function register():void{
        register_rest_route('delnavazan-platform/v1','/booking-account',[
            'methods'=>'POST','permission_callback'=>'__return_true','callback'=>[self::class,'create'],
        ]);
    }

    public static function create(\WP_REST_Request $request):\WP_REST_Response{
        if(get_current_user_id()>0)return new \WP_REST_Response(['success'=>false,'code'=>'already_signed_in'],409);
        $input=$request->get_json_params();
        if(!is_array($input)||array_diff(array_keys($input),['full_name','email','password']))return new \WP_REST_Response(['success'=>false,'code'=>'invalid_account'],400);
        foreach(['full_name','email','password'] as $field)if(!isset($input[$field])||!is_string($input[$field]))return new \WP_REST_Response(['success'=>false,'code'=>'invalid_account'],400);
        $email=strtolower(trim($input['email']));$name=trim(sanitize_text_field($input['full_name']));$password=$input['password'];
        if(!is_email($email)||$name===''||strlen($name)>764||strlen($password)<12||strlen($password)>256)return new \WP_REST_Response(['success'=>false,'code'=>'invalid_account'],400);
        if(email_exists($email))return new \WP_REST_Response(['success'=>false,'code'=>'account_exists'],409);
        if(!(new BookingRequestRateLimiter())->allow())return new \WP_REST_Response(['success'=>false,'code'=>'rate_limited'],429);
        $userId=wp_insert_user(['user_login'=>$email,'user_email'=>$email,'user_pass'=>$password,'display_name'=>$name,'role'=>'subscriber']);
        unset($password,$input['password']);
        if(is_wp_error($userId))return new \WP_REST_Response(['success'=>false,'code'=>$userId->get_error_code()==='existing_user_email'?'account_exists':'account_unavailable'],$userId->get_error_code()==='existing_user_email'?409:500);
        wp_set_current_user((int)$userId);wp_set_auth_cookie((int)$userId,true,is_ssl());
        return new \WP_REST_Response(['success'=>true,'nonce'=>wp_create_nonce('wp_rest')],201);
    }
}
