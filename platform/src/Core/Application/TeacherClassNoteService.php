<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\PrincipalInvitationRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherLessonNoteRepository;
use Delnavazan\Platform\Portals\PortalPrincipalResolver;

/**
 * Writes a private Teacher note for one owned canonical class occurrence.
 *
 * The note is deliberately not attendance evidence, a completion report or a Student-visible
 * instruction. Attendance stays with TeacherDeliveryConfirmationService; this service only stores
 * the Teacher's own memory aid for the next class.
 */
final class TeacherClassNoteService {
    public const MAX_LENGTH=5000;

    public function __construct(private ?TeacherLessonNoteRepository $notes=null){
        $this->notes??=new TeacherLessonNoteRepository();
    }

    /** @return array{lesson_id:int,schedule_version_id:int,note_text:string,updated_at:string,saved:bool} */
    public function saveForLesson(string $lessonUid,string $scheduleUid,string $note):array{
        $lessonUid=trim($lessonUid);
        $scheduleUid=trim($scheduleUid);
        if(!preg_match('/^[0-9A-Z]{26}$/D',$lessonUid)||!preg_match('/^[0-9A-Z]{26}$/D',$scheduleUid))throw new \InvalidArgumentException('portal_object_not_found');
        $note=trim($note);
        if(mb_strlen($note)>self::MAX_LENGTH)throw new \InvalidArgumentException('teacher_note_too_long');

        $principal=(new PortalPrincipalResolver())->resolve('teacher');
        $teacherId=(int)($principal['id']??0);
        if($teacherId<1)throw new \InvalidArgumentException('portal_principal_required');
        if(!(new PrincipalInvitationRepository())->hasActiveTeacherAuthority((int)get_current_user_id(),$teacherId))throw new \InvalidArgumentException('teacher_onboarding_required');

        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $lesson=$wpdb->get_row($wpdb->prepare(
            "SELECT id,teacher_id,student_id,record_model,lifecycle_state,archived_at
             FROM {$p}lessons WHERE uid=%s AND archived_at IS NULL LIMIT 1",
            $lessonUid
        ));
        if(!$lesson||$wpdb->last_error!=='')throw new \InvalidArgumentException('portal_object_not_found');
        if((string)$lesson->record_model!=='canonical_term_lesson_v1')throw new \InvalidArgumentException('canonical_lesson_required');
        if((int)$lesson->teacher_id!==$teacherId)throw new \InvalidArgumentException('portal_object_not_owned');
        if((string)$lesson->lifecycle_state==='cancelled')throw new \InvalidArgumentException('portal_object_not_portal_visible');

        $schedule=$wpdb->get_row($wpdb->prepare(
            "SELECT id,lesson_id,applicable_slot FROM {$p}canonical_lesson_schedule_versions WHERE uid=%s LIMIT 1",
            $scheduleUid
        ));
        if(!$schedule||(int)$schedule->lesson_id!==(int)$lesson->id||(int)$schedule->applicable_slot!==1)throw new \InvalidArgumentException('schedule_version_conflict');

        $saved=$this->notes->save(
            (int)$lesson->id,
            (int)$schedule->id,
            $teacherId,
            (int)$lesson->student_id,
            $note,
            (int)get_current_user_id()
        );
        return array(
            'lesson_id'=>(int)$lesson->id,
            'schedule_version_id'=>(int)$schedule->id,
            'note_text'=>(string)($saved->note_text??''),
            'updated_at'=>(string)($saved->updated_at??''),
            'saved'=>true,
        );
    }
}
