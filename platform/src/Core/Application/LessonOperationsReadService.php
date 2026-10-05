<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalLessonAuthorityRepository,LessonRepository,LessonScheduleVersionRepository};

/** Read-only operator queue for the Classes workflow. Canonical lesson and schedule facts are validated by their owners. */
final class LessonOperationsReadService {
    public function recent(): array {
        if (!current_user_can('dzn_manage_lessons')) throw new \RuntimeException('Unauthorized');
        global $wpdb; $p=$wpdb->prefix.'dzn_';
        $models=current_user_can('dzn_manage_canonical_lessons') ? "('legacy_phase1','canonical_term_lesson_v1')" : "('legacy_phase1')";
        $rows=$wpdb->get_results("SELECT l.id,l.reference_code,l.record_model,l.lesson_type,l.status,l.lifecycle_state,l.canonical_sequence,l.current_schedule_version_id,COALESCE(NULLIF(s.display_name,''),NULLIF(s.first_name,''),s.last_name) student_name,COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) teacher_name,COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name,legacy.local_wall_date,legacy.local_wall_time,legacy.schedule_timezone FROM {$p}lessons l LEFT JOIN {$p}students s ON s.id=l.student_id LEFT JOIN {$p}teachers t ON t.id=l.teacher_id LEFT JOIN {$p}courses c ON c.id=l.course_id LEFT JOIN {$p}lesson_schedule_versions legacy ON legacy.id=l.current_schedule_version_id AND legacy.lesson_id=l.id AND legacy.superseded_at IS NULL WHERE l.record_model IN {$models} AND l.archived_at IS NULL ORDER BY l.id DESC LIMIT 100");
        if($wpdb->last_error!=='')throw new \RuntimeException('Class operator read failed: '.$wpdb->last_error);
        $rows=is_array($rows)?$rows:array();
        foreach($rows as$row){
            $row->class_status='';$row->schedule_label='Not scheduled';$row->next_action='Open class';
            if($row->record_model==='legacy_phase1'){
                $row->class_status=match((string)$row->status){'draft'=>'Ready to schedule','scheduled'=>'Scheduled','completed'=>'Completed','cancelled'=>'Cancelled',default=>'Review class status'};
                if((int)$row->current_schedule_version_id>0&&$row->local_wall_date&&$row->local_wall_time)$row->schedule_label=(string)$row->local_wall_date.' · '.substr((string)$row->local_wall_time,0,5).' '.(string)$row->schedule_timezone;
                elseif((int)$row->current_schedule_version_id>0||$row->status==='scheduled'){$row->class_status='Schedule needs review';$row->schedule_label='Schedule unavailable';}
                $row->next_action=$row->status==='draft'?'Set class time':'Review class';
                continue;
            }
            try{
                $canonical=$this->canonicalLesson((int)$row->id);
                if(!$canonical)throw new \InvalidArgumentException('data_integrity_conflict');
                $row->class_status=match((string)$canonical->lifecycle_state){'authorised'=>'Ready to schedule','completed'=>'Completed','cancelled'=>'Cancelled',default=>'Review class status'};
                if(!current_user_can('dzn_manage_canonical_lesson_schedules')){$row->schedule_label='Scheduling access required';$row->next_action='Open class';continue;}
                $schedule=(new CanonicalLessonScheduleReadService())->forLesson((int)$row->id);
                if($schedule['active_version']){$active=$schedule['active_version'];$row->schedule_label=(string)$active->local_wall_date.' · '.substr((string)$active->local_wall_time,0,5).' '.(string)$active->schedule_timezone;if($canonical->lifecycle_state==='authorised')$row->class_status='Scheduled';}
                $row->next_action=$schedule['active_version']?'Review class':'Schedule class';
            }catch(\InvalidArgumentException){$row->class_status='Integrity review needed';$row->schedule_label='Schedule unavailable';$row->next_action='Review class';}
        }
        usort($rows,static function(object $a,object $b):int{
            $rank=static fn(object $row):int=>in_array($row->class_status,array('Integrity review needed','Schedule needs review'),true)?0:($row->class_status==='Ready to schedule'?1:($row->class_status==='Scheduled'?2:3));
            $order=$rank($a)<=>$rank($b);if($order!==0)return$order;
            if($rank($a)===2){$order=strcmp((string)$a->schedule_label,(string)$b->schedule_label);if($order!==0)return$order;}
            return (int)$b->id<=>(int)$a->id;
        });
        return $rows;
    }

