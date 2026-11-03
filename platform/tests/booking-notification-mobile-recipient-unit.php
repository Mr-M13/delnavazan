<?php
/** Canonical mobile recipient selection for booking notifications. */
require_once dirname(__DIR__).'/src/Integrations/Notifications/PhoneNumberNormalizer.php';
require_once dirname(__DIR__).'/src/Integrations/BookingNotificationMobileDispatcher.php';

use Delnavazan\Platform\Integrations\BookingNotificationMobileDispatcher;

$reflect = new ReflectionClass(BookingNotificationMobileDispatcher::class);
$dispatcher = $reflect->newInstanceWithoutConstructor();
$contact = $reflect->getMethod('contact');
$contact->setAccessible(true);
$resolve = static fn(object $row, string $audience): ?array => $contact->invoke($dispatcher, $row, $audience);
$assert = static function(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };

$student = (object) array(
    'student_id' => 7,
    'student_account_whatsapp' => '+61411111111',
    'student_account_phone' => '+61422222222',
    'student_whatsapp' => '+61433333333',
    'student_mobile' => '+61444444444',
);
$resolved = $resolve($student, 'student');
$assert($resolved === array('id' => 7, 'whatsapp' => '+61411111111', 'sms' => '+61422222222'), 'The current Student account WhatsApp and phone must win over legacy snapshot contacts.');

$legacy = (object) array('student_id' => 7, 'student_whatsapp' => '+61433333333', 'student_mobile' => '+61444444444');
$resolved = $resolve($legacy, 'student');
$assert($resolved === array('id' => 7, 'whatsapp' => '+61433333333', 'sms' => '+61444444444'), 'Legacy Student payload contacts must remain a valid fallback.');

$whatsappOnly = (object) array('student_id' => 7, 'student_account_whatsapp' => '+61411111111');
$resolved = $resolve($whatsappOnly, 'student');
$assert($resolved === array('id' => 7, 'whatsapp' => '+61411111111', 'sms' => '+61411111111'), 'A WhatsApp-only contact must supply the SMS fallback only when it is a usable number.');

$teacher = (object) array('teacher_id' => 9, 'teacher_whatsapp' => '+61455555555', 'teacher_phone' => '+61466666666');
$resolved = $resolve($teacher, 'teacher');
$assert($resolved === array('id' => 9, 'whatsapp' => '+61455555555', 'sms' => '+61466666666'), 'The Teacher mobile recipient must resolve from the Teacher record.');

$assert($resolve((object) array('student_id' => 7), 'student') === null, 'A Student without any usable contact must be reported as contact_unusable.');
$assert($resolve((object) array('student_id' => 7, 'student_account_phone' => 'not-a-number'), 'student') === null, 'An invalid contact must be reported as contact_unusable.');
$assert($resolve((object) array('teacher_whatsapp' => '+61455555555'), 'teacher') === null, 'A Teacher without an identity must not be used as a recipient.');

echo "Booking notification mobile recipient unit passed\n";
