<?php
use Delnavazan\Platform\Core\Application\CanonicalTeacherOccupancyReadService;
use Delnavazan\Platform\Core\Application\CanonicalLessonScheduleValidator;

$root=dirname(__DIR__);
require $root.'/src/Core/Application/AvailabilityLocalTime.php';
require $root.'/src/Core/Application/CanonicalLessonScheduleValidator.php';
require $root.'/src/Core/Application/CanonicalTeacherOccupancyReadService.php';
$expect=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$overlap=static fn(string $a,string $b,string $c,string $d):bool=>CanonicalTeacherOccupancyReadService::overlaps($a,$b,$c,$d);
$existingStart='2026-10-02 02:00:00';$existingLessonEnd='2026-10-02 02:30:00';$existingOccupiedEnd='2026-10-02 02:45:00';
$expect($overlap($existingStart,$existingOccupiedEnd,'2026-10-02 02:15:00','2026-10-02 03:00:00'),'direct overlap must conflict');
$expect($overlap($existingStart,$existingOccupiedEnd,'2026-10-02 02:30:00','2026-10-02 03:15:00'),'candidate beginning during the lesson must conflict');
$expect($overlap($existingStart,$existingOccupiedEnd,'2026-10-02 01:30:00','2026-10-02 02:15:00'),'candidate ending during the lesson must conflict');
$expect($overlap($existingStart,$existingOccupiedEnd,'2026-10-02 02:05:00','2026-10-02 02:20:00'),'candidate inside an existing lesson must conflict');
$expect($overlap('2026-10-02 02:10:00','2026-10-02 02:25:00',$existingStart,$existingOccupiedEnd),'existing lesson inside candidate occupancy must conflict');
$expect($overlap($existingLessonEnd,$existingOccupiedEnd,'2026-10-02 02:40:00','2026-10-02 03:25:00'),'buffer-only collision must conflict');
$expect(!$overlap($existingStart,$existingOccupiedEnd,'2026-10-02 02:45:00','2026-10-02 03:30:00'),'exact allowed boundary must not conflict');
$expect(!$overlap('2026-10-02 02:45:00','2026-10-02 03:30:00',$existingStart,$existingOccupiedEnd),'adjacent lessons must not conflict');
$expect(!$overlap($existingStart,$existingOccupiedEnd,'2026-10-02 02:45:01','2026-10-02 03:30:01'),'non-overlap must remain available');

$utc=static function(string $date,string $time,string $zone):string{$wall=\Delnavazan\Platform\Core\Application\AvailabilityLocalTime::wall($date,$time,$zone);return $wall->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');};
$expect($utc('2026-10-02','12:00:00','Australia/Brisbane')===$utc('2026-10-02','07:45:00','Asia/Kathmandu'),'same UTC instant in different display zones must share collision identity');
$expect($utc('2026-10-02','12:00:00','Asia/Tehran')==='2026-10-02 08:30:00','Iran wall time must follow IANA offset data');
$expect($utc('2026-10-02','23:30:00','America/New_York')==='2026-10-03 03:30:00','UTC date rollover must remain explicit in canonical identity');
foreach(array(
    array('2026-10-04','02:15:00','Australia/Sydney'),
    array('2026-11-01','01:30:00','America/New_York'),
    array('2026-09-27','02:15:00','Pacific/Auckland'),
    array('2026-03-29','01:30:00','Europe/London'),
) as [$date,$time,$zone]){
    try{$utc($date,$time,$zone);$expect(false,'DST gaps and folds must not create occupancy identities');}catch(\InvalidArgumentException $expected){}
}

$repo=file_get_contents($root.'/src/Core/Infrastructure/Repository/CanonicalLessonScheduleRepository.php');
$continuations=file_get_contents($root.'/src/Core/Infrastructure/Repository/CanonicalContinuationRepository.php');
$read=file_get_contents($root.'/src/Core/Application/CanonicalTeacherOccupancyReadService.php');
$assessment=file_get_contents($root.'/src/Core/Application/BookingAvailabilityAssessmentService.php');
$scheduleService=file_get_contents($root.'/src/Core/Application/CanonicalLessonScheduleService.php');
$scheduleRepo=file_get_contents($root.'/src/Core/Infrastructure/Repository/CanonicalLessonScheduleRepository.php');
$scheduleRaces=file_get_contents($root.'/tests/phase-2a2n-concurrency-runner.sh');
$scheduleFailures=file_get_contents($root.'/tests/phase-2a2n-failure-runtime.php');
foreach(array('applicable_slot=1','ORDER BY starts_at_utc,id') as $fact)$expect(str_contains($repo,$fact),'occupancy source must select current versions deterministically: '.$fact);
foreach(array('CanonicalLessonScheduleValidator::validForLesson','CanonicalLessonAuthorityValidator::valid','canonical_schedule_integrity_conflict','canonical_lesson_integrity_conflict','Cancellation/completion state alone does not release Teacher capacity') as $fact)$expect(str_contains($read,$fact),'canonical occupancy read must validate fail-closed authority: '.$fact);
$expect(str_contains($repo,"if(\$wpdb->last_error!=='')throw new \\RuntimeException('Canonical Teacher occupancy read failed"),'a failed canonical occupancy query must not masquerade as an empty schedule');
$expect(str_contains($continuations,"state='active' AND expires_at>%s")&&str_contains($continuations,"starts_at_utc<%s AND occupied_ends_at_utc>%s"),'only effective overlapping continuation holds must participate in teacher capacity');
$expect(str_contains($continuations,"if(\$wpdb->last_error!=='')throw new \\RuntimeException('Continuation capacity read failed"),'a failed continuation hold read must not masquerade as an empty schedule');
$expect(str_contains($assessment,'CanonicalContinuationCapacityAuthority::assertNoActiveHold')&&str_contains($assessment,"getMessage() === 'teacher_slot_conflict'"),'active continuation holds must block advisory availability while authority errors fail closed');
$expect(str_contains($assessment,'CanonicalTeacherOccupancyReadService')&&str_contains($assessment,"'status' => 'blocked'"),'booking assessment must consume occupancy and fail closed');
$expect(str_contains($assessment,"'exception_type' => 'schedule_conflict'")&&str_contains($assessment,"'fingerprint_key' => 'booking_availability_canonical_occupancy_v1'")&&str_contains($assessment,'canonical_continuation_integrity_conflict'),'serious teacher-capacity uncertainty must use deduplicated operational exceptions');
foreach(array('ensureAndLockTeacherRoot','assertCapacity','overlappingApplicable','begin()','commit()') as $fact)$expect(str_contains($scheduleService.$scheduleRepo,$fact),'commit-time scheduling must serialize and recheck teacher capacity: '.$fact);
foreach(array('capacity_first','same_key','different_keys','buffer_adjacency','unrelated_teachers') as $fact)$expect(str_contains($scheduleRaces,$fact),'teacher scheduling race suite is missing case: '.$fact);
foreach(array('dzn_phase_2a2n_after_version_supersede','dzn_phase_2a2n_after_version_insert','dzn_phase_2a2n_after_event_insert','dzn_phase_2a2n_after_command_insert','Rollback') as $fact)$expect(str_contains($scheduleFailures,$fact),'teacher scheduling failure-atomicity suite is missing case: '.$fact);
echo "Booking canonical occupancy contract passed\n";
