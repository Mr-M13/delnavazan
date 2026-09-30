<?php
namespace Delnavazan\Platform\Integrations\Payment\Stripe;

use Delnavazan\Platform\Core\Application\PaymentExecution\{PaymentEventIntakeService,PaymentExecutionRule,PaymentExecutionSupport};

/**
 * The only public entry point of Phase 2A.2-T (contract §9.1, §9.2, §9.8).
 *
 * It performs no WordPress authentication: the caller is a provider, not a principal, and the entire
 * trust decision is deferred to signature verification. The account segment is resolved *before* the
 * body is read, because verification needs the matching account, mode and signing secret. Every inbound
 * request is receipted, including a refusal decided before parsing; rate limiting is deliberately not
 * applied — signature verification is the control, and a rate limit would discard legitimately retried
 * provider traffic.
 *
 * [C8-1] "Every inbound request" includes a request whose method is not `POST`: WordPress matches a
 * route's declared methods *before* it reaches a callback, so the route is registered for every HTTP
 * method and the controlled handler decides `method_not_allowed` itself (§9.2). The handler also always
 * hands the *exact raw bytes* that arrived to the receipt, even when a precheck refuses the request
 * before it is parsed, so the durable audit digest can never describe a body the provider did not send
 * (§9.3).
 *
 * [C9-4] `OPTIONS` is the one method registration alone cannot deliver: WordPress answers it in
 * `rest_handle_options_request()`, itself a `rest_pre_dispatch` filter, so an `OPTIONS` delivery is
 * answered *before* `dispatch()` would ever resolve a route and call a callback. The endpoint therefore
 * intercepts its own two route shapes on the same hook at a priority below the core handler's, and
 * refuses the request through the very same controlled path — same precheck, same receipt, same
 * `method_not_allowed` verdict with the exact raw body — so a registered method can never silently
 * bypass the durable receipt of §9.1/§9.3.
 */
final class StripeWebhookController {
    /** The namespace and the two route shapes, declared once so registration and interception agree. */
    private const ROUTE_NAMESPACE='delnavazan-platform/v1';
    private const ROUTE_PROVIDER='/payment-provider-events/(?P<provider>[a-z0-9_]{1,32})';
    private const ROUTE_ACCOUNT='/(?P<account>[A-Za-z0-9_-]{1,32})';
    /**
     * [C8-1] The complete HTTP-method set of both webhook routes.
     *
     * A route registered for `POST` alone would answer a `GET`/`PUT`/`PATCH`/`DELETE` delivery with a
     * routing-level error and record nothing. Registering every method keeps the method check inside the
     * controlled handler, where it is receipted with `method_not_allowed` and the exact raw body.
     */
    private const ROUTE_METHODS='GET,POST,PUT,PATCH,DELETE,HEAD,OPTIONS';
    /**
     * [C9-4] The precedence of the endpoint's own `rest_pre_dispatch` interception.
     *
     * WordPress registers `rest_handle_options_request()` on `rest_pre_dispatch` at priority 10, and that
     * handler answers any `OPTIONS` request before normal route dispatch. Running first is therefore the
     * only way this endpoint's `OPTIONS` can be receipted at all; a core filter that replaced the default
     * handling would still leave this endpoint's own receipt in place, because the interception never
     * depends on which handler would otherwise answer.
     */
    private const OPTIONS_INTERCEPT_PRIORITY=1;

    public static function register():void{
        // [C9-4] The one method the route's method set cannot deliver on its own.
        add_filter('rest_pre_dispatch',array(__CLASS__,'interceptOptions'),self::OPTIONS_INTERCEPT_PRIORITY,3);
        register_rest_route(self::ROUTE_NAMESPACE,self::ROUTE_PROVIDER.self::ROUTE_ACCOUNT,array(
            'methods'=>self::ROUTE_METHODS,'permission_callback'=>'__return_true','callback'=>array(__CLASS__,'handle'),
        ));
        // The bare provider route exists so every inbound request is receipted and never falls back to
        // a default account.
        register_rest_route(self::ROUTE_NAMESPACE,self::ROUTE_PROVIDER,array(
            'methods'=>self::ROUTE_METHODS,'permission_callback'=>'__return_true','callback'=>array(__CLASS__,'handleProviderOnly'),
        ));
    }

    /**
     * [C9-4] Receipt and refuse this endpoint's `OPTIONS` delivery before WordPress's own `OPTIONS` handler.
     *
     * The interception is scoped in both directions: it ignores every request that is not an `OPTIONS`
     * delivery, and every path that is not one of this endpoint's two registered route shapes is returned
     * to the filter chain untouched, so no other route's `OPTIONS` handling is changed. A matched delivery
     * is handed to `process()` — the same controlled path a routed `GET`/`PUT`/`PATCH`/`DELETE` takes — so
     * the §9.2 method requirement decides it and the receipt carries the exact bytes that arrived (§9.3).
     *
     * @param mixed $result The pre-dispatch result another filter may already have produced.
     * @return mixed The untouched result, or this endpoint's controlled refusal.
     */
    public static function interceptOptions($result,$server,\WP_REST_Request $request){
        if($result!==null)return $result;
        if(strtoupper((string)$request->get_method())!=='OPTIONS')return $result;
        if(!preg_match(self::optionsRoutePattern(),self::requestRoute($request),$matches))return $result;
        return self::process((string)$matches['provider'],(string)($matches['account']??''),$request);
    }

