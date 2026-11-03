<?php
declare(strict_types=1);

namespace Delnavazan\Recorder;

use Delnavazan\Recorder\Archive\ArchivePort;
use Delnavazan\Recorder\Archive\ConfirmationSigner;
use Delnavazan\Recorder\Media\MediaVerifier;
use Delnavazan\Recorder\Provider\EgressPort;
use Delnavazan\Recorder\Storage\StorageLayout;

/**
 * Durable recorder/archiver worker.
 *
 * All provider and transfer work runs outside a web request. Every queue is database-leased and
 * restart-recoverable. A failure keeps the complete VPS copy and the queue entry; deletion happens
 * only after an independently verified archive confirmation and every legal/identity gate.
 */
final class Worker {
    private WordPressClient $wordpress;
    private ConfirmationSigner $confirmations;
    private ?int $lastHealthSent = null;
    public array $stats = [];

    public function __construct(
        private Config $config,
        private Repository $repository,
        private StorageLayout $storage,
        private EgressPort $egress,
        private ArchivePort $archive,
        private MediaVerifier $verifier,
        private Clock $clock,
        ?WordPressClient $wordpress = null,
        ?ConfirmationSigner $confirmations = null,
    ) {
        $this->wordpress = $wordpress ?? new WordPressClient($config);
        if ($confirmations) {
            $this->confirmations = $confirmations;
        } else {
            $secret = (string) $config->get('archive_signing_secret');
            $this->confirmations = new ConfirmationSigner(strlen($secret) >= 32 ? $secret : $config->requireSecret('wordpress_service_secret'));
        }
        $this->stats = [];
    }

    public function runOnce(bool $pull = true, bool $skipDeletions = false): array {
        $this->stats = ['pulled' => 0, 'leases_recovered' => $this->repository->recoverExpiredLeases(), 'inbox_completed' => 0, 'inbox_failed' => 0, 'reconciled' => 0, 'transfers' => 0, 'transfer_failures' => 0, 'deletions' => 0, 'deletion_failures' => 0, 'outbox_sent' => 0, 'outbox_failed' => 0];
        if ($pull && $this->wordpress->configured()) {
            try { $this->stats['pulled'] = $this->pullCommands(); } catch (\Throwable $error) { $this->stats['pull_failed'] = Support::safeReason($error->getMessage()); }
        }
        $this->processInbox();
        $this->reconcileActive();
        $this->processTransfers();
        if (!$skipDeletions) $this->processDeletions();
        $this->sendOutbox();
        $this->sendHealth();
        return $this->stats;
    }

    public function pullCommands(): int {
        $commands = $this->wordpress->pull(20);
        $stored = 0;
        foreach ($commands as $envelope) {
            if (!is_array($envelope)) continue;
            $verified = Signer::verify($envelope, $this->config->requireSecret('wordpress_service_secret'), 'recording-service-command', $this->clock->now());
            $payload = $verified['payload'];
            if ((string) ($payload['payload_digest'] ?? '') !== Support::payloadDigest((array) ($payload['payload'] ?? []))) throw new \RuntimeException('recording_command_digest_mismatch');
            $this->repository->inboxStore($envelope, $payload);
            $stored++;
        }
        return $stored;
    }

