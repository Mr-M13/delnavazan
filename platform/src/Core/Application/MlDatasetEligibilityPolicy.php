<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Pure fail-closed policy for machine-learning dataset eligibility.
 *
 * A lawfully recorded lesson is never automatically eligible for research. The caller must prove
 * recording authorisation *and* a current, separate research opt-in from every identifiable person
 * (or the authorised guardian), together with current policies and no withdrawal/exclusion.
 */
final class MlDatasetEligibilityPolicy {
    public const REASONS=array(
        'eligible','provenance_unavailable','lesson_mismatch','recording_not_authorised',
        'teacher_research_missing','teacher_research_withdrawn','teacher_policy_superseded',
        'student_research_missing','student_research_withdrawn','student_policy_superseded',
        'guardian_authority_invalid','guardian_research_missing','guardian_research_withdrawn',
        'guardian_policy_superseded','retention_restriction','excluded','quarantined','policy_unavailable',
    );

    /** @param array<string,mixed> $facts @return array{eligible:bool,reason:string} */
    public function evaluate(array $facts):array{
        foreach(array('provenance_valid','recording_authorised') as $key)if(!array_key_exists($key,$facts))return self::deny('policy_unavailable');
        if($facts['provenance_valid']!==true)return self::deny('provenance_unavailable');
        if(($facts['excluded']??false)===true)return self::deny('excluded');
        if(($facts['quarantined']??false)===true)return self::deny('quarantined');
        if(($facts['retention_restriction']??false)===true)return self::deny('retention_restriction');
        if($facts['recording_authorised']!==true)return self::deny('recording_not_authorised');
        if(($facts['teacher_research_withdrawn']??false)===true)return self::deny('teacher_research_withdrawn');
        if(($facts['teacher_research_current']??false)!==true)return self::deny('teacher_research_missing');
        if(($facts['teacher_policy_current']??true)!==true)return self::deny('teacher_policy_superseded');
        $capacity=(string)($facts['student_capacity']??'');
        if($capacity==='minor'){
            if(($facts['guardian_authority_valid']??false)!==true)return self::deny('guardian_authority_invalid');
            if(($facts['guardian_research_withdrawn']??false)===true)return self::deny('guardian_research_withdrawn');
            if(($facts['guardian_research_current']??false)!==true)return self::deny('guardian_research_missing');
            if(($facts['guardian_policy_current']??true)!==true)return self::deny('guardian_policy_superseded');
            return self::allow();
        }
        if($capacity!=='adult')return self::deny('student_research_missing');
        if(($facts['student_research_withdrawn']??false)===true)return self::deny('student_research_withdrawn');
        if(($facts['student_research_current']??false)!==true)return self::deny('student_research_missing');
        if(($facts['student_policy_current']??true)!==true)return self::deny('student_policy_superseded');
        return self::allow();
    }

    public static function allow():array{return array('eligible'=>true,'reason'=>'eligible');}
    public static function deny(string $reason):array{
        if(!in_array($reason,self::REASONS,true))$reason='policy_unavailable';
        return array('eligible'=>false,'reason'=>$reason);
    }
}
