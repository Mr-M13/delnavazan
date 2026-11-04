<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\ConsentPurposeRegistry;
use Delnavazan\Platform\Core\Application\ConsentWithdrawalService;
use Delnavazan\Platform\Core\Application\RecordingDecisionAuthorityService;

/**
 * Capability- and nonce-protected self-service boundary for recording/research choices.
 *
 * The browser submits only the action, the published notice identifier and language, and a fresh
 * idempotency key. The actor and subject are always resolved server-side. Withdrawal is performed
 * through the same append-only authority and then handed to the durable downstream processor.
 */
final class RecordingDecisionController {
    public const STUDENT_ACTION='dzn_recording_student_choice';
    public const TEACHER_ACTION='dzn_recording_teacher_terms';
    public const TEACHER_CHOICE_ACTION='dzn_recording_teacher_choice';

    public static function register():void{
        add_action('admin_post_'.self::STUDENT_ACTION,array(__CLASS__,'studentChoice'));
        add_action('admin_post_'.self::TEACHER_ACTION,array(__CLASS__,'teacherTerms'));
        add_action('admin_post_'.self::TEACHER_CHOICE_ACTION,array(__CLASS__,'teacherChoice'));
    }

    public static function studentChoice():never{
        self::requirePost();
        self::studentGuard();
        $returnTo=self::returnTo($_POST['return_to']??'account');
        $purpose=sanitize_key((string)wp_unslash($_POST['purpose']??''));
        $choice=sanitize_key((string)wp_unslash($_POST['choice']??''));
        $noticeUid=sanitize_text_field((string)wp_unslash($_POST['notice_uid']??''));
        $language=sanitize_key((string)wp_unslash($_POST['language']??''));
        $key=sanitize_text_field((string)wp_unslash($_POST['idempotency_key']??''));
        try{
            if(!in_array($purpose,array(ConsentPurposeRegistry::STUDENT_RECORDING,ConsentPurposeRegistry::STUDENT_RESEARCH,ConsentPurposeRegistry::PRIVACY_TERMS),true))throw new \InvalidArgumentException('recording_decision_purpose_invalid');
            $withdrawal=$purpose===ConsentPurposeRegistry::STUDENT_RESEARCH&&$choice==='withdraw';
            $recordingWithdrawal=$purpose===ConsentPurposeRegistry::STUDENT_RECORDING&&$choice==='decline';
            $service=new ConsentWithdrawalService();
            if($withdrawal)$service->withdrawStudentResearch($noticeUid,$language,$key);
            elseif($recordingWithdrawal)$service->withdrawStudentRecording($noticeUid,$language,$key);
            else (new RecordingDecisionAuthorityService())->recordOwnStudentChoice($purpose,$choice,$noticeUid,$language,$key,'portal_settings','student_portal');
            if($purpose===ConsentPurposeRegistry::STUDENT_RECORDING)$status=$choice==='allow'?'recording_saved':'recording_declined';
            elseif($purpose===ConsentPurposeRegistry::STUDENT_RESEARCH)$status=$choice==='opt_in'?'research_saved':'research_withdrawn';
            else $status='privacy_saved';
            self::rememberStatus('student',$status);
            self::redirectStudent($returnTo);
        }catch(\Throwable){
            self::rememberStatus('student','recording_unavailable');
            self::redirectStudent($returnTo);
        }
    }

    public static function teacherTerms():never{
        self::requirePost();
        if(!is_user_logged_in())auth_redirect();
        if(!current_user_can('dzn_accept_own_recording_terms'))wp_die('Access denied.','Delnavazan',array('response'=>403));
        check_admin_referer(self::TEACHER_ACTION);
        $returnTo=self::returnTo($_POST['return_to']??'onboarding');
        try{
            (new RecordingDecisionAuthorityService())->acceptTeacherTerms(
                sanitize_text_field((string)wp_unslash($_POST['notice_uid']??'')),
                sanitize_key((string)wp_unslash($_POST['language']??'')),
                sanitize_text_field((string)wp_unslash($_POST['idempotency_key']??''))
            );
            self::rememberStatus('teacher','terms_saved');
            self::redirectTeacher($returnTo);
        }catch(\Throwable){
            self::rememberStatus('teacher','terms_unavailable');
            self::redirectTeacher($returnTo);
        }
    }

