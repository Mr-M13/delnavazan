<?php
/** Phase 2A.2-T provider-neutral payment execution seam and Stripe adapter source contract. */
$root=dirname(__DIR__);
$plugin=file_get_contents($root.'/delnavazan-platform.php');
$migration=file_get_contents($root.'/src/Core/Infrastructure/Migration/Migrator.php');
$seam='';
foreach(glob($root.'/src/Core/Application/PaymentExecution/*.php') as $file)$seam.=file_get_contents($file);
$rule=file_get_contents($root.'/src/Core/Application/PaymentExecution/PaymentExecutionRule.php');
$support=file_get_contents($root.'/src/Core/Application/PaymentExecution/PaymentExecutionSupport.php');
$service=file_get_contents($root.'/src/Core/Application/PaymentExecution/PaymentExecutionService.php');
$intake=file_get_contents($root.'/src/Core/Application/PaymentExecution/PaymentEventIntakeService.php');
$seal=file_get_contents($root.'/src/Core/Application/PaymentExecution/PaymentExecutionDispatchSeal.php');
$vault=file_get_contents($root.'/src/Core/Application/PaymentExecution/PaymentSecretVault.php');
$accountService=file_get_contents($root.'/src/Core/Application/PaymentExecution/PaymentProviderAccountService.php');
$objectService=file_get_contents($root.'/src/Core/Application/PaymentExecution/PaymentProviderObjectService.php');
$worker=file_get_contents($root.'/src/Core/Application/PaymentExecution/PaymentExecutionWorkerContext.php');
$execRepository=file_get_contents($root.'/src/Core/Infrastructure/Repository/PaymentExecutionRepository.php');
$providerRepository=file_get_contents($root.'/src/Core/Infrastructure/Repository/PaymentProviderRepository.php');
$secretRepository=file_get_contents($root.'/src/Core/Infrastructure/Repository/PaymentSecretRepository.php');
$adapter=file_get_contents($root.'/src/Integrations/Payment/Stripe/StripePaymentAdapter.php');
$translator=file_get_contents($root.'/src/Integrations/Payment/Stripe/StripeEventTranslator.php');
$verifier=file_get_contents($root.'/src/Integrations/Payment/Stripe/StripeSignatureVerifier.php');
$controller=file_get_contents($root.'/src/Integrations/Payment/Stripe/StripeWebhookController.php');
$contractAdapter=file_get_contents($root.'/src/Integrations/Payment/ContractPaymentAdapter.php');
$runtime=file_get_contents($root.'/tests/phase-2a2t-runtime.php');
$migrationRuntime=file_get_contents($root.'/tests/phase-2a2t-migration-runtime.php');
$webhookRuntime=file_get_contents($root.'/tests/phase-2a2t-webhook-runtime.php');
$secretRuntime=file_get_contents($root.'/tests/phase-2a2t-secret-runtime.php');
$corruption=file_get_contents($root.'/tests/phase-2a2t-corruption-runtime.php');
$failure=file_get_contents($root.'/tests/phase-2a2t-failure-runtime.php');
$concurrency=file_get_contents($root.'/tests/phase-2a2t-concurrency-runner.sh')
    .file_get_contents($root.'/tests/phase-2a2t-concurrency-setup.php')
    .file_get_contents($root.'/tests/phase-2a2t-concurrency-worker.php')
    .file_get_contents($root.'/tests/phase-2a2t-concurrency-verify.php');
$tables=array('payment_provider_accounts','payment_provider_account_events','payment_provider_account_commands','payment_provider_objects','payment_provider_object_events','payment_provider_object_commands','payment_provider_secrets','payment_execution_commands','payment_execution_attempts','payment_execution_results','payment_execution_dispatches','payment_provider_event_receipts','payment_provider_events','payment_provider_event_decisions','payment_provider_event_decision_claims','payment_provider_secret_events');

// Build/schema identity: Phase T is Schema 29 (the fifteen-table seam of 028 plus [C10-1] the decision
// claim aggregate of 029), sequenced additively after the V candidate.
if(!preg_match("/DZN_PLATFORM_SCHEMA_VERSION', '([0-9]+)'/",$plugin,$schema)||(int)$schema[1]<29)throw new RuntimeException('Missing Phase T schema identity');
if(!preg_match("/DZN_PLATFORM_BUILD_ID', 'phase2a2t-[a-z0-9-]+-[0-9]{8}\.[0-9]+'/",$plugin))throw new RuntimeException('Missing Phase T build identity');

// Migration, storage, verifier wiring and capabilities.
foreach(array(
    '028_payment_execution_seam_provider_adapter','install_payment_execution_seam','verify_payment_execution_schema',
    '029_payment_event_decision_claim_authority','install_payment_event_decision_claim_authority','verify_payment_event_decision_claim_schema',
    'dzn_manage_payment_providers','dzn_manage_payment_execution','dzn_ingest_payment_provider_events',
    'dzn_view_payment_execution_authority','dzn_platform_capability_version_2a2t','$phaseTGrants','$teacherTRole',
) as $needle)if(!str_contains($migration.$plugin,$needle))throw new RuntimeException('Missing Phase T migration contract: '.$needle);
if(!str_contains($migration,"if(\$id==='028_payment_execution_seam_provider_adapter')self::verify_payment_execution_schema();"))throw new RuntimeException('Migration 028 must invoke the Phase T schema verifier before it is recorded');
if(substr_count($migration,'self::verify_payment_execution_schema();')<3)throw new RuntimeException('Phase T verifier must run after migration 028, on current-schema verification and before schema activation');
if(!str_contains($migration,"in_array( '028_payment_execution_seam_provider_adapter', (array) get_option( self::COMPLETED, array() ), true )"))throw new RuntimeException('Retained-028 pre-activation verification is missing');
if(!str_contains($migration,"'028_payment_execution_seam_provider_adapter', '029_payment_event_decision_claim_authority' )"))throw new RuntimeException('Phase T migration must be listed as required');
// [C10-1] The decision-claim aggregate is its own scheduled migration: it is installed, verified and
// required exactly like migration 028, so a database that completed 028 is repaired rather than failed.
if(!str_contains($migration,"if(\$id==='029_payment_event_decision_claim_authority')self::verify_payment_event_decision_claim_schema();"))throw new RuntimeException('[C10-1] Migration 029 must invoke its own verifier before it is recorded');
if(substr_count($migration,'self::verify_payment_event_decision_claim_schema();')<3)throw new RuntimeException('[C10-1] The claim verifier must run after migration 029, on current-schema verification and before schema activation');
if(!str_contains($migration,"in_array( '029_payment_event_decision_claim_authority', (array) get_option( self::COMPLETED, array() ), true )"))throw new RuntimeException('[C10-1] Retained-029 pre-activation verification is missing');
if(!str_contains($migration,"'029_payment_event_decision_claim_authority' )"))throw new RuntimeException('[C10-1] Migration 029 must be listed as required');
if(!str_contains($migration,"'029_payment_event_decision_claim_authority'=>array(__CLASS__,'install_payment_event_decision_claim_authority')"))throw new RuntimeException('[C10-1] Migration 029 must be scheduled in the migration ledger');
$installStart=strpos($migration,'private static function install_payment_execution_seam');
$installEnd=strpos($migration,'private static function verify_payment_execution_schema');
$install=substr($migration,$installStart,$installEnd-$installStart);
$install=substr($install,strpos($install,'{')); // body only: the signature names the phase, the storage must not
if(str_contains($install,'UPDATE ')||str_contains($install,'INSERT INTO')||str_contains($install,'ALTER TABLE'))throw new RuntimeException('Phase T migration must be additive only');
if(stripos($install,'stripe_')!==false)throw new RuntimeException('Phase T storage must stay provider-neutral');
preg_match_all('/CREATE TABLE \{\$p\}([a-z_]+)/',$install,$created);
$createdTables=$created[1];sort($createdTables);
$seamTables=array_values(array_diff($tables,array('payment_provider_event_decision_claims')));sort($seamTables);
if($createdTables!==$seamTables)throw new RuntimeException('Migration 028 must create exactly the fifteen declared Phase T seam tables');
if(count(array_unique($createdTables))!==15)throw new RuntimeException('Migration 028 must declare each Phase T seam table exactly once');
// [C10-1] The decision-claim aggregate is migration 029's own table: 028 must not create it, and 029 must
// create exactly it, so the union is the declared sixteen-table Phase-T set and each table is declared once.
$claimStart=strpos($migration,'private static function install_payment_event_decision_claim_authority');
$claimEnd=strpos($migration,'private static function verify_payment_event_decision_claim_schema');
$claimInstall=substr($migration,$claimStart,$claimEnd-$claimStart);
$claimInstall=substr($claimInstall,strpos($claimInstall,'{'));
if(str_contains($claimInstall,'UPDATE ')||str_contains($claimInstall,'INSERT INTO')||str_contains($claimInstall,'ALTER TABLE'))throw new RuntimeException('[C10-1] Migration 029 must be additive only');
preg_match_all('/CREATE TABLE \{\$p\}([a-z_]+)/',$claimInstall,$claimCreated);
if($claimCreated[1]!==array('payment_provider_event_decision_claims'))throw new RuntimeException('[C10-1] Migration 029 must create exactly the decision-claim table and nothing else');
$united=array_merge($createdTables,$claimCreated[1]);sort($united);$declared=$tables;sort($declared);
if($united!==$declared)throw new RuntimeException('[C10-1] Migrations 028 and 029 together must create exactly the sixteen declared Phase T tables');
if(count(array_unique($united))!==16)throw new RuntimeException('[C10-1] Each Phase T table must be declared by exactly one migration');
// [C10-1] The repair is scheduled, never assumed: 028's verifier neither requires nor validates the claim
// aggregate (an installation that completed 028 before the aggregate existed must still satisfy it), and it
// tolerates the table 029 owns.
$verifierStart=strpos($migration,'private static function verify_payment_execution_schema');
$seamVerifier=substr($migration,$verifierStart,strpos($migration,'private static function install_payment_event_decision_claim_authority')-$verifierStart);
$specStart=strpos($seamVerifier,'$spec = array(');
$specBody=substr($seamVerifier,$specStart,strpos($seamVerifier,');',$specStart)-$specStart);
if(str_contains($specBody,'payment_provider_event_decision_claims'))throw new RuntimeException('[C10-1] Migration 028\'s verifier must not require the decision-claim aggregate');
if(str_contains($seamVerifier,'FROM {$p}payment_provider_event_decision_claims'))throw new RuntimeException('[C10-1] Migration 028\'s verifier must not validate the decision-claim rows');
if(!str_contains($migration,"if ( \$short === 'payment_provider_event_decision_claims' ) continue;"))throw new RuntimeException('[C10-1] The seam verifier must tolerate the table migration 029 owns');

