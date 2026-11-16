<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\LessonLifecycleNotificationService;

/**
 * Lesson reminder scheduler.
 *
 * Finds canonical lessons whose schedule starts inside the reminder window and produces the Teacher
 * reminder and the enrolled Student reminder. Idempotency lives in the producer: the portal inbox row
 * is the durable per-lesson + audience record, so a repeated worker run never duplicates a reminder and
 * the two audiences never double-notify the same recipient. The worker never changes a lesson, schedule,
 * obligation or delivery decision.
 */
final class LessonReminderWorker {
    public const HOOK='dzn_lesson_reminder_dispatch';
    private const BATCH=50;
    private const LEAD_SECONDS=86400; // remind inside the 24 hours before the class starts

    public static function register():void{
        add_action(self::HOOK,array(__CLASS__,'run'));
        add_action('init',array(__CLASS__,'ensureScheduled'));
    }

    public static function ensureScheduled():void{
        if(!self::schedulingEnabled())return;
        if(!wp_next_scheduled(self::HOOK))wp_schedule_event(time()+300,'dzn_two_minutes',self::HOOK);
    }

    /** @return array{examined:int,queued:int,skipped:int,errors:int} */
    public static function run():array{
        $result=array('examined'=>0,'queued'=>0,'skipped'=>0,'errors'=>0);
        global $wpdb;
        if(!is_object($wpdb))return $result;
        $p=$wpdb->prefix.'dzn_';
        $now=gmdate('Y-m-d H:i:s');
        $upper=gmdate('Y-m-d H:i:s',time()+self::LEAD_SECONDS);
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT l.id lesson_id
               FROM {$p}lessons l
               INNER JOIN {$p}canonical_lesson_schedule_versions v ON v.lesson_id=l.id AND v.applicable_slot=1
              WHERE l.record_model='canonical_term_lesson_v1'
                AND l.lifecycle_state='authorised'
                AND l.archived_at IS NULL
                AND l.activity_type<>'test'
                AND v.starts_at_utc>%s AND v.starts_at_utc<=%s
              ORDER BY l.id ASC
              LIMIT %d",
            $now,$upper,self::BATCH
        ));
        if(!is_array($rows))return $result;
        $service=new LessonLifecycleNotificationService();
        foreach($rows as $row){
            $result['examined']++;
            $lessonId=(int)$row->lesson_id;
            try{
                $teacher=$service->notifyReminder($lessonId);
                $student=$service->notifyStudentReminder($lessonId);
                if(!empty($teacher['produced'])||!empty($student['produced']))$result['queued']++;
                else $result['skipped']++;
            }catch(\Throwable $error){
                $result['errors']++;
                do_action('dzn_lesson_reminder_failed',$lessonId,get_class($error),(string)$error->getMessage());
            }
        }
        return $result;
    }

    private static function schedulingEnabled():bool{
        if(!function_exists('wp_get_environment_type')||!function_exists('apply_filters'))return false;
        $default=in_array(wp_get_environment_type(),array('staging','production'),true);
        return (bool)apply_filters('dzn_lesson_reminder_scheduling_enabled',$default);
    }
}
