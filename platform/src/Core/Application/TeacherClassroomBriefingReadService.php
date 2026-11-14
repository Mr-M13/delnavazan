<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\LessonRescheduleRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherLessonNoteRepository;
use Delnavazan\Platform\Portals\PortalPrincipalResolver;

/**
 * Read-only pre/post-class briefing for an owning Teacher.
 *
 * It composes the exact canonical occurrence, the Student's current-Term timeline, outstanding
 * Teacher actions derived from canonical attendance/reschedule facts, the Teacher's own previous
 * and current class notes, and whether the occurrence may still be confirmed as delivered.
 *
 * Nothing here is a Student-visible model and nothing writes authority. Attendance confirmation is
 * still performed by TeacherDeliveryConfirmationService.
 */
final class TeacherClassroomBriefingReadService {
    private const UNRESOLVED_ATTENDANCE=array('open','ready_for_review','settlement_pending');

    public function __construct(
        private ?TeacherLessonNoteRepository $notes=null,
        private ?LessonRescheduleRepository $reschedules=null
    ){
        $this->notes??=new TeacherLessonNoteRepository();
        $this->reschedules??=new LessonRescheduleRepository();
    }

    /** @return array<string,mixed>|null */
    public function forLesson(string $lessonUid,string $scheduleUid):?array{
        $lessonUid=trim($lessonUid);
        $scheduleUid=trim($scheduleUid);
        if(!preg_match('/^[0-9A-Z]{26}$/D',$lessonUid)||!preg_match('/^[0-9A-Z]{26}$/D',$scheduleUid))return null;

        try{
            $principal=(new PortalPrincipalResolver())->resolve('teacher');
        }catch(\Throwable){return null;}
        $teacherId=(int)($principal['id']??0);
        if($teacherId<1)return null;
        // Match the join boundary: the owning Teacher principal and the portal-schedule
        // capability are sufficient. Availability rules are an onboarding concern and must not
        // hide the lesson briefing or its private note.
        if(!current_user_can('dzn_view_own_portal_schedule'))return null;

        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $lesson=$wpdb->get_row($wpdb->prepare(
            "SELECT l.id,l.uid,l.reference_code,l.teacher_id,l.student_id,l.course_id,l.term_id,l.lesson_type AS lesson_kind,
                    l.lifecycle_state,l.record_model,l.archived_at,l.canonical_sequence,
                    l.canonical_replacement_origin_lesson_id,
                    COALESCE(NULLIF(i.name_fa,''),NULLIF(i.name_en,''),NULLIF(c.name_fa,''),NULLIF(c.name_en,''),'') course_name,
                    COALESCE(NULLIF(s.display_name,''),NULLIF(s.reference_code,''),'هنرجو') student_name,
                    s.reference_code student_reference,s.whatsapp_phone,s.phone,
                    COALESCE(NULLIF(t.display_name,''),NULLIF(t.persian_name,''),NULLIF(t.english_name,'')) teacher_name
             FROM {$p}lessons l
             LEFT JOIN {$p}courses c ON c.id=l.course_id
             LEFT JOIN {$p}instruments i ON i.id=c.instrument_id
             LEFT JOIN {$p}students s ON s.id=l.student_id
             LEFT JOIN {$p}teachers t ON t.id=l.teacher_id
             WHERE l.uid=%s AND l.archived_at IS NULL LIMIT 1",
            $lessonUid
        ));
        if(!$lesson||$wpdb->last_error!=='')return null;
        if((string)$lesson->record_model!=='canonical_term_lesson_v1')return null;
        if((int)$lesson->teacher_id!==$teacherId)return null;

        $schedule=$wpdb->get_row($wpdb->prepare(
            "SELECT id,uid,lesson_id,starts_at_utc,ends_at_utc,schedule_timezone,applicable_slot
             FROM {$p}canonical_lesson_schedule_versions
             WHERE uid=%s AND lesson_id=%d LIMIT 1",
            $scheduleUid,(int)$lesson->id
        ));
        if(!$schedule||(int)$schedule->applicable_slot!==1||$wpdb->last_error!=='')return null;

        $term=$this->term((int)($lesson->term_id??0),(int)$lesson->student_id,(int)$lesson->id,(string)$schedule->starts_at_utc);
        $previous=null;
        $note=null;
        try{
            $previous=$this->notes->previousForStudent($teacherId,(int)$lesson->student_id,(int)$lesson->id,(string)$schedule->starts_at_utc);
            $note=$this->notes->forLesson((int)$lesson->id,$teacherId);
        }catch(\Throwable){
            // A pending note-store migration must not hide the Term timeline or outstanding actions.
        }
        $completion=$this->completion($lesson,$schedule,(int)$teacherId);

        return array(
            'lesson'=>array(
                'lesson_id'=>(int)$lesson->id,
                'lesson_uid'=>(string)$lesson->uid,
                'reference_code'=>(string)$lesson->reference_code,
                'teacher_id'=>(int)$lesson->teacher_id,
                'student_id'=>(int)$lesson->student_id,
                'course_id'=>(int)$lesson->course_id,
                'term_id'=>(int)($lesson->term_id??0),
                'course_name'=>(string)$lesson->course_name,
                'lesson_kind'=>(string)$lesson->lesson_kind,
                'lifecycle_state'=>(string)$lesson->lifecycle_state,
                'canonical_sequence'=>(int)($lesson->canonical_sequence??0),
                'is_replacement'=>null!==$lesson->canonical_replacement_origin_lesson_id,
                'starts_at_utc'=>(string)$schedule->starts_at_utc,
                'ends_at_utc'=>(string)$schedule->ends_at_utc,
                'schedule_timezone'=>(string)$schedule->schedule_timezone,
                'schedule_version_id'=>(int)$schedule->id,
                'schedule_version_uid'=>(string)$schedule->uid,
            ),
            'student'=>array(
                'name'=>(string)$lesson->student_name,
                'reference'=>(string)($lesson->student_reference??''),
                'whatsapp'=>(string)($lesson->whatsapp_phone??$lesson->phone??''),
            ),
            'teacher'=>array(
                'name'=>(string)($lesson->teacher_name??''),
            ),
            'term'=>$term,
            'attention'=>$this->attention($teacherId,(int)$lesson->student_id,(int)($lesson->term_id??0),$term['timeline'],(string)$schedule->ends_at_utc),
            'previous_note'=>$this->noteModel($previous),
            'note'=>$this->noteModel($note),
            'completion'=>$completion,
        );
    }

