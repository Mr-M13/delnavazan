<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\CanonicalLessonAuthorityService;
use Delnavazan\Platform\Core\Application\PostIntroSystemActor;
use Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalLessonAuthorityRepository;

/**
 * Interim completion automation.
 *
 * This worker evaluates canonical Lessons whose latest canonical UTC occurrence has ended and asks
 * the canonical Lesson authority to record a *presumed* completion. It never records verified
 * attendance, never completes a future/running occurrence, and never overrides a reported issue or
 * any existing delivery outcome. Repeats are idempotent because the command key and evidence are
 * derived from the Lesson UID and its exact schedule version.
 */
final class LessonCompletionAutomationWorker {
    public const HOOK='dzn_lesson_completion_automation_dispatch';
    private const BATCH=25;
    private const SKIP_CODES=array(
        'stale_lesson_state',
        'occurrence_not_started',
        'occurrence_not_ended',
        'schedule_required_for_completion_presumption',
        'occurrence_not_scheduled',
        'delivery_outcome_exists',
        'lesson_not_delivered',
        'lesson_delivery_review_required',
        'attendance_issue_unresolved',
        'attendance_conflict_unresolved',
        'canonical_lesson_integrity_conflict',
        'canonical_schedule_integrity_conflict',
        'canonical_delivery_integrity_conflict',
        'canonical_enrolment_required',
        'enrolment_not_lesson_terminalisable',
        'term_not_current',
    );

    public static function register():void{
        add_filter('cron_schedules',static function(array $schedules):array{
            $schedules['dzn_five_minutes']??=array('interval'=>300,'display'=>'Delnavazan every five minutes');
            return $schedules;
        });
        add_action(self::HOOK,array(__CLASS__,'run'));
        add_action('init',array(__CLASS__,'ensureScheduled'));
    }

    public static function ensureScheduled():void{
        if(!wp_next_scheduled(self::HOOK))wp_schedule_event(time()+120,'dzn_five_minutes',self::HOOK);
    }

    /**
     * @return array{examined:int,presumed:int,skipped:int,errors:int}
     */
    public static function run():array{
        $now=gmdate('Y-m-d H:i:s');
        $repository=new CanonicalLessonAuthorityRepository();
        $ids=$repository->endedAuthorisedLessonIds($now,self::BATCH);
        $result=array('examined'=>count($ids),'presumed'=>0,'skipped'=>0,'errors'=>0);
        foreach($ids as $lessonId){
            try{
                $outcome=self::runLesson($lessonId,$now);
                if(($outcome['state']??'')==='presumed')$result['presumed']++;
                else $result['skipped']++;
            }catch(\Throwable $error){
                $result['errors']++;
                do_action('dzn_lesson_completion_automation_failed',$lessonId,get_class($error),(string)$error->getMessage());
            }
        }
        return $result;
    }

    /**
     * Evaluate one Lesson. The guard inside canonical Lesson authority performs the authoritative
     * re-check under its own locks; this method only prepares replay-stable evidence.
     *
     * @return array{lesson_id:int,state:string,result?:array}
     */
    public static function runLesson(int $lessonId,?string $now=null):array{
        $now??=gmdate('Y-m-d H:i:s');
        $repository=new CanonicalLessonAuthorityRepository();
        $lesson=$repository->lesson($lessonId);
        if(!$lesson||(string)($lesson->record_model??'')!=='canonical_term_lesson_v1')throw new \InvalidArgumentException('canonical_lesson_required');
        $versions=(new \Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalLessonScheduleRepository())->versionsForLesson($lessonId);
        if(!$versions)throw new \InvalidArgumentException('schedule_required_for_completion_presumption');
        $version=$versions[count($versions)-1];
        if((string)$version->ends_at_utc>$now)throw new \InvalidArgumentException('occurrence_not_ended');
        $reference='presumed-completion:'.$lesson->uid.':'.$version->id;
        $evidence=array(
            'evidence_channel'=>'authenticated_platform',
            'evidence_reference'=>$reference,
            // The canonical occurrence end is replay-stable and is the instant at which silence
            // became meaningful. It is never the wall clock of an individual worker attempt.
            'evidence_at'=>(string)$version->ends_at_utc,
        );
        $key='lesson-completion-presumption:'.$lesson->uid.':'.$version->id;
        return PostIntroSystemActor::run(static function()use($lessonId,$evidence,$key):array{
            try{
                $result=(new CanonicalLessonAuthorityService())->presume($lessonId,'authorised',$evidence,$key);
                return array('lesson_id'=>$lessonId,'state'=>'presumed','result'=>$result);
            }catch(\InvalidArgumentException $exception){
                if(in_array($exception->getMessage(),self::SKIP_CODES,true))return array('lesson_id'=>$lessonId,'state'=>'skipped','reason'=>$exception->getMessage());
                throw $exception;
            }
        });
    }

    public static function skipCodes():array{return self::SKIP_CODES;}
}
