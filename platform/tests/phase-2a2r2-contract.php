<?php
/** Phase 2A.2-R2 renewal, next-Term, recurring collection, recovery, lapse & refund authority source contract. */
$root=dirname(__DIR__);
$plugin=file_get_contents($root.'/delnavazan-platform.php');
$migration=file_get_contents($root.'/src/Core/Infrastructure/Migration/Migrator.php');
$rule=file_get_contents($root.'/src/Core/Application/RecurringRule.php');
$support=file_get_contents($root.'/src/Core/Application/RecurringSupport.php');
$idempotency=file_get_contents($root.'/src/Core/Application/RecurringIdempotency.php');
$enrol=file_get_contents($root.'/src/Core/Application/RecurringEnrolmentService.php');
$cycle=file_get_contents($root.'/src/Core/Application/RenewalCycleService.php');
$collection=file_get_contents($root.'/src/Core/Application/CollectionIntentService.php');
$recovery=file_get_contents($root.'/src/Core/Application/RecoveryService.php');
$refund=file_get_contents($root.'/src/Core/Application/RefundReviewService.php');
$protection=file_get_contents($root.'/src/Core/Application/RecurringProtectionService.php');
$read=file_get_contents($root.'/src/Core/Application/RecurringEnrolmentReadService.php').file_get_contents($root.'/src/Core/Application/RenewalCycleReadService.php').file_get_contents($root.'/src/Core/Application/CollectionReadService.php').file_get_contents($root.'/src/Core/Application/RecoveryReadService.php').file_get_contents($root.'/src/Core/Application/RefundReviewReadService.php').file_get_contents($root.'/src/Core/Application/RecurringProtectionReadService.php');
$integrity=file_get_contents($root.'/src/Core/Application/RecurringIntegrity.php');
$outbox=file_get_contents($root.'/src/Core/Infrastructure/Repository/RecurringOutboxRepository.php');
$runtime=file_get_contents($root.'/tests/phase-2a2r2-runtime.php');
$corruption=file_get_contents($root.'/tests/phase-2a2r2-corruption-runtime.php');
$failure=file_get_contents($root.'/tests/phase-2a2r2-failure-runtime.php');
$fixture=file_get_contents($root.'/tests/phase-2a2r2-fixture.php');
$concurrency=file_get_contents($root.'/tests/phase-2a2r2-concurrency-runner.sh').file_get_contents($root.'/tests/phase-2a2r2-concurrency-setup.php').file_get_contents($root.'/tests/phase-2a2r2-concurrency-worker.php').file_get_contents($root.'/tests/phase-2a2r2-concurrency-verify.php');
$phaseR2=$rule.$support.$idempotency.$enrol.$cycle.$collection.$recovery.$refund.$protection.$read.$integrity;

if(!preg_match("/DZN_PLATFORM_SCHEMA_VERSION', '([0-9]+)'/",$plugin,$schema)||(int)$schema[1]<26)throw new RuntimeException('Missing Phase R2 schema identity');
// The package build identity is monotonic, exactly as the R1 suite states it and as every later phase
// suite already asserts: R2's identity must still be recognisable in the bootstrap while any additive
// descendant phase (for example S) may stamp a later one of the same canonical shape.
if(!preg_match("/DZN_PLATFORM_BUILD_ID', 'phase2a2[a-z0-9]+-[a-z0-9-]+-[0-9]{8}\.[0-9]+'/",$plugin))throw new RuntimeException('Missing Phase R2 build identity');

