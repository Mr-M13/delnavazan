<?php
/**
 * Source contract for the re-offer lift: offer/decline rows carry the requested-
 * time set version, the same set keeps the ban and a new set lifts only the
 * times_unsuitable decline.
 */
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$expect = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$contains = static function (string $source, array $needles, string $message) use ($expect): void {
    foreach ($needles as $needle) $expect(str_contains($source, $needle), $message . ' Missing: ' . $needle);
};

$migration = $read('src/Core/Infrastructure/Migration/Migrator.php');
$allocationRepo = $read('src/Core/Infrastructure/Repository/BookingAllocationRepository.php');
$responseRepo = $read('src/Core/Infrastructure/Repository/BookingTeacherResponseRepository.php');
$allocation = $read('src/Core/Application/BookingAllocationService.php');
$response = $read('src/Core/Application/BookingTeacherResponseService.php');
$openRepo = $read('src/Core/Infrastructure/Repository/TeacherOpenBookingRequestRepository.php');

// 1. The schema versions both ledgers and backfills existing rows.
$contains($migration, array(
    '082_booking_reoffer_lift',
    "ADD COLUMN time_set_version int unsigned NOT NULL DEFAULT 1",
    'DROP INDEX request_teacher',
    'ADD UNIQUE KEY request_teacher_version(booking_request_id,teacher_id,time_set_version)',
    'SET time_set_version=COALESCE((SELECT MAX(rt.version)',
    'verify_booking_reoffer_lift_schema',
), 'The re-offer migration must widen the offer/decline keys and backfill the current time-set version.');

// 2. The offer ledger reads, writes and excludes by version.
$contains($allocationRepo, array(
    'currentTimeSetVersion',
    'historyForVersion',
    'o.time_set_version=%d',
    "'time_set_version'=>max(1,\$timeSetVersion)",
    'stickyDeclinedTeacherIds',
    "reason_code<>'times_unsuitable' OR time_set_version>=%d",
    'time_set_version=(SELECT COALESCE(MAX(rt.version),1)',
), 'The allocation ledger must scope offers and declines to the current time-set version.');

// 3. The service uses one version per decision and never re-offers a sticky decline.
$contains($allocation, array(
    '$timeSetVersion=$this->repo->currentTimeSetVersion($requestId)',
    'historyForVersion($requestId,$timeSetVersion)',
    'stickyDeclinedTeacherIds($requestId,$timeSetVersion)',
    'startOffer($requestId,$priority[\'candidate\'],$priority[\'deadline\'],$now,$timeSetVersion)',
    'startOffer($requestId,$chosen,$deadline,$now,$timeSetVersion)',
    'addOffer($requestId,$deliveryId,$candidate,1,$now,$deadline,$timeSetVersion)',
    'addOffer($requestId,$deliveryId,$next,(int)$offer->offer_sequence+1,$now,$nextDeadline,$timeSetVersion)',
    'historicalOffer($requestId,$delivery,$deadline,$this->repo->currentTimeSetVersion($requestId))',
), 'Every automated offer must record the current time-set version.');

// 4. The claim path reads the current set and writes it to the claim offer.
$contains($responseRepo, array(
    'offerForTeacher',
    'time_set_version=(SELECT COALESCE(MAX(rt.version),1)',
    'declineForTeacher',
    "reason_code<>'times_unsuitable' OR time_set_version>=",
    'insertClaimOffer',
    "'time_set_version'=>max(1,\$timeSetVersion)",
    'recordDecline',
    'time_set_version=%d',
), 'The claim path and decline evidence must use the current time-set version.');
$contains($response, array(
    '$timeSetVersion=$this->repository->currentTimeSetVersion($requestId)',
    'insertClaimOffer($requestId,$deliveryId,(int)$teacher->id,$requestedTimeId,$now,$deadline,$timeSetVersion)',
), 'A claim must record the requested-time set version it was made against.');

// 5. The open board hides only prior offers/declines from the current set,
//    while sticky declines remain hidden across sets.
$contains($openRepo, array(
    'prior.time_set_version=COALESCE((SELECT MAX(prior_time.version)',
    "declined.reason_code<>'times_unsuitable' OR declined.time_set_version>=COALESCE((SELECT MAX(decline_time.version)",
), 'The open board must apply the same version-aware offer and decline exclusions.');

// 6. The new unique keys replace the old per-request/Teacher ban.
$expect(str_contains($migration, "the old request_teacher key must be retired"), 'The schema verification must retire the old single-version ban.');

echo "Booking offer re-offer contract passed\n";
