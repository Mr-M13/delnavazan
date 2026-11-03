<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Canonical, dependency-free consent purpose definitions.
 *
 * A purpose is the unit of specific consent. Recording and machine-learning research are always
 * separate purposes, and neither grants the other. Adding a purpose here is additive: the evidence
 * tables store the purpose code, so no database redesign is required for a future purpose.
 */
final class ConsentPurposeRegistry {
    public const TEACHER_TERMS='teacher_terms';
    public const TEACHER_RECORDING='teacher_lesson_recording';
    public const TEACHER_RESEARCH='teacher_research_model_training';
    public const STUDENT_RECORDING='lesson_recording';
    public const STUDENT_RESEARCH='research_model_training';
    public const PRIVACY_TERMS='privacy_terms';

    public const PURPOSES=array(
        self::TEACHER_TERMS=>array(
            'code'=>self::TEACHER_TERMS,'subject_scope'=>'teacher','notice_audience'=>'teacher_terms',
            'choices'=>array('accepted'),'optional'=>false,'guardian_allowed'=>false,
            'required_for_recording'=>true,'required_for_dataset'=>false,'scope_code'=>'recorded_work_terms',
            'label_en'=>'Teacher recorded-work terms','label_fa'=>'شرایط کار ضبط‌شدهٔ مدرس',
        ),
        self::TEACHER_RECORDING=>array(
            'code'=>self::TEACHER_RECORDING,'subject_scope'=>'teacher','notice_audience'=>'teacher_lesson_recording',
            'choices'=>array('allow','decline'),'optional'=>true,'guardian_allowed'=>false,
            'required_for_recording'=>true,'required_for_dataset'=>true,'scope_code'=>'future_lessons',
            'label_en'=>'Teacher lesson recording consent','label_fa'=>'رضایت ضبط کلاس مدرس',
        ),
        self::TEACHER_RESEARCH=>array(
            'code'=>self::TEACHER_RESEARCH,'subject_scope'=>'teacher','notice_audience'=>'teacher_research_model_training',
            'choices'=>array('opt_in','withdraw'),'optional'=>true,'guardian_allowed'=>false,
            'required_for_recording'=>false,'required_for_dataset'=>true,'scope_code'=>'research_dataset',
            'label_en'=>'Teacher machine-learning research consent','label_fa'=>'رضایت پژوهش و آموزش مدل مدرس',
        ),
        self::STUDENT_RECORDING=>array(
            'code'=>self::STUDENT_RECORDING,'subject_scope'=>'student','notice_audience'=>'student_lesson_recording',
            'choices'=>array('allow','decline'),'optional'=>true,'guardian_allowed'=>true,
            'required_for_recording'=>true,'required_for_dataset'=>true,'scope_code'=>'future_lessons',
            'label_en'=>'Student lesson recording consent','label_fa'=>'رضایت ضبط کلاس هنرجو',
        ),
        self::STUDENT_RESEARCH=>array(
            'code'=>self::STUDENT_RESEARCH,'subject_scope'=>'student','notice_audience'=>'student_research_model_training',
            'choices'=>array('opt_in','withdraw'),'optional'=>true,'guardian_allowed'=>true,
            'required_for_recording'=>false,'required_for_dataset'=>true,'scope_code'=>'research_dataset',
            'label_en'=>'Student machine-learning research consent','label_fa'=>'رضایت پژوهش و آموزش مدل هنرجو',
        ),
        self::PRIVACY_TERMS=>array(
            'code'=>self::PRIVACY_TERMS,'subject_scope'=>'any','notice_audience'=>'privacy_terms',
            'choices'=>array('accepted'),'optional'=>false,'guardian_allowed'=>true,
            'required_for_recording'=>false,'required_for_dataset'=>false,'scope_code'=>'privacy_acknowledgement',
            'label_en'=>'Privacy policy acknowledgement','label_fa'=>'تأیید سیاست حفظ حریم خصوصی',
        ),
    );

    public static function all():array{return self::PURPOSES;}
    public static function has(string $purpose):bool{return isset(self::PURPOSES[$purpose]);}
    public static function get(string $purpose):array{
        if(!self::has($purpose))throw new \InvalidArgumentException('consent_purpose_invalid');
        return self::PURPOSES[$purpose];
    }
    public static function forSubject(string $subjectType):array{
        if(!in_array($subjectType,array('teacher','student'),true))throw new \InvalidArgumentException('consent_subject_type_invalid');
        return array_values(array_filter(self::PURPOSES,static fn(array $p):bool=>$p['subject_scope']===$subjectType));
    }
    /** @return string[] */
    public static function choices(string $purpose):array{return self::get($purpose)['choices'];}
    public static function isChoice(string $purpose,string $choice):bool{return in_array($choice,self::choices($purpose),true);}
    public static function audience(string $purpose):string{return (string)self::get($purpose)['notice_audience'];}
    public static function isOptional(string $purpose):bool{return (bool)self::get($purpose)['optional'];}
    public static function guardianAllowed(string $purpose):bool{return (bool)self::get($purpose)['guardian_allowed'];}
    public static function scopeCode(string $purpose):string{return (string)self::get($purpose)['scope_code'];}
    /** A positive decision is the only value that can authorise processing for that exact purpose. */
    public static function isPositive(string $purpose,?string $decision):bool{
        if($decision===null)return false;
        $positive=array(
            self::TEACHER_TERMS=>array('accepted'),self::PRIVACY_TERMS=>array('accepted'),
            self::TEACHER_RECORDING=>array('allow'),self::STUDENT_RECORDING=>array('allow'),
            self::TEACHER_RESEARCH=>array('opt_in'),self::STUDENT_RESEARCH=>array('opt_in'),
        );
        return in_array($decision,$positive[$purpose]??array(),true);
    }
    /** A decision that explicitly closes an earlier positive grant. */
    public static function isWithdrawal(string $purpose,string $decision):bool{
        $withdrawal=array(
            self::TEACHER_RECORDING=>array('decline'),self::STUDENT_RECORDING=>array('decline'),
            self::TEACHER_RESEARCH=>array('withdraw'),self::STUDENT_RESEARCH=>array('withdraw'),
        );
        return in_array($decision,$withdrawal[$purpose]??array(),true);
    }
    public static function requiresRecordingConsent(string $purpose):bool{return (bool)self::get($purpose)['required_for_recording'];}
    public static function requiresDatasetConsent(string $purpose):bool{return (bool)self::get($purpose)['required_for_dataset'];}
    public static function requiredRecordingPurposes():array{
        return array_values(array_keys(array_filter(self::PURPOSES,static fn(array $p):bool=>(bool)$p['required_for_recording'])));
    }
    public static function requiredDatasetPurposes():array{
        return array_values(array_keys(array_filter(self::PURPOSES,static fn(array $p):bool=>(bool)$p['required_for_dataset'])));
    }
}
