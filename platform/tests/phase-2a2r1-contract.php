<?php
/** Phase 2A.2-R1 commercial purchase, funding & current-Term capacity authority source contract. */
$root=dirname(__DIR__);
$plugin=file_get_contents($root.'/delnavazan-platform.php');
$migration=file_get_contents($root.'/src/Core/Infrastructure/Migration/Migrator.php');
$rule=file_get_contents($root.'/src/Core/Application/CommercialRule.php');
$money=file_get_contents($root.'/src/Core/Application/CommercialMoney.php');
$validator=file_get_contents($root.'/src/Core/Application/CommercialValidator.php');
$lineage=file_get_contents($root.'/src/Core/Application/CommercialLineageValidator.php');
$commitment=file_get_contents($root.'/src/Core/Application/CommercialCommitmentValidator.php');
$commandShape=file_get_contents($root.'/src/Core/Application/CommercialCommandShape.php');
$offer=file_get_contents($root.'/src/Core/Application/CommercialOfferService.php');
$payment=file_get_contents($root.'/src/Core/Application/CommercialPaymentService.php');
$funding=file_get_contents($root.'/src/Core/Application/CommercialTermFundingService.php');
$capacity=file_get_contents($root.'/src/Core/Application/CommercialCapacityService.php');
$capacityAuthority=file_get_contents($root.'/src/Core/Application/CommercialCapacityAuthority.php');
$pattern=file_get_contents($root.'/src/Core/Application/CommercialPatternService.php');
$policy=file_get_contents($root.'/src/Core/Application/CommercialPolicyService.php');
$catalogue=file_get_contents($root.'/src/Core/Application/CommercialCatalogueService.php');
$promotion=file_get_contents($root.'/src/Core/Application/CommercialPromotionService.php');
$adjustment=file_get_contents($root.'/src/Core/Application/CommercialAdjustmentService.php');
$exceptions=file_get_contents($root.'/src/Core/Application/CommercialExceptionService.php');
$read=file_get_contents($root.'/src/Core/Application/CommercialReadService.php');
$support=file_get_contents($root.'/src/Core/Application/CommercialSupport.php');
$idempotency=file_get_contents($root.'/src/Core/Application/CommercialIdempotency.php');
$termAuthority=file_get_contents($root.'/src/Core/Application/CanonicalTermAuthorityService.php');
$lessonAuthority=file_get_contents($root.'/src/Core/Application/CanonicalLessonAuthorityService.php');
$schedules=file_get_contents($root.'/src/Core/Application/CanonicalLessonScheduleService.php');
$continuation=file_get_contents($root.'/src/Core/Application/CanonicalContinuationService.php');
$continuationRule=file_get_contents($root.'/src/Core/Application/CanonicalContinuationRule.php');
$authorityRepo=file_get_contents($root.'/src/Core/Infrastructure/Repository/CommercialAuthorityRepository.php');
$paymentRepo=file_get_contents($root.'/src/Core/Infrastructure/Repository/CommercialPaymentRepository.php');
$capacityRepo=file_get_contents($root.'/src/Core/Infrastructure/Repository/CommercialCapacityRepository.php');
$phaseR1=$rule.$money.$validator.$lineage.$commitment.$commandShape.$offer.$payment.$funding.$capacity.$capacityAuthority.$pattern.$policy.$catalogue.$promotion.$adjustment.$exceptions.$read.$support.$idempotency.$authorityRepo.$paymentRepo.$capacityRepo;
$phaseR1Application=$rule.$money.$validator.$lineage.$commitment.$commandShape.$offer.$payment.$funding.$capacity.$capacityAuthority.$pattern.$policy.$catalogue.$promotion.$adjustment.$exceptions.$read.$support.$idempotency;

if(!preg_match("/DZN_PLATFORM_SCHEMA_VERSION', '([0-9]+)'/",$plugin,$schema)||(int)$schema[1]<25)throw new RuntimeException('Missing Phase R1 schema identity');
// The package build identity is monotonic: R1's identity must still be recognisable in the
// bootstrap while any additive descendant phase (for example R2 or S) may stamp a later one.
if(!preg_match("/DZN_PLATFORM_BUILD_ID', 'phase2a2[a-z0-9]+-[a-z0-9-]+-[0-9]{8}\.[0-9]+'/",$plugin))throw new RuntimeException('Missing Phase R1 build identity');

// Migration, storage, verifier wiring and capabilities.
foreach(array(
    '025_commercial_purchase_funding_authority','install_commercial_purchase_authority','verify_commercial_purchase_schema',
    'commercial_account_roots','commercial_policies','commercial_products','commercial_prices','commercial_promotions',
    'commercial_promotion_redemptions','commercial_account_adjustments','commercial_account_adjustment_events',
    'commercial_offers','commercial_offer_adjustments','commercial_offer_policies','commercial_offer_obligations',
    'commercial_purchases','commercial_entitlements','commercial_term_funding_plans','commercial_payment_evidence',
    'commercial_payment_facts','commercial_obligation_settlements','commercial_recurring_patterns',
    'commercial_capacity_claims','commercial_capacity_claim_intervals','commercial_exceptions','commercial_commands',
    'dzn_manage_commercial_catalogue','dzn_manage_commercial_promotions','dzn_manage_commercial_adjustments',
    'dzn_issue_commercial_offers','dzn_ingest_commercial_payment_evidence','dzn_bind_commercial_term_funding',
    'dzn_manage_commercial_capacity','dzn_manage_commercial_policies','dzn_manage_commercial_exceptions',
    'dzn_view_commercial_authority',
) as $needle) if(!str_contains($migration.$plugin,$needle))throw new RuntimeException('Missing Phase R1 migration contract: '.$needle);
if(!str_contains($migration,"if(\$id==='025_commercial_purchase_funding_authority')self::verify_commercial_purchase_schema();"))throw new RuntimeException('Migration 025 must invoke the Phase R1 schema verifier before it is recorded');
if(substr_count($migration,'self::verify_commercial_purchase_schema();')<3)throw new RuntimeException('Phase R1 verifier must run after migration 025, on current-schema verification and before schema activation');
if(!str_contains($migration,"in_array( '025_commercial_purchase_funding_authority', (array) get_option( self::COMPLETED, array() ), true )"))throw new RuntimeException('Retained-025 pre-activation verification is missing');
// The required-migration ledger must still name 025; a later additive phase appends its own entry,
// so the assertion locates the entry instead of requiring it to be the final element.
if(!preg_match("/'025_commercial_purchase_funding_authority'\s*[,)]/",$migration))throw new RuntimeException('Phase R1 migration must be listed as required');
$install=substr($migration,strpos($migration,'private static function install_commercial_purchase_authority'),strpos($migration,'private static function verify_commercial_purchase_schema')-strpos($migration,'private static function install_commercial_purchase_authority'));
if(str_contains($install,'UPDATE ')||str_contains($install,'INSERT INTO'))throw new RuntimeException('Phase R1 migration must be additive only: no backfill, no inference, no settlement import');
if(stripos($install,'stripe')!==false||stripos($install,'google')!==false||stripos($install,'provider_intent')!==false)throw new RuntimeException('Phase R1 storage must stay provider-neutral');
if(str_contains($migration,'CAPABILITY_OPTION_R1')===false||str_contains($migration,'dzn_platform_capability_version_2a2r1')===false)throw new RuntimeException('Phase R1 capability marker is missing');
if(!str_contains($migration,'$phaseR1Grants')||!str_contains($migration,'$teacherR1Role'))throw new RuntimeException('Phase R1 capability repair must independently ensure every grant and deny the Teacher role');

