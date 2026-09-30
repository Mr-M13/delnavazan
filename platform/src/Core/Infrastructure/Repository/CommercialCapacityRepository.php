<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Persistence boundary for the Phase 2A.2-R1 Regular pattern, current-Term protected capacity and
 * commercial exception authority.
 *
 * A protected claim interval is durable non-Lesson capacity: it protects a committed Teacher
 * interval without ever creating a Lesson, schedule version, Term or provider row. Capacity
 * arbitration reads this storage, Phase-N schedules and Phase-Q holds under the same per-Teacher
 * scheduling root, so an interval is never both protected and sold.
 */
final class CommercialCapacityRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}

    // ------------------------------------------------------------------------ Regular pattern

    public function pattern(int $patternId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}commercial_recurring_patterns WHERE id=%d".($lock?' FOR UPDATE':''),$patternId);}
    public function patternForSlotAuthority(int $slotAuthorityId,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}commercial_recurring_patterns WHERE source_slot_authority_id=%d".($lock?' FOR UPDATE':''),$slotAuthorityId);
    }
    public function activePatternFor(int $studentId,int $courseId,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}commercial_recurring_patterns WHERE student_id=%d AND course_id=%d AND state='active'".($lock?' FOR UPDATE':''),$studentId,$courseId);
    }
    public function patternsForStudent(int $studentId):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}commercial_recurring_patterns WHERE student_id=%d ORDER BY id",$studentId))?:array();
    }
    public function insertPattern(array $data):int{return $this->insert('commercial_recurring_patterns',$data,'Recurring pattern persistence failed');}
    public function supersedePattern(int $patternId,int $expectedVersion,?int $successorId,string $state,string $now,int $actor):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'commercial_recurring_patterns',array(
            'state'=>$state,'superseded_by_pattern_id'=>$successorId,'pattern_version'=>$expectedVersion+1,'updated_at'=>$now,'updated_by'=>$actor,
        ),array('id'=>$patternId,'pattern_version'=>$expectedVersion));
        if($changed!==1)throw new \RuntimeException('Stale recurring pattern');
    }

    // ------------------------------------------------------------- protected capacity claims

    public function claim(int $claimId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}commercial_capacity_claims WHERE id=%d".($lock?' FOR UPDATE':''),$claimId);}
    public function claimForEntitlement(int $entitlementId,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}commercial_capacity_claims WHERE entitlement_id=%d".($lock?' FOR UPDATE':''),$entitlementId);
    }
    public function claimsForTerm(int $termId,bool $lock=false):array{
        global $wpdb;$suffix=$lock?' FOR UPDATE':'';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}commercial_capacity_claims WHERE term_id=%d ORDER BY id{$suffix}",$termId))?:array();
    }
    public function activeClaimsForEnrolment(int $enrolmentId):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT c.* FROM {$this->p}commercial_capacity_claims c INNER JOIN {$this->p}commercial_term_funding_plans p ON p.term_id=c.term_id WHERE p.enrolment_id=%d AND c.state='active' ORDER BY c.id",$enrolmentId))?:array();
    }
    public function insertClaim(array $data):int{return $this->insert('commercial_capacity_claims',$data,'Protected capacity claim persistence failed');}
    public function updateClaimState(int $claimId,int $expectedVersion,string $state,?string $reason,string $now,int $actor):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'commercial_capacity_claims',array(
            'state'=>$state,'release_reason_code'=>$reason,'released_at'=>$now,'claim_version'=>$expectedVersion+1,'updated_at'=>$now,'updated_by'=>$actor,
        ),array('id'=>$claimId,'claim_version'=>$expectedVersion));
        if($changed!==1)throw new \RuntimeException('Stale protected capacity claim');
    }
    public function bindClaimToTerm(int $claimId,int $expectedVersion,int $termId,string $now,int $actor):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'commercial_capacity_claims',array(
            'term_id'=>$termId,'claim_version'=>$expectedVersion+1,'updated_at'=>$now,'updated_by'=>$actor,
        ),array('id'=>$claimId,'claim_version'=>$expectedVersion));
        if($changed!==1)throw new \RuntimeException('Stale protected capacity claim');
    }

    public function intervals(int $claimId,bool $lock=false):array{
        global $wpdb;$suffix=$lock?' FOR UPDATE':'';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}commercial_capacity_claim_intervals WHERE claim_id=%d ORDER BY interval_sequence,id{$suffix}",$claimId))?:array();
    }
    public function interval(int $intervalId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}commercial_capacity_claim_intervals WHERE id=%d".($lock?' FOR UPDATE':''),$intervalId);}
    /**
     * Capacity arbitration query: EVERY committed interval overlapping the exact Teacher interval,
     * whatever its state. The caller validates each row's claim aggregate before deciding, so a
     * corrupted interval can neither silently block nor silently disappear from arbitration; only a
     * valid `protected` interval blocks. The caller excludes only the claim interval that authorises
     * the exact Lesson being scheduled.
     */
    public function overlappingIntervals(int $teacherId,string $startsAt,string $occupiedEnd,int $excludeIntervalId=0,bool $lock=false):array{
        global $wpdb;$suffix=$lock?' FOR UPDATE':'';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}commercial_capacity_claim_intervals WHERE teacher_id=%d AND id<>%d AND starts_at_utc<%s AND occupied_ends_at_utc>%s ORDER BY id{$suffix}",$teacherId,$excludeIntervalId,$occupiedEnd,$startsAt))?:array();
    }
    public function protectedIntervalsForTeacher(int $teacherId,string $fromUtc,string $toUtc):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}commercial_capacity_claim_intervals WHERE teacher_id=%d AND state='protected' AND starts_at_utc>=%s AND starts_at_utc<%s ORDER BY starts_at_utc",$teacherId,$fromUtc,$toUtc))?:array();
    }
    public function insertInterval(array $data):int{return $this->insert('commercial_capacity_claim_intervals',$data,'Protected capacity interval persistence failed');}
    public function satisfyInterval(int $intervalId,int $expectedVersion,int $lessonId,int $scheduleVersionId,string $now,int $actor):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'commercial_capacity_claim_intervals',array(
            'state'=>'satisfied','satisfied_lesson_id'=>$lessonId,'satisfied_schedule_version_id'=>$scheduleVersionId,
            'satisfied_at'=>$now,'interval_version'=>$expectedVersion+1,'updated_at'=>$now,'updated_by'=>$actor,
        ),array('id'=>$intervalId,'interval_version'=>$expectedVersion,'state'=>'protected'));
        if($changed!==1)throw new \RuntimeException('Stale protected capacity interval');
    }
    public function reopenInterval(int $intervalId,int $expectedVersion,string $now,int $actor):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'commercial_capacity_claim_intervals',array(
            'state'=>'protected','satisfied_lesson_id'=>null,'satisfied_schedule_version_id'=>null,'satisfied_at'=>null,
            'interval_version'=>$expectedVersion+1,'updated_at'=>$now,'updated_by'=>$actor,
        ),array('id'=>$intervalId,'interval_version'=>$expectedVersion,'state'=>'satisfied'));
        if($changed!==1)throw new \RuntimeException('Stale protected capacity interval');
    }
    public function releaseInterval(int $intervalId,int $expectedVersion,string $now,int $actor):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'commercial_capacity_claim_intervals',array(
            'state'=>'released','released_at'=>$now,'interval_version'=>$expectedVersion+1,'updated_at'=>$now,'updated_by'=>$actor,
        ),array('id'=>$intervalId,'interval_version'=>$expectedVersion,'state'=>'protected'));
        if($changed!==1)throw new \RuntimeException('Stale protected capacity interval');
    }

    // ------------------------------------------------------------------ commercial exceptions

    public function openException(string $fingerprint,bool $lock=true):?object{
        return $this->one("SELECT * FROM {$this->p}commercial_exceptions WHERE fingerprint=%s AND state='open'".($lock?' FOR UPDATE':''),$fingerprint);
    }
    public function exception(int $exceptionId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}commercial_exceptions WHERE id=%d".($lock?' FOR UPDATE':''),$exceptionId);}
    public function exceptions(string $state='open'):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}commercial_exceptions WHERE state=%s ORDER BY id",$state))?:array();
    }
    public function exceptionsForStudent(int $studentId):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}commercial_exceptions WHERE student_id=%d ORDER BY id",$studentId))?:array();
    }
    public function insertException(array $data):int{return $this->insert('commercial_exceptions',$data,'Commercial exception persistence failed');}
    /**
     * Fingerprint deduplication is serialized with an advisory lock rather than a global unique
     * index, matching the operational-exception convention: a closed fault stays historical and a
     * later recurrence is free to create a new row.
     */
    public function acquireFingerprintLock(string $fingerprint):string{
        global $wpdb;
        $lock='dzn_commercial_exc_'.substr(hash('sha256',$fingerprint),0,40);
        $acquired=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)',$lock));
        if((string)$acquired!=='1')throw new \RuntimeException('Commercial exception deduplication lock unavailable');
        return $lock;
    }
    public function releaseFingerprintLock(string $lock):void{
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
    }
    public function touchException(int $exceptionId,int $count,string $now):void{
        global $wpdb;
        if($wpdb->update($this->p.'commercial_exceptions',array('occurrence_count'=>$count,'last_seen_at'=>$now),array('id'=>$exceptionId))===false)throw new \RuntimeException('Commercial exception update failed');
    }
    /**
     * Close an exception from its observed current state. The caller holds the row FOR UPDATE, so
     * the observed state is the optimistic guard (wpdb::update does not support IN in its WHERE).
     */
    public function resolveException(int $exceptionId,string $expectedState,string $state,string $now,int $actor,string $note):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'commercial_exceptions',array(
            'state'=>$state,'resolved_at'=>$now,'resolved_by'=>$actor,'resolution_note'=>$note,'last_seen_at'=>$now,
        ),array('id'=>$exceptionId,'state'=>$expectedState));
        if($changed!==1)throw new \RuntimeException('Stale commercial exception');
    }

    public function command(string $digest):?object{return $this->one("SELECT * FROM {$this->p}commercial_commands WHERE command_key_digest=%s",$digest);}
    public function insertCommand(array $data):int{return $this->insert('commercial_commands',$data,'Commercial command persistence failed');}

    public function duplicate(\Throwable $e):?string{
        if(!preg_match("/Duplicate entry .* for key ['`](?:[^'`.]+\\.)?([^'`]+)['`]/i",$e->getMessage(),$m))return null;
        $key=strtolower((string)$m[1]);
        return in_array($key,array('command_key_digest','uid','reference_code','source_slot_authority_id','claim_sequence','fingerprint_state'),true)?$key:null;
    }

    private function one(string $sql,...$args):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare($sql,...$args));}
    private function insert(string $table,array $data,string $message):int{
        global $wpdb;
        $old=$wpdb->suppress_errors(true);
        $ok=$wpdb->insert($this->p.$table,$data);
        $error=$wpdb->last_error;
        $wpdb->suppress_errors($old);
        if($ok===false)throw new \RuntimeException($message.': '.$error);
        return (int)$wpdb->insert_id;
    }
}
