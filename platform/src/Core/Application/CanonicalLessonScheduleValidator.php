<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalLessonAuthorityRepository,CanonicalLessonScheduleRepository};

/**
 * Single canonical hydration and integrity gate for a canonical Lesson's schedule aggregate.
 *
 * The aggregate is an append-only version history plus an append-only event chain:
 * `scheduled` opens an authority period, `rescheduled` continues it and `released` closes it.
 * Every version is introduced by exactly one scheduled/rescheduled event, and the version of
 * the currently open period is the single applicable version.
 *
 * A historical version must stay valid after its Teacher Assignment was replaced, so the
 * recorded Assignment is validated as structurally belonging to the Lesson enrolment with the
 * recorded immutable Teacher; it is never required to remain the applicable Assignment.
 *
 * A canonical Lesson must never carry a legacy scheduling projection.
 */
final class CanonicalLessonScheduleValidator {
    private const MODEL='canonical_term_lesson_v1';
    private const EVENT_TYPES=array('scheduled','rescheduled','released');
    private const DURATION_SOURCES=array('course_default','override');
    private const AVAILABILITY_BASES=array('within_availability','administrative_override');
    private const CHANNELS=array('staff_record','authenticated_platform','document_reference');

    /** Hydrate and validate the complete schedule aggregate of one canonical Lesson. */
    public static function validForLesson(int $lessonId,?CanonicalLessonScheduleRepository $repository=null,?CanonicalLessonAuthorityRepository $lessons=null,bool $lock=false):bool{
        $repository??=new CanonicalLessonScheduleRepository();
        $lessons??=new CanonicalLessonAuthorityRepository();
        $lesson=$lessons->lesson($lessonId,$lock);
        if(!$lesson)return false;
        $versions=$repository->versionsForLesson($lessonId,$lock);
        $events=$repository->eventsForLesson($lessonId,$lock);
        $latest=$versions?$versions[count($versions)-1]:null;
        $assignment=$latest?$lessons->assignmentById((int)$latest->teacher_assignment_id,$lock):null;
        return self::valid($lesson,$versions,$events,$assignment);
    }

    /** Pure aggregate validation over hydrated rows. */
    public static function valid(object $lesson,array $versions,array $events,?object $assignment):bool{
        $lessonId=(int)($lesson->id??0);
        if($lessonId<1||(string)($lesson->record_model??'')!==self::MODEL)return false;
        if(($lesson->current_schedule_version_id??null)!==null||($lesson->archived_at??null)!==null||($lesson->replacement_for_lesson_id??null)!==null)return false;
        if(!$versions)return $events===array();
        if(!$events)return false;
        $count=count($versions);
        foreach($versions as$index=>$version){
            if((int)($version->version_number??0)!==$index+1||(int)($version->lesson_id??0)!==$lessonId)return false;
            if((int)($version->enrolment_id??0)!==(int)($lesson->enrolment_id??0)||(int)($version->term_id??0)!==(int)($lesson->term_id??0))return false;
            if((int)($version->teacher_assignment_id??0)!==(int)($lesson->teacher_assignment_id??0)||(int)($version->teacher_id??0)!==(int)($lesson->teacher_id??0))return false;
            if(!self::interval($version))return false;
            $slot=(int)($version->applicable_slot??0);
            if($slot===1){if(($version->superseded_at??null)!==null)return false;}
            elseif($slot===0){if(($version->superseded_at??null)===null)return false;}
            else return false;
        }
        // Event chain: scheduled opens a period, rescheduled continues, released closes.
        $introducing=array();$closedAfter=array();$open=false;$openVersionIndex=-1;$previous=null;
        foreach($events as$index=>$event){
            if((int)($event->event_sequence??0)!==$index+1||(int)($event->lesson_id??0)!==$lessonId)return false;
            $type=(string)($event->event_type??'');
            if(!in_array($type,self::EVENT_TYPES,true))return false;
            if(!self::evidenceShape($event->reason_code??null,$event->evidence_channel??null,$event->evidence_reference_digest??null,$event->recorded_at??null))return false;
            $from=$event->from_schedule_version_id===null?null:(int)$event->from_schedule_version_id;
            $to=$event->to_schedule_version_id===null?null:(int)$event->to_schedule_version_id;
            if($type==='scheduled'){
                if($index!==0&&(!$previous||(string)$previous->event_type!=='released'))return false;
                if($from!==null)return false;
                $openVersionIndex++;
                if($to===null||$openVersionIndex>=count($versions)||$to!==(int)$versions[$openVersionIndex]->id)return false;
                $introducing[$openVersionIndex]=$event;$open=true;
            }elseif($type==='rescheduled'){
                if(!$open||$openVersionIndex<0)return false;
                if($from!==(int)$versions[$openVersionIndex]->id)return false;
                $openVersionIndex++;
                if($to===null||$openVersionIndex>=count($versions)||$to!==(int)$versions[$openVersionIndex]->id)return false;
                $introducing[$openVersionIndex]=$event;
            }else{
                if(!$open||$openVersionIndex<0)return false;
                if($from!==(int)$versions[$openVersionIndex]->id||$to!==null)return false;
                $closedAfter[$openVersionIndex]=true;$open=false;
            }
            $previous=$event;
        }
        if($openVersionIndex+1!==$count||count($introducing)!==$count)return false;
        $applicable=0;$last=$versions[$count-1];
        foreach($versions as$version)$applicable+=(int)($version->applicable_slot??0)===1?1:0;
        if($applicable>1)return false;
        if($open){
            if($applicable!==1||(int)($last->applicable_slot??0)!==1||($last->superseded_by_version_id??null)!==null)return false;
        }else{
            if($applicable!==0||($last->superseded_by_version_id??null)!==null)return false;
        }
        // Supersession lineage: the successor is the next version unless the period was released.
        for($i=0;$i<$count-1;$i++){
            $expected=empty($closedAfter[$i])?(int)$versions[$i+1]->id:null;
            $stored=$versions[$i]->superseded_by_version_id===null?null:(int)$versions[$i]->superseded_by_version_id;
            if($stored!==$expected)return false;
        }
        // Durable binding: the introducing event is the version's recorded evidence.
        for($i=0;$i<$count;$i++){
            $event=$introducing[$i];$version=$versions[$i];
            if((string)$event->recorded_at!==(string)$version->created_at)return false;
            if((string)$event->reason_code!==(string)$version->reason_code)return false;
            if((string)$event->evidence_channel!==(string)$version->evidence_channel)return false;
            if(!hash_equals((string)$event->evidence_reference_digest,(string)$version->evidence_reference_digest))return false;
        }
        // Historical Assignment provenance stays structurally valid; it need not be current.
        if(!$assignment)return false;
        if((int)($assignment->id??0)!==(int)$last->teacher_assignment_id)return false;
        if((int)($assignment->enrolment_id??0)!==(int)($lesson->enrolment_id??0))return false;
        if((int)($assignment->teacher_id??0)!==(int)($lesson->teacher_id??0))return false;
        return true;
    }

