<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\ProviderIntegrationService;
use Delnavazan\Platform\Core\Infrastructure\Repository\ProviderIntegrationRepository;

/**
 * Keeps the Google Calendar/Meet projection bound to the applicable schedule version.
 *
 * A canonical schedule revision is the authority for the class instant. The provider projection
 * must follow that revision, otherwise the portal has the right class time but no verified join
 * reference, so the LiveKit fallback and the explicit Google Meet action both disappear. The work
 * is queued out of band so a provider outage can never roll back the committed schedule.
 */
final class LessonScheduleCalendarResync {
    public const HOOK='dzn_lesson_schedule_calendar_resync';

    public static function register():void{
        add_action('dzn_phase_2a2n_after_command_insert',array(__CLASS__,'schedule'),10,2);
        add_action(self::HOOK,array(__CLASS__,'run'),10,1);
    }

    public static function schedule(string $operation,int $lessonId):void{
        if($lessonId<1||!in_array($operation,array('schedule_initial','schedule_revise'),true))return;
        if(!function_exists('wp_schedule_single_event'))return;
        if(function_exists('wp_next_scheduled')&&wp_next_scheduled(self::HOOK,array($lessonId)))return;
        wp_schedule_single_event(time()+2,self::HOOK,array($lessonId));
    }

    /** Rebind the active provider projection after the canonical schedule has committed. */
    public static function run(int $lessonId):void{
        if($lessonId<1)return;
        global $wpdb;
        $p=$wpdb->prefix.'dzn_';
        $current=$wpdb->get_row($wpdb->prepare(
            "SELECT id,uid,starts_at_utc,ends_at_utc FROM {$p}canonical_lesson_schedule_versions WHERE lesson_id=%d AND applicable_slot=1 AND superseded_at IS NULL ORDER BY id DESC LIMIT 1",
            $lessonId
        ));
        if(!$current)return;
        $repository=new ProviderIntegrationRepository();
        if($repository->activeCalendarMapping($lessonId,(int)$current->id)||$repository->pendingCalendarMapping($lessonId,(int)$current->id))return;
        try{
            LessonRescheduleCalendarActor::run(static function()use($lessonId,$current):void{
                $repository=new ProviderIntegrationRepository();
                $authority=new ProviderIntegrationService(null,null,new GoogleOAuthTransport(),new GoogleCalendarMeetAdapter(),new GoogleCalendarMeetAdapter());
                $now=gmdate('Y-m-d H:i:s');
                $evidence=array(
                    'reason_code'=>'schedule_revised',
                    'evidence_channel'=>'authenticated_platform',
                    'evidence_reference'=>'schedule-resync:'.$lessonId.':'.(int)$current->id,
                    'evidence_at'=>$now,
                );
                foreach($repository->calendarMappings($lessonId)as$mapping){
                    if((int)$mapping->schedule_version_id===(int)$current->id)continue;
                    if((string)$mapping->projection_state!=='pending'&&(string)$mapping->projection_state!=='verified')continue;
                    if((int)($mapping->active_slot??0)!==1&&(string)$mapping->projection_state!=='pending')continue;
                    try{
                        $authority->retractCalendarProjection((int)$mapping->id,$evidence,'schedule-resync-retract-calendar:'.$lessonId.':'.(int)$mapping->id);
                    }catch(\InvalidArgumentException $error){
                        if($error->getMessage()!=='projection_already_recorded')throw $error;
                    }
                }
                foreach($repository->meetingMappings($lessonId)as$mapping){
                    if((int)$mapping->schedule_version_id===(int)$current->id)continue;
                    if((string)$mapping->projection_state!=='pending'&&(string)$mapping->projection_state!=='verified')continue;
                    if((int)($mapping->active_slot??0)!==1&&(string)$mapping->projection_state!=='pending')continue;
                    try{
                        $authority->retractMeetingProjection((int)$mapping->id,$evidence,'schedule-resync-retract-meeting:'.$lessonId.':'.(int)$mapping->id);
                    }catch(\InvalidArgumentException $error){
                        if($error->getMessage()!=='projection_already_recorded')throw $error;
                    }
                }
                $result=$authority->projectCalendarEvent(array(
                    'lesson_id'=>$lessonId,
                    'schedule_version_id'=>(int)$current->id,
                    'projection_reference'=>'schedule-resync:'.$lessonId.':'.(int)$current->id,
                    'evidence_channel'=>'authenticated_platform',
                    'evidence_reference'=>'schedule-resync:'.$lessonId.':'.(int)$current->id,
                    'evidence_at'=>$now,
                ),'schedule-resync-project-calendar:'.$lessonId.':'.(int)$current->id);
                $mappingId=(int)($result['mapping_id']??0);
                if($mappingId<1)return;
                $mapping=$repository->calendarMapping($mappingId);
                if($mapping&&(string)$mapping->projection_state==='pending'){
                    // The projection worker normally dispatches this later. Dispatching here keeps
                    // the portal buttons available immediately after an operator changes the time.
                    (new GoogleCalendarProjectionService())->dispatch($mappingId,'schedule-resync-dispatch:'.$lessonId.':'.(int)$current->id);
                }
            });
        }catch(\Throwable $error){
            if(function_exists('do_action'))do_action('dzn_lesson_schedule_calendar_resync_failed',$lessonId,get_class($error));
        }
    }
}
