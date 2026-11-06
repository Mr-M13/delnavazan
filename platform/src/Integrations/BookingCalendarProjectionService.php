<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\{BookingProvisioningRecoveryRule,IntegrationSecretService,ProviderIntegrationIdempotency,ProviderReferenceVault};
use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherResponseRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/** Idempotently creates the Calendar event/Meet link before an introductory booking may confirm. */
final class BookingCalendarProjectionService {
    private const MAX_ATTEMPTS=6;
    public function __construct(
        private ?BookingTeacherResponseRepository $repository=null,
        private ?IntegrationSecretService $credentials=null,
        private ?ProviderReferenceVault $references=null,
        private ?GoogleCalendarProjectionTransport $transport=null,
        private ?BookingGoogleCredentialRefresher $refresher=null
    ){$this->repository??=new BookingTeacherResponseRepository();$this->credentials??=new IntegrationSecretService();$this->references??=new ProviderReferenceVault();$this->transport??=new GoogleCalendarProjectionTransport();$this->refresher??=new BookingGoogleCredentialRefresher();}

    public function dispatch(int $confirmationId):array{
        $lock=$this->acquireLock($confirmationId);
        if($lock===false)return array('confirmation_id'=>$confirmationId,'state'=>'retryable','failure_reason_code'=>'booking_projection_busy','retryable'=>true);
        try{
            $confirmation=$this->repository->confirmation($confirmationId);if(!$confirmation)throw new \InvalidArgumentException('Booking confirmation not found');
            if((string)$confirmation->state==='confirmed')return array('confirmation_id'=>$confirmationId,'state'=>'confirmed','idempotent'=>true);
            if((string)$confirmation->state==='awaiting_payment')throw new \InvalidArgumentException('intro_payment_required');
            if(!in_array((string)$confirmation->state,array('pending_projection','awaiting_conference','retryable'),true))throw new \InvalidArgumentException('Booking confirmation is not projectable');
            $centralInstalled=class_exists('Delnavazan\\Platform\\Integrations\\GoogleWorkspace\\GoogleWorkspaceClassroomService');
            if($centralInstalled&&!\Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceClassroomService::enabled()){
                $state=$this->retry($confirmation,'meet_classroom_paused',true);
                return array('confirmation_id'=>$confirmationId,'state'=>$state,'failure_reason_code'=>'meet_classroom_paused','retryable'=>true);
            }
            // The central Delnavazan Workspace classroom is the production path. Once the central
            // code is deployed, the legacy Teacher calendar path is not used; a paused or
            // re-authorising organizer leaves the accepted booking durably retryable.
            if($centralInstalled){
                if(!\Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceClassroomService::configured()){
                    $state=$this->retry($confirmation,'meet_organizer_not_connected',true);
                    return array('confirmation_id'=>$confirmationId,'state'=>$state,'failure_reason_code'=>'meet_organizer_not_connected','retryable'=>true);
                }
                try{
                    $provider=(new \Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceClassroomService())->ensureForBooking($confirmationId);
                    return $this->confirm($confirmation,$provider,false);
                }catch(\Throwable $error){
                    $code=$this->safeCode($error->getMessage(),'meet_classroom_projection_failed');
                    $retryable=$this->retryableCentralFailure($code);
                    $state=$this->retry($confirmation,$code,$retryable);
                    return array('confirmation_id'=>$confirmationId,'state'=>$state,'failure_reason_code'=>$code,'retryable'=>$state==='retryable');
                }
            }
            try{
                $credential=$this->repository->credential((int)$confirmation->connection_id);if(!$credential)throw new \RuntimeException('provider_credential_required');
                if($credential->valid_until_utc!==null&&strtotime((string)$credential->valid_until_utc.' UTC')<=time()+60){
                    if(!$this->refresher->refresh((int)$confirmation->connection_id,$confirmationId,(int)$confirmation->attempt_count))throw new \RuntimeException('provider_credential_refresh_required');
                    $credential=$this->repository->credential((int)$confirmation->connection_id);
                    if(!$credential||($credential->valid_until_utc!==null&&strtotime((string)$credential->valid_until_utc.' UTC')<=time()+60))throw new \RuntimeException('provider_credential_refresh_required');
                }
                $material=$this->credentials->open((int)$confirmation->connection_id,'google_calendar',$credential);
                if((string)$confirmation->state==='awaiting_conference'){
                    $secret=$this->repository->projectionSecret($confirmationId);if(!$secret)throw new \RuntimeException('provider_reference_required');
                    $opened=$this->references->open('booking_calendar',$confirmationId,$secret);$event=(string)($opened['provider_object_reference']??'');
                    $provider=$this->transport->inspect($event,$material);
                }else{$provider=$this->transport->dispatch($this->request($confirmation),$material);}
                return empty($provider['join_uri_reference'])?$this->awaitConference($confirmation,$provider):$this->confirm($confirmation,$provider);
            }catch(\Throwable $error){
                $code=in_array($error->getMessage(),array('provider_credential_required','provider_credential_refresh_required','provider_reference_required'),true)?$error->getMessage():'provider_projection_retryable';
                $state=$this->retry($confirmation,$code);return array('confirmation_id'=>$confirmationId,'state'=>$state,'failure_reason_code'=>$code,'retryable'=>$state==='retryable');
            }
        }finally{
            $this->releaseLock(is_string($lock)?$lock:null);
        }
    }