// Locked R1 rule constants.
foreach(array(
    "RULE_VERSION='commercial_purchase_v1'","PLAN_KINDS=array('full','two_instalments')",
    "INSTALMENT_TRANCHES=2","TRANCHE_ONE_FROM=1","TRANCHE_ONE_TO=6","TRANCHE_TWO_FROM=7","TRANCHE_TWO_TO=12",
    "OFFER_STATES=array('issued','accepted','expired','withdrawn')","ENTITLEMENT_STATES=array('issued','term_bound')",
    "CLAIM_STATES=array('active','released','expired')","CLAIM_INTERVAL_STATES=array('protected','satisfied','released')",
    "CLAIM_SOURCE_KINDS=array('q_succession','regular_pattern')","EVIDENCE_KINDS=array('attempt','success','failure','refund','mandate')",
    "ADJUSTMENT_SOURCE_TYPES=array('promotion','account_adjustment','credit')","IMPLEMENTED_ADJUSTMENT_SOURCES=array('promotion','account_adjustment')",
) as $constant) if(!str_contains($rule,$constant))throw new RuntimeException('Missing locked Phase R1 rule constant: '.$constant);
foreach(array('unmatched_payment_evidence','ambiguous_obligation_attribution','amount_mismatch','currency_mismatch','invalid_or_expired_offer','conflicting_payment_evidence','missing_instalment_predecessor','capacity_handoff_failed','duplicate_entitlement_binding','funding_term_mismatch','late_payment_after_offer_window','capacity_conflict_during_transition','refund_evidence_received') as $reason) if(!str_contains($rule,$reason))throw new RuntimeException('Missing controlled commercial exception reason: '.$reason);
if(!str_contains($rule,'instalment_prerequisite_unsettled'))throw new RuntimeException('The informational prerequisite-pending reconciliation signal is missing');
if(!str_contains($rule,'public static function exceptionReason')||!str_contains($exceptions,'CommercialRule::exceptionReason'))throw new RuntimeException('The controlled exception vocabulary must include the informational reconciliation signals');

// Structural invariants stay single-sourced and are NOT configurable policy values.
foreach(array('TERM_SESSION_COUNT','TERM_STUDENT_CHANGE_ALLOWANCE','TERM_CHANGE_ALLOWANCE') as $structural) if(str_contains($rule,$structural))throw new RuntimeException('A structural invariant must not be a configurable commercial policy: '.$structural);
foreach(array('INTRO_BOOKING_HORIZON','MANUAL_RENEWAL_SLOT_GUARANTEE_WEEKS','AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME','PAYMENT_RECOVERY_POLICY','INSTALMENT_DUE_DATE_POLICY') as $policyKey) if(!str_contains($rule,$policyKey))throw new RuntimeException('Missing class-B runtime commercial policy key: '.$policyKey);
if(!str_contains($policy,"in_array(\$policyKey,CommercialRule::POLICY_KEYS,true)"))throw new RuntimeException('The policy authority must reject non-configurable keys');
// Documentation traceability: the registry must document both policy classes without becoming configuration.
$registry=@file_get_contents($root.'/docs/COMMERCIAL-POLICY-REGISTRY.md');
if(!is_string($registry))throw new RuntimeException('docs/COMMERCIAL-POLICY-REGISTRY.md must exist');
foreach(array('TERM_SESSION_COUNT','TERM_STUDENT_CHANGE_ALLOWANCE','CAPACITY_SUCCESSION','INTRO_BOOKING_HORIZON','MANUAL_RENEWAL_SLOT_GUARANTEE_WEEKS','AUTOMATIC_RENEWAL_CHARGE_LEAD_TIME','PAYMENT_RECOVERY_POLICY','INSTALMENT_DUE_DATE_POLICY') as $documented) if(!str_contains($registry,$documented))throw new RuntimeException('The commercial policy registry must document: '.$documented);
if(!str_contains($registry,'structural invariant')||!str_contains($registry,'runtime configurable'))throw new RuntimeException('The commercial policy registry must classify structural invariants and runtime policies');
$phaseDoc=@file_get_contents($root.'/docs/PHASE-2A-2R1-COMMERCIAL-PURCHASE-FUNDING-CAPACITY-AUTHORITY.md');
if(!is_string($phaseDoc)||!str_contains($phaseDoc,'CAPACITY SUCCESSION')&&!str_contains($phaseDoc,'Phase-Q hold'))throw new RuntimeException('The Phase R1 document must record the capacity-succession contract');
if(!str_contains($termAuthority,'public const SESSION_ALLOCATION = 12;')||!str_contains($termAuthority,'public const REPLACEMENT_ALLOWANCE = 2;'))throw new RuntimeException('The canonical Term authority must own the session allocation and change allowance constants');
if(!str_contains($termAuthority,"'lesson_allocation'=>self::SESSION_ALLOCATION,'replacement_allowance'=>self::REPLACEMENT_ALLOWANCE"))throw new RuntimeException('Canonical Term creation must use the single-sourced structural constants');
if(!str_contains($lessonAuthority,'(int)$term->lesson_allocation'))throw new RuntimeException('Lesson issuance must read the recorded Term allocation');
if(!str_contains($lessonAuthority,'(int)$term->replacement_allowance'))throw new RuntimeException('Replacement issuance must read the recorded Term change allowance');
if(!str_contains($lessonAuthority,'CommercialTermFundingService::standardAllowance'))throw new RuntimeException('Lesson issuance must be guarded by the commercial funded allowance');
if(!str_contains($lessonAuthority,"'standard_funding_exhausted'"))throw new RuntimeException('Lesson issuance must fail closed with standard_funding_exhausted');
if(!str_contains($termAuthority,'assertTermClosable'))throw new RuntimeException('Term closure must consult protected commercial capacity');

