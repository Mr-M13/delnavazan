<?php
/** Phase 2A.2-P canonical attendance intake & review authority source contract. */
$root=dirname(__DIR__);
$plugin=file_get_contents($root.'/delnavazan-platform.php');
$migration=file_get_contents($root.'/src/Core/Infrastructure/Migration/Migrator.php');
$rule=file_get_contents($root.'/src/Core/Application/CanonicalAttendanceRule.php');
$intake=file_get_contents($root.'/src/Core/Application/CanonicalAttendanceIntakeService.php');
$settlement=file_get_contents($root.'/src/Core/Application/CanonicalAttendanceSettlementService.php');
$validator=file_get_contents($root.'/src/Core/Application/CanonicalAttendanceValidator.php');
$read=file_get_contents($root.'/src/Core/Application/CanonicalAttendanceReadService.php');
$identity=file_get_contents($root.'/src/Core/Application/CanonicalAttendanceIdentityService.php');
$repo=file_get_contents($root.'/src/Core/Infrastructure/Repository/CanonicalAttendanceRepository.php');
$phaseP=$rule.$intake.$settlement.$validator.$read.$repo.$identity;

if(!preg_match("/DZN_PLATFORM_SCHEMA_VERSION', '([0-9]+)'/",$plugin,$schema)||(int)$schema[1]<23)throw new RuntimeException('Missing Phase P schema identity');
// Forward-compatible canonical build identity: a later additive phase advances the build id, so the
// Phase-P contract pins the repository's canonical build-identity pattern rather than a frozen value.
if(!preg_match("/DZN_PLATFORM_BUILD_ID', '[a-z0-9-]+-[0-9]{8}\.[0-9]+'/",$plugin))throw new RuntimeException('Missing canonical build identity');

// Migration, storage, verifier wiring, capabilities.
foreach(array('023_canonical_attendance_intake_authority','install_canonical_attendance_intake_authority','verify_canonical_attendance_intake_schema','canonical_attendance_cases','canonical_attendance_evidence','canonical_attendance_decisions','canonical_attendance_case_anomalies','canonical_attendance_commands','canonical_attendance_cutover_policies','canonical_attendance_participant_mappings','canonical_attendance_conflicts','cutover_policy_id','dzn_ingest_canonical_attendance_evidence','dzn_submit_own_attendance_claim','dzn_submit_own_delivery_claim','dzn_manage_canonical_attendance_review','dzn_view_canonical_attendance_review','dzn_manage_canonical_attendance_identity')as$n)if(!str_contains($migration.$plugin,$n))throw new RuntimeException('Missing Phase P migration contract: '.$n);
if(!str_contains($migration,"if(\$id==='023_canonical_attendance_intake_authority')self::verify_canonical_attendance_intake_schema();"))throw new RuntimeException('Migration 023 must invoke the Phase P schema verifier before it is recorded');
if(substr_count($migration,'self::verify_canonical_attendance_intake_schema();')<3)throw new RuntimeException('Phase P verifier must run after migration 023, on current-schema verification and before schema activation');
if(!str_contains($migration,"in_array( '023_canonical_attendance_intake_authority', (array) get_option( self::COMPLETED, array() ), true )"))throw new RuntimeException('Retained-023 pre-activation verification is missing');
// Forward-compatible: a later additive migration is appended to the required list, so assert that
// migration 023 is listed as a required entry rather than that it is the final entry.
if(!str_contains($migration,"'023_canonical_attendance_intake_authority',")&&!str_contains($migration,"'023_canonical_attendance_intake_authority' )"))throw new RuntimeException('Phase P migration must be listed as required');
$install=substr($migration,strpos($migration,'private static function install_canonical_attendance_intake_authority'),strpos($migration,'private static function verify_canonical_attendance_intake_schema')-strpos($migration,'private static function install_canonical_attendance_intake_authority'));
// Only the two mutable registry aggregates (case state, participant mapping state) may carry updated_at.
if(substr_count($install,'updated_at datetime')!==2)throw new RuntimeException('Only the mutable intake case and participant-mapping aggregates may carry updated_at; Phase P evidence must stay append-only');
if(str_contains($install,'UPDATE ')||str_contains($install,'INSERT INTO'))throw new RuntimeException('Phase P migration must be additive only: no backfill or legacy import');
if(stripos($install,'google')!==false)throw new RuntimeException('Phase P storage must not contain provider-specific columns');
if(str_contains($migration,"SHOW COLUMNS FROM {\$physical} LIKE '%google%'")){/* provider-neutrality guard present */}else throw new RuntimeException('Phase P verifier must reject provider-specific storage');
// Capability repair must be per-capability, never gated on a single grant.
if(str_contains($migration,"if(!\$role->has_cap('dzn_manage_canonical_attendance_review')){"))throw new RuntimeException('Phase P capability repair must not be gated on one capability');
foreach(array('dzn_manage_canonical_attendance_identity','dzn_view_canonical_attendance_review')as$n)if(!str_contains($migration,'$phasePGrants')||!str_contains($migration,$n))throw new RuntimeException('Phase P capability repair must independently ensure '.$n);
if(!str_contains($migration,'remove_cap'))throw new RuntimeException('Phase P capability repair must withhold reserved authority from the Teacher role');