    /**
     * Explicit administrator recovery for a terminal booking projection failure.
     *
     * Teacher acceptance is already durable before this method runs. Recovery therefore only
     * reactivates the same confirmation and delivery; it never returns the delivery to the
     * pending-request state, creates another offer, or asks the Teacher to accept again.
     */
    public function retryFailed(int $confirmationId,?int $expectedVersion=null,?string $reason=null):array{
        if(function_exists('current_user_can')&&!current_user_can('dzn_manage_lessons')&&!current_user_can('dzn_manage_booking_request_coordination')&&!current_user_can('manage_options'))throw new \RuntimeException('Unauthorized');
        if(function_exists('get_current_user_id')&&(int)get_current_user_id()<1)throw new \RuntimeException('Unauthorized');
        $observed=$this->repository->confirmation($confirmationId);
        if(!$observed||(string)$observed->state!=='failed')throw new \InvalidArgumentException('Booking confirmation is not failed');
        if($expectedVersion!==null&&(int)$observed->version!==$expectedVersion)throw new \RuntimeException('Booking confirmation changed concurrently');
        $now=gmdate('Y-m-d H:i:s');$actor=function_exists('get_current_user_id')?(int)get_current_user_id():0;
        $this->repository->begin();
        try{
            $row=$this->repository->confirmation($confirmationId,true);
            if(!$row||(string)$row->state!=='failed')throw new \RuntimeException('Booking confirmation changed concurrently');
            if($expectedVersion!==null&&(int)$row->version!==$expectedVersion)throw new \RuntimeException('Booking confirmation changed concurrently');
            $delivery=$this->repository->delivery((int)$row->delivery_id,true);
            $this->assertRecoveryEligible($row,$delivery,$now);
            if((string)$delivery->state==='pending_request'){
                // Repair only the historical bug where a provider failure moved the accepted
                // delivery back to pending_request. The current path never performs this move.
                $this->repository->transitionDelivery((int)$delivery->id,'pending_request','accepting',$now);
            }
            $this->repository->updateConfirmation($confirmationId,(int)$row->version,array(
                'state'=>'retryable','failure_reason_code'=>null,'attempt_count'=>0,
                'next_attempt_at'=>$now,'updated_at'=>$now,'updated_by'=>$actor,
            ));
            $this->recordRecoveryAudit($row,$actor,trim((string)$reason)?:'administrator_projection_retry',$now);
            $this->repository->commit();
        }catch(\Throwable $error){$this->repository->rollback();throw $error;}
        return $this->dispatch($confirmationId);
    }