// Provider neutrality and the exact-money invariant.
if(preg_match('/\b(floatval|doubleval|float|round|number_format)\s*\(/',$phaseR1))throw new RuntimeException('Commercial authority must not use floating-point money handling');
if(str_contains($money,'/ '))throw new RuntimeException('Commercial money arithmetic must be integer-only');
foreach(array('stripe','Stripe','wp_remote_','wp_mail','curl_','webhook') as $forbidden) if(str_contains($phaseR1,$forbidden))throw new RuntimeException('Phase R1 must not acquire this authority: '.$forbidden);
foreach(array('INSERT INTO','UPDATE ','DELETE FROM') as $sql) if(str_contains($phaseR1Application,$sql))throw new RuntimeException('Phase R1 application services must not write storage with raw SQL: '.$sql);
foreach(array('cron','wp_schedule_event','wp_schedule_single_event') as $forbidden) if(str_contains($phaseR1,$forbidden))throw new RuntimeException('Phase R1 must not become time-authoritative through scheduling: '.$forbidden);

// The success invariant chain: offer → evidence → settlement → effectiveness → binding → guard.
if(!str_contains($payment,"array('issued','accepted')")||!str_contains($payment,'late_payment_after_offer_window'))throw new RuntimeException('Payment acceptance must honour the offer window and its explicit late-payment reason');
if(!str_contains($payment,'conflicting_payment_evidence'))throw new RuntimeException('A second settlement attempt must fail closed');
if(!str_contains($payment,'CommercialLineageValidator::assertForOffer'))throw new RuntimeException('Payment acceptance must prove the authoritative commercial aggregate');
if(!str_contains($payment,'purchaseByOffer'))throw new RuntimeException('One purchase per offer must be enforced through the canonical lookup');
if(!str_contains($funding,'$isEffective=$settled&&!$blocked;'))throw new RuntimeException('Academic effectiveness must require every lower-sequence obligation to be settled');
if(!str_contains($funding,"min((int)\$plan['committed_sessions'],\$this->effectiveSessions((int)\$plan['offer_id']))"))throw new RuntimeException('The Term allowance must be derived and bounded by the commitment');

// Correction Round 1 — accepted-purchase benefit consumption is the only consumption boundary.
if(!str_contains($payment,'private function consumeBenefits'))throw new RuntimeException('Purchase acceptance must consume the accepted offer benefits');
if(!str_contains($payment,'$this->consumeBenefits($offer,(string)$occurredAt,$now,$actor);'))throw new RuntimeException('Benefit consumption must run inside accepted purchase convergence');
if(!str_contains($payment,'insertRedemption')||!str_contains($payment,'updateAdjustmentState'))throw new RuntimeException('The payment authority must consume the promotion redemption and the account adjustment');
if(!str_contains($payment,'promotion_usage_limit_reached')||!str_contains($payment,'promotion_beneficiary_limit_reached')||!str_contains($payment,'commercial_adjustment_already_consumed'))throw new RuntimeException('Benefit consumption must enforce both promotion limits and single adjustment consumption');
foreach(array('insertRedemption','updateAdjustmentState') as $forbidden) if(str_contains($offer,$forbidden))throw new RuntimeException('Offer issuance must never consume a commercial benefit: '.$forbidden);
if(!str_contains($payment,'CommercialValidator::evidenceAttributionMatches'))throw new RuntimeException('Conflicting evidence replay must distinguish attribution conflicts');

// Correction Round 1 — exact protected interval ↔ Phase-N occupancy identity.
if(!str_contains($validator,'public static function intervalMatchesSchedule'))throw new RuntimeException('Exact interval identity matching is missing');
foreach(array('starts_at_utc','ends_at_utc','occupied_ends_at_utc','schedule_timezone','local_wall_date','local_wall_time','teacher_id') as $field) if(!str_contains($validator,$field))throw new RuntimeException('Exact interval identity must include '.$field);
foreach(array('protected_interval_mismatch','protected_interval_missing','protected_interval_teacher_mismatch') as $reason) if(!str_contains($capacityAuthority,$reason))throw new RuntimeException('Exact interval identity must fail closed with '.$reason);
if(!str_contains($capacityAuthority,'$repository->claimsForTerm($termId,true)')||!str_contains($capacityAuthority,'$repository->intervals((int)$claim->id,true)'))throw new RuntimeException('The authorising interval must be locked inside the scheduling transaction');
if(!str_contains($schedules,'CommercialCapacityAuthority::assertScheduleAllowed($lesson,$schedule??array())'))throw new RuntimeException('Phase N must authorise against the exact schedule identity');
if(!str_contains($schedules,'CommercialCapacityAuthority::satisfyForLesson($lesson,(int)$versionId,$schedule??array(),$actor,$now)'))throw new RuntimeException('Phase N must satisfy using the exact schedule identity');

// Correction Round 1 — historical capacity rows are never blockers, and only the exact interval is excluded.
if(!str_contains($capacityAuthority,"if((string)\$claim->state!=='active')continue;"))throw new RuntimeException('A historical claim must not block arbitration');
if(!str_contains($capacityAuthority,"if((string)\$interval->state!=='protected')continue;"))throw new RuntimeException('A satisfied or released interval must not block arbitration');
if(!str_contains($capacityRepo,'WHERE teacher_id=%d AND id<>%d'))throw new RuntimeException('Conflict arbitration must exclude only the exact authorising interval');
if(str_contains($capacityRepo,'claim_id<>%d'))throw new RuntimeException('Conflict arbitration must never exclude a whole commercial claim');

// Correction Round 1 — Course identity continuity across every represented authority.
foreach(array($offer,$pattern,$funding) as $source) if(!str_contains($source,'commercial_course_continuity_conflict'))throw new RuntimeException('Course identity continuity must be enforced');
if(!str_contains($validator,'public static function courseConsistent'))throw new RuntimeException('The shared Course continuity check is missing');

// Correction Round 1 — claim release participates in the canonical Teacher serialisation.
if(substr_count($capacity,'ensureAndLockTeacherRoot')<2)throw new RuntimeException('Claim release must acquire the canonical Teacher scheduling root');
if(!str_contains($capacity,'Releasing protected capacity is a capacity mutation'))throw new RuntimeException('Claim release must document its serialisation boundary');

// Correction Round 1 — exact provider-evidence fact identity.
if(!str_contains($idempotency,'public static function providerFact'))throw new RuntimeException('The canonical provider-evidence fact digest is missing');
if(!str_contains($migration,'evidence_fact_digest'))throw new RuntimeException('Payment evidence must durably store its fact identity');
if(substr_count($payment,'evidence_fact_digest')<3)throw new RuntimeException('Every evidence write must record its fact identity');

