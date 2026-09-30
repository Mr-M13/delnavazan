<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Support\Identifier;

/** Bounded operational seam: reads and records evidence; it never creates Core domain rows. */
final class CoreDatasetReadinessService {
    private const SCOPES = array('enrolments','teacher_assignments','canonical_lessons');
    private const TABLES = array('enrolments'=>'enrolments','teacher_assignments'=>'teacher_assignments','canonical_lessons'=>'lessons');
    /**
     * Project one bounded scope's ordered digest, then persist that very run as append-only evidence.
     *
     * The projection itself writes no Core row and repairs no mismatch: the run row, and on a mismatch its
     * deterministic findings, are the only writes, so the recorded reconciliation digest, result, actor and
     * mismatch evidence survive the request that produced them. Those writes are one transaction — opened
     * before the run row is inserted and held until every required finding is persisted — so a mismatched
     * run never becomes durable without its findings: any failed insert rolls the whole run back.
     *
     * The projection read is itself fail-closed: WordPress returns an empty array from `get_results()` for a
     * failed query while `$wpdb->last_error` carries the failure, so the failure state is preserved and
     * checked before the digest is computed and before the evidence transaction opens. A read failure is
     * therefore thrown, never projected: no zero-count digest is hashed and no run or finding row is written.
     */
    public function reconcile(string $scope, int $expectedCount, string $expectedDigest): array {
        if (!in_array($scope,self::SCOPES,true) || $expectedCount < 0 || !preg_match('/^[a-f0-9]{64}$/D',$expectedDigest)) throw new \InvalidArgumentException('Bounded Core reconciliation input required');
        $actor=get_current_user_id(); if($actor<1) throw new \RuntimeException('Core reconciliation actor unavailable');
        global $wpdb; $table=$wpdb->prefix.'dzn_'.self::TABLES[$scope]; $where=$scope==='canonical_lessons' ? " WHERE record_model='canonical_term_lesson_v1'" : ($scope==='teacher_assignments' ? " WHERE state IN ('assigned','replaced')" : '');
        $startedAt=gmdate('Y-m-d H:i:s'); $rows=$wpdb->get_results("SELECT id,uid,created_by,created_at FROM {$table}{$where} ORDER BY id ASC",ARRAY_A); $readFailure=(string)$wpdb->last_error;
        if(!is_array($rows)||$readFailure!=='')throw new \RuntimeException('Core reconciliation projection read failed'.($readFailure!==''?': '.$readFailure:''));
        $actualCount=count($rows); $actualDigest=hash('sha256',wp_json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        $match=$expectedCount===$actualCount && hash_equals($expectedDigest,$actualDigest); $completedAt=gmdate('Y-m-d H:i:s');
        $runs=$wpdb->prefix.'dzn_core_dataset_reconciliation_runs';
        if($wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Core reconciliation persistence failed: '.$wpdb->last_error);
        try{
            if($wpdb->insert($runs,array('uid'=>Identifier::uid(),'scope_kind'=>$scope,'expected_digest'=>$expectedDigest,'actual_digest'=>$actualDigest,'expected_count'=>$expectedCount,'actual_count'=>$actualCount,'match_state'=>$match?'matched':'mismatched','started_at'=>$startedAt,'completed_at'=>$completedAt,'recorded_by'=>$actor,'created_at'=>$completedAt,'created_by'=>$actor))===false)throw new \RuntimeException('Core reconciliation persistence failed: '.$wpdb->last_error);
            $runId=(int)$wpdb->insert_id; self::recordFindings($runId,$scope,$expectedCount,$actualCount,$expectedDigest,$actualDigest,$match,$completedAt,$actor);
            if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Core reconciliation persistence failed: '.$wpdb->last_error);
        }catch(\Throwable $e){$wpdb->query('ROLLBACK');throw $e;}
        return array('run_id'=>$runId,'scope'=>$scope,'expected_count'=>$expectedCount,'actual_count'=>$actualCount,'expected_digest'=>$expectedDigest,'actual_digest'=>$actualDigest,'match'=>$match);
    }
    /**
     * The declared mismatch findings for one failed run, in a fixed order and with digests only, so a
     * mismatch always records the same bounded evidence and a matched run records no finding at all.
     * Runs inside the caller's reconciliation transaction, so the run row and its findings commit together.
     */
    private static function recordFindings(int $runId,string $scope,int $expectedCount,int $actualCount,string $expectedDigest,string $actualDigest,bool $match,string $now,int $actor): void {
        if($match)return; global $wpdb; $table=$wpdb->prefix.'dzn_core_dataset_reconciliation_findings'; $findings=array();
        if($expectedCount!==$actualCount)$findings[]=array('finding_code'=>'scope_count_mismatch','expected_digest'=>null,'actual_digest'=>null,'detail_digest'=>hash('sha256',wp_json_encode(array('expected_count'=>$expectedCount,'actual_count'=>$actualCount),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)));
        if(!hash_equals($expectedDigest,$actualDigest))$findings[]=array('finding_code'=>'scope_digest_mismatch','expected_digest'=>$expectedDigest,'actual_digest'=>$actualDigest,'detail_digest'=>hash('sha256',wp_json_encode(array('scope'=>$scope),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)));
        foreach($findings as $index=>$finding)if($wpdb->insert($table,array('uid'=>Identifier::uid(),'run_id'=>$runId,'finding_sequence'=>$index+1,'entity_kind'=>$scope,'entity_id'=>null,'finding_code'=>$finding['finding_code'],'expected_digest'=>$finding['expected_digest'],'actual_digest'=>$finding['actual_digest'],'detail_digest'=>$finding['detail_digest'],'created_at'=>$now,'created_by'=>$actor))===false)throw new \RuntimeException('Core reconciliation finding persistence failed: '.$wpdb->last_error);
    }
    public function recordOperation(string $operation,string $targetKind,?int $targetId,string $reason,string $evidenceChannel,string $evidenceReference,string $key,array $result): int {
        $actor=get_current_user_id(); if($actor<1) throw new \RuntimeException('Core readiness actor unavailable'); if($operation===''||$targetKind===''||$reason===''||$evidenceChannel===''||trim($evidenceReference)==='') throw new \InvalidArgumentException('Complete operator evidence required'); if(strlen(trim($key))<24) throw new \InvalidArgumentException('Operator idempotency key required');
        global $wpdb; $table=$wpdb->prefix.'dzn_core_dataset_operator_commands'; $now=gmdate('Y-m-d H:i:s'); $evidenceReferenceDigest=hash('sha256',$evidenceReference); $payload=array('operation'=>$operation,'target_kind'=>$targetKind,'target_id'=>$targetId,'reason_code'=>$reason,'evidence_channel'=>$evidenceChannel,'evidence_reference_digest'=>$evidenceReferenceDigest,'result'=>$result); $keyDigest=hash_hmac('sha256',trim($key),wp_salt('dzn_core_dataset_readiness')); $payloadDigest=hash('sha256',wp_json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        $existing=$wpdb->get_row($wpdb->prepare("SELECT id,command_payload_digest FROM {$table} WHERE command_key_digest=%s",$keyDigest));
        if($existing)return self::replayedCommandId($existing,$payloadDigest);
        $ok=$wpdb->insert($table,array('uid'=>Identifier::uid(),'operation'=>$operation,'command_key_digest'=>$keyDigest,'command_payload_digest'=>$payloadDigest,'target_kind'=>$targetKind,'target_id'=>$targetId,'result_state'=>(string)($result['state']??'recorded'),'result_id'=>isset($result['id'])?(int)$result['id']:null,'evidence_channel'=>$evidenceChannel,'evidence_reference_digest'=>$evidenceReferenceDigest,'reason_code'=>$reason,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor));
        if($ok===false){
            $existing=$wpdb->get_row($wpdb->prepare("SELECT id,command_payload_digest FROM {$table} WHERE command_key_digest=%s",$keyDigest));
            if($existing)return self::replayedCommandId($existing,$payloadDigest);
            throw new \RuntimeException('Core operator evidence persistence failed: '.$wpdb->last_error);
        }
        return (int)$wpdb->insert_id;
    }
    /**
     * An exact replay of a completed readiness operation converges on the recorded row rather than
     * failing on the unique command key; only a same-key/different-payload replay fails closed. The
     * payload digest binds the evidence-reference digest the row stores, so replaying a key against a
     * different operator evidence reference is a conflicting payload and is refused.
     */
    private static function replayedCommandId(object $existing,string $payloadDigest): int {
        if($existing->command_payload_digest===null||!hash_equals((string)$existing->command_payload_digest,$payloadDigest))throw new \RuntimeException('Core operator evidence replay conflict');
        return (int)$existing->id;
    }
    public function recordCorrection(string $entityKind,int $entityId,string $priorDigest,string $correctedDigest,string $reason,string $channel,string $reference,string $note=''): int {
        $actor=get_current_user_id(); if($actor<1)throw new \RuntimeException('Core correction actor unavailable'); foreach(array($priorDigest,$correctedDigest) as $digest)if(!preg_match('/^[a-f0-9]{64}$/D',$digest))throw new \InvalidArgumentException('Correction digest required'); global $wpdb; $table=$wpdb->prefix.'dzn_core_dataset_corrections'; $sequence=(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(correction_sequence),0)+1 FROM {$table} WHERE entity_kind=%s AND entity_id=%d",$entityKind,$entityId)); $now=gmdate('Y-m-d H:i:s'); $ok=$wpdb->insert($table,array('uid'=>Identifier::uid(),'entity_kind'=>$entityKind,'entity_id'=>$entityId,'correction_sequence'=>$sequence,'prior_digest'=>$priorDigest,'corrected_digest'=>$correctedDigest,'reason_code'=>$reason,'note_digest'=>$note===''?null:hash('sha256',$note),'evidence_channel'=>$channel,'evidence_reference_digest'=>hash('sha256',$reference),'evidence_at'=>$now,'recorded_at'=>$now,'recorded_by'=>$actor,'created_at'=>$now,'created_by'=>$actor)); if($ok===false)throw new \RuntimeException('Core correction evidence persistence failed: '.$wpdb->last_error); return(int)$wpdb->insert_id;
    }
}
