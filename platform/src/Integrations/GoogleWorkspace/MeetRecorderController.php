<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

/**
 * Token-protected REST surface for the operator-owned recording companion.
 *
 * The companion is the only caller. It polls for a pending classroom join, receives the sealed
 * Meet URI for that exact classroom, joins as the organizer on web (which starts auto-recording),
 * and reports the result. A missing or wrong token fails closed; an unconfigured token disables the
 * endpoints entirely.
 */
final class MeetRecorderController {
    private const NAMESPACE='delnavazan-platform/v1';
    private const RATE_LIMIT=120;

    public static function register():void{
        add_action('rest_api_init',array(__CLASS__,'routes'));
    }

    public static function routes():void{
        register_rest_route(self::NAMESPACE,'/meet/recorder/next',array(
            'methods'=>'GET','permission_callback'=>array(__CLASS__,'authorize'),'callback'=>array(__CLASS__,'next'),
        ));
        register_rest_route(self::NAMESPACE,'/meet/recorder/result',array(
            'methods'=>'POST','permission_callback'=>array(__CLASS__,'authorize'),'callback'=>array(__CLASS__,'result'),
        ));
    }

    /** @return bool|\WP_Error */
    public static function authorize(\WP_REST_Request $request){
        $token=MeetRecorderQueue::token();
        if($token==='')return new \WP_Error('meet_recorder_disabled','Recorder companion is not configured',array('status'=>503));
        $header=(string)$request->get_header('authorization');
        if(!preg_match('/^Bearer\s+(.+)$/i',trim($header),$matches))return new \WP_Error('meet_recorder_unauthorized','Bearer token required',array('status'=>401));
        if(!hash_equals($token,trim((string)$matches[1])))return new \WP_Error('meet_recorder_unauthorized','Bearer token rejected',array('status'=>401));
        if(function_exists('get_transient')&&function_exists('set_transient')){
            // A fixed one-minute bucket: steady polling advances the window instead of refreshing a
            // counter's expiry, so a long-lived companion is never locked out by its own traffic.
            $fingerprint=substr(hash('sha256',$token),0,16);
            $key='dzn_meet_recorder_rate_'.$fingerprint.'_'.(int)floor(time()/60);
            $count=(int)get_transient($key);
            if($count>=self::RATE_LIMIT)return new \WP_Error('meet_recorder_rate_limited','Too many requests',array('status'=>429));
            set_transient($key,$count+1,120);
        }
        return true;
    }

    /** @return \WP_REST_Response */
    public static function next(\WP_REST_Request $request){
        return rest_ensure_response(array('job'=>(new MeetRecorderQueue())->next()));
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function result(\WP_REST_Request $request){
        $uid=sanitize_text_field((string)$request->get_param('uid'));
        $classroomId=absint($request->get_param('classroom_id'));
        $state=sanitize_key((string)$request->get_param('state'));
        $detail=sanitize_text_field((string)$request->get_param('detail'));
        if($uid===''||$classroomId<1||!in_array($state,array('joined','left','failed'),true)){
            return new \WP_Error('meet_recorder_result_invalid','Invalid companion result',array('status'=>400));
        }
        (new MeetRecorderQueue())->complete($uid,$classroomId,$state,$detail);
        return rest_ensure_response(array('ok'=>true));
    }
}
