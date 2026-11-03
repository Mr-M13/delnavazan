<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Application\Port\ConsentDatasetPort;

use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Machine-learning dataset eligibility and manifest authority.
 *
 * Recording consent is a prerequisite but never sufficient. Every dataset item separately proves
 * current teacher and student/guardian research consent, provenance, policy currency and the absence
 * of withdrawal/exclusion. Ineligible material is quarantined and never silently exported.
 */
final class MlDatasetEligibilityService implements ConsentDatasetPort {
    public const CAPABILITY='dzn_manage_consent_policies';
    public const EXPORT_CAPABILITY='dzn_export_consent_evidence';
    private MlDatasetEligibilityPolicy $policy;
    private RecordingAuthorisationGate $gate;

    public function __construct(?MlDatasetEligibilityPolicy $policy=null,?RecordingAuthorisationGate $gate=null){
        $this->policy=$policy??new MlDatasetEligibilityPolicy();
        $this->gate=$gate??new RecordingAuthorisationGate();
    }

    /** @param array<string,mixed> $source */
    public function evaluate(array $source):array{
        $lessonId=(int)($source['lesson_id']??0);
        $sourceId=trim((string)($source['source_recording_id']??''));
        $contentDigest=trim((string)($source['content_digest']??''));
        $facts=array('provenance_valid'=>false,'recording_authorised'=>false,'student_capacity'=>'unknown',
            'teacher_research_current'=>false,'teacher_policy_current'=>false,
            'student_research_current'=>false,'student_policy_current'=>true,
            'guardian_authority_valid'=>false,'guardian_research_current'=>false,'guardian_policy_current'=>true,
            'excluded'=>!empty($source['excluded']),'quarantined'=>!empty($source['quarantined']),
            'retention_restriction'=>!empty($source['retention_restriction']));
        if($sourceId===''||$lessonId<1||preg_match('/^[a-f0-9]{64}$/D',$contentDigest)!==1){
            return array_merge(MlDatasetEligibilityPolicy::deny('provenance_unavailable'),array('facts'=>$facts,'decisions'=>array()));
        }
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $lesson=$wpdb->get_row($wpdb->prepare("SELECT id,teacher_id,student_id,record_model,lifecycle_state FROM {$p}lessons WHERE id=%d AND archived_at IS NULL LIMIT 1",$lessonId));
        if(!$lesson||(string)$lesson->record_model!=='canonical_term_lesson_v1'){
            return array_merge(MlDatasetEligibilityPolicy::deny('provenance_unavailable'),array('facts'=>$facts,'decisions'=>array()));
        }
        $facts['provenance_valid']=true;
        $facts['lesson_id']=$lessonId;
        $facts['teacher_id']=(int)$lesson->teacher_id;
        $facts['student_id']=(int)$lesson->student_id;
        $recording=$this->gate->authoriseLesson($lessonId,ConsentPurposeRegistry::STUDENT_RECORDING,0);
        $facts['recording_authorised']=(bool)$recording['authorised'];
        $teacher=$this->decision((int)$lesson->teacher_id,'teacher',ConsentPurposeRegistry::TEACHER_RESEARCH,false);
        $facts['teacher_research_current']=$teacher!==null&&(string)$teacher['row']->decision_code==='opt_in'&&$teacher['current'];
        $facts['teacher_research_withdrawn']=$teacher!==null&&(string)$teacher['row']->decision_code==='withdraw';
        $facts['teacher_policy_current']=$teacher!==null&&$teacher['current'];
        $student=$wpdb->get_row($wpdb->prepare("SELECT s.id,s.status,s.archived_at,c.classification FROM {$p}students s LEFT JOIN {$p}student_acceptance_capacity_classifications c ON c.id=s.current_acceptance_capacity_classification_id AND c.student_id=s.id WHERE s.id=%d LIMIT 1",(int)$lesson->student_id));
        $capacity=$student?(string)$student->classification:'unknown';
        $facts['student_capacity']=$capacity;
        $research=null;$guardian=null;
        if($capacity==='adult'){
            $research=$this->decision((int)$lesson->student_id,'student',ConsentPurposeRegistry::STUDENT_RESEARCH,false);
            $facts['student_research_current']=$research!==null&&(string)$research['row']->decision_code==='opt_in'&&$research['current'];
            $facts['student_research_withdrawn']=$research!==null&&(string)$research['row']->decision_code==='withdraw';
            $facts['student_policy_current']=$research!==null&&$research['current'];
        }elseif($capacity==='minor'){
            $guardian=$this->decision((int)$lesson->student_id,'student',ConsentPurposeRegistry::STUDENT_RESEARCH,true);
            $facts['guardian_research_current']=$guardian!==null&&(string)$guardian['row']->decision_code==='opt_in'&&$guardian['current'];
            $facts['guardian_research_withdrawn']=$guardian!==null&&(string)$guardian['row']->decision_code==='withdraw';
            $facts['guardian_policy_current']=$guardian!==null&&$guardian['current'];
            $grantId=$guardian?(int)($guardian['row']->guardian_authority_grant_id??0):0;
            $facts['guardian_authority_valid']=$grantId>0&&$this->guardianGrantActive($grantId,(int)$lesson->student_id);
        }
        $verdict=$this->policy->evaluate($facts);
        return array_merge($verdict,array('facts'=>$facts,'decisions'=>array(
            'teacher_research'=>$teacher,'student_research'=>$research,'guardian_research'=>$guardian,
        ),'teacher_id'=>(int)$lesson->teacher_id,'student_id'=>(int)$lesson->student_id,'lesson_id'=>$lessonId));
    }

