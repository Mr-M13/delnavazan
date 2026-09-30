<?php
/** Phase 2A.2-V provider-neutral Google Calendar & Meet integration source contract. */
$root=dirname(__DIR__);
$plugin=file_get_contents($root.'/delnavazan-platform.php');
$migration=file_get_contents($root.'/src/Core/Infrastructure/Migration/Migrator.php');
$rule=file_get_contents($root.'/src/Core/Application/ProviderIntegrationRule.php');
$idempotency=file_get_contents($root.'/src/Core/Application/ProviderIntegrationIdempotency.php');
$secrets=file_get_contents($root.'/src/Core/Application/IntegrationSecretService.php');
$validator=file_get_contents($root.'/src/Core/Application/ProviderIntegrationValidator.php');
$service=file_get_contents($root.'/src/Core/Application/ProviderIntegrationService.php');
$ingest=file_get_contents($root.'/src/Core/Application/ProviderEventIngestService.php');
$read=file_get_contents($root.'/src/Core/Application/ProviderIntegrationReadService.php');
$repository=file_get_contents($root.'/src/Core/Infrastructure/Repository/ProviderIntegrationRepository.php');
$adapter=file_get_contents($root.'/src/Integrations/GoogleCalendarMeetAdapter.php');
$contractAdapters=file_get_contents($root.'/src/Integrations/ContractProviderAdapters.php');
$ports='';
foreach(array('ProviderOAuthPort','ProviderCalendarPort','ProviderMeetingPort','ProviderEventNormalizer') as $port)$ports.=file_get_contents($root.'/src/Core/Application/Port/'.$port.'.php');
$phaseV=$rule.$idempotency.$secrets.$validator.$service.$ingest.$read.$repository.$adapter.$contractAdapters.$ports;
$runtime=file_get_contents($root.'/tests/phase-2a2v-runtime.php');
$migrationRuntime=file_get_contents($root.'/tests/phase-2a2v-migration-runtime.php');
$corruption=file_get_contents($root.'/tests/phase-2a2v-corruption-runtime.php');
$failure=file_get_contents($root.'/tests/phase-2a2v-failure-runtime.php');
$concurrency=file_get_contents($root.'/tests/phase-2a2v-concurrency-runner.sh').file_get_contents($root.'/tests/phase-2a2v-concurrency-setup.php').file_get_contents($root.'/tests/phase-2a2v-concurrency-worker.php').file_get_contents($root.'/tests/phase-2a2v-concurrency-verify.php');

// Build/schema identity: Phase V is Schema 27, sequenced additively after the R2 candidate.
if(!preg_match("/DZN_PLATFORM_SCHEMA_VERSION', '([0-9]+)'/",$plugin,$schema)||(int)$schema[1]<27)throw new RuntimeException('Missing Phase V schema identity');
if(!preg_match("/DZN_PLATFORM_BUILD_ID', 'phase2a2v-[a-z0-9-]+-[0-9]{8}\.[0-9]+'/",$plugin))throw new RuntimeException('Missing Phase V build identity');

