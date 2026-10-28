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
        $completed=0;$failed=0;
        foreach($this->store->pendingCalendarJobs($limit) as $row){
            $this->store->begin();
            try{
                $locked=$this->store->calendarJob((int)$row->id,true);
                if(!$locked||!in_array((string)$locked->state,array('queued','retry'),true)){$this->store->commit();continue;}
                $result=($this->dispatch)((array)$locked);
                $now=gmdate('Y-m-d H:i:s',(int)($this->clock)());
                $this->store->updateCalendarJob((int)$locked->id,array('state'=>'completed','attempt_count'=>(int)$locked->attempt_count+1,'completed_at'=>$now,'lease_token_digest'=>null,'lease_expires_at'=>null,'last_error_code'=>null,'updated_at'=>$now));
                $this->store->commit();$completed++;
            }catch(\Throwable $e){
                $this->store->rollback();
                try{
                    $now=gmdate('Y-m-d H:i:s',(int)($this->clock)());
                    $code=self::safeErrorCode((string)$e->getMessage());
                    $actorUnavailable=in_array($code,array('reschedule_calendar_actor_unconfigured','reschedule_calendar_actor_invalid'),true);
                    $attempt=(int)($row->attempt_count??0)+($actorUnavailable?0:1);
                    // Missing or underprivileged actors keep the job queued and retryable; an
                    // already accepted canonical reschedule is never rolled back for this.
                    $state=$actorUnavailable?'queued':($attempt>=self::MAX_ATTEMPTS?'failed':'retry');
                    $this->store->updateCalendarJob((int)$row->id,array('state'=>$state,'attempt_count'=>$attempt,'next_attempt_at'=>gmdate('Y-m-d H:i:s',(int)($this->clock)()+($actorUnavailable?300:min(3600,60*(2**min(5,$attempt))))),'last_error_code'=>$code,'updated_at'=>$now));
                }catch(\Throwable){}
                $failed++;
            }
        }
        return array('completed'=>$completed,'failed'=>$failed);
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
            $authority=new ProviderIntegrationService();
            $now=gmdate('Y-m-d H:i:s');
            $evidence=array('reason_code'=>'reschedule_accepted','evidence_channel'=>'authenticated_platform','evidence_reference'=>'reschedule-calendar:'.(string)$job['uid'],'evidence_at'=>$now);
            if($old=$repository->activeCalendarMapping($lessonId,$from))$authority->retractCalendarProjection((int)$old->id,$evidence,'reschedule-retract-calendar:'.$job['uid']);
            if($oldMeeting=$repository->activeMeetingMapping($lessonId,$from))$authority->retractMeetingProjection((int)$oldMeeting->id,$evidence,'reschedule-retract-meeting:'.$job['uid']);
            $projection=array('lesson_id'=>$lessonId,'schedule_version_id'=>$to,'evidence_channel'=>'authenticated_platform','evidence_reference'=>'reschedule-calendar:'.$job['uid'],'evidence_at'=>$now);
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