// Migration, storage, verifier wiring and capabilities.
foreach(array(
    '026_renewal_recurring_enrolment_authority','install_renewal_recurring_enrolment_authority','verify_renewal_recurring_enrolment_schema',
    'recurring_enrolments','recurring_enrolment_events','recurring_enrolment_commands',
    'renewal_cycles','renewal_cycle_events','renewal_cycle_commands',
    'collection_intents','collection_intent_events','collection_intent_commands',
    'recovery_cases','recovery_case_events','recovery_case_commands',
    'refund_review_cases','refund_review_events','refund_review_commands',
    'recurring_protections','recurring_protection_events','recurring_protection_commands',
    'dzn_manage_recurring_enrolments','dzn_manage_renewal_cycles','dzn_manage_collection_intents',
    'dzn_manage_recovery','dzn_manage_refund_reviews','dzn_manage_recurring_protection','dzn_view_recurring_authority',
) as $needle) if(!str_contains($migration.$plugin,$needle))throw new RuntimeException('Missing Phase R2 migration contract: '.$needle);
if(!str_contains($migration,"if(\$id==='026_renewal_recurring_enrolment_authority')self::verify_renewal_recurring_enrolment_schema();"))throw new RuntimeException('Migration 026 must invoke the Phase R2 schema verifier before it is recorded');
if(substr_count($migration,'self::verify_renewal_recurring_enrolment_schema();')<3)throw new RuntimeException('Phase R2 verifier must run after migration 026, on current-schema verification and before schema activation');
if(!str_contains($migration,"in_array( '026_renewal_recurring_enrolment_authority', (array) get_option( self::COMPLETED, array() ), true )"))throw new RuntimeException('Retained-026 pre-activation verification is missing');
// The required list is asserted over the list itself, so an additive descendant phase may append its own
// migration to the same array without invalidating R2's assertion about its own entry.
$requiredStart=strpos($migration,'$required = array(');
$requiredList=substr($migration,$requiredStart,strpos($migration,');',$requiredStart)-$requiredStart);
if($requiredStart===false||!str_contains($requiredList,"'026_renewal_recurring_enrolment_authority'"))throw new RuntimeException('Phase R2 migration must be listed as required');
$install=substr($migration,strpos($migration,'private static function install_renewal_recurring_enrolment_authority'),strpos($migration,'private static function verify_renewal_recurring_enrolment_schema')-strpos($migration,'private static function install_renewal_recurring_enrolment_authority'));
if(str_contains($install,'UPDATE ')||str_contains($install,'INSERT INTO'))throw new RuntimeException('Phase R2 migration must be additive only');
if(stripos($install,'stripe')!==false||stripos($install,'google')!==false||stripos($install,'webhook')!==false)throw new RuntimeException('Phase R2 storage must stay provider-neutral');
// The phase creates exactly its own storage: no Lesson, Term, schedule or notification table may be
// smuggled into the migration, and no declared R2 table may be missing from it.
preg_match_all('/CREATE TABLE \{\$p\}([a-z_]+)/',$install,$created);
$declaredTables=array('recurring_enrolments','recurring_enrolment_events','recurring_enrolment_commands','renewal_cycles','renewal_cycle_events','renewal_cycle_commands','collection_intents','collection_intent_events','collection_intent_commands','recovery_cases','recovery_case_events','recovery_case_commands','refund_review_cases','refund_review_events','refund_review_commands','recurring_protections','recurring_protection_events','recurring_protection_commands');
$createdTables=$created[1];sort($createdTables);sort($declaredTables);
if($createdTables!==$declaredTables)throw new RuntimeException('Migration 026 must create exactly the eighteen declared Phase R2 tables');
if(count(array_unique($createdTables))!==18)throw new RuntimeException('Migration 026 must declare each Phase R2 table exactly once');
if(str_contains($migration,'CAPABILITY_OPTION_R2')===false||str_contains($migration,'dzn_platform_capability_version_2a2r2')===false)throw new RuntimeException('Phase R2 capability marker is missing');
if(!str_contains($migration,'$phaseR2Grants')||!str_contains($migration,'$teacherR2Role'))throw new RuntimeException('Phase R2 capability repair must ensure every grant and deny the Teacher role');

// Locked R2 state vocabularies and safe defaults.
foreach(array(
    "RECURRING_STATES=array('active','suspended','closed')","COLLECTION_MODES=array('manual','automatic')",
    "CYCLE_STATES=array('pending','guarantee_protected','payment_required','collected','term_bound','closed','lapsed','cancelled')",
    "COLLECTION_INTENT_STATES=array('pending','submitted','confirmed','failed','recovered','cancelled')",
    "COLLECTION_KINDS=array('manual_payment_required','automatic_charge')",
    "RECOVERY_STATES=array('open','recovering','recovered','lapsed')",
    "REFUND_REVIEW_STATES=array('open','review_required','resolved','dismissed')",
    "REFUND_REVIEW_KINDS=array('refund','reversal')","PROTECTION_STATES=array('active','released','lapsed')",
    "MANUAL_RENEWAL_SLOT_GUARANTEE_WEEKS=4",
) as $constant) if(!str_contains($rule,$constant))throw new RuntimeException('Missing locked Phase R2 rule constant: '.$constant);
foreach(array('AUTOMATIC_RENEWAL_UPCOMING','AUTOMATIC_RENEWAL_CHARGED','AUTOMATIC_RENEWAL_FAILED','MANUAL_RENEWAL_PAYMENT_REQUIRED','GUARANTEE_DEADLINE_APPROACHING','GUARANTEE_EXPIRED','PAYMENT_FAILED','PAYMENT_RECOVERED','TERM_LAPSED','REFUND_REVIEW_REQUIRED','REFUND_RESOLVED') as $intent) if(!str_contains($rule,$intent))throw new RuntimeException('Missing channel-neutral notification intent: '.$intent);

