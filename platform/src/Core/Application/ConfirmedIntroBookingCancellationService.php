<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalLessonScheduleRepository,ConfirmedIntroBookingCancellationRepository};
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Authorised cancellation of one confirmed introductory booking.
 *
 * The cancellation row is immutable evidence. The confirmation/delivery/request state moves in
 * one READ COMMITTED transaction, a durable provider-retraction job is queued, and one student
 * cancellation notice is queued. No Calendar/Meet secret is edited or deleted here.
 */
final class ConfirmedIntroBookingCancellationService {
    public const CAPABILITY='dzn_cancel_confirmed_intro_booking';
    private const REASONS=array('duplicate_request','student_request','teacher_unavailable','academy_cancelled','operator_correction');

    public function __construct(private ?ConfirmedIntroBookingCancellationRepository $repository=null){$this->repository??=new ConfirmedIntroBookingCancellationRepository();}

    /** @return array{cancellation_id:int,retraction_job_id:int,state:string,idempotent:bool} */
    public function cancel(string $confirmationUid,string $reasonCode,string $evidenceReference,string $commandKey):array{
        if(!current_user_can(self::CAPABILITY))throw new \RuntimeException('Unauthorized');
        $actor=(int)get_current_user_id();if($actor<1)throw new \RuntimeException('Unauthorized');
        $confirmationUid=trim($confirmationUid);if(!preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D',$confirmationUid))throw new \InvalidArgumentException('Confirmed booking identity required');
        $reasonCode=trim($reasonCode);if(!in_array($reasonCode,self::REASONS,true))throw new \InvalidArgumentException('Controlled cancellation reason required');
        $evidenceReference=trim($evidenceReference);if($evidenceReference===''||strlen($evidenceReference)>191)throw new \InvalidArgumentException('Cancellation evidence reference required');
        $commandKey=trim($commandKey);if($commandKey===''||strlen($commandKey)>191)throw new \InvalidArgumentException('Cancellation command required');
        $keyDigest=hash_hmac('sha256','confirmed-intro-cancellation-key:'.$commandKey,wp_salt('dzn_booking_cancellation'));
        $evidenceDigest=hash_hmac('sha256','confirmed-intro-cancellation-evidence:'.$evidenceReference,wp_salt('dzn_booking_cancellation'));
        $payloadDigest=hash_hmac('sha256','confirmed-intro-cancellation-payload:'.$confirmationUid.'|'.$reasonCode.'|'.$evidenceDigest,wp_salt('dzn_booking_cancellation'));
        $now=gmdate('Y-m-d H:i:s');$cancellationId=0;$jobId=0;
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($keyDigest)){$result=$this->replay($winner,$payloadDigest);$this->repository->commit();return $result;}
            $confirmation=$this->repository->confirmationByUid($confirmationUid,true);
            if(!$confirmation)throw new \InvalidArgumentException('Confirmed booking not found');
            $existing=$this->repository->cancellationForConfirmation((int)$confirmation->id,true);
            if($existing&&(string)$confirmation->state==='cancelled'){
                $this->repository->commit();
                return array('cancellation_id'=>(int)$existing->id,'retraction_job_id'=>(int)$this->jobId($existing),'state'=>'cancelled','idempotent'=>true);
            }
            if((string)$confirmation->state!=='confirmed')throw new \InvalidArgumentException('booking_not_cancellable');
            if((string)$confirmation->delivery_state!=='confirmed'||(string)$confirmation->lifecycle_status!=='closed'||(string)$confirmation->resolution_state!=='teacher_confirmed'||$confirmation->privacy_erased_at!==null)throw new \InvalidArgumentException('booking_not_cancellable');
            $studentId=(int)($confirmation->student_id??0);if($studentId<1)throw new \InvalidArgumentException('Resolved Student required');
            // Serialise with intake, acceptance and scheduling before releasing the slot.
            (new CanonicalLessonScheduleRepository())->ensureAndLockStudentRoot($studentId,$now,$actor);
            $resolution=$this->resolution($reasonCode);
            $cancellationId=$this->repository->insertCancellation(array(
                'uid'=>Identifier::uid(),'confirmation_id'=>(int)$confirmation->id,'booking_request_id'=>(int)$confirmation->booking_request_id,
                'delivery_id'=>(int)$confirmation->delivery_id,'teacher_id'=>(int)$confirmation->teacher_id,'student_id'=>$studentId,
                'reason_code'=>$reasonCode,'evidence_reference_digest'=>$evidenceDigest,
                'previous_confirmation_state'=>(string)$confirmation->state,'previous_delivery_state'=>(string)$confirmation->delivery_state,
                'previous_request_lifecycle'=>(string)$confirmation->lifecycle_status,'previous_request_resolution'=>(string)$confirmation->resolution_state,
                'cancelled_at'=>$now,'cancelled_by'=>$actor,'command_key_digest'=>$keyDigest,'created_at'=>$now,
            ));
            $this->repository->transitionConfirmation((int)$confirmation->id,(int)$confirmation->version,'confirmed','cancelled',$now,$actor);
            $this->repository->transitionDelivery((int)$confirmation->delivery_id,'confirmed','cancelled',$now);
            $this->repository->cancelRequest((int)$confirmation->booking_request_id,'teacher_confirmed',$resolution,$now,$actor);
            $this->repository->closeCoordinationCase((int)$confirmation->booking_request_id,$now,$actor);
            $this->repository->queueCancellationNotification((int)$confirmation->delivery_id,(int)$confirmation->id,$now);
            $jobId=$this->repository->insertRetractionJob(array(
                'uid'=>Identifier::uid(),'cancellation_id'=>$cancellationId,'confirmation_id'=>(int)$confirmation->id,
                'connection_id'=>(int)$confirmation->connection_id,'teacher_id'=>(int)$confirmation->teacher_id,
                'state'=>'queued','idempotency_key'=>hash_hmac('sha256','booking-cancellation-retraction:'.$cancellationId,wp_salt('dzn_booking_cancellation')),
                'attempt_count'=>0,'next_attempt_at'=>$now,'lease_token_digest'=>null,'lease_expires_at'=>null,
                'last_error_code'=>null,'completed_at'=>null,'created_at'=>$now,'updated_at'=>$now,
            ));
            $this->repository->insertCommand(array('uid'=>Identifier::uid(),'command_key_digest'=>$keyDigest,'command_payload_digest'=>$payloadDigest,'cancellation_id'=>$cancellationId,'result_state'=>'cancelled','created_at'=>$now,'created_by'=>$actor));
            $this->repository->commit();
            return array('cancellation_id'=>$cancellationId,'retraction_job_id'=>$jobId,'state'=>'cancelled','idempotent'=>false);
        }catch(\Throwable $error){
            $this->repository->rollback();
            if($winner=$this->repository->command($keyDigest))return $this->replay($winner,$payloadDigest);
            if(isset($confirmation)&&is_object($confirmation)){
                $existing=$this->repository->cancellationForConfirmation((int)$confirmation->id);
                if($existing&&(string)$existing->reason_code===$reasonCode)return array('cancellation_id'=>(int)$existing->id,'retraction_job_id'=>(int)$this->jobId($existing),'state'=>'cancelled','idempotent'=>true);
            }
            throw $error;
        }
    }

    private function replay(object $command,string $payloadDigest):array{
        if(!hash_equals((string)$command->command_payload_digest,$payloadDigest))throw new IdempotencyConflictException('Idempotency conflict');
        $cancellation=$this->repository->cancellation((int)$command->cancellation_id);
        if(!$cancellation)throw new \RuntimeException('Contaminated cancellation command');
        return array('cancellation_id'=>(int)$cancellation->id,'retraction_job_id'=>(int)$this->jobId($cancellation),'state'=>'cancelled','idempotent'=>true);
    }
    private function jobId(object $cancellation):int{$job=$this->repository->retractionJobForCancellation((int)$cancellation->id);return $job?(int)$job->id:0;}
    private function resolution(string $reason):string{return match($reason){'duplicate_request'=>'cancelled_duplicate','student_request'=>'cancelled_student','teacher_unavailable'=>'cancelled_teacher','academy_cancelled'=>'cancelled_academy',default=>'cancelled_operator'};}
}