// Locked Phase-T vocabulary and structural constants (§5.2).
foreach(array(
    "PROVIDERS=array('stripe')","MODES=array('test','live')","ACCOUNT_STATES=array('active','suspended','closed')",
    "EXECUTION_STATES=array('disabled','enabled')","CREDENTIAL_STATES=array('unconfigured','configured','invalid')",
    "OBJECT_KINDS=array('customer','payment_method','intent','charge','subscription','mandate')",
    "CANONICAL_KINDS=array('student','purchase','obligation','collection_intent','recurring_enrolment')",
    "OPERATIONS=array('submit_collection','cancel_collection','reconcile_collection')",
    "COMMAND_STATES=array('authorised','dispatching','completed','refused','conflicted')",
    "DISPATCH_STATES=array('claimed','in_flight','settled','released')",
    "RESULT_STATES=array('completed','refused','conflicted')",
    "OUTCOME_STATES=array('accepted_by_provider','declined','requires_action','unavailable','invalid_request','not_attempted')",
    "COMMAND_CONFLICT_REASON='duplicate_command_key_materially_different_payload'",
    "EVENT_TYPES=array(", "DECISION_STATES=array('translated','ignored','refused','conflicted')",
    "SECRET_CLASSES=array('webhook_signing_secret','api_key')","CIPHER='sodium_secretbox_v1'",
    "PROVISIONABLE_PROVIDERS=array()","SECRET_AUDIT_TYPES=array('stored','rotated','retired','revoked','write_refused','decrypt_failed')",
    "VERIFICATION_STATES=array('verified','refused')","R2_CONSEQUENCE_STATES=array('not_applicable','pending','applied','refused')",
    "DECISION_CLAIM_STATES=array('claimed','settled','released')",
    "SIGNATURE_TOLERANCE_SECONDS=300","MAX_WEBHOOK_BYTES=262144","TIMESTAMP_TOLERANCE_CLAMP_SECONDS=600",
    "DISPATCH_LEASE_SECONDS=120","DISPATCH_CALL_TIMEOUT_SECONDS=30","DISPATCH_LEASE_MARGIN_SECONDS=60",
    "DECISION_CLAIM_LEASE_SECONDS=120","DECISION_CLAIM_WAIT_MILLISECONDS=1000",
    "HTTPS_PROXY_HEADERS=array('HTTP_X_FORWARDED_PROTO')","TRUSTED_PROXY_OPTION='dzn_platform_payment_trusted_proxy'",
    "TRUSTED_PROXY_HTTPS_VALUE='https'",
    "DISPATCH_DESCRIPTOR_DOMAIN='payment_dispatch_descriptor_v1'",
    "DESCRIPTOR_PREFLIGHT_STATES=array('ok','dispatch_descriptor_unavailable')","LIVE_EXECUTION_PROVIDERS=array()",
    "'dispatch_descriptor_unavailable','dispatch_descriptor_incomplete',",
) as $needle)if(!str_contains($rule,$needle))throw new RuntimeException('Missing Phase T vocabulary: '.$needle);
if(!str_contains($rule,"'command_key_digest','idempotency_key_digest',"))throw new RuntimeException('The sealed descriptor must carry its binding pair');
if(!str_contains($rule,'public static function operationReferences')||!str_contains($rule,'public static function arbitrationSubject'))throw new RuntimeException('Phase T rule must own the reference map and the arbitration subject');

// §8.3: the dispatch claim is the only mutable execution row and is written inside transaction 1.
if(!str_contains($execRepository,"'claimed|in_flight'")&&!str_contains($rule,"'claimed|in_flight'"))throw new RuntimeException('The dispatch lifecycle must be locked');
foreach(array('acquireLease','takeoverLease','renewLease','settleClaim','releaseClaim','noCallAbort') as $method)
    if(!str_contains($execRepository,'function '.$method.'('))throw new RuntimeException('Missing fenced dispatch transition: '.$method);
if(!str_contains($execRepository,'claim_generation=claim_generation+1'))throw new RuntimeException('The takeover must advance the fencing generation');
if(!str_contains($execRepository,'dispatch_state=\'released\',lease_expires_at=NULL'))throw new RuntimeException('The generation-1 no-call abort must clear the lease in the same statement');
if(!str_contains($execRepository,'dispatch_state=\'released\',active_claim_slot=NULL'))throw new RuntimeException('The descriptor refusal must release the live slot');
foreach(array('insertCommand','insertAttempt','insertResult') as $method)if(!str_contains($execRepository,'function '.$method.'('))throw new RuntimeException('Missing insert-only execution method: '.$method);
foreach(array('function updateCommand','function updateResult','function deleteResult','function updateAttempt') as $forbidden)if(str_contains($execRepository,$forbidden))throw new RuntimeException('Execution evidence must be insert-only: '.$forbidden);
if(!str_contains($service,'SUBJECT')&&!str_contains($service,'subject_claim'))throw new RuntimeException('Cross-operation arbitration must be represented');
if(!str_contains($service,"'dispatch_in_flight'"))throw new RuntimeException('The losing opposing operation must be refused with dispatch_in_flight');
if(!str_contains($service,'acquireLease')||!str_contains($service,'settleClaim'))throw new RuntimeException('The execution service must use the fenced transitions');
if(!str_contains($service,'providerIdempotencyKey')||!str_contains($service,"'dzn-phase2a2t-'"))throw new RuntimeException('The provider idempotency key must be deterministically re-derivable');
if(!str_contains($service,'reconcile('))throw new RuntimeException('A takeover must reconcile before it re-issues');
if(!str_contains($service,'proveFence'))throw new RuntimeException('A takeover re-issue must pass a conditional pre-call ownership check');
// The pre-call preflight must be ordered before the lease acquisition it authorises (§8.3 step 2): the
// assertion is written in its declared direction, so a future reordering that acquires the lease first
// fails here.
if(strpos($service,'acquireLease')<strpos($service,'preflightDispatchDescriptor'))throw new RuntimeException('The acquisition must follow the pre-call preflight');
if(!str_contains($service,'sealedCommandKeyDigest')||!str_contains($service,'sealedIdempotencyKeyDigest'))throw new RuntimeException("Core's mandatory pre-lease binding comparison is missing");
if(strpos($service,'hash_equals($preflight->sealedIdempotencyKeyDigest()')>strpos($service,'acquireLease'))throw new RuntimeException("Core's binding comparison must be ordered before the acquisition");
if(strpos($service,'renewLease')===false)throw new RuntimeException('The takeover winner must renew its lease before acting');