// The four unresolved product decisions stay behind safe unset/deferred seams.
if(!str_contains($migration,"academic_consequence varchar(32) NULL")||!str_contains($refund,"'academic_consequence'=>null"))throw new RuntimeException('Refund academic consequence must stay nullable/unresolved');
if(!str_contains($refund,'academic_consequence'))throw new RuntimeException('Refund authority must preserve the unresolved academic-consequence seam');
if(!str_contains($recovery,'recovery_policy_unset')||!str_contains($recovery,"PAYMENT_RECOVERY_POLICY"))throw new RuntimeException('Recovery lapse must fail closed while the recovery policy is unset');
if(!str_contains($collection,"'charge_at'")||!str_contains($collection,'automatic_charge'))throw new RuntimeException('Automatic charge must stay provider-neutral and lead-time unset by default');

// Provider neutrality, integer money and no raw SQL or scheduling in the application layer.
foreach(array('stripe','Stripe','wp_remote_','wp_mail','curl_','webhook','floatval','doubleval','round(',"number_format") as $forbidden) if(str_contains($phaseR2,$forbidden))throw new RuntimeException('Phase R2 must not acquire this authority: '.$forbidden);
foreach(array('INSERT INTO','UPDATE ','DELETE FROM') as $sql) if(str_contains($phaseR2,$sql))throw new RuntimeException('Phase R2 application services must not write storage with raw SQL');
foreach(array('cron','wp_schedule_event','wp_schedule_single_event') as $forbidden) if(str_contains($phaseR2,$forbidden))throw new RuntimeException('Phase R2 must not become time-authoritative through scheduling');

// R2 orchestrates R1/Phase-L without reimplementing it.
foreach(array('CommercialTermFundingService','bindEntitlementToTerm','CommercialCapacityService','releaseClaim','CommercialPolicyService') as $delegation) if(!str_contains($cycle.$protection.$recovery,$delegation))throw new RuntimeException('Phase R2 must delegate '.$delegation.' to the existing authority');
if(!str_contains($enrol,'funding_plan_required'))throw new RuntimeException('A recurring enrolment must fail closed without an R1 funding plan');
foreach(array('insertTerm','insertLesson','insertVersion','insertFundingPlan','insertClaim') as $forbidden) if(str_contains($phaseR2,$forbidden))throw new RuntimeException('Phase R2 must not become a parallel academic/commercial writer: '.$forbidden);

// Digest-only, append-only and capability-protected authority.
foreach(array('command_key_digest','command_payload_digest','evidence_reference_digest') as $digest) if(!str_contains($migration,$digest)||!str_contains($phaseR2,$digest))throw new RuntimeException('Phase R2 evidence must be digest-only: '.$digest);
foreach(array('dzn_manage_recurring_enrolments','dzn_manage_renewal_cycles','dzn_manage_collection_intents','dzn_manage_recovery','dzn_manage_refund_reviews','dzn_manage_recurring_protection','dzn_view_recurring_authority') as $cap) if(!str_contains($phaseR2,$cap))throw new RuntimeException('Phase R2 capability is missing: '.$cap);
if(!str_contains($idempotency,'hash_hmac')||!str_contains($idempotency,'wp_salt'))throw new RuntimeException('Phase R2 digests must be keyed');

// The R1 serialization root is the only R2 serialization device; R2 owns no lock of its own.
if(!str_contains($support,'public static function lockAccountRoot')||!str_contains($support,'CommercialAuthorityRepository'))throw new RuntimeException('Phase R2 must serialise on the R1 commercial account root');
if(!str_contains($support,'public static function guardAggregate')||!str_contains($support,'public static function aggregateStudent'))throw new RuntimeException('Phase R2 must resolve and lock aggregate ownership through one shared seam');
if(!str_contains($migration,'commercial_account_roots'))throw new RuntimeException('The R1 commercial serialization root must remain the documented lock root');
foreach(array('recurring_enrolment','renewal_cycle','collection_intent','recovery_case','refund_review','recurring_protection') as $aggregate) if(!str_contains($support,"case '".$aggregate."'"))throw new RuntimeException('Every R2 aggregate must resolve its owning Student: '.$aggregate);
foreach(array('recurring_enrolment','renewal_cycle','collection_intent','recovery_case','refund_review','recurring_protection') as $aggregate) if(!str_contains($enrol.$cycle.$collection.$recovery.$refund.$protection,"'{$aggregate}',"))throw new RuntimeException('Every R2 mutation must guard its aggregate before writing: '.$aggregate);