// Migration, storage, verifier wiring and capabilities.
foreach(array(
    '027_google_calendar_meet_provider_integration','install_google_calendar_meet_provider_integration','verify_google_calendar_meet_provider_integration_schema',
    'integration_connections','integration_credentials','integration_oauth_authorizations',
    'provider_identity_mappings','provider_calendar_event_mappings','provider_meeting_mappings',
    'provider_ingest_events','provider_ingest_outcomes','provider_event_conflicts','provider_integration_commands',
    'dzn_connect_own_provider_calendar','dzn_manage_provider_integrations','dzn_revoke_provider_integrations',
    'dzn_ingest_provider_events','dzn_view_provider_integrations','dzn_platform_capability_version_2a2v',
) as $needle)if(!str_contains($migration.$plugin,$needle))throw new RuntimeException('Missing Phase V migration contract: '.$needle);
if(!str_contains($migration,"if(\$id==='027_google_calendar_meet_provider_integration')self::verify_google_calendar_meet_provider_integration_schema();"))throw new RuntimeException('Migration 027 must invoke the Phase V schema verifier before it is recorded');
if(substr_count($migration,'self::verify_google_calendar_meet_provider_integration_schema();')<3)throw new RuntimeException('Phase V verifier must run after migration 027, on current-schema verification and before schema activation');
if(!str_contains($migration,"in_array( '027_google_calendar_meet_provider_integration', (array) get_option( self::COMPLETED, array() ), true )"))throw new RuntimeException('Retained-027 pre-activation verification is missing');
if(!str_contains($migration,"'027_google_calendar_meet_provider_integration' )"))throw new RuntimeException('Phase V migration must be listed as required');
$installStart=strpos($migration,'private static function install_google_calendar_meet_provider_integration');
$installEnd=strpos($migration,'private static function verify_google_calendar_meet_provider_integration_schema');
$install=substr($migration,$installStart,$installEnd-$installStart);
$install=substr($install,strpos($install,'{')); // body only: the signature names the phase, the storage must not
if(str_contains($install,'UPDATE ')||str_contains($install,'INSERT INTO'))throw new RuntimeException('Phase V migration must be additive only');
if(stripos($install,'google_')!==false||stripos($install,'zoom')!==false||stripos($install,'teams')!==false)throw new RuntimeException('Phase V storage must stay provider-neutral');
if(stripos($install,'token')!==false||stripos($install,'secret')!==false||stripos($install,'password')!==false)throw new RuntimeException('Phase V domain storage must never carry a plaintext credential column');
preg_match_all('/CREATE TABLE \{\$p\}([a-z_]+)/',$install,$created);
$declaredTables=array('integration_connections','integration_credentials','integration_oauth_authorizations','provider_identity_mappings','provider_calendar_event_mappings','provider_meeting_mappings','provider_ingest_events','provider_ingest_outcomes','provider_event_conflicts','provider_integration_commands');
$createdTables=$created[1];sort($createdTables);sort($declaredTables);
if($createdTables!==$declaredTables)throw new RuntimeException('Migration 027 must create exactly the ten declared Phase V tables');
if(count(array_unique($createdTables))!==10)throw new RuntimeException('Migration 027 must declare each Phase V table exactly once');
if(!str_contains($migration,'$phaseVGrants')||!str_contains($migration,'$teacherVRole'))throw new RuntimeException('Phase V capability repair must ensure every grant and deny the Teacher role');

// Locked Phase-V owner decisions and controlled vocabularies.
foreach(array(
    "PROVIDER_CODES=array('google_calendar','google_meet')","EVIDENCE_PROVIDER_CODES=array('google_meet')",
    "PURPOSES=array('connection_identity','calendar_event','meeting_conference')",
    "CONNECTION_STATES=array('disconnected','authorizing','connected','refresh_failed','revoking','revoke_failed','revoked')",
    "CREDENTIAL_STATES=array('active','quarantined','revoked')","MAPPING_STATES=array('pending','verified','unverified','revoked')",
    "INGEST_STATES=array('received','admitted','converged','conflicted','refused')","INGEST_OUTCOMES=array('admitted','refused')",
    "PROOF_TRANSPORTS=array('google_channel_jwt','google_pubsub_oidc','deployment_gateway')",
    "'acknowledge_calendar_projection','acknowledge_meeting_projection'",
    "CONFLICT_KINDS=array('changed_payload','cross_lesson','cross_schedule_version','cross_context','cross_participant','cross_interval')",
    "COMMAND_DOMAIN='provider_integration_v1'","RULE_VERSION='provider_neutral_google_calendar_meet_v1'",
    "EVIDENCE_CHANNELS=array('staff_record','authenticated_platform','document_reference','provider_callback','system_ingest')",
) as $needle)if(!str_contains($rule,$needle))throw new RuntimeException('Missing Phase V vocabulary: '.$needle);
if(!str_contains($rule,'public static function canTransition')||!str_contains($rule,'public static function sameRedirectUri'))throw new RuntimeException('Phase V rule must own the lifecycle transition and exact redirect comparison');

// V-D4: authenticated encryption, domain separation, versioned key/cipher, fail-closed decrypt.
foreach(array('sodium_crypto_secretbox','sodium_crypto_secretbox_open','CIPHER_VERSION','KEY_VERSION','random_bytes','base64_encode','hash_hmac','wp_salt') as $needle)
    if(!str_contains($secrets,$needle))throw new RuntimeException('Credential sealing boundary is incomplete: '.$needle);
