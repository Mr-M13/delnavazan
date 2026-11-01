<?php
/**
 * Source contract for Teacher-confirmed canonical delivery.
 *
 * The Teacher Portal may confirm an ended, owned occurrence, but it must not acquire a second
 * attendance authority. This contract proves the narrow seam: exact Teacher principal, exact
 * schedule version, ended occurrence, unresolved-review refusal, canonical delivered fact,
 * canonical completion, deterministic replay and no replacement/payment invention.
 */
$root = dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $file = $root . '/' . $relative;
    if (!is_file($file)) throw new RuntimeException('Missing required file: ' . $relative);
    return (string) file_get_contents($file);
};
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

$confirmation = $read('src/Core/Application/TeacherDeliveryConfirmationService.php');
$delivery = $read('src/Core/Application/CanonicalLessonDeliveryService.php');
$authority = $read('src/Core/Application/CanonicalLessonAuthorityService.php');
$controller = $read('src/Portals/TeacherDeliveryClaimController.php');
$notices = $read('../theme/inc/teacher-portal.php');

$assert(str_contains($confirmation, 'PortalPrincipalResolver'), 'The confirmation must resolve the authenticated Teacher principal');
$assert(str_contains($confirmation, 'hasActiveTeacherAuthority'), 'The confirmation must prove the active Teacher authority');
$assert(str_contains($confirmation, 'portal_object_not_owned'), 'The confirmation must refuse a Lesson owned by another Teacher');
$assert(str_contains($confirmation, 'schedule_version_conflict'), 'The confirmation must bind the exact schedule version');
$assert(str_contains($confirmation, 'occurrence_not_ended'), 'The confirmation must refuse before the canonical occurrence end');
$assert(str_contains($confirmation, "'open','ready_for_review','settlement_pending'"), 'Unresolved attendance review must block confirmation');
$assert(str_contains($confirmation, 'attendance_outcome_final'), 'A conflicting delivery outcome must block confirmation');
$assert(str_contains($confirmation, 'recordTeacherConfirmedDelivery'), 'Confirmation must record the canonical delivered fact');
$assert(str_contains($confirmation, 'completeTeacherConfirmedDelivery'), 'Confirmation must complete through canonical Lesson authority');
$assert(str_contains($confirmation, "'teacher-confirm-delivery:'") && str_contains($confirmation, "'teacher-confirm-complete:'"), 'Confirmation keys must be deterministic per Lesson and schedule version');
$assert(str_contains($confirmation, 'captureAfterCompletion'), 'Confirmation must use the existing Finance orchestration seam');
$assert(!str_contains($confirmation, 'replacement_allowance') && !str_contains($confirmation, 'payable_amount'), 'Confirmation must never create a replacement or financial amount');

$assert(str_contains($delivery, 'function recordTeacherConfirmedDelivery('), 'Delivery authority must expose a Teacher-confirmation path');
$assert(substr_count($delivery, "dzn_submit_own_delivery_claim") >= 2, 'The Teacher path must require the existing claim capability');
$assert(str_contains($delivery, 'portal_object_not_owned'), 'The delivery authority must independently re-check ownership under lock');
$assert(str_contains($authority, 'function completeTeacherConfirmedDelivery('), 'Lesson authority must expose a Teacher-confirmation completion path');
$assert(str_contains($authority, 'portal_object_not_owned'), 'Lesson authority must independently re-check ownership under lock');

$assert(str_contains($controller, "'class_completed'"), 'A successful confirmation must return its own outcome status');
$assert(str_contains($controller, 'completion_too_early'), 'A too-early confirmation must return a meaningful error');
$assert(str_contains($controller, 'completion_not_allowed'), 'A refused confirmation must return a meaningful error');
$assert(str_contains($controller, 'completion_unavailable'), 'A failed confirmation must return a meaningful error');
$assert(substr_count($controller, 'TeacherDeliveryConfirmationService') === 1, 'Delivered confirmation must use the canonical teacher-confirmation seam');

$assert(str_contains($notices, "'class_completed'"), 'The Teacher Portal must show the successful completion status');
$assert(str_contains($notices, "'completion_too_early'") && str_contains($notices, "'completion_not_allowed'"), 'The Teacher Portal must show controlled failure statuses');

echo "teacher-delivery-confirmation-contract: OK\n";
