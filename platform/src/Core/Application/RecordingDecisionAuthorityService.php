<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Canonical append-only consent decision authority.
 *
 * The service records evidence only. It owns no capture, provider, asset, export or user-interface
 * action. Public controllers call the principal methods below; teacher, student, guardian and actor
 * identifiers are always resolved from authenticated server-side links, never from the browser.
 *
 * Recording and machine-learning research are independent purposes. Neither grant implies the other.
 * A later choice is a new decision row; it never rewrites an earlier decision.
 */
final class RecordingDecisionAuthorityService {
    public const DOMAIN='recording_decision_v3';
    public const EVIDENCE_KEY_VERSION='recording_decision_evidence_v3';
    public const AUDIENCE_TEACHER_TERMS='teacher_terms';
    public const AUDIENCE_TEACHER_RECORDING='teacher_lesson_recording';
    public const AUDIENCE_TEACHER_RESEARCH='teacher_research_model_training';
    public const AUDIENCE_STUDENT_RECORDING='student_lesson_recording';
    public const AUDIENCE_STUDENT_RESEARCH='student_research_model_training';
    public const AUDIENCE_PRIVACY_TERMS='privacy_terms';
    public const PURPOSES=array(
        ConsentPurposeRegistry::TEACHER_TERMS,
        ConsentPurposeRegistry::TEACHER_RECORDING,
        ConsentPurposeRegistry::TEACHER_RESEARCH,
        ConsentPurposeRegistry::STUDENT_RECORDING,
        ConsentPurposeRegistry::STUDENT_RESEARCH,
        ConsentPurposeRegistry::PRIVACY_TERMS,
    );
    /** Capture and research export remain disabled until explicitly approved. This slice builds the enforced gates only. */
    public const CAPTURE_ENABLED=false;
    public const RESEARCH_EXPORT_ENABLED=false;
    public const GUARDIAN_SCOPE='service_acceptance';

    public function acceptTeacherTerms(string $noticeUid,string $language,string $key):array {
        return $this->recordTeacherChoice(ConsentPurposeRegistry::TEACHER_TERMS,'accepted',$noticeUid,$language,$key,'teacher_onboarding','teacher_onboarding');
    }

    /** Teacher recording or research decision. The Teacher is resolved from the authenticated principal. */
    public function recordTeacherChoice(string $purpose,string $choice,string $noticeUid,string $language,string $key,string $channel='portal_settings',string $workflow='teacher_portal'):array {
        $this->assertPurposeSubject($purpose,'teacher');
        if(!ConsentPurposeRegistry::isChoice($purpose,$choice))throw new \InvalidArgumentException('recording_decision_choice_invalid');
        $actor=$this->actor();$teacher=$this->resolveTeacher($actor);
        return $this->record('teacher',$teacher,null,null,null,$purpose,$choice,$noticeUid,$language,$key,$actor,$channel,$workflow);
    }

    /** Adult Student's own explicit prospective choice. No student ID is accepted from a caller. */
    public function recordOwnStudentChoice(string $purpose,string $choice,string $noticeUid,string $language,string $key,string $channel='portal_settings',string $workflow='student_portal'):array {
        $this->assertPurposeSubject($purpose,'student');
        if(!ConsentPurposeRegistry::isChoice($purpose,$choice))throw new \InvalidArgumentException('recording_decision_choice_invalid');
        $actor=$this->actor();
        [$studentId,$guardianActor,$grantId,$grantVersion]=$this->resolveStudentDecisionAuthority($actor);
        return $this->record('student',$studentId,$guardianActor,$grantId,$grantVersion,$purpose,$choice,$noticeUid,$language,$key,$actor,$channel,$workflow);
    }

    /**
     * Guardian decision for the minor Student resolved from the active guardian grant.
     * The browser supplies no student or guardian identifier.
     */
    public function recordGuardianChoiceForResolvedStudent(string $purpose,string $choice,string $noticeUid,string $language,string $key,string $channel='portal_settings',string $workflow='guardian_portal'):array {
        $this->assertPurposeSubject($purpose,'student');
        if(!ConsentPurposeRegistry::guardianAllowed($purpose))throw new \RuntimeException('recording_guardian_scope_unavailable');
        if(!ConsentPurposeRegistry::isChoice($purpose,$choice))throw new \InvalidArgumentException('recording_decision_choice_invalid');
        $actor=$this->actor();$grant=$this->resolveGuardianGrant($actor);
        return $this->record('student',(int)$grant->student_id,$actor,(int)$grant->id,(int)$grant->version,$purpose,$choice,$noticeUid,$language,$key,$actor,$channel,$workflow);
    }

