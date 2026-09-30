<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/** Persistence boundary for the Phase 2A.2-U per-Lesson snapshots and their correction chain. */
final class FinanceSnapshotRepository extends FinanceRepository {
    public function __construct(){parent::__construct();}

    public function byLesson(int $lessonId,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}finance_lesson_snapshots WHERE lesson_id=%d".($lock?' FOR UPDATE':''),$lessonId);
    }
    public function byId(int $id,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}finance_lesson_snapshots WHERE id=%d".($lock?' FOR UPDATE':''),$id);
    }
    public function insertSnapshot(array $data):int{return $this->insert('finance_lesson_snapshots',$data,'Lesson finance snapshot persistence failed');}
    /** The snapshot's stable public handle, assigned once after its insert (declared `reference_code`). */
    public function assignPublicHandle(int $snapshotId):void{$this->assignReference('finance_lesson_snapshots',$snapshotId,\Delnavazan\Platform\Core\Support\Identifier::reference('FINS',$snapshotId));}
    /** The newest applicable correction of a snapshot, or `null` when the snapshot stands uncorrected. */
    public function applicableCorrection(int $snapshotId,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}finance_snapshot_corrections WHERE snapshot_id=%d AND applicable_slot=1".($lock?' FOR UPDATE':''),$snapshotId);
    }
    public function corrections(int $snapshotId,bool $lock=false):array{
        return $this->many("SELECT * FROM {$this->p}finance_snapshot_corrections WHERE snapshot_id=%d ORDER BY correction_sequence,id".($lock?' FOR UPDATE':''),$snapshotId);
    }
    public function correctionById(int $id,bool $lock=false):?object{
        return $this->one("SELECT * FROM {$this->p}finance_snapshot_corrections WHERE id=%d".($lock?' FOR UPDATE':''),$id);
    }
    public function nextCorrectionSequence(int $snapshotId):int{
        return (int)$this->value("SELECT COALESCE(MAX(correction_sequence),0)+1 FROM {$this->p}finance_snapshot_corrections WHERE snapshot_id=%d",$snapshotId);
    }
    public function insertCorrection(array $data):int{return $this->insert('finance_snapshot_corrections',$data,'Snapshot correction persistence failed');}
    /** §15.4: the one conditional supersession statement of the correction chain. */
    public function supersedeCorrection(int $correctionId,int $successorId,string $now):int{
        global $wpdb;$table=$this->declared('finance_snapshot_corrections');
        $changed=$wpdb->query($wpdb->prepare("UPDATE {$table} SET superseded_at=%s,superseded_by_correction_id=%d,applicable_slot=NULL WHERE id=%d AND applicable_slot=1 AND superseded_by_correction_id IS NULL",$now,$successorId,$correctionId));
        if($changed===false)throw new \RuntimeException('Snapshot correction supersession failed: '.$wpdb->last_error);
        return (int)$changed;
    }
    /**
     * §15.4: the appended correction claims the snapshot's one applicable slot, after its predecessor
     * released it. `UNIQUE snapshot_applicable` admits exactly one non-NULL slot per snapshot, so the
     * append writes `applicable_slot = NULL` and this second conditional statement performs the claim.
     */
    public function claimApplicable(int $correctionId):int{
        global $wpdb;$table=$this->declared('finance_snapshot_corrections');
        $changed=$wpdb->query($wpdb->prepare("UPDATE {$table} SET applicable_slot=1 WHERE id=%d AND applicable_slot IS NULL AND superseded_by_correction_id IS NULL",$correctionId));
        if($changed===false)throw new \RuntimeException('Snapshot correction slot claim failed: '.$wpdb->last_error);
        return (int)$changed;
    }
    public function correctionsForLesson(int $lessonId):array{
        return $this->many("SELECT * FROM {$this->p}finance_snapshot_corrections WHERE lesson_id=%d ORDER BY id",$lessonId);
    }
    public function command(string $digest):?object{return $this->one("SELECT * FROM {$this->p}finance_snapshot_commands WHERE command_key_digest=%s",$digest);}
    public function commandById(int $id):?object{return $this->one("SELECT * FROM {$this->p}finance_snapshot_commands WHERE id=%d",$id);}
    public function insertCommand(array $data):int{return $this->insert('finance_snapshot_commands',$data,'Snapshot command persistence failed');}
}
