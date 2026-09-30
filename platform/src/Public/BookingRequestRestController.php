<?php
namespace Delnavazan\Platform\Public;

use Delnavazan\Platform\Core\Application\BookingRequestSubmissionService;
use Delnavazan\Platform\Core\Application\IdempotencyConflictException;
use Delnavazan\Platform\Core\Application\BookingRequestRateLimitException;

final class BookingRequestRestController {
    public static function register(): void { register_rest_route( 'delnavazan-platform/v1', '/booking-requests', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( __CLASS__, 'submit' ) ) ); }
    public static function submit( \WP_REST_Request $request ): \WP_REST_Response { try { $key=$request->get_header('idempotency-key'); if(!is_string($key)||$key==='')throw new \InvalidArgumentException('Idempotency key required'); $limiter=new BookingRequestRateLimiter(); $result=(new BookingRequestSubmissionService())->submitPublic((array)$request->get_json_params(),$key,static fn(): bool => $limiter->allow()); return new \WP_REST_Response(array('success'=>true,'request_reference'=>$result['request_reference']),201); } catch(IdempotencyConflictException){return new \WP_REST_Response(array('success'=>false,'code'=>'idempotency_conflict'),409);}catch(BookingRequestRateLimitException){return new \WP_REST_Response(array('success'=>false,'code'=>'rate_limited'),429);}catch(\InvalidArgumentException){return new \WP_REST_Response(array('success'=>false,'code'=>'invalid_request'),400);}catch(\Throwable){return new \WP_REST_Response(array('success'=>false,'code'=>'submission_unavailable'),500);} }
}