// Locked temporal window and threshold.
foreach(array("RULE_VERSION='canonical_attendance_overlap_v1'","THRESHOLD_SECONDS=1200","PRE_GRACE_SECONDS=0","POST_GRACE_SECONDS=900")as$n)if(!str_contains($rule,$n))throw new RuntimeException('Missing locked Phase P rule constant: '.$n);
if(!str_contains($rule,'$end+self::POST_GRACE_SECONDS'))throw new RuntimeException('Qualifying window must end at scheduled end plus the post-class grace');
if(!str_contains($rule,'$start+self::PRE_GRACE_SECONDS'))throw new RuntimeException('Qualifying window must start at the scheduled start with zero pre-class grace');
if(!str_contains($rule,'$seconds>=self::THRESHOLD_SECONDS'))throw new RuntimeException('Automatic success must require at least the locked threshold');
if(!str_contains($rule,'EXCLUDED_OPEN_INTERVAL')||!str_contains($rule,'EXCLUDED_IMPOSSIBLE_INTERVAL'))throw new RuntimeException('Open and impossible intervals must be explicitly excluded');
if(!str_contains($rule,'private static function union')||!str_contains($rule,'private static function intersect'))throw new RuntimeException('Overlap must union per participant then intersect');

// P-1 durable provider-neutral participant identity authority.
foreach(array("const CAPABILITY='dzn_manage_canonical_attendance_identity'",'record_participant_mapping','revoke_participant_mapping','provider_account_digest','mapping_version','public static function resolve','public static function resolveExpected')as$n)if(!str_contains($identity,$n))throw new RuntimeException('Missing Phase P identity authority contract: '.$n);
if(stripos($identity,'google')!==false||stripos($identity,'zoom')!==false)throw new RuntimeException('The identity authority must not encode provider-specific behaviour');
if(!str_contains($repo,'mappingsForAccount')||!str_contains($repo,'mappingsForDigests')||!str_contains($repo,'insertMapping'))throw new RuntimeException('Participant identity mapping storage access is missing');
if(str_contains($intake,"??'resolved'")||str_contains($intake,"??'verified'"))throw new RuntimeException('Provider intake must never default identity or verification to resolved/verified');
foreach(array("input['participant_identity_state']","input['verification_state']","input['resolved_student_id']","input['resolved_teacher_id']")as$n)if(str_contains($intake,$n))throw new RuntimeException('Provider intake must never accept caller-asserted identity or verification: '.$n);
if(!str_contains($intake,'Provider account identity required'))throw new RuntimeException('Provider intake must require an authenticated provider account identity');
if(!str_contains($intake,'resolveIdentity')||!str_contains($intake,'CanonicalAttendanceIdentityService::resolve'))throw new RuntimeException('Provider intake must resolve identity only through the durable registry');
if(!str_contains($intake,'canonical_attendance_integrity_conflict'))throw new RuntimeException('Provider intake must fail closed when the post-insert aggregate is invalid');

