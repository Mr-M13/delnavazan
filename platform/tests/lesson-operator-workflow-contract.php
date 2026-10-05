<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$screen=file_get_contents($root.'/src/Admin/Controller/ScreenController.php');
$read=file_get_contents($root.'/src/Core/Application/CoreReadService.php');
$workflow=file_get_contents($root.'/src/Core/Application/LessonOperationsReadService.php');
$navigation=file_get_contents($root.'/src/Admin/Controller/Menu.php');
$expect=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};

$expect(str_contains($navigation,"['dzn-classes-menu', 'Classes'")&&str_contains($navigation,"'dzn-lesson'"),'Classes must remain under the Classes / Schedule operator route.');
$expect(str_contains($screen,"if(\$entity==='lesson')")&&str_contains($screen,"lessonOperatorRows()")&&str_contains($screen,'<th>Student / course</th>')&&str_contains($screen,'<th>Class status</th>')&&str_contains($screen,'<th>Schedule</th>')&&str_contains($screen,'<th>Next step</th>'),'Class list must show a human workflow with its next operator step.');
$expect(str_contains($read,'public function lessonOperatorRows(): array')&&str_contains($read,'new LessonOperationsReadService())->recent()'),'Class rows must use the application read seam.');
$expect(str_contains($workflow,"current_user_can('dzn_manage_lessons')")&&str_contains($workflow,"current_user_can('dzn_manage_canonical_lessons')")&&str_contains($workflow,'CanonicalLessonAuthorityValidator::valid'),'Canonical class rows must stay behind their capability and validated lifecycle authority.');
$expect(str_contains($workflow,'CanonicalLessonScheduleReadService())->forLesson')&&str_contains($workflow,"current_user_can('dzn_manage_canonical_lesson_schedules')"),'Canonical schedule state must come from its protected, integrity-checked read authority.');
$expect(str_contains($screen,'lessonOperatorRecord($id)')&&str_contains($workflow,'public function operatorRecord(int $lessonId): ?object')&&str_contains($read,'public function canonicalLesson(int $lessonId): ?object'),'Canonical class detail must resolve through the application read seam.');
$expect(str_contains($screen,'if($canonicalLesson){if(current_user_can(\'dzn_manage_canonical_lesson_schedules\'))self::canonicalLessonSchedule($record)')&&str_contains($screen,'else self::lessonSchedules($id,(string)$record->status)')&&str_contains($screen,"lifecycle_state!=='authorised'"),'Legacy and canonical scheduling forms must not compete, and terminal canonical classes must be read-only.');
$expect(str_contains($workflow,"'Ready to schedule'")&&str_contains($workflow,"'Schedule class'")&&str_contains($workflow,"'Review class'"),'Class states must lead operators to a readable next action.');
$expect(str_contains($workflow,'usort($rows')&&str_contains($workflow,"'Schedule needs review'"),'Schedule integrity issues and unscheduled classes must surface ahead of completed records.');

echo "PASS lesson operator workflow, canonical authority handoff, and schedule ownership\n";