// The channel-neutral notification intents ride the existing outbox seam: intent names only.
if(!str_contains($support,'public static function publishIntent')||!str_contains($outbox,'platform_outbox'))throw new RuntimeException('Phase R2 must publish intents through the existing platform_outbox seam');
if(!str_contains($outbox,'wp_salt')||!str_contains($outbox,'idempotency_key'))throw new RuntimeException('An intent identity must be a keyed digest, not a raw key');
foreach(array('wp_mail','curl_','wp_remote_','notification_template','delivery_attempt') as $forbidden) if(str_contains($outbox,$forbidden))throw new RuntimeException('Phase R2 must never carry a template or deliver an intent: '.$forbidden);
if(!str_contains($rule,'public static function notificationIntent'))throw new RuntimeException('The finalised intent set must be enforced at the publish boundary');

// R2 orchestrates R1 without nesting a transaction inside a delegating R1 command.
foreach(array($cycle,$protection) as $delegating){
    $delegation=strpos($delegating,'new CommercialTermFundingService())->bindEntitlementToTerm');
    if($delegation===false)$delegation=strpos($delegating,'new CommercialCapacityService())->releaseClaim');
    if($delegation===false)throw new RuntimeException('A delegating R2 command must call the existing authority');
}
$bindSource=substr($cycle,strpos($cycle,'public function bindNextTerm'));
$bindSource=substr($bindSource,0,strpos($bindSource,'public function lapse'));
if(strpos($bindSource,'bindEntitlementToTerm')>strpos($bindSource,'$this->repository->begin();'))throw new RuntimeException('The delegated R1 binding must own its transaction: R2 must not nest one inside it');
if(!str_contains($bindSource,'assertEntitlementBoundToTerm'))throw new RuntimeException('The delegated binding must be verified before R2 records it');
$releaseSource=substr($protection,strpos($protection,'public function releaseProtection'));
$releaseSource=substr($releaseSource,0,strpos($releaseSource,'private function replay'));
if(strpos($releaseSource,'releaseClaim')>strpos($releaseSource,'$this->repository->begin();'))throw new RuntimeException('The delegated capacity release must own its transaction: R2 must not nest one inside it');
if(!str_contains($releaseSource,'claimReleased'))throw new RuntimeException('The delegated release must be verified before R2 records it');

// The boundary derivation is progression-derived, never `intro + 7 days`.
foreach(array('CommercialPatternService','CanonicalContinuationRule::resolveWallClock','commercial_recurring_patterns','boundary_facts_required','guarantee_deadline_at','AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME') as $needle) if(!str_contains($cycle,$needle))throw new RuntimeException('The next-Term boundary must be derived from the authorised R1 pattern facts: '.$needle);
if(str_contains($cycle,'intro_lesson_id')||str_contains($cycle,'anchor_starts_at_utc +7')||str_contains($cycle,"strtotime((string)\$cycle->boundary_derived_at"))throw new RuntimeException('The next-Term boundary must never be inferred from the introductory occurrence');

// The seven required concurrency modes exist as first-class scenarios.
foreach(array('renewal_vs_schedule','guarantee_vs_close','recovery_vs_satisfaction','release_vs_succession','mode_change_vs_cycle','refund_vs_settlement','unrelated_recurring_enrolments') as $race) if(!str_contains($concurrency,$race))throw new RuntimeException('Missing Phase R2 concurrency mode: '.$race);
if(!str_contains($concurrency,'gate')||!str_contains($concurrency,"'.result'")||!str_contains($concurrency,'release')||!str_contains($concurrency,'DZN_PHASE_2A2R2_RUNTIME_TEST'))throw new RuntimeException('The Phase R2 concurrency harness must gate and verify both workers');
foreach(array('dzn_phase_2a2r2_after_recurring_command_insert','dzn_phase_2a2r2_after_cycle_event_insert','dzn_phase_2a2r2_after_collection_intent_event_insert','dzn_phase_2a2r2_after_recovery_case_event_insert','dzn_phase_2a2r2_after_refund_review_event_insert','dzn_phase_2a2r2_after_protection_event_insert') as $hook) if(!str_contains($enrol.$cycle.$collection.$recovery.$refund.$protection.$support,$hook))throw new RuntimeException('Missing Phase R2 write-boundary seam: '.$hook);

