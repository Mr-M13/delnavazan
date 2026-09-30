<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Locked Phase 2A.2-Q post-intro continuation rule (owner decisions Q-D1…Q-D12).
 *
 * The rule derives the ONE expected first regular class slot from the authoritative introductory
 * occurrence's own wall-clock/timezone provenance — the intro day/time is expected to become the
 * ongoing regular day/time — and freezes the hold expiry as the earlier of that slot start and six
 * days after the introductory occurrence boundary. Nothing here creates a Lesson, schedule, Term,
 * payment or entitlement.
 */
final class CanonicalContinuationRule {
    public const RULE_VERSION='canonical_continuation_v1';
    /** Six-day maximum hold, measured from the authoritative introductory occurrence boundary. */
    public const HOLD_DAYS=6;
    public const DECISION_CONTINUE='continue_with_teacher';
    public const DECISION_DIFFERENT_TEACHER='different_teacher';
    public const DECISION_CONTACT_ME='contact_me';
    public const DECISION_NOT_CONTINUING='not_continuing';
    public const DECISION_TEACHER_UNSUITABLE='teacher_unsuitable';
    public const STUDENT_DECISIONS=array(
        self::DECISION_CONTINUE,self::DECISION_DIFFERENT_TEACHER,self::DECISION_CONTACT_ME,self::DECISION_NOT_CONTINUING,
    );
    public const ALL_DECISIONS=array(
        self::DECISION_CONTINUE,self::DECISION_DIFFERENT_TEACHER,self::DECISION_CONTACT_ME,self::DECISION_NOT_CONTINUING,self::DECISION_TEACHER_UNSUITABLE,
    );
    /** Only a continuing Student decision may hold capacity (Q-D3/Q-D4). */
    public const HOLDING_DECISIONS=array(self::DECISION_CONTINUE);
    /** Controlled, privacy-minimised optional feedback for not_continuing (Q-D10). */
    public const FEEDBACK_REASONS=array('teacher_fit','schedule','price','changed_mind','technical_experience','other','prefer_not_to_say');
    public const INTERVENTION_REASONS=array(
        'student_requested_different_teacher','student_requested_contact','teacher_match_unsuitable','integrity_conflict',
        'first_regular_slot_authority_required',
    );
    public const RESERVATION_STATES=array('active','expired','released');

    /**
     * Resolve one EXPLICITLY AUTHORISED local wall-clock slot into exact UTC instants.
     *
     * This helper performs no recurrence reasoning and invents no future class: the slot, its
     * timezone and its wall clock must already come from an authoritative record (the Phase-Q
     * first-regular-slot authority). It only converts an authoritative wall clock into the exact UTC
     * interval plus wall-clock provenance, failing closed on an invalid, nonexistent or ambiguous
     * local time.
     *
     * @return array{starts_at_utc:string,ends_at_utc:string,occupied_ends_at_utc:string,duration_minutes:int,buffer_minutes:int,schedule_timezone:string,local_wall_date:string,local_wall_time:string}
     */
    public static function resolveWallClock(string $timezone,string $localDate,string $localTime,int $durationMinutes,int $bufferMinutes):array{
        if($timezone===''||$localDate===''||$localTime==='')throw new \InvalidArgumentException('continuation_intro_provenance_required');
        try{$zone=new \DateTimeZone($timezone);}catch(\Throwable$e){throw new \InvalidArgumentException('continuation_intro_provenance_required');}
        $local=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$localDate.' '.$localTime,$zone);
        if(!$local||$local->format('Y-m-d H:i:s')!==$localDate.' '.$localTime)throw new \InvalidArgumentException('continuation_slot_wall_clock_invalid');
        $duration=$durationMinutes;
        $buffer=max(0,min(240,$bufferMinutes));
        if($duration<5||$duration>480)throw new \InvalidArgumentException('continuation_course_duration_required');
        $end=$local->modify('+'.$duration.' minutes');
        if($end<=$local)throw new \InvalidArgumentException('continuation_slot_wall_clock_invalid');
        // Reject a wall clock the zone skips or repeats (DST gap / ambiguity): the authority must be
        // unambiguous before capacity can be held against it.
        $offsets=array();
        foreach($zone->getTransitions($local->getTimestamp()-172800,$end->getTimestamp()+172800)as$transition){
            $candidate=(new \DateTimeImmutable('@'.(strtotime($localDate.' '.$localTime.' UTC')-(int)$transition['offset'])))->setTimezone($zone);
            if($candidate->format('Y-m-d H:i:s')===$localDate.' '.$localTime)$offsets[(int)$transition['offset']]=true;
        }
        if(count($offsets)>1)throw new \InvalidArgumentException('continuation_slot_wall_clock_ambiguous');
        $utc=new \DateTimeZone('UTC');
        $starts=$local->setTimezone($utc)->format('Y-m-d H:i:s');
        $ends=$end->setTimezone($utc)->format('Y-m-d H:i:s');
        $occupied=$end->modify('+'.$buffer.' minutes')->setTimezone($utc)->format('Y-m-d H:i:s');
        return array(
            'starts_at_utc'=>$starts,'ends_at_utc'=>$ends,'occupied_ends_at_utc'=>$occupied,
            'duration_minutes'=>$duration,'buffer_minutes'=>$buffer,
            'schedule_timezone'=>$timezone,'local_wall_date'=>$localDate,'local_wall_time'=>$localTime,
        );
    }

    /**
     * Frozen hold expiry: the earlier of the expected first regular slot start and six days after the
     * authoritative introductory occurrence boundary. Both bounds are exact UTC instants.
     */
    public static function expiresAt(string $introOccurrenceEndUtc,string $slotStartUtc):string{
        if(!CanonicalContinuationValidator::utc($introOccurrenceEndUtc)||!CanonicalContinuationValidator::utc($slotStartUtc))throw new \InvalidArgumentException('continuation_expiry_source_required');
        $boundary=gmdate('Y-m-d H:i:s',strtotime($introOccurrenceEndUtc.' UTC')+self::HOLD_DAYS*86400);
        return $slotStartUtc<$boundary?$slotStartUtc:$boundary;
    }

    /** Controlled authority basis for an explicit post-intro first regular slot record. */
    public const SLOT_AUTHORITY_BASES=array('administrator_attestation');
    public const SLOT_REASON_CODES=array('agreed_regular_slot','intro_slot_becomes_regular','rescheduled_agreement');

    /** A reservation is capacity-effective only while it is active AND its frozen expiry has not passed. */
    public static function capacityEffective(string $state,string $expiresAt,string $now):bool{
        return $state==='active'&&$expiresAt>$now;
    }
}