// Correction Round 1 — durable structural integrity proof (repository policy avoids foreign keys).
if(!str_contains($validator,'public static function policyValid'))throw new RuntimeException('Runtime policy validation is missing');
if(!str_contains($policy,"if(!CommercialValidator::policyValid(\$row))throw new \InvalidArgumentException('commercial_policy_integrity_conflict');"))throw new RuntimeException('A stored non-class-B policy must fail the read closed');
if(!str_contains($funding,'commercial_funding_integrity_conflict'))throw new RuntimeException('The funding ownership chain must fail closed');
if(!str_contains($lineage,"if((int)\$case->student_id!==\$studentId||(int)\$case->teacher_id!==\$teacherId)"))throw new RuntimeException('Offer ownership must be validated against its continuation case');
if(!str_contains($funding,'CommercialValidator::entitlementValid'))throw new RuntimeException('Term binding must validate the entitlement aggregate');
if(!str_contains($funding,'commercial_capacity_handoff_required'))throw new RuntimeException('A Term must not be bound before its successor capacity is durable');
if(!str_contains($funding,'create(')||!str_contains($funding,'self::CAPABILITY'))throw new RuntimeException('Term binding must use the existing canonical Term authority');
foreach(array('insertTerm','insertLesson','insertVersion') as $forbidden) if(str_contains($phaseR1,$forbidden))throw new RuntimeException('Phase R1 must not become a parallel academic writer: '.$forbidden);

// Capacity succession: Q hold → R1 claim → N schedule, all under the same Teacher root.
if(!str_contains($capacity,'ensureAndLockTeacherRoot'))throw new RuntimeException('Protected capacity must serialize on the canonical per-Teacher scheduling root');
if(!str_contains($capacity,'setReservationState'))throw new RuntimeException('The predecessor hold must be released through the existing version-checked Phase-Q seam');
if(!str_contains($capacity,"'released'"))throw new RuntimeException('The predecessor hold must be released only after the successor claim is durable');
if(!str_contains($capacityAuthority,'assertNoConflictingClaim')||!str_contains($capacityAuthority,'satisfyForLesson')||!str_contains($capacityAuthority,'reopenForLesson'))throw new RuntimeException('The protected-capacity arbitration seam is incomplete');
if(!str_contains($schedules,'CommercialCapacityAuthority::assertScheduleAllowed'))throw new RuntimeException('Phase N must consult protected capacity');
if(!str_contains($schedules,'CommercialCapacityAuthority::satisfyForLesson'))throw new RuntimeException('Phase N must satisfy the protected interval atomically with its schedule');
if(!str_contains($schedules,'CommercialCapacityAuthority::reopenForLesson'))throw new RuntimeException('Phase N must restore protection when a canonical schedule is released');
if(!str_contains($continuation,'CommercialCapacityAuthority::assertNoConflictingClaim'))throw new RuntimeException('Phase Q must consult protected capacity before holding a slot');
if(!str_contains($continuationRule,"RESERVATION_STATES=array('active','expired','released')"))throw new RuntimeException('Phase Q reservation vocabulary must not be extended by Phase R1');
if(str_contains($continuationRule,'+7 days')||str_contains($pattern,'+7 days'))throw new RuntimeException('Neither Phase Q nor Phase R1 may infer a future paid slot from the introduction date');
if(!str_contains($pattern,'CanonicalContinuationRule::resolveWallClock'))throw new RuntimeException('Recurring occurrences must reuse the authoritative wall-clock resolver');
if(!str_contains($pattern,'continuation_slot_duration_mismatch'))throw new RuntimeException('The pattern anchor must fail closed when it does not match the authorised slot');
if(!str_contains($capacity,"'q_succession'"))throw new RuntimeException('A Flexible commitment must claim only the explicitly authorised interval');

// Idempotency and durable evidence.
foreach(array('command_key_digest','command_payload_digest') as $column) if(!str_contains($migration,$column)||!str_contains($phaseR1Application,$column))throw new RuntimeException('Commercial command evidence must be digest-only: '.$column);
if(!str_contains($paymentRepo,'provider_reference'))throw new RuntimeException('Payment evidence must be deduplicated by provider reference');
if(!str_contains($payment,'evidenceByProviderReference'))throw new RuntimeException('Duplicate provider evidence must converge on the recorded outcome');
if(!str_contains($idempotency,'hash_hmac')||!str_contains($idempotency,'wp_salt'))throw new RuntimeException('Commercial digests must be keyed');
if(!str_contains($capacityRepo,'protected')||!str_contains($capacityRepo,"state='protected'"))throw new RuntimeException('Protected interval arbitration storage is missing');

// Commercial failures never silently drop verified financial evidence.
if(!str_contains($exceptions,'recordAfterFailure')||!str_contains($exceptions,'openException')||!str_contains($migration,'fingerprint_state'))throw new RuntimeException('Commercial exceptions must be durable and deduplicated');
if(!str_contains($capacity,'recordHandoffFailure'))throw new RuntimeException('A paid commitment whose capacity cannot converge must be preserved and routed');

// Correction Round 2 — one canonical, transaction-aware offer-lineage validator at every owning boundary.
if(!str_contains($lineage,'final class CommercialLineageValidator'))throw new RuntimeException('The canonical commercial lineage validator is missing');
if(!str_contains($lineage,'public static function assertOfferAggregate')||!str_contains($lineage,'public static function assertForOffer'))throw new RuntimeException('The canonical lineage validator must expose both the id and hydrated-row entry points');
foreach(array('caseById','slotAuthorityForIntro','slotAuthorityById','reservationForCase','product(','price(','canonicalEnrolmentFor','StudentRepository','TeacherRepository') as $proved) if(!str_contains($lineage,$proved))throw new RuntimeException('The canonical lineage chain must prove: '.$proved);
if(substr_count($lineage,'commercial_course_continuity_conflict')<1||substr_count($lineage,'$courseConflict')<5)throw new RuntimeException('Course continuity must be proved at every aggregate layer');
if(str_contains($lineage,'CommercialValidator::courseConsistent'))throw new RuntimeException('The lineage validator must not fall back to the previous partial Course check');
if(str_contains($lineage,'INSERT INTO')||str_contains($lineage,'UPDATE ')||str_contains($lineage,'DELETE FROM'))throw new RuntimeException('The lineage validator must never mutate storage');
// Every owning mutation authority invokes the one validator, and the read seam reuses it.
if(!str_contains($payment,'CommercialLineageValidator::assertForOffer'))throw new RuntimeException('Payment acceptance must prove the aggregate at its owning boundary');
if(!str_contains($capacity,'CommercialCommitmentValidator::assertForEntitlement'))throw new RuntimeException('Capacity handoff must prove the commitment before any capacity mutation');
if(!str_contains($funding,'CommercialCommitmentValidator::assertForEntitlement'))throw new RuntimeException('Term binding must prove the commitment before Term creation');
if(!str_contains($offer,'CommercialLineageValidator::assertOfferAggregate'))throw new RuntimeException('The authoritative offer read must reuse the one canonical validator');
if(str_contains($capacityAuthority,'CommercialLineageValidator'))throw new RuntimeException('Protected-interval arbitration must not acquire a second lineage variant');
// The aggregate proof must run before the capacity mutation and keep the repository lock order.
$handoff=substr($capacity,strpos($capacity,'public function handoffFromEntitlement'));
$handoff=substr($handoff,0,strpos($handoff,'public function releaseClaim'));
if(strpos($handoff,'CommercialCommitmentValidator::assertForEntitlement')>strpos($handoff,'ensureAndLockTeacherRoot'))throw new RuntimeException('The aggregate proof must precede the Teacher scheduling root');
if(strpos($handoff,'CommercialCommitmentValidator::assertForEntitlement')>strpos($handoff,'insertClaim'))throw new RuntimeException('The aggregate proof must precede the successor claim');
if(strpos($handoff,'insertClaim')>strpos($handoff,'setReservationState'))throw new RuntimeException('The predecessor hold must still be released only after the successor claim is durable');
if(!str_contains($authorityRepo,'no code path may acquire it after an Enrolment-chain or Teacher-root lock'))throw new RuntimeException('The commercial account-root lock contract must stay documented');

