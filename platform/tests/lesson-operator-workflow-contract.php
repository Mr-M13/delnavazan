<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$screen=file_get_contents($root.'/src/Admin/Controller/ScreenController.php');
$read=file_get_contents($root.'/src/Core/Application/CoreReadService.php');
$workflow=file_get_contents($root.'/src/Core/Application/LessonOperationsReadService.php');
$navigation=file_get_contents($root.'/src/Admin/Controller/Menu.php');
$expect=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};

$expect(str_contains($navigation,"['Classes', 'dzn_manage_lessons', 'dzn-lesson'")&&str_contains($navigation,'add_submenu_page(self::ROOT_SLUG, $label, $label, $capability, $slug, $callback)'),'Classes must remain a capability-gated Delnavazan application route.');
$expect(str_contains($screen,"if(\$entity==='lesson')")&&str_contains($screen,"lessonOperatorRows()")&&str_contains($screen,'<th>Date / time</th>')&&str_contains($screen,'<th>Student / enrolment</th>')&&str_contains($screen,'<th>Attendance / outcome</th>')&&str_contains($screen,'<th>Attention</th>'),'Class list must show its operational relationship, timing, attendance outcome and attention state.');
$expect(str_contains($read,'public function lessonOperatorRows(): array')&&str_contains($read,'new LessonOperationsReadService())->recent()'),'Class rows must use the application read seam.');
$expect(str_contains($workflow,"current_user_can('dzn_manage_lessons')")&&str_contains($workflow,"current_user_can('dzn_manage_canonical_lessons')")&&str_contains($workflow,'CanonicalLessonAuthorityValidator::valid'),'Canonical class rows must stay behind their capability and validated lifecycle authority.');
$expect(str_contains($workflow,'CanonicalLessonScheduleReadService())->forLesson')&&str_contains($workflow,"current_user_can('dzn_manage_canonical_lesson_schedules')"),'Canonical schedule state must come from its protected, integrity-checked read authority.');
$expect(str_contains($screen,'lessonOperatorRecord($id)')&&str_contains($workflow,'public function operatorRecord(int $lessonId): ?object')&&str_contains($read,'public function canonicalLesson(int $lessonId): ?object'),'Canonical class detail must resolve through the application read seam.');
$expect(str_contains($screen,'if($canonicalLesson){if(current_user_can(\'dzn_manage_canonical_lesson_schedules\'))self::canonicalLessonSchedule($record)')&&str_contains($screen,'else self::lessonSchedules($id,(string)$record->status,(int)($record->current_schedule_version_id??0))')&&str_contains($screen,"lifecycle_state!=='authorised'"),'Legacy and canonical scheduling forms must not compete, and terminal canonical classes must be read-only.');
$expect(str_contains($workflow,"'Ready to schedule'")&&str_contains($workflow,"'Open class'")&&str_contains($workflow,"'Review class'"),'Class states must lead operators to a readable next action without competing with schedule authority.');
$expect(str_contains($workflow,'usort($rows')&&str_contains($workflow,"'Schedule needs review'"),'Schedule integrity issues and unscheduled classes must surface ahead of completed records.');
$expect(str_contains($workflow,"\$canonical->lifecycle_state === 'authorised') \$row->class_status = 'Scheduled'")&&str_contains($workflow,'validatedCanonicalLesson'),'Canonical class status must reflect an applicable schedule without changing its lifecycle authority.');
$expect(str_contains($workflow,'CanonicalLessonDeliveryReadService')&&str_contains($workflow,'CanonicalAttendanceReadService')&&str_contains($workflow,"current_user_can('dzn_view_canonical_attendance_review')"),'Class delivery and attendance summaries must use their protected read models only when the relevant capability exists.');
$expect(str_contains($workflow,'OperationalExceptionRepository')&&str_contains($workflow,"activeCountFor('lesson'")&&str_contains($workflow,"current_user_can('dzn_manage_exceptions')"),'Class exception indicators must be privacy-minimised and capability-gated.');
$expect(str_contains($screen,"'standard'=>'Regular class'")&&str_contains($screen,"'replacement'=>'Replacement class'"),'Class type labels must read clearly on the operator detail screen.');
$expect(str_contains($screen,"array('version_number','local_wall_date','local_wall_time','schedule_timezone')")&&str_contains($screen,'schedulePointer!==(int)'),'Legacy schedule detail must be curated and stale pointers must stop scheduling actions.');

echo "PASS lesson operator workflow, canonical authority handoff, and schedule ownership\n";