// §5.3/§7: the port, the sealed descriptor, the preflight, the capability and the mapping registry.
foreach(array('sealDispatchDescriptor','preflightDispatchDescriptor','submit','cancel','reconcile') as $method)
    if(!str_contains($seam,'function '.$method.'('))throw new RuntimeException('Missing port method: '.$method);
if(!str_contains($seam,'string $expectedClaimIdempotencyKeyDigest'))throw new RuntimeException('The preflight must receive the expected claim digest explicitly');
if(!str_contains($seam,'class ProviderDispatchCapability'))throw new RuntimeException('The one-use dispatch capability is missing');
if(!str_contains($seam,'class ProviderReferenceClaims'))throw new RuntimeException('The memory-only reference claims are missing');
if(!str_contains($seam,'class PaymentProviderRegistry'))throw new RuntimeException('The provider registry is missing');
if(!str_contains($seam,"'unsupported_payment_provider'"))throw new RuntimeException('An unregistered provider must fail closed');
if(!str_contains($objectService,'activeObject')||!str_contains($objectService,'object_reference_digest'))throw new RuntimeException('The mapping registry must arbitrate on the keyed reference digest');
if(!str_contains($objectService,'unlink')&&!str_contains($objectService,'detach'))throw new RuntimeException('A mapping must be detachable');
if(!str_contains($providerRepository,'active_slot=1'))throw new RuntimeException('The mapping registry must keep one active link');

// §11: the vault refuses every provider write, and the descriptor seal is not a credential path.
if(!str_contains($vault,'PROVISIONABLE_PROVIDERS')&&!str_contains($vault,'provisionableProvider'))throw new RuntimeException("The vault must gate on the locked provisionable-provider constant");
if(!str_contains($vault,'provider_secret_write_not_authorised'))throw new RuntimeException('Every provider secret write must be refused with the locked reason');
if(strpos($vault,'provider_secret_write_not_authorised')>strpos($vault,'requireCapability'))throw new RuntimeException('The provider refusal must precede the capability check');
if(!str_contains($vault,"'write_refused'"))throw new RuntimeException('A refused write must be audited');
if(!str_contains($vault,'DZN_PLATFORM_PAYMENT_TEST_VAULT'))throw new RuntimeException('The constant-gated test vault is missing');
if(substr_count($vault,'sodium_crypto_secretbox')<1||!str_contains($vault,'random_bytes'))throw new RuntimeException('The vault must use authenticated encryption with a fresh nonce');
if(!str_contains($seal,'sodium_crypto_secretbox'))throw new RuntimeException('The dispatch seal must use authenticated encryption');
if(!str_contains($seal,'DISPATCH_DESCRIPTOR_FIELDS')&&!str_contains($seal,'fieldsMatch'))throw new RuntimeException('The sealed payload must be the locked field set');
if(str_contains($seal,'PaymentSecretVault'))throw new RuntimeException('The dispatch seal must not be the credential vault');
foreach(array('key_version','nonce','ciphertext') as $needle)if(!str_contains($seal,$needle))throw new RuntimeException('The sealed envelope shape is incomplete: '.$needle);
// Only an adapter may seal or open an envelope; Core may only digest a stored one.
foreach(glob($root.'/src/*/*.php') as $file)$allSources[]=array('file'=>$file,'code'=>file_get_contents($file));
foreach(glob($root.'/src/*/*/*.php') as $file)$allSources[]=array('file'=>$file,'code'=>file_get_contents($file));
foreach(glob($root.'/src/*/*/*/*.php') as $file)$allSources[]=array('file'=>$file,'code'=>file_get_contents($file));
foreach($allSources as $source){
    if(str_contains($source['code'],'PaymentExecutionDispatchSeal::seal(')||str_contains($source['code'],'PaymentExecutionDispatchSeal::open(')){
        if(!str_contains($source['file'],'/Integrations/'))throw new RuntimeException('Only an adapter may seal or open a dispatch descriptor: '.$source['file']);
    }
    // The sealing boundaries this phase owns are its dispatch seal, its credential vault and its adapters.
    // The Phase-V integration secret service is an inherited, separately reviewed boundary that this phase
    // neither adds to nor changes, so it is named explicitly rather than silently tolerated.
    if(str_contains($source['code'],'sodium_crypto_secretbox')&&!str_contains($source['file'],'/Integrations/')&&!str_contains($source['file'],'PaymentExecutionDispatchSeal')&&!str_contains($source['file'],'PaymentSecretVault')&&!str_contains($source['file'],'IntegrationSecretService'))throw new RuntimeException('An unexpected sealing boundary: '.$source['file']);
}
// No port method other than the preflight opens an envelope, and the port never stores anything.
if(substr_count($adapter,'PaymentExecutionDispatchSeal::open(')!==1)throw new RuntimeException('The adapter must open an envelope exactly once, in its preflight');
foreach(array('submit','cancel','reconcile') as $method){
    $start=strpos($adapter,'function '.$method.'(');
    $body=substr($adapter,$start,400);
    if(str_contains($body,'PaymentExecutionDispatchSeal::open('))throw new RuntimeException('A port call must never open an envelope: '.$method);
}
if(str_contains($adapter,'$wpdb'))throw new RuntimeException('The adapter must not touch storage');
if(!str_contains($adapter,'DISPATCH_CALL_TIMEOUT_SECONDS'))throw new RuntimeException('The outbound call must use the structural timeout');
if(!str_contains($adapter,'redirection')&&!str_contains($adapter,'redact'))throw new RuntimeException('The adapter must bound its request and redact its errors');
if(!str_contains($rule,'callFitsWithinLease')&&!str_contains($seam,'callFitsWithinLease'))throw new RuntimeException('The structural timeout inequality must be stated');

// §9: the webhook receipt, the pre-parse account selector, single-secret verification and the worker.
if(!str_contains($plugin,'StripeWebhookController'))throw new RuntimeException('The webhook controller must be registered');
if(!str_contains($controller,'payment-provider-events'))throw new RuntimeException('The webhook route is missing');
if(!str_contains($controller,"'permission_callback'=>'__return_true'"))throw new RuntimeException('The webhook must defer its trust decision to signature verification');
if(!str_contains($controller,"(?P<account>[A-Za-z0-9_-]{1,32})"))throw new RuntimeException('The route must carry the pre-parse account selector');
if(!str_contains($controller,"'method_not_allowed'")||!str_contains($controller,"'https_required'")||!str_contains($controller,"'unsupported_content_type'")||!str_contains($controller,"'payload_too_large'")||!str_contains($controller,"'empty_payload'")||!str_contains($controller,"'unexpected_request_shape'"))throw new RuntimeException('The §9.2 request requirements are incomplete');
if(!str_contains($intake,'recordReceipt')||!str_contains($intake,'account_selector_digest'))throw new RuntimeException('Every inbound request must be receipted');
if(!str_contains($intake,"'unsupported_payment_provider'")||!str_contains($intake,"'webhook_account_unresolved'")||!str_contains($intake,"'provider_account_inactive'"))throw new RuntimeException('The account resolution reasons are incomplete');
if(strpos($intake,'resolveByReferenceCode')>strpos($intake,'$translator->verify'))throw new RuntimeException('The account must resolve before the body is verified');
if(!str_contains($verifier,'hash_equals'))throw new RuntimeException('The signature comparison must be constant-time');
if(str_contains($verifier,'foreach')&&str_contains($verifier,'activeSecret(')&&substr_count($verifier,'activeSecret(')>1)throw new RuntimeException('Exactly one secret may ever be tried');
if(!str_contains($verifier,'SIGNATURE_TOLERANCE_SECONDS')||!str_contains($verifier,'TIMESTAMP_TOLERANCE_CLAMP_SECONDS'))throw new RuntimeException('The signature tolerance must be bounded and clamped');
if(!str_contains($verifier,"'webhook_secret_unconfigured'"))throw new RuntimeException('A missing signing secret must fail closed');
foreach(array('payment_succeeded','payment_failed','payment_requires_action','refund_recorded','mandate_recorded','provider_recurring_semantics_unresolved','unrecognised_provider_event') as $type)
    if(!str_contains($translator,$type))throw new RuntimeException('The translation mapping is incomplete: '.$type);