    /**
     * Add or refresh a dataset manifest item. Ineligible material is persisted as quarantined, which
     * makes the refusal auditable and prevents a later export from treating it as eligible.
     *
     * @return array{item_id:?int,eligible:bool,reason:string,refused:bool}
     */
    public function ingest(array $source,string $datasetKey,string $datasetVersion,string $storageReference,int $actor,string $lineage=''):array{
        $datasetKey=$this->code($datasetKey,'persian_music_research');
        $datasetVersion=$this->code($datasetVersion,'v1');
        $evaluation=$this->evaluate($source);
        $eligible=(bool)$evaluation['eligible'];
        $sourceId=(string)($source['source_recording_id']??'');
        if($sourceId==='')return array('item_id'=>null,'eligible'=>false,'reason'=>'provenance_unavailable','refused'=>true);
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');
        $decisionIds=array();
        foreach((array)$evaluation['decisions'] as$decision)if($decision&&isset($decision['row']))$decisionIds[]=(int)$decision['row']->id;
        $evidenceDigest=hash('sha256',implode(',',$decisionIds).'|'.($evaluation['reason']));
        $pseudonym=hash_hmac('sha256',$datasetKey.'|'.(int)($evaluation['lesson_id']??0).'|'.(int)($evaluation['student_id']??0),wp_salt('auth'));
        $fields=array(
            'uid'=>Identifier::uid(),'dataset_key'=>$datasetKey,'dataset_version'=>$datasetVersion,
            'source_recording_id'=>$sourceId,'lesson_id'=>(int)($evaluation['lesson_id']??($source['lesson_id']??0)),
            'teacher_id'=>(int)($evaluation['teacher_id']??0),'student_id'=>(int)($evaluation['student_id']??0),
            'content_digest'=>$this->digest((string)($source['content_digest']??'')),
            'consent_evidence_digest'=>$evidenceDigest,
            'teacher_decision_id'=>isset($evaluation['decisions']['teacher_research']['row'])?(int)$evaluation['decisions']['teacher_research']['row']->id:null,
            'student_decision_id'=>isset($evaluation['decisions']['student_research']['row'])?(int)$evaluation['decisions']['student_research']['row']->id:null,
            'guardian_decision_id'=>isset($evaluation['decisions']['guardian_research']['row'])?(int)$evaluation['decisions']['guardian_research']['row']->id:null,
            'eligibility_result'=>$eligible?'eligible':'quarantined','eligibility_reason'=>$evaluation['reason'],
            'ingested_at'=>$eligible?$now:null,
            'processing_lineage'=>substr($lineage!==''?$lineage:'source_ingest',0,2000),
            'storage_reference'=>$storageReference!==''?substr($storageReference,0,255):null,
            'pseudonym_subject'=>substr($pseudonym,0,64),
            'exclusion_state'=>$eligible?'none':'quarantine_pending',
            'created_at'=>$now,'created_by'=>max(0,$actor),'updated_at'=>$now,'updated_by'=>max(0,$actor),
        );
        $existing=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}consent_dataset_items WHERE dataset_key=%s AND source_recording_id=%s LIMIT 1",$datasetKey,$sourceId));
        if($existing>0){
            unset($fields['uid'],$fields['created_at'],$fields['created_by']);
            if($wpdb->update($p.'consent_dataset_items',$fields,array('id'=>$existing))===false)throw new \RuntimeException('ml_dataset_manifest_persistence_failed');
            $itemId=$existing;
        }else{
            if($wpdb->insert($p.'consent_dataset_items',$fields)===false)throw new \RuntimeException('ml_dataset_manifest_persistence_failed');
            $itemId=(int)$wpdb->insert_id;
        }
        $this->event($itemId,null,$evaluation['teacher_id']??0,'teacher','dataset_ingest', $eligible?'eligible':'quarantined',$evaluation['reason'],$actor);
        return array('item_id'=>$itemId,'eligible'=>$eligible,'reason'=>$evaluation['reason'],'refused'=>!$eligible);
    }

    /** Strict ingestion seam: any failed condition refuses the item instead of silently dropping it. */
    public function assertEligible(array $source,string $datasetKey,string $datasetVersion,string $storageReference,int $actor,string $lineage=''):array{
        $result=$this->ingest($source,$datasetKey,$datasetVersion,$storageReference,$actor,$lineage);
        if(!$result['eligible'])throw new \RuntimeException('ml_dataset_ingestion_refused:'.$result['reason']);
        return $result;
    }

    /** Re-evaluate one item; a lost eligibility becomes quarantine_pending and a durable job is queued. */
    public function reEvaluateItem(int $itemId,int $actor=0):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');
        $item=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}consent_dataset_items WHERE id=%d LIMIT 1",$itemId));
        if(!$item)throw new \InvalidArgumentException('ml_dataset_item_not_found');
        $evaluation=$this->evaluate(array(
            'source_recording_id'=>(string)$item->source_recording_id,'lesson_id'=>(int)$item->lesson_id,
            'content_digest'=>(string)$item->content_digest,'dataset_key'=>(string)$item->dataset_key,
            'dataset_version'=>(string)$item->dataset_version,
            'excluded'=>(string)$item->exclusion_state==='excluded',
            'quarantined'=>(string)$item->exclusion_state==='quarantined',
        ));
        $eligible=(bool)$evaluation['eligible'];
        $state=$eligible?'none':((string)$item->exclusion_state==='excluded'?'excluded':'quarantine_pending');
        if($wpdb->update($p.'consent_dataset_items',array('eligibility_result'=>$eligible?'eligible':'quarantined','eligibility_reason'=>$evaluation['reason'],'exclusion_state'=>$state,'excluded_at'=>$eligible?null:$now,'exclusion_reason'=>$eligible?null:$evaluation['reason'],'updated_at'=>$now,'updated_by'=>max(0,$actor)),array('id'=>$itemId))===false)throw new \RuntimeException('ml_dataset_manifest_persistence_failed');
        return array('item_id'=>$itemId,'eligible'=>$eligible,'reason'=>$evaluation['reason'],'state'=>$state);
    }

    /** Re-evaluate every item for a changed teacher/student; returns the changed item ids. */
    public function reEvaluateForSubject(string $subjectType,int $subjectId,int $actor=0):array{
        if(!in_array($subjectType,array('teacher','student'),true))throw new \InvalidArgumentException('consent_subject_type_invalid');
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $ids=(array)$wpdb->get_col($wpdb->prepare("SELECT id FROM {$p}consent_dataset_items WHERE ".($subjectType==='teacher'?'teacher_id':'student_id')."=%d AND exclusion_state NOT IN ('excluded') ORDER BY id",$subjectId));
        $out=array();foreach($ids as$id)$out[(int)$id]=$this->reEvaluateItem((int)$id,$actor);
        return $out;
    }

    public function markExcluded(int $itemId,string $reason,int $actor=0):void{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');
        if($wpdb->update($p.'consent_dataset_items',array('eligibility_result'=>'excluded','eligibility_reason'=>$this->safeReason($reason),'exclusion_state'=>'excluded','excluded_at'=>$now,'exclusion_reason'=>$this->safeReason($reason),'updated_at'=>$now,'updated_by'=>max(0,$actor)),array('id'=>$itemId))===false)throw new \RuntimeException('ml_dataset_manifest_persistence_failed');
    }

    /**
     * Export only currently eligible, non-excluded, pseudonymised manifest rows. Requires the
     * explicit consent-evidence export capability.
     */
    public function exportManifest(string $datasetKey):array{
        if(!current_user_can(self::EXPORT_CAPABILITY)&&!current_user_can('manage_options'))throw new \RuntimeException('consent_export_capability_required');
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT uid,dataset_key,dataset_version,source_recording_id,lesson_id,content_digest,consent_evidence_digest,eligibility_result,eligibility_reason,ingested_at,processing_lineage,storage_reference,pseudonym_subject,exclusion_state,created_at FROM {$p}consent_dataset_items WHERE dataset_key=%s AND eligibility_result='eligible' AND exclusion_state='none' ORDER BY id",$this->code($datasetKey,'persian_music_research')));
        return array_map(static fn($row)=>get_object_vars($row),$rows);
    }

    public function counts():array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        return array(
            'eligible'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}consent_dataset_items WHERE eligibility_result='eligible' AND exclusion_state='none'"),
            'quarantined'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}consent_dataset_items WHERE exclusion_state IN ('quarantine_pending','quarantined')"),
            'excluded'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}consent_dataset_items WHERE exclusion_state='excluded'"),
        );
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

    private function event(?int $itemId,?int $jobId,int $subjectId,string $subjectType,string $eventType,string $state,string $reason,int $actor):void{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');
        $wpdb->insert($p.'consent_processing_events',array('uid'=>Identifier::uid(),'job_id'=>$jobId,'dataset_item_id'=>$itemId,'subject_type'=>$subjectType,'subject_id'=>$subjectId,'purpose_code'=>ConsentPurposeRegistry::TEACHER_RESEARCH,'event_type'=>$eventType,'reason_code'=>$this->safeReason($state.':'.$reason),'evidence_digest'=>hash('sha256',$eventType.'|'.$state.'|'.$reason),'occurred_at'=>$now,'created_at'=>$now,'created_by'=>max(0,$actor)));
    }

    private function digest(string $value):string{return preg_match('/^[a-f0-9]{64}$/D',$value)===1?$value:hash('sha256',$value);}
    private function safeReason(string $value):string{$value=sanitize_key($value);return $value!==''?substr($value,0,64):'consent_processing';}
    private function code(string $value,string $fallback):string{$value=sanitize_key($value);return $value!==''?substr($value,0,64):$fallback;}
}