// Intake is not delivery truth; settlement delegates to Phase O and Lesson authority.
foreach(array("const PROVIDER_CAPABILITY='dzn_ingest_canonical_attendance_evidence'","const STUDENT_CLAIM_CAPABILITY='dzn_submit_own_attendance_claim'","const TEACHER_CLAIM_CAPABILITY='dzn_submit_own_delivery_claim'","const REVIEW_CAPABILITY='dzn_manage_canonical_attendance_review'",'ingestProviderEvidence','submitClaim','adjudicate','reassess','assessAndDecide','cutover_policy_required','occurrence_before_cutover','schedule_version_conflict','provider_evidence_missing','overlap_below_threshold','teacher_participation_unproven','student_participation_unproven','late_evidence','canonical_outcome_exists','lesson_cancelled')as$n)if(!str_contains($intake,$n))throw new RuntimeException('Missing Phase P intake contract: '.$n);
if(!str_contains($intake,'CanonicalLessonDeliveryService'))throw new RuntimeException('Phase P must delegate canonical outcomes to Phase O');
if(!str_contains($settlement,'CanonicalLessonDeliveryService')||!str_contains($settlement,'CanonicalLessonAuthorityService'))throw new RuntimeException('Settlement must delegate to Phase O and Lesson authority');
if(!str_contains($settlement,'attendance-settlement-outcome-')||!str_contains($settlement,'attendance-settlement-complete-'))throw new RuntimeException('Settlement must use deterministic staged convergence keys');
if(!str_contains($settlement,'attendance_settlement_incomplete'))throw new RuntimeException('Settlement must verify canonical truth before reporting success');
if(str_contains($intake,'INSERT INTO')||str_contains($settlement,'INSERT INTO'))throw new RuntimeException('Phase P must not write canonical storage with raw SQL');
foreach(array('canonical_lesson_delivery_outcomes','canonical_academy_obligations','canonical_replacement_origin_lesson_id')as$n)if(str_contains($phaseP,'INSERT INTO '.$n))throw new RuntimeException('Phase P must never insert canonical Phase-O/M storage directly: '.$n);
if(str_contains($intake,'createReplacement'))throw new RuntimeException('Phase P must never create Phase-M replacements');
if(str_contains($settlement,'owe('))throw new RuntimeException('Phase P must never create academy obligations directly');

// P-2: canonical consequences must hydrate a durable, fully validated case.
if(!str_contains($settlement,'public function settleDelivered(int $caseId'))throw new RuntimeException('Settlement must accept a durable case ID, never a caller-supplied case object');
if(str_contains($settlement,'settleDelivered(object'))throw new RuntimeException('Settlement must never trust a caller-supplied case object');
if(!str_contains($settlement,'attendance_settlement_not_pending'))throw new RuntimeException('Settlement must refuse a case with no durable settlement intent');
if(!str_contains($settlement,'CanonicalAttendanceValidator::validForCase'))throw new RuntimeException('Settlement must validate the durable aggregate before mutating canonical truth');
if(!str_contains($settlement,'schedule_version_conflict'))throw new RuntimeException('Settlement must refuse a superseded schedule version');
if(!str_contains($intake,'hydrateDurableCase'))throw new RuntimeException('Adjudication and reassessment must hydrate the durable case');

// P-6: caller-supplied expected case version.
if(!str_contains($intake,'expected_case_version_required')||!str_contains($intake,'stale_case_version'))throw new RuntimeException('Adjudication must require and enforce the caller-supplied expected case version');
if(!str_contains($intake,"'expected_case_version'=>\$expected"))throw new RuntimeException('The expected case version must be bound into the adjudication command identity');

