<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Disabled prospective recording/research decision authority. It deliberately owns no capture,
 * provider, asset, export or user-interface action. Public controllers must call only the current
 * principal methods below; there is no actor or subject identifier in their input.
 *
 * Evidence is append-only. A later choice is a new decision; it never rewrites an earlier one.
 * Recording and research are independent purposes, and neither purpose grants the other.
 */
final class RecordingDecisionAuthorityService {
    public const DOMAIN='recording_decision_v2';
    public const EVIDENCE_KEY_VERSION='recording_decision_evidence_v2';
    public const AUDIENCE_TEACHER_TERMS='teacher_terms';
    public const AUDIENCE_STUDENT_RECORDING='student_lesson_recording';
    public const AUDIENCE_STUDENT_RESEARCH='student_research_model_training';
    public const PURPOSES=array('lesson_recording','research_model_training');
    /** Capture and research export are not implemented by this slice and cannot be enabled here. */
    public const CAPTURE_ENABLED=false;
    public const RESEARCH_EXPORT_ENABLED=false;

    /** Teacher terms acceptance for the Teacher resolved from the authenticated principal. */
    public function acceptTeacherTerms(string $noticeUid,string $language,string $key):array {
        $actor=$this->actor(); $teacher=$this->resolveTeacher($actor);
        return $this->record('teacher',$teacher,null,'teacher_terms','accepted',$noticeUid,$language,$key,$actor);
    }

    /** Student's own explicit prospective choice. No student ID is accepted from a caller. */
    public function recordOwnStudentChoice(string $purpose,string $choice,string $noticeUid,string $language,string $key):array {
        $actor=$this->actor(); $student=$this->resolveStudent($actor);
        return $this->studentChoice((int)$student->student_id,$actor,null,$purpose,$choice,$noticeUid,$language,$key);
    }

    /** Guardian decisions remain unavailable: service_acceptance is not reviewed for these purposes. */
    public function recordGuardianChoiceForResolvedStudent(int $studentId,string $purpose,string $choice,string $noticeUid,string $language,string $key):array {
        $this->actor(); throw new \RuntimeException('recording_guardian_scope_unavailable');
    }

    /** Reserved for a future approved legal policy; this disabled slice never makes it available. */
    public function recordApprovedOptOutBasisForResolvedStudent(int $studentId,string $noticeUid,string $key):array {
        $this->actor(); throw new \RuntimeException('recording_default_on_policy_unavailable');
    }

    /**
     * Current Teacher terms state for the authenticated principal. Available only through exactly
     * one active Teacher link; no historic Teacher is backfilled by this read.
     */
    public function teacherTermsState():array {
        $actor=$this->actor();
        try{$teacher=$this->resolveTeacher($actor);}catch(\RuntimeException $e){return array('available'=>false,'reason'=>$e->getMessage(),'accepted'=>false,'accepted_at'=>null,'notice_uid'=>null,'notice_version'=>null,'language'=>null);}
        $row=$this->latestDecision('teacher',$teacher,'teacher_terms');
        return array('available'=>true,'reason'=>null,'accepted'=>$row!==null&&(string)$row->decision_code==='accepted','accepted_at'=>$row?->decided_at,'notice_uid'=>$row?->notice_uid,'notice_version'=>$row!==null?(int)$row->version_number:null,'language'=>$row?->language_code);
    }

    /**
     * Current Student recording and research choices. Missing, minor, unknown-capacity, unlinked or
     * ambiguous accounts are not available for a writable self-service form. An unanswered or
     * unavailable recording choice resolves as ineligible for capture.
     */
    public function studentDecisionState():array {
        $actor=$this->actor();
        try{$student=$this->resolveStudent($actor);}catch(\RuntimeException $e){return array('available'=>false,'reason'=>$e->getMessage(),'capture_eligible'=>false,'recording'=>$this->emptyChoice(),'research'=>$this->emptyChoice());}
        $recording=$this->latestDecision('student',(int)$student->student_id,'lesson_recording');
        $research=$this->latestDecision('student',(int)$student->student_id,'research_model_training');
        return array(
            'available'=>true,
            'reason'=>null,
            'capture_eligible'=>$recording!==null&&(string)$recording->decision_code==='allow'&&$this->noticeIsCurrent((int)$recording->notice_version_id),
            'recording'=>$this->choiceState($recording),
            'research'=>$this->choiceState($research),
            'research_export_enabled'=>self::RESEARCH_EXPORT_ENABLED,
        );
    }

