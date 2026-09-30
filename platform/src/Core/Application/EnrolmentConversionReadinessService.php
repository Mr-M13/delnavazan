<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\EnrolmentConversionRepository;

/** Capability-protected, write-free conversion readiness. */
final class EnrolmentConversionReadinessService {
    private const CAPABILITY='dzn_convert_service_arrangements_to_enrolments';
    public function __construct(private ?EnrolmentConversionRepository$repository=null){$this->repository??=new EnrolmentConversionRepository();}
    public function assess(int$acceptedServiceArrangementId):string{
        if(!current_user_can(self::CAPABILITY))throw new \RuntimeException('Unauthorized');
        if($acceptedServiceArrangementId<1)throw new \InvalidArgumentException('Accepted Service Arrangement identity required');
        $graph=$this->repository->sourceGraphForRead($acceptedServiceArrangementId);if(!$graph)return'source_integrity_conflict';$a=$graph['arrangement'];
        $rows=$a->course_id===null?array():$this->repository->enrolments((int)$a->student_id,(int)$a->course_id,false);
        $events=$this->repository->lifecycleEvents($rows,false);
        return EnrolmentConversionAssessment::evaluate($this->repository,$graph,$rows,$events,$this->repository->student((int)$a->student_id,false),$a->course_id===null?null:$this->repository->course((int)$a->course_id,false),$this->repository->teacher((int)$a->teacher_id,false))->classification;
    }
}