if(!str_contains($translator,"'provider_evidence'")&&!str_contains($intake,"'provider_evidence'"))throw new RuntimeException('Evidence must be submitted on the provider_evidence channel');
if(!str_contains($intake,"'evidence_submitted'")||!str_contains($intake,"'conflicting_provider_event'")||!str_contains($intake,"'stale_provider_event'"))throw new RuntimeException('The decision vocabulary is incomplete');
if(!str_contains($intake,'intentForObligation'))throw new RuntimeException('The R2 consequence must resolve the exact intent');
if(!str_contains($intake,"'confirm_intent'")||!str_contains($intake,"'confirm_cycle'"))throw new RuntimeException('The ordered R2 consequence must confirm the collection intent and then the cycle');
if(strpos($intake,"'confirm_intent'")>strpos($intake,"'confirm_cycle'"))throw new RuntimeException('Step 1 must precede step 2');
if(!str_contains($intake,'RenewalCycleService')||!str_contains($intake,'CollectionIntentService'))throw new RuntimeException('The consequence must delegate both existing R2 commands');
if(!str_contains($intake,'recordRefundEvidence'))throw new RuntimeException('A refund event must route to the R2 refund review');
if(str_contains($intake,'requirePayment(')||str_contains($intake,'->lapse(')||str_contains($intake,'->cancel('))throw new RuntimeException('Phase T must never advance an R2 cycle itself');
if(str_contains($intake,'CommercialRule::EVIDENCE_KINDS')||str_contains($intake,'$wpdb->insert')||str_contains($intake,'$wpdb->query("INSERT'))throw new RuntimeException('The intake must not write commercial storage directly');
if(!str_contains($intake,'CommercialPaymentService')||!str_contains($intake,'->ingest('))throw new RuntimeException('Translation must route through the existing R1 ingest boundary');

// [C8-1] Every HTTP method reaches the controlled handler, and every receipt carries the exact raw body.
if(!str_contains($controller,'ROUTE_METHODS'))throw new RuntimeException('[C8-1] the webhook method set must be declared once');
if(!str_contains($controller,'GET,POST,PUT,PATCH,DELETE,HEAD,OPTIONS'))throw new RuntimeException('[C8-1] every HTTP method must be routed to the controlled handler');
if(substr_count($controller,"'methods'=>self::ROUTE_METHODS")!==2)throw new RuntimeException('[C8-1] both webhook routes must declare the all-method set');
if(!str_contains($controller,'$intake->receive($providerKey,$accountSelector,$rawBody,$headers,$meta)'))throw new RuntimeException('[C8-1] the receipt must always receive the exact raw body, whatever a precheck decided');
if(str_contains($controller,'$reason===null?'))throw new RuntimeException('[C8-1] a precheck refusal must never substitute an empty or rewritten body');
if(!str_contains($intake,'precheck_refusal'))throw new RuntimeException('[C8-1] the controller must pass a controlled precheck refusal to the intake');
if(!str_contains($intake,'body_bytes')||!str_contains($intake,"'request_digest'=>PaymentExecutionIdempotency::payloadDigest(\$rawBody)"))throw new RuntimeException('[C8-1] the receipt must record the digest and byte count of the body it was given');

// [C8-2] Attribution is exact: the event object's active mapping must own the obligation the event names.
if(!str_contains($providerRepository,'function activeObjectsByReferenceDigest('))throw new RuntimeException('[C8-2] the active mapping must be resolvable by reference digest');
if(!str_contains($providerRepository,'object_reference_digest=%s AND active_slot=1'))throw new RuntimeException('[C8-2] a historical mapping must never be returned as attribution authority');
if(!str_contains($providerRepository,'function obligationForCollectionIntent('))throw new RuntimeException('[C8-2] an R2 collection-intent mapping must resolve the obligation it owns');
if(str_contains($intake,'objectIsMapped'))throw new RuntimeException('[C8-2] the loose "any historical mapping" check must be gone');
if(!str_contains($intake,'activeObjectsByReferenceDigest'))throw new RuntimeException('[C8-2] the intake must resolve the event object through the active mapping');
if(!str_contains($intake,'$this->canonicalObligation($mapping)===$obligationId?null'))throw new RuntimeException('[C8-2] the mapping\'s canonical obligation must equal the obligation the event resolved');
if(!str_contains($intake,'function canonicalObligation(')||!str_contains($intake,"'collection_intent'=>\$this->repository->obligationForCollectionIntent("))throw new RuntimeException('[C8-2] the canonical obligation of both obligation and collection-intent mappings must be resolved');
if(!str_contains($intake,"'unmapped_provider_object'")||!str_contains($intake,"'ambiguous_obligation_attribution'"))throw new RuntimeException('[C8-2] a mismatch and an absent mapping must each be refused');

// [C8-3] Insert-or-resolve reports ownership, and a lost insert race converges like any duplicate.
if(!str_contains($intake,'private function convergeExisting('))throw new RuntimeException('[C8-3] duplicates must converge through one shared path');
if(!str_contains($intake,"string \$eventReferenceDigest,string \$factDigest,string \$receivedAt):array"))throw new RuntimeException('[C8-3] insert-or-resolve must report whether this worker created the event');
if(!str_contains($intake,"return array('event_id'=>(int)\$winner->id,'created'=>false);"))throw new RuntimeException('[C8-3] a worker that loses the unique event index must adopt the winner\'s event without ownership');
if(!str_contains($intake,"if(!\$inserted['created'])return \$this->convergeExisting(\$event,\$factDigest,\$envelope);"))throw new RuntimeException('[C8-3] a lost insert race must route through the same convergence path as a read duplicate');
if(!str_contains($intake,"if(\$existing)return \$this->convergeExisting(\$existing,\$factDigest,\$envelope);"))throw new RuntimeException('[C8-3] a read duplicate must use the same convergence path');

// [C8-4] A drain recomputes the full fact digest and never translates changed facts.
if(!str_contains($intake,'$this->factDigest((string)$event->provider_key,(string)$event->event_reference_digest,$match)'))throw new RuntimeException('[C8-4] the drain must recompute the recorded event fact digest');
if(!str_contains($intake,'return $this->recordConflict($event);'))throw new RuntimeException('[C8-4] a changed drain payload must append the controlled conflict decision');