    /** The current published notice for a supported audience, or null when none is approved. */
    public function publishedNotice(string $audience,string $language):?array {
        if(!in_array($audience,array(self::AUDIENCE_TEACHER_TERMS,self::AUDIENCE_STUDENT_RECORDING,self::AUDIENCE_STUDENT_RESEARCH),true))throw new \InvalidArgumentException('recording_notice_audience_invalid');
        if(!in_array($language,array('en','fa'),true))throw new \InvalidArgumentException('recording_decision_language_invalid');
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');
        $row=$wpdb->get_row($wpdb->prepare("SELECT uid,audience_code,language_code,version_number,body_text,published_at,expires_at FROM {$p}recording_notice_versions WHERE audience_code=%s AND language_code=%s AND publication_state='published' AND published_at IS NOT NULL AND published_at<=%s AND (expires_at IS NULL OR expires_at>%s) AND withdrawn_at IS NULL ORDER BY version_number DESC LIMIT 1",$audience,$language,$now,$now));
        return $row?array('uid'=>(string)$row->uid,'audience'=>(string)$row->audience_code,'language'=>(string)$row->language_code,'version'=>(int)$row->version_number,'body'=>(string)$row->body_text,'published_at'=>(string)$row->published_at,'expires_at'=>$row->expires_at):null;
    }

    private function studentChoice(int $studentId,int $actor,?int $guardianActor,string $purpose,string $choice,string $noticeUid,string $language,string $key):array {
        if(!in_array($purpose,self::PURPOSES,true))throw new \InvalidArgumentException('recording_decision_purpose_invalid');
        $allowed=$purpose==='lesson_recording'?array('allow','decline'):array('opt_in','withdraw');
        if(!in_array($choice,$allowed,true))throw new \InvalidArgumentException('recording_decision_choice_invalid');
        return $this->record('student',$studentId,$guardianActor,$purpose,$choice,$noticeUid,$language,$key,$actor);
    }

