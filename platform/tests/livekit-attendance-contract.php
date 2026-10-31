<?php
/**
 * Source-level contract for the LiveKit canonical attendance adapter.
 *
 * These are structural guards, not behavioural proof. The disposable runtime suite proves the
 * behaviour against the real Phase-P/Phase-O authorities.
 */
$root=dirname(__DIR__);
$read=static function(string $path)use($root):string{
    $file=$root.'/'.$path;
    if(!is_file($file))throw new RuntimeException('Missing source file: '.$path);
    return (string)file_get_contents($file);
};
$check=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('LiveKit attendance contract failed: '.$message);};

$intake=$read('src/Core/Application/CanonicalAttendanceIntakeService.php');
$webhook=$read('src/Integrations/LiveKit/LiveKitWebhookController.php');
$integration=$read('src/Integrations/LiveKit/LiveKitAttendanceIntegration.php');
$repository=$read('src/Integrations/LiveKit/LiveKitAttendanceRepository.php');
$schema=$read('src/Integrations/LiveKit/LiveKitAttendanceSchema.php');
$event=$read('src/Integrations/LiveKit/LiveKitAttendanceEvent.php');
$worker=$read('src/Integrations/LiveKit/LiveKitAttendanceReconciliationWorker.php');
$session=$read('src/Integrations/LiveKit/LiveKitClassroomSessionService.php');
$actor=$read('src/Core/Application/ProviderEvidenceSystemActor.php');
$settlementActor=$read('src/Core/Application/CanonicalAttendanceSettlementSystemActor.php');
$plugin=$read('delnavazan-platform.php');

$check(str_contains($intake,"'livekit'"),'the controlled Phase-P provider list must admit livekit explicitly');
$check(str_contains($intake,'ingestProviderEvidence'),'Phase-P provider intake must remain the only evidence seam');

$check(str_contains($webhook,'LiveKitAttendanceIntegration'),'the signed webhook must use the attendance adapter');
$check(str_contains($webhook,"'participant_left'")&&str_contains($webhook,"'room_finished'"),'the webhook must handle leave and room termination');
$check(str_contains($webhook,'legacyJoined'),'the pre-adapter lifecycle path must be preserved');
$check(str_contains($webhook,'grantStillActive')&&str_contains($webhook,'revokeRoom'),'room revocation must be preserved');
$check(str_contains($webhook,"'status' => 503"),'transient failures must be retryable');
$check(!str_contains($webhook,'settleDelivered')&&!str_contains($webhook,'CanonicalLessonAuthorityService'),'the webhook must not complete Lessons directly');

$check(str_contains($integration,'ingestProviderEvidence'),'closed intervals must be submitted through Phase-P intake');
$check(!str_contains($integration,'settleDelivered')&&!str_contains($integration,'CanonicalLessonAuthorityService')&&!str_contains($integration,'CanonicalLessonDeliveryService'),'the adapter must not own settlement or completion');
$check(str_contains($integration,'ProviderEvidenceSystemActor::run'),'provider evidence must run under the narrow integration actor');
$check(str_contains($integration,'CanonicalAttendanceSettlementSystemActor::run'),'settlement must run under the separate narrow settlement actor');
$check(str_contains($integration,'mappingPreflight')&&str_contains($integration,'provider_identity_unmapped'),'an unmapped participant must be held, never guessed');
$check(str_contains($integration,'LiveKitAttendanceEvent::normalise'),'provider timestamps must be normalized before correlation');
$check(str_contains($integration,'STALE_OPEN_RETENTION_SECONDS')&&str_contains($integration,'livekit-open:'),'missing leave must be surfaced only as open evidence');
$check(str_contains($integration,'hasLiveOpenConnection'),'settlement must not revoke a still-live room');
$check(str_contains($integration,'phase_conflict')&&str_contains($integration,'submitCorrelationConflict'),'changed provider intervals must leave a conflict receipt');
$check(!str_contains($integration,'raw_body')&&!str_contains($integration,'wp_remote'),'the adapter must not persist raw payloads or call providers');

$check(str_contains($repository,'livekit_attendance_events')&&str_contains($repository,'livekit_attendance_connections')&&str_contains($repository,'livekit_attendance_rooms'),'durable correlation storage must be explicit');
$check(str_contains($repository,'connectionByKey')&&str_contains($repository,'connection_digest'),'leave must close the exact connection/session');
$check(str_contains($repository,'pending_occurrence'),'occurrence-end retry must be durable');
$check(str_contains($schema,"UNIQUE KEY event_digest(event_digest)"),'provider events must be idempotent at the database boundary');
$check(str_contains($schema,"UNIQUE KEY connection_key(room_id,identity_digest,connection_digest)"),'one connection must have one correlation row');
$check(str_contains($schema,"'payload','token','secret','email','phone','contact','raw_body'"),'the schema verifier must forbid raw payload/secret columns');
$check(str_contains($schema,'OPTION')&&str_contains($schema,'livekit_attendance_correlation_v1'),'the LiveKit schema must be feature-local and versioned');
$check(!str_contains($plugin,'DZN_PLATFORM_SCHEMA_VERSION\', \'58'),'the adapter must not activate an unrelated platform schema version');

$check(str_contains($event,'MAX_FUTURE_SECONDS')&&str_contains($event,'provider_at'),'provider event time must be bounded and normalized');
$check(str_contains($event,"'room_finished'"),'room termination must be a supported correlation event');

$check(str_contains($worker,'LiveKitAttendanceIntegration::enabled')&&str_contains($worker,'reconcile'),'the worker must be disabled by default and only retry durable work');
$check(str_contains($session,'bindGrant'),'token issuance must establish the durable server-owned mapping');
$check(str_contains($actor,"'dzn_ingest_canonical_attendance_evidence'")&&str_contains($actor,"'dzn_manage_canonical_attendance_identity'"),'the integration actor must hold only the narrow evidence/mapping capabilities');
$check(str_contains($actor,'manage_options')&&str_contains($actor,'dzn_manage_provider_integrations'),'the integration actor must refuse broad authority');
$check(str_contains($settlementActor,"'dzn_manage_canonical_lesson_delivery'")&&str_contains($settlementActor,"'dzn_manage_canonical_lessons'"),'the settlement actor must hold only the staged settlement capabilities');
$check(str_contains($settlementActor,'dzn_manage_canonical_attendance_review'),'the settlement actor must refuse attendance adjudication');
$check(str_contains($plugin,'LiveKitAttendanceSchema::maybeInstall')&&str_contains($plugin,'LiveKitAttendanceReconciliationWorker::register'),'the additive schema and worker must be wired');

$docs=$read('docs/LIVEKIT-CANONICAL-ATTENDANCE-ADAPTER.md');
$check(str_contains($docs,'Activation')&&str_contains($docs,'Rollback'),'activation and rollback must be documented separately');
$check(str_contains($docs,'dzn_attendance_settlement_system_user_id'),'the separate settlement actor must be documented');
$check(str_contains($docs,'1200')&&str_contains($docs,'900'),'the locked policy must be documented');
$check(str_contains($docs,'prospective')&&str_contains($docs,'backfill'),'prospective-only activation and no backfill must be documented');

echo "LiveKit attendance contract passed\n";
