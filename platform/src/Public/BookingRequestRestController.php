<?php
namespace Delnavazan\Platform\Public;

use Delnavazan\Platform\Core\Application\BookingRequestSubmissionService;
use Delnavazan\Platform\Core\Application\BookingRequestValidationService;
use Delnavazan\Platform\Core\Application\IdempotencyConflictException;
use Delnavazan\Platform\Core\Application\BookingRequestRateLimitException;

final class BookingRequestRestController {
    public static function register(): void { register_rest_route( 'delnavazan-platform/v1', '/booking-requests', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( __CLASS__, 'submit' ) ) ); }
    public static function submit( \WP_REST_Request $request ): \WP_REST_Response { try { $key=$request->get_header('idempotency-key'); if(!is_string($key)||$key==='')throw new \InvalidArgumentException('Idempotency key required'); $limiter=new BookingRequestRateLimiter(); $accountId=get_current_user_id(); if($request->get_header('x-dzn-account-required')==='1'&&$accountId<1)return new \WP_REST_Response(array('success'=>false,'code'=>'account_session_required'),401); $result=(new BookingRequestSubmissionService())->submitPublic((array)$request->get_json_params(),$key,static fn(): bool => $limiter->allow(),$accountId>0?$accountId:null); return new \WP_REST_Response(array('success'=>true,'request_reference'=>$result['request_reference']),201); } catch(IdempotencyConflictException){return new \WP_REST_Response(array('success'=>false,'code'=>'idempotency_conflict'),409);}catch(BookingRequestRateLimitException){return new \WP_REST_Response(array('success'=>false,'code'=>'rate_limited'),429);}catch(\InvalidArgumentException $e){$code=$e->getMessage()===BookingRequestValidationService::BLOCKED_TIME_MESSAGE?'blocked_time':($e->getMessage()==='Booking email must match the signed-in account'?'account_email_mismatch':'invalid_request');return new \WP_REST_Response(array('success'=>false,'code'=>$code),400);}catch(\Throwable){return new \WP_REST_Response(array('success'=>false,'code'=>'submission_unavailable'),500);} }
}
