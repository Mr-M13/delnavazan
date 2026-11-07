<?php
namespace Delnavazan\Platform\Portals;

use Delnavazan\Platform\Core\Application\ConsentPurposeRegistry;
use Delnavazan\Platform\Core\Application\RecordingDecisionAuthorityService;

/**
 * Display-ready projection over the append-only consent decision authority.
 *
 * This service resolves no identity from the request and writes no decision. It exposes the
 * authenticated principal's current state and the published notice versions that a
 * controller-protected form may cite. A minor is shown only through a verified guardian grant.
 */
final class RecordingDecisionUiReadService {
    private RecordingDecisionAuthorityService $authority;

    public function __construct(?RecordingDecisionAuthorityService $authority=null){
        $this->authority=$authority??new RecordingDecisionAuthorityService();
    }

    /** Student/guardian enrolment and Account Settings projection. */
    public function student(string $context='account',string $preferredLanguage='en'):array {
        $context=$context==='enrolment'?'enrolment':'account';
        $language=$this->language($preferredLanguage);
        $state=$this->authority->studentDecisionState();
        $recordingNotice=$this->notice(ConsentPurposeRegistry::audience(ConsentPurposeRegistry::STUDENT_RECORDING),$language);
        $researchNotice=$this->notice(ConsentPurposeRegistry::audience(ConsentPurposeRegistry::STUDENT_RESEARCH),$language);
        $privacyNotice=$this->notice(ConsentPurposeRegistry::audience(ConsentPurposeRegistry::PRIVACY_TERMS),$language);
        $forms=array();
        if(!empty($state['available'])){
            if($recordingNotice!==null)$forms['recording']=$this->form(ConsentPurposeRegistry::STUDENT_RECORDING,$recordingNotice,'dzn_recording_student_choice',$context);
            if($researchNotice!==null)$forms['research']=$this->form(ConsentPurposeRegistry::STUDENT_RESEARCH,$researchNotice,'dzn_recording_student_choice',$context);
            if($privacyNotice!==null)$forms['privacy']=$this->form(ConsentPurposeRegistry::PRIVACY_TERMS,$privacyNotice,'dzn_recording_student_choice',$context);
        }
        $consentActive=!empty($state['recording']['positive'])&&!empty($state['research']['positive']);
        $unified=array('available'=>false,'consent_active'=>$consentActive,'action_url'=>'','nonce_action'=>'dzn_unified_student_consent','withdraw_action_url'=>'','withdraw_nonce_action'=>'dzn_unified_student_withdraw','idempotency_key'=>'','withdraw_idempotency_key'=>'','language'=>$language,'return_to'=>$context==='enrolment'?'home':'account','notices'=>array());
        if(!empty($state['available'])&&$recordingNotice!==null&&$researchNotice!==null&&$privacyNotice!==null){
            $unified['available']=true;
            $unified['action_url']=admin_url('admin-post.php?action=dzn_unified_student_consent');
            $unified['withdraw_action_url']=admin_url('admin-post.php?action=dzn_unified_student_withdraw');
            $unified['idempotency_key']=wp_generate_uuid4();
            $unified['withdraw_idempotency_key']=wp_generate_uuid4();
            $unified['notices']=array(
                ConsentPurposeRegistry::STUDENT_RECORDING=>$recordingNotice,
                ConsentPurposeRegistry::STUDENT_RESEARCH=>$researchNotice,
                ConsentPurposeRegistry::PRIVACY_TERMS=>$privacyNotice,
            );
        }
        return array(
            'available'=>!empty($state['available']),
            'reason'=>$state['reason']??null,
            'context'=>$context,
            'authority'=>$state['authority']??null,
            'guardian_grant_id'=>$state['guardian_grant_id']??null,
            'recording'=>$state['recording']??$this->emptyChoice(),
            'research'=>$state['research']??$this->emptyChoice(),
            'capture_eligible'=>!empty($state['capture_eligible']),
            'recording_notice'=>$recordingNotice,
            'research_notice'=>$researchNotice,
            'privacy_notice'=>$privacyNotice,
            'forms'=>$forms,
            'unified'=>$unified,
            'consent_active'=>$consentActive,
            'default_recording_choice'=>null,
            'default_research_choice'=>null,
            'information_url'=>$this->informationUrl(),
            'policy_urls'=>$this->policyUrls(),
            'status'=>$this->status('student'),
        );
    }