    public function canonicalLesson(int $lessonId): ?object {
        if(!current_user_can('dzn_manage_canonical_lessons'))throw new \RuntimeException('Unauthorized');
        if($lessonId<1)throw new \InvalidArgumentException('Lesson identity required');
        $repository=new CanonicalLessonAuthorityRepository();$lesson=$repository->lesson($lessonId);
        if(!$lesson)return null;
        if(($lesson->record_model??null)!=='canonical_term_lesson_v1')return null;
        if(!CanonicalLessonAuthorityValidator::valid($lesson,$repository->events($lessonId),$repository))throw new \InvalidArgumentException('data_integrity_conflict');
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $labels=$wpdb->get_row($wpdb->prepare("SELECT COALESCE(NULLIF(s.display_name,''),NULLIF(s.first_name,''),s.last_name) student_name,COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) teacher_name,COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name FROM {$p}lessons l LEFT JOIN {$p}students s ON s.id=l.student_id LEFT JOIN {$p}teachers t ON t.id=l.teacher_id LEFT JOIN {$p}courses c ON c.id=l.course_id WHERE l.id=%d",$lessonId));
        if(!$labels||$wpdb->last_error!=='')throw new \RuntimeException('Class identity read failed: '.$wpdb->last_error);
        $lesson->student_name=(string)$labels->student_name;$lesson->teacher_name=(string)$labels->teacher_name;$lesson->course_name=(string)$labels->course_name;
        $lesson->class_status=match((string)$lesson->lifecycle_state){'authorised'=>'Ready to schedule','completed'=>'Completed','cancelled'=>'Cancelled',default=>'Review class status'};
        $lesson->schedule_label='Scheduling access required';
        if(current_user_can('dzn_manage_canonical_lesson_schedules')){
            try{$schedule=(new CanonicalLessonScheduleReadService())->forLesson($lessonId);$lesson->schedule_label='Not scheduled';
                if($schedule['active_version']){$active=$schedule['active_version'];$lesson->schedule_label=(string)$active->local_wall_date.' · '.substr((string)$active->local_wall_time,0,5).' '.(string)$active->schedule_timezone;if($lesson->lifecycle_state==='authorised')$lesson->class_status='Scheduled';}
            }catch(\InvalidArgumentException){$lesson->class_status='Integrity review needed';$lesson->schedule_label='Schedule unavailable';}
        }
        return $lesson;
    }

    public function operatorRecord(int $lessonId): ?object {
        if(!current_user_can('dzn_manage_lessons'))throw new \RuntimeException('Unauthorized');
        $lesson=(new LessonRepository())->find($lessonId);
        if(!$lesson)return current_user_can('dzn_manage_canonical_lessons')?$this->canonicalLesson($lessonId):null;
        global $wpdb;$p=$wpdb->prefix.'dzn_';
        $labels=$wpdb->get_row($wpdb->prepare("SELECT COALESCE(NULLIF(s.display_name,''),NULLIF(s.first_name,''),s.last_name) student_name,COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) teacher_name,COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name FROM {$p}lessons l LEFT JOIN {$p}students s ON s.id=l.student_id LEFT JOIN {$p}teachers t ON t.id=l.teacher_id LEFT JOIN {$p}courses c ON c.id=l.course_id WHERE l.id=%d",$lessonId));
        if(!$labels||$wpdb->last_error!=='')throw new \RuntimeException('Class identity read failed: '.$wpdb->last_error);
        $lesson->student_name=(string)$labels->student_name;$lesson->teacher_name=(string)$labels->teacher_name;$lesson->course_name=(string)$labels->course_name;
        $lesson->class_status=match((string)$lesson->status){'draft'=>'Ready to schedule','scheduled'=>'Scheduled','completed'=>'Completed','cancelled'=>'Cancelled',default=>'Review class status'};
        $schedule=(new LessonScheduleVersionRepository())->current($lessonId);$lesson->schedule_label=$schedule?(string)$schedule->local_wall_date.' · '.substr((string)$schedule->local_wall_time,0,5).' '.(string)$schedule->schedule_timezone:'Not scheduled';
        if((int)($lesson->current_schedule_version_id??0)!==(int)($schedule->id??0)){$lesson->class_status='Schedule needs review';$lesson->schedule_label='Schedule unavailable';}
        return $lesson;
    }
}