    private function processInbox(): void {
        foreach ($this->repository->inboxClaim(20) as $row) {
            $tokenDigest = Support::digest((string) $row['_lease_token']);
            try {
                $payload = json_decode((string) $row['payload_json'], true);
                if (!is_array($payload)) throw new \RuntimeException('recording_command_payload_invalid');
                match ((string) $row['command_type']) {
                    'start' => $this->handleStart($payload),
                    'stop' => $this->handleStop($payload),
                    'reconcile' => $this->handleReconcile($payload),
                    'transfer', 'transfer_retry' => $this->handleTransferCommand($payload),
                    'archive_verification' => $this->handleArchiveVerification($payload),
                    'source_deletion' => $this->handleDeletionCommand($payload),
                    'withdrawal_disposition' => $this->handleWithdrawal($payload),
                    default => throw new \RuntimeException('recording_command_type_unsupported'),
                };
                $recordingUid = (string) ($payload['recording_uid'] ?? '');
                $this->outboxEnqueue('command_completed', $recordingUid, (int) ($payload['content_version'] ?? 1), [
                    'event' => 'command_completed', 'recording_uid' => $recordingUid,
                    'content_version' => (int) ($payload['content_version'] ?? 1),
                    'command_idempotency_key' => (string) $row['idempotency_key'], 'result_code' => 'completed',
                    'idempotency_key' => $this->eventKey('command_completed|' . $row['idempotency_key']),
                    'source' => 'recorder', 'occurred_at' => $this->now(),
                ], $this->eventKey('command_completed|' . $row['idempotency_key']));
                $this->repository->inboxComplete((int) $row['id'], $tokenDigest);
                $this->stats['inbox_completed']++;
            } catch (\Throwable $error) {
                if (getenv('DZN_RECORDER_DEBUG')) fwrite(STDERR, 'inbox error: ' . get_class($error) . ' ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine() . "\n");
                $this->repository->inboxRetry((int) $row['id'], $tokenDigest, $error->getMessage(), (int) $row['max_attempts']);
                $this->stats['inbox_failed']++;
            }
        }
    }

    private function handleStart(array $payload): void {
        $check = $this->storage->canAccept();
        if (!$check['ok']) throw new \RuntimeException('recording_' . $check['reason']);
        $job = $this->repository->jobCreate($payload);
        if (in_array((string) $job['state'], ['recording_active', 'recording_finalising', 'recording_verified', 'awaiting_archive', 'archived', 'archived_vps_deleted'], true)) return;
        $this->storage->ensure();
        $this->storage->ensureDirectory($this->storage->tmpDirectory((string) $job['recording_uid'], (int) $job['content_version']));
        $path = $this->storage->tmpCapturePath((string) $job['recording_uid'], (int) $job['content_version']);
        $started = $this->egress->start([
            'recording_uid' => $job['recording_uid'], 'content_version' => (int) $job['content_version'],
            'room_name' => (string) ($payload['room_name'] ?? ''),
            'output_path' => $path, 'layout' => $job['layout'], 'watermark_required' => (bool) $job['watermark_required'],
        ]);
        if (!$this->repository->jobUpdate((string) $job['recording_uid'], (int) $job['version'], ['state' => 'recording_active', 'provider_egress_id' => $started['egress_id']])) {
            throw new \RuntimeException('recording_job_version_conflict');
        }
        $this->repository->providerEventStore((string) $job['recording_uid'], $started['egress_id'], 'egress_started', $this->eventKey('egress_started|' . $job['recording_uid'] . '|' . $job['content_version']), ['status' => $started['status']]);
        $this->outboxEnqueue('egress_started', (string) $job['recording_uid'], (int) $job['content_version'], $this->callbackPayload('egress_started', $job, ['provider_egress_id' => $started['egress_id']]), $this->eventKey('egress_started|' . $job['recording_uid'] . '|' . $job['content_version']));
    }

    private function handleStop(array $payload): void {
        $job = $this->repository->job((string) ($payload['recording_uid'] ?? ''));
        if (!$job) return;
        if ((string) $job['provider_egress_id'] !== '') {
            $this->egress->stop((string) $job['provider_egress_id']);
            $this->repository->jobUpdate((string) $job['recording_uid'], (int) $job['version'], ['state' => 'recording_finalising']);
        } elseif (in_array((string) $job['state'], ['recording_requested', 'recording_active'], true)) {
            $this->repository->jobUpdate((string) $job['recording_uid'], (int) $job['version'], ['state' => 'failed', 'last_error' => 'stop_before_egress_started']);
        }
    }

    private function handleReconcile(array $payload): void {
        $job = $this->repository->job((string) ($payload['recording_uid'] ?? ''));
        if (!$job) return;
        if (isset($payload['legal_hold_state'])) {
            $this->repository->jobUpdate((string) $job['recording_uid'], (int) $job['version'], ['legal_hold_state' => (string) $payload['legal_hold_state'] === 'active' ? 'active' : 'none']);
            $job = $this->repository->job((string) $job['recording_uid']) ?: $job;
        }
        $this->reconcileJob($job);
    }

    private function reconcileActive(): void {
        foreach ($this->repository->jobsByState(['recording_requested', 'recording_active', 'recording_finalising']) as $job) {
            try { $this->reconcileJob($job); $this->stats['reconciled']++; }
            catch (\Throwable $error) { $this->repository->jobUpdate((string) $job['recording_uid'], (int) $job['version'], ['last_error' => Support::safeReason($error->getMessage())]); }
        }
    }

    private function reconcileJob(array $job): void {
        $egressId = (string) $job['provider_egress_id'];
        if ($egressId === '') {
            $this->handleStart(['recording_uid' => $job['recording_uid'], 'content_version' => (int) $job['content_version'], 'lesson_id' => (int) $job['lesson_id'], 'lesson_uid' => $job['lesson_uid'], 'schedule_version_id' => (int) $job['schedule_version_id'], 'schedule_version_uid' => $job['schedule_version_uid'], 'teacher_id' => (int) $job['teacher_id'], 'teacher_uid' => $job['teacher_uid'], 'student_id' => (int) $job['student_id'], 'student_uid' => $job['student_uid'], 'provider' => $job['provider'], 'authorisation_evidence_id' => (int) $job['authorisation_evidence_id'], 'authorisation_digest' => $job['authorisation_digest'], 'scheduled_duration_seconds' => (int) $job['scheduled_duration_seconds'], 'room_name' => (string) $job['room_name'], 'capture_mode' => $job['capture_mode'], 'layout' => $job['layout'], 'watermark_required' => (bool) $job['watermark_required']]);
            $job = $this->repository->job((string) $job['recording_uid']) ?: $job;
            $egressId = (string) $job['provider_egress_id'];
        }
        $status = $this->egress->status($egressId);
        $state = (string) $status['status'];
        if ($state === 'active' || $state === 'starting') {
            if ((string) $job['state'] === 'recording_requested') {
                $this->repository->jobUpdate((string) $job['recording_uid'], (int) $job['version'], ['state' => 'recording_active']);
                $this->outboxEnqueue('egress_started', (string) $job['recording_uid'], (int) $job['content_version'], $this->callbackPayload('egress_started', $job, ['provider_egress_id' => $egressId]), $this->eventKey('egress_started|' . $job['recording_uid'] . '|' . $job['content_version']));
            }
            return;
        }
        if ($state === 'ending') {
            if ((string) $job['state'] !== 'recording_finalising') {
                $this->repository->jobUpdate((string) $job['recording_uid'], (int) $job['version'], ['state' => 'recording_finalising']);
                $this->outboxEnqueue('egress_completed', (string) $job['recording_uid'], (int) $job['content_version'], $this->callbackPayload('egress_completed', $job), $this->eventKey('egress_completed|' . $job['recording_uid'] . '|' . $job['content_version']));
            }
            return;
        }
        if (in_array($state, ['failed', 'aborted', 'limit_reached'], true)) {
            $this->repository->jobUpdate((string) $job['recording_uid'], (int) $job['version'], ['state' => 'failed', 'last_error' => 'egress_' . $state]);
            $this->outboxEnqueue('egress_failed', (string) $job['recording_uid'], (int) $job['content_version'], $this->callbackPayload('egress_failed', $job, ['failure_class' => 'egress_' . $state]), $this->eventKey('egress_failed|' . $job['recording_uid'] . '|' . $state));
            return;
        }
        if ($state === 'complete') {
            if ((string) $job['state'] !== 'recording_finalising') {
                $this->repository->jobUpdate((string) $job['recording_uid'], (int) $job['version'], ['state' => 'recording_finalising']);
                $this->outboxEnqueue('egress_completed', (string) $job['recording_uid'], (int) $job['content_version'], $this->callbackPayload('egress_completed', $job), $this->eventKey('egress_completed|' . $job['recording_uid'] . '|' . $job['content_version']));
                $job = $this->repository->job((string) $job['recording_uid']) ?: $job;
            }
            $this->finaliseJob($job, (string) ($status['output_path'] ?? ''));
        }
    }

    private function finaliseJob(array $job, string $outputPath): void {
        $recordingUid = (string) $job['recording_uid'];
        $contentVersion = (int) $job['content_version'];
        $path = $outputPath !== '' && is_file($outputPath) ? $outputPath : $this->storage->tmpCapturePath($recordingUid, $contentVersion);
        try {
            $evidence = $this->verifier->verify($path, (int) $job['scheduled_duration_seconds']);
        } catch (\Throwable $error) {
            if (getenv('DZN_RECORDER_DEBUG')) fwrite(STDERR, 'media error: ' . $error->getMessage() . ' path=' . $path . "\n");
            $this->repository->jobUpdate($recordingUid, (int) $job['version'], ['state' => 'failed', 'last_error' => Support::safeReason($error->getMessage())]);
            $this->outboxEnqueue('media_verified', $recordingUid, $contentVersion, $this->callbackPayload('media_verified', $job, [
                'media' => ['egress_complete' => true, 'file_closed' => false, 'format' => 'mp4', 'video_streams' => 0, 'audio_streams' => 0, 'video_codec' => '', 'audio_codec' => '', 'duration_ms' => 0, 'source_bytes' => 0, 'size_stable' => false, 'sha256' => ''],
                'scheduled_duration_seconds' => (int) $job['scheduled_duration_seconds'],
            ]), $this->eventKey('media_failed|' . $recordingUid . '|' . $contentVersion));
            return;
        }
        $relative = $this->storage->readyRelativePath($recordingUid, $contentVersion, $evidence['sha256']);
        $final = $this->storage->readyPath($recordingUid, $contentVersion, $evidence['sha256']);
        $this->storage->promote($path, $final, $evidence['sha256']);
        $this->repository->assetUpsert([
            'recording_uid' => $recordingUid, 'content_version' => $contentVersion, 'path_relative' => $relative,
            'state' => 'verified', 'bytes' => $evidence['bytes'], 'sha256' => $evidence['sha256'],
            'duration_ms' => $evidence['duration_ms'], 'width' => $evidence['width'], 'height' => $evidence['height'],
            'video_codec' => $evidence['video_codec'], 'audio_codec' => $evidence['audio_codec'],
            'video_streams' => $evidence['video_streams'], 'audio_streams' => $evidence['audio_streams'],
            'sync_offset_ms' => $evidence['sync_offset_ms'], 'verified_at' => $this->clock->now(),
        ]);
        $this->repository->jobUpdate($recordingUid, (int) $job['version'], ['state' => 'recording_verified']);
        $this->outboxEnqueue('egress_completed', $recordingUid, $contentVersion, $this->callbackPayload('egress_completed', $job), $this->eventKey('egress_completed|' . $recordingUid . '|' . $contentVersion));
        $mediaKey = $this->eventKey('media_verified|' . $recordingUid . '|' . $contentVersion . '|' . $evidence['sha256']);
        $mediaPayload = $this->callbackPayload('media_verified', $job, [
            'idempotency_key' => $mediaKey,
            'media' => [
                'egress_complete' => true, 'file_closed' => true, 'format' => 'mp4', 'video_streams' => $evidence['video_streams'],
                'audio_streams' => $evidence['audio_streams'], 'expected_video_streams' => 1, 'expected_audio_streams' => 1,
                'video_codec' => $evidence['video_codec'], 'audio_codec' => $evidence['audio_codec'],
                'duration_ms' => $evidence['duration_ms'], 'width' => $evidence['width'], 'height' => $evidence['height'],
                'source_bytes' => $evidence['bytes'], 'size_stable' => true, 'sha256' => $evidence['sha256'],
                'sync_offset_ms' => $evidence['sync_offset_ms'],
            ],
            'scheduled_duration_seconds' => (int) $job['scheduled_duration_seconds'],
        ]);
        $this->outboxEnqueue('media_verified', $recordingUid, $contentVersion, $mediaPayload, $mediaKey);
        $queuedKey = $this->eventKey('transfer_queued|' . $recordingUid . '|' . $contentVersion);
        $this->outboxEnqueue('transfer_queued', $recordingUid, $contentVersion, $this->callbackPayload('transfer_queued', $job, ['idempotency_key' => $queuedKey]), $queuedKey);
        $objectKey = $this->archive->objectKey((string) $job['lesson_uid'], $recordingUid, $contentVersion, $evidence['sha256']);
        $tempKey = $objectKey . '.part-' . substr(Support::digest($recordingUid . '|' . $contentVersion), 0, 12);
        $this->repository->transferCreate($recordingUid, $contentVersion, $objectKey, $tempKey);
    }

    private function processTransfers(): void {
        foreach ($this->repository->transferClaim(10) as $transfer) {
            $tokenDigest = Support::digest((string) $transfer['_lease_token']);
            $recordingUid = (string) $transfer['recording_uid'];
            $contentVersion = (int) $transfer['content_version'];
            $startedAt = $this->dbNow();
            try {
                $asset = $this->repository->asset($recordingUid, $contentVersion);
                $job = $this->repository->job($recordingUid);
                if (!$asset || !$job || (string) $asset['state'] !== 'verified') throw new \RuntimeException('recording_source_not_verified');
                $identity = $this->archive->identity();
                if (!$identity['mounted']) throw new \RuntimeException('archive_mount_absent');
                if ((int) $identity['free_bytes'] < (int) $this->config->get('archive_min_free_bytes')) throw new \RuntimeException('archive_storage_below_threshold');
                $source = $this->storage->readyPath($recordingUid, $contentVersion, (string) $asset['sha256']);
                $check = $this->storage->sizeAndDigest($source);
                if (!hash_equals((string) $asset['sha256'], $check['sha256']) || (int) $asset['bytes'] !== $check['bytes']) throw new \RuntimeException('recording_source_identity_changed');
                $transferStartedKey = $this->eventKey('transfer_started|' . $recordingUid . '|' . $contentVersion);
                $this->outboxEnqueue('transfer_started', $recordingUid, $contentVersion, $this->callbackPayload('transfer_started', $job, ['archive_node_identity' => $identity['identity'], 'idempotency_key' => $transferStartedKey]), $transferStartedKey);
                $result = $this->archive->transfer($source, (string) $transfer['archive_object_key'], (string) $asset['sha256'], (string) $transfer['destination_temp_key']);
                $verified = $this->archive->verify((string) $transfer['archive_object_key'], (string) $asset['sha256'], (int) $asset['bytes']);
                $confirmation = [
                    'recording_uid' => $recordingUid, 'content_version' => $contentVersion,
                    'source_bytes' => (int) $asset['bytes'], 'destination_bytes' => $verified['bytes'],
                    'source_sha256' => (string) $asset['sha256'], 'destination_sha256' => $verified['sha256'],
                    'archive_node_identity' => $identity['identity'], 'destination_object_key' => (string) $transfer['archive_object_key'],
                    'durable_commit_receipt' => $verified['receipt'], 'confirmation_time' => $this->dbNow(),
                ];
                $confirmation['confirmation_signature'] = $this->confirmations->sign($confirmation);
                $this->repository->confirmation($confirmation);
                $this->repository->transferUpdate((int) $transfer['id'], (int) $transfer['version'], ['state' => 'archived', 'last_error' => null]);
                $this->repository->jobUpdate($recordingUid, (int) $job['version'], ['state' => 'archived']);
                $this->repository->transferAttempt((int) $transfer['id'], (int) $transfer['attempts'], $startedAt, $this->dbNow(), $result['idempotent'] ? 'idempotent' : 'verified', $verified['bytes']);
                $transferVerifiedKey = $this->eventKey('transfer_verified|' . $recordingUid . '|' . $contentVersion . '|' . $verified['sha256']);
                $this->outboxEnqueue('transfer_verified', $recordingUid, $contentVersion, $this->callbackPayload('transfer_verified', $job, ['idempotency_key' => $transferVerifiedKey, 'bytes' => $verified['bytes'], 'sha256' => $verified['sha256'], 'archive_node_identity' => $identity['identity'], 'destination_object_key' => $transfer['archive_object_key']]), $transferVerifiedKey);
                $this->outboxEnqueue('archive_confirmed', $recordingUid, $contentVersion, [
                    'event' => 'archive_confirmed', 'recording_uid' => $recordingUid, 'content_version' => $contentVersion,
                    'confirmation' => $confirmation, 'idempotency_key' => $this->eventKey('archive_confirmed|' . $recordingUid . '|' . $contentVersion . '|' . $verified['sha256']),
                    'source' => 'recorder', 'occurred_at' => $this->now(),
                ], $this->eventKey('archive_confirmed|' . $recordingUid . '|' . $contentVersion . '|' . $verified['sha256']));
                $this->repository->deletionCreate($recordingUid, $contentVersion, false);
                $this->stats['transfers']++;
            } catch (\Throwable $error) {
                $this->stats['transfer_failures']++;
                $attempts = (int) $transfer['attempts'];
                $max = (int) $transfer['max_attempts'];
                $state = $attempts >= $max ? 'failed' : 'pending';
                $delay = min(3600, 15 * (2 ** max(0, $attempts - 1)));
                $this->repository->transferUpdate((int) $transfer['id'], (int) $transfer['version'], ['state' => $state, 'available_at' => gmdate('Y-m-d H:i:s', $this->clock->now() + $delay), 'last_error' => Support::safeReason($error->getMessage()), 'lease_token_digest' => null, 'lease_expires_at' => null]);
                $this->repository->transferAttempt((int) $transfer['id'], $attempts, $startedAt, $this->dbNow(), 'failed', null, Support::safeReason($error->getMessage()));
                $job = $this->repository->job($recordingUid);
                if ($job) { $failedKey = $this->eventKey('transfer_failed|' . $recordingUid . '|' . $contentVersion . '|' . $attempts); $this->outboxEnqueue('transfer_failed', $recordingUid, $contentVersion, $this->callbackPayload('transfer_failed', $job, ['idempotency_key' => $failedKey, 'failure_class' => Support::safeReason($error->getMessage()), 'attempt' => $attempts]), $failedKey); }
            }
        }
    }

    private function processDeletions(): void {
        foreach ($this->repository->deletionClaim(10) as $deletion) {
            $tokenDigest = Support::digest((string) $deletion['_lease_token']);
            $recordingUid = (string) $deletion['recording_uid'];
            $contentVersion = (int) $deletion['content_version'];
            try {
                $job = $this->repository->job($recordingUid);
                $asset = $this->repository->asset($recordingUid, $contentVersion);
                $confirmation = $this->repository->confirmationFor($recordingUid, $contentVersion);
                if (!$job || !$asset || !$confirmation) throw new \RuntimeException('recording_archive_confirmation_required');
                if ((string) $job['legal_hold_state'] === 'active') throw new \RuntimeException('recording_legal_hold_active');
                if (!hash_equals((string) $asset['sha256'], (string) $confirmation['destination_sha256']) || !hash_equals((string) $asset['sha256'], (string) $confirmation['source_sha256']) || (int) $asset['bytes'] !== (int) $confirmation['destination_bytes']) throw new \RuntimeException('recording_archive_identity_mismatch');
                $this->archive->verify((string) $confirmation['destination_object_key'], (string) $asset['sha256'], (int) $asset['bytes']);
                $authorisation = $this->wordpress->deletionAuthorisation($recordingUid, $contentVersion, (string) $asset['sha256']);
                if (($authorisation['authorised'] ?? false) !== true) throw new \RuntimeException('recording_deletion_not_authorised:' . Support::safeReason((string) ($authorisation['reason'] ?? 'refused')));
                $relative = (string) $asset['path_relative'];
                if (preg_match('#^[0-9A-HJKMNP-TV-Z]{26}/[0-9]+/[a-f0-9]{64}\.mp4$#D', $relative) !== 1) throw new \RuntimeException('recording_source_path_invalid');
                $deleteArchive = $deletion['delete_archive'] === true || $deletion['delete_archive'] === 1 || $deletion['delete_archive'] === '1' || $deletion['delete_archive'] === 't';
                $path = $this->storage->readyRoot() . '/' . $relative;
                if (!is_file($path) && !is_link($path)) {
                    if (!$deleteArchive) throw new \RuntimeException('recording_source_missing');
                    $this->archive->delete((string) $confirmation['destination_object_key']);
                    $receipt = 'source_missing=1;size=' . (int) $asset['bytes'] . ';sha256=' . $asset['sha256'] . ';archive_deleted=1;deleted_at=' . $this->dbNow();
                    $this->repository->deletionUpdate((int) $deletion['id'], (int) $deletion['version'], ['state' => 'completed', 'deletion_receipt' => $receipt]);
                    $this->repository->jobUpdate($recordingUid, (int) $job['version'], ['state' => 'archived_vps_deleted']);
                    $this->repository->assetUpsert(['recording_uid' => $recordingUid, 'content_version' => $contentVersion, 'path_relative' => $relative, 'state' => 'deleted', 'bytes' => (int) $asset['bytes'], 'sha256' => (string) $asset['sha256'], 'duration_ms' => $asset['duration_ms'], 'width' => $asset['width'], 'height' => $asset['height'], 'video_codec' => $asset['video_codec'], 'audio_codec' => $asset['audio_codec'], 'video_streams' => $asset['video_streams'], 'audio_streams' => $asset['audio_streams'], 'sync_offset_ms' => $asset['sync_offset_ms'], 'verified_at' => $asset['verified_at']]);
                    $this->outboxEnqueue('source_deleted', $recordingUid, $contentVersion, $this->callbackPayload('source_deleted', $job, ['deleted_source_bytes' => (int) $asset['bytes'], 'deleted_source_sha256' => (string) $asset['sha256'], 'deletion_receipt' => $receipt, 'archive_deleted' => true]), $this->eventKey('source_deleted|' . $recordingUid . '|' . $contentVersion));
                    $this->stats['deletions']++;
                    continue;
                }
                $root = realpath($this->storage->readyRoot());
                $real = realpath($path);
                if (!$root || !$real || !str_starts_with($real, $root . DIRECTORY_SEPARATOR) || is_link($path)) throw new \RuntimeException('recording_source_path_invalid');
                $before = lstat($path);
                if (!is_array($before)) throw new \RuntimeException('recording_source_missing');
                if ((int) $before['size'] !== (int) $asset['bytes']) throw new \RuntimeException('recording_source_identity_changed');
                if (!hash_equals((string) $asset['sha256'], (string) hash_file('sha256', $path))) throw new \RuntimeException('recording_source_identity_changed');
                $dir = dirname($path); $base = basename($path);
                $handle = opendir($dir);
                if (!is_resource($handle)) throw new \RuntimeException('recording_source_directory_unavailable');
                $cwd = getcwd();
                try {
                    if (!chdir($dir) || realpath((string) getcwd()) !== $root . '/' . dirname($relative)) {
                        throw new \RuntimeException('recording_source_directory_invalid');
                    }
                    $after = lstat($base);
                    if (!is_array($after) || $after['dev'] !== $before['dev'] || $after['ino'] !== $before['ino'] || $after['size'] !== $before['size']) throw new \RuntimeException('recording_source_identity_changed');
                    if (!@unlink($base)) throw new \RuntimeException('recording_source_deletion_failed');
                } finally {
                    if ($cwd !== false) @chdir($cwd);
                    closedir($handle);
                }
                $this->storage->fsyncDirectory($dir);
                $archiveDeleted = false;
                if ($deleteArchive) {
                    $this->archive->delete((string) $confirmation['destination_object_key']);
                    $archiveDeleted = true;
                }
                $receipt = 'dev=' . $before['dev'] . ';ino=' . $before['ino'] . ';size=' . $before['size'] . ';sha256=' . $asset['sha256'] . ';archive_deleted=' . ($archiveDeleted ? '1' : '0') . ';deleted_at=' . $this->dbNow();
                $this->repository->deletionUpdate((int) $deletion['id'], (int) $deletion['version'], ['state' => 'completed', 'deletion_receipt' => $receipt, 'source_device' => (string) $before['dev'], 'source_inode' => (string) $before['ino']]);
                $this->repository->jobUpdate($recordingUid, (int) $job['version'], ['state' => 'archived_vps_deleted']);
                $this->repository->assetUpsert(['recording_uid' => $recordingUid, 'content_version' => $contentVersion, 'path_relative' => $relative, 'state' => 'deleted', 'bytes' => (int) $asset['bytes'], 'sha256' => (string) $asset['sha256'], 'duration_ms' => $asset['duration_ms'], 'width' => $asset['width'], 'height' => $asset['height'], 'video_codec' => $asset['video_codec'], 'audio_codec' => $asset['audio_codec'], 'video_streams' => $asset['video_streams'], 'audio_streams' => $asset['audio_streams'], 'sync_offset_ms' => $asset['sync_offset_ms'], 'verified_at' => $asset['verified_at']]);
                $this->outboxEnqueue('source_deleted', $recordingUid, $contentVersion, $this->callbackPayload('source_deleted', $job, ['deleted_source_bytes' => (int) $asset['bytes'], 'deleted_source_sha256' => (string) $asset['sha256'], 'deletion_receipt' => $receipt, 'archive_deleted' => $archiveDeleted]), $this->eventKey('source_deleted|' . $recordingUid . '|' . $contentVersion));
                $this->stats['deletions']++;
            } catch (\Throwable $error) {
                $this->stats['deletion_failures']++;
                $attempts = (int) $deletion['attempts'];
                $state = $attempts >= (int) $deletion['max_attempts'] ? 'failed' : 'pending';
                $delay = min(3600, 15 * (2 ** max(0, $attempts - 1)));
                $this->repository->deletionUpdate((int) $deletion['id'], (int) $deletion['version'], ['state' => $state, 'available_at' => gmdate('Y-m-d H:i:s', $this->clock->now() + $delay), 'last_error' => Support::safeReason($error->getMessage()), 'lease_token_digest' => null, 'lease_expires_at' => null]);
                $job = $this->repository->job($recordingUid);
                if ($job) { $failedKey = $this->eventKey('deletion_failed|' . $recordingUid . '|' . $contentVersion . '|' . $attempts); $this->outboxEnqueue('deletion_failed', $recordingUid, $contentVersion, $this->callbackPayload('deletion_failed', $job, ['idempotency_key' => $failedKey, 'failure_class' => Support::safeReason($error->getMessage()), 'attempt' => $attempts]), $failedKey); }
            }
        }
    }

    private function handleTransferCommand(array $payload): void {
        $job = $this->repository->job((string) ($payload['recording_uid'] ?? ''));
        if (!$job) throw new \RuntimeException('recording_job_not_found');
        $asset = $this->repository->asset((string) $job['recording_uid'], (int) $job['content_version']);
        if (!$asset || (string) $asset['state'] !== 'verified') throw new \RuntimeException('recording_source_not_verified');
        $objectKey = $this->archive->objectKey((string) $job['lesson_uid'], (string) $job['recording_uid'], (int) $job['content_version'], (string) $asset['sha256']);
        $tempKey = $objectKey . '.part-' . substr(Support::digest((string) $job['recording_uid'] . '|' . (int) $job['content_version']), 0, 12);
        $this->repository->transferCreate((string) $job['recording_uid'], (int) $job['content_version'], $objectKey, $tempKey);
    }

    private function handleArchiveVerification(array $payload): void {
        $job = $this->repository->job((string) ($payload['recording_uid'] ?? ''));
        if (!$job) return;
        $asset = $this->repository->asset((string) $job['recording_uid'], (int) $job['content_version']);
        $confirmation = $this->repository->confirmationFor((string) $job['recording_uid'], (int) $job['content_version']);
        if (!$asset || !$confirmation) throw new \RuntimeException('recording_archive_confirmation_required');
        $this->archive->verify((string) $confirmation['destination_object_key'], (string) $confirmation['destination_sha256'], (int) $confirmation['destination_bytes']);
    }

    private function handleDeletionCommand(array $payload): void {
        $job = $this->repository->job((string) ($payload['recording_uid'] ?? ''));
        if (!$job) return;
        if (isset($payload['legal_hold_state'])) {
            $this->repository->jobUpdate((string) $job['recording_uid'], (int) $job['version'], ['legal_hold_state' => (string) $payload['legal_hold_state'] === 'active' ? 'active' : 'none']);
        }
        $this->repository->deletionCreate((string) $job['recording_uid'], (int) $job['content_version'], (bool) ($payload['delete_archive'] ?? false));
    }

    private function handleWithdrawal(array $payload): void {
        $job = $this->repository->job((string) ($payload['recording_uid'] ?? ''));
        if (!$job) return;
        if ((string) $job['provider_egress_id'] !== '' && in_array((string) $job['state'], ['recording_requested', 'recording_active'], true)) {
            $this->egress->stop((string) $job['provider_egress_id']);
        }
        $this->repository->jobUpdate((string) $job['recording_uid'], (int) $job['version'], ['state' => 'deletion_required']);
        $this->repository->deletionCreate((string) $job['recording_uid'], (int) $job['content_version'], false);
    }

    private function sendOutbox(): void {
        if (!$this->wordpress->configured()) return;
        foreach ($this->repository->outboxClaim(50) as $row) {
            $tokenDigest = Support::digest((string) $row['_lease_token']);
            try {
                $payload = json_decode((string) $row['payload_json'], true);
                if (!is_array($payload)) throw new \RuntimeException('outbox_payload_invalid');
                $this->wordpress->callback($payload);
                $this->repository->outboxSent((int) $row['id'], $tokenDigest);
                $this->stats['outbox_sent']++;
            } catch (\Throwable $error) {
                $this->repository->outboxRetry((int) $row['id'], $tokenDigest, $error->getMessage());
                $this->stats['outbox_failed']++;
            }
        }
    }

    private function sendHealth(): void {
        if (!$this->wordpress->configured()) return;
        if ($this->lastHealthSent !== null && $this->clock->now() - $this->lastHealthSent < 60) return;
        try {
            $this->wordpress->health($this->health());
            $this->lastHealthSent = $this->clock->now();
        } catch (\Throwable $error) {
            $this->stats['health_failed'] = Support::safeReason($error->getMessage());
        }
    }

    public function health(): array {
        $storage = $this->storage->describe();
        $archive = $this->archive->identity();
        $tailscale = $this->tailscaleConnected();
        $ready = $storage['accepting'] && $archive['mounted'] && $tailscale;
        return [
            'event' => 'health', 'ready' => $ready, 'service_id' => (string) $this->config->get('service_id'),
            'storage_free_bytes' => $storage['free_bytes'], 'storage_free_percent' => $storage['free_percent'],
            'archive_mount_identity' => $archive['identity'], 'archive_available' => $archive['mounted'],
            'archive_free_bytes' => $archive['free_bytes'], 'tailscale_connected' => $tailscale,
            'egress_cpu_percent' => (float) $this->config->get('egress_cpu_percent'),
            'egress_memory_bytes' => (int) $this->config->get('egress_memory_bytes'),
            'oldest_queue_age_seconds' => $this->repository->oldestQueueAge(),
            'recorder_version' => 'recorder-archiver-1.0.0', 'queue_counts' => $this->repository->counts(),
            'received_at' => $this->clock->now(),
        ];
    }

    private function tailscaleConnected(): bool {
        $override = getenv('DZN_TAILSCALE_CONNECTED');
        if ($override !== false) return $override === '1' || strtolower($override) === 'true';
        $binary = (string) $this->config->get('tailscale_binary');
        if (!is_executable($binary)) return false;
        $command = escapeshellarg($binary) . ' status --json 2>/dev/null';
        $output = shell_exec($command);
        if (!is_string($output) || $output === '') return false;
        $decoded = json_decode($output, true);
        return is_array($decoded) && (string) ($decoded['BackendState'] ?? '') === 'Running';
    }

    private function callbackPayload(string $event, array $job, array $extra = []): array {
        return array_merge([
            'event' => $event, 'recording_uid' => (string) $job['recording_uid'],
            'content_version' => (int) $job['content_version'], 'source' => 'recorder',
            'actor_id' => 0, 'occurred_at' => $this->now(),
            'idempotency_key' => $this->eventKey($event . '|' . $job['recording_uid'] . '|' . (int) $job['content_version']),
        ], $extra);
    }

    private function outboxEnqueue(string $event, string $recordingUid, int $contentVersion, array $payload, string $key): void {
        try { $this->repository->outboxEnqueue($event, $recordingUid, $contentVersion, $payload, $key); }
        catch (\Throwable $error) { if (!str_contains($error->getMessage(), 'digest_conflict')) throw $error; }
    }

    private function eventKey(string $value): string { return Support::digest('dzn-recorder-event|' . $value); }
    private function now(): string { return gmdate('Y-m-d H:i:s', $this->clock->now()); }
    private function dbNow(): string { return $this->repository->db()->now(); }
}
