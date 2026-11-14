<?php
namespace Delnavazan\Platform\Core\Application\Recording;

use Delnavazan\Platform\Portals\PortalPrincipalResolver;

/**
 * Short-lived WordPress playback grants.
 *
 * A grant is limited to one recording content version, one principal, the playback-gateway
 * audience and at most five minutes. Raw grants are never stored; only a keyed digest of the jti is
 * appended to dzn_recording_playback_access.
 */
final class RecordingPlaybackGrantService {
    public const AUDIENCE='recording-playback-gateway';
    public const TTL_SECONDS=300;

    private RecordingPlaybackPolicy $policy;
    private RecordingControlPlane $control;
    private ?int $now;

    public function __construct(?RecordingPlaybackPolicy $policy=null,?RecordingControlPlane $control=null,?int $now=null){
        $this->policy=$policy??new RecordingPlaybackPolicy();
        $this->control=$control??new RecordingControlPlane(null,null,$now);
        $this->now=$now;
    }

    /** @return array{grant:array,gateway_url:string,expires_at:int,recording:array} */
    public function issueForRecording(string $recordingUid,string $source='portal'):array{
        $now=$this->now();
        $recording=$this->control->recordingByUid($recordingUid);
        $principal=null;
        try{$principal=$this->principalForRecording($recording?(int)$recording->teacher_id:0,$recording?(int)$recording->student_id:0);}
        catch(\Throwable $error){
            if($recording)$this->control->recordPlaybackEvent((int)$recording->id,(int)$recording->content_version,'unknown',0,'refuse','principal_unresolved',hash('sha256',$recordingUid.'|'.$now),null,null,null,$source);
            throw new \RuntimeException('recording_playback_denied:principal_required');
        }
        if(!$recording){
            $this->control->recordPlaybackEvent(0,1,(string)$principal['kind'],(int)$principal['id'],'refuse','recording_not_found',hash('sha256',$recordingUid.'|'.$now),null,null,null,$source);
            throw new \RuntimeException('recording_playback_denied:recording_not_found');
        }
        $guardianValid=false;$guardianStudent=0;
        if((string)$principal['kind']==='guardian'){
            $guardianValid=isset($principal['grant_id'])&&(int)$principal['grant_id']>0;
            $guardianStudent=(int)$principal['id'];
        }
        $health=RecordingFeatureFlags::serviceHealth();
        $fresh=(int)($health['received_at']??0)>=$now-180;
        $archiveAvailable=$fresh&&($health['archive_available']??false)===true&&trim((string)($health['archive_mount_identity']??''))!=='';
        $withdrawalBlocking=(string)$recording->disposition_state!=='none'||in_array((string)$recording->lifecycle_state,array(RecordingLifecycle::QUARANTINED,RecordingLifecycle::DELETION_REQUIRED,RecordingLifecycle::FAILED),true);
        $facts=array(
            'recording_found'=>true,'principal_kind'=>(string)$principal['kind'],'principal_id'=>(int)$principal['id'],
            'teacher_id'=>(int)$recording->teacher_id,'student_id'=>(int)$recording->student_id,
            'guardian_valid'=>$guardianValid,'guardian_student_id'=>$guardianStudent,
            'recording_state'=>(string)$recording->lifecycle_state,'content_version'=>(int)$recording->content_version,
            'disposition_state'=>(string)$recording->disposition_state,'withdrawal_blocking'=>$withdrawalBlocking,
            'archive_available'=>$archiveAvailable,
        );
        $verdict=$this->policy->evaluate($facts);
        $jti=bin2hex(random_bytes(16));
        if(!$verdict['authorised']){
            $this->control->recordPlaybackEvent((int)$recording->id,(int)$recording->content_version,(string)$principal['kind'],(int)$principal['id'],'refuse',$verdict['reason'],$jti,null,null,null,$source);
            throw new \RuntimeException('recording_playback_denied:'.$verdict['reason']);
        }
        $expires=$now+self::TTL_SECONDS;
        $payload=array(
            'iss'=>(string)home_url('/'),'aud'=>self::AUDIENCE,'sub'=>(string)get_current_user_id(),
            'principal_type'=>(string)$principal['kind'],'principal_id'=>(int)$principal['id'],
            'recording_uid'=>(string)$recording->uid,'content_version'=>(int)$recording->content_version,
            'lesson_id'=>(int)$recording->lesson_id,'scope'=>'stream','jti'=>$jti,'nbf'=>$now,'iat'=>$now,'exp'=>$expires,
            'archive_object_key'=>(string)$recording->archive_object_key,
        );
        $grant=RecordingSignature::sign($payload,RecordingFeatureFlags::playbackSecret(),RecordingFeatureFlags::playbackKeyId(),self::AUDIENCE,$now,self::TTL_SECONDS);
        $this->control->recordPlaybackEvent((int)$recording->id,(int)$recording->content_version,(string)$principal['kind'],(int)$principal['id'],'allow','authorised',$jti,null,null,null,$source);
        return array(
            'grant'=>$grant,'gateway_url'=>RecordingFeatureFlags::playbackGatewayUrl().'/', 'expires_at'=>$expires,
            'recording'=>array(
                'recording_uid'=>(string)$recording->uid,'content_version'=>(int)$recording->content_version,
                'lesson_id'=>(int)$recording->lesson_id,'duration_ms'=>(int)($recording->duration_ms??0),
                'media_format'=>(string)($recording->media_format??'mp4'),'title'=>'Delnavazan lesson recording',
            ),
        );
    }