    /** Reserved for a future approved legal default-on policy; never available in this slice. */
    public function recordApprovedOptOutBasisForResolvedStudent(string $noticeUid,string $key):array {
        $this->actor(); throw new \RuntimeException('recording_default_on_policy_unavailable');
    }

    /** Current Teacher terms state for the authenticated principal. */
    public function teacherTermsState():array {
        $state=$this->teacherConsentState();
        return array(
            'available'=>(bool)$state['available'],
            'reason'=>$state['reason'],
            'accepted'=>(bool)$state['terms']['current']&&$state['terms']['decision']==='accepted',
            'accepted_at'=>$state['terms']['decided_at'],
            'notice_uid'=>$state['terms']['notice_uid'],
            'notice_version'=>$state['terms']['notice_version'],
            'language'=>$state['terms']['language'],
        );
    }

    /** Current Teacher terms, recording and research state. */
    public function teacherConsentState():array {
        $actor=$this->actor();
        try{$teacher=$this->resolveTeacher($actor);}catch(\RuntimeException $e){
            return $this->emptyTeacherState($e->getMessage());
        }
        return $this->teacherState($teacher);
    }

    /**
     * Current Student recording and research choices for the authenticated principal.
     * Adult Students act for themselves; minors act only through a valid guardian grant.
     */
    public function studentDecisionState():array {
        $actor=$this->actor();
        try{$link=$this->studentLink($actor);}catch(\RuntimeException $e){return $this->emptyStudentState($e->getMessage());}
        if($link!==null&&(string)$link->classification==='adult'){
            return $this->studentState((int)$link->student_id,'student',null);
        }
        if($link===null&&$this->hasAnyStudentLink($actor))return $this->emptyStudentState('recording_student_principal_required');
        try{$grant=$this->resolveGuardianGrant($actor);}
        catch(\RuntimeException $e){
            if($link!==null&&in_array((string)$link->classification,array('minor','unknown'),true)){
                return $this->emptyStudentState('recording_student_capacity_unavailable');
            }
            return $this->emptyStudentState($link!==null?'recording_student_capacity_unavailable':'recording_student_principal_required');
        }
        return $this->studentState((int)$grant->student_id,'guardian',$grant);
    }