// [C9-1] Every decision is appended by exactly one claimed worker, and an event that still owes one is
// always completed — including an event that was recorded and then left with no decision at all.
if(!str_contains($intake,'private function completeDecision('))throw new RuntimeException('[C9-1] the serialised decision path is missing');
if(!str_contains($intake,'private function acquireDecisionClaim('))throw new RuntimeException('[C9-1] the per-event decision claim is missing');
if(!str_contains($intake,'private function appendDecisionUnderClaim('))throw new RuntimeException('[C9-1] the fenced decision append is missing');
if(!str_contains($intake,'private function convergeOnOwner('))throw new RuntimeException('[C9-1] the loser of a claim must converge instead of working');
if(!str_contains($intake,'private function decisionForEvent('))throw new RuntimeException('[C9-1] the owed-decision timeline must exclude conflict records');
if(!str_contains($intake,"if(!\$alwaysAppends&&!\$this->decisionIsOwed(\$last))return \$this->convergedDecision(\$eventId,\$last);"))throw new RuntimeException('[C9-1] an event that owes no decision must converge before any claim');
if(!str_contains($intake,"if(\$claim===null)return \$this->convergeOnOwner(\$eventId);"))throw new RuntimeException('[C9-1] a worker that cannot own the claim must not do any work');
if(!str_contains($intake,"return \$this->completeDecision(\$event,fn():array=>\$this->decide(\$event,\$envelope),'first_decision');"))throw new RuntimeException('[C9-1] a newly recorded event must take its first decision through the claim');
if(!str_contains($intake,"return \$this->completeDecision(\$existing,fn():array=>\$this->decide(\$existing,\$envelope),'duplicate');"))throw new RuntimeException('[C9-1] a duplicate must converge or complete an owed decision through the claim');
if(str_contains($intake,'function appendDecision('))throw new RuntimeException('[C9-1] no decision may be appended outside the claim fence');
if(strpos($intake,'settleDecisionClaim')>strpos($intake,'maxDecisionSequence'))throw new RuntimeException('[C9-1] the claim fence must precede the decision-sequence allocation');
if(strpos($intake,'acquireDecisionClaim')>strpos($intake,"\$decision=\$decide();"))throw new RuntimeException('[C9-1] the claim must be acquired before any decision work runs');
foreach(array('insertDecisionClaim','liveDecisionClaim','settleDecisionClaim','releaseDecisionClaim','takeoverDecisionClaim') as $method)
    if(!str_contains($providerRepository,'function '.$method.'('))throw new RuntimeException('[C9-1] missing decision-claim method: '.$method);
if(!str_contains($providerRepository,'UNIQUE KEY event_claim')&&!str_contains($providerRepository,'active_claim_slot=1'))throw new RuntimeException('[C9-1] the decision claim must arbitrate on its live slot');
if(!str_contains($providerRepository,'claim_generation=claim_generation+1'))throw new RuntimeException('[C9-1] a decision-claim takeover must advance the fencing generation');
if(!str_contains($providerRepository,"'subject_claim','event_claim',"))throw new RuntimeException('[C9-1] the decision-claim unique index must be an arbitrated duplicate key');
if(!str_contains($seam,'function decisionClaim('))throw new RuntimeException('[C9-1] the decision claim must be proved as an aggregate');
if(!str_contains($intake,'outstandingDecisionClaims'))throw new RuntimeException('[C9-1] the live decision claims must be visible in the diagnostics');

// [C10-2] Ownership covers the whole decision operation, not just the row the decision is appended
// through: the claim's bounded window is re-proved and renewed *before* every R1/R2 work unit, it can never
// be renewed once it has expired, and a generation whose window has closed stops before the work instead
// of discovering the loss when it appends. [C11-1] It covers the unit's whole transaction as well, at the
// connection's statement boundary: every statement the unit's own transaction runs is fenced from inside
// that transaction, so the unit holds the claim row for its whole transaction and a unit whose window has
// closed is rolled back before the next statement instead of committing the mutation it had started.
if(!str_contains($providerRepository,'function fenceDecisionClaimWindow('))throw new RuntimeException('[C10-2] the decision work-unit window fence is missing');
if(!str_contains($providerRepository,'function fenceDecisionClaimWindow(int $claimId,int $expectedGeneration,string $tokenDigest,?string $leaseUntil,string $now):bool'))throw new RuntimeException('[C11-1] the fence must prove the window at every statement and renew it only outside a transaction');
$fenceStart=strpos($providerRepository,'function fenceDecisionClaimWindow(');
$fenceBody=substr($providerRepository,$fenceStart,strpos($providerRepository,'public function duplicate(',$fenceStart)-$fenceStart);
foreach(array("claim_state='claimed'","claim_generation=%d","claim_token_digest=%s","active_claim_slot=1","lease_expires_at IS NOT NULL AND lease_expires_at>=%s","FOR UPDATE","lease_expires_at=%s") as $needle)
    if(!str_contains($fenceBody,$needle))throw new RuntimeException('[C10-2] the gate must re-prove the live generation and an unexpired lease: '.$needle);
if(strpos($fenceBody,'FOR UPDATE')>strpos($fenceBody,'SET lease_expires_at=%s'))throw new RuntimeException('[C11-1] the locking proof must precede the renewal it licenses');
if(!str_contains($fenceBody,"if(\$live===null||\$live==='')return false;"))throw new RuntimeException('[C11-1] the fence verdict must be the locking read, never an affected-row count');
if(!str_contains($fenceBody,'if($leaseUntil===null)return true;'))throw new RuntimeException('[C11-1] a statement inside the unit transaction must be bounded by the window it was granted, not by a fresh renewal');
if(!str_contains($seam,'class DecisionClaimWindowClosed'))throw new RuntimeException('[C10-2] the controlled closed-window stop is missing');
if(!str_contains($intake,'private function assertDecisionWorkWindow('))throw new RuntimeException('[C10-2] the per-work-unit entry gate is missing');
if(!str_contains($intake,'private function openDecisionWindow(')||!str_contains($intake,'private function closeDecisionWindow('))throw new RuntimeException('[C10-2] the decision window must be opened and closed around the operation');
if(!str_contains($intake,"DecisionClaimWindowClosed(self::DECISION_WINDOW_CLOSED_REASON"))throw new RuntimeException('[C11-1] a fence that finds no live window must stop the worker');
if(!str_contains($intake,'catch(DecisionClaimWindowClosed $e)'))throw new RuntimeException('[C10-2] a closed window must be a controlled stop, never a failure');
if(strpos($intake,'openDecisionWindow(')>strpos($intake,'$decision=$decide();'))throw new RuntimeException('[C10-2] the window must be opened before any decision work runs');
if(!str_contains($rule,"DECISION_UNIT_FENCE_FILTER='query'"))throw new RuntimeException('[C11-1] the statement boundary the unit is fenced at must be declared once');
if(!str_contains($rule,"DECISION_UNIT_UNFENCED_STATEMENTS='^(START\\s+TRANSACTION|ROLLBACK|SET\\s)'"))throw new RuntimeException('[C11-1] the statements a fence must never block must be declared once');
if(!str_contains($intake,'private function decisionWorkUnit(string $unit,callable $work):mixed'))throw new RuntimeException('[C11-1] the fenced work unit is missing');
if(!str_contains($intake,'private function armDecisionUnitFence(string $unit):void')||!str_contains($intake,'private function disarmDecisionUnitFence():void'))throw new RuntimeException('[C11-1] the statement fence must be registered for exactly one unit');
if(!str_contains($intake,'private function fenceDecisionUnitStatement(string $query):string'))throw new RuntimeException('[C11-1] the statement fence itself is missing');
if(!str_contains($intake,'add_filter(PaymentExecutionRule::DECISION_UNIT_FENCE_FILTER,$fence,self::DECISION_UNIT_FENCE_PRIORITY,1)'))throw new RuntimeException('[C11-1] the fence must be registered on the declared statement boundary');
if(!str_contains($intake,'remove_filter(PaymentExecutionRule::DECISION_UNIT_FENCE_FILTER,$fence,self::DECISION_UNIT_FENCE_PRIORITY)'))throw new RuntimeException('[C11-1] the fence must be removed again in a finally');
if(!str_contains($intake,'if($this->decisionUnitFenceDepth>0)return $query;'))throw new RuntimeException('[C11-1] the fence must never fence its own proof statement');
if(!str_contains($intake,'preg_match(\'/\'.PaymentExecutionRule::DECISION_UNIT_UNFENCED_STATEMENTS.\'/i\',$sql)'))throw new RuntimeException('[C11-1] the fence must pass transaction control and unwinding through');
if(strpos($intake,"decisionWorkUnit('r1_evidence_submission'")>strpos($intake,'$this->payments->ingest('))throw new RuntimeException('[C10-2] the R1 submission must be fenced by the window');
if(!str_contains($intake,"\$result=\$this->decisionWorkUnit('r1_evidence_submission',fn():array=>\$this->payments->ingest(\$input,\$key));"))throw new RuntimeException('[C11-1] the R1 submission must run as one fenced unit');
if(!str_contains($intake,"\$this->decisionWorkUnit('r2_'.\$step,"))throw new RuntimeException('[C11-1] every R2 command must run as one fenced unit');
if(strpos($intake,"\$this->decisionWorkUnit('r2_'.\$step,")>strpos($intake,'(new CollectionIntentService())->confirm('))throw new RuntimeException('[C10-2] every R2 command must be gated by the window');
if(!str_contains($intake,'$this->abandonDecisionClaim($claim);')||substr_count($intake,'$this->abandonDecisionClaim($claim);')<3)throw new RuntimeException('[C11-1] a stale generation must release the claim it appended nothing to');
if(!str_contains($intake,"PaymentExecutionSupport::hook('dzn_phase_2a2t_after_provider_event_decision_claim'"))throw new RuntimeException('[C10-2] the owned claim must be observable before any decision work runs');
if(!str_contains($rule,'It bounds the work, not merely the row'))throw new RuntimeException('[C10-2] the lease must be documented as the bounded window the owner works inside');

