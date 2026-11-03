<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Consent withdrawal and downstream processing authority.
 *
 * Withdrawal is a new append-only decision. It immediately blocks future gate decisions and queues
 * durable, retryable work to quarantine dataset items and stop any recording disposition that
 * depended on the withdrawn consent. It never claims that an already-trained model forgot anything.
 */
final class ConsentWithdrawalService {
    private RecordingDecisionAuthorityService $authority;
    private RecordingAuthorisationGate $gate;
    private MlDatasetEligibilityService $datasets;

    public function __construct(?RecordingDecisionAuthorityService $authority=null,?RecordingAuthorisationGate $gate=null,?MlDatasetEligibilityService $datasets=null){
        $this->authority=$authority??new RecordingDecisionAuthorityService();
        $this->gate=$gate??new RecordingAuthorisationGate();
        $this->datasets=$datasets??new MlDatasetEligibilityService();
    }

    /** Authenticated Student or guardian withdrawal. The actor is resolved server-side. */
    public function withdrawStudentResearch(string $noticeUid,string $language,string $key,string $channel='portal_settings'):array{
        $result=$this->authority->recordOwnStudentChoice(ConsentPurposeRegistry::STUDENT_RESEARCH,'withdraw',$noticeUid,$language,$key,$channel,'student_withdrawal');
        $this->propagate('student',(int)($this->currentStudentId()??0),ConsentPurposeRegistry::STUDENT_RESEARCH,(int)$result['decision_id'],0);
        return $result;
    }

    /** Authenticated Teacher withdrawal. */
    public function withdrawTeacherResearch(string $noticeUid,string $language,string $key,string $channel='portal_settings'):array{
        $result=$this->authority->recordTeacherChoice(ConsentPurposeRegistry::TEACHER_RESEARCH,'withdraw',$noticeUid,$language,$key,$channel,'teacher_withdrawal');
        $this->propagate('teacher',(int)($this->currentTeacherId()??0),ConsentPurposeRegistry::TEACHER_RESEARCH,(int)$result['decision_id'],0);
        return $result;
    }

    /** Authenticated Student or guardian recording withdrawal. */
    public function withdrawStudentRecording(string $noticeUid,string $language,string $key,string $channel='portal_settings'):array{
        $result=$this->authority->recordOwnStudentChoice(ConsentPurposeRegistry::STUDENT_RECORDING,'decline',$noticeUid,$language,$key,$channel,'student_withdrawal');
        $this->propagate('student',(int)($this->currentStudentId()??0),ConsentPurposeRegistry::STUDENT_RECORDING,(int)$result['decision_id'],0);
        return $result;
    }

    /** Authenticated Teacher recording withdrawal. */
    public function withdrawTeacherRecording(string $noticeUid,string $language,string $key,string $channel='portal_settings'):array{
        $result=$this->authority->recordTeacherChoice(ConsentPurposeRegistry::TEACHER_RECORDING,'decline',$noticeUid,$language,$key,$channel,'teacher_withdrawal');
        $this->propagate('teacher',(int)($this->currentTeacherId()??0),ConsentPurposeRegistry::TEACHER_RECORDING,(int)$result['decision_id'],0);
        return $result;
    }

    /**
     * Create durable downstream work for one appended decision. Idempotent per decision/subject.
     */
    public function propagate(string $subjectType,int $subjectId,string $purpose,int $decisionId,int $actor=0):array{
        if(!in_array($subjectType,array('teacher','student'),true)||$subjectId<1)return array('jobs'=>array());
        global $wpdb;$p=$wpdb->prefix.'dzn_';$jobs=array();
        if($purpose===ConsentPurposeRegistry::TEACHER_RESEARCH||$purpose===ConsentPurposeRegistry::STUDENT_RESEARCH){
            $column=$subjectType==='teacher'?'teacher_id':'student_id';
            $items=(array)$wpdb->get_results($wpdb->prepare("SELECT id,lesson_id,dataset_key FROM {$p}consent_dataset_items WHERE {$column}=%d AND exclusion_state NOT IN ('excluded') ORDER BY id",$subjectId));
            foreach($items as$item){
                $jobs[]=$this->queueJob('dataset_exclusion',$subjectType,$subjectId,$purpose,'dataset_item',(int)$item->id,$actor,array('decision_id'=>$decisionId));
                $wpdb->update($p.'consent_dataset_items',array('eligibility_result'=>'quarantined','eligibility_reason'=>$this->reason($purpose),'exclusion_state'=>'quarantine_pending','excluded_at'=>gmdate('Y-m-d H:i:s'),'exclusion_reason'=>$this->reason($purpose)),array('id'=>(int)$item->id));
            }
            if($subjectType==='teacher')$this->datasets->reEvaluateForSubject('teacher',$subjectId,$actor);
            else $this->datasets->reEvaluateForSubject('student',$subjectId,$actor);
        }elseif($purpose===ConsentPurposeRegistry::TEACHER_RECORDING||$purpose===ConsentPurposeRegistry::STUDENT_RECORDING){
            $column=$subjectType==='teacher'?'teacher_id':'student_id';
            $lessons=(array)$wpdb->get_col($wpdb->prepare("SELECT DISTINCT lesson_id FROM {$p}consent_recording_authorisations WHERE {$column}=%d AND outcome='authorised' ORDER BY lesson_id",$subjectId));
            foreach($lessons as$lessonId){
                $result=$this->gate->authoriseLesson((int)$lessonId,ConsentPurposeRegistry::STUDENT_RECORDING,$actor);
                if(!$result['authorised']) $jobs[]=$this->queueJob('recording_disposition',$subjectType,$subjectId,$purpose,'lesson',(int)$lessonId,$actor,array('decision_id'=>$decisionId,'reason'=>$result['reason']));
            }
            // The recording control plane owns stop/quarantine/playback revocation. It is invoked
            // through a durable action after this append-only decision; a failure cannot undo the
            // withdrawal and remains visible to the retry worker.
            do_action('dzn_recording_withdrawal_requested',$subjectType,$subjectId,$purpose,$decisionId,$actor);
        }
        $this->event(null,$subjectType,$subjectId,$purpose,'withdrawal_recorded',$this->reason($purpose),$actor);
        return array('jobs'=>$jobs);
    }