if(!str_contains($secrets,"'key_version'")||!str_contains($secrets,"'cipher_version'")||!str_contains($secrets,"'nonce'")||!str_contains($secrets,"'ciphertext'"))throw new RuntimeException('Credential storage shape must be ciphertext/nonce plus key and cipher version');
if(str_contains($secrets,"'access_token'")||str_contains($secrets,"'refresh_token'"))throw new RuntimeException('Credential storage must never declare a token column');
if(substr_count($secrets,'throw new \\RuntimeException')<4)throw new RuntimeException('Credential opening must fail closed on every malformed input');
if(!str_contains($secrets,'binding mismatch'))throw new RuntimeException('A sealed credential must be bound to exactly one connection');

// V-D10/V-D11: digest-only keys, payloads and provider references.
foreach(array('provider_integration_key:','provider_integration_event_key:','provider_integration_payload:','provider_integration_evidence:','provider_integration_subject:','hash_hmac','sha256') as $needle)
    if(!str_contains($idempotency,$needle))throw new RuntimeException('Digest boundary is incomplete: '.$needle);
if(!str_contains($idempotency,'ksort'))throw new RuntimeException('Payload digests must be canonicalised so an exact replay reconstructs the same digest');
if(!str_contains($service,'hash_equals((string)$command->command_payload_digest,$payload)'))throw new RuntimeException('Replay must compare the reconstructed payload digest unconditionally');
if(!str_contains($service,'IdempotencyConflictException'))throw new RuntimeException('A divergent replay must fail as an idempotency conflict');
foreach(array('raw_key','payload_json','access_token','refresh_token') as $forbidden)if(str_contains($migration,"`{$forbidden}`"))throw new RuntimeException('Integration storage must stay digest-only: '.$forbidden);

// V-D5/V-D6/V-D7/V-D8: mappings are references; M/N/O/P authority is never written by V.
if(!str_contains($validator,'CanonicalLessonScheduleValidator::validForLesson'))throw new RuntimeException('A projection must compose the canonical schedule validator');
if(!str_contains($validator,'CanonicalLessonDeliveryValidator::validForLesson'))throw new RuntimeException('Occurrence validity must compose the canonical delivery validator');
if(!str_contains($validator,'stale_schedule_version'))throw new RuntimeException('A projection against a stale schedule version must fail closed');
if(!str_contains($service,'CanonicalAttendanceIntakeService')&&!str_contains($ingest,'CanonicalAttendanceIntakeService'))throw new RuntimeException('Provider facts must enter through the Phase-P intake seam');
if(!str_contains($ingest,'ingestProviderEvidence'))throw new RuntimeException('Phase V must delegate provider evidence to CanonicalAttendanceIntakeService::ingestProviderEvidence');
if(!str_contains($ingest,'conflict_kind'))throw new RuntimeException('A changed provider-event context must leave a durable conflict receipt');
if(str_contains($ingest,'canonical_lesson_lifecycle_events')||str_contains($ingest,'canonical_attendance_evidence'))throw new RuntimeException('The integration layer must never write Phase-M/P storage directly');
if(str_contains($service,'UPDATE '))throw new RuntimeException('The integration authority must never write canonical storage with raw SQL');
if(!str_contains($ingest,'CanonicalAttendanceIdentityService')&&str_contains($ingest,'resolved_teacher_id'))throw new RuntimeException('Phase V must never assert a resolved participant');
foreach(array('sign_','signed_capability','public_join') as $forbidden)if(str_contains($phaseV,$forbidden))throw new RuntimeException('Phase V must never mint a public capability');

// V-D13 least privilege: capability-gated commands and object-level authorisation.
foreach(array('dzn_connect_own_provider_calendar','dzn_manage_provider_integrations','dzn_revoke_provider_integrations','dzn_ingest_provider_events','dzn_view_provider_integrations') as $capability)
    if(!str_contains($service,$capability))throw new RuntimeException('Phase V capability wiring is incomplete: '.$capability);
