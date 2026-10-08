<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$screen=file_get_contents($root.'/src/Admin/Controller/ScreenController.php');
$read=file_get_contents($root.'/src/Core/Application/CoreReadService.php');
$enrolments=file_get_contents($root.'/src/Core/Application/EnrolmentOperationsReadService.php');
$classes=file_get_contents($root.'/src/Core/Application/LessonOperationsReadService.php');
$exceptions=file_get_contents($root.'/src/Core/Infrastructure/Repository/OperationalExceptionRepository.php');
$expect=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};

$expect(str_contains($screen,'private static function enrolmentWorkspace(): void')&&str_contains($screen,'Enrolments and learning progress')&&str_contains($screen,'<th>Student / course</th>')&&str_contains($screen,'instrument_name')&&str_contains($screen,'<th>Term / status</th>')&&str_contains($screen,'<th>Next class</th>')&&str_contains($screen,'<th>Attention</th>'),'Enrolments must present the daily relationship, course/instrument, progress and attention workspace.');
$expect(str_contains($screen,'enrolmentOperatorRows()')&&str_contains($screen,'enrolmentOperatorRecord($id)')&&str_contains($screen,'enrolmentOperatorClasses($id)'),'Enrolment pages must use the application read seam for list, detail and classes.');
$expect(str_contains($read,'public function enrolmentOperatorRows(): array')&&str_contains($read,'new EnrolmentOperationsReadService())->recent()')&&str_contains($read,'public function enrolmentOperatorRecord(int $enrolmentId): ?object'),'Core reads must keep the enrolment workspace capability-gated.');
$expect(str_contains($enrolments,"current_user_can('dzn_manage_enrolments')")&&str_contains($enrolments,'CanonicalEnrolmentLifecycleValidator::valid')&&str_contains($enrolments,'TeacherAssignmentAssessment::validHistory')&&str_contains($enrolments,'CanonicalTermReadService'),'Enrolment relationship status must compose existing validated lifecycle, assignment and term reads.');
$expect(str_contains($enrolments,"current_user_can('dzn_manage_lessons')")&&str_contains($enrolments,'LessonOperationsReadService())->forEnrolment')&&str_contains($enrolments,'Class details unavailable'),'Class detail must respect the existing class capability and report unavailable data truthfully.');
$expect(str_contains($screen,'Legacy manual class entry')&&str_contains($screen,'does not create an alternative canonical path'),'Manual class entry must remain secondary to the canonical workflow.');
$expect(str_contains($enrolments,'No current teacher assignment')&&str_contains($enrolments,'No current term')&&str_contains($enrolments,'activeCountFor(\'enrolment\'')&&str_contains($classes,'No schedule recorded')&&str_contains($classes,'Attendance needs integrity review')&&str_contains($classes,'Remedy: '),'Existing missing-assignment, missing-schedule, missing-term, attendance/remedy and exception states must surface as attention without a new business rule.');
$expect(str_contains($exceptions,'public function activeCountFor(string $entityType, int $entityId): int')&&str_contains($exceptions,"status IN ('open','acknowledged')"),'Exception indicators must use only active recorded exception state.');
foreach(array('insert(','update(','createStandard','schedule(','transition(') as $writer)$expect(!str_contains($enrolments,$writer),'Enrolment workspace must not introduce a writer: '.$writer);

echo "PASS enrolment/classes workspace read authority and attention contracts\n";