// Correction round 1: derived-fact ownership, terminal-cycle protection and closure guards.
foreach(array('canonical_source_term_required',"record_model??'')!=='canonical_enrolment_term_v1'",'archived_at!==null') as $needle) if(!str_contains($cycle,$needle))throw new RuntimeException('The next-Term boundary must only accept a canonical Term of the recurring enrolment own Enrolment: '.$needle);
if(!str_contains($cycle,"(int)\$term->enrolment_id!==\$enrolmentId")||!str_contains($cycle,"array('authorised','current','closed')"))throw new RuntimeException('The source-Term guard must prove Enrolment ownership and a non-cancelled canonical Term');
if(!str_contains($collection,'collection_obligation_ownership_conflict')||!str_contains($collection,'assertCycleCollection'))throw new RuntimeException('A collection intent must prove the exact cycle obligation ownership');
foreach(array('beneficiary_student_id','course_id','currency') as $needle) if(!str_contains($collection,$needle))throw new RuntimeException('Obligation ownership must be proven against the R1 offer: '.$needle);
if(!str_contains($cycle,"state<>'cancelled' ORDER BY id ASC"))throw new RuntimeException('A cycle must be collected by its authoritative first non-cancelled obligation');
foreach(array('recurring_protection_release_required','activeProtectionId') as $needle) if(!str_contains($cycle,$needle))throw new RuntimeException('A terminal cycle may never leave an active protection behind: '.$needle);
if(!str_contains($enrol,'hasOpenBlockingCase')||!str_contains($enrol,'refund_review_cases'))throw new RuntimeException('Closing a recurring enrolment must be blocked by an open refund/recovery case');
if(!str_contains($migration,'unexpected renewal storage'))throw new RuntimeException('The Phase R2 verifier must reject storage smuggled in beside the phase tables');

// Correction round 2: cross-commitment ownership, accepted-evidence settlement and digest-only storage.
foreach(array('raw_key','idempotency_key','evidence_reference','reference_code') as $rawColumn) if(!str_contains($migration,$rawColumn))throw new RuntimeException('The Phase R2 verifier must reject a raw key or reference column on append-only storage: '.$rawColumn);
if(!str_contains($migration,'renewal evidence must stay digest-only'))throw new RuntimeException('The Phase R2 verifier must reject raw-key/reference columns on append-only tables');
foreach(array('settlementReason','accepted_payment_evidence_required','commercial_obligation_settlements') as $needle) if(!str_contains($support,$needle))throw new RuntimeException('A settlement must be re-proven from accepted R1 evidence: '.$needle);
foreach(array($collection,$cycle) as $consumer) if(!str_contains($consumer,'RecurringSupport::settlementReason'))throw new RuntimeException('Collection confirmation and cycle collection must both consume the accepted-evidence settlement rule');
foreach(array('renewal_entitlement_ownership_conflict','assertEntitlementOwnership') as $needle) if(!str_contains($cycle,$needle))throw new RuntimeException('A next-Term binding must prove the entitlement belongs to this cycle commitment: '.$needle);
foreach(array('renewal_term_binding_conflict','plan.enrolment_id') as $needle) if(!str_contains($cycle,$needle))throw new RuntimeException('The delegated binding must prove the created Term sits in the cycle own Student/Course chain: '.$needle);
foreach(array('recurring_protection_claim_conflict','assertClaimOwnership','forClaim') as $needle) if(!str_contains($protection,$needle))throw new RuntimeException('Continuous protection must adopt only the cycle own active claim: '.$needle);
foreach(array('refund_review_evidence_conflict','assertEvidenceOwnership') as $needle) if(!str_contains($refund,$needle))throw new RuntimeException('A refund review must record its own purchase/obligation evidence: '.$needle);
foreach(array('renewal_entitlement_ownership_conflict','recurring_protection_claim_conflict','refund_review_evidence_conflict','accepted_payment_evidence_required') as $probe) if(!str_contains(file_get_contents($root.'/tests/phase-2a2r2-runtime.php'),$probe))throw new RuntimeException('The runtime proof must exercise the cross-commitment guard: '.$probe);
if(!str_contains(file_get_contents($root.'/tests/phase-2a2r2-migration-runtime.php'),'raw_key'))throw new RuntimeException('The migration runtime must prove the verifier rejects raw-key storage');