    /** Teacher onboarding and settings projection. */
    public function teacher(string $context='onboarding'):array {
        $context=$context==='settings'?'settings':'onboarding';
        $state=$this->authority->teacherConsentState();
        $termsNotices=$this->notices(ConsentPurposeRegistry::TEACHER_TERMS);
        $recordingNotices=$this->notices(ConsentPurposeRegistry::TEACHER_RECORDING);
        $researchNotices=$this->notices(ConsentPurposeRegistry::TEACHER_RESEARCH);
        $privacyNotices=$this->notices(ConsentPurposeRegistry::PRIVACY_TERMS);
        $forms=array();
        foreach($termsNotices as$notice)$forms['terms'][]=$this->form(ConsentPurposeRegistry::TEACHER_TERMS,$notice,'dzn_recording_teacher_terms',$context);
        foreach($recordingNotices as$notice)$forms['recording'][]=$this->form(ConsentPurposeRegistry::TEACHER_RECORDING,$notice,'dzn_recording_teacher_choice',$context);
        foreach($researchNotices as$notice)$forms['research'][]=$this->form(ConsentPurposeRegistry::TEACHER_RESEARCH,$notice,'dzn_recording_teacher_choice',$context);
        foreach($privacyNotices as$notice)$forms['privacy'][]=$this->form(ConsentPurposeRegistry::PRIVACY_TERMS,$notice,'dzn_recording_teacher_choice',$context);
        $mandatoryAccepted=!empty($state['terms']['positive'])&&!empty($state['recording']['positive']);
        $unified=$this->teacherUnified($state['available']??false,$context,$termsNotices,$recordingNotices,$researchNotices,$privacyNotices,$mandatoryAccepted);
        return array(
            'available'=>!empty($state['available'])&&$termsNotices!==array(),
            'linked'=>!empty($state['available']),
            'reason'=>$state['reason']??null,
            'accepted'=>!empty($state['terms']['positive']),
            'mandatory_accepted'=>$mandatoryAccepted,
            'accepted_at'=>$state['terms']['decided_at']??null,
            'notice_uid'=>$state['terms']['notice_uid']??null,
            'notice_version'=>$state['terms']['notice_version']??null,
            'language'=>$state['terms']['language']??null,
            'terms'=>$state['terms']??$this->emptyChoice(),
            'recording'=>$state['recording']??$this->emptyChoice(),
            'research'=>$state['research']??$this->emptyChoice(),
            'notices'=>$forms['terms']??array(),
            'recording_notices'=>$recordingNotices,
            'research_notices'=>$researchNotices,
            'privacy_notices'=>$privacyNotices,
            'forms'=>$forms,
            'unified'=>$unified,
            'information_url'=>$this->informationUrl(),
            'policy_urls'=>$this->policyUrls(),
            'status'=>$this->status('teacher'),
        );
    }

    private function teacherUnified(bool $linked,string $context,$termsNotices,$recordingNotices,$researchNotices,$privacyNotices,bool $mandatoryAccepted):array{
        $locale=function_exists('get_user_locale')?(string)get_user_locale():'en';
        $preferred=str_starts_with(strtolower($locale),'fa')?'fa':'en';
        $pick=function($notices)use($preferred){
            foreach((array)$notices as $notice){
                if(is_array($notice)&&(string)($notice['language']??'')===$preferred)return $notice;
            }
            foreach((array)$notices as $notice){
                if(is_array($notice))return $notice;
            }
            return null;
        };
        $terms=$pick($termsNotices);$recording=$pick($recordingNotices);$research=$pick($researchNotices);$privacy=$pick($privacyNotices);
        $available=$linked&&$terms!==null&&$recording!==null&&$research!==null;
        $unified=array('available'=>$available,'mandatory_accepted'=>$mandatoryAccepted,'action_url'=>'','nonce_action'=>'dzn_unified_teacher_consent','idempotency_key'=>'','language'=>$preferred,'return_to'=>$context,'notices'=>array());
        if($available){
            $unified['action_url']=admin_url('admin-post.php?action=dzn_unified_teacher_consent');
            $unified['idempotency_key']=wp_generate_uuid4();
            $unified['notices']=array(
                ConsentPurposeRegistry::TEACHER_TERMS=>$terms,
                ConsentPurposeRegistry::TEACHER_RECORDING=>$recording,
                ConsentPurposeRegistry::TEACHER_RESEARCH=>$research,
                ConsentPurposeRegistry::PRIVACY_TERMS=>$privacy,
            );
        }
        return $unified;
    }

    private function notices(string $purpose):array {
        $out=array();
        $audience=ConsentPurposeRegistry::audience($purpose);
        foreach(array('fa','en')as$language){
            $notice=$this->authority->publishedNotice($audience,$language);
            if($notice!==null)$out[]=$notice;
        }
        return$out;
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
            'return_to'=>in_array($context,array('onboarding','settings'),true)?$context:($context==='enrolment'?'home':'account'),
        );
    }

    private function language(string $language):string { return $language==='fa'?'fa':'en'; }
    private function informationUrl():string { return home_url('/recording-and-research/'); }
    private function policyUrls():array{return array('privacy'=>home_url('/privacy-policy/'),'recording_ai'=>home_url('/recording-and-ai-policy/'),'terms'=>home_url('/terms-and-conditions/'),'recording_research'=>home_url('/recording-and-research/'));}
    private function emptyChoice():array{return array('decision'=>null,'decided_at'=>null,'notice_uid'=>null,'notice_version'=>null,'language'=>null,'decision_id'=>null,'current'=>false,'positive'=>false);}
    private function status(string $scope):string {
        $key='dzn_recording_status_'.$scope.'_'.get_current_user_id();
        $status=sanitize_key((string)get_transient($key));
        if($status!=='')delete_transient($key);
        return $status;
    }
}