    /** Presentation descriptor only; it never issues a grant or returns a permanent URL. */
    public function descriptorForLesson(string $lessonUid):array{
        global $wpdb;
        $empty=array('available'=>false,'recording_uid'=>'','status'=>'','label'=>'','state'=>'','content_version'=>0);
        if(preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D',$lessonUid)!==1)return $empty;
        $row=$wpdb->get_row($wpdb->prepare("SELECT r.uid,r.lifecycle_state,r.disposition_state,r.content_version,r.lesson_id,r.teacher_id,r.student_id,l.uid lesson_uid FROM {$wpdb->prefix}dzn_recordings r INNER JOIN {$wpdb->prefix}dzn_lessons l ON l.id=r.lesson_id WHERE l.uid=%s ORDER BY r.id DESC LIMIT 1",$lessonUid));
        if(!$row)return $empty;
        $principal=null;
        try{$principal=$this->principalForRecording((int)$row->teacher_id,(int)$row->student_id);}catch(\Throwable $error){return $empty;}
        $kind=(string)$principal['kind'];$id=(int)$principal['id'];
        $owned=($kind==='teacher'&&$id===(int)$row->teacher_id)||($kind==='student'&&$id===(int)$row->student_id)||($kind==='guardian'&&(int)($principal['grant_id']??0)>0);
        $health=RecordingFeatureFlags::serviceHealth();
        $archive=$health['archive_available']??false;
        $available=$owned&&RecordingLifecycle::playbackCandidate((string)$row->lifecycle_state)&&(string)$row->disposition_state==='none'&&$archive===true;
        $labels=array(
            RecordingLifecycle::AUTHORISED=>'ضبط برنامه‌ریزی نشده','recording_requested'=>'در انتظار آغاز ضبط',
            RecordingLifecycle::RECORDING_ACTIVE=>'در حال ضبط',RecordingLifecycle::RECORDING_FINALISING=>'در حال نهایی‌سازی',
            RecordingLifecycle::RECORDING_VERIFIED=>'ضبط تأیید شد',RecordingLifecycle::AWAITING_ARCHIVE=>'در انتظار بایگانی',
            RecordingLifecycle::TRANSFERRING=>'در حال انتقال به بایگانی',RecordingLifecycle::TRANSFER_RETRY_PENDING=>'انتقال بایگانی در انتظار تلاش دوباره',
            RecordingLifecycle::ARCHIVE_VERIFICATION=>'در حال بررسی بایگانی',RecordingLifecycle::ARCHIVED=>'بایگانی‌شده',
            RecordingLifecycle::VPS_DELETION_PENDING=>'بایگانی‌شده · حذف نسخهٔ موقت در انتظار',
            RecordingLifecycle::ARCHIVED_VPS_DELETED=>'بایگانی‌شده',RecordingLifecycle::FAILED=>'ضبط ناموفق',
            RecordingLifecycle::QUARANTINED=>'قرنطینه‌شده',RecordingLifecycle::DELETION_REQUIRED=>'در انتظار حذف',
        );
        $label=$labels[(string)$row->lifecycle_state]??'وضعیت ضبط نامشخص';
        if(!$owned)$label='وضعیت ضبط برای این حساب قابل نمایش نیست.';
        elseif(RecordingLifecycle::playbackCandidate((string)$row->lifecycle_state)&&(string)$row->disposition_state==='none'&&$archive!==true)$label='بایگانی موقتاً در دسترس نیست.';
        return array('available'=>$available,'recording_uid'=>(string)$row->uid,'status'=>(string)$row->lifecycle_state,
            'label'=>$label,'state'=>(string)$row->lifecycle_state,'content_version'=>(int)$row->content_version);
    }

    /**
     * Resolve the principal that owns the exact recording. A dual-role account
     * has both a Student and a Teacher link; resolving `any` would be ambiguous,
     * so prefer the side that owns this recording before falling back.
     */
    private function principalForRecording(int $teacherId, int $studentId): array {
        $resolver = new PortalPrincipalResolver();
        if ($teacherId > 0) {
            try { $principal = $resolver->resolve('teacher'); if ((int) $principal['id'] === $teacherId) return $principal; } catch (\Throwable) {}
        }
        if ($studentId > 0) {
            try { $principal = $resolver->resolve('student'); if ((int) $principal['id'] === $studentId) return $principal; } catch (\Throwable) {}
        }
        return $resolver->resolve('any');
    }

    private function now():int{return $this->now??(function_exists('time')?(int)time():0);}
}
