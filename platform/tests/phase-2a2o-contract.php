<?php
/** Phase 2A.2-O canonical Lesson delivery/attendance outcome authority source contract. */
$root=dirname(__DIR__);
$plugin=file_get_contents($root.'/delnavazan-platform.php');
$migration=file_get_contents($root.'/src/Core/Infrastructure/Migration/Migrator.php');
$service=file_get_contents($root.'/src/Core/Application/CanonicalLessonDeliveryService.php');
$validator=file_get_contents($root.'/src/Core/Application/CanonicalLessonDeliveryValidator.php');
$guard=file_get_contents($root.'/src/Core/Application/CanonicalLessonDeliveryGuard.php');
$read=file_get_contents($root.'/src/Core/Application/CanonicalLessonDeliveryReadService.php');
$repo=file_get_contents($root.'/src/Core/Infrastructure/Repository/CanonicalLessonDeliveryRepository.php');
$authority=file_get_contents($root.'/src/Core/Application/CanonicalLessonAuthorityService.php');
$authorityRepo=file_get_contents($root.'/src/Core/Infrastructure/Repository/CanonicalLessonAuthorityRepository.php');
$authorityValidator=file_get_contents($root.'/src/Core/Application/CanonicalLessonAuthorityValidator.php');
$schedule=file_get_contents($root.'/src/Core/Application/CanonicalLessonScheduleService.php');

if(!preg_match("/DZN_PLATFORM_SCHEMA_VERSION', '([0-9]+)'/",$plugin,$schema)||(int)$schema[1]<22)throw new RuntimeException('Missing Phase O schema identity');
// Phase O+ tolerance: later Platform slices advance the build identity (for example Phase 2A.2-P),
// so this asserts a canonical Platform build identity rather than pinning the Phase-O string.
if(!preg_match("/DZN_PLATFORM_BUILD_ID', 'phase2a2[a-z0-9]+-[a-z0-9-]+-[0-9]{8}\.[0-9]+'/",$plugin))throw new RuntimeException('Missing compatible Phase O+ build identity');

// Migration, storage, verifier wiring and capability.
foreach(array('022_canonical_lesson_delivery_attendance_authority','install_canonical_lesson_delivery_attendance_authority','verify_canonical_lesson_delivery_authority_schema','canonical_lesson_delivery_outcomes','canonical_lesson_delivery_commands','canonical_academy_obligations','lesson_applicable_outcome','lesson_outcome_sequence','schedule_version_id','occurrence_ends_at_utc','reconciles_completion_event_id','dzn_manage_canonical_lesson_delivery')as$n)if(!str_contains($migration.$plugin,$n))throw new RuntimeException('Missing Phase O migration contract: '.$n);
if(!str_contains($migration,"if(\$id==='022_canonical_lesson_delivery_attendance_authority')self::verify_canonical_lesson_delivery_authority_schema();"))throw new RuntimeException('Migration 022 must invoke the Phase O schema verifier before it is recorded as complete');
if(!str_contains($migration,'self::verify_canonical_lesson_delivery_authority_schema();'))throw new RuntimeException('Phase O storage verifier is never invoked');
if(!str_contains($migration,"in_array( '022_canonical_lesson_delivery_attendance_authority', (array) get_option( self::COMPLETED, array() ), true )"))throw new RuntimeException('Retained-022 pre-activation verification is missing');
if(substr_count($migration,'self::verify_canonical_lesson_delivery_authority_schema();')<3)throw new RuntimeException('Phase O verifier must run after migration 022, on current-schema verification and before schema activation');
if(!str_contains($migration,"CAPABILITY_VERSION = '2a2n'"))throw new RuntimeException('Phase N capability marker must remain intact for the F-N regression suites');
if(str_contains(substr($migration,strpos($migration,'CREATE TABLE {$p}canonical_lesson_delivery_outcomes'),strpos($migration,'private static function verify_canonical_lesson_delivery_authority_schema')-strpos($migration,'CREATE TABLE {$p}canonical_lesson_delivery_outcomes')),'updated_at datetime'))throw new RuntimeException('Delivery outcome evidence must stay immutable');
if(str_contains($migration,'canonical_remedy_class'))throw new RuntimeException('Academy debt must not be modelled as a Phase-M replacement classification');

