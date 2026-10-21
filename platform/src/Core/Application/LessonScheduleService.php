<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalLessonScheduleRepository,CourseRepository,LessonRepository,LessonScheduleVersionRepository};

/** Legacy Lesson scheduling shares the canonical per-Teacher capacity lock and occupancy read. */
final class LessonScheduleService {
    private const LEGACY_BUFFER_MINUTES = 15;

    public function initial(int $lesson,array $input):int{return $this->apply($lesson,$input,true);}
    public function reschedule(int $lesson,array $input):int{return $this->apply($lesson,$input,false);}
    /**
     * Import the exact occurrence of an introductory Lesson that was already confirmed by a
     * Teacher-owned booking. This is not a scheduling decision: the booking confirmation is the
     * authority, the occurrence is historical, and no Course-default duration is substituted.
     * Replays converge on the identical existing current occurrence.
     */
    public function recordConfirmedBookingOccurrence(int $lessonId,array $input):int{
        if(!current_user_can('dzn_manage_lessons'))throw new \RuntimeException('Unauthorized');
        $lessons=new LessonRepository();$schedules=new LessonScheduleVersionRepository();$capacity=new CanonicalLessonScheduleRepository();
        $bookingRequestId=Normalizer::id($input['booking_request_id']??null);
        $start=(string)($input['starts_at_utc']??'');$end=(string)($input['ends_at_utc']??'');
        if(!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$start)||!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$end)||$end<=$start)throw new \InvalidArgumentException('Confirmed occurrence interval required');
        if($end>gmdate('Y-m-d H:i:s'))throw new \InvalidArgumentException('Confirmed occurrence must have ended');
        $timezone=Normalizer::timezone($input['schedule_timezone']??null);$date=(string)($input['local_wall_date']??'');$time=(string)($input['local_wall_time']??'');
        if(!$timezone)throw new \InvalidArgumentException('Confirmed occurrence timezone required');
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)||!preg_match('/^\d{2}:\d{2}:\d{2}$/D',$time))throw new \InvalidArgumentException('Confirmed occurrence wall clock required');
        $zone=new \DateTimeZone($timezone);$local=(new \DateTimeImmutable($start,new \DateTimeZone('UTC')))->setTimezone($zone);
        if($local->format('Y-m-d')!==$date||$local->format('H:i:s')!==$time)throw new \InvalidArgumentException('Confirmed occurrence wall clock mismatch');
        $capacity->begin();
        try{
            $lesson=$lessons->locked($lessonId);
            if(!$lesson||($lesson->record_model??null)!=='legacy_phase1'||(string)$lesson->lesson_type!=='introductory'||(int)($lesson->booking_request_id??0)!==$bookingRequestId||$lesson->enrolment_id!==null||$lesson->term_id!==null||$lesson->archived_at!==null)throw new \InvalidArgumentException('confirmed_intro_lesson_required');
            $current=$schedules->current($lessonId,true);
            if($current){
                if((string)$current->starts_at_utc!==$start||(string)$current->ends_at_utc!==$end||(string)$current->schedule_timezone!==$timezone||(string)$current->local_wall_date!==$date||(string)$current->local_wall_time!==$time){throw new \InvalidArgumentException('confirmed_occurrence_conflict');}
                $capacity->commit();return(int)$current->id;
            }
            if($lesson->current_schedule_version_id!==null)throw new \RuntimeException('Inconsistent current schedule pointer');
            if(in_array((string)$lesson->status,array('archived','cancelled','completed'),true))throw new \InvalidArgumentException('Lesson not schedulable');
            $now=gmdate('Y-m-d H:i:s');$actor=get_current_user_id()?:0;
            $capacity->ensureAndLockTeacherRoot((int)$lesson->teacher_id,$now,$actor);
            if((new CanonicalTeacherOccupancyReadService($capacity))->overlapping((int)$lesson->teacher_id,$start,$end,$lessonId))throw new \InvalidArgumentException('teacher_slot_conflict');
            CanonicalContinuationCapacityAuthority::assertNoActiveHold((int)$lesson->teacher_id,$start,$end);
            CommercialCapacityAuthority::assertNoConflictingClaim((int)$lesson->teacher_id,$start,$end);
            $versionId=$schedules->insert(array('lesson_id'=>$lessonId,'version_number'=>1,'starts_at_utc'=>$start,'ends_at_utc'=>$end,'schedule_timezone'=>$timezone,'local_wall_date'=>$date,'local_wall_time'=>$time,'reason'=>'confirmed_introductory_booking','changed_by'=>$actor?:null,'created_at'=>$now));
            $lessons->setCurrentSchedule($lessonId,null,$versionId,$now,$actor?:null);
            $capacity->commit();return $versionId;
        }catch(\Throwable$e){$capacity->rollback();throw $e;}
    }

    private function apply(int $id,array $input,bool $initial):int{
        if(!current_user_can('dzn_manage_lessons'))throw new \RuntimeException('Unauthorized');
        $lessons=new LessonRepository();$schedules=new LessonScheduleVersionRepository();$capacity=new CanonicalLessonScheduleRepository();
        $lesson=$lessons->usable($id);
        if(!$lesson||in_array($lesson->status,array('archived','completed','cancelled'),true))throw new \InvalidArgumentException('Lesson not schedulable');
        $current=$schedules->current($id);
        $this->assertPointer($lesson,$current);
        if($initial&&$current)throw new \InvalidArgumentException('Schedule already exists');
        if(!$initial&&!$current)throw new \RuntimeException('Missing current schedule');
        [$start,$end,$timezone,$date,$time]=$this->times($lesson,$input);
        $this->assertChanged($current,$start,$end,$timezone,$date,$time);

        $capacity->begin();
        try{
            $locked=$lessons->locked($id);
            if(!$locked||($locked->record_model??null)!=='legacy_phase1'||$locked->archived_at||in_array($locked->status,array('archived','completed','cancelled'),true))throw new \InvalidArgumentException('Lesson not schedulable');
            $current=$schedules->current($id,true);
            $this->assertPointer($locked,$current);
            if($initial&&$current)throw new \InvalidArgumentException('Schedule already exists');
            if(!$initial&&!$current)throw new \RuntimeException('Missing current schedule');
            [$start,$end,$timezone,$date,$time]=$this->times($locked,$input);
            $this->assertChanged($current,$start,$end,$timezone,$date,$time);

            $now=gmdate('Y-m-d H:i:s');$actor=get_current_user_id()?:0;
            $capacity->ensureAndLockTeacherRoot((int)$locked->teacher_id,$now,$actor);
            $occupiedEnd=(new \DateTimeImmutable($end,new \DateTimeZone('UTC')))->modify('+'.self::LEGACY_BUFFER_MINUTES.' minutes')->format('Y-m-d H:i:s');
            if((new CanonicalTeacherOccupancyReadService($capacity))->overlapping((int)$locked->teacher_id,$start,$occupiedEnd,$id))throw new \InvalidArgumentException('teacher_slot_conflict');
            CanonicalContinuationCapacityAuthority::assertNoActiveHold((int)$locked->teacher_id,$start,$occupiedEnd);
            CommercialCapacityAuthority::assertNoConflictingClaim((int)$locked->teacher_id,$start,$occupiedEnd);

            if($current)$schedules->supersede((int)$current->id,$now);
            $version=$schedules->latest($id)+1;
            $versionId=$schedules->insert(array('lesson_id'=>$id,'version_number'=>$version,'starts_at_utc'=>$start,'ends_at_utc'=>$end,'schedule_timezone'=>$timezone,'local_wall_date'=>$date,'local_wall_time'=>$time,'reason'=>Normalizer::text($input['reason']??null,255),'changed_by'=>$actor?:null,'created_at'=>$now));
            $lessons->setCurrentSchedule($id,$locked->current_schedule_version_id,$versionId,$now,$actor?:null);
            $capacity->commit();
            return $versionId;
        }catch(\Throwable $exception){$capacity->rollback();throw $exception;}
    }

    private function assertPointer(object $lesson,?object $current):void{
        if(($current&&(int)$lesson->current_schedule_version_id!==(int)$current->id)||(!$current&&$lesson->current_schedule_version_id))throw new \RuntimeException('Inconsistent current schedule pointer');
    }
    private function assertChanged(?object $current,string $start,string $end,string $timezone,string $date,string $time):void{
        if($current&&$current->starts_at_utc===$start&&$current->ends_at_utc===$end&&$current->schedule_timezone===$timezone&&$current->local_wall_date===$date&&$current->local_wall_time===$time)throw new \InvalidArgumentException('Schedule unchanged');
    }
    private function times(object $lesson,array $input):array{
        $timezone=Normalizer::timezone($input['schedule_timezone']??null);$date=(string)($input['local_wall_date']??'');$time=Normalizer::time($input['local_wall_time']??null);$zone=new \DateTimeZone($timezone);
        $local=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$date.' '.$time,$zone);
        if(!$local||$local->format('Y-m-d H:i:s')!==$date.' '.$time)throw new \InvalidArgumentException('Invalid or nonexistent local time');
        $offsets=array();foreach($zone->getTransitions($local->getTimestamp()-86400,$local->getTimestamp()+86400)as$transition){$candidate=(new \DateTimeImmutable('@'.(strtotime($date.' '.$time.' UTC')-(int)$transition['offset'])))->setTimezone($zone);if($candidate->format('Y-m-d H:i:s')===$date.' '.$time)$offsets[(int)$transition['offset']]=true;}
        if(count($offsets)>1)throw new \InvalidArgumentException('Ambiguous local time');
        $course=(new CourseRepository())->usable((int)$lesson->course_id);if(!$course)throw new \RuntimeException('Lesson Course unavailable');
        $end=$local->modify('+'.(int)$course->default_duration_minutes.' minutes');if($end<=$local)throw new \InvalidArgumentException('Schedule end must be after start');
        $utc=new \DateTimeZone('UTC');return array($local->setTimezone($utc)->format('Y-m-d H:i:s'),$end->setTimezone($utc)->format('Y-m-d H:i:s'),$timezone,$date,$time);
    }
}
