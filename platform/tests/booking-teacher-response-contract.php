<?php
/** Source contract for teacher-owned introductory booking responses and provider-first confirmation. */
$root = dirname(__DIR__);
$theme = dirname($root) . '/theme';
$read = static fn(string $path): string => (string) file_get_contents($path);
$files = array(
    'plugin' => $read($root . '/delnavazan-platform.php'),
    'migration' => $read($root . '/src/Core/Infrastructure/Migration/Migrator.php'),
    'service' => $read($root . '/src/Core/Application/BookingTeacherResponseService.php'),
    'provider_service' => $read($root . '/src/Core/Application/ProviderIntegrationService.php'),
    'provider_rule' => $read($root . '/src/Core/Application/ProviderIntegrationRule.php'),
    'repository' => $read($root . '/src/Core/Infrastructure/Repository/BookingTeacherResponseRepository.php'),
    'onboarding_repository' => $read($root . '/src/Core/Infrastructure/Repository/TeacherOnboardingRepository.php'),
    'projection' => $read($root . '/src/Integrations/BookingCalendarProjectionService.php'),
    'refresh' => $read($root . '/src/Integrations/BookingGoogleCredentialRefresher.php'),
    'worker' => $read($root . '/src/Integrations/BookingCalendarProjectionWorker.php'),
    'notification' => $read($root . '/src/Integrations/BookingStudentNotificationWorker.php'),
    'oauth' => $read($root . '/src/Integrations/GoogleOAuthController.php'),
    'google_adapter' => $read($root . '/src/Integrations/GoogleCalendarMeetAdapter.php'),
    'controller' => $read($root . '/src/Portals/TeacherBookingResponseController.php'),
    'bridge' => $read($theme . '/inc/platform-bridge.php'),
    'portal' => $read($theme . '/inc/teacher-portal.php'),
    'wizard' => $read($theme . '/template-parts/teacher-portal/onboarding.php'),
    'attention' => $read($theme . '/template-parts/teacher-portal/attention.php'),
);
$checks = array(
    'schema 44+ and migrations are registered' => preg_match("/DZN_PLATFORM_SCHEMA_VERSION', '([0-9]+)'/", $files['plugin'], $schema) && (int) $schema[1] >= 44 && str_contains($files['migration'], '043_booking_teacher_response') && str_contains($files['migration'], '044_booking_notification_recovery') && str_contains($files['migration'], 'verify_booking_teacher_response_schema'),
    'response persistence is durable and idempotent' => str_contains($files['migration'], 'booking_request_confirmations') && str_contains($files['migration'], 'booking_request_teacher_commands') && str_contains($files['migration'], 'booking_request_student_notifications') && str_contains($files['migration'], 'UNIQUE KEY delivery_kind') && str_contains($files['migration'], 'UNIQUE KEY command_key_digest') && str_contains($files['migration'], 'lease_token_digest'),
    'teacher authority owns the response' => str_contains($files['service'], 'get_current_user_id()') && str_contains($files['repository'], 'l.wordpress_user_id=%d') && str_contains($files['service'], '(int)$delivery->teacher_id!==(int)$teacher->id'),
    'acceptance requires current readiness and byte-exactly the Calendar events scope' => str_contains($files['service'], "readiness_state!=='ready'") && str_contains($files['repository'], "c.identity_state='verified'") && str_contains($files['repository'], 'BINARY c.scope_snapshot = BINARY %s') && str_contains($files['onboarding_repository'], 'BINARY c.scope_snapshot = BINARY %s') && !str_contains($files['repository'].$files['onboarding_repository'], 'scope_snapshot LIKE'),
    'idempotent replay observes the live confirmation state' => str_contains($files['service'], 'function replay(') && str_contains($files['service'], '$this->repository->confirmation($confirmationId)') && substr_count($files['service'], '$this->repository->command($keyDigest)') >= 2,
    'calendar and Meet precede confirmation' => str_contains($files['projection'], "empty(\$provider['join_uri_reference'])") && str_contains($files['projection'], "'state'=>'confirmed'") && strpos($files['projection'], "'state'=>'confirmed'") < strpos($files['projection'], "'notification_kind'=>'confirmed'"),
    'provider writes are deterministic and vaulted' => str_contains($files['projection'], 'dzn:booking-confirmation:') && str_contains($files['projection'], 'dzn-meet:booking-confirmation:') && str_contains($files['projection'], "seal('booking_calendar'") && !str_contains($files['migration'], 'join_uri_reference varchar'),
    'retry worker uses the least-privilege provider principal' => str_contains($files['worker'], 'ProviderProjectionWorkerContext::principalId()') && str_contains($files['worker'], 'new ProviderProjectionWorkerContext())->run'),
    'expired OAuth credentials rotate through the encrypted refresh authority' => str_contains($files['projection'], '$this->refresher->refresh(') && str_contains($files['refresh'], 'ProviderIntegrationService') && str_contains($files['refresh'], 'refreshConnection(') && str_contains($files['provider_service'], 'DISPATCH_CAPABILITY') && !str_contains($files['provider_service'], "'quarantine_reason_code'=>'refresh_failed'"),
    'OAuth consent and refresh enforce the exact Calendar scope allowlist' => str_contains($files['oauth'], 'CALENDAR_SCOPE') && str_contains($files['provider_rule'], 'function permittedScopes(') && substr_count($files['provider_service'], 'permittedScopes(') >= 4 && !str_contains($files['oauth'].$files['google_adapter'], 'meetings.space.created'),
    'legacy over-scoped grants are quarantined for re-consent' => str_contains($files['migration'], 'quarantine_legacy_google_oauth_grants') && str_contains($files['migration'], 'oauth_scope_reconsent_required') && str_contains($files['migration'], "k.state='quarantined'") && str_contains($files['migration'], "m.mapping_state='revoked'") && str_contains($files['migration'], "connection_state='disconnected'") && str_contains($files['migration'], 'verify_google_oauth_scope_policy'),
    'projection retries are bounded and reopen the request' => str_contains($files['projection'], 'MAX_ATTEMPTS') && str_contains($files['projection'], "'failed'") && str_contains($files['projection'], "'accepting','pending_request'"),
    'student notification uses fenced recovery and reports handoff, not delivery' => str_contains($files['notification'], "open('booking_calendar'") && str_contains($files['notification'], 'lease_token_digest') && str_contains($files['notification'], "'handed_off'") && str_contains($files['notification'], "'recipient_delivery_confirmed'=>false"),
    'portal actions are nonce protected' => str_contains($files['controller'], "check_admin_referer('dzn_teacher_booking_response')") && str_contains($files['attention'], 'response_nonce') && str_contains($files['attention'], 'delivery_uid'),
    'Google readiness is reachable in the onboarding wizard' => str_contains($files['bridge'], 'dzn_theme_platform_teacher_onboarding_step') && str_contains($files['portal'], "current_step'] > 5") && str_contains($files['wizard'], '$current === 4') && str_contains($files['wizard'], '$current === 5') && str_contains($files['wizard'], 'اتصال Google Calendar'),
);
foreach ($checks as $name => $ok) {
    if (!$ok) throw new RuntimeException('Booking teacher response contract failed: ' . $name);
}
echo 'Booking teacher response contracts passed (' . count($checks) . ").\n";