    /**
     * Read-only eligibility shared by the administrator command and the dashboard.
     *
     * A cancelled, superseded, ended or reallocated booking is never resurrected. A legacy
     * pending_request delivery may be repaired only while it still anchors this exact confirmation.
     */
    private function assertRecoveryEligible(object $confirmation,?object $delivery,string $now):void{
        // A confirmed acceptance must have consumed its offer. Any live offer means allocation
        // has started another Teacher response and must not be overwritten by a delayed retry.
        $liveOffer=method_exists($this->repository,'activeAllocationOffer')
            &&$this->repository->activeAllocationOffer((int)$confirmation->booking_request_id)!==null;
        $assessment=BookingProvisioningRecoveryRule::assess(
            array(
                'state'=>(string)$confirmation->state,
                'delivery_id'=>(int)$confirmation->delivery_id,
                'booking_request_id'=>(int)$confirmation->booking_request_id,
                'teacher_id'=>(int)$confirmation->teacher_id,
                'requested_time_id'=>(int)$confirmation->requested_time_id,
                'starts_at_utc'=>(string)$confirmation->starts_at_utc,
            ),
            $delivery?array(
                'id'=>(int)$delivery->id,
                'booking_request_id'=>(int)$delivery->booking_request_id,
                'teacher_id'=>(int)$delivery->teacher_id,
                'requested_time_id'=>(int)$delivery->requested_time_id,
                'state'=>(string)$delivery->state,
                'lifecycle_status'=>(string)$delivery->lifecycle_status,
                'resolution_state'=>(string)$delivery->resolution_state,
                'privacy_erased_at'=>$delivery->privacy_erased_at,
            ):null,
            $liveOffer,
            $now
        );
        if($assessment['eligible'])return;
        $message=BookingProvisioningRecoveryRule::message($assessment['reason']);
        if(in_array($assessment['reason'],array(BookingProvisioningRecoveryRule::NOT_FAILED,BookingProvisioningRecoveryRule::DELIVERY_MISSING,BookingProvisioningRecoveryRule::OWNERSHIP_MISMATCH),true))throw new \RuntimeException($message);
        throw new \InvalidArgumentException($message);
    }

    private function recordRecoveryAudit(object $confirmation,int $actor,string $reason,string $now):void{
        if(!method_exists($this->repository,'auditByDigest')||!method_exists($this->repository,'insertAudit'))return;
        $digest=hash('sha256','booking-projection-recovery:'.(int)$confirmation->id.':'.(int)$confirmation->version);
        if($this->repository->auditByDigest($digest))return;
        $safeReason=preg_match('/^[a-z0-9_]{3,64}$/D',$reason)===1?$reason:'administrator_projection_retry';
        $this->repository->insertAudit(array(
            'aggregate_type'=>'booking_confirmation','aggregate_id'=>(int)$confirmation->id,
            'event_type'=>'projection_retry_authorised','actor_type'=>'administrator','actor_id'=>$actor>0?$actor:null,
            'reason_code'=>$safeReason,
            'safe_detail'=>wp_json_encode(array(
                'reason'=>substr($reason,0,255),
                'delivery_id'=>(int)$confirmation->delivery_id,
                'booking_request_id'=>(int)$confirmation->booking_request_id,
                'confirmation_version'=>(int)$confirmation->version,
                'failure_reason_code'=>$confirmation->failure_reason_code!==null?(string)$confirmation->failure_reason_code:null,
                'attempt_count'=>(int)$confirmation->attempt_count,
            ),JSON_UNESCAPED_SLASHES),
            'idempotency_key'=>$digest,'occurred_at'=>$now,
        ));
    }

