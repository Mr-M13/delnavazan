<?php
/**
 * Teacher invitation SMS leg — pure unit test.
 *
 * Exercises the Iranian eligibility decision and the SMS-body render without WordPress, a database or
 * a transport. The worker's send orchestration is covered by the source contract; these two methods
 * are the only pieces that can be exercised as pure functions.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/Integrations/Notifications/NotificationCatalogue.php';
require_once $root . '/src/Integrations/Notifications/PhoneNumberNormalizer.php';
require_once $root . '/src/Integrations/Notifications/TeacherInvitationPresentation.php';
require_once $root . '/src/Core/Application/TeacherInvitationDeliveryWorker.php';

use Delnavazan\Platform\Core\Application\TeacherInvitationDeliveryWorker;
use Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue;

$check = static function (bool $ok, string $msg): void { if (!$ok) throw new RuntimeException('teacher-invitation-sms-unit: ' . $msg); };

/* 1. Iranian phone → eligible (null skip) */
$check(TeacherInvitationDeliveryWorker::smsSkipReason('+989121234567', 'IR', true) === null, 'Iranian E.164 phone must be eligible');
$check(TeacherInvitationDeliveryWorker::smsSkipReason('09121234567', 'IR', true) === null, 'Iranian local phone normalises to +98 and must be eligible');
$check(TeacherInvitationDeliveryWorker::smsSkipReason('00989121234567', 'IR', true) === null, 'Iranian 0098 phone normalises to +98 and must be eligible');
$check(TeacherInvitationDeliveryWorker::smsSkipReason('+989121234567', '', true) === null, 'Iranian +98 prefix is enough without a country code');
$check(TeacherInvitationDeliveryWorker::smsSkipReason('+61400000000', 'IR', true) === null, 'country_code IR marks the teacher Iranian even with a non-+98 number');

/* 2. non-Iranian → no SMS */
$check(TeacherInvitationDeliveryWorker::smsSkipReason('+61400000000', 'AU', true) === 'non_iranian', 'non-Iranian number and country must skip');
$check(TeacherInvitationDeliveryWorker::smsSkipReason('+61400000000', '', true) === 'non_iranian', 'non-Iranian number without a country must skip');
$check(TeacherInvitationDeliveryWorker::smsSkipReason('+989121234567', 'AU', true) === null, 'a +98 number stays Iranian even with a non-IR country code');

/* 3. missing / invalid phone → no SMS */
$check(TeacherInvitationDeliveryWorker::smsSkipReason(null, 'IR', true) === 'no_phone', 'missing phone must skip with no_phone');
$check(TeacherInvitationDeliveryWorker::smsSkipReason('', 'IR', true) === 'no_phone', 'empty phone must skip with no_phone');
$check(TeacherInvitationDeliveryWorker::smsSkipReason('not-a-number', 'IR', true) === 'invalid_number', 'invalid phone must skip with invalid_number');

/* 4. sms_ir not configured → no SMS with recorded reason */
$check(TeacherInvitationDeliveryWorker::smsSkipReason('+989121234567', 'IR', false) === 'sms_ir_not_configured', 'unconfigured SMS.ir must skip with sms_ir_not_configured');
$check(TeacherInvitationDeliveryWorker::smsSkipReason('+989121234567', '', false) === 'sms_ir_not_configured', 'Iranian number without SMS.ir config must skip');

/* 5. Rendered SMS body contains the code and URL and matches the catalogue copy exactly. */
$code = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
$url = 'https://example.invalid/teacher-invitation/';
$rendered = TeacherInvitationDeliveryWorker::smsBody($url, $code);
$catalogueBody = (string) NotificationCatalogue::exactCopy('teacher.invitation_issued.teacher', 'sms', 'fa-IR')['body'];
$check(str_contains($rendered, $code), 'rendered SMS must contain the one-time code');
$check(str_contains($rendered, $url), 'rendered SMS must contain the claim URL');
$check($rendered === strtr($catalogueBody, array('{{invitation_code}}' => $code, '{{claim_url}}' => $url)), 'rendered SMS must equal the catalogue template rendering');

echo "teacher-invitation-sms-unit: pass\n";
