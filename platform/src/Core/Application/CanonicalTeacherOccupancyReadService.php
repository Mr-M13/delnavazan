<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalLessonAuthorityRepository,CanonicalLessonScheduleRepository};

/** Validated read seam for committed canonical Teacher occupancy used by booking guidance. */
final class CanonicalTeacherOccupancyReadService {
    public function __construct(private ?CanonicalLessonScheduleRepository $schedules=null,private ?CanonicalLessonAuthorityRepository $lessons=null){
        $this->schedules??=new CanonicalLessonScheduleRepository();
        $this->lessons??=new CanonicalLessonAuthorityRepository();
    }

    /** @return list<array{lesson_id:int,starts_at_utc:string,occupied_ends_at_utc:string}> */
    public function overlapping(int $teacherId,string $startsAtUtc,string $occupiedEndUtc,int $excludeLessonId=0):array{
        if($teacherId<1||!CanonicalLessonScheduleValidator::utc($startsAtUtc)||!CanonicalLessonScheduleValidator::utc($occupiedEndUtc)||$occupiedEndUtc<=$startsAtUtc)throw new \InvalidArgumentException('canonical_occupancy_interval_invalid');
        $result=array();
        // Validate every current fact for this Teacher before trusting the subset selected by
        // interval comparison. Corrupt endpoints must not disappear from a SQL overlap filter.
        foreach($this->schedules->applicableForTeacher($teacherId) as $version){
            $lessonId=(int)($version->lesson_id??0);
            if($lessonId<1||!CanonicalLessonScheduleValidator::validForLesson($lessonId,$this->schedules,$this->lessons))throw new \RuntimeException('canonical_schedule_integrity_conflict');
            $lesson=$this->lessons->lesson($lessonId);
            if(!$lesson||!CanonicalLessonAuthorityValidator::valid($lesson,$this->lessons->events($lessonId),$this->lessons))throw new \RuntimeException('canonical_lesson_integrity_conflict');
            // Applicable schedule facts remain authoritative until an explicit release.
            // Cancellation/completion state alone does not release Teacher capacity.
            if((int)$version->teacher_id!==$teacherId||!CanonicalLessonScheduleValidator::utc($version->starts_at_utc??null)||!CanonicalLessonScheduleValidator::utc($version->occupied_ends_at_utc??null)||$version->occupied_ends_at_utc<=$version->starts_at_utc)throw new \RuntimeException('canonical_schedule_integrity_conflict');
            if($lessonId!==$excludeLessonId&&self::overlaps((string)$version->starts_at_utc,(string)$version->occupied_ends_at_utc,$startsAtUtc,$occupiedEndUtc))$result[]=array('lesson_id'=>$lessonId,'starts_at_utc'=>(string)$version->starts_at_utc,'occupied_ends_at_utc'=>(string)$version->occupied_ends_at_utc);
        }
        foreach($this->schedules->legacyApplicableForTeacher($teacherId) as $version){
            $lessonId=(int)($version->lesson_id??0);
            if($lessonId<1||(int)($version->teacher_id??0)!==$teacherId||!CanonicalLessonScheduleValidator::utc($version->starts_at_utc??null)||!CanonicalLessonScheduleValidator::utc($version->ends_at_utc??null)||!CanonicalLessonScheduleValidator::utc($version->occupied_ends_at_utc??null)||$version->ends_at_utc<=$version->starts_at_utc||$version->occupied_ends_at_utc<=$version->ends_at_utc)throw new \RuntimeException('legacy_schedule_integrity_conflict');
            if($lessonId!==$excludeLessonId&&self::overlaps((string)$version->starts_at_utc,(string)$version->occupied_ends_at_utc,$startsAtUtc,$occupiedEndUtc))$result[]=array('lesson_id'=>$lessonId,'starts_at_utc'=>(string)$version->starts_at_utc,'occupied_ends_at_utc'=>(string)$version->occupied_ends_at_utc);
        }
        usort($result,static fn(array $a,array $b):int=>($a['starts_at_utc']<=>$b['starts_at_utc'])?:($a['lesson_id']<=>$b['lesson_id']));
        return $result;
    }

    /** Canonical UTC half-open interval collision; presentation timezone is intentionally absent. */
    public static function overlaps(string $existingStart,string $existingOccupiedEnd,string $candidateStart,string $candidateOccupiedEnd):bool{
        foreach(array($existingStart,$existingOccupiedEnd,$candidateStart,$candidateOccupiedEnd) as $instant)if(!CanonicalLessonScheduleValidator::utc($instant))throw new \InvalidArgumentException('canonical_occupancy_interval_invalid');
        if($existingOccupiedEnd<=$existingStart||$candidateOccupiedEnd<=$candidateStart)throw new \InvalidArgumentException('canonical_occupancy_interval_invalid');
        return $existingStart<$candidateOccupiedEnd&&$existingOccupiedEnd>$candidateStart;
    }
}
