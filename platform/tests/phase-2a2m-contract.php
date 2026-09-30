<?php
$root=dirname(__DIR__);
$plugin=file_get_contents($root.'/delnavazan-platform.php');
$migration=file_get_contents($root.'/src/Core/Infrastructure/Migration/Migrator.php');
$service=file_get_contents($root.'/src/Core/Application/CanonicalLessonAuthorityService.php');
$repo=file_get_contents($root.'/src/Core/Infrastructure/Repository/CanonicalLessonAuthorityRepository.php');
$legacy=file_get_contents($root.'/src/Core/Infrastructure/Repository/LessonRepository.php');
if(!preg_match("/DZN_PLATFORM_SCHEMA_VERSION', '([0-9]+)'/",$plugin,$schema)||(int)$schema[1]<20)throw new RuntimeException('Missing compatible Phase M+ schema identity');
if(!preg_match("/DZN_PLATFORM_BUILD_ID', 'phase2a2[a-z0-9]+-[a-z0-9-]+-[0-9]{8}\.[0-9]+'/",$plugin))throw new RuntimeException('Missing compatible Phase M+ build identity');
foreach(array('020_canonical_lesson_authority','canonical_term_lesson_v1','dzn_manage_canonical_lessons','canonical_lesson_commands','canonical_lesson_lifecycle_events','019_canonical_enrolment_lifecycle_authority')as$n)if(!str_contains($plugin.$migration.$service.$repo,$n))throw new RuntimeException('Missing Phase M authority invariant: '.$n);
foreach(array('createStandard','createReplacement','expected_teacher_assignment_id','standard_allocation_exhausted','replacement_allocation_exhausted','replacement_origin_already_claimed','stale_teacher_assignment','IdempotencyConflictException','START TRANSACTION','FOR UPDATE','lockRoot','validTransitionContext','canonical_lesson_integrity_conflict')as$n)if(!str_contains($service.$repo.$migration,$n))throw new RuntimeException('Missing canonical Lesson control: '.$n);
foreach(array('LessonScheduleService','wp_amelia','GoogleCalendar','PaymentService','AttendanceService')as$n)if(stripos($service,$n)!==false)throw new RuntimeException('Phase M leaked excluded authority: '.$n);
foreach(array("record_model='legacy_phase1'",'Legacy Lesson mutation target required')as$n)if(!str_contains($legacy,$n))throw new RuntimeException('Legacy Lesson isolation missing: '.$n);
// Correction round 1: one canonical aggregate hydration/validation path for immutable provenance.
$validator=file_get_contents($root.'/src/Core/Application/CanonicalLessonAuthorityValidator.php');
foreach(array('use Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalLessonAuthorityRepository','function valid(object $lesson,array $events,?CanonicalLessonAuthorityRepository $repository=null,bool $lock=false)','function replacementEligible','assignmentById','function provenance','function structure','canonical_student_course_v1','canonical_enrolment_term_v1')as$n)if(!str_contains($validator,$n))throw new RuntimeException('Canonical Lesson aggregate validator incomplete: '.$n);
foreach(array('(int)($lesson->student_id??0)!==(int)($enrolment->student_id??0)','(int)($lesson->course_id??0)!==(int)($enrolment->course_id??0)','(int)($term->enrolment_id??0)!==(int)($enrolment->id??0)','(int)($assignment->enrolment_id??0)!==(int)($lesson->enrolment_id??0)','(int)($lesson->teacher_id??0)!==(int)($assignment->teacher_id??0)','(int)($origin->term_id??0)!==(int)($lesson->term_id??0)')as$n)if(!str_contains($validator,$n))throw new RuntimeException('Missing immutable Lesson provenance relationship: '.$n);
if(str_contains($validator,'applicable_slot'))throw new RuntimeException('Historical Lesson validity must not depend on the Assignment still being current');
if(!str_contains($repo,'SELECT * FROM {$this->p}teacher_assignments WHERE id=%d'))throw new RuntimeException('Canonical Lesson repository cannot load a historical Teacher Assignment');
// Correction round 1: replay revalidates command intent, durable evidence and the result aggregate.
foreach(array('function replayEvidence','Contaminated canonical Lesson command','Contaminated canonical Lesson result','CanonicalLessonAuthorityValidator::valid($lesson,$history,$this->repository)','expected_from_state')as$n)if(!str_contains($service,$n))throw new RuntimeException('Canonical Lesson replay does not revalidate: '.$n);
// Correction round 1: committed deterministic harness, corruption and failure-injection coverage.
$harness=array('phase-2a2m-concurrency-runner.sh','phase-2a2m-concurrency-wait.php','phase-2a2m-concurrency-setup.php','phase-2a2m-concurrency-worker.php','phase-2a2m-concurrency-verify.php','phase-2a2m-corruption-runtime.php','phase-2a2m-failure-runtime.php');
foreach($harness as$f)if(!is_file($root.'/tests/'.$f))throw new RuntimeException('Missing committed Phase M validation artefact: '.$f);
$runner=file_get_contents($root.'/tests/phase-2a2m-concurrency-runner.sh');
foreach(array('expect()','w2.result','w1.locked','w2.blocked','failures=$((failures+1))','DZN_PHASE_2A2M_MODE')as$n)if(!str_contains($runner,$n))throw new RuntimeException('Concurrency runner does not assert worker artefacts: '.$n);
foreach(array('same_key','standard_final','replacement_origin_same','replacement_final','lesson_pause','pause_lesson','lesson_close','close_lesson','lesson_term_close','term_close_lesson','lesson_term_cancel','term_cancel_lesson','lesson_replace','replace_lesson','unrelated')as$n)if(!str_contains($runner,$n))throw new RuntimeException('Concurrency runner omitted race mode: '.$n);
$failure=file_get_contents($root.'/tests/phase-2a2m-failure-runtime.php');
foreach(array('dzn_phase_2a2m_after_lesson_insert','dzn_phase_2a2m_after_lifecycle_event_insert','dzn_phase_2a2m_after_command_insert','dzn_phase_2a2m_after_lesson_transition','canonical_replacement_origin_lesson_id')as$n)if(!str_contains($failure,$n))throw new RuntimeException('Failure injection boundary coverage missing: '.$n);
$corruption=file_get_contents($root.'/tests/phase-2a2m-corruption-runtime.php');
foreach(array('command_payload_digest','replacement_origin_lesson_id','result_lesson_id','subordinate_lesson_integrity_conflict','authorised_canonical_lesson_exists')as$n)if(!str_contains($corruption,$n))throw new RuntimeException('Corruption regression coverage missing: '.$n);
echo "phase-2a2m-contract: pass\n";