// [C12-1] The append is the last step that same bounded window covers. The fenced `claimed → settled`
// transition therefore requires the worker's own live slot and an unexpired lease as well as its generation
// and token — judged at the instant the statement itself runs, by one database-time expression that fences
// the predicate and stamps the settlement alike, so a lease that lapsed after the final R1/R2 work unit (or
// during the seam before this transaction) can never settle the claim and publish a decision, no matter what
// instant a caller read before that seam; the intake releases the claim it appended nothing to and
// converges, so an expired-but-not-yet-taken-over claim never strands the event.
$settleStart=strpos($providerRepository,'function settleDecisionClaim(');
$settleBody=substr($providerRepository,$settleStart,strpos($providerRepository,'function releaseDecisionClaim(',$settleStart)-$settleStart);
if(!str_contains($settleBody,"claim_state='claimed'")||!str_contains($settleBody,'claim_generation=%d')||!str_contains($settleBody,'claim_token_digest=%s'))throw new RuntimeException('[C12-1] the append must stay fenced by the claim state, generation and token');
if(!str_contains($settleBody,'active_claim_slot=1'))throw new RuntimeException('[C12-1] the append must require the claim\'s live slot');
if(!str_contains($settleBody,'lease_expires_at IS NOT NULL AND lease_expires_at>=UTC_TIMESTAMP()'))throw new RuntimeException('[C12-1] the append must require an unexpired lease');
if(!str_contains($settleBody,"claim_token_digest=%s AND active_claim_slot=1 AND lease_expires_at IS NOT NULL AND lease_expires_at>=UTC_TIMESTAMP()"))throw new RuntimeException('[C12-1] the live slot and the unexpired lease must be conditions of the same conditional statement');
if(!str_contains($settleBody,'settled_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()'))throw new RuntimeException('[C12-1] the settlement must stamp the row with the same database-time expression that judged its window');
if(str_contains($settleBody,'$now'))throw new RuntimeException('[C12-1] the append must judge the window at the instant the statement runs, never against an instant its caller read before the seam');
if(!str_contains($settleBody,'$claimId,$expectedGeneration,$tokenDigest'))throw new RuntimeException('[C12-1] the append must pass exactly its claim id, generation and token');
$appendStart=strpos($intake,'private function appendDecisionUnderClaim(');
$appendBody=substr($intake,$appendStart,strpos($intake,'private function abandonDecisionClaim(',$appendStart)-$appendStart);
if(substr_count($appendBody,'settleDecisionClaim(')!==1)throw new RuntimeException('[C12-1] the decision append must be fenced by exactly one conditional transition');
if(strpos($appendBody,'settleDecisionClaim(')>strpos($appendBody,'maxDecisionSequence('))throw new RuntimeException('[C12-1] the append fence must precede the decision-sequence allocation');
if(!str_contains($appendBody,"PaymentExecutionSupport::hook('dzn_phase_2a2t_before_provider_event_decision_append'"))throw new RuntimeException('[C12-1] the append seam after the final work unit and before the fence must be observable');
if(strpos($appendBody,'before_provider_event_decision_append')>strpos($appendBody,'settleDecisionClaim('))throw new RuntimeException('[C12-1] the append seam must be observable before the fence that closes the window');
if(!str_contains($appendBody,"settleDecisionClaim((int)\$claim['claim_id'],(int)\$claim['generation'],(string)\$claim['token'])"))throw new RuntimeException('[C12-1] the append fence must be given the claim identity alone, never an instant the caller read before the seam');
if(strpos($appendBody,'before_provider_event_decision_append')>strpos($appendBody,'$now=PaymentExecutionSupport::now();'))throw new RuntimeException('[C12-1] the instants the appended row records must be read after the append seam');
$appendRelease=strpos($appendBody,'abandonDecisionClaim($claim)');
if($appendRelease===false||$appendRelease<strpos($appendBody,'settleDecisionClaim(')||$appendRelease>strpos($appendBody,'convergeOnOwner'))throw new RuntimeException('[C12-1] a refused append must release the claim it appended nothing to and then converge');

// [C14-1] A database-time expression is evaluated once, when its statement starts — not when the statement
// reaches the row it judges — so the append's window verdict is only honest if it is taken *after* the claim
// row is held. The transition therefore takes that row with its own fenced locking read, where a wait decides
// nothing, and runs the conditional `claimed → settled` update only once the row is held: the update can no
// longer be the statement that waits, so a window that lapsed while the append was queued behind another
// transaction's lock on that row can never be settled with the database instant the waiting statement began
// with. The locking read can only ever refuse a claim the update would also refuse — the update's
// affected-row count remains the only proof of ownership.
if(strpos($settleBody,"SET claim_state='settled'")===false)throw new RuntimeException('[C14-1] the conditional settlement must stay in the append transition');
$settleLock=substr($settleBody,0,strpos($settleBody,"SET claim_state='settled'"));
if(!str_contains($settleLock,'SELECT id FROM')||!str_contains($settleLock,'FOR UPDATE'))throw new RuntimeException('[C14-1] the append must take the claim row lock with its own fenced locking read before the conditional update');
if(strpos($settleBody,'FOR UPDATE')>strpos($settleBody,"SET claim_state='settled'"))throw new RuntimeException('[C14-1] the claim row lock must be taken before the conditional update that fences the append');
foreach(array("claim_state='claimed'",'claim_generation=%d','claim_token_digest=%s','active_claim_slot=1','lease_expires_at IS NOT NULL AND lease_expires_at>=UTC_TIMESTAMP()') as $needle)
    if(!str_contains($settleLock,$needle))throw new RuntimeException('[C14-1] the locking read must fence the same claim identity, live slot and unexpired lease: '.$needle);

// [C9-3] The §9.2 transport rule is configured, allowlisted and never satisfied by a client's own header.
if(!str_contains($rule,'public static function trustedProxyHeaders('))throw new RuntimeException('[C9-3] the configured proxy-header gate is missing');
if(!str_contains($rule,'public static function proxyHeaderIndicatesHttps('))throw new RuntimeException('[C9-3] the allowlisted proxy-header verdict is missing');
if(!str_contains($rule,'HTTPS_PROXY_HEADERS'))throw new RuntimeException('[C9-3] the locked proxy-header allowlist is missing');
if(!str_contains($rule,'TRUSTED_PROXY_OPTION'))throw new RuntimeException('[C9-3] the operator proxy configuration is missing');
if(!str_contains($controller,'public static function transportIsHttps('))throw new RuntimeException('[C9-3] the §9.2 transport verdict is missing');
if(!str_contains($controller,'PaymentExecutionRule::trustedProxyHeaders()'))throw new RuntimeException('[C9-3] the controller must trust only the configured allowlisted proxy headers');
if(!str_contains($controller,'PaymentExecutionRule::proxyHeaderIndicatesHttps('))throw new RuntimeException('[C9-3] the controller must use the allowlisted proxy-header verdict');
if(!str_contains($controller,'self::transportIsHttps(self::requestHeaders($request))'))throw new RuntimeException('[C9-3] the precheck must decide the transport from the request headers');
if(str_contains($controller,'x-forwarded-proto')||str_contains($controller,'HTTP_X_FORWARDED_PROTO'))throw new RuntimeException('[C9-3] the controller must never name a proxy header outside the locked allowlist');
if(!str_contains($rule,"if(!in_array(\$headerName,self::trustedProxyHeaders(),true))return false;"))throw new RuntimeException('[C9-3] an unconfigured proxy header must never mark a delivery secure');