// Correction Round 2 — account-adjustment source ↔ immutable snapshot correspondence.
if(!str_contains($validator,'public static function adjustmentSnapshotDigest'))throw new RuntimeException('The immutable snapshot digest must be single-sourced');
if(!str_contains($offer,'CommercialValidator::adjustmentSnapshotDigest')||str_contains($offer,'CommercialIdempotency::payload(array(\'source_type\''))throw new RuntimeException('Offer issuance must derive its snapshot digest through the canonical validator');
if(!str_contains($validator,'public static function adjustmentSnapshotFor')||!str_contains($validator,'public static function accountAdjustmentSnapshotMatches')||!str_contains($validator,'public static function promotionSnapshotMatches'))throw new RuntimeException('The pricing-snapshot correspondence checks are missing');
if(!str_contains($validator,'public static function recomputedAdjustmentAmount'))throw new RuntimeException('The adjustment must be recomputed against the running amount');
foreach(array('source_id','percentage_bp','amount_minor','applied_amount_minor','application_order','currency','snapshot_digest') as $field) if(!str_contains($validator,$field))throw new RuntimeException('The snapshot correspondence must compare '.$field);
if(!str_contains($lineage,'$running=(int)$offer->base_amount_minor-(int)($offer->promotion_amount_minor??0)'))throw new RuntimeException('The adjustment must be recomputed against the POST-promotion running amount');
if(!str_contains($lineage,'commercial_adjustment_snapshot_conflict'))throw new RuntimeException('A source/snapshot mismatch must fail closed with its own controlled reason');
if(!str_contains($lineage,'assertAdjustmentSources($offer,$lock,$authority)'))throw new RuntimeException('The aggregate validator must prove the immutable pricing pipeline');
if(!str_contains($payment,'commercial_offer_adjustments snapshot'))throw new RuntimeException('Payment acceptance must document the proven snapshot correspondence');

// Correction Round 2 — one canonical duplicate-evidence recovery boundary.
if(!str_contains($payment,'private function convergeExistingEvidence'))throw new RuntimeException('The canonical duplicate-evidence recovery boundary is missing');
if(substr_count($payment,'convergeExistingEvidence(')<4)throw new RuntimeException('Every duplicate-evidence path must reuse the canonical recovery boundary');
if(!str_contains($payment,'hash_equals((string)$recorded->evidence_fact_digest,$factDigest)'))throw new RuntimeException('The recovery boundary must compare the recorded immutable fact digest');
if(!str_contains($payment,'CommercialValidator::evidenceAttributionMatches'))throw new RuntimeException('The recovery boundary must preserve attribution conflict detection');
if(!str_contains($payment,'commercial_evidence_required'))throw new RuntimeException('A corrupted recorded evidence row must fail the recovery closed');
if(!str_contains($payment,'dzn_phase_2a2r1_after_unattributed_evidence_insert'))throw new RuntimeException('The initially-unattributed intake must expose its durable write boundary');
if(!str_contains($payment,"'conflicting_payment_evidence'"))throw new RuntimeException('A conflicting unattributed fact must be routed durably');

// Correction Round 2 — behavioural and concurrency evidence for the corrected invariants.
$corruptionRuntime=@file_get_contents($root.'/tests/phase-2a2r1-corruption-runtime.php');
foreach(array('commercial_adjustment_snapshot_conflict','acceptance against an adjustment source mutated after its snapshot','acceptance against a rewritten immutable adjustment snapshot','payment acceptance over a stored product/Course mismatch','capacity handoff over a stored product/Course mismatch','Term binding over a stored product/Course mismatch') as $proof) if(!is_string($corruptionRuntime)||!str_contains($corruptionRuntime,$proof))throw new RuntimeException('Correction Round 2 corruption proof missing: '.$proof);
$setup=@file_get_contents($root.'/tests/phase-2a2r1-concurrency-setup.php');
$worker=@file_get_contents($root.'/tests/phase-2a2r1-concurrency-worker.php');
$verify=@file_get_contents($root.'/tests/phase-2a2r1-concurrency-verify.php');
foreach(array($setup,$worker,$verify) as $source) if(!is_string($source)||!str_contains($source,'unattributed_conflict'))throw new RuntimeException('The initially-unattributed conflicting-facts concurrency mode is missing');
if(!str_contains($worker,'dzn_phase_2a2r1_after_unattributed_evidence_insert'))throw new RuntimeException('The unattributed concurrency holder must be gated inside its own transaction');
if(!str_contains($verify,'the winning evidence row must remain unchanged')||!str_contains($verify,'the conflict must be durably routed into controlled commercial review'))throw new RuntimeException('The unattributed concurrency verifier must assert the durable outcome');
if(!is_string($setup)||!str_contains($setup,'unattributed_convergence'))throw new RuntimeException('The identical-facts idempotent convergence mode is missing');
$failureRuntime=@file_get_contents($root.'/tests/phase-2a2r1-failure-runtime.php');
if(!is_string($failureRuntime)||!str_contains($failureRuntime,'dzn_phase_2a2r1_after_unattributed_evidence_insert'))throw new RuntimeException('The unattributed intake rollback boundary is not proven');

// Correction Round 2 — the documented testing/concurrency matrix must match what is executed.
foreach(array('unattributed_conflict','duplicate_evidence','handoff_vs_schedule','settlement_vs_lesson_seven','unrelated_commitments','promotion_global_limit','conflicting_evidence_replay','release_vs_satisfaction') as $mode) if(!str_contains($phaseDoc,$mode))throw new RuntimeException('The Phase R1 document must record the executed concurrency mode: '.$mode);
if(!str_contains($phaseDoc,'Correction round 2'))throw new RuntimeException('The Phase R1 document must record correction round 2');
$continuity=@file_get_contents($root.'/docs/DELNAVAZAN-CORE-CONTINUITY.md');
if(!is_string($continuity)||!str_contains($continuity,'Correction round 2'))throw new RuntimeException('The continuity record must record correction round 2');