if(!str_contains($service,'teacher_principal_links')&&!str_contains($repository,'teacher_principal_links'))throw new RuntimeException('Teacher self-connect must revalidate the Phase-J principal link');
if(!str_contains($read,'authorizeTeacher')||!str_contains($read,'authorizeLesson'))throw new RuntimeException('Reads must enforce object-level authorisation against the exact Teacher and Lesson');

// Delivery authenticity: a raw delivery, a header or a channel token is never proof. Only a delivery
// envelope a named trusted transport authenticated over the exact body may reach the normaliser.
if(!str_contains($rule,'deliveryEnvelope')||!str_contains($ingest,'deliveryEnvelope'))throw new RuntimeException('A provider delivery must be reduced to an authenticated transport envelope');
if(str_contains($adapter,'x-goog-channel-token'))throw new RuntimeException('The Google seam must never treat a channel token as proof of authenticity');
if(!str_contains($ingest,'ProviderIntegrationRule::deliveryEnvelope($delivery)'))throw new RuntimeException('The ingest seam must validate the authenticated envelope before the normaliser');
if(!str_contains($ingest,'$facts=$this->normalizer->normalise($envelope)'))throw new RuntimeException('Only the authenticated envelope may be handed to the normaliser');
if(str_contains($ingest,'$delivery[\'headers\']'))throw new RuntimeException('The ingest seam must never read a raw header set');
if(!str_contains($rule,'hash_equals(hash(\'sha256\',$body),$digest)'))throw new RuntimeException('The authenticated body must be bound to the envelope by digest');

// Port boundary: a translation-only adapter records a pending projection, and only a separate
// acknowledged provider result may mark a mapping verified.
if(!str_contains($service,'recordPendingProjection')||!str_contains($service,'acknowledge_calendar_projection')||!str_contains($service,'acknowledgeMeetingProjection'))throw new RuntimeException('A translation-only projection must persist a pending mapping and require an acknowledgement');
if(str_contains($adapter,'provider_object_reference'))throw new RuntimeException('The Google translation seam can never return an acknowledged provider reference');
if(!str_contains($adapter,"'acknowledged'=>false"))throw new RuntimeException('The Google translation seam must report its projection as unacknowledged');
if(!str_contains($service,"'projection_not_pending'"))throw new RuntimeException('A mapping must only be verified from its pending translation');
if(!str_contains($service,'provider_request')||!str_contains($service,"'projection_state'=>'pending'"))throw new RuntimeException('A translation-only projection must be recorded as a pending mapping');

// Immutable durable handoff: the receipt table is never updated, admission is an appended outcome, and
// the mapping tables carry a pending state.
if(str_contains($ingest,'markProcessingState'))throw new RuntimeException('The ingest receipt must never be mutated after it is written');
if(str_contains($ingest,'$wpdb->update')||str_contains($ingest,'dzn_provider_ingest_events'))throw new RuntimeException('The ingest seam must never write the receipt table directly');
if(!str_contains($ingest,'recordOutcome')||!str_contains($repository,'insertIngestOutcome'))throw new RuntimeException('Admission must be recorded as an appended handoff outcome');
if(!str_contains($migration,'provider_ingest_outcomes'))throw new RuntimeException('The handoff outcome storage must exist');
if(!str_contains($validator,'ingestOutcomeShape'))throw new RuntimeException('A handoff outcome must be validated fail-closed');

// Consent identity: every authorization intent is bound to exactly the lifecycle it was created for.
if(!str_contains($migration,'connection_authorization'))throw new RuntimeException('An authorization intent must be bound to its exact connection');
if(!str_contains($service,'settleCompetingLifecycles'))throw new RuntimeException('Competing consent lifecycles must be settled explicitly');
if(str_contains($service,'authorizingConnection('))throw new RuntimeException('Completion must never select whichever authorizing connection is newest');

// Self-service reads require the view capability as well as exact Teacher ownership.
if(!str_contains($read,'VIEW_CAPABILITY'))throw new RuntimeException('Self-service reads must require the view capability');
if(str_contains($read,'if($this->isOwnTeacher($teacherId))return;'))throw new RuntimeException('A linked Teacher must never read without the view capability');