    /**
     * Lesson-scoped recording eligibility used by the Google Meet classroom authority.
     *
     * Read-only legal gate. Recording may be configured only when all purposes that the canonical
     * ConsentPurposeRegistry marks as required for recording are current and positive for the exact
     * Lesson participants: Teacher terms, Teacher lesson-recording consent and Student
     * lesson-recording consent (the Student row may be recorded by a valid guardian grant).
     * Research permission is independent and never enables recording or export.
     */
    public function lessonRecordingEligibility(int $lessonId):array {
        global $wpdb; $p=$wpdb->prefix.'dzn_';
        $lesson=$wpdb->get_row($wpdb->prepare("SELECT id,student_id,teacher_id FROM {$p}lessons WHERE id=%d AND archived_at IS NULL LIMIT 1",$lessonId));
        if(!$lesson)return $this->eligibility(false,'lesson_not_found',null,null);
        $required=array(
            ConsentPurposeRegistry::TEACHER_TERMS,
            ConsentPurposeRegistry::TEACHER_RECORDING,
            ConsentPurposeRegistry::STUDENT_RECORDING,
        );
        foreach($required as $purpose){
            if(!ConsentPurposeRegistry::requiresRecordingConsent($purpose))return $this->eligibility(false,'recording_purpose_not_required',null,null);
        }
        $teacherTerms=$this->latestDecision('teacher',(int)$lesson->teacher_id,ConsentPurposeRegistry::TEACHER_TERMS,false);
        if(!$teacherTerms||!ConsentPurposeRegistry::isPositive(ConsentPurposeRegistry::TEACHER_TERMS,(string)$teacherTerms->decision_code)||!$this->noticeIsCurrent((int)$teacherTerms->notice_version_id))return $this->eligibility(false,'teacher_terms_not_current',$teacherTerms,null);
        $teacherRecording=$this->latestDecision('teacher',(int)$lesson->teacher_id,ConsentPurposeRegistry::TEACHER_RECORDING,false);
        if(!$teacherRecording||!ConsentPurposeRegistry::isPositive(ConsentPurposeRegistry::TEACHER_RECORDING,(string)$teacherRecording->decision_code)||!$this->noticeIsCurrent((int)$teacherRecording->notice_version_id))return $this->eligibility(false,'teacher_recording_not_allowed',$teacherRecording,null);
        $student=$this->latestDecision('student',(int)$lesson->student_id,ConsentPurposeRegistry::STUDENT_RECORDING,false);
        if(!$student||!ConsentPurposeRegistry::isPositive(ConsentPurposeRegistry::STUDENT_RECORDING,(string)$student->decision_code)||!$this->noticeIsCurrent((int)$student->notice_version_id))return $this->eligibility(false,'student_recording_not_allowed',$student,null);
        $capacity=(string)$wpdb->get_var($wpdb->prepare("SELECT c.classification FROM {$p}students s LEFT JOIN {$p}student_acceptance_capacity_classifications c ON c.id=s.current_acceptance_capacity_classification_id AND c.student_id=s.id WHERE s.id=%d AND s.status='active' AND s.archived_at IS NULL LIMIT 1",(int)$lesson->student_id));
        $guardianGrantId=(int)($student->guardian_authority_grant_id??0);
        $guardianVersion=(int)($student->guardian_authority_version??0);
        $guardianActor=(int)($student->guardian_actor_user_id??0);
        if($capacity==='minor'){
            if($guardianGrantId<1||$guardianActor<1)return $this->eligibility(false,'student_guardian_authority_required',$student,$teacherRecording);
            $grant=$wpdb->get_row($wpdb->prepare("SELECT id,student_id,version,state,active_slot,effective_from,effective_until FROM {$p}student_acceptance_authority_grants WHERE id=%d LIMIT 1",$guardianGrantId));
            if(!$grant||(int)$grant->student_id!==(int)$lesson->student_id||(string)$grant->state!=='active'||(int)$grant->active_slot!==1||(int)$grant->version!==$guardianVersion)return $this->eligibility(false,'student_guardian_authority_not_current',$student,$teacherRecording);
            $now=gmdate('Y-m-d H:i:s');
            if((string)$grant->effective_from>$now||($grant->effective_until!==null&&(string)$grant->effective_until<=$now))return $this->eligibility(false,'student_guardian_authority_not_current',$student,$teacherRecording);
        }elseif($capacity!=='adult'){
            return $this->eligibility(false,'student_capacity_unavailable',$student,$teacherRecording);
        }elseif($guardianGrantId>0||$guardianActor>0){
            return $this->eligibility(false,'student_guardian_authority_invalid',$student,$teacherRecording);
        }
        return $this->eligibility(true,null,$student,$teacherRecording);
    }

    /** Current published notice for a supported audience, or null when none is approved. */
    public function publishedNotice(string $audience,string $language):?array {
        $audiences=array(
            self::AUDIENCE_TEACHER_TERMS,self::AUDIENCE_TEACHER_RECORDING,self::AUDIENCE_TEACHER_RESEARCH,
            self::AUDIENCE_STUDENT_RECORDING,self::AUDIENCE_STUDENT_RESEARCH,self::AUDIENCE_PRIVACY_TERMS,
        );
        if(!in_array($audience,$audiences,true))throw new \InvalidArgumentException('recording_notice_audience_invalid');
        if(!in_array($language,array('en','fa'),true))throw new \InvalidArgumentException('recording_decision_language_invalid');
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');
        $row=$wpdb->get_row($wpdb->prepare("SELECT uid,audience_code,language_code,version_number,body_text,published_at,expires_at FROM {$p}recording_notice_versions WHERE audience_code=%s AND language_code=%s AND publication_state='published' AND published_at IS NOT NULL AND published_at<=%s AND (expires_at IS NULL OR expires_at>%s) AND withdrawn_at IS NULL ORDER BY version_number DESC LIMIT 1",$audience,$language,$now,$now));
        return $row?array('uid'=>(string)$row->uid,'audience'=>(string)$row->audience_code,'language'=>(string)$row->language_code,'version'=>(int)$row->version_number,'body'=>(string)$row->body_text,'published_at'=>(string)$row->published_at,'expires_at'=>$row->expires_at):null;
    }