    /** Move the provider event after the local confirmation has been revalidated. */
    public function reschedule(int $confirmationId,string $startsAtUtc,string $endsAtUtc,string $timezone):array{
        $confirmation=$this->repository->confirmation($confirmationId);
        if(!$confirmation)throw new \InvalidArgumentException('Booking confirmation not found');
        if((string)$confirmation->state!=='confirmed')throw new \InvalidArgumentException('Booking confirmation is not provider-confirmed');
        if(class_exists('Delnavazan\\Platform\\Integrations\\GoogleWorkspace\\GoogleWorkspaceClassroomService')&&\Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceClassroomService::organizerConnected()){
            $provider=(new \Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceClassroomService())->rescheduleForBooking($confirmationId,$startsAtUtc,$endsAtUtc,$timezone);
            return array(
                'confirmation_id'=>$confirmationId,
                'state'=>'confirmed',
                'provider_object_reference'=>(string)($provider['provider_object_reference']??''),
                'join_uri_reference'=>$provider['join_uri_reference']??null,
            );
        }
        $secret=$this->repository->projectionSecret($confirmationId);
        if(!$secret)throw new \RuntimeException('provider_reference_required');
        $opened=$this->references->open('booking_calendar',$confirmationId,$secret);
        $event=trim((string)($opened['provider_object_reference']??''));
        if($event==='')throw new \RuntimeException('provider_reference_required');
        $material=$this->credentialMaterial($confirmation);
        $provider=$this->transport->revise($event,$startsAtUtc,$endsAtUtc,$timezone,$material);
        return array(
            'confirmation_id'=>$confirmationId,
            'state'=>'confirmed',
            'provider_object_reference'=>(string)($provider['provider_object_reference']??$event),
            'join_uri_reference'=>$provider['join_uri_reference']??null,
        );
    }

    /** Remove the provider event; the local cancellation authority decides the final state. */
    public function cancel(int $confirmationId):array{
        $confirmation=$this->repository->confirmation($confirmationId);
        if(!$confirmation)throw new \InvalidArgumentException('Booking confirmation not found');
        if(class_exists('Delnavazan\\Platform\\Integrations\\GoogleWorkspace\\GoogleWorkspaceClassroomService')&&\Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceClassroomService::organizerConnected()){
            $result=(new \Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceClassroomService())->cancelForBooking($confirmationId);
            if(!empty($result['cancelled']))return array('confirmation_id'=>$confirmationId,'state'=>'cancelled','idempotent'=>false);
        }
        if((string)$confirmation->state!=='confirmed')return array('confirmation_id'=>$confirmationId,'state'=>'not_projected','idempotent'=>true);
        $secret=$this->repository->projectionSecret($confirmationId);
        if(!$secret)return array('confirmation_id'=>$confirmationId,'state'=>'not_projected','idempotent'=>true);
        $opened=$this->references->open('booking_calendar',$confirmationId,$secret);
        $event=trim((string)($opened['provider_object_reference']??''));
        if($event==='')return array('confirmation_id'=>$confirmationId,'state'=>'not_projected','idempotent'=>true);
        $this->transport->cancel($event,$this->credentialMaterial($confirmation));
        $this->repository->retireSecret((int)$secret->id,(int)get_current_user_id(),gmdate('Y-m-d H:i:s'));
        return array('confirmation_id'=>$confirmationId,'state'=>'cancelled','idempotent'=>false);
    }