    /** Immutable interval self-consistency, wall-clock provenance and controlled evidence shape. */
    private static function interval(object $version):bool{
        $starts=(string)($version->starts_at_utc??'');$ends=(string)($version->ends_at_utc??'');$occupied=(string)($version->occupied_ends_at_utc??'');
        $duration=(int)($version->duration_minutes??0);$buffer=(int)($version->buffer_minutes??0);
        if(!self::utc($starts)||!self::utc($ends)||!self::utc($occupied))return false;
        if($duration<1||$duration>1440||$buffer<0||$buffer>480)return false;
        $start=(int)strtotime($starts.' UTC');$end=(int)strtotime($ends.' UTC');$occ=(int)strtotime($occupied.' UTC');
        if($end!==$start+$duration*60||$occ!==$end+$buffer*60||$end<=$start||$occ<$end)return false;
        $timezone=(string)($version->schedule_timezone??'');
        if($timezone===''||!in_array($timezone,timezone_identifiers_list(),true))return false;
        $date=(string)($version->local_wall_date??'');$time=(string)($version->local_wall_time??'');
        if(!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$date))return false;
        try{$wall=AvailabilityLocalTime::wall($date,$time,$timezone);}catch(\Throwable){return false;}
        if($wall->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')!==$starts)return false;
        if(!in_array((string)($version->duration_source??''),self::DURATION_SOURCES,true))return false;
        $basis=(string)($version->availability_basis??'');
        if(!in_array($basis,self::AVAILABILITY_BASES,true))return false;
        $override=self::evidenceShape($version->override_reason_code??null,$version->override_evidence_channel??null,$version->override_evidence_reference_digest??null,$version->override_evidence_at??null);
        if($basis==='administrative_override'){if(!$override)return false;}
        elseif(($version->override_reason_code??null)!==null||($version->override_evidence_channel??null)!==null||($version->override_evidence_reference_digest??null)!==null||($version->override_evidence_at??null)!==null)return false;
        return self::evidenceShape($version->reason_code??null,$version->evidence_channel??null,$version->evidence_reference_digest??null,$version->evidence_at??null);
    }

    /** Controlled reason/channel/digest/timestamp shape used by versions, events and commands. */
    public static function evidenceShape(mixed $reason,mixed $channel,mixed $digest,mixed $at):bool{
        $reason=(string)($reason??'');
        if($reason===''||strlen($reason)>64||!preg_match('/^[a-z0-9_]+$/D',$reason))return false;
        if(!in_array((string)($channel??''),self::CHANNELS,true))return false;
        $digest=(string)($digest??'');
        if(!preg_match('/^[a-f0-9]{64}$/D',$digest))return false;
        return self::utc($at);
    }

    public static function utc(mixed $value):bool{
        $value=(string)($value??'');
        if(!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D',$value))return false;
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new \DateTimeZone('UTC'));
        return (bool)($parsed&&$parsed->format('Y-m-d H:i:s')===$value);
    }
}
