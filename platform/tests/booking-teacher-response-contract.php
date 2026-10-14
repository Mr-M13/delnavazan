<?php
/** Source contract for teacher-owned introductory booking responses and provider-first confirmation. */
$root = dirname(__DIR__);
$theme = dirname($root) . '/theme';
$read = static fn(string $path): string => (string) file_get_contents($path);
$files = array(
    'plugin' => $read($root . '/delnavazan-platform.php'),
    'migration' => $read($root . '/src/Core/Infrastructure/Migration/Migrator.php'),
    'service' => $read($root . '/src/Core/Application/BookingTeacherResponseService.php'),
    'repository' => $read($root . '/src/Core/Infrastructure/Repository/BookingTeacherResponseRepository.php'),
    'projection' => $read($root . '/src/Integrations/BookingCalendarProjectionService.php'),
    'worker' => $read($root . '/src/Integrations/BookingCalendarProjectionWorker.php'),
    'notification' => $read($root . '/src/Integrations/BookingStudentNotificationWorker.php'),
    'controller' => $read($root . '/src/Portals/TeacherBookingResponseController.php'),
    'bridge' => $read($theme . '/inc/platform-bridge.php'),
    'portal' => $read($theme . '/inc/teacher-portal.php'),
    'wizard' => $read($theme . '/template-parts/teacher-portal/onboarding.php'),
    'attention' => $read($theme . '/template-parts/teacher-portal/attention.php'),
);
$checks = array(
    'schema 43 and migration are registered' => str_contains($files['plugin'], "DZN_PLATFORM_SCHEMA_VERSION', '43'") && str_contains($files['migration'], '043_booking_teacher_response') && str_contains($files['migration'], 'verify_booking_teacher_response_schema'),
    'response persistence is durable and idempotent' => str_contains($files['migration'], 'booking_request_confirmations') && str_contains($files['migration'], 'booking_request_teacher_commands') && str_contains($files['migration'], 'booking_request_student_notifications') && str_contains($files['migration'], 'UNIQUE KEY delivery_kind') && str_contains($files['migration'], 'UNIQUE KEY command_key_digest'),
    'teacher authority owns the response' => str_contains($files['service'], 'get_current_user_id()') && str_contains($files['repository'], 'l.wordpress_user_id=%d') && str_contains($files['service'], '(int)$delivery->teacher_id!==(int)$teacher->id'),
    'acceptance requires current readiness and a verified scoped connection' => str_contains($files['service'], "readiness_state!=='ready'") && str_contains($files['repository'], "c.identity_state='verified'") && str_contains($files['repository'], 'calendar.events') && str_contains($files['repository'], 'meetings.space.created'),
    'idempotent replay observes the live confirmation state' => str_contains($files['service'], 'function replay(') && str_contains($files['service'], '$this->repository->confirmation($confirmationId)') && substr_count($files['service'], '$this->repository->command($keyDigest)') >= 2,
    'calendar and Meet precede confirmation' => str_contains($files['projection'], "empty(\$provider['join_uri_reference'])") && str_contains($files['projection'], "'state'=>'confirmed'") && strpos($files['projection'], "'state'=>'confirmed'") < strpos($files['projection'], "'notification_kind'=>'confirmed'"),
    'provider writes are deterministic and vaulted' => str_contains($files['projection'], 'dzn:booking-confirmation:') && str_contains($files['projection'], 'dzn-meet:booking-confirmation:') && str_contains($files['projection'], "seal('booking_calendar'") && !str_contains($files['migration'], 'join_uri_reference varchar'),
    'retry worker uses the least-privilege provider principal' => str_contains($files['worker'], 'ProviderProjectionWorkerContext::principalId()') && str_contains($files['worker'], 'new ProviderProjectionWorkerContext())->run'),
    'student notification opens only the vaulted confirmed reference' => str_contains($files['notification'], "open('booking_calendar'") && str_contains($files['notification'], 'wp_mail(') && str_contains($files['notification'], "notification_kind==='declined'"),
    'portal actions are nonce protected' => str_contains($files['controller'], "check_admin_referer('dzn_teacher_booking_response')") && str_contains($files['attention'], 'response_nonce') && str_contains($files['attention'], 'delivery_uid'),
    'Google readiness is reachable in the onboarding wizard' => str_contains($files['bridge'], 'min(5') && str_contains($files['portal'], "current_step'] > 5") && str_contains($files['wizard'], '$current === 4') && str_contains($files['wizard'], '$current === 5') && str_contains($files['wizard'], 'اتصال Google Calendar'),
);
foreach ($checks as $name => $ok) {
    if (!$ok) throw new RuntimeException('Booking teacher response contract failed: ' . $name);
}
echo 'Booking teacher response contracts passed (' . count($checks) . ").\n";
