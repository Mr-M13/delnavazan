<?php
/** Phase 2A.2-E static authority guard. */
$root=dirname(__DIR__);
$service=file_get_contents($root.'/src/Core/Application/ProposalAcceptanceService.php');
$repo=file_get_contents($root.'/src/Core/Infrastructure/Repository/ProposalAcceptanceRepository.php');
$idem=file_get_contents($root.'/src/Core/Application/AcceptanceIdempotency.php');
$migration=file_get_contents($root.'/src/Core/Infrastructure/Migration/Migrator.php');
$controller=file_get_contents($root.'/src/Admin/Controller/CoordinationCaseController.php');
$plugin=file_get_contents($root.'/delnavazan-platform.php');
$worker=file_get_contents($root.'/tests/phase-2a2e-concurrency-worker.php');
$setup=file_get_contents($root.'/tests/phase-2a2e-concurrency-setup.php');
$verify=file_get_contents($root.'/tests/phase-2a2e-concurrency-verify.php');
$fixture=file_get_contents($root.'/tests/phase-2a2e-concurrency-fixture.php');
$runner=file_get_contents($root.'/tests/phase-2a2e-concurrency-runner.sh');
if(!preg_match("/DZN_PLATFORM_SCHEMA_VERSION', '([0-9]+)'/",$plugin,$schema)||(int)$schema[1]<11)throw new RuntimeException('Missing compatible provisional acceptance schema marker');foreach(array('011_provisional_acceptance_evidence','proposal_acceptance_events','ENGINE=InnoDB','accepted_pending_conditions','authority_unresolved','dzn_record_booking_request_provisional_acceptance','command_key_digest','command_payload_digest','exact_target','verify_provisional_acceptance_schema')as$n)if(strpos($service.$repo.$idem.$migration.$controller.$plugin,$n)===false)throw new RuntimeException('Missing provisional acceptance invariant: '.$n);
foreach(array('proposal_family_id','proposal_option_id','proposal_version_id','family_uid','option_uid','version_uid','version_number','version_fingerprint','prospective_subject_ref','evidence_channel','evidence_reference','evidence_at','recorded_by')as$n)if(strpos($migration,$n)===false)throw new RuntimeException('Missing immutable evidence field: '.$n);
foreach(array('consumeCurrentForFutureProposalIssuance','register_rest_route','createStudent','createEnrolment','createLesson','accepted_service','platform_payments','amelia_','wp_amelia','platform_outbox')as$n)if(stripos($service.$repo,$n)!==false)throw new RuntimeException('Provisional acceptance exceeded authority: '.$n);
$acceptanceSchema=substr($migration,strpos($migration,'CREATE TABLE {$p}proposal_acceptance_events'));
$acceptanceSchema=substr($acceptanceSchema,0,strpos($acceptanceSchema,'ENGINE=InnoDB'));
foreach(array('full_name','email','mobile','whatsapp','address','contact_snapshot')as$n)if(stripos($acceptanceSchema,$n)!==false)throw new RuntimeException('Provisional evidence stores prohibited PII: '.$n);
if(stripos($repo,'raw idempotency')!==false)throw new RuntimeException('Provisional evidence persists a raw idempotency key');
if(preg_match('/function\s+(?:update|delete)/i',$repo))throw new RuntimeException('Acceptance repository is not append-only');
if(!str_contains($service,'requestForUpdate')||!str_contains($service,'caseForUpdate')||!str_contains($service,'familyForCaseForUpdate')||!str_contains($service,'currentVersionForOption'))throw new RuntimeException('Exact target lock sequence is incomplete');
foreach(array('isDuplicate','eventForCommandForUpdate','command_payload_digest','IdempotencyConflictException')as$n)if(strpos($service.$repo,$n)===false)throw new RuntimeException('Cross-request duplicate recovery is incomplete: '.$n);
foreach(array('wp_get_environment_type','DZN_PHASE_2A2E_GATE_DIR','synthetic_domain','phase-2a2e.invalid','wait_gate','outcome=conflict','proposal_acceptance_events','dzn_phase_2a0_create_ready_teacher_fixture','BookingRequestSubmissionService','recordAdministratorAttestation','issueInitial','c1','c2','d1','d2','x1','x2')as$n)if(strpos($worker.$setup.$verify.$runner.$fixture,$n)===false)throw new RuntimeException('Reproducible Race A-D/X harness rule missing: '.$n);
if(!str_contains($controller,'record_provisional_acceptance')||!str_contains($controller,'check_admin_referer')||!str_contains($controller,'requireAcceptanceCapability'))throw new RuntimeException('Protected acceptance admin surface missing');
echo "Phase 2A.2-E source contract passed\n";