    private function credentialMaterial(object $confirmation):string{
        $credential=$this->repository->credential((int)$confirmation->connection_id);
        if(!$credential)throw new \RuntimeException('provider_credential_required');
        if($credential->valid_until_utc!==null&&strtotime((string)$credential->valid_until_utc.' UTC')<=time()+60){
            if(!$this->refresher->refresh((int)$confirmation->connection_id,(int)$confirmation->id,(int)$confirmation->attempt_count))throw new \RuntimeException('provider_credential_refresh_required');
            $credential=$this->repository->credential((int)$confirmation->connection_id);
            if(!$credential||($credential->valid_until_utc!==null&&strtotime((string)$credential->valid_until_utc.' UTC')<=time()+60))throw new \RuntimeException('provider_credential_refresh_required');
        }
        return $this->credentials->open((int)$confirmation->connection_id,'google_calendar',$credential);
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
            $attempt=(int)$row->attempt_count+1;
            if($attempt>=self::MAX_ATTEMPTS){
                // Terminal provisioning failure. Teacher acceptance and the delivery remain intact;
                // only the confirmation becomes administrator-retryable.
                $this->repository->updateConfirmation((int)$row->id,(int)$row->version,array('state'=>'failed','provider_event_digest'=>ProviderIntegrationIdempotency::subject($reference,'calendar_event'),'failure_reason_code'=>'provider_conference_unavailable','attempt_count'=>$attempt,'next_attempt_at'=>null,'updated_at'=>$now,'updated_by'=>$actor));
                $this->repository->commit();return array('confirmation_id'=>(int)$row->id,'state'=>'failed','failure_reason_code'=>'provider_conference_unavailable','retryable'=>false);
            }
            $this->repository->updateConfirmation((int)$row->id,(int)$row->version,array('state'=>'awaiting_conference','provider_event_digest'=>ProviderIntegrationIdempotency::subject($reference,'calendar_event'),'failure_reason_code'=>null,'attempt_count'=>$attempt,'next_attempt_at'=>gmdate('Y-m-d H:i:s',time()+120),'updated_at'=>$now,'updated_by'=>$actor));
            $this->repository->commit();return array('confirmation_id'=>(int)$row->id,'state'=>'awaiting_conference','retryable'=>true);
        }catch(\Throwable $error){$this->repository->rollback();throw $error;}
    }

    private function confirm(object $observed,array $provider,bool $legacyVault=true):array{
        $reference=trim((string)($provider['provider_object_reference']??''));$join=trim((string)($provider['join_uri_reference']??''));if($reference===''||$join==='')throw new \RuntimeException('provider_reference_required');
        $now=gmdate('Y-m-d H:i:s');$actor=(int)get_current_user_id();$this->repository->begin();
        try{
            $row=$this->repository->confirmation((int)$observed->id,true);if(!$row)throw new \RuntimeException('Booking confirmation changed concurrently');if((string)$row->state==='confirmed'){$this->repository->commit();return array('confirmation_id'=>(int)$row->id,'state'=>'confirmed','idempotent'=>true);}
            if(!in_array((string)$row->state,array('pending_projection','retryable','awaiting_conference'),true))throw new \RuntimeException('Booking confirmation changed concurrently');
            $delivery=$this->repository->delivery((int)$row->delivery_id,true);if(!$delivery)throw new \RuntimeException('Booking request changed concurrently');
            // Repair the historical bug where a provider failure moved an accepted delivery back
            // to pending_request. Only the exact confirmation owner may move it forward; any live
            // re-offer still refuses recovery to avoid a duplicate booking.
            if((string)$delivery->state==='pending_request'){
                if((int)$delivery->booking_request_id!==(int)$row->booking_request_id
                    ||(int)$delivery->teacher_id!==(int)$row->teacher_id
                    ||(int)$delivery->requested_time_id!==(int)$row->requested_time_id)throw new \RuntimeException('Booking request changed concurrently');
                if(method_exists($this->repository,'activeAllocationOffer')&&$this->repository->activeAllocationOffer((int)$row->booking_request_id))throw new \RuntimeException('Booking allocation has been superseded');
                $this->repository->transitionDelivery((int)$delivery->id,'pending_request','accepting',$now);
                $delivery->state='accepting';
            }
            if((string)$delivery->state!=='accepting'||(string)$delivery->lifecycle_status!=='submitted'||(string)$delivery->resolution_state!=='unresolved'||$delivery->privacy_erased_at!==null)throw new \RuntimeException('Booking request changed concurrently');
            if($legacyVault){
                $existing=$this->repository->projectionSecret((int)$row->id);if($existing)$this->repository->retireSecret((int)$existing->id,$actor,$now);
                $sealed=$this->references->seal('booking_calendar',(int)$row->id,array('provider_object_reference'=>$reference,'join_uri_reference'=>$join));
                $this->repository->insertSecret(array_merge(array('uid'=>Identifier::uid(),'mapping_kind'=>'booking_calendar','mapping_id'=>(int)$row->id,'state'=>'active','active_slot'=>1,'created_at'=>$now,'created_by'=>$actor,'retired_at'=>null,'retired_by'=>null),$sealed));
            }
            $this->repository->updateConfirmation((int)$row->id,(int)$row->version,array('state'=>'confirmed','provider_event_digest'=>ProviderIntegrationIdempotency::subject($reference,'calendar_event'),'failure_reason_code'=>null,'attempt_count'=>(int)$row->attempt_count+1,'next_attempt_at'=>null,'confirmed_at'=>$now,'updated_at'=>$now,'updated_by'=>$actor));
            $this->repository->transitionDelivery((int)$delivery->id,'accepting','confirmed',$now);$this->repository->closeRequest((int)$delivery->booking_request_id,'teacher_confirmed',$now,$actor);
            if(!$this->repository->notificationForDeliveryKind((int)$delivery->id,'confirmed'))$this->repository->insertNotification(array('uid'=>Identifier::uid(),'delivery_id'=>(int)$delivery->id,'confirmation_id'=>(int)$row->id,'notification_kind'=>'confirmed','state'=>'queued','attempt_count'=>0,'next_attempt_at'=>$now,'lease_token_digest'=>null,'lease_expires_at'=>null,'last_error_code'=>null,'handed_off_at'=>null,'created_at'=>$now,'updated_at'=>$now));
            $this->repository->commit();return array('confirmation_id'=>(int)$row->id,'state'=>'confirmed','idempotent'=>false);
        }catch(\Throwable $error){$this->repository->rollback();throw $error;}
    }

    private function retry(object $observed,string $reason,bool $retryable=true):string{
        $now=gmdate('Y-m-d H:i:s');$actor=(int)get_current_user_id();$this->repository->begin();
        try{
            $state='retryable';
            $row=$this->repository->confirmation((int)$observed->id,true);
            if($row&&in_array((string)$row->state,array('pending_projection','retryable','awaiting_conference'),true)){
                $attempt=(int)$row->attempt_count+1;
                if(!$retryable||$attempt>=self::MAX_ATTEMPTS){
                    // A provider failure is never a Teacher-response failure. Keep the accepted
                    // delivery and request unresolved so the same confirmation can be retried by
                    // an administrator without a second Teacher acceptance or another offer.
                    $state='failed';
                    $this->repository->updateConfirmation((int)$row->id,(int)$row->version,array('state'=>'failed','failure_reason_code'=>$reason,'attempt_count'=>$attempt,'next_attempt_at'=>null,'updated_at'=>$now,'updated_by'=>$actor));
                }else{
                    $delay=min(3600,60*(2**min(5,$attempt)));
                    $this->repository->updateConfirmation((int)$row->id,(int)$row->version,array('state'=>'retryable','failure_reason_code'=>$reason,'attempt_count'=>$attempt,'next_attempt_at'=>gmdate('Y-m-d H:i:s',time()+$delay),'updated_at'=>$now,'updated_by'=>$actor));
                }
            }
            $this->repository->commit();return $state;
        }catch(\Throwable $error){$this->repository->rollback();throw $error;}

    }

    private function retryableCentralFailure(string $code):bool{
        if(!class_exists('Delnavazan\\Platform\\Integrations\\GoogleWorkspace\\MeetProvisioningRule'))return true;
        return \Delnavazan\Platform\Integrations\GoogleWorkspace\MeetProvisioningRule::isRetryableBookingFailure($code);
    }

    /** @return string|false|null null means no database lock is available (offline unit contract). */
    private function acquireLock(int $confirmationId):string|false|null{
        global $wpdb;
        if(!isset($wpdb)||!is_object($wpdb))return null;
        $name='dzn_booking_projection_'.hash('sha256','booking-confirmation:'.$confirmationId);
        return (int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)',$name))===1?$name:false;
    }

    private function releaseLock(?string $name):void{
        global $wpdb;
        if($name!==null&&isset($wpdb)&&is_object($wpdb))$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name));
    }

    private function safeCode(string $message,string $fallback):string{
        $message=trim($message);
        return preg_match('/^[a-z0-9_]{3,64}$/D',$message)===1?$message:$fallback;
    }

    private function rfc3339(string $utc):string{return str_replace(' ','T',substr($utc,0,19)).'Z';}
}
