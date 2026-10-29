<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\RecordingDecisionAuthorityService;

/**
 * Display-ready projection over the disabled recording/research decision authority.
 *
 * This service resolves no identity from the request and writes no decision. It only
 * exposes the authenticated principal's current state and the published notice versions
 * that a controller-protected form may cite.
 */
final class RecordingDecisionUiReadService {
    private RecordingDecisionAuthorityService $authority;

    public function __construct(?RecordingDecisionAuthorityService $authority=null){
        $this->authority=$authority??new RecordingDecisionAuthorityService();
    }

    /** Student enrolment and Account Settings projection. */
    public function student(string $context='account',string $preferredLanguage='en'):array {
        $context=$context==='enrolment'?'enrolment':'account';
        $language=$this->language($preferredLanguage);
        $state=$this->authority->studentDecisionState();
        $recordingNotice=$this->notice(RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RECORDING,$language);
        $researchNotice=$this->notice(RecordingDecisionAuthorityService::AUDIENCE_STUDENT_RESEARCH,$language);
        $forms=array();
        if(!empty($state['available'])){
            if($recordingNotice!==null)$forms['recording']=$this->form('lesson_recording',$recordingNotice,'dzn_recording_student_choice',$context);
            if($researchNotice!==null)$forms['research']=$this->form('research_model_training',$researchNotice,'dzn_recording_student_choice',$context);
        }
        return array(
            'available'=>!empty($state['available']),
            'reason'=>$state['reason']??null,
            'context'=>$context,
            'recording'=>$state['recording']??array('decision'=>null,'decided_at'=>null,'notice_uid'=>null,'notice_version'=>null,'language'=>null),
            'research'=>$state['research']??array('decision'=>null,'decided_at'=>null,'notice_uid'=>null,'notice_version'=>null,'language'=>null),
            'capture_eligible'=>!empty($state['capture_eligible']),
            'recording_notice'=>$recordingNotice,
            'research_notice'=>$researchNotice,
            'forms'=>$forms,
            'default_recording_choice'=>null,
            'default_research_choice'=>null,
            'information_url'=>$this->informationUrl(),
            'status'=>isset($_GET['recording_status'])?sanitize_key((string)wp_unslash($_GET['recording_status'])):'',
        );
    }

    /** Teacher onboarding and settings projection. */
    public function teacher():array {
        $state=$this->authority->teacherTermsState();
        $notices=array();
        foreach(array('en','fa')as$language){
            $notice=$this->notice(RecordingDecisionAuthorityService::AUDIENCE_TEACHER_TERMS,$language);
            if($notice!==null)$notices[]=$this->form('teacher_terms',$notice,'dzn_recording_teacher_terms','onboarding');
        }
        return array(
            'available'=>!empty($state['available'])&&$notices!==array(),
            'linked'=>!empty($state['available']),
            'reason'=>$state['reason']??null,
            'accepted'=>!empty($state['accepted']),
            'accepted_at'=>$state['accepted_at']??null,
            'notice_uid'=>$state['notice_uid']??null,
            'notice_version'=>$state['notice_version']??null,
            'language'=>$state['language']??null,
            'notices'=>$notices,
            'information_url'=>$this->informationUrl(),
            'status'=>isset($_GET['recording_status'])?sanitize_key((string)wp_unslash($_GET['recording_status'])):'',
        );
    }

    private function notice(string $audience,string $preferred):?array {
        $notice=$this->authority->publishedNotice($audience,$preferred);
        if($notice===null){
            $other=$preferred==='fa'?'en':'fa';
            $notice=$this->authority->publishedNotice($audience,$other);
        }
        return $notice;
    }

    private function form(string $purpose,array $notice,string $nonceAction,string $context):array {
        return array(
            'action_url'=>admin_url('admin-post.php?action='.$nonceAction),
            'nonce'=>wp_create_nonce($nonceAction),
            'idempotency_key'=>wp_generate_uuid4(),
            'purpose'=>$purpose,
            'notice_uid'=>$notice['uid'],
            'language'=>$notice['language'],
            'notice_version'=>$notice['version'],
            'body'=>(string)($notice['body']??''),
            'return_to'=>$context==='enrolment'?'home':'account',
        );
    }

    private function language(string $language):string { return $language==='fa'?'fa':'en'; }
    private function informationUrl():string { return home_url('/recording-and-research/'); }
}