// Correction round 4: recovery-state enforcement. A recovery case records a *failed* collection
// intent of a live cycle, and `recovered` records the accepted R1 evidence that settled the obligation.
foreach(array('collection_intent_not_failed','assertRecoverable','assertRecoverySettled','RecurringSupport::settlementReason') as $needle) if(!str_contains($recovery,$needle))throw new RuntimeException('The Recovery Case authority must enforce its own recorded source and settlement state: '.$needle);
if(!str_contains($recovery,"(string)\$intent->state!=='failed'")||!str_contains($recovery,'RecurringRule::CYCLE_LIVE_STATES'))throw new RuntimeException('A recovery case may only be opened on a failed collection intent of a live renewal cycle');
if(!str_contains($recovery,"array('open','recovering'),'recovered'"))throw new RuntimeException('A recovery may only be recorded from an open or recovering case');
if(!str_contains($rule,"CYCLE_LIVE_STATES=array('pending','guarantee_protected','payment_required','collected','term_bound')"))throw new RuntimeException('The live-cycle vocabulary must be a single locked constant');
if(!str_contains($protection,'RecurringRule::CYCLE_LIVE_STATES'))throw new RuntimeException('Protection and recovery must share one live-cycle vocabulary');
foreach(array('collection_intent_not_failed','obligation_not_settled','accepted_payment_evidence_required','invalid_renewal_cycle_state') as $probe) if(!str_contains(file_get_contents($root.'/tests/phase-2a2r2-runtime.php'),$probe))throw new RuntimeException('The runtime proof must exercise the recovery-state enforcement: '.$probe);

