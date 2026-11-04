<?php
/** Pure hardening proof for recording privacy, co-host readiness and playback access. */
$root = dirname(__DIR__);
require_once $root . '/src/Integrations/GoogleWorkspace/GoogleWorkspaceClassroomRule.php';
require_once $root . '/src/Integrations/GoogleWorkspace/MeetRecordingPrivacyRule.php';
require_once $root . '/src/Integrations/GoogleWorkspace/MeetCoHostRule.php';
require_once $root . '/src/Integrations/GoogleWorkspace/MeetRecordingAccessRule.php';

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
use Delnavazan\Platform\Integrations\GoogleWorkspace\MeetRecordingPrivacyRule as Privacy;
use Delnavazan\Platform\Integrations\GoogleWorkspace\MeetCoHostRule as CoHost;
use Delnavazan\Platform\Integrations\GoogleWorkspace\MeetRecordingAccessRule as Access;

$private = Privacy::classify(array(array('type'=>'user','role'=>'owner','emailAddress'=>'classroom@delnavazan.com')), 'classroom@delnavazan.com');
$assert($private['state'] === Privacy::VERIFIED_PRIVATE, 'organizer-only permission must be verified private');
$assert(Privacy::canPlayback($private['state']), 'verified private must allow playback');
$assert(Privacy::alertCode($private['state']) === null, 'verified private must not alert');

$public = Privacy::classify(array(array('type'=>'anyone','role'=>'reader')), 'classroom@delnavazan.com');
$assert($public['state'] === Privacy::PUBLIC_SHARED, 'anyone permission must be public');
$assert(!Privacy::canPlayback($public['state']), 'public sharing must deny playback');
$assert(Privacy::alertCode($public['state']) === 'recording_privacy_public', 'public sharing must alert');

$domain = Privacy::classify(array(array('type'=>'domain','domain'=>'delnavazan.com','role'=>'reader')), 'classroom@delnavazan.com');
$assert($domain['state'] === Privacy::DOMAIN_SHARED, 'domain sharing must not be treated as private');
$assert(!Privacy::canPlayback($domain['state']), 'domain sharing must deny playback');

$other = Privacy::classify(array(
    array('type'=>'user','role'=>'owner','emailAddress'=>'classroom@delnavazan.com'),
    array('type'=>'user','role'=>'reader','emailAddress'=>'other@example.invalid'),
), 'classroom@delnavazan.com');
$assert($other['state'] === Privacy::SHARED_WITH_OTHERS, 'another user must be unintended sharing');
$assert(!Privacy::canPlayback($other['state']), 'additional user must deny playback');

$empty = Privacy::classify(array(), 'classroom@delnavazan.com');
$assert($empty['state'] === Privacy::VERIFICATION_FAILED && $empty['reason'] === 'permissions_empty', 'empty permissions must fail closed');
$malformed = Privacy::classify(array('not-a-permission'), 'classroom@delnavazan.com');
$assert($malformed['state'] === Privacy::VERIFICATION_FAILED, 'malformed permissions must fail closed');
$noOrganizer = Privacy::classify(array(array('type'=>'user','role'=>'reader','emailAddress'=>'other@example.invalid')), 'classroom@delnavazan.com');
$assert($noOrganizer['state'] === Privacy::SHARED_WITH_OTHERS, 'missing organizer must not be private');
$failure = Privacy::failure('drive_permissions_unavailable');
$assert($failure['state'] === Privacy::VERIFICATION_FAILED && !Privacy::canPlayback($failure['state']), 'API refusal must fail closed');
$transient = Privacy::failure('transport_unavailable');
$assert($transient['state'] === Privacy::VERIFICATION_FAILED && Privacy::alertCode($transient['state']) === 'recording_privacy_unverified', 'transient permissions failure must remain unverified and alert');
$missing = Privacy::failure('drive_permissions_missing');
$assert($missing['state'] === Privacy::VERIFICATION_FAILED && $missing['permissions_count'] === 0, 'missing permissions data must not be private');

$assert(CoHost::isReady('ready', CoHost::VERIFIED), 'verified co-host is ready');
$assert(CoHost::isReady('ready', CoHost::EXCEPTION_APPROVED), 'approved exception is ready');
$assert(!CoHost::isReady('ready', CoHost::PENDING), 'pending co-host is not ready');
$assert(!CoHost::isReady('ready', CoHost::RETRYABLE), 'retryable co-host is not ready');
$assert(!CoHost::isReady('ready', CoHost::BLOCKED), 'blocked co-host is not ready');
$assert(!CoHost::canSilentlyDowngrade(), 'no silent participant downgrade may exist');
$assert(CoHost::isRetryableStatus(0) && CoHost::isRetryableStatus(429) && CoHost::isRetryableStatus(503), 'transient statuses must retry');
$assert(!CoHost::isRetryableStatus(403) && !CoHost::isRetryableStatus(400), 'permanent refusals must not retry');
$assert(CoHost::isRetryableErrorClass('rate_limit_exceeded') && CoHost::isRetryableErrorClass('backend_error'), 'transient error classes must retry');
$assert(!CoHost::isRetryableErrorClass('permission_denied'), 'permission denial must not retry');
$assert(CoHost::retryDelaySeconds(1) === 60 && CoHost::retryDelaySeconds(8) === 3600, 'retry backoff must stay bounded');
$assert(CoHost::exceptionAllowed(7, 'Documented operational reason', '1'), 'documented admin exception must pass');
$assert(!CoHost::exceptionAllowed(7, 'short', '1'), 'short exception reason must fail');
$assert(!CoHost::exceptionAllowed(0, 'Documented operational reason', '1'), 'exception requires an approver');

