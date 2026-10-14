<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\{IntegrationSecretService,ProviderIntegrationIdempotency,ProviderReferenceVault};
use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/** Idempotently creates the Calendar event/Meet link before an introductory booking may confirm. */
final class BookingCalendarProjectionService {
    public function __construct(
        private ?BookingTeacherResponseRepository $repository=null,
        private ?IntegrationSecretService $credentials=null,
        private ?ProviderReferenceVault $references=null,
        private ?GoogleCalendarProjectionTransport $transport=null
    ){$this->repository??=new BookingTeacherResponseRepository();$this->credentials??=new IntegrationSecretService();$this->references??=new ProviderReferenceVault();$this->transport??=new GoogleCalendarProjectionTransport();}

    public function dispatch(int $confirmationId):array{
        $confirmation=$this->repository->confirmation($confirmationId);if(!$confirmation)throw new \InvalidArgumentException('Booking confirmation not found');
        if((string)$confirmation->state==='confirmed')return array('confirmation_id'=>$confirmationId,'state'=>'confirmed','idempotent'=>true);
        if(!in_array((string)$confirmation->state,array('pending_projection','awaiting_conference','retryable'),true))throw new \InvalidArgumentException('Booking confirmation is not projectable');
        try{
            $credential=$this->repository->credential((int)$confirmation->connection_id);if(!$credential)throw new \RuntimeException('provider_credential_required');
            if($credential->valid_until_utc!==null&&strtotime((string)$credential->valid_until_utc.' UTC')<=time()+60)throw new \RuntimeException('provider_credential_refresh_required');
            $material=$this->credentials->open((int)$confirmation->connection_id,'google_calendar',$credential);
            if((string)$confirmation->state==='awaiting_conference'){
                $secret=$this->repository->projectionSecret($confirmationId);if(!$secret)throw new \RuntimeException('provider_reference_required');
                $opened=$this->references->open('booking_calendar',$confirmationId,$secret);$event=(string)($opened['provider_object_reference']??'');
                $provider=$this->transport->inspect($event,$material);
            }else{$provider=$this->transport->dispatch($this->request($confirmation),$material);}
            return empty($provider['join_uri_reference'])?$this->awaitConference($confirmation,$provider):$this->confirm($confirmation,$provider);
        }catch(\Throwable $error){
            $code=in_array($error->getMessage(),array('provider_credential_required','provider_credential_refresh_required','provider_reference_required'),true)?$error->getMessage():'provider_projection_retryable';
            $this->retry($confirmation,$code);return array('confirmation_id'=>$confirmationId,'state'=>'retryable','failure_reason_code'=>$code,'retryable'=>true);
        }
    }

    private function request(object $confirmation):array{
        $eventId=substr(hash('sha256','dzn:booking-confirmation:'.(string)$confirmation->uid),0,32);
        return array('method'=>'POST','origin'=>'https://www.googleapis.com','path'=>'/calendar/v3/calendars/primary/events','query'=>array('conferenceDataVersion'=>1,'sendUpdates'=>'none'),'body'=>array(
            'id'=>$eventId,'summary'=>'Delnavazan introductory lesson',
            'start'=>array('dateTime'=>$this->rfc3339((string)$confirmation->starts_at_utc),'timeZone'=>(string)$confirmation->schedule_timezone),
            'end'=>array('dateTime'=>$this->rfc3339((string)$confirmation->ends_at_utc),'timeZone'=>(string)$confirmation->schedule_timezone),
            'conferenceData'=>array('createRequest'=>array('requestId'=>substr(hash('sha256','dzn-meet:booking-confirmation:'.(string)$confirmation->uid),0,40),'conferenceSolutionKey'=>array('type'=>'hangoutsMeet'))),
            'extendedProperties'=>array('private'=>array('dzn_booking_confirmation_uid'=>(string)$confirmation->uid)),
        ));
    }

    private function awaitConference(object $observed,array $provider):array{
        $reference=trim((string)($provider['provider_object_reference']??''));if($reference==='')throw new \RuntimeException('provider_reference_required');
        $now=gmdate('Y-m-d H:i:s');$actor=(int)get_current_user_id();$this->repository->begin();
        try{
            $row=$this->repository->confirmation((int)$observed->id,true);if(!$row||!in_array((string)$row->state,array('pending_projection','retryable','awaiting_conference'),true))throw new \RuntimeException('Booking confirmation changed concurrently');
            $secret=$this->repository->projectionSecret((int)$row->id);if(!$secret){$sealed=$this->references->seal('booking_calendar',(int)$row->id,array('provider_object_reference'=>$reference));$this->repository->insertSecret(array_merge(array('uid'=>Identifier::uid(),'mapping_kind'=>'booking_calendar','mapping_id'=>(int)$row->id,'state'=>'active','active_slot'=>1,'created_at'=>$now,'created_by'=>$actor,'retired_at'=>null,'retired_by'=>null),$sealed));}
            $this->repository->updateConfirmation((int)$row->id,(int)$row->version,array('state'=>'awaiting_conference','provider_event_digest'=>ProviderIntegrationIdempotency::subject($reference,'calendar_event'),'failure_reason_code'=>null,'attempt_count'=>(int)$row->attempt_count+1,'next_attempt_at'=>gmdate('Y-m-d H:i:s',time()+120),'updated_at'=>$now,'updated_by'=>$actor));
            $this->repository->commit();return array('confirmation_id'=>(int)$row->id,'state'=>'awaiting_conference','retryable'=>true);
        }catch(\Throwable $error){$this->repository->rollback();throw $error;}
    }

