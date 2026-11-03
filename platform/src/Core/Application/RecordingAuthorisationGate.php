<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Server-side recording authorisation gate.
 *
 * It resolves the exact canonical Lesson, participants, capacity, guardian authority and current
 * policy evidence, then records immutable authorisation evidence. Frontend checkbox state, client
 * claims and stale projections are never inputs. Any missing or invalid fact denies capture.
 *
 * This service does not start a provider capture. It is the gate a capture caller must pass first.
 */
final class RecordingAuthorisationGate {
    public const PROVIDER_LIVEKIT='livekit';
    public const PROVIDER_GOOGLE_MEET='google_meet';

    private RecordingConsentPolicy $policy;

    public function __construct(?RecordingConsentPolicy $policy=null){$this->policy=$policy??new RecordingConsentPolicy();}

    /** Recording through a provider that cannot enforce this gate before capture is disabled. */
    public function assertProviderSupported(string $provider):void{
        if($provider!==self::PROVIDER_LIVEKIT)throw new \RuntimeException('recording_provider_not_consent_enforced');
    }

    public function authoriseLessonUid(string $lessonUid,string $purpose=ConsentPurposeRegistry::STUDENT_RECORDING,int $actor=0):array{
        if(!preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D',$lessonUid))return $this->denied('lesson_not_found');
        global $wpdb;
        $id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}dzn_lessons WHERE uid=%s AND archived_at IS NULL LIMIT 1",$lessonUid));
        return $id>0?$this->authoriseLesson($id,$purpose,$actor):$this->denied('lesson_not_found');
    }

    /**
     * @return array{authorised:bool,reason:string,evidence_id:?int,lesson_id:int,decisions:array}
     */
    public function authoriseLesson(int $lessonId,string $purpose=ConsentPurposeRegistry::STUDENT_RECORDING,int $actor=0,bool $persist=true):array{
        if($purpose!==ConsentPurposeRegistry::STUDENT_RECORDING)return $this->denied('policy_unavailable');
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $lesson=$wpdb->get_row($wpdb->prepare("SELECT id,teacher_id,student_id,enrolment_id,current_schedule_version_id,record_model,lifecycle_state FROM {$p}lessons WHERE id=%d AND archived_at IS NULL LIMIT 1",$lessonId));
        if(!$lesson)return $this->denied('lesson_not_found');
        $facts=array(
            'lesson_found'=>true,
            'lesson_canonical'=>(string)$lesson->record_model==='canonical_term_lesson_v1',
            'lesson_authorised'=>(string)$lesson->lifecycle_state==='authorised',
            'teacher_terms_current'=>false,'teacher_recording_current'=>false,'teacher_recording_decision'=>null,
            'teacher_policy_current'=>true,'student_found'=>false,'student_capacity'=>'unknown',
            'student_recording_current'=>false,'student_recording_decision'=>null,'student_policy_current'=>true,
            'guardian_required'=>(string)$lesson->student_id!=='',
            'guardian_authority_valid'=>false,'guardian_recording_current'=>false,'guardian_recording_decision'=>null,
            'guardian_policy_current'=>true,'blocking_withdrawal'=>false,
        );
        $teacher=$this->decision((int)$lesson->teacher_id,'teacher',ConsentPurposeRegistry::TEACHER_TERMS,false);
        $teacherRecording=$this->decision((int)$lesson->teacher_id,'teacher',ConsentPurposeRegistry::TEACHER_RECORDING,false);
        $facts['teacher_terms_current']=$teacher!==null&&(string)$teacher['row']->decision_code==='accepted'&&$teacher['current'];
        $facts['teacher_recording_decision']=$teacherRecording?(string)$teacherRecording['row']->decision_code:null;
        $facts['teacher_recording_current']=$teacherRecording!==null&&(string)$teacherRecording['row']->decision_code==='allow'&&$teacherRecording['current'];
        $facts['teacher_policy_current']=$teacherRecording!==null?$teacherRecording['current']:false;
        $student=$wpdb->get_row($wpdb->prepare("SELECT s.id,s.status,s.archived_at,c.classification FROM {$p}students s LEFT JOIN {$p}student_acceptance_capacity_classifications c ON c.id=s.current_acceptance_capacity_classification_id AND c.student_id=s.id WHERE s.id=%d LIMIT 1",(int)$lesson->student_id));
        $capacity='unknown';
        if($student&&(string)$student->status==='active'&&$student->archived_at===null){$facts['student_found']=true;$capacity=(string)$student->classification;}
        $facts['student_capacity']=$capacity;
        if($capacity==='adult'){
            $recording=$this->decision((int)$lesson->student_id,'student',ConsentPurposeRegistry::STUDENT_RECORDING,false);
            $facts['student_recording_decision']=$recording?(string)$recording['row']->decision_code:null;
            $facts['student_recording_current']=$recording!==null&&(string)$recording['row']->decision_code==='allow'&&$recording['current'];
            $facts['student_policy_current']=$recording!==null?$recording['current']:false;
            $facts['guardian_required']=false;
        }elseif($capacity==='minor'){
            $facts['guardian_required']=true;
            $guardian=$this->decision((int)$lesson->student_id,'student',ConsentPurposeRegistry::STUDENT_RECORDING,true);
            if($guardian!==null){
                $facts['guardian_recording_decision']=(string)$guardian['row']->decision_code;
                $facts['guardian_recording_current']=(string)$guardian['row']->decision_code==='allow'&&$guardian['current'];
                $facts['guardian_policy_current']=$guardian['current'];
                $grantId=(int)($guardian['row']->guardian_authority_grant_id??0);
                $facts['guardian_authority_valid']=$grantId>0&&$this->guardianGrantActive($grantId,(int)$lesson->student_id);
            }
        }
        $verdict=$this->policy->evaluate($facts);
        $result=array('authorised'=>$verdict['authorised'],'reason'=>$verdict['reason'],'evidence_id'=>null,'lesson_id'=>$lessonId,
            'teacher_id'=>(int)$lesson->teacher_id,'student_id'=>(int)$lesson->student_id,
            'teacher_decision_id'=>$teacherRecording?(int)$teacherRecording['row']->id:null,
            'student_decision_id'=>$capacity==='adult'&&isset($recording)?(int)$recording['row']->id:null,
            'guardian_decision_id'=>$capacity==='minor'&&isset($guardian)?(int)$guardian['row']->id:null,
            'decisions'=>array('teacher_terms'=>$teacher,'teacher_recording'=>$teacherRecording,
                'student_recording'=>$capacity==='adult'?($recording??null):null,
                'guardian_recording'=>$capacity==='minor'?($guardian??null):null),
        );
        if($persist)$result['evidence_id']=$this->persist($lesson,$purpose,$result,$actor,$capacity==='minor'?(int)($guardian['row']->guardian_authority_grant_id??0):0);
        return $result;
    }

