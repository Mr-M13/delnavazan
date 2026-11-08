<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Private Teacher notes per canonical class occurrence.
 *
 * The owner is the Teacher principal on the Lesson. A note is never part of a Student read
 * model, notification payload, provider projection or finance fact. One row per
 * (Teacher, Lesson, applicable schedule version) keeps repeated saves idempotent.
 */
final class TeacherLessonNoteRepository {
    private string $p;
    public function __construct(){global $wpdb;$this->p=$wpdb->prefix.'dzn_';}

    public function forLesson(int $lessonId,int $teacherId):?object{
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT id,uid,lesson_id,schedule_version_id,teacher_id,student_id,note_text,note_version,created_at,updated_at
             FROM {$this->p}teacher_lesson_notes
             WHERE lesson_id=%d AND teacher_id=%d LIMIT 1",
            $lessonId,$teacherId
        ));
        if($wpdb->last_error!=='')throw new \RuntimeException('teacher_lesson_note_unavailable');
        return $row?:null;
    }

    /**
     * The latest non-empty note the same Teacher wrote for this Student before an occurrence.
     * The Lesson and schedule join prove the note belongs to a real canonical occurrence.
     */
    public function previousForStudent(int $teacherId,int $studentId,int $excludeLessonId,string $beforeStartsAtUtc):?object{
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT n.id,n.uid,n.lesson_id,n.schedule_version_id,n.note_text,n.updated_at,
                    l.uid lesson_uid,l.reference_code lesson_reference,v.starts_at_utc,v.ends_at_utc,v.schedule_timezone
             FROM {$this->p}teacher_lesson_notes n
             INNER JOIN {$this->p}lessons l ON l.id=n.lesson_id AND l.archived_at IS NULL
             INNER JOIN {$this->p}canonical_lesson_schedule_versions v ON v.id=n.schedule_version_id AND v.lesson_id=l.id
             WHERE n.teacher_id=%d AND n.student_id=%d AND n.lesson_id<>%d
               AND n.note_text IS NOT NULL AND TRIM(n.note_text)<>''
               AND v.starts_at_utc<%s
             ORDER BY v.starts_at_utc DESC,n.id DESC LIMIT 1",
            $teacherId,$studentId,$excludeLessonId,$beforeStartsAtUtc
        ));
        if($wpdb->last_error!=='')throw new \RuntimeException('teacher_lesson_note_unavailable');
        return $row?:null;
    }

    /**
     * Latest previous note for each requested Lesson, keyed by Lesson id.
     *
     * The Teacher Portal renders many cards at once, so this stays a single bounded query instead
     * of one note lookup per card.
     *
     * @param array<int,int|string> $lessonIds
     * @return array<int,string>
     */
    public function previousNotesForLessons(int $teacherId,array $lessonIds):array{
        $ids=array_values(array_unique(array_filter(array_map('intval',$lessonIds),static fn(int $id):bool=>$id>0)));
        if(!$ids)return array();
        global $wpdb;
        $placeholders=implode(',',array_fill(0,count($ids),'%d'));
        $args=array_merge(array($teacherId,$teacherId),$ids);
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT l.id lesson_id,(
                SELECT n2.note_text
                FROM {$this->p}teacher_lesson_notes n2
                INNER JOIN {$this->p}lessons l2 ON l2.id=n2.lesson_id AND l2.archived_at IS NULL
                INNER JOIN {$this->p}canonical_lesson_schedule_versions v2 ON v2.id=n2.schedule_version_id AND v2.lesson_id=l2.id
                WHERE n2.teacher_id=%d AND n2.student_id=l.student_id AND n2.lesson_id<>l.id
                  AND n2.note_text IS NOT NULL AND TRIM(n2.note_text)<>''
                  AND v2.starts_at_utc<v.starts_at_utc
                ORDER BY v2.starts_at_utc DESC,n2.id DESC LIMIT 1
             ) previous_note
             FROM {$this->p}lessons l
             INNER JOIN {$this->p}canonical_lesson_schedule_versions v ON v.lesson_id=l.id AND v.applicable_slot=1
             WHERE l.teacher_id=%d AND l.archived_at IS NULL AND l.id IN ({$placeholders})",
            ...$args
        ));
        if($wpdb->last_error!=='')throw new \RuntimeException('teacher_lesson_note_unavailable');
        $notes=array();
        foreach((array)$rows as $row){
            $text=trim((string)($row->previous_note??''));
            if(''!==$text)$notes[(int)$row->lesson_id]=$text;
        }
        return $notes;
    }

    public function save(int $lessonId,int $scheduleVersionId,int $teacherId,int $studentId,string $note,int $userId):object{
        global $wpdb;
        $existing=$this->forLesson($lessonId,$teacherId);
        $now=gmdate('Y-m-d H:i:s');
        if($existing){
            $changed=$wpdb->update(
                $this->p.'teacher_lesson_notes',
                array('schedule_version_id'=>$scheduleVersionId,'note_text'=>$note,'note_version'=>(int)$existing->note_version+1,'updated_at'=>$now,'updated_by'=>$userId),
                array('id'=>(int)$existing->id)
            );
            if($changed===false)throw new \RuntimeException('teacher_lesson_note_unavailable');
            return $this->forLesson($lessonId,$teacherId)
                ?? throw new \RuntimeException('teacher_lesson_note_unavailable');
        }
        $ok=$wpdb->insert($this->p.'teacher_lesson_notes',array(
            'uid'=>Identifier::uid(),
            'lesson_id'=>$lessonId,
            'schedule_version_id'=>$scheduleVersionId,
            'teacher_id'=>$teacherId,
            'student_id'=>$studentId,
            'note_text'=>$note,
            'note_version'=>1,
            'created_at'=>$now,
            'created_by'=>$userId,
            'updated_at'=>$now,
            'updated_by'=>$userId,
        ));
        if($ok===false){
            // A concurrent insert may have won the unique Teacher/Lesson key. Re-read and
            // update it instead of failing the Teacher's save.
            $existing=$this->forLesson($lessonId,$teacherId);
            if(!$existing)throw new \RuntimeException('teacher_lesson_note_unavailable');
            $wpdb->update(
                $this->p.'teacher_lesson_notes',
                array('schedule_version_id'=>$scheduleVersionId,'note_text'=>$note,'note_version'=>(int)$existing->note_version+1,'updated_at'=>$now,'updated_by'=>$userId),
                array('id'=>(int)$existing->id)
            );
        }
        return $this->forLesson($lessonId,$teacherId)
            ?? throw new \RuntimeException('teacher_lesson_note_unavailable');
    }
}