// Authority contract: explicit commands, digest-only evidence, no cascade.
foreach(array("const CAPABILITY='dzn_manage_canonical_lesson_delivery'","const DOMAIN='canonical_lesson_delivery_v1'",'record_outcome','correct_outcome','reconcile_completion_non_delivery','occurrence_not_started','occurrence_not_ended','schedule_required_for_delivery_outcome','delivery_outcome_exists','stale_delivery_outcome','lesson_completed_reconciliation_required','already_reconciled_non_delivery','completion_reconciliation_cannot_be_undone','lesson_not_delivery_recordable','stale_lesson_state','replay','supersede','setSuccessor')as$n)if(!str_contains($service.$repo,$n))throw new RuntimeException('Missing Phase O authority control: '.$n);
foreach(array('function profile','delivered','student_no_show','teacher_non_delivery','interruption','review_required','academy_obligation','blocksCompletion','academyObligation','evidenceShape','staff_record','authenticated_platform','document_reference')as$n)if(!str_contains($validator,$n))throw new RuntimeException('Missing Phase O outcome vocabulary: '.$n);
foreach(array('lesson_not_delivered','lesson_delivery_review_required','assertCompletable','hasAcademyObligation','canonical_delivery_integrity_conflict')as$n)if(!str_contains($guard,$n))throw new RuntimeException('Missing Phase O fail-closed guard: '.$n);
foreach(array("const CAPABILITY='dzn_manage_canonical_lesson_delivery'",'canonical_delivery_integrity_conflict','effectiveOutcome')as$n)if(!str_contains($read,$n))throw new RuntimeException('Missing Phase O protected read: '.$n);
foreach(array('START TRANSACTION','FOR UPDATE','lockRoot','lesson_outcome_sequence')as$n)if(!str_contains($service.$repo,$n))throw new RuntimeException('Missing Phase O persistence discipline: '.$n);

// Cross-phase reconciliation.
if(!str_contains($authorityRepo,'CanonicalLessonDeliveryGuard'))throw new RuntimeException('Lesson completion/cancellation is not reconciled with the delivery authority');
if(!str_contains($authorityRepo,'occurrence_already_started_use_delivery_outcome'))throw new RuntimeException('Advance cancellation and post-occurrence non-delivery are not distinguishable');
if(!str_contains($schedule,'delivery_outcome_exists'))throw new RuntimeException('Canonical scheduling is not reconciled with the delivery authority');
if(str_contains($authorityValidator,'academyObligation')||str_contains($authorityValidator,'CanonicalLessonDelivery'))throw new RuntimeException('Phase-M replacement eligibility must not be coupled to Phase-O academy obligation authority');
if(str_contains($authority,'reconcile_completion_non_delivery'))throw new RuntimeException('Phase-M cancellation authority must not implement the Phase-O reconciliation command');
if(!str_contains($guard,'hasOutcome'))throw new RuntimeException('Delivery guard cannot answer whether an outcome exists');

