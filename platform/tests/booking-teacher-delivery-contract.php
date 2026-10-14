<?php
$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/src/Core/Application/BookingTeacherDeliveryService.php');
$repo = (string) file_get_contents($root . '/src/Core/Infrastructure/Repository/BookingTeacherDeliveryRepository.php');
$submission = (string) file_get_contents($root . '/src/Core/Application/BookingRequestSubmissionService.php');
$migrator = (string) file_get_contents($root . '/src/Core/Infrastructure/Migration/Migrator.php');
$plugin = (string) file_get_contents($root . '/delnavazan-platform.php');
$dashboard = (string) file_get_contents($root . '/src/Admin/Controller/AcademyOperationsController.php');
$bridge = (string) file_get_contents(dirname($root) . '/theme/inc/platform-bridge.php');
$attention = (string) file_get_contents(dirname($root) . '/theme/template-parts/teacher-portal/attention.php');
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$assert(str_contains($plugin, "DZN_PLATFORM_SCHEMA_VERSION', '42'"), 'Booking delivery migration must advance the schema.');
foreach (array('booking_request_teacher_deliveries', 'UNIQUE KEY booking_request_id', 'KEY teacher_state') as $needle) {
    $assert(str_contains($migrator, $needle), 'Booking delivery migration contract missing: ' . $needle);
}
$assert(str_contains($submission, 'createTime') && str_contains($submission, 'BookingTeacherDeliveryService'), 'Booking intake must persist requested times and invoke Teacher delivery.');
$timeWrite = strpos($submission, 'createTime');
$deliveryWrite = strpos($submission, 'BookingTeacherDeliveryService())->deliver');
$commit = strrpos($submission, 'commit()');
$assert($timeWrite !== false && $deliveryWrite > $timeWrite && $commit > $deliveryWrite, 'Delivery must be created after requested times and inside the intake transaction.');
foreach (array('eligibleTeachers($courseId', 'effective($teacherId', 'overlapping(', 'assertNoActiveHold', "'pending_request'") as $needle) {
    $assert(str_contains($service, $needle), 'Teacher matching authority missing: ' . $needle);
}
$assert(str_contains($repo, 'WHERE d.teacher_id=%d') && str_contains($repo, "d.state='pending_request'"), 'Teacher inbox reads must be scoped to the signed-in Teacher and pending state.');
$assert(str_contains($repo, "r.resolution_state='unresolved'") && str_contains($repo, 'r.privacy_erased_at IS NULL'), 'Resolved or erased requests must not remain in the Teacher inbox.');
$assert(!str_contains($service, 'new CanonicalLesson') && !str_contains($service, 'new Google') && !str_contains($service, '->project('), 'Intake delivery must not create lessons or provider projections.');
$assert(str_contains($bridge, 'ownPending()') && str_contains($bridge, "'authoritative_request'=>true"), 'Teacher portal must use the teacher-scoped delivery read model.');
$assert(str_contains($attention, 'درخواست در انتظار بررسی') && str_contains($attention, 'کلاس تأییدشده نیست'), 'Teacher portal must distinguish a request from a confirmed class.');
$assert(str_contains($dashboard, "current_user_can('dzn_manage_onboarding')") && str_contains($dashboard, "state='pending_review'"), 'Academy approval alert must be narrowly capability-gated and count pending review submissions.');
echo "Booking teacher delivery contract passed\n";
