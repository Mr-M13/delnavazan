<?php
/** Phase 2A.2-N canonical Lesson scheduling and Teacher capacity authority source contract. */
$root=dirname(__DIR__);
$plugin=file_get_contents($root.'/delnavazan-platform.php');
$migration=file_get_contents($root.'/src/Core/Infrastructure/Migration/Migrator.php');
$service=file_get_contents($root.'/src/Core/Application/CanonicalLessonScheduleService.php');
$validator=file_get_contents($root.'/src/Core/Application/CanonicalLessonScheduleValidator.php');
$guard=file_get_contents($root.'/src/Core/Application/CanonicalLessonScheduleGuard.php');
$repo=file_get_contents($root.'/src/Core/Infrastructure/Repository/CanonicalLessonScheduleRepository.php');
$read=file_get_contents($root.'/src/Core/Application/CanonicalLessonScheduleReadService.php');
$screen=file_get_contents($root.'/src/Admin/Controller/ScreenController.php');
if(!preg_match("/DZN_PLATFORM_SCHEMA_VERSION', '([0-9]+)'/",$plugin,$schema)||(int)$schema[1]<21)throw new RuntimeException('Missing compatible Phase N+ schema identity');
if(!preg_match("/DZN_PLATFORM_BUILD_ID', 'phase2a2[a-z0-9]+-[a-z0-9-]+-[0-9]{8}\.[0-9]+'/",$plugin))throw new RuntimeException('Missing compatible Phase N+ build identity');
foreach(array('021_canonical_lesson_schedule_authority','install_canonical_lesson_schedule_authority','verify_canonical_lesson_schedule_authority_schema','teacher_schedule_roots','canonical_lesson_schedule_versions','canonical_lesson_schedule_events','canonical_lesson_schedule_commands','lesson_applicable','teacher_occupancy','lesson_sequence','command_key_digest')as$n)if(!str_contains($migration,$n))throw new RuntimeException('Missing Phase N migration contract: '.$n);
if(!str_contains($migration,"if(\$id==='021_canonical_lesson_schedule_authority')self::verify_canonical_lesson_schedule_authority_schema();"))throw new RuntimeException('Migration 021 must invoke the Phase N schema verifier before it is recorded as complete');
if(!str_contains($migration,'self::verify_canonical_lesson_authority_schema(); self::verify_canonical_lesson_schedule_authority_schema();'))throw new RuntimeException('Current-schema verification must invoke the Phase N schema verifier');
if(!str_contains($migration,';} self::verify_canonical_lesson_schedule_authority_schema(); update_option( self::OPTION, DZN_PLATFORM_SCHEMA_VERSION, false ); }'))throw new RuntimeException('Phase N storage must be verified before the schema option is advanced to Schema 21');
$phaseNVerifier=substr($migration,strpos($migration,'private static function verify_canonical_lesson_schedule_authority_schema'));
if(str_contains($phaseNVerifier,'new\RuntimeException'))throw new RuntimeException('Phase N verifier must throw RuntimeException: new\\RuntimeException is a fatal Error, not a fail-closed rejection');
if(substr_count($phaseNVerifier,'throw new \RuntimeException')<5)throw new RuntimeException('Phase N verifier failure path must fail closed with RuntimeException');
if(!str_contains($migration,"CAPABILITY_VERSION = '2a2n'"))throw new RuntimeException('Capability version was not advanced');
foreach(array('dzn_manage_canonical_lesson_schedules','dzn_override_canonical_lesson_schedule_availability')as$n)if(substr_count($migration,$n)<3)throw new RuntimeException('Capability is not registered, granted and repaired: '.$n);
// The immutability rule is scoped to Phase N's own table definition rather than to a byte range that a
// later additive migration may extend: the slice runs to the next CREATE TABLE, whatever phase owns it.
$phaseNScheduleTable=substr($migration,strpos($migration,'CREATE TABLE {$p}canonical_lesson_schedule_versions'));
$phaseNNextTable=strpos($phaseNScheduleTable,'CREATE TABLE',8);
if($phaseNNextTable!==false)$phaseNScheduleTable=substr($phaseNScheduleTable,0,$phaseNNextTable);
if(str_contains($phaseNScheduleTable,'updated_at datetime'))throw new RuntimeException('Canonical schedule evidence must stay immutable');
if(str_contains($migration,'teacher_lesson_slot_reservations'))throw new RuntimeException('Phase N must not create a mutable reservation projection');
foreach(array("const CAPABILITY='dzn_manage_canonical_lesson_schedules'","const OVERRIDE_CAPABILITY='dzn_override_canonical_lesson_schedule_availability'","const DOMAIN='canonical_lesson_schedule_v1'",'schedule_initial','schedule_revise','schedule_release','teacher_slot_conflict','teacher_unavailable','schedule_start_not_future','schedule_already_exists','stale_schedule_version','schedule_unchanged','lesson_not_schedulable','enrolment_not_schedulable','stale_teacher_assignment','administrative_override','within_availability','course_default')as$n)if(!str_contains($service,$n))throw new RuntimeException('Missing Phase N service contract: '.$n);
foreach(array('resolvePolicy','availabilityBasis','assertCapacity','assertApplicableAssignment','replay','CanonicalLessonScheduleValidator::validForLesson')as$n)if(!str_contains($service,$n))throw new RuntimeException('Missing Phase N service path: '.$n);
if(strpos($service,'assertApplicableAssignment(')===false||strpos($service,'$this->repository->ensureAndLockTeacherRoot(')===false||strpos($service,'assertApplicableAssignment(')>strpos($service,'$this->repository->ensureAndLockTeacherRoot('))throw new RuntimeException('Teacher scheduling root must be acquired after the canonical Enrolment/Lesson/Assignment context');
if(!str_contains($guard,'activeFutureExists')||!str_contains($guard,'CanonicalLessonScheduleValidator::validForLesson'))throw new RuntimeException('Missing Phase N fail-closed guard scope');
foreach(array('function validForLesson','function valid(','function interval','function evidenceShape','function utc','current_schedule_version_id','schedule_timezone','occupied_ends_at_utc','event_type','superseded_by_version_id','availability_basis')as$n)if(!str_contains($validator,$n))throw new RuntimeException('Missing Phase N aggregate validator rule: '.$n);
foreach(array("'canonical_schedule_integrity_conflict'",'validForLesson',"match(\$scope)",'lessonIdsByEnrolment','lessonIdsByTerm','lessonIdsByTeacher')as$n)if(!str_contains($guard.$repo,$n))throw new RuntimeException('Missing Phase N guard/repository scope: '.$n);
foreach(array('ensureAndLockTeacherRoot','LAST_INSERT_ID','FOR UPDATE','overlappingApplicable','supersede','setSuccessor')as$n)if(!str_contains($repo,$n))throw new RuntimeException('Missing Phase N repository primitive: '.$n);
if(!str_contains($repo,"SET TRANSACTION ISOLATION LEVEL READ COMMITTED"))throw new RuntimeException('Canonical scheduling must keep the project isolation level');
if(!str_contains($read,'dzn_manage_canonical_lesson_schedules')||!str_contains($read,'canonical_schedule_integrity_conflict'))throw new RuntimeException('Protected canonical schedule read seam is incomplete');
foreach(array('LessonScheduleVersionRepository','dzn_lesson_schedule_versions')as$n)if(str_contains($service.$read.$validator.$repo,$n))throw new RuntimeException('Canonical scheduling must never read legacy scheduling storage: '.$n);
foreach(array('Amelia','GoogleCalendar','Google_Calendar','wp_amelia','Stripe','WhatsApp','PaymentService','AttendanceService')as$n)if(stripos($service.$validator.$repo.$read,$n)!==false)throw new RuntimeException('Phase N leaked excluded provider authority: '.$n);
foreach(array('new LessonScheduleService','LessonScheduleVersionRepository','lesson_schedule_versions (')as$n)if(str_contains($service,$n))throw new RuntimeException('Phase N must not reuse legacy scheduling authority: '.$n);
foreach(array('schedule_canonical_lesson','revise_canonical_lesson','release_canonical_lesson','CanonicalLessonScheduleReadService')as$n)if(!str_contains($screen,$n))throw new RuntimeException('Missing minimum administrator invocation: '.$n);
// Cross-phase guards.
$lessonRepo=file_get_contents($root.'/src/Core/Infrastructure/Repository/CanonicalLessonAuthorityRepository.php');
$m0=file_get_contents($root.'/src/Core/Application/CanonicalEnrolmentLifecycleService.php');
$l=file_get_contents($root.'/src/Core/Application/CanonicalTermAuthorityService.php');
$j=file_get_contents($root.'/src/Core/Application/TeacherAssignmentService.php');
$teacherRepo=file_get_contents($root.'/src/Core/Infrastructure/Repository/TeacherRepository.php');
foreach(array(array($lessonRepo,'Lesson completion/cancellation guard'),array($m0,'Enrolment closure guard'),array($l,'Term close/cancel guard'),array($j,'Assignment replacement guard'),array($teacherRepo,'Teacher archival guard'))as$spec)if(!str_contains($spec[0],'active_future_schedule_exists')||!str_contains($spec[0],'CanonicalLessonScheduleGuard'))throw new RuntimeException('Missing Phase N cross-phase guard: '.$spec[1]);
// Harness and test artefacts.
foreach(array('phase-2a2n-contract.php','phase-2a2n-migration-runtime.php','phase-2a2n-runtime.php','phase-2a2n-corruption-runtime.php','phase-2a2n-failure-runtime.php','phase-2a2n-concurrency-setup.php','phase-2a2n-concurrency-worker.php','phase-2a2n-concurrency-wait.php','phase-2a2n-concurrency-verify.php','phase-2a2n-concurrency-runner.sh')as$f)if(!is_file($root.'/tests/'.$f))throw new RuntimeException('Missing Phase N validation artefact: '.$f);
$runner=file_get_contents($root.'/tests/phase-2a2n-concurrency-runner.sh');
foreach(array('expect()','w2.result','w1.locked','w2.blocked','failures=$((failures+1))','DZN_PHASE_2A2N_MODE','wait.php','verify.php')as$n)if(!str_contains($runner,$n))throw new RuntimeException('Concurrency runner does not assert worker artefacts: '.$n);
foreach(array('capacity_first','capacity_prepared','same_key','different_keys','revise_stale','revise_release','buffer_adjacency','unrelated_teachers','availability_race','override_race','schedule_pause','pause_schedule','schedule_close','close_schedule','schedule_term_close','term_close_schedule','schedule_term_cancel','term_cancel_schedule','schedule_complete','complete_schedule','schedule_cancel','cancel_schedule','schedule_replace','replace_schedule','schedule_archive','archive_schedule')as$n)if(!str_contains($runner,$n))throw new RuntimeException('Concurrency runner omitted race mode: '.$n);
$failure=file_get_contents($root.'/tests/phase-2a2n-failure-runtime.php');
foreach(array('dzn_phase_2a2n_after_version_supersede','dzn_phase_2a2n_after_version_insert','dzn_phase_2a2n_after_event_insert','dzn_phase_2a2n_after_command_insert')as$n)if(!str_contains($failure,$n))throw new RuntimeException('Failure injection boundary missing: '.$n);
$corruption=file_get_contents($root.'/tests/phase-2a2n-corruption-runtime.php');
foreach(array('occupied_ends_at_utc','buffer_minutes','superseded_by_version_id','availability_basis','current_schedule_version_id','command_payload_digest','result_schedule_version_id')as$n)if(!str_contains($corruption,$n))throw new RuntimeException('Corruption coverage missing: '.$n);
echo "phase-2a2n-contract: pass\n";
