<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Persistence boundary for canonical attendance intake, evidence and review decisions.
 *
 * This repository never writes Phase-O, Lesson lifecycle, obligation or replacement storage: it owns
 * intake cases, append-only evidence, append-only decisions/anomalies, digest-only command evidence
 * and the durable prospective-cutover policy row. Canonical delivery truth stays in Phase O.
 */
final class CanonicalAttendanceRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}

    /** One review aggregate per exact Lesson + canonical schedule version occurrence. */
    public function caseFor(int $lessonId,int $scheduleVersionId,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}canonical_attendance_cases WHERE lesson_id=%d AND schedule_version_id=%d".($lock?' FOR UPDATE':''),$lessonId,$scheduleVersionId);
    }
    public function caseById(int $caseId,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}canonical_attendance_cases WHERE id=%d".($lock?' FOR UPDATE':''),$caseId);
    }
    public function casesForLesson(int $lessonId,bool $lock=false):array{
        global $wpdb;$suffix=$lock?' FOR UPDATE':'';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}canonical_attendance_cases WHERE lesson_id=%d ORDER BY id{$suffix}",$lessonId))?:array();
    }
    public function casesForTerm(int $termId,bool $lock=false):array{
        global $wpdb;$suffix=$lock?' FOR UPDATE':'';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}canonical_attendance_cases WHERE term_id=%d ORDER BY id{$suffix}",$termId))?:array();
    }
    /** Cases whose source Lesson belongs to the Term or Enrolment, unioned with the stored selector. */
    public function casesForEnrolment(int $enrolmentId,bool $lock=false):array{
        global $wpdb;$suffix=$lock?' FOR UPDATE':'';
        $sql="SELECT c.* FROM {$this->p}canonical_attendance_cases c WHERE c.enrolment_id=%d OR c.lesson_id IN (SELECT l.id FROM {$this->p}lessons l WHERE l.enrolment_id=%d AND l.record_model='canonical_term_lesson_v1') ORDER BY c.id{$suffix}";
        return $wpdb->get_results($wpdb->prepare($sql,$enrolmentId,$enrolmentId))?:array();
    }
    public function insertCase(array $data):int{return $this->insert('canonical_attendance_cases',$data,'Canonical attendance case persistence failed');}
    public function updateCaseState(int $caseId,int $expectedVersion,string $state,?int $latestDecisionId,string $now,int $actor):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'canonical_attendance_cases',array(
            'state'=>$state,'case_version'=>$expectedVersion+1,'latest_decision_id'=>$latestDecisionId,'updated_at'=>$now,'updated_by'=>$actor,
        ),array('id'=>$caseId,'case_version'=>$expectedVersion));
        if($changed!==1)throw new \RuntimeException('Stale canonical attendance case');
    }

    public function evidenceForCase(int $caseId,bool $lock=false):array{
        global $wpdb;$suffix=$lock?' FOR UPDATE':'';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}canonical_attendance_evidence WHERE case_id=%d ORDER BY id{$suffix}",$caseId))?:array();
    }
    public function evidenceByEventKey(string $eventKeyDigest,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}canonical_attendance_evidence WHERE provider_event_key_digest=%s".($lock?' FOR UPDATE':''),$eventKeyDigest);
    }
    public function evidence(int $evidenceId,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}canonical_attendance_evidence WHERE id=%d".($lock?' FOR UPDATE':''),$evidenceId);
    }
    public function insertEvidence(array $data):int{return $this->insert('canonical_attendance_evidence',$data,'Canonical attendance evidence persistence failed');}

    public function decisionsForCase(int $caseId,bool $lock=false):array{
        global $wpdb;$suffix=$lock?' FOR UPDATE':'';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}canonical_attendance_decisions WHERE case_id=%d ORDER BY decision_sequence,id{$suffix}",$caseId))?:array();
    }
    public function decision(int $decisionId,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}canonical_attendance_decisions WHERE id=%d".($lock?' FOR UPDATE':''),$decisionId);
    }
    public function latestDecision(int $caseId,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}canonical_attendance_decisions WHERE case_id=%d ORDER BY decision_sequence DESC,id DESC LIMIT 1".($lock?' FOR UPDATE':''),$caseId);
    }
    public function maxDecisionSequence(int $caseId):int{
        global $wpdb;return(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(decision_sequence),0) FROM {$this->p}canonical_attendance_decisions WHERE case_id=%d",$caseId));
    }
    /** The highest recorded assessment overlap for one case; used for the final settlement record only. */
    public function maxAssessmentOverlap(int $caseId):int{
        global $wpdb;return(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(qualifying_overlap_seconds),0) FROM {$this->p}canonical_attendance_decisions WHERE case_id=%d AND decision_kind='assessment'",$caseId));
    }
    public function insertDecision(array $data):int{return $this->insert('canonical_attendance_decisions',$data,'Canonical attendance decision persistence failed');}

    public function anomaliesForCase(int $caseId):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}canonical_attendance_case_anomalies WHERE case_id=%d ORDER BY id",$caseId))?:array();
    }
    public function insertAnomaly(array $data):int{return $this->insert('canonical_attendance_case_anomalies',$data,'Canonical attendance anomaly persistence failed');}

    public function command(string $digest):?object{
        return $this->one("SELECT * FROM {$this->p}canonical_attendance_commands WHERE command_key_digest=%s",$digest);
    }
    public function insertCommand(array $data):int{return $this->insert('canonical_attendance_commands',$data,'Canonical attendance command persistence failed');}

    /**
     * Durable prospective cutover policy. No production cutover is performed by migration.
     *
     * Applicability is deterministic from the occurrence instant: the applicable policy for an
     * occurrence is the most recently activated policy whose cutover instant has already passed for
     * that occurrence. A later policy can never reinterpret an earlier admitted occurrence, because
     * every case stores the exact policy row that admitted it.
     */
    public function policy(int $policyId,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}canonical_attendance_cutover_policies WHERE id=%d".($lock?' FOR UPDATE':''),$policyId);
    }
    /** One immutable cutover policy per exact cutover instant. */
    public function policyForInstant(string $cutoverUtc,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}canonical_attendance_cutover_policies WHERE cutover_utc=%s".($lock?' FOR UPDATE':''),$cutoverUtc);
    }
    /** @return array<int,object> policies whose cutover has passed for this occurrence, newest cutover first. */
    public function policiesAtOrBefore(string $occurrenceStartUtc,bool $lock=false):array{
        global $wpdb;$suffix=$lock?' FOR UPDATE':'';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}canonical_attendance_cutover_policies WHERE cutover_utc<=%s ORDER BY cutover_utc DESC{$suffix}",$occurrenceStartUtc))?:array();
    }
    public function applicablePolicy(string $occurrenceStartUtc,bool $lock=false):?object{
        $rows=$this->policiesAtOrBefore($occurrenceStartUtc,$lock);
        if(!$rows)return null;
        // No database-insertion-id tie-breaker is ever permitted: if corrupted/legacy data contains
        // several policies at the same newest cutover instant, applicability is ambiguous and must
        // fail closed rather than silently pick one by id.
        $max=(string)$rows[0]->cutover_utc;
        $matching=array();
        foreach($rows as$row)if((string)$row->cutover_utc===$max)$matching[]=$row;
        if(count($matching)>1)throw new \RuntimeException('cutover_policy_ambiguous');
        return $matching[0];
    }
    public function latestPolicy():?object{
        return $this->one("SELECT * FROM {$this->p}canonical_attendance_cutover_policies ORDER BY cutover_utc DESC LIMIT 1");
    }
    public function insertCutoverPolicy(array $data):int{return $this->insert('canonical_attendance_cutover_policies',$data,'Canonical attendance cutover policy persistence failed');}

    /** Durable provider-neutral participant identity registry: the only provider identity authority. */
    public function mapping(int $mappingId,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}canonical_attendance_participant_mappings WHERE id=%d".($lock?' FOR UPDATE':''),$mappingId);
    }
    /** @return array<int,object> every mapping row recorded for one provider account and role */
    public function mappingsForAccount(string $providerCode,string $accountDigest,string $role,bool $lock=false):array{
        global $wpdb;$suffix=$lock?' FOR UPDATE':'';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}canonical_attendance_participant_mappings WHERE provider_code=%s AND provider_account_digest=%s AND participant_role=%s ORDER BY id{$suffix}",$providerCode,$accountDigest,$role))?:array();
    }
    /** @param array<int,string> $digests @return array<int,object> */
    public function mappingsForDigests(array $digests,bool $lock=false):array{
        global $wpdb;$digests=array_values(array_unique(array_filter($digests,static fn($d)=>is_string($d)&&$d!=='')));
        if(!$digests)return array();
        $suffix=$lock?' FOR UPDATE':'';
        $in=implode(',',array_fill(0,count($digests),'%s'));
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}canonical_attendance_participant_mappings WHERE provider_account_digest IN ({$in}) ORDER BY id{$suffix}",...$digests))?:array();
    }
    public function insertMapping(array $data):int{return $this->insert('canonical_attendance_participant_mappings',$data,'Canonical attendance participant mapping persistence failed');}
    public function updateMappingState(int $mappingId,int $expectedVersion,string $state,?string $revokedAt,string $now,int $actor):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'canonical_attendance_participant_mappings',array(
            'state'=>$state,'revoked_at'=>$revokedAt,'mapping_version'=>$expectedVersion+1,'updated_at'=>$now,'updated_by'=>$actor,
        ),array('id'=>$mappingId,'mapping_version'=>$expectedVersion));
        if($changed!==1)throw new \RuntimeException('Stale canonical attendance participant mapping');
    }

    /** Append-only durable conflict receipts: changed or cross-context provider events under one key. */
    public function insertConflict(array $data):int{return $this->insert('canonical_attendance_conflicts',$data,'Canonical attendance conflict persistence failed');}
    public function conflictsForCase(int $caseId):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}canonical_attendance_conflicts WHERE case_id=%d ORDER BY id",$caseId))?:array();
    }
    public function conflictForEventKey(string $eventKeyDigest,int $caseId):?object{
        return $this->one("SELECT * FROM {$this->p}canonical_attendance_conflicts WHERE provider_event_key_digest=%s AND case_id=%d ORDER BY id LIMIT 1",$eventKeyDigest,$caseId);
    }

    public function duplicate(\Throwable $e):?string{
        if(!preg_match("/Duplicate entry .* for key ['`](?:[^'`.]+\\.)?([^'`]+)['`]/i",$e->getMessage(),$m))return null;
        $key=strtolower($m[1]);
        return in_array($key,array('command_key_digest','provider_event_key_digest','case_occurrence','decision_sequence','uid','cutover_instant','provider_identity'),true)?$key:null;
    }
    /** Read-only transaction-state diagnostic: zero means no transaction is open on this connection. */
    public function openTransactions():int{
        global $wpdb;
        $value=$wpdb->get_var('SELECT @@in_transaction');
        return $value===null?-1:(int)$value;
    }
    private function one(string $sql,...$args):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare($sql,...$args));}
    private function insert(string $table,array $data,string $message):int{
        global $wpdb;
        $old=$wpdb->suppress_errors(true);
        $ok=$wpdb->insert($this->p.$table,$data);
        $error=$wpdb->last_error;
        $wpdb->suppress_errors($old);
        if($ok===false)throw new \RuntimeException($message.': '.$error);
        return(int)$wpdb->insert_id;
    }
}
