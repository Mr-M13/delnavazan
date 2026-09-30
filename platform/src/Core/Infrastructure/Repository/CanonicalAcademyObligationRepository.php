<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Persistence boundary for canonical academy-owed occurrences.
 *
 * An academy obligation is DISTINCT canonical authority: it is not a Phase-M `replacement` Lesson,
 * it never consumes the Student's two-per-Term replacement allowance, and it is not capped by that
 * mechanism. It is bounded to one obligation per source occurrence by `UNIQUE(source_lesson_id)`.
 * Obligations are immutable and independent of Term lifecycle: closing a Term never removes them.
 */
final class CanonicalAcademyObligationRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}
    public function forLesson(int $lessonId,bool $lock=false):array{global $wpdb;$suffix=$lock?' FOR UPDATE':'';return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->p}canonical_academy_obligations WHERE source_lesson_id=%d ORDER BY id{$suffix}",$lessonId))?:array();}
    /**
     * Obligations that either CLAIM the Term selector or whose SOURCE canonical Lesson belongs to
     * the Term. The deliberate union means a corrupted stored selector is still selected — and then
     * fails canonical validation — instead of being silently omitted from the aggregate.
     */
    public function forTerm(int $termId,bool $lock=false):array{
        global $wpdb;$suffix=$lock?' FOR UPDATE':'';
        $sql="SELECT o.* FROM {$this->p}canonical_academy_obligations o WHERE o.term_id=%d OR o.source_lesson_id IN (SELECT l.id FROM {$this->p}lessons l WHERE l.term_id=%d AND l.record_model='canonical_term_lesson_v1') ORDER BY o.id{$suffix}";
        return $wpdb->get_results($wpdb->prepare($sql,$termId,$termId))?:array();
    }
    /** As forTerm(): the stored selector and the source Lesson relationship are both considered. */
    public function forEnrolment(int $enrolmentId,bool $lock=false):array{
        global $wpdb;$suffix=$lock?' FOR UPDATE':'';
        $sql="SELECT o.* FROM {$this->p}canonical_academy_obligations o WHERE o.enrolment_id=%d OR o.source_lesson_id IN (SELECT l.id FROM {$this->p}lessons l WHERE l.enrolment_id=%d AND l.record_model='canonical_term_lesson_v1') ORDER BY o.id{$suffix}";
        return $wpdb->get_results($wpdb->prepare($sql,$enrolmentId,$enrolmentId))?:array();
    }
    public function obligation(int $id,bool $lock=false):?object{global $wpdb;$suffix=$lock?' FOR UPDATE':'';return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->p}canonical_academy_obligations WHERE id=%d{$suffix}",$id));}
    public function insert(array $data):int{
        global $wpdb;
        $old=$wpdb->suppress_errors(true);
        $ok=$wpdb->insert($this->p.'canonical_academy_obligations',$data);
        $error=$wpdb->last_error;
        $wpdb->suppress_errors($old);
        if($ok===false)throw new \RuntimeException('Canonical academy obligation persistence failed: '.$error);
        return(int)$wpdb->insert_id;
    }
    public function duplicate(\Throwable $e):?string{
        if(!preg_match("/Duplicate entry .* for key ['`](?:[^'`.]+\\.)?([^'`]+)['`]/i",$e->getMessage(),$m))return null;
        return strtolower($m[1])==='source_lesson'?'source_lesson':null;
    }
}