// P-3/P-4: context-bound replay and durable conflict receipts.
if(!str_contains($intake,'sameEvidenceContext')||!str_contains($intake,'recordConflict'))throw new RuntimeException('Provider replay must prove full context equality and record durable conflicts');
if(!str_contains($intake,'duplicate_event_conflict'))throw new RuntimeException('A refused conflicting provider event must leave a reviewable anomaly');
if(!str_contains($validator,"'changed_payload'")&&!str_contains($validator,'CONFLICT_KINDS'))throw new RuntimeException('Conflict classification must be a controlled vocabulary');
if(!str_contains($intake,'cross_context')||!str_contains($intake,'cross_lesson'))throw new RuntimeException('Cross-context provider replay must be classified, not treated as the current command result');

// P-5: exact ingest replay resumes interrupted settlement.
if(!str_contains($intake,'convergeSettlement')||!str_contains($intake,'settlement_pending'))throw new RuntimeException('Exact ingest replay must resume a pending settlement');
if(!str_contains($intake,'recordSettlementResult'))throw new RuntimeException('Settlement must record its converged result exactly once');

// P-7: prospective cutover authority.
if(!str_contains($intake,'cutover_instant_not_prospective'))throw new RuntimeException('A cutover instant must be prospective at activation');
if(!str_contains($repo,'applicablePolicy')||!str_contains($repo,'cutover_utc<='))throw new RuntimeException('Cutover applicability must be deterministic from the occurrence instant');
if(str_contains($repo,'cutover_utc DESC,id DESC')||str_contains($repo,'cutover_utc DESC, id DESC'))throw new RuntimeException('Cutover policy must never use the database insertion id as an authority tie-breaker');
if(!str_contains($repo,'policyForInstant')||!str_contains($repo,'cutover_policy_ambiguous'))throw new RuntimeException('Cutover applicability must be unambiguous and fail closed on duplicate instants');
if(!str_contains($intake,'duplicate_cutover_instant'))throw new RuntimeException('A second policy at the same cutover instant must be rejected');
if(!str_contains($migration,'UNIQUE KEY cutover_instant(cutover_utc)')&&!str_contains($migration,"'cutover_instant',true"))throw new RuntimeException('Migration 023 must enforce one immutable cutover policy per instant');
if(!str_contains($validator,"'policy'")&&!str_contains($validator,'$policy'))throw new RuntimeException('The validator must validate the cutover policy binding');
foreach(array('->threshold_seconds','->pre_grace_seconds','->post_grace_seconds','->cutover_utc')as$n)if(!str_contains($validator,$n))throw new RuntimeException('The validator must freeze the cutover policy constants: '.$n);
if(!str_contains($validator,'rule_version')||!str_contains($validator,'CanonicalAttendanceRule::RULE_VERSION'))throw new RuntimeException('The validator must validate the case rule version');

// P-8: duplicate-key recovery always compares the expected payload, with no null-digest bypass.
if(str_contains($intake,'replayCommand($winner,null'))throw new RuntimeException('Duplicate command recovery must never pass a null expected payload/context digest');
foreach(array('replayIngestCommand','replayClaimCommand','replayAdjudicationCommand','replayReassessCommand')as$n)if(!str_contains($intake,$n))throw new RuntimeException('Duplicate command recovery is missing: '.$n);
foreach(array('replayIngestCommand','replayClaimCommand')as$fn){
    $start=strpos($intake,'private function '.$fn);
    if($start===false)throw new RuntimeException('Missing duplicate-command recovery helper: '.$fn);
    $end=strpos($intake,'private function ',$start+1);
    $body=$end===false?substr($intake,$start):substr($intake,$start,$end-$start);
    if(!str_contains($body,'CanonicalAttendanceIdempotency::payload('))throw new RuntimeException($fn.' must reconstruct the complete incoming payload digest');
    if(!str_contains($body,"replayCommand(\$winner,\$expected"))throw new RuntimeException($fn.' must compare the reconstructed expected digest through the canonical replay path');
}
// R3-2: the replay primitive itself must compare unconditionally and expose no nullable/sentinel bypass.
$replayStart=strpos($intake,'private function replayCommand(');
if($replayStart===false)throw new RuntimeException('replayCommand primitive is missing');
$replayEnd=strpos($intake,'private function ',$replayStart+1);
$replayBody=$replayEnd===false?substr($intake,$replayStart):substr($intake,$replayStart,$replayEnd-$replayStart);
if(!str_contains($replayBody,'private function replayCommand(object $command,string $payload,string $operation):array'))throw new RuntimeException('replayCommand must require a non-null expected digest');
if(str_contains($replayBody,'?string $payload')||str_contains($replayBody,'$payload!==null')||str_contains($replayBody,'$payload !== null'))throw new RuntimeException('replayCommand must never contain a nullable or conditional expected-digest bypass');

