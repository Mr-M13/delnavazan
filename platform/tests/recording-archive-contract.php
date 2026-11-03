<?php
/**
 * Static source contract for the recording/M-Box boundary.
 *
 * It proves the declared safeguards are present in the source. It does not prove a real provider,
 * VPS, PixelBox or production end-to-end run.
 */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$fail = [];
$need = static function (bool $ok, string $message) use (&$fail): void { if (!$ok) $fail[] = $message; };

$migrator = $read('src/Core/Infrastructure/Migration/Migrator.php');
$plugin = $read('delnavazan-platform.php');
$control = $read('src/Core/Application/Recording/RecordingControlPlane.php');
$lifecycle = $read('src/Core/Application/Recording/RecordingLifecycle.php');
$grant = $read('src/Core/Application/Recording/RecordingPlaybackGrantService.php');
$playbackPolicy = $read('src/Core/Application/Recording/RecordingPlaybackPolicy.php');
$flags = $read('src/Core/Application/Recording/RecordingFeatureFlags.php');
$issuer = $read('src/Integrations/LiveKit/LiveKitJwtIssuer.php');
$authorizer = $read('src/Integrations/LiveKit/LiveKitClassroomAuthorizer.php');
$decision = $read('src/Core/Application/RecordingDecisionAuthorityService.php');
$withdrawal = $read('src/Core/Application/ConsentWithdrawalService.php');
$admin = $read('src/Admin/Controller/RecordingOperationsController.php');

foreach (['066_recording_archive_authority','install_recording_archive_authority','verify_recording_archive_authority_schema','}recordings','}recording_events','}recording_commands','}recording_archive_confirmations','}recording_playback_access','dzn_manage_recording_operations','dzn_retry_recording_commands','dzn_reconcile_recordings','dzn_manage_recording_legal_hold'] as $x) $need(str_contains($migrator, $x), 'missing migration/capability authority ' . $x);
$need(str_contains($migrator, '066_recording_archive_authority'), 'migration ledger must include 066');
$need(!preg_match('/INSERT\s+INTO[^;]*recordings/i', $migrator), 'migration must not backfill a recording or decision');

foreach (['Recordings','dzn-recordings','dzn_manage_recording_operations'] as $x) $need(str_contains($read('src/Admin/Controller/Menu.php'), $x) && str_contains($read('src/Admin/Controller/AdminShellController.php'), $x), 'admin consolidation missing ' . $x);
foreach (['RecordingInvalidationListener','RecordingWithdrawalListener','RecordingReconciliationWorker','RecordingOperationsController','RecordingServiceRestController'] as $x) $need(str_contains($plugin, $x), 'bootstrap missing ' . $x);

foreach (['assertCaptureAuthorised','RecordingFeatureFlags::captureEnabledForLesson','freshServiceHealth','dzn_recording_withdrawal_requested','recording_idempotency_conflict','recording_legal_hold_active','recording_archive_confirmation_required','deletionAuthorisation','recording_archive_confirmation_signature_invalid'] as $x) $need(str_contains($control . $withdrawal . $flags, $x), 'control-plane safeguard missing ' . $x);
foreach (['$_POST','$_REQUEST','$_GET','wp_upload_dir','wp_handle_upload','move_uploaded_file'] as $x) $need(!str_contains($control, $x), 'control plane must not use browser/upload input ' . $x);
foreach (['livekit_api_secret','livekit_secret','StartRoomCompositeEgress','livekit.Egress','file_outputs'] as $x) $need(!str_contains($control . $grant, $x), 'WordPress control plane must not hold Egress authority ' . $x);

$need(str_contains($issuer, "'roomRecord' => false"), 'participant tokens must retain roomRecord=false');
$need(str_contains($authorizer, 'livekit_recording_not_authorised'), 'LiveKit authorizer must fail closed on recording');
$need(str_contains($authorizer, 'RecordingAuthorisationGate'), 'authorizer must use the canonical gate');
$need(!str_contains($authorizer, 'Egress'), 'participant authorizer must not own Egress');

$need(str_contains($decision, 'CAPTURE_ENABLED=false'), 'build capture flag must remain disabled');
$need(str_contains($decision, 'RESEARCH_EXPORT_ENABLED=false'), 'build research-export flag must remain disabled');
$need(str_contains($flags, "in_array(wp_get_environment_type(),array('local','development','staging')"), 'staging-only capture override must be environment bound');
$need(str_contains($flags, 'STAGING_COHORT_OPTION'), 'capture must require an explicit staging cohort');
$need(!str_contains($flags, 'RESEARCH_EXPORT_ENABLED=true'), 'research export must not be enabled by recording code');

foreach (['recording_requested','recording_active','recording_finalising','recording_verified','awaiting_archive','transferring','transfer_retry_pending','archive_verification','archived','vps_deletion_pending','archived_vps_deleted','failed','quarantined','deletion_required'] as $state) $need(str_contains($lifecycle, $state), 'missing canonical state ' . $state);
$need(str_contains($lifecycle, 'public const STATES'), 'missing canonical state registry');

