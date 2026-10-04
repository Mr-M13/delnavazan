<?php
/** Phase 2A.2-Q post-intro continuation & slot reservation authority source contract. */
$root=dirname(__DIR__);
$plugin=file_get_contents($root.'/delnavazan-platform.php');
$migration=file_get_contents($root.'/src/Core/Infrastructure/Migration/Migrator.php');
$rule=file_get_contents($root.'/src/Core/Application/CanonicalContinuationRule.php');
$service=file_get_contents($root.'/src/Core/Application/CanonicalContinuationService.php');
$validator=file_get_contents($root.'/src/Core/Application/CanonicalContinuationValidator.php');
$read=file_get_contents($root.'/src/Core/Application/CanonicalContinuationReadService.php');
$repo=file_get_contents($root.'/src/Core/Infrastructure/Repository/CanonicalContinuationRepository.php');
$capacity=file_get_contents($root.'/src/Core/Application/CanonicalContinuationCapacityAuthority.php');
$schedule=file_get_contents($root.'/src/Core/Application/CanonicalLessonScheduleService.php');
$phaseQ=$rule.$service.$validator.$read.$repo.$capacity;

if(!preg_match("/DZN_PLATFORM_SCHEMA_VERSION', '([0-9]+)'/",$plugin,$schema)||(int)$schema[1]<24)throw new RuntimeException('Missing Phase Q schema identity');
/* Compatibility markers are minimum-schema plus the canonical build identity shape, so a later
   phase may advance the platform build without invalidating this closed phase's contract. */
if(!preg_match("/DZN_PLATFORM_BUILD_ID', 'phase2a2[a-z0-9]*-[a-z0-9-]+-[0-9]{8}\.[0-9]+'/",$plugin))throw new RuntimeException('Missing canonical build identity for Phase Q+');

// Migration, storage, verifier wiring, capabilities.
foreach(array('024_post_intro_continuation_slot_reservation_authority','install_canonical_continuation_authority','verify_canonical_continuation_schema','canonical_continuation_cases','canonical_continuation_decisions','canonical_continuation_reservations','canonical_continuation_interventions','canonical_continuation_commands','dzn_manage_canonical_continuation','dzn_view_canonical_continuation','dzn_submit_own_continuation_match_exception')as$n)if(!str_contains($migration.$plugin,$n))throw new RuntimeException('Missing Phase Q migration contract: '.$n);
if(!str_contains($migration,"if(\$id==='024_post_intro_continuation_slot_reservation_authority')self::verify_canonical_continuation_schema();"))throw new RuntimeException('Migration 024 must invoke the Phase Q schema verifier before it is recorded');
if(substr_count($migration,'self::verify_canonical_continuation_schema();')<3)throw new RuntimeException('Phase Q verifier must run after migration 024, on current-schema verification and before schema activation');
if(!str_contains($migration,"in_array( '024_post_intro_continuation_slot_reservation_authority', (array) get_option( self::COMPLETED, array() ), true )"))throw new RuntimeException('Retained-024 pre-activation verification is missing');
/* The required-migration list is append-only, so a later phase may follow this entry. */
if(!str_contains($migration,"'024_post_intro_continuation_slot_reservation_authority',")&&!str_contains($migration,"'024_post_intro_continuation_slot_reservation_authority' )"))throw new RuntimeException('Phase Q migration must be listed as required');
$install=substr($migration,strpos($migration,'private static function install_canonical_continuation_authority'),strpos($migration,'private static function verify_canonical_continuation_schema')-strpos($migration,'private static function install_canonical_continuation_authority'));
if(substr_count($install,'updated_at datetime')!==2)throw new RuntimeException('Only the mutable case and reservation aggregates may carry updated_at; Phase Q evidence must stay append-only');
if(str_contains($install,'UPDATE ')||str_contains($install,'INSERT INTO'))throw new RuntimeException('Phase Q migration must be additive only: no backfill or payment import');
if(stripos($install,'google')!==false||stripos($install,'stripe')!==false||stripos($install,'payment')!==false)throw new RuntimeException('Phase Q storage must not contain provider- or payment-specific columns');
// Per-capability repair: Phase Q grants are never gated on one capability and the Teacher role is
// explicitly denied administrative continuation authority.
if(!str_contains($migration,'$phaseQGrants')||!str_contains($migration,'dzn_submit_own_continuation_match_exception'))throw new RuntimeException('Phase Q capability repair must independently ensure every grant');
if(!str_contains($migration,'CAPABILITY_OPTION_Q')||!str_contains($migration,'dzn_platform_capability_version_2a2q'))throw new RuntimeException('Phase Q capability marker is missing');