    public static function teacherChoice():never{
        self::requirePost();
        if(!is_user_logged_in())auth_redirect();
        if(!current_user_can('dzn_accept_own_recording_terms'))wp_die('Access denied.','Delnavazan',array('response'=>403));
        check_admin_referer(self::TEACHER_CHOICE_ACTION);
        $returnTo=self::returnTo($_POST['return_to']??'onboarding');
        $purpose=sanitize_key((string)wp_unslash($_POST['purpose']??''));
        $choice=sanitize_key((string)wp_unslash($_POST['choice']??''));
        $noticeUid=sanitize_text_field((string)wp_unslash($_POST['notice_uid']??''));
        $language=sanitize_key((string)wp_unslash($_POST['language']??''));
        $key=sanitize_text_field((string)wp_unslash($_POST['idempotency_key']??''));
        try{
            if(!in_array($purpose,array(ConsentPurposeRegistry::TEACHER_RECORDING,ConsentPurposeRegistry::TEACHER_RESEARCH,ConsentPurposeRegistry::PRIVACY_TERMS),true))throw new \InvalidArgumentException('recording_decision_purpose_invalid');
            $service=new ConsentWithdrawalService();
            if($purpose===ConsentPurposeRegistry::TEACHER_RESEARCH&&$choice==='withdraw')$service->withdrawTeacherResearch($noticeUid,$language,$key);
            elseif($purpose===ConsentPurposeRegistry::TEACHER_RECORDING&&$choice==='decline')$service->withdrawTeacherRecording($noticeUid,$language,$key);
            else (new RecordingDecisionAuthorityService())->recordTeacherChoice($purpose,$choice,$noticeUid,$language,$key,'portal_settings','teacher_portal');
            if($purpose===ConsentPurposeRegistry::TEACHER_RECORDING)$status=$choice==='allow'?'teacher_recording_saved':'teacher_recording_declined';
            elseif($purpose===ConsentPurposeRegistry::TEACHER_RESEARCH)$status=$choice==='opt_in'?'teacher_research_saved':'teacher_research_withdrawn';
            else $status='privacy_saved';
            self::rememberStatus('teacher',$status);
            self::redirectTeacher($returnTo);
        }catch(\Throwable){
            self::rememberStatus('teacher','terms_unavailable');
            self::redirectTeacher($returnTo);
        }
    }

    private static function studentGuard():void{
        if(!is_user_logged_in())auth_redirect();
        if(!current_user_can('read'))wp_die('Access denied.','Delnavazan',array('response'=>403));
        check_admin_referer(self::STUDENT_ACTION);
    }

    private static function requirePost():void{
        if('POST'!==strtoupper((string)($_SERVER['REQUEST_METHOD']??'')))wp_die('Method not allowed.','Delnavazan',array('response'=>405));
    }
    private static function rememberStatus(string $scope,string $status):void{
        set_transient('dzn_recording_status_'.$scope.'_'.get_current_user_id(),sanitize_key($status),120);
    }
    private static function returnTo(mixed $value):string{
        $value=sanitize_key((string)wp_unslash($value));
        return in_array($value,array('account','home','settings','onboarding'),true)?$value:'account';
    }
    private static function redirectStudent(string $returnTo):never{
        $view=$returnTo==='home'?'home':'account';
        self::redirect(add_query_arg(array('portal-view'=>$view),home_url('/student-portal/')));
    }
    private static function redirectTeacher(string $returnTo):never{
        $view=$returnTo==='settings'?'settings':'onboarding';
        self::redirect(add_query_arg(array('teacher-view'=>$view),home_url('/teacher-portal/')));
    }
    private static function redirect(string $url):never{
        nocache_headers();
        if(wp_safe_redirect($url))exit;
        wp_die('Redirect unavailable.','Delnavazan',array('response'=>400));
    }
}