// O-D8/O-D9 authority: distinct append-only reconciliation and academy-owed occurrence authority.
$obligationService=file_get_contents($root.'/src/Core/Application/CanonicalAcademyObligationService.php');
$obligationValidator=file_get_contents($root.'/src/Core/Application/CanonicalAcademyObligationValidator.php');
$obligationRepo=file_get_contents($root.'/src/Core/Infrastructure/Repository/CanonicalAcademyObligationRepository.php');
foreach(array('owe','teacher_non_delivery','academy_cancellation','obligation_source_conflict','academy_obligation_source_missing','forLesson','outstandingForTerm','outstandingForEnrolment')as$n)if(!str_contains($obligationService.$obligationValidator,$n))throw new RuntimeException('Missing Phase O academy obligation contract: '.$n);
if(!str_contains($obligationValidator,'canonical_lesson_cancelled_academy_unavailable')||!str_contains($authority,'canonical_lesson_cancelled_academy_unavailable'))throw new RuntimeException('Missing controlled academy cancellation reason on the cancellation and obligation paths');
if(!str_contains($migration,'UNIQUE KEY source_lesson(source_lesson_id)'))throw new RuntimeException('Academy obligation must be bounded to one per source occurrence');
if(!str_contains($obligationRepo,'source_lesson'))throw new RuntimeException('Academy obligation repository does not address its source occurrence');
if(!str_contains($authorityRepo,'CanonicalAcademyObligationService'))throw new RuntimeException('Advance cancellation does not establish the academy obligation');
if(!str_contains($service,'schedule_version_id')||!str_contains($service,'reconciles_completion_event_id'))throw new RuntimeException('Outcome provenance does not bind the exact schedule version and reconciliation lineage');

// Correction round 1 — O-1: the public obligation write seam enforces the Phase-O capability itself,
// before any validation or canonical mutation.
$oweStart=strpos($obligationService,'public function owe(');
$capabilityAt=strpos($obligationService,'$this->capability();');
$firstInsert=strpos($obligationService,'->insert(');
$firstLookup=strpos($obligationService,'->lesson(');
if($oweStart===false||$capabilityAt===false||$firstInsert===false)throw new RuntimeException('Academy obligation write seam is not capability-gated');
if($capabilityAt<$oweStart||$capabilityAt>$firstInsert)throw new RuntimeException('Academy obligation capability check must precede persistence');
if($firstLookup!==false&&$capabilityAt>$firstLookup)throw new RuntimeException('Academy obligation capability check must precede source validation');

// Correction round 1 — O-2: aggregate obligation reads validate every selected row and the count
// derives from the same validated authority.
foreach(array('private function validated','validated($this->repository->forTerm($termId))','validated($this->repository->forEnrolment($enrolmentId))')as$n)if(!str_contains($obligationService,$n))throw new RuntimeException('Aggregate obligation read is not integrity-validated: '.$n);
if(str_contains($obligationRepo,'outstandingCount'))throw new RuntimeException('A raw obligation count seam must not exist');
if(!str_contains($obligationRepo,'o.source_lesson_id IN (SELECT l.id FROM'))throw new RuntimeException('Aggregate obligation selectors must also select by source Lesson so corrupted selectors cannot be omitted');
foreach(array('!==(int)($lesson->student_id??0)','!==(int)($lesson->course_id??0)','!==(int)($lesson->teacher_id??0)','!==(int)($lesson->term_id??0)','!==(int)($lesson->enrolment_id??0)')as$n)if(!str_contains($obligationValidator,$n))throw new RuntimeException('Obligation validator does not require selector agreement: '.$n);

