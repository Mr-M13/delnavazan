<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Disabled prospective recording/research decision authority. It deliberately owns no capture,
 * provider, asset, export or user-interface action. Public controllers must call only the current
 * principal methods below; there is no actor or subject identifier in their input.
 */
final class RecordingDecisionAuthorityService {
    public const DOMAIN='recording_decision_v1';
    public const EVIDENCE_KEY_VERSION='recording_decision_evidence_v1';
    private const PURPOSES=array('lesson_recording','research_model_training');

    /** Teacher terms acceptance for the Teacher resolved from the authenticated principal. */
    public function acceptTeacherTerms(string $noticeUid,string $language,string $key):array {
        $actor=$this->actor(); $teacher=$this->teacherFor($actor); if(!$teacher)throw new \RuntimeException('recording_teacher_principal_required');
        return $this->record('teacher',(int)$teacher->id,null,'teacher_terms','accepted',$noticeUid,$language,$key,$actor);
    }

    /** Student's own explicit prospective choice. No student ID is accepted from a caller. */
    public function recordOwnStudentChoice(string $purpose,string $choice,string $noticeUid,string $language,string $key):array {
        $actor=$this->actor(); $student=$this->studentFor($actor); if(!$student)throw new \RuntimeException('recording_student_principal_required');
        return $this->studentChoice((int)$student->id,$actor,null,$purpose,$choice,$noticeUid,$language,$key);
    }

    /** Guardian decisions remain unavailable: service_acceptance is not reviewed for these purposes. */
    public function recordGuardianChoiceForResolvedStudent(int $studentId,string $purpose,string $choice,string $noticeUid,string $language,string $key):array {
        $this->actor(); throw new \RuntimeException('recording_guardian_scope_unavailable');
    }

    /** Reserved for a future approved legal policy; this disabled slice never makes it available. */
    public function recordApprovedOptOutBasisForResolvedStudent(int $studentId,string $noticeUid,string $key):array {
        $this->actor(); throw new \RuntimeException('recording_default_on_policy_unavailable');
    }

    private function studentChoice(int $studentId,int $actor,?int $guardianActor,string $purpose,string $choice,string $noticeUid,string $language,string $key):array {
        if(!in_array($purpose,self::PURPOSES,true))throw new \InvalidArgumentException('recording_decision_purpose_invalid');
        $allowed=$purpose==='lesson_recording'?array('allow','decline'):array('opt_in','withdraw');
        if(!in_array($choice,$allowed,true))throw new \InvalidArgumentException('recording_decision_choice_invalid');
        return $this->record('student',$studentId,$guardianActor,$purpose,$choice,$noticeUid,$language,$key,$actor);
    }

