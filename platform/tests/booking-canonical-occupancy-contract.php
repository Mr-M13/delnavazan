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
$legacySchedule=file_get_contents($root.'/src/Core/Application/LessonScheduleService.php');
$continuations=file_get_contents($root.'/src/Core/Infrastructure/Repository/CanonicalContinuationRepository.php');
$read=file_get_contents($root.'/src/Core/Application/CanonicalTeacherOccupancyReadService.php');
$assessment=file_get_contents($root.'/src/Core/Application/BookingAvailabilityAssessmentService.php');
$availabilityRepository=file_get_contents($root.'/src/Core/Infrastructure/Repository/TeacherAvailabilityRepository.php');
$eligibilityRepository=file_get_contents($root.'/src/Core/Infrastructure/Repository/BookingRequestMatchAssessmentRepository.php');
$availabilityService=file_get_contents($root.'/src/Core/Application/TeacherAvailabilityService.php');
$scheduleService=file_get_contents($root.'/src/Core/Application/CanonicalLessonScheduleService.php');
$continuationService=file_get_contents($root.'/src/Core/Application/CanonicalContinuationService.php');
$commercialCapacity=file_get_contents($root.'/src/Core/Application/CommercialCapacityService.php');
$scheduleRepo=file_get_contents($root.'/src/Core/Infrastructure/Repository/CanonicalLessonScheduleRepository.php');
$scheduleRaces=file_get_contents($root.'/tests/phase-2a2n-concurrency-runner.sh');
$scheduleFailures=file_get_contents($root.'/tests/phase-2a2n-failure-runtime.php');
foreach(array('schedule_version.applicable_slot=1 OR schedule_version.superseded_at IS NULL','lesson.teacher_id=%d','ORDER BY schedule_version.starts_at_utc,schedule_version.id') as $fact)$expect(str_contains($repo,$fact),'occupancy source must select applicable facts and surface unsuperseded corruption deterministically: '.$fact);
foreach(array('CanonicalLessonScheduleValidator::validForLesson','CanonicalLessonAuthorityValidator::valid','canonical_schedule_integrity_conflict','canonical_lesson_integrity_conflict','Cancellation/completion state alone does not release Teacher capacity') as $fact)$expect(str_contains($read,$fact),'canonical occupancy read must validate fail-closed authority: '.$fact);
$expect(str_contains($repo,"if(\$wpdb->last_error!=='')throw new \\RuntimeException('Canonical Teacher occupancy read failed"),'a failed canonical occupancy query must not masquerade as an empty schedule');
$expect(str_contains($repo,'legacyApplicableForTeacher')&&str_contains($repo,"l.current_schedule_version_id")&&str_contains($repo,"l.status='scheduled'")&&str_contains($repo,'INTERVAL 15 MINUTE'),'current scheduled legacy Lessons must expose validated pointer-based occupancy with the established buffer');
$expect(str_contains($read,'legacyApplicableForTeacher')&&str_contains($read,'legacy_schedule_integrity_conflict'),'booking availability must include legacy current occupancy and fail closed on contradictory pointers');
$expect(str_contains($scheduleService,'new CanonicalTeacherOccupancyReadService($this->repository,$this->lessons)')&&str_contains($scheduleService,'$this->occupancy->overlapping'),'canonical commit-time capacity must use the shared canonical and legacy occupancy authority');
$expect(str_contains($continuationService,'new CanonicalTeacherOccupancyReadService($this->schedules)')&&str_contains($continuationService,'$this->occupancy->overlapping')&&!str_contains($continuationService,'overlappingApplicable('),'continuation hold commits must use shared canonical and legacy occupancy authority');
$expect(str_contains($commercialCapacity,'new CanonicalTeacherOccupancyReadService($this->schedules)')&&str_contains($commercialCapacity,'$this->occupancy->overlapping')&&!str_contains($commercialCapacity,'overlappingApplicable('),'protected commercial claim arbitration must use shared canonical and legacy occupancy authority');
$expect(str_contains($legacySchedule,'ensureAndLockTeacherRoot')&&str_contains($legacySchedule,'CanonicalTeacherOccupancyReadService')&&str_contains($legacySchedule,'assertNoActiveHold')&&str_contains($legacySchedule,'CommercialCapacityAuthority::assertNoConflictingClaim'),'legacy schedule commits must serialize and revalidate schedule, hold, and protected claim capacity');
$expect(str_contains($continuations,"state='active' AND expires_at>%s")&&str_contains($continuations,"starts_at_utc<%s AND occupied_ends_at_utc>%s"),'only effective overlapping continuation holds must participate in teacher capacity');
$expect(str_contains($continuations,"if(\$wpdb->last_error!=='')throw new \\RuntimeException('Continuation capacity read failed"),'a failed continuation hold read must not masquerade as an empty schedule');
$expect(str_contains($assessment,'CanonicalContinuationCapacityAuthority::assertNoActiveHold')&&str_contains($assessment,"getMessage() === 'teacher_slot_conflict'"),'active continuation holds must block advisory availability while authority errors fail closed');
$expect(str_contains($assessment,'CanonicalTeacherOccupancyReadService')&&str_contains($assessment,"'status' => 'blocked'"),'booking assessment must consume occupancy and fail closed');
$expect(str_contains($assessment,"'exception_type' => \$exceptionType")&&str_contains($assessment,"'fingerprint_key' => \$fingerprintKey")&&str_contains($assessment,'booking_availability_canonical_occupancy_v1')&&str_contains($assessment,'canonical_continuation_integrity_conflict')&&str_contains($assessment,'legacy_schedule_integrity_conflict'),'serious teacher-capacity uncertainty must use deduplicated operational exceptions');
foreach(array('profile','activeRules','activeExceptions','evaluableTeacher') as $method)$expect(str_contains($availabilityRepository,'function '.$method)&&str_contains($availabilityRepository,'teacher_availability_authority_unavailable'),'Teacher availability '.$method.' reads must surface database failures');
$expect(str_contains($availabilityService,'teacher_timezone_invalid')&&str_contains($availabilityService,'authorityTimezone')&&str_contains($availabilityService,'Normalizer::timezone')&&str_contains($availabilityService,'catch ( UnavailableLocalTimeException )'),'availability may skip a DST gap occurrence but must fail closed on malformed IANA timezone or rule authority');
$expect(str_contains($assessment,'$this->coverageState(')&&str_contains($assessment,'$this->availability->profileTimezone(')&&str_contains($assessment,'teacher_timezone_invalid')&&str_contains($assessment,"\$exceptionType = \$reason === 'teacher_timezone_invalid' ? 'timezone_missing'")&&str_contains($assessment,"'exception_type' => \$exceptionType"),'booking assessment must guard availability and timezone authority and report failures');
$expect(str_contains($eligibilityRepository,'booking_teacher_eligibility_authority_unavailable')&&str_contains($assessment,'booking_availability_teacher_eligibility_v1'),'teacher readiness and eligibility read failure must block and report booking availability');
foreach(array('ensureAndLockTeacherRoot','assertCapacity','overlappingApplicable','begin()','commit()') as $fact)$expect(str_contains($scheduleService.$scheduleRepo,$fact),'commit-time scheduling must serialize and recheck teacher capacity: '.$fact);
foreach(array('capacity_first','same_key','different_keys','buffer_adjacency','unrelated_teachers') as $fact)$expect(str_contains($scheduleRaces,$fact),'teacher scheduling race suite is missing case: '.$fact);
foreach(array('dzn_phase_2a2n_after_version_supersede','dzn_phase_2a2n_after_version_insert','dzn_phase_2a2n_after_event_insert','dzn_phase_2a2n_after_command_insert','Rollback') as $fact)$expect(str_contains($scheduleFailures,$fact),'teacher scheduling failure-atomicity suite is missing case: '.$fact);
echo "Booking canonical occupancy contract passed\n";