// Correction round 2 — HIGH: the teacher_non_delivery branch must validate O-D8 completion-event
// lineage rather than trusting the stored identifier, and same-kind replay must not bypass the
// source/evidence contract.
foreach(array('$reconciledEvent=$effective->reconciles_completion_event_id','$storedEvent=$obligation->source_event_id','if($reconciledEvent===null)','$storedEvent!==$reconciledEvent')as$n)if(!str_contains($obligationValidator,$n))throw new RuntimeException('O-D8 completion-event lineage is not validated on obligations: '.$n);
// Correction round 3 — HIGH: canonical completion is decided by Lesson authority, never re-derived
// locally. Both Phase-O consumers must consume the canonical Lesson/lifecycle validation path, and
// the local max-id completion approximation must be gone.
if(!str_contains($obligationValidator,'CanonicalLessonAuthorityValidator::valid($lesson,$lifecycle'))throw new RuntimeException('Academy obligation validation does not consume canonical Lesson authority');
$deliveryValidatorSource=file_get_contents($root.'/src/Core/Application/CanonicalLessonDeliveryValidator.php');
if(!str_contains($deliveryValidatorSource,'CanonicalLessonAuthorityValidator::valid($lesson,$lifecycle'))throw new RuntimeException('Delivery reconciliation lineage does not consume canonical Lesson authority');
foreach(array($obligationValidator,$deliveryValidatorSource)as$consumer)foreach(array('latestCompletion','max($completionEventId')as$approximation)if(str_contains($consumer,$approximation))throw new RuntimeException('Local completion approximation still present: '.$approximation);
if(!str_contains($obligationService,'assertReplayMatches')||!str_contains($obligationService,"'obligation_replay_conflict'"))throw new RuntimeException('Same-kind obligation replay does not enforce the source/evidence contract');
$corruptionRound2=file_get_contents($root.'/tests/phase-2a2o-corruption-runtime.php');
foreach(array('Teacher Assignment identity','O-D8 wrong completion event identifier','O-D8 completion event from another Lesson','O-D8 missing completion event lineage','O-D8 completion event of the wrong lifecycle type','ordinary non-delivery with spurious completion lineage')as$n)if(!str_contains($corruptionRound2,$n))throw new RuntimeException('Correction round 2 corruption coverage is incomplete: '.$n);

// Provider neutrality and excluded authority: no provider or finance coupling may enter Phase O.
$phaseO=$service.$validator.$guard.$read.$repo;
foreach(array('google','amelia','wp_remote','curl_','webhook','access_token','payment','refund','invoice','payroll','whatsapp')as$n)if(stripos($phaseO,$n)!==false)throw new RuntimeException('Phase O leaked excluded authority: '.$n);
foreach(array('MeetingService','CalendarService','NotificationService')as$n)if(str_contains($phaseO,$n))throw new RuntimeException('Phase O leaked excluded service: '.$n);

// Committed validation artefacts.
foreach(array('phase-2a2o-runtime.php','phase-2a2o-corruption-runtime.php','phase-2a2o-failure-runtime.php','phase-2a2o-migration-runtime.php','phase-2a2o-concurrency-runner.sh','phase-2a2o-concurrency-setup.php','phase-2a2o-concurrency-worker.php','phase-2a2o-concurrency-wait.php','phase-2a2o-concurrency-verify.php')as$f)if(!is_file($root.'/tests/'.$f))throw new RuntimeException('Missing committed Phase O validation artefact: '.$f);
$runner=file_get_contents($root.'/tests/phase-2a2o-concurrency-runner.sh');
foreach(array('expect()','w2.result','w1.locked','failures=$((failures+1))','DZN_PHASE_2A2O_MODE','outcome_first','complete_first','outcome_cancel','outcome_release','outcome_revise','outcome_close','outcome_term_close','correction_correction','duplicate_assertion','unrelated_lessons')as$n)if(!str_contains($runner,$n))throw new RuntimeException('Concurrency runner omitted race requirement: '.$n);
$failure=file_get_contents($root.'/tests/phase-2a2o-failure-runtime.php');
foreach(array('dzn_phase_2a2o_after_outcome_insert','dzn_phase_2a2o_after_outcome_supersede','dzn_phase_2a2o_after_command_insert','dzn_phase_2a2o_delivery_locks_held')as$n)if(!str_contains($failure,$n))throw new RuntimeException('Failure injection boundary coverage missing: '.$n);
$corruption=file_get_contents($root.'/tests/phase-2a2o-corruption-runtime.php');
foreach(array('outcome_code','delivery_state','attendance_state','remedy_class','evidence_reference_digest','occurrence_starts_at_utc','occurrence_ends_at_utc','schedule_version_id','superseded_by_outcome_id','command_payload_digest')as$n)if(!str_contains($corruption,$n))throw new RuntimeException('Corruption regression coverage missing: '.$n);
echo "phase-2a2o-contract: pass\n";