// Correction round 5 (host round 2): derived cycle mode and charge instant, refund-evidence provenance,
// current-Term protection ownership with an authorised release, and fail-closed aggregate reads.
foreach(array('AGGREGATE_TRANSITIONS','SAME_STATE_EVENT_TYPES','VERSION_NEUTRAL_EVENT_TYPES','MODE_INTENT_KINDS','REVIEW_EVIDENCE_KINDS') as $needle) if(!str_contains($rule,$needle))throw new RuntimeException('The locked R2 rule table is missing: '.$needle);
foreach(array('legalTransition','aggregateStates','aggregateEventTypes','intentKindForMode','reviewEvidenceKind','sameStateEventType','versionNeutralEventType') as $needle) if(!str_contains($rule,$needle))throw new RuntimeException('The locked R2 rule accessor is missing: '.$needle);
foreach(array('aggregate','VERSION_COLUMNS','event_sequence','to_state','advancing') as $needle) if(!str_contains($integrity,$needle))throw new RuntimeException('The fail-closed aggregate read proof is missing: '.$needle);
// The declared append-only event vocabulary must be the one the services actually write.
if(!str_contains($recovery,"?'attempt_recorded':\$to"))throw new RuntimeException('A recovery attempt must be recorded with its declared attempt_recorded event type');
// Every read model proves its aggregate against its own append-only history before returning it.
if(substr_count($read,'RecurringIntegrity::aggregate')!==6)throw new RuntimeException('Every R2 read model must validate its aggregate history before returning authority');
foreach(array('recurring_enrolment','renewal_cycle','collection_intent','recovery_case','refund_review','recurring_protection') as $aggregate) if(!str_contains($read,"RecurringIntegrity::aggregate('".$aggregate."'"))throw new RuntimeException('The read model must validate its aggregate history: '.$aggregate);
// §5.2/§5.3: the cycle mode and the charge instant are derived, never caller-supplied.
if(!str_contains($cycle,'resolveCycleMode')||!str_contains($cycle,'recurring_collection_mode_conflict'))throw new RuntimeException('A renewal cycle must snapshot the locked recurring-enrolment collection mode and refuse a conflicting input');
if(!str_contains($cycle,'enrolment($recurringEnrolmentId,true)'))throw new RuntimeException('The cycle mode must be derived from the locked recurring enrolment');
if(!str_contains($cycle,'public static function automaticChargeAt'))throw new RuntimeException('The charge instant must have one shared derivation seam');
foreach(array('collection_charge_time_not_authoritative','collection_intent_kind_conflict','assertCycleCollection','automatic_charge_at') as $needle) if(!str_contains($collection,$needle))throw new RuntimeException('The collection intent authority must derive its kind and read its charge instant: '.$needle);
// §6.2.4(b)(4) (R2 amendment): the collection side reads the instant the cycle persisted when it
// announced the advance notice. A second derivation from the current lead-time policy would let the
// charged instant diverge from the announced one, so the seam is removed rather than merely unused.
if(str_contains($collection,'automaticChargeAt'))throw new RuntimeException('The collection side must read the persisted tier-F instant instead of re-deriving it');
if(!str_contains($cycle,'\'automatic_charge_at\'=>$chargeAt'))throw new RuntimeException('The cycle-open transaction must persist the announced instant durably');
if(!str_contains($cycle,'$mode===\'manual\'?\'MANUAL_RENEWAL_PAYMENT_REQUIRED\':null'))throw new RuntimeException('An automatic require_payment transition must publish no second advance notice');
if(!str_contains($collection,"!=='payment_required'")||!str_contains($collection,'invalid_renewal_cycle_state'))throw new RuntimeException('A collection intent may only open on a live payment-required cycle');
// §5.5: a review records matching authoritative refund evidence, and never an unrepresented reversal.
foreach(array('reversal_evidence_not_supported','refund_review_amount_conflict','evidence_kind','processing_state') as $needle) if(!str_contains($refund,$needle))throw new RuntimeException('A refund review must prove the authoritative evidence provenance: '.$needle);
if(!str_contains($refund,'$evidence->amount_minor===null||(string)$evidence->currency!==$currency'))throw new RuntimeException('A refund review must adopt the exact amount and currency of its evidence');
// §5.6: protection binds the current-Term claim, and a release needs a durable successor or a terminal path.
foreach(array('releaseRefusal','successorDurable','terminalLapseRecorded','renewal_successor_not_durable','cycle_term_id') as $needle) if(!str_contains($protection,$needle))throw new RuntimeException('Continuous protection must prove current-Term ownership and its release authority: '.$needle);
if(!str_contains($protection,'(int)$claim->term_id!==(int)$claim->cycle_term_id'))throw new RuntimeException('Protection must bind the claim to the cycle own source Term');
if(!str_contains($protection,'in_array((string)$claim->cycle_state,RecurringRule::CYCLE_LIVE_STATES,true)'))throw new RuntimeException('Protection must revalidate the live cycle state under serialization');
if(!str_contains($protection,'if($this->claimReleased($claimId))return null;'))throw new RuntimeException('An R1-resolved claim must let R2 record the terminal protection instead of leaving it live');
if(!str_contains($runtime,'dzn_r1_fix_release_claim'))throw new RuntimeException('The runtime proof must exercise the R1-resolution terminal path');
// The new guards are exercised by the runtime, corruption and concurrency proofs.
foreach(array('reversal_evidence_not_supported','refund_review_amount_conflict','refund_review_evidence_conflict','recurring_collection_mode_conflict','collection_intent_kind_conflict','collection_charge_time_not_authoritative','renewal_successor_not_durable','recurring_protection_claim_conflict') as $probe) if(!str_contains($runtime,$probe))throw new RuntimeException('The runtime proof must exercise the new authority guard: '.$probe);
foreach(array('recurring_enrolment_integrity_conflict','renewal_cycle_integrity_conflict','collection_intent_integrity_conflict','recovery_case_integrity_conflict','refund_review_integrity_conflict','recurring_protection_integrity_conflict') as $probe) if(!str_contains($corruption,$probe))throw new RuntimeException('The corruption proof must exercise the aggregate read guard: '.$probe);
if(!str_contains($corruption,"'to_state','closed','active'")||!str_contains($corruption,"'to_state','collected','guarantee_protected'"))throw new RuntimeException('The corruption proof must re-write stored history and prove the read fails closed');
foreach(array('dzn_r2_fix_refund_evidence','refundEvidence') as $needle) if(!str_contains($fixture.$runtime.$corruption.$failure.$concurrency,$needle))throw new RuntimeException('The fixtures must record authoritative refund evidence: '.$needle);
if(!str_contains($failure,'releaseProtection')||!str_contains($concurrency,'bindNextTerm')||!str_contains($concurrency,"==='term_bound'"))throw new RuntimeException('The delegated release must be exercised by the failure and concurrency proofs');
if(!str_contains($concurrency,"collection_mode==='automatic','the cycle must snapshot the recorded mode"))throw new RuntimeException('The mode race must prove the cycle snapshots the recorded enrolment mode');

