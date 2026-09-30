<?php
namespace Delnavazan\Platform\Portals;
final class PortalPublicActionController {
    /** The registered surface of each public route. A callback passes its own member, so no request text can select another surface's admission bucket or denial audit row. */
    private const SURFACE_JOIN='portal_public_join';
    private const SURFACE_ABSENCE='portal_public_absence';
    public static function register():void { register_rest_route('delnavazan-platform/v1','/portal/join',array('methods'=>'GET','permission_callback'=>'__return_true','callback'=>array(__CLASS__,'join'))); register_rest_route('delnavazan-platform/v1','/portal/absence',array('methods'=>'GET','permission_callback'=>'__return_true','callback'=>array(__CLASS__,'absence'))); register_rest_route('delnavazan-platform/v1','/portal/absence/confirm',array('methods'=>'POST','permission_callback'=>'__return_true','callback'=>array(__CLASS__,'absence'))); }
    /**
     * §15.6 — the refusal evidence (the refused action row and its denial row, §15.2 order) is written
     * by `recordRefusal()` inside the one root-serialized transaction, so a persistence failure of
     * either row rolls both back and this controller never writes denial evidence of its own.  A
     * refusal the post-delegation outcome transaction already recorded is passed as `$alreadyRecorded`
     * because that transaction committed the refused outcome row and its denial together.
     *
     * The denial row's `surface` is the invoked route's own fixed surface: `recordRefusal()` maps the
     * purpose constant this callback passes (`PortalRule::JOIN` or `PortalRule::ABSENCE`) through its
     * `declaredSurface()` member, so no query value or path text can move a refusal's audit row.
     *
     * §15.6 — only a declared business refusal (`PortalRule::refusalReason()`) appends that evidence.  A
     * persistence, corruption or infrastructure failure — `portal_action_evidence_persistence_failed`,
     * `portal_capability_persistence_failed`, an unexpected `\Throwable` — has already rolled its own
     * transaction back, so it is re-raised unchanged and is never converted into a new durable refusal
     * record: a transient failed insert or commit can never be followed by refusal evidence.
     */
    private static function refused(\Throwable $e,string $purpose,string $handle,bool $alreadyRecorded=false):\WP_REST_Response { $reason=PortalRule::refusalReason($e);if($reason===null)throw $e;if(!$alreadyRecorded)(new PortalPublicActionService())->recordRefusal($purpose,$handle,$reason);$response=new \WP_REST_Response(array('code'=>'portal_action_unavailable'),404);$response->header('Cache-Control','no-store');return $response; }
    /** Best-effort public abuse control on the invoked route's own fixed surface. Runs for every public-route request before the option gate and before any capability verification. Everything up to and including a completed allow() is deliberately fail-open; only a completed allow() that returns false refuses portal_rate_limited. */
    private static function admit(string $surface,string $handle):void { try { $signal=is_string($_SERVER['REMOTE_ADDR']??null)?(string)$_SERVER['REMOTE_ADDR']:''; $fingerprint=hash_hmac('sha256',$signal.'|'.$handle,wp_salt('dzn_portal_public_rate')); $allowed=(new PortalRateLimiter())->allow($surface,$fingerprint); } catch(\Throwable $e){ /* declared fail-open: a fingerprinting, cache or limiter failure never blocks a public request */ return; } if(false===$allowed)throw new \InvalidArgumentException('portal_rate_limited'); }
    public static function join(\WP_REST_Request $request):\WP_REST_Response { $handle=(string)$request->get_param('handle');try { self::admit(self::SURFACE_JOIN,$handle); if((string)get_option(PortalRule::PUBLIC_ACTION_OPTION,'')!==PortalRule::PUBLIC_ACTION_ENABLED_VALUE)throw new \InvalidArgumentException('portal_route_disabled');$url=(new PortalCapabilityService())->join($handle,(string)$request->get_param('token'));$response=new \WP_REST_Response(null,302,array('Location'=>$url));$response->header('Referrer-Policy','no-referrer');$response->header('Cache-Control','no-store');return $response; } catch(\Throwable $e){return self::refused($e,PortalRule::JOIN,$handle,$e instanceof PortalPublicActionRefusalRecorded);} }
    public static function absence(\WP_REST_Request $request):\WP_REST_Response { $handle=(string)$request->get_param('handle');try { self::admit(self::SURFACE_ABSENCE,$handle); if((string)get_option(PortalRule::PUBLIC_ACTION_OPTION,'')!==PortalRule::PUBLIC_ACTION_ENABLED_VALUE)throw new \InvalidArgumentException('portal_route_disabled');$service=new PortalPublicActionService();$token=(string)$request->get_param('token');if($request->get_method()==='GET'){$body=$service->renderAbsenceConfirmation($handle,$token);$response=new \WP_REST_Response($body,200);$response->header('Content-Type','text/html; charset='.get_bloginfo('charset'));}else{$body=$service->confirmAbsence($handle,$token,(string)$request->get_param('confirmation'));$response=new \WP_REST_Response($body,200);} $response->header('Cache-Control','no-store');$response->header('Referrer-Policy','no-referrer');return$response; } catch(\Throwable $e){return self::refused($e,PortalRule::ABSENCE,$handle,$e instanceof PortalPublicActionRefusalRecorded);} }
}