// Locked owner decisions Q-D1…Q-D12.
foreach(array("RULE_VERSION='canonical_continuation_v1'","HOLD_DAYS=6","DECISION_CONTINUE='continue_with_teacher'","DECISION_DIFFERENT_TEACHER='different_teacher'","DECISION_CONTACT_ME='contact_me'","DECISION_NOT_CONTINUING='not_continuing'","DECISION_TEACHER_UNSUITABLE='teacher_unsuitable'","HOLDING_DECISIONS=array(self::DECISION_CONTINUE)","RESERVATION_STATES=array('active','expired','released')")as$n)if(!str_contains($rule,$n))throw new RuntimeException('Missing locked Phase Q rule constant: '.$n);
foreach(array('teacher_fit','schedule','price','changed_mind','technical_experience','other','prefer_not_to_say')as$n)if(!str_contains($rule,$n))throw new RuntimeException('Missing controlled feedback reason: '.$n);
foreach(array('student_requested_different_teacher','student_requested_contact','teacher_match_unsuitable','integrity_conflict')as$n)if(!str_contains($rule,$n))throw new RuntimeException('Missing controlled intervention reason: '.$n);
// Q-1: no weekly-recurrence derivation may exist. The introduction time is only an intent; an
// explicit authoritative slot record is required before any capacity may be held.
if(str_contains($rule,'+7 days')||str_contains($rule,'expectedFirstRegularSlot'))throw new RuntimeException('Phase Q must never derive a future paid slot from the introduction time');
if(!str_contains($rule,'public static function resolveWallClock')||str_contains($rule,'$next='))throw new RuntimeException('The slot rule must only resolve an explicitly authorised wall clock');
foreach(array("SLOT_AUTHORITY_BASES=array('administrator_attestation')",'first_regular_slot_authority_required')as$n)if(!str_contains($rule,$n))throw new RuntimeException('Missing Phase Q slot-authority contract: '.$n);
if(!str_contains($service,'recordFirstRegularSlot')||!str_contains($service,'first_regular_slot_not_after_introduction')||!str_contains($service,'first_regular_slot_already_authorised'))throw new RuntimeException('Phase Q must expose one explicit first-regular-slot authority command');
if(!str_contains($repo,'canonical_continuation_slot_authorities')||!str_contains($validator,'slotAuthority')||!str_contains($validator,'resolveWallClock'))throw new RuntimeException('The slot authority must be persisted and validated');
// Q-3: accepted-arrangement lineage is optional and never chosen by insertion id.
if(!str_contains($repo,'acceptedArrangements')||str_contains($repo,'acceptedArrangement('))throw new RuntimeException('Accepted-arrangement lineage must be resolved without an id-ordered single row');
if(!str_contains($service,'continuation_arrangement_ambiguous'))throw new RuntimeException('Ambiguous accepted-arrangement lineage must fail closed');
if(!str_contains($rule,'HOLD_DAYS*86400'))throw new RuntimeException('The six-day hold bound must be an exact duration from the introductory occurrence boundary');
if(!str_contains($rule,'return $slotStartUtc<$boundary?$slotStartUtc:$boundary;'))throw new RuntimeException('Hold expiry must be the earlier of the slot start and the six-day bound');
if(!str_contains($rule,'$state===\'active\'&&$expiresAt>$now'))throw new RuntimeException('Capacity effectivity must be active AND unexpired');