// [C9-4] OPTIONS is answered ahead of WordPress's own OPTIONS handler, through the one controlled path.
// WordPress answers OPTIONS in `rest_handle_options_request()`, a `rest_pre_dispatch` filter, so the route
// method set alone can never deliver one to the controller: the endpoint must intercept its own routes first.
if(!str_contains($controller,'rest_pre_dispatch'))throw new RuntimeException('[C9-4] the endpoint must intercept its own OPTIONS delivery on rest_pre_dispatch');
if(!str_contains($controller,'public static function interceptOptions('))throw new RuntimeException('[C9-4] the OPTIONS interception is missing');
if(!str_contains($controller,"add_filter('rest_pre_dispatch',array(__CLASS__,'interceptOptions'),self::OPTIONS_INTERCEPT_PRIORITY,3)"))throw new RuntimeException('[C9-4] the interception must be registered on the pre-dispatch hook with the request');
if(!preg_match('/OPTIONS_INTERCEPT_PRIORITY=([0-9]+)/',$controller,$interceptPriority)||(int)$interceptPriority[1]>=10)
    throw new RuntimeException('[C9-4] the interception must outrank WordPress default OPTIONS handler (rest_handle_options_request, priority 10)');
if(!str_contains($controller,'ROUTE_NAMESPACE=')||!str_contains($controller,'ROUTE_PROVIDER=')||!str_contains($controller,'ROUTE_ACCOUNT='))
    throw new RuntimeException('[C9-4] the registered route shapes must be declared once and shared with the interception');
if(substr_count($controller,'register_rest_route(self::ROUTE_NAMESPACE,')!==2)throw new RuntimeException('[C9-4] both webhook routes must be registered from those shared shapes');
if(!str_contains($controller,"'#^/'.self::ROUTE_NAMESPACE.self::ROUTE_PROVIDER"))throw new RuntimeException('[C9-4] the interception must match exactly the endpoint route shapes');
$interceptStart=strpos($controller,'public static function interceptOptions(');
$interceptEnd=strpos($controller,'private static function optionsRoutePattern(');
$interceptBody=substr($controller,$interceptStart,$interceptEnd-$interceptStart);
if(!str_contains($interceptBody,'self::process('))throw new RuntimeException('[C9-4] the interception must run the one controlled path, never a second refusal implementation');
if(!str_contains($interceptBody,"!=='OPTIONS'"))throw new RuntimeException('[C9-4] only an OPTIONS delivery may be intercepted');
if(!str_contains($webhookRuntime,"'/^[A-Za-z0-9_-]{1,32}$/'"))throw new RuntimeException('[C9-4] the runtime suite must prove its disposable account selector fits the route account segment');

// §9.7: the bounded worker principal is adopted, proved and restored.
if(!str_contains($support,'dzn_platform_payment_worker_principal'))throw new RuntimeException('The worker principal option is missing');
foreach(array("'dzn_ingest_payment_provider_events'","'dzn_ingest_commercial_payment_evidence'","'dzn_manage_collection_intents'","'dzn_manage_renewal_cycles'") as $capability)
    if(!str_contains($support,$capability))throw new RuntimeException('The worker principal capability set is incomplete: '.$capability);
foreach(array("'manage_options'","'dzn_manage_payment_execution'","'dzn_manage_payment_providers'") as $forbidden)
    if(!str_contains($support,$forbidden))throw new RuntimeException('The worker principal must refuse an administrative capability: '.$forbidden);
if(!str_contains($worker,'wp_set_current_user')||!str_contains($worker,'get_current_user_id')||!str_contains($worker,'finally'))throw new RuntimeException('The worker context must adopt, prove and restore the principal');
if(!str_contains($worker,'payment_worker_context_reentrant'))throw new RuntimeException('The worker context must refuse re-entry');
if(str_contains($worker,'user 1')||str_contains($worker,'add_role')||str_contains($worker,'add_cap'))throw new RuntimeException('The worker context must never escalate');
foreach($allSources as $source)if(str_contains($source['code'],'wp_set_current_user')&&!str_contains($source['file'],'PaymentExecutionWorkerContext'))throw new RuntimeException('Only the worker context may set a current user: '.$source['file']);
if(!str_contains($service,"'dzn_manage_payment_execution'"))throw new RuntimeException('The execution surface must keep its own actor rule');
if(str_contains($intake,'dzn_manage_payment_execution'))throw new RuntimeException('The event worker must never hold the execution capability');

// §8.4: hooks fire after the context exits and carry declared ids only.
if(!str_contains($service,'dzn_phase_2a2t_after_execution_command'))throw new RuntimeException('The execution hook is missing');
if(!str_contains($intake,'dzn_phase_2a2t_after_provider_event_decision'))throw new RuntimeException('The decision hook is missing');
$hookPos=strpos($service,'dzn_phase_2a2t_after_execution_command');
if(str_contains(substr($service,$hookPos,120),'wp_set_current_user'))throw new RuntimeException('The execution hook must fire outside the worker context');

// §13: read models and diagnostics are view-capability gated and secret-free.
if(!str_contains($seam,"'dzn_view_payment_execution_authority'"))throw new RuntimeException('The read models must require the view capability');
if(!str_contains($seam,'descriptorRefusals')||!str_contains($seam,'outstandingDispatches'))throw new RuntimeException('The dispatch diagnostics are incomplete');
if(!str_contains($seam,'refused_writes')||!str_contains($seam,'decrypt_failures'))throw new RuntimeException('The secret diagnostics are incomplete');

// §14: no scheduler is introduced, and nothing in Core names a provider SDK or performs I/O.
$core='';
foreach(glob($root.'/src/Core/Application/*.php') as $file)$core.=file_get_contents($file);
foreach(glob($root.'/src/Core/Application/PaymentExecution/*.php') as $file)$core.=file_get_contents($file);
foreach(array('wp_schedule_event','wp_schedule_single_event','new \\Cron','Stripe\\','stripe-php','curl_init','curl_exec','wp_remote_post','wp_remote_get') as $forbidden)
    if(str_contains($core,$forbidden))throw new RuntimeException('Core must hold no scheduler or outbound capability: '.$forbidden);
if(str_contains($rule,'DESCRIPTOR_VERIFY')||str_contains($seam,'file_get_contents'))throw new RuntimeException('The seam must perform no I/O');

// §12.4: the verifier rejects the declared malformed shapes and re-runs the R1/R2 verifiers.
foreach(array(
    "table outside the declared Phase 2A.2-T set","must use InnoDB","identity contract","unique public handle",
    "provider-neutral and secret-free","only the dispatch claim may carry a sealed envelope","a raw reference column may not exist",
    "evidence must be append-only","digest nullability","reference type","reference parent","reference parent identity",
    "reference index","secret scope index","may never be unscoped","two live dispatch claims share one arbitration subject",
    "a claim may only coexist with its own descriptor refusal","an incomplete sealed descriptor","non-positive dispatch generation",
    "Phase 2A.2-T decision-claim state","two live decision claims share one provider event",
    "a settled decision claim must carry the decision it appended","a non-positive decision-claim generation",
    "must not own academic, notification or settlement storage",
) as $needle)if(!str_contains($migration,$needle))throw new RuntimeException('The Phase T verifier is incomplete: '.$needle);
foreach(array("'payment_terms'","'payment_lessons'","'payment_schedules'","'payment_notifications'","'payment_evidence'","'payment_settlements'") as $table)
    if(!str_contains($migration,$table))throw new RuntimeException('The phase-owned academic table rejection is incomplete');
if(!str_contains($migration,'externalParents')||!str_contains($migration,"'commercial_offer_obligations'")||!str_contains($migration,"'collection_intents'"))throw new RuntimeException('The frozen external authoritative parents are missing');