// Correction Round 3 — one canonical commitment validator: entitlement → purchase → offer → lineage.
if(!str_contains($commitment,'final class CommercialCommitmentValidator'))throw new RuntimeException('The canonical commercial commitment validator is missing');
if(!str_contains($commitment,'public static function assertForEntitlement')||!str_contains($commitment,'public static function assertCommitment')||!str_contains($commitment,'public static function assertClaimBelongsToCommitment'))throw new RuntimeException('The commitment validator must expose both entry points and the claim-ownership proof');
if(str_contains($commitment,'CommercialValidator::courseConsistent')&&!str_contains($commitment,'commercial_course_continuity_conflict'))throw new RuntimeException('The commitment validator must fail closed on course continuity');
// The complete persisted commitment surface is proved, not merely the identity chain.
foreach(array(
    'entitlement->purchase_id','entitlement->beneficiary_student_id','entitlement->session_count',
    'purchase->offer_id','purchase->beneficiary_student_id','purchase->product_id','purchase->currency',
    'purchase->amount_minor','purchase->plan_kind','purchase->reconciliation_state','purchase->purchase_version',
    'offer->committed_sessions','offer->amount_due_minor','offer->plan_kind','purchaseByOffer','first_evidence_id',
) as $proved) if(!str_contains($commitment,$proved))throw new RuntimeException('The commitment validator must prove: '.$proved);
if(!str_contains($commitment,'CommercialValidator::entitlementValid')||!str_contains($commitment,'CommercialValidator::evidenceValid'))throw new RuntimeException('The commitment validator must reuse the canonical entitlement/evidence validity');
// The creating boundary must persist exactly the accepted-offer snapshot the validator proves.
foreach(array(
    "'offer_id'=>(int)\$offer->id","'beneficiary_student_id'=>(int)\$offer->beneficiary_student_id",
    "'product_id'=>(int)\$offer->product_id","'currency'=>(string)\$offer->currency",
    "'amount_minor'=>(int)\$offer->amount_due_minor","'plan_kind'=>(string)\$offer->plan_kind",
) as $written) if(!str_contains($payment,$written))throw new RuntimeException('Purchase creation must persist the accepted offer snapshot the commitment validator proves: '.$written);
foreach(array(
    "'purchase_id'=>\$purchaseId","'beneficiary_student_id'=>(int)\$offer->beneficiary_student_id",
    "'session_count'=>(int)\$offer->committed_sessions",
) as $written) if(!str_contains($payment,$written))throw new RuntimeException('Entitlement creation must persist the accepted commitment the commitment validator proves: '.$written);
if(!str_contains($commitment,'in_array((string)$entitlement->state,$allowedEntitlementStates,true)'))throw new RuntimeException('The entitlement state must be validated against the requested mutation');
if(!str_contains($commitment,'STATES_PRE_CAPACITY')||!str_contains($commitment,'STATES_PRE_TERM'))throw new RuntimeException('Mutation-specific entitlement states must be explicit');
// It delegates the upstream aggregate proof instead of duplicating it, and never mutates storage.
if(!str_contains($commitment,'CommercialLineageValidator::assertForOffer'))throw new RuntimeException('The commitment validator must delegate the upstream offer lineage proof');
foreach(array('INSERT INTO','UPDATE ','DELETE FROM') as $sql) if(str_contains($commitment,$sql))throw new RuntimeException('The commitment validator must never write storage: '.$sql);
// Both downstream mutation owners consume the one implementation, not their own comparisons.
if(substr_count($capacity,'CommercialCommitmentValidator::assertForEntitlement')!==1||substr_count($funding,'CommercialCommitmentValidator::assertForEntitlement')!==1)throw new RuntimeException('Each downstream mutation owner must prove the commitment exactly once');
// Once at the mutation boundary and once in the replay revalidation: exactly two canonical claim proofs.
if(substr_count($capacity,'CommercialCommitmentValidator::assertClaimAggregateBelongsToCommitment')!==2||substr_count($funding,'CommercialCommitmentValidator::assertClaimAggregateBelongsToCommitment')!==2)throw new RuntimeException('Each downstream mutation owner must prove its existing claim aggregate at its boundary and on replay');
if(substr_count($commitment,'commercial_commitment_integrity_conflict')<1||substr_count($commitment,'$conflict')<8)throw new RuntimeException('The commitment ownership comparisons must fail closed with one controlled reason');
if(str_contains($capacity.' '.$funding,'amount_due_minor'))throw new RuntimeException('Accepted-amount ownership must not be re-implemented outside the canonical commitment validator');
if(str_contains($capacity.' '.$funding,'purchaseByOffer'))throw new RuntimeException('Purchase/offer ownership must not be re-implemented outside the canonical commitment validator');
// Ordering: commitment proof precedes every downstream mutation, including the Phase-Q release.
if(strpos($handoff,'CommercialCommitmentValidator::assertClaimAggregateBelongsToCommitment')===false)throw new RuntimeException('The idempotent existing-claim path must prove the complete claim aggregate');
if(strpos($handoff,'CommercialCommitmentValidator::assertForEntitlement')>strpos($handoff,'$existing=$this->capacity->claimForEntitlement'))throw new RuntimeException('The commitment proof must precede the existing-claim decision');
if(strpos($handoff,'ensureAndLockTeacherRoot')>strpos($handoff,'insertClaim'))throw new RuntimeException('The Teacher root must still precede the successor claim');
if(strpos($handoff,'insertClaim')>strpos($handoff,'setReservationState'))throw new RuntimeException('The predecessor hold must still be released only after the successor claim is durable');
$binding=substr($funding,strpos($funding,'public function bindEntitlementToTerm'));
$binding=substr($binding,0,strpos($binding,'public function fundingPlanForTerm'));
if(strpos($binding,'CommercialCommitmentValidator::assertForEntitlement')>strpos($binding,'insertFundingPlan'))throw new RuntimeException('The commitment proof must precede the funding plan');
if(strpos($binding,'CommercialCommitmentValidator::assertForEntitlement')>strpos($binding,'CanonicalTermAuthorityService())->create('))throw new RuntimeException('The commitment proof must precede Phase-L Term creation');
if(strpos($binding,'CommercialCommitmentValidator::assertClaimAggregateBelongsToCommitment')>strpos($binding,'insertFundingPlan'))throw new RuntimeException('Claim ownership must be proved before the funding plan');
// C2 preservation: the upstream validator stays untouched and still owns the read/payment seams.
if(!str_contains($offer,'CommercialLineageValidator::assertOfferAggregate'))throw new RuntimeException('The offer read seam must still reuse the upstream lineage validator');
if(!str_contains($payment,'CommercialLineageValidator::assertForOffer'))throw new RuntimeException('Payment acceptance must still prove the upstream aggregate');
if(str_contains($commitment,'public static function adjustmentSnapshotFor'))throw new RuntimeException('The commitment validator must not duplicate upstream pricing-snapshot semantics');

