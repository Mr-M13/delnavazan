<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\RecordingDecisionAuthorityService;

/**
 * Capability- and nonce-protected self-service boundary for recording/research choices.
 *
 * The browser submits only the user's action, the published notice identifier and language,
 * and a fresh idempotency key. The actor and subject are always resolved server-side.
 */
final class RecordingDecisionController {
    public const STUDENT_ACTION='dzn_recording_student_choice';
    public const TEACHER_ACTION='dzn_recording_teacher_terms';

    public static function register():void{
        add_action('admin_post_'.self::STUDENT_ACTION,array(__CLASS__,'studentChoice'));
        add_action('admin_post_'.self::TEACHER_ACTION,array(__CLASS__,'teacherTerms'));
    }

    public static function studentChoice():never{
        self::studentGuard();
        $returnTo=self::returnTo($_POST['return_to']??'account');
        $purpose=sanitize_key((string)wp_unslash($_POST['purpose']??''));
        $choice=sanitize_key((string)wp_unslash($_POST['choice']??''));
        try{
            if(!in_array($purpose,RecordingDecisionAuthorityService::PURPOSES,true))throw new \InvalidArgumentException('recording_decision_purpose_invalid');
            if($purpose==='lesson_recording'&&!in_array($choice,array('allow','decline'),true))throw new \InvalidArgumentException('recording_decision_choice_invalid');
            if($purpose==='research_model_training'&&!in_array($choice,array('opt_in','withdraw'),true))throw new \InvalidArgumentException('recording_decision_choice_invalid');
            (new RecordingDecisionAuthorityService())->recordOwnStudentChoice(
                $purpose,
                $choice,
                sanitize_text_field((string)wp_unslash($_POST['notice_uid']??'')),
                sanitize_key((string)wp_unslash($_POST['language']??'')),
                sanitize_text_field((string)wp_unslash($_POST['idempotency_key']??''))
            );
            $status=$purpose==='lesson_recording'?($choice==='allow'?'recording_saved':'recording_declined'):($choice==='opt_in'?'research_saved':'research_withdrawn');
            self::redirectStudent($returnTo,$status);
        }catch(\Throwable){
            self::redirectStudent($returnTo,'recording_unavailable');
        }
    }

    public static function teacherTerms():never{
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
            self::redirectTeacher($returnTo,'terms_saved');
        }catch(\Throwable){
            self::redirectTeacher($returnTo,'terms_unavailable');
        }
    }

    private static function studentGuard():void{
        if(!is_user_logged_in())auth_redirect();
        if(!current_user_can('read'))wp_die('Access denied.','Delnavazan',array('response'=>403));
        check_admin_referer(self::STUDENT_ACTION);
    }

    private static function returnTo(mixed $value):string{
        $value=sanitize_key((string)wp_unslash($value));
        return in_array($value,array('account','home','settings','onboarding'),true)?$value:'account';
    }

    private static function redirectStudent(string $returnTo,string $status):never{
        $view=$returnTo==='home'?'home':'account';
        self::redirect(add_query_arg(array('portal-view'=>$view,'recording_status'=>$status),home_url('/student-portal/')));
    }

    private static function redirectTeacher(string $returnTo,string $status):never{
        $view=$returnTo==='settings'?'settings':'onboarding';
        self::redirect(add_query_arg(array('teacher-view'=>$view,'recording_status'=>$status),home_url('/teacher-portal/')));
    }

    private static function redirect(string $url):never{
        nocache_headers();
        if(wp_safe_redirect($url))exit;
        wp_die('Redirect unavailable.','Delnavazan',array('response'=>400));
    }
}
