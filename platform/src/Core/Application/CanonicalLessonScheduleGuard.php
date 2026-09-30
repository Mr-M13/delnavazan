<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalLessonAuthorityRepository,CanonicalLessonScheduleRepository};

/**
 * Fail-closed cross-phase guard over canonical schedule authority.
 *
 * Every schedule aggregate in scope is validated before the active-future predicate is
 * evaluated, so a corrupted aggregate blocks the consuming authority instead of silently
 * approving or stranding future Teacher time. The guard never mutates and never cascades.
 */
final class CanonicalLessonScheduleGuard {
    public function __construct(private ?CanonicalLessonScheduleRepository $repository=null,private ?CanonicalLessonAuthorityRepository $lessons=null){
        $this->repository??=new CanonicalLessonScheduleRepository();
        $this->lessons??=new CanonicalLessonAuthorityRepository();
    }
    public function activeFutureExists(string $scope,int $id,string $now):bool{return $this->activeFutureCount($scope,$id,$now)>0;}

    /** @param string $scope lesson|enrolment|term|teacher */
    public function activeFutureCount(string $scope,int $id,string $now):int{
        if(!CanonicalLessonScheduleValidator::utc($now))throw new \InvalidArgumentException('Canonical UTC instant required');
        $lessonIds=match($scope){
            'lesson'=>array($id),
            'enrolment'=>$this->repository->lessonIdsByEnrolment($id),
            'term'=>$this->repository->lessonIdsByTerm($id),
            'teacher'=>$this->repository->lessonIdsByTeacher($id),
            default=>throw new \InvalidArgumentException('Unknown canonical schedule scope'),
        };
        $count=0;
        foreach($lessonIds as$lessonId){
            if(!CanonicalLessonScheduleValidator::validForLesson((int)$lessonId,$this->repository,$this->lessons))throw new \InvalidArgumentException('canonical_schedule_integrity_conflict');
            $active=$this->repository->applicableVersion((int)$lessonId);
            if($active&&(string)$active->starts_at_utc>$now)$count++;
        }
        return $count;
    }
}
