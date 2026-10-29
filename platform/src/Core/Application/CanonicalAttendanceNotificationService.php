<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Immediate Teacher notice for a Student-owned absence claim.
 *
 * Delivery is best-effort and happens after the attendance claim has committed. The durable pending
 * task is the Teacher portal read, not this email; a transport failure must never roll back evidence.
 */
final class CanonicalAttendanceNotificationService {
    public function notifyTeacher(int $lessonId,int $scheduleVersionId,string $claimKind):bool{
        if($lessonId<1||$scheduleVersionId<1)return false;
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT t.email,COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name,'') teacher_name,
                    COALESCE(NULLIF(s.display_name,''),NULLIF(s.reference_code,''),'هنرجو') student_name,
                    v.starts_at_utc,v.ends_at_utc,v.schedule_timezone,
                    COALESCE(NULLIF(i.name_fa,''),NULLIF(c.name_fa,''),NULLIF(c.name_en,''),'کلاس') course_name
             FROM {$p}lessons l
             INNER JOIN {$p}canonical_lesson_schedule_versions v ON v.id=%d AND v.lesson_id=l.id
             INNER JOIN {$p}teachers t ON t.id=l.teacher_id AND t.archived_at IS NULL
             INNER JOIN {$p}students s ON s.id=l.student_id
             LEFT JOIN {$p}courses c ON c.id=l.course_id
             LEFT JOIN {$p}instruments i ON i.id=c.instrument_id
             WHERE l.id=%d AND l.archived_at IS NULL LIMIT 1",
            $scheduleVersionId,$lessonId
        ));
        if(!$row||trim((string)($row->email??''))==='')return false;
        $email=(string)apply_filters('dzn_attendance_notification_recipient',(string)$row->email,$lessonId,$scheduleVersionId);
        if(!is_email($email))return false;
        $timezone=(string)($row->schedule_timezone??'UTC');
        try{$zone=new \DateTimeZone($timezone);}catch(\Throwable){$zone=new \DateTimeZone('UTC');}
        $when=wp_date('Y-m-d H:i',(int)strtotime((string)$row->starts_at_utc.' UTC'),$zone);
        $name=trim((string)$row->teacher_name);
        $past='attendance_claim'===$claimKind;
        $message=$past
            ? 'هنرجو '.$row->student_name.' اعلام کرده است که جلسهٔ «'.$row->course_name.'» در '.$when.' (' . $timezone . ') را از دست داده است. لطفاً برای جلسهٔ جایگزین با هنرجو هماهنگ کنید و زمان تازه را در پرتال مدرس ثبت کنید.'
            : 'هنرجو '.$row->student_name.' برای جلسهٔ «'.$row->course_name.'» در '.$when.' (' . $timezone . ') اطلاع غیبت فرستاده است. لطفاً با هنرجو هماهنگ کنید و در صورت توافق، زمان همین جلسه را در پرتال مدرس به زمان جایگزین تغییر دهید.';
        $subject='دلنوازان · اطلاع غیبت هنرجو';
        $body='سلام'.(''!==$name?' '.$name:'')."،\n\n".$message."\n\nاین درخواست در «کارهای مهم» پرتال مدرس نیز نمایش داده می‌شود.\n\nدلنوازان";
        try{return (bool)wp_mail($email,$subject,$body);}catch(\Throwable){return false;}
    }
}
