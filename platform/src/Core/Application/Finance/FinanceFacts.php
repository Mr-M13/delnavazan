<?php
namespace Delnavazan\Platform\Core\Application\Finance;

use Delnavazan\Platform\Core\Application\CanonicalAcademyObligationValidator;
use Delnavazan\Platform\Core\Application\CanonicalLessonAuthorityValidator;
use Delnavazan\Platform\Core\Application\CanonicalLessonDeliveryValidator;
use Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalAcademyObligationRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalLessonAuthorityRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalLessonDeliveryRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalLessonScheduleRepository;

/**
 * Read-only hydration of the canonical facts a Finance row is derived from (contract §16.1).
 *
 * Phase U owns no upstream row. Every Finance derivation reads the canonical Lesson, schedule, delivery
 * outcome and academy obligation through their owning validators and fails closed when one is missing,
 * ambiguous, contradictory or corrupt — it never fills the gap with a default, an approximation, a
 * legacy flag or a provider value. `introductory` is decided from the canonical Lesson kind (a
 * legacy-classified introductory Lesson with no Term), never from an Amelia service category id.
 */
final class FinanceFacts {
    private const LESSON_MODEL='canonical_term_lesson_v1';
    private const INTRO_MODEL='legacy_phase1';
    private const INTRO_TYPE='introductory';
    private const CANONICAL_TYPES=array('standard','replacement');
    private const CANONICAL_STATES=array('authorised','completed','cancelled');
    private const LEGACY_STATES=array('draft','scheduled','cancelled','completed');
    private const FINAL_STATES=array('completed','cancelled');

    private CanonicalLessonAuthorityRepository $lessons;
    private CanonicalLessonDeliveryRepository $delivery;
    private CanonicalAcademyObligationRepository $obligations;
    private CanonicalLessonScheduleRepository $schedules;

    public function __construct(
        ?CanonicalLessonAuthorityRepository $lessons=null,
        ?CanonicalLessonDeliveryRepository $delivery=null,
        ?CanonicalAcademyObligationRepository $obligations=null,
        ?CanonicalLessonScheduleRepository $schedules=null
    ){
        $this->lessons??=new CanonicalLessonAuthorityRepository();
        $this->delivery??=new CanonicalLessonDeliveryRepository();
        $this->obligations??=new CanonicalAcademyObligationRepository();
        $this->schedules??=new CanonicalLessonScheduleRepository();
    }

    /**
     * The validated Finance context of one canonical Lesson.
     *
     * @return array{lesson:object,kind:string,state:string,finalised:bool,archived:bool,teacher_id:int,enrolment_id:?int,term_id:?int,course_id:int,teacher_assignment_id:int,schedule_version_id:int,occurrence_starts_at_utc:string,occurrence_ends_at_utc:string,duration_minutes:int,outcome:?object,obligation:?object}
     * @throws \RuntimeException `finance_lesson_kind_not_allowed`, `occurrence_anchor_missing` or `upstream_aggregate_invalid`
     */
    public function context(int $lessonId,bool $lock=false):array{
        $lessonId=FinanceSupport::positiveInt($lessonId,'Valid canonical Lesson required');
        $lesson=$this->lessons->lesson($lessonId,$lock);
        if(!$lesson)throw new \RuntimeException('finance_parent_not_live');
        $kind=(string)($lesson->lesson_type??'');
        if($kind===self::INTRO_TYPE&&(string)($lesson->record_model??'')===$self::INTRO_MODEL&&$lesson->term_id===null){
            return $this->introContext($lesson,$lock);
        }
        if(!in_array($kind,self::CANONICAL_TYPES,true))throw new \RuntimeException('finance_lesson_kind_not_allowed');
        if((string)($lesson->record_model??'')!==self::LESSON_MODEL||!in_array((string)($lesson->lifecycle_state??''),self::CANONICAL_STATES,true))throw new \RuntimeException('upstream_aggregate_invalid');
        $events=$this->lessons->events($lessonId,$lock);
        if(!CanonicalLessonAuthorityValidator::valid($lesson,$events,$this->lessons,$lock))throw new \RuntimeException('upstream_aggregate_invalid');
        $state=(string)$lesson->lifecycle_state;
        $outcomes=$this->delivery->outcomesForLesson($lessonId,$lock);
        $outcome=CanonicalLessonDeliveryValidator::effective($outcomes);
        $obligationRows=$this->obligations->forLesson($lessonId,$lock);
        $obligation=null;
        foreach($obligationRows as $row)if((string)($row->state??'')==='owed'){$obligation=$row;break;}
        if(!CanonicalAcademyObligationValidator::valid($lesson,$obligationRows,$outcomes,$events,$this->lessons))throw new \RuntimeException('upstream_aggregate_invalid');
        $scheduleVersionId=0;$starts=null;$ends=null;$duration=0;
        if($outcome){
            $scheduleVersionId=(int)$outcome->schedule_version_id;
            $starts=(string)$outcome->occurrence_starts_at_utc;$ends=(string)$outcome->occurrence_ends_at_utc;
        }
        $applicable=null;
        foreach($this->schedules->versionsForLesson($lessonId,$lock) as $version)if((int)($version->applicable_slot??0)===1)$applicable=$version;
        if($scheduleVersionId<1){
            if(!$applicable)throw new \RuntimeException('occurrence_anchor_missing');
            $scheduleVersionId=(int)$applicable->id;$starts=(string)$applicable->starts_at_utc;$ends=(string)$applicable->ends_at_utc;
        }
        if($starts===null||$ends===null||!FinanceRule::utc($starts)||!FinanceRule::utc($ends))throw new \RuntimeException('occurrence_anchor_missing');
        if($outcome){$duration=max(1,(int)round((strtotime($ends.' UTC')-strtotime($starts.' UTC'))/60));}
        else{$duration=(int)($applicable->duration_minutes??0);if($duration<1)$duration=max(1,(int)round((strtotime($ends.' UTC')-strtotime($starts.' UTC'))/60));}
        $teacherId=(int)$lesson->teacher_id;
        if($outcome&&(int)$outcome->teacher_id!==$teacherId)throw new \RuntimeException('upstream_aggregate_invalid');
        if($outcome&&(int)$outcome->teacher_assignment_id!==(int)$lesson->teacher_assignment_id)throw new \RuntimeException('upstream_aggregate_invalid');
        return array(
            'lesson'=>$lesson,'kind'=>$kind,'state'=>$state,'finalised'=>in_array($state,self::FINAL_STATES,true),
            'archived'=>$lesson->archived_at!==null||$state==='archived',
            'teacher_id'=>$teacherId,'enrolment_id'=>(int)$lesson->enrolment_id,'term_id'=>(int)$lesson->term_id,
            'course_id'=>(int)$lesson->course_id,'teacher_assignment_id'=>(int)$lesson->teacher_assignment_id,
            'schedule_version_id'=>$scheduleVersionId,'occurrence_starts_at_utc'=>(string)$starts,
            'occurrence_ends_at_utc'=>(string)$ends,'duration_minutes'=>$duration,
            'outcome'=>$outcome,'obligation'=>$obligation,
        );
    }

