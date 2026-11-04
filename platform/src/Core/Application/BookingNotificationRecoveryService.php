<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository;

/**
 * Authorised, idempotent recovery of one failed booking notification.
 *
 * Recovery re-reads the current booking/delivery state and current canonical recipient.
 * It never changes the booking request or Teacher delivery. Stale invitations are refused.
 */
final class BookingNotificationRecoveryService {
    public const CAPABILITY='dzn_operate_notification_dispatch';

    public function __construct(private ?BookingTeacherResponseRepository $repository=null){$this->repository??=new BookingTeacherResponseRepository();}

    /** @return array<string,mixed> */
    public function preview(int $notificationId):array{
        $this->authorise();
        $row=$this->repository->notificationPayload($notificationId);
        if(!$row)throw new \InvalidArgumentException('notification_not_found');
        $recipient=BookingNotificationRecipient::email($row);
        $eligible=(string)$row->state==='failed'
            &&BookingNotificationRelevance::isStillRelevant($row)
            &&function_exists('is_email')&&is_email($recipient);
        return array(
            'notification_id'=>$notificationId,
            'event_key'=>(string)$row->notification_kind,
            'audience'=>str_ends_with((string)$row->notification_kind,'_teacher')?'teacher':'student',
            'channel'=>'email',
            'state'=>(string)$row->state,
            'attempt_count'=>(int)$row->attempt_count,
            'last_error_code'=>(string)($row->last_error_code??''),
            'updated_at'=>(string)($row->updated_at??''),
            'eligible'=>$eligible,
            'reason'=>$eligible?'Eligible for one authorised recovery attempt.':$this->reason($row,$recipient),
        );
    }

    /** @return array{notification_id:int,state:string,replayed:bool,reason:string} */
    public function recover(int $notificationId,string $commandKey):array{
        $this->authorise();
        if($notificationId<1)throw new \InvalidArgumentException('notification_not_found');
        $commandKey=trim($commandKey);
        if($commandKey==='')throw new \InvalidArgumentException('notification_command_required');
        $digest=NotificationSupport::keyDigest('booking-notification-recovery:'.$commandKey);
        if($existing=$this->repository->auditByDigest($digest))return $this->replay($existing,$notificationId);
        $this->repository->begin();
        try{
            $row=$this->repository->notificationForUpdate($notificationId);
            if(!$row)throw new \InvalidArgumentException('notification_not_found');
            $payload=$this->repository->notificationPayload($notificationId,true);
            if(!$payload)throw new \InvalidArgumentException('notification_not_found');
            $now=NotificationSupport::now();$actor=NotificationSupport::actor();
            $reason='';
            if((string)$row->state!=='failed')$reason='notification_not_retryable';
            elseif(!BookingNotificationRelevance::isStillRelevant($payload))$reason='invitation_stale';
            elseif(!function_exists('is_email')||!is_email(BookingNotificationRecipient::email($payload)))$reason='recipient_contact_unusable';
            if($reason!==''){
                $this->audit($payload,$digest,$actor,$now,'refused',$reason,(string)$row->state);
                $this->repository->commit();
                return array('notification_id'=>$notificationId,'state'=>'failed','replayed'=>false,'reason'=>$reason);
            }
            $this->repository->updateNotification($notificationId,'failed',array(
                'state'=>'queued','attempt_count'=>0,'next_attempt_at'=>$now,'lease_token_digest'=>null,
                'lease_expires_at'=>null,'last_error_code'=>null,'updated_at'=>$now,
            ));
            $this->audit($payload,$digest,$actor,$now,'queued','',(string)$row->state);
            $this->repository->commit();
            return array('notification_id'=>$notificationId,'state'=>'queued','replayed'=>false,'reason'=>'');
        }catch(\Throwable $error){$this->repository->rollback();throw $error;}
    }

    private function replay(object $audit,int $notificationId):array{
        $detail=json_decode((string)($audit->safe_detail??''),true);
        if(!is_array($detail)||(int)($detail['notification_id']??0)!==$notificationId)throw new IdempotencyConflictException('notification_idempotency_conflict');
        return array('notification_id'=>$notificationId,'state'=>(string)($detail['result_state']??'failed'),'replayed'=>true,'reason'=>(string)($detail['reason']??''));
    }

    private function audit(object $payload,string $digest,int $actor,string $now,string $result,string $reason,string $fromState):void{
        $this->repository->insertAudit(array(
            'aggregate_type'=>'booking_request','aggregate_id'=>(int)$payload->booking_request_id,
            'event_type'=>'booking_request.notification_recovery','actor_type'=>'administrator','actor_id'=>$actor,
            'reason_code'=>$reason!==''?$reason:'staff_recovery',
            'safe_detail'=>wp_json_encode(array(
                'notification_id'=>(int)$payload->id,'notification_kind'=>(string)$payload->notification_kind,
                'audience'=>str_ends_with((string)$payload->notification_kind,'_teacher')?'teacher':'student',
                'from_state'=>$fromState,'result_state'=>$result,'reason'=>$reason,
                'original_error_code'=>(string)($payload->last_error_code??''),
                'original_attempt_count'=>(int)$payload->attempt_count,
            ),JSON_UNESCAPED_SLASHES),
            'idempotency_key'=>$digest,'occurred_at'=>$now,
        ));
    }

    private function reason(object $row,string $recipient):string{
        if((string)$row->state!=='failed')return 'Only a failed booking notification can be recovered.';
        if(!BookingNotificationRelevance::isStillRelevant($row))return 'The invitation is cancelled, resolved or otherwise no longer relevant.';
        if(!function_exists('is_email')||!is_email($recipient))return 'The current canonical recipient address is missing or invalid.';
        return 'Eligible for one authorised recovery attempt.';
    }
    private function authorise():void{if(!current_user_can(self::CAPABILITY))throw new \RuntimeException('Unauthorized');}
}