// V-D12/V-D14: no migration-time or build-time external call, credential read or provider traffic anywhere.
foreach(array('wp_remote_','wp_safe_remote_','curl_','fsockopen','stream_socket_client','new \\SoapClient','google-api-php-client') as $forbidden)
    if(str_contains($phaseV.$install,$forbidden))throw new RuntimeException('Phase V must make no external call: '.$forbidden);
if(str_contains($install,'get_option')||str_contains($install,'get_transient'))throw new RuntimeException('The migration must not read runtime configuration or a credential');
if(str_contains($phaseV,'Integrations\\GoogleCalendarMeetAdapter')&&str_contains($service,'Integrations\\'))throw new RuntimeException('Core must not depend on the Integrations module');
foreach(glob($root.'/src/Core/Application/*.php') as $file)
    if(str_contains((string)file_get_contents($file),'Delnavazan\\Platform\\Integrations\\'))throw new RuntimeException('Core must not import the Integrations module: '.basename($file));

// §13 test surface: every artefact exists, is gated on the disposable harness and covers its subject.
foreach(array(
    array($migrationRuntime,'DZN_PHASE_2A2V_RUNTIME_TEST','migration'),array($runtime,'DZN_PHASE_2A2V_RUNTIME_TEST','authority'),
    array($corruption,'DZN_PHASE_2A2V_RUNTIME_TEST','corruption'),array($failure,'DZN_PHASE_2A2V_RUNTIME_TEST','failure'),
) as $gate)if(!str_contains($gate[0],$gate[1])||!str_contains($gate[0],$gate[2])||!str_contains($gate[0],'wp_get_environment_type'))throw new RuntimeException('Phase V runtime artefact must be harness-gated: '.$gate[2]);
foreach(array('connect','revoke','authorization','projection','completion','duplicate','conflict','archival','unrelated','provider_event_sequence_race','cross_lesson_event_key_race','ingest_vs_canonical_authority') as $mode)
    if(!str_contains($concurrency,$mode))throw new RuntimeException('Concurrency matrix is missing mode: '.$mode);
if(!str_contains($contractAdapters,'implements ProviderOAuthPort')||!str_contains($contractAdapters,'ProviderCalendarPort')||!str_contains($contractAdapters,'ProviderMeetingPort')||!str_contains($contractAdapters,'ProviderEventNormalizer'))throw new RuntimeException('The deterministic contract adapters must implement all four ports');
if(!str_contains($contractAdapters,'[redacted]'))throw new RuntimeException('The deterministic adapters must never record credential material');
if(str_contains($contractAdapters,'wp_remote_')||str_contains($adapter,'wp_remote_'))throw new RuntimeException('The adapter seam must make no provider call');
if(!str_contains($adapter,'code_challenge_method')||!str_contains($adapter,'PKCE')&&!str_contains($adapter,'S256'))throw new RuntimeException('The Google translation seam must render a PKCE-bound authorization request');

// Declaration order: the Phase-V canonical chain is taken root → Enrolment → Term → Lesson, so the
// projection, acknowledgement and ingest paths can never form the reverse cycle that would deadlock
// against a canonical Phase-L/M/N/O/P operation on the same aggregate.
$lockAt=strpos($repository,'function lockLessonRoots');
$lockEnd=strpos($repository,'private function assertLessonTerm');
if($lockAt===false||$lockEnd===false)throw new RuntimeException('The Phase-V canonical chain lock must be confined to one declared primitive');
$lockBody=substr($repository,$lockAt,$lockEnd-$lockAt);
$rootLock=strpos($lockBody,'enrolment_identity_roots');
$enrolmentLock=strpos($lockBody,'enrolments WHERE id=%d FOR UPDATE');
$termLock=strpos($lockBody,'terms WHERE id=%d FOR UPDATE');
$lessonLock=strpos($lockBody,'lessons WHERE id=%d FOR UPDATE');
if($rootLock===false||$enrolmentLock===false||$termLock===false||$lessonLock===false)throw new RuntimeException('The Phase-V canonical chain lock must name each lock of the declared order');
if(!($rootLock<$enrolmentLock&&$enrolmentLock<$termLock&&$termLock<$lessonLock))throw new RuntimeException('lockLessonRoots must take the canonical chain in the declared order: root → Enrolment → Term → Lesson');
if(!str_contains($lockBody,'canonical_enrolment_identity_root_changed')||!str_contains($lockBody,'canonical_lesson_enrolment_changed')||!str_contains($repository,'canonical_lesson_term_changed'))throw new RuntimeException('The locked canonical relationship must be revalidated before it is handed to a caller');
// A caller that needs a mapping row must take the canonical chain first: locking the mapping before the
// chain would form the reverse cycle against a projection, which locks the chain and then this row.
$ackAt=strpos($service,'private function acknowledge(');
$ackEnd=strpos($service,'private function connectionForProjection(');
if($ackAt===false||$ackEnd===false||$ackEnd<$ackAt)throw new RuntimeException('The Phase-V acknowledgement path must stay a single declared operation');
$ackBody=substr($service,$ackAt,$ackEnd-$ackAt);
$ackChain=strpos($ackBody,'lockLessonRoots');
$ackMappingLock=strpos($ackBody,'meetingMapping($mappingId,true)');
if($ackChain===false||$ackMappingLock===false||!($ackChain<$ackMappingLock))throw new RuntimeException('An acknowledgement must lock the canonical chain before it locks the mapping row');
if(!str_contains($ackBody,'integration_mapping_malformed'))throw new RuntimeException('An acknowledgement must still validate the mapping shape it locked');

