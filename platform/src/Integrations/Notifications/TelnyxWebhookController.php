<?php
namespace Delnavazan\Platform\Integrations\Notifications;

use Delnavazan\Platform\Core\Application\IntegrationSettingsService;

/**
 * The only public Telnyx webhook entry point.
 *
 * The caller is a provider, not a WordPress principal. No cookie, nonce or capability is consulted:
 * the Ed25519 signature over the exact raw body is the entire trust decision. The endpoint returns a
 * small outcome code and never reflects provider payloads, recipient data or credentials.
 */
final class TelnyxWebhookController {
    public const ROUTE_NAMESPACE='delnavazan-platform/v1';
    public const ROUTE='/provider-events/telnyx';
    public static function register():void{
        register_rest_route(self::ROUTE_NAMESPACE,self::ROUTE,array(
            'methods'=>'POST','permission_callback'=>'__return_true','callback'=>array(__CLASS__,'handle'),
        ));
    }
    public static function url():string{
        return function_exists('rest_url')?rest_url(self::ROUTE_NAMESPACE.self::ROUTE):'';
    }
    public static function handle(\WP_REST_Request $request){
        $rawBody=(string)$request->get_body();
        $headers=array();
        foreach((array)$request->get_headers() as $name=>$value)$headers[str_replace('_','-',strtolower((string)$name))]=is_array($value)?(string)reset($value):(string)$value;
        try{
            $settings=new IntegrationSettingsService();
            $result=TelnyxWebhookService::fromSettings($settings)->receive($rawBody,$headers,time());
            $status=(int)$result['http'];
            $outcome=(string)$result['outcome'];
        }catch(\Throwable $error){
            $status=500;$outcome='processing_failed';
        }
        $response=new \WP_REST_Response(array('status'=>$outcome),$status);
        $response->header('Cache-Control','no-store');
        return $response;
    }
}