// §17: every required suite exists and the concurrency runner names every required mode.
foreach(array($root.'/tests/phase-2a2t-migration-runtime.php',$root.'/tests/phase-2a2t-runtime.php',$root.'/tests/phase-2a2t-webhook-runtime.php',$root.'/tests/phase-2a2t-secret-runtime.php',$root.'/tests/phase-2a2t-corruption-runtime.php',$root.'/tests/phase-2a2t-failure-runtime.php',$root.'/tests/phase-2a2t-concurrency-runner.sh') as $suite)
    if(!is_file($suite))throw new RuntimeException('A required Phase T suite is missing: '.basename($suite));
foreach(array(
    'duplicate_webhook','out_of_order_event','submit_vs_cancel','settlement_vs_attempt','mapping_change_vs_intake',
    'secret_rotation_vs_intake','unrelated_students','duplicate_command_replay','settlement_vs_r2_consequence',
    'submit_vs_cancel_in_flight','redrive_after_crash','concurrent_expired_lease','takeover_reissue_fenced',
    'fenced_settlement_lost','initial_dispatch_descriptor_failure','post_preflight_capability_failure',
    'conflicting_duplicate_webhook','pending_decision_retry','undecided_event_recovery',
    'stale_owner_after_lease_expiry','stale_owner_inside_r1_unit','stale_owner_inside_r2_unit',
    'stale_owner_at_decision_append','append_blocked_on_claim_row',
) as $mode)if(!str_contains($concurrency,$mode))throw new RuntimeException('The concurrency runner must cover: '.$mode);
if(!str_contains($concurrency,'webhook_delivery'))throw new RuntimeException('[C8-3] the duplicate-webhook race must actually deliver a provider event');
if(!str_contains($concurrency,'body_changed'))throw new RuntimeException('[C8-3] the conflicting duplicate must re-deliver one event identity with different facts');
if(!str_contains($concurrency,'converge on exactly one recorded event'))throw new RuntimeException('[C8-3] the duplicate race must assert one recorded event');
if(!str_contains($concurrency,'decision claim'))throw new RuntimeException('[C9-1] the decision-claim races must assert the claim');
// [C10-2] The stale-owner race must stall the owner past its own lease, prove the successor generation
// took the claim over, and prove the stale generation reached no R1/R2 work boundary at all.
if(!str_contains($concurrency,'lease_expires_at=%s WHERE provider_event_id=%d AND active_claim_slot=1 AND claim_generation=%d'))throw new RuntimeException('[C10-2] the stale-owner race must let the owner stall past its own lease');
if(!str_contains($concurrency,"!is_file(\$gate.'/w1.work')")||!str_contains($concurrency,'the stale generation must perform no R1/R2 work at all'))throw new RuntimeException('[C10-2] the stale-owner race must prove the stale generation performed no R1/R2 work');
if(!str_contains($concurrency,"is_file(\$gate.'/w2.work')"))throw new RuntimeException('[C10-2] the stale-owner race must prove the takeover generation did the work');
if(!str_contains($concurrency,'claim_generation===2'))throw new RuntimeException('[C10-2] the stale-owner race must prove the takeover generation advanced the claim');
// [C12-1] The append race must let the owner's own window lapse — by real elapsed time, never by writing it
// into the past — at the append seam, and prove that the lapsed lease (never a successor's take-over) is what
// refuses the stale generation, which appends nothing and releases the claim it appended nothing to, and that
// the next delivery completes the event.
if(!str_contains($concurrency,'before_provider_event_decision_append'))throw new RuntimeException('[C12-1] the append race must let the window lapse at the append seam');
if(!str_contains($concurrency,"strtotime(\$expires.' UTC')+1"))throw new RuntimeException('[C12-1] the append race must let the owner window lapse by real elapsed time, never by writing it into the past');
if(!str_contains($concurrency,'the append race must let the owner window lapse by real elapsed time'))throw new RuntimeException('[C12-1] the append race must prove the window genuinely lapsed in real time');
if(!str_contains($concurrency,'the stale generation must have reached the R1/R2 work boundaries'))throw new RuntimeException('[C12-1] the append race must prove the stale generation reached the work units');
if(!str_contains($concurrency,'no successor generation may have replaced the stale generation'))throw new RuntimeException('[C12-1] the append race must prove no successor generation replaced the stale one');
if(!str_contains($concurrency,'must append nothing and report the event as still owing its decision'))throw new RuntimeException('[C12-1] the append race must prove the stale generation appended nothing');
// [C14-1] The queued-append race must hold the claim row in a *separate* transaction until the owner's live
// window has lapsed, attribute the owner's queued append to that exact row lock, and prove the queued append
// settled no claim and appended no decision.
if(!str_contains($concurrency,"SELECT id,claim_generation,lease_expires_at FROM {\$p}payment_provider_event_decision_claims WHERE id=%d FOR UPDATE"))throw new RuntimeException('[C14-1] the queued-append race must hold the claim row in the blocker\'s own transaction');
if(!str_contains($concurrency,'the append must be queued behind the claim row lock before it is released'))throw new RuntimeException('[C14-1] the queued-append race must prove the append is queued behind that lock');
if(!str_contains($concurrency,'performance_schema')||!str_contains($concurrency,'information_schema.INNODB_TRX'))throw new RuntimeException('[C14-1] the queued append must be attributed to the claim row lock');
if(!str_contains($concurrency,'the queued append must settle no claim: the window it was granted had lapsed before the statement that judges it could run'))throw new RuntimeException('[C14-1] the queued-append race must prove the queued append settled no claim');
if(!str_contains($concurrency,'the queued append must append no decision: a window that closed publishes nothing, exactly like a replaced generation'))throw new RuntimeException('[C14-1] the queued-append race must prove the queued append appended no decision');
if(!str_contains($concurrency,"strtotime((string)\$lapsed['db_time'].' UTC')>\$leaseAt"))throw new RuntimeException('[C14-1] the queued-append race must prove the lock outlived the live window it closed');
foreach(array('retained','repeat','fresh','active_slot','descriptor_ciphertext','claim_generation','decision_claim','completed-028','029') as $needle)
    if(!str_contains($migrationRuntime,$needle))throw new RuntimeException('The migration-runtime suite is incomplete: '.$needle);
foreach(array('redrive','reconcile','dispatch_descriptor_unavailable','provider_credentials_unconfigured','live_execution_not_authorised','dispatch_in_flight') as $needle)
    if(!str_contains($runtime,$needle))throw new RuntimeException('The runtime suite is incomplete: '.$needle);
foreach(array('signature_invalid','signature_outside_tolerance','webhook_account_unresolved','conflicting_provider_event','stale_provider_event','payment_worker_principal_required','method_not_allowed','payload_too_large','request_digest','unmapped_provider_object','ambiguous_obligation_attribution','active_slot','recorded_event_recovery','trusted_proxy','rest_pre_dispatch','interceptOptions','OPTIONS') as $needle)
    if(!str_contains($webhookRuntime,$needle))throw new RuntimeException('The webhook suite is incomplete: '.$needle);
foreach(array('provider_secret_write_not_authorised','write_refused','decrypt_failed','REDACTED_SECRET') as $needle)
    if(!str_contains($secretRuntime,$needle))throw new RuntimeException('The secret suite is incomplete: '.$needle);
foreach(array('result_id','dispatches','transplanted','generation') as $needle)
    if(!str_contains($corruption,$needle))throw new RuntimeException('The corruption suite is incomplete: '.$needle);
foreach(array('rollback','worker principal','claim','result') as $needle)
    if(!str_contains($failure,$needle))throw new RuntimeException('The failure suite is incomplete: '.$needle);

// The network-free contract adapter is the only provider the suites may exercise.
if(!str_contains($contractAdapter,'class ContractPaymentAdapter'))throw new RuntimeException('The network-free contract adapter is missing');
if(str_contains($contractAdapter,'wp_remote_post')||str_contains($contractAdapter,'curl_'))throw new RuntimeException('The contract adapter must be network-free');
echo "phase-2a2t-contract: OK\n";