$base = array('actor_user_id'=>10,'role'=>'student','purpose'=>Access::STUDENT_REVIEW,'owns_lesson'=>true,'consent_current'=>true,'privacy_state'=>Privacy::VERIFIED_PRIVATE,'artifact_available'=>true,'playback_verified'=>true,'capabilities'=>array(),'reason'=>'');
$allow = Access::decide($base);
$assert($allow['allowed'] && $allow['media_allowed'], 'owning student with current consent and private storage may play');
$notPlayback = Access::decide(array_merge($base, array('playback_verified'=>false)));
$assert(!$notPlayback['allowed'] && $notPlayback['reason'] === 'recording_not_playback_verified', 'unverified playback must deny');
$noConsent = Access::decide(array_merge($base, array('consent_current'=>false)));
$assert(!$noConsent['allowed'] && $noConsent['reason'] === 'recording_consent_not_current', 'revoked consent must deny playback');
$notPrivate = Access::decide(array_merge($base, array('privacy_state'=>Privacy::PUBLIC_SHARED)));
$assert(!$notPrivate['allowed'] && $notPrivate['reason'] === 'recording_privacy_not_verified', 'unverified privacy must deny playback');
$notOwner = Access::decide(array_merge($base, array('owns_lesson'=>false)));
$assert(!$notOwner['allowed'], 'non-owner must be denied');
$teacherMismatch = Access::decide(array_merge($base, array('purpose'=>Access::TEACHER_REVIEW)));
$assert(!$teacherMismatch['allowed'], 'student purpose must not open teacher access');

$operational = Access::decide(array_merge($base, array(
    'role'=>'administrator','purpose'=>Access::ADMIN_OPERATIONAL,'owns_lesson'=>false,
    'capabilities'=>array('dzn_view_recording_operations'),
)));
$assert(!$operational['allowed'] && !$operational['media_allowed'] && $operational['reason'] === 'metadata_only', 'ordinary administrator must not receive media');
$manageOptions = Access::decide(array_merge($base, array('role'=>'administrator','purpose'=>Access::ADMIN_OPERATIONAL,'owns_lesson'=>false,'capabilities'=>array('manage_options'))));
$assert(!$manageOptions['allowed'] && $manageOptions['reason'] === 'metadata_only', 'manage_options grants metadata only, never media');
$ordinaryAdmin = Access::decide(array_merge($base, array('role'=>'administrator','purpose'=>Access::ADMIN_OPERATIONAL,'owns_lesson'=>false,'consent_current'=>false,'privacy_state'=>Privacy::VERIFICATION_FAILED)));
$assert(!$ordinaryAdmin['media_allowed'], 'administrator must not bypass consent or privacy without compliance capability');

$compliance = Access::decide(array_merge($base, array(
    'role'=>'administrator','purpose'=>Access::COMPLIANCE_REVIEW,'owns_lesson'=>false,
    'consent_current'=>false,'privacy_state'=>Privacy::VERIFICATION_FAILED,
    'capabilities'=>array('dzn_manage_recording_legal_hold'),
    'reason'=>'Incident review reference IR-2026-11-04-01',
)));
$assert($compliance['allowed'] && $compliance['media_allowed'], 'documented compliance capability may review media');
$noCapability = Access::decide(array_merge($base, array('role'=>'administrator','purpose'=>Access::COMPLIANCE_REVIEW,'owns_lesson'=>false,'capabilities'=>array(),'reason'=>'Incident review reference IR-2026-11-04-01')));
$assert(!$noCapability['allowed'] && $noCapability['reason'] === 'compliance_capability_required', 'compliance access requires an existing capability');
$shortReason = Access::decide(array_merge($base, array('role'=>'administrator','purpose'=>Access::COMPLIANCE_REVIEW,'owns_lesson'=>false,'capabilities'=>array('dzn_manage_recording_operations'),'reason'=>'short')));
$assert(!$shortReason['allowed'], 'compliance access requires a documented reason');
$research = Access::decide(array_merge($base, array('purpose'=>Access::RESEARCH_DATASET,'role'=>'administrator','capabilities'=>array('dzn_manage_recording_operations'))));
$assert(!$research['allowed'] && $research['reason'] === 'dataset_authority_required', 'research dataset access must stay separate');

echo "Google Meet hardening unit passed\n";