    /** A legacy-classified introductory Lesson: no Term, a legacy lifecycle status and a current occurrence. */
    private function introContext(object $lesson,bool $lock):array{
        if(!in_array((string)($lesson->status??''),self::LEGACY_STATES,true))throw new \RuntimeException('upstream_aggregate_invalid');
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $occurrence=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}lesson_schedule_versions WHERE lesson_id=%d AND id=(SELECT current_schedule_version_id FROM {$p}lessons WHERE id=%d) AND superseded_at IS NULL".($lock?' FOR UPDATE':''),(int)$lesson->id,(int)$lesson->id));
        if(!$occurrence)throw new \RuntimeException('occurrence_anchor_missing');
        $state=(string)$lesson->status;
        return array(
            'lesson'=>$lesson,'kind'=>self::INTRO_TYPE,'state'=>$state,'finalised'=>in_array($state,self::FINAL_STATES,true),
            'archived'=>$lesson->archived_at!==null,'teacher_id'=>(int)$lesson->teacher_id,
            'enrolment_id'=>null,'term_id'=>null,'course_id'=>(int)$lesson->course_id,'teacher_assignment_id'=>0,
            'schedule_version_id'=>(int)$occurrence->id,'occurrence_starts_at_utc'=>(string)$occurrence->starts_at_utc,
            'occurrence_ends_at_utc'=>(string)$occurrence->ends_at_utc,
            'duration_minutes'=>max(1,(int)round((strtotime((string)$occurrence->ends_at_utc.' UTC')-strtotime((string)$occurrence->starts_at_utc.' UTC'))/60)),
            'outcome'=>null,'obligation'=>null,
        );
    }

    /** The live canonical Teacher, fail closed. */
    public function teacher(int $teacherId,bool $lock=false):object{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}teachers WHERE id=%d".($lock?' FOR UPDATE':''),$teacherId));
        if(!$row||$row->archived_at!==null||(string)$row->status==='archived')throw new \RuntimeException('finance_parent_not_live');
        return $row;
    }
    /** The live canonical Course, fail closed. */
    public function course(int $courseId):object{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}courses WHERE id=%d",$courseId));
        if(!$row||$row->archived_at!==null)throw new \RuntimeException('finance_parent_not_live');
        return $row;
    }
    /** The Lesson row itself, or `null` when no such canonical Lesson exists. */
    public function lesson(int $lessonId):?object{return $this->lessons->lesson($lessonId);}
}
