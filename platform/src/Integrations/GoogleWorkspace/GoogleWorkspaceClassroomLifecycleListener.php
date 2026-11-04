<?php
namespace Delnavazan\Platform\Integrations\GoogleWorkspace;

/**
 * Bridges canonical schedule/lesson changes to the Meet classroom out of band.
 *
 * The canonical transaction remains the only authority. A provider outage can never reverse or delay
 * a committed schedule, cancellation or completion because provisioning runs in a separate event.
 */
final class GoogleWorkspaceClassroomLifecycleListener {
    public const PROVISION_HOOK='dzn_meet_provision_lesson';
    public const CANCEL_HOOK='dzn_meet_cancel_lesson';

    public static function register():void{
        add_action('dzn_phase_2a2n_after_command_insert',array(__CLASS__,'scheduleCommand'),10,2);
        add_action('dzn_phase_2a2m_after_lesson_transition',array(__CLASS__,'lessonTransition'),10,2);
        add_action(self::PROVISION_HOOK,array(__CLASS__,'provisionLesson'),10,1);
        add_action(self::CANCEL_HOOK,array(__CLASS__,'cancelLesson'),10,1);
    }

    public static function scheduleCommand(string $operation,int $lessonId):void{
        if($lessonId<1)return;
        if(in_array($operation,array('schedule_initial','schedule_revise'),true))self::queue(self::PROVISION_HOOK,$lessonId);
        elseif($operation==='schedule_release')self::queue(self::CANCEL_HOOK,$lessonId);
    }

    public static function lessonTransition(string $operation,int $lessonId):void{
        if($lessonId>0&&'cancel' === $operation)self::queue(self::CANCEL_HOOK,$lessonId);
    }

    public static function provisionLesson(int $lessonId):void{
        if(!GoogleWorkspaceClassroomService::configured())return;
        try{
            $repository=new GoogleWorkspaceRepository();
            $version=$repository->currentScheduleVersion($lessonId);
            if(!$version||(int)$version->applicable_slot!==1||$version->superseded_at!==null){
                (new GoogleWorkspaceClassroomService($repository))->cancelLesson($lessonId);
                return;
            }
            (new GoogleWorkspaceClassroomService($repository))->ensureForLesson($lessonId,(int)$version->id);
        }catch(\Throwable $error){
            do_action('dzn_meet_provisioning_failed',$lessonId,get_class($error));
        }
    }

    public static function cancelLesson(int $lessonId):void{
        try{(new GoogleWorkspaceClassroomService())->cancelLesson($lessonId);}
        catch(\Throwable $error){do_action('dzn_meet_cancellation_failed',$lessonId,get_class($error));}
    }

    private static function queue(string $hook,int $lessonId):void{
        if(!function_exists('wp_schedule_single_event'))return;
        if(function_exists('wp_next_scheduled')&&wp_next_scheduled($hook,array($lessonId)))return;
        wp_schedule_single_event(time()+5,$hook,array($lessonId));
    }
}