// P-10 + protected read: identity, policy and decision-chain validation before presenting review data.
foreach(array("const CAPABILITY='dzn_view_canonical_attendance_review'",'CanonicalLessonDeliveryValidator::effective','CanonicalAcademyObligationRepository','canonical_attendance_integrity_conflict','evidence_set_digest','schedule_version_conflict','cutover','conflict_count','recorded_overlap_seconds')as$n)if(!str_contains($read,$n))throw new RuntimeException('Missing Phase P protected read contract: '.$n);
if(str_contains($read,'INSERT INTO')||str_contains($read,'UPDATE '))throw new RuntimeException('The protected read must never mutate state');
if(str_contains($read,'->begin()'))throw new RuntimeException('The protected read must not open a write transaction or take write locks');
if(stripos($read,'provider_payload')!==false&&!str_contains($read,'No raw provider payload'))throw new RuntimeException('Protected read must never expose raw provider payloads');

// Automation may not be replaced by click/link evidence, and unresolved review is not auto-published.
if(stripos($phaseP,'join_click')!==false||stripos($phaseP,'link_click')!==false)throw new RuntimeException('A join/link click must never settle attendance');
$assessBody=substr($intake,strpos($intake,'private function assessAndDecide'),strpos($intake,'private function maybeSettle')-strpos($intake,'private function assessAndDecide'));
if(str_contains($assessBody,"'review_required'"))throw new RuntimeException('Phase P must not auto-publish Phase-O review_required');
if(str_contains($assessBody,"'teacher_non_delivery'"))throw new RuntimeException('Failed assessment must never identify Teacher responsibility automatically');
if(!str_contains($assessBody,"'ready_for_review'"))throw new RuntimeException('Phase P anomalies must surface as its own review state');
if(str_contains($settlement,"review_required")||str_contains($settlement,"teacher_non_delivery"))throw new RuntimeException('Automatic settlement must only ever publish delivered truth');
if(!str_contains($intake,'lesson_not_delivery_recordable'))throw new RuntimeException('Administrative publishing must respect Lesson lifecycle recordability');
if(!str_contains($settlement,'lesson_cancelled'))throw new RuntimeException('Settlement must refuse cancelled Lessons');

// Commands are digest-only and staged.
foreach(array('command_key_digest','provider_event_key_digest','provider_payload_digest','IdempotencyConflictException')as$n)if(!str_contains($intake.$repo,$n))throw new RuntimeException('Missing Phase P idempotency contract: '.$n);

foreach(array('phase-2a2p-runtime.php','phase-2a2p-overlap-runtime.php','phase-2a2p-corruption-runtime.php','phase-2a2p-failure-runtime.php','phase-2a2p-migration-runtime.php','phase-2a2p-concurrency-runner.sh','phase-2a2p-concurrency-setup.php','phase-2a2p-concurrency-worker.php','phase-2a2p-concurrency-wait.php','phase-2a2p-concurrency-verify.php')as$f)if(!is_file($root.'/tests/'.$f))throw new RuntimeException('Missing committed Phase P validation artefact: '.$f);
echo "phase-2a2p-contract: pass\n";