    /** Guardian authority reference for the authenticated guardian, without exposing the minor. */
    public function guardianAuthorityState():array {
        $actor=(int)get_current_user_id();
        if($actor<1||!is_user_logged_in())return array('available'=>false,'reason'=>'recording_authenticated_principal_required');
        try{$grant=$this->resolveGuardianGrant($actor);}catch(\RuntimeException $e){return array('available'=>false,'reason'=>$e->getMessage());}
        return array('available'=>true,'reason'=>null,'grant_id'=>(int)$grant->id,'version'=>(int)$grant->version,'student_id'=>(int)$grant->student_id);
    }

    private function assertPurposeSubject(string $purpose,string $subject):void {
        if(!ConsentPurposeRegistry::has($purpose))throw new \InvalidArgumentException('recording_decision_purpose_invalid');
        $scope=(string)ConsentPurposeRegistry::get($purpose)['subject_scope'];
        if($scope!==$subject&&$scope!=='any')throw new \InvalidArgumentException('recording_decision_purpose_invalid');
    }

    private function record(string $subjectType,int $subjectId,?int $guardianActor,?int $grantId,?int $grantVersion,string $purpose,string $choice,string $noticeUid,string $language,string $key,int $actor,string $channel,string $workflow):array {
        global $wpdb; $p=$wpdb->prefix.'dzn_'; $keyDigest=$this->digest('command:'.$key); $now=gmdate('Y-m-d H:i:s');
        if($key===''||!preg_match('/^[A-Za-z0-9:_-]{12,160}$/D',$key))throw new \InvalidArgumentException('recording_decision_key_required');
        if(!in_array($language,array('en','fa'),true))throw new \InvalidArgumentException('recording_decision_language_invalid');
        if(!preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D',$noticeUid))throw new \RuntimeException('recording_notice_unavailable');
        $channel=$this->code($channel,'portal_settings');
        $workflow=$this->code($workflow,'portal_consent');
        $encoded=wp_json_encode(array('v'=>3,'actor'=>$actor,'subject_type'=>$subjectType,'subject_id'=>$subjectId,'guardian_actor'=>$guardianActor,'guardian_grant'=>$grantId,'guardian_version'=>$grantVersion,'purpose'=>$purpose,'choice'=>$choice,'notice'=>$noticeUid,'language'=>$language,'channel'=>$channel,'workflow'=>$workflow));
        if($encoded===false)throw new \InvalidArgumentException('recording_decision_payload_invalid');
        $payload=$this->digest($encoded);
        if($wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('recording_decision_transaction_failed');
        try {
            if($subjectType==='student'){
                if($guardianActor!==null)$this->lockGuardianStudent($subjectId,$guardianActor,(int)$grantId);
                else $this->lockAdultStudent($subjectId,$actor);
            } else $this->lockTeacher($subjectId,$actor);
            $prior=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recording_decision_commands WHERE command_key_digest=%s FOR UPDATE",$keyDigest));
            if($prior){
                if(!hash_equals((string)$prior->command_payload_digest,$payload))throw new \RuntimeException('recording_decision_replay_conflict');
                $stored=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recording_decisions WHERE id=%d FOR UPDATE",(int)$prior->result_decision_id));
                if(!$stored||(string)$prior->result_state!==$choice||(string)$stored->subject_type!==$subjectType||(int)$stored->subject_id!==$subjectId||(string)$stored->purpose_code!==$purpose||(string)$stored->decision_code!==$choice||(string)$stored->notice_uid!==$noticeUid||(string)$stored->notice_language_code!==$language)throw new \RuntimeException('recording_decision_replay_conflict');
                if($wpdb->query('COMMIT')===false)throw new \RuntimeException('recording_decision_transaction_failed');
                return array('decision_id'=>(int)$stored->id,'state'=>(string)$stored->decision_code,'decided_at'=>(string)$stored->decided_at,'idempotent'=>true);
            }
            $notice=$this->noticeForDecision($noticeUid,$language,$subjectType,$purpose);
            $previous=$this->latestDecision($subjectType,$subjectId,$purpose,$guardianActor!==null);
            $withdrawal=ConsentPurposeRegistry::isWithdrawal($purpose,$choice)&&$previous!==null&&ConsentPurposeRegistry::isPositive($purpose,(string)$previous->decision_code)?(int)$previous->id:null;
            $inserted=$wpdb->insert($p.'recording_decisions',array(
                'uid'=>Identifier::uid(),'subject_type'=>$subjectType,'subject_id'=>$subjectId,
                'guardian_actor_user_id'=>$guardianActor,'guardian_authority_grant_id'=>$grantId,'guardian_authority_version'=>$grantVersion,
                'purpose_code'=>$purpose,'decision_code'=>$choice,
                'notice_version_id'=>(int)$notice->id,'notice_uid'=>(string)$notice->uid,
                'notice_audience_code'=>(string)$notice->audience_code,'notice_language_code'=>(string)$notice->language_code,
                'notice_version_number'=>(int)$notice->version_number,'notice_text_digest'=>(string)$notice->text_digest,
                'consent_statement_digest'=>(string)$notice->text_digest,
                'evidence_key_version'=>self::EVIDENCE_KEY_VERSION,'evidence_digest'=>$this->digest('decision:'.$payload.':'.$now),
                'evidence_action'=>'explicit_submission','collection_channel'=>$channel,'workflow_code'=>$workflow,
                'scope_code'=>ConsentPurposeRegistry::scopeCode($purpose),
                'supersedes_decision_id'=>$previous?(int)$previous->id:null,'withdrawal_of_decision_id'=>$withdrawal,
                'effective_from'=>$now,'effective_until'=>null,
                'decided_at'=>$now,'decided_by_user_id'=>$actor,'created_at'=>$now,
            ));
            if($inserted===false)throw new \RuntimeException('recording_decision_persistence_failed');
            $id=(int)$wpdb->insert_id; if($id<1)throw new \RuntimeException('recording_decision_persistence_failed');
            if($wpdb->insert($p.'recording_decision_commands',array('uid'=>Identifier::uid(),'domain'=>self::DOMAIN,'operation'=>'record','command_key_digest'=>$keyDigest,'command_payload_digest'=>$payload,'result_decision_id'=>$id,'result_state'=>$choice,'created_at'=>$now,'created_by'=>$actor))===false)throw new \RuntimeException('recording_decision_command_persistence_failed');
            if((int)$wpdb->insert_id<1)throw new \RuntimeException('recording_decision_command_persistence_failed');
            if($wpdb->query('COMMIT')===false)throw new \RuntimeException('recording_decision_transaction_failed');
            do_action('dzn_consent_decision_recorded',$subjectType,$subjectId,$purpose,$choice,$id);
            return array('decision_id'=>$id,'state'=>$choice,'decided_at'=>$now,'idempotent'=>false);
        } catch(\Throwable $e){$wpdb->query('ROLLBACK');throw $e;}
    }

    private function noticeForDecision(string $noticeUid,string $language,string $subjectType,string $purpose):object {
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');
        $audience=ConsentPurposeRegistry::audience($purpose);
        $notice=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recording_notice_versions WHERE uid=%s AND language_code=%s AND audience_code=%s AND publication_state='published' AND published_at IS NOT NULL AND published_at<=%s AND (expires_at IS NULL OR expires_at>%s) AND withdrawn_at IS NULL FOR UPDATE",$noticeUid,$language,$audience,$now,$now));
        if(!$notice||(string)$notice->audience_code!==$audience)throw new \RuntimeException('recording_notice_unavailable');
        if(!$this->noticeVersionIsCurrent($audience,$language,(int)$notice->version_number))throw new \RuntimeException('recording_notice_unavailable');
        return $notice;
    }

    private function noticeVersionIsCurrent(string $audience,string $language,int $version):bool{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');
        return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}recording_notice_versions WHERE audience_code=%s AND language_code=%s AND version_number>%d AND publication_state='published' AND published_at IS NOT NULL AND published_at<=%s AND (expires_at IS NULL OR expires_at>%s) AND withdrawn_at IS NULL",$audience,$language,$version,$now,$now))===0;
    }

    private function actor():int{$id=(int)get_current_user_id();if($id<1||!is_user_logged_in())throw new \RuntimeException('recording_authenticated_principal_required');return $id;}

    private function resolveTeacher(int $user):int{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $rows=(array)$wpdb->get_col($wpdb->prepare("SELECT l.teacher_id FROM {$p}teacher_principal_links l INNER JOIN {$p}teachers t ON t.id=l.teacher_id WHERE l.wordpress_user_id=%d AND l.status='active' AND l.revoked_at IS NULL AND t.status='active' AND t.archived_at IS NULL",$user));
        if(count($rows)===0)throw new \RuntimeException('recording_teacher_principal_required');
        if(count($rows)!==1)throw new \RuntimeException('recording_teacher_principal_ambiguous');
        return(int)$rows[0];
    }

    private function studentLink(int $user):?object{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT l.student_id,c.classification FROM {$p}student_principal_links l INNER JOIN {$p}students s ON s.id=l.student_id LEFT JOIN {$p}student_acceptance_capacity_classifications c ON c.id=s.current_acceptance_capacity_classification_id AND c.student_id=s.id WHERE l.wordpress_user_id=%d AND l.status='active' AND l.active_slot=1 AND l.revoked_at IS NULL AND s.status='active' AND s.archived_at IS NULL",$user));
        if(count($rows)===0)return null;
        if(count($rows)!==1)throw new \RuntimeException('recording_student_principal_ambiguous');
        return$rows[0];
    }

    /** @return array{0:int,1:?int,2:?int,3:?int} studentId, guardianActor, grantId, grantVersion */
    private function resolveStudentDecisionAuthority(int $actor):array{
        $link=$this->studentLink($actor);
        if($link!==null){
            if((string)$link->classification!=='adult')throw new \RuntimeException('recording_student_capacity_unavailable');
            return array((int)$link->student_id,null,null,null);
        }
        if($this->hasAnyStudentLink($actor))throw new \RuntimeException('recording_student_principal_required');
        try{$grant=$this->resolveGuardianGrant($actor);}catch(\RuntimeException $e){
            if($e->getMessage()==='recording_guardian_authority_required'&&!$this->hasAnyGuardianGrant($actor))throw new \RuntimeException('recording_student_principal_required');
            throw $e;
        }
        return array((int)$grant->student_id,$actor,(int)$grant->id,(int)$grant->version);
    }

    private function hasAnyStudentLink(int $user):bool{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}student_principal_links WHERE wordpress_user_id=%d",$user))>0;
    }

