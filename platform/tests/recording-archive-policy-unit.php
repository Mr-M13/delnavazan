<?php
/**
 * Pure recording lifecycle, playback and media-evidence policy tests.
 *
 * No WordPress, database or network is used.
 */
$root = dirname(__DIR__);
require_once $root . '/src/Core/Application/Recording/RecordingLifecycle.php';
require_once $root . '/src/Core/Application/Recording/RecordingPlaybackPolicy.php';
require_once $root . '/src/Core/Application/Recording/RecordingMediaEvidencePolicy.php';
require_once $root . '/src/Core/Application/Recording/RecordingSignature.php';

use Delnavazan\Platform\Core\Application\Recording\RecordingLifecycle as L;
use Delnavazan\Platform\Core\Application\Recording\RecordingPlaybackPolicy;
use Delnavazan\Platform\Core\Application\Recording\RecordingMediaEvidencePolicy;
use Delnavazan\Platform\Core\Application\Recording\RecordingSignature;

$fail = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

$fail(count(L::STATES) === 15, 'All fifteen canonical states must exist');
foreach (['authorised','recording_requested','recording_active','recording_finalising','recording_verified','awaiting_archive','transferring','transfer_retry_pending','archive_verification','archived','vps_deletion_pending','archived_vps_deleted','failed','quarantined','deletion_required'] as $state) $fail(L::isState($state), 'missing state '.$state);

$apply = static function (string $state, string $expected, int $version, string $event): array { return L::apply($state, $expected, $version, 0, $event); };
$r = $apply(L::AUTHORISED, L::AUTHORISED, 1, 'request_start');
$fail($r['ok'] && $r['state'] === L::RECORDING_REQUESTED && $r['version'] === 2, 'authorised -> recording_requested');
$r = $apply(L::RECORDING_REQUESTED, L::RECORDING_REQUESTED, 2, 'egress_started');
$fail($r['ok'] && $r['state'] === L::RECORDING_ACTIVE, 'egress_started');
$r = $apply(L::RECORDING_ACTIVE, L::RECORDING_ACTIVE, 4, 'egress_completed');
$fail($r['ok'] && $r['state'] === L::RECORDING_FINALISING, 'egress_completed');
$r = $apply(L::RECORDING_FINALISING, L::RECORDING_FINALISING, 5, 'media_verified');
$fail($r['ok'] && $r['state'] === L::RECORDING_VERIFIED, 'media_verified');
$r = $apply(L::RECORDING_VERIFIED, L::RECORDING_VERIFIED, 6, 'transfer_queued');
$fail($r['ok'] && $r['state'] === L::AWAITING_ARCHIVE, 'transfer_queued');
$r = $apply(L::AWAITING_ARCHIVE, L::AWAITING_ARCHIVE, 7, 'transfer_started');
$fail($r['ok'] && $r['state'] === L::TRANSFERRING, 'transfer_started');
$r = $apply(L::TRANSFERRING, L::TRANSFERRING, 8, 'transfer_verified');
$fail($r['ok'] && $r['state'] === L::ARCHIVE_VERIFICATION, 'transfer_verified');
$r = $apply(L::ARCHIVE_VERIFICATION, L::ARCHIVE_VERIFICATION, 9, 'archive_confirmed');
$fail($r['ok'] && $r['state'] === L::ARCHIVED, 'archive_confirmed');
$r = $apply(L::ARCHIVED, L::ARCHIVED, 10, 'deletion_queued');
$fail($r['ok'] && $r['state'] === L::VPS_DELETION_PENDING, 'deletion_queued');
$r = $apply(L::VPS_DELETION_PENDING, L::VPS_DELETION_PENDING, 11, 'source_deleted');
$fail($r['ok'] && $r['state'] === L::ARCHIVED_VPS_DELETED, 'source_deleted');

// Replay, version and state conflicts.
$r = L::apply(L::RECORDING_ACTIVE, L::RECORDING_ACTIVE, 5, 5, 'egress_started');
$fail($r['ok'] && $r['idempotent'] && $r['state'] === L::RECORDING_ACTIVE, 'exact provider replay must be idempotent');
$r = L::apply(L::RECORDING_ACTIVE, L::RECORDING_ACTIVE, 5, 3, 'egress_started');
$fail(!$r['ok'] && $r['reason'] === 'version_conflict', 'optimistic version must be enforced');
$r = L::apply(L::RECORDING_ACTIVE, L::RECORDING_REQUESTED, 5, 0, 'egress_completed');
$fail(!$r['ok'] && $r['reason'] === 'expected_state_mismatch', 'expected prior state must be enforced');
$r = L::apply(L::RECORDING_REQUESTED, L::RECORDING_REQUESTED, 2, 0, 'archive_confirmed');
$fail(!$r['ok'] && $r['reason'] === 'transition_not_allowed', 'provider cannot skip required states');
$r = L::apply(L::RECORDING_ACTIVE, L::RECORDING_ACTIVE, 5, 5, 'withdrawal_stop');
$fail($r['ok'] && $r['state'] === L::RECORDING_FINALISING, 'withdrawal during active capture must stop and finalise');
$r = L::apply(L::AUTHORISED, L::AUTHORISED, 1, 1, 'withdrawal_stop');
$fail($r['ok'] && $r['state'] === L::DELETION_REQUIRED, 'withdrawal before capture must require deletion');
$fail(L::captureStarted(L::RECORDING_ACTIVE) && !L::captureStarted(L::AUTHORISED), 'capture-started boundary');
$fail(L::playbackCandidate(L::ARCHIVED) && L::playbackCandidate(L::VPS_DELETION_PENDING) && L::playbackCandidate(L::ARCHIVED_VPS_DELETED) && !L::playbackCandidate(L::QUARANTINED), 'playback states');

