<?php
namespace Delnavazan\Platform\Core\Application\Recording;

/**
 * Signed recorder-facing REST boundary.
 *
 * The recorder authenticates every pull with a keyed envelope. The callback is verified before a
 * payload can become durable state. The playback grant endpoint never accepts a lesson, principal,
 * path or lifecycle state from the browser.
 */
final class RecordingServiceRestController {
    public const NAMESPACE='delnavazan-platform/v1';

    public static function register():void{
        register_rest_route(self::NAMESPACE,'/recordings/playback',array(
            'methods'=>'POST','callback'=>array(__CLASS__,'playback'),'permission_callback'=>'is_user_logged_in',
        ));
        register_rest_route(self::NAMESPACE,'/recording-service/pull',array(
            'methods'=>'POST','callback'=>array(__CLASS__,'pull'),'permission_callback'=>'__return_true',
        ));
        register_rest_route(self::NAMESPACE,'/recording-service/callback',array(
            'methods'=>'POST','callback'=>array(__CLASS__,'callback'),'permission_callback'=>'__return_true',
        ));
        register_rest_route(self::NAMESPACE,'/recording-service/health',array(
            'methods'=>'POST','callback'=>array(__CLASS__,'health'),'permission_callback'=>'__return_true',
        ));
        register_rest_route(self::NAMESPACE,'/recording-service/deletion-authorisation',array(
            'methods'=>'POST','callback'=>array(__CLASS__,'deletionAuthorisation'),'permission_callback'=>'__return_true',
        ));
    }

    public static function playback(\WP_REST_Request $request):\WP_REST_Response|\WP_Error{
        $nonce=(string)($request->get_header('x_wp_nonce')?:'');
        if(!wp_verify_nonce($nonce,'wp_rest'))return new \WP_Error('recording_playback_nonce_invalid','A fresh session is required.',array('status'=>403));
        $userId=get_current_user_id();$bucket='dzn_recording_playback_'.$userId;
        $count=(int)get_transient($bucket);
        if($count>=30)return new \WP_Error('recording_playback_rate_limited','Too many playback requests.',array('status'=>429));
        set_transient($bucket,$count+1,MINUTE_IN_SECONDS);
        $body=(array)$request->get_json_params();
        $recordingUid=trim((string)($body['recording_uid']??''));
        try{
            $result=(new RecordingPlaybackGrantService())->issueForRecording($recordingUid,'rest');
            $response=new \WP_REST_Response($result,200);
            $response->header('Cache-Control','no-store, private, max-age=0');
            $response->header('Referrer-Policy','no-referrer');
            return $response;
        }catch(\Throwable $error){
            $reason=(string)$error->getMessage();
            $code=str_starts_with($reason,'recording_playback_denied:')?substr($reason,25):'recording_playback_denied';
            return new \WP_Error('recording_playback_denied',preg_replace('/[^a-z0-9_]/','_',$code),array('status'=>403));
        }
    }

    public static function pull(\WP_REST_Request $request):\WP_REST_Response|\WP_Error{
        try{
            $envelope=self::envelope($request);
            $verified=RecordingSignature::verify($envelope,RecordingFeatureFlags::serviceSecret(),RecordingControlPlane::PULL_AUDIENCE,time());
            $payload=$verified['payload'];
            if((string)($payload['service_id']??'')==='')throw new \RuntimeException('recording_service_identity_missing');
            $limit=max(1,min(100,(int)($payload['limit']??20)));
            $commands=(new RecordingControlPlane())->claimCommands($limit);
            $response=new \WP_REST_Response(array('commands'=>$commands,'server_time'=>time()),200);
            $response->header('Cache-Control','no-store, private, max-age=0');
            return $response;
        }catch(\Throwable $error){
            return new \WP_Error('recording_service_pull_denied',self::safeReason($error),array('status'=>401));
        }
    }

    public static function callback(\WP_REST_Request $request):\WP_REST_Response|\WP_Error{
        try{
            $result=(new RecordingControlPlane())->handleCallback(self::envelope($request));
            return new \WP_REST_Response(array('ok'=>true,'result'=>$result),200);
        }catch(\Throwable $error){
            return new \WP_Error('recording_callback_denied',self::safeReason($error),array('status'=>409));
        }
    }

    public static function health(\WP_REST_Request $request):\WP_REST_Response|\WP_Error{
        try{
            $verified=RecordingSignature::verify(self::envelope($request),RecordingFeatureFlags::serviceSecret(),RecordingControlPlane::CALLBACK_AUDIENCE,time());
            $payload=$verified['payload'];
            update_option(RecordingFeatureFlags::SERVICE_HEALTH_OPTION,array(
                'received_at'=>time(),'ready'=>($payload['ready']??false)===true,
                'storage_free_bytes'=>(int)($payload['storage_free_bytes']??0),
                'storage_free_percent'=>(float)($payload['storage_free_percent']??0),
                'archive_mount_identity'=>(string)($payload['archive_mount_identity']??''),
                'archive_available'=>($payload['archive_available']??false)===true,
                'archive_free_bytes'=>(int)($payload['archive_free_bytes']??0),
                'tailscale_connected'=>($payload['tailscale_connected']??false)===true,
                'secure_transport'=>(string)($payload['secure_transport']??'tailscale'),
                'secure_transport_connected'=>($payload['secure_transport_connected']??($payload['tailscale_connected']??false))===true,
                'egress_cpu_percent'=>(float)($payload['egress_cpu_percent']??0),
                'egress_memory_bytes'=>(int)($payload['egress_memory_bytes']??0),
                'oldest_queue_age_seconds'=>(int)($payload['oldest_queue_age_seconds']??0),
                'recorder_version'=>(string)($payload['recorder_version']??''),
            ),false);
            return new \WP_REST_Response(array('ok'=>true),200);
        }catch(\Throwable $error){
            return new \WP_Error('recording_health_denied',self::safeReason($error),array('status'=>401));
        }
    }

    public static function deletionAuthorisation(\WP_REST_Request $request):\WP_REST_Response|\WP_Error{
        try{
            $response=(new RecordingControlPlane())->deletionAuthorisation(self::envelope($request));
            return new \WP_REST_Response($response,200);
        }catch(\Throwable $error){
            return new \WP_Error('recording_deletion_authorisation_denied',self::safeReason($error),array('status'=>401));
        }
    }

    private static function envelope(\WP_REST_Request $request):array{
        $header=(string)($request->get_header('x_dzn_signature')?:'');
        if($header==='')throw new \RuntimeException('recording_signature_missing');
        $decoded=base64_decode(strtr($header,'-_','+/'),true);
        if($decoded===false)throw new \RuntimeException('recording_signature_invalid');
        $envelope=json_decode($decoded,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($envelope))throw new \RuntimeException('recording_signature_invalid');
        return $envelope;
    }

    private static function safeReason(\Throwable $error):string{
        $message=(string)$error->getMessage();
        return preg_match('/^[a-z0-9_:]{3,96}$/D',$message)===1?$message:'recording_service_request_denied';
    }
}
