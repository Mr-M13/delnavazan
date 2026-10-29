<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Integrations\Notifications\DelnavazanEmailRenderer;
use Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue;
use Delnavazan\Platform\Integrations\Notifications\NotificationCopyResolver;

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
            "SELECT t.id teacher_id,t.email,t.timezone teacher_timezone,COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name,'') teacher_name,
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
        // The notice is addressed to the Teacher, so the Teacher's configured zone wins when it is valid;
        // the class schedule zone remains the fallback for an unset legacy profile.
        $timezone=trim((string)($row->teacher_timezone??''));
        if(!in_array($timezone,timezone_identifiers_list(),true))$timezone=trim((string)($row->schedule_timezone??'UTC'));
        try{$zone=new \DateTimeZone($timezone);}catch(\Throwable){$zone=new \DateTimeZone('UTC');}
        $when=wp_date('Y-m-d H:i',(int)strtotime((string)$row->starts_at_utc.' UTC'),$zone);
        $name=trim((string)$row->teacher_name);
        $past='attendance_claim'===$claimKind;
        $message=$past
            ? 'هنرجو '.$row->student_name.' اعلام کرده است که جلسهٔ «'.$row->course_name.'» در '.$when.' (' . $timezone . ') را از دست داده است. لطفاً برای جلسهٔ جایگزین با هنرجو هماهنگ کنید و زمان تازه را در پرتال مدرس ثبت کنید.'
            : 'هنرجو '.$row->student_name.' برای جلسهٔ «'.$row->course_name.'» در '.$when.' (' . $timezone . ') اطلاع غیبت فرستاده است. لطفاً با هنرجو هماهنگ کنید و در صورت توافق، زمان همین جلسه را در پرتال مدرس به زمان جایگزین تغییر دهید.';
        $subject='دلنوازان · اطلاع غیبت هنرجو';
        $body='سلام'.(''!==$name?' '.$name:'')."،\n\n".$message."\n\nاین درخواست در «کارهای مهم» پرتال مدرس نیز نمایش داده می‌شود.\n\nدلنوازان";
        $teacherId=(int)($row->teacher_id??0);
        $eventKey=$past?'attendance.missed_class_claim.teacher':'attendance.absence_reported.teacher';
        $portal=home_url('/teacher-portal/');
        $variables=array('teacher'=>$name,'student'=>trim((string)$row->student_name),'course'=>trim((string)$row->course_name),'when'=>$when,'timezone'=>$timezone,'teacher_portal_url'=>$portal);
        $copy=array('subject'=>$subject,'body'=>$body);
        if(class_exists(NotificationCopyResolver::class)){
            $copy=NotificationCopyResolver::email($eventKey,NotificationCatalogue::PRIMARY_LOCALE,$variables,$copy);
            if($teacherId>0&&class_exists(PortalNotificationRecorder::class))PortalNotificationRecorder::teacher($teacherId,$eventKey,'attendance_notification',$lessonId,$variables,$portal);
        }
        try{
            if(class_exists(DelnavazanEmailRenderer::class))return DelnavazanEmailRenderer::send($email,$copy['subject'],$copy['body'],array('locale'=>NotificationCatalogue::PRIMARY_LOCALE,'cta'=>array('url'=>(string)($copy['cta_url']??$portal),'label'=>'رفتن به پرتال مدرس')));
            return (bool)wp_mail($email,$copy['subject'],$copy['body']);
        }catch(\Throwable){return false;}
    }
}