// Playback decision table.
$policy = new RecordingPlaybackPolicy();
$base = ['principal_kind'=>'student','principal_id'=>22,'teacher_id'=>11,'student_id'=>22,'recording_found'=>true,'recording_state'=>L::ARCHIVED,'content_version'=>1,'disposition_state'=>'none','archive_available'=>true,'withdrawal_blocking'=>false];
$fail($policy->evaluate($base) === ['authorised'=>true,'reason'=>'authorised'], 'owner playback authorised');
$fail(!$policy->evaluate(array_merge($base,['principal_id'=>99]))['authorised'], 'unrelated principal denied');
$fail($policy->evaluate(array_merge($base,['principal_kind'=>'teacher','principal_id'=>11]))['authorised'], 'assigned teacher authorised');
$fail(!$policy->evaluate(array_merge($base,['principal_kind'=>'teacher','principal_id'=>12]))['authorised'], 'other teacher denied');
$fail(!$policy->evaluate(array_merge($base,['principal_kind'=>'guardian','principal_id'=>22,'guardian_valid'=>false]))['authorised'], 'guardian without grant denied');
$fail($policy->evaluate(array_merge($base,['principal_kind'=>'guardian','principal_id'=>22,'guardian_valid'=>true,'guardian_student_id'=>22]))['authorised'], 'active guardian authorised');
$fail(!$policy->evaluate(array_merge($base,['withdrawal_blocking'=>true]))['authorised'], 'withdrawal blocks playback');
$fail(!$policy->evaluate(array_merge($base,['archive_available'=>false]))['authorised'], 'offline archive denies grant');
$fail(!$policy->evaluate(array_merge($base,['recording_state'=>L::QUARANTINED]))['authorised'], 'quarantined recording denied');

// Signed media evidence.
$media = new RecordingMediaEvidencePolicy();
$evidence = ['egress_complete'=>true,'file_closed'=>true,'format'=>'mp4','video_streams'=>1,'audio_streams'=>1,'video_codec'=>'h264','audio_codec'=>'aac','duration_ms'=>3000,'source_bytes'=>17202,'size_stable'=>true,'sha256'=>hash('sha256','media')];
$fail($media->evaluate($evidence, 3) === ['verified'=>true,'reason'=>'verified'], 'complete media evidence verified');
foreach (['egress_complete'=>false] as $key=>$value) $fail(!$media->evaluate(array_merge($evidence,[$key=>$value]), 3)['verified'], 'incomplete Egress denied');
$fail(!$media->evaluate(array_merge($evidence,['file_closed'=>false]), 3)['verified'], 'open file denied');
$fail(!$media->evaluate(array_merge($evidence,['video_streams'=>0]), 3)['verified'], 'missing video denied');
$fail(!$media->evaluate(array_merge($evidence,['audio_codec'=>'opus']), 3)['verified'], 'wrong audio codec denied');
$fail(!$media->evaluate(array_merge($evidence,['duration_ms'=>300]), 3)['verified'], 'implausible duration denied');
$fail(!$media->evaluate(array_merge($evidence,['size_stable'=>false]), 3)['verified'], 'unstable size denied');

// Bounded keyed envelope.
$secret = str_repeat('k', 64); $now = time();
$envelope = RecordingSignature::sign(['a'=>1,'b'=>['c'=>'متن']], $secret, 'key-1', 'recording-service-command', $now, 300);
$verified = RecordingSignature::verify($envelope, $secret, 'recording-service-command', $now);
$fail($verified['payload']['a'] === 1, 'signed envelope verifies');
$tampered = $envelope; $tampered['payload']['a'] = 2;
try { RecordingSignature::verify($tampered, $secret, 'recording-service-command', $now); $fail(false, 'tampered payload accepted'); } catch (RuntimeException $e) { $fail(str_contains($e->getMessage(), 'payload_mismatch'), 'tamper reason'); }
try { RecordingSignature::sign(['a'=>1], $secret, 'key-1', 'other', $now, 901); $fail(false, 'overlong signature accepted'); } catch (InvalidArgumentException $e) { $fail($e->getMessage() === 'recording_signing_ttl_invalid', 'ttl bound'); }

echo "Recording archive policy unit: PASS\n";