    private function hasAnyGuardianGrant(int $user):bool{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}student_acceptance_authority_grants WHERE acting_wordpress_user_id=%d AND authority_type='guardian_representative' AND authority_scope=%s",$user,self::GUARDIAN_SCOPE))>0;
    }

    private function resolveGuardianGrant(int $actor):object{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT g.id,g.student_id,g.version,c.classification FROM {$p}student_acceptance_authority_grants g INNER JOIN {$p}students s ON s.id=g.student_id LEFT JOIN {$p}student_acceptance_capacity_classifications c ON c.id=s.current_acceptance_capacity_classification_id AND c.student_id=s.id WHERE g.acting_wordpress_user_id=%d AND g.authority_type='guardian_representative' AND g.authority_scope=%s AND g.state='active' AND g.active_slot=1 AND g.effective_from<=%s AND (g.effective_until IS NULL OR g.effective_until>%s) AND s.status='active' AND s.archived_at IS NULL",$actor,self::GUARDIAN_SCOPE,$now,$now));
        if(count($rows)===0)throw new \RuntimeException('recording_guardian_authority_required');
        if(count($rows)!==1)throw new \RuntimeException('recording_guardian_authority_ambiguous');
        if((string)$rows[0]->classification!=='minor')throw new \RuntimeException('recording_guardian_authority_invalid');
        return$rows[0];
    }

    private function lockTeacher(int $teacher,int $actor):void{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $rows=(array)$wpdb->get_col($wpdb->prepare("SELECT l.teacher_id FROM {$p}teacher_principal_links l INNER JOIN {$p}teachers t ON t.id=l.teacher_id WHERE l.wordpress_user_id=%d AND l.teacher_id=%d AND l.status='active' AND l.revoked_at IS NULL AND t.status='active' AND t.archived_at IS NULL FOR UPDATE",$actor,$teacher));
        if(count($rows)===0)throw new \RuntimeException('recording_teacher_principal_required');
        if(count($rows)!==1)throw new \RuntimeException('recording_teacher_principal_ambiguous');
    }

    private function lockAdultStudent(int $student,int $actor):void{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT s.id,c.classification FROM {$p}student_principal_links l INNER JOIN {$p}students s ON s.id=l.student_id LEFT JOIN {$p}student_acceptance_capacity_classifications c ON c.id=s.current_acceptance_capacity_classification_id AND c.student_id=s.id WHERE l.wordpress_user_id=%d AND l.student_id=%d AND l.status='active' AND l.active_slot=1 AND l.revoked_at IS NULL AND s.status='active' AND s.archived_at IS NULL FOR UPDATE",$actor,$student));
        if(count($rows)===0)throw new \RuntimeException('recording_student_principal_required');
        if(count($rows)!==1)throw new \RuntimeException('recording_student_principal_ambiguous');
        if((string)$rows[0]->classification!=='adult')throw new \RuntimeException('recording_student_capacity_unavailable');
    }

    private function lockGuardianStudent(int $student,int $actor,int $grantId):void{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT g.id,g.version,s.id student_id,c.classification FROM {$p}student_acceptance_authority_grants g INNER JOIN {$p}students s ON s.id=g.student_id LEFT JOIN {$p}student_acceptance_capacity_classifications c ON c.id=s.current_acceptance_capacity_classification_id AND c.student_id=s.id WHERE g.id=%d AND g.student_id=%d AND g.acting_wordpress_user_id=%d AND g.authority_type='guardian_representative' AND g.authority_scope=%s AND g.state='active' AND g.active_slot=1 AND g.effective_from<=%s AND (g.effective_until IS NULL OR g.effective_until>%s) AND s.status='active' AND s.archived_at IS NULL FOR UPDATE",$grantId,$student,$actor,self::GUARDIAN_SCOPE,$now,$now));
        if(count($rows)!==1)throw new \RuntimeException('recording_guardian_authority_required');
        if((string)$rows[0]->classification!=='minor')throw new \RuntimeException('recording_guardian_authority_invalid');
    }

    private function eligibility(bool $eligible,?string $reason,?object $student,?object $teacher):array{
        return array(
            'eligible'=>$eligible,'reason'=>$reason,'evaluated_at'=>gmdate('Y-m-d H:i:s'),
            'student_decision_id'=>$student?(int)$student->id:null,
            'student_notice_uid'=>$student?(string)$student->notice_uid:null,
            'student_notice_version'=>$student?(int)$student->notice_version_number:null,
            'teacher_decision_id'=>$teacher?(int)$teacher->id:null,
            'teacher_notice_uid'=>$teacher?(string)$teacher->notice_uid:null,
            'teacher_notice_version'=>$teacher?(int)$teacher->notice_version_number:null,
            'research_export_enabled'=>self::RESEARCH_EXPORT_ENABLED,
        );
    }

    private function latestDecision(string $subjectType,int $subjectId,string $purpose,bool $guardian):?object{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $guardianSql=$guardian?'IS NOT NULL':'IS NULL';
        return$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recording_decisions WHERE subject_type=%s AND subject_id=%d AND purpose_code=%s AND guardian_actor_user_id {$guardianSql} ORDER BY decided_at DESC,id DESC LIMIT 1",$subjectType,$subjectId,$purpose));
    }

    private function noticeIsCurrent(int $noticeId):bool{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');
        $row=$wpdb->get_row($wpdb->prepare("SELECT audience_code,language_code,version_number FROM {$p}recording_notice_versions WHERE id=%d AND publication_state='published' AND published_at IS NOT NULL AND published_at<=%s AND (expires_at IS NULL OR expires_at>%s) AND withdrawn_at IS NULL",$noticeId,$now,$now));
        if(!$row)return false;
        return$this->noticeVersionIsCurrent((string)$row->audience_code,(string)$row->language_code,(int)$row->version_number);
    }

    private function teacherState(int $teacher):array{
        $terms=$this->latestDecision('teacher',$teacher,ConsentPurposeRegistry::TEACHER_TERMS,false);
        $recording=$this->latestDecision('teacher',$teacher,ConsentPurposeRegistry::TEACHER_RECORDING,false);
        $research=$this->latestDecision('teacher',$teacher,ConsentPurposeRegistry::TEACHER_RESEARCH,false);
        return array(
            'available'=>true,'reason'=>null,'teacher_id'=>$teacher,'authority'=>'teacher',
            'terms'=>$this->decisionState($terms,ConsentPurposeRegistry::TEACHER_TERMS),
            'recording'=>$this->decisionState($recording,ConsentPurposeRegistry::TEACHER_RECORDING),
            'research'=>$this->decisionState($research,ConsentPurposeRegistry::TEACHER_RESEARCH),
        );
    }

    private function studentState(int $studentId,string $authority,?object $grant):array{
        $guardian=$authority==='guardian';
        $recording=$this->latestDecision('student',$studentId,ConsentPurposeRegistry::STUDENT_RECORDING,$guardian);
        $research=$this->latestDecision('student',$studentId,ConsentPurposeRegistry::STUDENT_RESEARCH,$guardian);
        return array(
            'available'=>true,'reason'=>null,'student_id'=>$studentId,'authority'=>$authority,
            'guardian_grant_id'=>$grant?(int)$grant->id:null,
            'guardian_grant_version'=>$grant?(int)$grant->version:null,
            'capture_eligible'=>$recording!==null&&(string)$recording->decision_code==='allow'&&$this->noticeIsCurrent((int)$recording->notice_version_id),
            'recording'=>$this->decisionState($recording,ConsentPurposeRegistry::STUDENT_RECORDING),
            'research'=>$this->decisionState($research,ConsentPurposeRegistry::STUDENT_RESEARCH),
            'research_export_enabled'=>self::RESEARCH_EXPORT_ENABLED,
        );
    }

    private function decisionState(?object $row,string $purpose):array{
        if($row===null)return array('decision'=>null,'decided_at'=>null,'notice_uid'=>null,'notice_version'=>null,'language'=>null,'decision_id'=>null,'current'=>false,'positive'=>false);
        $current=$this->noticeIsCurrent((int)$row->notice_version_id);
        return array(
            'decision'=>(string)$row->decision_code,'decided_at'=>(string)$row->decided_at,
            'notice_uid'=>(string)$row->notice_uid,'notice_version'=>(int)$row->notice_version_number,
            'language'=>(string)$row->notice_language_code,'decision_id'=>(int)$row->id,
            'current'=>$current,'positive'=>$current&&ConsentPurposeRegistry::isPositive($purpose,(string)$row->decision_code),
            'guardian_actor_user_id'=>$row->guardian_actor_user_id!==null?(int)$row->guardian_actor_user_id:null,
            'guardian_authority_grant_id'=>$row->guardian_authority_grant_id!==null?(int)$row->guardian_authority_grant_id:null,
            'withdrawal_of_decision_id'=>$row->withdrawal_of_decision_id!==null?(int)$row->withdrawal_of_decision_id:null,
        );
    }

    private function emptyTeacherState(string $reason):array{
        return array('available'=>false,'reason'=>$reason,'teacher_id'=>null,'authority'=>'teacher',
            'terms'=>array('decision'=>null,'decided_at'=>null,'notice_uid'=>null,'notice_version'=>null,'language'=>null,'decision_id'=>null,'current'=>false,'positive'=>false),
            'recording'=>array('decision'=>null,'decided_at'=>null,'notice_uid'=>null,'notice_version'=>null,'language'=>null,'decision_id'=>null,'current'=>false,'positive'=>false),
            'research'=>array('decision'=>null,'decided_at'=>null,'notice_uid'=>null,'notice_version'=>null,'language'=>null,'decision_id'=>null,'current'=>false,'positive'=>false));
    }

    private function emptyStudentState(string $reason):array{
        $empty=array('decision'=>null,'decided_at'=>null,'notice_uid'=>null,'notice_version'=>null,'language'=>null,'decision_id'=>null,'current'=>false,'positive'=>false);
        return array('available'=>false,'reason'=>$reason,'student_id'=>null,'authority'=>null,'guardian_grant_id'=>null,'guardian_grant_version'=>null,'capture_eligible'=>false,'recording'=>$empty,'research'=>$empty,'research_export_enabled'=>self::RESEARCH_EXPORT_ENABLED);
    }

    private function code(string $value,string $fallback):string{
        $value=sanitize_key($value);
        return preg_match('/^[a-z0-9_]{3,48}$/D',$value)===1?$value:$fallback;
    }

    private function digest(string $value):string{return hash('sha256',self::EVIDENCE_KEY_VERSION."\n".$value);}
}
