<?php
namespace Delnavazan\Platform\Core\Application\Recording;

/**
 * Pure playback decision table.
 *
 * Facts are resolved by the WordPress service from canonical principals and durable recording
 * state. Browser fields never flow into this table.
 */
final class RecordingPlaybackPolicy {
    public const REASONS=array(
        'authorised','principal_required','principal_not_permitted','lesson_not_owned',
        'recording_not_found','recording_not_archived','recording_quarantined','withdrawal_blocking',
        'archive_unavailable','grant_unavailable','version_invalid',
    );

    /**
     * @param array<string,mixed> $facts
     * @return array{authorised:bool,reason:string}
     */
    public function evaluate(array $facts):array{
        foreach(array('principal_kind','principal_id','teacher_id','student_id','recording_state','disposition_state','archive_available') as $key){
            if(!array_key_exists($key,$facts))return self::deny('principal_required');
        }
        $kind=(string)$facts['principal_kind'];$principal=(int)$facts['principal_id'];
        if($principal<1||!in_array($kind,array('teacher','student','guardian'),true))return self::deny('principal_required');
        if(($facts['recording_found']??false)!==true)return self::deny('recording_not_found');
        $owned=($kind==='teacher'&&$principal===(int)$facts['teacher_id'])
            ||($kind==='student'&&$principal===(int)$facts['student_id'])
            ||($kind==='guardian'&&($facts['guardian_valid']??false)===true&&(int)($facts['guardian_student_id']??0)===(int)$facts['student_id']);
        if(!$owned)return self::deny('lesson_not_owned');
        if(($facts['withdrawal_blocking']??false)===true)return self::deny('withdrawal_blocking');
        $state=(string)$facts['recording_state'];
        if(!RecordingLifecycle::playbackCandidate($state))return self::deny('recording_not_archived');
        if(!in_array((string)$facts['disposition_state'],array('none'),true))return self::deny('recording_quarantined');
        if(($facts['archive_available']??false)!==true)return self::deny('archive_unavailable');
        if((int)($facts['content_version']??0)<1)return self::deny('version_invalid');
        return array('authorised'=>true,'reason'=>'authorised');
    }

    public static function deny(string $reason):array{
        if(!in_array($reason,self::REASONS,true))$reason='grant_unavailable';
        return array('authorised'=>false,'reason'=>$reason);
    }
}