// Correction Round 3 — behavioural corruption coverage for the commitment layer.
foreach(array(
    'purchase.offer_id-alternate-valid-offer','purchase.beneficiary','purchase.product','purchase.currency',
    'purchase.amount','purchase.plan','entitlement.beneficiary','entitlement.session_count',
    'claim.purchase','claim.student','claim.committed_sessions',
) as $probe) if(!str_contains($corruptionRuntime,$probe))throw new RuntimeException('The commitment corruption matrix is missing: '.$probe);
foreach(array('entitlementPurchaseProbe','purchase.offer_id-alternate-valid-offer-with-claim','$assertOfferG2Valid') as $probe) if(!str_contains($corruptionRuntime,$probe))throw new RuntimeException('The commitment corruption matrix is missing: '.$probe);
if(!str_contains($corruptionRuntime,'commercial_commitment_integrity_conflict')||!str_contains($corruptionRuntime,'commercial_capacity_integrity_conflict'))throw new RuntimeException('The commitment corruption matrix must assert both controlled reasons');
if(!str_contains($corruptionRuntime,'must never silently repair')||!str_contains($corruptionRuntime,'must be restorable'))throw new RuntimeException('The commitment corruption matrix must prove no silent repair and exact restoration');
if(!str_contains($phaseDoc,'Correction round 3'))throw new RuntimeException('The Phase R1 document must record correction round 3');
if(!str_contains($continuity,'Correction round 3'))throw new RuntimeException('The continuity record must record correction round 3');

// Correction Round 4 — the exact acceptance-fact chain, the existing-claim aggregate and replay integrity.
foreach(array(
    'factForEvidence','settlementForObligation','CommercialValidator::settlementValid','evidence->evidence_kind',
    'provider_occurred_at','purchase->accepted_at','first_evidence_id','settlement->evidence_id','fact->purchase_id','fact->occurred_at',
) as $proved) if(!str_contains($commitment,$proved))throw new RuntimeException('The commitment validator must prove the acceptance-fact chain: '.$proved);
if(!str_contains($commitment,"!=='success'"))throw new RuntimeException('Only a successful evidence fact may mint an accepted purchase');
if(str_contains($commitment,"!=='refund'")&&!str_contains($commitment,'evidence_kind'))throw new RuntimeException('The acceptance-fact chain must stay provider-neutral and kind-explicit');
if(!str_contains($commitment,'public static function assertClaimAggregateBelongsToCommitment'))throw new RuntimeException('The complete claim-aggregate proof is missing');
if(!str_contains($commitment,'$requiredStates')||!str_contains($commitment,'CommercialValidator::claimValid($claim,$intervals)'))throw new RuntimeException('The claim aggregate must be state-aware and validated through the canonical claim validator');
if(!str_contains($commitment,'if($claim->predecessor_reservation_id===null)'))throw new RuntimeException('An R1 successor claim must always name its mandatory predecessor hold');
if(!str_contains($commitment,'if($sourceKind===\'regular_pattern\'')||!str_contains($commitment,'if($sourceKind===\'q_succession\''))throw new RuntimeException('The claim source/pattern identity must be coherent');
foreach(array('assertClaimAggregateBelongsToCommitment','claimValid($claim,$intervals)','interval_count') as $proved) if(!str_contains($commitment,$proved))throw new RuntimeException('The claim aggregate must prove: '.$proved);
// Replay may only report success after the current stored aggregate is re-proved, inside a transaction.
foreach(array($capacity,$funding) as $service){
    if(str_contains($service,'return $this->replay($winner,$payload);')||str_contains($service,'return $this->replayBinding($winner,$payload);'))throw new RuntimeException('A duplicate-command replay must not run as loose autocommit reads');
}
if(!str_contains($capacity,'private function replayAfterRollback')||!str_contains($funding,'private function replayBindingAfterRollback'))throw new RuntimeException('The rolled-back duplicate-command replay must be re-run inside its own transaction');
$capacityReplay=substr($capacity,strpos($capacity,'private function replay(object $command'));
if(!str_contains($capacityReplay,'CommercialCommitmentValidator::assertCommitment')||!str_contains($capacityReplay,'CommercialCommitmentValidator::assertClaimAggregateBelongsToCommitment'))throw new RuntimeException('The capacity replay must re-prove the commitment and the claim aggregate');
if(strpos($capacityReplay,'CommercialCommitmentValidator::assertCommitment')>strpos($capacityReplay,'return $this->claimResult'))throw new RuntimeException('The capacity replay must re-prove the aggregate before reporting success');
if(!str_contains($capacityReplay,"array('released')")||!str_contains($capacityReplay,"array('active')"))throw new RuntimeException('The capacity replay must bind the claim state to the exact recorded operation outcome');
$fundingReplay=substr($funding,strpos($funding,'private function replayBinding(object'));
foreach(array('CommercialCommitmentValidator::assertCommitment','assertClaimAggregateBelongsToCommitment','fundingPlanForTerm','CanonicalTermAuthorityRepository','term_id','enrolment_id') as $proved) if(!str_contains($fundingReplay,$proved))throw new RuntimeException('The binding replay must re-prove: '.$proved);
if(strpos($fundingReplay,'CommercialCommitmentValidator::assertCommitment')>strpos($fundingReplay,'return array('))throw new RuntimeException('The binding replay must re-prove the aggregate before reporting success');
// Correction Round 4 — behavioural coverage for all three findings.
foreach(array(
    'evidence.kind-non-success','evidence.amount','evidence.currency','evidence.occurrence','settlement.amount','settlement.evidence',
    'fact.purchase','fact.obligation','fact.amount','fact.occurrence','purchase.accepted_at-mismatch',
    'predecessor-null','predecessor-mismatch','state-released','state-expired','claim-version','pattern-identity','interval-count','interval-state',
    'replayProbe','release replay over a corrupt claim aggregate',
) as $probe) if(!str_contains($corruptionRuntime,$probe))throw new RuntimeException('The correction-round-4 corruption matrix is missing: '.$probe);
if(!str_contains($corruptionRuntime,'an unchanged successful handoff must replay idempotently')||!str_contains($corruptionRuntime,'an unchanged successful binding must replay idempotently'))throw new RuntimeException('The correction-round-4 matrix must prove positive idempotent replay');
if(!str_contains($phaseDoc,'Correction round 4'))throw new RuntimeException('The Phase R1 document must record correction round 4');
if(!str_contains($continuity,'Correction round 4'))throw new RuntimeException('The continuity record must record correction round 4');