    private function record(string $subjectType,int $subjectId,?int $guardianActor,string $purpose,string $choice,string $noticeUid,string $language,string $key,int $actor):array {
        global $wpdb; $p=$wpdb->prefix.'dzn_'; $keyDigest=$this->digest('command:'.$key); $now=gmdate('Y-m-d H:i:s');
        if($key===''||!preg_match('/^[A-Za-z0-9:_-]{12,160}$/D',$key))throw new \InvalidArgumentException('recording_decision_key_required');
        if(!in_array($language,array('en','fa'),true))throw new \InvalidArgumentException('recording_decision_language_invalid');
        $payload=$this->digest(wp_json_encode(array('v'=>2,'actor'=>$actor,'subject_type'=>$subjectType,'subject_id'=>$subjectId,'guardian_actor'=>$guardianActor,'purpose'=>$purpose,'choice'=>$choice,'notice'=>$noticeUid,'language'=>$language)));
        $wpdb->query('START TRANSACTION');
        try {
            // Revalidate current identity and capacity before an exact replay can return a stored result.
            if($subjectType==='student')$this->lockAdultStudent($subjectId,$actor); else $this->lockTeacher($subjectId,$actor);
            $prior=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recording_decision_commands WHERE command_key_digest=%s FOR UPDATE",$keyDigest));
            if($prior){
                if(!hash_equals((string)$prior->command_payload_digest,$payload))throw new \RuntimeException('recording_decision_replay_conflict');
                $stored=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recording_decisions WHERE id=%d FOR UPDATE",(int)$prior->result_decision_id));
                if(!$stored||(string)$stored->subject_type!==$subjectType||(int)$stored->subject_id!==$subjectId||(string)$stored->purpose_code!==$purpose)throw new \RuntimeException('recording_decision_replay_conflict');
                $wpdb->query('COMMIT');
                return array('decision_id'=>(int)$stored->id,'state'=>(string)$stored->decision_code,'decided_at'=>(string)$stored->decided_at,'idempotent'=>true);
            }
            $notice=$this->noticeForDecision($noticeUid,$language,$subjectType,$purpose);
            $wpdb->insert($p.'recording_decisions',array('uid'=>Identifier::uid(),'subject_type'=>$subjectType,'subject_id'=>$subjectId,'guardian_actor_user_id'=>$guardianActor,'purpose_code'=>$purpose,'decision_code'=>$choice,'notice_version_id'=>(int)$notice->id,'notice_text_digest'=>(string)$notice->text_digest,'evidence_key_version'=>self::EVIDENCE_KEY_VERSION,'evidence_digest'=>$this->digest('decision:'.$payload.':'.$now),'decided_at'=>$now,'decided_by_user_id'=>$actor,'created_at'=>$now));
            $id=(int)$wpdb->insert_id; if($id<1)throw new \RuntimeException('recording_decision_persistence_failed');
            $wpdb->insert($p.'recording_decision_commands',array('uid'=>Identifier::uid(),'domain'=>self::DOMAIN,'operation'=>'record','command_key_digest'=>$keyDigest,'command_payload_digest'=>$payload,'result_decision_id'=>$id,'result_state'=>$choice,'created_at'=>$now,'created_by'=>$actor));
            if((int)$wpdb->insert_id<1)throw new \RuntimeException('recording_decision_command_persistence_failed');
            $wpdb->query('COMMIT');
            return array('decision_id'=>$id,'state'=>$choice,'decided_at'=>$now,'idempotent'=>false);
        } catch(\Throwable $e){$wpdb->query('ROLLBACK');throw $e;}
    }

    private function noticeForDecision(string $noticeUid,string $language,string $subjectType,string $purpose):object {
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');
        $audience=$subjectType==='teacher'?self::AUDIENCE_TEACHER_TERMS:($purpose==='lesson_recording'?self::AUDIENCE_STUDENT_RECORDING:self::AUDIENCE_STUDENT_RESEARCH);
        $notice=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recording_notice_versions WHERE uid=%s AND language_code=%s AND audience_code=%s AND publication_state='published' AND published_at IS NOT NULL AND published_at<=%s AND (expires_at IS NULL OR expires_at>%s) AND withdrawn_at IS NULL FOR UPDATE",$noticeUid,$language,$audience,$now,$now));
        if(!$notice||(string)$notice->audience_code!==$audience)throw new \RuntimeException('recording_notice_unavailable');
        return $notice;
    }

