<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherBookingOccupancyQuery;

/**
 * Capacity authority for introductory-booking workflows.
 *
 * Canonical lessons and legacy schedules remain authoritative, while offers and
 * confirmations add the pre-lesson portion of a Teacher's capacity. Keeping
 * this seam booking-specific prevents an intro confirmation from being treated
 * as a conflicting future canonical lesson while continuation setup is running.
 */
final class BookingTeacherOccupancyReadService {
    public function __construct(private ?CanonicalTeacherOccupancyReadService $canonical=null){
        $this->canonical??=new CanonicalTeacherOccupancyReadService();
    }

    /** @return list<array{lesson_id:int,starts_at_utc:string,occupied_ends_at_utc:string}> */
    public function overlapping(int $teacherId,string $startsAtUtc,string $occupiedEndUtc,int $excludeLessonId=0,int $excludeBookingRequestId=0,bool $includePendingOffers=true):array{
        $result=$this->canonical->overlapping($teacherId,$startsAtUtc,$occupiedEndUtc,$excludeLessonId);
        foreach(TeacherBookingOccupancyQuery::conflicts($teacherId,$startsAtUtc,$occupiedEndUtc,$excludeBookingRequestId,$includePendingOffers) as $conflict)$result[]=array('lesson_id'=>(int)$conflict['conflict_id'],'starts_at_utc'=>$conflict['starts_at_utc'],'occupied_ends_at_utc'=>$conflict['occupied_ends_at_utc']);
        usort($result,static fn(array $a,array $b):int=>($a['starts_at_utc']<=>$b['starts_at_utc'])?:($a['lesson_id']<=>$b['lesson_id']));
        return $result;
    }
}
