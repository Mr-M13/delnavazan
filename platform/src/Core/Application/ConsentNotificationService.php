<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Consent notification producer.
 *
 * It records the website inbox through the canonical recorder and publishes one neutral action for
 * the existing delivery authority. It never calls a transport, never chooses a recipient from
 * request input and never includes consent evidence or sensitive personal data in the message.
 */
final class ConsentNotificationService {
    public const TEACHER_REQUIRED='consent.teacher_required.teacher';
    public const STUDENT_REQUIRED='consent.student_required.student';
    public const GUARDIAN_REQUIRED='consent.guardian_required.guardian';
    public const RECORDED_TEACHER='consent.recorded.teacher';
    public const RECORDED_STUDENT='consent.recorded.student';
    public const POLICY_UPDATED_STUDENT='consent.policy_updated.student';
    public const POLICY_UPDATED_TEACHER='consent.policy_updated.teacher';
    public const WITHDRAWAL_TEACHER='consent.withdrawal_confirmed.teacher';
    public const WITHDRAWAL_STUDENT='consent.withdrawal_confirmed.student';
    public const ADMIN_ATTENTION='consent.admin_attention.admin';

    public function teacherRequired(int $teacherId,int $decisionId=0):?int{
        return $this->record(self::TEACHER_REQUIRED,'teacher',$teacherId,'consent_decision',$decisionId,array('teacher_portal_url'=>home_url('/teacher-portal/')),home_url('/teacher-portal/'));
    }
    public function studentRequired(int $studentId,int $decisionId=0):?int{
        return $this->record(self::STUDENT_REQUIRED,'student',$studentId,'consent_decision',$decisionId,array('portal_url'=>home_url('/student-portal/')),home_url('/student-portal/'));
    }
    public function guardianRequired(int $studentId,int $decisionId=0):?int{
        return $this->record(self::GUARDIAN_REQUIRED,'guardian',$studentId,'consent_decision',$decisionId,array('portal_url'=>home_url('/student-portal/')),home_url('/student-portal/'));
    }
    public function recorded(string $audience,int $recipientId,int $decisionId):?int{
        $key=$audience==='teacher'?self::RECORDED_TEACHER:self::RECORDED_STUDENT;
        $url=$audience==='teacher'?home_url('/teacher-portal/'):home_url('/student-portal/');
        return $this->record($key,$audience,$recipientId,'consent_decision',$decisionId,$audience==='teacher'?array('teacher_portal_url'=>$url):array('portal_url'=>$url),$url);
    }
    public function policyUpdated(string $target,int $recipientId,int $noticeVersionId):?int{
        $key=$target==='teacher'?self::POLICY_UPDATED_TEACHER:self::POLICY_UPDATED_STUDENT;
        $url=$target==='teacher'?home_url('/teacher-portal/'):home_url('/student-portal/');
        return $this->record($key,$target,$recipientId,'recording_notice_version',$noticeVersionId,$target==='teacher'?array('teacher_portal_url'=>$url):array('portal_url'=>$url),$url);
    }
    public function withdrawal(string $audience,int $recipientId,int $decisionId):?int{
        $key=$audience==='teacher'?self::WITHDRAWAL_TEACHER:self::WITHDRAWAL_STUDENT;
        $url=$audience==='teacher'?home_url('/teacher-portal/'):home_url('/student-portal/');
        return $this->record($key,$audience,$recipientId,'consent_decision',$decisionId,$audience==='teacher'?array('teacher_portal_url'=>$url):array('portal_url'=>$url),$url);
    }
    public function adminAttention(int $sourceId,string $reason='consent_processing'):?int{
        return $this->record(self::ADMIN_ATTENTION,'admin',0,'consent_processing',max(1,$sourceId),array('when'=>gmdate('Y-m-d H:i:s')),admin_url('admin.php?page=dzn-policies'));
    }

    public static function register():void{
        add_action('dzn_consent_decision_recorded',static function($subjectType,$subjectId,$purpose,$choice,$decisionId):void{
            try{
                $service=new self();
                $service->recorded((string)$subjectType,(int)$subjectId,(int)$decisionId);
                if(in_array((string)$choice,array('decline','withdraw'),true))$service->withdrawal((string)$subjectType,(int)$subjectId,(int)$decisionId);
            }catch(\Throwable $error){do_action('dzn_consent_notification_failed','decision_recorded','', $error->getMessage());}
        },10,5);
        add_action('dzn_recording_notice_published',static function($audience,$language,$version,$uid,$actor):void{
            try{
                global $wpdb;$p=$wpdb->prefix.'dzn_';
                $audience=(string)$audience;
                if(str_starts_with($audience,'teacher_')){
                    $ids=(array)$wpdb->get_col("SELECT id FROM {$p}teachers WHERE status='active' AND archived_at IS NULL ORDER BY id LIMIT 200");
                    foreach($ids as$id)(new self())->policyUpdated('teacher',(int)$id,(int)$version);
                }elseif(str_starts_with($audience,'student_')){
                    $ids=(array)$wpdb->get_col("SELECT id FROM {$p}students WHERE status='active' AND archived_at IS NULL ORDER BY id LIMIT 200");
                    foreach($ids as$id)(new self())->policyUpdated('student',(int)$id,(int)$version);
                }
                (new self())->adminAttention((int)$version,'consent_policy_updated');
            }catch(\Throwable $error){do_action('dzn_consent_notification_failed','policy_published','', $error->getMessage());}
        },10,5);
    }

    private function record(string $eventKey,string $audience,int $recipientId,string $sourceKind,int $sourceId,array $variables,?string $link):?int{        if(!class_exists(PortalNotificationRecorder::class))return null;
        try{
            $id=$audience==='teacher'?PortalNotificationRecorder::teacher($recipientId,$eventKey,$sourceKind,$sourceId,$variables,$link)
                :($audience==='student'?PortalNotificationRecorder::student($recipientId,$eventKey,$sourceKind,$sourceId,$variables,$link)
                :($audience==='guardian'?PortalNotificationRecorder::guardian($recipientId,$eventKey,$sourceKind,$sourceId,$variables,$link)
                :PortalNotificationRecorder::admin($eventKey,$sourceKind,$sourceId,$variables,$link)));
            do_action('dzn_consent_notification_requested',$eventKey,$audience,$recipientId,$variables,$link);
            return $id;
        }catch(\Throwable $error){
            do_action('dzn_consent_notification_failed',$eventKey,$audience,$error->getMessage());
            return null;
        }
    }
}