    /** Re-evaluate dataset material after a policy version change or dataset rebuild. */
    public function reevaluateDataset(string $datasetKey,int $actor=0):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $ids=(array)$wpdb->get_col($wpdb->prepare("SELECT id FROM {$p}consent_dataset_items WHERE dataset_key=%s ORDER BY id",$datasetKey));
        $out=array();foreach($ids as$id)$out[(int)$id]=$this->queueJob('policy_reevaluation','system',0,ConsentPurposeRegistry::TEACHER_RESEARCH,'dataset_item',(int)$id,$actor,array());
        return $out;
    }

    private function queueJob(string $type,string $subjectType,int $subjectId,string $purpose,string $sourceKind,int $sourceId,int $actor,array $context):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');
        $key=hash('sha256','consent-job|'.$type.'|'.$subjectType.'|'.$subjectId.'|'.$purpose.'|'.$sourceKind.'|'.$sourceId.'|'.(int)($context['decision_id']??0));
        $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}consent_processing_jobs WHERE job_key_digest=%s LIMIT 1",$key));
        if($existing)return array('job_id'=>(int)$existing->id,'idempotent'=>true,'state'=>(string)$existing->state);
        $inserted=$wpdb->insert($p.'consent_processing_jobs',array(
            'uid'=>Identifier::uid(),'job_key_digest'=>$key,'job_type'=>$type,'subject_type'=>$subjectType,
            'subject_id'=>$subjectId,'purpose_code'=>$purpose,'source_kind'=>$sourceKind,'source_id'=>$sourceId,
            'state'=>'pending','attempts'=>0,'max_attempts'=>5,'available_at'=>$now,
            'created_at'=>$now,'created_by'=>max(0,$actor),'updated_at'=>$now,'updated_by'=>max(0,$actor),
        ));
        if($inserted===false)throw new \RuntimeException('consent_processing_job_persistence_failed');
        return array('job_id'=>(int)$wpdb->insert_id,'idempotent'=>false,'state'=>'pending');
    }

    private function currentStudentId():?int{
        $state=$this->authority->studentDecisionState();
        return !empty($state['available'])?(int)$state['student_id']:null;
    }

    private function currentTeacherId():?int{
        $state=$this->authority->teacherConsentState();
        return !empty($state['available'])?(int)$state['teacher_id']:null;
    }

    private function reason(string $purpose):string{
        return $purpose===ConsentPurposeRegistry::STUDENT_RESEARCH||$purpose===ConsentPurposeRegistry::TEACHER_RESEARCH?'research_consent_withdrawn':'recording_consent_withdrawn';
    }

    private function event(?int $jobId,string $subjectType,int $subjectId,string $purpose,string $eventType,string $reason,int $actor):void{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');
        $wpdb->insert($p.'consent_processing_events',array('uid'=>Identifier::uid(),'job_id'=>$jobId,'dataset_item_id'=>null,'subject_type'=>$subjectType,'subject_id'=>$subjectId,'purpose_code'=>$purpose,'event_type'=>$eventType,'reason_code'=>substr($reason,0,64),'evidence_digest'=>hash('sha256',$eventType.'|'.$reason.'|'.$jobId),'occurred_at'=>$now,'created_at'=>$now,'created_by'=>max(0,$actor)));
    }
}