    private function record(string $subjectType,int $subjectId,?int $guardianActor,string $purpose,string $choice,string $noticeUid,string $language,string $key,int $actor):array {
        global $wpdb; $p=$wpdb->prefix.'dzn_'; $keyDigest=$this->digest('command:'.$key); $now=gmdate('Y-m-d H:i:s');
        if($key===''||!preg_match('/^[a-z0-9:_-]{12,160}$/D',$key))throw new \InvalidArgumentException('recording_decision_key_required');
        if(!in_array($language,array('en','fa'),true))throw new \InvalidArgumentException('recording_decision_language_invalid');
        $payload=$this->digest(wp_json_encode(array('v'=>1,'subject_type'=>$subjectType,'subject_id'=>$subjectId,'guardian_actor'=>$guardianActor,'purpose'=>$purpose,'choice'=>$choice,'notice'=>$noticeUid,'language'=>$language)));
        $wpdb->query('START TRANSACTION');
        try {
            $prior=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recording_decision_commands WHERE command_key_digest=%s FOR UPDATE",$keyDigest));
            if($prior){ if(!hash_equals((string)$prior->command_payload_digest,$payload))throw new \RuntimeException('recording_decision_replay_conflict'); $wpdb->query('COMMIT'); return array('decision_id'=>(int)$prior->result_decision_id,'state'=>(string)$prior->result_state,'idempotent'=>true); }
            $notice=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recording_notice_versions WHERE uid=%s AND publication_state='published' AND language_code=%s FOR UPDATE",$noticeUid,$language));
            if(!$notice||!$this->noticeMatches($notice,$subjectType,$purpose))throw new \RuntimeException('recording_notice_unavailable');
            if($subjectType==='student')$this->lockedAdultStudent($subjectId,$actor); else $this->lockedTeacher($subjectId,$actor);
            $wpdb->insert($p.'recording_decisions',array('uid'=>Identifier::uid(),'subject_type'=>$subjectType,'subject_id'=>$subjectId,'guardian_actor_user_id'=>$guardianActor,'purpose_code'=>$purpose,'decision_code'=>$choice,'notice_version_id'=>(int)$notice->id,'notice_text_digest'=>(string)$notice->text_digest,'evidence_key_version'=>self::EVIDENCE_KEY_VERSION,'evidence_digest'=>$this->digest('decision:'.$payload.':'.$now),'decided_at'=>$now,'decided_by_user_id'=>$actor,'created_at'=>$now));
            $id=(int)$wpdb->insert_id; if($id<1)throw new \RuntimeException('recording_decision_persistence_failed');
            $wpdb->insert($p.'recording_decision_commands',array('uid'=>Identifier::uid(),'domain'=>self::DOMAIN,'operation'=>'record','command_key_digest'=>$keyDigest,'command_payload_digest'=>$payload,'result_decision_id'=>$id,'result_state'=>$choice,'created_at'=>$now,'created_by'=>$actor));
            if((int)$wpdb->insert_id<1)throw new \RuntimeException('recording_decision_command_persistence_failed'); $wpdb->query('COMMIT'); return array('decision_id'=>$id,'state'=>$choice,'idempotent'=>false);
        } catch(\Throwable $e){$wpdb->query('ROLLBACK');throw $e;}
    }
    private function actor():int{$id=(int)get_current_user_id();if($id<1||!is_user_logged_in())throw new \RuntimeException('recording_authenticated_principal_required');return $id;}
    private function teacherFor(int $user):?object{global $wpdb;$p=$wpdb->prefix.'dzn_';$rows=(array)$wpdb->get_results($wpdb->prepare("SELECT t.id FROM {$p}teacher_principal_links l INNER JOIN {$p}teachers t ON t.id=l.teacher_id WHERE l.wordpress_user_id=%d AND l.status='active' AND l.revoked_at IS NULL AND t.status='active' AND t.archived_at IS NULL",$user));return count($rows)===1?$rows[0]:null;}
    private function studentFor(int $user):?object{global $wpdb;$p=$wpdb->prefix.'dzn_';$rows=(array)$wpdb->get_results($wpdb->prepare("SELECT s.id FROM {$p}student_principal_links l INNER JOIN {$p}students s ON s.id=l.student_id WHERE l.wordpress_user_id=%d AND l.status='active' AND l.active_slot=1 AND l.revoked_at IS NULL AND s.status='active' AND s.archived_at IS NULL",$user));return count($rows)===1?$rows[0]:null;}
    private function lockedTeacher(int $teacher,int $actor):void{global $wpdb;$p=$wpdb->prefix.'dzn_';$rows=(array)$wpdb->get_results($wpdb->prepare("SELECT t.id FROM {$p}teacher_principal_links l INNER JOIN {$p}teachers t ON t.id=l.teacher_id WHERE l.wordpress_user_id=%d AND l.teacher_id=%d AND l.status='active' AND l.revoked_at IS NULL AND t.status='active' AND t.archived_at IS NULL FOR UPDATE",$actor,$teacher));if(count($rows)!==1)throw new \RuntimeException('recording_teacher_principal_ambiguous');}
    /** Lock the identity and capacity projection with the decision command. */
    private function lockedAdultStudent(int $student,int $actor):void{global $wpdb;$p=$wpdb->prefix.'dzn_';$rows=(array)$wpdb->get_results($wpdb->prepare("SELECT s.id,c.classification FROM {$p}student_principal_links l INNER JOIN {$p}students s ON s.id=l.student_id LEFT JOIN {$p}student_acceptance_capacity_classifications c ON c.id=s.current_acceptance_capacity_classification_id WHERE l.wordpress_user_id=%d AND l.student_id=%d AND l.status='active' AND l.active_slot=1 AND l.revoked_at IS NULL AND s.status='active' AND s.archived_at IS NULL FOR UPDATE",$actor,$student));if(count($rows)!==1)throw new \RuntimeException('recording_student_principal_ambiguous');if((string)$rows[0]->classification!=='adult')throw new \RuntimeException('recording_student_capacity_unavailable');}
    private function activeStudent(int $student):bool{global $wpdb;$p=$wpdb->prefix.'dzn_';return(bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}students WHERE id=%d AND status='active' AND archived_at IS NULL LIMIT 1",$student));}
    private function noticeMatches(object $n,string $subject,string $purpose):bool{return (string)$n->audience_code===($subject==='teacher'?'teacher_terms':'student_'.$purpose);}
    private function digest(string $value):string{return hash('sha256',self::EVIDENCE_KEY_VERSION."\n".$value);}
}
