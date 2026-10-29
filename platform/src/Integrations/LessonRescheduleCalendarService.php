<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\{LessonRescheduleStore,ProviderIntegrationService};
use Delnavazan\Platform\Core\Infrastructure\Repository\{LessonRescheduleRepository,ProviderIntegrationRepository};

/**
 * Recoverable calendar hand-off after a student accepts a reschedule.
 *
 * The canonical schedule has already moved. This service retracts the old provider mapping,
 * records the new schedule-version projections through the existing provider integration
 * authority, and lets the existing projection worker dispatch them. The job itself is idempotent:
 * a retry finds the new pending/verified projection and treats `projection_already_recorded` as
 * convergence, never as a second provider write.
 */
final class LessonRescheduleCalendarService {
    private const MAX_ATTEMPTS=6;
    /** Provider refusals that cannot converge by retrying the same immutable request. */
    private const PERMANENT_ERRORS=array('google_calendar_projection_refused','google_meet_join_uri_untrusted','google_calendar_projection_identity_mismatch','google_calendar_event_id_invalid','google_calendar_request_not_allowed','google_calendar_request_incomplete','reschedule_calendar_binding_invalid');
    /** @var callable(array):array */
    private $dispatch;
    /** @var callable():int */
    private $clock;

    public function __construct(
        private ?LessonRescheduleStore $store=null,
        ?callable $dispatch=null,
        ?callable $clock=null
    ){
        $this->store??=new LessonRescheduleRepository();
        $this->dispatch=$dispatch??static fn(array $job):array=>self::defaultDispatch($job);
        $this->clock=$clock??static fn():int=>time();
    }

    public function dispatchPending(int $limit=10):array{
        $completed=0;$failed=0;$skipped=0;
        foreach($this->store->pendingCalendarJobs($limit) as $row){
            try{
                $state=$this->dispatchJob((int)$row->id);
                if($state==='completed')$completed++;else $skipped++;
            }catch(\Throwable $e){
                $failed++;
            }
        }
        return array('completed'=>$completed,'failed'=>$failed,'skipped'=>$skipped);
    }

    /**
     * Dispatch one exact job. Used by the worker and by an authorised recovery action.
     * The row is re-read under a lock; a completed job is never dispatched twice even when a
     * worker's queue snapshot is stale.
     */
    public function dispatchJob(int $jobId):string{
        // The provider authority owns its own transaction. Wrapping it in a second transaction
        // would make ProviderIntegrationRepository::begin() attempt SET TRANSACTION while a
        // transaction is already open (MySQL refuses), and a nested rollback would be ambiguous.
        // The outbox row is therefore advanced after the provider command commits; the provider
        // command key is deterministic, so a crash between the two converges on retry without a
        // second calendar event.
        $job=$this->store->calendarJob($jobId);
        if(!$job||!in_array((string)$job->state,array('queued','retry'),true))return 'skipped';
        try{
            $result=($this->dispatch)((array)$job);
        }catch(\Throwable $e){
            $this->recordFailure($jobId,$e);
            throw $e;
        }
        $now=gmdate('Y-m-d H:i:s',(int)($this->clock)());
        $this->store->updateCalendarJob($jobId,array('state'=>'completed','attempt_count'=>(int)$job->attempt_count+1,'completed_at'=>$now,'lease_token_digest'=>null,'lease_expires_at'=>null,'last_error_code'=>null,'updated_at'=>$now));
        return 'completed';
    }

    private function recordFailure(int $jobId,\Throwable $e):void{
        try{
            $job=$this->store->calendarJob($jobId);
            if(!$job)return;
            $now=gmdate('Y-m-d H:i:s',(int)($this->clock)());
            $code=self::safeErrorCode((string)$e->getMessage());
            $actorUnavailable=in_array($code,array('reschedule_calendar_actor_unconfigured','reschedule_calendar_actor_invalid'),true);
            $permanent=in_array($code,self::PERMANENT_ERRORS,true);
            $attempt=(int)($job->attempt_count??0)+($actorUnavailable?0:1);
            // Missing or underprivileged actors keep the job queued and retryable; an
            // already accepted canonical reschedule is never rolled back for this.
            // A deterministic provider refusal is terminal, while transport/outage
            // failures use bounded exponential backoff.
            $state=$actorUnavailable?'queued':(($permanent||$attempt>=self::MAX_ATTEMPTS)?'failed':'retry');
            $next=$permanent?null:gmdate('Y-m-d H:i:s',(int)($this->clock)()+($actorUnavailable?300:min(3600,60*(2**min(5,$attempt)))));
            $this->store->updateCalendarJob($jobId,array('state'=>$state,'attempt_count'=>$attempt,'next_attempt_at'=>$next,'last_error_code'=>$code,'updated_at'=>$now));
        }catch(\Throwable){}
    }

    /** Store only controlled codes; never persist raw provider references, tokens or payload text. */
    private static function safeErrorCode(string $message):string{
        $message=trim($message);
        return preg_match('/^[a-z0-9_]{1,64}$/D',$message)===1?$message:'calendar_handoff_failed';
    }

    /** Existing integration authority: retract old references, then record new projections. */
    public static function defaultDispatch(array $job):array{
        return LessonRescheduleCalendarActor::run(static function()use($job):array{
            $lessonId=(int)$job['lesson_id'];$from=(int)$job['from_schedule_version_id'];$to=(int)$job['to_schedule_version_id'];
            if($lessonId<1||$from<1||$to<1)throw new \InvalidArgumentException('reschedule_calendar_binding_invalid');
            $repository=new ProviderIntegrationRepository();
            // The provider authority requires its adapter ports; constructing it without them
            // always fails before any canonical/projection work. Use the same translation-only
            // Google seam as the projection service; the real HTTP write still belongs to the
            // authorised projection worker.
            $adapter=new GoogleCalendarMeetAdapter();
            $authority=new ProviderIntegrationService(null,null,new GoogleOAuthTransport(),$adapter,$adapter);
            $now=gmdate('Y-m-d H:i:s');
            $evidence=array('reason_code'=>'reschedule_accepted','evidence_channel'=>'authenticated_platform','evidence_reference'=>'reschedule-calendar:'.(string)$job['uid'],'evidence_at'=>$now);
            if($old=$repository->activeCalendarMapping($lessonId,$from))$authority->retractCalendarProjection((int)$old->id,$evidence,'reschedule-retract-calendar:'.$job['uid']);
            if($oldMeeting=$repository->activeMeetingMapping($lessonId,$from))$authority->retractMeetingProjection((int)$oldMeeting->id,$evidence,'reschedule-retract-meeting:'.$job['uid']);
            $projection=array('lesson_id'=>$lessonId,'schedule_version_id'=>$to,'projection_reference'=>'reschedule-handoff:'.$job['uid'],'evidence_channel'=>'authenticated_platform','evidence_reference'=>'reschedule-calendar:'.$job['uid'],'evidence_at'=>$now);
            foreach(array('calendar','meeting') as $kind){
                try{
                    if('calendar'===$kind)$authority->projectCalendarEvent($projection,'reschedule-project-calendar:'.$job['uid']);
                    else $authority->projectMeetingConference($projection,'reschedule-project-meeting:'.$job['uid']);
                }catch(\InvalidArgumentException $e){
                    if($e->getMessage()!=='projection_already_recorded')throw $e;
                }
            }
            return array('lesson_id'=>$lessonId,'from_schedule_version_id'=>$from,'to_schedule_version_id'=>$to,'state'=>'handed_off');
        });
    }
}
