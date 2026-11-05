<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Application\Port\ConsentOperationsPort;

/**
 * Authoritative, read-only consent operations projections for Settings → Policies.
 *
 * Every count is derived from evidence tables. There is no hardcoded dashboard value and no write
 * path: an administrator cannot fabricate, edit or silently override a person's decision here.
 */
final class ConsentOperationsReadService implements ConsentOperationsPort {
    public const CAPABILITY='dzn_manage_consent_policies';
    public const VIEW_CAPABILITY='dzn_view_consent_evidence';

    public function counts():array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $now=gmdate('Y-m-d H:i:s');
        $currentNotice=$this->currentNoticeSql('n');
        $teacherMissing="NOT EXISTS(SELECT 1 FROM {$p}recording_decisions d WHERE d.id=(SELECT MAX(d2.id) FROM {$p}recording_decisions d2 WHERE d2.subject_type='teacher' AND d2.subject_id=t.id AND d2.purpose_code='teacher_terms' AND d2.guardian_actor_user_id IS NULL) AND d.decision_code='accepted' AND EXISTS(SELECT 1 FROM {$p}recording_notice_versions n WHERE n.id=d.notice_version_id AND {$currentNotice}))";
        $teacherRecordingMissing="NOT EXISTS(SELECT 1 FROM {$p}recording_decisions d WHERE d.id=(SELECT MAX(d2.id) FROM {$p}recording_decisions d2 WHERE d2.subject_type='teacher' AND d2.subject_id=t.id AND d2.purpose_code='teacher_lesson_recording' AND d2.guardian_actor_user_id IS NULL) AND d.decision_code IN ('allow','decline') AND EXISTS(SELECT 1 FROM {$p}recording_notice_versions n WHERE n.id=d.notice_version_id AND {$currentNotice}))";
        $studentRecordingMissing="NOT EXISTS(SELECT 1 FROM {$p}recording_decisions d INNER JOIN {$p}students s2 ON s2.id=d.subject_id LEFT JOIN {$p}student_acceptance_capacity_classifications c2 ON c2.id=s2.current_acceptance_capacity_classification_id AND c2.student_id=s2.id WHERE d.id=(SELECT MAX(d2.id) FROM {$p}recording_decisions d2 WHERE d2.subject_type='student' AND d2.subject_id=s.id AND d2.purpose_code='lesson_recording' AND ((c2.classification='minor' AND d2.guardian_actor_user_id IS NOT NULL) OR (c2.classification<>'minor' AND d2.guardian_actor_user_id IS NULL))) AND d.decision_code IN ('allow','decline') AND EXISTS(SELECT 1 FROM {$p}recording_notice_versions n WHERE n.id=d.notice_version_id AND {$currentNotice}))";
        $guardianAttention="EXISTS(SELECT 1 FROM {$p}student_acceptance_capacity_classifications c WHERE c.id=s.current_acceptance_capacity_classification_id AND c.student_id=s.id AND c.classification='minor') AND NOT EXISTS(SELECT 1 FROM {$p}recording_decisions d INNER JOIN {$p}student_acceptance_authority_grants g ON g.id=d.guardian_authority_grant_id AND g.student_id=d.subject_id AND g.authority_type='guardian_representative' AND g.authority_scope='service_acceptance' AND g.state='active' AND g.active_slot=1 AND g.effective_from<=%s AND (g.effective_until IS NULL OR g.effective_until>%s) WHERE d.id=(SELECT MAX(d2.id) FROM {$p}recording_decisions d2 WHERE d2.subject_type='student' AND d2.subject_id=s.id AND d2.purpose_code='lesson_recording' AND d2.guardian_actor_user_id IS NOT NULL) AND d.decision_code='allow' AND EXISTS(SELECT 1 FROM {$p}recording_notice_versions n WHERE n.id=d.notice_version_id AND {$currentNotice}))";
        $teachersAwaiting=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}teachers t WHERE t.status='active' AND t.archived_at IS NULL AND (".$teacherMissing.' OR '.$teacherRecordingMissing.')');
        $studentsAwaiting=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}students s WHERE s.status='active' AND s.archived_at IS NULL AND s.current_acceptance_capacity_classification_id IS NOT NULL AND ".$studentRecordingMissing);
        $guardianDecisions=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}students s WHERE s.status='active' AND s.archived_at IS NULL AND ".$guardianAttention,$now,$now));
        $authorisedLessons=(int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT lesson_id,SUBSTRING_INDEX(GROUP_CONCAT(outcome ORDER BY id DESC),',',1) outcome FROM {$p}consent_recording_authorisations GROUP BY lesson_id) latest WHERE outcome='authorised'");
        $blocked=(int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT lesson_id,SUBSTRING_INDEX(GROUP_CONCAT(outcome ORDER BY id DESC),',',1) outcome FROM {$p}consent_recording_authorisations GROUP BY lesson_id) latest WHERE outcome<>'authorised'");
        $dataset=(new MlDatasetEligibilityService());
        try{$datasetCounts=$dataset->counts();}catch(\Throwable){$datasetCounts=array('eligible'=>0,'quarantined'=>0,'excluded'=>0);}
        return array(
            'teachers_awaiting'=>$teachersAwaiting,
            'students_awaiting'=>$studentsAwaiting,
            'guardian_attention'=>$guardianDecisions,
            'recording_authorised_lessons'=>$authorisedLessons,
            'ml_eligible_recordings'=>$datasetCounts['eligible'],
            'blocked_or_quarantined'=>$blocked+$datasetCounts['quarantined'],
            'pending_withdrawals'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}consent_processing_jobs WHERE state IN ('pending','running') AND job_type IN ('dataset_exclusion','recording_disposition')"),
            'failed_jobs'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}consent_processing_jobs WHERE state='failed'"),
        );
    }

    public function pendingTeachers(int $limit=50):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$current=$this->currentNoticeSql('n');
        $termsMissing="NOT EXISTS(SELECT 1 FROM {$p}recording_decisions d WHERE d.id=(SELECT MAX(d2.id) FROM {$p}recording_decisions d2 WHERE d2.subject_type='teacher' AND d2.subject_id=t.id AND d2.purpose_code='teacher_terms' AND d2.guardian_actor_user_id IS NULL) AND d.decision_code='accepted' AND EXISTS(SELECT 1 FROM {$p}recording_notice_versions n WHERE n.id=d.notice_version_id AND {$current}))";
        $recordingMissing="NOT EXISTS(SELECT 1 FROM {$p}recording_decisions d WHERE d.id=(SELECT MAX(d2.id) FROM {$p}recording_decisions d2 WHERE d2.subject_type='teacher' AND d2.subject_id=t.id AND d2.purpose_code='teacher_lesson_recording' AND d2.guardian_actor_user_id IS NULL) AND d.decision_code IN ('allow','decline') AND EXISTS(SELECT 1 FROM {$p}recording_notice_versions n WHERE n.id=d.notice_version_id AND {$current}))";
        return (array)$wpdb->get_results($wpdb->prepare("SELECT t.id,t.display_name,t.email FROM {$p}teachers t WHERE t.status='active' AND t.archived_at IS NULL AND (".$termsMissing.' OR '.$recordingMissing.") ORDER BY t.id DESC LIMIT %d",$limit));
    }

    public function pendingStudents(int $limit=50):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');$current=$this->currentNoticeSql('n');
        return (array)$wpdb->get_results($wpdb->prepare("SELECT s.id,s.display_name,s.email,c.classification FROM {$p}students s INNER JOIN {$p}student_acceptance_capacity_classifications c ON c.id=s.current_acceptance_capacity_classification_id AND c.student_id=s.id WHERE s.status='active' AND s.archived_at IS NULL AND NOT EXISTS(SELECT 1 FROM {$p}recording_decisions d WHERE d.id=(SELECT MAX(d2.id) FROM {$p}recording_decisions d2 WHERE d2.subject_type='student' AND d2.subject_id=s.id AND d2.purpose_code='lesson_recording' AND ((c.classification='minor' AND d2.guardian_actor_user_id IS NOT NULL) OR (c.classification<>'minor' AND d2.guardian_actor_user_id IS NULL))) AND d.decision_code IN ('allow','decline') AND EXISTS(SELECT 1 FROM {$p}recording_notice_versions n WHERE n.id=d.notice_version_id AND {$current})) ORDER BY s.id DESC LIMIT %d",$limit));
    }

    public function guardianAttention(int $limit=50):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';$now=gmdate('Y-m-d H:i:s');$current=$this->currentNoticeSql('n');
        return (array)$wpdb->get_results($wpdb->prepare("SELECT s.id,s.display_name,COUNT(g.id) active_grants FROM {$p}students s INNER JOIN {$p}student_acceptance_capacity_classifications c ON c.id=s.current_acceptance_capacity_classification_id AND c.student_id=s.id AND c.classification='minor' LEFT JOIN {$p}student_acceptance_authority_grants g ON g.student_id=s.id AND g.authority_type='guardian_representative' AND g.authority_scope='service_acceptance' AND g.state='active' AND g.active_slot=1 AND g.effective_from<=%s AND (g.effective_until IS NULL OR g.effective_until>%s) WHERE s.status='active' AND s.archived_at IS NULL AND NOT EXISTS(SELECT 1 FROM {$p}recording_decisions d INNER JOIN {$p}student_acceptance_authority_grants dg ON dg.id=d.guardian_authority_grant_id AND dg.student_id=d.subject_id AND dg.authority_type='guardian_representative' AND dg.authority_scope='service_acceptance' AND dg.state='active' AND dg.active_slot=1 AND dg.effective_from<=%s AND (dg.effective_until IS NULL OR dg.effective_until>%s) WHERE d.id=(SELECT MAX(d2.id) FROM {$p}recording_decisions d2 WHERE d2.subject_type='student' AND d2.subject_id=s.id AND d2.purpose_code='lesson_recording' AND d2.guardian_actor_user_id IS NOT NULL) AND d.decision_code='allow' AND EXISTS(SELECT 1 FROM {$p}recording_notice_versions n WHERE n.id=d.notice_version_id AND {$current})) GROUP BY s.id,s.display_name ORDER BY s.id DESC LIMIT %d",$now,$now,$now,$now,$limit));
    }

    public function withdrawn(int $limit=50):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        return (array)$wpdb->get_results($wpdb->prepare("SELECT d.id,d.subject_type,d.subject_id,d.purpose_code,d.decision_code,d.decided_at,d.notice_uid,d.notice_version_number FROM {$p}recording_decisions d WHERE d.decision_code IN ('decline','withdraw') ORDER BY d.decided_at DESC,d.id DESC LIMIT %d",$limit));
    }

    public function exceptions(int $limit=50):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $blocked=(array)$wpdb->get_results($wpdb->prepare("SELECT 'recording' kind,lesson_id source_id,reason_code reason,evaluated_at occurred_at FROM {$p}consent_recording_authorisations WHERE outcome<>'authorised' ORDER BY id DESC LIMIT %d",$limit));
        $dataset=(array)$wpdb->get_results($wpdb->prepare("SELECT 'dataset' kind,id source_id,eligibility_reason reason,updated_at occurred_at FROM {$p}consent_dataset_items WHERE exclusion_state IN ('quarantine_pending','quarantined','excluded') OR eligibility_result<>'eligible' ORDER BY id DESC LIMIT %d",$limit));
        return array_merge($blocked,$dataset);
    }

    public function jobs(int $limit=50):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        return (array)$wpdb->get_results($wpdb->prepare("SELECT id,uid,job_type,subject_type,subject_id,purpose_code,source_kind,source_id,state,attempts,max_attempts,last_error_code,result_code,created_at,completed_at FROM {$p}consent_processing_jobs ORDER BY id DESC LIMIT %d",$limit));
    }

    public function evidence(string $subjectType,int $subjectId,int $limit=100):array{
        if(!in_array($subjectType,array('teacher','student'),true))throw new \InvalidArgumentException('consent_subject_type_invalid');
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        return (array)$wpdb->get_results($wpdb->prepare("SELECT d.id,d.uid,d.purpose_code,d.decision_code,d.notice_uid,d.notice_audience_code,d.notice_language_code,d.notice_version_number,d.notice_text_digest,d.collection_channel,d.workflow_code,d.scope_code,d.decided_at,d.decided_by_user_id,d.guardian_actor_user_id,d.guardian_authority_grant_id,d.supersedes_decision_id,d.withdrawal_of_decision_id FROM {$p}recording_decisions d WHERE d.subject_type=%s AND d.subject_id=%d ORDER BY d.decided_at DESC,d.id DESC LIMIT %d",$subjectType,$subjectId,$limit));
    }

    public function policyLibrary():array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $purposes=(array)$wpdb->get_results("SELECT * FROM {$p}consent_purposes ORDER BY subject_scope,purpose_code");
        $versions=(array)$wpdb->get_results("SELECT id,uid,audience_code,language_code,version_number,publication_state,approval_state,published_at,expires_at,withdrawn_at,text_digest,created_at,created_by FROM {$p}recording_notice_versions ORDER BY audience_code,language_code,version_number DESC LIMIT 100");
        return array('purposes'=>$purposes,'versions'=>$versions);
    }

    /** Current published notice existence for an audience, without exposing any body. */
    private function currentNoticeSql(string $alias):string{
        return "{$alias}.publication_state='published' AND {$alias}.published_at IS NOT NULL AND {$alias}.published_at<=UTC_TIMESTAMP() AND ({$alias}.expires_at IS NULL OR {$alias}.expires_at>UTC_TIMESTAMP()) AND {$alias}.withdrawn_at IS NULL AND NOT EXISTS(SELECT 1 FROM {$GLOBALS['wpdb']->prefix}dzn_recording_notice_versions {$alias}2 WHERE {$alias}2.audience_code={$alias}.audience_code AND {$alias}2.language_code={$alias}.language_code AND {$alias}2.version_number>{$alias}.version_number AND {$alias}2.publication_state='published' AND {$alias}2.published_at IS NOT NULL AND {$alias}2.published_at<=UTC_TIMESTAMP() AND ({$alias}2.expires_at IS NULL OR {$alias}2.expires_at>UTC_TIMESTAMP()) AND {$alias}2.withdrawn_at IS NULL)";
    }
}
