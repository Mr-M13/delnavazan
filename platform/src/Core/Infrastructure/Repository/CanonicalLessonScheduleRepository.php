<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Persistence boundary for canonical Lesson scheduling and Teacher occupancy.
 *
 * Teacher occupancy is DERIVED from applicable canonical schedule versions. There is no
 * separate mutable reservation projection and no mutable capacity counter: the only
 * serialization device is the per-Teacher scheduling root, which holds no authority state.
 */
final class CanonicalLessonScheduleRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}

    /**
     * Ensure and lock the per-Teacher scheduling root.
     *
     * The root serializes scheduling for one Teacher, including the empty-range case where
     * no reservation row yet exists to lock. READ COMMITTED disables gap locking, so this
     * row is the only correct serialization authority.
     */
    public function ensureAndLockTeacherRoot(int $teacherId,string $now,int $actor):object{
        global $wpdb;
        $sql=$wpdb->prepare("INSERT INTO {$this->p}teacher_schedule_roots(teacher_id,created_at,created_by) VALUES(%d,%s,%d) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",$teacherId,$now,$actor);
        if($wpdb->query($sql)===false)throw new \RuntimeException('Teacher scheduling root persistence failed: '.$wpdb->last_error);
        $id=(int)$wpdb->insert_id;
        if($id<1)$id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->p}teacher_schedule_roots WHERE teacher_id=%d",$teacherId));
        $row=$id>0?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}teacher_schedule_roots WHERE id=%d FOR UPDATE",$id)):null;
        if(!$row||(int)$row->teacher_id!==$teacherId)throw new \RuntimeException('Teacher scheduling root lock failed');
        return $row;
    }
    public function teacherRoot(int $teacherId):?object{global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}teacher_schedule_roots WHERE teacher_id=%d",$teacherId));}

    public function versionsForLesson(int $lessonId,bool $lock=false):array{global $wpdb;$suffix=$lock?' FOR UPDATE':'';return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}canonical_lesson_schedule_versions WHERE lesson_id=%d ORDER BY version_number,id{$suffix}",$lessonId))?:array();}
    public function eventsForLesson(int $lessonId,bool $lock=false):array{global $wpdb;$suffix=$lock?' FOR UPDATE':'';return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}canonical_lesson_schedule_events WHERE lesson_id=%d ORDER BY event_sequence,id{$suffix}",$lessonId))?:array();}
    public function applicableVersion(int $lessonId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}canonical_lesson_schedule_versions WHERE lesson_id=%d AND applicable_slot=1".($lock?' FOR UPDATE':''),$lessonId);}
    public function version(int $versionId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}canonical_lesson_schedule_versions WHERE id=%d".($lock?' FOR UPDATE':''),$versionId);}

    /** Lessons with any canonical schedule aggregate in the scope, ordered deterministically. */
    public function lessonIdsByEnrolment(int $enrolmentId):array{return $this->ids("SELECT DISTINCT lesson_id FROM {$this->p}canonical_lesson_schedule_versions WHERE enrolment_id=%d ORDER BY lesson_id",$enrolmentId);}
    public function lessonIdsByTerm(int $termId):array{return $this->ids("SELECT DISTINCT lesson_id FROM {$this->p}canonical_lesson_schedule_versions WHERE term_id=%d ORDER BY lesson_id",$termId);}
    public function lessonIdsByTeacher(int $teacherId):array{return $this->ids("SELECT DISTINCT lesson_id FROM {$this->p}canonical_lesson_schedule_versions WHERE teacher_id=%d ORDER BY lesson_id",$teacherId);}

    /** Occupied-interval overlap authority: [starts_at, occupied_end) half-open semantics. */
    public function overlappingApplicable(int $teacherId,string $startsAt,string $occupiedEnd,int $excludeLessonId):array{
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}canonical_lesson_schedule_versions WHERE teacher_id=%d AND applicable_slot=1 AND lesson_id<>%d AND starts_at_utc<%s AND occupied_ends_at_utc>%s ORDER BY id",$teacherId,$excludeLessonId,$occupiedEnd,$startsAt))?:array();
    }
    public function maxVersionNumber(int $lessonId):int{global $wpdb;return(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(version_number),0) FROM {$this->p}canonical_lesson_schedule_versions WHERE lesson_id=%d",$lessonId));}
    public function command(string $digest):?object{return $this->one("SELECT * FROM {$this->p}canonical_lesson_schedule_commands WHERE command_key_digest=%s",$digest);}
    public function commandByPayload(string $payload):?object{return $this->one("SELECT * FROM {$this->p}canonical_lesson_schedule_commands WHERE command_payload_digest=%s LIMIT 1",$payload);}

    public function insertVersion(array $data):int{return $this->insert('canonical_lesson_schedule_versions',$data,'Canonical schedule version persistence failed');}
    public function insertEvent(array $data):int{return $this->insert('canonical_lesson_schedule_events',$data,'Canonical schedule event persistence failed');}
    public function insertCommand(array $data):int{return $this->insert('canonical_lesson_schedule_commands',$data,'Canonical schedule command persistence failed');}

    /** Supersession is the only mutation of an existing version; all facts stay immutable. */
    public function supersede(int $versionId,string $now,?int $successorVersionId):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'canonical_lesson_schedule_versions',array('applicable_slot'=>null,'superseded_at'=>$now,'superseded_by_version_id'=>$successorVersionId),array('id'=>$versionId,'applicable_slot'=>1,'superseded_at'=>null));
        if($changed!==1)throw new \RuntimeException('Stale canonical schedule version');
    }
    /** Record the successor of a superseded version so history lineage stays complete. */
    public function setSuccessor(int $versionId,int $successorVersionId):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'canonical_lesson_schedule_versions',array('superseded_by_version_id'=>$successorVersionId),array('id'=>$versionId,'applicable_slot'=>null));
        if($changed!==1)throw new \RuntimeException('Stale canonical schedule version lineage');
    }
    public function duplicate(\Throwable $e):?string{
        if(!preg_match("/Duplicate entry .* for key ['`](?:[^'`.]+\\.)?([^'`]+)['`]/i",$e->getMessage(),$m))return null;
        $key=strtolower($m[1]);
        return in_array($key,array('command_key_digest','lesson_version','lesson_applicable','lesson_sequence','teacher'),true)?$key:null;
    }
    private function ids(string $sql,int $id):array{
        global $wpdb;
        $rows=$wpdb->get_col($wpdb->prepare($sql,$id))?:array();
        return array_map('intval',$rows);
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
