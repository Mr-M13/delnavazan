<?php
namespace Delnavazan\Platform\Core\Application;

/** Shared canonical Enrolment projection/history integrity contract. */
final class CanonicalEnrolmentLifecycleValidator {
    public static function legalTransition(?string $from, string $to): bool {
        if ($from === null) return $to === 'authorised';
        return ($from === 'authorised' && in_array($to, array('current','closed'), true))
            || ($from === 'current' && in_array($to, array('paused','closed'), true))
            || ($from === 'paused' && in_array($to, array('current','closed'), true));
    }

    public static function valid(object $row, array $events): bool {
        $state=(string)($row->lifecycle_state??'');
        $slot=in_array($state,array('authorised','current','paused'),true)?1:null;
        if(($row->record_model??null)!=='canonical_student_course_v1'||($row->status??null)!=='canonical'
            ||!in_array($state,array('authorised','current','paused','closed'),true)
            ||(($row->applicable_slot===null?null:(int)$row->applicable_slot)!==$slot)
            ||(int)($row->accepted_service_arrangement_id??0)<1||$row->archived_at!==null||!$events)return false;
        $previous=null;$occurred=null;
        foreach($events as$index=>$event){
            $from=$event->from_state===null?null:(string)$event->from_state;$to=(string)$event->to_state;
            if((int)$event->enrolment_id!==(int)$row->id||(int)$event->event_sequence!==$index+1||$from!==$previous
                ||!self::legalTransition($from,$to)||!self::audit($event)||($occurred!==null&&(string)$event->occurred_at<$occurred))return false;
            if($index===0&&($event->reason_code!=='accepted_service_arrangement_conversion'||$event->evidence_channel!=='platform_conversion_command'))return false;
            if($index>0&&($event->lineage_meaning!==null||!self::transitionEvidence($from,$to,$event)))return false;
            $previous=$to;$occurred=(string)$event->occurred_at;
        }
        return $previous===$state;
    }

    private static function transitionEvidence(?string$from,string$to,object$event):bool{
        $reason=$to==='closed'?'canonical_enrolment_closed':($from==='authorised'&&$to==='current'?'canonical_enrolment_activated':($from==='current'&&$to==='paused'?'canonical_enrolment_paused':($from==='paused'&&$to==='current'?'canonical_enrolment_resumed':'')));
        return $reason!==''&&$event->reason_code===$reason
            &&in_array((string)$event->evidence_channel,array('staff_record','authenticated_platform','document_reference'),true)
            &&preg_match('/^[a-f0-9]{64}$/D',(string)$event->evidence_reference)===1;
    }

    private static function audit(object $event): bool {
        return preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D',(string)$event->uid)===1
            && preg_match('/^[a-z0-9_]{3,64}$/D',(string)$event->reason_code)===1
            && preg_match('/^[a-z0-9_]{3,32}$/D',(string)$event->evidence_channel)===1
            && trim((string)$event->evidence_reference)!==''&&self::utc($event->occurred_at)&&self::utc($event->recorded_at)&&self::utc($event->created_at)
            &&(string)$event->recorded_at>=(string)$event->occurred_at&&(string)$event->created_at>=(string)$event->recorded_at
            &&(int)$event->recorded_by>0&&(int)$event->created_by>0&&(int)$event->recorded_by===(int)$event->created_by;
    }
    private static function utc(mixed $v):bool{return is_string($v)&&preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$v)===1&&strtotime($v.' UTC')!==false;}
}