    /** Throw a stable, non-sensitive refusal when capture is not authorised. */
    public function assertCaptureAuthorised(int $lessonId,string $provider=self::PROVIDER_LIVEKIT,string $purpose=ConsentPurposeRegistry::STUDENT_RECORDING,int $actor=0):array{
        $this->assertProviderSupported($provider);
        $result=$this->authoriseLesson($lessonId,$purpose,$actor);
        if(!$result['authorised'])throw new \RuntimeException('recording_not_authorised:'.$result['reason']);
        return $result;
    }

    /** Re-evaluate every lesson that depends on a changed participant or policy. */
    public function reauthoriseLessonsForSubject(string $subjectType,int $subjectId,int $actor=0):array{
        if(!in_array($subjectType,array('teacher','student'),true))throw new \InvalidArgumentException('consent_subject_type_invalid');
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $column=$subjectType==='teacher'?'teacher_id':'student_id';
        $ids=(array)$wpdb->get_col($wpdb->prepare("SELECT id FROM {$p}lessons WHERE {$column}=%d AND archived_at IS NULL AND lifecycle_state='authorised' ORDER BY id",$subjectId));
        $out=array();
        foreach($ids as$id)$out[(int)$id]=$this->authoriseLesson((int)$id,ConsentPurposeRegistry::STUDENT_RECORDING,$actor);
        return $out;
    }

