<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalLessonAuthorityRepository,CanonicalLessonScheduleRepository};

/**
 * Protected, integrity-checked canonical schedule read seam.
 *
 * It reads only canonical scheduling storage and fails closed when the aggregate is corrupt;
 * legacy Phase-1 scheduling rows are never read or mixed here.
 */
final class CanonicalLessonScheduleReadService {
    private const CAPABILITY='dzn_manage_canonical_lesson_schedules';
    public function __construct(private ?CanonicalLessonScheduleRepository $repository=null,private ?CanonicalLessonAuthorityRepository $lessons=null){
        $this->repository??=new CanonicalLessonScheduleRepository();
        $this->lessons??=new CanonicalLessonAuthorityRepository();
    }
    /** @return array{lesson_id:int,state:string,active_version:?object,versions:array,events:array} */
    public function forLesson(int $lessonId):array{
        if(!current_user_can(self::CAPABILITY))throw new \RuntimeException('Unauthorized');
        $lesson=$this->lessons->lesson($lessonId);
        if(!$lesson||(string)($lesson->record_model??'')!=='canonical_term_lesson_v1')throw new \InvalidArgumentException('canonical_lesson_required');
        if(!CanonicalLessonScheduleValidator::validForLesson($lessonId,$this->repository,$this->lessons))throw new \InvalidArgumentException('canonical_schedule_integrity_conflict');
        $versions=$this->repository->versionsForLesson($lessonId);
        $events=$this->repository->eventsForLesson($lessonId);
        $active=null;
        foreach($versions as$version)if((int)($version->applicable_slot??0)===1)$active=$version;
        return array('lesson_id'=>$lessonId,'state'=>$active?'scheduled':'unscheduled','active_version'=>$active,'versions'=>$versions,'events'=>$events);
    }
    public function activeSchedule(int $lessonId):?object{return $this->forLesson($lessonId)['active_version'];}
}