    private function actor():int{$id=(int)get_current_user_id();if($id<1||!is_user_logged_in())throw new \RuntimeException('recording_authenticated_principal_required');return $id;}
    private function resolveTeacher(int $user):int{global $wpdb;$p=$wpdb->prefix.'dzn_';$rows=(array)$wpdb->get_col($wpdb->prepare("SELECT l.teacher_id FROM {$p}teacher_principal_links l INNER JOIN {$p}teachers t ON t.id=l.teacher_id WHERE l.wordpress_user_id=%d AND l.status='active' AND l.revoked_at IS NULL AND t.status='active' AND t.archived_at IS NULL",$user));if(count($rows)===0)throw new \RuntimeException('recording_teacher_principal_required');if(count($rows)!==1)throw new \RuntimeException('recording_teacher_principal_ambiguous');return(int)$rows[0];}
    private function resolveStudent(int $user):object{global $wpdb;$p=$wpdb->prefix.'dzn_';$rows=(array)$wpdb->get_results($wpdb->prepare("SELECT l.student_id,c.classification FROM {$p}student_principal_links l INNER JOIN {$p}students s ON s.id=l.student_id LEFT JOIN {$p}student_acceptance_capacity_classifications c ON c.id=s.current_acceptance_capacity_classification_id WHERE l.wordpress_user_id=%d AND l.status='active' AND l.active_slot=1 AND l.revoked_at IS NULL AND s.status='active' AND s.archived_at IS NULL",$user));if(count($rows)===0)throw new \RuntimeException('recording_student_principal_required');if(count($rows)!==1)throw new \RuntimeException('recording_student_principal_ambiguous');if((string)$rows[0]->classification!=='adult')throw new \RuntimeException('recording_student_capacity_unavailable');return$rows[0];}
    private function lockTeacher(int $teacher,int $actor):void{global $wpdb;$p=$wpdb->prefix.'dzn_';$rows=(array)$wpdb->get_col($wpdb->prepare("SELECT l.teacher_id FROM {$p}teacher_principal_links l INNER JOIN {$p}teachers t ON t.id=l.teacher_id WHERE l.wordpress_user_id=%d AND l.teacher_id=%d AND l.status='active' AND l.revoked_at IS NULL AND t.status='active' AND t.archived_at IS NULL FOR UPDATE",$actor,$teacher));if(count($rows)===0)throw new \RuntimeException('recording_teacher_principal_required');if(count($rows)!==1)throw new \RuntimeException('recording_teacher_principal_ambiguous');}
    /** Lock the identity and capacity projection with the decision command. */
    private function lockAdultStudent(int $student,int $actor):void{global $wpdb;$p=$wpdb->prefix.'dzn_';$rows=(array)$wpdb->get_results($wpdb->prepare("SELECT s.id,c.classification FROM {$p}student_principal_links l INNER JOIN {$p}students s ON s.id=l.student_id LEFT JOIN {$p}student_acceptance_capacity_classifications c ON c.id=s.current_acceptance_capacity_classification_id WHERE l.wordpress_user_id=%d AND l.student_id=%d AND l.status='active' AND l.active_slot=1 AND l.revoked_at IS NULL AND s.status='active' AND s.archived_at IS NULL FOR UPDATE",$actor,$student));if(count($rows)===0)throw new \RuntimeException('recording_student_principal_required');if(count($rows)!==1)throw new \RuntimeException('recording_student_principal_ambiguous');if((string)$rows[0]->classification!=='adult')throw new \RuntimeException('recording_student_capacity_unavailable');}
    private function latestDecision(string $subjectType,int $subjectId,string $purpose):?object{global $wpdb;$p=$wpdb->prefix.'dzn_';return$wpdb->get_row($wpdb->prepare("SELECT d.*,n.uid AS notice_uid,n.version_number,n.language_code FROM {$p}recording_decisions d INNER JOIN {$p}recording_notice_versions n ON n.id=d.notice_version_id WHERE d.subject_type=%s AND d.subject_id=%d AND d.purpose_code=%s ORDER BY d.decided_at DESC,d.id DESC LIMIT 1",$subjectType,$subjectId,$purpose));}
    private function noticeIsCurrent(int $noticeId):bool{global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');return(bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}recording_notice_versions WHERE id=%d AND publication_state='published' AND published_at IS NOT NULL AND published_at<=%s AND (expires_at IS NULL OR expires_at>%s) AND withdrawn_at IS NULL",$noticeId,$now,$now));}
    private function emptyChoice():array{return array('decision'=>null,'decided_at'=>null,'notice_uid'=>null,'notice_version'=>null,'language'=>null);}
    private function choiceState(?object $row):array{return $row?array('decision'=>(string)$row->decision_code,'decided_at'=>(string)$row->decided_at,'notice_uid'=>(string)$row->notice_uid,'notice_version'=>(int)$row->version_number,'language'=>(string)$row->language_code):$this->emptyChoice();}
    private function digest(string $value):string{return hash('sha256',self::EVIDENCE_KEY_VERSION."\n".$value);}
}