    private function decision(int $subjectId,string $subjectType,string $purpose,bool $guardian):?array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $guardianSql=$guardian?'IS NOT NULL':'IS NULL';
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}recording_decisions WHERE subject_type=%s AND subject_id=%d AND purpose_code=%s AND guardian_actor_user_id {$guardianSql} ORDER BY decided_at DESC,id DESC LIMIT 1",$subjectType,$subjectId,$purpose));
        if(!$row)return null;
        return array('row'=>$row,'current'=>$this->noticeCurrent((int)$row->notice_version_id));
    }

    private function noticeCurrent(int $noticeId):bool{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');
        $row=$wpdb->get_row($wpdb->prepare("SELECT audience_code,language_code,version_number FROM {$p}recording_notice_versions WHERE id=%d AND publication_state='published' AND published_at IS NOT NULL AND published_at<=%s AND (expires_at IS NULL OR expires_at>%s) AND withdrawn_at IS NULL",$noticeId,$now,$now));
        if(!$row)return false;
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}recording_notice_versions WHERE audience_code=%s AND language_code=%s AND version_number>%d AND publication_state='published' AND published_at IS NOT NULL AND published_at<=%s AND (expires_at IS NULL OR expires_at>%s) AND withdrawn_at IS NULL",(string)$row->audience_code,(string)$row->language_code,(int)$row->version_number,$now,$now))===0;
    }

    private function guardianGrantActive(int $grantId,int $studentId):bool{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}student_acceptance_authority_grants g INNER JOIN {$p}students s ON s.id=g.student_id WHERE g.id=%d AND g.student_id=%d AND g.authority_type='guardian_representative' AND g.authority_scope=%s AND g.state='active' AND g.active_slot=1 AND g.effective_from<=%s AND (g.effective_until IS NULL OR g.effective_until>%s) AND s.status='active' AND s.archived_at IS NULL",$grantId,$studentId,RecordingDecisionAuthorityService::GUARDIAN_SCOPE,$now,$now))===1;
    }

    private function persist(object $lesson,string $purpose,array $result,int $actor,int $grantId):int{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');
        $scheduleId=(int)($lesson->current_schedule_version_id??0);
        $teacher=$result['decisions']['teacher_recording']??null;
        $student=$result['decisions']['student_recording']??null;
        $guardian=$result['decisions']['guardian_recording']??null;
        $studentEvidence=$student??$guardian;
        $digest=hash('sha256',wp_json_encode(array(
            'v'=>1,'lesson'=>(int)$lesson->id,'schedule'=>$scheduleId,'purpose'=>$purpose,
            'teacher'=>(int)$lesson->teacher_id,'student'=>(int)$lesson->student_id,
            'teacher_decision'=>$teacher?(int)$teacher['row']->id:null,
            'student_decision'=>$student?(int)$student['row']->id:null,
            'guardian_decision'=>$guardian?(int)$guardian['row']->id:null,
            'outcome'=>$result['reason'],
        )));
        $existing=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}consent_recording_authorisations WHERE lesson_id=%d AND schedule_version_id=%d AND purpose_code=%s AND evidence_digest=%s LIMIT 1",(int)$lesson->id,$scheduleId,$purpose,$digest));
        if($existing>0)return $existing;
        $inserted=$wpdb->insert($p.'consent_recording_authorisations',array(
            'uid'=>Identifier::uid(),'lesson_id'=>(int)$lesson->id,'schedule_version_id'=>$scheduleId,
            'teacher_id'=>(int)$lesson->teacher_id,'student_id'=>(int)$lesson->student_id,'purpose_code'=>$purpose,
            'outcome'=>$result['authorised']?'authorised':'denied','reason_code'=>$result['reason'],
            'teacher_decision_id'=>$teacher?(int)$teacher['row']->id:null,
            'student_decision_id'=>$student?(int)$student['row']->id:null,
            'guardian_decision_id'=>$guardian?(int)$guardian['row']->id:null,
            'guardian_authority_grant_id'=>$grantId>0?$grantId:null,
            'teacher_notice_uid'=>$teacher?(string)$teacher['row']->notice_uid:null,
            'student_notice_uid'=>$studentEvidence?(string)$studentEvidence['row']->notice_uid:null,
            'teacher_notice_version'=>$teacher?(int)$teacher['row']->notice_version_number:null,
            'student_notice_version'=>$studentEvidence?(int)$studentEvidence['row']->notice_version_number:null,
            'evidence_digest'=>$digest,'evaluated_at'=>$now,'created_at'=>$now,'created_by'=>max(0,$actor),
        ));
        if($inserted===false){
            $concurrent=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}consent_recording_authorisations WHERE lesson_id=%d AND schedule_version_id=%d AND purpose_code=%s AND evidence_digest=%s LIMIT 1",(int)$lesson->id,$scheduleId,$purpose,$digest));
            if($concurrent>0)return $concurrent;
            throw new \RuntimeException('recording_authorisation_persistence_failed');
        }
        return (int)$wpdb->insert_id;
    }

    private function denied(string $reason):array{
        return array('authorised'=>false,'reason'=>$reason,'evidence_id'=>null,'lesson_id'=>0,'teacher_id'=>0,'student_id'=>0,
            'teacher_decision_id'=>null,'student_decision_id'=>null,'guardian_decision_id'=>null,'decisions'=>array());
    }
}