// Decision authority: explicit commands, self-enforced authority, no caller-trusted identity.
foreach(array('continueWithTeacher','requestDifferentTeacher','requestContact','stopContinuation','markMatchNeedsAdmin','flagIntegrityConflict')as$n)if(!str_contains($service,$n))throw new RuntimeException('Missing Phase Q command seam: '.$n);
foreach(array("const CAPABILITY_ADMIN='dzn_manage_canonical_continuation'","const CAPABILITY_TEACHER='dzn_submit_own_continuation_match_exception'",'student_capacity_classification_required','activePrincipalLink','activeGuardianGrant','activeTeacherPrincipal','teacher_match_suppressed','continuation_closed','canonical_continuation_integrity_conflict')as$n)if(!str_contains($service,$n))throw new RuntimeException('Missing Phase Q authority contract: '.$n);
if(!str_contains($service,'introductory_occurrence_not_ended'))throw new RuntimeException('Phase Q must not record a decision before the introductory occurrence window has ended');
if(!str_contains($service,'hash_equals'))throw new RuntimeException('Phase Q commands must compare the durable expected digest');
if(!str_contains($service,'private function replay(object $command,string $payload,string $operation)'))throw new RuntimeException('Phase Q replay must require a non-null expected digest');
// Absolute boundary: Phase Q never creates payment, Term, Lesson, obligation or attendance truth.
foreach(array('INSERT INTO','UPDATE ')as$sql)if(str_contains($phaseQ,$sql))throw new RuntimeException('Phase Q must not write canonical storage with raw SQL: '.$sql);
foreach(array('canonical_term_lesson_v1','createStandard','createReplacement','canonical_lesson_delivery_outcomes','canonical_academy_obligations','stripe','Stripe','payment_intent','wp_mail','wp_remote_')as$n)if(str_contains($phaseQ,$n))throw new RuntimeException('Phase Q must never acquire this authority: '.$n);
if(str_contains($service,'setCurrentSchedule')||str_contains($service,'lesson_schedule_versions'))throw new RuntimeException('A temporary hold must never become a Lesson schedule');

// Slot derivation and reservation coherence.
if(!str_contains($validator,'slotAuthority')||!str_contains($validator,'expiresAt'))throw new RuntimeException('The validator must validate the authoritative slot record and frozen expiry');
if(!str_contains($validator,'HOLDING_DECISIONS'))throw new RuntimeException('Only a holding decision may own a reservation');
if(!str_contains($validator,"'legacy_phase1'")||!str_contains($validator,"'introductory'"))throw new RuntimeException('The validator must bind a legacy introductory Lesson, never a paid Term Lesson');

// Real capacity integration at the narrowest canonical seam.
if(!str_contains($schedule,'CanonicalContinuationCapacityAuthority::assertNoActiveHold'))throw new RuntimeException('Phase-N capacity arbitration must consider active Phase-Q holds');
if(!str_contains($capacity,'overlappingEffectiveReservations')||!str_contains($capacity,'teacher_slot_conflict'))throw new RuntimeException('The Phase Q capacity seam must fail closed on conflict');
if(!str_contains($service,'ensureAndLockTeacherRoot'))throw new RuntimeException('Phase Q holds must use the same per-Teacher scheduling root as Phase N');

// Protected read: read-only, validated, privacy-minimised.
foreach(array("const CAPABILITY='dzn_view_canonical_continuation'",'validForCase','capacity_effective','reason_codes','expires_at')as$n)if(!str_contains($read,$n))throw new RuntimeException('Missing Phase Q protected read contract: '.$n);
if(str_contains($read,'INSERT INTO')||str_contains($read,'UPDATE ')||str_contains($read,'->begin()'))throw new RuntimeException('The Phase Q protected read must remain read-only with no write transaction');
foreach(array('guardian_grant_id','principal_link_id','evidence_reference_digest')as$n)if(str_contains($read,$n))throw new RuntimeException('The Phase Q protected read must not expose guardian evidence internals: '.$n);

foreach(array('phase-2a2q-runtime.php','phase-2a2q-corruption-runtime.php','phase-2a2q-failure-runtime.php','phase-2a2q-migration-runtime.php','phase-2a2q-concurrency-runner.sh','phase-2a2q-concurrency-setup.php','phase-2a2q-concurrency-worker.php','phase-2a2q-concurrency-wait.php','phase-2a2q-concurrency-verify.php')as$f)if(!is_file($root.'/tests/'.$f))throw new RuntimeException('Missing committed Phase Q validation artefact: '.$f);
echo "phase-2a2q-contract: pass\n";
