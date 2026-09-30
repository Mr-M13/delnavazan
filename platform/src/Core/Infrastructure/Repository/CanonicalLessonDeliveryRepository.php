<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Persistence boundary for canonical Lesson delivery/attendance outcomes.
 *
 * The outcome history is append-only. The only mutation of an existing outcome row is
 * supersession (applicable slot cleared, superseded_at set, successor named); the recorded
 * fact itself is never rewritten. There is no mutable counter and no provider projection.
 */
final class CanonicalLessonDeliveryRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function begin():void{global $wpdb;if($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')===false||$wpdb->query('START TRANSACTION')===false)throw new \RuntimeException('Transaction start failed');}
    public function commit():void{global $wpdb;if($wpdb->query('COMMIT')===false)throw new \RuntimeException('Transaction commit failed');}
    public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}

    public function outcomesForLesson(int $lessonId,bool $lock=false):array{global $wpdb;$suffix=$lock?' FOR UPDATE':'';return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}canonical_lesson_delivery_outcomes WHERE lesson_id=%d ORDER BY outcome_sequence,id{$suffix}",$lessonId))?:array();}
    public function commandsForLesson(int $lessonId,bool $lock=false):array{global $wpdb;$suffix=$lock?' FOR UPDATE':'';return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}canonical_lesson_delivery_commands WHERE lesson_id=%d ORDER BY id{$suffix}",$lessonId))?:array();}
    public function applicableOutcome(int $lessonId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}canonical_lesson_delivery_outcomes WHERE lesson_id=%d AND applicable_slot=1".($lock?' FOR UPDATE':''),$lessonId);}
    public function outcome(int $outcomeId,bool $lock=false):?object{return $this->one("SELECT * FROM {$this->p}canonical_lesson_delivery_outcomes WHERE id=%d".($lock?' FOR UPDATE':''),$outcomeId);}
    public function maxOutcomeSequence(int $lessonId):int{global $wpdb;return(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(outcome_sequence),0) FROM {$this->p}canonical_lesson_delivery_outcomes WHERE lesson_id=%d",$lessonId));}
    public function academyObligationCount(int $termId):int{global $wpdb;return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->p}canonical_lesson_delivery_outcomes WHERE term_id=%d AND applicable_slot=1 AND remedy_class='academy_obligation'",$termId));}
    public function lessonIdsByEnrolment(int $enrolmentId):array{return $this->ids("SELECT DISTINCT lesson_id FROM {$this->p}canonical_lesson_delivery_outcomes WHERE enrolment_id=%d ORDER BY lesson_id",$enrolmentId);}
    public function lessonIdsByTerm(int $termId):array{return $this->ids("SELECT DISTINCT lesson_id FROM {$this->p}canonical_lesson_delivery_outcomes WHERE term_id=%d ORDER BY lesson_id",$termId);}
    public function command(string $digest):?object{return $this->one("SELECT * FROM {$this->p}canonical_lesson_delivery_commands WHERE command_key_digest=%s",$digest);}

    public function insertOutcome(array $data):int{return $this->insert('canonical_lesson_delivery_outcomes',$data,'Canonical Lesson delivery outcome persistence failed');}
    public function insertCommand(array $data):int{return $this->insert('canonical_lesson_delivery_commands',$data,'Canonical Lesson delivery command persistence failed');}

    /** Supersession is the only mutation of an existing outcome; the recorded fact stays immutable. */
    public function supersede(int $outcomeId,string $now,?int $successorOutcomeId):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'canonical_lesson_delivery_outcomes',array('applicable_slot'=>null,'superseded_at'=>$now,'superseded_by_outcome_id'=>$successorOutcomeId),array('id'=>$outcomeId,'applicable_slot'=>1,'superseded_at'=>null));
        if($changed!==1)throw new \RuntimeException('Stale canonical Lesson delivery outcome');
    }
    /** Record the successor of a superseded outcome so correction lineage stays complete. */
    public function setSuccessor(int $outcomeId,int $successorOutcomeId):void{
        global $wpdb;
        $changed=$wpdb->update($this->p.'canonical_lesson_delivery_outcomes',array('superseded_by_outcome_id'=>$successorOutcomeId),array('id'=>$outcomeId,'applicable_slot'=>null));
        if($changed!==1)throw new \RuntimeException('Stale canonical Lesson delivery outcome lineage');
    }
    public function duplicate(\Throwable $e):?string{
        if(!preg_match("/Duplicate entry .* for key ['`](?:[^'`.]+\\.)?([^'`]+)['`]/i",$e->getMessage(),$m))return null;
        $key=strtolower($m[1]);
        return in_array($key,array('command_key_digest','lesson_outcome_sequence','lesson_applicable_outcome'),true)?$key:null;
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