// Correction round 6 (host round 3): the per-aggregate event-type/transition proof, the audited
// collection-mode continuity of §5.1, and the fail-closed public history reads of §7.3.
foreach(array('AGGREGATE_EVENT_TRANSITIONS','eventTransitions','recordsTransition') as $needle) if(!str_contains($rule,$needle))throw new RuntimeException('The locked R2 event-type/transition map is missing: '.$needle);
foreach(array('MODE_AUDITED_AGGREGATE','MODE_CHANGE_EVENT_TYPE','recordsTransition','from_collection_mode','to_collection_mode','previousMode') as $needle) if(!str_contains($integrity,$needle))throw new RuntimeException('The aggregate proof must pair each transition with its event type and audit the collection mode: '.$needle);
if(!str_contains($rule,"'collection_mode_changed'=>array('active|active','suspended|suspended')"))throw new RuntimeException('The audited collection mode may only change through its own append-only event type');
// The event-type map may neither leave a legal transition unrecorded nor invent one the locked table
// does not carry, and the recovered/attempt vocabulary must stay append-only-readable.
if(!class_exists('Delnavazan\\Platform\\Core\\Application\\RecurringRule'))require_once $root.'/src/Core/Application/RecurringRule.php';
$mappedEventTypes=Delnavazan\Platform\Core\Application\RecurringRule::AGGREGATE_EVENT_TRANSITIONS;
foreach(Delnavazan\Platform\Core\Application\RecurringRule::AGGREGATE_TRANSITIONS as $aggregate=>$transitions){
    $recorded=array();
    foreach($mappedEventTypes[$aggregate]??array() as $eventType=>$patterns) foreach($patterns as $pattern) $recorded[$pattern]=$eventType;
    foreach($transitions as $pattern) if(!isset($recorded[$pattern]))throw new RuntimeException('Every legal transition must be recorded by exactly one event type: '.$aggregate.' '.$pattern);
    foreach(array_keys($recorded) as $pattern) if(!in_array($pattern,$transitions,true))throw new RuntimeException('The event-type map must not record a transition the locked table does not carry: '.$aggregate.' '.$pattern);
}
if(!str_contains($rule,"'attempt_recorded'=>array('open|recovering','recovering|recovering')"))throw new RuntimeException('A repeated recovery attempt must stay a legal append-only event');
// Both public read seams of every read model share one validated loader: the history read can never
// bypass the proof that `one()` applies.
foreach(array('RecurringEnrolmentReadService','RenewalCycleReadService','CollectionReadService','RecoveryReadService','RefundReviewReadService','RecurringProtectionReadService') as $service){
    $model=file_get_contents($root.'/src/Core/Application/'.$service.'.php');
    if(substr_count($model,'$this->validated($id)')!==2)throw new RuntimeException('Both public read seams must share one validated loader: '.$service);
    if(!preg_match('/public function events\(int \$id\):array\{[^}]*\$this->validated\(\$id\)[^}]*\}/',$model))throw new RuntimeException('The public history read must prove its aggregate before shaping an event: '.$service);
    if(preg_match('/public function events\(int \$id\):array\{[^}]*\$this->history\(/',$model))throw new RuntimeException('The public history read must never read its raw history table directly: '.$service);
}
foreach(array("'collection_mode','automatic','manual'","'to_collection_mode','automatic','manual'","'event_type','resumed','collection_mode_changed'","'event_type','released','extended'") as $probe) if(!str_contains($corruption,$probe))throw new RuntimeException('The corruption proof must re-write a valid alternate mode and a valid-but-forged event type: '.$probe);
// Every one of the six public history seams is proved fail-closed by the corruption runtime.
foreach(array('RecurringEnrolmentReadService','RenewalCycleReadService','CollectionReadService','RecoveryReadService','RefundReviewReadService','RecurringProtectionReadService') as $service) if(!str_contains($corruption,'(new '.$service.'())->events('))throw new RuntimeException('The corruption proof must read the public history seam fail-closed: '.$service);
if(!str_contains($runtime,'the mode history must be append-only and ordered')||!str_contains($runtime,"'attempt-b-again'")||!str_contains($runtime,'a repeated recovery attempt must stay readable as an audited same-state append'))throw new RuntimeException('The runtime proof must exercise the audited mode history and a repeated recovery attempt');

echo "Phase 2A.2-R2 contract static test passed\n";
