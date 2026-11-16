<?php
/**
 * Source contract for the teacher invitation SMS leg.
 *
 * The send orchestration lives in a WordPress worker, so the source is the executable evidence: the
 * leg must route only through SMS.ir (never a Telnyx fallback), gate on IntegrationSettingsService
 * `verified('sms_ir')`, record its outcome independently of the email outbox terminal state, and never
 * write the rendered body — which contains the one-time code — to a log or audit surface.
 */
$root = dirname(__DIR__);
$worker = (string) file_get_contents($root . '/src/Core/Application/TeacherInvitationDeliveryWorker.php');
$repository = (string) file_get_contents($root . '/src/Core/Infrastructure/Repository/PrincipalInvitationRepository.php');
$catalogue = (string) file_get_contents($root . '/src/Integrations/Notifications/NotificationCatalogue.php');
$service = (string) file_get_contents($root . '/src/Core/Application/PrincipalInvitationService.php');

$check = static function (bool $ok, string $msg): void { if (!$ok) throw new RuntimeException('teacher-invitation-sms-contract: ' . $msg); };

/* 1. SMS.ir is the only Iran route; never a Telnyx fallback. */
$check(str_contains($worker, 'SmsIrAdapter'), 'the SMS leg uses the SMS.ir adapter');
$check(!str_contains($worker, 'TelnyxSmsAdapter') && !str_contains($worker, 'Telnyx'), 'the SMS leg never falls back to Telnyx');
$check(str_contains($worker, "verified('sms_ir')"), 'SMS.ir readiness is gated on IntegrationSettingsService::verified');

/* 2. Skip reasons are stable and recorded. */
foreach (array('no_phone', 'invalid_number', 'non_iranian', 'sms_ir_not_configured') as $reason) {
    $check(str_contains($worker, "'{$reason}'"), 'skip reason must be recorded: ' . $reason);
}
$check(str_contains($worker, 'PhoneNumberNormalizer'), 'the phone is normalised to E.164 before routing');
$check(str_contains($worker, "str_starts_with(\$normalized,'+98')"), 'the Iranian E.164 prefix check is present');
$check(str_contains($worker, "strtoupper((string)\$countryCode)==='IR'"), 'country_code IR is accepted as Iranian');

/* 3. The SMS outcome is recorded separately from the email outbox terminal state. */
$check(str_contains($repository, 'recordSmsDelivery'), 'the repository exposes a dedicated SMS-outcome recorder');
$check(str_contains($repository, 'teacher_invitation.sms_'), 'the SMS outcome is recorded as a distinct audit event');
$check(str_contains($worker, 'finishPreparedDelivery') && str_contains($worker, 'attemptSms'), 'the email terminal state is recorded before the SMS leg');
$check(str_contains($worker, 'sms_ir_exception'), 'an SMS failure is recorded but never fails the invitation');

/* 4. The one-time code (part of the SMS body) is never logged. */
$check(str_contains($worker, 'unset($text)'), 'the rendered SMS body is discarded immediately after send');
$check(!str_contains($worker, 'error_log'), 'the SMS body is never written to the log');

/* 5. The prepared delivery exposes the teacher id for the SMS leg. */
$check(str_contains($service, "'teacher_id'=>(int)\$parent->teacher_id"), 'prepareDelivery exposes the teacher id');

/* 6. The catalogue declares sms for the teacher invitation. */
$check(str_contains($catalogue, "array('email','sms')"), 'the teacher invitation catalogue entry declares the sms channel');

echo "teacher-invitation-sms-contract: pass\n";
