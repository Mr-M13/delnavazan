<?php
/**
 * Source contract for No-reservation (Option B) and the payment-secured slot.
 *
 * An unpaid introductory acceptance reserves nothing. Capacity is rechecked and
 * secured only when the verified payment lands; a lost race fails the
 * confirmation, keeps the obligation paid as a credit and opens coordination.
 */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$expect = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$contains = static function (string $haystack, array $needles, string $message) use ($expect): void {
    foreach ($needles as $needle) $expect(str_contains($haystack, $needle), $message . ' Missing: ' . $needle);
};

$occupancyQuery = $read('src/Core/Infrastructure/Repository/TeacherBookingOccupancyQuery.php');
$canonical = $read('src/Core/Application/CanonicalTeacherOccupancyReadService.php');
$bookingOccupancy = $read('src/Core/Application/BookingTeacherOccupancyReadService.php');
$allocation = $read('src/Core/Application/BookingAllocationService.php');
$response = $read('src/Core/Application/BookingTeacherResponseService.php');
$payment = $read('src/Core/Application/Finance/Intro/IntroPaymentService.php');
$checkout = $read('src/Core/Application/Finance/Intro/IntroCheckoutInitiationService.php');
$repo = $read('src/Core/Infrastructure/Repository/IntroPaymentRepository.php');
$worker = $read('src/Integrations/IntroPaymentExpiryWorker.php');
$plugin = $read('delnavazan-platform.php');
$migration = $read('src/Core/Infrastructure/Migration/Migrator.php');
$catalogue = $read('src/Integrations/Notifications/NotificationCatalogue.php');
$studentWorker = $read('src/Integrations/BookingStudentNotificationWorker.php');
$portal = $read('src/Portals/PortalAuthenticatedReadService.php');
$view = (string) file_get_contents(dirname($root) . '/theme/template-parts/portal/booking-requests.php');

// 1. Unpaid intros reserve nothing; offers still reserve briefly.
$contains($occupancyQuery, array("c.state NOT IN ('failed','cancelled','awaiting_payment')", "o.state='pending'"), 'An unpaid acceptance must not reserve Teacher capacity while a pending offer still does.');
$contains($canonical, array('TeacherBookingOccupancyQuery::committedConfirmationConflicts'), 'Committed confirmations must re-enter canonical capacity through the committed-only query.');
$contains($occupancyQuery, array("c.state IN ('pending_projection','awaiting_conference','retryable','confirmed')"), 'Only committed confirmations may re-enter canonical capacity.');
$expect(!str_contains($canonical, 'TeacherBookingOccupancyQuery::conflicts('), 'The canonical seam must never append pending offers.');
$contains($canonical, array('$excludeBookingRequestId'), 'The canonical seam must pass through the request exclusion.');
$contains($bookingOccupancy, array('$this->canonical->inRange($teacherId,$fromUtc,$untilUtc,$excludeLessonId,$excludeBookingRequestId)', '$unique['), 'The booking occupancy seam must include committed confirmations without double counting.');

// 2. Retirement happens at slot-securing, not at an unpaid acceptance.
$contains($response, array('acceptedLocked($delivery,$now,!$paid)', 'acceptedLocked($locked,$now,!$paid)', 'administratorAcceptedLocked($delivery,$now,!$paid)', "'starts_at_utc'=>(string)\$delivery->starts_at_utc"), 'Only a committed winner may retire competing offers, and acceptance must supply the class start for the payment deadline.');
$contains($allocation, array('if($slotSecured)$this->retireCompetingOffers', 'public function assertPaidSlotAvailable(', 'public function retireOverlappingOffers(', 'CanonicalTeacherOccupancyReadService())->overlapping($teacherId,$start,$end,0,$requestId)', 'return null;'), 'Payment must recheck committed capacity before retiring offers.');

// 3. The payment path secures or fails safely; the money is never lost.
$contains($payment, array(
    'assertPaidSlotAvailable(',
    'retireOverlappingOffers(',
    "'slot_taken_after_payment'",
    "'payment_slot_conflict'",
    "'payment_conflict'",
), 'Payment settlement must recheck the slot, fail the confirmation on conflict and open coordination.');
$expect(!preg_match('/slot_taken_after_payment.*STATE_REFUNDED/s', $payment), 'A lost payment race must keep the obligation paid as a credit, never refund it.');
$contains($payment, array('public static function paymentDueAt', 'PAYMENT_DEADLINE_SECONDS', "'payment_due_at' => \$paymentDueAt"), 'Every obligation must be created with min(created_at + 7 days, class start).');
$contains($repo, array('payment_due_at<=%s', 'state=\'pending\''), 'The expiry pass must select only past-due pending obligations.');
$contains($checkout, array('payment_due_at', 'checkout_unavailable'), 'A lapsed payment gate must not open a new checkout session.');

// 4. The expiry worker is registered on the existing two-minute cron.
$contains($worker, array("'dzn_two_minutes'", 'dueObligations', 'expireLapsedObligation'), 'The expiry worker must follow the bounded scheduled-pass pattern.');
$contains($plugin, array('IntroPaymentExpiryWorker::register()'), 'The expiry worker must be registered.');
$contains($migration, array('080_intro_payment_expiry', 'payment_due_at datetime NULL', 'verify_intro_payment_expiry_schema'), 'The deadline column must be migrated and verified.');
$contains($migration, array("WHERE state='pending' AND payment_due_at IS NULL"), 'Pre-migration pending obligations must be backfilled, never left without a deadline.');

// 5. Catalogue + producer wiring for the four new events.
foreach (array('booking.payment_lapsed.student', 'booking.payment_lapsed.teacher', 'booking.payment_conflict.student', 'booking.payment_conflict.admin') as $event) {
    $expect(str_contains($catalogue, "self::event('" . $event . "'"), 'The catalogue must define ' . $event . '.');
}
$contains($catalogue, array('مهلت پرداخت', 'payment deadline'), 'New events must carry Persian and English copy.');
$contains($studentWorker, array(
    "notification_kind==='payment_lapsed')",
    "notification_kind==='payment_lapsed_teacher')",
    "notification_kind==='payment_conflict')",
    'website_email_only',
), 'The booking worker must map the new kinds and keep them off WhatsApp/SMS.');

// 6. The Student portal exposes the deadline and the lapsed label.
$contains($portal, array('intro.payment_due_at', "'payment_due_at'"), 'The portal read model must expose the payment deadline.');
$contains($view, array('payment_deadline_lapsed', 'مهلت پرداخت گذشته'), 'The Student portal must show that the payment deadline lapsed.');

// 7. Student occupancy is deliberately untouched: a Student's own unpaid intro still blocks them.
$studentOccupancy = $read('src/Core/Infrastructure/Repository/StudentOccupancyQuery.php');
$contains($studentOccupancy, array("'pending_projection','awaiting_conference','retryable','awaiting_payment','confirmed'"), 'Student occupancy keeps the unpaid intro self-scoped.');

echo "Booking payment slot security contract passed\n";