$need(str_contains($grant, 'self::TTL_SECONDS=300') || str_contains($grant, 'TTL_SECONDS=300'), 'playback grants must be at most five minutes');
foreach (['recording_uid','content_version','principal_type','principal_id','jti','nbf','exp','archive_object_key'] as $x) $need(str_contains($grant, $x), 'playback grant missing binding ' . $x);
$need(str_contains($grant, 'recordPlaybackEvent') && str_contains($grant, 'refuse'), 'playback must append allow/refuse audit');
$need(str_contains($playbackPolicy, 'withdrawal_blocking') && str_contains($playbackPolicy, 'archive_available'), 'playback policy must check withdrawal and archive availability');

$need(str_contains($read('src/Core/Application/Recording/RecordingMediaEvidencePolicy.php'), 'source_sha256') || str_contains($read('src/Core/Application/Recording/RecordingMediaEvidencePolicy.php'), 'sha256'), 'media evidence must bind SHA-256');
$need(str_contains($read('src/Core/Application/Recording/RecordingMediaEvidencePolicy.php'), 'file_closed'), 'media evidence must require a closed file');

$serviceRoot = dirname($root) . '/services';
$need(is_file($serviceRoot . '/recorder-archiver/src/Storage/StorageLayout.php'), 'recorder storage layout missing');
$need(is_file($serviceRoot . '/recorder-archiver/src/Archive/RsyncArchiveAdapter.php'), 'resumable rsync adapter missing');
$need(is_file($serviceRoot . '/recorder-archiver/src/Provider/LiveKitEgressClient.php'), 'Egress adapter missing');
$need(is_file($serviceRoot . '/recorder-archiver/src/Worker.php'), 'recorder worker missing');
$need(is_file($serviceRoot . '/playback-gateway/src/RangeStreamer.php'), 'playback range streamer missing');
$need(is_file($serviceRoot . '/playback-gateway/src/ConcurrencyLimiter.php'), 'playback concurrency limiter missing');
$need(is_file($serviceRoot . '/infra/tailscale-acl.example.hujson'), 'Tailscale ACL example missing');
$need(is_file($serviceRoot . '/infra/mbox-provision.sh'), 'M-Box provisioning script missing');
$need(is_file($root . '/docs/RECORDING-MBOX-ARCHIVAL.md'), 'recording implementation/runbook documentation missing');

$storage = $read('../services/recorder-archiver/src/Storage/StorageLayout.php');
foreach (['0700','0600','same_filesystem','fsync','atomic','promote'] as $x) $need(str_contains($storage, $x), 'storage safety missing ' . $x);
$worker = $read('../services/recorder-archiver/src/Worker.php');
foreach (['archive_mount_absent','recording_source_path_invalid','recording_archive_confirmation_required','recording_legal_hold_active','recording_source_identity_changed','delete_archive'] as $x) $need(str_contains($worker, $x), 'deletion safety missing ' . $x);
$need(!preg_match('/unlink\s*\(\s*\$(payload|command|request)/', $worker), 'deletion must not unlink a request-supplied path');
$need(str_contains($read('../services/recorder-archiver/src/Archive/RsyncArchiveAdapter.php'), 'BatchMode=yes'), 'archive SSH must be non-interactive');
$need(str_contains($read('../services/recorder-archiver/src/Archive/RsyncArchiveAdapter.php'), 'StrictHostKeyChecking=yes'), 'archive SSH must verify host keys');

$gateway = $read('../services/playback-gateway/src/RangeStreamer.php');
foreach (['Accept-Ranges: bytes','Content-Range','206','416'] as $x) $need(str_contains($gateway, $x), 'gateway range support missing ' . $x);
$need(str_contains($gateway, "'HEAD'"), 'gateway must support HEAD');
$need(str_contains($read('../services/playback-gateway/src/ArchiveResolver.php'), 'archive_mount_absent'), 'gateway must fail closed without the M-Box mount');
$need(str_contains($read('../services/playback-gateway/src/AccessLog.php'), 'unset($entry'), 'gateway log must strip raw grant/token fields');
$need(!str_contains($read('../services/playback-gateway/src/App.php'), 'wp_upload_dir'), 'gateway must not use WordPress uploads');

$need(str_contains($read('src/Core/Application/MlDatasetEligibilityService.php'), 'recording_authorised'), 'ML eligibility must remain separate');
$need(!str_contains($control . $grant . $admin, 'consent_dataset_items'), 'recording/playback must not touch ML dataset storage');

if ($fail) {
    echo "Recording archive contract failed:\n- " . implode("\n- ", $fail) . "\n";
    exit(1);
}
echo "Recording archive contract passed\n";