    /**
     * [C9-4] The two registered route shapes as one anchored, case-insensitive pattern.
     *
     * The trailing separator is optional because WordPress may serve the request path untrailingslashed:
     * a delivery that reaches this endpoint under a registered method is receipted under every method,
     * `OPTIONS` included, rather than slipping out through a normalisation difference.
     */
    private static function optionsRoutePattern():string{
        return '#^/'.self::ROUTE_NAMESPACE.self::ROUTE_PROVIDER.'(?:'.self::ROUTE_ACCOUNT.')?/?$#i';
    }

    /**
     * [C9-4] The route this request targets, resolved before `dispatch()` would have set it.
     *
     * `serve_request()` builds its request with the REST path, and that is the route the interception
     * matches; the REST route query variable is read only when a request was built without a path.
     */
    private static function requestRoute(\WP_REST_Request $request):string{
        $route=(string)$request->get_route();
        if($route===''&&isset($GLOBALS['wp'])&&is_object($GLOBALS['wp'])&&isset($GLOBALS['wp']->query_vars['rest_route']))
            $route=(string)$GLOBALS['wp']->query_vars['rest_route'];
        return '/'.ltrim(preg_replace('/[?#].*$/','',ltrim($route,'/'))??'','/');
    }

    public static function handle(\WP_REST_Request $request){
        return self::process((string)$request->get_param('provider'),(string)$request->get_param('account'),$request);
    }
    public static function handleProviderOnly(\WP_REST_Request $request){
        return self::process((string)$request->get_param('provider'),'',$request);
    }

    private static function process(string $providerKey,string $accountSelector,\WP_REST_Request $request){
        $intake=new PaymentEventIntakeService();
        $rawBody=(string)$request->get_body();
        $headers=self::requestHeaders($request);
        $meta=array('received_at'=>PaymentExecutionSupport::now(),'source'=>(string)$request->get_header('x-forwarded-for'));
        $reason=self::precheck($request,$rawBody);
        if($reason!==null)$meta['precheck_refusal']=$reason;
        // [C8-1] The actual raw body is always passed to the receipt: the precheck decides whether the
        // request may be parsed and verified, never which bytes are recorded. Substituting an empty body
        // for an oversized, unsupported-content-type or otherwise refused request would record a
        // zero-byte/different digest and destroy the exact-raw-body audit invariant of §9.3.
        $result=$intake->receive($providerKey,$accountSelector,$rawBody,$headers,$meta);
        $response=new \WP_REST_Response(array('status'=>$result['verification_state']),self::status((int)$result['status']));
        $response->header('Cache-Control','no-store');
        return $response;
    }

    /** §9.2: method, transport, content type and body-size requirements, decided before parsing. */
    private static function precheck(\WP_REST_Request $request,string $rawBody):?string{
        if(strtoupper((string)$request->get_method())!=='POST')return 'method_not_allowed';
        if(!self::transportIsHttps(self::requestHeaders($request)))return 'https_required';
        $contentType=$request->get_content_type();
        if(!is_array($contentType)||!str_contains(strtolower((string)($contentType['value']??'')),'application/json'))return 'unsupported_content_type';
        if(strlen($rawBody)>PaymentExecutionRule::MAX_WEBHOOK_BYTES)return 'payload_too_large';
        if(trim($rawBody)==='')return 'empty_payload';
        if((array)$request->get_query_params()!==array())return 'unexpected_request_shape';
        if(count((array)$request->get_url_params())>2)return 'unexpected_request_shape';
        return null;
    }

    /** The normalised request header map: lower-cased names, one string value per name. */
    private static function requestHeaders(\WP_REST_Request $request):array{
        $headers=array();
        foreach((array)$request->get_headers() as $name=>$value)$headers[strtolower((string)$name)]=is_array($value)?(string)reset($value):(string)$value;
        return $headers;
    }

    /**
     * [C9-3] The §9.2 transport verdict, from inputs only.
     *
     * A delivery is secure when the transport itself is TLS, when the site runs in the local development
     * environment, or when a header that the *operator* has configured this site to trust — always a
     * member of `PaymentExecutionRule::HTTPS_PROXY_HEADERS` — names `https`. A header the site has not
     * been configured to trust is ignored whatever it says, so a client can never satisfy the HTTPS
     * requirement by sending `X-Forwarded-Proto` to a site that is not behind a TLS-terminating proxy.
     */
    public static function transportIsHttps(array $headers,?bool $directTls=null,?string $environmentType=null):bool{
        $directTls??=function_exists('is_ssl')&&is_ssl();
        if($directTls)return true;
        $environmentType??=function_exists('wp_get_environment_type')?(string)wp_get_environment_type():'';
        if($environmentType==='local')return true;
        // Header names are compared without their separators, because WordPress normalises the same
        // inbound proxy header to the dashed spelling on the server path and to the underscored spelling
        // when a request is built in process. Neither spelling is trusted on its own: the locked allowlist
        // and the operator configuration decide, and the value is judged by the rule.
        $trusted=array();
        foreach(PaymentExecutionRule::trustedProxyHeaders() as $header){
            $name=strtolower(str_replace('_','-',$header));
            if(str_starts_with($name,'http-'))$name=substr($name,5);
            $trusted[str_replace(array('-','_'),'',$name)]=$header;
        }
        foreach($headers as $name=>$value){
            $key=str_replace(array('-','_'),'',strtolower((string)$name));
            if(isset($trusted[$key])&&PaymentExecutionRule::proxyHeaderIndicatesHttps($trusted[$key],(string)$value))return true;
        }
        return false;
    }
    private static function status(int $status):int{return in_array($status,array(200,400,401,404,405,413,500,503),true)?$status:200;}
}