// Provider-scoped receipt sequence: a new receipt takes its `event_sequence` under a provider-scoped
// serialisation taken before the head is read and released only after the receipt transaction ended, so
// two deliveries that lock different Lessons cannot both choose the same sequence.
if(!str_contains($repository,'function lockProviderEventSequence')||!str_contains($repository,'function releaseProviderEventSequence'))throw new RuntimeException('The provider-scoped receipt sequence must be serialised by its own primitive');
if(!str_contains($repository,'GET_LOCK')||!str_contains($repository,'RELEASE_LOCK'))throw new RuntimeException('The provider-scoped receipt sequence must be serialised on a provider-scoped lock');
$sequenceAcquire=strpos($ingest,'lockProviderEventSequence');
$sequenceInsert=strpos($ingest,'insertIngestEvent(');
$sequenceRelease=strpos($ingest,'releaseProviderEventSequence');
if($sequenceAcquire===false||$sequenceInsert===false||$sequenceRelease===false||!($sequenceAcquire<$sequenceInsert&&$sequenceInsert<$sequenceRelease))throw new RuntimeException('The ingest must serialise the sequence before the receipt insert and release it after the transaction');
if(!str_contains($ingest,'}finally{'))throw new RuntimeException('The provider-scoped sequence lock must be released on every path');
// One provider event key is decided under that same provider-scoped lock: the committed head is
// re-read under it before a new receipt is inserted, so a contender that loses the race for the key
// decides from the committed winner instead of colliding with it on the unique index.
$recheck=strpos($ingest,'$existing=$this->repository->ingestEvent($providerCode,$eventKeyDigest,true);',$sequenceAcquire);
if($recheck===false||$recheck>$sequenceInsert)throw new RuntimeException('The ingest must re-read the committed receipt under the provider-scoped serialisation before it inserts a new one');
// Immutable-ingest contract: a materially changed context is durably recorded as a conflict on every
// path — including the durable unique-index guard — and is never merely rejected.
if(!str_contains($ingest,'conflictReceipt(')||!str_contains($ingest,'conflictResult('))throw new RuntimeException('A changed provider-event context must be recorded through the durable conflict recorder');
if(!str_contains($repository,'conflict_identity'))throw new RuntimeException('A conflict receipt must converge on the recorded conflict identity');
$guard=strpos($ingest,"duplicate(\$e)==='provider_event'");
$guardedConflict=strpos($ingest,'return $this->conflictReceipt(');
if($guard===false||$guardedConflict===false||$guardedConflict<$guard)throw new RuntimeException('A changed winner of the provider-event key race must be recorded as a durable conflict');
if(str_contains($ingest,"throw new IdempotencyConflictException('Idempotency conflict');"))throw new RuntimeException('A materially changed provider-event context must never be reduced to a bare idempotency conflict');
echo "Phase 2A.2-V contract static test passed\n";
