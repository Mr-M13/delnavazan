<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Application\Finance\Intro\IntroPaymentService;
use Delnavazan\Platform\Core\Application\Finance\Pricing\IntroPricingService;
use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/** Teacher-owned Accept/Decline authority for one delivered introductory booking request. */
final class BookingTeacherResponseService {
    /** Stable Teacher-facing decline reasons; `other` requires free text. */
    public const DECLINE_REASONS=array('times_unsuitable','no_capacity','cannot_teach_student','other');
    public function __construct(private ?BookingTeacherResponseRepository $repository=null,private ?object $projector=null,private ?\Closure $introPricing=null,private ?\Closure $introPayments=null,private ?BookingAllocationService $allocation=null){$this->repository??=new BookingTeacherResponseRepository();$this->allocation??=new BookingAllocationService();}

    public function respond(string $deliveryUid,string $decision,string $commandKey,?int $requestedTimeId=null,?string $reasonCode=null,?string $reasonText=null):array{
        $actor=(int)get_current_user_id();if($actor<1)throw new \RuntimeException('Unauthorized');
        if(!in_array($decision,array('accept','decline'),true))throw new \InvalidArgumentException('Unsupported booking response');
        $deliveryUid=trim($deliveryUid);if(!preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D',$deliveryUid))throw new \InvalidArgumentException('Booking delivery identity required');
        $commandKey=trim($commandKey);if($commandKey===''||strlen($commandKey)>191)throw new \InvalidArgumentException('Booking response command required');
        if($requestedTimeId!==null&&$requestedTimeId<1)throw new \InvalidArgumentException('Choose one of the requested times');
        $keyDigest=hash_hmac('sha256','booking-response-key:'.$commandKey,wp_salt('dzn_booking_response'));
        // The explicitly selected Student preference is part of the idempotency payload. Calls that
        // do not choose a preference keep the original digest so a retried pre-existing command
        // continues to replay rather than conflict.
        $payloadDigest=hash_hmac('sha256','booking-response-payload:'.$deliveryUid.'|'.$decision.($requestedTimeId!==null?'|'.$requestedTimeId:''),wp_salt('dzn_booking_response'));
        $now=gmdate('Y-m-d H:i:s');$confirmationId=null;$deliveryId=null;
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($keyDigest)){$result=$this->replay($winner,$payloadDigest);$this->repository->commit();return $result;}
            $teacher=$this->repository->teacherForUser($actor,true);
            if(!$teacher||(string)$teacher->status!=='active'||$teacher->archived_at!==null)throw new \RuntimeException('Active Teacher required');
            $delivery=$this->repository->deliveryByUid($deliveryUid,true);$deliveryId=(int)($delivery->id??0);
            if($winner=$this->repository->command($keyDigest)){$result=$this->replay($winner,$payloadDigest);$this->repository->commit();return $result;}
            if(!$delivery||(int)$delivery->teacher_id!==(int)$teacher->id)throw new \RuntimeException('Booking request not found');
            if((string)$delivery->state!=='pending_request'||(string)$delivery->lifecycle_status!=='submitted'||(string)$delivery->resolution_state!=='unresolved'||$delivery->privacy_erased_at!==null)throw new \InvalidArgumentException('Booking request is no longer pending');
            $this->allocation->assertPending($delivery,$now);
            if($decision==='decline'){
                [$declineReason,$declineText]=$this->declineReason($reasonCode,$reasonText);
                $this->recordDecline($delivery,'assigned',$declineReason,$declineText,$actor,$keyDigest,$now);
                $next=$this->allocation->advanceLocked($delivery,'declined',$now);
                $this->repository->insertCommand(array('uid'=>Identifier::uid(),'command_key_digest'=>$keyDigest,'command_payload_digest'=>$payloadDigest,'delivery_id'=>$deliveryId,'operation'=>'decline','result_state'=>'declined','result_confirmation_id'=>null,'created_at'=>$now,'created_by'=>$actor));
                $this->repository->commit();return array('delivery_id'=>$deliveryId,'confirmation_id'=>null,'state'=>'declined','next_offer'=>$next,'reason_code'=>$declineReason,'idempotent'=>false);
            }
            // The Teacher may choose any Student preference. The originally offered time keeps the
            // strict availability rules; a different, explicitly selected preference may proceed
            // outside declared availability and is audited.
            $offeredTimeId=(int)$delivery->requested_time_id;
            $selectedTimeId=$requestedTimeId??$offeredTimeId;
            $allowOverride=$selectedTimeId!==$offeredTimeId;
            if($allowOverride){
                $offer=$this->repository->activeAllocationOffer((int)$delivery->booking_request_id,true);
                if(!$offer)throw new \InvalidArgumentException('Booking offer expired or reassigned');
                $this->repository->switchOfferedTime((int)$delivery->id,(int)$offer->id,$selectedTimeId,$now);
                $delivery=$this->repository->deliveryByUid($deliveryUid,true);
                if(!$delivery)throw new \RuntimeException('Booking request not found');
            }
            $overridden=(bool)$this->allocation->assertAcceptable($delivery,$now,$selectedTimeId,$allowOverride);
            if($overridden)$this->auditAvailabilityOverride($delivery,$selectedTimeId,$actor,$keyDigest,$now);
            [$confirmationId,$paid,$introUid]=$this->confirmDelivery($delivery,$teacher,$actor,$keyDigest,$payloadDigest,$now);
            $this->allocation->acceptedLocked($delivery,$now);
            $this->repository->commit();
        }catch(\Throwable $error){$this->repository->rollback();throw $error;}
        if($paid)return array('delivery_id'=>(int)$deliveryId,'confirmation_id'=>(int)$confirmationId,'state'=>'awaiting_payment','payment_required'=>true,'intro_obligation_uid'=>$introUid,'idempotent'=>false);
        return $this->dispatchProjection((int)$deliveryId,(int)$confirmationId);
    }

    /**
     * Claim one currently unmatched request for an instrument this Teacher teaches.
     *
     * An unmatched request has no active offer, no confirmation and no previous offer to this
     * Teacher. The Teacher sees it precisely because no available Teacher was matched, so the
     * chosen Student preference is allowed outside declared availability. Occupancy and
     * continuation holds remain hard constraints through the shared allocation authority, and the
     * override is audited before the ordinary confirmation path runs.
     */
    public function claim(string $requestUid,int $requestedTimeId,string $commandKey):array{
        $actor=(int)get_current_user_id();if($actor<1)throw new \RuntimeException('Unauthorized');
        $requestUid=trim($requestUid);if(!preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D',$requestUid))throw new \InvalidArgumentException('Booking request identity required');
        if($requestedTimeId<1)throw new \InvalidArgumentException('Choose one of the requested times');
        $commandKey=trim($commandKey);if($commandKey===''||strlen($commandKey)>191)throw new \InvalidArgumentException('Booking response command required');
        $keyDigest=hash_hmac('sha256','booking-claim-key:'.$commandKey,wp_salt('dzn_booking_response'));
        $payloadDigest=hash_hmac('sha256','booking-claim-payload:'.$requestUid.'|'.$requestedTimeId,wp_salt('dzn_booking_response'));
        $now=gmdate('Y-m-d H:i:s');$confirmationId=null;$paid=false;$introUid=null;$deliveryId=0;
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($keyDigest)){$result=$this->replay($winner,$payloadDigest);$this->repository->commit();return $result;}
            $teacher=$this->repository->teacherForUser($actor,true);
            if(!$teacher||(string)$teacher->status!=='active'||$teacher->archived_at!==null)throw new \RuntimeException('Active Teacher required');
            // Lock order follows the response path: request, then delivery/offer. Privacy erasure
            // also serialises on the request row, so a claim cannot revive an erased request.
            $request=$this->repository->requestByUid($requestUid,true);
            if(!$request)throw new \RuntimeException('Booking request not found');
            if($winner=$this->repository->command($keyDigest)){$result=$this->replay($winner,$payloadDigest);$this->repository->commit();return $result;}
            if((string)$request->lifecycle_status!=='submitted'||(string)$request->resolution_state!=='unresolved'||$request->privacy_erased_at!==null)throw new \InvalidArgumentException('Booking request is no longer pending');
            if((int)($request->student_id??0)<1||(int)($request->selected_intro_course_id??0)<1)throw new \InvalidArgumentException('Resolve the student identity before confirming a match');
            if(!$this->repository->teacherEligibleForCourse((int)$teacher->id,(int)$request->selected_intro_course_id))throw new \InvalidArgumentException('Teacher is not eligible for this instrument');
            $requestId=(int)$request->id;
            if($this->repository->declineForTeacher($requestId,(int)$teacher->id,true))throw new \InvalidArgumentException('Booking request is no longer available');
            $time=$this->repository->requestedTime($requestId,$requestedTimeId,true);
            if(!$time)throw new \InvalidArgumentException('Choose one of the requested times');
            if((string)$time->starts_at_utc<=$now)throw new \InvalidArgumentException('Booking time is no longer available');
            if($this->repository->confirmationForRequest($requestId,true))throw new \InvalidArgumentException('Booking request is no longer pending');
            if($this->repository->activeAllocationOffer($requestId,true))throw new \InvalidArgumentException('Booking request is no longer available');
            if($this->repository->offerForTeacher($requestId,(int)$teacher->id,true))throw new \InvalidArgumentException('Booking request is no longer available');
            // The request has no current match. Re-point the one delivery anchor if a prior cycle
            // left it behind, otherwise create it; the unique booking request index still permits
            // exactly one delivery.
            $delivery=$this->repository->deliveryForRequest($requestId,true);
            $uid=Identifier::uid();
            if($delivery){
                $this->repository->repointDelivery((int)$delivery->id,(int)$teacher->id,$requestedTimeId,$uid,'claim',$now);
                $deliveryId=(int)$delivery->id;
            }else{
                $deliveryId=$this->repository->createDelivery(array('uid'=>$uid,'booking_request_id'=>$requestId,'requested_time_id'=>$requestedTimeId,'teacher_id'=>(int)$teacher->id,'state'=>'pending_request','match_strength'=>'claim','created_at'=>$now,'updated_at'=>$now));
            }
            $deadline=BookingAllocationService::addSeconds($now,BookingAllocationService::RESPONSE_SECONDS);
            $this->repository->insertClaimOffer($requestId,$deliveryId,(int)$teacher->id,$requestedTimeId,$now,$deadline);
            $locked=$this->repository->deliveryByUid($uid,true);
            if(!$locked||(int)$locked->teacher_id!==(int)$teacher->id)throw new \RuntimeException('Booking request not found');
            $overridden=(bool)$this->allocation->assertAcceptable($locked,$now,$requestedTimeId,true);
            $this->auditClaim($locked,$requestedTimeId,$overridden,$actor,$keyDigest,$now);
            [$confirmationId,$paid,$introUid]=$this->confirmDelivery($locked,$teacher,$actor,$keyDigest,$payloadDigest,$now);
            $this->allocation->acceptedLocked($locked,$now);
            $this->repository->commit();
        }catch(\Throwable $error){$this->repository->rollback();throw $error;}
        if($paid)return array('delivery_id'=>$deliveryId,'confirmation_id'=>(int)$confirmationId,'state'=>'awaiting_payment','payment_required'=>true,'intro_obligation_uid'=>$introUid,'idempotent'=>false);
        return $this->dispatchProjection($deliveryId,(int)$confirmationId);
    }

    /**
     * Record one Teacher's disinterest in an unassigned opportunity.
     *
     * This is deliberately not a request cancellation: the opportunity remains visible to every
     * other eligible Teacher, no allocation offer is consumed and no confirmation is created. The
     * active decline row is Teacher-scoped and prevents the same opportunity being shown to the
     * same Teacher again unless an authorised workflow explicitly clears that row.
     */
    public function declineOpportunity(string $requestUid,string $reasonCode,string $reasonText,string $commandKey):array{
        $actor=(int)get_current_user_id();if($actor<1)throw new \RuntimeException('Unauthorized');
        $requestUid=trim($requestUid);if(!preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D',$requestUid))throw new \InvalidArgumentException('Booking request identity required');
        [$declineReason,$declineText]=$this->declineReason($reasonCode,$reasonText);
        $commandKey=trim($commandKey);if($commandKey===''||strlen($commandKey)>191)throw new \InvalidArgumentException('Booking response command required');
        $keyDigest=hash_hmac('sha256','booking-opportunity-decline-key:'.$commandKey,wp_salt('dzn_booking_response'));
        $now=gmdate('Y-m-d H:i:s');$requestId=0;
        $this->repository->begin();
        try{
            $teacher=$this->repository->teacherForUser($actor,true);
            if(!$teacher||(string)$teacher->status!=='active'||$teacher->archived_at!==null)throw new \RuntimeException('Active Teacher required');
            $request=$this->repository->requestByUid($requestUid,true);
            if(!$request)throw new \RuntimeException('Booking request not found');
            $requestId=(int)$request->id;
            if((string)$request->lifecycle_status!=='submitted'||(string)$request->resolution_state!=='unresolved'||$request->privacy_erased_at!==null)throw new \InvalidArgumentException('Booking request is no longer pending');
            if((int)($request->selected_intro_course_id??0)<1||!$this->repository->teacherEligibleForCourse((int)$teacher->id,(int)$request->selected_intro_course_id))throw new \InvalidArgumentException('Teacher is not eligible for this instrument');
            if($this->repository->confirmationForRequest($requestId,true))throw new \InvalidArgumentException('Booking request is no longer available');
            if($this->repository->activeAllocationOffer($requestId,true))throw new \InvalidArgumentException('Booking request is no longer available');
            $delivery=$this->repository->deliveryForRequest($requestId,true);
            if($delivery&&in_array((string)($delivery->state??''),array('accepting','confirmed'),true))throw new \InvalidArgumentException('Booking request is no longer available');
            if($this->repository->declineForTeacher($requestId,(int)$teacher->id,true)){
                $this->repository->commit();
                return array('request_id'=>$requestId,'delivery_id'=>0,'state'=>'declined','reason_code'=>$declineReason,'idempotent'=>true);
            }
            $this->recordDecline((object)array('id'=>0,'booking_request_id'=>$requestId,'teacher_id'=>(int)$teacher->id),'opportunity',$declineReason,$declineText,$actor,$keyDigest,$now,$requestId);
            $this->repository->commit();
        }catch(\Throwable $error){$this->repository->rollback();throw $error;}
        return array('request_id'=>$requestId,'delivery_id'=>0,'state'=>'declined','reason_code'=>$declineReason,'idempotent'=>false);
    }

    /**
     * Administrator-confirmed match after a directly evidenced Teacher conversation.
     *
     * The delivery is re-read and locked. The confirmation, projection state and notification
     * obligation are the same rows used by the Teacher-owned response path; only the asserting
     * principal differs. Availability preference is not inferred here: the caller must supply
     * explicit agreement and audit the administrative override before invoking this method.
     */
    public function administratorConfirmDelivery(int $deliveryId,string $commandKey):array{
        if(!current_user_can('dzn_manage_booking_request_coordination'))throw new \RuntimeException('Unauthorized');
        $actor=(int)get_current_user_id();if($actor<1)throw new \RuntimeException('Unauthorized');
        if($deliveryId<1)throw new \InvalidArgumentException('Booking delivery identity required');
        $commandKey=trim($commandKey);if($commandKey===''||strlen($commandKey)>191)throw new \InvalidArgumentException('Booking response command required');
        $keyDigest=hash_hmac('sha256','booking-admin-response-key:'.$commandKey,wp_salt('dzn_booking_response'));
        $payloadDigest=hash_hmac('sha256','booking-admin-response-payload:'.$deliveryId.'|admin_accept',wp_salt('dzn_booking_response'));
        $now=gmdate('Y-m-d H:i:s');$confirmationId=null;$paid=false;$introUid=null;
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($keyDigest)){$result=$this->replay($winner,$payloadDigest);$this->repository->commit();return$result;}
            $delivery=$this->repository->delivery($deliveryId,true);
            if(!$delivery||(string)$delivery->state!=='pending_request'||(string)$delivery->lifecycle_status!=='submitted'||(string)$delivery->resolution_state!=='unresolved'||$delivery->privacy_erased_at!==null)throw new \InvalidArgumentException('Booking request is no longer pending');
            if((int)($delivery->student_id??0)<1)throw new \InvalidArgumentException('Resolve the student identity before confirming a match');
            $teacher=$this->repository->teacherForAdministrator((int)$delivery->teacher_id,true);
            if(!$teacher||(string)$teacher->status!=='active'||$teacher->archived_at!==null)throw new \RuntimeException('Active Teacher required');
            [$confirmationId,$paid,$introUid]=$this->confirmDelivery($delivery,$teacher,$actor,$keyDigest,$payloadDigest,$now);
            $this->allocation->administratorAcceptedLocked($delivery,$now);
            $this->repository->commit();
        }catch(\Throwable $error){$this->repository->rollback();throw $error;}
        if($paid)return array('delivery_id'=>$deliveryId,'confirmation_id'=>(int)$confirmationId,'state'=>'awaiting_payment','payment_required'=>true,'intro_obligation_uid'=>$introUid,'idempotent'=>false);
        return $this->dispatchProjection($deliveryId,(int)$confirmationId);
    }

    /**
     * Acceptance is already committed before the provider call. A dispatch exception must therefore
     * never be reported as a lost acceptance; leave the durable confirmation retryable for the worker.
     */
    private function dispatchProjection(int $deliveryId,int $confirmationId):array{
        if($this->projector===null)return array('delivery_id'=>$deliveryId,'confirmation_id'=>$confirmationId,'state'=>'accepting','idempotent'=>false);
        try{
            $result=$this->projector->dispatch($confirmationId);
            if(is_array($result))$result['delivery_id']=$deliveryId;
            return is_array($result)?$result:array('delivery_id'=>$deliveryId,'confirmation_id'=>$confirmationId,'state'=>'retryable','failure_reason_code'=>'booking_projection_pending','retryable'=>true,'idempotent'=>false);
        }catch(\Throwable $error){
            do_action('dzn_booking_calendar_projection_failed',$confirmationId,get_class($error));
            return array('delivery_id'=>$deliveryId,'confirmation_id'=>$confirmationId,'state'=>'retryable','failure_reason_code'=>'booking_projection_pending','retryable'=>true,'idempotent'=>false);
        }
    }

    /** @return array{0:int,1:bool,2:?string} */
    private function confirmDelivery(object $delivery,object $teacher,int $actor,string $keyDigest,string $payloadDigest,string $now):array{
        $deliveryId=(int)$delivery->id;
        $studentId=(int)($delivery->student_id??0);
        if($studentId<1)throw new \InvalidArgumentException('Resolve the student identity before confirming a booking');
        // Acceptance serializes against every other Student scheduling change. The
        // lock is held until this method's caller commits the confirmation.
        $this->repository->ensureAndLockStudentRoot($studentId,$now,$actor);
        do_action('dzn_booking_student_root_held','acceptance',$studentId);
        $occupiedEnd=(string)($delivery->occupied_ends_at_utc??'');
        if($occupiedEnd==='')throw new \RuntimeException('Booking requested time is unavailable');
        if($this->repository->studentOccupancyConflicts($studentId,array(array('starts_at_utc'=>(string)$delivery->starts_at_utc,'occupied_ends_at_utc'=>$occupiedEnd)),array('exclude_booking_request_id'=>(int)$delivery->booking_request_id)))throw new \InvalidArgumentException('student_slot_conflict');
        $this->repository->ensureAndLockTeacherRoot((int)$teacher->id,$now,$actor);
        if($this->repository->teacherOccupancyConflicts((int)$teacher->id,(string)$delivery->starts_at_utc,$occupiedEnd,(int)$delivery->booking_request_id))throw new \InvalidArgumentException('teacher_slot_conflict');
        if((string)$teacher->onboarding_state!=='active'||(string)$teacher->readiness_state!=='ready')throw new \InvalidArgumentException('Teacher is not ready to accept bookings');
        // The central Delnavazan Workspace organizer creates the classroom. A Teacher personal
        // Google grant is required only while the caller has explicitly rolled back to legacy.
        $centralInstalled=class_exists('Delnavazan\\Platform\\Integrations\\GoogleWorkspace\\GoogleWorkspaceClassroomService');
        // The central code path owns the booking even while the organizer is temporarily paused or
        // re-authorising. Persist acceptance as retryable instead of rejecting it or falling back to
        // a Teacher-owned Calendar event.
        $connection=$centralInstalled?null:$this->repository->activeConnection((int)$teacher->id,true);
        if(!$centralInstalled&&!$connection)throw new \InvalidArgumentException('Google Calendar connection required');
        $zone=new \DateTimeZone((string)$teacher->timezone);$start=new \DateTimeImmutable((string)$delivery->starts_at_utc,new \DateTimeZone('UTC'));
        $pricing=$this->introPricing!==null?($this->introPricing)((int)$delivery->booking_request_id):(new IntroPricingService())->forBooking((int)$delivery->booking_request_id);
        $paid=$pricing['mode']==='paid';
        $prior=$this->repository->confirmationForDelivery($deliveryId,true);
        if($prior){
            if((string)$prior->state!=='failed')throw new \InvalidArgumentException('Booking confirmation is not restartable');
            $confirmationId=(int)$prior->id;
            $resume=$paid?'awaiting_payment':($prior->provider_event_digest===null?'pending_projection':'awaiting_conference');
            $this->repository->updateConfirmation($confirmationId,(int)$prior->version,array(
                'connection_id'=>$connection?(int)$connection->id:0,'state'=>$resume,'failure_reason_code'=>null,'attempt_count'=>0,'next_attempt_at'=>$now,
                'starts_at_utc'=>(string)$delivery->starts_at_utc,'ends_at_utc'=>(string)$delivery->instructional_ends_at_utc,
                'schedule_timezone'=>(string)$teacher->timezone,'local_wall_date'=>$start->setTimezone($zone)->format('Y-m-d'),'local_wall_time'=>$start->setTimezone($zone)->format('H:i:s'),
                'updated_at'=>$now,'updated_by'=>$actor,
            ));
        }else{
            $confirmationId=$this->repository->insertConfirmation(array(
                'uid'=>Identifier::uid(),'delivery_id'=>$deliveryId,'booking_request_id'=>(int)$delivery->booking_request_id,'requested_time_id'=>(int)$delivery->requested_time_id,
                'teacher_id'=>(int)$teacher->id,'connection_id'=>$connection?(int)$connection->id:0,'state'=>$paid?'awaiting_payment':'pending_projection','version'=>1,
                'starts_at_utc'=>(string)$delivery->starts_at_utc,'ends_at_utc'=>(string)$delivery->instructional_ends_at_utc,
                'schedule_timezone'=>(string)$teacher->timezone,'local_wall_date'=>$start->setTimezone($zone)->format('Y-m-d'),'local_wall_time'=>$start->setTimezone($zone)->format('H:i:s'),
                'provider_code'=>'google_calendar','provider_event_digest'=>null,'failure_reason_code'=>null,'attempt_count'=>0,'next_attempt_at'=>$now,'confirmed_at'=>null,
                'created_at'=>$now,'created_by'=>$actor,'updated_at'=>$now,'updated_by'=>$actor,
            ));
        }
        $introUid=null;
        if($paid){
            if((int)$delivery->student_id<1)throw new \InvalidArgumentException('intro_payment_student_required');
            $obligation=($this->introPayments!==null?$this->introPayments:static fn(array $ctx,string $key,bool $within):array=>(new IntroPaymentService())->ensureObligation($ctx,$key,$within))(array(
                'booking_request_id'=>(int)$delivery->booking_request_id,'confirmation_id'=>$confirmationId,
                'student_id'=>(int)$delivery->student_id,'teacher_id'=>(int)$teacher->id,
                'course_id'=>(int)$delivery->selected_intro_course_id,'region_code'=>(string)$pricing['region_code'],
                'currency'=>(string)$pricing['currency'],'amount_minor'=>(int)$pricing['amount_minor'],
            ),'intro-payment-obligation:'.$confirmationId,true);
            $introUid=(string)$obligation['uid'];
        }
        $this->repository->transitionDelivery($deliveryId,'pending_request','accepting',$now);
        $this->repository->insertCommand(array('uid'=>Identifier::uid(),'command_key_digest'=>$keyDigest,'command_payload_digest'=>$payloadDigest,'delivery_id'=>$deliveryId,'operation'=>'accept','result_state'=>$paid?'awaiting_payment':'accepting','result_confirmation_id'=>$confirmationId,'created_at'=>$now,'created_by'=>$actor));
        return array((int)$confirmationId,(bool)$paid,$introUid);
    }

    /** @return array{0:string,1:string} */
    private function declineReason(?string $reasonCode,?string $reasonText):array{
        $code=strtolower(trim((string)$reasonCode));
        $text=trim((string)$reasonText);
        if(''===$code)$code='unspecified';
        if('unspecified'!==$code&&!in_array($code,self::DECLINE_REASONS,true))throw new \InvalidArgumentException('Choose a valid decline reason');
        if(strlen($text)>1000)throw new \InvalidArgumentException('Decline reason is too long');
        if('other'===$code&&''===$text)throw new \InvalidArgumentException('Decline reason text required');
        return array($code,$text);
    }

    /**
     * Persist Teacher-specific decline evidence plus a privacy-safe audit event.
     *
     * `$delivery` is the assigned delivery anchor when one exists; an unassigned opportunity has
     * no delivery yet, so the request identity alone is the aggregate.
     */
    private function recordDecline(?object $delivery,string $source,string $reasonCode,string $reasonText,int $actor,string $keyDigest,string $now,int $requestId=0):void{
        $requestId=$requestId>0?$requestId:(int)($delivery->booking_request_id??0);
        $teacherId=(int)($delivery->teacher_id??0);
        if($teacherId<1){$teacher=$this->repository->teacherForUser($actor,false);$teacherId=(int)($teacher->id??0);}
        if($requestId<1||$teacherId<1)throw new \RuntimeException('Booking decline target required');
        $deliveryId=(int)($delivery->id??0);
        $this->repository->recordDecline(array(
            'uid'=>Identifier::uid(),'booking_request_id'=>$requestId,'delivery_id'=>$deliveryId>0?$deliveryId:null,
            'teacher_id'=>$teacherId,'source'=>$source,'reason_code'=>$reasonCode,'reason_text'=>$reasonText,
            'created_at'=>$now,'updated_at'=>$now,
        ));
        $digest=hash('sha256','booking-decline-audit:'.$keyDigest);
        if($this->repository->auditByDigest($digest))return;
        $this->repository->insertAudit(array(
            'aggregate_type'=>'booking_request','aggregate_id'=>$requestId,'event_type'=>'teacher_booking_decline',
            'actor_type'=>'user','actor_id'=>$actor,'reason_code'=>$reasonCode,
            'safe_detail'=>(string)json_encode(array('teacher_id'=>$teacherId,'delivery_id'=>$deliveryId,'source'=>$source,'reason_text'=>$reasonText)),
            'idempotency_key'=>$digest,'occurred_at'=>$now,
        ));
    }

    /** Audit an explicit alternative preference accepted outside declared availability. */
    private function auditAvailabilityOverride(object $delivery,int $requestedTimeId,int $actor,string $keyDigest,string $now):void{
        $digest=hash('sha256','booking-response-override:'.$keyDigest);
        if($this->repository->auditByDigest($digest))return;
        $this->repository->insertAudit(array(
            'aggregate_type'=>'booking_request','aggregate_id'=>(int)$delivery->booking_request_id,
            'event_type'=>'teacher_booking_response_availability_override','actor_type'=>'user','actor_id'=>$actor,
            'reason_code'=>'teacher_selected_preference',
            'safe_detail'=>(string)json_encode(array('delivery_id'=>(int)$delivery->id,'teacher_id'=>(int)$delivery->teacher_id,'requested_time_id'=>$requestedTimeId)),
            'idempotency_key'=>$digest,'occurred_at'=>$now,
        ));
    }

    /** Audit an unmatched-request claim, including whether availability was overridden. */
    private function auditClaim(object $delivery,int $requestedTimeId,bool $overridden,int $actor,string $keyDigest,string $now):void{
        $digest=hash('sha256','booking-claim-audit:'.$keyDigest);
        if($this->repository->auditByDigest($digest))return;
        $this->repository->insertAudit(array(
            'aggregate_type'=>'booking_request','aggregate_id'=>(int)$delivery->booking_request_id,
            'event_type'=>'teacher_booking_claim','actor_type'=>'user','actor_id'=>$actor,
            'reason_code'=>$overridden?'teacher_claim_availability_override':'teacher_claim',
            'safe_detail'=>(string)json_encode(array('delivery_id'=>(int)$delivery->id,'teacher_id'=>(int)$delivery->teacher_id,'requested_time_id'=>$requestedTimeId,'availability_overridden'=>$overridden)),
            'idempotency_key'=>$digest,'occurred_at'=>$now,
        ));
    }

    private function queueNotification(int $deliveryId,?int $confirmationId,string $kind,string $now):void{
        if($this->repository->notificationForDeliveryKind($deliveryId,$kind))return;
        $this->repository->insertNotification(array('uid'=>Identifier::uid(),'delivery_id'=>$deliveryId,'confirmation_id'=>$confirmationId,'notification_kind'=>$kind,'state'=>'queued','attempt_count'=>0,'next_attempt_at'=>$now,'lease_token_digest'=>null,'lease_expires_at'=>null,'last_error_code'=>null,'handed_off_at'=>null,'created_at'=>$now,'updated_at'=>$now));
    }

    private function replay(object $command,string $payloadDigest):array{
        if(!hash_equals((string)$command->command_payload_digest,$payloadDigest))throw new IdempotencyConflictException('Idempotency conflict');
        $confirmationId=$command->result_confirmation_id===null?null:(int)$command->result_confirmation_id;$state=(string)$command->result_state;
        if($confirmationId!==null&&($confirmation=$this->repository->confirmation($confirmationId)))$state=(string)$confirmation->state;
        return array('delivery_id'=>(int)$command->delivery_id,'confirmation_id'=>$confirmationId,'state'=>$state,'idempotent'=>true);
    }
}
