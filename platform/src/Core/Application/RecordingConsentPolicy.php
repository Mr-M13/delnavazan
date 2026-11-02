<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Pure fail-closed policy for lesson-recording authorisation.
 *
 * The gate resolves canonical facts from the database and passes them here. This class owns no I/O,
 * accepts no browser state and can therefore be tested exhaustively: any missing, stale, declined,
 * withdrawn, unresolved-guardian or policy-superseded fact denies capture.
 */
final class RecordingConsentPolicy {
    public const REASONS=array(
        'authorised','lesson_not_found','lesson_not_canonical','lesson_not_authorised',
        'teacher_terms_missing','teacher_recording_missing','teacher_recording_declined',
        'teacher_policy_superseded','student_missing','student_capacity_unresolved',
        'student_recording_missing','student_recording_declined','student_policy_superseded',
        'guardian_authority_missing','guardian_authority_invalid','guardian_recording_missing',
        'guardian_recording_declined','guardian_policy_superseded','withdrawal_blocking',
        'policy_unavailable','provider_disabled',
    );

    /**
     * @param array<string,mixed> $facts Canonical server-side facts only.
     * @return array{authorised:bool,reason:string}
     */
    public function evaluate(array $facts):array{
        foreach(array('lesson_found','lesson_canonical','lesson_authorised','teacher_terms_current','teacher_recording_current') as $key){
            if(!array_key_exists($key,$facts))return self::deny('policy_unavailable');
        }
        if($facts['lesson_found']!==true)return self::deny('lesson_not_found');
        if($facts['lesson_canonical']!==true)return self::deny('lesson_not_canonical');
        if($facts['lesson_authorised']!==true)return self::deny('lesson_not_authorised');
        if(($facts['blocking_withdrawal']??false)===true)return self::deny('withdrawal_blocking');
        if($facts['teacher_terms_current']!==true)return self::deny('teacher_terms_missing');
        if(($facts['teacher_recording_current']??false)!==true){
            $decision=$facts['teacher_recording_decision']??null;
            if($decision==='decline')return self::deny('teacher_recording_declined');
            if($decision===null)return self::deny('teacher_recording_missing');
            if(($facts['teacher_policy_current']??true)!==true)return self::deny('teacher_policy_superseded');
            return self::deny('teacher_recording_missing');
        }
        if(($facts['teacher_recording_declined']??false)===true)return self::deny('teacher_recording_declined');
        if(($facts['teacher_policy_current']??true)!==true)return self::deny('teacher_policy_superseded');
        if(($facts['student_found']??false)!==true)return self::deny('student_missing');
        $capacity=(string)($facts['student_capacity']??'');
        if(!in_array($capacity,array('adult','minor'),true))return self::deny('student_capacity_unresolved');
        if($capacity==='adult'){
            $studentDecision=$facts['student_recording_decision']??null;
            if(($facts['student_recording_declined']??false)===true||$studentDecision==='decline')return self::deny('student_recording_declined');
            if(($facts['student_recording_current']??false)!==true){
                if($studentDecision!==null&&($facts['student_policy_current']??true)!==true)return self::deny('student_policy_superseded');
                return self::deny('student_recording_missing');
            }
            if(($facts['student_policy_current']??true)!==true)return self::deny('student_policy_superseded');
            return self::allow();
        }
        if(($facts['guardian_required']??true)!==true)return self::deny('guardian_authority_invalid');
        if(($facts['guardian_authority_valid']??false)!==true)return self::deny('guardian_authority_missing');
        $guardianDecision=$facts['guardian_recording_decision']??null;
        if(($facts['guardian_recording_declined']??false)===true||$guardianDecision==='decline')return self::deny('guardian_recording_declined');
        if(($facts['guardian_recording_current']??false)!==true){
            if($guardianDecision!==null&&($facts['guardian_policy_current']??true)!==true)return self::deny('guardian_policy_superseded');
            return self::deny('guardian_recording_missing');
        }
        if(($facts['guardian_policy_current']??true)!==true)return self::deny('guardian_policy_superseded');
        return self::allow();
    }

    public static function allow():array{return array('authorised'=>true,'reason'=>'authorised');}
    public static function deny(string $reason):array{
        if(!in_array($reason,self::REASONS,true))$reason='policy_unavailable';
        return array('authorised'=>false,'reason'=>$reason);
    }
}