// Correction Round 5 — settlement/fact timeline, release-replay lifecycle and full command results.
foreach(array(
    'ingested_at','settlement->settled_at','settlement->currency','fact->currency','fact->recorded_at','obligation->currency',
) as $proved) if(!str_contains($commitment,$proved))throw new RuntimeException('The commitment validator must prove the settlement/payment-fact chain: '.$proved);
if(!str_contains($commitment,'(string)$settlement->settled_at!==(string)$evidence->ingested_at'))throw new RuntimeException('The settlement occurrence must be bound to the authoritative acceptance timeline');
// Release replay: exactly the released lifecycle, and the recorded release metadata.
$capacityReplayV=substr($capacity,strpos($capacity,'private function replay(object $command'));
if(!str_contains($capacityReplayV,'$releasing?array(\'released\'):array(\'active\')'))throw new RuntimeException('A recorded release must replay only against the exact released claim state');
foreach(array('CLAIM_RELEASE_REASONS','release_reason_code','released_at','protected','command->claim_id','CommercialCommandShape::assertShape') as $proved) if(!str_contains($capacityReplayV,$proved))throw new RuntimeException('The capacity replay must prove the release lifecycle/result field: '.$proved);
if(!str_contains($capacityReplayV,"CommercialCommandShape::RELEASE:CommercialCommandShape::ESTABLISH"))throw new RuntimeException('The capacity replay must use the operation-specific command shape');
if(strpos($capacityReplayV,'CommercialCommitmentValidator::assertCommitment')>strpos($capacityReplayV,'return $this->claimResult'))throw new RuntimeException('The capacity replay must re-prove the aggregate before reporting success');
// A release command records no offer identity of its own.
if(!str_contains($capacity,"'release_protected_capacity',(int)\$claim->student_id,(int)\$claim->teacher_id,\$claim->purchase_id===null?null:(int)\$claim->purchase_id,null,"))throw new RuntimeException('A release command must not record a borrowed offer identity');
// Term binding: the command records its claim, and replay proves every recorded result field.
if(!str_contains($funding,"'claim_id'=>(int)\$claim->id,'term_id'=>\$termId,'result_state'=>'term_bound','result_id'=>\$termId"))throw new RuntimeException('The binding command must record its claim and exact result identity');
foreach(array(
    'CommercialCommandShape::assertShape',"CommercialCommandShape::BIND,'term_bound'","'term_id'=>(int)\$plan->term_id","'claim_id'=>(int)\$claim->id",
) as $proved) if(!str_contains($fundingReplay,$proved))throw new RuntimeException('The binding replay must prove the complete operation shape: '.$proved);
// Correction Round 5 — behavioural coverage.
foreach(array(
    'release replay over an otherwise valid active aggregate','otherwise valid active claim aggregate','release replay over a corrupt claim aggregate',
    'settlement.settled_at','settlement.currency','fact.currency',
    "'binding.result_state'","'binding.result_id'","'binding.term_id'","'binding.entitlement_id'","'binding.purchase_id'","'binding.offer_id'","'binding.claim_id'",
    "'handoff.result_state'","'handoff.claim_id'","'handoff.offer_id'","'release.result_state'","'release.offer_id'","'release.claim_id'",
    'must never silently repair the command','must not duplicate the command row',
) as $probe) if(!str_contains($corruptionRuntime,$probe))throw new RuntimeException('The correction-round-5 corruption matrix is missing: '.$probe);
if(!str_contains($phaseDoc,'Correction round 5'))throw new RuntimeException('The Phase R1 document must record correction round 5');
if(!str_contains($continuity,'Correction round 5'))throw new RuntimeException('The continuity record must record correction round 5');

// Correction Round 6 — the complete operation-specific command shape.
if(!str_contains($commandShape,'final class CommercialCommandShape'))throw new RuntimeException('The canonical commercial command-shape validator is missing');
if(!str_contains($commandShape,"SELECTORS=array('student_id','teacher_id','offer_id','obligation_id','purchase_id','entitlement_id','claim_id','term_id')"))throw new RuntimeException('The command-shape validator must enumerate every nullable command selector');
if(!str_contains($commandShape,'public static function selectorsFor')||!str_contains($commandShape,'public static function assertShape'))throw new RuntimeException('The command-shape validator must expose its per-operation shape and its check');
foreach(array('self::ESTABLISH=>','self::RELEASE=>','self::BIND=>') as $operation) if(!str_contains($commandShape,$operation))throw new RuntimeException('The command-shape validator must declare each operation shape: '.$operation);
foreach(array(
    "(string)\$command->command_domain!==CommercialRule::DOMAIN","(string)\$command->operation!==\$operation",
    "(string)\$command->result_state!==\$resultState","(int)\$command->result_id!==\$resultId",
    "if(!array_key_exists(\$selector,\$selectors))throw","if(\$recorded!==null)throw",
    "CommercialValidator::utc((string)\$command->created_at)",
) as $proved) if(!str_contains($commandShape,$proved))throw new RuntimeException('The command-shape validator must prove: '.$proved);
if(substr_count($commandShape,'$reason')<8)throw new RuntimeException('Every command-shape violation must fail closed with the caller controlled reason');
foreach(array('INSERT INTO','UPDATE ','DELETE FROM') as $sql) if(str_contains($commandShape,$sql))throw new RuntimeException('The command-shape validator must never write storage: '.$sql);
if(!str_contains($commandShape,'must remain exactly NULL')&&!str_contains($commandShape,'must remain exactly NULL:'))throw new RuntimeException('The inapplicable-selector rule must be documented');
// Both replays use the one shape validator for their own operation.
if(!str_contains($capacity,'CommercialCommandShape::assertShape')||!str_contains($funding,'CommercialCommandShape::assertShape'))throw new RuntimeException('Every replay must prove the complete operation-specific command shape');
if(substr_count($capacity,"CommercialCommandShape::assertShape(")!==1||substr_count($funding,"CommercialCommandShape::assertShape(")!==1)throw new RuntimeException('Each replay must use exactly one canonical shape check');
if(str_contains($capacity,'$command->offer_id!==null')||str_contains($funding,'$command->teacher_id!==null'))throw new RuntimeException('No ad-hoc selector NULL check may remain outside the canonical shape validator');
// Correction Round 6 — behavioural coverage for the null-selector selectors.
foreach(array(
    "'handoff.obligation_id'","'handoff.term_id'","'release.obligation_id'","'release.term_id'","'binding.teacher_id'","'binding.obligation_id'",
    'must not carry a','must never silently repair the command','must not duplicate the command row',
) as $probe) if(!str_contains($corruptionRuntime,$probe))throw new RuntimeException('The correction-round-6 selector matrix is missing: '.$probe);
if(!str_contains($phaseDoc,'Correction round 6'))throw new RuntimeException('The Phase R1 document must record correction round 6');
if(!str_contains($continuity,'Correction round 6'))throw new RuntimeException('The continuity record must record correction round 6');

echo "Phase 2A.2-R1 contract passed\n";