    /** @return array<string,mixed> */
    private function term(int $termId,int $studentId,int $currentLessonId,string $currentStartsAtUtc):array{
        $empty=array('term_id'=>$termId,'sequence'=>0,'total'=>0,'completed'=>0,'cancelled'=>0,'remaining'=>0,'replacement_available'=>0,'timeline'=>array(),'current_sequence'=>0);
        if($termId<1)return $empty;
        global $wpdb;$p=$wpdb->prefix.'dzn_';

        $aggregate=$wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) total,SUM(lifecycle_state='completed') completed,SUM(lifecycle_state='cancelled') cancelled
             FROM {$p}lessons
             WHERE term_id=%d AND student_id=%d AND record_model='canonical_term_lesson_v1' AND archived_at IS NULL",
            $termId,$studentId
        ));
        if(!$aggregate||$wpdb->last_error!=='')return $empty;
        $termRow=$wpdb->get_row($wpdb->prepare("SELECT sequence_number,replacement_allowance FROM {$p}terms WHERE id=%d",$termId));
        $total=max(0,(int)($aggregate->total??0));
        $completed=max(0,(int)($aggregate->completed??0));
        $cancelled=max(0,(int)($aggregate->cancelled??0));

        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT l.id lesson_id,l.uid lesson_uid,l.reference_code,l.canonical_sequence,l.lesson_type AS lesson_kind,l.lifecycle_state,
                    v.starts_at_utc,v.ends_at_utc,v.schedule_timezone,
                    (SELECT d.outcome_code FROM {$p}canonical_lesson_delivery_outcomes d
                     WHERE d.lesson_id=l.id AND d.applicable_slot=1 LIMIT 1) delivery_outcome,
                    (SELECT a.state FROM {$p}canonical_attendance_cases a
                     WHERE a.lesson_id=l.id AND a.schedule_version_id=v.id ORDER BY a.id DESC LIMIT 1) attendance_state
             FROM {$p}lessons l
             INNER JOIN {$p}canonical_lesson_schedule_versions v ON v.lesson_id=l.id AND v.applicable_slot=1
             WHERE l.term_id=%d AND l.student_id=%d AND l.record_model='canonical_term_lesson_v1' AND l.archived_at IS NULL
             ORDER BY v.starts_at_utc,l.id LIMIT 80",
            $termId,$studentId
        ));
        if($wpdb->last_error!=='')return $empty;

        $timeline=array();
        $currentSequence=0;
        foreach((array)$rows as $row){
            if((int)$row->lesson_id===$currentLessonId)$currentSequence=(int)($row->canonical_sequence??0);
            $timeline[]=array(
                'lesson_id'=>(int)$row->lesson_id,
                'lesson_uid'=>(string)$row->lesson_uid,
                'reference_code'=>(string)$row->reference_code,
                'sequence'=>(int)($row->canonical_sequence??0),
                'lesson_kind'=>(string)$row->lesson_kind,
                'lifecycle_state'=>(string)$row->lifecycle_state,
                'starts_at_utc'=>(string)$row->starts_at_utc,
                'ends_at_utc'=>(string)$row->ends_at_utc,
                'schedule_timezone'=>(string)$row->schedule_timezone,
                'delivery_outcome'=>(string)($row->delivery_outcome??''),
                'attendance_state'=>(string)($row->attendance_state??''),
                'is_current'=>(int)$row->lesson_id===$currentLessonId,
            );
        }

        $replacementAvailable=max(0,(int)($termRow->replacement_allowance??0));
        try{
            $allocation=$this->reschedules->termReplacementAllocation($termId);
            $replacementAvailable=max(0,(int)($allocation['available']??$replacementAvailable));
        }catch(\Throwable){}

        return array(
            'term_id'=>$termId,
            'sequence'=>(int)($termRow->sequence_number??0),
            'total'=>$total,
            'completed'=>$completed,
            'cancelled'=>$cancelled,
            'remaining'=>max(0,$total-$completed-$cancelled),
            'replacement_available'=>$replacementAvailable,
            'timeline'=>$timeline,
            'current_sequence'=>$currentSequence,
        );
    }

    /**
     * Only actionable facts for this Student's current Term are returned. A row is a prompt to
     * open the Teacher Portal; it never claims attendance, entitlement or a final outcome.
     *
     * @param array<int,array<string,mixed>> $timeline
     * @return array<int,array<string,mixed>>
     */
    private function attention(int $teacherId,int $studentId,int $termId,array $timeline,string $currentEndsAtUtc):array{
        if($termId<1)return array();
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $items=array();

        $proposals=$wpdb->get_results($wpdb->prepare(
            "SELECT r.uid proposal_uid,r.proposed_starts_at_utc,r.schedule_timezone,
                    l.uid lesson_uid,l.reference_code
             FROM {$p}lesson_reschedule_proposals r
             INNER JOIN {$p}lessons l ON l.id=r.lesson_id AND l.archived_at IS NULL AND l.term_id=%d
             WHERE r.teacher_id=%d AND r.student_id=%d AND r.state='pending'
               AND (r.expires_at IS NULL OR r.expires_at>UTC_TIMESTAMP())
             ORDER BY r.proposed_starts_at_utc,r.id LIMIT 10",
            $termId,$teacherId,$studentId
        ));
        if($wpdb->last_error!=='')$proposals=array();
        foreach((array)$proposals as $row){
            $items[]=array(
                'kind'=>'reschedule_pending',
                'title'=>'پیشنهاد زمان در انتظار پاسخ هنرجو',
                'message'=>'پیشنهاد شما برای این هنرجو ارسال شده و هنوز پذیرفته یا رد نشده است.',
                'when'=>(string)$row->proposed_starts_at_utc,
                'timezone'=>(string)$row->schedule_timezone,
                'lesson_uid'=>(string)$row->lesson_uid,
                'lesson_reference'=>(string)$row->reference_code,
                'action_url'=>add_query_arg('teacher-view','classes',home_url('/teacher-portal/')),
                'action_label'=>'مشاهده در پرتال',
            );
        }

        $cases=$wpdb->get_results($wpdb->prepare(
            "SELECT c.id case_id,c.state,l.uid lesson_uid,l.reference_code,v.starts_at_utc,v.ends_at_utc,v.schedule_timezone
             FROM {$p}canonical_attendance_cases c
             INNER JOIN {$p}lessons l ON l.id=c.lesson_id AND l.archived_at IS NULL AND l.term_id=%d
             INNER JOIN {$p}canonical_lesson_schedule_versions v ON v.id=c.schedule_version_id
             WHERE c.teacher_id=%d AND c.student_id=%d AND c.state IN ('open','ready_for_review','settlement_pending')
             ORDER BY c.occurrence_start_utc,c.id LIMIT 10",
            $termId,$teacherId,$studentId
        ));
        if($wpdb->last_error!=='')$cases=array();
        foreach((array)$cases as $row){
            $items[]=array(
                'kind'=>'attendance_pending',
                'title'=>'وضعیت حضور نیازمند پیگیری است',
                'message'=>'برای این جلسه گزارش یا مورد حضوری ثبت شده که هنوز نهایی نشده است.',
                'when'=>(string)$row->starts_at_utc,
                'timezone'=>(string)$row->schedule_timezone,
                'lesson_uid'=>(string)$row->lesson_uid,
                'lesson_reference'=>(string)$row->reference_code,
                'action_url'=>add_query_arg('teacher-view','classes',home_url('/teacher-portal/')),
                'action_label'=>'بررسی در پرتال',
            );
        }

        // A past occurrence in the Term that still has no delivery outcome is the Teacher's next
        // factual action: confirm the class or report a disruption from the portal.
        $now=time();
        foreach($timeline as $row){
            $end=(int)(strtotime((string)($row['ends_at_utc']??'').' UTC')?:0);
            if($end<1||$end>$now)continue;
            if('authorised'!==(string)($row['lifecycle_state']??''))continue;
            if(''!==trim((string)($row['delivery_outcome']??'')))continue;
            if(in_array((string)($row['attendance_state']??''),self::UNRESOLVED_ATTENDANCE,true))continue;
            $items[]=array(
                'kind'=>'attendance_unconfirmed',
                'title'=>'حضور جلسهٔ گذشته تأیید نشده است',
                'message'=>'زمان این جلسه گذشته و هنوز گزارش برگزاری و حضور برای آن ثبت نشده است.',
                'when'=>(string)($row['starts_at_utc']??''),
                'timezone'=>(string)($row['schedule_timezone']??''),
                'lesson_uid'=>(string)($row['lesson_uid']??''),
                'lesson_reference'=>(string)($row['reference_code']??''),
                'action_url'=>home_url('/teacher-portal/'),
                'action_label'=>'ثبت در پرتال',
            );
        }

        return array_slice($items,0,8);
    }

    /** @return array<string,mixed> */
    private function completion(object $lesson,object $schedule,int $teacherId):array{
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $outcome=$wpdb->get_row($wpdb->prepare(
            "SELECT outcome_code,delivery_state,attendance_state FROM {$p}canonical_lesson_delivery_outcomes
             WHERE lesson_id=%d AND applicable_slot=1 LIMIT 1",
            (int)$lesson->id
        ));
        if($wpdb->last_error!=='')$outcome=null;
        $case=$wpdb->get_row($wpdb->prepare(
            "SELECT state FROM {$p}canonical_attendance_cases
             WHERE lesson_id=%d AND schedule_version_id=%d ORDER BY id DESC LIMIT 1",
            (int)$lesson->id,(int)$schedule->id
        ));
        if($wpdb->last_error!=='')$case=null;

        $outcomeCode=strtolower(trim((string)($outcome->outcome_code??'')));
        $caseState=strtolower(trim((string)($case->state??'')));
        $lifecycle=(string)$lesson->lifecycle_state;
        $end=(int)(strtotime((string)$schedule->ends_at_utc.' UTC')?:0);
        $ended=$end>0&&$end<=time();
        $reviewPending=in_array($caseState,self::UNRESOLVED_ATTENDANCE,true);
        $recorded=''!==$outcomeCode;
        $delivered=in_array($outcomeCode,array('delivered','completed'),true);
        $completed=$delivered;

        $available=$ended
            && !$recorded
            && in_array($lifecycle,array('authorised','completed'),true)
            && !$reviewPending;

        $labels=array(
            'completed'=>'برگزار شد','delivered'=>'برگزار شد','cancelled'=>'لغو شده',
            'no_show'=>'حضور تأیید نشده','student_no_show'=>'غیبت هنرجو ثبت شده',
            'teacher_no_show'=>'عدم برگزاری ثبت شده','not_delivered'=>'برگزار نشد',
            'review_required'=>'در انتظار بررسی','presumed_completed'=>'پایان زمان‌بندی‌شده؛ حضور تأیید نشده',
        );
        $outcomeLabel=$recorded?($labels[$outcomeCode]??'وضعیت برگزاری ثبت شده'):'';
        if(''===$outcomeLabel&&'completed'===$lifecycle)$outcomeLabel='پایان‌یافته — گزارش برگزاری ثبت نشده';

        if($completed){
            $message='این کلاس تکمیل شده است و فرم گزارش برگزاری برای آن نمایش داده نمی‌شود.';
        }elseif($reviewPending){
            $message='این کلاس در انتظار بررسی حضور است؛ گزارش تازه‌ای ثبت نمی‌شود.';
        }elseif(!$ended){
            $message='پس از پایان زمان کلاس می‌توانید برگزاری و حضور را تأیید کنید.';
        }elseif($recorded){
            $message='برای این کلاس پیش‌تر وضعیت برگزاری ثبت شده است.';
        }elseif(!in_array($lifecycle,array('authorised','completed'),true)){
            $message='این کلاس در وضعیت قابل گزارش نیست.';
        }else{
            $message='اگر کلاس برگزار شد، برگزاری و حضور را تأیید کنید.';
        }

        return array(
            'available'=>$available,
            'completed'=>$completed,
            'review_pending'=>$reviewPending,
            'recorded'=>$recorded,
            'outcome_code'=>$outcomeCode,
            'outcome_label'=>$outcomeLabel,
            'message'=>$message,
            'action_url'=>admin_url('admin-post.php?action=dzn_teacher_report_delivery'),
            'nonce'=>wp_create_nonce('dzn_teacher_report_delivery'),
        );
    }

    /** @return array<string,mixed>|null */
    private function noteModel(?object $row):?array{
        if(!$row)return null;
        $text=trim((string)($row->note_text??''));
        if(''===$text)return null;
        return array(
            'text'=>$text,
            'updated_at'=>(string)($row->updated_at??''),
            'lesson_reference'=>(string)($row->lesson_reference??''),
            'lesson_uid'=>(string)($row->lesson_uid??''),
            'starts_at_utc'=>(string)($row->starts_at_utc??''),
            'schedule_timezone'=>(string)($row->schedule_timezone??''),
        );
    }
}
