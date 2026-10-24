<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/** Teacher-owned Accept/Decline authority for one delivered introductory booking request. */
final class BookingTeacherResponseService {
    public function __construct(private ?BookingTeacherResponseRepository $repository=null,private ?object $projector=null){$this->repository??=new BookingTeacherResponseRepository();}

    public function respond(string $deliveryUid,string $decision,string $commandKey):array{
        $actor=(int)get_current_user_id();if($actor<1)throw new \RuntimeException('Unauthorized');
        if(!in_array($decision,array('accept','decline'),true))throw new \InvalidArgumentException('Unsupported booking response');
        $deliveryUid=trim($deliveryUid);if(!preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D',$deliveryUid))throw new \InvalidArgumentException('Booking delivery identity required');
        $commandKey=trim($commandKey);if($commandKey===''||strlen($commandKey)>191)throw new \InvalidArgumentException('Booking response command required');
        $keyDigest=hash_hmac('sha256','booking-response-key:'.$commandKey,wp_salt('dzn_booking_response'));
        $payloadDigest=hash_hmac('sha256','booking-response-payload:'.$deliveryUid.'|'.$decision,wp_salt('dzn_booking_response'));
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
            if($decision==='decline'){
                $this->repository->transitionDelivery($deliveryId,'pending_request','declined',$now);
                $this->repository->closeRequest((int)$delivery->booking_request_id,'teacher_declined',$now,$actor);
                $this->queueNotification($deliveryId,null,'declined',$now);
                $this->repository->insertCommand(array('uid'=>Identifier::uid(),'command_key_digest'=>$keyDigest,'command_payload_digest'=>$payloadDigest,'delivery_id'=>$deliveryId,'operation'=>'decline','result_state'=>'declined','result_confirmation_id'=>null,'created_at'=>$now,'created_by'=>$actor));
                $this->repository->commit();return array('delivery_id'=>$deliveryId,'confirmation_id'=>null,'state'=>'declined','idempotent'=>false);
            }
            if((string)$teacher->onboarding_state!=='active'||(string)$teacher->readiness_state!=='ready')throw new \InvalidArgumentException('Teacher is not ready to accept bookings');
            $connection=$this->repository->activeConnection((int)$teacher->id,true);if(!$connection)throw new \InvalidArgumentException('Google Calendar connection required');
            $zone=new \DateTimeZone((string)$teacher->timezone);$start=new \DateTimeImmutable((string)$delivery->starts_at_utc,new \DateTimeZone('UTC'));
            $prior=$this->repository->confirmationForDelivery($deliveryId,true);
            if($prior){
                if((string)$prior->state!=='failed')throw new \InvalidArgumentException('Booking confirmation is not restartable');
                $confirmationId=(int)$prior->id;
                $resume=$prior->provider_event_digest===null?'pending_projection':'awaiting_conference';
                $this->repository->updateConfirmation($confirmationId,(int)$prior->version,array(
                    'connection_id'=>(int)$connection->id,'state'=>$resume,'failure_reason_code'=>null,'attempt_count'=>0,'next_attempt_at'=>$now,
                    'starts_at_utc'=>(string)$delivery->starts_at_utc,'ends_at_utc'=>(string)$delivery->instructional_ends_at_utc,
                    'schedule_timezone'=>(string)$teacher->timezone,'local_wall_date'=>$start->setTimezone($zone)->format('Y-m-d'),'local_wall_time'=>$start->setTimezone($zone)->format('H:i:s'),
                    'updated_at'=>$now,'updated_by'=>$actor,
                ));
            }else{
                $confirmationId=$this->repository->insertConfirmation(array(
                    'uid'=>Identifier::uid(),'delivery_id'=>$deliveryId,'booking_request_id'=>(int)$delivery->booking_request_id,'requested_time_id'=>(int)$delivery->requested_time_id,
                    'teacher_id'=>(int)$teacher->id,'connection_id'=>(int)$connection->id,'state'=>'pending_projection','version'=>1,
                    'starts_at_utc'=>(string)$delivery->starts_at_utc,'ends_at_utc'=>(string)$delivery->instructional_ends_at_utc,
                    'schedule_timezone'=>(string)$teacher->timezone,'local_wall_date'=>$start->setTimezone($zone)->format('Y-m-d'),'local_wall_time'=>$start->setTimezone($zone)->format('H:i:s'),
                    'provider_code'=>'google_calendar','provider_event_digest'=>null,'failure_reason_code'=>null,'attempt_count'=>0,'next_attempt_at'=>$now,'confirmed_at'=>null,
                    'created_at'=>$now,'created_by'=>$actor,'updated_at'=>$now,'updated_by'=>$actor,
                ));
            }
            $this->repository->transitionDelivery($deliveryId,'pending_request','accepting',$now);
            $this->repository->insertCommand(array('uid'=>Identifier::uid(),'command_key_digest'=>$keyDigest,'command_payload_digest'=>$payloadDigest,'delivery_id'=>$deliveryId,'operation'=>'accept','result_state'=>'accepting','result_confirmation_id'=>$confirmationId,'created_at'=>$now,'created_by'=>$actor));
            $this->repository->commit();
        }catch(\Throwable $error){$this->repository->rollback();throw $error;}
        if($this->projector!==null)return $this->projector->dispatch((int)$confirmationId);
        return array('delivery_id'=>(int)$deliveryId,'confirmation_id'=>(int)$confirmationId,'state'=>'accepting','idempotent'=>false);
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