    private function confirm(object $observed,array $provider):array{
        $reference=trim((string)($provider['provider_object_reference']??''));$join=trim((string)($provider['join_uri_reference']??''));if($reference===''||$join==='')throw new \RuntimeException('provider_reference_required');
        $now=gmdate('Y-m-d H:i:s');$actor=(int)get_current_user_id();$this->repository->begin();
        try{
            $row=$this->repository->confirmation((int)$observed->id,true);if(!$row)throw new \RuntimeException('Booking confirmation changed concurrently');if((string)$row->state==='confirmed'){$this->repository->commit();return array('confirmation_id'=>(int)$row->id,'state'=>'confirmed','idempotent'=>true);}
            if(!in_array((string)$row->state,array('pending_projection','retryable','awaiting_conference'),true))throw new \RuntimeException('Booking confirmation changed concurrently');
            $delivery=$this->repository->delivery((int)$row->delivery_id,true);if(!$delivery||(string)$delivery->state!=='accepting'||(string)$delivery->lifecycle_status!=='submitted'||(string)$delivery->resolution_state!=='unresolved'||$delivery->privacy_erased_at!==null)throw new \RuntimeException('Booking request changed concurrently');
            $existing=$this->repository->projectionSecret((int)$row->id);if($existing)$this->repository->retireSecret((int)$existing->id,$actor,$now);
            $sealed=$this->references->seal('booking_calendar',(int)$row->id,array('provider_object_reference'=>$reference,'join_uri_reference'=>$join));
            $this->repository->insertSecret(array_merge(array('uid'=>Identifier::uid(),'mapping_kind'=>'booking_calendar','mapping_id'=>(int)$row->id,'state'=>'active','active_slot'=>1,'created_at'=>$now,'created_by'=>$actor,'retired_at'=>null,'retired_by'=>null),$sealed));
            $this->repository->updateConfirmation((int)$row->id,(int)$row->version,array('state'=>'confirmed','provider_event_digest'=>ProviderIntegrationIdempotency::subject($reference,'calendar_event'),'failure_reason_code'=>null,'attempt_count'=>(int)$row->attempt_count+1,'next_attempt_at'=>null,'confirmed_at'=>$now,'updated_at'=>$now,'updated_by'=>$actor));
            $this->repository->transitionDelivery((int)$delivery->id,'accepting','confirmed',$now);$this->repository->closeRequest((int)$delivery->booking_request_id,'teacher_confirmed',$now,$actor);
            if(!$this->repository->notificationForDeliveryKind((int)$delivery->id,'confirmed'))$this->repository->insertNotification(array('uid'=>Identifier::uid(),'delivery_id'=>(int)$delivery->id,'confirmation_id'=>(int)$row->id,'notification_kind'=>'confirmed','state'=>'queued','attempt_count'=>0,'next_attempt_at'=>$now,'last_error_code'=>null,'accepted_at'=>null,'created_at'=>$now,'updated_at'=>$now));
            $this->repository->commit();return array('confirmation_id'=>(int)$row->id,'state'=>'confirmed','idempotent'=>false);
        }catch(\Throwable $error){$this->repository->rollback();throw $error;}
    }

    private function retry(object $observed,string $reason):void{
        $now=gmdate('Y-m-d H:i:s');$actor=(int)get_current_user_id();$this->repository->begin();
        try{$row=$this->repository->confirmation((int)$observed->id,true);if($row&&in_array((string)$row->state,array('pending_projection','retryable','awaiting_conference'),true)){$attempt=(int)$row->attempt_count+1;$delay=min(3600,60*(2**min(5,$attempt)));$this->repository->updateConfirmation((int)$row->id,(int)$row->version,array('state'=>'retryable','failure_reason_code'=>$reason,'attempt_count'=>$attempt,'next_attempt_at'=>gmdate('Y-m-d H:i:s',time()+$delay),'updated_at'=>$now,'updated_by'=>$actor));}$this->repository->commit();}catch(\Throwable $error){$this->repository->rollback();throw $error;}
    }
    private function rfc3339(string $utc):string{return str_replace(' ','T',substr($utc,0,19)).'Z';}
}
