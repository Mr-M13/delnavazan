<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\{IntegrationSecretService,ProviderReferenceVault};
use Delnavazan\Platform\Core\Infrastructure\Repository\ConfirmedIntroBookingCancellationRepository;

/** Leased retry worker for confirmed-intro provider retraction. It owns no canonical state. */
final class BookingCancellationRetractionWorker {
    public const HOOK='dzn_booking_cancellation_retraction_dispatch';
    private const MAX_ATTEMPTS=6;
    private const LEASE_SECONDS=600;
    public function __construct(
        private ?ConfirmedIntroBookingCancellationRepository $repository=null,
        private ?object $transport=null,
        private ?IntegrationSecretService $credentials=null,
        private ?ProviderReferenceVault $references=null,
        private ?BookingGoogleCredentialRefresher $refresher=null
    ){
        $this->repository??=new ConfirmedIntroBookingCancellationRepository();
        $this->transport??=new GoogleCalendarProjectionTransport();
        $this->credentials??=new IntegrationSecretService();
        $this->references??=new ProviderReferenceVault();
        $this->refresher??=new BookingGoogleCredentialRefresher();
    }
    public static function register():void{add_action(self::HOOK,array(__CLASS__,'run'));add_action('init',array(__CLASS__,'ensureScheduled'));}
    public static function ensureScheduled():void{if(!wp_next_scheduled(self::HOOK))wp_schedule_event(time()+120,'dzn_two_minutes',self::HOOK);}
    public static function run():void{
        $repository=new ConfirmedIntroBookingCancellationRepository();
        foreach($repository->dueRetractionJobs(10)as$job){try{self::dispatch((int)$job->id);}catch(\Throwable $error){do_action('dzn_booking_cancellation_retraction_failed',(int)$job->id,get_class($error));}}
    }

    /** @return array{job_id:int,state:string,retryable:bool} */
    public static function dispatch(int $jobId,?ConfirmedIntroBookingCancellationRepository $repository=null,?object $transport=null):array{
        $worker=new self($repository,$transport);return $worker->execute($jobId);
    }

    private function execute(int $jobId):array{
        $this->repository->begin();
        try{
            $job=$this->repository->retractionJob($jobId,true);
            if(!$job)throw new \InvalidArgumentException('Booking retraction job not found');
            $state=(string)$job->state;
            $stale=$state==='sending'&&$job->lease_expires_at!==null&&strtotime((string)$job->lease_expires_at.' UTC')<=time();
            $due=in_array($state,array('queued','retry'),true)&&($job->next_attempt_at===null||strtotime((string)$job->next_attempt_at.' UTC')<=time());
            if(!$due&&!$stale)throw new \InvalidArgumentException('Booking retraction job is not dispatchable');
            $lease=bin2hex(random_bytes(32));$leaseDigest=hash('sha256',$lease);$attempt=(int)$job->attempt_count+1;$now=gmdate('Y-m-d H:i:s');
            $this->repository->updateRetractionJob($jobId,array('state'=>$state),array('state'=>'sending','attempt_count'=>$attempt,'lease_token_digest'=>$leaseDigest,'lease_expires_at'=>gmdate('Y-m-d H:i:s',time()+self::LEASE_SECONDS),'next_attempt_at'=>null,'last_error_code'=>$stale?'previous_outcome_unknown':null,'updated_at'=>$now));
            $this->repository->commit();
        }catch(\Throwable $error){$this->repository->rollback();throw $error;}
        $failure=null;
        try{
            $secret=$this->repository->projectionSecret((int)$job->confirmation_id);
            if(!$secret){$this->finish($jobId,$leaseDigest,'retracted',null,$now);return array('job_id'=>$jobId,'state'=>'retracted','retryable'=>false);}
            $credential=$this->repository->credential((int)$job->connection_id);
            if(!$credential)throw new \RuntimeException('provider_credential_required');
            if($credential->valid_until_utc!==null&&strtotime((string)$credential->valid_until_utc.' UTC')<=time()+60){
                if(!$this->refresher->refresh((int)$job->connection_id,(int)$job->confirmation_id,$attempt))throw new \RuntimeException('provider_credential_refresh_required');
                $credential=$this->repository->credential((int)$job->connection_id);
                if(!$credential)throw new \RuntimeException('provider_credential_refresh_required');
            }
            $material=$this->credentials->open((int)$job->connection_id,'google_calendar',$credential);
            $references=$this->references->open('booking_calendar',(int)$job->confirmation_id,$secret);
            $eventId=trim((string)($references['provider_object_reference']??''));
            if($eventId==='')throw new \RuntimeException('provider_reference_required');
            $this->transport->cancel($eventId,$material);
        }catch(\Throwable $error){$failure=$error;}
        if($failure===null){$this->finish($jobId,$leaseDigest,'retracted',null,$now);return array('job_id'=>$jobId,'state'=>'retracted','retryable'=>false);}
        $code=$this->safeCode($failure->getMessage());
        $this->repository->begin();
        try{
            $locked=$this->repository->retractionJob($jobId,true);
            if(!$locked||(string)$locked->lease_token_digest!==$leaseDigest)throw new \RuntimeException('Booking retraction lease changed concurrently');
            if((int)$locked->attempt_count>=self::MAX_ATTEMPTS){
                $this->repository->updateRetractionJob($jobId,array('state'=>'sending','lease_token_digest'=>$leaseDigest),array('state'=>'failed','lease_token_digest'=>null,'lease_expires_at'=>null,'next_attempt_at'=>null,'last_error_code'=>$code,'updated_at'=>gmdate('Y-m-d H:i:s')));
                $this->repository->commit();
                return array('job_id'=>$jobId,'state'=>'failed','retryable'=>false);
            }
            $delay=min(3600,60*(2**min(5,(int)$locked->attempt_count)));
            $this->repository->updateRetractionJob($jobId,array('state'=>'sending','lease_token_digest'=>$leaseDigest),array('state'=>'retry','lease_token_digest'=>null,'lease_expires_at'=>null,'next_attempt_at'=>gmdate('Y-m-d H:i:s',time()+$delay),'last_error_code'=>$code,'updated_at'=>gmdate('Y-m-d H:i:s')));
            $this->repository->commit();
            return array('job_id'=>$jobId,'state'=>'retry','retryable'=>true);
        }catch(\Throwable $error){$this->repository->rollback();throw $error;}
    }

    private function finish(int $jobId,string $leaseDigest,string $state,?string $code,string $now):void{
        $this->repository->begin();
        try{
            $locked=$this->repository->retractionJob($jobId,true);
            if(!$locked||(string)$locked->lease_token_digest!==$leaseDigest)throw new \RuntimeException('Booking retraction lease changed concurrently');
            $secret=$this->repository->projectionSecret((int)$locked->confirmation_id);
            if($secret)$this->repository->retireSecret((int)$secret->id,get_current_user_id()?:0,$now);
            $this->repository->updateRetractionJob($jobId,array('state'=>'sending','lease_token_digest'=>$leaseDigest),array('state'=>$state,'lease_token_digest'=>null,'lease_expires_at'=>null,'next_attempt_at'=>null,'last_error_code'=>$code,'completed_at'=>$now,'updated_at'=>$now));
            $this->repository->commit();
        }catch(\Throwable $error){$this->repository->rollback();throw $error;}
    }
    private function safeCode(string $message):string{return in_array($message,array('provider_credential_required','provider_credential_refresh_required','provider_reference_required','google_calendar_cancellation_refused'),true)?$message:'provider_retraction_retryable';}
}
